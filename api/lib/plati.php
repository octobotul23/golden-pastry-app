<?php
/**
 * Plățile și pragul de comenzi neplătite (runda 2.7.0, migrarea 032).
 *
 * HOTĂRÂREA PATRONULUI, 4–5 octombrie 2026: a doua comandă neplătită a unui
 * client trece cu o avertizare, a treia se oprește până la plată. Pragul e 2
 * pentru toată lumea, cu excepții pe client (`users.prag_neplatite`). Agentul și
 * managerul se opresc la fel ca clientul; peste prag trece numai fondatorul (și
 * cine primește din „Acces” dreptul `plati.ocoleste`), cu o confirmare apăsată
 * dinadins, iar trecerea se scrie în Jurnal.
 *
 * STĂ AICI, în `api/lib/`, fiindcă poarta are cinci uși în două fișiere de rute
 * (`api/routes/requests.php`: trimiterea, acceptarea și readucerea unei cereri;
 * `api/routes/orders.php`: comanda nouă și corectura), iar routerul încarcă
 * UN SINGUR fișier de rute pe cerere (api/index.php). Scrisă de două ori, s-ar
 * fi despărțit la prima reparație — și s-ar fi despărțit pe partea care lasă să
 * treacă. Câte uși sunt nu se mai ține minte aici: le numără din cod
 * `.claude/check-plati.mjs`, care cere fiecăreia rândul ei din contract.
 *
 * „ANULATĂ” NU E O STARE DE PLATĂ. Se citește din etapă, cu `status_cancelled()`;
 * în `orders.plata` nu scrie nimeni așa ceva. O comandă anulată nu e datorie,
 * orice ar spune coloana de plată de pe ea.
 */

declare(strict_types=1);

/** Cele patru stări de plată, în ordinea în care le citește omul. */
const GP_PLATI_STARI      = ['neplatita', 'amanata', 'platita', 'banca'];
/** Neîncasat: banii nu au ajuns încă. „Amânată” e tot datorie, doar cu termen. */
const GP_PLATI_NEINCASATE = ['neplatita', 'amanata'];
/** Încasat: direct ori prin bancă. */
const GP_PLATI_INCASATE   = ['platita', 'banca'];
/** Numele fiecărei stări, cum se scrie în Jurnal și în rapoarte. */
const GP_PLATI_NUME       = [
    'neplatita' => 'Neplătită',
    'amanata'   => 'Amânată',
    'platita'   => 'Plătită',
    'banca'     => 'Plătită prin bancă',
];

/**
 * A fost importată migrarea 032?
 *
 * AMÂNDOUĂ COLOANELE SAU NIMIC, ca la `request_staff_supported()`: `orders.plata`
 * ține starea, `users.prag_neplatite` ține excepția de prag, iar poarta le
 * citește pe amândouă în aceeași numărătoare. O bază cu una din două e o migrare
 * oprită la mijloc, nu o stare în care s-ar putea merge mai departe cu grijă.
 *
 * Cade ÎNCHIS, dar „închis” aici înseamnă că POARTA lipsește, nu vânzarea: fără
 * 032 nu se oprește nicio comandă, nu se numără nimic și nicio interogare nu
 * pomenește vreo coloană nouă. Casa merge întocmai ca ieri.
 *
 * DE ȚINUT MINTE: întrebarea asta e o citire din `information_schema`, iar
 * făcută ÎNĂUNTRUL unei tranzacții îi îngheață „poza” — vezi `plati_poarta()`,
 * unde tocmai de aceea lacătul se ia înaintea ei.
 */
function plati_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        return $ok = ((int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND ((TABLE_NAME = 'orders' AND COLUMN_NAME = 'plata')
                  OR (TABLE_NAME = 'users'  AND COLUMN_NAME = 'prag_neplatite'))"
        ) === 2);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica migrarea plăților: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Pragul casei, pentru clienții care nu au unul al lor. 0 = fără limită.
 *
 * Setarea e text scris dintr-un panou, deci poate fi orice. CE NU E NUMĂR SE
 * CITEȘTE CA 2, hotărârea patronului — nu ca 0: o casetă stricată n-are voie să
 * scoată poarta din țâțâni pentru toți clienții deodată. Peste 99 se taie la 99,
 * marginea pe care o ține și coloana de pe cont.
 */
function plati_prag_general(): int
{
    $v = trim(setting('plati_prag_implicit', '2'));
    if (preg_match('/^\d{1,4}$/', $v) !== 1) {
        return 2;
    }
    return min(99, (int) $v);
}

/**
 * Pragul unui client: al lui, dacă are unul, altfel cel al casei.
 *
 * `NULL` pe cont înseamnă „cel general”, iar 0 înseamnă „fără limită” — două
 * lucruri deosebite, deci nu se poate citi cu `?:` ori cu `(int)` de-a dreptul:
 * clientul scos dinadins de sub prag ar fi căzut înapoi pe pragul tuturor.
 */
function plati_prag(int $clientId): int
{
    if (!plati_supported()) {
        return plati_prag_general();
    }
    $r = one('SELECT prag_neplatite FROM users WHERE id = ?', [$clientId]);
    return plati_prag_din($r === null ? null : $r['prag_neplatite']);
}

/**
 * Pragul care iese din coloana de pe cont, oricine ar fi citit-o: `NULL` e
 * pragul casei, altfel numărul de acolo, ținut între 0 și 99.
 *
 * DESPRINSĂ DIN `plati_prag()` pe 9 octombrie 2026, când lista datornicilor din
 * „Încasări” a încetat să întrebe poarta client cu client (`incasari_clienti()`,
 * api/routes/plati.php) și își citește pragurile într-o singură interogare.
 * Coloana o are deja în mână; ce îi lipsea era REGULA — iar regula scrisă a
 * doua oară acolo ar fi fost tocmai locul în care cartonașul și poarta ajung să
 * spună două praguri despre același om.
 *
 * @param mixed $coloana ce a întors baza pentru `users.prag_neplatite`
 */
function plati_prag_din($coloana): int
{
    if ($coloana === null) {
        return plati_prag_general();
    }
    return max(0, min(99, (int) $coloana));
}

/**
 * Starea care iese dintr-un prag și dintr-o numărătoare: `liber`, `avertizare`
 * ori `blocat`.
 *
 * 0 = fără limită, deci „liber” oricâte ar avea. Altfel se oprește cel ajuns LA
 * prag, nu abia cel trecut de el: cu pragul 2, a doua neplătită e ultima care
 * intră, iar a treia e cea refuzată.
 *
 * O SINGURĂ SOCOTEALĂ, pentru poartă (`plati_situatie()`) și pentru cartonașele
 * din „Încasări” — din aceeași pricină ca `plati_prag_din()`.
 */
function plati_stare(int $prag, int $n): string
{
    if ($prag === 0 || $n === 0) {
        return 'liber';
    }
    return $n >= $prag ? 'blocat' : 'avertizare';
}

/**
 * Ce cerere se numără la prag, ca bucată de `WHERE` peste `order_requests r`:
 * cea încă în așteptare CARE ARE PRODUSE (de ce, la `plati_situatie()`).
 *
 * `EXISTS`, nu JOIN: o cerere cu zece poziții e tot una singură.
 *
 * Scrisă o dată, fiindcă o citesc doi: numărătoarea unui client și cea pe
 * mulțime (`plati_cereri_asteptate()`).
 */
