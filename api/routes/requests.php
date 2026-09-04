<?php
/**
 * Cereri de comandă — treapta întâi a fluxului.
 *
 * Clientul trimite o cerere de pe site. Ea NU intră în laborator: rămâne în
 * așteptare până când un administrator o acceptă, moment în care devine
 * comandă cu număr, interval și zonă. Așa nu ajunge în producție ceva ce nu
 * încape în capacitatea zilei.
 */

declare(strict_types=1);

/* -------------------------------------------------------------- listare -- */

/** GET /requests?status=nou */
function route_requests_list(): void
{
    $u = require_auth();

    $where  = [];
    $params = [];

    // Clientul își vede doar propriile cereri; personalul le vede pe toate.
    if ($u['role'] === 'client') {
        $where[]  = 'r.customer_id = ?';
        $params[] = (int) $u['id'];
    }

    $status = trim((string) ($_GET['status'] ?? ''));
    if ($status !== '') {
        $list = array_values(array_intersect(explode(',', $status), ['nou', 'acceptata', 'respinsa']));
        if (!$list) {
            fail('Status necunoscut pentru cereri.', 422, 'bad_status');
        }
        $where[] = 'r.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
        $params  = array_merge($params, $list);
    }

    $sql = 'SELECT r.*, o.seq AS order_seq, o.ref AS order_ref
              FROM order_requests r
              LEFT JOIN orders o ON o.id = r.order_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY r.created_at DESC, r.id DESC LIMIT 500';

    json_out(['requests' => attach_request_items(all($sql, $params))]);
}

/* --------------------------------------------------------------- creare -- */

/**
 * POST /requests
 *
 * Accesibilă fără cont: site-ul public trebuie să poată primi cereri.
 * Dacă există sesiune de client, cererea se leagă automat de contul lui.
 */
