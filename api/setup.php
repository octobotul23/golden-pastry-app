<?php
/**
 * Instalare inițială — crearea primului cont de administrator.
 *
 * Se poate rula O SINGURĂ DATĂ: din momentul în care există un administrator
 * activ, pagina răspunde 404, ca și cum fișierul ar fi fost deja șters.
 * Conturile demo din prototip (admin/goldenpastry etc.) nu sunt importate
 * nicăieri — parolele lor erau publice în sursa paginilor.
 *
 * Până atunci, contul se creează numai cu codul scris la „install_code" în
 * api/config.php. Fișierul acesta pleacă în pachetul de publicare, fiindcă
 * omul are nevoie de el la prima instalare, și stă pe un domeniu public din
 * clipa în care se urcă; fără cod, primul străin care nimerește adresa între
 * urcarea fișierelor și crearea contului devine administratorul patiseriei —
 * cu telefoanele și adresele clienților, comenzile, Jurnalul și Setările.
 *
 * După ce ai creat contul, ȘTERGE acest fișier de pe server.
 */

declare(strict_types=1);

// Aceeași regulă ca în index.php: configurarea poate sta în afara zonei publice.
$configFile = getenv('GP_CONFIG') ?: (__DIR__ . '/config.php');
if (!is_file($configFile)) {
    exit('Lipsește api/config.php. Copiază api/config.example.php și completează-l.');
}

$GP_CONFIG = require $configFile;
function config(string $key, $default = null)
{
    global $GP_CONFIG;
    return $GP_CONFIG[$key] ?? $default;
}

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/crypto.php';
require __DIR__ . '/lib/db.php';

// Amândouă rolurile cu drepturi depline, nu doar „admin" — pagina asta nu poate
// cere api/lib/auth.php (n-are sesiune, n-are rute), deci lista se scrie aici pe
// litere; e singurul loc din tot codul unde are voie.
//
// Numărat doar pe „admin", lacătul de mai jos se descuia singur: ștergerea
// ultimului cont cu rol „admin" e permisă dinadins câtă vreme rămâne un fondator
// (api/routes/users.php:388-396), iar în clipa aia instalatorul înceta să mai
// răspundă 404 și își arăta din nou formularul pe api.goldenpastry.ro. Codul de
// instalare din config.php ar fi rămas singura pază — pe o instalare terminată
// de luni de zile, unde nimeni nu se mai gândește la el.
$adminCount = (int) scalar(
    "SELECT COUNT(*) FROM users WHERE role IN ('admin', 'fondator') AND active = 1"
);

// Instalarea e terminată: de aici încolo pagina nu mai există. Ștergerea
// fișierului rămâne în documentație, dar omul care publică o uită, iar pagina
// rămâne pe api.goldenpastry.ro. Mesajul de dinainte („Există deja un
// administrator") îi confirma oricui trecea pe acolo că a găsit o instalare
// Golden Pastry pornită, cu instalatorul încă pe server; un 404 nu-i spune
// nimic.
if ($adminCount > 0) {
    http_response_code(404);
    exit;
}

$done       = false;
$error      = '';
// Codul generat pentru tablete. Rămâne gol dacă exista deja unul configurat.
$panelCode  = '';

// Codul de instalare, scris de om în api/config.php la pasul 4 al publicării.
// Cât timp nu există niciun administrator, el e singurul lucru care desparte
// formularul de restul internetului: certificatul cerut la pasul 2 anunță
// public api.goldenpastry.ro în jurnalele de Certificate Transparency, iar
// cererile pe /setup.php sunt printre primele pe care le încearcă scanerele.
// Între pasul 4 și pasul 6 pot trece ore — o seară, o discuție cu găzduirea —
// și în tot intervalul ăsta pagina creează un cont cu drepturi depline.
// Un cod sub 12 caractere se ghicește, deci nu deschide nimic.
$installCode = (string) config('install_code', '');
if (strlen($installCode) < 12) {
    $error = 'Nu este pus niciun cod de instalare în api/config.php. Deschide fișierul, scrie la '
        . '„install_code" un cod de cel puțin 12 caractere (Password Generator din cPanel îți dă unul), '
        . 'salvează, apoi reîncarcă pagina.';
}