const GP_PLATI_CERERE_NUMARATA = "r.status = 'nou'"
    . ' AND EXISTS (SELECT 1 FROM order_request_items i WHERE i.request_id = r.id)';

/**
 * Cererile în așteptare care se numără la prag, pentru MAI MULȚI clienți
 * deodată: `[id de cont => câte]`. Cine n-are niciuna lipsește din răspuns.
 *
 * DE CE EXISTĂ (9 octombrie 2026). `incasari_clienti()` întreba
 * `plati_situatie()` pentru fiecare datornic în parte — patru citiri de client,
 * măsurate pe 5 octombrie la 1,14 ms. La 27 de cartonașe nu se vedea; la o
 * mie de conturi cu ceva de încasat, deschiderea „Încasărilor” ar fi ajuns la
 * patru mii de interogări. Aici e UNA, oricâți ar fi.
 *
 * Aceeași condiție ca la un singur client (`GP_PLATI_CERERE_NUMARATA`), deci
 * aceeași cifră. Cere 032 în bază, ca tot ce numără plăți: cine o cheamă a
 * întrebat deja.
 *
 * @param int[] $conturi
 * @return array<int, int>
 */
function plati_cereri_asteptate(array $conturi): array
{
    $conturi = array_values(array_unique(array_map('intval', $conturi)));
    if (!$conturi) {
        return [];
    }
    $semne   = implode(', ', array_fill(0, count($conturi), '?'));
    $randuri = all(
        'SELECT r.customer_id AS cont, COUNT(*) AS n FROM order_requests r'
            . ' WHERE r.customer_id IN (' . $semne . ') AND ' . GP_PLATI_CERERE_NUMARATA
            . ' GROUP BY r.customer_id',
        $conturi
    );
    $out = [];
    foreach ($randuri as $r) {
        $out[(int) $r['cont']] = (int) $r['n'];
    }
    return $out;
}

/**
 * Lacătul clientului: cât ține tranzacția, nimeni altcineva nu mai numără și nu
 * mai scrie pentru el prin poartă.
 *
 * DE CE PE RÂNDUL CONTULUI, și nu pe comenzile numărate. Comenzile și cererile
 * lui sunt rânduri pe care le mai țin și alții — mutarea unei etape, scăderea
 * din stoc, acceptarea altei cereri. Încuiate de aici, s-ar fi deschis
 * încrucișări: eu țin contul și aștept o comandă, altul ține comanda aceea și
 * așteaptă contul. InnoDB rupe nodul omorând una din tranzacții (40001), iar
 * buclele de reluare din rute reiau numai 23000 — omul ar fi văzut „Eroare
 * internă”. Rândul contului e un singur punct, luat de fiecare poartă ultimul
 * dintre rândurile vechi, deci cozile se formează, nu se încrucișează.
 *
 * CERE TRANZACȚIE DESCHISĂ, și o spune cu voce tare. În afara uneia, `FOR
 * UPDATE` ia lacătul și îl lasă în aceeași clipă: poarta ar fi arătat pusă fără
 * să țină pe nimeni. O greșeală de felul ăsta trebuie să iasă la prima probă,
 * nu la a doua filă deschisă de un client grăbit.
 */
function plati_lacat(int $clientId): void
{
    if (!db()->inTransaction()) {
        throw new LogicException('plati_lacat() cere o tranzacție deschisă.');
    }
    q('SELECT id FROM users WHERE id = ? FOR UPDATE', [$clientId])->fetchAll();
}

/**
 * Situația de plată a unui client: câte are neîncasate, cât datorează, și dacă
 * mai poate comanda.
 *
 * CE SE NUMĂRĂ:
 *   · comenzile LUI (legate de cont) neîncasate și neanulate — cele scrise pe
 *     un nume fără cont nu sunt ale nimănui anume și nu intră la nimeni;
 *   · cererile lui încă în așteptare (`nou`) CARE AU PRODUSE. O întrebare ori o
 *     cerere de ofertă nu e o comandă și nu ține locul uneia; dar o cerere cu
 *     coș e o comandă pe drum, iar nenumărată ar fi fost chiar ocolul pragului:
 *     trei cereri trimise una după alta, toate acceptate a doua zi.
 *
 * `$fara` scoate din numărătoare lucrul care tocmai se scrie: `'cerere' => id`
 * la acceptare (cererea aceea devine comanda judecată, nu una în plus) și
 * `'comanda' => id` la corectarea unei comenzi (ea însăși nu se pune la socoteală
 * împotriva ei).
 *
 * SOLDUL E NUMAI AL COMENZILOR. Cererile nu sunt datorie — nu s-a făcut și nu
 * s-a livrat nimic din ele.
 *
 * LISTA E TĂIATĂ LA 20, NUMĂRUL NU. `nComenzi` și `sold` vin dintr-o socoteală
 * pe toată baza; lista e pentru ecran, iar un client cu o sută de comenzi
 * neplătite n-are de ce să primească o sută de rânduri într-un refuz.
 *
 * CITIRI SIMPLE, DINADINS (fără `FOR UPDATE`/`FOR SHARE`): vezi `plati_lacat()`.
 * Că sunt și proaspete o ține ordinea din `plati_poarta()`.
 *
 * @return array{prag:int, comenzi:array, nComenzi:int, cereri:int, n:int, sold:float, stare:string}
 */
