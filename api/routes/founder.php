<?php
/**
 * Contul de fondator: cererea, linkul și confirmarea.
 *
 * Fondatorul e administrator plus o singură putere — face și desface
 * administratori și alți fondatori. Regula stă în api/routes/users.php, la
 * „guard_leader_role” — nu aici, fiindcă un fișier din routes/ nu-l vede pe
 * altul. Tocmai fiindcă e puterea de deasupra administratorului, drumul spre
 * ea NU trece prin pagina Conturi: acolo ar însemna că oricine ajunge o dată
 * administrator și-o poate acorda singur.
 *
 * Poarta e cutia poștală a firmei — „company_email” din Setări, azi
 * contact@goldenpastry.ro. Omul CERE din contul lui; linkul de confirmare
 * pleacă la adresa aceea, nu la el. Cine ține cutia hotărăște.
 *
 *   POST /founder/request           — cer pentru mine. Oricine e conectat.
 *   GET  /founder/invite?token=…    — cine e omul din link. Public, cu token.
 *   POST /founder/accept            — confirm. Public, cu token.
 *
 * Ultimele două sunt publice dinadins: cel care deschide cutia poștală a
 * firmei nu are neapărat un cont, iar dacă are, nu e neapărat conectat pe
 * telefonul pe care citește e-mailul. Modelul de încredere e chiar cel de la
 * „mi-am uitat parola”: cine ajunge la mesaj are dreptul din faptul că a ajuns
 * la el. De aceea tokenul e de unică folosință și expiră.
 */

declare(strict_types=1);

/**
 * Poarta comună: rolul și tabelul cer migrarea 009.
 *
 * 503, nu 500: serverul e sănătos, doar nu i s-a importat încă fișierul.
 * Mesajul spune ce se rulează, fiindcă îl citește patronul, nu un programator.
 */
function founder_require_support(): void
{
    if (!founder_supported()) {
        fail(
            'Contul de fondator nu este disponibil: rulează întâi '
              . 'sql/009_pontaj_si_roluri.sql (și 008 înaintea lui).',
            503,
            'founder_unavailable'
        );
    }
}

/**
 * Linkul de confirmare, construit ca fratele lui de la parole.
 *
 * Aceeași regulă și același motiv, cuvânt cu cuvânt: originea se acceptă numai
 * dacă e pe lista albă, altfel oricine ar putea determina aplicația să trimită,
 * de pe adresa firmei, un e-mail cu un link către site-ul lui. Fratele de la
 * parole stă în api/routes/auth.php, sub numele „reset_link”.
 *
 * „?fondator=” cade pe aceeași pagină ca „?parola=”: Intrare.dc.html ajunge
 * `index.html` pe amândouă subdomeniile (.claude/build-deploy.mjs:37).
 */
function founder_link(string $token): string
{
    $fix = trim((string) config('reset_url', ''));
    if ($fix !== '') {
        return $fix . (str_contains($fix, '?') ? '&' : '?') . 'fondator=' . $token;
    }

    $allowed = (array) config('allowed_origins', []);
    $origin  = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || !in_array($origin, $allowed, true)) {
        $origin = (string) ($allowed[0] ?? '');
    }
    return rtrim($origin, '/') . '/?fondator=' . $token;
}

/* ------------------------------------------------------------- cererea -- */

/**
 * POST /founder/request — „vreau să fiu fondator”.
 *
 * Se cere numai pentru sine: nu primește niciun identificator din corpul
 * cererii. Altfel butonul ar fi devenit „propune-l pe X”, iar cel care ține
 * cutia poștală ar fi trebuit să ghicească dacă X chiar a vrut.
 */
