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
