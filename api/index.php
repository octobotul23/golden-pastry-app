<?php
/**
 * Golden Pastry — punctul de intrare al API-ului.
 *
 * Toate cererile către api.goldenpastry.ro ajung aici prin .htaccess.
 * Ruta se dispecerizează către fișierele din routes/.
 */

declare(strict_types=1);

/* ---------------------------------------------------------- configurare -- */

// Calea configurării poate fi dată din mediu (SetEnv în .htaccess sau
// variabilă de sistem). Folositoare în două situații: la testarea pachetului,
// unde nu vrem o copie a datelor de conectare în folderul de publicare, și pe
// server, dacă preferi să ții config.php în afara zonei publice.
$configFile = getenv('GP_CONFIG') ?: (__DIR__ . '/config.php');
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'code'    => 'no_config',
            'message' => 'Lipsește api/config.php. Copiază api/config.example.php și completează-l.',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$GP_CONFIG = require $configFile;

function config(string $key, $default = null)
{
    global $GP_CONFIG;
    return $GP_CONFIG[$key] ?? $default;
}

$isDev = config('env') === 'dev';
// Avertismentele NU se tipăresc niciodată, nici în dezvoltare: ieșirea
// acestui fișier este JSON, iar un „Warning: Undefined array key" tipărit
// înaintea lui face răspunsul imposibil de citit pentru orice client. S-a
// întâmplat: o cheie lipsă dintr-un formular a stricat răspunsul întregii rute,
// deși operația reușise.
//
// Sunt raportate în continuare — în jurnalul de erori al serverului — iar
// excepțiile ajung tot la client, ca text, prin dispecerul de mai jos.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting($isDev ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/crypto.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/settings.php';
require __DIR__ . '/lib/orders.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/notify.php';
require __DIR__ . '/lib/referral.php';

cors((array) config('allowed_origins', []));

// Antete de securitate: API-ul nu trebuie încadrat sau interpretat ca HTML.
//
// „X-Powered-By: PHP/8.3.33" pleacă din PHP la fiecare răspuns, dacă
// `expose_php` e pornit — și pe cPanel e, implicit. Un scaner automat citește
// versiunea exactă și o compară cu lista de vulnerabilități cunoscute pentru
// ea: îi spunem singuri ce să încerce. Îl scoatem aici, în cod, ca să nu
// depindă de o setare de server la care nu ajungem întotdeauna.
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

/* ---------------------------------------------------------------- rută -- */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Funcționează și când API-ul stă într-un subfolder (goldenpastry.ro/api/...)
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim($path, '/');

