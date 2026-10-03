<?php
/** Panoul de Setări. Strict pentru rolul `admin`. */

declare(strict_types=1);

function route_settings_get(): void
{
    require_admin();
    // Anunțurile eșuate pleacă odată cu setările, ca panoul să le poată arăta
    // fără o cerere în plus. Până acum eșecul se scria doar în tabelul
    // `notifications`, în care nu se uita nicio pagină: o comandă care nu a
    // ajuns la laborator rămânea necunoscută până suna clientul să întrebe de
    // ea.
    json_out([
        'settings' => settings_public(),
        'failed'      => failed_notifications(),
        // Câte sunt CU ADEVĂRAT, nu câte încap în listă. Titlul casetei roșii
        // se scria din rândurile aduse — cel mult 20 — deci un weekend cu 47
        // de eșecuri se anunța ca „20 anunțuri nu au plecat”. O cifră mai mică
        // decât adevărul, tocmai într-un mesaj de alarmă.
        'failedTotal' => failed_notifications_count(),
    ]);
}

/**
 * Anunțurile care nu au plecat și pe care nu le-a văzut încă nimeni.
 *
 * Se opresc la 20 fiindcă lista este un semnal, nu un jurnal: când un canal se
 * strică, toate rândurile au aceeași cauză, iar ce contează este să afli de ea,
 * nu să le numeri.
 */
function failed_notifications(): array
{
    // Comanda se aduce odată cu anunțul: rândul scria „comanda #<id>”, adică
    // numărul din baza de date, care nu apare nicăieri altundeva în program.
    // Pe hârtia din laborator, în panou și pe factură scrie referința („#1003”),
    // deci omul avea în față un număr pe care nu-l putea căuta nicăieri.
    $rows = all(
        "SELECT n.id, n.channel, n.recipient, n.subject, n.error, n.order_id, n.created_at,
                o.ref AS order_ref, o.seq AS order_seq, o.day AS order_day
           FROM notifications n
           LEFT JOIN orders o ON o.id = n.order_id
          WHERE n.status = 'failed' AND n.seen_at IS NULL
          ORDER BY n.id DESC LIMIT 20"
    );

    return array_map(static function (array $r): array {
        // Comanda poate lipsi din două motive: anunțul e al unei cereri de pe
        // site (order_id NULL) sau comanda a fost ștearsă între timp. Ziua
        // merge alături de referință fiindcă referința repornește în fiecare
        // zi de la #1001 — singură, n-ar deosebi două comenzi.
        $comanda = $r['order_seq'] !== null;
        return [
            'id'        => (int) $r['id'],
            'channel'   => $r['channel'],
            'recipient' => $r['recipient'],
            'subject'   => $r['subject'],
            'error'     => $r['error'],
            'orderId'   => $r['order_id'] === null ? null : (int) $r['order_id'],
            'orderRef'  => $comanda ? ($r['order_ref'] ?: ('#' . (1000 + (int) $r['order_seq']))) : null,
            'orderDay'  => $comanda ? $r['order_day'] : null,
            'createdAt' => $r['created_at'],
        ];
    }, $rows);
}

/** Câte anunțuri eșuate nevăzute sunt, dincolo de cele 20 aduse în listă. */
function failed_notifications_count(): int
{
    $r = one("SELECT COUNT(*) AS n FROM notifications WHERE status = 'failed' AND seen_at IS NULL");
    return (int) ($r['n'] ?? 0);
}

/**
 * POST /settings/notifications/seen
 *
 * Stinge din panou anunțurile eșuate pe care le-a citit administratorul.
 * Rândurile rămân în tabel, doar primesc `seen_at`: fără butonul acesta, o
 * defecțiune reparată acum o săptămână ar rămâne pe ecran la nesfârșit și nu ai
 * mai deosebi-o de una nouă, adică exact felul în care se pierde din vedere un
 * anunț care chiar contează.
 *
 * Se sting DOAR id-urile trimise, adică exact rândurile pe care pagina le-a
 * avut pe ecran. Înainte, butonul trimitea un corp gol și UPDATE-ul mătura tot
 * tabelul — dar lista arată cel mult 20 de rânduri. Un weekend cu tokenul de
 * WhatsApp expirat lăsa 47 de eșecuri: omul citea 20 și stingea 47, iar
 * celelalte 27 nu se mai puteau afla de nicăieri, fiindcă Setările sunt singurul
 * loc din tot programul care citește tabelul `notifications`. Rămânea cu
 * canalul reparat și fără să știe pe care clienți să-i sune înapoi.
 *
 * Al doilea drum, care nu cerea nici măcar 20 de eșecuri: lista se încarcă o
 * singură dată, la deschiderea paginii. Un anunț picat la 11:30 era stins de un
 * buton apăsat la 12:00 pentru cu totul altceva, fără să fi apărut vreodată pe
 * ecran.
 *
 * O cerere fără id-uri nu mai stinge nimic, dinadins: singurul lucru care ar
 * putea trimite așa ceva e o pagină veche, iar „nu fac nimic” e răspunsul care
 * nu pierde date.
 */
function route_settings_notifications_seen(): void
{
    require_admin();

    $b   = body();
    $ids = [];
    foreach ((array) ($b['ids'] ?? []) as $v) {
        $id = (int) $v;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        if (count($ids) >= 200) {
            break;                           // lista arată 20; 200 e loc de zece loturi
        }
    }

    $n = 0;
    if ($ids !== []) {
        $semne = implode(', ', array_fill(0, count($ids), '?'));
        $n = q(
            "UPDATE notifications SET seen_at = UTC_TIMESTAMP()
              WHERE id IN ($semne) AND status = 'failed' AND seen_at IS NULL",
            $ids
        )->rowCount();
    }

    if ($n > 0) {
        audit('notifications_seen', 'settings', null, $n . ' anunțuri eșuate marcate ca văzute');
    }
    json_out([
        'ok'          => true,
        'marked'      => $n,
        'failed'      => failed_notifications(),
        'failedTotal' => failed_notifications_count(),
    ]);
}

