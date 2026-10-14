<?php
/**
 * Raportul de încasări pe Telegram și amintirile de scadență (runda 2.7.0).
 *
 * CE FACE. De două ori pe zi, la orele alese de patron în „Încasări”, pleacă
 * spre CHATUL BANILOR un mesaj cu cât e de încasat, câte amânări sunt și cât
 * s-a încasat ieri. Odată cu primul raport al zilei pleacă, într-un singur
 * mesaj, amânările al căror termen a venit. Hotărârile patronului din
 * 4–5 octombrie 2026:
 *
 *   · banii au chatul LOR, altul decât cel al comenzilor: în grupul comenzilor
 *     stă toată tura, iar lista datornicilor nu e a turei;
 *   · un raport ratat (server oprit) se mai trimite cu cel mult două ore
 *     întârziere; peste, se sare — raportul de dimineață primit seara nu mai
 *     spune nimic adevărat despre dimineață;
 *   · amintirile merg cu primul raport al zilei.
 *
 * CINE O CHEAMĂ. Două uși, și numai ele:
 *
 *   · ceasul de pe server (`api/cron/plati.php`, din cinci în cinci minute) —
 *     PRIMUL lucru din program care rulează fără să fi cerut nimeni o pagină;
 *   · `POST /plati/raport-proba` (api/routes/plati.php), butonul „Trimite acum
 *     un raport de probă” din „Încasări”, sub dreptul `plati.telegram`.
 *
 * DE CE NU TRECE PRIN `notify_event()`. Anunțurile casei pleacă spre chatul
 * comenzilor și se bifează din Setări, pe canale. Raportul de bani n-are voie
 * să ajungă acolo nici din greșeală și nu e o bifă a nimănui: are ecranul lui,
 * dreptul lui și chatul lui. De aceea cele două evenimente — `plati_raport` și
 * `plati_amintire` — stau DINADINS în afara catalogului `notify_events()`
 * (api/lib/notify.php): puse acolo, ar fi apărut în Setări ca două bife noi,
 * iar bifate pe e-mail ar fi trimis datornicii pe adresa laboratorului.
 * Rândurile se scriu totuși în `notifications`, cu numele lor, ca o trimitere
 * picată să se vadă — în „Încasări” (ultimele trimiteri) și în lista de eșecuri
 * din Setări, unde `failed_notifications_fara_plati()` le arată numai celui cu
 * dreptul `plati.telegram`.
 *
 * CEL MULT O DATĂ. Ceasul rulează des și poate rula de două ori deodată (un
 * `flock` pierdut, un om care îl pornește cu mâna). De aceea nu întreabă „am
 * trimis?”, ci REVENDICĂ: un `UPDATE` care schimbă rândul numai dacă nu l-a
 * schimbat altcineva azi, iar trimite doar rularea care chiar l-a schimbat.
 * Revendicarea se face ÎNAINTEA trimiterii — vezi `plati_ceas()` pentru ce
 * costă alegerea asta și de ce e totuși cea bună.
 */

declare(strict_types=1);

/**
 * Cele trei feluri de trimitere: evenimentul scris în `notifications` și
 * subiectul după care „Încasări” deosebește raportul de la oră de cel de probă
 * (amândouă sunt același eveniment — același text, altă pricină).
 *
 * Un singur tabel, citit în amândouă sensurile: `plati_trimite()` scrie din el,
 * `plati_raport_ultimele()` recunoaște după el. Scris de două ori, al doilea
 * loc ar fi rămas în urmă la prima vorbă schimbată, iar „Raport de probă” ar fi
 * apărut în pagină drept raport de la oră.
 */
const GP_PLATI_TRIMITERI = [
    'raport'   => ['event' => 'plati_raport',   'subiect' => 'Raport de încasări'],
    'proba'    => ['event' => 'plati_raport',   'subiect' => 'Raport de probă'],
    'amintire' => ['event' => 'plati_amintire', 'subiect' => 'Amintire de scadență'],
];

/** Cât mai poate întârzia un raport, în secunde: două ore (hotărârea patronului). */
const GP_PLATI_INTARZIERE = 7200;

/**
 * Ora amintirilor când patronul a oprit amândouă rapoartele, dar a lăsat
 * amintirile pornite. HOTĂRÂT în runda 2.7.0: la prima rulare de după 09:00.
 * Fără ea, amintirile ar fi atârnat de un raport care nu mai pleacă niciodată.
 */
const GP_PLATI_ORA_AMINTIRI = '09:00';

/** Câți datornici încap în raport și câte amânări într-o amintire; restul se numără („… și încă 3”). */
const GP_PLATI_RAPORT_CEI_MAI_MARI = 5;
const GP_PLATI_AMINTIRI_MAX        = 20;

