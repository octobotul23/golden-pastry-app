// Verificări de coerență pentru fișierele SQL, fără server MySQL.
// Prinde nepotrivirile de coloane și referințele către tabele inexistente —
// erori care altfel apar abia la import, în phpMyAdmin.
//
// Se uită la TOATE fișierele din `sql/`, nu doar la primele două. Migrările
// adaugă coloane și tabele și au și ele inserări (contul „panou", etapele
// speciale, zonele de livrare, grupele de produse): o greșeală acolo pică la
// import exact ca una din schemă.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const DIR = path.join(ROOT, 'sql');

// În ordinea numerelor: 001 creează, 003 modifică, 005 modifică peste 003.
// Citite altfel, o coloană adăugată de o migrare ar părea inexistentă.
const fisiere = fs.readdirSync(DIR).filter((f) => f.endsWith('.sql')).sort();
const surse = fisiere.map((f) => [f, fs.readFileSync(path.join(DIR, f), 'utf8')]);
const schema = surse.find(([f]) => f.startsWith('001'))[1];

const problems = [];

/* --- tabelele și coloanele lor din schemă --- */
const TIPURI = 'INT|BIGINT|SMALLINT|TINYINT|VARCHAR|CHAR|TEXT|DATE|DATETIME|TIMESTAMP|DECIMAL|ENUM|JSON';
const tables = new Map();

function citesteCreate(sql) {
  for (const m of sql.matchAll(/CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\(([\s\S]*?)\n\)\s*ENGINE/g)) {
    const cols = tables.get(m[1]) || [];
    for (const line of m[2].split('\n')) {
      const t = line.trim();
      if (!t || t.startsWith('--')) continue;
      const c = t.match(new RegExp('^`?(\\w+)`?\\s+(?:' + TIPURI + ')', 'i'));
      if (c && !cols.includes(c[1])) cols.push(c[1]);
    }
    tables.set(m[1], cols);
  }
}

citesteCreate(schema);

/* --- ce adaugă și ce redenumesc migrările ---
   Instrucțiunile stau în șiruri, fiindcă MySQL n-are „ADD COLUMN IF NOT
   EXISTS" și trebuie construite din catalog. Le citim ca text: altfel
   verificarea ar spune despre o coloană adăugată de o migrare că nu există. */
for (const [nume, sql] of surse) {
  if (nume.startsWith('001')) continue;
  citesteCreate(sql);

  for (const m of sql.matchAll(/ALTER TABLE (\w+) ADD COLUMN (\w+)/g)) {
    const cols = tables.get(m[1]);
    if (!cols) { problems.push(`${nume}: adaugă o coloană în tabelul inexistent „${m[1]}"`); continue; }
    if (!cols.includes(m[2])) cols.push(m[2]);
  }
  for (const m of sql.matchAll(/ALTER TABLE (\w+) CHANGE (\w+) (\w+)/g)) {
    const cols = tables.get(m[1]);
    if (!cols) { problems.push(`${nume}: redenumește o coloană în tabelul inexistent „${m[1]}"`); continue; }
    if (!cols.includes(m[3])) cols.push(m[3]);
  }
}

/* --- cheile străine trimit către tabele care există? --- */
for (const m of schema.matchAll(/REFERENCES\s+(\w+)\s*\((\w+)\)/g)) {
  if (!tables.has(m[1])) problems.push(`cheie străină către tabelul inexistent „${m[1]}"`);
  else if (!tables.get(m[1]).includes(m[2])) {
    problems.push(`cheie străină către ${m[1]}.${m[2]} — coloana nu există`);
  }
}

/* --- INSERT-urile: coloane vs valori ---
   Tiparul cerea „INSERT INTO", dar toate inserările din proiect sunt scrise
   „INSERT IGNORE INTO" — ca re-importul să nu strice ce s-a schimbat între
   timp din aplicație. Deci bucla asta nu se potrivea cu niciuna și nu rula
   niciodată: scriptul ieșea cu „SQL coerent." orice ar fi scris în seed. */
