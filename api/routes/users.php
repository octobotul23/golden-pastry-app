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
 * require_role('admin').
 */
const ROLES = ['admin', 'angajat', 'client', 'manager', 'agent_vanzari'];

/*
 * check_user_email() stătea aici. S-a mutat în api/lib/auth.php, fără nicio
 * schimbare de nume, de semnătură sau de comportament: rutele din routes/ nu se
 * încarcă una pe alta (index.php cere doar fișierul rutei potrivite), iar de
 * regula asta are nevoie acum și înscrierea de pe site, din routes/auth.php.
 * O a doua copie a unei reguli de unicitate se depărtează de prima exact când
 * contează. Apelurile de mai jos merg neschimbate — lib/ se încarcă mereu.
 */

function route_users_list(): void
{
    require_role('admin');

    // Coloanele de recomandare se cer numai dacă migrarea 007 a fost rulată.
    // Cerute necondiționat, o instalare pe care fișierele au fost urcate
    // înaintea importului din phpMyAdmin ar primi 1054 „Unknown column" — adică
    // pagina Conturi ar rămâne goală până la import. Așa, ea merge exact ca
    // înainte, doar fără coloana de recomandări.
    $areReferral = referral_supported();
    $extra = $areReferral
        ? ', u.referral_code, u.referred_by, u.referred_at, r.name AS referred_by_name'
        : '';
    $join = $areReferral ? ' LEFT JOIN users r ON r.id = u.referred_by' : '';

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
    json_out(['referral' => $areReferral, 'users' => array_map(static function ($r) {
        $u                = public_user($r);
        $u['phone']       = $r['phone'];
        $u['active']      = (bool) $r['active'];
        $u['lastLogin']   = $r['last_login'];
        // Recomandări: codul propriu, linkul gata de trimis și cine l-a adus.
        // `referralCode` vine deja din public_user(); aici se adaugă restul.
        $u['referralLink']   = referral_link($r['referral_code'] ?? null);
        $u['referredByName'] = $r['referred_by_name'] ?? null;
        $u['referredAt']     = $r['referred_at'] ?? null;
        // Momentul în care omul și-a schimbat SINGUR parola — NULL cât timp pe
        // cont e cea pusă de administrator. Pagina arăta aici `last_login` și
        // scria „parolă schimbată" despre un cont care doar se autentificase.
        $u['passwordChangedAt'] = $r['password_changed_at'];
        $u['createdAt']   = $r['created_at'];
        // Contul „panou" nu are parolă de om: se intră în el cu codul
        // tabletelor. Pagina trebuie să știe asta ca să nu ofere butoane care
        // oricum sunt refuzate pe server.
        $u['isSystem']    = (int) $r['is_system'] === 1;
        return $u;
    }, $rows)]);
}

