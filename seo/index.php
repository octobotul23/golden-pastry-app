<?php
/**
 * goldenpastry.ro — învelișul servit de server.
 *
 * Aplicația se construiește în browser: un motor de căutare care nu execută
 * JavaScript vede o pagină goală, iar unul care îl execută are nevoie de un
 * pas în plus și de mai multă încredere. Fișierul acesta trimite din prima
 * cerere, direct în HTML:
 *
 *   · titlul, descrierea și imaginea de partajare — ce apare în rezultatele
 *     căutării și pe WhatsApp când cineva dă mai departe linkul;
 *   · datele structurate schema.org: firma, adresa, programul, telefonul și
 *     catalogul cu prețuri, în forma pe care Google o citește direct;
 *   · catalogul ca text, în <noscript>, pentru cine nu rulează JavaScript.
 *
 * Totul vine din aceeași bază de date din care se calculează comenzile, deci
 * nu poate rămâne în urmă: schimbi un preț din panoul de Catalog și se schimbă
 * și aici.
 *
 * Aplicația interactivă rămâne neatinsă — o citim din `app.html` și o servim
 * după ce completăm antetul.
 */

declare(strict_types=1);

$APP = __DIR__ . '/app.html';
if (!is_file($APP)) {
    http_response_code(500);
    exit('Lipsește app.html — pachetul nu a fost construit complet.');
}

/* ------------------------------------------------------- date din bază -- */

/**
 * Catalogul și datele firmei.
 *
 * Dacă baza nu răspunde, pagina TREBUIE să se servească oricum: mai bine un
 * site fără date structurate decât un site căzut. De aceea totul e într-un
 * try/catch care întoarce valori goale.
 */
