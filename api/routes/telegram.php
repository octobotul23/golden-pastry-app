<?php
/**
 * Butoanele de sub anunțul primit pe Telegram.
 *
 * O cerere nouă de pe site ajunge pe telefonul patronului cu două butoane sub
 * ea — „Acceptă" și „Respinge" — iar apăsarea lor face EXACT ce ar fi făcut
 * apăsarea din „Administrare Comenzi". Nu ceva asemănător: aceleași rute,
 * aceleași validări, același rând în Jurnal.
 *
 * DOUĂ, fiindcă atât a cerut patronul: de pe telefon se ia hotărârea — intră
 * cererea în producție sau nu —, iar acceptarea o naște de-a dreptul în prima
 * etapă, unde o vede tot laboratorul pe ecranul lui. De acolo încolo e treaba
 * angajaților s-o împingă mai departe, de la tabletă, unde au ziua întreagă
 * sub ochi. Au fost o vreme și „🕒 Mai târziu" pe cerere, și „👀 Am văzut" /
 * „➡️ Etapa următoare" pe comandă: o etapă schimbată cu degetul, dintr-o listă
 * de anunțuri, fără să vezi restul zilei, e mai ușor de greșit decât de
 * îndreptat.
 *
 * De ce contează atât de mult „exact": `route_requests_accept()` are trei sute
 * de rânduri care verifică ziua cerută, intervalul, zona, modul de onorare,
 * nasc comanda, rezervă stocul, scriu comisionul și leagă contul
 * cumpărătorului. Scrise a doua oară aici, ele ar fi început să se depărteze de
 * la prima reparație făcută numai într-una din ele — iar cererea acceptată de
 * pe telefon s-ar fi purtat, într-o zi, altfel decât aceeași cerere acceptată
 * din panou. Fără ca nimeni să bage de seamă, fiindcă amândouă merg.
 *
 * Cum se trece prin ele, deci, fără cookie și fără formular:
 *
 *   1. `act_as()`  (api/lib/auth.php) — cererea lucrează pe contul ales de
 *      `telegram_actor()`: al omului care a apăsat, dacă și l-a legat; altfel
 *      al celui care a legat botul. Se împrumută CONTUL, nu dreptul: poarta
 *      rutei (`require_drept('comenzi.scrie')`) se execută în continuare, pe
 *      contul acela. Și, de la 2.7.5, nu se mai împrumută NUMELE: cine apasă
 *      fără cont legat își poartă numele lui de pe Telegram, cu „cont nelegat”.
 *   2. `body()`    (api/lib/http.php) — corpul adevărat al cererii e mesajul
 *      trimis de Telegram, care n-are ce căuta în rută; i se dă tabloul gol,
 *      adică „ia toate câmpurile din cerere, cum sunt".
 *   3. O funcție de încheiere (`telegram_incheie`), fiindcă rutele se termină
 *      cu `json_out()`, care face `exit`. De acolo nu se mai întoarce nimeni,
 *      deci toastul din Telegram se dă din `register_shutdown_function()` —
 *      singurul loc căruia PHP îi mai dă cuvântul după `exit`.
 *
 * Cine ar fi putut apăsa din afara casei: nimeni. Trei porți, toate necesare,
 * în ordinea în care se verifică mai jos — secretul din antet, potrivit cu cel
 * din Setări; conversația, potrivită cu `notify_telegram_chat_id`; și contul:
 * al celui care a apăsat, încă activ și cu dreptul `comenzi.scrie`, ori — pentru
 * omul din grup cu contul nelegat — cel care a legat botul, încă activ și încă
 * administrator.
 *
 * CINE APASĂ ÎN GRUP E OMUL CASEI, și de aceea apăsarea celui nelegat merge:
 * așa a hotărât patronul (runda 2.7.5). Ce nu mai merge e ca ea să poarte
 * numele ALTCUIVA — v. `telegram_actor()` și api/lib/telegram-legaturi.php.
 */

declare(strict_types=1);

// Ruta prin care trec faptele. Dispecerul din index.php încarcă un SINGUR
// fișier de rute per cerere (`require_once __DIR__ . '/routes/' . $file`), deci
// ce cheamă fișierul ăsta trebuie cerut aici, cu mâna. Sunt numai definiții de
// funcții, fără nimic care să se execute la încărcare.
//
// `orders.php` stătea alături până acum, pentru butonul „Etapa următoare", care
// trecea prin `route_orders_update()`. Odată cu butonul a plecat și el: un
// fișier de rute cerut degeaba se încarcă la fiecare apăsare și nu se vede
// nicăieri că e de prisos.
require_once __DIR__ . '/requests.php';

/* ==================================================== apăsarea, primită == */

/**
 * POST /telegram/webhook — aici bate Telegram când cineva apasă un buton.
 *
 * Publică prin forța lucrurilor: cererea vine de la serverele Telegram, nu
 * dintr-un browser, deci n-are cookie și n-are cum avea token CSRF. Ce ține loc
 * de amândouă e secretul din antetul `X-Telegram-Bot-Api-Secret-Token`, pe
 * care îl generăm noi la legarea botului și pe care Telegram îl trimite înapoi
 * la fiecare apăsare.
 *
 * REGULA DE AUR A ACESTEI RUTE: îi răspunde lui Telegram ÎNTOTDEAUNA cu 200.
 * Telegram reîncearcă la orice alt cod, cu insistență, iar o cerere deja
 * acceptată răspunde a doua oară 409 — deci un 409 lăsat să iasă ar naște o
 * buclă care se oprește abia când Telegram renunță la webhook cu totul. Ce s-a
 * întâmplat de fapt se spune omului în toast, nu codului HTTP.
 */
