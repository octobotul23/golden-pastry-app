<?php
/** Conturi și parole. Doar `admin`. */

declare(strict_types=1);

/**
 * Rolurile pe care le poate purta un cont.
 *
 * Ordinea e cea din ENUM-ul coloanei `users.role` (vezi sql/007): valorile noi
 * se adaugă mereu la coadă, fiindcă MySQL ține în rând poziția, nu textul, iar
 * o valoare strecurată la mijloc ar schimba în tăcere rolul conturilor de după.
 *
 * `manager` și `agent_vanzari` intră în GP_STAFF_ROLES (api/lib/auth.php), deci
 * ajung la panourile de lucru ca angajatul — dar NU la conturi, catalog, setări,
 * jurnal, acceptarea cererilor sau ordinea opririlor: acelea cer în continuare
 * require_admin().
 *
 * `fondator` e singurul care trece de require_admin() fără să fie `admin`, și
 * singurul care poate face și desface roluri de conducere — vezi
 * guard_leader_role() mai jos.
 *
 * `utilizator_lab` a stat în ENUM din migrarea 009 fără să-l poată alege
 * nimeni, fiindcă n-avea ce face: nu deschide niciun panou și nu-i dă omului
 * nimic de apăsat. Migrarea 014 i-a dat rostul — e omul care PONTEAZĂ, adică
 * cel care apare pe tableta din laborator și pentru care se ține socoteala
 * orelor. Rolul singur nu-l pune pe tabletă: acolo intră cine are cod de
 * pontaj (`users.lab_pin`), oricare i-ar fi rolul. Așa un brutar cu rol de
 * `angajat` pontează fără să-și piardă panourile de lucru, iar un contabil cu
 * rol de `utilizator_lab` nu capătă nicio ușă în plus.
 */
const ROLES = ['admin', 'angajat', 'client', 'manager', 'agent_vanzari', 'fondator', 'utilizator_lab'];

/*
 * check_user_email() stătea aici. S-a mutat în api/lib/auth.php, fără nicio
 * schimbare de nume, de semnătură sau de comportament: rutele din routes/ nu se
 * încarcă una pe alta (index.php cere doar fișierul rutei potrivite), iar de
 * regula asta are nevoie acum și înscrierea de pe site, din routes/auth.php.
 * O a doua copie a unei reguli de unicitate se depărtează de prima exact când
 * contează. Apelurile de mai jos merg neschimbate — lib/ se încarcă mereu.
 */

/**
 * Cine are voie să umble la rolurile de conducere.
 *
 * Două reguli, nu una, fiindcă au tării diferite:
 *
 * 1. **Rolul de fondator se dă NUMAI de un fondator.** Fără portiță, fără
 *    excepție pentru instalările fără fondator. Cealaltă ușă spre rolul ăsta
 *    e cutia poștală a firmei (api/routes/founder.php), și e acolo dinadins:
 *    dacă un administrator și-ar putea da singur rolul din pagina Conturi,
 *    toată ceremonia cu linkul trimis pe „company_email" ar fi decor.
 *
 * 2. **Restul rolurilor de conducere — adică `admin` — cer un fondator numai
 *    dacă există vreunul.** Vezi founder_exists() în api/lib/auth.php pentru
 *    ce s-ar strica altfel: o bază cu doi administratori și niciun fondator
 *    ar rămâne, din clipa importului, fără nimeni care să aibă voie să-i
 *    schimbe rolul vreunuia. Nu se dă nimic pe gratis — puterea în plus a
 *    fondatorului e tocmai cea asupra administratorilor, iar când fondatori
 *    nu sunt, administratorul o are oricum.
 *
 * Se cheamă și pentru rolul CERUT, și pentru cel de ACUM: „fă-l administrator"
 * și „scoate-l din administratori" sunt aceeași putere, și n-are sens ca una
 * să fie păzită și cealaltă nu.
 *
 * Se cheamă și pentru ADRESA unui cont de conducere, cu `$motiv` scris: acolo
 * nu se atinge niciun rol, dar puterea mutată e aceeași (vezi ramura de e-mail
 * din route_users_update() mai jos). Fără al treilea parametru, celui care a
 * atins caseta de e-mail i s-ar fi arătat un refuz despre ROLURI, adică l-ar fi
 * trimis să caute vinovatul unde nu e.
 *
 * @param string $nou   rolul cerut; "" când nu se schimbă rolul
 * @param string $vechi rolul de acum al contului atins
 * @param string $motiv refuzul de arătat când fapta nu e despre roluri; gol = cel obișnuit
 */
function guard_leader_role(string $nou, string $vechi, string $motiv = ''): void
{
    $eu = current_user();
    $euSunt = (string) ($eu['role'] ?? '');

    if ($nou === 'fondator') {
        if ($euSunt !== 'fondator') {
            fail('Rolul de fondator îl poate da numai un alt fondator. Dacă nu există încă niciunul, '
                . 'omul îl cere singur din contul lui, iar linkul de confirmare pleacă pe adresa firmei.',
                403, 'founder_only');
        }
        return;
    }

    $atinge = in_array($nou, GP_ADMIN_ROLES, true) || in_array($vechi, GP_ADMIN_ROLES, true);
    if ($atinge && $euSunt !== 'fondator' && founder_exists()) {
        fail($motiv !== '' ? $motiv : 'Rolurile de administrator se dau și se iau numai de un fondator.',
            403, 'founder_only');
    }
}

/**
 * Ultimul fondator nu se șterge, nu se dezactivează și nu se retrogradează.
 *
 * Contul care poate ridica pe cineva în rang n-are voie să dispară din lume:
 * fără el, rolul de fondator nu se mai poate da decât prin cutia poștală a
 * firmei, iar dacă adresa aceea s-a schimbat între timp, deloc.
 *
 * Aceeași formă ca „ultimul administrator" de mai jos, dinadins: sunt aceeași
 * grijă, la două niveluri.
 */
function guard_last_founder(int $id, string $rolulLui): void
{
    if ($rolulLui !== 'fondator') {
        return;
    }
    if ((int) scalar("SELECT COUNT(*) FROM users WHERE role = 'fondator' AND active = 1 AND id <> ?", [$id]) === 0) {
        fail('Nu poți scoate ultimul fondator. Fă întâi un al doilea fondator, apoi revino.',
            422, 'last_founder');
    }
}

