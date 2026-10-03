<?php
/**
 * Recomandările, partea de bani: ce vede fiecare om și ce poate face patronul.
 *
 * Rutele se împart în două după CINE ÎNTREABĂ, nu după ce se cere:
 *
 *   GET  /referrals            — ale mele. Oricine e conectat.
 *   POST /referrals/code       — fă-mi un cod. Oricine e conectat.
 *   GET  /referrals/all        — toate, cu tot cu conflicte. Numai administratorul.
 *   POST /referrals/pay        — bifa „i-am dat banii". Numai administratorul.
 *   POST /referrals/#/resolve  — conflictul, hotărât. Numai administratorul.
 *   DELETE /referrals/#        — scoate rândul din socoteală. Numai administratorul.
 *   POST /referrals/#/restore  — adu-l înapoi. Numai administratorul.
 *   GET  /referrals/rate-fix   — ce s-ar îndrepta din cele născute la 0%.
 *   POST /referrals/rate-fix   — îndreaptă-le. Amândouă, numai administratorul.
 *
 * Împărțirea nu e o formalitate: pe drumul „ale mele", identitatea vine din
 * SESIUNE și intră direct în `WHERE`. Nu există parametru prin care un agent
 * să ceară rândurile altuia — vezi `referral_history()` din api/lib/referral.php,
 * unde e scris ce anume NU pleacă spre el.
 */

declare(strict_types=1);

/**
 * Poarta comună: comisioanele au nevoie de migrarea 008.
 *
 * 503, nu 500: serverul e sănătos, doar nu i s-a importat încă fișierul. Mesajul
 * spune exact ce se rulează, fiindcă cel care îl citește e patronul, nu un
 * programator.
 */
function referral_require_money(): void
{
    if (!referral_money_supported()) {
        fail(
            'Comisioanele nu sunt disponibile: rulează întâi '
              . 'sql/008_comisioane_recomandari.sql (și 007 înaintea lui).',
            503,
            'referral_money_unavailable'
        );
    }
}

/* ------------------------------------------------------------- ale mele -- */

/**
 * Pagina proprie: codul, linkul, cele trei plăci și istoricul.
 *
 * Merge și pe o bază fără migrarea 008 — atunci întoarce zerouri și liste
 * goale, iar pagina desenează cardul cu codul și atât. Un 503 aici ar rupe
 * pagina „Recomandări" pentru toată lumea în intervalul dintre urcarea
 * fișierelor și importul din phpMyAdmin.
 */
function route_referrals_mine(): void
{
    $u  = require_auth();
    $id = (int) $u['id'];

    $cod  = referral_code_of($id);
    $ist  = referral_money_supported()
        ? referral_history($id)
        : ['rows' => [], 'buyers' => [], 'totals' => referral_totals_zero()];

    json_out([
        'code'    => $cod,
        'link'    => referral_link($cod),
        'canMake' => $cod === null && referral_supported(),
        // Procentul lui, ca să-și poată verifica singur plata. E de neevitat
        // dacă vrea să înmulțească: cine vede suma și baza află oricum
        // procentul. Restul comenzii rămâne închis.
        'rate'    => referral_money_supported() ? referral_rate_for($id) : 0.0,
        'money'   => referral_money_supported(),
        'rows'    => $ist['rows'],
        'buyers'  => $ist['buyers'],
        'totals'  => $ist['totals'],
    ]);
}

/**
 * „Fă-mi un cod", apăsat de omul însuși.
 *
 * Nu primește niciun cod din cerere: codul se derivă din numele contului, ca la
 * înscriere. Dacă ar accepta un șir, oricine și-ar putea lua „gold2025" înaintea
 * patronului — alegerea codurilor frumoase rămâne la administrator.
 */
