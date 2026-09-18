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
        . 'Schimb-o din cPanel → Select PHP Version.'
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
        'PHP ' . $branch . ' nu mai primește corecții de securitate. Găzduirea are '
            . 'versiuni mai noi instalate — treci pe cea mai nouă din cPanel → '
            . 'Select PHP Version. Aplicația funcționează pe oricare de la 8.0 în sus.',
        false
    );
}

check('Arhitectură pe 64 de biți', PHP_INT_SIZE >= 8, PHP_INT_SIZE * 8 . ' biți',
    'Pe 32 de biți, datele de după 2038 se calculează greșit.', false);

/* ----------------------------------------------------------- extensii -- */

$ext = [
    'pdo_mysql' => ['Legătura cu baza de date. Fără ea aplicația nu pornește deloc.', true],
    'openssl'   => ['Criptează tokenurile din Setări (WhatsApp, Telegram) și codul tabletelor.', true],
    'mbstring'  => ['Taie corect textele cu diacritice în mesajele de WhatsApp.', true],
    'curl'      => ['Trimite mesajele către WhatsApp și Telegram.', false],
    'json'      => ['Formatul în care vorbesc paginile cu serverul.', true],
    'filter'    => ['Validează adresele de e-mail.', true],
];
foreach ($ext as $name => [$why, $blocking]) {
    $has = extension_loaded($name);
    check(
        'Extensia ' . $name,
        $has,
        $has ? 'prezentă' : 'LIPSEȘTE',
        $why . ($name === 'curl'
            ? ' Fără ea se folosesc stream-urile PHP, dar numai dacă allow_url_fopen este pornit.'
            : ' Activeaz-o din cPanel → Select PHP Version → Extensions.'),
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

check('Funcția mail()', function_exists('mail'), function_exists('mail') ? 'disponibilă' : 'dezactivată',
    'Notificările pe e-mail nu vor pleca. WhatsApp și Telegram nu sunt afectate.', false);

/* ------------------------------------------------------------ fișiere -- */

$configPath = __DIR__ . '/config.php';
$hasConfig  = is_file($configPath);
check('Fișierul api/config.php', $hasConfig, $hasConfig ? 'există' : 'lipsește',
    'Copiază config.example.php ca config.php și completează datele bazei de date.');

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
        // folderul acela nu ajunge lângă `api/`: `actualizeaza-vps.sh:684-687`
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

        // Coloanele adăugate ulterior, prin migrarea 003.
        if (in_array('orders', $tables, true)) {
            $cols = $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_COLUMN);
            foreach (['route_seq' => 'ordinea opririlor pe ruta șoferului',
                      'stock_synced' => 'marcajul de scădere din depozit'] as $col => $what) {
                check(
                    'Coloana orders.' . $col,
                    in_array($col, $cols, true),
                    in_array($col, $cols, true) ? 'prezentă' : 'lipsește',
                    'Ține ' . $what . '. Importă sql/003_route_order.sql din phpMyAdmin.',
                    false
                );
            }
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

/* ------------------------------------------------------------- HTTPS -- */

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
check('Conexiune HTTPS', $https, $https ? 'da' : 'nu',
    'Activează SSL din cPanel (Let\'s Encrypt e gratuit). Fără HTTPS, parolele circulă necriptat.', false);

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
