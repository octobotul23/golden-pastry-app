<?php
/**
 * Notificări pentru comenzi noi.
 *
 * Canalele sunt independente și se activează din panoul de Setări. Fiecare
 * încercare se scrie în tabelul `notifications`, deci se poate vedea ulterior
 * ce s-a trimis și ce a eșuat — o notificare pierdută în tăcere înseamnă o
 * comandă care nu ajunge la laborator.
 *
 * E-mailul funcționează imediat. WhatsApp cere WhatsApp Business API (Meta
 * Cloud API): cont Meta Business, număr dedicat și șablon aprobat. Mesajele
 * inițiate de business se taxează per mesaj.
 */

declare(strict_types=1);

/**
 * Numărul comenzii, așa cum îl vede omul.
 *
 * `seq` e contorul zilei (1, 2, 3…), `ref` e ce scrie pe hârtia din laborator,
 * în panou și pe factură („#1003”) — și poate fi rescris de mână din panou.
 * Anunțurile porneau de la `seq`, deci e-mailul spunea „Comandă nouă #3”
 * pentru comanda pe care tot restul programului o numea „#1003”: cine primea
 * anunțul nu avea cum s-o găsească.
 *
 * Diezul stă în `ref`, nu în textul de aici — altfel ar ieși „##1003”, iar o
 * referință scrisă de mână n-are de ce să înceapă cu diez.
 */
function order_ref_text(array $order): string
{
    return (string) ($order['ref'] ?? ('#' . (1000 + (int) ($order['seq'] ?? 0))));
}

/* ===================================================== ce se anunță ===== */

/**
 * Tot ce poate fi anunțat, cu numele pe care îl vede patronul în Setări.
 *
 * O singură listă, în cod, nu în baza de date: un eveniment fără cârlig scris
 * în program e o bifă care nu face nimic — cea mai urâtă minciună pe care o
 * poate spune un panou de setări. De aceea fiecare rând de aici are, în
 * coloana `unde`, locul din program care îl declanșează; `.claude/check-anunturi.mjs`
 * citește chiar comentariul ăsta și cade dacă vreunul nu mai e chemat de nicăieri.
 *
 * `implicit` spune ce se seamănă la o instalare nouă. Numai cele două de azi —
 * cerere nouă și comandă nouă — fiindcă restul sunt anunțuri pe care nu le-a
 * cerut nimeni încă, iar un program n-are voie să înceapă singur să sune
 * telefonul omului.
 *
 * `doarUnaPeCanal` nu există dinadins: dacă patronul vrea comenzile pe toate
 * trei canalele, le primește pe toate trei. Dublura o hotărăște el, nu noi.
 */
function notify_events(): array
{
    return [
        'request_new' => [
            'label' => 'Cerere nouă de pe site',
            'help'  => 'Cineva a completat coșul de pe site și așteaptă un răspuns. '
                . 'Pe Telegram vine cu două butoane: acceptă și respinge.',
            // Un singur loc, de când s-a scos amânarea. Al doilea era ghiontul
            // cererii puse deoparte, trimis din `route_requests_list()`: patronul
            // a hotărât să rămână numai „Acceptă" și „Respinge", deci n-a mai
            // rămas nimic de amânat și nimeni de ghiontit.
            // unde: api/routes/requests.php · route_requests_create()
        ],
        'order_new' => [
            'label' => 'Comandă nouă',
            'help'  => 'O comandă scrisă în panou sau născută dintr-o cerere acceptată.',
            // Două locuri, fiindcă sunt două nașteri, iar acceptarea are INSERT
            // propriu și NU trece prin `route_orders_create()`. Cârligul chiar
            // lipsea de la acceptare: o patiserie care lucrează numai cu
            // comenzi venite de pe site avea bifa pornită și nu primea nimic.
            // unde: api/routes/orders.php · route_orders_create()
            // unde: api/routes/requests.php · route_requests_accept()
        ],
        'order_done' => [
            'label' => 'Comandă gata',
            'help'  => 'Comanda a ajuns în etapa de încheiere („Predată"). '
                . 'Folositor dacă vrei să știi de departe că a plecat marfa.',
            // unde: api/routes/orders.php · route_orders_update()
        ],
        'order_cancelled' => [
            'label' => 'Comandă anulată',
            'help'  => 'Comanda a fost trecută pe etapa de anulare, adică ștearsă din panou.',
            // unde: api/routes/orders.php · route_orders_update()
        ],
        'user_new' => [
            'label' => 'Cont nou pe site',
            'help'  => 'Un cumpărător și-a făcut singur cont. Dacă a venit pe un cod '
                . 'de recomandare, anunțul spune al cui e codul.',
            // unde: api/routes/auth.php · route_register()
        ],
        'stock_low' => [
            'label' => 'Articol sub pragul minim',
            'help'  => 'O ieșire din depozit a coborât un articol sub pragul lui. '
                . 'Se anunță o singură dată, la trecerea pragului, nu la fiecare ieșire.',
            // unde: api/routes/stock.php · route_moves_create()
        ],
    ];
}

