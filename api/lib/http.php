<?php
/**
 * Ajutoare HTTP: răspunsuri JSON, CORS, citirea corpului cererii.
 */

declare(strict_types=1);

/** Oprește execuția cu un răspuns JSON. */
function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // API-ul nu trebuie memorat de browser sau de proxy-uri.
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Trimite răspunsul JSON, încheie conexiunea cu browserul și lasă scriptul să
 * meargă mai departe.
 *
 * Este pentru munca de după răspuns — astăzi, anunțurile despre o cerere nouă.
 * Ele pleacă pe rând, pe e-mail, pe WhatsApp și pe Telegram, iar fiecare
 * legătură poate sta cincisprezece secunde până renunță. Trimise înaintea
 * răspunsului, lăsau vizitatorul de pe site cu butonul „Se trimite…" apăsat o
 * jumătate de minut pentru o cerere care era deja scrisă în bază, iar procesul
 * PHP rămânea ocupat tot atâta: în timpul unei pene la furnizor, câțiva
 * vizitatori deodată umpleau procesele găzduirii și site-ul nu mai răspundea
 * nimănui.
 *
 * Ce se întâmplă după apel nu mai ajunge niciodată la client: nu mai scrie
 * nimic pentru el și nu mai chema fail(), fiindcă răspunsul a plecat. Un eșec
 * de acolo se vede doar în jurnalul serverului și în tabelul `notifications`.
 *
 * Spre deosebire de json_out(), funcția se întoarce: apelantul își încheie
 * singur treaba cu `exit`.
 */
function json_out_early($data, int $status = 200): void
{
    // Browserul închide conexiunea imediat ce a primit corpul. Fără linia asta
    // PHP ar opri scriptul chiar acolo, iar anunțul către laborator nu ar mai
    // pleca niciodată.
    ignore_user_abort(true);
    // Pe gazdele care numără timpul scurs, nu doar calculul, procesul ar putea
    // fi oprit tocmai în timpul anunțurilor: comanda ar rămâne nespusă, iar
    // rândul din `notifications` ar rămâne pe „pending", fără nicio eroare.
    @set_time_limit(60);

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // API-ul nu trebuie memorat de browser sau de proxy-uri.
    header('Cache-Control: no-store');
    // Conexiunea nu se mai refolosește: ce ar scrie scriptul după răspuns nu
    // are ce căuta lipit de cererea următoare de pe aceeași conexiune.
    header('Connection: close');
    // Comprimarea ar schimba numărul de octeți trimiși, iar lungimea anunțată
    // mai jos ar fi greșită: browserul ar aștepta octeți care nu mai vin.
    @ini_set('zlib.output_compression', '0');

    ob_start();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // Fără lungimea corpului, browserul nu știe unde se termină răspunsul și
    // așteaptă închiderea conexiunii — exact așteptarea pe care o eliminăm.
    header('Content-Length: ' . ob_get_length());

    // Se golesc toate nivelurile, nu doar cel deschis aici: pe găzduirile cu
    // `output_buffering` pornit din php.ini există unul deschis de la început,
    // iar corpul ar rămâne blocat în el până la sfârșitul scriptului.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();

    // Pe PHP-FPM și pe LiteSpeed — cazul obișnuit pe cPanel — conexiunea se
    // închide chiar aici, iar procesul rămâne singur cu treaba lui.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }

    // Fără niciuna dintre ele (mod_php), conexiunea rămâne deschisă până la
    // sfârșitul scriptului, dar browserul are deja corpul întreg și lungimea
    // lui, deci nu mai așteaptă. Bufferul de aici înghite orice s-ar mai
    // scrie, ca un mesaj scăpat de la spate să nu strice răspunsul trimis.
    ob_start(static function (): string {
        return '';
    });
}

/** Eroare uniformă: { error: { code, message } }. */
function fail(string $message, int $status = 400, string $code = ''): void
{
    json_out(['error' => ['code' => $code ?: (string) $status, 'message' => $message]], $status);
}

/**
 * Trimite antetele CORS, dacă originea cererii este în lista albă.
 * Originile necunoscute nu primesc antet, deci browserul le blochează.
 */