if ($error === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $name     = trim((string) ($_POST['name'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    // Tăiate de spații, ca la autentificare. Parola scrisă aici e comparată,
    // la prima intrare, cu ce trece prin need() din api/lib/http.php — iar
    // need() taie. Un spațiu lipit de managerul de parole sau de tastatura
    // telefonului dădea un hash pe care intrarea în cont nu-l mai putea potrivi
    // NICIODATĂ, și nu exista întoarcere: pagina asta răspunde 404 din clipa în
    // care contul de administrator există. Tăierea se face ÎNAINTE de
    // verificarea lungimii, altfel două spații ar fi trecut drept caractere de
    // parolă și „minim 10" ar fi acceptat opt.
    $pass     = trim((string) ($_POST['password'] ?? ''));
    $pass2    = trim((string) ($_POST['password2'] ?? ''));

    // hash_equals, nu „!==": o comparație care se oprește la primul caracter
    // greșit lasă codul să fie aflat caracter cu caracter, măsurând cât durează
    // răspunsul.
    if (!hash_equals($installCode, (string) ($_POST['install_code'] ?? ''))) {
        $error = 'Codul de instalare nu este cel din api/config.php.';
    } elseif (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
        $error = 'Utilizatorul poate conține doar litere mici, cifre, punct, minus și underscore (3–64 caractere).';
    } elseif ($name === '') {
        $error = 'Completează numele.';
    } elseif (strlen($pass) < 10) {
        $error = 'Parola trebuie să aibă cel puțin 10 caractere.';
    } elseif ($pass !== $pass2) {
        $error = 'Cele două parole nu coincid.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresa de e-mail nu este validă.';
    } else {
        q(
            "INSERT INTO users (username, password_hash, name, email, role, active, must_change_password)
             VALUES (?, ?, ?, ?, 'admin', 1, 0)",
            [$username, password_hash($pass, PASSWORD_DEFAULT), $name, $email ?: null]
        );
        // Contul de sistem al tabletelor din laborator. Nu are parolă proprie:
        // se deschide cu codul comun, verificat pe server la /auth/panel.
        q(
            "INSERT INTO users (username, password_hash, name, role, active, is_system)
             VALUES ('panou', ?, 'Panou comun', 'angajat', 1, 1)
             ON DUPLICATE KEY UPDATE is_system = 1",
            [password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]
        );
        // Codul tabletelor: generat aici și arătat O SINGURĂ DATĂ, mai jos.
        //
        // Până acum se scria o valoare fixă, aceeași la orice instalare, care
        // apărea și în sursa paginilor publicate pe lab.goldenpastry.ro. Un cod
        // fix, publicat, nu e cod: o singură cerere fără cookie și fără antet
        // CSRF deschidea o sesiune de angajat pe contul comun „panou", validă
        // șapte zile. Nici măcar sursa nu-i trebuia atacatorului — o valoare
        // implicită a instalatorului se ghicește din prima.
        //
        // Nu se salvează nicăieri în clar, deci nici noi nu-l mai putem arăta
        // a doua oară; dacă se pierde, administratorul pune altul din Setări.
        if (!scalar("SELECT 1 FROM settings WHERE setting_key = 'panel_code' AND value <> ''")) {
            $panelCode = random_panel_code();
            q(
                "UPDATE settings SET value = ? WHERE setting_key = 'panel_code' AND (value IS NULL OR value = '')",
                [encrypt_value($panelCode)]
            );
        }

        // Prima adresă de notificare, ca sistemul să fie funcțional imediat.
        if ($email !== '') {
            q("UPDATE settings SET value = ? WHERE setting_key = 'notify_email_to' AND (value IS NULL OR value = '')", [$email]);
        }
        $done = true;
    }
}

$h = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ro">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Golden Pastry — instalare</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.55 system-ui, "Segoe UI", sans-serif; max-width: 34rem;
         margin: 3rem auto; padding: 0 1.25rem; }
  h1 { font-size: 1.4rem; margin-bottom: .25rem; }
  p.sub { color: #6b6b6b; margin-top: 0; }
  label { display: block; margin: .9rem 0 .25rem; font-weight: 600; font-size: .92rem; }
  input { width: 100%; padding: .6rem .7rem; font-size: 1rem; border: 1px solid #bbb;
          border-radius: .4rem; background: canvas; color: canvastext; }
  button { margin-top: 1.4rem; padding: .7rem 1.3rem; font-size: 1rem; font-weight: 600;
           border: 0; border-radius: .4rem; background: #a67c2e; color: #fff; cursor: pointer; }
  .msg { padding: .8rem 1rem; border-radius: .4rem; margin: 1rem 0; }
  .err { background: #fde8e8; color: #8b1a1a; }
  .ok  { background: #e6f6ea; color: #14532d; }
  .cod { background: #fff6e2; color: #5a3d05; border: 1px solid #e3c47a; }
  .cod p { margin: .5rem 0 0; font-size: .92rem; }
  .cod-val code { display: inline-block; font-size: 1.6rem; letter-spacing: .18em;
                  padding: .35rem .7rem; background: #fff; border: 1px solid #e3c47a; }
  code { background: #0001; padding: .1rem .35rem; border-radius: .25rem; }
</style>

<h1>Golden Pastry — instalare</h1>
<p class="sub">Creează primul cont de administrator.</p>

<?php if ($error): ?>
  <div class="msg err"><?= $h($error) ?></div>
<?php endif; ?>

<?php if ($done): ?>
  <div class="msg ok">
    <strong>Contul a fost creat.</strong><br>
    Acum șterge fișierul <code>api/setup.php</code> de pe server, apoi
    autentifică-te pe <code>lab.goldenpastry.ro</code>.
  </div>
  <?php if ($panelCode !== ''): ?>
    <div class="msg cod">
      <strong>Codul tabletelor din laborator</strong>
      <p class="cod-val"><code><?= $h($panelCode) ?></code></p>
      <p>Cu el se deschid panourile de lucru de pe tablete, fără cont
        individual. <strong>Notează-l acum.</strong> Se păstrează criptat, deci
        nu mai poate fi afișat niciodată — nici de această pagină, nici din
        Setări. Dacă îl pierzi, pui altul din <em>Setări → Acces</em>.</p>
    </div>
  <?php endif; ?>
<?php elseif ($adminCount === 0): ?>
  <form method="post" autocomplete="off">
    <label for="c">Cod de instalare (din api/config.php)</label>
    <!-- Nu se rescrie în pagină după o încercare greșită: e cheia care ține
         formularul închis, nu un câmp de completat pe jumătate. -->
    <input id="c" name="install_code" required autocomplete="off">

    <label for="u">Utilizator</label>
    <input id="u" name="username" required pattern="[a-z0-9._\-]{3,64}"
           value="<?= $h($_POST['username'] ?? '') ?>">

    <label for="n">Nume complet</label>
    <input id="n" name="name" required value="<?= $h($_POST['name'] ?? '') ?>">

    <label for="e">E-mail (primește notificările de comandă nouă)</label>
    <input id="e" name="email" type="email" value="<?= $h($_POST['email'] ?? '') ?>">

    <label for="p">Parolă (minim 10 caractere)</label>
    <input id="p" name="password" type="password" required minlength="10">

    <label for="p2">Repetă parola</label>
    <input id="p2" name="password2" type="password" required minlength="10">

    <button type="submit">Creează administratorul</button>
  </form>
<?php endif; ?>
</html>
