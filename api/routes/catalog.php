<?php
/**
 * Editarea catalogului: produse și grupe.
 *
 * Citirea este publică (o face site-ul), scrierea este strict pentru `admin`.
 *
 * Regula care ține totul în picioare: un produs NU se șterge niciodată din
 * baza de date, ci se dezactivează. Comenzile vechi păstrează denumirea și
 * prețul de la momentul lor în `order_items`, dar articolele din depozit
 * trimit către `products.id`, iar rapoartele pe produse ar rămâne cu goluri.
 * Un produs scos din catalog dispare de pe site și din formulare, dar
 * istoricul rămâne întreg.
 */

declare(strict_types=1);

/*
 * Lățimile coloanelor din `sql/001_schema.sql`, scrise o singură dată.
 *
 * Catalogul era singurul loc din API care nu trecea textele prin
 * `text_field()`: lua `trim((string) …)` gol-goluț și le dădea mai departe lui
 * MySQL. Un nume de produs de 300 de caractere — o lipire greșită din Word, nu
 * un atac — ieșea ca „Eroare internă", fiindcă `slug`-ul făcut din el nu
 * încăpea în coloană. Aceeași lecție ca la depozit
 * (`.claude/test-reparat-depozit-refuzuri.mjs`): datele prea lungi ies ca refuz
 * lămurit, nu ca eroare de server.
 */
const CAT_NUME_MAX  = 96;    // product_categories.name / name_en
const CAT_NOTA_MAX  = 160;   // product_categories.note / note_en
const CAT_CHEIE_MAX = 64;    // product_categories.slug
const PROD_NUME_MAX = 160;   // products.name / name_en
const PROD_DESC_MAX = 4000;  // products.description — coloana e TEXT (65535 OCTEȚI,
                             // adică vreo 32000 de caractere cu diacritice); 4000
                             // e cu mult peste orice descriere de patiserie și
                             // lasă coloanei aer chiar și în cel mai rău caz.
const PROD_CHEIE_MAX = 96;   // products.slug

/**
 * Taie cheia de adresă la lățimea coloanei, pe ultima cratimă.
 *
 * Cheia se face din nume, iar numele are voie să fie lung: „Croissant cu unt
 * franțuzesc și fistic de Bronte, ediție limitată" trece lejer de 64 de
 * caractere. Numele îl păstrăm întreg — cheia o scurtăm, fiindcă ea e doar
 * adresa din bara browserului. Tăiem pe cratimă ca adresa să rămână citibilă,
 * nu retezată la mijlocul unui cuvânt.
 *
 * Rezultatul e verificat de apelant pentru unicitate, deci două nume lungi care
 * ajung la aceeași cheie scurtă primesc refuzul obișnuit „cheia e deja luată",
 * nu una peste alta.
 */
function slug_scurt(string $slug, int $max): string
{
    // `slugify()` întoarce numai [a-z0-9-], deci octeții sunt caractere.
    if (strlen($slug) <= $max) {
        return $slug;
    }
    $t      = substr($slug, 0, $max);
    $ultima = strrpos($t, '-');
    if ($ultima !== false && $ultima >= (int) ($max / 2)) {
        $t = substr($t, 0, $ultima);
    }
    return trim($t, '-');
}

/* ------------------------------------------------------------- categorii -- */

/** GET /categories — grupele din catalog. Public. */
function route_categories_list(): void
{
    $all = isset($_GET['all']) && e_admin(current_user() ?? '');
    $rows = all(
        'SELECT * FROM product_categories' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort, id'
    );
    json_out(['categories' => array_map('category_public', $rows)]);
}

function route_categories_create(): void
{
    require_admin();
    $b = body();

    $name   = text_field($b['name'] ?? null, 'Numele grupei', CAT_NUME_MAX);
    $nameEn = text_field($b['nameEn'] ?? null, 'Numele grupei în engleză', CAT_NUME_MAX);
    $nota   = text_field($b['note'] ?? null, 'Mențiunea grupei', CAT_NOTA_MAX);
    $notaEn = text_field($b['noteEn'] ?? null, 'Mențiunea grupei în engleză', CAT_NOTA_MAX);
    if ($name === '' || $nameEn === '') {
        fail('Numele grupei este obligatoriu în ambele limbi.', 422, 'missing_name');
    }

    // Cheia scrisă cu mâna se refuză dacă e prea lungă — omul a ales-o și o
    // poate scurta. Cea făcută din nume o scurtăm noi: nu el a scris-o.
    $cheieData = text_field($b['slug'] ?? null, 'Cheia grupei', CAT_CHEIE_MAX);
    $slug      = $cheieData !== '' ? slugify($cheieData) : slug_scurt(slugify($name), CAT_CHEIE_MAX);
    if ($slug === '') {
        fail('Nu pot construi o cheie din numele dat. Scrie una manual.', 422, 'bad_slug');
    }
    if (scalar('SELECT 1 FROM product_categories WHERE slug = ?', [$slug])) {
        fail('Există deja o grupă cu cheia „' . $slug . '”.', 409, 'slug_taken');
    }

    $sort = (int) scalar('SELECT COALESCE(MAX(sort), 0) + 10 FROM product_categories');
    q(
        'INSERT INTO product_categories (slug, name, name_en, note, note_en, sort) VALUES (?, ?, ?, ?, ?, ?)',
        [$slug, $name, $nameEn, $nota ?: null, $notaEn ?: null, $sort]
    );
    $id = (int) db()->lastInsertId();

    audit('category_create', 'category', (string) $id, $name);
    json_out(['id' => $id, 'slug' => $slug], 201);
}