function route_settings_save(): void
{
    $u = require_admin();
    $b = body();

    $incoming = $b['settings'] ?? $b;
    if (!is_array($incoming)) {
        fail('Format invalid.', 422, 'bad_payload');
    }

    // Validări minime, ca o greșeală de tastare să fie prinsă aici, nu abia
    // când se pierde notificarea unei comenzi reale.
    //
    // Expeditorul se verifică la fel de strict ca destinatarul: valoarea lui
    // ajunge nefiltrată în antetul „From:” al mesajului, iar anteturile se
    // despart prin salt de linie. O adresă scrisă greșit pleacă mai departe cu
    // fiecare comandă și dă motiv de respingere la destinație, fără ca nimic să
    // se plângă pe ecran; una care conține CR sau LF ar adăuga și anteturi pe
    // care nu le-a scris nimeni.
    // Adresa publică se verifică la fel ca cele două de notificare: e scrisă
    // în aceeași pagină, de aceeași mână, dar pleacă mai departe pe drumul cel
    // mai vizibil — GET /lists o dă oricui, iar magazinul o pune în linkul
    // „scrie-ne”. O adresă fără domeniu nu se plânge nicăieri: vizitatorul
    // apasă, clientul lui de mail refuză, iar patiseria pierde cererile fără
    // să afle vreodată de ce.
    $emails = [
        'notify_email_to'   => 'Adresa de e-mail pentru notificări nu este validă.',
        'notify_email_from' => 'Adresa expeditor pentru notificări nu este validă.',
        'company_email'     => 'Adresa de e-mail publică nu este validă.',
    ];
    foreach ($emails as $cheie => $mesaj) {
        $email = trim((string) ($incoming[$cheie] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail($mesaj, 422, 'bad_email');
        }
    }
    foreach (['notify_whatsapp_to', 'company_phone'] as $k) {
        $v = trim((string) ($incoming[$k] ?? ''));
        if ($v !== '' && !preg_match('/^\+?[0-9 ().-]{6,24}$/', $v)) {
            fail('Numărul de telefon „' . $v . '” nu este valid.', 422, 'bad_phone');
        }
    }

    // Codul tabletelor e singurul lucru care apără fluxul de comenzi al zilei,
    // iar pragul de încercări e larg tocmai ca echipa să nu rămână pe dinafară.
    // Compensăm cerând un cod care nu se ghicește din câteva încercări.
    if (array_key_exists('panel_code', $incoming)) {
        $code = trim((string) $incoming['panel_code']);
        if ($code !== '' && $code !== '__clear__' && !str_starts_with($code, '•') && strlen($code) < 6) {
            fail('Codul panoului trebuie să aibă cel puțin 6 caractere.', 422, 'weak_panel_code');
        }
    }

    // Pragul minim implicit, verificat acolo unde se SCRIE, nu unde se citește.
    //
    // Caseta trecea nefiltrată în bază, iar articolele noi o luau de acolo cu un
    // simplu `(float)` (api/routes/stock.php). Două pagube, amândouă tăcute:
    // „0,5" scris cu virgulă — cum scrie zecimalele oricine de aici, și cum le
    // primește anume caseta vecină de coordonate (guard_hq_coords() mai jos) —
    // devenea 0, adică „fără prag": articolul nu mai apărea niciodată în „Sub
    // prag minim" și nimeni nu afla că s-a terminat, până când îți trebuia și
    // nu-l aveai. Iar un număr peste cât încape în DECIMAL(10,2) ieșea invers,
    // ca „Eroare internă", abia la primul articol nou — la trei săptămâni după
    // ce fusese tastat, când nimeni nu mai lega una de alta.
    //
    // Verificarea stă aici, la salvare, ca patronul să afle pe loc că „0,5" nu
    // e ce crede el. Forma cu punct se scrie înapoi în cerere, ca baza să țină
    // cifrele scrise de om, nu un float rotunjit — același tipar ca la
    // coordonate.
    if (array_key_exists('stock_min_default', $incoming)) {
        $brut = str_replace(',', '.', trim((string) $incoming['stock_min_default']));
        if ($brut !== '') {
            if (!is_numeric($brut) || (float) $brut < 0 || (float) $brut > 99999999.99) {
                fail('Pragul minim implicit trebuie să fie un număr între 0 și 99999999,99. '
                    . 'Zecimalele se pot scrie și cu virgulă.', 422, 'bad_stock_min_default');
            }
            $incoming['stock_min_default'] = $brut;
        }
    }

    // Procentul de recomandare, mărginit la 0–100.
    //
    // Nu e o formalitate: coloana e DECIMAL(5,2), deci baza primește fără să
    // clipească un „500". Pagina Setări are o casetă de numere, dar un PUT
    // scris cu curl n-are casetă — iar de la clipa aceea FIECARE comandă ar
    // naște o datorie de cinci ori valoarea ei, plătibilă unor oameni adevărați.
    // `referral_clamp_rate()` taie oricum la 100 mai târziu, dar tăierea tăcută
    // ar însemna că patronul a scris una și s-a salvat alta.
    if (array_key_exists('referral_rate_default', $incoming)) {
        $v = trim((string) $incoming['referral_rate_default']);
        if ($v !== '' && (!is_numeric($v) || (float) $v < 0 || (float) $v > 100)) {
            fail('Procentul de recomandare trebuie să fie un număr între 0 și 100.',
                422, 'bad_referral_rate');
        }
    }

    $incoming = guard_catalog($incoming);

    guard_whatsapp_provider($incoming);

    validate_lists($incoming);
    $incoming = guard_event_lists($incoming);
    guard_status_list($incoming);
    $incoming = guard_zone_list($incoming);
    $incoming = guard_slot_list($incoming);
    $incoming = guard_zone_coords($incoming);
    $incoming = guard_hq_coords($incoming);

    $changed = settings_save($incoming, $u);
    // `true` = recitire din baza de date, ca răspunsul să reflecte ce s-a salvat.
    json_out(['ok' => true, 'changed' => $changed, 'settings' => settings_public(true)]);
}

/* ------------------------------------------------------------- validări -- */

/**
 * Cardul „Catalog" (migrarea 028): vederile cu care se deschide magazinul și
 * pragurile cu care se precompletează un produs nou.
 *
 * Vederea e unul din trei cuvinte; orice altceva s-ar citi la deschidere ca
 * vederea de pornire (`catalog_implicite()`), deci patronul ar alege una și ar
 * vedea alta, fără să afle de ce. Se refuză pe loc.
 *
 * Pragurile sunt bucăți întregi, de la 2 la 10000 — aceleași margini ca pe
 * produs (`product_trepte_din()`), ca un prag precompletat din Setări să nu fie
 * refuzat abia la salvarea produsului. Golul e îngăduit: înseamnă „nu
 * precompleta". C cere un B sub el. Perechea se judecă ÎNTREAGĂ — cea trimisă
 * peste cea din bază —, ca o salvare numai a lui B să nu treacă pe sub un C pe
 * care l-ar lăsa dedesubt.
 */
function guard_catalog(array $incoming): array
{
    foreach (['catalog_vedere_telefon' => 'pe telefon', 'catalog_vedere_calculator' => 'pe calculator'] as $k => $unde) {
        if (!array_key_exists($k, $incoming)) {
            continue;
        }
        $v = is_scalar($incoming[$k]) ? trim((string) $incoming[$k]) : '';
        if (!in_array($v, catalog_vederi(), true)) {
            fail('Vederea catalogului ' . $unde . ' trebuie să fie una din: '
                . implode(', ', catalog_vederi()) . '.', 422, 'bad_catalog_view');
        }
        $incoming[$k] = $v;
    }

    $chei = ['pret_prag_b_implicit' => 'B', 'pret_prag_c_implicit' => 'C'];
    if (!array_key_exists('pret_prag_b_implicit', $incoming)
        && !array_key_exists('pret_prag_c_implicit', $incoming)) {
        return $incoming;
    }
    $praguri = [];
    foreach ($chei as $k => $lit) {
        $v = array_key_exists($k, $incoming)
            ? (is_scalar($incoming[$k]) ? trim((string) $incoming[$k]) : 'x')
            : trim(setting($k));
        if ($v !== '' && (!preg_match('/^\d+$/', $v) || (int) $v < 2 || (int) $v > 10000)) {
            fail('Pragul implicit pentru prețul ' . $lit . ' trebuie să fie un număr întreg de '
                . 'bucăți, între 2 și 10000 — sau gol, dacă nu vrei precompletare.', 422, 'bad_threshold');
        }
        $praguri[$lit] = $v === '' ? null : (int) $v;
        if (array_key_exists($k, $incoming)) {
            $incoming[$k] = $v === '' ? '' : (string) (int) $v;
        }
    }
    if ($praguri['C'] !== null && ($praguri['B'] === null || $praguri['C'] <= $praguri['B'])) {
        fail('Pragul implicit pentru prețul C trebuie să fie mai mare decât cel pentru prețul B.',
            422, 'threshold_order');
    }
    return $incoming;
}

/**
 * Schimbarea furnizorului de WhatsApp cere datele noului furnizor.
 *
 * Cele două căi împart aceleași două cutii, dar pun în ele lucruri deosebite:
 *
 *   · Meta    — `notify_whatsapp_token` e un Bearer token,
 *               `notify_whatsapp_phone_id` e Phone Number ID;
 *   · Twilio  — tokenul e Auth Token,
 *               `notify_whatsapp_phone_id` ține Account SID (api/lib/notify.php ·
 *               `send_whatsapp_twilio()`, unde scrie asta pe față).
 *
 * Un Bearer token de la Meta nu deschide nimic la Twilio, și invers. Până
 * acum, schimbarea furnizorului singură trecea cuminte: valorile celuilalt
 * rămâneau în bază, iar panoul le arăta ca „Token salvat" — fiindcă tokenul
 * CHIAR e salvat, doar că e al altcuiva. Prima comandă după schimbare pleca
 * spre furnizorul nou cu cheia celui vechi, primea 401, iar anunțul cădea în
 * caseta de anunțuri eșuate, cu o eroare de la un serviciu străin.
 *
 * De aceea schimbarea se refuză când nu vine cu datele ei. Nu e o pază de
 * securitate, ci un refuz în locul unei tăceri: ce urma să se întâmple oricum
 * (mesaje neduse) se spune înainte, nu după.
 *
 * Mascarea nu trece drept valoare: „•" în față înseamnă „las-o cum e"
 * (api/lib/settings.php · `settings_save()`), adică exact tokenul vechi.
 */
function guard_whatsapp_provider(array $incoming): void
{
    if (!array_key_exists('notify_whatsapp_provider', $incoming)) {
        return;
    }
    $nou  = trim((string) $incoming['notify_whatsapp_provider']);
    $vechi = setting('notify_whatsapp_provider', 'meta');
    if ($nou === '' || $nou === $vechi) {
        return;
    }

    $scris = static function (array $incoming, string $cheie): bool {
        if (!array_key_exists($cheie, $incoming)) {
            return false;
        }
        $v = trim((string) $incoming[$cheie]);
        return $v !== '' && $v !== '__clear__' && !str_starts_with($v, '•');
    };

    $nume = ['meta' => 'Meta (WhatsApp Cloud API)', 'twilio' => 'Twilio'];
    $etNou = $nume[$nou] ?? $nou;
    if (!$scris($incoming, 'notify_whatsapp_token') || !$scris($incoming, 'notify_whatsapp_phone_id')) {
        fail(
            'Ca să treci pe ' . $etNou . ', scrie în aceeași salvare și tokenul, și '
            . ($nou === 'twilio' ? 'Account SID-ul' : 'Phone Number ID-ul')
            . ' de acolo. Cele salvate acum sunt ale furnizorului de dinainte și nu deschid nimic la cel nou.',
            422,
            'provider_credentials_missing'
        );
    }
}

/**
 * Listele „ce anunț pe ce canal".
 *
 * NU intră în `list_keys()`, deși sunt tot liste JSON în `settings`, și asta
 * din exact un motiv: acolo lista goală e refuzată („nu poate rămâne goală"),
 * fiindcă o patiserie fără nicio etapă de producție e o greșeală de tastat.
 * Aici lista goală e o HOTĂRÂRE — „nu vreau nimic pe WhatsApp" — și trebuie să
 * treacă. Aceeași deosebire o face și `notify_channel_events()` la citire,
 * care de-aceea nu folosește `setting_list()`.
 *
 * Ce se verifică în schimb: fiecare cheie să existe în catalog. O bifă pentru
 * un eveniment fără cârlig scris în program e o promisiune pe care panoul n-o
 * poate ține — omul o bifează, se salvează cuminte, și telefonul tace la
 * nesfârșit fără să afle nimeni de ce. Mai bine un refuz limpede la salvare.
 *
 * Lista se scrie înapoi în cerere curățată și în ordinea catalogului, ca două
 * salvări cu aceleași bife să dea același șir în baza de date — altfel jurnalul
 * ar raporta o schimbare la fiecare apăsare de „Salvează".
 */
function guard_event_lists(array $incoming): array
{
    $stiute = array_keys(notify_events());

    foreach (notify_channels() as $canal) {
        $cheie = 'notify_' . $canal . '_events';
        if (!array_key_exists($cheie, $incoming)) {
            continue;
        }
        $raw  = $incoming[$cheie];
        $list = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($list)) {
            fail('Lista de anunțuri pentru „' . $canal . '” nu este validă.', 422, 'bad_event_list');
        }

        $curat = [];
        foreach ($list as $v) {
            if (is_array($v) || is_object($v)) {
                fail('Lista de anunțuri pentru „' . $canal . '” conține o valoare care nu este text.',
                    422, 'bad_event_list');
            }
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            if (!in_array($v, $stiute, true)) {
                fail(
                    'Anunțul „' . $v . '” nu există. Cele care există: ' . implode(', ', $stiute) . '.',
                    422,
                    'unknown_event'
                );
            }
            if (!in_array($v, $curat, true)) {
                $curat[] = $v;
            }
        }

        // Ordinea catalogului, nu ordinea în care au fost bifate casetele.
        $incoming[$cheie] = array_values(array_filter($stiute, static fn($e) => in_array($e, $curat, true)));
    }

    return $incoming;
}

