<?php
/**
 * Ziua instalării: poate omul intra în propria aplicație?
 *
 *     php .claude/test-defect-instalare.php
 *
 * Trei lucruri se probează aici, toate pe drumul adevărat — pagina de
 * instalare rulată ca proces separat, autentificarea prin `do_login()`,
 * pagina de verificare rulată pe o bază adevărată:
 *
 * 1. Parola primului administrator. Instalarea hașura parola NETĂIATĂ, în
 *    timp ce autentificarea o trece prin `need()`, care taie spațiile. Un
 *    spațiu lipit de managerul de parole dădea un cont de administrator care
 *    nu se mai putea deschide NICIODATĂ — iar `api/setup.php` răspunde 404 din
 *    clipa în care contul există, deci nu mai există nicio cale înapoi.
 *
 * 2. `must_change_password`. Steagul era pus de administrator și citit doar
 *    pentru afișare: nicio rută nu-l consulta, deci parola dictată la telefon
 *    deschidea aplicația la nesfârșit.
 *
 * 3. Lista de tabele din pagina de verificare. Dacă e mai scurtă decât schema,
 *    pagina dă verde peste un tabel care lipsește — exact pagina care există
 *    ca să prindă asta.
 *
 * Ca și `test-instalare.php`, lucrează pe o bază proprie,
 * `gp_defect_instalare_proba`, pe care o creează și o șterge singură. Nu
 * atinge niciodată baza din api/config.php.
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$failures = [];

function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  ok    $name\n";
    } else {
        $fail++;
        $failures[] = $name . ($extra !== '' ? " — $extra" : '');
        echo "  FAIL  $name" . ($extra !== '' ? " — $extra" : '') . "\n";
    }
}

function opreste(string $de_ce): void
{
    echo "  FAIL  $de_ce\n\n";
    exit(1);
}

/** Text de o linie, pentru mesajul de lângă o probă picată. */
function scurt(string $s, int $n = 200): string
{
    return substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($s))), 0, $n);
}

$ROOT   = dirname(__DIR__);
$DBNAME = 'gp_defect_instalare_proba';

$GP_CONFIG = require $ROOT . '/api/config.php';

$cfg = $GP_CONFIG['db'];

// Aceeași plasă ca în test-instalare.php: dacă cineva ar pune numele bazei de
// probă în config.php, DROP-ul de mai jos i-ar șterge datele.
if ($cfg['name'] === $DBNAME) {
    opreste("api/config.php arată chiar spre „$DBNAME”; proba ar șterge baza folosită.");
}

$dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], (int) $cfg['port'], $cfg['charset'] ?? 'utf8mb4');
try {
    $srv = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    opreste('nu pot deschide MySQL: ' . $e->getMessage());
}

/** @var PDO|null $db legătura pe baza de probă, refăcută la fiecare resetare */
$db = null;

/** Bază proaspătă, doar din sql/001 și sql/002. */
$reset = static function () use ($srv, $ROOT, $DBNAME, &$db): void {
    $srv->exec("DROP DATABASE IF EXISTS `$DBNAME`");
    $srv->exec("CREATE DATABASE `$DBNAME` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Legătură nouă, cu baza în DSN. Un „USE" pe legătura de server se pierdea
    // când altcineva lucra în paralel pe același MySQL, iar importul pica cu
    // „Unknown database".
    global $cfg;
    $db = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], (int) $cfg['port'], $DBNAME, $cfg['charset'] ?? 'utf8mb4'),
        $cfg['user'],
        $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    // Fișierele se dau prin PDO, nu prin clientul `mysql`: pe Windows acela
    // citește fișierul în codepage-ul consolei și strică diacriticele.
    foreach (['sql/001_schema.sql', 'sql/002_seed.sql'] as $f) {
        $db->exec(file_get_contents($ROOT . '/' . $f));
    }
};

/* --------------------------------------------- configurarea de probă -- */

$COD = 'cod-de-proba-defecte-77b1';

$tmpCfg = $GP_CONFIG;
$tmpCfg['db']['name']   = $DBNAME;
$tmpCfg['install_code'] = $COD;
$configProba = tempnam(sys_get_temp_dir(), 'gpcfg') . '.php';
file_put_contents($configProba, '<?php return ' . var_export($tmpCfg, true) . ';');

$temporare = [$configProba];

/* ---------------------------------------------- rularea instalatorului -- */

