// Verifică blocurile <script type="text/x-dc"> ale paginilor .dc.html:
// sintaxa și metodele apelate dar inexistente.
//
// Runtime-ul „dc" compilează aceste blocuri în browser, deci o greșeală nu
// apare la încărcarea fișierului, ci ca pagină goală cu un „Unexpected token"
// — fără linie și fără fișier. Aici o localizăm.
//
// Verificarea metodelor există fiindcă o greșeală de tipul „this.persistSeq is
// not a function" trece nevăzută de parser: pagina se încarcă, arată corect și
// crapă abia când cineva apasă butonul care o folosește. Exact așa a rămas un
// apel către o metodă ștearsă la curățenia localStorage.
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

// Rădăcina paginilor se poate muta prin GP_PAGES_ROOT. Nu e pentru rulările
// obișnuite, ci pentru proba din .claude/test-defect-unelte.mjs: ea pune
// verificarea pe pagini-fixtură scrise anume greșit, ca să arate că poate să
// pice. O verificare pe care n-o poți vedea picând nu dovedește nimic.
const ROOT = process.env.GP_PAGES_ROOT
  ? path.resolve(process.env.GP_PAGES_ROOT)
  : path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const pages = fs.readdirSync(ROOT).filter((f) => f.endsWith('.dc.html')).sort();

// Metode oferite de runtime („dc") sau de React, nu de pagină.
const INHERITED = new Set([
  'setState', 'forceUpdate', 'render', 'renderVals', 'state', 'props',
  'componentDidMount', 'componentWillUnmount', 'componentDidUpdate',
]);

