<?php
/** Comenzi: creare de pe site, listare în panouri, schimbare de status. */

declare(strict_types=1);

// Etapele nu mai sunt fixe în cod: se citesc din Setări cu order_statuses().

/* -------------------------------------------------------------- listare -- */

/**
 * GET /orders?day=YYYY-MM-DD&status=...&from=&to=
 *
 * Personalul vede toate comenzile — afară de agentul de vânzări, care vede
 * numai ce a adus el; un client vede exclusiv comenzile lui. Filtrul se aplică
 * pe server, nu în interfață.
 */
/**
 * Cine ce comenzi are voie să vadă, ca bucată de SQL.
 *
 * Stă într-o funcție fiindcă e chemată din două locuri: lista de comenzi și
 * numărătoarea pe zile care hrănește calendarul din laborator. Scrisă de două
 * ori, s-ar fi despărțit la prima corectură — iar despărțirea aici n-ar ieși
 * ca o eroare, ci ca un calendar care spune „5 comenzi” într-o zi care se
 * deschide cu patru. Cifra de pe calendar trebuie să fie exact numărul de
 * rânduri pe care le vede același om când intră în ziua aceea.
 *
 * Coloanele se dau ca prefix, fiindcă lista folosește alias-ul `o.` iar
 * numărătoarea n-are nevoie de niciun alias.
 */
// Cine e primit în registru — și de ce întrebarea stă deoparte de filtrul de
// mai jos — e scris o singură dată, la `orders_readable()` (api/lib/auth.php),
// fiindcă marginea asta o cere și `/requests`.
function orders_visibility_filter(array $u, string $prefix = 'o.'): array
{
    if ($u['role'] === 'client') {
        return [$prefix . 'customer_id = ?', [(int) $u['id']]];
    }
    // Cine n-are registrul deschis nu vede niciun rând — și se întreabă
    // ÎNAINTEA ramurii agentului, nu după ea (migrarea 033): altfel un agent
    // căruia fondatorul i-a luat „Comenzi" din „Acces" și-ar fi văzut mai
    // departe felia, prin API. `1 = 0` e „niciun rând", nu „eroare": lista iese
    // goală, numărătoarea iese zero, iar o comandă anume iese „nu există" —
    // adică răspunsul nu spune nici măcar ce numere are casa.
    if (!orders_readable($u)) {
        return ['1 = 0', []];
    }
    if ($u['role'] === 'agent_vanzari') {
        // Agentul e personal, dar comenzile casei nu sunt ale lui: vede numai
        // cumpărătorii aduși de el și comenzile care îi poartă codul.
        // Regula stă într-un singur loc — vezi referral_agent_scope().
        $sc = referral_agent_scope((int) $u['id'], $prefix . 'customer_id', $prefix . 'referral_code');
        return [$sc['sql'], $sc['params']];
    }
    // A rămas personalul cu Comenzi, Laborator ori Rută: le vede pe toate.
    return ['', []];
}

