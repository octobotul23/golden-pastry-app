<?php
/**
 * Forma publică a catalogului: produse și grupe.
 *
 * Stau aici, nu într-un fișier de rută, fiindcă routerul încarcă DOAR fișierul
 * rutei potrivite: `routes/products.php` (citirea, publică) și
 * `routes/catalog.php` (editarea, pentru admin) nu se văd niciodată între ele.
 * Prima versiune avea `category_public()` doar în al doilea, iar catalogul
 * răspundea cu eroare la fiecare cerere de pe site.
 */

declare(strict_types=1);

/**
 * Știe baza de laturi, adică a intrat migrarea 024?
 *
 * Aceeași formă ca `promo_supported()` (api/lib/promo.php), dar cu o deosebire
 * care contează: la promo, „nu” înseamnă 503 — o reducere pe care serverul n-o
 * poate socoti ar încasa un preț pe care clientul nu l-a văzut. Aici „nu”
 * înseamnă doar „poartă-te ca înainte de 024”, iar purtarea aceea e corectă:
 * produsele cu amândouă prețurile se văd pe amândouă laturile. Un 503 ar opri
 * magazinul pentru o fereastră de câteva minute în care el chiar poate lucra.
 *
 * Răspunsul se ține minte pe cererea asta: se întreabă din mai multe locuri,
 * iar information_schema nu se schimbă în mijlocul unei cereri.
 */
function catalog_laturi_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $coloane = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                AND COLUMN_NAME IN ('in_frozen', 'in_baked', 'photo_baked')"
        );
        return $ok = ($coloane === 3);
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * Coloanele celor trei prețuri (migrarea 028), așa cum se cer într-un SELECT.
 *
 * Scrise o singură dată, fiindcă se cer din trei locuri — lista de produse,
 * tarifarea și editarea — iar o coloană uitată într-unul din ele n-ar da nicio
 * eroare: `latura_scara()` ar răspunde cuminte „numai A", și produsul s-ar vinde
 * tăcut la prețul mare.
 */
const CATALOG_TREPTE_COLOANE = 'price_frozen_b, price_frozen_c, price_baked_b, price_baked_c, prag_b, prag_c';

/**
 * Știe baza de cele trei prețuri, adică a intrat migrarea 028?
 *
 * Se cer ȘI coloanele de pe rândurile de comandă, nu doar cele de pe produs.
 * Pe o bază cu jumătate din migrare — produsele cu trepte, pozițiile fără —
 * comenzile s-ar tarifa la B și s-ar scrie fără treaptă, iar prima corectură
 * n-ar mai ști că B a fost câștigat de un prag. Deci ori tot, ori nimic: fără
 * migrare, casa vinde exact ca ieri, cu prețul A.
 *
 * Spre deosebire de reducere (`promo_supported()`), „nu" nu oprește nimic cu
 * 503: un catalog fără trepte e starea de ieri, nu o stare stricată. Numai
 * scrierea unui preț B sau C se refuză — v. `route_products_update()`.
 */
function catalog_trepte_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $coloane = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND ((TABLE_NAME = 'products'
                      AND COLUMN_NAME IN ('price_frozen_b', 'price_frozen_c', 'price_baked_b',
                                          'price_baked_c', 'prag_b', 'prag_c'))
                  OR (TABLE_NAME IN ('order_items', 'order_request_items')
                      AND COLUMN_NAME IN ('treapta', 'prag_treapta')))"
        );
        return $ok = ($coloane === 10);
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** Bucata de SELECT pentru prețurile pe trepte: goală pe o bază fără 028. */
function catalog_trepte_select(): string
{
    return catalog_trepte_supported() ? ', ' . CATALOG_TREPTE_COLOANE : '';
}

/**
 * Coloanele fișelor tehnice (migrarea 031), așa cum se cer într-un SELECT.
 *
 * Scrise o singură dată, din același motiv ca treptele: o coloană uitată în
 * lista de produse n-ar da nicio eroare — `latura_fisa()` ar răspunde cuminte
 * „fără fișă", iar butonul ar lipsi tăcut din tot magazinul.
 */
const CATALOG_FISE_COLOANE = 'sheet_frozen, sheet_baked';