function route_telegram_webhook(): void
{
    $update = body();

    // --------------------------------------------------- poarta 1: secretul
    //
    // `hash_equals`, nu `===`: comparația obișnuită se oprește la primul
    // caracter diferit, iar din cât durează se poate ghici secretul, literă cu
    // literă. Aici nu e paranoia — ruta e publică și cine trece de ea poate
    // accepta cereri în locul patronului.
    $secret = setting('notify_telegram_webhook_secret');
    $trimis = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($secret === '' || $trimis === '' || !hash_equals($secret, $trimis)) {
        error_log('[gp] telegram: apăsare cu secret nepotrivit, respinsă');
        // 403, nu 200: Telegram nu ajunge niciodată aici cu secretul greșit,
        // deci nu există buclă de reîncercări de care să ne ferim. Cine ajunge
        // e altcineva, și merită un refuz limpede.
        fail('Secret nepotrivit.', 403, 'bad_webhook_secret');
    }

    // Orice altceva decât o apăsare de buton — un mesaj scris botului, o
    // editare, o intrare în grup — se primește politicos și se aruncă. Fără
    // ramura asta, Telegram ar primi 500 pentru fiecare „bună ziua" scris
    // botului din greșeală și ar începe să reîncerce.
    $cb = $update['callback_query'] ?? null;
    if (!is_array($cb) || !isset($cb['id'])) {
        json_out(['ok' => true]);
    }

    // ------------------------------------------- poarta 2: chiar conversația
    //
    // Secretul singur n-ar ajunge dacă botul e adăugat, din nebăgare de seamă,
    // într-un grup: acolo apasă oricine. Fapta se primește numai din
    // conversația scrisă în Setări — aceeași din care pleacă anunțurile.
    $chat    = (string) ($cb['message']['chat']['id'] ?? '');
    $asteptat = setting('notify_telegram_chat_id');
    if ($asteptat === '' || $chat === '' || !hash_equals($asteptat, $chat)) {
        error_log('[gp] telegram: apăsare din altă conversație (' . $chat . '), ignorată');
        telegram_spune($cb, false, 'Apăsarea nu vine din conversația configurată în Setări.');
        json_out(['ok' => true]);
    }

    // Omul ăsta e în grup și tocmai a apăsat: se ține minte, pentru lista
    // „Oameni din grup” din Setări. DUPĂ poarta conversației, nu înaintea ei —
    // altfel lista s-ar umple cu oricine nimerește botul din altă parte — și
    // ÎNAINTEA oricărui refuz de mai jos: și cel refuzat trebuie să apară în
    // listă, fiindcă tocmai pe el îl are fondatorul de legat.
    telegram_om_vazut($cb['from'] ?? null);

    // ------------------------------------------------------------- fapta
    //
    // `callback_data` are 64 de octeți cu totul, de aceea forma e scurtă:
    // literă, două puncte, id, două puncte, literă. Vezi `telegram_request_buttons()`
    // din api/lib/notify.php pentru cum se scrie.
    //
    // DESPICATĂ ÎNAINTEA PORȚII 3, de la 2.2.1 încoace. Pricina e o capcană de
    // ordine: poarta 3 cere un cont pe numele căruia să lucreze apăsarea, dar
    // LEGAREA e tocmai fapta omului care încă n-are niciun cont legat. Lăsată
    // după poartă, singura apăsare de care are nevoie cel nelegat ar fi fost
    // singura pe care n-ar fi putut-o face.
    //
    // Mutarea nu slăbește nimic: porțile 1 și 2 — secretul și conversația — au
    // trecut deja, și numai ele hotărăsc cine ajunge aici. Poarta 3 nu spune
    // „cine intră", ci „pe numele cui se scrie fapta".
    $date = explode(':', (string) ($cb['data'] ?? ''));
    if (count($date) !== 3 || !ctype_digit($date[1])) {
        telegram_spune($cb, false, 'Buton necunoscut. Probabil e dintr-un mesaj mai vechi decât programul.');
        json_out(['ok' => true]);
    }
    [$fel, $id, $fapta] = [$date[0], (int) $date[1], $date[2]];

    // ------------------------------------------ legarea: fapta fără actor
    //
    // Nu hotărăște nimic și nu atinge nicio cerere: scrie doar CINE a apăsat,
    // pe rândul cererii de legare. Legătura însăși se face din pagina celui
    // care a cerut-o, cu sesiunea lui — v. api/lib/telegram-legaturi.php.
    if ($fel === 'leg') {
        telegram_buton_legare($cb, $id, $fapta);
        json_out(['ok' => true]);
    }

    // ------------------------------------------------- poarta 3: omul
    $actor = telegram_actor($cb, $pricina);
    if ($actor === null) {
        // Fiecare refuz își spune pricina și ce e de făcut: omul ține telefonul
        // în mână, nu panoul, și n-are de unde afla altfel de ce n-a mers.
        // (Toastul ține 190 de litere — v. `telegram_toast()`.)
        telegram_spune($cb, false, match ($pricina) {
            'fara_drept' => 'Contul tău e legat de Telegram, dar e oprit ori nu are dreptul de a scrie comenzi. '
                . 'Nu am scris nimic. Cere dreptul din pagina „Acces”, apoi apasă din nou.',
            'fara_id' => 'Nu pot citi cine a apăsat, deci nu am scris nimic. Încearcă din nou.',
            'nestiut' => 'Nu pot afla acum dacă Telegramul tău e legat de un cont, deci nu am scris nimic. '
                . 'Apasă din nou peste un minut.',
            'fara_036' => 'Contul tău de Telegram nu e legat de niciun cont, iar serverul n-are încă unde să-ți '
                . 'scrie numele: lipsește migrarea sql/036_telegram_oameni.sql. Nu am scris nimic.',
            default => 'Botul nu e legat de niciun cont de administrator. Intră în Setări → Notificări și apasă „Leagă botul".',
        });
        json_out(['ok' => true]);
    }

    // Două ramuri, și `default` pentru tot restul. Butoanele scoase — „Mai
    // târziu" pe cerere, „Am văzut" și „Etapa următoare" pe comandă — zac încă
    // sub mesajele vechi din conversație, iar Telegram trimite apăsarea oricât
    // de vechi ar fi mesajul. Pe ele nu se mai face nimic, dar omul află de ce,
    // în loc să se uite la o rotiță care se învârte până expiră.
    match ($fel . ':' . $fapta) {
        'r:a'   => telegram_cerere_acceptata($cb, $actor, $id),
        'r:x'   => telegram_cerere_respinsa($cb, $actor, $id),
        default => telegram_spune($cb, false,
            'Butonul ăsta nu mai există. Sub anunțuri au rămas „Acceptă" și „Respinge"; '
                . 'restul se face din panou.'),
    };

    json_out(['ok' => true]);
}

/* ------------------------------------------------------- cele două fapte -- */

/** „Acceptă" — cererea devine comandă, exact ca din panou. */
function telegram_cerere_acceptata(array $cb, array $actor, int $id): void
{
    telegram_prin_ruta($cb, $actor, [], static fn() => route_requests_accept((string) $id), [
        'ok'   => 'Cererea a fost acceptată. Comanda e în panou.',
        // Urma poartă numele celui care a apăsat — v. `telegram_semn()`.
        'semn' => telegram_semn('✅ Acceptată', $actor),
    ]);
}

/** „Respinge" — cererea se închide, cu motivul scris în ea. */
function telegram_cerere_respinsa(array $cb, array $actor, int $id): void
{
    telegram_prin_ruta(
        $cb,
        $actor,
        // Motivul se scrie, nu se lasă gol: pagina de cereri îl arată, iar
        // „respinsă, fără motiv" e exact răspunsul pe care nu-l poate lămuri
        // nimeni la telefon, a doua zi, când sună clientul.
        ['reason' => 'Respinsă din Telegram'],
        static fn() => route_requests_reject((string) $id),
        ['ok' => 'Cererea a fost respinsă.', 'semn' => telegram_semn('❌ Respinsă', $actor)]
    );
}

/*
 * Aici au stat celelalte trei fapte: „Mai târziu" (amâna cererea cu două
 * ceasuri, scriind `order_requests.snoozed_until`), „Am văzut" (stingea rândul
 * din `notifications`) și „Etapa următoare" (împingea comanda pe fluxul din
 * laborator, prin `route_orders_update()`). Au plecat toate trei odată,
 * la cererea patronului.
 *
 * Coloana `order_requests.snoozed_until` a RĂMAS în bază, deși nimeni nu-i mai
 * scrie o oră: o migrare adaugă, nu dărâmă, iar sql/013 nu se rescrie după ce
 * s-a aplicat pe serverele oamenilor. Ce mai are de-a face cu ea programul stă
 * scris la `requests_mark_decided()`, în api/routes/requests.php — acolo se
 * golește la fiecare hotărâre, ca orele rămase de dinainte de schimbare să nu
 * mai încurce pe nimeni.
 */

