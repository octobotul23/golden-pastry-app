<?php
/** Rute de autentificare. */

declare(strict_types=1);

function route_login(): void
{
    $b        = body();
    $username = need($b, 'username');
    $password = need($b, 'password');

    [, $csrf, $u] = do_login($username, $password);

    // Curățenie ocazională, ca sesiunile expirate să nu se adune la infinit.
    if (random_int(1, 20) === 1) {
        purge_expired();
    }

    $full = one(
        'SELECT id, username, name, email, phone, address, role, customer_name, must_change_password
           FROM users WHERE id = ?',
        [$u['id']]
    );

    json_out([
        'user'        => public_user($full),
        'csrf'        => $csrf,
        'sessionDays' => session_days_for((string) $full['role']),
    ]);
}

function route_logout(): void
{
    $u = current_user();
    if ($u) {
        audit('logout', 'user', (string) $u['id']);
    }
    do_logout();
    json_out(['ok' => true]);
}

/** Cine sunt? Folosit de fiecare pagină la încărcare, ca să știe dacă are sesiune. */
function route_me(): void
{
    $u = current_user();
    if (!$u) {
        json_out(['user' => null], 200);
    }
    // Cât ține sesiunea nu e un secret, iar pagina de intrare are nevoie de
    // el ca să spună limpede de ce nu mai cere parola. Fără asta ar trebui
    // scris un număr în pagină, care s-ar depărta tăcut de configurație.
    json_out([
        'user'        => public_user($u),
        'csrf'        => $u['csrf_token'],
        'sessionDays' => session_days_for((string) $u['role']),
    ]);
}

/**
 * POST /auth/panel — deschiderea panoului cu codul comun al tabletelor.
 *
 * Tabletele din laborator sunt folosite de mai mulți oameni, deci nu are sens
 * un cont individual. Codul se verifică AICI, pe server — în varianta veche
 * stătea în localStorage și se compara în browser, deci oricine îl putea citi
 * din sursa paginii.
 *
 * Sesiunea rezultată rulează pe contul de sistem „panou", cu rol de angajat:
 * vede și avansează comenzi, dar nu ajunge la conturi, setări sau jurnal.
 */
function route_panel_login(): void
{
    $ip = client_ip();
    // Prag propriu ȘI pe conexiune: contul „panou" e comun tuturor tabletelor,
    // deci pragul conturilor individuale ar fi închis panoul pentru toată
    // echipa. Numărate global, cum le număra ramura „pe utilizator", cele
    // douăzeci de greșeli se puteau consuma de oriunde de pe internet — ruta
    // asta n-are parolă — și bucătarul cu codul bun primea „Prea multe
    // încercări", cu aluatul pe masă.
    if (panel_rate_limited($ip, (int) config('max_panel_attempts', 20))) {
        fail('Prea multe încercări. Încearcă din nou peste câteva minute.', 429, 'rate_limited');
    }

    if (!setting_bool('panel_enabled')) {
        fail('Accesul cu cod este dezactivat din Setări.', 403, 'panel_disabled');
    }

    $expected = setting('panel_code');
    if ($expected === '') {
        // Fără cod configurat poarta se închide, nu se deschide.
        record_attempt($ip, 'panou', false);
        fail('Codul panoului nu este configurat. Setează-l din pagina de Setări.', 503, 'panel_not_configured');
    }

    $given = trim((string) (body()['code'] ?? ''));
    if ($given === '' || !hash_equals($expected, $given)) {
        record_attempt($ip, 'panou', false);
        fail('Cod greșit.', 401, 'bad_code');
    }

    $u = one("SELECT * FROM users WHERE username = 'panou' AND is_system = 1");
    if (!$u || (int) $u['active'] !== 1) {
        fail('Contul de panou lipsește sau este dezactivat.', 503, 'panel_account_missing');
    }

    record_attempt($ip, 'panou', true);

    $token = random_token();
    $csrf  = random_token();
    q(
        'INSERT INTO sessions (id, user_id, csrf_token, expires_at, ip, user_agent)
         VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY, ?, ?)',
        [
            token_hash($token), $u['id'], $csrf, session_days_for('angajat'),
            $ip, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]
    );
    set_session_cookie($token, session_days_for('angajat'));

    audit('panel_unlock', 'user', (string) $u['id'], 'Panou deschis cu cod', (int) $u['id'], 'Panou comun');

    json_out(['user' => public_user($u), 'csrf' => $csrf]);
}

