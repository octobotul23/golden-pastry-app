<?php
/**
 * Plățile (runda 2.7.0, migrarea 032): registrul „Încasări”, situația de plată
 * a unui client, schimbarea stării de plată a unei comenzi și setările
 * raportului de bani.
 *
 * CE E AICI ȘI CE NU. Poarta pragului — cea care oprește a treia comandă
 * neplătită — NU e aici: stă în `api/lib/plati.php`, fiindcă o cheamă rutele de
 * comenzi și de cereri, iar routerul încarcă un singur fișier de rute pe cerere
 * (api/index.php). Aici sunt numai rutele care ARATĂ și SCHIMBĂ plata.
 *
 * TREI PUBLICURI, TREI PORȚI (hotărârile patronului din 4–5 octombrie 2026):
 *
 *   · clientul își vede situația LUI (`GET /plati/mine`) — atât cât să știe că
 *     se apropie de prag; niciodată „amânată”, „prin bancă”, termene ori note;
 *   · cine lucrează cu comenzile vede situația clientului ales (`GET
 *     /plati/client/#`), ca avertizarea să apară înainte de „Salvează”, nu ca
 *     refuz după — dar numai pentru clienții din ograda lui;
 *   · cine are „Încasări” vede registrul și, cu `plati.stare`, îl schimbă.
 *
 * FĂRĂ 032, rutele care au nevoie de coloanele ei răspund `503
 * plati_unavailable`, cu leacul în mesaj. Una singură nu: `GET /plati/mine`, pe
 * care magazinul o cere la fiecare deschidere — ea răspunde „nimic de spus”,
 * fiindcă magazinul n-are voie să cadă pentru o migrare neimportată.
 *
 * BANII SE ADUNĂ ÎN SQL, pe `DECIMAL`, și abia rezultatul se rotunjește pentru
 * JSON. Adunați în PHP, pe `float`, zece comenzi de 0,10 lei ar fi dat
 * 0,9999999999999999 — iar o sumă de încasat care nu se potrivește la ban cu
 * raportul de pe Telegram arată ca două sume.
 */

declare(strict_types=1);

/** Câte comenzi încap pe o pagină a registrului. Pagina o citește din `perPage`. */
const INCASARI_PE_PAGINA = 200;

/** Vederile registrului (`?f=`), în ordinea pastilelor din pagină. */
const INCASARI_VEDERI = ['deincasat', 'amanate', 'platite', 'restituit', 'diferente', 'toate'];

/**
 * Cât a intrat cu adevărat pe o comandă încasată: suma ținută minte la bifare,
 * iar pentru rândurile marcate „Plătită” de migrare (care n-au sumă) totalul.
 */
const INCASARI_SUMA = 'COALESCE(o.plata_suma, o.total)';

/**
 * Între ce zile se poate alege perioada registrului (de ce există margini, la
 * `incasari_zi_ceruta()`).
 *
 * CU NUME, fiindcă fiecare apărea de două ori în aceeași funcție: o dată în
 * comparație și o dată în vorba refuzului. Mutată numai una dintre ele, omul ar
 * fi citit „între 2000 și 2100” și ar fi fost refuzat la 2090.
 */
const INCASARI_ZI_MIN = '2000-01-01';
const INCASARI_ZI_MAX = '2100-12-31';

/* ======================================================================== *
 *  Situația unui client
 * ======================================================================== */

/**
 * Situația de plată, în forma pe care o primesc paginile.
 *
 * CHEILE SE SCRIU UNA CÂTE UNA, nu se trimite de-a dreptul ce întoarce
 * `plati_situatie()`. Forma asta ajunge la CLIENT: o cheie adăugată mâine în
 * funcția din lib (o scadență, o notă a casei) ar fi plecat singură spre
 * magazin, fără ca cineva să fi hotărât asta.
 */
function plati_situatie_aratata(array $s): array
{
    return [
        'activ'   => true,
        'stare'   => (string) $s['stare'],
        'prag'    => (int) $s['prag'],
        'n'       => (int) $s['n'],
        'sold'    => round((float) $s['sold'], 2),
        'cereri'  => (int) $s['cereri'],
        // Numai referința, ziua și suma — așa le scrie `plati_situatie()`.
        'comenzi' => array_values($s['comenzi']),
    ];
}

/**
 * GET /plati/mine — clientul intrat în cont întreabă cum stă.
 *
 * `require_auth()`, nu un drept: întrebarea e a clientului, iar clientul n-are
 * rânduri în matricea de drepturi. Răspunsul e NUMAI despre contul din sesiune —
 * ruta nu citește niciun parametru, deci n-are ce să fie schimbat din adresă.
 *
 * Omul casei primește „nimic de spus” (`activ: false`): el nu cumpără, iar
 * magazinul deschis de un administrator n-are de ce să-i arate un prag. Tot așa
 * o bază fără 032 — vezi antetul fișierului.
 */
function route_plati_mine(): void
{
    $u = require_auth();

    if (($u['role'] ?? '') !== 'client' || !plati_supported()) {
        json_out([
            'activ' => false, 'stare' => 'liber', 'prag' => 0, 'n' => 0,
            'sold' => 0.0, 'cereri' => 0, 'comenzi' => [],
        ]);
    }

    json_out(plati_situatie_aratata(plati_situatie((int) $u['id'])));
}

/**
 * GET /plati/client/# — avertizarea timpurie din formularul de comandă.
 *
 * DOUĂ REFUZURI DEOSEBITE, ÎN ORDINEA ASTA:
 *
 *   1. `403 client_out_of_scope` când cel care întreabă n-are de ce să afle cum
 *      stă contul acela. Agentul de vânzări vede numai clienții aduși de el:
 *      datoria clientului altui agent nu e treaba lui.
 *
 *      Întrebarea NU se scrie aici. O pune `plati_poate_afla()`
 *      (api/lib/plati.php), aceeași pe care o pune și refuzul porții: din 9
 *      octombrie 2026, cine primește „nu” la ușa asta primește la refuz numai
 *      răspunsul scurt, și invers. Scrisă în două locuri, s-au despărțit o
 *      dată deja — ușa cerea ograda, refuzul nu cerea nimic.
 *   2. `404` când numărul nu e al unui cont de CLIENT. Întrebarea „e client?”
 *      se pune pe față: `plati_situatie()` numără pentru orice număr de cont,
 *      deci fără ea ruta ar fi răspuns liniștit și pentru contul unui angajat
 *      — „liber, 0 comenzi”.
 *
 * DE CE 403 ÎNAINTEA LUI 404. Până pe 9 octombrie 2026 erau invers, iar
 * agentul afla din ușa asta, număr cu număr, care conturi ale casei sunt de
 * client și câte sunt: 404 pentru contul unui om din casă ori pentru un număr
 * liber, 403 pentru clientul altui agent. Tocmai ce nu-i spune căutarea
 * (`GET /clients` tace despre clienții altora) și ce nu-i spune nici ograda:
 * la `client_in_scope()` (api/lib/orders.php) stă scris că un cont care nu
 * există dă tot refuz, nu „nu există”, fiindcă agentul n-are de ce să afle
 * prin ghicire câți clienți are casa. Acum primește același 403, cu același
 * text, pentru ORICE număr care nu e al unui client de-al lui — și pentru unul
 * scris strâmb. 404 îl mai vede numai cine scrie comenzi, adică cel care vede
 * oricum toate conturile de client (`test-plati-prag`, secțiunea N).
 *
 * `poateOcoli` e judecat pe cel care ÎNTREABĂ, ca în poartă: pagina desenează
 * după el întrebarea „Treci peste prag?”, iar hotărârea adevărată o ia tot
 * `plati_poarta()`, la scriere.
 */
