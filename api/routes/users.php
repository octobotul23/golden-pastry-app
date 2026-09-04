<?php
/** Conturi și parole. Doar `admin`. */

declare(strict_types=1);

const ROLES = ['admin', 'angajat', 'client'];

/**
 * Verifică adresa unui cont înainte să fie scrisă.
 *
 * Formatul: singurul lucru pentru care aplicația folosește câmpul e „am uitat
 * parola". O adresă strâmbă nu se plânge la salvare, dar face ca linkul să nu
 * plece niciodată — send_email() aruncă, excepția e doar jurnalizată, iar omul
 * primește același răspuns ca oricine. Adică o greșeală de tastare descoperită
 * peste o lună, de cineva care nu mai poate intra în cont.
 *
 * Unicitatea: nu e curățenie, e securitate. Cu aceeași adresă pe două conturi,
 * /auth/forgot nu mai poate spune care dintre ele a cerut parola nouă (vezi
 * api/routes/auth.php), deci ori schimbă parola omului nepotrivit, ori — de
 * când e reparat acolo — nu mai poate trimite nimic niciunuia. Ambele rele se
 * opresc aici, la scriere: e singurul loc unde se mai știe cine a cerut ce.
 *
 * `$exceptId` e contul care se editează, ca adresa lui să nu se certe cu ea
 * însăși.
 */
function check_user_email(string $email, ?int $exceptId = null): void
{
    // Contul fără adresă e o stare normală: clienții luați la telefon n-au una.
    if ($email === '') {
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Adresa de e-mail nu este validă.', 422, 'bad_email');
    }
    // Coloana e utf8mb4_unicode_ci, deci comparația nu se uită la majuscule:
    // „Comenzi@Firma.ro" și „comenzi@firma.ro" duc la aceeași cutie poștală și
    // trebuie să se ciocnească.
    $alt = one('SELECT username FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, $exceptId ?? 0]);
    if ($alt) {
        fail('Adresa „' . $email . '” este deja trecută pe contul „' . $alt['username'] . '”. '
            . 'Recuperarea parolei se face după adresă, deci două conturi nu pot avea aceeași adresă. '
            . 'Șterge-o de pe celălalt cont sau folosește alta.', 409, 'email_taken');
    }
}

function route_users_list(): void
{
    require_role('admin');
    $rows = all(
        'SELECT id, username, name, email, phone, address, role, customer_name, active,
                must_change_password, password_changed_at, last_login, created_at, is_system
           FROM users ORDER BY role, name'
    );
    // public_user() nu include niciodată password_hash.
    json_out(['users' => array_map(static function ($r) {
        $u                = public_user($r);
        $u['phone']       = $r['phone'];
        $u['active']      = (bool) $r['active'];
        $u['lastLogin']   = $r['last_login'];
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
    if (!in_array($role, ROLES, true)) {
        fail('Rol necunoscut. Valori permise: ' . implode(', ', ROLES), 422, 'bad_role');
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
        if (!in_array($b['role'], ROLES, true)) {
            fail('Rol necunoscut.', 422, 'bad_role');
        }
        // Fără asta, un admin își poate retrograda propriul cont și rămâne
        // instalarea fără niciun administrator.
        if ($id === (int) $me['id'] && $b['role'] !== 'admin') {
            fail('Nu îți poți schimba propriul rol de administrator.', 422, 'self_demote');
        }
        $sets[] = 'role = ?';
        $vals[] = $b['role'];
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