function plati_situatie(int $clientId, array $fara = []): array
{
    $gol = [
        'prag' => 0, 'comenzi' => [], 'nComenzi' => 0, 'cereri' => 0,
        'n' => 0, 'sold' => 0.0, 'stare' => 'liber',
    ];
    // Fără 032 nu există nici stare de plată, nici prag: „fără limită” e
    // singurul răspuns adevărat, și e cel care nu oprește pe nimeni.
    if (!plati_supported()) {
        return $gol;
    }

    $prag = plati_prag($clientId);

    // Starea de plată se scrie din constantă, nu de mână: a doua listă cu
    // aceleași două vorbe ar fi rămas în urmă la prima stare nouă.
    $semne = implode(', ', array_fill(0, count(GP_PLATI_NEINCASATE), '?'));
    $cond  = 'customer_id = ? AND plata IN (' . $semne . ')';
    $par   = array_merge([$clientId], GP_PLATI_NEINCASATE);

    // Etapa de anulare nepusă în Setări = nicio comandă nu e anulată. Condiția
    // se lasă atunci pe dinafară cu totul, ca la `primele_comenzi()`: un
    // `status <> ''` ar fi fost adevărat pentru toate și n-ar fi stricat nimic,
    // dar ar fi spus în cod că există o etapă cu numele gol.
    $anulata = status_cancelled();
    if ($anulata !== '') {
        $cond .= ' AND status <> ?';
        $par[] = $anulata;
    }
    $faraComanda = (int) ($fara['comanda'] ?? 0);
    if ($faraComanda > 0) {
        $cond .= ' AND id <> ?';
        $par[] = $faraComanda;
    }

    $cifre = one('SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS sold FROM orders WHERE ' . $cond, $par);
    $nComenzi = (int) ($cifre['n'] ?? 0);
    $sold     = round((float) ($cifre['sold'] ?? 0), 2);

    $comenzi = [];
    if ($nComenzi > 0) {
        // Cele mai vechi întâi: aceea e comanda de care întreabă omul de la
        // casă — „de când nu a mai plătit?”.
        $randuri = all(
            'SELECT id, seq, ref, day, total FROM orders WHERE ' . $cond . ' ORDER BY day ASC, id ASC LIMIT 20',
            $par
        );
        foreach ($randuri as $r) {
            $comenzi[] = [
                'id'    => (int) $r['id'],
                // Aceeași rezervă ca în `attach_items()`: comenzile vechi n-au
                // referința scrisă, iar omul trebuie să vadă același număr pe
                // care îl vede în panou.
                'ref'   => $r['ref'] ?: ('#' . (1000 + (int) $r['seq'])),
                'zi'    => (string) $r['day'],
                'total' => round((float) $r['total'], 2),
            ];
        }
    }

    // Ce cerere se numără stă într-un singur loc — o citește și numărătoarea
    // pe mulțime a „Încasărilor” (`plati_cereri_asteptate()`).
    $condCereri = 'r.customer_id = ? AND ' . GP_PLATI_CERERE_NUMARATA;
    $parCereri  = [$clientId];
    $faraCerere = (int) ($fara['cerere'] ?? 0);
    if ($faraCerere > 0) {
        $condCereri .= ' AND r.id <> ?';
        $parCereri[] = $faraCerere;
    }
    $cereri = (int) scalar('SELECT COUNT(*) FROM order_requests r WHERE ' . $condCereri, $parCereri);

    $n     = $nComenzi + $cereri;
    $stare = plati_stare($prag, $n);

    return [
        'prag'     => $prag,
        'comenzi'  => $comenzi,
        'nComenzi' => $nComenzi,
        'cereri'   => $cereri,
        'n'        => $n,
        'sold'     => $sold,
        'stare'    => $stare,
    ];
}

/**
 * POARTA PRAGULUI. Se cheamă ÎNĂUNTRUL tranzacției apelantului, înaintea
 * oricărei scrieri.
 *
 * Întoarce `null` când nu e nimic de oprit, ori situația cu `'ocolit' => true`
 * când cineva cu drept a trecut dinadins peste prag — atunci apelantul scrie
 * trecerea în Jurnal DUPĂ commit, cu `plati_jurnal_ocolire()`. Altfel NU SE MAI
 * ÎNTOARCE: dă tranzacția înapoi și răspunde 409 `prag_neplatite`. Din clipa
 * aceea nu s-a scris nimic, nu s-a rezervat nimic, n-a plecat niciun anunț și nu
 * s-a consumat nimic din plafonul de cereri al conexiunii.
 *
 * ORDINEA DE MAI JOS E CHIAR REPARAȚIA, nu un amănunt:
 *
 *   1. LACĂTUL ÎNTÂI, ÎNAINTEA ORICĂREI ALTE CITIRI. Baza merge pe REPEATABLE
 *      READ: prima citire simplă a tranzacției îi face „poza”, iar tot ce
 *      numără după aceea numără din poză. Două file care trimit în aceeași
 *      clipă: a doua așteaptă la lacăt, îl primește după ce prima a scris — și,
 *      dacă poza ei era făcută dinainte, numără tot lumea de DINAINTEA primei.
 *      Trec amândouă, cu lacăt cu tot. Citirea cu lacăt (`FOR UPDATE`) nu face
 *      poză, deci dacă ea e prima, poza se face abia după ce prima filă a
 *      terminat.
 *
 *      MĂSURAT pe 5 octombrie 2026, pe MySQL 8.4 local, cu două legături: o
 *      citire din `information_schema` — adică tocmai întrebarea
 *      `plati_supported()` — face și ea poza. De aceea lacătul stă ÎNAINTEA ei,
 *      deși foaia rundei le scria invers. Pe o bază fără 032 se ia astfel un
 *      lacăt de care nu era nevoie; nu costă nimic și nu oprește pe nimeni
 *      (comanda scrisă imediat după ia oricum același rând, pentru cheia
 *      străină), iar poarta rămâne dreaptă oriunde ar fi chemată.
 *
 *      Ce rămâne în grija APELANTULUI: între `beginTransaction()` și poartă
 *      n-are voie să stea nicio citire simplă. Citirile cu lacăt sunt bune.
 *
 *   2. Abia apoi „e baza migrată?” și numărătoarea.
 *
 * CINE POATE TRECE se judecă pe CEL CARE FACE FAPTA (`$cine`), niciodată pe
 * clientul pentru care se scrie. Clientul nu are dreptul și nu-l poate primi
 * (`drept()` răspunde „nu” pentru rolul lui), deci un `ocolesteprag` trimis de
 * el nu folosește la nimic. `$faraOcolire` e pentru drumurile pe care n-are
 * unde să se apese confirmarea — acceptarea de sub anunțul din Telegram.
 *
 * @param int|null   $clientId    contul pe care s-ar scrie; `null` = fără cont,
 *                                deci nimic de numărat și nimic de oprit
 * @param array|null $cine        cel care face fapta
 * @param array      $b           corpul cererii (de aici se citește `ocolesteprag`)
 * @param array      $fara        ce se scoate din numărătoare — v. `plati_situatie()`
 * @param bool       $faraOcolire drumul ăsta nu poate trece peste prag
 */