/** Cele trei canale, în ordinea în care apar în Setări. */
function notify_channels(): array
{
    return ['email', 'whatsapp', 'telegram'];
}

/**
 * Ce evenimente vrea canalul ăsta.
 *
 * DE CE NU `setting_list()`, deși valoarea e tot o listă JSON în `settings`:
 * acela se încheie cu `return $clean ?: $fallback`, adică o listă GOALĂ iese
 * pe ușă ca lista implicită. Pentru etape sau intervale e purtarea corectă —
 * o patiserie fără nicio etapă de producție e o greșeală, nu o hotărâre. Aici
 * e pe dos: „niciun anunț pe WhatsApp" e tocmai ce a bifat omul, iar dacă i-am
 * întoarce implicitul, canalul ar continua să sune după ce a debifat tot.
 *
 * Rândul LIPSĂ e altceva decât lista goală, și de-asta se deosebesc: lipsește
 * doar pe o bază pe care nu s-a rulat încă migrarea 013. Acolo purtarea bună e
 * cea de dinaintea migrării — cerere nouă și comandă nouă — nu tăcerea.
 */
function notify_channel_events(string $channel): array
{
    $raw = trim(setting('notify_' . $channel . '_events'));
    if ($raw === '') {
        return ['request_new', 'order_new'];       // migrarea 013 nerulată
    }
    $list = json_decode($raw, true);
    if (!is_array($list)) {
        error_log('[gp] „notify_' . $channel . '_events" nu conține JSON valid');
        return ['request_new', 'order_new'];
    }

    // Numai chei din catalog: o cheie rămasă de la o versiune mai veche a
    // programului n-are cârlig, deci n-ar face nimic — dar ar rămâne bifată în
    // panou și ar da impresia că se trimite ceva.
    $stiute = array_keys(notify_events());
    $out    = [];
    foreach ($list as $v) {
        if (is_array($v) || is_object($v)) {
            continue;
        }
        $v = trim((string) $v);
        if ($v !== '' && in_array($v, $stiute, true) && !in_array($v, $out, true)) {
            $out[] = $v;
        }
    }
    return $out;                                    // gol RĂMÂNE gol
}

/**
 * Pe ce canale pleacă evenimentul ăsta.
 *
 * Două condiții, amândouă necesare: canalul să fie pornit din întrerupătorul
 * lui (cum era și până acum) ȘI evenimentul să fie bifat în lista lui.
 * Întrerupătorul rămâne stăpân peste liste fiindcă e singurul lucru pe care îl
 * apeși când pleci în concediu și vrei liniște — n-ai de ce să debifezi șase
 * rânduri ca să taci un canal.
 */
function notify_channels_for(string $event): array
{
    $out = [];
    foreach (notify_channels() as $canal) {
        if (!setting_bool('notify_' . $canal . '_enabled')) {
            continue;
        }
        if (in_array($event, notify_channel_events($canal), true)) {
            $out[] = $canal;
        }
    }
    return $out;
}