function route_plati_client(string $id): void
{
    $u = require_drept('comenzi');
    plati_cere_baza();

    // `ctype_digit`, nu `(int)`: „12abc” ar fi ajuns clientul 12.
    $clientId = ctype_digit($id) ? (int) $id : 0;
    if (!plati_poate_afla($u, $clientId)) {
        fail('Clientul ăsta nu e printre clienții tăi.', 403, 'client_out_of_scope');
    }
    $cont = $clientId > 0
        ? one('SELECT id, role, name, customer_name FROM users WHERE id = ?', [$clientId])
        : null;
    if ($cont === null || (string) $cont['role'] !== 'client') {
        fail('Clientul nu există.', 404, 'not_found');
    }

    $nume = client_display_name($cont['customer_name'] ?? null, $cont['name'] ?? null);

    json_out(plati_situatie_aratata(plati_situatie($clientId)) + [
        'client'     => ['id' => $clientId, 'nume' => $nume !== '' ? $nume : 'Clientul'],
        'poateOcoli' => drept('plati.ocoleste', $u),
    ]);
}

/* ======================================================================== *
 *  Registrul „Încasări”
 * ======================================================================== */

/**
 * Un parametru din adresă, ca text.
 *
 * `?f[]=x` ajunge în `$_GET` ca TABLOU. Un `(string)` pus pe el dă „Array” și un
 * avertisment; iar citit ca „gol”, `?client[]=31` ar fi întors registrul
 * întreg sub titlul unui singur client. Se refuză, cu numele parametrului.
 */
function incasari_param(string $nume): string
{
    $v = $_GET[$nume] ?? '';
    if (!is_scalar($v)) {
        fail('Parametrul „' . $nume . '” se dă o singură dată, ca text.', 422, 'bad_param');
    }
    return trim((string) $v);
}

/**
 * O margine de perioadă din adresă: `null` când lipsește.
 *
 * MARGINILE DE AN NU SUNT DE FORMĂ. Perioada plăților se compară pe CLIPE
 * (`plati_clipa_utc()`), cu „începutul zilei de după” drept capăt. Pentru
 * 9999-12-31, ziua de după are anul din cinci cifre — MySQL n-o mai primește ca
 * dată, iar cererea ar fi ieșit „Eroare internă” dintr-un calendar derulat prea
 * departe. Tot ce ține casa e între marginile de mai jos.
 */
function incasari_zi_ceruta(string $nume): ?string
{
    $v = incasari_param($nume);
    if ($v === '') {
        return null;
    }
    $zi = valid_day($v);
    if ($zi < INCASARI_ZI_MIN || $zi > INCASARI_ZI_MAX) {
        fail('Perioada se alege între ' . INCASARI_ZI_MIN . ' și ' . INCASARI_ZI_MAX . '.', 422, 'bad_date');
    }
    return $zi;
}

/**
 * CE INTRĂ într-o vedere a registrului: bucata de `WHERE` și parametrii ei.
 *
 * O SINGURĂ FUNCȚIE PENTRU LISTĂ ȘI PENTRU CIFRELE DE DEASUPRA EI. Pagina scrie
 * pe fiecare pastilă numărul din `cifre` și dedesubt `total`-ul listei; două
 * condiții scrise de mână s-ar fi depărtat la prima îndreptare, iar omul ar fi
 * văzut „Amânate · 3” deasupra a două rânduri.
 *
 * „ANULATĂ” SE CITEȘTE DIN ETAPĂ (`status_cancelled()`), niciodată din plată.
 * Când etapa de anulare nu e pusă în Setări, funcția aceea întoarce șirul gol:
 * atunci NICIO comandă nu e anulată — condiția se lasă pe dinafară cu totul (ca
 * în `plati_situatie()`), „De restituit” e gol, iar „Toate” e chiar toate.
 *
 * @return array{0: string, 1: array}
 */
function incasari_conditie(string $vedere): array
{
    $semne      = static fn (array $l): string => implode(', ', array_fill(0, count($l), '?'));
    $neincasata = 'o.plata IN (' . $semne(GP_PLATI_NEINCASATE) . ')';
    $incasata   = 'o.plata IN (' . $semne(GP_PLATI_INCASATE) . ')';

    $anulata = status_cancelled();
    $vie     = $anulata !== '' ? ' AND o.status <> ?' : '';
    $vieP    = $anulata !== '' ? [$anulata] : [];

    switch ($vedere) {
        case 'deincasat':
            return [$neincasata . $vie, array_merge(GP_PLATI_NEINCASATE, $vieP)];

        case 'amanate':
            return ['o.plata = ?' . $vie, array_merge(['amanata'], $vieP)];

        case 'platite':
            return [$incasata . $vie, array_merge(GP_PLATI_INCASATE, $vieP)];

        case 'restituit':
            // Anulată DUPĂ ce s-a plătit: banii sunt la casă și trebuie dați
            // înapoi. Se închide mutând plata pe „Neplătită”.
            if ($anulata === '') {
                return ['1 = 0', []];
            }
            return ['o.status = ? AND ' . $incasata, array_merge([$anulata], GP_PLATI_INCASATE)];

        case 'diferente':
            // Comanda s-a schimbat după ce a fost plătită. Amândouă sunt
            // DECIMAL(10,2), deci scăderea e dreaptă la ban; pragul de jumătate
            // de ban e cel din contract și spune pe față că se compară bani, nu
            // numere cu virgulă.
            return [
                $incasata . $vie . ' AND o.plata_suma IS NOT NULL AND ABS(o.plata_suma - o.total) >= 0.005',
                array_merge(GP_PLATI_INCASATE, $vieP),
            ];

        case 'toate':
            // Tot ce are legătură cu banii: neanulatele, plus anulatele pe care
            // s-au încasat bani. O anulată neplătită nu e a nimănui aici.
            if ($anulata === '') {
                return ['1 = 1', []];
            }
            return ['(o.status <> ? OR ' . $incasata . ')', array_merge([$anulata], GP_PLATI_INCASATE)];
    }

    throw new LogicException('incasari_conditie(): vedere necunoscută „' . $vedere . '”.');
}

