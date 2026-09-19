<?php
/**
 * Ajutoare comune pentru cereri și comenzi.
 *
 * Stau în lib/, nu într-un fișier de rută: routerul încarcă doar ruta care se
 * potrivește, iar aceste funcții sunt folosite și de `routes/orders.php`, și
 * de `routes/requests.php`.
 */

declare(strict_types=1);

/**
 * Calculează prețurile pornind de la catalogul din baza de date.
 *
 * Prețul trimis de browser este ignorat deliberat: altfel oricine poate
 * comanda la ce preț vrea, modificând cererea din consolă.
 *
 * @return array{0: array<int, array>, 1: float}  [poziții cu preț, total]
 */
/**
 * Cele două variante în care pleacă marfa. Prețul depinde de ea, nu de client.
 *
 *   congelat — livrat congelat, în lanț frigorific;
 *   copt     — gata de servire.
 *
 * Prețul se alegea după cum clientul are cont sau nu („cu cont = HoReCa"),
 * ceea ce era o citire greșită a catalogului: cele două prețuri de pe site nu
 * țin de tipul clientului, ci de forma mărfii. Consecința se vedea în bani —
 * vizitatorul alegea „Congelate", vedea 28,50 lei, iar cererea se înregistra
 * cu 34,90.
 */
function order_variants(): array
{
    return ['congelat', 'copt'];
}

/**
 * Contul de client care poartă exact numele acesta, dacă e unul singur ȘI dacă
 * a garantat casa pentru el.
 *
 * Comanda pe care o adaugă administratorul în panou — cea luată la telefon —
 * nu era legată de niciun cont. Clientul o vedea abia la ușă: panoul lui
 * întoarce doar comenzile legate de contul lui, iar aceasta nu era a nimănui.
 *
 * Potrivim doar pe numele întreg și doar când răspunsul e unul singur. Dacă
 * două conturi poartă același nume, nu ghicim: comanda rămâne nelegată, așa
 * cum era înainte, și administratorul o poate corecta.
 *
 * DE CE NU ORICE CONT. Un cont pe care și l-a făcut singur cineva de pe
 * internet nu e o identitate. Pagina „Fă-ți cont" scria numele afișat exact
 * cum era tastat, fără să-l compare cu nimic, și năștea contul cu
 * `role = 'client'`, `active = 1` — adică fix ce caută interogarea de mai jos;
 * iar pe nume nu există nicio unicitate, nici în schemă, nici în migrări.
 * Numele sub care e strigat în casă un client de en-gros e scris pe firma din
 * stradă, deci îl știe oricine. Străinul își făcea un cont cu numele acela și,
 * de a doua zi, comanda pe care operatoarea o scria în panou se lega tăcut de
 * contul LUI: în „Comanda mea" i se deschideau adresa punctului de lucru,
 * telefonul, mențiunile și PREȚUL UNITAR negociat pe fiecare poziție — pentru
 * en-gros, tocmai cifra pe care patronul n-o dă nimănui. Nimeni n-avea cum să
 * bage de seamă: nicio pagină nu arăta contul de care s-a legat o comandă.
 *
 * Aici se face doar UN LUCRU: se scot din candidați conturile născute pe site
 * pe care casa nu le-a recunoscut încă. Nu se închide „străinul își face cont
 * cu numele tău" — acela e un defect al paginii de înscriere, cu semnalarea
 * lui; se închide „și capătă comenzile tale", care e cel cu bani în el.
 *
 * Regula e ACEEAȘI pe toate cele patru drumuri prin care un nume se preface în
 * cont (api/routes/orders.php la creare și la corectura de nume,
 * api/routes/requests.php la trimitere și la acceptare), fiindcă funcția asta e
 * singurul loc din tot API-ul unde se face prefacerea.
 *
 * PORTIȚA, ca reparația să nu fie o capcană: clientul cinstit care s-a înscris
 * singur de pe site rămâne nelegat până când cineva din casă apasă „Recunosc
 * clientul" în Conturi (`POST /users/{id}/vouch`). Atunci `vouched_at` se
 * umple și contul se poartă ca oricare altul, inclusiv retroactiv: comenzile
 * lui vechi rămân nelegate, dar toate cele de la recunoaștere încolo se leagă.
 * Fără portița asta, cine se înscrie de pe site ar rămâne pe veci cu panoul gol
 * și fără să afle vreodată de ce — de-aia „Comanda mea" îi spune, iar Conturi
 * îi poartă însemnul.
 *
 * CE SE PIERDE, de știut: un client venit pe linkul unui agent se înscrie chiar
 * de pe site. Până e recunoscut, comenzile lui luate la telefon n-au
 * `customer_id`, deci `referral_commission_sync()` nu mai găsește
 * recomandantul prin cont (api/lib/referral.php) — agentul își pierde
 * comisionul tocmai pe clientul adus de el, dacă nu se tastează codul pe
 * comandă. Recunoașterea îl repune. Însemnul din Conturi există și pentru asta.
 */
function client_by_name(string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    // Fără migrarea 010 coloanele nu există, iar filtrul ar cădea cu 1054 pe
    // fiecare comandă. Vezi `user_origin_supported()` pentru ce se pierde atunci.
    $garantat = user_origin_supported()
        ? ' AND (self_registered = 0 OR vouched_at IS NOT NULL)'
        : '';
    $rows = all(
        "SELECT id FROM users
          WHERE role = 'client' AND active = 1
            AND TRIM(COALESCE(NULLIF(customer_name, ''), name)) = ?" . $garantat . "
          LIMIT 2",
        [$name]
    );
    return count($rows) === 1 ? (int) $rows[0]['id'] : null;
}

/**
 * Cine ar mai purta numele ăsta, dacă numele s-ar scrie acum.
 *
 * `client_by_name()` de deasupra leagă NUMAI când răspunsul e unul singur, iar
 * la două conturi cu același nume tace. E purtarea cuminte — nu ghicește — dar
 * are un capăt neapărat, și nu se vede din ea: al doilea cont cu numele acela
 * nu-și strică numai lui legarea, I-O STRICĂ ȘI PRIMULUI. Clientul vechi al
 * casei, care n-a făcut nimic, se trezește că de a doua zi comenzile lui luate
 * la telefon nu-i mai intră în cont. Nu află niciodată de ce: nu se scrie nimic
 * nicăieri, fiindcă din locul unde stă codul nu s-a întâmplat nimic — pur și
 * simplu n-a fost „unul singur".
 *
 * MĂSURAT VIU pe 14 septembrie 2026, pe serverul de dezvoltare: un agent a
 * făcut cu `POST /users` un cont purtând numele „Cafeneaua Veche", al unui
 * client vechi al casei. Comanda scrisă imediat după de administrator, pe
 * numele acela, s-a întors cu `customer_id` gol. Nici administratorul, nici
 * clientul n-aveau de unde afla că se schimbase ceva.
 *
 * DE-AIA POARTA STĂ LA NAȘTEREA NUMELUI, nu la folosirea lui: se refuză al
 * doilea, ca primul să rămână întreg. Regula e „nu înrăutăți", nu „respinge
 * trecutul" — două conturi rămase omonime dinainte nu se ating, și orice altă
 * corectură pe ele merge mai departe (aceeași socoteală ca la adresa de
 * e-mail, api/routes/users.php).
 *
 * INTEROGAREA E GEAMĂNA CELEI DE SUS, literă cu literă: același `role`,
 * același `active`, aceeași expresie de nume, aceeași poartă de recunoaștere.
 * O pază care cerne altfel decât cerne cel păzit nu păzește nimic — ar refuza
 * nume care n-ar fi stricat nimic și ar lăsa să treacă tocmai pe cele care
 * strică. Cine schimbă una din ele să le schimbe pe amândouă.
 *
 * STĂ AICI, în `api/lib/`, fiindcă are TREI uși de păzit — crearea contului,
 * redenumirea lui și recunoașterea unui cont făcut pe site — iar routerul
 * încarcă un singur fișier de rute pe cerere (api/index.php).
 *
 * @param string   $nume    numele sub care ar fi strigat contul: firma, ori,
 *                          lipsă ea, numele afișat
 * @param int|null $afaraDe contul care se redenumește ori se recunoaște chiar
 *                          acum — el nu se ciocnește de sine însuși
 */
function client_name_conflict(string $nume, ?int $afaraDe = null): ?array
{
    $nume = trim($nume);
    if ($nume === '') {
        return null;
    }
    $garantat = user_origin_supported()
        ? ' AND (self_registered = 0 OR vouched_at IS NOT NULL)'
        : '';
    // `id <> ?` cu zero când n-avem pe cine scoate: numerele de cont încep de
    // la 1, deci condiția e adevărată pentru toți. Altfel ar fi trebuit două
    // interogări aproape la fel, iar a doua ar fi rămas în urmă la prima
    // corectură.
    return one(
        "SELECT id, username, name, customer_name FROM users
          WHERE role = 'client' AND active = 1
            AND TRIM(COALESCE(NULLIF(customer_name, ''), name)) = ?" . $garantat . "
            AND id <> ?
          LIMIT 1",
        [$nume, $afaraDe ?? 0]
    );
}

/**
 * Numele sub care e strigat un cont: firma, ori, lipsă ea, numele afișat.
 *
 * Scrisă o dată, fiindcă e chiar expresia din SQL-ul celor două funcții de mai
 * sus — `TRIM(COALESCE(NULLIF(customer_name, ''), name))` — și fiecare loc
 * care o rescrie de mână e un loc care poate să se depărteze de ea.
 */
function client_display_name(?string $firma, ?string $nume): string
{
    $firma = trim((string) $firma);
    return $firma !== '' ? $firma : trim((string) $nume);
}

/**
 * Ograda de clienți a unui om din echipă.
 *
 * Întoarce `null` pentru cine n-are voie să caute deloc (manager, angajat,
 * client, vizitator), altfel o pereche: `toti` pentru administrator și
 * fondator, iar pentru agentul de vânzări numărul lui, cu care se taie lista
 * la clienții aduși chiar de el.
 *
 * STĂ AICI, în `api/lib/`, nu în fișierul de rute, fiindcă routerul încarcă UN
 * SINGUR fișier de rute pe cerere (api/index.php). Regula e nevoie în două
 * locuri deodată — la căutare (api/routes/clients.php) și la legarea cererii de
 * cont (api/routes/requests.php) — iar scrisă de două ori s-ar fi despărțit
 * într-o zi, tăcut, pe partea care deschide.
 */