const GP_PLATI_LUNI = [1 => 'ian.', 'feb.', 'mar.', 'apr.', 'mai', 'iun.', 'iul.', 'aug.', 'sep.', 'oct.', 'nov.', 'dec.'];
const GP_PLATI_ZILE = [1 => 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă', 'duminică'];

/* ================================================================ vorbe == */

/** O sumă, cum o scrie omul: „2.340,00”. Cuvântul „lei” îl pune cine o cheamă. */
function plati_raport_lei(float $v): string
{
    return number_format($v, 2, ',', '.');
}

/** „1 comandă”, „9 comenzi”, „20 de comenzi”. */
function plati_raport_cate(int $n, string $una, string $multe): string
{
    return $n . ' ' . ($n === 1 ? $una : ro_de($n) . $multe);
}

/**
 * O zi, pe scurt: „5 oct.”; cu numele ei: „luni 5 oct.”. Anul apare numai când
 * nu e cel de azi — o datorie „din 28 dec.” citită în ianuarie e de anul trecut,
 * și trebuie s-o spună.
 */
function plati_raport_zi(string $zi, string $azi, bool $cuNume = false): string
{
    $d = new DateTimeImmutable($zi . ' 12:00:00', new DateTimeZone('Europe/Bucharest'));
    return ($cuNume ? GP_PLATI_ZILE[(int) $d->format('N')] . ' ' : '')
        . (int) $d->format('j') . ' ' . GP_PLATI_LUNI[(int) $d->format('n')]
        . (substr($zi, 0, 4) !== substr($azi, 0, 4) ? ' ' . $d->format('Y') : '');
}

/**
 * Un nume de client, gata de pus pe un rând de mesaj.
 *
 * Numele îl scrie cine vrea — un vizitator, în formularul de pe site. Mesajul
 * pleacă drept TEXT SIMPLU (`send_telegram()` nu cere niciun `parse_mode`),
 * deci un `<b>` ori un `_` din nume nu pot strica nimic: se văd ca atare. Ce
 * poate strica un text simplu e RÂNDUL NOU — un nume cu un Enter în el ar fi
 * desenat, sub raport, un rând care pare al casei. `text_de_aratat()` scoate
 * caracterele de comandă și taie numele lung, ca lista să rămână o listă.
 *
 * NU NUMAI ENTERUL RUPE UN RÂND. `text_de_aratat()` scoate octeții de comandă
 * (sub 0x20 și 0x7F) și atât; Unicode mai are trei sfârșituri de rând care nu
 * sunt printre ei — U+2028, U+2029 și U+0085 — pe care Telegram le desenează
 * tot ca rând nou, și semnele de direcție (U+202E și suratele lui), care
 * întorc pe dos tot ce urmează pe rând: suma, telefonul, termenul. Văzut la
 * citirea rundei, pe 9 oct. 2026: un nume „Ion⟨U+2028⟩De încasat: nimic”
 * ajungea în amintire pe două rânduri, al doilea părând al casei. Se scot AICI,
 * înaintea curățării de obicei (ca tăierea la 60 să numere ce rămâne), și
 * numai aici: sita casei e a tuturor refuzurilor și nu e a rundei ăsteia.
 * Cu `u`: pe octeți stricați `preg_replace` întoarce `null`, și atunci numele
 * merge mai departe cum a venit — tot prin curățarea de obicei.
 */
function plati_raport_nume(string $nume): string
{
    $faraRupturi = preg_replace('/[\x{0085}\x{2028}\x{2029}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $nume);
    $curat = text_de_aratat($faraRupturi ?? $nume, 60);
    return $curat !== '' ? $curat : '(fără nume)';
}

/* ================================================================ cifre == */

/**
 * Cifrele raportului, luate din ACELEAȘI funcții din care le ia pagina
 * „Încasări” (`incasari_cifre()`, `incasari_clienti()` — api/routes/plati.php).
 *
 * DE CE NU O SOCOTEALĂ A EI. Patronul citește raportul pe telefon și deschide
 * apoi pagina: două sume care diferă cu o comandă îl fac să nu mai creadă pe
 * niciuna. Două interogări scrise de două ori s-ar fi despărțit la prima
 * îndreptare — cine se numără „anulat”, ce înseamnă „ieri” în noaptea în care
 * se mută ceasul. Aici nu e nicio interogare: numai chemări.
 *
 * SINGURUL LOC din afara rutelor „Încasări” care are voie să cheme funcțiile
 * `incasari_*()` — `.claude/check-plati.mjs` îl știe pe nume. Ce întoarce pleacă
 * numai spre chatul banilor (`plati_trimite()`); nu se pune pe niciun răspuns.
 *
 * „Încasat ieri” e chiar „Încasat azi” al paginii, cerut pentru ziua de ieri:
 * aceleași margini de zi locală, aceeași regulă a plăților cu clipă.
 */
function plati_raport_date(string $azi): array
{
    if (!plati_supported()) {
        throw new LogicException('plati_raport_date(): fără migrarea 032 nu sunt cifre de raportat.');
    }
    if (!function_exists('incasari_cifre') || !function_exists('incasari_clienti')) {
        // Routerul încarcă un singur fișier de rute pe cerere, iar ceasul
        // niciunul: cine cheamă de aici trebuie să fi cerut api/routes/plati.php.
        throw new LogicException('plati_raport_date(): cifrele le socotește api/routes/plati.php, care nu e încărcat.');
    }

    $cifre   = incasari_cifre($azi, null, null);
    $clienti = incasari_clienti($azi);
    $ieri    = incasari_cifre(zi_mutata($azi, -1), null, null)['incasatAzi'];

    $ceiMaiMari = [];
    foreach (array_slice($clienti, 0, GP_PLATI_RAPORT_CEI_MAI_MARI) as $c) {
        $ceiMaiMari[] = [
            'nume'    => (string) $c['nume'],
            'comenzi' => (int) $c['comenzi'],
            'sold'    => (float) $c['sold'],
            'din'     => (string) $c['ceaMaiVeche'],
        ];
    }

    return [
        'deIncasat'   => [
            'suma'    => (float) $cifre['deIncasat']['suma'],
            'comenzi' => (int) $cifre['deIncasat']['comenzi'],
            // Ca în `GET /plati`: numărul clienților e lungimea listei lor.
            'clienti' => count($clienti),
        ],
        'amanate'     => [
            'suma'     => (float) $cifre['amanate']['suma'],
            'comenzi'  => (int) $cifre['amanate']['comenzi'],
            'depasite' => (int) $cifre['amanate']['depasite'],
        ],
        'incasatIeri' => [
            'suma'    => (float) $ieri['suma'],
            'direct'  => (float) $ieri['direct'],
            'banca'   => (float) $ieri['banca'],
            'comenzi' => (int) $ieri['comenzi'],
        ],
        'ceiMaiMari'  => $ceiMaiMari,
        'altii'       => max(0, count($clienti) - count($ceiMaiMari)),
    ];
}

/* ================================================================ texte == */

/**
 * Textul raportului. Forma e cea hotărâtă în planul rundei:
 *
 *   Încasări · luni 5 oct., 09:00
 *   De încasat: 2.340,00 lei · 9 comenzi · 5 clienți
 *   Amânate: 3 · 610,00 lei (1 depășită)
 *   Încasat ieri: 1.120,00 lei (direct 700,00 · bancă 420,00)
 *   Cei mai mari:
 *   • Cafeneaua X — 3 com. · 820,00 lei · din 28 sep.
 *
 * CEASUL DIN CAP e clipa la care s-au socotit cifrele, nu ora aleasă în
 * Setări: un raport plecat cu patruzeci de minute întârziere spune cât era de
 * încasat ATUNCI, și trebuie să spună când.
 *
 * Unde nu e nimic, rândul rămâne și o spune („nimic”, „niciuna”): un raport
 * din care lipsește un rând arată a raport stricat, nu a zi bună.
 */
function plati_raport_text(array $d, DateTimeImmutable $acum, bool $proba = false): string
{
    $azi = $acum->format('Y-m-d');
    $r   = [];

    if ($proba) {
        $r[] = 'Probă — așa arată raportul care pleacă la orele alese.';
    }
    $r[] = 'Încasări · ' . plati_raport_zi($azi, $azi, true) . ', ' . $acum->format('H:i');

    $di  = $d['deIncasat'];
    $r[] = $di['comenzi'] > 0
        ? 'De încasat: ' . plati_raport_lei($di['suma']) . ' lei · '
            . plati_raport_cate($di['comenzi'], 'comandă', 'comenzi') . ' · '
            . plati_raport_cate($di['clienti'], 'client', 'clienți')
        : 'De încasat: nimic';

    $am  = $d['amanate'];
    $dep = $am['depasite'] > 0
        ? ' (' . plati_raport_cate($am['depasite'], 'depășită', 'depășite') . ')'
        : '';
    $r[] = $am['comenzi'] > 0
        ? 'Amânate: ' . $am['comenzi'] . ' · ' . plati_raport_lei($am['suma']) . ' lei' . $dep
        : 'Amânate: niciuna';

    $ie  = $d['incasatIeri'];
    $r[] = $ie['comenzi'] > 0
        ? 'Încasat ieri: ' . plati_raport_lei($ie['suma']) . ' lei (direct ' . plati_raport_lei($ie['direct'])
            . ' · bancă ' . plati_raport_lei($ie['banca']) . ')'
        : 'Încasat ieri: nimic';

    if ($d['ceiMaiMari']) {
        $r[] = 'Cei mai mari:';
        foreach ($d['ceiMaiMari'] as $c) {
            $r[] = '• ' . plati_raport_nume($c['nume']) . ' — ' . $c['comenzi'] . ' com. · '
                . plati_raport_lei($c['sold']) . ' lei · din ' . plati_raport_zi($c['din'], $azi);
        }
        if ($d['altii'] > 0) {
            // Lista se oprește aici și spune câți au rămas: Telegram refuză cu
            // totul un mesaj mai lung de 4096 de semne, iar o casă cu două sute
            // de datornici n-ar mai fi primit niciun raport.
            $r[] = '… și încă ' . $d['altii'] . ' — vezi în Încasări';
        }
    }

    return implode("\n", $r);
}

/**
 * Textul amintirii: toate amânările al căror termen a venit, într-un singur
 * mesaj.
 *
 *   ⚠️ Important — azi e termenul de plată
 *   • #123 Cafeneaua X — 340,00 lei · tel. 07xx — poate fi sunat azi
 *
 * O amânare cu termenul TRECUT ajunge aici numai dacă ceasul n-a apucat s-o
 * spună la vreme (server oprit, chat pus abia azi): rândul ei spune de când e
 * trecută, ca omul să nu sune pe cineva crezând că termenul e azi.
 *
 * `$randuri` vin de la `plati_amintiri_ia()`: referința, numele, suma,
 * telefonul și termenul.
 */
function plati_amintiri_text(array $randuri, string $azi): string
{
    $deAzi = 0;
    foreach ($randuri as $x) {
        $deAzi += $x['termen'] === $azi ? 1 : 0;
    }
    $r = ["\u{26A0}\u{FE0F} Important — " . ($deAzi > 0 ? 'azi e termenul de plată' : 'termene de plată trecute')];

    foreach (array_slice($randuri, 0, GP_PLATI_AMINTIRI_MAX) as $x) {
        $tel = $x['tel'] !== '' ? ' · tel. ' . text_de_aratat($x['tel'], 24) : '';
        if ($x['termen'] === $azi) {
            $coada = $x['tel'] !== '' ? ' — poate fi sunat azi' : ' — fără telefon pe comandă';
        } else {
            $coada = ' — termenul a fost pe ' . plati_raport_zi($x['termen'], $azi);
        }
        $r[] = '• ' . text_de_aratat($x['ref'], 20) . ' ' . plati_raport_nume($x['nume']) . ' — '
            . plati_raport_lei($x['suma']) . ' lei' . $tel . $coada;
    }
    $rest = count($randuri) - GP_PLATI_AMINTIRI_MAX;
    if ($rest > 0) {
        $r[] = '… și încă ' . $rest . ' — vezi Amânatele';
    }

    return implode("\n", $r);
}

/**
 * Butonul de sub mesaj: deschide „Încasări” pe vederea potrivită.
 *
 * Adresa se lipește din `panel_base_url` (pusă de patron în „Încasări”; din
 * migrare, `https://lab.goldenpastry.ro`). Dacă se poate pune pe un buton o
 * judecă `send_telegram()` — într-UN singur loc, pentru orice buton de adresă:
 * numai `https://` și numai spre o gazdă din lume. Pe calculatorul de lucru,
 * unde adresa arată spre `localhost`, raportul pleacă fără buton.
 */
function plati_raport_buton(string $eticheta, string $vedere): array
{
    $baza = rtrim(trim(setting('panel_base_url')), '/');
    if ($baza === '') {
        return [];
    }
    return [[['text' => $eticheta, 'url' => $baza . '/incasari?f=' . $vedere]]];
}

/* ============================================================= trimitere == */

/**
 * Un text din care a fost scos tokenul botului, tăiat la o lungime de citit.
 *
 * Tokenul stă în ADRESA fiecărei cereri către Telegram, iar o eroare de
 * legătură poate cita adresa. Textul erorii ajunge în `notifications`, de
 * acolo în „Încasări”, în răspunsul butonului de probă și în jurnalul
 * serverului — patru locuri în care tokenul n-are ce căuta. Se scoate de două
 * ori: tokenul însuși, dacă îl știm, și orice bucată care arată a token
 * (`bot<cifre>:<litere>`), pentru ziua în care cel din Setări e altul decât
 * cel din eroare.
 */
function plati_text_fara_token(string $text, string $token = ''): string
{
    if ($token !== '') {
        $text = str_replace($token, '…', $text);
    }
    $curat = preg_replace('~(?:bot)?\d{5,}:[A-Za-z0-9_-]{10,}~', '…', $text);
    return mb_substr(trim($curat ?? $text), 0, 300);
}

/** `plati_text_fara_token()` cu tokenul din Setări. Nu aruncă: se cheamă din blocuri `catch`. */
function plati_fara_token(string $text): string
{
    try {
        $token = setting('notify_telegram_token');
    } catch (Throwable $e) {
        error_log('[gp] plati: nu pot citi tokenul ca să-l scot dintr-un mesaj: ' . get_class($e));
        $token = '';
    }
    return plati_text_fara_token($text, $token);
}

/**
 * De ce NU se poate trimite acum nimic spre chatul banilor: `[cod, vorbă]`,
 * ori `null` când se poate.
 *
 * O singură judecată pentru amândouă ușile. Ceasul o citește ca „tac și nu
 * însemn nimic” — un loc revendicat fără chat ar fi mâncat raportul din ziua
 * în care patronul pune, în sfârșit, chatul. Butonul de probă o citește ca
 * refuz spus pe nume.
 *
 * CHATUL COMENZILOR NU E NICIODATĂ REZERVĂ. Chat gol înseamnă „raport oprit”,
 * nu „trimite unde mai e ceva pus”. Iar dacă cele două chaturi au ajuns egale
 * pe altă cale decât pagina (o bază adusă de altundeva, un rând scris cu
 * mâna), raportul tace: regula de la salvare nu poate păzi ce n-a trecut pe la
 * ea, deci se mai întreabă o dată aici, înaintea fiecărei trimiteri.
 *
 * VORBELE NU NUMESC O PAGINĂ (runda 2.7.5). Chatul raportului se pune de acum
 * din două locuri — cartonașul „Raport pe Telegram” din Încasări și cutia
 * „Raport de bani pe Telegram” din Setări › Notificări › Telegram —, iar
 * refuzul se citește în oricare din ele, chiar în cutie: lângă caseta
 * chatului (`plati_chat_missing`) ori sub butonul de probă (celelalte).
 * „Pune-l în Încasări”, citit în Setări, ar fi trimis omul în altă pagină
 * după o casetă pe care o are sub ochi. Vorba spune deci „Raport pe Telegram”
 * și atât: e titlul întreg al cartonașului din Încasări; în Setări cutia
 * poartă un nume mai lung („Raport de bani pe Telegram”), dar refuzul e scris
 * ÎN ea, deci nu e loc de căutat. Și amândouă se termină cu „și salvează”:
 * proba pleacă după ce e pe server, nu după ce scrie în casetă.
 *
 * Tokenul botului e altceva: se pune dintr-un singur loc, deci refuzul lui
 * are voie să spună unde. Vorbele le ține `RAPORT_REFUZ_SERVER`, din
 * `.claude/check-plati-conturi.mjs`.
 */
function plati_trimite_piedica(): ?array
{
    $chat = trim(setting('plati_telegram_chat_id'));
    if ($chat === '') {
        return ['plati_chat_missing', 'Raportul de bani nu are chat. Pune numărul chatului la „Raport pe Telegram” și salvează.'];
    }
    if (plati_chat_acelasi($chat, setting('notify_telegram_chat_id'))) {
        return ['plati_chat_same', 'Chatul raportului de bani e același cu al comenzilor. Raportul are chatul lui: schimbă-l la „Raport pe Telegram” și salvează.'];
    }
    if (setting('notify_telegram_token') === '') {
        return ['plati_token_missing', 'Botul de Telegram nu are token. Pune-l în Setări, la Telegram.'];
    }
    return null;
}

/**
 * Trimite un mesaj al plăților spre CHATUL BANILOR și scrie încercarea în
 * `notifications`. Întoarce `['ok' => bool, 'motiv' => ?string]`; nu aruncă.
 *
 * SINGURUL LOC din fișier care trimite. Destinatarul e citit aici, din
 * `plati_telegram_chat_id`, și nu se primește din afară: nicio chemare nu
 * poate îndrepta raportul spre alt chat.
 *
 * Rândul se scrie ÎNAINTEA trimiterii (`pending`) și se încheie după: un
 * proces oprit la mijloc lasă urma că a încercat, nu liniște.
 *
 * O TRIMITERE PICATĂ NU SE REIA. Rămâne `failed`, cu pricina, la vedere în
 * „Încasări”. Reluarea automată ar fi însemnat un raport de dimineață sosit
 * la prânz, după ce Telegram își revine — ori două, dacă prima plecase și
 * numai răspunsul s-a pierdut.
 */
function plati_trimite(string $fel, string $text, array $butoane = []): array
{
    if (!isset(GP_PLATI_TRIMITERI[$fel])) {
        throw new LogicException('plati_trimite(): fel necunoscut „' . $fel . '”.');
    }
    if (!plati_supported() || !notify_event_column()) {
        return ['ok' => false, 'motiv' => 'Baza nu are încă plățile (migrarea 032).'];
    }
    $piedica = plati_trimite_piedica();
    if ($piedica !== null) {
        return ['ok' => false, 'motiv' => $piedica[1]];
    }
    $chat = trim(setting('plati_telegram_chat_id'));

    q(
        'INSERT INTO notifications (channel, event, recipient, subject, payload, status, attempts)
         VALUES (?, ?, ?, ?, ?, ?, 1)',
        ['telegram', GP_PLATI_TRIMITERI[$fel]['event'], $chat, GP_PLATI_TRIMITERI[$fel]['subiect'], $text, 'pending']
    );
    $id = (int) db()->lastInsertId();

    try {
        send_telegram($chat, $text, $butoane);
    } catch (Throwable $e) {
        $motiv = plati_fara_token($e->getMessage());
        q('UPDATE notifications SET status = ?, error = ? WHERE id = ?', ['failed', $motiv, $id]);
        error_log('[gp] plati: ' . GP_PLATI_TRIMITERI[$fel]['subiect'] . ' — nu a plecat: ' . $motiv);
        return ['ok' => false, 'motiv' => $motiv];
    }

    q('UPDATE notifications SET status = ?, sent_at = UTC_TIMESTAMP() WHERE id = ?', ['sent', $id]);
    return ['ok' => true, 'motiv' => null];
}

/**
 * Raportul de probă: aceleași cifre, același buton, trimis ACUM.
 *
 * NU REVENDICĂ NIMIC ȘI NU ATINGE AMINTIRILE. E un „arată-mi cum arată”:
 * apăsat la 08:55, n-are voie să mănânce raportul de la 09:00, iar apăsat de
 * trei ori n-are voie să însemne trei amânări drept „amintite”.
 */
function plati_raport_proba(): array
{
    $acum = new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest'));
    $text = plati_raport_text(plati_raport_date($acum->format('Y-m-d')), $acum, true);

    return plati_trimite('proba', $text, plati_raport_buton('Deschide Încasări', 'deincasat'));
}

/**
 * Cele mai noi trimiteri, pentru `GET /plati/setari` → `ultimele`:
 * `{ la, fel, stare, motiv }`.
 *
 * `la` e clipa UTC, cum stă în bază și cum pleacă și `plataLa`; pagina o trece
 * pe ceasul patiseriei. `fel` e `raport`, `amintire` ori `proba`. `stare` e
 * `sent` ori `failed`, iar `motiv` e pricina unui eșec, cu tokenul scos.
 *
 * NICI DESTINATARUL, NICI TEXTUL nu pleacă de aici: chatul îl are pagina din
 * setări, iar textul e lista datornicilor — n-are de ce să stea încă o dată
 * într-un răspuns.
 *
 * Un rând rămas `pending` e o trimitere al cărei proces s-a oprit la mijloc.
 * Proaspăt (sub două minute), e una care tocmai pleacă și nu se arată încă;
 * vechi, se arată drept eșec, cu vorba lui.
 */
function plati_raport_ultimele(int $cate = 5): array
{
    if (!notify_event_column()) {
        return [];
    }
    $randuri = all(
        'SELECT event, subject, status, error, created_at, sent_at,
                TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) AS varsta
           FROM notifications
          WHERE event IN (?, ?)
          ORDER BY id DESC
          LIMIT ' . max(1, min(20, $cate)) * 2,
        ['plati_raport', 'plati_amintire']
    );

    $out = [];
    foreach ($randuri as $r) {
        $stare = (string) $r['status'];
        if ($stare === 'pending' && (int) $r['varsta'] < 120) {
            continue;
        }
        $fel = 'raport';
        foreach (GP_PLATI_TRIMITERI as $nume => $t) {
            if ($t['event'] === $r['event'] && $t['subiect'] === $r['subject']) {
                $fel = $nume;
                break;
            }
        }
        if ($r['event'] === 'plati_amintire') {
            $fel = 'amintire';
        }
        $motiv = null;
        if ($stare !== 'sent') {
            $motiv = $stare === 'pending'
                ? 'Trimiterea nu s-a încheiat: serverul s-a oprit la mijloc.'
                : plati_fara_token((string) ($r['error'] ?? ''));
        }
        $out[] = [
            'la'    => (string) ($r['sent_at'] ?? $r['created_at']),
            'fel'   => $fel,
            'stare' => $stare === 'sent' ? 'sent' : 'failed',
            'motiv' => $motiv !== '' ? $motiv : null,
        ];
        if (count($out) >= $cate) {
            break;
        }
    }
    return $out;
}

/* ================================================================== ceas == */

/**
 * Clipa cerută cu `--acum`, în ceasul României; `null` dacă nu e o clipă.
 *
 * `AAAA-LL-ZZ HH:MM`, ori aceeași cu decalajul scris (`…+02:00`). A doua formă
 * există pentru o singură noapte pe an: cea din octombrie în care ora 03:00–04:00
 * vine de două ori, iar „03:30” singur nu spune care din ele (PHP o ia pe una,
 * după versiune; cu decalajul scris, proba le poate cere pe amândouă).
 *
 * O clipă care NU EXISTĂ — 31 februarie, ori 03:30 din noaptea de martie în
 * care ceasul sare de la 03:00 la 04:00 — e refuzată, nu potrivită în tăcere pe
 * alta: o probă care crede că a întrebat de 03:30 și a primit răspunsul pentru
 * 04:30 nu mai măsoară ce spune că măsoară.
 */
function plati_ceas_clipa_ceruta(string $v): ?DateTimeImmutable
{
    $tz = new DateTimeZone('Europe/Bucharest');
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) ([01]\d|2[0-3]):[0-5]\d([+-]\d{2}:\d{2})?\z/', $v, $m) !== 1
        || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    try {
        $d = isset($m[5]) ? (new DateTimeImmutable($v))->setTimezone($tz) : new DateTimeImmutable($v . ':00', $tz);
    } catch (Exception $e) {
        error_log('[gp] plati: --acum nu s-a putut citi: ' . plati_text_fara_token($e->getMessage()));
        return null;
    }
    if (!isset($m[5]) && $d->format('Y-m-d H:i') !== $v) {
        return null;
    }
    return $d;
}

