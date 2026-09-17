// Dovada că regulile noi ale benzii Q chiar pot să pice.
//
//   node scratchpad/runda-2-1-1/dovezi/dovada-ghilimea-si-listele.mjs
//
// DE CE EXISTĂ
// ────────────
// Banda a scris trei reguli, în DOUĂ unelte, și fiecare păzește altceva:
//
//   1. `.claude/check-php.mjs` — orice refuz de 409 „s-a schimbat între timp"
//      din `api/` vorbește cu ghilimelele casei („ … ”), oriunde ar fi scris.
//   2. `.claude/check-panouri.mjs` — ciotul `refuz409()` poartă chiar mesajul
//      scos din SURSA PHP, literă cu literă (oglinda serverului).
//   3. `.claude/check-panouri.mjs` — paginile care pleacă la autentificare prin
//      `gp.toLogin()` se caută PE DISC, cu două scutiri MĂSURATE, nu scrise.
//
// O regulă care nu poate pica e mai rea decât una care lipsește: nu se vede că
// lipsește ceva, dimpotrivă, apare un rând verde care spune că s-a uitat
// cineva. Unealta de aici strică pe rând, dinadins, exact locul pe care fiecare
// regulă zice că îl apără, rulează AMÂNDOUĂ uneltele și cere lista EXACTĂ de
// reguli înroșite — nici una în plus, nici una în minus — plus ca regulile care
// NU trebuie s-o prindă să rămână verzi.
//
// CELE DOUĂ CHEI ALE LUCRĂRII 1, și de ce trebuie amândouă. Stricăciunile C1,
// C2 și C3 sunt scrise anume ca să se vadă că niciuna din cele două reguli nu
// ține locul celeilalte: cu serverul stricat singur, se aud amândouă; cu ciotul
// stricat singur, se aude numai oglinda; cu AMÂNDOUĂ stricate la fel — adică
// exact starea de dinaintea rundei —, oglinda tace, fiindcă ele se potrivesc
// între ele, și numai paznicul din PHP mai strigă.
//
// PUNEREA LA LOC nu stă în `finally` — `finally` nu rulează la Ctrl-C, la o
// pană de curent sau la un proces omorât, iar proiectul nu e sub git. Stă în
// `pazesteFisiere()` din `.claude/proba-pazita.mjs`, legată de `process.on
// ('exit')` și de SIGINT/SIGTERM. Ce nici ea nu poate prinde rămâne în
// `.claude/.adapost/`, iar `verifica-tot` refuză să pornească până nu se
// lămurește. `finally` e păstrat pe deasupra, ca a doua plasă.
//
// PAGINA DE PROBĂ. Lucrarea 2 se dovedește FĂRĂ să se atingă vreo pagină
// adevărată: se NAȘTE o pagină nouă în rădăcină, în patru chipuri deosebite, și
// se cere ce trebuie de la fiecare. Ăsta e chiar scenariul de care se teme
// regula — „peste trei luni e o pagină nouă care nu e în listă" —, iar
// `pazesteFisiere()` știe să păzească și un fișier care NU există încă: punerea
// la loc înseamnă să-l șteargă.
//
// CE NU DOVEDEȘTE unealta: că omul chiar vede ghilimeaua pe ecran. Nu o vede —
// măsurat, scris în fișa defectului. Vezi și stricăciunile INERTE de la capăt.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { pazesteFisiere } from '../../../.claude/proba-pazita.mjs';

const AICI = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(AICI, '..', '..', '..');
const PANOURI = path.join(ROOT, '.claude', 'check-panouri.mjs');
const PHP = path.join(ROOT, '.claude', 'check-php.mjs');

const ORDERS = 'api/routes/orders.php';
const UNEALTA_PANOURI = '.claude/check-panouri.mjs';
const PAGINA_NOUA = 'Proba Benzii Q.dc.html';

