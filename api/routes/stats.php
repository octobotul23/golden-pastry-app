<?php
/**
 * Statistici — cifrele casei, numai pentru fondator.
 *
 * O singură rută, `GET /stats`, care întoarce dintr-o dată tot ce arată pagina
 * Statistici: rezumatul, inelul, zilele, produsele, clienții și listele de
 * filtre. Nu zece rute mici, fiindcă toate ar citi aceleași rânduri cu aceleași
 * filtre, iar zece cereri care pleacă odată ar da zece socoteli care nu se
 * potrivesc între ele dacă o comandă se schimbă între ele.
 *
 * TREI HOTĂRÂRI CARE SE VĂD ÎN FIECARE CIFRĂ DE MAI JOS
 *
 * 1. SE SOCOTEȘTE DIN POZIȚII, NU DIN TOTALUL COMENZII. Adică
 *    `order_items.qty * order_items.unit_price`, nu `orders.total`. Se poate,
 *    fiindcă reducerea e deja scăzută din prețul bucății (`promo_unit_price()`,
 *    api/lib/promo.php) — deci suma pozițiilor ESTE totalul comenzii, nu o
 *    socoteală la preț de catalog. Și trebuie, fiindcă un total de comandă nu
 *    se poate despica pe produse: fără poziții n-ar exista nici „cât a adus
 *    fiecare produs", nici filtrul pe categorie, nici cel pe felul mărfii.
 *
 *    Urmarea, spusă pe față: o comandă fără nicio poziție nu se numără nicăieri.
 *    Nu e o scăpare — o comandă goală n-a vândut nimic.
 *
 * 2. FELUL MĂRFII VINE DE PE POZIȚIE. `orders.variant` spune doar ce e
 *    majoritar și ajunge „mixt" când sunt amândouă (vezi sql/001_schema.sql);
 *    adevărul e în `order_items.variant`. Filtrul „congelate" pe o comandă
 *    mixtă ia deci NUMAI rândurile congelate din ea, nu comanda întreagă.
 *
 * 3. ETAPA DE ANULARE NU E SCRISĂ ÎN COD. Se cere de la `status_cancelled()`,
 *    care o citește din setarea `order_status_cancelled`. Dacă patronul
 *    redenumește etapa din panou, socoteala află; scrisă aici pe litere, ar fi
 *    început în tăcere să pună comenzile anulate la bani. Când setarea e goală,
 *    `status_cancelled()` întoarce șirul gol, iar `o.status <> ''` e adevărat
 *    pentru toată lumea: nimic nu se numără anulat. E purtarea bună — mai bine
 *    o statistică ce nu știe de anulări decât una care aruncă rânduri la
 *    întâmplare.
 *
 * BANII. `bani` numără VÂNZAREA: tot ce nu e anulat, nu numai ce s-a predat. Ce
 * s-a comandat și încă n-a plecat se întoarce aparte, ca `baniDeIncasat`, și
 * pagina îl arată deoparte.
 *
 * `baniDeIncasat` ÎȘI PĂSTREAZĂ NUMELE, DAR NU MAI ÎNSEAMNĂ CE SPUNE. Cheia nu
 * se redenumește — o pagină veche, rămasă deschisă într-o filă, o citește —,
 * dar de la runda 2.7.0 (4–5 octombrie 2026) ea înseamnă „comandat și încă
 * NELIVRAT”, nu „neplătit”. Cât nimeni nu ținea socoteala plăților, vorba
 * trecea. De când casa are condica plăților (pagina Încasări), „de încasat” e
 * altă sumă, cu altă socoteală: o comandă predată și neplătită nu e aici, iar
 * una plătită înainte de livrare e. Nimic din ce numără ruta asta din poziții
 * nu spune dacă banii au și ajuns în casă.
 *
 * BANII CU ADEVĂRAT ÎNCASAȚI, și cei de încasat, stau în cheia `plati` — vezi
 * `stats_plati()` —, numai pentru cine are dreptul `incasari`.
 */

declare(strict_types=1);

/**
 * Cel mai lung interval socotit dintr-o dată.
 *
 * Trei ani. Nu e o părere despre cât poate ține patiseria socotelile, ci o
 * margine pusă cererii: fără ea, `?from=0001-01-01` ar cere bazei o grupare pe
 * zile cu 730.000 de rânduri de întors, iar pagina ar aștepta o socoteală de
 * care n-are nimeni nevoie. Cine vrea mai mult cere pe bucăți.
 */
const GP_STATS_ZILE_MAX = 1100;

/** Câți clienți intră în listă. Restul se numără și se spune, nu se ascund. */
const GP_STATS_CLIENTI = 50;

/** Numele sub care se adună pozițiile al căror produs nu mai e în catalog. */
const GP_STATS_FARA_CAT = 'fără categorie';

/**
 * GET /stats
 *
 * ?from=AAAA-LL-ZZ&to=AAAA-LL-ZZ  intervalul; implicit ultimele 30 de zile
 * ?baza=livrare|comanda           după ce zi se socotește; implicit livrarea
 * ?variant=copt|congelat          felul mărfii, de pe poziție
 * ?categorie=…                    categoria produsului, din catalog
 * ?canal=site|panou               pe unde a intrat comanda
 * ?onorare=Livrare|Ridicare|…     cum a plecat marfa
 * ?zona=…                         zona de livrare
 * ?client=…                       căutare după numele de pe comandă sau de pe cont
 */
