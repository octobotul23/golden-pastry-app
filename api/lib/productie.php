<?php

/**
 * Registrul producției de laborator: ceasul lipit de bucățile făcute.
 *
 * Angajații raportau deja câte au făcut — `+`/`−` din „Panou Laborator" scriu
 * `order_items.done_qty` prin `POST /orders/#/production`. Dar `done_qty` e un
 * TOTAL CURENT, fără nicio clipă lipită de el: nu se putea spune CÂND s-au
 * făcut bucățile, deci nu se putea potrivi cu nicio tură. Aici se scrie clipa.
 *
 * Tot ce ține de forma tabelului și de hotărârile din spatele lui — delta în
 * loc de total, de ce nu `stock_moves`, de ce `order_id` cade pe NULL și
 * `item_id` n-are cheie străină — e lămurit întreg în sql/021_raspundere.sql.
 *
 * CE NU SCRIE REGISTRUL ĂSTA, și de ce: omul. Patronul a cerut anume „vreau să
 * știu doar cine era de tură când s-a făcut comanda", nu cine a apăsat. Și n-ar
 * avea de unde: tableta din laborator intră cu un cont comun („panou"), deci un
 * `user_id` de-aici ar scrie de fiecare dată același nume și ar părea un adevăr.
 * Cine era de tură se află potrivind clipele cu `lab_shifts`, la raport.
 */

/**
 * Există tabelul? Dacă nu, producția se raportează mai departe, fără registru.
 *
 * `api/` și paginile se urcă pe server în aceeași rulare, dar migrarea se dă de
 * mână, uneori mai târziu. Între cele două clipe codul ăsta rulează peste o bază
 * fără `lab_production`, iar un INSERT într-un tabel care nu există ar da 500 —
 * adică ar opri laboratorul din raportat, pentru un raport de tură pe care
 * patronul încă nici nu l-a cerut. Deci paza cade ÎNCHIS: fără tabel nu se
 * scrie nimic, raportul de tură iese gol, iar restul se poartă exact ca în
 * 2.2.0. Același tipar ca `telegram_legaturi_suportate()` și
 * `user_zone_supported()`.
 *
 * Răspunsul se ține minte pe durata cererii: un raport cu douăzeci de poziții
 * ar întreba altfel de douăzeci de ori același lucru.
 */
function lab_production_suportata(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $tabel = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_production'"
        );
        return $ok = ($tabel === 1);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica registrul de producție: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Un rând de registru pentru o poziție care tocmai s-a mișcat.
 *
 * `$delta` e DIFERENȚA, cu semn, nu totalul nou — corecțiile în jos sunt
 * îngăduite în laborator și trebuie să scadă din raport, nu să se adune la el.
 * Zero nu se scrie: ruta de producție nici nu-l trimite (pozițiile neschimbate
 * nu intră în plan), iar un rând de zero ar umfla numărul de atingeri degeaba.
 *
 * `$itemId` e POZIȚIA DE PE COMANDĂ (`order_items.id`), nu articolul de depozit.
 * Deosebirea contează aici mai mult decât oriunde: în `route_order_production()`
 * variabila `$p['item_id']` înseamnă tocmai celălalt lucru — articolul de produs
 * finit din `stock_items`, cel care poate lipsi. Registrul ăsta ține poziția,
 * fiindcă el nu are voie să aibă goluri (v. mai jos).
 *
 * CHEMAT PENTRU FIECARE POZIȚIE SCHIMBATĂ, inclusiv pentru cele fără articol de
 * produs finit. Asta e chiar pricina pentru care registrul nu e `stock_moves`:
 * acolo, când articolul finit lipsește, nu se scrie niciun rând. Un registru cu
 * goluri, citit lângă un stat de plată, ar fura ore de muncă adevărată.
 */
