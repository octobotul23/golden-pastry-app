// Probele de regresie pentru sloturile de poze — `image-slot.js`.
//
//   node .claude/check-poze.mjs
//
// Fișierul e un element propriu (custom element) cu shadow DOM, împrumutat din
// unealta cu care s-au construit paginile. Are două vieți: una de editor, în
// care pozele se pun cu mouse-ul și se reîncadrează, și una publică, în care nu
// e decât o poză pe ecranul unui vizitator. Poarta dintre ele e atributul
// `data-editable`, pus la randare doar dacă pagina are cu ce scrie pe disc
// (`window.omelette.writeFile`).
//
// Trei bucăți de editor scăpaseră de poartă și ajunseseră la clienți. Toate
// trei se verifică aici, plus a patra probă — că pachetul de publicare are
// aceleași reparații ca sursa. Fiecare a fost verificată stricând reparația la
// loc.
//
// Ce *nu* acoperă: pan-ul, zoom-ul și reîncadrarea — alea sunt gata gatuite
// (`:614-616`, `:624-626`, `:687-689`) și au fost verificate citind codul.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SURSA = 'image-slot.js';
const PACHET = path.join('deploy', 'public_html', 'image-slot.js');

let pass = 0, fail = 0;
const check = (defect, nume, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${nume}`); }
  else { fail++; console.log(`  FAIL  ${nume}  [${defect}]${extra ? ' — ' + extra : ''}`); }
};

/**
 * Cele trei porți, verificate pe conținutul unui fișier. Aceeași funcție
 * rulează pe sursă și pe copia din pachet: o reparație făcută în rădăcină și
 * nereconstruită nu e o reparație publicată.
 */
function porti(src, unde) {
  // (1) Atingerea. `touch-action:none` stătea pe regula `.frame img`,
  // necondiționat, în fiecare shadow root: degetul pus pe orice poză nu mai
  // derula pagina. Pe catalogul public poza e o bandă pe toată înălțimea
  // fiecărui card de produs, deci lista întreagă era moartă la atingere.
  // Pan-ul și redimensionarea pornesc de pe `.spill`, care își are propriul
  // `touch-action`, deci regula de aici n-a apărat niciodată vreun gest.
  const regulaImg = (src.match(/'\.frame img\{[^']*'\s*\+\s*\n\s*'[^']*'/) || [''])[0];
  check(`${unde}:300`, `${unde}: poza nu mai fură atingerea`,
    !/touch-action/.test(regulaImg), 'regula .frame img are încă touch-action');
  check(`${unde}:300`, `${unde}: atingerea se blochează doar la reîncadrare`,
    /:host\(\[data-reframe\]\)\s*\.frame img\{touch-action:none\}/.test(src));

  // (2) Slotul gol. Un vizitator care atingea un produs fără poză primea
  // selectorul de fișiere al propriului telefon.
  const peGol = (src.match(/_empty\.addEventListener\('click',[\s\S]{0,600}?\n {6}\}\);/) || [''])[0];
  check(`${unde}:571`, `${unde}: slotul gol nu mai deschide selectorul de fișiere`,
    /data-editable/.test(peGol), peGol ? 'handlerul nu are poartă' : 'n-am găsit handlerul');

  // (3) Trasul cu mouse-ul. `preventDefault()` pe dragover/drop fără poartă
  // însemna că o poză trasă peste site o înlocuia sub ochii vizitatorului.
  const drag = (src.match(/handleEvent\(e\)\s*\{[\s\S]{0,700}?dragenter/) || [''])[0];
  check(`${unde}:857`, `${unde}: pagina publică nu mai primește poze trase cu mouse-ul`,
    /data-editable/.test(drag), drag ? 'handleEvent n-are poartă' : 'n-am găsit handleEvent');
}

console.log('\n=== sloturile de poze ===');
porti(fs.readFileSync(path.join(ROOT, SURSA), 'utf8'), SURSA);

// Pachetul e generat, nu editat — dar tocmai de asta se uită. `check-deploy`
// verifică alte lucruri (.htaccess, rescrieri), nu conținutul acestui fișier.
const inPachet = path.join(ROOT, PACHET);
if (fs.existsSync(inPachet)) {
  console.log('\n=== aceleași reparații, în pachetul de publicare ===');
  porti(fs.readFileSync(inPachet, 'utf8'), PACHET);
} else {
  console.log('\n  ~     sar peste pachet: nu e construit (node .claude/build-deploy.mjs)');
}

console.log(`\n  ${pass} trecute · ${fail} picate\n`);
process.exit(fail ? 1 : 0);
