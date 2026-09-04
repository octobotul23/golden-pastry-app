<?php
/**
 * Instalarea de la zero, pe o bază goală.
 *
 *     php .claude/test-instalare.php
 *
 * Ăsta e singurul drum pe care utilizatorul îl parcurge o dată și nu-l mai
 * poate relua: importă `sql/001_schema.sql` și `sql/002_seed.sql` în
 * phpMyAdmin, deschide `api/setup.php`, își face contul de administrator și
 * șterge fișierul. Dacă ceva lipsește acolo, se vede abia pe serverul adevărat.
 *
 * Două lucruri se verifică aici și nicăieri altundeva:
 *
 * 1. Codul tabletelor. Era literalul „lab123", scris de instalator și tipărit
 *    în două pagini publicate ca fișiere statice pe lab.goldenpastry.ro. Acum
 *    se generează la instalare și se arată o singură dată; proba citește chiar
 *    pagina instalatorului și verifică apoi, în baza de date, că valoarea
 *    criptată se decriptează exact în codul afișat.
 *
 * 2. Schema. Baza proaspătă trebuie să aibă TOT ce scrie codul, fără migrări:
 *    coloanele adăugate de 003 și 004 (varianta pe fiecare poziție, adresa de
 *    255, `notifications.seen_at`) au fost mutate în 001, altfel prima comandă
 *    de pe site pica cu „Eroare internă" pe o instalare nouă.
 *
 * Lucrează pe o bază proprie, `gp_instalare_proba`, pe care o creează și o
 * șterge singură. Nu atinge niciodată baza din api/config.php.
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

$ROOT   = dirname(__DIR__);
$DBNAME = 'gp_instalare_proba';

$GP_CONFIG = require $ROOT . '/api/config.php';
function config(string $key, $default = null)
{
    global $GP_CONFIG;
    return $GP_CONFIG[$key] ?? $default;
}

require $ROOT . '/api/lib/http.php';
require $ROOT . '/api/lib/crypto.php';

$cfg = $GP_CONFIG['db'];

// Plasă de siguranță. Numele bazei de probă e altul decât orice bază reală sau
// de dezvoltare, dar dacă cineva l-ar pune totuși în config.php, DROP-ul de mai
// jos i-ar șterge datele.
if ($cfg['name'] === $DBNAME) {
    opreste("api/config.php arată chiar spre „$DBNAME”; proba ar șterge baza folosită.");
}

/* ------------------------------------------------ baza proaspătă -- */

echo "\n=== baza proaspătă, doar din sql/001 și sql/002 ===\n";

$dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], (int) $cfg['port'], $cfg['charset'] ?? 'utf8mb4');
try {
    $srv = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    opreste('nu pot deschide MySQL: ' . $e->getMessage());
}

$srv->exec("DROP DATABASE IF EXISTS `$DBNAME`");
$srv->exec("CREATE DATABASE `$DBNAME` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$srv->exec("USE `$DBNAME`");

// Fișierele se dau prin PDO, nu prin clientul `mysql` din linia de comandă:
// pe Windows acela citește fișierul în codepage-ul consolei, iar „Predată"
// ajunge „Predat─â" — exact avertismentul din capul migrării 003.
foreach (['sql/001_schema.sql', 'sql/002_seed.sql'] as $f) {
    try {
        $srv->exec(file_get_contents($ROOT . '/' . $f));
        check("$f se importă fără erori", true);
    } catch (PDOException $e) {
        check("$f se importă fără erori", false, $e->getMessage());
    }
}

$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], (int) $cfg['port'], $DBNAME, $cfg['charset'] ?? 'utf8mb4'),
    $cfg['user'],
    $cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$coloana = static function (string $tabel, string $col) use ($db, $DBNAME): ?array {
    $st = $db->prepare(
        'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH AS len, COLUMN_DEFAULT AS dflt
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$DBNAME, $tabel, $col]);
    return $st->fetch() ?: null;
};

// Ce adăugau migrările 003 și 004. Pe o instalare nouă nimeni nu le rulează —
// documentația spune explicit că 003 „nu-ți trebuie la prima instalare" — deci
// trebuie să fie deja în schemă.
echo "\n=== schema are tot ce scrie codul, fără migrări ===\n";

