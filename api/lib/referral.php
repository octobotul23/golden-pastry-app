<?php
/**
 * Recomandările (referral): codul fiecărui cont și legătura „cine pe cine a adus".
 *
 * Codul se derivă din PRENUME — primul cuvânt din `users.name` — curățat de
 * diacritice și de tot ce nu e literă sau cifră: „Ana Maria" → „ana",
 * „Ștefan Popescu" → „stefan". Motivul e practic: codul ajunge într-un link pe
 * care omul îl dictează la telefon și îl scrie de mână („goldenpastry.ro/?ref=ana").
 * Un șir aleator ar fi mai sigur, dar nimeni nu-l ține minte.
 *
 * Prenumele nu sunt unice, deci al doilea „Ion" primește „ion1", al treilea
 * „ion2" și așa mai departe. Unicitatea e păzită de indexul `uq_users_referral_code`
 * din sql/007, nu doar de numărătoarea de aici: între SELECT și UPDATE încap
 * două înscrieri paralele, iar amândouă ar calcula același „ion".
 *
 * TOATE funcțiile de aici se opresc singure, fără eroare, dacă migrarea 007 nu
 * a fost încă rulată — vezi referral_supported(). Fișierele se urcă pe server
 * înaintea importului din phpMyAdmin, iar în intervalul acela aplicația trebuie
 * să meargă exact ca înainte, nu să cadă cu „Eroare internă".
 */

declare(strict_types=1);

/** Câte încercări de cod (prenume, prenume1, prenume2…) înainte de a renunța. */
const GP_REFERRAL_MAX_TRIES = 50;

/**
 * Există coloanele de referral în baza de date?
 *
 * Răspunsul se ține minte pe durata cererii: e o singură interogare, dar ar fi
 * chemată din mai multe locuri pe același request.
 */
function referral_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $n = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND COLUMN_NAME IN ('referral_code','referred_by','referred_at')"
        );
        return $ok = ($n === 3);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica suportul de referral: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Prenumele, curățat, gata de pus într-un link.
 *
 * Diacriticele se traduc cu o hartă explicită, nu cu iconv//TRANSLIT: acela
 * depinde de locale-ul serverului și, pe o găzduire cPanel cu locale „C",
 * întoarce „?" în loc de litera de bază. Harta acoperă româna (ă â î ș ț, în
 * ambele variante Unicode — cu virgulă și cu sedilă, fiindcă tastaturile și
 * fișierele vechi le amestecă) plus vocalele accentuate care apar în nume
 * străine trecute prin registrul unei patiserii.
 *
 * @return string doar [a-z0-9], sau '' dacă nu rămâne nimic folosibil
 */
function referral_slug(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    // Doar primul cuvânt: prenumele. „Ana Maria Ionescu" → „Ana".
    $parts = preg_split('/\s+/u', $text) ?: [];
    $first = (string) ($parts[0] ?? '');
    if ($first === '') {
        return '';
    }

    static $harta = [
        'ă' => 'a', 'â' => 'a', 'á' => 'a', 'à' => 'a', 'ä' => 'a', 'å' => 'a', 'ã' => 'a',
        'î' => 'i', 'í' => 'i', 'ì' => 'i', 'ï' => 'i',
        'ș' => 's', 'ş' => 's', 'š' => 's',          // virgulă dedesubt ȘI sedilă
        'ț' => 't', 'ţ' => 't', 'ť' => 't',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ů' => 'u',
        'ç' => 'c', 'č' => 'c', 'ć' => 'c',
        'ñ' => 'n', 'ń' => 'n',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
        'ý' => 'y', 'ÿ' => 'y',
        'ł' => 'l', 'đ' => 'd', 'ğ' => 'g',
        'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
    ];

    // Minusculele întâi, ca harta să aibă nevoie doar de varianta mică a
    // fiecărei litere. mb_strtolower știe de diacritice; strtolower nu.
    $first = function_exists('mb_strtolower') ? mb_strtolower($first, 'UTF-8') : strtolower($first);
    $first = strtr($first, $harta);

    // Ce a mai rămas necurățat (alfabete pe care harta nu le acoperă) pică aici.
    $curat = preg_replace('/[^a-z0-9]/', '', $first);

    return (string) ($curat ?? '');
}

