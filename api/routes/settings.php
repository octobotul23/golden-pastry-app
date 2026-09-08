<?php
/** Panoul de Setări. Strict pentru rolul `admin`. */

declare(strict_types=1);

function route_settings_get(): void
{
    require_role('admin');
    // Anunțurile eșuate pleacă odată cu setările, ca panoul să le poată arăta
    // fără o cerere în plus. Până acum eșecul se scria doar în tabelul
    // `notifications`, în care nu se uita nicio pagină: o comandă care nu a
    // ajuns la laborator rămânea necunoscută până suna clientul să întrebe de
    // ea.
    json_out([
        'settings' => settings_public(),
        'failed'   => failed_notifications(),
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
    $rows = all(
        "SELECT id, channel, recipient, subject, error, order_id, created_at
           FROM notifications
          WHERE status = 'failed' AND seen_at IS NULL
          ORDER BY id DESC LIMIT 20"
    );

    return array_map(static fn($r) => [
        'id'        => (int) $r['id'],
        'channel'   => $r['channel'],
        'recipient' => $r['recipient'],
        'subject'   => $r['subject'],
        'error'     => $r['error'],
        'orderId'   => $r['order_id'] === null ? null : (int) $r['order_id'],
        'createdAt' => $r['created_at'],
    ], $rows);
}

/**
 * POST /settings/notifications/seen
 *
 * Golește lista de anunțuri eșuate din panou. Rândurile rămân în tabel, doar
 * primesc `seen_at`: fără butonul acesta, o defecțiune reparată acum o
 * săptămână ar rămâne pe ecran la nesfârșit și nu ai mai deosebi-o de una nouă,
 * adică exact felul în care se pierde din vedere un anunț care chiar contează.
 */
function route_settings_notifications_seen(): void
{
    require_role('admin');

    $n = q(
        "UPDATE notifications SET seen_at = UTC_TIMESTAMP()
          WHERE status = 'failed' AND seen_at IS NULL"
    )->rowCount();

    if ($n > 0) {
        audit('notifications_seen', 'settings', null, $n . ' anunțuri eșuate marcate ca văzute');
    }
    json_out(['ok' => true, 'marked' => $n, 'failed' => failed_notifications()]);
}

function route_settings_save(): void
{
    $u = require_role('admin');
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

    validate_lists($incoming);
    guard_status_list($incoming);
    guard_zone_list($incoming);
    $incoming = guard_zone_coords($incoming);
    $incoming = guard_hq_coords($incoming);

    $changed = settings_save($incoming, $u);
    // `true` = recitire din baza de date, ca răspunsul să reflecte ce s-a salvat.
    json_out(['ok' => true, 'changed' => $changed, 'settings' => settings_public(true)]);
}

/* ------------------------------------------------------------- validări -- */

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

    foreach (['order_status_done' => 'încheiată', 'order_status_cancelled' => 'anulată'] as $key => $label) {
        if (!array_key_exists($key, $incoming)) {
            continue;
        }
        $v = trim((string) $incoming[$key]);
        if ($v !== '' && !in_array($v, $next, true)) {
            fail('Etapa „' . $label . '” trebuie să fie una dintre etapele din listă.', 422, 'bad_special_status');
        }
    }
}

/**
 * Protejează zonele folosite de comenzi care încă urmează să fie livrate.
 *
 * O zonă ștearsă din listă nu face comenzile să dispară, dar le lasă cu o
 * zonă pe care harta nu o mai cunoaște: oprirea primește coordonatele
 * depozitului, iar ruta arată zero kilometri până la ea. Ar părea corectă și
 * n-ar fi, ceea ce e mai rău decât o eroare pe față.
 *
 * Comenzile din trecut nu contează — ruta lor s-a făcut demult.
 */
function guard_zone_list(array $incoming): void
{
    if (!array_key_exists('delivery_zones', $incoming)) {
        return;
    }
    $raw  = $incoming['delivery_zones'];
    $list = is_array($raw) ? $raw : json_decode((string) $raw, true);
    $next = [];
    foreach ((array) $list as $v) {
        $v = trim((string) $v);
        if ($v !== '' && !in_array($v, $next, true)) {
            $next[] = $v;
        }
    }

    // Ca și la etape: doar zonele pe care le scoate chiar această salvare.
    $current = delivery_zones();
    $inUse = all(
        'SELECT zone, COUNT(*) AS n FROM orders
          WHERE zone IS NOT NULL AND zone <> \'\' AND day >= UTC_DATE()
          GROUP BY zone'
    );
    foreach ($inUse as $row) {
        $zone = (string) $row['zone'];
        if (in_array($zone, $current, true) && !in_array($zone, $next, true)) {
            fail(
                'Zona „' . $zone . '” nu poate fi ștearsă: ' . (int) $row['n'] .
                ((int) $row['n'] === 1
                    ? ' comandă care urmează este în ea. Mut-o mai întâi în altă zonă.'
                    : ' comenzi care urmează sunt în ea. Mută-le mai întâi în altă zonă.'),
                409,
                'zone_in_use'
            );
        }
    }
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
    require_role('admin');

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