function client_scope_for(array $u): ?array
{
    $rol = (string) ($u['role'] ?? '');
    // `GP_ORDER_CREATE_ROLES`, nu `GP_ADMIN_ROLES`: de la 19 septembrie 2026
    // regula e „cine poate scrie comanda poate căuta clientul". Managerul a
    // intrat aici la cererea patronului — „când scrii numele clientului să ai o
    // căutare prin istoricul clienților și să-i poți alege" —, iar deschiderea
    // nu-i arată nimic nou: numele, telefonul și adresa fiecărui client îi trec
    // oricum prin mână la `GET /orders`, pe comenzile pe care le mută zilnic
    // dintr-o etapă în alta. Ce capătă e o cale de a le NIMERI, în loc să le
    // scrie din memorie — adică mai puține comenzi legate de contul greșit.
    //
    // Deschiderea atinge toate trei ușile care trec pe aici, și e bine că le
    // atinge pe toate: căutarea (`GET /clients`), alegerea unui cont ANUME
    // (`client_in_scope()`, la `POST /requests`) și legarea după nume
    // (`client_by_name_in_scope()`). Managerul putea deja, de mult, să lege
    // comanda de contul clientului scriind numele — v. lămurirea de la
    // `client_by_name_in_scope()`. Alegerea cu degetul e drumul mai SIGUR
    // dintre cele două: un nume tastat poate nimeri alt cont, un cont ales nu.
    if (in_array($rol, GP_ORDER_CREATE_ROLES, true)) {
        return ['toti' => true, 'agent' => 0];
    }
    if ($rol === 'agent_vanzari') {
        return ['toti' => false, 'agent' => (int) ($u['id'] ?? 0)];
    }

    return null;
}

/**
 * Clientul ăsta e în ograda omului ăstuia?
 *
 * Întrebarea se pune în SQL, cu exact aceeași bucată de `WHERE` pe care o
 * folosește lista — tiparul lui `referral_agent_sees_order()`
 * (api/lib/referral.php). Un client care nu există dă tot „false", deci refuz,
 * nu „nu există": agentul n-are de ce să afle prin ghicire câți clienți are casa.
 *
 * Poarta de „recunoaștere" a lui `client_by_name()` NU se pune aici, dinadins.
 * Acolo se potrivește un NUME tastat, și de-aia conturile născute pe site și
 * nerecunoscute încă sunt scoase din candidați: numele nu e o dovadă. Aici se
 * alege un cont ANUME, cu ochii pe numele, telefonul și adresa lui, de către un
 * om din casă — iar alegerea aceea e chiar dovada care lipsea. Lista îl poartă
 * totuși însemnat, ca cel care alege să știe ce alege.
 */
function client_in_scope(array $u, int $clientId): bool
{
    $sc = client_scope_for($u);
    if ($sc === null || $clientId <= 0) {
        return false;
    }

    $sql = "SELECT COUNT(*) FROM users WHERE id = ? AND role = 'client' AND active = 1";
    $par = [$clientId];
    if (!$sc['toti']) {
        $sql     .= ' AND referred_by = ?';
        $par[]    = $sc['agent'];
    }

    return (int) scalar($sql, $par) === 1;
}

/**
 * Numele tastat leagă cererea de un cont — DACĂ omul are voie la contul acela.
 *
 * Stă aici, lângă celelalte două, fiindcă regula e nevoie în DOUĂ fișiere de
 * rute deodată — `api/routes/requests.php` (cererea de pe site) și
 * `api/routes/orders.php` (comanda luată la telefon) — iar routerul încarcă un
 * singur fișier de rute pe cerere (api/index.php). Scrisă de două ori, s-ar fi
 * despărțit într-o zi, tăcut, pe partea care deschide. Exact pățania de față:
 * poarta pusă pe `customerId` n-a fost pusă și pe nume, și s-a intrat pe nume.
 *
 * CE S-A MĂSURAT, pe 14 septembrie 2026, viu, nu dedus din cod. Cu contul
 * agentului „mihai" (id 1811) și clientul „Cafeneaua Veche" (id 1809, adus de
 * nimeni, pe care `GET /clients?q=cafe` i-l ascundea cu lista goală):
 *   - `POST /requests` cu `customerId: 1809` → 403 `client_not_in_scope`;
 *   - aceeași cerere cu numele scris în clar → 201, legată de ACELAȘI cont;
 *   - `POST /orders` cu numele scris în clar → comanda 28970 legată de 1809,
 *     comision de 2,85 RON născut pe numele agentului, ȘI
 *     `users.referred_by = 1811` scris pe contul clientului PENTRU TOTDEAUNA,
 *     de `referral_link_new_user()`. Adică fiecare comandă viitoare a acelui
 *     client i-ar fi adus agentului bani, fără să mai fie nevoie de vreun cod.
 * Rândurile probei au fost șterse și contul pus la loc pe NULL.
 *
 * OGRADA E NUMAI PENTRU CINE ARE UNA — hotărârea patronului, seara de
 * 14 septembrie. `client_scope_for()` întoarce `null` pentru cine n-are
 * unealta de căutat clienți, iar `null` acolo înseamnă chiar asta, nu „n-are
 * niciun client". Citit ca ogradă goală, omul ar fi rămas fără singurul drum
 * prin care comanda luată de el la telefon ajunge în contul clientului — n-ar
 * fi avut cu ce înlocui căutarea pe care n-o are. Gaura era a agentului;
 * leacul se oprește la el.
 *
 * DE LA 19 SEPTEMBRIE 2026 asta îl privește numai pe `angajat`: managerul a
 * primit ogradă întreagă odată cu căutarea (v. `client_scope_for()`), deci
 * pentru el ramura de mai jos nu mai cade pe `null`, ci pe `toti` — și dă
 * același răspuns ca până acum, fiindcă o ogradă care cuprinde toți clienții nu
 * respinge pe niciunul. Purtarea nu s-a schimbat; s-a schimbat doar drumul.
 *
 * Tăcerea la refuz e dinadins, și se deosebește de 403-ul de pe `customerId`:
 * acolo omul a ALES un cont și merită să afle că n-are voie la el; aici a scris
 * un nume, iar un refuz pe nume ar face din formular un instrument de ghicit
 * clienții casei — scrii un nume, și răspunsul îți spune dacă firma aceea are
 * cont. Cererea intră întreagă, cu numele, telefonul și adresa ei; doar nu se
 * agață de contul altuia.
 */
function client_by_name_in_scope(?array $u, string $name): ?int
{
    $gasit = client_by_name($name);
    if ($gasit === null || $u === null) {
        return $gasit;
    }

    $sc = client_scope_for($u);
    if ($sc === null) {
        return $gasit;
    }

    return client_in_scope($u, $gasit) ? $gasit : null;
}

/**
 * Varianta, cu „copt" ca implicit când nu s-a trimis nimic.
 *
 * Atenție: o valoare *greșită* nu mai trece tăcut pe „copt" — vezi
 * `require_variant()`. Aici ajung doar cazurile în care lipsa e legitimă.
 */
/**
 * Un câmp de text din formular, verificat înainte să ajungă în baza de date.
 *
 * Fără asta, un câmp mai lung decât coloana pica pe „Data too long for column",
 * adică o excepție PDO — adică „Eroare internă" în fața clientului, cu comanda
 * pierdută. O adresă de 300 de caractere sau un nume de firmă lung nu sunt
 * greșeli de sistem: omul merită să afle ce câmp e prea lung și cât încape.
 *
 * Lungimile sunt în caractere, nu în octeți: `ă` ocupă doi octeți în utf8mb4,
 * iar MySQL numără caractere pentru VARCHAR.
 */
function text_field($v, string $eticheta, int $max, bool $obligatoriu = false): string
{
    $s = is_scalar($v) ? trim((string) $v) : '';
    // Caracterele de control (inclusiv \r\n lipite din altă pagină) nu au ce
    // căuta într-un nume sau într-o adresă; le strângem la un singur spațiu.
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? $s);

    if ($s === '') {
        if ($obligatoriu) {
            fail($eticheta . ' este obligatoriu.', 422, 'missing_field');
        }
        return '';
    }
    if (mb_strlen($s) > $max) {
        // „— text prea lung", nu „este prea lung": eticheta poate fi și
        // feminină („Mențiunea", „Descrierea"), iar adjectivul ar trebui atunci
        // acordat. Forma de aici merge cu orice etichetă, la orice gen.
        $n = mb_strlen($s);
        fail(
            $eticheta . ' — text prea lung: ' . $n . ' ' . ro_de($n) . 'caractere, maximum ' . $max . '.',
            422,
            'field_too_long'
        );
    }
    return $s;
}

function valid_variant(?string $v): string
{
    $v = trim((string) $v);
    return in_array($v, order_variants(), true) ? $v : 'copt';
}

/**
 * Varianta trimisă de client, verificată.
 *
 * Lipsa („", null) cade pe implicit. O valoare necunoscută este refuzată, nu
 * corectată în tăcere: înainte, `variant: "frozen"` — scris în engleză, cum
 * arată cheile din coșul de pe site — devenea „copt", iar comanda se
 * înregistra la celălalt preț fără ca nimeni să afle.
 */
function require_variant($v, string $implicit = 'copt'): string
{
    if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) {
        return valid_variant($implicit);
    }
    if (!is_string($v) || !in_array(trim($v), order_variants(), true)) {
        fail(
            'Variantă necunoscută: ' . (is_scalar($v) ? (string) $v : gettype($v))
              . '. Acceptate: ' . implode(', ', order_variants()) . '.',
            422,
            'bad_variant'
        );
    }
    return trim($v);
}