/** Cheile care țin o listă JSON editabilă din panou. */
function list_keys(): array
{
    return [
        'order_statuses', 'delivery_slots', 'delivery_zones',
        'fulfillment_types', 'fulfillment_pickup', 'stock_categories', 'stock_units',
    ];
}

/**
 * Listele trebuie să rămână JSON valid, cu cel puțin o valoare.
 *
 * Fără verificarea asta, o listă salvată stricat ar fi înlocuită în tăcere cu
 * valorile implicite din cod (setting_list() are un fallback), iar tu ai vedea
 * altceva decât ai scris, fără niciun mesaj de eroare.
 */
function validate_lists(array $incoming): void
{
    // Cât încape în coloanele în care ajung valorile: `orders.slot` VARCHAR(16),
    // `zone` 64, `status` 48, `fulfillment` 32, `stock_items.category` 64,
    // `unit` 16. Fără pragul ăsta, un interval scris „06:30 – 08:00 dimineața"
    // se salva fără nicio obiecție — iar fiecare vizitator care îl alegea pe
    // site primea „Eroare internă." de la POST /requests (1406, data too long).
    // Refuzul trebuie să fie aici, la cel care poate corecta valoarea.
    $maxim = [
        'delivery_slots'     => 16,
        'delivery_zones'     => 64,
        'order_statuses'     => 48,
        'fulfillment_types'  => 32,
        'fulfillment_pickup' => 32,
        'stock_categories'   => 64,
        'stock_units'        => 16,
    ];
    foreach (list_keys() as $key) {
        if (!array_key_exists($key, $incoming)) {
            continue;
        }
        $raw  = $incoming[$key];
        $list = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (!is_array($list)) {
            fail('Lista „' . $key . '” nu este validă.', 422, 'bad_list');
        }
        $clean = [];
        foreach ($list as $v) {
            // Un element care nu e text simplu — un tablou imbricat, un obiect —
            // devine în PHP literal „Array” la conversia de mai jos, cu un
            // avertisment care ajunge doar în log. Lista trecea de validare
            // (nu era goală), se salva așa cum venise, iar clientul vedea în
            // formular un interval scris „Array”. Îl oprim aici, cu numele
            // cheii în mesaj, ca cel care a trimis lista să știe unde a greșit.
            if (is_array($v) || is_object($v)) {
                fail('Lista „' . $key . '” conține o valoare care nu este text.', 422, 'bad_list');
            }
            $v = trim((string) $v);
            if (isset($maxim[$key]) && mb_strlen($v) > $maxim[$key]) {
                fail(
                    'Lista „' . $key . '”: valoarea „' . $v . '” are ' . mb_strlen($v)
                      . ' caractere, maximum ' . $maxim[$key] . '.',
                    422,
                    'list_value_too_long'
                );
            }
            if ($v !== '' && !in_array($v, $clean, true)) {
                $clean[] = $v;
            }
        }
        if (!$clean) {
            fail('Lista „' . $key . '” nu poate rămâne goală.', 422, 'empty_list');
        }
    }
}

