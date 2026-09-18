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
        'SELECT id, username, name, email, phone, address, role, customer_name, must_change_password,
                is_system
           FROM users WHERE id = ?',
        [$u['id']]
    );

    // Zona firmei, lipită pe rând înainte de `public_user()`. Ca `referral` de
    // mai jos: citire după cheia primară, nu o coloană în plus în lista fixă de
    // deasupra — aceea ar cădea cu 1054 pe o instalare fără migrarea 016, adică
    // nimeni n-ar mai putea intra în cont.
    $full['zone'] = user_zone((int) $full['id']);

    json_out([
        'user'        => public_user($full),
        'csrf'        => $csrf,
        'sessionDays' => session_days_for((string) $full['role'], !empty($full['is_system'])),
        // Codul de recomandare și linkul gata de trimis. Se citește separat, nu
        // din SELECT-ul de mai sus: acela are listă fixă de coloane, iar o
        // coloană adăugată acolo ar cădea cu 1054 pe o instalare pe care
        // migrarea 007 încă n-a fost rulată. `null` când nu există — pagina
        // verifică și nu desenează nimic.
        'referral'    => referral_public((int) $full['id']),
    ]);
}

/**
 * POST /auth/register { username, name, password, email?, phone?, address?, zone?, customerName?, ref? }
 *
 * Contul pe care și-l face singur vizitatorul de pe site. Până acum conturile se
 * puteau naște în două locuri — instalatorul (o singură dată) și `POST /users`,
 * care cere `require_admin()` — deci un client nu avea de unde începe, iar
 * o recomandare n-avea unde să se agațe: momentul „își face cont" nu exista.
 *
 * Trei lucruri sunt scrise fix și NU se citesc niciodată din corpul cererii:
 *
 *  1. `role = 'client'`. Ruta e publică. Citit din `body()`, un
 *     `{"role":"admin"}` trimis de oricine ar da drepturi depline peste comenzi,
 *     prețuri, conturi, Jurnal și Setări. Rolurile se schimbă doar din pagina
 *     Conturi, de către un administrator.
 *  2. `must_change_password = 0`. Steagul e pentru parolele DICTATE de
 *     administrator; aici omul și-a ales-o singur. Pus pe 1, l-ar fi trimis, la
 *     prima intrare, să schimbe o parolă provizorie pe care nu i-a dat-o nimeni
 *     — și, până atunci, require_auth() îi răspundea 403 pe tot API-ul.
 *  3. `is_system = 0`, implicit din schemă. Conturile de sistem sunt ale
 *     tabletelor, nu ale internetului.
 *
 * Validările și mesajele sunt aceleași cu ale conturilor făcute de administrator: omul
 * primește exact aceleași explicații, indiferent cine îi face contul.
 */
