<?php
/**
 * Forma publică a catalogului: produse și grupe.
 *
 * Stau aici, nu într-un fișier de rută, fiindcă routerul încarcă DOAR fișierul
 * rutei potrivite: `routes/products.php` (citirea, publică) și
 * `routes/catalog.php` (editarea, pentru admin) nu se văd niciodată între ele.
 * Prima versiune avea `category_public()` doar în al doilea, iar catalogul
 * răspundea cu eroare la fiecare cerere de pe site.
 */

declare(strict_types=1);

/**
 * Știe baza de laturi, adică a intrat migrarea 024?
 *
 * Aceeași formă ca `promo_supported()` (api/lib/promo.php), dar cu o deosebire
 * care contează: la promo, „nu” înseamnă 503 — o reducere pe care serverul n-o
 * poate socoti ar încasa un preț pe care clientul nu l-a văzut. Aici „nu”
 * înseamnă doar „poartă-te ca înainte de 024”, iar purtarea aceea e corectă:
 * produsele cu amândouă prețurile se văd pe amândouă laturile. Un 503 ar opri
 * magazinul pentru o fereastră de câteva minute în care el chiar poate lucra.
 *
 * Răspunsul se ține minte pe cererea asta: se întreabă din mai multe locuri,
 * iar information_schema nu se schimbă în mijlocul unei cereri.
 */
function catalog_laturi_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $coloane = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                AND COLUMN_NAME IN ('in_frozen', 'in_baked', 'photo_baked')"
        );
        return $ok = ($coloane === 3);
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * Coloanele celor trei prețuri (migrarea 028), așa cum se cer într-un SELECT.
 *
 * Scrise o singură dată, fiindcă se cer din trei locuri — lista de produse,
 * tarifarea și editarea — iar o coloană uitată într-unul din ele n-ar da nicio
 * eroare: `latura_scara()` ar răspunde cuminte „numai A", și produsul s-ar vinde
 * tăcut la prețul mare.
 */
const CATALOG_TREPTE_COLOANE = 'price_frozen_b, price_frozen_c, price_baked_b, price_baked_c, prag_b, prag_c';

/**
 * Știe baza de cele trei prețuri, adică a intrat migrarea 028?
 *
 * Se cer ȘI coloanele de pe rândurile de comandă, nu doar cele de pe produs.
 * Pe o bază cu jumătate din migrare — produsele cu trepte, pozițiile fără —
 * comenzile s-ar tarifa la B și s-ar scrie fără treaptă, iar prima corectură
 * n-ar mai ști că B a fost câștigat de un prag. Deci ori tot, ori nimic: fără
 * migrare, casa vinde exact ca ieri, cu prețul A.
 *
 * Spre deosebire de reducere (`promo_supported()`), „nu" nu oprește nimic cu
 * 503: un catalog fără trepte e starea de ieri, nu o stare stricată. Numai
 * scrierea unui preț B sau C se refuză — v. `route_products_update()`.
 */
function catalog_trepte_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $coloane = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND ((TABLE_NAME = 'products'
                      AND COLUMN_NAME IN ('price_frozen_b', 'price_frozen_c', 'price_baked_b',
                                          'price_baked_c', 'prag_b', 'prag_c'))
                  OR (TABLE_NAME IN ('order_items', 'order_request_items')
                      AND COLUMN_NAME IN ('treapta', 'prag_treapta')))"
        );
        return $ok = ($coloane === 10);
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** Bucata de SELECT pentru prețurile pe trepte: goală pe o bază fără 028. */
function catalog_trepte_select(): string
{
    return catalog_trepte_supported() ? ', ' . CATALOG_TREPTE_COLOANE : '';
}

/**
 * Vederile catalogului: un produs pe rând (cu toate detaliile), patru pe ecran,
 * șase pe ecran. Numele sunt cele scrise în Setări (migrarea 028).
 */
function catalog_vederi(): array
{
    return ['unu', 'patru', 'sase'];
}

/**
 * Ce a ales patronul în Setări pentru catalog, gata de citit de pagini.
 *
 * Vederea cu care se deschide magazinul — deosebită pe telefon și pe calculator
 * — și pragurile cu care se precompletează un produs nou în Produse.
 *
 * Citirea se apără ca `hq_coord()`: în bază poate sta orice a scris cineva cu
 * mâna. O vedere necunoscută cade pe cea de pornire; o pereche de praguri
 * strâmbă (C sub B) se dă înapoi fără C, fiindcă un formular precompletat cu o
 * pereche pe care serverul o refuză ar fi o capcană. Un prag gol rămâne gol:
 * patronul poate hotărî să nu precompleteze nimic.
 *
 * @return array{vedereTelefon: string, vedereCalculator: string, pragB: ?int, pragC: ?int}
 */
function catalog_implicite(): array
{
    $vedere = static function (string $cheie, string $implicit): string {
        $v = trim(setting($cheie));
        return in_array($v, catalog_vederi(), true) ? $v : $implicit;
    };
    $prag = static function (string $cheie): ?int {
        $v = trim(setting($cheie));
        return preg_match('/^\d+$/', $v) && (int) $v >= 2 && (int) $v <= 10000 ? (int) $v : null;
    };
    $b = $prag('pret_prag_b_implicit');
    $c = $prag('pret_prag_c_implicit');
    if ($c !== null && ($b === null || $c <= $b)) {
        $c = null;
    }
    return [
        'vedereTelefon'    => $vedere('catalog_vedere_telefon', 'unu'),
        'vedereCalculator' => $vedere('catalog_vedere_calculator', 'patru'),
        'pragB'            => $b,
        'pragC'            => $c,
    ];
}

