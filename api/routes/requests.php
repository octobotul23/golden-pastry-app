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

/*
 * Aici bătea ceasul amânărilor: `requests_snooze_column()` întreba dacă baza a
 * primit migrarea 013, iar `requests_claim_snoozed()` scotea din amânare
 * cererile cărora le venise ora și trimitea câte un ghiont pentru fiecare.
 * Ceasul nu era cron — programul n-are cron, n-are coadă și n-are proces de
 * fundal —, ci chiar ruta asta, pe care panoul o cere din patru în patru
 * secunde cât stă deschis.
 *
 * A plecat tot, odată cu butonul „🕒 Mai târziu" de sub anunțul din Telegram,
 * care era singurul lucru din program care punea o cerere deoparte. Patronul a
 * hotărât să rămână numai „Acceptă" și „Respinge": ce se acceptă intră pe loc
 * în prima etapă și se vede pe ecranul din laborator, ce nu, se respinge — și
 * nu mai e nimic de amânat.
 *
 * Coloana `order_requests.snoozed_until` a rămas în bază. O migrare adaugă, nu
 * dărâmă, iar sql/013 nu se rescrie după ce s-a aplicat pe serverele oamenilor.
 * Singurul loc care o mai atinge e `requests_mark_decided()`, la capătul
 * fișierului, și scrie acolo de ce.
 */

/**
 * GET /requests?status=nou
 *
 * Întoarce cel mult 500 de rânduri, dar niciodată tăind o cerere NEHOTĂRÂTĂ:
 * ele ies primele din `ORDER BY`, oricare ar fi filtrul cerut. Și întoarce,
 * lângă listă, numărătoarea pe stări din toată ograda cui întreabă — vezi
 * tăietura și `counts`, mai jos, pentru ce se pierdea fără ele.
 */