function route_requests_create(): void
{
    $b    = body();
    $user = current_user();

    $items = $b['items'] ?? [];
    if (!is_array($items) || !$items) {
        fail('Cererea trebuie să conțină cel puțin un produs.', 422, 'empty_request');
    }
    if (count($items) > 200) {
        fail('Prea multe poziții în cerere.', 422, 'too_many_items');
    }

    // Clientul cu cont nu-și mai scrie datele la fiecare comandă: le are pe
    // cont. Numele vine de acolo și NU se poate suprascrie din formular —
    // altfel o cerere ar putea intra pe numele altui client.
    $isClient   = $user && $user['role'] === 'client';
    $customerId = $isClient ? (int) $user['id'] : null;

    // Personalul care comandă de pe site o face pentru altcineva, deci numele
    // din formular rămâne al clientului. Înainte, orice cerere trimisă de un
    // administrator se înregistra pe numele lui, oricare ar fi fost cel scris
    // în formular: clientul dispărea din cerere fără niciun semn.
    //
    // Lungimile sunt cele ale coloanelor. Fără verificarea asta, un câmp mai
    // lung decât coloana ajungea la MySQL și se întorcea „Data too long", adică
    // „Eroare internă" în fața clientului — cu cererea pierdută și fără să afle
    // nimeni de ea. Formularul de pe site nu are `maxlength` pe nimic, iar
    // mențiunile se lipesc din cinci câmpuri, deci se depășea ușor.
    $customerName = $isClient
        ? ($user['customer_name'] ?: $user['name'])
        : text_field($b['customerName'] ?? '', 'Numele', 160);
    if ($customerName === '' && $user) {
        $customerName = $user['customer_name'] ?: $user['name'];
    }

    // Ce lipsește din formular se ia de pe cont. Câmpul trimis are întâietate:
    // o livrare la altă adresă decât cea obișnuită rămâne posibilă.
    $phone   = text_field($b['phone'] ?? '', 'Telefonul', 32);
    $email   = text_field($b['email'] ?? '', 'E-mailul', 190);
    $address = text_field($b['address'] ?? '', 'Adresa', 255);
    if ($isClient) {
        if ($phone === '')   { $phone   = trim((string) ($user['phone'] ?? '')); }
        if ($email === '')   { $email   = trim((string) ($user['email'] ?? '')); }
        if ($address === '') { $address = trim((string) ($user['address'] ?? '')); }
    }

    if ($customerName === '') {
        fail('Numele este obligatoriu.', 422, 'missing_customer');
    }

    // Aceeași legătură ca la comenzi: o cerere scrisă de personal pentru un
    // client cu cont trebuie să ajungă în istoricul acelui client.
    // Numai personalul poate lega o comandă de contul unui client: el știe cine
    // e omul de la telefon. Pentru un vizitator anonim, numele din formular nu
    // e o dovadă a nimic — oricine putea scrie „Cafeneaua Veche" și comanda lui,
    // cu telefonul și mențiunile lui, apărea în contul acelui client.
    if ($customerId === null && $user) {
        $customerId = client_by_name($customerName);
    }
    if (!$user && $phone === '') {
        fail('Numărul de telefon este obligatoriu pentru cererile fără cont.', 422, 'missing_phone');
    }

    // Ziua se verifică ACUM, nu abia la acceptare. Câmpul de dată de pe site
    // n-avea nici `min`, nici `max`, iar aici se verifica doar forma: clientul
    // nimerea ziua de ieri în calendar, pagina îi răspundea „Mulțumim! Cererea
    // ta a intrat la noi în platformă", cererea chiar intra în panou — și abia
    // butonul „Acceptă" o refuza, cu `day_in_past`. Cererea rămânea blocată pe
    // „nou" pentru totdeauna, iar cineva trebuia să sune clientul ca s-o ia de
    // la capăt. În sus nu exista nicio margine: „3026" în loc de „2026" trecea
    // prin tot lanțul și năștea o comandă activă pe care niciun ecran n-o vede.
    $day = trim((string) ($b['day'] ?? ''));
    $day = $day === '' ? null : valid_order_day($day, 'Ziua cerută');

    // Interval și zonă se validează față de listele din Setări, ca să nu
    // intre valori pe care panourile nu le pot afișa.
    $slot = trim((string) ($b['slot'] ?? ''));
    if ($slot !== '' && !in_array($slot, delivery_slots(), true)) {
        fail('Interval de livrare necunoscut: ' . $slot, 422, 'bad_slot');
    }
    $zone = trim((string) ($b['zone'] ?? ''));
    $zones = delivery_zones();
    if ($zone !== '' && $zones && !in_array($zone, $zones, true)) {
        fail('Zonă necunoscută: ' . $zone, 422, 'bad_zone');
    }

    // Varianta aleasă de client decide prețul. Fiecare poziție poate avea
    // varianta ei — coșul de pe site ține cheile separat, deci un client poate
    // cere și congelat, și copt în aceeași comandă. Varianta de aici e doar
    // implicitul pentru pozițiile care nu trimit una.
    $variant = require_variant($b['variant'] ?? null);
    [$priced, $total] = price_items($items, $variant);
    $variant = order_variant_of($priced);

    // Mențiunile se lipesc pe site din cinci câmpuri (persoană, segment, volum,
    // mesaj, „vreau catalogul"); coloana e TEXT, dar tot merită o limită, ca un
    // mesaj de 2 MB să nu ajungă în e-mailul de notificare.
    $notes = text_field($b['notes'] ?? '', 'Mențiunile', 2000);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q(
            'INSERT INTO order_requests
               (customer_id, customer_name, phone, email, day, slot, zone, address, notes, total, status, variant)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $customerId, $customerName, $phone ?: null, $email ?: null,
                $day, $slot ?: null, $zone ?: null, $address ?: null,
                $notes ?: null,
                round($total, 2), 'nou', $variant,
            ]
        );
        $id = (int) $pdo->lastInsertId();

        foreach ($priced as $p) {
            q(
                'INSERT INTO order_request_items (request_id, product_id, name, variant, qty, unit_price) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $p['id'], $p['name'], $p['variant'], $p['qty'], $p['unit']]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Adresa nouă rămâne pe cont: la comanda următoare e deja completată.
    // Se salvează după commit, ca o eroare aici să nu piardă cererea.
    if ($isClient && $address !== '' && $address !== trim((string) ($user['address'] ?? ''))) {
        q('UPDATE users SET address = ? WHERE id = ?', [$address, (int) $user['id']]);
    }

    $req = attach_request_items([one('SELECT * FROM order_requests WHERE id = ?', [$id])])[0];
    audit('request_create', 'request', (string) $id, 'Cerere de la ' . $customerName);

    // Răspunsul pleacă înaintea anunțurilor, nu după ele. Cererea e deja
    // scrisă în bază, iar anunțurile se duc pe rând la e-mail, la Meta și la
    // Telegram, unde un furnizor căzut ține conexiunea până la cincisprezece
    // secunde de fiecare dată. Trimise întâi, țineau vizitatorul de pe site cu
    // butonul „Se trimite…" apăsat o jumătate de minut pentru o cerere care
    // reușise, și țineau ocupat câte un proces PHP tot atâta.
    json_out_early(['request' => $req], 201);

    // De aici încolo nimic nu mai ajunge la client: răspunsul a plecat cu 201,
    // fiindcă asta s-a și întâmplat — cererea a fost salvată. Un anunț care nu
    // pleacă rămâne scris în tabelul `notifications` cu status „failed" și în
    // jurnalul de erori al serverului, de unde se poate afla ce s-a pierdut.
    try {
        notify_new_request($req);
    } catch (Throwable $e) {
        error_log('[gp] notificare eșuată pentru cererea ' . $id . ': ' . $e->getMessage());
    }
    // Fără oprirea asta, dispecerul din index.php ar chema fail(...) după
    // rută și ar lipi octeți în plus la un răspuns deja trimis.
    exit;
}

/* ------------------------------------------------------------ acceptare -- */

/**
 * Ziua de azi așa cum o înțeleg ecranele din laborator: la București.
 *
 * Restul aplicației scrie și citește totul în UTC (api/lib/db.php), dar zilele
 * de livrare sunt zile de calendar românești: panourile își construiesc
 * fereastra din ceasul tabletei (`gp.days()` în shared/gp-api.js). `gmdate()`
 * arată încă ziua de ieri până la 03:00 vara și până la 02:00 iarna — tocmai
 * orele în care laboratorul lucrează pentru livrarea de 06:30 — deci o
 * margine pusă pe el ar fi lăsat să treacă exact ziua pe care panoul n-o mai
 * arată.
 *
 * Fusul e scris aici, nu luat de la găzduire: patiseria, depozitul și zonele
 * de livrare sunt în București (vezi api/lib/settings.php), iar ora serverului
 * de cPanel poate fi oricare. DateTimeZone ține singur socoteala orei de vară.
 */
function requests_today_local(): string
{
    // Adevărul stă acum în api/lib/http.php (`today_local()`), ca ruta
    // cererilor, ruta comenzilor și `GET /lists` să nu poată răspunde lucruri
    // diferite despre ce înseamnă „azi". Numele rămâne, ca să nu se rupă
    // apelurile de mai jos.
    return today_local();
}

/**
 * POST /requests/{id}/accept
 *
 * Transformă cererea în comandă. Administratorul poate corecta ziua,
 * intervalul, zona și modul de onorare în acest pas — de aceea există treapta.
 */
function route_requests_accept(string $id): void
{
    // Doar administratorul, nu tot personalul: „Administrare Comenzi” e singura
    // pagină care acceptă cereri și se închide singură pentru orice alt rol.
    // Contul comun al tabletelor („panou”, rol angajat) se deschide cu un cod
    // știut de toată tura, iar cu el acceptarea trecea din consolă: cererea
    // devenea comandă pe altă zi decât cea cerută de client, iar în jurnal
    // autorul rămânea „Panou comun”, deci nu se putea afla cine a apăsat.
    $u  = require_role('admin');
    $b  = body();
    $id = (int) $id;

    $req = one('SELECT * FROM order_requests WHERE id = ?', [$id]);
    if (!$req) {
        fail('Cererea nu există.', 404, 'not_found');
    }
    if ($req['status'] !== 'nou') {
        fail('Cererea a fost deja ' . ($req['status'] === 'acceptata' ? 'acceptată' : 'respinsă') . '.', 409, 'already_handled');
    }

    $azi = requests_today_local();
    $day = trim((string) ($b['day'] ?? '')) ?: ($req['day'] ?? $azi);

    // O cerere lăsată în coadă peste weekend vine cu ziua cerută de client deja
    // trecută, iar formularul de acceptare o preselectează ca pe oricare alta.
    // `valid_day()` verifică doar forma, deci comanda se năștea pe acea zi și
    // primea 201 „Cerere acceptată" — dar niciun ecran de producție nu o vede:
    // Panoul Laborator și Intrare cer fereastra azi..azi+6, iar ruta șoferului
    // o singură zi. Marfa nu se făcea și nimeni nu avea de unde afla, în timp
    // ce Depozit îi ținea stocul rezervat la nesfârșit.
    //
    // Nu mutăm tăcut ziua pe azi: exact asta era defectul reparat înainte în
    // formular („ziua cerută de client, înlocuită în tăcere"). Refuzăm și
    // spunem de ce, ca la interval, zonă și mod de onorare — administratorul
    // stabilește el ziua nouă, la fel cum stabilește restul.
    //
    // Verificarea e aceeași cu cea de la trimitere (`valid_order_day`), inclusiv
    // marginea de sus: o cerere veche, primită înainte ca fereastra să existe,
    // trebuie oprită și ea aici, nu doar la intrare.
    $day = valid_order_day($day, 'Ziua cerută');

    $slot = trim((string) ($b['slot'] ?? '')) ?: (string) ($req['slot'] ?? '');
    if ($slot !== '' && !in_array($slot, delivery_slots(), true)) {
        fail('Interval de livrare necunoscut: ' . $slot, 422, 'bad_slot');
    }
    $zone  = trim((string) ($b['zone'] ?? '')) ?: (string) ($req['zone'] ?? '');
    $zones = delivery_zones();
    if ($zone !== '' && $zones && !in_array($zone, $zones, true)) {
        fail('Zonă necunoscută: ' . $zone, 422, 'bad_zone');
    }

    $fulfillment = trim((string) ($b['fulfillment'] ?? '')) ?: 'Livrare';
    if (!in_array($fulfillment, fulfillment_types(), true)) {
        fail('Mod de onorare necunoscut: ' . $fulfillment, 422, 'bad_fulfillment');
    }

    // Acceptarea copia cererea literă cu literă. Formularul de acceptare arată
    // însă și clientul, telefonul, adresa, prioritatea și pozițiile, iar
    // administratorul le corectează tocmai acolo: numele scris greșit de client,
    // telefonul incomplet, o cantitate lămurită la telefon. Tot ce corecta se
    // pierdea la apăsarea butonului, fără niciun semn.
    $customerName = array_key_exists('customerName', $b)
        ? text_field($b['customerName'], 'Numele clientului', 160, true)
        : (string) $req['customer_name'];
    $phone = array_key_exists('phone', $b)
        ? (text_field($b['phone'], 'Telefonul', 32) ?: null)
        : $req['phone'];
    $address = array_key_exists('address', $b)
        ? (text_field($b['address'], 'Adresa', 255) ?: null)
        : $req['address'];
    $urgent = !empty($b['urgent']);
    $timed  = !empty($b['timed']);

    // Numele din formular e text, nu identitate. Administratorul corectează la
    // acceptare „Brutăria X" în „Brutăria X S.R.L.", iar `client_by_name()` nu
    // mai găsește nimic după numele nou: comanda se năștea cu `customer_id`
    // gol, clientul n-o mai vedea în „Comanda mea" și primea „Nu ai acces la
    // această comandă" când o deschidea din legătura primită — pentru propria
    // lui comandă, cea pe care tocmai o ceruse de pe site.
    //
    // Deci căutarea după nume are voie să lege și să MUTE, niciodată să
    // dezlege. Dacă numele scris de administrator duce la un cont, comanda
    // pleacă la acel cont — așa rămâne posibilă și corectura „n-o pune pe
    // punctul de lucru, ci pe firma care are contul". Dacă nu duce nicăieri,
    // rămâne contul cu care a venit cererea: o literă în plus în numele firmei
    // nu are voie să scoată comanda din contul clientului.
    //
    // Dezlegarea de tot rămâne cerută pe față, nu ca efect al unei corecturi
    // de nume: PATCH /orders/{id} cu `customerId: null`.
    $customerId = $req['customer_id'];
    if ($customerName !== (string) $req['customer_name']) {
        $gasit = client_by_name($customerName);
        if ($gasit !== null) {
            $customerId = $gasit;
        }
    }

    $statuses = order_statuses();
    $first    = $statuses[0] ?? 'În așteptare';

    $items = all('SELECT * FROM order_request_items WHERE request_id = ? ORDER BY id', [$id]);
    if (!$items) {
        fail('Cererea nu are produse — nu poate deveni comandă.', 422, 'empty_request');
    }

    // Pozițiile corectate în panou înlocuiesc cererea; fără ele rămân cele
    // cerute de client, cu prețul lor de atunci. Prețul acela se onorează și
    // când vin poziții: „Administrare Comenzi" trimite `items` la FIECARE
    // acceptare, deci o scumpire pusă în catalog între trimiterea cererii și
    // apăsarea butonului „Acceptă" rescria tăcut ce văzuse clientul — 285 de
    // lei pe ecranul lui, 785 în comanda născută din ea. Doar cantitatea pe
    // care administratorul o adaugă acum ia prețul de azi.
    //
    // Tot de aici vine și îngăduința: produsele care erau pe cerere trec chiar
    // dacă între timp au fost scoase din catalog, altfel cererea rămânea pe
    // „nou" pentru totdeauna și clientul nu mai primea niciun răspuns.
    // Varianta comenzii poate fi și „mixt" — o valoare pe care `valid_variant()`
    // nu o acceptă, fiindcă nu se poate tarifa cu ea. De aceea sunt două:
    // `$variant` merge în comandă așa cum e, `$implicit` doar tarifează.
    $variant  = (string) ($req['variant'] ?? 'copt');
    $implicit = valid_variant($variant);
    $total    = $req['total'];
    $nota     = '';
    if (array_key_exists('items', $b)) {
        if (!is_array($b['items']) || !$b['items']) {
            fail('Comanda trebuie să conțină cel puțin un produs.', 422, 'empty_order');
        }
        if (count($b['items']) > 200) {
            fail('Prea multe poziții în comandă.', 422, 'too_many_items');
        }
        [$priced, $sum] = price_items(
            $b['items'],
            require_variant($b['variant'] ?? null, $implicit),
            honored_prices($items),
            array_filter(array_column($items, 'product_id'), static fn($x) => $x !== null)
        );
        $variant = order_variant_of($priced);
        $total   = round($sum, 2);
        $nota    = price_honored_note($priced);
        $items   = array_map(static fn($p) => [
            'product_id' => $p['id'], 'name' => $p['name'],
            'variant' => $p['variant'], 'qty' => $p['qty'], 'unit_price' => $p['unit'],
        ], $priced);
    }

    $pdo     = db();
    $orderId = null;

    // Verificarea stării de la începutul rutei s-a făcut în afara oricărei
    // tranzacții, iar între ea și inserarea de aici încap două tablete care
    // apasă „Adaugă comanda" pe aceeași cerere în aceeași secundă. UNIQUE(day,
    // seq) nu le desparte: indexul prinde doar coliziunea de număr, iar bucla
    // de mai jos i-ar da celei de-a doua următorul seq liber, deci ar reuși
    // amândouă. De aceea cererea se blochează aici, înăuntrul tranzacției, și
    // starea se citește din nou: a doua acceptare așteaptă până încheie prima
    // și pleacă cu 409. Altfel laboratorul pregătește aceeași marfă de două
    // ori, șoferul are două opriri la aceeași adresă, iar comanda în plus nu
    // se poate șterge din panou.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $pdo->beginTransaction();

            $sub   = one('SELECT status, order_id FROM order_requests WHERE id = ? FOR UPDATE', [$id]);
            $stare = (string) ($sub['status'] ?? '');
            if ($stare !== 'nou') {
                $pdo->rollBack();
                fail('Cererea a fost deja ' . ($stare === 'acceptata' ? 'acceptată' : 'respinsă') . '.', 409, 'already_handled');
            }

            // O cerere care are deja o comandă în spate nu mai naște una nouă,
            // oricare i-ar fi starea. Rândurile lăsate strâmbe de cursa dintre
            // „Acceptă" și „Respinge" — respinse, dar cu comandă — au putut fi
            // redeschise cât timp redeschiderea nu se uita la `order_id`: ele
            // stau acum în bază pe „nou", cu comanda plecată deja în laborator,
            // iar butonul „Acceptă" le-ar face marfa a doua oară și ar trimite
            // șoferul încă o dată la aceeași adresă.
            if ($sub['order_id'] !== null) {
                $pdo->rollBack();
                $refVeche = scalar('SELECT ref FROM orders WHERE id = ?', [(int) $sub['order_id']]);
                fail(
                    'Cererea are deja comanda ' . ($refVeche ?: '#' . (int) $sub['order_id'])
                      . '. Nu mai facem una nouă — modifică sau anulează comanda aceea.',
                    409,
                    'has_order'
                );
            }

            $seq = (int) scalar('SELECT COALESCE(MAX(seq), 0) + 1 FROM orders WHERE day = ?', [$day]);
            q(
                // `variant` merge mai departe din cerere: laboratorul trebuie să știe
                // dacă marfa pleacă congelată sau gata de servire, iar prețul
                // deja calculat corespunde acelei variante.
                'INSERT INTO orders
                   (seq, ref, customer_id, customer_name, phone, address, zone, day, slot,
                    fulfillment, channel, status, notes, total, request_id, created_by, variant,
                    urgent, timed)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $seq, '#' . (1000 + $seq), $customerId, $customerName,
                    $phone, $address, $zone ?: null, $day, $slot ?: null,
                    $fulfillment, 'site', $first,
                    trim((string) ($b['notes'] ?? '')) ?: $req['notes'],
                    $total, $id, (int) $u['id'], $variant,
                    $urgent ? 1 : 0, $timed ? 1 : 0,
                ]
            );
            $orderId = (int) $pdo->lastInsertId();

            foreach ($items as $it) {
                q(
                    'INSERT INTO order_items (order_id, product_id, name, variant, qty, unit_price) VALUES (?, ?, ?, ?, ?, ?)',
                    [$orderId, $it['product_id'], $it['name'], $it['variant'] ?? 'copt', $it['qty'], $it['unit_price']]
                );
            }
            // „Rezervare la acceptare" nu e o regulă în plus, ci același lucru
            // spus o dată: o cerere nu are rânduri în `order_items`, deci nu
            // promite nimic. Promisiunea se naște exact când se naște poziția —
            // adică aici, la acceptare. Acceptarea are INSERT propriu, nu trece
            // prin `route_orders_create`, deci apelul trebuie scris și aici.
            stock_reserve_order($orderId);

            // A doua plasă, pentru cazul în care cineva ar ocoli blocarea de mai
            // sus: dacă între timp cererea nu mai e „nou", aici nu se atinge
            // niciun rând, iar comanda inserată adineaori dispare cu tranzacția.
            $marcata = q(
                "UPDATE order_requests SET status = 'acceptata', order_id = ?, handled_by = ?, handled_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'nou'",
                [$orderId, (int) $u['id'], $id]
            )->rowCount();
            if ($marcata === 0) {
                $pdo->rollBack();
                fail('Cererea a fost deja acceptată.', 409, 'already_handled');
            }
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
    // Prețul văzut de client s-a onorat, dar diferența față de catalogul de azi
    // se scrie în jurnal: altfel patiseria livrează sub prețul curent fără ca
    // nimeni să bage de seamă, iar administratorul află abia la socoteala
    // lunii, când nu mai are ce corecta. Pagina Jurnal arată deja
    // `action · detail`, deci nu e nimic de schimbat în panou.
    audit(
        'request_accept',
        'request',
        (string) $id,
        'Cerere acceptată → comanda ' . $order['ref']
            . ($nota !== '' ? ' · preț onorat din cerere: ' . $nota : '')
    );

    json_out(['order' => $order], 201);
}