function route_referrals_make_code(): void
{
    $u = require_auth();
    if (!referral_supported()) {
        fail('Recomandările nu sunt disponibile: rulează întâi sql/007_update_roles_referral.sql.',
            503, 'referral_unavailable');
    }
    if ((int) ($u['is_system'] ?? 0) === 1) {
        fail('Contul de sistem nu poate avea cod de recomandare.', 422, 'system_account');
    }

    // Aici apasă omul însuși, iar el NU-și poate schimba numele contului: nu
    // există nicio rută pentru asta. Sfatul de dinainte îl trimitea deci către
    // un lucru pe care nu-l putea face. Amândouă refuzurile duc acum la singurul
    // om care chiar poate rezolva: administratorul.
    $pricina = null;
    $cod = referral_self_assign((int) $u['id'], $pricina);
    if ($cod === null) {
        if ($pricina === 'toate_luate') {
            fail('Numele „' . $u['name'] . '” e prea des întâlnit: toate codurile care se pot '
                . 'face din el sunt deja luate. Cere-i unui administrator un cod ales de mână, '
                . 'din „Conturi".', 422, 'referral_all_taken');
        }
        fail('Nu am putut construi un cod din numele „' . $u['name'] . '”. '
            . 'Cere-i unui administrator să-ți schimbe numele contului sau să-ți dea un cod '
            . 'ales de mână, din „Conturi".', 422, 'referral_failed');
    }
    audit('referral_self_code', 'user', (string) $u['id'], $u['name'] . ' → ' . $cod);
    json_out(['code' => $cod, 'link' => referral_link($cod)]);
}

/* --------------------------------------------------------- administrator -- */

/**
 * Toate comisioanele, pentru pagina patronului.
 *
 * Aici PLEACĂ tot ce lipsea din răspunsul agentului — `order_id`, `order_ref`,
 * `base_total`, codurile aflate în conflict. Diferența dintre cele două rute e
 * chiar dovada că filtrarea se face în SQL, nu în pagină: sunt două interogări
 * deosebite, nu aceeași interogare cu un `if` în față.
 *
 * Căutarea și sortarea se fac în PAGINĂ, nu aici, dinadins: lista unei patiserii
 * are sute de rânduri, nu sute de mii, iar filtrarea pe server ar însemna un
 * drum la bază la fiecare literă tastată. Ce se mărginește pe server e
 * INTERVALUL — luna — fiindcă acela chiar poate crește nemărginit.
 */
