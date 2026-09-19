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
    if ($u['role'] === 'agent_vanzari') {
        // Agentul e personal, dar comenzile casei nu sunt ale lui: vede numai
        // cumpărătorii aduși de el și comenzile care îi poartă codul.
        // Regula stă într-un singur loc — vezi referral_agent_scope().
        $sc = referral_agent_scope((int) $u['id'], $prefix . 'customer_id', $prefix . 'referral_code');
        return [$sc['sql'], $sc['params']];
    }
    // Au rămas două tabere: personalul de operațiuni, care le vede pe toate
    // (filtru gol), și oricine altcineva, care nu vede niciuna. `1 = 0` e
    // „niciun rând", nu „eroare": lista iese goală, numărătoarea iese zero,
    // iar o comandă anume iese „nu există" — adică răspunsul nu spune nici
    // măcar ce numere are casa.
    return orders_readable($u) ? ['', []] : ['1 = 0', []];
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
    $orders = attach_items($orders, $personal, $personal);

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
    $out = attach_items([$o], $personal, $personal)[0];
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
    require_staff();
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
    $u = require_staff();
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
        'SELECT product_id, variant, qty, done_qty, unit_price FROM order_items WHERE order_id = ? ORDER BY id',
        [(int) $id]
    );

    [$priced, $total] = price_items(
        $items,
        $implicit,
        honored_prices($vechile),
        array_filter(array_column($vechile, 'product_id'), static fn($x) => $x !== null)
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
    $user = require_order_create();
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

            // Prețul de catalog rămâne scris lângă cel pus pe hârtie. Pe o
            // comandă luată la telefon cele două sunt de obicei aceeași cifră;
            // diferă când s-a onorat un preț promis mai demult, iar atunci
            // tocmai diferența e ce trebuie să se vadă. Interogarea se alege o
            // dată, în afara buclei, ca o bază fără migrarea 011 să nu cadă pe
            // fiecare poziție.
            $cuLista = promo_supported();
            $sqlPozitie = $cuLista
                ? 'INSERT INTO order_items (order_id, product_id, name, variant, qty, unit_price, list_price) VALUES (?, ?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO order_items (order_id, product_id, name, variant, qty, unit_price) VALUES (?, ?, ?, ?, ?, ?)';
            foreach ($priced as $p) {
                $val = [$orderId, $p['id'], $p['name'], $p['variant'], $p['qty'], $p['unit']];
                if ($cuLista) {
                    $val[] = $p['catalog'] ?? null;
                }
                q($sqlPozitie, $val);
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
    $order = attach_items([one('SELECT * FROM orders WHERE id = ?', [$orderId])], true, true)[0];

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
 */
const ORDER_FIELDS_PRECONDITION = ['from'];

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
    $u = require_staff();
    $b = body();

    // Poarta stă mai sus, scrisă o singură dată: o ține și tarifarea care
    // arată ce ar scrie PATCH-ul ăsta (`POST /orders/#/quote`).
    poarta_scrierii_comenzii($u, $b, $id);

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

    // Referința scoasă din $sets nu are voie să scoată salvarea din picioare:
    // o corectură numai de referință e o faptă întreagă, nu „nimic de
    // modificat”.
    if (!$sets && $refCerut === null) {
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
        // Referința cu care rămâne comanda: cea tastată acum, dacă s-a tastat
        // una, altfel cea pe care o are. Se socotește la FIECARE încercare,
        // fiindcă blocul de mutare o poate schimba, iar a doua rulare trebuie
        // să pornească din nou de la ce a cerut omul.
        $refFinal = $refCerut ?? (string) $o['ref'];
        try {
            $pdo->beginTransaction();

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
                // de la prima mutare de etapă din laborator.
                $cuLista = promo_supported();
                $sqlPozitie = $cuLista
                    ? 'INSERT INTO order_items (order_id, product_id, name, variant, qty, done_qty, unit_price, list_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    : 'INSERT INTO order_items (order_id, product_id, name, variant, qty, done_qty, unit_price) VALUES (?, ?, ?, ?, ?, ?, ?)';
                foreach ($priced as $p) {
                    $cheie = ((int) $p['id']) . '|' . $p['variant'];
                    // Plafonat la cantitatea nouă: cine taie comanda de la 8 la 5
                    // după ce s-au făcut 6 nu are 6 făcute din 5. A șasea bucată nu
                    // se pierde — intrarea ei pe articolul finit e deja în registru
                    // și rămâne acolo, exact ca la o comandă anulată.
                    $done  = min((float) $p['qty'], (float) ($ramas[$cheie] ?? 0));
                    $ramas[$cheie] = ($ramas[$cheie] ?? 0) - $done;
                    $val = [(int) $id, $p['id'], $p['name'], $p['variant'], $p['qty'], round($done, 2), $p['unit']];
                    if ($cuLista) {
                        $val[] = $p['catalog'] ?? null;
                    }
                    q($sqlPozitie, $val);
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
    // ruta asta. Inclusiv butonul „Șterge comanda", care de fapt trimite un
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

    $out = attach_items([one('SELECT * FROM orders WHERE id = ?', [(int) $id])], true, true)[0];
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

    // Faptele pe care patronul le poate cere pe telefon: comanda a ajuns gata,
    // a fost anulată, ori a fost refăcută după o anulare. Locul e ales, nu
    // întâmplător: `orders.status` se scrie într-un SINGUR loc în tot programul —
    // ruta asta. Aici ajung butonul „Șterge comanda" (un PATCH pe etapa de
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
    $u = require_ops();   // laboratorul: agentul de vânzări n-are ce raporta aici
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

        // Potrivirea poziție → articol finit, copiată literă cu literă din
        // `route_stock_sync`, filtrul pe `active` inclusiv. Dacă intrarea de la
        // producție ia un articol și scăderea de la „Predată” altul, croasantul
        // intră într-un raft și iese din altul: două cifre greșite în loc de una.
        //
        // Costul filtrului, spus pe față: ruta asta scrie și „Corecție
        // producție”, adică desface o intrare de mai devreme. Dacă între raport
        // și corectură articolul a fost scos din depozit, corectura nu mai are
        // unde se scrie — `done_qty` se îndreaptă, dar cifra raftului nu, iar
        // produsul apare în `missing`. Ieșirea e la îndemână și e aceeași ca la
        // scădere: articolul se repune din „Scoase din depozit”, în josul
        // paginii Depozit. Alternativa — să lăsăm producția să intre într-un raft
        // pe care nu-l vede nimeni — e tocmai defectul reparat aici.
        $pozitii = all(
            'SELECT oi.id, oi.product_id, oi.name, oi.qty, oi.done_qty,
                    si.id AS item_id, si.name AS item_name
               FROM order_items oi
               LEFT JOIN stock_items si
                      ON si.id = (
                           SELECT s.id
                             FROM stock_items s
                            WHERE (
                                    (s.product_id = oi.product_id AND s.product_id IS NOT NULL)
                                    OR (s.product_id IS NULL AND s.name = oi.name)
                                  )
                              AND s.active = 1
                            ORDER BY (s.product_id IS NULL), s.id
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

            // CEASUL. Aceeași atingere, scrisă în două registre deosebite:
            // `done_qty` ține TOTALUL de acum — tableta trimite totaluri
            // dinadins, ca o cerere pierdută să se vindece singură la
            // următoarea —, iar registrul de producție ține DIFERENȚA, cu clipa
            // ei. Din totaluri nu se poate afla niciodată când s-a lucrat; din
            // diferențe cu ceas se află și una, și alta.
            //
            // `$p['id']` e POZIȚIA de pe comandă. NU `$p['item_id']`: acela e
            // articolul de depozit potrivit mai sus, cel care uneori lipsește.
            // Rândul se scrie și pentru pozițiile fără articol finit — registrul
            // ăsta n-are voie să aibă goluri, fiindcă se citește lângă un stat
            // de plată. Tocmai golurile sunt pricina pentru care raportul de
            // tură nu se sprijină pe `stock_moves`, deși și el are ceas.
            //
            // Înăuntrul tranzacției, dinadins: un raport care se întoarce din
            // drum n-are voie să lase în urmă ore de muncă pe care nu le-a
            // făcut nimeni.
            lab_production_scrie((int) $id, (int) $p['id'], (string) $p['name'], $delta);

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
        'order' => attach_items([one('SELECT * FROM orders WHERE id = ?', [(int) $id])], true, true)[0],
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
    $u   = require_ops();   // ruta: v. GP_OPS_ROLES
    $day = valid_day(trim((string) ($_GET['day'] ?? '')) ?: today_local());

    // Opririle cu ordine stabilită manual vin primele, în ordinea aceea;
    // restul, după intervalul promis.
    $order = 'ORDER BY route_seq IS NULL, route_seq, slot, seq';
    [$cond, $params] = route_stop_filter($u);
    array_unshift($params, $day);
    $orders = all("SELECT * FROM orders WHERE day = ? AND $cond $order", $params);

    json_out(['day' => $day, 'stops' => attach_items($orders, true, true)]);
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
    $u    = require_ops();   // ruta: v. GP_OPS_ROLES
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
    $u = require_ops();   // aceeași poartă ca `/routes`: v. GP_OPS_ROLES

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
        'stops' => attach_items($orders, true, true),
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
    require_admin();
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