/**
 * Un cod liber pornind de la prenume: „ana", apoi „ana1", „ana2"…
 *
 * Întoarce null dacă prenumele nu dă nimic folosibil ȘI nici rezerva nu ajută —
 * cazul e tratat de apelant, care preferă un cont fără cod unui cont neînscris.
 */
function referral_code_candidate(string $name, string $username = ''): ?string
{
    $baza = referral_slug($name);
    if ($baza === '') {
        // Prenumele n-a dat nimic (nume scris doar cu semne, alfabet neacoperit).
        // Numele de utilizator e deja /^[a-z0-9._-]{3,64}$/, deci curățat de el
        // rămâne aproape sigur ceva.
        $baza = referral_slug($username);
    }
    if ($baza === '') {
        return null;
    }
    // Coloana e VARCHAR(64); lăsăm loc pentru cifrele de dezambiguizare.
    return substr($baza, 0, 58);
}

/**
 * Scrie codul de recomandare pe un cont care încă n-are unul.
 *
 * Se cheamă DUPĂ ce contul a fost creat, niciodată în același INSERT. Motivul:
 * o ciocnire de coduri („încă un Ion") nu are voie să dea peste cap înscrierea.
 * Aici se poate reîncerca liniștit, iar dacă tot nu iese, contul rămâne fără cod
 * și îl poate primi mai târziu — pierdem o recomandare, nu un client.
 *
 * Nu atinge niciodată un cont care are deja cod (`referral_code IS NULL` în
 * WHERE): rularea peste conturile vechi e sigură.
 *
 * @return string|null codul scris, sau null dacă nu s-a putut
 */