/**
 * Protejează etapele aflate în uz.
 *
 * Dacă ștergi din listă o etapă în care se află comenzi, acele comenzi rămân
 * cu un status pe care nicio pagină nu îl mai recunoaște: dispar din panoul
 * laboratorului, din filtre și de pe ruta șoferului. Se pierd din vedere fără
 * niciun semn, de aceea ștergerea se oprește aici cu un mesaj explicit.
 *
 * Tot aici verificăm că etapa „gata” și cea „anulată” fac parte din listă —
 * altfel aplicația n-ar mai ști când o comandă este încheiată.
 */
function guard_status_list(array $incoming): void
{
    if (!array_key_exists('order_statuses', $incoming)) {
        $next = order_statuses();
    } else {
        $raw  = $incoming['order_statuses'];
        $list = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $next = [];
        foreach ((array) $list as $v) {
            $v = trim((string) $v);
            if ($v !== '' && !in_array($v, $next, true)) {
                $next[] = $v;
            }
        }

        // Ne interesează DOAR etapele pe care le scoate chiar această salvare.
        //
        // Comparate cu toate etapele din comenzi, o singură valoare rămasă în
        // baza de date din altă parte — un import, o corecție manuală — ar fi
        // blocat orice salvare din Setări, la nesfârșit și fără scăpare.
        $current = order_statuses();
        $inUse = array_column(
            all('SELECT status, COUNT(*) AS n FROM orders GROUP BY status'),
            'n',
            'status'
        );
        foreach ($inUse as $status => $count) {
            $status = (string) $status;
            if ($status !== '' && in_array($status, $current, true) && !in_array($status, $next, true)) {
                fail(
                    'Etapa „' . $status . '” nu poate fi ștearsă: ' . (int) $count .
                    ((int) $count === 1 ? ' comandă se află' : ' comenzi se află') .
                    ' în ea. Mută-le mai întâi în altă etapă.',
                    409,
                    'status_in_use'
                );
            }
        }
    }

    // SE VERIFICĂ VALOAREA DE DUPĂ SALVARE, NU NUMAI CEA TRIMISĂ ACUM.
    //
    // Aici era un `continue` pe cheia lipsă din cerere, adică „dacă nu se
    // schimbă rolul, nu-l verifica". Numai că rolul se poate rupe și fără să-l
    // atingă cineva: e de ajuns ca ETAPA spre care arată să iasă din listă. Iar
    // pagina de Setări trimite exact atât — numai lista, fiindcă numai ea s-a
    // schimbat (Setari.dc.html · `changes()`).
    //
    // Ce urma nu se mai vedea nicăieri: `status_cancelled()` întoarce ''
    // pentru totdeauna (ea, spre deosebire de `status_done()`, n-are cădere de
    // siguranță), deci comenzile anulate se întorc pe ruta șoferului, își țin
    // rezervările din depozit și își păstrează comisioanele „activ".
    //
    // Ștergerea rămâne cu totul posibilă — dar în aceeași salvare cu alegerea
    // altei etape. Adică fix ce face omul când redenumește o etapă.
    foreach (['order_status_done' => 'încheiată', 'order_status_cancelled' => 'anulată'] as $key => $label) {
        $cerut = array_key_exists($key, $incoming);
        $v     = trim((string) ($cerut ? $incoming[$key] : setting($key)));
        if ($v === '' || in_array($v, $next, true)) {
            continue;
        }
        fail(
            $cerut
                ? 'Etapa „' . $label . '” trebuie să fie una dintre etapele din listă. '
                  . '„' . $v . '” nu e printre ele.'
                : 'Etapa care marchează comanda „' . $label . '” e acum „' . $v . '”, '
                  . 'iar ea nu mai e în listă. Alege alta la „Etapele cu rol special” '
                  . 'și salvează amândouă deodată.',
            422,
            'bad_special_status'
        );
    }

    // ROLUL MUTAT SE JUDECĂ ȘI DUPĂ LOCUL DIN LISTĂ, NU NUMAI DUPĂ „E ÎN LISTĂ”.
    //
    // De la 2.6.0 lista are „Gata de livrare” chiar înaintea etapei finale, iar
    // în Setări scrie „Comanda este încheiată (livrată) când ajunge la”. Cine
    // alege aici „Gata de livrare” trecea de paza de mai sus (etapa e în listă)
    // și strica trei lucruri deodată, fără niciun semn: clientul citea
    // „Livrată” cu marfa încă în laborator, plafonul laboratorului cobora cu o
    // etapă (`status_lab_ceiling()` e etapa dinaintea celei finale), iar
    // comenzile vechi din „Predată” se întorceau pe panou drept cartoane de
    // lucru.
    //
    // Regula e STRÂNSĂ dinadins: se judecă numai salvarea care MUTĂ un rol.
    //   · „încheiată” și „anulată” nu pot ajunge pe aceeași etapă;
    //   · etapa nouă de „încheiată” trebuie să fie ultima din drum — lista de
    //     după salvare, scoasă cea de anulare, care poate sta oriunde.
    //
    // O salvare care nu mută niciun rol nu e judecată aici, oricum ar arăta
    // lista. Regula largă („încheiată e mereu ultima”, la orice salvare a
    // listei) ar refuza adăugarea unei etape la coadă — adică tocmai drumul pe
    // care omul îl are ca să-și facă o etapă nouă de anulare — și ar încuia o
    // casă cu lista deja strâmbă: n-ar mai putea salva nici telefonul.
    $vineGata = array_key_exists('order_status_done', $incoming);
    $vineAnul = array_key_exists('order_status_cancelled', $incoming);
    $acumGata = trim(setting('order_status_done'));
    $acumAnul = trim(setting('order_status_cancelled'));
    $gata     = $vineGata ? trim((string) $incoming['order_status_done']) : $acumGata;
    $anul     = $vineAnul ? trim((string) $incoming['order_status_cancelled']) : $acumAnul;
    if ($gata === '' || ($gata === $acumGata && $anul === $acumAnul)) {
        return;
    }

    if ($gata === $anul) {
        fail(
            'Aceeași etapă, „' . $gata . '”, nu poate și încheia, și anula comanda. '
              . 'Alege două etape diferite la „Etapele cu rol special”.',
            422,
            'bad_special_status'
        );
    }

    if ($gata !== $acumGata) {
        $drum = array_values(array_filter($next, static fn ($s) => $s !== $anul));
        $loc  = array_search($gata, $drum, true);
        if ($loc !== false && $loc < count($drum) - 1) {
            fail(
                '„' . $gata . '” nu poate fi etapa care încheie comanda: după ea mai urmează „'
                  . implode('”, „', array_slice($drum, $loc + 1)) . '”. '
                  . 'Comanda se încheie la ultima etapă din listă (cea de anulare nu se numără). '
                  . 'Dacă marfa ajunge la client chiar la „' . $gata . '”, mut-o întâi la capătul listei '
                  . '„Etape în laborator”, apoi alege-o la „Etapele cu rol special” și salvează.',
                422,
                'bad_special_status'
            );
        }
    }
}

