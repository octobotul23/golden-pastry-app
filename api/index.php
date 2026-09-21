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
require __DIR__ . '/lib/laturi.php';
require __DIR__ . '/lib/poze.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/notify.php';
require __DIR__ . '/lib/referral.php';
require __DIR__ . '/lib/promo.php';
require __DIR__ . '/lib/firma.php';
require __DIR__ . '/lib/telegram-legaturi.php';
require __DIR__ . '/lib/productie.php';

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
    ['POST',   '/auth/password/keep', 'auth',    'route_keep_password'],
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
    // Datele cererii, date celui care are jetonul primit la trimiterea ei, ca
    // să-și poată umple formularul de cont. Nu stinge jetonul și nu scrie
    // nimic. Stă deasupra rutelor cu `#`: azi nu există niciun /requests/{id}
    // cu două segmente, deci „claim" n-ar avea cu ce se ciocni — dar locul e
    // ales ca regula să nu atârne de ce NU există încă.
    ['POST',   '/requests/claim',    'requests', 'route_requests_claim'],
    ['POST',   '/requests/#/accept', 'requests', 'route_requests_accept'],
    ['POST',   '/requests/#/reject', 'requests', 'route_requests_reject'],
    // Refuzul se poate întoarce: o cerere respinsă din greșeală trebuie să
    // poată redeveni „nouă", altfel datele clientului rămân blocate în tabel
    // și trebuie retastate ca o comandă nouă.
    ['POST',   '/requests/#/reopen', 'requests', 'route_requests_reopen'],

    ['GET',    '/orders',            'orders',   'route_orders_list'],
    ['POST',   '/orders',            'orders',   'route_orders_create'],
    // ÎNAINTEA lui /orders/# dinadins: # e un segment variabil, iar prima
    // potrivire câștigă. Pusă după, ruta asta n-ar fi chemată niciodată —
    // "counts" ar intra ca id de comandă și ar ieși 404.
    ['GET',    '/orders/counts',     'orders',   'route_orders_counts'],
    // Tot înaintea lui /orders/#, din aceeași pricină. Azi nu există POST pe
    // /orders/#, deci ciocnirea n-ar apărea — dar regula nu se cuvine să
    // atârne de ce NU există încă.
    ['POST',   '/orders/quote',      'orders',   'route_orders_quote'],
    ['GET',    '/orders/#',          'orders',   'route_orders_get'],
    ['PATCH',  '/orders/#',          'orders',   'route_orders_update'],

    // Câte bucăți s-au făcut, raportat din laborator. Rută proprie, nu un câmp
    // în plus la PATCH: acolo angajatul are voie doar la etapă
    // (`ORDER_FIELDS_STAFF`), iar raportarea scrie și în registrul depozitului.
    ['POST',   '/orders/#/production', 'orders', 'route_order_production'],

    // Cât ar costa rescrierea pozițiilor unei comenzi, fără să scrie nimic.
    // Rută deosebită de /orders/quote fiindcă poarta e alta: aici trece numai
    // cine are voie să scrie pe comanda aceea. Cu un singur drum, poarta s-ar
    // fi ales dintr-un câmp din corp.
    ['POST',   '/orders/#/quote',    'orders',   'route_orders_quote_edit'],

    ['GET',    '/routes',            'orders',   'route_driver_routes'],
    // Numărul de opriri pe mai multe zile deodată. Meniul de zile al șoferului
    // îl scotea din comenzile zilei deschise — singurele pe care le are pagina
    // — deci scria „0 opriri" în dreptul tuturor celorlalte zile.
    ['GET',    '/routes/counts',     'orders',   'route_driver_route_counts'],
    // Ce a rămas în urmă: opririle zilelor trecute care nu s-au încheiat.
    // `/routes` de deasupra e ruta UNEI zile — o livrare ratată ieri nu se
    // mai vedea nicăieri de pe telefonul șoferului.
    ['GET',    '/routes/restante',   'orders',   'route_driver_restante'],
    // Fereastra îngustă a clientului și a agentului: numai cifre și nume de
    // zone, plus opririle lui. `/routes` de deasupra nu se putea lărgi — e
    // `require_ops()` și întoarce comenzi întregi, cu nume și adrese.
    ['GET',    '/routes/mine',       'orders',   'route_my_route_window'],
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

    // Pontajul din laborator. Trei porți deosebite dinadins, fiindcă cele trei
    // rute nu cer același drept: tableta cere lista de nume și pontează
    // (`require_clock()`, dar cine pontează se dovedește cu codul lui propriu);
    // raportul e al administratorului, fondatorului ȘI managerului
    // (`require_shifts()`); corectura unui rând uitat deschis e numai a
    // administratorului. Vezi antetul lui api/routes/lab.php.
    ['GET',    '/lab/people',        'lab',      'route_lab_people'],
    ['POST',   '/lab/clock',         'lab',      'route_lab_clock'],
    ['GET',    '/lab/shifts',        'lab',      'route_lab_shifts'],
    ['PATCH',  '/lab/shifts/#',      'lab',      'route_lab_shift_update'],

    // Raportul de producție, pe zi și pe tură (migrarea 021). Aceeași poartă
    // ca raportul de ore de deasupra — `require_shifts()` —, fiindcă e același
    // lucru citit din alt unghi: ce a ieșit din laborator în orele alea. Nu în
    // Statistici, unde poarta e numai a fondatorului: managerul conduce tura,
    // deci el are nevoie de cifra ei.
    ['GET',    '/lab/production',    'lab',      'route_lab_production'],

    // Ordinea în care laboratorul lucrează ziua, și împărțirea cantităților
    // produse pe comenzile ei (migrarea 023). Amândouă `require_ops()`, adică
    // și contul comun al tabletelor: ele stau în fața cuptorului, ele aranjează
    // și ele raportează. Pricinile, pe larg, lângă fiecare funcție.
    //
    // `PUT` pentru ordine, ca la sora ei `PUT /routes`: se trimite lista
    // ÎNTREAGĂ a zilei, nu o mutare — două tablete care trag în aceeași clipă
    // își scriu una alteia peste ordine, dar niciuna nu lasă ziua pe jumătate
    // aranjată.
    ['PUT',    '/lab/ordine',        'lab',      'route_lab_ordine'],
    ['POST',   '/lab/productie/imparte', 'lab',  'route_lab_productie_imparte'],
    // „Înapoi" de lângă caseta de introdus: desface exact lotul pe care
    // împărțirea tocmai l-a întors. Rută proprie, nu o cifră cu minus pe cea de
    // deasupra — vezi antetul ei.
    ['POST',   '/lab/productie/desfa',   'lab',  'route_lab_productie_desfa'],

    // Tabla de anunțuri de pe ecranul laboratorului. Citește oricine ține
    // ecranul deschis, inclusiv contul `panou` al tabletei (`require_ops()`);
    // scrie și ia jos cine conduce tura (`require_shifts()`) — dar numai
    // anunțul lui, afară de administratori și fondatori, care pot lua jos
    // orice. „Ia jos" nu șterge rândul, îi pune termenul pe clipa asta:
    // vezi route_lab_message_delete().
    ['GET',    '/lab/messages',      'lab',      'route_lab_messages'],
    ['POST',   '/lab/messages',      'lab',      'route_lab_message_new'],
    ['DELETE', '/lab/messages/#',    'lab',      'route_lab_message_delete'],

    ['GET',    '/users',             'users',    'route_users_list'],
    ['POST',   '/users',             'users',    'route_users_create'],
    ['PATCH',  '/users/#',           'users',    'route_users_update'],
    ['DELETE', '/users/#',           'users',    'route_users_delete'],
    ['POST',   '/users/#/password',  'users',    'route_users_password'],
    // Codul de pontaj: pus, schimbat sau șters. Rută proprie, ca sora ei de
    // deasupra și din aceleași motive — vezi route_users_lab_pin().
    ['POST',   '/users/#/lab-pin',   'users',    'route_users_lab_pin'],
    // „Recunosc clientul": contul făcut pe site capătă dreptul de a primi
    // comenzile luate la telefon pe numele lui. Vezi route_users_vouch().
    ['POST',   '/users/#/vouch',     'users',    'route_users_vouch'],
    // Codul de recomandare pentru un cont vechi, care s-a născut înainte să
    // existe coloana. Rută proprie, nu un câmp în plus la PATCH: e o operație
    // care se face o singură dată pe cont și nu trebuie să poată fi trimisă din
    // greșeală de formularul care salvează numele la ieșirea din casetă.
    ['POST',   '/users/#/referral',  'users',    'route_users_referral'],
    // Schimbarea dinadins a codului și a procentului. Verb propriu, fiindcă are
    // o urmare pe care POST-ul de deasupra n-o are: codul vechi moare pe loc.
    ['PATCH',  '/users/#/referral',  'users',    'route_users_referral_edit'],

    // Căutarea de client, pentru cine scrie o cerere în numele altuia. Stă
    // deoparte de `/users` fiindcă are alt public și alt răspuns: `/users` e
    // numai a administratorului și dă contul întreg, pe când aici intră și
    // agentul de vânzări — dar numai la clienții aduși de el, și numai la
    // datele de livrare. Vezi api/routes/clients.php.
    ['GET',    '/clients',           'clients',  'route_clients_search'],

    // Recomandările cu bani. Prima rută e a oricui e conectat și scoate NUMAI
    // rândurile lui; a treia e a administratorului și le scoate pe toate. Sunt
    // două rute deosebite, nu una cu un `if` înăuntru, tocmai ca filtrarea să
    // stea în `WHERE` și să nu poată fi ocolită dintr-un parametru.
    ['GET',    '/referrals',         'referrals', 'route_referrals_mine'],
    ['POST',   '/referrals/code',    'referrals', 'route_referrals_make_code'],
    ['GET',    '/referrals/all',     'referrals', 'route_referrals_all'],
    ['POST',   '/referrals/pay',     'referrals', 'route_referrals_pay'],
    // Comisioanele născute la procentul zero, îndreptate la apăsarea patronului.
    // Aceeași cale cu două verbe, nu două căi: GET-ul ARATĂ ce s-ar schimba,
    // POST-ul chiar schimbă. Ținute pe un singur nume, previzualizarea și fapta
    // nu se pot depărta una de alta — iar patronul a cerut anume să vadă
    // înainte de a apăsa. Lămurirea întreagă, în api/lib/referral.php,
    // „ÎNDREPTAREA ZEROURILOR".
    ['GET',    '/referrals/rate-fix', 'referrals', 'route_referrals_rate_fix_preview'],
    ['POST',   '/referrals/rate-fix', 'referrals', 'route_referrals_rate_fix'],
    ['POST',   '/referrals/#/resolve', 'referrals', 'route_referrals_resolve'],
    // Rândul scos din socoteală și adus înapoi. DELETE e ce cere omul care
    // apasă; dedesubt e o tăietură care ține minte, nu o ștergere — altfel
    // cârligul de la comenzi ar scrie rândul la loc singur (sql/012).
    ['DELETE', '/referrals/#',       'referrals', 'route_referrals_delete'],
    ['POST',   '/referrals/#/restore', 'referrals', 'route_referrals_restore'],

    // Fondatorul. Prima rută cere o sesiune și lucrează numai pentru cel
    // conectat; celelalte două sunt PUBLICE și se legitimează cu tokenul din
    // e-mail, fiindcă cine deschide cutia poștală a firmei nu are neapărat un
    // cont — și, dacă are, nu e neapărat conectat pe telefonul pe care citește.
    ['POST',   '/founder/request',   'founder',  'route_founder_request'],
    ['GET',    '/founder/invite',    'founder',  'route_founder_invite'],
    ['POST',   '/founder/accept',    'founder',  'route_founder_accept'],

    ['GET',    '/log',               'log',      'route_log_list'],

    // Cifrele casei. Singura rută din tabelul ăsta care NU se deschide
    // administratorului: `require_stats()` o ține numai pentru fondator
    // (api/lib/auth.php · GP_STATS_ROLES).
    ['GET',    '/stats',             'stats',    'route_stats_get'],

    ['GET',    '/settings',          'settings', 'route_settings_get'],
    ['PUT',    '/settings',          'settings', 'route_settings_save'],
    ['POST',   '/settings/test/#',   'settings', 'route_settings_test'],
    // Butonul „Am văzut" din Setări. Fără el, lista anunțurilor care nu au
    // plecat ar rămâne pe ecran și după ce cauza a fost reparată, iar un eșec
    // nou nu s-ar mai deosebi de unul vechi.
    ['POST',   '/settings/notifications/seen', 'settings', 'route_settings_notifications_seen'],

    // Butoanele de sub anunțul primit pe Telegram. Webhookul e SINGURA rută
    // din tabelul ăsta la care nu bate un browser: cererea vine de la
    // serverele Telegram, fără cookie și fără token CSRF, iar în locul lor
    // stau secretul din antet și potrivirea conversației — vezi
    // api/routes/telegram.php.
    ['POST',   '/telegram/webhook',  'telegram', 'route_telegram_webhook'],
    ['POST',   '/telegram/link',     'telegram', 'route_telegram_link'],
    ['POST',   '/telegram/unlink',   'telegram', 'route_telegram_unlink'],
    // Telegramul OMULUI, nu al botului. Toate au segmente fixe, deci ordinea
    // între ele e liberă; stau lângă cele de sus fiindcă se citesc împreună.
    // Pe „/telegram/" nu există niciun tipar cu „#", deci nu e nimic de pus
    // înainte ca să nu fie înghițite.
    ['GET',    '/telegram/me',       'telegram', 'route_telegram_me'],
    ['POST',   '/telegram/me/link',  'telegram', 'route_telegram_me_link'],
    ['POST',   '/telegram/me/confirm', 'telegram', 'route_telegram_me_confirm'],
    ['DELETE', '/telegram/me/link',  'telegram', 'route_telegram_me_unlink'],
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
