<?php

declare(strict_types=1);

/**
 * Legătura dintre un cont din aplicație și un cont de Telegram.
 *
 * DE CE EXISTĂ FIȘIERUL ĂSTA
 * ==========================
 *
 * `telegram_actor()` (api/routes/telegram.php) citea o SINGURĂ setare globală,
 * `notify_telegram_actor_id`, scrisă o dată la legarea botului. Iar
 * `$cb['from']['id']` — omul care CHIAR apasă — nu se citea niciodată. Deci
 * orice cerere acceptată de pe telefon purta numele celui care a legat botul,
 * oricine ar fi apăsat.
 *
 * Nu era o scăpare: era singurul răspuns cinstit pe atunci, și scrie așa în
 * docblocul de la `telegram_actor()` — „identitățile din Telegram nu se pot
 * potrivi în niciun fel cu conturile din aplicație, iar un «cine o fi» scris
 * în Jurnal e mai rău decât nimic". Fișierul ăsta face potrivirea care lipsea.
 * Fără el, lucrarea care scoate autorul comenzii la vedere („Creat de …") ar fi
 * scris un nume greșit pe ecran, în loc de o nepotrivire ascunsă în bază.
 *
 * LEGAREA SE FACE ÎN DOI PAȘI, ȘI ĂSTA E MIEZUL
 * =============================================
 *
 * Botul vede o singură conversație: grupul personalului
 * (`notify_telegram_chat_id`, păzit cu `hash_equals` la telegram.php:110-116).
 * Deci butonul de legare pleacă ACOLO, unde apasă oricine.
 *
 * Dacă apăsarea ar lega pe loc, primul coleg care apasă ar căpăta de-atunci
 * înainte comenzile scrise pe numele altuia — tocmai minciuna pe care lucrarea
 * o repară, mutată cu un pas mai încolo și făcută mai greu de văzut.
 *
 * Deci apăsarea nu leagă: PROPUNE. Scrie pe rândul cererii cine a apăsat, iar
 * legătura se mută pe `users.telegram_user_id` numai când cel care a cerut-o
 * spune „da, eu sunt", din contul lui, cu sesiunea lui. Nici graba, nici
 * răutatea nu leagă pe nimeni.
 *
 * De la 2.7.5 mai e un drum, și e numai al fondatorului: el leagă un om văzut
 * în grup de contul lui, din lista „Oameni din grup” din Setări (capătul
 * fișierului). Cei doi pași de mai sus rămân, pentru cine se leagă singur.
 *
 * CE PĂZEȘTE JETONUL, ȘI CE NU
 * ============================
 *
 * Jetonul din buton NU păzește împotriva grupului — toți din grup văd mesajul,
 * deci toți îl au. Împotriva grupului păzește încuviințarea de mai sus.
 *
 * Jetonul leagă apăsarea de MESAJUL ACELA: un buton rămas sub un mesaj vechi,
 * dintr-o cerere de mult consumată sau anulată, nu mai poate deschide o
 * propunere nouă. Telegram trimite apăsarea oricât de vechi ar fi mesajul —
 * asta e scris și în dispecerul de la telegram.php, pentru butoanele scoase.
 *
 * Se ține numai amprenta (`token_hash`), ca la `password_resets` (001),
 * `founder_invites` (009) și `request_claims` (019). Jetonul în clar există o
 * singură dată: în butonul trimis în grup.
 *
 * JETONUL E SCURT DINADINS. `callback_data` are 64 de octeți cu totul, iar
 * forma e „leg:<id>:<jeton>". Un jeton de 32 de octeți (64 de caractere) n-ar
 * fi încăput. 8 octeți — 16 caractere hexazecimale, 64 de biți — sunt destui
 * pentru ce păzește: nu e o parolă, e un număr care trebuie să nu se nimerească
 * din greșeală, iar cine l-ar ghici tot n-ar putea lega nimic fără
 * încuviințarea celui care a cerut-o.
 */

/** Cât ține o cerere de legare nedeschisă. */
const GP_TGLINK_MINUTE = 30;

/**
 * Există tabelul și coloana aduse de migrarea 021?
 *
 * CADE ÎNCHIS, dinadins: pe un server fără migrare nimeni nu e legat, deci
 * fiecare apăsare ia calea omului cu contul nelegat — pe drepturile contului
 * care a legat botul, cu numele celui care a apăsat (de la 2.7.5; v.
 * `telegram_actor()`, api/routes/telegram.php). Jumătatea urcată nu strică
 * nimic.
 *
 * Răspunsul se ține minte pe durata cererii: e o singură interogare, dar ar fi
 * chemată din mai multe locuri pe același request.
 *
 * „NU” ARE DOUĂ ÎNȚELESURI, și al doilea parametru le desparte (de la 2.7.5).
 * „Migrarea lipsește” e un răspuns; „baza n-a răspuns” e lipsa lui. Pentru
 * aproape toți cei care întreabă, amândouă înseamnă „nu fac nimic”. Pentru
 * `telegram_contul_dupa_id()` nu: acolo „nu e legat nimeni” DESCHIDE o cale
 * (omul lucrează pe drepturile contului botului), deci un „nu” spus din
 * neștiință ar fi dat calea aceea și cuiva legat de un cont oprit.
 *
 * @param bool|null $aflat  `false` când răspunsul e „nu” numai fiindcă baza n-a
 *                          putut fi întrebată
 */
function telegram_legaturi_suportate(?bool &$aflat = null): bool
{
    static $ok = null;
    static $stiut = true;
    if ($ok === null) {
        try {
            $coloana = (int) scalar(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                    AND COLUMN_NAME = 'telegram_user_id'"
            );
            $tabel = (int) scalar(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_links'"
            );
            $ok = ($coloana === 1 && $tabel === 1);
        } catch (Throwable $e) {
            error_log('[gp] nu pot verifica suportul de legături Telegram: ' . $e->getMessage());
            $ok    = false;
            $stiut = false;
        }
    }
    $aflat = $stiut;
    return $ok;
}