function route_stats_get(): void
{
    require_drept('statistici');

    /* ------------------------------------------------------------ ce se cere */

    $azi = today_local();
    // Implicit: ultimele 30 de zile, cu ziua de azi la capăt. „30 de zile"
    // înseamnă 30 de zile, adică azi plus 29 în urmă — nu 31.
    $to   = valid_day(trim((string) ($_GET['to'] ?? '')) ?: $azi);
    $from = valid_day(trim((string) ($_GET['from'] ?? '')) ?: zi_mutata($to, -29));

    if ($from > $to) {
        fail('Începutul intervalului e după sfârșitul lui.', 422, 'bad_range');
    }
    $zile = zile_intre($from, $to);
    if ($zile > GP_STATS_ZILE_MAX) {
        fail(
            'Intervalul cerut are ' . $zile . ' de zile, iar socoteala se face pe cel mult '
            . GP_STATS_ZILE_MAX . '. Cere-l pe bucăți.',
            422,
            'range_too_long'
        );
    }

    // Ziua socotită. „livrare" e `orders.day` — ziua în care marfa ajunge la
    // client — iar „comanda" e ziua în care s-a scris comanda. Aceeași lună dă
    // două răspunsuri deosebite: o comandă scrisă pe 30 pentru 2 ale lunii
    // viitoare intră în septembrie după una și în octombrie după cealaltă.
    $baza = ($_GET['baza'] ?? 'livrare') === 'comanda' ? 'comanda' : 'livrare';
    // `orders.day` e deja o zi locală, scrisă de om. `created_at` e o clipă
    // UTC, fiindcă legătura cu baza pornește pe „+00:00" (api/lib/db.php:45) —
    // deci trebuie adusă în ora patiseriei ÎNAINTE de a se tăia în zile. Tăiată
    // cu un `DATE()` simplu, o comandă scrisă la 01:15 într-o noapte de vară
    // intra în ziua de ieri: 22:15 UTC. Adică socoteala „după ziua în care s-a
    // comandat" mințea tocmai în orele în care se ia comanda pentru livrarea de
    // dimineață. Fereastra cerută expresiei începe de la perioada DE COMPARAT,
    // nu de la cea cerută, fiindcă și aceea se numără cu aceeași coloană.
    $colZi = $baza === 'comanda'
        ? zi_locala_sql('o.created_at', zi_mutata($from, -$zile), $to)
        : 'o.day';

    // Felul mărfii se cerne pe listă, nu se trece mai departe cum a venit: e
    // singurul filtru cu valori știute dinainte, iar unul necunoscut („mixt",
    // de pildă, care e o stare de comandă, nu de poziție) ar goli pagina fără
    // să spună de ce. Necunoscut înseamnă deci „toate".
    $variant   = in_array($_GET['variant'] ?? '', ['copt', 'congelat'], true)
        ? (string) $_GET['variant'] : '';
    $categorie = trim((string) ($_GET['categorie'] ?? ''));
    $canal     = trim((string) ($_GET['canal'] ?? ''));
    $onorare   = trim((string) ($_GET['onorare'] ?? ''));
    $zona      = trim((string) ($_GET['zona'] ?? ''));
    $client    = trim((string) ($_GET['client'] ?? ''));

    /* ---------------------------------------------------------- de unde citim */

    // O singură legătură, folosită de toate socotelile. `products` și `users`
    // sunt LEFT: produsul poate fi șters din catalog (`product_id` devine NULL,
    // vezi cheia străină din sql/001_schema.sql), iar comanda poate fi a
    // cuiva fără cont. În amândouă cazurile rândul rămâne la socoteală —
    // vânzarea s-a făcut oricum. (Dacă s-a și plătit e altă întrebare, cu altă
    // socoteală: cheia `plati`, mai jos.)
    $DIN = 'FROM order_items it
              JOIN orders o ON o.id = it.order_id
              LEFT JOIN products p ON p.id = it.product_id
              LEFT JOIN users u ON u.id = o.customer_id';
    $BANI = 'it.qty * it.unit_price';

    $anulata = status_cancelled();
    $predata = status_done();

    // Trei site, una în alta:
    //   · `$undeData`  — numai intervalul. Din ea ies listele de filtre, ca
    //                    alegerea unui filtru să nu golească lista celorlalte.
    //   · `$unde`      — intervalul plus filtrele cerute. Din ea iese rezumatul,
    //                    care numără și anulatele.
    //   · `$undeBun`   — la fel, fără anulate. Din ea ies toate desfacerile.
    $undeData = [$colZi . ' BETWEEN ? AND ?'];
    $parData  = [$from, $to];

    $unde = $undeData;
    $par  = $parData;

    if ($variant !== '') {
        $unde[] = 'it.variant = ?';
        $par[]  = $variant;
    }
    if ($categorie !== '') {
        if ($categorie === GP_STATS_FARA_CAT) {
            $unde[] = "(p.category IS NULL OR p.category = '')";
        } else {
            $unde[] = 'p.category = ?';
            $par[]  = $categorie;
        }
    }
    foreach ([['channel', $canal], ['fulfillment', $onorare], ['zone', $zona]] as [$col, $val]) {
        if ($val !== '') {
            $unde[] = 'o.' . $col . ' = ?';
            $par[]  = $val;
        }
    }
    if ($client !== '') {
        // Se caută și în numele scris pe comandă, și în contul legat: o comandă
        // luată la telefon are numele scris de mână, una de pe site îl are de pe
        // cont, iar cine caută „Amandina" le vrea pe amândouă.
        $unde[] = '(o.customer_name LIKE ? OR u.name LIKE ? OR u.username LIKE ?)';
        $like   = '%' . $client . '%';
        array_push($par, $like, $like, $like);
    }

    $undeBun = array_merge($unde, ['o.status <> ?']);
    $parBun  = array_merge($par, [$anulata]);

    $CU      = ' WHERE ' . implode(' AND ', $unde);
    $CU_BUN  = ' WHERE ' . implode(' AND ', $undeBun);
    $CU_DATA = ' WHERE ' . implode(' AND ', $undeData);

    /* -------------------------------------------------------------- rezumatul */

    // Bucățile de SELECT și parametrii lor se scriu ÎMPREUNĂ, printr-un
    // ajutor, nu în două liste paralele. Parametrii sunt poziționali: o bucată
    // adăugată la mijloc fără parametrul ei ar muta toate întrebările de după
    // ea cu un loc, iar socoteala ar ieși greșită FĂRĂ nicio eroare — cea mai
    // urâtă formă de defect.
    $sel = [];
    $selPar = [];
    $pune = static function (string $nume, string $sql, array $ale = []) use (&$sel, &$selPar): void {
        $sel[] = $sql . ' AS ' . $nume;
        foreach ($ale as $p) {
            $selPar[] = $p;
        }
    };

    $pune('comenzi', 'COUNT(DISTINCT CASE WHEN o.status <> ? THEN o.id END)', [$anulata]);
    $pune('anulate', 'COUNT(DISTINCT CASE WHEN o.status = ? THEN o.id END)', [$anulata]);
    $pune('predate', 'COUNT(DISTINCT CASE WHEN o.status = ? THEN o.id END)', [$predata]);
    $pune(
        'nepredate',
        'COUNT(DISTINCT CASE WHEN o.status <> ? AND o.status <> ? THEN o.id END)',
        [$anulata, $predata]
    );
    $pune('bani', "SUM(CASE WHEN o.status <> ? THEN $BANI ELSE 0 END)", [$anulata]);
    $pune('bucati', 'SUM(CASE WHEN o.status <> ? THEN it.qty ELSE 0 END)', [$anulata]);
    $pune('baniAnulati', "SUM(CASE WHEN o.status = ? THEN $BANI ELSE 0 END)", [$anulata]);
    $pune(
        'baniDeIncasat',
        "SUM(CASE WHEN o.status <> ? AND o.status <> ? THEN $BANI ELSE 0 END)",
        [$anulata, $predata]
    );

    $r = one('SELECT ' . implode(', ', $sel) . " $DIN $CU", array_merge($selPar, $par)) ?: [];

    $bani    = (float) ($r['bani'] ?? 0);
    $comenzi = (int) ($r['comenzi'] ?? 0);
    $bucati  = (float) ($r['bucati'] ?? 0);

    $rezumat = [
        'bani'          => round($bani, 2),
        'baniPeZi'      => round($zile ? $bani / $zile : 0, 2),
        'comenzi'       => $comenzi,
        'predate'       => (int) ($r['predate'] ?? 0),
        'nepredate'     => (int) ($r['nepredate'] ?? 0),
        'anulate'       => (int) ($r['anulate'] ?? 0),
        'baniAnulati'   => round((float) ($r['baniAnulati'] ?? 0), 2),
        'baniDeIncasat' => round((float) ($r['baniDeIncasat'] ?? 0), 2),
        'bucati'        => round($bucati, 2),
        'cos'           => round($comenzi ? $bani / $comenzi : 0, 2),
        'bucatiPeComanda' => round($comenzi ? $bucati / $comenzi : 0, 2),
        'comenziPeZi'   => round($zile ? $comenzi / $zile : 0, 2),
    ];

    /* ------------------------------------------- perioada dinainte, de comparat */

    // Aceeași lungime, lipită de începutul celei cerute. Nu „luna trecută":
    // dacă omul a cerut 12 zile, se compară cu cele 12 dinainte, nu cu o lună.
    $faFrom = zi_mutata($from, -$zile);
    $faTo   = zi_mutata($from, -1);
    $parFa  = $par;
    $parFa[0] = $faFrom;
    $parFa[1] = $faTo;
    $f = one(
        "SELECT COUNT(DISTINCT CASE WHEN o.status <> ? THEN o.id END) AS comenzi,
                SUM(CASE WHEN o.status <> ? THEN $BANI ELSE 0 END) AS bani
           $DIN $CU",
        array_merge([$anulata, $anulata], $parFa)
    ) ?: [];

    $faBani    = (float) ($f['bani'] ?? 0);
    $faComenzi = (int) ($f['comenzi'] ?? 0);
    $fata = [
        'deLa'      => $faFrom,
        'panaLa'    => $faTo,
        'bani'      => round($faBani, 2),
        'comenzi'   => $faComenzi,
        // `null`, nu `0`, când n-a fost nimic înainte: „+100%" față de zero e o
        // minciună cu cifre, iar pagina trebuie să poată tăcea în locul ei.
        'pasBani'    => $faBani > 0 ? round(($bani - $faBani) / $faBani * 100, 1) : null,
        'pasComenzi' => $faComenzi > 0 ? round(($comenzi - $faComenzi) / $faComenzi * 100, 1) : null,
    ];

    /* ----------------------------------------------------------- desfacerile */

    $felRanduri = all(
        "SELECT it.variant AS cheie, SUM(it.qty) AS bucati, SUM($BANI) AS bani,
                COUNT(DISTINCT o.id) AS comenzi
           $DIN $CU_BUN GROUP BY it.variant ORDER BY bucati DESC",
        $parBun
    );

    $categorii = all(
        "SELECT COALESCE(NULLIF(p.category, ''), ?) AS nume,
                SUM(it.qty) AS bucati, SUM($BANI) AS bani,
                SUM(CASE WHEN it.variant = 'copt' THEN it.qty ELSE 0 END) AS copt,
                SUM(CASE WHEN it.variant = 'congelat' THEN it.qty ELSE 0 END) AS congelat,
                COUNT(DISTINCT it.name) AS produse
           $DIN $CU_BUN GROUP BY nume ORDER BY bucati DESC",
        array_merge([GP_STATS_FARA_CAT], $parBun)
    );

    // Gruparea e pe NUMELE DE PE POZIȚIE, nu pe `product_id`. Numele e cel de
    // la data comenzii, pus deoparte tocmai ca o redenumire de mai târziu să nu
    // rescrie istoria (vezi sql/001_schema.sql). Deci un produs redenumit apare
    // cu amândouă numele, fiecare cu bucățile lui — și așa și trebuie: sub
    // numele vechi s-a vândut altceva decât sub cel nou.
    $produse = all(
        "SELECT it.name AS nume,
                COALESCE(NULLIF(MAX(p.category), ''), ?) AS categorie,
                SUM(it.qty) AS bucati, SUM($BANI) AS bani,
                SUM(CASE WHEN it.variant = 'copt' THEN it.qty ELSE 0 END) AS copt,
                SUM(CASE WHEN it.variant = 'congelat' THEN it.qty ELSE 0 END) AS congelat,
                COUNT(DISTINCT o.id) AS comenzi
           $DIN $CU_BUN GROUP BY it.name ORDER BY bucati DESC",
        array_merge([GP_STATS_FARA_CAT], $parBun)
    );

    // Zilele fără nicio vânzare se completează AICI, cu zerouri, nu în pagină.
    //
    // Baza întoarce numai zilele care au rânduri. Un grafic desenat din ele
    // arată o săptămână liniștită la fel ca una plină, fiindcă zilele goale
    // dispar cu totul și cele dinainte și de după ajung lipite — adică minte
    // despre formă, care e tocmai lucrul pentru care se face graficul. Umplut
    // în pagină, ar trebui umplut în fiecare pagină care cere ruta, iar cea
    // uitată ar minți singură.
    $gasite = [];
    foreach (all(
        "SELECT $colZi AS zi, SUM($BANI) AS bani, SUM(it.qty) AS bucati,
                COUNT(DISTINCT o.id) AS comenzi
           $DIN $CU_BUN GROUP BY zi ORDER BY zi",
        $parBun
    ) as $x) {
        $gasite[(string) $x['zi']] = $x;
    }
    // Zilele se NUMĂRĂ, nu se compară. Până la runda 2.7.0 bucla mergea „cât
    // ziua e cel mult capătul”, cu zilele puse față în față ca text — iar după
    // 9999-12-31 vine „10000-01-01”, care ca text e MAI MICĂ decât capătul
    // („1” stă înaintea lui „9”). Cu `to=9999-12-31` bucla nu se mai oprea:
    // cererea își mânca memoria și murea cu 500, la oricine avea Statisticile.
    // `$zile` e numărat mai sus, cu amândouă capetele înăuntru, și e deja
    // ținut sub `GP_STATS_ZILE_MAX`, deci bucla are capăt orice zile ar primi.
    $zi_cu_zi = [];
    $z = $from;
    for ($i = 0; $i < $zile; $i++) {
        $zi_cu_zi[] = $gasite[$z] ?? ['zi' => $z, 'bani' => 0, 'bucati' => 0, 'comenzi' => 0];
        $z = zi_mutata($z, 1);
    }

    // Clientul cu cont se strânge după cont; cel fără, după numele scris pe
    // comandă. Cheia poartă felul ei în față („c:" ori „n:") ca un cont cu
    // numărul 7 să nu se amestece cu un client fără cont numit „7".
    $cheieClient = "CASE WHEN o.customer_id IS NULL
                         THEN CONCAT('n:', COALESCE(o.customer_name, ''))
                         ELSE CONCAT('c:', o.customer_id) END";
    $clientiTot = (int) scalar(
        "SELECT COUNT(*) FROM (SELECT 1 $DIN $CU_BUN GROUP BY $cheieClient) x",
        $parBun
    );
    $limita = GP_STATS_CLIENTI;
    $clienti = all(
        "SELECT $cheieClient AS cheie,
                COALESCE(NULLIF(MAX(u.name), ''), MAX(o.customer_name), '(fără nume)') AS nume,
                MAX(o.customer_id) AS cont,
                SUM($BANI) AS bani, SUM(it.qty) AS bucati, COUNT(DISTINCT o.id) AS comenzi
           $DIN $CU_BUN GROUP BY cheie ORDER BY bani DESC LIMIT $limita",
        $parBun
    );

    // „Cum a plecat marfa", „pe unde a venit comanda", „în ce zonă" — trei
    // întrebări de aceeași formă, deci o singură socoteală. Numerele ies din
    // PDO ca șiruri; aduse la număr aici, o dată, ca pagina să nu adune text.
    $peColoana = static function (string $col) use ($DIN, $CU_BUN, $parBun, $BANI): array {
        return array_map(static fn($x) => [
            'nume'    => (string) $x['nume'],
            'comenzi' => (int) $x['comenzi'],
            'bani'    => round((float) $x['bani'], 2),
            'bucati'  => round((float) $x['bucati'], 2),
        ], all(
            "SELECT COALESCE(NULLIF(o.$col, ''), '(necompletat)') AS nume,
                    COUNT(DISTINCT o.id) AS comenzi, SUM($BANI) AS bani, SUM(it.qty) AS bucati
               $DIN $CU_BUN GROUP BY nume ORDER BY bani DESC",
            $parBun
        ));
    };

    /* ------------------------------------- ce se poate alege din filtre, deloc filtrat */

    // Listele de filtre ies din interval, NU din ce a mai rămas după filtrele
    // deja puse. Altfel alegerea „congelate" ar goli lista de categorii care nu
    // se vând congelate, iar omul n-ar mai avea cum să se întoarcă din ea.
    $valori = static function (string $expr) use ($DIN, $CU_DATA, $parData): array {
        $r = all("SELECT DISTINCT $expr AS v $DIN $CU_DATA ORDER BY v", $parData);
        return array_values(array_filter(array_map(static fn($x) => (string) $x['v'], $r),
            static fn($x) => $x !== ''));
    };

    $raspuns = [
        'interval' => [
            'deLa'    => $from,
            'panaLa'  => $to,
            'zile'    => $zile,
            'baza'    => $baza,
        ],
        'rezumat'   => $rezumat,
        'fata'      => $fata,
        'fel'       => array_map(static fn($x) => [
            'cheie'  => (string) $x['cheie'],
            'bucati' => round((float) $x['bucati'], 2),
            'bani'   => round((float) $x['bani'], 2),
            'comenzi' => (int) $x['comenzi'],
        ], $felRanduri),
        'categorii' => array_map(static fn($x) => [
            'nume'     => (string) $x['nume'],
            'bucati'   => round((float) $x['bucati'], 2),
            'bani'     => round((float) $x['bani'], 2),
            'copt'     => round((float) $x['copt'], 2),
            'congelat' => round((float) $x['congelat'], 2),
            'produse'  => (int) $x['produse'],
        ], $categorii),
        'produse'   => array_map(static fn($x) => [
            'nume'      => (string) $x['nume'],
            'categorie' => (string) $x['categorie'],
            'bucati'    => round((float) $x['bucati'], 2),
            'bani'      => round((float) $x['bani'], 2),
            'copt'      => round((float) $x['copt'], 2),
            'congelat'  => round((float) $x['congelat'], 2),
            'comenzi'   => (int) $x['comenzi'],
        ], $produse),
        'zile'      => array_map(static fn($x) => [
            'zi'      => (string) $x['zi'],
            'bani'    => round((float) $x['bani'], 2),
            'bucati'  => round((float) $x['bucati'], 2),
            'comenzi' => (int) $x['comenzi'],
        ], $zi_cu_zi),
        'clienti'   => array_map(static fn($x) => [
            'nume'    => (string) $x['nume'],
            'cont'    => $x['cont'] === null ? null : (int) $x['cont'],
            'bani'    => round((float) $x['bani'], 2),
            'bucati'  => round((float) $x['bucati'], 2),
            'comenzi' => (int) $x['comenzi'],
        ], $clienti),
        // Câți sunt cu totul și câți s-au trimis. Pagina scrie deosebirea cu
        // glas tare: o listă tăiată în tăcere se citește ca o listă întreagă.
        'clientiTotal' => $clientiTot,
        'onorare'   => $peColoana('fulfillment'),
        'canale'    => $peColoana('channel'),
        'zone'      => $peColoana('zone'),
        'filtre'    => [
            'categorii' => $valori("COALESCE(NULLIF(p.category, ''), '" . GP_STATS_FARA_CAT . "')"),
            'canale'    => $valori('o.channel'),
            'onorare'   => $valori('o.fulfillment'),
            'zone'      => $valori('o.zone'),
        ],
        // Numele etapelor cu rol special, ca pagina să poată scrie „predate" și
        // „anulate" cu vorbele patronului, nu cu ale ei.
        'etape'     => ['predata' => $predata, 'anulata' => $anulata],
    ];

    /* ----------------------------------------------------- costul și profitul */

    // CEREREA PATRONULUI, 4 octombrie 2026 (runda 2.7.0): „în statistici să văd
    // cât profit am pe produse și cât mă costă". Cheia `costuri`, și NUMAI
    // pentru cine are dreptul cu același nume — din pornire, doar fondatorul.
    // Statisticile pot fi deschise și altcuiva din „Acces"; profitul, nu
    // neapărat. Celui fără drept cheia nu-i vine goală, ci DELOC: răspunsul lui
    // e, literă cu literă, cel de până la runda asta, și nici nu se citește
    // nimic în plus din bază pentru el.
    if (drept('costuri')) {
        $raspuns['costuri'] = costuri_supported()
            ? stats_costuri($produse, $colZi, $DIN, $BANI, $CU_BUN, $parBun)
            // Baza n-a primit migrarea 034: pagina află că n-are ce arăta, în
            // loc să deseneze o coloană de liniuțe fără nicio vorbă.
            : ['activ' => false];
    }

    /* -------------------------------------------- banii încasați, lângă vânzare */

    // CEREREA PATRONULUI, 4 octombrie 2026 (runda 2.7.0): „la statistică să
    // adăugăm: câți bani avem de încasat și de la cine, câți bani am încasat
    // deja”. Tot ce e mai sus numără VÂNZAREA; cheia `plati` numără banii, din
    // condica plăților. E a dreptului `incasari` — al paginii Încasări, din
    // pornire numai al fondatorului —, nu al Statisticilor: cine are
    // Statisticile fără Încasări primește răspunsul de până la runda asta, fără
    // cheie (nu cu una goală) și fără nicio citire în plus din bază.
    //
    // Fără migrarea 032 pagina află că n-are ce arăta (`activ` fals), iar
    // `stats_plati()` nu se cheamă: nici fișierul plăților nu se încarcă, nici
    // vreo coloană de plată nu se pomenește în vreo interogare.
    //
    // PE UN SINGUR RÂND, nu pe trei ca la costuri, dinadins. O dovadă din
    // `check-costuri.mjs` își caută rândul `: ['activ' => false];`, cu cele
    // douăsprezece spații din fața lui, și îl vrea o singură dată în fișier;
    // scris pe trei rânduri, blocul de aici i l-ar fi dat a doua oară.
    //
    // ATÂT ține forma asta, nu mai mult. Blocul stă ÎNTRE cel al costurilor și
    // `json_out()` (contractul pune `plati` după `costuri`), deci o ancoră care
    // își caută blocul costurilor LIPIT de `json_out()` nu se mai găsește,
    // oricum ar fi scris rândul de mai jos. Așa a picat o dovadă a lui
    // `check-costuri` la venirea blocului; se îndreaptă în proba aceea, oprind
    // ancora la acolada care închide blocul costurilor — nu de aici.
    if (drept('incasari')) {
        $raspuns['plati'] = plati_supported() ? stats_plati($azi, $from, $to) : ['activ' => false];
    }

    json_out($raspuns);
}

