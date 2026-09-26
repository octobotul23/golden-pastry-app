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
 *   · datele structurate schema.org: firma, adresa, telefonul și catalogul cu
 *     prețuri, în forma pe care Google o citește direct;
 *   · catalogul ca text, în <noscript>, pentru cine nu rulează JavaScript.
 *
 * Totul vine din aceeași bază de date din care se calculează comenzile, deci
 * nu poate rămâne în urmă: schimbi un preț din panoul de Catalog și se schimbă
 * și aici.
 *
 * Aplicația interactivă rămâne neatinsă — o citim din `app.html` și o servim
 * după ce completăm antetul.
 *
 * DIN 2026-09: fișierul nu mai servește o singură pagină
 * ──────────────────────────────────────────────────────
 * Până acum, tot magazinul era adresa „/". Cele cinci ecrane — start, catalog,
 * distribuție, despre, contact — se comutau în browser fără ca adresa să se
 * schimbe. Un motor de căutare vedea un site cu o singură pagină: un titlu, o
 * descriere, o intrare în index. Nu se putea ieși în căutări după „catalog
 * patiserie congelată" cu o pagină care se numește, oficial, doar „Golden
 * Pastry", și nici după numele unui produs, fiindcă produsul n-avea adresă.
 *
 * Acum fiecare ecran are adresa lui, fiecare grupă din catalog are adresa ei,
 * fiecare produs are adresa lui, iar engleza stă sub „/en/" cu `hreflang` care
 * leagă perechile. Harta e în `seo/adrese.php` — un singur tabel, citit și de
 * `sitemap.php`, și de aplicația din browser.
 *
 * Trei lucruri de ținut minte dacă umbli aici:
 *
 *   1. O adresă care nu e în tabel primește 404, nu pagina de start. O pagină
 *      necunoscută servită cu 200 („soft 404") e mai rea decât un 404 curat:
 *      motorul o indexează, o găsește identică cu pagina de start, și începe
 *      să aibă îndoieli despre amândouă.
 *   2. `<base href="/">` din antet nu e decorativ. Toate trimiterile din
 *      `app.html` sunt relative („./support.js", „_ds/…", „products/prod-1.jpg"
 *      construită la rulare). Servită la „/produs/strudel-cu-mere", pagina și-ar
 *      căuta codul în „/produs/support.js" și ar rămâne albă. `<base>` prinde
 *      și adresele scrise în marcaj, și pe cele făcute de JavaScript.
 *   3. Aplicația nu-și ține propria hartă de adrese; o primește de aici, ca
 *      `window.GP_ADRESE`. Două hărți s-ar fi depărtat una de alta la prima
 *      adresă adăugată, iar meniul ar fi trimis spre pagini pe care serverul
 *      le servește și aplicația nu le recunoaște.
 */

declare(strict_types=1);

require __DIR__ . '/adrese.php';

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

        // `name_en` și `description_en` există în tabel de la prima schemă, dar
        // nu erau cerute aici: pagina engleză se construia în browser, din
        // aceleași date luate a doua oară prin API. De când engleza are adresa
        // ei („/en/…"), titlul și descrierea ei se scriu pe server, deci
        // coloanele trebuie să ajungă până aici.
        // Fără preț nu se vinde, deci nici nu se arată. Condiția e aceeași cu
        // cea din magazin ("Site Public.dc.html", filtrul lui VANDABIL) și cu
        // cea a pachetului de test ("api/lib/promo.php"), fiindcă e aceeași
        // hotărâre: un produs căruia îi lipsește prețul laturii e refuzat la
        // salvarea comenzii ("api/lib/orders.php", missing_price), deci o
        // pagină care i-ar face reclamă ar trimite omul într-un refuz.
        //
        // Rândul ăsta atinge trei lucruri deodată, fiindcă toate trei se fac
        // din $products: prima pagină, fișa produsului (care devine un 404
        // curat, nu o pagină cu "—" în locul prețului) și textele care numără
        // catalogul. Până acum produsul clipea: serverul îl scria, iar React-ul
        // îl ștergea o clipă mai târziu.
        //
        // DE LA MIGRAREA 024 condiția e o REUNIUNE, nu o alăturare. Un produs
        // poate fi numai în catalogul congelat sau numai în cel de coapte, și
        // atunci are exact o adresă pe site — a lui — pe care trebuie s-o aibă.
        // Lăsat „și", filtrul ar fi ascuns tocmai produsele dintr-o singură
        // parte, adică tot ce aduce nou runda asta: ele ar fi fost singurele
        // fără pagină, fără rând în hartă și fără preț în rezultatele Google.
        //
        // ÎNTREBAREA SE PUNE ÎN DOUĂ FELURI, și a doua nu e o politețe.
        // Fișierele ajung pe server ÎNAINTE de importul SQL: până la import,
        // coloanele nu există, iar prima interogare crapă. A doua e chiar
        // purtarea de până azi, deci fereastra aceea trece nevăzută. Fără ea,
        // `catch`-ul de mai jos ar fi întors $empty și site-ul public ar fi
        // rămas gol cu totul — pe dos față de regula casei scrisă la
        // `seo_data()`: mai bine un site fără date structurate decât unul căzut.
        $intreaba = static function (string $camp, string $unde) use ($pdo): array {
            return $pdo->query(
                'SELECT slug, name, name_en, description, description_en, photo, grams,
                        price_frozen, price_baked, category, is_new' . $camp . '
                   FROM products
                  WHERE active = 1 AND (' . $unde . ')
                  ORDER BY sort, id'
            )->fetchAll(PDO::FETCH_ASSOC);
        };
        // Cele trei prețuri (migrarea 028) se cer cu încă un ocol, din același
        // motiv: fișierele urcă înaintea importului. Coloanele sunt cele din
        // `CATALOG_TREPTE_COLOANE` (api/lib/catalog.php). Fișierul ăsta nu
        // poate încărca lista de acolo, fiindcă `catalog.php` cere baza prin
        // `scalar()`; de aceea le ține în pas o probă (`test-trepte-pret`).
        $undeLaturi = '(in_frozen = 1 AND price_frozen IS NOT NULL)
                  OR (in_baked = 1 AND price_baked IS NOT NULL)';
        try {
            $products = $intreaba(
                ', photo_baked, in_frozen, in_baked'
                    . ', price_frozen_b, price_frozen_c, price_baked_b, price_baked_c, prag_b, prag_c',
                $undeLaturi
            );
        } catch (Throwable $eFaraTrepte) {
            try {
                $products = $intreaba(', photo_baked, in_frozen, in_baked', $undeLaturi);
            } catch (Throwable $eFaraLaturi) {
                $products = $intreaba('', 'price_frozen IS NOT NULL AND price_baked IS NOT NULL');
            }
        }

        $categories = $pdo->query(
            'SELECT slug, name, name_en, note, note_en FROM product_categories WHERE active = 1 ORDER BY sort, id'
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

$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/* ------------------------------------------------------------- ruta -- */

$cerut = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$ruta  = gp_ruta($cerut);

/**
 * Pagina de „nu există", cu 404 adevărat.
 *
 * Nu servim aplicația pe o adresă necunoscută: ar însemna 200, iar Google ar
 * aduna un număr nemărginit de adrese cu același conținut („soft 404", motiv
 * documentat de scoatere din index).
 *
 * Pagina e scrisă de mână, nu construită din app.html, tocmai fiindcă trebuie
 * să meargă și când nu merge nimic altceva: `.htaccess` o cheamă și pentru
 * fișierele lipsă (ErrorDocument), iar aici jos putem ajunge înainte de orice
 * atingere a bazei de date.
 */
$da404 = static function (bool $engleza) use ($base, $e): void {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');

    $lang  = $engleza ? 'en' : 'ro';
    $titlu = $engleza ? 'Page not found — Golden Pastry' : 'Pagina nu există — Golden Pastry';
    $capul = $engleza ? 'This page does not exist' : 'Pagina asta nu există';
    $sub   = $engleza
        ? 'The address you asked for does not lead anywhere in the shop.'
        : 'Adresa cerută nu duce nicăieri în magazin.';
    // Trei ieșiri, nu una: cine a nimerit aici a venit după ceva anume, iar
    // catalogul și contactul sunt cele două locuri unde s-ar putea să fie.
    $iesiri = [
        [$e($base . gp_cale('home', $lang)),    $engleza ? 'Home page' : 'Pagina de start'],
        [$e($base . gp_cale('catalog', $lang)), $engleza ? 'Catalogue' : 'Catalogul de produse'],
        [$e($base . gp_cale('contact', $lang)), $engleza ? 'Contact' : 'Contact'],
    ];
    $legaturi = '';
    foreach ($iesiri as [$adresa, $eticheta]) {
        $legaturi .= '<a href="' . $adresa . '">' . $e($eticheta) . '</a>';
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>{$titlu}</title>
<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#FBF7F1;color:#241A12;font:16px/1.6 system-ui,sans-serif;text-align:center;padding:2rem}nav{display:flex;flex-wrap:wrap;gap:1.25rem;justify-content:center}a{color:#8A5A1E}</style>
</head>
<body>
<div>
<h1 style="margin:0 0 .5rem;font-size:1.5rem">{$capul}</h1>
<p style="margin:0 0 1.5rem;opacity:.75">{$sub}</p>
<nav>{$legaturi}</nav>
</div>
</body>
</html>
HTML;
    exit;
};

// Ce nu e în tabelul de adrese pică aici, ÎNAINTE de orice atingere a bazei.
// Ordinea nu e o simpleță de stil: `.htaccess` trimite la pagina asta și
// fișierele lipsă, iar o poză cerută greșit n-are de ce să deschidă o
// conexiune la baza de date. Pe deasupra, așa 404-ul răspunde corect și în
// clipele în care baza e căzută — adică exact atunci când contează.
if ($ruta === null) {
    $da404((bool) preg_match('#^/en(?:/|$)#i', (string) parse_url($cerut, PHP_URL_PATH)));
}

$data  = seo_data();
$set   = $data['settings'];
$prods = $data['products'];
$cats  = $data['categories'];

// Cheia din adresă trebuie să existe în catalog. Fără verificarea asta,
// „/produs/orice-scriu-eu" ar răspunde 200 cu pagina de start înăuntru, iar
// Google ar aduna un număr nemărginit de adrese cu același conținut.
$produs = null;
$grupa  = null;
if ($ruta['fel'] === 'product') {
    foreach ($prods as $p) {
        if (($p['slug'] ?? '') === $ruta['cheie']) {
            $produs = $p;
            break;
        }
    }
    if (!$produs) {
        $da404($ruta['lang'] === 'en');
    }
}
if ($ruta['fel'] === 'group') {
    foreach ($cats as $c) {
        if (($c['slug'] ?? '') === $ruta['cheie']) {
            $grupa = $c;
            break;
        }
    }
    if (!$grupa) {
        $da404($ruta['lang'] === 'en');
    }
}

$lang = $ruta['lang'];
$ro   = $lang === 'ro';
/** Alege textul după limba adresei. Aceeași unealtă ca `t()` din pagină. */
$t    = static fn($a, $b) => $ro ? $a : $b;

/*
 * Adresa și programul se citesc cu `firma_publica()`, care stă acum în
 * `api/lib/firma.php`: le citește și magazinul, prin GET /lists, iar două
 * citiri ale acelorași rânduri s-ar fi depărtat una de alta.
 *
 * Căutarea e aceeași cu cea a lui `config.php` de mai sus, fiindcă e aceeași
 * întrebare: unde stă `api/` față de pagina asta — lângă ea, sau un nivel mai
 * sus, pe subdomenii separate.
 *
 * Dacă nu se găsește, pagina se servește MAI DEPARTE, fără ore în datele
 * structurate. Regula casei pentru fișierul ăsta e scrisă la `seo_data()`:
 * mai bine un site fără date structurate decât un site căzut.
 */
foreach ([
    __DIR__ . '/api/lib/firma.php',
    __DIR__ . '/../api/lib/firma.php',
    dirname(__DIR__) . '/api/lib/firma.php',
] as $gpCaleFirma) {
    if (is_file($gpCaleFirma)) {
        require_once $gpCaleFirma;
        break;
    }
}

/**
 * Cele două laturi ale catalogului, aduse pe aceeași ușă îngăduitoare.
 *
 * `api/lib/laturi.php` e scris anume fără nicio legătură — nu atinge baza și
 * nu cheamă pe nimeni — tocmai ca să poată fi împrumutat aici. Vezi capul lui.
 */
foreach ([
    __DIR__ . '/api/lib/laturi.php',
    __DIR__ . '/../api/lib/laturi.php',
    dirname(__DIR__) . '/api/lib/laturi.php',
] as $gpCaleLaturi) {
    if (is_file($gpCaleLaturi)) {
        require_once $gpCaleLaturi;
        break;
    }
}

/**
 * Prețul, poza și apartenența — prin ajutor dacă e, ca ieri dacă nu.
 *
 * DE CE E NEVOIE DE REZERVĂ. Fișierele urcă pe server unul câte unul: câteva
 * secunde, `public_html/index.php` e cel nou și `api/lib/laturi.php` e încă
 * cel vechi sau lipsește de tot. Fără ocolul ăsta, în secundele acelea pagina
 * publică ar cădea cu „Call to undefined function" — adică un site alb, tocmai
 * în timpul urcării, când nimeni nu se uită.
 *
 * E aceeași fereastră ca la interogarea de mai sus, și are același leac: ce
 * spun rezervele NU e o a doua scriere a regulii noi, ci regula VECHE, scrisă
 * o singură dată. Un produs se vedea pe amândouă laturile dacă avea amândouă
 * prețurile, prețul arătat era cel congelat, poza era `photo`. Atât.
 */
$pretVitrina = static function (array $p) {
    return function_exists('latura_pret_vitrina')
        ? latura_pret_vitrina($p)
        : ($p['price_frozen'] ?? $p['price_baked']);
};
$pozaVitrina = static function (array $p): ?string {
    if (function_exists('latura_poza_vitrina')) {
        return latura_poza_vitrina($p);
    }
    $v = trim((string) ($p['photo'] ?? ''));
    return $v === '' ? null : $v;
};
$seVinde = static function (array $p, string $latura): bool {
    if (function_exists('latura_se_vinde')) {
        return latura_se_vinde($p, $latura);
    }
    return ($p['price_frozen'] ?? null) !== null && ($p['price_baked'] ?? null) !== null;
};


/** Numele unui produs sau al unei grupe, în limba paginii, cu rezervă. */
$nume = static function (array $r, string $camp) use ($ro): string {
    $en = trim((string) ($r[$camp . '_en'] ?? ''));
    return $ro ? (string) $r[$camp] : ($en !== '' ? $en : (string) $r[$camp]);
};

/* --------------------------------------------------- datele firmei -- */

$company = $set['company_name']  ?? 'Golden Pastry';
$phone   = $set['company_phone'] ?? '';
$email   = $set['company_email'] ?? '';

/*
 * Adresa și programul, dintr-un singur izvor: `firma_publica()`, din
 * `api/lib/firma.php`. De aici pleacă și rândul din pagina fără JavaScript, și
 * blocul PostalAddress de mai jos — iar prin GET /lists pleacă ACELAȘI tablou
 * și spre magazin, ca datele structurate să nu spună altă adresă și alt program
 * decât cele pe care le citește omul. Înainte chiar spuneau: pagina zicea
 * „Luni–Vineri 07:00–21:00, Sâmbătă 07:00–17:00", Google afla „Luni–Sâmbătă
 * 05:00–21:00".
 *
 * Din descrierea paginii de start adresa a ieșit: cele 160 de caractere pe care
 * le arată Google se cheltuiesc mai bine pe ce vinde firma decât pe stradă, pe
 * care Google o citește oricum din PostalAddress, unde chiar o folosește.
 *
 * Venea din `hq_label`, care nu este adresa firmei, ci eticheta primei opriri
 * de pe ruta șoferului — panoul de Setări o descrie chiar așa. Administratorul
 * care o scurtează la „Depozit”, ca să încapă pe telefonul șoferului, publica
 * fără să știe „Laborator propriu în București, Depozit.” în rezultatele
 * căutării și în pagina fără JavaScript, iar cine o golea rămânea cu
 * „…în București, . Comenzi la…”.
 *
 * Verificările cu `?:` în loc de `??` stau acum în `firma_publica()`, dintr-un
 * motiv care nu s-a schimbat: caseta golită din panou salvează șir GOL, nu
 * null, iar null-coalescing ar lăsa adresa să dispară din pagină.
 *
 * Dacă fișierul nu s-a găsit — vezi căutarea de mai sus — pagina se servește
 * mai departe cu adresa din cod și fără ore, fiindcă regula fișierului ăstuia
 * e „mai bine un site fără date structurate decât un site căzut".
 */
$firma = function_exists('firma_publica')
    ? firma_publica($set['company_address'] ?? null, $set['company_hours'] ?? null)
    : [
        'adresa'     => 'Șos. Olteniței 103',
        'sector'     => 'Sector 4',
        'oras'       => 'București',
        'intreaga'   => 'Șos. Olteniței 103, sector 4, București',
        'intreagaEn' => 'Șos. Olteniței 103, district 4, Bucharest',
        'harta'      => '',
        'program'    => ['spec' => [], 'ro' => '', 'en' => '', 'zile' => []],
    ];

$strada = $firma['adresa'];
$sector = $firma['sector'];
$oras   = $firma['oras'];
$addr   = $firma['intreaga'];
$addrEn = $firma['intreagaEn'];

$program = $firma['program'];

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

/* ------------------------------------------------- titlu și descriere -- */

/*
 * Titlul are un buget: Google taie afișarea pe la 580-600 px, adică vreo 60 de
 * caractere. Titlul dinainte avea 73 și se tăia fix după „cafenele", iar
 * descrierea avea 224 de caractere din 160 câte încap, deci telefonul din
 * coada ei nu se vedea niciodată. Mai rău: nu conținea „en-gros", cuvântul
 * după care caută chiar cumpărătorul căutat.
 *
 * Fiecare pagină își are titlul ei. Două pagini cu același titlu sunt, pentru
 * un motor de căutare, un semn că una din ele e de prisos.
 */
$nProd = count($prods);
/*
 * „1 sortimente" e o greșeală care apare abia la un catalog mic — deci nu se
 * vede azi, la 25 de produse, dar se vede în ziua în care rămâne unul singur
 * activ. Aceeași grijă ca la titlurile de grupă: numărul și cuvântul se scriu
 * împreună, o dată, ca să nu se poată despărți.
 */
$nrSortimente = static fn(int $n): string => $n === 1 ? '1 sortiment' : $n . ' sortimente';
$nrLines      = static fn(int $n): string => $n === 1 ? '1 line' : $n . ' lines';
$catNume = $grupa ? $nume($grupa, 'name') : '';
$prodNume = $produs ? $nume($produs, 'name') : '';

/** Prețul cel mai mic din catalog, pentru descrierile care spun „de la". */
$pretMin = null;
/** Și cel mai mare, pentru intervalul din fișa firmei. */
$pretMax = null;
// Fiecare produs intră cu prețul laturii pe care îl vede omul întâi — v.
// `latura_vitrina()`. Cu `price_frozen ?? price_baked`, un produs scos din
// catalogul congelat dar cu prețul lui congelat păstrat (chiar hotărârea
// patronului: stingerea unei laturi NU șterge prețul) ar fi intrat aici cu un
// preț pe care nicio pagină nu-l arată. Rândul „de la X lei" e locul unde o
// abatere se vede cel mai greu: nu se compară cu nimic, e doar un număr.
foreach ($prods as $p) {
    $v = $pretVitrina($p);
    if ($v === null) {
        continue;
    }
    $v = (float) $v;
    if ($pretMin === null || $v < $pretMin) {
        $pretMin = $v;
    }
    if ($pretMax === null || $v > $pretMax) {
        $pretMax = $v;
    }
}
/**
 * O sumă, scrisă ca în limba paginii.
 *
 * Româna pune virgula la zecimale și spune „lei"; engleza pune punctul și
 * spune „RON". Cu formatul românesc într-o propoziție englezească — „2,85 lei"
 * — cititorul străin vede două lei și optzeci și cinci de bani ca fiind două
 * mii opt sute cincizeci.
 */
$bani = static fn(float $v): string => $ro
    ? number_format($v, 2, ',', '.') . ' lei'
    : number_format($v, 2, '.', ',') . ' RON';
$lei = static fn(?float $v): string => $v === null ? '' : $bani($v);

$titlu = '';
$desc  = '';

switch ($ruta['fel'] === 'pagina' ? $ruta['pagina'] : $ruta['fel']) {
    case 'catalog':
        $titlu = $t(
            'Catalog patiserie congelată — ' . $nrSortimente($nProd) . ' | ' . $company,
            'Frozen pastry catalogue — ' . $nrLines($nProd) . ' | ' . $company
        );
        $desc = $t(
            'Ștrudele, trigoane, croissante, covrigi și specialități, cu gramaje și prețuri en-gros. '
                . 'Congelate pentru cuptorul tău sau coapte, gata de vitrină. Comandă minimă 700 lei.',
            'Strudels, triangles, croissants, pretzels and specialities, with weights and wholesale prices. '
                . 'Frozen for your own oven or baked, ready for the counter. 700 RON minimum order.'
        );
        break;

    case 'distribution':
        $titlu = $t(
            'Livrare frigorifică în București și Ilfov | ' . $company,
            'Refrigerated delivery in Bucharest and Ilfov | ' . $company
        );
        $desc = $t(
            'Lanț frigorific neîntrerupt, flotă proprie, livrare în ziua următoare în București și Ilfov. '
                . 'Comandă minimă 700 lei. În restul țării, curier frigorific la cerere.',
            'Unbroken cold chain, own fleet, next-day delivery in Bucharest and Ilfov. '
                . '700 RON minimum order. Refrigerated courier elsewhere in Romania, on request.'
        );
        break;

    case 'about':
        $titlu = $t(
            'Despre noi — laborator de patiserie în sectorul 4 | ' . $company,
            'About us — pastry workshop in Bucharest | ' . $company
        );
        $desc = $t(
            'Rețete de casă la scară industrială, în laboratorul propriu din ' . $strada . '. '
                . 'Cum producem, cum ambalăm și cum ținem gustul la fel de la o livrare la alta.',
            'Home recipes at industrial scale, in our own workshop at ' . $strada . ', Bucharest. '
                . 'How we produce, how we pack, and how we keep the taste the same between deliveries.'
        );
        break;

    case 'contact':
        $titlu = $t(
            'Contact și cerere de ofertă | ' . $company . ' București',
            'Contact and quote request | ' . $company . ' Bucharest'
        );
        $desc = $t(
            'Cere catalogul cu prețuri en-gros și un pachet de probă. ' . $addr . '.'
                . ($phone !== '' ? ' Telefon ' . $phone . '.' : ''),
            'Ask for the wholesale price list and a trial pack. ' . $addrEn . '.'
                . ($phone !== '' ? ' Phone ' . $phone . '.' : '')
        );
        break;

    case 'group':
        $nota = trim((string) $nume($grupa, 'note'));
        $inGrupa = 0;
        // Și câte dintre ele se vând chiar congelate: titlul de mai jos le
        // numește „sortimente congelate", iar de la migrarea 024 o grupă poate
        // ține și marfă numai coaptă. Numărul rămâne al grupei
        // întregi — omul numără rândurile de pe pagină și trebuie să-i iasă —,
        // dar cuvântul cade când n-ar mai fi adevărat despre toate.
        $receGrupa = 0;
        foreach ($prods as $p) {
            if (($p['category'] ?? '') === $grupa['slug']) {
                $inGrupa++;
                if ($seVinde($p, 'congelat')) {
                    $receGrupa++;
                }
            }
        }
        /*
         * Numele grupelor le scrie proprietarul din panoul de Catalog, iar
         * genul și numărul lor nu se pot ghici din cod: „Ștrudel" e masculin
         * singular, „Covrigi" masculin plural, „Specialități" feminin plural.
         * Un titlu care lipește „congelate" de nume iese „Ștrudel congelate"
         * la cinci grupe din șapte — iar titlul e primul lucru pe care îl
         * citește cumpărătorul în rezultatele Google.
         *
         * Adjectivul se acordă aici cu un cuvânt al nostru, „sortiment(e)",
         * care are gen și număr știute. Numele grupei rămâne neatins, exact
         * cum l-a scris el, și titlul e corect pentru orice grupă adăugată de
         * acum înainte, fără să mai trebuiască atins codul.
         */
        // Cu toată grupa congelată — cazul de până azi și cel obișnuit —
        // vorbele ies literă cu literă cele dinainte.
        $sortim = $receGrupa === $inGrupa
            ? $t(
                $inGrupa === 1 ? '1 sortiment congelat' : $inGrupa . ' sortimente congelate',
                $inGrupa === 1 ? '1 frozen line' : $inGrupa . ' frozen lines'
            )
            : $t(
                $inGrupa === 1 ? '1 sortiment' : $inGrupa . ' sortimente',
                $inGrupa === 1 ? '1 line' : $inGrupa . ' lines'
            );
        $titlu = $t(
            $catNume . ' en-gros — ' . $sortim . ' | ' . $company,
            $catNume . ' wholesale — ' . $sortim . ' | ' . $company
        );
        $desc = $t(
            ($nota !== '' ? $nota . '. ' : '') . $sortim . ' din grupa ' . $catNume
                . '. Livrare en-gros în București și Ilfov, comandă minimă 700 lei.',
            ($nota !== '' ? $nota . '. ' : '') . $sortim . ' in the ' . $catNume
                . ' range. Wholesale delivery in Bucharest and Ilfov, 700 RON minimum order.'
        );
        break;

    case 'product':
        $pret = $pretVitrina($produs);
        $g    = (int) ($produs['grams'] ?? 0);
        // Aceeași capcană ca la grupe, dar mai vizibilă: „Merdenea" e feminin,
        // „Poale-n brâu" e plural, iar „Merdenea congelat" e o greșeală pe
        // care o vede oricine. „Livrare congelată" nu depinde de numele
        // produsului, deci se poate scrie o dată pentru toate.
        //
        // DAR DEPINDE DE LATURĂ, de la migrarea 024. Un produs care se vinde
        // numai copt nu se livrează congelat deloc, iar titlul și
        // descrierea de aici sunt chiar cele două rânduri pe care omul le
        // citește în rezultatele Google, înainte să deschidă ceva. Un titlu
        // care promite altă marfă decât cea din pagină nu e o scăpare de
        // cuvinte: e omul care vine după congelate și pleacă.
        $rece = $seVinde($produs, 'congelat');
        $titlu = $rece
            ? $t(
                $prodNume . ' en-gros, livrare congelată | ' . $company,
                $prodNume . ' frozen, wholesale | ' . $company
            )
            : $t(
                $prodNume . ' en-gros, din cuptorul nostru | ' . $company,
                $prodNume . ' baked, wholesale | ' . $company
            );
        $desc = trim($t((string) $produs['description'], (string) ($produs['description_en'] ?: $produs['description'])));
        $coada = $t(
            ($g ? $g . ' g. ' : '') . ($pret !== null ? 'Preț en-gros ' . $lei((float) $pret) . ' bucata. ' : '')
                . ($rece ? 'Livrare congelată în București și Ilfov.' : 'Din cuptorul nostru, cu livrare în București și Ilfov.'),
            ($g ? $g . ' g. ' : '') . ($pret !== null ? 'Wholesale ' . $lei((float) $pret) . ' each. ' : '')
                . ($rece ? 'Frozen delivery in Bucharest and Ilfov.' : 'Baked by us, delivered in Bucharest and Ilfov.')
        );
        $desc = $desc === '' ? $coada : $desc . ' ' . $coada;
        break;

    default: // home
        $titlu = $t(
            'Patiserie congelată en-gros București | ' . $company,
            'Wholesale frozen pastry, Bucharest | ' . $company
        );
        // „en-gros" lipsea din descriere, deși e cuvântul pe care îl tastează
        // chiar cumpărătorul căutat: un magazin care caută furnizor nu scrie
        // „patiserie", scrie „patiserie congelată en-gros București". Google
        // îngroașă în rezultate cuvintele din căutare, iar rândul îngroșat e
        // cel pe care se dă clic. Adresa din stradă a ieșit în schimbul lui:
        // ea nu e căutată de nimeni și oricum se citește din datele
        // structurate, unde Google chiar o folosește.
        $desc = $nProd
            ? $t(
                'Furnizor de patiserie congelată en-gros în București: ' . $nrSortimente($nProd)
                    . ' pentru magazine, cafenele și hoteluri.'
                    . ' Livrare a doua zi, comandă minimă 700 lei.',
                'Wholesale frozen pastry supplier in Bucharest: ' . $nrLines($nProd)
                    . ' for shops, cafés and hotels.'
                    . ' Next-day delivery, 700 RON minimum order.'
            )
            : $t(
                'Patiserie românească produsă în București și livrată congelată sau coaptă.',
                'Romanian pastry produced in Bucharest and delivered frozen or baked.'
            );
        break;
}

/* ------------------------------------------------------- adresele -- */

$caleAsta = gp_cale($ruta['pagina'], $lang, $ruta['fel'], $ruta['cheie']);
$canonic  = $base . $caleAsta;
$caleRo   = gp_cale($ruta['pagina'], 'ro', $ruta['fel'], $ruta['cheie']);
$caleEn   = gp_cale($ruta['pagina'], 'en', $ruta['fel'], $ruta['cheie']);

/**
 * Poza de partajare: a produsului, când e vorba de un produs.
 *
 * Tot latura vitrinei, ca peste tot. Pe `photo` gol, un produs vândut
 * numai copt ar fi ieșit în WhatsApp cu sigla firmei în locul mărfii —
 * iar poza lui era pe disc tot timpul, doar în cealaltă coloană.
 */
$poza = $base . '/logo-full-gold.png';
if ($produs) {
    $pozaProd = $pozaVitrina($produs);
    if ($pozaProd !== null && $pozaProd !== '') {
        $poza = $base . '/' . ltrim($pozaProd, '/');
    }
}

/* ------------------------------------------------- datele structurate -- */

$ld = [
    '@context' => 'https://schema.org',
    '@graph'   => [],
];

$zone = array_values(array_unique(array_filter(
    (array) json_decode($set['delivery_zones'] ?? '[]', true) ?: [],
    'is_string'
)));

$business = [
    '@type'      => 'Bakery',
    '@id'        => $base . '/#firma',
    'name'       => $company,
    'url'        => $base . '/',
    'description' => $t(
        'Producător de patiserie congelată în București. Vindem en-gros către magazine, cafenele și hoteluri.',
        'Frozen pastry producer in Bucharest. We supply shops, cafés and hotels wholesale.'
    ),
    'logo'       => $base . '/logo-full-gold.png',
    'image'      => $base . '/logo-full-gold.png',
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
    // Zonele veneau din setări așa cum le scrisese cineva în panou, iar
    // „Băneasa" apărea de trei ori la rând. Repetiția nu strică nimic, dar un
    // bloc de date structurate care se repetă e primul semn că nu se uită
    // nimeni la el.
    'areaServed' => $zone,
    'currenciesAccepted' => 'RON',
];
if ($program['spec']) {
    // Fără câmpul ăsta, rândul cu orele lipsește cu totul din rezultatul
    // Google, iar cine caută „patiserie deschisă acum” nu vede firma.
    $business['openingHoursSpecification'] = $program['spec'];
}
if ($pretMin !== null) {
    // priceRange e ce arată Google lângă firmă în Hărți. Fără el, rândul
    // rămâne gol; cu el, cumpărătorul vede din listă că e vorba de en-gros.
    //
    // Câmpul e text liber, dar îl citesc și oameni, și programe, iar pagina se
    // servește în două limbi din același cod. „de la 1,20 RON/buc" ajungea așa
    // cum e, în românește, și pe pagina engleză — unde virgula zecimală se
    // citește greșit. Un interval scris cu punct nu are limbă și nu are ce
    // înțelege greșit nimeni.
    $business['priceRange'] = $pretMax !== null && $pretMax > $pretMin
        ? number_format($pretMin, 2, '.', '') . ' - ' . number_format($pretMax, 2, '.', '') . ' RON'
        : number_format($pretMin, 2, '.', '') . ' RON';
}
if ($phone !== '') {
    $business['telephone'] = $phone;
}
if ($email !== '') {
    $business['email'] = $email;
}
$ld['@graph'][] = $business;

// Nodul WebSite: îi spune lui Google numele sub care să arate site-ul, în loc
// să-l ghicească din domeniu.
$ld['@graph'][] = [
    '@type'         => 'WebSite',
    '@id'           => $base . '/#site',
    'url'           => $base . '/',
    'name'          => $company,
    'inLanguage'    => $ro ? 'ro-RO' : 'en',
    'publisher'     => ['@id' => $base . '/#firma'],
];

// Firimiturile: ele produc rândul cu „goldenpastry.ro › catalog › ștrudel"
// sub titlu, în loc de adresa crudă.
$firimituri = [[
    'name' => $t('Acasă', 'Home'),
    'item' => $base . gp_cale('home', $lang),
]];
if ($ruta['fel'] === 'group' || $ruta['fel'] === 'product' || $ruta['pagina'] === 'catalog') {
    $firimituri[] = ['name' => $t('Catalog', 'Catalogue'), 'item' => $base . gp_cale('catalog', $lang)];
}
if ($ruta['fel'] === 'group') {
    $firimituri[] = ['name' => $catNume, 'item' => $canonic];
}
if ($ruta['fel'] === 'product') {
    // Grupa, între Catalog și produs. Fără ea, drumul sare o treaptă, iar
    // paginile de grupă rămân fără nicio legătură care să le arate lui Google
    // că sunt un nivel al site-ului, nu niște pagini rătăcite.
    //
    // Aceleași trepte, în aceeași ordine, trebuie să se vadă și pe pagină:
    // Google cere ca firimiturile din marcaj să fie cele pe care le vede omul
    // (`Site Public.dc.html`, bara de sub antet).
    $grupaProd = null;
    foreach ($cats as $c) {
        if (($c['slug'] ?? '') === ($produs['category'] ?? '')) {
            $grupaProd = $c;
            break;
        }
    }
    if ($grupaProd) {
        $firimituri[] = [
            'name' => $nume($grupaProd, 'name'),
            'item' => $base . gp_cale('catalog', $lang, 'group', (string) $grupaProd['slug']),
        ];
    }
    $firimituri[] = ['name' => $prodNume, 'item' => $canonic];
}
if ($ruta['fel'] === 'pagina' && $ruta['pagina'] !== 'home' && $ruta['pagina'] !== 'catalog') {
    $firimituri[] = ['name' => $titlu, 'item' => $canonic];
}
if (count($firimituri) > 1) {
    $ld['@graph'][] = [
        '@type'           => 'BreadcrumbList',
        '@id'             => $canonic . '#firimituri',
        'itemListElement' => array_map(
            static fn(int $i, array $f): array => [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $f['name'],
                'item'     => $f['item'],
            ],
            array_keys($firimituri),
            $firimituri
        ),
    ];
}

/**
 * Un produs, în forma pe care o citește Google.
 *
 * Prețul trimis motoarelor de căutare trebuie să fie cel pe care omul îl vede
 * când deschide pagina. Magazinul pornește pe varianta congelată, deci numărul
 * mare de pe card e cel congelat, iar cel al laturii coapte stă dedesubt. Cu
 * prețul celălalt în marcaj, în rezultatele căutării apărea o sumă mai mare
 * decât cea de pe pagină, iar omul ajungea aici crezând că prețul s-a schimbat.
 *
 * PRICINA A RĂMAS ADEVĂRATĂ, DAR S-A FĂCUT CONDIȚIONATĂ. „Magazinul pornește
 * pe congelate" e adevărat despre FILĂ, nu despre produs: un produs din afara
 * catalogului de congelate nu e văzut întâi acolo, fiindcă acolo nu e deloc.
 * Pentru el, latura vitrinei e cealaltă — și atunci prețul, poza și rândul din
 * `<noscript>` trebuie să fie toate trei ale aceleiași laturi. Altfel
 * rezultatul din căutare arată poza unei laturi cu prețul celeilalte.
 *
 * Socoteala nu se scrie aici: stă o singură dată în `latura_vitrina()`
 * (api/lib/laturi.php) și se cheamă din toate patru locurile prin
 * `$pretVitrina` / `$pozaVitrina`. Lăsată pe „`??`", ea ar fi trebuit ținută
 * în pas cu mâna în patru locuri care nu se văd între ele.
 */
/**
 * Numele grupelor, după cheia lor. `category` primea cheia din adresă
 * („strudel"), adică un cuvânt scris pentru calculator; în fișa produsului se
 * așteaptă numele sub care se vinde marfa („Ștrudel"), în limba paginii.
 */
$numeGrupe = [];
foreach ($cats as $c) {
    $numeGrupe[(string) $c['slug']] = $nume($c, 'name');
}

$produsLd = static function (array $p) use ($base, $nume, $ro, $lang, $company, $numeGrupe, $pretVitrina, $pozaVitrina): ?array {
    $price = $pretVitrina($p);
    if ($price === null) {
        return null;
    }
    $descr = $ro ? (string) $p['description'] : (string) ($p['description_en'] ?: $p['description']);
    $item = [
        '@type'       => 'Product',
        '@id'         => $base . gp_cale('product', $lang, 'product', (string) $p['slug']) . '#produs',
        'name'        => $nume($p, 'name'),
        'description' => $descr ?: null,
        'category'    => $numeGrupe[(string) $p['category']] ?? $p['category'],
        // Marca: fără ea, Google leagă produsul de nimeni și fișa nu intră în
        // rezultatele bogate, unde se vede prețul sub titlu.
        'brand'       => ['@type' => 'Brand', 'name' => $company],
        'url'         => $base . gp_cale('product', $lang, 'product', (string) $p['slug']),
        'offers'      => [
            '@type'         => 'Offer',
            'price'         => number_format((float) $price, 2, '.', ''),
            'priceCurrency' => 'RON',
            'availability'  => 'https://schema.org/InStock',
            'url'           => $base . gp_cale('product', $lang, 'product', (string) $p['slug']),
            'seller'        => ['@id' => $base . '/#firma'],
        ],
    ];
    // Cele trei prețuri, când produsul le are pe latura vitrinei (migrarea
    // 028): Google citește din ele de la câte bucăți scade prețul. `price`
    // rămâne A, cifra pe care o vede omul când deschide pagina, iar un produs
    // cu un singur preț rămâne cu marcajul de ieri — v. `latura_scara_ld()`.
    if (function_exists('latura_scara_ld') && function_exists('latura_vitrina')) {
        $trepte = latura_scara_ld($p, latura_vitrina($p));
        if ($trepte) {
            $item['offers']['priceSpecification'] = $trepte;
        }
    }
    if ($p['grams']) {
        $item['weight'] = ['@type' => 'QuantitativeValue', 'value' => (int) $p['grams'], 'unitCode' => 'GRM'];
    }
    // Aceeași latură ca prețul, întotdeauna — v. pricina de mai sus. Și tot
    // `latura_poza_vitrina()` ține hotărârea patronului din 21 septembrie
    // 2026: fiecare latură arată numai poza ei. Un produs a cărui latură de
    // vitrină n-are poză ajunge în Google fără `image` — mai bine niciun câmp
    // decât fotografia celeilalte laturi, care e altă marfă la vedere.
    $poza = $pozaVitrina($p);
    if ($poza !== null && $poza !== '') {
        $item['image'] = $base . '/' . ltrim($poza, '/');
    }
    return array_filter($item);
};

if ($ruta['fel'] === 'product' && $produs) {
    $unul = $produsLd($produs);
    if ($unul) {
        $ld['@graph'][] = $unul;
    }
} else {
    // Catalogul, ca listă de produse cu preț. Google poate afișa prețurile
    // direct în rezultate, iar pentru un furnizor asta e jumătate din decizia
    // clientului. Pe pagina unei grupe, lista e doar a grupei — altfel toate
    // paginile de grupă ar spune același lucru și s-ar anula între ele.
    $lista = $ruta['fel'] === 'group'
        ? array_values(array_filter($prods, static fn(array $p): bool => ($p['category'] ?? '') === $grupa['slug']))
        : $prods;

    $items = [];
    foreach ($lista as $i => $p) {
        $unul = $produsLd($p);
        if ($unul) {
            $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'item' => $unul];
        }
    }
    if ($items) {
        $ld['@graph'][] = [
            '@type'           => $ruta['fel'] === 'group' ? 'CollectionPage' : 'ItemList',
            '@id'             => $canonic . '#lista',
            'name'            => $ruta['fel'] === 'group'
                ? $catNume . ' — ' . $company
                : $t('Catalog ', 'Catalogue ') . $company,
            'url'             => $canonic,
            'numberOfItems'   => count($items),
            'itemListElement' => $items,
        ];
    }
}

// Fără JSON_UNESCAPED_SLASHES: textul stă într-un <script>, iar un „</script>"
// dintr-o denumire de produs (scrisă de administrator, dar servită fiecărui
// vizitator) ar închide blocul și ar injecta HTML în pagină. Cu „/" scris ca
// „\/", secvența nu mai poate apărea. JSON-ul rămâne perfect valid.
$ldJson = json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

/* ------------------------------------------------------------- antetul -- */

$adreseJson = json_encode(
    gp_adrese_pentru_pagina(),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
$rutaJson = json_encode(
    ['pagina' => $ruta['pagina'], 'lang' => $lang, 'fel' => $ruta['fel'], 'cheie' => $ruta['cheie']],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

$head = "\n"
    // Codificarea trebuie citită în primii 1024 de octeți ai documentului, iar
    // datele structurate de mai jos pot avea zeci de mii. Antetul HTTP o spune
    // deja și el are ultimul cuvânt, dar pagina salvată pe disc nu-l mai are.
    . '<meta charset="utf-8">' . "\n"
    // Vezi comentariul de sus, punctul 2: fără asta, orice adresă cu două
    // segmente rămâne o pagină albă.
    . '<base href="' . $e($base) . "/\">\n"
    . '<title>' . $e($titlu) . "</title>\n"
    . '<meta name="description" content="' . $e($desc) . "\">\n"
    . '<link rel="canonical" href="' . $e($canonic) . "\">\n"
    // Perechea de limbi. „x-default" spune ce se servește cuiva a cărui limbă
    // nu e niciuna din cele două — pentru o firmă din București, româna.
    . '<link rel="alternate" hreflang="ro" href="' . $e($base . $caleRo) . "\">\n"
    . '<link rel="alternate" hreflang="en" href="' . $e($base . $caleEn) . "\">\n"
    . '<link rel="alternate" hreflang="x-default" href="' . $e($base . $caleRo) . "\">\n"
    . '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">' . "\n"
    . '<meta property="og:type" content="' . ($ruta['fel'] === 'product' ? 'product' : 'website') . "\">\n"
    . '<meta property="og:site_name" content="' . $e($company) . "\">\n"
    . '<meta property="og:title" content="' . $e($titlu) . "\">\n"
    . '<meta property="og:description" content="' . $e($desc) . "\">\n"
    . '<meta property="og:url" content="' . $e($canonic) . "\">\n"
    . '<meta property="og:image" content="' . $e($poza) . "\">\n"
    . '<meta property="og:locale" content="' . ($ro ? 'ro_RO' : 'en_GB') . "\">\n"
    . '<meta property="og:locale:alternate" content="' . ($ro ? 'en_GB' : 'ro_RO') . "\">\n"
    . '<meta name="twitter:card" content="summary_large_image">' . "\n"
    . '<script>window.GP_ADRESE = ' . $adreseJson . '; window.GP_RUTA = ' . $rutaJson . ";</script>\n"
    . '<script type="application/ld+json">' . $ldJson . "</script>\n";

/* ------------------------------------- catalogul ca text, fără JavaScript -- */

/*
 * Blocul de mai jos e citit de cine nu execută JavaScript: motoare mai mici,
 * culegătoarele care fac previzualizarea linkului în WhatsApp și Facebook, și
 * Google la prima trecere, înainte de a pune pagina la rând pentru randare.
 *
 * De la adresele reale încoace, are și o a doua treabă: poartă legăturile
 * către toate paginile site-ului. Meniul adevărat se construiește în browser,
 * deci un cititor care nu rulează JavaScript nu vedea de aici nicio cale mai
 * departe — și nici Google la prima trecere.
 */
$linkuri = '';
foreach (GP_PAGINI as $stare => $rand) {
    $et = [
        'home'         => ['Acasă', 'Home'],
        'catalog'      => ['Catalog', 'Catalogue'],
        'distribution' => ['Distribuție', 'Distribution'],
        'about'        => ['Despre noi', 'About us'],
        'contact'      => ['Contact', 'Contact'],
    ][$stare] ?? [$stare, $stare];
    $linkuri .= '<a href="' . $e(gp_cale($stare, $lang)) . '">' . $e($t($et[0], $et[1])) . '</a> · ';
}
$linkuri = rtrim($linkuri, ' ·');

$noscript = "\n<noscript>\n"
    . '<div style="max-width:60rem;margin:0 auto;padding:2rem 1.25rem;font-family:system-ui,sans-serif;line-height:1.6">' . "\n"
    . '<h1>' . $e($ruta['fel'] === 'product' ? $prodNume : ($ruta['fel'] === 'group' ? $catNume : $company)) . "</h1>\n"
    . '<p>' . $e($desc) . "</p>\n"
    . '<p><strong>' . $t('Adresă', 'Address') . ':</strong> ' . $e($ro ? $addr : $addrEn) . '<br>'
    . ($program['ro'] !== '' ? '<strong>' . $t('Program', 'Hours') . ':</strong> ' . $e($ro ? $program['ro'] : $program['en']) . '<br>' : '')
    . ($phone !== '' ? '<strong>' . $t('Telefon', 'Phone') . ':</strong> <a href="tel:' . $e(preg_replace('/\s+/', '', $phone)) . '">' . $e($phone) . '</a><br>' : '')
    . ($email !== '' ? '<strong>E-mail:</strong> <a href="mailto:' . $e($email) . '">' . $e($email) . '</a>' : '')
    . "</p>\n"
    . '<nav>' . $linkuri . "</nav>\n";

if ($ruta['fel'] === 'product' && $produs) {
    $bits = [];
    if ($produs['grams']) {
        $bits[] = (int) $produs['grams'] . ' g';
    }
    // Fiecare rând de preț sare când produsul nu e în catalogul lui. Prețul
    // poate foarte bine să stea în bază cu latura stinsă — chiar așa a cerut-o
    // patronul —, dar un preț scris aici e o ofertă, iar o ofertă pe o latură
    // pe care marfa nu se vinde e o promisiune pe care serverul o refuză.
    // `<noscript>` e ce citește un motor de căutare și ce vede omul cu
    // JavaScript oprit: sunt chiar ochii pentru care se scrie pagina asta.
    if ($seVinde($produs, 'congelat')) {
        $bits[] = $t('congelat ', 'frozen ') . $bani((float) $produs['price_frozen']);
    }
    if ($seVinde($produs, 'copt')) {
        $bits[] = $t('copt ', 'baked ') . $bani((float) $produs['price_baked']);
    }
    $noscript .= '<p>' . $e(implode(' · ', $bits)) . "</p>\n"
        . '<p><a href="' . $e(gp_cale('catalog', $lang)) . '">' . $t('Vezi tot catalogul', 'See the full catalogue') . "</a></p>\n";
} elseif ($prods) {
    // Pe pagina unei grupe intră doar produsele ei: altfel toate paginile de
    // grupă ar avea același text și s-ar anula una pe alta în index.
    $lista = $ruta['fel'] === 'group'
        ? array_values(array_filter($prods, static fn(array $p): bool => ($p['category'] ?? '') === $grupa['slug']))
        : $prods;

    $byCat = [];
    foreach ($lista as $p) {
        $byCat[$p['category']][] = $p;
    }

    $noscript .= '<h2>' . $t('Catalog', 'Catalogue') . "</h2>\n";
    foreach ($cats as $c) {
        $inGr = $byCat[$c['slug']] ?? [];
        if (!$inGr) {
            continue;
        }
        $noscript .= '<h3><a href="' . $e(gp_cale('catalog', $lang, 'group', (string) $c['slug'])) . '">'
            . $e($nume($c, 'name')) . "</a></h3>\n";
        $notaGr = trim((string) $nume($c, 'note'));
        if ($notaGr !== '') {
            $noscript .= '<p>' . $e($notaGr) . "</p>\n";
        }
        $noscript .= "<ul>\n";
        foreach ($inGr as $p) {
            $bits = [];
            if ($p['grams']) {
                $bits[] = (int) $p['grams'] . ' g';
            }
            // Ca la fișă, mai sus: numai laturile pe care marfa chiar se vinde.
            if ($seVinde($p, 'congelat')) {
                $bits[] = $t('congelat ', 'frozen ') . $bani((float) $p['price_frozen']);
            }
            if ($seVinde($p, 'copt')) {
                $bits[] = $t('copt ', 'baked ') . $bani((float) $p['price_baked']);
            }
            $descrP = $ro ? (string) $p['description'] : (string) ($p['description_en'] ?: $p['description']);

            /*
             * Fotografia, cu text alternativ scris de cod.
             *
             * Google Imagini citește `alt`, iar fără el o poză de produs e un
             * fișier fără nume. Textul se compune ca titlurile: numele venit
             * din catalog, neatins, plus o coadă pe care o stăpânește codul —
             * așa rămâne corect gramatical pentru orice produs viitor.
             *
             * `loading="lazy"` nu costă nimic aici (browserul cu JavaScript
             * nici nu deschide <noscript>), dar pentru omul fără JavaScript
             * înseamnă o pagină care se vede înainte să se descarce 22 de poze.
             */
            $pozaP = '';
            $pozaLat = $pozaVitrina($p);
            if ($pozaLat !== null && $pozaLat !== '') {
                // Și coada textului alternativ urmează latura: „patiserie
                // congelată" scris sub poza unei mărfi care nu se vinde
                // congelată ar fi chiar cuvântul greșit în singurul loc în care
                // Google Imagini are ce citi.
                $altP = $nume($p, 'name') . ($seVinde($p, 'congelat')
                    ? $t(' — patiserie congelată en-gros', ' — wholesale frozen pastry')
                    : $t(' — patiserie coaptă en-gros', ' — wholesale baked pastry'));
                $pozaP = '<img src="' . $e($base . '/' . ltrim($pozaLat, '/')) . '"'
                    . ' alt="' . $e($altP) . '" loading="lazy"'
                    . ' style="max-width:180px;height:auto;display:block;margin:.4rem 0"><br>';
            }

            $noscript .= '<li>' . $pozaP . '<strong><a href="' . $e(gp_cale('product', $lang, 'product', (string) $p['slug'])) . '">'
                . $e($nume($p, 'name')) . '</a></strong>'
                . ($bits ? ' — ' . $e(implode(' · ', $bits)) : '')
                . ($descrP !== '' ? '<br>' . $e($descrP) : '')
                . "</li>\n";
        }
        $noscript .= "</ul>\n";
    }
}

$noscript .= "</div>\n</noscript>\n";

/* ------------------------------------------------------------- livrare -- */

$html = (string) file_get_contents($APP);

// Limba documentului. Un cititor de ecran alege pronunția după ea, iar Google
// o folosește ca semn în plus lângă hreflang. `app.html` are „<html>" gol,
// fiindcă aceeași pagină servește ambele limbi — aici știm care e.
$html = preg_replace('/<html(\s[^>]*)?>/i', '<html lang="' . ($ro ? 'ro' : 'en') . '">', $html, 1) ?? $html;

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