function route_orders_list(): void
{
    $u = require_auth();

    $where  = [];
    $params = [];

    [$vSql, $vParams] = orders_visibility_filter($u);
    if ($vSql !== '') {
        $where[] = $vSql;
        $params  = array_merge($params, $vParams);
    }

    // „COMENZILE SCRISE DE MINE” — `?mine=1`, istoricul celor din casă.
    //
    // Hotărârea patronului din 4–5 octombrie 2026 (runda 2.7.0): fiecare cont
    // are o pagină cu istoricul comenzilor lui. Clientul îl are de mult — ruta
    // îi dă oricum numai comenzile lui, de aceea parametrul nu-l atinge. Nou e
    // istoricul celor care SCRIU comenzi: agent, manager, administrator,
    // fondator.
    //
    // NUMAI ÎNGUSTEAZĂ, NU LĂRGEȘTE NICIODATĂ. Stă după marginile lui
    // `orders_visibility_filter()` și se leagă de ele cu AND: registrul închis
    // rămâne „niciun rând”, iar agentul rămâne în felia lui. O comandă scrisă
    // de el pentru un cumpărător care între timp nu mai e al lui (codul scos de
    // pe comandă, contul trecut la alt agent) iese deci și din istoric, cum a
    // ieșit din Comenzi. Pusă în locul marginilor, condiția ar fi făcut din
    // istoric ușa pe care agentul mai citește numele, telefonul și adresa unui
    // client care nu mai e al lui.
    //
    // DE CE ȘI CANALUL, NU NUMAI AUTORUL. `created_by` spune „cine a scris”,
    // dar la acceptarea unei cereri venite de pe site îl primește cel care a
    // apăsat „Acceptă” — din panou ori de sub anunțul din Telegram, e aceeași
    // rută (`route_requests_accept()`, api/routes/requests.php). Numai cu
    // autorul, omul care acceptă cererile ar „deține” în istoricul lui toate
    // comenzile clienților, iar „Comandă din nou” i-ar copia comenzi pe care
    // nu le-a scris niciodată. Canalul le desparte: comanda născută dintr-o
    // cerere de pe site poartă `site`; cea scrisă din formular poartă `panou`;
    // cea născută din cererea unui om al casei tot `panou`, pe numele LUI,
    // oricine ar fi acceptat-o. „A mea” înseamnă: am scris-o eu, cu mâna mea.
    //
    // PREȚUL REGULII SIMPLE. `POST /orders` trimis fără niciun canal cade pe
    // `site` (`route_orders_create()`, mai jos), deci o asemenea comandă NU
    // intră în istoricul celui care a scris-o. Formularul din Comenzi trimite
    // mereu `panou`; fără canal ajunge aici numai o cerere scrisă de mână, pe
    // API.
    //
    // NUMAI ȘIRUL „1” ÎL APRINDE, ca `sort=urgent` mai jos: se cere pe față.
    // Comparat fără `(string)`, dinadins — `?mine[]=1` sosește ca tablou, iar
    // un tablou trecut prin `(string)` ar fi scris un avertisment în jurnalul
    // serverului la fiecare cerere. Orice nu e chiar „1” (lipsă, gol, `0`,
    // `true`, tablou) e „fără”, adică lista de până acum.
    //
    // FĂRĂ POARTĂ DE MIGRARE, fiindcă n-are ce păzi: `created_by` și `channel`
    // sunt amândouă din 001, odată cu tabelul. `channel` e NOT NULL, deci `<>`
    // nu pierde niciun rând pe un NULL; `created_by` gol (comanda fără autor)
    // nu e egal cu nimeni, cum se și cuvine.
    //
    // STĂ AICI, înaintea numărătorii de mai jos, și își pune parametrul în
    // același loc cu condiția: `total`, `pages` și `truncated` ies de la sine
    // pe aceleași rânduri ca lista. Parametrii se leagă în ordinea din text —
    // unul pus în alt loc decât condiția lui i-ar fi mutat pe toți cei de după.
    if ($u['role'] !== 'client' && ($_GET['mine'] ?? null) === '1') {
        $where[]  = "(o.created_by = ? AND o.channel <> 'site')";
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

    // Plafonul, o singură valoare cu nume, fiindcă se folosește de două ori:
    // în interogare și în steagul de mai jos. Scrise separat, s-ar fi depărtat
    // una de alta la prima corectură, iar steagul ar fi început să mintă.
    //
    // `?limit=` e pentru cine vrea mai puțin, nu pentru cine vrea mai mult:
    // se taie la plafon, deci o cerere scrisă de mână nu poate trage tot
    // tabelul într-un singur răspuns. Zero sau scris greșit înseamnă „cât dai”,
    // adică plafonul — un parametru neînțeles n-are voie să golească lista.
    // Felul de livrare, ca filtru de server.
    //
    // Panoul îl avea numai în interfață, deci filtra ce apucase să încarce.
    // Odată ce lista se citește pe pagini, un filtru care lucrează după
    // tăietură nu mai filtrează comenzile: filtrează pagina.
    $chan = trim((string) ($_GET['chan'] ?? ''));
    if ($chan !== '') {
        $where[]  = 'o.fulfillment = ?';
        $params[] = $chan;
    }

    // Căutarea, dusă pe server.
    //
    // Caută exact ce scrie pe cutie — „Caută client, referință, produs" — și
    // nimic în plus. Telefonul e în tabel și ar fi fost ieftin de adăugat, dar
    // o casetă care caută mai mult decât spune e tot un ecran care minte.
    //
    // `%` și `_` se scapă, altfel cine caută „50%" primea toate comenzile, iar
    // cine caută „a_b" primea și „axb". Caracterul de scăpare e cerut pe față
    // — ESCAPE '!' — fiindcă backslash-ul implicit al MySQL are alt înțeles în
    // șirurile PHP și în modul NO_BACKSLASH_ESCAPES: aceeași interogare ar fi
    // căutat altceva după o schimbare de configurație a serverului.
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $ac = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        // Numele produsului se caută prin EXISTS, nu prin JOIN: un JOIN ar fi
        // înmulțit comanda cu numărul de poziții care se potrivesc, deci
        // `total` ar fi numărat aceeași comandă de mai multe ori — chiar cifra
        // de care atârnă numărul de pagini.
        $where[]  = "(o.ref LIKE ? ESCAPE '!' OR o.customer_name LIKE ? ESCAPE '!'"
            . " OR EXISTS (SELECT 1 FROM order_items oi"
            . " WHERE oi.order_id = o.id AND oi.name LIKE ? ESCAPE '!'))";
        $params[] = $ac;
        $params[] = $ac;
        $params[] = $ac;
    }

    $limita = 500;
    if (isset($_GET['limit'])) {
        $cerut = (int) $_GET['limit'];
        if ($cerut > 0) {
            $limita = min($cerut, 500);
        }
    }

    // Ordinea se socotește după cum a fost cerută lista, fiindcă tăietura cade
    // întotdeauna la capătul de jos al ei.
    //
    // Fereastra de zile înseamnă „mă interesează bucata asta de calendar”, și e
    // cerută de laborator (o zi, ?day=), de panoul de comenzi și de fila de intrare:
    // azi..azi+6. Citită de la coadă, ca până acum, tăietura de 500 mânca
    // tocmai ziua de azi și pe cea de mâine — comenzile care se coc — și lăsa
    // în listă capătul cel mai depărtat, care nu se face încă. Cerută anume, o
    // fereastră se citește deci de la capătul ei apropiat.
    //
    // Fără fereastră (Depozit, pagina clientului) se vrea ce e proaspăt: ordinea
    // rămâne cea dinainte, iar tăietura cade pe comenzile vechi.
    $fereastra = $day !== '' || $from !== '' || $to !== '';
    $ordine    = $fereastra ? 'o.day ASC, o.seq ASC' : 'o.day DESC, o.seq DESC';

    // Urgentele deasupra, dar numai cui cere anume.
    //
    // Panoul de administrare le ridica singur, după ce primea lista. Merge
    // câtă vreme lista e întreagă; de îndată ce se citește pe pagini, o
    // sortare făcută după tăietură nu mai sortează comenzile — sortează
    // pagina. O comandă urgentă de pe pagina a treia rămâne pe pagina a
    // treia, oricât de sus ar fi urcată acolo.
    //
    // Se cere pe față (`?sort=urgent`), nu se face din oficiu: laboratorul,
    // ruta, depozitul și fila clientului își au ordinea lor și n-au cerut
    // nicio schimbare. Ordinea rămâne totală — `day, seq` e cheie unică pe
    // coloane NOT NULL — deci paginile nu se pot amesteca între două cereri.
    if (trim((string) ($_GET['sort'] ?? '')) === 'urgent') {
        $ordine = 'o.urgent DESC, ' . $ordine;
    }

    // Pagina cerută. Plafonată ca la jurnal (api/routes/log.php:37): un
    // `?page=99999999999999999999` dădea (int) PHP_INT_MAX, iar înmulțirea
    // trecea în float — „OFFSET 4.6E+20" e eroare de sintaxă SQL, deci 500
    // pentru un număr de pagină.
    //
    // Implicit 1. Cine nu cere o pagină primește exact ce primea și înainte:
    // primele `$limita` comenzi, în aceeași ordine. Panoul de laborator, ruta,
    // depozitul și fila clientului n-au aflat nimic.
    $page   = min(1000000, max(1, (int) ($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $limita;

    // Numărul total, socotit pe ACELEAȘI condiții.
    //
    // Nu se deduce din câte rânduri au venit: asta ar da „câte încap pe
    // pagină", adică exact minciuna care se repară. Fără JOIN peste poziții,
    // din motivul scris la căutare.
    $total = (int) scalar(
        'SELECT COUNT(*) FROM orders o'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : ''),
        $params
    );

    // LIMIT/OFFSET se interpolează ca întregi validați: MySQL nu acceptă
    // parametri legați în poziția asta.
    $sql = 'SELECT o.* FROM orders o'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY ' . $ordine . ' LIMIT ' . $limita . ' OFFSET ' . $offset;

    $orders = all($sql, $params);
    // Amândouă steagurile întreabă azi același lucru — „cel care se uită e din
    // casă?" —, dar păzesc două secrete deosebite: banii agentului și numele
    // angajatului care a scris comanda. Scrise apart, se pot despărți mâine.
    $personal = $u['role'] !== 'client';
    // Al patrulea steag e plata (runda 2.7.0), și nu se scoate din `$personal`:
    // casa nu e toată de un fel aici. Clientul află doar dacă mai are de
    // plătit, cine are „Încasări” vede tot, iar laboratorul, șoferul și
    // agentul — nimic. Socoteala stă într-un singur loc, `plati_fel()`.
    $orders = attach_items($orders, $personal, $personal, plati_fel($u));

    // Eticheta violetă „Adus de …" — numai pentru personal.
    //
    // Clientul își vede aici propriile comenzi, iar cine l-a adus în casă și cât
    // câștigă acela din el nu-l privește: e o înțelegere între patron și agent.
    // Filtrul stă pe rol, nu pe ce desenează pagina, ca de obicei.
    if ($u['role'] !== 'client') {
        $com = referral_commissions_for_orders($orders);
        foreach ($orders as &$o) {
            $o['commission'] = $com[(int) $o['id']] ?? null;
        }
        unset($o);
    }
    // Tăietura se spune pe față.
    //
    // Până acum lista se oprea la 500 fără să sufle o vorbă: panoul desena
    // liniștit ce primise, iar comenzile de dincolo de tăietură pur și simplu
    // nu existau pentru cine se uita la ecran. E felul cel mai rău de a pierde
    // o comandă — nimeni nu caută ce nu știe că lipsește.
    //
    // „>=”, nu „>”: baza n-are cum să dea mai mult decât plafonul, deci egal
    // înseamnă „exact cât încape”, adică foarte probabil mai era. Un fals
    // pozitiv la fix 500 de comenzi e un avertisment în plus; unul fals negativ
    // ar fi o comandă pierdută.
    // Taietura se spune in continuare, dar acum se poate si numara.
    //
    // `truncated` ramane pentru apelantii care nu cer pagini: fila
    // clientului si panoul de laborator (depozitul ia `pending` din stoc). Cifrele
    // noi sunt in plus, nu in loc — un apelant care nu le citeste nu simte nimic.
    json_out([
        'orders'    => $orders,
        'truncated' => count($orders) >= $limita,
        'total'     => $total,
        'page'      => $page,
        'perPage'   => $limita,
        'pages'     => (int) max(1, (int) ceil($total / $limita)),
    ]);
}

/**
 * GET /orders/counts?from=&to= — câte comenzi și câte bucăți are fiecare zi.
 *
 * Pentru calendarul din laborator. Panoul aduce oricum o fereastră de șapte
 * zile întregi, cu tot cu poziții, deci săptămâna n-are nevoie de ruta asta;
 * ce-i lipsea era orizontul. Un brutar care vrea să știe dacă joia viitoare e
 * încărcată nu poate afla decât mutând fereastra acolo și așteptând — adică
 * pierzând ziua de azi de pe ecran.
 *
 * Se numără, nu se aduc: o lună de comenzi întregi, cu nume, telefoane și
 * adrese, ar fi și mult de trimis, și mai mult decât are nevoie cineva ca să
 * se uite pe un calendar.
 *
 * Anulatele nu se numără. Laboratorul nu le coace, panoul le scoate din listă
 * (`Panou Laborator.dc.html`, filtrul după `gp.cancelled()`), deci dacă s-ar
 * număra aici, calendarul ar arăta o zi mai plină decât e.
 *
 * ATENȚIE la ordinea din tabelul de rute: `/orders/counts` TREBUIE să stea
 * înaintea lui `/orders/#`, altfel segmentul variabil înghite „counts” și
 * ruta asta nu se cheamă niciodată. Prima potrivire câștigă — v. api/index.php.
 */
function route_orders_counts(): void
{
    $u = require_auth();

    $from = valid_day(trim((string) ($_GET['from'] ?? '')) ?: today_local());
    $to   = valid_day(trim((string) ($_GET['to'] ?? '')) ?: $from);
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    // O lună are 31 de zile, dar grila de calendar arată și zilele lunii
    // vecine care umplu prima și ultima săptămână: 42 de căsuțe. Plafonul e
    // pus peste ele, cu loc de o a doua lună cerută din mers, și e acolo ca o
    // cerere scrisă de mână să nu pună serverul să numere toată baza.
    if ((strtotime($to) - strtotime($from)) > 92 * 86400) {
        fail('Intervalul cerut e prea lung: cel mult 92 de zile.', 422, 'range_too_long');
    }

    $where  = ['o.day BETWEEN ? AND ?'];
    $params = [$from, $to];

    [$vSql, $vParams] = orders_visibility_filter($u);
    if ($vSql !== '') {
        $where[] = $vSql;
        $params  = array_merge($params, $vParams);
    }

    $cancelled = status_cancelled();
    if ($cancelled !== '') {
        $where[]  = 'o.status <> ?';
        $params[] = $cancelled;
    }

    $cond = implode(' AND ', $where);

    $rows = all(
        "SELECT o.day AS day, COUNT(*) AS n FROM orders o WHERE $cond GROUP BY o.day",
        $params
    );

    // Bucățile se numără separat, cu aceeași condiție: un JOIN pe poziții în
    // aceeași interogare ar înmulți rândurile comenzii și ar strica `COUNT(*)`
    // — o comandă cu trei poziții ar fi numărată de trei ori.
    $rowsQ = all(
        "SELECT o.day AS day, COALESCE(SUM(oi.qty), 0) AS q
           FROM orders o JOIN order_items oi ON oi.order_id = o.id
          WHERE $cond
          GROUP BY o.day",
        $params
    );

    $counts = [];
    $pieces = [];
    foreach ($rows as $r) {
        $counts[(string) $r['day']] = (int) $r['n'];
        $pieces[(string) $r['day']] = 0.0;
    }
    foreach ($rowsQ as $r) {
        // Cantitățile sunt zecimale în bază (o jumătate de tavă e 0.5), iar
        // tăierea la întreg le-ar pierde — v. proba „cantitățile zecimale”.
        $pieces[(string) $r['day']] = (float) $r['q'];
    }

    json_out(['from' => $from, 'to' => $to, 'counts' => $counts, 'pieces' => $pieces]);
}

function route_orders_get(string $id): void
{
    $u  = require_auth();

    // Un rol căruia comenzile nu-i sunt deschise DELOC e oprit aici, înaintea
    // oricărei citiri, și cu același răspuns pentru orice număr: altfel, din
    // 404 față de 403, ar putea afla care numere de comandă are casa. Lista îi
    // iese oricum goală (`orders_visibility_filter()`); rândul ăsta închide
    // drumul lăturalnic, adresa scrisă de mână.
    if (!orders_readable($u)) {
        fail('Contul tău nu are acces la comenzi.', 403, 'forbidden');
    }

    // Marginea agentului stă înaintea oricărei citiri, ca poarta de la
    // `PATCH /orders`: pusă după „comanda nu există", i-ar fi spus agentului,
    // din 404 față de 403, care numere de comandă are casa.
    if ($u['role'] === 'agent_vanzari' && !referral_agent_sees_order((int) $u['id'], (int) $id)) {
        fail('Comanda nu e a unui cumpărător adus de tine.', 403, 'forbidden');
    }

    $o  = one('SELECT * FROM orders WHERE id = ?', [(int) $id]);
    if (!$o) {
        fail('Comanda nu există.', 404, 'not_found');
    }
    if ($u['role'] === 'client' && (int) $o['customer_id'] !== (int) $u['id']) {
        fail('Nu ai acces la această comandă.', 403, 'forbidden');
    }
    $personal = $u['role'] !== 'client';
    $out = attach_items([$o], $personal, $personal, plati_fel($u))[0];
    // Ca la listă: cine a adus clientul e treabă între patron și agent.
    if ($u['role'] !== 'client') {
        $out['commission'] = referral_commissions_for_orders([$out])[(int) $out['id']] ?? null;
    }
    json_out(['order' => $out]);
}

/* ------------------------------------------------------------- tarifare -- */

/**
 * Răspunsul unei tarifări: exact ce s-ar scrie, cu numele sub care panoul redă
 * deja o comandă salvată (`attach_items()`).
 *
 * Scris o singură dată, fiindcă îl dau două rute. Două copii ar fi ajuns să
 * numească altfel aceleași cifre, iar pagina ar fi arătat bani pe un drum și
 * nimic pe celălalt.
 */
function tarifare_raspuns(array $priced, float $total): array
{
    $pozitii = [];
    foreach ($priced as $p) {
        $pozitii[] = [
            'productId' => (int) $p['id'],
            'name'      => $p['name'],
            'qty'       => (float) $p['qty'],
            'unitPrice' => (float) $p['unit'],
            // Suma rândului se socotește aici, nu în pagină: altfel ar ieși din
            // înmulțirea a două zecimale rotunjite altfel decât le rotunjește
            // casa, iar rândurile n-ar mai da totalul de dedesubt.
            'lineTotal' => round((float) $p['unit'] * (float) $p['qty'], 2),
            'variant'   => $p['variant'],
            // Prețul de azi, lângă cel pus pe hârtie. De obicei e aceeași
            // cifră; când diferă, tocmai diferența e ce trebuie să se vadă —
            // s-a onorat un preț promis mai demult.
            'listPrice' => $p['catalog'] === null ? null : (float) $p['catalog'],
            // Treapta la care s-a pus prețul (1 = A, 2 = B, 3 = C) și pragul
            // care a câștigat-o, ca formularul să poată scrie „preț B, de la
            // 100 buc." lângă rând. Un produs fără trepte e mereu 1, fără prag.
            'treapta'   => (int) ($p['treapta'] ?? 1),
            'prag'      => $p['prag'] ?? null,
        ];
    }

    return [
        'items' => $pozitii,
        // Rotunjit ca la INSERT (`round($total, 2)`), altfel cifra de pe ecran
        // n-ar fi chiar cea care ajunge în `orders.total`.
        'total' => round($total, 2),
        // Varianta comenzii, dedusă din poziții, ca la scriere: formularul
        // poate cuprinde și congelate, și coapte, iar atunci comanda e „mixt".
        'variant' => order_variant_of($priced),
        // Rândul care s-ar scrie în jurnal dacă s-a onorat ceva sub catalog.
        // Gol când nu s-a onorat nimic, adică aproape întotdeauna.
        'note' => price_honored_note($priced),
    ];
}

/**
 * POST /orders/quote — cât ar costa o comandă NOUĂ, fără să scrie nimic.
 *
 * Formularul din panou arăta bucăți, nu bani, deși serverul socotește prețul
 * la fiecare salvare. O înmulțire făcută în pagină ar minți în patru feluri,
 * toate scrise în `price_items()`: prețurile deja promise, felierea unei
 * poziții în două rânduri cu două prețuri, produsele scoase între timp din
 * catalog și varianta luată de pe fiecare poziție (care poate da „mixt").
 * Omul ar vedea o sumă pe ecran și ar salva alta — paguba scrisă în
 * `docs/04-catalogul-de-produse.md`.
 *
 * Ruta cheamă ACELEAȘI funcții ca scrierea, cu aceiași parametri, și întoarce
 * ce s-ar scrie, rotunjit cum rotunjește INSERT-ul. Fiind fără scriere, nu
 * atinge depozitul, nu rezervă nimic, nu scrie în jurnal și nu trimite niciun
 * anunț.
 *
 * POARTA E A SCRIERII, nu una nouă spre prețuri: `require_staff()`, ca
 * `route_orders_create()`. Catalogul cu prețuri e oricum public
 * (`GET /products`); ce adaugă ruta e socoteala casei, iar aceea se cuvine
 * numai personalului.
 *
 * NU PRIMEȘTE NICIO IDENTITATE. Corpul e `{ items, variant? }` — fără client,
 * fără cont, fără cod de recomandare. Ce nu primește, nu poate scurge: nu e
 * nevoie nici de `client_by_name_in_scope()`, nici de marginea agentului.
 *
 * Refuzurile rămân ale serverului (`bad_product`, `missing_price`,
 * `total_too_big`): omul află de prețul lipsă în timp ce scrie comanda, nu
 * după ce a scris-o toată și apasă „Salvează".
 *
 * De ce POST, deși nu scrie: coșul e o listă, iar o listă de poziții într-un
 * query string ajunge în jurnalele serverului și în `Referer`. Prețul e că
 * ruta intră prin `check_csrf()` — și e bine că intră.
 */
function route_orders_quote(): void
{
    // Socoteala casei e a celor care au registrul deschis (Comenzi, Laborator
    // ori Rută). Din pornire îl are tot personalul, deci nimic nu se schimbă;
    // se schimbă în ziua în care fondatorul ia cuiva, din „Acces", toate trei —
    // ori îi dă omului de laborator unul din ele.
    $u = require_drept(...GP_DREPT_REGISTRU);
    $b = body();

    // Comanda care EXISTĂ se tarifează pe cealaltă rută, fiindcă acolo poarta e
    // alta. Fără refuzul ăsta, panoul care editează o comandă ar putea nimeri
    // aici din greșeală și ar arăta prețul de azi peste bucățile deja promise —
    // adică exact minciuna pentru care s-a scris ruta.
    if (isset($b['orderId']) && $b['orderId'] !== '' && $b['orderId'] !== null) {
        fail(
            'Tarifarea unei comenzi care există se cere la POST /orders/{id}/quote.',
            422,
            'bad_route'
        );
    }

    $items = cos_de_tarifat($b['items'] ?? null);

    // Varianta decide prețul și se poate da pe fiecare poziție, ca la creare.
    [$priced, $total] = price_items($items, require_variant($b['variant'] ?? null));

    json_out(tarifare_raspuns($priced, $total));
}

/**
 * POST /orders/#/quote — cât ar costa RESCRIEREA pozițiilor unei comenzi.
 *
 * Geamăna de deasupra, pentru comanda care există deja. Repetă, cuvânt cu
 * cuvânt, apelul din `route_orders_update()`: aceleași prețuri onorate
 * (`honored_prices()`) și aceeași îngăduință pentru produsele scoase între
 * timp din catalog. Deci răspunsul e exact ce ar scrie `PATCH /orders/#`.
 *
 * DE CE E RUTĂ DEOSEBITĂ, ȘI NU UN `orderId` ÎN CORPUL CELEI DE SUS. Poarta e
 * alta: aici trece numai cine are voie să scrie pe comanda aceea. Cu un singur
 * drum, poarta s-ar fi ales dintr-un câmp din corp — tiparul din care ies
 * scurgerile. Din contul comun al tabletelor, o tarifare cu `orderId` ar fi
 * spus, după 404 față de 403, care numere de comandă există în casă, și ar fi
 * arătat prețurile negociate ale oricui.
 *
 * Poarta e chiar a lui `PATCH` — aceeași funcție, `poarta_scrierii_comenzii()`,
 * nu o copie a ei.
 */
function route_orders_quote_edit(string $id): void
{
    $u = require_drept(...GP_DREPT_REGISTRU);
    $b = body();

    // `items` nu e în ORDER_FIELDS_STAFF, deci din contul de tură cererea se
    // oprește aici, cu același 403 și același mesaj ca la salvare.
    poarta_scrierii_comenzii($u, $b, $id);

    $o = one('SELECT variant FROM orders WHERE id = ?', [(int) $id]);
    if (!$o) {
        fail('Comanda nu există.', 404, 'not_found');
    }

    $items    = cos_de_tarifat($b['items'] ?? null);
    $implicit = require_variant($b['variant'] ?? null, valid_variant($o['variant']));
    $vechile  = all(
        'SELECT ' . honored_columns() . ' FROM order_items WHERE order_id = ? ORDER BY id',
        [(int) $id]
    );

    // Perechile deja pe comandă trec de poarta laturii; un rând NOU pe o
    // latură stinsă între timp, nu. Amândouă listele ies din aceeași hartă, deci
    // nu pot ieși din pas.
    $onorate = honored_prices($vechile);
    [$priced, $total] = price_items(
        $items,
        $implicit,
        $onorate,
        array_filter(array_column($vechile, 'product_id'), static fn($x) => $x !== null),
        array_keys($onorate)
    );

    json_out(tarifare_raspuns($priced, $total));
}

/* --------------------------------------------------------------- creare -- */

/**
 * POST /orders — numai cine răspunde de ce intră în laborator.
 *
 * Ruta era deschisă oricui, „fiindcă site-ul public trebuie să poată primi
 * comenzi". Nu e adevărat: site-ul trimite `POST /requests`, iar cererea
 * devine comandă abia după ce o accepți. Singurul care cheamă ruta asta e
 * panoul de comenzi — comanda luată la telefon sau la ghișeu.
 *
 * Cât timp a fost deschisă, oricine de pe internet putea sări peste treapta de
 * aprobare și băga marfă direct în laborator, la orice zi și în orice
 * cantitate: producția pornea pe o comandă pe care n-o ceruse nimeni.
 *
 * Apoi a fost a întregului personal — și tot era prea largă. Agentul de vânzări
 * scria o comandă care intra pe loc în producție, fără ca cineva s-o fi
 * încuviințat; `angajat`, care nici n-are Comenzi în meniu, putea face același
 * lucru prin API, din contul comun al tabletelor. De la 18 septembrie 2026
 * poarta e `require_order_create()` — admin, manager, fondator. Ceilalți
 * trimit `POST /requests` și așteaptă o apăsare; v. `GP_ORDER_CREATE_ROLES`
 * în api/lib/auth.php.
 */
function route_orders_create(): void
{
    $user = require_drept('comenzi.scrie');
    $b    = body();

    // CODUL DE RECOMANDARE SE SCRIE DE ORICINE POATE SCRIE COMANDA.
    //
    // Hotărârea patronului, 19 septembrie 2026: „când faci o comandă ca
    // fondator/admin trebuie să poți adăuga alt cod de recomandare, dacă
    // comanda a luat-o altcineva și tu doar o treci; toată lumea trebuie să
    // poată face comenzi cu codul altuia — doar că, la deschiderea
    // formularului, să fie scris al lui".
    //
    // Aici au stat până azi două porți, și amândouă cad odată cu hotărârea:
    //
    //   · `referral_not_yours` — agentul de vânzări n-avea voie decât cu
    //     codul lui. Poarta era deja fără obiect de la 18 septembrie:
    //     agentul nu mai ajunge pe ruta asta deloc (`require_order_create()`
    //     de mai sus), ci scrie o CERERE — iar `POST /requests` primește
    //     `referralCode` de la oricine, cum îl primește dintotdeauna de la
    //     vizitatorul de pe site. Mutarea ușii ridicase deci poarta fără ca
    //     cineva s-o spună; hotărârea de azi o scrie pe față.
    //   · `staff_field_denied` — managerul și contul de tură n-aveau voie cu
    //     niciun cod. Contul de tură nu mai ajunge nici el aici. Rămânea
    //     managerul, iar patronul îl vrea înăuntru: cine poate naște
    //     comanda poate scrie și cine a adus-o.
    //
    // CE RĂMÂNE ÎN PICIOARE, fiindcă nu poarta asta le ținea:
    //
    //   · `client_by_name_in_scope()`, mai jos. Codul spune „al cui e
    //     comisionul", nu „al cui e clientul" — două întrebări deosebite,
    //     iar a doua și-a căpătat paza ei pe 14 septembrie 2026 tocmai
    //     fiindcă poarta de pe cod nu răspundea la ea (v. lămurirea de
    //     acolo, cu comanda 28970).
    //   · Auto-recomandarea. Cel cu codul nu poate fi chiar cumpărătorul —
    //     paza e în `referral_commission_sync()`, care se execută pe
    //     AMÂNDOUĂ drumurile (creare și corectare) și scrie în jurnal
    //     `referral_self_blocked`.
    //   · Plata deja făcută și codul scos de mână, prin
    //     `referral_guard_paid_code_change()` și
    //     `referral_guard_dismissed_code()`. Ele se cheamă din
    //     `PATCH /orders`, deci pe o comandă care există; la NAȘTERE n-au ce
    //     apăra: comanda n-are încă nici comision plătit, nici rând scos.
    //
    // ASIMETRIA DE IERI A CĂZUT. Până pe 19 septembrie, `PATCH /orders` lăsa
    // codul tot numai administratorului: `referralCode` nu e în
    // `ORDER_FIELDS_STAFF`, iar poarta de la `poarta_scrierii_comenzii()`
    // cerea acolo `e_admin()`. Ieșea că managerul putea scrie codul la
    // naștere și nu și-l mai putea îndrepta un minut mai târziu. Poarta
    // întreabă de-acum `writes_orders()`, deci cine naște comanda o și
    // corectează — v. lămurirea întreagă de la funcția aceea.

    $items = cos_de_tarifat($b['items'] ?? null);

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
    //
    // DAR NUMELE TREBUIE SĂ DUCĂ LA UN CLIENT LA CARE OMUL ARE VOIE. Ușa asta
    // era cea mai scumpă dintre toate, și s-a măsurat pe 14 septembrie 2026:
    // agentul „mihai" a născut aici comanda 28970 pe numele „Cafeneaua Veche",
    // un client pe care lista lui nici nu i-l arăta — iar poarta de deasupra,
    // cea care-i îngăduie numai codul LUI, l-a lăsat să treacă, fiindcă chiar
    // codul lui era. Din asta au ieșit trei lucruri deodată: comanda a intrat
    // în istoricul unui client străin, s-a născut un comision de 2,85 RON, și
    // — cel mai greu de întors — `referral_link_new_user()` a scris
    // `users.referred_by = 1811` pe contul clientului PENTRU TOTDEAUNA. Adică
    // orice comandă viitoare a acelui client i-ar fi adus agentului bani, fără
    // să mai fie nevoie de vreun cod și fără ca cineva să se mai uite.
    //
    // Poarta de pe cod nu apăra nimic singură: ea întreabă „al cui e codul?",
    // nu „al cui e clientul?". Sunt două întrebări, și amândouă trebuie puse.
    $customerId = client_by_name_in_scope($user, $customerName);

    $slot = trim((string) ($b['slot'] ?? ''));
    if ($slot !== '' && !in_array($slot, delivery_slots(), true)) {
        fail('Interval de livrare necunoscut: ' . $slot, 422, 'bad_slot');
    }

    // Zona se verifică față de lista din Setări, exact ca la cereri — și chiar
    // prin aceeași funcție, `zone_field()` din api/lib/orders.php, ca regula să
    // nu mai stea scrisă în patru locuri.
    $zone = zone_field($b['zone'] ?? '');

    // Marcajele se puneau doar prin PATCH, după creare: bifa „URGENT" din
    // formularul de comandă nouă se pierdea, iar comanda intra în laborator ca
    // una obișnuită — adică la coadă.
    $urgent = !empty($b['urgent']);
    $timed  = !empty($b['timed']);

    // Implicitul vine din Setări, nu din textul scris aici: v.
    // `default_fulfillment()` (api/lib/settings.php) pentru toată povestea.
    $fulfillment = trim((string) ($b['fulfillment'] ?? '')) ?: default_fulfillment();
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
    $pragOcolit = null;

    // UNIQUE(day, seq) împiedică două comenzi simultane să primească același
    // număr; dacă se întâmplă, reluăm cu următorul număr liber.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $pdo->beginTransaction();

            // POARTA PRAGULUI DE NEPLĂTITE (runda 2.7.0, hotărârea patronului
            // din 4–5 octombrie 2026): a treia comandă neplătită a aceluiași
            // client nu se mai scrie. Toată regula stă la `plati_poarta()`,
            // api/lib/plati.php; aici e de ținut minte numai LOCUL.
            //
            // PRIMA INSTRUCȚIUNE A TRANZACȚIEI, deasupra numărului de ordine.
            // `MAX(seq)` de mai jos e o citire simplă, iar pe REPEATABLE READ
            // prima citire simplă face „poza” tranzacției. Pusă poarta sub ea,
            // a doua din două comenzi trimise în aceeași clipă ar fi așteptat
            // cuminte la lacătul clientului și apoi ar fi numărat din poza de
            // DINAINTEA primei: treceau amândouă.
            //
            // ÎNĂUNTRUL BUCLEI, deci luată de la capăt la fiecare reluare: după
            // un `rollBack()` lacătul s-a dus, iar între timp altă filă a putut
            // scrie. `$pragOcolit` se scrie din nou de fiecare dată — o trecere
            // peste prag ținută minte de la o tură dată înapoi ar fi ajuns în
            // Jurnal pe o numărătoare care nu mai e a comenzii născute.
            //
            // O comandă pe un nume fără cont (`$customerId` null) nu e a
            // nimănui anume: nu se numără și nu se oprește.
            $pragOcolit = plati_poarta($customerId, $user, $b);

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

            // Prețul de catalog rămâne scris lângă cel pus pe hârtie. Pe o
            // comandă luată la telefon cele două sunt de obicei aceeași cifră;
            // diferă când s-a onorat un preț promis mai demult, iar atunci
            // tocmai diferența e ce trebuie să se vadă. Tot aici treapta care a
            // dat prețul (migrarea 028). Interogarea se alege o dată, în afara
            // buclei, ca o bază fără migrările 011 ori 028 să nu cadă pe
            // fiecare poziție — v. `pozitie_insert()`.
            [$sqlPozitie, $cuLista, $cuTrepte] = pozitie_insert(
                'order_items', 'order_id, product_id, name, variant, qty, unit_price'
            );
            foreach ($priced as $p) {
                q($sqlPozitie, pozitie_valori(
                    [$orderId, $p['id'], $p['name'], $p['variant'], $p['qty'], $p['unit']],
                    $cuLista, $cuTrepte, $p['catalog'] ?? null, $p['treapta'] ?? 1, $p['prag'] ?? null
                ));
            }
            // Promisiunea se naște odată cu poziția, în aceeași tranzacție:
            // dacă e scrisă mai târziu, între cele două clipe depozitul spune
            // că are făină liberă pe care a promis-o deja.
            stock_reserve_order($orderId);
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

    // Comisionul se scrie DUPĂ commit, nu în tranzacție.
    //
    // În tranzacție, o defecțiune la socoteala recomandării ar da înapoi toată
    // comanda — marfa, rezervările, tot — pentru o socoteală de marketing.
    // Regula e cea de la anunțuri (`:221-225`): comanda e lucrul adevărat, ce
    // vine după ea nu are voie s-o omoare. `UNIQUE (order_id)` din
    // referral_commissions face scrierea idempotentă, deci nici reluarea buclei
    // de mai sus n-o poate dubla.
    // LEGAREA CONTULUI PE VECI CERE O BIFĂ ȘI AICI, nu numai la corectare.
    //
    // Hotărârea patronului, 19 septembrie 2026. Până azi cârligul se chema cu
    // trei argumente, deci al patrulea rămânea pe valoarea lui implicită,
    // `true` — iar un cod tastat în formularul de comandă nouă scria, pe
    // tăcute, `users.referred_by` pe contul cumpărătorului. O dată, pe viață.
    //
    // Ce însemna asta, în curte: clienta are cont de anul trecut, a cumpărat
    // de două ori singură, n-a adus-o nimeni. Sună azi, managerul îi scrie
    // comanda, o leagă de cont scriindu-i numele și pune codul agentului
    // fiindcă ea a pomenit că el i-a recomandat patiseria. Din clipa aceea
    // TOATE comenzile ei viitoare — de pe site, la telefon, peste doi ani —
    // îi aduc agentului comision, fără ca nimeni să mai tasteze nimic. Nici
    // ecranul n-o spunea: nota de sub câmp vorbea numai despre comisionul
    // comenzii de față.
    //
    // Aceeași faptă, pe o comandă SALVATĂ, cerea de mult o bifă apăsată
    // dinadins, cu un text care spune limpede ce face (`route_orders_update`).
    // De-acum o cere și nașterea, cu aceeași bifă, pornită tot nebifată.
    //
    // FĂRĂ BIFĂ, CODUL NU E ARUNCAT: comanda de față intră în „Recomandări" ca
    // adusă de cel cu codul, iar comisionul ei se calculează întreg. Se
    // schimbă numai ce se întâmplă cu comenzile de MÂINE ale aceluiași om.
    //
    // CE NU TRECE PE AICI, dinadins: cererea de pe site prefăcută în comandă
    // (`route_requests_accept`). Acolo codul tastat de personal e deja aruncat
    // de hotărârea din 14 septembrie când cererea s-a legat de un cont, iar
    // codul care rămâne e chiar al clientului care a comandat singur — adică
    // singura mărturie despre cine l-a trimis. Aceea leagă mai departe.
    $comision = null;
    try {
        $comision = referral_commission_sync(
            $orderId,
            isset($b['referralCode']) ? (string) $b['referralCode'] : null,
            (int) $user['id'],
            !empty($b['referralLink'])
        );
    } catch (Throwable $e) {
        error_log('[gp] comision nescris pentru comanda ' . $orderId . ': ' . $e->getMessage());
    }
    // Comanda se citește DUPĂ cârlig, nu înainte de el.
    //
    // `referral_commission_sync()` scrie `orders.referral_code`, deci un rând
    // citit mai devreme poartă încă `null` acolo — iar panoul primea în răspuns
    // o comandă fără codul pe care tocmai îl pusese pe ea. Aceeași ordine e
    // deja în `route_orders_update()` (`:833`): sincronizează, apoi recitește.
    $order = attach_items([one('SELECT * FROM orders WHERE id = ?', [$orderId])], true, true, plati_fel($user))[0];

    // Eticheta violetă pleacă odată cu comanda: panoul adaugă în listă exact
    // rândul primit aici, fără să mai ceară o dată. Fără asta, „Adus de …" ar
    // apărea abia la prima reîmprospătare, iar operatorul ar crede că lipsește.
    $order['commission'] = $comision === null ? null : [
        'id'       => (int) $comision['id'],
        'referrer' => $comision['referrer_id'] !== null ? (int) $comision['referrer_id'] : null,
        'label'    => $comision['referrer_label'],
        'amount'   => round((float) $comision['amount'], 2),
        'rate'     => round((float) $comision['rate'], 2),
        'status'   => (string) $comision['status'],
        'paid'     => $comision['paid_at'] !== null,
        // „Bifa a lucrat?" Nu „am trimis bifa", ci „s-a scris legătura".
        // Contul putea avea deja un recomandant, caz în care bifa n-a
        // schimbat nimic — iar panoul are voie s-o spună pe față.
        'linked'   => !empty($comision['legat_acum']),
    ];

    audit('order_create', 'order', (string) $orderId, 'Comandă #' . $order['seq'] . ' — ' . $customerName);

    // Trecerea peste prag se scrie abia acum, cu numărul comenzii născute din
    // ea — v. `plati_jurnal_ocolire()`.
    if ($pragOcolit !== null) {
        plati_jurnal_ocolire($pragOcolit, 'comanda ' . $order['ref']);
    }

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
    //
    // Cine a scris comanda și al cui e clientul se agață ABIA AICI, după
    // răspuns: `anunt` e numai pentru mesaj (`anunt_randuri_oameni()`,
    // api/lib/notify.php) și n-are ce căuta în ce primește panoul.
    $order['anunt'] = ['autorId' => (int) $user['id'], 'aducatorId' => $order['commission']['referrer'] ?? null];
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
 * Câmpuri care NU se scriu în comandă: spun doar pe ce stare s-a bazat omul.
 *
 * Azi unul singur, `from` — etapa pe care o avea comanda pe ecranul de pe care
 * s-a apăsat. Serverul o compară cu etapa din bază și, dacă între timp s-a
 * schimbat, refuză mutarea cu 409 `status_changed`, fără să scrie nimic.
 *
 * Stau deoparte de ORDER_FIELDS_STAFF fiindcă poarta de mai jos judecă ce se
 * SCHIMBĂ în comandă, iar `from` nu schimbă nimic: pus în lista aceea, ar fi
 * arătat ca un al doilea câmp pe care contul de tură are voie să-l editeze —
 * exact neînțelegerea din care se nasc porți largi. Iar lăsat pe dinafara
 * amândurora, tableta din laborator ar fi luat 403 `staff_field_denied` la
 * fiecare apăsare, adică paza ar fi închis tocmai ecranul pe care îl apără.
 *
 * Al doilea, din runda 2.7.0 (plăți, 4–5 octombrie 2026): `ocolesteprag` —
 * „știu că clientul e peste pragul de comenzi neplătite și trec totuși”. Nici
 * el nu se scrie nicăieri în comandă: e răspunsul omului la refuzul
 * `prag_neplatite`, iar dacă are voie să-l dea hotărăște `plati_poarta()`
 * (api/lib/plati.php) după dreptul `plati.ocoleste` al celui care FACE scrierea,
 * nu lista asta. Stă aici din aceeași pricină ca `from`: contul de tură care
 * readuce o comandă din etapa de anulare trimite numai etapa, iar cu
 * `ocolesteprag` pe dinafară ar fi primit `staff_field_denied` în locul
 * refuzului adevărat. Singur în cerere nu schimbă nimic, deci rămâne
 * „Nimic de modificat.” — poarta de acolo numără numai ce intră în `$sets`.
 */
const ORDER_FIELDS_PRECONDITION = ['from', 'ocolesteprag'];

/**
 * Poarta de dinaintea oricărei citiri, pentru scrisul pe o comandă anume.
 *
 * O ține `PATCH /orders/#` și o ține la fel `POST /orders/#/quote`, care
 * arată ce ar scrie acel PATCH. Scrisă de două ori, s-ar fi despărțit la
 * prima schimbare, iar copia rămasă în urmă ar fi devenit ușa largă:
 * tarifarea spune, pe lângă preț, și dacă o comandă există.
 *
 * Nu citește nimic din bază — tocmai ăsta e rostul ei. Vezi lămuririle
 * dinăuntru, mutate aici întregi din `route_orders_update()`.
 */
function poarta_scrierii_comenzii(array $u, array $b, string $id): void
{
    // REGISTRUL ÎNCHIS ÎNTÂI (migrarea 033, „Acces"). Se întreabă dacă omul are
    // măcar unul din panourile care lucrează cu comenzi — Comenzi, Laborator,
    // Rută. Din pornire îl are tot personalul, deci poarta se poartă ca ieri.
    // Fără ea, un angajat căruia fondatorul i-a lăsat numai Depozitul ar fi
    // putut muta mai departe, prin API, comenzi pe care nu le mai vede nicăieri.
    //
    // Cele două rute care trec pe aici întreabă același lucru chiar la intrare
    // (`require_drept(...GP_DREPT_REGISTRU)`), din aceeași listă. Întrebarea
    // rămâne și aici fiindcă funcția asta e poarta scrierii întregi: cine o
    // cheamă mâine de pe altă rută n-are voie să uite jumătate din ea.
    if (!orders_readable($u)) {
        fail('Nu ai dreptul să accesezi această resursă.', 403, 'forbidden');
    }

    // Poarta stă înaintea oricărei citiri din bază: din contul comun al
    // tabletelor nu trebuie să se poată afla nici măcar dacă o comandă există,
    // încercând să i se schimbe clientul.
    //
    // Cererea se oprește întreagă, nu se curăță de câmpurile interzise: un
    // `customerName` strecurat lângă `status` ar fi trecut altfel neobservat
    // odată cu apăsarea firească a brutarului, exact pe drumul pe care nimeni
    // nu se uită.
    // POARTA ÎNTREABĂ „POATE SCRIE COMENZI?", NU „E ADMINISTRATOR?".
    //
    // Hotărârea patronului, 19 septembrie 2026: managerul e de-acum egal cu
    // administratorul pe comenzi — orice câmp, pe orice comandă.
    //
    // Până azi poarta cerea `e_admin()`, adică `GP_ADMIN_ROLES`, iar managerul
    // nu e acolo. Ieșea o asimetrie pe care n-o ceruse nimeni: el putea NAȘTE
    // comanda (`GP_ORDER_CREATE_ROLES`, `POST /orders`) și nu mai putea
    // îndrepta în ea nici o cifră din adresă. Omul care a luat comanda la
    // telefon și a tastat el însuși codul de recomandare nu-și putea îndrepta
    // nici propria greșeală de tastare de acum patru minute — trebuia să sune
    // un administrator.
    //
    // Cele două liste nu se contopesc, ci se cheamă una pe alta: întrebarea
    // de aici e „poate scrie comanda?", iar răspunsul ei stă într-un singur
    // loc, `writes_orders()` (api/lib/auth.php). Scrisă aici ca un `in_array`
    // cu trei roluri, s-ar fi despărțit de listă la primul rol nou — pățania
    // din `liste-scrise-cu-mana`.
    //
    // CE NU SE LĂRGEȘTE ODATĂ CU EA, fiindcă nu poarta asta le ținea:
    //   · ordinea opririlor pe rută — `PUT /routes` cere mai departe
    //     `require_admin()`, iar pagina nu-i desenează managerului butoanele
    //     (`potRuta`, Administrare Comenzi.dc.html). Ruta o pune biroul.
    //   · conturile, setările, statisticile, jurnalul — porți proprii, toate
    //     neatinse.
    //
    // CONTUL DE TURĂ ȘI AGENTUL RĂMÂN EXACT UNDE ERAU: niciunul nu e în
    // `GP_ORDER_CREATE_ROLES`, deci pentru ei poarta se poartă ca ieri. Paguba
    // pentru care s-a născut — un `customerName` strecurat lângă `status` din
    // contul comun al tabletelor, care lega comanda de contul altui client —
    // e oprită mai departe de aceeași singură cheie îngăduită.
    if (!writes_orders($u)) {
        $oprite = array_values(array_diff(array_keys($b), ORDER_FIELDS_STAFF, ORDER_FIELDS_PRECONDITION));
        if ($oprite) {
            fail(
                'Din contul de personal se poate schimba doar starea comenzii, adică etapa ei. '
                    . 'Câmpuri refuzate: ' . implode(', ', $oprite) . '. '
                    . 'Restul se corectează din „Administrare Comenzi", cu un cont de administrator sau de manager.',
                403,
                'staff_field_denied'
            );
        }
    }

    // Marginea agentului stă tot înaintea citirii, din același motiv ca poarta
    // de mai sus: altfel un agent ar afla, după 404 față de 403, care numere de
    // comandă există în casă. Vezi referral_agent_scope().
    if ($u['role'] === 'agent_vanzari' && !referral_agent_sees_order((int) $u['id'], (int) $id)) {
        fail('Comanda nu e a unui cumpărător adus de tine.', 403, 'forbidden');
    }
}

/**
 * Poarta etapei FINALE: cine poate spune că o comandă a ajuns la client.
 *
 * HOTĂRÂREA PATRONULUI, 19 septembrie 2026: „numai șoferul confirmă livrarea".
 *
 * Etapa finală („Predată") înseamnă „ajunsă la client". Serverul n-avea nicio
 * regulă de trecere și niciun drept pe etapă: orice cont de personal putea
 * pune orice etapă, chiar sărind de la prima la ultima. Laboratorul o apăsa
 * când termina de ambalat, iar comanda apărea livrată înainte să fi urcat în
 * mașină.
 *
 * Pe atunci lista de etape n-avea una a ei pentru predarea către curier. De la
 * `sql/030_gata_de_livrare.sql` (2 octombrie 2026) o are: „Gata de livrare",
 * chiar înaintea celei finale. Poarta judecă și acum tot mutarea SPRE etapa
 * finală; etapa nouă e una dinaintea ei, ca oricare alta.
 *
 * DE CE NU AJUNGE OPRIREA DIN PAGINĂ. Contul `angajat` are ȘI panoul Laborator,
 * ȘI panoul Rută. Același om, cu același cont, ar fi apăsat pe cealaltă ușă —
 * iar butonul stins din „Panou Laborator" ar fi arătat ca o regulă fără să fie
 * una. Pagina e politețea; regula stă aici.
 *
 * DE CE STĂ LÂNGĂ `poarta_scrierii_comenzii()` ȘI SE SCRIE O SINGURĂ DATĂ.
 * Routerul încarcă un singur fișier de rute pe cerere, deci n-avem de plătit
 * nimic ca s-o ținem aici; scrisă de două ori, s-ar fi despărțit într-o zi pe
 * ușa care DESCHIDE, iar despărțirea aia nu s-ar fi văzut din nimic.
 *
 * DE CE 403 CU COD PROPRIU, ȘI NICIODATĂ `409 status_changed`. Cele două
 * înseamnă lucruri deosebite: 409 zice „altcineva a mutat-o între timp,
 * reîncarcă", iar pagina îl desenează așa, cu etapa de acum în `error.current`.
 * Un refuz de DREPT îmbrăcat în el ar minți omul („nu s-a schimbat nimic, n-ai
 * voie") și s-ar bate cap în cap cu paza de concurență, ale cărei două mesaje
 * sunt cerute identice literă cu literă de `.claude/check-php.mjs`.
 *
 * REFUZUL NUMEȘTE LEACUL: pagina și bifa care-l repară. Un refuz greșit se
 * drege atunci în zece secunde; unul care spune doar „nu ai voie" trimite omul
 * să sune pe cineva, cu marfa în mână.
 *
 * CE NU JUDECĂ POARTA:
 *   · comanda care E DEJA la etapa finală și rămâne acolo. Formularul din
 *     „Administrare Comenzi" retrimite etapa nemișcată la fiecare salvare, deci
 *     fără scutirea asta un manager n-ar mai fi putut îndrepta adresa unei
 *     comenzi livrate. Poarta judecă MUTAREA spre etapa finală, nu șederea pe ea.
 *   · întoarcerea ÎNAPOI de pe etapa finală. Aceea e tocmai dregerea unei
 *     apăsări greșite, și rămâne deschisă tuturor, ca azi.
 *   · fluxul fără etapă finală în Setări (`status_done()` gol): atunci nu se
 *     știe care e „ajunsă la client", deci nu se refuză nimic.
 *
 * Etapa de acum se citește din rândul adus înaintea tranzacției, nu de sub
 * lacăt. Fereastra e îngustă și se închide singură: ca scutirea de mai sus să
 * deschidă ceva, ar trebui ca între citire și scriere altcineva să mute comanda
 * ÎNAPOI de pe etapa finală, iar cel care scrie să fie tocmai un cont fără semn
 * care trimite etapa finală — adică apăsarea pe care pagina laboratorului n-o
 * mai desenează. Cererile care poartă `from` sunt oricum prinse sub lacăt, mai
 * jos, de paza de concurență.
 */
function poarta_etapei_finale(array $u, array $b, array $o): void
{
    if (!array_key_exists('status', $b)) {
        return;
    }
    $finala = status_done();
    if ($finala === '' || (string) $b['status'] !== $finala) {
        return;
    }
    if ((string) $o['status'] === $finala) {
        return;
    }
    if (poate_confirma_livrarea($u)) {
        return;
    }

    fail(
        'Livrarea o confirmă numai cine duce marfa. Comanda se poate trece până la „'
            . status_lab_ceiling() . '”, iar „' . $finala . '” o pune șoferul de pe telefonul lui. '
            . 'Dacă trebuie să o poți pune și tu, cere-i administratorului să bifeze „Poate confirma '
            . 'livrarea” pe contul tău, în „Conturi”.',
        403,
        'delivery_denied'
    );
}

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
 * Pe lângă câmpurile comenzii, cererea poate aduce `from` — etapa pe care o
 * avea comanda pe ecranul de pe care s-a apăsat (ORDER_FIELDS_PRECONDITION).
 * Dat și nepotrivit cu ce e în bază, ruta refuză cu 409 `status_changed` și
 * întoarce etapa de acum în `error.current`, fără să scrie nimic. Lipsa lui se
 * poartă ca până acum.
 *
 * Când corectura atinge o comandă deja scăzută din depozit, marfa se întoarce
 * întâi în depozit (`stock_unsync_order()`) și comanda redevine „de scăzut".
 * Fără asta, cantitatea urcată la telefon nu ajungea niciodată în registru,
 * iar comanda anulată rămânea scăzută deși marfa era în rafturi.
 */
function route_orders_update(string $id): void
{
    $u = require_drept(...GP_DREPT_REGISTRU);
    $b = body();

    // Poarta stă mai sus, scrisă o singură dată: o ține și tarifarea care
    // arată ce ar scrie PATCH-ul ăsta (`POST /orders/#/quote`).
    poarta_scrierii_comenzii($u, $b, $id);

    $o = one('SELECT * FROM orders WHERE id = ?', [(int) $id]);
    if (!$o) {
        fail('Comanda nu există.', 404, 'not_found');
    }

    // Cine poate spune că marfa a ajuns la client. Stă AICI, înaintea oricărei
    // scrieri și a oricărei tranzacții, ca refuzul să nu lase în urmă nici o
    // mișcare de stoc, nici un rând de jurnal, nici un anunț la coadă. Pricina
    // întreagă e în antetul funcției, lângă `poarta_scrierii_comenzii()`.
    poarta_etapei_finale($u, $b, $o);

    $sets = [];
    $vals = [];

    // Contul pe care îl SCRIE cererea asta, pentru poarta pragului de mai jos
    // (runda 2.7.0): `false` = contul rămâne cum e, `null` = comanda se
    // dezleagă, un număr = contul de care se leagă. Se ține minte aici, în cele
    // trei locuri care pun `customer_id` în `$sets`, ca poarta să nu ghicească
    // din `$b` a doua oară ce au hotărât ele o dată.
    $contNou = false;

    // Contul se schimbă numai când cineva o cere pe față. `customerId: null`
    // dezleagă comanda de cont, un număr o leagă de contul acela — atât are
    // nevoie personalul ca să repare o legătură pusă greșit.
    if (array_key_exists('customerId', $b)) {
        $cid = $b['customerId'];
        if ($cid === null || $cid === '') {
            $sets[] = 'customer_id = ?';
            $vals[] = null;
            $contNou = null;
        } else {
            $cid = (int) $cid;
            if (!one("SELECT id FROM users WHERE id = ? AND role = 'client'", [$cid])) {
                fail('Contul de client nu există.', 422, 'bad_customer');
            }
            $sets[] = 'customer_id = ?';
            $vals[] = $cid;
            $contNou = $cid;
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
                $contNou = (int) $gasit;
            }
        }
    }
    if (array_key_exists('phone', $b)) {
        $sets[] = 'phone = ?';
        $vals[] = text_field($b['phone'], 'Telefonul', 32) ?: null;
    }
    // Ținută deoparte de $sets, ca să nu ajungă `ref` de două ori în același
    // UPDATE. Vezi lămurirea de la ramura `ref`, mai jos.
    $refCerut = null;
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
        //
        // Referința tastată NU intră aici în `$sets`. Blocul de mutare pe altă
        // zi, de mai jos, adaugă și el `ref = ?` când numărul e ocupat în ziua
        // nouă — iar coloana ajungea astfel de două ori în același UPDATE.
        // MySQL le aplică în ordinea scrierii, deci a doua o bătea pe prima:
        // administratorul tasta o referință, o vedea în răspuns (răspunsul se
        // face din ce s-a trimis, nu din ce s-a scris) și găsea în bază cu
        // totul alta. Se ține deoparte, în $refCerut, și se scrie într-un
        // singur loc, la capătul buclei.
        $ref = text_field($b['ref'], 'Referința', 24);
        // Caseta golită era primită, măsurată și apoi uitată: `$refCerut`
        // rămânea `null`, referința veche rămânea în bază, iar cererea răspundea
        // 200. Panoul închidea formularul cu „Comanda  a fost salvată." — cu un
        // gol în loc de număr, fiindcă mesajul se compune din formularul tocmai
        // golit. Toate celelalte casete golite de pe ACELAȘI formular se aplică
        // (telefonul, adresa, mențiunile, intervalul, zona ajung `null`), deci
        // omul avea toate motivele să creadă că s-a aplicat și asta.
        //
        // Nu se poate accepta golirea: după referință se caută comanda în
        // laborator și pe bon, iar unicitatea e pe (zi, referință) — o coloană
        // goală ar face două comenzi de nedeosebit. Deci se spune pe față.
        if ($ref === '') {
            fail('Referința nu poate rămâne goală: după ea se caută comanda în laborator și pe bon. '
                . 'Dacă vrei alt număr, scrie-l aici.', 422, 'empty_ref');
        }
        $refCerut = $ref;
    }

    if (array_key_exists('status', $b)) {
        if (!in_array($b['status'], order_statuses(), true)) {
            fail('Status necunoscut. Valori permise: ' . implode(', ', order_statuses()), 422, 'bad_status');
        }
        $sets[] = 'status = ?';
        $vals[] = $b['status'];
    }

    // ETAPA PE CARE A VĂZUT-O OMUL CÂND A APĂSAT.
    //
    // Nu e un câmp al comenzii, deci nu intră în `$sets`: e o condiție pusă
    // mutării. Ecranele de tură se uită la o listă adusă acum câteva secunde —
    // tableta din laborator se împrospătează la patru secunde și STĂ PE LOC cât
    // fila e ascunsă, iar telefonul șoferului la cincisprezece. Între timp
    // administratorul poate anula comanda, ori o poate muta el însuși mai
    // departe. Apăsarea de pe ecranul rămas în urmă ajungea aici ca orice
    // apăsare, iar ruta o scria: comanda anulată învia în tăcere, reapărea în
    // laborator și pe rută, iar pe canalele casei pleca și „Comandă refăcută"
    // — spre PATRON, nu spre cumpărător. Destinatarul oricărui anunț de etapă
    // se naște într-un singur loc, din trei setări ale casei
    // (`notify_email_to` / `notify_whatsapp_to` / `notify_telegram_chat_id`,
    // v. `send_channel()` din api/lib/notify.php); niciun anunț de etapă nu s-a
    // uitat vreodată la e-mailul sau la telefonul scrise pe comandă.
    //
    // Lipsa lui înseamnă „nu știu de pe ce plec" și se poartă exact ca până
    // acum: paginile vechi rămase în memoria unui browser nu se rup.
    //
    // Se verifică aici doar că e o etapă cunoscută; comparația cu baza se face
    // mai jos, SUB LACĂT (v. tranzacția). O etapă necunoscută e 400, nu 409:
    // nu s-a schimbat nimic între timp, ci cererea e strâmbă — cel mai des o
    // pagină rămasă cu o listă de etape redenumită de atunci din Setări.
    $from = null;
    if (array_key_exists('from', $b)) {
        $from = is_string($b['from']) ? $b['from'] : '';
        if (!in_array($from, order_statuses(), true)) {
            fail(
                'Etapa de plecare („from") nu e una cunoscută. Valori permise: '
                    . implode(', ', order_statuses()) . '. Reîncarcă pagina.',
                400,
                'bad_from'
            );
        }
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

    // Codul de recomandare, pus sau corectat pe o comandă deja făcută. Cazul
    // obișnuit: cineva a luat comanda la telefon și a uitat să întrebe „cine
    // v-a trimis?". Fără asta, singura reparație era prin phpMyAdmin.
    //
    // Se scrie AICI, în tranzacția antetului, deși cârligul de comisioane de
    // mai jos ar ști și el să-l scrie. Motivul: cârligul rulează după commit,
    // prins în try/catch, iar o cădere a lui ar înghiți în tăcere tocmai
    // corectura pentru care s-a deschis formularul. Scris în `$sets`, codul
    // rămâne salvat chiar dacă registrul de comisioane are o zi proastă —
    // comisionul se recalculează singur la următoarea atingere a comenzii.
    //
    // Tot de aici vine și că o salvare cu NUMAI codul schimbat nu mai cade în
    // „Nimic de modificat" (`!$sets`, mai jos).
    //
    // `referralCode` și `referralLink` sunt în afara lui ORDER_FIELDS_STAFF
    // dinadins: cine împarte banii nu e treaba contului de tură. Poarta de la
    // începutul rutei le oprește cu 403 înainte să se ajungă aici.
    $codNou   = null;
    // Pornește de la NU, nu de la DA.
    //
    // Steagul ăsta ajunge, mai jos, la `referral_commission_sync()`, care pe
    // `true` scrie legătura „pentru totdeauna" dintre cumpărător și
    // recomandant (api/lib/referral.php:1062-1064). Pornit de la `true`, el rămânea
    // așa la ORICE salvare care nu trimite un cod — iar cea mai obișnuită
    // salvare din patiserie e tocmai aceea: tableta din laborator mută
    // comanda o etapă, cu un singur câmp în cerere, `status`.
    //
    // Adică operatorul punea codul pe o comandă veche și lăsa bifa nebifată,
    // anume ca să NU lege contul; a doua zi cineva apăsa „La dospit" și
    // legătura se scria singură, pe la spate. Hotărârea lui era desfăcută de
    // o apăsare care n-avea nicio legătură cu banii.
    //
    // Legatul rămâne o faptă cerută pe față: numai bifa de mai jos îl aprinde.
    // Crearea unei comenzi nu trece pe aici — acolo cârligul e chemat cu
    // valoarea lui implicită (`:317`), deci un cod tastat la comanda nouă
    // leagă contul ca până acum.
    $refLeaga = false;
    if (array_key_exists('referralCode', $b)) {
        if (!referral_money_supported()) {
            fail(
                'Codurile de recomandare nu sunt disponibile: rulează întâi '
                  . 'sql/008_comisioane_recomandari.sql (și 007 înaintea lui).',
                503,
                'referral_money_unavailable'
            );
        }
        $codNou = referral_normalize_code((string) ($b['referralCode'] ?? ''));

        // Bifa din formular, nu un câmp al comenzii: spune dacă legătura
        // „pentru totdeauna" dintre cumpărător și recomandant are voie să se
        // scrie acum. Lipsa ei înseamnă NU, fiindcă o comandă reparată azi nu
        // e o hotărâre despre toate comenzile de mâine ale aceluiași om.
        $refLeaga = !empty($b['referralLink']);

        // Înaintea oricărei scrieri: vezi api/lib/referral.php.
        referral_guard_paid_code_change((int) $id, $codNou);

        // A doua pază, tot înaintea scrierii: codul care e chiar al
        // cumpărătorului. Refuzul e răspicat numai aici, unde omul e de față și
        // tocmai a tastat ceva; pe drumul de pe site comisionul nu se naște în
        // tăcere, dar cu urmă în jurnal. Lămurirea întreagă e la funcție.
        referral_guard_self_code((int) $id, $codNou);

        // A treia: rândul scos din socoteală de mâna patronului. Cârligul îl
        // lasă dinadins în pace, deci un cod nou n-ar avea niciun efect, iar
        // omul ar rămâne cu impresia că a reparat ceva. Aici e singurul loc de
        // unde i se poate spune — cârligul primește `null` mai jos și n-are de
        // unde ști că tocmai a tastat cineva ceva.
        referral_guard_dismissed_code((int) $id, $codNou);

        $sets[] = 'referral_code = ?';
        $vals[] = $codNou === '' ? null : $codNou;
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
        $items = cos_de_tarifat($b['items']);
        $implicit = require_variant($b['variant'] ?? null, valid_variant($o['variant']));
        $vechile  = all(
            'SELECT ' . honored_columns() . ' FROM order_items WHERE order_id = ? ORDER BY id',
            [(int) $id]
        );
        // Perechile deja pe comandă trec de poarta laturii; un rând NOU pe o
        // latură stinsă între timp, nu. Amândouă listele ies din aceeași hartă, deci
        // nu pot ieși din pas.
        $onorate = honored_prices($vechile);
        [$priced, $total] = price_items(
            $items,
            $implicit,
            $onorate,
            array_filter(array_column($vechile, 'product_id'), static fn($x) => $x !== null),
            array_keys($onorate)
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

    // Referința scoasă din $sets nu are voie să scoată salvarea din picioare:
    // o corectură numai de referință e o faptă întreagă, nu „nimic de
    // modificat”.
    if (!$sets && $refCerut === null) {
        fail('Nimic de modificat.', 422, 'nothing_to_update');
    }

    // POARTA PRAGULUI DE COMENZI NEPLĂTITE — întâi dacă are ce judeca.
    //
    // Hotărârea patronului, 4–5 octombrie 2026 (runda 2.7.0): a treia comandă
    // neplătită nu se mai primește. Corectarea unei comenzi nu naște o comandă,
    // deci aproape niciun PATCH nu trece pe la prag — adresa, pozițiile, ziua
    // și mutarea pe flux a unei comenzi neplătite se salvează ca ieri, chiar și
    // pentru un client ajuns la prag. Rămân DOUĂ feluri de salvare care îi pun
    // clientului o comandă în plus în socoteală fără să treacă prin `POST`:
    //
    //   a) comanda se leagă de ALT cont (un `customerId` trimis pe față, ori
    //      legătura pe care o face `client_by_name()` unei comenzi fără cont);
    //   b) comanda iese din etapa de anulare: până acum nu se număra, de acum da.
    //
    // Fără ele pragul ar avea o ușă din dos: comanda se scrie pe numele altcuiva
    // (ori se anulează și se reface) și ajunge tot la clientul oprit.
    //
    // Aici se hotărăște numai DACĂ cererea e de unul din cele două feluri, din
    // ce s-a trimis. Dacă într-adevăr schimbă ceva o spune rândul citit sub
    // lacăt, în tranzacție — `$o` de la intrarea în rută poate fi deja vechi.
    //
    // `plati_supported()` și `status_cancelled()` se întreabă AICI, înaintea
    // tranzacției, nu înăuntrul ei: prima citește din `information_schema`, a
    // doua din `settings`, iar orice citire fără lacăt făcută după
    // `beginTransaction()` îngheață imaginea bazei (REPEATABLE READ) — numărarea
    // de sub lacătul clientului n-ar mai vedea comanda scrisă adineauri din
    // altă filă. Măsurat pe MySQL 8.4, 5 octombrie 2026.
    $anulata       = status_cancelled();
    $pragDeJudecat = plati_supported()
        && (is_int($contNou) || ($statusNou !== null && $anulata !== '' && $statusNou !== $anulata));
    $pragOcolit = null;
    $pragPricina = '';

    // Antetul și pozițiile se schimbă împreună: altfel o eroare la mijloc lasă
    // comanda cu totalul nou și pozițiile vechi.
    $pdo = db();
    $refSchimbat = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $s = $sets;
        $v = $vals;
        // Și trecerea peste prag pornește de la zero la fiecare încercare: una
        // dată înapoi n-a scris nimic, deci n-are ce ajunge în jurnal.
        $pragOcolit  = null;
        $pragPricina = '';
        // Fiecare încercare pornește de la zero: dacă prima s-a izbit de un
        // „Duplicate entry" și a fost dată înapoi, referința ei nouă n-a fost
        // scrisă, deci nu are ce ajunge în jurnal.
        $refSchimbat = null;
        // Referința cu care rămâne comanda: cea tastată acum, dacă s-a tastat
        // una, altfel cea pe care o are. Se socotește la FIECARE încercare,
        // fiindcă blocul de mutare o poate schimba, iar a doua rulare trebuie
        // să pornească din nou de la ce a cerut omul.
        $refFinal = $refCerut ?? (string) $o['ref'];
        try {
            $pdo->beginTransaction();

            // RÂNDUL COMENZII, SUB LACĂT, ÎNAINTEA ORICĂREI SCRIERI — la fiecare
            // trecere prin buclă (runda 2.7.0: de când comenzile anulate se pot
            // șterge, rândul citit la intrarea în rută poate să nu mai fie).
            // Pricina întreagă e în antetul funcției chemate.
            comanda_sub_lacat_sau_404($pdo, (int) $id);

            // Comparația cu etapa văzută de om, dacă a trimis-o.
            //
            // Se citește SUB LACĂT, nu din `$o`: rândul de la intrarea în rută
            // s-a citit fără blocare, iar între el și clipa asta încape
            // întreagă mutarea făcută din alt panou. Cu `FOR UPDATE`, cine
            // ajunge al doilea așteaptă până se încheie prima scriere și abia
            // apoi compară — deci ori trece, ori pleacă cu 409, niciodată „a
            // citit vechiul și a scris peste". Aceeași măsură ca la acceptarea
            // unei cereri (api/routes/requests.php · `already_handled`).
            //
            // Refuzul se dă ÎNAINTE de orice scriere: nu se atinge depozitul,
            // nu se scrie în jurnal, nu se pune niciun anunț la coadă. Cele
            // două din urmă stau oricum după bucla asta, deci ieșirea de aici
            // le sare pe amândouă.
            if ($from !== null) {
                $acum = (string) (one(
                    'SELECT status FROM orders WHERE id = ? FOR UPDATE',
                    [(int) $id]
                )['status'] ?? '');
                if ($acum !== $from) {
                    $pdo->rollBack();
                    // `fail()` nu poate duce și etapa de acum, iar pagina are
                    // nevoie de ea ca să spună omului ce s-a întâmplat, fără
                    // încă o cerere. Aceeași formă ca la orice refuz —
                    // { error: { code, message } } —, cu un câmp în plus.
                    //
                    // Etapa stă între ghilimelele casei, „ … ”, ca la fratele
                    // lui de la ușa producției (`:1616`). Închidea cu ghilimeaua
                    // dreaptă, iar cele două refuzuri trebuie să fie copiate
                    // literă cu literă, nu doar asemănătoare (v. comentariul de
                    // la `:1600`). Paznicul e în `.claude/check-php.mjs`.
                    json_out([
                        'error' => [
                            'code'    => 'status_changed',
                            'message' => 'Comanda ' . (((string) $o['ref']) ?: '#' . $o['seq'])
                                . ' a fost schimbată între timp: acum e „' . $acum . '”. N-am mutat-o.',
                            'current' => $acum,
                        ],
                    ], 409);
                }
                // Etapa de acum, luată de sub lacăt, devine etapa „de dinainte"
                // pentru jurnalul și anunțurile de mai jos. `$o` s-a citit
                // înaintea tranzacției: când cele două se despart, adevărată e
                // asta, iar rândul de jurnal „Anulată → Predată" trebuie să
                // numească etapa de pe care s-a plecat cu adevărat.
                $o['status'] = $acum;
            }

            // POARTA PRAGULUI (runda 2.7.0) — vezi lămurirea de deasupra buclei.
            //
            // Stă DUPĂ comparația cu `from` și ÎNAINTEA oricărei scrieri: un
            // ecran rămas în urmă află întâi că etapa s-a schimbat (refuzul care
            // îi spune ce să facă), iar un refuz de prag nu apucă să întoarcă
            // marfă în depozit — `stock_unsync_order()` e chiar instrucțiunea
            // următoare.
            //
            // Rândul comenzii se citește CU LACĂT, și numai cu lacăt: o citire
            // cu `FOR UPDATE` vede ultima stare încheiată și nu îngheață
            // imaginea tranzacției, pe când una simplă ar îngheța-o înainte ca
            // `plati_lacat()` să fi așteptat cealaltă filă. Ordinea lacătelor e
            // comandă → client, aceeași pe care o avea deja ruta (UPDATE-ul de
            // mai jos ia rândul comenzii, iar cheia străină pe cel al
            // clientului); cine ține lacătul unui client nu cere niciodată
            // rândul unei comenzi vechi, deci nu se pot încrucișa.
            //
            // Clientul judecat e cel de DUPĂ salvare, iar comanda însăși iese din
            // numărătoare (`'comanda' => $id`): se întreabă câte ALTE comenzi
            // neplătite are el, exact ca la una nouă. Astăzi excluderea nu
            // schimbă nicio cifră — poarta stă înaintea scrierii, deci comanda e
            // încă anulată ori încă pe contul vechi și nu s-ar număra oricum
            // (proba cu excluderea scoasă rămâne verde). E plasa pentru ziua în
            // care poarta ar ajunge după UPDATE: atunci, fără ea, comanda s-ar
            // număra împotriva ei însăși. Plata nu se schimbă pe ușa asta (are
            // ruta ei), deci cea citită acum e și cea de după salvare.
            // O comandă încasată ori una care rămâne anulată nu se numără, deci
            // nici nu are de ce să fie oprită.
            if ($pragDeJudecat) {
                $viu = one('SELECT status, customer_id, plata FROM orders WHERE id = ? FOR UPDATE', [(int) $id]);
                if ($viu) {
                    $contVechi  = $viu['customer_id'] !== null ? (int) $viu['customer_id'] : null;
                    $contDupa   = $contNou === false ? $contVechi : $contNou;
                    $etapaVeche = (string) $viu['status'];
                    $etapaDupa  = $statusNou ?? $etapaVeche;
                    $mutata     = is_int($contNou) && $contNou !== $contVechi;
                    $inviata    = $anulata !== '' && $etapaVeche === $anulata && $etapaDupa !== $anulata;
                    $seNumara   = in_array((string) $viu['plata'], GP_PLATI_NEINCASATE, true)
                        && ($anulata === '' || $etapaDupa !== $anulata);
                    if ($contDupa !== null && $seNumara && ($mutata || $inviata)) {
                        $pragOcolit = plati_poarta($contDupa, $u, $b, ['comanda' => (int) $id]);
                        // Când s-au întâmplat amândouă deodată, mutarea e cea
                        // care spune al cui a devenit soldul.
                        $pragPricina = $mutata ? 'mutată pe contul lui' : 'scoasă din „' . $anulata . '”';
                    }
                }
            }

            // Returul se scrie ÎNAINTE de rescrierea pozițiilor. De când
            // ieșirile poartă `order_id`, calea obișnuită citește din registru
            // și n-ar mai avea nevoie de ordinea asta — dar plasa pentru
            // comenzile scăzute înainte de reparație recalculează tot din
            // `order_items`, iar după `DELETE FROM order_items` acolo nu mai
            // există nimic. Fiind în aceeași tranzacție, o cădere la mijloc nu
            // lasă marfa întoarsă pe jumătate.
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
                // Ciocnirea se caută pe referința FINALĂ, nu pe cea veche: dacă
                // administratorul a tastat una acum, ea e cea care ajunge în
                // ziua nouă, deci ea e cea care se poate izbi de o comandă de
                // acolo. Căutată pe cea veche, referința tastată trecea
                // neverificată și pica în MySQL pe UNIQUE(day, ref).
                $refNou = $refFinal;
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
                    $refFinal    = $candidat;
                }
            }

            // Singurul loc din toată ruta unde `ref` intră în SET.
            if ($refCerut !== null || $refSchimbat !== null) {
                $s[] = 'ref = ?';
                $v[] = $refFinal;
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
                // Prețul tăiat se rescrie odată cu poziția, din catalogul de
                // acum — perechea lui, prețul plătit, tocmai s-a socotit din
                // același catalog. Fără el aici, reducerea de pe o comandă ar
                // rămâne fără explicație de la prima atingere a comenzii, adică
                // de la prima mutare de etapă din laborator. Treapta, la fel: fără
                // ea, o corectură de mâine n-ar mai ști ce prag a câștigat prețul.
                [$sqlPozitie, $cuLista, $cuTrepte] = pozitie_insert(
                    'order_items', 'order_id, product_id, name, variant, qty, done_qty, unit_price'
                );
                foreach ($priced as $p) {
                    $cheie = ((int) $p['id']) . '|' . $p['variant'];
                    // Plafonat la cantitatea nouă: cine taie comanda de la 8 la 5
                    // după ce s-au făcut 6 nu are 6 făcute din 5. A șasea bucată nu
                    // se pierde — intrarea ei pe articolul finit e deja în registru
                    // și rămâne acolo, exact ca la o comandă anulată.
                    $done  = min((float) $p['qty'], (float) ($ramas[$cheie] ?? 0));
                    $ramas[$cheie] = ($ramas[$cheie] ?? 0) - $done;
                    q($sqlPozitie, pozitie_valori(
                        [(int) $id, $p['id'], $p['name'], $p['variant'], $p['qty'], round($done, 2), $p['unit']],
                        $cuLista, $cuTrepte, $p['catalog'] ?? null, $p['treapta'] ?? 1, $p['prag'] ?? null
                    ));
                }
                // Rezervările vechi au plecat odată cu pozițiile vechi
                // (`ON DELETE CASCADE` pe `order_item_id`); aici se scriu cele
                // de pe pozițiile de acum.
                stock_reserve_order((int) $id);
            }
            $pdo->commit();
            break;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Se reia și IMPASUL (1213), nu numai numărul zilei luat de altul:
            // zăvorul zilei din laborator ia lacătele pe dos față de o mutare
            // pe altă zi, iar un impas desface trecerea întreagă, ca o cheie
            // unică izbită.
            if (($e->getCode() !== '23000' && (int) ($e->errorInfo[1] ?? 0) !== 1213) || $attempt === 4) {
                throw $e;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $detail = 'Comandă #' . $o['seq'];
    // „Predată → Predată" nu e o trecere. Era scris pentru ORICE cerere care
    // aducea o etapă, chiar și aceeași — iar butonul de la ultima etapă din
    // laborator trimitea exact așa (v. `advance()`, Panou Laborator.dc.html).
    // Jurnalul nu se golește niciodată, deci fiecare apăsare în gol rămânea
    // acolo, arătând leit cu o mutare adevărată.
    $etapaNoua = isset($b['status']) && (string) $b['status'] !== (string) $o['status'];
    if ($etapaNoua) {
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
    // Pe codul ăsta se împart bani, deci schimbarea lui nu are voie să fie
    // tăcută: la sfârșitul lunii, un comision apărut din senin pe o comandă
    // veche trebuie să poată fi urmărit până la cine l-a pus și când.
    if ($codNou !== null && $codNou !== (string) ($o['referral_code'] ?? '')) {
        $detail .= ' · cod de recomandare: ' . (($o['referral_code'] ?? '') ?: '—')
            . ' → ' . ($codNou ?: '—')
            . ($refLeaga ? ' (și contul cumpărătorului s-a legat)' : '');
    }
    // Referința e singurul lucru pe care clientul îl ține minte. Schimbată în
    // tăcere, el ar suna a doua zi cu un număr care nu mai există nicăieri.
    if ($refSchimbat !== null) {
        // Urma pornește de la ce a CERUT omul, când a cerut ceva: altfel ar
        // duce la referința veche, pe care n-a mai vrut-o nimeni, iar cel care
        // caută a doua zi n-ar găsi nicăieri numărul pe care l-a tastat.
        $detail .= ' · referință ' . ($refCerut !== null ? 'cerută' : 'schimbată') . ': '
            . $refSchimbat[0] . ' → ' . $refSchimbat[1]
            . ' (era deja folosită în ziua nouă)';
    } elseif ($refCerut !== null && $refCerut !== (string) $o['ref']) {
        // Referința e singurul lucru pe care clientul îl ține minte, deci
        // schimbată de mână lasă urmă chiar și când a intrat curat.
        $detail .= ' · referință: ' . (((string) $o['ref']) ?: '—') . ' → ' . $refCerut;
    }
    // UN RÂND CARE NU SPUNE NIMIC NU SE SCRIE.
    //
    // Poarta e îngustă dinadins, și se aplică unui singur fel de cerere: cea
    // care aduce NUMAI o etapă, și aceeași. Atunci nu s-a schimbat nimic, deci
    // n-are ce să intre în registru.
    //
    // Orice altă salvare scrie mai departe, chiar și când detaliul rămâne scurt.
    // Rândurile de mai sus numesc etapa, prețul onorat, clientul, contul,
    // returul, codul și referința — dar NU adresa, telefonul, ziua, intervalul
    // sau pozițiile. O corectură de adresă lasă deci un „Comandă #84" simplu:
    // puțin, dar adevărat, și singura urmă că a umblat cineva la comandă. Aici
    // nu se taie aceea — se taie numai apăsarea în gol.
    $doarEtapaNeschimbata = isset($b['status']) && !$etapaNoua
        && $detail === 'Comandă #' . $o['seq'];
    if (!$doarEtapaNeschimbata) {
        audit('order_update', 'order', $id, $detail);
    }

    // Un singur cârlig acoperă TOATE drumurile prin care se schimbă o comandă,
    // fiindcă `orders.status` se scrie într-un singur loc din tot codul: chiar
    // ruta asta. Inclusiv butonul „Anulează comanda", care trimite un
    // PATCH pe etapa de anulare — v. `askDelete` din „Administrare Comenzi".
    // Numele funcției, nu numărul rândului: rândurile paginii se mută la
    // fiecare lucrare, iar o trimitere putrezită duce cititorul în alt cod.
    //
    // De aceea anularea și dezanularea se poartă simetric fără să scrie nimeni
    // regula a doua oară: `referral_commission_sync()` recitește starea
    // comenzii și pune „anulat" sau „activ" după cum o găsește. La fel pentru
    // totalul schimbat — pozițiile rescrise mută baza de calcul, deci și suma.
    //
    // Prins în try/catch, ca la creare: comanda e deja salvată și comisă.
    $comision = null;
    try {
        $comision = referral_commission_sync((int) $id, null, (int) $u['id'], $refLeaga);
    } catch (Throwable $e) {
        error_log('[gp] comision nerecalculat pentru comanda ' . $id . ': ' . $e->getMessage());
    }

    $out = attach_items([one('SELECT * FROM orders WHERE id = ?', [(int) $id])], true, true, plati_fel($u))[0];

    // Trecerea peste prag lasă urmă, ca la comanda nouă: după commit, cu
    // referința cu care a rămas comanda (o mutare pe altă zi o poate schimba).
    if ($pragOcolit !== null) {
        plati_jurnal_ocolire($pragOcolit, 'comanda ' . $out['ref'] . ', ' . $pragPricina);
    }

    $out['commission'] = $comision === null ? null : [
        'id'       => (int) $comision['id'],
        'referrer' => $comision['referrer_id'] !== null ? (int) $comision['referrer_id'] : null,
        'label'    => $comision['referrer_label'],
        'amount'   => round((float) $comision['amount'], 2),
        'rate'     => round((float) $comision['rate'], 2),
        'status'   => (string) $comision['status'],
        'paid'     => $comision['paid_at'] !== null,
        // „Bifa a lucrat?" Nu „am trimis bifa", ci „s-a scris legătura".
        // Contul putea avea deja un recomandant, caz în care bifa n-a
        // schimbat nimic — iar panoul are voie s-o spună pe față.
        'linked'   => !empty($comision['legat_acum']),
    ];

    // COMANDA ȘTEARSĂ ÎNTRE `commit` ȘI RECITIRE (runda 2.7.0). Lacătul de la
    // începutul tranzacției ține ștergerea afară cât se scrie; odată comisă
    // salvarea, rândul e iar liber, iar o ștergere încăpută exact aici face
    // ca recitirea de mai sus să nu mai aducă nimic. Fără paza asta, pagina
    // primea `200` cu o comandă fără `id`, fără etapă și fără poziții — și
    // încerca s-o deseneze. Refuzul e cel pe care l-ar fi primit venind o
    // clipă mai târziu; anunțurile de mai jos nu mai pleacă, fiindcă n-au
    // despre ce.
    if (!isset($out['id'])) {
        fail('Comanda nu există.', 404, 'not_found');
    }

    // Faptele pe care patronul le poate cere pe telefon: comanda a ajuns gata,
    // a fost anulată, ori a fost refăcută după o anulare. Locul e ales, nu
    // întâmplător: `orders.status` se scrie într-un SINGUR loc în tot programul —
    // ruta asta. Aici ajung butonul „Anulează comanda" (un PATCH pe etapa de
    // anulare), perechea lui „Reface comanda" (un PATCH pe începutul fluxului),
    // lista de etape din formular și din fișa deschisă din listă, tableta din
    // laborator și ruta șoferului. Un cârlig aici acoperă toate drumurile,
    // exact ca `referral_commission_sync()` de deasupra.
    //
    // Se întreabă întâi, se trimite abia apoi. Fără întrebare, FIECARE apăsare
    // de „La dospit" de pe tableta din laborator — sutele zilei — ar pleca pe
    // drumul lung al lui `json_out_early()`, ca să nu anunțe nimic. Întrebarea
    // cuprinde TOATE anunțurile trecerii, nu doar primul: o comandă anulată
    // mutată pe „Predată" e și refăcută, și gata, iar un canal bifat numai la
    // una dintre ele trebuie să-și primească mesajul.
    $vechi     = (string) $o['status'];
    $nou       = (string) $out['status'];
    $deAnuntat = array_filter(
        array_keys(notify_order_status_events($vechi, $nou)),
        static fn(string $e): bool => (bool) notify_channels_for($e)
    );
    if (!$deAnuntat) {
        json_out(['order' => $out]);
    }

    // De aici încolo, ca la comanda nouă: răspunsul pleacă primul, anunțurile
    // pe urmă. Un furnizor căzut ține conexiunea cincisprezece secunde, iar
    // tableta din laborator ar rămâne cu butonul apăsat tot atâta.
    json_out_early(['order' => $out]);
    try {
        notify_order_status($out, $vechi, $nou);
    } catch (Throwable $e) {
        error_log('[gp] anunț de etapă nedus pentru comanda ' . $id . ': ' . $e->getMessage());
    }
    exit;
}

/* ------------------------------------------- ștergerea comenzii anulate -- */

/**
 * DELETE /orders/# — scoate din registru o comandă DEJA anulată și îi lasă în
 * urmă poza.
 *
 * CEREREA PATRONULUI, 4 octombrie 2026 (runda 2.7.0): pe o comandă deja
 * anulată, butonul să întrebe dacă vrei s-o ștergi; ștearsă, să dispară din
 * registru — „dar dacă văd ștergerea, să pot vedea ce era comanda".
 *
 * E PRIMA RUTĂ DIN CASĂ CARE SCOATE UN RÂND DIN `orders`. Până la ea, „a
 * șterge" a însemnat mereu „a anula": un PATCH pe etapa de anulare, cu rândul
 * rămas pe loc (v. `route_orders_update`). De aceea tot restul programului a
 * putut socoti că un număr de comandă văzut o dată există pe vecie; cine mai
 * scrie de acum încolo o rută care ține minte un `order_id` peste două cereri
 * trebuie să se întrebe ce face când nu-l mai găsește.
 *
 * DOI PAȘI, DINADINS: întâi „Anulează", apoi, pe comanda anulată, „Șterge". O
 * comandă vie nu se poate șterge dintr-o apăsare, nici din pagină, nici cu un
 * curl — refuzul `not_cancelled` e aici, nu în buton. Anularea e cea care
 * face curat în lume (întoarce marfa în depozit, stinge comisionul, anunță pe
 * telefon); ștergerea doar scoate rândul.
 *
 * CE PLEACĂ ODATĂ CU RÂNDUL — cheile străine, numărate pe migrări (001, 006,
 * 008, 021), nu luate de bune:
 *
 *   `order_items`, `stock_reservations`, `referral_commissions`   CASCADE
 *   `stock_moves`, `lab_production`, `notifications`              SET NULL
 *   `order_requests.order_id`                                     SET NULL
 *
 * Primele trei dispar, deci tot ce trebuie ținut minte din ele intră în poză
 * ÎNAINTEA ștergerii, în aceeași tranzacție. Celelalte rămân, fără legătură:
 * ce s-a copt s-a copt, ce s-a anunțat s-a anunțat, iar cererea din care s-a
 * născut comanda rămâne „acceptata", fără comandă. Niciuna nu are RESTRICT,
 * deci ștergerea nu se poate izbi de o cheie străină.
 *
 * NUMĂRUL SE ELIBEREAZĂ. Numerotarea e pe zi (`uq_orders_day_seq`), iar
 * următoarea comandă a zilei ia `MAX(seq) + 1` — deci chiar numărul de aici,
 * dacă era ultimul. „#1012" de mâine poate fi altă comandă decât „#1012" de
 * azi. De aceea poza își ține referința ei, iar Jurnalul nu mai dă, pentru o
 * comandă ștearsă, niciun `ref` de urmat (`log_subiect()`, api/routes/log.php).
 *
 * CE NU FACE, tot dinadins:
 *   · nu trimite niciun anunț — patronul a aflat de la anulare, iar o comandă
 *     ștearsă nu mai are ce spune nimănui;
 *   · nu atinge stocul — marfa s-a întors la anulare (`stock_unsync_order()`);
 *     rândurile din `stock_reservations` ale unei comenzi anulate sunt încă
 *     acolo, dar nu se numără (rezervarea deschisă se DERIVĂ din etapă, v.
 *     `stock_reserve_order()`), și pleacă acum prin CASCADE;
 *   · nu atinge cererea — rămâne „acceptata": a fost acceptată, asta e istorie.
 *
 * Răspuns: `{ ok: true, sters: { id, ref, client, zi, total, produse } }` —
 * destul cât pagina să spună ce a șters, fără a doua cerere (care ar da 404).
 */
function route_orders_delete(string $id): void
{
    // DREPTUL ÎNAINTEA ORICĂREI CITIRI, ca la `route_orders_get` și la
    // `route_orders_update`. Cu citirea întâi, un cont fără drept ar primi 404
    // pe numerele care nu există și 403 pe cele care există — adică ar putea
    // număra comenzile casei bătând la ușă. Cheia e din „Acces" (migrarea 033):
    // din pornire administratorul și fondatorul, și cere panoul `comenzi`.
    $u = require_drept('comenzi.sterge');

    // Marginea agentului, pentru ziua în care fondatorul îi dă cheia din
    // „Acces": șterge numai comenzile cumpărătorilor aduși de el. Aceeași
    // margine și același mesaj ca la `route_orders_update` — o comandă pe care
    // n-are voie s-o schimbe n-are cum să aibă voie s-o șteargă. Stă înaintea
    // tranzacției: `fail()` încheie scriptul, iar cu tranzacția deschisă ar
    // lăsa rândul zăvorât.
    if ($u['role'] === 'agent_vanzari' && !referral_agent_sees_order((int) $u['id'], (int) $id)) {
        fail('Comanda nu e a unui cumpărător adus de tine.', 403, 'forbidden');
    }

    // Fără migrarea 035 n-are unde rămâne poza, deci nu se șterge nimic: o
    // ștergere fără urmă e exact ce n-a vrut patronul. Refuzul numește migrarea
    // cu fișierul ei, nu cu numărul: omul de la telefon are ce-i spune celui
    // care ține serverul, iar acela are în față un dosar `sql/`, nu o numărătoare.
    if (!comenzi_sterse_supported()) {
        fail(
            'Ștergerea comenzilor nu e pornită încă pe server: rulează întâi sql/035_comenzi_sterse.sql.',
            503,
            'comenzi_sterse_indisponibil'
        );
    }

    // NUMĂRUL ÎNTREG SAU NIMIC. `PATCH /orders/12abc` lucrează pe comanda 12,
    // fiindcă `(int)` taie coada — acolo e o stângăcie fără urmări. Aici ar fi
    // șters comanda 12 pentru o adresă care nu o numește, iar rândul de Jurnal
    // ar fi intrat sub cheia „12abc", unde istoria comenzii nu l-ar mai fi
    // găsit niciodată. O ușă care șterge nu ghicește.
    if (!ctype_digit($id)) {
        fail('Comanda nu există.', 404, 'not_found');
    }
    $nr = (int) $id;

    // IMPASUL SE REIA, NU I SE ARATĂ OMULUI (reparația din 5 oct. 2026).
    //
    // Ștergerea ia întâi rândul comenzii și abia la `DELETE` înregistrările
    // din indici; zăvorul zilei din laborator (`lab_ziua_si_produsul()`) vine
    // pe dos, printr-un indice care începe cu ziua (pe care anume, hotărăște
    // motorul — v. acolo). Când se prind una pe alta, MySQL
    // omoară una din ele cu 1213 — iar dacă aceea e ștergerea, omul care a
    // apăsat „Șterge" pe o comandă anulată primea „Eroare internă" pentru o
    // apăsare nevinovată, cu comanda rămasă pe loc.
    //
    // Reluarea e curată fiindcă TOT ce scrie ștergerea — poza, rândul scos,
    // rândul de Jurnal — stă într-o singură tranzacție, iar un impas o desface
    // întreagă: încercarea omorâtă n-a lăsat nimic în urmă. A doua oară se
    // așază cuminte la coadă pe rândul comenzii, în spatele celei care a
    // câștigat. Numai 1213: orice altă eroare e o defecțiune adevărată și
    // rămâne o defecțiune. Trei încercări, nu o buclă fără capăt — o bază care
    // dă impas de trei ori la rând are altceva de spus decât „mai încearcă".
    $pdo = db();
    for ($incercare = 1; ; $incercare++) {
        try {
            $sters = comanda_sterge_sub_lacat($pdo, $nr, $u);
            break;
        } catch (PDOException $e) {
            if ($incercare >= 3 || (int) ($e->errorInfo[1] ?? 0) !== 1213) {
                throw $e;
            }
        }
    }

    json_out(['ok' => true, 'sters' => $sters]);
}

/**
 * Tranzacția ștergerii unei comenzi anulate, de la primul lacăt la `commit`.
 *
 * Despărțită de `route_orders_delete()` ca să poată fi LUATĂ DE LA CAPĂT după
 * un impas (v. bucla de acolo): porțile rămân în rută și se întreabă o singură
 * dată, iar aici stă numai ce se desface întreg odată cu tranzacția.
 *
 * Refuzurile cu nume (`not_found`, `not_cancelled`, `commission_paid`,
 * `order_paid`, `already_deleted`) încheie scriptul de aici, după ce au dat
 * tranzacția înapoi. Orice altceva — și impasul — iese ca excepție, tot cu
 * tranzacția dată înapoi.
 *
 * @param  array<string, mixed> $u  contul care șterge (din `require_drept()`)
 * @return array{id: int, ref: string, client: ?string, zi: string, total: float, produse: int}
 *         ce pleacă spre pagină în `sters`
 */
function comanda_sterge_sub_lacat(PDO $pdo, int $nr, array $u): array
{
    $pdo->beginTransaction();

    // `fail()` încheie scriptul pe loc; chemat cu tranzacția deschisă, ar lăsa
    // rândul comenzii prins cu FOR UPDATE până când găzduirea închide
    // conexiunea. Toate refuzurile de mai jos trec pe aici — aceeași formă ca
    // în `route_order_production`, de unde e copiat: `$peDeasupra` duce etapa
    // de acum în `error.current`, pentru refuzul care are de spus mai mult
    // decât un text.
    $opreste = static function (string $mesaj, int $status, string $cod, array $peDeasupra = []) use ($pdo): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$peDeasupra) {
            fail($mesaj, $status, $cod);
        }
        json_out(['error' => array_merge(
            ['code' => $cod ?: (string) $status, 'message' => $mesaj],
            $peDeasupra
        )], $status);
    };

    try {
        // 1. RÂNDUL COMENZII, SUB LACĂT — ȘI ÎNAINTEA ORICĂRUI ALT LACĂT.
        //
        // Cine SCRIE într-o comandă ia întâi rândul ei din `orders` și abia
        // apoi umblă la copiii lui: `route_orders_update` (la începutul
        // fiecărei treceri, `comanda_sub_lacat_sau_404()`),
        // `route_order_production`, `route_stock_sync`. Aceeași ordine aici; pe
        // dos, o ștergere și un PATCH care rescrie pozițiile s-ar aștepta una
        // pe alta până când MySQL ar omorî una din ele cu „Deadlock found" —
        // adică 500 pentru o apăsare nevinovată.
        //
        // NU ȘI LABORATORUL. Aici scria că și împărțirea producției ia întâi
        // rândul; nu-l ia. Zăvorul zilei (`lab_ziua_si_produsul()`) citește
        // `WHERE day = ? … FOR UPDATE`, deci intră printr-un indice care începe
        // cu ziua (sunt trei; pe care îl ia, hotărăște motorul):
        // prinde întâi înregistrarea din INDICE și abia apoi rândul. Ștergerea
        // vine pe dos — are rândul și, la `DELETE`, cere înregistrările din
        // indici. `ORDER BY id` de acolo nu ajută: sortarea se face după ce
        // lacătele s-au luat, în ordinea citirii. Cele două se pot deci prinde
        // în impas (văzut pe viu, 5 oct. 2026: 1213, cu oricare din ele
        // victimă). Ordinea de aici nu se poate întoarce fără s-o strice pe cea
        // cu PATCH-ul, pe care o cere contractul — de aceea încercarea omorâtă
        // se ia de la capăt, v. bucla din `route_orders_delete()`.
        //
        // Lacătul e și paza celor două curse:
        //   · două ștergeri deodată — a doua așteaptă aici, iar când îi vine
        //     rândul nu mai găsește nimic: 404, niciodată 500 pe cheia unică a
        //     arhivei;
        //   · ștergere și „Reface comanda" deodată — dacă PATCH-ul a ajuns
        //     primul, ștergerea citește aici etapa NOUĂ și pleacă cu
        //     `not_cancelled`; dacă a ajuns al doilea, își ia și el rândul sub
        //     lacăt înaintea oricărei scrieri, nu-l mai găsește și pleacă cu
        //     404, fără să fi scris nimic.
        //
        // `SELECT *`, nu o listă de coloane: rândul intră ÎNTREG în poză (v.
        // `comanda_stearsa_poza()`), și din același rând se citește `plata`.
        $o = one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$nr]);
        if (!$o) {
            // Și când a șters-o altcineva cu o clipă înainte.
            $opreste('Comanda nu există.', 404, 'not_found');
        }

        // Numele comenzii în mesaje, cum îl scrie `attach_items()`: referința,
        // ori „#" + (1000 + numărul zilei) când referința e goală.
        $ref = ((string) ($o['ref'] ?? '')) ?: '#' . (1000 + (int) $o['seq']);

        // 2. NUMAI O COMANDĂ ANULATĂ.
        //
        // Etapa de anulare se cere de la `status_cancelled()` — niciodată
        // „Anulată" scris pe litere: lista de etape se schimbă din Setări. Când
        // funcția întoarce șirul gol (lista n-are etapă de anulare), NICIO
        // comandă nu e „anulată". Fără paza pe gol, comparația ar fi lăsat să
        // se șteargă tocmai comenzile cu etapa goală — adică o setare nepusă ar
        // fi deschis ștergerea pe comenzi vii.
        //
        // Forma refuzului e a ușii de etapă din `route_orders_update`:
        // { error: { code, message, current } }. Pagina citește `current` ca să
        // spună „nu mai e anulată: acum e …" fără încă o cerere.
        $anulata = status_cancelled();
        $etapa   = (string) $o['status'];
        if ($anulata === '' || $etapa !== $anulata) {
            $opreste(
                'Comanda ' . $ref . ' nu e anulată: acum e „' . $etapa . '”. Se șterg numai comenzile anulate.',
                409,
                'not_cancelled',
                ['current' => $etapa]
            );
        }

        // Pozițiile, tot sub lacăt și în ordinea `id` — a doua treaptă a
        // aceleiași ordini (întâi comanda, apoi pozițiile ei), ca în
        // `route_order_production`. Citirea CU lacăt vede ultimul rând comis,
        // nu fotografia tranzacției: poza trebuie să fie a pozițiilor care
        // chiar pleacă, nu a celor de dinaintea unui PATCH încheiat cât am
        // așteptat noi la ușă.
        $pozitii = all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id FOR UPDATE', [$nr]);

        // 3. COMISION DEJA PLĂTIT → NU SE ȘTERGE.
        //
        // Rândul din `referral_commissions` pleacă odată cu comanda (CASCADE),
        // iar cu el ar pleca singura urmă că s-au dat bani cuiva: la sfârșitul
        // lunii, suma ieșită din casă n-ar mai avea nicio comandă de care să
        // se lege. Un comision NEPLĂTIT nu oprește nimic — pe o comandă anulată
        // e oricum stins — dar intră în poză, să se vadă că a existat.
        //
        // Tabelul vine abia cu migrarea 008: fără ea nu se întreabă nimic.
        // `FOR UPDATE` și aici, fiindcă bifa „plătit" din Recomandări
        // (`referral_commission_pay()`) nu ia lacătul comenzii: fără el, o
        // bifă pusă în aceeași clipă ar fi căzut pe un rând pe care noi tocmai
        // l-am citit ca neplătit și îl ștergem.
        $comision = null;
        if (referral_money_supported()) {
            $comision = one('SELECT * FROM referral_commissions WHERE order_id = ? FOR UPDATE', [$nr]);
            if ($comision !== null && $comision['paid_at'] !== null) {
                $opreste(
                    'Pentru comanda ' . $ref . ' s-a plătit deja un comision de recomandare. '
                        . 'O comandă cu bani dați nu se șterge.',
                    409,
                    'commission_paid'
                );
            }
        }

        // 4. PLATĂ TRECUTĂ → NU SE ȘTERGE.
        //
        // „Plătită" și „Plătită prin bancă" înseamnă bani intrați în casă pe
        // comanda asta. Dacă s-au dat înapoi la anulare, omul o spune întâi în
        // Încasări; altfel ar dispărea o comandă cu banii încă trecuți pe ea.
        //
        // Se citește din rândul luat mai sus, cu `array_key_exists` și fără
        // nicio interogare în plus: coloana vine cu migrarea 032, iar pe o bază
        // fără ea cheia lipsește din rând și refuzul pur și simplu nu se
        // aprinde. Nicio funcție din partea de plăți nu e chemată — ușa asta
        // trebuie să meargă și acolo unde partea aceea nu există.
        if (array_key_exists('plata', $o) && in_array((string) $o['plata'], ['platita', 'banca'], true)) {
            $opreste(
                'Comanda ' . $ref . ' e trecută ca plătită. Dacă banii s-au dat înapoi, '
                    . 'trece-o întâi pe „Neplătită”, din Încasări, apoi șterge-o.',
                409,
                'order_paid'
            );
        }

        // 5. POZA, ÎNAINTEA ȘTERGERII.
        //
        // Cererea din care s-a născut comanda (patru câmpuri; ea nu se șterge)
        // și numele celui care a scris-o — luat acum, cât contul mai are nume.
        $cerere = null;
        if (($o['request_id'] ?? null) !== null) {
            $cerere = one(
                'SELECT id, status, created_at, customer_name FROM order_requests WHERE id = ?',
                [(int) $o['request_id']]
            );
        }
        $autor    = ($o['created_by'] ?? null) === null ? null : (int) $o['created_by'];
        $scrisaDe = autor_comanda($autor, autorii_comenzilor([$autor]));

        // Clipa ștergerii, în UTC, ca restul bazei — aceeași în poză și în
        // coloana `deleted_at`, ca cele două să nu se certe la o secundă.
        $clipa = gmdate('Y-m-d H:i:s');

        // Aruncă dacă poza nu se poate scrie: tranzacția se desface mai jos și
        // comanda rămâne neatinsă. Nu se șterge nimic fără urmă.
        $poza = comanda_stearsa_poza($o, $pozitii, $comision, $cerere, $scrisaDe, $u, $clipa);

        // Totalul e cel al casei: coloana `orders.total` (e în schemă din 001 și
        // tot pe ea o dă `attach_items()`), nu o sumă refăcută aici din poziții.
        $total = round((float) $o['total'], 2);

        // Coloanele de lângă JSON vin din ACELAȘI rând, ca Jurnalul să poată
        // căuta și arăta fără să desfacă poza.
        //
        // `order_id` e unic în arhivă. Un duplicat nu poate apărea cât timp
        // numărătoarea din `orders` merge înainte — dar pe un MySQL vechi ori pe
        // un MariaDB ea pornește de la capăt după o repornire, dacă ultimele
        // rânduri au fost șterse, iar atunci o comandă NOUĂ ia numărul uneia
        // șterse ieri. Numai 1062 (rând duplicat), ca în `q_unique()`: orice
        // altceva e o defecțiune adevărată și rămâne o defecțiune. Nu se șterge
        // nimic: poza veche n-are voie să fie călcată de una nouă.
        try {
            q(
                'INSERT INTO orders_deleted
                    (order_id, ref, seq, day, customer_id, customer_name, total, request_id,
                     snapshot, deleted_by, deleted_by_label, deleted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $nr,
                    $o['ref'],
                    (int) $o['seq'],
                    (string) $o['day'],
                    $o['customer_id'] === null ? null : (int) $o['customer_id'],
                    $o['customer_name'],
                    $total,
                    ($o['request_id'] ?? null) === null ? null : (int) $o['request_id'],
                    $poza,
                    (int) $u['id'],
                    mb_substr((string) $u['name'], 0, 128),
                    $clipa,
                ]
            );
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $opreste(
                'În arhiva comenzilor șterse există deja o comandă cu același număr de rând (' . $nr
                    . '), deci n-am șters-o pe ' . $ref . ': poza celei vechi s-ar fi pierdut. '
                    . 'Spune-i celui care ține serverul.',
                409,
                'already_deleted'
            );
        }

        // 6. ȘTERGEREA. Pozițiile, rezervările și comisionul pleacă prin
        // CASCADE; mișcările de stoc, producția, anunțurile și cererea rămân,
        // cu legătura pusă pe NULL.
        q('DELETE FROM orders WHERE id = ?', [$nr]);

        // 7. JURNALUL, ÎNĂUNTRUL TRANZACȚIEI.
        //
        // În restul casei `audit()` stă după `commit` — un rând de jurnal
        // pierdut nu strică o comandă salvată. Aici e pe dos: o ștergere fără
        // rând în Jurnal e o comandă dispărută fără urmă, deci cele două se
        // scriu împreună ori deloc.
        //
        // Sumele ca în Jurnalul comenzilor („preț onorat", `price_honored_note()`):
        // virgulă, două zecimale, „lei". Ziua cum o ține baza, AAAA-LL-ZZ — așa
        // o scrie casa peste tot unde o pune într-un text (v. `anunt_rand_zi()`).
        // Fără telefon și fără adresă: Jurnalul e citit de cine are panoul
        // „Jurnal", iar acolo n-au ce căuta datele de contact ale clientului.
        $n = count($pozitii);
        audit(
            'order_delete',
            'order',
            (string) $nr,
            'Comandă ' . $ref . ' ștearsă definitiv · ' . (((string) ($o['customer_name'] ?? '')) ?: '—')
                . ' · ' . (string) $o['day']
                . ' · ' . ($n === 1 ? '1 produs' : $n . ' ' . ro_de($n) . 'produse')
                . ' · ' . number_format($total, 2, ',', '.') . ' lei'
        );

        // 8. Poza își ține minte rândul de Jurnal. `lastInsertId()` IMEDIAT
        // după `audit()`: funcția aceea își face citirile întâi și se încheie cu
        // un singur INSERT, deci numărul de aici e al rândului abia scris. O
        // interogare strecurată între cele două rânduri ar lega poza de altceva.
        $jurnal = (int) $pdo->lastInsertId();
        if ($jurnal > 0) {
            q('UPDATE orders_deleted SET audit_id = ? WHERE order_id = ?', [$jurnal, $nr]);
        }

        // 9.
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'id'      => $nr,
        'ref'     => $ref,
        'client'  => $o['customer_name'],
        'zi'      => (string) $o['day'],
        'total'   => $total,
        // Câte POZIȚII avea, nu câte bucăți.
        'produse' => $n,
    ];
}

/**
 * Rândul comenzii, luat sub lacăt la ÎNCEPUTUL tranzacției — ori 404.
 *
 * Chemată din `route_orders_update`, la fiecare trecere prin bucla ei.
 *
 * DE CE EXISTĂ (runda 2.7.0, cititorul potrivnic din 5 oct. 2026). Până la
 * `route_orders_delete()` un rând din `orders` nu dispărea niciodată, deci
 * PATCH-ul și-a putut citi comanda o singură dată, FĂRĂ lacăt, la intrarea în
 * rută, și a putut socoti că ea mai e acolo când ajunge să scrie. Cu ștergerea
 * în casă, între citirea aceea și tranzacție încape o ștergere întreagă, iar
 * PATCH-ul sosit al doilea făcea, după ce anume ducea:
 *
 *   · poziții (`items`) → `500 db_error`: `INSERT INTO order_items` pe o
 *     comandă care nu mai e (cheia străină, 1452), luat drept ciocnire de
 *     număr și reîncercat de cinci ori;
 *   · etapă, „urgent", telefon, zi → `200` cu o comandă GOALĂ în răspuns și un
 *     rând `order_update` scris în Jurnal DUPĂ `order_delete` — „Anulată → În
 *     așteptare" pe o comandă care nu mai există;
 *   · etapă cu `from` → `409 status_changed`, „acum e „”", cu `current` gol.
 *
 * Contractul ștergerii promite pentru toate trei un singur lucru: 404.
 *
 * LA ÎNCEPUT, NU LA PRIMA SCRIERE. Lacătul de la ușa de etapă (`from`) se ia
 * numai când cererea aduce `from`, iar cel din `stock_unsync_order()` numai
 * când e marfă de întors. Aici se ia MEREU, înaintea oricărei scrieri: cine
 * ajunge după o ștergere nu găsește rândul și pleacă pe loc, fără retur în
 * depozit, fără rând în Jurnal, fără comision recalculat și fără anunț — toate
 * stau mai jos. Cine ajunge înaintea ei o ține la ușă până la `commit`, iar ea
 * citește apoi ce a lăsat el (`not_cancelled`, dacă a refăcut comanda).
 *
 * Ordinea lacătelor nu se schimbă: rândul comenzii era și până acum primul
 * lacăt al rutei, pe oricare drum — doar că se lua mai târziu.
 *
 * CE NU REPARĂ, ȘI CE LĂRGEȘTE PUȚIN. Zăvorul zilei din laborator
 * (`lab_ziua_si_produsul()`) ia lacătele pe dos — întâi înregistrarea dintr-un
 * indice care începe cu ziua, apoi rândul —, iar un PATCH care mută comanda pe
 * altă zi are rândul și cere înregistrarea din indice. Cele două se puteau prinde
 * în impas și înainte (văzut pe codul de dinaintea ștergerii, 5 oct. 2026:
 * 1213, `500` pentru PATCH); luat aici, mai devreme, rândul stă zăvorât cu
 * câteva citiri mai mult până la `UPDATE`, deci fereastra aceea e cu atât mai
 * largă. Leacul nu e aici, ci în bucla de reluare a rutei: de la încheierile
 * rundei 2.7.0 ea reia, pe lângă cheia unică izbită (23000), și impasul (1213),
 * de la `beginTransaction` — deci tot prin lacătul de aici.
 *
 * Citirea CU lacăt vede ultimul rând comis, nu fotografia tranzacției: de aceea
 * e o citire cu `FOR UPDATE` și nu o a doua citire simplă.
 */
function comanda_sub_lacat_sau_404(PDO $pdo, int $id): void
{
    if (one('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$id]) !== null) {
        return;
    }
    // `fail()` încheie scriptul; cu tranzacția deschisă ar ține-o așa până
    // când găzduirea închide conexiunea.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail('Comanda nu există.', 404, 'not_found');
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
 * e un fapt, o rezervare e o promisiune; faptele nu se desfac. Asta e despre
 * producția raportată ÎNAINTE de anulare; raportul care sosește DUPĂ e altă
 * poveste și se refuză — vezi comentariul lung de la citirea de sub lacăt.
 *
 * Stocul are voie să iasă pe minus. Făina a plecat fizic indiferent ce crede
 * aplicația, iar un refuz aici ar face aplicația să mintă despre ce e în
 * rafturi. Cifra negativă se vede în Depozit — asta e informația utilă.
 */
function route_order_production(string $id): void
{
    $u = require_drept('laborator');   // laboratorul: agentul de vânzări n-are ce raporta aici
    $b = body();

    // Raportul de producție e tot o atingere de comandă, deci trece prin
    // aceeași margine: agentul n-are ce raporta pe comanda altcuiva. Poarta
    // stă înaintea tranzacției — `fail()` încheie scriptul, iar cu tranzacția
    // deschisă ar lăsa rândurile zăvorâte, ca la `$opreste` mai jos.
    if ($u['role'] === 'agent_vanzari' && !referral_agent_sees_order((int) $u['id'], (int) $id)) {
        fail('Comanda nu e a unui cumpărător adus de tine.', 403, 'forbidden');
    }

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
    //
    // `$peDeasupra` e pentru refuzul care are de spus mai mult decât un text:
    // azi doar cel al comenzii anulate, care duce și etapa de acum, în
    // `current`. `fail()` scrie numai { code, message }, iar pagina are nevoie
    // de etapă ca să spună omului ce s-a întâmplat fără încă o cerere — exact
    // ca la ușa de etapă (`route_orders_update`), de unde e copiată forma.
    // Cheile de temelie se pun primele, ca răspunsul să se citească la fel
    // indiferent pe care din cele două uși a bătut tableta.
    $opreste = static function (string $mesaj, int $status, string $cod, array $peDeasupra = []) use ($pdo): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$peDeasupra) {
            fail($mesaj, $status, $cod);
        }
        json_out(['error' => array_merge(
            ['code' => $cod ?: (string) $status, 'message' => $mesaj],
            $peDeasupra
        )], $status);
    };

    $planuit = [];
    $miscari = 0;
    $bucati  = 0.0;
    $fara    = [];

    try {
        // `status` și `seq` nu se citeau până acum: primul, fiindcă ruta nu
        // întreba niciodată pe ce etapă e comanda (vezi refuzul de mai jos); al
        // doilea, fiindcă mesajul refuzului numește comanda ca peste tot în
        // casă — referința, ori numărul zilei când referința e goală.
        $o = one('SELECT id, ref, seq, customer_name, status FROM orders WHERE id = ? FOR UPDATE', [(int) $id]);
        if (!$o) {
            $opreste('Comanda nu există.', 404, 'not_found');
        }

        // RAPORTUL CARE SOSEȘTE DUPĂ ANULARE NU SE SCRIE.
        //
        // Tableta din laborator stă cu ecranul stins de douăzeci de minute, iar
        // lista de pe ea e de atunci: `gp.poll` nu cere nimic cât fila e
        // ascunsă. Administratorul anulează comanda #1042 — a sunat clientul —,
        // marfa se întoarce în depozit. Brutarul ia tableta, deschide fișa
        // cardului care e încă acolo și apasă „Toate gata". Până acum ruta
        // scria liniștită: făina IEȘEA din rafturi pentru comanda nimănui,
        // patruzeci de croasante urcau pe stocul de finit, `done_qty` se umplea
        // și rezervările treceau pe consumate — iar răspunsul era 200, fără
        // niciun semn. Apoi omul apăsa butonul de etapă de deasupra, pe același
        // card, și primea „Comanda #1042 a fost schimbată între timp". Două
        // apăsări la o secundă distanță: una păzită, una nu — iar cea nepăzită
        // era tocmai cea care umblă la bani.
        //
        // NU SE CEARTĂ CU „faptele nu se desfac" de la capul funcției. Acolo e
        // vorba de producția raportată ÎNAINTE de anulare: croasantele acelea
        // chiar s-au făcut, făina chiar a ieșit din sac, iar rândurile lor rămân
        // scrise — marfa e în congelator și se vinde altcuiva. Aici e vorba de
        // raportul care sosește DUPĂ: el nu însemnează o faptă întâmplată, o
        // naște. Una păzește ce s-a făcut, cealaltă oprește ce nu s-a făcut.
        //
        // ÎNGUSTĂ DINADINS: numai etapa de anulare, niciodată „Predată" și
        // nicio altă etapă. `trimiteProd` din „Panou Laborator" pleacă abia
        // după 600 ms de liniște, fiindcă cine face patruzeci de croasante apasă
        // de patruzeci de ori. Dacă șoferul marchează „livrat" exact în secunda
        // aceea, un refuz pe „Predată" ar rupe o cursă NORMALĂ, în care nimeni
        // n-a greșit nimic și bucățile chiar s-au făcut. Anularea e altă
        // poveste: comanda a murit, iar marfa nu mai are stăpân.
        //
        // Etapa de anulare nu se scrie aici pe litere: lista de etape e
        // editabilă din Setări, deci se cere de la `status_cancelled()`
        // (api/lib/settings.php). Aceeași pază ca la numărătoarea zilelor și la
        // filtrul rutei: funcția întoarce ȘIRUL GOL când setarea e necompletată
        // și nicio etapă din listă nu se potrivește, iar o comparație fără
        // paza asta ar refuza tocmai comenzile cu etapa goală — adică ar opri
        // laboratorul pentru o setare nepusă.
        //
        // Refuzul are aceeași formă ca la ușa de etapă: 409, cod
        // `status_changed`, cu etapa de acum în `error.current` — copiată
        // literă cu literă, nu doar asemănătoare. Pagina deosebește refuzul de
        // o pană de rețea uitându-se la cod și la `current`; cu altă formă pe
        // ușa asta, tableta ar fi trebuit să învețe două feluri de a citi
        // același lucru, iar al doilea s-ar fi uitat la prima corectură.
        $anulata = status_cancelled();
        if ($anulata !== '' && (string) $o['status'] === $anulata) {
            $opreste(
                'Comanda ' . (((string) $o['ref']) ?: '#' . $o['seq'])
                    . ' a fost schimbată între timp: acum e „' . $anulata . '”. N-am notat producția.',
                409,
                'status_changed',
                ['current' => $anulata]
            );
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

        // Pozițiile, fiecare cu articolul de depozit care îi ține locul.
        //
        // Potrivirea și pricina ei — filtrul pe `active`, întâietatea
        // articolului legat de produs — stau în `productie_pozitii()`
        // (api/lib/productie.php), de unde le ia și ușa care împarte
        // producția pe ziua întreagă. O singură potrivire, un singur adevăr:
        // dacă intrarea de la producție ar lua un articol și scăderea de la
        // etapa finală altul, croasantul ar intra într-un raft și ar ieși din
        // altul.
        $pozitii = productie_pozitii([(int) $id]);

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

        // Rețetele tuturor produselor din raport, într-o singură interogare —
        // vezi `productie_retete()`. Cheamă și ușa care împarte producția.
        $reteta = productie_retete(array_map(
            static fn(array $pl): int => (int) ($pl['p']['product_id'] ?? 0),
            $planuit
        ));

        // Aceeași însemnare ca la scădere și la retur, ca rândurile să se
        // citească pereche în registru: „#1042 Restaurant Bistro”.
        $nota = trim((string) ($o['ref'] ?? '') . ' ' . (string) ($o['customer_name'] ?? ''));

        foreach ($planuit as $pl) {
            $p     = $pl['p'];
            $delta = (float) $pl['delta'];

            // TOATĂ scrierea stă într-un singur loc: consumul de materii
            // prime, intrarea pe finit, `done_qty`, ceasul din registrul de
            // producție și stingerea rezervărilor. Aici a stat până în runda
            // 2.3.0, când s-a deschis a doua ușă — `POST /lab/productie/imparte`,
            // care împarte o cantitate peste comenzile zilei. Scrisă a doua
            // oară, s-ar fi despărțit la prima corectură în două adevăruri care
            // răspund amândouă 200: una care mănâncă făină și una care nu.
            // Pricina întreagă e în antetul lui `productie_aplica()`.
            $urma = productie_aplica(
                $p,
                $delta,
                (float) $pl['nou'],
                $reteta[(int) ($p['product_id'] ?? 0)] ?? [],
                $nota,
                (int) $u['id'],
                (int) $id
            );
            $miscari += $urma['miscari'];
            if ($urma['fara'] !== null) {
                $fara[] = $urma['fara'];
            }
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
        // Plata: laboratorul nu primește nimic, afară de cel căruia fondatorul
        // i-a dat și „Încasări” — hotărăște `plati_fel()`, nu ruta.
        'order' => attach_items([one('SELECT * FROM orders WHERE id = ?', [(int) $id])], true, true, plati_fel($u))[0],
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
 * @param  array|null $u          cine cere, pentru marginea agentului de vânzări
 * @return array{0: string, 1: array}  [condiția SQL, valorile ei]
 */
function route_stop_filter(?array $u = null): array
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

    // Marginea agentului de vânzări, aceeași ca la lista de comenzi.
    //
    // Stă aici, nu la cele două rute care cheamă funcția, tocmai fiindcă sunt
    // două: scrisă de două ori, s-ar fi despărțit la prima corectură.
    //
    // DAR RÂNDUL DE MAI JOS NU SE ATINGE NICIODATĂ AZI, și e bine să scrie asta
    // aici, negru pe alb. Amândouă rutele care cheamă funcția cu un utilizator
    // — `route_driver_routes()` și `route_driver_route_counts()` — încep cu
    // `require_ops()`, iar `GP_OPS_ROLES` (api/lib/auth.php) e
    // `admin, angajat, manager, fondator`. Un `agent_vanzari` ia 403 pe ușă,
    // măsurat cu conturi adevărate. Cine citește filtrul și crede că agenții
    // își văd opririle citește greșit: nu ajung până aici.
    //
    // Atunci de ce rămâne. `GET /routes` întoarce comenzi ÎNTREGI, cu nume,
    // telefon și adresă. În ziua în care cineva adaugă agenții la
    // `GP_OPS_ROLES` — o singură linie, în alt fișier — mărginirea listei de
    // comenzi s-ar ocoli cerând ruta zilei. Rândul de față ține ziua aceea în
    // frâu dinainte, și costă o comparație de șiruri.
    //
    // Lărgirea porții n-o face rândul ăsta: e o hotărâre despre cine ce vede,
    // și e a patronului.
    if ($u !== null && ($u['role'] ?? '') === 'agent_vanzari') {
        $sc     = referral_agent_scope((int) $u['id'], 'customer_id', 'referral_code');
        $cond[] = $sc['sql'];
        $params = array_merge($params, $sc['params']);
    }

    return [$cond ? implode(' AND ', $cond) : '1', $params];
}

/** GET /routes?day= — opririle zilei, în ordinea comenzilor. */
function route_driver_routes(): void
{
    $u   = require_drept('ruta');   // ruta: v. GP_OPS_ROLES
    $day = valid_day(trim((string) ($_GET['day'] ?? '')) ?: today_local());

    // Opririle cu ordine stabilită manual vin primele, în ordinea aceea;
    // restul, după intervalul promis.
    $order = 'ORDER BY route_seq IS NULL, route_seq, slot, seq';
    [$cond, $params] = route_stop_filter($u);
    array_unshift($params, $day);
    $orders = all("SELECT * FROM orders WHERE day = ? AND $cond $order", $params);

    // Plata: șoferul nu primește nimic despre ea (nici cheile); cine are
    // „Încasări” și deschide ruta le vede. Hotărăște `plati_fel()`.
    json_out(['day' => $day, 'stops' => attach_items($orders, true, true, plati_fel($u))]);
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
    $u    = require_drept('ruta');   // ruta: v. GP_OPS_ROLES
    $from = valid_day(trim((string) ($_GET['from'] ?? '')) ?: today_local());
    $to   = valid_day(trim((string) ($_GET['to'] ?? '')) ?: $from);
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    // Panourile cer o săptămână; calendarul rutei cere o grilă de 42 de
    // căsuțe — o lună întreagă plus zilele lunilor vecine care umplu prima și
    // ultima săptămână. Plafonul e pus peste ele, cu loc de o a doua lună
    // cerută din mers, și e acolo ca o cerere scrisă de mână să nu pună
    // serverul să numere toată baza.
    //
    // Aceeași cifră ca la `route_orders_counts()`, unde e scrisă aceeași
    // socoteală: două calendare cu aceeași grilă n-au de ce să aibă plafoane
    // deosebite. Cât a fost 31, calendarul șoferului lua 422 la deschidere —
    // adică se înroșea fix pe luna pe care omul voia s-o vadă.
    if ((strtotime($to) - strtotime($from)) > 92 * 86400) {
        fail('Intervalul cerut e prea lung: cel mult 92 de zile.', 422, 'range_too_long');
    }

    [$cond, $params] = route_stop_filter($u);
    array_unshift($params, $from, $to);
    $rows = all("SELECT day, COUNT(*) AS n FROM orders WHERE day BETWEEN ? AND ? AND $cond GROUP BY day", $params);

    $counts = [];
    foreach ($rows as $r) {
        $counts[(string) $r['day']] = (int) $r['n'];
    }

    json_out(['from' => $from, 'to' => $to, 'counts' => $counts]);
}

/**
 * GET /routes/restante?zile= — opririle trecute rămase nefinalizate.
 *
 * Ruta șoferului a fost dintotdeauna ruta UNEI zile: ce nu s-a predat ieri nu
 * se mai vedea de pe telefon. Dar o livrare ratată nu se încheie fiindcă a
 * trecut miezul nopții — ea rămâne, iar șoferul afla de ea de la birou, peste
 * zile, sau deloc. Asta e lista pe care o cerea patronul: „comenzile vechi
 * nefinalizate".
 *
 * DE CE O RUTĂ NOUĂ ȘI NU UN `?from=&to=` PUS PESTE `GET /routes`. Aceea
 * răspunde la „opririle zilei, în ordinea în care le fac" — o zi, ordinea
 * rutei, tot ce e în ea, predat sau nu. Asta răspunde la altceva: „ce mi-a
 * rămas în urmă" — mai multe zile, cele mai noi întâi, numai ce nu s-a
 * încheiat. Două întrebări deosebite au margini deosebite: aici se cer zile
 * multe, deci lista se mărginește ȘI la număr, nu doar la zile. Lărgită,
 * `/routes` ar fi ajuns să poarte amândouă înțelesurile într-un singur set de
 * parametri, iar ordinea rezultatului ar fi depins de care dintre ei e dat —
 * exact felul de rută pe care n-o mai poate citi nimeni. Același drum l-au
 * făcut și `/routes/counts`, și `/routes/mine`.
 *
 * CE NU INTRĂ: ziua de azi și zilele de mâine încolo. Ruta de azi nu e „rămasă
 * în urmă" — e chiar ecranul de dedesubt, cu tot cu butoanele ei. Marginea de
 * sus e `day < azi`, nu `<=`.
 *
 * Etapele de scos sunt cele două setări ale casei, nu texte scrise aici:
 * anularea o taie `route_stop_filter()`, ca peste tot, iar încheierea se cere
 * de la `status_done()`. Dacă „Predată" se redenumește din panou, lista află
 * de acolo.
 */
function route_driver_restante(): void
{
    $u = require_drept('ruta');   // aceeași poartă ca `/routes`: v. GP_OPS_ROLES

    // Cât de departe în urmă. Scrisă de mână în adresă, fereastra ar putea
    // cere toată baza; plafonul e același cu al numărătorii de mai sus, ca
    // cele două să nu se contrazică — calendarul arată o lună, restanțele se
    // pot uita peste trei.
    $zile = (int) ($_GET['zile'] ?? 30);
    if ($zile < 1) {
        $zile = 1;
    }
    if ($zile > 92) {
        $zile = 92;
    }

    // Ceasul patiseriei, ca peste tot unde se hotărăște ce e „azi": pe un
    // server pus pe UTC, `date()` ar muta hotarul cu trei ore, iar livrarea de
    // azi de la 01:00 ar intra în restanțe cu tot cu butonul „rămasă în urmă".
    $azi   = today_local();
    $de_la = (new DateTimeImmutable($azi))->modify('-' . $zile . ' day')->format('Y-m-d');

    [$cond, $params] = route_stop_filter($u);
    array_unshift($params, $de_la, $azi);

    // Fără setare validă `status_done()` întoarce '' — și atunci nu se scade
    // nimic. O listă prea lungă se citește; una care ascunde livrări, nu.
    $gata = status_done();
    if ($gata !== '') {
        $cond    .= ' AND status <> ?';
        $params[] = $gata;
    }

    // Cele mai noi întâi: ce a rămas ieri se închide înaintea a ce a rămas
    // acum trei săptămâni. Înăuntrul zilei rămâne ordinea rutei, ca omul să
    // recunoască lista pe care a avut-o în mână.
    //
    // `LIMIT` e paza de la urmă. O casă care n-a mai închis nimic de luni de
    // zile n-are voie să trimită un telefon la genunchi, iar cifra nu e o
    // margine a adevărului: ce nu încape aici se vede oricum în calendar, pe
    // ziua lui.
    $orders = all(
        "SELECT * FROM orders WHERE day >= ? AND day < ? AND $cond
          ORDER BY day DESC, route_seq IS NULL, route_seq, slot, seq
          LIMIT 200",
        $params
    );

    json_out([
        'from'  => $de_la,
        'to'    => $azi,
        'stops' => attach_items($orders, true, true, plati_fel($u)),
    ]);
}

/**
 * GET /routes/mine — cât mai are șoferul până la comanda ta, azi.
 *
 * Cine întreabă: clientul, pentru comanda lui; agentul de vânzări, pentru
 * cumpărătorii aduși de el — câte un rând pentru fiecare, aleasă de patron.
 *
 * De ce o rută nouă și nu o lărgire a lui `GET /routes`. Aceea e
 * `require_ops()` și întoarce comenzile ÎNTREGI, cu nume, telefon, adresă și
 * poziții. Un client n-are ce căuta în comenzile altora, iar un agent n-are ce
 * căuta în ale casei. Aici pleacă numai cifre („mai are 7 opriri", „2 până la
 * tine") și numele zonelor — adică fix cât ține un om să-și aștepte marfa.
 *
 * De ce nu primește `?day=`. Fereastra e despre AZI: „nu aveți nicio comandă
 * care trebuie livrată astăzi" e chiar mesajul ales. O zi liberă în adresă ar
 * fi lăsat pe oricine să numere opririle oricărei zile, la nesfârșit — o
 * scurgere mică, dar fără niciun câștig, fiindcă nimeni n-o cere.
 *
 * Ordinea e a rutei adevărate, aceeași cu a ecranului șoferului, și se
 * socotește peste TOATE opririle zilei — de-aia `route_stop_filter()` se
 * cheamă aici fără utilizator. Cu marginea agentului pusă peste ea, „2 opriri
 * până la tine" ar fi numărat doar clienții agentului, adică ar fi mințit.
 */
function route_my_route_window(): void
{
    $u   = require_auth();
    $day = today_local();

    // Toate opririle zilei, în ordinea în care le face șoferul.
    [$cond, $params] = route_stop_filter();
    array_unshift($params, $day);
    $stops = all(
        'SELECT id, ref, seq, customer_name, zone, slot, status
           FROM orders
          WHERE day = ? AND ' . $cond . '
          ORDER BY route_seq IS NULL, route_seq, slot, seq',
        $params
    );

    // Etapa de „gata" e o setare, nu un cuvânt scris în cod. Dacă lipsește,
    // nicio oprire nu se socotește livrată: mai bine „mai are 7 opriri" decât
    // un „0" liniștitor și fals.
    $done = status_done();

    // Care opriri sunt ale celui care întreabă. Regula stă tot în
    // `orders_visibility_filter()`, ca la lista de comenzi: scrisă a doua oară
    // aici, s-ar fi despărțit de ea la prima corectură. Pentru personal
    // întoarce gol, deci nu are opriri „ale lui" — ei au ecranul șoferului.
    [$vSql, $vParams] = orders_visibility_filter($u, '');
    $aleMele = [];
    if ($vSql !== '') {
        $p = array_merge([$day], $vParams);
        foreach (all(
            'SELECT id FROM orders WHERE day = ? AND (' . $vSql . ')',
            $p
        ) as $r) {
            $aleMele[(int) $r['id']] = true;
        }
    }

    $ramase  = 0;
    $peZone  = [];
    $inainte = [];
    foreach ($stops as $s) {
        if ($done !== '' && (string) $s['status'] === $done) {
            continue;
        }
        // Câte opriri nelivrate stau ÎNAINTEA ei. Se scrie înainte de
        // incrementare, deci prima oprire rămasă are zero.
        $inainte[(int) $s['id']] = $ramase;
        $ramase++;
        $z = trim((string) $s['zone']);
        if ($z !== '') {
            $peZone[$z] = ($peZone[$z] ?? 0) + 1;
        }
    }

    $zone = [];
    foreach ($peZone as $z => $n) {
        $zone[] = ['zone' => $z, 'remaining' => $n];
    }

    $mine = [];
    foreach ($stops as $s) {
        $id = (int) $s['id'];
        if (!isset($aleMele[$id])) {
            continue;
        }
        $gata = $done !== '' && (string) $s['status'] === $done;
        $mine[] = [
            'id'          => $id,
            'ref'         => $s['ref'],
            'customer'    => $s['customer_name'],
            'zone'        => $s['zone'],
            'slot'        => $s['slot'],
            'delivered'   => $gata,
            'stopsBefore' => $gata ? null : ($inainte[$id] ?? null),
        ];
    }

    json_out(['day' => $day, 'remaining' => $ramase, 'zones' => $zone, 'mine' => $mine]);
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
    require_drept('ruta.ordine');
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
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    audit('route_reorder', 'route', $day, $pos > 0 ? $pos . ' opriri' : 'ordine ștearsă');
    json_out(['ok' => true, 'day' => $day, 'stops' => $pos]);
}

/* ------------------------------------------------------------- ajutoare -- */