/**
 * Știe baza de fișe, adică a intrat migrarea 031?
 *
 * „Nu" nu oprește nimic la citire: un catalog fără fișe e starea de ieri, iar
 * magazinul pur și simplu nu arată butonul. Numai ÎNCĂRCAREA și SCOATEREA unei
 * fișe se refuză, cu 503 și cu numele fișierului de importat — v.
 * `route_products_sheet()` în api/routes/catalog.php.
 *
 * Aceeași formă ca `catalog_trepte_supported()`, ținută minte pe cererea asta.
 */
function catalog_fise_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $coloane = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                AND COLUMN_NAME IN ('sheet_frozen', 'sheet_baked')"
        );
        return $ok = ($coloane === 2);
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/** Bucata de SELECT pentru fișele tehnice: goală pe o bază fără 031. */
function catalog_fise_select(): string
{
    return catalog_fise_supported() ? ', ' . CATALOG_FISE_COLOANE : '';
}

/**
 * Vederile catalogului: un produs pe rând (cu toate detaliile), patru pe ecran,
 * șase pe ecran. Numele sunt cele scrise în Setări (migrarea 028).
 */
function catalog_vederi(): array
{
    return ['unu', 'patru', 'sase'];
}

/**
 * Ce a ales patronul în Setări pentru catalog, gata de citit de pagini.
 *
 * Vederea cu care se deschide magazinul — deosebită pe telefon și pe calculator
 * — și pragurile cu care se precompletează un produs nou în Produse.
 *
 * Citirea se apără ca `hq_coord()`: în bază poate sta orice a scris cineva cu
 * mâna. O vedere necunoscută cade pe cea de pornire; o pereche de praguri
 * strâmbă (C sub B) se dă înapoi fără C, fiindcă un formular precompletat cu o
 * pereche pe care serverul o refuză ar fi o capcană. Un prag gol rămâne gol:
 * patronul poate hotărî să nu precompleteze nimic.
 *
 * @return array{vedereTelefon: string, vedereCalculator: string, pragB: ?int, pragC: ?int}
 */
function catalog_implicite(): array
{
    $vedere = static function (string $cheie, string $implicit): string {
        $v = trim(setting($cheie));
        return in_array($v, catalog_vederi(), true) ? $v : $implicit;
    };
    $prag = static function (string $cheie): ?int {
        $v = trim(setting($cheie));
        return preg_match('/^\d+$/', $v) && (int) $v >= 2 && (int) $v <= 10000 ? (int) $v : null;
    };
    $b = $prag('pret_prag_b_implicit');
    $c = $prag('pret_prag_c_implicit');
    if ($c !== null && ($b === null || $c <= $b)) {
        $c = null;
    }
    return [
        'vedereTelefon'    => $vedere('catalog_vedere_telefon', 'unu'),
        'vedereCalculator' => $vedere('catalog_vedere_calculator', 'patru'),
        'pragB'            => $b,
        'pragC'            => $c,
    ];
}

/** Un număr din bază, sau null. DECIMAL vine ca text, INT ca întreg. */
function catalog_numar_sau_null($v, bool $intreg = false)
{
    if ($v === null || $v === '') {
        return null;
    }
    return $intreg ? (int) $v : (float) $v;
}

/* ------------------------------------------ numărul din colțul pozei -- */

/**
 * Numărul cel mai mare pe care îl poate primi un produs. Trei cifre încap în
 * colțul pozei fără să-l lățească; iar un catalog cu o mie de produse n-ar
 * mai fi o vitrină, ci un depozit.
 */
const CATALOG_NUMAR_MAX = 999;

/**
 * Numerele se pot alege de mână, adică a intrat migrarea 029?
 *
 * Poarta e SEMNUL scris de migrare, nu o coloană: `products.sort` e acolo din
 * `001_schema.sql`, dar ține pași de zece (0, 10, 20…) până o renumerotează
 * 029. Fără semn, numărul din colț rămâne cel de ieri — cheia bazei — și
 * Catalogul nu lasă numerele să fie mutate: o mutare peste pași de zece ar
 * fi scris 7 între 60 și 70, iar vitrina ar fi arătat „07" după „06" și
 * înaintea lui „60", adică exact dezordinea pe care o repară runda.
 *
 * „Nu" nu oprește nimic: e starea de ieri. Aceeași formă ca
 * `catalog_trepte_supported()`, ținută minte pe cererea asta.
 */
