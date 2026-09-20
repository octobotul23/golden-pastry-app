<?php

declare(strict_types=1);

/**
 * Ce cere laboratorul de la server: pontajul turei și tabla de anunțuri.
 *
 * Două lucrări deosebite, puse în același fișier fiindcă fișierul e al familiei
 * de adrese `/lab/*`, nu al uneia din ele — cine urmărește o adresă o caută
 * într-un singur loc. Se citesc pe rând, mai jos; ce au în comun e mesele din
 * laborator.
 *
 * ── I. PONTAJUL (migrarea 014) ───────────────────────────────────────────
 *
 * CONTEXTUL, care explică tot ce urmează. Tabletele din laborator se deschid cu
 * UN SINGUR cont — `panou`, `is_system = 1` — cu un cod știut de toată tura
 * (`api/routes/auth.php:321`). E o alegere bună pentru ecranul de comenzi:
 * nimeni nu-și pierde minutele cu parola când are mâinile în aluat. Dar
 * înseamnă că sesiunea din fața tabletei NU spune cine e omul. De-aia pontajul
 * nu se poate sprijini pe `current_user()`, cum face restul aplicației: aici
 * sesiunea spune doar „sunt o tabletă a casei", iar cine pontează se dovedește
 * cu un cod scurt, propriu (`users.lab_pin`, migrarea 014).
 *
 * CELE DOUĂ PORȚI, deosebite dinadins:
 *
 *   · tableta — `require_clock()`. Poate cere lista de nume și poate ponta.
 *     Atât. Nu vede nicio oră a nimănui în afară de cea pe care tocmai a
 *     scris-o, și nu vede nicio zi trecută.
 *   · raportul — `require_shifts()`, adică administrator, fondator și manager.
 *     Orele oamenilor sunt date de personal; managerul le capătă fiindcă el
 *     conduce tura, și e singurul lucru de personal pe care îl vede.
 *
 * Corectura unui rând uitat deschis e a treia poartă, cea mai strâmtă:
 * `require_admin()`. Managerul citește statul de plată, nu îl scrie.
 *
 * ── II. TABLA DE ANUNȚURI (migrarea 015) ─────────────────────────────────
 *
 * Anunțul stă deasupra comenzilor zilei, pe Panoul Laborator. Aici sesiunea
 * spune cine e omul și asta ajunge: scrie cine conduce tura (`require_shifts()`
 * — aceiași cu raportul, fiindcă sunt aceiași oameni), citește oricine ține
 * ecranul deschis (`require_ops()`, adică și contul `panou` al tabletei).
 *
 * DE CE NU SE SPRIJINĂ ȘI ASTA PE CODUL DE PONTAJ, ca partea de sus: n-are pe
 * cine legitima. Anunțul se citește de toată lumea din laborator — e chiar
 * rostul lui — iar cine îl pune nu stă la tabletă, ci la biroul lui, într-o
 * sesiune cu numele pe ea.
 */

/* ------------------------------------------------------- ajutoare -- */
//
// `lab_supported()`, `require_lab()` și `lab_attempt_key()` stau în
// api/lib/auth.php, nu aici: de ele are nevoie și pagina Conturi, care pune și
// șterge codurile, iar fișierele din routes/ nu se încarcă unul pe altul.

/** Tura deschisă a unui om, sau null. */
function lab_open_shift(int $userId): ?array
{
    return one(
        'SELECT id, day, started_at FROM lab_shifts
          WHERE user_id = ? AND ended_at IS NULL
          ORDER BY started_at DESC LIMIT 1',
        [$userId]
    );
}

/** Forma în care pleacă o tură spre pagină. */
function lab_shift_public(array $r): array
{
    return [
        'id'        => (int) $r['id'],
        'userId'    => (int) $r['user_id'],
        'name'      => $r['name'] ?? '',
        'day'       => $r['day'],
        'startedAt' => $r['started_at'],
        'endedAt'   => $r['ended_at'],
        // Cine a scris ora de plecare, când n-a scris-o omul. Gol = rând curat.
        'endedBy'   => $r['ended_by_name'] ?? null,
        'note'      => $r['note'],
    ];
}

/* --------------------------------------------------------- rutele -- */

/**
 * GET /lab/people — cine poate ponta, și cine e în tură acum.
 *
 * Lista NU e „toți utilizatorii de laborator": e „toți cei care au cod de
 * pontaj". Așa se face singură, din cine a primit cod, fără o a doua listă de
 * ținut în acord cu prima — iar un om căruia i s-a luat codul dispare de pe
 * tabletă în aceeași clipă, fără să i se schimbe rolul.
 *
 * Ce pleacă de aici: numele, și dacă e în tură de la ce oră. Nimic altceva —
 * nici rol, nici e-mail, nici telefon. Tableta stă la vedere într-un laborator.
 */
function route_lab_people(): void
{
    require_clock();
    require_lab();

    $rows = all(
        "SELECT u.id, u.name, s.started_at, s.day
           FROM users u
           LEFT JOIN lab_shifts s
             ON s.user_id = u.id AND s.ended_at IS NULL
          WHERE u.lab_pin IS NOT NULL AND u.active = 1 AND u.is_system = 0
          ORDER BY u.name"
    );

    $oameni = [];
    foreach ($rows as $r) {
        $oameni[] = [
            'id'        => (int) $r['id'],
            'name'      => $r['name'],
            'open'      => $r['started_at'] !== null,
            'startedAt' => $r['started_at'],
            // Tura deschisă pe o zi trecută înseamnă că omul a uitat să plece.
            // Tableta o spune, ca să nu ponteze „venit" peste o venire veche.
            'stale'     => $r['started_at'] !== null && (string) $r['day'] < today_local(),
        ];
    }

    json_out(['people' => $oameni]);
}

/**
 * POST /lab/clock — omul a venit, sau pleacă.
 *
 * Un singur buton, nu două: dacă are tură deschisă, apăsarea o închide; dacă
 * nu, o deschide. Două butoane ar fi însemnat că se poate apăsa cel greșit, iar
 * pe un ecran cu mâinile în făină greșeala aia se face. Răspunsul spune ce s-a
 * întâmplat (`action`), ca ecranul să scrie „Bun venit" sau „Spor la odihnă"
 * fără să ghicească.
 *
 * DE CE NU SE POATE PONTA ÎN LOCUL ALTUIA. Codul se cere la fiecare apăsare,
 * și la venire, și la plecare. Ar fi fost mai comod să se ceară doar la venire
 * — dar atunci oricine trecea pe lângă tabletă putea închide tura altuia, iar
 * ora de plecare e chiar cea care face banii.
 */