function route_categories_update(string $id): void
{
    require_admin();
    $id  = (int) $id;
    $cat = one('SELECT * FROM product_categories WHERE id = ?', [$id]);
    if (!$cat) {
        fail('Grupa nu există.', 404, 'not_found');
    }

    $b = body();
    $sets = [];
    $vals = [];

    foreach (['name' => 'name', 'nameEn' => 'name_en'] as $in => $col) {
        if (array_key_exists($in, $b)) {
            $v = text_field($b[$in], $in === 'name' ? 'Numele grupei' : 'Numele grupei în engleză', CAT_NUME_MAX);
            if ($v === '') {
                fail('Numele grupei nu poate rămâne gol.', 422, 'missing_name');
            }
            $sets[] = "$col = ?";
            $vals[] = $v;
        }
    }
    foreach (['note' => 'note', 'noteEn' => 'note_en'] as $in => $col) {
        if (array_key_exists($in, $b)) {
            $sets[] = "$col = ?";
            $vals[] = text_field($b[$in], $in === 'note' ? 'Mențiunea grupei' : 'Mențiunea grupei în engleză', CAT_NOTA_MAX) ?: null;
        }
    }
    if (array_key_exists('sort', $b)) {
        $sets[] = 'sort = ?';
        $vals[] = (int) $b['sort'];
    }
    if (array_key_exists('active', $b)) {
        // O grupă ascunsă cu produse în ea le-ar face invizibile fără ca
        // produsele să pară scoase din catalog: spunem asta pe față.
        $on = (bool) $b['active'];
        if (!$on) {
            $n = (int) scalar('SELECT COUNT(*) FROM products WHERE category = ? AND active = 1', [$cat['slug']]);
            if ($n > 0) {
                fail(
                    'Grupa „' . $cat['name'] . '” are ' . $n . ($n === 1 ? ' produs activ' : ' produse active') .
                    '. Mută-le în altă grupă sau scoate-le din catalog întâi.',
                    409,
                    'category_in_use'
                );
            }
        }
        $sets[] = 'active = ?';
        $vals[] = $on ? 1 : 0;
    }

    if (!$sets) {
        fail('Nu ai trimis nimic de schimbat.', 422, 'nothing_to_update');
    }

    $schimbari = audit_diff($sets, $vals, $cat, [
        'name'    => 'nume',
        'name_en' => 'nume EN',
        'note'    => 'mențiune',
        'note_en' => 'mențiune EN',
        'sort'    => 'ordine',
        'active'  => ['în catalog', ['1' => 'da', '0' => 'nu']],
    ]);
    $vals[] = $id;
    q('UPDATE product_categories SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);

    audit('category_update', 'category', (string) $id,
        $cat['name'] . ($schimbari === '' ? '' : ' · ' . $schimbari));
    json_out(['ok' => true]);
}

/**
 * DELETE /categories/{id}
 *
 * Ștergerea e reală doar pentru o grupă goală. Cu produse în ea, refuzăm:
 * altfel produsele ar rămâne cu o cheie de grupă care nu mai există și ar
 * dispărea din catalog fără ca nimeni să înțeleagă de ce.
 */
function route_categories_delete(string $id): void
{
    require_admin();
    $id  = (int) $id;
    $cat = one('SELECT * FROM product_categories WHERE id = ?', [$id]);
    if (!$cat) {
        fail('Grupa nu există.', 404, 'not_found');
    }

    $n = (int) scalar('SELECT COUNT(*) FROM products WHERE category = ?', [$cat['slug']]);
    if ($n > 0) {
        fail(
            'Grupa „' . $cat['name'] . '” are ' . $n . ($n === 1 ? ' produs' : ' produse') .
            ' în ea, inclusiv scoase din catalog. Mută-le în altă grupă întâi.',
            409,
            'category_in_use'
        );
    }

    q('DELETE FROM product_categories WHERE id = ?', [$id]);
    audit('category_delete', 'category', (string) $id, $cat['name']);
    json_out(['ok' => true]);
}

/* --------------------------------------------------------------- produse -- */

function route_products_create(): void
{
    require_admin();
    $b = body();

    [$name, $nameEn, $desc, $descEn] = product_texts($b, true);
    $category = product_category($b);

    // Vezi `slug_scurt()`: adresa scrisă cu mâna se refuză dacă nu încape,
    // cea făcută din nume se scurtează singură.
    $cheieData = text_field($b['slug'] ?? null, 'Adresa produsului', PROD_CHEIE_MAX);
    $slug      = $cheieData !== '' ? slugify($cheieData) : slug_scurt(slugify($name), PROD_CHEIE_MAX);
    if ($slug === '') {
        fail('Nu pot construi o adresă din numele dat. Scrie una manual.', 422, 'bad_slug');
    }
    if (scalar('SELECT 1 FROM products WHERE slug = ?', [$slug])) {
        fail('Există deja un produs cu adresa „' . $slug . '”.', 409, 'slug_taken');
    }

    [$grams, $horeca, $retail] = product_numbers($b);

    // ÎN CE CATALOAGE INTRĂ. Implicit în amândouă, ca `DEFAULT 1` din migrarea
    // 024: un panou vechi, care nu trimite bifele, naște produsul exact cum îl
    // năștea ieri.
    $inFrozen = !array_key_exists('inFrozen', $b) || !empty($b['inFrozen']);
    $inBaked  = !array_key_exists('inBaked', $b)  || !empty($b['inBaked']);
    $laturi   = catalog_laturi_supported();

    if ($laturi) {
        product_laturi_verifica([
            'price_frozen' => $horeca,
            'price_baked'  => $retail,
            'in_frozen'    => $inFrozen ? 1 : 0,
            'in_baked'     => $inBaked ? 1 : 0,
        ]);
    } elseif ($horeca === null || $retail === null) {
        // Fereastra dintre urcarea fișierelor și importul SQL. Fără coloanele
        // laturilor, magazinul ascunde orice produs care n-are amândouă
        // prețurile: unul cu un singur preț chiar n-ar avea cum să se vadă.
        fail('Completează amândouă prețurile: cel pentru congelate și cel pentru gata de servire.',
            422, 'missing_price');
    }

    $sort = (int) scalar('SELECT COALESCE(MAX(sort), 0) + 10 FROM products');
    $args = [$slug, $name, $nameEn, $desc, $descEn, $grams, $horeca, $retail,
             $category, !empty($b['isNew']) ? 1 : 0, $sort];
    if ($laturi) {
        array_push($args, $inFrozen ? 1 : 0, $inBaked ? 1 : 0);
    }
    q(
        'INSERT INTO products (slug, name, name_en, description, description_en, grams,
                               price_frozen, price_baked, category, is_new, sort'
        . ($laturi ? ', in_frozen, in_baked' : '') . ')
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . ($laturi ? ', ?, ?' : '') . ')',
        $args
    );
    $id = (int) db()->lastInsertId();

    audit('product_create', 'product', (string) $id, $name);
    json_out(['id' => $id, 'slug' => $slug], 201);
}

function route_products_update(string $id): void
{
    require_admin();
    $id = (int) $id;
    $p  = one('SELECT * FROM products WHERE id = ?', [$id]);
    if (!$p) {
        fail('Produsul nu există.', 404, 'not_found');
    }

    $b    = body();
    $sets = [];
    $vals = [];

    // La modificare, câmpurile lipsă rămân neatinse — dar cele trimise goale
    // sunt refuzate: catalogul e bilingv, iar un nume gol n-are ce căuta.
    $etichete = [
        'name'          => ['Numele produsului', PROD_NUME_MAX],
        'nameEn'        => ['Numele produsului în engleză', PROD_NUME_MAX],
        'description'   => ['Descrierea produsului', PROD_DESC_MAX],
        'descriptionEn' => ['Descrierea produsului în engleză', PROD_DESC_MAX],
    ];
    foreach (['name' => 'name', 'nameEn' => 'name_en',
              'description' => 'description', 'descriptionEn' => 'description_en'] as $in => $col) {
        if (!array_key_exists($in, $b)) {
            continue;
        }
        $v = text_field($b[$in], $etichete[$in][0], $etichete[$in][1]);
        if ($v === '') {
            fail('Câmpul „' . $in . '” nu poate rămâne gol: catalogul este bilingv.', 422, 'missing_text');
        }
        $sets[] = "$col = ?";
        $vals[] = $v;
    }

    if (array_key_exists('category', $b)) {
        $sets[] = 'category = ?';
        $vals[] = product_category($b);
    }
    if (array_key_exists('grams', $b) || array_key_exists('priceFrozen', $b) || array_key_exists('priceBaked', $b)) {
        [$grams, $horeca, $retail] = product_numbers($b);
        if (array_key_exists('grams', $b))       { $sets[] = 'grams = ?';        $vals[] = $grams; }
        // UN PREȚ GOLIT nu se mai refuză din capul locului: cu catalogul lui
        // stins, ăsta e chiar drumul spre un produs care se vinde numai într-o
        // parte. Cine rămâne într-un catalog fără preț e prins mai jos, pe
        // rândul rezultat, cu un mesaj care spune CARE catalog și CARE casetă.
        //
        // Fără coloanele laturilor (fereastra dintre fișiere și importul SQL)
        // refuzul de ieri rămâne singurul lucru corect: acolo un preț gol chiar
        // înseamnă un produs pe care magazinul nu-l mai arată nicăieri.
        if (array_key_exists('priceFrozen', $b)) {
            if ($horeca === null && !catalog_laturi_supported()) {
                fail('Prețul pentru congelate nu poate rămâne gol.', 422, 'missing_price');
            }
            $sets[] = 'price_frozen = ?';
            $vals[] = $horeca;
        }
        if (array_key_exists('priceBaked', $b)) {
            if ($retail === null && !catalog_laturi_supported()) {
                fail('Prețul pentru gata de servire nu poate rămâne gol.', 422, 'missing_price');
            }
            $sets[] = 'price_baked = ?';
            $vals[] = $retail;
        }
    }

    // BIFELE DE CATALOG. Se primesc numai dacă baza le are: altfel ar ajunge în
    // UPDATE o coloană necunoscută, adică o eroare de SQL în locul unei
    // salvări. Un panou nou peste o bază veche trimite bifele degeaba, dar
    // salvează restul — nu se oprește.
    if (catalog_laturi_supported()) {
        foreach (['inFrozen' => 'in_frozen', 'inBaked' => 'in_baked'] as $in => $col) {
            if (array_key_exists($in, $b)) {
                $sets[] = $col . ' = ?';
                $vals[] = !empty($b[$in]) ? 1 : 0;
            }
        }
    }
    if (array_key_exists('isNew', $b))  { $sets[] = 'is_new = ?'; $vals[] = !empty($b['isNew']) ? 1 : 0; }
    if (array_key_exists('active', $b)) { $sets[] = 'active = ?'; $vals[] = !empty($b['active']) ? 1 : 0; }
    if (array_key_exists('sort', $b))   { $sets[] = 'sort = ?';   $vals[] = (int) $b['sort']; }

    if (!$sets) {
        fail('Nu ai trimis nimic de schimbat.', 422, 'nothing_to_update');
    }

    // RÂNDUL REZULTAT: cel din bază, peste care s-a pus ce trimite formularul.
    // Perechile se desfac la fel ca în `audit_diff()`, care citește chiar
    // aceleași două șiruri câteva rânduri mai jos.
    if (catalog_laturi_supported()) {
        $rezultat = $p;
        foreach ($sets as $i => $set) {
            $rezultat[trim(explode('=', (string) $set, 2)[0])] = $vals[$i];
        }
        product_laturi_verifica($rezultat);
    }

    // Prețul e motivul pentru care rândul ăsta există. Până acum jurnalul
    // scria „Croissant" și atât: la o reclamație despre o factură se vedea că
    // produsul a fost atins marți, dar nu dacă i s-a mișcat prețul, gramajul
    // ori o virgulă din descriere — iar comenzile date înainte se livrează la
    // prețul convenit atunci (vezi „preț onorat" din api/routes/orders.php).
    $schimbari = audit_diff($sets, $vals, $p, [
        'name'           => 'nume',
        'name_en'        => 'nume EN',
        'description'    => 'descriere',
        'description_en' => 'descriere EN',
        'category'       => 'grupă',
        'grams'          => 'grame',
        'price_frozen'   => 'preț congelat',
        'price_baked'    => 'preț gata de servire',
        'in_frozen'      => ['în congelate', ['1' => 'da', '0' => 'nu']],
        'in_baked'       => ['la gata de servire', ['1' => 'da', '0' => 'nu']],
        'is_new'         => ['nou', ['1' => 'da', '0' => 'nu']],
        'active'         => ['în catalog', ['1' => 'da', '0' => 'nu']],
        'sort'           => 'ordine',
    ]);
    $vals[] = $id;
    q('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);

    audit('product_update', 'product', (string) $id,
        $p['name'] . ($schimbari === '' ? '' : ' · ' . $schimbari));
    json_out(['ok' => true]);
}

/**
 * DELETE /products/{id}
 *
 * Două comportamente, după cât istoric are produsul:
 *
 *  - dacă nu a fost comandat niciodată și nu are articol în depozit, rândul se
 *    șterge de tot. Un produs adăugat din greșeală trebuie să poată dispărea,
 *    nu să rămână pentru totdeauna în listă ca „scos din catalog";
 *  - dacă apare în comenzi sau în depozit, se dezactivează. Articolele din
 *    depozit trimit către `products.id`, iar rapoartele pe produs ar rămâne cu
 *    goluri. Produsul dispare de pe site, dar istoricul rămâne întreg.
 */
function route_products_delete(string $id): void
{
    require_admin();
    $id = (int) $id;
    $p  = one('SELECT * FROM products WHERE id = ?', [$id]);
    if (!$p) {
        fail('Produsul nu există.', 404, 'not_found');
    }

    // Se numără COMENZI, nu poziții: aceeași comandă poate avea produsul și
    // congelat, și copt (`sql/001_schema.sql:158`). Cu `COUNT(*)` refuzul ar fi
    // spus „apare în 2 comenzi” despre una singură, iar omul ar fi plecat s-o
    // caute pe a doua. Zero rămâne zero în amândouă felurile, deci poarta de mai
    // jos se poartă întocmai ca înainte.
    $inOrders = (int) scalar('SELECT COUNT(DISTINCT order_id) FROM order_items WHERE product_id = ?', [$id])
        + (int) scalar('SELECT COUNT(DISTINCT request_id) FROM order_request_items WHERE product_id = ?', [$id]);
    $inStock  = (int) scalar('SELECT COUNT(*) FROM stock_items WHERE product_id = ?', [$id]);

    // „Numai ștergere", cerut anume de pagină.
    //
    // Fără steagul ăsta ruta face ce-a făcut dintotdeauna: produsul cu istoric
    // se scoate din catalog în loc să fie șters. Purtarea aceea era singura
    // scăpare pe vremea când × era singurul buton de pe rând.
    //
    // Acum rândul are comutatorul lui, iar × înseamnă ștergere și atât. Pagina
    // arată o confirmare scrisă pe cifrele pe care i le-a dat lista — citite
    // acum o clipă, ori acum zece minute. Dacă între timp produsul a intrat
    // într-o comandă, confirmarea a mințit: spunea „dispare definitiv". Aici se
    // termină minciuna aceea cu un refuz lămurit, nu cu o faptă pe care omul
    // n-a cerut-o. Refuzul nu schimbă nimic: nici nu șterge, nici nu scoate.
    if (($_GET['mod'] ?? '') === 'doar-sterge' && ($inOrders > 0 || $inStock > 0)) {
        $unde = [];
        if ($inOrders > 0) {
            $unde[] = $inOrders . ($inOrders === 1 ? ' comandă' : ' comenzi');
        }
        if ($inStock > 0) {
            $unde[] = $inStock . ($inStock === 1 ? ' articol de depozit' : ' articole de depozit');
        }
        fail(
            'Produsul „' . $p['name'] . '” apare în ' . implode(' și în ', $unde)
            . '. Dacă l-am șterge, comenzile de atunci n-ar mai ști ce s-a livrat.'
            . ' Scoate-l din catalog: nu se mai vinde, dar istoricul rămâne întreg.',
            409,
            'are_istoric'
        );
    }

    if ($inOrders === 0 && $inStock === 0) {
        // Fotografiile urcate din panou nu mai folosesc nimănui — AMÂNDOUĂ.
        // Bucla merge pe laturi, nu pe un nume de coloană scris aici: o a
        // treia latură n-ar mai cere o a treia scăpare de ținut minte.
        foreach (laturi() as $lat) {
            $poza = (string) ($p[latura_coloana_poza($lat)] ?? '');
            if ($poza !== '' && is_uploaded_photo($poza)) {
                @unlink(photo_dir() . '/' . basename($poza));
            }
        }
        q('DELETE FROM products WHERE id = ?', [$id]);
        audit('product_delete', 'product', (string) $id, $p['name']);
        json_out([
            'ok'      => true,
            'deleted' => true,
            'message' => 'Produsul a fost șters. Nu apărea în nicio comandă și nu avea articol în depozit.',
        ]);
    }

    q('UPDATE products SET active = 0 WHERE id = ?', [$id]);
    audit('product_deactivate', 'product', (string) $id, $p['name']);

    json_out([
        'ok'      => true,
        'deleted' => false,
        'message' => 'Produsul a fost scos din catalog. Apare în ' . $inOrders
            . ($inOrders === 1 ? ' comandă' : ' comenzi')
            . ', deci rândul rămâne ca istoricul să nu se rupă. Îl poți repune oricând.',
    ]);
}

/* ---------------------------------------------------------------- poze -- */

/**
 * POST /products/{id}/photo — încarcă fotografia produsului.
 *
 * Tipul fișierului se verifică după CONȚINUT, nu după extensie sau după ce
 * spune browserul: ambele se pot falsifica trivial, iar un fișier PHP urcat cu
 * numele „poza.jpg" ar fi cod executabil în folderul public.
 */
function route_products_photo(string $id): void
{
    require_admin();
    $id = (int) $id;
    $p  = one('SELECT * FROM products WHERE id = ?', [$id]);
    if (!$p) {
        fail('Produsul nu există.', 404, 'not_found');
    }

    // PE CE LATURĂ. Ruta rămâne una singură, cu un câmp în plus în formular:
    // un panou vechi, care nu-l trimite, scrie unde scria — în `photo`.
    $latura = latura_normalizeaza($_POST['latura'] ?? null);
    $col    = latura_coloana_poza($latura);
    if ($col !== 'photo' && !catalog_laturi_supported()) {
        fail(
            'Pozele pe laturi cer migrarea 024: baza de date nu are încă coloana.'
              . ' Importă sql/024_doua_cataloage.sql și încearcă din nou.',
            503,
            'laturi_unavailable'
        );
    }

    $f = $_FILES['photo'] ?? null;

    // CORPUL ARUNCAT, adică pragul care minte cel mai urât. Peste
    // `post_max_size`, PHP leapădă TOATĂ cererea înainte să ruleze un rând
    // de-al nostru: nu doar `$_FILES` iese gol, ci și `$_POST`, iar
    // `$_FILES['photo']['error']` nu există deloc — deci `match`-ul de mai jos
    // cădea pe UPLOAD_ERR_NO_FILE și îi spunea „Nu ai ales niciun fișier"
    // tocmai omului care alesese unul, trimițându-l să caute unde nu era nimic
    // de găsit.
    //
    // Martorul care supraviețuiește e lungimea anunțată de browser. Cerem
    // amândouă faptele deodată — nimic parsat ȘI un corp mai mare decât
    // pragul —, ca o cerere mică și strâmbă (un JSON trimis din greșeală
    // aici) să nu fie luată drept poză pierdută.
    $lungime = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (poza_corp_aruncat($f, $_POST, $lungime)) {
        fail(poza_mesaj_corp_aruncat($lungime), 422, 'too_large');
    }

    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $eroare = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        $why    = match ($eroare) {
            // Aceeași vorbă ca la pragul panoului, de mai jos: pentru om e
            // același lucru — poza n-a intrat —, iar CINE a spus „nu" se
            // socotește o singură dată, în `poza_mesaj_prea_mare()`.
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => poza_mesaj_prea_mare(),
            UPLOAD_ERR_PARTIAL   => 'Încărcarea s-a întrerupt. Încearcă din nou.',
            UPLOAD_ERR_NO_FILE   => 'Nu ai ales niciun fișier.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Serverul nu poate scrie fișiere temporare.',
            default              => 'Încărcarea a eșuat.',
        };
        // Prea mare rămâne prea mare, oricare din cele două praguri a spus-o:
        // panoul deosebește `too_large` de restul.
        $cod = in_array($eroare, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'too_large'
            : 'upload_failed';
        fail($why, 422, $cod);
    }

    if ((int) $f['size'] > POZA_MAX_OCTETI) {
        fail(poza_mesaj_prea_mare(), 422, 'too_large');
    }

    // getimagesize citește antetul real al fișierului.
    $info = @getimagesize($f['tmp_name']);
    $ext  = match ($info[2] ?? 0) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        default        => null,
    };
    if ($ext === null) {
        fail('Acceptăm doar fotografii JPEG, PNG sau WebP.', 422, 'bad_type');
    }

    $dir = photo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        fail('Nu pot crea folderul pentru fotografii pe server.', 500, 'no_dir');
    }
    if (!is_writable($dir)) {
        fail('Folderul „products" de pe server nu permite scrierea. Pune-i drepturi 755.', 500, 'not_writable');
    }

    // Nume nou la fiecare încărcare: altfel browserele ar arăta poza veche din
    // memoria lor încă zile bune după schimbare.
    $file = 'prod-' . $id . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $file)) {
        fail('Nu am putut salva fișierul pe server.', 500, 'move_failed');
    }

    $old = (string) ($p[$col] ?? '');
    $web = photo_web_prefix() . $file;
    // `$col` nu vine de la client: `latura_coloana_poza()` întoarce una din
    // două vorbe scrise în cod, oricare ar fi textul primit.
    q('UPDATE products SET ' . $col . ' = ? WHERE id = ?', [$web, $id]);

    // Poza dinainte se șterge doar dacă a fost încărcată tot de aici: cele
    // venite odată cu proiectul pot fi folosite și în altă parte.
    //
    // „Dinainte" înseamnă cea a LATURII ĂSTEIA. Cu `$p['photo']` scris aici,
    // fiecare urcare de gata-de-servire ar fi șters poza congelată de pe disc
    // — iar rândul din bază ar fi rămas, deci poza s-ar fi rupt tăcut.
    //
    // ȘI NICI DACĂ CEALALTĂ LATURĂ O MAI FOLOSEȘTE. Două coloane pot arăta
    // spre același fișier: așa le-a lăsat `sql/027_poza_fiecarei_laturi.sql`,
    // care a copiat poza congelată în latura goală ca tăierea căderii să nu
    // golească vitrina. Numele fișierului e acolo de două ori, dar fișierul e
    // unul singur — iar codul de mai jos presupunea tăcut că fiecare fișier
    // are un singur stăpân. Fără rândul ăsta, prima poză nouă de congelate ar
    // fi șters de pe disc tocmai fișierul spre care `photo_baked` încă arată,
    // și poza de la gata de servire s-ar fi rupt în tăcere, la fiecare produs,
    // în ziua următoare migrării.
    $cealaltaCol = latura_coloana_poza($latura === 'copt' ? 'congelat' : 'copt');
    $tinutDeCealalta = (string) ($p[$cealaltaCol] ?? '') === $old;
    if (is_uploaded_photo($old) && !$tinutDeCealalta) {
        @unlink($dir . '/' . basename($old));
    }

    audit('product_photo', 'product', (string) $id, $p['name'] . ' — ' . latura_cuvant($latura));
    json_out(['ok' => true, 'photo' => $web, 'latura' => $latura]);
}

