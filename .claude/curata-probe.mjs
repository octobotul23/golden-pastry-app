// Scoate din baza de date urmele lăsate de teste.
//
// De ce e nevoie: `test-catalog` verifică, pe bună dreptate, că un produs deja
// comandat NU se șterge de tot, ci doar se scoate din catalog — altfel comenzile
// vechi și-ar pierde pozițiile. Ca să verifice asta, testul creează un produs,
// îl comandă și îl șterge. Produsul rămâne, inactiv, legat de comanda de probă.
//
// Prin API nu se poate face curat: nu există ștergere de comenzi (comenzile se
// anulează, nu dispar), deci produsul rămâne legat de una și nu poate fi șters
// niciodată. După treisprezece rulări, catalogul din panou arăta așa:
//
//     Ștrudel de probă 059rw   SCOS DIN CATALOG
//     Ștrudel de probă ifa3l   SCOS DIN CATALOG
//     ... încă unsprezece
//
// Pe site nu se vedeau — sunt inactive — dar pagina Catalog era de necitit.
//
// Scriptul umblă direct în MySQL, nu prin API, tocmai fiindcă API-ul nu are
// (și nu trebuie să aibă) o cale de ștergere definitivă. Rulează doar local:
// se oprește dacă baza nu e cea de dezvoltare.
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BAZA_DE_PROBA, CERE_CONFIG } from './baza-probei.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');

/** PHP știe deja să citească `api/config.php`; nu redeschidem noi fișierul. */
function phpBinary() {
  if (process.env.GP_PHP) return process.env.GP_PHP;
  const guess = 'C:/laragon/bin/php';
  try {
    const v = fs.readdirSync(guess).find((d) => d.startsWith('php-'));
    if (v) return path.join(guess, v, 'php.exe');
  } catch { /* alt sistem */ }
  return 'php';
}

function mysqlBinary() {
  if (process.env.GP_MYSQL) return process.env.GP_MYSQL;
  const guess = 'C:/laragon/bin/mysql';
  try {
    const v = fs.readdirSync(guess).find((d) => d.startsWith('mysql-'));
    if (v) return path.join(guess, v, 'bin', 'mysql.exe');
  } catch { /* alt sistem */ }
  return 'mysql';
}

let db;
try {
  const raw = execFileSync(phpBinary(), [
    '-r', '$c = require ' + CERE_CONFIG + '; echo json_encode($c["db"]);',
  ], { cwd: ROOT, encoding: 'utf8' });
  db = JSON.parse(raw);
} catch (e) {
  console.log('  —     nu pot citi api/config.php (' + e.message.split('\n')[0] + ')');
  process.exit(0);
}

// Plasă de siguranță: pe un server adevărat scriptul nu are ce căuta.
if (!BAZA_DE_PROBA.test(db.name)) {
  console.log(`  —     sărit: baza „${db.name}” nu e una de dezvoltare`);
  process.exit(0);
}

/**
 * Ce lasă testele în urmă. Fiecare tipar e legat de un test anume, ca să nu
 * ștergem din greșeală date adevărate: „probă" apare și în produse reale.
 */
/**
 * Numele sub care își trimit cererile testele. Fiecare vine dintr-un fișier
 * anume — `test-flow`, `test-settings`, `test-catalog`, `test-api` — și niciunul
 * nu seamănă cu numele unui client adevărat.
 */
