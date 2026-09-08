<?php
/** Comenzi: creare de pe site, listare în panouri, schimbare de status. */

declare(strict_types=1);

// Etapele nu mai sunt fixe în cod: se citesc din Setări cu order_statuses().

/* -------------------------------------------------------------- listare -- */

/**
 * GET /orders?day=YYYY-MM-DD&status=...&from=&to=
 *
 * Personalul vede toate comenzile; un client vede exclusiv comenzile lui —
 * filtrul se aplică pe server, nu în interfață.
 */
function route_orders_list(): void
{
    $u = require_auth();

    $where  = [];
    $params = [];

    if ($u['role'] === 'client') {
        $where[]  = 'o.customer_id = ?';
        $params[] = (int) $u['id'];
    }

    $day = trim((string) ($_GET['day'] ?? ''));
    if ($day !== '') {
        $where[]  = 'o.day = ?';
        $params[] = valid_day($day);
    }

    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    if ($from !== '') {
        $where[]  = 'o.day >= ?';
        $params[] = valid_day($from);
    }
    if ($to !== '') {
        $where[]  = 'o.day <= ?';
        $params[] = valid_day($to);
    }

    $status = trim((string) ($_GET['status'] ?? ''));
    if ($status !== '') {
        $list = array_values(array_intersect(explode(',', $status), order_statuses()));
        if (!$list) {
            fail('Status necunoscut.', 422, 'bad_status');
        }
        $where[] = 'o.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
        $params  = array_merge($params, $list);
    }

    $sql = 'SELECT o.* FROM orders o'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY o.day DESC, o.seq DESC LIMIT 500';

    $orders = all($sql, $params);
    json_out(['orders' => attach_items($orders)]);
}

function route_orders_get(string $id): void
{
    $u  = require_auth();
    $o  = one('SELECT * FROM orders WHERE id = ?', [(int) $id]);
    if (!$o) {
        fail('Comanda nu există.', 404, 'not_found');
    }
    if ($u['role'] === 'client' && (int) $o['customer_id'] !== (int) $u['id']) {
        fail('Nu ai acces la această comandă.', 403, 'forbidden');
    }
    json_out(['order' => attach_items([$o])[0]]);
}

/* --------------------------------------------------------------- creare -- */

/**
 * POST /orders — numai personalul.
 *
 * Ruta era deschisă oricui, „fiindcă site-ul public trebuie să poată primi
 * comenzi". Nu e adevărat: site-ul trimite `POST /requests`, iar cererea
 * devine comandă abia după ce o accepți. Singurul care cheamă ruta asta e
 * panoul de comenzi — comanda luată la telefon sau la ghișeu.
 *
 * Cât timp a fost deschisă, oricine de pe internet putea sări peste treapta de
 * aprobare și băga marfă direct în laborator, la orice zi și în orice
 * cantitate: producția pornea pe o comandă pe care n-o ceruse nimeni.
 */
function route_orders_create(): void
{
    $user = require_staff();
    $b    = body();

    $items = $b['items'] ?? [];
    if (!is_array($items) || !$items) {
        fail('Comanda trebuie să conțină cel puțin un produs.', 422, 'empty_order');
    }
    if (count($items) > 200) {
        fail('Prea multe poziții în comandă.', 422, 'too_many_items');
    }

    // `gmdate()` dă ziua UTC. Între 00:00 și 03:00 ora României — tocmai orele
    // în care laboratorul lucrează pentru livrarea de dimineață — o comandă
    // luată la telefon fără zi se scria pe ziua de IERI, iar Panoul Laborator,
    // care cere fereastra azi..azi+6, n-o mai arăta deloc.
    $day = valid_day_not_too_far(trim((string) ($b['day'] ?? '')) ?: today_local());

    // Comanda o scrie personalul, pentru altcineva: numele vine din formular
    // și e obligatoriu. Înainte, un formular fără nume nu era refuzat — se
    // punea tăcut numele celui autentificat, iar comanda ajungea în laborator
    // și pe ruta șoferului drept „Administrator Test", fără client.
    $customerName = text_field($b['customerName'] ?? '', 'Numele clientului', 160, true);
    $phone        = text_field($b['phone'] ?? '', 'Telefonul', 32);
    $address      = text_field($b['address'] ?? '', 'Adresa', 255);
    $notes        = text_field($b['notes'] ?? '', 'Mențiunile', 2000);
    $channel      = in_array(($b['channel'] ?? ''), ['site', 'telefon', 'whatsapp', 'panou'], true)
        ? $b['channel'] : 'site';

    // Comanda luată la telefon se leagă de contul clientului, dacă numele îl
    // arată fără dubiu. Altfel clientul n-o vede niciodată în „Comanda mea":
    // panoul lui întoarce doar ce e legat de cont.
    $customerId = client_by_name($customerName);

    $slot = trim((string) ($b['slot'] ?? ''));
    if ($slot !== '' && !in_array($slot, delivery_slots(), true)) {
        fail('Interval de livrare necunoscut: ' . $slot, 422, 'bad_slot');
    }

    // Zona se verifică față de lista din Setări, exact ca la cereri. Fără
    // verificare, o zonă inexistentă („Centru", rămasă din prototip) trecea, iar
    // ruta șoferului o punea pe coordonatele depozitului: oprirea apărea peste
    // magazie, iar drumul ieșea calculat greșit.
    $zone  = trim((string) ($b['zone'] ?? ''));
    $zones = delivery_zones();
    if ($zone !== '' && $zones && !in_array($zone, $zones, true)) {
        fail('Zonă necunoscută: ' . $zone, 422, 'bad_zone');
    }

    // Marcajele se puneau doar prin PATCH, după creare: bifa „URGENT" din
    // formularul de comandă nouă se pierdea, iar comanda intra în laborator ca
    // una obișnuită — adică la coadă.
    $urgent = !empty($b['urgent']);
    $timed  = !empty($b['timed']);

    $fulfillment = trim((string) ($b['fulfillment'] ?? '')) ?: 'Livrare';
    if (!in_array($fulfillment, fulfillment_types(), true)) {
        fail('Mod de onorare necunoscut: ' . $fulfillment, 422, 'bad_fulfillment');
    }

    // Prețul se ia din baza de date, nu din ce trimite browserul.
    // Varianta decide prețul și se poate da pe fiecare poziție: comanda luată
    // la telefon poate cuprinde și congelate, și coapte. Fără ea, poziția
    // rămâne pe implicitul comenzii.
    $variant = require_variant($b['variant'] ?? null);
    [$priced, $total] = price_items($items, $variant);
    $variant = order_variant_of($priced);

    $statuses = order_statuses();
    $first    = $statuses[0] ?? 'În așteptare';

    $pdo = db();
    $orderId = null;

    // UNIQUE(day, seq) împiedică două comenzi simultane să primească același
    // număr; dacă se întâmplă, reluăm cu următorul număr liber.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $pdo->beginTransaction();
            $seq = (int) scalar('SELECT COALESCE(MAX(seq), 0) + 1 FROM orders WHERE day = ?', [$day]);
            q(
                'INSERT INTO orders (seq, ref, customer_id, customer_name, phone, address, zone, day,
                                     slot, fulfillment, channel, status, notes, total, created_by,
                                     variant, urgent, timed)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $seq, '#' . (1000 + $seq), $customerId, $customerName, $phone ?: null,
                    $address ?: null, $zone ?: null, $day, $slot ?: null,
                    $fulfillment, $channel, $first, $notes ?: null, round($total, 2),
                    (int) $user['id'], $variant, $urgent ? 1 : 0, $timed ? 1 : 0,
                ]
            );
            $orderId = (int) $pdo->lastInsertId();

            foreach ($priced as $p) {
                q(
                    'INSERT INTO order_items (order_id, product_id, name, variant, qty, unit_price) VALUES (?, ?, ?, ?, ?, ?)',
                    [$orderId, $p['id'], $p['name'], $p['variant'], $p['qty'], $p['unit']]
                );
            }
            // Promisiunea se naște odată cu poziția, în aceeași tranzacție:
            // dacă e scrisă mai târziu, între cele două clipe depozitul spune
            // că are făină liberă pe care a promis-o deja.
            stock_reserve_order($orderId);
            $pdo->commit();
            break;
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() !== '23000' || $attempt === 4) {
                throw $e;
            }
        }
    }

    $order = attach_items([one('SELECT * FROM orders WHERE id = ?', [$orderId])])[0];

    audit('order_create', 'order', (string) $orderId, 'Comandă #' . $order['seq'] . ' — ' . $customerName);

    // Răspunsul pleacă înaintea anunțurilor, la fel ca la cereri. Comanda e
    // deja scrisă și comisă; anunțurile se duc pe rând la e-mail, la Meta și la
    // Telegram, unde un furnizor căzut ține conexiunea câte cincisprezece
    // secunde. Trimise întâi, țineau butonul „Salvează" apăsat o jumătate de
    // minut, iar peste `max_execution_time` procesul era ucis în timpul
    // apelului: panoul arăta eroare pentru o comandă care exista, operatorul o
    // introducea din nou și în laborator ajungeau două comenzi identice.
    json_out_early(['order' => $order], 201);

    // De aici încolo nimic nu mai ajunge la client. Un anunț care nu pleacă
    // rămâne scris în `notifications` cu status „failed", cu tot cu numărul de
    // încercări și motivul, de unde se vede în Setări ce s-a pierdut.
    try {
        notify_new_order($order);
    } catch (Throwable $e) {
        error_log('[gp] notificare eșuată pentru comanda ' . $orderId . ': ' . $e->getMessage());
    }
    // Fără oprire, dispecerul din index.php ar mai scrie octeți după un răspuns
    // deja trimis.
    exit;
}

