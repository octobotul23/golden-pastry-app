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
    } elseif (!orders_readable($u)) {
        // Aceeași cădere întoarsă ca la comenzi, și în aceeași ordine: cine
        // n-are registrul deschis — azi omul de laborator, mâine oricine căruia
        // fondatorul i-a luat din „Acces" și Comenzile, și Laboratorul, și Ruta
        // — nu vede niciun rând. Se întreabă ÎNAINTEA ramurii agentului, ca
        // luarea panoului să-i ia și felia. Regula stă scrisă o singură dată,
        // în `orders_readable()` (api/lib/auth.php).
        $where[] = '1 = 0';
    } elseif ($u['role'] === 'agent_vanzari') {
        $sc      = referral_agent_scope((int) $u['id'], 'r.customer_id', 'r.referral_code');
        $where[] = $sc['sql'];
        $params  = array_merge($params, $sc['params']);
    }

    // „CERERILE SCRISE DE MINE” — `?mine=1`, perechea celui din lista
    // comenzilor (`route_orders_list()`, api/routes/orders.php).
    //
    // Hotărârea patronului din 4–5 octombrie 2026 (runda 2.7.0): istoricul e și
    // al celor din casă care scriu comenzi, iar în el intră și cererile lor
    // care n-au devenit comenzi. Aici regula e mai scurtă decât la comenzi:
    // cererea are autor numai când a scris-o un om al casei (migrarea 022), iar
    // cea de pe site n-are niciunul — deci nu mai trebuie un canal care să le
    // despartă.
    //
    // ÎN OGRADĂ, NU NUMAI ÎN LISTĂ, și de aceea stă DEASUPRA rândurilor care pun
    // ograda deoparte. `counts` se numără din ogradă: cu condiția pusă mai jos,
    // lângă filtrul de stare, pagina ar fi scris „3 respinse” deasupra unei
    // liste cu una — numărul casei peste lista omului.
    //
    // NUMAI ÎNGUSTEAZĂ, ca la comenzi: marginile de deasupra rămân toate și se
    // leagă cu AND. Clientul își vede oricum numai cererile lui, deci
    // parametrul nu-l atinge. Numai șirul „1” îl aprinde, comparat fără
    // `(string)` — un `?mine[]=1` sosește ca tablou.
    //
    // FĂRĂ MIGRAREA 022 coloana `created_by` lipsește, iar numită în WHERE ar
    // fi căzut cu „Unknown column”, adică 500 pe o listă. Pe o asemenea bază
    // nimeni din casă n-a scris vreo cerere pe numele lui — n-avea unde să-și
    // lase numele —, deci răspunsul adevărat e „niciun rând”: `1 = 0`, ca la
    // registrul închis, și numărătoarea iese zero odată cu lista.
    if ($u['role'] !== 'client' && ($_GET['mine'] ?? null) === '1') {
        if (request_staff_supported()) {
            $where[]  = 'r.created_by = ?';
            $params[] = (int) $u['id'];
        } else {
            $where[] = '1 = 0';
        }
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
        // Numele celui care a hotărât iese numai pentru cei din casă — cum
        // iese și autorul comenzii, din aceeași pricină.
        'requests' => attach_request_items(all($sql, $params), $u['role'] !== 'client'),
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
 * A DOUA TRIMITERE A ACELEIAȘI CERERI — răspunsul cererii dintâi (migrarea 026).
 *
 * Se cheamă din două locuri, amândouă în `route_requests_create()`. Cel vechi
 * e `catch`-ul, când `INSERT`-ul a căzut pe `uq_req_cheie`: ciocnirea aia nu e
 * o eroare — e chiar dovada că cererea asta a intrat deja, o dată. Cel nou
 * (runda 2.7.0) e poarta pragului de comenzi neplătite, care caută cheia
 * ÎNAINTE să numere: altfel clientul aflat fix la prag ar primi „refuzat”
 * pentru o cerere primită. Amândouă o cheamă cu tranzacția ÎNCHISĂ.
 *
 * HOTĂRÂREA PATRONULUI: „ÎI ARĂTĂM CĂ A REUȘIT." Nu se refuză și nu se numără a
 * doua oară. Iese același 201 cu același rând, ca și cum atunci ar fi sosit —
 * fiindcă, din partea omului, atunci a și sosit: a apăsat o dată, legătura a
 * căzut, a apăsat din nou. Laboratorul primește UNA.
 *
 * NU TRIMITE ANUNȚURILE ÎNCĂ O DATĂ. Cererea dintâi și le-a trimis pe ale ei
 * când a intrat. Repetate, ar fi umplut e-mailul și Telegramul patiseriei cu
 * aceeași comandă de două ori — adică taman dublura de oprit, mutată de pe
 * ecranul administratorului pe telefonul lui.
 *
 * NU SCADE DIN PLAFONUL PE CONEXIUNE. O legătură proastă ar fi costat clientul
 * două cereri din douăzeci pentru o singură comandă — o nedreptate făcută
 * tocmai omului pe care lucrarea asta s-a pus să-l ajute. Cererile lui
 * adevărate se numără mai departe, la poartă, fiecare o dată.
 *
 * JETONUL SE FACE DIN NOU, dacă omul ceruse cont. Cel dintâi a plecat o
 * singură dată, în răspunsul care S-A PIERDUT — de-aia e omul aici a doua oară
 * —, deci nu-l are nimeni, iar `request_id` e UNIQUE și n-ar primi un al
 * doilea. Fără asta, vizitatorul care a apăsat „Trimite comanda ȘI fă-ți cont"
 * ar fi ajuns la „Mulțumim" fără drumul spre cont — adică nu chiar „ca prima
 * oară".
 *
 * Întoarce fără să scrie nimic dacă rândul nu mai e acolo (o tranzacție dată
 * înapoi exact între timp). Atunci `route_requests_create()` aruncă mai departe
 * eroarea dintâi, cum ar fi făcut oricum.
 */
function request_reply_again(string $cheie, ?array $user, array $b): void
{
    $vechi = one('SELECT * FROM order_requests WHERE idempotency_key = ?', [$cheie]);
    if (!$vechi) {
        return;
    }

    $id  = (int) $vechi['id'];
    $req = attach_request_items([$vechi])[0];

    audit(
        'request_repeat',
        'request',
        (string) $id,
        'Aceeași cerere, trimisă din nou — i-am răspuns cu cea dintâi'
    );

    $minuteJeton = (int) config('claim_minutes', 30);
    $jeton = ($user === null && $vechi['customer_id'] === null && !empty($b['vreauCont']))
        ? request_claim_new($id, $minuteJeton, true)
        : null;

    // `dejaPrimita` e pentru pagină, nu pentru om: pe ecran scrie „Mulțumim",
    // la fel ca prima oară. Steagul îi spune paginii să nu numere încă o dată
    // ce a numărat deja, și e și singurul lucru pe care o probă îl poate cere
    // ca să deosebească răspunsul ăsta de celălalt — amândouă sunt 201 cu
    // același rând, și asta e chiar ce s-a cerut.
    $raspuns = ['request' => $req, 'dejaPrimita' => true];
    if ($jeton !== null) {
        $raspuns['jeton']       = $jeton;
        $raspuns['jetonMinute'] = $minuteJeton;
    }
    json_out($raspuns, 201);
}

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

    // Cererea asta e scrisă de un om al casei, sau vine de pe site?
    //
    // Întrebarea e nouă de la 18 septembrie 2026, când `POST /requests` a
    // devenit și drumul agentului de vânzări și al angajatului: ei scriu o
    // cerere acolo unde până ieri scriau o comandă (v. `GP_ORDER_CREATE_ROLES`,
    // api/lib/auth.php). `e_personal()`, nu „are cont": un client cu cont e
    // tot lumea de pe site. Omul de laborator scrie de aici numai dacă a primit
    // din „Acces" panoul Comenzi — altfel cererea lui, cu autor cu tot, s-ar fi
    // înregistrat ca venită de pe site.
    $dinCasa = $user && e_personal($user, 'comenzi');

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
    //
    // PRAGUL, PENTRU CINE N-ARE OGRADĂ, SE JUDECĂ LA ACCEPTARE (9 octombrie
    // 2026). Tăcerea de mai sus avea o crăpătură la celălalt capăt: omul fără
    // ogradă (cel din laborator, cu panoul „Comenzi” primit din „Acces”) leagă
    // după nume orice cont — iar poarta pragului, câteva zeci de rânduri mai
    // jos, îi răspundea 409 pentru numele unui client ajuns la prag și 201
    // pentru oricare altul. Adică formularul rămânea unealtă de ghicit, numai
    // că acum ghicea cine e DATOR: scrii numele firmei, și răspunsul îți spune
    // dacă mai are voie să comande.
    //
    // Deci pentru el cererea legată de un cont INTRĂ, în așteptare, iar
    // răspunsul nu spune nimic despre plăți: e același, valoare cu valoare,
    // pentru clientul ajuns la prag și pentru unul cu aceeași istorie plătită.
    // (Că numele scris ARE cont se vede și acum, din `customerId` și din
    // `rangClient` — legarea după nume e hotărârea din 14 septembrie și nu ține
    // de plăți. Ce nu se mai vede e cum STĂ contul acela.) Cererea nu scapă de
    // prag: cea cu produse se numără din clipa asta împotriva clientului
    // (`plati_situatie()`), iar poarta o judecă la acceptare — în fața cuiva
    // care scrie comenzi, adică a cuiva care are voie să vadă lista.
    //
    // CINE E „EL” SE ÎNTREABĂ ÎNTR-UN SINGUR LOC: `plati_poate_afla()`, aceeași
    // socoteală care alege între refuzul lung și cel scurt. Cine ar primi aici
    // refuzul SCURT nu e judecat aici deloc — fiindcă refuzul scurt, dat la
    // trimitere, e chiar răspunsul „da/nu” despre datoria clientului, oricât de
    // puține vorbe ar avea.
    //
    // Până la a doua citire (9 octombrie 2026) semnul se ridica numai pe
    // drumul numelui și numai pentru cine n-are ogradă deloc
    // (`client_scope_for() === null`). Rămânea pe dinafară agentul căruia
    // fondatorul i-a luat din „Acces” panoul „Comenzi”: ograda lui e a
    // ROLULUI, deci cererea îi era judecată la trimitere; socoteala comună
    // cere însă și panoul, deci refuzul lui era cel scurt. 409 pentru clientul
    // lui ajuns la prag, 201 pentru oricare altul — și scriind numele, și
    // alegând contul din listă (`test-plati-prag`, secțiunea N). De aceea
    // semnul stă acum DUPĂ amândouă legările și nu mai întreabă pe ce drum s-a
    // ajuns la cont.
    //
    // Ce NU se schimbă: clientul în nume propriu, agentul cu panoul „Comenzi”
    // (pentru clienții lui) și cine scrie comenzi sunt opriți tot aici, la
    // trimitere, cu lista. Ce le spune refuzul le-ar fi spus și ușa din timp.
    if ($customerId === null && $user) {
        $customerId = client_by_name_in_scope($user, $customerName);
    }
    $pragLaAcceptare = $customerId !== null && !plati_poate_afla($user, (int) $customerId);
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

    // CE A ALES OMUL CARE A LUAT TELEFONUL (migrarea 022).
    //
    // Cele trei câmpuri n-au existat pe cerere fiindcă cererea era a site-ului:
    // vizitatorul n-are de unde ști ce înseamnă „Curier" la noi în casă. De
    // când cererea e și drumul agentului, ele sunt chiar ce știe el și nu știe
    // nimeni altcineva — clientul i-a spus la telefon că o vrea până la zece și
    // că vine șoferul. Fără ele, alegerile lui se pierdeau tăcut între „Trimite"
    // și „Acceptă": comanda născută cădea pe `default_fulfillment()` și nu era
    // urgentă, iar administratorul n-avea de unde bănui că a fost.
    //
    // NUMAI DE LA PERSONAL, și nici măcar citite în rest. Deschise, ar fi fost
    // trei câmpuri pe care orice vizitator le poate trimite prin curl: „urgent"
    // sare peste toată coada laboratorului, iar modul de onorare s-ar fi ales
    // de cineva care nu răspunde de el. Pe site nu sunt în formular; aici nu
    // sunt în cerere.
    //
    // GOL NU E O ALEGERE. `fulfillment` rămas gol se scrie NULL, adică „cererea
    // nu spune", iar acceptarea cade pe implicitul din Setări — exact ca la
    // cererile de pe site. Scris ca „Livrare", ar fi fost alegerea nimănui,
    // trecută drept a cuiva.
    $fulfillment = '';
    $urgent      = false;
    $timed       = false;
    if ($dinCasa) {
        $fulfillment = trim((string) ($b['fulfillment'] ?? ''));
        if ($fulfillment !== '' && !in_array($fulfillment, fulfillment_types(), true)) {
            fail('Mod de onorare necunoscut: ' . $fulfillment, 422, 'bad_fulfillment');
        }
        $urgent = !empty($b['urgent']);
        $timed  = !empty($b['timed']);
    }

    // CHEIA CARE RECUNOAȘTE A DOUA TRIMITERE A ACELEIAȘI CERERI (migrarea 026).
    //
    // Treizeci și două de cifre hexazecimale scrise de pagină o dată pe apăsare
    // și ținute la retrimitere. `request_key_normalize()` le cere pe toate
    // treizeci și două; orice altceva se citește ca „fără cheie", iar cererea
    // intră exact ca ieri.
    //
    // SPRE DEOSEBIRE DE CELELALTE COLOANE NOI, ASTA MERGE ÎN `INSERT`, NU ÎNTR-UN
    // `UPDATE` DE DUPĂ. Coloanele lui 022 și codul de recomandare se scriu cu
    // UPDATE tocmai ca o bază nemigrată să nu cadă cu „Unknown column" pe ruta
    // publică — dar aici toată reparația E scrierea dintâi: două cereri plecate
    // în aceeași secundă ar trece amândouă de INSERT și abia pe urmă și-ar
    // scrie cheia, adică exact dublura de oprit. Deci se alege INTEROGAREA,
    // după poartă, ca la `list_price` în pozițiile de mai jos.
    $cheie   = request_key_normalize((string) ($b['cheie'] ?? ''));
    $cuCheie = $cheie !== '' && request_key_supported();

    // Trecerea peste pragul de comenzi neplătite, dacă a fost una: se scrie în
    // jurnal abia după commit, cu numărul cererii născute (v. poarta de mai jos).
    $pragOcolit = null;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // POARTA PRAGULUI DE COMENZI NEPLĂTITE (runda 2.7.0).
        //
        // Hotărârea patronului, 4–5 octombrie 2026: a doua comandă neplătită
        // trece cu avertizare, a treia se oprește până la plată — pentru client,
        // pentru agent și pentru manager la fel. Numai cine are dreptul
        // `plati.ocoleste` (din pornire fondatorul) poate trece, cu confirmare.
        //
        // NUMAI CEREREA CU PRODUSE, LEGATĂ DE UN CONT. Mesajul fără coș (o
        // întrebare, o cerere de ofertă) nu e o comandă și nu se oprește
        // niciodată: tocmai omul rămas dator trebuie să ne poată scrie. Iar
        // vizitatorul fără cont n-are ce prag să atingă — pragul e al contului.
        //
        // E PRIMA INSTRUCȚIUNE A TRANZACȚIEI, și nu din pedanterie. Baza lucrează
        // pe REPEATABLE READ: prima citire fără lacăt îngheață imaginea pe care
        // o vede tranzacția. Lacătul pe rândul clientului (`plati_lacat()`) NU o
        // îngheață, deci numărătoarea de după el vede tot ce a scris fila care a
        // ținut lacătul înainte. Pusă după `INSERT`, ori după o singură citire
        // nevinovată, două cereri plecate deodată ar număra amândouă „unu” și ar
        // trece amândouă.
        if ($priced && $customerId !== null) {
            plati_lacat($customerId);

            // ÎNTÂI RETRIMITEREA, APOI PRAGUL (migrarea 026).
            //
            // Clientul aflat fix la prag trimite cererea, ea intră, răspunsul se
            // pierde pe drum, iar pagina o trimite din nou cu aceeași cheie.
            // Întrebat întâi pragul, a doua trimitere ar număra cererea dintâi —
            // a lui! — și ar răspunde „refuzat” pentru o cerere PRIMITĂ. Omul ar
            // suna să întrebe de ce, iar laboratorul ar coace deja.
            //
            // Rândul se caută SUB lacătul clientului: două retrimiteri pornite
            // deodată stau la rând aici, iar a doua o vede pe prima scrisă.
            // (Fără lacăt, a doua cade tot bine, pe `uq_req_cheie`, în `catch`.)
            if ($cuCheie && scalar('SELECT 1 FROM order_requests WHERE idempotency_key = ? LIMIT 1', [$cheie])) {
                // Tranzacția se închide ÎNAINTE de răspuns: `request_reply_again()`
                // scrie în jurnal și iese prin `json_out()`, iar lacătul clientului
                // n-are de ce să mai țină pe nimeni.
                $pdo->rollBack();
                request_reply_again($cheie, $user, $b);
                // S-A ÎNTORS FĂRĂ SĂ RĂSPUNDĂ: rândul a pierit exact între cele
                // două citiri (o curățenie, o ștergere). Cererea asta e deci una
                // nouă — dar tranzacția e închisă, iar lacătul s-a dus cu ea.
                // Se iau amândouă de la capăt; altfel `INSERT`-ul de mai jos s-ar
                // scrie fără tranzacție și `commit()` ar cădea.
                $pdo->beginTransaction();
                plati_lacat($customerId);
            }

            // Lacătul și căutarea retrimiterii rămân pentru toți; numai
            // JUDECATA se sare pentru cererea celui care n-are de ce să afle
            // cum stă clientul (de ce, acolo unde se pune `$pragLaAcceptare`).
            // Cererea lui se scrie sub același lacăt, deci numărătoarea
            // clientului o vede la următoarea întrebare a oricui.
            if (!$pragLaAcceptare) {
                $pragOcolit = plati_poarta($customerId, $user, $b);
            }
        }

        q(
            $cuCheie
                ? 'INSERT INTO order_requests
                     (customer_id, customer_name, phone, email, day, slot, zone, address, notes, total, status, variant, idempotency_key)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO order_requests
                     (customer_id, customer_name, phone, email, day, slot, zone, address, notes, total, status, variant)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge(
                [
                    $customerId, $customerName, $phone ?: null, $email ?: null,
                    $day, $slot ?: null, $zone ?: null, $address ?: null,
                    $notes ?: null,
                    round($total, 2), 'nou', $variant,
                ],
                $cuCheie ? [$cheie] : []
            )
        );
        $id = (int) $pdo->lastInsertId();

        // Cele patru coloane ale migrării 022 se scriu cu un UPDATE, nu în
        // INSERT-ul de deasupra — același motiv ca la codul de recomandare de
        // mai jos: numite acolo, ORICE cerere de pe site ar cădea cu „Unknown
        // column" în toată fereastra dintre urcarea fișierelor și import. Aici,
        // pe o bază nemigrată, pur și simplu nu se execută nimic și cererea
        // intră întocmai ca ieri.
        //
        // Rămâne în aceeași tranzacție, și nu din grijă pentru o coloană sau
        // alta: o cerere scrisă de agent care și-ar pierde tocmai autorul n-ar
        // mai putea fi deosebită de una de pe site, iar comanda născută din ea
        // s-ar înregistra pe canalul „site" — o minciună scrisă în bază, nu o
        // lipsă.
        //
        // Un singur UPDATE pentru toate patru, fiindcă migrarea le aduce
        // împreună și `request_staff_supported()` le cere pe toate patru.
        if ($dinCasa && request_staff_supported()) {
            q(
                'UPDATE order_requests SET fulfillment = ?, urgent = ?, timed = ?, created_by = ? WHERE id = ?',
                [$fulfillment ?: null, $urgent ? 1 : 0, $timed ? 1 : 0, (int) $user['id'], $id]
            );
        }

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
        // veche ar arăta o reducere de altă mărime — sau niciuna. Treapta care a
        // dat prețul merge și ea (migrarea 028), ca acceptarea să știe ce prag a
        // câștigat-o. Interogarea se alege o singură dată, în afara buclei, ca o
        // bază nemigrată să nu cadă pe fiecare poziție — v. `pozitie_insert()`.
        [$sqlPozitie, $cuLista, $cuTrepte] = pozitie_insert(
            'order_request_items', 'request_id, product_id, name, variant, qty, unit_price'
        );
        foreach ($priced as $p) {
            q($sqlPozitie, pozitie_valori(
                [$id, $p['id'], $p['name'], $p['variant'], $p['qty'], $p['unit']],
                $cuLista, $cuTrepte, $p['catalog'] ?? null, $p['treapta'] ?? 1, $p['prag'] ?? null
            ));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        // „Dacă mai e deschisă”: poarta pragului de mai sus își închide singură
        // tranzacția înainte de a răspunde cu cererea dintâi, iar o eroare
        // apărută chiar atunci ar ajunge aici cu tranzacția deja închisă —
        // `rollBack()` gol ar arunca „There is no active transaction” și ar
        // îngropa eroarea adevărată sub a lui.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // CIOCNIREA DE CHEI NU E O EROARE, E RĂSPUNSUL (migrarea 026).
        //
        // `uq_req_cheie` a oprit a doua scriere a aceleiași cereri, adică exact
        // ce i s-a cerut. De aici încolo nu mai e nimic de salvat — cererea e
        // deja în bază, întreagă, cu pozițiile ei — deci se răspunde cu ea.
        //
        // DUPĂ `rollBack()`, nu înainte: tranzacția căzută trebuie închisă până
        // să se mai citească ceva pe aceeași legătură, iar rândul căutat e al
        // ALTEI tranzacții, demult încheiată.
        //
        // Se cere numele indexului, nu doar codul 1062: în tranzacția asta mai
        // sunt pozițiile și trei UPDATE-uri, iar un rând dublat venit de
        // altundeva e un defect adevărat — înghițit cu un 201 vesel, ar fi
        // dispărut din ochii tuturor.
        if ($cuCheie && request_key_duplicate($e)) {
            request_reply_again($cheie, $user, $b);
        }
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

    // Trecerea peste prag lasă urmă: cine, pentru ce client, câte neîncasate
    // avea și ce s-a scris. După commit, ca rândul să nu poată vorbi despre o
    // cerere care n-a mai intrat.
    if ($pragOcolit !== null) {
        plati_jurnal_ocolire($pragOcolit, 'cererea #' . $id);
    }

    // Jetonul cu care omul își leagă cererea de contul pe care și-l face în
    // clipa următoare. Numai pentru VIZITATORUL ANONIM care a apăsat chiar
    // „Trimite comanda și fă-ți cont": un secret care nu trebuie nimănui nu se
    // face. Cererea scrisă de personal pentru altcineva nu primește jeton —
    // altfel jetonul ar fi o cale de a da cererea unui client unui cont
    // oarecare; nici cea a clientului deja intrat în cont, care e legată.
    //
    // Se naște DUPĂ commit, ca adresa pusă pe cont mai sus și din același
    // motiv: o piedică la jeton n-are voie să piardă cererea. `request_claim_new()`
    // își înghite singură necazurile și întoarce null.
    $minuteJeton = (int) config('claim_minutes', 30);
    $jeton = ($user === null && $customerId === null && !empty($b['vreauCont']))
        ? request_claim_new($id, $minuteJeton)
        : null;

    $raspuns = ['request' => $req];
    if ($jeton !== null) {
        $raspuns['jeton']       = $jeton;
        $raspuns['jetonMinute'] = $minuteJeton;
    }

    // Răspunsul pleacă înaintea anunțurilor, nu după ele. Cererea e deja
    // scrisă în bază, iar anunțurile se duc pe rând la e-mail, la Meta și la
    // Telegram, unde un furnizor căzut ține conexiunea până la cincisprezece
    // secunde de fiecare dată. Trimise întâi, țineau vizitatorul de pe site cu
    // butonul „Se trimite…" apăsat o jumătate de minut pentru o cerere care
    // reușise, și țineau ocupat câte un proces PHP tot atâta.
    json_out_early($raspuns, 201);

    // De aici încolo nimic nu mai ajunge la client: răspunsul a plecat cu 201,
    // fiindcă asta s-a și întâmplat — cererea a fost salvată. Un anunț care nu
    // pleacă rămâne scris în tabelul `notifications` cu status „failed" și în
    // jurnalul de erori al serverului, de unde se poate afla ce s-a pierdut.
    //
    // Cine a scris cererea se agață ABIA AICI, după răspuns: `anunt` e numai
    // pentru mesaj (`anunt_randuri_oameni()`, api/lib/notify.php). Pus mai sus,
    // ar fi plecat în 201, iar vizitatorul de pe site ar fi citit id-ul unui
    // om din casă. Al cui e clientul își află mesajul singur, din contul
    // clientului și din codul cererii: aici nu s-a hotărât încă niciun comision.
    $req['anunt'] = ['autorId' => $dinCasa ? (int) $user['id'] : null, 'dePeSite' => !$dinCasa];
    try {
        notify_new_request($req);
    } catch (Throwable $e) {
        error_log('[gp] notificare eșuată pentru cererea ' . $id . ': ' . $e->getMessage());
    }
    // Fără oprirea asta, dispecerul din index.php ar chema fail(...) după
    // rută și ar lipi octeți în plus la un răspuns deja trimis.
    exit;
}

/* ------------------------------------------- jetonul, citit fără stins -- */

/**
 * POST /requests/claim { jeton } — datele cererii, pentru formularul de cont.
 *
 * Nu stinge jetonul și nu scrie nimic. Rostul ei e să umple casetele
 * formularului de înscriere cu ce a scris omul în comandă: numele, telefonul,
 * e-mailul, adresa, zona. Fără ea, datele ar fi trebuit să treacă prin adresă
 * — și datele personale n-au ce căuta într-o adresă —, iar `sessionStorage`
 * nu le poate duce: magazinul stă pe goldenpastry.ro, „Intrare" pe
 * clienti.goldenpastry.ro, adică două origini deosebite.
 *
 * RUTĂ PUBLICĂ, DINADINS: cine o cheamă n-are încă niciun cont, tocmai de aceea
 * o cheamă. Poarta e jetonul însuși — 256 de biți dați o singură dată, în
 * răspunsul la chiar trimiterea acelei cereri. Nu se poate ghici, iar cu el nu
 * se deschide nimic altceva: o singură cerere, atât.
 *
 * Fără sesiune, `check_csrf()` se întoarce oricum de la ușă, deci ruta nu
 * trebuie trecută în `csrf_exempt()` — și nici nu se cuvine: lista aceea e
 * pentru scutiri, nu pentru cereri care n-au ce scuti.
 */
function route_requests_claim(): void
{
    $b   = body();
    $req = request_claim_peek(trim((string) ($b['jeton'] ?? '')));

    if (!$req) {
        // UN SINGUR MOTIV PENTRU TOATE EȘECURILE — expirat, folosit, necunoscut,
        // bază fără migrare. Deosebite, răspunsul ar fi devenit un oracol care
        // spune dacă un jeton a existat vreodată.
        fail(
            'Legătura cu comanda a expirat sau a fost deja folosită. '
                . 'Fă-ți contul și te sunăm noi — comanda ta a plecat deja.',
            410,
            'expired_token'
        );
    }

    // NUMAI ce se pune în formular. Cererea are și poziții, și total, și
    // mențiuni; niciuna n-are ce căuta într-un răspuns care pleacă fără
    // sesiune. Cu cât dă mai puțin, cu atât un jeton scăpat costă mai puțin.
    json_out(['cerere' => [
        'customerName' => $req['customer_name'],
        'phone'        => $req['phone'],
        'email'        => $req['email'],
        'address'      => $req['address'],
        'zone'         => $req['zone'],
        'ref'          => $req['referral_code'] ?? null,
    ]]);
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
    // Nu tot personalul, ci numai cine răspunde de ce intră în laborator:
    // `GP_ORDER_CREATE_ROLES` — admin, manager, fondator. Contul comun al
    // tabletelor („panou”, rol angajat) se deschide cu un cod știut de toată
    // tura, iar cât poarta a fost `require_staff()` acceptarea trecea din
    // consolă: cererea devenea comandă pe altă zi decât cea cerută de client,
    // iar în jurnal autorul rămânea „Panou comun”, deci nu se putea afla cine
    // a apăsat.
    //
    // MANAGERUL A INTRAT AICI la 18 septembrie 2026, odată cu hotărârea că el
    // scrie comenzi de-a dreptul. Acceptarea ESTE nașterea unei comenzi, doar
    // pe celălalt drum — sunt singurele două din casă. Lăsată pe
    // `require_admin()`, poarta i-ar fi spus managerului „scrie tu una nouă în
    // loc s-o accepți pe asta”: aceeași comandă, aceeași zi, dar cererea
    // clientului rămâne neînchisă și legătura cerere → comandă se rupe.
    $u  = require_drept('comenzi.scrie');
    $b  = body();
    $id = (int) $id;

    $req = one('SELECT * FROM order_requests WHERE id = ?', [$id]);
    if (!$req) {
        fail('Cererea nu există.', 404, 'not_found');
    }
    if ($req['status'] !== 'nou') {
        fail('Cererea a fost deja ' . ($req['status'] === 'acceptata' ? 'acceptată' : 'respinsă') . '.', 409, 'already_handled');
    }

    // CINE A SCRIS CEREREA: omul casei care a luat telefonul, sau nimeni —
    // atunci a venit de pe site (migrarea 022). `?? null` fiindcă pe o bază
    // nemigrată `SELECT *` nu aduce coloana deloc, iar `$req['created_by']` ar
    // fi un indice nedefinit.
    //
    // Se citește O SINGURĂ DATĂ, aici, fiindcă răspunde la două întrebări
    // deosebite mai jos — pe ce canal s-a născut comanda și pe numele cui —,
    // iar două citiri ale aceleiași coloane se pot despărți la prima
    // schimbare.
    $autorCerere = ((int) ($req['created_by'] ?? 0)) ?: null;

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

    // Trei trepte, ca la interval și zonă deasupra: ce scrie administratorul
    // acum, altfel ce a ales cel care a scris cererea (migrarea 022), altfel
    // implicitul din Setări — nu din textul scris aici; v.
    // `default_fulfillment()`, api/lib/settings.php, și motivul, mai jos.
    //
    // Treapta din mijloc e nouă și e chiar rostul migrării: până la ea,
    // alegerea agentului nu ajungea nicăieri. Rămâne mijlocie dinadins —
    // administratorul care schimbă modul la acceptare are în continuare
    // ultimul cuvânt, fiindcă el vede ziua întreagă și mașina.
    $fulfillment = trim((string) ($b['fulfillment'] ?? ''))
        ?: trim((string) ($req['fulfillment'] ?? ''))
        ?: default_fulfillment();
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
    // Aceeași grijă ca la mențiuni, câteva zeci de rânduri mai jos: câmpul
    // TRIMIS are întâietate chiar și gol, fiindcă atunci administratorul a
    // văzut steagul în formular și l-a scos. Câmpul LIPSĂ e altceva — un panou
    // mai vechi, care nici nu-l trimite — și atunci rămâne ce a cerut cel care
    // a scris cererea (migrarea 022). Scris ca `!empty($b[...])` singur, cum a
    // fost până acum, graba cerută de agent se stingea tăcut la acceptare.
    $urgent = array_key_exists('urgent', $b) ? !empty($b['urgent']) : !empty($req['urgent']);
    $timed  = array_key_exists('timed', $b)  ? !empty($b['timed'])  : !empty($req['timed']);

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
    //
    // O SINGURĂ CĂUTARE, DOUĂ HOTĂRÂRI (runda 2.7.0). Întrebarea „la ce cont
    // duce numele ăsta?” se pune o dată, aici, iar răspunsul ei slujește și
    // legării de mai sus, și porții pragului de comenzi neplătite (v. „CONTUL
    // JUDECAT”, înaintea tranzacției). Se caută când numele a fost corectat —
    // ca până acum — și, nou, când cererea a venit FĂRĂ cont, chiar cu numele
    // neatins. În al doilea caz răspunsul NU leagă nimic: se ține minte numai
    // pentru judecata pragului.
    //
    // De ce nu două chemări, una pentru fiecare hotărâre: între ele contul
    // poate fi recunoscut ori dezactivat, iar atunci comanda s-ar lega după un
    // răspuns și s-ar judeca după altul. Și fiindcă drumurile pe care un nume
    // ajunge la un cont sunt numărate dinadins
    // (`.claude/test-defect-cont-uzurpat.mjs`, „tot patru drumuri cheamă
    // poarta”): acceptarea e UN drum, deci întreabă o dată.
    $customerId   = $req['customer_id'];
    $numeCorectat = $customerName !== (string) $req['customer_name'];
    $contDupaNume = ($numeCorectat || $customerId === null) ? client_by_name($customerName) : null;
    if ($numeCorectat && $contDupaNume !== null) {
        $customerId = $contDupaNume;
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
        $pozitii = cos_de_tarifat($b['items']);
        // Perechile deja pe comandă trec de poarta laturii; un rând NOU pe o
        // latură stinsă între timp, nu. Amândouă listele ies din aceeași hartă, deci
        // nu pot ieși din pas.
        $onorate = honored_prices($items);
        [$priced, $sum] = price_items(
            $pozitii,
            require_variant($b['variant'] ?? null, $implicit),
            $onorate,
            array_filter(array_column($items, 'product_id'), static fn($x) => $x !== null),
            array_keys($onorate)
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
            // Treapta, din aceeași socoteală — aceleași nume ca pe rândul
            // cererii, fiindcă scrierea de mai jos le citește de acolo când
            // administratorul nu corectează nimic.
            'treapta'      => $p['treapta'] ?? 1,
            'prag_treapta' => $p['prag'] ?? null,
        ], $priced);
    }

    $pdo     = db();
    $orderId = null;

    // Pentru poarta pragului de comenzi neplătite, dinăuntrul buclei (runda
    // 2.7.0). Toate se află AICI, înaintea tranzacției: înăuntrul ei, orice
    // citire fără lacăt făcută înaintea porții ar îngheța imaginea bazei — iar
    // `client_by_name()` e tocmai o citire fără lacăt (de aceea căutarea după
    // nume s-a făcut mai sus, o singură dată, și aici doar i se folosește
    // răspunsul).
    //
    //   · contul pe care se JUDECĂ pragul, ca număr, fiindcă `plati_poarta()`
    //     nu primește text;
    //   · de unde vine apăsarea: de sub anunțul de pe Telegram nu se poate
    //     trece peste prag, fiindcă acolo n-are unde să se arate confirmarea.
    //
    // CONTUL JUDECAT NU E MEREU CONTUL PE CARE SE SCRIE COMANDA. Când comanda se
    // naște cu cont — al cererii, ori cel găsit după numele corectat la
    // acceptare — cele două sunt unul și același. Când cererea a venit FĂRĂ
    // cont, se judecă contul la care duce NUMELE cu care se naște comanda, cu
    // aceeași recunoaștere ca la legare (`client_by_name()`: un singur cont de
    // client, activ, cunoscut casei, cu numele întocmai) — chiar aceeași
    // căutare, `$contDupaNume`.
    //
    // De ce (găsit la recitirea rundei, 5 octombrie 2026): legarea de mai sus
    // caută contul numai când numele a fost SCHIMBAT. Clientul oprit de prag
    // ieșea din cont, trimitea aceeași cerere de pe formularul public, cu numele
    // lui scris la fel, iar cererea venea fără cont (formularul public nu leagă
    // pe nimeni după un nume tastat: ar fi și oracol, și ușă de uzurpat).
    // Managerul o accepta fără să atingă numele, poarta primea „niciun cont” și
    // se dădea la o parte: a treia comandă neplătită a aceluiași om, născută
    // chiar pe ușa pe care pragul o păzește. Pragul se ocolea cu o delogare.
    //
    // Comanda NU se leagă de cont din pricina asta: `$customerId` rămâne gol,
    // ca până acum. Cine a completat formularul n-a dovedit că e clientul, iar o
    // comandă legată de un cont după un nume tastat de un necunoscut i-ar apărea
    // aceluia în „Comenzile mele” (paza „cont uzurpat”). Se judecă doar dacă
    // CASA mai dă marfă pe numele ăsta; a cui e comanda rămâne de hotărât pe
    // față, la acceptare (corectând numele) ori din fișa comenzii.
    //
    // Refuzul ajunge numai la oamenii casei care au „Comenzi · scrie” — aceiași
    // care primesc același refuz când scriu numele clientului într-o comandă
    // nouă (POST /orders). Nimic nu se întoarce spre vizitator: cererea lui a
    // fost primită cu 201 și stă pe „nou”, deci formularul nu spune nimănui cât
    // datorează firma al cărei nume l-a tastat.
    $pragPeNume   = $customerId === null && $contDupaNume !== null;
    $pragCont     = $customerId !== null ? (int) $customerId : $contDupaNume;
    $pragTelegram = requests_decided_via() === 'telegram';
    $pragOcolit   = null;

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

            // POARTA PRAGULUI DE COMENZI NEPLĂTITE (runda 2.7.0; hotărârea
            // patronului din 4–5 octombrie 2026).
            //
            // Stă DUPĂ lacătul cererii și ÎNAINTEA primei citiri fără lacăt
            // (`MAX(seq)`, chiar dedesubt): cele două citiri de deasupra sunt cu
            // `FOR UPDATE` și nu îngheață imaginea tranzacției, deci numărătoarea
            // de sub lacătul clientului vede și comanda scrisă adineauri din altă
            // filă. Ordinea lacătelor e cerere → client; cine ține lacătul unui
            // client nu cere niciodată rândul unei cereri vechi, deci cele două
            // nu se pot aștepta una pe alta.
            //
            // Cererea de acum iese din numărătoare (`'cerere' => $id`): ea e
            // chiar cea care devine comandă, iar numărată ar fi oprit orice
            // client aflat la un pas de prag să-și vadă acceptată cererea primită
            // deja. Înăuntrul buclei, ca o reluare după „Duplicate entry” să
            // numere din nou, pe tranzacția ei.
            //
            // Refuzul iese cu tranzacția dată înapoi: cererea rămâne „nou”, nu se
            // naște comandă, nu se rezervă marfă, nu pleacă niciun anunț.
            $pragOcolit = plati_poarta($pragCont, $u, $b, ['cerere' => (int) $id], $pragTelegram);

            $seq = (int) scalar('SELECT COALESCE(MAX(seq), 0) + 1 FROM orders WHERE day = ?', [$day]);
            q(
                // `variant` merge mai departe din cerere: laboratorul trebuie să știe
                // dacă marfa pleacă congelată sau coaptă, iar prețul
                // deja calculat corespunde acelei variante.
                'INSERT INTO orders
                   (seq, ref, customer_id, customer_name, phone, address, zone, day, slot,
                    fulfillment, channel, status, notes, total, request_id, created_by, variant,
                    urgent, timed)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $seq, '#' . (1000 + $seq), $customerId, $customerName,
                    $phone, $address, $zone ?: null, $day, $slot ?: null,
                    // CANALUL SPUNE DE UNDE A VENIT, și până la migrarea 022
                    // spunea „site" pentru toate — fiindcă toate cererile
                    // veneau de pe site. De când agentul scrie cereri din
                    // panou, „site" ar fi fost o minciună scrisă în bază, iar
                    // orice socoteală pe canale ar fi numărat munca lui la
                    // rubrica vizitatorilor. Cererea fără autor e, mai departe,
                    // chiar cea de pe site.
                    $fulfillment, $autorCerere !== null ? 'panou' : 'site', $first,
                    // Mențiunile trimise de administrator, chiar și goale:
                    // el a văzut nota clientului în formular și a ales să o
                    // șteargă. Cu „?: $req['notes']", golirea era ignorată
                    // și nota revenea pe comandă. Fără câmp deloc (un client
                    // vechi), rămâne nota cererii. Lungimea, ca la creare.
                    array_key_exists('notes', $b)
                        ? (text_field($b['notes'], 'Mențiunile', 2000) ?: null)
                        : $req['notes'],
                    // PE NUMELE CUI SE NAȘTE COMANDA: al celui care a scris
                    // cererea, dacă a scris-o cineva din casă; altfel al celui
                    // care a apăsat „Acceptă", ca până acum (cererea de pe site
                    // n-are alt autor). Agentul care a luat comanda la telefon
                    // rămâne astfel pe propria lui muncă — altminteri ar fi
                    // dispărut din ea, iar insigna „Creat de" ar fi arătat pe
                    // toate comenzile casei numele administratorului de
                    // serviciu.
                    //
                    // CINE A ÎNCUVIINȚAT nu se pierde: stă pe cerere, în
                    // `handled_by`, și în jurnal, pe fapta `request_accept`.
                    // Sunt două întrebări, și de-acum au două răspunsuri.
                    $total, $id, $autorCerere ?? (int) $u['id'], $variant,
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
            //
            // Treapta trece la fel, pe același drum: din rândul cererii când nu
            // se corectează nimic, din tarifarea de acum când se corectează. Un
            // rând de cerere de dinainte de 028 n-are treaptă, și nici n-avea ce
            // treaptă să aibă — rămâne NULL, adică A.
            [$sqlPozitie, $cuLista, $cuTrepte] = pozitie_insert(
                'order_items', 'order_id, product_id, name, variant, qty, unit_price'
            );
            foreach ($items as $it) {
                q($sqlPozitie, pozitie_valori(
                    [$orderId, $it['product_id'], $it['name'], $it['variant'] ?? 'copt', $it['qty'], $it['unit_price']],
                    $cuLista, $cuTrepte, $it['list_price'] ?? null, $it['treapta'] ?? null, $it['prag_treapta'] ?? null
                ));
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
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
    $order = attach_items([one('SELECT * FROM orders WHERE id = ?', [$orderId])], true, true, plati_fel($u))[0];

    // Trecerea peste prag lasă urmă în jurnal, după commit, cu comanda născută.
    //
    // Când pragul s-a judecat după nume (cerere fără cont), rândul o spune pe
    // față. Altfel, cine îl citește peste o lună caută comanda în „Încasări”,
    // la clientul acela, și n-o găsește: ea a rămas nelegată de cont.
    if ($pragOcolit !== null) {
        plati_jurnal_ocolire(
            $pragOcolit,
            'comanda ' . $order['ref'] . ', din cererea #' . (int) $id
                . ($pragPeNume ? ' (cerere fără cont, pe numele lui; comanda a rămas nelegată de cont)' : '')
        );
    }

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
    //
    // Oamenii din jurul comenzii se agață ABIA AICI, după răspuns, ca la
    // nașterea din panou: cine a scris cererea (nimeni, dacă a venit de pe
    // site — `created_by` gol, nu `channel`), cine a acceptat-o și pe unde.
    $order['anunt'] = ['autorId' => $autorCerere, 'dePeSite' => $autorCerere === null, 'acceptataDe' => (int) $u['id'], 'prin' => requests_decided_via(), 'aducatorId' => $order['commission']['referrer'] ?? null];
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
    // Ca la acceptare, și din același motiv: `GP_ORDER_CREATE_ROLES`. O
    // respingere trimisă din contul comun al tabletelor trecea cererea definitiv
    // pe „respinsa”, iar de acolo acceptarea nu mai e posibilă (409
    // „already_handled”): comanda clientului se pierdea, iar jurnalul îl trecea
    // pe autor tot ca „Panou comun”.
    //
    // Refuzul e ACEEAȘI autoritate ca încuviințarea, nu una mai mică: cine poate
    // spune „da” poate spune și „nu”. Despărțite, managerul ar fi putut accepta
    // orice și n-ar fi putut respinge nimic — iar o cerere pe care n-o vrea i-ar
    // fi rămas în față până venea un administrator s-o șteargă.
    $u  = require_drept('comenzi.scrie');
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
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
    // Ca la acceptare și respingere, pe aceeași listă: redeschiderea anulează o
    // hotărâre deja luată, deci cere aceeași autoritate ca ea și n-are ce căuta
    // în contul comun al tabletelor.
    $u  = require_drept('comenzi.scrie');
    // Corpul se citește numai pentru `ocolesteprag` (poarta pragului, mai jos).
    // Panoul trimite `{}`; un corp lipsă e tot „nimic”.
    $b  = body();
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

    // REDESCHIDEREA TRECE PRIN POARTA PRAGULUI DE COMENZI NEPLĂTITE (runda
    // 2.7.0; găsit la recitirea rundei, 5 octombrie 2026).
    //
    // O cerere respinsă nu intră în numărătoarea clientului; una „nou”, cu
    // produse, intră (`plati_situatie()`). Redeschiderea o mută deci din afara
    // numărătorii înăuntrul ei — exact ce face trimiterea unei cereri noi, pe
    // care poarta o păzește. Fără poartă aici, clientul ajuns la prag cât i-a
    // stat cererea respinsă trecea peste el printr-o apăsare a managerului
    // (care n-are dreptul să treacă peste prag și nici nu afla că o face), iar
    // două redeschideri apăsate deodată treceau amândouă pe ultimul loc liber.
    //
    // Se judecă întocmai ca o cerere nouă: numai cererea CU produse (una fără
    // produse e o întrebare, nu ține locul unei comenzi) și CU cont (cea fără
    // cont nu e în numărătoarea nimănui; pragul ei se judecă la acceptare, pe
    // numele cu care se naște comanda). Celelalte se redeschid ca până acum.
    //
    // „Are produse?” se află AICI, înaintea tranzacției: înăuntrul ei, o citire
    // fără lacăt făcută înaintea porții ar îngheța imaginea bazei, iar
    // numărătoarea de sub lacătul clientului n-ar mai vedea ce a scris cealaltă
    // filă. Răspunsul nu se poate învechi până la poartă: pozițiile unei cereri
    // se scriu o singură dată, la naștere, și nu le mai atinge nimeni.
    $cuProduse = scalar('SELECT 1 FROM order_request_items WHERE request_id = ? LIMIT 1', [$id]) !== null;

    $pdo        = db();
    $pragOcolit = null;
    $pdo->beginTransaction();
    try {
        // Rândul cererii, sub lacăt, și starea citită din nou: verificările de
        // deasupra s-au făcut în afara tranzacției. Lacătul ține și când doi
        // administratori apasă „Redeschide" în aceeași clipă — al doilea
        // așteaptă aici și găsește cererea deja „nou”; fără el ar readuce la
        // „nou" o cerere pe care primul o redeschisese și pe care cineva apucase
        // s-o accepte, adică tot a doua comandă din aceleași date, pe altă cale.
        //
        // Contul vine tot de aici, nu din citirea de sus: o cerere fără cont se
        // poate lega între timp de contul nou al celui care a trimis-o.
        $sub = one('SELECT status, order_id, customer_id FROM order_requests WHERE id = ? FOR UPDATE', [$id]);
        if (!$sub || $sub['status'] !== 'respinsa' || $sub['order_id'] !== null) {
            $pdo->rollBack();
            fail('Cererea nu mai este respinsă — altcineva a apucat s-o schimbe. Reîncarcă lista de cereri.', 409, 'already_handled');
        }

        // Ordinea lacătelor e cea de la acceptare: cerere → client. Între
        // `beginTransaction()` și poartă stă numai citirea cu lacăt de deasupra,
        // care nu îngheață imaginea. Cererea de față nu se scoate din
        // numărătoare: e încă „respinsa”, deci nu se numără pe sine.
        //
        // Refuzul iese cu tranzacția dată înapoi și are forma celorlalte porți
        // (409 `prag_neplatite`): cererea rămâne respinsă, cu motivul ei.
        if ($cuProduse && $sub['customer_id'] !== null) {
            $pragOcolit = plati_poarta((int) $sub['customer_id'], $u, $b);
        }

        // Condiția pe stare rămâne și sub lacăt: e a doua plasă, pentru ziua în
        // care altcineva ar scrie starea fără să ia rândul.
        $redeschisa = q(
            "UPDATE order_requests SET status = 'nou', reject_reason = NULL, handled_by = NULL, handled_at = NULL
              WHERE id = ? AND status = 'respinsa' AND order_id IS NULL",
            [$id]
        )->rowCount();
        if ($redeschisa === 0) {
            $pdo->rollBack();
            fail('Cererea nu mai este respinsă — altcineva a apucat s-o schimbe. Reîncarcă lista de cereri.', 409, 'already_handled');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Hotărârea s-a desfăcut, deci se șterge și urma ei: cererea e din nou în
    // așteptare, iar un „respinsă de pe telefon" rămas lipit de ea ar minți la
    // prima reclamație — adică tocmai la treaba pentru care există coloana.
    requests_mark_decided($id, null);

    // Trecerea peste prag lasă urmă în jurnal, după commit — v.
    // `plati_jurnal_ocolire()`.
    if ($pragOcolit !== null) {
        plati_jurnal_ocolire($pragOcolit, 'cererea #' . $id . ', redeschisă după un refuz');
    }

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

/**
 * Atașează pozițiile la cereri, printr-o singură interogare.
 *
 * @param bool $cuAutor Scoate și numele celui care a hotărât cererea. Steag,
 *   nu socoteală din rol, pentru aceeași pricină ca la `attach_items()`:
 *   pâlnia asta n-are de unde ști cine se uită.
 */
function attach_request_items(array $rows, bool $cuAutor = false): array
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
            // Treapta și pragul ei (migrarea 028), ca la pozițiile de comandă.
            'treapta'     => isset($it['treapta']) ? (int) $it['treapta'] : 1,
            'pragTreapta' => isset($it['prag_treapta']) ? (int) $it['prag_treapta'] : null,
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

    // Al câtelea client e omul de pe cerere. Aceeași socoteală ca la comenzi,
    // din același ajutor (`api/lib/orders.php`): două scrieri ale aceleiași
    // idei s-ar fi depărtat la prima schimbare, iar insigna ar fi spus altceva
    // în fila „Cereri de pe site" decât în registrul de comenzi.
    $primele = primele_comenzi(array_map(
        static fn($r) => $r['customer_id'] === null ? 0 : (int) $r['customer_id'],
        $rows
    ));

    // Cine a hotărât cererea — a primit-o sau a respins-o. `handled_by` se
    // scrie de amândouă hotărârile (`:905` și `:1058`) și se golește la
    // redeschidere (`:1137`): coloana era acolo din 001, întreagă, și n-o citea
    // nimeni.
    //
    // Pentru cererea RESPINSĂ ăsta e SINGURUL nume rămas. Din ea nu se naște
    // nicio comandă, deci nu există niciun `orders.created_by` care să-l poarte,
    // iar jurnalul scrie refuzul pe `entity = 'request'`. Fără rândul ăsta,
    // „cine a refuzat" nu se putea afla de nicăieri din panou.
    //
    // Același ajutor ca la comenzi, sub același steag: numele omului din casă
    // nu se arată cumpărătorului.
    // ȘI CINE A SCRIS-O, de la migrarea 022 încoace: cererea venită din panou
    // poartă autorul ei (`created_by`), cea de pe site nu poartă niciunul. Din
    // asta se vede în „Cereri" dacă hârtia a intrat prin agentul de vânzări sau
    // a picat de pe site — altfel toate arată la fel, iar administratorul n-are
    // de unde ști pe cine să întrebe.
    //
    // UN SINGUR LOT PENTRU AMÂNDOUĂ ÎNTREBĂRILE. Numele se caută o dată,
    // fiindcă `autorii_comenzilor()` curăță singură zerourile și repetările:
    // două loturi ar fi fost două drumuri la bază pe fiecare împrospătare a
    // panoului, adică la fiecare patru secunde, pentru două jumătăți din
    // aceeași listă de oameni.
    $numeDinCasa = $cuAutor
        ? autorii_comenzilor(array_merge(
            array_map(
                static fn($r) => isset($r['handled_by']) && $r['handled_by'] !== null ? (int) $r['handled_by'] : 0,
                $rows
            ),
            array_map(
                static fn($r) => isset($r['created_by']) && $r['created_by'] !== null ? (int) $r['created_by'] : 0,
                $rows
            )
        ))
        : [];

    return array_map(static function (array $r) use ($byReq, $adus, $primele, $cuAutor, $numeDinCasa) {
        $cod = $r['referral_code'] ?? null;
        $cod = is_string($cod) && $cod !== '' ? $cod : null;
        return [
            'id'           => (int) $r['id'],
            'customerId'   => $r['customer_id'] === null ? null : (int) $r['customer_id'],
            // Insigna de client. Cererea deja acceptată o ia pe cea a comenzii
            // născute din ea; cea încă neacceptată spune doar dacă omul a mai
            // comandat vreodată.
            'rangClient'   => rang_client(
                $r['customer_id'] === null ? null : (int) $r['customer_id'],
                $r['order_id'] === null ? null : (int) $r['order_id'],
                $primele
            ),
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
            'hotaratDe'    => $cuAutor
                ? autor_comanda(
                    isset($r['handled_by']) && $r['handled_by'] !== null ? (int) $r['handled_by'] : null,
                    $numeDinCasa
                )
                : null,
            // Cele patru ale migrării 022, cu aceeași grijă ca `referral_code`
            // de mai sus: pe o bază nemigrată `SELECT r.*` se întoarce fără
            // ele, deci `?? null` și `!empty()` țin lista întreagă până la
            // import. Cererea de pe site le are goale oricum — nimeni n-a ales
            // nimic pentru ea.
            'fulfillment'  => ($r['fulfillment'] ?? null) ?: null,
            'urgent'       => !empty($r['urgent']),
            'timed'        => !empty($r['timed']),
            'scrisDe'      => $cuAutor
                ? autor_comanda(
                    isset($r['created_by']) && $r['created_by'] !== null ? (int) $r['created_by'] : null,
                    $numeDinCasa
                )
                : null,
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
