<?php
/**
 * Ce spune patiseria despre sine, în public: adresa și programul.
 *
 * UN SINGUR IZVOR. Cele două lucruri astea se arată azi în trei locuri
 * deosebite — datele structurate pentru Google (`seo/index.php`), pagina fără
 * JavaScript din același fișier, și magazinul (`Site Public.dc.html`) — iar
 * până la fișierul ăsta fiecare loc își ținea propria valoare, scrisă de mână.
 * Nu era o scăpare mică: site-ul chiar spunea DOUĂ programe deodată. Pagina pe
 * care o citea omul zicea „Luni–Vineri 07:00–21:00, Sâmbătă 07:00–17:00,
 * Duminică închis"; datele trimise lui Google, din `company_hours`, ziceau
 * „Luni–Sâmbătă 05:00–21:00". Nu forme deosebite ale aceluiași program: ore
 * deosebite și zile deosebite. Clientul care caută pe telefon „deschis acum"
 * afla una, iar cel care deschidea site-ul, alta — și unul din ei venea la ușă
 * închisă.
 *
 * Fișierul stă în `api/lib/` dintr-un motiv de așezare: `api/index.php` încarcă
 * UN SINGUR fișier de rută pe cerere (`require_once __DIR__ . '/routes/' .
 * $file`), deci orice unealtă folosită de mai multe rute trebuie să stea în
 * `lib`, altfel a doua rută care o cheamă o găsește nedefinită.
 *
 * `seo/index.php` rămâne un fișier care se servește singur, fără API: el cheamă
 * fișierul ăsta dacă îl găsește lângă el, și se lipsește de ore dacă nu — mai
 * bine un site fără date structurate decât un site căzut.
 */

declare(strict_types=1);

/**
 * Adresa arătată când caseta din Setări e goală.
 *
 * Stă aici, nu în trei fișiere, tocmai fiindcă asta e toată lucrarea. Caseta
 * golită din panou salvează șir GOL, nu null — de-aia verificările sunt peste
 * tot cu `?:`, nu cu `??`: cu `??` adresa ar dispărea din pagină în loc să se
 * întoarcă la cea de aici.
 */
const GP_FIRMA_ADRESA = 'Șos. Olteniței 103';

/**
 * Restul adresei.
 *
 * Sunt constante și rămân constante, exact ca până acum (erau scrise în
 * `seo/index.php`, la fel). Panoul Setări NU are casetă pentru ele, deci o
 * patiserie care s-ar muta din sectorul 4 ar trebui să le schimbe de aici. E o
 * margine cunoscută, nu o scăpare: `streetAddress`, `addressLocality` și
 * `addressRegion` din datele structurate cer cele trei bucăți DEOSEBITE, iar o
 * casetă unde omul scrie tot n-ar putea fi tăiată înapoi în trei fără să se
 * ghicească. Cine mută patiseria schimbă două rânduri aici.
 */
const GP_FIRMA_SECTOR = 'Sector 4';
const GP_FIRMA_ORAS   = 'București';

/**
 * Programul arătat când caseta din Setări e goală. Aceeași regulă ca la adresă.
 *
 * Valoarea e cea hotărâtă de patron la unificarea celor două fețe: până atunci
 * pagina arăta omului „Luni–Vineri 07:00–21:00, Sâmbătă 07:00–17:00, Duminică
 * Închis", iar Google afla „Luni–Sâmbătă 05:00–21:00". Adevărat e primul, deci
 * el a rămas — și în cod, și în sămânța din `sql/018`.
 */
const GP_FIRMA_PROGRAM = 'Lu-Vi 07:00-21:00, Sâ 07:00-17:00';

/**
 * Tot ce se arată în public despre firmă, dintr-o singură citire.
 *
 * Primește valorile BRUTE din `settings` — nu le caută singură — fiindcă cei
 * doi chemători le au deja, pe căi deosebite: `seo/index.php` își deschide
 * propriul PDO și le are într-un tablou, iar rutele API le iau cu `setting()`.
 * Dacă ar întreba ea baza, s-ar face o a doua interogare pe fiecare pagină.
 *
 * @param  string|null $adresa  `company_address`, așa cum stă în tabel
 * @param  string|null $program `company_hours`, așa cum stă în tabel
 * @return array{
 *   adresa: string, sector: string, oras: string, intreaga: string,
 *   intreagaEn: string, harta: string,
 *   program: array{spec: array<int, array<string, mixed>>, ro: string, en: string, zile: array<string, array{0: string, 1: string}|null>}
 * }
 */