/* ------------------------------------------------------------ modificare -- */

/**
 * Ce poate schimba prin PATCH /orders/{id} un cont care nu e de administrator.
 *
 * Doar etapa — exact ce fac cele două ecrane de tură: tableta din laborator
 * împinge comanda pe flux („Panou Laborator"), iar telefonul șoferului o trece
 * pe livrată („Ruta Șofer"). Amândouă trimit `{ status }` și nimic altceva.
 *
 * Restul câmpurilor — clientul, contul de care se leagă comanda, telefonul,
 * adresa, ziua, intervalul, zona, modul de onorare, referința, mențiunile,
 * marcajele și pozițiile — rămân ale administratorului. Motivul e contul comun
 * al tabletelor („panou", rol angajat): se deschide cu un cod știut de toată
 * tura, iar cu el un PATCH cu `customerName` pus pe numele unei firme lega
 * comanda de contul acelui client, care o vedea de-atunci în „Comanda mea" —
 * cu adresa, telefonul și prețurile altcuiva.
 */
const ORDER_FIELDS_STAFF = ['status'];

/**
 * PATCH /orders/{id} — orice câmp al comenzii, inclusiv pozițiile.
 *
 * Ruta accepta doar status, note, zi, interval, zonă și mod de onorare. Formularul
 * din panou arată însă și clientul, telefonul, adresa, referința și pozițiile:
 * cine le corecta și apăsa „Salvează modificările" primea confirmarea „a fost
 * salvată", dar în bază nu se schimba nimic din ele. Corectura unei adrese
 * greșite sau a unei cantități se pierdea în tăcere, iar comanda pleca pe teren
 * cu datele vechi.
 *
 * Pozițiile se rescriu întregi (se șterg și se pun la loc), iar prețul îl pune
 * tot serverul: browserul nu poate impune un preț. Nu se ia însă din catalog
 * pentru toate — bucățile deja confirmate își păstrează prețul cu care au fost
 * confirmate, iar la prețul de azi se tarifează doar cantitatea adăugată acum
 * (`honored_prices()` + `price_items()`).
 *
 * Din contul de tură trece o singură cheie, vezi ORDER_FIELDS_STAFF mai sus.
 *
 * Când corectura atinge o comandă deja scăzută din depozit, marfa se întoarce
 * întâi în depozit (`stock_unsync_order()`) și comanda redevine „de scăzut".
 * Fără asta, cantitatea urcată la telefon nu ajungea niciodată în registru,
 * iar comanda anulată rămânea scăzută deși marfa era în rafturi.
 */
