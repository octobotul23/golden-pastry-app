<?php
/**
 * Golden Pastry — ceasul plăților (runda 2.7.0).
 *
 * PRIMUL lucru din aplicație care rulează fără să fi cerut nimeni o pagină.
 * Îl pornește serverul, din cinci în cinci minute, cu rândul scris de
 * `instaleaza-vps.sh` și `actualizeaza-vps.sh` în /etc/cron.d/goldenpastry-plati:
 *
 *     php /var/www/gp/api/cron/plati.php
 *
 * La orele alese în „Încasări” trimite raportul de bani pe Telegram și, cu
 * primul raport al zilei, amânările ajunse la termen. Tot ce face stă în
 * `plati_ceas()` (api/lib/plati-raport.php); aici e numai pornirea.
 *
 * NUMAI DIN LINIA DE COMANDĂ. Fișierul stă în folderul publicat, deci are și o
 * adresă. Cerut prin ea, răspunde 404 și nu mai face NIMIC — nu citește
 * configurarea, nu deschide baza. Altfel oricine i-ar fi aflat adresa putea
 * porni un raport ori putea ține serverul ocupat cu el. Sunt două lacăte,
 * fiindcă fiecare are ziua lui proastă: `api/cron/.htaccess` (pe Apache-ul
 * serverului) și primul rând de mai jos (oriunde altundeva — un server fără
 * `AllowOverride`, serverul de probă al lui PHP, un nginx pus mâine în față).
 *
 * UN RÂND PE RULARE, pe ieșirea obișnuită. Rândul de cron îl trece prin
 * `logger -t gp-plati`, deci se citește pe server cu `journalctl -t gp-plati`.
 * Tokenul botului nu apare în el niciodată.
 *
 * Codul de ieșire: 0 — a mers ori n-a avut nimic de făcut; 1 — ceva n-a plecat
 * ori n-a pornit; 2 — a fost chemat greșit.
 *
 * Pentru probe și pentru omul care vrea să vadă fără să trimită:
 *
 *     --uscat                     tipărește ce AR pleca; nu trimite, nu însemnează
 *     --acum="2026-10-05 09:02"   se poartă ca la clipa aceea (ceasul României)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* ------------------------------------------------------------ argumente -- */

$uscat = false;
$cerut = null;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if ($arg === '--uscat') {
        $uscat = true;
    } elseif (str_starts_with($arg, '--acum=')) {
        $cerut = substr($arg, 7);
    } else {
        echo 'plati · oprit: nu cunosc „' . $arg . '”. Se primesc --uscat și --acum="AAAA-LL-ZZ HH:MM".', "\n";
        exit(2);
    }
}

/* ---------------------------------------------------------- configurare -- */

// Ca în api/index.php: mediul are întâietate. Pachetul probat și serverul pe
// care config.php stă în afara zonei publice își spun calea prin `GP_CONFIG`;
// un ceas care ar căuta numai lângă el ar trimite raportul ALTEI instalări.
$configFile = getenv('GP_CONFIG') ?: (dirname(__DIR__) . '/config.php');
if (!is_file($configFile)) {
    echo "plati · oprit: lipsește api/config.php\n";
    exit(1);
}

$GP_CONFIG = require $configFile;

function config(string $key, $default = null)
{
    global $GP_CONFIG;
    return $GP_CONFIG[$key] ?? $default;
}

// Nimic tipărit de PHP pe lângă rândul nostru: avertismentele merg în jurnalul
// de erori, ca în api/index.php.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(config('env') === 'dev' ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* -------------------------------------------------------------- pornire -- */

/**
 * Tipărește rândul rulării și iese. Tot ce se scrisese până aici se aruncă: pe
 * ieșire rămâne UN rând (și, numai la --uscat, textele cerute).
 */
function plati_cron_gata(int $cod, string $clipa, string $rand, array $texte = []): void
{
    $GLOBALS['plati_cron_incheiat'] = true;
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo 'plati ' . $clipa . ' · ' . str_replace(["\r", "\n"], ' ', $rand) . "\n";
    foreach ($texte as $text) {
        echo "---\n" . $text . "\n";
    }
    exit($cod);
}

// Biblioteca oprește programul din interior la o piedică mare — baza de date
// care nu răspunde iese prin `fail()`, adică un JSON tipărit și `exit`, gândit
// pentru o cerere de pagină. Într-un jurnal de server, JSON-ul acela ar fi un
// rând de neînțeles, cu ieșirea 0 — „a mers”. Îl prindem aici: ieșirea se
// strânge într-un tampon, iar la o oprire pe care n-am cerut-o noi se scrie
// rândul obișnuit, cu vorba din refuz și cu ieșirea 1.
$GLOBALS['plati_cron_incheiat'] = false;
$GLOBALS['plati_cron_clipa']    = gmdate('Y-m-d H:i') . ' UTC';
ob_start();
register_shutdown_function(static function (): void {
    if ($GLOBALS['plati_cron_incheiat']) {
        return;
    }
    $scris = '';
    while (ob_get_level() > 0) {
        $scris = (string) ob_get_clean() . $scris;
    }
    $j     = json_decode($scris, true);
    $vorba = is_array($j) && isset($j['error']['message'])
        ? (string) $j['error']['message']
        : 'programul s-a oprit înainte de vreme (vezi jurnalul de erori al PHP-ului)';
    if (function_exists('plati_text_fara_token')) {
        $vorba = plati_text_fara_token($vorba);
    }
    echo 'plati ' . $GLOBALS['plati_cron_clipa'] . ' · oprit: ' . str_replace(["\r", "\n"], ' ', $vorba) . "\n";
    exit(1);
});

