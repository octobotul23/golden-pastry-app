<?php
/**
 * Cele două laturi ale catalogului: congelate și coapte.
 *
 * DE CE EXISTĂ FIȘIERUL ĂSTA. Începând cu migrarea 024, un produs poate fi
 * într-un catalog și nu în celălalt, și poate avea o poză a fiecărei laturi.
 * Socoteala „ce preț și ce poză arăt pe latura asta" se cere în patru locuri
 * care nu se văd între ele — pagina magazinului, panoul, ruta de catalog și
 * fișierele de SEO — iar ultimul dintre ele nu are voie să depindă de nimic:
 * `seo/index.php` își face singur legătura la bază și se servește mai departe
 * chiar dacă bucăți din `api/lib/` lipsesc.
 *
 * De-aia aici NU se atinge baza de date și nu se cheamă nicio funcție din altă
 * parte. Fiecare funcție primește un rând deja citit și întoarce un răspuns.
 * Sonda care întreabă baza dacă știe de laturi stă aparte, în
 * `api/lib/catalog.php` — acolo unde există `scalar()`.
 *
 * ÎNȚELESUL COLOANELOR, fiindcă numele nu-l spune singur:
 *
 *   `photo`        latura CONGELATĂ, și poza de bază a amândurora
 *   `photo_baked`  acoperirea pentru latura coaptă; NULL = „cade pe `photo`"
 *
 * Numele `photo` a rămas ce era dinadins — v. migrarea 024, „DE CE `photo` NU
 * SE REDENUMEȘTE".
 *
 * PURTAREA PE O BAZĂ DE DINAINTE DE 024. Fiecare funcție de aici lucrează și
 * peste un rând fără coloanele noi, și atunci răspunde exact ce răspundea
 * magazinul până azi: un produs se vede pe amândouă laturile dacă are amândouă
 * prețurile. Asta nu e o politețe — e singurul lucru corect în fereastra dintre
 * urcarea fișierelor pe server și importul SQL, când codul nou rulează peste
 * baza veche.
 */

declare(strict_types=1);

/**
 * Vorbele laturii, așa cum se scriu pe sârmă.
 *
 * Sunt CHIAR cele din `order_variants()` (api/lib/orders.php), și trebuie să
 * rămână: stau scrise în `order_items.variant` pe fiecare rând de comandă din
 * trecut. Redenumirea cerută de patron e numai de AFIȘAT — v. `latura_cuvant()`.
 */
function laturi(): array
{
    return ['congelat', 'copt'];
}

/**
 * Latura primită de la client, adusă la una din cele două.
 *
 * Ce nu se potrivește devine `$implicit`. Implicitul e „congelat" peste tot
 * unde se cere unul, fiindcă aceea e latura cu care pornește magazinul.
 */
function latura_normalizeaza($v, string $implicit = 'congelat'): string
{
    $s = is_scalar($v) ? strtolower(trim((string) $v)) : '';
    return in_array($s, laturi(), true) ? $s : $implicit;
}

/** Numele laturii, ca titlu — exact vorbele de pe site: „Congelate", „Coapte". */
function latura_nume(string $latura, bool $ro = true): string
{
    if ($latura === 'copt') {
        return $ro ? 'Coapte' : 'Baked';
    }
    return $ro ? 'Congelate' : 'Frozen';
}

/**
 * Numele laturii, ca vorbă în propoziție: „nu se mai vinde congelat", „nu se
 * mai vinde copt".
 *
 * Până pe 26 septembrie 2026 latura caldă se chema pe ecran „gata de servire",
 * iar „copt" nu ieșea din bază și de pe sârmă: era socotit vorbă de laborator.
 * Atunci patronul a ales „Coapte", după pozele „acum / coapte". Titlul e la
 * plural, ca „Congelate"; în propoziție vorba se acordă cu produsul, ca
 * „congelat" — deci „copt". Cheia de sârmă rămâne `copt`, neatinsă: stă scrisă
 * în fiecare rând de comandă din trecut.
 */