function plati_poarta(?int $clientId, ?array $cine, array $b, array $fara = [], bool $faraOcolire = false): ?array
{
    if ($clientId === null) {
        return null;
    }

    plati_lacat($clientId);

    if (!plati_supported()) {
        return null;
    }

    $s = plati_situatie($clientId, $fara);
    if ($s['stare'] !== 'blocat') {
        return null;
    }

    $cont = one('SELECT name, customer_name FROM users WHERE id = ?', [$clientId]);
    $nume = $cont ? client_display_name($cont['customer_name'] ?? null, $cont['name'] ?? null) : '';
    if ($nume === '') {
        $nume = 'Clientul';
    }
    $client = ['id' => $clientId, 'nume' => $nume];

    $poate = !$faraOcolire && $cine !== null && drept('plati.ocoleste', $cine);
    if ($poate && !empty($b['ocolesteprag'])) {
        return $s + ['ocolit' => true, 'client' => $client];
    }

    // ÎNTREBAREA „CUI I SE RĂSPUNDE” SE PUNE AICI, CÂT TRANZACȚIA E DESCHISĂ, nu
    // mai jos, lângă răspunsul care o folosește. `plati_poate_afla()` citește
    // din bază (matricea de drepturi, ograda), iar o citire poate cădea. Căzută
    // DUPĂ `rollBack()`-ul de dedesubt, eroarea ei urca în `catch`-ul
    // apelantului — iar trei din cele cinci uși (acceptarea unei cereri,
    // comanda nouă, corectarea unei comenzi) dau acolo tranzacția înapoi fără
    // să întrebe dacă mai e una. PDO răspunde atunci „There is no active
    // transaction”, și în jurnalul serverului rămâne eroarea ASTA, nu cea
    // adevărată: omul vede „Eroare internă”, iar cine caută pricina găsește o
    // minciună. Văzut la a doua citire a încheierii, pe 9 octombrie 2026.
    // `catch`-urile acelea sunt drepte pentru tot ce stă ÎNĂUNTRUL tranzacției
    // lor, deci s-a mutat citirea, nu ele.
    //
    // De la `rollBack()` în jos nu se mai citește nimic din bază: numai vorbe
    // puse cap la cap și `json_out()`. `.claude/check-plati.mjs` ține ordinea.
    $afla = plati_poate_afla($cine, $clientId);

    // Tranzacția se închide ÎNAINTEA răspunsului: `json_out()` face `exit`, iar
    // o tranzacție lăsată deschisă la ieșire s-ar da înapoi oricum — dar
    // lacătul clientului ar fi ținut până atunci coada celorlalte file.
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    // CUI I SE RĂSPUNDE. Refuzul lung de mai jos poartă numele clientului, câte
    // are, cât datorează și lista comenzilor lui — adică tocmai ce dă ușa din
    // timp (`GET /plati/client/#`). Îl primește deci numai cine ar fi primit
    // și răspunsul aceleia; ceilalți află doar că s-a oprit la prag.
    //
    // PĂȚANIA (văzută pe 9 octombrie 2026, cu proba vie): un cont numai cu
    // „Laborator” scotea din „Anulată” comanda unui client aflat la prag și
    // primea numele firmei, două referințe și „13,96 lei” — omul căruia lista
    // de comenzi nu-i arată nici măcar dacă o comandă e plătită
    // (`plati_fel()`). Ușa din timp îl refuza; refuzul de aici îi spunea tot.
    //
    // `poateOcoli` rămâne cel adevărat și în răspunsul scurt. Cine are „trece
    // peste prag” fără să aibă și ograda (bifa dată din „Acces” unui rol care
    // nu scrie comenzi) trebuie să poată fi întrebat „treci?” — altfel dreptul
    // lui ar fi o bifă care nu face nimic. Lista însă nu i se dă pe ușa din
    // dos: trece știind doar că e peste prag, iar trecerea i se scrie în
    // Jurnal ca oricui.
    //
    // Cine nu e nimeni (nicio sesiune) intră tot aici. Azi drumul ăsta nu
    // există: o cerere nu se leagă de un cont decât cu o sesiune în spate.
    if (!$afla) {
        json_out(['error' => [
            'code'       => 'prag_neplatite',
            'message'    => plati_mesaj_scurt($cine, $poate),
            'poateOcoli' => $poate,
        ]], 409);
    }

    // Lista are, pentru oricine, numai referința, ziua și suma. Clientul nu
    // află din refuz care comandă e „amânată”, până când, ori ce notă și-a
    // scris casa pe ea: acelea sunt ale paginii „Încasări”.
    json_out(['error' => [
        'code'       => 'prag_neplatite',
        'message'    => ($cine['role'] ?? '') === 'client'
            ? plati_mesaj_client()
            : plati_mesaj_casa($nume, $s),
        'prag'       => $s['prag'],
        'n'          => $s['n'],
        'sold'       => $s['sold'],
        'cereri'     => $s['cereri'],
        'neplatite'  => $s['comenzi'],
        'client'     => $client,
        'poateOcoli' => $poate,
    ]], 409);
    return null;
}

/**
 * CUI I SE SPUNE cum stă clientul ăsta cu plățile: câte are, cât datorează,
 * care comenzi.
 *
 * O SINGURĂ SOCOTEALĂ, pentru amândouă locurile care dau răspunsul acela: ușa
 * din timp (`route_plati_client()`, api/routes/plati.php) și refuzul porții de
 * mai sus. Până pe 9 octombrie 2026 erau două: ușa întreba de ogradă, refuzul
 * nu întreba nimic — și ce nu dădea una dădea celălalt, oricui ajungea să fie
 * oprit (pățania, la `plati_poarta()`). `.claude/check-plati.mjs` cere ca
 * amândouă să întrebe AICI și niciuna să nu-și mai scrie condiția cu mâna.
 *
 *   clientul  → despre EL, da: lista lui o are oricum la `GET /plati/mine`;
 *   casa      → numai cine are panoul „Comenzi”, și dintre ei:
 *                 · cine scrie comenzi, pentru ORICE cont, activ ori nu;
 *                 · agentul, pentru clienții LUI (`client_in_scope()`);
 *   restul    → nu. Nici cine mută etape în laborator, nici cine duce marfa,
 *               nici cine n-are sesiune.
 *
 * DE CE CINE SCRIE COMENZI NU MAI TRECE PRIN `client_in_scope()`. Aceea e
 * întrebarea „pot ALEGE clientul ăsta pentru o comandă nouă?”, deci cere un
 * cont ACTIV — pe bună dreptate acolo. Aici întrebarea e alta: „am voie să văd
 * ce datorează?”. Sprijinită pe ea, socoteala răspundea „nu” oricui pentru un
 * client dezactivat, iar managerul care readucea cererea respinsă a unui astfel
 * de client primea refuzul scurt: „Roagă pe cineva care scrie comenzi să vadă
 * cum stă cu plățile”. Cel care scrie comenzi era chiar el, iar ușa din timp îi
 * răspundea 403 — și fondatorului. Un „nu” fără nicio ieșire, tocmai pentru
 * clientul la care e cel mai mult de lămurit: datoria nu se stinge odată cu
 * contul, iar „Încasări” i-o arăta oricum (văzut la a doua citire, 9 octombrie
 * 2026; `test-plati-prag`, secțiunea N).
 *
 * Agentul rămâne la ograda lui întreagă, cu „activ” cu tot: clientul dezactivat
 * nu mai e al lui nici în căutare, nici aici.
 *
 * Că numărul e chiar al unui cont de CLIENT nu se întreabă aici. Poarta e
 * chemată numai cu contul de pe o comandă ori de pe o cerere, iar ușa din timp
 * întreabă singură, DUPĂ socoteala asta (de ce în ordinea aceea, acolo).
 */
function plati_poate_afla(?array $cine, int $clientId): bool
{
    if ($cine === null) {
        return false;
    }
    if (($cine['role'] ?? '') === 'client') {
        return (int) ($cine['id'] ?? 0) === $clientId;
    }
    if (!drept('comenzi', $cine)) {
        return false;
    }
    $ograda = client_scope_for($cine);
    if ($ograda !== null && $ograda['toti']) {
        return true;
    }
    return client_in_scope($cine, $clientId);
}

/** Vorba patronului pentru clientul oprit la prag. Aceeași peste tot. */
function plati_mesaj_client(): string
{
    return 'Numărul maxim de comenzi a fost atins. Până plătești soldul comenzilor precedente '
        . 'nu putem primi o comandă nouă. Contactează-ne pentru mai multe detalii.';
}