function cors(array $allowed): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Max-Age: 86400');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** Corpul JSON al cererii, ca tablou asociativ. */
function body(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $cached = [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail('Corpul cererii nu este JSON valid.', 400, 'bad_json');
    }
    return $cached = $data;
}

/** Câmp obligatoriu din corpul cererii. */
function need(array $src, string $key): string
{
    $v = trim((string) ($src[$key] ?? ''));
    if ($v === '') {
        fail("Câmpul „$key” este obligatoriu.", 422, 'missing_field');
    }
    return $v;
}

/**
 * Un număr venit din formular, verificat.
 *
 * `(float)` nu refuză nimic: pe un tablou nevid dă 1.0, pe `true` dă 1.0, pe
 * „trei" dă 0.0. Un câmp completat greșit nu era deci refuzat, ci înlocuit
 * tăcut cu altceva. Iar unul completat cu prea multe cifre trecea de noi și
 * pica abia în MySQL — 1264 „Out of range value" — adică „Eroare internă"
 * pentru omul din depozit, fără să afle ce câmp e de vină.
 *
 * Stă lângă `need()`, nu lângă `text_field()`: e ajutorul pereche pentru
 * câmpurile numerice ale oricărei rute, nu ceva al comenzilor.
 */
function num_field($v, string $eticheta, float $min, float $max, string $cod = 'bad_number'): float
{
    $numar = is_int($v) || is_float($v) || (is_string($v) && is_numeric(trim($v)));
    if (is_bool($v) || !$numar) {
        fail($eticheta . ' trebuie să fie un număr.', 422, $cod);
    }
    $n = (float) $v;
    if ($n < $min || $n > $max) {
        $scrie = static fn(float $x): string => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');
        fail(
            $eticheta . ' trebuie să fie între ' . $scrie($min) . ' și ' . $scrie($max) . '.',
            422,
            $cod
        );
    }
    return $n;
}

/**
 * Validează o dată AAAA-LL-ZZ; oprește cererea dacă e greșită.
 *
 * Stă în lib/, nu într-un fișier de rută: routerul încarcă doar ruta care se
 * potrivește, iar funcția este folosită și de comenzi, și de depozit.
 */
function valid_day(string $d): string
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) {
        fail('Dată invalidă: „' . $d . '”. Format așteptat: AAAA-LL-ZZ.', 422, 'bad_date');
    }
    return $d;
}

/**
 * Cât de departe în viitor se poate cere o comandă.
 *
 * Nu exista nicio margine în sus: nici formularul de pe site, nici cererea, nici
 * acceptarea. Cine tasta „3026" în loc de „2026" — o greșeală de o tastă —
 * primea o comandă adevărată, în starea „În așteptare", pe care niciun ecran
 * n-o mai vede: Panoul Laborator și Intrare arată fereastra azi..azi+6, iar
 * ruta șoferului o singură zi. Rămânea o comandă activă pe care n-o vedea și
 * n-o închidea nimeni, cu stocul ținut rezervat la nesfârșit.
 *
 * Un an acoperă cu mult tot ce comandă cineva din timp — o nuntă se rezervă cu
 * luni înainte, nu cu ani — și oprește greșeala de tastare la mia de ani.
 */
const GP_ORIZONT_ZILE = 365;

/**
 * Ziua de azi în ora patiseriei, nu a serverului.
 *
 * Fusul e scris aici, nu luat de la găzduire: patiseria, depozitul și zonele de
 * livrare sunt în București (vezi api/lib/settings.php), iar ora unui server de
 * cPanel poate fi oricare. Cu `gmdate()`, între 00:00 și 03:00 ora României —
 * tocmai orele în care laboratorul lucrează pentru livrarea de dimineață —
 * serverul scria și citea ziua de ieri. DateTimeZone ține singur socoteala
 * orei de vară.
 */
function today_local(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d');
}

/**
 * Fereastra în care se poate cere o comandă: [prima zi, ultima zi].
 *
 * O singură sursă de adevăr pentru toate cele patru locuri care o foloseau
 * altfel (sau deloc): formularul de pe site, `POST /requests`, acceptarea
 * cererii și `GET /lists`, de unde site-ul își ia marginile câmpului de dată.
 *
 * @return array{0: string, 1: string}
 */