/** Câți datornici pleacă spre pagină. Restul se numără (`datorniciTotal`) și se spune, nu se ascund. */
const GP_STATS_DATORNICI = 5;

/**
 * Marginile între care casa ține plăți. Sunt cele pe care le cere și registrul
 * (`incasari_zi_ceruta()`, api/routes/plati.php), scrise a doua oară fiindcă
 * acolo sunt puse de-a dreptul în refuz, nu într-o constantă. Ca să nu se
 * despartă, `check-stat-plati.mjs` citește cele două zile din refuzul
 * registrului și le cere egale cu cele de aici.
 *
 * LA CE FOLOSESC. `GET /stats` primește orice zi care trece de `valid_day()`,
 * de la anul 0000 la 9999; registrul plăților refuză tot ce e în afara
 * marginilor (422 `bad_date`). Intervalul se taie la ele înainte să ajungă la
 * funcțiile plăților.
 *
 * SUNT O PLASĂ, NU O REPARAȚIE — măsurat pe 9 octombrie 2026, cu marginile și
 * fără ele, pe serverul de probă. Perioada plăților se compară pe CLIPE
 * (`plati_clipa_utc()`), cu „începutul zilei de după” drept capăt: pentru
 * 9999-12-31 ziua de după are anul din cinci cifre, PHP n-o mai citește ca
 * dată și `incasari_cifre()` ar arunca; pentru anul 0000 clipa de început iese
 * „-0001-12-31 22:15:36” (măsurat fără bază, pe 5 octombrie). Cât a fost
 * scrisă plasa, NU blocul plăților era cel care cădea acolo: cu
 * `to=9999-12-31` ruta răspundea 500 ORICUI, cu ori fără dreptul `incasari`,
 * de dinaintea rundei — așezarea zilelor una după alta (`$zi_cu_zi`, mai
 * sus) trecea de ultima zi a calendarului și nu se mai oprea. Așezarea aceea
 * a fost reparată tot pe 9 octombrie (zilele se numără, nu se compară ca
 * text), iar DE ATUNCI MARGINILE SUNT CELE CARE ȚIN PAGINA ÎN PICIOARE la
 * capătul calendarului pentru cine are dreptul `incasari`. Dovedit pe viu:
 * cu marginea de sus mutată la 9999-12-31, `test-stat-plati` se înroșește
 * la „pe ultima lună a calendarului…” cu 500 `server_error`, și numai acolo
 * — adică pagina ar fi căzut numai celui cu Încasări, la o adresă care
 * altuia îi merge. Pentru orice alt an din afara marginilor răspunsul e
 * același cu ele și fără ele: funcțiile plăților primesc zilele și nu găsesc
 * nimic.
 *
 * CE ADUC CU ELE, ȘI SE VEDE. Tăiat, un interval cu totul în afara marginilor
 * rămâne fără nicio zi (`$areZile` fals, în `stats_plati()`). Întrebat FĂRĂ
 * perioadă, `incasari_cifre()` răspunde cu ultimele 30 de zile — iar omul ar
 * citi banii lunii ăsteia sub „Încasat în martie 1990”. De aceea `incasat`
 * pleacă atunci pe zero, cheie cu cheie, iar cele două socoteli scrise aici
 * nu se mai fac. (`test-stat-plati.mjs` o cere pe viu.)
 *
 * Nu se pierde nimic: în afara marginilor nu există nicio plată și nicio
 * comandă.
 */