/**
 * Refuzul SCURT: pentru cine a fost oprit la prag, dar n-are de ce să afle cum
 * stă clientul (`plati_poate_afla()`).
 *
 * Fără nume, fără nicio cifră și fără nicio comandă. Nici pragul: „pragul e 2”
 * lângă „s-a oprit” ar spune câte are. Spune două lucruri de care omul are
 * nevoie ca să nu rămână în fața unui „nu” fără ieșire: că NU S-A SCRIS nimic
 * (altfel încearcă a doua oară, crezând că a rămas pe jumătate) și CUI să-i
 * ceară — cuiva care scrie comenzi, fiindcă aceia văd lista și tot ei pot
 * însemna plata.
 *
 * Celui care poate trece i se spune că poate, nu cui să ceară: întrebarea de
 * confirmare a paginii se sprijină pe vorba asta.
 *
 * Cine n-are sesiune ori e client (fără să fie chiar contul oprit — drum care
 * azi nu există) primește vorba patronului pentru clienți: „roagă pe cineva
 * care scrie comenzi” n-are niciun înțeles în afara casei.
 */
function plati_mesaj_scurt(?array $cine, bool $poate): string
{
    if ($cine === null || ($cine['role'] ?? '') === 'client') {
        return plati_mesaj_client();
    }
    $oprit = 'Clientul ăsta a ajuns la pragul de comenzi neplătite. Nu am scris nimic. ';
    return $poate
        ? $oprit . 'Poți trece peste prag, cu confirmare.'
        : $oprit . 'Roagă pe cineva care scrie comenzi să vadă cum stă cu plățile.';
}

/**
 * Refuzul citit de omul casei: cine, câte, cât, și că nu s-a scris nimic.
 *
 * „Ori în așteptare” apare numai când în numărătoare au intrat și cereri:
 * altfel omul ar căuta în „Încasări” trei comenzi și ar găsi două.
 */
function plati_mesaj_casa(string $nume, array $s): string
{
    $n = (int) $s['n'];
    $ce = $n === 1 ? 'comandă neplătită' : 'comenzi neplătite';
    if ((int) $s['cereri'] > 0) {
        $ce .= ' ori în așteptare';
    }
    return $nume . ' are ' . $n . ' ' . ro_de($n) . $ce
        . ' (' . number_format((float) $s['sold'], 2, ',', '.') . ' lei)'
        . ' — pragul e ' . (int) $s['prag'] . '. Nu am scris nimic.';
}

/**
 * Trecerea peste prag, scrisă în Jurnal. Se cheamă DUPĂ commit, cu ce a întors
 * `plati_poarta()` și cu ce s-a născut din trecere.
 *
 * După commit, nu în tranzacție: un rând de Jurnal despre o comandă care până
 * la urmă nu s-a scris ar fi învinuit pe cineva de o faptă nefăcută. Iar
 * numărul comenzii născute nici nu există până atunci.
 *
 * Pe contul CLIENTULUI (`entity` = `user`), nu pe comandă: întrebarea la care
 * răspunde rândul e „cui i s-a dat peste prag, și de către cine”, iar răspunsul
 * se caută după client.
 */
function plati_jurnal_ocolire(array $ocolit, string $scris): void
{
    $n = (int) ($ocolit['n'] ?? 0);
    audit(
        'prag_ocolit',
        'user',
        (string) ($ocolit['client']['id'] ?? ''),
        'Peste prag pentru „' . (string) ($ocolit['client']['nume'] ?? '') . '”: '
            . $n . ' ' . ro_de($n) . 'neîncasate, pragul e ' . (int) ($ocolit['prag'] ?? 0)
            . ' — ' . $scris
    );
}

/**
 * Cât din plată are voie să vadă cel care se uită la o comandă: `''` (nimic),
 * `'client'` ori `'tot'` — al patrulea argument al lui `attach_items()`.
 *
 * Scrisă o dată, fiindcă o cheamă toate rutele care întorc comenzi. Trei
 * răspunsuri, și cel din mijloc e miezul:
 *
 *   clientul              → numai „de plată / plătită” pe comenzile lui;
 *   cine are „Încasări”   → starea întreagă, cu scadență, clipă, sumă și notă;
 *   restul casei          → NIMIC, nici măcar cheile. Cine mută etape în
 *                           laborator ori duce marfa n-are de ce să afle din
 *                           lista de comenzi cine a plătit și cine nu.
 *
 * Fără 032 răspunsul e „nimic” pentru oricine.
 */
function plati_fel(?array $cine): string
{
    if ($cine === null || !plati_supported()) {
        return '';
    }
    if (($cine['role'] ?? '') === 'client') {
        return 'client';
    }
    return drept('incasari', $cine) ? 'tot' : '';
}

/* ======================================================================== *
 *  Ce le trebuie RUTELOR plăților (api/routes/plati.php) și celor două rute
 *  vechi care scriu praguri și setări ale plăților (api/routes/users.php,
 *  api/routes/settings.php). Stau aici din aceeași pricină ca poarta de sus:
 *  routerul încarcă un singur fișier de rute pe cerere.
 * ======================================================================== */

/**
 * Oprește cererea când lipsește migrarea 032.
 *
 * O rută a plăților fără coloanele ei n-are cu ce lucra, iar o interogare
 * scăpată spre bază ar fi ieșit ca 1054 — „Eroare internă”, fără nicio vorbă
 * despre leac. 503, nu 500: nu s-a stricat nimic, lipsește un pas de instalare,
 * iar refuzul îl numește.
 *
 * Îl numește CU FIȘIERUL, ca toate celelalte refuzuri de felul ăsta din casă
 * („rulează sql/033_acces.sql”). „Migrarea 032” singură îi spune ceva numai
 * celui care a scris-o: omul care ține serverul are în față un dosar `sql/`,
 * nu o numărătoare, iar „actualizarea bazei” nu e o comandă pe care s-o poată
 * rula. Proba bazei vechi cere numele fișierului de la fiecare ușă pe care o
 * poate atinge; `check-refuzuri-503` îl cere și de la cele pe care nu le atinge.
 *
 * `$cod` e altul numai la `PATCH /users/#` (`prag_unavailable`): acolo pagina
 * „Conturi” deosebește „lipsește pragul” de celelalte migrări lipsă ale contului.
 */
function plati_cere_baza(string $cod = 'plati_unavailable'): void
{
    if (!plati_supported()) {
        fail('Plățile nu sunt încă pornite pe server: rulează întâi sql/032_plati_si_prag.sql.', 503, $cod);
    }
}

/**
 * Clipa UTC la care ÎNCEPE o zi a patiseriei — miezul nopții din România, scris
 * cum îl ține baza.
 *
 * DE CE E NEVOIE DE EA. `orders.plata_la` e o clipă UTC (`UTC_TIMESTAMP()`), iar
 * „Încasat azi” și perioada din „Încasări” sunt zile LOCALE. Un `DATE(plata_la)`
 * pus între ele ar muta pe ziua de ieri fiecare plată bifată între 00:00 și
 * 03:00 ora României — tocmai orele în care șoferul de noapte predă banii.
 *
 * DE CE CLIPE, ȘI NU `zi_locala_sql()`. Aceea e pentru când ziua locală trebuie
 * să iasă PE FIECARE RÂND, ca să se poată grupa după ea. Aici sunt numai două
 * margini — „de la începutul zilei A până la începutul zilei de după B” — deci
 * se compară coloana goală cu două clipe, ca `stock_local_day_utc()` din depozit:
 * indexul `ix_orders_plata_la` rămâne folositor, iar ziua de 23 ori de 25 de ore
 * din noaptea în care se mută ceasul iese dreaptă de la sine, fiindcă marginile
 * ei le socotește fusul, nu un decalaj scris de mână.
 *
 * Miezul nopții nu cade niciodată în ora sărită ori repetată: în România ceasul
 * se mută la 03:00, respectiv la 04:00.
 */
