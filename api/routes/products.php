<?php
/** Catalogul de produse. Public: site-ul îl afișează fără autentificare. */

declare(strict_types=1);

/**
 * GET /products[?all=1]
 *
 * Fără parametru: doar produsele din catalog — asta vede site-ul.
 * Cu `all=1` și cont de administrator: și cele scoase din catalog, ca să le
 * poți repune din pagina Produse.
 */
function route_products_list(): void
{
    $u   = current_user();
    $all = isset($_GET['all']) && $u && $u['role'] === 'admin';

    $rows = all(
        'SELECT id, slug, name, name_en, description, description_en, photo, grams,
                price_frozen, price_baked, category, is_new, active, sort
           FROM products'
        . ($all ? '' : ' WHERE active = 1')
        . ' ORDER BY sort, id'
    );

    // Grupele merg alături: site-ul are nevoie de titlurile lor, iar pagina de
    // administrare de lista din care alegi.
    $cats = all(
        'SELECT * FROM product_categories' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort, id'
    );

    json_out([
        'products'   => array_map('product_public', $rows),
        'categories' => array_map('category_public', $cats),
    ]);
}

/**
 * GET /lists — listele configurabile din Setări.
 *
 * Publică: formularul de cerere de pe site are nevoie de intervale și zone.
 * Nu conține nimic sensibil, doar vocabularul aplicației.
 */
function route_lists_get(): void
{
    json_out([
        'statuses'    => order_statuses(),
        'done'        => status_done(),
        'cancelled'   => status_cancelled(),
        'slots'       => delivery_slots(),
        'zones'       => delivery_zones(),
        'fulfillment' => fulfillment_types(),
        // Care dintre moduri înseamnă „clientul vine după marfă”. Serverul o
        // folosea deja ca să decidă ce urcă în mașină (route_stop_filter);
        // panourile n-o primeau, deci își țineau propria hartă de trei nume
        // scrise în cod, iar un mod adăugat din Setări rămânea gri pe vecie.
        'fulfillmentPickup' => pickup_fulfillments(),
        'stockCategories' => stock_categories(),
        'stockUnits'      => stock_units(),
        // Punctul de plecare al livrărilor, pentru calculul rutei.
        // Punctul folosit la estimarea distanțelor, pentru fiecare zonă.
        // O zonă fără coordonate cade pe depozit, ca ruta să nu se strice.
        'zoneCoords' => zone_coords(),
        // Telefonul și adresa de e-mail publice, din Setări. Erau scrise în
        // pagina magazinului, deci numărul schimbat din panou nu ajungea
        // niciodată la vizitator: el suna în continuare la cel vechi, în timp
        // ce datele trimise motoarelor de căutare arătau numărul nou.
        'contact' => [
            'phone' => setting('company_phone'),
            'email' => setting('company_email'),
        ],
        'hq' => [
            'label' => setting('hq_label', 'Depozit'),
            'lat'   => hq_coord('hq_lat', 44.39235, 90.0),
            'lng'   => hq_coord('hq_lng', 26.12385, 180.0),
        ],
        // Marginile câmpului „Data dorită" din formularul de pe site.
        // Vin de la server, nu din ceasul browserului: un telefon rămas cu
        // data greșită i-ar fi arătat clientului alt „azi" decât cel după care
        // se ia patiseria, iar cererea lui ar fi picat la acceptare.
        'orderWindow' => (static function (): array {
            [$prima, $ultima] = order_day_window();
            return ['first' => $prima, 'last' => $ultima];
        })(),
    ]);
}

/**
 * O coordonată a depozitului, adusă la număr.
 *
 * `(float) setting(...)` lua orice: „44,39235” — forma pe care o dă tastatura
 * românească — se citea ca 44, adică depozitul cu 43 de kilometri mai la sud,
 * iar un text fără cifre se citea ca 0, punctul din Golful Guineei. De aici
 * pleacă `HQ_OF()` din Ruta Șofer și din Administrare Comenzi, deci greșeala
 * se vedea doar ca niște kilometri mai mari decât se aștepta cineva.
 *
 * Salvarea o oprește de acum din Setări (guard_hq_coords), dar citirea se
 * apără și ea: în baza de date pot sta valori scrise înainte de verificarea
 * aceea, exact cum face zone_coords() pentru zonele salvate cândva cu 0,0.
 * Ce nu se poate folosi ca punct pe hartă cade pe depozitul din cod — un loc
 * real, nu unul din ocean.
 */
function hq_coord(string $key, float $implicit, float $max): float
{
    $raw = str_replace(',', '.', trim(setting($key)));
    if (!is_numeric($raw)) {
        return $implicit;
    }
    $v = (float) $raw;
    // Zero înseamnă tot „necompletat”: depozitul e în București, nu pe ecuator.
    return ($v == 0.0 || $v < -$max || $v > $max) ? $implicit : $v;
}