let pass = 0, fail = 0;
const esecuri = [];
const check = (nume, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${nume}`); }
  else {
    fail++; esecuri.push(nume + (extra ? ' — ' + extra : ''));
    console.log(`  FAIL  ${nume}${extra ? ' — ' + extra : ''}`);
  }
};

/* ─────────────────────────────────────────────── paginile de probă ── */

/** O pagină nouă în rădăcină, în chipul cerut. */
const pagina = ({ bibliotecaCasei, cheamaToLogin, trimiteDeMana }) => `<!DOCTYPE html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title>Proba Benzii Q</title>
${bibliotecaCasei ? '<script src="./shared/gp-api.js"></script>' : '<!-- fără biblioteca casei -->'}
</head>
<body>
<div id="app"></div>
<script type="text/x-dc">
class Component extends DCLogic {
  state = { gata: false };
  componentDidMount() {
    gp.me()
      .then((u) => this.setState({ gata: true, u }))
      .catch(() => {${cheamaToLogin ? '\n        gp.toLogin();' : ''}${
  trimiteDeMana ? '\n        location.href = "Intrare.dc.html";' : ''}
      });
  }
  renderVals() { return { gata: this.state.gata }; }
}
</script>
</body>
</html>
`;

/* ────────────────────────────────────────────────── stricăciunile ── */
//
// `taie` e o listă de perechi [din, în] pe un fișier adevărat; `naste` scrie un
// fișier care nu există. Fiecare bucată căutată trebuie să se găsească EXACT o
// dată, altfel unealta spune „n-am putut strica", nu trece verde pe tăcute.

// Mesajul serverului, cu ghilimeaua DREAPTĂ de dinaintea rundei.
const PHP_BUN = `. ' a fost schimbată între timp: acum e „' . $acum . '”. N-am mutat-o.',`;
const PHP_RAU = `. ' a fost schimbată între timp: acum e „' . $acum . '". N-am mutat-o.',`;
const CIOT_BUN = `      new Error('Comanda #1042 a fost schimbată între timp: acum e „' + acum + '”. N-am mutat-o.'),`;
const CIOT_RAU = `      new Error('Comanda #1042 a fost schimbată între timp: acum e „' + acum + '". N-am mutat-o.'),`;

const OGLINDA = 'ciotul de refuz poartă chiar mesajul serverului, literă cu literă';
const GHILIMELE = 'nu folosește ghilimelele casei';

const DEFECTE = [
  /* ─── Lucrarea 1: ghilimeaua ─────────────────────────────────────── */
  {
    nume: 'C1 · SERVERUL, pus înapoi pe ghilimeaua dreaptă (chiar defectul din registru)',
    taie: [[ORDERS, [[PHP_BUN, PHP_RAU]]]],
    panouri: [OGLINDA],
    php: [GHILIMELE],
  },
  {
    nume: 'C2 · CIOTUL singur, lăsat în urma serverului (oglinda se sparge, PHP-ul e curat)',
    taie: [[UNEALTA_PANOURI, [[CIOT_BUN, CIOT_RAU]]]],
    panouri: [OGLINDA],
    // Paznicul din PHP nu se uită la JavaScript, și bine face: dacă ar prinde-o
    // și el, n-am ști care dintre cele două reguli lucrează.
    php: [],
  },
  {
    nume: 'C3 · AMÂNDOUĂ stricate la fel — starea de dinaintea rundei: oglinda TACE, paznicul PHP strigă',
    taie: [[ORDERS, [[PHP_BUN, PHP_RAU]]], [UNEALTA_PANOURI, [[CIOT_BUN, CIOT_RAU]]]],
    // Nicio regulă din check-panouri: ciotul și serverul se potrivesc între ei.
    // Asta e dovada că oglinda singură NU putea găsi defectul — și de ce regula
    // despre ghilimele trebuia scrisă în unealta care citește PHP.
    panouri: [],
    php: [GHILIMELE],
  },
  {
    nume: 'C4 · AL TREILEA refuz, scris azi de altcineva, cu ghilimele drepte',
    taie: [[ORDERS, [[
      `        $anulata = status_cancelled();`,
      `        $altRefuz = 'Comanda ' . $id . ' a fost schimbată între timp: acum e „' . $x . '". N-am predat-o.';\n        $anulata = status_cancelled();`,
    ]]]],
    // Miezul Lucrării 1, punctul 3: regula nu păzește două rânduri știute azi,
    // ci ORICE refuz de felul ăsta din `api/`.
    panouri: [],
    php: [GHILIMELE],
  },
  {
    nume: 'C5 · refuzul ușii de producție rămâne fără ghilimea de închidere',
    taie: [[ORDERS, [[
      `. ' a fost schimbată între timp: acum e „' . $anulata . '”. N-am notat producția.',`,
      `. ' a fost schimbată între timp: acum e „' . $anulata . '. N-am notat producția.',`,
    ]]]],
    panouri: [],
    php: [GHILIMELE],
  },
  {
    nume: 'C6 · serverul își schimbă coada mesajului, iar ciotul rămâne cu cea veche',
    taie: [[ORDERS, [[`'”. N-am mutat-o.',`, `'”. N-am clintit-o.',`]]]],
    // Oglinda nu păzește numai ghilimeaua: păzește TOT mesajul.
    panouri: [OGLINDA],
    php: [],
  },

  /* ─── Lucrarea 2: lista care nu mai poate îmbătrâni în tăcere ─────── */
  {
    nume: 'L1 · PAGINĂ NOUĂ din aplicație, fără `gp.toLogin()` (defectul de peste trei luni)',
    naste: [[PAGINA_NOUA, pagina({ bibliotecaCasei: true, cheamaToLogin: false })]],
    panouri: ['Proba Benzii Q pleacă la autentificare prin locul comun'],
    php: [],
  },
  {
    nume: 'L2 · PAGINĂ NOUĂ care trimite de mână la pagina de intrare, deși cheamă și `gp.toLogin()`',
    naste: [[PAGINA_NOUA, pagina({ bibliotecaCasei: true, cheamaToLogin: true, trimiteDeMana: true })]],
    panouri: ['Proba Benzii Q pleacă la autentificare prin locul comun'],
    php: [],
  },
  {
    nume: 'L3 · descoperirea de pe disc, golită: nicio pagină nu se mai găsește',
    taie: [[UNEALTA_PANOURI, [[
      `  const PAGINI_DISC = fs.readdirSync(ROOT).filter((n) => n.endsWith('.dc.html')).sort();`,
      `  const PAGINI_DISC = fs.readdirSync(ROOT).filter((n) => n.endsWith('.dc.htmlZ')).sort();`,
    ]]]],
    // Paza descoperirii. Fără cele trei rânduri de mai jos, un `readdirSync`
    // golit ar face regula să nu mai măsoare NIMIC și să tacă — adică aceeași
    // tăcere ca a listei scrise de mână, numai pe alt drum.
    panouri: [
      'paginile se numără de pe disc, nu dintr-o listă scrisă de mână',
      'pagina de autentificare se află din gp.loginHref(), nu dintr-un nume scris în probă',
      'paginile din aplicație se cunosc după biblioteca pe care o încarcă',
    ],
    php: [],
  },
  {
    nume: 'L4 · scutirea paginii de intrare nu se mai măsoară din `gp.loginHref()`',
    taie: [[UNEALTA_PANOURI, [[
      `    const m = /return '([^']+\\.dc\\.html)'\\s*\\+\\s*\\(/.exec(cite('shared/gp-api.js'));`,
      `    const m = /return '([^']+\\.dc\\.htmlZ)'\\s*\\+\\s*\\(/.exec(cite('shared/gp-api.js'));`,
    ]]]],
    // Fără adresa cerută de la bibliotecă, scutirea se stinge: pagina de intrare
    // intră și ea sub regulă și i se cere `gp.toLogin()`, ceea ce e absurd. Se
    // aude pe loc, în trei locuri — deci scutirea nu poate fi pierdută în tăcere.
    panouri: [
      'pagina de autentificare se află din gp.loginHref(), nu dintr-un nume scris în probă',
      'paginile din aplicație se cunosc după biblioteca pe care o încarcă',
      'Intrare pleacă la autentificare prin locul comun',
    ],
    php: [],
  },

  /* ─── ce trebuie să RĂMÂNĂ verde ─────────────────────────────────── */
  {
    nume: 'V1 · PAGINĂ NOUĂ din aplicație care cheamă `gp.toLogin()` — acoperită singură, fără s-o scrie cineva',
    naste: [[PAGINA_NOUA, pagina({ bibliotecaCasei: true, cheamaToLogin: true })]],
    panouri: [],
    php: [],
    trebuieVerde: true,
  },
  {
    nume: 'V2 · PAGINĂ NOUĂ care nu leagă biblioteca casei — scutirea măsurată o lasă în pace',
    naste: [[PAGINA_NOUA, pagina({ bibliotecaCasei: false, cheamaToLogin: false })]],
    panouri: [],
    php: [],
    trebuieVerde: true,
  },

  /* ─── STRICĂCIUNI INERTE ─────────────────────────────────────────── */
  //
  // Listele de așteptări sunt GOALE dinadins, iar rândurile astea NU DOVEDESC
  // NIMIC. Sunt scrise ca să se vadă negru pe alb unde se termină ce măsoară
  // banda.
  {
    nume: 'INERT · `current` scos din refuzul de 409 — tocmai câmpul care ține mesajul serverului departe de ecran',
    taie: [[ORDERS, [[`                            'current' => $acum,\n`, ``]]]],
    inert: true,
    deCe: 'Fără `current`, toate cele trei pagini cad pe „Nu s-a salvat: " + e.message, adică '
      + 'ghilimeaua serverului ajunge chiar sub ochii omului. Nicio regulă a benzii nu se uită la '
      + 'câmpul ăsta: regulile despre el se cântăresc pe ciotul din check-panouri, care e scris de '
      + 'mână și nu se schimbă când se schimbă PHP-ul.',
  },
  {
    nume: 'INERT · proza din `api/` scrisă cu ghilimea dreaptă, în afara refuzurilor',
    taie: [[ORDERS, [[`$returStoc = 'Retur: comandă ieșită din „' . $incheiata . '"';`,
      `$returStoc = 'Retur: comanda iesita din „' . $incheiata . '"';`]]]],
    inert: true,
    deCe: 'Regula păzește REFUZURILE, nu toată proza casei: în `api/` sunt vreo douăzeci de mesaje '
      + 'care închid cu ghilimea dreaptă și nu sunt ale rundei ăsteia. Lărgită peste ele, unealta '
      + 'ar fi dat alarme pe fișiere ale altor benzi — iar o unealtă care dă alarme false se învață '
      + 'repede să fie ocolită.',
  },
];

