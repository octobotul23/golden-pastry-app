<?php

/**
 * Golden Pastry — mărimea fotografiilor, într-un singur loc.
 *
 * Fișierul n-are nicio dependență, dinadins: îl împrumută și `api/verifica.php`,
 * care rulează fără bază de date, fără sesiune și fără celelalte biblioteci.
 *
 * DOUĂ PRAGURI ALE SERVERULUI, NU UNUL, și mint deosebit — măsurat, nu
 * presupus, cu două servere de probă și un fișier de 3 MB:
 *
 *   - peste `upload_max_filesize`, cererea ajunge întreagă la cod: `$_POST` e
 *     acolo, iar `$_FILES['photo']['error']` e `1` (UPLOAD_ERR_INI_SIZE);
 *   - peste `post_max_size`, PHP aruncă TOT corpul înainte să ruleze un rând
 *     din noi: `$_POST` și `$_FILES` ies amândouă goale, de parcă n-ar fi ales
 *     nimeni niciun fișier. Singurul martor rămas e `CONTENT_LENGTH`.
 *
 * Al doilea e cel viclean: fără el, panoul îi spunea „Nu ai ales niciun
 * fișier" tocmai omului care alesese unul.
 */

/**
 * Cât îngăduie PANOUL.
 *
 * Promisiunea scrisă sub casetele de fișier din Catalog se socotește de aici.
 * E singurul loc în care cifra asta se schimbă.
 *
 * 10 MB de la 21 septembrie 2026 (hotărârea patronului), în sus de la 5 MB —
 * dar cifra singură ar fi fost o pagubă, nu o înlesnire: poza se punea pe disc
 * așa cum venea, deci cât urcă patronul, atât descarcă fiecare client. Măsurat
 * în ziua hotărârii: cele 22 de poze de atunci aveau 84 KB una (mijlocia),
 * tot catalogul 2,2 MB. O poză de telefon are 4-12 MB — de o sută de ori mai
 * mult. De-aia cifra urcă ÎMPREUNĂ cu micșorarea de mai jos, nu înaintea ei.
 */
const POZA_MAX_OCTETI = 10 * 1024 * 1024;

/**
 * Latura lungă peste care poza se micșorează, în puncte.
 *
 * Magazinul arată poza în carduri — 16/10, 4/3 ori 1/1 —, late de cel mult
 * vreo 500 de puncte pe un ecran mare. 1600 e dublu față de ce cere un ecran
 * cu puncte dese, deci poza rămâne limpede oriunde, iar fișierul cade de la
 * megaocteți la sute de kiloocteți.
 */
const POZA_LATURA_MAX = 1600;

/**
 * Calitatea la re-scriere, pentru JPEG și WebP.
 */
const POZA_CALITATE = 82;

/**
 * Plicul în care călătorește fișierul: hotarele multipart, antetele lui și
 * câmpul `latura`.
 *
 * `post_max_size` cântărește CORPUL ÎNTREG, nu fișierul, deci o poză exact cât
 * pragul tot nu intră. Măsurat pe cererea panoului: sub 400 de octeți. Ținem
 * 4 KB, ca marginea să nu fie niciodată prea strâmtă.
 */
const POZA_PLIC_OCTETI = 4096;

/**
 * „6M", „8192K", „2G", „-1" → octeți.
 *
 * `-1`, `0` și șirul gol înseamnă „fără margine" la PHP, nu „nimic nu trece".
 */
function poza_octeti_din_ini(string $val): int
{
    $val = trim($val);
    if ($val === '' || $val === '-1' || $val === '0') {
        return PHP_INT_MAX;
    }
    $n = (int) $val;
    return match (strtolower(substr($val, -1))) {
        'g'     => $n * 1024 * 1024 * 1024,
        'm'     => $n * 1024 * 1024,
        'k'     => $n * 1024,
        default => $n,
    };
}

/**
 * Cele două praguri ale serverului, și cel care leagă cu adevărat mâinile.
 *
 * @return array{upload:int, post:int, efectiv:int}
 */
function poza_limite_server(): array
{
    $upload  = poza_octeti_din_ini((string) ini_get('upload_max_filesize'));
    $post    = poza_octeti_din_ini((string) ini_get('post_max_size'));
    $dinPost = $post === PHP_INT_MAX ? PHP_INT_MAX : max(0, $post - POZA_PLIC_OCTETI);

    return ['upload' => $upload, 'post' => $post, 'efectiv' => min($upload, $dinPost)];
}

