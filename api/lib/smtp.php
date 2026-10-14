<?php

declare(strict_types=1);

/**
 * Poșta: cele două drumuri pe care pleacă un e-mail (runda 2.7.5).
 *
 * DE CE EXISTĂ FIȘIERUL ĂSTA
 * ==========================
 *
 * Până la 2.7.5 e-mailul avea un singur drum: funcția `mail()` din PHP, adică
 * „dă-i mesajul programului de poștă al serverului”. Pe găzduirea veche
 * programul acela exista de la sine. Pe VPS nu există niciunul până nu-l pune
 * cineva — iar `mail()` întoarce atunci `false`, fără niciun cuvânt. „Ți-ai
 * uitat parola?” a răspuns mai departe „am trimis un e-mail”, și n-a mai
 * trimis niciunul.
 *
 * HOTĂRÂREA PATRONULUI: amândouă drumurile.
 *
 *   · CĂSUȚA (SMTP) — când e completată în Setări → Notificări → E-mail.
 *     Mesajul pleacă prin căsuța firmei, de la furnizorul ei, cu utilizator și
 *     parolă: exact ca dintr-un program de poștă de pe telefon.
 *   · DIRECT — când căsuța e goală. Mesajul se dă programului de poștă al
 *     serverului, cum se făcea și până acum.
 *
 * O CĂSUȚĂ CARE DĂ GREȘ NU CADE ÎN TĂCERE PE DRUMUL DIRECT. Dacă patronul a
 * completat căsuța, a ales pe unde pleacă poșta firmei; un mesaj plecat „pe
 * ocolite” de pe server ar ajunge în Spam ori nicăieri, iar în panou ar scrie
 * că a plecat. Greșeala se spune, cu treapta la care s-a oprit convorbirea.
 *
 * DE CE E SCRIS DE MÂNĂ. Casa n-are Composer și nu urcă biblioteci pe server
 * (vezi docs/). Convorbirea SMTP de care e nevoie aici e scurtă și veche de
 * patruzeci de ani: salut, criptare, autentificare, plic, mesaj.
 *
 * CE NU SE FACE NICIODATĂ, ȘI E PĂZIT ÎN `.claude/check-posta.mjs`:
 *
 *   · parola nu pleacă pe o legătură necriptată: `smtp_autentifica()` refuză
 *     dacă legătura n-a fost criptată, oricine ar chema-o;
 *   · certificatul serverului se verifică mereu, cu nume cu tot. Singura
 *     portiță e cheia `smtp_ca_file` din config.php, pentru probe, și ea e
 *     ascultată NUMAI când gazda e calculatorul însuși (vezi `smtp_ca_de_proba()`);
 *   · niciun antet nu se scrie fără `mail_antet()`, care oprește saltul de linie;
 *   · parola nu intră în niciun mesaj de eroare: răspunsul serverului trece
 *     prin `smtp_fara_secret()` înainte să fie scris undeva;
 *   · nimic din ce a sosit pe legătura necriptată nu se citește după criptare
 *     (vezi `smtp_cripteaza()`), iar un răspuns are un singur ceas, oricât de
 *     mărunt ar picura serverul (vezi `smtp_citeste()`).
 *
 * Fișierul nu face nimic la încărcare: numai funcții. Stă în amândouă listele
 * de biblioteci (api/index.php și api/cron/plati.php), fiindcă `send_email()`
 * din notify.php îl cheamă, iar notify.php e în amândouă.
 */

/** Cât se așteaptă legarea, un răspuns, și toată convorbirea — în secunde. */
const GP_SMTP_LEGARE  = 8;
const GP_SMTP_RASPUNS = 10;
const GP_SMTP_TOTAL   = 25;

/** Cât de lung poate fi un rând de răspuns (octeți) și câte rânduri poate avea un răspuns. */
const GP_SMTP_RAND    = 2048;
const GP_SMTP_RANDURI = 64;

/* -------------------------------------------------- ce e scris în Setări -- */

/**
 * Căsuța, așa cum e scrisă în Setări.
 *
 * Pe o bază fără migrarea 037 rândurile lipsesc, `setting()` întoarce gol, iar
 * poșta merge pe drumul direct, ca înainte: codul nou nu cere baza nouă.
 *
 * @return array{host: string, port: int, user: string, pass: string, security: string}
 */
function smtp_date(): array
{
    $port = (int) trim(setting('mail_smtp_port', '465'));
    $fel  = strtolower(trim(setting('mail_smtp_security', 'auto')));
    return [
        'host'     => strtolower(trim(setting('mail_smtp_host'))),
        'port'     => $port >= 1 && $port <= 65535 ? $port : 465,
        'user'     => trim(setting('mail_smtp_user')),
        'pass'     => setting('mail_smtp_password'),
        'security' => in_array($fel, ['auto', 'ssl', 'starttls'], true) ? $fel : 'auto',
    ];
}

/**
 * E salvată o parolă a căsuței? Numai da ori nu.
 *
 * O cere paza din Setări (`guard_posta()`): gazda schimbată pe lângă o parolă
 * salvată ar trimite parola veche spre serverul nou. Întrebarea stă AICI, nu în
 * rută, ca parola să rămână citită dintr-un singur loc (`smtp_date()`), iar
 * ruta să afle despre ea numai „e pusă?” — niciodată valoarea.
 */
function smtp_parola_pusa(): bool
{
    return smtp_date()['pass'] !== '';
}

