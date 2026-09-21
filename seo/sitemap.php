<?php
/**
 * sitemap.xml, construit din baza de date.
 *
 * Fișier static ar fi rămas în urmă la primul produs adăugat din panoul de
 * Catalog. Aici lista e mereu cea reală.
 *
 * `.htaccess` trimite /sitemap.xml aici, ca adresa să fie cea pe care o caută
 * motoarele de căutare.
 *
 * Ce s-a schimbat în 2026-09
 * ──────────────────────────
 * Harta avea o singură adresă, „/", și comentariul de aici explica de ce:
 * magazinul era o singură pagină, iar „/catalog", „/despre" și celelalte erau
 * stări comutate în browser în același document. Trecute în hartă, ar fi fost
 * cerute de motoare una câte una și ar fi primit 404 — pe o hartă pe care
 * `robots.txt` o anunță el însuși.
 *
 * Acum adresele acelea răspund cu adevărat: `seo/index.php` le servește, cu
 * titlul și datele structurate ale fiecăreia. Lista de aici nu se mai scrie de
 * mână — se citește din `seo/adrese.php`, același tabel din care se servesc
 * paginile. O hartă scrisă separat ar fi început să mintă la prima adresă
 * adăugată, și tocmai o hartă care minte învață motoarele să n-o mai citească.
 *
 * Regula rămâne neschimbată: aici intră doar adrese care răspund 200.
 */

declare(strict_types=1);

require __DIR__ . '/adrese.php';

header('Content-Type: application/xml; charset=utf-8');

$host   = $_SERVER['HTTP_HOST'] ?? 'goldenpastry.ro';
// Harta trebuie să listeze aceleași adrese pe care le anunță robots.txt și
// canonical-ul paginii, adică pe cele fără „www.". Cerută pe
// www.goldenpastry.ro, ea trimitea motoarele de căutare către un al doilea set
// de adrese, pe care nu le anunță nimeni și nu le tipărește nimeni.
$host   = (string) preg_replace('/^www\./i', '', $host);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = $scheme . '://' . $host;

/**
 * Produsele și grupele, exact ca în pagină: cele active ȘI cu preț.
 *
 * O hartă care trimite la un produs scos din catalog e o hartă cu 404 în ea.
 * Și una care trimite la un produs fără preț e la fel: pagina lui răspunde
 * acum 404, fiindcă "seo/index.php" nu-l mai are în listă. Cele două
 * interogări trebuie să spună mereu același lucru — dacă una se schimbă și
 * cealaltă nu, harta începe să indice pagini care nu există. Le ține împreună
 * ".claude/test-defect-pret-gol.mjs", care le cere pe amândouă pe același
 * produs.
 * Interogarea e minimală — harta n-are nevoie decât de chei — și, ca peste
 * tot în folderul acesta, dacă baza nu răspunde harta se servește oricum, cu
 * paginile fixe. Mai bine o hartă scurtă decât o hartă căzută.
 */
function sitemap_chei(): array
{
    $gol = ['products' => [], 'categories' => []];

    $configFile = getenv('GP_CONFIG') ?: (__DIR__ . '/api/config.php');
    if (!is_file($configFile)) {
        foreach ([__DIR__ . '/../api/config.php', dirname(__DIR__) . '/api/config.php'] as $alt) {
            if (is_file($alt)) {
                $configFile = $alt;
                break;
            }
        }
    }
    if (!is_file($configFile)) {
        return $gol;
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

        // Harta trimisă motoarelor pomenește fiecare produs care se vinde
        // UNDEVA — reuniune, nu alăturare. Un produs numai gata-de-servire are
        // o pagină bună (v. `seo/index.php`), deci lipsa lui de aici ar fi o
        // pagină pe care nimic n-o arată către Google.
        //
        // Aceeași cădere ca la pagina publică, și din aceeași pricină: până la
        // importul SQL coloanele nu există. Aici căderea contează chiar mai
        // mult, fiindcă `catch`-ul de dedesubt întoarce o hartă GOALĂ, iar o
        // hartă goală trimisă la Google e mai rea decât una veche.
        $intreaba = static function (string $unde) use ($pdo): array {
            return $pdo->query('SELECT slug, category FROM products
                  WHERE active = 1 AND (' . $unde . ')
                  ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC);
        };
        try {
            $produse = $intreaba('(in_frozen = 1 AND price_frozen IS NOT NULL)
                    OR (in_baked = 1 AND price_baked IS NOT NULL)');
        } catch (Throwable $eFaraLaturi) {
            $produse = $intreaba('price_frozen IS NOT NULL AND price_baked IS NOT NULL');
        }

        return [
            'products'   => $produse,
            'categories' => $pdo->query('SELECT slug FROM product_categories WHERE active = 1 ORDER BY sort, id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    } catch (Throwable $e) {
        error_log('[gp] harta site-ului nu a putut citi baza: ' . $e->getMessage());
        return $gol;
    }
}

$chei = sitemap_chei();

/*
 * Grupele goale nu intră în hartă.
 *
 * O grupă fără niciun produs activ are o pagină care răspunde 200 și nu spune
 * nimic: un titlu, o notă și o listă goală. Google numește asta „thin content"
 * și, când o găsește într-o hartă pe care site-ul o anunță singur prin
 * `robots.txt`, o socotește o promisiune neținută. Câteva pagini de felul ăsta
 * scad încrederea în harta întreagă, deci și în paginile bune din ea.
 *
 * Grupele rămân în catalog și răspund în continuare dacă cineva le cere — doar
 * că nu le mai trimitem noi înainte. Se întorc singure în hartă în clipa în
 * care proprietarul pune primul produs în ele.
 */
$cuProduse = [];
foreach ($chei['products'] as $p) {
    $cuProduse[(string) ($p['category'] ?? '')] = true;
}
$grupe = array_values(array_filter(
    $chei['categories'],
    static fn(array $c): bool => isset($cuProduse[(string) ($c['slug'] ?? '')])
));

$pagini = gp_adrese_toate($chei['products'], $grupe);

$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
// Data ultimei modificări a paginii publice, nu ziua de azi: o hartă care zice
// „modificat azi" la fiecare vizită învață motoarele s-o ignore.
$azi = gmdate('Y-m-d', max(
    @filemtime(__DIR__ . '/index.php') ?: 0,
    @filemtime(__DIR__ . '/adrese.php') ?: 0,
    @filemtime(__DIR__ . '/app.html') ?: 0
) ?: time());

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
    . '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

foreach ($pagini as $p) {
    echo "  <url>\n";
    echo '    <loc>' . $e($base . $p['cale']) . "</loc>\n";
    // Perechea din cealaltă limbă, scrisă pe fiecare adresă în parte. Fără ea,
    // Google vede două pagini cu același înțeles în două limbi și alege
    // singur pe care s-o arate — de obicei nu pe cea potrivită cititorului.
    foreach ($p['alt'] as $limba => $cale) {
        echo '    <xhtml:link rel="alternate" hreflang="' . $e($limba) . '" href="' . $e($base . $cale) . "\"/>\n";
    }
    echo '    <xhtml:link rel="alternate" hreflang="x-default" href="' . $e($base . $p['alt']['ro']) . "\"/>\n";
    echo '    <lastmod>' . $azi . "</lastmod>\n";
    echo '    <changefreq>' . $p['freq'] . "</changefreq>\n";
    echo '    <priority>' . $p['prio'] . "</priority>\n";
    echo "  </url>\n";
}

echo "</urlset>\n";