function latura_cuvant(string $latura, bool $ro = true): string
{
    if ($latura === 'copt') {
        return $ro ? 'copt' : 'baked';
    }
    return $ro ? 'congelat' : 'frozen';
}

/** Coloana de preț a laturii. */
function latura_coloana_pret(string $latura): string
{
    return $latura === 'copt' ? 'price_baked' : 'price_frozen';
}

/** Coloana de apartenență a laturii. */
function latura_coloana_in(string $latura): string
{
    return $latura === 'copt' ? 'in_baked' : 'in_frozen';
}

/** Coloana de poză a laturii — cea PROPRIE, fără căderea pe cealaltă. */
function latura_coloana_poza(string $latura): string
{
    return $latura === 'copt' ? 'photo_baked' : 'photo';
}

/** Un text care nu e nici NULL, nici gol. Altfel: null. */
function latura_text($v): ?string
{
    $s = is_scalar($v) ? trim((string) $v) : '';
    return $s === '' ? null : $s;
}

/** Prețul laturii, sau null dacă n-are. */
function latura_pret(array $r, string $latura): ?float
{
    $v = $r[latura_coloana_pret($latura)] ?? null;
    return $v === null || $v === '' ? null : (float) $v;
}

/**
 * E produsul în catalogul laturii ăsteia?
 *
 * Pe un rând de dinainte de 024 nu există coloana, iar răspunsul e purtarea de
 * până azi: da, dacă are amândouă prețurile.
 */
function latura_in(array $r, string $latura): bool
{
    $col = latura_coloana_in($latura);
    if (!array_key_exists($col, $r)) {
        return latura_pret($r, 'congelat') !== null && latura_pret($r, 'copt') !== null;
    }
    return (bool) $r[$col];
}

/**
 * Poza de arătat pe latura asta — numai a EI.
 *
 * Hotărârea patronului, 21 septembrie 2026. Până atunci o latură fără poză o
 * împrumuta pe a celeilalte, ca produsul să nu apară gol pe site în ziua
 * dintre cele două urcări. Purtarea aia nu se poate însă deosebi de un defect,
 * și chiar așa s-a și citit în prima zi de folosință: schimbi poza la
 * congelate și se schimbă și la gata de servire — fiindcă latura cealaltă
 * n-avea încă una a ei, nu fiindcă ar fi scris cineva în două coloane.
 *
 * Ce se împrumuta până acum s-a făcut al fiecărei laturi o dată pentru
 * totdeauna, cu `sql/027_poza_fiecarei_laturi.sql`, deci tăierea căderii nu
 * golește nicio vitrină: în ziua schimbării, amândouă laturile arată exact ce
 * arătau.
 *
 * De-acum gol înseamnă gol: magazinul pune substituentul „fără poză", nu poza
 * celeilalte laturi.
 */
function latura_poza(array $r, string $latura): ?string
{
    return latura_text($r[latura_coloana_poza($latura)] ?? null);
}

/**
 * Se vinde produsul pe latura asta?
 *
 * INVARIANTUL spune că `in_X = 1` cere `price_X IS NOT NULL`, deci în teorie
 * prima jumătate ar fi de ajuns. Se cer amândouă fiindcă invariantul e păzit de
 * server, nu de bază: un import făcut cu mâna în phpMyAdmin poate scrie orice,
 * iar un produs „în catalog, fără preț" n-are voie să ajungă pe site ca preț
 * zero sau ca „preț la cerere".
 */
function latura_se_vinde(array $r, string $latura): bool
{
    return latura_in($r, $latura) && latura_pret($r, $latura) !== null;
}

/** Se vinde undeva? Dacă nu, produsul nu are ce căuta pe site. */
function latura_vandabil(array $r): bool
{
    return latura_se_vinde($r, 'congelat') || latura_se_vinde($r, 'copt');
}

