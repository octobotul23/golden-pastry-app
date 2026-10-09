<?php
/**
 * Căutarea de client, pentru cine ia comanda în numele altuia.
 *
 *   GET /clients?q=…   — caută printre clienți. Numai personalul, și numai
 *                        fiecare în ograda lui.
 *
 * DE CE O RUTĂ PROPRIE, și nu `GET /users`: aceea e `require_admin()` și
 * întoarce contul întreg — rol, stare, cine pe cine a adus, când s-a schimbat
 * parola. Agentul de vânzări n-are ce căuta acolo, iar formularul de contact
 * n-are nevoie decât de numele, telefonul și adresa clientului, ca să le poată
 * pune singur în casete.
 *
 * OGRADA, scrisă o singură dată și numai în SQL:
 *
 *   admin, fondator, manager — toți clienții;
 *   agent_vanzari            — numai cei aduși de el (`users.referred_by`);
 *   angajat                  — nimic, 403.
 *
 * Managerul a intrat pe 19 septembrie 2026, la cererea patronului. Pricina, pe
 * larg, la `client_scope_for()` (api/lib/orders.php): el vede oricum datele
 * fiecărui client pe comenzile pe care le mută zilnic — ce capătă e o cale de a
 * le nimeri, nu o vedere nouă.
 *
 * FIECARE RÂND POARTĂ UN DOSAR SCURT (`history`): câte comenzi, ultima, unde a
 * mers și cât a lăsat în casă. Tot patronul l-a cerut: „când ții mouse-ul
 * deasupra sau apeși lung pe telefon să apară un pop-up cu mai multe amănunte
 * despre clientul acela înainte să-l alegi". Vine ODATĂ cu lista, nu la
 * apăsare: altfel fiecare plimbare de deget peste un rând ar fi fost o cerere
 * la server, iar amănuntele ar fi sosit după ce mâna a plecat mai departe.
 *
 * Îngrădirea NU vine dintr-un parametru al cererii: nu există niciun șir prin
 * care agentul să ceară clienții altcuiva. Identitatea intră din sesiune direct
 * în `WHERE`, ca la `referral_history()` (api/lib/referral.php).
 *
 * NIMIC DESPRE BANI nu pleacă pe aici: nici `referral_code`, nici
 * `referral_rate`, nici `referred_by`. Cine caută un client ca să-i scrie o
 * cerere are nevoie de datele de livrare, nu de socoteala lui.
 */

declare(strict_types=1);

/** Câți clienți se întorc dintr-o căutare. Mai mulți nu încap pe telefon. */
const GP_CLIENT_SEARCH_LIMIT = 12;

/** Cel mai scurt șir care merită o interogare. */
const GP_CLIENT_SEARCH_MIN = 2;

/**
 * `GET /clients?q=…`
 *
 * Răspunsul are întotdeauna aceeași formă — `rows`, `short`, `more` — ca pagina
 * să nu fie nevoită să deosebească „n-am căutat încă" de „n-am găsit nimic"
 * după ce lipsește din răspuns.
 */