function route_orders_update(string $id): void
{
    $u = require_staff();
    $b = body();

    // Poarta stă înaintea oricărei citiri din bază: din contul comun al
    // tabletelor nu trebuie să se poată afla nici măcar dacă o comandă există,
    // încercând să i se schimbe clientul.
    //
    // Cererea se oprește întreagă, nu se curăță de câmpurile interzise: un
    // `customerName` strecurat lângă `status` ar fi trecut altfel neobservat
    // odată cu apăsarea firească a brutarului, exact pe drumul pe care nimeni
    // nu se uită.
    if ($u['role'] !== 'admin') {
        $oprite = array_values(array_diff(array_keys($b), ORDER_FIELDS_STAFF));
        if ($oprite) {
            fail(
                'Din contul de personal se poate schimba doar starea comenzii, adică etapa ei. '
                    . 'Câmpuri refuzate: ' . implode(', ', $oprite) . '. '
                    . 'Restul se corectează din „Administrare Comenzi", cu un cont de administrator.',
                403,
                'staff_field_denied'
            );
        }
    }

    $o = one('SELECT * FROM orders WHERE id = ?', [(int) $id]);
    if (!$o) {
        fail('Comanda nu există.', 404, 'not_found');
    }

    $sets = [];
    $vals = [];

    // Contul se schimbă numai când cineva o cere pe față. `customerId: null`
    // dezleagă comanda de cont, un număr o leagă de contul acela — atât are
    // nevoie personalul ca să repare o legătură pusă greșit.
    if (array_key_exists('customerId', $b)) {
        $cid = $b['customerId'];
        if ($cid === null || $cid === '') {
            $sets[] = 'customer_id = ?';
            $vals[] = null;
        } else {
            $cid = (int) $cid;
            if (!one("SELECT id FROM users WHERE id = ? AND role = 'client'", [$cid])) {
                fail('Contul de client nu există.', 422, 'bad_customer');
            }
            $sets[] = 'customer_id = ?';
            $vals[] = $cid;
        }
    }

    if (array_key_exists('customerName', $b)) {
        $nume   = text_field($b['customerName'], 'Numele clientului', 160, true);
        $sets[] = 'customer_name = ?';
        $vals[] = $nume;
        // Numele de pe formular e text, nu identitate. Ruta refăcea legătura cu
        // contul din el la fiecare salvare, iar `client_by_name()` întoarce null
        // ori de câte ori contul a fost redenumit între timp, e dezactivat
        // temporar sau două conturi poartă același nume: o simplă schimbare de
        // interval ștergea `customer_id`, comanda dispărea din „Comanda mea" a
        // clientului și nu se mai lega niciodată la loc.
        //
        // Comanda luată la telefon rămâne acoperită: acolo clientul e doar un
        // nume, fără cont, și abia atunci căutarea are voie să lege — poate
        // adăuga o legătură care lipsește, niciodată să o șteargă pe cea bună.
        if ($o['customer_id'] === null && !array_key_exists('customerId', $b)) {
            $gasit = client_by_name($nume);
            if ($gasit !== null) {
                $sets[] = 'customer_id = ?';
                $vals[] = $gasit;
            }
        }
    }
    if (array_key_exists('phone', $b)) {
        $sets[] = 'phone = ?';
        $vals[] = text_field($b['phone'], 'Telefonul', 32) ?: null;
    }
    if (array_key_exists('address', $b)) {
        $sets[] = 'address = ?';
        $vals[] = text_field($b['address'], 'Adresa', 255) ?: null;
    }
    if (array_key_exists('ref', $b)) {
        // 24, cât ține coloana `orders.ref` (sql/001_schema.sql). Verificarea
        // cerea 32: o referință de 25–32 de caractere trecea de ea și pica abia
        // în MySQL, cu STRICT_TRANS_TABLES, pe 1406 „Data too long". Excepția
        // nu e SQLSTATE 23000, deci reîncercarea o arunca mai departe: omul
        // primea „Eroare internă", fără să afle ce câmp e de vină, iar
        // tranzacția anula tot ce corectase în formular — adresa, pozițiile,
        // starea. Aici se oprește cu numele câmpului și cu lungimea permisă.
        $ref = text_field($b['ref'], 'Referința', 24);
        if ($ref !== '') {
            $sets[] = 'ref = ?';
            $vals[] = $ref;
        }
    }

    if (array_key_exists('status', $b)) {
        if (!in_array($b['status'], order_statuses(), true)) {
            fail('Status necunoscut. Valori permise: ' . implode(', ', order_statuses()), 422, 'bad_status');
        }
        $sets[] = 'status = ?';
        $vals[] = $b['status'];
    }
    if (array_key_exists('notes', $b)) {
        // Aceeași măsură ca la creare. Aici se scria `trim((string) …)` direct
        // în coloană: un text lipit dintr-un fir de e-mail trecea de 65.535 de
        // octeți și pica în MySQL cu 1406, adică „Eroare internă" — iar
        // tranzacția anula tot ce corectase omul în formular, nu doar
        // mențiunile. Un tablou trimis în locul textului devenea, literalmente,
        // cuvântul „Array". Și rămânea ciudățenia că aceeași comandă, scrisă de
        // la zero, era refuzată la 2001 de caractere, dar corectată trecea.
        $sets[] = 'notes = ?';
        $vals[] = text_field($b['notes'], 'Mențiunile', 2000) ?: null;
    }
    $newDay = null;
    if (array_key_exists('day', $b)) {
        $newDay = valid_day_not_too_far((string) $b['day']);
        $sets[] = 'day = ?';
        $vals[] = $newDay;
    }
    if (array_key_exists('slot', $b)) {
        $slot = trim((string) $b['slot']);
        if ($slot !== '' && !in_array($slot, delivery_slots(), true)) {
            fail('Interval de livrare necunoscut: ' . $slot, 422, 'bad_slot');
        }
        $sets[] = 'slot = ?';
        $vals[] = $slot ?: null;
    }
    foreach (['urgent', 'timed'] as $flag) {
        if (array_key_exists($flag, $b)) {
            $sets[] = "$flag = ?";
            $vals[] = $b[$flag] ? 1 : 0;
        }
    }
    if (array_key_exists('zone', $b)) {
        // Zona se verifică față de lista din Setări, ca la creare și ca la
        // acceptarea unei cereri. Aici se scria direct în coloană: o zonă
        // ștearsă între timp din Setări, sau numele rămas din prototip, intra
        // liniștit în comandă și ajungea așa pe ruta șoferului — iar harta, care
        // caută zona în lista de coordonate, nu găsea nimic și desena oprirea
        // exact peste depozit. Drumul zilei se calcula cu ea acolo.
        //
        // Lista goală (nicio zonă configurată) lasă câmpul liber, ca la creare:
        // patiseria care nu lucrează pe zone nu trebuie să fie oprită de o
        // verificare fără obiect.
        $zonaNoua = text_field($b['zone'], 'Zona', 64);
        $zones    = delivery_zones();
        if ($zonaNoua !== '' && $zones && !in_array($zonaNoua, $zones, true)) {
            fail('Zonă necunoscută: ' . $zonaNoua . '. Valori permise: ' . implode(', ', $zones), 422, 'bad_zone');
        }
        $sets[] = 'zone = ?';
        $vals[] = $zonaNoua ?: null;
    }
    if (array_key_exists('fulfillment', $b)) {
        if (!in_array($b['fulfillment'], fulfillment_types(), true)) {
            fail('Mod de onorare necunoscut.', 422, 'bad_fulfillment');
        }
        $sets[] = 'fulfillment = ?';
        $vals[] = $b['fulfillment'];
    }

    // Pozițiile vin întregi sau deloc: lista trimisă o înlocuiește pe cea din
    // bază. Prețul nu se mai ia însă din catalog pentru toate — browserul
    // trimite doar produsul, cantitatea și varianta, iar bucățile care erau
    // deja pe comandă își păstrează prețul cu care au fost confirmate. Altfel o
    // singură bucată adăugată luni pe comanda de vineri retarifa și bucățile
    // convenite atunci: clientul primea altă factură decât comanda pe care o
    // dăduse, iar administratorul care apăsase „Salvează" nu avea de unde ști.
    //
    // Tot din rândurile astea iese și îngăduința pentru produsele scoase între
    // timp din catalog: fără ea, comanda rămânea de neatins — până și mărirea
    // cantității la CELĂLALT produs de pe ea era refuzată cu „bad_product",
    // fiindcă pozițiile se rescriu toate. Un produs scos din catalog tot nu se
    // poate ADĂUGA aici: lista cuprinde numai ce era pe comandă înainte.
    $priced = null;
    $nota   = '';
    // Cât s-a raportat făcut, cărat peste rescrierea pozițiilor. Se calculează o
    // singură dată, aici, în afara buclei de reîncercare: harta e materia primă
    // a împărțirii de mai jos, iar o reluare trebuie să pornească de la ea
    // întreagă.
    $facut  = [];
    if (array_key_exists('items', $b)) {
        $items = $b['items'];
        if (!is_array($items) || !$items) {
            fail('Comanda trebuie să conțină cel puțin un produs.', 422, 'empty_order');
        }
        if (count($items) > 200) {
            fail('Prea multe poziții în comandă.', 422, 'too_many_items');
        }
        $implicit = require_variant($b['variant'] ?? null, valid_variant($o['variant']));
        $vechile  = all(
            'SELECT product_id, variant, qty, done_qty, unit_price FROM order_items WHERE order_id = ? ORDER BY id',
            [(int) $id]
        );
        [$priced, $total] = price_items(
            $items,
            $implicit,
            honored_prices($vechile),
            array_filter(array_column($vechile, 'product_id'), static fn($x) => $x !== null)
        );
        $nota   = price_honored_note($priced);
        $facut  = produced_qty($vechile);
        $sets[] = 'total = ?';
        $vals[] = round($total, 2);
        $sets[] = 'variant = ?';
        $vals[] = order_variant_of($priced);
    }

    // Zăvorul „deja scăzut din depozit" se deschide la loc, în două situații —
    // singurele în care ce s-a scăzut nu mai seamănă cu ce pleacă la client:
    //
    //   a) comanda iese din etapa care o încheie. Cel mai des pe „Anulată":
    //      marfa nu mai pleacă nicăieri, deci trebuie să se întoarcă în
    //      evidență, altfel depozitul arată mai puțin decât are în rafturi și
    //      se comandă din nou marfă care există;
    //   b) i se rescriu pozițiile. Clientul sună și urcă de la 10 la 40 de
    //      bucăți: scăzute rămân 10, iar a doua apăsare pe „Scade comenzile"
    //      sărea peste comandă („0 comenzi, 0 bucăți"), fiindcă marcajul era
    //      încă pus. Diferența de 30 nu se mai regăsea niciodată.
    //
    // Aici se alege doar MOTIVUL, din ce s-a trimis; dacă marfa chiar e
    // scăzută hotărăște `stock_unsync_order()`, care citește rândul cu
    // FOR UPDATE. Întrebat aici, din `$o` — luat la intrarea în rută, fără
    // blocare — răspunsul poate fi deja vechi: între citire și tranzacție
    // cineva apasă „Scade comenzile", comanda se marchează scăzută cu
    // cantitățile vechi, iar salvarea asta îi rescrie pozițiile fără să mai
    // întoarcă nimic.
    //
    // Etapa de sosire se citește din ce s-a trimis, nu din ce era: comanda
    // tocmai se schimbă. Formularul din panou trimite `items` doar când chiar
    // s-au atins pozițiile (vezi `doarSchimbate` din „Administrare Comenzi"),
    // deci o corectură de telefon nu clatină degeaba registrul.
    $returStoc = null;
    $intoarse  = 0;
    $incheiata = status_done();
    $statusNou = array_key_exists('status', $b) ? (string) $b['status'] : null;
    if ($statusNou !== null && $incheiata !== '' && $statusNou !== $incheiata) {
        $returStoc = 'Retur: comandă ieșită din „' . $incheiata . '"';
    } elseif ($priced !== null) {
        $returStoc = 'Retur: pozițiile comenzii s-au schimbat';
    }

    if (!$sets) {
        fail('Nimic de modificat.', 422, 'nothing_to_update');
    }

    // Antetul și pozițiile se schimbă împreună: altfel o eroare la mijloc lasă
    // comanda cu totalul nou și pozițiile vechi.
    $pdo = db();
    $refSchimbat = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $s = $sets;
        $v = $vals;
        // Fiecare încercare pornește de la zero: dacă prima s-a izbit de un
        // „Duplicate entry" și a fost dată înapoi, referința ei nouă n-a fost
        // scrisă, deci nu are ce ajunge în jurnal.
        $refSchimbat = null;
        try {
            $pdo->beginTransaction();

            // Returul se scrie ÎNAINTE de rescrierea pozițiilor: după
            // `DELETE FROM order_items` nu mai există nicăieri ce anume s-a
            // scăzut din depozit pentru comanda asta. Fiind în aceeași
            // tranzacție, o cădere la mijloc nu lasă marfa întoarsă pe
            // jumătate.
            $intoarse = $returStoc === null
                ? 0
                : stock_unsync_order((int) $id, $returStoc, (int) $u['id']);

            // Numerotarea e per zi: `UNIQUE(day, seq)`. Mutată pe altă zi cu
            // numărul ei vechi, comanda fie se izbea de comanda care avea deja
            // acel număr acolo — iar panoul arăta „Eroare BD: Duplicate entry",
            // cu modificarea pierdută — fie lăsa în urmă o zi cu numere sărite.
            // Primește următorul număr liber din ziua nouă; referința (`ref`),
            // pe care o știe clientul, rămâne neatinsă.
            if ($newDay !== null && $newDay !== $o['day']) {
                $seqNou = (int) scalar('SELECT COALESCE(MAX(seq), 0) + 1 FROM orders WHERE day = ?', [$newDay]);
                $s[]    = 'seq = ?';
                $v[]    = $seqNou;

                // Numărul de oprire de pe rută se șterge.
                //
                // `route_seq` e locul comenzii pe foaia de drum, așezat de
                // administrator din PUT /routes — și e al zilei lui. Rămas
                // neatins, comanda venea în ziua nouă cu poziția din ziua veche
                // și se așeza PESTE oprirea care avea deja acel număr: ruta
                // gândită de administrator se rupea fără niciun semn, iar
                // șoferul pornea dimineața cu o oprire pe care nimeni n-o pusese
                // prima. Golită, comanda se așază la coada rutei
                // (`ORDER BY route_seq IS NULL, …`) și așteaptă să fie pusă la
                // locul ei. În ziua rămasă în urmă numerotarea are un gol — asta
                // nu supără pe nimeni, ordinea celorlalte opriri se păstrează.
                $s[] = 'route_seq = ?';
                $v[] = null;

                // Referința se schimbă doar dacă e ocupată în ziua nouă.
                //
                // `ref` se naște ca „#” + (1000 + seq), iar numerotarea
                // reîncepe în fiecare zi: fiecare zi are un „#1001”. Mutată,
                // comanda își ținea referința — pe bună dreptate, e cea pe care
                // o știe clientul — dar ajungea lângă comanda care avea deja
                // acel număr acolo. Foaia de drum arăta două opriri „#1001” la
                // doi clienți diferiți, iar șoferul care bifa „#1001 livrat” nu
                // spunea pe care. Așa că se păstrează cât se poate, și se
                // schimbă numai la ciocnire — atunci se scrie și în jurnal, ca
                // omul să-i poată spune clientului noua referință.
                $refNou = (string) $o['ref'];
                if ($refNou !== '' && scalar(
                    'SELECT 1 FROM orders WHERE day = ? AND ref = ? AND id <> ?',
                    [$newDay, $refNou, (int) $id]
                )) {
                    $candidat = '#' . (1000 + $seqNou);
                    for ($i = 2; $i < 100 && scalar(
                        'SELECT 1 FROM orders WHERE day = ? AND ref = ? AND id <> ?',
                        [$newDay, $candidat, (int) $id]
                    ); $i++) {
                        $candidat = '#' . (1000 + $seqNou) . '-' . $i;
                    }
                    $refSchimbat = [$refNou, $candidat];
                    $s[] = 'ref = ?';
                    $v[] = $candidat;
                }
            }

            $v[] = (int) $id;
            q('UPDATE orders SET ' . implode(', ', $s) . ' WHERE id = ?', $v);

            if ($priced !== null) {
                // Producția se RECITEȘTE sub lacăt, chiar înainte de rescriere.
                // `$facut` de mai sus s-a citit înaintea tranzacției, iar între
                // acea citire și lacătul pe rândul comenzii (luat de
                // stock_unsync_order) încap un raport de producție de pe
                // tabletă: cu harta veche, pozițiile se rescriau cu done_qty=0,
                // producția raportată dispărea fără urmă, iar următorul raport
                // (pe totaluri) scădea făina încă o dată. Rândul comenzii e deja
                // blocat; SELECT-ul de aici e explicit, ca regula să nu depindă
                // de ce face funcția de retur.
                one('SELECT id FROM orders WHERE id = ? FOR UPDATE', [(int) $id]);
                $facut = produced_qty(all(
                    'SELECT product_id, variant, done_qty FROM order_items WHERE order_id = ?',
                    [(int) $id]
                ));

                q('DELETE FROM order_items WHERE order_id = ?', [(int) $id]);
                // Producția raportată trece peste rescriere ca prețurile promise.
                // Se pleacă de la o COPIE a hărții, nu de la ea: blocul se reia la
                // conflict de cheie, iar a doua rulare trebuie să aibă de împărțit
                // tot atât, nu ce a mai rămas după prima.
                $ramas = $facut;
                foreach ($priced as $p) {
                    $cheie = ((int) $p['id']) . '|' . $p['variant'];
                    // Plafonat la cantitatea nouă: cine taie comanda de la 8 la 5
                    // după ce s-au făcut 6 nu are 6 făcute din 5. A șasea bucată nu
                    // se pierde — intrarea ei pe articolul finit e deja în registru
                    // și rămâne acolo, exact ca la o comandă anulată.
                    $done  = min((float) $p['qty'], (float) ($ramas[$cheie] ?? 0));
                    $ramas[$cheie] = ($ramas[$cheie] ?? 0) - $done;
                    q(
                        'INSERT INTO order_items (order_id, product_id, name, variant, qty, done_qty, unit_price) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [(int) $id, $p['id'], $p['name'], $p['variant'], $p['qty'], round($done, 2), $p['unit']]
                    );
                }
                // Rezervările vechi au plecat odată cu pozițiile vechi
                // (`ON DELETE CASCADE` pe `order_item_id`); aici se scriu cele
                // de pe pozițiile de acum.
                stock_reserve_order((int) $id);
            }
            $pdo->commit();
            break;
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() !== '23000' || $attempt === 4) {
                throw $e;
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    $detail = 'Comandă #' . $o['seq'];
    if (isset($b['status'])) {
        $detail .= ': ' . $o['status'] . ' → ' . $b['status'];
    }
    // Când s-a onorat un preț mai vechi decât catalogul, jurnalul o spune. Pe
    // ecran nu se vede nimic, iar altfel scumpirea din catalog ar părea aplicată
    // pe toate comenzile — și nu e: comenzile date înainte de ea se livrează la
    // prețul convenit, iar cine face socoteala trebuie să afle.
    if ($nota !== '') {
        $detail .= ' · preț onorat: ' . $nota;
    }
    // Cine a mutat comanda pe alt client, și de la cine. Fără rândul ăsta,
    // legătura schimbată nu lasă nicio urmă: comanda apare pur și simplu în
    // „Comanda mea" a altcuiva, iar la reclamație nu se mai poate afla când.
    if (array_key_exists('customerName', $b)
        && (string) $b['customerName'] !== (string) $o['customer_name']) {
        $detail .= ' · client: ' . ($o['customer_name'] ?? '—') . ' → ' . (string) $b['customerName'];
    }
    if (array_key_exists('customerId', $b)) {
        $nouCont = ($b['customerId'] === null || $b['customerId'] === '') ? '—' : (string) (int) $b['customerId'];
        $detail .= ' · cont client: ' . ($o['customer_id'] ?? '—') . ' → ' . $nouCont;
    }
    // Scris în jurnal pe FAPT, nu pe intenție: altfel o intrare de marfă
    // apărută singură în registru, fără urma celui care a făcut-o, arată ca o
    // greșeală de numărat, iar inventarul de seară se caută ore în șir.
    if ($intoarse > 0) {
        $detail .= ' · marfa s-a întors în depozit';
    }
    // Referința e singurul lucru pe care clientul îl ține minte. Schimbată în
    // tăcere, el ar suna a doua zi cu un număr care nu mai există nicăieri.
    if ($refSchimbat !== null) {
        $detail .= ' · referință schimbată: ' . $refSchimbat[0] . ' → ' . $refSchimbat[1]
            . ' (era deja folosită în ziua nouă)';
    }
    audit('order_update', 'order', $id, $detail);

    json_out(['order' => attach_items([one('SELECT * FROM orders WHERE id = ?', [(int) $id])])[0]]);
}

/* ------------------------------------------------------- producția din lot -- */

/**
 * Câte bucăți s-au făcut din fiecare poziție a comenzii, raportate din laborator.
 *
 * Rută separată, nu un câmp în plus la `PATCH /orders`: `ORDER_FIELDS_STAFF`
 * (mai sus) e un zid deliberat — angajatul mută comanda pe altă etapă și atât.
 * Raportarea are pe deasupra efecte proprii (scrie în registrul depozitului),
 * idempotență proprie și jurnal propriu, deci n-are ce căuta pe aceeași ușă.
 *
 * `doneQty` este TOTALUL NOU al poziției, nu adaosul. Tableta din laborator e
 * optimistă și reîncearcă singură; un adaos s-ar aduna de două ori la o
 * reîncercare sau la o dublă apăsare pe „+”, iar făina s-ar scădea de două ori
 * pentru aceleași croasante. Cu totaluri, o cerere pierdută pe drum e
 * inofensivă: următoarea o repară, fiindcă spune unde trebuie să se ajungă, nu
 * cu cât să se urce.
 *
 * Ce se scrie, pentru fiecare poziție care s-a mișcat față de cât era raportat:
 *
 *     materii prime    −1 × (diferență × cantitatea din rețetă)
 *     articolul finit  +1 × diferență
 *
 * adică exact pasul de producție: făina iese, croasantul intră. Scăderea de la
 * „Predată” (`route_stock_sync`, neatinsă) e pasul următor — croasantul pleacă.
 * Nu e nicio dublare: sunt trei fapte diferite, scrise ca trei rânduri.
 *
 * ÎN JOS E PERMIS. Cine tastează 8 în loc de 3 trebuie să poată îndrepta, iar
 * răspunsul casei la un rând greșit de registru e contra-rândul, nu ștergerea:
 * corecția scrie mișcările inverse, cu motivul „Corecție producție”. Nu e
 * „făina se întoarce în sac”, ci un număr care n-a fost niciodată adevărat.
 *
 * Ce NU face: materiile prime nu se întorc niciodată de la sine. Comanda
 * anulată după opt croasante făcute lasă făina consumată și cele opt croasante
 * `+1` pe stocul de finit — sunt în congelator și se vând altcuiva. Un consum
 * e un fapt, o rezervare e o promisiune; faptele nu se desfac.
 *
 * Stocul are voie să iasă pe minus. Făina a plecat fizic indiferent ce crede
 * aplicația, iar un refuz aici ar face aplicația să mintă despre ce e în
 * rafturi. Cifra negativă se vede în Depozit — asta e informația utilă.
 */
function route_order_production(string $id): void
{
    $u = require_staff();
    $b = body();

    $raport = $b['items'] ?? null;
    if (!is_array($raport) || !$raport) {
        fail('Trimite cel puțin o poziție cu cantitatea făcută.', 422, 'empty_report');
    }
    if (count($raport) > 200) {
        fail('Prea multe poziții într-un singur raport.', 422, 'too_many_items');
    }

    // Cifrele din mesaje se scriu ca la tejghea: „8”, nu „8,00”. Același tipar
    // ca în `num_field()` și în rezumatul comenzii din `notify.php`.
    $nr = static fn(float $x): string => rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',');

    $pdo = db();
    $pdo->beginTransaction();

    // `fail()` încheie scriptul pe loc. Chemat cu tranzacția deschisă, ar lăsa
    // rândurile prinse cu FOR UPDATE blocate până când găzduirea închide
    // conexiunea — iar tabletele din laborator s-ar opri una după alta pe
    // aceeași comandă. Toate refuzurile de mai jos trec pe aici.
    $opreste = static function (string $mesaj, int $status, string $cod) use ($pdo): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail($mesaj, $status, $cod);
    };

    $planuit = [];
    $miscari = 0;
    $bucati  = 0.0;
    $fara    = [];

    try {
        $o = one('SELECT id, ref, customer_name FROM orders WHERE id = ? FOR UPDATE', [(int) $id]);
        if (!$o) {
            $opreste('Comanda nu există.', 404, 'not_found');
        }

        // Zăvor și pe poziții, nu doar pe comandă: `PATCH /orders` le șterge și
        // le scrie la loc (`route_orders_update`), iar fără el raportul s-ar
        // scrie pe rânduri care tocmai au dispărut — mișcările de stoc ar
        // rămâne, `done_qty` nu.
        //
        // Zăvorul se pune pe interogarea goală, nu pe cea cu legături de mai
        // jos: `FOR UPDATE` peste un LEFT JOIN ar bloca și rândurile din
        // `stock_items`, adică ar pune raportarea unei comenzi în calea
        // depozitarului care tocmai înregistrează o livrare de făină.
        all('SELECT id FROM order_items WHERE order_id = ? ORDER BY id FOR UPDATE', [(int) $id]);

        // Potrivirea poziție → articol finit, copiată literă cu literă din
        // `route_stock_sync`. Dacă intrarea de la producție ia un articol și
        // scăderea de la „Predată” altul, croasantul intră într-un raft și iese
        // din altul: două cifre greșite în loc de una.
        $pozitii = all(
            'SELECT oi.id, oi.product_id, oi.name, oi.qty, oi.done_qty,
                    si.id AS item_id, si.name AS item_name
               FROM order_items oi
               LEFT JOIN stock_items si
                      ON si.id = (
                           SELECT s.id
                             FROM stock_items s
                            WHERE (s.product_id = oi.product_id AND s.product_id IS NOT NULL)
                               OR (s.product_id IS NULL AND s.name = oi.name)
                            ORDER BY (s.active = 0), (s.product_id IS NULL), s.id
                            LIMIT 1
                         )
              WHERE oi.order_id = ?
              ORDER BY oi.id',
            [(int) $id]
        );

        $dupaId = [];
        foreach ($pozitii as $p) {
            $dupaId[(int) $p['id']] = $p;
        }

        // Se verifică TOT înainte să se scrie ceva. Comanda e unitatea de
        // lucru, ca la scădere și la retur: un raport cu o poziție greșită n-are
        // voie să lase în urmă jumătate din mișcări, fiindcă a doua încercare —
        // cu aceleași totaluri — n-ar mai avea ce corecta.
        $vazute = [];
        foreach ($raport as $r) {
            if (!is_array($r)) {
                $opreste('Fiecare poziție raportată are nevoie de numărul ei și de cantitate.', 422, 'bad_item');
            }
            $rid = (int) ($r['id'] ?? 0);
            if ($rid <= 0 || !isset($dupaId[$rid])) {
                $opreste(
                    'Poziția raportată nu mai este pe comanda aceasta. Reîncarcă lista de comenzi — '
                        . 'între timp cineva a schimbat pozițiile din Administrare comenzi.',
                    422,
                    'bad_item'
                );
            }
            if (isset($vazute[$rid])) {
                $opreste('Aceeași poziție apare de două ori în raport.', 409, 'duplicate_item');
            }
            $vazute[$rid] = true;

            $p   = $dupaId[$rid];
            $cer = num_field($r['doneQty'] ?? null, 'Cantitatea făcută', 0, 99999999.99, 'bad_done_qty');
            $max = (float) $p['qty'];
            if ($cer > $max) {
                $opreste(
                    'Pe poziția „' . (string) $p['name'] . '” s-au cerut ' . $nr($max)
                        . ' bucăți, deci nu se pot raporta mai multe. Bucățile făcute în plus se înregistrează '
                        . 'ca intrare în Depozit, pe articolul lor — sunt marfă de vânzare, nu marfă a comenzii.',
                    422,
                    'bad_done_qty'
                );
            }

            $delta = round($cer - (float) $p['done_qty'], 2);
            if ($delta !== 0.0) {
                $planuit[] = ['p' => $p, 'nou' => $cer, 'delta' => $delta];
            }
        }

        // Rețetele, într-o singură interogare pentru toate produsele din raport:
        // altfel o comandă cu douăzeci de poziții ar face douăzeci de drumuri la
        // bază pentru ceva ce încape într-un `IN`.
        $reteta  = [];
        $produse = [];
        foreach ($planuit as $pl) {
            $pid = (int) ($pl['p']['product_id'] ?? 0);
            if ($pid > 0) {
                $produse[$pid] = true;
            }
        }
        if ($produse) {
            $pids  = array_keys($produse);
            $marks = implode(',', array_fill(0, count($pids), '?'));
            $linii = all(
                "SELECT pr.product_id, pr.item_id, pr.qty, si.name AS item_name
                   FROM product_recipes pr
                   JOIN stock_items si ON si.id = pr.item_id
                  WHERE pr.product_id IN ($marks)
                  ORDER BY pr.id",
                $pids
            );
            foreach ($linii as $l) {
                $reteta[(int) $l['product_id']][] = $l;
            }
        }

        // Aceeași însemnare ca la scădere și la retur, ca rândurile să se
        // citească pereche în registru: „#1042 Restaurant Bistro”.
        $nota = trim((string) ($o['ref'] ?? '') . ' ' . (string) ($o['customer_name'] ?? ''));

        foreach ($planuit as $pl) {
            $p     = $pl['p'];
            $delta = (float) $pl['delta'];
            $sens  = $delta > 0 ? 1 : -1;
            $cant  = abs($delta);
            // Motivele vin din vocabularul paginii Depozit (`IN_R` / `OUT_R`),
            // ca registrul să se citească la fel indiferent cine a scris rândul.
            // Corecția are motivul ei: e altceva decât o producție și trebuie să
            // se vadă din listă că e altceva.
            $motivFinit   = $delta > 0 ? 'Producție laborator' : 'Corecție producție';
            $motivMaterii = $delta > 0 ? 'Consum în producție' : 'Corecție producție';

            foreach ($reteta[(int) ($p['product_id'] ?? 0)] ?? [] as $l) {
                q(
                    'INSERT INTO stock_moves (item_id, name, qty, direction, reason, note, user_id, order_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        (int) $l['item_id'], (string) $l['item_name'],
                        round($cant * (float) $l['qty'], 4), -$sens,
                        $motivMaterii, $nota, (int) $u['id'], (int) $id,
                    ]
                );
                $miscari++;
            }

            if ($p['item_id'] !== null) {
                q(
                    'INSERT INTO stock_moves (item_id, name, qty, direction, reason, note, user_id, order_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        (int) $p['item_id'], (string) $p['item_name'],
                        $cant, $sens, $motivFinit, $nota, (int) $u['id'], (int) $id,
                    ]
                );
                $miscari++;
            } else {
                // Lipsa articolului finit nu oprește raportarea: laboratorul chiar
                // a făcut bucățile, iar materiile prime chiar au ieșit din rafturi.
                // Doar intrarea pe finit n-are unde se scrie, iar comanda rămâne
                // oricum blocată la „Predată”, exact ca azi. Se spune, nu se tace.
                $fara[] = (string) $p['name'];
            }

            q('UPDATE order_items SET done_qty = ? WHERE id = ?', [round((float) $pl['nou'], 2), (int) $p['id']]);

            // Ce s-a produs nu mai e promis: făina a ieșit din raft cu o mișcare
            // adevărată, scrisă adineaori. Fără rândul ăsta aceeași făină ar fi
            // numărată de două ori — o dată ca ieșită, o dată ca încă rezervată —
            // iar „Disponibil" din Depozit ar arăta mai puțin decât are patiseria.
            //
            // `r.qty` e cantitatea pe toată poziția (bucăți × rețetă), deci
            // `r.qty * done_qty / oi.qty` dă exact `done_qty × rețetă`, fără să
            // mai fie nevoie de o citire a rețetei. Marfa gata rămâne promisă
            // până pleacă: pe ea o stinge filtrul de status, nu producția.
            q(
                "UPDATE stock_reservations r
                    JOIN order_items oi ON oi.id = r.order_item_id
                     SET r.consumed = CASE WHEN r.kind = 'materie' AND oi.qty > 0
                                           THEN r.qty * oi.done_qty / oi.qty
                                           ELSE 0 END
                  WHERE r.order_item_id = ?",
                [(int) $p['id']]
            );
            $bucati += $delta;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Raportul care nu schimbă nimic nu se jurnalizează: tableta trimite cu
    // întârziere și reîncearcă singură, iar un rând de jurnal la fiecare apăsare
    // fără efect ar îneca exact rândurile care contează.
    if ($planuit) {
        audit(
        'order_production',
        'order',
        $id,
        count($planuit) . ' poziții · ' . ($bucati > 0 ? '+' : '') . $nr($bucati)
            . ' bucăți · ' . $miscari . ' mișcări de stoc'
        );
    }

    json_out([
        'order' => attach_items([one('SELECT * FROM orders WHERE id = ?', [(int) $id])])[0],
        'moves' => $miscari,
        // Pozițiile fără articol finit în depozit: intrarea nu s-a putut scrie
        // nicăieri. Pagina le arată, ca lipsa să nu se descopere abia la
        // „Scade comenzile”, când comanda se blochează.
        'missing' => array_values(array_unique($fara)),
    ]);
}