function route_users_list(): void
{
    require_admin();

    // Coloanele de recomandare se cer numai dacă migrarea 007 a fost rulată.
    // Cerute necondiționat, o instalare pe care fișierele au fost urcate
    // înaintea importului din phpMyAdmin ar primi 1054 „Unknown column" — adică
    // pagina Conturi ar rămâne goală până la import. Așa, ea merge exact ca
    // înainte, doar fără coloana de recomandări.
    $areReferral = referral_supported();
    // Procentul stă pe altă migrare decât codul (008, nu 007), deci are nevoie
    // de poarta lui: pe o bază cu 007 dar fără 008, cerut împreună cu codul,
    // ar da tot 1054 „Unknown column" — exact defectul de care se apără rândul
    // de deasupra, mutat cu o migrare mai încolo.
    $areBani = referral_money_supported();
    $extra = $areReferral
        ? ', u.referral_code, u.referred_by, u.referred_at, r.name AS referred_by_name'
        : '';
    $extra .= $areBani ? ', u.referral_rate' : '';
    // Migrarea 010, cu poarta ei: fără ea, pagina Conturi n-are ce arăta despre
    // originea conturilor, iar butonul „Recunosc clientul" nici nu se desenează.
    $areOrigine = user_origin_supported();
    $extra .= $areOrigine ? ', u.self_registered, u.vouched_at, v.name AS vouched_by_name' : '';
    // Migrarea 014, cu poarta ei. Se cere coloana ca să se poată răspunde ARE
    // sau N-ARE cod de pontaj; `public_user()` nu scoate niciodată valoarea.
    $arePontaj = lab_supported();
    $extra .= $arePontaj ? ', u.lab_pin' : '';
    // Migrarea 016, cu poarta ei: zona firmei, ca să se poată vedea și corecta
    // din Conturi, nu numai din contul clientului. `public_user()` o ia din rând.
    $areZona = user_zone_supported();
    $extra .= $areZona ? ', u.zone' : '';
    // Migrarea 023, cu poarta ei: semnul „poate confirma livrarea". Se cere
    // coloana ca să se poată desena bifa cu ce SCRIE pe cont — nu cu dreptul
    // de fapt al omului, care pentru administrator și fondator vine din cod
    // chiar cu bifa goală (v. `poate_confirma_livrarea()`, api/lib/auth.php).
    // Bifa arată ce se poate schimba de aici; dreptul se citește din `/auth/me`.
    $areLivrare = livrare_semn_suportat();
    $extra .= $areLivrare ? ', u.poate_confirma_livrarea' : '';
    $join = $areReferral ? ' LEFT JOIN users r ON r.id = u.referred_by' : '';
    $join .= $areOrigine ? ' LEFT JOIN users v ON v.id = u.vouched_by' : '';

    $rows = all(
        'SELECT u.id, u.username, u.name, u.email, u.phone, u.address, u.role, u.customer_name,
                u.active, u.must_change_password, u.password_changed_at, u.last_login,
                u.created_at, u.is_system' . $extra . '
           FROM users u' . $join . '
          ORDER BY u.role, u.name'
    );
    // `referral` spune paginii dacă migrarea 007 a fost rulată. Fără steagul
    // ăsta, ea n-ar avea cum deosebi „niciun cont n-are încă un cod" de
    // „coloanele nici nu există" — și ar oferi un buton care s-ar lovi de 503.
    // public_user() nu include niciodată password_hash.
    json_out(['referral' => $areReferral, 'referralMoney' => $areBani, 'origine' => $areOrigine,
        'pontaj' => $arePontaj,
        // A intrat migrarea 023? Fără steagul ăsta pagina n-ar putea deosebi
        // „niciun cont n-are încă semnul" de „coloana nici nu există" — și ar
        // desena o bifă pe care serverul o refuză cu 503.
        'livrare' => $areLivrare,
        'referralRateDefault' => $areBani ? referral_default_rate() : 0.0, 'users' => array_map(static function ($r) use ($areBani, $areOrigine, $areLivrare) {
        $u                = public_user($r);
        $u['phone']       = $r['phone'];
        $u['active']      = (bool) $r['active'];
        $u['lastLogin']   = $r['last_login'];
        // Recomandări: codul propriu, linkul gata de trimis și cine l-a adus.
        // `referralCode` vine deja din public_user(); aici se adaugă restul.
        $u['referralLink']   = referral_link($r['referral_code'] ?? null);
        $u['referredByName'] = $r['referred_by_name'] ?? null;
        $u['referredAt']     = $r['referred_at'] ?? null;
        // `null` nu e „zero la sută", e „ia-l pe cel global din Setări". Cele
        // două arată la fel într-o casetă goală și înseamnă lucruri opuse, deci
        // se duc mai departe deosebite până în pagină. Vezi comentariul
        // coloanei din sql/008 și `route_users_referral_edit()`.
        $u['referralRate']   = ($areBani && $r['referral_rate'] !== null)
            ? round((float) $r['referral_rate'], 2)
            : null;
        // Momentul în care omul și-a schimbat SINGUR parola — NULL cât timp pe
        // cont e cea pusă de administrator. Pagina arăta aici `last_login` și
        // scria „parolă schimbată" despre un cont care doar se autentificase.
        $u['passwordChangedAt'] = $r['password_changed_at'];
        $u['createdAt']   = $r['created_at'];
        // Contul „panou" nu are parolă de om: se intră în el cu codul
        // tabletelor. Pagina trebuie să știe asta ca să nu ofere butoane care
        // oricum sunt refuzate pe server.
        $u['isSystem']    = (int) $r['is_system'] === 1;
        // Contul și l-a făcut singur cineva de pe site, și l-a recunoscut casa
        // de-atunci? De astea două atârnă dacă i se leagă comenzile luate la
        // telefon (`client_by_name()`, api/lib/orders.php) — deci pagina trebuie
        // să le poată arăta, altfel regula ar lucra nevăzută.
        $u['selfRegistered'] = $areOrigine ? ((int) $r['self_registered'] === 1) : false;
        $u['vouchedAt']      = $areOrigine ? ($r['vouched_at'] ?? null) : null;
        $u['vouchedByName']  = $areOrigine ? ($r['vouched_by_name'] ?? null) : null;
        // CE SCRIE PE CONT, nu ce poate omul. Administratorul și fondatorul pot
        // confirma livrarea din cod, cu bifa goală; bifa asta arată numai ce se
        // poate pune și scoate de aici, iar pagina spune în dreptul lor că
        // dreptul le vine din rol. Dacă bifa ar arăta dreptul de fapt, ar
        // apărea pusă și n-ar putea fi scoasă — o bifă care minte de două ori.
        $u['poateConfirmaLivrarea'] = $areLivrare ? ((int) $r['poate_confirma_livrarea'] === 1) : false;
        return $u;
    }, $rows)]);
}

/**
 * POST /users/{id}/vouch — „Recunosc clientul", pusă sau scoasă.
 *
 * Contul născut pe site nu capătă comenzile luate la telefon pe numele lui
 * (`client_by_name()`, api/lib/orders.php), fiindcă un nume tastat de oricine de
 * pe internet nu e o identitate. Aici e locul unde casa spune „ba da, pe ăsta
 * îl cunosc" — și de-aici înainte contul se poartă ca oricare altul.
 *
 * DE CE O RUTĂ A LUI, ȘI NU UN CÂMP LA `PATCH /users/{id}`. Prima formă a
 * reparației citea rândul `user_update` din jurnal, adică „cineva a atins
 * vreodată un câmp de pe contul ăsta". Nu înseamnă asta: se scrie și la un
 * telefon corectat, și la o dezactivare urmată de o reactivare, și — fiindcă
 * fiecare literă tastată în „Nume afișat" trimite propriul PATCH — la o singură
 * apăsare de tastă. Administratorul care cerceta un cont suspect și îi corecta
 * telefonul tocmai îl albea, tăcut. O hotărâre despre identitate trebuie luată
 * anume, nu culeasă dintr-un efect lăturalnic al muncii de zi cu zi.
 *
 * SE POATE ȘI SCOATE, dinadins, ca bifa de plată din „Recomandări": e o
 * afirmație a unui om, iar o afirmație greșită trebuie să se poată retrage
 * fără phpMyAdmin. Scoaterea nu dezleagă comenzile deja legate — ele au fost
 * legate atunci, iar rescrierea istoriei ar fi altceva decât ce a cerut omul.
 */