/**
 * Numără pe valoare comenzile care urmează și cererile încă nerezolvate.
 *
 * Ziua se leagă ca parametru, calculată în PHP, nu se cere bazei prin
 * `UTC_DATE()`. Ora României e cu 2-3 ore înaintea celei din UTC, deci între
 * miezul nopții și 03:00 `UTC_DATE()` întoarce ziua de IERI: paza număra
 * comenzile de ieri, deja livrate, și refuza o ștergere pe care nimic n-o mai
 * ținea — cu o cifră pe care omul n-o putea verifica din nicio pagină, deci
 * putea ajunge să umble la comenzi vechi ca să scape de mesaj. Restul codului
 * care compară cu ziua omului folosește `today_local()`; aici rămăsese în urmă.
 *
 * Cererile n-au filtru pe zi: una rămasă pe „nou” e nerezolvată oricare ar fi
 * ziua cerută, iar acceptarea verifică oricum ziua separat (`valid_order_day`).
 */
function list_usage(string $coloana): array
{
    $n = [];
    foreach (all(
        "SELECT `$coloana` AS v, COUNT(*) AS n FROM orders
          WHERE `$coloana` IS NOT NULL AND `$coloana` <> '' AND day >= ?
          GROUP BY `$coloana`",
        [today_local()]
    ) as $r) {
        $n[(string) $r['v']] = ['comenzi' => (int) $r['n'], 'cereri' => 0];
    }
    foreach (all(
        "SELECT `$coloana` AS v, COUNT(*) AS n FROM order_requests
          WHERE status = 'nou' AND `$coloana` IS NOT NULL AND `$coloana` <> ''
          GROUP BY `$coloana`"
    ) as $r) {
        $v = (string) $r['v'];
        if (!isset($n[$v])) { $n[$v] = ['comenzi' => 0, 'cereri' => 0]; }
        $n[$v]['cereri'] = (int) $r['n'];
    }
    return $n;
}