/**
 * Latura pe care o vede întâi cel care ajunge pe produsul ăsta.
 *
 * Magazinul pornește pe „congelate" (Site Public.dc.html), deci aceea e latura
 * vitrinei — DAR numai pentru un produs care chiar se vinde acolo. Un produs
 * din afara catalogului de congelate e văzut întâi la coapte, fiindcă
 * acolo e singurul loc unde e.
 *
 * De asta atârnă prețul și poza din datele pentru Google: perechea trebuie să
 * fie a ACELEIAȘI laturi, altfel rezultatul din căutare arată poza unei laturi
 * cu prețul celeilalte.
 */
function latura_vitrina(array $r): string
{
    return latura_se_vinde($r, 'congelat') ? 'congelat' : 'copt';
}

/** Prețul de arătat pe vitrină, sau null dacă produsul nu se vinde nicăieri. */
function latura_pret_vitrina(array $r): ?float
{
    return latura_vandabil($r) ? latura_pret($r, latura_vitrina($r)) : null;
}

/** Poza de arătat pe vitrină — aceeași latură ca prețul, întotdeauna. */
function latura_poza_vitrina(array $r): ?string
{
    return latura_poza($r, latura_vitrina($r));
}

/* ------------------------------------------- trei prețuri, după cantitate -- */

/*
 * CEREREA PATRONULUI, 24 septembrie 2026: până la trei prețuri pe fiecare
 * latură — A sub primul prag, B de la el, C de la al doilea. Pragurile sunt ale
 * produsului, o singură pereche pentru amândouă laturile; prețurile sunt ale
 * fiecărei laturi (migrarea 028).
 *
 * Funcțiile de aici sunt SINGURUL loc care hotărăște ce trepte are un produs.
 * Banii (`price_items()`), catalogul trimis magazinului (`product_public()`) și
 * pagina scrisă pe server pentru motoarele de căutare le cheamă pe ele, ca să
 * nu poată spune trei lucruri deosebite despre același produs. De-aia stau în
 * fișierul ăsta, care nu atinge baza: `seo/index.php` îl încarcă singur.
 *
 * O TREAPTĂ SE APLICĂ NUMAI DACĂ E ÎNTREAGĂ ȘI MAI IEFTINĂ. Panoul nu lasă să
 * se salveze altfel (`product_trepte_verifica()` din api/routes/catalog.php),
 * dar baza se poate scrie și cu mâna, din phpMyAdmin. Un B mai scump decât A
 * ar însemna că clientul care cumpără mai mult plătește mai mult pe bucată —
 * deci o treaptă strâmbă se sare, nu se aplică. Și cu ea se sar cele de
 * deasupra: C are sens numai peste un B.
 *
 * Pe un rând fără coloanele lui 028 (baza nemigrată) scara are o singură
 * treaptă, A — adică exact prețul unic de ieri.
 */

/** Coloana prețului unei trepte: A e coloana de azi, B și C cele din 028. */
function latura_coloana_treapta(string $latura, int $treapta): string
{
    $baza = latura_coloana_pret($latura);
    if ($treapta === 2) {
        return $baza . '_b';
    }
    return $treapta === 3 ? $baza . '_c' : $baza;
}

/**
 * Scara de prețuri a laturii: treptele care CHIAR se aplică, de jos în sus.
 *
 * A are pragul 1 — „de la o bucată" —, ca pagina să poată scrie „1–99" fără
 * să mai socotească nimic. La alegerea treptei A nu se compară cu nimic: orice
 * cantitate primește măcar A (v. `latura_treapta()`).
 *
 * @return array<int, array{treapta: int, prag: int, pret: float}>  [] = latura n-are preț
 */