/**
 * O zonă de livrare primită din formular, verificată față de lista din Setări.
 *
 * Regula asta era scrisă de două ori, cuvânt cu cuvânt, în `POST /requests` și
 * în `POST /orders`. Odată cu zona ținută pe cont ar fi ajuns la patru copii —
 * iar patru copii ale aceleiași reguli se depărtează una de alta exact în ziua
 * în care contează. Rutele nu se încarcă una pe alta (api/index.php cere un
 * singur fișier din `routes/`), deci locul unei reguli împărțite e aici.
 *
 * Fără verificare, o zonă inexistentă („Centru", rămasă din prototip) trecea, iar
 * ruta șoferului o punea pe coordonatele depozitului: oprirea apărea peste
 * magazie, iar drumul ieșea calculat greșit.
 *
 * LISTA GOALĂ LASĂ ORICE SĂ TREACĂ, dinadins. Goală înseamnă „instalarea asta
 * n-a apucat să-și scrie zonele", nu „nicio zonă nu e bună": închisă, o bază
 * abia ridicată n-ar mai primi nicio comandă cu zonă, adică prima zi de lucru ar
 * fi o zi de refuzuri. Purtarea e cea de dinainte, păstrată neschimbată.
 *
 * Întoarce '' pentru „nu s-a spus", nu null: cele două rute de mai sus scriu
 * valoarea mai departe în INSERT-urile lor, iar '' e ce scriau și până acum.
 */
function zone_field($v, string $cod = 'bad_zone'): string
{
    $zone = is_scalar($v) ? trim((string) $v) : '';
    if ($zone === '') {
        return '';
    }
    $zones = delivery_zones();
    if ($zones && !in_array($zone, $zones, true)) {
        fail('Zonă necunoscută: ' . $zone, 422, $cod);
    }
    return $zone;
}

/**
 * Prețurile deja promise clientului, pe cheia „produs|variantă".
 *
 * Se citesc din rândurile care EXISTĂ: `order_request_items` la acceptarea
 * unei cereri, `order_items` la salvarea unei comenzi. Se ține și CÂTE bucăți
 * s-au promis la prețul acela, în ordinea în care au fost scrise: promisiunea
 * acoperă o cantitate, nu o marfă la nesfârșit. Când aceeași pereche are două
 * rânduri (10 la prețul vechi și 1 la cel nou, dintr-o salvare de dinainte),
 * amândouă intră în coadă și se consumă în ordine.
 *
 * @return array<string, array<int, array{qty: float, unit: float}>>
 */
function honored_prices(array $rows): array
{
    $onorate = [];
    foreach ($rows as $r) {
        $pid = (int) ($r['product_id'] ?? 0);
        // Poziția rămasă fără produs (produs șters din catalog) nu are ce
        // onora: nu se mai poate lega de nimic din ce trimite panoul.
        if ($pid <= 0) {
            continue;
        }
        $cheie = $pid . '|' . (string) ($r['variant'] ?? 'copt');
        $onorate[$cheie][] = [
            'qty'  => (float) ($r['qty'] ?? 0),
            'unit' => (float) $r['unit_price'],
        ];
    }
    return $onorate;
}

/**
 * Cât s-a raportat făcut, adunat pe pereche produs+variantă, ca să treacă peste
 * o rescriere a pozițiilor.
 *
 * Geamăna lui `honored_prices()`, cu aceeași cheie și din același motiv:
 * `PATCH /orders` șterge pozițiile și le scrie la loc, iar fără cărăușul ăsta
 * `done_qty` s-ar duce cu ele. Pierderea n-ar fi doar a unui număr — materiile
 * prime consumate pentru bucățile alea NU se întorc, deci comanda ar arăta
 * „zero făcut" peste un depozit din care făina lipsește deja, iar tura
 * următoare ar face încă o dată tot lotul.
 *
 * Aici nu e nevoie de coada de felii a prețurilor: cantitatea făcută n-are
 * preț, deci se adună. Împărțirea pe rândurile noi se face la scriere, în
 * ordine și plafonată la cantitatea fiecăruia.
 *
 * @return array<string, float>
 */
function produced_qty(array $rows): array
{
    $facut = [];
    foreach ($rows as $r) {
        $pid = (int) ($r['product_id'] ?? 0);
        // Poziția rămasă fără produs n-are cu ce se potrivi după rescriere.
        if ($pid <= 0) {
            continue;
        }
        $cheie = $pid . '|' . (string) ($r['variant'] ?? 'copt');
        $facut[$cheie] = ($facut[$cheie] ?? 0.0) + (float) ($r['done_qty'] ?? 0);
    }
    return $facut;
}

/**
 * Diferența dintre ce s-a onorat și catalogul de azi, în cuvinte, pentru jurnal.
 *
 * Fără rândul ăsta patiseria ar vinde sub prețul curent fără ca nimeni să bage
 * de seamă, iar administratorul ar afla abia la socoteala lunii, când nu mai
 * are ce corecta. Când nu s-a onorat nimic altfel decât scrie în catalog,
 * întoarce text gol și jurnalul rămâne exact cum era.
 */
function price_honored_note(array $priced): string
{
    $dif = [];
    foreach ($priced as $p) {
        $catalog = $p['catalog'] ?? null;
        // Sub jumătate de ban nu e o scumpire, ci rotunjirea zecimalelor
        // întoarse de MySQL; nu are ce căuta în jurnal.
        if ($catalog === null || abs($catalog - (float) $p['unit']) < 0.005) {
            continue;
        }
        // Cantitatea intră în notă: „onorat la 3,49" fără ea nu spune dacă
        // patiseria a lăsat din preț la zece bucăți sau la o mie, adică tocmai
        // lucrul pentru care se citește rândul din jurnal.
        $dif[] = '„' . $p['name'] . '” (' . $p['variant'] . ') × '
            . rtrim(rtrim(number_format((float) $p['qty'], 2, ',', '.'), '0'), ',')
            . ' onorat la ' . number_format((float) $p['unit'], 2, ',', '.')
            . ' lei, în catalog ' . number_format($catalog, 2, ',', '.') . ' lei';
    }
    if (!$dif) {
        return '';
    }
    // O comandă poate avea 200 de poziții cu nume de 160 de caractere, iar
    // `audit_log.detail` e TEXT: peste 65535 de octeți MySQL taie cu 1406
    // „Data too long", exact eroarea de care se ferește și `orders.ref`. Nota
    // se scrie DUPĂ commit, deci comanda ar rămâne salvată, iar omul ar primi
    // „Eroare internă" pentru o salvare care a reușit.
    if (count($dif) > 12) {
        $restul = count($dif) - 12;
        $dif    = array_slice($dif, 0, 12);
        $dif[]  = 'și încă ' . $restul . ' poziții onorate sub catalog';
    }
    return implode('; ', $dif);
}

/**
 * Pozițiile primite din corpul cererii, verificate ca număr.
 *
 * Regula — „cel puțin un produs, cel mult 200" — era scrisă de trei ori,
 * cuvânt cu cuvânt: la crearea comenzii, la rescrierea pozițiilor ei și la
 * acceptarea unei cereri. Odată cu tarifarea care nu scrie
 * (`POST /orders/quote`) ar fi ajuns la cinci copii, iar cinci copii ale
 * aceleiași reguli se depărtează una de alta exact în ziua în care contează:
 * panoul ar arăta un preț pentru un coș pe care salvarea îl refuză, sau
 * invers — și tocmai potrivirea dintre cifra de pe ecran și cea salvată e
 * lucrul pentru care există tarifarea.
 *
 * Plafonul nu e o formalitate: `price_items()` face câte o interogare pe
 * poziție, iar tarifarea se cere la fiecare modificare a formularului.
 *
 * `route_requests_create()` rămâne dinadins pe dinafară: acolo coșul GOL e
 * îngăduit — o cerere fără poziții e o cerere de ofertă — și are refuzurile
 * lui, cu alte cuvinte („Lista de produse nu are forma așteptată.",
 * „Prea multe poziții în cerere.").
 *
 * @return array<int|string, mixed>
 */
function cos_de_tarifat($items): array
{
    if (!is_array($items) || !$items) {
        fail('Comanda trebuie să conțină cel puțin un produs.', 422, 'empty_order');
    }
    if (count($items) > 200) {
        fail('Prea multe poziții în comandă.', 422, 'too_many_items');
    }
    return $items;
}

/**
 * Calculează prețurile pornind de la catalog, onorând ce s-a promis deja.
 *
 * Varianta se ia de pe fiecare poziție, iar `$variant` e doar implicitul
 * pentru pozițiile care nu o trimit. Coșul de pe site ține de la început
 * cheile separat — `frozen:3` și `baked:3` — deci un client poate cere 100
 * congelate și 20 coapte în aceeași comandă. Până acum spre server pleca o
 * singură variantă pentru tot coșul: pe ecran scria o sumă, în comandă se
 * scria alta, iar laboratorul primea altă marfă decât cea cerută.
 *
 * `$onorate` sunt prețurile deja promise clientului, adunate de
 * `honored_prices()`. O poziție care se regăsește acolo își păstrează prețul
 * de atunci pentru CÂTE bucăți s-au promis; cantitatea adăugată peste ele se
 * tarifează la prețul de azi. Fără asta, o scumpire pusă în catalog dimineața
 * rescria tăcut ce se convenise: clientul vedea 285 de lei când trimitea
 * cererea și primea comanda cu 785, iar bucățile confirmate demult se retarifau
 * și ele când administratorul adăuga o singură bucată pe o comandă veche.
 *
 * `$ingaduite` sunt produsele care trec chiar dacă au fost scoase din catalog:
 * cele aflate DEJA pe comanda care se corectează, respectiv pe cererea care se
 * acceptă. Fără lista asta, scoaterea unui produs din catalog îngheța tot ce îl
 * conținea — comanda nu se mai putea corecta deloc, nici măcar cantitatea
 * altui produs de pe ea, fiindcă pozițiile se rescriu toate și se retarifează.
 * Acolo unde se naște ceva nou — comandă luată la telefon, cerere de pe site —
 * lista rămâne goală: un produs scos din catalog nu se mai vinde.
 *
 * Prețul de azi rămâne pe fiecare poziție, în `catalog`, ca
 * `price_honored_note()` să poată spune în jurnal unde s-a onorat altceva
 * decât scrie acum.
 *
 * @param array<int, int> $ingaduite  id-uri de produse primite deși scoase din catalog
 * @return array{0: array<int, array>, 1: float}  [poziții cu preț, total]
 */