/** Serverul primește cel puțin cât promite panoul? */
function poza_server_incape(): bool
{
    return poza_limite_server()['efectiv'] >= POZA_MAX_OCTETI;
}

/** „5 MB", „1,5 MB", „512 KB" — cifre de citit, nu de socotit. */
function poza_marime_text(int $octeti): string
{
    if ($octeti >= PHP_INT_MAX) {
        return 'fără margine';
    }
    if ($octeti >= 1073741824) {
        $gb = rtrim(rtrim(number_format($octeti / 1073741824, 1, '.', ''), '0'), '.');
        return str_replace('.', ',', $gb) . ' GB';
    }
    if ($octeti >= 1048576) {
        $mb = rtrim(rtrim(number_format($octeti / 1048576, 1, '.', ''), '0'), '.');
        return str_replace('.', ',', $mb) . ' MB';
    }
    return max(1, (int) round($octeti / 1024)) . ' KB';
}

/** Unde se ridică limita serverului — aceeași vorbă în toate mesajele. */
function poza_unde_se_ridica(): string
{
    return 'în „api/.htaccess", rândurile „upload_max_filesize" și „post_max_size".';
}

/**
 * Vorba pentru „poza e prea mare".
 *
 * UNA SINGURĂ pentru amândouă pragurile — al panoului și al serverului —,
 * fiindcă omul vede același lucru: poza n-a intrat. Ce le deosebește e CINE a
 * spus nu, și asta se SOCOTEȘTE, nu se scrie cu mâna: cât timp serverul
 * primește măcar cât promite panoul, vina e a fișierului și se cere doar
 * micșorat; când serverul e mai strâmt decât promisiunea, i se spune pe față,
 * cu cifra lui cu tot.
 *
 * Urmarea e că mesajul se îndreaptă singur: în ziua în care limita serverului
 * urcă, propoziția a doua dispare fără să umble nimeni la ea. Și pe dos: dacă
 * limita cade vreodată la loc, mesajul o spune de la prima poză, în loc să
 * dea vina pe fișier.
 */
function poza_mesaj_prea_mare(): string
{
    $limite = poza_limite_server();
    $promis = poza_marime_text(POZA_MAX_OCTETI);

    if ($limite['efectiv'] >= POZA_MAX_OCTETI) {
        return 'Fotografia depășește ' . $promis . '. Micșoreaz-o și încearcă din nou.';
    }

    $are = poza_marime_text($limite['efectiv']);

    return 'Serverul primește acum fotografii de cel mult ' . $are . ', deși panoul îți promite '
        . $promis . '. Micșorează poza sub ' . $are . ', sau ridică limita serverului — '
        . poza_unde_se_ridica();
}

/**
 * A aruncat PHP corpul cererii înainte să apucăm noi să-l vedem?
 *
 * Trei fapte deodată, și toate trei trebuie să fie adevărate: n-a venit niciun
 * fișier, n-a venit NICIUN câmp (aici e deosebirea față de „n-am ales poza",
 * unde câmpul `latura` sosește cuminte), și browserul a anunțat un corp mai
 * mare decât pragul. A treia le ține pe primele două de rău: o cerere mică și
 * strâmbă — un JSON trimis din greșeală pe ruta asta — n-are voie să fie luată
 * drept poză pierdută.
 *
 * Judecata stă aici, nu în rută, ca să poată fi cântărită fără server: tabelul
 * ei întreg e în .claude/test-poze-limita.mjs.
 */
function poza_corp_aruncat(?array $fisier, array $campuri, int $lungime): bool
{
    return $fisier === null && $campuri === [] && $lungime > poza_limite_server()['post'];
}

/**
 * Vorba pentru corpul aruncat de PHP înainte de orice cod al nostru.
 *
 * Aici nu se poate spune „fișierul are atât", fiindcă fișierul n-a ajuns:
 * singurul număr care a supraviețuit e cât a anunțat browserul că trimite.
 */
