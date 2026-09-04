<?php
/**
 * Autentificare, sesiuni, roluri, CSRF și jurnalizare.
 *
 * Principiul de bază: rolul se verifică ÎNTOTDEAUNA aici, pe server. Interfața
 * poate ascunde butoane, dar asta nu este o măsură de securitate — în
 * prototipul de la care am pornit, verificarea se făcea în browser și oricine
 * putea să o ocolească.
 */

declare(strict_types=1);

const GP_COOKIE = 'gp_session';

/* ------------------------------------------------------------- sesiune -- */

/** Utilizatorul curent, sau null dacă nu este autentificat. */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }

    $token = $_COOKIE[GP_COOKIE] ?? '';
    if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return $user = null;
    }

    $row = one(
        'SELECT s.id AS sid, s.csrf_token, s.expires_at,
                u.id, u.username, u.name, u.email, u.phone, u.address, u.role, u.customer_name,
                u.active, u.must_change_password, u.is_system
           FROM sessions s
           JOIN users u ON u.id = s.user_id
          WHERE s.id = ? AND s.expires_at > UTC_TIMESTAMP()',
        [token_hash($token)]
    );

    if (!$row || (int) $row['active'] !== 1) {
        return $user = null;
    }

    return $user = $row;
}

/**
 * Ruta cerută, normalizată ca în index.php.
 *
 * Nu se ia din routerul de acolo: `require_auth()` e chemată și din probe și
 * din scripturi, unde nu există niciun router. Se recalculează din cerere,
 * fără nicio variabilă globală. Când nu există cerere (linia de comandă),
 * iese '/' — adică o cale care NU e pe lista de mai jos, deci poarta se
 * închide, nu se deschide.
 */
function request_path(): string
{
    $p = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return '/' . trim((string) $p, '/');
}

/**
 * Contul umblă încă pe parola pusă de altcineva?
 *
 * `must_change_password` era pus de administrator la crearea contului și la
 * resetarea parolei, dar nu-l consulta nicio rută: singura piedică era o
 * redirecționare făcută în browser, pe pagina de intrare, iar pagina spre care
 * ducea avea pe ea linkul „← Înapoi la administrare". Un clic și omul lucra
 * mai departe cu parola dictată la telefon — cea pe care o mai știe cel puțin
 * o persoană. Steagul rămânea 1 la nesfârșit.
 *
 * De aici încolo serverul e cel care oprește, nu interfața. Rămâne deschisă o
 * singură rută, schimbarea parolei: fără ea contul ar fi închis definitiv,
 * fiindcă tocmai acolo trebuie să ajungă. Ieșirea din cont (/auth/logout) și
 * /auth/me nu trec pe aici — folosesc current_user() — deci omul se poate
 * oricând deconecta.
 *
 * Comparația se face pe coadă, nu pe egalitate: API-ul poate sta și într-un
 * subfolder (goldenpastry.ro/api/...), iar atunci calea are un prefix.
 *
 * Conturile de sistem sunt lăsate în afară — vezi mai jos de ce.
 */
function password_change_route(string $path): bool
{
    return $path === '/auth/password' || str_ends_with($path, '/auth/password');
}

function require_auth(): array
{
    $u = current_user();
    if (!$u) {
        fail('Trebuie să fii autentificat.', 401, 'unauthenticated');
    }
    // Contul „panou" al tabletelor nu are parolă proprie: se deschide cu codul
    // comun, iar hash-ul lui e o valoare aleatoare pe care n-o știe nimeni.
    // Dacă steagul ajunge cumva 1 pe el, nu s-ar mai putea scoate niciodată —
    // /auth/password cere parola curentă — și tot laboratorul ar rămâne fără
    // panouri. Conturile de sistem nu intră deci sub regula asta.
    $sistem = (int) ($u['is_system'] ?? 0) === 1;
    if (!$sistem && (int) ($u['must_change_password'] ?? 0) === 1 && !password_change_route(request_path())) {
        fail(
            'Trebuie să îți schimbi parola provizorie înainte de a folosi aplicația.',
            403,
            'must_change_password'
        );
    }
    return $u;
}