check('order_items.variant există (varianta pe fiecare poziție)',
    $coloana('order_items', 'variant') !== null);
check('order_request_items.variant există',
    $coloana('order_request_items', 'variant') !== null);
check('notifications.seen_at există (anunțurile eșuate se pot marca văzute)',
    $coloana('notifications', 'seen_at') !== null);

$adr = $coloana('users', 'address');
check('users.address are 255, ca adresa din cerere și din comandă',
    $adr !== null && (int) $adr['len'] === 255, 'are ' . ($adr['len'] ?? 'nimic'));

foreach ([['orders', 'route_seq'], ['orders', 'stock_synced'], ['orders', 'variant'],
          ['order_requests', 'variant'], ['products', 'price_frozen'], ['products', 'price_baked']] as [$t, $c]) {
    check("$t.$c există", $coloana($t, $c) !== null);
}

/* ------------------------------------------------ rularea instalării -- */

echo "\n=== api/setup.php pe baza goală ===\n";

// Codul de instalare: în producție îl scrie omul în api/config.php la pasul 4
// al publicării, iar fără el setup.php nu creează niciun cont. Proba își pune
// unul al ei, ca să poată verifica și drumul corect, și pe cel refuzat.
$COD = 'cod-de-proba-9f3c1a';

$tmpCfg = $GP_CONFIG;
$tmpCfg['db']['name'] = $DBNAME;
$tmpCfg['install_code'] = $COD;
$configProba = tempnam(sys_get_temp_dir(), 'gpcfg') . '.php';
file_put_contents($configProba, '<?php return ' . var_export($tmpCfg, true) . ';');

$bootstrap = tempnam(sys_get_temp_dir(), 'gpsetup') . '.php';
file_put_contents($bootstrap, <<<'PHP'
<?php
// Rulează pagina de instalare ca și cum ar fi fost trimis formularul.
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_USER_AGENT'] = 'proba-instalare';
$_POST = [
    // Codul vine din mediu, ca proba să poată rula și cu unul greșit.
    'install_code' => (string) getenv('GP_COD_INSTALARE'),
    'username'  => 'instalator',
    'name'      => 'Proba Instalare',
    'email'     => '',
    'password'  => 'Parola-Instalare-1234',
    'password2' => 'Parola-Instalare-1234',
];
require getenv('GP_SETUP');
PHP);