function price_items(array $items, string $variant, array $onorate = [], array $ingaduite = []): array
{
    $implicit  = valid_variant($variant);
    $ingaduite = array_map('intval', array_values($ingaduite));
    $priced    = [];
    $total     = 0.0;

    foreach ($items as $it) {
        // Numerele se cer numere, nu se storc din ce-a venit.
        //
        // `(int)` pe un tablou nevid dă 1 în PHP, iar `(float)` la fel, fără
        // nicio eroare. Deci `productId: [22, 21]` — două id-uri rămase într-un
        // tablou după o modificare a coșului — devenea tăcut produsul cu id 1,
        // iar `qty: true` devenea o bucată. Verificările de dedesubt nu se mai
        // declanșau: valorile ajungeau la ele deja „valide". Serverul răspundea
        // 201 „comandă primită", clientul primea confirmare pentru ce ceruse, iar
        // în laborator se făcea altceva, la alt preț.
        $pidBrut = $it['productId'] ?? null;
        if (!is_int($pidBrut) && !(is_string($pidBrut) && preg_match('/^\d+$/', trim($pidBrut)))) {
            fail('Produsul unei poziții nu e un număr. Reîncarcă pagina și alege produsul din listă.',
                422, 'bad_product');
        }
        $pid = (int) $pidBrut;

        $qtyBrut = $it['qty'] ?? null;
        if (!is_int($qtyBrut) && !is_float($qtyBrut)
            && !(is_string($qtyBrut) && is_numeric(trim($qtyBrut)))) {
            fail('Cantitatea unei poziții nu e un număr.', 422, 'bad_qty');
        }
        // Rotunjită la două zecimale ÎNAINTE de verificare, cum o ține și
        // coloana (DECIMAL(10,2)): 0,004 trecea de „> 0", MySQL o rotunjea la
        // 0,00 și rămânea o poziție cu cantitate zero; 0,125 se salva 0,13
        // dar totalul se calcula din 0,125 — linia și totalul nu mai băteau.
        $qty = round((float) $qtyBrut, 2);
        if ($qty <= 0 || $qty > 10000) {
            fail('Cantitate invalidă.', 422, 'bad_qty');
        }

        $vi = require_variant($it['variant'] ?? null, $implicit);

        // Căutarea nu mai cere `active = 1`. Rândul rămâne în tabel și după
        // scoaterea din catalog — DELETE /products îl șterge de tot doar când
        // nu apare în nicio comandă și în nicio cerere —, deci prețul lui se
        // poate recalcula. Dreptul de a-l pune pe hârtie se hotărăște dedesubt.
        $p = one('SELECT id, name, price_frozen, price_baked, active FROM products WHERE id = ?', [$pid]);
        if (!$p) {
            // Aici nu avem un nume de arătat — rândul nu mai e în tabel —, deci
            // măcar spunem ce e de făcut. Altfel operatorul rămâne cu un număr
            // pe ecran și cu o comandă pe care n-o poate salva.
            fail(
                'Produs inexistent: ' . $pid
                    . '. Rândul nu mai e în catalog — reîncarcă pagina și alege produsul din listă.',
                422,
                'bad_product'
            );
        }
        if ((int) $p['active'] === 0 && !in_array($pid, $ingaduite, true)) {
            fail(
                'Produsul „' . $p['name'] . '” a fost scos din catalog și nu se mai poate adăuga.'
                    . ' Repune-l din pagina Produse sau alege altul.',
                422,
                'bad_product'
            );
        }

        $raw     = $vi === 'congelat' ? $p['price_frozen'] : $p['price_baked'];
        $catalog = $raw === null ? null : (float) $raw;

        // Prețul promis are întâietate în fața catalogului, dar NUMAI pentru
        // câte bucăți s-au promis. Brutăria care a confirmat acum două
        // săptămâni 10 ștrudele la 3,49 lei le primește pe cele 10 la 3,49; a
        // unsprezecea, cerută azi la telefon, se plătește cu prețul de azi.
        // Altfel „mai pune unul" ar fi devenit o portiță: clientul cerea o
        // bucată înainte de scumpire și corecta apoi comanda la o mie, iar
        // patiseria livra toată mia la prețul de anul trecut, fără ca cineva să
        // apese vreun buton care să spună asta.
        //
        // Bucățile promise și cele adăugate ies ca poziții separate, fiindcă au
        // prețuri diferite: `order_items.unit_price` ține un singur preț pe
        // rând, iar un preț mediu inventat ar face ca înmulțirea de pe factură
        // să nu mai dea totalul.
        $cheie  = $pid . '|' . $vi;
        $ramas  = $qty;
        $felii  = [];

        if (!isset($onorate[$cheie])) {
            $onorate[$cheie] = [];
        }
        while ($ramas > 0 && $onorate[$cheie]) {
            $promis = array_shift($onorate[$cheie]);
            $ia     = min($ramas, (float) $promis['qty']);
            if ($ia <= 0) {
                continue;
            }
            $rest = (float) $promis['qty'] - $ia;
            if ($rest > 0) {
                array_unshift($onorate[$cheie], ['qty' => $rest, 'unit' => $promis['unit']]);
            }
            $felii[] = ['qty' => $ia, 'unit' => (float) $promis['unit']];
            $ramas  -= $ia;
        }

        // Ce trece peste cantitatea promisă e marfă nouă și se tarifează la zi.
        // Aici — și numai aici — lipsa prețului oprește salvarea: pentru marfa
        // deja convenită prețul e cel de atunci, nu unul care lipsește azi.
        if ($ramas > 0) {
            if ($catalog === null) {
                fail('Produsul „' . $p['name'] . '” nu are preț pentru varianta „' . $vi . '”.', 422, 'missing_price');
            }
            $felii[] = ['qty' => $ramas, 'unit' => $catalog];
        }

        // Feliile cu același preț se adună la loc. În cazul obișnuit — nimeni
        // n-a umblat la catalog între cerere și salvare — prețul promis E cel
        // din catalog, deci rămâne o singură poziție, exact ca înainte. Se
        // despart numai acolo unde prețul chiar diferă, fiindcă
        // `order_items.unit_price` ține un singur preț pe rând, iar un preț
        // mediu inventat ar face ca înmulțirea de pe factură să nu dea totalul.
        foreach ($felii as $f) {
            $unit  = round((float) $f['unit'], 2);
            $ultim = $priced ? array_key_last($priced) : null;
            if ($ultim !== null
                && $priced[$ultim]['id'] === (int) $p['id']
                && $priced[$ultim]['variant'] === $vi
                && abs($priced[$ultim]['unit'] - $unit) < 0.005) {
                $priced[$ultim]['qty'] += (float) $f['qty'];
            } else {
                $priced[] = [
                    'id' => (int) $p['id'], 'name' => $p['name'],
                    'qty' => (float) $f['qty'], 'unit' => $unit, 'variant' => $vi,
                    'catalog' => $catalog,
                ];
            }
            $total += $unit * (float) $f['qty'];
        }
    }

    // Coloana `total` e DECIMAL(10,2): peste 99.999.999,99 INSERT-ul pica cu
    // 22003 și omul primea „Eroare internă." Nicio comandă adevărată nu ajunge
    // aici; e doar plasa pentru 200 de poziții × 10.000 bucăți.
    if ($total > 99999999.99) {
        fail('Totalul comenzii depășește ce se poate înregistra. Împarte comanda în mai multe.', 422, 'total_too_big');
    }
    return [$priced, $total];
}

/**
 * Varianta comenzii, dedusă din pozițiile ei.
 *
 * Coloana `orders.variant` rămâne, ca să nu se rupă nimic din ce o citește,
 * dar adevărul stă acum pe poziții. Când toate pozițiile au aceeași variantă,
 * comanda o poartă pe aceea; când sunt amestecate, „mixt".
 */
function order_variant_of(array $priced): string
{
    $v = array_values(array_unique(array_map(
        static fn($p) => $p['variant'] ?? 'copt',
        $priced
    )));
    return count($v) === 1 ? $v[0] : 'mixt';
}

/**
 * Prima comandă NEANULATĂ a fiecărui cont, dintr-o singură interogare.
 *
 * Materia primă a insignei de client. Se cere O DATĂ pentru tot lotul, ca și
 * pozițiile din `attach_items()`, fiindcă socoteala făcută în pagină ar minți:
 * registrul e tăiat la 500 de rânduri pe zi, deci „a mai comandat" s-ar fi
 * judecat după ce a încăput pe ecran, nu după ce are contul. E chiar paguba pe
 * care Depozitul a reparat-o mutând „Rezervat" pe server.
 *
 * ANULATELE NU SE NUMĂRĂ: o comandă anulată nu face din nimeni client fidel,
 * iar un „#1" mutat de pe comanda adevărată pe una anulată ar fi o minciună la
 * prima vedere. Numele etapei NU se scrie în cod — se ia din Setări, prin
 * `status_cancelled()`, ca la `route_orders_counts()` și la Statistici: o casă
 * care și-a redenumit etapele n-ar mai fi fost numărată cum trebuie. Șirul gol
 * înseamnă „instalarea asta n-are etapă de anulare", iar atunci condiția
 * lipsește cu totul din interogare: trimis ca parametru, '' ar fi scos tăcut
 * din numărătoare comenzile cu etapă goală.
 *
 * Se judecă după `id`, nu după `created_at`: două comenzi luate în aceeași
 * secundă au aceeași clipă, dar nu același rând, iar atunci amândouă ar fi
 * ieșit „prima".
 *
 * FĂRĂ FUNCȚII DE FEREASTRĂ (`ROW_NUMBER() OVER …`), oricât ar fi de potrivite:
 * schema declară „MySQL 5.7+ / MariaDB 10.3+" (`sql/001_schema.sql:2`), iar
 * MariaDB 10.3 nu le are. O interogare care merge în producție și pică la
 * dezvoltare se descoperă la cea mai proastă oră cu putință.
 *
 * @param array<int, int|null> $conturi
 * @return array<int, int>  customer_id => id-ul celei mai vechi comenzi neanulate
 */
