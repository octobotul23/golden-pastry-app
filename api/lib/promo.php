<?php
/**
 * Reducerile, ca preț, nu ca vorbă pe ecran.
 *
 * Azi există una singură — „pachetul de test" de pe site: toate produsele din
 * gamă, câte trei bucăți, cu 20% mai puțin, o dată per client. Fișierul e
 * scris totuși pentru mai multe, fiindcă a doua reducere se cere singură (de
 * Crăciun, la prima comandă, la volum) și n-are rost ca ea să însemne
 * rescrierea rutelor.
 *
 * DE UNDE VINE, ÎN CUVINTE SIMPLE
 * ===============================
 *
 * Pagina publică arăta −20%. Serverul nu știa nimic despre asta: `price_items()`
 * retarifa fiecare poziție din `products`, deci cererea se înregistra la preț
 * de catalog. Diferența se cârpea cu o notă în mențiuni — „clientul a văzut
 * 244,89, catalog 306,15; aplică reducerea la confirmare" —, adică reducerea
 * era o rugăminte pe care o ținea minte un om. Uitată, clientul plătea cu o
 * sută de lei mai mult decât scria pe ecranul lui.
 *
 * Iar agentului de vânzări i se plătea comisionul din prețul de catalog, adică
 * dintr-o sumă pe care patiseria n-o încasase: o parte din comision ieșea din
 * reducere.
 *
 * CUM INTRĂ REDUCEREA ÎN PREȚ
 * ===========================
 *
 * NU printr-o ramură nouă în `price_items()`. Acolo există de mult mecanismul
 * potrivit: `$onorate`, „prețurile deja promise clientului", cu coadă de felii
 * pe cantitate (api/lib/orders.php). Pachetul de test E o promisiune de felul
 * ăsta: trei bucăți din fiecare produs, la 80% din catalog; ce trece peste trei
 * se plătește la zi.
 *
 * Din alegerea asta ies gratis două lucruri care altfel ar fi cerut cod:
 *
 *   * ACCEPTAREA CERERII nu retarifează. `route_requests_accept()` cheamă
 *     `honored_prices($items)` peste `order_request_items`, deci prețul redus
 *     scris la trimitere e chiar cel onorat la naștere comenzii.
 *   * `PATCH /orders` la fel: rescrie pozițiile onorând `order_items`. Mutarea
 *     de etapă de pe tableta din laborator nu mai poate rupe reducerea.
 *
 * DE CE `promo_gama()` CITEȘTE CATALOGUL, ȘI NU O LISTĂ SCRISĂ
 * ===========================================================
 *
 * Site-ul scrie „Toate cele N produse din gamă, câte 3 bucăți". N e lungimea
 * catalogului public, nu un număr ales. Dacă mâine se adaugă un produs,
 * pachetul trebuie să-l cuprindă — altfel pagina ar minți. O listă de id-uri
 * scrisă aici s-ar fi depărtat de catalog la primul produs nou, și tocmai
 * partea cu bani în ea ar fi rămas în urmă.
 *
 * Filtrul e IDENTIC cu al paginii: `active = 1` (ca `route_products_list()`)
 * plus amândouă prețurile scrise (ca `PROD` din Site Public.dc.html). Scris
 * altfel, serverul ar cere un produs pe care pagina nu-l pune în coș, iar
 * pachetul n-ar fi niciodată întreg.
 */

/** Codul singurei reduceri de azi. Scris o dată, ca să nu se scrie greșit. */
const GP_PROMO_PACHET = 'pachet-test';

/**
 * Reducerile pe care le cunoaște casa.
 *
 * `off`  — cât se scade, în procente.
 * `each` — câte bucăți din FIECARE produs intră în pachet.
 * `once` — se dă o singură dată per client?
 */
function promo_all(): array
{
    return [
        GP_PROMO_PACHET => [
            'code'  => GP_PROMO_PACHET,
            'label' => 'Pachet de test',
            'off'   => 20.0,
            'each'  => 3.0,
            'once'  => true,
        ],
    ];
}

/**
 * Baza știe de reducere?
 *
 * Același tipar ca `referral_money_supported()`: fișierele urcă pe server
 * înaintea importului din phpMyAdmin, deci codul trebuie să poată rula o vreme
 * pe o bază fără migrarea 011.
 *
 * ATENȚIE, aici răspunsul „nu" NU înseamnă „mergi mai departe fără reducere".
 * Ar însemna să înregistrăm la preț de catalog o cerere pe care clientul a
 * văzut-o redusă — adică exact defectul reparat. Rutele răspund 503 și spun ce
 * e de importat; vezi `promo_cere_baza()`.
 */