/**
 * Contul legat de un id de Telegram, sau `null`.
 *
 * Întoarce rândul întreg, cu `active` și `role` cu tot, ca cel care întreabă să
 * poată hotărî singur ce face cu un cont închis sau rămas fără dreptul de a
 * scrie comenzi — v. `telegram_actor()`, unde deosebirea dintre „nelegat" și
 * „legat, dar fără drept" e chiar miezul.
 *
 * INTEROGAREA NU ALEGE DUPĂ `active`, dinadins: un „AND active = 1” pus aici ar
 * face din contul legat și oprit un om „nelegat”, iar acela lucrează pe
 * drepturile contului botului. Rândul se întoarce oricum ar fi, iar cine l-a
 * cerut hotărăște.
 *
 * `null` ARE DOUĂ ÎNȚELESURI, despărțite de `$aflat` (de la 2.7.5). „Nu e legat
 * de niciun cont” e un răspuns al bazei. „N-am putut întreba” (baza a picat pe
 * interogarea asta ori pe cea despre migrarea 021) NU e: până la 2.7.5 se
 * întorcea același `null`, iar `telegram_actor()` îl citea drept „nelegat” și
 * îi dădea omului drepturile contului botului — și celui legat de un cont
 * oprit ori rămas fără drept, în clipa în care baza se bâlbâia o dată.
 *
 * @param bool|null $aflat  `false` când `null` înseamnă „nu știu”, nu „nu e legat”
 */
function telegram_contul_dupa_id(int $tgId, ?bool &$aflat = null): ?array
{
    $aflat = true;
    if ($tgId <= 0) {
        return null;
    }
    if (!telegram_legaturi_suportate($aflat)) {
        return null;
    }
    try {
        $u = one(
            'SELECT id, username, name, email, phone, address, role, customer_name,
                    active, must_change_password, is_system
               FROM users WHERE telegram_user_id = ?',
            [$tgId]
        );
    } catch (Throwable $e) {
        error_log('[gp] căutarea contului după Telegram a picat: ' . $e->getMessage());
        $aflat = false;
        return null;
    }
    return $u ?: null;
}

/**
 * Deschide o cerere de legare pentru un cont și întoarce jetonul în clar.
 *
 * O cerere deschisă pe om: a doua o stinge pe prima, cum face „am uitat
 * parola". Cheia `uq_tglink_user` din migrare o ține de cuvânt și în bază, nu
 * doar aici — două cereri deschise ar însemna două butoane în grup, iar omul
 * n-ar ști pe care l-a apăsat colegul.
 *
 * @return string jetonul în clar, care pleacă în buton și nicăieri altundeva
 */
function telegram_cerere_legare(int $userId): string
{
    $jeton = random_token(8);
    q('DELETE FROM telegram_links WHERE user_id = ?', [$userId]);
    q(
        'INSERT INTO telegram_links (user_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE))',
        [$userId, token_hash($jeton), GP_TGLINK_MINUTE]
    );
    return $jeton;
}

/**
 * Cererea de legare a unui om, așa cum o vede pagina lui.
 *
 * `null` când nu e niciuna deschisă. Nu întoarce NICIODATĂ amprenta jetonului:
 * pagina n-are ce face cu ea, iar un câmp care nu e nevoie să plece nu pleacă.
 */
function telegram_legarea_mea(int $userId): ?array
{
    if (!telegram_legaturi_suportate()) {
        return null;
    }
    try {
        $r = one(
            'SELECT id, pressed_tg_id, pressed_name, pressed_at, expires_at, used_at
               FROM telegram_links WHERE user_id = ?',
            [$userId]
        );
    } catch (Throwable $e) {
        error_log('[gp] nu pot citi cererea de legare: ' . $e->getMessage());
        return null;
    }
    return $r ?: null;
}

/**
 * Cine a apăsat butonul de legare — scris pe rândul cererii, fără să lege nimic.
 *
 * Aici se oprește tot ce poate face grupul. Mai departe merge numai omul din
 * contul lui.
 *
 * Jetonul se caută cu `token_hash` pe amprenta scrisă la deschidere, iar
 * ceasul se citește din bază (`UTC_TIMESTAMP()`), nu din PHP: `expires_at` s-a
 * scris tot cu ceasul bazei, iar două ceasuri deosebite ar da o fereastră
 * strâmbă. (Aici, spre deosebire de producția din §D, amândouă clipele sunt
 * UTC — nu se potrivesc cu nicio tură, deci n-au de ce să fie locale.)
 *
 * @return array{stare:string, userId:int} stare: 'legat_propus' | 'necunoscut' | 'expirat' | 'deja'
 */
function telegram_legare_apasata(int $linkId, string $jeton, int $tgId, string $numeTg): array
{
    if (!telegram_legaturi_suportate()) {
        return ['stare' => 'necunoscut', 'userId' => 0];
    }
    // Amândouă, nu numai jetonul: `callback_data` poartă oricum numărul
    // rândului, fiindcă dispecerul cere o bucată din cifre la mijloc
    // (`ctype_digit`, telegram.php). Un număr purtat degeaba ar fi ajuns, peste
    // un an, să pară că păzește ceva. Cerut și el, chiar păzește: butonul se
    // potrivește cu rândul lui, nu doar cu un jeton care s-ar fi nimerit.
    $r = one(
        'SELECT id, user_id, used_at, expires_at < UTC_TIMESTAMP() AS trecut
           FROM telegram_links WHERE id = ? AND token_hash = ?',
        [$linkId, token_hash($jeton)]
    );
    if (!$r) {
        return ['stare' => 'necunoscut', 'userId' => 0];
    }
    if ($r['used_at'] !== null) {
        return ['stare' => 'deja', 'userId' => (int) $r['user_id']];
    }
    if ((int) $r['trecut'] === 1) {
        return ['stare' => 'expirat', 'userId' => (int) $r['user_id']];
    }

    // Numele de la clipa apăsării: ecranul de încuviințare trebuie să arate pe
    // cine confirmi. Un număr gol n-ar spune nimănui nimic.
    //
    // NU „cum a venit”, de la 2.7.5: `telegram_buton_legare()` îl ia prin
    // `telegram_apasator_din()`, deci ajunge aici curățat și tăiat — coloana se
    // citește înapoi pe ecran și în Jurnal. Nu se mai curăță o dată aici,
    // fiindcă lângă nume stă lipit `@utilizator`, iar tăietura la
    // `GP_TG_NUME_UNITATI` l-ar reteza tocmai pe el.
    q(
        'UPDATE telegram_links
            SET pressed_tg_id = ?, pressed_name = ?, pressed_at = UTC_TIMESTAMP()
          WHERE id = ?',
        [$tgId, mb_substr($numeTg, 0, 128), (int) $r['id']]
    );
    return ['stare' => 'legat_propus', 'userId' => (int) $r['user_id']];
}

