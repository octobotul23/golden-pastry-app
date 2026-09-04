<?php
/**
 * Setările editabile din panou (lab.goldenpastry.ro/setari).
 *
 * Valorile marcate `is_secret` sunt criptate în baza de date și nu părăsesc
 * niciodată serverul în clar: interfața primește doar o formă mascată.
 */

declare(strict_types=1);

/**
 * Toate setările, decriptate. Citite o singură dată per cerere.
 *
 * `$fresh` forțează recitirea din baza de date. Este necesar după o salvare:
 * altfel răspunsul ar fi construit din cache-ul dinainte de scriere și panoul
 * ar afișa vechea valoare, ca și cum salvarea ar fi eșuat.
 */
function settings_all(bool $fresh = false): array
{
    static $cache = null;
    if ($fresh) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    foreach (all('SELECT setting_key, value, is_secret FROM settings') as $r) {
        $val = (string) ($r['value'] ?? '');
        if ((int) $r['is_secret'] === 1 && $val !== '') {
            $dec = decrypt_value($val);
            // null = valoare coruptă sau cheie schimbată. O tratăm ca necompletată,
            // ca aplicația să funcționeze și să poți reintroduce tokenul din panou.
            if ($dec === null) {
                error_log('[gp] setare secretă indecodabilă: ' . $r['setting_key']);
            }
            $val = $dec ?? '';
        }
        $cache[$r['setting_key']] = $val;
    }
    return $cache;
}

/** O singură setare. */
function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    $v   = $all[$key] ?? '';
    return $v === '' ? $default : $v;
}

function setting_bool(string $key): bool
{
    return setting($key) === '1';
}

/**
 * O setare care ține o listă JSON (etape de producție, intervale, zone).
 *
 * Listele sunt editabile din panoul de Setări, de aceea nu sunt ENUM-uri în
 * schemă: schimbarea fluxului din laborator nu trebuie să ceară modificarea
 * bazei de date. `$fallback` acoperă cazul în care setarea lipsește sau a fost
 * salvată stricat — aplicația trebuie să funcționeze oricum.
 */
function setting_list(string $key, array $fallback = []): array
{
    $raw = setting($key);
    if ($raw === '') {
        return $fallback;
    }
    $list = json_decode($raw, true);
    if (!is_array($list)) {
        error_log('[gp] setarea „' . $key . '” nu conține JSON valid');
        return $fallback;
    }
    // Doar șiruri nevide, fără duplicate, cu indici renumerotați.
    //
    // Ce nu e text simplu se sare, nu se convertește: `(string) $tablou` dă în
    // PHP literal „Array”, deci o valoare imbricată strecurată în baza de date
    // ar apărea în panou și în formularul clientului ca un interval numit
    // „Array”. Ruta o oprește la salvare; aici o oprim și la citire, pentru
    // rândurile scrise înainte de acea verificare.
    $clean = [];
    foreach ($list as $v) {
        if (is_array($v) || is_object($v)) {
            continue;
        }
        $v = trim((string) $v);
        if ($v !== '' && !in_array($v, $clean, true)) {
            $clean[] = $v;
        }
    }
    return $clean ?: $fallback;
}

/** Etapele din laborator, în ordinea în care avansează o comandă. */
function order_statuses(): array
{
    return setting_list('order_statuses', ['În așteptare', 'La dospit', 'În cuptor', 'La ambalare', 'Predată', 'Anulată']);
}

/**
 * Etapa care încheie comanda („Predată”) și cea care o anulează.
 *
 * Sunt setări separate, nu valori scrise în cod, fiindcă lista de etape este
 * editabilă din panou. Dacă „Predată” devine „Livrată”, șoferul, depozitul și
 * pagina clientului trebuie să afle asta de aici — altfel fiecare ar căuta un
 * text care nu mai există și comanda ar părea veșnic neterminată.
 *
 * Amândouă cad înapoi pe ultima, respectiv prima etapă potrivită din listă,
 * ca aplicația să funcționeze chiar dacă setarea a rămas necompletată.
 */
function status_done(): string
{
    $v   = setting('order_status_done');
    $all = order_statuses();
    if ($v !== '' && in_array($v, $all, true)) {
        return $v;
    }
    // Fără setare validă luăm ultima etapă — dar niciodată pe cea de anulare.
    // Altfel butonul „gata” din laborator ar anula comanda, iar asta s-a și
    // întâmplat o dată, când setarea era coruptă.
    $cancelled = setting('order_status_cancelled');
    $flow      = array_values(array_filter($all, static fn($x) => $x !== $cancelled));
    return $flow ? $flow[count($flow) - 1] : '';
}