/**
 * Orele „coapte”: cele a căror clipă a trecut, dar de mai puțin de două ore.
 * Întoarce `[['loc' => 1|2, 'zi' => 'AAAA-LL-ZZ', 'ora' => 'HH:MM'], …]`.
 *
 * PE CLIPE ADEVĂRATE, NU PE CEASUL DE PERETE. „Au trecut sub două ore” se
 * scade între două clipe (secunde de la 1970), fiindcă de două ori pe an
 * ceasul de perete minte:
 *
 *   · toamna, în ultima duminică din octombrie, 03:00–04:00 vine de DOUĂ ori.
 *     O oră aleasă în golul ăsta (03:30) e UNA din cele două treceri — PHP 8.3
 *     o alege pe a doua (văzut pe 9 oct. 2026), versiuni mai vechi pe prima.
 *     Oricare ar fi: trecerea neluată ori e dinaintea clipei (nimic de trimis),
 *     ori găsește ziua deja însemnată. O dată, nu de două ori și nu niciodată;
 *   · primăvara, în ultima duminică din martie, 03:00–04:00 NU EXISTĂ. PHP
 *     duce „03:30” pe clipa de după săritură (04:30), deci raportul pleacă în
 *     ziua aceea cu o oră mai târziu pe ceas — dar pleacă. Socotit pe ceasul de
 *     perete, n-ar fi plecat deloc: nicio rulare nu vede vreodată „03:30”.
 *
 * SE UITĂ ȘI LA IERI, fiindcă două ore de îngăduință trec peste miezul nopții:
 * raportul de la 23:30, prins de o rulare de la 00:10, e tot al zilei de ieri
 * și se însemnează pe ea.
 */