let inserari = 0;
for (const [nume, sql] of surse) {
  for (const m of sql.matchAll(/INSERT\s+(?:IGNORE\s+)?INTO\s+(\w+)\s*\n?\s*\(([^)]+)\)\s*VALUES/g)) {
    inserari++;
    const table = m[1];
    const cols = m[2].split(',').map((s) => s.trim().replace(/`/g, ''));

    if (!tables.has(table)) {
      problems.push(`${nume}: inserează în tabelul inexistent „${table}"`);
      continue;
    }
    for (const c of cols) {
      if (!tables.get(table).includes(c)) {
        problems.push(`${nume} · ${table}: coloana „${c}" din inserare nu există în schemă`);
      }
    }

    // Numără valorile din fiecare rând al acestui INSERT. Blocul se termină la
    // „ON DUPLICATE KEY" sau la punctul și virgula de la capăt — migrările nu
    // au niciodată „ON DUPLICATE KEY", iar `indexOf` întorcea -1, adică se
    // tăia ultimul caracter din tot restul fișierului și se numărau rândurile
    // inserărilor următoare la grămadă.
    const after = sql.slice(m.index + m[0].length);
    const iDup = after.indexOf('ON DUPLICATE KEY');
    const iCap = after.indexOf(';');
    const capat = Math.min(iDup < 0 ? Infinity : iDup, iCap < 0 ? Infinity : iCap);
    const block = capat === Infinity ? after : after.slice(0, capat);

    // Numărătoarea mergea linie cu linie și cerea ca rândul să înceapă cu „(".
    // Rândul contului „panou" e scris pe două linii, fiindcă hash-ul e lung:
    // se numărau doar primele trei valori din șase. Mergem prin tot blocul,
    // ținând minte adâncimea parantezelor, ca un rând să poată ocupa oricâte
    // linii. Comentariile ies din drum întâi: un apostrof din ele („Șos.
    // Olteniței, n-are\") ar deschide un șir care nu se mai închide niciodată.
    const curat = block.split('\n').filter((l) => !l.trim().startsWith('--')).join('\n');
    let depth = 0, inStr = false, n = 0, row = 0;
    for (let i = 0; i < curat.length; i++) {
      const ch = curat[i];
      if (inStr) {
        if (ch === "'") inStr = curat[i + 1] === "'" ? (i++, true) : false;
        continue;
      }
      if (ch === "'") { inStr = true; continue; }
      if (ch === '(') { depth++; if (depth === 1) n = 1; continue; }
      if (ch === ')') {
        depth--;
        if (depth === 0) {
          row++;
          if (n !== cols.length) {
            problems.push(`${nume} · ${table}, rândul ${row}: ${n} valori pentru ${cols.length} coloane`);
          }
        }
        continue;
      }
      if (ch === ',' && depth === 1) n++;
    }
    if (row === 0) {
      problems.push(`${nume} · ${table}: inserare fără niciun rând de valori`);
    }
  }
}

// Dacă tiparul nu mai prinde nicio inserare, jumătatea de verificare de mai sus
// tace fără să spună nimic — exact defectul care a dus la rescrierea asta.
if (inserari === 0) {
  problems.push('nicio inserare găsită în sql/ — tiparul nu se mai potrivește cu fișierele');
}

const seed = surse.find(([f]) => f.startsWith('002'))[1];
console.log(`\nfișiere: ${fisiere.join(', ')}`);
console.log(`tabele: ${tables.size} (${[...tables.keys()].join(', ')})`);
console.log(`inserări verificate: ${inserari}`);
const prodCount = (seed.match(/^\s{2}\(\d+, '/gm) || []).length;
console.log(`produse în seed: ${prodCount}`);

if (problems.length) {
  console.log(`\nPROBLEME (${problems.length}):`);
  for (const p of problems) console.log('  x ' + p);
  process.exit(1);
}
console.log('SQL coerent.\n');
