<?php
/**
 * Conexiunea la baza de date (PDO).
 *
 * Toate interogările din proiect folosesc prepared statements — niciodată
 * concatenare de text în SQL. Emularea este dezactivată, deci parametrii sunt
 * trimiși separat de interogare, la nivel de protocol MySQL.
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'], (int) $cfg['port'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        // Mesajul brut poate conține utilizatorul și gazda bazei de date.
        error_log('[gp] conexiune BD eșuată: ' . $e->getMessage());
        fail(
            config('env') === 'dev'
                ? 'Conexiune la baza de date eșuată: ' . $e->getMessage()
                : 'Serviciul este temporar indisponibil.',
            503,
            'db_unavailable'
        );
    }

    // Datele se scriu și se citesc în UTC; afișarea locală se face în interfață.
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** Rulează o interogare cu parametri și returnează statement-ul. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/**
 * O scriere care se poate izbi de un index unic, cu răspunsul omenesc pregătit.
 *
 * Verificarea obișnuită — `SELECT 1 … WHERE name = ?` și abia apoi INSERT — e
 * bună, dar are o crăpătură cât o clipă: între cei doi pași încap două cereri
 * paralele. Amândouă găsesc locul liber, amândouă scriu, iar a doua se lovește
 * de index și urcă până la dispecerul din api/index.php ca 500 `db_error` —
 * text brut de MySQL în dezvoltare, „Eroare internă." în producție. Omul nu
 * află că articolul există deja, așa că mai încearcă o dată sau cheamă pe
 * cineva. Se întâmplă exact când doi administratori vorbesc la telefon și
 * amândoi zic „îl adaug eu".
 *
 * Aici prindem chiar căderea aceea și o întoarcem în refuzul pe care cererea
 * l-ar fi primit dacă ar fi venit o clipă mai târziu. Numai 1062 (rând
 * duplicat): restul lui 23000 — o cheie străină ruptă, de pildă — e o
 * defecțiune adevărată și trebuie să rămână o defecțiune.
 */
function q_unique(string $sql, array $params, string $mesaj, string $cod): PDOStatement
{
    try {
        return q($sql, $params);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            fail($mesaj, 409, $cod);
        }
        throw $e;
    }
}

/** Primul rând, sau null. */
function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Toate rândurile. */
function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Prima coloană a primului rând, sau null. */
function scalar(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}