/**
 * Încuviințarea: unul dintre cele DOUĂ locuri în care se scrie
 * `users.telegram_user_id`. Celălalt, de la 2.7.5, e `telegram_om_leaga()` de
 * la capătul fișierului — legarea făcută de fondator din „Oameni din grup”.
 * În amândouă numărul se ia de pe un rând scris de server la apăsare,
 * niciodată din cerere.
 *
 * Se cheamă din ruta celui care a cerut legarea, cu sesiunea lui. Id-ul de
 * Telegram nu vine din cerere, ci de pe rândul scris la apăsare: altfel omul
 * și-ar putea trece pe cont ORICE număr, inclusiv pe al altuia, iar apoi
 * apăsările aceluia i-ar purta numele. Cererea spune doar „da", nu „da, ăsta".
 *
 * @return array{ok:bool, pricina:string, nume:string}
 */
function telegram_incuviinteaza(int $userId): array
{
    if (!telegram_legaturi_suportate()) {
        return ['ok' => false, 'pricina' => 'nesuportat', 'nume' => ''];
    }
    $r = one(
        'SELECT id, pressed_tg_id, pressed_name, used_at,
                expires_at < UTC_TIMESTAMP() AS trecut
           FROM telegram_links WHERE user_id = ?',
        [$userId]
    );
    if (!$r || $r['pressed_tg_id'] === null) {
        return ['ok' => false, 'pricina' => 'neapasat', 'nume' => ''];
    }
    if ($r['used_at'] !== null) {
        return ['ok' => false, 'pricina' => 'deja', 'nume' => (string) $r['pressed_name']];
    }
    if ((int) $r['trecut'] === 1) {
        return ['ok' => false, 'pricina' => 'expirat', 'nume' => (string) $r['pressed_name']];
    }

    $tgId = (int) $r['pressed_tg_id'];

    // `uq_users_telegram` din migrare oprește două conturi pe același Telegram.
    // Prins aici ca să iasă o vorbă omenească, nu un 500 cu „Duplicate entry":
    // se întâmplă chiar când cineva își leagă al doilea cont din greșeală, iar
    // ăsta e un lucru de spus, nu o eroare de server.
    $altul = one('SELECT id, name FROM users WHERE telegram_user_id = ? AND id <> ?', [$tgId, $userId]);
    if ($altul) {
        return ['ok' => false, 'pricina' => 'luat', 'nume' => (string) $altul['name']];
    }

    // CURSA CU FONDATORUL (val5 a rundei 2.7.5). Între întrebarea de mai sus și
    // scriere încape „Leagă” din „Oameni din grup” (`telegram_om_leaga()`).
    // Fără `AND telegram_user_id IS NULL`, încuviințarea scria pe tăcute alt
    // Telegram peste legătura făcută de el chiar atunci, iar Jurnalul lui
    // spunea altceva decât baza. Același Telegram pus între timp pe ALT cont îl
    // oprește cheia `uq_users_telegram` (migrarea 021) — prinsă aici, ca să iasă
    // o vorbă, nu un 500. Cererea se stinge numai DUPĂ o scriere reușită: cea
    // care pierde cursa rămâne, iar omul o poate încuviința după ce se lămurește.
    try {
        $legat = q(
            'UPDATE users SET telegram_user_id = ?
              WHERE id = ? AND telegram_user_id IS NULL',
            [$tgId, $userId]
        )->rowCount() === 1;
    } catch (PDOException $e) {
        // Numai rândul duplicat (1062), ca la `q_unique()`: orice altă eroare a
        // bazei e o defecțiune adevărată și urcă mai departe.
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            $al = one('SELECT name FROM users WHERE telegram_user_id = ?', [$tgId]);
            return ['ok' => false, 'pricina' => 'luat', 'nume' => $al ? (string) $al['name'] : ''];
        }
        throw $e;
    }
    if (!$legat) {
        // Contul are deja un Telegram: chiar acesta (a doua încuviințare, din
        // altă filă) ori altul, legat între timp de fondator.
        $acum = (int) (scalar('SELECT telegram_user_id FROM users WHERE id = ?', [$userId]) ?? 0);
        return ['ok' => false, 'pricina' => $acum === $tgId ? 'deja' : 'cont_legat', 'nume' => (string) $r['pressed_name']];
    }
    q('UPDATE telegram_links SET used_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $r['id']]);
    return ['ok' => true, 'pricina' => '', 'nume' => (string) $r['pressed_name']];
}

/** Dezlegarea: contul nu mai are Telegram, iar cererile deschise pleacă. */
function telegram_dezleaga(int $userId): void
{
    if (!telegram_legaturi_suportate()) {
        return;
    }
    q('UPDATE users SET telegram_user_id = NULL WHERE id = ?', [$userId]);
    q('DELETE FROM telegram_links WHERE user_id = ?', [$userId]);
}

/* ============================================ cine a apăsat, cu numele lui == */

/*
 * RUNDA 2.7.5 — „VREAU SĂ ȘTIU CINE A ACCEPTAT”
 * =============================================
 *
 * Botul stă într-un GRUP cu oamenii casei, iar dintre ei numai unul-doi și-au
 * legat contul. Până acum apăsarea oricui altcuiva se scria pe contul care a
 * legat botul — cu tot cu NUMELE lui: sub mesaj, în anunțul de după, în panou
 * și în Jurnal scria că a acceptat patronul.
 *
 * HOTĂRÂREA PATRONULUI: apăsarea unui om cu contul nelegat MERGE mai departe,
 * dar poartă NUMELE LUI de pe Telegram și vorba „cont nelegat”. Niciodată
 * numele altcuiva.
 *
 * REGULA LUCRĂRII, de ținut minte la orice loc nou în care se scrie cine a
 * hotărât: pentru cel nelegat, contul botului rămâne purtătorul de DREPTURI (pe
 * el trec porțile rutelor, el stă în `handled_by` și în `audit_log.user_id`);
 * NUMELE e întotdeauna al celui care a apăsat.
 *
 * NUMELE DE PE TELEGRAM E TEXT STRĂIN. Și-l scrie fiecare cum vrea, iar aici
 * ajunge sub un mesaj, într-un anunț, pe ecranul panoului și în Jurnal. De
 * aceea are O SINGURĂ ușă de intrare — `telegram_apasator_din()` — și o singură
 * curățenie, `telegram_nume_curat()`. Nimeni altcineva nu citește `first_name`,
 * `last_name` ori `username` dintr-o apăsare; .claude/check-telegram-apasator.mjs
 * le numără.
 */