function route_founder_request(): void
{
    $u = require_auth();
    founder_require_support();

    // Fiecare apăsare trimite un e-mail către o adresă pe care cel care apasă
    // n-o alege. Fără prag, butonul ar fi o unealtă de umplut cutia patiseriei.
    $ip = client_ip();
    if (founder_rate_limited($ip, (int) config('max_founder_attempts', 3))) {
        fail('Prea multe cereri de pe această conexiune. Încearcă peste o oră.', 429, 'rate_limited');
    }

    if ((int) ($u['is_system'] ?? 0) === 1) {
        fail('Contul de sistem nu poate fi fondator.', 422, 'system_account');
    }
    if ($u['role'] === 'fondator') {
        fail('Ești deja fondator.', 422, 'already_founder');
    }

    // Adresa firmei, nu a omului. Dacă e goală, nu se trimite nimic și se
    // spune de ce: un „am trimis” mincinos ar pune pe cineva să aștepte la
    // nesfârșit un e-mail care n-a plecat niciodată.
    $cutia = trim(setting('company_email'));
    if ($cutia === '') {
        fail('Nu e trecută nicio adresă de e-mail a firmei în Setări → General. '
            . 'Linkul de confirmare n-are unde să plece.', 422, 'no_company_email');
    }

    // Cutia trebuie să fie a FIRMEI, nu a celui care cere. Un administrator are
    // voie să scrie „E-mail public" — adresa de contact de pe site e treaba lui
    // — dar dacă tot el cere apoi rolul, linkul de confirmare i se întoarce în
    // mână: ceremonia se ține până la capăt, cu jurnal cu tot, numai că cel care
    // deschide cutia e chiar cel care cere. Rolul de fondator ar fi luat, nu dat.
    //
    // Și nu e o luare fără urmări chiar și pe o bază fără niciun fondator:
    // primul care apucă rolul îi îngheață pe ceilalți administratori: din clipa
    // aceea founder_exists() e adevărat, și nimeni în afară de el nu mai poate
    // umbla la roluri de administrator. Portița de la api/lib/auth.php:259-262
    // spune limpede că NU se întinde și asupra ACORDĂRII rolului — aici o ținem.
    //
    // Perechea ei stă în api/routes/users.php, la ramura de e-mail din
    // route_users_update(): acolo se apără adresa unui CONT de conducere. Sunt
    // două uși spre același capăt — cine umblă la una să se uite și la cealaltă.
    //
    // Se numără numai CEILALȚI șefi activi. Cine e singurul administrator de pe
    // bază n-are de la cine lua, iar un refuz l-ar închide pe dinafară:
    // instalarea proaspătă care își scrie întâi adresa firmei peste cea din
    // sămânță n-ar mai putea cere rolul niciodată. Aceeași socoteală ca portița
    // de la founder_exists(), din același motiv.
    $scrisaDe = (int) scalar('SELECT updated_by FROM settings WHERE setting_key = ?', ['company_email']);
    $altiSefi = (int) scalar(
        "SELECT COUNT(*) FROM users WHERE role IN ('admin', 'fondator') AND active = 1 AND id <> ?",
        [$u['id']]
    );
    if ($scrisaDe === (int) $u['id'] && $altiSefi > 0) {
        fail('Adresa firmei din Setări → General a fost scrisă chiar de tine, deci linkul de confirmare '
            . 'ți s-ar întoarce în mână. Roagă alt administrator sau un fondator să scrie el adresa, '
            . 'apoi cere din nou.', 403, 'mailbox_self_set');
    }

    record_attempt($ip, GP_FOUNDER_KEY, false);

    // Cererile nefolosite de dinainte se închid: rămâne valabil un singur link,
    // cel din urmă e-mail primit. Aceeași regulă ca la parola uitată — altfel
    // un link mai vechi, dintr-un e-mail rămas în cutie, ar mai putea ridica pe
    // cineva în rang mult după ce cererea a fost uitată.
    q('UPDATE founder_invites SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL',
        [$u['id']]);

    $token = random_token();
    q(
        'INSERT INTO founder_invites (user_id, requested_by, token_hash, expires_at)
         VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? MINUTE)',
        [$u['id'], $u['id'], token_hash($token), (int) config('founder_minutes', 60)]
    );

    $minute = (int) config('founder_minutes', 60);
    // Cine cere, scris pe față și cu tot ce ajută la recunoaștere. Cel care
    // deschide cutia firmei trebuie să poată spune „da, ăsta e omul meu” sau
    // „pe ăsta nu-l cunosc” fără să caute prin pagina Conturi.
    $text = implode("\n", [
        'Cineva cere drept de fondator în Golden Pastry.',
        '',
        'Contul care a cerut:',
        '  nume:        ' . $u['name'],
        '  utilizator:  ' . $u['username'],
        '  e-mail:      ' . (trim((string) $u['email']) !== '' ? $u['email'] : '(fără)'),
        '  rol de acum: ' . $u['role'],
        '',
        'Fondatorul poate face și desface administratori și alți fondatori.',
        'Dacă îl recunoști și vrei să-i dai dreptul ăsta, deschide adresa de mai jos:',
        '',
        founder_link($token),
        '',
        'Linkul este valabil ' . $minute . ' de minute și poate fi folosit o singură dată.',
        'Dacă nu recunoști contul, nu face nimic: fără confirmare nu se schimbă nimic.',
        '',
        'Golden Pastry',
    ]);

    // Un server de mail căzut nu are voie să lase impresia că cererea a mers.
    // Spre deosebire de „am uitat parola” — unde răspunsul e dinadins același
    // pentru toate cazurile, ca formularul să nu devină o listă de conturi —
    // aici cel care cere este deja autentificat și nu află nimic despre altul,
    // deci eșecul se poate spune pe față. Și trebuie: altfel omul așteaptă.
    try {
        send_email($cutia, 'Cerere de drept de fondator — Golden Pastry', $text);
    } catch (Throwable $e) {
        error_log('[gp] e-mail de fondator eșuat pentru contul ' . $u['id'] . ': ' . $e->getMessage());
        fail('Cererea s-a înregistrat, dar e-mailul n-a putut fi trimis. '
            . 'Verifică setările de e-mail, apoi cere din nou.', 502, 'mail_failed');
    }

    audit('founder_request', 'user', (string) $u['id'],
        $u['name'] . ' → link trimis la ' . $cutia);

    json_out([
        'ok'      => true,
        'sentTo'  => $cutia,
        'minutes' => $minute,
        'message' => 'Am trimis linkul de confirmare la ' . $cutia . '. '
            . 'Cine deschide cutia aceea hotărăște. Linkul e valabil ' . $minute . ' de minute.',
    ]);
}

