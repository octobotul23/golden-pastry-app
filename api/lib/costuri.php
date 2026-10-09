<?php
/**
 * Costul produselor — socoteala, fără nicio ieșire.
 *
 * CEREREA PATRONULUI, 4 octombrie 2026 (runda 2.7.0): să scrie cât costă făina,
 * ouăle, untul, să vadă cât îl costă fiecare produs și cât îi rămâne din el, și
 * să poată schimba prețurile când se schimbă. Prețurile stau în
 * `stock_item_prices` (migrarea 034), cu ISTORIC: trecutul rămâne cu prețul de
 * atunci.
 *
 * Aici e numai socoteala. Funcțiile nu scriu nimic, nu răspund nimănui și nu
 * întreabă de drepturi — le cheamă trei locuri care au fiecare poarta lui:
 * rutele prețurilor (api/routes/costuri.php, cheia `preturi`), rețeta unui
 * produs (api/routes/catalog.php, cheia `costuri`) și Statisticile
 * (api/routes/stats.php, cheia `costuri`). O socoteală scrisă de trei ori ar da,
 * mai devreme ori mai târziu, trei costuri pentru același croissant.
 *
 * BAZA SE CITEȘTE O DATĂ PE CERERE (`costuri_context()`): toate articolele,
 * toate rețetele, tot istoricul prețurilor. Restul e PHP. Statisticile pe trei
 * ani au zeci de mii de rânduri (produs × zi), iar o întrebare pusă bazei
 * pentru fiecare ar ține pagina în loc.
 *
 * CELE ȘASE REGULI (contractul rundei, §3) — fiecare are locul ei mai jos:
 *   1. costul unei linii = cantitatea din rețetă × prețul articolului în ziua aceea;
 *   2. un preț scris pe altă unitate decât are articolul ACUM nu se folosește;
 *   3. articolul finit fără preț propriu ia costul produsului de care e legat
 *      — și tot pe el rămâne în zilele DINAINTEA primului său preț propriu;
 *   4. o linie fără preț lasă produsul FĂRĂ cost — niciodată cu o jumătate de cost;
 *   5. se adună întreg și se rotunjește la urmă;
 *   6. un articol scos din listă, rămas în rețetă, se socotește ca oricare altul.
 */

declare(strict_types=1);

/**
 * Câte rețete, una într-alta, urmează socoteala.
 *
 * Cutia cadou are croissantul ca linie; croissantul poate avea un aluat făcut
 * în casă; aluatul, o cremă. Trei trepte acoperă tot ce se face într-o
 * patiserie, iar marginea e aici ca o rețetă legată greșit să nu poată ține
 * cererea în loc: fără ea, zece articole finite puse fiecare în rețeta
 * celorlalte ar da o socoteală care nu se mai termină în timp omenesc.
 */
const GP_COST_ADANC_MAX = 3;

/**
 * Are baza tabelul prețurilor? (migrarea 034)
 *
 * Tiparul lui `acces_supported()`. „Nu" nu strică nimic din ce mergea ieri:
 * rețeta și Statisticile răspund ca înainte, iar cele patru rute ale prețurilor
 * spun pe față că baza n-a primit actualizarea (503 `costuri_unavailable`).
 * De aceea NICIO interogare din fișierele costurilor nu atinge
 * `stock_item_prices` înainte să fi întrebat aici — pe o bază fără 034, un
 * `SELECT` de acolo ar fi „Eroare internă" pe pagini care n-au nicio vină.
 */