/**
 * Cât de lung poate fi un nume venit de pe Telegram, în unități UTF-16 — cu tot
 * cu „…” de la tăietură.
 *
 * UNITĂȚI UTF-16, nu litere: așa numără Telegram cele 4096 ale unui mesaj, iar
 * un emoji face două. `telegram_text_taiat()` (api/lib/notify.php) lasă 96 sub
 * orice anunț pentru urma hotărârii, iar cea mai lungă urmă e
 *
 *   „✅ Acceptată de ” (15) + nume (40) + „, de pe Telegram” (16)
 *   + „ (cont nelegat)” (15) = 86, plus cele două rânduri noi dinaintea ei = 88.
 *
 * Mărită peste 48, urma nu mai încape sub un anunț tăiat și `editMessageText`
 * e refuzat cu totul — adică tocmai mesajele lungi rămân fără semnul că s-a
 * hotărât. .claude/check-anunturi.mjs face socoteala cu funcțiile de aici.
 */
const GP_TG_NUME_UNITATI = 40;

/** Câți oameni ține, cel mult, lista „Oameni din grup” — v. `telegram_om_vazut()`. */
const GP_TG_OAMENI_MAX = 200;

/**
 * Un nume venit de pe Telegram, făcut bun de scris oriunde: un singur rând,
 * fără semne nevăzute, cel mult `GP_TG_NUME_UNITATI` unități.
 *
 * CE SCOATE, și de ce pe fiecare:
 *
 *   · semnele de direcție și celelalte semne „de format” (categoria Cf: U+202A–
 *     U+202E, U+2066–U+2069, U+200E/F, lățimile zero). Un U+202E într-un nume
 *     întoarce pe ecran tot ce urmează după el — și vorba „cont nelegat”, și
 *     restul rândului din Jurnal. E singura cale prin care un nume ar putea
 *     minți despre ce scrie LÂNGĂ el;
 *   · umpluturile care se desenează goale (U+3164 și rudele lui), cu care se
 *     face un nume „fără nimic”;
 *   · rândurile noi, taburile și orice alt spațiu ori semn de control: devin
 *     UN spațiu. Un nume pe două rânduri ar scrie al doilea rând ca pe un rând
 *     al anunțului („Acceptată de: Ion⏎Client adus de: nimeni”);
 *   · semnele de îmbinare stivuite (categoria M: accentele puse peste literă,
 *     selectoarele de variantă). O literă poate purta oricâte, iar treizeci de
 *     accente pe un „Z” se desenează ca o coloană care trece peste rândurile de
 *     deasupra și de dedesubt, în Jurnal și în panou. Rămân cel mult TREI la
 *     rând — cât le trebuie scrierilor adevărate —, iar cele fără nicio literă
 *     sub ele (la început ori după un spațiu) pleacă de tot: s-ar fi așezat pe
 *     vorba NOASTRĂ de dinaintea numelui („Acceptată de”).
 *
 * Ce rămâne fără nicio literă, cifră ori semn care se vede iese ȘIRUL GOL, iar
 * cine cheamă pune altceva în loc (`telegram_apasator_din()`): un nume alcătuit
 * numai din spații n-are voie să ajungă „Acceptată de , de pe Telegram”.
 *
 * TĂIETURA se face pe litere întregi, numărând unitățile UTF-16 — niciodată în
 * mijlocul unui emoji —, și lasă loc pentru „…”, ca omul să vadă că numele a
 * fost mai lung.
 *
 * A doua trecere nu mai schimbă nimic: funcția se poate chema liniștit și pe
 * un nume deja curățat (cum face `telegram_semn()` cu numele contului).
 *
 * NU scapă nimic pentru HTML ori Markdown, dinadins: mesajele pleacă text
 * simplu (`send_telegram()` nu cere `parse_mode`), iar paginile își pun singure
 * textul în pagină ca text.
 */
function telegram_nume_curat(string $s): string
{
    // Octeții care nu fac UTF-8 devin semnul de înlocuire; altfel `preg_replace`
    // cu `/u` ar întoarce null pe tot numele, pentru un singur octet strâmb.
    $s = mb_scrub($s, 'UTF-8');
    $s = (string) preg_replace('/[\p{Cf}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{2800}\x{3164}\x{FFA0}]+/u', '', $s);
    // DUPĂ rândul de mai sus, nu înaintea lui: un semn nevăzut pus între două
    // accente le-ar fi despărțit în două stive scurte, care se lipesc la loc
    // când el pleacă.
    $s = (string) preg_replace('/(?:^|(?<=[\p{Cc}\p{Z}]))\p{M}+/u', '', $s);
    $s = (string) preg_replace('/(\p{M}{3})\p{M}+/u', '$1', $s);
    $s = trim((string) preg_replace('/[\p{Cc}\p{Z}]+/u', ' ', $s));
    // Numai semne de îmbinare și spații = nimic de citit.
    if ($s === '' || preg_match('/[^\p{M}\p{Z}]/u', $s) !== 1) {
        return '';
    }

    // În UTF-8, patru octeți = o literă de dincolo de U+FFFF = două unități.
    $unitati = static fn(string $c): int => strlen($c) === 4 ? 2 : 1;
    $litere  = mb_str_split($s, 1, 'UTF-8');
    if (array_sum(array_map($unitati, $litere)) <= GP_TG_NUME_UNITATI) {
        return $s;
    }
    $out = '';
    $n   = 0;
    foreach ($litere as $c) {
        // „- 1”: locul lui „…”.
        if ($n + $unitati($c) > GP_TG_NUME_UNITATI - 1) {
            break;
        }
        $out .= $c;
        $n   += $unitati($c);
    }
    return rtrim($out) . '…';
}

/**
 * Cine a apăsat, citit din `from`-ul unei apăsări — SINGURA ușă prin care un
 * nume de pe Telegram intră în program.
 *
 * `null` când apăsarea n-are un `from.id` bun (număr întreg, pozitiv). Fără el
 * nu se poate ști nici dacă omul e legat de un cont, nici pe numele cui să se
 * scrie fapta, iar „un om oarecare” e chiar răspunsul pe care lucrarea asta îl
 * scoate din casă — deci `telegram_actor()` refuză apăsarea.
 *
 * NUMELE, în ordinea asta: prenume + nume; în lipsa lor, `@utilizator`; în
 * lipsa lui, „Telegram #număr”. Niciodată gol. Prenumele și numele NU pot
 * purta „@” (v. `$faraSemn`, mai jos): semnul e al numelui de utilizator.
 *
 * `user` e numele de utilizator FĂRĂ „@”, ori șirul gol. Telegram îl ține în
 * litere latine, cifre și „_”. Trece întâi prin aceeași curățenie ca numele
 * (semnele nevăzute cad); ce nu e nici atunci un nume de utilizator se aruncă,
 * nu se îndreaptă.
 *
 * @param mixed $from `callback_query.from`, cum a venit
 * @return array{tg:int, nume:string, user:string}|null
 */