function route_users_vouch(string $id): void
{
    $eu = require_admin();
    if (!user_origin_supported()) {
        fail(
            'Recunoașterea conturilor cere migrarea sql/010_origine_cont.sql, care nu a fost '
              . 'încă importată în baza de date.',
            503,
            'origin_unavailable'
        );
    }

    $t = one('SELECT id, name, customer_name, self_registered, vouched_at FROM users WHERE id = ?', [(int) $id]);
    if (!$t) {
        fail('Contul nu există.', 404, 'not_found');
    }
    if ((int) $t['self_registered'] !== 1) {
        fail(
            'Contul acesta n-a fost făcut de pe site, deci n-are ce se recunoaște: '
              . 'comenzile pe numele lui i se leagă oricum.',
            409,
            'not_self_registered'
        );
    }

    $b    = body();
    // Lipsa bifei înseamnă DA. Butonul se apasă ca să recunoască; scoaterea se
    // cere pe față, cu `vouched: false`.
    $cere = !array_key_exists('vouched', $b) || !empty($b['vouched']);
    $are  = $t['vouched_at'] !== null;
    if ($cere === $are) {
        json_out(['vouched' => $are, 'changed' => false]);
    }

    // A TREIA UȘĂ, și cea mai ascunsă: contul făcut pe site nu intră în
    // socoteala lui `client_by_name()` cât timp nu e recunoscut (poarta
    // `self_registered = 0 OR vouched_at IS NOT NULL`). Apăsarea de aici e
    // chiar clipa în care începe să conteze — deci e tot o NAȘTERE de nume,
    // deși contul există de mult. Fără poarta asta, „Recunosc clientul" pe un
    // omonim ar fi butonul care rupe tăcut legarea comenzilor clientului vechi,
    // și încă din mâna casei, nu a unui străin.
    //
    // Regula întreagă e la `client_name_conflict()` (api/lib/orders.php).
    // Numai la punerea bifei: scoaterea ei nu poate naște niciun omonim, doar
    // scoate unul din socoteală.
    if ($cere) {
        $strigat = client_display_name($t['customer_name'] ?? '', $t['name'] ?? '');
        $altul   = client_name_conflict($strigat, (int) $id);
        if ($altul !== null) {
            fail(
                'Mai există un client cu numele „' . $strigat . '" (contul „'
                  . $altul['username'] . '"). Recunoscut acum, niciunul dintre cele două '
                  . 'conturi n-ar mai primi comenzile luate la telefon pe numele acela. '
                  . 'Deosebește-le întâi numele, apoi recunoaște-l.',
                409,
                'customer_name_taken'
            );
        }
    }

    q(
        $cere
            ? 'UPDATE users SET vouched_at = UTC_TIMESTAMP(), vouched_by = ? WHERE id = ?'
            : 'UPDATE users SET vouched_at = NULL, vouched_by = NULL WHERE id = ?',
        $cere ? [(int) $eu['id'], (int) $id] : [(int) $id]
    );

    $nume = trim((string) ($t['customer_name'] ?: $t['name']));
    audit(
        $cere ? 'user_vouch' : 'user_unvouch',
        'user',
        (string) (int) $id,
        $cere
            ? 'Cont făcut pe site, recunoscut: „' . $nume . '" — de acum comenzile luate la '
              . 'telefon pe numele acesta i se leagă de cont.'
            : 'Recunoașterea scoasă de pe contul „' . $nume . '" — comenzile noi pe numele '
              . 'acesta nu i se mai leagă.',
        (int) $eu['id']
    );

    json_out(['vouched' => $cere, 'changed' => true]);
}