const NUME_DE_TEST = [
  'Cafeneaua Lumina',    // test-flow: cererea care devine comandă
  'Client Respins',      // test-flow: cererea respinsă
  'Cafeneaua Test',      // test-api: contul de client de probă
  'Proba varianta',      // test-flow: prețul după variantă
  'Proba pret',          // test-flow: prețul calculat de server
  'Test Notificare SRL', // test-settings: notificarea de comandă nouă
  'Verificare Pachet SRL', // test-seo: comanda trimisă din pachetul construit
  'Client Anonim',       // test-api: cerere fără cont
  'Hacker',              // test-api: încearcă să scrie pe contul altuia
  'Test catalog',        // test-catalog: comanda care ține produsul ocupat
  'Test etape',          // test-settings: mutarea între etape
  'Test stoc',           // test-settings: scăderea din depozit
  'Oprirea A',           // test-settings: ordinea opririlor pe rută
  'Oprirea B',
  'Cofetăria Verificare', // test-flow: cererea scrisă de personal
  'Altcineva SRL',        // test-flow: numele care nu trebuie să treacă
  'Administrator Test',  // comenzi rămase de dinainte ca numele scris în
                         // formular să nu mai fie înlocuit cu al contului
  'Client Inițial',      // test-editare: comanda înainte de corectură
  'Client Corectat',     // test-editare: aceeași comandă, după
  'Client Mutare',       // test-editare: mutarea între zile
  'Cofetăria Cu Greșeli', // test-editare: cererea care se corectează la acceptare
  'Cofetăria Corectată',  // test-editare: comanda ieșită din ea
  'Client Zonă',         // test-editare: acceptarea cu zonă necunoscută
  'Bistro Din Greșeală', // test-editare: refuzul anulat
  'Proba Panou Mixt',    // verificare în browser: formularul cu două variante
  'Fără Articol În Depozit', // test-settings: comanda care nu se poate scădea
  'Proba Fereastra Zilei',   // test-defect-ziua-comenzii: zilele primite și cele refuzate
  'Proba Fara Zi',           // test-defect-ziua-comenzii: cererea „cât de repede se poate"
  'Proba Cursa Cerere',      // test-defect-cursa-cerere: rândul rămas strâmb
  'Probă Scădere Dublă',     // test-reparat-scadere-dubla: comenzile celor patru scene
  'Probă Câmpuri Comandă',   // test-reparat-campuri-comenzi: zona, mențiunile, pozițiile
  'Comandă urgentă',         // test-api: comanda urgentă care urcă prima în laborator
  'Client Poziții',          // test-editare: pozițiile rescrise de PATCH
  'Cofetăria Amestecată',    // test-flow: cererea cu variante amestecate
  'Cofetăria Dinaintea Ta',  // test-flow: cererea altui client
  'PROBA panou comenzi',     // test-defect-panou-comenzi: reîmprospătarea, fereastra de zile
  'Probă drepturi angajat',  // test-api: cererea pe care o scrie angajatul
  // Numele *vechi* ale probei de moduri de onorare. Azi proba le scrie
  // „Probă Onorare …”, dar bazele de dezvoltare existente au sute de rânduri
  // sub numele de dinainte — rămân aici ca să se cureţe și ele.
  'Client Curier',
  'Client Ridicare',
].map((x) => "'" + x + "'").join(', ');

/**
 * Începuturile de nume pe care le lasă probele cu sufix aleatoriu.
 *
 * Probele mai noi scriu „Brutăria Probei k3f9x2", ca două rulări să nu se
 * încurce una pe alta pe aceeași bază. Căutarea după nume exact nu le mai
 * prinde, deci se adaugă aici, ca tipar. Fiecare vine dintr-un fișier anume și
 * niciunul nu poate fi numele unui client adevărat: sufixul aleatoriu e chiar
 * partea care le deosebește de orice s-ar scrie la tejghea.
 */
const PREFIXE_DE_TEST = [
  'Brutăria Probei %',   // test-reparat-legatura-cont, test-reparat-drepturi-patch
  'Cofetăria Probei %',  // test-reparat-legatura-cont: al doilea cont
  'Client Probă %',      // test-reparat-legatura-cont: numele contului
  'Client Anonim %',     // test-reparat-drepturi-patch: comanda nelegată de cont
  'Cursa Cerere %',      // test-defect-cursa-cerere: cererile din cursă
  'Proba Cursa Cerere%', // test-defect-cursa-cerere: rândul rămas strâmb
  'Proba Retarifare%',   // test-reparat-retarifare: comanda care se recorectează
  'Probă Zăvor %',       // test-defect-zavor-stoc: cele patru scene
  'Probă Onorare %',     // test-settings: modul de onorare nou și comenzile lui
  'Probă Rețetă %',      // test-reteta-productie: comenzile pe care se raportează producția
  'Cofetăria Necunoscută %', // test-reparat-legatura-cont: contul nerecunoscut
];

/** Numele exacte SAU începuturile de mai sus, pentru o coloană anume. */
const potrivit = (col) =>
  '(' + col + ' IN (' + NUME_DE_TEST + ')'
    + PREFIXE_DE_TEST.map((p) => " OR " + col + " LIKE '" + p + "'").join('')
    + ')';

