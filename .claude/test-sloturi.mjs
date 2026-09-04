// Teste pentru pozele puse direct în pagină, cu mouse-ul.
//
//   php -S 127.0.0.1:8100 -t . .claude/dev-server.php
//   node .claude/test-sloturi.mjs
//
// Miza: `image-slot.js` scrie poza într-un fișier de lângă pagină,
// `.image-slots.state.json`, dar o scrie printr-o singură funcție —
// `window.omelette.writeFile` — care există numai în editorul unde au fost
// desenate paginile. Pe orice alt server funcția lipsește, iar `save()` din
// image-slot.js iese tăcut: poza se vede pe ecran până la reîncărcare și apoi
// dispare, fără nicio eroare în consolă. Exact așa s-au pierdut pozele puse
// pe pagina „Despre noi".
//
// Serverul de test pune funcția la loc și primește fișierul înapoi pe
// `/__slots`. Probele de aici păzesc ambele capete: peticul trebuie să fie în
// pagină, iar ruta trebuie să scrie pe disc — și să refuze ce n-ar trebui să
// scrie.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// 127.0.0.1, nu „localhost": pe Windows „localhost" se rezolvă întâi la ::1,
// unde serverul încorporat nu ascultă. Aceeași alegere ca în test-api.mjs.
const BASE = process.env.GP_DEV || 'http://127.0.0.1:8100';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const FISIER = path.resolve(HERE, '..', '.image-slots.state.json');

let pass = 0, fail = 0;
const failures = [];

function check(name, cond, extra = '') {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
}

let pagina = '';
try {
  const res = await fetch(BASE + '/Site%20Public.dc.html');
  pagina = await res.text();
  check('pagina publică răspunde → 200', res.status === 200, `a dat ${res.status}`);
} catch {
  // Fără serverul local probele astea nu sunt „eșuate", ci nerulate.
  console.log('\n  —  sărit: serverul de dezvoltare nu răspunde pe ' + BASE);
  console.log('     php -S 127.0.0.1:8100 -t . .claude/dev-server.php\n');
  console.log('0 trecute · 0 eșuate (sărit)');
  process.exit(0);
}

// ── Peticul din pagină ──────────────────────────────────────────────────────
// Fără el, tot restul poate fi în regulă și poza tot se pierde: nimeni nu mai
// cheamă `/__slots`.
check('pagina primește funcția de scriere care îi lipsește',
  /window\.omelette/.test(pagina), 'nu am găsit peticul în <head>');