function route_referrals_all(): void
{
    require_admin();
    referral_require_money();

    $q    = $_GET['month'] ?? '';
    $luna = is_string($q) ? trim($q) : '';
    $unde = [];
    $args = [];
    // „2026-09". Verificat cu tipar, nu concatenat: șirul intră într-un BETWEEN.
    if ($luna !== '' && preg_match('/^\d{4}-\d{2}$/', $luna)) {
        $unde[] = 'c.order_day >= ? AND c.order_day < DATE_ADD(?, INTERVAL 1 MONTH)';
        $args[] = $luna . '-01';
        $args[] = $luna . '-01';
    }

    // Coloanele scoaterii se cer numai dacă există. Pe o bază fără migrarea
    // 012 numele lor nu e o comparație care iese falsă, ci o eroare de SQL —
    // adică pagina „Recomandări" moartă exact în intervalul dintre urcarea
    // fișierelor și importul din phpMyAdmin.
    $colScos = referral_dismiss_supported() ? ', c.dismissed_at, c.dismissed_by' : '';

    // LISTA PATRONULUI LE ARATĂ, TOTALURILE NU LE NUMĂRĂ.
    //
    // Un rând ascuns cu totul ar fi fost un cimitir din care nu se mai iese:
    // singurul om care poate aduce rândul înapoi e chiar cel căruia i l-am fi
    // ascuns. Rămâne deci în listă, tăiat, cu butonul lui — iar din cele cinci
    // socoteli de deasupra iese, fiindcă tocmai asta a cerut patronul când l-a
    // scos. Pagina agentului e altă poveste: acolo nu se vede deloc, fiindcă
    // agentul n-are butonul (vezi `referral_history()`).
    $rows = all(
        'SELECT c.id, c.order_id, c.order_ref, c.order_day, c.referrer_id, c.referrer_label,
                c.buyer_id, c.buyer_label, c.code_used, c.code_account, c.code_typed,
                c.rate, c.base_total, c.amount, c.status, c.paid_at, c.paid_by' . $colScos . '
           FROM referral_commissions c'
          . ($unde ? ' WHERE ' . implode(' AND ', $unde) : '')
          . ' ORDER BY c.order_day DESC, c.id DESC
          LIMIT 5000',
        $args
    );

    // TOTALURILE NU SE MAI ADUNĂ DIN CE A ÎNCĂPUT ÎN LISTĂ.
    //
    // Lista e tăiată la 5000 de rânduri, iar tăietura cade pe cele mai vechi
    // (sortarea e descrescătoare). Adunate din rânduri, cele trei plăci arătau
    // mai puțin decât adevărul tocmai în cazul în care conta: firma care a
    // strâns destule comisioane cât să treacă de prag. Și nicio cifră de pe
    // ecran nu spunea că s-a tăiat ceva.
    //
    // Grija scrisă aici înainte — „două interogări pot vedea baze diferite" —
    // era adevărată, dar prețul ei era mai mare decât ea: un total mut și
    // greșit. Acum socoteala se face în SQL, peste TOATE rândurile intervalului,
    // iar pagina primește și `truncated`, ca să poată spune pe față când lista
    // de dedesubt e mai scurtă decât socoteala de deasupra.
    //
    // BANII PLĂTIȚI SE NUMĂRĂ ORICUM S-AR FI SFÂRȘIT COMANDA. Comisionul plătit
    // pe o comandă anulată după aceea nu mai e „câștigat" — dar banii au ieșit
    // din casă. Numărat numai la „activ", cum era, el dispărea din placa
    // „Plătit" și nu-l mai căuta nimeni. De-aia pleacă și `paidCancelled`:
    // exact suma pe care patronul o are de recuperat.
    // Aceleași margini ca lista, plus una: rândurile scoase. Condiția n-are
    // niciun semn de întrebare în ea, deci `$args` rămâne neatins.
    $undeT = $unde;
    $cScos = referral_dismiss_cond('c');
    if ($cScos !== '') {
        $undeT[] = $cScos;
    }

    $t = one(
        'SELECT
            SUM(CASE WHEN c.status = ? THEN c.amount ELSE 0 END)                        AS castigat,
            SUM(CASE WHEN c.paid_at IS NOT NULL THEN c.amount ELSE 0 END)               AS platit,
            SUM(CASE WHEN c.status = ? AND c.paid_at IS NULL THEN c.amount ELSE 0 END)  AS de_incasat,
            SUM(CASE WHEN c.status = ? AND c.paid_at IS NOT NULL THEN c.amount ELSE 0 END) AS platit_stins,
            SUM(CASE WHEN c.status = ? THEN 1 ELSE 0 END)                               AS conflicte,
            COUNT(*)                                                                    AS toate
           FROM referral_commissions c'
          . ($undeT ? ' WHERE ' . implode(' AND ', $undeT) : ''),
        array_merge(['activ', 'activ', 'anulat', 'in_asteptare'], $args)
    );
    $castigat  = round((float) ($t['castigat'] ?? 0), 2);
    $platit    = round((float) ($t['platit'] ?? 0), 2);
    $deIncasat = round((float) ($t['de_incasat'] ?? 0), 2);
    $platitStins = round((float) ($t['platit_stins'] ?? 0), 2);
    $conflicte = (int) ($t['conflicte'] ?? 0);
    $taiata    = ((int) ($t['toate'] ?? 0)) > count($rows);

    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['order_id']   = (int) $r['order_id'];
        $r['rate']       = round((float) $r['rate'], 2);
        $r['base_total'] = round((float) $r['base_total'], 2);
        $r['amount']     = round((float) $r['amount'], 2);
        $r['paid']       = $r['paid_at'] !== null;
        // Steagul pleacă întotdeauna, și pe o bază fără 012 — altfel pagina ar
        // trebui să știe singură dacă are voie să se uite după el.
        $r['dismissed']  = ($r['dismissed_at'] ?? null) !== null;
    }
    unset($r);

    // Cine stă în spatele celor două coduri aflate în conflict.
    //
    // Fără asta, patronul alege între „probacodbbci95" și „probacodanoubci95" —
    // două șiruri care nu-i spun nimic despre oameni. Numele nu poate fi luat
    // din `referrer_label`: pe un rând în așteptare e gol dinadins, fiindcă
    // rândul nu e încă al nimănui.
    //
    // O singură interogare pentru toate conflictele, nu una pe rând: lista se
    // deschide cu tot cu ele, iar un SELECT pe fiecare ar face din pagina asta
    // un drum la bază pentru fiecare cod nehotărât.
    $coduri = [];
    foreach ($rows as $r) {
        if ($r['status'] !== 'in_asteptare') {
            continue;
        }
        foreach ([$r['code_account'], $r['code_typed']] as $c) {
            if (is_string($c) && $c !== '') {
                $coduri[$c] = true;
            }
        }
    }
    if ($coduri) {
        $nume = referral_owners_for_codes(array_keys($coduri));
        foreach ($rows as &$r) {
            if ($r['status'] !== 'in_asteptare') {
                continue;
            }
            // Lipsa e o stare adevărată: codul a fost între timp redenumit sau
            // contul închis. Pagina scrie atunci codul gol, ca omul să vadă de
            // ce butonul acela nu duce nicăieri.
            $r['code_account_name'] = $nume[(string) $r['code_account']]['name'] ?? null;
            $r['code_typed_name']   = $nume[(string) $r['code_typed']]['name'] ?? null;
            // Și cine e omul, nu doar cum îl cheamă: pe un rând deja plătit
            // serverul primește numai hotărârea în favoarea celui plătit
            // (`referral_guard_paid_code_change`), iar pagina compară id-ul
            // stăpânului cu `referrer_id` ca să nu deseneze butonul refuzat.
            $r['code_account_owner_id'] = $nume[(string) $r['code_account']]['id'] ?? null;
            $r['code_typed_owner_id']   = $nume[(string) $r['code_typed']]['id'] ?? null;
        }
        unset($r);
    }

    json_out([
        'rows'   => $rows,
        'month'  => $luna,
        'totals' => [
            'earned'        => $castigat,
            'paid'          => $platit,
            // „De încasat" se socotește direct, nu ca scădere: cu banii plătiți
            // pe comenzi anulate în placa „Plătit", o scădere ar putea ieși
            // negativă și ar speria degeaba.
            'due'           => $deIncasat,
            'paidCancelled' => $platitStins,
            'conflicts'     => $conflicte,
        ],
        'truncated' => $taiata,
        'defaultRate' => referral_default_rate(),
    ]);
}