/**
 * Perioada plăților, când omul n-a ales-o întreagă: [de la, până la].
 *
 * Fără nicio margine: ULTIMELE 30 DE ZILE, cu azi cu tot (azi și 29 de zile
 * înapoi). Cu una singură, cealaltă se așază firesc: „de la 1 septembrie”
 * înseamnă până azi; „până la 31 august” înseamnă cele 30 de zile dinaintea ei.
 *
 * Aceeași socoteală pentru vederea „Plătite” și pentru placa „Încasat în
 * perioadă” — altfel placa ar fi spus o sumă și lista de sub ea alta.
 *
 * @return array{0: string, 1: string}
 */
function incasari_perioada(?string $from, ?string $to, string $azi): array
{
    $pana = $to ?? ($from !== null && $from > $azi ? $from : $azi);
    $deLa = $from ?? zi_mutata($pana, -29);
    return [$deLa, $pana];
}

/**
 * „Încasată între zilele astea”, ca bucată de `WHERE`.
 *
 * ZIUA UNEI PLĂȚI E ZIUA DIN ROMÂNIA A CLIPEI ÎN CARE S-A BIFAT. `plata_la` e
 * UTC, deci marginile se dau drept clipe (`plati_clipa_utc()`): începutul zilei
 * dintâi și începutul zilei de DUPĂ ultima. O plată bifată la 00:30 ora
 * României stă în bază cu data de ieri (21:30 ori 22:30 UTC) și cade totuși pe
 * azi; în noaptea în care se mută ceasul, ziua de 23 ori de 25 de ore iese
 * dreaptă, fiindcă marginile le socotește fusul.
 *
 * RÂNDURILE FĂRĂ CLIPĂ sunt comenzile pe care migrarea 032 le-a marcat
 * „Plătită”: predate demult, plătite nu se știe când. Ele se judecă după ziua
 * comenzii — altfel n-ar mai fi apărut în nicio perioadă, niciodată.
 *
 * @return array{0: string, 1: array}
 */
function incasari_incasat_intre(string $deLa, string $pana): array
{
    return [
        '((o.plata_la >= ? AND o.plata_la < ?) OR (o.plata_la IS NULL AND o.day >= ? AND o.day <= ?))',
        [plati_clipa_utc($deLa), plati_clipa_utc(zi_mutata($pana, 1)), $deLa, $pana],
    ];
}

/**
 * Coloanele unui rând de registru și parametrii lor.
 *
 * „E anulată?” se întreabă ÎN SQL, cu aceeași comparație ca în filtre
 * (`o.status = ?`, pe colaționarea coloanei). Întrebată în PHP, cu `===`, ar fi
 * putut răspunde altfel decât filtrul pentru o etapă scrisă cu altă literă
 * mare — iar rândul ar fi stat în „De restituit” fără semnul de anulată.
 *
 * Diferența de bani se scade tot aici, pe DECIMAL.
 *
 * @return array{0: string, 1: array}
 */
function incasari_coloane(): array
{
    $anulata = status_cancelled();
    return [
        ($anulata !== '' ? '(o.status = ?)' : '0') . ' AS e_anulata,'
            . ' o.id, o.ref, o.seq, o.day, o.status, o.customer_id, o.customer_name, o.phone, o.total,'
            . ' o.plata, o.plata_scadenta, o.plata_la, o.plata_suma, o.plata_nota,'
            . ' (o.total - o.plata_suma) AS plata_dif, pu.name AS plata_de_nume',
        $anulata !== '' ? [$anulata] : [],
    ];
}

/** De unde se citește un rând de registru: comanda, cu numele celui care a bifat. */
const INCASARI_DIN = 'orders o LEFT JOIN users pu ON pu.id = o.plata_de';

/**
 * Un rând de registru, în forma din contract. Aceeași pentru listă și pentru
 * răspunsul lui `PATCH /orders/#/plata` — pagina pune rândul întors de
 * schimbare PESTE cel din listă, deci cele două n-au voie să difere cu o cheie.
 */
function incasari_rand(array $r, string $azi): array
{
    $plata    = (string) $r['plata'];
    $incasata = in_array($plata, GP_PLATI_INCASATE, true);
    $scadenta = $r['plata_scadenta'] !== null ? (string) $r['plata_scadenta'] : null;
    $nota     = trim((string) ($r['plata_nota'] ?? ''));
    $cine     = trim((string) ($r['plata_de_nume'] ?? ''));

    return [
        'id'           => (int) $r['id'],
        // Aceeași rezervă ca în `attach_items()` și în `plati_situatie()`:
        // comenzile vechi n-au referința scrisă.
        'ref'          => $r['ref'] ?: ('#' . (1000 + (int) $r['seq'])),
        'seq'          => (int) $r['seq'],
        'zi'           => (string) $r['day'],
        'status'       => (string) $r['status'],
        'anulata'      => (int) $r['e_anulata'] === 1,
        'customerId'   => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
        // Numele scris PE COMANDĂ, nu cel de pe cont: registrul arată comanda
        // așa cum a fost luată, iar contul și-a putut schimba numele de atunci.
        'customerName' => (string) $r['customer_name'],
        'phone'        => (string) ($r['phone'] ?? ''),
        'total'        => round((float) $r['total'], 2),
        'plata'        => $plata,
        // Termenul are înțeles numai pe o amânare. Unul rămas pe altă stare ar
        // fi o dată fără stăpân pe ecran.
        'scadenta'     => $plata === 'amanata' ? $scadenta : null,
        'depasita'     => $plata === 'amanata' && $scadenta !== null && $scadenta < $azi,
        // Clipa rămâne UTC, cum e în bază și cum o trimite și `attach_items()`:
        // pagina o trece pe ceasul patiseriei (`gp.utc` + `gp.laPatiserie`).
        'plataLa'      => $r['plata_la'] ?? null,
        // NUMELE, nu numărul contului: cine are „Încasări” nu are neapărat și
        // „Conturi”, deci un id nu i-ar spune nimic.
        'plataDe'      => $cine !== '' ? $cine : null,
        'plataSuma'    => $r['plata_suma'] !== null ? round((float) $r['plata_suma'], 2) : null,
        'nota'         => $nota !== '' ? $nota : null,
        'diferenta'    => $incasata && $r['plata_dif'] !== null && abs((float) $r['plata_dif']) >= 0.005
            ? round((float) $r['plata_dif'], 2)
            : null,
    ];
}

/**
 * Cifrele de deasupra registrului.
 *
 * PE TOATĂ BAZA, nu pe filtrul ales și nu pe pagina de rânduri: omul care se
 * uită la „Amânate”, pe pagina a doua, căutând un nume, trebuie să vadă tot
 * suma întreagă de încasat. De aceea funcția nu primește nici vederea, nici
 * clientul, nici căutarea — n-are cu ce să greșească.
 *
 * Singura care ține de perioadă e „Încasat în perioadă”.
 *
 * „ÎNCASAT AZI” NUMĂRĂ NUMAI PLĂȚI CU CLIPĂ. Rândurile marcate de migrare n-au
 * `plata_la`; după ziua comenzii, o comandă de azi marcată așa ar fi apărut ca
 * bani intrați azi, pe care nu i-a numărat nimeni.
 */