function telegram_apasator_din($from): ?array
{
    if (!is_array($from)) {
        return null;
    }
    $id = $from['id'] ?? null;
    if (is_string($id) && ctype_digit($id) && strlen($id) <= 18) {
        $id = (int) $id;
    }
    if (!is_int($id) || $id <= 0) {
        return null;
    }

    $bucata = static fn($v): string => is_string($v) ? telegram_nume_curat($v) : '';
    $user   = ltrim($bucata($from['username'] ?? null), '@');
    if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $user) !== 1) {
        $user = '';
    }
    // „@” E AL NOSTRU, NU AL OMULUI. Lângă nume, „(@ionpop)” înseamnă în toată
    // casa „numele de utilizator pe care îl dă TELEGRAM” — singurul lucru din
    // rând pe care omul nu și-l scrie cum vrea. Lăsat în prenume, oricine își
    // punea numele „Ion (@patron)” și rândul din Jurnal îl arăta cu
    // utilizatorul altuia. Deci din prenume și din nume semnul pleacă (și
    // cele două forme late ale lui), iar un „@” rămâne numai unde îl punem noi.
    $faraSemn = static fn(string $s): string => (string) preg_replace('/[@\x{FF20}\x{FE6B}]+/u', '', $s);
    $nume = telegram_nume_curat($faraSemn($bucata($from['first_name'] ?? null)) . ' ' . $faraSemn($bucata($from['last_name'] ?? null)));
    if ($nume === '') {
        $nume = $user !== '' ? '@' . $user : 'Telegram #' . $id;
    }
    return ['tg' => $id, 'nume' => $nume, 'user' => $user];
}

/** Numele celui care a apăsat, curățat; șirul gol când apăsarea n-are `from.id`. */
function telegram_nume_apasator($from): string
{
    $ap = telegram_apasator_din($from);
    return $ap === null ? '' : $ap['nume'];
}

/**
 * Există coloanele și tabelul aduse de migrarea 036?
 *
 * CADE ÎNCHIS, ca `telegram_legaturi_suportate()` — dar aici „închis” nu mai
 * înseamnă „ca până acum”. Fără cele două coloane de pe cerere, numele celui
 * nelegat n-are unde se scrie, iar hotărârea lui ar ieși iar pe numele contului
 * care a legat botul. Deci `telegram_actor()` îi REFUZĂ apăsarea, cu un mesaj
 * care numește fișierul. Cine are contul legat lucrează mai departe.
 *
 * Se cer amândouă jumătățile migrării, nu numai coloanele: tabelul e făcut de
 * același fișier, iar o jumătate lipsă înseamnă că fișierul n-a rulat întreg.
 *
 * `$numaiColoanele` e pentru cine CITEȘTE ori ȘTERGE un nume deja scris pe
 * cerere (`apasatorii_comenzilor()`, `requests_sterge_apasator()`): lor le
 * ajung cele două coloane. Cerând și tabelul, pe o bază rămasă fără el numele
 * scrise nu s-ar mai fi citit — panoul ar fi arătat iar contul botului — și
 * nici nu s-ar mai fi șters la redeschidere, deci ar fi trecut drept al celui
 * care acceptă apoi cererea din panou. SCRIEREA unui nume nou cere mai departe
 * migrarea întreagă.
 */
function telegram_apasatori_suportati(bool $numaiColoanele = false): bool
{
    // [coloanele, tabelul], aflate o singură dată pe cerere.
    static $are = null;
    if ($are === null) {
        try {
            $coloane = (int) scalar(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
                    AND COLUMN_NAME IN ('handled_tg_id', 'handled_tg_name')"
            );
            $tabel = (int) scalar(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telegram_oameni'"
            );
            $are = [$coloane === 2, $tabel === 1];
        } catch (Throwable $e) {
            error_log('[gp] nu pot verifica suportul pentru apăsătorii de pe Telegram: ' . $e->getMessage());
            $are = [false, false];
        }
    }
    return $numaiColoanele ? $are[0] : ($are[0] && $are[1]);
}

/**
 * Ține la zi lista „Oameni din grup”: omul ăsta a apăsat acum.
 *
 * Se cheamă la FIECARE apăsare care a trecut de poarta conversației, oricare ar
 * fi butonul — și la cele refuzate mai jos. Din lista asta își alege
 * fondatorul, în Setări, pe cine să lege de ce cont (`telegram_oameni_lista()`,
 * la capătul fișierului); altfel ar trebui să afle de la fiecare un număr de
 * zece cifre.
 *
 * NU ARE VOIE SĂ OPREASCĂ APĂSAREA. E o însemnare pe margine: dacă pică, omul
 * tot trebuie să-și poată accepta cererea. De aceea tot ce atinge baza stă în
 * `try`, iar piedica se scrie numai în logul serverului.
 *
 * LISTA NU CREȘTE LA NESFÂRȘIT. Un grup are zece oameni, dar tabelul e scris de
 * o rută publică, iar „zece” e o presupunere despre lume, nu o pază. Peste
 * `GP_TG_OAMENI_MAX` rânduri se scot cei mai de demult văzuți dintre cei
 * NELEGAȚI: un om legat nu se scoate niciodată — lângă numele lui stă
 * legătura, iar fără rând fondatorul n-ar mai avea de unde s-o desfacă.
 * Nici cel care tocmai a apăsat nu se scoate.
 *
 * Ceasul e al bazei și e UTC (`UTC_TIMESTAMP()`), ca la `telegram_links`.
 *
 * @param mixed $from `callback_query.from`, cum a venit
 */