/**
 * Singura ușă prin care pleacă un anunț.
 *
 * Până acum fiecare cârlig își scria singur cele trei `if`-uri cu
 * `setting_bool('notify_..._enabled')`, iar al treilea eveniment ar fi adus a
 * treia copie a aceleiași scări. Mai rău: `notify_new_request()` uitase
 * subiectul pe WhatsApp o vreme, fiindcă erau trei rânduri aproape la fel și
 * unul singur diferit. Cu o ușă, o purtare.
 *
 * `$butoane` sunt butoanele de sub mesajul din Telegram. Le primește numai
 * Telegram: e-mailul și WhatsApp-ul n-au unde le pune, iar a le trimite acolo
 * ar însemna un parametru care se ignoră în tăcere pe două canale din trei.
 */
function notify_event(
    string $event,
    string $text,
    ?int $orderId = null,
    ?string $subject = null,
    ?array $order = null,
    array $butoane = []
): void {
    foreach (notify_channels_for($event) as $canal) {
        try {
            send_channel(
                $canal,
                $text,
                $orderId,
                $subject,
                $order,
                $event,
                $canal === 'telegram' ? $butoane : []
            );
        } catch (Throwable $e) {
            // `send_channel()` își înghite singură eșecurile de trimitere; aici
            // prindem doar ce se poate rupe în jurul ei — scrierea rândului din
            // `notifications`, de pildă. Un canal căzut nu are voie să oprească
            // celelalte două.
            error_log('[gp] anunț „' . $event . '" nedus pe ' . $canal . ': ' . $e->getMessage());
        }
    }
}

/**
 * Are tabela `notifications` coloana `event`?
 *
 * Aceeași grijă ca la comisioane (`referral_money_supported()`): programul nou
 * trebuie să meargă și pe o bază pe care nu s-a apucat nimeni să ruleze încă
 * migrarea 013. Fără verificarea asta, primul INSERT ar cădea cu 1054 și
 * anunțul s-ar pierde — adică exact paguba de care se apără tot fișierul ăsta.
 */
function notify_event_column(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        return $ok = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'event'"
        ) === 1;
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica coloana notifications.event: ' . $e->getMessage());
        return $ok = false;
    }
}

/* ================================================ cârligele programului == */

/**
 * Comandă nouă — scrisă în panou sau născută dintr-o cerere acceptată.
 *
 * Anunțul pleacă FĂRĂ butoane, dinadins. O comandă nu se acceptă și nu se
 * respinge — ea există deja, are marfă rezervată în depozit și un număr pe care
 * clientul îl știe —, iar cele două fapte care i se puteau da de pe telefon
 * („Am văzut", „Etapa următoare") au fost scoase la cererea patronului:
 * comanda apare de-a dreptul pe ecranul din laborator, iar de acolo o duc mai
 * departe angajații, de la tabletă, unde văd toată ziua deodată.
 */
function notify_new_order(array $order): void
{
    notify_event(
        'order_new',
        format_order_message($order),
        $order['id'],
        'Comandă nouă ' . order_ref_text($order),
        $order
    );
}

/**
 * Comanda a intrat în etapa de încheiere sau în cea de anulare.
 *
 * Se cheamă dintr-un singur loc — `route_orders_update()` — fiindcă acela e
 * singurul loc din tot programul care scrie `orders.status`. Inclusiv butonul
 * „Șterge comanda", care de fapt trimite un PATCH pe etapa de anulare.
 *
 * `$vechi` nu e decor: fără el, o salvare care atinge telefonul unei comenzi
 * deja predate ar trimite încă o dată „Comandă gata". Anunțul se dă la
 * TRECERE, nu la starea de fapt.
 */
function notify_order_status(array $order, string $vechi, string $nou): void
{
    $event = notify_order_status_event($vechi, $nou);
    if ($event === null) {
        return;
    }

    $ref  = order_ref_text($order);
    $cap  = $event === 'order_done' ? 'Comandă gata ' : 'Comandă anulată ';
    $text = $cap . $ref . ' — ' . ($order['customerName'] ?? '')
        . "\n" . 'Etapă: ' . $vechi . ' → ' . $nou
        . (!empty($order['day']) ? "\n" . 'Pentru: ' . $order['day'] : '')
        . "\n" . 'Total: ' . number_format((float) ($order['total'] ?? 0), 2, ',', '.') . ' lei';

    notify_event($event, $text, (int) $order['id'], rtrim($cap) . ' ' . $ref, $order);
}