const GP_STATS_PLATI_DE_LA   = '2000-01-01';
const GP_STATS_PLATI_PANA_LA = '2100-12-31';

/**
 * Banii încasați și cei de încasat — cheia `plati` a răspunsului.
 *
 * ACEEAȘI CIFRĂ, ACEEAȘI SOCOTEALĂ. „De încasat”, „Amânate”, „Încasat în
 * perioadă” și lista datornicilor NU se socotesc aici: se cer de la funcțiile
 * paginii Încasări (`incasari_cifre()`, `incasari_clienti()`). Scrise a doua
 * oară, ar fi ajuns la prima îndreptare să spună două sume pentru „de încasat”
 * — una în Statistici, alta în Încasări —, adică tocmai defectul pentru care
 * s-a făcut runda: aceeași vorbă cu două înțelesuri pe două pagini. Și cele
 * două socoteli care sunt numai ale Statisticilor își iau CONDIȚIA tot de
 * acolo (`incasari_conditie()`): ce înseamnă „neîncasată” ori „anulată” se
 * hotărăște într-un singur loc.
 *
 * FILTRELE PAGINII NU AJUNG AICI, și de aceea funcția nici nu le primește.
 * Felul mărfii, categoria, canalul, onorarea, zona, căutarea după client și
 * „după ce zi se socotește” cern POZIȚII de comandă; plata e a comenzii
 * întregi. O comandă mixtă plătită nu e „pe jumătate încasată la congelate”.
 * De la pagină se ia numai intervalul, iar ziua de azi e cea a rutei — trei
 * valori deja cernute de `route_stats_get()`. De aici nu se mai citește nimic
 * din adresă.
 *
 * CE ȚINE DE INTERVAL ȘI CE NU:
 *   · `deIncasat`, `amanate`, `datornici`, `datorniciTotal` — starea de ACUM,
 *     pe toată baza. Datoria unui client nu se micșorează fiindcă omul se uită
 *     la luna trecută.
 *   · `incasat` — plățile bifate în interval, după ziua LOCALĂ a plății.
 *   · `dinPerioada` — comenzile cu ziua în interval, încă neplătite: „din ce
 *     s-a livrat în perioada asta, cât nu s-a plătit încă”.
 *   · `zilePlata` — pentru plățile din interval, câte zile au trecut, în
 *     medie, de la ziua comenzii până la ziua plății.
 *
 * FĂRĂ SCURGERI. Din ce știe pagina Încasări pleacă numai ce e în contract:
 * sume, numărători și, despre datornici, numele, soldul și vechimea. Telefonul,
 * pragul, starea, cererile în așteptare, notele, cine a bifat și lista
 * comenzilor rămân ale paginii Încasări. De aceea rândul unui datornic se
 * scrie mai jos cheie cu cheie, nu se trece mai departe cum vine: un câmp
 * adăugat mâine cartonașului din Încasări n-are voie să ajungă singur aici.
 *
 * `$azi`, `$from` și `$to` sunt zile AAAA-LL-ZZ deja trecute prin
 * `valid_day()`, cu `$from` cel mult egal cu `$to`.
 */
