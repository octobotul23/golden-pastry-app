<?php
/**
 * „Acces": matricea drepturilor, cum o vede și o schimbă fondatorul.
 *
 * CEREREA PATRONULUI, 4 octombrie 2026: „un meniu Acces unde să pot pune
 * nivelul de acces pentru orice". Judecata însăși — cine are ce — stă în
 * api/lib/auth.php (`drept()` și constantele `GP_DREPT_*`), fiindcă o cere
 * fiecare rută. Aici sunt numai cele trei uși ale paginii:
 *
 *   GET    /acces   matricea întreagă, cu numele ei de arătat;
 *   PUT    /acces   bifele mutate, toate într-o singură tranzacție;
 *   DELETE /acces   înapoi la început.
 *
 * TOATE TREI SUNT ALE FONDATORULUI, și asta nu e o bifă (`require_acces()`).
 * Un drept de a da drepturi, dat altcuiva, ar fi o cheie care își deschide
 * singură lacătul.
 *
 * TABELUL `role_rights` ȚINE NUMAI DEOSEBIRILE față de pornire. O bifă pusă la
 * loc pe valoarea ei din pornire își ȘTERGE rândul, nu îl rescrie — așa
 * „tabelul gol = aplicația de ieri" rămâne adevărat și după o lună de bife
 * mutate încoace și încolo, iar „înapoi la început" e o golire.
 *
 * FĂRĂ MIGRAREA 033: citirea răspunde cu matricea din pornire și `activ: false`
 * (pagina se deschide și spune ce lipsește), scrierile răspund 503
 * `acces_unavailable`. Restul aplicației nu simte nimic — `drept()` cade pe
 * implicit.
 */

declare(strict_types=1);

/** Numele coloanelor, cum se citesc în capul matricei și în Jurnal. */
const GP_ACCES_ROLURI = [
    'admin'          => 'Administrator',
    'manager'        => 'Manager',
    'agent_vanzari'  => 'Agent de vânzări',
    'angajat'        => 'Angajat',
    'utilizator_lab' => 'Om de laborator',
];

/**
 * Ce trebuie știut despre o coloană înainte de a pune bife în ea.
 *
 * Tableta din laborator intră cu rolul `angajat`, dar are plafonul ei
 * (`GP_DREPT_TABLETA`): fondatorul trebuie să afle AICI că bifa „Catalog" pusă
 * angajatului nu deschide Catalogul și pe tabletă — altfel o caută acolo și
 * crede că salvarea n-a mers.
 */
const GP_ACCES_NOTE = [
    'angajat' => 'Cuprinde și tableta din laborator. Tableta rămâne însă numai cu Laborator, Rută, Depozit și Pontaj, orice ai bifa aici.',
];

/** Câte bife primește o salvare. Matricea întreagă are 24 × 5 = 120. */
const GP_ACCES_MAX_SCHIMBARI = 200;

/**
 * Matricea, în forma pe care o desenează pagina.
 *
 * `acum` e CELULA — ce e bifat —, nu judecata întreagă: o faptă bifată pe un
 * rol fără panoul ei iese aici „da", iar pagina scrie lângă ea „fără Depozit
 * bifa nu ajută" (din `cere`). Dacă s-ar trimite judecata, bifa ar părea
 * nepusă și fondatorul ar pune-o încă o dată.
 *
 * `schimbate` numără celulele care se deosebesc de pornire. Celulele fixe nu
 * intră: nu se pot deosebi.
 */
function acces_raspuns(): array
{
    $coloane = [];
    foreach (GP_DREPT_COLOANE as $rol) {
        $c = ['rol' => $rol, 'eticheta' => GP_ACCES_ROLURI[$rol] ?? $rol];
        if (isset(GP_ACCES_NOTE[$rol])) {
            $c['nota'] = GP_ACCES_NOTE[$rol];
        }
        $coloane[] = $c;
    }

    $randuri   = [];
    $schimbate = 0;
    foreach (GP_DREPT_IMPLICIT as $cheie => $dinPornire) {
        $implicit = [];
        $acum     = [];
        $fix      = [];
        foreach (GP_DREPT_COLOANE as $rol) {
            $implicit[$rol] = in_array($rol, $dinPornire, true);
            $acum[$rol]     = drept_celula($cheie, $rol);
            if (isset(GP_DREPT_LACATE[$cheie][$rol])) {
                $fix[$rol] = [
                    'valoare' => GP_DREPT_LACATE[$cheie][$rol],
                    'motiv'   => GP_DREPT_LACATE_MOTIV[$cheie][$rol] ?? '',
                ];
            } elseif ($acum[$rol] !== $implicit[$rol]) {
                $schimbate++;
            }
        }
        $randuri[] = [
            'cheie'    => $cheie,
            'eticheta' => GP_DREPT_ETICHETE[$cheie] ?? $cheie,
            'fel'      => in_array($cheie, GP_DREPT_PANOURI, true) ? 'panou' : 'fapta',
            'cere'     => GP_DREPT_CERE[$cheie] ?? [],
            'implicit' => $implicit,
            'acum'     => $acum,
            // `(object)`: fără el, un rând fără celule fixe ar pleca `[]`, iar
            // pagina ar citi când o listă, când un obiect.
            'fix'      => (object) $fix,
        ];
    }

    return [
        'activ'     => acces_supported(),
        'coloane'   => $coloane,
        'randuri'   => $randuri,
        'schimbate' => $schimbate,
    ];
}