function primele_comenzi(array $conturi): array
{
    $conturi = array_values(array_unique(array_filter(array_map('intval', $conturi))));
    if (!$conturi) {
        return [];
    }

    $ph   = implode(',', array_fill(0, count($conturi), '?'));
    $unde = "customer_id IN ($ph)";
    $vals = $conturi;

    $anulata = status_cancelled();
    if ($anulata !== '') {
        $unde  .= ' AND status <> ?';
        $vals[] = $anulata;
    }

    $primele = [];
    foreach (all("SELECT customer_id, MIN(id) AS primul FROM orders WHERE $unde GROUP BY customer_id", $vals) as $r) {
        $primele[(int) $r['customer_id']] = (int) $r['primul'];
    }
    return $primele;
}

/**
 * Rangul clientului pe o comandă sau pe o cerere: ce insignă i se cuvine.
 *
 * Trei valori, atât:
 *   `fara-cont` — comanda nu e legată de niciun cont. Și comanda al cărei cont
 *                 a fost șters ajunge aici: `customer_id` e pus pe NULL de
 *                 `ON DELETE SET NULL`, iar comanda chiar nu mai e a nimănui.
 *                 Nu se încearcă recunoașterea după nume — potrivirea pe nume
 *                 e tocmai ce păzește `client_by_name()`.
 *   `prima`     — contul n-are nicio comandă neanulată mai veche decât asta.
 *   `revenit`   — are.
 *
 * Pentru o CERERE încă neacceptată `$comandaId` e null: atunci întrebarea e
 * doar dacă omul a mai comandat vreodată. Cererea deja acceptată își poartă
 * comanda (`order_requests.order_id`) și primește insigna comenzii ei, ca să
 * nu scrie „a doua" în fila de cereri și „prima" în registru.
 *
 * Când prima comandă neanulată a contului e chiar asta, iese `prima`; de aceea
 * `<=`, nu `<`. Iar o comandă anulată se judecă după ce a fost înaintea ei: un
 * cont a cărui primă comandă a fost anulată poate purta „#1" pe amândouă, și
 * asta e adevărat — niciuna n-a avut una adevărată înaintea ei.
 *
 * @param array<int, int> $primele  de la `primele_comenzi()`
 */
function rang_client(?int $contId, ?int $comandaId, array $primele): string
{
    if ($contId === null) {
        return 'fara-cont';
    }
    $primul = $primele[$contId] ?? null;
    if ($primul === null) {
        return 'prima';
    }
    return $comandaId !== null && $comandaId <= $primul ? 'prima' : 'revenit';
}

/**
 * Numele oamenilor care au scris comenzile din lot.
 *
 * O SINGURĂ interogare pe tot lotul, ca `primele_comenzi()` de mai sus și ca
 * pozițiile din `attach_items()`. Un `SELECT` pe rând ar fi însemnat cinci sute
 * de drumuri la bază pentru o listă de-o zi — chiar paguba pe care lotul
 * rangului a ocolit-o.
 *
 * `orders.created_by` se scrie din 001 pe amândouă căile prin care se naște o
 * comandă (`route_orders_create()` și `route_requests_accept()`), dar până azi
 * nu-l citea nimeni.
 *
 * @param array<int, int|null> $conturi
 * @return array<int, string>  id de cont => numele lui
 */
function autorii_comenzilor(array $conturi): array
{
    $conturi = array_values(array_unique(array_filter(array_map('intval', $conturi))));
    if (!$conturi) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($conturi), '?'));
    $autori = [];
    foreach (all("SELECT id, name FROM users WHERE id IN ($ph)", $conturi) as $r) {
        $autori[(int) $r['id']] = (string) $r['name'];
    }
    return $autori;
}

/**
 * Dosarul scurt al unui pumn de clienți: câte comenzi, ultima, cât a lăsat.
 *
 * ÎNCĂRCĂTOR PE LOT, ca `autorii_comenzilor()` de mai sus și din aceeași
 * pricină: căutarea întoarce până la doisprezece clienți, iar o socoteală pe
 * rând ar fi însemnat douăzeci și patru de interogări la FIECARE literă tastată
 * în caseta de nume. Două interogări pentru tot lotul, oricâți ar fi.
 *
 * DE CE DOUĂ ȘI NU UNA: prima numără și adună, a doua aduce ultima comandă
 * întreagă. Puse laolaltă, ar fi cerut o funcție de fereastră
 * (`ROW_NUMBER() OVER`), pe care n-o are orice MySQL pe care ar putea sta casa;
 * așa, amândouă sunt SQL pe care îl înțelege și unul vechi.
 *
 * „ULTIMA" ÎNSEAMNĂ ULTIMA SCRISĂ (`MAX(id)`), nu ultima zi de livrare. Omul de
 * la telefon întreabă „ce mi-a cerut data trecută?", iar răspunsul e comanda pe
 * care a scris-o cel mai recent — chiar dacă e pentru o zi care n-a venit încă.
 * Din ea se iau adresa, zona și intervalul, fiindcă tocmai ele se repetă.
 *
 * COMENZILE ANULATE NU SE NUMĂRĂ. Un client cu trei comenzi anulate n-a
 * cumpărat nimic, iar o adresă luată de pe o comandă anulată e chiar cea care
 * n-a mers. Etapa de anulare vine din Setări (`status_cancelled()`), nu e
 * scrisă în cod.
 */
function dosare_clienti(array $conturi): array
{
    $conturi = array_values(array_unique(array_filter(array_map('intval', $conturi))));
    if (!$conturi) {
        return [];
    }

    $ph      = implode(',', array_fill(0, count($conturi), '?'));
    $anulata = status_cancelled();
    $dosare  = [];

    foreach (all(
        "SELECT customer_id, COUNT(*) AS n, COALESCE(SUM(total), 0) AS tot
           FROM orders WHERE customer_id IN ($ph) AND status <> ?
          GROUP BY customer_id",
        array_merge($conturi, [$anulata])
    ) as $r) {
        $dosare[(int) $r['customer_id']] = [
            'orders'  => (int) $r['n'],
            'spent'   => (float) $r['tot'],
            'day'     => '',
            'slot'    => '',
            'zone'    => '',
            'address' => '',
            'fulfillment' => '',
        ];
    }

    foreach (all(
        "SELECT o.customer_id, o.day, o.slot, o.zone, o.address, o.fulfillment
           FROM orders o
           JOIN (SELECT customer_id, MAX(id) AS ultima
                   FROM orders WHERE customer_id IN ($ph) AND status <> ?
                  GROUP BY customer_id) x ON x.ultima = o.id",
        array_merge($conturi, [$anulata])
    ) as $r) {
        $id = (int) $r['customer_id'];
        if (!isset($dosare[$id])) {
            continue;
        }
        $dosare[$id]['day']         = (string) ($r['day'] ?? '');
        $dosare[$id]['slot']        = (string) ($r['slot'] ?? '');
        $dosare[$id]['zone']        = (string) ($r['zone'] ?? '');
        $dosare[$id]['address']     = (string) ($r['address'] ?? '');
        $dosare[$id]['fulfillment'] = (string) ($r['fulfillment'] ?? '');
    }

    return $dosare;
}

/**
 * Cine a scris comanda: numele lui, sau null dacă nu se poate ști.
 *
 * Trei feluri de null, toate adevărate și niciunul o greșeală de arătat:
 *   - comenzile de dinaintea coloanei, care n-au `created_by`;
 *   - contul șters între timp — `created_by` n-are cheie străină, deci numărul
 *     rămâne pe comandă, dar nu mai are pereche în `users`;
 *   - cererile trimise de pe site, care nu sunt scrise de nimeni din casă.
 *
 * Pagina nu arată nimic în locurile astea. Un „—" ar fi spus că lipsește ceva.
 *
 * @param array<int, string> $autori  de la `autorii_comenzilor()`
 */
function autor_comanda(?int $contId, array $autori): ?string
{
    if ($contId === null) {
        return null;
    }
    return $autori[$contId] ?? null;
}

/* ------------------------------------- jetonul cererii de pe site -- */

/**
 * A fost importată migrarea 019, cea a jetonului de cerere?
 *
 * Aceeași poartă ca `lab_supported()` și `user_origin_supported()`, din același
 * motiv: codul de pe server poate fi mai nou decât baza lui — din
 * `--doar-fisiere`, dintr-un import oprit la mijloc, ori din plasturele pus de
 * mână. În fereastra aceea, o interogare pe `request_claims` ar cădea cu „1146
 * Table doesn't exist", iar vizitatorul care tocmai a apăsat „Trimite comanda"
 * ar citi „Eroare internă." în loc să-și vadă comanda plecată.
 *
 * Cade ÎNCHIS, spre deosebire de `user_origin_supported()`: fără tabel nu se
 * naște niciun jeton, iar cererea pleacă exact ca ieri. Închis aici înseamnă
 * „ca înainte", deci e alegerea sigură.
 */
function request_claim_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        return $ok = ((int) scalar(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'request_claims'"
        ) === 1);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica jetoanele de cerere: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * A fost importată migrarea 022, cea a cererii scrise de personal?
 *
 * Aceeași poartă ca `request_claim_supported()` de deasupra și ca
 * `promo_supported()`, din același motiv: fișierele urcă pe server înaintea
 * importului, iar în fereastra aceea o scriere pe coloanele noi ar cădea cu
 * „Unknown column" — pe `POST /requests`, adică tocmai în fața vizitatorului
 * care apasă „Trimite comanda".
 *
 * TOATE PATRU SAU NICIUNA, ca la `promo_supported()`. Migrarea le aduce
 * împreună, deci o bază cu trei din patru e o migrare oprită la mijloc, nu o
 * stare în care s-ar putea merge mai departe cu grijă. Numărate una câte una,
 * fiecare scriere ar fi cerut poarta ei, iar cea uitată ar fi căzut exact pe
 * ruta publică.
 *
 * Cade ÎNCHIS: fără coloane, cererea pleacă întocmai ca ieri — fără mod de
 * onorare, fără grabă, fără autor —, iar acceptarea cade pe
 * `default_fulfillment()`, cum a făcut dintotdeauna pentru cele de pe site.
 * Ce se pierde e alegerea agentului, și se pierde ZGOMOTOS: pagina „Verificare"
 * numește coloanele lipsă pe nume (api/verifica.php).
 */