function stats_plati(string $azi, string $from, string $to): array
{
    // Funcțiile paginii Încasări stau în fișierul ei de rute, iar routerul
    // încarcă UN singur fișier de rute pe cerere (api/index.php) — pe al
    // nostru. Se aduce AICI, înăuntru, nu în capul fișierului: cine n-are
    // dreptul `incasari`, ori lucrează pe o bază fără migrarea 032, nu ajunge
    // niciodată la rândul ăsta, deci nu plătește nici citirea fișierului (la
    // fel face api/routes/telegram.php cu requests.php). Fișierul acela nu
    // face nimic la încărcare — numai constante și funcții. Dacă funcțiile se
    // mută într-o zi în api/lib/plati.php, rândul rămâne nevinovat.
    require_once __DIR__ . '/plati.php';

    // Intervalul, tăiat la marginile casei (v. constantele). Când nu mai
    // rămâne nicio zi din el — o perioadă cu totul în afara lor —, în perioadă
    // nu s-a încasat nimic și nu se întreabă baza degeaba.
    $deLa    = max($from, GP_STATS_PLATI_DE_LA);
    $pana    = min($to, GP_STATS_PLATI_PANA_LA);
    $areZile = $deLa <= $pana;

    // TOATE CITIRILE DIN ACEEAȘI „POZĂ”, ca în registru (`route_plati_list()`).
    // Baza merge pe REPEATABLE READ, deci o tranzacție ține cifrele și lista
    // datornicilor pe aceeași clipă. Fără ea, o plată bifată de pe alt telefon
    // între două interogări ar fi lăsat pe ecran „9 comenzi · 5 clienți”
    // deasupra unei liste cu patru nume. Nu se scrie nimic și nu se ia niciun
    // lacăt. (Întrebarea despre migrare s-a pus înainte, în rută: e o citire
    // din `information_schema` și n-are ce căuta într-o poză.)
    $pdo = db();
    $pdo->beginTransaction();

    $cifre = incasari_cifre($azi, $areZile ? $deLa : null, $areZile ? $pana : null);
    $toti  = incasari_clienti($azi);

    $neplatit = [];
    $platit   = [];
    if ($areZile) {
        // DIN PERIOADĂ, ÎNCĂ NEPLĂTIT. Condiția e chiar cea a vederii „De
        // încasat” din registru, cu parametrii ei, în ordinea ei; aici se
        // adaugă numai ziua comenzii. Rescrisă de mână, ar fi uitat într-o zi
        // anulatele — iar o comandă anulată ar fi apărut ca bani de luat.
        // Suma e totalul comenzii, ca la „De încasat”: cele două se citesc una
        // lângă alta, iar a doua e o parte din prima.
        [$cond, $deLegat] = incasari_conditie('deincasat');
        $neplatit = one(
            'SELECT COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS suma FROM orders o'
                . ' WHERE ' . $cond . ' AND o.day >= ? AND o.day <= ?',
            array_merge($deLegat, [$deLa, $pana])
        ) ?? [];

        // ZILELE PÂNĂ LA PLATĂ. Care plăți cad în perioadă o spune aceeași
        // funcție din care iese „Încasat în perioadă” (`incasari_incasat_intre()`,
        // pe clipe), deci cele două numără aceleași plăți — mai puțin rândurile
        // FĂRĂ clipă, marcate „Plătită” de migrarea 032: despre ele nu se știe
        // când s-au plătit, iar o zi închipuită ar fi mutat media. De aceea
        // `comenzi` de aici poate fi mai mic decât cel de la `incasat`.
        //
        // ZIUA PLĂȚII, PE FIECARE RÂND, E CEA DIN ROMÂNIA. `plata_la` e o clipă
        // UTC; un `DATE()` pus de-a dreptul pe ea ar fi dat ziua de ieri pentru
        // orice plată bifată între miezul nopții și trei dimineața, adică o zi
        // mai puțin tocmai la banii predați de șoferul de noapte. Expresia o
        // scrie `zi_locala_sql()`, pe aceeași fereastră ca marginile de mai
        // sus și din același fus, deci o plată pe care marginile o pun în ziua
        // Z are și aici ziua Z. Nu se mai mută nimic a doua oară: `orders.day`
        // e deja o zi locală, scrisă de om.
        //
        // `GREATEST(0, …)`: o comandă plătită ÎNAINTE de ziua ei (avansul,
        // plata la comandă) a așteptat zero zile, nu minus trei. Lăsate cu
        // minus, două asemenea comenzi ar fi „șters” din medie un rău-platnic.
        //
        // SUMA ȘI NUMĂRUL, NU `AVG()`. Pe întregi, `AVG()` ajunge în PHP ca text
        // cu patru zecimale, deja rotunjit de bază, pe care PHP l-ar mai
        // rotunji o dată. Măsurat pe baza locală (5 octombrie 2026): 1001 plăți
        // cu 4054 de zile dau „4.0500”, adică 4,1 după a doua rotunjire, pe
        // când media adevărată, 4,04995…, e 4,0. Împărțirea se face deci aici,
        // o singură dată, pe numere întregi.
        [$cond, $deLegat]       = incasari_conditie('platite');
        [$intre, $intreDeLegat] = incasari_incasat_intre($deLa, $pana);
        $ziPlatii               = zi_locala_sql('o.plata_la', $deLa, $pana);
        $platit = one(
            'SELECT COUNT(*) AS n,'
                . ' COALESCE(SUM(GREATEST(0, DATEDIFF(' . $ziPlatii . ', o.day))), 0) AS zile'
                . ' FROM orders o'
                . ' WHERE ' . $cond . ' AND o.plata_la IS NOT NULL AND ' . $intre,
            array_merge($deLegat, $intreDeLegat)
        ) ?? [];
    }

    $pdo->commit();

    // Câți datorează e chiar lungimea listei — așa îl pune și registrul.
    $cifre['deIncasat']['clienti'] = count($toti);

    // Primii, în ordinea în care îi dă registrul (cel mai mare sold întâi; la
    // egalitate, datoria mai veche). Valorile se iau cum sunt, deja aduse la
    // număr acolo: aceeași cifră, literă cu literă, pe amândouă paginile. Un
    // client fără cont are `customerId` nul și cheia făcută din numele lui.
    $primii = [];
    foreach (array_slice($toti, 0, GP_STATS_DATORNICI) as $d) {
        $primii[] = [
            'cheie'       => $d['cheie'],
            'customerId'  => $d['customerId'],
            'nume'        => $d['nume'],
            'comenzi'     => $d['comenzi'],
            'sold'        => $d['sold'],
            'ceaMaiVeche' => $d['ceaMaiVeche'],
            'zile'        => $d['zile'],
        ];
    }

    // „Încasat în perioadă” al registrului, fără capetele perioadei: pagina le
    // are deja, în `interval`.
    $incasat = $cifre['incasatPerioada'];
    $n       = (int) ($platit['n'] ?? 0);

    return [
        'activ'       => true,
        'azi'         => $azi,
        'deIncasat'   => $cifre['deIncasat'],
        'amanate'     => $cifre['amanate'],
        'incasat'     => [
            'suma'    => $areZile ? $incasat['suma'] : 0.0,
            'direct'  => $areZile ? $incasat['direct'] : 0.0,
            'banca'   => $areZile ? $incasat['banca'] : 0.0,
            'comenzi' => $areZile ? $incasat['comenzi'] : 0,
        ],
        'dinPerioada' => [
            'suma'    => round((float) ($neplatit['suma'] ?? 0), 2),
            'comenzi' => (int) ($neplatit['n'] ?? 0),
        ],
        // `null`, nu o medie de zero, când în perioadă nu s-a bifat nicio
        // plată: „0 zile” ar spune că toată lumea plătește pe loc, iar pagina
        // trebuie să poată scrie o liniuță în locul ei.
        'zilePlata'   => $n > 0
            ? ['medie' => round(((int) ($platit['zile'] ?? 0)) / $n, 1), 'comenzi' => $n]
            : null,
        'datornici'      => $primii,
        'datorniciTotal' => count($toti),
    ];
}