function promo_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $coloane = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND ((TABLE_NAME = 'order_requests'      AND COLUMN_NAME = 'promo')
                  OR (TABLE_NAME = 'orders'              AND COLUMN_NAME = 'promo')
                  OR (TABLE_NAME = 'order_request_items' AND COLUMN_NAME = 'list_price')
                  OR (TABLE_NAME = 'order_items'         AND COLUMN_NAME = 'list_price'))"
        );
        return $ok = ($coloane === 4);
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * Codul trimis de pagină, curățat. Gol înseamnă „fără reducere".
 *
 * Nu se face `strtolower` pe litere cu diacritice și nici înlocuiri istețe:
 * codul e ales de noi, nu tastat de client. Ce nu se potrivește exact e
 * necunoscut, iar necunoscutul se refuză la `promo_def()`.
 */
function promo_normalize($v): string
{
    if (!is_scalar($v)) {
        return '';
    }
    $s = strtolower(trim((string) $v));
    return preg_replace('/[^a-z0-9\-]/', '', $s) ?? '';
}

/** Definiția reducerii, sau null dacă n-o cunoaște nimeni. */
function promo_def(string $cod): ?array
{
    $toate = promo_all();
    return $toate[$cod] ?? null;
}

/**
 * Se oprește cu 503 dacă baza n-are încă migrarea 011.
 *
 * Mesajul e scris pentru omul care urcă pachetul pe cPanel, fiindcă el e
 * singurul care poate face ceva: clientul de pe site n-are ce învăța din el.
 */
function promo_cere_baza(): void
{
    if (!promo_supported()) {
        fail(
            'Reducerea nu se poate aplica: baza de date nu are încă migrarea 011. '
              . 'Importă sql/011_reducere_pachet_test.sql și încearcă din nou.',
            503,
            'promo_unavailable'
        );
    }
}

/**
 * Gama din care se face pachetul: exact ce vede clientul în catalogul public.
 *
 * @return array<int, array{id: int, price_frozen: ?float, price_baked: ?float}>
 */
function promo_gama(): array
{
    $rows = all(
        'SELECT id, price_frozen, price_baked
           FROM products
          WHERE active = 1 AND price_frozen IS NOT NULL AND price_baked IS NOT NULL
          ORDER BY sort, id'
    );
    return array_map(static fn($r) => [
        'id'           => (int) $r['id'],
        'price_frozen' => (float) $r['price_frozen'],
        'price_baked'  => (float) $r['price_baked'],
    ], $rows);
}

/**
 * Coșul chiar conține pachetul? Dacă da, în ce variantă?
 *
 * FĂRĂ ÎNTREBAREA ASTA REPARAȚIA AR FI FOST O REDUCERE LA CERERE. `promo` vine
 * din corpul cererii, adică de la client. Dacă s-ar fi aplicat pur și simplu
 * peste ce e în coș, oricine trimitea `promo: "pachet-test"` cu o singură
 * poziție lua −20% pe ea. Pachetul se dă numai cui chiar cumpără pachetul:
 * toate produsele din gamă, cel puțin `each` bucăți din fiecare, în ACEEAȘI
 * variantă — fiindcă asta pune butonul de pe site în coș.
 *
 * Cantitățile se adună pe produs, nu se caută un rând anume: coșul poate trimite
 * același produs pe două poziții (trei bucăți din pachet și o sută adăugate cu
 * mâna), iar pentru întrebarea de aici ele sunt tot o sută trei bucăți.
 *
 * @return 'congelat'|'copt'|null
 */