/**
 * Cât din căsuță e completat: „goala”, „jumatate” ori „intreaga”.
 *
 * Trei casete o fac întreagă: gazda, utilizatorul și parola. Portul și felul
 * criptării au valori din pornire, deci nu se numără.
 */
function smtp_stare(): string
{
    $d    = smtp_date();
    $pline = (int) ($d['host'] !== '') + (int) ($d['user'] !== '') + (int) ($d['pass'] !== '');
    return $pline === 0 ? 'goala' : ($pline === 3 ? 'intreaga' : 'jumatate');
}

/**
 * Pleacă poșta prin căsuță?
 *
 * GAZDA e comutatorul: cu ea scrisă, poșta merge prin căsuță — și dacă lipsește
 * utilizatorul ori parola, trimiterea se oprește cu vorba asta, nu ocolește pe
 * drumul direct. Fără gazdă, drumul e cel direct.
 */
function smtp_configurat(): bool
{
    return smtp_date()['host'] !== '';
}

/** Drumul pe care pleacă e-mailul acum: „smtp” ori „direct”. */
function mail_cale(): string
{
    return smtp_configurat() ? 'smtp' : 'direct';
}

/**
 * Programul din `sendmail_path`, fără argumentele lui.
 *
 * Valoarea e o linie de comandă („/usr/sbin/sendmail -t -i”), iar pe Windows
 * calea poate fi între ghilimele, cu spații în ea.
 */
function mail_program_din_ini(): string
{
    $linie = trim((string) ini_get('sendmail_path'));
    if ($linie === '') {
        return '';
    }
    if ($linie[0] === '"' || $linie[0] === "'") {
        $capat = strpos($linie, $linie[0], 1);
        return $capat === false ? substr($linie, 1) : substr($linie, 1, $capat - 1);
    }
    // „C:/laragon/bin/mailpit/1.22.3/mailpit.exe sendmail”: până la primul spațiu.
    return (string) preg_split('/\s+/', $linie)[0];
}

/**
 * Are serverul un program de poștă? „da”, „nu” ori „nestiut”.
 *
 * SE CAUTĂ LA FEL CA ÎN SCRIPTURILE DE VPS (`instaleaza-vps.sh`,
 * `actualizeaza-vps.sh`): întâi `/usr/sbin/sendmail`, dacă se poate rula; apoi
 * programul pe care îl știe PHP (`sendmail_path`) și locurile obișnuite din
 * PATH. Două căutări care s-ar despărți ar face pagina să spună „nu e” pe un
 * server unde scriptul tocmai a spus „e”.
 *
 * „NEȘTIUT” NU E „NU”. Unde PHP n-are voie să se uite (`open_basedir`,
 * `is_executable` oprit), ori unde nu există un program de căutat (Windows fără
 * `sendmail_path`: acolo `mail()` vorbește de-a dreptul cu un server de poștă),
 * răspunsul cinstit e că nu se știe. Un „nu” spus din neputință ar opri pe loc
 * e-mailuri care altfel ar pleca.
 */
function mail_direct_stare(): string
{
    static $stare = null;
    if ($stare !== null) {
        return $stare;
    }
    if (!function_exists('mail')) {
        // Funcția e oprită de găzduire: pe drumul ăsta nu pleacă nimic, sigur.
        return $stare = 'nu';
    }
    if (!function_exists('is_executable') || !function_exists('is_file')) {
        return $stare = 'nestiut';
    }
    $ini = mail_program_din_ini();

    if (PHP_OS_FAMILY === 'Windows') {
        if ($ini === '') {
            return $stare = 'nestiut';
        }
        return $stare = @is_file($ini) ? 'da' : 'nu';
    }

    $locuri = ['/usr/sbin/sendmail'];
    if ($ini !== '') {
        $locuri[] = $ini;
    }
    foreach (explode(':', (string) getenv('PATH')) as $dosar) {
        if ($dosar !== '') {
            $locuri[] = rtrim($dosar, '/') . '/sendmail';
        }
    }
    foreach (['/usr/lib/sendmail', '/usr/bin/sendmail', '/usr/local/sbin/sendmail'] as $loc) {
        $locuri[] = $loc;
    }
    foreach (array_unique($locuri) as $loc) {
        if (@is_file($loc) && @is_executable($loc)) {
            return $stare = 'da';
        }
    }
    // Negăsit. Cu `open_basedir` pus, „negăsit” înseamnă numai „n-am avut voie
    // să mă uit”: fișierele din afara dosarelor îngăduite par toate lipsă.
    return $stare = trim((string) ini_get('open_basedir')) !== '' ? 'nestiut' : 'nu';
}

/**
 * Starea poștei, pentru pagina de Setări (`GET /settings` → `posta`).
 *
 * @return array{cale: string, direct: string, casuta: string}
 */
function posta_stare(): array
{
    return [
        'cale'   => mail_cale(),
        'direct' => mail_direct_stare(),
        'casuta' => smtp_stare(),
    ];
}

/* ----------------------------------------------------------- mesajul -- */

/**
 * Un antet, cu paza lui: nimic cu salt de linie nu ajunge în capul mesajului.
 *
 * Anteturile se despart prin salt de linie, deci o valoare cu CR ori LF în ea
 * ar adăuga anteturi pe care nu le-a scris nimeni — un „Bcc:” către un străin,
 * de pildă. Paza stă AICI, o singură dată, pentru amândouă drumurile: orice
 * antet al mesajului trece prin funcția asta.
 */
