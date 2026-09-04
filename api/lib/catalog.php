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