function costuri_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        return $ok = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_item_prices'"
        ) === 1;
    } catch (Throwable $e) {
        error_log('[gp] costuri_supported: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Are articolul vreun preț scris? Răspunde numai cu da ori nu.
 *
 * E singura întrebare despre prețuri pe care o poate pune cineva din afara
 * fișierelor costurilor și fără dreptul `preturi`: ștergerea unui articol din
 * Depozit (`route_items_delete()`, cheia `depozit`). Fără ea, omul de la
 * depozit ștergea un articol, iar prețurile scrise de fondator plecau odată cu
 * el, prin cascada cheii străine, fără niciun rând în Jurnal.
 *
 * Întoarce `bool` DINADINS: prin el nu poate trece o cifră, oricine ar chema
 * funcția. Iar numele tabelului rămâne aici, în fișierele costurilor — Depozitul
 * nu-l scrie nicăieri, deci nicio interogare de-a lui nu poate căra un preț.
 * Că un articol ARE prețuri nu e o taină (Jurnalul o spune oricui îl deschide);
 * taină e cât.
 *
 * Pe o bază fără migrarea 034 răspunsul e „nu", fără nicio interogare.
 */
function costuri_are_preturi(int $itemId): bool
{
    if (!costuri_supported()) {
        return false;
    }
    return (int) scalar('SELECT COUNT(*) FROM stock_item_prices WHERE item_id = ?', [$itemId]) > 0;
}

/**
 * Tot istoricul prețurilor, o dată pe cerere.
 *
 * [itemId => [['price' => float, 'unit' => string, 'validFrom' => 'A-L-Z', 'id' => int], …]]
 * — fiecare articol cu prețurile lui în ordinea zilelor, cel mai vechi întâi
 * (`pret_la()` se sprijină pe ordinea asta).
 *
 * CITIREA NU STĂ ÎN try/catch, dinadins. Dacă tabelul există și nu se poate
 * citi, a răspunde „niciun preț" ar preface tăcut fiecare cost în „nu se poate
 * socoti" — iar patronul ar căuta o zi întreagă prețul pe care l-a scris ieri.
 * Eroarea urcă până la router; o cerere căzută e mai cinstită decât una care
 * minte liniștit.
 *
 * `price` iese `float`, cum cere contractul. Nu se pierde nimic: coloana are
 * patru zecimale, iar un `float` ține cincisprezece cifre; socoteala îl trece
 * oricum înapoi în întregi înainte să înmulțească (v. `costuri_intreg()`).
 */
function costuri_istoric(): array
{
    static $ist = null;
    if ($ist !== null) {
        return $ist;
    }
    $ist = [];
    if (!costuri_supported()) {
        return $ist;
    }
    foreach (all(
        'SELECT id, item_id, price, unit, valid_from
           FROM stock_item_prices
          ORDER BY item_id, valid_from, id'
    ) as $r) {
        $ist[(int) $r['item_id']][] = [
            'price'     => (float) $r['price'],
            'unit'      => (string) $r['unit'],
            'validFrom' => (string) $r['valid_from'],
            'id'        => (int) $r['id'],
        ];
    }
    return $ist;
}

/**
 * Prețul unui articol într-o zi.
 *
 * `null` când articolul n-are NICIUN preț. Altfel ultimul rând cu
 * `validFrom <= $zi`; iar dacă toate sunt DUPĂ ziua cerută, cel mai vechi, cu
 * `estimat => true`.
 *
 * DE CE „CEL MAI VECHI” ȘI NU „LIPSĂ” (hotărârea patronului, 4 oct. 2026).
 * Prețurile încep să fie scrise în octombrie 2026, iar comenzile sunt în bază
 * de ani. Cu „lipsă” înaintea primului preț, Statisticile lunii trecute ar fi
 * rămas goale pentru totdeauna — nimeni nu scrie înapoi prețul făinii din
 * martie. Cel mai vechi preț cunoscut e cea mai bună părere pe care o avem
 * despre ce a fost înaintea lui, iar cifra poartă semnul „≈” ca să nu treacă
 * drept socoteală.
 *
 * Capcana zilei de AZI: un preț „anunțat” (scris cu ziua de poimâine) pe un
 * articol fără alt preț e tot „dinaintea primului preț” — azi e înaintea lui.
 * Deci și rețeta deschisă azi îl arată cu „≈”, nu numai Statisticile vechi.
 *
 * Zilele se compară ca text: „A-L-Z” cu zerouri se ordonează ca zilele însele.
 */
function pret_la(array $istoricArticol, string $zi): ?array
{
    if (!$istoricArticol) {
        return null;
    }
    $ales = null;
    foreach ($istoricArticol as $p) {
        if ((string) $p['validFrom'] > $zi) {
            break;
        }
        $ales = $p;
    }
    $estimat = false;
    if ($ales === null) {
        $ales    = reset($istoricArticol);
        $estimat = true;
    }
    return [
        'price'     => (float) $ales['price'],
        'unit'      => (string) $ales['unit'],
        'validFrom' => (string) $ales['validFrom'],
        'estimat'   => $estimat,
    ];
}

/**
 * Contextul unei socoteli: articolele, rețetele și istoricul, citite O DATĂ.
 *
 *   'articole' => [id => ['id', 'nume', 'unit', 'category', 'active', 'productId']]
 *   'retete'   => [productId => [['itemId', 'qty'], …]]   (în ordinea din Catalog)
 *   'istoric'  => ca `costuri_istoric()`
 *
 * Trei citiri, oricâte produse s-ar socoti după aceea. Ținut minte pe cerere:
 * ruta rețetei îl cere și pentru `preturi`, și pentru `cost`.
 *
 * Rețetele sunt cele DE AZI (n-au istoric — hotărârea patronului: profitul se
 * socotește cu rețeta de azi, iar pagina o spune). La fel unitatea articolului:
 * cea de acum, fiindcă regula 2 întreabă tocmai dacă prețul mai e pe ea.
 *
 * Nicio rută nu scrie un preț și apoi socotește în aceeași cerere; dacă se va
 * face vreodată, memoria de aici trebuie golită întâi.
 */
function costuri_context(): array
{
    static $ctx = null;
    if ($ctx !== null) {
        return $ctx;
    }

    $articole = [];
    foreach (all('SELECT id, name, unit, category, active, product_id FROM stock_items') as $r) {
        $articole[(int) $r['id']] = [
            'id'        => (int) $r['id'],
            'nume'      => (string) $r['name'],
            'unit'      => (string) $r['unit'],
            'category'  => (string) $r['category'],
            'active'    => (int) $r['active'] === 1,
            'productId' => $r['product_id'] === null ? null : (int) $r['product_id'],
        ];
    }

    // Aceeași ordine a liniilor ca în `GET /products/#/recipe` (categoria, apoi
    // numele), ca `linii` să se poată pune lângă rețeta de pe ecran fără o
    // potrivire în plus.
    $retete = [];
    foreach (all(
        'SELECT r.product_id, r.item_id, r.qty
           FROM product_recipes r
           JOIN stock_items i ON i.id = r.item_id
          ORDER BY r.product_id, i.category, i.name, r.id'
    ) as $r) {
        $retete[(int) $r['product_id']][] = [
            'itemId' => (int) $r['item_id'],
            'qty'    => (float) $r['qty'],
        ];
    }

    return $ctx = [
        'articole' => $articole,
        'retete'   => $retete,
        'istoric'  => costuri_istoric(),
    ];
}

/**
 * Costul de materii prime al UNEI bucăți dintr-un produs, într-o zi.
 *
 *   'cost'  => ?float   patru zecimale; `null` când nu se poate socoti
 *   'stare' => 'ok' | 'estimat' | 'lipsa_pret' | 'fara_reteta'
 *   'linii' => [['itemId', 'nume', 'unit', 'qty', 'pret' => ?float, 'cost' => ?float,
 *                'pricina' => '' | 'fara_pret' | 'unitate_schimbata' | 'bucla',
 *                'estimat' => bool, 'derivat' => bool], …]
 *   'lipsa' => [['itemId', 'nume', 'pricina'], …]
 *
 * `lipsa` numește articolele DE LA RĂDĂCINA necazului, nu linia prin care s-a
 * ajuns la ele: dacă în cutia cadou croissantul n-are cost fiindcă ouăle n-au
 * preț, lipsa cutiei sunt ouăle — acolo are patronul de scris, nu la croissant.
 */
function cost_produs(int $productId, string $zi, array $ctx): array
{
    $memo = [];
    $r = costuri_socoteste($productId, $zi, $ctx, $memo, [], 0);
    return [
        'cost'  => $r['cost'],
        'stare' => $r['stare'],
        'linii' => $r['linii'],
        'lipsa' => $r['lipsa'],
    ];
}

/* ------------------------------------------------------- socoteala dinăuntru -- */

/**
 * Un zecimal din bază (șir, cum îl dă PDO, ori număr) ca ÎNTREG de zecimi de
 * miimi (`$scara` = 10000) ori de sutimi (100).
 *
 * BANII NU SE ADUNĂ ÎN `float`. `0.1 + 0.2` nu e `0.3`, iar o rețetă cu
 * treizeci de linii adunate așa iese cu un ban în plus ori în minus după
 * ordinea liniilor. În întregi, 0,1 kg × 10,00 lei + 2 buc × 0,50 lei e exact
 * 2,0000 — cifra din proba contractului.
 *
 * `round()` înainte de `(int)`: `0.1 * 10000` e 1000.0000000000001 ori
 * 999.9999999999999 după noroc, iar `(int)` singur taie, nu rotunjește.
 */
function costuri_intreg($v, int $scara): int
{
    return (int) round((float) $v * $scara);
}

/**
 * Împărțire cu rotunjire la cel mai apropiat întreg, jumătatea în sus (în
 * mărime) — rotunjirea pe care o face omul cu creionul.
 *
 * Primește și `float`: înmulțirea a doi întregi foarte mari iese din `int` și
 * PHP o preface singur în `float` (o cantitate de milioane la un preț de sute
 * de mii — nu e o rețetă, dar nici n-are voie să fie o eroare fatală;
 * `intdiv()` pe un `float` ar fi, sub `strict_types`). Acolo se rotunjește ca
 * `float`, cu precizia lui; în tot ce încape într-o patiserie se rămâne în
 * întregi.
 *
 * @param int|float $v
 * @return int|float
 */
function costuri_rotunjit($v, int $cat)
{
    if (is_int($v) && $v < PHP_INT_MAX - $cat && $v > PHP_INT_MIN + $cat) {
        $jum = intdiv($cat, 2);
        return $v >= 0 ? intdiv($v + $jum, $cat) : -intdiv($jum - $v, $cat);
    }
    return round($v / $cat);
}

/**
 * Prețul cu care intră UN articol într-o rețetă, într-o zi. Singurul loc în
 * care se hotărăște asta: îl cheamă și costul rețetei salvate, și lista de
 * prețuri dată formularului — deci cele două nu pot ajunge să socotească altfel.
 *
 *   'pret4'    => int|float|null   prețul în zecimi de miimi; `null` = nefolosibil
 *   'pricina'  => '' | 'fara_pret' | 'unitate_schimbata' | 'bucla'
 *   'estimat'  => bool
 *   'derivat'  => bool             vine din costul produsului legat
 *   'unit'     => string           unitatea articolului, cea de acum
 *   'unitPret' => ?string          unitatea de pe preț, când nu se mai potrivește
 *   'lipsa'    => [['itemId', 'nume', 'pricina'], …]   gol când prețul e bun
 *   'curat'    => bool             v. `costuri_socoteste()`
 *
 * `$drum` sunt produsele prin care s-a ajuns aici (rădăcina întâi), iar
 * `$adanc` câte rețete s-au deschis deja sub rădăcină.
 *
 * ORDINEA ÎNCERCĂRILOR, pe scurt:
 *   articol simplu  → prețul propriu din ziua aceea (ori cel mai vechi, cu „≈");
 *   articol finit   → prețul propriu, DACĂ a început până în ziua aceea;
 *                     altfel cel derivat; iar dacă nici acela nu iese și există
 *                     un preț propriu care începe mai târziu, acela, cu „≈".
 */
function costuri_pret_articol(int $itemId, string $zi, array $ctx, array &$memo, array $drum, int $adanc): array
{
    $art  = $ctx['articole'][$itemId] ?? null;
    $nume = $art === null ? ('#' . $itemId) : (string) $art['nume'];

    $r = [
        'pret4'    => null,
        'pricina'  => 'fara_pret',
        'estimat'  => false,
        'derivat'  => false,
        'unit'     => $art === null ? '' : (string) $art['unit'],
        'unitPret' => null,
        'lipsa'    => [['itemId' => $itemId, 'nume' => $nume, 'pricina' => 'fara_pret']],
        'curat'    => true,
    ];
    if ($art === null) {
        // Nu se întâmplă cât ține cheia străină a rețetei (`fk_recipe_item`,
        // RESTRICT); dacă s-ar întâmpla, e o lipsă, nu un zero.
        return $r;
    }

    $ist = $ctx['istoric'][$itemId] ?? [];
    $p   = $ist ? pret_la($ist, $zi) : null;
    $sub = $art['productId'];

    // PREȚUL PROPRIU CÂȘTIGĂ, și pe un articol finit: e marfă cumpărată gata
    // făcută, iar costul ei e factura, nu rețeta.
    //
    // DAR NUMAI DIN ZIUA LUI DE ÎNCEPUT. Pe un articol FINIT, ziua dinaintea
    // primului preț propriu nu e o zi „fără preț": atunci produsul se făcea în
    // casă, iar prețul lui era costul rețetei. `pret_la()` întoarce și pentru
    // ziua aceea ceva — „cel mai vechi preț, cu ≈", regula bună pentru făina
    // din martie, care altă cifră n-are. Pusă înaintea prețului derivat, făcea
    // ca un preț propriu scris azi pe croasantul finit să rescrie costul cutiei
    // din toate lunile trecute (profitul lor se schimba, cu „≈" în față), iar
    // unul abia ANUNȚAT, pentru luna viitoare, să mute costul de azi. De aceea
    // prețul propriu „estimat" al unui articol finit e numai a doua încercare:
    // se ia când cel derivat nu iese (v. la coada funcției).
    if ($p !== null && ($sub === null || !$p['estimat'])) {
        return costuri_pret_propriu($r, $p, $itemId, $nume);
    }
    if ($sub === null) {
        return $r;
    }

    // De aici: articol finit care, în ziua cerută, n-are preț propriu — ori
    // n-are niciunul, ori ziua e înaintea primului.
    $d = costuri_pret_derivat($r, (int) $sub, $itemId, $nume, $zi, $ctx, $memo, $drum, $adanc);
    if ($d['pret4'] !== null || $p === null) {
        return $d;
    }

    // Prețul derivat nu iese (produsul legat n-are rețetă, îi lipsește un preț,
    // rețetele se mușcă de coadă ori e prea adânc), dar articolul are un preț
    // propriu care începe mai târziu. Atunci rămâne hotărârea patronului din
    // 4 octombrie 2026: înaintea primului preț se ia cel mai vechi cunoscut, cu
    // „≈" — mai bine o părere însemnată ca părere decât un rând gol.
    //
    // Calea e cea a prețului propriu ÎNTREAGĂ, cu tot cu regula 2: dacă prețul
    // acela a rămas pe altă unitate, articolul spune `unitate_schimbata`, la
    // fel ca rândul lui din fila Prețuri.
    //
    // „Curat" rămâne ce a ieșit din încercarea de sus: dacă s-a ajuns aici
    // fiindcă drumul a dat de o buclă ori de marginea de adâncime, din alt
    // produs același articol ar fi putut avea preț derivat, deci răspunsul ține
    // de drum și nu se ține minte.
    $o = costuri_pret_propriu($r, $p, $itemId, $nume);
    $o['curat'] = $d['curat'];
    return $o;
}

/**
 * Prețul PROPRIU al unui articol, pus în răspunsul lui `costuri_pret_articol()`.
 * `$r` e răspunsul gol de acolo, `$p` rândul ales de `pret_la()`.
 */
function costuri_pret_propriu(array $r, array $p, int $itemId, string $nume): array
{
    if ($p['unit'] !== $r['unit']) {
        // REGULA 2. Articolul a fost trecut din kg în g: prețul de pe rând
        // e „3,45 lei" pe o unitate de o mie de ori mai mare decât cea în
        // care e scrisă acum rețeta. Înmulțit pe tăcute, fiecare cost ar
        // sări de o mie de ori — și ar arăta ca o cifră adevărată. Se
        // spune, și se așteaptă un preț scris pe unitatea de acum.
        //
        // Nici nu se cade de aici pe prețul derivat al unui articol finit:
        // omul a scris un preț pe articolul ăsta, deci asta vrea să fie
        // folosit; a-l înlocui în liniște cu altă cifră ar ascunde tocmai
        // nepotrivirea pe care trebuie s-o vadă.
        $r['pricina']  = 'unitate_schimbata';
        $r['unitPret'] = $p['unit'];
        $r['lipsa']    = [['itemId' => $itemId, 'nume' => $nume, 'pricina' => 'unitate_schimbata']];
        return $r;
    }
    $r['pret4']   = costuri_intreg($p['price'], 10000);
    $r['pricina'] = '';
    $r['estimat'] = $p['estimat'];
    $r['lipsa']   = [];
    return $r;
}

/**
 * Prețul DERIVAT al unui articol finit: costul produsului de care e legat
 * (`$sub`), pus în răspunsul lui `costuri_pret_articol()`. Restul parametrilor
 * sunt ai ei.
 */
function costuri_pret_derivat(array $r, int $sub, int $itemId, string $nume, string $zi, array $ctx, array &$memo, array $drum, int $adanc): array
{
    // REGULA 3. Articol finit fără preț propriu: prețul lui e costul produsului
    // de care e legat. Fără asta patronul ar fi trebuit să scrie cu mâna „cât
    // costă croissantul" ca să afle cât costă cutia — adică tocmai cifra pe
    // care o cere de la aplicație.
    $r['derivat'] = true;

    if (in_array($sub, $drum, true)) {
        // Cutia are croissantul, croissantul are cutia. Fără paza asta
        // socoteala s-ar învârti până la marginea de adâncime și ar întoarce o
        // „lipsă" fără nume; cu ea, omul află că rețetele se mușcă de coadă.
        // Rezultatul ține de DRUM (din alt produs, același articol poate avea
        // cost), deci nu e „curat" și nu se ține minte.
        $r['pricina'] = 'bucla';
        $r['lipsa']   = [['itemId' => $itemId, 'nume' => $nume, 'pricina' => 'bucla']];
        $r['curat']   = false;
        return $r;
    }
    if ($adanc >= GP_COST_ADANC_MAX) {
        // Prea adânc (v. GP_COST_ADANC_MAX): articolul rămâne fără preț, și se
        // poate drege oricând scriindu-i unul propriu. Nici ăsta nu e „curat":
        // de mai sus, același articol ar fi avut loc să-și deschidă rețeta.
        $r['curat'] = false;
        return $r;
    }

    $s = costuri_socoteste($sub, $zi, $ctx, $memo, $drum, $adanc + 1);
    $r['curat'] = $s['curat'];

    if ($s['cost4'] !== null) {
        // Prețul derivat e costul ROTUNJIT la patru zecimale al produsului
        // legat — aceeași cifră pe care o vede omul la produsul acela. Așa
        // „preț × cantitate" de pe linie se poate verifica cu creionul.
        $r['pret4']   = $s['cost4'];
        $r['pricina'] = '';
        $r['estimat'] = $s['stare'] === 'estimat';
        $r['lipsa']   = [];
        return $r;
    }
    if ($s['stare'] === 'fara_reteta') {
        // Produsul legat n-are rețetă: de dres e chiar articolul ăsta (un preț
        // al lui, ori o rețetă produsului său). `lipsa` rămâne cea de sus.
        return $r;
    }
    // Produsul legat are rețetă, dar îi lipsește ceva: lipsa lui e și a noastră.
    $r['lipsa'] = $s['lipsa'];
    foreach ($s['lipsa'] as $l) {
        if ($l['pricina'] === 'bucla') {
            $r['pricina'] = 'bucla';
            break;
        }
    }
    return $r;
}

/**
 * Costul unui produs, cu drumul pe care s-a ajuns la el.
 *
 * Pe lângă cheile lui `cost_produs()` mai întoarce `cost4` (costul în zecimi de
 * miimi, întreg) și `curat`.
 *
 * `$memo` ține rezultatele deja socotite în ACEEAȘI zi, pe (produs, adâncime):
 * o cutie cu șase feluri de prăjituri care au toate aceeași cremă nu socotește
 * crema de șase ori. Se țin minte NUMAI rezultatele „curate" — cele în care
 * socoteala n-a dat nici de o buclă, nici de marginea de adâncime. Acelea nu
 * depind de drum: un produs din care s-a coborât până la capăt fără nicio
 * repetare nu stă pe niciun cerc, deci va ieși la fel oricum s-ar ajunge la el.
 * Un rezultat cu `bucla` e adevărat numai pentru drumul lui, iar ținut minte
 * ar fi dat altui drum un răspuns care nu e al lui.
 */
function costuri_socoteste(int $pid, string $zi, array $ctx, array &$memo, array $drum, int $adanc): array
{
    $cheie = $pid . '|' . $adanc;
    if (isset($memo[$cheie])) {
        return $memo[$cheie];
    }

    $reteta = $ctx['retete'][$pid] ?? [];
    if (!$reteta) {
        // REGULA 4, prima stare. Aici ajunge și produsul șters din catalog:
        // rețeta lui s-a dus odată cu el (`fk_recipe_product`, CASCADE).
        return [
            'cost'  => null, 'cost4' => null, 'stare' => 'fara_reteta',
            'linii' => [], 'lipsa' => [], 'curat' => true,
        ];
    }

    $drum[] = $pid;

    $suma8   = 0;       // lei × 10^8: cantitate (×10^4) ori preț (×10^4)
    $estimat = false;
    $curat   = true;
    $lipsa   = [];
    $linii   = [];

    foreach ($reteta as $l) {
        $itemId = (int) $l['itemId'];
        $p      = costuri_pret_articol($itemId, $zi, $ctx, $memo, $drum, $adanc);
        $curat  = $curat && $p['curat'];

        // REGULA 1, în întregi. REGULA 6 e tocmai ce NU se face aici: nicio
        // cernere după `active` — consumul unui articol scos din listă a fost
        // adevărat cât a stat în rețetă.
        $qty4  = costuri_intreg($l['qty'], 10000);
        $cost8 = $p['pret4'] === null ? null : $qty4 * $p['pret4'];

        $linii[] = [
            'itemId'  => $itemId,
            'nume'    => (string) ($ctx['articole'][$itemId]['nume'] ?? ('#' . $itemId)),
            'unit'    => $p['unit'],
            'qty'     => $qty4 / 10000,
            'pret'    => $p['pret4'] === null ? null : $p['pret4'] / 10000,
            'cost'    => $cost8 === null ? null : costuri_rotunjit($cost8, 10000) / 10000,
            'pricina' => $p['pricina'],
            'estimat' => $p['estimat'],
            'derivat' => $p['derivat'],
        ];

        if ($cost8 === null) {
            foreach ($p['lipsa'] as $x) {
                // Un articol o singură dată, oricâte linii ar duce la el.
                $lipsa[(int) $x['itemId']] ??= $x;
            }
            continue;
        }
        // REGULA 5: se adună ÎNTREG; rotunjirea vine o singură dată, la urmă.
        $suma8  += $cost8;
        $estimat = $estimat || $p['estimat'];
    }

    if ($lipsa) {
        // REGULA 4, a doua stare — și miezul ei: `null`, NU suma liniilor care
        // au preț. Un croissant socotit fără unt ar ieși ieftin, profitul lui
        // mare, iar cifra ar arăta la fel de adevărată ca oricare alta. Mai
        // bine „nu se poate socoti" decât un profit mincinos.
        $r = [
            'cost'  => null, 'cost4' => null, 'stare' => 'lipsa_pret',
            'linii' => $linii, 'lipsa' => array_values($lipsa), 'curat' => $curat,
        ];
    } else {
        $cost4 = costuri_rotunjit($suma8, 10000);
        $r = [
            'cost'  => $cost4 / 10000, 'cost4' => $cost4,
            'stare' => $estimat ? 'estimat' : 'ok',
            'linii' => $linii, 'lipsa' => [], 'curat' => $curat,
        ];
    }

    if ($curat) {
        $memo[$cheie] = $r;
    }
    return $r;
}

/* ------------------------------------------------------ pentru formularul rețetei -- */

/**
 * Prețurile de AZI ale articolelor pe care formularul rețetei le poate pune pe
 * o linie: toate cele active (aceeași mulțime ca `GET /stock/items`) plus cele
 * din rețeta produsului, care pot fi și scoase din listă.
 *
 *   [itemId => ['price' => float, 'unit' => string, 'estimat' => bool, 'derivat' => bool]]
 *   [itemId => ['pricina' => 'fara_pret' | 'bucla']]
 *   [itemId => ['pricina' => 'unitate_schimbata', 'unitPret' => string]]
 *
 * Se socotește „ca din rețeta produsului `$pid`" (drumul pornește de la el, cu
 * o treaptă deschisă). De aceea costul pe care îl adună pagina din lista asta
 * pentru rețeta de pe ecran iese LA FEL cu `cost_produs()` pe aceeași rețetă
 * salvată: aceeași funcție, același drum, aceeași adâncime. Și tot de aceea
 * articolul finit al produsului însuși vine cu `bucla` — pus în rețeta lui,
 * produsul s-ar avea pe el ca ingredient.
 */
function costuri_preturi_formular(int $pid, string $zi, array $ctx): array
{
    $iduri = [];
    foreach ($ctx['articole'] as $id => $a) {
        if ($a['active']) {
            $iduri[(int) $id] = true;
        }
    }
    foreach ($ctx['retete'][$pid] ?? [] as $l) {
        $iduri[(int) $l['itemId']] = true;
    }

    $memo = [];
    $out  = [];
    foreach (array_keys($iduri) as $id) {
        $p = costuri_pret_articol((int) $id, $zi, $ctx, $memo, [$pid], 0);
        if ($p['pret4'] !== null) {
            $out[$id] = [
                'price'   => $p['pret4'] / 10000,
                'unit'    => $p['unit'],
                'estimat' => $p['estimat'],
                'derivat' => $p['derivat'],
            ];
        } elseif ($p['pricina'] === 'unitate_schimbata') {
            $out[$id] = ['pricina' => 'unitate_schimbata', 'unitPret' => (string) $p['unitPret']];
        } else {
            $out[$id] = ['pricina' => $p['pricina']];
        }
    }
    return $out;
}

/* ------------------------------------------------------------ pentru fila Prețuri -- */

/**
 * Articolele care țin în loc, într-o zi, costul a măcar unui produs — lista
 * roșie din fila Prețuri.
 *
 *   [itemId => ['itemId' => int, 'nume' => string,
 *               'pricina' => 'fara_pret' | 'unitate_schimbata' | 'bucla',
 *               'produse' => [productId, …]]]
 *
 * E `lipsa` lui `costuri_socoteste()`, adunată peste TOATE produsele cu rețetă.
 * Nimic altceva: fila Prețuri, rețeta din Catalog și Statisticile numesc așa
 * aceleași articole, cu aceeași pricină, fiindcă le numește aceeași funcție.
 * (O listă făcută cu regula ei tăcea despre articolele finite care țin un cost
 * în loc — v. `route_prices_list()`.)
 *
 * `produse` sunt produsele al căror cost NU iese din cauza articolului — și
 * cele la care se ajunge prin altă rețetă: untul fără preț ține și cozonacul,
 * și cutia cu șase cozonaci. Ruta le pune numele.
 *
 * O SINGURĂ MEMORIE PENTRU TOATE PRODUSELE. Ziua e aceeași, iar `$memo` ține
 * numai rezultatele „curate", care nu depind de drumul pe care s-a ajuns la ele
 * (v. `costuri_socoteste()`): crema socotită pentru primul produs e bună și
 * pentru al doilea. Fără ea, fiecare rețetă s-ar fi socotit o dată pentru ea și
 * încă o dată pentru fiecare cutie în care stă.
 *
 * Pricina unui articol finit poate ține de drum: dintr-un produs e `bucla`,
 * din altul, de prea adânc, `fara_pret`. Rândul din listă e unul singur, și
 * atunci spune `bucla` — rețetele care se mușcă de coadă nu se dreg coborând
 * mai puțin.
 */
function costuri_lipsuri(string $zi, array $ctx): array
{
    $memo = [];
    $out  = [];
    foreach (array_keys($ctx['retete']) as $pid) {
        $r = costuri_socoteste((int) $pid, $zi, $ctx, $memo, [], 0);
        foreach ($r['lipsa'] as $l) {
            $id = (int) $l['itemId'];
            if (!isset($out[$id])) {
                $out[$id] = [
                    'itemId'  => $id,
                    'nume'    => (string) $l['nume'],
                    'pricina' => (string) $l['pricina'],
                    'produse' => [],
                ];
            } elseif ($l['pricina'] === 'bucla') {
                $out[$id]['pricina'] = 'bucla';
            }
            $out[$id]['produse'][] = (int) $pid;
        }
    }
    return $out;
}

/* --------------------------------------------------------------- pentru Statistici -- */

/**
 * Zilele în care costul unui produs SE POATE schimba: zilele de început ale
 * tuturor prețurilor articolelor la care se ajunge din rețeta lui.
 *
 * Între două asemenea zile costul stă pe loc — rețeta e cea de azi, unitățile
 * sunt cele de azi, deci numai un preț nou îl poate muta. Statisticile țin
 * minte costul pe (produs, felia dintre două praguri) și socotesc o rețetă de
 * câteva ori, nu o dată pentru fiecare zi din trei ani.
 *
 * Și PRIMA zi a unui preț e prag, deși prețul e același de o parte și de alta
 * a ei: înainte e „estimat", după e „ok", iar starea face parte din răspuns.
 *
 * Se coboară prin ORICE articol finit, și prin cele cu preț propriu. Înaintea
 * primului lor preț socoteala chiar coboară (acolo articolul stă pe prețul
 * derivat — v. `costuri_pret_articol()`), deci pragurile de dedesubt sunt ale
 * produsului; iar ziua de început a prețului propriu, pragul la care se trece
 * de pe unul pe celălalt, e luată odată cu istoricul articolului. După ziua
 * aceea socoteala nu mai coboară, și pragurile de dedesubt sunt de prisos — dar
 * un prag în plus taie o felie în două felii la fel, adică o socoteală de
 * prisos; un prag în minus ar lipi două costuri deosebite.
 * Mulțimea de produse văzute ține loc și de pază la bucle, și de margine.
 */
function costuri_praguri(int $pid, array $ctx): array
{
    $zile   = [];
    $vazute = [];
    $rand   = [$pid];
    for ($adanc = 0; $rand && $adanc <= GP_COST_ADANC_MAX; $adanc++) {
        $urmator = [];
        foreach ($rand as $p) {
            if (isset($vazute[$p])) {
                continue;
            }
            $vazute[$p] = true;
            foreach ($ctx['retete'][$p] ?? [] as $l) {
                $itemId = (int) $l['itemId'];
                foreach ($ctx['istoric'][$itemId] ?? [] as $pr) {
                    $zile[(string) $pr['validFrom']] = true;
                }
                $sub = $ctx['articole'][$itemId]['productId'] ?? null;
                if ($sub !== null) {
                    $urmator[] = (int) $sub;
                }
            }
        }
        $rand = $urmator;
    }
    $zile = array_map('strval', array_keys($zile));
    sort($zile, SORT_STRING);
    return $zile;
}

/** În a câta felie cade o zi: câte praguri sunt `<=` ea. Căutare prin înjumătățire. */
function costuri_felie(array $praguri, string $zi): int
{
    $jos = 0;
    $sus = count($praguri);
    while ($jos < $sus) {
        $mij = intdiv($jos + $sus, 2);
        if ($praguri[$mij] <= $zi) {
            $jos = $mij + 1;
        } else {
            $sus = $mij;
        }
    }
    return $jos;
}

/**
 * Cheia `costuri` din `GET /stats`, socotită din rândurile citite de rută.
 *
 * `$parti` sunt bucățile din care e făcut fiecare rând al paginii — câte una
 * pentru fiecare (produs, nume, zi):
 *   ['pid' => ?int, 'clasa' => string, 'zi' => 'A-L-Z', 'bucati' => zecimal, 'bani' => zecimal]
 * `clasa` e cheia rândului (numele de pe poziție, cum îl strânge baza), iar
 * `$randuri[clasa] = ['nume' => …, 'categorie' => …]` e cum se cheamă el pe
 * pagină. Funcția nu citește nimic din bază: tot ce-i trebuie e în `$ctx`.
 *
 * DE CE PE ZI. Costul unei poziții e cel din ZIUA ei: făina scumpită pe 15
 * intră numai în croissantele de după 15. O medie a perioadei ar fi dat aceeași
 * cifră tuturor și ar fi ascuns tocmai scumpirea pe care patronul vrea s-o vadă.
 *
 * UN RÂND FĂRĂ COST RĂMÂNE FĂRĂ COST. Dacă o singură bucată din el nu se poate
 * socoti — o zi cu prețul pe altă unitate, un al doilea produs cu același nume
 * și fără rețetă —, tot rândul are `cost: null`: alăturat de încasările
 * întregi, un cost pe jumătate ar arăta un profit pe care nu l-a făcut nimeni.
 * Iar `null` nu ajunge NICĂIERI zero: banii rândului trec la „neacoperiți", nu
 * la profit.
 */
function costuri_statistici(array $parti, array $randuri, array $ctx): array
{
    // Mai rea = cifră mai mare. `fara_reteta` bate `lipsa_pret`: la una omul
    // are de scris o rețetă întreagă, la cealaltă un preț.
    static $rang = ['ok' => 0, 'estimat' => 1, 'lipsa_pret' => 2, 'fara_reteta' => 3];

    $praguri = [];   // [pid => zilele de prag]
    $tinut   = [];   // [pid => [felie => rezultatul lui cost_produs()]]
    $adunat  = [];   // [clasa => ce s-a strâns pe rând]

    foreach ($parti as $x) {
        $clasa = (string) $x['clasa'];
        if (!isset($adunat[$clasa])) {
            $adunat[$clasa] = [
                'buc100' => 0, 'bani4' => 0, 'cost6' => 0,
                'stare'  => 'ok', 'lipsa' => [],
            ];
        }
        $a = &$adunat[$clasa];

        // `bucati` are două zecimale în bază, `bani` (bucăți × preț) patru.
        $buc100 = costuri_intreg($x['bucati'], 100);
        $a['buc100'] += $buc100;
        $a['bani4']  += costuri_intreg($x['bani'], 10000);

        if ($x['pid'] === null) {
            // Produsul nu mai e în catalog (`order_items.product_id` a rămas
            // NULL la ștergere): nu mai are nici rețetă.
            $stare = 'fara_reteta';
            $cost  = null;
            $lipsa = [];
        } else {
            $pid = (int) $x['pid'];
            if (!isset($praguri[$pid])) {
                $praguri[$pid] = costuri_praguri($pid, $ctx);
            }
            $felie = costuri_felie($praguri[$pid], (string) $x['zi']);
            if (!isset($tinut[$pid][$felie])) {
                $tinut[$pid][$felie] = cost_produs($pid, (string) $x['zi'], $ctx);
            }
            $r     = $tinut[$pid][$felie];
            $stare = $r['stare'];
            $cost  = $r['cost'];
            $lipsa = $r['lipsa'];
        }

        if ($rang[$stare] > $rang[$a['stare']]) {
            $a['stare'] = $stare;
        }
        if ($cost === null) {
            foreach ($lipsa as $l) {
                $a['lipsa'][(int) $l['itemId']] ??= $l;
            }
        } else {
            // bucăți (×100) ori cost pe bucată (×10^4) = lei × 10^6, întreg.
            $a['cost6'] += $buc100 * costuri_intreg($cost, 10000);
        }
        unset($a);
    }

    $produse = [];
    $lipsuri = [];
    $tot = ['bani4' => 0, 'acoperit4' => 0, 'cost6' => 0, 'neacoperite' => 0, 'estimat' => false];

    foreach ($adunat as $clasa => $a) {
        // `ok` și `estimat` sunt singurele stări cu cost întreg; cum starea
        // rândului e cea mai rea dintre ale bucăților lui, una fără cost îl
        // scoate cu totul de aici.
        $areCost = $rang[$a['stare']] <= $rang['estimat'];

        // ACEEAȘI expresie ca în `produse` (api/routes/stats.php):
        // `round((float) zecimal, 2)`. Împărțirea unui întreg la 10000 dă
        // același `float` ca citirea zecimalului din text, deci cifrele celor
        // două liste ies identice, nu „aproape".
        $bani   = round($a['bani4'] / 10000, 2);
        $bucati = round($a['buc100'] / 100, 2);
        $bani2  = (int) round($bani * 100);

        $rand = [
            'nume'       => (string) ($randuri[$clasa]['nume'] ?? $clasa),
            'categorie'  => (string) ($randuri[$clasa]['categorie'] ?? ''),
            'bucati'     => $bucati,
            'bani'       => $bani,
            'cost'       => null,
            'costBucata' => null,
            'profit'     => null,
            'marja'      => null,
            'stare'      => $a['stare'],
            'lipsa'      => [],
        ];

        $tot['bani4'] += $a['bani4'];

        if ($areCost) {
            // REGULA 5: abia aici se rotunjește, la bani întregi.
            $cost2 = costuri_rotunjit($a['cost6'], 10000);
            $rand['cost'] = $cost2 / 100;
            // Media perioadei. Fără bucăți nu există „pe bucată" — și nici
            // împărțire la zero.
            $rand['costBucata'] = $a['buc100'] === 0
                ? null
                : round($a['cost6'] / ($a['buc100'] * 10000), 4);
            // Profitul e diferența celor două cifre AFIȘATE, ca omul care
            // scade cu creionul „bani − cost" să dea de aceeași cifră.
            $profit2 = $bani2 - $cost2;
            $rand['profit'] = $profit2 / 100;
            // Marfă dată pe gratis: profitul există (e minus costul), dar un
            // procent din zero nu.
            $rand['marja'] = $bani2 > 0 ? round($profit2 / $bani2 * 100, 1) : null;

            $tot['acoperit4'] += $a['bani4'];
            $tot['cost6']     += $a['cost6'];
            $tot['estimat']    = $tot['estimat'] || $a['stare'] === 'estimat';
        } else {
            $tot['neacoperite']++;
            foreach ($a['lipsa'] as $id => $l) {
                $rand['lipsa'][] = (string) $l['nume'];
                $lipsuri[(int) $id] ??= [
                    'itemId'  => (int) $l['itemId'],
                    'nume'    => (string) $l['nume'],
                    'pricina' => (string) $l['pricina'],
                ];
            }
        }
        $produse[] = $rand;
    }

    // După profit, cel mai mare întâi; rândurile fără cost la urmă, cele cu
    // mai mulți bani nesocotiți în frunte — acolo are omul cel mai mult de
    // câștigat scriind un preț. Numele hotărăște la egalitate, ca ordinea să
    // nu joace de la o cerere la alta.
    usort($produse, static function (array $a, array $b): int {
        if (($a['cost'] === null) !== ($b['cost'] === null)) {
            return $a['cost'] === null ? 1 : -1;
        }
        if ($a['cost'] !== null && $a['profit'] !== $b['profit']) {
            return $b['profit'] <=> $a['profit'];
        }
        return [$b['bani'], $a['nume']] <=> [$a['bani'], $b['nume']];
    });

    $lipsuri = array_values($lipsuri);
    usort($lipsuri, static fn(array $a, array $b): int => [$a['nume'], $a['itemId']] <=> [$b['nume'], $b['itemId']]);

    $bani      = round($tot['bani4'] / 10000, 2);
    $acoperiti = round($tot['acoperit4'] / 10000, 2);
    $bani2     = (int) round($bani * 100);
    $acop2     = (int) round($acoperiti * 100);
    // TOTALUL SE ROTUNJEȘTE O SINGURĂ DATĂ, din suma ÎNTREAGĂ a costurilor — nu
    // se adună rândurile gata rotunjite. E regula 5 dusă până la capăt: trei
    // rânduri de câte 12,345 lei fac 37,035, adică 37,04; adunate după
    // rotunjire (12,35 de trei ori) ar face 37,05, un ban care nu e al nimănui.
    // Urmarea, știută și lăsată așa: totalul poate sta la cel mult o jumătate
    // de ban pe rând de suma rândurilor de pe ecran — la fel ca `rezumat.bani`
    // față de `produse[].bani`, de când există pagina. Pe total, ca pe fiecare
    // rând, se ține la ban ce vede omul: profit = bani acoperiți − cost.
    $cost2     = costuri_rotunjit($tot['cost6'], 10000);
    $profit2   = $acop2 - $cost2;

    return [
        'activ'       => true,
        // Hotărârea patronului: rețetele n-au istoric, deci profitul lunii
        // trecute e socotit cu rețeta de AZI. Pagina o spune pe față.
        'retetaDeAzi' => true,
        'total'       => [
            'bani'               => $bani,
            'baniAcoperiti'      => $acoperiti,
            'cost'               => $cost2 / 100,
            'profit'             => $profit2 / 100,
            'marja'              => $acop2 > 0 ? round($profit2 / $acop2 * 100, 1) : null,
            // Ce rămâne din `bani` după cei acoperiți — scăzut, nu adunat
            // aparte, ca cele trei cifre să se lege pe ecran la ban.
            'baniNeacoperiti'    => ($bani2 - $acop2) / 100,
            'produseNeacoperite' => $tot['neacoperite'],
            'estimat'            => $tot['estimat'],
        ],
        'produse'     => $produse,
        'lipsuri'     => $lipsuri,
    ];
}
