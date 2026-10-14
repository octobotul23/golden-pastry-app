<?php
/** Jurnalul de modificări. Doar `admin`. */

declare(strict_types=1);

/** GET /log?search=&page=&action=&entity=&entityId= */
function route_log_list(): void
{
    $eu = require_drept('jurnal');

    $where  = [];
    $params = [];

    // FAPTELE PLĂȚILOR SE VĂD NUMAI CU „ÎNCASĂRI” (runda 2.7.0; hotărârea
    // patronului din 4–5 octombrie 2026). Jurnalul îl are, din pornire, orice
    // administrator; cine a plătit, cine a fost amânat și unde pleacă raportul
    // de bani sunt ale fondatorului, până le dă el altcuiva. Fără rândul de mai
    // jos, tot ce ascunde pagina „Încasări” s-ar fi citit de aici, rând cu rând.
    //
    // CONDIȚIA STĂ ÎN `$where`, NU ÎNTR-O CERNERE DE DUPĂ. Din același `$where`
    // se scriu și numărătoarea, și pagina de rânduri, deci tac toate deodată:
    // lista, `total`, `pages`, căutarea (care lovește și `l.action`, și
    // `l.detail`), filtrul `?action=plata_schimba` și istoria unei comenzi
    // (`?entity=order`). Cernute după citire, `total` ar fi numărat și
    // rândurile ascunse — „47 de rânduri”, din care se văd 45 — iar o căutare
    // după numele unui client ar fi spus, din cifră, că i s-a umblat la plată.
    //
    // `prag_ocolit` rămâne la vedere pentru oricine are Jurnalul: trecerea peste
    // prag e o faptă a casei care trebuie să poată fi întrebată, nu o cifră.
    if (!drept('incasari', $eu)) {
        $where[] = 'l.action NOT IN ('
            . implode(', ', array_fill(0, count(GP_PLATI_FAPTE_ASCUNSE), '?')) . ')';
        array_push($params, ...GP_PLATI_FAPTE_ASCUNSE);
    }

    $search = trim((string) ($_GET['search'] ?? ''));
    if ($search !== '') {
        // Numele se caută și prin legătura spre `users`, nu numai în ce s-a
        // scris atunci în jurnal: rândurile mai vechi de reparația din
        // `audit()` au `user_label` gol, iar fără COALESCE o căutare după
        // numele omului le arunca din numărătoare cu totul.
        $where[] = '(COALESCE(l.user_label, u.name) LIKE ? OR l.action LIKE ? OR l.detail LIKE ?)';
        $like    = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $action = trim((string) ($_GET['action'] ?? ''));
    if ($action !== '') {
        $where[]  = 'l.action = ?';
        $params[] = $action;
    }

    // ISTORIA UNUI SINGUR LUCRU. Pagina Jurnal deschide un rând și cere tot ce
    // s-a mai scris despre același lucru. Filtrul e pe CHEIE, nu pe text:
    // căutarea de mai sus lucrează cu `LIKE`, iar „#101" s-ar potrivi și cu
    // „#1010" — istoria unei comenzi ar căpăta rândurile alteia.
    $entity   = trim((string) ($_GET['entity'] ?? ''));
    $entityId = trim((string) ($_GET['entityId'] ?? ''));
    $subiect  = null;
    // Numărul comenzii ȘTERSE în care se prefăcuse cererea a cărei istorie s-a
    // cerut — și numai el. Rămâne `null` pentru orice altceva: istoria unei
    // comenzi, a unui cont, a unei cereri cu comanda vie ori fără comandă.
    // Numele lui e al lui DINADINS: numărul unei comenzi VII (`$comanda`, mai
    // jos) n-are voie să ajungă aici, fiindcă de el atârnă poza de pe rând.
    $stearsaCererii = null;

    if ($entity !== '' && $entityId !== '') {
        // CAPCANA, închisă aici o dată pentru toate paginile care ar mai cere
        // vreodată istoria unei comenzi.
        //
        // O comandă NĂSCUTĂ DINTR-O CERERE acceptată n-are niciun rând
        // `order_create`: acceptarea are INSERT propriu și își scrie nașterea
        // pe CERERE — `audit('request_accept', 'request', …)`, api/routes/
        // requests.php. Cine ar cere numai `order:<id>` ar primi o comandă
        // fără naștere, ca și cum ar fi răsărit din senin; și tocmai comenzile
        // venite de pe site sunt cele despre care se întreabă mai des.
        //
        // Legătura dintre cele două e în bază (`orders.request_id`), deci se
        // urmează — în AMÂNDOUĂ sensurile: dintr-o comandă se vede de unde
        // vine, dintr-o cerere se vede ce s-a ales de ea. Nimic nu se
        // amestecă pe nevăzute: fiecare rând întors își spune singur entitatea
        // și numărul, deci pagina poate arăta pe față de unde vine.
        $perechi = [[$entity, $entityId]];
        if (ctype_digit($entityId)) {
            if ($entity === 'order') {
                $cerere = scalar('SELECT request_id FROM orders WHERE id = ?', [(int) $entityId]);
                // COMANDA ȘTEARSĂ ÎȘI URMEAZĂ CEREREA MAI DEPARTE (runda 2.7.0).
                // Rândul ei nu mai e în `orders`, deci legătura de deasupra iese
                // goală tocmai când omul a deschis fereastra ca să afle ce a
                // fost comanda — și ar fi găsit-o fără naștere. Legătura se ia
                // atunci din arhivă, unde ștergerea a copiat-o lângă poză.
                if ($cerere === null) {
                    $cerere = log_comanda_stearsa((int) $entityId)['request_id'] ?? null;
                }
                if ($cerere !== null) {
                    $perechi[] = ['request', (string) $cerere];
                }
            } elseif ($entity === 'request') {
                // `LIMIT 1`: o cerere naște o singură comandă, dar coloana
                // n-are `UNIQUE`, deci nu se presupune — se ia prima.
                $comanda = scalar(
                    'SELECT id FROM orders WHERE request_id = ? ORDER BY id LIMIT 1',
                    [(int) $entityId]
                );
                // ȘI DINSPRE CERERE SE VEDE COMANDA ȘTEARSĂ (runda 2.7.0,
                // reparația din 5 oct. 2026). Ștergerea golește legătura din
                // `order_requests` și scoate rândul din `orders`, deci căutarea
                // de deasupra iese goală tocmai pentru cererea despre care e
                // cel mai mult de aflat: fereastra ei pierdea anularea și
                // ștergerea comenzii în care se prefăcuse — „ce s-a ales de
                // ea" rămânea fără răspuns, iar sensul celălalt (din comandă
                // spre cerere) mergea. Legătura se ia atunci din arhivă.
                if ($comanda === null) {
                    $stearsaCererii = log_comanda_stearsa_a_cererii((int) $entityId);
                    $comanda        = $stearsaCererii;
                }
                if ($comanda !== null) {
                    $perechi[] = ['order', (string) $comanda];
                }
            }
        }

        $where[] = '(' . implode(' OR ', array_fill(
            0,
            count($perechi),
            '(l.entity = ? AND l.entity_id = ?)'
        )) . ')';
        foreach ($perechi as [$e, $i]) {
            array_push($params, $e, $i);
        }

        $subiect = log_subiect($entity, $entityId);
    }

    $perPage = 50;
    // Plafonat: `?page=99999999999999999999` dădea (int) PHP_INT_MAX, iar
    // înmulțirea trecea în float — „OFFSET 4.6E+20" e eroare de sintaxă SQL,
    // deci 500 pentru un număr de pagină. Peste un milion de pagini nu are
    // jurnalul, și oricum răspunsul e gol.
    $page    = min(1000000, max(1, (int) ($_GET['page'] ?? 1)));
    $offset  = ($page - 1) * $perPage;

    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    // Aceeași legătură ca la citire: `$clause` poate cuprinde `u.name`, iar
    // o numărătoare fără JOIN ar pica pe o coloană necunoscută.
    $total  = (int) scalar(
        "SELECT COUNT(*) FROM audit_log l LEFT JOIN users u ON u.id = l.user_id$clause",
        $params
    );

    // LIMIT/OFFSET se interpolează ca întregi validați: MySQL nu acceptă
    // parametri legați în această poziție.
    // Rolul vine din tabelul de conturi, prin legătură: în jurnal se păstrează
    // doar numele, ca înregistrarea să rămână lizibilă după ștergerea contului.
    // Fără el, coloana „Cont" din pagină arăta o liniuță la fiecare rând.
    $rows = all(
        "SELECT l.*, u.role, u.name AS user_name
           FROM audit_log l
           LEFT JOIN users u ON u.id = l.user_id
         $clause ORDER BY l.id DESC LIMIT $perPage OFFSET $offset",
        $params
    );

    // POZA COMENZII ȘTERSE, PE RÂNDUL ȘTERGERII EI (9 oct. 2026).
    //
    // Fereastra unei CERERI n-are de unde afla ce era în comanda în care se
    // prefăcuse: `subject` e al cererii (numele ei, comanda ei VIE), iar poza
    // stă în arhivă, pe numărul comenzii. Pagina o caută de aceea pe rândul
    // `order_delete` din istorie (`h.sters`) — și până azi n-o găsea: omul
    // vedea „comandă ștearsă” și atât, deși venise tocmai ca să afle ce s-a
    // ales de cerere și ce era în ea.
    //
    // Poza pleacă O SINGURĂ DATĂ, pe rândul ștergerii, nu pe fiecare rând al
    // comenzii: e aceeași la toate, iar o comandă de patruzeci de poziții
    // repetată pe cincizeci de rânduri ar fi umflat răspunsul degeaba.
    //
    // Se ia prin `log_comanda_stearsa()`, nu printr-o citire nouă a arhivei:
    // acolo stau paza lui 035 și `NOT EXISTS`-ul care nu lasă un număr purtat
    // azi de o comandă vie să arate poza unei străine. Și trece prin
    // `log_poza_comenzii()`, aceeași listă scrisă cu mâna ca la `subject.sters`
    // — fără telefon, fără adresă, fără bani. `null` când poza nu se mai poate
    // citi: fereastra arată atunci ce arăta și până acum.
    $arhivaCererii = $stearsaCererii === null ? null : log_comanda_stearsa($stearsaCererii);
    $pozaCererii   = $arhivaCererii === null ? null : log_poza_comenzii($arhivaCererii);

    json_out([
        'entries' => array_map(static fn($r) => [
            'id'        => (int) $r['id'],
            // Numele din jurnal, iar dacă lipsește (rând scris înainte de
            // reparația din `audit()`), cel de pe contul legat. Numai când nu
            // există nici contul rămâne gol, iar pagina scrie „Sistem" —
            // atunci chiar e adevărat.
            'user'      => $r['user_label'] ?? $r['user_name'],
            'role'      => log_rol_randului($r),
            'action'    => $r['action'],
            'entity'    => $r['entity'],
            'entityId'  => $r['entity_id'],
            'detail'    => $r['detail'],
            'ip'        => $r['ip'],
            'createdAt' => $r['created_at'],
        ] + (
            $stearsaCererii !== null
            && $r['action'] === 'order_delete'
            && $r['entity'] === 'order'
            && (string) $r['entity_id'] === (string) $stearsaCererii
                ? ['sters' => $pozaCererii]
                : []
        ), $rows),
        'total'   => $total,
        'page'    => $page,
        'pages'   => (int) ceil($total / $perPage),
        // Numai când s-a cerut istoria unui lucru anume; altfel `null`.
        'subject' => $subiect,
    ]);
}

/**
 * Rolul de trimis lângă „Cine”, pe un rând din Jurnal.
 *
 * Fapta unui om din grup cu contul NELEGAT stă pe contul care a legat botul —
 * pe drepturile lui s-a lucrat —, deci `users.role` al rândului e rangul
 * aceluia, nu al omului. Trimis cum e, răspunsul spunea „fondator” lângă
 * „Ion Pop (Telegram, cont nelegat)”: pagina îl ascundea (`rolulRandului`,
 * Jurnal.dc.html), dar orice altă pagină ori export scris pe aceeași rută l-ar
 * fi arătat iar (fișa 655, 2.7.5). De-acum serverul nu-l mai trimite: `null`,
 * ca la „Sistem”.
 *
 * Omul se recunoaște după coada pusă de `jurnal_eticheta()` (api/lib/auth.php),
 * literă cu literă; .claude/check-telegram-apasator.mjs ține cele trei locuri
 * — eticheta, funcția asta și pagina — la aceeași vorbă.
 */
function log_rol_randului(array $r): ?string
{
    $cine = $r['user_label'] ?? $r['user_name'] ?? null;
    if (is_string($cine) && str_ends_with($cine, ' (Telegram, cont nelegat)')) {
        return null;
    }
    return $r['role'] ?? null;
}

/**
 * Ce ESTE lucrul despre care s-a cerut istoria.
 *
 * Jurnalul ține numai numărul, fiindcă el trebuie să rămână citibil și după ce
 * lucrul a fost șters. Fereastra din pagină are însă nevoie de un titlu care
 * spune ceva unui om — „#1010, Cafeneaua Lumina" —, iar butonul care duce
 * acolo are nevoie de cheia cu care pagina-țintă își găsește lucrul.
 *
 * DOUĂ CHEI DEOSEBITE, DINADINS:
 *
 *   · `ref` e UN SINGUR lucru: referința comenzii spre care duce butonul
 *     „vezi comanda", fiindcă panoul de comenzi caută pe server după `o.ref`
 *     (`route_orders_list`, parametrul `q`). Pentru o comandă e referința ei;
 *     pentru o CERERE e a comenzii în care s-a prefăcut — asta vrea să afle
 *     omul care deschide un rând de cerere: ce s-a ales de ea. O cerere încă
 *     nehotărâtă, sau respinsă, n-are niciuna, deci nu capătă buton.
 *     „#1010" nu spune nimic despre nimeni, deci poate merge într-o adresă.
 *   · `label` e numele clientului sau al omului, deci DATĂ PERSONALĂ: se
 *     arată pe ecran, în fereastra administratorului, și n-are voie să intre
 *     într-o adresă web. Regula casei, scrisă și în docs/03.
 *
 * `exists: false` e un răspuns, nu o lipsă: lucrul a fost șters, iar rândurile
 * din jurnal sunt tot ce a mai rămas din el. Pagina spune asta pe față în loc
 * să arate un buton care duce în gol.
 */
function log_subiect(string $entity, string $id): ?array
{
    if (!ctype_digit($id)) {
        return null;
    }
    $n = (int) $id;

    $r = null;
    if ($entity === 'order') {
        $r = one('SELECT ref, customer_name AS label FROM orders WHERE id = ?', [$n]);
        // COMANDA ȘTEARSĂ (runda 2.7.0, cererea patronului din 4 oct. 2026:
        // „dacă văd ștergerea, să pot vedea ce era comanda"). Nu mai e în
        // `orders`, dar poza ei e în arhivă — și numai aici, pe ușa Jurnalului,
        // se dă mai departe.
        //
        // `ref` rămâne `null`, DINADINS. Numerotarea e pe zi, iar ștergerea
        // eliberează numărul: o comandă nouă din aceeași zi îl poate lua. Cu
        // referința dată aici, butonul „Vezi comanda #1012" ar duce la ALTĂ
        // comandă — a altui client. Referința veche stă în `sters.ref`, ca text
        // de citit, nu ca legătură de urmat.
        //
        // `sters` e `null` când poza nu se mai poate citi (v.
        // `log_poza_comenzii()`): fereastra arată atunci rândul ei obișnuit
        // pentru un lucru șters, nu o eroare.
        if ($r === null) {
            $arhiva = log_comanda_stearsa($n);
            if ($arhiva !== null) {
                return [
                    'entity' => $entity,
                    'id'     => $n,
                    'exists' => false,
                    'ref'    => null,
                    'label'  => $arhiva['customer_name'],
                    'sters'  => log_poza_comenzii($arhiva),
                ];
            }
        }
    } elseif ($entity === 'request') {
        $r = one(
            'SELECT (SELECT o.ref FROM orders o WHERE o.request_id = c.id ORDER BY o.id LIMIT 1) AS ref,
                    c.customer_name AS label
               FROM order_requests c WHERE c.id = ?',
            [$n]
        );
    } elseif ($entity === 'user') {
        $r = one('SELECT NULL AS ref, name AS label FROM users WHERE id = ?', [$n]);
    } else {
        return null;
    }

    return [
        'entity' => $entity,
        'id'     => $n,
        'exists' => $r !== null,
        'ref'    => $r['ref'] ?? null,
        'label'  => $r['label'] ?? null,
    ];
}

/* ------------------------------------------------- comanda ștearsă -- */

/**
 * Rândul din arhivă al unei comenzi șterse — sau `null`.
 *
 * `null` are trei pricini, toate „mergi mai departe ca ieri":
 *   · baza n-are migrarea 035 — și atunci NICIO interogare nu pleacă spre
 *     `orders_deleted`: Jurnalul e pagina la care se vine când ceva nu merge,
 *     iar un „Table doesn't exist" aici ar fi stricat-o tocmai pe serverul pe
 *     care fișierele au urcat înaintea importului;
 *   · comanda n-a fost ștearsă niciodată (ori n-a existat);
 *   · comanda EXISTĂ. Pe un MySQL vechi ori pe un MariaDB numărătoarea din
 *     `orders` pornește de la capăt după o repornire, dacă ultimele rânduri au
 *     fost șterse, iar o comandă nouă poate lua numărul uneia șterse ieri.
 *     Atunci cea vie câștigă: fereastra e a ei, nu a pozei unei străine.
 *     (`NOT EXISTS`, în aceeași interogare, ca să nu fie două drumuri la bază.)
 *
 * Ținut minte pe cerere: aceeași deschidere de rând întreabă de două ori —
 * o dată pentru legătura spre cerere, o dată pentru lămurirea lucrului.
 */
function log_comanda_stearsa(int $id): ?array
{
    static $tinute = [];
    if (array_key_exists($id, $tinute)) {
        return $tinute[$id];
    }
    if (!comenzi_sterse_supported()) {
        return $tinute[$id] = null;
    }
    return $tinute[$id] = one(
        'SELECT d.order_id, d.customer_name, d.request_id, d.snapshot,
                d.deleted_by_label, d.deleted_at
           FROM orders_deleted d
          WHERE d.order_id = ?
            AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.id = d.order_id)',
        [$id]
    );
}