/**
 * „Eu sunt" — apăsarea care PROPUNE o legătură, fără să lege nimic.
 *
 * Singura faptă de sub anunțuri care nu cere un actor: v. lămurirea din
 * dispecer. Nu atinge nicio comandă și nicio cerere, nu scrie în Jurnal —
 * fiindcă n-are pe numele cui să scrie, și tocmai asta se hotărăște aici.
 *
 * Toastul spune pe față ce s-a întâmplat și ce urmează, ca omul să nu creadă
 * că e gata: apăsarea e jumătate de faptă, cealaltă jumătate e la el pe ecran.
 */
function telegram_buton_legare(array $cb, int $linkId, string $jeton): void
{
    // Numele NU se mai citește aici, bucată cu bucată, din ce a trimis
    // Telegram: vine prin singura ușă a numelor străine, curățat și tăiat
    // (`telegram_apasator_din()`, api/lib/telegram-legaturi.php). Ecranul de
    // încuviințare îl arată întocmai, iar Jurnalul îl scrie la legare.
    $ap = telegram_apasator_din($cb['from'] ?? null);
    if ($ap === null) {
        telegram_spune($cb, false, 'Nu pot citi cine a apăsat. Încearcă din nou.');
        return;
    }
    // Lângă nume, și `@utilizator`: pe ecranul de încuviințare doi „Ion” din
    // grup se deosebesc numai după el.
    $nume = $ap['nume'] . ($ap['user'] !== '' && $ap['nume'] !== '@' . $ap['user'] ? ' @' . $ap['user'] : '');

    $r = telegram_legare_apasata($linkId, $jeton, $ap['tg'], $nume);
    match ($r['stare']) {
        'legat_propus' => telegram_spune($cb, true,
            'Am însemnat că ai apăsat tu. Intră în Setări → Notificări, pe contul tău, '
                . 'și încuviințează — abia atunci apăsările încep să-ți poarte numele.'),
        'expirat' => telegram_spune($cb, false,
            'Cererea de legare a expirat. Cere una nouă din Setări → Notificări.'),
        'deja' => telegram_spune($cb, false,
            'Legătura asta s-a făcut deja. Dacă vrei să o schimbi, dezleagă întâi din Setări.'),
        default => telegram_spune($cb, false,
            'Butonul ăsta nu mai e bun. Probabil e dintr-un mesaj vechi; cere o legare nouă din Setări.'),
    };
}

/* =================================================== mașinăria de trecere = */

/**
 * Contul pe numele căruia lucrează apăsarea, sau `null`.
 *
 * AICI A SCRIS, PÂNĂ LA 2.2.1: „scris la legarea botului, nu ghicit din cine a
 * apăsat: identitățile din Telegram nu se pot potrivi în niciun fel cu
 * conturile din aplicație, iar un «cine o fi» scris în Jurnal e mai rău decât
 * nimic".
 *
 * Era adevărat, și era răspunsul cinstit: nu exista nicio potrivire. Ce s-a
 * schimbat nu e hotărârea, ci temeiul ei — `api/lib/telegram-legaturi.php` face
 * potrivirea care lipsea, iar omul și-o scrie singur pe cont, în doi pași, din
 * sesiunea lui (de la 2.7.5 i-o poate scrie și fondatorul, din „Oameni din
 * grup”, la capătul fișierului). Nu se mai ghicește nimic: sau se ȘTIE cine a
 * apăsat, sau se cade înapoi pe actorul global, exact ca înainte.
 *
 * Fără pasul ăsta, lucrarea care scoate autorul la vedere ar fi scris un nume
 * GREȘIT pe ecran, tocmai pe calea pentru care a fost cerută — ceea ce e mai
 * rău decât nepotrivirea de până acum, ascunsă în bază.
 *
 * CE A RĂMAS NEREPARAT PÂNĂ LA 2.7.5, și a cerut patronul: căderea „pe actorul
 * global” îi dădea omului nelegat, odată cu drepturile contului care a legat
 * botul, și NUMELE lui. Botul stă într-un grup; oricine apăsa „Acceptă”,
 * mesajul, panoul și Jurnalul spuneau că a acceptat patronul. Hotărârea lui:
 * apăsarea celui nelegat MERGE, dar poartă numele LUI de pe Telegram și vorba
 * „cont nelegat” — niciodată numele altcuiva.
 *
 * PATRU IEȘIRI, și a doua e miezul:
 *
 *   1. apăsătorul e legat, contul e      → lucrează pe contul LUI, cu numele
 *      activ și are `comenzi.scrie`        lui — e chiar el
 *   2. apăsătorul e legat, dar contul    → `null` + pricina „fara_drept”; nu se
 *      e oprit ori fără dreptul acela      scrie nimic și NU se cade pe contul
 *                                          botului: căderea i-ar da unui om
 *                                          oprit drepturile altuia
 *   3. apăsătorul nu e legat             → contul botului ca purtător de
 *                                          DREPTURI, plus cheia `_apasator`
 *                                          (cine a apăsat) din care se ia
 *                                          NUMELE peste tot. Fără migrarea 036
 *                                          numele n-are unde se scrie: `null` +
 *                                          pricina „fara_036”
 *   4. botul n-are un cont bun în spate  → `null` + pricina „nelegat”
 *
 * Și două înaintea tuturor. Apăsarea fără `from.id` e refuzată („fara_id”):
 * fără el nu se poate ști nici dacă omul e legat, nici ce nume să poarte fapta.
 * Iar când baza nu poate spune DACĂ omul e legat („nestiut”), apăsarea se
 * refuză și ea: ieșirea 3 e pentru cel despre care se ȘTIE că n-are cont, nu
 * pentru cel despre care nu s-a putut afla — altfel o bâlbâială a bazei i-ar
 * deschide celui de la ieșirea 2 tocmai calea pe care i-o închide ea.
 *
 * DREPTUL CELUI LEGAT E `comenzi.scrie`, NU RANGUL. Până la 2.7.5 aici scria
 * `GP_ADMIN_ROLES`, rămas de când acceptarea era `require_admin()`. Ruta cere
 * de mult dreptul din matrice (`require_drept('comenzi.scrie')`), deci un
 * manager legat trecea de poarta rutei și era oprit aici, de o listă care nu
 * mai hotăra nimic altundeva. Întrebarea e aceeași în amândouă locurile, ca
 * bifa din „Acces” să însemne același lucru în panou și pe telefon.
 *
 * CONTUL BOTULUI rămâne judecat după rang (activ și administrator): e
 * întrebarea „al cui cuvânt îl poartă botul”, care nu se dă cu o bifă — v.
 * `GP_ADMIN_ROLES`, api/lib/auth.php.
 *
 * Se verifică de fiecare dată, nu o singură dată la legare: între timp contul
 * poate fi oprit ori rămâne fără drept, și atunci butoanele trebuie să
 * înceteze să lucreze pe loc — la fel ca sesiunile, care se închid când se
 * schimbă codul tabletelor.
 *
 * @param array|null   $cb      apăsarea, din care se citește `from`
 * @param string|null  $pricina „fara_id” | „nestiut” | „fara_drept” | „fara_036” |
 *                              „nelegat”, pentru vorba din toast
 */
