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
 * fiecare apăsare ia calea de azi (actorul global) și totul se poartă exact ca
 * în 2.2.0. Jumătatea urcată nu strică nimic — v. „La publicare" din plan.
 *
 * Răspunsul se ține minte pe durata cererii: e o singură interogare, dar ar fi
 * chemată din mai multe locuri pe același request.
 */
function telegram_legaturi_suportate(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
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
        return $ok = ($coloana === 1 && $tabel === 1);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica suportul de legături Telegram: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Contul legat de un id de Telegram, sau `null`.
 *
 * Întoarce rândul întreg, cu `active` și `role` cu tot, ca cel care întreabă să
 * poată hotărî singur ce face cu un cont închis sau scos din administratori —
 * v. `telegram_actor()`, unde deosebirea dintre „nelegat" și „legat, dar fără
 * drept" e chiar miezul.
 */
function telegram_contul_dupa_id(int $tgId): ?array
{
    if ($tgId <= 0 || !telegram_legaturi_suportate()) {
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

    // Numele se păstrează cum a venit, la clipa apăsării: ecranul de
    // încuviințare trebuie să arate pe cine confirmi. Un număr gol n-ar spune
    // nimănui nimic.
    q(
        'UPDATE telegram_links
            SET pressed_tg_id = ?, pressed_name = ?, pressed_at = UTC_TIMESTAMP()
          WHERE id = ?',
        [$tgId, mb_substr($numeTg, 0, 128), (int) $r['id']]
    );
    return ['stare' => 'legat_propus', 'userId' => (int) $r['user_id']];
}

/**
 * Încuviințarea: aici, și numai aici, se scrie `users.telegram_user_id`.
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

    q('UPDATE users SET telegram_user_id = ? WHERE id = ?', [$tgId, $userId]);
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