function firma_publica(?string $adresa, ?string $program): array
{
    $strada = trim((string) $adresa) ?: GP_FIRMA_ADRESA;

    /*
     * Sectorul și orașul se lipesc la coadă NUMAI dacă nu sunt deja acolo.
     * Pilda scrisă în caseta din Setări e chiar „Șos. Olteniței 103, sector 4,
     * București" — deci omul care o urmează întocmai ar fi căpătat „…, sector
     * 4, București, sector 4, București", și în pagină, și în legătura spre
     * hartă. Căutarea e fără diacritice și fără majuscule, ca „Sector 4",
     * „sector 4" și „SECTOR 4" să numere toate la fel.
     */
    $fara = static fn(string $s): string => strtolower(strtr($s, [
        'ă' => 'a', 'Ă' => 'a', 'â' => 'a', 'Â' => 'a', 'î' => 'i', 'Î' => 'i',
        'ș' => 's', 'Ș' => 's', 'ş' => 's', 'Ş' => 's',
        'ț' => 't', 'Ț' => 't', 'ţ' => 't', 'Ţ' => 't',
    ]));
    $cauta = $fara($strada);
    $coada = [];
    foreach ([mb_strtolower(GP_FIRMA_SECTOR, 'UTF-8'), GP_FIRMA_ORAS] as $parte) {
        if (!str_contains($cauta, $fara($parte))) {
            $coada[] = $parte;
        }
    }
    $intreaga = implode(', ', array_merge([$strada], $coada));

    $coadaEn = [];
    foreach (['district 4', 'Bucharest'] as $parte) {
        // Se pleacă de la ce lipsea în românește: cine a scris „sector 4" în
        // casetă l-a scris o singură dată, iar englezescul „district 4" n-are
        // cum să fie acolo.
        if (!str_contains($cauta, $fara($parte === 'district 4' ? GP_FIRMA_SECTOR : GP_FIRMA_ORAS))) {
            $coadaEn[] = $parte;
        }
    }

    return [
        'adresa'     => $strada,
        'sector'     => GP_FIRMA_SECTOR,
        'oras'       => GP_FIRMA_ORAS,
        'intreaga'   => $intreaga,
        'intreagaEn' => implode(', ', array_merge([$strada], $coadaEn)),
        // Legătura spre hartă se face din aceeași stradă, nu dintr-un text
        // scris de mână: altfel adresa schimbată din Setări ar muta rândul din
        // pagină și ar lăsa butonul „Deschide în Google Maps" pe cea veche.
        'harta'      => 'https://www.google.com/maps/search/?api=1&query='
            . rawurlencode($intreaga),
        'program'    => gp_program(trim((string) $program) ?: GP_FIRMA_PROGRAM),
    ];
}

/**
 * Programul de lucru, adus din setări în forma pe care o citește Google.
 *
 * Google nu arată ore lângă firmă decât dacă i le dai în date structurate, iar
 * forma cerută e `openingHoursSpecification`, cu zilele scrise ca adrese de
 * schema.org. Nimeni nu tastează așa ceva într-o casetă din panou. Aici se
 * primește ce ar scrie un om — „Lu-Sâ 05:00-21:00", „Luni-Vineri 05:00-21:00,
 * Sâmbătă 06:00-14:00", sau numai „05:00-21:00" — și se scoate și forma pentru
 * mașină, și rândul pe care îl citește vizitatorul, în limba paginii.
 *
 * Se primesc și prescurtările englezești (Mo, Tu, We, Th, Fr, Sa, Su): exact
 * ele apar în orice exemplu de pe internet de unde ar copia cineva.
 *
 * Ce NU se face: nu se ghicește. O bucată care nu se înțelege se lasă afară cu
 * totul, iar dacă nu rămâne nimic, câmpul lipsește din marcaj. Un program
 * greșit publicat e mai scump decât niciunul: omul vine la ușă închisă.
 *
 * Singura presupunere e scrisă anume, într-un singur loc: când se dau numai
 * orele, fără zile, se înțelege luni-sâmbătă. Patronul o schimbă scriind
 * zilele în față, iar caseta din Setări îi spune asta în dreptul ei.
 *
 * @return array{spec: array<int, array<string, mixed>>, ro: string, en: string}
 */