/**
 * Ce fel de anunț naște trecerea asta de etapă — sau `null`, dacă niciunul.
 *
 * Stă deoparte, nu în funcția care trimite, fiindcă ruta trebuie să poată
 * ÎNTREBA fără să trimită: dacă nu iese niciun anunț, răspunsul pleacă pe
 * drumul scurt și obișnuit. Altfel fiecare apăsare de „La dospit" de pe tabletă
 * ar trece prin `json_out_early()` — cu buffere golite, `Content-Length` scris
 * de mână și `fastcgi_finish_request()` — degeaba, de sute de ori pe zi.
 *
 * Etapele se citesc din Setări, nu din cod: dacă patronul redenumește „Predată"
 * în „Livrată", anunțul îl urmează, fiindcă `status_done()` îl urmează.
 */
function notify_order_status_event(string $vechi, string $nou): ?string
{
    if ($vechi === $nou) {
        return null;               // anunțul se dă la TRECERE, nu la stare
    }
    if ($nou !== '' && $nou === status_done()) {
        return 'order_done';
    }
    if ($nou !== '' && $nou === status_cancelled()) {
        return 'order_cancelled';
    }
    return null;
}

/**
 * Cont nou făcut de pe site.
 *
 * Numai conturile făcute de vizitator, nu și cele pe care le face
 * administratorul din „Conturi": acolo omul e chiar de față și tocmai a apăsat
 * el butonul — un anunț despre propria faptă e zgomot.
 */
function notify_user_new(array $user, string $adusDe = ''): void
{
    $nume  = trim((string) ($user['name'] ?? $user['username'] ?? ''));
    $lines = ['Cont nou pe site — ' . $nume];
    if (!empty($user['username'])) {
        $lines[] = 'Utilizator: ' . $user['username'];
    }
    if (!empty($user['phone'])) {
        $lines[] = 'Telefon: ' . $user['phone'];
    }
    if (!empty($user['email'])) {
        $lines[] = 'E-mail: ' . $user['email'];
    }
    if ($adusDe !== '') {
        $lines[] = 'Recomandat de: ' . $adusDe;
    }

    notify_event('user_new', implode("\n", $lines), null, 'Cont nou — ' . $nume);
}

/**
 * Un articol a coborât sub pragul lui minim.
 *
 * Se cheamă numai la TRECEREA pragului, nu la fiecare ieșire de sub el: altfel
 * o zi de producție — o mișcare pe fiecare linie de rețetă, la fiecare raport —
 * ar trimite același anunț de douăzeci de ori, iar al douăzeci și unulea, cel
 * care chiar conta, s-ar pierde printre ele. Socoteala „era deasupra, acum e
 * dedesubt" se face la locul mișcării, unde se știu amândouă cifrele.
 */
function notify_stock_low(string $nume, float $acum, float $prag, string $unitate): void
{
    $nr = static fn(float $v): string =>
        rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',');

    $text = 'Sub pragul minim: ' . $nume
        . "\n" . 'A rămas ' . $nr($acum) . ' ' . $unitate
        . ', pragul e ' . $nr($prag) . ' ' . $unitate . '.'
        . "\n" . 'Se vede în Depozit, la „Sub prag minim".';

    notify_event('stock_low', $text, null, 'Stoc scăzut: ' . $nume);
}

/**
 * Notificare pentru o cerere nouă de pe site.
 *
 * Aici se declanșează mesajul către tine: cererea aduce lucru de făcut —
 * trebuie verificată și acceptată — spre deosebire de avansarea unei comenzi
 * prin etapele din laborator, care nu are de ce să te anunțe.
 */