const CURATENIE = [
  {
    ce: 'produse de probă',
    sql: `DELETE FROM products WHERE slug LIKE 'strudel-de-proba-%' AND active = 0`,
    // Rândurile din comenzi trebuie să plece primele: cheia străină le ține.
    inainte: [
      // Articolele de depozit legate de produsele de probă, cu mișcările lor.
      // `test-settings` creează un articol ca să arate că o comandă blocată se
      // scade abia după ce apare articolul; fără curățenia asta, fila Depozit
      // s-ar umple cu „Ștrudel de probă …", exact ca odinioară Catalogul.
      // Se leagă de produs (`product_id`), nu de nume: e legătura pe care o
      // scrie chiar testul, deci nu poate prinde un articol adevărat.
      `DELETE m FROM stock_moves m JOIN stock_items i ON i.id = m.item_id
         JOIN products p ON p.id = i.product_id
         WHERE p.slug LIKE 'strudel-de-proba-%' AND p.active = 0`,
      `DELETE i FROM stock_items i JOIN products p ON p.id = i.product_id
         WHERE p.slug LIKE 'strudel-de-proba-%' AND p.active = 0`,
      `DELETE oi FROM order_items oi JOIN products p ON p.id = oi.product_id
         WHERE p.slug LIKE 'strudel-de-proba-%' AND p.active = 0`,
      `DELETE ri FROM order_request_items ri JOIN products p ON p.id = ri.product_id
         WHERE p.slug LIKE 'strudel-de-proba-%' AND p.active = 0`,
    ],
  },
  {
    // O comandă fără nicio poziție nu spune nimic: nu are ce se face în
    // laborator, nu are ce se încărca în mașină, nu are preț. Aplicația nu
    // creează așa ceva — apar doar aici, când produsul de probă pleacă și
    // lasă comanda de probă goală. Le-am filtrat întâi după numele clientului
    // și au rămas nouăsprezece pe dinafară, fiindcă testul comandă pe contul
    // cu care e autentificat, nu pe un nume ales de el.
    ce: 'comenzi rămase fără produse',
    sql: `DELETE o FROM orders o
           WHERE NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id)`,
  },
  {
    // Cererile de probă se adunau la fel ca produsele: fiecare rulare a
    // testelor lasă în urmă câteva, iar fila „Cereri de pe site" din
    // administrare ajunsese să arate treisprezece cereri de la „Proba
    // varianta" și „Client Respins" în fața celor adevărate.
    //
    // Ștergem doar numele pe care le scriu testele, nu un tipar de genul
    // „conține «probă»": un client adevărat se poate numi „Patiseria de
    // probă", iar o curățenie care ghicește ar șterge comenzi reale.
    ce: 'cereri de probă',
    inainte: [
      `DELETE ri FROM order_request_items ri JOIN order_requests r ON r.id = ri.request_id
         WHERE ${potrivit('r.customer_name')}`,
      // Comanda născută dintr-o cerere de probă pleacă odată cu ea.
      `DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id
         JOIN order_requests r ON r.id = o.request_id
         WHERE ${potrivit('r.customer_name')}`,
      `DELETE n FROM notifications n JOIN orders o ON o.id = n.order_id
         JOIN order_requests r ON r.id = o.request_id
         WHERE ${potrivit('r.customer_name')}`,
      // Legătura în ambele sensuri se rupe întâi, altfel cheile străine
      // dintre cerere și comandă nu lasă niciuna dintre ele să plece.
      `UPDATE order_requests r SET r.order_id = NULL
         WHERE ${potrivit('r.customer_name')}`,
      `DELETE o FROM orders o JOIN order_requests r ON r.id = o.request_id
         WHERE ${potrivit('r.customer_name')}`,
    ],
    sql: `DELETE FROM order_requests WHERE ${potrivit('customer_name')}`,
  },
  {
    // Comenzile de probă se adunau la fel: cinci sute în registru, toate din
    // aceeași zi, toate de la teste. Panoul de administrare le număra în bara
    // laterală („Comenzi 500"), iar registrul pe șapte zile era de necitit.
    ce: 'comenzi de probă',
    inainte: [
      `DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id
         WHERE ${potrivit('o.customer_name')}`,
      `DELETE n FROM notifications n JOIN orders o ON o.id = n.order_id
         WHERE ${potrivit('o.customer_name')}`,
      `UPDATE order_requests r JOIN orders o ON o.id = r.order_id
         SET r.order_id = NULL WHERE ${potrivit('o.customer_name')}`,
    ],
    sql: `DELETE FROM orders WHERE ${potrivit('customer_name')}`,
  },
  {
    // `test-defect-zavor-stoc` își face singură produsele ȘI articolele de
    // depozit legate de ele, ca cifrele din registru să fie absolute și să nu
    // depindă de ce a lăsat altă probă. Numele lor nu începe cu „Ștrudel de
    // probă", deci regula de mai sus nu le prinde, iar pagina Depozit s-ar
    // umple cu „Probă zăvor cantitate zavor…" la fiecare rulare a suitei.
    ce: 'articole și produse de la proba de zăvor',
    inainte: [
      `DELETE m FROM stock_moves m JOIN stock_items i ON i.id = m.item_id
         WHERE i.name LIKE 'Probă zăvor %'`,
      `DELETE FROM stock_items WHERE name LIKE 'Probă zăvor %'`,
      `DELETE oi FROM order_items oi JOIN products p ON p.id = oi.product_id
         WHERE p.name LIKE 'Probă zăvor %'`,
      `DELETE ri FROM order_request_items ri JOIN products p ON p.id = ri.product_id
         WHERE p.name LIKE 'Probă zăvor %'`,
    ],
    sql: `DELETE FROM products WHERE name LIKE 'Probă zăvor %'`,
  },
  {
    // `test-reparat-depozit-refuzuri` face articole de depozit ca să încerce
    // pe ele numele prea lungi și numele deja luat. Nu sunt legate de niciun
    // produs, deci regula de mai sus nu le prinde, iar pagina Depozit s-ar
    // umple cu „Probă Refuz …" la fiecare rulare a suitei.
    ce: 'articole de la proba de refuzuri din depozit',
    inainte: [
      `DELETE m FROM stock_moves m JOIN stock_items i ON i.id = m.item_id
         WHERE i.name LIKE 'Probă Refuz %'`,
    ],
    sql: `DELETE FROM stock_items WHERE name LIKE 'Probă Refuz %'`,
  },
  {
    // `test-reteta-productie` face produse ȘI articole de depozit, iar între
    // ele rețete. Ordinea de aici e obligatorie, nu de gust: `fk_recipe_item`
    // e ON DELETE RESTRICT — un articol folosit într-o rețetă nu se șterge din
    // greșeală. Deci întâi cad rețetele, abia apoi articolele. Rețetele legate
    // de produs ar cădea și singure, prin CASCADE, dar produsul se șterge
    // ultimul, iar articolul n-are cum să aștepte până atunci.
    ce: 'rețete, articole și produse de la proba de rețete',
    inainte: [
      `DELETE r FROM product_recipes r JOIN stock_items i ON i.id = r.item_id
         WHERE i.name LIKE 'Probă Rețetă %'`,
      `DELETE r FROM product_recipes r JOIN products p ON p.id = r.product_id
         WHERE p.name LIKE 'Probă Rețetă %'`,
      `DELETE m FROM stock_moves m JOIN stock_items i ON i.id = m.item_id
         WHERE i.name LIKE 'Probă Rețetă %'`,
      `DELETE FROM stock_items WHERE name LIKE 'Probă Rețetă %'`,
      `DELETE oi FROM order_items oi JOIN products p ON p.id = oi.product_id
         WHERE p.name LIKE 'Probă Rețetă %'`,
      `DELETE ri FROM order_request_items ri JOIN products p ON p.id = ri.product_id
         WHERE p.name LIKE 'Probă Rețetă %'`,
    ],
    sql: `DELETE FROM products WHERE name LIKE 'Probă Rețetă %'`,
  },
  {
    // `test-reparat-scadere-dubla` face articole de depozit purtând numele
    // produsului de probă, iar unele NELEGATE de vreun produs — chiar asta
    // măsoară: ramura pe nume. Regula de mai sus le prinde doar pe cele legate,
    // prin `product_id`, deci restul rămâneau în Depozit de la o rulare la alta,
    // împreună cu mișcările lor. Iar mișcările vechi făceau proba să numere „2
    // mișcări" acolo unde codul făcuse una, fiindcă ziua probei e fixă și
    // referințele se repetă.
    //
    // Numele e cel scris de probă, cu sufix aleatoriu: nu poate fi al unui
    // articol adevărat.
    ce: 'articole de depozit de la proba de scădere dublă',
    inainte: [
      `DELETE m FROM stock_moves m JOIN stock_items i ON i.id = m.item_id
         WHERE i.name LIKE 'Ștrudel de probă %'`,
    ],
    sql: `DELETE FROM stock_items WHERE name LIKE 'Ștrudel de probă %'`,
  },
  {
    // Conturile de probă se adunau în fila Conturi la fel ca produsele în
    // Catalog: două la fiecare rulare a probelor de drepturi și de legătură cu
    // contul. Toate cheile străine care arată spre `users` sunt ON DELETE SET
    // NULL sau CASCADE, deci ștergerea nu rupe comenzi vechi — le lasă doar
    // fără cont, ceea ce e adevărat: contul chiar nu mai există.
    //
    // Conturile așezate de instalare — `testadmin`, `testangajat`,
    // `testclient` — nu încep cu „proba", deci rămân pe loc.
    ce: 'conturi de probă',
    sql: `DELETE FROM users WHERE username LIKE 'proba%'`,
  },
  {
    ce: 'grupe de probă goale',
    sql: `DELETE c FROM product_categories c
           WHERE c.slug LIKE 'grupa-de-proba%'
             AND NOT EXISTS (SELECT 1 FROM products p WHERE p.category = c.slug)`,
  },
];