function route_lab_clock(): void
{
    require_clock();
    require_lab();

    $b   = body();
    $id  = (int) ($b['userId'] ?? 0);
    $pin = trim((string) ($b['pin'] ?? ''));
    $ip  = client_ip();

    if ($id <= 0 || $pin === '') {
        fail('Alege numele și tastează codul.', 422, 'missing_fields');
    }

    // Pragul se verifică ÎNAINTE de citirea contului, ca ghicitul să nu poată
    // afla din viteza răspunsului dacă omul există.
    if (login_rate_limited($ip, lab_attempt_key($id))) {
        fail('Prea multe încercări. Mai așteaptă câteva minute.', 429, 'rate_limited');
    }

    $u = one(
        'SELECT id, name, lab_pin FROM users
          WHERE id = ? AND active = 1 AND is_system = 0 AND lab_pin IS NOT NULL',
        [$id]
    );
    if (!$u || !password_verify($pin, (string) $u['lab_pin'])) {
        record_attempt($ip, lab_attempt_key($id), false);
        // Același text pentru „nu există" și „cod greșit": altfel lista de nume
        // de pe tabletă ar deveni o listă de conturi de ghicit.
        //
        // DE CE 422 ȘI NU 401, deși e vorba de o cheie care nu se potrivește.
        // În aplicația asta 401 e deja un cuvânt cu înțeles hotărât, și nu ăsta:
        // înseamnă „sesiunea a murit". `shared/gp-api.js` aruncă tokenul CSRF
        // la orice 401 (`if (res.status === 401) setCsrf('')`), iar toate cele
        // șaisprezece pagini au același tipar în catch —
        // `if (e.isAuth) gp.toLogin();` — adică pleacă la ecranul de intrare.
        //
        // Aici sesiunea e vie și cu acte în regulă: e cea a tabletei, care a
        // trecut de `require_clock()` cu două rânduri mai sus. Ce s-a dovedit
        // greșit e cheia PERSONALĂ din corpul cererii — un câmp trimis, nu o
        // legitimație. Cu 401, o cifră tastată strâmb cu mâinile în făină ar fi
        // închis tableta întregii ture și ar fi cerut codul de panou, pe care
        // nimeni din laborator nu-l știe pe dinafară.
        //
        // 422 e codul casei pentru „cererea e întreagă, valoarea e refuzată" —
        // același pe care îl dă și punerea unui cod prea ușor de ghicit
        // (api/routes/users.php). `gp.motiv()` întoarce pentru el chiar textul
        // de mai jos, deci omul citește „Cod greșit." și rămâne pe ecran.
        fail('Cod greșit.', 422, 'bad_pin');
    }
    record_attempt($ip, lab_attempt_key($id), true);

    $acum      = now_local();
    $deschisa  = lab_open_shift($id);

    if ($deschisa) {
        // O tură rămasă deschisă de ieri NU se închide la ora de azi: ar scrie
        // pe statul de plată o tură de douăzeci de ore, care n-a existat. Se
        // lasă pe seama administratorului, iar omul își începe ziua curat.
        if ((string) $deschisa['day'] < today_local()) {
            fail(
                'Ai o tură rămasă deschisă din ' . $deschisa['day'] . '. '
                  . 'Spune-i unui administrator s-o încheie; până atunci nu poți ponta.',
                409,
                'stale_shift'
            );
        }
        q('UPDATE lab_shifts SET ended_at = ? WHERE id = ?', [$acum, (int) $deschisa['id']]);
        audit(
            'lab_clock_out',
            'lab_shift',
            (string) $deschisa['id'],
            'Plecare pontată la ' . $acum,
            $id,
            (string) $u['name']
        );
        json_out([
            'action'    => 'out',
            'name'      => $u['name'],
            'startedAt' => $deschisa['started_at'],
            'endedAt'   => $acum,
        ]);
    }

    q(
        'INSERT INTO lab_shifts (user_id, day, started_at) VALUES (?, ?, ?)',
        [$id, today_local(), $acum]
    );
    $nou = (int) db()->lastInsertId();
    audit('lab_clock_in', 'lab_shift', (string) $nou, 'Venire pontată la ' . $acum, $id, (string) $u['name']);

    json_out(['action' => 'in', 'name' => $u['name'], 'startedAt' => $acum, 'endedAt' => null]);
}

/**
 * GET /lab/shifts?from=&to=&user= — raportul, pe zi și pe om.
 *
 * Fără interval, ultimele paisprezece zile: cât ține o tură de brutărie în
 * minte. Intervalul e mărginit la un an, ca o adresă tastată greșit să nu ceară
 * bazei toate turele de la deschiderea patiseriei.
 */
