<?php
/**
 * Notificări pentru comenzi noi.
 *
 * Canalele sunt independente și se activează din panoul de Setări. Fiecare
 * încercare se scrie în tabelul `notifications`, deci se poate vedea ulterior
 * ce s-a trimis și ce a eșuat — o notificare pierdută în tăcere înseamnă o
 * comandă care nu ajunge la laborator.
 *
 * E-mailul funcționează imediat. WhatsApp cere WhatsApp Business API (Meta
 * Cloud API): cont Meta Business, număr dedicat și șablon aprobat. Mesajele
 * inițiate de business se taxează per mesaj.
 */

declare(strict_types=1);

/** Trimite notificarea pentru o comandă nouă pe toate canalele active. */
function notify_new_order(array $order): void
{
    $text = format_order_message($order);

    if (setting_bool('notify_email_enabled')) {
        send_channel('email', $text, $order['id'], 'Comandă nouă #' . $order['seq']);
    }
    if (setting_bool('notify_whatsapp_enabled')) {
        send_channel('whatsapp', $text, $order['id'], null, $order);
    }
    if (setting_bool('notify_telegram_enabled')) {
        send_channel('telegram', $text, $order['id']);
    }
}

/**
 * Notificare pentru o cerere nouă de pe site.
 *
 * Aici se declanșează mesajul către tine: cererea aduce lucru de făcut —
 * trebuie verificată și acceptată — spre deosebire de avansarea unei comenzi
 * prin etapele din laborator, care nu are de ce să te anunțe.
 */
function notify_new_request(array $req): void
{
    $text = format_request_message($req);

    if (setting_bool('notify_email_enabled')) {
        send_channel('email', $text, null, 'Cerere nouă de la ' . $req['customerName']);
    }
    if (setting_bool('notify_whatsapp_enabled')) {
        send_channel('whatsapp', $text, null);
    }
    if (setting_bool('notify_telegram_enabled')) {
        send_channel('telegram', $text, null);
    }
}

function format_request_message(array $req): string
{
    $lines   = [];
    $lines[] = 'Cerere nouă — ' . $req['customerName'];
    if (!empty($req['day'])) {
        $lines[] = 'Pentru: ' . $req['day'] . (!empty($req['slot']) ? ', ora ' . $req['slot'] : '');
    }
    if (!empty($req['phone'])) {
        $lines[] = 'Telefon: ' . $req['phone'];
    }
    if (!empty($req['zone'])) {
        $lines[] = 'Zonă: ' . $req['zone'];
    }
    $lines[] = '';
    foreach ($req['items'] as $it) {
        $lines[] = sprintf('· %s × %s', rtrim(rtrim(number_format($it['qty'], 2, ',', ''), '0'), ','), $it['name']);
    }
    $lines[] = '';
    $lines[] = 'Total estimat: ' . number_format($req['total'], 2, ',', '.') . ' lei';
    if (!empty($req['notes'])) {
        $lines[] = 'Mențiuni: ' . $req['notes'];
    }
    $lines[] = '';
    $lines[] = 'Acceptă sau respinge din pagina Administrare Comenzi.';

    return implode("\n", $lines);
}

/** Mesajul citibil, folosit de toate canalele. */
function format_order_message(array $order): string
{
    $lines = [];
    $lines[] = 'Comandă nouă #' . $order['seq'] . ' — ' . $order['customerName'];
    $lines[] = 'Pentru: ' . $order['day'];
    if (!empty($order['phone'])) {
        $lines[] = 'Telefon: ' . $order['phone'];
    }
    if (!empty($order['address'])) {
        $lines[] = 'Adresă: ' . $order['address'];
    }
    $lines[] = '';
    foreach ($order['items'] as $it) {
        $lines[] = sprintf('· %s × %s', rtrim(rtrim(number_format($it['qty'], 2, ',', ''), '0'), ','), $it['name']);
    }
    $lines[] = '';
    $lines[] = 'Total: ' . number_format($order['total'], 2, ',', '.') . ' lei';
    if (!empty($order['notes'])) {
        $lines[] = 'Mențiuni: ' . $order['notes'];
    }
    return implode("\n", $lines);
}

/**
 * Înregistrează încercarea și trimite. Eșecul nu aruncă excepție: comanda este
 * deja salvată, iar notificarea rămâne marcată `failed` pentru diagnostic.
 */
