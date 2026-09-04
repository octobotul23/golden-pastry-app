<?php
/**
 * Criptarea valorilor sensibile din tabelul `settings`.
 *
 * Tokenurile de API (WhatsApp, Telegram) sunt introduse din panoul de Setări
 * și ajung în baza de date. Dacă cineva obține o copie a bazei — un backup
 * pierdut, de exemplu — tokenurile nu trebuie să fie lizibile.
 *
 * AES-256-GCM: criptare autentificată, deci o valoare modificată în baza de
 * date este respinsă la decriptare, nu decriptată greșit în tăcere.
 * Formatul stocat: base64(iv[12] || tag[16] || ciphertext).
 */

declare(strict_types=1);

const GP_CIPHER  = 'aes-256-gcm';
const GP_IV_LEN  = 12;
const GP_TAG_LEN = 16;

/**
 * Cheia din config are forma cerută de criptare?
 *
 * Regula stă într-un singur loc fiindcă o pune și verificarea de dinaintea
 * publicării (api/verifica.php). Scrisă în două locuri, se desparte: acolo
 * ajunge „bine" o cheie pe care crypto_key() o refuză, iar omul care publică
 * descoperă asta abia la crearea primului administrator, cu instalarea oprită
 * la jumătate. Funcția nu cere nici config(), nici fail(), tocmai ca
 * verificarea să o poată chema fără să pornească restul aplicației.
 */
function crypto_key_valid(string $hex): bool
{
    return preg_match('/^[0-9a-fA-F]{64}$/', $hex) === 1;
}

/** Cheia binară de 32 de octeți, din config. */
function crypto_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $hex = (string) config('encryption_key');
    if ($hex === '') {
        fail(
            'Lipsește `encryption_key` din config.php. Generează una cu: '
            . 'php -r "echo bin2hex(random_bytes(32));"',
            500,
            'no_encryption_key'
        );
    }
    if (!crypto_key_valid($hex)) {
        fail('`encryption_key` trebuie să fie 64 de caractere hexazecimale (32 de octeți).', 500, 'bad_encryption_key');
    }

    return $key = hex2bin($hex);
}

/** Criptează un text. String-ul gol rămâne gol (înseamnă „necompletat”). */
function encrypt_value(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $iv  = random_bytes(GP_IV_LEN);
    $tag = '';
    $ct  = openssl_encrypt($plain, GP_CIPHER, crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, '', GP_TAG_LEN);
    if ($ct === false) {
        fail('Criptare eșuată.', 500, 'encrypt_failed');
    }
    return base64_encode($iv . $tag . $ct);
}

/**
 * Decriptează. Întoarce null dacă valoarea este coruptă sau cheia s-a schimbat
 * — apelantul decide ce înseamnă asta (de regulă: „reintrodu tokenul”).
 */
function decrypt_value(string $stored): ?string
{
    if ($stored === '') {
        return '';
    }
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= GP_IV_LEN + GP_TAG_LEN) {
        return null;
    }
    $iv    = substr($raw, 0, GP_IV_LEN);
    $tag   = substr($raw, GP_IV_LEN, GP_TAG_LEN);
    $ct    = substr($raw, GP_IV_LEN + GP_TAG_LEN);
    $plain = openssl_decrypt($ct, GP_CIPHER, crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);

    return $plain === false ? null : $plain;
}

/**
 * Reprezentarea sigură a unui secret, pentru afișare în interfață.
 *
 * Ultimele 4 caractere rămân vizibile doar la secretele lungi (tokenuri de
 * API), ca administratorul să distingă ce valoare e configurată. La secretele
 * scurte — codul panoului are 6 caractere — cele 4 caractere ar însemna
 * aproape tot secretul, deci nu se arată nimic.
 */
function mask_secret(?string $plain): string
{
    if ($plain === null || $plain === '') {
        return '';
    }
    return strlen($plain) < 12
        ? str_repeat('•', 8)
        : str_repeat('•', 8) . substr($plain, -4);
}

/** Token aleator, sigur criptografic (pentru sesiuni și resetări de parolă). */
function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Codul de acces al tabletelor din laborator, generat la instalare.
 *
 * Înainte, instalatorul scria un literal, același la orice instalare, care
 * apărea și în sursa paginilor publicate pe lab.goldenpastry.ro. Un cod pe
 * care îl știe oricine a citit sursa — sau documentația — nu e cod: deschidea
 * o sesiune de angajat pe contul comun „panou”, dintr-o singură cerere, de
 * oriunde.
 *
 * Se tastează cu mâna, de mai mulți oameni, pe o tabletă, deci alfabetul nu
 * conține perechile care se citesc greșit: i / l / 1 și o / 0. Rămân 31 de
 * caractere; opt dintre ele înseamnă aproape 40 de biți — imposibil de ghicit
 * pe o poartă care se închide după 20 de încercări.
 *
 * Minusculele nu sunt o slăbiciune, ci o alegere: codul se compară exact
 * (`hash_equals`), iar o tastatură de tabletă care scrie prima literă cu
 * majusculă ar face un cod corect să pară greșit.
 *
 * `random_int` e generatorul criptografic al PHP-ului. Cu `rand()`, codul s-ar
 * putea reconstitui din ceasul serverului — adică n-ar fi mai bun decât unul fix.
 */
function random_panel_code(int $len = 8): string
{
    $alfabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $max     = strlen($alfabet) - 1;
    $out     = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alfabet[random_int(0, $max)];
    }
    return $out;
}

/** Hash-ul stocat pentru un token. */
function token_hash(string $token): string
{
    return hash('sha256', $token);
}