function lab_production_scrie(?int $orderId, ?int $itemId, string $nume, float $delta): void
{
    if ($delta === 0.0 || !lab_production_suportata()) {
        return;
    }

    // ORA PATISERIEI, nu a serverului. Toate legăturile pornesc cu
    // `SET time_zone = '+00:00'` (api/lib/db.php), deci un `CURRENT_TIMESTAMP`
    // de-aici ar scrie UTC — pe când turele se scriu cu `now_local()`. Potrivite
    // între ele, cele două ceasuri ar da ture greșite cu două-trei ore, TĂCUT.
    //
    // Ziua se TAIE din aceeași clipă, nu se cere a doua oară ceasului.
    // `today_local()` ar da aproape întotdeauna același lucru — dar „aproape"
    // înseamnă aici rândul scris la 23:59:59.99: două citiri deosebite pot cădea
    // de-o parte și de alta a miezului nopții, iar raportul ar avea un rând a
    // cărui zi nu se potrivește cu ora lui scrisă alături. Se citește o dată.
    $acum = now_local();

    q(
        'INSERT INTO lab_production (order_id, item_id, name, delta, at, day)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$orderId, $itemId, $nume, round($delta, 2), $acum, substr($acum, 0, 10)]
    );
}

/* ------------------------------------------------- mașinăria producției -- */

/*
 * DE CE STAU CELE TREI FUNCȚII DE MAI JOS AICI, ȘI NU ÎN RUTĂ.
 *
 * Până în runda 2.3.0 raportarea producției avea o singură ușă:
 * `POST /orders/#/production`, apăsată de om pe fișa unei comenzi. Toată
 * mașinăria stătea înăuntrul ei — consumul de materii prime, intrarea pe
 * finit, `done_qty`, ceasul din registru, stingerea rezervărilor.
 *
 * Runda asta deschide a doua ușă: `POST /lab/productie/imparte`, care împarte
 * o cantitate produsă peste comenzile zilei, de sus în jos, fără ca omul să
 * atingă vreo comandă anume. Scrisă a doua oară, mașinăria s-ar fi despărțit
 * în două adevăruri la prima corectură — iar despărțirea ar fi fost tocmai
 * felul ăsta: una care mănâncă făină și una care nu, sau una care stinge
 * rezervările și una care le lasă. Nu s-ar fi văzut din nimic: amândouă
 * răspund 200, iar cifra greșită apare abia la inventar.
 *
 * Deci mașinăria se mută aici, întreagă, iar cele două rute o cheamă. Ce
 * rămâne în fiecare rută e numai ce ține de ușa ei: ce se verifică înainte și
 * ce se răspunde după.
 */

/**
 * Pozițiile unor comenzi, fiecare cu articolul de depozit care îi ține locul.
 *
 * Potrivirea poziție → articol finit e copiată literă cu literă din
 * `route_stock_sync()`, filtrul pe `active` inclusiv. Dacă intrarea de la
 * producție ia un articol și scăderea de la etapa finală ia altul, croasantul
 * intră într-un raft și iese din altul: două cifre greșite în loc de una.
 *
 * `si.id` poate ieși NULL — produsul n-are articol de depozit. Nu e o eroare
 * și nu oprește nimic (v. `productie_aplica()`), dar cine cheamă funcția
 * trebuie să se uite la el.
 *
 * O SINGURĂ interogare pentru toate comenzile cerute, nu una pe comandă:
 * împărțirea atinge ziua întreagă, iar o zi are zeci de comenzi.
 */
function productie_pozitii(array $orderIds): array
{
    $ids = [];
    foreach ($orderIds as $x) {
        $x = (int) $x;
        if ($x > 0) {
            $ids[$x] = true;
        }
    }
    $ids = array_keys($ids);
    if (!$ids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));

    return all(
        "SELECT oi.id, oi.order_id, oi.product_id, oi.name, oi.qty, oi.done_qty,
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
          WHERE oi.order_id IN ($marks)
          ORDER BY oi.order_id, oi.id",
        $ids
    );
}