/** Folderul public cu fotografiile produselor. */
function photo_dir(): string
{
    // Configurabil, fiindcă pe server API-ul stă în alt subdomeniu decât site-ul.
    $configured = (string) config('photo_dir', '');
    if ($configured !== '') {
        return rtrim($configured, '/\\');
    }
    return dirname(__DIR__, 2) . '/products';
}

/**
 * Sub ce adresă cere site-ul fotografiile.
 *
 * Se ia din chiar folderul configurat, nu din textul fix „products/".
 * Altfel, cine muta folderul din `photo_dir` ajungea într-o situație tăcută:
 * fișierul se salva unde trebuie, dar în baza de date se scria mereu
 * „products/…", deci site-ul îl căuta în altă parte și poza rămânea ruptă —
 * iar poza înlocuită nu se mai ștergea niciodată, fiindcă și ștergerea
 * presupunea aceeași structură.
 */
function photo_web_prefix(): string
{
    return basename(photo_dir()) . '/';
}

/** A fost fotografia urcată din panou? Doar acelea se șterg de pe disc. */
function is_uploaded_photo(string $photo): bool
{
    $pref = preg_quote(photo_web_prefix(), '#');
    return (bool) preg_match('#^' . $pref . 'prod-\d+-[0-9a-f]{8}\.(jpg|png|webp)$#', $photo);
}

