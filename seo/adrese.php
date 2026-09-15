<?php
/**
 * Harta adreselor publice — un singur loc care spune ce adrese există.
 *
 * De ce există fișierul acesta
 * ────────────────────────────
 * Magazinul era o singură adresă. „Catalog", „Distribuție", „Despre" și
 * „Contact" erau stări comutate în browser în același document: apăsai pe
 * meniu, se schimba ce vezi, dar adresa din bară rămânea „/". Pentru un motor
 * de căutare asta înseamnă un site cu o singură pagină — o singură intrare în
 * index, un singur titlu, o singură descriere. Nu se poate ieși în căutări
 * după „catalog patiserie congelată" cu o pagină care se numește, oficial,
 * „Golden Pastry".
 *
 * Fișierul acesta e tabelul din care se citește adresa în trei locuri deodată:
 *
 *   · `seo/index.php`  — ce pagină servește și cu ce titlu, descriere și date
 *                        structurate;
 *   · `seo/sitemap.php` — ce adrese anunță harta trimisă motoarelor;
 *   · `Site Public.dc.html` — pe unde navighează aplicația, prin tabelul pe
 *                        care `index.php` i-l pune în pagină ca `GP_ADRESE`.
 *
 * Un singur tabel, trei cititori: o adresă nouă se scrie o dată. Dacă ar fi
 * fost scris în trei locuri, primul care s-ar fi schimbat ar fi rupt harta sau
 * meniul, tăcut.
 *
 * Perechea româno-engleză
 * ───────────────────────
 * Pagina are de la început text în ambele limbi, comutat cu butoanele RO/EN.
 * Amândouă stăteau la aceeași adresă, deci Google primea un singur document cu
 * două limbi în el — nu putea arăta niciuna la locul ei. Aici fiecare stare
 * are două adrese, iar cea englezească stă sub „/en/". Legătura dintre ele se
 * anunță cu `hreflang`, din `index.php`.
 */

declare(strict_types=1);

/**
 * Stările paginii publice și adresa fiecăreia.
 *
 * Cheia este numele stării din „Site Public.dc.html" (`this.state.page`) —
 * nu-l schimba fără să schimbi și pagina, altfel meniul trimite spre o adresă
 * pe care serverul o servește, dar aplicația n-o recunoaște, și vizitatorul
 * vede antetul deasupra unei pagini goale.
 *
 * Coloane: [cale românească, cale engleză, prioritate în hartă, cât de des se
 * schimbă].
 */
const GP_PAGINI = [
    'home'         => ['',            '',             '1.0', 'weekly'],
    'catalog'      => ['catalog',     'catalogue',    '0.9', 'weekly'],
    'distribution' => ['distributie', 'distribution', '0.7', 'monthly'],
    'about'        => ['despre',      'about',        '0.6', 'monthly'],
    'contact'      => ['contact',     'contact',      '0.8', 'monthly'],
];

/** Prefixul sub care stă toată versiunea englezească. */
const GP_PREFIX_EN = 'en';

/**
 * Adresele cu o cheie în ele: o grupă din catalog, un produs.
 *
 * `stare` spune ce vede omul pe ecran când ajunge acolo — o grupă deschide
 * catalogul filtrat, un produs deschide fișa lui.
 */
const GP_CU_CHEIE = [
    'group'   => ['stare' => 'catalog', 'ro' => 'catalog', 'en' => 'catalogue', 'prio' => '0.8', 'freq' => 'weekly'],
    'product' => ['stare' => 'product', 'ro' => 'produs',  'en' => 'product',   'prio' => '0.7', 'freq' => 'weekly'],
];

/**
 * Ce limbă și ce pagină cere adresa asta?
 *
 * Întoarce `null` pentru o adresă care nu există — și atunci cel care întreabă
 * TREBUIE să răspundă 404. O pagină necunoscută servită cu 200 („soft 404")
 * e mai rea decât un 404 curat: motorul o indexează, o compară cu pagina de
 * start, o găsește identică, și începe să aibă îndoieli despre amândouă.
 *
 * @return array{pagina:string,lang:string,fel:string,cheie:string}|null
 */
function gp_ruta(string $uri): ?array
{
    // Numai calea ne interesează: șirul de interogare (?ref=…, ?utm_source=…)
    // nu schimbă ce pagină e cerută, dar ar strica potrivirea de mai jos.
    $cale = (string) parse_url($uri, PHP_URL_PATH);
    $cale = rawurldecode($cale);
    $cale = trim($cale, '/');

    $lang = 'ro';
    if ($cale === GP_PREFIX_EN || str_starts_with($cale, GP_PREFIX_EN . '/')) {
        $lang = 'en';
        $cale = trim(substr($cale, strlen(GP_PREFIX_EN)), '/');
    }

    $bucati = $cale === '' ? [] : explode('/', $cale);

    if (count($bucati) === 0) {
        return ['pagina' => 'home', 'lang' => $lang, 'fel' => 'pagina', 'cheie' => ''];
    }

    if (count($bucati) === 1) {
        foreach (GP_PAGINI as $stare => $rand) {
            if ($rand[$lang === 'en' ? 1 : 0] === $bucati[0] && $bucati[0] !== '') {
                return ['pagina' => $stare, 'lang' => $lang, 'fel' => 'pagina', 'cheie' => ''];
            }
        }
        return null;
    }

    if (count($bucati) === 2) {
        foreach (GP_CU_CHEIE as $fel => $rand) {
            if ($rand[$lang === 'en' ? 'en' : 'ro'] === $bucati[0] && gp_cheie_valida($bucati[1])) {
                return ['pagina' => $rand['stare'], 'lang' => $lang, 'fel' => $fel, 'cheie' => $bucati[1]];
            }
        }
    }

    return null;
}

