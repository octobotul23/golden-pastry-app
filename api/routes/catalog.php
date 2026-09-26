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
        fail('Completează amândouă prețurile: cel pentru congelate și cel pentru coapte.',
            422, 'missing_price');
    }

    // CELE TREI PREȚURI (migrarea 028). Numai câmpurile trimise; un panou
    // vechi, care nu le știe, naște produsul cu un singur preț, ca ieri.
    $trepte = product_trepte_din($b);
    if ($trepte) {
        product_trepte_verifica(
            $trepte
            + ['price_frozen' => $horeca, 'price_baked' => $retail]
            + array_fill_keys(array_values(PROD_TREPTE_CAMPURI), null)
        );
    }

    // NUMĂRUL DIN COLȚUL POZEI (migrarea 029). Fără număr cerut, produsul nou
    // îl ia pe următorul — „în ordinea în care sunt postate", cum a cerut
    // patronul. Cu număr cerut, i se face loc ca la o mutare. Se judecă aici,
    // înaintea tranzacției: `fail()` încheie scriptul pe loc.
    $numerotat  = catalog_numere_supported();
    $numarCerut = product_numar_cerut($b, 'numar');
    if ($numarCerut !== null && !$numerotat) {
        fail('Numărul din catalog se poate alege după importul migrării 029 '
            . '(sql/029_numere_in_catalog.sql). Salvează fără el: produsul își primește singur numărul.',
            409, 'numbers_unavailable');
    }

    $insereaza = static function (int $sort) use ($slug, $name, $nameEn, $desc, $descEn, $grams, $horeca,
                                                  $retail, $category, $b, $laturi, $inFrozen, $inBaked, $trepte): int {
        $args = [$slug, $name, $nameEn, $desc, $descEn, $grams, $horeca, $retail,
                 $category, !empty($b['isNew']) ? 1 : 0, $sort];
        if ($laturi) {
            array_push($args, $inFrozen ? 1 : 0, $inBaked ? 1 : 0);
        }
        array_push($args, ...array_values($trepte));
        q(
            'INSERT INTO products (slug, name, name_en, description, description_en, grams,
                                   price_frozen, price_baked, category, is_new, sort'
            . ($laturi ? ', in_frozen, in_baked' : '')
            . ($trepte ? ', ' . implode(', ', array_keys($trepte)) : '') . ')
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . ($laturi ? ', ?, ?' : '')
            . str_repeat(', ?', count($trepte)) . ')',
            $args
        );
        return (int) db()->lastInsertId();
    };

    if (!$numerotat) {
        // Baza de dinainte de 029: pașii de zece de ieri, neatinși.
        $id = $insereaza((int) scalar('SELECT COALESCE(MAX(sort), 0) + 10 FROM products'));
        audit('product_create', 'product', (string) $id, $name);
        json_out(['id' => $id, 'slug' => $slug], 201);
    }

    // Sub lacăt, ca două produse puse deodată să nu ia același număr.
    //
    // Următorul după cel mai mare, dar niciodată peste 999: un produs pus de
    // mână pe 999 („să stea ultimul") ar fi dat altfel produsului nou 1000 —
    // un număr pe care formularul nu-l mai primește înapoi. Acolo produsul nou
    // intră pe 999 ca pe orice număr ocupat, iar cel de pe el se dă spre cap.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $numere  = product_numere_sub_lacat();
        $urmator = min($numere ? max($numere) + 1 : 1, CATALOG_NUMAR_MAX);
        $tinta   = $numarCerut ?? $urmator;
        $mutari  = catalog_numar_mutari($numere, 0, $tinta);
        if ($mutari === null) {
            $pdo->rollBack();
            product_numar_plin($tinta);
        }
        $id = $insereaza($tinta);
        $altii = $mutari;
        unset($altii[0]);
        product_numere_scrie($altii);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Jurnalul spune numărul numai când s-a cerut unul ori când s-a mutat
    // cineva; produsul pus la coadă, ca de obicei, rămâne „Croissant" și atât.
    audit('product_create', 'product', (string) $id, $name
        . ($numarCerut === null && !$altii ? '' : ' · ' . catalog_numar_poveste($numere, 0, $mutari)));
    json_out(['id' => $id, 'slug' => $slug, 'numar' => $tinta,
              'mutate' => product_numere_mutate($numere, $altii)], 201);
}

