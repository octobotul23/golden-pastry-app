<?php
/**
 * Proba pentru scăderea dublă din stoc.
 *
 *     php .claude/test-stoc-concurent.php
 *
 * De ce nu prin HTTP: serverul de dezvoltare (`php -S`) răspunde la o singură
 * cerere odată pe Windows, deci două apăsări „simultane" pe „Scade din stoc"
 * nu se pot reproduce prin rețea. Aici deschidem două conexiuni la baza de
 * date și le împletim manual, exact cum s-ar suprapune două cereri reale:
 *
 *   A: BEGIN, citește comenzile nescăzute
 *   B: BEGIN, citește comenzile nescăzute   ← aici se năștea dublarea
 *   A: scade, marchează, COMMIT
 *   B: scade încă o dată aceleași comenzi
 *
 * Reparația citește lista cu `FOR UPDATE`, deci pasul al doilea așteaptă și,
 * după ce A încheie, nu mai găsește nimic. Proba a fost verificată scoțând
 * `FOR UPDATE` din api/routes/stock.php și văzând-o picând.
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

$GP_CONFIG = require __DIR__ . '/../api/config.php';
function config(string $key, $default = null)
{
    global $GP_CONFIG;
    return $GP_CONFIG[$key] ?? $default;
}

require __DIR__ . '/../api/lib/http.php';
require __DIR__ . '/../api/lib/db.php';

/** Interogarea de listare, luată din chiar fișierul care se testează. */
function selectul_din_cod(): string
{
    $sursa = file_get_contents(__DIR__ . '/../api/routes/stock.php');
    $i = strpos($sursa, "SELECT id, ref, customer_name FROM orders");
    if ($i === false) {
        echo "  FAIL  nu găsesc interogarea de listare în api/routes/stock.php\n";
        exit(1);
    }
    $j = strpos($sursa, "'", $i);
    return preg_replace('/\s+/', ' ', substr($sursa, $i, $j - $i));
}

function conexiune(): PDO
{
    $cfg = config('db');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], (int) $cfg['port'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4'),
        $cfg['user'],
        $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    // Fără asta, a doua conexiune ar aștepta 50 de secunde la fiecare rulare.
    $pdo->exec('SET innodb_lock_wait_timeout = 2');
    return $pdo;
}

$SQL   = selectul_din_cod();
$are4U = (bool) preg_match('/FOR UPDATE\s*$/i', $SQL);
echo "\n=== interogarea din cod ===\n";
echo "  " . $SQL . "\n";
check('lista comenzilor de scăzut se citește cu blocare (FOR UPDATE)', $are4U, $SQL);

/* Etapa finală și o comandă de probă, direct în bază: proba se uită la
   încuietoare, nu la rutele HTTP, care sunt deja acoperite în test-settings. */
$done = (string) (one("SELECT value FROM settings WHERE setting_key = 'order_status_done'")['value'] ?? '');
if ($done === '') {
    echo "  FAIL  nu e configurată etapa care încheie comanda\n";
    exit(1);
}

$ziua = gmdate('Y-m-d', strtotime('+400 day'));
$seq  = (int) db()->query("SELECT COALESCE(MAX(seq), 0) + 1 FROM orders WHERE day = '$ziua'")->fetchColumn();
q(
    'INSERT INTO orders (seq, ref, day, status, fulfillment, channel, customer_name, total, stock_synced)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)',
    [$seq, '#TEST-LOCK-' . $seq, $ziua, $done, 'Ridicare', 'panou', 'Probă blocare stoc', 0]
);
$oid = (int) db()->lastInsertId();

echo "\n=== două scăderi care se suprapun ===\n";

$a = conexiune();
$b = conexiune();

$a->beginTransaction();
$st = $a->prepare($SQL);
$st->execute([$done]);
$vazuteA = array_column($st->fetchAll(), 'id');
check('prima cerere vede comanda nescăzută', in_array($oid, array_map('intval', $vazuteA), true),
    'a văzut ' . count($vazuteA) . ' comenzi');

// A doua cerere pornește înainte ca prima să încheie — exact dublul clic.
$b->beginTransaction();
$blocata = false;
$vazuteB = [];
try {
    $st = $b->prepare($SQL);
    $st->execute([$done]);
    $vazuteB = array_column($st->fetchAll(), 'id');
} catch (PDOException $e) {
    // 1205 = lock wait timeout. Înseamnă că prima cerere ține rândul: exact ce
    // vrem. În producție, a doua cerere ar aștepta și ar continua după commit.
    $blocata = str_contains($e->getMessage(), '1205') || $e->getCode() === 'HY000';
}
check('a doua cerere nu poate citi aceleași comenzi cât timp prima lucrează',
    $blocata || !in_array($oid, array_map('intval', $vazuteB), true),
    'a văzut aceeași comandă: ' . json_encode($vazuteB));

// Prima cerere își termină treaba.
$a->prepare('UPDATE orders SET stock_synced = 1 WHERE id = ? AND stock_synced = 0')->execute([$oid]);
$a->commit();

// A doua reia de unde a rămas — și nu mai are ce scădea.
if ($b->inTransaction()) {
    $b->rollBack();
}
$b->beginTransaction();
$st = $b->prepare($SQL);
$st->execute([$done]);
$vazuteB = array_map('intval', array_column($st->fetchAll(), 'id'));
$b->commit();
check('după ce prima încheie, a doua nu mai găsește comanda',
    !in_array($oid, $vazuteB, true), json_encode($vazuteB));

/* Marcajul are și el condiția lui, ca a doua plasă de siguranță. */
echo "\n=== marcajul nu se poate pune de două ori ===\n";
$st = q('UPDATE orders SET stock_synced = 1 WHERE id = ? AND stock_synced = 0', [$oid]);
check('a doua marcare pe o comandă deja scăzută nu atinge niciun rând',
    $st->rowCount() === 0, 'a atins ' . $st->rowCount() . ' rânduri');

$sursa = file_get_contents(__DIR__ . '/../api/routes/stock.php');
check('marcajul din cod cere „stock_synced = 0”',
    (bool) preg_match('/UPDATE orders SET stock_synced = 1 WHERE id IN \([^)]*\) AND stock_synced = 0/', $sursa));

q('DELETE FROM orders WHERE id = ?', [$oid]);

echo "\n" . str_repeat('=', 52) . "\n";
echo "$pass trecute · $fail eșuate\n";
if ($fail) {
    echo "\nESUATE:\n";
    foreach ($failures as $f) {
        echo "  x $f\n";
    }
}
echo "\n";
exit($fail ? 1 : 0);
