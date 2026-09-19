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
