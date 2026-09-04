// Caută date calendaristice scrise în cod, în paginile care ajung în browser.
//
// Prototipul era desenat pentru săptămâna 26 august – 1 septembrie 2026, iar
// datele erau trecute direct în cod: liste de zile, „ziua de azi", etichete.
// Într-o aplicație publicată, asta înseamnă că filtrele de zi rămân blocate în
// trecut și nu mai găsesc nicio comandă, iar pagina clientului anunță „3 zile
// până la comandă" pentru o comandă de azi.
//
// Defectele nu arată la fel — „2026-08-26", { v: "26" }, „miercuri 26 august" —
// de aceea căutăm tiparele separat. Ultimul dintre ele a scăpat de două ori
// unei căutări făcute în grabă.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// „Pastry Lab Board" este plansa de prezentare a prototipului, nu o pagină
// din aplicație: acolo datele fixe sunt corecte.
const SKIP = new Set(['Pastry Lab Board.dc.html', 'Canvas.dc.html', 'Previzualizare.dc.html']);

const files = [
  ...fs.readdirSync(ROOT).filter((f) => f.endsWith('.dc.html') && !SKIP.has(f)),
  ...fs.readdirSync(path.join(ROOT, 'shared')).map((f) => 'shared/' + f),
];

const LUNI = 'ianuarie|februarie|martie|aprilie|mai|iunie|iulie|august|septembrie|octombrie|noiembrie|decembrie|ian|feb|mar|apr|iun|iul|aug|sep|oct|noi|dec';

const PATTERNS = [
  // Luna și ziua se cer plauzibile. „9999-99-99" nu e o dată scrisă în cod, ci
  // un capăt de sortare: ceva care se compară mai mare decât orice zi reală, ca
  // rândurile fără dată să cadă la urmă. Nu îmbătrânește și nu are ce strica —
  // spre deosebire de o dată adevărată uitată în cod, care e tot ce caută proba.
  { re: /["']\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])["']/, why: 'dată completă scrisă în cod' },
  { re: new RegExp('\\b\\d{1,2}\\s+(' + LUNI + ')\\b', 'i'), why: 'zi și lună scrise în text' },
  { re: /\bTODAY\s*=\s*["']\d+["']/, why: '„ziua de azi" fixată în cod' },
  { re: /\bv:\s*["']\d{2}["']\s*,\s*l:\s*["']/, why: 'listă de zile scrisă în cod' },
];

// Rândurile care explică tocmai că data a fost scoasă din cod.
const EXPLANATION = /calculat|la fiecare încărcare|era scris|erau scrise|nu se scrie|generat|gp\.days\(/i;

let hits = 0;
for (const rel of files) {
  const lines = fs.readFileSync(path.join(ROOT, rel), 'utf8').split('\n');
  const found = [];

  lines.forEach((line, i) => {
    if (EXPLANATION.test(line)) return;
    // Comentariile care descriu tocmai defectul („era scris 26 august") nu
    // sunt defectul. Verificarea le raporta pe toate opt, deci raportul era
    // format exclusiv din explicații despre reparațiile deja făcute.
    if (/^\s*(\/\/|\*|\/\*)/.test(line)) return;
    for (const { re, why } of PATTERNS) {
      const m = re.exec(line);
      if (m) found.push({ n: i + 1, why, text: line.trim().slice(0, 78) });
    }
  });

  if (found.length) {
    hits += found.length;
    console.log(`  ATENȚIE ${rel}`);
    for (const f of found) console.log(`         · linia ${f.n}: ${f.why}\n           ${f.text}`);
  } else {
    console.log(`  ok      ${rel}`);
  }
}

console.log(`\n${files.length} fișiere | ${hits} ${hits === 1 ? 'dată fixă' : 'date fixe'}\n`);
process.exit(hits ? 1 : 0);
