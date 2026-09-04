// Probele de regresie pentru pagina de Setări — `Setari.dc.html`.
//
//   node .claude/check-setari.mjs
//
// Pagina se randează în browser și își ține logica într-un bloc `text/x-dc`,
// deci nu poate fi apelată dintr-un test de API. Ce se poate verifica din
// afară e chiar cusătura pe care a stat defectul: ce face butonul „Trimite
// mesaj de test" cu formularul de pe ecran.
// Fiecare probă e legată de un defect din `.claude/defecte.json` și a fost
// verificată stricând reparația la loc.
//
// Ce *nu* acoperă: salvarea propriu-zisă a setărilor și ruta de test de pe
// server — alea sunt în `.claude/test-settings.mjs`, care trimite cereri
// adevărate.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const FILE = 'Setari.dc.html';
const src = fs.readFileSync(path.join(ROOT, FILE), 'utf8');

const iScript = src.indexOf('<script type="text/x-dc"');
const logica = iScript < 0 ? '' : src.slice(iScript);

// Doar metoda `test()`: probele de mai jos vorbesc despre ce se întâmplă la
// apăsarea butoanelor de test, nu despre restul paginii.
const iTest = logica.indexOf('\n  test(channel) {');
const iEnd = iTest < 0 ? -1 : logica.indexOf('\n  renderVals()', iTest);
const testFn = iTest < 0 || iEnd < 0 ? '' : logica.slice(iTest, iEnd);

let pass = 0, fail = 0;
const check = (defect, nume, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${nume}`); }
  else { fail++; console.log(`  FAIL  ${nume}  [${defect}]${extra ? ' — ' + extra : ''}`); }
};

console.log(`\n=== ${FILE} ===`);

/* --- butonul de test ștergea modificările nesalvate ---------------------- */
// După un test reușit se chema `pull()`, care înlocuiește tot formularul cu ce
// vine de la server: tot ce era tastat sau adăugat în liste și încă nesalvat
// dispărea, iar pe ecran rămânea mesajul verde „Mesajul de test a plecat".
const iPost = testFn.indexOf('gp.post("/settings/test/');
const iGarda = testFn.search(/if\s*\(Object\.keys\(this\.changes\(\)\)\.length\)/);

check('Setari.dc.html:570', 'metoda test() se găsește în pagină',
  testFn !== '' && iPost > 0, 'nu am putut citi corpul lui test(channel)');
check('Setari.dc.html:570', 'testul nu mai pleacă peste modificări nesalvate',
  iGarda >= 0 && iPost >= 0 && iGarda < iPost,
  'oprirea lipsește sau vine după gp.post');
check('Setari.dc.html:570', 'oprirea îi cere omului să salveze întâi',
  /err:\s*"Ai modificări nesalvate\./.test(testFn) && /salvează întâi/.test(testFn));
check('Setari.dc.html:570', 'reîmprospătarea de după test nu mai rescrie orbește formularul',
  !/\.then\(\(\)\s*=>\s*this\.pull\(\)\)/.test(testFn),
  'a rămas `.then(() => this.pull())`');
check('Setari.dc.html:570', 'starea canalului se recitește doar cu formularul curat',
  /Object\.keys\(this\.changes\(\)\)\.length\s*\?\s*null\s*:\s*this\.pull\(\)/.test(testFn));

console.log(`\n  ${pass} trecute · ${fail} picate\n`);
process.exit(fail ? 1 : 0);