/**
 * Numărul comenzii ȘTERSE în care se prefăcuse o cerere de pe site — sau `null`.
 *
 * Perechea lui `log_comanda_stearsa()`, pentru drumul celălalt: acolo se
 * pleacă de la comandă și se află cererea, aici se pleacă de la cerere. Se
 * întreabă numai după ce `orders` n-a avut nicio comandă cu cererea asta.
 *
 * Aceleași trei paze ca acolo:
 *   · fără migrarea 035 nu pleacă nicio interogare spre `orders_deleted`;
 *   · o cerere a cărei comandă n-a fost ștearsă (ori care n-a avut niciuna) dă
 *     `null`, iar fereastra arată ce arăta și ieri;
 *   · `NOT EXISTS`: dacă numărul din arhivă e purtat azi de o comandă VIE (un
 *     MySQL vechi care și-a luat numărătoarea de la capăt), rândurile ei din
 *     Jurnal sunt ale altui client și n-au ce căuta în istoria cererii ăsteia.
 *
 * `ORDER BY d.id DESC LIMIT 1`: o cerere naște o singură comandă (după
 * ștergere rămâne „acceptata" și nu se mai poate accepta a doua oară), dar
 * coloana n-are `UNIQUE`, deci nu se presupune — se ia ultima ștearsă.
 *
 * Căutarea merge pe cheia `ix_orders_deleted_request (request_id)`, pusă în
 * migrarea 035 din 9 oct. 2026. O bază care a importat 035 ÎNAINTEA zilei
 * aceleia are tabelul fără ea (`CREATE TABLE IF NOT EXISTS` nu mai adaugă
 * nimic unui tabel care există): acolo întrebarea citește tot tabelul până se
 * dă cu mâna comanda `ALTER TABLE` din foaia rundei. Răspunsul e același, doar
 * mai încet — iar tabelul crește numai cu ștergerile făcute de mână.
 */