function ruleaza(sql) {
  const out = execFileSync(mysqlBinary(), [
    // Fără asta, clientul mysql de pe Windows citește instrucțiunea în
    // codepage-ul consolei, nu în UTF-8: „Cofetăria Verificare” ajunge la
    // server stricat și nu se potrivește cu nimic. Regulile scrise cu
    // diacritice treceau tăcut pe lângă rândurile pe care trebuiau să le
    // șteargă, iar curățenia raporta că a terminat.
    '--default-character-set=utf8mb4',
    '-u', db.user, ...(db.pass ? ['-p' + db.pass] : []),
    '-h', db.host, '-P', String(db.port), db.name,
    '-N', '-B', '-e', sql + '; SELECT ROW_COUNT();',
  ], { encoding: 'utf8' });
  return parseInt(out.trim().split('\n').pop(), 10) || 0;
}

let total = 0;
try {
  for (const { ce, sql, inainte } of CURATENIE) {
    for (const pre of inainte || []) ruleaza(pre);
    const n = ruleaza(sql);
    total += n;
    if (n) console.log(`  ${String(n).padStart(3)} ${ce}`);
  }
} catch (e) {
  console.log('  —     nu pot curăța (' + String(e.message).split('\n')[0] + ')');
  process.exit(0);
}

