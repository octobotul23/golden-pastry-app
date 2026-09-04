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

/* ------------------------------------------------------------- categorii -- */

/** GET /categories — grupele din catalog. Public. */
function route_categories_list(): void
{
    $all = isset($_GET['all']) && current_user() && current_user()['role'] === 'admin';
    $rows = all(
        'SELECT * FROM product_categories' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort, id'
    );
    json_out(['categories' => array_map('category_public', $rows)]);
}

function route_categories_create(): void
{
    require_role('admin');
    $b = body();

    $name   = trim((string) ($b['name'] ?? ''));
    $nameEn = trim((string) ($b['nameEn'] ?? ''));
    if ($name === '' || $nameEn === '') {
        fail('Numele grupei este obligatoriu în ambele limbi.', 422, 'missing_name');
    }

    $slug = slugify(trim((string) ($b['slug'] ?? '')) ?: $name);
    if ($slug === '') {
        fail('Nu pot construi o cheie din numele dat. Scrie una manual.', 422, 'bad_slug');
    }
    if (scalar('SELECT 1 FROM product_categories WHERE slug = ?', [$slug])) {
        fail('Există deja o grupă cu cheia „' . $slug . '”.', 409, 'slug_taken');
    }

    $sort = (int) scalar('SELECT COALESCE(MAX(sort), 0) + 10 FROM product_categories');
    q(
        'INSERT INTO product_categories (slug, name, name_en, note, note_en, sort) VALUES (?, ?, ?, ?, ?, ?)',
        [$slug, $name, $nameEn, trim((string) ($b['note'] ?? '')) ?: null,
         trim((string) ($b['noteEn'] ?? '')) ?: null, $sort]
    );
    $id = (int) db()->lastInsertId();

    audit('category_create', 'category', (string) $id, $name);
    json_out(['id' => $id, 'slug' => $slug], 201);
}

function route_categories_update(string $id): void
{
    require_role('admin');
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
            $v = trim((string) $b[$in]);
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
            $vals[] = trim((string) $b[$in]) ?: null;
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

    $vals[] = $id;
    q('UPDATE product_categories SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);

    audit('category_update', 'category', (string) $id, $cat['name']);
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
    require_role('admin');
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
    require_role('admin');
    $b = body();

    [$name, $nameEn, $desc, $descEn] = product_texts($b, true);
    $category = product_category($b);

    $slug = slugify(trim((string) ($b['slug'] ?? '')) ?: $name);
    if ($slug === '') {
        fail('Nu pot construi o adresă din numele dat. Scrie una manual.', 422, 'bad_slug');
    }
    if (scalar('SELECT 1 FROM products WHERE slug = ?', [$slug])) {
        fail('Există deja un produs cu adresa „' . $slug . '”.', 409, 'slug_taken');
    }

    [$grams, $horeca, $retail] = product_numbers($b);

    // Amândouă prețurile sunt obligatorii: catalogul de pe site le afișează pe
    // rând, după cum alege clientul, iar unul lipsă nu doar că lasă un gol —
    // oprea randarea întregii pagini. Un produs fără preț oricum nu poate fi
    // vândut.
    if ($horeca === null || $retail === null) {
        fail('Completează ambele prețuri: cel pentru HoReCa și cel de raft.', 422, 'missing_price');
    }

    $sort = (int) scalar('SELECT COALESCE(MAX(sort), 0) + 10 FROM products');
    q(
        'INSERT INTO products (slug, name, name_en, description, description_en, grams,
                               price_frozen, price_baked, category, is_new, sort)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$slug, $name, $nameEn, $desc, $descEn, $grams, $horeca, $retail,
         $category, !empty($b['isNew']) ? 1 : 0, $sort]
    );
    $id = (int) db()->lastInsertId();

    audit('product_create', 'product', (string) $id, $name);
    json_out(['id' => $id, 'slug' => $slug], 201);
}