function plati_ceas_ore_coapte(DateTimeImmutable $acum, array $ore): array
{
    $tz  = new DateTimeZone('Europe/Bucharest');
    $azi = $acum->setTimezone($tz)->format('Y-m-d');
    $out = [];
    foreach ([0, 1] as $i) {
        $ora = (string) ($ore[$i] ?? '');
        if (preg_match(GP_PLATI_ORA, $ora) !== 1) {
            continue;
        }
        foreach ([$azi, zi_mutata($azi, -1)] as $zi) {
            $clipa  = new DateTimeImmutable($zi . ' ' . $ora . ':00', $tz);
            $trecut = $acum->getTimestamp() - $clipa->getTimestamp();
            if ($trecut >= 0 && $trecut < GP_PLATI_INTARZIERE) {
                $out[] = ['loc' => $i + 1, 'zi' => $zi, 'ora' => $ora];
                break;
            }
        }
    }
    return $out;
}

/**
 * REVENDICĂ ora: însemnează că raportul zilei `$zi` de pe locul `$loc` e al
 * rulării ăsteia. `true` numai pentru rularea care chiar a schimbat rândul.
 *
 * `rowCount()` numără aici rândurile SCHIMBATE (legătura casei nu cere
 * `FOUND_ROWS`), iar condiția face ca a doua rulare să nu mai aibă ce schimba:
 * dintre două porniri deodată, InnoDB o lasă pe una să scrie, iar cealaltă își
 * reface întrebarea pe rândul deja scris și primește zero.
 *
 * ÎNSEMNAREA MERGE NUMAI ÎNAINTE (`GP_PLATI_ORA_DE_LUAT`). Până pe 9 oct. 2026
 * condiția era „alta decât ziua orei” (`<>`), și lăsa însemnarea să se întoarcă
 * cu o zi: raportul de la 00:10 plecat azi, ora mutată apoi la 23:50 — la 00:35
 * ora de IERI, 23:50, e „coaptă” (au trecut 45 de minute), ziua ei e alta decât
 * cea însemnată, deci pleca un al doilea raport, însemnarea cobora pe ieri, iar
 * la 23:50 pleca al treilea. Văzut la citirea rundei. Acum ora se ia numai
 * dacă însemnarea e MAI VECHE decât ziua ei.
 *
 * …ȘI TOTUȘI NU SE ÎNȚEPENEȘTE. „Mai veche” singur ar fi oprit rapoartele, în
 * tăcere, după o însemnare ajunsă în viitor (o probă oprită la mijloc, un ceas
 * de server greșit o zi) — pricina pentru care fusese ales `<>`. De aceea a
 * doua jumătate: o însemnare de DUPĂ ziua de azi a ceasului nu poate fi
 * adevărată și se calcă. `$azi`, nu `$zi`: ziua orei poate fi ieri (raportul
 * de la 23:30 prins la 00:10), iar atunci însemnarea de azi e bună, nu gunoi.
 */