/** Rulează instalatorul într-un proces separat și întoarce ce a tipărit. */
$ruleaza = static function (?string $cod = null) use ($bootstrap, $configProba, $ROOT, $COD): string {
    $env = getenv();
    $env['GP_CONFIG'] = $configProba;
    $env['GP_SETUP']  = $ROOT . '/api/setup.php';
    $env['GP_COD_INSTALARE'] = $cod ?? $COD;

    $p = proc_open(
        [PHP_BINARY, $bootstrap],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $ROOT,
        $env
    );
    if (!is_resource($p)) {
        return '';
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return $out;
};

// Codul de instalare. Se probează ÎNAINTE de instalarea reușită: după ea
// pagina răspunde 404 și n-ar mai dovedi nimic. Fereastra apărată aici e cea
// dintre pasul 4 și pasul 6 din docs/03-publicare-cpanel.md — ore în care
// fișierul stă pe un domeniu public deja anunțat de certificat, iar cine ajunge
// primul la el ar deveni administratorul patiseriei.
$fara = $ruleaza('');
check('fără codul de instalare nu se creează niciun cont',
    !str_contains($fara, 'Contul a fost creat'),
    substr(preg_replace('/s+/', ' ', strip_tags($fara)), 0, 160));

$gresit = $ruleaza('cod-gresit-dar-lung-12345');
check('cu alt cod decât cel din config.php instalarea e refuzată',
    str_contains($gresit, 'Codul de instalare nu este cel din api/config.php'),
    substr(preg_replace('/s+/', ' ', strip_tags($gresit)), 0, 160));

check('nicio încercare respinsă nu lasă un administrator în bază',
    (int) ($db->query("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")->fetch()['n'] ?? -1) === 0);

$out = $ruleaza();

check('instalarea se încheie cu succes', str_contains($out, 'Contul a fost creat'),
    substr(preg_replace('/\s+/', ' ', strip_tags($out)), 0, 160));
check('nu tipărește nicio eroare PHP',
    !preg_match('/(Fatal error|Warning|Notice|Deprecated):/', $out),
    substr($out, 0, 160));

$admin = $db->query("SELECT username, role, active FROM users WHERE role = 'admin'")->fetch();
check('contul de administrator există', $admin && $admin['username'] === 'instalator',
    json_encode($admin));

$panou = $db->query("SELECT username, role, is_system, active FROM users WHERE username = 'panou'")->fetch();
check('contul de sistem al tabletelor există', $panou && (int) $panou['is_system'] === 1,
    json_encode($panou));
check('contul tabletelor e angajat, nu administrator', $panou && $panou['role'] === 'angajat',
    $panou['role'] ?? '');

/* ------------------------------------------------- codul tabletelor -- */

echo "\n=== codul tabletelor, arătat o singură dată ===\n";

preg_match('#<p class="cod-val"><code>([^<]+)</code>#', $out, $m);
$cod = $m[1] ?? '';

check('instalatorul arată codul generat', $cod !== '',
    'nu am găsit codul în pagina de instalare');
check('codul NU este vechea valoare fixă', strtolower($cod) !== 'lab123', $cod);
check('codul are opt caractere din alfabetul generatorului',
    (bool) preg_match('/^[a-z2-9]{8}$/', $cod), $cod);

$stocat = (string) ($db->query("SELECT value FROM settings WHERE setting_key = 'panel_code'")->fetch()['value'] ?? '');
check('codul nu se păstrează în clar în baza de date', $stocat !== $cod && $stocat !== '',
    substr($stocat, 0, 24));
check('codul stocat se decriptează exact în cel afișat', decrypt_value($stocat) === $cod,
    var_export(decrypt_value($stocat), true));

// Al doilea drum: pagina nu mai are voie să facă nimic. Nu mai spune nici
// „Există deja un administrator": pe un server real fișierul rămâne adesea
// neșters, iar mesajul acela confirma oricui trecea pe acolo că a găsit o
// instalare Golden Pastry pornită. Acum nu mai tipărește nimic — 404, ca și
// cum ar fi fost deja șters.
$dinNou = $ruleaza();
check('a doua rulare nu mai tipărește nimic', trim(strip_tags($dinNou)) === '',
    substr(preg_replace('/\s+/', ' ', strip_tags($dinNou)), 0, 160));
check('a doua rulare nu mai arată formularul', !str_contains($dinNou, 'name="username"'),
    substr(preg_replace('/\s+/', ' ', strip_tags($dinNou)), 0, 160));
// Se caută markup-ul, nu clasa: regula CSS `.cod-val` e tipărită oricum, în
// blocul <style> al paginii.
check('a doua rulare nu mai arată niciun cod',
    !preg_match('#<p class="cod-val">#', $dinNou));

$dupa = (string) ($db->query("SELECT value FROM settings WHERE setting_key = 'panel_code'")->fetch()['value'] ?? '');
check('codul rămas în bază e tot cel de la prima instalare', $dupa === $stocat);

// Două instalări diferite nu primesc același cod: proba de mai jos reface baza
// și compară. Fără asta, un generator care întoarce mereu aceeași valoare ar
// trece de tot ce e mai sus.
$srv->exec("DROP DATABASE IF EXISTS `$DBNAME`");
$srv->exec("CREATE DATABASE `$DBNAME` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$srv->exec("USE `$DBNAME`");
foreach (['sql/001_schema.sql', 'sql/002_seed.sql'] as $f) {
    $srv->exec(file_get_contents($ROOT . '/' . $f));
}
preg_match('#<p class="cod-val"><code>([^<]+)</code>#', $ruleaza(), $m2);
check('a doua instalare primește alt cod', ($m2[1] ?? '') !== '' && ($m2[1] ?? '') !== $cod,
    ($m2[1] ?? '(niciunul)') . ' vs ' . $cod);

/* ------------------------------------------------------------ curat -- */

$srv->exec("DROP DATABASE IF EXISTS `$DBNAME`");
@unlink($configProba);
@unlink($bootstrap);

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