function route_requests_list(): void
{
    $u = require_auth();

    $where  = [];
    $params = [];

    // Clientul își vede doar propriile cereri; personalul le vede pe toate,
    // afară de agentul de vânzări, care vede numai cererile oamenilor aduși de
    // el — aceeași margine ca la comenzi, fiindcă panoul de Comenzi cere și
    // lista asta, iar o cerere are tot nume, telefon și adresă.
    if ($u['role'] === 'client') {
        $where[]  = 'r.customer_id = ?';
        $params[] = (int) $u['id'];
    } elseif ($u['role'] === 'agent_vanzari') {
        $sc      = referral_agent_scope((int) $u['id'], 'r.customer_id', 'r.referral_code');
        $where[] = $sc['sql'];
        $params  = array_merge($params, $sc['params']);
    } elseif (!orders_readable($u)) {
        // Și aceeași cădere întoarsă ca la comenzi, din același motiv: un rol
        // care nu e nici client, nici agent, nici personal — azi omul de
        // laborator — primea altfel lista întreagă. Regula stă scrisă o
        // singură dată, în `orders_readable()` (api/lib/auth.php).
        $where[] = '1 = 0';
    }

    // Marginile ogrăzii, puse deoparte înainte de filtrul de stare. Din ele
    // iese numărătoarea de la capătul funcției: un agent trebuie să vadă
    // numărul cererilor LUI, nu al casei, iar un client pe al lui.
    $ograda       = $where;
    $ogradaParams = $params;

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
        // CELE NEHOTĂRÂTE IES PRIMELE, oricare ar fi filtrul, și de-aia nu se
        // mai pot pierde sub tăietura de 500.
        //
        // Tăietura a fost dintotdeauna aici, și dintotdeauna pe vechime: cele
        // mai noi 500 de rânduri, de orice stare. Asta merge cât timp panoul e
        // gol, și se strică singură cu timpul — cererile acceptate și refuzate
        // se adună în aceleași 500 de locuri, iar o casă care primește zece
        // cereri pe zi umple tăietura în două luni. De-atunci, o cerere rămasă
        // fără răspuns din urmă nu mai ajungea la panou deloc: nici în listă,
        // nici în numărul de pe filă. Nimeni n-o căuta, fiindcă nimeni nu știa
        // de ea. Același lucru îl făcea, în două minute, un potop de cereri.
        //
        // Ordinea din listă NU se schimbă pentru om: panoul și pagina
        // clientului își aleg oricum rândurile după stare și le desenează în
        // ordinea primită, iar cele nehotărâte sunt oricum cele mai noi la o
        // casă care răspunde. Ce se schimbă e numai CINE apucă cele 500 de
        // locuri când nu mai sunt de ajuns.
        //
        // Chezășia stă la cel care taie, nu la cel care cere: o pagină poate
        // cere `?status=nou` (și panoul chiar cere), dar sub „Toate" ar cere
        // iar toate stările, și s-ar tăia iar. Aici se ține pentru toți.
        . ' ORDER BY (r.status = \'nou\') DESC, r.created_at DESC, r.id DESC LIMIT 500';

    // NUMĂRĂTOAREA PE STĂRI, din aceeași ogradă ca lista, dar fără filtrul ei.
    //
    // De ce nu se mai numără în browser: de când panoul cere anume starea de
    // care are nevoie, lista adusă nu mai cuprinde toate stările, deci un număr
    // socotit din ea ar fi scris 0 la „Acceptate" cât timp omul se uită la
    // „Noi". Iar cifrele astea au un rost scris pe față în panou — „Fiecare
    // număr vine din CHIAR lista pe care butonul o deschide" —, adică sunt
    // tocmai ce n-are voie să mintă.
    //
    // Pe deasupra, numărul e de-acum ADEVĂRAT, nu tăiat la 500 ca înainte: o
    // casă cu o mie de cereri în istoric scria „500 în total" și nimeni n-avea
    // de unde ști că nu-s toate.
    //
    // O interogare în plus pe aceeași cerere, nu o cerere în plus: panoul bate
    // din patru în patru secunde, iar a doua chemare ar fi costat cât ține ziua.
    // `GROUP BY` pe o coloană de ENUM, pe tabelul cererilor, e o socoteală mică.
    $counts = ['nou' => 0, 'acceptata' => 0, 'respinsa' => 0];
    $randuri = all(
        'SELECT r.status, COUNT(*) AS n FROM order_requests r'
          . ($ograda ? ' WHERE ' . implode(' AND ', $ograda) : '')
          . ' GROUP BY r.status',
        $ogradaParams
    );
    foreach ($randuri as $rand) {
        $cheie = (string) $rand['status'];
        if (array_key_exists($cheie, $counts)) {
            $counts[$cheie] = (int) $rand['n'];
        }
    }

    $raspuns = [
        'requests' => attach_request_items(all($sql, $params)),
        'counts'   => $counts,
    ];

    // Răspunsul iese pe ușa obișnuită, fiindcă ruta asta nu mai trimite nimic.
    // Cât a ținut ceasul amânărilor, rotirea care nimerea o cerere coaptă
    // trecea prin `json_out_early()` — buffere golite, `Content-Length` scris
    // de mână — ca panoul să nu aștepte cele cincisprezece secunde ale unui
    // canal. Acum nu mai are de ce: nimeni nu mai ghiontește pe aici.
    json_out($raspuns);
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

    // Pragul pe conexiune. Se verifică înainte de orice citire sau scriere.
    //
    // Cine intră în numărătoare e scris o singură dată, în
    // `request_counts_against_ip()`: vizitatorul fără cont ȘI contul făcut
    // singur pe site pe care casa nu l-a recunoscut încă. Un cont se face în
    // zece secunde, deci „are cont" nu mai e o dovadă că e cineva.
    if (request_counts_against_ip($user)
        && request_rate_limited(client_ip(), (int) config('max_request_attempts', 20))) {
        fail(
            'Prea multe cereri trimise de pe această conexiune. Încearcă din nou peste o oră '
            . 'sau sună-ne și luăm comanda la telefon.',
            429,
            'rate_limited'
        );
    }

    // COȘUL GOL NU MAI E UN REFUZ: ACELAȘI FORMULAR, DOUĂ ÎNȚELESURI.
    //
    // Hotărârea patronului din 14 septembrie 2026. Până azi, orice cerere
    // fără produse se refuza aici cu `empty_request` — deși butoanele care
    // duc la formular promit „Cere catalogul PDF” și „Scrie-ne”, adică exact
    // lucrurile care n-au coș. Un om care voia doar să întrebe ceva primea
    // „Cererea trebuie să conțină cel puțin un produs” și pleca.
    //
    // De azi coșul hotărăște ce fel de hârtie pleacă: fără produse e o
    // CERERE DE OFERTĂ sau o întrebare, cu produse e cererea de comandă de
    // totdeauna. Pe ecranul vizitatorului scrie care din două (`sendLabel`
    // și nota de sub buton, Site Public.dc.html), iar în panou cererea fără
    // poziții se arată de mult ca atare: „Fără produse selectate — doar
    // cerere de ofertă” (Administrare Comenzi.dc.html).
    //
    // REGULA „O COMANDĂ ARE PRODUSE” NU S-A PIERDUT — s-a mutat acolo unde e
    // adevărată. O cerere fără poziții nu poate deveni comandă:
    // `route_requests_accept()` o refuză mai jos, tot cu `empty_request`.
    // Deosebirea e că refuzul cade în fața administratorului, care poate
    // suna clientul, nu în fața clientului, care n-avea ce face cu el.
    //
    // Ce NU s-a slăbit: o valoare care nu e listă rămâne un refuz — altfel
    // `price_items()` ar primi un șir și ar cădea cu o eroare internă —, iar
    // plafonul de 200 de poziții e neatins.
    $items = $b['items'] ?? [];
    if (!is_array($items)) {
        fail('Lista de produse nu are forma așteptată.', 422, 'bad_items');
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
    // CLIENTUL ALES ANUME, din căutarea de la `GET /clients`. Are întâietate
    // față de potrivirea după nume de mai jos: omul din casă a ALES un cont,
    // cu ochii pe telefonul și adresa lui — n-a nimerit unul scriind un nume.
    //
    // Alegerea trece prin ACEEAȘI ogradă ca lista care i-a arătat-o
    // (`client_in_scope()`, api/lib/orders.php). Fără poarta asta, un agent ar
    // putea lega o cerere de un client pe care nu l-a adus el, trimițând prin
    // curl un număr pe care lista nu i l-ar fi arătat niciodată — iar cererea
    // ar intra în istoricul acelui client fără ca nimeni să afle.
    $alesAnume = false;
    $cerut     = $b['customerId'] ?? null;
    if ($customerId === null && $user && $cerut !== null && (string) $cerut !== '') {
        $ales = (int) $cerut;
        if (!client_in_scope($user, $ales)) {
            fail('Clientul ales nu e printre clienții tăi.', 403, 'client_not_in_scope');
        }
        $customerId = $ales;
        $alesAnume  = true;

        // Numele vine de pe CONTUL ales, nu din formular — aceeași grijă ca la
        // clientul care comandă singur, cu câteva rânduri mai sus. Altfel o
        // cerere ar putea sta pe contul unui client purtând scris numele
        // altuia, iar panoul ar arăta două adevăruri despre aceeași cerere:
        // titlul ar spune una, istoricul clientului alta.
        $contAles = one('SELECT customer_name, name FROM users WHERE id = ?', [$ales]);
        if ($contAles) {
            $customerName = trim((string) ($contAles['customer_name'] ?? '')) !== ''
                ? (string) $contAles['customer_name']
                : (string) $contAles['name'];
        }
    }

    // A DOUA POARTĂ, pusă pe 14 septembrie 2026 după o măsurătoare vie, nu
    // după o bănuială. Poarta de deasupra păzea NUMĂRUL, dar rândul ăsta lega
    // cererea după NUME, fără să întrebe nimic — iar numele e scris de mână.
    // Măsurat cu contul agentului „mihai" (id 1811): pe „Cafeneaua Veche"
    // (id 1809, adusă de nimeni) cererea cu `customerId: 1809` primea 403
    // `client_not_in_scope`, iar aceeași cerere cu numele scris în clar și
    // numărul lăsat gol intra cu 200 și se lega de ACELAȘI cont — deși
    // `GET /clients?q=cafe` îi răspundea agentului cu lista goală. Poarta
    // dintâi se ocolea scriind, în loc să alegi.
    //
    // Toată regula — și de ce tace în loc să refuze, și de ce managerul leagă
    // mai departe — stă scrisă la `client_by_name_in_scope()`, api/lib/orders.php.
    if ($customerId === null && $user) {
        $customerId = client_by_name_in_scope($user, $customerName);
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
    $zone = zone_field($b['zone'] ?? '');
    // Zona lipsă se ia de pe cont, exact ca adresa și telefonul de mai sus.
    // Fără rândul ăsta, tot ce aduce migrarea 016 s-ar opri la pagina de
    // înscriere: zona ar fi scrisă pe cont și cerută din nou la fiecare comandă.
    //
    // `user_zone()`, nu `$user['zone']`: interogarea din `current_user()` are
    // listă fixă de coloane și nu cuprinde zona — vezi motivul acolo.
    if ($zone === '' && $isClient) {
        $zone = (string) (user_zone((int) $user['id']) ?? '');
    }

    // Varianta aleasă de client decide prețul. Fiecare poziție poate avea
    // varianta ei — coșul de pe site ține cheile separat, deci un client poate
    // cere și congelat, și copt în aceeași comandă. Varianta de aici e doar
    // implicitul pentru pozițiile care nu trimit una.
    $variant = require_variant($b['variant'] ?? null);

    // REDUCEREA, ÎNAINTE DE TARIFARE.
    //
    // Pachetul de test scria −20% pe ecran, iar cererea se înregistra la preț
    // de catalog: reducerea rămânea o notă în mențiuni, pe care o ținea minte
    // un om. Aici ea intră în preț, prin ușa care exista deja — prețurile
    // promise clientului (`$onorate` din `price_items()`). Vezi api/lib/promo.php.
    //
    // Ordinea celor patru porți nu e întâmplătoare:
    //   1. baza știe de reducere? — altfel am înregistra la catalog o cerere
    //      pe care clientul a văzut-o redusă, adică chiar defectul reparat;
    //   2. codul e unul cunoscut? — un cod inventat se refuză, nu se ignoră;
    //   3. coșul E pachetul? — fără asta, oricine trimitea `promo` lua −20%
    //      pe orice pusese în coș;
    //   4. omul l-a mai luat o dată? — regula scrisă pe site.
    $promo      = promo_normalize($b['promo'] ?? '');
    $promoPromis = [];
    if ($promo !== '') {
        promo_cere_baza();
        $def = promo_def($promo);
        if ($def === null) {
            fail('Reducere necunoscută: ' . $promo . '.', 422, 'bad_promo');
        }
        $vPromo = promo_variant_din_cos($items, $def, $variant);
        if ($vPromo === null) {
            fail(
                'Reducerea „' . $def['label'] . '” se dă numai pe pachetul întreg: '
                  . 'toate produsele din gamă, câte ' . (int) $def['each']
                  . ' bucăți din fiecare, în aceeași variantă.',
                422,
                'promo_incomplete'
            );
        }
        if ($def['once']) {
            $vechi = promo_used_by($promo, $customerId, $phone, $email);
            if ($vechi !== null) {
                fail(
                    'Reducerea „' . $def['label'] . '” se dă o singură dată per client, '
                      . 'iar la noi apare deja pe ' . $vechi['ref'] . ' (' . $vechi['day'] . '). '
                      . 'Scoate pachetul din coș și trimite cererea fără el.',
                    409,
                    'promo_used'
                );
            }
        }
        $promoPromis = promo_slices($def, $vPromo);
    }

    [$priced, $total] = price_items($items, $variant, $promoPromis);
    // Fără poziții nu există „varianta cererii”, iar `order_variant_of([])`
    // răspunde „mixt”: zero valori deosebite nu fac una singură, deci cade pe
    // ramura de la urmă. „Mixt” scris pe o întrebare fără produse ar fi o
    // minciună mică pe care panoul o arată ca atare. Rămâne varianta cerută,
    // adică implicitul „copt”.
    $variant = $priced ? order_variant_of($priced) : $variant;

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

        // Codul din coș se scrie cu un UPDATE, nu ca o coloană în INSERT-ul de
        // mai sus — dinadins. Coloana apare abia cu migrarea 008, iar fișierele
        // urcă pe server înaintea importului din phpMyAdmin: numită în INSERT,
        // orice cerere de pe site ar cădea cu „Unknown column" în tot intervalul
        // acela. Aici, fără 008, pur și simplu nu se execută nimic.
        //
        // Rămâne în aceeași tranzacție: o cerere fără codul ei ar fi o
        // recomandare pierdută tăcut, iar clientul a apucat să-l scrie.
        //
        // CÂND CLIENTUL A FOST ALES ANUME, codul din formular NU se scrie. E
        // hotărârea patronului din 14 septembrie 2026: cererea scrisă pentru un
        // cont ales merge „ca și cum ar fi comandat singur", deci comisionul e
        // al celui care l-a adus PE CLIENT, nu al celui care a scris cererea.
        // Lăsat să treacă, codul agentului ar face una din două rele: pe un
        // client fără recomandant l-ar lega de agent PENTRU TOTDEAUNA, iar pe
        // unul adus de altcineva ar naște un „in_asteptare" — un conflict pe
        // care patronul ar fi trebuit să-l descâlcească cu mâna, rând cu rând.
        // Fără cod, socoteala cade pe ramura a doua din
        // `referral_commission_sync()` și citește `users.referred_by`, adică
        // exact adevărul de pe contul clientului.
        // A DOUA PORȚIE A HOTĂRÂRII DIN 14 SEPTEMBRIE, SEARA. Steagul de mai
        // sus însemna „omul a ales un cont cu degetul". Acum înseamnă „cererea
        // s-a legat de un cont", oricum s-ar fi legat — fiindcă socoteala de
        // mai jos nu întreabă CUM s-a legat, ci citește `users.referred_by` de
        // pe contul acela. Porția asta acoperă și administratorul, a cărui
        // ogradă e largă și care deci trece de poarta dintâi fără să se
        // oprească: pe cererea scrisă de el pentru contul unui client, codul
        // din formular tot nu are ce căuta.
        //
        // CLIENTUL CARE COMANDĂ SINGUR RĂMÂNE PE DINAFARĂ, dinadins. Contul lui
        // e legat de la rândul 124, nu de vreo alegere; iar caseta lui e goală
        // și deschisă exact când contul spune „nu l-a adus nimeni"
        // (`codRecomandare()`, Site Public.dc.html). Codul scris atunci e chiar
        // mărturia lui despre cine l-a trimis — singura care există — și n-are
        // de ce să cadă odată cu cele scrise de altcineva pentru el.
        $alesAnume = $alesAnume || (!$isClient && $customerId !== null);

        if (referral_money_supported() && !$alesAnume) {
            $cod = referral_normalize_code((string) ($b['referralCode'] ?? ''));
            if ($cod !== '') {
                q('UPDATE order_requests SET referral_code = ? WHERE id = ?', [$cod, $id]);
            }
        }

        // Reducerea se scrie tot cu un UPDATE, din același motiv ca și codul de
        // recomandare: coloana vine abia cu migrarea 011, iar numită în INSERT
        // ar cădea cu „Unknown column" pe o bază nemigrată. Aici, fără 011,
        // `$promo` e gol oricum — `promo_cere_baza()` a oprit cererea mai sus.
        if ($promo !== '') {
            q('UPDATE order_requests SET promo = ? WHERE id = ?', [$promo, $id]);
        }

        // Prețul tăiat merge alături de cel plătit. Nu se poate recalcula mai
        // târziu din catalog: catalogul se schimbă, iar peste trei luni comanda
        // veche ar arăta o reducere de altă mărime — sau niciuna. Interogarea se
        // alege o singură dată, în afara buclei, ca o bază nemigrată să nu cadă
        // pe fiecare poziție.
        $cuLista = promo_supported();
        $sqlPozitie = $cuLista
            ? 'INSERT INTO order_request_items (request_id, product_id, name, variant, qty, unit_price, list_price) VALUES (?, ?, ?, ?, ?, ?, ?)'
            : 'INSERT INTO order_request_items (request_id, product_id, name, variant, qty, unit_price) VALUES (?, ?, ?, ?, ?, ?)';
        foreach ($priced as $p) {
            $val = [$id, $p['id'], $p['name'], $p['variant'], $p['qty'], $p['unit']];
            if ($cuLista) {
                $val[] = $p['catalog'] ?? null;
            }
            q($sqlPozitie, $val);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Se consumă din plafon abia după ce cererea e scrisă: una refuzată mai sus
    // n-a costat pe nimeni nimic.
    //
    // ACEEAȘI ÎNTREBARE CA LA POARTĂ, pusă cu aceeași funcție. Despărțite, ar fi
    // ieșit un prag care se verifică pe cineva și nu se scade de la el: plafonul
    // n-ar fi fost atins niciodată, iar poarta ar fi arătat pusă fără să fie.
    if (request_counts_against_ip($user)) {
        record_attempt(client_ip(), GP_REQUEST_KEY, false);
    }

    // Adresa nouă rămâne pe cont: la comanda următoare e deja completată.
    // Se salvează după commit, ca o eroare aici să nu piardă cererea — și
    // prinsă aici, ca o eroare aici (o coloană rămasă îngustă pe o bază
    // nemigrată) să nu întoarcă „Eroare internă." pentru o cerere care există
    // deja; omul ar retrimite-o și ar ieși dublă.
    if ($isClient && $address !== '' && $address !== trim((string) ($user['address'] ?? ''))) {
        try {
            q('UPDATE users SET address = ? WHERE id = ?', [$address, (int) $user['id']]);
        } catch (Throwable $e) {
            error_log('[gp] adresa clientului nu s-a putut salva pe cont: ' . $e->getMessage());
        }
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
    $u  = require_admin();
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

    // Implicitul se ia din Setări, nu din textul scris aici. V.
    // `default_fulfillment()`, api/lib/settings.php — și motivul, mai jos.
    $fulfillment = trim((string) ($b['fulfillment'] ?? '')) ?: default_fulfillment();
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
            // Prețul tăiat urmează prețul plătit: amândouă s-au socotit acum,
            // din catalogul de acum. Lăsat cel din cerere, ar fi arătat o
            // reducere pe care poziția retarifată n-o mai are.
            'list_price' => $p['catalog'] ?? null,
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
                    // Mențiunile trimise de administrator, chiar și goale:
                    // el a văzut nota clientului în formular și a ales să o
                    // șteargă. Cu „?: $req['notes']", golirea era ignorată
                    // și nota revenea pe comandă. Fără câmp deloc (un client
                    // vechi), rămâne nota cererii. Lungimea, ca la creare.
                    array_key_exists('notes', $b)
                        ? (text_field($b['notes'], 'Mențiunile', 2000) ?: null)
                        : $req['notes'],
                    $total, $id, (int) $u['id'], $variant,
                    $urgent ? 1 : 0, $timed ? 1 : 0,
                ]
            );
            $orderId = (int) $pdo->lastInsertId();

            // Prețul tăiat trece din cerere pe comandă. Nu se recalculează din
            // catalogul de acum: cifra pe care o poartă poziția e cea de lângă
            // care s-a scris prețul plătit, iar între trimitere și acceptare pot
            // trece zile în care catalogul s-a mișcat. Rescrisă din catalogul de
            // azi, ea ar arăta altă reducere decât cea dată.
            //
            // Când administratorul trimite `items` la acceptare, pozițiile s-au
            // retarifat mai sus și `$it['list_price']` vine deja din
            // `$priced[...]['catalog']` — catalogul de acum, potrivit cu prețul
            // de acum.
            $cuLista = promo_supported();
            $sqlPozitie = $cuLista
                ? 'INSERT INTO order_items (order_id, product_id, name, variant, qty, unit_price, list_price) VALUES (?, ?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO order_items (order_id, product_id, name, variant, qty, unit_price) VALUES (?, ?, ?, ?, ?, ?)';
            foreach ($items as $it) {
                $val = [$orderId, $it['product_id'], $it['name'], $it['variant'] ?? 'copt', $it['qty'], $it['unit_price']];
                if ($cuLista) {
                    $val[] = $it['list_price'] ?? null;
                }
                q($sqlPozitie, $val);
            }

            // Reducerea trece și ea. Panoul de comenzi nu deschide cererea din
            // care s-a născut comanda — și nici n-ar avea ce deschide pentru una
            // luată la telefon —, deci fără rândul ăsta laboratorul ar vedea un
            // total sub catalog fără nimic care să spună de ce. UPDATE, nu
            // coloană în INSERT, din același motiv ca peste tot: coloana vine cu
            // migrarea 011.
            if ($cuLista && ($req['promo'] ?? null) !== null && (string) $req['promo'] !== '') {
                q('UPDATE orders SET promo = ? WHERE id = ?', [(string) $req['promo'], $orderId]);
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

    // Pe unde s-a hotărât: „panou" de obicei, „telegram" când apăsarea a venit
    // de sub anunțul de pe telefon. Separat de UPDATE-ul care tocmai a schimbat
    // starea, și după el — v. `requests_mark_decided()`.
    requests_mark_decided($id, requests_decided_via());

    // Comisionul se naște la acceptare, nu la trimiterea cererii: până aici nu
    // există comandă, deci nu există nici bază de calcul. Codul l-a scris
    // clientul poate acum trei zile, în coș — de acolo îl luăm.
    //
    // Acceptarea are INSERT propriu și NU trece prin `route_orders_create`,
    // deci cârligul trebuie scris și aici. Aceeași notă ca la
    // `stock_reserve_order()` de mai sus, din același motiv.
    //
    // După commit și în try/catch: cererea e deja acceptată și comanda scrisă.
    $comision = null;
    try {
        $codCerere = referral_money_supported()
            ? (string) (scalar('SELECT referral_code FROM order_requests WHERE id = ?', [$id]) ?? '')
            : '';
        $comision = referral_commission_sync($orderId, $codCerere !== '' ? $codCerere : null, (int) $u['id']);
    } catch (Throwable $e) {
        error_log('[gp] comision nescris la acceptarea cererii ' . $id . ': ' . $e->getMessage());
    }

    // Recitită după cârlig, din același motiv ca la crearea comenzii de la
    // panou (api/routes/orders.php, funcția care scrie comanda nouă):
    // `referral_commission_sync()` scrie `orders.referral_code`, iar un rând
    // citit înaintea lui poartă încă `null` acolo.
    $order = attach_items([one('SELECT * FROM orders WHERE id = ?', [$orderId])], true)[0];
    $order['commission'] = $comision === null ? null : [
        'id'       => (int) $comision['id'],
        'referrer' => $comision['referrer_id'] !== null ? (int) $comision['referrer_id'] : null,
        'label'    => $comision['referrer_label'],
        'amount'   => round((float) $comision['amount'], 2),
        'rate'     => round((float) $comision['rate'], 2),
        'status'   => (string) $comision['status'],
        'paid'     => $comision['paid_at'] !== null,
    ];

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

    // Anunțul „Comandă nouă" pleacă și de aici, nu numai din panou.
    //
    // Catalogul din Setări îl promite pe față — „o comandă scrisă în panou sau
    // născută dintr-o cerere acceptată" (`api/lib/notify.php`, cheia
    // `order_new`) — iar documentația lui `notify_new_order()` spune la fel.
    // Cârligul lipsea totuși tocmai aici, din același motiv ca la stoc și la
    // comision, mai sus: acceptarea are INSERT propriu și nu trece prin
    // `route_orders_create()`. Deci patiseria care lucrează numai cu comenzi
    // venite de pe site avea bifa pornită și nu primea niciun anunț la nicio
    // comandă — nici pe e-mail, nici pe Telegram.
    //
    // Răspunsul pleacă ÎNAINTEA anunțurilor, ca la crearea din panou: comanda
    // e deja scrisă și comisă, iar un furnizor căzut ține conexiunea câte
    // cincisprezece secunde. Trimise întâi, țineau butonul „Acceptă" apăsat o
    // jumătate de minut, iar peste `max_execution_time` procesul era ucis în
    // timpul apelului: panoul arăta eroare pentru o comandă care exista.
    json_out_early(['order' => $order], 201);

    // De aici încolo nimic nu mai ajunge la client. Un anunț care nu pleacă
    // rămâne scris în `notifications` cu status „failed", cu tot cu motivul,
    // de unde se vede în Setări ce s-a pierdut.
    try {
        notify_new_order($order);
    } catch (Throwable $e) {
        error_log('[gp] notificare eșuată pentru comanda ' . $orderId . ': ' . $e->getMessage());
    }
    // Fără oprire, dispecerul din index.php ar mai scrie octeți după un răspuns
    // deja trimis.
    exit;
}

/** POST /requests/{id}/reject */
function route_requests_reject(string $id): void
{
    // Doar administratorul, ca la acceptare. O respingere trimisă din contul
    // comun al tabletelor trecea cererea definitiv pe „respinsa”, iar de acolo
    // acceptarea nu mai e posibilă (409 „already_handled”): comanda clientului
    // se pierdea, iar jurnalul îl trecea pe autor tot ca „Panou comun”.
    $u  = require_admin();
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
            // Lungimea coloanei (255), verificată aici: peste ea UPDATE-ul
            // pica cu 1406, tranzacția se întorcea și adminul vedea „Eroare
            // internă." pentru un motiv de refuz scris mai lung.
            [text_field($b['reason'] ?? '', 'Motivul', 255) ?: null, (int) $u['id'], $id]
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

    // Ca la acceptare: pe unde s-a hotărât se însemnează după ce starea s-a
    // schimbat, și singur, ca o coloană lipsă să nu piardă respingerea.
    requests_mark_decided($id, requests_decided_via());

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
    require_admin();
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

    // Hotărârea s-a desfăcut, deci se șterge și urma ei: cererea e din nou în
    // așteptare, iar un „respinsă de pe telefon" rămas lipit de ea ar minți la
    // prima reclamație — adică tocmai la treaba pentru care există coloana.
    requests_mark_decided($id, null);

    audit('request_reopen', 'request', (string) $id, 'Refuz anulat — ' . $req['customer_name']);
    json_out(['ok' => true]);
}

/* ------------------------------------------------------------- ajutoare -- */

/**
 * Pe unde vine hotărârea care se scrie acum: din panou sau din Telegram.
 *
 * Rutele de acceptare și de respingere n-au de unde ști pe ce ușă au fost
 * chemate: dispecerul din api/index.php le cheamă cu argumente fixe — calea și
 * atât —, iar butoanele din Telegram trec prin CHIAR rutele astea
 * (`telegram_prin_ruta()`, api/routes/telegram.php) tocmai ca să nu existe două
 * purtări deosebite pentru aceeași faptă. O semnătură lărgită aici ar fi cerut
 * lărgit și dispecerul, și toate celelalte rute odată cu el.
 *
 * Deci stratul de Telegram își spune numele o dată, la intrare, și rămâne spus
 * până la sfârșitul cererii — o cerere PHP servește o singură apăsare, deci
 * `static` nu se poate încurca între doi oameni.
 *
 * Implicitul e „panou" fiindcă acela e drumul obișnuit, și fiindcă o hotărâre
 * fără origine scrisă e tocmai ce nu se mai poate lămuri la o reclamație.
 */
function requests_decided_via(?string $set = null): string
{
    static $via = 'panou';
    if ($set !== null) {
        $via = $set;
    }
    return $via;
}

/**
 * Închide hotărârea: scrie pe unde s-a luat și șterge ce-a mai rămas din
 * amânare.
 *
 * `decided_via` e rostul funcției: „panou" la hotărârea luată din ecran,
 * „telegram" la cea luată de sub anunț — singurul loc din care se poate lămuri,
 * la o reclamație, cine a hotărât și de unde. La redeschidere se golește, cu
 * `$via` dat `null`.
 *
 * `snoozed_until` a rămas în UPDATE deși nimeni nu mai scrie amânări: butonul
 * „Mai târziu" a plecat, ceasul care le cocea la fel, dar coloana a rămas în
 * bază (o migrare adaugă, nu dărâmă — sql/013 nu se rescrie după ce s-a aplicat
 * pe serverele oamenilor), și pe bazele de până azi ea poartă ore scrise
 * înainte. Panoul de cereri se uită încă la ele și arată „Amânată" în locul
 * stării adevărate până le trece ora; golirea de aici ține cele două ceasuri
 * din urmă curate, ca o cerere hotărâtă să se vadă hotărâtă pe loc. Când
 * panoul va uita și el de amânări, rândul ăsta poate pleca — și odată cu el
 * cheia `snoozedUntil` din răspuns, mai jos.
 *
 * DE CE SEPARAT DE UPDATE-UL CARE SCHIMBĂ STAREA, ȘI DUPĂ EL: pe un server pe
 * care nu s-a rulat migrarea 013 coloanele nu există. Lipite de acceptare, ele
 * ar fi făcut ca acceptarea SĂ CADĂ cu 1054 — cererea n-ar mai fi devenit
 * comandă din pricina unei însemnări. Aici se pierde numai însemnarea, iar
 * jurnalul serverului spune că s-a pierdut.
 */
function requests_mark_decided(int $id, ?string $via): void
{
    try {
        q('UPDATE order_requests SET decided_via = ?, snoozed_until = NULL WHERE id = ?', [$via, $id]);
    } catch (Throwable $e) {
        error_log('[gp] „pe unde s-a hotărât" nescris pentru cererea ' . $id . ': ' . $e->getMessage());
    }
}

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

    // Cine a adus cumpărătorul, pentru eticheta violetă de pe listă.
    //
    // Codul e tastat de mână în coș, deci poate să nu fie al nimănui: greșit,
    // vechi, sau de pe un cont închis între timp. Atunci numele lipsește, iar
    // pagina scrie doar codul — patronul vede ce a scris omul și de ce nu duce
    // nicăieri, în loc să nu vadă nimic.
    //
    // Pe o bază fără migrarea 008 coloana nici nu există, iar `SELECT r.*` se
    // întoarce fără ea: `?? null` ține lista întreagă până la import.
    $coduri = [];
    foreach ($rows as $r) {
        $c = $r['referral_code'] ?? null;
        if (is_string($c) && $c !== '') {
            $coduri[] = $c;
        }
    }
    $adus = $coduri ? referral_owners_for_codes($coduri) : [];

    return array_map(static function (array $r) use ($byReq, $adus) {
        $cod = $r['referral_code'] ?? null;
        $cod = is_string($cod) && $cod !== '' ? $cod : null;
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
            // Amândouă vin cu migrarea 013 și amândouă lipsesc dintr-un
            // `SELECT r.*` făcut pe o bază nemigrată — de aceea `?? null`, ca
            // la `referral_code` de mai sus: lista rămâne întreagă până la
            // import.
            //
            // `snoozedUntil` nu se mai umple de nicăieri: butonul „Mai târziu"
            // a plecat cu tot cu ceasul lui. Cheia rămâne fiindcă panoul de
            // cereri o citește încă („Amânate", Administrare Comenzi.dc.html),
            // iar pe bazele de până azi mai sunt ore scrise înainte de
            // schimbare. Scoasă acum, filtrul acela ar arăta gol fără să spună
            // de ce; se scoate odată cu el.
            //
            // Ora e cea scrisă de MySQL, în UTC, și pleacă exact cum a fost
            // citită — „2026-09-12 20:15:00", fără fus și fără „Z" —, la fel ca
            // `createdAt` și `handledAt`. Deci panoul o trece prin aceeași
            // socoteală prin care le trece și pe acelea, nu prin `new Date()`
            // pe curat, care ar citi-o ca oră locală și ar greși cu trei ore.
            'snoozedUntil' => $r['snoozed_until'] ?? null,
            'decidedVia'   => $r['decided_via'] ?? null,
            'referralCode' => $cod,
            'referralName' => $cod === null ? null : ($adus[$cod]['name'] ?? null),
            'referralId'   => $cod === null ? null : ($adus[$cod]['id'] ?? null),
            'items'        => $byReq[(int) $r['id']] ?? [],
        ];
    }, $rows);
}