const GP_PLATI_ORA_DE_LUAT = "setting_key = ? AND (COALESCE(value, '') < ? OR value > ?)";

function plati_ceas_ia_ora(int $loc, string $zi, string $azi): bool
{
    return q(
        'UPDATE settings SET value = ? WHERE ' . GP_PLATI_ORA_DE_LUAT,
        [$zi, 'plati_raport_trimis_' . $loc, $zi, $azi]
    )->rowCount() === 1;
}

/** Ar mai putea fi revendicată ora? Numai pentru `--uscat`, care întreabă fără să însemne. */
function plati_ceas_ora_libera(int $loc, string $zi, string $azi): bool
{
    return (int) scalar(
        'SELECT COUNT(*) FROM settings WHERE ' . GP_PLATI_ORA_DE_LUAT,
        ['plati_raport_trimis_' . $loc, $zi, $azi]
    ) === 1;
}

/**
 * Amânările de amintit ACUM, revendicate una câte una. Întoarce numai pe cele
 * luate de rularea asta: referința, numele, suma, telefonul și termenul (și
 * `id`-ul rândului, cu care se dau înapoi dacă amintirea nu pleacă).
 *
 * CARE. Comenzile `amanata`, neanulate, cu termenul azi ori trecut, despre
 * care nu s-a amintit încă DE LA ACEASTĂ AMÂNARE.
 *
 * O DATĂ PE AMÂNARE, NU O DATĂ PE ZI. Foaia rundei scria revendicarea ca
 * „`plata_amintire_la` e gol ori mai vechi decât azi”. Cu ea, o amânare
 * depășită ar fi fost amintită în fiecare dimineață, până la plată — iar un
 * mesaj „Important” primit zilnic nu-l mai citește nimeni din a treia zi.
 * Hotărât: amintirea pleacă O DATĂ, la termen; a doua zi nu din nou. Deci se
 * compară cu TERMENUL, nu cu ziua de azi: amintită e amânarea a cărei
 * însemnare a ajuns la termenul ei. O amânare NOUĂ ori MUTATĂ se amintește
 * iar — `PATCH /orders/#/plata` șterge însemnarea la orice schimbare, iar un
 * termen mutat mai târziu trece oricum peste însemnarea veche.
 *
 * „Azi ori trecut”, nu numai „azi”: dacă ceasul n-a rulat în ziua termenului
 * (server oprit, chatul pus abia a doua zi), amânarea se spune o dată, cu
 * întârziere, și textul spune de când e trecută.
 *
 * `updated_at` RĂMÂNE NEATINS. Coloana se mută singură la orice scriere pe
 * rând și pleacă pe comandă drept „schimbată la”; o amintire trimisă de ceas
 * nu e o schimbare a comenzii și n-are de ce s-o arate drept umblată.
 *
 * Cu `$uscat`, nimic nu se însemnează: întoarce ce AR lua.
 */