function status_cancelled(): string
{
    $v   = setting('order_status_cancelled');
    $all = order_statuses();
    return $v !== '' && in_array($v, $all, true) ? $v : '';
}

function delivery_slots(): array
{
    return setting_list('delivery_slots', ['06:30', '08:00', '09:00', '10:00', '12:30', '14:00', '16:00']);
}

/**
 * O coordonată de zonă, adusă la număr.
 *
 * Întoarce null pentru orice nu se poate folosi ca punct pe hartă: câmp lăsat
 * gol, text, sau un zero — care aici înseamnă tot „necompletat”, fiindcă
 * depozitul și zonele de livrare sunt în București, nu pe ecuator și nu pe
 * meridianul Greenwich. Fără verificarea asta, un câmp golit în Setări era
 * citit ca 0: oprirea ajungea în Golful Guineei, piciorul de rută ieșea de mii
 * de kilometri, iar „Optimizează ruta” rearanja opririle față de punctul acela.
 *
 * Virgula zecimală este acceptată fiindcă tastatura românească asta dă, iar
 * caseta din Setări nu o refuză: „44,4795” citit ca 44 mută zona cu vreo 50 de
 * kilometri, fără ca cineva să aibă de unde afla.
 */
function zone_coord($raw): ?float
{
    if (is_string($raw)) {
        $raw = str_replace(',', '.', trim($raw));
    }
    if (!is_numeric($raw)) {
        return null;
    }
    $v = (float) $raw;
    return $v == 0.0 ? null : $v;
}

/**
 * Coordonatele zonelor de livrare, pentru estimarea distanțelor.
 *
 * Zonele erau editabile din Setări, dar coordonatele stăteau scrise în cod, în
 * trei pagini. O zonă adăugată din panou primea în tăcere coordonatele
 * Centrului, iar kilometrii de pe ruta șoferului ieșeau greșiți fără ca cineva
 * să aibă de unde afla.
 */
function zone_coords(): array
{
    $raw = setting('delivery_zone_coords');
    if ($raw === '') {
        return [];
    }
    $map = json_decode($raw, true);
    if (!is_array($map)) {
        error_log('[gp] delivery_zone_coords nu conține JSON valid');
        return [];
    }

    $out = [];
    foreach ($map as $zone => $point) {
        if (!is_array($point)) {
            continue;
        }
        $lat = zone_coord($point['lat'] ?? null);
        $lng = zone_coord($point['lng'] ?? null);
        // Coordonate imposibile sau necompletate ar muta o oprire în ocean; le
        // ignorăm, iar paginile cad atunci pe depozit: ruta arată zero
        // kilometri până la zona aceea, ceea ce se vede că e greșit — spre
        // deosebire de o distanță până în Golful Guineei, care arată ca o cifră
        // adevărată. Tot aici se opresc și zonele salvate cu 0,0 înainte ca
        // verificarea de la salvare să existe.
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            continue;
        }
        $out[(string) $zone] = ['lat' => $lat, 'lng' => $lng];
    }
    return $out;
}

function delivery_zones(): array
{
    return setting_list('delivery_zones', []);
}

function stock_categories(): array
{
    return setting_list('stock_categories', ['Materii prime', 'Produse finite', 'Ambalaje']);
}

function stock_units(): array
{
    return setting_list('stock_units', ['buc', 'kg', 'L']);
}

function fulfillment_types(): array
{
    return setting_list('fulfillment_types', ['Angro', 'Livrare', 'Ridicare']);
}

/**
 * Modurile de onorare în care marfa NU pleacă cu mașina noastră.
 *
 * Restul modurilor din lista de mai sus devin opriri pe ruta șoferului.
 * Ruta alegea opririle comparând cu textul „Livrare” scris în pagină, deși
 * modurile se editează din Setări: un mod adăugat din panou („Curier”) sau
 * „Livrare” redenumită lăsau ruta goală, deși comenzile existau și ajunseseră
 * pe telefon. Lista e pusă pe dos dinadins — spune cine NU se livrează, nu
 * cine se livrează — fiindcă un mod nou trebuie să ajungă pe rută până când
 * cineva spune altfel: o oprire în plus se vede și se corectează, una lipsă nu
 * se vede deloc, iar clientul rămâne fără marfă.
 */