/**
 * Rețetele mai multor produse deodată, așezate pe `product_id`.
 *
 * Într-o singură interogare: altfel o comandă cu douăzeci de poziții ar face
 * douăzeci de drumuri la bază pentru ceva ce încape într-un `IN`, iar
 * împărțirea pe o zi întreagă ar face sute.
 *
 * Un produs fără rețetă lipsește cu totul din răspuns, nu iese cu listă goală:
 * cine citește folosește `?? []`, ca și până acum.
 */
function productie_retete(array $productIds): array
{
    $pids = [];
    foreach ($productIds as $x) {
        $x = (int) $x;
        if ($x > 0) {
            $pids[$x] = true;
        }
    }
    $pids = array_keys($pids);
    if (!$pids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($pids), '?'));

    $linii = all(
        "SELECT pr.product_id, pr.item_id, pr.qty, si.name AS item_name
           FROM product_recipes pr
           JOIN stock_items si ON si.id = pr.item_id
          WHERE pr.product_id IN ($marks)
          ORDER BY pr.id",
        $pids
    );

    $out = [];
    foreach ($linii as $l) {
        $out[(int) $l['product_id']][] = $l;
    }
    return $out;
}

/**
 * O atingere de producție, scrisă în toate registrele deodată.
 *
 * CHEAMĂ-O DIN TRANZACȚIE. Scrie în patru locuri, iar o cădere la mijloc ar
 * lăsa făina ieșită din sac fără ca bucățile să fi intrat pe raft. Funcția nu
 * deschide și nu încheie nimic: unitatea de lucru e a rutei, fiindcă o rută
 * atinge de obicei mai multe poziții.
 *
 * `$delta` e DIFERENȚA, cu semn. Pozitivă înseamnă „s-au mai făcut atâtea",
 * negativă înseamnă corectură în jos — îngăduită în laborator, și atunci toate
 * mișcările se întorc: făina se pune la loc în sac, bucățile ies de pe raft.
 * De-asta motivele sunt alese pe semn, nu pe rută.
 *
 * `$nou` e TOTALUL nou al poziției, ori `null` când nu există poziție de scris
 * (surplusul: bucăți făcute peste tot ce cerea ziua). Cele două nu se pot
 * socoti una din alta — `$delta` singur n-ar ști de unde a pornit, iar `$nou`
 * singur n-ar ști cât s-a mișcat.
 *
 * `$p` e rândul întors de `productie_pozitii()`, ori, pentru surplus, un rând
 * făcut de mână cu aceleași chei: `name`, `product_id`, `item_id`, `item_name`
 * — fără `id`. Lipsa lui `id` e chiar semnul că nu e a nimănui: atunci nu se
 * scrie `done_qty` (n-are unde) și nu se sting rezervări (n-are cui).
 *
 * @return array{miscari:int, fara:?string} câte rânduri de stoc s-au scris, și
 *         numele produsului dacă intrarea pe finit n-a avut unde se scrie.
 */
