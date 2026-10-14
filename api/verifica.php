<?php
/**
 * Golden Pastry — verificarea serverului înainte de publicare.
 *
 * Urcă acest fișier în folderul `api/` și deschide-l în browser:
 *     https://api.goldenpastry.ro/verifica.php
 *
 * Îți spune dacă găzduirea are tot ce îi trebuie aplicației. Nu modifică
 * nimic și nu afișează parole.
 *
 * ȘTERGE-L DUPĂ CE L-AI FOLOSIT. Nu e periculos, dar nu are rost să rămână pe
 * server ceva ce spune străinilor ce versiuni rulezi.
 *
 * Dacă uiți, se închide singur: din clipa în care instalarea are un
 * administrator activ, pagina răspunde 404. Până atunci trebuie să rămână
 * deschisă — tocmai o găzduire pe care aplicația încă nu pornește verifică, iar
 * acolo n-are cine să te autentifice.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$checks = [];
$fatal  = 0;
$warn   = 0;

/**
 * @param string $name  ce verificăm
 * @param bool   $ok    a trecut?
 * @param string $found ce am găsit
 * @param string $why   de ce contează, dacă a picat
 * @param bool   $blocking  fără asta aplicația nu pornește deloc
 */
function check(string $name, bool $ok, string $found, string $why = '', bool $blocking = true): void
{
    global $checks, $fatal, $warn;
    if (!$ok) {
        if ($blocking) {
            $fatal++;
        } else {
            $warn++;
        }
    }
    $checks[] = compact('name', 'ok', 'found', 'why', 'blocking');
}

/* ------------------------------------------------------------------ PHP -- */

$phpOk = version_compare(PHP_VERSION, '8.0', '>=');
check(
    'Versiunea de PHP care servește acest domeniu',
    $phpOk,
    PHP_VERSION,
    'Aplicația folosește match() și str_starts_with(), apărute în PHP 8.0. '
        . 'Pe VPS se instalează o versiune nouă și se trece site-ul pe ea; pașii '
        . 'întregi sunt în ghidul de instalare, docs/08-publicare-vps-hostinger.md.'
);

// Versiunea din phpMyAdmin NU este neapărat aceasta: phpMyAdmin rulează pe
// PHP-ul serverului, iar fiecare domeniu poate fi pus pe altă versiune.
// Aici vezi versiunea care rulează efectiv codul aplicației.
//
// Ultima zi cu actualizări de securitate, după calendarul oficial php.net.
$eol = [
    '8.0' => '2023-11-26', '8.1' => '2025-12-31', '8.2' => '2026-12-31',
    '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31',
];
$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
if (isset($eol[$branch])) {
    $supported = $eol[$branch] >= gmdate('Y-m-d');
    check(
        'Actualizări de securitate pentru PHP ' . $branch,
        $supported,
        $supported ? 'până la ' . $eol[$branch] : 'oprite din ' . $eol[$branch],
        'PHP ' . $branch . ' nu mai primește corecții de securitate. Pe VPS se pune '
            . 'cea mai nouă (`sudo apt install php8.4-fpm php8.4-mysql php8.4-mbstring`) '
            . 'și se trece site-ul pe ea. Aplicația funcționează pe oricare de la 8.0 în sus.',
        false
    );
}

check('Arhitectură pe 64 de biți', PHP_INT_SIZE >= 8, PHP_INT_SIZE * 8 . ' biți',
    'Pe 32 de biți, datele de după 2038 se calculează greșit.', false);

/* ----------------------------------------------------------- extensii -- */

// Al treilea câmp e NUMELE PACHETULUI de pe Ubuntu, iar el nu e numele
// extensiei: `pdo_mysql` se pune cu `php8.3-mysql`. `null` înseamnă „e parte
// din PHP" — pentru astea nu există ce instala, iar o îndrumare care ar fi
// spus „apt install php8.3-json" l-ar fi trimis pe om după un pachet care nu
// există.
$ext = [
    'pdo_mysql' => ['Legătura cu baza de date. Fără ea aplicația nu pornește deloc.', true, 'mysql'],
    'openssl'   => ['Criptează tokenurile din Setări (WhatsApp, Telegram) și codul tabletelor.', true, null],
    'mbstring'  => ['Taie corect textele cu diacritice în mesajele de WhatsApp.', true, 'mbstring'],
    'curl'      => ['Trimite mesajele către WhatsApp și Telegram.', false, 'curl'],
    'json'      => ['Formatul în care vorbesc paginile cu serverul.', true, null],
    'filter'    => ['Validează adresele de e-mail.', true, null],
    // Fără ea pozele urcate NU se micșorează: ajung pe site la mărimea de pe
    // telefon, iar clientul de pe date mobile le descarcă întregi. Nimic nu se
    // strică, deci nu e blocantă — dar se vede la factura de trafic.
    'gd'        => ['Micșorează pozele urcate la 1600 de puncte pe latura lungă.', false, 'gd'],
];
// Versiunea se citește din PHP-ul care chiar rulează, nu se scrie cu mâna:
// pe un server trecut la 8.4 o îndrumare cu „8.3" scris în cod ar fi trimis
// spre un pachet greșit tocmai în clipa în care omul are nevoie de ea.
$phpV = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$cumSePune = static function (?string $pachet) use ($phpV): string {
    if ($pachet === null) {
        return ' E parte din PHP: dacă lipsește, instalarea de PHP e ciuntită — pune-o la loc cu „sudo apt install --reinstall php' . $phpV . '-common".';
    }
    return ' Se pune cu „sudo apt install php' . $phpV . '-' . $pachet
        . '", apoi „sudo systemctl reload php' . $phpV . '-fpm" (ori apache2, după cum e serverul).';
};

foreach ($ext as $name => [$why, $blocking, $pachet]) {
    $has = extension_loaded($name);
    check(
        'Extensia ' . $name,
        $has,
        $has ? 'prezentă' : 'LIPSEȘTE',
        $why . ($name === 'curl'
            ? ' Fără ea se folosesc stream-urile PHP, dar numai dacă allow_url_fopen este pornit.'
            : $cumSePune($pachet)),
        $blocking
    );
}

// Fără cURL, varianta de rezervă cere allow_url_fopen.
if (!extension_loaded('curl')) {
    check('allow_url_fopen (rezervă pentru cURL)', (bool) ini_get('allow_url_fopen'),
        ini_get('allow_url_fopen') ? 'pornit' : 'oprit',
        'Fără cURL și fără allow_url_fopen, notificările pe WhatsApp și Telegram nu pot pleca. '
            . 'E-mailul funcționează în continuare.', false);
}

/* ------------------------------------------------- criptarea propriu-zisă -- */

// Nu e destul ca openssl să existe: cifrul folosit trebuie să fie disponibil.
if (extension_loaded('openssl')) {
    $ciphers = openssl_get_cipher_methods();
    $hasGcm  = in_array('aes-256-gcm', array_map('strtolower', $ciphers), true);
    check('Cifrul aes-256-gcm', $hasGcm, $hasGcm ? 'disponibil' : 'INDISPONIBIL',
        'Cu el sunt criptate tokenurile. Fără el, panoul de Setări nu poate salva secrete.');
}

// Fotografiile urcate din pagina Catalog ajung aici. Fără drept de scriere,
// butonul de încărcare eșuează abia la prima poză.
$photoDir = __DIR__ . '/../products';
$hasPhotos = is_dir($photoDir);
check('Folderul products/', $hasPhotos, $hasPhotos ? 'există' : 'lipsește',
    'Aici stau fotografiile produselor. Creează-l în public_html.', false);
if ($hasPhotos) {
    check('Drept de scriere în products/', is_writable($photoDir),
        is_writable($photoDir) ? 'da' : 'nu',
        'Fără el nu poți urca fotografii din pagina Catalog. Pune drepturi 755 pe folder.', false);
}

// Cât de mare poate fi o poză urcată din Catalog. PHP are DOUĂ praguri, iar
// panoul promite 5 MB: dacă serverul e mai strâmt, poza patronului pică, și
// până acum panoul nu avea de unde ști de ce. Aici se citesc amândouă, cu
// cifră cu tot, lângă promisiunea pe care ar trebui s-o acopere.
$pozeLib  = __DIR__ . '/lib/poze.php';
$hasPoze  = is_file($pozeLib);
if ($hasPoze) {
    require_once $pozeLib;
}
$pozeLim   = $hasPoze ? poza_limite_server() : [];
$pozeGasit = $hasPoze
    ? poza_marime_text($pozeLim['upload']) . ' pe fișier, ' . poza_marime_text($pozeLim['post'])
        . ' pe cerere — panoul promite ' . poza_marime_text(POZA_MAX_OCTETI)
    : 'nu s-a putut verifica — api/lib/poze.php lipsește';
check(
    'Limita de mărime pentru fotografii',
    $hasPoze && poza_server_incape(),
    $pozeGasit,
    $hasPoze
        ? 'Serverul primește mai puțin decât promite pagina Catalog, deci pozele făcute cu'
            . ' telefonul pică fără ca panoul să poată spune de ce. Blocul care ridică limita e'
            . ' deja scris în api/.htaccess; dacă nu se aplică, serverul nu rulează PHP ca modul'
            . ' de Apache — atunci lucrează api/.user.ini, sau ridică-le din php.ini.'
        : 'Fișierul face parte din API și e cerut de api/index.php: fără el nu pornește nimic.'
            . ' Urcă folderul api/ din pachet încă o dată, întreg.',
    false
);

/*
 * Al treilea prag, și singurul despre care PHP nu poate spune nimic.
 *
 * Cele două de mai sus — `upload_max_filesize` și `post_max_size` — se citesc
 * cu `ini_get()`, fiindcă sunt ale lui PHP. `client_max_body_size` e al lui
 * nginx, iar nginx taie cererea ÎNAINTE să pornească PHP: la o poză prea mare
 * browserul primește o pagină „413" a lui nginx, fără niciun rând de-al
 * nostru în ea. Adică tocmai felul de cădere despre care panoul n-are cum să
 * spună de ce — de-aia se scrie aici, unde omul se uită anume.
 *
 * Implicitul lui nginx e 1 MB, adică sub orice poză făcută cu telefonul.
 */
$soft   = trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
$eNginx = $soft !== '' && stripos($soft, 'nginx') !== false;
$cere   = $hasPoze ? poza_marime_text($pozeLim['post']) : '13 MB';
check(
    'Plafonul serverului web (PHP nu-l poate vedea)',
    !$eNginx,
    $soft !== '' ? $soft : 'serverul nu-și spune numele',
    'Pe nginx mai e un plafon peste cele două de sus: „client_max_body_size", implicit 1 MB. El taie cererea înainte să pornească PHP, deci o poză prea mare cade cu o pagină „413" goală, fără niciun mesaj de-al aplicației. Pune-l cel puțin ' . $cere . ' în blocul „server" al site-ului (/etc/nginx/sites-available/…), apoi „sudo nginx -t && sudo systemctl reload nginx".',
    false
);

