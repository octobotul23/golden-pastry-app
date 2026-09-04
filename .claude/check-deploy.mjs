// Probele de regresie pentru pachetul de publicare — `.claude/build-deploy.mjs`.
//
//   node .claude/check-deploy.mjs
//
// Verificarea citește scriptul de construcție, nu folderul `deploy/`. Ca să se
// uite în `deploy/`, proba ar trebui să-l construiască din nou, iar
// construcția începe prin a-l șterge — exact folderul pe care îl ține deschis
// serverul pachetului de pe portul 8101, cel de care are nevoie `test-seo.mjs`.
// Într-o rulare completă (`verifica-tot.mjs` pornește verificările statice
// înaintea testelor) o probă care reconstruiește pachetul ar pica singură cu
// „EPERM" și ar lăsa în urmă un `deploy/` șters pe jumătate. Așa că ne uităm la
// textul din care se nasc fișierele, iar pentru rescrierea paginilor aplicăm
// chiar rescrierea din build pe pagina adevărată și îi evaluăm rezultatul.
//
// Fiecare probă e legată de un defect din `.claude/defecte.json` și a fost
// verificată stricând reparația la loc.

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const build = fs.readFileSync(path.join(HERE, 'build-deploy.mjs'), 'utf8');

let pass = 0, fail = 0;
const check = (defect, nume, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${nume}`); }
  else { fail++; console.log(`  FAIL  ${nume}  [${defect}]${extra ? ' — ' + extra : ''}`); }
};

console.log('\n=== .claude/build-deploy.mjs ===');

/* --- poza din antetul paginii de start, blocată de propriul .htaccess ---- */
// Imaginile sloturilor care n-au „src" în marcaj stau în sidecar, iar
// image-slot.js îl cere cu fetch la fiecare deschidere a paginii. Cele două
// reguli FilesMatch îl prind de două ori — o dată după extensie, o dată după
// punctul din față — și 403-ul nu se vede nicăieri: fetch-ul e înghițit.
const SIDECAR = '.image-slots.state.json';
const sidecar = JSON.parse(fs.readFileSync(path.join(ROOT, SIDECAR), 'utf8'));
const start = fs.readFileSync(path.join(ROOT, 'Site Public.dc.html'), 'utf8');

// Premisa se citește din COD, nu din pagină. Două variante dinainte au greșit
// aici: prima pornea de la cheile sidecarului și pica ori de câte ori fișierul
// era gol — adică raporta defecțiune de publicare pentru o stare normală; a
// doua cerea să existe sloturi fără „src", și a picat în clipa în care toate
// pozele au primit „src", adică exact când starea s-a îmbunătățit.
//
// Ce ține pericolul viu e altceva, și nu se schimbă când umpli sloturile:
// `image-slot.js:174` cheamă `fetch(STATE_FILE)` o singură dată, necondiționat,
// la fiecare deschidere a paginii; iar la `image-slot.js:1109` scrie
// `const url = this._userUrl || srcAttr`, adică ce vine din sidecar BATE
// „src"-ul din marcaj. Deci fișierul e cerut, și contează pentru orice slot,
// gol sau nu — atâta vreme cât pagina duce scriptul cu ea. Un 403 pe el n-ar
// arunca nicio eroare: `.catch(() => {})` de la `image-slot.js:194` îl înghite,
// iar omul care tocmai a pus o poză cu mouse-ul ar vedea-o dispărând tăcut la
// următoarea deschidere a paginii.
const slotJs = fs.readFileSync(path.join(ROOT, 'image-slot.js'), 'utf8');
check('.claude/build-deploy.mjs:182', 'pagina de start chiar cere fișierul de stare la fiecare deschidere',
  /<script[^>]+src="\.\/image-slot\.js"/.test(start)
  && slotJs.includes('fetch(STATE_FILE)')
  && slotJs.includes('const url = this._userUrl || srcAttr'),
  'pagina nu mai duce image-slot.js, sau scriptul nu mai citește din sidecar');
check('.claude/build-deploy.mjs:182', 'pachetul public duce mai departe sidecarul',
  new RegExp("root:[^\\]]*'\\.image-slots\\.state\\.json'").test(build));

const iExt   = build.indexOf('(sql|md|mjs|json|bak|log)$">');
const iPunct = build.indexOf('(image-slots|thumbnail)">');
const iVoie  = build.indexOf('<Files "' + SIDECAR + '">');
check('.claude/build-deploy.mjs:182', '.htaccess lasă să treacă fișierul din care se încarcă poza din antet',
  iVoie > iExt && iVoie > iPunct
  && /<Files "\.image-slots\.state\.json">\s*\n\s*Require all granted/.test(build),
  iVoie < 0 ? 'nicio excepție pentru sidecar, deci fetch-ul primește 403'
            : 'excepția e scrisă înaintea interdicțiilor, deci nu le anulează');

/* --- miniaturile din Catalog, cerute de la subdomeniul greșit ------------ */
// API-ul scrie în bază adrese relative, iar pagina Catalog le pune direct în
// `src`. Pe lab.goldenpastry.ro ele ajung la un subdomeniu care nu are
// fișierele; fotografiile stau într-un singur loc, lângă site-ul public.
const produse = fs.readFileSync(path.join(ROOT, 'Produse.dc.html'), 'utf8');
check('.claude/build-deploy.mjs:58', 'pagina Catalog pune adresa primită de la API direct în „src"',
  /<img data-pr="thumb"[^>]*\ssrc="\{\{ p\.photo \}\}"/.test(produse));

const iNoindex = build.indexOf('const HT_NOINDEX');
const iApi     = build.indexOf('const HT_API');
const iPoze    = build.indexOf('RewriteRule ^products/(.+)$ https://${DOMAIN}/products/$1 [R=302,L]');
check('.claude/build-deploy.mjs:58', 'panourile trimit cererile de poze acolo unde stau fișierele',
  iPoze > iNoindex && iPoze < iApi,
  iPoze < 0 ? 'nicio regulă pentru products/ în .htaccess-ul panourilor'
            : 'regula nu e în blocul panourilor');

/* --- robots.txt-ul panourilor ascundea tocmai antetul „noindex" ---------- */
const robots = (build.match(/const ROBOTS_PRIVATE = `([\s\S]*?)`;/) || [])[1] || '';
check('.claude/build-deploy.mjs:262', 'robots.txt-ul panourilor nu mai interzice crawlarea',
  robots !== '' && !/^Disallow: \/\s*$/m.test(robots), 'încă scrie „Disallow: /"');
check('.claude/build-deploy.mjs:262', 'robotul are voie să intre ca să citească interdicția',
  /^Allow: \/\s*$/m.test(robots));
check('.claude/build-deploy.mjs:262', 'interdicția rămâne pusă în antet',
  /X-Robots-Tag "noindex, nofollow, noarchive"/.test(build));

/* --- rescrierea interceptorului întorcea condiția pe dos ----------------- */
// Aplicăm exact rescrierea din build pe linia adevărată din fiecare pagină
// publicată și evaluăm condiția rezultată. Așa proba nu se uită la text, ci la
// ce va face pagina în browserul vizitatorului.
const rescrie = build.match(/\.split\('([^']+)'\)\.join\('([^']+)'\)/);
const cauta = rescrie ? rescrie[1].replace(/\\\\/g, '\\') : '';
const pune  = rescrie ? rescrie[2].replace(/\\\\/g, '\\') : '';
const pagini = [...build.matchAll(/\{ src: '([^']+)'/g)].map((m) => m[1]);

// Ce trebuie să facă regula pentru fiecare fel de adresă care apare în pagini.
// „iese" înseamnă că interceptorul nu se atinge de legătură.
const ADRESE = [
  ['https://clienti.goldenpastry.ro/',    true,  'legătură absolută între subdomenii'],
  ['https://lab.goldenpastry.ro/comenzi', true,  'legătură absolută între subdomenii'],
  ['mailto:comenzi@goldenpastry.ro',      true,  'adresă de e-mail'],
  ['tel:+40712345678',                    true,  'număr de telefon'],
  ['./',                                  false, 'pagina de start a aceluiași subdomeniu'],
  ['comenzi',                             false, 'pagina vecină, fără extensie'],
  ['#',                                   false, 'butonul din meniul mobil'],
];

const stricate = [];
let verificate = 0;
for (const p of pagini) {
  const sursa = fs.readFileSync(path.join(ROOT, p), 'utf8');
  const linie = (sursa.match(/^[ \t]*if \(![^\n]*\.test\(h\)\) return;$/m) || [])[0];
  if (!linie) continue;
  const dupa = linie.split(cauta).join(pune);
  if (dupa === linie) { stricate.push(`${p}: rescrierea nu se mai potrivește pe linia din pagină`); continue; }
  const cond = dupa.trim().replace(/^if \(/, '').replace(/\) return;$/, '');
  verificate++;
  for (const [href, iese, ce] of ADRESE) {
    let r;
    try { r = vm.runInNewContext(`(function (h) { return !!(${cond}); })`)(href); }
    catch (e) { stricate.push(`${p}: condiția rescrisă nu se compilează — ${e.message}`); break; }
    if (r !== iese) {
      stricate.push(`${p}: „${href}" (${ce}) — ${iese ? 'trebuia lăsată în pace' : 'trebuia interceptată'}`);
    }
  }
}
check('.claude/build-deploy.mjs:294', 'interceptorul rescris lasă în pace adresele absolute dintre subdomenii',
  stricate.length === 0, stricate.slice(0, 3).join(' · '));
check('.claude/build-deploy.mjs:294', 'proba a găsit interceptorul în paginile publicate',
  verificate >= 10, `doar ${verificate} pagini verificate`);

console.log(`\n  ${pass} trecute · ${fail} picate\n`);
process.exit(fail ? 1 : 0);