function telegram_actor(?array $cb = null, ?string &$pricina = null): ?array
{
    $pricina = null;

    // --- întâi cine a apăsat CHIAR ACUM ---------------------------------
    $ap = telegram_apasator_din($cb['from'] ?? null);
    if ($ap === null) {
        $pricina = 'fara_id';
        error_log('[gp] telegram: apăsare fără `from.id`, oprită');
        return null;
    }
    $aflat = true;
    $alLui = telegram_contul_dupa_id($ap['tg'], $aflat);
    if (!$aflat) {
        // „N-AM PUTUT AFLA” NU E „NU E LEGAT”. Baza a picat tocmai pe
        // întrebarea „al cui e Telegramul ăsta?”. Luat drept „al nimănui”,
        // răspunsul i-ar fi dat omului drepturile contului botului — și celui
        // legat de un cont oprit ori rămas fără drept, pe care ieșirea 2 de
        // mai jos îl oprește. Deci nu se scrie nimic, iar omul apasă din nou.
        $pricina = 'nestiut';
        error_log('[gp] telegram: nu pot afla dacă apăsătorul e legat de un cont; apăsare oprită');
        return null;
    }
    if ($alLui !== null) {
        if ((int) $alLui['active'] === 1 && drept('comenzi.scrie', $alLui)) {
            return $alLui;
        }
        // Legat, dar oprit ori fără drept. Aici NU se cade pe contul botului:
        // căderea i-ar da unui om căruia casa i-a luat dreptul drepturile
        // altuia — și, până la 2.7.5, i-ar fi scris fapta pe numele aceluia.
        $pricina = 'fara_drept';
        error_log('[gp] telegram: apăsare de la un cont legat fără drept (#' . $alLui['id'] . '), oprită');
        return null;
    }

    // --- altfel, omul din grup cu contul nelegat ------------------------
    //
    // Lucrează pe DREPTURILE contului care a legat botul. Ordinea porților
    // contează pentru vorba din toast: întâi „botul n-are cont” (de reparat din
    // Setări), abia apoi „lipsește migrarea” (de reparat pe server).
    $id = (int) setting('notify_telegram_actor_id');
    if ($id <= 0) {
        $pricina = 'nelegat';
        return null;
    }
    $u = one(
        'SELECT id, username, name, email, phone, address, role, customer_name,
                active, must_change_password, is_system
           FROM users WHERE id = ?',
        [$id]
    );
    if (!$u || (int) $u['active'] !== 1 || !in_array($u['role'], GP_ADMIN_ROLES, true)) {
        $pricina = 'nelegat';
        return null;
    }
    if (!telegram_apasatori_suportati()) {
        // Fără cele două coloane de pe cerere, numele omului n-are unde se
        // scrie, iar hotărârea ar ieși iar pe numele contului de mai sus —
        // adică minciuna scoasă din casă, pusă la loc de o migrare nerulată.
        // Mai bine nimic decât un nume greșit: apăsarea se refuză, iar toastul
        // numește fișierul.
        // Fără numărul lui de Telegram în logul serverului: numește un om în
        // afara aplicației, iar pricina se citește întreagă și fără el.
        $pricina = 'fara_036';
        error_log('[gp] telegram: apăsare de la un om cu contul nelegat, iar migrarea 036 lipsește; oprită');
        return null;
    }
    // CINE A APĂSAT stă de-acum pe rândul contului împrumutat. De aici își iau
    // numele Jurnalul (`jurnal_eticheta()`), urma de sub mesaj
    // (`telegram_semn()`), anunțul și panoul (`act_as_apasator()`, citit în
    // api/routes/requests.php). Cheia se pune numai aici, din singura ușă a
    // numelor străine — deci tot ce o citește primește un nume deja curățat.
    $u['_apasator'] = $ap;

    // `csrf_token` lipsește dinadins: pe drumul ăsta nu există sesiune, deci
    // nici token. `check_csrf()` a trecut deja, înaintea rutei, pe o cerere
    // fără cookie — iar `current_user()` nu era încă împrumutat atunci.
    return $u;
}

/**
 * Trece fapta prin ruta adevărată și încheie discuția cu Telegram.
 *
 * Rutele se termină cu `json_out()`, care face `exit`. Nu e o scăpare, e
 * purtarea lor firească — un răspuns e un răspuns. Dar aici mai e ceva de
 * făcut după el: toastul din Telegram, și urma scrisă sub mesajul din
 * conversație. Singurul loc căruia PHP îi mai dă cuvântul după `exit` e o
 * funcție înregistrată cu `register_shutdown_function()`.
 *
 * `ob_start()` de mai jos nu e decor. PHP trimite antetele la prima ieșire
 * adevărată; cu un buffer deschis, ce scrie ruta rămâne în el, antetele rămân
 * nescrise, iar funcția de încheiere poate încă schimba codul de răspuns în
 * 200 — regula de aur a rutei ăsteia. Fără buffer, un `fail(…, 409)` din rută
 * ar fi plecat ca atare, iar Telegram ar fi reîncercat aceeași apăsare la
 * nesfârșit.
 */
function telegram_prin_ruta(array $cb, array $actor, array $corp, callable $ruta, array $texte): void
{
    act_as($actor, true);
    body($corp);

    // Stratul ăsta își spune numele o dată, aici. Mai jos rulează CHIAR rutele
    // panoului, iar ele n-au de unde ști că apăsarea a venit de pe telefon:
    // dispecerul din api/index.php le cheamă cu argumente fixe, deci nu era pe
    // unde să li se spună. De aici încolo `decided_via` se scrie „telegram"
    // (`requests_decided_via()`, api/routes/requests.php).
    //
    // Aici, nu în fiecare buton: asta e singura ușă prin care trec faptele de
    // sub anunț, iar amândouă hotărăsc ceva. Rămâne scris o singură dată și
    // atunci când s-ar mai adăuga una.
    requests_decided_via('telegram');

    ob_start();
    register_shutdown_function('telegram_incheie', $cb, $texte);

    $ruta();

    // Nu se ajunge aici: ruta se încheie cu `json_out()`. Dacă totuși se
    // ajunge, dispecerul din index.php spune „Ruta nu a produs niciun răspuns"
    // și tot prin `json_out()` iese — deci încheierea se face oricum.
}

/**
 * Ce se întâmplă după ce ruta a spus ce avea de spus.
 *
 * Se cheamă din `register_shutdown_function`, deci codul de răspuns și corpul
 * scris de rută sunt gata — numai că niciunul n-a plecat încă, fiindcă
 * `telegram_prin_ruta()` a deschis un buffer.
 */