function notify_new_request(array $req): void
{
    // Subiectul merge pe toate canalele, nu doar pe e-mail: e singurul lucru
    // după care caseta din Setări deosebește un anunț fără comandă în spate —
    // o cerere sau un mesaj de test — de unul care chiar are una.
    notify_event(
        'request_new',
        format_request_message($req),
        null,
        'Cerere nouă de la ' . $req['customerName'],
        null,
        telegram_request_buttons((int) $req['id'])
    );
}

/**
 * Mesajul unei cereri, același pe toate canalele.
 *
 * A avut o vreme și un al doilea cap — „Cerere amânată, i-a venit ora" —, pe
 * care îl cerea ghiontul cererii puse deoparte de butonul „Mai târziu". Butonul
 * acela nu mai există, deci nici capul al doilea: a rămas o singură formă, cea
 * de la trimitere, neatinsă literă cu literă.
 */
function format_request_message(array $req): string
{
    $lines   = [];
    $lines[] = 'Cerere nouă — ' . $req['customerName'];
    if (!empty($req['day'])) {
        $lines[] = 'Pentru: ' . $req['day'] . (!empty($req['slot']) ? ', ora ' . $req['slot'] : '');
    }
    if (!empty($req['phone'])) {
        $lines[] = 'Telefon: ' . $req['phone'];
    }
    if (!empty($req['zone'])) {
        $lines[] = 'Zonă: ' . $req['zone'];
    }
    $lines[] = '';
    // O CERERE FĂRĂ PRODUSE E O ÎNTREBARE, ȘI ANUNȚUL TREBUIE S-O SPUNĂ.
    //
    // De când coșul gol nu mai e un refuz (`POST /requests`), pe Telegram și
    // pe e-mail poate ajunge o cerere fără nicio poziție. Lăsat cum era,
    // mesajul arăta două rânduri goale lipite și „Total estimat: 0,00 lei” —
    // adică o comandă de zero lei, care nu înseamnă nimic pentru cine o
    // citește noaptea, pe telefon. Aici scrie pe față ce e.
    if (!$req['items']) {
        $lines[] = 'FĂRĂ PRODUSE ALESE — e o întrebare sau o cerere de ofertă,';
        $lines[] = 'nu o comandă. Citește mențiunile și sună omul.';
    } else {
        foreach ($req['items'] as $it) {
            $lines[] = sprintf('· %s × %s', rtrim(rtrim(number_format($it['qty'], 2, ',', ''), '0'), ','), $it['name']);
        }
        $lines[] = '';
        $lines[] = 'Total estimat: ' . number_format($req['total'], 2, ',', '.') . ' lei';
    }
    if (!empty($req['notes'])) {
        $lines[] = 'Mențiuni: ' . $req['notes'];
    }
    $lines[] = '';
    $lines[] = 'Acceptă sau respinge din pagina Administrare Comenzi.';

    return implode("\n", $lines);
}

/** Mesajul citibil, folosit de toate canalele. */
function format_order_message(array $order): string
{
    $lines = [];
    $lines[] = 'Comandă nouă ' . order_ref_text($order) . ' — ' . $order['customerName'];
    $lines[] = 'Pentru: ' . $order['day'];
    if (!empty($order['phone'])) {
        $lines[] = 'Telefon: ' . $order['phone'];
    }
    if (!empty($order['address'])) {
        $lines[] = 'Adresă: ' . $order['address'];
    }
    $lines[] = '';
    foreach ($order['items'] as $it) {
        $lines[] = sprintf('· %s × %s', rtrim(rtrim(number_format($it['qty'], 2, ',', ''), '0'), ','), $it['name']);
    }
    $lines[] = '';
    $lines[] = 'Total: ' . number_format($order['total'], 2, ',', '.') . ' lei';
    if (!empty($order['notes'])) {
        $lines[] = 'Mențiuni: ' . $order['notes'];
    }
    return implode("\n", $lines);
}

/**
 * Înregistrează încercarea și trimite. Eșecul nu aruncă excepție: comanda este
 * deja salvată, iar notificarea rămâne marcată `failed` pentru diagnostic.
 */
