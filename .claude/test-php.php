<?php
/**
 * Probe directe pe funcțiile PHP care nu se pot verifica prin HTTP.
 *
 *     php .claude/test-php.php
 *
 * `client_ip()` este cazul principal: prin HTTP, cererea vine mereu de la
 * 127.0.0.1, care în configurarea locală ESTE proxy de încredere — deci nu s-ar
 * putea arăta niciodată că antetul e ignorat de la o sursă necunoscută. Aici
 * putem pune orice în $_SERVER și verificăm ambele situații.
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

/* Configurarea o dăm noi, ca proba să nu depindă de config.php. */
$GP_CONFIG = [];
function config(string $key, $default = null)
{
    global $GP_CONFIG;
    return $GP_CONFIG[$key] ?? $default;
}

require __DIR__ . '/../api/lib/http.php';

/* --------------------------------------------------------- client_ip -- */

echo "\n=== adresa clientului ===\n";

$scenarii = [
    [
        'ce'       => 'fără proxy de încredere, antetul este ignorat',
        'trusted'  => [],
        'server'   => ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'],
        'asteptat' => '10.0.0.7',
    ],
    [
        'ce'       => 'fără proxy, nici antetul Cloudflare nu contează',
        'trusted'  => [],
        'server'   => ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8'],
        'asteptat' => '10.0.0.7',
    ],
    [
        'ce'       => 'de la un proxy de încredere, antetul este crezut',
        'trusted'  => ['10.0.0.7'],
        'server'   => ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'],
        'asteptat' => '1.2.3.4',
    ],
    [
        'ce'       => 'din listă se ia prima adresă, clientul real',
        'trusted'  => ['10.0.0.7'],
        'server'   => ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 9.9.9.9'],
        'asteptat' => '1.2.3.4',
    ],
    [
        'ce'       => 'un antet cu text în loc de adresă cade pe conexiune',
        'trusted'  => ['10.0.0.7'],
        'server'   => ['REMOTE_ADDR' => '10.0.0.7', 'HTTP_X_FORWARDED_FOR' => 'nu-e-adresa'],
        'asteptat' => '10.0.0.7',
    ],
    [
        'ce'       => 'un proxy care nu e în listă nu e crezut',
        'trusted'  => ['10.0.0.7'],
        'server'   => ['REMOTE_ADDR' => '10.0.0.99', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'],
        'asteptat' => '10.0.0.99',
    ],
    [
        'ce'       => 'fără nicio adresă validă întoarce 0.0.0.0',
        'trusted'  => [],
        'server'   => ['REMOTE_ADDR' => 'aiurea'],
        'asteptat' => '0.0.0.0',
    ],
];

foreach ($scenarii as $s) {
    $GP_CONFIG = ['trusted_proxies' => $s['trusted']];
    $_SERVER = $s['server'];
    $got = client_ip();
    check($s['ce'], $got === $s['asteptat'], "a dat $got, așteptam {$s['asteptat']}");
}

/* ----------------------------------------------------------- slugify -- */

echo "\n=== adresa produsului ===\n";

// slugify() stă în routes/catalog.php, care are nevoie de restul aplicației
// ca să se încarce. Copiem doar funcția aici? Nu: am avea două versiuni care
// se pot despărți. O încărcăm prin citirea fișierului și extragerea ei.
$src = file_get_contents(__DIR__ . '/../api/routes/catalog.php');
if (preg_match('/function slugify\(string \$text\): string\s*\{.*?\n\}/s', $src, $m)) {
    eval($m[0]);
    $probe = [
        'Ștrudel cu mere'        => 'strudel-cu-mere',
        'Croissant cu ciocolată' => 'croissant-cu-ciocolata',
        'Trigon cu brânză'       => 'trigon-cu-branza',
        'Covrig  cu   sare'      => 'covrig-cu-sare',
        '  Marginal  '           => 'marginal',
        'Ăsta-i Țapul!'          => 'asta-i-tapul',
        '???'                    => '',
    ];
    foreach ($probe as $in => $want) {
        $got = slugify($in);
        // Acoladele sunt necesare: „”" este multi-octet, iar PHP l-ar considera
        // parte din numele variabilei — „$in”" ar căuta o variabilă inexistentă.
        check("„{$in}” → „{$want}”", $got === $want, "a dat „{$got}”");
    }
} else {
    check('slugify() poate fi găsită în catalog.php', false, 'tiparul funcției s-a schimbat');
}

/* ------------------------------------------------ codul tabletelor -- */

// Codul cu care se deschid panourile de pe tablete era literalul „lab123",
// scris de instalator și tipărit în două pagini publicate ca fișiere statice.
// Acum se generează la instalare și se arată o singură dată. Proba se uită la
// generator: un cod slab e la fel de rău ca unul fix.
echo "\n=== codul tabletelor, generat la instalare ===\n";

require __DIR__ . '/../api/lib/crypto.php';

$coduri = [];
for ($i = 0; $i < 200; $i++) {
    $coduri[] = random_panel_code();
}
$toate = implode('', $coduri);

check('are opt caractere', strlen($coduri[0]) === 8, 'a dat ' . strlen($coduri[0]));
check('trece pragul cerut de server (minim 6)', strlen($coduri[0]) >= 6);
check('două instalări nu primesc același cod', count(array_unique($coduri)) === 200,
    count(array_unique($coduri)) . ' coduri distincte din 200');
check('fără caractere care se citesc greșit (i, l, 1, o, 0)',
    preg_match('/[il1o0]/', $toate) === 0, 'a apărut cel puțin unul');
check('doar minuscule și cifre — se tastează pe tabletă, unde prima literă s-ar scrie mare',
    preg_match('/[^a-z2-9]/', $toate) === 0, 'a apărut alt caracter');

// Un generator care ar returna mereu aceleași câteva litere ar trece de
// verificările de mai sus. 1600 de extrageri din 31 de caractere: fiecare
// trebuie să apară, altfel alfabetul nu e cel declarat.
$lipsa = [];
foreach (str_split('abcdefghjkmnpqrstuvwxyz23456789') as $ch) {
    if (!str_contains($toate, $ch)) {
        $lipsa[] = $ch;
    }
}
check('folosește tot alfabetul, nu un colț din el', $lipsa === [], 'lipsesc: ' . implode(' ', $lipsa));

/* ------------------------------------------- fotografiile produselor -- */

// `photo_dir` se poate muta din config.php — pe cPanel, API-ul stă în alt
// subdomeniu decât site-ul. Adresa scrisă în baza de date era însă mereu
// „products/…", iar ștergerea pozei înlocuite presupunea aceeași structură:
// fișierul ajungea într-un loc și site-ul îl căuta în altul.
// Aceeași metodă ca la slugify(): extragem cele trei funcții din fișier, ca să
// nu existe niciodată două versiuni care se pot despărți una de alta.
$srcCat = file_get_contents(__DIR__ . '/../api/routes/catalog.php');
foreach (['photo_dir', 'photo_web_prefix', 'is_uploaded_photo'] as $fn) {
    if (!preg_match('/function ' . $fn . '\(.*?\n\}/s', $srcCat, $m)) {
        echo "  FAIL  nu găsesc $fn() în api/routes/catalog.php\n";
        exit(1);
    }
    eval($m[0]);
}

echo "\n=== unde ajung fotografiile produselor ===\n";

$GP_CONFIG = ['photo_dir' => ''];
check('fără setare, folderul rămâne cel de lângă proiect',
    str_ends_with(str_replace('\\', '/', photo_dir()), '/products'), photo_dir());
check('și adresa web rămâne „products/”', photo_web_prefix() === 'products/', photo_web_prefix());

$GP_CONFIG = ['photo_dir' => 'D:/site/public_html/poze-produse'];
check('folderul mutat e chiar cel din setare',
    photo_dir() === 'D:/site/public_html/poze-produse', photo_dir());
check('adresa web urmează folderul mutat, nu textul fix „products/”',
    photo_web_prefix() === 'poze-produse/', photo_web_prefix());
check('poza urcată din panou e recunoscută sub noul folder',
    is_uploaded_photo('poze-produse/prod-3-a1b2c3d4.jpg'));
check('o poză scrisă cu vechiul folder nu se mai șterge din greșeală',
    !is_uploaded_photo('products/prod-3-a1b2c3d4.jpg'));
// Pozele venite odată cu proiectul se folosesc și pe pagina publică: ele nu
// se șterg niciodată de pe disc, oricât ar semăna numele.
check('pozele din pachetul inițial nu sunt confundate cu cele urcate',
    !is_uploaded_photo('poze-produse/strudel.jpg'));
check('nici o cale care încearcă să iasă din folder',
    !is_uploaded_photo('poze-produse/../../etc/prod-1-aabbccdd.jpg'));

$GP_CONFIG = [];

/* ------------------------------------------------- cheia de criptare -- */

// Verificarea de dinaintea publicării (api/verifica.php) și aplicația trebuie
// să judece cheia din config.php după aceeași regulă. Cât timp verificarea avea
// pragul ei — „cel puțin 32 de caractere" — o cheie de 64 de caractere
// alfanumerice, exact cea pe care o producea pasul 4 din manual, primea „bine"
// și verdict verde, iar criptarea se oprea abia la instalare: contul de
// administrator scris deja în baza de date, codul tabletelor rămas gol și
// tabletele din laborator fără cum să intre.
echo "\n=== cheia de criptare ===\n";

$cheieBuna = str_repeat('a1b2c3d4', 8);   // 64 de caractere hexazecimale
$cazuri = [
    ['cheia dată de bin2hex(random_bytes(32)) este bună', $cheieBuna, true],
    ['aceeași cheie scrisă cu majuscule este bună', strtoupper($cheieBuna), true],
    ['32 de caractere (16 octeți) sunt respinse — treceau de vechiul prag', str_repeat('a1b2c3d4', 4), false],
    ['64 de caractere alfanumerice, ca cele de la random.org, sunt respinse',
        'Ab3xK9mQ2pR7tW5zAb3xK9mQ2pR7tW5zAb3xK9mQ2pR7tW5zAb3xK9mQ2pR7tW5z', false],
    ['cheia necompletată este respinsă', '', false],
    ['63 de caractere hexazecimale sunt respinse', str_repeat('a', 63), false],
    ['65 de caractere hexazecimale sunt respinse', str_repeat('a', 65), false],
    ['un spațiu lipit la sfârșit strică cheia', $cheieBuna . ' ', false],
];
foreach ($cazuri as [$ce, $cheie, $asteptat]) {
    $got = crypto_key_valid($cheie);
    check($ce, $got === $asteptat, 'aplicația o consideră ' . ($got ? 'bună' : 'respinsă'));
}

// Regula trebuie să rămână într-un singur loc. Dacă verifica.php sau
// crypto_key() își scriu din nou pragul, cele două pot ajunge să răspundă
// diferit, iar omul care publică află abia la prima criptare.
$srcCrypto = file_get_contents(__DIR__ . '/../api/lib/crypto.php');
if (preg_match('/function crypto_key\(\): string\s*\{.*?\n\}/s', $srcCrypto, $mc)) {
    check('crypto_key() întreabă crypto_key_valid(), nu o a doua copie a regulii',
        str_contains($mc[0], 'crypto_key_valid('), 'în corpul funcției nu apare crypto_key_valid()');
} else {
    check('crypto_key() poate fi găsită în crypto.php', false, 'tiparul funcției s-a schimbat');
}

$srcVerifica = file_get_contents(__DIR__ . '/../api/verifica.php');
check('verifica.php judecă cheia cu crypto_key_valid(), nu cu un prag propriu',
    str_contains($srcVerifica, 'crypto_key_valid($key)')
        && preg_match('/strlen\(\$key\)\s*>=/', $srcVerifica) === 0,
    'verificarea de dinaintea publicării are din nou pragul ei');

/* ------------------------------- răspunsul înaintea notificărilor -- */

// La o cerere trimisă de pe site, anunțul către laborator trebuie să plece
// abia după ce vizitatorul și-a primit confirmarea. Altfel el așteaptă cât
// ține cea mai înceată legătură — până la cincisprezece secunde de canal —
// pentru o cerere care e deja scrisă în bază.
echo "\n=== răspunsul către client pleacă înaintea notificărilor ===\n";

$capturat        = '';
$plecat          = '';
$nivel           = -1;
$aMersMaiDeparte = false;

// Dacă json_out_early() ar opri scriptul (cum face json_out), proba s-ar
// încheia aici în tăcere, cu cod de ieșire 0 — un eșec care ar trece drept
// reușită. Plasa de mai jos scrie pe STDERR, care nu trece prin buffere.
register_shutdown_function(static function () use (&$aMersMaiDeparte): void {
    if (!$aMersMaiDeparte) {
        fwrite(STDERR, "  FAIL  json_out_early() a oprit scriptul: notificările nu ar mai pleca niciodată\n");
        exit(1);
    }
});

// Santinelă: prinde tot ce iese din funcție, în loc să ajungă în consola
// probei. Handlerul întoarce șir gol, deci nimic nu se tipărește.
ob_start(static function (string $bucata) use (&$capturat): string {
    $capturat .= $bucata;
    return '';
});

// Proba rulează în linia de comandă, unde nu există anteturi HTTP și unde
// fișierul a tipărit deja rezultatele de mai sus: PHP se plânge atunci de
// „headers already sent" pentru fiecare header() din funcție, iar
// avertismentele ar ajunge în santinelă și ar trece drept corp de răspuns.
// Ce probăm aici e ce iese din buffere, nu anteturile — deci le tăcem cât
// ține apelul, și doar atât.
$raportare = error_reporting(0);
json_out_early(['request' => ['id' => 7]], 201);
error_reporting($raportare);

$plecat          = $capturat;      // ce apucase să iasă până la întoarcere
$nivel           = ob_get_level();
$aMersMaiDeparte = true;           // se execută doar dacă funcția s-a întors

echo 'text scris din greșeală după ce răspunsul a plecat';
while (ob_get_level() > 0) {
    ob_end_clean();
}

check('funcția se întoarce, deci notificările mai apucă să plece', $aMersMaiDeparte);
check('corpul răspunsului iese din toate bufferele până la întoarcere',
    $plecat === '{"request":{"id":7}}', 'a dat „' . $plecat . '"');
check('rămâne deschis un buffer care înghite ce s-ar mai scrie după răspuns',
    $nivel === 1, 'niveluri rămase: ' . $nivel);

// Ordinea din rută contează la fel de mult ca funcția: dacă anunțurile urcă
// din nou înaintea răspunsului, vizitatorul așteaptă iar cât ține cea mai
// înceată legătură, pentru o cerere care s-a salvat deja.
$srcCerere  = file_get_contents(__DIR__ . '/../api/routes/requests.php');
$pozRaspuns = strpos($srcCerere, 'json_out_early(');
$pozAnunt   = strpos($srcCerere, 'notify_new_request(');
check('cererea de pe site primește răspuns înainte de trimiterea anunțurilor',
    $pozRaspuns !== false && $pozAnunt !== false && $pozRaspuns < $pozAnunt,
    $pozRaspuns === false
        ? 'nu mai există json_out_early() în requests.php'
        : ($pozAnunt === false
            ? 'nu mai există notify_new_request() în requests.php'
            : 'anunțul stă înaintea răspunsului'));

/* ------------------------------------ numele firmei în antetul e-mailului -- */

// „Numele firmei" din Setări promitea că apare în antetul mesajelor, dar
// expeditorul era scris în cod: firma își schimba denumirea și e-mailurile
// plecau tot cu cea veche. Antetul nu se poate citi prin HTTP — mail() e
// singura ieșire — deci luăm funcția direct din sursă.
echo "\n=== numele expeditorului ===\n";

$GP_SETARI = [];
function setting(string $key, string $default = ''): string
{
    global $GP_SETARI;
    $v = $GP_SETARI[$key] ?? '';
    return $v === '' ? $default : $v;
}

$srcNotify = file_get_contents(__DIR__ . '/../api/lib/notify.php');

check('antetul From ia numele din Setări, nu din cod',
    preg_match("/'From: '\s*\.\s*email_from_name\(\)/", $srcNotify) === 1
    && !str_contains($srcNotify, "'From: Golden Pastry <'"),
    'antetul e construit altfel');

if (preg_match('/function email_from_name\(\): string\s*\{.*?\n\}/s', $srcNotify, $m)) {
    eval($m[0]);

    $GP_SETARI = [];
    check('fără setare, rămâne numele de până acum',
        str_contains(email_from_name(), 'Golden Pastry'), email_from_name());

    $GP_SETARI = ['company_name' => 'Patiseria Aurie SRL'];
    check('denumirea scrisă în Setări ajunge în antet',
        str_contains(email_from_name(), 'Patiseria Aurie SRL'), email_from_name());

    // Un antet cu diacritice brute e refuzat de unele servere, iar atunci nu
    // pleacă nici anunțul comenzii noi.
    $GP_SETARI = ['company_name' => 'Cofetăria Bucureșteană'];
    $codat = email_from_name();
    check('diacriticele se codifică, ca antetul să fie acceptat',
        str_starts_with($codat, '=?UTF-8?B?')
        && base64_decode(substr($codat, 10, -2)) === 'Cofetăria Bucureșteană', $codat);

    // Un nume cu salt de linie ar putea adăuga un antet pe care nu l-a cerut
    // nimeni — de exemplu o copie ascunsă către altcineva.
    $GP_SETARI = ['company_name' => "Firma\r\nBcc: altcineva@exemplu.ro"];
    $curat = email_from_name();
    check('un salt de linie din nume nu poate adăuga alt antet',
        !str_contains($curat, "\r") && !str_contains($curat, "\n"), $curat);
} else {
    check('email_from_name() poate fi găsită în notify.php', false, 'tiparul funcției s-a schimbat');
}

/* ---------------------------------------------------------- rezultat -- */

echo "\n====================================================\n";
echo "$pass trecute · $fail eșuate\n";
if ($failures) {
    echo "\nEșecuri:\n";
    foreach ($failures as $f) {
        echo "  · $f\n";
    }
}
exit($fail ? 1 : 0);
