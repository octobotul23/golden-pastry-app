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
            // Nu mai scrie „de pe site": de la 18 septembrie 2026 tot aici
            // ajung și cererile agentului de vânzări și ale angajatului, care
            // scriu o cerere acolo unde până atunci scriau o comandă. Cu numele
            // vechi, patronul care voia să afle de cererile agenților căuta o
            // a doua bifă, care nu există. Cine a trimis-o scrie în anunț.
            'label' => 'Cerere nouă',
            'help'  => 'Cineva a trimis o cerere și așteaptă un răspuns: un client, de pe site, '
                . 'ori un om al casei care nu scrie comenzi de-a dreptul. '
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
            // „Comandă livrată", nu „Comandă gata" (hotărârea patronului din
            // 2 octombrie 2026): de când laboratorul are etapa „Gata de
            // livrare", un anunț numit „gata" ar fi fost citit ca intrarea în
            // ea — adică marfa pe masă, nu marfa predată. Cheia rămâne
            // `order_done`: stă scrisă în bifele salvate ale fiecărui canal.
            'label' => 'Comandă livrată',
            'help'  => 'Comanda a ajuns în etapa de încheiere („Predată"). '
                . 'Folositor dacă vrei să știi de departe că a plecat marfa.',
            // unde: api/routes/orders.php · route_orders_update()
        ],
        'order_cancelled' => [
            'label' => 'Comandă anulată',
            'help'  => 'Comanda a fost trecută pe etapa de anulare, adică ștearsă din panou. '
                . 'Pe aceleași canale pleacă și „Comandă refăcută", când o comandă anulată e adusă înapoi.',
            // Refacerea n-are cheie a ei, dinadins: o bifă separată ar fi putut-o
            // despărți de anulare. Lămurirea e la notify_order_status_events().
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
 * Comanda a intrat în etapa de încheiere ori în cea de anulare, sau a ieșit
 * din cea de anulare.
 *
 * Se cheamă dintr-un singur loc — `route_orders_update()` — fiindcă acela e
 * singurul loc din tot programul care scrie `orders.status`. Inclusiv butonul
 * „Șterge comanda", care de fapt trimite un PATCH pe etapa de anulare, și
 * perechea lui, „Reface comanda", care trimite un PATCH pe începutul fluxului.
 *
 * `$vechi` nu e decor: fără el, o salvare care atinge telefonul unei comenzi
 * deja predate ar trimite încă o dată „Comandă livrată". Anunțul se dă la
 * TRECERE, nu la starea de fapt. Tot din `$vechi` se vede și refacerea: nu
 * contează unde ajunge comanda, contează că pleacă de pe anulare.
 *
 * O trecere poate naște două anunțuri: o comandă anulată mutată direct pe
 * „Predată" e și refăcută, și livrată. Pleacă amândouă, fiecare pe canalele
 * bifei lui, în ordinea faptelor — întâi refacerea, apoi încheierea.
 */
function notify_order_status(array $order, string $vechi, string $nou): void
{
    $ref = order_ref_text($order);
    foreach (notify_order_status_events($vechi, $nou) as $event => $cap) {
        $text = format_order_status_message($order, $event, $cap, $vechi, $nou);

        notify_event($event, $text, (int) $order['id'], $cap . ' ' . $ref, $order);
    }
}

/**
 * Mesajul unei treceri de etapă: capul, etapa, ziua și totalul.
 *
 *   Comandă livrată #1042 — Cafeneaua Lumina
 *   Onorare: Ridicare — vine clientul după marfă
 *   Etapă: Gata de livrare → Predată
 *   Pentru: 2026-10-03
 *   Total: 432,00 lei
 *
 * RÂNDUL DE ONORARE E NUMAI AL COMENZII RIDICATE DE CLIENT, la predare. Capul
 * rămâne „Comandă livrată" și pentru ea — e numele bifei din Setări, iar omul
 * care a bifat-o caută în conversație un mesaj care începe așa (hotărârea
 * patronului, v. `notify_order_status_events()`). Dar fără rândul acesta
 * mesajul unei comenzi după care a venit clientul era, literă cu literă, al
 * uneia duse de curier: patronul nu putea deosebi, de pe telefon, o livrare de
 * o ridicare. Rândul e chiar cel din „Comandă nouă" (`anunt_randuri_loc()`),
 * ca aceeași comandă să poarte aceleași vorbe la naștere și la predare.
 *
 * Comanda livrată n-are rândul: „Comandă livrată … Onorare: Livrare" ar spune
 * același lucru de două ori. Nici anularea ori refacerea: acolo nu s-a predat
 * nimic.
 *
 * Stă deoparte de `notify_order_status()`, care trimite, ca textul să poată fi
 * citit fără să plece nimic: `.claude/check-anunturi.mjs` îl cheamă peste o
 * bază de mucava.
 */
function format_order_status_message(array $order, string $event, string $cap, string $vechi, string $nou): string
{
    $lines = [$cap . ' ' . order_ref_text($order) . ' — ' . ($order['customerName'] ?? '')];
    if ($event === 'order_done' && anunt_e_ridicare($order)) {
        $lines[] = anunt_randuri_loc($order)[0];
    }
    $lines[] = 'Etapă: ' . $vechi . ' → ' . $nou;
    if (!empty($order['day'])) {
        $lines[] = 'Pentru: ' . $order['day'];
    }
    $lines[] = 'Total: ' . number_format((float) ($order['total'] ?? 0), 2, ',', '.') . ' lei';

    return implode("\n", $lines);
}

/**
 * Ce anunțuri naște trecerea asta de etapă, ca listă `cheia bifei => capul
 * mesajului` — goală, dacă niciunul.
 *
 * Stă deoparte, nu în funcția care trimite, fiindcă ruta trebuie să poată
 * ÎNTREBA fără să trimită: dacă nu iese niciun anunț, răspunsul pleacă pe
 * drumul scurt și obișnuit. Altfel fiecare apăsare de „La dospit" de pe tabletă
 * ar trece prin `json_out_early()` — cu buffere golite, `Content-Length` scris
 * de mână și `fastcgi_finish_request()` — degeaba, de sute de ori pe zi.
 *
 * Etapele se citesc din Setări, nu din cod: dacă patronul redenumește „Predată"
 * în „Livrată", anunțul îl urmează, fiindcă `status_done()` îl urmează.
 *
 * „COMANDĂ REFĂCUTĂ" N-ARE BIFĂ A EI: PLEACĂ PE A LUI „COMANDĂ ANULATĂ".
 * Hotărârea patronului: refacerea pleacă pe aceleași canale pe care a plecat
 * anularea — altfel pe Telegram rămâne ultimul mesaj „anulată", iar echipa
 * crede că nu mai e. O cheie separată ar fi fost o bifă separată, deci un
 * canal care primește anularea fără să primească refacerea — exact ce cere
 * hotărârea să nu se poată. Pe o bază veche, cheia nouă ar fi pornit și
 * nebifată, lângă o anulare bifată. De aceea rândul refacerii poartă în
 * `notifications.event` tot `order_cancelled`: coloana spune pe ce bifă a
 * plecat anunțul, iar ce spune anunțul stă în subiect.
 *
 * Mutată direct pe „Predată", o comandă anulată dă DOUĂ anunțuri, tot din
 * hotărârea patronului: întâi „Comandă refăcută", apoi „Comandă livrată".
 * Ordinea cheilor din lista întoarsă e ordinea în care pleacă.
 *
 * CAPUL „Comandă livrată" E ACELAȘI CU NUMELE BIFEI DIN SETĂRI, literă cu
 * literă: omul care a bifat „Comandă livrată" caută în conversație un mesaj
 * care începe așa. `.claude/check-anunturi.mjs` le compară.
 */
function notify_order_status_events(string $vechi, string $nou): array
{
    if ($vechi === $nou) {
        return [];                 // anunțul se dă la TRECERE, nu la stare
    }
    $anulata = status_cancelled();
    $out     = [];
    // Ieșirea din anulare, oriunde ar duce: începutul fluxului („Reface
    // comanda"), etapa aleasă din formular ori din lista vederii rapide, ori
    // chiar „Predată".
    if ($anulata !== '' && $vechi === $anulata) {
        $out['order_cancelled'] = 'Comandă refăcută';
    }
    if ($nou !== '' && $nou === status_done()) {
        $out['order_done'] = 'Comandă livrată';
    } elseif ($anulata !== '' && $nou === $anulata) {
        $out['order_cancelled'] = 'Comandă anulată';
    }
    return $out;
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
 * Notificare pentru o cerere nouă — de pe site ori scrisă de un om al casei.
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
 * de la trimitere.
 *
 * De la runda 2.6.0 poartă aceleași rânduri ca „Comandă nouă" — cine a scris-o,
 * al cui e clientul, unde merge marfa și pe ce latură e fiecare produs —, toate
 * făcute de ajutoarele `anunt_…()` de mai jos. Corpul de aici rămâne scurt
 * dinadins: `.claude/check-anunturi.mjs` citește ramura cererii fără produse
 * din primele 2600 de caractere ale funcției.
 */
function format_request_message(array $req): string
{
    $lines   = [];
    $lines[] = 'Cerere nouă — ' . $req['customerName'];
    array_push($lines, ...anunt_randuri_oameni($req, 'cererea'));
    if (!empty($req['day'])) {
        $lines[] = anunt_rand_zi($req);
    }
    array_push($lines, ...anunt_randuri_loc($req));
    if (!empty($req['phone'])) {
        $lines[] = 'Telefon: ' . $req['phone'];
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
        array_push($lines, ...anunt_randuri_pozitii($req['items'], (string) ($req['variant'] ?? '')));
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

/**
 * Mesajul citibil al unei comenzi noi, folosit de toate canalele.
 *
 * PRIMUL RÂND NU SE ATINGE: „Comandă nouă <referința> — <clientul>". După el
 * caută omul comanda în panou, iar `.claude/test-defect-anunturi-esuate.mjs`
 * îl ține cu `startsWith`.
 *
 * Restul a crescut la runda 2.6.0, la cererea patronului: anunțul spunea ce
 * s-a comandat și pentru cine, dar nu CINE a scris comanda, AL CUI e clientul,
 * pe ce latură e fiecare produs și dacă marfa pleacă ori vine omul după ea —
 * adică tocmai cele patru lucruri după care hotărăște, de pe telefon, dacă are
 * de sunat pe cineva. Le fac ajutoarele `anunt_…()` de mai jos, aceleași
 * pentru comandă și pentru cerere.
 */
function format_order_message(array $order): string
{
    $lines   = [];
    $lines[] = 'Comandă nouă ' . order_ref_text($order) . ' — ' . $order['customerName'];
    array_push($lines, ...anunt_randuri_oameni($order, 'comanda'));
    $lines[] = anunt_rand_zi($order);
    array_push($lines, ...anunt_randuri_loc($order));
    if (!empty($order['phone'])) {
        $lines[] = 'Telefon: ' . $order['phone'];
    }
    $lines[] = '';
    array_push($lines, ...anunt_randuri_pozitii($order['items'], (string) ($order['variant'] ?? '')));
    $lines[] = '';
    $lines[] = 'Total: ' . number_format($order['total'], 2, ',', '.') . ' lei';
    if (!empty($order['notes'])) {
        $lines[] = 'Mențiuni: ' . $order['notes'];
    }
    return implode("\n", $lines);
}

/* ============================== rândurile anunțului: cine, când, unde, ce == */

/**
 * Numele rolului, ca vorbă de pus între paranteze după numele omului.
 *
 * Pe server nu exista nicio hartă de felul ăsta: rolurile se arătau numai în
 * pagini, iar a lor e `ROLE_L` din Conturi.dc.html. Vorbele de aici sunt
 * aceleași, literă cu literă — omul care citește „agent de vânzări" pe telefon
 * trebuie să găsească tot „agent de vânzări" în lista de conturi.
 *
 * Un rol necunoscut iese cum e scris în bază: un cuvânt neșlefuit spune totuși
 * ceva, pe când un gol ar lăsa paranteza goală.
 */
function anunt_rol(string $rol): string
{
    return [
        'fondator'       => 'fondator',
        'admin'          => 'administrator',
        'manager'        => 'manager',
        'agent_vanzari'  => 'agent de vânzări',
        'angajat'        => 'angajat',
        'utilizator_lab' => 'om de laborator',
        'client'         => 'client',
    ][$rol] ?? $rol;
}

/**
 * Contul unui om din anunț: numele și rolul, sau null dacă nu se poate afla.
 *
 * CITIREA STĂ ÎN try/catch, ca toate citirile din bază de aici: anunțul se
 * scrie după ce comanda e salvată și răspunsul a plecat, deci o piedică de aici
 * nu mai are cui să fie spusă — dar, neprinsă, ar opri TOT anunțul, iar o
 * comandă neanunțată e o comandă care nu ajunge la laborator. Fără numele
 * omului, mesajul pleacă mai sărac; fără mesaj, nu pleacă nimic.
 *
 * `Throwable`, nu `Exception`: și o funcție care lipsește e tot o piedică, iar
 * aceea e `Error`.
 *
 * @return array{name:string,role:string}|null
 */
function anunt_cont(?int $id): ?array
{
    if ($id === null || $id <= 0) {
        return null;
    }
    try {
        $u = one('SELECT name, role FROM users WHERE id = ?', [$id]);
    } catch (Throwable $e) {
        error_log('[gp] anunț: nu pot citi contul ' . $id . ': ' . $e->getMessage());
        return null;
    }
    $nume = is_array($u) ? trim((string) ($u['name'] ?? '')) : '';
    return $nume === '' ? null : ['name' => $nume, 'role' => (string) ($u['role'] ?? '')];
}

/** Omul, cum se scrie în anunț: „Ana Pop (agent de vânzări)". Null = cont dispărut. */
function anunt_om(?int $id): ?string
{
    $c = anunt_cont($id);
    return $c === null ? null : $c['name'] . ' (' . anunt_rol($c['role']) . ')';
}

/**
 * Contul clientului de pe hârtie: un id, `null` = n-are cont, `0` = nu s-a
 * putut afla.
 *
 * Hârtia LEGATĂ de un cont îl spune singură (`customerId`). Cea nelegată,
 * scrisă de un om al casei, se caută după NUME, cu `client_by_name()`
 * (api/lib/orders.php) — aceeași căutare, cu aceleași porți (un singur cont cu
 * numele acela, recunoscut de casă), cu care se leagă orice comandă scrisă în
 * panou.
 *
 * DE CE SE CAUTĂ ȘI DUPĂ NUME. Fără căutarea asta, rândul „Atenție" n-ar fi
 * avut când să se aprindă. Agentul de vânzări nu scrie comenzi, scrie cereri,
 * iar cererea lui se leagă de un cont numai dacă acel client e al LUI
 * (`client_by_name_in_scope()`); pe clientul altuia rămâne nelegată, în tăcere,
 * și pleacă singură cu codul agentului. Citită numai după legătură și după
 * cod, hârtia aceea ar fi spus „adus de Ana — tot cine a scris cererea",
 * tocmai despre clientul lui Mihai. Tăcerea de acolo e pentru agent, ca
 * formularul să nu-i spună ce clienți are casa; patronul tocmai asta a cerut
 * să afle, din anunț.
 *
 * ANUNȚUL NU PLEACĂ ÎNSĂ NUMAI LA PATRON. Fiecare canal are un singur
 * destinatar (`send_channel()`), iar pe Telegram acela e conversația din
 * Setări — grupul personalului, în care apasă pe butoane oricine e în el.
 * Rândurile „Client adus de" și „Atenție" le citește deci tot grupul, cu
 * agentul care a scris cererea cu tot: ce-i ascunde formularul, îi spune
 * anunțul, o clipă mai târziu. Cine e în grup nu se poate afla din cod; e
 * hotărârea patronului dacă rândurile acestea au ce căuta acolo.
 *
 * NUMAI PE HÂRTIA SCRISĂ DE UN OM AL CASEI. El știe cine e omul de la telefon;
 * numele scris de un vizitator de pe site nu e dovada nimănui — oricine poate
 * scrie „Cafeneaua Veche" —, deci cererea de pe site, nelegată, rămâne „a
 * casei", cum o și socotește cârligul de comision.
 *
 * „N-ARE CONT" ȘI „NUMELE N-A PUTUT HOTĂRÎ" NU SUNT ACELAȘI RĂSPUNS.
 * `client_by_name()` întoarce același nimic pentru trei lucruri deosebite:
 * niciun cont cu numele acesta, un cont înscris singur de pe site și încă
 * nerecunoscut de casă, două conturi cu același nume. Numai primul e „n-are
 * cont". În celelalte două clientul poate fi al unui agent — contul
 * nerecunoscut s-a înscris, poate, chiar de pe linkul lui —, iar anunțul ar fi
 * spus despre el „nimeni (client al casei)". De aceea, când căutarea nu
 * găsește, se mai pune o întrebare, fără poarta de recunoaștere și fără „unul
 * singur": poartă VREUN cont de client activ numele acesta? Dacă da, răspunsul
 * e `0`, iar rândul lipsește (v. `anunt_aducator()`).
 */
function anunt_client(array $x, bool $dinCasa): ?int
{
    $id = $x['customerId'] ?? null;
    if (is_numeric($id) && (int) $id > 0) {
        return (int) $id;
    }
    $nume = trim((string) ($x['customerName'] ?? ''));
    if (!$dinCasa || $nume === '') {
        return null;
    }
    try {
        $gasit = client_by_name($nume);
        if ($gasit !== null) {
            return $gasit;
        }
        $poarta = (int) scalar(
            "SELECT COUNT(*) FROM users
              WHERE role = 'client' AND active = 1
                AND TRIM(COALESCE(NULLIF(customer_name, ''), name)) = ?",
            [$nume]
        );
        return $poarta > 0 ? 0 : null;
    } catch (Throwable $e) {
        error_log('[gp] anunț: nu pot căuta contul clientului după nume: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Cine a adus clientul — contul lui, sau null dacă nu l-a adus nimeni.
 *
 * TREI IZVOARE, ÎN ORDINEA ASTA:
 *
 *   1. RECOMANDANTUL CLIENTULUI, `users.referred_by` — legătura „pentru
 *      totdeauna" de pe contul lui (`anunt_client()`). Numai conturi active și
 *      non-sistem, ca în cârligul de comision.
 *   2. CEL ȘTIUT — recomandantul scris pe comisionul comenzii
 *      (`commission.referrer`), cum a hotărât `referral_commission_sync()`
 *      (api/lib/referral.php).
 *   3. STĂPÂNUL CODULUI de pe hârtie, când nu s-a hotărât încă niciun comision
 *      (cererea) ori când clientul n-are pe nimeni.
 *
 * DE CE CONTUL STĂ ÎNAINTEA CELORLALTE DOUĂ. Întrebarea anunțului e „al cui e
 * CLIENTUL", nu „al cui e codul" și nici „cine ia comisionul". Pe o hârtie
 * legată de cont și fără ceartă, cele trei spun același om, deci ordinea nu
 * schimbă nimic. Se despart exact în cele două cazuri pentru care există
 * rândul „Atenție":
 *
 *   - comisionul rămas „în așteptare" — codul de pe hârtie e al unui om, iar
 *     clientul e al altuia —, unde cel știut lipsește cu totul;
 *   - cererea agentului pe clientul altuia, care rămâne nelegată și pleacă
 *     singură cu codul LUI: acolo cel știut și stăpânul codului sunt chiar
 *     agentul care a scris-o.
 *
 * Cu codul ori cu cel știut întâi, mesajul ar fi spus în amândouă „adus de Ana
 * — tot cine a scris comanda", iar nepotrivirea ar fi rămas de găsit în
 * „Recomandări", de cine s-ar fi uitat.
 *
 * CUMPĂRĂTORUL ÎNSUȘI NU SE NUMĂRĂ: codul propriu scris pe propria comandă nu
 * naște comision, deci nici „adus de el însuși" n-are ce căuta în anunț. Cine
 * e „el însuși" se întreabă cu ACEEAȘI poartă ca în cârligul de comision,
 * `referral_potrivire_cumparator()` (api/lib/referral.php), nu cu una făcută
 * aici: după contul de care e legată hârtia, iar pe cea venită printr-o cerere
 * și după telefon, după e-mailul cererii ori după numele pe care îl poartă un
 * singur cont din casă. O vreme s-a comparat numai contul — și tocmai
 * vizitatorul nelogat, care n-are niciun cont pe hârtie, ieșea „Client adus
 * de: Ion Popescu (client)" sub „Cerere nouă — Ion Popescu". `$urme` e hârtia,
 * cu cheile pe care le cere poarta (`customer_id`, `customer_name`, `phone`,
 * `request_id`).
 *
 * Totul sub `referral_supported()` și în try/catch — v. `anunt_cont()`.
 *
 * TREI RĂSPUNSURI, NU DOUĂ: un id, `null` = nu l-a adus nimeni, și `0` = nu
 * s-a putut afla (citirea a căzut, ori contul clientului n-a putut fi aflat).
 * Ultimul trebuie deosebit de al doilea, fiindcă anunțul scrie „client al
 * casei" pe `null` — iar a spune asta despre clientul unui agent, numai
 * fiindcă baza n-a răspuns, ar fi o minciună tocmai pe rândul după care se
 * împart comisioanele.
 *
 * CÂND CONTUL CLIENTULUI NU S-A PUTUT AFLA (`$clientId === 0`), NU SE MAI
 * ÎNTREABĂ NIMIC. Nici cel știut, nici codul: pe cererea nelegată a unui agent
 * amândouă sunt chiar agentul care a scris-o (cererea pleacă singură cu codul
 * LUI), deci a merge mai departe ar fi însemnat „adus de Ana — tot cine a
 * scris cererea" tocmai despre clientul pe care nu l-am putut căuta. Oprirea
 * stă pe primul rând, înaintea oricărei citiri.
 *
 * CÂND CONTUL A FOST GĂSIT DUPĂ NUME (`$dupaNume`), SE ÎNTREABĂ NUMAI CONTUL.
 * Hârtia aceea e nelegată fiindcă agentul a scris pe un client din afara
 * ogrăzii lui; cel știut și codul sunt, iarăși, ale celui care a scris-o.
 * Dacă pe cont nu stă niciun recomandant, clientul e al casei — iar a-l da
 * agentului, cu aceleași rânduri ca la o firmă nouă, fără niciun cont, ar fi
 * aruncat tocmai răspunsul pentru care s-a făcut căutarea după nume. Ce se
 * spune atunci în plus, v. `anunt_randuri_oameni()`.
 */
function anunt_aducator(?int $clientId, ?string $cod, ?int $stiut, bool $dupaNume = false, array $urme = []): ?int
{
    $stiut = $stiut !== null && $stiut > 0 ? $stiut : null;
    try {
        if ($clientId === 0) {
            return 0;
        }
        if (!referral_supported()) {
            return $stiut;
        }
        if ($clientId !== null && $clientId > 0) {
            $id = (int) scalar(
                'SELECT r.id
                   FROM users u JOIN users r ON r.id = u.referred_by
                  WHERE u.id = ? AND r.active = 1 AND r.is_system = 0',
                [$clientId]
            );
            if ($id > 0) {
                return $id;
            }
            if ($dupaNume) {
                return null;
            }
        }
        if ($stiut !== null) {
            return $stiut;
        }
        $cod = trim((string) $cod);
        if ($cod !== '') {
            $stapan = referral_owner($cod);
            if ($stapan && (int) $stapan['id'] > 0
                && referral_potrivire_cumparator((int) $stapan['id'], $urme) === null) {
                return (int) $stapan['id'];
            }
        }
    } catch (Throwable $e) {
        error_log('[gp] anunț: nu pot afla cine a adus clientul: ' . $e->getMessage());
        // Pe hârtia găsită după nume, cel știut e codul celui care a scris-o,
        // nu răspunsul la „al cui e clientul": fără citirea contului, nu se știe.
        return $dupaNume ? 0 : ($stiut ?? 0);
    }
    return null;
}

/**
 * Rândurile despre oameni: cine a scris hârtia, cine a primit-o, al cui e
 * clientul.
 *
 * CE CITEȘTE. Cheia `anunt`, pe care ruta o agață de comandă (ori de cerere)
 * DUPĂ ce răspunsul HTTP a plecat — după `json_out_early()`. Așa numele
 * oamenilor din casă nu ajung niciodată în răspunsul pe care îl primește
 * vizitatorul de pe site, iar semnăturile lui `notify_new_order()` și
 * `notify_new_request()` rămân neatinse:
 *
 *   autorId      contul care a scris hârtia (gol la cea venită de pe site)
 *   dePeSite     a trimis-o clientul, de pe site
 *   acceptataDe  contul care a acceptat cererea din care s-a născut comanda
 *   prin         pe unde a acceptat-o: „panou" ori „telegram"
 *   aducatorId   recomandantul deja știut — v. `anunt_aducator()`
 *
 * Fără cheie nu se scrie niciun rând: o funcție care ghicește cine a scris
 * comanda e mai rea decât una care tace.
 *
 * FORMELE, toate cerute de patron:
 *
 *   Scrisă de: Ana Pop (agent de vânzări)
 *   Client adus de: Mihai Ionescu (agent de vânzări)
 *   Atenție: a scris-o alt agent decât cel al clientului.
 *
 *   Trimisă de client, de pe site
 *   Acceptată de: Octavian (administrator)
 *   Client adus de: nimeni (client al casei)
 *
 *   Client adus de: Ana Pop (agent de vânzări) — tot cine a scris comanda
 *
 * Ultima ține locul a două rânduri: când omul care a scris hârtia e chiar cel
 * care a adus clientul, numele lui spus de două ori ar fi fost de citit de
 * două ori, pe telefon, pentru un singur fapt.
 *
 * Și una adăugată la recitirea rundei 2.6.0, cu vorba încă de ales de patron:
 *
 *   Scrisă de: Ana Pop (agent de vânzări)
 *   Client adus de: nimeni (client al casei)
 *   Atenție: clientul e mai vechi al casei, iar cererea poartă codul agentului.
 *
 * „ATENȚIE" SE APRINDE NUMAI PENTRU UN AGENT. Administratorul și managerul
 * scriu comenzi pentru clienții oricui, asta le e munca; agentul de vânzări
 * care scrie pe clientul altui om e singurul caz pe care patronul vrea să-l
 * vadă fără să-l caute. Sunt două asemenea cazuri, fiecare cu vorba lui
 * (`anunt_atentie()`): clientul e al ALTUI AGENT, ori clientul e AL CASEI —
 * cont vechi, adus de nimeni —, iar agentul a scris pe numele lui o cerere
 * care poartă un cod. La al doilea, comisionul comenzii chiar se scrie pe
 * codul acela (hârtia rămâne nelegată de cont, deci cârligul n-are de unde ști
 * că firma e un client vechi); de aceea trebuie văzut de un om.
 *
 * RÂNDUL „CLIENT ADUS DE" LIPSEȘTE CÂND NU S-A PUTUT AFLA: citirea a căzut,
 * ori numele de pe hârtie n-a putut hotărî un cont (cont încă nerecunoscut,
 * două conturi cu același nume). „Nimeni" ar fi o afirmație, iar acolo n-avem
 * ce afirma — v. `anunt_client()`.
 *
 * LA „ACCEPTATĂ DE", NUMELE E AL CONTULUI PE CARE S-A LUCRAT. Din panou e chiar
 * omul. De pe butonul din Telegram e contul legat de cel care a apăsat — ori,
 * dacă acela nu și-a legat Telegramul de niciun cont, contul care a legat botul
 * (`telegram_actor()`, api/routes/telegram.php). De aceea rândul spune și „de
 * pe Telegram": cine citește știe atunci că numele e al legăturii, nu neapărat
 * al degetului.
 *
 * @param string $hartia  „comanda" ori „cererea", pentru rândul cel scurt
 */
function anunt_randuri_oameni(array $x, string $hartia = 'comanda'): array
{
    $a = $x['anunt'] ?? null;
    if (!is_array($a)) {
        return [];
    }
    $id = static fn($v): ?int => is_numeric($v) && (int) $v > 0 ? (int) $v : null;

    $dePeSite   = !empty($a['dePeSite']);
    $autorId    = $dePeSite ? null : $id($a['autorId'] ?? null);
    $autor      = anunt_cont($autorId);
    $eAgent     = $autor !== null && $autor['role'] === 'agent_vanzari';
    $legatDe    = $id($x['customerId'] ?? null);
    $clientId   = anunt_client($x, !$dePeSite && $autorId !== null);
    // Contul n-a stat pe hârtie: l-a găsit căutarea după nume.
    $dupaNume   = $legatDe === null && $clientId !== null && $clientId > 0;
    // Hârtia, cu cheile pe care le cere poarta de auto-recomandare a cârligului
    // de comision. `request_id` spune „a venit printr-o cerere": cererea e ea
    // însăși una, iar comanda o poartă pe cea din care s-a născut.
    $urme       = [
        'customer_id'   => $legatDe,
        'customer_name' => (string) ($x['customerName'] ?? ''),
        'phone'         => (string) ($x['phone'] ?? ''),
        'request_id'    => $id($hartia === 'cererea' ? ($x['id'] ?? null) : ($x['requestId'] ?? null)),
    ];
    $cod        = trim((string) ($x['referralCode'] ?? ''));
    $aducatorId = anunt_aducator($clientId, $cod, $id($a['aducatorId'] ?? null), $dupaNume, $urme);
    $aducator   = anunt_om($aducatorId);
    // Același om la amândouă capetele — se spune o dată, pe rândul aducătorului.
    $totEl      = $autor !== null && $aducator !== null && $autorId === $aducatorId;

    $out = [];
    if ($dePeSite) {
        $out[] = 'Trimisă de client, de pe site';
    } elseif ($autor !== null && !$totEl) {
        $out[] = 'Scrisă de: ' . $autor['name'] . ' (' . anunt_rol($autor['role']) . ')';
    }

    // Cine a acceptat cererea — numai dacă e altcineva decât cel care a scris-o
    // (un manager își poate accepta singur cererea, iar atunci rândul de sus a
    // spus deja tot).
    $acceptaId = $id($a['acceptataDe'] ?? null);
    if ($acceptaId !== null && $acceptaId !== $autorId) {
        $om = anunt_om($acceptaId);
        if ($om !== null) {
            $out[] = 'Acceptată de: ' . $om . (($a['prin'] ?? '') === 'telegram' ? ', de pe Telegram' : '');
        }
    }

    if ($aducatorId === null) {
        $out[] = 'Client adus de: nimeni (client al casei)';
        // Clientul vechi al casei, găsit după nume, pe hârtia cu cod a unui
        // agent. Fără cod pe hârtie nu e nimic de semnalat: nu se scrie niciun
        // comision, iar „poartă codul agentului" ar fi o minciună.
        if ($dupaNume && $eAgent && $cod !== '') {
            $out[] = anunt_atentie('clientVechi');
        }
    } elseif ($aducator === null) {
        // Nu s-a putut afla (ori contul nu mai e): rândul lipsește. „Nimeni"
        // ar fi o afirmație, iar aici n-avem ce afirma.
    } elseif ($totEl) {
        $out[] = 'Client adus de: ' . $aducator . ' — tot cine a scris ' . $hartia;
    } else {
        $out[] = 'Client adus de: ' . $aducator;
        if ($eAgent) {
            $out[] = anunt_atentie('altAgent');
        }
    }
    return $out;
}

/**
 * Vorbele rândului „Atenție", toate într-un singur loc.
 *
 * Stau deoparte de `anunt_randuri_oameni()` fiindcă sunt ale patronului de
 * ales și de schimbat: cine le schimbă le găsește aici, câte o dată, nu
 * printre condițiile care le aprind. `.claude/check-anunturi.mjs` numără ca în
 * tot fișierul să nu mai fie scrisă nicio altă vorbă care începe cu „Atenție:".
 *
 *   altAgent     clientul e al altui agent decât cel care a scris hârtia
 *   clientVechi  clientul e al casei (cont vechi, adus de nimeni), iar agentul
 *                a scris pe numele lui o cerere care poartă un cod
 *
 * A doua spune „cererea" și pe comandă, dinadins: agentul nu scrie comenzi,
 * scrie cereri, iar comanda pe care stă rândul e cea născută din cererea lui.
 */
function anunt_atentie(string $care): string
{
    return [
        'altAgent'    => 'Atenție: a scris-o alt agent decât cel al clientului.',
        'clientVechi' => 'Atenție: clientul e mai vechi al casei, iar cererea poartă codul agentului.',
    ][$care];
}

/**
 * Rândul zilei: „Pentru: 2026-10-03, ora 08:00 (oră fixă)".
 *
 * Ora e intervalul ales din lista din Setări; „(oră fixă)" se adaugă numai
 * când comanda poartă bifa și are o oră de care să se lege — fără oră, bifa
 * n-ar spune nimic.
 */
function anunt_rand_zi(array $x): string
{
    $ora = trim((string) ($x['slot'] ?? ''));
    return 'Pentru: ' . ($x['day'] ?? '')
        . ($ora !== '' ? ', ora ' . $ora . (!empty($x['timed']) ? ' (oră fixă)' : '') : '');
}

/**
 * Rândurile despre loc: cum se onorează și unde merge marfa.
 *
 *   Onorare: Livrare
 *   Adresă: Str. Probei 1 · Sector 2
 *
 *   Onorare: Ridicare — vine clientul după marfă
 *
 * LA RIDICARE NU SE SCRIE NICIO ADRESĂ, dinadins: adresa de pe o comandă cu
 * ridicare e a clientului, nu a drumului, iar pe telefon ar fi citită drept
 * „du marfa acolo". Care moduri sunt de ridicare se întreabă lista din Setări
 * (`pickup_fulfillments()`), nu vorba „Ridicare" — modurile se redenumesc.
 *
 * „Onorare:" apare numai când modul e ales: cererea de pe site n-are niciunul,
 * fiindcă vizitatorul nu alege (îl pune cine o acceptă).
 *
 * Zona stă lângă adresă, pe același rând — e tot răspunsul la „unde". Pe o
 * hârtie fără adresă rămâne singură, cu numele ei.
 */
function anunt_randuri_loc(array $x): array
{
    $out      = [];
    $mod      = trim((string) ($x['fulfillment'] ?? ''));
    $ridicare = anunt_e_ridicare($x);
    if ($mod !== '') {
        $out[] = 'Onorare: ' . $mod . ($ridicare ? ' — vine clientul după marfă' : '');
    }
    if (!$ridicare) {
        $adresa = trim((string) ($x['address'] ?? ''));
        $zona   = trim((string) ($x['zone'] ?? ''));
        if ($adresa !== '') {
            $out[] = 'Adresă: ' . $adresa . ($zona !== '' ? ' · ' . $zona : '');
        } elseif ($zona !== '') {
            $out[] = 'Zonă: ' . $zona;
        }
    }
    return $out;
}

/**
 * Vine clientul după marfa de pe hârtia asta?
 *
 * Se întreabă lista din Setări (`pickup_fulfillments()`), nu vorba „Ridicare":
 * modurile se redenumesc. Hârtia fără mod ales nu e ridicare. Dacă lista nu se
 * poate citi, răspunsul e „nu" — mesajul pleacă atunci cu adresa pe el, ca la
 * o livrare, mai degrabă decât să nu plece.
 *
 * Stă deoparte fiindcă o întreabă două mesaje: „Comandă nouă" (prin
 * `anunt_randuri_loc()`) și „Comandă livrată" (`format_order_status_message()`).
 */
function anunt_e_ridicare(array $x): bool
{
    $mod = trim((string) ($x['fulfillment'] ?? ''));
    if ($mod === '') {
        return false;
    }
    try {
        return in_array($mod, pickup_fulfillments(), true);
    } catch (Throwable $e) {
        error_log('[gp] anunț: nu pot citi modurile de ridicare: ' . $e->getMessage());
        return false;
    }
}

/**
 * Rândurile cu produse: „· 24 × Croissant cu unt — congelat".
 *
 * LATURA SE SPUNE PE FIECARE RÂND, în vorbă (`latura_cuvant()`, api/lib/laturi.php),
 * fiindcă o comandă poate fi amestecată: același croissant, o dată congelat și
 * o dată copt, sunt două lucruri deosebite pentru laborator și două prețuri.
 * Rândul care n-are latura lui o ia pe a hârtiei (`$implicit`); ce nu e nici
 * „congelat", nici „copt" cade pe „congelat", ca peste tot în casă.
 *
 * DUPĂ `$max` RÂNDURI SE OPREȘTE și spune câte au rămas. Telegram refuză cu
 * totul un mesaj mai lung de 4096 de caractere: o comandă de optzeci de
 * poziții n-ar fi ajuns scurtată, n-ar fi ajuns deloc. Totalul de sub listă
 * rămâne al comenzii întregi.
 *
 * Pragul numără RÂNDURI, nu caractere, deci ține sub limită comanda obișnuită,
 * nu pe oricare: patruzeci de nume lungi, lângă un nume de client și o adresă
 * cât le țin coloanele și mențiuni de 2000, trec de ea. Pe acelea le taie
 * `send_telegram()` (`telegram_text_taiat()`), la coadă — pragul de aici
 * rămâne, fiindcă o listă oprită cu „… și încă 3 poziții", urmată de total, se
 * citește mai bine decât una ruptă la jumătatea unui rând.
 */
function anunt_randuri_pozitii(array $items, string $implicit = '', int $max = 40): array
{
    $max      = max(1, $max);
    $implicit = latura_normalizeaza($implicit);
    $out      = [];
    foreach (array_slice($items, 0, $max) as $it) {
        $out[] = sprintf(
            '· %s × %s — %s',
            rtrim(rtrim(number_format((float) ($it['qty'] ?? 0), 2, ',', ''), '0'), ','),
            (string) ($it['name'] ?? ''),
            latura_cuvant(latura_normalizeaza($it['variant'] ?? '', $implicit))
        );
    }
    $rest = count($items) - $max;
    if ($rest > 0) {
        // „încă 3 poziții", „încă 20 de poziții", „încă o poziție".
        $cate = $rest === 1
            ? 'o poziție'
            : $rest . ($rest % 100 === 0 || $rest % 100 >= 20 ? ' de' : '') . ' poziții';
        $out[] = '… și încă ' . $cate . ' — vezi în panou';
    }
    return $out;
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
        throw new RuntimeException('Funcția mail() a eșuat. Cel mai des, adresa '
            . 'expeditoare nu e pe domeniul tău și serverul o refuză — schimb-o din '
            . 'Setări → General și încearcă din nou butonul de test.');
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
    $payload = ['chat_id' => $chatId, 'text' => telegram_text_taiat($text)];

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
 * Textul, scurtat cât să-l primească Telegram.
 *
 * Telegram nu scurtează un mesaj mai lung de 4096 de caractere: îl refuză cu
 * totul. Fără tăietura asta, tocmai comanda cea mai mare — patruzeci de
 * poziții cu nume lungi, mențiuni lungi — nu mai ajungea pe telefon deloc;
 * rămânea un rând „a eșuat" în Setări, de găsit de cine s-ar fi uitat. E sora
 * tăieturii de 1000 din `send_whatsapp()`.
 *
 * SE TAIE LA 4000, NU LA 4096. Sub mesajul unei cereri, telefonul mai lipește
 * urma hotărârii — „✅ Acceptată de pe telefon", cu două rânduri goale
 * înainte (`telegram_toast()`, api/routes/telegram.php) —, iar
 * `editMessageText` are aceeași limită. Un mesaj trimis plin ochi ar fi rămas
 * fără urmă, deci cu hotărârea nescrisă sub el.
 *
 * SE NUMĂRĂ CUM NUMĂRĂ TELEGRAM, în unități UTF-16: un semn din afara planului
 * de bază — un croissant desenat în mențiuni — ține cât două. Numărate cu
 * `mb_strlen()`, două mii de asemenea semne ar fi trecut drept jumătate din
 * cât sunt. De aceea tăietura se face pe textul trecut în UTF-16, iar dacă
 * marginea cade în mijlocul unui semn de două unități, se lasă afară cu totul:
 * o jumătate de semn face mesajul nevalid.
 *
 * Coada spune pe față că mesajul e tăiat și unde e restul. Mesajul care încape
 * pleacă neatins.
 */
function telegram_text_taiat(string $text): string
{
    $max = 4000;
    $u16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    if (strlen($u16) <= 2 * $max) {
        return $text;
    }
    $coada = "\n" . '… (mesaj tăiat — vezi în panou)';
    $cap   = substr($u16, 0, 2 * ($max - mb_strlen($coada)));
    // Prima jumătate a unui semn de două unități (D800–DBFF), rămasă singură.
    $ultima = unpack('v', substr($cap, -2))[1];
    if ($ultima >= 0xD800 && $ultima <= 0xDBFF) {
        $cap = substr($cap, 0, -2);
    }
    return rtrim(mb_convert_encoding($cap, 'UTF-8', 'UTF-16LE')) . $coada;
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