function gp_program(string $scris): array
{
    $ZILE = [
        'Monday'    => ['lu', 'mo'],
        'Tuesday'   => ['ma', 'tu'],
        'Wednesday' => ['mi', 'we'],
        'Thursday'  => ['jo', 'th'],
        'Friday'    => ['vi', 'fr'],
        'Saturday'  => ['sa'],
        'Sunday'    => ['du', 'su'],
    ];
    $ET = [
        'Monday'    => ['Luni', 'Monday'],
        'Tuesday'   => ['Marți', 'Tuesday'],
        'Wednesday' => ['Miercuri', 'Wednesday'],
        'Thursday'  => ['Joi', 'Thursday'],
        'Friday'    => ['Vineri', 'Friday'],
        'Saturday'  => ['Sâmbătă', 'Saturday'],
        'Sunday'    => ['Duminică', 'Sunday'],
    ];
    $ordine = array_keys($ZILE);
    $cod = [];
    foreach ($ZILE as $zi => $prescurtari) {
        foreach ($prescurtari as $p) {
            $cod[$p] = $zi;
        }
    }

    // Diacriticele cad întâi, ca „Sâmbătă", „Sambata" și „SÂ" să ajungă la
    // aceeași cheie. strtolower() singur n-ar coborî „Â": lucrează pe octeți,
    // nu pe litere.
    $simplu = static function (string $s): string {
        return strtolower(strtr($s, [
            'ă' => 'a', 'Ă' => 'a', 'â' => 'a', 'Â' => 'a', 'î' => 'i', 'Î' => 'i',
            'ș' => 's', 'Ș' => 's', 'ş' => 's', 'Ş' => 's',
            'ț' => 't', 'Ț' => 't', 'ţ' => 't', 'Ţ' => 't',
        ]));
    };

    $spec = [];
    $bucatiRo = [];
    $bucatiEn = [];
    // Programul desfăcut pe zile, pentru cine desenează un tabel cu un rând de
    // fiecare zi — magazinul face asta. Se putea scoate și din `spec`, dar
    // numai citind adresele „https://schema.org/Monday" înapoi, în browser:
    // adică tocmai o A DOUA citire a aceluiași lucru, care e ce repară
    // fișierul ăsta. Ziua care lipsește din tablou e zi închisă.
    $peZi = [];

    foreach (preg_split('/[,;]+/u', $scris) ?: [] as $bucata) {
        $b = trim($simplu($bucata));
        if ($b === '') {
            continue;
        }
        // Ora se scrie și cu punct („05.00"), fiindcă așa o dă tastatura
        // numerică de pe telefon; liniuța poate fi și una tipografică, dacă
        // textul a trecut printr-un editor care „îndreaptă" cratimele.
        if (!preg_match('/^(?:([a-z\/ -]*?)\s+)?(\d{1,2})[:.](\d{2})\s*[-–—]\s*(\d{1,2})[:.](\d{2})$/u', $b, $m)) {
            continue;
        }

        $zile = [];
        $parteZile = trim($m[1] ?? '');
        if ($parteZile === '') {
            $zile = array_slice($ordine, 0, 6);   // luni-sâmbătă; vezi explicația
        } else {
            foreach (preg_split('/[\/ ]+/', $parteZile) ?: [] as $grup) {
                $grup = trim($grup);
                if ($grup === '') {
                    continue;
                }
                $capete = explode('-', $grup);
                if (count($capete) === 2) {
                    $de = $cod[substr(trim($capete[0]), 0, 2)] ?? null;
                    $la = $cod[substr(trim($capete[1]), 0, 2)] ?? null;
                    if ($de === null || $la === null) {
                        continue;
                    }
                    $i = (int) array_search($de, $ordine, true);
                    $j = (int) array_search($la, $ordine, true);
                    // Se merge circular, ca „Vi-Lu" să însemne vineri, sâmbătă,
                    // duminică, luni — nu o listă goală.
                    for ($k = 0; $k < 7; $k++) {
                        $poz = ($i + $k) % 7;
                        $zile[] = $ordine[$poz];
                        if ($poz === $j) {
                            break;
                        }
                    }
                } else {
                    $una = $cod[substr($grup, 0, 2)] ?? null;
                    if ($una !== null) {
                        $zile[] = $una;
                    }
                }
            }
        }

        $zile = array_values(array_unique($zile));
        if (!$zile) {
            continue;
        }
        $ora  = (int) $m[2];
        $min  = (int) $m[3];
        $oraC = (int) $m[4];
        $minC = (int) $m[5];
        if ($ora > 23 || $min > 59 || $oraC > 24 || $minC > 59) {
            continue;
        }
        $deschide = sprintf('%02d:%02d', $ora, $min);
        $inchide  = sprintf('%02d:%02d', $oraC, $minC);

        $spec[] = [
            '@type'     => 'OpeningHoursSpecification',
            'dayOfWeek' => array_map(
                static fn(string $z): string => 'https://schema.org/' . $z,
                $zile
            ),
            'opens'     => $deschide,
            'closes'    => $inchide,
        ];

        // Prima bucată care numește o zi o câștigă: „Lu-Vi 07:00-21:00,
        // Vi 09:00-13:00" lasă vineri la 07:00-21:00. Se putea și invers, dar
        // atunci un al doilea interval scris din greșeală ar fi șters tăcut
        // primul, iar aici tăcerea e tocmai paguba de reparat.
        foreach ($zile as $z) {
            if (!isset($peZi[$z])) {
                $peZi[$z] = [$deschide, $inchide];
            }
        }

        // Rândul pentru om: zilele alăturate se strâng într-un interval, ca să
        // nu scrie „Luni, Marți, Miercuri, Joi, Vineri, Sâmbătă 05:00-21:00".
        $indici = array_map(
            static fn(string $z): int => (int) array_search($z, $ordine, true),
            $zile
        );
        sort($indici);
        $siruri  = [];
        $inceput = $indici[0];
        $ultim   = $indici[0];
        foreach (array_slice($indici, 1) as $ix) {
            if ($ix === $ultim + 1) {
                $ultim = $ix;
                continue;
            }
            $siruri[] = [$inceput, $ultim];
            $inceput  = $ix;
            $ultim    = $ix;
        }
        $siruri[] = [$inceput, $ultim];

        $scrieZile = static function (int $limba) use ($siruri, $ordine, $ET): string {
            $parti = [];
            foreach ($siruri as [$a, $z]) {
                $numeA = $ET[$ordine[$a]][$limba];
                $numeZ = $ET[$ordine[$z]][$limba];
                if ($z - $a >= 2) {
                    $parti[] = $numeA . '–' . $numeZ;
                } elseif ($z > $a) {
                    $parti[] = $numeA . ', ' . $numeZ;
                } else {
                    $parti[] = $numeA;
                }
            }
            return implode(', ', $parti);
        };

        $bucatiRo[] = $scrieZile(0) . ' ' . $deschide . '–' . $inchide;
        $bucatiEn[] = $scrieZile(1) . ' ' . $deschide . '–' . $inchide;
    }

    // Toate cele șapte zile, în ordine, ca cine desenează tabelul să nu-și facă
    // propria listă de zile — a treia listă ar fi și ea un al doilea izvor.
    $zileIntregi = [];
    foreach ($ordine as $z) {
        $zileIntregi[$z] = $peZi[$z] ?? null;
    }

    return [
        'spec' => $spec,
        'ro'   => implode(' · ', $bucatiRo),
        'en'   => implode(' · ', $bucatiEn),
        'zile' => $zileIntregi,
    ];
}