function plati_clipa_utc(string $zi): string
{
    return (new DateTimeImmutable($zi . ' 00:00:00', new DateTimeZone('Europe/Bucharest')))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

/**
 * Un prag de comenzi neplătite, citit FĂRĂ nicio bunăvoință: întreg, de la 0
 * la 99. Orice altceva oprește cererea cu `422 bad_prag`.
 *
 * DE CE NU `num_field()` ȘI NU `(int)`. Pragul are trei înțelesuri lipite de
 * trei valori vecine — `null` „cel general”, 0 „fără limită”, 1…99 pragul — iar
 * fiecare rotunjire tăcută mută omul dintr-unul în altul: `(int) ""` și
 * `(int) "abc"` dau 0, adică un client scos de sub prag de o casetă goală;
 * `(int) 2.5` dă 2; `(int) true` dă 1; `num_field()` primește 2.5 fără să
 * clipească. Aici trec numai întregul JSON și textul făcut numai din cifre
 * („3”, cum vine pragul general din caseta de Setări). `null` NU e treaba
 * funcției: cine are voie să-l primească îl judecă înainte de a o chema.
 */
function plati_prag_citit($v, string $eticheta): int
{
    $n = null;
    if (is_int($v)) {
        $n = $v;
    } elseif (is_string($v) && preg_match('/^(0|[1-9]\d?)\z/', $v) === 1) {
        $n = (int) $v;
    }
    if ($n === null || $n < 0 || $n > 99) {
        fail(
            $eticheta . ' trebuie să fie un număr întreg de la 0 la 99 (0 = fără limită).',
            422,
            'bad_prag'
        );
    }
    return $n;
}

/**
 * Setările plăților: numele din cererea lui `PUT /plati/setari` → cheia din
 * tabelul `settings`. Dreptul fiecăreia îl ține `setare_plati_drept()`
 * (api/lib/settings.php), lângă cele două uși pe care le păzește.
 */
const GP_PLATI_SETARI = [
    'pragImplicit' => 'plati_prag_implicit',
    'chatId'       => 'plati_telegram_chat_id',
    'ore'          => 'plati_raport_ore',
    'amintiri'     => 'plati_amintiri',
    'panelBaseUrl' => 'panel_base_url',
];

/**
 * Faptele din Jurnal pe care le vede numai cine are „Încasări”: schimbarea
 * stării de plată a unei comenzi și schimbarea setărilor plăților.
 *
 * O singură listă, citită de `GET /log` (api/routes/log.php). O faptă nouă a
 * plăților care poartă bani ori nume de datornici se adaugă AICI, altfel o vede
 * oricine are Jurnalul. `prag_ocolit` lipsește dinadins: se vede oricui.
 */
const GP_PLATI_FAPTE_ASCUNSE = ['plata_schimba', 'plati_setari'];

/** O oră de raport: `HH:MM`, pe 24 de ore. */
const GP_PLATI_ORA = '/^([01]\d|2[0-3]):[0-5]\d\z/';

/**
 * Numărul unui chat Telegram, adus la O SINGURĂ scriere, ca două chaturi să se
 * poată compara: fără spații, fără plus, fără zerouri în față.
 *
 * DE CE (găsit la recitirea rundei, 5 octombrie 2026). Telegram citește
 * `chat_id` ca NUMĂR: „-01001234567890”, „ -1001234567890 ” și „-1001234567890”
 * sunt, pentru el, aceeași conversație. Comparate ca TEXT, sunt trei chaturi
 * diferite — iar regula „raportul de bani are chatul lui, altul decât cel al
 * comenzilor” trecea cu un zero pus în față, și raportul cu datornici pleca
 * tocmai în grupul în care stă toată tura.
 *
 * Ce nu e număr (un `@canal`, cum se poate scrie chatul comenzilor) rămâne
 * textul lui, curățat de margini. Un `@canal` și numărul ACELUIAȘI chat nu se
 * pot recunoaște de aici — le-ar putea lega numai Telegram, întrebat; chatul
 * banilor nu poate fi însă decât număr, deci regula se sprijină pe numere.
 */
function plati_chat_norma(string $v): string
{
    // Spațiile de orice fel, și cele nevăzute pe care le aduce o lipire din
    // Telegram ori dintr-un document (spațiul nedespărțitor, cel de lățime
    // zero, semnul de ordine de la capăt de fișier).
    $curat = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $v);
    // `null` = textul nu e UTF-8 întreg; atunci rămâne cum a venit, tăiat de
    // margini, și se compară ca text.
    $v = $curat === null ? trim($v) : $curat;
    if (preg_match('/^([+-]?)0*(\d+)\z/', $v, $m) === 1) {
        return ($m[1] === '-' ? '-' : '') . $m[2];
    }
    return $v;
}

/**
 * Sunt cele două același chat? Un chat gol nu e „același” cu nimic: gol
 * înseamnă „oprit”, iar două lucruri oprite nu-și calcă pe conversație.
 *
 * SINGURA comparație dintre chatul banilor și cel al comenzilor. O cheamă
 * amândouă sensurile regulii — chatul banilor pus peste al comenzilor
 * (`plati_setari_valideaza()`) și chatul comenzilor mutat peste al banilor
 * (`guard_plati()`, api/routes/settings.php) — și e de chemat și de cel care
 * TRIMITE raportul, înaintea fiecărei trimiteri: regula de la scriere nu poate
 * păzi o bază în care cele două au ajuns egale pe altă cale.
 */
function plati_chat_acelasi(string $a, string $b): bool
{
    $a = plati_chat_norma($a);
    return $a !== '' && $a === plati_chat_norma($b);
}

/**
 * REGULILE celor cinci setări ale plăților, într-un singur loc.
 *
 * Două uși scriu setările astea: `PUT /plati/setari` (pagina „Încasări”) și
 * `PUT /settings` (caseta pragului general din „Setări”, plus orice cerere
 * scrisă de mână). Cu regulile ținute de două ori, a doua ușă ar fi rămas în
 * urmă la prima îndreptare — și ar fi rămas pe partea care lasă să treacă: un
 * prag de 500 ori un chat egal cu al comenzilor, scrise liniștit prin ușa din
 * dos.
 *
 * PRIMEȘTE numele din `PUT /plati/setari` (`GP_PLATI_SETARI`); ce vine pe chei
 * de tabel trece întâi prin `plati_setari_din_chei()`. Judecă NUMAI cheile care
 * sunt de față: o cerere poate purta orice parte din cele cinci.
 *
 * ÎNTOARCE `cheia din tabel => textul de scris`. Nu scrie nimic și nu întreabă
 * de niciun drept: dreptul îl hotărăște ruta, ÎNAINTE să ajungă aici, ca un
 * refuz de regulă să nu-i spună celui fără drept ceva despre o setare pe care
 * n-are voie s-o vadă.
 *
 * `$chatComenzi` = chatul comenzilor cu care se compară chatul banilor. Lipsă =
 * cel din bază. `PUT /settings` îl dă pe cel din ACEEAȘI cerere, când cererea
 * îl schimbă și pe acela: altfel cele două s-ar fi putut face egale într-o
 * singură salvare, fiecare judecat lângă valoarea veche a celuilalt.
 *
 * DE CE CELE DOUĂ CHATURI NU AU VOIE SĂ FIE ACELAȘI (hotărârea patronului,
 * 4–5 octombrie 2026): în grupul comenzilor stă toată tura. Raportul de bani
 * trimis acolo ar fi spus fiecărui om din laborator cine cât datorează.
 */