function promo_variant_din_cos(array $items, array $def, string $implicit): ?string
{
    $each = (float) $def['each'];
    $gama = promo_gama();
    if (!$gama) {
        return null;
    }

    $adunat = ['congelat' => [], 'copt' => []];
    foreach ($items as $it) {
        $pid = (int) ($it['productId'] ?? 0);
        $qty = is_numeric($it['qty'] ?? null) ? (float) $it['qty'] : 0.0;
        if ($pid <= 0 || $qty <= 0) {
            continue;
        }
        // `require_variant()`, nu `valid_variant()`: o variantă scrisă greșit
        // trebuie să iasă cu `bad_variant`, mesajul ei adevărat, nu să cadă tăcut
        // pe `copt` și să facă pachetul să pară neîntreg. `price_items()` ar fi
        // refuzat-o oricum mai jos, dar cu alt răspuns decât cel de aici.
        $vi = require_variant($it['variant'] ?? null, $implicit);
        $adunat[$vi][$pid] = ($adunat[$vi][$pid] ?? 0.0) + $qty;
    }

    // Varianta implicită se cercetează întâi: e cea a filei deschise pe site,
    // deci cea în care a fost adăugat pachetul în cazul obișnuit. Ordinea nu
    // schimbă răspunsul când e întreg într-una singură; contează doar când
    // cineva ar avea pachetul întreg în amândouă, iar atunci reducerea i se dă
    // pe cea aleasă, nu pe amândouă — pachetul e unul.
    $ordine = valid_variant($implicit) === 'congelat' ? ['congelat', 'copt'] : ['copt', 'congelat'];
    foreach ($ordine as $vi) {
        $intreg = true;
        foreach ($gama as $p) {
            if (($adunat[$vi][$p['id']] ?? 0.0) + 0.0001 < $each) {
                $intreg = false;
                break;
            }
        }
        if ($intreg) {
            return $vi;
        }
    }
    return null;
}

/**
 * Pachetul, scris ca prețuri promise — forma pe care o înțelege `price_items()`.
 *
 * Cheia e „produs|variantă", exact ca la `honored_prices()`, fiindcă ajunge în
 * același parametru. Fiecare produs promite `each` bucăți la prețul redus; ce
 * cere clientul peste ele iese ca poziție separată, la catalogul de azi.
 *
 * Rotunjirea la doi bani se face AICI, nu la înmulțire: `order_items.unit_price`
 * e DECIMAL(10,2), deci un preț cu trei zecimale s-ar rotunji oricum la
 * scriere, iar totalul socotit din cel nerotunjit n-ar mai da suma rândurilor
 * de pe factură.
 *
 * @return array<string, array<int, array{qty: float, unit: float}>>
 */
/**
 * Prețul unei bucăți, cu reducerea scăzută.
 *
 * Trei rânduri care merită numele lor, fiindcă sunt chemate din două locuri
 * care N-AU voie să se despartă: `promo_slices()`, adică banii, și
 * `promo_units()`, adică cifra de pe ecranul clientului.
 *
 * Cât timp pagina își socotea singură procentul, ele puteau ajunge la cifre
 * diferite din simpla rotunjire: `round()` din PHP corectează reprezentarea
 * binară înainte să taie, `Math.round` din JavaScript nu. Puse față în față
 * pe toate prețurile de la 0,01 la 50,00 lei, cele două se despart pe 776 din
 * 50.000 de cazuri — dar pe NICIUNUL la −20%, fiindcă un preț cu doi zecimali
 * înmulțit cu 0,8 nu cade niciodată exact pe jumătate. Adică defectul ar fi
 * stat adormit până la primul procent schimbat în `promo_all()`, și s-ar fi
 * arătat atunci ca un ban pe bucată — 78 de bani pe un pachet de 26 de
 * produse — fără nimic care să lege cauza de efect.
 *
 * Socotit o singură dată, aici, nu mai are de unde să apară.
 */
function promo_unit_price(float $catalog, float $off): float
{
    return round($catalog * (1 - $off / 100), 2);
}

/**
 * Prețurile reduse ale întregii game, pentru ecran.
 *
 * Pleacă odată cu catalogul (`GET /products`), deci pagina nu mai socotește
 * nimic: citește. Lista se face din aceeași interogare din care se fac și
 * feliile de preț promis, deci ce vede clientul și ce se scrie în bază sunt,
 * literalmente, aceleași numere.
 *
 * Listă, nu dicționar cu id-uri drept chei: PHP transformă cheile care arată a
 * număr în întregi, iar `json_encode` scoate atunci uneori un obiect și alteori
 * o listă, după cum se nimeresc id-urile. Forma trebuie să fie mereu aceeași.
 */
function promo_units(array $def): array
{
    $off = (float) $def['off'];
    $out = [];
    foreach (promo_gama() as $p) {
        $out[] = [
            'id'     => $p['id'],
            'frozen' => promo_unit_price($p['price_frozen'], $off),
            'baked'  => promo_unit_price($p['price_baked'], $off),
        ];
    }
    return $out;
}