function send_channel(string $channel, string $text, ?int $orderId, ?string $subject = null, ?array $order = null): bool
{
    $to = match ($channel) {
        'email'    => setting('notify_email_to'),
        'whatsapp' => setting('notify_whatsapp_to'),
        'telegram' => setting('notify_telegram_chat_id'),
        default    => '',
    };

    q(
        'INSERT INTO notifications (channel, recipient, subject, payload, order_id, status, attempts)
         VALUES (?, ?, ?, ?, ?, ?, 1)',
        [$channel, $to ?: null, $subject, $text, $orderId, 'pending']
    );
    $id = (int) db()->lastInsertId();

    try {
        if ($to === '') {
            throw new RuntimeException('Destinatarul nu este configurat în Setări.');
        }
        match ($channel) {
            'email'    => send_email($to, $subject ?? 'Comandă nouă', $text),
            'whatsapp' => send_whatsapp($to, $text, $order),
            'telegram' => send_telegram($to, $text),
            default    => throw new RuntimeException('Canal necunoscut: ' . $channel),
        };
        q('UPDATE notifications SET status = ?, sent_at = UTC_TIMESTAMP() WHERE id = ?', ['sent', $id]);
        if ($channel === 'whatsapp') {
            set_whatsapp_status('conectat', '');
        }
        return true;
    } catch (Throwable $e) {
        q('UPDATE notifications SET status = ?, error = ? WHERE id = ?', ['failed', $e->getMessage(), $id]);
        if ($channel === 'whatsapp') {
            set_whatsapp_status('eroare', $e->getMessage());
        }
        error_log('[gp] notificare ' . $channel . ' eșuată: ' . $e->getMessage());
        return false;
    }
}

function set_whatsapp_status(string $status, string $error): void
{
    q('UPDATE settings SET value = ? WHERE setting_key = ?', [$status, 'notify_whatsapp_status']);
    q('UPDATE settings SET value = ? WHERE setting_key = ?', [substr($error, 0, 500), 'notify_whatsapp_last_error']);
}

/* -------------------------------------------------------------- e-mail -- */

/**
 * Numele care apare ca expeditor, luat din Setări.
 *
 * Era scris în cod, „Golden Pastry”. Firma își schimba denumirea din panou —
 * unde scrie negru pe alb că numele apare în antetul mesajelor — și e-mailurile
 * plecau mai departe cu cea veche.
 *
 * Diacriticele se codifică, la fel ca subiectul: un antet cu litere românești
 * brute este refuzat de unele servere de mail, iar atunci nu se pierde doar
 * numele, ci tot anunțul comenzii noi. Ce ar putea rupe antetul în două —
 * saltul de linie, ghilimelele, parantezele unghiulare — se scoate: un nume
 * scris greșit nu are voie să adauge un antet pe care nu l-a cerut nimeni.
 */
function email_from_name(): string
{
    $name = trim(str_replace(["\r", "\n", '"', '\\', '<', '>'], ' ', setting('company_name', 'Golden Pastry')));
    if ($name === '') {
        $name = 'Golden Pastry';
    }
    return preg_match('/[^\x20-\x7E]/', $name) === 1
        ? '=?UTF-8?B?' . base64_encode($name) . '?='
        : '"' . $name . '"';
}

function send_email(string $to, string $subject, string $text): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Adresă de e-mail invalidă: ' . $to);
    }
    $from = setting('notify_email_from', 'noreply@goldenpastry.ro');
    // Expeditorul intră în antet exact așa cum a fost scris în Setări, iar
    // anteturile se despart prin salt de linie: o valoare cu CR sau LF în ea ar
    // adăuga anteturi pe care nu le-a scris nimeni — de pildă un „Bcc:” către
    // un străin, la fiecare comandă nouă. Verificarea de la salvare nu ajunge:
    // în baza de date poate sta încă o valoare pusă înaintea ei. Oprim aici, cu
    // motivul scris pe față, ca anunțul să apară „eșuat” în Setări și să se
    // vadă ce anume trebuie corectat.
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Adresa expeditor din Setări nu este o adresă validă: ' . $from);
    }

    $headers = implode("\r\n", [
        'From: ' . email_from_name() . ' <' . $from . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: golden-pastry',
    ]);
    // Subiectul se codifică: diacriticele nu sunt permise brut în anteturi.
    $encoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    if (!@mail($to, $encoded, $text, $headers)) {
        throw new RuntimeException('Funcția mail() a eșuat. Verifică setările de e-mail din cPanel.');
    }
}

