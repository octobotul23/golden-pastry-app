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
 * BANII. `bani` numără tot ce nu e anulat, nu numai ce s-a predat. Ce s-a
 * comandat și încă n-a plecat e tot bani ai casei, doar că nu în mână — de
 * aceea se întoarce aparte, ca `baniDeIncasat`, și pagina îi arată deoparte.
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
    require_stats();

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
    // cuiva fără cont. În amândouă cazurile rândul rămâne la socoteală — banii
    // au intrat oricum.
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
    $zi_cu_zi = [];
    for ($z = $from; $z <= $to; $z = zi_mutata($z, 1)) {
        $zi_cu_zi[] = $gasite[$z] ?? ['zi' => $z, 'bani' => 0, 'bucati' => 0, 'comenzi' => 0];
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

    json_out([
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
    ]);
}
