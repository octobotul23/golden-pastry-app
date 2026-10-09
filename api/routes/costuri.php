<?php
/**
 * Prețurile ingredientelor — cele patru rute, toate pe cheia `preturi`.
 *
 * CEREREA PATRONULUI, 4 octombrie 2026 (runda 2.7.0): să scrie cât costă făina,
 * ouăle, untul, și să poată schimba prețurile când se schimbă.
 *
 *   GET    /stock/prices            toate articolele, fiecare cu prețul de azi
 *   GET    /stock/items/#/prices    istoricul unui articol
 *   POST   /stock/items/#/prices    un preț nou (ori corectura celui din aceeași zi)
 *   DELETE /stock/prices/#          șterge UN rând de preț
 *
 * TREI HOTĂRÂRI CARE SE VĂD ÎN FIECARE RUTĂ DE MAI JOS
 *
 * 1. PREȚUL NU PLEACĂ PE ALTĂ UȘĂ. Depozitul îl deschid și angajatul, și
 *    tableta din laborator, iar prețurile sunt, din pornire, numai ale
 *    fondatorului. De aceea prețul stă în tabelul lui (`stock_item_prices`,
 *    migrarea 034) și nu e lipit nicăieri de `GET /stock/items` — acolo e un
 *    `SELECT i.*`, iar o coloană în plus ar fi plecat cu el la toată lumea.
 *    Costul socotit din prețuri iese pe alte două uși, pe cheia `costuri`
 *    (rețeta din Catalog și Statisticile); socoteala e în api/lib/costuri.php.
 *
 * 2. VALOAREA NU SE SCRIE ÎN JURNAL. Jurnalul îl deschid și cei fără dreptul
 *    `preturi`. Rămâne acolo CINE a scris, pe CE articol și din ce zi e bun
 *    prețul — destul ca să se știe ce s-a întâmplat —, nu și cât. La fel
 *    mesajele de refuz: niciunul nu repetă cifra primită.
 *
 * 3. UN PREȚ PE ZI, PE ARTICOL. A doua scriere în aceeași zi e CORECTURA
 *    primei (o cifră greșită la tastare), nu un al doilea preț: rândul se
 *    rescrie. Prețul vechi rămâne în istoric numai când cel nou începe în altă
 *    zi — și așa trecutul se socotește cu prețul de atunci.
 *
 * Fiecare rută întreabă ÎNTÂI de drept și abia apoi de tabel: cine n-are voie
 * la prețuri primește același 403 și pe o bază fără migrarea 034, deci nu află
 * nici măcar dacă prețurile există.
 */

declare(strict_types=1);

/**
 * Cel mai mare preț primit: un milion de lei pe unitate. Coloana ține mai mult
 * (DECIMAL(12,4)); marginea e pentru degetul scăpat pe tastă — „3450000" în
 * loc de „3,45" ar preface fiecare croissant într-o pagubă de milioane, iar
 * pagina de Statistici ar arăta-o liniștită.
 */
const GP_PRET_MAX = 1000000;

/**
 * Cât de departe în urmă poate începe un preț: 1100 de zile, adică exact cât
 * socotesc Statisticile dintr-o dată (`GP_STATS_ZILE_MAX`, api/routes/stats.php
 * — scris aici a doua oară fiindcă fișierul acela nu e încărcat pe rutele
 * astea). Un preț mai vechi de atât n-ar mai intra în nicio socoteală.
 */
const GP_PRET_ZILE_INAPOI = 1100;

/**
 * Cât de departe înainte: 60 de zile. Furnizorul anunță scumpirea de luna
 * viitoare și patronul o scrie de acum; dincolo de două luni nu mai e un preț
 * anunțat, e o zi greșită (anul viitor în loc de anul ăsta).
 */
const GP_PRET_ZILE_INAINTE = 60;

/**
 * Fără migrarea 034 rutele spun pe față de ce nu merg, nu „Eroare internă".
 *
 * Și spun CE fișier lipsește, nu „actualizarea rundei”: vorba asta ajunge
 * întocmai în fila „Prețuri” a Depozitului (pagina arată `e.message`), iar
 * fondatorul care o citește trebuie să aibă ce-i dicta la telefon celui care
 * ține serverul. O rundă are patru migrări; numai una dintre ele e leacul.
 */