// Formularul de instalare, trimis ca și cum l-ar fi apăsat omul. Câmpurile vin
// din mediu, ca aceeași bucată de cod să poată proba și parola cu spațiu, și pe
// cea prea scurtă.
$bootSetup = tempnam(sys_get_temp_dir(), 'gpsetup') . '.php';
file_put_contents($bootSetup, <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD']  = 'POST';
$_SERVER['HTTP_USER_AGENT'] = 'proba-defecte-instalare';
$_POST = [
    'install_code' => (string) getenv('GP_COD_INSTALARE'),
    'username'     => (string) getenv('GP_USER'),
    'name'         => 'Proba Defecte',
    'email'        => '',
    'password'     => (string) getenv('GP_PASS'),
    'password2'    => (string) getenv('GP_PASS'),
];
require getenv('GP_SETUP');
PHP);
$temporare[] = $bootSetup;

/**
 * Autentificare și verificare de drept, exact prin funcțiile serverului.
 *
 * Rulează în proces separat, nu în cel de față: `current_user()` ține
 * rezultatul într-o statică, deci autentificarea și cererea de după ea trebuie
 * să fie două cereri, ca în realitate.
 */
$bootAuth = tempnam(sys_get_temp_dir(), 'gpauth') . '.php';
file_put_contents($bootAuth, <<<'PHP'
<?php
$GP_CONFIG = require getenv('GP_CONFIG');
function config(string $key, $default = null)
{
    global $GP_CONFIG;
    return $GP_CONFIG[$key] ?? $default;
}
$R = getenv('GP_ROOT');
require $R . '/api/lib/http.php';
require $R . '/api/lib/crypto.php';
require $R . '/api/lib/db.php';
require $R . '/api/lib/auth.php';

$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'proba-defecte-instalare';

// Sesiune deschisă direct, pentru conturile care nu intră cu parolă — contul
// „panou" al tabletelor se deschide cu codul comun, nu prin do_login().
if (getenv('GP_MOD') === 'sesiune') {
    $u = one('SELECT id, role FROM users WHERE username = ?', [(string) getenv('GP_USER')]);
    $token = random_token();
    q(
        'INSERT INTO sessions (id, user_id, csrf_token, expires_at, ip, user_agent)
         VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY, ?, ?)',
        [token_hash($token), $u['id'], random_token(), session_days_for((string) $u['role']), '127.0.0.1', 'proba']
    );
    echo "LOGIN-OK $token\n";
    exit;
}

if (getenv('GP_MOD') === 'login') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI']    = '/auth/login';
    // Exact ce face route_login: ambele câmpuri trec prin need(), care TAIE.
    $u = need(['username' => (string) getenv('GP_USER')], 'username');
    $p = need(['password' => (string) getenv('GP_PASS')], 'password');
    [$token] = do_login($u, $p);
    echo "LOGIN-OK $token\n";
    exit;
}

// Cerere obișnuită, cu cookie-ul de sesiune, pe ruta cerută.
$_SERVER['REQUEST_METHOD'] = (string) (getenv('GP_METHOD') ?: 'GET');
$_SERVER['REQUEST_URI']    = (string) getenv('GP_URI');
$_COOKIE[GP_COOKIE]        = (string) getenv('GP_TOKEN');
$u = require_auth();
echo 'AJUNS ' . $u['username'] . "\n";
PHP);
$temporare[] = $bootAuth;

