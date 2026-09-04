<?php
/**
 * Router pentru serverul de dezvoltare încorporat în PHP.
 *
 * Serverul din `php -S` nu citește .htaccess, deci rescrierea către
 * api/index.php se face aici. Folosit doar la testare locală — pe Laragon și
 * pe cPanel se folosește .htaccess.
 *
 * Pornire:
 *     php -S 127.0.0.1:8100 -t . .claude/dev-server.php
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = __DIR__ . '/..';

/**
 * Numele fișierului în care `image-slot.js` ține pozele puse direct în pagină.
 * E scris o singură dată aici și verificat la scriere: pagina nu poate cere
 * salvarea altui fișier.
 */
const SLOTS_FILE = '.image-slots.state.json';

/** Peste atât nu mai e o pagină cu poze, ci o greșeală. */
const SLOTS_MAX = 12 * 1024 * 1024;

// Cererile către API merg la router, indiferent de sub-cale.
if (str_starts_with($path, '/api')) {
    $rest = substr($path, 4);

    // Fișierele reale din api/ (de pildă setup.php) se servesc direct.
    $direct = $root . '/api' . $rest;
    if ($rest !== '' && $rest !== '/' && is_file($direct) && !str_ends_with($direct, 'config.php')) {
        $_SERVER['SCRIPT_NAME']     = '/api' . $rest;
        $_SERVER['SCRIPT_FILENAME'] = $direct;
        require $direct;
        return true;
    }

    // Restul intră în dispecerul de rute. SCRIPT_NAME trebuie setat manual:
    // index.php deduce din el prefixul sub care este montat API-ul.
    $_SERVER['SCRIPT_NAME']     = '/api/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/api/index.php';
    require $root . '/api/index.php';
    return true;
}

/*
 * Salvarea pozelor puse direct în pagină.
 *
 * `image-slot.js` ține poza lângă pagină, în SLOTS_FILE, dar o scrie printr-o
 * singură funcție: `window.omelette.writeFile`. Aceea există numai în editorul
 * în care au fost desenate paginile. Pe orice alt server funcția lipsește, iar
 * `save()` din image-slot.js iese tăcut — poza se vede până la reîncărcare și
 * apoi dispare, fără nimic în consolă. Așa s-au pierdut pozele puse pe
 * „Despre noi”.
 *
 * Aici punem funcția la loc, dar numai pe serverul de test: pagina trimite
 * fișierul înapoi, noi îl scriem pe disc, iar de acolo îl ia
 * `build-deploy.mjs` în pachetul de publicare.
 *
 * Ruta nu ajunge niciodată pe site: routerul acesta e folosit doar de
 * `php -S`, nu se copiază în `deploy/`, iar pe cPanel rescrierile le face
 * .htaccess. În plus scrierea e refuzată oricui nu vine de pe calculatorul
 * acesta și acceptă un singur nume de fișier.
 */
if ($path === '/__slots') {
    header('Content-Type: text/plain; charset=utf-8');

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip !== '127.0.0.1' && $ip !== '::1') {
        http_response_code(403);
        echo "403 — sloturile se scriu doar de pe calculatorul local\n";
        return true;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo "405 — doar POST\n";
        return true;
    }

    $corp = file_get_contents('php://input');
    if ($corp === false || $corp === '' || strlen($corp) > SLOTS_MAX) {
        http_response_code(413);
        echo "413 — corp gol sau peste " . SLOTS_MAX . " octeți\n";
        return true;
    }

    // Trebuie să fie exact forma pe care o citește image-slot.js: un obiect cu
    // o intrare per slot. Orice altceva ar șterge pozele care există deja.
    $date = json_decode($corp, true);
    if (!is_array($date) || array_is_list($date)) {
        http_response_code(400);
        echo "400 — nu e un obiect JSON cu sloturi\n";
        return true;
    }

    // Scriere prin fișier temporar: o întrerupere la jumătate ar lăsa altfel
    // un JSON rupt, iar la reîncărcare s-ar pierde și pozele dinainte.
    $tinta = $root . '/' . SLOTS_FILE;
    $tmp   = $tinta . '.tmp';
    if (file_put_contents($tmp, $corp) === false || !rename($tmp, $tinta)) {
        @unlink($tmp);
        http_response_code(500);
        echo "500 — nu am putut scrie " . SLOTS_FILE . "\n";
        return true;
    }

    http_response_code(204);
    return true;
}

// Orice altceva: fișier static, dacă există.
$file = realpath($root . urldecode($path));
if ($file !== false && is_file($file)) {
    // Paginile desenate primesc, doar aici, funcția de scriere care le lipsește.
    if (str_ends_with($file, '.dc.html')) {
        $html = (string) file_get_contents($file);
        $petec = <<<'JS'
<script>
/* Pus de serverul de test — vezi comentariul din .claude/dev-server.php.
   Fără el, o poză trasă într-un <image-slot> se pierde la reîncărcare. */
(function () {
  var o = window.omelette || (window.omelette = {});
  if (o.writeFile) return;
  o.writeFile = function (nume, text) {
    if (nume !== ".image-slots.state.json") {
      return Promise.reject(new Error("nume de fisier neasteptat: " + nume));
    }
    return fetch("/__slots", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: text
    }).then(function (r) {
      if (!r.ok) throw new Error("pozele nu s-au salvat, raspuns " + r.status);
      console.info("[sloturi] salvat pe disc, " + text.length + " caractere");
    });
  };
})();
</script>
JS;
        $poz = stripos($html, '</head>');
        header('Content-Type: text/html; charset=utf-8');
        echo $poz === false
            ? $petec . $html
            : substr($html, 0, $poz) . $petec . substr($html, $poz);
        return true;
    }
    return false;   // serverul încorporat îl trimite singur, cu tipul potrivit
}

// Pagina de start: lista artboard-urilor, ca la serverul din import.
if ($path === '/' || $path === '') {
    $pages = array_values(array_filter(scandir($root), static fn($f) => str_ends_with($f, '.dc.html')));
    sort($pages);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Golden Pastry — dev</title>';
    echo '<h1>Golden Pastry — server local</h1><ul>';
    foreach ($pages as $p) {
        echo '<li><a href="/' . rawurlencode($p) . '">' . htmlspecialchars($p, ENT_QUOTES) . '</a></li>';
    }
    echo '</ul><p><a href="/api/products">/api/products</a> · <a href="/api/setup.php">/api/setup.php</a></p>';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 — nu există: $path\n";
return true;