function seo_data(): array
{
    $empty = ['products' => [], 'categories' => [], 'settings' => []];

    $configFile = getenv('GP_CONFIG') ?: (__DIR__ . '/api/config.php');
    if (!is_file($configFile)) {
        // Pe subdomenii separate, config.php stă lângă API.
        foreach ([__DIR__ . '/../api/config.php', dirname(__DIR__) . '/api/config.php'] as $alt) {
            if (is_file($alt)) {
                $configFile = $alt;
                break;
            }
        }
    }
    if (!is_file($configFile)) {
        return $empty;
    }

    try {
        $cfg = require $configFile;
        $db  = $cfg['db'] ?? [];
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'] ?? '127.0.0.1',
                (int) ($db['port'] ?? 3306),
                $db['name'] ?? '',
                $db['charset'] ?? 'utf8mb4'
            ),
            $db['user'] ?? '',
            $db['pass'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );

        $products = $pdo->query(
            'SELECT slug, name, name_en, description, photo, grams, price_frozen, price_baked, category
               FROM products WHERE active = 1 ORDER BY sort, id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $categories = $pdo->query(
            'SELECT slug, name, note FROM product_categories WHERE active = 1 ORDER BY sort, id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $settings = [];
        foreach ($pdo->query('SELECT setting_key, value FROM settings WHERE is_secret = 0') as $r) {
            $settings[$r['setting_key']] = (string) $r['value'];
        }

        return compact('products', 'categories', 'settings');
    } catch (Throwable $e) {
        error_log('[gp] pagina publică nu a putut citi baza: ' . $e->getMessage());
        return $empty;
    }
}

$data  = seo_data();
$set   = $data['settings'];
$prods = $data['products'];
$cats  = $data['categories'];

$host   = $_SERVER['HTTP_HOST'] ?? 'goldenpastry.ro';
// Aceeași pagină răspunde și pe goldenpastry.ro, și pe www.goldenpastry.ro:
// pe cPanel amândouă vin din același folder. Fără tăierea lui „www.", pagina
// cerută pe www se declară pe sine adresă canonică, iar magazinul ajunge în
// rezultatele căutării ca două site-uri diferite cu același conținut. Cine dă
// mai departe linkul cu www trimite oamenii la altă adresă decât cea tipărită
// pe ambalaje, iar ce a strâns magazinul în căutări se împarte între cele două.
$host   = (string) preg_replace('/^www\./i', '', $host);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = $scheme . '://' . $host;

$company = $set['company_name']  ?? 'Golden Pastry';
$phone   = $set['company_phone'] ?? '';
$email   = $set['company_email'] ?? '';

/*
 * Adresa firmei, într-un singur loc: de aici pleacă și textul din descriere,
 * și blocul PostalAddress de mai jos, ca datele structurate să nu spună altă
 * adresă decât cea citită de om.
 *
 * Venea din `hq_label`, care nu este adresa firmei, ci eticheta primei opriri
 * de pe ruta șoferului — panoul de Setări o descrie chiar așa. Administratorul
 * care o scurtează la „Depozit”, ca să încapă pe telefonul șoferului, publica
 * fără să știe „Laborator propriu în București, Depozit.” în rezultatele
 * căutării și în pagina fără JavaScript, iar cine o golea rămânea cu
 * „…în București, . Comenzi la…”.
 *
 * `company_address` este cheia care ar trebui să țină adresa publică; până
 * când există în tabelul `settings`, rămâne adresa de mai jos. Verificarea e
 * cu `?:`, nu cu `??`: o casetă golită din panou salvează șir gol, nu null,
 * deci null-coalescing ar lăsa adresa să dispară din pagină.
 */
$strada = trim((string) ($set['company_address'] ?? '')) ?: 'Șos. Olteniței 103';
$sector = 'Sector 4';
$oras   = 'București';
$addr   = $strada . ', ' . strtolower($sector) . ', ' . $oras;

/**
 * O coordonată a depozitului, adusă la număr.
 *
 * Aceeași grijă ca la GET /lists: „44,39235” — forma dată de tastatura
 * românească — citit direct ca număr devine 44, iar un text fără cifre devine
 * 0. Aici valoarea pleacă la motoarele de căutare ca punct pe hartă, deci un
 * 0 ar arăta patiseria în Golful Guineei.
 */
$coord = static function (string $raw, float $implicit, float $max): float {
    $raw = str_replace(',', '.', trim($raw));
    if (!is_numeric($raw)) {
        return $implicit;
    }
    $v = (float) $raw;
    return ($v == 0.0 || $v < -$max || $v > $max) ? $implicit : $v;
};

$titlu = $company . ' — patiserie congelată pentru magazine, cafenele și hoteluri';
$desc  = $prods
    ? 'Producem patiserie românească și o livrăm congelată sau gata de servire: '
        . count($prods) . ' produse — ștrudele, trigoane, croissante, covrigi. '
        . 'Laborator propriu în București, ' . $addr . '. Comenzi la ' . $phone . '.'
    : 'Patiserie românească produsă în București și livrată congelată sau gata de servire.';

$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/* ------------------------------------------------- datele structurate -- */

$ld = [
    '@context' => 'https://schema.org',
    '@graph'   => [],
];

$business = [
    '@type'      => 'Bakery',
    '@id'        => $base . '/#firma',
    'name'       => $company,
    'url'        => $base,
    'description' => $desc,
    'address'    => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => $strada,
        'addressLocality' => $oras,
        'addressRegion'   => $sector,
        'addressCountry'  => 'RO',
    ],
    'geo' => [
        '@type'     => 'GeoCoordinates',
        'latitude'  => $coord((string) ($set['hq_lat'] ?? ''), 44.39235, 90.0),
        'longitude' => $coord((string) ($set['hq_lng'] ?? ''), 26.12385, 180.0),
    ],
    'areaServed' => array_values(array_filter(
        (array) json_decode($set['delivery_zones'] ?? '[]', true) ?: [],
        'is_string'
    )),
];
if ($phone !== '') {
    $business['telephone'] = $phone;
}
if ($email !== '') {
    $business['email'] = $email;
}
$ld['@graph'][] = $business;

// Catalogul, ca listă de produse cu preț. Google poate afișa prețurile direct
// în rezultate, iar pentru un furnizor asta e jumătate din decizia clientului.
if ($prods) {
    $items = [];
    foreach ($prods as $i => $p) {
        // Prețul trimis motoarelor de căutare trebuie să fie cel pe care omul
        // îl vede când deschide pagina: magazinul pornește pe varianta
        // congelată, deci numărul mare de pe card este price_frozen, iar cel
        // copt stă dedesubt, pe rândul „Gata de servire". Cu prețul copt în
        // marcaj, în rezultatele căutării apărea o sumă mai mare decât cea de
        // pe pagină, iar omul ajungea aici crezând că prețul s-a schimbat.
        // Rezerva rămâne pentru produsele vândute doar coapte.
        $price = $p['price_frozen'] ?? $p['price_baked'];
        if ($price === null) {
            continue;
        }
        $item = [
            '@type'       => 'Product',
            'name'        => $p['name'],
            'description' => $p['description'] ?: null,
            'category'    => $p['category'],
            'offers'      => [
                '@type'         => 'Offer',
                'price'         => number_format((float) $price, 2, '.', ''),
                'priceCurrency' => 'RON',
                'availability'  => 'https://schema.org/InStock',
            ],
        ];
        if ($p['grams']) {
            $item['weight'] = ['@type' => 'QuantitativeValue', 'value' => (int) $p['grams'], 'unitCode' => 'GRM'];
        }
        if ($p['photo']) {
            $item['image'] = $base . '/' . ltrim($p['photo'], '/');
        }
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => array_filter($item)];
    }
    if ($items) {
        $ld['@graph'][] = [
            '@type'           => 'ItemList',
            'name'            => 'Catalog Golden Pastry',
            'numberOfItems'   => count($items),
            'itemListElement' => $items,
        ];
    }
}