function log_comanda_stearsa_a_cererii(int $cerere): ?int
{
    if (!comenzi_sterse_supported()) {
        return null;
    }
    $nr = scalar(
        'SELECT d.order_id
           FROM orders_deleted d
          WHERE d.request_id = ?
            AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.id = d.order_id)
          ORDER BY d.id DESC LIMIT 1',
        [$cerere]
    );
    return $nr === null ? null : (int) $nr;
}

/**
 * Ce era într-o comandă ștearsă, scos din poza ei, în forma pe care o citește
 * fereastra din Jurnal (`subject.sters`).
 *
 * NUMAI ATÂT IESE PE UȘA ASTA, deși poza ține rândurile întregi: referința,
 * clientul, ziua și ora, canalul, nota, pozițiile cu prețul lor, totalul, cine
 * a scris-o, cine a șters-o și când. Lista e scrisă cu mâna DINADINS — spre
 * deosebire de poză, unde rândurile intră întregi tocmai ca să nu fie o listă:
 *
 *   · FĂRĂ telefon și FĂRĂ adresă. Jurnalul e al celui cu panoul „Jurnal", nu
 *     al celui cu panoul „Comenzi"; datele de contact ale clientului n-au ce
 *     căuta într-o fereastră de istorie.
 *   · FĂRĂ plată și FĂRĂ comision. Banii au porțile lor (`incasari`,
 *     `recomandari.bani`); scoși pe aici, cine are numai „Jurnal" ar fi aflat
 *     cât i s-a plătit unui agent. Rămân în poză, în bază.
 *
 * O coloană nouă în `orders` intră deci singură în poză, dar NU iese singură
 * pe ecran: cine o vrea arătată o adaugă aici, cu bună știință.
 *
 * POZA PE CARE N-O MAI ÎNȚELEG NU SE GHICEȘTE. Altă versiune a formei (`v`),
 * text care nu e JSON, o cheie de temelie lipsă, o poziție fără cantitate ori
 * fără preț → `null`, iar fereastra spune ce spunea și până acum despre un
 * lucru șters. O jumătate de comandă arătată ca și cum ar fi întreagă ar fi
 * mai rea decât nimic: omul vine aici tocmai ca să afle cât era și ce era.
 *
 * `pret`, `suma` și `total` pleacă drept NUMERE, nu șiruri: din bază vin ca
 * text („4.50"), iar pagina le-ar fi lipit în loc să le adune.
 *
 * @param array<string, mixed> $arhiva  rândul din `orders_deleted` (v. `log_comanda_stearsa()`)
 */