/** Cere unul dintre rolurile date, altfel 403. */
function require_role(string ...$roles): array
{
    $u = require_auth();
    if (!in_array($u['role'], $roles, true)) {
        fail('Nu ai dreptul să accesezi această resursă.', 403, 'forbidden');
    }
    return $u;
}

/** Scurtătură pentru personalul intern. */
function require_staff(): array
{
    return require_role('admin', 'angajat');
}

/* ---------------------------------------------------------------- CSRF -- */

/**
 * Verifică tokenul CSRF pentru orice operație care modifică date.
 * Cookie-ul de sesiune este trimis automat de browser; tokenul acesta nu —
 * el trebuie citit din răspunsul de login și pus în antetul X-CSRF-Token,
 * ceea ce un site străin nu poate face.
 */
/**
 * Rutele care intră sau ies din sesiune.
 *
 * Ele NU pot cere token CSRF. Tokenul se obține din răspunsul autentificării
 * și se ține în sessionStorage, care dispare la închiderea filei — în timp ce
 * cookie-ul de sesiune ține 14 zile. Rezultă o combinație perfect obișnuită
 * (cookie valid, token pierdut) în care utilizatorul era considerat
 * autentificat, deci i se cerea tokenul, dar nu îl mai avea și nici nu îl mai
 * putea obține: nu se mai putea nici autentifica, nici deconecta. Pe un
 * calculator din laborator, folosit pe rând de mai mulți oameni, asta
 * însemna blocare completă.
 *
 * Ce pierdem: protecția împotriva „login CSRF" — cineva te poate forța să
 * intri în contul LUI. Cere ca atacatorul să aibă deja un cont valid aici,
 * iar paguba e că lucrezi în sesiunea lui, nu că ajunge el în a ta. Față de
 * blocarea reală de mai sus, schimbul merită.
 */
function csrf_exempt(string $path): bool
{
    return in_array($path, ['/auth/login', '/auth/logout', '/auth/panel'], true);
}

function check_csrf(string $path = ''): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if ($path !== '' && csrf_exempt($path)) {
        return;
    }
    $u = current_user();
    if (!$u) {
        return;   // cererile neautentificate sunt oprite oricum de require_auth
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($sent === '' || !hash_equals((string) $u['csrf_token'], $sent)) {
        fail('Token CSRF lipsă sau invalid.', 403, 'bad_csrf');
    }
}

/* ------------------------------------------------------- autentificare -- */

/**
 * Prea multe încercări eșuate?
 *
 * Se numără pe DOUĂ niveluri, deliberat:
 *
 *  - pe utilizator: cineva care ghicește parola unui cont anume este oprit
 *    repede, indiferent de unde încearcă;
 *  - pe IP: prag mult mai larg, ca să prindă un atac automat.
 *
 * Motivul separării: într-o patiserie toate tabletele și calculatoarele ies
 * la internet prin aceeași adresă IP. Cu o singură limită pe IP, un angajat
 * care își greșește parola de câteva ori ar bloca tot personalul.
 */
function login_rate_limited(string $ip, string $username = '', ?int $maxPerUser = null): bool
{
    $window = (int) config('login_window_min');

    if ($username !== '') {
        $perUser = (int) scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE username = ? AND ok = 0 AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
            [$username, $window]
        );
        if ($perUser >= ($maxPerUser ?? (int) config('max_login_attempts'))) {
            return true;
        }
    }

    $perIp = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND ok = 0 AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$ip, $window]
    );
    return $perIp >= (int) config('max_login_attempts_ip');
}

/** Pseudo-utilizatorul sub care se numără cererile de parolă nouă. */
const GP_FORGOT_KEY = 'recuperare';

/**
 * Prea multe cereri de parolă nouă de pe ACEEAȘI conexiune?
 *
 * Cererile de recuperare se înregistrau toate sub același pseudo-utilizator,
 * iar pragul se citea din ramura „pe utilizator" a limitării de mai sus — care
 * nu se uită deloc la IP. Zece cereri, de oriunde din lume, opreau recuperarea
 * parolei pentru toți clienții și toți angajații, un sfert de oră la rând.
 * Mesajul afișat spunea totuși „de pe această conexiune".
 *
 * Aici se numără pe IP ȘI pe pseudo-utilizator, deci pragul e chiar cel promis
 * de mesaj, iar rândurile ies din socoteală singure, la capătul ferestrei.
 */