function catalog_numere_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $semn = (int) scalar(
            "SELECT COUNT(*) FROM settings WHERE setting_key = 'migration_029_numere_catalog'"
        );
        return $ok = ($semn > 0);
    } catch (Throwable $e) {
        return $ok = false;
    }
}

/**
 * Numărul trimis de panou, citit: un întreg de la 1 la 999, sau null.
 *
 * Primește și text („07" e chiar ce vede omul pe card), cu spații în jur, dar
 * nimic altceva: nici „7,5", nici „+7", nici un număr cu virgulă venit din
 * JSON. Oglinda lui e `gp.numarCatalog()` din shared/gp-api.js, legată de
 * asta de `.claude/test-numere-oglinda.mjs` pe același tabel de cazuri.
 */
function catalog_numar_din($v): ?int
{
    if (is_int($v)) {
        $n = $v;
    } elseif (is_string($v) && preg_match('/^[ \t\r\n]*(\d{1,6})[ \t\r\n]*$/D', $v, $m)) {
        $n = (int) $m[1];
    } else {
        return null;
    }
    return $n >= 1 && $n <= CATALOG_NUMAR_MAX ? $n : null;
}

/** Numărul așa cum stă în colțul pozei: cu zero în față până la 9. */
function catalog_numar_afisat(int $n): string
{
    return $n < 10 ? '0' . $n : (string) $n;
}

/**
 * Cine se mută, și unde, când produsul `$id` primește numărul `$tinta`.
 *
 * `$numere` ține numărul de azi al FIECĂRUI produs (id → număr), și al celor
 * scoase din catalog: își păstrează locul, ca să se întoarcă tot acolo.
 * Produsul mutat poate lipsi din el — e unul nou, încă fără număr.
 *
 * Întoarce id → numărul nou, numai pentru cele care se schimbă (produsul
 * mutat inclus); [] dacă nu se schimbă nimic; null dacă nu încape (toate
 * numerele de la țintă până la capăt, în amândouă părțile, ocupate).
 *
 * REGULA: se mută cât mai puține produse, și niciunul cu mai mult de un loc.
 *   - numărul e liber: se mută numai produsul;
 *   - e ocupat, iar produsul vine de mai încolo (sau e nou): cei de la țintă
 *     încolo se dau cu un loc mai încolo, până la primul număr liber;
 *   - e ocupat, iar produsul pleacă spre capăt: cei de la țintă înapoi se dau
 *     cu un loc în față, până la primul număr liber — cel mai târziu, locul
 *     lăsat chiar de produs.
 * Cu numere fără goluri, e exact mutarea prin tragere dintr-o listă: 19 → 7
 * dă 7…18 cu un loc mai încolo (8…19); 20 → 22 aduce 21 și 22 înapoi (20,
 * 21). Un gol oprește împingerea: cine stă după el nu se mișcă.
 *
 * Două produse cu același număr — scrise cu mâna în bază — se mută împreună.
 * Oglinda: `gp.mutariNumar()` din shared/gp-api.js, cu care panoul arată
 * dinainte cine se va muta; aceeași probă le leagă.
 */
function catalog_numar_mutari(array $numere, int $id, int $tinta): ?array
{
    $de = array_key_exists($id, $numere) ? (int) $numere[$id] : null;
    if ($de === $tinta) {
        return [];
    }
    $ocupat = [];
    foreach ($numere as $alt => $n) {
        if ((int) $alt !== $id) {
            $ocupat[(int) $n][] = (int) $alt;
        }
    }
    $mutari = [$id => $tinta];
    if (!isset($ocupat[$tinta])) {
        return $mutari;
    }

    // Lanțul de numere ocupate de la țintă într-o parte, până la primul liber;
    // null dacă vreunul dintre ele ar ieși din 1…999.
    $lant = static function (int $pas) use ($ocupat, $tinta): ?array {
        $l = [];
        for ($n = $tinta; isset($ocupat[$n]); $n += $pas) {
            if ($n + $pas < 1 || $n + $pas > CATALOG_NUMAR_MAX) {
                return null;
            }
            $l[] = $n;
        }
        return $l;
    };
    $pas = ($de === null || $tinta < $de) ? 1 : -1;
    $l   = $lant($pas);
    if ($l === null) {
        $pas = -$pas;
        $l   = $lant($pas);
    }
    if ($l === null) {
        return null;
    }
    foreach ($l as $n) {
        foreach ($ocupat[$n] as $alt) {
            $mutari[$alt] = $n + $pas;
        }
    }
    return $mutari;
}