function order_day_window(): array
{
    $azi = today_local();
    return [
        $azi,
        (new DateTimeImmutable($azi, new DateTimeZone('Europe/Bucharest')))
            ->modify('+' . GP_ORIZONT_ZILE . ' days')->format('Y-m-d'),
    ];
}

/**
 * Validează ziua unei comenzi: forma, apoi fereastra.
 *
 * Verificarea formei exista peste tot, cea a ferestrei nicăieri la trimitere —
 * doar la acceptare, și doar în jos. Clientul putea alege în calendar ziua de
 * ieri (câmpul n-avea `min`), pagina îi răspundea „Mulțumim! Cererea ta a
 * intrat la noi în platformă", cererea chiar intra în panou — iar acceptarea o
 * refuza apoi cu „ziua a trecut". Cererea rămânea blocată, iar cineva trebuia
 * să sune clientul ca s-o ia de la capăt. Ce nu se poate onora se refuză din
 * clipa în care e scris, cu ziua bună spusă în mesaj.
 */
function valid_order_day(string $d, string $eticheta = 'Ziua cerută'): string
{
    $d = valid_day($d);
    [$prima, $ultima] = order_day_window();

    if ($d < $prima) {
        fail(
            $eticheta . ', ' . $d . ', a trecut. Alege o zi începând cu azi, ' . $prima
              . ': o comandă pe o zi trecută nu ajunge pe niciun ecran din laborator.',
            422,
            'day_in_past'
        );
    }
    if ($d > $ultima) {
        fail(
            $eticheta . ', ' . $d . ', e prea departe. Ultima zi care se poate cere este '
              . $ultima . '.',
            422,
            'day_too_far'
        );
    }
    return $d;
}

/**
 * Doar marginea de sus a ferestrei: pentru comenzile scrise de personal.
 *
 * `POST /orders` și mutarea zilei din `PATCH /orders` primeau orice dată
 * validă ca formă — deci și „3026", tocmai greșeala pentru care există
 * GP_ORIZONT_ZILE. Comanda rămânea activă pe o zi pe care n-o vede niciun
 * ecran, cu stocul rezervat la nesfârșit. Marginea de jos NU se pune aici:
 * administratorul corectează și comenzi de ieri (o livrare consemnată târziu),
 * iar o comandă luată la telefon după miezul nopții e tot „de azi".
 */
function valid_day_not_too_far(string $d, string $eticheta = 'Ziua comenzii'): string
{
    $d = valid_day($d);
    [, $ultima] = order_day_window();
    if ($d > $ultima) {
        fail(
            $eticheta . ', ' . $d . ', e prea departe. Ultima zi care se poate cere este '
              . $ultima . '.',
            422,
            'day_too_far'
        );
    }
    return $d;
}

/** Adresa IP a clientului, ținând cont de proxy-ul din față (cPanel/Cloudflare). */
/**
 * Adresa clientului.
 *
 * Antetele „X-Forwarded-For" și „CF-Connecting-IP" sunt crezute DOAR dacă
 * cererea vine de la un proxy trecut în `trusted_proxies`. Altfel se folosește
 * adresa reală a conexiunii.
 *
 * Varianta de dinainte le credea de la oricine. Consecința: oricine putea
 * trimite un antet cu altă adresă la fiecare cerere și ocolea complet
 * limitarea încercărilor de autentificare — apărarea împotriva ghicirii
 * parolelor devenea o formalitate. În plus, jurnalul se putea umple cu adrese
 * inventate, inclusiv ale altcuiva.
 *
 * Pe o găzduire cPanel obișnuită cererile ajung direct la Apache, deci lista
 * rămâne goală și se folosește adresa conexiunii. Dacă pui site-ul în spatele
 * Cloudflare sau al altui proxy, treci adresele lui în `trusted_proxies`.
 */
function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    $trusted = (array) config('trusted_proxies', []);
    if ($remote !== '' && in_array($remote, $trusted, true)) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            $v = $_SERVER[$h] ?? '';
            if ($v === '') {
                continue;
            }
            // X-Forwarded-For poate conține o listă; prima valoare este clientul.
            $ip = trim(explode(',', $v)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}