function productie_aplica(
    array $p,
    float $delta,
    ?float $nou,
    array $liniiReteta,
    string $nota,
    int $userId,
    ?int $orderId
): array {
    if ($delta === 0.0) {
        return ['miscari' => 0, 'fara' => null];
    }

    $sens = $delta > 0 ? 1 : -1;
    $cant = abs($delta);

    // Motivele vin din vocabularul paginii Depozit (`IN_R` / `OUT_R`), ca
    // registrul să se citească la fel indiferent cine a scris rândul. Corecția
    // are motivul ei: e altceva decât o producție și trebuie să se vadă din
    // listă că e altceva.
    $motivFinit   = $delta > 0 ? 'Producție laborator' : 'Corecție producție';
    $motivMaterii = $delta > 0 ? 'Consum în producție' : 'Corecție producție';

    $miscari = 0;
    $fara    = null;

    foreach ($liniiReteta as $l) {
        q(
            'INSERT INTO stock_moves (item_id, name, qty, direction, reason, note, user_id, order_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $l['item_id'], (string) $l['item_name'],
                round($cant * (float) $l['qty'], 4), -$sens,
                $motivMaterii, $nota, $userId, $orderId,
            ]
        );
        $miscari++;
    }

    if (($p['item_id'] ?? null) !== null) {
        q(
            'INSERT INTO stock_moves (item_id, name, qty, direction, reason, note, user_id, order_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $p['item_id'], (string) $p['item_name'],
                $cant, $sens, $motivFinit, $nota, $userId, $orderId,
            ]
        );
        $miscari++;
    } else {
        // Lipsa articolului finit NU oprește raportarea: laboratorul chiar a
        // făcut bucățile, iar materiile prime chiar au ieșit din rafturi. Doar
        // intrarea pe finit n-are unde se scrie. Se spune, nu se tace — cine
        // cheamă duce numele mai departe, în răspuns.
        $fara = (string) $p['name'];
    }

    $pozitie = isset($p['id']) ? (int) $p['id'] : null;

    // `done_qty` ține TOTALUL de acum, nu diferența: tableta trimite totaluri
    // dinadins, ca o cerere pierdută să se vindece singură la următoarea.
    // Se scrie ÎNAINTEA rezervărilor, fiindcă ele se socotesc din el.
    if ($pozitie !== null && $nou !== null) {
        q('UPDATE order_items SET done_qty = ? WHERE id = ?', [round($nou, 2), $pozitie]);
    }

    // CEASUL. Aceeași atingere, scrisă în două registre deosebite: `done_qty`
    // ține totalul, iar registrul de producție ține DIFERENȚA, cu clipa ei. Din
    // totaluri nu se poate afla niciodată când s-a lucrat; din diferențe cu
    // ceas se află și una, și alta.
    //
    // `$pozitie` e POZIȚIA de pe comandă, NU articolul de depozit — acela e
    // `$p['item_id']`, cel care uneori lipsește. Rândul se scrie și pentru
    // pozițiile fără articol finit: registrul ăsta n-are voie să aibă goluri,
    // fiindcă se citește lângă un stat de plată. Tocmai golurile sunt pricina
    // pentru care raportul de tură nu se sprijină pe `stock_moves`.
    //
    // Surplusul intră aici cu amândouă nule — și e bine așa: tabelul a fost
    // făcut cu `order_id` care primește NULL (sql/021_raspundere.sql), tocmai
    // fiindcă munca s-a făcut chiar dacă nu e a nimănui. Fără rândul ăsta, cine
    // face douăzeci de bucăți în plus a muncit degeaba în raportul de tură.
    lab_production_scrie($orderId, $pozitie, (string) $p['name'], $delta);

    // Ce s-a produs nu mai e promis: făina a ieșit din raft cu o mișcare
    // adevărată, scrisă adineaori. Fără rândul ăsta aceeași făină ar fi
    // numărată de două ori — o dată ca ieșită, o dată ca încă rezervată — iar
    // „Disponibil" din Depozit ar arăta mai puțin decât are patiseria.
    //
    // `r.qty` e cantitatea pe toată poziția (bucăți × rețetă), deci
    // `r.qty * done_qty / oi.qty` dă exact `done_qty × rețetă`, fără să mai fie
    // nevoie de o citire a rețetei. Marfa gata rămâne promisă până pleacă: pe
    // ea o stinge filtrul de status, nu producția.
    if ($pozitie !== null) {
        q(
            "UPDATE stock_reservations r
                JOIN order_items oi ON oi.id = r.order_item_id
                 SET r.consumed = CASE WHEN r.kind = 'materie' AND oi.qty > 0
                                       THEN r.qty * oi.done_qty / oi.qty
                                       ELSE 0 END
              WHERE r.order_item_id = ?",
            [$pozitie]
        );
    }

    return ['miscari' => $miscari, 'fara' => $fara];
}