/*
 * E-MAILUL (runda 2.7.5). Aici stătea un singur rând, „Funcția mail()”, care
 * spunea „disponibilă” pe orice PHP — și pe VPS-ul fără niciun program de
 * poștă, unde `mail()` există, dar n-are cui preda mesajul. Rândul era verde
 * în tot timpul în care „Ți-ai uitat parola?” nu mai trimitea nimic.
 *
 * Programul de poștă se caută acum cu ADEVĂRAT, cu aceeași funcție pe care o
 * folosește aplicația când trimite (`mail_direct_stare()`, api/lib/smtp.php):
 * o a doua căutare scrisă aici s-ar despărți de cea adevărată. Rândurile se
 * scriu mai jos, lângă baza de date — abia acolo se știe dacă e-mailul pleacă
 * prin căsuța din Setări ori direct de pe server, iar un program de poștă
 * lipsă e o pagubă numai pe al doilea drum. Dacă baza nu răspunde, rămâne
 * rândul de după ea, numai cu ce se poate ști fără bază.
 */
$smtpLib = __DIR__ . '/lib/smtp.php';
$hasSmtp = is_file($smtpLib);
if ($hasSmtp) {
    require_once $smtpLib;
}
$postaDirect = $hasSmtp ? mail_direct_stare() : (function_exists('mail') ? 'nestiut' : 'nu');
$postaSpusa  = false;
$postaVorba  = [
    'da'      => 'există',
    'nu'      => function_exists('mail') ? 'lipsește' : 'funcția mail() e oprită',
    'nestiut' => 'nu se poate ști (PHP n-are voie să se uite ori nu are un program de căutat)',
];

/* ------------------------------------------------------------ fișiere -- */

// Calea se află ca la `api/index.php` și `api/setup.php`: mediul are
// întâietate. `GP_CONFIG` ține datele de conectare în afara folderului
// publicat — așa pornește și pachetul de probă (.claude/deploy-server.php).
// Căutat numai lângă el, fișierul părea lipsă pe un server care mergea
// perfect. Iar rândul roșu fals era paguba mică: tot ce urmează atârnă de
// `$hasConfig`, deci pagina se oprea din verificat exact acolo unde avea mai
// mult de spus — și se oprea în verde, fiindcă cine se uită la ea numără
// rânduri bune, nu rânduri lipsă.
$dinMediu   = (string) (getenv('GP_CONFIG') ?: '');
$configPath = $dinMediu !== '' ? $dinMediu : (__DIR__ . '/config.php');
$hasConfig  = is_file($configPath);
// Calea din mediu se SPUNE, dar nu se scrie întreagă: pe o găzduire încă fără
// configurare pagina n-are niciun cod care s-o apere, iar o cale de pe disc
// spusă unui trecător e mai mult decât îi trebuie.
check(
    'Fișierul api/config.php',
    $hasConfig,
    $hasConfig ? ($dinMediu !== '' ? 'există, pe calea din GP_CONFIG' : 'există') : 'lipsește',
    $dinMediu !== ''
        ? 'Variabila de mediu GP_CONFIG arată spre un fișier care nu există. Îndreapt-o, '
            . 'ori scoate-o cu totul, ca să fie căutat api/config.php.'
        : 'Copiază config.example.php ca config.php și completează datele bazei de date.'
);

/* ------------------------------------------------- baza de date, dacă se poate -- */