/**
 * Bifa de plată, pusă sau scoasă, pe unul sau pe mai multe rânduri deodată.
 *
 * Una singură și „bifează toată luna" merg pe aceeași rută, cu același corp:
 * o listă de identificatori. Două rute ar fi însemnat două locuri în care se
 * scrie aceeași regulă, iar „toată luna" ar fi fost cea care rămânea în urmă.
 */
function route_referrals_pay(): void
{
    $u = require_admin();
    referral_require_money();

    $b   = body();
    $ids = $b['ids'] ?? null;
    if (!is_array($ids) || !$ids) {
        fail('Trimite lista de comisioane („ids").', 422, 'no_ids');
    }
    if (count($ids) > 1000) {
        fail('Prea multe comisioane deodată (cel mult 1000).', 422, 'too_many');
    }
    // Implicit „da": butonul obișnuit bifează. Scoaterea trebuie cerută anume.
    $paid = !array_key_exists('paid', $b) || (bool) $b['paid'];

    $n = referral_commission_pay(array_map('intval', $ids), $paid, (int) $u['id']);
    json_out(['ok' => true, 'changed' => $n, 'paid' => $paid]);
}

/* ---------------------------------------- comisioanele născute la zero -- */

/**
 * Previzualizarea: ce ar deveni fiecare rând născut la procentul zero.
 *
 * GET, fiindcă nu schimbă nimic — se poate reîncărca de câte ori vrea omul, și
 * chiar se reîncarcă, odată cu lista, după fiecare bifă de plată sau scoatere.
 *
 * Aceeași poartă ca lista întreagă, și din același motiv: răspunsul spune cine
 * încasează, din ce comandă și din ce bază — adică tocmai ce nu pleacă
 * niciodată spre un agent (v. `referral_history()`).
 *
 * Toată socoteala stă în `referral_zero_rate_preview()`, în bibliotecă, nu
 * aici: o folosesc DOUĂ rute — asta și apăsarea de mai jos — iar ruterul
 * încarcă un singur fișier de rută pe cerere (api/index.php:284). Scrisă în
 * fișierul ăsta, ar fi fost o socoteală pe care a doua rută n-o vede.
 */