function telegram_om_vazut($from): void
{
    $ap = telegram_apasator_din($from);
    if ($ap === null || !telegram_apasatori_suportati()) {
        return;
    }
    try {
        q(
            'INSERT INTO telegram_oameni (tg_id, name, username, first_seen_at, last_seen_at, apasari)
             VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), username = VALUES(username),
                                     last_seen_at = UTC_TIMESTAMP(), apasari = apasari + 1',
            [$ap['tg'], $ap['nume'], $ap['user'] === '' ? null : $ap['user']]
        );

        $prisos = (int) scalar('SELECT COUNT(*) FROM telegram_oameni') - GP_TG_OAMENI_MAX;
        if ($prisos <= 0) {
            return;
        }
        // Fără migrarea 021 nu e nimeni legat, iar coloana după care s-ar
        // alătura nici nu există.
        $nelegati = telegram_legaturi_suportate()
            ? 'SELECT o.id FROM telegram_oameni o
                 LEFT JOIN users u ON u.telegram_user_id = o.tg_id
                WHERE u.id IS NULL AND o.tg_id <> ?
                ORDER BY o.last_seen_at, o.id LIMIT ' . $prisos
            : 'SELECT o.id FROM telegram_oameni o
                WHERE o.tg_id <> ?
                ORDER BY o.last_seen_at, o.id LIMIT ' . $prisos;
        $deScos = array_map(static fn(array $r): int => (int) $r['id'], all($nelegati, [$ap['tg']]));
        if ($deScos) {
            q(
                'DELETE FROM telegram_oameni WHERE id IN (' . implode(',', array_fill(0, count($deScos), '?')) . ')',
                $deScos
            );
        }
    } catch (Throwable $e) {
        error_log('[gp] telegram: nu pot însemna omul văzut în grup: ' . $e->getMessage());
    }
}

/**
 * Urma scrisă sub mesaj: cine a hotărât, cu numele lui.
 *
 *   ✅ Acceptată de Ion Pop, de pe Telegram (cont nelegat)
 *   ✅ Acceptată de Maria Ionescu, de pe Telegram
 *   ❌ Respinsă de …                              — la fel
 *
 * Până la 2.7.5 scria „✅ Acceptată de pe telefon”, fără niciun nume: sub zece
 * cereri hotărâte de trei oameni stătea același rând, iar în grup nu se putea
 * afla cine a apăsat fără un drum în Jurnal — unde scria, oricum, patronul.
 *
 * `$actor` e contul pe care lucrează apăsarea, cum îl dă `telegram_actor()`.
 * Cu `_apasator` pe el (om cu contul nelegat), numele e AL CELUI CARE A APĂSAT,
 * iar al contului nu apare deloc. Fără, e al contului — care e chiar omul.
 *
 * ȘI NUMELE CONTULUI trece prin `telegram_nume_curat()`: nu fiindcă n-ar fi de
 * încredere, ci pentru tăietură. Urma are loc numărat sub mesaj (v.
 * `GP_TG_NUME_UNITATI`), iar un cont cu numele de o sută de litere ar fi lăsat
 * mesajele lungi fără ea.
 *
 * @param string $fapta „✅ Acceptată” ori „❌ Respinsă”
 */
function telegram_semn(string $fapta, array $actor): string
{
    // Cheia e ACOLO ⇒ contul e numai purtător de drepturi, iar numele lui nu se
    // mai citește pe ramura asta deloc. Un nume gol al apăsătorului nu se poate
    // naște (`telegram_apasator_din()` cade pe „Telegram #număr”), dar dacă ar
    // ajunge vreodată aici, urma rămâne fără nume — nu cu al altcuiva.
    if (array_key_exists('_apasator', $actor)) {
        $ap   = $actor['_apasator'];
        $nume = is_array($ap) ? telegram_nume_curat((string) ($ap['nume'] ?? '')) : '';
        return $fapta . ($nume === '' ? '' : ' de ' . $nume) . ', de pe Telegram (cont nelegat)';
    }
    $nume = telegram_nume_curat((string) ($actor['name'] ?? ''));
    // Un cont fără nume nu există în casă; dar un „de , de pe Telegram” n-are
    // voie să ajungă sub un mesaj nici atunci.
    return $nume === '' ? $fapta . ' de pe Telegram' : $fapta . ' de ' . $nume . ', de pe Telegram';
}

/* =================================== „Oameni din grup”: lista din Setări == */

/*
 * FONDATORUL LEAGĂ OAMENII DE CONTURI (runda 2.7.5, pasul al doilea)
 * ==================================================================
 *
 * Legarea în doi pași de la începutul fișierului („eu sunt” → „da, eu am
 * apăsat”) e a celui care își leagă SINGUR contul, și a rămas a
 * administratorilor. Restul casei — managerul, omul de la ambalare — n-are
 * pagina Setărilor: pe ei îi leagă FONDATORUL, dintr-o listă cu cine a apăsat
 * vreodată un buton în grup (`telegram_om_vazut()`).
 *
 * DE CE NUMAI FONDATORUL. Legătura hotărăște al cui nume se scrie sub o cerere
 * acceptată. Cine poate lega pe oricine de orice cont poate face ca apăsările
 * lui Ion să poarte numele Mariei — adică poate scrie orice nume în Jurnal. E
 * puterea paginii „Acces”, deci stă după poarta ei (`require_acces()`), nu
 * după rangul de administrator.
 *
 * NUMELE DIN LISTĂ NU DOVEDEȘTE NIMIC. Și-l pune fiecare singur pe Telegram,
 * iar doi oameni se pot numi la fel. De aceea pagina o spune lângă listă, iar
 * aici omul se numește prin RÂNDUL lui din `telegram_oameni` (`id`).
 *
 * NUMĂRUL DE TELEGRAM NU PLEACĂ SPRE PAGINĂ ȘI NU SE SCRIE ÎN JURNAL — nici
 * `tg_id`, nici `telegram_user_id`. Numește un om în afara aplicației (v.
 * `route_telegram_me()`), iar pagina n-are ce face cu el. Singura abatere,
 * hotărâtă la pasul întâi: omul fără nume și fără `@utilizator` se cheamă
 * „Telegram #număr” (`telegram_apasator_din()`), deci numărul lui stă în
 * NUMELE lui — altfel doi oameni fără nume nu s-ar deosebi în listă.
 */

/** Câți oameni arată lista din Setări: cei mai de curând văzuți. */
const GP_TG_OAMENI_LISTA = 50;

/**
 * Regula ȚINTEI: de ce cont se poate lega un om din grup.
 *
 * Un cont PORNIT, al unui om al casei: nu contul comun al tabletelor
 * (`is_system`), care n-are un om în spate, și nu un client. Scrisă O DATĂ, ca
 * bucată de SQL, și citită din două locuri — lista din care alege fondatorul
 * și verificarea de la legare —, ca pagina să nu poată oferi un cont pe care
 * serverul îl refuză.
 *
 * NU cere dreptul `comenzi.scrie`. Un angajat legat nu poate hotărî cereri
 * (`telegram_actor()` îl refuză, cu vorba lui), dar apăsarea lui nu mai trece
 * drept a unui necunoscut pe drepturile botului, iar în ziua în care primește
 * dreptul din „Acces” legătura e deja făcută. Lista spune care sunt aceia
 * (`poate`), iar pagina o scrie lângă nume.
 */
