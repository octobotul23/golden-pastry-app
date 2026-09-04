// Baza de date din spatele lui `GP_BASE`, aflată o singură dată.
//
// De ce există: mai multe probe nu se pot mărgini la API. Ele cheamă PHP sau
// `mysql` ca să pună în bază o situație pe care API-ul n-o poate face (un rând
// rămas strâmb, un anunț eșuat, o setare veche) sau ca să numere ce API-ul nu
// arată. Toate scriau, literal, `require "api/config.php"` — adică baza
// `gp_dev`, orice ar fi spus `GP_BASE`.
//
// Rulate pe o instanță izolată (`node .claude/instanta.mjs porneste <nume>`),
// probele acelea scriau într-o bază și verificau în alta. Nu picau cinstit: ori
// treceau degeaba, ori picau din motive care n-aveau nicio legătură cu ce
// măsoară — „am primit [], așteptam patru mișcări" — și trimiteau căutarea în
// altă parte cu ceasuri întregi.
//
// Aici se află configurarea instanței care ascultă chiar pe portul din
// `GP_BASE` și se pune în mediu, ca `GP_CONFIG`. `api/index.php` citește deja
// variabila asta, deci orice bucată de PHP chemată de o probă o poate folosi la
// fel: e destul ca fragmentul care încarcă configurarea să fie scris cu
// `CERE_CONFIG`, nu cu calea bătută în cuie.
//
// Fără nicio instanță izolată pornită, nimic nu se schimbă: `GP_CONFIG` rămâne
// nepus și PHP cade pe `api/config.php`, adică pe `gp_dev` — mediul obișnuit,
// cu serverul pe 8100.

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

/**
 * Calea configurării instanței care ascultă pe portul din `GP_BASE`.
 *
 * `instanta.mjs` își ține starea în `%TEMP%/gp-instante/<nume>.json`: portul și
 * calea configurării făcute pentru ea. Potrivim după port, fiindcă asta e
 * singura legătură între adresa pe care o știe proba și baza din spatele ei.
 *
 * @returns {string|null} calea, sau null dacă nu e nicio instanță pe portul ăla
 */
export function configulLuiBase(base = process.env.GP_BASE || '') {
  if (!base) return null;
  let port;
  try {
    port = Number(new URL(base).port || 80);
  } catch {
    return null;
  }
  try {
    const dir = path.join(os.tmpdir(), 'gp-instante');
    for (const f of fs.readdirSync(dir).filter((x) => x.endsWith('.json'))) {
      const st = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
      if (Number(st.port) === port && fs.existsSync(st.cfg)) return st.cfg;
    }
  } catch {
    /* nicio instanță izolată pornită */
  }
  return null;
}

/**
 * Bazele pe care o probă are voie să scrie.
 *
 * Plasa de siguranță a tuturor probelor care ating baza direct: `gp_dev`,
 * `gp_test`, `gp_fresh` și bazele instanțelor izolate, care se numesc mereu
 * `gp_i_<nume>`. Orice altceva — o bază adevărată, de pildă — oprește proba.
 */
export const BAZA_DE_PROBA = /^(gp_dev|gp_test|gp_fresh|gp_i_[a-z0-9_]+)$/;

/**
 * Fragmentul PHP care încarcă configurarea potrivită.
 *
 * Se scrie în locul lui `"api/config.php"` în bucățile de PHP chemate cu
 * `php -r`. Aceeași regulă ca în api/index.php: mediul are întâietate.
 */
export const CERE_CONFIG = '(getenv("GP_CONFIG") ?: "api/config.php")';

// Efectul la import: PHP-ul chemat de probă moștenește mediul procesului, deci
// e destul să punem calea o dată, aici. O valoare pusă de om din afară nu se
// atinge — poate vrea anume altă bază.
const cfgulLuiBase = configulLuiBase();
if (cfgulLuiBase && !process.env.GP_CONFIG) {
  process.env.GP_CONFIG = cfgulLuiBase;
}