/* ------------------------------------------------- parola uitată -- */

/**
 * Unde trimite linkul din e-mail.
 *
 * Implicit se deduce din originea cererii: formularul stă pe pagina de intrare,
 * deci pagina care cere resetarea este chiar cea care trebuie deschisă cu
 * tokenul. Originea se acceptă numai dacă e în lista albă — altfel oricine ar
 * putea determina aplicația să trimită, de pe adresa ta, un e-mail cu un link
 * către site-ul lui.
 */
function reset_link(string $token): string
{
    $fix = trim((string) config('reset_url', ''));
    if ($fix !== '') {
        return $fix . (str_contains($fix, '?') ? '&' : '?') . 'parola=' . $token;
    }

    $allowed = (array) config('allowed_origins', []);
    $origin  = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || !in_array($origin, $allowed, true)) {
        $origin = (string) ($allowed[0] ?? '');
    }
    return rtrim($origin, '/') . '/?parola=' . $token;
}

/**
 * POST /auth/forgot { username }
 *
 * Trimite pe e-mailul contului un link valabil o oră și o singură dată.
 *
 * Răspunsul este identic indiferent dacă acel cont există, dacă e activ sau
 * dacă are e-mail. Un răspuns diferit ar transforma formularul într-o listă de
 * utilizatori reali, pe care apoi s-ar încerca parole.
 */
