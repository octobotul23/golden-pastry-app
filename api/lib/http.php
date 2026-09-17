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
    echo json_body($data);
    exit;
}

/**
 * Scrie datele ca JSON, fără să iasă vreodată cu mâna goală.
 *
 * `json_encode` întoarce `false` — nu un text — dacă i se dă un octet care nu e
 * UTF-8 valid, iar `echo false` scrie ȘIRUL GOL. Un singur octet stricat într-un
 * șir de interogare scris de mână (`?day=%FF`) ajungea în mesajul de refuz, iar
 * omul primea un `422` cu corpul gol: `res.json()` din pagină arunca, și în loc
 * de „Dată invalidă" apărea eroarea generică a paginii. Nu e o poartă spartă, e
 * un refuz care nu se aude.
 *
 * `JSON_INVALID_UTF8_SUBSTITUTE` înlocuiește octeții aceia cu semnul de
 * întrebare al Unicodului și lasă refuzul să ajungă întreg. Orice alt motiv de
 * eșec — adâncime, recursie — iese tot ca JSON, nu ca tăcere.
 */
function json_body($data): string
{
    $txt = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($txt === false) {
        http_response_code(500);
        return '{"error":{"code":"encode_error","message":"Răspunsul nu a putut fi scris."}}';
    }
    return $txt;
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
    echo json_body($data);
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
/**
 * Corpul cererii, ca tablou.
 *
 * `$pus` are UN SINGUR folositor, `api/routes/telegram.php`, și aceeași
 * pricină ca `act_as()` din api/lib/auth.php: pe drumul acela corpul adevărat
 * al cererii e mesajul trimis de Telegram — `update_id`, `callback_query` — iar
 * ruta prin care trece fapta așteaptă cu totul altceva. Fără linia asta, ruta
 * ar fi citit tabloul lui Telegram și ar fi luat drept „ziua cerută" ce găsea
 * acolo, adică nimic; ar fi mers, din întâmplare, prin valorile ei de rezervă —
 * iar în ziua în care Telegram ar fi adăugat un câmp nou cu un nume nepotrivit,
 * s-ar fi stricat fără să lege cineva una de alta.
 *
 * Tabloul gol e o valoare bună și dorită: „nu trimit niciun câmp, ia-le pe
 * toate din cerere, cum sunt". De aceea `$pus` e un `?array` verificat cu
 * `!== null`, nu un tablou verificat cu `if ($pus)`.
 */
function body(?array $pus = null): array
{
    static $cached = null;
    if ($pus !== null) {
        return $cached = $pus;
    }
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
    // Forma se cerne ÎNAINTE de `DateTime`, nu după.
    //
    // Pe PHP 8, `DateTime::createFromFormat` nu mai întoarce `false` când textul
    // are un octet zero în el: ARUNCĂ `ValueError`. Garda `!$dt` de mai jos a
    // fost scrisă pe purtarea din PHP 7 și n-avea ce prinde, așa că un `"\0"`
    // strecurat în ziua cererii ieșea ca „Eroare internă" — de pe formularul
    // public de cereri, adică fără niciun cont.
    //
    // Sita de aici e și mai strictă decât trebuie, dinadins: ziua vine
    // întotdeauna dintr-un `<input type="date">` sau dintr-o rută care o scrie
    // singură, deci orice altă formă e deja o greșeală.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        fail('Dată invalidă: „' . text_de_aratat($d) . '”. Format așteptat: AAAA-LL-ZZ.', 422, 'bad_date');
    }
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) {
        fail('Dată invalidă: „' . $d . '”. Format așteptat: AAAA-LL-ZZ.', 422, 'bad_date');
    }
    return $d;
}

/**
 * „de" dinaintea substantivului, după regula numeralului românesc.
 *
 * 19 caractere, dar 20 DE caractere; 101 caractere, dar 120 DE caractere.
 * Regula: de la 20 în sus, „de" se pune când ultimele două cifre sunt 00 sau
 * de la 20 la 99. Mesajele de refuz sunt citite de oameni, nu de mașini.
 */