/* ------------------------------------------------------------- ajutoare -- */

/** Textele obligatorii ale unui produs, în ambele limbi. */
function product_texts(array $b, bool $required): array
{
    $name   = text_field($b['name'] ?? null, 'Numele produsului', PROD_NUME_MAX);
    $nameEn = text_field($b['nameEn'] ?? null, 'Numele produsului în engleză', PROD_NUME_MAX);
    $desc   = text_field($b['description'] ?? null, 'Descrierea produsului', PROD_DESC_MAX);
    $descEn = text_field($b['descriptionEn'] ?? null, 'Descrierea produsului în engleză', PROD_DESC_MAX);

    if ($required) {
        $missing = [];
        if ($name === '')   { $missing[] = 'numele în română'; }
        if ($nameEn === '') { $missing[] = 'numele în engleză'; }
        if ($desc === '')   { $missing[] = 'descrierea în română'; }
        if ($descEn === '') { $missing[] = 'descrierea în engleză'; }
        if ($missing) {
            fail('Lipsește ' . implode(', ', $missing) . '. Catalogul este bilingv.', 422, 'missing_text');
        }
    }
    return [$name, $nameEn, $desc, $descEn];
}

/** Grupa produsului, verificată față de grupele existente. */
function product_category(array $b): string
{
    $cat = trim((string) ($b['category'] ?? ''));
    if ($cat === '') {
        fail('Alege grupa din care face parte produsul.', 422, 'missing_category');
    }
    if (!scalar('SELECT 1 FROM product_categories WHERE slug = ? AND active = 1', [$cat])) {
        fail('Grupa „' . $cat . '” nu există sau este ascunsă.', 422, 'bad_category');
    }
    return $cat;
}