function telegram_incheie(array $cb, array $texte): void
{
    // Se golesc TOATE nivelurile: pe găzduirile cu `output_buffering` pornit
    // din php.ini mai e unul deschis de la începutul cererii.
    $corp = '';
    while (ob_get_level() > 0) {
        $corp .= (string) ob_get_clean();
    }

    $cod = (int) http_response_code();
    $ok  = $cod >= 200 && $cod < 300;

    // DE CE AR PUTEA FI ANTETELE DEJA PLECATE, deși bufferul de mai sus tocmai
    // pentru asta s-a deschis: câteva rute răspund înainte să-și termine treaba
    // lungă, prin `json_out_early()` (api/lib/http.php). Ea golește TOATE
    // bufferele, scrie `Content-Length` și închide conexiunea, ca browserul să
    // nu aștepte cât pleacă anunțurile. Pe drumul nostru atinge un singur
    // buton: „Acceptă" — de când acceptarea unei cereri trimite și ea anunțul
    // „Comandă nouă" (`api/routes/requests.php`).
    //
    // Nu e o pagubă, și de aceea nu se oprește nimic aici: `json_out_early()`
    // se cheamă NUMAI pe drumurile bune, deci codul care scapă nerescris e din
    // familia 200 — 201 la acceptare, fiindcă s-a născut o comandă. Pentru
    // Telegram orice 2xx înseamnă „primit", deci nu intră în bucla de
    // reîncercări de care ne ferim. Ce n-are voie să iasă de aici e un cod de
    // EROARE, iar acela vine numai pe drumuri care nu ajung la
    // `json_out_early()` — deci trece prin rescrierea de mai jos. Singura
    // urmare e că
    // toastul pleacă după anunțuri, adică poate după cele cincisprezece secunde
    // ale lui Telegram: atunci rotița de pe buton se stinge singură, fără text.
    // Urma scrisă sub mesaj — „✅ Acceptată de …, de pe Telegram" — ajunge
    // oricum, și ea e cea care se citește a doua zi.
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{"ok":true}';
    } else {
        error_log('[gp] telegram: răspunsul plecase deja (' . $cod . '), toastul poate întârzia');
    }

    telegram_toast($cb, $ok, $ok ? $texte['ok'] : telegram_mesaj_eroare($corp),
        $ok ? ($texte['semn'] ?? '') : '');
}

/**
 * Ce a păţit fapta, scos din răspunsul rutei.
 *
 * UN SINGUR REFUZ ARE VORBA LUI, alta decât a rutei: `prag_neplatite` (runda
 * 2.7.0, hotărârea patronului din 4–5 octombrie 2026). Clientul are prea multe
 * comenzi neplătite, iar acceptarea de sub anunț nu poate trece peste prag —
 * trecerea cere o confirmare, și un buton de pe telefon n-are unde s-o arate.
 * Mesajul rutei („… Nu am scris nimic.”) e scris pentru panou, unde lângă el
 * stă butonul de confirmare; aici ar fi lăsat omul fără pasul următor. Deci i
 * se spune pe scurt ce e și unde se face.
 *
 * Se recunoaște după COD, nu după vorbe: mesajul rutei se poate rescrie, codul
 * e contractul cu paginile. Iese tot ca fereastră (`show_alert`), ca orice
 * eșec, iar butoanele rămân sub anunț — cererea e tot „nou”.
 */
function telegram_mesaj_eroare(string $corp): string
{
    $j = json_decode($corp, true);
    if (is_array($j) && ($j['error']['code'] ?? '') === 'prag_neplatite') {
        return 'Clientul are comenzi neplătite peste prag. Acceptă cererea din panou.';
    }
    $m = is_array($j) ? (string) ($j['error']['message'] ?? '') : '';
    return $m !== '' ? $m : 'Nu s-a putut. Intră în panou și verifică.';
}

/**
 * Răspunde unei apăsări care NU trece prin nicio rută.
 *
 * Aceeași încheiere ca mai sus, dar fără dansul cu bufferele: aici n-a scris
 * nimeni nimic și nimeni n-a chemat `exit`.
 */
function telegram_spune(array $cb, bool $ok, string $mesaj, string $semn = ''): void
{
    telegram_toast($cb, $ok, $mesaj, $ok ? $semn : '');
}

/**
 * Urma scrisă sub mesaj, plus toastul de pe ecranul telefonului.
 *
 * ÎN ORDINEA ASTA, ȘI ÎN DOUĂ BLOCURI NEATÂRNATE. Multă vreme amândouă au stat
 * într-un singur `try`, cu toastul primul, iar asta lega lucrul temeinic de cel
 * trecător: prima chemare care arunca sărea peste a doua cu totul.
 *
 * Și tocmai prima era cea care cade. Toastul are termen — cincisprezece
 * secunde de la apăsare — dar pleacă de-abia din `register_shutdown_function()`,
 * adică DUPĂ ce ruta și-a trimis anunțurile pe Telegram, pe e-mail și pe
 * WhatsApp (vezi lămurirea de la `telegram_incheie()`). Pe o dimineață cu
 * rețeaua înceată fereastra aceea se poate cheltui întreagă, și atunci
 * `answerCallbackQuery` răspunde „query is too old". Într-un `try` comun,
 * hotărârea rămânea nescrisă din pricina unei curtoazii care expirase.
 *
 * CE E URMA. „✅ Acceptată de Ion Pop, de pe Telegram" / „❌ Respinsă de …" —
 * cu numele celui care a apăsat, cum o scrie `telegram_semn()`
 * (api/lib/telegram-legaturi.php) —, pusă sub mesajul de anunț, cu butoanele
 * scoase odată cu ea — `reply_markup` lipsă
 * din `editMessageText` înseamnă „fără butoane". E singurul lucru care rămâne
 * a doua zi: fără ea, conversația e plină de cereri identice, toate cu
 * butoanele intacte, și nu se mai poate afla care a fost hotărâtă. Și e tot ce
 * împiedică a doua apăsare pe același mesaj — al doilea om care deschide
 * conversația apasă peste hotărârea primului fără să știe că a fost una.
 * Urmele sunt două fiindcă butoanele sunt două; cele ale butoanelor scoase —
 * „👀 Văzută", „➡️ Trecută pe …", „🕒 Amânată" — au plecat odată cu ele.
 *
 * CE E TOASTUL. Rotița de pe buton, care fără `answerCallbackQuery` se învârte
 * până expiră singură, iar omul apasă din nou crezând că n-a nimerit — adică
 * hotărăște a doua oară. Rămâne obligatoriu, dar e al doilea: dacă pică, pică
 * singur, și pierderea e o rotiță, nu o hotărâre.
 *
 * Urma pleacă acum cu o chemare mai devreme, deci toastul întârzie cu atât cât
 * ține ea. E prețul plătit dinadins: mai bine o rotiță stinsă fără text decât
 * un mesaj care nu spune că s-a hotărât.
 */