function poza_mesaj_corp_aruncat(int $octetiCerere): string
{
    $limite = poza_limite_server();

    return 'Poza n-a ajuns deloc la server: cererea avea ' . poza_marime_text($octetiCerere)
        . ', iar serverul taie orice cerere peste ' . poza_marime_text($limite['post'])
        . ' înainte să apuce să citească ceva din ea. Micșorează poza, sau ridică limita'
        . ' serverului — ' . poza_unde_se_ridica();
}

/* ═════════════════════════════════════════════════════════════════════════
 * MICȘORAREA
 *
 * Până pe 21 septembrie 2026 poza se punea pe disc așa cum venea:
 * `move_uploaded_file` și atât. Cât timp panoul îngăduia 5 MB, paguba era
 * mărginită. Cu 10 MB nu mai e: o poză de telefon are 4000 de puncte pe latură
 * și 4-12 MB, iar magazinul o trimite ÎNTREAGĂ fiecărui client care deschide
 * catalogul de pe date mobile. Cardul în care o arată e lat de vreo 500 de
 * puncte. Măsurat în ziua hotărârii: cele 22 de poze de atunci aveau 84 KB una
 * (mijlocia), tot catalogul 2,2 MB.
 *
 * Deci cifra urcă ÎMPREUNĂ cu micșorarea, niciodată înaintea ei.
 * ═══════════════════════════════════════════════════════════════════════ */

/** Poate casa micșora poze? GD e singura unealtă folosită aici. */
function poza_poate_micsora(): bool
{
    return extension_loaded('gd');
}

/**
 * Întoarcerea scrisă în EXIF de telefon: 1 = dreaptă, 3 = cu susul în jos,
 * 6 și 8 = culcată pe o parte.
 *
 * Telefoanele nu rotesc punctele, scriu doar un număr în antet, iar browserul
 * îl ascultă. GD nu-l ascultă și nici nu-l păstrează — deci o poză re-scrisă
 * fără rotirea asta ar ajunge pe site culcată, deși în panou arăta dreaptă.
 * Numărul se citește ÎNAINTE de re-scriere, fiindcă după ea nu mai există.
 */
function poza_orientare(string $cale): int
{
    if (!function_exists('exif_read_data')) {
        return 1;
    }
    $date = @exif_read_data($cale);
    $o    = (int) ($date['Orientation'] ?? 1);

    return ($o >= 1 && $o <= 8) ? $o : 1;
}

/**
 * Încape desfacerea pozei în memoria îngăduită?
 *
 * GD ține fiecare punct pe 4 octeți, oricât de bine ar fi împachetat fișierul:
 * o poză de 8000x6000 cere 192 MB numai ca original, iar `memory_limit`
 * implicit pe Ubuntu e 128M. Peste el, PHP moare cu eroare fatală ÎNAINTE să
 * apuce să scrie vreun răspuns — omul vede o pagină albă, nu un mesaj, și n-are
 * de unde ști de ce. De-aia socoteala se face înainte, iar când nu încape, poza
 * rămâne așa cum a venit: mai grea, dar întreagă și cu o pricină de spus.
 */
function poza_incape_in_memorie(int $lat, int $inalt): bool
{
    $limita = poza_octeti_din_ini((string) ini_get('memory_limit'));
    if ($limita >= PHP_INT_MAX) {
        return true;
    }
    // Originalul, plus copia micșorată, plus o margine pentru bibliotecă.
    $cerut = ($lat * $inalt * 4) + (POZA_LATURA_MAX * POZA_LATURA_MAX * 4);

    return $cerut < ($limita - memory_get_usage(true)) * 0.8;
}

/**
 * Micșorează poza DE PE DISC, la locul ei, și spune ce a făcut.
 *
 * Nu aruncă și nu oprește nimic: o poză care nu poate fi micșorată rămâne așa
 * cum a venit. Urcarea a reușit oricum — fișierul e pe disc, rândul din bază e
 * scris —, iar `pricina` spune de ce n-a mers, pentru raport.
 *
 * Fișierul nou se scrie ALĂTURI și abia apoi ia locul celui vechi: o cădere la
 * mijlocul scrierii n-are voie să lase în urmă o poză jumătate.
 *
 * @return array{facut:bool, pricina:string, de:array{0:int,1:int}, la:array{0:int,1:int}, octetiDe:int, octetiLa:int}
 */