$ldJson = json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

/* ------------------------------------------------------------- antetul -- */

$head = "\n"
    . '<title>' . $e($titlu) . "</title>\n"
    . '<meta name="description" content="' . $e($desc) . "\">\n"
    . '<link rel="canonical" href="' . $e($base) . "/\">\n"
    . '<meta property="og:type" content="website">' . "\n"
    . '<meta property="og:site_name" content="' . $e($company) . "\">\n"
    . '<meta property="og:title" content="' . $e($titlu) . "\">\n"
    . '<meta property="og:description" content="' . $e($desc) . "\">\n"
    . '<meta property="og:url" content="' . $e($base) . "/\">\n"
    . '<meta property="og:image" content="' . $e($base) . "/logo-full-gold.png\">\n"
    . '<meta property="og:locale" content="ro_RO">' . "\n"
    . '<meta name="twitter:card" content="summary_large_image">' . "\n"
    . '<script type="application/ld+json">' . $ldJson . "</script>\n";

/* ------------------------------------- catalogul ca text, fără JavaScript -- */

$noscript = '';
if ($prods) {
    $byCat = [];
    foreach ($prods as $p) {
        $byCat[$p['category']][] = $p;
    }

    $noscript = "\n<noscript>\n"
        . '<div style="max-width:60rem;margin:0 auto;padding:2rem 1.25rem;font-family:system-ui,sans-serif;line-height:1.6">' . "\n"
        . '<h1>' . $e($company) . "</h1>\n"
        . '<p>' . $e($desc) . "</p>\n"
        . '<p><strong>Adresă:</strong> ' . $e($addr) . '<br>'
        . ($phone !== '' ? '<strong>Telefon:</strong> <a href="tel:' . $e(preg_replace('/\s+/', '', $phone)) . '">' . $e($phone) . '</a><br>' : '')
        . ($email !== '' ? '<strong>E-mail:</strong> <a href="mailto:' . $e($email) . '">' . $e($email) . '</a>' : '')
        . "</p>\n<h2>Catalog</h2>\n";

    foreach ($cats as $c) {
        $list = $byCat[$c['slug']] ?? [];
        if (!$list) {
            continue;
        }
        $noscript .= '<h3>' . $e($c['name']) . '</h3>' . "\n";
        if ($c['note']) {
            $noscript .= '<p>' . $e($c['note']) . "</p>\n";
        }
        $noscript .= "<ul>\n";
        foreach ($list as $p) {
            $bits = [];
            if ($p['grams']) {
                $bits[] = (int) $p['grams'] . ' g';
            }
            if ($p['price_frozen'] !== null) {
                $bits[] = 'congelat ' . number_format((float) $p['price_frozen'], 2, ',', '.') . ' lei';
            }
            if ($p['price_baked'] !== null) {
                $bits[] = 'gata de servire ' . number_format((float) $p['price_baked'], 2, ',', '.') . ' lei';
            }
            $noscript .= '<li><strong>' . $e($p['name']) . '</strong>'
                . ($bits ? ' — ' . $e(implode(' · ', $bits)) : '')
                . ($p['description'] ? '<br>' . $e($p['description']) : '')
                . "</li>\n";
        }
        $noscript .= "</ul>\n";
    }
    $noscript .= "</div>\n</noscript>\n";
}

/* ------------------------------------------------------------- livrare -- */

$html = (string) file_get_contents($APP);

// Antetul intră imediat după <head>; dacă pagina are deja un <title>, al
// nostru rămâne primul — browserele și motoarele îl iau pe acela.
$pos = stripos($html, '<head>');
$html = $pos === false
    ? $head . $html
    : substr_replace($html, '<head>' . $head, $pos, strlen('<head>'));

$pos = stripos($html, '<body>');
if ($pos !== false) {
    $html = substr_replace($html, '<body>' . $noscript, $pos, strlen('<body>'));
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