function route_users_create(): void
{
    // Nu `require_admin()` ca restul rutei de conturi, și nu o rută nouă
    // alături: agentul de vânzări are voie la exact un colț din ea — un cont de
    // CUMPĂRĂTOR, adus de el. Toată verificarea de dedesubt (utilizator liber,
    // parolă de minimum 8, `text_field`, ciocnirea 1062) e deja aici și n-are
    // niciun rost scrisă a doua oară, unde ar rămâne în urmă la prima corectură.
    //
    // Poarta îngustă stă imediat sub `require_staff()`, înaintea oricărei
    // citiri din cerere: cine nu e admin sau fondator trebuie să fie agent, iar
    // agentul nu poate cere decât rolul „client".
    $eu = require_staff();
    $b  = body();

    // CINE E RECOMANDANTUL CONTULUI NOU: nimeni, sau chiar cel care apasă.
    //
    // Pentru agent nu e o alegere — ăsta e tot rostul butonului. Pentru
    // administrator ERA, până acum, o imposibilitate: ramura de mai jos citea
    // codul numai când cel care apasă NU e admin, deci pentru admin și fondator
    // nu se lega nimic. Iar butonul din „Recomandări" se arată oricui are un
    // cod (Recomandari.dc.html · `potFaceCont`), inclusiv patronului — care
    // primea pe ecran „e legat de codul tău" pentru un cont nelegat, și pe urmă
    // se mira că nu-i cad comenzile omului.
    //
    // Steagul e un DA/NU, nu un cod. Un cod în cerere ar fi însemnat că oricine
    // poate împinge cumpărători în lista altcuiva — adică în comisioanele
    // altcuiva. Recomandantul rămâne cine a apăsat butonul, cum a fost mereu.
    $vreauLegat = false;
    if (!e_admin($eu)) {
        if ((string) $eu['role'] !== 'agent_vanzari') {
            fail(
                'Conturile se fac din „Conturi", cu un cont de administrator. '
                  . 'Din contul tău se pot face doar conturi de cumpărător, și numai dacă ești agent de vânzări.',
                403,
                'not_allowed'
            );
        }
        if ((string) ($b['role'] ?? 'client') !== 'client') {
            fail(
                'Un agent de vânzări poate face numai conturi de cumpărător. '
                  . 'Pentru orice alt rol, cere-i unui administrator.',
                403,
                'role_denied'
            );
        }
        $vreauLegat = true;
    } elseif (!empty($b['linkToMe'])) {
        // „Recomandări" cere legarea pe față; „Conturi" nu trimite nimic și
        // rămâne exact ce a fost — o rută de înregistrare, nu de recomandare.
        // Un cont de angajat făcut de acolo n-are ce căuta în lista „aduși de
        // mine" a nimănui.
        $vreauLegat = true;
    }

    $agentul = null;
    if ($vreauLegat) {
        // Codul se citește cu `referral_public()`, NU din `$eu`: interogarea
        // din `current_user()` are listă fixă de coloane și nu cuprinde
        // `referral_code` (motivul e scris la api/routes/auth.php:218-222).
        // Luat de acolo, ar fi fost mereu null și fiecare cont făcut de aici ar
        // fi ieșit nelegat — adică exact munca pentru care există butonul.
        $codul = referral_public((int) $eu['id']);
        $pricina = null;

        if ($codul === null && !e_admin($eu)) {
            // AGENTUL ÎȘI FACE CODUL AICI, nu e trimis după el.
            //
            // Defectul, întreg: butonul „fă un cont de cumpărător" se arată
            // numai cui ARE deja un cod (`potFaceCont`, Recomandari.dc.html),
            // iar codul se face din ALT buton, pe care nimic nu-l lega de
            // primul. Deci un agent proaspăt nu vedea butonul deloc — și dacă
            // l-ar fi văzut, ajungea aici, la un 409 care îl trimitea la un
            // administrator pentru un pas pe care putea să-l facă singur
            // (`canMake`, GET /referrals). Patronul a spus-o scurt: „agentul nu
            // are cum să creeze conturi noi".
            //
            // Codul se face în PHP, nu într-o migrare: se scoate din NUMELE
            // omului, cu dezambiguizare la ciocnire (`referral_assign()`), iar
            // SQL-ul n-ar fi ajuns la aceleași litere. Două generatoare
            // deosebite ar fi dat două coduri deosebite pentru același om.
            //
            // `referral_self_assign()` e ACELAȘI pe care îl cheamă butonul din
            // „Recomandări", și nu strică un cod pus deja: chemat de două ori,
            // îl întoarce pe cel dintâi.
            if (referral_self_assign((int) $eu['id'], $pricina) !== null) {
                $codul = referral_public((int) $eu['id']);
                audit('referral_self_code', 'user', (string) $eu['id'],
                    $eu['name'] . ' → ' . ($codul['code'] ?? '?') . ' (făcut la primul cont adus)');
            }
        }

        if ($codul === null) {
            // Refuz, nu cont nelegat în tăcere: cine cere legarea o cere
            // fiindcă de-asta face contul. Un „gata" peste o legătură care nu
            // s-a scris e chiar defectul reparat aici, mutat cu un pas mai
            // încolo.
            //
            // Pentru ADMINISTRATORUL care a cerut `linkToMe` fără cod, refuzul
            // rămâne cel de dinainte: acolo chiar e o alegere greșită, nu o
            // lipsă de pornire — codul lui se dă din „Conturi", de mână.
            // Pentru agent, s-a ajuns aici numai fiindcă numele lui nu dă
            // niciun cod, și atunci asta se spune, nu altceva.
            fail(
                $pricina === null
                    ? 'Contul tău nu are încă un cod de recomandare, deci cumpărătorul n-ar avea cum '
                        . 'să fie legat de tine. Codul se dă din „Conturi", de la butonul de lângă cont — '
                        . 'cere-i unui administrator dacă tu n-ai acces acolo.'
                    : ($pricina === 'toate_luate'
                        ? 'Numele „' . $eu['name'] . '" e prea des întâlnit: toate codurile care se pot '
                            . 'face din el sunt deja luate. Cere-i unui administrator un cod ales de mână, '
                            . 'din „Conturi", și apoi fă contul.'
                        : 'Nu am putut face un cod de recomandare din numele „' . $eu['name'] . '", '
                            . 'deci cumpărătorul n-ar avea cum să fie legat de tine. Cere-i unui '
                            . 'administrator un cod ales de mână, din „Conturi".'),
                409,
                'no_referral_code'
            );
        }
        $agentul = $codul['code'];
    }

    $username = strtolower(need($b, 'username'));
    $name     = need($b, 'name');
    $password = need($b, 'password');
    $role     = (string) ($b['role'] ?? 'client');

    if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
        fail('Utilizatorul poate conține doar litere mici, cifre, punct, minus și underscore (3–64).', 422, 'bad_username');
    }
    if (reserved_username($username)) {
        fail('Acest nume de utilizator este rezervat. Alege altul.', 422, 'bad_username');
    }
    // Lista se ia din schemă, nu din constantă: între urcarea fișierelor și
    // importul migrării din phpMyAdmin, codul cunoaște roluri pe care coloana
    // încă nu le încape, iar MySQL le-ar refuza cu „1265 Data truncated" — adică
    // „Eroare internă." în loc de o vorbă despre migrarea neimportată.
    $permise = roles_available(ROLES);
    if (!in_array($role, $permise, true)) {
        fail(
            in_array($role, ROLES, true)
                ? 'Rolul „' . $role . '” cere migrarea sql/007_update_roles_referral.sql, care nu a fost '
                  . 'încă importată în baza de date. Valori permise acum: ' . implode(', ', $permise)
                : 'Rol necunoscut. Valori permise: ' . implode(', ', $permise),
            422,
            'bad_role'
        );
    }
    // Un cont nou n-are rol de acum, deci al doilea argument e gol.
    guard_leader_role($role, '');
    if (strlen($password) < 8) {
        fail('Parola trebuie să aibă cel puțin 8 caractere.', 422, 'weak_password');
    }
    if (scalar('SELECT 1 FROM users WHERE username = ?', [$username])) {
        fail('Există deja un cont cu acest utilizator.', 409, 'username_taken');
    }

    $email = trim((string) ($b['email'] ?? ''));
    // Al treilea argument: cine primește refuzul amănunțit și cine pe cel scurt.
    //
    // Ruta asta nu mai e numai a administratorului de când agentul de vânzări
    // își poate face singur cumpărători (vezi poarta de mai sus). Refuzul plin
    // spune „Adresa «x» este deja trecută pe contul «y»” — pentru administrator
    // e tocmai ce-i trebuie, fiindcă el are lista de conturi și trebuie să știe
    // de pe care rând să scoată adresa. Agentul n-are lista (route_users_list()
    // e require_admin()), deci pentru el numele acela e o informație pe care nu
    // are de unde s-o afle altfel: cu o adresă ghicită sau primită de la client
    // scotea, o cerere pe rând, numele de utilizator al oricui — jumătatea care
    // lipsește ca să încerci o parolă.
    //
    // Codul rămâne 409 „email_taken” pentru toată lumea: nu se ascunde faptul
    // că adresa are deja cont — asta i-ar face agentului butonul de neînțeles —
    // ci doar numele contului.
    check_user_email($email, null, !e_admin($eu));

    // Aceeași crăpătură ca la articolele din depozit: verificarea de mai sus e
    // din memorie, iar între ea și scriere încap două cereri paralele. Se
    // întâmplă când doi administratori fac contul aceluiași angajat pentru tura
    // nouă, în aceeași clipă.
    // Adresa și zona firmei. `users.address` are coloană din 001, dar până azi
    // n-o scria NICIO rută: se putea citi peste tot și nu se putea completa de
    // nicăieri, deci era goală pe toate conturile. Zona vine cu migrarea 016.
    //
    // Zona se verifică ÎNTOTDEAUNA, chiar dacă baza n-are încă unde s-o pună: un
    // nume greșit trebuie refuzat în față, nu înghițit tăcut și pierdut.
    $address = text_field($b['address'] ?? '', 'Adresa', 255) ?: null;
    $zone    = zone_field($b['zone'] ?? '') ?: null;
    $cuZona  = user_zone_supported();
    // Cele două nume se scot din cerere aici, nu în argumentele lui INSERT: de
    // ele atârnă poarta de dedesubt, iar `text_field()` chemat de două ori pe
    // același câmp e două purtări care pot să se depărteze.
    $numeAfisat = text_field($name, 'Numele', 128, true);
    $firma      = text_field($b['customerName'] ?? '', 'Numele firmei', 160) ?: null;

    // PRIMA UȘĂ prin care se naște un nume de client. Regula întreagă, cu
    // pățania care a scos-o la iveală, e scrisă la `client_name_conflict()`
    // (api/lib/orders.php): al doilea cont cu același nume nu-și strică numai
    // lui legarea comenzilor de la telefon, i-o strică și primului, tăcut.
    //
    // Numai pentru rolul „client": `client_by_name()` nu se uită la nimeni
    // altcineva, deci un angajat omonim cu o cafenea n-a stricat niciodată
    // nimic, iar un refuz acolo ar fi o pază care apără aerul.
    if ($role === 'client') {
        $strigat = client_display_name($firma, $numeAfisat);
        $altul   = client_name_conflict($strigat);
        if ($altul !== null) {
            // Aceeași împărțire ca la adresa de e-mail de mai sus, și din
            // aceeași pricină: administratorul are lista de conturi și trebuie
            // să știe pe care rând să umble, iar agentul n-are lista (`GET
            // /users` e `require_admin()`), deci numele contului străin ar fi
            // pentru el o informație pe care n-are de unde s-o afle altfel.
            fail(
                'Mai există un client cu numele „' . $strigat . '"'
                  . (e_admin($eu) ? ' (contul „' . $altul['username'] . '")' : '')
                  . '. Două conturi cu același nume fac ca NICIUNUL să nu mai primească '
                  . 'comenzile luate la telefon pe numele acela — nici cel vechi. '
                  . 'Alege alt nume'
                  . (e_admin($eu) ? ', ori corectează-l pe cel vechi din „Conturi".' : '.'),
                409,
                'customer_name_taken'
            );
        }
    }
    q_unique(
        'INSERT INTO users (username, password_hash, name, email, phone, address, role, customer_name, must_change_password'
        . ($cuZona ? ', zone' : '') . ')
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1' . ($cuZona ? ', ?' : '') . ')',
        // Semnul de întrebare al zonei e ultimul din VALUES, deci și valoarea
        // lui trebuie să fie ultima aici.
        array_merge([
            $username, password_hash($password, PASSWORD_DEFAULT), $numeAfisat,
            $email ?: null, text_field($b['phone'] ?? '', 'Telefonul', 32) ?: null,
            $address,
            $role, $firma,
        ], $cuZona ? [$zone] : []),
        'Există deja un cont cu acest utilizator.',
        'username_taken'
    );
    $id = (int) db()->lastInsertId();

    // Codul de recomandare se dă AUTOMAT doar agenților de vânzări: la ei el e
    // unealta de lucru, nu un accesoriu. Restul conturilor — clienți, angajați,
    // manageri — pornesc fără cod, iar administratorul îl dă când chiar e
    // nevoie, de la butonul din pagina Conturi (POST /users/{id}/referral).
    //
    // Regula asta ține numărul de coduri în frâu. Cu un cod pe fiecare cont,
    // lista de recomandări devenea un dublet al listei de conturi, în care
    // codurile care chiar aduc clienți nu se mai deosebeau de restul.
    //
    // Se scrie într-un UPDATE separat, după cont: o ciocnire de coduri — al
    // doilea „Ion" din firmă — nu are voie să dea peste cap crearea contului.
    if ($role === 'agent_vanzari') {
        referral_assign($id, $name, $username);
    }

    // Contul făcut de agent se leagă de codul LUI, nu de unul ales din cerere:
    // recomandantul nu e un câmp de formular, e cine a apăsat butonul. Legarea
    // e cea obișnuită (`referred_by IS NULL` în WHERE), deci se poartă exact ca
    // înscrierea printr-un link cu `?ref=`.
    //
    // Un cod care între timp nu mai duce nicăieri nu oprește nimic: contul e
    // deja făcut și e bun. Aceeași regulă de aur ca la înscriere — o
    // recomandare nescrisă e o pierdere de marketing, nu un motiv de refuz.
    $legat = null;
    if ($agentul !== null) {
        $legat = referral_link_new_user($id, $agentul);
    }

    audit(
        'user_create',
        'user',
        (string) $id,
        $name . ' (' . $role . ')' . ($legat === null ? '' : ' · adus de „' . $agentul . '"')
    );
    json_out([
        'user' => public_user(one('SELECT * FROM users WHERE id = ?', [$id])),
        // CE S-A ÎNTÂMPLAT CU ADEVĂRAT, nu ce s-a cerut.
        //
        // Pagina scria „e gata și e legat de codul tău" fără să se uite la
        // nimic. Câmpul ăsta e ca ea să nu mai poată: pe `null` nu are ce
        // repeta. Și `null` chiar se poate întâmpla cu codul în mână —
        // `referral_link_new_user()` înghite orice piedică dinadins (o
        // recomandare nescrisă nu e motiv să cadă contul), deci singurul loc
        // unde se mai poate afla dacă s-a scris e valoarea întoarsă de ea.
        'referrer' => $legat === null ? null : [
            'id'   => (int) $legat['id'],
            'name' => (string) $legat['name'],
            'code' => $agentul,
        ],
    ], 201);
}