/**
 * Rândul de jurnal al unei mutări, citit de un om: „număr în catalog: 19 → 07
 * · 12 produse mutate cu un loc (07–18 → 08–19)". Fără el, jurnalul ar fi
 * spus că s-a schimbat UN produs, iar vitrina ar fi arătat treisprezece.
 */
function catalog_numar_poveste(array $numere, int $id, array $mutari): string
{
    $de    = array_key_exists($id, $numere) ? (int) $numere[$id] : null;
    $text  = 'număr în catalog: ' . ($de === null ? '' : catalog_numar_afisat($de) . ' → ')
           . catalog_numar_afisat((int) $mutari[$id]);
    $altii = $mutari;
    unset($altii[$id]);
    if (!$altii) {
        return $text;
    }
    $vechi = [];
    foreach (array_keys($altii) as $alt) {
        $vechi[] = (int) $numere[$alt];
    }
    $interval = static function (array $l): string {
        return min($l) === max($l)
            ? catalog_numar_afisat(min($l))
            : catalog_numar_afisat(min($l)) . '–' . catalog_numar_afisat(max($l));
    };
    $n = count($altii);
    return $text . ' · ' . ($n === 1 ? 'un produs mutat' : $n . ' ' . ro_de($n) . 'produse mutate')
        . ' cu un loc (' . $interval($vechi) . ' → ' . $interval(array_values($altii)) . ')';
}