const GP_TG_TINTA_SQL = "active = 1 AND is_system = 0 AND role <> 'client'";

/**
 * Lista „Oameni din grup”, cum pleacă spre pagină: cei mai de curând văzuți,
 * fiecare cu contul de care e legat (ori `null`).
 *
 * CHEILE SE SCRIU UNA CÂTE UNA, dinadins. Un `return $randuri;` ar trimite tot
 * ce a ales interogarea, iar în ziua în care cineva îi adaugă o coloană
 * numărul de Telegram ar pleca odată cu ea. Aici pleacă numai ce e numit.
 *
 * Numele trece încă o dată prin `telegram_nume_curat()`, deși a fost curățat
 * la scriere: a doua trecere nu schimbă nimic, iar un rând ajuns în tabel pe
 * altă cale decât `telegram_om_vazut()` n-are voie să scrie pe ecran ce vrea.
 *
 * `poate` — are contul legat dreptul de a hotărî cereri, ACUM? E aceeași
 * întrebare pe care o pune `telegram_actor()` la apăsare (pornit și cu
 * `comenzi.scrie`), ca pagina să poată spune dinainte „apăsările lui sunt
 * refuzate”, nu să afle omul din toast.
 *
 * @return list<array{id:int, nume:string, utilizator:?string, vazutLa:string, apasari:int, cont:?array}>
 */
function telegram_oameni_lista(): array
{
    $randuri = all(
        'SELECT o.id, o.name, o.username, o.last_seen_at, o.apasari,
                u.id AS cont_id, u.name AS cont_nume, u.role AS cont_rol,
                u.active AS cont_activ, u.is_system AS cont_sistem
           FROM telegram_oameni o
           LEFT JOIN users u ON u.telegram_user_id = o.tg_id
          ORDER BY o.last_seen_at DESC, o.id DESC
          LIMIT ' . GP_TG_OAMENI_LISTA
    );

    $oameni = [];
    foreach ($randuri as $r) {
        $cont = null;
        if (($r['cont_id'] ?? null) !== null) {
            // Rândul de cont, cu numele de coloane pe care le citește `drept()`.
            $legat  = ['id' => (int) $r['cont_id'], 'role' => $r['cont_rol'], 'is_system' => $r['cont_sistem']];
            $pornit = (int) $r['cont_activ'] === 1;
            $cont   = [
                'id'    => (int) $r['cont_id'],
                'nume'  => (string) $r['cont_nume'],
                'rol'   => (string) $r['cont_rol'],
                'activ' => $pornit,
                'poate' => $pornit && drept('comenzi.scrie', $legat),
            ];
        }
        $utilizator = telegram_nume_curat((string) ($r['username'] ?? ''));
        $oameni[]   = [
            'id'         => (int) $r['id'],
            'nume'       => telegram_nume_curat((string) $r['name']),
            'utilizator' => $utilizator === '' ? null : $utilizator,
            // Ceasul bazei, UTC (`telegram_om_vazut()`); pagina îl mută pe
            // ceasul patiseriei.
            'vazutLa'    => (string) $r['last_seen_at'],
            'apasari'    => (int) $r['apasari'],
            'cont'       => $cont,
        ];
    }
    return $oameni;
}

/**
 * Conturile dintre care alege fondatorul: cele care împlinesc regula țintei
 * (`GP_TG_TINTA_SQL`) și n-au încă un Telegram legat.
 *
 * `poate` e dreptul `comenzi.scrie` al contului: cel fără el rămâne în listă,
 * iar pagina îi scrie în coadă „nu poate hotărî cereri”.
 *
 * @return list<array{id:int, nume:string, rol:string, poate:bool}>
 */
function telegram_conturi_de_legat(): array
{
    $conturi = [];
    foreach (all(
        'SELECT id, name, role, is_system FROM users
          WHERE ' . GP_TG_TINTA_SQL . ' AND telegram_user_id IS NULL
          ORDER BY name, id'
    ) as $c) {
        $conturi[] = [
            'id'    => (int) $c['id'],
            'nume'  => (string) $c['name'],
            'rol'   => (string) $c['role'],
            'poate' => drept('comenzi.scrie', $c),
        ];
    }
    return $conturi;
}

/**
 * Conturile legate de un Telegram care n-au rând în lista de mai sus (fișa
 * 710, 2.7.5): legate înainte de 036 — atunci nu se ținea minte cine apasă —
 * și care n-au mai apăsat nimic de atunci, ori căzute din cei 50 arătați.
 * Fără ele, fondatorul nu le găsea nicăieri ca să le dezlege: pagina dezleagă
 * numai din rânduri, iar „Dezleagă-mi contul” e numai pentru sine.
 *
 * Numărul de Telegram stă numai în `WHERE`: nu e ales, deci nu pleacă.
 *
 * @param list<array{cont:?array}> $oameni lista deja aleasă
 * @return list<array{id:int, nume:string, rol:string, activ:bool}>
 */
function telegram_legati_nevazuti(array $oameni): array
{
    $vazuti = [];
    foreach ($oameni as $o) {
        if (is_array($o['cont'] ?? null)) {
            $vazuti[(int) $o['cont']['id']] = true;
        }
    }
    $conturi = [];
    foreach (all(
        'SELECT id, name, role, active FROM users
          WHERE telegram_user_id IS NOT NULL
          ORDER BY name, id'
    ) as $c) {
        if (isset($vazuti[(int) $c['id']])) {
            continue;
        }
        $conturi[] = [
            'id'    => (int) $c['id'],
            'nume'  => (string) $c['name'],
            'rol'   => (string) $c['role'],
            'activ' => (int) $c['active'] === 1,
        ];
    }
    return $conturi;
}

/**
 * Tot ce-i trebuie cutiei „Oameni din grup”, într-un singur răspuns.
 *
 * Îl dau toate cele trei uși (lista, legarea, dezlegarea): după o faptă pagina
 * își redesenează cutia din răspunsul ei, fără a doua cerere — iar între două
 * cereri lista s-ar fi putut schimba sub ochii omului.
 *
 * `total` e tot tabelul, nu numai cei arătați: pagina spune „se văd cei mai
 * noi 50 din N” când grupul e mai mare decât lista.
 */