/** Rulează un bootstrap PHP într-un proces separat și întoarce ce a tipărit. */
$ruleaza = static function (string $boot, array $env) use ($ROOT, $configProba): string {
    $env = array_merge(getenv(), $env, [
        'GP_CONFIG' => $configProba,
        'GP_ROOT'   => $ROOT,
        'GP_SETUP'  => $ROOT . '/api/setup.php',
    ]);
    $p = proc_open([PHP_BINARY, $boot], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $ROOT, $env);
    if (!is_resource($p)) {
        return '';
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return $out;
};

$instaleaza = static fn(string $user, string $parola): string
    => $ruleaza($bootSetup, ['GP_COD_INSTALARE' => $COD, 'GP_USER' => $user, 'GP_PASS' => $parola]);

$autentifica = static fn(string $user, string $parola): string
    => $ruleaza($bootAuth, ['GP_MOD' => 'login', 'GP_USER' => $user, 'GP_PASS' => $parola]);

$cere = static fn(string $token, string $uri, string $metoda = 'GET'): string
    => $ruleaza($bootAuth, ['GP_MOD' => 'cerere', 'GP_TOKEN' => $token, 'GP_URI' => $uri, 'GP_METHOD' => $metoda]);

/* ============================================================ verifica.php */

echo "\n=== api/verifica.php numără toate tabelele schemei ===\n";

// Pagina citește `__DIR__/config.php`, deci nu poate fi îndreptată spre baza de
// probă prin mediu. O copiem într-un folder temporar, cu configurarea de probă
// lângă ea — fișierul din proiect rămâne neatins.
$dirVer = sys_get_temp_dir() . '/gp-verifica-' . bin2hex(random_bytes(4));
mkdir($dirVer, 0777, true);
copy($ROOT . '/api/verifica.php', $dirVer . '/verifica.php');
copy($configProba, $dirVer . '/config.php');

$vedeVerifica = static function () use ($ruleaza, $dirVer): string {
    return $ruleaza($dirVer . '/verifica.php', []);
};

$reset();

// Câte tabele creează schema, numărate în chiar fișierul importat mai sus.
preg_match_all('/^\s*CREATE TABLE IF NOT EXISTS\s+(\w+)/mi', file_get_contents($ROOT . '/sql/001_schema.sql'), $mt);
$tabeleSchema = array_unique($mt[1]);
$nSchema = count($tabeleSchema);

$out = $vedeVerifica();
check('pagina de verificare se deschide pe o instalare neterminată',
    str_contains($out, 'Tabelele aplicației'), scurt($out));

// Numărul tipărit trebuie să fie numărul real de tabele din sql/001_schema.sql.
// Cu lista scurtată, pagina scria „14 din 14 prezente" pe o schemă de 15.
preg_match('/(\d+) din (\d+) prezente/u', $out, $mn);
check("pagina numără toate cele $nSchema tabele din sql/001_schema.sql, nu mai puține",
    (int) ($mn[2] ?? 0) === $nSchema,
    'pagina spune „' . ($mn[0] ?? '(niciun număr)') . '”, schema are ' . $nSchema . ': ' . implode(', ', $tabeleSchema));

// Și proba care contează cu adevărat: un tabel lipsă trebuie să fie văzut.
// `product_categories` e cel care lipsea din listă; fără el, prima deschidere a
// paginii Catalog cade cu eroare de bază de date, după ce pagina de verificare
// a dat verde.
$db->exec('DROP TABLE IF EXISTS product_categories');
$outLipsa = $vedeVerifica();
check('un tabel lipsă din schemă este raportat ca lipsă, nu bifat verde',
    str_contains($outLipsa, 'lipsesc: ') && str_contains($outLipsa, 'product_categories'),
    scurt($outLipsa, 400));

/* ================================================== parola la instalare */

echo "\n=== parola primului administrator, cu spațiu lipit de managerul de parole ===\n";

$reset();

// Ce ajunge din formular când parola e lipită dintr-un manager de parole sau
// scrisă pe telefon: un spațiu la capăt. Câmpul <input type="password"> nu-l
// taie, deci pleacă spre server așa cum e.
$PAROLA_TASTATA = 'Xk7#pLm2qR ';
$PAROLA_CURATA  = 'Xk7#pLm2qR';

$out = $instaleaza('instalator', $PAROLA_TASTATA);
check('instalarea cu o parolă care are spațiu la capăt reușește',
    str_contains($out, 'Contul a fost creat'), scurt($out));

$hash = (string) ($db->query("SELECT password_hash FROM users WHERE role = 'admin'")->fetch()['password_hash'] ?? '');
check('hash-ul salvat e al parolei tăiate, cum o trimite autentificarea',
    $hash !== '' && password_verify($PAROLA_CURATA, $hash),
    'password_verify pe parola tăiată a întors false');

// Proba adevărată: drumul complet, prin need() și do_login(), ca la prima
// intrare pe lab.goldenpastry.ro.
$log = $autentifica('instalator', $PAROLA_TASTATA);
check('omul intră în cont cu parola exact așa cum a scris-o la instalare',
    str_contains($log, 'LOGIN-OK'), scurt($log));

$log = $autentifica('instalator', $PAROLA_CURATA);
check('intră și cu parola fără spațiul de la capăt',
    str_contains($log, 'LOGIN-OK'), scurt($log));

// Utilizatorul: instalarea îl scrie cu litere mici, autentificarea nu-l
// coboară. Colația bazei e insensibilă la majuscule, deci trebuie să meargă și
// scris cu majusculă — dacă asta se schimbă vreodată, proba o prinde.
$log = $autentifica('Instalator', $PAROLA_CURATA);
check('utilizatorul scris cu majusculă deschide același cont',
    str_contains($log, 'LOGIN-OK'), scurt($log));

echo "\n=== parola prea scurtă nu se poate strecura pe spații ===\n";

$reset();

// Opt caractere plus două spații treceau de „minim 10": lungimea se măsura pe
// valoarea netăiată, iar contul rămânea cu o parolă de opt caractere.
$out = $instaleaza('instalator', 'Scurta12  ');
check('parola de 8 caractere plus spații este refuzată',
    str_contains($out, 'Parola trebuie să aibă cel puțin 10 caractere'), scurt($out));
check('încercarea refuzată nu lasă niciun administrator în bază',
    (int) ($db->query("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")->fetch()['n'] ?? -1) === 0);

/* ============================================== must_change_password */

echo "\n=== parola provizorie chiar oprește accesul, pe server ===\n";

$reset();

$out = $instaleaza('instalator', 'Parola-Instalare-1234');
check('instalarea de control reușește', str_contains($out, 'Contul a fost creat'), scurt($out));

// Cont făcut de administrator din Conturi: parola e dictată, deci
// must_change_password = 1 (api/routes/users.php).
$db->prepare(
    "INSERT INTO users (username, password_hash, name, role, active, must_change_password)
     VALUES ('client.nou', ?, 'Client Nou', 'client', 1, 1)"
)->execute([password_hash('Parola-Dictata-77', PASSWORD_DEFAULT)]);

$log = $autentifica('client.nou', 'Parola-Dictata-77');
preg_match('/LOGIN-OK (\S+)/', $log, $mt2);
$tokenProvizoriu = $mt2[1] ?? '';
check('contul cu parolă provizorie se poate autentifica', $tokenProvizoriu !== '', scurt($log));

if ($tokenProvizoriu !== '') {
    // Miezul: cu steagul pus, serverul nu mai servește nicio altă rută. Până la
    // reparație, singura piedică era o redirecționare din browser, pe care un
    // clic pe „← Înapoi la magazin" o ocolea.
    $r = $cere($tokenProvizoriu, '/orders');
    check('cu parolă provizorie, o rută obișnuită este refuzată de server',
        str_contains($r, 'must_change_password') && !str_contains($r, 'AJUNS'),
        scurt($r));

    $r = $cere($tokenProvizoriu, '/requests', 'POST');
    check('refuzul e la fel și pe o rută care scrie date',
        str_contains($r, 'must_change_password') && !str_contains($r, 'AJUNS'),
        scurt($r));

    // Poarta trebuie să rămână deschisă exact cât să se poată schimba parola.
    $r = $cere($tokenProvizoriu, '/auth/password', 'POST');
    check('schimbarea parolei rămâne permisă, altfel contul ar fi blocat definitiv',
        str_contains($r, 'AJUNS'), scurt($r));

    // Și după schimbare totul se deschide, fără reautentificare.
    $db->exec("UPDATE users SET must_change_password = 0 WHERE username = 'client.nou'");
    $r = $cere($tokenProvizoriu, '/orders');
    check('după ce steagul cade, rutele obișnuite se deschid din nou',
        str_contains($r, 'AJUNS'), scurt($r));
}

// Contul „panou" al tabletelor nu are parolă proprie, deci n-ar putea niciodată
// să iasă din starea de „parolă provizorie": /auth/password cere parola curentă,
// pe care n-o știe nimeni. Dacă steagul ajunge cumva 1 pe el, laboratorul nu are
// voie să rămână fără panouri.
$db->exec("UPDATE users SET must_change_password = 1 WHERE username = 'panou'");
$sesPanou = $ruleaza($bootAuth, ['GP_MOD' => 'sesiune', 'GP_USER' => 'panou']);
preg_match('/LOGIN-OK (\S+)/', $sesPanou, $mp);
if (($mp[1] ?? '') !== '') {
    $r = $cere($mp[1], '/orders');
    check('contul de sistem al tabletelor nu poate fi închis de steagul de parolă provizorie',
        str_contains($r, 'AJUNS'), scurt($r));
} else {
    check('sesiune pentru contul de panou', false, scurt($sesPanou));
}

// Contul de administrator făcut la instalare nu are steagul pus: el nu trebuie
// să simtă nimic din toate astea.
$log = $autentifica('instalator', 'Parola-Instalare-1234');
preg_match('/LOGIN-OK (\S+)/', $log, $mt3);
$tokenAdmin = $mt3[1] ?? '';
check('administratorul creat la instalare se autentifică', $tokenAdmin !== '', scurt($log));
if ($tokenAdmin !== '') {
    $r = $cere($tokenAdmin, '/orders');
    check('administratorul de la instalare nu e oprit de nimic',
        str_contains($r, 'AJUNS'), scurt($r));
}

/* ------------------------------------------------------------ curat -- */

$db = null;
$srv->exec("DROP DATABASE IF EXISTS `$DBNAME`");
foreach ($temporare as $f) {
    @unlink($f);
}
@unlink($dirVer . '/verifica.php');
@unlink($dirVer . '/config.php');
@rmdir($dirVer);

echo "\n" . str_repeat('=', 52) . "\n";
echo "$pass trecute · $fail eșuate\n";
if ($failures) {
    echo "\nEȘUATE:\n";
    foreach ($failures as $f) {
        echo "  x $f\n";
    }
}
echo "\n";
exit($fail ? 1 : 0);