/* ------------------------------------------------------------ WhatsApp -- */

/**
 * Meta Cloud API. Mesajele inițiate de business trebuie trimise ca „template”
 * aprobat în prealabil — text liber este permis doar în fereastra de 24h
 * deschisă de un mesaj al destinatarului.
 */
function send_whatsapp(string $to, string $text, ?array $order = null): void
{
    $provider = setting('notify_whatsapp_provider', 'meta');
    $token    = setting('notify_whatsapp_token');
    if ($token === '') {
        throw new RuntimeException('Tokenul de WhatsApp nu este configurat în Setări.');
    }

    $to = preg_replace('/[^0-9]/', '', $to);
    if ($to === '') {
        throw new RuntimeException('Număr de WhatsApp invalid.');
    }

    if ($provider === 'twilio') {
        send_whatsapp_twilio($to, $text, $token);
        return;
    }

    $phoneId = setting('notify_whatsapp_phone_id');
    if ($phoneId === '') {
        throw new RuntimeException('Phone Number ID lipsește din Setări.');
    }
    $template = setting('notify_whatsapp_template', 'comanda_noua');

    // Șablonul primește un singur parametru: rezumatul comenzii. WhatsApp nu
    // acceptă salt de linie în parametri, deci textul se aplatizează.
    $param = str_replace("\n", ' · ', $text);
    if (mb_strlen($param) > 1000) {
        $param = mb_substr($param, 0, 997) . '...';
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => [
            'name'       => $template,
            'language'   => ['code' => 'ro'],
            'components' => [[
                'type'       => 'body',
                'parameters' => [['type' => 'text', 'text' => $param]],
            ]],
        ],
    ];

    $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($phoneId) . '/messages';
    [$code, $resp] = http_post_json($url, $payload, ['Authorization: Bearer ' . $token]);

    if ($code < 200 || $code >= 300) {
        $msg = json_decode($resp, true)['error']['message'] ?? substr($resp, 0, 300);
        throw new RuntimeException('Meta a răspuns ' . $code . ': ' . $msg);
    }
}

function send_whatsapp_twilio(string $to, string $text, string $token): void
{
    // La Twilio, „Phone Number ID” ține Account SID, iar tokenul este Auth Token.
    $sid  = setting('notify_whatsapp_phone_id');
    $from = setting('notify_whatsapp_from', '');
    if ($sid === '' || $from === '') {
        throw new RuntimeException('Pentru Twilio completează Account SID și numărul expeditor în Setări.');
    }

    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
    $post = http_build_query([
        'From' => 'whatsapp:+' . preg_replace('/[^0-9]/', '', $from),
        'To'   => 'whatsapp:+' . $to,
        'Body' => $text,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $sid . ':' . $token,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException('Conexiune către Twilio eșuată: ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('Twilio a răspuns ' . $code . ': ' . substr((string) $resp, 0, 300));
    }
}

/* ------------------------------------------------------------ Telegram -- */

function send_telegram(string $chatId, string $text): void
{
    $token = setting('notify_telegram_token');
    if ($token === '') {
        throw new RuntimeException('Tokenul de Telegram nu este configurat în Setări.');
    }
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    [$code, $resp] = http_post_json($url, ['chat_id' => $chatId, 'text' => $text]);

    if ($code < 200 || $code >= 300) {
        $msg = json_decode($resp, true)['description'] ?? substr($resp, 0, 300);
        throw new RuntimeException('Telegram a răspuns ' . $code . ': ' . $msg);
    }
}

/* ------------------------------------------------------------- ajutoare -- */

/** POST JSON. Folosește cURL dacă există, altfel stream-urile PHP. */
function http_post_json(string $url, array $payload, array $headers = []): array
{
    $json    = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = array_merge(['Content-Type: application/json'], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException('Conexiune eșuată: ' . $err);
        }
        return [$code, (string) $resp];
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $json,
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        throw new RuntimeException('Conexiune eșuată (fără cURL disponibil).');
    }
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int) $m[1];
        }
    }
    return [$code, $resp];
}