function incasari_cifre(string $azi, ?string $from, ?string $to): array
{
    $bani = static fn ($v): float => round((float) $v, 2);

    [$c, $p]   = incasari_conditie('deincasat');
    $deIncasat = one('SELECT COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS suma FROM orders o WHERE ' . $c, $p) ?? [];

    [$c, $p] = incasari_conditie('amanate');
    $amanate = one(
        'SELECT COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS suma,'
            . ' COALESCE(SUM(o.plata_scadenta < ?), 0) AS depasite'
            . ' FROM orders o WHERE ' . $c,
        array_merge([$azi], $p)
    ) ?? [];

    // „Direct” = `platita`, „bancă” = `banca`. Cele două se adună în aceeași
    // trecere prin tabel ca totalul, ca să nu poată ieși niciodată
    // direct + bancă ≠ sumă.
    $incasat = static function (string $inPlus, array $inPlusP): array {
        [$c, $p] = incasari_conditie('platite');
        return one(
            'SELECT COUNT(*) AS n, COALESCE(SUM(' . INCASARI_SUMA . '), 0) AS suma,'
                . ' COALESCE(SUM(CASE WHEN o.plata = ? THEN ' . INCASARI_SUMA . ' ELSE 0 END), 0) AS direct,'
                . ' COALESCE(SUM(CASE WHEN o.plata = ? THEN ' . INCASARI_SUMA . ' ELSE 0 END), 0) AS banca'
                . ' FROM orders o WHERE ' . $c . ' AND ' . $inPlus,
            array_merge(['platita', 'banca'], $p, $inPlusP)
        ) ?? [];
    };

    $aziR = $incasat(
        'o.plata_la >= ? AND o.plata_la < ?',
        [plati_clipa_utc($azi), plati_clipa_utc(zi_mutata($azi, 1))]
    );

    [$deLa, $pana] = incasari_perioada($from, $to, $azi);
    [$pc, $pp]     = incasari_incasat_intre($deLa, $pana);
    $perioada      = $incasat($pc, $pp);

    // De dat înapoi e ce s-a ÎNCASAT, nu cât face comanda azi.
    [$c, $p]     = incasari_conditie('restituit');
    $deRestituit = one(
        'SELECT COUNT(*) AS n, COALESCE(SUM(' . INCASARI_SUMA . '), 0) AS suma FROM orders o WHERE ' . $c,
        $p
    ) ?? [];

    [$c, $p]   = incasari_conditie('diferente');
    $diferente = (int) scalar('SELECT COUNT(*) FROM orders o WHERE ' . $c, $p);

    return [
        // `clienti` îl pune ruta, din lista de clienți: e chiar lungimea ei.
        'deIncasat'       => ['suma' => $bani($deIncasat['suma'] ?? 0), 'comenzi' => (int) ($deIncasat['n'] ?? 0), 'clienti' => 0],
        'amanate'         => [
            'suma'     => $bani($amanate['suma'] ?? 0),
            'comenzi'  => (int) ($amanate['n'] ?? 0),
            'depasite' => (int) ($amanate['depasite'] ?? 0),
        ],
        'incasatAzi'      => [
            'suma'    => $bani($aziR['suma'] ?? 0),
            'direct'  => $bani($aziR['direct'] ?? 0),
            'banca'   => $bani($aziR['banca'] ?? 0),
            'comenzi' => (int) ($aziR['n'] ?? 0),
        ],
        'incasatPerioada' => [
            'suma'    => $bani($perioada['suma'] ?? 0),
            'direct'  => $bani($perioada['direct'] ?? 0),
            'banca'   => $bani($perioada['banca'] ?? 0),
            'comenzi' => (int) ($perioada['n'] ?? 0),
            // Perioada pe care s-a socotit cu adevărat, ca pagina s-o poată
            // scrie sub sumă și când omul n-a ales niciuna.
            'from'    => $deLa,
            'to'      => $pana,
        ],
        'deRestituit'     => ['suma' => $bani($deRestituit['suma'] ?? 0), 'comenzi' => (int) ($deRestituit['n'] ?? 0)],
        'diferente'       => ['comenzi' => $diferente],
    ];
}

/**
 * Toți cei cu ceva de încasat, cel mai mare sold întâi.
 *
 * TOȚI, nu cei de pe pagina de rânduri și nu cei prinși de filtru: cartonașele
 * sunt „cine îmi datorează”, iar un client tăiat de o căutare ori de o perioadă
 * ar fi părut plătit.
 *
 * CONTURILE se strâng după cont, în DOUĂ interogări, oricâți datornici ar fi:
 * una pentru comenzi (cu pragul de pe cont cu tot) și una pentru cererile în
 * așteptare ale tuturor (`plati_cereri_asteptate()`).
 *
 * Până pe 9 octombrie 2026 pragul, starea și cererile se cereau, pentru
 * fiecare în parte, de la `plati_situatie()`: patru citiri de client, 1,14 ms
 * măsurate pe baza locală. La 27 de cartonașe nu se vedea (registrul întreg
 * răspundea în 37 ms); numărul creștea însă odată cu lista datornicilor, iar
 * pagina asta e tocmai cea deschisă când lista e lungă.
 *
 * Pricina pentru care se întreba poarta rămâne în picioare: cartonașul nu poate
 * spune „Blocat 2/2” despre un client pe care poarta l-ar lăsa să treacă. De
 * aceea NICIO REGULĂ nu e scrisă a doua oară aici. Ce prag iese din coloană
 * (`plati_prag_din()`), ce stare iese din prag și număr (`plati_stare()`), ce
 * cerere se numără (`GP_PLATI_CERERE_NUMARATA`) și ce comandă e neîncasată
 * (`incasari_conditie('deincasat')`, aceeași condiție ca în `plati_situatie()`)
 * sunt ale lib-ului; aici se face numai adunarea. Că cifrele ies aceleași o
 * întreabă proba vie, cartonaș cu cartonaș, față de `GET /plati/client/#`.
 *
 * COMENZILE FĂRĂ CONT se strâng după NUMELE scris pe comandă, cu regula restului
 * casei (`client_by_name()`, api/lib/orders.php): `TRIM(...) = ...` pe
 * colaționarea coloanei, `utf8mb4_unicode_ci`. Adică „Cafeneaua Lumina”,
 * „cafeneaua lumina” și „Cafeneaua Lumina ” (cu spații la capete) sunt ACELAȘI
 * client; „Cafeneaua  Lumina”, cu două spații la mijloc, e altul — tot așa l-ar
 * fi deosebit și legarea după nume. Dintre scrierile strânse laolaltă se arată
 * mereu aceeași (cea mai mică pe octeți), ca `cheie` să nu se schimbe de la o
 * cerere la alta sub cartonașul deschis.
 *
 * Fără cont nu există prag (n-are cine să-l poarte) și nici cereri legate, deci
 * `prag`, `stare` și `pragPropriu` sunt `null`.
 */