function forgot_rate_limited(string $ip, int $max): bool
{
    $window = (int) config('login_window_min');
    $n = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND username = ? AND ok = 0
            AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$ip, GP_FORGOT_KEY, $window]
    );
    return $n >= $max;
}

/** Pseudo-utilizatorul sub care se numără deschiderile panoului cu cod. */
const GP_PANEL_KEY = 'panou';

/**
 * Prea multe coduri greșite de panou de pe ACEEAȘI conexiune?
 *
 * Codul tabletelor e unul singur, deci toate greșelile se strâng sub același
 * pseudo-utilizator. Numărate global — cum le numără ramura „pe utilizator" a
 * limitării de mai sus — douăzeci de cereri trimise de oriunde de pe internet
 * închideau deschiderea panourilor pentru TOATĂ echipa: exact paguba pe care
 * pragul separat din configurație voia s-o evite („cu aluatul pe masă"), doar
 * că pornită din afară, nu de colegi. Și /auth/panel este o rută publică, fără
 * parolă și fără token CSRF, deci „oricine" înseamnă chiar oricine.
 *
 * Aici se numără pe IP ȘI pe pseudo-utilizator: cel care ghicește codul își
 * blochează doar propria conexiune, iar tableta din laborator deschide mai
 * departe cu codul bun.
 */
function panel_rate_limited(string $ip, int $max): bool
{
    $window = (int) config('login_window_min');
    $n = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND username = ? AND ok = 0
            AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$ip, GP_PANEL_KEY, $window]
    );
    return $n >= $max;
}

function record_attempt(string $ip, string $username, bool $ok): void
{
    // Tăiat la lungimea coloanei (VARCHAR(64)). Orice text lipit în câmpul
    // „Utilizator" ajunge până aici: o linie copiată dintr-un mesaj, un token,
    // o completare automată greșită. Mai lung de 64 de caractere, INSERT-ul
    // pica cu eroarea 1406, cererea se încheia cu „Eroare internă." în loc de
    // „Utilizator sau parolă greșită.", iar încercarea nici nu se mai număra.
    // Un cont nu poate avea oricum un nume mai lung, deci tăierea nu confundă
    // niciodată două conturi reale.
    $username = mb_substr($username, 0, 64);
    q('INSERT INTO login_attempts (ip, username, ok) VALUES (?, ?, ?)', [$ip, $username, $ok ? 1 : 0]);

    // O intrare reușită șterge greșelile de dinainte ale aceluiași cont.
    //
    // Fără asta, limitarea numără greșelile dintr-o fereastră de timp,
    // indiferent de ce s-a întâmplat între ele: într-o dimineață aglomerată,
    // greșelile răzlețe ale mai multor oameni se adunau până închideau contul,
    // deși de fiecare dată cineva intrase corect imediat după. Contează cât
    // s-a greșit LA RÂND, nu cât s-a greșit în total.
    //
    // Nu slăbește apărarea: cine nu știe parola nu poate ajunge niciodată aici
    // cu $ok = true, deci nu-și poate șterge propriile încercări.
    if ($ok && $username !== '') {
        // Contul „panou" e comun tuturor tabletelor și se numără pe conexiune
        // (vezi panel_rate_limited), deci și ștergerea rămâne pe conexiune.
        // Altfel prima tabletă care deschide dimineața ar șterge și greșelile
        // celui care ghicește codul de la distanță, adică i-ar da drumul de la
        // capăt tocmai când echipa își vede de treabă.
        if ($username === GP_PANEL_KEY) {
            q('DELETE FROM login_attempts WHERE username = ? AND ip = ? AND ok = 0', [$username, $ip]);
        } else {
            q('DELETE FROM login_attempts WHERE username = ? AND ok = 0', [$username]);
        }
    }
}