function latura_scara(array $r, string $latura): array
{
    $a = latura_pret($r, $latura);
    if ($a === null) {
        return [];
    }
    $scara = [['treapta' => 1, 'prag' => 1, 'pret' => $a]];
    $prag  = 1;
    $pret  = $a;
    foreach ([2 => 'prag_b', 3 => 'prag_c'] as $t => $colPrag) {
        $p = $r[$colPrag] ?? null;
        $v = $r[latura_coloana_treapta($latura, $t)] ?? null;
        if ($p === null || $p === '' || $v === null || $v === ''
            || !is_numeric($p) || !is_numeric($v)) {
            break;
        }
        $p = (int) $p;
        $v = round((float) $v, 2);
        // Pragul trebuie să urce, prețul să coboare. A stă pe pragul 1, deci
        // „să urce" refuză și pragul 1 — care ar face din B prețul oricui,
        // adică un A ascuns.
        if ($p <= $prag || $v <= 0 || $v >= $pret) {
            break;
        }
        $scara[] = ['treapta' => $t, 'prag' => $p, 'pret' => $v];
        $prag = $p;
        $pret = $v;
    }
    return $scara;
}

/**
 * Treapta în care cade o cantitate: cea mai înaltă al cărei prag e atins.
 *
 * `prag` e null la A — treapta A nu e „câștigată" de nicio cantitate, deci n-are
 * ce pierde la o corectură. Așa se scrie și pe rândul de comandă
 * (`order_items.prag_treapta`).
 *
 * Cantitatea e cea din tot coșul, pe perechea produs+latură, nu a unui singur
 * rând: v. `cantitati_pe_pereche()` în api/lib/orders.php.
 *
 * @return array{treapta: int, prag: ?int, pret: float}|null  null = latura n-are preț
 */
function latura_treapta(array $r, string $latura, float $q): ?array
{
    $scara = latura_scara($r, $latura);
    if (!$scara) {
        return null;
    }
    $ales = $scara[0];
    foreach (array_slice($scara, 1) as $t) {
        if ($q >= $t['prag']) {
            $ales = $t;
        }
    }
    return [
        'treapta' => $ales['treapta'],
        'prag'    => $ales['treapta'] === 1 ? null : $ales['prag'],
        'pret'    => $ales['pret'],
    ];
}

/**
 * Scara, în forma în care o citesc paginile: `[{prag, pret}, …]`.
 *
 * Lista e goală pe o latură fără preț. O latură cu un singur preț are o singură
 * treaptă — magazinul arată atunci prețul ca până azi, fără scară.
 *
 * @return array<int, array{prag: int, pret: float}>
 */
function latura_scara_publica(array $r, string $latura): array
{
    return array_map(
        static fn(array $t): array => ['prag' => $t['prag'], 'pret' => $t['pret']],
        latura_scara($r, $latura)
    );
}

/**
 * Scara laturii în forma în care o citește Google: câte un
 * `UnitPriceSpecification` pe treaptă, cu cantitatea care o primește
 * (`eligibleQuantity`, numărată în bucăți — codul UN/CEFACT „C62").
 *
 * Null pe o latură cu un singur preț. Acolo `offers.price` spune tot, iar
 * marcajul rămâne literă cu literă cel de dinainte de trepte. Oricum,
 * `offers.price` rămâne prețul A: cifra pe care o vede omul când deschide
 * pagina, cu coșul gol (v. `$produsLd` în seo/index.php).
 *
 * Intervalele sunt cele de pe card: A „1–99", B „100–299", C „300+". Treapta
 * de sus n-are `maxValue`, fiindcă n-are capăt. Scara vine din `latura_scara()`,
 * deci o treaptă strâmbă (B peste A, prag care nu urcă) nu ajunge nici aici.
 *
 * @return array<int, array<string, mixed>>|null
 */
function latura_scara_ld(array $r, string $latura): ?array
{
    $scara = latura_scara($r, $latura);
    if (count($scara) < 2) {
        return null;
    }
    $ld = [];
    foreach ($scara as $i => $t) {
        $cate = ['@type' => 'QuantitativeValue', 'minValue' => $t['prag']];
        if (isset($scara[$i + 1])) {
            $cate['maxValue'] = $scara[$i + 1]['prag'] - 1;
        }
        $cate['unitCode'] = 'C62';
        $ld[] = [
            '@type'            => 'UnitPriceSpecification',
            'price'            => number_format($t['pret'], 2, '.', ''),
            'priceCurrency'    => 'RON',
            'eligibleQuantity' => $cate,
        ];
    }
    return $ld;
}