function incasari_clienti(string $azi): array
{
    [$cond, $par] = incasari_conditie('deincasat');

    // Câte zile au trecut de la cea mai veche. `zile_intre()` numără capetele
    // (de azi până azi = 1) și lucrează pe valoarea absolută, deci o comandă
    // de mâine ar fi ieșit „de 1 zi”: se întreabă întâi dacă ziua a trecut.
    $zile = static fn (string $veche): int => $veche < $azi ? zile_intre($veche, $azi) - 1 : 0;

    $clienti = [];

    $conturi = all(
        'SELECT u.id, u.name, u.customer_name, u.phone, u.prag_neplatite,'
            . ' COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS sold, MIN(o.day) AS veche,'
            . " MAX(NULLIF(o.phone, '')) AS tel"
            . ' FROM orders o JOIN users u ON u.id = o.customer_id'
            . ' WHERE ' . $cond
            . ' GROUP BY u.id, u.name, u.customer_name, u.phone, u.prag_neplatite',
        $par
    );
    // Cererile în așteptare ale tuturor, dintr-o singură citire. ÎNAINTEA
    // buclei: înăuntrul ei n-are voie să stea nicio interogare, altfel pagina
    // se întoarce la „câte una de client”.
    $asteptate = plati_cereri_asteptate(array_column($conturi, 'id'));

    foreach ($conturi as $r) {
        $id   = (int) $r['id'];
        $prag = plati_prag_din($r['prag_neplatite']);
        // Aici ajung numai conturi cu cel puțin o comandă neîncasată, deci
        // „numai o cerere, nicio comandă” nu are cartonaș — ca și până acum.
        $nCer = $asteptate[$id] ?? 0;
        $nume = client_display_name($r['customer_name'] ?? null, $r['name'] ?? null);
        // Telefonul contului; lipsă el, cel scris pe o comandă de-a lui.
        $tel  = trim((string) ($r['phone'] ?? ''));
        if ($tel === '') {
            $tel = trim((string) ($r['tel'] ?? ''));
        }
        $veche = (string) $r['veche'];

        $clienti[] = [
            'cheie'       => 'c' . $id,
            'customerId'  => $id,
            'nume'        => $nume,
            'phone'       => $tel,
            'comenzi'     => (int) $r['n'],
            'sold'        => round((float) $r['sold'], 2),
            'ceaMaiVeche' => $veche,
            'zile'        => $zile($veche),
            'prag'        => $prag,
            'pragPropriu' => $r['prag_neplatite'] !== null ? (int) $r['prag_neplatite'] : null,
            // Comenzile ȘI cererile, ca la poartă: clientul cu o comandă și o
            // cerere e „blocat” la pragul 2, nu „avertizat”.
            'stare'       => plati_stare($prag, (int) $r['n'] + $nCer),
            'cereri'      => $nCer,
        ];
    }

    $faraCont = all(
        'SELECT MIN(TRIM(o.customer_name) COLLATE utf8mb4_bin) AS nume,'
            . ' COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS sold, MIN(o.day) AS veche,'
            . " MAX(NULLIF(o.phone, '')) AS tel"
            . ' FROM orders o'
            . ' WHERE o.customer_id IS NULL AND ' . $cond
            . ' GROUP BY TRIM(o.customer_name)',
        $par
    );
    foreach ($faraCont as $r) {
        $nume  = (string) $r['nume'];
        $veche = (string) $r['veche'];

        $clienti[] = [
            'cheie'       => 'n:' . $nume,
            'customerId'  => null,
            'nume'        => $nume,
            'phone'       => trim((string) ($r['tel'] ?? '')),
            'comenzi'     => (int) $r['n'],
            'sold'        => round((float) $r['sold'], 2),
            'ceaMaiVeche' => $veche,
            'zile'        => $zile($veche),
            'prag'        => null,
            'pragPropriu' => null,
            'stare'       => null,
            'cereri'      => 0,
        ];
    }

    // Soldul cel mai mare întâi; la egalitate, datoria mai veche. `cheie` la
    // urmă, ca ordinea să fie aceeași la fiecare cerere — altfel două
    // cartonașe cu același sold și-ar schimba locul sub degetul omului.
    usort($clienti, static function (array $a, array $b): int {
        return [$b['sold'], $a['ceaMaiVeche'], $a['cheie']] <=> [$a['sold'], $b['ceaMaiVeche'], $b['cheie']];
    });

    return $clienti;
}

/**
 * GET /plati?f=&from=&to=&client=&q=&page= — registrul „Încasări”.
 *
 * Poarta e una singură, `incasari`. Tot ce e aici — sume, nume de datornici,
 * note, cine a bifat — e al celui care are pagina; restul casei nu primește
 * nici cheile (vezi `plati_fel()`).
 */
