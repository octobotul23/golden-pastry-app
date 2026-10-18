<?php
/**
 * Golden Pastry — micșorarea pozelor urcate ÎNAINTE de micșorare (runda 2.7.6).
 *
 * De ce există. Micșorarea pozelor la urcare a intrat cu 2.4.2 (vezi
 * `api/lib/poze.php`). Cele 22 de poze ale catalogului au fost urcate pe 21
 * septembrie 2026, între 12:49 și 12:59, adică cu câteva ore ÎNAINTE: au rămas
 * cum au venit de pe telefon, 1792x2390 și 2,2-3,1 MB fiecare, 55 MB cu
 * totul. Măsurat pe site-ul viu pe 17 octombrie 2026, pe un telefon cu 4G slab:
 * pagina unui produs își arăta poza abia după 21,6 secunde. Pozele urcate după
 * 2.4.2 au 1600 de puncte pe latura lungă și câteva sute de KB — deci leacul nu
 * e o micșorare nouă, ci aceeași, trecută o dată și peste cele vechi.
 *
 * Îl pornește `actualizeaza-vps.sh`, la pasul „Pozele mari", ca utilizator al
 * serverului web (www-data), DUPĂ ce a copiat pozele din `--lista` lângă
 * celelalte copii de siguranță. Unealta nu face copii: o copie făcută de root
 * stă unde www-data n-are voie să scrie, iar un root care re-scrie fișiere
 * într-un folder pe care îl poate umple oricine are www-data e o ușă lăsată
 * deschisă.
 *
 * Se poate rula și de mână, de câte ori vrei: o poză micșorată are 1600 de
 * puncte, deci a doua oară nu mai e nimic de făcut.
 *
 *     php api/cron/micsoreaza-poze.php --poze=/var/www/gp/public_html/products
 *
 *     --uscat     spune ce AR micșora, fără să atingă nimic
 *     --lista     tipărește numai numele pozelor de micșorat, câte unul pe rând
 *                 (din ea face scriptul copia: pune deoparte EXACT ce se atinge)
 *
 * NUMAI DIN LINIA DE COMANDĂ, ca ceasul plăților de alături: cerut prin adresă,
 * răspunde 404 și nu face nimic (al doilea lacăt e `api/cron/.htaccess`).
 *
 * Codul de ieșire: 0 — a mers ori n-a avut nimic de făcut; 1 — cel puțin o poză
 * n-a putut fi micșorată ori PHP n-are GD; 2 — a fost chemat greșit.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/poze.php';

/* ------------------------------------------------------------ argumente -- */

$poze   = '';
$uscat  = false;
$lista  = false;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if ($arg === '--uscat') {
        $uscat = true;
    } elseif ($arg === '--lista') {
        $lista = true;
    } elseif (str_starts_with($arg, '--poze=')) {
        $poze = substr($arg, 7);
    } else {
        fwrite(STDERR, 'Nu cunosc „' . $arg . '”. Se primesc --poze=CALE, --uscat și --lista.' . "\n");
        exit(2);
    }
}

// Calea se cere pe față, nu se ghicește: pe server pozele stau unde spune
// `photo_dir` din config.php, iar scriptul de actualizare o are deja citită.
// O unealtă care ar căuta singură un folder „products" ar putea micșora pozele
// ALTEI instalări.
//
// Greșelile de chemare se spun pe ieșirea de erori: cu `--lista`, ieșirea
// obișnuită e citită de script ca listă de nume, iar o frază ajunsă acolo ar fi
// luată drept numele unei poze.
$poze = rtrim($poze, '/\\');
if ($poze === '') {
    fwrite(STDERR, "Lipsește --poze=CALE (folderul pozelor din config.php, „photo_dir”).\n");
    exit(2);
}
if (!is_dir($poze)) {
    fwrite(STDERR, 'Folderul pozelor nu există: ' . $poze . "\n");
    exit(2);
}

/* --------------------------------------------------------------- vorbe -- */

/** „1 poză", „2 poze", „20 de poze", „101 poze" — ca peste tot în aplicație. */
function mp_poze(int $n): string
{
    if ($n === 1) {
        return '1 poză';
    }
    $rest = $n % 100;
    $de   = $n > 0 && ($rest === 0 || $rest >= 20);

    return $n . ($de ? ' de poze' : ' poze');
}

/**
 * „1 poză e deja mică" / „3 poze sunt deja mici"; cu `$trecut`, „era" / „erau".
 * Acordul se face aici, o dată: scris pe loc, raportul ieșea cu „1 poză erau".
 */
function mp_mici(int $n, bool $trecut): string
{
    if ($n === 1) {
        return '1 poză ' . ($trecut ? 'era' : 'e') . ' deja mică';
    }

    return mp_poze($n) . ($trecut ? ' erau' : ' sunt') . ' deja mici';
}

/** Pricina din `poza_micsoreaza()`, spusă pentru omul care citește raportul. */
function mp_pricina(string $pricina): string
{
    return match ($pricina) {
        'gd_lipseste'            => 'PHP n-are GD (pachetul php-gd)',
        'nu_pot_citi_marimea'    => 'nu i-am putut citi mărimea',
        'nu_incape_in_memorie'   => 'nu încape în memoria îngăduită lui PHP',
        'gd_nu_a_putut_deschide' => 'fișierul e stricat ori nu e poza care pare',
        'gd_fara_memorie'        => 'n-a ajuns memoria pentru copia micșorată',
        'nu_s_a_putut_scrie'     => 'n-am putut scrie lângă ea (drepturile folderului?)',
        'nu_a_ajutat'            => 'micșorată ar fi ieșit mai grea, deci a rămas cum era',
        'nu_s_a_putut_muta'      => 'n-am putut pune copia micșorată în locul ei',
        default                  => $pricina,
    };
}