function telegram_oameni_raspuns(): array
{
    $oameni = telegram_oameni_lista();
    return [
        'oameni'  => $oameni,
        'conturi' => telegram_conturi_de_legat(),
        'legatiNevazuti' => telegram_legati_nevazuti($oameni),
        'total'   => (int) scalar('SELECT COUNT(*) FROM telegram_oameni'),
        'maxim'   => GP_TG_OAMENI_LISTA,
    ];
}

/**
 * Un om din listă, cum se scrie într-o vorbă ori în Jurnal: „Ion Pop (@ionpop)”.
 *
 * `@utilizator` stă lângă nume fiindcă numele și-l schimbă omul când vrea, iar
 * peste un an rândul din Jurnal trebuie să mai spună despre cine e vorba. Nu
 * se repetă când numele e chiar el (omul fără prenume). NUMĂRUL de Telegram nu
 * intră aici: funcția nici nu-l primește de citit.
 *
 * @param array $om un rând din `telegram_oameni` (`name`, `username`)
 */
function telegram_om_eticheta(array $om): string
{
    $nume = telegram_nume_curat((string) ($om['name'] ?? ''));
    $user = telegram_nume_curat((string) ($om['username'] ?? ''));
    return $nume . ($user !== '' && $nume !== '@' . $user ? ' (@' . $user . ')' : '');
}

/**
 * Leagă un om din listă de un cont. Fapta fondatorului, fără încuviințarea
 * nimănui: el răspunde de ea, și de aceea se scrie în Jurnal cu numele lui.
 *
 * Întoarce PRICINA, nu refuză singură — ca `telegram_incuviinteaza()`: vorba
 * omenească și codul refuzului le alege ruta. Ordinea întrebărilor e ordinea
 * în care are omul ceva de îndreptat: întâi rândul apăsat (a dispărut din
 * listă?), apoi contul ales, apoi ce e deja legat.
 *
 *   'om_necunoscut'  rândul nu mai e în `telegram_oameni` (lista se scurtează
 *                    singură la `GP_TG_OAMENI_MAX`);
 *   'cont_nepotrivit' contul nu există ori nu împlinește regula țintei;
 *   'luat'           omul e deja legat de un cont — și de ACELAȘI: un om are un
 *                    singur cont, iar a doua legare nu mută nimic pe tăcute;
 *   'cont_legat'     contul are deja alt Telegram.
 *
 * `altul` e numele contului de care se lovește refuzul, pentru vorba lui.
 *
 * @return array{ok:bool, pricina:string, om:string, cont:string, altul:string}
 */
function telegram_om_leaga(int $omId, int $contId): array
{
    $r = ['ok' => false, 'pricina' => '', 'om' => '', 'cont' => '', 'altul' => ''];

    $om = one('SELECT id, tg_id, name, username FROM telegram_oameni WHERE id = ?', [$omId]);
    if (!$om) {
        return ['pricina' => 'om_necunoscut'] + $r;
    }
    $r['om'] = telegram_om_eticheta($om);

    $cont = one(
        'SELECT id, name, telegram_user_id, (' . GP_TG_TINTA_SQL . ') AS buna FROM users WHERE id = ?',
        [$contId]
    );
    if (!$cont || (int) $cont['buna'] !== 1) {
        return ['pricina' => 'cont_nepotrivit'] + $r;
    }
    $r['cont'] = (string) $cont['name'];

    $tgId  = (int) $om['tg_id'];
    $alCui = one('SELECT id, name FROM users WHERE telegram_user_id = ?', [$tgId]);
    if ($alCui) {
        return ['pricina' => 'luat', 'altul' => (string) $alCui['name']] + $r;
    }
    if ($cont['telegram_user_id'] !== null) {
        return ['pricina' => 'cont_legat', 'altul' => (string) $cont['name']] + $r;
    }

    // `AND telegram_user_id IS NULL`: între întrebarea de mai sus și scriere
    // încape o a doua cerere. Cea care pierde nu mută nimic și află de ce. Iar
    // același Telegram scris pe două conturi îl oprește cheia `uq_users_telegram`
    // din migrarea 021 — prinsă aici, ca să iasă o vorbă, nu un 500. Numai 1062
    // (rând duplicat), ca la `q_unique()`: restul e o defecțiune adevărată.
    try {
        $scris = q(
            'UPDATE users SET telegram_user_id = ? WHERE id = ? AND telegram_user_id IS NULL',
            [$tgId, $contId]
        )->rowCount();
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
            throw $e;
        }
        return ['pricina' => 'luat'] + $r;
    }
    if ($scris !== 1) {
        return ['pricina' => 'cont_legat', 'altul' => (string) $cont['name']] + $r;
    }

    // O cerere de legare deschisă de contul ăsta (pasul „eu sunt”) se stinge:
    // altfel un „da, eu am apăsat” de mai târziu ar scrie alt Telegram peste
    // legătura făcută acum, fără ca fondatorul să afle. DUPĂ scriere, nu
    // înaintea ei: o legare refuzată nu are voie să lase nimic schimbat, nici
    // măcar cererea omului care tocmai își lega singur contul.
    q('DELETE FROM telegram_links WHERE user_id = ?', [$contId]);

    return ['ok' => true] + $r;
}

/**
 * Desface legătura unui cont, din lista fondatorului.
 *
 * Întoarce numele contului și dacă era ceva de desfăcut: contul fără Telegram
 * nu e o greșeală (a doua apăsare pe „Dezleagă”, din altă filă), dar nici nu
 * se scrie în Jurnal o dezlegare care n-a avut loc.
 *
 * `om` e omul din listă de care era legat contul, pentru Jurnal — ori șirul
 * gol: un cont legat de mult, prin „eu sunt”, poate să nu aibă rând în listă
 * (omul n-a mai apăsat nimic de la migrarea 036 încoace).
 *
 * @return array{gasit:bool, eraLegat:bool, cont:string, om:string}
 */
function telegram_cont_dezleaga(int $contId): array
{
    $cont = one('SELECT id, name, telegram_user_id FROM users WHERE id = ?', [$contId]);
    if (!$cont) {
        return ['gasit' => false, 'eraLegat' => false, 'cont' => '', 'om' => ''];
    }
    $eraLegat = $cont['telegram_user_id'] !== null;
    $om       = '';
    if ($eraLegat) {
        $rand = one('SELECT name, username FROM telegram_oameni WHERE tg_id = ?', [(int) $cont['telegram_user_id']]);
        $om   = $rand ? telegram_om_eticheta($rand) : '';
        telegram_dezleaga($contId);
    }
    return ['gasit' => true, 'eraLegat' => $eraLegat, 'cont' => (string) $cont['name'], 'om' => $om];
}