/* ----------------------------------------------------------- ruta șofer -- */

/**
 * Ce comandă este oprire pe ruta șoferului.
 *
 * Comenzile anulate nu au ce căuta pe rută: filtrul de dinainte căuta textul
 * 'anulat', care nu apare în nicio listă de etape, deci nu excludea nimic, iar
 * șoferul pleca la un client cu comanda anulată.
 *
 * Tot aici se scot modurile de onorare în care marfa nu pleacă cu mașina.
 * Alegerea o făcea pagina șoferului, comparând cu textul „Livrare” scris în
 * cod, deși modurile sunt o listă editabilă din Setări: un mod adăugat din
 * panou sau „Livrare” redenumită goleau ruta pe telefon, deși serverul
 * trimisese comenzile. Decizia stă acum într-un singur loc, lângă Setări, ca
 * ruta și numărătoarea zilelor să nu spună lucruri diferite.
 *
 * @return array{0: string, 1: array}  [condiția SQL, valorile ei]
 */
function route_stop_filter(): array
{
    $cond   = [];
    $params = [];

    $cancelled = status_cancelled();
    if ($cancelled !== '') {
        $cond[]   = 'status <> ?';
        $params[] = $cancelled;
    }

    $pickup = pickup_fulfillments();
    if ($pickup) {
        $cond[] = 'fulfillment NOT IN (' . implode(',', array_fill(0, count($pickup), '?')) . ')';
        $params = array_merge($params, $pickup);
    }

    return [$cond ? implode(' AND ', $cond) : '1', $params];
}

