<?php
/** Jurnalul de modificări. Doar `admin`. */

declare(strict_types=1);

/** GET /log?search=&page=&action=&entity=&entityId= */
function route_log_list(): void
{
    require_admin();

    $where  = [];
    $params = [];

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

    json_out([
        'entries' => array_map(static fn($r) => [
            'id'        => (int) $r['id'],
            // Numele din jurnal, iar dacă lipsește (rând scris înainte de
            // reparația din `audit()`), cel de pe contul legat. Numai când nu
            // există nici contul rămâne gol, iar pagina scrie „Sistem" —
            // atunci chiar e adevărat.
            'user'      => $r['user_label'] ?? $r['user_name'],
            'role'      => $r['role'],
            'action'    => $r['action'],
            'entity'    => $r['entity'],
            'entityId'  => $r['entity_id'],
            'detail'    => $r['detail'],
            'ip'        => $r['ip'],
            'createdAt' => $r['created_at'],
        ], $rows),
        'total'   => $total,
        'page'    => $page,
        'pages'   => (int) ceil($total / $perPage),
        // Numai când s-a cerut istoria unui lucru anume; altfel `null`.
        'subject' => $subiect,
    ]);
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