function route_users_create(): void
{
    require_role('admin');
    $b = body();

    $username = strtolower(need($b, 'username'));
    $name     = need($b, 'name');
    $password = need($b, 'password');
    $role     = (string) ($b['role'] ?? 'client');

    if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
        fail('Utilizatorul poate conține doar litere mici, cifre, punct, minus și underscore (3–64).', 422, 'bad_username');
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
    if (strlen($password) < 8) {
        fail('Parola trebuie să aibă cel puțin 8 caractere.', 422, 'weak_password');
    }
    if (scalar('SELECT 1 FROM users WHERE username = ?', [$username])) {
        fail('Există deja un cont cu acest utilizator.', 409, 'username_taken');
    }

    $email = trim((string) ($b['email'] ?? ''));
    check_user_email($email);

    // Aceeași crăpătură ca la articolele din depozit: verificarea de mai sus e
    // din memorie, iar între ea și scriere încap două cereri paralele. Se
    // întâmplă când doi administratori fac contul aceluiași angajat pentru tura
    // nouă, în aceeași clipă.
    q_unique(
        'INSERT INTO users (username, password_hash, name, email, phone, role, customer_name, must_change_password)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
        [
            $username, password_hash($password, PASSWORD_DEFAULT), $name,
            $email ?: null, trim((string) ($b['phone'] ?? '')) ?: null,
            $role, trim((string) ($b['customerName'] ?? '')) ?: null,
        ],
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

    audit('user_create', 'user', (string) $id, $name . ' (' . $role . ')');
    json_out(['user' => public_user(one('SELECT * FROM users WHERE id = ?', [$id]))], 201);
}

function route_users_update(string $id): void
{
    $me = require_role('admin');
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

    foreach (['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'customerName' => 'customer_name'] as $in => $col) {
        if (array_key_exists($in, $b)) {
            $val = trim((string) $b[$in]);
            // `users.name` e NOT NULL, spre deosebire de celelalte trei. Golit,
            // ajungea ca NULL în UPDATE și ieșea cu „Eroare internă." — adică
            // ștergerea numelui ca să-l rescrii, lucru pe care îl face oricine
            // corectează o denumire, arăta ca o defecțiune a aplicației.
            if ($col === 'name' && $val === '') {
                fail('Numele nu poate rămâne gol. Scrie numele afișat al contului.', 422, 'empty_name');
            }
            // Adresa se verifică numai când chiar se schimbă. Pagina Conturi
            // salvează la ieșirea din casetă, deci trimite câmpul și când nu
            // l-a atins nimeni; verificat necondiționat, un duplicat rămas în
            // bază de dinainte de regula asta ar bloca orice altă corecție pe
            // rândul acela. Regula e „nu înrăutăți", nu „respinge trecutul".
            if ($col === 'email' && strtolower($val) !== strtolower((string) $target['email'])) {
                check_user_email($val, $id);
            }
            $sets[] = "$col = ?";
            $vals[] = $val ?: null;
        }
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
        // instalarea fără niciun administrator.
        if ($id === (int) $me['id'] && $b['role'] !== 'admin') {
            fail('Nu îți poți schimba propriul rol de administrator.', 422, 'self_demote');
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
        $sets[] = 'active = ?';
        $vals[] = $b['active'] ? 1 : 0;
        if (!$b['active']) {
            q('DELETE FROM sessions WHERE user_id = ?', [$id]);   // îl deconectăm imediat
        }
    }

    if (!$sets) {
        fail('Nimic de modificat.', 422, 'nothing_to_update');
    }

    $vals[] = $id;
    q('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    audit('user_update', 'user', (string) $id, $target['name']);

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
    $me = require_role('admin');
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
    if ($target['role'] === 'admin' && (int) scalar("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1") <= 1) {
        fail('Nu poți șterge ultimul administrator.', 422, 'last_admin');
    }

    q('DELETE FROM users WHERE id = ?', [$id]);
    audit('user_delete', 'user', (string) $id, $target['name']);
    json_out(['ok' => true]);
}

/** Resetarea parolei de către admin. */
function route_users_password(string $id): void
{
    require_role('admin');
    $b   = body();
    $id  = (int) $id;
    $new = need($b, 'password');

    if (strlen($new) < 8) {
        fail('Parola trebuie să aibă cel puțin 8 caractere.', 422, 'weak_password');
    }
    $target = one('SELECT username, name, is_system FROM users WHERE id = ?', [$id]);
    if (!$target) {
        fail('Contul nu există.', 404, 'not_found');
    }
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
    require_role('admin');
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

    $cod = referral_assign($id, (string) $target['name'], (string) $target['username']);
    if ($cod === null) {
        fail('Nu am putut construi un cod din numele „' . $target['name'] . '”. '
            . 'Scrie un nume care începe cu litere, apoi încearcă din nou.', 422, 'referral_failed');
    }

    audit('user_referral_code', 'user', (string) $id, $target['name'] . ' → ' . $cod);
    json_out(['referralCode' => $cod, 'referralLink' => referral_link($cod), 'created' => true]);
}