function send_channel(
    string $channel,
    string $text,
    ?int $orderId,
    ?string $subject = null,
    ?array $order = null,
    ?string $event = null,
    array $butoane = []
): bool {
    $to = match ($channel) {
        'email'    => setting('notify_email_to'),
        'whatsapp' => setting('notify_whatsapp_to'),
        'telegram' => setting('notify_telegram_chat_id'),
        default    => '',
    };

    // Felul anunțului se scrie lângă canal, ca lista „anunțuri care nu au
    // plecat" din Setări să poată spune DESPRE CE era, nu doar pe unde trebuia
    // să plece. Cu șase feluri în loc de două, „telegram · a eșuat" nu mai
    // spune nimic. Pe o bază fără migrarea 013 coloana lipsește, iar atunci
    // scriem ca înainte — un anunț fără etichetă e mai bun decât niciun anunț.
    if ($event !== null && notify_event_column()) {
        q(
            'INSERT INTO notifications (channel, event, recipient, subject, payload, order_id, status, attempts)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
            [$channel, $event, $to ?: null, $subject, $text, $orderId, 'pending']
        );
    } else {
        q(
            'INSERT INTO notifications (channel, recipient, subject, payload, order_id, status, attempts)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$channel, $to ?: null, $subject, $text, $orderId, 'pending']
        );
    }
    $id = (int) db()->lastInsertId();

    try {
        if ($to === '') {
            throw new RuntimeException('Destinatarul nu este configurat în Setări.');
        }
        match ($channel) {
            'email'    => send_email($to, $subject ?? 'Comandă nouă', $text),
            'whatsapp' => send_whatsapp($to, $text, $order),
            'telegram' => send_telegram($to, $text, $butoane),
            default    => throw new RuntimeException('Canal necunoscut: ' . $channel),
        };
        q('UPDATE notifications SET status = ?, sent_at = UTC_TIMESTAMP() WHERE id = ?', ['sent', $id]);
        if ($channel === 'whatsapp') {
            set_whatsapp_status('conectat', '');
        }
        return true;
    } catch (Throwable $e) {
        q('UPDATE notifications SET status = ?, error = ? WHERE id = ?', ['failed', $e->getMessage(), $id]);
        if ($channel === 'whatsapp') {
            set_whatsapp_status('eroare', $e->getMessage());
        }
        error_log('[gp] notificare ' . $channel . ' eșuată: ' . $e->getMessage());
        return false;
    }
}

function set_whatsapp_status(string $status, string $error): void
{
    q('UPDATE settings SET value = ? WHERE setting_key = ?', [$status, 'notify_whatsapp_status']);
    q('UPDATE settings SET value = ? WHERE setting_key = ?', [substr($error, 0, 500), 'notify_whatsapp_last_error']);
}

/* -------------------------------------------------------------- e-mail -- */

/**
 * Numele care apare ca expeditor, luat din Setări.
 *
 * Era scris în cod, „Golden Pastry”. Firma își schimba denumirea din panou —
 * unde scrie negru pe alb că numele apare în antetul mesajelor — și e-mailurile
 * plecau mai departe cu cea veche.
 *
 * Diacriticele se codifică, la fel ca subiectul: un antet cu litere românești
 * brute este refuzat de unele servere de mail, iar atunci nu se pierde doar
 * numele, ci tot anunțul comenzii noi. Ce ar putea rupe antetul în două —
 * saltul de linie, ghilimelele, parantezele unghiulare — se scoate: un nume
 * scris greșit nu are voie să adauge un antet pe care nu l-a cerut nimeni.
 */
function email_from_name(): string
{
    $name = trim(str_replace(["\r", "\n", '"', '\\', '<', '>'], ' ', setting('company_name', 'Golden Pastry')));
    if ($name === '') {
        $name = 'Golden Pastry';
    }
    return preg_match('/[^\x20-\x7E]/', $name) === 1
        ? '=?UTF-8?B?' . base64_encode($name) . '?='
        : '"' . $name . '"';
}