function telegram_toast(array $cb, bool $ok, string $mesaj, string $semn = ''): void
{
    $msgId = (int) ($cb['message']['message_id'] ?? 0);
    $chat  = (string) ($cb['message']['chat']['id'] ?? '');
    if ($semn !== '' && $msgId > 0 && $chat !== '') {
        try {
            $vechi = trim((string) ($cb['message']['text'] ?? ''));
            telegram_api('editMessageText', [
                'chat_id'    => $chat,
                'message_id' => $msgId,
                'text'       => ($vechi !== '' ? $vechi . "\n\n" : '') . $semn,
            ]);
        } catch (Throwable $e) {
            // Fapta e făcută și scrisă în Jurnal; ce se pierde aici e mărturia
            // de sub mesaj. Se scrie în logul serverului tocmai fiindcă e
            // singura pierdere care nu se vede nicăieri altundeva.
            error_log('[gp] telegram: urma de sub mesaj nu s-a scris: ' . $e->getMessage());
        }
    }

    try {
        telegram_api('answerCallbackQuery', [
            'callback_query_id' => (string) $cb['id'],
            // 200 de caractere e limita lui Telegram; tăiem cu margine, pe
            // caractere, nu pe octeți — un „ă" tăiat pe jumătate ar face
            // mesajul nevalid și toastul n-ar mai apărea deloc.
            'text'       => mb_substr($mesaj, 0, 190),
            // Eșecul se arată ca fereastră, nu ca toast care trece: „nu s-a
            // putut" citit din colțul ochiului se confundă cu „s-a făcut".
            'show_alert' => !$ok,
        ]);
    } catch (Throwable $e) {
        // Fapta e deja făcută, scrisă în Jurnal și însemnată sub mesaj. Un
        // toast nedus e o supărare, nu o pagubă.
        error_log('[gp] telegram: toastul de pe telefon nu a plecat: ' . $e->getMessage());
    }
}

/**
 * Un apel către API-ul Telegram.
 *
 * Aruncă la eșec, ca `send_telegram()`. Cine cheamă hotărăște dacă o eroare de
 * aici oprește ceva sau se scrie doar în log.
 *
 * Adresa nu se scrie aici: o dă `telegram_api_url()` (api/lib/notify.php),
 * aceeași pentru amândouă drumurile spre Telegram, ca o probă s-o poată muta
 * pe un ciot local din config.php (runda 2.7.0).
 */
function telegram_api(string $metoda, array $payload): array
{
    $token = setting('notify_telegram_token');
    if ($token === '') {
        throw new RuntimeException('Tokenul de Telegram nu este configurat în Setări.');
    }
    [$cod, $resp] = http_post_json(telegram_api_url($token, $metoda), $payload);
    $j = json_decode($resp, true);
    if ($cod < 200 || $cod >= 300) {
        throw new RuntimeException('Telegram a răspuns ' . $cod . ': '
            . (is_array($j) ? (string) ($j['description'] ?? '') : substr($resp, 0, 200)));
    }
    return is_array($j) ? $j : [];
}

/* ======================================================= legarea botului == */

/**
 * POST /telegram/link — spune-i lui Telegram unde să bată.
 *
 * Se apasă din Setări → Notificări, o singură dată, după ce tokenul și chat-ul
 * sunt salvate. Face trei lucruri deodată, fiindcă despărțite ar lăsa loc unei
 * stări pe jumătate legate pe care n-ar repara-o nimeni:
 *
 *   · naște secretul comun cu Telegram și îl salvează criptat;
 *   · îi spune lui Telegram adresa la care să trimită apăsările;
 *   · ține minte pe DREPTURILE cui lucrează apăsările oamenilor din grup care
 *     nu și-au legat contul — cel care apasă butonul acum. Numele lui nu-l mai
 *     poartă niciuna (de la 2.7.5): fiecare apăsare își scrie omul ei.
 */
function route_telegram_link(): void
{
    $u = require_admin();

    if (setting('notify_telegram_token') === '') {
        fail('Salvează întâi tokenul botului, apoi leagă-l.', 422, 'no_telegram_token');
    }
    if (setting('notify_telegram_chat_id') === '') {
        fail('Salvează întâi Chat ID-ul, altfel botul n-are cui răspunde.', 422, 'no_telegram_chat');
    }

    $url = telegram_webhook_url();
    if (!str_starts_with($url, 'https://')) {
        // Telegram cere https și numai porturile 443, 80, 88 sau 8443. Pe
        // serverul de probă de acasă nu se poate, și e bine să se spună pe
        // față — altfel refuzul vine de la Telegram, într-o engleză pe care
        // n-are de ce s-o descurce patronul.
        fail(
            'Botul se poate lega numai de pe adresa publică, pe https. Aici ești pe „' . $url . '". '
              . 'Publică întâi pachetul pe server, apoi apasă butonul de acolo.',
            422,
            'webhook_needs_https'
        );
    }

    // 24 de octeți, adică 48 de caractere hexazecimale. Telegram acceptă între
    // 1 și 256 de caractere din [A-Za-z0-9_-]; hexa intră cu totul înăuntru și
    // n-are ce să strice în niciun antet.
    $secret = bin2hex(random_bytes(24));

    try {
        telegram_api('setWebhook', [
            'url'          => $url,
            'secret_token' => $secret,
            // Numai apăsările de butoane. Fără linia asta, fiecare mesaj scris
            // botului — și fiecare mesaj din grupul în care ar fi adăugat — ar
            // ajunge pe serverul patiseriei, degeaba.
            'allowed_updates' => ['callback_query'],
            // Ce s-a adunat cât timp botul era nelegat se aruncă. Altfel, la
            // prima legare, ar năvăli apăsări vechi de zile — pe cereri de mult
            // hotărâte — și fiecare ar fi răspuns cu „a fost deja acceptată".
            'drop_pending_updates' => true,
        ]);
    } catch (Throwable $e) {
        telegram_seteaza('notify_telegram_webhook_status', 'eroare', $u);
        fail('Telegram nu a acceptat legătura: ' . $e->getMessage(), 502, 'telegram_link_failed');
    }

    telegram_seteaza('notify_telegram_webhook_secret', encrypt_value($secret), $u);
    telegram_seteaza('notify_telegram_webhook_status', 'legat', $u);
    telegram_seteaza('notify_telegram_actor_id', (string) $u['id'], $u);

    // Secretul NU se scrie în Jurnal, nici măcar pe jumătate: jurnalul se
    // citește de la orice administrator și nu se șterge niciodată, iar cine are
    // secretul poate accepta cereri. Se scrie doar fapta și adresa.
    audit('telegram_link', 'settings', 'notify_telegram_webhook_secret',
        'Bot legat la ' . $url . ' · apăsările celor cu contul nelegat lucrează pe drepturile contului „'
            . $u['name'] . '”, cu numele lor de pe Telegram');

    json_out(['ok' => true, 'url' => $url, 'status' => 'legat', 'actor' => $u['name']]);
}

/** POST /telegram/unlink — botul nu mai ascultă apăsări. */
function route_telegram_unlink(): void
{
    $u = require_admin();

    try {
        telegram_api('deleteWebhook', ['drop_pending_updates' => true]);
    } catch (Throwable $e) {
        // Dezlegarea de pe server se face oricum: dacă Telegram nu răspunde,
        // important e ca apăsările care ar mai sosi să nu mai fie ascultate.
        error_log('[gp] telegram: deleteWebhook a eșuat: ' . $e->getMessage());
    }

    telegram_seteaza('notify_telegram_webhook_secret', '', $u);
    telegram_seteaza('notify_telegram_webhook_status', 'nelegat', $u);
    telegram_seteaza('notify_telegram_actor_id', '', $u);

    audit('telegram_unlink', 'settings', 'notify_telegram_webhook_secret', 'Bot dezlegat');
    json_out(['ok' => true, 'status' => 'nelegat']);
}