/**
 * Numărul din catalog cerut de panou, judecat — sau null dacă cererea nu cere
 * niciunul. Refuzul (422) spune ce a primit, ca omul să vadă ce a scris.
 */
function product_numar_cerut(array $b, string $cheie): ?int
{
    if (!array_key_exists($cheie, $b)) {
        return null;
    }
    $n = catalog_numar_din($b[$cheie]);
    if ($n !== null) {
        return $n;
    }
    $aratat = text_de_aratat($b[$cheie]);
    fail('Numărul din catalog trebuie să fie un număr întreg de la 1 la ' . CATALOG_NUMAR_MAX . '.'
        . ($aratat === '' ? ' Căsuța a rămas goală.' : ' Am primit „' . $aratat . '”.'),
        422, 'bad_catalog_number');
    return null;
}

/**
 * Numerele TUTUROR produselor, prinse cu `FOR UPDATE` (id → număr).
 *
 * În ordinea id-ului, la orice cerere: două mutări date deodată din două
 * panouri se așază una după alta, fără blocaj de moarte, iar a doua o vede pe
 * prima întreagă. Cheam-o NUMAI dintr-o tranzacție deschisă.
 */
function product_numere_sub_lacat(): array
{
    $numere = [];
    foreach (all('SELECT id, sort FROM products ORDER BY id FOR UPDATE') as $r) {
        $numere[(int) $r['id']] = (int) $r['sort'];
    }
    return $numere;
}

/** Scrie numerele noi (id → număr). Tot din tranzacție. */
function product_numere_scrie(array $mutari): void
{
    foreach ($mutari as $pid => $nr) {
        q('UPDATE products SET sort = ? WHERE id = ?', [(int) $nr, (int) $pid]);
    }
}

/**
 * Cine s-a mai mutat, pentru răspuns: [{id, de, la}]. Panoul le pune la loc
 * în listă fără să mai întrebe serverul, și scrie omului câte s-au mișcat.
 */
function product_numere_mutate(array $numere, array $mutari): array
{
    $out = [];
    foreach ($mutari as $pid => $nr) {
        $out[] = ['id' => (int) $pid, 'de' => (int) ($numere[$pid] ?? 0), 'la' => (int) $nr];
    }
    return $out;
}