/**
 * Costul și profitul rândurilor din `produse` — cheia `costuri` a răspunsului.
 *
 * ACELEAȘI RÂNDURI CA `produse`, nu „cam aceleași”. Primește chiar bucățile din
 * care ruta și-a făcut interogările — legătura `$DIN`, sita `$CU_BUN` cu
 * parametrii ei, coloana zilei, expresia banilor — și nu-și scrie niciuna a
 * lui. Un filtru adăugat mâine în rută ajunge aici singur; scrisă a doua oară,
 * sita ar fi rămas în urmă la primul filtru nou, iar profitul s-ar fi socotit
 * pe alte comenzi decât vânzările de lângă el, fără nicio eroare.
 *
 * O SINGURĂ TRECERE PRIN POZIȚII, oricât de lung ar fi intervalul: strânse pe
 * (produs, nume, zi). Pe zi, fiindcă o poziție se socotește cu prețurile din
 * ZIUA ei — `$colZi`, aceeași zi după care numără pagina (livrare ori comandă)
 * —, deci făina scumpită pe 15 intră numai în ce s-a vândut de pe 15 încolo.
 * Costul pe bucată nu se cere bazei pentru fiecare rând: îl socotește
 * `costuri_statistici()` în PHP și îl ține minte pe (produs, felia dintre două
 * schimbări de preț). Trei ani de vânzări înseamnă zeci de mii de rânduri, dar
 * numai câteva zeci de rețete socotite.
 *
 * NUMELE. `produse` strânge pe `it.name`, iar baza socotește „Plăcintă” și
 * „placinta” același nume (colaționarea coloanei nu deosebește nici literele
 * mari, nici diacriticele) și arată pe rând una dintre scrieri, la alegerea ei.
 * Trecerea de aici strânge pe scrierea literă cu literă, deci scoate la iveală
 * toate scrierile; fiecare trebuie dusă la rândul ei din `produse`, cu EXACT
 * numele de acolo. Care scrieri țin de același rând o știe numai baza — regula
 * colaționării nu se poate reface în PHP fără s-o ghicești.
 *
 * DE CE NU SE MAI ÎNTREABĂ BAZA PRINTR-O A DOUA TRECERE. La început citirea
 * avea două: una strângea pe scriere, alta ca `produse`, legate cu `=`-ul
 * bazei. A doua trecea încă o dată prin toate pozițiile numai ca să spună ce
 * `produse` spusese deja, și era jumătate din tot ce costa cheia `costuri` (pe
 * o bază cu 39 600 de poziții și 1100 de zile: 525 ms cu ea, 271 fără). Or,
 * răspunsul e în mână:
 *   · numele unui rând din `produse` e una dintre scrierile de aici, deci se
 *     găsește literă cu literă și e rândul LUI — fără nicio întrebare;
 *   · rămân scrierile care nu sunt numele niciunui rând (a doua scriere a unui
 *     nume, adică aproape niciodată vreuna). Numai pentru ele se întreabă baza,
 *     tot cu `=`-ul ei, pe câte UN rând de fiecare scriere, luat după cheia
 *     primară — câteva rânduri, nu toate pozițiile.
 *
 * Parametrii sunt poziționali și se pun în ordinea în care apar semnele de
 * întrebare în text. `$colZi` și `$DIN` n-au niciun semn de întrebare în ele.
 */