const urme = total === 1 ? 'o urmă' : `${total} urme`;
console.log(total ? `\nȘters, din „${db.name}”: ${urme} de la teste.`
                  : `Nimic de curățat în „${db.name}”.`);

/* --------------------------------------------------------------------------
 * Ce a rămas pe dinafară.
 *
 * Listele de mai sus se scriu de mână, iar o probă nouă cu un nume nou nu
 * apare nicăieri: nu pică nimic, nu se plânge nimeni, rândurile doar se adună.
 * Când le-am numărat prima oară, baza de dezvoltare avea aproape șapte sute de
 * comenzi de probă din opt nume neînregistrate — și una dintre ele („Client
 * Curier", cu un mod de onorare botezat aleatoriu) ajunsese să fie raportată
 * ca defect al aplicației: „curier fx2ep, ce înseamnă?".
 *
 * Deci nu ne mai bazăm pe memorie. Culegem numele chiar din fișierele probelor
 * și întrebăm baza ce a supraviețuit curățeniei. Un nume refuzat de server
 * (gol, prea lung, produs inexistent) n-are rânduri, deci trece de la sine —
 * raportul vorbește numai despre ce chiar s-a scris și a rămas.
 * ------------------------------------------------------------------------ */

/**
 * Un rând de LIKE. Numele cu joker în ele („%", „_") se sar cu totul, în loc
 * să fie scăpate: probele n-au așa nume, iar o escapare scrisă degeaba e o
 * bucată de cod pe care n-o verifică nimeni.
 */