/**
 * Adresa la care bate Telegram, calculată din cererea de față.
 *
 * Nu se ia din `site_url`: acela e magazinul (goldenpastry.ro), iar API-ul stă
 * în altă parte (api.goldenpastry.ro, ori un subfolder /api). Singurul lucru
 * care știe sigur unde stă API-ul e chiar cererea prin care patronul apasă
 * butonul: ea a ajuns aici, deci adresa ei duce aici.
 */
function telegram_webhook_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $gazda = (string) ($_SERVER['HTTP_HOST'] ?? '');
    // Același calcul ca dispecerul din index.php, ca adresa să fie cea care
    // chiar nimerește ruta și când API-ul stă într-un subfolder.
    $baza = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');

    return ($https ? 'https' : 'http') . '://' . $gazda . $baza . '/telegram/webhook';
}

/**
 * Scrie o setare, fără să treacă prin formularul de Setări.
 *
 * `settings_save()` e făcută pentru formularul întreg — cu validări, jurnal pe
 * fiecare cheie și reguli pentru secrete. Aici se scriu trei valori pe care nu
 * le-a tastat nimeni, deci trec pe lângă ea. Cache-ul se golește după, altfel
 * restul cererii ar citi în continuare starea de dinainte.
 */
function telegram_seteaza(string $cheie, string $valoare, array $u): void
{
    q('UPDATE settings SET value = ?, updated_by = ? WHERE setting_key = ?',
        [$valoare, (int) $u['id'], $cheie]);
    settings_all(true);
}

/* ======================================== Telegramul meu, nu al botului == */

/**
 * GET /telegram/me — sunt legat? cine a apăsat? mai am vreme?
 *
 * `require_admin()`, nu `require_auth()`: legarea de sine, în doi pași, a rămas
 * o unealtă a administratorilor. Pricina scrisă aici până la 2.7.5 — „numai
 * `GP_ADMIN_ROLES` pot hotărî cereri de sub anunțuri” — nu mai e adevărată:
 * `route_requests_accept/reject` cer dreptul `comenzi.scrie`, pe care îl are și
 * managerul, iar `telegram_actor()` judecă de-acum la fel. Ceilalți oameni ai
 * casei sunt legați de fondator, din lista „Oameni din grup” (Setări), nu de
 * aici.
 */
function route_telegram_me(): void
{
    $u = require_admin();

    if (!telegram_legaturi_suportate()) {
        // Nu e o eroare, e un server fără migrarea 021. Pagina spune „nu se
        // poate încă", nu „a picat ceva".
        json_out(['suportat' => false, 'legat' => false, 'cerere' => null]);
    }

    $legat = (int) (scalar('SELECT telegram_user_id FROM users WHERE id = ?', [(int) $u['id']]) ?? 0);
    $c = telegram_legarea_mea((int) $u['id']);

    json_out([
        'suportat' => true,
        'legat'    => $legat > 0,
        // Id-ul de Telegram NU pleacă spre pagină. Nu-i trebuie: ea are de
        // arătat „ești legat" și numele apăsătorului, iar un număr care
        // identifică un om în afara aplicației n-are ce căuta într-un răspuns
        // pe care îl poate citi orice administrator din consola browserului.
        'cerere'   => $c === null ? null : [
            'apasat'    => $c['pressed_tg_id'] !== null,
            'numeleLui' => $c['pressed_name'],
            'apasatLa'  => $c['pressed_at'],
            'expiraLa'  => $c['expires_at'],
            'folosit'   => $c['used_at'] !== null,
        ],
    ]);
}

/**
 * POST /telegram/me/link — trimite în grup butonul „eu sunt".
 *
 * Mesajul pleacă în grupul personalului fiindcă botul n-are voie să vadă altă
 * conversație (`notify_telegram_chat_id`). Deci scrie pe față AL CUI e butonul:
 * colegii trebuie să poată vedea că nu e al lor înainte să apese, iar cel care
 * apasă greșit află pe loc din toast că n-a legat nimic.
 */
function route_telegram_me_link(): void
{
    $u = require_admin();

    if (!telegram_legaturi_suportate()) {
        fail('Serverul n-are încă tabelele pentru legarea pe om (migrarea 021).', 422, 'not_supported');
    }
    $chat = setting('notify_telegram_chat_id');
    if ($chat === '') {
        fail('Salvează întâi Chat ID-ul în Setări, altfel butonul n-are unde să plece.', 422, 'no_telegram_chat');
    }
    if (setting('notify_telegram_webhook_status') !== 'legat') {
        // Un buton sub un webhook nelegat se desenează frumos și nu face nimic
        // — aceeași pricină pentru care `send_telegram()` nu pune butoane fără
        // `telegram_buttons_live()`.
        fail('Leagă întâi botul (butonul „Leagă botul" de mai sus), apoi contul tău.', 422, 'no_webhook');
    }

    $jeton = telegram_cerere_legare((int) $u['id']);
    $id    = (int) scalar('SELECT id FROM telegram_links WHERE user_id = ?', [(int) $u['id']]);

    try {
        send_telegram(
            $chat,
            'Cine e „' . $u['name'] . '" pe Telegram?' . "\n\n"
                . 'Dacă tu ești, apasă butonul. Apăsarea nu leagă nimic singură: '
                . $u['name'] . ' trebuie să încuviințeze din contul lui.' . "\n"
                . 'Butonul e bun ' . GP_TGLINK_MINUTE . ' de minute.',
            [[['text' => '👤 Eu sunt ' . $u['name'], 'callback_data' => 'leg:' . $id . ':' . $jeton]]]
        );
    } catch (Throwable $e) {
        // Cererea deschisă se stinge: altfel ar rămâne un rând care așteaptă o
        // apăsare pe un buton care n-a plecat nicăieri.
        telegram_dezleaga((int) $u['id']);
        fail('Mesajul n-a plecat: ' . $e->getMessage(), 502, 'telegram_send_failed');
    }

    // Jetonul NU se scrie în Jurnal, ca niciun alt secret al casei.
    audit('telegram_self_link_cerut', 'user', (string) $u['id'],
        'A cerut legarea contului de Telegram; butonul a plecat în grup');

    json_out(['ok' => true, 'minute' => GP_TGLINK_MINUTE]);
}

/**
 * POST /telegram/me/confirm — „da, eu am apăsat".
 *
 * Aici și în `telegram_om_leaga()` (legarea făcută de fondator din „Oameni din
 * grup”, de la 2.7.5) se scrie `users.telegram_user_id` — în niciun al treilea
 * loc. Pe amândouă drumurile id-ul nu vine din
 * cerere, ci de pe rândul scris la apăsare: dacă l-ar trimite pagina, omul
 * și-ar putea trece pe cont numărul altuia, iar apăsările aceluia i-ar purta
 * numele — adică minciuna de reparat, pusă la loc cu mâna lui.
 */