function stats_costuri(array $produse, string $colZi, string $DIN, string $BANI, string $CU_BUN, array $parBun): array
{
    // `un_rand`: o poziție oarecare cu scrierea aceea — martorul cu care se
    // poate întreba baza, mai jos, „al cui e numele ăsta?”.
    $parti = all(
        "SELECT it.product_id AS pid, MIN(it.name) AS varianta, $colZi AS zi,
                SUM(it.qty) AS bucati, SUM($BANI) AS bani, MIN(it.id) AS un_rand
           $DIN $CU_BUN
          GROUP BY it.product_id, CAST(it.name AS BINARY), zi",
        $parBun
    );

    // [scrierea numelui => o poziție care o poartă]. Cheile unui tablou PHP se
    // deosebesc literă cu literă, exact ca gruparea de mai sus. (Un nume făcut
    // numai din cifre ajunge cheie-număr; de aceea, la citire, `(string)`.)
    $martor = [];
    foreach ($parti as $x) {
        $s  = (string) $x['varianta'];
        $id = (int) $x['un_rand'];
        if (!isset($martor[$s]) || $id < $martor[$s]) {
            $martor[$s] = $id;
        }
    }

    $randuri = [];   // [clasa => cum se cheamă rândul pe pagină]
    $alCui   = [];   // [scrierea numelui => clasa ei]

    // Rândurile din `produse`: fiecare își ia scrierea cu care apare acolo.
    foreach ($produse as $x) {
        $n = (string) $x['nume'];
        if (isset($martor[$n])) {
            $alCui[$n]   = $n;
            $randuri[$n] = ['nume' => $n, 'categorie' => (string) $x['categorie']];
        }
    }

    // Scrierile rămase fără rând. De obicei niciuna, și atunci nu se mai
    // întreabă nimic.
    $straine = [];   // [poziția-martor => scrierea]
    foreach ($martor as $s => $id) {
        if (!isset($alCui[(string) $s])) {
            $straine[$id] = (string) $s;
        }
    }
    if ($straine) {
        $scriereaDe = [];   // [poziția-martor => scrierea], pentru toate
        foreach ($martor as $s => $id) {
            $scriereaDe[$id] = (string) $s;
        }
        // Fiecare scriere străină față în față cu toate celelalte, la judecata
        // bazei. Amândouă părțile sunt luate după cheia primară.
        $rude = [];   // [poziția străină => [pozițiile cu același nume, după bază]]
        foreach (all(
            'SELECT a.id AS strain, b.id AS ruda
               FROM order_items a
               JOIN order_items b ON b.name = a.name
              WHERE a.id IN (' . implode(',', array_fill(0, count($straine), '?')) . ')
                AND b.id IN (' . implode(',', array_fill(0, count($scriereaDe), '?')) . ')',
            array_merge(array_keys($straine), array_keys($scriereaDe))
        ) as $x) {
            $rude[(int) $x['strain']][] = (int) $x['ruda'];
        }
        foreach ($straine as $id => $s) {
            $ale = $rude[$id] ?? [$id];
            sort($ale);
            // Dacă una dintre rude e numele unui rând din `produse`, acolo
            // merge. Altfel e un rând pe care `produse` nu l-a văzut (o
            // comandă scrisă chiar între cele două citiri): toate scrierile
            // lui se strâng sub cea cu poziția cea mai mică, aceeași pentru
            // fiecare dintre ele.
            $clasa = $scriereaDe[$ale[0]];
            foreach ($ale as $r) {
                if (isset($randuri[$scriereaDe[$r]])) {
                    $clasa = $scriereaDe[$r];
                    break;
                }
            }
            $alCui[$s] = $clasa;
        }
    }

    // Fiecare bucată își primește rândul. Din mers se adună și rândurile pe
    // care `produse` nu le are (v. mai sus), cu produsele de sub ele.
    $faraRand = [];   // [clasa => [pid => true, …]]
    foreach ($parti as $k => $x) {
        $clasa = $alCui[(string) $x['varianta']];
        $parti[$k]['clasa'] = $clasa;
        if (!isset($randuri[$clasa])) {
            $faraRand[$clasa] ??= [];
            if ($x['pid'] !== null) {
                $faraRand[$clasa][(int) $x['pid']] = true;
            }
        }
    }
    // Rândul fără pereche în `produse` își ia numele din scrierea lui, iar
    // categoria se socotește cu regula lui `produse`: cea mai mare dintre
    // categoriile produselor de sub nume, după ordinea BAZEI — de aceea o
    // sortează ea, iar aici se ia prima. Citirea asta se face numai în clipa
    // aceea; altfel `$faraRand` e gol.
    if ($faraRand) {
        $pids = [];
        foreach ($faraRand as $ale) {
            $pids += $ale;
        }
        $categoriaDe = [];   // [pid => categoria], în ordinea bazei, cea mai mare întâi
        if ($pids) {
            foreach (all(
                'SELECT id, category FROM products
                  WHERE id IN (' . implode(',', array_fill(0, count($pids), '?')) . ')
                  ORDER BY category DESC, id',
                array_keys($pids)
            ) as $p) {
                $categoriaDe[(int) $p['id']] = (string) ($p['category'] ?? '');
            }
        }
        foreach ($faraRand as $clasa => $ale) {
            $categorie = '';
            foreach ($categoriaDe as $pid => $c) {
                if (isset($ale[$pid])) {
                    $categorie = $c;
                    break;
                }
            }
            $randuri[(string) $clasa] = [
                'nume'      => (string) $clasa,
                'categorie' => $categorie === '' ? GP_STATS_FARA_CAT : $categorie,
            ];
        }
    }

    return costuri_statistici($parti, $randuri, costuri_context());
}