function request_staff_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        return $ok = ((int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
                AND COLUMN_NAME IN ('fulfillment', 'urgent', 'timed', 'created_by')"
        ) === 4);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica cererile scrise de personal: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Naște jetonul unei cereri și îl întoarce ÎN CLAR, o singură dată.
 *
 * În bază rămâne numai sha256-ul lui (`token_hash()`), ca la resetarea de
 * parolă: jetonul întreg trăiește o clipă, în răspunsul către browserul care
 * tocmai a trimis cererea. Nu pleacă pe e-mail și nu se scrie nicăieri
 * altundeva — e singurul jeton al casei care nu părăsește fila care l-a cerut.
 *
 * Întoarce null dacă nu se poate, și atunci cererea pleacă exact ca până azi:
 * omul a apăsat ca să comande, nu ca să-și facă cont, iar o piedică la jeton
 * n-are voie să-i oprească comanda.
 */
function request_claim_new(int $requestId, int $minute): ?string
{
    if (!request_claim_supported()) {
        return null;
    }
    try {
        $jeton = random_token();
        q(
            'INSERT INTO request_claims (request_id, token_hash, expires_at)
             VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? MINUTE)',
            [$requestId, token_hash($jeton), $minute]
        );
        return $jeton;
    } catch (Throwable $e) {
        error_log('[gp] jetonul cererii ' . $requestId . ' nu s-a putut scrie: ' . $e->getMessage());
        return null;
    }
}

/**
 * Cererea din spatele unui jeton, FĂRĂ să-l stingă.
 *
 * De aici își umple formularul de înscriere casetele: numele, telefonul,
 * e-mailul, adresa și zona scrise în comandă. Datele NU pot călători prin
 * adresă — și nici n-ar avea voie —, iar `sessionStorage` nu trece nici el:
 * „Site Public" stă pe goldenpastry.ro, „Intrare" pe clienti.goldenpastry.ro,
 * adică două origini deosebite. Prin adresă trece numai jetonul, care e un
 * secret fără înțeles, ca linkul de parolă nouă; datele le dă serverul, celui
 * care are jetonul.
 *
 * Nu stinge nimic: omul poate reîncărca pagina de înscriere de zece ori fără
 * să-și piardă legătura cu comanda.
 *
 * @return array|null  rândul cererii, sau null dacă jetonul nu e (mai) bun
 */
function request_claim_peek(string $jeton): ?array
{
    if (!request_claim_supported() || !preg_match('/^[0-9a-f]{64}$/', $jeton)) {
        return null;
    }
    $c = one(
        'SELECT request_id FROM request_claims
          WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
        [token_hash($jeton)]
    );
    return $c ? one('SELECT * FROM order_requests WHERE id = ?', [(int) $c['request_id']]) : null;
}

/**
 * Stinge jetonul și leagă de contul nou cererea — și comanda născută din ea.
 *
 * Întoarce id-ul cererii legate, sau null dacă n-a legat nimic. NU aruncă
 * niciodată și nu oprește nimic: contul se face oricum. Omul a apăsat „Creează
 * cont", iar o socoteală de legături n-are voie să-i refuze contul.
 *
 * `client_by_name()` rămâne neatinsă. Jetonul e o dovadă de altă natură —
 * „browserul ăsta a trimis cererea asta" — nu o lărgire a potrivirii după nume:
 * dă o singură cerere, nimic retroactiv și nimic viitor, iar contul rămâne
 * `self_registered = 1` cu `vouched_at` NULL. Recunoașterea casei rămâne o
 * hotărâre de om.
 */
