<?php
/**
 * sitemap.xml, construit din baza de date.
 *
 * Fișier static ar fi rămas în urmă la primul produs adăugat din panoul de
 * Catalog. Aici lista e mereu cea reală.
 *
 * `.htaccess` trimite /sitemap.xml aici, ca adresa să fie cea pe care o caută
 * motoarele de căutare.
 */

declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');

$host   = $_SERVER['HTTP_HOST'] ?? 'goldenpastry.ro';
// Harta trebuie să listeze aceleași adrese pe care le anunță robots.txt și
// canonical-ul paginii, adică pe cele fără „www.". Cerută pe
// www.goldenpastry.ro, ea trimitea motoarele de căutare către un al doilea set
// de adrese, pe care nu le anunță nimeni și nu le tipărește nimeni.
$host   = (string) preg_replace('/^www\./i', '', $host);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base   = $scheme . '://' . $host;

// Magazinul este o singură pagină. `/catalog`, `/distribution`, `/about` și
// `/contact` sunt stări comutate în browser în același document, nu adrese pe
// care serverul le poate servi: în pachet există doar `app.html`, iar
// `.htaccess` rescrie o adresă fără extensie numai dacă fișierul `.html` cu
// acel nume există. Trecute în hartă, motoarele de căutare le-ar cere una câte
// una, ar primi 404 și le-ar raporta ca pagini negăsite — pe o hartă pe care
// `robots.txt` o anunță el însuși. Aici intră doar adrese care răspund.
$pagini = [
    ['', '1.0', 'weekly'],
];

$e = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
// Data ultimei modificări a paginii publice, nu ziua de azi: o hartă care zice
// „modificat azi" la fiecare vizită învață motoarele s-o ignore.
$azi = gmdate('Y-m-d', max(
    @filemtime(__DIR__ . '/index.php') ?: 0,
    @filemtime(__DIR__ . '/app.html') ?: 0
) ?: time());

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($pagini as [$path, $prio, $freq]) {
    echo "  <url>\n";
    echo '    <loc>' . $e($base . '/' . $path) . "</loc>\n";
    echo '    <lastmod>' . $azi . "</lastmod>\n";
    echo '    <changefreq>' . $freq . "</changefreq>\n";
    echo '    <priority>' . $prio . "</priority>\n";
    echo "  </url>\n";
}

echo "</urlset>\n";