function route_forgot(): void
{
    $ip  = client_ip();
    $who = trim((string) (body()['username'] ?? ''));

    // Fiecare cerere trimite un e-mail către o adresă pe care nu o alege cel
    // care cere. Fără prag, formularul ar fi și o unealtă de hărțuire.
    //
    // Pragul e pe conexiune, cum spune și mesajul. Înainte se folosea ramura
    // „pe utilizator" a limitării de la autentificare, care nu se uită la IP:
    // zece cereri, de oriunde, opreau recuperarea parolei pentru toată lumea.
    if (forgot_rate_limited($ip, (int) config('max_forgot_attempts', 10))) {
        fail('Prea multe cereri de pe această conexiune. Încearcă peste câteva minute.', 429, 'rate_limited');
    }

    $raspuns = [
        'ok'      => true,
        'message' => 'Dacă există un cont cu datele astea, am trimis un e-mail cu instrucțiuni. '
            . 'Dacă nu primești nimic în câteva minute, sună-ne: se poate ca pe cont să nu fie trecută o adresă.',
    ];

    if ($who === '') {
        json_out($raspuns);
    }

    // Abia acum se consumă din prag: o cerere cu câmpul gol nu trimite niciun
    // e-mail și nu scrie nimic, deci n-are de ce să mănânce din plafonul
    // omului care chiar și-a uitat parola.
    record_attempt($ip, GP_FORGOT_KEY, false);

    // După utilizator sau după e-mail: omul nu ține minte întotdeauna cu care
    // dintre ele s-a înscris. Contul de sistem al tabletelor nu are parolă de
    // recuperat — el se deschide cu codul din Setări.
    //
    // Întâi după utilizator, fiindcă e singurul câmp cu index unic: o
    // potrivire acolo arată contul fără dubiu, oricâte adrese s-ar repeta.
    $u = one(
        'SELECT id, name, email, role FROM users
          WHERE username = ? AND active = 1 AND is_system = 0',
        [strtolower($who)]
    );

    if (!$u) {
        // Abia apoi după adresă — și numai dacă ea duce la un singur cont.
        //
        // Varianta veche punea cele două condiții într-un OR cu `LIMIT 1` și
        // fără ORDER BY: cu aceeași adresă pe două conturi alegea unul dintre
        // ele la voia bazei, adică mereu pe primul creat. Omul de pe al doilea
        // cerea parolă nouă, primea în cutia comună un link scris pe numele
        // colegului și, folosindu-l, îi schimba parola și îi ștergea toate
        // sesiunile — colegului, care nu ceruse nimic. Al lui rămânea de
        // neatins oricâte cereri ar fi trimis.
        //
        // Scrierile duplicate sunt oprite acum în api/routes/users.php, dar
        // rândurile de dinainte de acea regulă există în continuare, deci
        // ambiguitatea trebuie tratată și aici. Nu se poate rezolva ghicind:
        // când adresa arată spre mai multe conturi nu plecăm cu niciun link,
        // iar omul are calea sigură la îndemână — numele lui de utilizator,
        // pe care formularul îl cere oricum („Utilizator sau e-mail").
        $candidati = all(
            'SELECT id, name, email, role FROM users
              WHERE email = ? AND active = 1 AND is_system = 0
              LIMIT 2',
            [$who]
        );
        if (count($candidati) === 1) {
            $u = $candidati[0];
        } elseif (count($candidati) > 1) {
            // Doar id-urile, luate din bază: adresa vine din corpul cererii și
            // n-are ce căuta netrecută prin nimic în jurnalul serverului.
            error_log('[gp] „am uitat parola": adresa cerută e trecută pe mai multe conturi ('
                . implode(', ', array_column($candidati, 'id')) . '); nu trimit niciun link.');
        }
    }

    if ($u && trim((string) $u['email']) !== '') {
        // Cererile nefolosite de dinainte se închid: rămâne valabil un singur
        // link, cel din urmă e-mail primit.
        q('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$u['id']]);

        $token = random_token();
        q(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? MINUTE)',
            [$u['id'], token_hash($token), (int) config('reset_minutes', 60)]
        );

        $minute = (int) config('reset_minutes', 60);
        $text = implode("\n", [
            'Bună, ' . $u['name'] . ',',
            '',
            'Cineva a cerut o parolă nouă pentru contul tău Golden Pastry.',
            'Deschide adresa de mai jos și scrie parola pe care o vrei:',
            '',
            reset_link($token),
            '',
            'Linkul este valabil ' . $minute . ' de minute și poate fi folosit o singură dată.',
            'Dacă nu ai cerut tu asta, nu trebuie să faci nimic: parola rămâne cea veche.',
            '',
            'Golden Pastry',
        ]);

        // Un server de mail căzut nu trebuie să dea altă formă răspunsului:
        // asta ar spune, indirect, că acel cont există.
        try {
            send_email($u['email'], 'Parolă nouă pentru contul tău Golden Pastry', $text);
        } catch (Throwable $e) {
            error_log('[gp] e-mail de recuperare eșuat pentru contul ' . $u['id'] . ': ' . $e->getMessage());
        }

        audit('password_forgot', 'user', (string) $u['id'], 'Cerere de parolă nouă', (int) $u['id'], (string) $u['name']);
    }

    json_out($raspuns);
}

/**
 * POST /auth/reset { token, password }
 *
 * Închide toate sesiunile contului: dacă ai cerut parolă nouă fiindcă bănuiești
 * că o mai știe cineva, sesiunile lui trebuie să dispară odată cu ea.
 */