function poza_micsoreaza(string $cale, string $ext): array
{
    $r = [
        'facut'    => false,
        'pricina'  => '',
        'de'       => [0, 0],
        'la'       => [0, 0],
        'octetiDe' => (int) @filesize($cale),
        'octetiLa' => 0,
    ];

    if (!poza_poate_micsora()) {
        $r['pricina'] = 'gd_lipseste';
        return $r;
    }

    $info = @getimagesize($cale);
    if (!is_array($info)) {
        $r['pricina'] = 'nu_pot_citi_marimea';
        return $r;
    }
    $lat     = (int) $info[0];
    $inalt   = (int) $info[1];
    $r['de'] = [$lat, $inalt];
    $r['la'] = [$lat, $inalt];

    // POZA MICĂ SE LASĂ ÎN PACE, și întoarcerea din EXIF e chiar pricina: cât
    // timp n-o atingem, antetul rămâne pe fișier și browserul o întoarce
    // singur, cum face azi. Abia re-scrierea pierde antetul, deci abia atunci
    // trebuie coaptă rotirea în puncte.
    if (max($lat, $inalt) <= POZA_LATURA_MAX) {
        $r['pricina'] = 'era_deja_mica';
        return $r;
    }

    if (!poza_incape_in_memorie($lat, $inalt)) {
        $r['pricina'] = 'nu_incape_in_memorie';
        return $r;
    }

    $src = match ($ext) {
        'jpg'   => @imagecreatefromjpeg($cale),
        'png'   => @imagecreatefrompng($cale),
        'webp'  => @imagecreatefromwebp($cale),
        default => false,
    };
    if (!$src) {
        $r['pricina'] = 'gd_nu_a_putut_deschide';
        return $r;
    }

    // Rotirea, numai la JPEG: EXIF trăiește numai acolo. Cele patru întoarceri
    // oglindite (2, 4, 5, 7) se lasă neatinse — sunt rare, iar o oglindire pusă
    // greșit strică poza mai rău decât o lasă o rotire nefăcută.
    $orient = $ext === 'jpg' ? poza_orientare($cale) : 1;
    if (in_array($orient, [3, 6, 8], true)) {
        $unghi = match ($orient) { 3 => 180, 6 => -90, default => 90 };
        $rotit = @imagerotate($src, $unghi, 0);
        if ($rotit !== false) {
            imagedestroy($src);
            $src   = $rotit;
            $lat   = imagesx($src);
            $inalt = imagesy($src);
        }
    }

    $scara = POZA_LATURA_MAX / max($lat, $inalt);
    $nlat  = max(1, (int) round($lat * $scara));
    $ninal = max(1, (int) round($inalt * $scara));

    $dst = @imagecreatetruecolor($nlat, $ninal);
    if (!$dst) {
        imagedestroy($src);
        $r['pricina'] = 'gd_fara_memorie';
        return $r;
    }

    // Străveziul se păstrează la PNG și WebP; fără rândurile astea, fundalul
    // străveziu iese negru.
    if ($ext === 'png' || $ext === 'webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nlat, $ninal, $lat, $inalt);

    $langa = $cale . '.nou';
    $scris = match ($ext) {
        'jpg'   => @imagejpeg($dst, $langa, POZA_CALITATE),
        'png'   => @imagepng($dst, $langa, 6),
        'webp'  => @imagewebp($dst, $langa, POZA_CALITATE),
        default => false,
    };
    imagedestroy($src);
    imagedestroy($dst);

    if (!$scris || !is_file($langa)) {
        @unlink($langa);
        $r['pricina'] = 'nu_s_a_putut_scrie';
        return $r;
    }

    // Dacă munca n-a ajutat, nu se face. Se poate întâmpla la un PNG desenat,
    // unde împachetarea celui vechi era mai bună decât a noastră — iar atunci
    // originalul e și mai mic, și mai limpede.
    $noi = (int) filesize($langa);
    if ($noi >= $r['octetiDe'] && $r['octetiDe'] > 0) {
        @unlink($langa);
        $r['pricina'] = 'nu_a_ajutat';
        return $r;
    }

    if (!@rename($langa, $cale)) {
        @unlink($langa);
        $r['pricina'] = 'nu_s_a_putut_muta';
        return $r;
    }

    $r['facut']    = true;
    $r['la']       = [$nlat, $ninal];
    $r['octetiLa'] = $noi;

    return $r;
}