function route_plati_list(): void
{
    $u = require_drept('incasari');
    plati_cere_baza();

    $vedere = incasari_param('f');
    if ($vedere === '') {
        $vedere = 'deincasat';
    }
    if (!in_array($vedere, INCASARI_VEDERI, true)) {
        // Refuz, nu „De încasat” pe tăcute: o adresă scrisă greșit ar fi arătat
        // datoriile sub titlul altei vederi.
        fail(
            'Vederea „' . text_de_aratat($vedere) . '” nu există. Sunt: ' . implode(', ', INCASARI_VEDERI) . '.',
            422,
            'bad_filter'
        );
    }

    $from = incasari_zi_ceruta('from');
    $to   = incasari_zi_ceruta('to');
    // Capetele puse pe dos sunt tot perioada dintre ele. Lăsate așa, lista ar
    // fi ieșit goală — iar „nicio plată” e un răspuns pe care omul îl crede.
    if ($from !== null && $to !== null && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    $client = incasari_param('client');
    if ($client !== '' && (!ctype_digit($client) || strlen($client) > 10 || (int) $client < 1)) {
        fail('Clientul se alege după numărul contului.', 422, 'bad_client');
    }

    $q    = incasari_param('q');
    $page = min(1000000, max(1, (int) incasari_param('page')));
    $azi  = today_local();

    [$cond, $par] = incasari_conditie($vedere);
    $where        = [$cond];

    if ($vedere === 'platite') {
        // Singura vedere judecată după ziua PLĂȚII, nu a comenzii — și singura
        // care are o perioadă și când omul n-a ales una.
        [$deLa, $pana] = incasari_perioada($from, $to, $azi);
        [$pc, $pp]     = incasari_incasat_intre($deLa, $pana);
        $where[]       = $pc;
        $par           = array_merge($par, $pp);
    } else {
        if ($from !== null) {
            $where[] = 'o.day >= ?';
            $par[]   = $from;
        }
        if ($to !== null) {
            $where[] = 'o.day <= ?';
            $par[]   = $to;
        }
    }

    if ($client !== '') {
        $where[] = 'o.customer_id = ?';
        $par[]   = (int) $client;
    }

    if ($q !== '') {
        // Aceeași scăpare ca în `route_orders_list`: fără ea, „50%” ar fi
        // găsit tot registrul. Referința se caută cum o VEDE omul — cu rezerva
        // „#1004” pentru comenzile vechi, care n-o au scrisă în coloană.
        $ac      = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $where[] = "(o.customer_name LIKE ? ESCAPE '!'"
            . " OR COALESCE(NULLIF(o.ref, ''), CONCAT('#', 1000 + o.seq)) LIKE ? ESCAPE '!')";
        $par[]   = $ac;
        $par[]   = $ac;
    }

    // `o.id` la capătul fiecărei ordini: fără el, două comenzi din aceeași zi
    // și-ar putea schimba locul între pagina 1 și pagina 2.
    switch ($vedere) {
        case 'deincasat':
            // Cea mai veche datorie întâi: de ea se întreabă prima dată.
            $ordine = 'o.day ASC, o.id ASC';
            break;
        case 'amanate':
            // Termenul cel mai apropiat întâi, deci cele depășite în capul listei.
            $ordine = 'o.plata_scadenta IS NULL, o.plata_scadenta ASC, o.day ASC, o.id ASC';
            break;
        case 'platite':
            // Cea mai proaspătă plată întâi; rândurile fără clipă se așază după
            // ziua comenzii lor, ca în perioadă.
            $ordine = 'COALESCE(o.plata_la, TIMESTAMP(o.day)) DESC, o.id DESC';
            break;
        default:
            $ordine = 'o.day DESC, o.id DESC';
    }

    $clauza = ' WHERE ' . implode(' AND ', $where);
    $offset = ($page - 1) * INCASARI_PE_PAGINA;

    // TOATE CITIRILE DIN ACEEAȘI „POZĂ”. Baza merge pe REPEATABLE READ, deci o
    // tranzacție ține lista, numărătoarea, cifrele și cartonașele pe aceeași
    // clipă. Fără ea, o plată bifată de pe alt telefon între două interogări
    // ar fi lăsat pe ecran „De încasat · 9” deasupra a opt rânduri, până la
    // următoarea încărcare. Nu se scrie nimic și nu se ia niciun lacăt.
    $pdo = db();
    $pdo->beginTransaction();

    $total = (int) scalar('SELECT COUNT(*) FROM orders o' . $clauza, $par);

    // LIMIT/OFFSET se interpolează ca întregi validați: MySQL nu primește
    // parametri legați în poziția asta.
    [$col, $colP] = incasari_coloane();
    $randuri      = all(
        'SELECT ' . $col . ' FROM ' . INCASARI_DIN . $clauza
            . ' ORDER BY ' . $ordine . ' LIMIT ' . INCASARI_PE_PAGINA . ' OFFSET ' . $offset,
        array_merge($colP, $par)
    );

    $cifre   = incasari_cifre($azi, $from, $to);
    $clienti = incasari_clienti($azi);
    $cifre['deIncasat']['clienti'] = count($clienti);

    $pdo->commit();

    json_out([
        'activ'   => true,
        'azi'     => $azi,
        'f'       => $vedere,
        'from'    => $from,
        'to'      => $to,
        'cifre'   => $cifre,
        'comenzi' => array_map(static fn (array $r): array => incasari_rand($r, $azi), $randuri),
        'clienti' => $clienti,
        'total'   => $total,
        'page'    => $page,
        'pages'   => max(1, (int) ceil($total / INCASARI_PE_PAGINA)),
        'perPage' => INCASARI_PE_PAGINA,
        'prag'    => ['implicit' => plati_prag_general()],
        // Ce butoane are voie pagina să deseneze. Politețea e a ei; regula e
        // la fiecare rută de scriere în parte.
        'poate'   => [
            'stare'    => drept('plati.stare', $u),
            'telegram' => drept('plati.telegram', $u),
            'prag'     => drept('plati.prag', $u),
        ],
    ]);
}

/* ======================================================================== *
 *  Schimbarea stării de plată
 * ======================================================================== */

/** O zi `AAAA-LL-ZZ` care chiar există, ori `null`. Nu oprește cererea: codul refuzului îl alege apelantul. */
function incasari_zi_buna($v): ?string
{
    if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $v) !== 1) {
        return null;
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $v);
    return $dt && $dt->format('Y-m-d') === $v ? $v : null;
}

/**
 * PATCH /orders/#/plata — { plata, from, scadenta?, nota? }
 *
 * RUTĂ APARTE, nu un câmp în plus la `PATCH /orders/#`: acolo trece și contul
 * de tură, cu etapa, iar poarta aceea judecă „ce câmpuri ale comenzii se
 * schimbă”. Plata are alt stăpân (`plati.stare`) și alte urmări — niciuna.
 *
 * CE NU FACE, DINADINS. Nu atinge etapa, totalul, pozițiile, stocul ori
 * comisionul, și nu trimite niciun anunț: `UPDATE`-ul de jos scrie numai
 * coloane `plata_*`, iar din ruta asta nu se cheamă nimic din `notify`,
 * `referral` ori depozit. O plată bifată nu e o veste pentru client și nu mută
 * nicio marfă.
 *
 * `from` E OBLIGATORIU: starea pe care o arăta ecranul în clipa apăsării. Două
 * telefoane deschise pe aceeași comandă ar fi scris altfel unul peste altul —
 * al doilea ar fi mutat pe „Neplătită” o plată pe care nici n-a văzut-o
 * bifată. Cu el, al doilea primește `409 plata_schimbata` și starea de acum.
 * Tot `from` ține și apăsarea dublă: a doua cerere „Neplătită → Plătită”
 * găsește comanda deja plătită și e refuzată, deci clipa plății nu se
 * rescrie și Jurnalul nu capătă un al doilea rând.
 */