function route_clients_search(): void
{
    $u = require_personal('comenzi.scrie');

    // Ograda vine din `api/lib/orders.php`, lângă `client_by_name()`, ca să fie
    // ACEEAȘI cu cea de la legarea cererii de cont (api/routes/requests.php).
    $sc = client_scope_for($u);
    if ($sc === null) {
        fail(
            'Căutarea de clienți e pentru administrator, fondator și agenții de vânzări.',
            403,
            'client_search_denied'
        );
    }

    $q = trim((string) ($_GET['q'] ?? ''));

    // Un șir prea scurt nu e o greșeală: caseta întreabă la fiecare literă, iar
    // o singură literă ar aduce jumătate din listă pe fiecare apăsare de tastă.
    // Se răspunde cu lista goală și cu `short`, ca pagina să poată spune de ce.
    if (mb_strlen($q) < GP_CLIENT_SEARCH_MIN) {
        json_out([
            'rows'  => [],
            'short' => true,
            'more'  => false,
            'min'   => GP_CLIENT_SEARCH_MIN,
        ]);
    }

    $where  = ["u.role = 'client'", 'u.active = 1'];
    $params = [];

    if (!$sc['toti']) {
        // Ograda agentului. `referred_by` e legătura de cont, scrisă o singură
        // dată la nașterea contului de `referral_link_new_user()`. Codul de pe
        // o comandă NU se amestecă aici: întrebarea e „l-ai adus tu pe omul
        // ăsta?", nu „ți-a intrat vreodată codul pe o comandă de-a lui".
        $where[]  = 'u.referred_by = ?';
        $params[] = $sc['agent'];
    }

    // `%` și `_` se taie. O casetă în care semnul procent înseamnă „tot" e o
    // casetă care minte: cine scrie „100%" caută „100%", nu toată lista.
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $where[] = '(u.customer_name LIKE ? OR u.name LIKE ?'
        . ' OR u.phone LIKE ? OR u.email LIKE ? OR u.username LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);

    // Se cere UNUL peste plafon, ca să se știe dacă mai sunt și alții. Fără el,
    // o listă plină ar arăta la fel cu una care se termină exact acolo, iar
    // omul n-ar ști niciodată că trebuie să scrie mai mult.
    // Însemnul „venit de pe site, nerecunoscut încă" — aceleași coloane pe care
    // le cântărește `client_by_name()`. Fără migrarea 010 coloanele nu există,
    // iar `SELECT`-ul ar cădea cu 1054 pe fiecare căutare; atunci se întoarce 0,
    // adică „nu știm", și lista nu poartă niciun însemn.
    $semn = user_origin_supported()
        ? ', (u.self_registered = 1 AND u.vouched_at IS NULL) AS nerecunoscut'
        : ', 0 AS nerecunoscut';

    // Zona ținută pe cont vine din migrarea 016 și are ACEEAȘI poartă ca
    // însemnul de deasupra, din aceeași pricină. Cerută necondiționat, coloana
    // răspundea 1054 pe o bază nemigrată, iar `all()` o ridica în 500 — adică,
    // în fereastra dintre urcarea fișierelor și importul SQL (așa lucrează
    // `actualizeaza-vps.sh`, așa scrie în docs/07), cine lua comanda la telefon
    // nu mai găsea NICIUN client. Toate vecinele care ating coloana asta au
    // poarta lor de la bun început — `api/routes/users.php:146`,
    // `api/routes/auth.php:157`, `user_zone()` din `api/lib/auth.php` — numai
    // căutarea rămăsese pe dinafară.
    //
    // Fără coloană se întoarce șirul gol, nu se scoate cheia: răspunsul trebuie
    // să aibă ACEEAȘI formă întotdeauna, ca pagina să nu fie nevoită să
    // deosebească „n-are zonă" de „n-am coloana". Pentru cel care ia comanda e
    // același lucru: caseta zonei rămâne goală și o alege el, ca înainte de
    // migrarea 016. O comoditate care lipsește n-are voie să oprească vânzarea.
    $zona = user_zone_supported() ? ', u.zone' : ", '' AS zone";

    $randuri = all(
        'SELECT u.id, u.name, u.customer_name, u.phone, u.email, u.address'
        . $zona
        . $semn
        . ' FROM users u WHERE ' . implode(' AND ', $where)
        . " ORDER BY COALESCE(NULLIF(u.customer_name, ''), u.name), u.id"
        . ' LIMIT ' . (GP_CLIENT_SEARCH_LIMIT + 1),
        $params
    );

    $more = count($randuri) > GP_CLIENT_SEARCH_LIMIT;
    if ($more) {
        array_pop($randuri);
    }

    // Dosarele, pe lot: două interogări pentru toată lista, oricât de lungă.
    // V. `dosare_clienti()`, api/lib/orders.php.
    $dosare = dosare_clienti(array_map(static fn(array $r): int => (int) $r['id'], $randuri));

    $rows = array_map(static function (array $r) use ($dosare): array {
        $firma = trim((string) ($r['customer_name'] ?? ''));
        $nume  = trim((string) ($r['name'] ?? ''));
        // Clientul fără nicio comandă are dosar GOL, nu lipsă: pagina desenează
        // aceeași fereastră, cu „n-a comandat încă" în loc de cifre. Un dosar
        // care lipsește din răspuns ar fi cerut paginii să deosebească „n-are
        // comenzi" de „n-am aflat" — două lucruri care arată la fel.
        $d = $dosare[(int) $r['id']] ?? [
            'orders' => 0, 'spent' => 0.0, 'day' => '', 'slot' => '',
            'zone' => '', 'address' => '', 'fulfillment' => '',
        ];

        return [
            'id' => (int) $r['id'],
            // `company` e numele sub care e cunoscut clientul în comenzi, adică
            // exact ce caută `client_by_name()` (api/lib/orders.php). Când
            // `customer_name` e gol, numele contului ține locul — la fel ca
            // acolo, ca să nu se certe două adevăruri despre aceeași firmă.
            'company' => $firma !== '' ? $firma : $nume,
            'name'    => $nume,
            'phone'   => (string) ($r['phone'] ?? ''),
            'email'   => (string) ($r['email'] ?? ''),
            'address' => (string) ($r['address'] ?? ''),
            'zone'    => (string) ($r['zone'] ?? ''),
            // Nu e o poartă, e un avertisment: contul se poate alege, dar cel
            // care alege trebuie să știe că numele de pe el n-a fost întărit de
            // nimeni din casă. Însemnul e același cu cel din Conturi.
            'unverified' => (bool) ($r['nerecunoscut'] ?? false),
            // Dosarul scurt, pentru fereastra de dinaintea alegerii. Cheile sunt
            // cele ale ULTIMEI comenzi scrise, nu ale contului: adresa de pe
            // cont poate fi cea de acasă, pe când marfa merge de trei luni la
            // birou — iar cel care ia comanda pe asta din urmă o caută.
            'history' => [
                'orders'      => (int) $d['orders'],
                'spent'       => round((float) $d['spent'], 2),
                'lastDay'     => (string) $d['day'],
                'lastSlot'    => (string) $d['slot'],
                'lastZone'    => (string) $d['zone'],
                'lastAddress' => (string) $d['address'],
                'lastFulfillment' => (string) $d['fulfillment'],
            ],
        ];
    }, $randuri);

    json_out([
        'rows'  => $rows,
        'short' => false,
        'more'  => $more,
        'min'   => GP_CLIENT_SEARCH_MIN,
    ]);
}