function route_referrals_rate_fix_preview(): void
{
    require_admin();
    referral_require_money();

    json_out(referral_zero_rate_preview());
}

/**
 * Butonul „Îndreaptă", apăsat de patron.
 *
 * NU PRIMEȘTE NICIO LISTĂ DE RÂNDURI din cerere, dinadins. Ar fi părut mai
 * sigur — „atinge numai ce ți-am arătat" — dar ar fi deschis o ușă pe care
 * previzualizarea o ține închisă: un identificator scris de mână în cerere.
 * Marginile sunt aceleași în citire și în scriere, iar un rând nou nu poate
 * răsări între cele două: ca să se nască la 0%, procentul de azi al omului ar
 * trebui să fie tot zero — și atunci rândul acela nici nu intră în listă.
 *
 * Răspunsul spune câte rânduri s-au schimbat CU ADEVĂRAT. Zero nu e o eroare:
 * e treaba deja făcută de cel care a apăsat cu o clipă înainte.
 */
function route_referrals_rate_fix(): void
{
    $u = require_admin();
    referral_require_money();

    $r = referral_zero_rate_fix((int) $u['id']);
    json_out(['ok' => true, 'changed' => $r['changed'], 'total' => $r['total']]);
}

/**
 * Conflictul, hotărât: care dintre cele două coduri câștigă.
 *
 * Alegerea prinde comanda asta și, de-acum înainte, toate comenzile omului.
 * Toată regula stă în `referral_commission_resolve()`, inclusiv apărarea care
 * nu lasă comisionul să sară pe un al treilea om trimis prin curl.
 */
function route_referrals_resolve(string $id): void
{
    $u = require_admin();
    referral_require_money();

    $b   = body();
    $cod = trim((string) ($b['code'] ?? ''));
    if ($cod === '') {
        fail('Alege unul dintre cele două coduri („code").', 422, 'no_code');
    }

    $rand = referral_commission_resolve((int) $id, $cod, (int) $u['id']);
    json_out(['ok' => true, 'commission' => $rand]);
}

/* ------------------------------------------------- scos din socoteală -- */

/**
 * Scoate rândul din socoteală.
 *
 * DELETE, fiindcă asta cere omul care apasă: „rândul ăsta n-are ce căuta aici".
 * Ce se întâmplă dedesubt e altceva decât un DELETE, și trebuie să fie: rândul
 * e recalculat de `referral_commission_sync()` la fiecare atingere a comenzii,
 * deci șters de-a dreptul s-ar fi scris la loc singur, cu alt id, la prima
 * mutare de etapă. Se ține minte în schimb FAPTUL că a fost scos — un fapt
 * scris nu se recalculează. Toată lămurirea e în sql/012_comision_scos.sql.
 *
 * Aceeași hotărâre ca la comenzi, care nu se șterg, ci se anulează.
 */
function route_referrals_delete(string $id): void
{
    $u = require_admin();
    referral_require_money();

    $rand = referral_commission_dismiss((int) $id, true, (int) $u['id']);
    json_out(['ok' => true, 'dismissed' => true, 'commission' => $rand]);
}

/**
 * Adu-l înapoi.
 *
 * Există fiindcă scoaterea e o hotărâre a unui om, iar o hotărâre greșită
 * trebuie să se poată retrage — exact motivul pentru care și bifa de plată se
 * poate scoate. Fără asta, singura reparație ar fi prin phpMyAdmin.
 *
 * Amândouă trec prin aceeași funcție de bibliotecă, cu un steag, ca să nu se
 * poată depărta una de alta la prima reparație. Rândul întors poate fi și null:
 * aducerea înapoi recitește comanda, iar dacă între timp a rămas fără cod și
 * fără recomandant de cont, cârligul stinge rândul — răspuns corect, nu eroare.
 */
function route_referrals_restore(string $id): void
{
    $u = require_admin();
    referral_require_money();

    $rand = referral_commission_dismiss((int) $id, false, (int) $u['id']);
    json_out(['ok' => true, 'dismissed' => false, 'commission' => $rand]);
}
