<?php
/**
 * Cele două laturi ale catalogului: congelat și gata de servire.
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
 *   `photo_baked`  acoperirea pentru gata de servire; NULL = „cade pe `photo`"
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

/** Numele laturii, ca titlu — exact vorbele de pe site. */
function latura_nume(string $latura, bool $ro = true): string
{
    if ($latura === 'copt') {
        return $ro ? 'Gata de servire' : 'Ready to serve';
    }
    return $ro ? 'Congelate' : 'Frozen';
}

/**
 * Numele laturii, ca vorbă în propoziție: „nu se mai vinde congelat".
 *
 * Aici moare cuvântul „copt" pentru ochii omului. El rămâne în bază și pe
 * sârmă, dar niciun ecran și niciun mesaj de eroare nu-l mai arată: „copt" e
 * vorbă de laborator, iar panoul și magazinul sunt ale biroului și ale
 * clientului.
 */
function latura_cuvant(string $latura, bool $ro = true): string
{
    if ($latura === 'copt') {
        return $ro ? 'gata de servire' : 'ready to serve';
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
 * Poza de arătat pe latura asta.
 *
 * Hotărârea patronului: o latură fără poză a ei arată poza celeilalte. Un
 * produs n-are voie să apară gol pe site în ziua dintre cele două urcări.
 */
function latura_poza(array $r, string $latura): ?string
{
    $aLui = latura_text($r[latura_coloana_poza($latura)] ?? null);
    if ($aLui !== null) {
        return $aLui;
    }
    $cealalta = $latura === 'copt' ? 'congelat' : 'copt';
    return latura_text($r[latura_coloana_poza($cealalta)] ?? null);
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
 * din afara catalogului de congelate e văzut întâi la gata de servire, fiindcă
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
