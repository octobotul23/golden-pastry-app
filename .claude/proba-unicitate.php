<?php
/**
 * Ajutor pentru .claude/test-reparat-depozit-refuzuri.mjs: pune baza să respingă
 * un rând duplicat și arată ce iese din `q_unique()`.
 *
 * De ce nu prin HTTP: ciocnirea pe care o repară `q_unique()` se petrece în
 * crăpătura dintre „SELECT 1 … WHERE name = ?" și INSERT, adică între două
 * cereri PARALELE. Serverul de dezvoltare (`php -S`) are un singur proces, deci
 * cererile se așază la rând și crăpătura nu se deschide niciodată: prin HTTP,
 * și codul reparat, și cel stricat ar răspunde la fel, iar proba n-ar dovedi
 * nimic. Aici chemăm direct scrierea care se izbește de indexul unic — exact
 * ce pățește a doua cerere din cursă — și verificăm că iese refuzul omenesc,
 * nu excepția care ajunge la client ca „Eroare internă.".
 *
 *   php .claude/proba-unicitate.php <radacina> articol|cont|curata <nume>
 *
 * Baza se alege prin variabila de mediu GP_CONFIG, ca la api/index.php: proba
 * rulează pe instanța izolată din spatele lui GP_BASE, nu pe gp_dev.
 */

declare(strict_types=1);

$radacina = $argv[1] ?? '';
$ce       = $argv[2] ?? '';
$nume     = $argv[3] ?? '';

$caleCfg = getenv('GP_CONFIG') ?: ($radacina . '/api/config.php');
if (!is_file($caleCfg)) {
    fwrite(STDERR, 'lipsește configurarea: ' . $caleCfg . "\n");
    exit(2);
}

$GP_CONFIG = require $caleCfg;

function config(string $key, $default = null)
{
    global $GP_CONFIG;
    return $GP_CONFIG[$key] ?? $default;
}

require $radacina . '/api/lib/http.php';
require $radacina . '/api/lib/db.php';

// Plasă de siguranță: scriptul șterge și inserează rânduri, deci n-are ce căuta
// pe o bază adevărată.
$baza = (string) (config('db')['name'] ?? '');
if (!preg_match('/^(gp_dev|gp_test|gp_fresh|gp_i_[a-z0-9_]+)$/', $baza)) {
    fwrite(STDERR, 'bază neîngăduită pentru probă: ' . $baza . "\n");
    exit(2);
}
if ($nume === '') {
    fwrite(STDERR, "lipsește numele\n");
    exit(2);
}

// Codul dinainte de reparație nu are ajutorul. Ca proba să arate ce IEȘEA
// atunci — nu doar că lipsește o funcție — scriem, în lipsa lui, exact cum
// scria el: `q()` gol, cu excepția lăsată să urce.
if (!function_exists('q_unique')) {
    function q_unique(string $sql, array $params, string $mesaj, string $cod): PDOStatement
    {
        return q($sql, $params);
    }
}

if ($ce === 'curata') {
    q('DELETE FROM stock_items WHERE name = ?', [$nume]);
    q('DELETE FROM users WHERE username = ?', [$nume]);
    echo json_encode(['curatat' => true]);
    exit(0);
}

if ($ce === 'articol') {
    q('DELETE FROM stock_items WHERE name = ?', [$nume]);
    q('INSERT INTO stock_items (name, category, unit) VALUES (?, ?, ?)', [$nume, 'Produse finite', 'buc']);
    // A doua scriere, identică: exact ce trimite cererea care a pierdut cursa.
    q_unique(
        'INSERT INTO stock_items (name, category, unit) VALUES (?, ?, ?)',
        [$nume, 'Produse finite', 'buc'],
        'Există deja un articol cu numele „' . $nume . '”.',
        'name_taken'
    );
    echo json_encode(['nimic_nu_a_oprit_scrierea' => true]);
    exit(0);
}

if ($ce === 'cont') {
    q('DELETE FROM users WHERE username = ?', [$nume]);
    q(
        'INSERT INTO users (username, password_hash, name, role) VALUES (?, ?, ?, ?)',
        [$nume, 'fără-parolă', 'Probă Refuz Unicitate', 'client']
    );
    q_unique(
        'INSERT INTO users (username, password_hash, name, role) VALUES (?, ?, ?, ?)',
        [$nume, 'fără-parolă', 'Probă Refuz Unicitate', 'client'],
        'Există deja un cont cu acest utilizator.',
        'username_taken'
    );
    echo json_encode(['nimic_nu_a_oprit_scrierea' => true]);
    exit(0);
}

fwrite(STDERR, "folosire: php .claude/proba-unicitate.php <radacina> articol|cont|curata <nume>\n");
exit(2);