function log_poza_comenzii(array $arhiva): ?array
{
    $p = json_decode((string) ($arhiva['snapshot'] ?? ''), true);
    if (!is_array($p) || ($p['v'] ?? null) !== GP_POZA_COMANDA_V) {
        return null;
    }
    $c       = $p['comanda'] ?? null;
    $pozitii = $p['pozitii'] ?? null;
    if (!is_array($c) || !is_array($pozitii)) {
        return null;
    }

    // Un câmp de text — ori `null` când lipsește, e gol, sau nu e text deloc.
    $text = static function ($v): ?string {
        $s = is_scalar($v) ? trim((string) $v) : '';
        return $s === '' ? null : $s;
    };

    $zi = $text($c['day'] ?? null);
    if ($zi === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $zi)) {
        return null;
    }

    $randuri = [];
    $adunat  = 0.0;
    foreach ($pozitii as $it) {
        if (!is_array($it) || !is_numeric($it['qty'] ?? null) || !is_numeric($it['unit_price'] ?? null)) {
            return null;
        }
        $cat  = (float) $it['qty'];
        $pret = round((float) $it['unit_price'], 2);
        $suma = round($cat * $pret, 2);
        // UN NUMĂR CARE NU ÎNCAPE NU E UN NUMĂR (reparația din 5 oct. 2026).
        // `is_numeric()` spune „da" și pentru „1e999" ori pentru patru sute de
        // cifre la rând, dar `(float)` face din ele INF — iar două numere mari
        // și cinstite înmulțite dau tot INF, ori NAN dacă unul e zero.
        // `json_encode()` nu le poate scrie, așa că TOATĂ fereastra Jurnalului
        // pica în `500 encode_error` pentru o singură poziție stricată dintr-o
        // poză. O poză pe care n-o înțeleg nu se ghicește: `null`, ca mai sus.
        if (!is_finite($cat) || !is_finite($pret) || !is_finite($suma)) {
            return null;
        }
        $adunat += $suma;
        $randuri[] = [
            'nume'      => $text($it['name'] ?? null) ?? '',
            // Rândurile de dinaintea variantei pe poziție n-au cheia: sunt
            // „copt", ca în `attach_items()`.
            'varianta'  => $text($it['variant'] ?? null) ?? 'copt',
            'cantitate' => $cat,
            'pret'      => $pret,
            'suma'      => $suma,
        ];
    }

    $stearsaDe = is_array($p['stearsaDe'] ?? null) ? $text($p['stearsaDe']['nume'] ?? null) : null;

    // Totalul scris pe comandă, nu suma pozițiilor: așa îl vedea omul în
    // registru. Suma e numai plasa pentru o poză fără el. Aceeași pază ca la
    // poziții: un total care nu încape într-un număr (ori o sumă de poziții
    // cinstite care, adunate, dau INF) nu se scrie — `null`, nu 500.
    $total = is_numeric($c['total'] ?? null) ? round((float) $c['total'], 2) : round($adunat, 2);
    if (!is_finite($total)) {
        return null;
    }

    return [
        // Referința cum o arăta registrul (`attach_items()`): a ei, ori „#" +
        // (1000 + numărul zilei) când era goală.
        'ref'       => $text($c['ref'] ?? null) ?? ('#' . (1000 + (int) ($c['seq'] ?? 0))),
        'client'    => $text($c['customer_name'] ?? null),
        'zi'        => $zi,
        'ora'       => $text($c['slot'] ?? null),
        'canal'     => $text($c['channel'] ?? null),
        'nota'      => $text($c['notes'] ?? null),
        'pozitii'   => $randuri,
        'total'     => $total,
        'scrisaDe'  => $text($p['scrisaDe'] ?? null),
        // Din poză; iar dacă acolo lipsesc, din coloanele de lângă ea — au fost
        // scrise în aceeași clipă, de aceeași mână.
        'stearsaDe' => $stearsaDe ?? $text($arhiva['deleted_by_label'] ?? null),
        'stearsaLa' => $text($p['stearsaLa'] ?? null) ?? $text($arhiva['deleted_at'] ?? null),
    ];
}