// ACELEAȘI biblioteci ca în api/index.php, în ACEEAȘI ordine. Lista e scrisă
// de mână în două locuri, deci una dintre ele ar fi rămas în urmă la prima
// bibliotecă nouă — și ceasul ar fi murit pe server cu „funcție nedefinită”,
// noaptea, fără să-l vadă nimeni. `.claude/check-plati.mjs` le ține egale.
$api = dirname(__DIR__);
require $api . '/lib/http.php';
require $api . '/lib/crypto.php';
require $api . '/lib/db.php';
require $api . '/lib/auth.php';
require $api . '/lib/settings.php';
require $api . '/lib/orders.php';
require $api . '/lib/plati.php';
require $api . '/lib/laturi.php';
require $api . '/lib/poze.php';
require $api . '/lib/fise.php';
require $api . '/lib/catalog.php';
require $api . '/lib/smtp.php';
require $api . '/lib/notify.php';
require $api . '/lib/plati-raport.php';
require $api . '/lib/referral.php';
require $api . '/lib/promo.php';
require $api . '/lib/firma.php';
require $api . '/lib/telegram-legaturi.php';
require $api . '/lib/productie.php';
// Socoteala costurilor (runda 2.7.0): ceasul n-o cheamă, dar lista lui e
// cea din api/index.php, bibliotecă cu bibliotecă.
require $api . '/lib/costuri.php';
// Cifrele raportului le socotesc funcțiile paginii „Încasări”, ca raportul și
// pagina să nu spună două sume. Routerul încarcă fișierul lor numai pentru
// rutele lui; aici îl cerem noi. Nu rulează nimic la încărcare: numai definește.
require $api . '/routes/plati.php';

/* --------------------------------------------------------- codul întors -- */

// Drumul înapoi din foaia de urcare despachetează arhiva veche PESTE dosar.
// `tar` pune la loc fișierele pe care le avea și versiunea veche, dar nu șterge
// nimic: ceasul ăsta și bibliotecile plăților, care în arhivă nu sunt, RĂMÂN pe
// disc. Rămâne și rândul din /etc/cron.d, și rămân în bază coloanele lui 032.
// Ceasul ar porni deci mai departe, din cinci în cinci minute, cu jumătate de
// cod vechi — și nu s-ar rupe: `plati-raport.php` nu cheamă nimic din ce a
// plecat, deci raportul ar ieși prin `send_telegram()` cel vechi, care nu știe
// de butoanele cu adresă, iar abia DUPĂ trimitere rândul din jurnal ar spune
// „oprit: Call to undefined function”. Un „oprit” mincinos, de două ori pe zi,
// despre un mesaj plecat dintr-un amestec pe care nu l-a probat nimeni.
//
// De aceea se oprește AICI, înainte de ceas: dacă bibliotecile de lângă el nu
// au uneltele rundei lui, nu e codul lui. Numele sunt cele trei pe care drumul
// ceasului le cheamă din fișiere care existau și înainte (`notify.php`,
// `auth.php`) — adică tocmai cele care pleacă la o întoarcere.
// `.claude/check-plati.mjs` ține paza la locul ei, deasupra chemării ceasului.
foreach (['telegram_api_tinta', 'telegram_url_de_buton', 'drept'] as $unealta) {
    if (!function_exists($unealta)) {
        plati_cron_gata(1, $GLOBALS['plati_cron_clipa'],
            'oprit: codul de lângă ceas e mai vechi decât el (lipsește ' . $unealta . '). '
            . 'Nu trimit nimic. Dacă ai întors arhiva veche, rândul din /etc/cron.d/goldenpastry-plati '
            . 'se poate șterge; actualizarea următoare îl pune la loc.');
    }
}

/* ----------------------------------------------------------------- ceas -- */

$tz = new DateTimeZone('Europe/Bucharest');
if ($cerut !== null) {
    $acum = plati_ceas_clipa_ceruta($cerut);
    if ($acum === null) {
        plati_cron_gata(2, $GLOBALS['plati_cron_clipa'],
            'oprit: --acum nu e o clipă. Se scrie --acum="AAAA-LL-ZZ HH:MM", în ceasul României.');
    }
} else {
    $acum = new DateTimeImmutable('now', $tz);
}
$clipa = $acum->format('Y-m-d H:i');
$GLOBALS['plati_cron_clipa'] = $clipa;

try {
    $r    = plati_ceas($acum, $uscat);
    $rand = $r['rand'];
    if ($r['incercat']) {
        // Unde bate Telegramul: se spune mereu la --uscat și, la o rulare
        // adevărată, numai când NU e adresa adevărată — pe server, un
        // `telegram_api_base` uitat în config.php ar trimite rapoartele
        // altundeva, iar rândul ăsta e locul în care se vede.
        $tinta = telegram_api_tinta();
        if ($uscat) {
            $rand = 'uscat, nimic trimis și nimic însemnat · ' . $rand . ' · spre ' . $tinta;
        } elseif ($tinta !== 'api.telegram.org') {
            $rand .= ' · spre ' . $tinta . ' (telegram_api_base din config.php)';
        }
    } elseif ($uscat) {
        $rand = 'uscat · ' . $rand;
    }
    plati_cron_gata($r['cod'], $clipa, plati_fara_token($rand), $r['texte']);
} catch (Throwable $e) {
    error_log('[gp] plati: ceasul s-a oprit: ' . get_class($e) . ': ' . plati_fara_token($e->getMessage()));
    plati_cron_gata(1, $clipa, 'oprit: ' . plati_fara_token($e->getMessage()));
}
