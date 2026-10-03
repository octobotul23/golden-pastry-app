<?php

/**
 * Golden Pastry — fișele tehnice încărcate din Catalog, într-un singur loc.
 *
 * Două feluri de fișe, și deosebirea dintre ele e tot rostul fișierului:
 *
 *   - cele din PACHET stau în folderul `fise/` și se cheamă
 *     `fisa-tehnica-<slug>-congelat.pdf`. Vin odată cu fiecare actualizare și
 *     NU se șterg niciodată de pe disc de către server: o rută care ar șterge
 *     una ar lăsa-o lipsă până la urcarea următoare, iar pe calculatorul de
 *     lucru ar șterge-o din proiect;
 *   - cele ÎNCĂRCATE de patron stau lângă poze, în `products/`, și se cheamă
 *     `fisa-<id>-<8 hex>.pdf`. Pe acelea le scrie și le șterge serverul.
 *
 * De ce lângă poze și nu în `fise/`: actualizarea (`actualizeaza-vps.sh`)
 * sincronizează pachetul cu `--delete`, deci ar șterge din `fise/` orice fișier
 * care nu e în pachet — adică tocmai fișele patronului. Folderul pozelor e
 * singurul sărit de sincronizare.
 *
 * Fișierul se sprijină numai pe `api/lib/poze.php` (pragurile serverului și
 * scrisul mărimilor) și nu atinge baza: tabelul lui de cazuri se cântărește
 * fără server, din `.claude/check-fise-tehnice.mjs`.
 */

declare(strict_types=1);

/**
 * Cât îngăduie PANOUL pentru o fișă.
 *
 * Aceeași cifră ca la poze, și nu din întâmplare: serverul are un singur rând
 * de praguri (`upload_max_filesize 11M`, `post_max_size 13M` în
 * `api/.htaccess`), socotite pentru 10 MB. O fișă adevărată are sub 100 KB;
 * marginea e pentru una scanată.
 */
const FISA_MAX_OCTETI = 10 * 1024 * 1024;

/**
 * Forma numelui dat de încărcare, ca tipar — fără folder și fără ancore.
 *
 * Aceeași ca `FORMA_FISA_URCATA` din `.claude/poze-fara-produs.mjs`, care
 * hotărăște ce are voie să ȘTEARGĂ curățenia; proba le ține în pas. Un nume
 * din pachet (`fisa-tehnica-…`) nu se poate potrivi: după „fisa-" tiparul cere
 * o cifră.
 */
const FISA_FORMA_URCATA = 'fisa-\d+-[0-9a-f]{8}\.pdf';

/**
 * E fișierul un PDF?
 *
 * Se judecă după CONȚINUT, ca la poze, și din același motiv: extensia și tipul
 * anunțat de browser se pot scrie cu mâna, iar folderul e public. Două semne,
 * amândouă cerute:
 *
 *   - primii cinci octeți sunt `%PDF-`. Standardul îngăduie antetul oriunde în
 *     primii 1024, și cititoarele îl caută acolo; noi îl cerem la ÎNCEPUT,
 *     fiindcă un fișier care începe cu altceva poate fi două lucruri deodată
 *     (o pagină HTML cu un PDF lipit la coadă);
 *   - `finfo`, când serverul îl are, spune tot `application/pdf`. Când lipsește
 *     (extensia `fileinfo` nu e încărcată), rămâne primul semn — destul ca
 *     fișierul să nu fie servit drept altceva: `.htaccess`-ul din pachet îi pune
 *     tipul după extensie și `nosniff`.
 */
function fisa_e_pdf(string $cale): bool
{
    $f = @fopen($cale, 'rb');
    if ($f === false) {
        return false;
    }
    $cap = (string) fread($f, 5);
    fclose($f);
    if ($cap !== '%PDF-') {
        return false;
    }
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi !== false) {
            $tip = @finfo_file($fi, $cale);
            // `false` = finfo n-a putut citi baza lui de semne: nu e un „nu",
            // e o tăcere, și rămâne primul semn.
            if (is_string($tip) && $tip !== 'application/pdf') {
                return false;
            }
        }
    }
    return true;
}

/**
 * Numele sub care se pune pe disc o fișă încărcată.
 *
 * Nou la fiecare încărcare, ca la poze: o fișă îndreptată trebuie să ajungă la
 * client, nu cea veche din memoria browserului.
 */
function fisa_nume_urcat(int $id): string
{
    return 'fisa-' . $id . '-' . bin2hex(random_bytes(4)) . '.pdf';
}

/** Are numele forma dată de încărcare? Fără folder: numai numele fișierului. */
function fisa_e_nume_urcat(string $nume): bool
{
    return (bool) preg_match('#^' . FISA_FORMA_URCATA . '$#D', $nume);
}

/**
 * Vorba pentru „fișa e prea mare" — cu aceeași socoteală ca la poze: cine a
 * spus „nu", panoul ori serverul, se SOCOTEȘTE, nu se scrie cu mâna.
 */
function fisa_mesaj_prea_mare(): string
{
    $limite = poza_limite_server();
    $promis = poza_marime_text(FISA_MAX_OCTETI);

    if ($limite['efectiv'] >= FISA_MAX_OCTETI) {
        return 'Fișa depășește ' . $promis . '. Salveaz-o mai mică (fără poze scanate la rezoluție mare)'
            . ' și încearcă din nou.';
    }

    $are = poza_marime_text($limite['efectiv']);

    return 'Serverul primește acum fișiere de cel mult ' . $are . ', deși panoul îți promite '
        . $promis . '. Micșorează fișa sub ' . $are . ', sau ridică limita serverului — '
        . poza_unde_se_ridica();
}

/** Vorba pentru corpul aruncat de PHP înainte de orice cod al nostru. */
function fisa_mesaj_corp_aruncat(int $octetiCerere): string
{
    $limite = poza_limite_server();

    return 'Fișa n-a ajuns deloc la server: cererea avea ' . poza_marime_text($octetiCerere)
        . ', iar serverul taie orice cerere peste ' . poza_marime_text($limite['post'])
        . ' înainte să apuce să citească ceva din ea. Micșorează fișa, sau ridică limita'
        . ' serverului — ' . poza_unde_se_ridica();
}