/** POST /requests/{id}/reject */
function route_requests_reject(string $id): void
{
    // Doar administratorul, ca la acceptare. O respingere trimisă din contul
    // comun al tabletelor trecea cererea definitiv pe „respinsa”, iar de acolo
    // acceptarea nu mai e posibilă (409 „already_handled”): comanda clientului
    // se pierdea, iar jurnalul îl trecea pe autor tot ca „Panou comun”.
    $u  = require_role('admin');
    $b  = body();
    $id = (int) $id;

    $req = one('SELECT status, customer_name FROM order_requests WHERE id = ?', [$id]);
    if (!$req) {
        fail('Cererea nu există.', 404, 'not_found');
    }
    if ($req['status'] !== 'nou') {
        fail('Cererea a fost deja procesată.', 409, 'already_handled');
    }

    // Verificarea de mai sus s-a făcut în afara oricărei tranzacții, iar între
    // ea și scrierea de aici încap „Acceptă" și „Respinge" apăsate în aceeași
    // clipă, de pe două tablete. Respingerea scria fără nicio condiție pe
    // stare, deci trecea și peste „acceptata": cererea arăta „respinsă" în
    // panou, dar comanda era deja născută și plecase în laborator — marfa se
    // făcea, iar șoferul pleca la un client căruia tocmai i se spusese că nu se
    // poate. De acolo redeschiderea vedea o cerere „respinsa", o readucea la
    // „nou", și din aceleași date se năștea a doua comandă.
    //
    // Rândul se blochează deci aici, ca la acceptare, iar starea se citește din
    // nou sub blocare: cine ajunge al doilea pleacă cu 409, nu suprascrie.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stare = (string) (one('SELECT status FROM order_requests WHERE id = ? FOR UPDATE', [$id])['status'] ?? '');
        if ($stare === '') {
            // Rândul a dispărut între citirea de mai sus și zăvorul de aici.
            // Nu există azi rută care să-l șteargă, dar dacă apare vreodată,
            // serverul trebuie să spună adevărul, nu „a fost deja respinsă".
            $pdo->rollBack();
            fail('Cererea nu există.', 404, 'not_found');
        }
        if ($stare === 'acceptata') {
            $pdo->rollBack();
            fail('Cererea tocmai a fost acceptată și a devenit comandă. Dacă trebuie oprită, anulează comanda din panou.', 409, 'already_handled');
        }
        if ($stare !== 'nou') {
            $pdo->rollBack();
            fail('Cererea a fost deja respinsă.', 409, 'already_handled');
        }

        // A doua plasă, ca la acceptare: dacă starea s-a schimbat totuși, aici
        // nu se atinge niciun rând, deci respingerea nu are ce suprascrie.
        $marcata = q(
            "UPDATE order_requests SET status = 'respinsa', reject_reason = ?, handled_by = ?, handled_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'nou'",
            [trim((string) ($b['reason'] ?? '')) ?: null, (int) $u['id'], $id]
        )->rowCount();
        if ($marcata === 0) {
            $pdo->rollBack();
            fail('Cererea a fost deja procesată între timp. Reîncarcă lista de cereri.', 409, 'already_handled');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('request_reject', 'request', (string) $id, 'Cerere respinsă — ' . $req['customer_name']);
    json_out(['ok' => true]);
}

/**
 * POST /requests/{id}/reopen
 *
 * Anulează o respingere. Se poate întoarce doar dintr-un refuz: o cerere
 * acceptată a devenit deja comandă, iar readucerea ei la „nou" ar permite o a
 * doua comandă din aceleași date. Motivul refuzului se șterge, ca să nu rămână
 * lipit de o cerere care e din nou în așteptare.
 */
function route_requests_reopen(string $id): void
{
    // Ca la acceptare și respingere: redeschiderea anulează hotărârea
    // administratorului, deci nu are ce căuta în contul comun al tabletelor.
    require_role('admin');
    $id = (int) $id;

    // Referința comenzii vine odată cu cererea: dacă redeschiderea trebuie
    // refuzată din cauza ei, administratorul află pe loc care comandă îl
    // oprește, nu doar că „nu se poate".
    $req = one(
        'SELECT r.status, r.customer_name, r.order_id, o.ref AS order_ref
           FROM order_requests r
           LEFT JOIN orders o ON o.id = r.order_id
          WHERE r.id = ?',
        [$id]
    );
    if (!$req) {
        fail('Cererea nu există.', 404, 'not_found');
    }
    if ($req['status'] === 'nou') {
        fail('Cererea este deja în așteptare.', 409, 'already_open');
    }
    if ($req['status'] !== 'respinsa') {
        fail('Cererea a fost deja acceptată și a devenit comandă. Modifică sau anulează comanda.', 409, 'already_accepted');
    }
    // Plasa pentru cererile rămase strâmbe din cursa de dinainte: starea spune
    // „respinsa", dar `order_id` arată o comandă care chiar există și chiar a
    // plecat în laborator. Readusă la „nou", o a doua acceptare ar pune
    // laboratorul să facă încă o dată aceeași marfă și șoferul să meargă a doua
    // oară la aceeași adresă. Cererea rămâne respinsă; ce e de hotărât se
    // hotărăște pe comandă.
    if ($req['order_id'] !== null) {
        fail(
            'Cererea e trecută ca respinsă, dar are deja comanda '
              . ($req['order_ref'] ?: '#' . (int) $req['order_id'])
              . '. Nu o readucem în așteptare — modifică sau anulează comanda.',
            409,
            'has_order'
        );
    }

    // Condiția pe stare ține și când doi administratori apasă „Redeschide" în
    // aceeași clipă: fără ea, al doilea ar readuce la „nou" o cerere pe care
    // primul o redeschisese deja și pe care cineva apucase s-o accepte — adică
    // tot a doua comandă din aceleași date, pe altă cale.
    $redeschisa = q(
        "UPDATE order_requests SET status = 'nou', reject_reason = NULL, handled_by = NULL, handled_at = NULL
          WHERE id = ? AND status = 'respinsa' AND order_id IS NULL",
        [$id]
    )->rowCount();
    if ($redeschisa === 0) {
        fail('Cererea nu mai este respinsă — altcineva a apucat s-o schimbe. Reîncarcă lista de cereri.', 409, 'already_handled');
    }

    audit('request_reopen', 'request', (string) $id, 'Refuz anulat — ' . $req['customer_name']);
    json_out(['ok' => true]);
}

/* ------------------------------------------------------------- ajutoare -- */

/** Atașează pozițiile la cereri, printr-o singură interogare. */
function attach_request_items(array $rows): array
{
    $rows = array_values(array_filter($rows));
    if (!$rows) {
        return [];
    }

    $ids   = array_map(static fn($r) => (int) $r['id'], $rows);
    $ph    = implode(',', array_fill(0, count($ids), '?'));
    $items = all("SELECT * FROM order_request_items WHERE request_id IN ($ph) ORDER BY id", $ids);

    $byReq = [];
    foreach ($items as $it) {
        $byReq[(int) $it['request_id']][] = [
            'productId' => $it['product_id'] === null ? null : (int) $it['product_id'],
            'name'      => $it['name'],
            'qty'       => (float) $it['qty'],
            'unitPrice' => (float) $it['unit_price'],
            'variant'   => $it['variant'] ?? 'copt',
        ];
    }

    return array_map(static function (array $r) use ($byReq) {
        return [
            'id'           => (int) $r['id'],
            'customerId'   => $r['customer_id'] === null ? null : (int) $r['customer_id'],
            'customerName' => $r['customer_name'],
            'phone'        => $r['phone'],
            'email'        => $r['email'],
            'day'          => $r['day'],
            'slot'         => $r['slot'],
            'zone'         => $r['zone'],
            'address'      => $r['address'],
            'notes'        => $r['notes'],
            'total'        => (float) $r['total'],
            'variant'      => $r['variant'] ?? 'copt',
            'status'       => $r['status'],
            'orderId'      => $r['order_id'] === null ? null : (int) $r['order_id'],
            'orderRef'     => $r['order_ref'] ?? null,
            'rejectReason' => $r['reject_reason'],
            'createdAt'    => $r['created_at'],
            'handledAt'    => $r['handled_at'],
            'items'        => $byReq[(int) $r['id']] ?? [],
        ];
    }, $rows);
}