function plati_amintiri_ia(string $azi, bool $uscat = false): array
{
    if (!plati_supported()) {
        return [];
    }
    $anulata = status_cancelled();
    $vie     = $anulata !== '' ? ' AND o.status <> ?' : '';
    $vieP    = $anulata !== '' ? [$anulata] : [];
    $deLuat  = 'o.plata = ? AND o.plata_scadenta IS NOT NULL AND o.plata_scadenta <= ?'
        . ' AND (o.plata_amintire_la IS NULL OR o.plata_amintire_la < o.plata_scadenta)' . $vie;

    $randuri = all(
        'SELECT o.id, o.ref, o.seq, o.customer_name, o.total, o.plata_scadenta,'
            . " COALESCE(NULLIF(TRIM(o.phone), ''), NULLIF(TRIM(u.phone), ''), '') AS tel"
            . ' FROM orders o LEFT JOIN users u ON u.id = o.customer_id'
            . ' WHERE ' . $deLuat
            . ' ORDER BY o.plata_scadenta DESC, o.total DESC, o.id',
        array_merge(['amanata', $azi], $vieP)
    );

    $ale = [];
    foreach ($randuri as $r) {
        if (!$uscat) {
            $luata = q(
                'UPDATE orders o SET o.plata_amintire_la = ?, o.updated_at = o.updated_at WHERE o.id = ? AND ' . $deLuat,
                array_merge([$azi, (int) $r['id'], 'amanata', $azi], $vieP)
            )->rowCount() === 1;
            if (!$luata) {
                // A luat-o altă rulare, ori s-a plătit ori s-a mutat între
                // citire și însemnare: nu mai e a noastră.
                continue;
            }
        }
        $ale[] = [
            // Numărul rândului nu pleacă în mesaj: e pentru `plati_amintiri_da_inapoi()`.
            'id'     => (int) $r['id'],
            'ref'    => (string) ($r['ref'] ?: ('#' . (1000 + (int) $r['seq']))),
            'nume'   => (string) $r['customer_name'],
            'suma'   => (float) $r['total'],
            'tel'    => (string) $r['tel'],
            'termen' => (string) $r['plata_scadenta'],
        ];
    }
    return $ale;
}

