<?php
/**
 * Server local pentru VERIFICAREA pachetului din `deploy/`.
 *
 * Imită ce fac cele trei subdomenii pe cPanel, dar pe un singur port:
 *
 *     /            → deploy/public_html   (goldenpastry.ro)
 *     /clienti/    → deploy/clienti       (clienti.goldenpastry.ro)
 *     /lab/        → deploy/lab           (lab.goldenpastry.ro)
 *     /api/        → deploy/api           (api.goldenpastry.ro)
 *
 * Reface și rescrierea adreselor fără „.html", ca să prindem aici o legătură
 * greșită, nu după publicare.
 *
 * Pornire:
 *     php -S 127.0.0.1:8101 -t deploy .claude/deploy-server.php
 *
 * Legăturile dintre subdomenii sunt adrese complete („https://lab.golden...")
 * și NU funcționează aici — e normal, ele se verifică pe server.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = __DIR__ . '/../deploy';

/* --- API --------------------------------------------------------------- */

if (str_starts_with($path, '/api')) {
    $rest   = substr($path, 4);
    $direct = $root . '/api' . $rest;

    if ($rest !== '' && $rest !== '/' && is_file($direct) && !str_ends_with($direct, 'config.php')) {
        $_SERVER['SCRIPT_NAME']     = '/api' . $rest;
        $_SERVER['SCRIPT_FILENAME'] = $direct;
        require $direct;
        return true;
    }

    // Configurarea locală NU se copiază în deploy/: pachetul acela se urcă pe
    // server, iar datele bazei de test n-au ce căuta acolo. O arătăm doar
    // acestei rulări, prin variabilă de mediu.
    putenv('GP_CONFIG=' . realpath(__DIR__ . '/../api/config.php'));

    $_SERVER['SCRIPT_NAME']     = '/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/api/index.php';
    $_SERVER['REQUEST_URI']     = $rest === '' ? '/' : $rest;
    require $root . '/api/index.php';
    return true;
}

/* --- subdomeniile de pagini -------------------------------------------- */

$dir  = 'public_html';
$rest = $path;
foreach (['clienti', 'lab'] as $sub) {
    if ($path === '/' . $sub || str_starts_with($path, '/' . $sub . '/')) {
        $dir  = $sub;
        $rest = substr($path, strlen($sub) + 1) ?: '/';
        break;
    }
}

$base = $root . '/' . $dir;
$file = $base . $rest;

// „DirectoryIndex index.php index.html": pe domeniul public, index.php
// citește app.html și îi completează antetul cu datele pentru motoarele de
// căutare. Fără regula asta aici, proba locală ar sări exact peste el.
if ($rest === '/' || $rest === '') {
    $file = is_file($base . '/index.php') ? $base . '/index.php' : $base . '/index.html';
} elseif ($rest === '/sitemap.xml' && is_file($base . '/sitemap.php')) {
    $file = $base . '/sitemap.php';
} elseif (!is_file($file) && is_file($file . '.html')) {
    $file .= '.html';                       // adrese fără extensie, ca în .htaccess
}

// Paginile PHP se execută, nu se trimit ca text.
if (str_ends_with($file, '.php') && is_file($file)) {
    $_SERVER['SCRIPT_NAME']     = $rest;
    $_SERVER['SCRIPT_FILENAME'] = $file;
    putenv('GP_CONFIG=' . realpath(__DIR__ . '/../api/config.php'));
    require $file;
    return true;
}

if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 — negăsit în pachet: $path\n(căutat: " . str_replace($root, 'deploy', $file) . ")\n";
    return true;
}

$types = [
    'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
    'css'  => 'text/css; charset=utf-8',  'json' => 'application/json; charset=utf-8',
    'png'  => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'txt' => 'text/plain; charset=utf-8',
];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
readfile($file);
return true;