/** Gramaj și prețuri, cu limite care prind greșelile de tastare. */
function product_numbers(array $b): array
{
    // `?? null` și nu `$b['grams']` direct: cheia lipsește ori de câte ori
    // formularul nu trimite gramajul, iar accesul direct scrie un avertisment.
    $raw   = $b['grams'] ?? null;
    $grams = ($raw === null || $raw === '') ? null : (int) $raw;
    if ($grams !== null && ($grams <= 0 || $grams > 100000)) {
        fail('Gramajul trebuie să fie între 1 și 100000.', 422, 'bad_grams');
    }

    $price = static function ($v, string $label) {
        if ($v === null || $v === '') {
            return null;
        }
        // Câmpul din formular e text liber, iar `(float)` pe un text ca „la
        // cerere” dă tăcut 0.0: produsul se publica cu 0,00 lei, clientul putea
        // comanda o sută de cornuri pe gratis, iar cererea intra în registru cu
        // total 0 — fără ca cineva să afle că prețul tastat s-a pierdut. Cerem
        // un număr adevărat și-i spunem omului ce am primit în loc.
        $brut  = is_int($v) || is_float($v) || is_string($v) ? trim((string) $v) : '';
        $curat = str_replace(',', '.', $brut);
        if (!preg_match('/^-?\d+(\.\d*)?$/', $curat)) {
            $vazut = $brut === '' ? '' : ' Am primit „' . mb_substr($brut, 0, 40) . '”.';
            fail('Prețul ' . $label . ' trebuie scris în cifre, de pildă „2,85”.' . $vazut, 422, 'bad_price');
        }
        $n = (float) $curat;
        // Și 0 e refuzat, nu doar minusul: un produs cu prețul 0 ajunge pe site
        // cu „0,00 RON” și se poate comanda pe gratis.
        if ($n <= 0 || $n > 100000) {
            fail('Prețul ' . $label . ' nu pare corect: trebuie să fie între 0,01 și 100000 lei.', 422, 'bad_price');
        }
        return round($n, 2);
    };

    return [
        $grams,
        $price($b['priceFrozen'] ?? null, 'pentru congelate'),
        $price($b['priceBaked'] ?? null, 'pentru gata de servire'),
    ];
}