/**
 * Șterge greșelile numărate pentru un cont.
 *
 * Blocarea se calculează din `login_attempts`, nu dintr-o coloană, deci „i-am
 * dat parolă nouă, contul e liber" trebuie să însemne și că numărătoarea o ia
 * de la capăt. Altfel contul ar rămâne blocat chiar cu parola cea bună, iar
 * butonul din Conturi ar părea că nu face nimic.
 */
function clear_attempts(string $username): void
{
    if ($username !== '') {
        q('DELETE FROM login_attempts WHERE username = ? AND ok = 0', [$username]);
    }
}

/**
 * Verifică datele de autentificare și deschide o sesiune.
 * Întoarce [token, csrf, user] sau oprește cererea cu o eroare.
 */
function do_login(string $username, string $password): array
{
    $ip = client_ip();

    if (login_rate_limited($ip, $username)) {
        fail('Prea multe încercări. Încearcă din nou peste câteva minute.', 429, 'rate_limited');
    }

    $u = one(
        'SELECT id, username, password_hash, name, role, active, locked_until, failed_logins
           FROM users WHERE username = ?',
        [$username]
    );

    // Verificăm parola chiar și când contul nu există, ca durata răspunsului
    // să nu dezvăluie dacă utilizatorul este valid.
    $hash = $u['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
    $ok   = password_verify($password, $hash);

    if (!$u || !$ok || (int) $u['active'] !== 1) {
        record_attempt($ip, $username, false);
        if ($u) {
            // Se numără greșelile din fereastra de mai jos, nu din toată
            // istoria contului.
            //
            // `users.failed_logins` era un contor care creștea și nu scădea
            // niciodată de la sine: se punea pe zero doar la o autentificare
            // reușită sau la o resetare de parolă. Odată ajuns la prag, rămânea
            // acolo, deci condiția de blocare era adevărată la FIECARE greșeală
            // de după — o singură literă greșită, peste o lună, închidea contul
            // încă douăzeci de minute, și tot așa, la nesfârșit.
            //
            // Fereastra e mai lungă decât cea a limitării încercărilor (15
            // minute), altfel blocarea n-ar mai fi apucat să se declanșeze
            // niciodată: limitarea răspunde 429 la a opta greșeală dintr-un
            // sfert de oră, înainte de a zecea. Rostul blocării e tocmai să
            // prindă ghicirea răbdătoare, întinsă peste mai multe ferestre.
            //
            // Rândul curent e deja înregistrat de record_attempt() de mai sus,
            // deci numărătoarea îl include.
            $fails = (int) scalar(
                'SELECT COUNT(*) FROM login_attempts
                  WHERE username = ? AND ok = 0
                    AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
                [$username, (int) config('lock_window_min', 60)]
            );
            $lock  = $fails >= (int) config('lock_account_after')
                ? 'UTC_TIMESTAMP() + INTERVAL ' . (int) config('lock_minutes') . ' MINUTE'
                : 'locked_until';
            q("UPDATE users SET failed_logins = ?, locked_until = $lock WHERE id = ?", [$fails, $u['id']]);
        }
        // Mesaj identic în toate cazurile: nu spunem care parte a fost greșită.
        fail('Utilizator sau parolă greșită.', 401, 'bad_credentials');
    }

    if ($u['locked_until'] !== null && strtotime($u['locked_until'] . ' UTC') > time()) {
        record_attempt($ip, $username, false);
        fail('Contul este blocat temporar. Încearcă mai târziu.', 423, 'account_locked');
    }

    // Parola veche poate avea un cost mai mic decât cel curent al PHP-ului.
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }

    q('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
    record_attempt($ip, $username, true);

    // Sesiunea dinainte se închide. Pe un calculator din laborator, unde
    // oamenii se schimbă la tură, altfel ar fi rămas în baza de date o sesiune
    // valabilă a colegului dinainte, cu tot cu tokenul ei.
    $previous = $_COOKIE[GP_COOKIE] ?? '';
    if ($previous !== '') {
        q('DELETE FROM sessions WHERE id = ?', [token_hash($previous)]);
    }

    $token = random_token();
    $csrf  = random_token();
    q(
        'INSERT INTO sessions (id, user_id, csrf_token, expires_at, ip, user_agent)
         VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY, ?, ?)',
        [
            token_hash($token), $u['id'], $csrf, session_days_for((string) $u['role']),
            $ip, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]
    );

    set_session_cookie($token, session_days_for((string) $u['role']));
    audit('login', 'user', (string) $u['id'], null, (int) $u['id'], $u['name']);

    return [$token, $csrf, $u];
}