function route_order_plata(string $id): void
{
    $u = require_drept('plati.stare');
    // ÎNAINTEA tranzacției, nu înăuntrul ei: întrebarea e o citire din
    // `information_schema`, iar prima citire simplă a unei tranzacții îi face
    // „poza” (REPEATABLE READ; măsurat de autorul porții, v. `plati_poarta()`).
    // Citirea cu lacăt de mai jos vede oricum starea proaspătă, cu poză ori
    // fără; dar o citire SIMPLĂ adăugată mâine în tranzacție, după lacăt, ar
    // citi din poza făcută aici — adică lumea de dinaintea celui pe care l-am
    // așteptat. Ținută afară, întrebarea n-are ce poză să lase.
    plati_cere_baza();

    $b = body();

    $from = $b['from'] ?? null;
    if (!is_string($from) || !in_array($from, GP_PLATI_STARI, true)) {
        fail(
            'Cererea trebuie să spună starea de plată pe care o arăta ecranul (câmpul „from”). '
              . 'Reîncarcă pagina și încearcă din nou.',
            422,
            'from_required'
        );
    }

    $noua = $b['plata'] ?? null;
    if (!is_string($noua) || !in_array($noua, GP_PLATI_STARI, true)) {
        fail('Starea de plată e una dintre: ' . implode(', ', GP_PLATI_NUME) . '.', 422, 'bad_plata');
    }

    // Termenul are înțeles numai pe o amânare; pe celelalte stări se șterge,
    // orice ar fi venit în cerere.
    $scadenta = null;
    if ($noua === 'amanata') {
        $scadenta = incasari_zi_buna($b['scadenta'] ?? null);
        if ($scadenta === null) {
            fail('O amânare are nevoie de ziua până la care se plătește (AAAA-LL-ZZ).', 422, 'bad_scadenta');
        }
    }

    // Nota: lipsă = rămâne cum e; dată = se scrie (goală = se șterge).
    // `text_field()` preface în tăcere un tablou în șir gol — adică ar fi
    // ȘTERS nota pentru o cerere strâmbă. Felul se întreabă deci înainte.
    $areNota = array_key_exists('nota', $b);
    $nota    = '';
    if ($areNota) {
        if ($b['nota'] !== null && !is_string($b['nota'])) {
            fail('Nota plății trebuie să fie un text.', 422, 'bad_nota');
        }
        $nota = text_field($b['nota'] ?? '', 'Nota plății', 255);
    }

    // `ctype_digit`, nu `(int)`: „12abc” ar fi schimbat plata comenzii 12.
    $comandaId = ctype_digit($id) ? (int) $id : 0;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // LACĂTUL E PRIMA CITIRE A TRANZACȚIEI. Citirea cu lacăt nu face
        // „poză”, deci vede ce a scris cel care a ținut rândul înaintea mea.
        $o = one(
            'SELECT id, seq, ref, plata, plata_scadenta, plata_nota FROM orders WHERE id = ? FOR UPDATE',
            [$comandaId]
        );
        if ($o === null) {
            $pdo->rollBack();
            fail('Comanda nu există.', 404, 'not_found');
        }

        $veche = (string) $o['plata'];
        if ($veche !== $from) {
            $pdo->rollBack();
            // Aceeași formă ca `status_changed` din `route_orders_update`: 409,
            // cu starea de acum în `error.current`. Pagina reîncarcă lista și
            // scrie lângă rând ce a găsit.
            json_out([
                'error' => [
                    'code'    => 'plata_schimbata',
                    'message' => 'Plata comenzii ' . (((string) $o['ref']) ?: '#' . (1000 + (int) $o['seq']))
                        . ' a fost schimbată între timp: acum e „' . (GP_PLATI_NUME[$veche] ?? $veche)
                        . '”. N-am schimbat nimic.',
                    'current' => $veche,
                ],
            ], 409);
        }

        $scadentaVeche = $o['plata_scadenta'] !== null ? (string) $o['plata_scadenta'] : null;
        $notaVeche     = trim((string) ($o['plata_nota'] ?? ''));

        if ($noua === 'amanata') {
            // Între azi și capătul ferestrei de comenzi. O SINGURĂ EXCEPȚIE:
            // amânarea care își PĂSTREAZĂ termenul. Pagina retrimite ziua de pe
            // rând când omul schimbă numai nota; pe o amânare depășită ziua
            // aceea e în trecut, iar refuzată, „nu răspunde la telefon” nu s-ar
            // mai fi putut scrie decât dându-i clientului un termen nou — adică
            // scoțându-l dintre depășite tocmai când trebuia să rămână acolo.
            [$prima, $ultima] = order_day_window();
            $aceeasi = $veche === 'amanata' && $scadenta === $scadentaVeche;
            if (!$aceeasi && $scadenta < $prima) {
                $pdo->rollBack();
                fail(
                    'Ziua amânării, ' . $scadenta . ', a trecut. Alege o zi începând cu azi, ' . $prima . '.',
                    422,
                    'bad_scadenta'
                );
            }
            if (!$aceeasi && $scadenta > $ultima) {
                $pdo->rollBack();
                fail(
                    'Ziua amânării, ' . $scadenta . ', e prea departe. Ultima zi care se poate alege este '
                      . $ultima . '.',
                    422,
                    'bad_scadenta'
                );
            }
        }

        $eraIncasata  = in_array($veche, GP_PLATI_INCASATE, true);
        $vaFiIncasata = in_array($noua, GP_PLATI_INCASATE, true);

        $sets  = [];
        $vals  = [];
        $parti = [];

        if ($noua !== $veche) {
            $sets[]  = 'plata = ?';
            $vals[]  = $noua;
            $parti[] = GP_PLATI_NUME[$veche] . ' → ' . GP_PLATI_NUME[$noua]
                . ($noua === 'amanata' ? ' până pe ' . $scadenta : '');
        }

        if ($scadenta !== $scadentaVeche) {
            $sets[] = 'plata_scadenta = ?';
            $vals[] = $scadenta;
            if ($noua === $veche) {
                $parti[] = $noua === 'amanata'
                    ? GP_PLATI_NUME[$noua] . ': termenul mutat de pe ' . ($scadentaVeche ?? '—') . ' pe ' . $scadenta
                    : GP_PLATI_NUME[$noua] . ': termenul rămas de la o amânare veche, șters';
            }
        }

        if ($vaFiIncasata && !$eraIncasata) {
            // Banii intră ACUM: clipa (UTC, ca toate clipele din bază) și suma
            // de pe comandă în clipa asta. Suma se ține minte tocmai ca o
            // comandă schimbată mai târziu să iasă la „Diferențe”.
            $sets[] = 'plata_la = UTC_TIMESTAMP()';
            $sets[] = 'plata_suma = total';
        } elseif (!$vaFiIncasata && $eraIncasata) {
            // Întoarsă în neîncasat: n-a mai intrat nimic, deci nici clipă, nici sumă.
            $sets[] = 'plata_la = NULL';
            $sets[] = 'plata_suma = NULL';
        }
        // `platita` ↔ `banca` e îndreptarea FELULUI, nu o plată nouă: banii au
        // intrat atunci, tot atâția. Clipa și suma rămân neatinse — altfel o
        // corectură făcută azi ar fi mutat pe azi o încasare de săptămâna trecută.

        if ($areNota && $nota !== $notaVeche) {
            $sets[]  = 'plata_nota = ?';
            $vals[]  = $nota !== '' ? $nota : null;
            // În Jurnal se spune CĂ s-a umblat la notă, nu ce scrie în ea: nota
            // poartă adesea sume („a dat 100 avans”), iar rândul ăsta nu duce bani.
            $parti[] = $nota === '' ? 'nota ștearsă' : ($notaVeche === '' ? 'notă pusă' : 'nota schimbată');
        }

        if (!$sets) {
            $pdo->rollBack();
            fail('Nimic de modificat.', 422, 'nothing_to_update');
        }

        // LA ORICE SCHIMBARE: cine a umblat ultimul, și amintirea ștearsă — o
        // amânare nouă (ori mutată) se amintește din nou la termenul ei.
        $sets[] = 'plata_de = ?';
        $vals[] = (int) $u['id'];
        $sets[] = 'plata_amintire_la = NULL';

        $vals[] = $comandaId;
        q('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // DUPĂ commit, ca la `plati_jurnal_ocolire()`: un rând de Jurnal despre o
    // schimbare care până la urmă nu s-a scris ar învinui pe cineva de o faptă
    // nefăcută. FĂRĂ SUME: rândul spune cine și ce stare, atât.
    audit(
        'plata_schimba',
        'order',
        (string) $comandaId,
        'Comandă #' . $o['seq'] . ' — ' . implode(' · ', $parti)
    );

    [$col, $colP] = incasari_coloane();
    $rand         = one(
        'SELECT ' . $col . ' FROM ' . INCASARI_DIN . ' WHERE o.id = ?',
        array_merge($colP, [$comandaId])
    );
    if ($rand === null) {
        // Ștearsă între commit și citire. Schimbarea s-a scris; nu mai e ce arăta.
        fail('Comanda nu există.', 404, 'not_found');
    }

    json_out(['order' => incasari_rand($rand, today_local())]);
}

/* ======================================================================== *
 *  Setările plăților
 * ======================================================================== */

/**
 * Setările plăților, cât are voie să vadă cel care întreabă.
 *
 * DOUĂ DREPTURI, DOUĂ JUMĂTĂȚI. `plati.prag` vede pragul general;
 * `plati.telegram` vede chatul, orele, amintirile și adresa panoului. Cine are
 * numai unul NU primește cheile celuilalt — nici goale: o cheie cu `null` ar fi
 * spus tot că setarea există și că nu e a lui, iar pagina ar fi desenat o
 * casetă pe care salvarea o refuză.
 *
 * `ultimele` sunt cele mai noi cinci trimiteri spre chatul banilor — raport,
 * amintire ori probă —, citite din `notifications` de `plati_raport_ultimele()`
 * (api/lib/plati-raport.php): când a plecat, ce fel era, dacă a ajuns și, la un
 * eșec, de ce. Nici destinatarul, nici textul: chatul e deja mai sus, iar
 * textul e lista datornicilor.
 */
function plati_setari_raspuns(array $u): array
{
    $poate = [
        'telegram' => drept('plati.telegram', $u),
        'prag'     => drept('plati.prag', $u),
    ];

    $out = ['activ' => true];
    if ($poate['telegram']) {
        $out['chatId']       = trim(setting('plati_telegram_chat_id'));
        $out['ore']          = plati_raport_ore();
        // Gol în bază = neatins de nimeni = pornite, ca în migrare.
        $out['amintiri']     = setting('plati_amintiri', '1') === '1';
        $out['panelBaseUrl'] = trim(setting('panel_base_url'));
        $out['ultimele']     = plati_raport_ultimele();
    }
    if ($poate['prag']) {
        $out['pragImplicit'] = plati_prag_general();
    }
    $out['poate'] = $poate;

    return $out;
}

/** GET /plati/setari — oricare dintre cele două drepturi deschide ruta; fiecare își vede jumătatea. */
function route_plati_setari_get(): void
{
    $u = require_drept('plati.telegram', 'plati.prag');
    plati_cere_baza();

    json_out(plati_setari_raspuns($u));
}

/**
 * PUT /plati/setari — orice parte din { chatId, ore, amintiri, panelBaseUrl, pragImplicit }.
 *
 * ÎNTÂI DREPTUL PE FIECARE CHEIE, APOI REGULILE. Întoarse, refuzul de regulă
 * („chatul e același cu al comenzilor”) i-ar fi spus celui care are numai
 * pragul ceva despre o setare pe care n-are voie s-o vadă.
 *
 * TOTUL ORI NIMIC: o cheie fără drept, necunoscută ori greșită oprește
 * salvarea întreagă. Regulile le ține `plati_setari_valideaza()` — aceleași pe
 * care le aplică și `PUT /settings` —, iar de scris scrie
 * `plati_setari_scrie()`, care lasă în Jurnal fapta `plati_setari` numai când
 * s-a schimbat ceva.
 */
function route_plati_setari_save(): void
{
    $u = require_drept('plati.telegram', 'plati.prag');
    plati_cere_baza();

    $b = body();
    if (!$b) {
        fail('Nimic de modificat.', 422, 'nothing_to_update');
    }

    foreach (array_keys($b) as $camp) {
        $camp = (string) $camp;
        if (!array_key_exists($camp, GP_PLATI_SETARI)) {
            // Refuz, nu „sar peste ea”: o cheie scrisă greșit (`chatID`) ar fi
            // primit un „salvat” fără să se fi salvat nimic din ce voia omul.
            fail(
                'Setarea „' . text_de_aratat($camp) . '” nu e a plăților. Nimic din salvarea asta NU a fost scris.',
                422,
                'unknown_setting'
            );
        }
    }
    foreach (array_keys($b) as $camp) {
        $stapan = (string) setare_plati_drept(GP_PLATI_SETARI[(string) $camp]);
        if (!drept($stapan, $u)) {
            fail(
                'Nu ai dreptul să schimbi „' . $camp . '”. Nimic din salvarea asta NU a fost scris.',
                403,
                'forbidden'
            );
        }
    }

    plati_setari_scrie(plati_setari_valideaza($b), $u);

    json_out(plati_setari_raspuns($u));
}

/* ======================================================================== *
 *  Raportul de probă
 * ======================================================================== */

/**
 * POST /plati/raport-proba — trimite ACUM un raport spre chatul banilor.
 *
 * Butonul „Trimite acum un raport de probă” din „Încasări”: patronul a pus
 * chatul și vrea să vadă că ajunge, fără să aștepte ora nouă.
 *
 * NU ÎNSEAMNĂ NIMIC. Nu ia locul raportului de la oră și nu atinge amintirile
 * — vezi `plati_raport_proba()` (api/lib/plati-raport.php). Apăsat la 08:55,
 * raportul de la 09:00 pleacă tot.
 *
 * REFUZURILE, înaintea oricărei trimiteri: fără chat `422 plati_chat_missing`;
 * cu chatul egal cu al comenzilor `422 plati_chat_same` (raportul nu merge în
 * grupul turei nici „doar de probă”); fără tokenul botului `422
 * plati_token_missing`. Fără 032, `503 plati_unavailable`, ca restul rutelor.
 *
 * RĂSPUNSUL: `{ ok: true, trimis: true }`, ori — când Telegram n-a primit
 * mesajul — `502` cu `{ ok: false, motiv }`. `motiv` e vorba lui Telegram, din
 * care tokenul a fost scos; chatul și textul raportului nu se întorc.
 *
 * Nu scrie în Jurnal: încercarea rămâne în `notifications`, de unde o arată
 * „Încasări” la ultimele trimiteri, cu „probă” lângă ea.
 */
function route_plati_raport_proba(): void
{
    $u = require_drept('plati.telegram');
    plati_cere_baza();

    $piedica = plati_trimite_piedica();
    if ($piedica !== null) {
        fail($piedica[1], 422, $piedica[0]);
    }

    $r = plati_raport_proba();
    if (!$r['ok']) {
        json_out(['ok' => false, 'motiv' => (string) $r['motiv']], 502);
    }

    json_out(['ok' => true, 'trimis' => true]);
}