function route_lab_shifts(): void
{
    require_shifts();
    require_lab();

    $azi  = today_local();
    $to   = valid_day(trim((string) ($_GET['to'] ?? '')) ?: $azi);
    $from = valid_day(trim((string) ($_GET['from'] ?? ''))
        ?: (new DateTimeImmutable($to))->modify('-13 days')->format('Y-m-d'));
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    if ((new DateTimeImmutable($from))->modify('+1 year')->format('Y-m-d') < $to) {
        fail('Intervalul cerut e mai lung de un an.', 422, 'range_too_long');
    }

    $cond   = ['s.day BETWEEN ? AND ?'];
    $params = [$from, $to];
    $cine   = (int) ($_GET['user'] ?? 0);
    if ($cine > 0) {
        $cond[]   = 's.user_id = ?';
        $params[] = $cine;
    }

    $rows = all(
        'SELECT s.*, u.name, c.name AS ended_by_name
           FROM lab_shifts s
           JOIN users u ON u.id = s.user_id
           LEFT JOIN users c ON c.id = s.ended_by
          WHERE ' . implode(' AND ', $cond) . '
          ORDER BY s.day DESC, u.name, s.started_at',
        $params
    );

    $ture  = [];
    $peOm  = [];
    foreach ($rows as $r) {
        $t = lab_shift_public($r);
        // Minutele se socotesc AICI, nu în pagină. Pagina primește deja cifra,
        // deci două ecrane nu pot ajunge la două totaluri deosebite, iar o zi
        // fără plecare pontată nu se poate socoti din greșeală ca zero.
        //
        // DE CE POATE IEȘI UN NUMĂR NEGATIV, deși plecarea vine după venire.
        // Orele se scriu ca ceas de perete (`now_local()`), iar ceasul de
        // perete dă înapoi o dată pe an: în ultima duminică din octombrie,
        // 04:00 devine 03:00. Un brutar venit la 03:30 și plecat la 03:10 a
        // lucrat patruzeci de minute, dar scăderea celor două șiruri spune
        // minus douăzeci. Laboratorul lucrează nopțile: fereastra asta nu e
        // una teoretică pentru el.
        //
        // Un minus pe o foaie de ore nu e o ciudățenie de citit, e o scădere
        // din plata omului. Deci rândul nu primește o cifră mincinoasă: nu
        // primește niciuna, și spune de ce. Pontarea de pe tabletă NU se
        // refuză din pricina asta — omul care pleacă trebuie să poată pleca,
        // iar ora lui adevărată rămâne scrisă, de citit cu ochii.
        $brut = $t['endedAt'] === null
            ? null
            : (int) round((strtotime((string) $t['endedAt']) - strtotime((string) $t['startedAt'])) / 60);
        $t['backwards'] = $brut !== null && $brut < 0;
        $t['minutes']   = $t['backwards'] ? null : $brut;
        // Deschis pe ziua de azi e firesc; deschis pe o zi trecută e o uitare.
        $t['open']    = $t['endedAt'] === null;
        $t['stale']   = $t['endedAt'] === null && (string) $t['day'] < $azi;
        $ture[] = $t;

        $k = (int) $r['user_id'];
        if (!isset($peOm[$k])) {
            $peOm[$k] = [
                'userId' => $k, 'name' => $r['name'],
                'shifts' => 0, 'minutes' => 0, 'open' => 0, 'backwards' => 0,
            ];
        }
        $peOm[$k]['shifts']++;
        $peOm[$k]['minutes'] += (int) ($t['minutes'] ?? 0);
        if ($t['stale']) {
            $peOm[$k]['open']++;
        }
        if ($t['backwards']) {
            $peOm[$k]['backwards']++;
        }
    }

    usort($peOm, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

    json_out(['from' => $from, 'to' => $to, 'shifts' => $ture, 'people' => array_values($peOm)]);
}

/**
 * PATCH /lab/shifts/# — administratorul scrie ora de plecare pe care omul a uitat-o.
 *
 * Numai pe un rând rămas deschis, și numai spre închidere. O tură deja încheiată
 * nu se poate rescrie de aici: ora pontată de om e o mărturie, iar o mărturie
 * care se poate schimba pe tăcute nu mai e una. Rândul ține minte cine a scris
 * ora (`ended_by`) și de ce (`note`), plus un rând în jurnal.
 */
function route_lab_shift_update(string $idTxt): void
{
    $cine = require_admin();
    require_lab();

    $id = (int) $idTxt;
    $s  = one('SELECT s.*, u.name FROM lab_shifts s JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$id]);
    if (!$s) {
        fail('Tura nu există.', 404, 'not_found');
    }
    if ($s['ended_at'] !== null) {
        fail(
            'Tura are deja ora de plecare pontată de om. Ora pontată nu se rescrie de aici.',
            409,
            'already_closed'
        );
    }

    $b    = body();
    $ora  = trim((string) ($b['endedAt'] ?? ''));
    $nota = text_field((string) ($b['note'] ?? ''), 'Motivul', 255, false);
    if ($nota === '') {
        fail('Scrie de ce închizi tura de mână — rândul trebuie să-și spună povestea peste o lună.', 422, 'note_required');
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ora)
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $ora);
    if (!$dt) {
        fail('Ora de plecare se scrie ca „2026-09-13 14:30".', 422, 'bad_time');
    }
    $final = $dt->format('Y-m-d H:i:s');

    if ($final <= (string) $s['started_at']) {
        fail('Ora de plecare e înaintea celei de venire (' . $s['started_at'] . ').', 422, 'ends_before_start');
    }
    if ($final > now_local()) {
        fail('Ora de plecare e în viitor.', 422, 'in_the_future');
    }

    q(
        'UPDATE lab_shifts SET ended_at = ?, ended_by = ?, note = ? WHERE id = ?',
        [$final, (int) $cine['id'], $nota, $id]
    );
    audit(
        'lab_shift_close',
        'lab_shift',
        (string) $id,
        'Tura lui ' . $s['name'] . ' din ' . $s['day'] . ', deschisă la ' . $s['started_at']
          . ', încheiată de mână la ' . $final . ' — ' . $nota,
        (int) $s['user_id'],
        (string) $s['name']
    );

    $r = one('SELECT s.*, u.name, c.name AS ended_by_name
                FROM lab_shifts s JOIN users u ON u.id = s.user_id
                LEFT JOIN users c ON c.id = s.ended_by WHERE s.id = ?', [$id]);
    json_out(['shift' => lab_shift_public($r ?: [])]);
}

/**
 * GET /lab/production?from=&to= — bucățile făcute, pe zi și pe tură.
 *
 * ÎNTREBAREA PATRONULUI, cuvânt cu cuvânt: „vreau să știu doar cine era de tură
 * când s-a făcut comanda". Deci raportul NU împarte bucățile pe oameni și nu
 * pretinde că știe cine a pus mâna pe ce. Spune două lucruri deosebite:
 *
 *   · cât s-a făcut, pe zi — cifra adevărată, cea care se adună;
 *   · cine era în laborator în clipele alea — nume, nu porții.
 *
 * DE-AICI IESE SINGURA CAPCANĂ A RAPORTULUI. Când doi oameni sunt de tură în
 * aceeași clipă, bucățile apar ÎNTREGI la amândoi. Nu e o greșeală, e chiar
 * răspunsul la întrebarea pusă — dar înseamnă că rândurile oamenilor NU se
 * adună între ele. Cifra care se adună e cea a zilei, iar `shared` spune pe
 * care zi s-au suprapus ture. Cine ar vrea vreodată să schimbe asta în porții
 * (bucăți ÷ oameni de tură) să știe că inventează o măsurătoare pe care n-a
 * făcut-o nimeni: nimeni n-a numărat cine a pus mâna pe croasant.
 *
 * CE ÎNSEAMNĂ „BUCĂȚI": suma DELTELOR, cu semn. O corecție în jos scade,
 * fiindcă bucățile alea n-au existat. Deci o zi poate ieși și pe minus, dacă
 * azi s-a corectat un raport scris ieri — și e drept așa, ziua de ieri le are.
 *
 * TURA DESCHISĂ se socotește până acum. Pentru cea de azi ăsta e adevărul curat
 * — omul chiar e acolo —, și e tocmai vederea pentru care s-a cerut raportul:
 * se citește la mijlocul turei. Pentru una UITATĂ deschisă din altă zi, marginea
 * din dreapta e o presupunere, nu un pontaj; de-aceea `stale` se cară mai
 * departe peste tot unde ajunge tura aia — pe tură, pe om și pe zi —, ca cifra
 * să nu se citească drept pontaj. O tură de noapte începută aseară și încă în
 * lucru poartă același semn, fiindcă `lab_shifts` nu le deosebește (v.
 * `route_lab_shifts`); rândul se îndreaptă scriind ora de plecare uitată, prin
 * `PATCH /lab/shifts/#`.
 *
 * CE NU CADE PE NICIO TURĂ se numără deoparte, în `loose*`. Golul ăla e o
 * informație, nu un rest: ori s-a lucrat fără pontaj, ori cineva a raportat
 * de-acasă. Topit în total, n-ar mai întreba nimeni de ce.
 */
function route_lab_production(): void
{
    require_shifts();
    require_lab();

    $azi  = today_local();
    $to   = valid_day(trim((string) ($_GET['to'] ?? '')) ?: $azi);
    $from = valid_day(trim((string) ($_GET['from'] ?? ''))
        ?: (new DateTimeImmutable($to))->modify('-13 days')->format('Y-m-d'));
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    if ((new DateTimeImmutable($from))->modify('+1 year')->format('Y-m-d') < $to) {
        fail('Intervalul cerut e mai lung de un an.', 422, 'range_too_long');
    }

    // Fără migrarea 021 raportul iese GOL, nu cu eroare. `api/` și paginile se
    // urcă în aceeași rulare, dar migrarea se dă de mână, uneori mai târziu;
    // între cele două clipe pagina n-are de ce să arate un ecran roșu pentru un
    // raport care încă n-are de unde avea rânduri. `supported` spune de ce e gol.
    if (!lab_production_suportata()) {
        json_out([
            'from'   => $from, 'to' => $to, 'supported' => false,
            'days'   => [], 'shifts' => [], 'people' => [],
            'totals' => [
                'pieces' => 0.0, 'entries' => 0, 'orders' => 0,
                'loosePieces' => 0.0, 'looseEntries' => 0,
            ],
        ]);
    }

    $randuri = all(
        'SELECT id, order_id, item_id, name, delta, at, day
           FROM lab_production
          WHERE day BETWEEN ? AND ?
          ORDER BY at, id',
        [$from, $to]
    );

    // Turele care POT acoperi clipele de mai sus: cele din fereastră, cu o zi în
    // plus la stânga pentru tura de noapte începută aseară, plus toate cele
    // rămase deschise, oricât de vechi — una uitată deschisă acoperă, după
    // socoteala de mai jos, tot ce s-a făcut de-atunci, și tocmai de-aia trebuie
    // să apară, însemnată, nu să lipsească. Căutarea celor deschise nu poate
    // folosi `ix_lab_shift_open` (e `(user_id, ended_at)`, iar noi n-avem omul),
    // deci trece o dată prin tabel; într-o patiserie sănătoasă turele deschise
    // sunt o mână de rânduri, iar când nu mai sunt, raportul o spune singur.
    $ture = all(
        'SELECT s.id, s.user_id, s.day, s.started_at, s.ended_at, u.name
           FROM lab_shifts s
           JOIN users u ON u.id = s.user_id
          WHERE (s.day BETWEEN ? AND ?) OR s.ended_at IS NULL
          ORDER BY s.started_at, s.id',
        [(new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'), $to]
    );

    $acum = now_local();
    $fer  = [];
    foreach ($ture as $t) {
        $deschisa = $t['ended_at'] === null;
        $start    = (string) $t['started_at'];
        $stop     = $deschisa ? $acum : (string) $t['ended_at'];
        $fer[] = [
            'id'        => (int) $t['id'],
            'userId'    => (int) $t['user_id'],
            'name'      => (string) $t['name'],
            'day'       => (string) $t['day'],
            'startedAt' => $start,
            'endedAt'   => $deschisa ? null : $stop,
            'open'      => $deschisa,
            'stale'     => $deschisa && (string) $t['day'] < $azi,
            // Ceasul dat înapoi în ultima duminică din octombrie poate lăsa o
            // plecare scrisă ÎNAINTEA venirii; `route_lab_shifts` numește asta
            // `backwards` și refuză să scadă minutele. Aici fereastra iese goală
            // și tura nu prinde nimic — se spune pe față, nu se ghicește o
            // margine pe care n-a scris-o nimeni.
            'backwards' => $stop < $start,
            'start'     => $start,
            'stop'      => $stop,
            'pieces'    => 0.0, 'entries' => 0, 'orders' => [],
        ];
    }

    $peZi    = [];
    $comenzi = [];
    $slobod  = ['pieces' => 0.0, 'entries' => 0];

    foreach ($randuri as $r) {
        $zi    = (string) $r['day'];
        $clipa = (string) $r['at'];
        $delta = (float) $r['delta'];
        // `order_id` cade pe NULL când comanda se șterge. Bucățile rămân — s-au
        // făcut —, dar nu mai au ce număra la „comenzi atinse": un NULL numărat
        // ca o comandă ar inventa una, iar mai multe NULL-uri ar topi zeci de
        // comenzi șterse într-una singură. Deci numai id-urile adevărate intră.
        $oid   = $r['order_id'] === null ? 0 : (int) $r['order_id'];

        if (!isset($peZi[$zi])) {
            $peZi[$zi] = [
                'day' => $zi, 'pieces' => 0.0, 'entries' => 0, 'orders' => [],
                'people' => [], 'stale' => false, 'shared' => false,
                'loosePieces' => 0.0, 'looseEntries' => 0,
            ];
        }
        $peZi[$zi]['pieces'] += $delta;
        $peZi[$zi]['entries']++;
        if ($oid > 0) {
            $peZi[$zi]['orders'][$oid] = true;
            $comenzi[$oid] = true;
        }

        $prinse = 0;
        foreach ($fer as $i => $t) {
            if ($clipa < $t['start'] || $clipa > $t['stop']) {
                continue;
            }
            $prinse++;
            $fer[$i]['pieces'] += $delta;
            $fer[$i]['entries']++;
            if ($oid > 0) {
                $fer[$i]['orders'][$oid] = true;
            }
            $peZi[$zi]['people'][$t['userId']] = $t['name'];
            if ($t['stale']) {
                $peZi[$zi]['stale'] = true;
            }
        }
        if ($prinse > 1) {
            $peZi[$zi]['shared'] = true;
        }
        if ($prinse === 0) {
            $peZi[$zi]['loosePieces'] += $delta;
            $peZi[$zi]['looseEntries']++;
            $slobod['pieces'] += $delta;
            $slobod['entries']++;
        }
    }

    $peOm        = [];
    $turePublice = [];
    foreach ($fer as $t) {
        // Turele aduse de dincolo de fereastră — cea de aseară, cele uitate
        // deschise — se arată numai dacă au prins ceva. Altfel au fost chemate
        // ca să poată prinde, nu ca să umple raportul cu zile necerute.
        if ($t['entries'] === 0 && ($t['day'] < $from || $t['day'] > $to)) {
            continue;
        }
        $k = $t['userId'];
        if (!isset($peOm[$k])) {
            $peOm[$k] = [
                'userId' => $k, 'name' => $t['name'], 'shifts' => 0,
                'pieces' => 0.0, 'entries' => 0, 'orders' => [], 'stale' => false,
            ];
        }
        $peOm[$k]['shifts']++;
        $peOm[$k]['pieces']  += $t['pieces'];
        $peOm[$k]['entries'] += $t['entries'];
        foreach (array_keys($t['orders']) as $oid) {
            $peOm[$k]['orders'][$oid] = true;
        }
        if ($t['stale']) {
            $peOm[$k]['stale'] = true;
        }

        $turePublice[] = [
            'id'        => $t['id'],
            'userId'    => $k,
            'name'      => $t['name'],
            'day'       => $t['day'],
            'startedAt' => $t['startedAt'],
            'endedAt'   => $t['endedAt'],
            'open'      => $t['open'],
            'stale'     => $t['stale'],
            'backwards' => $t['backwards'],
            'pieces'    => round($t['pieces'], 2),
            'entries'   => $t['entries'],
            'orders'    => count($t['orders']),
        ];
    }

    $zile = [];
    foreach ($peZi as $z) {
        $oameniZi = [];
        foreach ($z['people'] as $uid => $nume) {
            $oameniZi[] = ['userId' => (int) $uid, 'name' => (string) $nume];
        }
        usort($oameniZi, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        $zile[] = [
            'day'          => $z['day'],
            'pieces'       => round($z['pieces'], 2),
            'entries'      => $z['entries'],
            'orders'       => count($z['orders']),
            'people'       => $oameniZi,
            'stale'        => $z['stale'],
            'shared'       => $z['shared'],
            'loosePieces'  => round($z['loosePieces'], 2),
            'looseEntries' => $z['looseEntries'],
        ];
    }

    $oameni = [];
    foreach ($peOm as $o) {
        $o['pieces'] = round($o['pieces'], 2);
        $o['orders'] = count($o['orders']);
        $oameni[] = $o;
    }

    // Aceeași rânduială ca la `GET /lab/shifts`, ca cele două rapoarte să se
    // citească unul lângă altul fără să-ți mute ochii: zilele de la cea mai
    // proaspătă în jos, oamenii pe nume.
    usort($zile, static fn (array $a, array $b): int => strcmp($b['day'], $a['day']));
    usort($oameni, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
    usort($turePublice, static fn (array $a, array $b): int => strcmp($b['day'], $a['day'])
        ?: (strcmp($a['name'], $b['name'])
        ?: strcmp((string) $a['startedAt'], (string) $b['startedAt'])));

    $totalBucati = 0.0;
    foreach ($randuri as $r) {
        $totalBucati += (float) $r['delta'];
    }

    json_out([
        'from'      => $from,
        'to'        => $to,
        'supported' => true,
        'days'      => $zile,
        'shifts'    => $turePublice,
        'people'    => $oameni,
        'totals'    => [
            'pieces'       => round($totalBucati, 2),
            'entries'      => count($randuri),
            'orders'       => count($comenzi),
            'loosePieces'  => round($slobod['pieces'], 2),
            'looseEntries' => $slobod['entries'],
        ],
    ]);
}

/* ------------------------------ tabla de anunțuri (migrarea 015) -- */

/**
 * Câte anunțuri pot sta deodată pe ecran.
 *
 * Ecranul acela e al comenzilor zilei; anunțurile stau deasupra lor. Fără o
 * margine, a zecea hârtie lipită pe geam ar împinge tocmai lucrul pentru care
 * se uită brutarul la ecran sub marginea de jos — și nimeni n-ar fi apăsat
 * nimic ca să se întâmple asta. Cinci e cât încape deasupra fără să împingă;
 * al șaselea e refuzat cu numele lucrului de făcut: ia unul jos.
 */
const GP_LAB_MESSAGES_MAX = 5;

/** Câte anunțuri mai țin acum. Aceeași întrebare ca filtrul de la citire. */
function lab_messages_live(): int
{
    return (int) scalar(
        'SELECT COUNT(*) FROM lab_messages WHERE until IS NULL OR until > ?',
        [now_local()]
    );
}

/**
 * Cine poate lua jos anunțul ăsta.
 *
 * Regula stă scrisă o dată și se citește de două ori: la `DELETE`, ca pază, și
 * la `GET`, ca pagina să știe pe care anunț desenează butonul. Scrisă în două
 * locuri, s-ar fi depărtat exact în ziua în care ecranul ar fi arătat un buton
 * care răspunde „n-ai voie".
 *
 * Un `created_by` gol — contul celui care l-a pus s-a șters între timp — nu se
 * potrivește cu nimeni: `(int) null` e 0, iar un `id` de om nu e niciodată 0.
 * Anunțul orfan rămâne al administratorilor. Altfel ar fi rămas nemuritor pe
 * ecran, fiindcă singurul om care avea voie să-l ia jos nu mai există.
 *
 * PRIMA ÎNTREBARE E CEA DE LA UȘĂ, deși pare de prisos aici: ruta cere oricum
 * `require_shifts()` înaintea acestei funcții. Dar răspunsul pleacă și spre
 * pagină, ca `canDelete`, iar acolo nu mai trece prin nicio ușă. Un manager
 * scoborât la „angajat" și-ar fi văzut anunțurile vechi cu butonul „Ia jos" pe
 * ele — un buton care răspunde 403, adică tocmai lucrul pentru care s-a scris
 * funcția asta într-un singur loc.
 */
function lab_message_deletable(array $cine, array $rand): bool
{
    if (!leads_shifts($cine)) {
        return false;
    }
    return e_admin($cine) || (int) ($rand['created_by'] ?? 0) === (int) $cine['id'];
}

/** Forma în care pleacă un anunț spre pagină. */
function lab_message_public(array $r, array $cine): array
{
    return [
        'id'        => (int) $r['id'],
        'body'      => $r['body'],
        'until'     => $r['until'],
        'createdAt' => $r['created_at'],
        // Numele se citește prin JOIN, nu se ține copiat în rând: gol după
        // ștergerea contului, și mereu cel de azi după o redenumire.
        'author'    => $r['author_name'] ?? null,
        'canDelete' => lab_message_deletable($cine, $r),
    ];
}

/**
 * Ora locală până la care ține anunțul. Gol înseamnă fără termen.
 *
 * Ceas de perete față de ceas de perete — vezi preambulul migrării 015. Nu se
 * cheamă `CONVERT_TZ` nicăieri: pe o instalare fără tabelele de fusuri
 * încărcate în MySQL, acela întoarce NULL, iar un anunț cu termenul NULL e
 * unul fără termen. Adică exact greșeala care nu s-ar fi văzut.
 */
function lab_message_until(string $ora): ?string
{
    if ($ora === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ora)
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $ora);
    if (!$dt) {
        fail('Ora până la care ține anunțul se scrie ca „2026-09-13 14:30".', 422, 'bad_time');
    }
    $pana = $dt->format('Y-m-d H:i:s');
    $acum = now_local();
    if ($pana <= $acum) {
        fail(
            'Ora aceea a trecut deja (la patiserie e acum ' . substr($acum, 0, 16)
              . '). Anunțul s-ar stinge înainte să apuce cineva să-l citească.',
            422,
            'in_the_past'
        );
    }
    if ($pana > (new DateTimeImmutable($acum))->modify('+1 year')->format('Y-m-d H:i:s')) {
        fail(
            'Un an e cel mai lung termen. Pentru un anunț care ține până îl ia cineva jos, '
              . 'lasă ora goală.',
            422,
            'too_far'
        );
    }
    return $pana;
}

/**
 * GET /lab/messages — ce mai ține acum pe ecranul laboratorului.
 *
 * POARTA E `require_ops()`, adică și contul `panou` al tabletei (rol
 * `angajat`): anunțul e scris ca să-l citească toată tura, iar tura se uită la
 * ecran prin contul acela. Omul de laborator (`utilizator_lab`) nu intră aici —
 * el n-are decât Pontajul, iar o poartă lărgită „că oricum nu strică" e chiar
 * felul în care s-a născut defectul de la comenzi (`orders_readable()`).
 *
 * CE NU PLEACĂ DE AICI: anunțurile cărora le-a trecut termenul. Rândurile rămân
 * în tabel, ca urmă a ceea ce s-a spus laboratorului și când, dar ecranul arată
 * numai ce ține acum — un anunț de marțea trecută, citit miercuri, e mai rău
 * decât niciunul.
 *
 * ORDINEA E DUPĂ `id`, nu după oră: două anunțuri puse în aceeași secundă au
 * aceeași oră, iar o ordine care se schimbă de la o rotire a panoului la alta
 * face ecranul să pâlpâie sub ochii oamenilor. `id`-ul crește întotdeauna.
 */
function route_lab_messages(): void
{
    $cine = require_ops();
    require_lab_board();

    $rows = all(
        'SELECT m.*, u.name AS author_name
           FROM lab_messages m
           LEFT JOIN users u ON u.id = m.created_by
          WHERE m.until IS NULL OR m.until > ?
          ORDER BY m.id DESC',
        [now_local()]
    );

    $mesaje = [];
    foreach ($rows as $r) {
        $mesaje[] = lab_message_public($r, $cine);
    }

    json_out([
        'messages' => $mesaje,
        // Pagina nu ține o copie a listei de roluri: întreabă serverul la
        // fiecare citire și desenează butonul după răspuns. Vezi leads_shifts().
        'canPost'  => leads_shifts($cine),
        'max'      => GP_LAB_MESSAGES_MAX,
    ]);
}

/**
 * POST /lab/messages — se lipește o hârtie pe ecran.
 */
function route_lab_message_new(): void
{
    $cine = require_shifts();
    require_lab_board();

    $b = body();
    // `text_field()` strânge rândurile noi la un singur spațiu, și bine face:
    // pe un ecran privit de la doi metri, cu mâinile în aluat, un anunț e o
    // propoziție, nu o pagină. Cine are de spus două lucruri pune două
    // anunțuri — și atunci poate lua jos numai pe cel care s-a împlinit.
    $text = text_field($b['body'] ?? '', 'Anunțul', 500, true);
    $pana = lab_message_until(trim((string) ($b['until'] ?? '')));

    // Numărătoarea se face ÎNAINTE de scriere, nu se taie ecranul după. Două
    // apăsări în aceeași clipă ar putea trece amândouă — o dată la câțiva ani,
    // și atunci ecranul are șase anunțuri până ia cineva unul jos. O încuietoare
    // pe tabel ar costa mai mult decât paguba pe care o oprește.
    if (lab_messages_live() >= GP_LAB_MESSAGES_MAX) {
        fail(
            'Pe ecran stau deja ' . GP_LAB_MESSAGES_MAX . ' anunțuri — cât încape deasupra '
              . 'comenzilor fără să le împingă din ecran. Ia unul jos și pune-l pe ăsta.',
            409,
            'board_full'
        );
    }

    $acum = now_local();
    q(
        'INSERT INTO lab_messages (body, until, created_at, created_by) VALUES (?, ?, ?, ?)',
        [$text, $pana, $acum, (int) $cine['id']]
    );
    $id = (int) db()->lastInsertId();

    // ANUNȚUL INTRĂ ÎN JURNAL CU TOT CU TEXT, spre deosebire de un cod sau de o
    // cheie de setare: e tocmai lucrul scris ca să-l citească toată tura, deci
    // n-are ce ascunde. Iar peste o lună, întrebarea „ce le-am spus despre
    // livrarea de marți?" are unde să-și caute răspunsul.
    audit(
        'lab_message_post',
        'lab_message',
        (string) $id,
        'Anunț pus pe ecranul laboratorului'
          . ($pana === null ? ', fără termen' : ', până la ' . $pana) . ': ' . $text
    );

    $r = one(
        'SELECT m.*, u.name AS author_name FROM lab_messages m
           LEFT JOIN users u ON u.id = m.created_by WHERE m.id = ?',
        [$id]
    );
    json_out(['message' => lab_message_public($r ?: [], $cine)], 201);
}

/**
 * DELETE /lab/messages/# — anunțul se ia jos.
 *
 * NU ȘTERGE RÂNDUL: îi pune termenul pe clipa asta. Ecranul îl pierde pe loc,
 * fiindcă e același filtru ca la citire, iar locul de pe tablă se eliberează
 * tot pe loc — dar urma a ceea ce s-a spus laboratorului rămâne, cum scrie în
 * preambulul migrării 015. Un rând șters ar fi luat cu el și răspunsul la
 * „când le-am spus?".
 *
 * POARTA ARE DOUĂ STRATURI. Ia jos cine conduce tura (`require_shifts()`), dar
 * numai anunțul lui — afară de administratori și fondatori, care pot lua jos
 * orice anunț, inclusiv al altuia. A doua îngustare e cerută anume de patron,
 * și are un rost limpede: managerul plecat în concediu cu un anunț uitat pe
 * ecran nu ține tabla ocupată până se întoarce.
 */
function route_lab_message_delete(string $idTxt): void
{
    $cine = require_shifts();
    require_lab_board();

    $id = (int) $idTxt;
    $m  = one('SELECT * FROM lab_messages WHERE id = ?', [$id]);
    if (!$m) {
        fail('Anunțul nu există.', 404, 'not_found');
    }

    $acum = now_local();
    // Deja stins — sau i-a trecut termenul singur, sau l-a luat altcineva jos
    // între rotirea panoului și apăsarea butonului. Răspunsul spune amândouă
    // înțelesurile, fiindcă de pe ecran nu se pot deosebi, iar omul are nevoie
    // să știe un singur lucru: nu mai e acolo, și nu din vina apăsării lui.
    if ($m['until'] !== null && (string) $m['until'] <= $acum) {
        fail(
            'Anunțul nu mai e pe ecran: ori i-a trecut termenul, ori l-a luat altcineva jos.',
            409,
            'already_down'
        );
    }
    if (!lab_message_deletable($cine, $m)) {
        fail(
            'Anunțul e pus de altcineva. Îl poate lua jos cine l-a scris, un administrator '
              . 'sau un fondator.',
            403,
            'forbidden'
        );
    }

    q('UPDATE lab_messages SET until = ? WHERE id = ?', [$acum, $id]);
    audit(
        'lab_message_down',
        'lab_message',
        (string) $id,
        'Anunț luat de pe ecranul laboratorului, pus la ' . $m['created_at'] . ': ' . $m['body']
    );

    json_out(['ok' => true]);
}

/* ------------- ordinea de lucru și împărțirea producției (migrarea 023) -- */

/**
 * PUT /lab/ordine — în ce ordine lucrează laboratorul ziua.
 *
 * Croită după sora ei gata scrisă, `route_driver_reorder()`
 * (api/routes/orders.php): ștergem întâi ordinea zilei, apoi scriem pozițiile
 * una câte una, FILTRAT PE ZI, totul într-o tranzacție. Filtrul pe zi e cel
 * care oprește mutarea unei comenzi din altă zi; ștergerea dinainte e cea care
 * face ca o comandă scoasă din listă să-și recapete locul firesc, în loc să
 * rămână cu poziția veche.
 *
 * DAR CU `require_ops()`, NU `require_admin()`. Sora ei e închisă la
 * administrator dinadins, și comentariul ei spune de ce: ordinea opririlor o
 * pune biroul, iar serverul lăsat deschis întregului personal însemna că
 * „ordinea se putea răsturna din contul comun al tabletelor, iar șoferul pleca
 * pe alt traseu decât cel stabilit". AICI LUCRUL E PE DOS: tocmai contul comun
 * al tabletelor trebuie să poată aranja — el e în fața cuptorului —, iar paguba
 * e mică și de-o zi. Două rute surori cu două drepturi deosebite, fiecare cu
 * pricina scrisă lângă ea.
 *
 * Corpul cererii: { day: 'YYYY-MM-DD', ids: [12, 7, 30] }.
 * O listă goală șterge ordinea de mână și revine la ordinea numerelor.
 */
function route_lab_ordine(): void
{
    require_ops();
    $b   = body();
    $zi  = valid_day(trim((string) ($b['day'] ?? '')));
    $ids = $b['ids'] ?? [];
    if (!is_array($ids)) {
        fail('Lista de comenzi trebuie să fie un tablou de id-uri.', 422, 'bad_ids');
    }
    // `lab_seq` e SMALLINT UNSIGNED: peste 65535 MySQL ar da eroare, iar pe o
    // configurație blândă ar tăia tăcut la 65535 și toate comenzile de după ar
    // ajunge pe aceeași poziție. Panoul arată oricum cel mult 500 pe zi.
    if (count($ids) > 1000) {
        fail('Prea multe comenzi într-o singură rearanjare.', 422, 'too_many_ids');
    }

    db()->beginTransaction();
    try {
        q('UPDATE orders SET lab_seq = NULL WHERE day = ?', [$zi]);

        $pos = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            q('UPDATE orders SET lab_seq = ? WHERE id = ? AND day = ?', [$pos, $id, $zi]);
            $pos++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    audit('lab_reorder', 'lab', $zi, $pos > 0 ? $pos . ' comenzi' : 'ordine ștearsă');
    json_out(['ok' => true, 'day' => $zi, 'orders' => $pos]);
}

/**
 * Ziua, zăvorâtă, cu pozițiile produsului cerut — temelia celor două rute de
 * mai jos.
 *
 * CHEAMĂ-O DIN TRANZACȚIE: pune `FOR UPDATE` pe comenzile zilei.
 *
 * ZĂVORÂREA SE FACE ÎN ORDINEA `id`, NU ÎN ORDINEA DE LUCRU. Aici a greșit
 * ciorna rundei, și greșeala ar fi costat scump: `route_stock_sync()` ia
 * comenzile `ORDER BY id FOR UPDATE`, și tot pe `id` le ia și
 * `route_order_production()`. Zăvorâte în ordinea `lab_seq`, două tranzacții
 * ar putea apuca aceleași două rânduri în ordine inversă — IMPAS, în mijlocul
 * turei, cu aluatul pe masă. Se zăvorăsc pe `id`, și abia apoi se așază în
 * ordinea de lucru, în PHP, unde nicio bază nu mai are ce bloca.
 *
 * ORDINEA DE LUCRU: `lab_seq` întâi, cele nearanjate la coadă, iar între egale
 * numărul zilei (`seq`). Aceeași ordine pe care o arată panoul, fiindcă
 * `GET /orders` întoarce ziua `ORDER BY day ASC, seq ASC` — dacă cele două
 * s-ar despărți, omul ar vedea împărțindu-se altundeva decât se uită.
 *
 * POTRIVIREA POZIȚIE → PRODUS oglindește felul în care panoul strânge rândurile
 * raftului: după numărul produsului când îl are, după nume numai pentru
 * pozițiile scrise de mână, care n-au număr. Scrisă altfel decât acolo, caseta
 * ar spune „170 / 300" și ar umple alte trei sute.
 *
 * @return array{0: array, 1: array} [comenzile zilei în ordinea de lucru,
 *         pozițiile produsului, așezate pe id-ul comenzii]
 */
function lab_ziua_si_produsul(string $zi, int $pid, string $nume): array
{
    $comenzi = all(
        'SELECT id, ref, seq, customer_name, status, lab_seq FROM orders WHERE day = ? ORDER BY id FOR UPDATE',
        [$zi]
    );

    usort($comenzi, static function (array $a, array $b): int {
        $la = $a['lab_seq'] === null ? PHP_INT_MAX : (int) $a['lab_seq'];
        $lb = $b['lab_seq'] === null ? PHP_INT_MAX : (int) $b['lab_seq'];
        return $la !== $lb ? $la <=> $lb : (int) $a['seq'] <=> (int) $b['seq'];
    });

    $pePoz = [];
    foreach (productie_pozitii(array_map(static fn(array $o): int => (int) $o['id'], $comenzi)) as $p) {
        $potrivit = $pid > 0
            ? (int) ($p['product_id'] ?? 0) === $pid
            : (($p['product_id'] ?? null) === null && (string) $p['name'] === $nume);
        if ($potrivit) {
            $pePoz[(int) $p['order_id']][] = $p;
        }
    }

    return [$comenzi, $pePoz];
}

/**
 * Cum se numește produsul cerut, în cuvintele cererii — pentru mesaje.
 */
function lab_numele_produsului(int $pid, string $nume): string
{
    return $nume !== '' ? $nume : 'produsul #' . $pid;
}

/**
 * Cele două câmpuri care spun CARE produs, citite la fel de amândouă rutele.
 *
 * @return array{0: int, 1: string}
 */
function lab_produsul_cerut(array $b): array
{
    $pid  = ($b['productId'] ?? null) === null ? 0 : (int) $b['productId'];
    $nume = trim((string) ($b['name'] ?? ''));
    if ($pid <= 0 && $nume === '') {
        fail('Spune care produs s-a făcut: numărul lui, ori numele scris pe poziție.', 422, 'bad_product');
    }
    return [$pid, $nume];
}

/**
 * POST /lab/productie/imparte — câte bucăți s-au făcut, împărțite pe ziua asta.
 *
 * HOTĂRÂREA PATRONULUI, 19 septembrie 2026: se tastează „tocmai am mai făcut
 * N", iar cifra cade pe comenzile zilei DE SUS ÎN JOS, în ordinea de lucru.
 *
 * DE CE E O RUTĂ, ȘI NU N CERERI DIN BROWSER. Două tablete care introduc în
 * aceeași clipă ar umple de două ori aceleași comenzi — fiecare a citit lista
 * înainte ca cealaltă să scrie. Iar o cădere la jumătatea celor N cereri ar
 * lăsa ziua strâmbă, cu făina scăzută pentru bucăți care nu figurează nicăieri.
 * Aici totul stă într-o singură tranzacție: ori intră toată cifra, ori niciuna.
 *
 * SURPLUSUL NU SE REFUZĂ ȘI NU SE PIERDE. Ce trece peste tot ce cerea ziua se
 * scrie ca marfă a casei: intrare pe finit cu `order_id = NULL`, consumul de
 * materie primă, și un rând în registrul de producție fără comandă — tabelul a
 * fost făcut anume cu `order_id` care primește NULL. Fără rândul acela, cine
 * face douăzeci de bucăți în plus a muncit degeaba în raportul de tură.
 *
 * ȘI ASTA E TOTUL, o singură dată. Mașinăria care scrie (`productie_aplica()`)
 * pune deja intrarea pe finit pentru bucățile căzute pe comenzi. Dacă
 * împărțirea ar mai adăuga o mișcare cu cantitatea ÎNTREAGĂ, raftul ar arăta
 * +220 pentru 120 de croasante. Hotărârea patronului — „în depozit intră
 * întreaga cantitate produsă" — se ține din cele două bucăți: cele căzute pe
 * comenzi plus prisosul.
 *
 * SE SAR comenzile anulate și cele ajunse la etapa finală. Ruta veche, pe o
 * singură comandă, nu sare peste etapa finală dinadins: acolo un om atinge
 * anume o comandă și știe ce face. Aici alege mașina, iar o comandă plecată la
 * client n-are ce primi.
 *
 * Corpul: { day, productId | name, qty }. Răspunsul spune ce s-a așezat, ce a
 * trecut peste, și LOTUL — lista exactă a pozițiilor atinse, pe care „Înapoi"
 * o dă îndărăt prin `POST /lab/productie/desfa`.
 */
function route_lab_productie_imparte(): void
{
    $u = require_ops();
    $b = body();

    $zi           = valid_day(trim((string) ($b['day'] ?? '')));
    [$pid, $nume] = lab_produsul_cerut($b);
    $cat          = num_field($b['qty'] ?? null, 'Cantitatea făcută', 0.01, 99999999.99, 'bad_qty');

    // Cifrele din mesaje se scriu ca la tejghea: „8”, nu „8,00”.
    $nr = static fn(float $x): string => rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',');

    $pdo = db();
    $pdo->beginTransaction();

    // `fail()` încheie scriptul pe loc. Chemat cu tranzacția deschisă, ar lăsa
    // comenzile zilei prinse cu FOR UPDATE până când găzduirea închide
    // conexiunea — adică toată ziua blocată pentru toată tura. Același tipar ca
    // `$opreste` din `route_order_production()`.
    $opreste = static function (string $mesaj, int $status, string $cod) use ($pdo): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail($mesaj, $status, $cod);
    };

    $miscari = 0;
    $fara    = [];
    $lot     = [];
    $ramas   = $cat;

    try {
        [$comenzi, $pePoz] = lab_ziua_si_produsul($zi, $pid, $nume);

        if (!$pePoz) {
            // Panoul arată numai produse care SUNT pe comenzile zilei, deci o
            // cerere fără nicio potrivire înseamnă ori un ecran rămas în urmă,
            // ori o zi golită între timp. Un surplus scris pe un produs pe care
            // ziua nu-l cunoaște ar fi o intrare în depozit pe care n-o poate
            // lega nimeni de nimic — mai bine se spune.
            $opreste(
                'Produsul „' . lab_numele_produsului($pid, $nume) . '” nu e pe nicio comandă din '
                    . $zi . '. Reîncarcă lista: între timp ziua s-a schimbat.',
                422,
                'product_not_today'
            );
        }

        // Identitatea produsului pentru prisos — raftul lui și rețeta lui — se
        // ia de pe PRIMA poziție potrivită, nu dintr-o a doua căutare scrisă
        // aici: potrivirea poziție → articol de depozit are un singur izvor
        // (`productie_pozitii()`), iar o a doua ar fi tocmai felul în care
        // producția ar intra într-un raft și scăderea ar ieși din altul.
        $intaia = $pePoz[array_key_first($pePoz)][0];
        $reteta = productie_retete([(int) ($intaia['product_id'] ?? 0)]);
        $linii  = $reteta[(int) ($intaia['product_id'] ?? 0)] ?? [];

        $anulata = status_cancelled();
        $finala  = status_done();

        foreach ($comenzi as $o) {
            if ($ramas <= 0) {
                break;
            }
            $st = (string) $o['status'];
            if (($anulata !== '' && $st === $anulata) || ($finala !== '' && $st === $finala)) {
                continue;
            }
            $oid = (int) $o['id'];
            if (!isset($pePoz[$oid])) {
                continue;
            }

            // Aceeași însemnare ca la raportarea de pe fișă, ca rândurile să se
            // citească pereche în registrul depozitului: „#1042 Bistro”.
            $nota = trim((string) ($o['ref'] ?? '') . ' ' . (string) ($o['customer_name'] ?? ''));

            foreach ($pePoz[$oid] as $p) {
                if ($ramas <= 0) {
                    break;
                }
                $cerut = (float) $p['qty'];
                $facut = (float) $p['done_qty'];
                $gol   = round($cerut - $facut, 2);
                if ($gol <= 0) {
                    continue;
                }
                $pune = round(min($gol, $ramas), 2);
                if ($pune <= 0) {
                    continue;
                }

                $urma = productie_aplica($p, $pune, round($facut + $pune, 2), $linii, $nota, (int) $u['id'], $oid);
                $miscari += $urma['miscari'];
                if ($urma['fara'] !== null) {
                    $fara[] = $urma['fara'];
                }
                $lot[]  = ['o' => $oid, 'i' => (int) $p['id'], 'd' => $pune];
                $ramas  = round($ramas - $pune, 2);
            }
        }

        $prisos = round($ramas, 2);
        if ($prisos > 0) {
            // Marfa casei: nu e a nimănui, deci nu are poziție și nu are
            // comandă. `productie_aplica()` citește tocmai lipsa lui `id` ca
            // „n-are unde scrie `done_qty` și n-are cui stinge rezervări”.
            $alCasei = [
                'name'       => (string) $intaia['name'],
                'product_id' => $intaia['product_id'],
                'item_id'    => $intaia['item_id'],
                'item_name'  => $intaia['item_name'],
            ];
            $urma = productie_aplica(
                $alCasei,
                $prisos,
                null,
                $linii,
                'Peste necesarul zilei ' . $zi,
                (int) $u['id'],
                null
            );
            $miscari += $urma['miscari'];
            if ($urma['fara'] !== null) {
                $fara[] = $urma['fara'];
            }
            $lot[] = ['o' => null, 'i' => null, 'd' => $prisos];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $asezat = round($cat - $ramas, 2);
    audit(
        'lab_production_split',
        'lab',
        $zi,
        lab_numele_produsului($pid, $nume) . ' · ' . $nr($cat) . ' buc. · ' . $nr($asezat)
            . ' pe comenzi · ' . $nr(max($ramas, 0)) . ' peste · ' . $miscari . ' mișcări de stoc'
    );

    json_out([
        'day'     => $zi,
        'qty'     => $cat,
        'placed'  => $asezat,
        'surplus' => round(max($ramas, 0), 2),
        'moves'   => $miscari,
        // Pozițiile fără articol finit în depozit: intrarea n-a avut unde se
        // scrie. Pagina le arată, ca lipsa să nu se descopere abia la scădere.
        'missing' => array_values(array_unique($fara)),
        // Lotul, pentru „Înapoi". Nu e o scădere ghicită: e chiar ce s-a scris.
        'lot'     => $lot,
    ]);
}

/**
 * POST /lab/productie/desfa — „Înapoi": dă îndărăt exact ultima împărțire.
 *
 * DE CE UN LOT, ȘI NU O CIFRĂ CU MINUS. O scădere de N bucăți ar trebui să
 * ghicească de unde le ia. Luată de jos în sus, ar scoate bucăți din alte
 * poziții decât cele umplute — dacă între timp un coleg a mai raportat ceva,
 * omul care apasă „Înapoi" ar desface munca aceluia, nu pe a lui. Cu lotul,
 * desfacerea atinge exact rândurile pe care le-a scris împărțirea.
 *
 * DE CE E TOTUȘI PĂZITĂ. Lotul vine din browser, deci e o CERERE, nu un
 * adevăr. Fiecare poziție se recitește sub zăvor și se scade numai cât mai e
 * acolo: dacă între timp cineva a corectat în jos, nu se coboară sub zero, și
 * nu se scade de două ori dacă omul apasă „Înapoi" de două ori.
 *
 * Corpul: { day, productId | name, lot: [{o, i, d}, …] }.
 */
function route_lab_productie_desfa(): void
{
    $u = require_ops();
    $b = body();

    $zi           = valid_day(trim((string) ($b['day'] ?? '')));
    [$pid, $nume] = lab_produsul_cerut($b);
    $lot          = $b['lot'] ?? null;
    if (!is_array($lot) || !$lot) {
        fail('Spune ce lot se dă îndărăt.', 422, 'empty_lot');
    }
    if (count($lot) > 1000) {
        fail('Prea multe poziții într-un singur lot.', 422, 'too_many_lot');
    }

    $nr = static fn(float $x): string => rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',');

    $pdo = db();
    $pdo->beginTransaction();
    $opreste = static function (string $mesaj, int $status, string $cod) use ($pdo): void {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail($mesaj, $status, $cod);
    };

    $miscari = 0;
    $luat    = 0.0;

    try {
        [, $pePoz] = lab_ziua_si_produsul($zi, $pid, $nume);
        if (!$pePoz) {
            $opreste(
                'Produsul „' . lab_numele_produsului($pid, $nume) . '” nu mai e pe nicio comandă din '
                    . $zi . '. N-am ce da îndărăt.',
                422,
                'product_not_today'
            );
        }

        $dupaId = [];
        foreach ($pePoz as $poz) {
            foreach ($poz as $p) {
                $dupaId[(int) $p['id']] = $p;
            }
        }
        $intaia = $pePoz[array_key_first($pePoz)][0];
        $reteta = productie_retete([(int) ($intaia['product_id'] ?? 0)]);
        $linii  = $reteta[(int) ($intaia['product_id'] ?? 0)] ?? [];

        foreach ($lot as $rand) {
            if (!is_array($rand)) {
                $opreste('Lotul are un rând care nu se poate citi.', 422, 'bad_lot');
            }
            $cat = num_field($rand['d'] ?? null, 'Cantitatea din lot', 0.01, 99999999.99, 'bad_lot_qty');
            $poz = ($rand['i'] ?? null) === null ? 0 : (int) $rand['i'];

            if ($poz === 0) {
                // Rândul prisosului: n-are poziție și n-are comandă, deci nu se
                // poate plafona din `done_qty`. Se dă îndărăt întreg — e chiar
                // marfa casei, scrisă adineaori de aceeași apăsare.
                $alCasei = [
                    'name'       => (string) $intaia['name'],
                    'product_id' => $intaia['product_id'],
                    'item_id'    => $intaia['item_id'],
                    'item_name'  => $intaia['item_name'],
                ];
                $urma = productie_aplica(
                    $alCasei,
                    -$cat,
                    null,
                    $linii,
                    'Luat înapoi din prisosul zilei ' . $zi,
                    (int) $u['id'],
                    null
                );
                $miscari += $urma['miscari'];
                $luat    = round($luat + $cat, 2);
                continue;
            }

            if (!isset($dupaId[$poz])) {
                // Poziția a fost rescrisă între timp (`PATCH /orders` le șterge
                // și le scrie la loc). Nu e o eroare a omului: ce voia să dea
                // îndărăt nu mai există, deci nu mai are ce strica.
                continue;
            }
            $p     = $dupaId[$poz];
            $facut = (float) $p['done_qty'];
            $scade = round(min($cat, max($facut, 0)), 2);
            if ($scade <= 0) {
                continue;
            }

            $o    = one('SELECT ref, customer_name FROM orders WHERE id = ?', [(int) $p['order_id']]);
            $nota = trim((string) ($o['ref'] ?? '') . ' ' . (string) ($o['customer_name'] ?? ''));

            $urma = productie_aplica(
                $p,
                -$scade,
                round($facut - $scade, 2),
                $linii,
                $nota,
                (int) $u['id'],
                (int) $p['order_id']
            );
            $miscari += $urma['miscari'];
            $luat     = round($luat + $scade, 2);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit(
        'lab_production_undo',
        'lab',
        $zi,
        lab_numele_produsului($pid, $nume) . ' · −' . $nr($luat) . ' buc. · ' . $miscari . ' mișcări de stoc'
    );

    json_out(['day' => $zi, 'taken' => $luat, 'moves' => $miscari]);
}