function pickup_fulfillments(): array
{
    return setting_list('fulfillment_pickup', ['Angro', 'Ridicare']);
}

/** Cheile secrete, după coloana is_secret. */
function secret_keys(): array
{
    static $keys = null;
    if ($keys === null) {
        $keys = array_column(all('SELECT setting_key FROM settings WHERE is_secret = 1'), 'setting_key');
    }
    return $keys;
}

/**
 * Forma trimisă către panou: secretele apar mascate (`••••••3f9a`) plus un
 * indicator `*_set` care spune dacă valoarea există, ca interfața să poată
 * afișa „configurat” fără a cunoaște tokenul.
 */
function settings_public(bool $fresh = false): array
{
    $all     = settings_all($fresh);
    $secrets = secret_keys();
    $out     = [];

    foreach ($all as $k => $v) {
        if (in_array($k, $secrets, true)) {
            $out[$k]           = mask_secret($v);
            $out[$k . '_set'] = $v !== '';
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
}

/**
 * Salvează setările trimise din panou.
 *
 * Regula pentru secrete: un câmp gol înseamnă „lasă valoarea existentă
 * neschimbată”. Fără asta, simpla deschidere a paginii — unde tokenul apare
 * mascat — ar șterge tokenul la prima salvare. Ștergerea explicită se face
 * trimițând valoarea specială `__clear__`.
 */
function settings_save(array $incoming, array $user): array
{
    $known   = array_keys(settings_all());
    $secrets = secret_keys();
    $changed = [];
    $inchide = false;

    foreach ($incoming as $key => $raw) {
        if (!in_array($key, $known, true)) {
            continue;                        // ignorăm chei necunoscute
        }
        // Listele pot veni și ca tablou JSON, nu doar ca șir: validările din
        // rută le acceptă explicit așa (`is_array($raw) ? $raw : json_decode(…)`),
        // deci o cerere scrisă de mână — curl, un script de import — trece de
        // ele. Fără ramura de aici, `(string) $tablou` dă în PHP literal
        // „Array”: salvarea răspunde „ok”, cu cheia în lista „changed”, dar
        // valoarea din baza de date nu se mai poate decoda la citire și lista
        // sare înapoi pe valorile scrise în cod. Adică intervalele de livrare
        // configurate dispar fără ca cineva să afle, iar clientul vede în
        // formular alte ore decât cele la care se coace.
        if (is_bool($raw)) {
            $val = $raw ? '1' : '0';
        } elseif (is_array($raw)) {
            $val = (string) json_encode($raw, JSON_UNESCAPED_UNICODE);
        } else {
            $val = trim((string) $raw);
        }
        $isSecret  = in_array($key, $secrets, true);

        if ($isSecret) {
            if ($val === '' || str_starts_with($val, '•')) {
                continue;                    // necompletat → păstrăm ce era
            }
            $store = $val === '__clear__' ? '' : encrypt_value($val);
        } else {
            $store = $val;
        }

        q(
            'UPDATE settings SET value = ?, updated_by = ? WHERE setting_key = ?',
            [$store, (int) $user['id'], $key]
        );
        // Schimbarea codului sau oprirea accesului scoate tabletele afară pe
        // loc (vezi mai jos). Starea se citește de aici, din ce tocmai s-a
        // scris, nu cu setting_bool(): settings_all() ține un cache pe cerere,
        // iar acela e încă cel de dinaintea salvării.
        if ($key === 'panel_code' || ($key === 'panel_enabled' && $store !== '1')) {
            $inchide = true;
        }
        // Jurnalizăm doar numele cheii, niciodată valoarea unui secret.
        $changed[] = $key;
    }

    // Sesiunile deja deschise pe tablete rămâneau valabile până la expirare:
    // schimbai codul fiindcă plecase cineva din echipă, iar tableta lui
    // continua să vadă comenzile zilei — exact ce voiai să oprești. La fel la
    // „Dezactivat": poarta se închidea doar pentru cine nu intrase încă.
    if ($inchide) {
        q("DELETE s FROM sessions s JOIN users u ON u.id = s.user_id
            WHERE u.username = 'panou' AND u.is_system = 1");
    }

    if ($changed) {
        audit('settings_update', 'settings', null, implode(', ', $changed));
    }
    return $changed;
}