/** GET /routes?day= — opririle zilei, în ordinea comenzilor. */
function route_driver_routes(): void
{
    require_staff();
    $day = valid_day(trim((string) ($_GET['day'] ?? '')) ?: today_local());

    // Opririle cu ordine stabilită manual vin primele, în ordinea aceea;
    // restul, după intervalul promis.
    $order = 'ORDER BY route_seq IS NULL, route_seq, slot, seq';
    [$cond, $params] = route_stop_filter();
    array_unshift($params, $day);
    $orders = all("SELECT * FROM orders WHERE day = ? AND $cond $order", $params);

    json_out(['day' => $day, 'stops' => attach_items($orders)]);
}

/**
 * GET /routes/counts?from=&to= — câte opriri are fiecare zi din interval.
 *
 * Meniul de zile din ruta șoferului își lua numărul din comenzile aduse pentru
 * ziua deschisă — singurele pe care le are pagina, fiindcă cere serverului o
 * singură zi. Rezultatul: „0 opriri” în dreptul tuturor celorlalte zile, chiar
 * și când aveau livrări, iar șoferul nu deschidea ziua fiindcă meniul îl
 * asigura că nu are nimic de făcut acolo. Numărătoarea se face aici, cu
 * aceeași regulă ca ruta, ca meniul și lista să nu spună lucruri diferite.
 */
