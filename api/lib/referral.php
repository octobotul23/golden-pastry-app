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
 * Cifrele se opresc la cincizeci, iar o patiserie chiar ajunge la a cincizecea
 * „Maria". De-aia prenumele nu e singura fântână: după el se încearcă numele
 * întreg („mariaionescu"), apoi numele de utilizator. Abia când toate trei au
 * secat se refuză — și refuzul spune atunci că variantele s-au terminat, nu că
 * numele ar fi rău. Vezi `referral_code_bases()`.
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
    return referral_slug_curata($text, true);
}

/**
 * Numele ÎNTREG, curățat și lipit: „Maria Ionescu" → „mariaionescu".
 *
 * A doua fântână din care se scoate un cod, când prima a secat. Aceleași reguli
 * de curățare ca la prenume — altfel „?ref=MariaIonescu" n-ar mai nimeri contul,
 * fiindcă `referral_owner()` curăță ce vine din URL cu exact aceste reguli.
 *
 * @return string doar [a-z0-9], sau '' dacă nu rămâne nimic folosibil
 */
function referral_slug_intreg(string $text): string
{
    return referral_slug_curata($text, false);
}

/** Miezul celor două de mai sus. Nu se cheamă de nicăieri altundeva. */
function referral_slug_curata(string $text, bool $doarPrimul): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    // Prima fântână ia doar primul cuvânt — prenumele: „Ana Maria Ionescu" →
    // „ana". A doua le ia pe toate, lipite: „anamariaionescu".
    if ($doarPrimul) {
        $parts = preg_split('/\s+/u', $text) ?: [];
        $text  = (string) ($parts[0] ?? '');
    }
    $first = $text;
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
 * Fântânile din care poate ieși un cod, în ordinea în care se încearcă.
 *
 * Prenumele întâi, fiindcă e cel mai scurt și cel mai ușor de spus la telefon:
 * „ana", apoi „ana1", „ana2"… Când toate cincizeci sunt luate — și „Maria"
 * chiar ajunge acolo într-o patiserie —, se trece la numele întreg
 * („mariaionescu"), iar la urmă la numele de utilizator, care e unic în bază.
 * Curățat, nici el nu mai e unic cu totul („ana.pop" și „ana_pop" dau amândouă
 * „anapop"), dar e o a treia fântână deosebită de primele două, iar cifrele de
 * la coadă duc treaba la capăt.
 *
 * Fără fântânile astea, un nume des întâlnit ducea la „nu am putut construi un
 * cod din numele «Maria»" — un refuz care dă vina pe nume, când numele era bun
 * și doar variantele lui se terminaseră.
 *
 * @return string[] baze deosebite între ele, niciuna goală; lista poate fi goală
 */
function referral_code_bases(string $name, string $username = ''): array
{
    $baze = [];
    foreach ([referral_slug($name), referral_slug_intreg($name), referral_slug_intreg($username)] as $b) {
        // Coloana e VARCHAR(64); lăsăm loc pentru cifrele de dezambiguizare.
        $b = substr($b, 0, 58);
        if ($b !== '' && !in_array($b, $baze, true)) {
            $baze[] = $b;
        }
    }
    return $baze;
}

/**
 * Prima fântână, atât.
 *
 * Întoarce null dacă nici prenumele, nici numele întreg, nici numele de
 * utilizator nu dau nimic folosibil — cazul e tratat de apelant, care preferă
 * un cont fără cod unui cont neînscris.
 */
function referral_code_candidate(string $name, string $username = ''): ?string
{
    return referral_code_bases($name, $username)[0] ?? null;
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
function referral_assign(int $userId, string $name, string $username = '', ?string &$pricina = null): ?string
{
    $pricina = null;
    if (!referral_supported() || $userId <= 0) {
        $pricina = 'fara_tabel';
        return null;
    }
    $baze = referral_code_bases($name, $username);
    if (!$baze) {
        // Numele nu dă NIMIC folosibil: scris doar cu semne, sau într-un alfabet
        // pe care harta nu-l acoperă. Cu totul altceva decât „toate variantele
        // sunt luate", și se repară altfel — de-aia apelantul află care din două.
        $pricina = 'nume_gol';
        return null;
    }

    foreach ($baze as $baza) {
        for ($i = 0; $i < GP_REFERRAL_MAX_TRIES; $i++) {
            $cod = $i === 0 ? $baza : $baza . $i;
            try {
                // Condiția pe referral_code oprește suprascrierea unui cod deja
                // pus: funcția poate fi chemată din nou pe același cont fără pagubă.
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
                if ($are !== null && $are !== '') {
                    return (string) $are;
                }
                // Cont fără cod, dar UPDATE-ul n-a prins niciun rând: contul a
                // dispărut între timp. Nu e nici nume gol, nici variante luate.
                $pricina = 'fara_cont';
                return null;
            } catch (PDOException $e) {
                // 1062 = codul e luat de altcineva. Trecem la „ana1", „ana2"…
                // Orice altceva e o defecțiune adevărată și trebuie să rămână una.
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }
        error_log('[gp] baza „' . $baza . '" e luată de ' . GP_REFERRAL_MAX_TRIES
            . ' ori; trec la fântâna următoare, pentru contul ' . $userId . '.');
    }

    // Toate fântânile au secat. Numele e bun — doar variantele lui s-au
    // terminat, iar asta se repară cu un cod ales de mână, nu schimbând numele
    // omului. Apelantul află pricina și spune exact asta.
    $pricina = 'toate_luate';
    error_log('[gp] nu am găsit un cod de recomandare liber pentru contul ' . $userId
        . ' (baze: „' . implode('", „', $baze) . '"); contul rămâne fără cod.');
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
 * Cine stă în spatele unei liste de coduri, dintr-o singură interogare.
 *
 * `referral_owner()` e pentru un cod. Ecranele au însă mereu mai multe deodată
 * — lista de conflicte, cererile de pe site — iar un apel pe fiecare ar face
 * din deschiderea unei pagini un drum la bază pentru fiecare rând.
 *
 * Codul care nu se întoarce de aici e o stare adevărată, nu o eroare: contul a
 * fost închis sau codul redenumit. Cine cheamă scrie atunci codul gol, ca omul
 * să vadă de ce nu duce nicăieri.
 *
 * @param  string[] $coduri
 * @return array<string,array{id:int,name:string}> cod → contul din spatele lui
 */
function referral_owners_for_codes(array $coduri): array
{
    if (!referral_supported()) {
        return [];
    }
    $curate = [];
    foreach ($coduri as $c) {
        $c = referral_normalize_code((string) $c);
        if ($c !== '') {
            $curate[$c] = true;
        }
    }
    if (!$curate) {
        return [];
    }

    $lista = array_keys($curate);
    $semne = implode(',', array_fill(0, count($lista), '?'));
    $out   = [];
    foreach (all(
        "SELECT id, referral_code, name FROM users
          WHERE referral_code IN ($semne) AND active = 1 AND is_system = 0",
        $lista
    ) as $u) {
        $out[(string) $u['referral_code']] = [
            'id'   => (int) $u['id'],
            'name' => (string) $u['name'],
        ];
    }
    return $out;
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
 * Codul celui care l-a ADUS pe omul ăsta — nu al lui, al celuilalt.
 *
 * Perechea lui `referral_code_of()`, privită din cealaltă parte: acolo „ce cod
 * am eu", aici „al cui cod scrie pe contul meu".
 *
 * De ce e nevoie de el. Magazinul public are o casetă „Cod de recomandare" pe
 * formularul de comandă, iar ea a purtat multă vreme un defect urât: pe un
 * calculator pe care se deschisese cândva un link „?ref=ples", codul acela
 * rămânea în cookie și se lipea de CERERILE ORICUI se așeza pe urmă la
 * tastatură — inclusiv ale clienților cu cont, care n-aveau nicio treabă cu
 * el. Pagina a fost reparată (Site Public.dc.html:1374): pentru clientul cu
 * cont, cookie-ul nu mai contează deloc, fiindcă hotărăște CONTUL. Dar ca să
 * hotărască contul, contul trebuie mai întâi să poată vorbi — și n-avea de
 * unde: niciun răspuns nu spunea cine l-a adus pe client.
 *
 * Rândul ăsta e vorba lui. Fără el, caseta clientului rămâne goală, iar
 * recomandarea lui merge mai departe nevăzută: serverul citește oricum
 * `users.referred_by` la socoteală. Cu el, caseta e o OGLINDĂ a contului — și
 * asta contează, fiindcă un om care nu-și vede recomandantul scris nicăieri e
 * un om care într-o zi tastează un cod din memorie și naște un conflict
 * („in_asteptare") pe care trebuie să-l descâlcească patronul cu mâna.
 *
 * Ca `referral_code_of()`: o citire după cheia primară, nu o coloană în plus
 * în `current_user()` — interogarea aceea e pe drumul FIECĂREI cereri și are
 * listă fixă de coloane; o coloană adăugată acolo ar cădea cu 1054 pe o
 * instalare fără migrarea 007, adică ar opri toată aplicația pentru o casetă.
 */
function referral_referred_by_code(int $userId): ?string
{
    if (!referral_supported() || $userId <= 0) {
        return null;
    }
    // O singură interogare, cu legătura în ea. Două (întâi `referred_by`, apoi
    // codul lui) ar fi fost două drumuri la bază pe fiecare `/auth/me`, adică
    // pe fiecare deschidere de pagină din magazin.
    $v = scalar(
        'SELECT r.referral_code
           FROM users u JOIN users r ON r.id = u.referred_by
          WHERE u.id = ?',
        [$userId]
    );
    return $v !== null && $v !== '' ? (string) $v : null;
}

/**
 * Ce vede un agent de vânzări: comenzile oamenilor pe care i-a adus el.
 *
 * Agentul e personal — `require_staff()` îi deschide comenzile — dar comenzile
 * casei nu sunt ale lui. Are voie la două feluri de rânduri, și numai la ele:
 *
 *   1. ale cumpărătorilor legați de el pentru totdeauna (`users.referred_by`);
 *   2. cele care îi poartă codul chiar pe rând (`referral_code`), puse așa de
 *      administrator pe o comandă anume fără să lege contul. Comisionul e al
 *      lui, deci și comanda din care iese: altfel ar vedea în „Recomandări" un
 *      câștig dintr-o comandă care nu-i apare în „Comenzi".
 *
 * Se întoarce o bucată de WHERE, nu o listă gata făcută de id-uri: lista ar fi
 * a doua copie a legăturilor, ar crește cât registrul de conturi și s-ar sparge
 * exact la agentul cu cei mai mulți aduși.
 *
 * Fără migrarea 007 nu există coloana `referred_by`, deci mărginirea nu se
 * poate face deloc. Atunci se refuză: o poartă care nu se poate închide trebuie
 * să oprească, nu să deschidă. (Pe o bază fără 007 nici rolul de agent nu
 * există, deci ramura e o plasă de siguranță, nu un drum umblat.)
 *
 * @param  string $colCont coloana de cumpărător, cu alias cu tot: „o.customer_id"
 * @param  string $colCod  coloana de cod de pe rând: „o.referral_code"
 * @return array{sql: string, params: array}
 */
function referral_agent_scope(int $agentId, string $colCont, string $colCod): array
{
    if (!referral_supported()) {
        fail('Recomandările nu sunt disponibile: rulează întâi sql/007_update_roles_referral.sql.',
            503, 'referral_unavailable');
    }

    $sql    = $colCont . ' IN (SELECT id FROM users WHERE referred_by = ?)';
    $params = [$agentId];

    // Coloana de cod de pe comandă vine abia cu migrarea 008. Pe o bază oprită
    // la 007 rămâne numai legătura de cont: mai puțin, dar adevărat.
    if (referral_money_supported()) {
        $cod = referral_code_of($agentId);
        if ($cod !== null) {
            $sql      = '(' . $sql . ' OR ' . $colCod . ' = ?)';
            $params[] = $cod;
        }
    }

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Aceeași poartă, pusă pe o singură comandă luată după id.
 *
 * Întrebarea se pune tot în SQL, cu exact bucata de WHERE a listei. Scrisă a
 * doua oară în PHP, s-ar putea certa cu lista — iar dintre două verificări care
 * nu se potrivesc, cea care greșește tăcut e întotdeauna cea care deschide.
 *
 * O comandă care nu există dă tot „false", deci refuz, nu „nu există": agentul
 * n-are de ce să afle prin ghicire câte comenzi are casa.
 */
function referral_agent_sees_order(int $agentId, int $orderId): bool
{
    $sc = referral_agent_scope($agentId, 'customer_id', 'referral_code');

    return (int) scalar(
        'SELECT COUNT(*) FROM orders WHERE id = ? AND ' . $sc['sql'],
        array_merge([$orderId], $sc['params'])
    ) === 1;
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

/* ==========================================================================
 * COMISIOANE — jumătatea comercială (migrarea 008)
 *
 * Până aici, fișierul știa doar CINE pe cine a adus. De aici încolo știe și CÂT
 * a câștigat din asta, când s-a plătit, și ce se întâmplă când comanda se
 * anulează.
 *
 * Aceeași regulă ca mai sus, dublată: fiecare funcție se oprește singură, fără
 * eroare, dacă migrarea 008 n-a fost încă rulată. Fișierele urcă pe server
 * înaintea importului din phpMyAdmin, iar în intervalul acela comenzile trebuie
 * să se scrie exact ca înainte.
 * ========================================================================== */

/**
 * Există registrul de comisioane și coloanele lui însoțitoare?
 *
 * Separat de `referral_supported()`, nu topit în el: 007 și 008 se importă la
 * momente diferite, iar între ele codurile de recomandare merg (007 e acolo)
 * fără ca banii să existe încă. Un singur steag pentru amândouă ar stinge și
 * legăturile „cine pe cine a adus" cât timp lipsește doar tabelul de bani.
 */
function referral_money_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!referral_supported()) {
        return $ok = false;
    }
    try {
        $tabel = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referral_commissions'"
        );
        $coloane = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND ((TABLE_NAME = 'users'  AND COLUMN_NAME = 'referral_rate')
                  OR (TABLE_NAME = 'orders' AND COLUMN_NAME = 'referral_code')
                  OR (TABLE_NAME = 'order_requests' AND COLUMN_NAME = 'referral_code'))"
        );
        return $ok = ($tabel === 1 && $coloane === 3);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica suportul de comisioane: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Se poate SCOATE un rând de comision din socoteală? (migrarea 012)
 *
 * A treia probă de felul acesta din fișier, și nu din lene de a le uni: 007,
 * 008 și 012 se importă la momente diferite, iar între urcarea fișierelor și
 * importul din phpMyAdmin codul trebuie să meargă exact ca înainte. Un
 * `dismissed_at` scris necondiționat într-un WHERE ar face din pagina
 * „Recomandări" un 500 curat în intervalul acela — adică tocmai în clipa în
 * care patronul se uită să vadă dacă publicarea a mers.
 */
function referral_dismiss_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    if (!referral_money_supported()) {
        return $ok = false;
    }
    try {
        $coloane = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referral_commissions'
                AND COLUMN_NAME IN ('dismissed_at', 'dismissed_by')"
        );
        return $ok = ($coloane === 2);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica scoaterea comisioanelor: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Condiția „rândul acesta nu e scos" — goală cât timp migrarea 012 lipsește.
 *
 * Se întoarce ca TEXT lipit în interogare, nu ca parametru, fiindcă un
 * parametru n-are cum să dispară: pe o bază fără 012 însuși NUMELE coloanei e o
 * eroare de SQL, nu o comparație care iese falsă. `$alias` vine numai din cod,
 * niciodată dintr-o cerere, dar se curăță oricum — un fragment de SQL venit
 * dintr-o variabilă merită tratat ca atare, oricât de sigur ar părea azi.
 */
function referral_dismiss_cond(string $alias = ''): string
{
    if (!referral_dismiss_supported()) {
        return '';
    }
    $alias = (string) preg_replace('/[^A-Za-z0-9_]/', '', $alias);
    return ($alias !== '' ? $alias . '.' : '') . 'dismissed_at IS NULL';
}

/** Aceeași condiție, gata de lipit după un WHERE care are deja ceva în el. */
function referral_dismiss_where(string $alias = ''): string
{
    $c = referral_dismiss_cond($alias);
    return $c === '' ? '' : ' AND ' . $c;
}

/**
 * Procentul, ținut între 0 și 100.
 *
 * Coloana e DECIMAL(5,2), adică încape 999,99 — iar un „500" strecurat prin
 * curl ar face din fiecare comandă o datorie de cinci ori valoarea ei. Baza
 * n-are cum să mărginească singură; aici se poate, și e singurul drum prin care
 * un procent ajunge în socoteală.
 */
function referral_clamp_rate(float $rate): float
{
    if (!is_finite($rate) || $rate < 0) {
        return 0.0;
    }
    return round(min($rate, 100.0), 2);
}

/**
 * Procentul implicit, din Setări.
 *
 * DOUĂ FELURI DE GOL, care nu înseamnă același lucru:
 *
 *   · RÂNDUL LIPSEȘTE din `settings` — nimeni n-a hotărât nimic, niciodată.
 *     Atunci vorbește casa: 5, aceeași cifră cu cea semănată de `sql/017`. Cât
 *     timp au fost două — rândul zicea zero, casa zicea cinci — un server pe
 *     care rândul lipsea din orice pricină ar fi socotit tăcut la zero, deși
 *     peste tot scria cinci. Un singur adevăr, în două locuri care nu se pot
 *     certa.
 *
 *   · CASETA E GOLITĂ, adică rândul există și e gol. Asta NU e o lipsă: e o
 *     hotărâre, luată cu mâna, din pagina Setări. „De acum, recomandările nu
 *     mai aduc bani." Căzută pe rezervă, hotărârea aceea era ștearsă în tăcere
 *     și fiecare comandă năștea mai departe un comision de 5% — iar scriptul de
 *     pe server îi spunea patronului, în aceeași zi, exact pe dos: „Procentul de
 *     recomandare e gol — recomandările nu aduc nimic" (actualizeaza-vps.sh).
 *     Două ecrane, două adevăruri, despre banii lui.
 *
 * `setting()` nu le poate deosebi — pentru ea amândouă sunt același șir gol —
 * de-aia se citește cu `setting_raw()`, care întoarce null numai pentru rândul
 * care chiar lipsește.
 *
 * Un ZERO SCRIS și o CASETĂ GOLITĂ ajung acum în același loc, dinadins: sunt
 * aceeași hotărâre, spusă în două feluri.
 */
function referral_default_rate(): float
{
    $v = setting_raw('referral_rate_default');
    if ($v === null) {
        return referral_clamp_rate(5.0);
    }
    if (trim($v) === '') {
        return 0.0;
    }
    return referral_clamp_rate((float) $v);
}

/**
 * Procentul unui cont: al lui dacă are, altfel cel global.
 *
 * `scalar()` întoarce null și când rândul lipsește, și când coloana e NULL —
 * amândouă înseamnă aici același lucru („n-are hotărâre proprie"), deci ramura
 * e una singură. Un 0 scris dinadins pe cont NU e null și nu cade aici: contul
 * acela chiar nu câștigă nimic, oricât ar fi procentul global.
 */
function referral_rate_for(?int $userId): float
{
    if (!referral_money_supported()) {
        return 0.0;
    }
    if ($userId !== null && $userId > 0) {
        $v = scalar('SELECT referral_rate FROM users WHERE id = ?', [$userId]);
        if ($v !== null) {
            return referral_clamp_rate((float) $v);
        }
    }
    return referral_default_rate();
}

/**
 * Codul tastat de om, adus la forma din bază.
 *
 * NU e `referral_slug()`, și diferența contează: acela ia doar PRIMUL CUVÂNT,
 * fiindcă e făcut să scoată un cod dintr-un nume întreg („Ana Maria" → „ana").
 * Aici omul nu dictează un nume, ci scrie un cod. „GOLD 2025" tastat cu un
 * spațiu din grabă ar deveni „gold" — un cod scurt, altul decât cel voit, și
 * poate deja al altcuiva. Lipim întâi cuvintele, apoi curățăm: „gold2025".
 */
function referral_normalize_code(string $cod): string
{
    $cod = preg_replace('/\s+/u', '', trim($cod));
    return referral_slug((string) ($cod ?? ''));
}

/**
 * Codul pus de administrator peste cel existent.
 *
 * Deliberat DIFERIT de `referral_assign()`: acela scrie doar pe conturile fără
 * cod (`referral_code IS NULL` în WHERE) și e chemat la înscriere. Ăsta
 * suprascrie, deci e o hotărâre, nu o completare — și are urmarea pe care
 * pagina trebuie s-o spună pe față: LINKURILE DEJA TRIMISE CU CODUL VECHI NU
 * MAI RECOMANDĂ PE NIMENI. Nu ținem istoric de coduri.
 *
 * Rândurile din registru NU se ating: `code_used` păstrează șirul cu care s-a
 * câștigat fiecare comision, tocmai ca redenumirea de azi să nu facă istoricul
 * de neexplicat.
 *
 * @return string codul scris
 */
function referral_code_set(int $userId, string $cod): string
{
    if (!referral_supported()) {
        fail('Recomandările nu sunt pornite pe serverul acesta.', 409, 'referral_off');
    }
    $curat = referral_normalize_code($cod);
    if ($curat === '') {
        fail('Codul poate avea numai litere și cifre.', 422, 'bad_code');
    }
    // Sub două caractere codul nu mai e un cod: „a" s-ar ciocni de orice și
    // n-ar spune nimănui nimic la telefon.
    if (strlen($curat) < 2) {
        fail('Codul e prea scurt — cel puțin două caractere.', 422, 'code_too_short');
    }
    if (strlen($curat) > 58) {
        fail('Codul e prea lung — cel mult 58 de caractere.', 422, 'code_too_long');
    }

    // `q_unique` întoarce 409 cu o vorbă omenească în loc de „Eroare internă":
    // ciocnirea de coduri e o greșeală de tastare, nu o defecțiune.
    q_unique(
        'UPDATE users SET referral_code = ? WHERE id = ?',
        [$curat, $userId],
        'Codul „' . $curat . '" e deja folosit de alt cont.',
        'code_taken'
    );
    return $curat;
}

/**
 * Butonul „fă-mi un cod", apăsat de omul însuși.
 *
 * Nu e o rută nouă peste `referral_assign()`, e chiar el: aceeași derivare din
 * prenume, aceeași reîncercare la ciocnire. Diferența e cine apasă. Contul care
 * are deja cod îl primește pe al lui înapoi, nu unul nou — altfel un al doilea
 * clic pe buton ar rupe linkurile trimise între timp.
 */
function referral_self_assign(int $userId, ?string &$pricina = null): ?string
{
    $pricina = null;
    if (!referral_supported() || $userId <= 0) {
        $pricina = 'fara_tabel';
        return null;
    }
    $are = referral_code_of($userId);
    if ($are !== null) {
        return $are;
    }
    $u = one('SELECT name, username FROM users WHERE id = ?', [$userId]);
    if (!$u) {
        $pricina = 'fara_cont';
        return null;
    }
    return referral_assign($userId, (string) $u['name'], (string) $u['username'], $pricina);
}

/**
 * Telefonul adus la o formă în care două scrieri ale aceluiași număr se pot
 * compara între ele.
 *
 * Nu e o validare și nu se scrie nicăieri: e o cheie de potrivire, folosită
 * numai de poarta de auto-recomandare de mai jos. Omul își scrie numărul cum îi
 * vine — „0722 111 222", „0722.111.222", „+40 722 111 222", „0040722111222" —
 * iar pe contul lui poate sta scris în a cincea formă. Comparate ca șiruri,
 * toate ar fi numere diferite, și poarta n-ar prinde niciodată nimic.
 *
 * Pragul de 9 cifre nu e o cochetărie: fără el, un „0" rătăcit sau un „123"
 * scris în grabă ar da aceeași cheie pe două comenzi care n-au nicio legătură,
 * iar poarta ar începe să refuze comisioane cinstite. Ce nu arată a număr de
 * telefon iese de aici GOL, iar golul nu se potrivește cu nimic — nici măcar
 * cu alt gol.
 */
function referral_telefon_cheie(string $tel): string
{
    // Rămân doar cifrele: spațiile, punctele, parantezele și „+"-ul sunt fel de
    // scris, nu parte din număr.
    $cifre = (string) preg_replace('/\D+/', '', $tel);

    // Zerourile din față cad înaintea prefixului de țară, ca „0040…" și „+40…"
    // să ajungă amândouă la „40…", iar „07…" la „7…". Prefixul se taie numai
    // dacă sub el mai rămâne un număr întreg, altfel un fix care începe chiar
    // cu 40 ar fi ciuntit.
    $cifre = ltrim($cifre, '0');
    if (strlen($cifre) > 9 && strncmp($cifre, '40', 2) === 0) {
        $cifre = substr($cifre, 2);
    }

    return strlen($cifre) >= 9 ? $cifre : '';
}

/**
 * Comanda asta e chiar a omului care ține codul de recomandare?
 *
 * Întrebarea pare să aibă un răspuns simplu — se compară `orders.customer_id`
 * cu contul — și chiar îl avea, dar numai pentru comenzile LEGATE de un cont.
 * Necazul e că foarte multe comenzi ale casei nu sunt legate: cererea trimisă
 * de pe site de cineva care nu e conectat se scrie cu `customer_id` gol
 * (api/routes/requests.php:91, iar completarea după nume de la :131 se face
 * numai când există o sesiune), iar comanda născută din ea la „Acceptă"
 * moștenește golul (api/routes/requests.php:382).
 *
 * Pe comenzile acelea, comparația de id-uri nu spunea „nu e el", ci „nu știu" —
 * și „nu știu" era citit drept „nu e el". Așa, oprirea auto-recomandării se
 * ocolea prin simpla deconectare: omul își punea marfa în coș ca vizitator, își
 * scria codul lui în caseta „Cod de recomandare" și încasa procentul din
 * propria cumpărătură, comandă după comandă, fără ca vreun ecran să arate ceva
 * neobișnuit — pagina de plată grupează rândurile pe încasator, nu pe cumpărător.
 *
 * Deci comanda se compară cu identitățile pe care LE POARTĂ: telefonul din
 * formular, e-mailul cererii din care s-a născut și numele clientului. Nu e o
 * dovadă de identitate, e o potrivire — dar exact potrivirea asta îi trebuie
 * omului ca să primească marfa, deci n-o poate ocoli fără să renunțe la marfă.
 *
 * TREI HOTĂRÂRI, fiecare luată ca poarta să nu omoare comisioane cinstite:
 *
 *   * TELEFONUL ȘI E-MAILUL se compară numai pe comenzile venite de pe site
 *     (`request_id`). Motivul e agentul de teren: el e singurul rol care
 *     primește cod automat (api/routes/users.php:311), și lucrează trecând
 *     PROPRIUL număr drept persoană de contact pe comanda unui client fără
 *     cont. Aplicată și comenzilor din panou, poarta i-ar fi stins tăcut
 *     comisionul chiar pe munca lui. Iar drumul pe care se scurgeau banii era
 *     oricum cel de pe site: `POST /orders` cere `require_staff()`.
 *
 *   * NUMELE cere să fie NEÎNDOIELNIC — un singur cont în toată casa să-l
 *     poarte — aceeași grijă pe care o are `client_by_name()`
 *     (api/lib/orders.php:52-61) și din același motiv: o firmă cu două puncte
 *     de lucru, o franciză, un „M. Pop" nu sunt o dovadă că e același om. Fără
 *     unicitate, poarta ar fi omorât pentru totdeauna comisionul celui de-al
 *     doilea punct de lucru, fără o vorbă.
 *
 *   * GOLUL NU SE POTRIVEȘTE CU GOLUL. `orders.phone` poate fi null
 *     (api/routes/orders.php scrie `$phone ?: null`), la fel adresa de
 *     e-mail a unui cont; comparate ca șiruri goale, orice două comenzi fără
 *     telefon ar fi „ale aceluiași om".
 *
 * ȘI O NEPUTINȚĂ, de spus pe față: cine scrie DINADINS alt nume și alt telefon
 * decât ale lui trece mai departe. Marfa pleacă după adresă, iar adresa nu se
 * compară cu nimic. Ușa se închide pentru cel care își folosește propriile
 * date; fraudă cu identitate inventată se prinde numai uitându-se cineva, în
 * „Recomandări", la cumpărătorii de pe rândurile fiecărui om.
 *
 * @param  int   $contId  contul care ar încasa comisionul
 * @param  array $o       rândul comenzii; îi trebuie `customer_id`,
 *                        `customer_name`, `phone` și `request_id`
 * @return string|null    după ce s-a potrivit, pentru jurnal și pentru refuz:
 *                        „cont", „telefon", „e-mail" sau „nume". Null = nu e el.
 */
function referral_potrivire_cumparator(int $contId, array $o): ?string
{
    if ($contId <= 0) {
        return null;
    }

    // Comanda legată chiar de contul lui: răspunsul sigur, cel de dinainte.
    $legat = ($o['customer_id'] ?? null) !== null ? (int) $o['customer_id'] : null;
    if ($legat === $contId) {
        return 'cont';
    }

    $cont = one('SELECT phone, email FROM users WHERE id = ?', [$contId]);
    if (!$cont) {
        return null;
    }

    // Telefonul și e-mailul, NUMAI pe comenzile de pe site — vezi hotărârea 1
    // din lămurirea de mai sus.
    //
    // Se cercetează și când comanda e legată de ALT cont, dinadins: al doilea
    // cont, făcut în treizeci de secunde pe un nume oarecare, era portița cea
    // mai comodă. Înscrierea nu cere nimic verificabil, iar ramura 1 a
    // cârligului ar fi legat contul acela „pentru totdeauna" de codul primului
    // (`referral_link_new_user()`), deci de la a doua comandă banii ar fi curs
    // singuri. Două conturi care poartă același telefon sau același e-mail sunt
    // același om, oricâte conturi ar avea.
    if (($o['request_id'] ?? null) !== null) {
        $telCont = referral_telefon_cheie((string) ($cont['phone'] ?? ''));
        if ($telCont !== '' && $telCont === referral_telefon_cheie((string) ($o['phone'] ?? ''))) {
            return 'telefon';
        }

        // E-mailul nu stă pe comandă — coloana e numai pe cerere — deci se
        // ajunge la el prin cererea din care s-a născut comanda.
        $mailCont = strtolower(trim((string) ($cont['email'] ?? '')));
        if ($mailCont !== '') {
            $mailCerere = strtolower(trim((string) (scalar(
                'SELECT email FROM order_requests WHERE id = ?',
                [(int) $o['request_id']]
            ) ?? '')));
            if ($mailCerere !== '' && $mailCerere === $mailCont) {
                return 'e-mail';
            }
        }
    }

    // Numele, numai pe comanda care n-are cont și numai dacă e neîndoielnic.
    // `LIMIT 2` e destul ca să se afle dacă e unul singur, ca la
    // `client_by_name()`; a-l număra pe tot ar fi o interogare mai scumpă
    // pentru același răspuns.
    $nume = trim((string) ($o['customer_name'] ?? ''));
    if ($legat === null && $nume !== '') {
        $randuri = all(
            "SELECT id FROM users
              WHERE active = 1 AND is_system = 0
                AND TRIM(COALESCE(NULLIF(customer_name, ''), name)) = ?
              LIMIT 2",
            [$nume]
        );
        if (count($randuri) === 1 && (int) $randuri[0]['id'] === $contId) {
            return 'nume';
        }
    }

    return null;
}

/**
 * Poarta răspicată: codul tastat de personal, care ar fi chiar al cumpărătorului.
 *
 * De ce răspicată aici și tăcută în cârlig. Sunt două situații, nu una:
 *
 *   * VIZITATORUL de pe site care își scrie propriul cod. Un refuz ar opri
 *     COMANDA — iar comanda e lucrul adevărat, o socoteală de marketing n-are
 *     voie s-o omoare (aceeași regulă ca la api/routes/orders.php:350). Acolo
 *     comisionul pur și simplu nu se naște, iar cârligul scrie în jurnal
 *     `referral_self_blocked`, ca patronul să poată afla de ce.
 *
 *   * ADMINISTRATORUL care tastează un cod în formular. El e om, e de față și
 *     are dreptul la un răspuns: azi el pune pe față orice cod pe orice comandă
 *     (api/routes/orders.php:141-144), deci o potrivire care i-ar anula
 *     hotărârea în tăcere i-ar lua o putere pe care o are pe drept. Aici refuzul
 *     e citibil și spune ce are de făcut.
 *
 * Se cheamă ÎNAINTE de orice scriere, lângă `referral_guard_paid_code_change()`:
 * un refuz de la mijlocul salvării ar lăsa comanda pe jumătate corectată.
 */
function referral_guard_self_code(int $orderId, string $codNou): void
{
    if (!referral_money_supported() || $orderId <= 0 || $codNou === '') {
        return;
    }
    // Un cod care nu duce nicăieri nu se refuză: el cade oricum la ramura 2 a
    // cârligului, iar un refuz aici ar fi despre o literă greșită, nu despre bani.
    $owner = referral_owner($codNou);
    if ($owner === null) {
        return;
    }
    $o = one(
        'SELECT customer_id, customer_name, phone, request_id FROM orders WHERE id = ?',
        [$orderId]
    );
    if (!$o) {
        return;
    }
    $dupa = referral_potrivire_cumparator((int) $owner['id'], $o);
    if ($dupa === null) {
        return;
    }

    fail(
        'Codul „' . $codNou . '" e al lui ' . $owner['name'] . ', iar comanda pare a fi tot a lui '
          . '(se potrivește după ' . $dupa . '). Din propriile cumpărături nu se câștigă comision, '
          . 'deci codul n-ar aduce niciun ban. Dacă e într-adevăr altcineva, leagă comanda de contul '
          . 'lui sau treci pe ea datele lui de contact, apoi pune codul.',
        409,
        'referral_self'
    );
}

/**
 * Cârligul unic al comisioanelor: creează, recalculează, stinge, reaprinde.
 *
 * TOT ce se întâmplă cu banii unei recomandări trece pe aici. E chemat din trei
 * locuri — la crearea comenzii din panou, la acceptarea unei cereri de pe site,
 * și la orice modificare de comandă — iar faptul că e unul singur e ce face ca
 * „anulez comanda" și „o dezanulez" să se poarte simetric fără să scrie nimeni
 * a doua oară regula.
 *
 * Regula de atribuire, în ordine:
 *
 *   1. cod pe comandă, care duce la un cont bun → contul acela;
 *   2. fără cod (sau cod care nu duce nicăieri) → recomandantul contului;
 *   3. cod bun, DAR contul are deja ALT recomandant → „in_asteptare": nu se
 *      numără pentru nimeni, patronul alege;
 *   4. auto-recomandare, cont dezactivat, cont de sistem → NICIUN RÂND.
 *
 * Un cod tastat greșit cade la 2, nu la 3: o literă pusă alături nu are voie să
 * cheme patronul să rezolve un conflict care nu există.
 *
 * Ramura 1 are o urmare care ține o viață: codul tastat pe un cont fără
 * recomandant îl leagă PENTRU TOTDEAUNA. La creare asta e chiar ce trebuie —
 * omul tocmai a venit prin cineva. La o CORECTURĂ de comandă veche, nu: cel
 * care repară o comandă uitată fără cod nu hotărăște prin asta și viitorul
 * contului. De-aia legarea e un parametru, nu o certitudine, iar formularul de
 * editare o cere pe față, cu o bifă nebifată.
 *
 * @param  int         $orderId  comanda
 * @param  string|null $codTastat codul scris de om; null = „nu s-a atins", se
 *                                folosește ce e deja pe comandă
 * @param  int|null    $actorId  cine a apăsat, pentru jurnal
 * @param  bool        $leagaContul  are voie ramura 1 să scrie legătura
 *                                „pentru totdeauna" pe contul cumpărătorului?
 *                                Implicit da, ca la creare și la acceptarea
 *                                unei cereri — acolo nimeni n-a fost întrebat
 *                                fiindcă nu era nimic de întrebat.
 * @return array|null  rândul din registru, sau null dacă nu e cazul unuia
 */
function referral_commission_sync(
    int $orderId,
    ?string $codTastat = null,
    ?int $actorId = null,
    bool $leagaContul = true
): ?array
{
    // „S-a scris ACUM legătura pentru totdeauna?" Rămâne fals pe toate
    // drumurile pe care nimeni n-a cerut-o, și pe cele pe care contul avea deja
    // un recomandant. Pleacă spre panou lipit de rândul de comision, ca mesajul
    // de după salvare să spună ce s-a întâmplat, nu doar că s-a întâmplat ceva.
    $legatAcum = false;

    if (!referral_money_supported() || $orderId <= 0) {
        return null;
    }

    $o = one(
        // `phone` și `request_id` se citesc pentru poarta de auto-recomandare:
        // pe o comandă fără cont ele sunt singurele urme de identitate pe care
        // comanda le poartă — telefonul din formular și cererea de pe site din
        // care s-a născut, prin care se ajunge la e-mail. Se iau din
        // interogarea pe care funcția o face oricum, fiindcă ea se execută la
        // FIECARE salvare de comandă, nu doar la creare.
        'SELECT id, ref, day, customer_id, customer_name, phone, request_id,
                total, status, referral_code
           FROM orders WHERE id = ?',
        [$orderId]
    );
    if (!$o) {
        return null;
    }

    // Codul se scrie pe comandă întâi, o singură dată, ca reluările de mai
    // târziu (o anulare, o corectură de cantități) să pornească de la același
    // șir. Ținut doar în parametru, s-ar pierde la prima recalculare, iar
    // comisionul ar sări în tăcere pe recomandantul contului.
    if ($codTastat !== null) {
        $curat = referral_normalize_code($codTastat);
        $curat = $curat === '' ? null : $curat;
        if ($curat !== (string) ($o['referral_code'] ?? '') && !($curat === null && $o['referral_code'] === null)) {
            q('UPDATE orders SET referral_code = ? WHERE id = ?', [$curat, $orderId]);
            $o['referral_code'] = $curat;
        }
    }

    // RÂNDUL SCOS DE MÂNA PATRONULUI NU SE MAI ATINGE. (migrarea 012)
    //
    // Poarta stă cât se poate de sus dinadins: dedesubt urmează și socoteala
    // care rescrie rândul, și ștergerea de la ramura 4 — iar un rând scos n-are
    // voie nici să se recalculeze, nici să dispară, nici să învie. Fără poarta
    // asta, „scoate rândul" ar fi fost o ștergere care se desface singură:
    // cârligul se execută la FIECARE atingere a comenzii, iar dacă
    // cumpărătorul are un recomandant pe cont, ramura 2 scrie rândul la loc,
    // cu alt id, la prima mutare de etapă de pe tableta din laborator. Patronul
    // ar fi apăsat „Șterge", ar fi văzut rândul dispărând, și l-ar fi găsit
    // înapoi peste zece minute, fără să afle niciodată de ce.
    //
    // Un rând scos se desface dintr-un singur loc, la o hotărâre omenească:
    // butonul „Adu înapoi" din „Recomandări".
    //
    // Deasupra porții rămâne, dinadins, scrierea codului pe COMANDĂ: acela e un
    // câmp al comenzii, nu un rând din registru. Nesalvat, s-ar întoarce vechi
    // în formular la prima reîncărcare — adică aceeași minciună, mutată în alt
    // loc.
    if (referral_dismiss_supported()) {
        $scos = one(
            'SELECT id FROM referral_commissions
              WHERE order_id = ? AND dismissed_at IS NOT NULL',
            [$orderId]
        );
        if ($scos) {
            // Poarta asta TACE, și nu din lene. Refuzul nu are voie să fie mut
            // — dar el nu se poate rosti de aici: `PATCH /orders` își scrie
            // singur `referral_code` și cheamă cârligul cu `null`
            // (api/routes/orders.php:1123), deci înăuntru nu se știe niciodată
            // dacă tocmai a tastat cineva un cod sau doar s-a mutat o etapă.
            // O urmă scrisă „când $codTastat !== null" n-ar fi apărut niciodată
            // la editare, adică exact acolo unde e omul.
            //
            // Vorbește în schimb `referral_guard_dismissed_code()`, chemată din
            // rută înaintea oricărei scrieri, lângă celelalte două porți ale
            // codului. Acolo omul e de față și primește un refuz din care poate
            // face ceva.
            return null;
        }
    }

    $cod       = trim((string) ($o['referral_code'] ?? ''));
    $buyerId   = $o['customer_id'] !== null ? (int) $o['customer_id'] : null;
    $anulata   = status_cancelled();
    $eAnulata  = $anulata !== '' && (string) $o['status'] === $anulata;

    // Recomandantul deja legat de contul cumpărătorului.
    $contRef = null;
    $codCont = null;
    if ($buyerId !== null) {
        $r = one(
            'SELECT r.id, r.name, r.referral_code
               FROM users u JOIN users r ON r.id = u.referred_by
              WHERE u.id = ? AND r.active = 1 AND r.is_system = 0',
            [$buyerId]
        );
        if ($r) {
            $contRef = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
            $codCont = $r['referral_code'] !== null ? (string) $r['referral_code'] : null;
        }
    }

    // Contul căruia îi aparține codul tastat. `referral_owner()` filtrează deja
    // conturile dezactivate și cele de sistem, deci un cod bun dar mort cade
    // singur la ramura 2.
    $codRef = $cod !== '' ? referral_owner($cod) : null;

    // Codul PROPRIU se stinge aici, înainte de orice altceva.
    //
    // Ordinea nu e un amănunt. Verificarea de auto-recomandare de mai jos se
    // uită la `$referrer`, iar pe ramura de conflict `$referrer` rămâne gol —
    // deci cine își tasta propriul cod pe o comandă a lui, având deja un
    // recomandant, nu primea „niciun rând", ci un CONFLICT: patronul era chemat
    // să aleagă între client și recomandantul lui, pentru bani pe care nimeni
    // nu-i putea încasa. Oricine își putea umple singur lista de rezolvat.
    //
    // Stins aici, codul propriu e ca și cum n-ar fi fost scris: comanda cade pe
    // recomandantul contului, ca oricare alta.
    //
    // Întrebarea „e chiar al lui?" NU se mai pune pe `$buyerId`. Condiția
    // `$buyerId !== null` stingea poarta exact acolo unde era nevoie de ea: pe
    // comanda venită de pe site fără sesiune, care se naște cu `customer_id`
    // gol. Vezi `referral_potrivire_cumparator()`.
    $refuzuri = [];
    if ($codRef !== null) {
        $dupa = referral_potrivire_cumparator((int) $codRef['id'], $o);
        if ($dupa !== null) {
            $refuzuri[] = ['nume' => (string) $codRef['name'], 'dupa' => $dupa];
            $codRef = null;
        }
    }

    $inAsteptare = false;
    $referrer    = null;
    $codFolosit  = null;

    if ($codRef !== null && $contRef !== null && $codRef['id'] !== $contRef['id']) {
        // 3. Conflictul. Amândoi sunt scriși în rând, ca patronul să vadă între
        //    cine alege; niciunul nu încasează nimic până nu alege.
        $inAsteptare = true;
    } elseif ($codRef !== null) {
        // 1. Codul câștigă. (Dacă e chiar recomandantul contului, e o
        //    confirmare, nu un conflict — aceeași ramură.)
        $referrer   = $codRef;
        $codFolosit = referral_normalize_code($cod);

        // Aici se naște regula „pentru totdeauna": un cod tastat pe un cont
        // FĂRĂ recomandant îl leagă de-acum înainte. De la comanda asta încolo,
        // fără să se mai tasteze nimic, câștigă același om.
        // `referral_link_new_user()` are `referred_by IS NULL` în WHERE, deci e
        // inofensiv dacă între timp l-a legat altcineva.
        //
        // Steagul nu atinge comisionul, doar legătura: comanda de față se
        // premiază la fel în ambele cazuri. Diferența se vede abia la comanda
        // URMĂTOARE a aceluiași cumpărător, care fără legătură nu mai are
        // recomandant.
        if ($contRef === null && $buyerId !== null && $leagaContul) {
            // Întoarce recomandantul numai dacă a scris CHIAR ACUM legătura
            // (`WHERE referred_by IS NULL`), deci e un răspuns cinstit la
            // „s-a legat?", nu o presupunere. Panoul îl spune omului: până
            // acum scria „salvat" și atât, iar bifa pusă degeaba arăta la fel
            // cu bifa care a lucrat.
            $legatAcum = referral_link_new_user($buyerId, $cod) !== null;
        }
    } elseif ($contRef !== null) {
        // 2. Fără cod folosibil: recomandantul contului.
        $referrer   = $contRef;
        $codFolosit = $codCont;
    }

    // Auto-recomandare: nu se câștigă din propriile comenzi.
    //
    // A doua poartă, pentru câștigătorul care nu vine din codul tastat, ci din
    // recomandantul contului. Judecă după aceeași regulă ca prima — o funcție,
    // nu două condiții scrise de mână care mâine ar începe să se deosebească.
    //
    // AȘEZAREA E JUMĂTATE DIN REPARAȚIE. Poarta veche stătea la capătul
    // funcției și făcea `return null`, adică ieșea PESTE ramura 4, cea care
    // șterge rândul rămas fără acoperire. Pe o comandă scursă înainte de
    // reparație asta ar fi însemnat: învierea de la 2bis ridică rândul din
    // registru, poarta iese, iar rândul rămâne pe veci „activ", cu suma lui,
    // și nu mai urmărește comanda — nici anularea n-ar mai putea să-l stingă.
    // Comisioanele scurse s-ar fi pietrificat, iar comenzile anulate ar fi
    // continuat să numere bani. Pusă aici, poarta doar golește `$referrer`, iar
    // rândul cade singur în ștergerea de la ramura 4. Proba e în
    // `.claude/test-defect-auto-recomandare.mjs`, scena D.
    if ($referrer !== null) {
        $dupa = referral_potrivire_cumparator((int) $referrer['id'], $o);
        if ($dupa !== null) {
            $refuzuri[] = ['nume' => (string) $referrer['name'], 'dupa' => $dupa];
            $referrer   = null;
            $codFolosit = null;
        }
    }


    // 2bis. Codul nu mai duce nicăieri, dar banii sunt deja câștigați.
    //
    // Un cod încetează să ducă undeva și fără ca cineva să atingă comanda:
    // agentul îl redenumește (`referral_owner()` caută după `referral_code`),
    // sau i se dezactivează contul (`active = 1` stă și în `referral_owner()`,
    // și în interogarea recomandantului de cont — se închid amândouă deodată).
    //
    // Fără rândurile de mai jos, comanda cădea la ramura 4 și rândul NEPLĂTIT
    // se ștergea la prima salvare oarecare: o corectură de adresă, o mutare de
    // etapă, butonul „Șterge comanda" — toate trec prin cârligul de la
    // `api/routes/orders.php:1123`. Comisionul dispărea fără audit și fără ca
    // cineva să apese pe ceva numit așa.
    //
    // Câștigătorul unui rând deja scris e OMUL din `referrer_id`, nu șirul de
    // litere. Deci: dacă comanda poartă mai departe un cod, nu se găsește
    // nimeni în spatele lui, nu există nici recomandant de cont, dar există un
    // rând neplătit — se ia câștigătorul din rând și socoteala merge înainte pe
    // el. Suma urmează totalul comenzii, anularea o stinge, dezanularea o
    // aprinde, ca la oricare altul.
    //
    // Trei limite, ca reparația să nu devină o altă gaură:
    //   * numai `paid_at IS NULL` — suma plătită e înghețată oricum mai jos,
    //     iar un rând plătit n-a fost niciodată în primejdie;
    //   * numai când comanda chiar poartă un cod — omul care scoate codul de pe
    //     comandă trebuie să vadă rândul dispărând, cum a fost gândit;
    //   * numai când rândul are un câștigător scris — un rând de conflict
    //     (`referrer_id` gol) n-are pe cine învia, și nici nu ține bani.
    if ($referrer === null && !$inAsteptare && $cod !== '') {
        $vechi = one(
            'SELECT referrer_id, referrer_label FROM referral_commissions
              WHERE order_id = ? AND paid_at IS NULL AND referrer_id IS NOT NULL',
            [$orderId]
        );
        // A treia oară aceeași întrebare, fiindcă e al treilea drum spre bani:
        // rândul înviat poartă un câștigător scris cândva, iar dacă acela e
        // chiar cumpărătorul, învierea ar readuce la viață exact scurgerea pe
        // care poarta o oprește. Neînviat, rândul cade în ștergerea de mai jos.
        if ($vechi) {
            $dupa = referral_potrivire_cumparator((int) $vechi['referrer_id'], $o);
            if ($dupa === null) {
                $referrer   = ['id' => (int) $vechi['referrer_id'], 'name' => (string) $vechi['referrer_label']];
                $codFolosit = referral_normalize_code($cod);
            } else {
                $refuzuri[] = ['nume' => (string) $vechi['referrer_label'], 'dupa' => $dupa];
            }
        }
    }

    // Textul pentru jurnal, socotit o dată și folosit pe amândouă drumurile de
    // mai jos: ori se șterge rândul și se iese, ori se scrie unul pe altcineva.
    $refuzText = '';
    foreach ($refuzuri as $r) {
        $refuzText .= ($refuzText === '' ? '' : ', ') . $r['nume'] . ' (după ' . $r['dupa'] . ')';
    }
    // 4. Nimeni de premiat, și nici conflict de rezolvat: nu se scrie rând.
    //    Un rând cu referrer NULL și status „activ" ar fi o datorie fără
    //    creditor, iar lista de conflicte s-ar umple de comenzi obișnuite.
    if ($referrer === null && !$inAsteptare) {
        // Dacă exista deja un rând (comanda avea cod, apoi i s-a șters), îl
        // stingem în loc să-l lăsăm să ceară bani pentru o legătură dispărută.
        $vechi = one('SELECT id, paid_at FROM referral_commissions WHERE order_id = ?', [$orderId]);
        $sters = false;
        if ($vechi && $vechi['paid_at'] === null) {
            q('DELETE FROM referral_commissions WHERE id = ? AND paid_at IS NULL', [(int) $vechi['id']]);
            $sters = true;
        }
        // Refuzul nu are voie să fie mut — vezi lămurirea de la
        // `referral_guard_self_code()`. Dar nici gălăgios: cârligul se execută
        // la fiecare atingere a comenzii, inclusiv la mutarea de etapă de pe
        // tableta din laborator, iar un rând de jurnal la fiecare ar îneca
        // jurnalul în același lucru spus de o sută de ori. Se scrie deci numai
        // când chiar s-a întâmplat ceva: cineva tocmai a tastat un cod, sau un
        // rând de comision a fost șters acum.
        if ($refuzuri && ($codTastat !== null || $sters)) {
            audit(
                'referral_self_blocked',
                'order',
                (string) $orderId,
                'Comanda ' . ($o['ref'] ?: '#' . $orderId) . ($sters ? ': comisionul s-a stins' : ': comisionul nu s-a scris')
                  . ', fiindcă cel cu codul e chiar cumpărătorul — ' . $refuzText . '.',
                $actorId
            );
        }
        return null;
    }

    // Aici se ajunge numai când un rând CHIAR se scrie. Dacă o poartă a lucrat
    // totuși pe drumul până aici — codul tastat era propriu, iar comanda a
    // căzut pe recomandantul contului — omul trebuie să afle, fiindcă banii au
    // plecat la altcineva decât la cel scris în formular.
    if ($refuzuri && $codTastat !== null) {
        audit(
            'referral_self_blocked',
            'order',
            (string) $orderId,
            'Comanda ' . ($o['ref'] ?: '#' . $orderId) . ': codul tastat n-a fost luat în seamă, '
              . 'fiindcă e chiar al cumpărătorului — ' . $refuzText . '.',
            $actorId
        );
    }

    // Rândul de până acum se citește ÎNAINTE de socoteală, fiindcă socoteala
    // are nevoie de el: procentul cu care s-a câștigat stă scris acolo.
    $exist = one('SELECT * FROM referral_commissions WHERE order_id = ?', [$orderId]);

    $base = round((float) $o['total'], 2);

    // PROCENTUL SE ÎNGHEAȚĂ LA NAȘTEREA RÂNDULUI CARE CHIAR CÂȘTIGĂ.
    //
    // `referral_rate_for()` citește contul de ACUM și, dacă acolo e gol, setarea
    // globală de ACUM. Recitit la fiecare sincronizare, el rescria rândurile
    // vechi: patronul urca procentul în octombrie, iar comenzile neplătite din
    // septembrie treceau pe procentul nou — dar numai acelea pe care se
    // întâmplase să le atingă cineva, fiindcă numai atingerea cheamă cârligul
    // (`api/routes/orders.php:1123`, inclusiv mutarea de etapă). Două comenzi
    // din aceeași zi, cu același agent, ajungeau cu procente diferite. Cifra de
    // pe ecran atârna de un lucru fără nicio legătură cu banii. Iar procentul
    // coborât fura din câștigul deja făcut.
    //
    // Ce NU se îngheață, dinadins:
    //   * SUMA — dacă se schimbă cantitățile, baza se mișcă, deci și suma; dar
    //     la procentul de atunci, nu la cel de azi;
    //   * rândul ÎN AȘTEPTARE — un conflict n-a câștigat nimic, iar `rate` e 0
    //     acolo dinadins. Înghețat, zeroul acela ar rămâne pe rândul rezolvat,
    //     și conflictul s-ar sfârși cu un comision de zero lei;
    //   * rândul al cărui CÂȘTIGĂTOR se schimbă — procentul e al omului, nu al
    //     comenzii. Altfel înghețul ar fi o portiță prin care cine primește o
    //     comandă mutată capătă procentul celui de dinaintea lui.
    $rate = $inAsteptare ? 0.0 : referral_rate_for($referrer['id']);
    if (
        !$inAsteptare
        && $exist
        && $exist['paid_at'] === null
        && (string) $exist['status'] !== 'in_asteptare'
        && $exist['referrer_id'] !== null
        && (int) $exist['referrer_id'] === (int) $referrer['id']
    ) {
        $rate = referral_clamp_rate((float) $exist['rate']);
    }

    // Zero nu e lene: face „nu se numără pentru nimeni" adevărat în SQL, deci
    // SUM(amount) WHERE status='activ' e singurul ban din sistem, fără excepții
    // de ținut minte. `base_total` rămâne real, ca patronul să vadă miza.
    $amount = $inAsteptare ? 0.0 : round($base * $rate / 100, 2);
    $status = $eAnulata ? 'anulat' : ($inAsteptare ? 'in_asteptare' : 'activ');

    $cimp = [
        'order_ref'      => $o['ref'] !== null ? (string) $o['ref'] : null,
        'order_day'      => (string) $o['day'],
        'referrer_id'    => $referrer['id'] ?? null,
        'referrer_label' => $referrer['name'] ?? null,
        'buyer_id'       => $buyerId,
        'buyer_label'    => $o['customer_name'] !== null ? (string) $o['customer_name'] : null,
        'code_used'      => $codFolosit,
        'code_account'   => $inAsteptare ? $codCont : null,
        'code_typed'     => $inAsteptare ? referral_normalize_code($cod) : null,
        'rate'           => $rate,
        'base_total'     => $base,
        'amount'         => $amount,
        'status'         => $status,
    ];

    if (!$exist) {
        try {
            q(
                'INSERT INTO referral_commissions
                   (order_id, order_ref, order_day, referrer_id, referrer_label, buyer_id,
                    buyer_label, code_used, code_account, code_typed, rate, base_total,
                    amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge([$orderId], array_values($cimp))
            );
        } catch (PDOException $e) {
            // 1062 = altcineva a scris rândul între SELECT și INSERT. Asta e
            // exact ce apără `UNIQUE (order_id)`, iar reluarea tranzacției din
            // `route_orders_create` o poate produce. Trecem pe drumul de
            // actualizare în loc să stricăm comanda.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $exist = one('SELECT * FROM referral_commissions WHERE order_id = ?', [$orderId]);
        }
    }

    if ($exist) {
        $platit = $exist['paid_at'] !== null;
        if ($platit) {
            // O sumă deja plătită NU se rescrie sub mâna nimănui. Starea, da:
            // „anulat, dar deja plătit" e tocmai starea din care patronul își
            // poate cere banii înapoi. Diferența se scrie în jurnal, ca ea să
            // nu se piardă tăcut.
            //
            // ȘI CELE DOUĂ CODURI, tot da. Ele nu sunt bani: sunt mărturia
            // despre CINE SE CEARTĂ pe rândul ăsta. Scrise numai pe ramura
            // neplătită, un conflict născut DUPĂ plată rămânea fără ele —
            // starea trecea pe „in_asteptare", dar `code_account` și
            // `code_typed` rămâneau goale. Iar `referral_commission_resolve()`
            // își face lista celor cu drept tocmai din ele:
            // `array_filter([$c['code_account'], $c['code_typed']])`. Goală,
            // lista refuză ORICE cod cu 422 `not_in_conflict` — inclusiv cele
            // două adevărate. Butoanele de pe card erau acolo, se apăsau, și nu
            // se putea alege nimeni; nici ștergerea nu era o ieșire
            // (409 `commission_paid`). Comisionul rămânea înțepenit în
            // așteptare pe vecie, plătit, fără nicio cale de ieșire.
            //
            // Suma rămâne înghețată, cum scrie mai sus: în UPDATE intră starea
            // și codurile; `amount` și `rate` nu, niciodată.
            //
            // Se scrie și când numai codurile s-au schimbat — altfel mărturia
            // ar rămâne cea de acum două conflicte. Jurnalul, în schimb, e al
            // stării: intrarea în conflict e faptul care se ține minte, iar
            // codurile se văd pe card oricând.
            $altCod = $exist['code_account'] !== $cimp['code_account']
                || $exist['code_typed'] !== $cimp['code_typed'];
            $altaStare = (string) $exist['status'] !== $status;
            if ($altaStare || $altCod) {
                q(
                    'UPDATE referral_commissions
                        SET status = ?, code_account = ?, code_typed = ?
                      WHERE id = ?',
                    [$status, $cimp['code_account'], $cimp['code_typed'], (int) $exist['id']]
                );
                if ($altaStare) {
                    audit(
                        'referral_commission_frozen',
                        'commission',
                        (string) $exist['id'],
                        'Comanda ' . ($o['ref'] ?: '#' . $orderId) . ': starea a trecut pe „' . $status
                          . '", dar comisionul de ' . number_format((float) $exist['amount'], 2, ',', '')
                          . ' RON e deja plătit — suma rămâne neschimbată.',
                        $actorId
                    );
                }
            }
        } else {
            q(
                'UPDATE referral_commissions
                    SET order_ref = ?, order_day = ?, referrer_id = ?, referrer_label = ?,
                        buyer_id = ?, buyer_label = ?, code_used = ?, code_account = ?,
                        code_typed = ?, rate = ?, base_total = ?, amount = ?, status = ?
                  WHERE id = ? AND paid_at IS NULL',
                array_merge(array_values($cimp), [(int) $exist['id']])
            );
        }
    }

    $rand = one('SELECT * FROM referral_commissions WHERE order_id = ?', [$orderId]);
    if ($rand !== null) {
        $rand['legat_acum'] = $legatAcum;
    }
    return $rand;
}

/**
 * Poarta care apără un rând SCOS DIN SOCOTEALĂ de un cod tastat pe comandă.
 *
 * Fără ea, omul tasta un cod pe o comandă al cărei comision fusese scos, apăsa
 * „Salvează", și nu se întâmpla nimic: codul se scria pe comandă, rândul rămânea
 * scos, iar pe ecran nu apărea niciun comision. Adică aceeași tăcere pe care
 * scoaterea o repară — mutată în alt loc.
 *
 * Refuzul e îngust, ca la sora ei de mai jos: numai când codul CHIAR se schimbă.
 * Cine retrimite același cod (formularul salvează la ieșirea din casetă și
 * trimite și câmpuri neatinse) trece nestingherit, iar restul câmpurilor unei
 * comenzi cu rândul scos — adresa, cantitățile, etapa — rămân editabile.
 *
 * Se cheamă ÎNAINTE de orice scriere, din același motiv ca celelalte două: un
 * refuz de la mijlocul salvării ar lăsa comanda pe jumătate corectată.
 */
function referral_guard_dismissed_code(int $orderId, string $codNou): void
{
    if (!referral_dismiss_supported() || $orderId <= 0) {
        return;
    }
    $c = one(
        'SELECT c.id, c.order_ref, o.referral_code
           FROM referral_commissions c JOIN orders o ON o.id = c.order_id
          WHERE c.order_id = ? AND c.dismissed_at IS NOT NULL',
        [$orderId]
    );
    if (!$c) {
        return;
    }
    // Același cod, retrimis: nu s-a schimbat nimic, deci nu se refuză nimic.
    if (referral_normalize_code((string) ($c['referral_code'] ?? '')) === referral_normalize_code($codNou)) {
        return;
    }
    fail(
        'Comisionul comenzii ' . ($c['order_ref'] ?: '#' . $orderId)
          . ' e scos din socoteală, deci un cod nou n-ar schimba nimic. '
          . 'Adu-l întâi înapoi din „Recomandări", apoi schimbă codul.',
        409,
        'commission_dismissed'
    );
}

/**
 * Poarta care apără un comision DEJA PLĂTIT de o schimbare de cod.
 *
 * Suma plătită e înghețată dinadins în `referral_commission_sync()`: o
 * afirmație „i-am dat banii" nu se rescrie singură. Dar înghețarea apără suma,
 * nu NUMELE. Lăsat liber, codul schimbat pe o comandă plătită ar muta
 * câștigătorul, iar comanda ar spune un om și chitanța altul — cu banii deja
 * ieșiți din casă către al treilea.
 *
 * Refuzul e îngust dinadins: numai când noul cod chiar duce la ALT câștigător.
 * Cine rescrie același cod cu majuscule, sau îl pune pe cel al omului deja
 * plătit, trece; restul câmpurilor unei comenzi plătite rămân editabile.
 *
 * Se cheamă ÎNAINTE de orice scriere: un refuz de la mijlocul salvării ar lăsa
 * comanda pe jumătate corectată.
 *
 * DOUĂ UȘI, O SINGURĂ LEGE. Paza asta se cheamă din două locuri care nu seamănă
 * deloc între ele:
 *
 *   · `PATCH /orders` (api/routes/orders.php) — patronul schimbă codul de pe o
 *     comandă, din formularul de editare;
 *   · `POST /referrals/#/resolve` (api/lib/referral.php, `referral_commission_resolve()`)
 *     — patronul hotărăște, din „Recomandări", care din doi agenți câștigă un
 *     conflict.
 *
 * A doua ușă n-a avut-o multă vreme, și de-aia hotărârea luată pe un rând deja
 * plătit trecea cu 200: codul se muta pe comandă, contul cumpărătorului se lega
 * de noul agent, jurnalul scria că s-a hotărât — iar suma, înghețată dinadins,
 * rămânea a primului. De atunci comanda spunea un nume și plata altul, și
 * nimeni n-avea de unde să afle, fiindcă amândouă ecranele arătau adevărul lor.
 *
 * Ultima propoziție a mesajului se poate schimba (`$urmatorulPas`) fiindcă cele
 * două uși se apasă din ecrane diferite; legea — cine câștigă ACUM și când se
 * refuză — rămâne scrisă o singură dată, aici.
 */
function referral_guard_paid_code_change(int $orderId, string $codNou, string $urmatorulPas = 'apoi pune codul'): void
{
    if (!referral_money_supported() || $orderId <= 0) {
        return;
    }

    $rand = one(
        'SELECT id, referrer_id, referrer_label, amount, paid_at
           FROM referral_commissions WHERE order_id = ?',
        [$orderId]
    );
    if (!$rand || $rand['paid_at'] === null) {
        return;
    }

    // Cumpărătorul se recitește din bază, nu se ia din rândul de comision:
    // acolo e cine era la plată, iar întrebarea de aici e cine ar câștiga ACUM.
    // Se citesc aceleași coloane ca în cârlig, fiindcă se pune aceeași
    // întrebare, cu aceeași funcție: două paze care judecă altfel ar fi
    // însemnat, mâine, două purtări diferite pe aceeași comandă.
    $o       = one(
        'SELECT customer_id, customer_name, phone, request_id FROM orders WHERE id = ?',
        [$orderId]
    );
    $buyerId = $o && $o['customer_id'] !== null ? (int) $o['customer_id'] : null;

    // Aceleași două ramuri ca la sincronizare: codul, dacă duce la un cont bun
    // și nu e chiar al cumpărătorului; altfel recomandantul legat de cont.
    // Ramura de conflict nu se rescrie aici — un conflict înseamnă oricum că
    // rândul plătit nu mai are acoperire, deci cade tot pe refuz.
    $castigator = null;
    $ownerCod   = $codNou === '' ? null : referral_owner($codNou);
    if ($ownerCod !== null && $o && referral_potrivire_cumparator((int) $ownerCod['id'], $o) !== null) {
        $ownerCod = null;
    }
    if ($ownerCod !== null) {
        $castigator = (int) $ownerCod['id'];
    } elseif ($buyerId !== null) {
        $cont = one(
            'SELECT r.id FROM users u JOIN users r ON r.id = u.referred_by
              WHERE u.id = ? AND r.active = 1 AND r.is_system = 0',
            [$buyerId]
        );
        $castigator = $cont ? (int) $cont['id'] : null;
    }

    $platitCatre = $rand['referrer_id'] !== null ? (int) $rand['referrer_id'] : null;
    if ($castigator === $platitCatre) {
        return;
    }

    fail(
        'Comisionul acestei comenzi e deja plătit lui '
          . ($rand['referrer_label'] ?: 'cineva') . ' ('
          . number_format((float) $rand['amount'], 2, ',', '')
          . ' RON), deci codul nu se mai poate schimba: comanda ar arăta un nume, '
          . 'iar banii au plecat deja la altul. Scoate întâi bifa de plată din '
          . '„Recomandări", ' . $urmatorulPas . '.',
        409,
        'commission_paid'
    );
}

/**
 * Bifa „i-am dat banii", pusă sau scoasă, pe unul sau pe mai multe rânduri.
 *
 * Se poate și SCOATE, dinadins: bifa e o afirmație a unui om obosit la
 * sfârșitul lunii, iar o afirmație greșită trebuie să se poată retrage. Fără
 * asta, singura reparație ar fi prin phpMyAdmin.
 *
 * @param  int[] $ids
 * @return int   câte rânduri s-au schimbat
 */
function referral_commission_pay(array $ids, bool $paid, int $adminId): int
{
    if (!referral_money_supported()) {
        return 0;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($x) => $x > 0)));
    if (!$ids) {
        return 0;
    }

    $in = implode(',', array_fill(0, count($ids), '?'));

    // Rândurile se citesc înainte de orice: bifa are nevoie să știe pe CE se
    // pune, iar scoaterea ei are nevoie de comenzile pe care le atinge.
    // Rândurile scoase din socoteală nu intră nici măcar în citire: ele nu se
    // pot plăti, deci nici nu se pot opri din plată, deci n-au ce căuta în
    // socoteala de mai jos. Pagina nu le trimite oricum (bifa nu se desenează
    // pe ele), iar filtrul de aici e pentru cealaltă cale: un curl, sau doi
    // administratori care apasă în aceeași secundă.
    $randuri = all(
        'SELECT id, order_id, order_ref, status, paid_at
           FROM referral_commissions WHERE id IN (' . $in . ')'
          . referral_dismiss_where(),
        $ids
    );

    // NU SE PLĂTEȘTE CE NU SE DATOREAZĂ.
    //
    // Poarta lipsea cu totul: `WHERE` avea doar `paid_at IS NULL`. Deci
    // comisionul unei comenzi ANULATE se putea bifa „plătit" dintr-o apăsare
    // greșită, iar de-acolo încolo nimeni nu mai vedea nimic: rândul anulat nu
    // intră în „Câștigat", iar suma ieșită din casă rămânea o cifră fără nicio
    // placă. La fel rândul ÎN AȘTEPTARE: e un conflict nehotărât, cu suma 0
    // dinadins — o bifă pe el ar fi însemnat „i-am dat banii" pentru un om care
    // nici măcar nu s-a ales încă.
    //
    // Refuzul e întreg, nu pe jumătate: dacă un singur rând din listă nu se
    // cuvine, nu se bifează niciunul. Un refuz parțial i-ar spune omului
    // „gata", iar el ar crede că a plătit tot.
    if ($paid) {
        $oprite = [];
        foreach ($randuri as $r) {
            if ($r['paid_at'] !== null || (string) $r['status'] === 'activ') {
                continue;
            }
            $oprite[] = ($r['order_ref'] ?: '#' . (int) $r['order_id'])
                . ((string) $r['status'] === 'anulat' ? ' (comandă anulată)' : ' (conflict nehotărât)');
        }
        if ($oprite) {
            fail(
                'Nu se pot bifa ca plătite: ' . implode(', ', array_slice($oprite, 0, 5))
                  . (count($oprite) > 5 ? ' și încă ' . (count($oprite) - 5) : '')
                  . '. Un comision stins sau nehotărât nu se datorează nimănui.',
                409,
                'commission_not_payable'
            );
        }
    }

    // Condiția pe `paid_at` face operația idempotentă: două apăsări pe același
    // buton (sau două tablete deodată) nu schimbă de două ori nimic, iar
    // numărul întors spune cât s-a schimbat CU ADEVĂRAT, nu câte rânduri s-au
    // atins. Asta e ce ajunge în jurnal.
    $st = $paid
        ? q(
            'UPDATE referral_commissions SET paid_at = UTC_TIMESTAMP(), paid_by = ?
              WHERE id IN (' . $in . ') AND paid_at IS NULL'
              . referral_dismiss_where(),
            array_merge([$adminId], $ids)
        )
        : q(
            'UPDATE referral_commissions SET paid_at = NULL, paid_by = NULL
              WHERE id IN (' . $in . ') AND paid_at IS NOT NULL'
              . referral_dismiss_where(),
            $ids
        );

    $n = $st->rowCount();

    // BIFA SCOASĂ DEZGHEAȚĂ RÂNDUL, DECI TREBUIE ȘI RECITIT.
    //
    // Cât e plătit, rândul e înghețat dinadins: `referral_commission_sync()`
    // îi scrie doar starea, nu și cifrele. Dacă între timp comanda s-a
    // îngroșat, rândul rămâne pe baza și suma de la plată — corect, atâta timp
    // cât banii sunt daţi. Dar în clipa în care bifa se scoate, îngheţul n-are
    // niciun temei, iar cifrele vechi nu se mai potriveau cu comanda de sub
    // ele: pagina arăta o bază care nu mai era a nimănui, până când se
    // întâmpla să atingă cineva comanda.
    //
    // `false` la urmă înseamnă „nu lega contul cumpărătorului": scoaterea unei
    // bife nu e clipa în care se scrie o legătură „pentru totdeauna".
    if (!$paid && $n > 0) {
        foreach ($randuri as $r) {
            if ($r['paid_at'] === null) {
                continue;
            }
            try {
                referral_commission_sync((int) $r['order_id'], null, $adminId, false);
            } catch (Throwable $e) {
                error_log('[gp] comision nerecitit pentru comanda ' . (int) $r['order_id'] . ': ' . $e->getMessage());
            }
        }
    }

    if ($n > 0) {
        audit(
            $paid ? 'referral_pay' : 'referral_unpay',
            'commission',
            implode(',', array_slice($ids, 0, 10)),
            ($paid ? 'Bifate ca plătite: ' : 'Bifa de plată scoasă de pe: ') . $n . ' comisioane',
            $adminId
        );
    }
    return $n;
}

/**
 * Conflictul, hotărât de patron: codul tastat sau recomandantul contului.
 *
 * Alegerea prinde ACEASTĂ comandă și, de-atunci înainte, toate comenzile
 * omului — fiindcă `referral_commission_sync()` scrie codul ales pe comandă și,
 * dacă contul n-avea recomandant, îl leagă. Contul care ARE deja un recomandant
 * și-l schimbă abia aici, explicit, o singură dată: e singurul loc din tot codul
 * unde `referred_by` se rescrie peste o valoare existentă.
 *
 * @param  string $cod codul câștigător, așa cum e scris în rând
 * @return array|null  rândul rezolvat
 */
function referral_commission_resolve(int $id, string $cod, int $adminId): ?array
{
    if (!referral_money_supported()) {
        fail('Comisioanele nu sunt pornite pe serverul acesta.', 409, 'referral_money_off');
    }
    $c = one('SELECT * FROM referral_commissions WHERE id = ?', [$id]);
    if (!$c) {
        fail('Comisionul nu există.', 404, 'not_found');
    }
    if ((string) $c['status'] !== 'in_asteptare') {
        fail('Comisionul nu e în așteptare — nu are ce se rezolva.', 409, 'not_pending');
    }
    // Un rând scos din socoteală n-are conflict de hotărât: hotărârea trece
    // prin `referral_commission_sync()`, iar acolo poarta de sus îl lasă în
    // pace și iese cu null. Alegerea ar fi părut că s-a făcut, dar recomandantul
    // contului s-ar fi rescris (asta se întâmplă ÎNAINTE de cârlig), iar rândul
    // ar fi rămas neatins. Ordinea corectă se spune pe față.
    if (($c['dismissed_at'] ?? null) !== null) {
        fail(
            'Comisionul e scos din socoteală. Adu-l întâi înapoi, apoi hotărăște conflictul.',
            409,
            'commission_dismissed'
        );
    }

    $curat = referral_normalize_code($cod);
    $ales  = $curat !== '' ? referral_owner($curat) : null;
    if ($ales === null) {
        fail('Codul „' . $cod . '" nu duce la niciun cont activ.', 422, 'bad_code');
    }
    // Numai între cei doi din rând. Un `code` oarecare trimis prin curl ar muta
    // comisionul pe un al treilea om, care n-a avut nicio legătură cu comanda.
    $permise = array_filter([$c['code_account'], $c['code_typed']]);
    if (!in_array($curat, array_map('strval', $permise), true)) {
        fail('Codul trebuie să fie unul dintre cele două din conflict.', 422, 'not_in_conflict');
    }

    // ACEEAȘI PAZĂ CA LA EDITAREA COMENZII, chemată înainte de prima scriere.
    //
    // Un rând plătit poate ajunge în conflict: de când codurile se scriu și pe
    // rândul plătit, el chiar arată cele două nume între care se alege. Numai că
    // suma lui e înghețată dinadins — bifa de plată e o afirmație despre bani
    // care au plecat din casă. Deci hotărârea „câștigă celălalt" muta tot ce se
    // putea muta (codul de pe comandă, recomandantul contului, rândul din
    // Jurnal) și lăsa pe loc singurul lucru care contează. Comanda spunea un
    // nume, plata altul, și fiecare ecran arăta adevărul lui.
    //
    // Refuzul e îngust, ca la cealaltă ușă: hotărârea în favoarea chiar a celui
    // deja plătit trece, fiindcă nu schimbă nimic. Numai mutarea pe alt om se
    // oprește, cu 409 și cu drumul scris: scoate întâi bifa de plată, pe urmă
    // hotărăște. Bifa se poate scoate — asta e cheia: nu e o fundătură, e o
    // ordine.
    referral_guard_paid_code_change((int) $c['order_id'], $curat, 'apoi hotărăște conflictul');

    $buyerId = $c['buyer_id'] !== null ? (int) $c['buyer_id'] : null;
    if ($buyerId !== null) {
        // Singurul loc unde recomandantul unui cont se rescrie peste unul
        // existent. Deliberat, și numai la o hotărâre omenească.
        q('UPDATE users SET referred_by = ?, referred_at = UTC_TIMESTAMP() WHERE id = ?',
            [$ales['id'], $buyerId]);
    }
    q('UPDATE orders SET referral_code = ? WHERE id = ?', [$curat, (int) $c['order_id']]);

    audit(
        'referral_resolve',
        'commission',
        (string) $id,
        'Conflict rezolvat pe comanda ' . ($c['order_ref'] ?: '#' . (int) $c['order_id'])
          . ' în favoarea codului „' . $curat . '" (' . $ales['name'] . ')',
        $adminId
    );

    // Recalcularea trece tot prin cârligul unic: aceeași socoteală, aceleași
    // reguli. Scris de mână aici, un procent ar fi putut ieși altfel decât la
    // creare, și nimeni n-ar fi știut de ce.
    return referral_commission_sync((int) $c['order_id'], $curat, $adminId);
}

/**
 * Rândul scos din socoteală — sau adus înapoi. (migrarea 012)
 *
 * DE CE NU E UN DELETE e scris pe larg în sql/012_comision_scos.sql și în
 * poarta de sus a lui `referral_commission_sync()`; pe scurt: rândul e o
 * consecință recalculată la fiecare atingere a comenzii, deci șters de-a
 * dreptul s-ar fi scris la loc singur. Se ține minte FAPTUL că a fost scos, iar
 * un fapt scris nu se recalculează.
 *
 * Cele două apăsări sunt aceeași funcție, cu un steag, exact ca la bifa de
 * plată: o hotărâre a unui om obosit trebuie să se poată retrage, iar dacă
 * retragerea ar sta în alt cod, cele două s-ar depărta cu prima reparație.
 *
 * @param  bool $scos true = scoate-l, false = adu-l înapoi
 * @return array|null rândul de după, sau null dacă aducerea înapoi a arătat că
 *                    nu mai are pe cine premia și cârligul l-a stins
 */
function referral_commission_dismiss(int $id, bool $scos, int $adminId): ?array
{
    if (!referral_money_supported()) {
        fail('Comisioanele nu sunt pornite pe serverul acesta.', 409, 'referral_money_off');
    }
    if (!referral_dismiss_supported()) {
        fail(
            'Scoaterea unui comision are nevoie de sql/012_comision_scos.sql, '
              . 'care nu s-a importat încă.',
            503,
            'referral_dismiss_unavailable'
        );
    }
    $c = one('SELECT * FROM referral_commissions WHERE id = ?', [$id]);
    if (!$c) {
        fail('Comisionul nu există.', 404, 'not_found');
    }

    // UN COMISION PLĂTIT NU SE SCOATE DIN SOCOTEALĂ.
    //
    // Banii au ieșit din casă, iar rândul care spune „i-am dat 340 de lei" e o
    // chitanță; o chitanță nu se face nevăzută fiindcă cineva s-a răzgândit.
    // Ordinea corectă e cea din viață: se scoate întâi bifa de plată — adică se
    // recunoaște pe față că plata n-a fost datorată — abia apoi rândul.
    // Refuzul o spune, ca omul să n-o caute singur.
    //
    // De aici iese și o liniște mai mare: „plătit" și „scos" nu se pot
    // suprapune niciodată, deci nicio socoteală de bani n-are de ales între ele.
    if ($c['paid_at'] !== null && $scos) {
        fail(
            'Comisionul e bifat ca plătit. Scoate întâi bifa de plată din „Recomandări", '
              . 'apoi scoate rândul.',
            409,
            'commission_paid'
        );
    }

    if (($c['dismissed_at'] !== null) === $scos) {
        // A doua apăsare pe același buton — două tablete deodată, sau o
        // reîncărcare grăbită. Nu se schimbă nimic și nu se scrie de două ori
        // în jurnal.
        return $c;
    }

    if ($scos) {
        q(
            'UPDATE referral_commissions SET dismissed_at = UTC_TIMESTAMP(), dismissed_by = ?
              WHERE id = ? AND paid_at IS NULL AND dismissed_at IS NULL',
            [$adminId, $id]
        );
        audit(
            'referral_dismiss',
            'commission',
            (string) $id,
            'Comanda ' . ($c['order_ref'] ?: '#' . (int) $c['order_id'])
              . ': comisionul a fost scos din socoteală ('
              . ($c['referrer_label'] ?: 'fără câștigător, conflict nehotărât') . ' · '
              . number_format((float) $c['amount'], 2, ',', '') . ' RON).',
            $adminId
        );
        return one('SELECT * FROM referral_commissions WHERE id = ?', [$id]);
    }

    q(
        'UPDATE referral_commissions SET dismissed_at = NULL, dismissed_by = NULL
          WHERE id = ? AND dismissed_at IS NOT NULL',
        [$id]
    );
    audit(
        'referral_restore',
        'commission',
        (string) $id,
        'Comanda ' . ($c['order_ref'] ?: '#' . (int) $c['order_id'])
          . ': comisionul a fost adus înapoi în socoteală.',
        $adminId
    );

    // ADUS ÎNAPOI ÎNSEAMNĂ ȘI RECITIT.
    //
    // Cât a stat scos, cârligul nu s-a atins de rând — asta e chiar rostul
    // porții. Între timp comanda putea să se îngroașe, să se anuleze, să-și
    // piardă codul. Cifrele de pe rând sunt cele de la data scoaterii, iar
    // lăsate așa ar arăta o bază care nu mai e a nimănui, până când s-ar
    // întâmpla să atingă cineva comanda. Aceeași grijă ca la bifa de plată
    // scoasă, din același motiv.
    //
    // `false` la urmă înseamnă „nu lega contul cumpărătorului": aducerea înapoi
    // a unui rând nu e clipa în care se scrie o legătură „pentru totdeauna".
    //
    // Recitirea poate și să STINGĂ rândul, dacă între timp comanda a rămas fără
    // cod și fără recomandant de cont: atunci ramura 4 îl șterge, iar funcția
    // întoarce null. E răspunsul corect, nu o eroare — pagina se reîncarcă și
    // rândul chiar nu mai are de ce să fie acolo.
    try {
        referral_commission_sync((int) $c['order_id'], null, $adminId, false);
    } catch (Throwable $e) {
        error_log('[gp] comision nerecitit după aducerea înapoi ' . $id . ': ' . $e->getMessage());
    }

    return one('SELECT * FROM referral_commissions WHERE id = ?', [$id]);
}

/* ================================================ îndreptarea zerourilor ===
 * COMISIOANELE NĂSCUTE LA PROCENT ZERO, ÎNDREPTATE LA APĂSAREA PATRONULUI
 *
 * DE UNDE VINE NEVOIA. Migrarea 008 a semănat `referral_rate_default` cu „0",
 * și avea un motiv scris negru pe alb acolo: o cifră inventată de noi ar fi
 * născut, din prima comandă de după actualizare, o datorie de bani pe care
 * n-o hotărâse nimeni. Motivul era bun. Urmarea nu: pe serverul patronului trei
 * comenzi au venit prin cod de recomandare, s-au legat corect, s-au numărat
 * corect, și au ieșit pe ecran cu 0,00 RON. Panoul „Recomandări" arăta un
 * sistem care merge perfect și nu face nimic.
 *
 * `sql/017_procent_recomandare.sql` a ridicat setarea la 5 pentru ce vine de
 * acum încolo. Ce s-a născut înainte NU se poate îndrepta de acolo: procentul
 * se îngheață la nașterea rândului (v. „PROCENTUL SE ÎNGHEAȚĂ LA NAȘTEREA
 * RÂNDULUI CARE CHIAR CÂȘTIGĂ", în `referral_commission_sync()`). Înghețul e
 * bun și rămâne — el apără un câștig deja făcut de o răscolire a procentului.
 * Numai că el a înghețat și zerourile, iar cele trei comenzi ar fi rămas
 * 0,00 RON pe veci, oricât s-ar fi pus în Setări după aceea.
 *
 * DE CE UN BUTON, ȘI NU O MIGRARE. O migrare care trece tăcută peste bani e
 * tocmai lucrul de care ne ferim: nimeni n-ar fi văzut pe cine atinge și cu
 * cât, iar a doua zi cifrele de pe ecran ar fi fost altele fără ca vreun om să
 * fi hotărât ceva. Aici se VEDE întâi — `referral_zero_rate_preview()` — se
 * apasă pe urmă — `referral_zero_rate_fix()` — și rămâne scris în jurnal cine
 * a apăsat, când, câte rânduri și de la ce la ce.
 *
 * CELE PATRU MARGINI, și de ce fiecare:
 *
 *   * `rate = 0`         — numai rândul bolnav. Un rând cu procent adevărat e
 *                          o hotărâre a cuiva, nu o scăpare, și nu se atinge;
 *   * `paid_at IS NULL`  — un rând plătit e o chitanță: „i-am dat 340 de lei".
 *                          Nu se rescrie, și nici măcar nu se CITEȘTE aici — o
 *                          previzualizare care l-ar arăta printre cele de
 *                          îndreptat ar fi o promisiune mincinoasă;
 *   * `status = 'activ'` — „în așteptare" e un conflict pe care nu l-a hotărât
 *                          încă niciun om, iar zeroul lui e pus dinadins (nu se
 *                          numără pentru nimeni, până se alege câștigătorul);
 *                          „anulat" s-a stins de tot;
 *   * procentul de azi > 0 — un rând care ar trece de la 0% la 0% n-are ce
 *                          îndrepta. Fără marginea asta, butonul ar răsări și
 *                          pe un server cu procentul casei tot zero, iar
 *                          apăsat n-ar face nimic — adică al doilea fel de
 *                          tăcere, după cel pe care tocmai îl reparăm.
 *
 * PROCENTUL E AL OMULUI, NU AL CASEI. Se cere `referral_rate_for()` pe fiecare
 * câștigător în parte, exact ca la nașterea rândului. O singură cifră globală
 * lipită peste toți ar fi șters procentul propriu al contului — pus din
 * „Conturi" tocmai fiindcă e altul decât al casei — și ar fi plătit greșit
 * chiar pe omul pentru care s-a făcut o excepție.
 * ========================================================================== */

/** Câte rânduri se iau într-o apăsare. Vezi lămurirea din funcția de mai jos. */
const GP_REFERRAL_FIX_LIMIT = 500;

/**
 * Rândurile de îndreptat, cu socoteala de după, gata de arătat pe ecran.
 *
 * NU SCHIMBĂ NIMIC: e chiar previzualizarea pe care a cerut-o patronul înainte
 * de apăsat — cine încasează, ce comandă, ce bază, procentul de azi și suma
 * nouă. Scrisă o singură dată, și folosită și de scriere: dacă lista arătată ar
 * fi altceva decât lista atinsă, butonul ar fi o surpriză, nu o unealtă.
 *
 * @return array{rows: array, count: int, total: float, truncated: bool}
 */
function referral_zero_rate_preview(): array
{
    if (!referral_money_supported()) {
        return ['rows' => [], 'count' => 0, 'total' => 0.0, 'truncated' => false];
    }

    // Coloana scoaterii se cere numai dacă există. Pe o bază fără migrarea 012
    // însuși NUMELE ei e o eroare de SQL, nu o comparație care iese falsă —
    // aceeași grijă ca în `referral_dismiss_cond()`, din același motiv.
    //
    // RÂNDUL SCOS DIN SOCOTEALĂ RĂMÂNE ÎN LISTĂ, dinadins. Are toate cele trei
    // semne cerute de patron — procent zero, neplătit, „activ" — iar sărit,
    // ar fi rămas cu zeroul înghețat pe el. Adus înapoi mâine din butonul lui,
    // cârligul îi recitește rândul și găsește tot 0%, fiindcă înghețul se uită
    // la procentul SCRIS, nu la vârsta lui: aceeași boală, mutată într-un colț
    // în care nu se mai uită nimeni. Pleacă deci cu steagul lui, iar pagina îl
    // arată tăiat, ca previzualizarea să spună adevărul întreg.
    $colScos = referral_dismiss_supported() ? ', c.dismissed_at' : '';

    // Tăiat la 500 și nu la nesfârșit: scrierea de sub el face un UPDATE pe
    // fiecare rând (suma se socotește în PHP, ca să iasă cifră cu cifră ce s-a
    // arătat pe ecran). Cinci sute e mult peste orice s-a văzut vreodată —
    // pățania de față avea TREI rânduri — dar pune o margine faptei, iar
    // `truncated` spune pe față când a doua apăsare mai are ce îndrepta.
    $randuri = all(
        'SELECT c.id, c.order_id, c.order_ref, c.order_day, c.referrer_id,
                c.referrer_label, c.buyer_label, c.base_total, c.amount' . $colScos . '
           FROM referral_commissions c
          WHERE c.rate = 0 AND c.paid_at IS NULL AND c.status = ?
          ORDER BY c.order_day DESC, c.id DESC
          LIMIT ' . GP_REFERRAL_FIX_LIMIT,
        ['activ']
    );

    // Procentul se ține minte pe câștigător: douăzeci de rânduri ale aceluiași
    // om ar fi altfel douăzeci de drumuri la bază pentru același răspuns.
    $procent = [];
    $out     = [];
    $total   = 0.0;

    foreach ($randuri as $r) {
        $cine = $r['referrer_id'] !== null ? (int) $r['referrer_id'] : 0;
        if (!array_key_exists($cine, $procent)) {
            // Zero înseamnă aici „rândul n-are câștigător scris". Nu se poate
            // întâmpla pe un rând „activ", dar dacă s-ar întâmpla, `null` duce
            // la procentul casei — același răspuns pe care l-ar da și cârligul.
            $procent[$cine] = referral_rate_for($cine > 0 ? $cine : null);
        }
        $nou = $procent[$cine];

        // 0% → 0% nu e o îndreptare. Rândul rămâne bolnav, dar leacul nu e aici,
        // ci în Setări → General: cât timp procentul casei e zero, orice apăsare
        // ar scrie aceleași zerouri la loc, iar omul ar crede că a reparat ceva.
        if ($nou <= 0) {
            continue;
        }

        $baza = round((float) $r['base_total'], 2);
        $suma = round($baza * $nou / 100, 2);
        $total += $suma;

        $out[] = [
            'id'             => (int) $r['id'],
            'order_id'       => (int) $r['order_id'],
            'order_ref'      => $r['order_ref'] !== null ? (string) $r['order_ref'] : null,
            'order_day'      => (string) $r['order_day'],
            'referrer_id'    => $r['referrer_id'] !== null ? (int) $r['referrer_id'] : null,
            'referrer_label' => $r['referrer_label'] !== null ? (string) $r['referrer_label'] : null,
            'buyer_label'    => $r['buyer_label'] !== null ? (string) $r['buyer_label'] : null,
            'base_total'     => $baza,
            // Suma de acum pleacă și ea, deși e zero prin construcție: jurnalul
            // scrie „de la ce la ce", iar „de la" trebuie citit din rând, nu
            // presupus din filtru.
            'amount_old'     => round((float) $r['amount'], 2),
            'rate_new'       => $nou,
            'amount_new'     => $suma,
            'dismissed'      => ($r['dismissed_at'] ?? null) !== null,
        ];
    }

    return [
        'rows'      => $out,
        'count'     => count($out),
        'total'     => round($total, 2),
        'truncated' => count($randuri) >= GP_REFERRAL_FIX_LIMIT,
    ];
}

/**
 * Butonul, apăsat: procentul de azi și suma care iese din el, scrise pe
 * rândurile născute la zero.
 *
 * Pornește chiar de la previzualizare, nu de la o a doua interogare scrisă
 * alături: două liste s-ar fi putut depărta una de alta la prima reparație, iar
 * atunci omul ar fi apăsat pe ce a văzut și s-ar fi scris altceva.
 *
 * Nu e o tranzacție, și nu are nevoie: fiecare rând se scrie singur, sub
 * aceleași margini pe care le-a ținut și citirea, iar o apăsare întreruptă la
 * jumătate lasă în urmă rânduri îndreptate și rânduri neatinse — adică exact
 * starea din care a doua apăsare își vede mai departe de treabă. Nimic nu se
 * poate scrie de două ori: după prima scriere `rate` nu mai e zero, deci rândul
 * nu mai intră nici în listă, nici sub `WHERE`.
 *
 * @return array{changed: int, total: float, rows: array}
 */
function referral_zero_rate_fix(int $adminId): array
{
    if (!referral_money_supported()) {
        fail('Comisioanele nu sunt pornite pe serverul acesta.', 409, 'referral_money_off');
    }

    $prev = referral_zero_rate_preview();
    if (!$prev['rows']) {
        // Nu e o eroare, e o treabă deja făcută: doi administratori pot apăsa
        // în aceeași secundă, iar al doilea găsește lista goală. Zero schimbate,
        // jurnalul neatins — un rând de jurnal care spune „am îndreptat nimic"
        // e zgomot în singurul loc care trebuie să rămână citibil.
        return ['changed' => 0, 'total' => 0.0, 'rows' => []];
    }

    $facute = [];
    $total  = 0.0;

    foreach ($prev['rows'] as $r) {
        // MARGINILE SE SCRIU A DOUA OARĂ ÎN `WHERE`, deși lista de sus le-a
        // ținut deja. Între citire și scriere încap două tablete: una bifează
        // plata, cealaltă apasă butonul ăsta. Fără condițiile de aici, a doua ar
        // rescrie suma unei chitanțe. Cu ele, rândul pur și simplu nu se atinge,
        // iar numărul întors spune ce s-a schimbat CU ADEVĂRAT — nu câte rânduri
        // s-au încercat.
        $st = q(
            'UPDATE referral_commissions
                SET rate = ?, amount = ?
              WHERE id = ? AND rate = 0 AND paid_at IS NULL AND status = ?',
            [$r['rate_new'], $r['amount_new'], $r['id'], 'activ']
        );
        if ($st->rowCount() === 0) {
            continue;
        }
        $facute[] = $r;
        $total   += $r['amount_new'];
    }

    if ($facute) {
        // CE INTRĂ ÎN JURNAL: cine (pus de `audit()`, din sesiune), când (data
        // rândului), câte rânduri, și de la ce la ce — rând cu rând, pentru
        // primele zece, ca patronul să poată verifica o sumă fără să deschidă
        // baza. Regula casei rămâne neatinsă: nicio valoare de setare secretă
        // nu se scrie aici. Procentul de recomandare nu e un secret — e chiar
        // cifra pe care fiecare om o vede pe propriul rând — dar regula se
        // scrie o dată la fiecare loc care atinge jurnalul, ca să nu se piardă.
        $bucati = [];
        foreach (array_slice($facute, 0, 10) as $r) {
            $bucati[] = ($r['order_ref'] ?: '#' . $r['order_id'])
                . ' · ' . ($r['referrer_label'] ?: 'fără câștigător scris')
                . ' · ' . number_format($r['base_total'], 2, ',', '') . ' RON × '
                . number_format($r['rate_new'], 2, ',', '') . '% · '
                . number_format($r['amount_old'], 2, ',', '') . ' RON → '
                . number_format($r['amount_new'], 2, ',', '') . ' RON';
        }
        audit(
            'referral_rate_fix',
            'commission',
            implode(',', array_map(static fn($r) => (string) $r['id'], array_slice($facute, 0, 10))),
            'Comisioane născute la 0%, îndreptate: ' . count($facute)
              . '. Fiecare a luat procentul de azi al câștigătorului lui; în total '
              . number_format(round($total, 2), 2, ',', '') . ' RON. '
              . implode(' | ', $bucati)
              . (count($facute) > 10 ? ' | și încă ' . (count($facute) - 10) : ''),
            $adminId
        );
    }

    return [
        'changed' => count($facute),
        'total'   => round($total, 2),
        'rows'    => $facute,
    ];
}

/**
 * Câștigurile unui om, pentru pagina LUI.
 *
 * CONFIDENȚIALITATEA SE APĂRĂ ÎN `WHERE`, NU ÎN INTERFAȚĂ — regula casei, la
 * fel ca la `route_orders_list`, care filtrează comenzile clientului tot în SQL.
 * `$referrerId` vine din SESIUNE, niciodată din cerere, și nu există parametru
 * care să lărgească interogarea asta.
 *
 * Ce NU pleacă spre agent: `order_id`, `order_ref`, și niciun join către
 * `orders`. Distribuitorul vede numele firmei și cât a câștigat de la ea —
 * atât a cerut patronul să vadă.
 *
 * `base_total` ȘI `rate` PLEACĂ ACUM, deși mai devreme scria aici că baza nu
 * pleacă. S-a schimbat din două pricini, amândouă bune:
 *
 *   1. NU ASCUNDEAU NIMIC. Omul primea deja și suma, și procentul lui
 *      (`route_referrals_mine`, câmpul `rate`), iar baza iese dintr-o
 *      împărțire: 18,50 împărțit la 5% dă 370. O taină pe care oricine o
 *      poate socoti pe telefon nu e o taină, e o piedică pusă degeaba.
 *   2. FĂRĂ ELE, „0,00 RON" N-AVEA NICIO EXPLICAȚIE. Exact asta s-a întâmplat
 *      pe serverul patronului: trei comenzi venite prin cod au ieșit în
 *      „Istoricul câștigurilor" cu 0,00 RON și nimic altceva. Cu socoteala
 *      scrisă pe rând — „370,00 RON × 0%" — omul vede dintr-o privire CE
 *      anume e zero, și că nu comanda lui a fost mică.
 *
 * Rămâne în picioare singurul lucru care chiar era apărat: comanda însăși.
 * Agentul află cât a fost coșul cumpărătorului LUI, nu și care comandă a fost,
 * și nu poate ajunge de aici la `orders`.
 */
function referral_history(int $referrerId): array
{
    if (!referral_money_supported() || $referrerId <= 0) {
        return ['rows' => [], 'buyers' => [], 'totals' => referral_totals_zero()];
    }

    // Pagina agentului nu vede rândurile scoase DELOC — nici tăiate, nici
    // socotite. Lista patronului le arată, fiindcă el le-a scos și el le poate
    // aduce înapoi; agentul n-are butonul acela, deci un rând tăiat pe ecranul
    // lui ar fi doar o veste proastă din care nu se poate face nimic.
    $rows = all(
        'SELECT id, buyer_label, order_day, rate, base_total, amount, status, paid_at
           FROM referral_commissions
          WHERE referrer_id = ?'
          . referral_dismiss_where()
          . ' ORDER BY order_day DESC, id DESC
          LIMIT 1000',
        [$referrerId]
    );

    // Cele trei plăci se socotesc în SQL, peste TOATE rândurile omului, nu peste
    // cele o mie citite mai sus. Adunate din listă, ele arătau mai puțin decât
    // adevărul tocmai agentului vechi, cu multe comenzi — singurul căruia îi
    // pasă de cifră. Rezumatul pe cumpărători rămâne pe rândurile citite: acela
    // e o listă, nu o socoteală, iar `truncated` spune când e mai scurtă.
    //
    // „Plătit" numără orice rând plătit, oricum s-ar fi sfârșit comanda: banii
    // aceia chiar au ajuns la om. „De încasat" se socotește direct din rândurile
    // active neplătite, nu ca scădere — altfel un comision plătit pe o comandă
    // anulată după aceea ar face diferența negativă.
    $t = one(
        'SELECT
            SUM(CASE WHEN status = ? THEN amount ELSE 0 END)                        AS castigat,
            SUM(CASE WHEN paid_at IS NOT NULL THEN amount ELSE 0 END)               AS platit,
            SUM(CASE WHEN status = ? AND paid_at IS NULL THEN amount ELSE 0 END)    AS de_incasat,
            SUM(CASE WHEN status = ? AND paid_at IS NOT NULL THEN amount ELSE 0 END) AS platit_stins,
            COUNT(*)                                                                AS toate
           FROM referral_commissions WHERE referrer_id = ?'
          . referral_dismiss_where(),
        ['activ', 'activ', 'anulat', $referrerId]
    );

    $buyers = [];
    foreach ($rows as &$r) {
        $r['id']     = (int) $r['id'];
        $r['amount'] = round((float) $r['amount'], 2);
        $r['paid']   = $r['paid_at'] !== null;
        // Cele două cifre ale socotelii se curăță ÎNAINTE de `continue`-ul de
        // mai jos, nu după: rândul anulat și cel în așteptare nu urcă în
        // rezumatul pe cumpărători, dar se desenează în istoric ca toate
        // celelalte, iar acolo au nevoie de socoteală ca să nu fie un zero mut.
        // Ca număr, nu ca șir din DECIMAL — pagina scrie „370,00 RON × 5%", iar
        // dintr-un „5.00" venit din bază ar fi ieșit „5,00%".
        $r['rate']       = round((float) $r['rate'], 2);
        $r['base_total'] = round((float) $r['base_total'], 2);

        // Rezumatul pe cumpărător numără numai banul adevărat: cel anulat s-a
        // stins, cel în așteptare nu s-a hotărât încă al cui e.
        if ($r['status'] !== 'activ') {
            continue;
        }

        // Rezumatul pe cumpărător — „Firma X · 340,50 RON din 7 comenzi" —
        // adunat aici, nu cu un GROUP BY separat: rândurile sunt deja în mână,
        // iar o a doua interogare ar putea vedea altceva decât prima.
        $cheie = (string) ($r['buyer_label'] ?? '—');
        if (!isset($buyers[$cheie])) {
            $buyers[$cheie] = ['buyer' => $cheie, 'amount' => 0.0, 'orders' => 0, 'last' => null];
        }
        $buyers[$cheie]['amount'] += $r['amount'];
        $buyers[$cheie]['orders']++;
        if ($buyers[$cheie]['last'] === null || $r['order_day'] > $buyers[$cheie]['last']) {
            $buyers[$cheie]['last'] = $r['order_day'];
        }
    }
    unset($r);

    foreach ($buyers as &$b) {
        $b['amount'] = round($b['amount'], 2);
    }
    unset($b);
    usort($buyers, static fn($x, $y) => $y['amount'] <=> $x['amount']);

    return [
        'rows'      => $rows,
        'buyers'    => array_values($buyers),
        'truncated' => ((int) ($t['toate'] ?? 0)) > count($rows),
        'totals'    => [
            'earned'        => round((float) ($t['castigat'] ?? 0), 2),
            'paid'          => round((float) ($t['platit'] ?? 0), 2),
            'due'           => round((float) ($t['de_incasat'] ?? 0), 2),
            'paidCancelled' => round((float) ($t['platit_stins'] ?? 0), 2),
        ],
    ];
}

/** Cele trei plăci, pe zero — ca pagina să aibă ce desena și fără migrare. */
function referral_totals_zero(): array
{
    return ['earned' => 0.0, 'paid' => 0.0, 'due' => 0.0, 'paidCancelled' => 0.0];
}

/**
 * Eticheta violetă, pentru o listă de comenzi deja citite.
 *
 * O singură interogare pentru tot ecranul, nu una pe comandă: panoul desenează
 * o sută de carduri odată, iar un SELECT pe fiecare ar face din deschiderea
 * listei o sută de drumuri la bază.
 *
 * @param  array $orders rândurile de comandă (au nevoie doar de `id`)
 * @return array         orderId => [referrer_id, referrer_label, amount, status, paid]
 */
function referral_commissions_for_orders(array $orders): array
{
    if (!referral_money_supported() || !$orders) {
        return [];
    }
    $ids = array_values(array_unique(array_filter(
        array_map(static fn($o) => (int) ($o['id'] ?? 0), $orders),
        static fn($x) => $x > 0
    )));
    if (!$ids) {
        return [];
    }

    $in   = implode(',', array_fill(0, count($ids), '?'));
    $rows = all(
        // Eticheta violetă de pe cardul comenzii tace pentru un rând scos:
        // altfel panoul de comenzi ar arăta un comision pe care registrul nu-l
        // mai numără nicăieri.
        'SELECT id, order_id, referrer_id, referrer_label, amount, rate, status, paid_at
           FROM referral_commissions WHERE order_id IN (' . $in . ')'
          . referral_dismiss_where(),
        $ids
    );

    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['order_id']] = [
            'id'       => (int) $r['id'],
            'referrer' => $r['referrer_id'] !== null ? (int) $r['referrer_id'] : null,
            'label'    => $r['referrer_label'] !== null ? (string) $r['referrer_label'] : null,
            'amount'   => round((float) $r['amount'], 2),
            'rate'     => round((float) $r['rate'], 2),
            'status'   => (string) $r['status'],
            'paid'     => $r['paid_at'] !== null,
        ];
    }
    return $out;
}