function route_reset(): void
{
    $b     = body();
    $token = trim((string) ($b['token'] ?? ''));
    // Tăiată de spații, ca peste tot altundeva: autentificarea compară parola
    // trecută prin need(), care taie. O parolă pusă aici cu un spațiu lipit la
    // capăt — de la copiat, sau de la tastatura telefonului, care adaugă unul
    // după cuvânt — dădea un hash pe care intrarea în cont nu-l mai putea
    // potrivi NICIODATĂ. Omul rămânea pe dinafară cu parola pe care tocmai o
    // scrisese, fără niciun mesaj care să-i spună de ce.
    $noua  = trim((string) ($b['password'] ?? ''));

    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        fail('Linkul nu este valid. Cere altul din pagina de intrare.', 422, 'bad_token');
    }
    if (strlen($noua) < 8) {
        fail('Parola nouă trebuie să aibă cel puțin 8 caractere.', 422, 'weak_password');
    }

    $r = one(
        'SELECT id, user_id FROM password_resets
          WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
        [token_hash($token)]
    );
    if (!$r) {
        fail('Linkul a expirat sau a fost deja folosit. Cere altul din pagina de intrare.', 410, 'expired_token');
    }

    $u = one('SELECT id, username, name, role FROM users WHERE id = ? AND active = 1', [$r['user_id']]);
    if (!$u) {
        fail('Contul nu mai există sau a fost dezactivat.', 410, 'no_account');
    }

    // Contul blocat de prea multe încercări se deblochează: cine ajunge aici a
    // dovedit că are acces la e-mailul contului.
    q(
        'UPDATE users SET password_hash = ?, must_change_password = 0,
                password_changed_at = UTC_TIMESTAMP(),
                failed_logins = 0, locked_until = NULL
          WHERE id = ?',
        [password_hash($noua, PASSWORD_DEFAULT), $u['id']]
    );
    // Nu doar linkul folosit: toate cererile nefolosite ale contului se închid.
    // Altfel un link mai vechi, primit pe un e-mail la care poate ajunge
    // altcineva, rămânea bun încă o oră DUPĂ ce parola fusese deja schimbată.
    q('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$u['id']]);
    q('DELETE FROM sessions WHERE user_id = ?', [$u['id']]);
    // Blocarea se calculează din încercările eșuate, deci ele trebuie să plece
    // odată cu parola veche — altfel contul rămâne blocat cu parola cea nouă.
    clear_attempts((string) $u['username']);

    audit('password_reset', 'user', (string) $u['id'], 'Parolă schimbată prin link', (int) $u['id'], (string) $u['name']);
    json_out(['ok' => true]);
}

/** Schimbarea propriei parole. */
function route_change_password(): void
{
    $u  = require_auth();
    $b  = body();
    $old = need($b, 'currentPassword');
    $new = need($b, 'newPassword');

    if (strlen($new) < 8) {
        fail('Parola nouă trebuie să aibă cel puțin 8 caractere.', 422, 'weak_password');
    }

    $row = one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
    if (!$row || !password_verify($old, $row['password_hash'])) {
        fail('Parola curentă este greșită.', 403, 'bad_password');
    }

    q(
        // Aici se scrie singura dată pe care Conturi o poate afirma cinstit:
        // momentul în care omul și-a ales el parola. Până acum pagina afișa
        // `last_login` sub eticheta „parolă schimbată", deci o simplă
        // autentificare cu parola primită de la administrator trecea drept
        // schimbare de parolă.
        'UPDATE users SET password_hash = ?, must_change_password = 0,
                password_changed_at = UTC_TIMESTAMP() WHERE id = ?',
        [password_hash($new, PASSWORD_DEFAULT), $u['id']]
    );

    // Celelalte sesiuni ale contului se închid: dacă schimbi parola fiindcă
    // bănuiești că altcineva o știe, sesiunile lui trebuie să dispară.
    $current = $_COOKIE[GP_COOKIE] ?? '';
    q('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [$u['id'], token_hash($current)]);

    // Și linkurile de „am uitat parola" cerute până acum. Sesiunile plecau, dar
    // un token nefolosit rămânea bun până la expirare, doar pe baza lui
    // `used_at IS NULL`, fără nicio legătură cu parola curentă: cine avea
    // e-mailul putea pune, o oră mai târziu, altă parolă peste cea nouă —
    // adică exact lucrul de care fugea schimbarea.
    q('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL', [$u['id']]);

    audit('password_change', 'user', (string) $u['id']);
    json_out(['ok' => true]);
}