function mail_antet(string $nume, string $valoare): string
{
    if (preg_match('/[\r\n\0]/', $nume . $valoare) === 1) {
        // Fără valoare în mesaj: tocmai ea e cea stricată.
        throw new RuntimeException('Antetul „' . preg_replace('/[^A-Za-z-]/', '', $nume)
            . '” al e-mailului are un salt de linie în el. Mesajul nu a fost trimis.');
    }
    return $nume . ': ' . $valoare;
}

/**
 * Subiectul, codificat și îndoit (RFC 2047).
 *
 * Diacriticele nu au voie brute în anteturi, deci subiectul se scrie în
 * „cuvinte codificate”. Un cuvânt codificat are cel mult 75 de semne, așa că un
 * subiect lung se taie în mai multe, legate prin CRLF și un spațiu. Tăietura
 * se face între LITERE, nu între octeți: un „ă” rupt în două ar ieși ca două
 * semne de întrebare. Saltul de linie din subiectul primit se face spațiu
 * înainte de orice — îndoitura de aici e singura care are voie în el.
 */
function mail_subiect(string $subiect): string
{
    $subiect = trim((string) preg_replace('/[\r\n\t\0]+/', ' ', $subiect));
    $litere  = preg_split('//u', $subiect, -1, PREG_SPLIT_NO_EMPTY);
    if ($litere === false) {
        // Nu e UTF-8 curat: se taie pe octeți, ca măcar să plece.
        $litere = str_split($subiect);
    }
    $cuvinte = [];
    $bucata  = '';
    foreach ($litere as $l) {
        // 45 de octeți fac 60 de semne în base64; cu „=?UTF-8?B?” și „?=”, 72.
        // Primul cuvânt stă pe rând cu „Subject: ”, deci e mai scurt: 36 de
        // octeți, adică 60 de semne, 69 cu numele antetului.
        $loc = $cuvinte === [] ? 36 : 45;
        if ($bucata !== '' && strlen($bucata) + strlen($l) > $loc) {
            $cuvinte[] = '=?UTF-8?B?' . base64_encode($bucata) . '?=';
            $bucata    = '';
        }
        $bucata .= $l;
    }
    $cuvinte[] = '=?UTF-8?B?' . base64_encode($bucata) . '?=';
    return implode("\r\n ", $cuvinte);
}

/**
 * Corpul, gata de drum: rânduri CRLF, „quoted-printable”.
 *
 * DE CE NU „8bit”, CA PÂNĂ ACUM. Un rând de e-mail are voie cel mult 998 de
 * octeți, iar textele noastre nu se îndoaie singure (un link lung, o listă de
 * poziții). „Quoted-printable” taie rândurile la 76 de semne cu o îndoitură pe
 * care orice program de poștă o desface la loc, și trece prin orice server,
 * fie că primește litere pe 8 biți, fie că nu.
 */
function mail_corp(string $text): string
{
    $text = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $text);
    return quoted_printable_encode($text);
}

/**
 * Mesajul, făcut O SINGURĂ DATĂ pentru amândouă drumurile.
 *
 * @return array{de: string, catre: string, subiect: string, antete: list<string>, corp: string}
 */