/* -------------------------------------------------------------- căutare -- */

// Numai fișierele de la rădăcina folderului, fără subfoldere și fără legături:
// acolo le pune urcarea din panou (`prod-<id>-<cod>.<ext>`), iar o legătură
// poate duce oriunde pe disc. Felul se ia din antetul fișierului, nu din nume,
// la fel ca la urcare. Un nume cu semne de control (un rând nou, un TAB) nu se
// atinge deloc: scriptul citește lista rând cu rând, deci o poză cu rând nou
// în nume n-ar intra întreagă în copie — și nimic nu se micșorează fără copie.
$mari     = [];
$deMici   = 0;
$nume     = scandir($poze) ?: [];
sort($nume, SORT_STRING);
foreach ($nume as $n) {
    if ($n === '' || $n[0] === '.' || str_ends_with($n, '.nou') || preg_match('/[\x00-\x1F\x7F]/', $n)) {
        continue;
    }
    $cale = $poze . '/' . $n;
    if (is_link($cale) || !is_file($cale)) {
        continue;
    }
    $info = @getimagesize($cale);
    $ext  = match ($info[2] ?? 0) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        default        => null,
    };
    if ($ext === null) {
        continue;
    }
    if (max((int) $info[0], (int) $info[1]) <= POZA_LATURA_MAX) {
        $deMici++;
        continue;
    }
    $mari[] = ['nume' => $n, 'cale' => $cale, 'ext' => $ext,
               'lat' => (int) $info[0], 'inalt' => (int) $info[1], 'octeti' => (int) filesize($cale)];
}

if ($lista) {
    // Fără GD nu se poate micșora nimic, deci nu e nimic de pus deoparte. O
    // listă tipărită aici făcea scriptul să copieze pozele degeaba: rularea de
    // după refuza fără rândul „Micșorate:", copia de prisos nu se mai ștergea,
    // și se aduna câte una la fiecare actualizare.
    if ($mari !== [] && !poza_poate_micsora()) {
        fwrite(STDERR, 'PHP n-are GD (pachetul php-gd), deci nu se poate micșora nimic: '
            . mp_poze(count($mari)) . (count($mari) === 1 ? ' rămâne cum e' : ' rămân cum sunt') . ".\n");
        exit(1);
    }
    foreach ($mari as $p) {
        echo $p['nume'], "\n";
    }
    exit(0);
}

$totalDe = array_sum(array_column($mari, 'octeti'));

if ($mari === []) {
    echo 'Nicio poză mai mare de ' . POZA_LATURA_MAX . ' de puncte'
        . ($deMici > 0 ? ': ' . mp_mici($deMici, false) : '') . ".\n";
    exit(0);
}

if ($uscat) {
    foreach ($mari as $p) {
        echo '  ' . $p['nume'] . '   ' . $p['lat'] . '×' . $p['inalt'] . ' · '
            . poza_marime_text($p['octeti']) . "  — s-ar micșora\n";
    }
    echo 'S-ar micșora ' . mp_poze(count($mari)) . ' (' . poza_marime_text($totalDe) . ' cu totul)'
        . ($deMici > 0 ? '; ' . mp_mici($deMici, false) : '')
        . ". N-am atins nimic (--uscat).\n";
    exit(0);
}

if (!poza_poate_micsora()) {
    echo 'PHP n-are GD (pachetul php-gd), deci n-am micșorat nimic: '
        . mp_poze(count($mari)) . (count($mari) === 1 ? ' a rămas cum era' : ' au rămas cum erau') . ".\n";
    exit(1);
}

/* ------------------------------------------------------------ micșorare -- */

$facute    = 0;
$totalDupa = 0;
$picate    = 0;
foreach ($mari as $p) {
    // Un `.nou` rămas de la o rulare tăiată la mijloc ar fi luat drept loc de
    // scris; se curăță înainte, ca micșorarea să înceapă de la zero.
    if (file_exists($p['cale'] . '.nou') || is_link($p['cale'] . '.nou')) {
        @unlink($p['cale'] . '.nou');
    }
    $r = poza_micsoreaza($p['cale'], $p['ext']);
    if ($r['facut']) {
        $facute++;
        $totalDupa += $r['octetiLa'];
        echo '  ' . $p['nume'] . '   ' . $r['de'][0] . '×' . $r['de'][1] . ' · ' . poza_marime_text($r['octetiDe'])
            . '  →  ' . $r['la'][0] . '×' . $r['la'][1] . ' · ' . poza_marime_text($r['octetiLa']) . "\n";
    } else {
        // „nu_a_ajutat" nu e o cădere: poza e întreagă și oricum mai mică așa.
        if ($r['pricina'] !== 'nu_a_ajutat') {
            $picate++;
        }
        $totalDupa += $p['octeti'];
        echo '  ' . $p['nume'] . '   neatinsă: ' . mp_pricina($r['pricina']) . "\n";
    }
}

echo 'Micșorate: ' . $facute . ' din ' . mp_poze(count($mari))
    . ($facute > 0 ? ' (' . poza_marime_text($totalDe) . ' → ' . poza_marime_text($totalDupa) . ' cu totul)' : '')
    . ($deMici > 0 ? '; ' . mp_mici($deMici, true) : '')
    . ".\n";

exit($picate > 0 ? 1 : 0);