function referral_assign(int $userId, string $name, string $username = ''): ?string
{
    if (!referral_supported() || $userId <= 0) {
        return null;
    }
    $baza = referral_code_candidate($name, $username);
    if ($baza === null) {
        return null;
    }

    for ($i = 0; $i < GP_REFERRAL_MAX_TRIES; $i++) {
        $cod = $i === 0 ? $baza : $baza . $i;
        try {
            // Condiția pe referral_code oprește suprascrierea unui cod deja pus:
            // funcția poate fi chemată din nou pe același cont fără pagubă.
            $st = q(
                'UPDATE users SET referral_code = ?
                  WHERE id = ? AND referral_code IS NULL',
                [$cod, $userId]
            );
            if ($st->rowCount() > 0) {
                return $cod;
            }
            // Zero rânduri: contul avea deja un cod. Îl întoarcem pe acela.
            $are = scalar('SELECT referral_code FROM users WHERE id = ?', [$userId]);
            return $are !== null && $are !== '' ? (string) $are : null;
        } catch (PDOException $e) {
            // 1062 = codul e luat de altcineva. Trecem la „ana1", „ana2"…
            // Orice altceva e o defecțiune adevărată și trebuie să rămână una.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }

    error_log('[gp] nu am găsit un cod de recomandare liber pentru contul ' . $userId
        . ' (bază: „' . $baza . '"); contul rămâne fără cod.');
    return null;
}

/**
 * Contul căruia îi aparține un cod de recomandare.
 *
 * Numai conturi ACTIVE și non-sistem: un cod de pe un cont dezactivat nu mai
 * premiază pe nimeni, iar contul comun „panou" al tabletelor n-are ce recomanda.
 *
 * Codul venit din URL e curățat cu aceleași reguli ca la generare, deci
 * „?ref=Ana", „?ref=ANA" și „?ref=ana" duc toate la același cont.
 *
 * @return array{id:int,name:string}|null
 */
function referral_owner(string $cod): ?array
{
    if (!referral_supported()) {
        return null;
    }
    // Aici NU se ia doar primul cuvânt — codul e un singur cuvânt oricum — dar
    // curățarea e aceeași, ca o majusculă sau un spațiu lipit din copy-paste să
    // nu strice recomandarea.
    $cod = referral_slug($cod);
    if ($cod === '') {
        return null;
    }
    $u = one(
        'SELECT id, name FROM users
          WHERE referral_code = ? AND active = 1 AND is_system = 0
          LIMIT 1',
        [$cod]
    );
    return $u ? ['id' => (int) $u['id'], 'name' => (string) $u['name']] : null;
}

/**
 * Leagă un cont nou de cel care l-a adus.
 *
 * Regula de aur: un cod greșit NU oprește nimic. Cine se înscrie printr-un link
 * vechi, tastat greșit sau de pe un cont între timp dezactivat, primește cont
 * ca oricine altcineva — doar fără recomandant. Refuzul l-ar costa pe patron un
 * client pentru un link stricat.
 *
 * Legătura se scrie o singură dată (`referred_by IS NULL` în WHERE): un cont nu
 * își schimbă recomandantul mai târziu. Auto-recomandarea e oprită explicit.
 *
 * @return array{id:int,name:string}|null recomandantul, dacă s-a legat
 */
function referral_link_new_user(int $userId, string $cod): ?array
{
    if (!referral_supported() || $userId <= 0) {
        return null;
    }
    $cod = trim($cod);
    if ($cod === '') {
        return null;
    }

    try {
        $owner = referral_owner($cod);
        if (!$owner || $owner['id'] === $userId) {
            return null;
        }
        $st = q(
            'UPDATE users SET referred_by = ?, referred_at = UTC_TIMESTAMP()
              WHERE id = ? AND referred_by IS NULL',
            [$owner['id'], $userId]
        );
        return $st->rowCount() > 0 ? $owner : null;
    } catch (Throwable $e) {
        // Contul există deja și e bun. O recomandare nescrisă e o pierdere de
        // marketing, nu un motiv să cadă înscrierea.
        error_log('[gp] recomandare nelegată pentru contul ' . $userId . ': ' . $e->getMessage());
        return null;
    }
}

/** Codul de recomandare al unui cont, sau null. Sigur pe o bază nemigrată. */
function referral_code_of(int $userId): ?string
{
    if (!referral_supported() || $userId <= 0) {
        return null;
    }
    $v = scalar('SELECT referral_code FROM users WHERE id = ?', [$userId]);
    return $v !== null && $v !== '' ? (string) $v : null;
}

/**
 * Adresa publică a magazinului, pentru linkul de invitație.
 *
 * Din `site_url` dacă e scris în config.php; altfel prima origine din lista
 * albă de CORS, care în producție este chiar https://goldenpastry.ro.
 */
function referral_site_url(): string
{
    $fix = trim((string) config('site_url', ''));
    if ($fix !== '') {
        return rtrim($fix, '/');
    }
    $allowed = (array) config('allowed_origins', []);
    return rtrim((string) ($allowed[0] ?? ''), '/');
}

/** Linkul de invitație gata de trimis, sau '' dacă nu există cod. */
function referral_link(?string $cod): string
{
    $cod  = trim((string) $cod);
    $site = referral_site_url();
    if ($cod === '' || $site === '') {
        return '';
    }
    return $site . '/?ref=' . rawurlencode($cod);
}

/**
 * Bucata de referral pe care o primesc paginile despre propriul cont.
 *
 * Întoarce null când nu există cod sau când migrarea n-a fost rulată — pagina
 * verifică asta și pur și simplu nu desenează secțiunea.
 *
 * @return array{code:string,link:string}|null
 */
function referral_public(int $userId): ?array
{
    $cod = referral_code_of($userId);
    if ($cod === null) {
        return null;
    }
    return ['code' => $cod, 'link' => referral_link($cod)];
}