function mail_mesaj(string $de, string $numeExpeditor, string $catre, string $subiect, string $text): array
{
    $domeniu = (string) substr((string) strrchr($de, '@'), 1);
    return [
        'de'      => $de,
        'catre'   => $catre,
        'subiect' => mail_subiect($subiect),
        'antete'  => [
            mail_antet('From', $numeExpeditor . ' <' . $de . '>'),
            mail_antet('Date', date('D, d M Y H:i:s O')),
            mail_antet('Message-ID', '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domeniu . '>'),
            mail_antet('MIME-Version', '1.0'),
            mail_antet('Content-Type', 'text/plain; charset=UTF-8'),
            mail_antet('Content-Transfer-Encoding', 'quoted-printable'),
            mail_antet('X-Mailer', 'golden-pastry'),
        ],
        'corp'    => mail_corp($text),
    ];
}

/* ----------------------------------------------------- drumul direct -- */

/**
 * Dă mesajul programului de poștă al serverului (`mail()`).
 *
 * `mail()` întoarce „adevărat” când programul a LUAT mesajul, nu când mesajul a
 * ajuns: ce se întâmplă mai departe nu mai află nimeni de aici. De aceea
 * butonul de test și Jurnalul spun „predat”, nu „trimis”.
 */
function mail_direct_trimite(array $mesaj): void
{
    if (mail_direct_stare() === 'nu') {
        throw new RuntimeException('Pe server nu e instalat niciun program de poștă, deci '
            . 'e-mailurile nu au cum pleca direct de pe el. Completează căsuța (SMTP) din '
            . 'Setări → Notificări → E-mail.');
    }
    // „-f” spune programului de poștă cine e expeditorul de pe plic. Fără el,
    // plicul poartă contul sub care rulează PHP („www-data@…”), iar furnizorii
    // mari aruncă un mesaj al cărui plic nu se potrivește cu antetul „From”.
    // Parametrul ajunge pe o linie de comandă, deci se pune numai pentru o
    // adresă din litere cuminți; pentru oricare alta se trimite fără el.
    // „\z”, nu „$”: „$” se potrivește și ÎNAINTEA unui salt de linie de la
    // coadă, deci „a@b.ro\n” ar trece drept cuminte.
    $plic = preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\z/', $mesaj['de']) === 1
        ? '-f' . $mesaj['de']
        : '';
    $ok = @mail($mesaj['catre'], $mesaj['subiect'], $mesaj['corp'], implode("\r\n", $mesaj['antete']), $plic);
    if (!$ok) {
        throw new RuntimeException('Serverul nu a putut preda mesajul programului lui de poștă. '
            . 'Completează căsuța (SMTP) din Setări → Notificări → E-mail, ori cere-i celui '
            . 'care ține serverul să se uite la programul de poștă.');
    }
}

/* ------------------------------------------------------------ căsuța -- */

/**
 * Scoate parola căsuței dintr-un text, în toate formele în care a călătorit.
 *
 * Răspunsul unui server de e-mail ajunge în mesajul de eroare, iar de acolo în
 * `notifications`, pe ecranul Setărilor și în jurnalul de erori. Un server
 * cuminte nu repetă ce i s-a trimis — dar nu noi îl alegem. Deci din orice
 * text venit de la el se scot: parola, forma ei base64 (AUTH LOGIN) și
 * pachetul base64 cu utilizator și parolă (AUTH PLAIN). Textul iese pe un
 * singur rând, tăiat la 300 de semne, fără octeți care n-ar intra în bază.
 */
function smtp_fara_secret(string $text, ?string $utilizator = null, ?string $parola = null): string
{
    if ($parola === null) {
        $d          = smtp_date();
        $parola     = $d['pass'];
        $utilizator = $d['user'];
    }
    if ($parola !== '') {
        $ascunse = [
            base64_encode("\0" . $utilizator . "\0" . $parola),
            base64_encode($utilizator . "\0" . $utilizator . "\0" . $parola),
            base64_encode($parola),
            $parola,
        ];
        $text = str_replace($ascunse, '[ascuns]', $text);
    }
    $text = trim((string) preg_replace('/\s+/', ' ', $text));
    if (preg_match('//u', $text) !== 1) {
        // Octeți care nu sunt UTF-8: rândul din bază i-ar refuza cu totul.
        $text = (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
    }
    $text = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text);
    return function_exists('mb_substr') ? mb_substr($text, 0, 300) : substr($text, 0, 300);
}

/**
 * Certificatul de probă din config.php (`smtp_ca_file`), dacă are voie.
 *
 * SINGURA PORTIȚĂ din verificarea certificatului, și e îngustă dinadins:
 *
 *   · cheia stă în config.php, nu în Setări — n-o poate pune nimeni dintr-un
 *     ecran (aceeași judecată ca la `telegram_api_base`);
 *   · e ascultată NUMAI când gazda căsuței e calculatorul însuși. Pentru orice
 *     gazdă adevărată întoarce `null`, adică „verifică după certificatele
 *     sistemului”, orice ar scrie în cheie;
 *   · nu oprește verificarea: adaugă UN certificat de încredere. Numele se
 *     verifică mai departe.
 *
 * Există pentru probe: serverul de probă (.claude/ciot-smtp.mjs) are un
 * certificat făcut pe loc, pe care sistemul n-are de unde să-l cunoască.
 */
function smtp_ca_de_proba(string $gazda): ?string
{
    $cale = function_exists('config') ? config('smtp_ca_file') : null;
    if (!is_string($cale) || trim($cale) === '') {
        return null;
    }
    if (!in_array($gazda, ['127.0.0.1', 'localhost'], true)) {
        return null;
    }
    if (!is_file($cale) || !is_readable($cale)) {
        // Fără calea în mesaj: config.php nu se povestește pe ecrane.
        throw new RuntimeException('Fișierul de certificat pentru probe din config.php '
            . '(smtp_ca_file) nu se poate citi. Pe un server adevărat cheia rămâne goală.');
    }
    return $cale;
}

/**
 * Felul criptării, hotărât: „ssl” (criptat de la primul octet) ori „starttls”.
 *
 * „auto” se ia după port: 465 e portul criptat de la început; pe oricare altul
 * (587, 25, 2525) legătura pornește necriptată și se cere STARTTLS. Necriptat
 * până la capăt nu există ca alegere.
 */
function smtp_felul_legaturii(string $fel, int $port): string
{
    if ($fel === 'ssl' || $fel === 'starttls') {
        return $fel;
    }
    return $port === 465 ? 'ssl' : 'starttls';
}

/** „10 secunde”, „25 de secunde”. */
function smtp_secunde(int $n): string
{
    return $n . ' ' . ro_de($n) . 'secunde';
}

/**
 * Scrie pe legătură, cu ochii pe ceasul întregii convorbiri.
 *
 * @param resource $fp
 */
function smtp_scrie($fp, string $date, float $capat, string $gazda, string $pas): void
{
    $scris = 0;
    $lung  = strlen($date);
    while ($scris < $lung) {
        $ramas = $capat - microtime(true);
        if ($ramas <= 0) {
            throw new RuntimeException('Convorbirea cu serverul de e-mail „' . $gazda . '” a trecut de '
                . smtp_secunde(GP_SMTP_TOTAL) . ' și am oprit-o (' . $pas . '). Mesajul nu a plecat.');
        }
        stream_set_timeout($fp, (int) max(1, min(GP_SMTP_RASPUNS, ceil($ramas))));
        $n = @fwrite($fp, substr($date, $scris));
        if ($n === false || $n === 0) {
            throw new RuntimeException('Serverul de e-mail „' . $gazda . '” a închis legătura ('
                . $pas . '). Mesajul nu a plecat.');
        }
        $scris += $n;
    }
}

/**
 * Ce a sosit de la server și n-a fost încă citit drept răspuns.
 *
 * DE CE E AL NOSTRU, ȘI NU AL LUI PHP. Cât s-a citit cu `fgets()`, octeții
 * sosiți peste rândul cerut rămâneau într-un tampon al lui PHP pe care codul
 * nu-l vedea: nu se putea nici măsura (cât e de lung rândul început?), nici
 * goli (ce a venit lipit de „220” la STARTTLS?). Aici stau la vedere, câte un
 * șir pentru fiecare legătură. Cu `$gata` legătura își uită tamponul.
 *
 * @param resource $fp
 */
function &smtp_tampon($fp, bool $gata = false): string
{
    static $tampoane = [];
    $nr = get_resource_id($fp);
    if ($gata) {
        unset($tampoane[$nr]);
        $gol = '';
        return $gol;
    }
    if (!isset($tampoane[$nr])) {
        $tampoane[$nr] = '';
    }
    return $tampoane[$nr];
}

/**
 * Citește un răspuns întreg (poate avea mai multe rânduri) și îi întoarce codul.
 *
 * Două ceasuri: fiecare răspuns are `GP_SMTP_RASPUNS` secunde, iar toată
 * convorbirea `GP_SMTP_TOTAL`. Al doilea e pentru serverul care nu tace, ci
 * răspunde de fiecare dată în ultima clipă: fără el, cererea omului ar sta
 * agățată un minut întreg.
 *
 * CEASUL E AL RĂSPUNSULUI, NU AL BUCĂȚII SOSITE. Termenul (`$pana`) se pune o
 * singură dată, la începutul răspunsului, și se citește cu `fread()`, care se
 * întoarce cu ce a sosit. `fgets()`, folosit întâi, aștepta un rând întreg și
 * își lua ceasul de la capăt la fiecare octet: un server care picura câte o
 * literă la nouă secunde ținea cererea omului agățată 54 de secunde, peste
 * amândouă ceasurile.
 *
 * DOUĂ MARGINI, ca un răspuns stricat să nu fie citit drept unul bun: un rând
 * mai lung de `GP_SMTP_RAND` octeți și un răspuns cu mai mult de
 * `GP_SMTP_RANDURI` rânduri opresc convorbirea, fiecare cu vorba ei. Fără ele,
 * rândul lung era tăiat în bucăți luate drept rânduri, iar al 65-lea rând
 * rămânea necitit și trecea drept răspunsul comenzii următoare.
 *
 * @param resource $fp
 * @return array{0: int, 1: string} codul și răspunsul întreg
 */
function smtp_citeste($fp, float $capat, string $gazda, string $pas): array
{
    $tampon  = &smtp_tampon($fp);
    $pana    = min($capat, microtime(true) + GP_SMTP_RASPUNS);
    $randuri = [];
    while (true) {
        $sfarsit = strpos($tampon, "\n");
        if (($sfarsit === false ? strlen($tampon) : $sfarsit) > GP_SMTP_RAND) {
            throw new RuntimeException('Serverul de e-mail „' . $gazda . '” a trimis un rând mai lung decât '
                . 'poate avea un răspuns (' . $pas . '): la portul acela nu pare să fie un server de e-mail. '
                . 'Mesajul nu a plecat. Verifică gazda și portul din Setări → Notificări → E-mail.');
        }
        if ($sfarsit === false) {
            // Rândul nu e întreg: se mai așteaptă, cât a rămas din termenul răspunsului.
            $acum  = microtime(true);
            $ramas = $pana - $acum;
            if ($ramas < 0.01) {
                if ($capat - $acum <= 0.05) {
                    throw new RuntimeException('Convorbirea cu serverul de e-mail „' . $gazda . '” a trecut de '
                        . smtp_secunde(GP_SMTP_TOTAL) . ' și am oprit-o (' . $pas . '). Mesajul nu a plecat.');
                }
                throw new RuntimeException('Serverul de e-mail „' . $gazda . '” a tăcut: n-a răspuns în '
                    . smtp_secunde(GP_SMTP_RASPUNS) . ' (' . $pas . '). Mesajul nu a plecat.');
            }
            // Niciodată zero: pe o legătură criptată, „zero secunde” înseamnă
            // pentru PHP „fără termen” (de aceea pragul de o sutime de mai sus).
            stream_set_timeout($fp, (int) floor($ramas), (int) (($ramas - floor($ramas)) * 1000000));
            $bucata = @fread($fp, 8192);
            if ($bucata === false || $bucata === '') {
                $meta = stream_get_meta_data($fp);
                if (empty($meta['timed_out']) && !empty($meta['eof'])) {
                    throw new RuntimeException('Serverul de e-mail „' . $gazda . '” a închis legătura ('
                        . $pas . '). Mesajul nu a plecat.');
                }
                // N-a sosit nimic în timpul dat: ceasul de sus hotărăște.
                continue;
            }
            $tampon .= $bucata;
            continue;
        }
        $rand      = rtrim(substr($tampon, 0, $sfarsit), "\r");
        $tampon    = (string) substr($tampon, $sfarsit + 1);
        $randuri[] = $rand;
        // „250-…” mai are rânduri după el; „250 …” (ori „250” gol) e ultimul.
        if (strlen($rand) < 4 || $rand[3] !== '-') {
            break;
        }
        if (count($randuri) >= GP_SMTP_RANDURI) {
            throw new RuntimeException('Serverul de e-mail „' . $gazda . '” a trimis un răspuns fără capăt ('
                . $pas . '): la portul acela nu pare să fie un server de e-mail. Mesajul nu a plecat. '
                . 'Verifică gazda și portul din Setări → Notificări → E-mail.');
        }
    }
    // Rândurile rămân despărțite: din ele se citește ce oferă serverul, rând cu
    // rând. Într-un mesaj de eroare ajung pe un singur rând (`smtp_fara_secret()`).
    return [(int) substr((string) end($randuri), 0, 3), implode("\n", $randuri)];
}

/**
 * O comandă și răspunsul ei.
 *
 * @param resource $fp
 * @return array{0: int, 1: string}
 */
function smtp_cere($fp, string $comanda, float $capat, string $gazda, string $pas): array
{
    smtp_scrie($fp, $comanda . "\r\n", $capat, $gazda, $pas);
    return smtp_citeste($fp, $capat, $gazda, $pas);
}

/**
 * Pornește criptarea pe o legătură deschisă și verifică certificatul.
 *
 * Aceeași funcție pentru amândouă felurile: la „ssl” e chemată imediat după
 * legare, la „starttls” după ce serverul a zis „220” la comandă. Avertismentul
 * lui PHP se prinde ca să se poată spune DE CE n-a pornit: un certificat care
 * nu se potrivește e altă greșeală (și altă îndreptare) decât un port greșit.
 *
 * NIMIC DIN CE A SOSIT NECRIPTAT NU SE CITEȘTE DUPĂ CRIPTARE. Cine stă pe drum
 * nu poate scrie înăuntrul legăturii criptate, dar poate lipi rânduri DUPĂ
 * „220”-ul de la STARTTLS, în aceeași bucată: ele rămân în tampon și ar fi
 * citite după criptare drept răspunsurile serverului adevărat („250 Ok”,
 * „235 autentificat”, „250 mesaj luat”) — panoul ar spune că mesajul a plecat,
 * iar el n-ar fi plecat nicăieri. De aceea, înainte să pornească criptarea,
 * tamponul nostru și al lui PHP trebuie să fie goale; altfel convorbirea se
 * oprește aici, înaintea parolei.
 *
 * @param resource $fp
 */
function smtp_cripteaza($fp, string $gazda, int $port): void
{
    if (smtp_tampon($fp) !== '' || (int) (stream_get_meta_data($fp)['unread_bytes'] ?? 0) > 0) {
        throw new RuntimeException('Serverul de e-mail „' . $gazda . '” a trimis ceva pe lângă răspunsul lui, '
            . 'înainte să pornească criptarea: pe drum poate fi altcineva decât el. Nu i-am trimis nici '
            . 'parola, nici mesajul.');
    }
    $avertisment = '';
    set_error_handler(static function (int $nr, string $text) use (&$avertisment): bool {
        $avertisment .= $text . ' ';
        return true;
    });
    try {
        $ok = stream_socket_enable_crypto(
            $fp,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );
    } finally {
        restore_error_handler();
    }
    if ($ok === true) {
        return;
    }
    if (preg_match('/certificate|verify failed|did not match|peer name|CN=/i', $avertisment) === 1) {
        throw new RuntimeException('Serverul de e-mail „' . $gazda . '” nu și-a putut dovedi identitatea: '
            . 'certificatul lui nu e de încredere ori e făcut pentru alt nume. Nu i-am trimis nici '
            . 'parola, nici mesajul. Scrie gazda exact cum o dă furnizorul căsuței (de pildă '
            . 'mail.domeniul-tau.ro).');
    }
    throw new RuntimeException('Legătura criptată cu serverul de e-mail „' . $gazda . '”, portul ' . $port
        . ', nu a pornit. Încearcă celălalt fel de criptare din Setări → Notificări → E-mail: '
        . 'portul 465 merge cu „SSL/TLS”, portul 587 cu „STARTTLS”.');
}

/**
 * Autentificarea: utilizatorul și parola căsuței.
 *
 * PRIMUL RÂND E PAZA: pe o legătură necriptată nu pleacă nicio parolă, orice
 * ar oferi serverul și oricine ar chema funcția. Comanda `AUTH` nu se scrie
 * nicăieri în afara funcției ăsteia.
 *
 * @param resource $fp
 */
function smtp_autentifica($fp, bool $criptat, string $ofera, array $d, float $capat): void
{
    if ($criptat !== true) {
        throw new LogicException('Parola căsuței nu se trimite pe o legătură necriptată.');
    }
    $gazda = $d['host'];
    // „250-AUTH PLAIN LOGIN” ori, la serverele vechi, „250-AUTH=PLAIN LOGIN”.
    $mecanisme = preg_match('/^250[- ]AUTH[ =]([^\n]+)$/mi', $ofera, $m) === 1
        ? preg_split('/[\s=]+/', strtoupper(trim($m[1])), -1, PREG_SPLIT_NO_EMPTY)
        : [];

    if (in_array('PLAIN', $mecanisme, true)) {
        [$cod, $raspuns] = smtp_cere(
            $fp,
            'AUTH PLAIN ' . base64_encode("\0" . $d['user'] . "\0" . $d['pass']),
            $capat,
            $gazda,
            'la autentificare'
        );
    } elseif (in_array('LOGIN', $mecanisme, true)) {
        [$cod, $raspuns] = smtp_cere($fp, 'AUTH LOGIN', $capat, $gazda, 'la autentificare');
        if ($cod === 334) {
            [$cod, $raspuns] = smtp_cere($fp, base64_encode($d['user']), $capat, $gazda, 'la autentificare');
        }
        if ($cod === 334) {
            [$cod, $raspuns] = smtp_cere($fp, base64_encode($d['pass']), $capat, $gazda, 'la autentificare');
        }
    } else {
        throw new RuntimeException('Serverul de e-mail „' . $gazda . '” nu primește, pe portul ' . $d['port']
            . ', autentificarea cu utilizator și parolă. Verifică portul din Setări → Notificări → E-mail.');
    }
    if ($cod !== 235) {
        throw new RuntimeException('Serverul de e-mail a refuzat utilizatorul ori parola căsuței. Scrie '
            . 'parola din nou în Setări → Notificări → E-mail și apasă iar butonul de test. Răspunsul lui: '
            . smtp_fara_secret($raspuns, $d['user'], $d['pass']));
    }
}

/**
 * Adresa poate sta pe plic (între „<” și „>”)?
 *
 * `FILTER_VALIDATE_EMAIL` primește și adrese cu ghilimele și spații în ele,
 * bune pe hârtie, dar care pe plicul SMTP ar putea închide paranteza mai
 * devreme. Aici trec numai cele dintr-o bucată.
 *
 * E SINGURA PAZĂ de salt de linie de pe plic: `MAIL FROM` și `RCPT TO` se
 * scriu cu adresa lipită între paranteze, iar filtrul lui PHP lasă să treacă
 * o adresă ca „"a\<LF>RSET"@client.ro” — al doilea rând al ei ar fi citit de
 * server drept comandă. Tiparul se încheie cu „\z”, nu cu „$”: „$” se
 * potrivește și înaintea unui salt de linie de la coadă.
 */
function smtp_adresa_de_plic(string $adresa): bool
{
    return preg_match('/^[^\s<>"\x00-\x1F\x7F]+@[A-Za-z0-9.-]+\z/', $adresa) === 1;
}

/**
 * Trimite mesajul prin căsuța din Setări. Întoarce răspunsul de primire al
 * serverului („250 2.0.0 Ok: queued as …”), pe un rând.
 *
 * Fiecare treaptă la care se poate opri convorbirea are vorba ei, în română,
 * cu ce e de făcut: legare, certificat, criptare lipsă, autentificare,
 * expeditor, destinatar, conținut, tăcere.
 */
function smtp_trimite(array $mesaj): string
{
    $d     = smtp_date();
    $gazda = $d['host'];
    $port  = $d['port'];

    if ($gazda === '' || preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/', $gazda) !== 1) {
        throw new RuntimeException('Gazda căsuței de e-mail din Setări nu e un nume de server. '
            . 'Scrie-o cum o dă furnizorul (de pildă mail.domeniul-tau.ro).');
    }
    if ($d['user'] === '' || $d['pass'] === '') {
        throw new RuntimeException('Căsuța de e-mail din Setări e completată pe jumătate: lipsește '
            . ($d['user'] === '' ? 'utilizatorul' : 'parola') . '. Completeaz-o în Setări → Notificări → '
            . 'E-mail, ori golește gazda ca e-mailurile să plece direct de pe server.');
    }
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('Serverul nu are extensia OpenSSL, deci nu poate cripta legătura cu '
            . 'căsuța de e-mail. Mesajul nu a plecat.');
    }
    if (!smtp_adresa_de_plic($mesaj['de']) || !smtp_adresa_de_plic($mesaj['catre'])) {
        throw new RuntimeException('Adresa ' . (smtp_adresa_de_plic($mesaj['de']) ? 'destinatarului' : 'expeditoare')
            . ' are semne care nu pot călători prin căsuța de e-mail. Mesajul nu a plecat.');
    }

    $fel   = smtp_felul_legaturii($d['security'], $port);
    $capat = microtime(true) + GP_SMTP_TOTAL;

    // Verificarea certificatului: pornită, cu nume cu tot. Nu există nicio
    // setare care s-o oprească — numai certificatul de probă, pentru gazda locală.
    $ssl = [
        'verify_peer'         => true,
        'verify_peer_name'    => true,
        'allow_self_signed'   => false,
        'peer_name'           => $gazda,
        'SNI_enabled'         => true,
        'disable_compression' => true,
    ];
    $deProba = smtp_ca_de_proba($gazda);
    if ($deProba !== null) {
        $ssl['cafile'] = $deProba;
    }

    $nr   = 0;
    $text = '';
    $fp   = @stream_socket_client(
        'tcp://' . $gazda . ':' . $port,
        $nr,
        $text,
        GP_SMTP_LEGARE,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => $ssl])
    );
    if (!is_resource($fp)) {
        throw new RuntimeException('Nu m-am putut lega la serverul de e-mail „' . $gazda . '”, portul ' . $port
            . ' (' . (smtp_fara_secret((string) $text, $d['user'], $d['pass']) ?: 'n-a răspuns în ' . smtp_secunde(GP_SMTP_LEGARE))
            . '). Verifică gazda și portul din Setări → Notificări → E-mail.');
    }

    try {
        stream_set_timeout($fp, GP_SMTP_RASPUNS);
        $criptat = false;
        if ($fel === 'ssl') {
            smtp_cripteaza($fp, $gazda, $port);
            $criptat = true;
        }

        [$cod, $raspuns] = smtp_citeste($fp, $capat, $gazda, 'la salut');
        if ($cod !== 220) {
            throw new RuntimeException('Serverul de e-mail „' . $gazda . '” nu primește legături acum. Răspunsul lui: '
                . smtp_fara_secret($raspuns, $d['user'], $d['pass']));
        }

        // Numele cu care ne prezentăm: al calculatorului, din litere cuminți.
        $eu = (string) preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname());
        $eu = $eu !== '' ? $eu : 'localhost';

        [$cod, $ofera] = smtp_cere($fp, 'EHLO ' . $eu, $capat, $gazda, 'la prezentare');
        if ($cod !== 250) {
            throw new RuntimeException('Serverul de e-mail „' . $gazda . '” n-a primit prezentarea. Răspunsul lui: '
                . smtp_fara_secret($ofera, $d['user'], $d['pass']));
        }

        if (!$criptat) {
            if (preg_match('/^250[- ]STARTTLS\s*$/mi', $ofera) !== 1) {
                throw new RuntimeException('Serverul de e-mail „' . $gazda . '” nu oferă criptare pe portul ' . $port
                    . ' (STARTTLS), iar parola căsuței nu se trimite pe o legătură necriptată. '
                    . 'Încearcă portul 465 ori 587.');
            }
            [$cod, $raspuns] = smtp_cere($fp, 'STARTTLS', $capat, $gazda, 'la pornirea criptării');
            if ($cod !== 220) {
                throw new RuntimeException('Serverul de e-mail „' . $gazda . '” a refuzat pornirea criptării. Răspunsul lui: '
                    . smtp_fara_secret($raspuns, $d['user'], $d['pass']));
            }
            smtp_cripteaza($fp, $gazda, $port);
            $criptat = true;
            // După criptare convorbirea se ia de la capăt: ce a oferit serverul
            // înainte nu mai e de crezut (putea fi scris de oricine pe drum).
            [$cod, $ofera] = smtp_cere($fp, 'EHLO ' . $eu, $capat, $gazda, 'la prezentare');
            if ($cod !== 250) {
                throw new RuntimeException('Serverul de e-mail „' . $gazda . '” n-a primit prezentarea. Răspunsul lui: '
                    . smtp_fara_secret($ofera, $d['user'], $d['pass']));
            }
        }

        smtp_autentifica($fp, $criptat, $ofera, $d, $capat);

        [$cod, $raspuns] = smtp_cere($fp, 'MAIL FROM:<' . $mesaj['de'] . '>', $capat, $gazda, 'la expeditor');
        if ($cod !== 250) {
            throw new RuntimeException('Serverul de e-mail nu primește mesaje de la adresa expeditoare „'
                . $mesaj['de'] . '”. De obicei expeditorul trebuie să fie chiar adresa căsuței: schimb-o în '
                . 'Setări → Notificări → E-mail. Răspunsul lui: ' . smtp_fara_secret($raspuns, $d['user'], $d['pass']));
        }
        [$cod, $raspuns] = smtp_cere($fp, 'RCPT TO:<' . $mesaj['catre'] . '>', $capat, $gazda, 'la destinatar');
        if ($cod !== 250 && $cod !== 251) {
            throw new RuntimeException('Serverul de e-mail a refuzat adresa destinatarului. Răspunsul lui: '
                . smtp_fara_secret($raspuns, $d['user'], $d['pass']));
        }
        [$cod, $raspuns] = smtp_cere($fp, 'DATA', $capat, $gazda, 'la mesaj');
        if ($cod !== 354) {
            throw new RuntimeException('Serverul de e-mail a primit legătura, dar a refuzat mesajul. Răspunsul lui: '
                . smtp_fara_secret($raspuns, $d['user'], $d['pass']));
        }

        $intreg = implode("\r\n", array_merge(
            $mesaj['antete'],
            [mail_antet('To', $mesaj['catre']), 'Subject: ' . $mesaj['subiect']]
        )) . "\r\n\r\n" . $mesaj['corp'];
        // Un rând care începe cu punct și-l dublează: singur pe rând, punctul
        // înseamnă „aici se termină mesajul” (RFC 5321, 4.5.2).
        $intreg = (string) preg_replace('/^\./m', '..', $intreg);
        if (!str_ends_with($intreg, "\r\n")) {
            $intreg .= "\r\n";
        }
        smtp_scrie($fp, $intreg . ".\r\n", $capat, $gazda, 'la mesaj');
        [$cod, $raspuns] = smtp_citeste($fp, $capat, $gazda, 'după mesaj');
        if ($cod !== 250) {
            throw new RuntimeException('Serverul de e-mail a primit legătura, dar a refuzat mesajul. Răspunsul lui: '
                . smtp_fara_secret($raspuns, $d['user'], $d['pass']));
        }
        $primire = smtp_fara_secret($raspuns, $d['user'], $d['pass']);

        // Mesajul e luat. Rămasul-bun e de politețe: dacă nu mai răspunde, nu-i nimic.
        try {
            smtp_scrie($fp, "QUIT\r\n", $capat, $gazda, 'la rămas-bun');
        } catch (Throwable $e) {
            // deja predat
        }
        return $primire;
    } finally {
        smtp_tampon($fp, true);
        @fclose($fp);
    }
}