function send_email(string $to, string $subject, string $text): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Adresă de e-mail invalidă: ' . $to);
    }
    $from = setting('notify_email_from', 'noreply@goldenpastry.ro');
    // Expeditorul intră în antet exact așa cum a fost scris în Setări, iar
    // anteturile se despart prin salt de linie: o valoare cu CR sau LF în ea ar
    // adăuga anteturi pe care nu le-a scris nimeni — de pildă un „Bcc:” către
    // un străin, la fiecare comandă nouă. Verificarea de la salvare nu ajunge:
    // în baza de date poate sta încă o valoare pusă înaintea ei. Oprim aici, cu
    // motivul scris pe față, ca anunțul să apară „eșuat” în Setări și să se
    // vadă ce anume trebuie corectat.
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Adresa expeditor din Setări nu este o adresă validă: ' . $from);
    }

    $headers = implode("\r\n", [
        'From: ' . email_from_name() . ' <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: golden-pastry',
    ]);
    // Subiectul se codifică: diacriticele nu sunt permise brut în anteturi.
    $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    if (!@mail($to, $encoded, $text, $headers)) {
        throw new RuntimeException('Funcția mail() a eșuat. Verifică setările de e-mail din cPanel.');
    }
}

/* ------------------------------------------------------------ WhatsApp -- */

/**
 * Meta Cloud API. Mesajele inițiate de business trebuie trimise ca „template”
 * aprobat în prealabil — text liber este permis doar în fereastra de 24h
 * deschisă de un mesaj al destinatarului.
 */
function send_whatsapp(string $to, string $text, ?array $order = null): void
{
    $provider = setting('notify_whatsapp_provider', 'meta');
    $token    = setting('notify_whatsapp_token');
    if ($token === '') {
        throw new RuntimeException('Tokenul de WhatsApp nu este configurat în Setări.');
    }

    $to = preg_replace('/[^0-9]/', '', $to);
    if ($to === '') {
        throw new RuntimeException('Număr de WhatsApp invalid.');
    }

    if ($provider === 'twilio') {
        send_whatsapp_twilio($to, $text, $token);
        return;
    }

    $phoneId = setting('notify_whatsapp_phone_id');
    if ($phoneId === '') {
        throw new RuntimeException('Phone Number ID lipsește din Setări.');
    }
    $template = setting('notify_whatsapp_template', 'comanda_noua');

    // Șablonul primește un singur parametru: rezumatul comenzii. WhatsApp nu
    // acceptă salt de linie în parametri, deci textul se aplatizează.
    $param = str_replace("\n", ' · ', $text);
    if (mb_strlen($param) > 1000) {
        $param = mb_substr($param, 0, 997) . '...';
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'       => $template,
            'language'   => ['code' => 'ro'],
            'components' => [[
                'type'       => 'body',
                'parameters' => [['type' => 'text', 'text' => $param]],
            ]],
        ],
    ];

    $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($phoneId) . '/messages';
    [$code, $resp] = http_post_json($url, $payload, ['Authorization: Bearer ' . $token]);

    if ($code < 200 || $code >= 300) {
        $msg = json_decode($resp, true)['error']['message'] ?? substr($resp, 0, 300);
        throw new RuntimeException('Meta a răspuns ' . $code . ': ' . $msg);
    }
}