function costuri_cere_tabelul(): void
{
    if (!costuri_supported()) {
        fail(
            'Prețurile nu sunt încă disponibile: rulează întâi sql/034_costuri.sql.',
            503,
            'costuri_unavailable'
        );
    }
}

/**
 * Un rând de preț, cum pleacă spre pagină.
 *
 * Numerele ies din PDO ca text („3.4500"); aduse la număr aici, o dată, ca
 * pagina să nu adune șiruri. `setAt` e clipa în UTC, ca toate clipele casei —
 * pagina o trece în ora patiseriei. `setBy` e numele de pe cont, ori `null`
 * dacă între timp contul a fost șters (`fk_item_prices_user`, SET NULL).
 */
function pret_public(array $r): array
{
    return [
        'id'        => (int) $r['id'],
        'price'     => (float) $r['price'],
        'unit'      => (string) $r['unit'],
        'validFrom' => (string) $r['valid_from'],
        'note'      => $r['note'] === null ? null : (string) $r['note'],
        'setAt'     => (string) $r['set_at'],
        'setBy'     => $r['set_by_name'] === null ? null : (string) $r['set_by_name'],
    ];
}

/** Coloanele unui rând de preț, cu numele celui care l-a scris. Un singur loc. */
const GP_PRET_COLOANE = 'p.id, p.item_id, p.price, p.unit, p.valid_from, p.note, p.set_at, u.name AS set_by_name';

/**
 * GET /stock/prices — toate articolele, fiecare cu prețul lui de azi.
 *
 * `articole`: cele active, plus cele scoase din listă care stau încă într-o
 * rețetă ori au măcar un preț (consumul și istoricul lor sunt adevărate).
 * Ordinea e a Depozitului — `category, sort, name`, exact ca `GET /stock/items`
 * —, ca omul să găsească făina în același loc în amândouă filele.
 *
 * `faraPret`: ce are de scris patronul ca să-i iasă costurile — articolele care
 * țin AZI în loc costul unui produs, fiecare cu produsele pe care le ține.
 *
 * LISTA ROȘIE NU ARE O REGULĂ A EI: e chiar `lipsa` socotelii, adunată peste
 * toate produsele cu rețetă (`costuri_lipsuri()`, api/lib/costuri.php). La
 * început avea una — „articol fără niciun preț, care nu e finit" — și tăcea
 * tocmai unde socoteala se oprea altfel: la articolul finit al unui produs fără
 * rețetă, la cel de dincolo de a treia treaptă, la rețetele care se mușcă de
 * coadă. Rețeta și Statisticile numeau articolul, iar fila în care se scriu
 * prețurile nu — patronul vedea „nu se poate socoti" și o listă roșie goală.
 * Două reguli care trebuie să spună același lucru ajung să spună două; una
 * singură nu are cum.
 *
 * De aici și cele trei urmări care se văd în răspuns:
 *   – articolul finit al cărui preț derivat IESE nu e în listă, iar ce-i
 *     lipsește produsului său apare pe numele articolului de la rădăcină;
 *   – `produse` cuprinde și produsele ținute PRIN altă rețetă (untul ține și
 *     cozonacul, și cutia cu șase cozonaci), nu numai pe cele în care stă;
 *   – `pricina` poate fi și `bucla`, ca în rețetă și în Statistici.
 */