/** „2 comenzi care urmează și o cerere în așteptare”, fără părțile care sunt zero. */
function list_usage_text(int $comenzi, int $cereri): string
{
    $parti = [];
    if ($comenzi > 0) {
        $parti[] = $comenzi === 1 ? 'o comandă care urmează' : $comenzi . ' comenzi care urmează';
    }
    if ($cereri > 0) {
        $parti[] = $cereri === 1 ? 'o cerere în așteptare' : $cereri . ' cereri în așteptare';
    }
    return implode(' și ', $parti);
}

/** Lista curățată de goluri și de dubluri, în ordinea în care a fost scrisă. */
function list_clean($raw): array
{
    $list = is_array($raw) ? $raw : json_decode((string) $raw, true);
    $next = [];
    foreach ((array) $list as $v) {
        $v = trim((string) $v);
        if ($v !== '' && !in_array($v, $next, true)) {
            $next[] = $v;
        }
    }
    return $next;
}

/**
 * Protejează zonele folosite de comenzi care încă urmează să fie livrate — și
 * de cererile care încă așteaptă să fie acceptate.
 *
 * O zonă ștearsă din listă nu face comenzile să dispară, dar le lasă cu o
 * zonă pe care harta nu o mai cunoaște: oprirea primește coordonatele
 * depozitului, iar ruta arată zero kilometri până la ea. Ar părea corectă și
 * n-ar fi, ceea ce e mai rău decât o eroare pe față.
 *
 * Cererile lipseau din numărătoare, deși ele sunt tocmai partea care nu s-a
 * așezat încă: zona lor putea fi ștearsă, iar la acceptare cererea cădea cu
 * „Zonă necunoscută” — o cerere venită de la un client adevărat, cu produsele
 * și telefonul lui, pe care operatorul n-o mai putea trece în comandă. Ocolirea
 * la îndemână, schimbă-i zona, mută oprirea în altă parte a orașului fără ca
 * nimeni să bage de seamă.
 *
 * Comenzile din trecut nu contează — ruta lor s-a făcut demult.
 *
 * Lista se și curăță aici: nimic nu se opunea până acum ca aceeași zonă să fie
 * scrisă de două ori. Pe ecran ieșeau două rânduri identice, imposibil de
 * deosebit unul de altul — ștergi unul și nu știi care a plecat.
 */
function guard_zone_list(array $incoming): array
{
    if (!array_key_exists('delivery_zones', $incoming)) {
        return $incoming;
    }
    $next = list_clean($incoming['delivery_zones']);

    // Ca și la etape: doar zonele pe care le scoate chiar această salvare.
    $current = delivery_zones();
    foreach (list_usage('zone') as $zone => $cate) {
        if (!in_array($zone, $current, true) || in_array($zone, $next, true)) {
            continue;
        }
        $total = $cate['comenzi'] + $cate['cereri'];
        fail(
            'Zona „' . $zone . '” nu poate fi ștearsă: ' . list_usage_text($cate['comenzi'], $cate['cereri'])
              . ($total === 1 ? ' este în ea. Mut-o' : ' sunt în ea. Mută-le')
              . ' mai întâi în altă zonă.',
            409,
            'zone_in_use'
        );
    }

    $incoming['delivery_zones'] = json_encode($next, JSON_UNESCAPED_UNICODE);
    return $incoming;
}

/**
 * Aceeași pază pentru intervalele de livrare, care n-aveau niciuna.
 *
 * Zonele erau apărate, etapele erau apărate, orele nu. Un interval scos din
 * listă lăsa comenzile și cererile care îl foloseau cu o oră pe care niciun
 * formular n-o mai recunoaște: în panou caseta sare pe primul interval, iar
 * cererea nu se mai poate accepta deloc.
 */