function route_register(): void
{
    $ip = client_ip();

    // Pragul se verifică ÎNAINTE de orice citire sau scriere: o rută publică
    // spre `users` fără prag e o invitație să fie umplută tabela peste noapte.
    if (register_rate_limited($ip, (int) config('max_register_attempts', 10))) {
        fail(
            'Prea multe conturi create de pe această conexiune. Încearcă din nou peste o oră '
            . 'sau sună-ne și îți facem noi contul.',
            429,
            'rate_limited'
        );
    }

    $b        = body();
    $username = strtolower(need($b, 'username'));
    // Lungimile coloanelor, verificate aici: peste ele, INSERT-ul pica cu 1406
    // și omul primea „Eroare internă." în loc de „numele e prea lung".
    $name     = text_field(need($b, 'name'), 'Numele', 128, true);
    // Tăiată de spații, ca peste tot altundeva: autentificarea compară parola
    // trecută prin need(), care taie. Un spațiu lipit de la copiat sau de la
    // tastatura telefonului ar da un hash pe care intrarea în cont nu l-ar mai
    // putea potrivi niciodată.
    $password = need($b, 'password');

    // Abia acum se consumă din plafon: o cerere fără câmpuri n-a încercat nimic.
    // Se numără și încercările care pică mai jos — altfel cineva ar putea afla,
    // fără limită, ce nume de utilizator sunt deja luate.
    record_attempt($ip, GP_REGISTER_KEY, false);

    if (!preg_match('/^[a-z0-9._-]{3,64}$/', $username)) {
        fail('Utilizatorul poate conține doar litere mici, cifre, punct, minus și underscore (3–64).', 422, 'bad_username');
    }
    if (reserved_username($username)) {
        fail('Acest nume de utilizator este rezervat. Alege altul.', 422, 'bad_username');
    }
    if (strlen($password) < 8) {
        fail('Parola trebuie să aibă cel puțin 8 caractere.', 422, 'weak_password');
    }
    if (scalar('SELECT 1 FROM users WHERE username = ?', [$username])) {
        fail('Există deja un cont cu acest utilizator.', 409, 'username_taken');
    }

    $email = trim((string) ($b['email'] ?? ''));
    // Aceeași funcție ca la conturile făcute de administrator: formatul, plus
    // regula că o adresă nu poate sta pe două conturi — fără ea, „Ți-ai uitat
    // parola?" nu mai știe cui să trimită linkul.
    //
    // Stă în api/lib/auth.php, nu în routes/users.php: rutele nu se încarcă una
    // pe alta, iar o a doua copie a unei reguli de unicitate se depărtează de
    // prima exact când contează.
    check_user_email($email, null, true);

    // Verificările de mai sus sunt din memorie, iar între ele și scriere încap
    // două cereri paralele. q_unique() prinde ciocnirea de index și o întoarce
    // ca refuzul pe care cererea l-ar fi primit o clipă mai târziu, în loc de
    // „Eroare internă.".
    // `self_registered` se scrie ÎN ACELAȘI INSERT, nu cu un UPDATE de după.
    //
    // Coloana e temelia pe care `client_by_name()` hotărăște cui i se dau
    // comenzile luate la telefon. Scrisă separat, o cădere între cele două
    // scrieri ar fi lăsat un cont născut pe site nemarcat — adică un cont „al
    // casei" pe veci, exact defectul reparat, doar ajuns pe altă ușă. Aici nu
    // există „între".
    //
    // Coloana se cere numai când migrarea 010 a fost importată: pe o instalare
    // pe care fișierele s-au urcat înaintea importului, cerută necondiționat, ea
    // ar da 1054 „Unknown column" — adică nimeni nu și-ar mai putea face cont.
    // ADRESA ȘI ZONA FIRMEI. Coloana `users.address` există din 001 și n-a
    // lipsit niciodată — doar n-o cerea nimeni: formularul scria în capul lui că
    // „îți ține adresa și telefonul, ca să nu le mai scrii la fiecare comandă"
    // și cerea numai telefonul. Amândouă rămân neobligatorii: cine își face cont
    // seara, de pe telefon, n-are întotdeauna la îndemână adresa exactă a
    // punctului de lucru, iar un formular care refuză din cauza asta pierde
    // clientul înainte de prima comandă. Se pot completa oricând din „Comanda
    // mea".
    $address = text_field($b['address'] ?? '', 'Adresa', 255) ?: null;
    // Zona trece prin aceeași verificare ca la comenzi: numai un nume din lista
    // Setărilor. Un cont cu o zonă pe care panourile n-o pot afișa ar duce
    // minciuna mai departe la fiecare comandă completată din el.
    $zone = zone_field($b['zone'] ?? '') ?: null;

    $cuOrigine = user_origin_supported();
    // Zona are poarta ei, ca `self_registered`: pe o instalare pe care migrarea
    // 016 n-a fost încă importată, coloana n-ar exista, iar cerută
    // necondiționat ar da 1054 — adică nimeni nu și-ar mai putea face cont.
    // Contul se naște atunci fără zonă, cum se năștea și ieri.
    $cuZona = user_zone_supported();
    q_unique(
        "INSERT INTO users (username, password_hash, name, email, phone, address, role, customer_name,
                            active, is_system, must_change_password, password_changed_at"
        . ($cuOrigine ? ', self_registered' : '')
        . ($cuZona ? ', zone' : '') . ")
         VALUES (?, ?, ?, ?, ?, ?, 'client', ?, 1, 0, 0, UTC_TIMESTAMP()"
        . ($cuOrigine ? ', 1' : '')
        . ($cuZona ? ', ?' : '') . ")",
        // Semnul de întrebare al zonei e ULTIMUL din VALUES, deci și valoarea
        // lui trebuie să fie ultima aici. Pusă lângă adresă, ar fi ajuns tăcut
        // în altă coloană.
        array_merge([
            $username, password_hash($password, PASSWORD_DEFAULT), $name,
            $email ?: null, text_field($b['phone'] ?? '', 'Telefonul', 32) ?: null,
            $address,
            text_field($b['customerName'] ?? '', 'Numele firmei', 160) ?: null,
        ], $cuZona ? [$zone] : []),
        'Există deja un cont cu acest utilizator.',
        'username_taken'
    );
    $id = (int) db()->lastInsertId();

    // Contul făcut de pe site NU primește cod de recomandare.
    //
    // Codurile sunt unealta agenților de vânzări, iar clienților li le dă
    // administratorul, unul câte unul, când chiar e cazul. Dat automat aici,
    // oricine se înscrie ar căpăta un cod — inclusiv un script care umple
    // tabelul — și lista recomandărilor ar deveni un dublet al listei de
    // conturi. Cine are nevoie de unul îl cere, și i-l dă adminul din Conturi.
    $codPropriu = null;

    // Recomandantul, dacă vizitatorul a venit pe un link cu „?ref=".
    // Un cod greșit, expirat sau de pe un cont dezactivat NU oprește înscrierea:
    // omul primește cont ca oricine altcineva, doar fără recomandant. Un refuz
    // aici ar costa un client pentru un link stricat.
    $adusDe = referral_link_new_user($id, (string) ($b['ref'] ?? ''));

    // Sesiunea se deschide pe loc, ca omul să nu fie pus să scrie a doua oară
    // parola pe care tocmai a ales-o. Pașii sunt cei din do_login(), fără
    // verificarea parolei — care aici n-are ce verifica.
    $previous = $_COOKIE[GP_COOKIE] ?? '';
    if ($previous !== '') {
        q('DELETE FROM sessions WHERE id = ?', [token_hash($previous)]);
    }

    $token = random_token();
    $csrf  = random_token();
    $zile  = session_days_for('client');
    q(
        'INSERT INTO sessions (id, user_id, csrf_token, expires_at, ip, user_agent)
         VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY, ?, ?)',
        [
            token_hash($token), $id, $csrf, $zile,
            $ip, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]
    );
    set_session_cookie($token, $zile);
    q('UPDATE users SET last_login = UTC_TIMESTAMP() WHERE id = ?', [$id]);

    audit(
        'user_register',
        'user',
        (string) $id,
        'Cont creat de pe site' . ($adusDe ? ', recomandat de ' . $adusDe['name'] : ''),
        $id,
        $name
    );

    // Cererea trimisă de pe site, înainte ca omul să aibă cont, se leagă acum
    // de contul lui — dacă a venit cu jetonul primit atunci.
    // `request_claim_use()` (api/lib/orders.php) stinge jetonul o singură
    // dată, leagă numai ce e nelegat și NU aruncă niciodată: contul e deja
    // scris și sesiunea deja deschisă, deci o socoteală de legături n-are ce
    // să mai strice aici.
    //
    // Un `jeton` trimis spre un server rămas în urmă e ignorat, nu refuzat:
    // ruta citește numai câmpurile pe care le știe. Contul se face oricum.
    $cerereLegata = isset($b['jeton'])
        ? request_claim_use(trim((string) $b['jeton']), $id)
        : null;
    if ($cerereLegata !== null) {
        // Altfel legătura ar apărea din senin în „Comanda mea", fără nimeni
        // care să poată spune de unde.
        audit('request_claim', 'request', (string) $cerereLegata,
            'Cerere legată de contul nou, cu jetonul de la trimitere', $id, $name);
    }

    $nou = one('SELECT * FROM users WHERE id = ?', [$id]);

    $raspuns = [
        'user'        => public_user($nou),
        'csrf'        => $csrf,
        'sessionDays' => $zile,
        'referral'    => $codPropriu === null ? null : [
            'code' => $codPropriu,
            'link' => referral_link($codPropriu),
        ],
        // Cine l-a adus, ca pagina să poată spune „mulțumim, ai venit de la Ana".
        'referredBy'  => $adusDe ? $adusDe['name'] : null,
    ];

    // Ce s-a întâmplat cu jetonul, ca pagina să nu tacă. `legat: false` nu
    // spune DE CE — expirat, folosit, necunoscut, toate arată la fel, altfel
    // răspunsul ar deveni un oracol care spune dacă un jeton a existat.
    if (isset($b['jeton'])) {
        $raspuns['cerere'] = [
            'legat'     => $cerereLegata !== null,
            'requestId' => $cerereLegata,
        ];
    }

    // Anunțul „cont nou" pleacă DUPĂ răspuns, ca la comanda nouă și din același
    // motiv: cine tocmai și-a făcut cont stă cu ochii pe butonul „Creează cont",
    // iar un canal căzut ține conexiunea cincisprezece secunde. Contul e deja
    // scris, sesiunea deja deschisă; anunțul n-are voie să le țină în loc.
    //
    // Numai conturile făcute de pe SITE trec pe aici. Cele făcute de
    // administrator din „Conturi" nu anunță nimic: omul e chiar de față și
    // tocmai a apăsat el butonul — un anunț despre propria faptă e zgomot.
    if (!notify_channels_for('user_new')) {
        json_out($raspuns, 201);
    }
    json_out_early($raspuns, 201);
    try {
        notify_user_new($nou, $adusDe ? (string) $adusDe['name'] : '');
    } catch (Throwable $e) {
        error_log('[gp] anunț „cont nou" nedus pentru ' . $id . ': ' . $e->getMessage());
    }
    exit;
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
    // Zona firmei — din același motiv și pe același drum ca `referral` de mai
    // jos: o citire după cheia primară, nu o coloană în plus în `current_user()`.
    $u['zone'] = user_zone((int) $u['id']);
    // Cât ține sesiunea nu e un secret, iar pagina de intrare are nevoie de
    // el ca să spună limpede de ce nu mai cere parola. Fără asta ar trebui
    // scris un număr în pagină, care s-ar depărta tăcut de configurație.
    json_out([
        'user'        => public_user($u),
        'csrf'        => $u['csrf_token'],
        'sessionDays' => session_days_for((string) $u['role'], !empty($u['is_system'])),
        // Codul de recomandare al contului. Nu se ia din current_user() —
        // interogarea aceea are listă fixă de coloane și e pe drumul fiecărei
        // cereri; o coloană adăugată acolo ar cădea cu 1054 pe o instalare pe
        // care migrarea 007 n-a fost încă rulată, adică ar opri toată aplicația
        // pentru o funcție secundară. Aici e o citire după cheia primară.
        'referral'    => referral_public((int) $u['id']),
        // Cine l-a adus PE EL. Nu e același lucru cu `referral` de deasupra:
        // acela e codul lui, ăsta e al celui care l-a trimis. Magazinul public
        // umple din el caseta „Cod de recomandare" a clientului cu cont
        // (Site Public.dc.html:1374), ca să fie o oglindă a contului și nu o a
        // doua părere: cookie-ul rămas dintr-un link vechi nu mai are voie să
        // ajungă pe cererile lui, iar caseta goală îl ispitea să tasteze un
        // cod din memorie — adică un conflict de descâlcit cu mâna.
        //
        // `null` are DOUĂ înțelesuri aici, și amândouă se desenează la fel:
        // „nu l-a adus nimeni" și „instalarea n-are migrarea 007". Pagina nu
        // scrie nimic în niciunul din cazuri, iar socoteala comisioanelor nu
        // atârnă de rândul ăsta: ea citește `users.referred_by` de-a dreptul.
        'referredByCode' => referral_referred_by_code((int) $u['id']),
        // Contul ăsta e făcut pe site și încă nerecunoscut de casă? De asta
        // atârnă dacă i se leagă comenzile luate la telefon (`client_by_name()`,
        // api/lib/orders.php), deci „Comanda mea" trebuie să poată spune omului
        // de ce îi e panoul gol. Ca `referral` de deasupra: citire după cheia
        // primară, nu o coloană în plus în `current_user()`.
        //
        // `null` înseamnă „instalarea n-are migrarea 010", nu „nu vine de pe
        // site" — pagina nu scrie nimic atunci, fiindcă regula nici nu se aplică.
        'origine'     => user_origin_public((int) $u['id']),
        // A fost importată migrarea 009? Steag despre INSTALARE, nu despre om:
        // nu spune nimic despre contul care întreabă și e la fel pentru toți.
        //
        // Fără el, pagina „Parola mea" ar avea de ales între două rele: să
        // arate mereu butonul „Cere drept de fondator", care pe o bază
        // nemigrată se lovește de 503 după apăsare, sau să nu-l arate
        // niciodată. Ca `referral` de deasupra: pagina află dinainte ce se
        // poate, ca să nu ofere un buton care n-are unde să ducă.
        'founder'     => founder_supported(),
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
            token_hash($token), $u['id'], $csrf, session_days_panou(),
            $ip, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]
    );
    // Nu `session_days_for('angajat')`, cum scria până acum: aceea e durata
    // omului din echipă (șapte zile), iar tableta nu e un om. Cu ea, ecranul
    // din laborator se stingea la o săptămână de la ultima deschidere cu cod,
    // de obicei în mijlocul unei ture. Vezi `session_days_panou()` și
    // `prelungeste_sesiunea_panoului()` în `api/lib/auth.php`: de acolo încolo
    // sesiunea se împinge singură înainte, cât timp tableta e folosită.
    set_session_cookie($token, session_days_panou());

    audit('panel_unlock', 'user', (string) $u['id'], 'Panou deschis cu cod', (int) $u['id'], 'Panou comun');

    json_out(['user' => public_user($u), 'csrf' => $csrf, 'sessionDays' => session_days_panou()]);
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