function request_claim_use(string $jeton, int $userId): ?int
{
    if (!request_claim_supported() || !preg_match('/^[0-9a-f]{64}$/', $jeton)) {
        return null;
    }

    $pdo     = db();
    $propriu = !$pdo->inTransaction();
    try {
        if ($propriu) {
            $pdo->beginTransaction();
        }

        // Stingerea ȘI verificarea, într-o singură instrucțiune. Două file care
        // apasă deodată: prima ia zăvorul rândului, a doua îl găsește cu
        // `used_at` scris și nu mai prinde nimic — deci una leagă, cealaltă nu,
        // fără eroare pe ecran și fără dublare. Despărțite într-un SELECT și un
        // UPDATE, amândouă ar fi văzut jetonul liber.
        $stins = q(
            'UPDATE request_claims SET used_at = UTC_TIMESTAMP(), used_by = ?
              WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            [$userId, token_hash($jeton)]
        )->rowCount();
        if ($stins !== 1) {
            if ($propriu) {
                $pdo->commit();
            }
            return null;
        }

        $c     = one('SELECT request_id FROM request_claims WHERE token_hash = ?', [token_hash($jeton)]);
        $reqId = (int) ($c['request_id'] ?? 0);
        $req   = $reqId
            ? one('SELECT id, order_id, customer_id FROM order_requests WHERE id = ?', [$reqId])
            : null;
        if (!$req) {
            if ($propriu) {
                $pdo->commit();
            }
            return null;
        }

        $legat = false;

        // Cererea deja acceptată și-a născut comanda, iar „Comanda mea" se uită
        // la `orders.customer_id` — deci saltul ăsta e tocmai ce face jetonul de
        // folos când patronul a fost mai iute decât omul.
        if ($req['order_id'] !== null) {
            $legat = q(
                'UPDATE orders SET customer_id = ? WHERE id = ? AND customer_id IS NULL',
                [$userId, (int) $req['order_id']]
            )->rowCount() === 1;
            if (!$legat) {
                // Comanda e deja a altui cont: la acceptare, administratorul a
                // corectat numele, iar `client_by_name()` a legat-o de contul
                // acela. Atunci NU se leagă nici cererea. O cerere a unui cont
                // cu comanda ei a altuia ar fi mai rea decât nimic — contul nou
                // ar vedea, cu preț cu tot, o comandă pe care casa a dat-o
                // altcuiva. Jetonul rămâne stins: a fost folosit.
                if ($propriu) {
                    $pdo->commit();
                }
                return null;
            }
        }

        // `AND customer_id IS NULL` e plasa: jetonul LEAGĂ CE E NELEGAT, nu
        // mută ce e legat.
        if (q(
            'UPDATE order_requests SET customer_id = ? WHERE id = ? AND customer_id IS NULL',
            [$userId, $reqId]
        )->rowCount() === 1) {
            $legat = true;
        }

        if ($propriu) {
            $pdo->commit();
        }
        return $legat ? $reqId : null;
    } catch (Throwable $e) {
        if ($propriu && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[gp] jetonul cererii nu s-a putut folosi: ' . $e->getMessage());
        return null;
    }
}

/** Atașează pozițiile la comenzi, printr-o singură interogare. */
/**
 * @param bool $cuRecomandare Trece și `referralCode` în JSON.
 * @param bool $cuAutor       Trece și `creatDe` — numele celui care a scris
 *                            comanda. Numai spre personal, niciodată spre
 *                            client: e numele unui angajat, nu al lui.
 *
 * Implicit NU, fiindcă printre apelanți e și lista clientului. Cine l-a
 * adus în casă și cât câștigă acela din el e o înțelegere între patron și
 * agent — aceeași regulă ca la `commission`, doar că aici steagul e pe
 * funcție, nu un câmp adăugat pe urmă de rută.
 *
 * Pus pe adevăr, câmpul e cerut de formularul de editare: fără el pagina
 * nu putea nici măcar ARĂTA codul scris pe comandă, darămite să-l
 * corecteze. Lipsește (rămâne null) pe o instalare fără migrarea 008 —
 * coloana nu există, iar `$o[...] ?? null` o trece fără să se plângă.
 */
function attach_items(array $orders, bool $cuRecomandare = false, bool $cuAutor = false): array
{
    $orders = array_values(array_filter($orders));
    if (!$orders) {
        return [];
    }

    $ids  = array_map(static fn($o) => (int) $o['id'], $orders);
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $rows = all("SELECT * FROM order_items WHERE order_id IN ($ph) ORDER BY id", $ids);

    $byOrder = [];
    foreach ($rows as $r) {
        $byOrder[(int) $r['order_id']][] = [
            // Numărul poziției, nu al produsului: raportarea producției se face pe
            // rând, iar același produs poate fi pe două rânduri (bucățile la
            // prețul promis și cele adăugate azi). Indexul din tablou nu e o
            // adresă — un PATCH rescrie pozițiile și le schimbă ordinea.
            'id'        => (int) $r['id'],
            'productId' => $r['product_id'] === null ? null : (int) $r['product_id'],
            'name'      => $r['name'],
            'qty'       => (float) $r['qty'],
            'doneQty'   => (float) ($r['done_qty'] ?? 0),
            'unitPrice' => (float) $r['unit_price'],
            'variant'   => $r['variant'] ?? 'copt',
        ];
    }

    // Al câtelea client e omul de pe comandă — o singură interogare pe tot
    // lotul, ca și pozițiile de mai sus.
    //
    // Se socotește ÎNTOTDEAUNA, nu în spatele unui steag ca `$cuRecomandare`:
    // steagul acela ascunde o înțelegere de bani între patron și agent, iar
    // rangul n-are ce ascunde — clientul își vede propriul rang pe propriile
    // comenzi. Un steag în plus ar fi însemnat o insignă care lipsește de pe o
    // singură pagină, și tocmai de pe aceea de care era nevoie.
    $primele = primele_comenzi(array_map(
        static fn($o) => $o['customer_id'] === null ? 0 : (int) $o['customer_id'],
        $orders
    ));

    // Cine a scris comanda — tot o singură interogare pe lot.
    //
    // DE CE ARE STEAG AL LUI, și nu merge pe `$cuRecomandare` deși azi amândouă
    // înseamnă „cel care se uită nu e clientul". Sunt două secrete deosebite:
    // acela ascunde o înțelegere de bani între patron și agent, ăsta ascunde
    // NUMELE unui angajat de ochii cumpărătorului. Cine cumpără n-are de ce să
    // afle care om din casă i-a scris comanda. Puse pe același steag, prima
    // schimbare a unuia l-ar fi mutat tăcut și pe celălalt.
    //
    // Nu se socotește „întotdeauna", cum se face rangul: rangul e despre omul
    // care se uită și el și-l poate vedea pe-al lui, autorul e despre altcineva.
    $autori = $cuAutor
        ? autorii_comenzilor(array_map(
            static fn($o) => isset($o['created_by']) && $o['created_by'] !== null ? (int) $o['created_by'] : 0,
            $orders
        ))
        : [];

    return array_map(static function (array $o) use ($byOrder, $cuRecomandare, $cuAutor, $primele, $autori) {
        return [
            'id'           => (int) $o['id'],
            'seq'          => (int) $o['seq'],
            'ref'          => $o['ref'] ?: ('#' . (1000 + (int) $o['seq'])),
            'day'          => $o['day'],
            'slot'         => $o['slot'],
            'zone'         => $o['zone'],
            'status'       => $o['status'],
            'fulfillment'  => $o['fulfillment'],
            'channel'      => $o['channel'],
            'customerId'   => $o['customer_id'] === null ? null : (int) $o['customer_id'],
            // Insigna de client: `fara-cont`, `prima` sau `revenit`.
            'rangClient'   => rang_client(
                $o['customer_id'] === null ? null : (int) $o['customer_id'],
                (int) $o['id'],
                $primele
            ),
            // Cine a scris-o. Null pentru cine nu e din casă — v. steagul de sus.
            'creatDe'      => $cuAutor
                ? autor_comanda(
                    isset($o['created_by']) && $o['created_by'] !== null ? (int) $o['created_by'] : null,
                    $autori
                )
                : null,
            'customerName' => $o['customer_name'],
            'phone'        => $o['phone'],
            'address'      => $o['address'],
            'notes'        => $o['notes'],
            'urgent'       => (bool) $o['urgent'],
            'timed'        => (bool) $o['timed'],
            'total'        => (float) $o['total'],
            'routeSeq'     => isset($o['route_seq']) && $o['route_seq'] !== null ? (int) $o['route_seq'] : null,
            'stockSynced'  => !empty($o['stock_synced']),
            'variant'      => $o['variant'] ?? 'copt',
            'requestId'    => $o['request_id'] === null ? null : (int) $o['request_id'],
            'referralCode' => $cuRecomandare ? ($o['referral_code'] ?? null) : null,
            'createdAt'    => $o['created_at'],
            'updatedAt'    => $o['updated_at'],
            'items'        => $byOrder[(int) $o['id']] ?? [],
        ];
    }, $orders);
}

/**
 * Întoarce în depozit marfa scăzută pentru o comandă și îi scoate marcajul.
 *
 * `orders.stock_synced` se punea pe 1 la scădere și nu se mai lua niciodată
 * jos, deci zăvorul mergea într-un singur sens. Se vedea în două feluri, la
 * aceeași tejghea: clientul suna și mărea comanda de la 10 la 40 de bucăți,
 * iar a doua apăsare pe „Scade comenzile" răspundea „0 comenzi, 0 bucăți" —
 * depozitul rămânea pe vecie cu 10 scăzute pentru o marfă de 40; iar o comandă
 * deja scăzută trecută pe „Anulată" lăsa marfa ieșită din evidență, deși ea
 * era în rafturi. În ambele cazuri registrul nu se mai putea îndrepta din
 * aplicație.
 *
 * Returul se scrie ca mișcări de INTRARE, nu prin ștergerea ieșirilor: stocul
 * curent nu are coloană proprie, se calculează din registru
 * (`SUM(qty * direction)`), iar o corectură ștearsă din el ar face cifra de pe
 * ecran de neexplicat. Așa rămâne scris rând cu rând ce a ieșit și ce s-a
 * întors, cu motivul alături.
 *
 * Ce se întoarce se citește din REGISTRU, pe `stock_moves.order_id`: marfa se
 * întoarce exact în raftul din care a ieșit, oricât s-ar fi schimbat depozitul
 * între timp. Nu ne legăm de textul din `note` („#1042 Restaurant Bistro"): și
 * referința, și numele clientului se schimbă din același formular care cere
 * returul. Pentru comenzile scăzute înainte de reparație, care n-au `order_id`
 * în registru, mai există o plasă care recalculează din pozițiile comenzii —
 * numai pentru ea funcția trebuie chemată ÎNAINTE ca pozițiile să fie rescrise.
 *
 * Se cheamă din tranzacția apelantului: rândul comenzii se blochează cu
 * `FOR UPDATE`, ca două salvări simultane pe aceeași comandă — două tablete,
 * un dublu-clic — să nu întoarcă marfa de două ori. Blocarea e și singura
 * poartă a întrebării „era chiar scăzută?": pusă în afara tranzacției, din
 * rândul luat la intrarea în rută, răspunsul poate fi deja vechi — între
 * citire și tranzacție cineva apasă „Scade comenzile", iar salvarea rescrie
 * pozițiile fără să mai întoarcă nimic.
 *
 * @return int  câte mișcări de retur s-au scris (0 = comanda nu era scăzută)
 */
function stock_unsync_order(int $orderId, string $motiv, ?int $userId): int
{
    $o = one('SELECT id, ref, customer_name, stock_synced FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
    if (!$o || empty($o['stock_synced'])) {
        return 0;
    }

    // Întâi întrebăm registrul dacă ȘTIE comanda asta.
    //
    // Nu ne uităm la sold, ci la existența unei ieșiri însemnate: un sold zero
    // înseamnă „s-a întors deja", nu „nu știu nimic". Confundate, comenzile
    // scăzute înainte de reparație ar fi căzut pe ghicit la FIECARE ciclu, la
    // nesfârșit — ieșirea lor veche n-are `order_id`, deci perechea s-ar aduna
    // mereu la zero. (Din același motiv returul de pe plasă se scrie nemarcat,
    // mai jos: un picior însemnat și celălalt nu ar strica tocmai socoteala.)
    $stieRegistrul = (bool) scalar(
        'SELECT 1 FROM stock_moves WHERE order_id = ? AND reason = ? LIMIT 1',
        [$orderId, 'Comandă client']
    );

    if ($stieRegistrul) {
        // De unde se întoarce marfa: din REGISTRU, nu din pozițiile comenzii.
        //
        // Până acum returul refăcea potrivirea poziție → articol cu aceeași
        // subinterogare ca scăderea. Aceeași literă, dar la alt moment:
        // scăderea se apasă marți, iar comanda iese din „Predată" joi. Între
        // cele două zile depozitul se schimbă la o corectură obișnuită —
        // articolul fusese făcut cu unitatea greșită, depozitarul îl scoate din
        // listă și pune altul pe același produs. La retur, ordonarea îl trimitea
        // pe cel vechi la urmă și alegea raftul NOU: opt croasante ieșeau
        // dintr-un raft și se întorceau în altul. Rămâneau două cifre greșite pe
        // vecie, iar `stock_synced = 0` spunea pe ecran că registrul s-a
        // îndreptat. Nici de găsit nu se mai puteau: cele două rânduri nu erau
        // legate prin nimic.
        //
        // Se întoarce SOLDUL comenzii pe fiecare articol — ieșirile „Comandă
        // client" minus retururile deja scrise pe ea — așa că o comandă scăzută,
        // întoarsă și scăzută din nou iese la socoteală corect, iar o a doua
        // trecere nu mai are ce întoarce (`HAVING qty > 0`). Suma e pe DECIMAL,
        // deci exactă.
        //
        // Filtrul pe `reason` nu e de fason: laboratorul scrie și el cu
        // `order_id` („Producție laborator", „Consum în producție", „Corecție
        // producție" — vezi `route_order_production`), iar acelea sunt materie
        // primă consumată și marfă făcută, nu marfă vândută. Luate în socoteală,
        // returul unei comenzi ar scoate făina din rafturi. Motivul returului nu
        // se poate scrie ca listă fixă, fiindcă se compune din numele etapei
        // luat din Setări — de aceea `LIKE 'Retur:%'`.
        //
        // `GROUP BY m.item_id` adună pe RAFT, nu pe poziție: o comandă cu
        // același produs în două variante scrie două ieșiri pe același articol
        // și primește un singur rând de retur, cu totalul. E mai aproape de
        // adevăr decât două rânduri jumătate-jumătate, dar înseamnă că
        // `$intoarse` numără de acum rafturi atinse, nu poziții.
        $items = all(
            'SELECT m.item_id,
                    COALESCE(MAX(si.name), MAX(m.name)) AS item_name,
                    SUM(m.qty * -m.direction)           AS qty
               FROM stock_moves m
               LEFT JOIN stock_items si ON si.id = m.item_id
              WHERE m.order_id = ?
                AND m.item_id IS NOT NULL
                AND (m.reason = ? OR m.reason LIKE ?)
              GROUP BY m.item_id
             HAVING qty > 0',
            [$orderId, 'Comandă client', 'Retur:%']
        );
    } else {
        // Plasa pentru comenzile scăzute ÎNAINTE de reparație: mișcările lor au
        // `order_id` gol, deci registrul n-are ce răspunde. Numai pentru ele se
        // mai ghicește raftul, cu potrivirea de dinainte — e tot ce se poate
        // face, iar alternativa (să nu se întoarcă nimic) ar lăsa marfă ieșită
        // din evidență pe comenzi vechi și adevărate. Aici filtrul pe `active`
        // NU se pune, dinadins: ce desface un fapt deja scris trebuie să ajungă
        // și la un raft stins între timp, altfel comanda ar rămâne agățată la
        // „Predată" pe vecie. Ramura se stinge singură la prima scădere nouă a
        // comenzii; se poate scoate de tot după o migrare care completează
        // `order_id` retroactiv.
        $items = all(
            'SELECT oi.qty, oi.name, si.id AS item_id, si.name AS item_name
               FROM order_items oi
               LEFT JOIN stock_items si
                      ON si.id = (
                           SELECT s.id
                             FROM stock_items s
                            WHERE (s.product_id = oi.product_id AND s.product_id IS NOT NULL)
                               OR (s.product_id IS NULL AND s.name = oi.name)
                            ORDER BY (s.active = 0), (s.product_id IS NULL), s.id
                            LIMIT 1
                         )
              WHERE oi.order_id = ?',
            [$orderId]
        );
    }

    // Poarta de mai jos mușcă de acum NUMAI pe ramura-plasă: citit din registru,
    // articolul e mereu acolo — chiar și scos din listă — și exact acolo trebuie
    // să se întoarcă marfa, fiindcă de acolo a plecat.
    //
    // Comanda e unitatea de lucru și la retur, exact ca la scădere: dacă vreo
    // poziție nu-și mai găsește articolul de depozit, nu se întoarce NIMIC și
    // marcajul rămâne pus. Altfel marfa aceea nu s-ar mai întoarce niciodată,
    // dar comanda ar redeveni „de scăzut": la următoarea apăsare pe „Scade
    // comenzile" aceleași bucăți ies a doua oară din depozit, iar cifra de pe
    // ecran arată cu o comandă mai puțin decât are patiseria în rafturi.
    // Se întâmplă la o operație obișnuită: un articol nelegat de produs, care
    // se potrivea prin nume, redenumit din pagina Depozit.
    foreach ($items as $it) {
        if ($it['item_id'] === null) {
            // `fail()` încheie scriptul; tranzacția apelantului se desface
            // aici, altfel rândurile blocate cu FOR UPDATE rămân blocate până
            // când găzduirea închide conexiunea — iar tabletele din laborator
            // se opresc una după alta pe aceeași comandă.
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            fail(
                'Poziția „' . (string) $it['name'] . '" nu mai are articol în depozit, deci marfa nu poate fi '
                    . 'întoarsă. Creează sau redenumește articolul în Depozit, apoi salvează comanda din nou.',
                409,
                'stock_return_missing_item'
            );
        }
    }

    // Aceeași însemnare ca la scădere, ca cele două rânduri să se citească
    // pereche în registru: „#1042 Restaurant Bistro".
    $note = trim((string) ($o['ref'] ?? '') . ' ' . (string) ($o['customer_name'] ?? ''));
    // `stock_moves.reason` are 96 de caractere, iar numele etapei vine din
    // Setări, unde nimeni nu-i limitează lungimea. Tăiat aici, nu în MySQL:
    // altfel o etapă lungă ar face din anularea unei comenzi „Eroare internă",
    // fiindcă 1406 „Data too long" nu e SQLSTATE 23000 și bucla de reîncercare
    // a rutei l-ar arunca mai departe.
    $motiv = mb_substr($motiv, 0, 96);
    $n     = 0;

    foreach ($items as $it) {
        // `name` păstrează denumirea articolului la momentul mișcării, ca la
        // scădere: registrul vechi rămâne lizibil după o redenumire.
        //
        // `order_id` se scrie numai când ieșirea lui e la rândul ei însemnată.
        // Pus și pe returul de pe plasă, un picior al perechii ar fi însemnat și
        // celălalt nu: soldul pe comandă ar ieși negativ, `HAVING qty > 0` l-ar
        // arunca, și de-atunci înainte comanda aceea ar cădea pe ghicit la
        // fiecare ciclu — tăcut, pe vecie. Perechea se însemnează întreagă sau
        // deloc.
        q(
            'INSERT INTO stock_moves (item_id, name, qty, direction, reason, note, user_id, order_id)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?)',
            [(int) $it['item_id'], (string) $it['item_name'], (float) $it['qty'], $motiv, $note, $userId,
                $stieRegistrul ? $orderId : null]
        );
        $n++;
    }

    q('UPDATE orders SET stock_synced = 0 WHERE id = ?', [$orderId]);

    return $n;
}

/**
 * Reface promisiunile de stoc ale unei comenzi, din pozițiile ei de acum.
 *
 * Depozitul avea nevoie de a doua cifră lângă stoc: nu doar „câtă făină e în
 * raft", ci și „câtă e deja promisă unei comenzi". Până acum cifra aceea se
 * socotea în browser, din primele 500 de comenzi aduse de pagină — peste 500,
 * „Rezervat" devenea tăcut greșit, iar depozitarul cumpăra după o cifră care
 * nu mai era adevărată.
 *
 * DE CE UN TABEL, ȘI NU UN RÂND CU `direction = 0` ÎN REGISTRU:
 *
 *   1. Soldul e o sumă, nu o coloană: `SUM(qty * direction)`. Un rând cu
 *      direction = 0 ar fi invizibil pentru sumă, dar VIZIBIL în toate
 *      listările — pagina Depozit îl mapează pe intrare/ieșire și l-ar arăta
 *      ca ieșire.
 *   2. Registrul e append-only, rezervarea e mutabilă. `stock_unsync_order`
 *      scrie contra-mișcare tocmai ca să nu șteargă din registru.
 *   3. O rezervare e o promisiune; o mișcare e un fapt. Faptele nu se desfac.
 *
 * SE ȘTERGE ȘI SE REFACE, nu se ajustează: promisiunea se deduce întreagă din
 * pozițiile de azi, deci n-are stare proprie de păstrat. Așa funcția e
 * idempotentă, iar `PATCH /orders` — care oricum șterge și rescrie pozițiile —
 * n-are nevoie să știe dacă `ON DELETE CASCADE` a apucat să ruleze înaintea ei.
 *
 * REZERVAREA DESCHISĂ NU SE GESTIONEAZĂ, SE DERIVĂ: `qty - consumed`, numărată
 * doar pentru comenzile al căror status nu e nici cel de încheiere, nici cel de
 * anulare (vezi `route_items_list`). De aici vin gratis eliberarea la „Predată",
 * redeschiderea dacă iese din „Predată" și eliberarea la anulare: toate sunt
 * același filtru pe status, nu trei bucăți de cod.
 *
 * Potrivirea poziție → articol e copiată literă cu literă din
 * `route_stock_sync`, filtrul pe `active` inclusiv: promisiunea trebuie să cadă
 * pe raftul din care se va face și scăderea, altfel „Rezervat" ar arăta pe un
 * articol, iar ieșirea pe altul. Un raft scos din depozit nu mai primește
 * promisiuni: marfa lui nu mai e de vândut, iar „Disponibil"-ul depozitarului
 * ar fi scăzut pentru o comandă care oricum nu se mai poate scădea de acolo.
 *
 * Se cheamă din tranzacția apelantului, după ce pozițiile sunt scrise.
 *
 * @return int  câte rânduri de rezervare s-au scris
 */
function stock_reserve_order(int $orderId): int
{
    q('DELETE FROM stock_reservations WHERE order_id = ?', [$orderId]);

    $items = all(
        'SELECT oi.id, oi.product_id, oi.name, oi.qty, oi.done_qty,
                si.id AS item_id
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
          WHERE oi.order_id = ?
          ORDER BY oi.id',
        [$orderId]
    );
    if (!$items) {
        return 0;
    }

    // Rețetele, într-o singură interogare pentru toate produsele comenzii.
    $produse = [];
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid > 0) {
            $produse[$pid] = true;
        }
    }
    $reteta = [];
    if ($produse) {
        $pids  = array_keys($produse);
        $marks = implode(',', array_fill(0, count($pids), '?'));
        foreach (all("SELECT product_id, item_id, qty FROM product_recipes WHERE product_id IN ($marks)", $pids) as $l) {
            $reteta[(int) $l['product_id']][] = $l;
        }
    }

    $scrise = 0;
    foreach ($items as $it) {
        $oid    = (int) $it['id'];
        $cerut  = (float) $it['qty'];
        $facut  = (float) ($it['done_qty'] ?? 0);

        // Cheia unică a tabelului e (poziție, articol), și pe bună dreptate: o
        // poziție nu poate promite de două ori din același raft. Adunăm deci
        // înainte de scriere, în loc să lăsăm a doua inserare să crape. Se
        // întâmplă doar la o configurare încâlcită — articolul finit al unui
        // produs pus și în rețeta lui — dar atunci raftul e chiar același, deci
        // promisiunea e una singură, cât ambele la un loc.
        $randuri = [];
        $pune = static function (int $itemId, string $kind, float $qty, float $consumat) use (&$randuri): void {
            if (isset($randuri[$itemId])) {
                $randuri[$itemId]['qty']      += $qty;
                $randuri[$itemId]['consumed'] += $consumat;
                // „finit" e mai tare: rândul spune atunci că raftul acela e
                // marfa gata a comenzii, nu doar o materie primă a ei.
                if ($kind === 'finit') {
                    $randuri[$itemId]['kind'] = 'finit';
                }
                return;
            }
            $randuri[$itemId] = ['kind' => $kind, 'qty' => $qty, 'consumed' => $consumat];
        };

        // Marfa gata: se promite toată cantitatea, iar producția NU o consumă.
        // Croasantul făcut e tot al comenzii până pleacă din patiserie, iar
        // plecarea e scăderea de la „Predată" — adică filtrul pe status, care
        // stinge rândul cu totul.
        if ($it['item_id'] !== null) {
            $pune((int) $it['item_id'], 'finit', $cerut, 0.0);
        }

        // Materiile prime: se promit pentru toată comanda, iar ce s-a produs e
        // deja consumat — a ieșit din raft cu o mișcare adevărată, scrisă de
        // `POST /orders/#/production`. Fără scăderea asta, aceeași făină ar fi
        // numărată de două ori: o dată ca ieșită, o dată ca încă promisă.
        foreach ($reteta[(int) ($it['product_id'] ?? 0)] ?? [] as $l) {
            $pePiesa = (float) $l['qty'];
            $pune((int) $l['item_id'], 'materie', round($cerut * $pePiesa, 4), round($facut * $pePiesa, 4));
        }

        foreach ($randuri as $itemId => $r) {
            q(
                'INSERT INTO stock_reservations (order_id, order_item_id, item_id, kind, qty, consumed)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$orderId, $oid, $itemId, $r['kind'], $r['qty'], $r['consumed']]
            );
            $scrise++;
        }
    }

    return $scrise;
}