function guard_slot_list(array $incoming): array
{
    if (!array_key_exists('delivery_slots', $incoming)) {
        return $incoming;
    }
    $next = list_clean($incoming['delivery_slots']);

    $current = delivery_slots();
    foreach (list_usage('slot') as $slot => $cate) {
        if (!in_array($slot, $current, true) || in_array($slot, $next, true)) {
            continue;
        }
        $total = $cate['comenzi'] + $cate['cereri'];
        fail(
            'Intervalul „' . $slot . '” nu poate fi scos: ' . list_usage_text($cate['comenzi'], $cate['cereri'])
              . ($total === 1 ? ' este pe el. Mut-o' : ' sunt pe el. Mută-le')
              . ' mai întâi pe alt interval.',
            409,
            'slot_in_use'
        );
    }

    $incoming['delivery_slots'] = json_encode($next, JSON_UNESCAPED_UNICODE);
    return $incoming;
}

/**
 * Verifică punctele de pe hartă ale zonelor, la salvare.
 *
 * Latitudinea și longitudinea se scriu în Setări în casete de text obișnuite:
 * golite, scrise cu litere sau cu virgulă zecimală, valoarea ajungea în baza de
 * date ca atare, iar serverul o citea ca 0 — adică un punct din Golful Guineei.
 * Pe rută ieșeau mii de kilometri până la zona aceea, iar „Optimizează ruta”
 * aranja opririle față de punctul acela, fără niciun mesaj de eroare. Oprim
 * salvarea aici, unde omul are caseta în față și o poate corecta.
 *
 * Ca la etape: ne uităm DOAR la punctele pe care le schimbă chiar această
 * salvare. O zonă rămasă din trecut cu coordonate nefolosibile — sau una
 * ștearsă din listă, al cărei punct nu mai apare în pagină — ar bloca altfel
 * orice modificare din Setări, la nesfârșit și fără scăpare.
 *
 * Punctele care trec verificarea se și curăță aici: tastatura românească dă
 * virgulă zecimală, iar „44,4795” se salva ca atare. Citirea îl înțelege, dar
 * în baza de date rămâneau două feluri de valori pentru același lucru.
 * Întoarcem setările cu punctele scrise ca numere.
 */
function guard_zone_coords(array $incoming): array
{
    if (!array_key_exists('delivery_zone_coords', $incoming)) {
        return $incoming;
    }
    $raw = $incoming['delivery_zone_coords'];
    $map = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($map)) {
        fail('Coordonatele zonelor de livrare nu sunt valide.', 422, 'bad_zone_coords');
    }

    $stored  = json_decode(setting('delivery_zone_coords'), true);
    $current = is_array($stored) ? $stored : [];

    $curatat = [];
    foreach ($map as $zone => $point) {
        $zone = (string) $zone;
        if (array_key_exists($zone, $current) && $current[$zone] == $point) {
            $curatat[$zone] = $point;        // punct neatins de această salvare
            continue;
        }
        $lat = is_array($point) ? zone_coord($point['lat'] ?? null) : null;
        $lng = is_array($point) ? zone_coord($point['lng'] ?? null) : null;
        if ($lat === null || $lng === null) {
            fail(
                'Zona „' . $zone . '” are nevoie de amândouă coordonatele, ca numere: ' .
                'o casetă goală sau un 0 ar trimite oprirea în ocean. Le iei din ' .
                'Google Maps, cu punct zecimal: 44.4795 și 26.0563.',
                422,
                'bad_zone_point'
            );
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            fail(
                'Coordonatele zonei „' . $zone . '” sunt în afara hărții: latitudinea ' .
                'este între -90 și 90, longitudinea între -180 și 180.',
                422,
                'bad_zone_point'
            );
        }
        $curatat[$zone] = ['lat' => $lat, 'lng' => $lng];
    }

    // Plasa de dedesubt: harta primită se scrie ÎNTREAGĂ peste cea din bază,
    // deci o cheie lipsă din ea nu însemna „las-o cum e”, ci „șterge punctul”.
    // Pagina chiar trimitea așa ceva: caseta cu numele zonei comita la fiecare
    // literă, iar un nume care trece prin numele altei zone — „Ilfov” →
    // „Sector 1 si Ilfov” trece, la a opta literă, exact prin „Sector 1” — îi
    // lua acesteia punctul și îl arunca la litera următoare. La final lista
    // avea toate zonele, dar una era fără punct.
    //
    // Ce urma nu se vedea nicăieri: gp.zonePoint() cade pe depozit când zona
    // n-are punct, deci opririle din zona aceea se desenau una peste alta, în
    // curtea depozitului, iar „Optimizează ruta” le punea primele — piciorul
    // până acolo arăta 0 km, nu o distanță absurdă care ar fi sărit în ochi.
    //
    // Refuzul e îngust dinadins, ca să nu calce peste ce spune comentariul de
    // mai sus: se plânge DOAR de o zonă care rămâne în listă și care are azi
    // un punct bun. O zonă ștearsă își duce punctul cu ea, iar una rămasă din
    // trecut fără punct nu blochează nimic — n-are ce pierde.
    $listaRaw = array_key_exists('delivery_zones', $incoming) ? $incoming['delivery_zones'] : null;
    if ($listaRaw === null) {
        $lista = delivery_zones();
    } else {
        $parsata = is_array($listaRaw) ? $listaRaw : json_decode((string) $listaRaw, true);
        $lista   = [];
        foreach ((array) $parsata as $v) {
            $lista[] = trim((string) $v);
        }
    }
    foreach ($current as $zone => $point) {
        $zone = (string) $zone;
        if (array_key_exists($zone, $curatat) || !in_array($zone, $lista, true)) {
            continue;
        }
        $latV = is_array($point) ? zone_coord($point['lat'] ?? null) : null;
        $lngV = is_array($point) ? zone_coord($point['lng'] ?? null) : null;
        if ($latV === null || $lngV === null) {
            continue;                        // n-a fost punct bun, n-are ce se pierde
        }
        fail(
            'Zona „' . $zone . '” ar rămâne fără punct pe hartă, deși rămâne în listă. ' .
            'Un punct pierdut nu se vede nicăieri: opririle din zona aceea s-ar desena ' .
            'în curtea depozitului, iar „Optimizează ruta” le-ar pune primele, ca și cum ' .
            'ar fi la zero kilometri. Dacă vrei să scoți zona, scoate-o din listă; dacă ' .
            'ai redenumit-o, trimite punctul pe numele nou.',
            422,
            'zone_point_lost'
        );
    }

    $incoming['delivery_zone_coords'] = json_encode($curatat, JSON_UNESCAPED_UNICODE);
    return $incoming;
}