/** Oprește scrierea pe o bază fără migrarea 033. */
function acces_cere_tabelul(): void
{
    if (!acces_supported()) {
        fail('Baza de date nu are încă tabelul pentru „Acces". Rulează migrarea sql/033_acces.sql, apoi încearcă din nou.',
            503, 'acces_unavailable');
    }
}

function route_acces_get(): void
{
    require_acces();
    json_out(acces_raspuns());
}

/**
 * Salvează bifele mutate: `{ schimbari: [{cheie, rol, permis}, …] }`.
 *
 * TOT ORI NIMIC. Cererea se judecă întreagă înainte de prima scriere: o celulă
 * necunoscută ori una fixă refuză toată salvarea, nu numai rândul ei. Jumătate
 * de matrice salvată ar fi o stare pe care fondatorul n-a cerut-o și pe care
 * pagina, rămasă cu bifele lui nesalvate pe ecran, n-ar ști s-o arate.
 *
 * UN SINGUR RÂND DE JURNAL pentru toată salvarea, pe roluri: „Manager:
 * +Catalog, −Depozit · Angajat: +Jurnal". În el intră numai ce s-a MIȘCAT — o
 * bifă trimisă pe valoarea pe care o avea deja nu e o schimbare și nu se scrie.
 */
function route_acces_save(): void
{
    $eu = require_acces();
    acces_cere_tabelul();

    $b   = body();
    $sch = $b['schimbari'] ?? null;
    // O LISTĂ, nu un obiect cu chei. (`array_is_list()` e din PHP 8.1, iar casa
    // cere numai 8.0 — v. api/verifica.php.)
    if (!is_array($sch) || !$sch || array_keys($sch) !== range(0, count($sch) - 1)) {
        fail('Nu ai trimis nicio schimbare.', 422, 'bad_cell');
    }
    if (count($sch) > GP_ACCES_MAX_SCHIMBARI) {
        fail('Prea multe schimbări într-o singură salvare.', 422, 'bad_cell');
    }

    // ---- judecata cererii, înainte de orice scriere ----
    $vrute = [];
    foreach ($sch as $s) {
        $cheie  = is_array($s) && is_string($s['cheie'] ?? null) ? $s['cheie'] : '';
        $rol    = is_array($s) && is_string($s['rol'] ?? null) ? $s['rol'] : '';
        $permis = is_array($s) ? ($s['permis'] ?? null) : null;

        if (!isset(GP_DREPT_IMPLICIT[$cheie])) {
            fail('Dreptul „' . mb_substr($cheie, 0, 48) . '" nu există.', 422, 'bad_cell');
        }
        if (!in_array($rol, GP_DREPT_COLOANE, true)) {
            // Fondatorul și clientul ajung aici: nu sunt coloane. Fondatorul are
            // mereu tot, clientul n-are niciun panou — nicio bifă nu le schimbă.
            fail('Rolul „' . mb_substr($rol, 0, 32) . '" nu are bife în „Acces".', 422, 'bad_cell');
        }
        // `true`/`false` adevărate. Un „0" ori un „nu" venit ca text ar fi citit
        // de PHP drept „da" — iar aici „da" deschide o ușă.
        if (!is_bool($permis)) {
            fail('Bifa pentru „' . GP_DREPT_ETICHETE[$cheie] . '" trebuie să fie da ori nu.', 422, 'bad_cell');
        }
        if (isset(GP_DREPT_LACATE[$cheie][$rol])) {
            fail('„' . GP_DREPT_ETICHETE[$cheie] . '" pentru ' . GP_ACCES_ROLURI[$rol] . ' nu se poate schimba. '
                . (GP_DREPT_LACATE_MOTIV[$cheie][$rol] ?? ''), 422, 'locked_cell');
        }
        $loc = $cheie . '|' . $rol;
        if (isset($vrute[$loc])) {
            // Aceeași celulă de două ori, poate cu două valori: nu se ghicește
            // care e cea vrută.
            fail('Aceeași bifă apare de două ori în cerere.', 422, 'bad_cell');
        }
        $vrute[$loc] = ['cheie' => $cheie, 'rol' => $rol, 'permis' => $permis];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Citită SUB LACĂT, nu din memoria cererii: doi fondatori care salvează
        // deodată se așază la rând, iar al doilea își compară bifele cu ce a
        // lăsat primul — altfel Jurnalul ar scrie o mișcare care n-a avut loc.
        $acum = [];
        foreach (all('SELECT cheie, rol, permis FROM role_rights FOR UPDATE') as $r) {
            $acum[(string) $r['cheie']][(string) $r['rol']] = (int) $r['permis'] === 1;
        }

        $miscate = [];
        foreach ($vrute as $v) {
            $dinPornire = in_array($v['rol'], GP_DREPT_IMPLICIT[$v['cheie']], true);
            $era        = $acum[$v['cheie']][$v['rol']] ?? $dinPornire;
            if ($era === $v['permis']) {
                continue;
            }
            if ($v['permis'] === $dinPornire) {
                q('DELETE FROM role_rights WHERE cheie = ? AND rol = ?', [$v['cheie'], $v['rol']]);
            } else {
                q(
                    'INSERT INTO role_rights (cheie, rol, permis, set_by, set_at)
                     VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE permis = VALUES(permis), set_by = VALUES(set_by), set_at = VALUES(set_at)',
                    [$v['cheie'], $v['rol'], $v['permis'] ? 1 : 0, (int) $eu['id']]
                );
            }
            $miscate[$v['rol']][] = ($v['permis'] ? '+' : '−') . GP_DREPT_ETICHETE[$v['cheie']];
        }

        if ($miscate) {
            // În ordinea coloanelor, nu a sosirii: același rând de Jurnal pentru
            // aceeași salvare, oricum ar fi umblat omul prin matrice.
            $parti = [];
            foreach (GP_DREPT_COLOANE as $rol) {
                if (isset($miscate[$rol])) {
                    $parti[] = GP_ACCES_ROLURI[$rol] . ': ' . implode(', ', $miscate[$rol]);
                }
            }
            audit('acces_schimbat', 'acces', null, implode(' · ', $parti));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Memoria cererii ține matricea de DINAINTEA salvării; răspunsul trebuie
    // s-o arate pe cea de după.
    drepturi_matrice(true);
    json_out(acces_raspuns());
}

/**
 * Înapoi la început: golește tabelul. Aplicația răspunde iar ca în prima zi.
 *
 * Rândul de Jurnal spune CE s-a pierdut, nu numai că s-a apăsat butonul: după
 * golire nu mai e de unde afla ce bife fuseseră mutate.
 */
function route_acces_reset(): void
{
    require_acces();
    acces_cere_tabelul();

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $erau = [];
        foreach (all('SELECT cheie, rol, permis FROM role_rights FOR UPDATE') as $r) {
            $cheie = (string) $r['cheie'];
            $rol   = (string) $r['rol'];
            // Un rând rămas de la o cheie scoasă între timp din cod, ori scris
            // cu mâna peste o celulă fixă, pleacă odată cu restul, dar nu are
            // ce nume să poarte în Jurnal.
            if (!isset(GP_DREPT_ETICHETE[$cheie], GP_ACCES_ROLURI[$rol]) || isset(GP_DREPT_LACATE[$cheie][$rol])) {
                continue;
            }
            if (((int) $r['permis'] === 1) === in_array($rol, GP_DREPT_IMPLICIT[$cheie], true)) {
                continue;
            }
            $erau[$rol][] = ((int) $r['permis'] === 1 ? '+' : '−') . GP_DREPT_ETICHETE[$cheie];
        }
        $sterse = q('DELETE FROM role_rights')->rowCount();

        if ($sterse > 0) {
            $parti = [];
            foreach (GP_DREPT_COLOANE as $rol) {
                if (isset($erau[$rol])) {
                    $parti[] = GP_ACCES_ROLURI[$rol] . ': ' . implode(', ', $erau[$rol]);
                }
            }
            audit('acces_reset', 'acces', null,
                'Înapoi la început. S-a renunțat la: ' . ($parti ? implode(' · ', $parti) : 'nicio bifă mutată') . '.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    drepturi_matrice(true);
    json_out(acces_raspuns());
}