/** Un număr din bază, sau null. DECIMAL vine ca text, INT ca întreg. */
function catalog_numar_sau_null($v, bool $intreg = false)
{
    if ($v === null || $v === '') {
        return null;
    }
    return $intreg ? (int) $v : (float) $v;
}

function product_public(array $r): array
{
    return [
        'id'            => (int) $r['id'],
        'slug'          => $r['slug'],
        'name'          => $r['name'],
        'nameEn'        => $r['name_en'],
        'description'   => $r['description'],
        'descriptionEn' => $r['description_en'],
        'photo'         => $r['photo'],
        'grams'         => $r['grams'] === null ? null : (int) $r['grams'],
        'priceFrozen'   => $r['price_frozen'] === null ? null : (float) $r['price_frozen'],
        'priceBaked'   => $r['price_baked'] === null ? null : (float) $r['price_baked'],
        'category'      => $r['category'],
        'isNew'         => (bool) $r['is_new'],
        'active'        => !isset($r['active']) || (bool) $r['active'],
        'sort'          => (int) ($r['sort'] ?? 0),

        // APARTENENȚA ȘI POZELE, în două feluri deodată, și amândouă sunt
        // folosite: cele CRUDE sunt ce editează panoul (două bife și două
        // casete de fișier), iar `photoFor` e ce ARATĂ magazinul — poza
        // fiecărei laturi, și atât. De la 21 septembrie 2026 cele două nu mai
        // sunt deosebite de nicio cădere (`latura_poza()` nu mai împrumută
        // poza celeilalte laturi), dar rămân două câmpuri deosebite: „crud"
        // poate fi null acolo unde „de arătat" e tot null, iar magazinul n-are
        // ce face cu apartenența, nici panoul cu forma rezolvată.
        //
        // Pe o bază de dinainte de 024 coloanele lipsesc din rând, iar
        // `latura_in()` răspunde purtarea de până azi. Vezi api/lib/laturi.php.
        'photoBaked'  => $r['photo_baked'] ?? null,
        'inFrozen'    => latura_in($r, 'congelat'),
        'inBaked'     => latura_in($r, 'copt'),
        'photoFor'    => [
            'frozen' => latura_poza($r, 'congelat'),
            'baked'  => latura_poza($r, 'copt'),
        ],

        // CELE TREI PREȚURI, tot în două feluri, din același motiv ca pozele:
        // cele CRUDE (pragurile și prețurile B, C) sunt ce editează panoul, iar
        // `trepte` e scara care CHIAR se aplică — aceeași pe care o folosește
        // tarifarea, din `latura_scara()`. Magazinul citește numai scara, deci
        // nu poate arăta un B pe care serverul nu-l dă.
        //
        // Pe o bază fără 028 câmpurile crude sunt null, iar scara are numai A.
        'pragB'        => catalog_numar_sau_null($r['prag_b'] ?? null, true),
        'pragC'        => catalog_numar_sau_null($r['prag_c'] ?? null, true),
        'priceFrozenB' => catalog_numar_sau_null($r['price_frozen_b'] ?? null),
        'priceFrozenC' => catalog_numar_sau_null($r['price_frozen_c'] ?? null),
        'priceBakedB'  => catalog_numar_sau_null($r['price_baked_b'] ?? null),
        'priceBakedC'  => catalog_numar_sau_null($r['price_baked_c'] ?? null),
        'trepte'       => [
            'frozen' => latura_scara_publica($r, 'congelat'),
            'baked'  => latura_scara_publica($r, 'copt'),
        ],
    ];
}

function category_public(array $r): array
{
    // O singură interogare, două cifre: „toate" numără și produsele scoase din
    // catalog, „in_catalog" doar pe cele care apar pe site.
    $n = one(
        'SELECT COUNT(*) AS toate, SUM(active = 1) AS in_catalog
           FROM products WHERE category = ?',
        [$r['slug']]
    );

    return [
        'id'     => (int) $r['id'],
        'slug'   => $r['slug'],
        'name'   => $r['name'],
        'nameEn' => $r['name_en'],
        'note'   => $r['note'],
        'noteEn' => $r['note_en'],
        'sort'   => (int) $r['sort'],
        'active' => (bool) $r['active'],
        // Câte produse are grupa — dar cifra are două înțelesuri, iar panoul
        // are nevoie de amândouă. `products` sunt cele din catalog (atâtea se
        // văd pe site și atâtea opresc ascunderea grupei), `productsAll` le
        // numără și pe cele scoase din catalog — exact numărul după care se
        // refuză ȘTERGEREA grupei. Fără al doilea, fila Grupe scria „0 produse"
        // pentru o grupă din care produsele fuseseră doar scoase din catalog,
        // iar la × serverul răspundea „grupa are 3 produse în ea": adminul
        // vedea două cifre care se contrazic pe același ecran și nu avea de
        // unde ști care e adevărată.
        'products'    => (int) ($n['in_catalog'] ?? 0),
        'productsAll' => (int) ($n['toate'] ?? 0),
    ];
}