/** Numele metodelor definite în corpul clasei, plus câmpurile-funcție. */
function definedMembers(code) {
  const out = new Set();
  // `nume(...) {` la început de linie, în corpul clasei
  for (const m of code.matchAll(/^\s{2}(?:async\s+)?([A-Za-z_$][\w$]*)\s*\(/gm)) {
    out.add(m[1]);
  }
  // `nume = ...` (câmp de clasă) și `this.nume = ...` (atribuit la runtime)
  for (const m of code.matchAll(/^\s{2}([A-Za-z_$][\w$]*)\s*=/gm)) out.add(m[1]);
  for (const m of code.matchAll(/this\.([A-Za-z_$][\w$]*)\s*=/g)) out.add(m[1]);
  return out;
}

/** Codul fără comentarii și fără conținutul șirurilor de caractere. */
function faraTexte(code) {
  let out = '';
  for (let i = 0; i < code.length; ) {
    const c = code[i], d = code[i + 1];
    if (c === '/' && d === '/') { while (i < code.length && code[i] !== '\n') i++; continue; }
    if (c === '/' && d === '*') { i += 2; while (i < code.length && !(code[i] === '*' && code[i + 1] === '/')) i++; i += 2; continue; }
    if (c === '"' || c === "'" || c === '`') {
      const q = c; i++;
      while (i < code.length && code[i] !== q) { if (code[i] === '\\') i++; i++; }
      i++; out += '""'; continue;
    }
    out += c; i++;
  }
  return out;
}

/**
 * Numele scrise cu majuscule care sunt folosite, dar nu declarate nicăieri.
 *
 * Un nume liber nu supără parserul: se caută abia la rulare. Așa a rămas în
 * Panou Laborator, după curățenia localStorage, un `OKEY` pe care nu-l mai
 * definea nimic — ascultătorul de „storage" arunca ReferenceError la fiecare
 * eveniment și reîmprospătarea între file n-a funcționat niciodată, fără ca
 * cineva să vadă ceva pe ecran.
 *
 * Ne uităm doar la numele de constante (CU_MAJUSCULE, cel puțin trei litere):
 * acolo greșeala e aproape sigură, iar zgomotul, zero. Un nume declarat pe
 * orice cale — `const`, parametru, destructurare — trece; preferăm să scăpăm
 * un defect decât să strigăm degeaba.
 */
const GLOBALE = new Set(['JSON', 'URL', 'NaN', 'API']);
function constanteNedeclarate(code) {
  const c = faraTexte(code);
  const rele = new Set();
  for (const m of c.matchAll(/(^|[^.\w$])([A-Z][A-Z0-9_]{2,})\b(?!\s*:)/g)) {
    const nume = m[2];
    if (GLOBALE.has(nume) || rele.has(nume)) continue;
    const declarat =
      new RegExp('\\b(?:const|let|var|class|function)\\b[^;\\n]*\\b' + nume + '\\b').test(c)
      || new RegExp('\\([^()]*\\b' + nume + '\\b[^()]*\\)\\s*=>').test(c)
      || new RegExp('\\bfunction\\s*\\w*\\s*\\([^()]*\\b' + nume + '\\b').test(c);
    if (!declarat) rele.add(nume);
  }
  return [...rele];
}

/**
 * Cheile scrise de două ori în obiectul de stare, plus dacă am găsit obiectul.
 *
 * JavaScript acceptă în tăcere `{ page: 1, page: "Toate" }` și păstrează
 * ultima valoare. Într-un obiect de stare cu treizeci de câmpuri asta trece
 * neobservat: cheia adăugată peste una existentă pare că nu face nimic, iar
 * cealaltă își pierde valoarea inițială.
 *
 * Căutăm acolada pereche în loc să cerem obiectul pe un singur rând, și
 * primim și forma `this.state = {`. Tiparul dinainte le cerea pe amândouă
 * deodată și sărea în tăcere peste 6 din 14 pagini — printre ele cele mai
 * mari trei, adică exact acelea unde greșeala e de așteptat. O verificare
 * care nu rulează e mai rea decât una care lipsește: lipsa se vede.
 *
 * Ne uităm la codul fără șiruri și fără comentarii, ca să nu numărăm acolade
 * dintr-un text și nici chei dintr-un exemplu comentat.
 */
function stareaPaginii(code) {
  const c = faraTexte(code);
  const dubluri = new Set();
  let gasit = false;

  for (const m of c.matchAll(/^[ \t]*(?:this\.)?state\s*=\s*\{/gm)) {
    gasit = true;
    const start = m.index + m[0].length;      // imediat după acolada deschisă
    let adanc = 1, i = start;
    for (; i < c.length && adanc > 0; i++) {
      const ch = c[i];
      if (ch === '{' || ch === '[' || ch === '(') adanc++;
      else if (ch === '}' || ch === ']' || ch === ')') adanc--;
    }
    const corp = c.slice(start, i - 1);

    // Doar cheile de la primul nivel: `{ filtre: { zi: 1 }, zi: 2 }` e corect,
    // iar o cheie repetată într-un obiect imbricat e altă poveste.
    const bucati = [];
    let d = 0, buf = '';
    for (const ch of corp) {
      if (ch === '{' || ch === '[' || ch === '(') d++;
      else if (ch === '}' || ch === ']' || ch === ')') d--;
      if (ch === ',' && d === 0) { bucati.push(buf); buf = ''; continue; }
      buf += ch;
    }
    bucati.push(buf);

    const chei = bucati
      .map((p) => (p.match(/^\s*([A-Za-z_$][\w$]*)\s*:/) || [])[1])
      .filter(Boolean);
    for (const k of chei.filter((k, idx) => chei.indexOf(k) !== idx)) dubluri.add(k);
  }

  return { gasit, dubluri: [...dubluri] };
}

/** Linia din fișier pentru prima apariție a unui text. */
function lineOf(src, needle) {
  const i = src.indexOf(needle);
  return i < 0 ? null : src.slice(0, i).split('\n').length;
}

let bad = 0;
// Câte pagini au avut obiectul de stare găsit. Îl scriem în rezumat fiindcă
// tocmai asta a lipsit ani de zile: verificarea sărea peste pagini fără să
// spună, iar suita raporta verde. Dacă numărul scade, se vede pe loc.
let cuStare = 0;
for (const p of pages) {
  const src = fs.readFileSync(path.join(ROOT, p), 'utf8');
  const m = src.match(/<script[^>]*type="text\/x-dc"[^>]*>([\s\S]*?)<\/script>/);
  if (!m) {
    console.log(`  —     ${p} (fără bloc de script)`);
    continue;
  }

  const offset = src.slice(0, m.index).split('\n').length;
  const code = m[1];
  const problems = [];

  try {
    // Clasele cu câmpuri (`state = {...}`) cer un parser modern; `new vm.Script`
    // folosește chiar parserul V8, același ca browserul.
    new vm.Script(code, { filename: p });
  } catch (e) {
    bad++;
    const line = e.lineNumber ?? (e.stack.match(/:(\d+)\)?\n/) || [])[1];
    const abs = line ? offset + Number(line) - 1 : null;
    console.log(`  EROARE ${p}${abs ? ` — linia ~${abs}` : ''}: ${e.message}`);
    if (abs) {
      const all = src.split('\n');
      for (let i = Math.max(0, abs - 3); i < Math.min(all.length, abs + 2); i++) {
        console.log(`${String(i + 1).padStart(6)} ${i + 1 === abs ? '>' : ' '} ${all[i].slice(0, 150)}`);
      }
    }
    continue;   // fără sintaxă validă, restul verificărilor n-au sens
  }

  // --- metode apelate dar nedefinite -------------------------------------
  const members = definedMembers(code);
  const calledSeen = new Set();
  for (const c of code.matchAll(/this\.([A-Za-z_$][\w$]*)\s*\(/g)) {
    const name = c[1];
    if (calledSeen.has(name) || members.has(name) || INHERITED.has(name)) continue;
    calledSeen.add(name);
    const ln = lineOf(src, `this.${name}(`);
    problems.push(`metoda this.${name}() este apelată${ln ? ` (linia ${ln})` : ''} dar nu există în clasă`);
  }

  // --- chei duplicate în `state = { ... }` --------------------------------
  const stare = stareaPaginii(code);
  if (stare.gasit) cuStare++;
  for (const k of stare.dubluri) {
    problems.push(`starea are cheia „${k}" de două ori — a doua o înlocuiește pe prima`);
  }

  // --- constante folosite dar nedeclarate ---------------------------------
  for (const nume of constanteNedeclarate(code)) {
    const ln = lineOf(src, nume);
    problems.push(`„${nume}" e folosit${ln ? ` (linia ${ln})` : ''} dar nu e declarat nicăieri în bloc — la rulare dă ReferenceError`);
  }

  // --- listele configurabile trebuie și cerute de la server ---------------
  //
  // gp.done() / gp.flow() răspund sincron, dintr-o copie locală, pe care o
  // umple gp.lists(). O pagină care le folosește fără să ceară listele merge
  // mai departe pe valorile implicite din cod și pare că funcționează — până
  // când cineva redenumește o etapă din Setări și nimic nu se mai potrivește.
  const usesLists = /gp\.(done|cancelled|flow|statuses|hq|zonePoint)\s*\(/.test(code)
    || /gp\.__lists/.test(code);
  if (usesLists && !code.includes('gp.lists(')) {
    problems.push('folosește gp.done()/gp.flow() dar nu apelează gp.lists() — ar rămâne pe etapele scrise în cod');
  }

  if (problems.length) {
    bad++;
    console.log(`  EROARE ${p}`);
    for (const pr of problems) console.log(`         · ${pr}`);
  } else {
    console.log(`  ok    ${p}`);
  }
}

console.log(`\n${pages.length} pagini | ${cuStare} cu starea verificată | ${bad} cu probleme\n`);
process.exit(bad ? 1 : 0);