/**
 * Verifică punctul din care pleacă mașina, la salvare.
 *
 * Coordonatele zonelor erau apărate de guard_zone_coords(), dar cele ale
 * depozitului — două casete de deasupra, în aceeași pagină — treceau ca text
 * brut. Tastatura românească dă virgulă zecimală, iar „44,39235” citit ca
 * număr devine 44: depozitul sare cu vreo 43 de kilometri spre sud. Un text
 * fără cifre („n/a”) devine 0, adică punctul din Golful Guineei împotriva
 * căruia au fost blindate zonele. Nimic nu se plângea: salvarea răspundea
 * „Setările au fost salvate.”, iar greșeala se vedea abia în kilometrii de pe
 * ruta șoferului și în ordinea în care „Optimizează ruta” aranja opririle.
 *
 * Virgula se acceptă și se aduce la punct, ca la zone: omul a scris o valoare
 * bună, doar cu semnul pe care i-l dă tastatura, iar a-l refuza ar fi o
 * pedeapsă pentru o greșeală care nu e a lui. În baza de date rămâne o
 * singură formă a aceluiași număr.
 *
 * Caseta GOLITĂ trece mai departe dinadins: setting() cade atunci pe
 * coordonatele depozitului scrise în cod, deci nu ajunge nimeni în ocean.
 * Refuzul ar bloca salvarea unei pagini în care omul a schimbat cu totul
 * altceva.
 */
function guard_hq_coords(array $incoming): array
{
    $campuri = [
        'hq_lat' => ['latitudine', 90.0, '44.39235'],
        'hq_lng' => ['longitudine', 180.0, '26.12385'],
    ];

    foreach ($campuri as $cheie => [$nume, $max, $exemplu]) {
        if (!array_key_exists($cheie, $incoming)) {
            continue;
        }
        $brut = is_scalar($incoming[$cheie]) ? trim((string) $incoming[$cheie]) : null;
        if ($brut === '') {
            continue;                        // golit → rămâne implicitul din cod
        }

        $v = $brut === null ? null : zone_coord($brut);
        if ($v === null) {
            fail(
                'Punctul de plecare al livrărilor are nevoie de o ' . $nume . ' scrisă ca ' .
                'număr: o casetă cu litere sau un 0 ar muta depozitul în ocean, iar toți ' .
                'kilometrii rutei ar pleca de acolo. O iei din Google Maps — merge și cu ' .
                'virgulă, și cu punct: ' . $exemplu . '.',
                422,
                'bad_hq_point'
            );
        }
        if ($v < -$max || $v > $max) {
            fail(
                'Coordonatele depozitului sunt în afara hărții: latitudinea este între ' .
                '-90 și 90, longitudinea între -180 și 180.',
                422,
                'bad_hq_point'
            );
        }

        // Păstrăm cifrele scrise de om, schimbăm doar semnul zecimal: un
        // drum prin float ar rotunji fără să ceară nimeni asta.
        $incoming[$cheie] = str_replace(',', '.', $brut);
    }

    return $incoming;
}

/**
 * POST /settings/test/{canal}
 *
 * Trimite un mesaj de probă, ca să verifici configurarea imediat, fără să
 * aștepți o comandă reală. Eroarea furnizorului este returnată ca atare —
 * mesajele Meta („template not found”, „token expired”) spun exact ce lipsește.
 */
function route_settings_test(string $channel): void
{
    require_admin();

    if (!in_array($channel, ['email', 'whatsapp', 'telegram'], true)) {
        fail('Canal necunoscut: ' . $channel, 404, 'unknown_channel');
    }

    $text = "Mesaj de test de la Golden Pastry.\n"
        . "Dacă ai primit acest mesaj, notificările pe „" . $channel . "” funcționează.\n"
        . 'Trimis la ' . gmdate('Y-m-d H:i') . ' UTC.';

    $order = [
        // Ziua în ora patiseriei, ca mesajul de test să arate ce va arăta și
        // anunțul unei comenzi adevărate: cu `gmdate()`, un test trimis noaptea
        // ajungea cu ziua de ieri și părea că anunțurile pleacă greșit.
        'seq' => 0, 'customerName' => 'Test', 'day' => today_local(),
        'phone' => '', 'address' => '', 'notes' => '', 'total' => 0, 'items' => [],
    ];

    $ok = send_channel($channel, $text, null, 'Test notificare Golden Pastry', $order);

    if (!$ok) {
        $last = one(
            'SELECT error FROM notifications WHERE channel = ? ORDER BY id DESC LIMIT 1',
            [$channel]
        );
        // În forma pe care o citește clientul: `error` este un obiect
        // {code, message}, ca la orice alt refuz (vezi fail() și gp-api.js).
        // Trimis ca text simplu, pagina Setări nu-l găsea și arăta doar
        // „Eroare 502." — tocmai motivul furnizorului, pentru care există
        // ruta asta, nu ajungea niciodată la om.
        fail($last['error'] ?? 'Cauză necunoscută.', 502, 'test_failed');
    }

    audit('settings_test', 'settings', $channel, 'Test trimis cu succes');
    json_out(['ok' => true, 'channel' => $channel]);
}