function ro_de(int $n): string
{
    $r = $n % 100;
    return ($n >= 20 && ($r === 0 || $r >= 20)) ? 'de ' : '';
}

/**
 * Curăță o valoare primită, ca să poată fi arătată înapoi într-un refuz.
 *
 * Un mesaj de forma „Am primit «X»" e cel mai bun ajutor pe care i-l poți da
 * omului — dar numai dacă X-ul nu duce cu el ce tocmai a stricat cererea.
 * Scoate caracterele de comandă (inclusiv octetul zero) și taie la 40 de
 * caractere, ca un câmp de 70000 să nu se întoarcă întreg în răspuns.
 */
function text_de_aratat($v, int $max = 40): string
{
    $s = is_scalar($v) ? (string) $v : '';
    // Fără `u`: pe octeți stricați modificatorul acela face `preg_replace` să
    // întoarcă `null`, adică exact textul pe care voiam să-l curățăm.
    $s = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
    $s = trim($s);
    // `mb_substr` cu octeți nevalizi poate tăia la mijlocul unui caracter;
    // `json_body()` înlocuiește ce rămâne stricat, deci răspunsul iese oricum.
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
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
 * Clipa de acum în ora patiseriei, gata de pus într-o coloană DATETIME.
 *
 * Sora lui `today_local()` de deasupra, cu același fus și din același motiv —
 * numai că dă și ceasul, nu doar ziua.
 *
 * DE CE NU `UTC_TIMESTAMP()` DIN MySQL, cum fac sesiunile. Sesiunile țin ore
 * UTC fiindcă acolo contează CÂT A TRECUT: o sesiune de șapte zile e la fel de
 * lungă în orice fus, iar noaptea în care se dă ceasul înapoi n-are voie să
 * prelungească nimănui dreptul de intrare cu o oră. Pontajul e exact pe dos:
 * ora lui se citește pe un stat de plată, lângă ceasul de pe peretele
 * laboratorului, și trebuie să semene cu el. `orders.day` e deja o zi locală,
 * din aceeași pricină.
 *
 * Prețul e cunoscut și acceptat (vezi antetul lui sql/014_pontaj_laborator.sql):
 * în noaptea de toamnă în care ceasul se dă înapoi, o oră se petrece de două
 * ori. Pe un stat de plată de brutărie, un rând de lămurit o dată pe an e mai
 * ieftin decât ore care nu seamănă cu ceasul de pe perete în fiecare zi.
 */
function now_local(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d H:i:s');
}

/**
 * Expresia SQL care dă ZIUA LOCALĂ a unei coloane de clipe scrise în UTC.
 *
 * DE CE E NEVOIE DE EA. Toate legăturile cu baza pornesc cu
 * `SET time_zone = '+00:00'` (api/lib/db.php:45), deci fiecare
 * `DEFAULT CURRENT_TIMESTAMP` scrie o clipă UTC. Zilele aplicației, în schimb,
 * sunt locale: `orders.day`, `today_local()`, ziua cerută de pe orice pagină.
 * Un `DATE(created_at)` pus între ele compară două lucruri deosebite, iar
 * greșeala se vede numai între 00:00 și 03:00 ora României — orele în care
 * laboratorul chiar lucrează. Depozitul a pățit-o o dată și a rezolvat-o
 * comparând pe clipe (`stock_local_day_utc()`, api/routes/stock.php:403). Acolo
 * se putea, fiindcă erau numai două margini. Când ziua locală trebuie să iasă
 * PE FIECARE RÂND — ca să se poată grupa după ea — marginile nu mai ajung.
 *
 * DE CE NU `CONVERT_TZ`. Pe o instalare fără tabelele de fusuri încărcate în
 * MySQL, `CONVERT_TZ` întoarce NULL în loc să se plângă — aceeași pricină
 * pentru care nu-l cheamă nici anunțurile din laborator (api/routes/lab.php:463).
 * Un NULL aici n-ar fi dat eroare: ar fi dat o zi lipsă, în tăcere.
 *
 * CE FACE, DECI. Cere fusului, în PHP, schimbările de oră din fereastra dată și
 * scrie din ele un `CASE` care mută fiecare clipă cu decalajul care era în
 * vigoare ATUNCI. Fără el, un decalaj fix ar fi greșit jumătate de an; cu două
 * ramuri pe an, e drept tot anul. Fereastra se dă întreagă, cu tot cu perioada
 * de comparat, altfel rândurile de dinaintea ei ar cădea pe ramura greșită.
 *
 * Nimic din expresie nu vine de la om: `$coloana` e scrisă de noi, iar orele și
 * secundele ies din `DateTimeZone`, nu din cerere.
 */
function zi_locala_sql(string $coloana, string $deLa, string $panaLa): string
{
    $z = new DateTimeZone('Europe/Bucharest');
    $u = new DateTimeZone('UTC');

    $start = (new DateTimeImmutable($deLa . ' 00:00:00', $z))->setTimezone($u)->getTimestamp();
    $stop  = (new DateTimeImmutable($panaLa . ' 00:00:00', $z))
        ->modify('+1 day')->setTimezone($u)->getTimestamp();

    // Primul rând întors de `getTransitions` NU e o schimbare din fereastră: e
    // starea în care fusul se afla deja la începutul ei. De aceea decalajul lui
    // e cel de plecare, iar restul rândurilor sunt schimbările propriu-zise.
    $treceri = $z->getTransitions($start, $stop) ?: [];
    $decalaj = $treceri ? (int) $treceri[0]['offset'] : 7200;

    $ramuri = [];
    foreach (array_slice($treceri, 1) as $t) {
        // Pentru o clipă dinaintea schimbării ăsteia e valabil decalajul de
        // dinaintea ei — cel strâns la pasul trecut.
        $ramuri[] = "WHEN $coloana < '" . gmdate('Y-m-d H:i:s', (int) $t['ts'])
            . "' THEN " . $decalaj;
        $decalaj = (int) $t['offset'];
    }

    $mutare = $ramuri
        ? '(CASE ' . implode(' ', $ramuri) . ' ELSE ' . $decalaj . ' END)'
        : (string) $decalaj;

    return "DATE($coloana + INTERVAL $mutare SECOND)";
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

/**
 * Ziua mutată cu atâtea zile înainte sau înapoi, în ora patiseriei.
 *
 * Nu `strtotime()`: acela socotește în fusul serverului, iar în noaptea în
 * care ceasul se dă înapoi o zi are 25 de ore — așa că „ziua de ieri" iese
 * uneori tot ziua de azi. `DateTimeImmutable` cu fusul scris pe față ține
 * socoteala singur, la fel ca `today_local()` de mai sus.
 */
function zi_mutata(string $zi, int $cate): string
{
    return (new DateTimeImmutable($zi, new DateTimeZone('Europe/Bucharest')))
        ->modify(($cate >= 0 ? '+' : '') . $cate . ' days')->format('Y-m-d');
}

/**
 * Câte zile ține intervalul, cu amândouă capetele înăuntru.
 *
 * De la 1 la 1 septembrie e O zi, nu zero: un interval de o zi are o zi în el.
 * Socoteala se face pe amiază, nu pe miezul nopții, ca ora de vară să nu scadă
 * și să nu adauge o zi la trecerile de primăvară și de toamnă.
 */
function zile_intre(string $de_la, string $pana_la): int
{
    $z = new DateTimeZone('Europe/Bucharest');
    $a = new DateTimeImmutable($de_la . ' 12:00:00', $z);
    $b = new DateTimeImmutable($pana_la . ' 12:00:00', $z);
    return (int) $a->diff($b)->days + 1;
}