check('peticul trimite fișierul înapoi pe /__slots',
  /["']\/__slots["']/.test(pagina), 'peticul nu pomenește ruta');
check('peticul stă înaintea paginii desenate',
  pagina.includes('window.omelette') && pagina.indexOf('window.omelette') < pagina.indexOf('<x-dc'),
  'peticul apare după marcajul paginii');

// Fișierele care nu sunt pagini desenate rămân servite ca atare — altfel
// peticul ar ajunge în JavaScript sau în JSON și le-ar strica.
{
  const r = await fetch(BASE + '/image-slot.js');
  const t = await r.text();
  check('peticul nu se lipește și de alte fișiere', !/window\.omelette *=/.test(t.slice(0, 400)),
    'image-slot.js a primit peticul');
}

// ── Ruta de scriere ─────────────────────────────────────────────────────────
// Fișierul real e cel cu pozele adevărate ale omului. Copia de siguranță se
// ține PE DISC, nu în memorie, și proba se închide cu un lacăt.
//
// Prima variantă ținea conținutul într-o variabilă și îl punea la loc în
// `finally`. A pierdut pozele de două ori, în două feluri: un proces oprit din
// afară (limită de sesiune, Ctrl-C) nu mai ajunge niciodată la `finally`; iar
// două rulări pornite în paralel își încalecă: a doua își face copia din starea
// de probă lăsată de prima, apoi o pune „la loc" peste fișierul adevărat.
// Amândouă se termină la fel: fișierul rămâne cu slotul de probă, iar pozele
// puse cu mouse-ul nu mai există în nicio copie.
const PASTRAT = FISIER + ".pastrat";
const LACAT   = FISIER + ".lacat";

// Ține altcineva fișierul chiar acum? Atunci nu-l atingem deloc.
let lacatStrain = false;
if (fs.existsSync(LACAT)) {
  if (Date.now() - fs.statSync(LACAT).mtimeMs < 10 * 60 * 1000) lacatStrain = true;
  else fs.unlinkSync(LACAT);
}

// Vindecare: dacă o rulare de dinainte a fost omorâtă, copia ei e încă pe disc.
// O punem la loc înainte de orice altceva, ca proba să nu clădească peste ea.
if (!lacatStrain && fs.existsSync(PASTRAT)) {
  fs.copyFileSync(PASTRAT, FISIER);
  fs.unlinkSync(PASTRAT);
  console.log("  (o rulare de dinainte a fost întreruptă — am pus fișierul cu poze la loc)");
}

// Lacăt: două rulări în paralel pe același fișier se termină cu pozele pierdute.
if (!lacatStrain) fs.writeFileSync(LACAT, String(process.pid));

if (lacatStrain) {
  // Altă rulare lucrează chiar acum pe fișierul cu pozele adevărate. Nu-l
  // atingem: exact așa s-au pierdut o dată pozele de pe „Despre noi" —
  // a doua rulare și-a făcut copia din starea de probă a primei.
  console.log("\n  ~ sar peste probele care scriu: altă rulare ține " + path.basename(FISIER));
} else {
  const inainte = fs.existsSync(FISIER) ? fs.readFileSync(FISIER, "utf8") : null;
  // Copia pe disc se scrie ÎNAINTE de prima scriere, ca să existe și dacă procesul
  // e omorât la mijloc.
  if (inainte !== null) fs.copyFileSync(FISIER, PASTRAT);

  const trimite = (corp, metoda = 'POST') => fetch(BASE + '/__slots', {
    method: metoda,
    headers: { 'Content-Type': 'application/json' },
    body: metoda === 'POST' ? corp : undefined,
  });

  try {
    const deProba = Object.assign({}, inainte ? JSON.parse(inainte) : {}, {
      'zz-proba-sloturi': { u: 'data:image/webp;base64,UklGRg==', s: 1, x: 0, y: 0 },
    });

    const r = await trimite(JSON.stringify(deProba));
    check('o poză pusă în pagină se scrie pe disc', r.status === 204, `a dat ${r.status}`);

    const dupa = fs.existsSync(FISIER) ? JSON.parse(fs.readFileSync(FISIER, 'utf8')) : {};
    check('scrierea chiar a ajuns în fișier', !!dupa['zz-proba-sloturi'],
      'slotul de probă nu e în ' + path.basename(FISIER));

    // Pozele care erau deja acolo nu au voie să dispară la o salvare nouă.
    if (inainte) {
      const vechi = Object.keys(JSON.parse(inainte));
      check('pozele dinainte rămân la locul lor',
        vechi.every((k) => k in dupa), 'au dispărut: ' + vechi.filter((k) => !(k in dupa)).join(', '));
    }

    // Și fișierul trebuie să rămână citibil de pagină, nu doar scris.
    const citit = await fetch(BASE + '/.image-slots.state.json');
    check('fișierul cu poze se poate citi înapoi din pagină', citit.ok, `a dat ${citit.status}`);

    // ── Ce trebuie refuzat ────────────────────────────────────────────────────
    // Un corp care nu e un obiect cu sloturi ar șterge tot ce e înăuntru.
    const gresit = await trimite('[1,2,3]');
    check('o listă în loc de sloturi e refuzată', gresit.status === 400, `a dat ${gresit.status}`);

    const stricat = await trimite('{nu e json');
    check('un JSON stricat e refuzat', stricat.status === 400, `a dat ${stricat.status}`);

    const gol = await trimite('');
    check('un corp gol e refuzat', gol.status === 413, `a dat ${gol.status}`);

    const get = await trimite(null, 'GET');
    check('citirea rutei de scriere e refuzată', get.status === 405, `a dat ${get.status}`);

    // Refuzurile nu au voie să lase fișierul rupt pe drum.
    const dupaRefuzuri = JSON.parse(fs.readFileSync(FISIER, 'utf8'));
    check('fișierul rămâne întreg după refuzuri', !!dupaRefuzuri['zz-proba-sloturi'],
      'refuzurile au atins fișierul');
  } finally {
    // Punem la loc exact ce era, inclusiv „nu exista". Sursa e copia de pe disc,
    // nu variabila: dacă ceva a mers prost, pe disc e adevărul.
    if (inainte === null) { try { fs.unlinkSync(FISIER); } catch { /* nu era */ } }
    else if (fs.existsSync(PASTRAT)) fs.copyFileSync(PASTRAT, FISIER);
    else fs.writeFileSync(FISIER, inainte);
    try { fs.unlinkSync(PASTRAT); } catch { /* nu era */ }
    try { fs.unlinkSync(LACAT); } catch { /* nu era */ }
    try { fs.unlinkSync(FISIER + ".tmp"); } catch { /* nu a rămas */ }
  }

  const refacut = fs.existsSync(FISIER) ? fs.readFileSync(FISIER, 'utf8') : null;
  check('proba nu a lăsat nimic în urmă', refacut === inainte, 'fișierul a rămas modificat');
}

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} eșuate`);
if (failures.length) {
  console.log('\nEșecuri:');
  failures.forEach((f) => console.log('  · ' + f));
}
process.exitCode = fail ? 1 : 0;