function route_telegram_me_confirm(): void
{
    $u = require_admin();

    $r = telegram_incuviinteaza((int) $u['id']);
    if (!$r['ok']) {
        [$vorba, $cod] = match ($r['pricina']) {
            'neapasat'   => ['Nimeni n-a apăsat încă butonul din grup.', 'not_pressed'],
            'expirat'    => ['Cererea a expirat. Cere una nouă.', 'expired'],
            'deja'       => ['Legătura s-a făcut deja.', 'already'],
            // Numele lipsește numai când Telegramul a fost pus pe alt cont chiar
            // în clipa încuviințării, iar contul nu s-a mai putut afla.
            'luat'       => [($r['nume'] === ''
                                 ? 'Telegramul ăsta a fost legat între timp de alt cont. '
                                 : 'Telegramul ăsta e legat de contul „' . $r['nume'] . '". ')
                             . 'Dezleagă-l de acolo întâi.', 'taken'],
            // Fondatorul a legat între timp alt Telegram de contul ăsta, din
            // „Oameni din grup”: legătura lui rămâne, nu se scrie nimic peste ea.
            'cont_legat' => ['Contul tău a fost legat între timp de alt Telegram, din „Oameni din grup”. '
                             . 'Dacă nu e al tău, dezleagă-l, apoi cere din nou.', 'account_has_telegram'],
            default      => ['Legarea pe om nu e pornită pe serverul ăsta.', 'not_supported'],
        };
        fail($vorba, 409, $cod);
    }

    audit('telegram_self_link', 'user', (string) $u['id'],
        'Cont legat de Telegram („' . $r['nume'] . '"); de-acum apăsările lui îi poartă numele');

    json_out(['ok' => true, 'numeleLui' => $r['nume']]);
}

/** DELETE /telegram/me/link — contul nu mai are Telegram. */
function route_telegram_me_unlink(): void
{
    $u = require_admin();

    telegram_dezleaga((int) $u['id']);
    audit('telegram_self_unlink', 'user', (string) $u['id'],
        'Cont dezlegat de Telegram; apăsările lui poartă de-acum numele de pe Telegram, cu „cont nelegat”');

    json_out(['ok' => true]);
}

/* ================================== „Oameni din grup”: fondatorul leagă == */

/*
 * Trei uși, toate ale FONDATORULUI (`require_acces()`, poarta paginii
 * „Acces”) — nu ale administratorilor, ca legarea de sine de mai sus. De ce, și
 * de ce numărul de Telegram nu pleacă prin niciuna: v. „Oameni din grup” în
 * api/lib/telegram-legaturi.php.
 *
 * POARTA STĂ ÎNAINTEA ORICĂREI ALTE ÎNTREBĂRI. Cine nu e fondator primește 403
 * și pe o bază fără migrare: nici măcar „lista nu e pornită” nu e treaba lui.
 */

/**
 * Baza are ce-i trebuie listei? Dacă nu, refuzul NUMEȘTE fișierul de rulat.
 *
 * 503, nu lista goală: o listă goală ar spune „n-a apăsat nimeni”, iar omul ar
 * aștepta apăsări care nu se scriu nicăieri. (.claude/check-refuzuri-503.mjs
 * cere ca fiecare 503 să-și numească fișierul.)
 */
function telegram_oameni_cere_baza(): void
{
    // Legătura se scrie pe `users.telegram_user_id`, adus de migrarea 021.
    if (!telegram_legaturi_suportate()) {
        fail('Legarea oamenilor de conturi n-are încă unde se scrie: rulează întâi sql/021_raspundere.sql.',
            503, 'telegram_oameni_unavailable');
    }
    if (telegram_apasatori_suportati()) {
        return;
    }
    fail('Lista „Oameni din grup” n-are încă tabelul ei: rulează întâi sql/036_telegram_oameni.sql.',
        503, 'telegram_oameni_unavailable');
}

/** GET /telegram/oameni — cine a apăsat în grup, și de ce cont e legat fiecare. */
function route_telegram_oameni(): void
{
    require_acces();
    telegram_oameni_cere_baza();

    json_out(telegram_oameni_raspuns());
}

/**
 * POST /telegram/oameni/#/leaga — `{ userId }`: omul din rândul `#` e contul ăsta.
 *
 * `#` e rândul din `telegram_oameni`, nu numărul de Telegram: pagina nu-l are
 * (și n-are voie să-l aibă), deci nici n-ar avea ce trimite. Numărul se
 * citește aici, de pe rând — ca la `route_telegram_me_confirm()`, unde vine de
 * pe rândul scris la apăsare, niciodată din cerere.
 */
function route_telegram_oameni_leaga(string $id): void
{
    require_acces();
    telegram_oameni_cere_baza();

    $ales   = body()['userId'] ?? null;
    $contId = is_int($ales) || (is_string($ales) && ctype_digit($ales)) ? (int) $ales : 0;

    $r = telegram_om_leaga(ctype_digit($id) ? (int) $id : 0, $contId);
    if (!$r['ok']) {
        // Fiecare refuz spune ce e de făcut, nu numai că nu s-a putut.
        if ($r['pricina'] === 'om_necunoscut') {
            fail('Omul ăsta nu mai e în listă. Încarcă lista din nou.', 404, 'om_necunoscut');
        }
        if ($r['pricina'] === 'luat') {
            fail($r['altul'] === ''
                ? '„' . $r['om'] . '” a fost legat între timp de alt cont. Încarcă lista din nou.'
                : '„' . $r['om'] . '” e deja legat de contul „' . $r['altul'] . '”. Dezleagă-l de acolo întâi.',
                409, 'telegram_taken');
        }
        if ($r['pricina'] === 'cont_legat') {
            fail('Contul „' . $r['altul'] . '” are deja un Telegram legat. Dezleagă-l întâi, ori alege alt cont.',
                409, 'account_has_telegram');
        }
        fail('Alege un cont pornit al unui om din casă: nu un client și nu contul tabletelor.', 422, 'bad_account');
    }

    // Numele de pe Telegram și al contului, nu numărul: Jurnalul îl citește
    // oricine are panoul lui.
    audit('telegram_om_legat', 'user', (string) $contId,
        'Omul din grup „' . $r['om'] . '” legat de contul „' . $r['cont'] . '”; de-acum apăsările lui îi poartă numele');

    json_out(['ok' => true] + telegram_oameni_raspuns());
}

/**
 * DELETE /telegram/conturi/# — contul `#` nu mai are Telegram.
 *
 * `#` e CONTUL, nu rândul din listă: legătura stă pe cont (un om are cel mult
 * unul), iar butonul „Dezleagă” stă lângă numele contului.
 *
 * Ce s-a scris până acum rămâne cum e: o cerere acceptată pe numele contului
 * nu se preface în „cont nelegat” după dezlegare, cum nici una veche, scrisă
 * „cont nelegat”, nu și-a schimbat numele la legare. Istoria nu se rescrie.
 */
function route_telegram_conturi_dezleaga(string $id): void
{
    require_acces();
    telegram_oameni_cere_baza();

    $contId = ctype_digit($id) ? (int) $id : 0;
    $r      = telegram_cont_dezleaga($contId);
    if (!$r['gasit']) {
        fail('Contul nu există.', 404, 'not_found');
    }
    if ($r['eraLegat']) {
        audit('telegram_om_dezlegat', 'user', (string) $contId,
            'Contul „' . $r['cont'] . '” dezlegat de Telegram' . ($r['om'] === '' ? '' : ' (era „' . $r['om'] . '”)')
                . '; apăsările omului poartă de-acum numele de pe Telegram, cu „cont nelegat”');
    }

    json_out(['ok' => true] + telegram_oameni_raspuns());
}