function route_prices_list(): void
{
    require_drept('preturi');
    costuri_cere_tabelul();

    $azi = today_local();

    // Trei citiri aici și trei în `costuri_context()`, oricâte articole ar fi.
    // Fără subinterogări pe rând: lista are peste o mie de articole.
    $istoric = [];
    foreach (all(
        'SELECT ' . GP_PRET_COLOANE . '
           FROM stock_item_prices p
           LEFT JOIN users u ON u.id = p.set_by
          ORDER BY p.item_id, p.valid_from, p.id'
    ) as $r) {
        $istoric[(int) $r['item_id']][] = $r;
    }

    // În câte rețete stă fiecare articol (`inRetete`) și, din aceeași citire,
    // numele produselor cu rețetă în ordinea în care le arată pagina (numele,
    // apoi id-ul): `$loc[produs]` e locul lui în ordinea aceea.
    $inRetete   = [];
    $numeProdus = [];
    $loc        = [];
    foreach (all(
        'SELECT r.item_id, p.id AS product_id, p.name
           FROM product_recipes r
           JOIN products p ON p.id = r.product_id
          ORDER BY p.name, p.id'
    ) as $r) {
        $pid = (int) $r['product_id'];
        $inRetete[(int) $r['item_id']] = ($inRetete[(int) $r['item_id']] ?? 0) + 1;
        $numeProdus[$pid] = (string) $r['name'];
        $loc[$pid]      ??= count($loc);
    }

    // Ce ține AZI un cost în loc, spus de socoteală (v. mai sus).
    $lipsuri = costuri_lipsuri($azi, costuri_context());

    $articole = [];
    $faraPret = [];

    foreach (all(
        'SELECT i.id, i.name, i.unit, i.category, i.active, i.product_id
           FROM stock_items i
          ORDER BY i.category, i.sort, i.name'
    ) as $i) {
        $id    = (int) $i['id'];
        $ist   = $istoric[$id] ?? [];
        $cate  = $inRetete[$id] ?? 0;
        $activ = (int) $i['active'] === 1;

        if (!$activ && $cate === 0 && !$ist) {
            continue;
        }

        // Prețul valabil AZI: ultimul care a început deja. Istoricul vine în
        // ordinea zilelor, deci primul rând „de mâine" oprește căutarea.
        $k = -1;
        foreach ($ist as $n => $p) {
            if ((string) $p['valid_from'] > $azi) {
                break;
            }
            $k = $n;
        }
        $pret     = $k >= 0 ? $ist[$k] : null;
        $urmator  = $ist[$k + 1] ?? null;
        $anterior = $k >= 1 ? $ist[$k - 1] : null;

        // Cu cât s-a mișcat prețul față de cel dinainte. `null`, nu `0`, când
        // n-are cu ce se compara: „+100%" față de un articol primit pe gratis
        // ori „−99,9%" între un preț pe kg și unul pe g sunt minciuni cu cifre.
        $schimbare = null;
        if ($pret !== null && $anterior !== null
            && (float) $anterior['price'] > 0
            && (string) $anterior['unit'] === (string) $pret['unit']) {
            $schimbare = round(
                ((float) $pret['price'] - (float) $anterior['price']) / (float) $anterior['price'] * 100,
                1
            );
        }

        // Prețul cu care se SOCOTEȘTE azi: cel valabil, iar în lipsa lui cel
        // mai vechi care urmează (regula „dinaintea primului preț" din
        // `pret_la()`). Pe el se întreabă dacă unitatea mai e cea a articolului.
        //
        // Pe un articol FINIT cu preț numai anunțat, costul de azi e încă cel
        // derivat, nu prețul acesta. Semnul rămâne totuși pe rând: prețul
        // anunțat pe altă unitate va opri socoteala din ziua lui de început, și
        // e mai bine văzut de acum. În lista roșie articolul intră abia când
        // chiar ține un cost în loc — asta o hotărăște socoteala, nu rândul.
        $folosit = $pret ?? $urmator;
        $unitateSchimbata = $folosit !== null && (string) $folosit['unit'] !== (string) $i['unit'];

        $articole[] = [
            'itemId'   => $id,
            'nume'     => (string) $i['name'],
            'unit'     => (string) $i['unit'],
            'category' => (string) $i['category'],
            'active'   => $activ,
            'finit'    => $i['product_id'] !== null,
            'inRetete' => $cate,
            'pret'     => $pret === null ? null : pret_public($pret),
            'urmator'  => $urmator === null ? null : pret_public($urmator),
            'anterior' => $anterior === null ? null : [
                'price'     => (float) $anterior['price'],
                'validFrom' => (string) $anterior['valid_from'],
            ],
            'schimbare'        => $schimbare,
            'unitateSchimbata' => $unitateSchimbata,
            'istoric'          => count($ist),
        ];

        if (!isset($lipsuri[$id])) {
            continue;
        }
        // Lista roșie se umple din mers, deci vine în ordinea articolelor de
        // mai sus — a Depozitului. Produsele ținute, în ordinea numelui.
        $tinute = [];
        foreach ($lipsuri[$id]['produse'] as $pid) {
            // Un produs căruia i s-a scris rețeta între cele două citiri n-are
            // încă nume aici; apare la următoarea deschidere a filei.
            if (isset($loc[$pid])) {
                $tinute[$loc[$pid]] = $numeProdus[$pid];
            }
        }
        ksort($tinute);
        $faraPret[] = [
            'itemId'  => $id,
            'nume'    => (string) $i['name'],
            'pricina' => $lipsuri[$id]['pricina'],
            'produse' => array_values($tinute),
        ];
    }

    json_out([
        'activ'    => true,
        'azi'      => $azi,
        'articole' => $articole,
        'faraPret' => $faraPret,
    ]);
}