function route_driver_route_counts(): void
{
    require_staff();
    $from = valid_day(trim((string) ($_GET['from'] ?? '')) ?: today_local());
    $to   = valid_day(trim((string) ($_GET['to'] ?? '')) ?: $from);
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    // Panourile cer o săptămână. O fereastră nemărginită ar pune serverul să
    // numere toată baza la fiecare deschidere a meniului de zile.
    if ((strtotime($to) - strtotime($from)) > 31 * 86400) {
        fail('Intervalul cerut e prea lung: cel mult 31 de zile.', 422, 'range_too_long');
    }

    [$cond, $params] = route_stop_filter();
    array_unshift($params, $from, $to);
    $rows = all("SELECT day, COUNT(*) AS n FROM orders WHERE day BETWEEN ? AND ? AND $cond GROUP BY day", $params);

    $counts = [];
    foreach ($rows as $r) {
        $counts[(string) $r['day']] = (int) $r['n'];
    }

    json_out(['from' => $from, 'to' => $to, 'counts' => $counts]);
}

/**
 * PUT /routes — ordinea opririlor pentru o zi.
 *
 * Ordinea se stabilește în Administrare și trebuie să ajungă la șofer, care
 * deschide ruta pe alt dispozitiv. Înainte era ținută în localStorage, deci
 * rămânea în browserul administratorului: pagina anunța „Șoferul o vede
 * imediat”, iar șoferul vedea cu totul altceva.
 *
 * Corpul cererii: { day: 'YYYY-MM-DD', ids: [12, 7, 30] }.
 * O listă goală șterge ordinea manuală și revine la ordinea intervalelor.
 */
