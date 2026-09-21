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
 * Promisiunea scrisă sub casetele de fișier din Catalog („maximum 5 MB") se
 * socotește de aici. E singurul loc în care cifra asta se schimbă.
 */
const POZA_MAX_OCTETI = 5 * 1024 * 1024;

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