function product_public(array $r): array
{
    return [
        'id'            => (int) $r['id'],
        'slug'          => $r['slug'],
        'name'          => $r['name'],
        'nameEn'        => $r['name_en'],
        'description'   => $r['description'],
        'descriptionEn' => $r['description_en'],
        'photo'         => $r['photo'],
        'grams'         => $r['grams'] === null ? null : (int) $r['grams'],
        'priceFrozen'   => $r['price_frozen'] === null ? null : (float) $r['price_frozen'],
        'priceBaked'   => $r['price_baked'] === null ? null : (float) $r['price_baked'],
        'category'      => $r['category'],
        'isNew'         => (bool) $r['is_new'],
        'active'        => !isset($r['active']) || (bool) $r['active'],
        'sort'          => (int) ($r['sort'] ?? 0),
        // NUMĂRUL DIN COLȚUL POZEI (migrarea 029): cel ales în Catalog, adică
        // chiar `sort`. Fără 029, cel de ieri — cheia bazei —, ca vitrina să
        // nu arate pașii de zece (00, 10, 20…) în fereastra dintre fișiere și
        // import.
        'numar'         => catalog_numere_supported() ? (int) ($r['sort'] ?? 0) : (int) $r['id'],

        // APARTENENȚA ȘI POZELE, în două feluri deodată, și amândouă sunt
        // folosite: cele CRUDE sunt ce editează panoul (două bife și două
        // casete de fișier), iar `photoFor` e ce ARATĂ magazinul — poza
        // fiecărei laturi, și atât. De la 21 septembrie 2026 cele două nu mai
        // sunt deosebite de nicio cădere (`latura_poza()` nu mai împrumută
        // poza celeilalte laturi), dar rămân două câmpuri deosebite: „crud"
        // poate fi null acolo unde „de arătat" e tot null, iar magazinul n-are
        // ce face cu apartenența, nici panoul cu forma rezolvată.
        //
        // Pe o bază de dinainte de 024 coloanele lipsesc din rând, iar
        // `latura_in()` răspunde purtarea de până azi. Vezi api/lib/laturi.php.
        'photoBaked'  => $r['photo_baked'] ?? null,
        'inFrozen'    => latura_in($r, 'congelat'),
        'inBaked'     => latura_in($r, 'copt'),
        'photoFor'    => [
            'frozen' => latura_poza($r, 'congelat'),
            'baked'  => latura_poza($r, 'copt'),
        ],

        // CELE TREI PREȚURI, tot în două feluri, din același motiv ca pozele:
        // cele CRUDE (pragurile și prețurile B, C) sunt ce editează panoul, iar
        // `trepte` e scara care CHIAR se aplică — aceeași pe care o folosește
        // tarifarea, din `latura_scara()`. Magazinul citește numai scara, deci
        // nu poate arăta un B pe care serverul nu-l dă.
        //
        // Pe o bază fără 028 câmpurile crude sunt null, iar scara are numai A.
        'pragB'        => catalog_numar_sau_null($r['prag_b'] ?? null, true),
        'pragC'        => catalog_numar_sau_null($r['prag_c'] ?? null, true),
        'priceFrozenB' => catalog_numar_sau_null($r['price_frozen_b'] ?? null),
        'priceFrozenC' => catalog_numar_sau_null($r['price_frozen_c'] ?? null),
        'priceBakedB'  => catalog_numar_sau_null($r['price_baked_b'] ?? null),
        'priceBakedC'  => catalog_numar_sau_null($r['price_baked_c'] ?? null),
        'trepte'       => [
            'frozen' => latura_scara_publica($r, 'congelat'),
            'baked'  => latura_scara_publica($r, 'copt'),
        ],

        // FIȘA TEHNICĂ (migrarea 031), tot în două feluri. Cele CRUDE — `sheet`
        // și `sheetBaked` — sunt ce editează panoul: fișa pusă chiar pe latura
        // aceea, fără împrumut, ca panoul să poată spune „Coapte n-are fișa
        // ei". `sheetFor` e ce OFERĂ magazinul: la Coapte, în lipsa unei fișe
        // proprii, pe cea congelată. Împrumutul se face într-un singur loc,
        // `latura_fisa()` din api/lib/laturi.php — magazinul citește numai
        // `sheetFor`, deci nu-l poate face a doua oară, altfel.
        //
        // Pe o bază fără 031 coloanele lipsesc din rând și toate patru sunt
        // null: niciun buton, ca ieri.
        'sheet'      => latura_fisa_proprie($r, 'congelat'),
        'sheetBaked' => latura_fisa_proprie($r, 'copt'),
        'sheetFor'   => [
            'frozen' => latura_fisa($r, 'congelat'),
            'baked'  => latura_fisa($r, 'copt'),
        ],
    ];
}

function category_public(array $r): array
{
    // O singură interogare, două cifre: „toate" numără și produsele scoase din
    // catalog, „in_catalog" doar pe cele care apar pe site.
    $n = one(
        'SELECT COUNT(*) AS toate, SUM(active = 1) AS in_catalog
           FROM products WHERE category = ?',
        [$r['slug']]
    );

    return [
        'id'     => (int) $r['id'],
        'slug'   => $r['slug'],
        'name'   => $r['name'],
        'nameEn' => $r['name_en'],
        'note'   => $r['note'],
        'noteEn' => $r['note_en'],
        'sort'   => (int) $r['sort'],
        'active' => (bool) $r['active'],
        // Câte produse are grupa — dar cifra are două înțelesuri, iar panoul
        // are nevoie de amândouă. `products` sunt cele din catalog (atâtea se
        // văd pe site și atâtea opresc ascunderea grupei), `productsAll` le
        // numără și pe cele scoase din catalog — exact numărul după care se
        // refuză ȘTERGEREA grupei. Fără al doilea, fila Grupe scria „0 produse"
        // pentru o grupă din care produsele fuseseră doar scoase din catalog,
        // iar la × serverul răspundea „grupa are 3 produse în ea": adminul
        // vedea două cifre care se contrazic pe același ecran și nu avea de
        // unde ști care e adevărată.
        'products'    => (int) ($n['in_catalog'] ?? 0),
        'productsAll' => (int) ($n['toate'] ?? 0),
    ];
}