function plati_setari_valideaza(array $in, ?string $chatComenzi = null): array
{
    $out = [];

    if (array_key_exists('pragImplicit', $in)) {
        // `null` nu e un prag general: „cel general” e chiar el.
        $out['plati_prag_implicit'] = (string) plati_prag_citit($in['pragImplicit'], 'Pragul general');
    }

    if (array_key_exists('chatId', $in)) {
        $v = $in['chatId'];
        // Numărul unui chat poate veni și ca număr JSON; încape într-un întreg
        // pe 64 de biți. Un `float` nu trece: peste 2^53 și-ar fi pierdut cifre.
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (!is_string($v)) {
            fail('Chatul raportului de bani trebuie să fie un număr de chat Telegram ori gol.', 422, 'bad_chat');
        }
        $v = trim($v);
        // Prima cifră NU poate fi zero. Foaia rundei scria `^-?\d{5,20}$`; cu
        // ea, „-01001234567890” era un chat bun și, ca text, ALTUL decât
        // „-1001234567890” al comenzilor — pe când Telegram le citește pe
        // amândouă ca același număr. Un număr de chat adevărat nu începe
        // niciodată cu zero, deci forma strânsă nu refuză nimic din ce trimite
        // Telegram; în schimb, ce se scrie în tabel are o singură scriere.
        if ($v !== '' && preg_match('/^-?[1-9]\d{4,19}\z/', $v) !== 1) {
            fail(
                'Chatul raportului de bani nu arată a număr de chat Telegram: numai cifre, '
                  . 'fără zerouri în față, cu minus la grupuri (de pildă -1001234567890). '
                  . 'Gol = raportul e oprit.',
                422,
                'bad_chat'
            );
        }
        // Comparate ca NUMERE, nu ca text (`plati_chat_acelasi()`): chatul
        // comenzilor nu are nicio regulă de formă — se poate scrie și `@canal` —
        // deci în tabel poate sta cu un zero ori cu un spațiu în plus.
        if (plati_chat_acelasi($v, $chatComenzi ?? setting('notify_telegram_chat_id'))) {
            fail('Raportul de bani are chatul lui, altul decât cel al comenzilor.', 422, 'plati_chat_same');
        }
        $out['plati_telegram_chat_id'] = $v;
    }

    if (array_key_exists('ore', $in)) {
        $v = $in['ore'];
        // EXACT două locuri, pe poziții: locul întâi e raportul de dimineață,
        // al doilea cel de seară, iar fiecare își are însemnarea lui de „trimis
        // azi” (`plati_raport_trimis_1/2`). O listă de un loc ori de trei n-ar
        // mai spune care e care.
        if (!is_array($v) || array_keys($v) !== [0, 1]) {
            fail('Orele raportului sunt două locuri: fiecare o oră (HH:MM) ori gol, adică oprit.', 422, 'bad_ore');
        }
        $ore = [];
        foreach ($v as $o) {
            $o = is_string($o) ? trim($o) : null;
            if ($o === null || ($o !== '' && preg_match(GP_PLATI_ORA, $o) !== 1)) {
                fail('O oră de raport se scrie HH:MM, între 00:00 și 23:59; gol înseamnă oprit.', 422, 'bad_ore');
            }
            $ore[] = $o;
        }
        if ($ore[0] !== '' && $ore[0] === $ore[1]) {
            fail('Cele două rapoarte nu pot pleca la aceeași oră. Lasă gol locul pe care nu-l vrei.', 422, 'bad_ore');
        }
        // MEREU cu virgula la mijloc, și când amândouă sunt oprite („,”).
        // Textul gol ar fi fost citit de `setting()` ca „nimic scris”, deci ca
        // orele din pornire — și raportul oprit dinadins ar fi plecat iar.
        $out['plati_raport_ore'] = $ore[0] . ',' . $ore[1];
    }

    if (array_key_exists('amintiri', $in)) {
        $v = $in['amintiri'];
        // Din „Încasări” vine `true`/`false`; prin `PUT /settings`, unde toate
        // valorile sunt text, vine „1”/„0”. Altceva nu e un răspuns la da/nu.
        if ($v === true || $v === 1 || $v === '1') {
            $out['plati_amintiri'] = '1';
        } elseif ($v === false || $v === 0 || $v === '0') {
            $out['plati_amintiri'] = '0';
        } else {
            fail('Amintirile de scadență sunt pornite ori oprite: adevărat sau fals.', 422, 'bad_amintiri');
        }
    }

    if (array_key_exists('panelBaseUrl', $in)) {
        $v = $in['panelBaseUrl'];
        if (!is_string($v)) {
            fail('Adresa panoului trebuie să fie un text: https://gazdă ori gol.', 422, 'bad_url');
        }
        $v = trim($v);
        if ($v !== '') {
            // Numai `https://gazdă[:port]`. Butonul din Telegram lipește singur
            // calea paginii după ea, deci o cale, un `?` ori un `/` la capăt ar
            // fi dat o legătură strâmbă în fiecare raport; iar `http://` ar fi
            // dus omul, cu banii casei pe ecran, printr-o legătură necriptată.
            $eticheta = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
            $bun = strlen($v) <= 200
                && preg_match('~^https://(?i:' . $eticheta . '(?:\.' . $eticheta . ')*)(?::(\d{1,5}))?\z~', $v, $m) === 1
                && (!isset($m[1]) || ((int) $m[1] >= 1 && (int) $m[1] <= 65535));
            if (!$bun) {
                fail(
                    'Adresa panoului se scrie https://gazdă (cu :port, dacă e nevoie), fără cale, '
                      . 'fără „?” și fără „/” la capăt. De pildă: https://lab.goldenpastry.ro',
                    422,
                    'bad_url'
                );
            }
            // ACEEAȘI SITĂ CA LA TRIMITERE. Forma de mai sus primește și
            // `https://localhost`, `https://192.168.1.5`, `https://panou` — iar
            // `send_telegram()` scoate butonul cu o asemenea adresă, în tăcere
            // (Telegram ar fi refuzat tot mesajul, ori legătura n-ar fi dus
            // nicăieri de pe un telefon ieșit din laborator). Salvarea zicea
            // „bine”, iar raportul venea, zi de zi, fără buton și fără nicio
            // vorbă despre pricină. Văzut la citirea rundei, pe 9 oct. 2026.
            // Întrebată AICI, cu chiar adresa care ar ajunge pe buton, omul
            // află la salvare, nu a doua zi la 09:00. Cine vrea rapoarte fără
            // buton lasă câmpul gol.
            if (!telegram_url_de_buton($v . '/incasari')) {
                fail(
                    'Pe adresa asta raportul ar pleca fără buton: Telegram primește numai o adresă https '
                      . 'care se deschide de oriunde (nu localhost, nu o adresă din rețeaua casei, nu un nume fără punct). '
                      . 'Scrie adresa de pe internet a panoului ori lasă câmpul gol.',
                    422,
                    'bad_url'
                );
            }
        }
        $out['panel_base_url'] = $v;
    }

    return $out;
}

