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

/**
 * Setarea așa cum stă în bază, fără nicio acoperire: null = RÂNDUL LIPSEȘTE.
 *
 * `setting()` nu poate face deosebirea asta, și de obicei e tocmai ce trebuie:
 * pentru ea „n-am rândul" și „omul a golit caseta" sunt același șir gol, deci
 * amândouă cad pe rezervă, iar aplicația merge înainte.
 *
 * Dar sunt setări unde GOLUL E O HOTĂRÂRE, nu o lipsă — caseta pe care omul a
 * șters-o dinadins, ca să oprească ceva. Acolo rezerva calcă peste voia lui, în
 * tăcere, și cu atât mai rău cu cât rezerva e o cifră care pare cuminte.
 * Procentul de recomandare e prima astfel de setare (api/lib/referral.php ·
 * `referral_default_rate()`).
 */
function setting_raw(string $key): ?string
{
    $all = settings_all();
    return array_key_exists($key, $all) ? (string) $all[$key] : null;
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

/**
 * Etapele prin care trece o comandă, fără cea de anulare.
 *
 * Anularea nu e un pas înainte, e o ieșire din drum: pusă în șir, „înapoi cu o
 * etapă" de pe ea ar duce la ambalare, iar „mai departe" de la ambalare ar
 * anula comanda. Panourile o scoteau fiecare pe cont propriu (`gp.flow()` în
 * browser); de-acum o scoate și serverul, din același izvor, pentru plafonul
 * de mai jos.
 */
function order_flow(): array
{
    $anulata = status_cancelled();
    return array_values(array_filter(
        order_statuses(),
        static fn($x) => $anulata === '' || $x !== $anulata
    ));
}

/**
 * Ultima etapă pe care o poate pune LABORATORUL: cea dinaintea celei finale.
 *
 * HOTĂRÂREA PATRONULUI, 19 septembrie 2026. Etapa finală înseamnă „ajunsă la
 * client", iar lista n-are una separată pentru „dată curierului". Laboratorul
 * o apăsa când termina de ambalat, deci comanda apărea livrată înainte să fi
 * urcat în mașină.
 *
 * SE SOCOTEȘTE, NU SE SCRIE ÎN COD. Lista de etape e editabilă din Setări:
 * scrisă pe litere („La ambalare"), plafonul ar fi rămas în urmă tăcut la
 * prima redenumire, iar laboratorul ar fi rămas fie cu o etapă în plus, fie
 * blocat cu una mai devreme. Tot din Setări se ia și care e cea finală
 * (`status_done()`), nu „ultima din listă".
 *
 * CAZUL SCHILOD, spus pe față: un flux cu O SINGURĂ etapă — care e atunci și
 * prima, și cea finală — iese cu plafonul tocmai pe ea, deci laboratorul ar
 * putea-o pune. E o listă de etape pe care n-o are nimeni, iar dacă cineva și-o
 * face, regula adevărată tot ține: poarta de pe server (`poarta_etapei_finale`,
 * api/routes/orders.php) întreabă semnul contului, nu plafonul ăsta. Plafonul e
 * politețea paginii, nu paza.
 */
function status_lab_ceiling(): string
{
    $flux = order_flow();
    if (!$flux) {
        return '';
    }
    $i = array_search(status_done(), $flux, true);
    if ($i === false) {
        // Etapa finală nu e în flux: setarea arată spre anulare ori spre un
        // nume care nu mai există. `status_done()` se apără singur de asta, dar
        // dacă totuși se întâmplă, plafonul e capătul fluxului — adică purtarea
        // de dinainte de runda 2.3.0, nu un laborator blocat.
        return $flux[count($flux) - 1];
    }
    return $flux[max($i - 1, 0)];
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

/**
 * Modul de onorare presupus când cererea nu spune niciunul.
 *
 * Era scris în cod, „Livrare", în amândouă locurile care nasc o comandă. Dar
 * modurile se editează din Setări: la o redenumire („Livrare" → „Curier"),
 * implicitul nu mai era în listă, iar verificarea de imediat după el răspundea
 * 422 „bad_fulfillment". Paguba nu e formularul din panou — acela trimite
 * întotdeauna un mod ales — ci butonul „✅ Acceptă" de sub anunțul din
 * Telegram: el intră cu CORPUL GOL (`api/routes/telegram.php`, apelul cu
 * `[]`), deci cade mereu pe implicit. După redenumire, butonul de pe telefon
 * murea pe vecie, cu un refuz pe care nimeni nu-l vedea.
 *
 * Nici primul mod din listă nu e un implicit onest: în lista implicită acela e
 * „Angro", adică en-gros — o comandă luată la telefon ar fi ajuns pe en-gros
 * și ar fi ieșit de pe ruta șoferului. Deci se caută primul mod care CHIAR
 * pleacă cu mașina, prin lista pusă pe dos din `pickup_fulfillments()`: cu
 * setările implicite iese tot „Livrare", iar după redenumire iese „Curier".
 *
 * Dacă TOATE modurile sunt de ridicare — se poate, e hotărârea patiseriei —
 * rămâne primul din listă: un mod adevărat, care trece verificarea.
 * `fulfillment_types()` nu se poate întoarce goală (`setting_list()` se
 * încheie cu `?: $fallback`), deci nu e nevoie de altă plasă.
 */
function default_fulfillment(): string
{
    $tipuri   = fulfillment_types();
    $ridicari = pickup_fulfillments();
    foreach ($tipuri as $t) {
        if (!in_array($t, $ridicari, true)) {
            return $t;
        }
    }
    return $tipuri[0];
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
    // Valorile de dinainte de salvare, pentru jurnal. `settings_all()` ține un
    // cache pe cerere, deci citirea de aici e chiar starea veche, și rămâne așa
    // și după UPDATE-urile de mai jos — care nu golesc cache-ul.
    $vechi   = settings_all();
    $known   = array_keys($vechi);
    $secrets = secret_keys();
    $changed = [];
    $jurnal  = [];
    $inchide = false;

    // DOUĂ treceri, nu una: întâi se pregătește și se cântărește tot, abia apoi
    // se scrie. Într-o singură trecere, o valoare prea lungă găsită la a cincea
    // cheie oprea salvarea cu primele patru deja scrise — jumătate de formular
    // salvat, fără ca omul să afle care jumătate. Ce pleacă într-o cerere se
    // scrie întreg sau deloc, deci și refuzul trebuie să fie întreg.
    $deScris = [];

    // CHEILE FĂRĂ RÂND ÎN TABEL SE REFUZĂ PE NUME — TOATE, înainte de orice.
    //
    // `$known` sunt cheile care AU rând azi în `settings`, fiindcă salvarea
    // scrie cu UPDATE, nu cu INSERT. Sărirea tăcută de aici a ținut ani:
    // panoul avea casetele „Adresa publică" și „Program de lucru", pagina
    // publică le citea, dar rândurile lor nu fuseseră semănate niciodată —
    // deci patronul scria, apăsa „Salvează", primea „ok", și nu se schimba
    // nimic. (Rândurile se seamănă acum, în `sql/018_adresa_si_program.sql`.)
    //
    // Un refuz e mai bun decât o tăcere chiar și pentru o cheie cu adevărat
    // necunoscută — o greșeală de tastare într-un script, o cheie ștearsă din
    // bază cu mâna: toate arătau la fel, adică a izbândă.
    //
    // Se refuză ÎNTREG, ca toate celelalte verificări de aici, și de-aia stă
    // înaintea buclei care cântărește, nu în cea care scrie. Dar se STRÂNG
    // întâi toate: pe o bază căreia îi lipsește o migrare, aceeași cerere
    // poate purta două-trei chei fără rând (018 aduce adresa ȘI programul),
    // iar un refuz care o numea doar pe prima îl trimitea pe om la actualizare
    // pentru o cheie și-l lăsa s-o afle pe a doua abia la apăsarea următoare.
    // Pagina de Setări nu le mai trimite deloc (`faraRand()` din
    // Setari.dc.html); refuzul de aici rămâne pentru orice altă cerere.
    $faraRand = [];
    foreach (array_keys($incoming) as $key) {
        if (!in_array($key, $known, true)) {
            $faraRand[] = (string) $key;
        }
    }
    if ($faraRand) {
        $una = count($faraRand) === 1;
        fail(
            ($una ? 'Setarea ' : 'Setările ')
              . implode(', ', array_map(static fn ($k) => '„' . $k . '"', $faraRand))
              . ' nu există în baza de date, deci nu '
              . ($una ? 'poate fi salvată' : 'pot fi salvate')
              . '. Cel mai adesea înseamnă că lipsește o migrare din `sql/`; pe server, '
              . '`sudo bash actualizeaza-vps.sh` le aduce pe toate — vezi '
              . 'docs/09-actualizare-vps.md. Nimic din salvarea asta NU a fost scris: '
              . 'formularul se salvează întreg sau deloc.',
            422,
            'unknown_setting'
        );
    }

    foreach ($incoming as $key => $raw) {
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

        // Coloana `settings.value` e TEXT: 65535 de OCTEȚI, nu de caractere. Cu
        // diacritice o literă ocupă doi, iar un secret se și criptează, deci se
        // umflă. Măsurăm exact ce urmează să scriem — după `json_encode`, după
        // criptare — fiindcă altfel MySQL răspundea „Data too long", adică
        // „Eroare internă" în fața omului, iar restul cheilor din aceeași
        // salvare rămâneau nescrise: jumătate de formular salvat, fără ca
        // cineva să afle care jumătate.
        //
        // Marginea de 5535 de octeți nu e superstiție: e loc pentru ca o
        // valoare aflată fix la limită să nu depindă de rotunjirile criptării.
        if (strlen($store) > 60000) {
            fail(
                'Valoarea pentru „' . $key . '” este prea lungă: ' . strlen($store) . ' ' .
                ro_de(strlen($store)) . 'octeți, maximum 60000. Scurteaz-o și salvează din nou.',
                422,
                'setting_too_long'
            );
        }

        // Se ține și ce se scrie în bază, și textul curat. Pentru secrete
        // cele două diferă: în coloană intră textul criptat, iar criptarea are
        // vector de inițializare aleatoriu — același token criptat de două ori
        // dă șiruri diferite. O comparație „s-a schimbat?" făcută pe ce se
        // scrie ar răspunde „da" la fiecare salvare, la fiecare secret.
        $clar = ($isSecret && $val === '__clear__') ? '' : $val;
        $deScris[$key] = [$store, $clar];
    }

    foreach ($deScris as $key => [$store, $clar]) {
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
        $changed[] = $key;
        // Ce anume s-a schimbat, nu doar care casetă a fost trimisă.
        //
        // Formularul de Setări se trimitea pe atunci ÎNTREG (azi pleacă doar
        // diferențele, din `changes()`), deci `$changed` cuprindea și
        // cheile pe care nu le-a atins nimeni: jurnalul scria de fiecare dată
        // aceeași listă de șapte-opt nume tehnice, dintre care unul singur era
        // cel căutat. „Care" fără „ce" nu e o urmă, e zgomot — iar cine căuta
        // în Jurnal cine a mutat ora de deschidere găsea zece rânduri identice.
        //
        // Valoarea unui secret nu se scrie NICIODATĂ, nici veche, nici nouă:
        // jurnalul se citește de la orice administrator, iar un token scris
        // acolo ar rămâne în clar într-o tabelă care nu se șterge niciodată.
        // Se spune doar că s-a pus, s-a schimbat ori s-a șters.
        $inainte = (string) ($vechi[$key] ?? '');
        if ($clar !== $inainte) {
            $jurnal[] = in_array($key, $secrets, true)
                ? $key . ': ' . ($inainte === '' ? 'pus' : ($clar === '' ? 'șters' : 'schimbat'))
                : $key . ': ' . audit_value($inainte === '' ? null : $inainte)
                    . ' → ' . audit_value($clar === '' ? null : $clar);
        }
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
        // O salvare care n-a schimbat nimic tot lasă urmă — cineva a deschis
        // formularul și a apăsat „Salvează" — dar o spune pe față, în loc să
        // arate ca o modificare.
        audit('settings_update', 'settings', null, $jurnal
            ? implode(' · ', $jurnal)
            : implode(', ', $changed) . ' · nimic schimbat');
    }
    return $changed;
}
