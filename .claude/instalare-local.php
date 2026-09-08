<?php
/**
 * Pregătire bază de date pentru mediul local (Laragon / MariaDB).
 * Creează baza gp_dev, utilizatorul din config.php și aplică fișierele din sql/.
 * FIȘIER TEMPORAR — se poate șterge după instalare.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);
$cfg  = require $root . '/api/config.php';
$db   = $cfg['db'];

$rootUser = $_GET['ru'] ?? 'root';
$rootPass = $_GET['rp'] ?? '';

function say(string $s): void { echo $s . "\n"; @ob_flush(); @flush(); }

try {
    $pdo = new PDO(
        "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4",
        $rootUser, $rootPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    http_response_code(500);
    say('Nu mă pot conecta la MySQL ca ' . $rootUser . ': ' . $e->getMessage());
    say('Pornește MySQL din Laragon (Start All) și reîncarcă pagina.');
    exit;
}

say('Conectat la MySQL ca ' . $rootUser . '.');

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
say("Baza `{$db['name']}` există.");

if ($db['user'] !== $rootUser) {
    $u = $pdo->quote($db['user']);
    $p = $pdo->quote($db['pass']);
    foreach (["'%'", "'localhost'", "'127.0.0.1'"] as $host) {
        $pdo->exec("CREATE USER IF NOT EXISTS {$u}@{$host} IDENTIFIED BY {$p}");
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$db['name']}`.* TO {$u}@{$host}");
    }
    $pdo->exec('FLUSH PRIVILEGES');
    say("Utilizatorul `{$db['user']}` are drepturi pe `{$db['name']}`.");
}

$pdo->exec("USE `{$db['name']}`");

/** Împarte un fișier .sql în instrucțiuni, ținând cont de ghilimele și comentarii. */
function instructiuni(string $sql): array {
    $out = []; $buf = ''; $q = null; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q !== null) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $n) { $buf .= $sql[++$i]; continue; }
            if ($c === $q) $q = null;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $buf .= $c; continue; }
        if ($c === '-' && substr($sql, $i, 2) === '--' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? "\n") === "\n")) {
            while ($i < $n && $sql[$i] !== "\n") $i++;
            $buf .= "\n"; continue;
        }
        if ($c === '/' && substr($sql, $i, 2) === '/*') {
            $end = strpos($sql, '*/', $i);
            $i = $end === false ? $n : $end + 1;
            continue;
        }
        if ($c === ';') { $out[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return array_values(array_filter($out, static fn($s) => $s !== ''));
}

$fisiere = glob($root . '/sql/*.sql');
sort($fisiere);
$totalErori = 0;

foreach ($fisiere as $f) {
    $nume = basename($f);
    $st = instructiuni((string) file_get_contents($f));
    $ok = 0; $sarite = 0; $erori = [];
    foreach ($st as $s) {
        try { $pdo->exec($s); $ok++; }
        catch (Throwable $e) {
            $m = $e->getMessage();
            // Migrări deja aplicate: coloană/cheie/tabel care există deja.
            if (preg_match('/duplicate (column|key)|already exists|Duplicate entry/i', $m)) { $sarite++; continue; }
            $erori[] = substr($m, 0, 200) . ' :: ' . substr(preg_replace('/\s+/', ' ', $s), 0, 120);
        }
    }
    $totalErori += count($erori);
    say(sprintf('%-32s %3d rulate · %2d sărite · %d erori', $nume, $ok, $sarite, count($erori)));
    foreach (array_slice($erori, 0, 5) as $e) say('    ! ' . $e);
}

$tabele = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
say('');
say('Tabele în ' . $db['name'] . ': ' . count($tabele));
say(implode(', ', $tabele));
$prod = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
say('Produse în catalog: ' . $prod);
say('');
say($totalErori === 0 ? 'GATA — baza e pregătită.' : 'Terminat, dar cu ' . $totalErori . ' erori (vezi mai sus).');
say('Următorul pas: /api/setup.php — creează contul de administrator.');