/** GET /stock/items/{id}/prices — istoricul unui articol, cel mai nou întâi. */
function route_item_prices_list(string $id): void
{
    require_drept('preturi');
    costuri_cere_tabelul();
    $id = (int) $id;

    $item = one('SELECT id, name, unit, active FROM stock_items WHERE id = ?', [$id]);
    if (!$item) {
        fail('Articolul nu există.', 404, 'not_found');
    }

    $rows = all(
        'SELECT ' . GP_PRET_COLOANE . '
           FROM stock_item_prices p
           LEFT JOIN users u ON u.id = p.set_by
          WHERE p.item_id = ?
          ORDER BY p.valid_from DESC, p.id DESC',
        [$id]
    );

    json_out([
        'item' => [
            'id'     => $id,
            'nume'   => (string) $item['name'],
            'unit'   => (string) $item['unit'],
            'active' => (int) $item['active'] === 1,
        ],
        // `unitateSchimbata` pe FIECARE rând: după ce articolul a trecut din
        // kg în g, omul trebuie să vadă care prețuri au rămas pe unitatea
        // veche (nu se mai folosesc) și care sunt scrise pe cea nouă.
        'preturi' => array_map(static fn(array $r): array => pret_public($r) + [
            'unitateSchimbata' => (string) $r['unit'] !== (string) $item['unit'],
        ], $rows),
    ]);
}

/**
 * Ziua de început a unui preț, verificată. Lipsă (ori goală) înseamnă azi.
 *
 * `valid_day()` nu se poate folosi aici: refuză cu `bad_date`, iar pagina
 * așteaptă `bad_day` ca să aprindă câmpul zilei. Și nu verifică marginile.
 *
 * `checkdate()` e miezul: „2026-02-30" are forma bună și trece de orice
 * expresie regulată, iar MySQL, după cum e pornit, fie o refuză cu o eroare
 * (500 în fața omului), fie o scrie ca „0000-00-00" — un preț „dintotdeauna",
 * care ar muta tăcut tot trecutul.
 */
function pret_zi_buna($v, string $azi): string
{
    if ($v === null || (is_string($v) && trim($v) === '')) {
        return $azi;
    }
    $min = zi_mutata($azi, -GP_PRET_ZILE_INAPOI);
    $max = zi_mutata($azi, GP_PRET_ZILE_INAINTE);

    $buna = false;
    if (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m)) {
        $buna = checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && $v >= $min && $v <= $max;
    }
    if (!$buna) {
        fail(
            'Ziua de început a prețului trebuie să fie o zi adevărată, între ' . $min . ' și ' . $max . '.',
            422,
            'bad_day'
        );
    }
    return $v;
}