function route_driver_reorder(): void
{
    // Ordinea opririlor o pune administratorul, de pe calculatorul din birou —
    // așa scrie și pe ecranul șoferului, care doar o citește. Singura pagină
    // care cheamă PUT /routes este „Administrare Comenzi”, închisă oricui nu e
    // administrator; serverul rămăsese însă deschis întregului personal, deci
    // ordinea se putea răsturna din contul comun al tabletelor, iar șoferul
    // pleca pe alt traseu decât cel stabilit, fără ca nimeni să știe de ce.
    require_role('admin');
    $b   = body();
    $day = valid_day(trim((string) ($b['day'] ?? '')));
    $ids = $b['ids'] ?? [];
    if (!is_array($ids)) {
        fail('Lista de opriri trebuie să fie un tablou de id-uri.', 422, 'bad_ids');
    }

    db()->beginTransaction();
    try {
        // Ștergem întâi ordinea zilei: o oprire scoasă din listă trebuie să
        // revină la locul ei firesc, nu să rămână cu poziția veche.
        q('UPDATE orders SET route_seq = NULL WHERE day = ?', [$day]);

        $pos = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            // Filtrul pe zi împiedică mutarea unei comenzi din altă zi.
            q('UPDATE orders SET route_seq = ? WHERE id = ? AND day = ?', [$pos, $id, $day]);
            $pos++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    audit('route_reorder', 'route', $day, $pos > 0 ? $pos . ' opriri' : 'ordine ștearsă');
    json_out(['ok' => true, 'day' => $day, 'stops' => $pos]);
}

/* ------------------------------------------------------------- ajutoare -- */