function promo_slices(array $def, string $variant): array
{
    $vi   = valid_variant($variant);
    $each = (float) $def['each'];
    $off  = (float) $def['off'];

    $out = [];
    foreach (promo_gama() as $p) {
        $catalog = $vi === 'congelat' ? $p['price_frozen'] : $p['price_baked'];
        $out[$p['id'] . '|' . $vi][] = [
            'qty'  => $each,
            'unit' => promo_unit_price($catalog, $off),
        ];
    }
    return $out;
}

/**
 * Omul ăsta a mai primit reducerea?
 *
 * Cine e „același om" — trei chei, fiindcă un client poate comanda o dată fără
 * cont și o dată cu el, iar reducerea rămâne una singură:
 *
 *   * contul, când e conectat;
 *   * telefonul, normalizat cu `referral_telefon_cheie()` — aceeași funcție
 *     care hotărăște și în cazul comisioanelor cine e cine, ca cele două să nu
 *     înceapă mâine să răspundă diferit la aceeași întrebare;
 *   * e-mailul, în litere mici.
 *
 * CE NU CONSUMĂ REDUCEREA, dinadins: cererea RESPINSĂ. Omul a cerut, casa a
 * spus nu — n-a primit nimic, deci nu s-a folosit nimic. Comanda anulată la
 * fel: marfa nu s-a livrat.
 *
 * Telefonul se compară în PHP, nu în SQL, fiindcă normalizarea („+40", „0040",
 * „07…" duc toate la același număr) n-are cum să încapă într-un WHERE fără să
 * ajungă o expresie pe care indexul n-o poate folosi. Rândurile care ajung aici
 * sunt puține: numai cele cu reducerea asta pe ele.
 *
 * @return array{ref: string, day: string}|null
 */
function promo_used_by(string $cod, ?int $userId, string $phone, string $email): ?array
{
    if (!promo_supported() || $cod === '') {
        return null;
    }

    $anulata = status_cancelled();
    $randuri = all(
        "SELECT r.id, r.customer_id, r.phone, r.email, r.created_at,
                o.ref AS order_ref, o.status AS order_status
           FROM order_requests r
      LEFT JOIN orders o ON o.id = r.order_id
          WHERE r.promo = ? AND r.status <> 'respinsa'
          ORDER BY r.id DESC
          LIMIT 200",
        [$cod]
    );

    $telCheie  = referral_telefon_cheie($phone);
    $mailCheie = strtolower(trim($email));

    foreach ($randuri as $r) {
        // Comanda anulată nu ține reducerea ocupată.
        if ($anulata !== '' && $r['order_status'] !== null && (string) $r['order_status'] === $anulata) {
            continue;
        }

        $potrivit = false;
        if ($userId !== null && $r['customer_id'] !== null && (int) $r['customer_id'] === $userId) {
            $potrivit = true;
        }
        if (!$potrivit && $telCheie !== ''
            && referral_telefon_cheie((string) ($r['phone'] ?? '')) === $telCheie) {
            $potrivit = true;
        }
        if (!$potrivit && $mailCheie !== ''
            && strtolower(trim((string) ($r['email'] ?? ''))) === $mailCheie) {
            $potrivit = true;
        }

        if ($potrivit) {
            return [
                'ref' => $r['order_ref'] !== null ? (string) $r['order_ref'] : ('cererea #' . (int) $r['id']),
                'day' => substr((string) $r['created_at'], 0, 10),
            ];
        }
    }
    return null;
}

/**
 * Reducerea, așa cum o vede pagina publică.
 *
 * Se trimite în `GET /products`, lângă catalog, fiindcă acolo e nevoie de ea:
 * cardul pachetului își scrie prețul tăiat și pe cel plătit din CATALOG × off.
 * Cifrele astea stăteau până acum scrise în pagină (`TRIAL_EACH`, `TRIAL_OFF`)
 * — adică exact felul în care ecranul și banii ajung să spună lucruri diferite.
 * Venind de aici, pagina nu mai POATE arăta altă reducere decât cea aplicată.
 *
 * `false` când baza n-are migrarea: pagina ascunde atunci pachetul, în loc să
 * ofere o reducere pe care serverul o va refuza.
 */
function promo_public()
{
    if (!promo_supported()) {
        return false;
    }
    $out = [];
    foreach (promo_all() as $cod => $d) {
        $out[$cod] = [
            'code'  => $d['code'],
            'label' => $d['label'],
            'off'   => $d['off'],
            'each'  => $d['each'],
            'once'  => (bool) $d['once'],
            // Procentul rămâne, dar numai ca text pe card („−20%"). Cifrele
            // după care se socotește coșul sunt cele de aici.
            'units' => promo_units($d),
        ];
    }
    return $out;
}