/** Tabela de rute: [metodă, tipar, fișier, funcție]. `#` = segment variabil. */
$routes = [
    ['POST',   '/auth/login',        'auth',     'route_login'],
    ['POST',   '/auth/logout',       'auth',     'route_logout'],
    ['GET',    '/auth/me',           'auth',     'route_me'],
    ['POST',   '/auth/password',     'auth',     'route_change_password'],
    ['POST',   '/auth/panel',        'auth',     'route_panel_login'],
    ['POST',   '/auth/forgot',       'auth',     'route_forgot'],
    ['POST',   '/auth/reset',        'auth',     'route_reset'],
    // Contul pe care și-l face singur vizitatorul magazinului. Publică prin
    // forța lucrurilor — cine n-are cont n-are nici sesiune, nici token CSRF —
    // deci e și singura rută spre `users` cu prag propriu pe conexiune
    // (register_rate_limited) și cu rolul scris fix, nu citit din cerere.
    ['POST',   '/auth/register',     'auth',     'route_register'],

    ['GET',    '/products',          'products', 'route_products_list'],
    ['POST',   '/products',          'catalog',  'route_products_create'],
    ['PATCH',  '/products/#',        'catalog',  'route_products_update'],
    ['DELETE', '/products/#',        'catalog',  'route_products_delete'],
    ['POST',   '/products/#/photo',  'catalog',  'route_products_photo'],
    // Rețeta ține de produs, deci se editează din Produse (admin), nu din
    // Depozit (orice angajat). `PUT` fiindcă înlocuiește lista întreagă.
    ['GET',    '/products/#/recipe', 'catalog',  'route_product_recipe_get'],
    ['PUT',    '/products/#/recipe', 'catalog',  'route_product_recipe_put'],

    ['GET',    '/categories',        'catalog',  'route_categories_list'],
    ['POST',   '/categories',        'catalog',  'route_categories_create'],
    ['PATCH',  '/categories/#',      'catalog',  'route_categories_update'],
    ['DELETE', '/categories/#',      'catalog',  'route_categories_delete'],
    // Listele configurabile (etape, intervale, zone) — publice: site-ul are
    // nevoie de intervale și zone ca să afișeze formularul de cerere.
    ['GET',    '/lists',             'products', 'route_lists_get'],

    ['GET',    '/requests',          'requests', 'route_requests_list'],
    ['POST',   '/requests',          'requests', 'route_requests_create'],
    ['POST',   '/requests/#/accept', 'requests', 'route_requests_accept'],
    ['POST',   '/requests/#/reject', 'requests', 'route_requests_reject'],
    // Refuzul se poate întoarce: o cerere respinsă din greșeală trebuie să
    // poată redeveni „nouă", altfel datele clientului rămân blocate în tabel
    // și trebuie retastate ca o comandă nouă.
    ['POST',   '/requests/#/reopen', 'requests', 'route_requests_reopen'],

    ['GET',    '/orders',            'orders',   'route_orders_list'],
    ['POST',   '/orders',            'orders',   'route_orders_create'],
    ['GET',    '/orders/#',          'orders',   'route_orders_get'],
    ['PATCH',  '/orders/#',          'orders',   'route_orders_update'],

    // Câte bucăți s-au făcut, raportat din laborator. Rută proprie, nu un câmp
    // în plus la PATCH: acolo angajatul are voie doar la etapă
    // (`ORDER_FIELDS_STAFF`), iar raportarea scrie și în registrul depozitului.
    ['POST',   '/orders/#/production', 'orders', 'route_order_production'],

    ['GET',    '/routes',            'orders',   'route_driver_routes'],
    // Numărul de opriri pe mai multe zile deodată. Meniul de zile al șoferului
    // îl scotea din comenzile zilei deschise — singurele pe care le are pagina
    // — deci scria „0 opriri" în dreptul tuturor celorlalte zile.
    ['GET',    '/routes/counts',     'orders',   'route_driver_route_counts'],
    ['PUT',    '/routes',            'orders',   'route_driver_reorder'],

    ['GET',    '/stock/items',       'stock',    'route_items_list'],
    ['POST',   '/stock/items',       'stock',    'route_items_create'],
    ['PATCH',  '/stock/items/#',     'stock',    'route_items_update'],
    // Scoaterea unui articol din depozit. Șterge de-a binelea doar articolul
    // fără nicio urmă; pe cel cu istoric îl scoate din listă, ca registrul să
    // rămână întreg. Vezi route_items_delete().
    ['DELETE', '/stock/items/#',     'stock',    'route_items_delete'],
    ['GET',    '/stock/moves',       'stock',    'route_moves_list'],
    ['POST',   '/stock/moves',       'stock',    'route_moves_create'],
    ['POST',   '/stock/sync',        'stock',    'route_stock_sync'],

    ['GET',    '/users',             'users',    'route_users_list'],
    ['POST',   '/users',             'users',    'route_users_create'],
    ['PATCH',  '/users/#',           'users',    'route_users_update'],
    ['DELETE', '/users/#',           'users',    'route_users_delete'],
    ['POST',   '/users/#/password',  'users',    'route_users_password'],
    // Codul de recomandare pentru un cont vechi, care s-a născut înainte să
    // existe coloana. Rută proprie, nu un câmp în plus la PATCH: e o operație
    // care se face o singură dată pe cont și nu trebuie să poată fi trimisă din
    // greșeală de formularul care salvează numele la ieșirea din casetă.
    ['POST',   '/users/#/referral',  'users',    'route_users_referral'],

    ['GET',    '/log',               'log',      'route_log_list'],

    ['GET',    '/settings',          'settings', 'route_settings_get'],
    ['PUT',    '/settings',          'settings', 'route_settings_save'],
    ['POST',   '/settings/test/#',   'settings', 'route_settings_test'],
    // Butonul „Am văzut" din Setări. Fără el, lista anunțurilor care nu au
    // plecat ar rămâne pe ecran și după ce cauza a fost reparată, iar un eșec
    // nou nu s-ar mai deosebi de unul vechi.
    ['POST',   '/settings/notifications/seen', 'settings', 'route_settings_notifications_seen'],
];

/** Potrivește ruta și extrage segmentele variabile. */
function match_route(string $pattern, string $path): ?array
{
    $p = explode('/', trim($pattern, '/'));
    $s = explode('/', trim($path, '/'));
    if (count($p) !== count($s)) {
        return null;
    }
    $params = [];
    foreach ($p as $i => $seg) {
        if ($seg === '#') {
            $params[] = $s[$i];
        } elseif ($seg !== $s[$i]) {
            return null;
        }
    }
    return $params;
}

/* ------------------------------------------------------------ dispecer -- */

try {
    $allowedForPath = [];

    foreach ($routes as [$m, $pattern, $file, $fn]) {
        $params = match_route($pattern, $path);
        if ($params === null) {
            continue;
        }
        $allowedForPath[] = $m;
        if ($m !== $method) {
            continue;
        }

        require_once __DIR__ . '/routes/' . $file . '.php';
        check_csrf($path);
        $fn(...$params);
        fail('Ruta nu a produs niciun răspuns.', 500, 'no_response');
    }

    if ($allowedForPath) {
        header('Allow: ' . implode(', ', array_unique($allowedForPath)));
        fail('Metodă nepermisă pentru această rută.', 405, 'method_not_allowed');
    }

    fail('Rută inexistentă: ' . $method . ' ' . $path, 404, 'not_found');
} catch (PDOException $e) {
    error_log('[gp] eroare BD: ' . $e->getMessage());
    fail($isDev ? 'Eroare BD: ' . $e->getMessage() : 'Eroare internă.', 500, 'db_error');
} catch (Throwable $e) {
    error_log('[gp] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail($isDev ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : 'Eroare internă.', 500, 'server_error');
}