/**
 * POST /stock/items/{id}/prices — un preț nou. Corp: `{ price, validFrom?, note? }`.
 *
 * `unit` NU se primește din corp: se ia de pe articol, în clipa scrierii. Dacă
 * ar veni de la pagină, o filă deschisă de dimineață ar scrie „kg" pe un
 * articol trecut între timp în „g", iar regula unității schimbate ar fi
 * ocolită tocmai de cifra greșită de o mie de ori.
 *
 * Răspunde 201 la un preț nou și 200 la o corectură (aceeași zi, același
 * articol): `{ pret: {…}, corectura: bool }`.
 */
function route_item_prices_add(string $id): void
{
    $u = require_drept('preturi');
    costuri_cere_tabelul();
    $b  = body();
    $id = (int) $id;

    if (!one('SELECT id FROM stock_items WHERE id = ?', [$id])) {
        fail('Articolul nu există.', 404, 'not_found');
    }

    // ZERO E UN PREȚ („primit pe gratis"), nu o lipsă — de aceea marginea de
    // jos e 0, nu „mai mare ca zero". `num_field()` refuză tot ce nu e număr:
    // textul gol, `null`, `true`, o listă, „3,45" cu virgulă (virgula o
    // preface PAGINA în punct, nu serverul: aici „3,45" ar putea fi la fel de
    // bine „3" și „45" lipite greșit). Mesajul lui nu repetă cifra primită.
    $primit = num_field($b['price'] ?? null, 'Prețul', 0, GP_PRET_MAX, 'bad_price');
    // Coloana ține patru zecimale; ce e peste se rotunjește AICI, ca răspunsul
    // să arate cifra care s-a scris, nu pe cea trimisă. `+ 0.0` șterge semnul
    // lui „-0", care altfel ar pleca în răspuns ca `-0.0`.
    $pret = round($primit, 4) + 0.0;

    // UN PREȚ MAI MARE CA ZERO NU AJUNGE „GRATIS" PRIN ROTUNJIRE. 0,00004 lei
    // e sub jumătatea ultimei zecimale ținute, deci s-ar scrie 0,0000 — iar
    // zero are aici un înțeles al lui: „primit pe gratis", preț bun, care
    // scoate articolul din lista roșie și intră în costuri ca atare. Omul care
    // a scris un zero în plus după virgulă ar fi primit 201 și un ingredient
    // gratuit, fără niciun semn. Zero se scrie numai de cel care a scris zero.
    // Mesajul nu repetă cifra (hotărârea 2 din capul fișierului).
    if ($primit > 0 && $pret <= 0) {
        fail('Prețul e prea mic pentru patru zecimale; scrie 0 dacă e primit pe gratis.', 422, 'bad_price');
    }

    $zi   = pret_zi_buna($b['validFrom'] ?? null, today_local());
    $nota = text_field($b['note'] ?? null, 'Nota', 255);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // SE ÎNCUIE ARTICOLUL, nu rândul de preț. Două pricini:
        //
        //  · unitatea se citește SUB încuietoare: un `PATCH /stock/items/#`
        //    care trece articolul din kg în g chiar acum fie a apucat înaintea
        //    noastră (și scriem „g"), fie așteaptă până terminăm (și prețul
        //    rămâne, cinstit, pe „kg" — `unitate_schimbata`). Niciodată un preț
        //    cu unitatea de după și cifra de dinainte;
        //
        //  · două cereri deodată pe același articol și aceeași zi trec PE RÂND.
        //    Fără asta, amândouă ar căuta rândul zilei, nu l-ar găsi și ar
        //    scrie: una ar cădea pe cheia unică (`uq_item_prices_day`), adică
        //    500 pentru un om care a apăsat de două ori. Încuiat rândul de PREȚ
        //    n-ar fi ajutat — nu se poate încuia un rând care încă nu există,
        //    iar golul încuiat în locul lui face din două cereri un blocaj
        //    reciproc. Articolul există mereu.
        $item = one('SELECT id, name, unit FROM stock_items WHERE id = ? FOR UPDATE', [$id]);
        if (!$item) {
            // Șters de altcineva între verificarea de sus și încuietoare.
            $pdo->rollBack();
            fail('Articolul nu există.', 404, 'not_found');
        }

        $corectura = one(
            'SELECT id FROM stock_item_prices WHERE item_id = ? AND valid_from = ?',
            [$id, $zi]
        ) !== null;

        // `ON DUPLICATE KEY UPDATE` rămâne și sub încuietoare: e centura de
        // lângă bretele, pentru o scriere venită pe altă cale decât ruta asta.
        // La corectură se rescriu TOATE: cifra, unitatea de acum a articolului,
        // nota, cine și când — rândul spune cine a pus cifra care e în el.
        q(
            'INSERT INTO stock_item_prices (item_id, price, unit, valid_from, note, set_by, set_at)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE price = VALUES(price), unit = VALUES(unit), note = VALUES(note),
                                     set_by = VALUES(set_by), set_at = VALUES(set_at)',
            [
                $id,
                // Ca text cu exact patru zecimale: un `float` legat de-a
                // dreptul ar ajunge la bază cu câte zecimale are el chef.
                number_format($pret, 4, '.', ''),
                (string) $item['unit'],
                $zi,
                $nota === '' ? null : $nota,
                (int) $u['id'],
            ]
        );

        // Fără valoare — v. hotărârea 2 din capul fișierului.
        audit(
            'pret_nou',
            'stock_item',
            (string) $id,
            ($corectura ? 'Preț corectat pe ' : 'Preț nou pe ') . $item['name'] . ', valabil din ' . $zi
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $rand = one(
        'SELECT ' . GP_PRET_COLOANE . '
           FROM stock_item_prices p
           LEFT JOIN users u ON u.id = p.set_by
          WHERE p.item_id = ? AND p.valid_from = ?',
        [$id, $zi]
    );
    if (!$rand) {
        // Șters de altă cerere în clipa dintre scriere și citire. Scrierea a
        // reușit și e în Jurnal; pagina reîncarcă lista și vede starea de acum.
        fail('Prețul a fost scris, dar între timp a fost șters de altcineva.', 409, 'price_gone');
    }

    json_out(['pret' => pret_public($rand), 'corectura' => $corectura], $corectura ? 200 : 201);
}

/**
 * DELETE /stock/prices/{id} — șterge UN rând de preț.
 *
 * Ștergerea e pentru prețul scris greșit (pe alt articol, cu altă zi). După
 * ea, zilele rândului șters se socotesc cu prețul dinaintea lui — ori, dacă
 * era singurul, articolul rămâne fără preț și produsele lui fără cost.
 */
function route_prices_delete(string $id): void
{
    require_drept('preturi');
    costuri_cere_tabelul();
    $id = (int) $id;

    $rand = one('SELECT id, item_id FROM stock_item_prices WHERE id = ?', [$id]);
    if (!$rand) {
        fail('Prețul nu există.', 404, 'not_found');
    }
    $itemId = (int) $rand['item_id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // ACEEAȘI ORDINE a încuietorilor ca la scriere: întâi articolul, apoi
        // prețul. Luate pe dos (prețul, apoi articolul), o ștergere și o
        // corectură pornite deodată s-ar aștepta una pe alta, iar baza ar
        // omorî una dintre ele — 500 pentru o apăsare de buton.
        $item = one('SELECT id, name FROM stock_items WHERE id = ? FOR UPDATE', [$itemId]);
        $pret = one('SELECT id, valid_from FROM stock_item_prices WHERE id = ? FOR UPDATE', [$id]);
        if (!$item || !$pret) {
            // Două ștergeri deodată: a doua nu mai are ce șterge.
            $pdo->rollBack();
            fail('Prețul nu există.', 404, 'not_found');
        }

        q('DELETE FROM stock_item_prices WHERE id = ?', [$id]);

        // Tot fără valoare. Ziua spune CARE preț a fost șters; cifra lui nu.
        audit(
            'pret_sters',
            'stock_item',
            (string) $itemId,
            'Preț șters pe ' . $item['name'] . ' (cel valabil din ' . $pret['valid_from'] . ')'
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    json_out(['ok' => true]);
}