const caLike = (t) => t.replace(/'/g, "''");
const areJoker = (t) => t.indexOf('%') >= 0 || t.indexOf('_') >= 0;

/**
 * Numele de client scrise de probe, culese din fișierele lor.
 *
 * Recunoaștem patru forme, fiindcă atâtea folosesc probele:
 *   customerName: 'Nume'          → nume exact
 *   customerName: 'Nume ' + sufix → început de nume
 *   customerName: NUME            → se caută `const NUME = …` în același fișier
 *   customerName: `Nume ${x}`     → început de nume
 * Restul („'A'.repeat(300)", „o.cust || ''") se sare: nu sunt nume, sunt scene.
 */
function numeleDinProbe() {
  const tipare = new Map();                    // tipar → fișierele care-l scriu
  const pune = (text, exact, fis) => {
    const t = String(text || '').trim();
    if (!t) return;
    const cheie = (exact ? '=' : '~') + t;
    if (!tipare.has(cheie)) tipare.set(cheie, { text: t, exact, fis: new Set() });
    tipare.get(cheie).fis.add(fis);
  };

  for (const f of fs.readdirSync(HERE).filter((x) => /^test-.*\.mjs$/.test(x))) {
    const src = fs.readFileSync(path.join(HERE, f), 'utf8');

    // Variabilele din același fișier, ca „customerName: NUME" să fie urmărit
    // până la textul lui. Cele compuse rămân începuturi, nu nume întregi.
    const vars = new Map();
    for (const m of src.matchAll(/\bconst\s+([A-Za-z_$][\w$]*)\s*=\s*'([^'\n]*)'\s*(\+?)/g)) {
      vars.set(m[1], { text: m[2], exact: m[3] !== '+' });
    }
    for (const m of src.matchAll(/\bconst\s+([A-Za-z_$][\w$]*)\s*=\s*`([^`\n$]*)\$\{/g)) {
      vars.set(m[1], { text: m[2], exact: false });
    }

    for (const m of src.matchAll(/customerName:\s*([^,\n}]+)/g)) {
      const e = m[1].trim();
      let g;
      if ((g = e.match(/^'([^']*)'\s*$/)))            pune(g[1], true, f);
      else if ((g = e.match(/^"([^"]*)"\s*$/)))       pune(g[1], true, f);
      else if ((g = e.match(/^'([^']*)'\s*\+/)))      pune(g[1], false, f);
      else if ((g = e.match(/^`([^`$]*)\$\{/)))       pune(g[1], false, f);
      else if ((g = e.match(/^([A-Za-z_$][\w$]*)\s*(\+?)/)) && vars.has(g[1])) {
        const v = vars.get(g[1]);
        pune(v.text, v.exact && g[2] !== '+', f);
      }
    }
  }
  return [...tipare.values()];
}

/** Rândurile unei interogări, ca tablou de tablouri. */
function intreaba(sql) {
  const out = execFileSync(mysqlBinary(), [
    '--default-character-set=utf8mb4',
    '-u', db.user, ...(db.pass ? ['-p' + db.pass] : []),
    '-h', db.host, '-P', String(db.port), db.name,
    '-N', '-B', '-e', sql,
  ], { encoding: 'utf8' });
  return out.split('\n').filter((l) => l.trim()).map((l) => l.split('\t'));
}

let resturi = [];
try {
  const tipare = numeleDinProbe();
  const exacte = tipare.filter((t) => t.exact).map((t) => "'" + t.text.replace(/'/g, "''") + "'");
  const inceputuri = tipare.filter((t) => !t.exact && !areJoker(t.text))
    .map((t) => "'" + caLike(t.text) + "%'");
  const cond = (col) => '(' + (exacte.length ? col + ' IN (' + exacte.join(', ') + ')' : '1=0')
    + inceputuri.map((p) => ' OR ' + col + ' LIKE ' + p).join('') + ')';

  resturi = intreaba(
    `SELECT 'comenzi', customer_name, COUNT(*) FROM orders WHERE ${cond('customer_name')} GROUP BY customer_name`
    + ' UNION ALL '
    + `SELECT 'cereri', customer_name, COUNT(*) FROM order_requests WHERE ${cond('customer_name')} GROUP BY customer_name`
  );

  if (resturi.length) {
    const dinCe = (nume) => tipare
      .filter((t) => (t.exact ? nume === t.text : nume.startsWith(t.text)))
      .flatMap((t) => [...t.fis]).filter((v, i, a) => a.indexOf(v) === i).join(', ');
    console.log('\n  Rânduri de probă rămase în „' + db.name + '”, pe nume neînregistrate:');
    for (const [tabel, nume, n] of resturi) {
      console.log(`  ${String(n).padStart(5)} ${tabel.padEnd(8)} „${nume}”  ← ${dinCe(nume) || 'nu știu din ce probă'}`);
    }
    console.log('\n  Adaugă numele în NUME_DE_TEST sau în PREFIXE_DE_TEST, mai sus.');
    console.log('  Dacă numele e prea general ca să fie șters fără grijă, redenumește-l');
    console.log('  în probă („Probă …"), ca aici să nu ajungă niciodată un client adevărat.');
  }
} catch (e) {
  console.log('  —     nu pot număra resturile (' + String(e.message).split('\n')[0] + ')');
}

if (resturi.length) process.exit(1);