/* ---------------------------------------------------------- confirmarea -- */

/**
 * Invitația din spatele unui token, citită fără să fie folosită.
 *
 * @return array{id:int,user_id:int} rândul viu; altfel iese prin fail()
 */
function founder_invite_by_token(string $token): array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        fail('Linkul nu este valid.', 422, 'bad_token');
    }
    $r = one(
        'SELECT id, user_id FROM founder_invites
          WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()',
        [token_hash($token)]
    );
    if (!$r) {
        fail('Linkul a expirat sau a fost deja folosit. Omul poate cere altul din contul lui.',
            410, 'expired_token');
    }
    return ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id']];
}

/**
 * GET /founder/invite?token=… — cine ar deveni fondator.
 *
 * Ca să nu se confirme în orb. Cine are tokenul poate oricum ridica omul în
 * rang, deci a-i arăta numele nu dă nimic în plus — dar îi dă exact ce-i
 * trebuie ca să poată spune „nu”.
 */
function route_founder_invite(): void
{
    founder_require_support();
    $inv = founder_invite_by_token((string) ($_GET['token'] ?? ''));

    $u = one('SELECT id, username, name, role, active, is_system FROM users WHERE id = ?', [$inv['user_id']]);
    if (!$u || (int) $u['active'] !== 1) {
        fail('Contul nu mai există sau a fost dezactivat.', 410, 'no_account');
    }

    json_out(['invite' => [
        'name'     => $u['name'],
        'username' => $u['username'],
        'role'     => $u['role'],
        'already'  => $u['role'] === 'fondator',
    ]]);
}

/**
 * POST /founder/accept { token } — confirmarea, de la cutia poștală a firmei.
 *
 * Sesiunile contului NU se închid, spre deosebire de schimbarea parolei: acolo
 * se închid tocmai fiindcă parola veche putea fi știută de altcineva. Aici nu
 * s-a schimbat niciun secret, iar `current_user()` citește rolul din `users` la
 * fiecare cerere (api/lib/auth.php:30) — deci omul vede drepturile noi la
 * următoarea încărcare de pagină, fără să fie dat afară din cont.
 */
function route_founder_accept(): void
{
    founder_require_support();

    $inv = founder_invite_by_token(trim((string) (body()['token'] ?? '')));

    $u = one('SELECT id, username, name, role, active, is_system FROM users WHERE id = ?', [$inv['user_id']]);
    if (!$u || (int) $u['active'] !== 1) {
        fail('Contul nu mai există sau a fost dezactivat.', 410, 'no_account');
    }
    if ((int) $u['is_system'] === 1) {
        fail('Contul de sistem nu poate fi fondator.', 422, 'system_account');
    }

    // Tokenul se stinge chiar dacă rolul era deja pus: altfel un link rămas
    // deschis într-o filă ar putea fi apăsat de două ori și ar scrie de două
    // ori în jurnal aceeași ridicare în rang.
    q('UPDATE founder_invites SET used_at = UTC_TIMESTAMP() WHERE id = ?', [$inv['id']]);
    q('UPDATE founder_invites SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL',
        [$u['id']]);

    if ($u['role'] === 'fondator') {
        json_out(['ok' => true, 'name' => $u['name'], 'already' => true]);
    }

    q("UPDATE users SET role = 'fondator' WHERE id = ?", [$u['id']]);

    // Actorul e contul ridicat, fiindcă altul nu există: confirmarea vine de la
    // o cutie poștală, nu de la o sesiune. De aceea detaliul poartă adresa IP —
    // ea e singura urmă a celui care a apăsat. (`audit()` o scrie oricum într-o
    // coloană proprie; aici e scrisă și în text, ca să se vadă în listă.)
    audit('founder_grant', 'user', (string) $u['id'],
        $u['name'] . ' a devenit fondator · confirmat de la ' . client_ip(),
        (int) $u['id'], (string) $u['name']);

    json_out(['ok' => true, 'name' => $u['name'], 'already' => false]);
}