/**
 * Câte zile ține sesiunea unui rol.
 *
 * Era un singur număr pentru toată lumea. Dar telefonul clientului și
 * calculatorul din laborator nu sunt același lucru: pe primul, o sesiune lungă
 * scutește o parolă scrisă degeaba; pe al doilea, ea rămâne deschisă pentru
 * cine intră după tine în tură.
 */
function session_days_for(string $role): int
{
    $key  = $role === 'client' ? 'session_days_client' : 'session_days_staff';
    $days = (int) (config($key) ?? config('session_days', 14));
    return $days > 0 ? $days : 14;
}

function set_session_cookie(string $token, int $days = 0): void
{
    $days = $days > 0 ? $days : (int) config('session_days', 14);
    $opts = [
        'expires'  => time() + 86400 * $days,
        'path'     => '/',
        'httponly' => true,                       // inaccesibil din JavaScript
        'samesite' => 'Lax',
        'secure'   => (bool) config('cookie_secure'),
    ];
    $domain = (string) config('cookie_domain');
    if ($domain !== '') {
        $opts['domain'] = $domain;                // „.goldenpastry.ro” → toate subdomeniile
    }
    setcookie(GP_COOKIE, $token, $opts);
}

function do_logout(): void
{
    $token = $_COOKIE[GP_COOKIE] ?? '';
    if ($token !== '') {
        q('DELETE FROM sessions WHERE id = ?', [token_hash($token)]);
    }
    $opts = ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'];
    $domain = (string) config('cookie_domain');
    if ($domain !== '') {
        $opts['domain'] = $domain;
    }
    setcookie(GP_COOKIE, '', $opts);
}

/** Șterge sesiunile și încercările expirate. Apelat ocazional, la login. */
function purge_expired(): void
{
    // Cererile de parolă nouă expiră în minute, dar rândurile rămân. Le luăm
    // odată cu sesiunile, ca tabela să nu crească la nesfârșit.
    q('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY');
    q('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP()');
    q('DELETE FROM login_attempts WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
}

/* -------------------------------------------------------------- jurnal -- */

/**
 * Scrie o intrare în jurnal. `user_label` este păstrat ca text, ca jurnalul
 * să rămână lizibil și după ștergerea contului.
 */
function audit(
    string $action,
    ?string $entity = null,
    ?string $entityId = null,
    ?string $detail = null,
    ?int $userId = null,
    ?string $userLabel = null
): void {
    if ($userId === null) {
        $u         = current_user();
        $userId    = $u ? (int) $u['id'] : null;
        $userLabel = $u ? $u['name'] : null;
    }
    q(
        'INSERT INTO audit_log (user_id, user_label, action, entity, entity_id, detail, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$userId, $userLabel, $action, $entity, $entityId, $detail, client_ip()]
    );
}

/** Forma publică a unui utilizator — fără hash de parolă, niciodată. */
function public_user(array $u): array
{
    return [
        'id'                 => (int) $u['id'],
        'username'           => $u['username'],
        'name'               => $u['name'],
        'email'              => $u['email'] ?? null,
        'role'               => $u['role'],
        'phone'              => $u['phone'] ?? null,
        // Adresa proprie, ca site-ul să poată completa formularul de comandă
        // în locul clientului. Nu e un secret față de el însuși, iar
        // public_user() se folosește numai pentru propriul cont sau din
        // paginile de administrare.
        'address'            => $u['address'] ?? null,
        'customerName'       => $u['customer_name'] ?? null,
        'mustChangePassword' => (bool) ($u['must_change_password'] ?? false),
    ];
}