/**
 * INVARIANTUL celor două cataloage, judecat pe RÂNDUL REZULTAT.
 *
 * Regula, una singură: dacă produsul e într-un catalog, are prețul acelui
 * catalog. Niciodată pe dos — un preț poate sta în casetă și cu latura stinsă,
 * fiindcă patronul a cerut anume ca scoaterea dintr-un catalog să NU-i șteargă
 * prețul. Aici se închide singurul drum prin care apartenența s-ar putea
 * despărți de preț: panoul.
 *
 * SE PRIMEȘTE RÂNDUL REZULTAT, nu corpul cererii. Altfel „aprind catalogul ȘI
 * îi scriu prețul în aceeași salvare" ar fi refuzat, fiindcă în bază prețul
 * încă lipsește; iar „golesc prețul" ar fi judecat după bifa din formularul
 * deschis acum zece minute, nu după cea de acum.
 *
 * CE NU FACE. Nu se atinge de `active`. „În niciun catalog" și „scos din
 * catalog" ar fi două nume pentru aceeași stare, iar două nume pentru o stare e
 * chiar felul în care s-a născut încurcătura dintre comutatorul de pe rând și
 * butonul ×. Deci: al doilea nume se refuză, și omul e trimis la buton.
 */
function product_laturi_verifica(array $r): void
{
    $aprinse = 0;
    foreach (laturi() as $lat) {
        if (!latura_in($r, $lat)) {
            continue;
        }
        $aprinse++;
        if (latura_pret($r, $lat) === null) {
            fail(
                'Produsul e în catalogul „' . latura_nume($lat) . '”, dar n-are prețul de acolo.'
                  . ' Scrie prețul din caseta „Preț ' . latura_cuvant($lat) . '”,'
                  . ' sau scoate-l din catalogul ăsta.',
                422,
                'missing_price'
            );
        }
    }
    if ($aprinse === 0) {
        fail(
            'Un produs trebuie să fie în cel puțin un catalog. Dacă nu se mai vinde deloc,'
              . ' scoate-l din catalog cu butonul de pe rând.',
            422,
            'no_catalog'
        );
    }
}

