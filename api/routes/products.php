<?php
/** Catalogul de produse. Public: site-ul îl afișează fără autentificare. */

declare(strict_types=1);

/**
 * GET /products[?all=1]
 *
 * Fără parametru: produsele din catalog. Cu `all=1` și cont de administrator:
 * și cele scoase din catalog, ca să le poți repune din pagina Produse.
 *
 * Aici NU se filtrează după preț, deși magazinul o face. Nu din scăpare: ruta
 * asta e folosită și de panoul de comenzi, unde personalul scrie numele
 * produsului ca să ia o comandă la telefon. Dacă produsul ar lipsi de aici,
 * omul ar citi "numele nu există în catalog" — ceea ce e fals — în loc de
 * refuzul adevărat al serverului, care spune care produs și care variantă n-au
 * preț (`api/lib/orders.php`, `missing_price`). Un refuz care numește cauza
 * face reparația; unul care ghicește o ascunde.
 *
 * Cine ascunde produsul fără preț, așadar: magazinul în browser (filtrul lui
 * `PROD` din `Site Public.dc.html`), pagina scrisă pe server și harta sitului
 * (`seo/index.php`, `seo/sitemap.php`). Că spun toate trei același lucru o
 * ține `.claude/test-defect-pret-gol.mjs`.
 */