/* ───────────────────────────────────────────────────────── mașinăria ── */

/** Rulează check-panouri și citește ce a picat. */
function ruleazaPanouri() {
  const r = spawnSync(process.execPath, [PANOURI], { cwd: ROOT, encoding: 'utf8' });
  const out = (r.stdout || '') + (r.stderr || '');
  const picate = new Set();
  let randuri = 0;
  for (const linie of out.split('\n')) {
    // `check()` scrie:  «  FAIL  <nume>  [<defect>] — <lămurire>»
    const m = linie.match(/^\s*FAIL\s\s(.+?)\s\s\[/);
    if (m) { picate.add(m[1].trim()); randuri++; }
  }
  // O rulare moartă la mijloc are și ea rânduri FAIL (cele apucate), dar ele nu
  // spun nimic despre regulile care n-au mai rulat. Socoteala de la capăt
  // trebuie să existe ȘI să se potrivească cu rândurile citite.
  const socoteala = out.match(/^(\d+) trecute · (\d+) picate/m);
  const eroare = (out.match(/^\[?([A-Za-z]*Error: [^\]\n]*)/m) || [])[1];
  let deCe = '';
  if (!socoteala) deCe = 'check-panouri s-a oprit înainte de socoteala de la capăt: ' + (eroare || 'fără nicio eroare tipărită');
  else if (Number(socoteala[2]) !== randuri) deCe = `socoteala spune ${socoteala[2]} picate, dar s-au citit ${randuri} rânduri FAIL`;
  return { picate, trecute: socoteala ? Number(socoteala[1]) : 0, intreaga: deCe === '', deCe };
}

/** Rulează check-php și citește problemele. */
function ruleazaPhp() {
  const r = spawnSync(process.execPath, [PHP], { cwd: ROOT, encoding: 'utf8' });
  const out = (r.stdout || '') + (r.stderr || '');
  const probleme = [];
  for (const linie of out.split('\n')) {
    const m = linie.match(/^\s*x\s(.+)$/);
    if (m) probleme.push(m[1].trim());
  }
  // Aceeași pretenție ca dincolo: antetul trebuie să existe (deci unealta a
  // ajuns la capăt), iar numărul anunțat trebuie să se potrivească cu rândurile
  // citite. Un `PROBLEME (n)` lipsă cu rânduri citite, ori invers, e harnașament
  // stricat, nu cod stricat.
  const antet = out.match(/^(\d+) fișiere PHP \| (\d+) funcții definite/m);
  const socoteala = out.match(/^PROBLEME \((\d+)\):/m);
  const eroare = (out.match(/^\[?([A-Za-z]*Error: [^\]\n]*)/m) || [])[1];
  let deCe = '';
  if (!antet) deCe = 'check-php s-a oprit înainte de antet: ' + (eroare || 'fără nicio eroare tipărită');
  else if (socoteala && Number(socoteala[1]) !== probleme.length) {
    deCe = `socoteala spune ${socoteala[1]} probleme, dar s-au citit ${probleme.length} rânduri`;
  } else if (!socoteala && probleme.length) deCe = 'rânduri de problemă fără antetul PROBLEME(n)';
  else if (socoteala && r.status !== 1) deCe = 'probleme găsite, dar codul de ieșire nu e 1';
  else if (!socoteala && r.status !== 0) deCe = 'nicio problemă, dar codul de ieșire nu e 0';
  return { probleme, intreaga: deCe === '', deCe };
}

/* ────────────────────────────────────────────────────────── rularea ── */

const CAI = [ORDERS, UNEALTA_PANOURI, PAGINA_NOUA];
const paza = pazesteFisiere(CAI, 'ghilimea-si-listele');
const abs = (rel) => path.join(ROOT, rel);
const BUNE = new Map(CAI.filter((c) => fs.existsSync(abs(c))).map((c) => [c, fs.readFileSync(abs(c), 'utf8')]));

/** Pune totul la loc: fișierele păzite și ștergerea paginii de probă. */
function laLoc() {
  for (const [rel, text] of BUNE) fs.writeFileSync(abs(rel), text);
  if (!BUNE.has(PAGINA_NOUA)) { try { fs.unlinkSync(abs(PAGINA_NOUA)); } catch { /* n-a apucat */ } }
}

let intreg = true;
const neintregi = [];
let trecuteLumeaBuna = 0;

try {
  console.log('\n=== lumea bună: nicio regulă nu pică, în niciuna din unelte ===');
  {
    const p = ruleazaPanouri();
    const f = ruleazaPhp();
    trecuteLumeaBuna = p.trecute;
    if (!p.intreaga || !f.intreaga) {
      neintregi.push('lumea bună: ' + (p.deCe || f.deCe));
      console.log('  —     nejudecat: harnașamentul, nu codul (vezi la sfârșit)');
    } else {
      // Alte benzi lucrează ACUM în același proiect. Dacă pică ceva ce nu e al
      // nostru, se vede aici pe nume: nu se repară de aici și nu se ascunde.
      check('pe codul întreg, check-panouri n-are niciun eșec', p.picate.size === 0,
        [...p.picate].slice(0, 5).join(' · '));
      check('pe codul întreg, check-php n-are nicio problemă', f.probleme.length === 0,
        f.probleme.slice(0, 3).join(' · '));
      console.log(`  ·     ${p.trecute} reguli trecute în check-panouri`);
    }
  }

  for (const d of DEFECTE) {
    console.log(`\n=== ${d.nume} ===`);

    // Fiecare bucată căutată trebuie să se găsească EXACT o dată: altfel nu
    // știm ce-am stricat, iar dovada n-ar mai însemna nimic.
    let potrivit = true;
    const scrise = [];
    for (const [rel, perechi] of (d.taie || [])) {
      let text = BUNE.get(rel);
      if (text === undefined) {
        check(`${d.nume}: fișierul de stricat există`, false, rel + ' nu e pe disc');
        potrivit = false; break;
      }
      for (const [din, catre] of perechi) {
        const cate = text.split(din).length - 1;
        if (cate !== 1) {
          check(`${d.nume}: bucata de stricat se găsește exact o dată`, false,
            `în ${rel} găsită de ${cate} ori — tiparul s-a depărtat de cod: «${din.slice(0, 70)}…»`);
          potrivit = false; break;
        }
        // Funcție, nu șir: într-un șir de înlocuire `$&` ar însemna „ce s-a
        // găsit", iar `\\` s-ar înjumătăți. Textele de aici au și una, și alta.
        text = text.replace(din, () => catre);
      }
      if (!potrivit) break;
      if (text === BUNE.get(rel)) {
        check(`${d.nume}: stricăciunea chiar schimbă ${rel}`, false, 'textul a ieșit neschimbat');
        potrivit = false; break;
      }
      scrise.push([rel, text]);
    }
    if (!potrivit) { laLoc(); continue; }
    for (const [rel, cuprins] of (d.naste || [])) scrise.push([rel, cuprins]);

    let rp = null, rf = null;
    try {
      for (const [rel, text] of scrise) fs.writeFileSync(abs(rel), text);
      rp = ruleazaPanouri();
      rf = ruleazaPhp();
    } finally {
      laLoc();
    }

    if (!rp.intreaga || !rf.intreaga) {
      neintregi.push(`${d.nume}: ${rp.deCe || rf.deCe}`);
      console.log('  —     nejudecat: harnașamentul, nu codul (vezi la sfârșit)');
      continue;
    }

    if (d.inert) {
      // Nu se așteaptă nimic, deci nu se dovedește nimic. Se tipărește doar ce
      // s-a văzut, ca golul de acoperire să fie SCRIS, nu bănuit.
      console.log(`  ·     inertă: ${rp.picate.size} reguli picate în check-panouri, `
        + `${rf.probleme.length} probleme în check-php`);
      console.log('  ·     ' + d.deCe);
      continue;
    }

    // Lista EXACTĂ, în amândouă uneltele: nici una în plus, nici una în minus.
    const cerutePanouri = new Set(d.panouri);
    const inPlus = [...rp.picate].filter((x) => !cerutePanouri.has(x));
    const inMinus = d.panouri.filter((x) => !rp.picate.has(x));
    check(`${d.nume} → check-panouri înroșește exact ${d.panouri.length} regul${d.panouri.length === 1 ? 'ă' : 'i'}`,
      inPlus.length === 0 && inMinus.length === 0,
      (inMinus.length ? 'n-au picat: ' + inMinus.join(' · ') + '. ' : '')
      + (inPlus.length ? 'au picat în plus: ' + inPlus.join(' · ') : ''));

    const gasitePhp = d.php.map((frag) => rf.probleme.filter((p) => p.includes(frag)).length);
    const potrivitePhp = d.php.every((frag, i) => gasitePhp[i] > 0);
    check(`${d.nume} → check-php scoate exact ${d.php.length} problem${d.php.length === 1 ? 'ă' : 'e'}`,
      rf.probleme.length === d.php.length && potrivitePhp,
      rf.probleme.length ? 'a scos: ' + rf.probleme.slice(0, 3).join(' · ') : 'a rămas curat');

    if (d.trebuieVerde) {
      check(`${d.nume} → amândouă uneltele rămân verzi`,
        rp.picate.size === 0 && rf.probleme.length === 0,
        [...rp.picate].slice(0, 3).join(' · ') + ' · ' + rf.probleme.slice(0, 2).join(' · '));
    }
  }
} finally {
  laLoc();
  for (const [rel, text] of BUNE) {
    if (fs.readFileSync(abs(rel), 'utf8') !== text) intreg = false;
  }
  if (!BUNE.has(PAGINA_NOUA) && fs.existsSync(abs(PAGINA_NOUA))) intreg = false;
  paza.puneLaLoc();
}

console.log('\n=== harnașamentul: fiecare judecată s-a sprijinit pe ce trebuia ===');
check('fiecare rulare a uneltelor a mers până la capăt', neintregi.length === 0,
  'harnașamentul, nu codul: ' + neintregi.slice(0, 3).join(' · '));

console.log('\n=== codul a rămas cum a fost ===');
check('fișierele stricate au fost puse la loc, iar pagina de probă a dispărut', intreg,
  'copiile bune sunt în .claude/.adapost/ghilimea-si-listele/');

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} picate   (check-panouri pe codul bun: ${trecuteLumeaBuna} reguli)`);
if (esecuri.length) {
  console.log('\nEșecuri:');
  esecuri.forEach((f) => console.log('  · ' + f));
}
process.exitCode = fail ? 1 : 0;