/** Nu mai e loc: de la numărul cerut până la 1 și până la 999, totul e ocupat. */
function product_numar_plin(int $n): void
{
    fail('Nu pot face loc numărului ' . catalog_numar_afisat($n) . ': de la el până la capete, toate numerele '
        . 'de la 1 la ' . CATALOG_NUMAR_MAX . ' sunt ocupate.', 409, 'catalog_numbers_full');
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

    // NUMĂRUL DIN COLȚUL POZEI (migrarea 029), judecat primul: `fail()`
    // încheie scriptul pe loc, deci niciun refuz nu are voie să vină după
    // vreo scriere. `sort` a fost primit aici dintotdeauna și scris ca atare;
    // de la 029 e numărul însuși, deci trece pe același drum ca `numar` — scris
    // ca atare, ar fi pus două produse pe același număr.
    $numerotat  = catalog_numere_supported();
    $numarCerut = product_numar_cerut($b, array_key_exists('numar', $b) || !$numerotat ? 'numar' : 'sort');
    if ($numarCerut !== null && !$numerotat && $numarCerut !== $id) {
        // Fără 029, numărul din colț e cheia bazei: îl poți „alege" numai pe
        // cel pe care îl are deja.
        fail('Numărul din catalog se poate schimba după importul migrării 029 '
            . '(sql/029_numere_in_catalog.sql). Până atunci, fiecare produs poartă numărul lui de la naștere.',
            409, 'numbers_unavailable');
    }

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
                fail('Prețul pentru coapte nu poate rămâne gol.', 422, 'missing_price');
            }
            $sets[] = 'price_baked = ?';
            $vals[] = $retail;
        }
    }

    // CELE TREI PREȚURI (migrarea 028): numai câmpurile trimise, ca oricare
    // altul. Ordinea dintre trepte se judecă mai jos, pe rândul rezultat.
    foreach (product_trepte_din($b) as $col => $v) {
        $sets[] = $col . ' = ?';
        $vals[] = $v;
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
    if (array_key_exists('sort', $b) && !$numerotat) { $sets[] = 'sort = ?'; $vals[] = (int) $b['sort']; }

    if (!$sets && $numarCerut === null) {
        fail('Nu ai trimis nimic de schimbat.', 422, 'nothing_to_update');
    }

    // RÂNDUL REZULTAT: cel din bază, peste care s-a pus ce trimite formularul.
    // Perechile se desfac la fel ca în `audit_diff()`, care citește chiar
    // aceleași două șiruri câteva rânduri mai jos.
    $rezultat = $p;
    foreach ($sets as $i => $set) {
        $rezultat[trim(explode('=', (string) $set, 2)[0])] = $vals[$i];
    }
    if (catalog_laturi_supported()) {
        product_laturi_verifica($rezultat);
    }
    // Treptele se judecă numai când cererea atinge un preț ori un prag. O
    // bifă de catalog sau „nou" n-are de ce să fie oprită de o scară strâmbă
    // scrisă cu mâna în bază — iar `latura_scara()` o sare oricum.
    if (product_trepte_atinse($b) || array_key_exists('priceFrozen', $b) || array_key_exists('priceBaked', $b)) {
        product_trepte_verifica($rezultat);
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
        'price_baked'    => 'preț copt',
        'price_frozen_b' => 'preț B congelat',
        'price_frozen_c' => 'preț C congelat',
        'price_baked_b'  => 'preț B copt',
        'price_baked_c'  => 'preț C copt',
        'prag_b'         => 'prag B',
        'prag_c'         => 'prag C',
        'in_frozen'      => ['în congelate', ['1' => 'da', '0' => 'nu']],
        'in_baked'       => ['în coapte', ['1' => 'da', '0' => 'nu']],
        'is_new'         => ['nou', ['1' => 'da', '0' => 'nu']],
        'active'         => ['în catalog', ['1' => 'da', '0' => 'nu']],
        'sort'           => 'ordine',
    ]);
    $vals[] = $id;

    if ($numarCerut === null || !$numerotat) {
        // Fără 029 se ajunge aici și cu numărul pe care produsul îl are deja
        // (cheia lui): nimic de scris, deci nici de trecut în jurnal.
        if ($sets) {
            q('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
            audit('product_update', 'product', (string) $id,
                $p['name'] . ($schimbari === '' ? '' : ' · ' . $schimbari));
        }
        json_out(['ok' => true]);
    }

    // Cu număr nou: restul câmpurilor și mutarea se scriu deodată, sub lacăt.
    // Numerele se citesc abia aici, nu din `$p`: între timp le-a putut muta
    // alt panou, iar o mutare socotită pe numere vechi ar lăsa două produse
    // pe același loc.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $numere = product_numere_sub_lacat();
        $mutari = catalog_numar_mutari($numere, $id, $numarCerut);
        if ($mutari === null) {
            $pdo->rollBack();
            product_numar_plin($numarCerut);
        }
        if ($sets) {
            q('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
        }
        product_numere_scrie($mutari);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Numărul pe care produsul îl avea deja nu mută nimic; trimis singur, nu
    // lasă nici rând în jurnal — n-a schimbat nimic.
    $poveste = $mutari ? catalog_numar_poveste($numere, $id, $mutari) : '';
    $detalii = array_values(array_filter([$schimbari, $poveste], static fn($s) => $s !== ''));
    if ($sets || $mutari) {
        audit('product_update', 'product', (string) $id,
            $p['name'] . ($detalii ? ' · ' . implode(' · ', $detalii) : ''));
    }
    $altii = $mutari;
    unset($altii[$id]);
    json_out(['ok' => true, 'numar' => $numarCerut, 'mutate' => product_numere_mutate($numere, $altii)]);
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

    // MICȘORAREA, îndată ce fișierul e pe disc.
    //
    // Nu poate strica urcarea: dacă nu se poate face, poza rămâne așa cum a
    // venit și raportul spune de ce. De-aia stă ÎNAINTEA rândului din bază —
    // nu fiindcă ar avea nevoie de el, ci ca numele scris în bază să arate
    // spre fișierul în forma lui de pe urmă, nu spre unul care tocmai se
    // rescrie.
    $mic = poza_micsoreaza($dir . '/' . $file, $ext);

    $old = (string) ($p[$col] ?? '');
    $web = photo_web_prefix() . $file;
    // `$col` nu vine de la client: `latura_coloana_poza()` întoarce una din
    // două vorbe scrise în cod, oricare ar fi textul primit.
    q('UPDATE products SET ' . $col . ' = ? WHERE id = ?', [$web, $id]);

    // Poza dinainte se șterge doar dacă a fost încărcată tot de aici: cele
    // venite odată cu proiectul pot fi folosite și în altă parte.
    //
    // „Dinainte" înseamnă cea a LATURII ĂSTEIA. Cu `$p['photo']` scris aici,
    // fiecare urcare pentru coapte ar fi șters poza congelată de pe disc
    // — iar rândul din bază ar fi rămas, deci poza s-ar fi rupt tăcut.
    //
    // ȘI NICI DACĂ CEALALTĂ LATURĂ O MAI FOLOSEȘTE. Două coloane pot arăta
    // spre același fișier: așa le-a lăsat `sql/027_poza_fiecarei_laturi.sql`,
    // care a copiat poza congelată în latura goală ca tăierea căderii să nu
    // golească vitrina. Numele fișierului e acolo de două ori, dar fișierul e
    // unul singur — iar codul de mai jos presupunea tăcut că fiecare fișier
    // are un singur stăpân. Fără rândul ăsta, prima poză nouă de congelate ar
    // fi șters de pe disc tocmai fișierul spre care `photo_baked` încă arată,
    // și poza de la coapte s-ar fi rupt în tăcere, la fiecare produs,
    // în ziua următoare migrării.
    $cealaltaCol = latura_coloana_poza($latura === 'copt' ? 'congelat' : 'copt');
    $tinutDeCealalta = (string) ($p[$cealaltaCol] ?? '') === $old;
    if (is_uploaded_photo($old) && !$tinutDeCealalta) {
        @unlink($dir . '/' . basename($old));
    }

    audit('product_photo', 'product', (string) $id, $p['name'] . ' — ' . latura_cuvant($latura));
    // Panoul spune omului ce s-a întâmplat cu poza lui. Fără rândurile astea,
    // o poză de 9 MB s-ar face 300 KB în tăcere, iar patronul n-ar avea de
    // unde ști nici că s-a micșorat, nici că nu s-a micșorat.
    json_out([
        'ok'     => true,
        'photo'  => $web,
        'latura' => $latura,
        'poza'   => [
            'micsorata' => $mic['facut'],
            'pricina'   => $mic['pricina'],
            'puncte'    => $mic['la'][0] . '×' . $mic['la'][1],
            'octetiDe'  => $mic['octetiDe'],
            'octetiLa'  => $mic['facut'] ? $mic['octetiLa'] : $mic['octetiDe'],
            // Și mărimile SCRISE, fiindcă cine le arată nu trebuie să le și
            // socotească: „9,4 MB" iese din aceeași funcție care scrie
            // promisiunea de sub caseta de fișier, deci nu pot ieși din pas.
            'scrisDe'   => poza_marime_text($mic['octetiDe']),
            'scrisLa'   => poza_marime_text($mic['facut'] ? $mic['octetiLa'] : $mic['octetiDe']),
        ],
    ]);
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
        $price($b['priceBaked'] ?? null, 'pentru coapte'),
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
            // Caseta se numește cum scrie în formular: din runda 2.5.0 fiecare
            // latură are panoul ei, cu titlul laturii, iar în el trei prețuri —
            // A, B, C. Cel cerut aici e A, singurul fără de care latura nu se
            // poate vinde; B și C rămân de voie.
            fail(
                'Produsul e în catalogul „' . latura_nume($lat) . '”, dar n-are prețul de acolo.'
                  . ' Scrie prețul A din panoul „' . latura_nume($lat) . '”,'
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

/**
 * Câmpurile celor trei prețuri, așa cum le trimite panoul, și coloanele lor.
 *
 * O singură listă, citită și la creare, și la modificare — ca un câmp nou să
 * nu poată fi primit într-un loc și ignorat tăcut în celălalt.
 */
const PROD_TREPTE_CAMPURI = [
    'priceFrozenB' => 'price_frozen_b',
    'priceFrozenC' => 'price_frozen_c',
    'priceBakedB'  => 'price_baked_b',
    'priceBakedC'  => 'price_baked_c',
    'pragB'        => 'prag_b',
    'pragC'        => 'prag_c',
];

/** Cererea atinge vreun câmp al treptelor? */
function product_trepte_atinse(array $b): bool
{
    foreach (array_keys(PROD_TREPTE_CAMPURI) as $in) {
        if (array_key_exists($in, $b)) {
            return true;
        }
    }
    return false;
}

/**
 * Câmpurile treptelor din cerere, verificate ca FORMĂ: prețuri în lei, praguri
 * în bucăți întregi. Ordinea dintre ele se judecă pe rândul rezultat, în
 * `product_trepte_verifica()`.
 *
 * Întoarce perechi coloană => valoare, numai pentru câmpurile trimise. Gol
 * înseamnă „fără treapta asta" și se scrie NULL.
 *
 * O bază fără migrarea 028 n-are unde scrie: cererea care aduce un preț B se
 * refuză atunci cu 503, pe față — altfel patronul ar scrie B, ar primi „salvat",
 * iar clientul ar plăti tot A.
 *
 * @return array<string, int|float|null>
 */
function product_trepte_din(array $b): array
{
    if (!product_trepte_atinse($b)) {
        return [];
    }
    $ceva = false;
    foreach (array_keys(PROD_TREPTE_CAMPURI) as $in) {
        $v = $b[$in] ?? null;
        if ($v !== null && $v !== '') {
            $ceva = true;
        }
    }
    if (!catalog_trepte_supported()) {
        if (!$ceva) {
            // Un panou nou peste baza veche trimite câmpurile goale la fiecare
            // salvare. Goale, n-au ce strica: se trec cu vederea, ca restul
            // salvării să meargă.
            return [];
        }
        fail(
            'Prețurile B și C nu se pot salva încă: baza de date nu are migrarea 028. '
              . 'Importă sql/028_preturi_pe_trepte.sql și încearcă din nou.',
            503,
            'tiers_unavailable'
        );
    }

    $pret = static function ($v, string $label) {
        if ($v === null || $v === '') {
            return null;
        }
        $brut  = is_int($v) || is_float($v) || is_string($v) ? trim((string) $v) : '';
        $curat = str_replace(',', '.', $brut);
        if (!preg_match('/^\d+(\.\d*)?$/', $curat) || (float) $curat <= 0 || (float) $curat > 100000) {
            $vazut = $brut === '' ? '' : ' Am primit „' . mb_substr($brut, 0, 40) . '”.';
            fail('Prețul ' . $label . ' trebuie să fie un număr între 0,01 și 100000 lei, de pildă „2,60”.'
                . $vazut, 422, 'bad_tier_price');
        }
        return round((float) $curat, 2);
    };
    $prag = static function ($v, string $label) {
        if ($v === null || $v === '') {
            return null;
        }
        $brut = is_int($v) || is_string($v) ? trim((string) $v) : '';
        // Bucăți întregi. De la 2: pragul 1 ar face din B prețul oricui. Până
        // la 10000: atâtea bucăți primește cel mult o poziție de comandă, deci
        // un prag mai mare n-ar putea fi atins niciodată.
        if (!preg_match('/^\d+$/', $brut) || (int) $brut < 2 || (int) $brut > 10000) {
            $vazut = $brut === '' ? '' : ' Am primit „' . mb_substr($brut, 0, 40) . '”.';
            fail('Pragul ' . $label . ' trebuie să fie un număr întreg de bucăți, între 2 și 10000.'
                . $vazut, 422, 'bad_threshold');
        }
        return (int) $brut;
    };

    $etichete = [
        'priceFrozenB' => [$pret, 'B pentru congelate'],
        'priceFrozenC' => [$pret, 'C pentru congelate'],
        'priceBakedB'  => [$pret, 'B pentru coapte'],
        'priceBakedC'  => [$pret, 'C pentru coapte'],
        'pragB'        => [$prag, 'prețului B'],
        'pragC'        => [$prag, 'prețului C'],
    ];
    $out = [];
    foreach (PROD_TREPTE_CAMPURI as $in => $col) {
        if (array_key_exists($in, $b)) {
            $out[$col] = $etichete[$in][0]($b[$in], $etichete[$in][1]);
        }
    }
    return $out;
}

/**
 * ORDINEA TREPTELOR, judecată pe RÂNDUL REZULTAT — din același motiv ca
 * `product_laturi_verifica()`: „scriu B și pragul lui în aceeași salvare"
 * trebuie să treacă, iar „scumpesc A peste B" trebuie prins chiar dacă B nu
 * era în cerere.
 *
 * Regulile, fiecare cu codul ei, ca panoul să poată arăta lângă caseta
 * potrivită ce e de îndreptat:
 *
 *   `tier_needs_base`       B fără A pe aceeași latură
 *   `tier_b_not_lower`      B nu e mai mic decât A
 *   `tier_c_needs_b`        C fără B pe aceeași latură
 *   `tier_c_not_lower`      C nu e mai mic decât B
 *   `tier_needs_threshold`  un preț B fără pragul B, ori un C fără pragul C
 *   `threshold_order`       pragul C nu e mai mare decât pragul B
 *
 * Un prag fără niciun preț al treptei lui e îngăduit: e doar valoarea de
 * pornire a casetei, iar `latura_scara()` n-are ce face cu el.
 *
 * Pe un rând fără coloanele lui 028 nu e nimic de judecat.
 */
function product_trepte_verifica(array $r): void
{
    if (!array_key_exists('prag_b', $r)) {
        return;
    }
    $n    = static fn($v) => ($v === null || $v === '') ? null : (float) $v;
    $bani = static fn(float $v) => number_format($v, 2, ',', '.');

    $pragB = $n($r['prag_b'] ?? null);
    $pragC = $n($r['prag_c'] ?? null);
    $areB  = false;
    $areC  = false;

    foreach (laturi() as $lat) {
        $a    = $n($r[latura_coloana_treapta($lat, 1)] ?? null);
        $b    = $n($r[latura_coloana_treapta($lat, 2)] ?? null);
        $c    = $n($r[latura_coloana_treapta($lat, 3)] ?? null);
        $nume = latura_cuvant($lat);

        if ($b !== null) {
            $areB = true;
            if ($a === null) {
                fail('Prețul B pentru ' . $nume . ' are nevoie de prețul A de acolo. '
                    . 'Scrie întâi prețul obișnuit, apoi pe cel de la volum.', 422, 'tier_needs_base');
            }
            if ($b >= $a) {
                fail('Prețul B pentru ' . $nume . ' (' . $bani($b) . ' lei) trebuie să fie mai mic '
                    . 'decât prețul A (' . $bani($a) . ' lei): cine cumpără mai mult plătește mai '
                    . 'puțin pe bucată.', 422, 'tier_b_not_lower');
            }
        }
        if ($c !== null) {
            $areC = true;
            if ($b === null) {
                fail('Prețul C pentru ' . $nume . ' are nevoie de prețul B de acolo: '
                    . 'treptele urcă una câte una.', 422, 'tier_c_needs_b');
            }
            if ($c >= $b) {
                fail('Prețul C pentru ' . $nume . ' (' . $bani($c) . ' lei) trebuie să fie mai mic '
                    . 'decât prețul B (' . $bani($b) . ' lei).', 422, 'tier_c_not_lower');
            }
        }
    }

    if ($areB && $pragB === null) {
        fail('Scrie de la câte bucăți începe prețul B.', 422, 'tier_needs_threshold');
    }
    if ($areC && $pragC === null) {
        fail('Scrie de la câte bucăți începe prețul C.', 422, 'tier_needs_threshold');
    }
    if ($pragC !== null && ($pragB === null || $pragC <= $pragB)) {
        fail(
            $pragB === null
                ? 'Pragul C are nevoie de pragul B: scrie întâi de la câte bucăți începe prețul B.'
                : 'Pragul C (' . (int) $pragC . ' buc.) trebuie să fie mai mare decât pragul B ('
                    . (int) $pragB . ' buc.).',
            422,
            'threshold_order'
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