function route_products_list(): void
{
    $u   = current_user();
    $all = isset($_GET['all']) && $u && e_admin($u);

    // CELE TREI COLOANE ALE LATURILOR se cer numai dacă baza le are.
    //
    // Pe server fișierele ajung ÎNAINTEA importului SQL, deci există o
    // fereastră în care codul ăsta rulează peste baza veche. O coloană cerută
    // și negăsită nu dă un câmp gol — dă eroare de SQL, adică magazinul stins
    // pentru toată lumea. `api/lib/laturi.php` știe să răspundă corect și
    // peste un rând fără ele.
    //
    // ȘI DE CE S-AU ADĂUGAT AICI, DEȘI RUTA PĂREA ÎNTREAGĂ. Lista de coloane e
    // scrisă cu mâna, iar calea de scriere citește rândul cu `SELECT *`: poza
    // de la coapte se salva cum trebuie, dar nu se mai întorcea
    // niciodată. Mai rău: nu se vedea că lipsește. Fără `in_frozen` în rând,
    // `latura_in()` cade pe purtarea de dinainte de 024 („are amândouă
    // prețurile") și răspunde ceva CREDIBIL — deci o latură stinsă de patron
    // s-ar fi întors aprinsă în panou, fără nicio eroare nicăieri.
    $laturi = catalog_laturi_supported() ? ', photo_baked, in_frozen, in_baked' : '';

    // Prețurile B și C (migrarea 028), cu aceeași poartă și din aceeași pricină.
    // Lăsate afară, scara din `product_public()` ar ieși „numai A" pe fiecare
    // produs — un răspuns credibil, deci unul pe care nu-l prinde nimeni.
    $rows = all(
        'SELECT id, slug, name, name_en, description, description_en, photo, grams,
                price_frozen, price_baked, category, is_new, active, sort' . $laturi
        . catalog_trepte_select()
        . ' FROM products'
        . ($all ? '' : ' WHERE active = 1')
        . ' ORDER BY sort, id'
    );

    // Grupele merg alături: site-ul are nevoie de titlurile lor, iar pagina de
    // administrare de lista din care alegi.
    $cats = all(
        'SELECT * FROM product_categories' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort, id'
    );

    // Câte comenzi și câte articole de depozit țin fiecare produs pe loc.
    //
    // De ce numai pentru panou. Cifrele astea spun cât s-a vândut din fiecare
    // produs. Ruta răspunde și fără sesiune — magazinul o cheamă la fiecare
    // vizitator — deci stau sub $all, care e deja poarta de administrator.
    //
    // De ce există. Butonul × din Catalog cere ȘTERGEREA produsului, iar
    // serverul alege singur între ștergere de tot (cu fotografie cu tot) și
    // simpla scoatere din catalog, după cât istoric are produsul. Omul apăsa
    // același × în amândouă cazurile și afla DUPĂ care din două s-a întâmplat.
    // Cu cifrele astea în listă, pagina poate scrie confirmarea dinainte.
    //
    // Două interogări grupate, nu una pe produs: catalogul are zeci de rânduri
    // și se cere întreg la fiecare împrospătare.
    $produse = array_map('product_public', $rows);
    if ($all) {
        $inComenzi = [];
        // `DISTINCT`, fiindcă se numără comenzi, nu poziții: aceeași comandă
        // poate avea produsul și congelat, și copt. Vezi `catalog.php`, unde
        // aceeași cifră ajunge în refuzul scris omului.
        foreach (all('SELECT product_id, COUNT(DISTINCT order_id) AS n FROM order_items
                       WHERE product_id IS NOT NULL GROUP BY product_id') as $g) {
            $inComenzi[(int) $g['product_id']] = (int) $g['n'];
        }
        // Cererile de pe site sunt și ele istoric: pozițiile lor trimit la
        // produs, iar ștergerea rândului le-ar lăsa fără nume.
        foreach (all('SELECT product_id, COUNT(DISTINCT request_id) AS n FROM order_request_items
                       WHERE product_id IS NOT NULL GROUP BY product_id') as $g) {
            $k = (int) $g['product_id'];
            $inComenzi[$k] = ($inComenzi[$k] ?? 0) + (int) $g['n'];
        }
        $inDepozit = [];
        foreach (all('SELECT product_id, COUNT(*) AS n FROM stock_items
                       WHERE product_id IS NOT NULL GROUP BY product_id') as $g) {
            $inDepozit[(int) $g['product_id']] = (int) $g['n'];
        }
        foreach ($produse as &$pr) {
            $pr['inOrders'] = $inComenzi[$pr['id']] ?? 0;
            $pr['inStock']  = $inDepozit[$pr['id']] ?? 0;
        }
        unset($pr);
    }

    json_out([
        'products'   => $produse,
        'categories' => array_map('category_public', $cats),
        // Reducerile merg alături de catalog fiindcă se socotesc DIN el: cardul
        // pachetului de test își scrie prețul tăiat și pe cel plătit înmulțind
        // prețurile de mai sus cu procentul de aici. Cifrele astea stăteau până
        // acum scrise în pagină (`TRIAL_EACH`, `TRIAL_OFF`) — adică exact felul
        // în care ecranul și banii ajung să spună lucruri diferite. Venind de
        // aici, pagina nu mai POATE arăta altă reducere decât cea pe care
        // serverul chiar o aplică.
        //
        // `false` când baza n-are migrarea 011: pagina ascunde atunci pachetul,
        // în loc să ofere o reducere pe care serverul o va refuza.
        'promos'     => promo_public(),
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
        // Până unde poate duce LABORATORUL o comandă: etapa dinaintea celei
        // finale. Se socotește pe server (`status_lab_ceiling()`), nu în
        // browser din `statuses` și `done`: socotită în pagină, ar fi a doua
        // copie a aceleiași reguli, iar cele două pagini care o citesc s-ar fi
        // despărțit la prima corectură. Tot din Setări, deci o etapă
        // redenumită nu lasă laboratorul nici blocat, nici cu una în plus.
        'labCeiling'  => status_lab_ceiling(),
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
        // Adresa și programul, din același izvor din care le ia pagina scrisă
        // pe server pentru motoarele de căutare (`api/lib/firma.php`, chemat și
        // din `seo/index.php`). Erau scrise în magazin, de mână, în șapte
        // locuri — iar ce scria în magazin nu era doar altă FORMĂ a aceluiași
        // program, ci alt program: pagina zicea „Luni–Vineri 07:00–21:00,
        // Sâmbătă 07:00–17:00, Duminică închis", Google afla „Luni–Sâmbătă
        // 05:00–21:00". Unul din cei doi clienți venea la ușă închisă.
        //
        // Orele se despart pe zile AICI, nu în browser: desfacerea rândului
        // scris de om („Lu-Vi 07:00-21:00, Sâ 07:00-17:00") e singurul loc unde
        // se hotărăște ce înseamnă el, iar o a doua desfacere, în JavaScript,
        // ar fi exact al doilea izvor pe care îl astupăm.
        'contact' => (static function (): array {
            $f = firma_publica(setting('company_address'), setting('company_hours'));
            return [
                'phone'      => setting('company_phone'),
                'email'      => setting('company_email'),
                'address'    => $f['adresa'],
                'addressFull'   => $f['intreaga'],
                'addressFullEn' => $f['intreagaEn'],
                'mapUrl'     => $f['harta'],
                // `spec` — forma schema.org — rămâne afară dinadins: o citește
                // numai pagina scrisă pe server, iar aici ar merge la fiecare
                // deschidere a magazinului, degeaba.
                'hours'      => [
                    'ro'   => $f['program']['ro'],
                    'en'   => $f['program']['en'],
                    'days' => $f['program']['zile'],
                ],
            ];
        })(),
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
        // Catalogul, cum l-a ales patronul în Setări: vederea cu care se
        // deschide magazinul (pe telefon și pe calculator) și pragurile cu care
        // se precompletează un produs nou. Publice fiindcă magazinul le citește
        // înainte să știe cine e vizitatorul — și n-au nimic de ascuns.
        'catalog' => catalog_implicite(),
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