/** Transformă un nume în cheie de adresă: „Ștrudel cu mere" → „strudel-cu-mere". */
function slugify(string $text): string
{
    $from = ['ă', 'â', 'î', 'ș', 'ş', 'ț', 'ţ', 'Ă', 'Â', 'Î', 'Ș', 'Ş', 'Ț', 'Ţ'];
    $to   = ['a', 'a', 'i', 's', 's', 't', 't', 'a', 'a', 'i', 's', 's', 't', 't'];
    $s    = str_replace($from, $to, $text);
    $s    = strtolower($s);
    $s    = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/* --------------------------------------------------------------- rețete -- */

/**
 * Rețeta unui produs: din ce materii prime e făcută O SINGURĂ bucată.
 *
 * Rețeta stă aici, nu în Depozit, fiindcă aparține produsului — „din ce e făcut
 * un croissant" e o proprietate a croissantului, iar pagina Produse editează
 * deja tot restul lui. E și o chestiune de drepturi: Produse e a adminului,
 * Depozitul e al oricărui angajat.
 *
 * Rețetele sunt pe UN SINGUR NIVEL, deliberat. O „cutie cadou" care conține
 * croissante trece croissantul ca linie de rețetă, pe articolul lui finit, și îl
 * scade direct de acolo. Nicio recursie, rezultat corect.
 */

/** GET /products/{id}/recipe — liniile rețetei, plus articolul finit al produsului. */
function route_product_recipe_get(string $id): void
{
    require_admin();
    $id = (int) $id;

    $produs = one('SELECT id, name FROM products WHERE id = ?', [$id]);
    if (!$produs) {
        fail('Produsul nu există.', 404, 'not_found');
    }

    $rows = all(
        'SELECT r.item_id, r.qty, i.name, i.unit, i.category, i.active
           FROM product_recipes r
           JOIN stock_items i ON i.id = r.item_id
          WHERE r.product_id = ?
          ORDER BY i.category, i.name',
        [$id]
    );

    // Articolul finit se dă alături, fiindcă fără el rețeta nu folosește la
    // nimic: producția scade materia primă și adaugă bucățile făcute, iar dacă
    // n-are unde să le adune, comanda rămâne blocată la scăderea de la
    // „Predată". Panoul poate spune asta din timp, nu după.
    $finit = one('SELECT id, name FROM stock_items WHERE product_id = ? AND active = 1', [$id]);

    json_out([
        'product' => ['id' => $id, 'name' => $produs['name']],
        'recipe'  => array_map(static fn(array $r): array => [
            'itemId'   => (int) $r['item_id'],
            'qty'      => (float) $r['qty'],
            'name'     => $r['name'],
            'unit'     => $r['unit'],
            'category' => $r['category'],
            // Un articol scos din listă rămâne în rețetă: consumul lui a fost
            // adevărat și poate redeveni. Panoul îl arată altfel, ca omul să
            // înțeleagă de ce nu-l găsește când caută să-l schimbe.
            'active'   => (int) $r['active'] === 1,
        ], $rows),
        'finished' => $finit ? ['id' => (int) $finit['id'], 'name' => $finit['name']] : null,
    ]);
}

/**
 * PUT /products/{id}/recipe — înlocuiește rețeta întreagă.
 *
 * Înlocuire, nu adăugare, ca listele din Setări: panoul trimite ce vede omul pe
 * ecran, iar o linie ștearsă din ecran e ștearsă și în bază. O rută care ar
 * adăuga ar cere o a doua rută de ștergere și un al treilea fel de a greși.
 *
 * Ștergerea unei linii se face scoțând-o din listă, nu punându-i cantitatea 0:
 * un rând de rețetă cu zero e o minciună („croissantul are drojdie, în cantitate
 * zero"), iar la producție ar scrie mișcări de stoc de zero în registru.
 */
function route_product_recipe_put(string $id): void
{
    require_admin();
    $b  = body();
    $id = (int) $id;

    $produs = one('SELECT id, name FROM products WHERE id = ?', [$id]);
    if (!$produs) {
        fail('Produsul nu există.', 404, 'not_found');
    }

    $linii = $b['recipe'] ?? null;
    if (!is_array($linii)) {
        fail('Rețeta trebuie trimisă ca listă de linii.', 422, 'bad_recipe');
    }
    // Plafonul e o plasă, nu o regulă de patiserie: nicio prăjitură n-are șaizeci
    // de ingrediente, dar o cerere stricată poate avea șaizeci de mii, iar fiecare
    // linie e o interogare la validare și un rând la scriere.
    if (count($linii) > 60) {
        fail('O rețetă are cel mult 60 de linii.', 422, 'too_many_lines');
    }

    // Articolul finit al produsului nu are ce căuta în propria lui rețetă:
    // producția l-ar scădea ca materie primă și l-ar adăuga ca rezultat, în
    // aceeași mișcare. Zero la sold, ceas oprit la ora fixă — corect de două ori
    // pe zi și greșit tot restul timpului, fiindcă rezervările tot l-ar promite.
    $finit = (int) (scalar('SELECT id FROM stock_items WHERE product_id = ? AND active = 1', [$id]) ?? 0);

    $curate = [];
    foreach ($linii as $l) {
        if (!is_array($l)) {
            fail('O linie de rețetă trebuie să aibă articol și cantitate.', 422, 'bad_item');
        }
        $itemId = (int) ($l['itemId'] ?? 0);
        $art    = $itemId > 0 ? one('SELECT id, name FROM stock_items WHERE id = ?', [$itemId]) : null;
        if (!$art) {
            fail('Articolul de depozit din rețetă nu există. Alege-l din listă.', 422, 'bad_item');
        }
        if ($itemId === $finit) {
            fail(
                'Articolul „' . $art['name'] . '” este chiar produsul finit al acestui produs. '
                . 'Rețeta spune din ce e făcut, nu se poate cuprinde pe sine.',
                422,
                'self_item'
            );
        }
        if (isset($curate[$itemId])) {
            fail(
                'Articolul „' . $art['name'] . '” apare de două ori în rețetă. '
                . 'Adună cantitățile într-o singură linie.',
                409,
                'duplicate_item'
            );
        }
        // Minimul e 0,0001, nu 0: cantitatea cea mai mică pe care o poate ține
        // coloana. Zero ar fi o linie care nu consumă nimic — vezi antetul.
        $curate[$itemId] = num_field($l['qty'] ?? null, 'Cantitatea din rețetă', 0.0001, 99999999.9999, 'bad_qty');
    }

    // Ștergem și rescriem într-o singură tranzacție: o rețetă golită pe jumătate,
    // fiindcă a doua inserare a căzut, ar scădea din depozit alt croissant decât
    // cel pe care l-a văzut adminul când a apăsat „Salvează".
    $pdo = db();
    $pdo->beginTransaction();
    $rescrise = 0;
    try {
        q('DELETE FROM product_recipes WHERE product_id = ?', [$id]);
        foreach ($curate as $itemId => $qty) {
            q('INSERT INTO product_recipes (product_id, item_id, qty) VALUES (?, ?, ?)', [$id, $itemId, $qty]);
        }

        // Promisiunile comenzilor deschise se rescriu după rețeta nouă.
        //
        // Rezervările de materie primă se calculează la acceptare, din rețeta
        // de ATUNCI (bucăți × cantitatea pe piesă), și rămâneau așa până
        // cineva corecta pozițiile comenzii. Rețeta schimbată de la 100 g la
        // 120 g de făină lăsa „Rezervat" pe cifra veche pentru toate comenzile
        // din lucru, iar producția — care scade după rețeta nouă — împingea
        // `consumed` peste `qty`. Rescrierea e aceeași cu cea de la corectarea
        // pozițiilor (stock_reserve_order), doar comenzile care au deja rânduri
        // de rezervare pe produsul ăsta și nu sunt predate ori anulate (acelea
        // nu mai promit nimic, stock.php le lasă oricum deoparte). Rândul se
        // blochează, ca un raport de producție de pe tabletă să nu scrie în
        // același timp.
        $deschise = all(
            'SELECT DISTINCT r.order_id
               FROM stock_reservations r
               JOIN order_items oi ON oi.id = r.order_item_id
               JOIN orders o       ON o.id  = r.order_id
              WHERE oi.product_id = ? AND o.status <> ? AND o.status <> ?',
            [$id, status_done(), status_cancelled()]
        );
        foreach ($deschise as $rand) {
            $oid = (int) $rand['order_id'];
            one('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$oid]);
            stock_reserve_order($oid);
            $rescrise++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit(
        'product_recipe',
        'product',
        (string) $id,
        $produs['name'] . ' · ' . count($curate) . ' linii'
            . ($rescrise > 0 ? ' · rezervări rescrise pe ' . $rescrise . ' comenzi' : '')
    );

    json_out(['ok' => true, 'lines' => count($curate), 'ordersUpdated' => $rescrise]);
}