/**
 * Aduce setările plăților venite PE CHEI DE TABEL (`PUT /settings`) la numele
 * pe care le judecă `plati_setari_valideaza()`.
 *
 * Nu judecă nimic, dinadins: doar mută. Singura prefacere e la ore, care în
 * tabel stau ca un text cu virgulă („09:00,18:00”) și se desfac în cele două
 * locuri — un text fără virgulă dă un singur loc, iar regula celor două locuri
 * îl refuză dincolo, cu vorbele ei.
 */
function plati_setari_din_chei(array $perChei): array
{
    $in = [];
    foreach (GP_PLATI_SETARI as $camp => $cheie) {
        if (!array_key_exists($cheie, $perChei)) {
            continue;
        }
        $v = $perChei[$cheie];
        if ($camp === 'ore' && is_string($v)) {
            $v = explode(',', $v);
        }
        $in[$camp] = $v;
    }
    return $in;
}

/**
 * Cele două ore ale raportului, cum stau acum în bază: două locuri, fiecare
 * `HH:MM` ori gol (oprit).
 *
 * O singură citire pentru pagină și pentru ceasul care trimite raportul, ca
 * „oprit” să însemne același lucru în amândouă. Ce nu e o oră se citește ca
 * OPRIT, nu ca ora din pornire: un rând stricat cu mâna n-are voie să trimită
 * banii casei pe Telegram la o oră pe care n-a ales-o nimeni. Numai rândul care
 * LIPSEȘTE cu totul (o bază fără 032) cade pe orele din pornire.
 */
function plati_raport_ore(): array
{
    $brut = setting_raw('plati_raport_ore');
    if ($brut === null) {
        return ['09:00', '18:00'];
    }
    $parti = explode(',', $brut);
    $ore   = [];
    foreach ([0, 1] as $i) {
        $o     = trim((string) ($parti[$i] ?? ''));
        $ore[] = preg_match(GP_PLATI_ORA, $o) === 1 ? $o : '';
    }
    return $ore;
}

/**
 * Scrie setările plăților deja judecate (ce întoarce `plati_setari_valideaza()`)
 * și lasă urma în Jurnal. Întoarce cheile care chiar s-au schimbat.
 *
 * UȘĂ APARTE, nu `settings_save()`: aceea scrie în Jurnal fapta
 * `settings_update`, pe care o citește oricine are Jurnalul. Fapta de aici,
 * `plati_setari`, se arată numai celui cu „Încasări” (api/routes/log.php).
 *
 * SE SCRIE NUMAI CE S-A SCHIMBAT, și tot atunci se scrie în Jurnal. Pagina
 * trimite la fiecare salvare amândouă orele, iar caseta pragului pleacă și
 * neatinsă; scrise necondiționat, Jurnalul s-ar fi umplut de rânduri „2 → 2”.
 * O salvare care n-a schimbat nimic nu lasă niciun rând.
 *
 * NUMĂRUL CHATULUI NU SE SCRIE ÎN JURNAL, nici vechi, nici nou — numai că s-a
 * pus, s-a schimbat ori s-a șters. Rândul îl citește oricine are „Încasări”,
 * iar chatul e al celui cu `plati.telegram`: două drepturi deosebite.
 *
 * Totul ori nimic: cheile se scriu într-o singură tranzacție. O cheie fără rând
 * în tabel (032 oprită la mijloc) oprește cererea înainte de prima scriere.
 */
function plati_setari_scrie(array $deScris, array $u): array
{
    if (!$deScris) {
        return [];
    }
    $vechi = settings_all();
    foreach (array_keys($deScris) as $cheie) {
        if (!in_array($cheie, GP_PLATI_SETARI, true)) {
            throw new LogicException('plati_setari_scrie() nu scrie „' . $cheie . '”.');
        }
        // Salvarea scrie cu UPDATE: peste o cheie fără rând ar fi „reușit” fără
        // să scrie nimic. Coloanele pot fi de față și rândul nu — o 032 oprită
        // la mijloc — deci întrebarea se pune pe rând, nu pe `plati_supported()`.
        // Leacul e tot fișierul întreg, rulat încă o dată: rândurile lui se pun
        // cu INSERT IGNORE, deci a doua trecere le adaugă pe cele rămase pe drum
        // și nu calcă nimic din ce a apucat să fie scris.
        if (!array_key_exists($cheie, $vechi)) {
            fail('Setările plăților nu sunt întregi pe server: rulează încă o dată sql/032_plati_si_prag.sql.', 503, 'plati_unavailable');
        }
    }

    $schimbate = [];
    $vorbe     = [];
    foreach ($deScris as $cheie => $nou) {
        $nou     = (string) $nou;
        $inainte = (string) $vechi[$cheie];
        if ($nou === $inainte) {
            continue;
        }
        $schimbate[$cheie] = $nou;
        $vorbe[]           = plati_setare_vorba($cheie, $inainte, $nou);
    }
    if (!$schimbate) {
        return [];
    }

    $pdo   = db();
    $a_mea = !$pdo->inTransaction();
    if ($a_mea) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($schimbate as $cheie => $nou) {
            q(
                'UPDATE settings SET value = ?, updated_by = ? WHERE setting_key = ?',
                [$nou, (int) $u['id'], $cheie]
            );
        }
        if ($a_mea) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($a_mea && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit('plati_setari', 'settings', null, implode(' · ', $vorbe));
    // `settings_all()` ține un cache pe cerere: fără golirea lui, răspunsul de
    // după salvare ar fi fost scris din valorile de dinainte.
    settings_all(true);

    return array_keys($schimbate);
}

/** O schimbare de setare a plăților, în cuvinte, pentru Jurnal. */
function plati_setare_vorba(string $cheie, string $inainte, string $nou): string
{
    $prag = static fn (string $v): string => $v === '0' ? 'fără limită' : ($v === '' ? '—' : $v);
    $ore  = static function (string $v): string {
        $p = explode(',', $v);
        $a = trim($p[0] ?? '');
        $b = trim($p[1] ?? '');
        return ($a === '' ? 'oprit' : $a) . ' și ' . ($b === '' ? 'oprit' : $b);
    };

    switch ($cheie) {
        case 'plati_prag_implicit':
            return 'Pragul general: ' . $prag($inainte) . ' → ' . $prag($nou);
        case 'plati_telegram_chat_id':
            return 'Chatul raportului de bani: '
                . ($inainte === '' ? 'pus' : ($nou === '' ? 'șters' : 'schimbat'));
        case 'plati_raport_ore':
            return 'Orele raportului: ' . $ore($inainte) . ' → ' . $ore($nou);
        case 'plati_amintiri':
            return 'Amintirile de scadență: ' . ($nou === '1' ? 'pornite' : 'oprite');
        case 'panel_base_url':
            return 'Adresa panoului: ' . ($inainte === '' ? '—' : audit_value($inainte))
                . ' → ' . ($nou === '' ? '—' : audit_value($nou));
    }
    return $cheie;
}