/**
 * Dă ÎNAPOI amânările luate de rularea asta, când amintirea lor n-a plecat.
 * Întoarce câte a dat înapoi.
 *
 * DE CE EXISTĂ. Raportul picat „nu se mai încearcă” fiindcă vine altul peste
 * câteva ore, cu aceleași cifre aduse la zi. Amintirea nu are un „altul”: se
 * trimite O DATĂ pe amânare, deci o amânare rămasă însemnată după o trimitere
 * picată nu mai era amintită NICIODATĂ — patronul afla de termenul trecut numai
 * dacă deschidea „Încasări”. Văzut la citirea rundei, pe 9 oct. 2026: raportul
 * pleacă, Telegram refuză al doilea mesaj, iar amânarea rămâne „amintită” în
 * bază și tace în zilele +1, +2, +9.
 *
 * DE CE NU PORNEȘTE POTOPUL. Amintirile se iau numai de o rulare care și-a
 * revendicat o ORĂ, iar ora rămâne luată: amânările date înapoi sunt încercate
 * din nou abia la ora următoare — de cel mult două ori pe zi, nu din cinci în
 * cinci minute. Paguba rămasă e cea mică: un mesaj primit de Telegram, dar
 * rămas fără răspuns până la noi, mai vine o dată la raportul următor.
 *
 * NUMAI ALE NOASTRE. `plata_amintire_la = azi`, pe rândurile luate adineauri:
 * amânarea plătită ori mutată între timp și-a șters singură însemnarea
 * (`PATCH /orders/#/plata`) și nu mai e atinsă. Se pune NULL, nu ce era
 * înainte: o însemnare mai veche decât termenul și una goală spun același
 * lucru — „neamintită de la această amânare”.
 */
function plati_amintiri_da_inapoi(array $ids, string $azi): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    if (!$ids || !plati_supported()) {
        return 0;
    }
    $semne = implode(', ', array_fill(0, count($ids), '?'));
    return q(
        'UPDATE orders o SET o.plata_amintire_la = NULL, o.updated_at = o.updated_at'
            . ' WHERE o.id IN (' . $semne . ') AND o.plata_amintire_la = ?',
        array_merge($ids, [$azi])
    )->rowCount();
}