/**
 * Cheia dintr-o adresă e o cheie de produs sau de grupă?
 *
 * `slugify()` din API scoate doar litere mici, cifre și cratime, iar coloanele
 * au 64 și 96 de caractere. Verificarea de aici e poarta: fără ea, o cale
 * scrisă de mână ar ajunge nefiltrată în interogare și în pagină.
 */
function gp_cheie_valida(string $s): bool
{
    return $s !== '' && strlen($s) <= 96 && (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s);
}

/**
 * Calea unei pagini, pornind de la starea aplicației.
 *
 * Întoarce mereu o cale absolută, începută cu „/": adresa se scrie identic
 * indiferent de unde e citită. O cale relativă ar fi însemnat altceva pe
 * „/produs/ceva" decât pe „/catalog".
 */
function gp_cale(string $pagina, string $lang = 'ro', string $fel = 'pagina', string $cheie = ''): string
{
    $en  = $lang === 'en';
    $pre = $en ? '/' . GP_PREFIX_EN : '';

    if ($fel !== 'pagina' && isset(GP_CU_CHEIE[$fel]) && $cheie !== '') {
        return $pre . '/' . GP_CU_CHEIE[$fel][$en ? 'en' : 'ro'] . '/' . $cheie;
    }

    $rand = GP_PAGINI[$pagina] ?? GP_PAGINI['home'];
    $seg  = $rand[$en ? 1 : 0];

    return $seg === '' ? ($pre === '' ? '/' : $pre . '/') : $pre . '/' . $seg;
}

/**
 * Tabelul pe care îl citește aplicația din browser.
 *
 * Aplicația nu-și ține propria copie a hărții: și-ar fi luat-o razna față de
 * cea de aici la prima adresă adăugată. Îl primește de la server, în pagină.
 * Când lipsește — canvasul de proiectare, unde pagina se deschide fără PHP —
 * aplicația rămâne exact cum era înainte: o singură stare, fără adrese.
 */
function gp_adrese_pentru_pagina(): array
{
    $pagini = [];
    foreach (GP_PAGINI as $stare => $rand) {
        $pagini[$stare] = ['ro' => $rand[0], 'en' => $rand[1]];
    }

    $cuCheie = [];
    foreach (GP_CU_CHEIE as $fel => $rand) {
        $cuCheie[$fel] = ['stare' => $rand['stare'], 'ro' => $rand['ro'], 'en' => $rand['en']];
    }

    return ['prefixEn' => GP_PREFIX_EN, 'pagini' => $pagini, 'cuCheie' => $cuCheie];
}

/**
 * Toate adresele care răspund — pentru harta trimisă motoarelor de căutare.
 *
 * Ordinea contează pentru cine citește harta cu ochiul: întâi paginile, apoi
 * grupele, apoi produsele; româna înaintea englezei.
 *
 * @param  array<int,array<string,mixed>> $prods produse active
 * @param  array<int,array<string,mixed>> $cats  grupe active
 * @return array<int,array{cale:string,prio:string,freq:string,alt:array<string,string>}>
 */
function gp_adrese_toate(array $prods, array $cats): array
{
    $out = [];

    $adauga = static function (string $fel, string $cheie, string $stare, string $prio, string $freq) use (&$out): void {
        $ro = gp_cale($stare, 'ro', $fel, $cheie);
        $en = gp_cale($stare, 'en', $fel, $cheie);
        // Fiecare adresă își poartă perechea din cealaltă limbă: fără
        // „alternate", Google vede două pagini cu același conținut în două
        // limbi și alege singur una — de obicei greșit.
        $alt = ['ro' => $ro, 'en' => $en];
        $out[] = ['cale' => $ro, 'prio' => $prio, 'freq' => $freq, 'alt' => $alt];
        $out[] = ['cale' => $en, 'prio' => $prio, 'freq' => $freq, 'alt' => $alt];
    };

    foreach (GP_PAGINI as $stare => $rand) {
        $adauga('pagina', '', $stare, $rand[2], $rand[3]);
    }

    foreach ($cats as $c) {
        $cheie = (string) ($c['slug'] ?? '');
        if (gp_cheie_valida($cheie)) {
            $adauga('group', $cheie, GP_CU_CHEIE['group']['stare'], GP_CU_CHEIE['group']['prio'], GP_CU_CHEIE['group']['freq']);
        }
    }

    foreach ($prods as $p) {
        $cheie = (string) ($p['slug'] ?? '');
        if (gp_cheie_valida($cheie)) {
            $adauga('product', $cheie, GP_CU_CHEIE['product']['stare'], GP_CU_CHEIE['product']['prio'], GP_CU_CHEIE['product']['freq']);
        }
    }

    return $out;
}