function route_users_update(string $id): void
{
    $me = require_admin();
    $b  = body();
    $id = (int) $id;

    $target = one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$target) {
        fail('Contul nu există.', 404, 'not_found');
    }
    // Contul de sistem poate primi cel mult o redenumire; rolul și starea lui
    // sunt legate de funcționarea tabletelor.
    if ((int) $target['is_system'] === 1 && (array_key_exists('role', $b) || array_key_exists('active', $b))) {
        fail('Rolul și starea contului de sistem „panou” nu pot fi schimbate. '
            . 'Accesul cu cod se oprește din Setări.', 422, 'system_account');
    }

    $sets = [];
    $vals = [];
    // Ce capătă fiecare coloană, strâns pe parcurs: poarta numelui de mai jos
    // are nevoie de numele de DUPĂ schimbare, iar el atârnă de două coloane
    // deodată, deci nu se poate hotărî la niciuna din ele în parte.
    $nou  = [];

    // `address` a stat pe lista asta de la bun început ca lipsă, nu ca hotărâre:
    // coloana există din 001 și o citeau trei interogări, dar n-o scria nicio
    // rută — adică adresa clientului nu se putea completa de nicăieri, deși
    // pagina „Fă-ți cont" promitea de mult că o ține.
    foreach (['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'address' => 'address', 'customerName' => 'customer_name'] as $in => $col) {
        if (array_key_exists($in, $b)) {
            $val = trim((string) $b[$in]);
            // `users.name` e NOT NULL, spre deosebire de celelalte trei. Golit,
            // ajungea ca NULL în UPDATE și ieșea cu „Eroare internă." — adică
            // ștergerea numelui ca să-l rescrii, lucru pe care îl face oricine
            // corectează o denumire, arăta ca o defecțiune a aplicației.
            if ($col === 'name' && $val === '') {
                fail('Numele nu poate rămâne gol. Scrie numele afișat al contului.', 422, 'empty_name');
            }
            // Lungimea coloanei, verificată înainte de UPDATE: peste ea ieșea
            // „Eroare internă." (1406), nu un refuz care spune ce e prea lung.
            $val = text_field($val, ['name' => 'Numele', 'email' => 'Adresa de e-mail', 'phone' => 'Telefonul', 'address' => 'Adresa', 'customer_name' => 'Numele firmei'][$col],
                ['name' => 128, 'email' => 190, 'phone' => 32, 'address' => 255, 'customer_name' => 160][$col]);
            // Adresa se verifică numai când chiar se schimbă. Pagina Conturi
            // salvează la ieșirea din casetă, deci trimite câmpul și când nu
            // l-a atins nimeni; verificat necondiționat, un duplicat rămas în
            // bază de dinainte de regula asta ar bloca orice altă corecție pe
            // rândul acela. Regula e „nu înrăutăți", nu „respinge trecutul".
            //
            // Tot aici se apără A TREIA cale spre puterea unui cont de
            // conducere, pe lângă rol și parolă: ADRESA lui. Un administrator
            // muta adresa unui fondator pe cutia lui, apăsa „Ți-ai uitat
            // parola?" pe contul acela și primea linkul de resetare. Nu i-a
            // cerut nimănui rolul — l-a luat pe cel care îl avea, fără să treacă
            // pe lângă nicio poartă și fără să lase în jurnal altceva decât o
            // corectură de adresă. Aceeași putere ca „fă-l administrator", deci
            // aceeași pază, cu aceeași portiță pentru bazele fără fondator.
            //
            // Perechea ei stă în api/routes/founder.php, la POST /founder/request:
            // acolo se apără cutia poștală a FIRMEI, prin care se DĂ rolul de
            // fondator. Sunt două uși spre același capăt, și n-are rost una
            // închisă fără cealaltă — cine umblă la asta să se uite și acolo.
            //
            // Propriul cont rămâne pe dinafară: de la tine însuți n-ai ce lua,
            // iar altfel un administrator cu o greșeală de tastare în propria
            // adresă n-ar mai avea de unde s-o îndrepte. Rămâne închis pe
            // dinafară doar fondatorul care și-a pierdut ȘI parola, ȘI adresa —
            // atunci îl scoate alt fondator, sau un rând de SQL.
            if ($col === 'email' && strtolower($val) !== strtolower((string) $target['email'])) {
                if ($id !== (int) $me['id']) {
                    guard_leader_role('', (string) $target['role'],
                        'Adresa unui cont de conducere se schimbă numai de un fondator: pe ea pleacă '
                        . 'linkul de resetare a parolei, deci cine o mută poate intra în cont. '
                        . 'Fondatorul și-o poate schimba oricând singur, din contul lui.');
                }
                check_user_email($val, $id);
            }
            $nou[$col] = $val;
            $sets[] = "$col = ?";
            // `!== ''`, nu `?:`. În PHP șirul „0" e FALS, iar `$val ?: null`
            // îl preschimba în NULL — deci un cont numit „0" (o firmă „0 Grade",
            // un client trecut în grabă) cădea cu „Eroare internă": `users.name`
            // e NOT NULL, iar MySQL răspundea cu 1048.
            //
            // Poarta de deasupra oprea numele GOL, și tot ea ar fi trebuit să
            // oprească și ăsta — dar n-avea cum: „0" NU E gol, e un nume. Ce s-a
            // reparat atunci a fost SIMPTOMUL (`$val === ''`), nu mecanismul (ce
            // socotește PHP drept fals), iar defectul a rămas în a doua lui
            // formă. De-aia întrebarea de aici e scrisă pe mecanism: „s-a șters
            // câmpul?", nu „e valoarea falsă?".
            //
            // Pe celelalte patru coloane, care sunt NULL-abile, „0" nu crăpa —
            // se pierdea TĂCUT: telefonul „0", adresa „0", numele de firmă „0"
            // se scriau ca NULL, iar pagina le arăta goale la reîncărcare.
            // Omul le scria din nou, și din nou dispăreau.
            $vals[] = $val !== '' ? $val : null;
        }
    }
    // Zona nu intră în bucla de sus: are de trecut printr-o listă, nu printr-o
    // lungime.
    //
    // ȘI REFUZĂ PE FAȚĂ CÂND MIGRAREA LIPSEȘTE, spre deosebire de înscrierea de
    // pe site, care în cazul ăla naște tăcut un cont fără zonă. Deosebirea e
    // cine apasă: clientul de pe site n-are ce face cu vestea și n-ar trebui
    // oprit din a-și face cont pentru ea, pe când administratorul e chiar omul
    // care poate importa fișierul — iar un „gata" peste o zonă care nu s-a
    // scris l-ar trimite să caute defectul în altă parte. Ca la `vouch`.
    if (array_key_exists('zone', $b)) {
        if (!user_zone_supported()) {
            fail(
                'Zona contului cere migrarea sql/016_zona_cont.sql, care nu a fost încă '
                  . 'importată în baza de date.',
                503,
                'zone_unavailable'
            );
        }
        $sets[] = 'zone = ?';
        $vals[] = zone_field($b['zone'] ?? '') ?: null;
    }
    // Semnul „poate confirma livrarea" (migrarea 023). Nu intră nici el în
    // bucla de sus: acolo se trec câmpuri de text, iar ăsta e un da/nu.
    //
    // REFUZĂ PE FAȚĂ CÂND MIGRAREA LIPSEȘTE, din același motiv ca zona de
    // deasupra: cine apasă aici e chiar omul care poate importa fișierul, iar
    // un „gata" peste o bifă care nu s-a scris l-ar trimite să caute defectul
    // în altă parte — pe ecranul șoferului, unde butonul ar fi rămas la fel.
    //
    // NU SE PUNE LA CREARE, dinadins: hotărârea patronului e că semnul se naște
    // închis pentru toți, iar apoi se bifează anume cine duce marfa. O casetă
    // pe formularul de cont nou ar fi făcut din asta o alegere care se poate
    // face din greșeală, la fiecare cont.
    if (array_key_exists('poateConfirmaLivrarea', $b)) {
        if (!livrare_semn_suportat()) {
            fail(
                'Semnul „poate confirma livrarea" cere migrarea '
                  . 'sql/023_laboratorul_si_livrarea.sql, care nu a fost încă importată în baza de date.',
                503,
                'delivery_flag_unavailable'
            );
        }
        $sets[] = 'poate_confirma_livrarea = ?';
        $vals[] = $b['poateConfirmaLivrarea'] ? 1 : 0;
    }
    if (array_key_exists('role', $b)) {
        // Aceeași regulă ca la creare: ce încape în coloană, nu doar ce știe codul.
        $permise = roles_available(ROLES);
        if (!in_array($b['role'], $permise, true)) {
            fail(
                in_array($b['role'], ROLES, true)
                    ? 'Rolul „' . $b['role'] . '” cere migrarea sql/007_update_roles_referral.sql, '
                      . 'care nu a fost încă importată în baza de date.'
                    : 'Rol necunoscut.',
                422,
                'bad_role'
            );
        }
        // Fără asta, un admin își poate retrograda propriul cont și rămâne
        // instalarea fără niciun administrator. Lista, nu şirul „admin": de
        // când există fondatori, un fondator care se trece singur pe „client"
        // ar face aceeași pagubă, doar cu un rol mai sus.
        if ($id === (int) $me['id'] && !in_array($b['role'], GP_ADMIN_ROLES, true)) {
            fail('Nu îți poți schimba propriul rol de administrator.', 422, 'self_demote');
        }
        // Cine are voie să dea și să ia rolurile de conducere. Aici se oprește
        // administratorul care ar vrea să-și dea singur rolul de fondator, și
        // tot aici se oprește cel care ar vrea să i-l ia altuia.
        guard_leader_role((string) $b['role'], (string) $target['role']);
        // Retrogradarea e a treia ușă către „niciun fondator", pe lângă
        // ștergere și dezactivare. Se păzește la fel.
        if ($b['role'] !== 'fondator') {
            guard_last_founder($id, (string) $target['role']);
        }
        $sets[] = 'role = ?';
        $vals[] = $b['role'];
        // Trecerea pe „agent de vânzări" e momentul firesc în care omul capătă
        // codul: de aici încolo îi trebuie ca să lucreze. Se scrie după UPDATE,
        // mai jos, ca să nu depindă de reușita lui.
        $devineAgent = $b['role'] === 'agent_vanzari' && $target['role'] !== 'agent_vanzari';
    }
    if (array_key_exists('active', $b)) {
        if ($id === (int) $me['id'] && !$b['active']) {
            fail('Nu îți poți dezactiva propriul cont.', 422, 'self_deactivate');
        }
        // Un cont de conducere dezactivat e retrogradat de fapt: nu mai poate
        // intra, deci nu mai poate face nimic din ce numai el avea voie. Deci
        // aceleași două reguli ca la schimbarea rolului.
        if (!$b['active']) {
            guard_leader_role('', (string) $target['role']);
            guard_last_founder($id, (string) $target['role']);
        }
        $sets[] = 'active = ?';
        $vals[] = $b['active'] ? 1 : 0;
        if (!$b['active']) {
            q('DELETE FROM sessions WHERE user_id = ?', [$id]);   // îl deconectăm imediat
        }
    }

    // A DOUA UȘĂ prin care se naște un nume de client: nu contul nou, ci
    // redenumirea unuia vechi. Regula întreagă e la `client_name_conflict()`
    // (api/lib/orders.php).
    //
    // Se întreabă O SINGURĂ DATĂ, aici, nu la fiecare câmp în parte: numele
    // sub care e strigat contul atârnă de AMÂNDOUĂ coloanele — firma, ori,
    // lipsă ea, numele afișat — deci schimbată doar una din ele, cealaltă
    // poate hotărî mai departe și nimic nu s-a schimbat cu adevărat.
    //
    // NUMAI CÂND NUMELE CHIAR SE SCHIMBĂ, ca la adresa de mai sus: pagina
    // Conturi salvează la ieșirea din casetă, deci trimite câmpurile și
    // neatinse. Un omonim rămas în bază de dinainte de regula asta ar bloca
    // altfel orice altă corectură pe rândul lui. Plus cazul în care numele
    // stă pe loc dar contul TOCMAI DEVINE client: până atunci n-a fost
    // niciodată cântărit, fiindcă `client_by_name()` nu se uită la alte roluri.
    $rolDupa = array_key_exists('role', $b) ? (string) $b['role'] : (string) $target['role'];
    if ($rolDupa === 'client') {
        $strigatInainte = client_display_name($target['customer_name'] ?? '', $target['name'] ?? '');
        $strigatDupa    = client_display_name(
            array_key_exists('customer_name', $nou) ? $nou['customer_name'] : ($target['customer_name'] ?? ''),
            array_key_exists('name', $nou) ? $nou['name'] : ($target['name'] ?? '')
        );
        $devineClient = (string) $target['role'] !== 'client';
        if ($strigatDupa !== '' && ($strigatDupa !== $strigatInainte || $devineClient)) {
            $altul = client_name_conflict($strigatDupa, $id);
            if ($altul !== null) {
                fail(
                    'Mai există un client cu numele „' . $strigatDupa . '" (contul „'
                      . $altul['username'] . '"). Două conturi cu același nume fac ca NICIUNUL '
                      . 'să nu mai primească comenzile luate la telefon pe numele acela — nici '
                      . 'cel vechi. Alege alt nume, ori corectează-l pe cel vechi.',
                    409,
                    'customer_name_taken'
                );
            }
        }
    }

    if (!$sets) {
        fail('Nimic de modificat.', 422, 'nothing_to_update');
    }

    // Ce s-a schimbat, nu doar pe cine. Adresa de e-mail e a treia cale spre
    // puterea unui cont de conducere (vezi paza de mai sus): mutată pe altă
    // cutie, jurnalul scria „Ana Popescu" și atât — adică exact nimic despre
    // singurul lucru care conta. Se socotește ÎNAINTE de `$vals[] = $id`, ca
    // tablourile să rămână pereche cu pereche.
    $schimbari = audit_diff($sets, $vals, $target, [
        'name'          => 'nume',
        'email'         => 'e-mail',
        'phone'         => 'telefon',
        'customer_name' => 'firmă',
        'role'          => 'rol',
        'active'        => ['stare', ['1' => 'activ', '0' => 'oprit']],
        // Semnul de livrare intră în jurnal CU VORBE, nu cu 1 și 0: e un drept,
        // iar peste șase luni cineva o să caute în jurnal cine i l-a dat
        // șoferului și când. „poate_confirma_livrarea: 1” ar fi cerut atunci
        // citirea codului ca să se afle în ce parte bate cifra.
        'poate_confirma_livrarea' => ['confirmă livrarea', ['1' => 'poate', '0' => 'nu poate']],
    ]);
    $vals[] = $id;
    q('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    // Numele rămâne primul, fiindcă după el se caută în jurnal
    // (api/routes/log.php: `l.detail LIKE ?`).
    audit('user_update', 'user', (string) $id,
        $target['name'] . ($schimbari === '' ? '' : ' · ' . $schimbari));

    // Codul agentului, dacă tocmai a devenit agent și n-avea unul.
    // referral_assign() nu atinge un cont care are deja cod, deci un agent
    // retrogradat și repus nu-și pierde codul dat clienților.
    if (!empty($devineAgent)) {
        referral_assign($id, (string) $target['name'], (string) $target['username']);
    }

    json_out(['user' => public_user(one('SELECT * FROM users WHERE id = ?', [$id]))]);
}

function route_users_delete(string $id): void
{
    $me = require_admin();
    $id = (int) $id;

    if ($id === (int) $me['id']) {
        fail('Nu îți poți șterge propriul cont.', 422, 'self_delete');
    }
    $target = one('SELECT name, role, is_system FROM users WHERE id = ?', [$id]);
    if (!$target) {
        fail('Contul nu există.', 404, 'not_found');
    }
    if ((int) $target['is_system'] === 1) {
        fail('Contul „panou” este de sistem și nu poate fi șters — tabletele din laborator îl folosesc. '
            . 'Dacă vrei să blochezi accesul cu cod, oprește-l din Setări.', 422, 'system_account');
    }
    // Un fondator nu se atinge de un administrator oarecare — dar de un
    // fondator nu se atinge decât un fondator.
    guard_leader_role('', (string) $target['role']);
    guard_last_founder($id, (string) $target['role']);
    // „Ultimul administrator" se numără acum peste amândouă rolurile cu drepturi
    // depline: un fondator ESTE administrator (vezi GP_ADMIN_ROLES), deci o
    // instalare cu un fondator și un singur admin nu rămâne fără nimeni dacă
    // adminul acela pleacă. Numărat doar pe „admin", refuzul ar fi fost o
    // minciună — ar fi apărat un scaun pe care sta deja altcineva.
    if (in_array($target['role'], GP_ADMIN_ROLES, true)) {
        $ramasi = (int) scalar(
            'SELECT COUNT(*) FROM users WHERE role IN (?, ?) AND active = 1 AND id <> ?',
            [GP_ADMIN_ROLES[0], GP_ADMIN_ROLES[1], $id]
        );
        if ($ramasi === 0) {
            fail('Nu poți șterge ultimul administrator.', 422, 'last_admin');
        }
    }

    q('DELETE FROM users WHERE id = ?', [$id]);
    audit('user_delete', 'user', (string) $id, $target['name']);
    json_out(['ok' => true]);
}

/**
 * Codul de pontaj al unui om: pus, schimbat sau șters de administrator.
 *
 * RUTĂ PROPRIE, NU UN CÂMP LA PATCH, și asta din trei motive care se adună:
 *
 *   · Pagina Conturi salvează la ieșirea din casetă, deci trimite la fiecare
 *     apăsare și câmpurile pe care nu le-a atins nimeni. Un cod trecut prin
 *     PATCH ar fi fost rescris — și deci re-hash-uit — la fiecare corectură de
 *     telefon, fără ca cineva să ceară asta.
 *   · `audit_diff()` scrie în jurnal ce s-a schimbat, valoare cu valoare. Un
 *     câmp de cod trecut pe acolo ar fi pus codul, în clar sau nu, într-un
 *     jurnal pe care îl citește orice administrator și care nu se golește
 *     niciodată. Aici se scrie „pus" ori „șters", atât — aceeași regulă ca la
 *     setările secrete (`api/routes/settings.php`).
 *   · E o cheie, nu un dat despre om. Sora ei de dedesubt, `POST
 *     /users/#/password`, e la fel din aceleași pricini.
 *
 * Codul gol înseamnă „ia-i-l": omul dispare de pe tabletă în aceeași clipă,
 * fără să i se schimbe rolul și fără să i se piardă turele de până acum.
 */
function route_users_lab_pin(string $id): void
{
    require_admin();
    require_lab();

    $b   = body();
    $id  = (int) $id;
    $pin = trim((string) ($b['pin'] ?? ''));

    $target = one('SELECT name, username, is_system, active FROM users WHERE id = ?', [$id]);
    if (!$target) {
        fail('Contul nu există.', 404, 'not_found');
    }
    // Contul „panou" e al tabletei, nu al unui om: pontajul lui ar fi tocmai
    // lucrul de care se apără toată lucrarea asta — o tură scrisă pe nimeni.
    if ((int) $target['is_system'] === 1) {
        fail('Contul „panou” este al tabletei, nu al unui om. Codul de pontaj se dă '
            . 'fiecăruia pe contul lui.', 422, 'system_account');
    }

    if ($pin === '') {
        q('UPDATE users SET lab_pin = NULL WHERE id = ?', [$id]);
        clear_attempts(lab_attempt_key($id));
        audit('lab_pin_clear', 'user', (string) $id, $target['name'] . ' · cod de pontaj șters');
        json_out(['ok' => true, 'hasLabPin' => false]);
    }

    if (!preg_match('/^[0-9]{4}$/', $pin)) {
        fail('Codul de pontaj are exact patru cifre.', 422, 'bad_pin');
    }
    // Cele optsprezece coduri pe care le încearcă oricine, primele: patru cifre
    // la fel și cele două șiruri drepte. Nu e o regulă de tărie — patru cifre
    // n-au cum fi tari — ci una de bun-simț: dacă oricine din laborator poate
    // ghici din prima codul colegului, pontajul nu mai spune cine a venit.
    // Restul ghicitului îl oprește limitarea de încercări de pe server.
    $cifre = str_split($pin);
    $laFel = count(array_unique($cifre)) === 1;
    $drept = true;
    $invers = true;
    for ($i = 1; $i < 4; $i++) {
        $drept  = $drept  && ((int) $cifre[$i] === (int) $cifre[$i - 1] + 1);
        $invers = $invers && ((int) $cifre[$i] === (int) $cifre[$i - 1] - 1);
    }
    if ($laFel || $drept || $invers) {
        fail('Codul ăsta se ghicește din prima (1234, 0000 și frații lor). Alege altul.',
            422, 'weak_pin');
    }

    q('UPDATE users SET lab_pin = ? WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $id]);
    // Codul schimbat golește încercările greșite de până acum: altfel omul
    // căruia i s-a pus cod nou rămânea blocat cu el, iar butonul părea că nu
    // face nimic. Aceeași reparație ca la `route_users_password()`, care îl
    // cheamă pe `clear_attempts()` pentru numele de cont.
    clear_attempts(lab_attempt_key($id));
    // ÎN JURNAL NU INTRĂ NICIODATĂ CODUL. Jurnalul e citit de orice
    // administrator și nu se golește; „pus" e tot ce trebuie să scrie acolo.
    audit('lab_pin_set', 'user', (string) $id, $target['name'] . ' · cod de pontaj '
        . ((int) $target['active'] === 1 ? 'pus' : 'pus (cont oprit)'));
    json_out(['ok' => true, 'hasLabPin' => true]);
}

/** Resetarea parolei de către admin. */
function route_users_password(string $id): void
{
    require_admin();
    $b   = body();
    $id  = (int) $id;
    $new = need($b, 'password');

    if (strlen($new) < 8) {
        fail('Parola trebuie să aibă cel puțin 8 caractere.', 422, 'weak_password');
    }
    $target = one('SELECT username, name, is_system, role FROM users WHERE id = ?', [$id]);
    if (!$target) {
        fail('Contul nu există.', 404, 'not_found');
    }
    // Resetarea parolei e a PATRA ușă spre un cont, și era singura lăsată
    // deschisă. Schimbarea rolului (`:376`), dezactivarea (`:397`) și ștergerea
    // (`:443`) trec toate prin `guard_leader_role()` — tocmai ca „de un fondator
    // să nu se atingă decât un fondator". Aici nu se citea nici măcar `role`.
    //
    // Prin ușa asta, un administrator obișnuit lua contul fondatorului fără să
    // treacă pe nicăieri: îi punea el parola, o citea pe ecran („Notează-o
    // acum"), intra cu ea, și de acolo avea exact puterea de care îl feresc
    // `api/routes/founder.php` și paza de mai jos. Nici `must_change_password`
    // nu-l oprea: schimbarea cere parola curentă, adică fix cea scrisă de el.
    // Toată ceremonia cu linkul trimis pe cutia poștală a firmei rămânea decor.
    guard_leader_role('', (string) $target['role']);
    // În contul „panou" nu se intră cu parolă, ci cu codul tabletelor.
    // O parolă pusă aici nu deschidea nimic — nicio pagină nu o cere — dar
    // ștergea sesiunile deschise, adică scotea toate tabletele din laborator
    // afară, în plin lucru, fără ca cineva să înțeleagă de ce.
    if ((int) $target['is_system'] === 1) {
        fail('Contul „panou" nu se deschide cu parolă, ci cu codul tabletelor. '
            . 'Codul se schimbă din Setări → Acces.', 422, 'system_account');
    }

    q(
        // `password_changed_at` se golește: parola de acum e a administratorului,
        // nu una aleasă de om. Cât rămâne NULL, Conturi arată „parolă provizorie",
        // adică exact ce trebuie să știe administratorul când verifică ce parole
        // dictate la telefon mai deschid conturi.
        'UPDATE users SET password_hash = ?, must_change_password = 1, password_changed_at = NULL,
                failed_logins = 0, locked_until = NULL WHERE id = ?',
        [password_hash($new, PASSWORD_DEFAULT), $id]
    );
    // Sesiunile vechi devin invalide după schimbarea parolei.
    q('DELETE FROM sessions WHERE user_id = ?', [$id]);
    // Și linkurile de „am uitat parola" cerute de cont până acum: altfel unul
    // nefolosit rămânea bun încă o oră și punea altă parolă peste cea dată de
    // administrator, fără ca el să afle.
    q('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$id]);
    // Blocarea se numără din login_attempts, nu dintr-o coloană, deci golirea
    // celor două coloane de mai sus nu mai ajungea: contul rămânea blocat și cu
    // parola cea nouă, iar butonul din Conturi părea că nu face nimic.
    clear_attempts((string) $target['username']);

    audit('user_password_reset', 'user', (string) $id, $target['name']);
    json_out(['ok' => true]);
}

/**
 * POST /users/{id}/referral — dă un cod de recomandare unui cont care n-are.
 *
 * Conturile făcute înainte de migrarea 007 au `referral_code` NULL: nu li s-a
 * generat nimic, fiindcă nu exista coloana. Ele NU se ating în masă la import —
 * un UPDATE peste tot tabelul ar fi exact genul de operație care merge prost o
 * singură dată și strică date pe care nimeni nu le mai poate reface. Codul se
 * dă unul câte unul, de la butonul din pagina Conturi, când chiar e nevoie.
 *
 * Nu schimbă rolul, parola, starea sau orice altceva de pe cont: scrie o
 * singură coloană, și numai dacă era goală. Chemată a doua oară pe același cont,
 * întoarce codul existent fără să-l schimbe — un cod deja dat pe la clienți nu
 * are voie să se schimbe sub picioarele lor.
 */
function route_users_referral(string $id): void
{
    require_admin();
    $id = (int) $id;

    if (!referral_supported()) {
        fail('Recomandările nu sunt disponibile: rulează întâi sql/007_update_roles_referral.sql.',
            503, 'referral_unavailable');
    }

    $target = one('SELECT id, name, username, referral_code, is_system FROM users WHERE id = ?', [$id]);
    if (!$target) {
        fail('Contul nu există.', 404, 'not_found');
    }
    // Contul comun „panou" al tabletelor nu recomandă pe nimeni.
    if ((int) $target['is_system'] === 1) {
        fail('Contul „panou” este de sistem și nu poate avea cod de recomandare.', 422, 'system_account');
    }

    $existent = trim((string) ($target['referral_code'] ?? ''));
    if ($existent !== '') {
        json_out(['referralCode' => $existent, 'referralLink' => referral_link($existent), 'created' => false]);
    }

    // Cele două pricini se repară altfel, deci se și spun altfel. Un singur
    // refuz pentru amândouă trimitea omul să schimbe un nume bun.
    $pricina = null;
    $cod = referral_assign($id, (string) $target['name'], (string) $target['username'], $pricina);
    if ($cod === null) {
        if ($pricina === 'toate_luate') {
            fail('Numele „' . $target['name'] . '” e prea des întâlnit: toate codurile care se '
                . 'pot face din el sunt deja luate. Scrie-i unul ales de tine, de la '
                . '„Alege codul", de lângă cont.', 422, 'referral_all_taken');
        }
        fail('Nu am putut construi un cod din numele „' . $target['name'] . '”. '
            . 'Scrie un nume care începe cu litere, apoi încearcă din nou.', 422, 'referral_failed');
    }

    audit('user_referral_code', 'user', (string) $id, $target['name'] . ' → ' . $cod);
    json_out(['referralCode' => $cod, 'referralLink' => referral_link($cod), 'created' => true]);
}

/**
 * Codul și procentul, schimbate DINADINS de administrator.
 *
 * Verb propriu, `PATCH`, nu un câmp în plus la ruta de mai sus. `POST
 * /users/#/referral` doar DĂ un cod contului care n-are, și nu-l schimbă
 * niciodată — butonul din Conturi și comentariul lui se sprijină pe asta. Aici
 * se suprascrie, și urmarea e alta: LINKURILE DEJA TRIMISE CU CODUL VECHI NU
 * MAI RECOMANDĂ PE NIMENI. Două intenții deosebite, două rute deosebite; topite
 * într-una, apăsarea nevinovată ar fi căpătat în tăcere puterea celeilalte.
 */
function route_users_referral_edit(string $id): void
{
    $u  = require_admin();
    $id = (int) $id;

    $target = one('SELECT id, name, referral_code, is_system FROM users WHERE id = ?', [$id]);
    if (!$target) {
        fail('Contul nu există.', 404, 'not_found');
    }
    if ((int) $target['is_system'] === 1) {
        fail('Contul de sistem nu poate avea cod de recomandare.', 422, 'system_account');
    }

    $b       = body();
    $detalii = [];

    if (array_key_exists('code', $b)) {
        $vechi = trim((string) ($target['referral_code'] ?? ''));
        $nou   = referral_code_set($id, (string) $b['code']);
        if ($nou !== $vechi) {
            $detalii[] = 'cod: ' . ($vechi !== '' ? $vechi : '—') . ' → ' . $nou;
        }
    }

    if (array_key_exists('rate', $b)) {
        if (!referral_money_supported()) {
            fail('Procentele nu sunt disponibile: rulează întâi '
                . 'sql/008_comisioane_recomandari.sql.', 503, 'referral_money_unavailable');
        }
        $raw = $b['rate'];
        // Gol sau null înseamnă „ia-l pe cel global" — adică NULL în bază, nu 0.
        // Cele două arată la fel într-un formular și înseamnă lucruri opuse:
        // vezi comentariul coloanei din sql/008.
        if ($raw === null || $raw === '') {
            q('UPDATE users SET referral_rate = NULL WHERE id = ?', [$id]);
            $detalii[] = 'procent: implicit';
        } else {
            if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
                fail('Procentul trebuie să fie un număr între 0 și 100.', 422, 'bad_rate');
            }
            $rate = round((float) $raw, 2);
            q('UPDATE users SET referral_rate = ? WHERE id = ?', [$rate, $id]);
            $detalii[] = 'procent: ' . $rate . '%';
        }
    }

    if (!$detalii) {
        fail('Nimic de modificat.', 422, 'nothing_to_update');
    }
    audit('user_referral_edit', 'user', (string) $id,
        $target['name'] . ' · ' . implode(' · ', $detalii), (int) $u['id']);

    $dupa = one('SELECT referral_code, referral_rate FROM users WHERE id = ?', [$id]);
    json_out([
        'ok'           => true,
        'referralCode' => $dupa['referral_code'],
        'referralLink' => referral_link($dupa['referral_code'] ?? ''),
        'referralRate' => $dupa['referral_rate'] === null ? null : round((float) $dupa['referral_rate'], 2),
    ]);
}