if ($hasConfig) {
    $cfg = require $configPath;
    $db  = $cfg['db'] ?? [];

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'] ?? '127.0.0.1',
            (int) ($db['port'] ?? 3306),
            $db['name'] ?? '',
            $db['charset'] ?? 'utf8mb4'
        );
        $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        check('Conectarea la baza de date', true, 'reușită');

        $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        check('Versiunea serverului de baze de date', true, $ver);

        // Diacriticele: dacă legătura nu e pe utf8mb4, „Predată" ajunge „Predat?".
        $charset = (string) $pdo->query("SELECT @@character_set_connection")->fetchColumn();
        check('Codificarea legăturii', str_starts_with($charset, 'utf8mb4'), $charset,
            'Cu altă codificare, diacriticele din denumirile produselor și din etape se strică.');

        // Tabelele aplicației: câte din ele există deja în bază?
        //
        // Lista trebuie să fie completă, altfel pagina minte tocmai în situația
        // pentru care există. S-a întâmplat de două ori, în același fel:
        //
        //   1. `product_categories` lipsea de aici, deci un import vechi sau
        //      parțial, fără el, primea „14 din 14 prezente" și bifă verde —
        //      iar prima deschidere a paginii Catalog cădea cu eroare de bază
        //      de date.
        //   2. Apoi lista a rămas la cele 17 tabele din `sql/001_schema.sql`,
        //      iar `referral_commissions` (008), `founder_invites` (009),
        //      `lab_shifts` (014) și `lab_messages` (015) n-au intrat niciodată
        //      în ea: o bază oprită la `002` primea „17 din 17 prezente" și
        //      bifă verde, fără patru tabele din douăzeci și unu.
        //
        // A doua oară NU s-a călcat nicio regulă. Regula scrisă aici zicea „când
        // se adaugă un tabel ÎN SCHEMĂ, se adaugă și aici", iar cele patru n-au
        // fost adăugate în schemă: au venit prin migrări. Regula era scrisă
        // pentru evenimentul greșit, deci n-avea cum să prindă ce s-a întâmplat.
        // De-aia o reparație care doar adaugă cele patru nume ar repeta
        // greșeala: peste trei luni ar fi cinci.
        //
        // Lista nu se mai ține deci de mână. `.claude/test-doc-actualizari.mjs`
        // (regula „lista de tabele din verifica.php e chiar ce creeaza sql")
        // cere ca cheile de mai jos să fie EXACT numele din toate `CREATE TABLE`
        // din `sql/`, fiecare cu fișierul care îl naște primul. O migrare nouă
        // cu un tabel nou înroșește proba în ziua în care intră în `sql/` — nu
        // peste trei luni, în pagina asta.
        //
        // DE CE RĂMÂNE SCRISĂ ÎN COD, ȘI NU SE CITEȘTE DIN `sql/`. Pe VPS
        // folderul acela nu ajunge lângă `api/`: `actualizeaza-vps.sh:776-779`
        // copiază în `/var/www/gp/` numai `api`, `public_html`, `clienti` și
        // `lab`, iar `sql/` rămâne unde a fost urcat, în `/root/deploy/`, de
        // unde îl citesc scripturile de server. O citire de pe disc n-ar găsi
        // nimic exact pe serverul pentru care există pagina: ori ar tăcea verde,
        // ori ar pune un rând „nu pot ști" la fiecare deschidere. Adevărul stă
        // deci în cod, iar proba îl ține în acord cu discul.
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $need   = [
            'users'                => '001_schema.sql',
            'sessions'             => '001_schema.sql',
            'password_resets'      => '001_schema.sql',
            'product_categories'   => '001_schema.sql',
            'products'             => '001_schema.sql',
            'order_requests'       => '001_schema.sql',
            'order_request_items'  => '001_schema.sql',
            'orders'               => '001_schema.sql',
            'order_items'          => '001_schema.sql',
            'stock_items'          => '001_schema.sql',
            'stock_moves'          => '001_schema.sql',
            'product_recipes'      => '001_schema.sql',
            'stock_reservations'   => '001_schema.sql',
            'audit_log'            => '001_schema.sql',
            'notifications'        => '001_schema.sql',
            'settings'             => '001_schema.sql',
            'login_attempts'       => '001_schema.sql',
            'referral_commissions' => '008_comisioane_recomandari.sql',
            'founder_invites'      => '009_pontaj_si_roluri.sql',
            'lab_shifts'           => '014_pontaj_laborator.sql',
            'lab_messages'         => '015_mesaje_laborator.sql',
            'request_claims'       => '019_jeton_cerere.sql',
            'telegram_links'       => '021_raspundere.sql',
            'lab_production'       => '021_raspundere.sql',
            'schema_migrations'    => '025_registru_migrari.sql',
            'role_rights'          => '033_acces.sql',
            'stock_item_prices'    => '034_costuri.sql',
            'orders_deleted'       => '035_comenzi_sterse.sql',
            'telegram_oameni'      => '036_telegram_oameni.sql',
        ];
        $missing = array_values(array_diff(array_keys($need), $tables));
        // Numele lipsă spune singur care fișier n-a intrat, ca în docs/01 și în
        // docs/08 („referral_commissions e din 008, lab_shifts din 014..."). Nu
        // se scrie a doua oară: se citește din lista de mai sus.
        $numite = array_map(static fn(string $t): string => $t . ' (' . $need[$t] . ')', $missing);
        // Fără tabelele schemei aplicația nu pornește deloc — prima pagină cade
        // cu eroare de bază de date. Fără cele aduse de migrări pornește, dar
        // paginile care le cer răspund „lipsește migrarea". Deosebirea se
        // citește tot din listă, nu se ține minte a doua oară.
        $lipsaSchema = array_filter($missing, static fn(string $t): bool => $need[$t] === '001_schema.sql');
        check(
            'Tabelele aplicației',
            $missing === [],
            $missing === []
                ? count($need) . ' din ' . count($need) . ' prezente'
                : (count($need) - count($missing)) . ' din ' . count($need) . ' — lipsesc: ' . implode(', ', $numite),
            'sql/001_schema.sql și sql/002_seed.sql NU sunt tot: restul tabelelor se nasc abia '
                . 'în migrări. Rulează TOATE fișierele din sql/, în ordinea numelui — nu le alege '
                . 'pe sprânceană, o listă scrisă cu mâna e exact felul în care se uită o migrare. '
                . 'Pe server o face singur instaleaza-vps.sh, iar la actualizare actualizeaza-vps.sh; '
                . 'de mână, bucla din docs/08-publicare-vps-hostinger.md. Local: '
                . 'docs/01-instalare-locala.md. Fișierul scris lângă fiecare nume de mai sus e '
                . 'chiar migrarea care n-a intrat.',
            $lipsaSchema !== []
        );

        // ---------------------------------------------- registrul migrărilor
        //
        // Până în runda 2.4.0, pagina asta ghicea din schemă: fiecare bloc de
        // mai jos caută un tabel sau o coloană și deduce din prezența lor că
        // migrarea a intrat. Merge cât timp migrarea ADUCE ceva. `017` nu aduce:
        // scrie un singur rând în `settings`, `referral_rate_default`, pe care
        // `008` îl pusese deja acolo cu valoarea 0. Prezența rândului nu
        // deosebește o bază cu 017 de una fără, iar valoarea nu se poate judeca
        // — 017 o îndreaptă la 5 numai acolo unde n-a atins-o omul, deci un zero
        // pus de patron rămâne zero, pe bună dreptate.
        //
        // Așa că pagina arăta tot verde, iar recomandările aduceau 0 lei.
        //
        // De-aceea fiecare migrare de la `003` încolo își scrie acum numele în
        // `schema_migrations`, la coada ei. Registrul ÎNSEAMNĂ, nu OPREȘTE:
        // migrările rulează toate, la fiecare actualizare, ca și până acum.
        //
        // Lista de mai jos e ținută de aceeași regulă ca `$need`: nu se scrie de
        // mână, `.claude/test-doc-actualizari.mjs` cere să fie EXACT fișierele
        // `003`+ de pe disc, iar pricina pentru care stă în cod și nu se citește
        // din `sql/` e cea scrisă la lista aceea — pe VPS folderul `sql/` nu
        // ajunge niciodată lângă `api/`.
        $migrari = [
            '003_route_order.sql',
            '004_variante_pe_pozitie.sql',
            '005_parola_schimbata.sql',
            '006_retete_rezervari.sql',
            '007_update_roles_referral.sql',
            '008_comisioane_recomandari.sql',
            '009_pontaj_si_roluri.sql',
            '010_origine_cont.sql',
            '011_reducere_pachet_test.sql',
            '012_comision_scos.sql',
            '013_anunturi_pe_canal.sql',
            '014_pontaj_laborator.sql',
            '015_mesaje_laborator.sql',
            '016_zona_cont.sql',
            '017_procent_recomandare.sql',
            '018_adresa_si_program.sql',
            '019_jeton_cerere.sql',
            '020_rang_client.sql',
            '021_raspundere.sql',
            '022_cerere_de_la_personal.sql',
            '023_laboratorul_si_livrarea.sql',
            '024_doua_cataloage.sql',
            '025_registru_migrari.sql',
            '026_cheie_cerere.sql',
            '027_poza_fiecarei_laturi.sql',
            '028_preturi_pe_trepte.sql',
            '029_numere_in_catalog.sql',
            '030_gata_de_livrare.sql',
            '031_fise_tehnice.sql',
            '032_plati_si_prag.sql',
            '033_acces.sql',
            '034_costuri.sql',
            '035_comenzi_sterse.sql',
            '036_telegram_oameni.sql',
            '037_posta_smtp.sql',
        ];
        $areRegistru = in_array('schema_migrations', $tables, true);
        check(
            'Registrul migrărilor',
            $areRegistru,
            $areRegistru ? 'prezent' : 'lipsește',
            'Tabelul în care fiecare migrare își scrie numele când rulează. Fără el, pagina '
                . 'asta nu poate ști decât ce se vede în schemă — iar o migrare care schimbă '
                . 'numai o setare nu se vede deloc acolo. Importă sql/025_registru_migrari.sql '
                . 'din phpMyAdmin, apoi rulează din nou TOATE fișierele din sql/, în ordinea '
                . 'numelui, ca fiecare să apuce să-și scrie numele.',
            false
        );
        if ($areRegistru) {
            $scrise    = $pdo->query('SELECT fisier FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
            $nescrise  = array_values(array_diff($migrari, $scrise));
            check(
                'Migrările aplicate',
                $nescrise === [],
                (count($migrari) - count($nescrise)) . ' din ' . count($migrari)
                    . ($nescrise === [] ? '' : ' — fără urmă: ' . implode(', ', $nescrise)),
                'O migrare fără urmă în registru ori n-a intrat, ori a intrat ÎNAINTE ca '
                    . 'registrul să existe — adică la o actualizare mai veche decât registrul '
                    . 'însuși, când n-avea unde-și scrie numele. Dacă ai actualizat cu '
                    . 'actualizeaza-vps.sh, rămâne numai prima pricină: scriptul face registrul '
                    . 'înaintea buclei, deci toate își scriu numele la aceeași urcare. Rulează '
                    . 'TOATE fișierele din sql/, în ordinea numelui — sunt scrise dinadins ca să '
                    . 'se poată rula de câte ori vrei. Fișierele numite aici sunt chiar cele de '
                    . 'importat.',
                false
            );
        }

        // Coloanele adăugate ulterior, prin migrarea 003. TOATE cinci, pe trei
        // tabele. Până în runda 2.4.0 blocul se uita numai la cele două de pe
        // `orders`, sub un comentariu care spunea „coloanele" — adică toate.
        //
        // Trei dintre ele (`orders.variant`, `order_requests.variant`,
        // `users.address`) sunt de mult ȘI în `sql/001_schema.sql`, deci nu pot
        // lipsi de pe o bază importată vreodată. Se măsoară totuși, fiindcă
        // paguba unei liste scrise pe jumătate nu e ce nu vede azi, ci ce crede
        // mâine cel care o citește: „migrarea 003 e acoperită aici" — și nu mai
        // pune măsurătoarea altundeva.
        //
        // Lista nu se scrie de mână de două ori: `SHOW COLUMNS` se cere o
        // singură dată pe tabel, iar tabelul lipsă se sare, nu pică.
        $col003 = [
            ['orders',         'route_seq',    'ordinea opririlor pe ruta șoferului'],
            ['orders',         'stock_synced', 'marcajul de scădere din depozit'],
            ['orders',         'variant',      'cum pleacă marfa comenzii: congelat ori copt'],
            ['order_requests', 'variant',      'aceeași alegere, pe cererea venită de pe site'],
            ['users',          'address',      'adresa clientului, pentru livrare'],
        ];
        $coloaneVazute = [];
        foreach ($col003 as [$tabel, $col, $laCeTine]) {
            if (!in_array($tabel, $tables, true)) {
                continue;
            }
            if (!isset($coloaneVazute[$tabel])) {
                $coloaneVazute[$tabel] = $pdo->query('SHOW COLUMNS FROM ' . $tabel)
                    ->fetchAll(PDO::FETCH_COLUMN);
            }
            $are = in_array($col, $coloaneVazute[$tabel], true);
            check(
                'Coloana ' . $tabel . '.' . $col,
                $are,
                $are ? 'prezentă' : 'lipsește',
                'Ține ' . $laCeTine . '. Importă sql/003_route_order.sql din phpMyAdmin.',
                false
            );
        }

        // Coloana adăugată de migrarea 005. Fără ea, pagina Conturi nu poate
        // spune care conturi mai umblă cu parola pusă de administrator, iar
        // lista de conturi se oprește cu eroare de la primul SELECT.
        if (in_array('users', $tables, true)) {
            $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
            $are  = in_array('password_changed_at', $cols, true);
            check(
                'Coloana users.password_changed_at',
                $are,
                $are ? 'prezentă' : 'lipsește',
                'Ține momentul în care omul și-a schimbat singur parola. '
                    . 'Importă sql/005_parola_schimbata.sql din phpMyAdmin.',
                false
            );
        }

        // Ce aduce migrarea 006: rețetele și rezervările. Verificăm și tabelele,
        // și coloanele, fiindcă se pot pierde separat — o bază pe care s-a
        // importat doar 001 vechi are tabelele lipsă, una migrată pe jumătate
        // le are pe ele și nu are `done_qty`.
        $lipsa006 = array_values(array_diff(['product_recipes', 'stock_reservations'], $tables));
        if (in_array('order_items', $tables, true)) {
            $cols = $pdo->query('SHOW COLUMNS FROM order_items')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('done_qty', $cols, true)) {
                $lipsa006[] = 'order_items.done_qty';
            }
        }
        if (in_array('stock_moves', $tables, true)) {
            $cols = $pdo->query('SHOW COLUMNS FROM stock_moves')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('order_id', $cols, true)) {
                $lipsa006[] = 'stock_moves.order_id';
            }
        }
        check(
            'Rețetele și rezervările (migrarea 006)',
            $lipsa006 === [],
            $lipsa006 === [] ? 'prezente' : 'lipsesc: ' . implode(', ', $lipsa006),
            'Țin din ce e făcut fiecare produs și cât din stoc e deja promis unei '
                . 'comenzi. Fără ele, laboratorul nu poate raporta ce a produs, iar '
                . 'depozitul nu știe ce e rezervat. '
                . 'Importă sql/006_retete_rezervari.sql din phpMyAdmin.',
            false
        );

        // Migrarea 007 — identitatea recomandărilor. Pasul acesta lipsea, deși
        // fișierul există din aceeași zi cu 006: pe o bază fără el, codurile de
        // recomandare pur și simplu nu se nasc, iar pagina nu se plânge de
        // nimic (referral_supported() stinge totul în tăcere, dinadins). Adică
        // exact felul de lipsă pe care numai o verificare o poate arăta.
        $lipsa007 = [];
        if (in_array('users', $tables, true)) {
            $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
            foreach (['referral_code', 'referred_by', 'referred_at'] as $c) {
                if (!in_array($c, $cols, true)) {
                    $lipsa007[] = 'users.' . $c;
                }
            }
        }
        check(
            'Recomandările (migrarea 007)',
            $lipsa007 === [],
            $lipsa007 === [] ? 'prezente' : 'lipsesc: ' . implode(', ', $lipsa007),
            'Țin codul fiecărui cont și legătura „cine pe cine a adus". Fără ele, '
                . 'linkurile „?ref=" nu leagă pe nimeni, iar aplicația merge mai '
                . 'departe fără să spună nimic. '
                . 'Importă sql/007_update_roles_referral.sql din phpMyAdmin.',
            false
        );

        // Migrarea 008 — banii recomandărilor. Se verifică și tabelul, și
        // coloanele, ca la 006: se pot pierde separat, iar o bază migrată pe
        // jumătate ar avea registrul fără procentul din care se calculează.
        $lipsa008 = array_values(array_diff(['referral_commissions'], $tables));
        if (in_array('users', $tables, true)) {
            $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('referral_rate', $cols, true)) {
                $lipsa008[] = 'users.referral_rate';
            }
        }
        foreach (['orders', 'order_requests'] as $t) {
            if (in_array($t, $tables, true)) {
                $cols = $pdo->query('SHOW COLUMNS FROM ' . $t)->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('referral_code', $cols, true)) {
                    $lipsa008[] = $t . '.referral_code';
                }
            }
        }
        check(
            'Comisioanele de recomandare (migrarea 008)',
            $lipsa008 === [],
            $lipsa008 === [] ? 'prezente' : 'lipsesc: ' . implode(', ', $lipsa008),
            'Țin procentul, câștigul pe fiecare comandă și bifa de plată. Fără ele, '
                . 'codurile de recomandare merg mai departe, dar nu produc niciun ban. '
                . 'Importă sql/008_comisioane_recomandari.sql din phpMyAdmin.',
            false
        );

        // Migrarea 009 — rolul de fondator și invitațiile lui. Se verifică
        // amândouă: rolul stă în ENUM-ul coloanei, tabelul stă separat, iar un
        // import oprit la jumătate le poate despărți. Cu rolul dar fără tabel,
        // butonul „Cere drept de fondator" ar cădea la scriere; cu tabelul dar
        // fără rol, ar cădea abia la confirmare — adică după ce omul a primit
        // e-mailul și a apăsat.
        $lipsa009 = array_values(array_diff(['founder_invites'], $tables));
        if (in_array('users', $tables, true)) {
            $tip = (string) $pdo->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                    AND COLUMN_NAME = 'role'"
            )->fetchColumn();
            if (!str_contains($tip, "'fondator'")) {
                $lipsa009[] = 'users.role → fondator';
            }
        }
        check(
            'Contul de fondator (migrarea 009)',
            $lipsa009 === [],
            $lipsa009 === [] ? 'prezente' : 'lipsesc: ' . implode(', ', $lipsa009),
            'Fondatorul e singurul care poate face și desface administratori. Fără '
                . 'ele, butonul lipsește din pagina contului, iar restul aplicației '
                . 'merge exact ca înainte. '
                . 'Importă sql/009_pontaj_si_roluri.sql din phpMyAdmin.',
            false
        );

        /* ------------------------------------------- restul migrărilor -- */

        // Blocurile de mai sus s-au oprit la 009, iar `sql/` a ajuns la 018.
        // Adică aceeași scădere ca la lista de tabele, cu altă față: o
        // actualizare rămasă pe drum, oriunde între 010 și 018, trecea prin
        // pagina asta fără un cuvânt — tocmai pagina pe care o deschide omul ca
        // să afle dacă instalarea e întreagă.
        //
        // De ce nu sunt scrise una câte una, ca 006–009: acolo fiecare migrare
        // are de spus altă poveste și o spune singură. Aici sunt opt, toate cu
        // aceeași formă („au intrat coloanele/rândurile ei?"), iar opt blocuri
        // copiate ar fi fost opt locuri de ținut minte. Tabelul de mai jos e
        // unul singur, iar `.claude/test-doc-actualizari.mjs` (regula
        // „verifica.php numeste fiecare migrare din sql") cere ca FIECARE fișier
        // din `sql/` să fie numit undeva în pagina asta. O migrare nouă
        // înroșește proba până când cineva scrie ce se vede din ea — sau de ce
        // nu se vede nimic.
        //
        // Coloanele și cheile scrise pe fiecare rând NU pot îmbătrâni, și asta
        // nu din noroc: un fișier din `sql/` nu se mai schimbă după ce a plecat
        // pe un server adevărat — corectura se face într-o migrare NOUĂ, nu în
        // una veche. Deci singurul lucru care poate apărea vreodată e un fișier
        // nou, iar tocmai pe el îl păzește proba de mai sus. Ce îmbătrânea până
        // acum era lista fișierelor, nu cuprinsul lor.
        //
        // `017_procent_recomandare.sql` e numită aici anume, și tot aici i se
        // scrie pricina: ea NU se vede în bază. Scrie un singur rând în
        // `settings`, `referral_rate_default`, pe care `008` îl pusese deja (cu
        // valoarea 0), deci prezența rândului nu deosebește o bază cu 017 de una
        // fără. Iar valoarea nu se poate judeca: 017 o îndreaptă la 5 numai
        // acolo unde n-a atins-o omul, și un zero pus de patron rămâne zero.
        // O verificare care ar cere „5" ar da roșu pe o instalare cinstită. Se
        // verifică în Setări → General, cum scrie docs/08.
        $altele = [
            '004_variante_pe_pozitie.sql' => [
                'nume'    => 'Variantele pe poziție (migrarea 004)',
                'coloane' => ['order_items' => ['variant'], 'order_request_items' => ['variant'],
                              'notifications' => ['seen_at']],
                'setari'  => [],
                'ce'      => 'Țin varianta aleasă pe fiecare rând de comandă și clipa în care a fost '
                    . 'văzut un anunț. Fără ele, comanda pierde varianta, iar clopoțelul nu mai știe ce e citit.',
            ],
            '010_origine_cont.sql' => [
                'nume'    => 'Originea conturilor (migrarea 010)',
                'coloane' => ['users' => ['self_registered', 'vouched_at', 'vouched_by']],
                // Urma pe care migrarea și-o scrie singură după ce judecă
                // conturile vechi (010, la coadă). E singurul rând din `settings`
                // care spune sigur „010 a rulat".
                'setari'  => ['migration_010_origin_judged'],
                'ce'      => 'Spun care cont s-a înscris singur și cine l-a girat. Fără ele, pagina '
                    . 'Conturi nu poate deosebi un client venit de pe site de unul făcut de patron.',
            ],
            '011_reducere_pachet_test.sql' => [
                'nume'    => 'Reducerile pe comandă (migrarea 011)',
                'coloane' => ['orders' => ['promo'], 'order_requests' => ['promo'],
                              'order_items' => ['list_price'], 'order_request_items' => ['list_price']],
                'setari'  => [],
                'ce'      => 'Țin numele reducerii și prețul de listă de dinaintea ei. Fără ele, '
                    . 'comanda nu mai poate arăta cât s-a scăzut și din ce.',
            ],
            '012_comision_scos.sql' => [
                'nume'    => 'Comisioanele scoase din plată (migrarea 012)',
                'coloane' => ['referral_commissions' => ['dismissed_at', 'dismissed_by']],
                'setari'  => [],
                'ce'      => 'Țin cine și când a scos un comision din plată. Fără ele, panoul '
                    . 'Recomandări nu poate renunța la un câștig fără să-l șteargă.',
            ],
            '013_anunturi_pe_canal.sql' => [
                'nume'    => 'Anunțurile pe canal (migrarea 013)',
                'coloane' => ['notifications' => ['event'],
                              'order_requests' => ['decided_via', 'snoozed_until']],
                'setari'  => ['notify_email_events', 'notify_whatsapp_events', 'notify_telegram_events',
                              'notify_telegram_buttons', 'notify_telegram_webhook_secret',
                              'notify_telegram_webhook_status', 'notify_telegram_actor_id'],
                'ce'      => 'Țin ce fel de anunț a plecat pe ce canal și cum a fost hotărâtă o cerere. '
                    . 'Fără ele, Setările nu pot alege ce se trimite pe e-mail, pe WhatsApp și pe Telegram.',
            ],
            '014_pontaj_laborator.sql' => [
                'nume'    => 'Codul de tabletă al pontajului (migrarea 014)',
                'coloane' => ['users' => ['lab_pin']],
                'setari'  => [],
                // Tabelul `lab_shifts` e numărat sus, la tabelele aplicației;
                // aici rămâne doar coloana, care se pierde separat de el.
                'ce'      => 'Ține codul cu care intră omul pe tableta din laborator. Fără el, lista '
                    . 'de nume de pe tabletă rămâne goală, chiar dacă tabelul turelor există.',
            ],
            '016_zona_cont.sql' => [
                'nume'    => 'Zona de livrare pe cont (migrarea 016)',
                'coloane' => ['users' => ['zone']],
                'setari'  => [],
                'ce'      => 'Ține zona în care livrezi fiecărui client. Fără ea, paginile care '
                    . 'socotesc livrarea răspund „nu pot încă", iar zona se ghicește la fiecare comandă.',
            ],
            '018_adresa_si_program.sql' => [
                'nume'    => 'Adresa și programul public (migrarea 018)',
                'coloane' => [],
                'setari'  => ['company_address', 'company_hours'],
                'ce'      => 'Țin adresa și programul arătate pe site. Fără rândurile lor, casetele '
                    . 'din Setări → General stau închise și site-ul arată ce scrie în cod.',
            ],
            // `request_claims` e numărat sus, la tabelele aplicației; aici stă
            // numai lămurirea, ca omul să știe ce pierde fără el.
            '019_jeton_cerere.sql' => [
                'nume'    => 'Jetonul cererii de pe site (migrarea 019)',
                'coloane' => [],
                'setari'  => [],
                'ce'      => 'Leagă cererea trimisă de pe site de contul făcut imediat după ea. '
                    . 'Fără tabelul lui, butonul „Trimite cererea și fă-ți cont” trimite cererea '
                    . 'la fel, dar contul nou n-o vede în „Comanda mea” până n-o leagă cineva cu mâna.',
            ],
            // 020 nu aduce niciun tabel și nicio coloană — numai un index. Nu se
            // poate pierde nimic fără el, doar se merge mai încet, deci nu are ce
            // căuta în rândul „de rezolvat”. Stă aici ca să fie numită, atât.
            '020_rang_client.sql' => [
                'nume'    => 'Indexul insignei de client (migrarea 020)',
                'coloane' => [],
                'setari'  => [],
                'ce'      => 'Grăbește socoteala insignei de pe fiecare comandă (prima comandă, '
                    . 'client revenit, fără cont). Fără el insignele ies la fel, doar mai încet: '
                    . 'nicio poartă din cod nu-l caută.',
            ],
            // `telegram_links` și `lab_production` sunt numărate sus, la tabelele
            // aplicației; aici stă coloana lui `users` și lămurirea a ce se pierde.
            '021_raspundere.sql' => [
                'nume'    => 'Cine a făcut și cine era de tură (migrarea 021)',
                'coloane' => ['users' => ['telegram_user_id']],
                'setari'  => [],
                'ce'      => 'Țin trei lucruri deodată. Coloana leagă contul omului de Telegramul '
                    . 'lui, ca fapta apăsată pe telefon să se scrie pe contul lui, cu drepturile '
                    . 'lui; fără ea, nimeni nu e legat, deci toate apăsările ies „cont nelegat”, '
                    . 'pe drepturile contului care a legat botul. Tabelul `telegram_links` ține cererile de '
                    . 'legare cât sunt în așteptare; fără el, legarea nu se poate porni deloc. '
                    . 'Tabelul `lab_production` ține fiecare bucată numărată în laborator cu '
                    . 'clipa ei; fără el, raportul de producție pe tură iese gol — nu dă eroare, '
                    . 'doar nu are ce număra.',
            ],
            // 022 nu aduce niciun tabel — numai patru coloane pe `order_requests`,
            // toate NULL sau cu implicit, deci rândurile vechi rămân întregi.
            '022_cerere_de_la_personal.sql' => [
                'nume'    => 'Cererea scrisă de personal (migrarea 022)',
                'coloane' => ['order_requests' => ['fulfillment', 'urgent', 'timed', 'created_by']],
                'setari'  => [],
                'ce'      => 'Țin ce a ales omul care a luat telefonul: modul de onorare, graba și '
                    . 'ora fixă, plus cine a scris cererea. Fără ele, agentul de vânzări și '
                    . 'angajatul încă pot trimite cereri, dar alegerile lor se pierd tăcut între '
                    . 'formular și acceptare: comanda născută cade pe modul implicit, nu e urgentă '
                    . 'chiar dacă a fost cerută așa, și poartă numele celui care a apăsat '
                    . '„Acceptă”, nu al celui care a scris-o.',
            ],
            // 023 nu aduce niciun tabel — două coloane, pe două tabele deosebite,
            // amândouă cu implicit, deci rândurile vechi rămân întregi.
            '023_laboratorul_si_livrarea.sql' => [
                'nume'    => 'Ordinea laboratorului și dreptul de livrare (migrarea 023)',
                'coloane' => ['orders' => ['lab_seq'], 'users' => ['poate_confirma_livrarea']],
                'setari'  => [],
                'ce'      => 'Prima ține ordinea în care laboratorul lucrează ziua, ca ea să fie a '
                    . 'casei și nu a ecranului de pe care s-a aranjat; fără ea tableta aranjează '
                    . 'pentru ea singură, iar împărțirea cantităților produse — care merge de sus '
                    . 'în jos pe lista aceea — umple alte comenzi după fiecare tabletă. A doua '
                    . 'spune cine poate confirma livrarea; fără ea paza CADE DESCHISĂ și etapa '
                    . 'finală se poate pune din nou din laborator, adică exact purtarea de '
                    . 'dinainte. O poartă care se închide singură într-o fereastră de actualizare '
                    . 'ar fi mai rea decât paguba de care fuge.',
            ],
            // 024 nu aduce niciun tabel — trei coloane pe `products`, toate cu
            // implicit, deci rândurile vechi rămân întregi. Cele două bife se
            // nasc APRINSE (`DEFAULT 1`): un produs născut de codul vechi, în
            // fereastra dintre fișiere și SQL, trebuie să se nască în amândouă
            // cataloagele, nu în niciunul.
            '024_doua_cataloage.sql' => [
                'nume'    => 'Două cataloage, o vitrină (migrarea 024)',
                'coloane' => ['products' => ['in_frozen', 'in_baked', 'photo_baked']],
                'setari'  => [],
                'ce'      => 'Cele două bife spun în CARE catalog e produsul — congelate, coapte '
                    . 'sau amândouă —, iar a treia coloană ține poza laturii coapte. '
                    . 'Fără ele magazinul se întoarce la regula veche, „se vede numai cine are '
                    . 'AMÂNDOUĂ prețurile", și atunci un produs care se vinde numai copt '
                    . 'nu poate exista: ori îi inventezi un preț congelat pe care nu-l '
                    . 'vinzi nimănui, ori nu se vede nicăieri. Purtarea fără ele nu e stricată, e '
                    . 'cea de dinainte — codul nou o ține dinadins, fiindcă fișierele ajung pe '
                    . 'server înaintea importului. Ce se pierde până la import: cele două bife din '
                    . 'panou n-au unde se scrie, iar o poză urcată pentru coapte ar cădea '
                    . 'peste cea congelată.',
            ],
            // 026 nu aduce niciun tabel — o coloană pe `order_requests` ȘI
            // INDEXUL EI, care e chiar reparația. De-aia se cer amândouă: o
            // bază cu coloana pusă și indexul nepus ar primi cheile și n-ar
            // observa niciodată o dublură, iar rândul ăsta ar fi verde peste o
            // pază care nu păzește. `request_key_supported()` întreabă tot de
            // index, din aceeași pricină.
            '026_cheie_cerere.sql' => [
                'nume'     => 'Cheia care recunoaște cererea trimisă a doua oară (migrarea 026)',
                'coloane'  => ['order_requests' => ['idempotency_key']],
                'indexuri' => ['order_requests' => ['uq_req_cheie']],
                'setari'   => [],
                'ce'       => 'Ține cele 32 de cifre pe care pagina le scrie o dată pe apăsare și '
                    . 'le păstrează la retrimitere, iar indexul oprește a doua scriere a '
                    . 'aceleiași cereri — serverul răspunde atunci cu cererea dintâi, ca și cum '
                    . 'atunci ar fi sosit. Fără ele, o legătură care cade tocmai după ce cererea '
                    . 'a intrat îl lasă pe om să apese din nou, iar laboratorul primește DOUĂ '
                    . 'comenzi identice, pe care administratorul le acceptă pe amândouă: marfă '
                    . 'dublată, rezervări dublate. Purtarea fără ele nu e stricată, e cea de '
                    . 'dinainte — pagina spune atunci, ca până acum, că nu se știe dacă cererea '
                    . 'a ajuns și îl trimite pe om să se uite întâi.',
            ],
            // 027 nu aduce nici tabel, nici coloană: mută o dată datele dintr-o
            // coloană în cealaltă. E deci de soiul lui 017 și 010 — nu se vede în
            // schemă —, iar singurul lucru care spune sigur „a rulat" e urma pe
            // care și-o scrie ea la coadă, în `settings`. Fără ea, pagina asta n-ar
            // avea ce măsura și ar tăcea verde peste o vitrină golită pe jumătate.
            '027_poza_fiecarei_laturi.sql' => [
                'nume'    => 'Poza fiecărei laturi, a ei (migrarea 027)',
                'coloane' => [],
                'setari'  => ['migration_027_poza_pe_latura'],
                'ce'      => 'Face a fiecărei laturi poza pe care până acum o ÎMPRUMUTA de la '
                    . 'cealaltă: până în 2.4.1, o latură fără poză a ei o arăta pe a celeilalte, '
                    . 'și de-aia schimbarea pozei la congelate se vedea și la coapte. '
                    . 'Împrumutul s-a tăiat, iar migrarea asta e cea care copiază o dată pentru '
                    . 'totdeauna, ca în ziua schimbării amândouă laturile să arate exact ce '
                    . 'arătau. Fără ea, codul nou pe baza veche lasă „Coapte" fără '
                    . 'nicio poză — fiecare produs cu substituentul „fără poză" pe el.',
            ],
            // 028 aduce coloane pe trei tabele ȘI patru rânduri în `settings`.
            // Se cer toate: `catalog_trepte_supported()` cere coloanele pe
            // AMÂNDOUĂ felurile de rânduri de comandă, iar cardul „Catalog" din
            // Setări nu se poate salva fără rândurile lui (salvarea scrie numai
            // cu UPDATE).
            '028_preturi_pe_trepte.sql' => [
                'nume'    => 'Trei prețuri, după cantitate (migrarea 028)',
                'coloane' => [
                    'products'            => ['price_frozen_b', 'price_frozen_c', 'price_baked_b',
                                              'price_baked_c', 'prag_b', 'prag_c'],
                    'order_items'         => ['treapta', 'prag_treapta'],
                    'order_request_items' => ['treapta', 'prag_treapta'],
                ],
                'setari'  => ['catalog_vedere_telefon', 'catalog_vedere_calculator',
                              'pret_prag_b_implicit', 'pret_prag_c_implicit'],
                'ce'      => 'Țin prețurile B și C ale fiecărei laturi, pragurile de la care încep, '
                    . 'și — pe fiecare rând de comandă — treapta la care s-a pus prețul. Fără ele '
                    . 'fiecare produs se vinde cu un singur preț, oricâte bucăți s-ar cumpăra: '
                    . 'casetele B și C din Produse refuză salvarea, iar cardul „Catalog" din '
                    . 'Setări nu are unde scrie vederea cu care se deschide magazinul. Purtarea '
                    . 'fără ele nu e stricată, e cea de dinainte — prețul A e prețul de ieri.',
            ],
            // 029 e de soiul lui 027: nu atinge schema, renumerotează o dată
            // `products.sort`. Singura urmă sigură e semnul din `settings`, pe
            // care îl citește și serverul ca poartă (`catalog_numere_supported()`).
            '029_numere_in_catalog.sql' => [
                'nume'    => 'Numărul din colțul pozei, ales de mână (migrarea 029)',
                'coloane' => [],
                'setari'  => ['migration_029_numere_catalog'],
                'ce'      => 'Numerotează o dată produsele 1, 2, 3… în ordinea în care au fost puse, '
                    . 'iar de atunci numărul din colțul pozei se alege din Catalog. Fără ea, '
                    . 'numărul rămâne cel de ieri — cheia bazei, pe care n-o poate schimba nimeni — '
                    . 'iar caseta „Număr în catalog" din Produse refuză salvarea. Purtarea fără ea '
                    . 'nu e stricată, e cea de dinainte.',
            ],
            // 030 e de soiul lui 029: nu atinge schema, pune o dată un nume în
            // lista de etape. Numele NU se cere aici, dinadins — lista e a
            // patronului, iar o etapă redenumită ori scoasă din Setări ar
            // înroși pagina pe o instalare cinstită (aceeași pricină pentru
            // care 017 nu-și judecă cifra). Se cere semnul, care spune „a
            // trecut pe-aici" oricum ar arăta lista azi.
            '030_gata_de_livrare.sql' => [
                'nume'    => 'Etapa „Gata de livrare", înaintea celei finale (migrarea 030)',
                'coloane' => [],
                'setari'  => ['migration_030_gata_de_livrare'],
                'ce'      => 'Pune o dată etapa „Gata de livrare" în lista din Setări, chiar înaintea '
                    . 'celei finale: laboratorul se oprește la ea, iar curierul ia de acolo. Fără '
                    . 'ea, laboratorul se oprește tot la ultima etapă de dinaintea celei finale — '
                    . 'la ambalare —, deci o comandă abia intrată la ambalat arată la fel ca una '
                    . 'gata de plecare, iar butonul curierului nu are după ce se aprinde. Comenzile '
                    . 'aflate la ambalare în ziua importului rămân acolo: laboratorul mai apasă o '
                    . 'dată pe fiecare.',
            ],
            // 031 aduce două coloane pe `products` și semnul ei. Se cer toate
            // trei: coloanele spun că fișa are unde se scrie, semnul spune că
            // migrarea a mers până la capăt.
            '031_fise_tehnice.sql' => [
                'nume'    => 'Fișa tehnică a fiecărei laturi (migrarea 031)',
                'coloane' => ['products' => ['sheet_frozen', 'sheet_baked']],
                'setari'  => ['migration_031_fise_tehnice'],
                'ce'      => 'Țin, pe fiecare produs, calea fișei tehnice a laturii congelate și pe a '
                    . 'celei coapte, iar migrarea leagă o dată de produse fișele venite cu pachetul. '
                    . 'Fără ele, magazinul nu arată butonul fișei la niciun produs, iar încărcarea '
                    . 'unei fișe din Produse e refuzată cu un mesaj care numește migrarea. Purtarea '
                    . 'fără ele nu e stricată, e cea de dinainte.',
            ],
            // 032 aduce șapte coloane pe `orders`, una pe `users`, șapte rânduri
            // în `settings` și semnul ei. Se cer toate: coloanele spun că plata
            // are unde se scrie, rândurile că pragul și raportul au de unde se
            // citi, semnul că și comenzile de dinainte au fost judecate — o
            // dată. Cele două indexuri ale ei (`ix_orders_plata`,
            // `ix_orders_plata_la`) rămân numite, nu cerute, ca al lui 020: doar
            // grăbesc numărătoarea, nu țin nimic.
            '032_plati_si_prag.sql' => [
                'nume'    => 'Plata fiecărei comenzi și pragul de neplătite (migrarea 032)',
                'coloane' => [
                    'orders' => ['plata', 'plata_scadenta', 'plata_la', 'plata_de',
                                 'plata_suma', 'plata_nota', 'plata_amintire_la'],
                    'users'  => ['prag_neplatite'],
                ],
                'setari'  => ['plati_prag_implicit', 'plati_telegram_chat_id', 'plati_raport_ore',
                              'plati_amintiri', 'plati_raport_trimis_1', 'plati_raport_trimis_2',
                              'panel_base_url', 'migration_032_plati_si_prag'],
                'ce'      => 'Țin starea plății fiecărei comenzi — neplătită, amânată, plătită ori '
                    . 'plătită prin bancă —, cu scadența, clipa, suma și omul care a bifat-o; pe '
                    . 'cont, pragul lui de comenzi neplătite; iar în `settings`, pragul general, '
                    . 'chatul și orele raportului de bani. Semnul spune că s-au judecat o dată '
                    . 'comenzile de dinainte: cele predate, cu ziua trecută, au fost trecute '
                    . 'plătite. Fără coloane, plata unei comenzi n-are unde se scrie și pragul '
                    . 'n-are ce număra; fără judecata aceea, fiecare client vechi apare cu tot '
                    . 'istoricul lui „de plată".',
            ],
            // `role_rights` e numărat sus, la tabelele aplicației; aici stă
            // numai lămurirea, ca la 019. Migrarea nu pune nicio coloană pe un
            // tabel vechi și niciun rând în `settings`, deci n-are ce cere.
            '033_acces.sql' => [
                'nume'    => 'Bifele mutate în „Acces" (migrarea 033)',
                'coloane' => [],
                'setari'  => [],
                'ce'      => 'Ține bifele pe care fondatorul le-a mutat în „Acces" — numai pe cele '
                    . 'schimbate față de pornire; tabelul gol înseamnă „ca până ieri". Fără '
                    . 'tabelul ei, fiecare rol intră exact unde intra și până acum, citirea '
                    . 'matricei răspunde cu cea din pornire, iar salvarea unei bife e refuzată cu '
                    . 'un mesaj care numește migrarea. Purtarea fără el nu e stricată, e cea de '
                    . 'dinainte.',
            ],
            // `stock_item_prices` e numărat sus, la tabelele aplicației; aici stă
            // numai lămurirea. Tabelul pornește gol, iar migrarea n-are semn.
            '034_costuri.sql' => [
                'nume'    => 'Prețul de cumpărare al articolelor din depozit (migrarea 034)',
                'coloane' => [],
                'setari'  => [],
                'ce'      => 'Ține istoricul prețurilor de cumpărare — cât costă făina, ouăle, untul, '
                    . 'și din ce zi —, din care se socotesc costul fiecărui produs și ce rămâne din '
                    . 'el. Fără tabelul lui, niciun preț n-are unde se scrie, deci nici costul n-are '
                    . 'din ce se socoti. Articolele, rețetele și mișcările din depozit nu-l cer.',
            ],
            // `orders_deleted` e numărat sus, la tabelele aplicației; aici stă
            // numai lămurirea. Arhiva pornește goală, iar migrarea n-are semn.
            '035_comenzi_sterse.sql' => [
                'nume'    => 'Ce era într-o comandă ștearsă (migrarea 035)',
                'coloane' => [],
                'setari'  => [],
                'ce'      => 'Ține poza unei comenzi anulate care a fost apoi ștearsă: antetul, '
                    . 'pozițiile, cine a șters-o și când — ca ștergerea să se poată citi și după ce '
                    . 'rândul a plecat din `orders`. Fără tabelul ei, poza n-are unde se face.',
            ],
            // `telegram_oameni` e numărat sus, la tabelele aplicației; aici stau
            // cele două coloane de pe cerere și lămurirea a ce se pierde.
            // Migrarea n-are semn în `settings`: nu face nimic „o singură dată”.
            '036_telegram_oameni.sql' => [
                'nume'    => 'Cine a apăsat, cu adevărat, pe Telegram (migrarea 036)',
                'coloane' => ['order_requests' => ['handled_tg_id', 'handled_tg_name']],
                'setari'  => [],
                'ce'      => 'Țin numele de pe Telegram al celui care a acceptat ori a respins o '
                    . 'cerere fără să aibă contul legat, ca mesajul, panoul și Jurnalul să-l '
                    . 'numească pe el, cu vorba „cont nelegat”, și nu pe cel care a legat botul. '
                    . 'Tabelul `telegram_oameni` ține oamenii văzuți apăsând în grup, din care '
                    . 'fondatorul alege, în Setări, pe cine să lege de ce cont. Fără ele, apăsarea unui om '
                    . 'cu contul nelegat e refuzată, cu un mesaj care numește migrarea — numele '
                    . 'lui n-ar avea unde se scrie. Cine are contul legat lucrează ca până acum.',
            ],
            // Numai rânduri în `settings`: nicio coloană, niciun tabel. Fără
            // registru, rândurile sunt singurul semn că migrarea a rulat.
            '037_posta_smtp.sql' => [
                'nume'    => 'Căsuța de e-mail, SMTP (migrarea 037)',
                'coloane' => [],
                'setari'  => ['mail_smtp_host', 'mail_smtp_port', 'mail_smtp_user', 'mail_smtp_password', 'mail_smtp_security'],
                'ce'      => 'Țin căsuța prin care pleacă e-mailurile: gazda, portul, utilizatorul, parola '
                    . '(criptată) și felul criptării. Fără rândurile lor, casetele din Setări → Notificări → '
                    . 'E-mail stau închise și e-mailul poate pleca numai direct de pe server — adică deloc, '
                    . 'dacă serverul n-are un program de poștă.',
            ],
        ];

        // Coloanele fiecărui tabel se cer o singură dată, nu o dată pe migrare:
        // `users` apare în trei rânduri de mai sus, iar un SHOW COLUMNS pe
        // rând ar fi trei drumuri la server pentru același răspuns.
        $coloaneLui = [];
        $vaz = static function (string $t) use (&$coloaneLui, $pdo, $tables): array {
            if (!in_array($t, $tables, true)) return [];
            if (!isset($coloaneLui[$t])) {
                $coloaneLui[$t] = $pdo->query('SHOW COLUMNS FROM ' . $t)->fetchAll(PDO::FETCH_COLUMN);
            }
            return $coloaneLui[$t];
        };
        $cheiSetari = in_array('settings', $tables, true)
            ? $pdo->query('SELECT setting_key FROM settings')->fetchAll(PDO::FETCH_COLUMN)
            : [];
        // Indexurile, la fel: o dată pe tabel, nu o dată pe migrare. `SHOW
        // INDEX` dă câte un rând pe COLOANĂ, deci un index pe două coloane vine
        // de două ori cu același `Key_name` — de-aia se trec prin `unique`.
        $indexiiLui = [];
        $vazIdx = static function (string $t) use (&$indexiiLui, $pdo, $tables): array {
            if (!in_array($t, $tables, true)) return [];
            if (!isset($indexiiLui[$t])) {
                $indexiiLui[$t] = array_values(array_unique(
                    $pdo->query('SHOW INDEX FROM ' . $t)->fetchAll(PDO::FETCH_COLUMN, 2)
                ));
            }
            return $indexiiLui[$t];
        };

        foreach ($altele as $fisier => $m) {
            $lipsa = [];
            // Ce NU s-a putut măsura, și de ce. Fără rândul ăsta, o coloană de
            // pe un tabel care lipsește ar fi trecut neverificată, iar rândul ar
            // fi spus „prezente" — adică exact tăcerea verde pe care o repară
            // lucrarea asta, mutată cu un cot mai încolo. Măsurat: pe o bază
            // oprită la `002`, unde `referral_commissions` nu există, migrarea
            // 012 ieșea bifată verde fără să se fi uitat nimeni la nimic.
            $nestiut = [];
            foreach ($m['coloane'] as $tabel => $coloane) {
                if (!in_array($tabel, $tables, true)) {
                    $nestiut[] = $tabel;
                    continue;
                }
                $are = $vaz($tabel);
                foreach ($coloane as $c) {
                    if (!in_array($c, $are, true)) $lipsa[] = $tabel . '.' . $c;
                }
            }
            // `?? []` — numai 026 cere un index până azi, iar celelalte intrări
            // n-au de ce să poarte o cheie goală. 020 aduce și el un index, dar
            // acela doar grăbește: pierdut, nu se pierde nimic, deci rămâne
            // numit, nu cerut.
            foreach ($m['indexuri'] ?? [] as $tabel => $indexuri) {
                if (!in_array($tabel, $tables, true)) {
                    $nestiut[] = $tabel;
                    continue;
                }
                $are = $vazIdx($tabel);
                foreach ($indexuri as $ix) {
                    if (!in_array($ix, $are, true)) $lipsa[] = $tabel . ', indexul ' . $ix;
                }
            }
            if ($m['setari'] !== [] && !in_array('settings', $tables, true)) {
                $nestiut[] = 'settings';
            } else {
                foreach ($m['setari'] as $k) {
                    if (!in_array($k, $cheiSetari, true)) $lipsa[] = 'settings.' . $k;
                }
            }
            $nestiut = array_values(array_unique($nestiut));
            check(
                $m['nume'],
                $lipsa === [] && $nestiut === [],
                $nestiut !== []
                    ? 'nu se poate ști: ' . (count($nestiut) === 1 ? 'lipsește tabelul ' : 'lipsesc tabelele ')
                        . implode(', ', $nestiut)
                        . ($lipsa === [] ? '' : '; lipsesc și ' . implode(', ', $lipsa))
                    : ($lipsa === [] ? 'prezente' : 'lipsesc: ' . implode(', ', $lipsa)),
                ($nestiut !== []
                    ? 'Tabelele pe care stau coloanele ei nu există încă, deci migrarea asta nu '
                        . 'poate fi măsurată. Vezi întâi rândul „Tabelele aplicației". '
                    : '')
                    . $m['ce'] . ' Importă sql/' . $fisier . ' din phpMyAdmin — sau, mai bine, '
                    . 'rulează toate fișierele din sql/ în ordinea numelui, cum face instaleaza-vps.sh.',
                false
            );
        }

        // --- ceasul plăților (runda 2.7.0) ---------------------------------
        //
        // Raportul de încasări e primul lucru din aplicație pe care nu-l cere
        // nicio pagină: îl pornește serverul, din /etc/cron.d. Lipsa lui nu
        // strică nimic la vedere — „Încasări" se deschide, chatul se salvează,
        // butonul de probă trimite — doar că la ora aleasă nu pleacă nimic. E
        // genul de lipsă pe care n-o găsește nimeni, deci se caută aici.
        //
        // Rândul spune și ULTIMA TRIMITERE, cu starea ei: un ceas pus care n-a
        // mai însemnat nimic de două zile, cu chat și ore alese, e un ceas care
        // nu rulează.
        //
        // Se citesc numai `settings` și `notifications`; nicio coloană a
        // migrării 032 nu e numită aici. Chatul NU se scrie pe pagină.
        $ceasFisier = '/etc/cron.d/goldenpastry-plati';
        $ceasPhp    = is_file(__DIR__ . '/cron/plati.php');
        $ceasText   = is_file($ceasFisier) && is_readable($ceasFisier) ? (string) file_get_contents($ceasFisier) : '';
        $ceasPus    = $ceasText !== '' && strpos($ceasText, 'api/cron/plati.php') !== false;
        $peWindows  = DIRECTORY_SEPARATOR === '\\';

        $ceasSet = [];
        // Chatul și orele, alese de mai mult de două zile? (`updated_at` se
        // mută singur la orice salvare a rândului.) Fără coloană, nu se știe,
        // deci nu se acuză.
        $ceasAlesDemult = false;
        if (in_array('settings', $tables, true)) {
            $cuData = in_array('updated_at', $vaz('settings'), true);
            $st = $pdo->prepare(
                'SELECT setting_key, value, ' . ($cuData ? '(updated_at < NOW() - INTERVAL 2 DAY)' : '0') . ' AS demult'
                . ' FROM settings WHERE setting_key IN (?, ?, ?, ?)'
            );
            $st->execute(['plati_telegram_chat_id', 'plati_raport_ore', 'plati_raport_trimis_1', 'plati_raport_trimis_2']);
            $demult = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ceasSet[(string) $r['setting_key']] = trim((string) ($r['value'] ?? ''));
                $demult[(string) $r['setting_key']]  = (int) $r['demult'] === 1;
            }
            $ceasAlesDemult = ($demult['plati_telegram_chat_id'] ?? false) && ($demult['plati_raport_ore'] ?? false);
        }
        $ceasChat = ($ceasSet['plati_telegram_chat_id'] ?? '') !== '';
        $ceasOre  = trim(str_replace(',', ' ', $ceasSet['plati_raport_ore'] ?? '')) !== '';
        $ceasZi   = max($ceasSet['plati_raport_trimis_1'] ?? '', $ceasSet['plati_raport_trimis_2'] ?? '');

        $ceasUltim = 'nicio trimitere a ceasului până acum';
        if (in_array('notifications', $tables, true) && in_array('event', $vaz('notifications'), true)) {
            // FĂRĂ RAPORTUL DE PROBĂ. Butonul „Trimite un raport de probă" din
            // „Încasări" scrie tot pe evenimentul plati_raport, dar îl apasă un
            // om, nu ceasul: socotit aici, un ceas care n-a rulat niciodată ar
            // fi arătat „ultima trimitere: raport, azi, plecată" după o singură
            // apăsare. Se deosebește prin subiect — cel din GP_PLATI_TRIMITERI
            // (api/lib/plati-raport.php), scris aici a doua oară fiindcă pagina
            // asta nu încarcă bibliotecile; check-plati le ține la fel.
            $faraProba = in_array('subject', $vaz('notifications'), true);
            $st = $pdo->prepare(
                'SELECT event, status, COALESCE(sent_at, created_at) AS la FROM notifications'
                . ' WHERE event IN (?, ?)' . ($faraProba ? ' AND (subject IS NULL OR subject <> ?)' : '')
                . ' ORDER BY id DESC LIMIT 1'
            );
            $st->execute(array_merge(['plati_raport', 'plati_amintire'], $faraProba ? ['Raport de probă'] : []));
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                // Clipa stă în bază pe UTC; aici se scrie pe ceasul patiseriei.
                $la = (new DateTimeImmutable((string) $r['la'], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i');
                $ceasUltim = 'ultima trimitere: ' . ($r['event'] === 'plati_amintire' ? 'amintire' : 'raport')
                    . ', ' . $la . ', ' . ($r['status'] === 'sent' ? 'plecată' : 'NEPLECATĂ');
            }
        }

        // Ceasul a amuțit? Numai când are ce trimite (chat și măcar o oră) și
        // ultima zi însemnată e mai veche de ieri. Fără chat, tăcerea e cea cerută.
        //
        // …ORI N-A BĂTUT NICIODATĂ. Până pe 9 oct. 2026 se cerea o zi însemnată
        // (`$ceasZi !== ''`), deci tocmai ceasul care nu pornise NICIODATĂ —
        // rândul scris pe un server fără cron — trecea drept bun, cât timp nu
        // apuca să însemneze nimic. Acum: fișierul de cron pus de mai mult de
        // două zile, chatul și orele alese tot de atâta vreme, și nicio zi
        // însemnată. Două zile, ca la celălalt caz: în ele au trecut cel puțin
        // trei ore de raport, oricum ar sta ceasurile bazei față de al serverului.
        $ieri          = (new DateTimeImmutable('yesterday', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
        $ceasPusLa     = $ceasPus ? (int) @filemtime($ceasFisier) : 0;
        $ceasNiciodata = $ceasZi === '' && $ceasAlesDemult && $ceasPusLa > 0 && $ceasPusLa < time() - 2 * 86400;
        $ceasAmutit    = $ceasPus && $ceasChat && $ceasOre && ($ceasZi !== '' ? $ceasZi < $ieri : $ceasNiciodata);

        if (!$ceasPhp) {
            $ceasGasit = 'lipsește api/cron/plati.php';
        } elseif ($peWindows) {
            // Calculatorul de lucru n-are /etc/cron.d și nici nu trebuie: acolo
            // ceasul îl pornesc probele, cu mâna.
            $ceasGasit = 'nu se judecă pe Windows (pe server îl pune actualizeaza-vps.sh)';
        } elseif (!$ceasPus) {
            $ceasGasit = is_dir('/etc/cron.d') ? 'nepus: lipsește ' . $ceasFisier : 'nepus: serverul nu are cron (/etc/cron.d)';
        } else {
            $ceasGasit = 'pus, din 5 în 5 minute';
        }
        check(
            'Ceasul plăților (raportul de încasări pe Telegram)',
            $ceasPhp && ($peWindows || $ceasPus) && !$ceasAmutit,
            $ceasGasit . ' · ' . ($ceasChat ? $ceasUltim : 'chatul banilor nu e ales, deci nu pleacă nimic')
                . ($ceasAmutit ? ' · ultima zi însemnată: ' . ($ceasZi !== '' ? $ceasZi : 'niciuna, niciodată') : ''),
            $ceasAmutit
                ? ($ceasZi !== ''
                    ? 'Ceasul e pus, chatul și orele sunt alese, dar de două zile n-a mai plecat niciun raport. '
                    : 'Ceasul e pus de mai mult de două zile, chatul și orele sunt alese, dar n-a însemnat '
                        . 'niciodată vreo oră: nu rulează (de obicei: serverul nu are programul cron, ori '
                        . 'serviciul lui e oprit). ')
                    . 'Vezi ce spune: journalctl -t gp-plati -n 20 — și dacă serviciul cron merge: '
                    . 'systemctl status cron (dacă lipsește: apt-get install -y cron).'
                : 'Raportul de încasări și amintirile de scadență le pornește serverul, nu o pagină: '
                    . 'fără rândul din ' . $ceasFisier . ' nu pleacă niciunul, oricât de bine ar fi pus '
                    . 'chatul în „Încasări". Îl scrie actualizeaza-vps.sh (și cu --doar-fisiere) și '
                    . 'instaleaza-vps.sh; dacă lipsește cron, întâi: apt-get install -y cron.',
            false
        );

        // Adresa Telegramului se poate muta din config.php pe un primitor
        // local, pentru probe. Rămasă mutată pe server, TOATE anunțurile —
        // comenzi, cereri, raportul de bani — pleacă în gol, fără nicio eroare
        // la vedere. Se scrie numai gazda, niciodată tokenul.
        $tgBaza = trim((string) ($cfg['telegram_api_base'] ?? ''));
        if ($tgBaza !== '') {
            check(
                'Adresa Telegramului',
                false,
                'mutată din config.php spre ' . ((string) (parse_url($tgBaza, PHP_URL_HOST) ?: 'o adresă care nu se poate citi')),
                'În api/config.php, telegram_api_base trebuie să fie goală pe server (ori să lipsească): '
                    . 'cu ea pusă, niciun mesaj nu ajunge la Telegramul adevărat. Cheia e numai pentru '
                    . 'probele de pe calculatorul de lucru.',
                false
            );
        }

        /* ------------------------------------------------------- poșta -- */
        // Pe ce drum pleacă e-mailul, și dacă drumul acela are cu ce. Căsuța se
        // citește din `settings` de-a dreptul (pagina asta nu încarcă aplicația).
        // Despre utilizator și parolă baza e întrebată numai DACĂ sunt puse:
        // valorile lor nu ajung nici măcar în memoria paginii, deci n-au cum
        // ajunge pe ecran. Se arată gazda și portul.
        $casuta = ['mail_smtp_host' => '', 'mail_smtp_port' => '', 'mail_smtp_user' => '', 'mail_smtp_password' => ''];
        if (in_array('settings', $tables, true)) {
            $randuri = $pdo->query(
                "SELECT setting_key,
                        IF(setting_key IN ('mail_smtp_user', 'mail_smtp_password'),
                           IF(TRIM(COALESCE(value, '')) = '', '', 'pus'), COALESCE(value, ''))
                   FROM settings WHERE setting_key IN
                    ('mail_smtp_host', 'mail_smtp_port', 'mail_smtp_user', 'mail_smtp_password')"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($randuri as $k => $v) {
                $casuta[$k] = trim((string) $v);
            }
        }
        $areGazda  = $casuta['mail_smtp_host'] !== '';
        $areOm     = $casuta['mail_smtp_user'] !== '';
        $areParola = $casuta['mail_smtp_password'] !== '';
        $pline     = (int) $areGazda + (int) $areOm + (int) $areParola;
        $lipsesc   = array_keys(array_filter([
            'gazda' => !$areGazda, 'utilizatorul' => !$areOm, 'parola' => !$areParola,
        ]));
        $postaSpusa = true;

        if ($areGazda) {
            // Gazda scrisă = e-mailul pleacă prin căsuță, și numai prin ea: o
            // căsuță care dă greș nu cade pe drumul direct.
            $portCasuta = $casuta['mail_smtp_port'] !== '' ? $casuta['mail_smtp_port'] : '465';
            check(
                'Drumul e-mailului',
                $pline === 3,
                'prin căsuța de e-mail (SMTP): ' . $casuta['mail_smtp_host'] . ', portul ' . $portCasuta
                    . ($pline === 3 ? '' : ' — completată pe jumătate'),
                'Căsuța din Setări → Notificări → E-mail e completată pe jumătate: '
                    . (count($lipsesc) === 1 ? 'lipsește ' : 'lipsesc ') . implode(' și ', $lipsesc)
                    . '. Până o completezi NU pleacă niciun e-mail: nici anunțurile, nici linkul de la '
                    . '„Ți-ai uitat parola?”. Ca e-mailul să plece direct de pe server, golește gazda.',
                false
            );
            check(
                'Criptarea pentru căsuța de e-mail (OpenSSL)',
                extension_loaded('openssl'),
                extension_loaded('openssl') ? 'se poate' : 'lipsește extensia',
                'Legătura cu căsuța se criptează, iar parola nu pleacă pe o legătură necriptată: fără '
                    . 'extensia OpenSSL nu pleacă niciun e-mail prin căsuță.',
                false
            );
            check(
                'Programul de poștă al serverului',
                true,
                $postaVorba[$postaDirect] . ' — nu e nevoie de el cât e-mailul pleacă prin căsuță'
            );
        } else {
            check(
                'Drumul e-mailului',
                $postaDirect === 'da' && $pline === 0,
                'direct de pe server' . ($pline === 0
                    ? ' (căsuța din Setări e goală)'
                    : ' — căsuța din Setări e completată pe jumătate: lipsește gazda'),
                $pline !== 0
                    ? 'În Setări → Notificări → E-mail e scris utilizatorul ori parola unei căsuțe, dar '
                        . 'nu și gazda ei. Fără gazdă e-mailul pleacă direct de pe server, nu prin căsuță. '
                        . 'Scrie gazda (o dă furnizorul căsuței), ori golește celelalte casete.'
                    : ($postaDirect === 'nu'
                        ? 'Serverul nu are niciun program de poștă, iar căsuța din Setări e goală: acum NU '
                            . 'pleacă niciun e-mail — nici anunțurile, nici linkul de la „Ți-ai uitat '
                            . 'parola?”. Completează căsuța (SMTP) în Setări → Notificări → E-mail, ori '
                            . 'rulează actualizeaza-vps.sh, care instalează și un program de poștă.'
                        : 'Căsuța din Setări e goală, deci e-mailul se predă programului de poștă al '
                            . 'serverului — iar de aici nu se poate ști dacă serverul are unul. Apasă '
                            . 'butonul de test din Setări → Notificări → E-mail.'),
                false
            );
            check(
                'Programul de poștă al serverului',
                $postaDirect === 'da',
                $postaVorba[$postaDirect],
                $postaDirect === 'nu'
                    ? 'Pe drumul direct, PHP predă mesajul unui program de poștă al serverului '
                        . '(sendmail, postfix). Aici nu e niciunul. Îl instalează actualizeaza-vps.sh; '
                        . 'ori completează căsuța (SMTP) din Setări → Notificări → E-mail și nu mai e nevoie de el.'
                    : 'PHP nu se poate uita după programul de poștă (open_basedir ori funcții oprite), '
                        . 'ori sistemul nu are unul de căutat. Asta nu înseamnă că lipsește: apasă butonul '
                        . 'de test din Setări → Notificări → E-mail.',
                false
            );
        }

        // Certificatul de probă: pentru o gazdă adevărată nu e ascultat
        // (`smtp_ca_de_proba()`), dar pe un server n-are ce căuta. Calea nu se
        // scrie pe ecran.
        if (trim((string) ($cfg['smtp_ca_file'] ?? '')) !== '') {
            check(
                'Certificatul de probă al căsuței de e-mail',
                false,
                'pus în config.php (smtp_ca_file)',
                'În api/config.php, smtp_ca_file trebuie să fie goală pe server (ori să lipsească). '
                    . 'Cheia e numai pentru probele de pe calculatorul de lucru.',
                false
            );
        }

        // Tot ce e mai sus spune dacă mesajul poate PLECA. Dacă și ajunge —
        // dacă furnizorul îl primește, dacă nu cade în Spam — nu se vede de pe
        // nicio pagină de verificare. Se spune pe față, ca un șir de rânduri
        // verzi să nu fie citit drept „e-mailul merge”.
        check(
            'Dacă e-mailul chiar ajunge',
            true,
            'Verificarea asta nu poate dovedi că un mesaj ajunge; folosește butonul de test.'
        );
    } catch (Throwable $e) {
        // Mesajul poate conține numele bazei, dar niciodată parola.
        // Nu îl escapăm aici: șablonul de mai jos escapează tot ce afișează.
        check('Conectarea la baza de date', false, 'eșuată',
            'Verifică host, nume bază, utilizator și parolă în api/config.php. '
                . 'Mesajul serverului: ' . $e->getMessage());
    }

    // Cheia e judecată cu regula pe care o aplică efectiv aplicația, luată din
    // crypto.php. Un prag scris a doua oară aici se desparte de cel adevărat:
    // cu „cel puțin 32 de caractere", verificarea dădea verde unei chei
    // alfanumerice de 64 de caractere — chiar cea pe care o produce pasul 4 din
    // manual — iar proprietarul afla abia la setup.php, cu contul de
    // administrator deja scris în baza de date, cu codul tabletelor rămas gol
    // și cu instalarea oprită la jumătate, după o pagină complet verde.
    $cryptoLib = __DIR__ . '/lib/crypto.php';
    $hasCrypto = is_file($cryptoLib);
    if ($hasCrypto) {
        require_once $cryptoLib;
    }

    $key   = (string) ($cfg['encryption_key'] ?? '');
    $keyOk = $hasCrypto && crypto_key_valid($key);
    if (!$hasCrypto) {
        $keyFound = 'nu s-a putut verifica';
    } elseif ($key === '') {
        $keyFound = 'necompletată';
    } else {
        $keyFound = strlen($key) . ' caractere' . ($keyOk ? ' hexazecimale' : ', nu 64 hexazecimale');
    }
    check(
        'Cheia de criptare',
        $keyOk,
        $keyFound,
        $hasCrypto
            ? 'Cheia trebuie să aibă exact 64 de caractere hexazecimale: cifre și literele de la a la '
                . 'f, nimic altceva. Un șir alfanumeric de 64 de caractere nu e bun. Generează una cu: '
                . 'php -r "echo bin2hex(random_bytes(32));" și pune-o în config.php.'
            : 'Lipsește api/lib/crypto.php, fișierul în care stă regula cheii, deci cheia nu poate fi '
                . 'verificată. Urcă din nou tot folderul api/.'
    );

    check('Modul de rulare', ($cfg['env'] ?? 'dev') === 'prod', (string) ($cfg['env'] ?? 'dev'),
        'Pe server pune env => \'prod\'. Pe \'dev\', erorile interne ajung în browser.', false);

    check('Cookie doar pe HTTPS', !empty($cfg['cookie_secure']), empty($cfg['cookie_secure']) ? 'oprit' : 'pornit',
        'Pe server pune cookie_secure => true, altfel sesiunea poate circula necriptat.', false);
}

/* ---------------------------------------- poșta, când baza n-a răspuns -- */

// Rândurile poștei se scriu lângă baza de date, fiindcă drumul e-mailului stă în
// `settings`. Dacă pagina n-a ajuns până acolo (lipsește config.php, baza nu
// răspunde), nu se știe pe ce drum pleacă e-mailul — dar ce se poate ști fără
// bază se spune totuși, ca poșta să nu lipsească tocmai de pe pagina cea mai roșie.
if (!$postaSpusa) {
    check(
        'Programul de poștă al serverului',
        $postaDirect === 'da',
        $postaVorba[$postaDirect],
        $postaDirect === 'nu'
            ? 'Fără un program de poștă pe server, e-mailul poate pleca numai prin căsuța (SMTP) din '
                . 'Setări → Notificări → E-mail. Dacă e completată nu se poate vedea până nu răspunde baza de date.'
            : 'PHP nu se poate uita după programul de poștă. Asta nu înseamnă că lipsește; după ce '
                . 'răspunde baza de date, apasă butonul de test din Setări → Notificări → E-mail.',
        false
    );
    check(
        'Dacă e-mailul chiar ajunge',
        true,
        'Verificarea asta nu poate dovedi că un mesaj ajunge; folosește butonul de test.'
    );
}

/* ------------------------------------------------------------- HTTPS -- */

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
// Certificatul e gratuit și se ia cu o comandă, dar comanda are două forme, iar
// cea greșită nu strică nimic — doar nu găsește configurarea site-ului și iese
// cu mâna goală. De vreme ce știm deja ce server web e (`$eNginx`, de mai sus),
// se scrie numai forma lui.
$certPachet = $eNginx ? 'python3-certbot-nginx' : 'python3-certbot-apache';
$certCum    = $eNginx ? 'certbot --nginx' : 'certbot --apache';
check('Conexiune HTTPS', $https, $https ? 'da' : 'nu',
    'Fără HTTPS, parolele circulă necriptat. Pe VPS certificatul e gratuit: '
        . 'sudo apt install -y certbot ' . $certPachet . ', apoi sudo ' . $certCum
        . '. Reînnoirea se face singură.', false);

/* --------------------------------------------- zăvorul de după instalare -- */

// Verificarea are rost cât timp instalarea nu e gata. Pe un server unde
// aplicația funcționează deja — adică are cel puțin un administrator activ —
// pagina asta nu-i mai spune omului nimic util, dar continuă să spună oricui o
// deschide ce versiune de PHP și de MySQL rulezi, ce extensii ai, cum se numesc
// tabelele aplicației, dacă erorile interne pleacă spre browser și dacă sesiunea
// circulă doar pe HTTPS. Documentația cere ștergerea fișierului după pasul 5;
// dacă omul a uitat, de aici încolo serverul răspunde ca și cum fișierul n-ar
// fi acolo.
//
// Când baza nu răspunde sau tabela users încă nu e importată, instalarea nu e
// terminată și pagina rămâne deschisă: exact situația pentru care există.
// Până aici nu s-a tipărit nimic, doar s-au adunat verificările, deci ieșirea
// nu lasă o pagină pe jumătate.
$instalat = null;
if (isset($pdo)) {
    try {
        // Amândouă rolurile cu drepturi depline. Vezi api/setup.php: numărat doar
        // pe „admin", zăvorul se descuia pe o instalare rămasă cu un fondator.
        $instalat = (int) $pdo->query(
            "SELECT COUNT(*) FROM users WHERE role IN ('admin', 'fondator') AND active = 1"
        )->fetchColumn();
        if ($instalat > 0) {
            http_response_code(404);
            exit;
        }
    } catch (Throwable $e) {
        // Fără tabela users nu există instalare terminată, deci nu zăvorâm.
    }
}

// Zăvorul de mai sus ține doar cât timp baza răspunde. La o pană de MySQL sau
// la o parolă schimbată, `$instalat` rămâne necunoscut și pagina se deschidea
// oricui, cu tot ce are pe ea: versiuni de PHP și MySQL, extensii, modul
// `env`, textul erorii PDO (care conține utilizatorul și gazda bazei). Când nu
// se poate dovedi că instalarea NU e terminată, pagina cere codul de instalare
// — același cu al lui setup.php, pe care îl are doar cine a scris config.php.
// Pe o găzduire încă fără config.php nu e nimic de protejat și codul nu există.
if ($instalat === null && $hasConfig) {
    $cod = (string) (($cfg ?? [])['install_code'] ?? '');
    $dat = (string) ($_GET['cod'] ?? $_POST['cod'] ?? '');
    if ($cod === '' || strlen($cod) < 12 || !hash_equals($cod, $dat)) {
        http_response_code(404);
        exit;
    }
}

$total = count($checks);
$okCount = $total - $fatal - $warn;
?>
<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Golden Pastry — verificarea serverului</title>
<style>
  body{margin:0;padding:28px 18px;background:#F7F3EC;color:#2A1E14;
       font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
  .wrap{max-width:820px;margin:0 auto}
  h1{font-size:26px;margin:0 0 4px}
  .sub{color:#7A6A5B;margin:0 0 22px}
  .verdict{padding:14px 18px;border-radius:14px;margin:0 0 22px;font-weight:600}
  .v-ok{background:#E4F2E4;color:#1F5C2B;border:1px solid #B9DCBC}
  .v-warn{background:#FDF1DC;color:#7A5410;border:1px solid #EBCF95}
  .v-bad{background:#FBE3E1;color:#8A2018;border:1px solid #EFB6B0}
  table{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;
        overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.06)}
  th,td{text-align:left;padding:11px 14px;border-bottom:1px solid #EFE7DC;vertical-align:top}
  th{background:#F2EADF;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#7A6A5B}
  tr:last-child td{border-bottom:0}
  .st{white-space:nowrap;font-weight:700}
  .ok{color:#2E7D39}.bad{color:#C0392B}.wr{color:#B7791F}
  .why{display:block;margin-top:4px;font-size:14px;color:#7A6A5B}
  .found{font-family:ui-monospace,Consolas,monospace;font-size:14px}
  .note{margin-top:22px;padding:14px 18px;background:#FDF1DC;border:1px solid #EBCF95;
        border-radius:14px;font-size:15px;color:#7A5410}
</style>
</head>
<body>
<div class="wrap">
  <h1>Golden Pastry — verificarea serverului</h1>
  <p class="sub"><?= $okCount ?> din <?= $total ?> verificări trecute.</p>

  <?php if ($fatal): ?>
    <p class="verdict v-bad"><?= $fatal ?> <?= $fatal === 1 ? 'problemă blochează' : 'probleme blochează' ?>
      pornirea aplicației. Vezi rândurile roșii.</p>
  <?php elseif ($warn): ?>
    <p class="verdict v-warn">Aplicația poate porni. <?= $warn ?>
      <?= $warn === 1 ? 'lucru merită rezolvat' : 'lucruri merită rezolvate' ?> înainte de publicare.</p>
  <?php else: ?>
    <p class="verdict v-ok">Serverul are tot ce trebuie.</p>
  <?php endif; ?>

  <table>
    <tr><th>Verificare</th><th>Găsit</th><th>Stare</th></tr>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td>
          <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>
          <?php if (!$c['ok'] && $c['why'] !== ''): ?>
            <span class="why"><?= htmlspecialchars($c['why'], ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </td>
        <td class="found"><?= htmlspecialchars($c['found'], ENT_QUOTES, 'UTF-8') ?></td>
        <td class="st <?= $c['ok'] ? 'ok' : ($c['blocking'] ? 'bad' : 'wr') ?>">
          <?= $c['ok'] ? '✓ bine' : ($c['blocking'] ? '✕ blochează' : '! de rezolvat') ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <p class="note"><strong>Șterge acest fișier</strong> după ce l-ai folosit.
    Nu afișează parole, dar spune oricui trece pe aici ce versiuni rulezi.</p>
</div>
</body>
</html>