function route_products_update(string $id): void
{
    require_role('admin');
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
    foreach (['name' => 'name', 'nameEn' => 'name_en',
              'description' => 'description', 'descriptionEn' => 'description_en'] as $in => $col) {
        if (!array_key_exists($in, $b)) {
            continue;
        }
        $v = trim((string) $b[$in]);
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
        if (array_key_exists('priceFrozen', $b)) {
            if ($horeca === null) {
                fail('Prețul pentru HoReCa nu poate rămâne gol.', 422, 'missing_price');
            }
            $sets[] = 'price_frozen = ?';
            $vals[] = $horeca;
        }
        if (array_key_exists('priceBaked', $b)) {
            if ($retail === null) {
                fail('Prețul de raft nu poate rămâne gol.', 422, 'missing_price');
            }
            $sets[] = 'price_baked = ?';
            $vals[] = $retail;
        }
    }
    if (array_key_exists('isNew', $b))  { $sets[] = 'is_new = ?'; $vals[] = !empty($b['isNew']) ? 1 : 0; }
    if (array_key_exists('active', $b)) { $sets[] = 'active = ?'; $vals[] = !empty($b['active']) ? 1 : 0; }
    if (array_key_exists('sort', $b))   { $sets[] = 'sort = ?';   $vals[] = (int) $b['sort']; }

    if (!$sets) {
        fail('Nu ai trimis nimic de schimbat.', 422, 'nothing_to_update');
    }

    $vals[] = $id;
    q('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);

    audit('product_update', 'product', (string) $id, $p['name']);
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
    require_role('admin');
    $id = (int) $id;
    $p  = one('SELECT * FROM products WHERE id = ?', [$id]);
    if (!$p) {
        fail('Produsul nu există.', 404, 'not_found');
    }

    $inOrders = (int) scalar('SELECT COUNT(*) FROM order_items WHERE product_id = ?', [$id])
        + (int) scalar('SELECT COUNT(*) FROM order_request_items WHERE product_id = ?', [$id]);
    $inStock  = (int) scalar('SELECT COUNT(*) FROM stock_items WHERE product_id = ?', [$id]);

    if ($inOrders === 0 && $inStock === 0) {
        // Fotografia urcată din panou nu mai folosește nimănui.
        if (is_uploaded_photo((string) $p['photo'])) {
            @unlink(photo_dir() . '/' . basename((string) $p['photo']));
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
    require_role('admin');
    $id = (int) $id;
    $p  = one('SELECT * FROM products WHERE id = ?', [$id]);
    if (!$p) {
        fail('Produsul nu există.', 404, 'not_found');
    }

    $f = $_FILES['photo'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $why = match ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE)) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fișierul e mai mare decât acceptă serverul.',
            UPLOAD_ERR_PARTIAL   => 'Încărcarea s-a întrerupt. Încearcă din nou.',
            UPLOAD_ERR_NO_FILE   => 'Nu ai ales niciun fișier.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Serverul nu poate scrie fișiere temporare.',
            default              => 'Încărcarea a eșuat.',
        };
        fail($why, 422, 'upload_failed');
    }

    $maxBytes = 5 * 1024 * 1024;
    if ((int) $f['size'] > $maxBytes) {
        fail('Fotografia depășește 5 MB. Micșoreaz-o și încearcă din nou.', 422, 'too_large');
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

    $old = (string) $p['photo'];
    $web = photo_web_prefix() . $file;
    q('UPDATE products SET photo = ? WHERE id = ?', [$web, $id]);

    // Poza dinainte se șterge doar dacă a fost încărcată tot de aici: cele
    // venite odată cu proiectul pot fi folosite și în altă parte.
    if (is_uploaded_photo($old)) {
        @unlink($dir . '/' . basename($old));
    }

    audit('product_photo', 'product', (string) $id, $p['name']);
    json_out(['ok' => true, 'photo' => $web]);
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
    $name   = trim((string) ($b['name'] ?? ''));
    $nameEn = trim((string) ($b['nameEn'] ?? ''));
    $desc   = trim((string) ($b['description'] ?? ''));
    $descEn = trim((string) ($b['descriptionEn'] ?? ''));

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
        $price($b['priceFrozen'] ?? null, 'pentru HoReCa'),
        $price($b['priceBaked'] ?? null, 'de raft'),
    ];
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
    require_role('admin');
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
    require_role('admin');
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
    try {
        q('DELETE FROM product_recipes WHERE product_id = ?', [$id]);
        foreach ($curate as $itemId => $qty) {
            q('INSERT INTO product_recipes (product_id, item_id, qty) VALUES (?, ?, ?)', [$id, $itemId, $qty]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('product_recipe', 'product', (string) $id, $produs['name'] . ' · ' . count($curate) . ' linii');

    json_out(['ok' => true, 'lines' => count($curate)]);
}