/**
 * O bătaie a ceasului: ce e de trimis ACUM se revendică și se trimite.
 * Întoarce `['cod' => 0|1, 'rand' => string, 'texte' => string[], 'incercat' => bool]`
 * — codul de ieșire, rândul de tipărit, textele care ar fi plecat (numai la
 * `$uscat`) și dacă rularea a avut ceva al ei de trimis.
 *
 * LINIȘTEA DE LA ÎNCEPUT. Fără migrarea 032, fără rândurile ei de setări, fără
 * chat, fără token: ceasul spune într-un rând de ce tace și iese cu 0. Nu e o
 * eroare — fișierele pot ajunge pe server înaintea bazei, iar patronul poate să
 * nu vrea niciun raport. Și NU REVENDICĂ NIMIC cât tace: ora luată azi fără chat
 * ar fi oprit raportul din ziua în care chatul e pus la 08:50.
 *
 * ÎNTÂI REVENDICĂ, APOI TRIMITE — alegerea, spusă pe față. Între cele două se
 * poate rupe ceva: Telegram nu răspunde, procesul e oprit. Două ordini, două
 * pagube:
 *
 *   · trimite, apoi însemnează: dacă însemnarea nu apucă să se scrie, rularea
 *     de peste cinci minute trimite ÎNCĂ O DATĂ — și tot așa, din cinci în
 *     cinci minute, două ore. Lista datornicilor, de douăzeci și patru de ori;
 *   · însemnează, apoi trimite (cea aleasă): dacă trimiterea pică, raportul
 *     acela NU mai pleacă. Rămâne în `notifications` ca `failed`, cu pricina,
 *     la vedere în „Încasări”, iar următorul vine la ora următoare.
 *
 * Un raport lipsă se vede și se poate cere cu butonul de probă; douăzeci și
 * patru de rapoarte la fel nu se mai pot lua înapoi. De aceea: cel mult o dată.
 *
 * AMINTIRILE VIN DUPĂ RAPORT ȘI NUMAI DACĂ EL A PLECAT. Dacă raportul a picat,
 * Telegram nu răspunde acum, iar amânările însemnate drept amintite ar fi fost
 * pierdute pentru nimic. Rămân neluate și pleacă la raportul următor, cu
 * întârzierea spusă în text.
 *
 * IAR DACĂ PICĂ AMINTIREA ÎNSĂȘI (raportul a plecat, al doilea mesaj nu),
 * amânările luate se dau înapoi — `plati_amintiri_da_inapoi()` spune de ce
 * „cel mult o dată” al raportului nu e bun și pentru ea.
 */
function plati_ceas(DateTimeImmutable $acum, bool $uscat = false): array
{
    $liniste = static fn (string $ce): array => ['cod' => 0, 'rand' => $ce, 'texte' => [], 'incercat' => false];
    // Ziua și ora din raport sunt ale patiseriei, oricum ar fi venit clipa.
    $acum    = $acum->setTimezone(new DateTimeZone('Europe/Bucharest'));

    if (!plati_supported() || !notify_event_column()) {
        return $liniste('nimic de făcut: baza nu are încă plățile (migrarea 032)');
    }
    foreach ([1, 2] as $loc) {
        if (setting_raw('plati_raport_trimis_' . $loc) === null) {
            return $liniste('nimic de făcut: lipsesc din bază setările plăților (migrarea 032 neîncheiată)');
        }
    }
    $piedica = plati_trimite_piedica();
    if ($piedica !== null) {
        return $liniste('oprit: ' . $piedica[1]);
    }

    $ore      = plati_raport_ore();
    // Aceeași citire ca în `GET /plati/setari`: gol în bază = neatins = pornite.
    $amintiri = setting('plati_amintiri', '1') === '1';
    // Amândouă orele oprite: nu mai pleacă niciun raport. Amintirile, dacă au
    // rămas pornite, își țin ora lor și locul întâi, rămas fără stăpân.
    $doarAmintiri = $ore[0] === '' && $ore[1] === '';
    if ($doarAmintiri) {
        if (!$amintiri) {
            return $liniste('oprit: nicio oră de raport aleasă, iar amintirile sunt oprite');
        }
        $ore = [GP_PLATI_ORA_AMINTIRI, ''];
    }

    $azi = $acum->format('Y-m-d');
    $ale = [];
    foreach (plati_ceas_ore_coapte($acum, $ore) as $o) {
        if ($uscat ? plati_ceas_ora_libera($o['loc'], $o['zi'], $azi) : plati_ceas_ia_ora($o['loc'], $o['zi'], $azi)) {
            $ale[] = $o;
        }
    }
    if (!$ale) {
        return $liniste('nimic de trimis');
    }
    $orele = implode(' și ', array_column($ale, 'ora'));

    $parti = [];
    $texte = [];
    $cod   = 0;
    $merge = true;

    if (!$doarAmintiri) {
        $text = plati_raport_text(plati_raport_date($azi), $acum);
        if ($uscat) {
            $texte[] = $text;
            $parti[] = 'ar pleca raportul de la ' . $orele;
        } else {
            $r = plati_trimite('raport', $text, plati_raport_buton('Deschide Încasări', 'deincasat'));
            if ($r['ok']) {
                $parti[] = 'raportul de la ' . $orele . ' a plecat';
            } else {
                $merge   = false;
                $cod     = 1;
                $parti[] = 'raportul de la ' . $orele . ' NU a plecat și nu se mai încearcă: ' . $r['motiv'];
            }
        }
    }

    if ($amintiri && $merge) {
        $deAmintit = plati_amintiri_ia($azi, $uscat);
        if ($deAmintit) {
            $cate = plati_raport_cate(count($deAmintit), 'amânare', 'amânări');
            $text = plati_amintiri_text($deAmintit, $azi);
            if ($uscat) {
                $texte[] = $text;
                $parti[] = 'ar pleca amintirea pentru ' . $cate;
            } else {
                $r = plati_trimite('amintire', $text, plati_raport_buton('Vezi amânatele', 'amanate'));
                if ($r['ok']) {
                    $parti[] = 'amintirea pentru ' . $cate . ' a plecat';
                } else {
                    // Altfel decât raportul: amânările se dau înapoi, ca
                    // amintirea să fie încercată din nou la ora următoare.
                    plati_amintiri_da_inapoi(array_column($deAmintit, 'id'), $azi);
                    $cod     = 1;
                    $parti[] = 'amintirea pentru ' . $cate . ' NU a plecat; se încearcă din nou la ora următoare: ' . $r['motiv'];
                }
            }
        } elseif ($doarAmintiri) {
            $parti[] = 'nicio amânare de amintit';
        }
    }

    return ['cod' => $cod, 'rand' => implode(' · ', $parti), 'texte' => $texte, 'incercat' => true];
}