function send_whatsapp_twilio(string $to, string $text, string $token): void
{
    // La Twilio, „Phone Number ID” ține Account SID, iar tokenul este Auth Token.
    $sid  = setting('notify_whatsapp_phone_id');
    $from = setting('notify_whatsapp_from', '');
    if ($sid === '' || $from === '') {
        throw new RuntimeException('Pentru Twilio completează Account SID și numărul expeditor în Setări.');
    }

    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
    $post = http_build_query([
        'From' => 'whatsapp:+' . preg_replace('/[^0-9]/', '', $from),
        'To'   => 'whatsapp:+' . $to,
        'Body' => $text,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $sid . ':' . $token,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Conexiune către Twilio eșuată: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Twilio a răspuns ' . $code . ': ' . substr((string) $resp, 0, 300));
    }
}

/* ------------------------------------------------------------ Telegram -- */

function send_telegram(string $chatId, string $text, array $butoane = []): void
{
    $token = setting('notify_telegram_token');
    if ($token === '') {
        throw new RuntimeException('Tokenul de Telegram nu este configurat în Setări.');
    }
    $url     = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $payload = ['chat_id' => $chatId, 'text' => $text];

    // Butoanele se pun NUMAI dacă botul are pe unde să audă apăsarea. Fără
    // webhook legat, Telegram desenează butonul frumos, omul îl apasă, și nu se
    // întâmplă nimic — nici măcar o rotiță. Un buton mort e mai rău decât
    // niciun buton: îl apeși din nou, crezi că n-ai nimerit, și pierzi minutele
    // în care puteai să intri în panou.
    if ($butoane && telegram_buttons_live()) {
        $payload['reply_markup'] = ['inline_keyboard' => $butoane];
    }

    [$code, $resp] = http_post_json($url, $payload);

    if ($code < 200 || $code >= 300) {
        $msg = json_decode($resp, true)['description'] ?? substr($resp, 0, 300);
        throw new RuntimeException('Telegram a răspuns ' . $code . ': ' . $msg);
    }
}

/**
 * Se poate răspunde la o apăsare de buton?
 *
 * Trei condiții, toate necesare: butoanele să fie pornite din Setări, webhookul
 * să fi fost legat cu succes, și să existe omul pe numele căruia se scriu în
 * jurnal apăsările. Ultima e cea care se uită ușor — fără ea, o cerere
 * acceptată de pe telefon ar apărea în Jurnal fără autor.
 */
function telegram_buttons_live(): bool
{
    return setting_bool('notify_telegram_buttons')
        && setting('notify_telegram_webhook_status') === 'legat'
        && setting('notify_telegram_actor_id') !== '';
}

/* ------------------------------------------------ butoanele, pe fapte -- */

/**
 * `callback_data` are 64 de OCTEȚI, cu totul.
 *
 * De aceea codurile sunt de o literă și nu „accepta"/„respinge": cu un id de
 * cerere de șapte cifre, `r:1234567:a` ocupă unsprezece octeți, iar forma
 * scrisă pe îndelete ar fi trecut de limită abia peste ani, când patiseria ar
 * fi avut destule cereri — adică exact în ziua în care nimeni nu s-ar mai fi
 * uitat aici. Telegram nu refuză mesajul politicos: răspunde
 * „BUTTON_DATA_INVALID" și anunțul nu mai pleacă deloc.
 *
 * Prima literă spune PE CE se apasă, ultima CE se face. Despărțitorul e `:`
 * fiindcă nu apare în cifre.
 *
 * Azi prima literă e numai `r`, de la cerere: comenzile noi au avut și ele o
 * pereche de butoane („👀 Am văzut", „➡️ Etapa următoare", pe litera `o`), scoase
 * la cererea patronului odată cu amânarea. Forma a rămas totuși în trei bucăți,
 * iar `api/routes/telegram.php` o despică la fel — și fiindcă e mai limpede
 * așa, și fiindcă în conversație pot zăcea mesaje vechi, cu butoanele de
 * atunci sub ele; cine apasă pe unul primește „Buton necunoscut", nu tăcere.
 */
function telegram_request_buttons(int $requestId): array
{
    // Numai două, și numai pe cerere. Așa a hotărât patronul: de pe telefon se
    // ia hotărârea — dacă intră în producție sau nu —, iar de acolo încolo
    // comanda merge pe ecranul din laborator, unde o duc mai departe angajații.
    return [[
        ['text' => '✅ Acceptă',  'callback_data' => 'r:' . $requestId . ':a'],
        ['text' => '❌ Respinge', 'callback_data' => 'r:' . $requestId . ':x'],
    ]];
}

/* ------------------------------------------------------------- ajutoare -- */

/** POST JSON. Folosește cURL dacă există, altfel stream-urile PHP. */
function http_post_json(string $url, array $payload, array $headers = []): array
{
    $json    = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = array_merge(['Content-Type: application/json'], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('Conexiune eșuată: ' . $err);
        }
        return [$code, (string) $resp];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $json,
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        throw new RuntimeException('Conexiune eșuată (fără cURL disponibil).');
    }
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int) $m[1];
        }
    }
    return [$code, $resp];
}
