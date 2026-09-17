// Dovada că regulile noi din `.claude/check-panouri.mjs` chiar pot să pice.
//
//   node scratchpad/runda-2-1-1/dovezi/dovada-vestea-soferului.mjs
//
// DE CE EXISTĂ
// ────────────
// Regulile păzesc două lucruri din „Ruta Sofer.dc.html":
//
//   A. vestea verde („… marcat livrat. Laboratorul vede «Gata»") nu se mai
//      scrie ÎNAINTE ca serverul să fi răspuns ceva;
//   B. îndreptarea roșie se desenează ÎNĂUNTRUL antetului lipicios, deci e pe
//      ecran oricât ar fi derulat șoferul.
//
// O regulă care nu poate pica e mai rea decât una care lipsește: nu se vede că
// lipsește ceva, dimpotrivă, apare un rând verde care spune că s-a uitat
// cineva. Unealta de aici face singurul lucru care dovedește ceva: strică pe
// rând PAGINA, în locul exact pe care fiecare regulă zice că îl apără, rulează
// `check-panouri.mjs` și cere ca regulile numite să iasă roșii.
//
// PUNEREA LA LOC nu stă în `finally` — `finally` nu rulează la Ctrl-C, la o
// pană de curent sau la un proces omorât, iar proiectul nu e sub git. Stă în
// `pazesteFisiere()` din `.claude/proba-pazita.mjs`, legată de `process.on
// ('exit')` și de SIGINT/SIGTERM. Ce nici ea nu poate prinde rămâne în
// `.claude/.adapost/`, iar `verifica-tot` refuză să pornească până nu se
// lămurește. `finally` e păstrat pe deasupra, ca a doua plasă.
//
// CE NU DOVEDEȘTE unealta: că pagina chiar se desenează așa pe un telefon.
// Regulile citesc sursa și rulează funcțiile scoase din ea; ecranul de 390px
// se măsoară cu altceva. Vezi și stricăciunea INERTĂ de la capătul listei.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { pazesteFisiere } from '../../../.claude/proba-pazita.mjs';

const AICI = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(AICI, '..', '..', '..');
const PAGINA = 'Ruta Sofer.dc.html';
const UNEALTA = path.join(ROOT, '.claude', 'check-panouri.mjs');

let pass = 0, fail = 0;
const esecuri = [];
const check = (nume, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${nume}`); }
  else { fail++; esecuri.push(nume + (extra ? ' — ' + extra : '')); console.log(`  FAIL  ${nume}${extra ? ' — ' + extra : ''}`); }
};

/* ──────────────────────────────────────────────────── stricăciunile ── */
//
// `taie` e o listă de perechi [din, în]: unele stricăciuni cer două tăieturi
// (mutarea unui `</div>` înseamnă să-l scoți dintr-un loc și să-l pui în
// altul). Fiecare bucată căutată trebuie să se găsească EXACT o dată, altfel
// unealta spune „n-am putut strica", nu trece verde pe tăcute.

const DEFECTE = [
  {
    nume: 'A · vestea verde pusă înapoi în primul setState, iar `.then()` golit (reparația desfăcută)',
    taie: [[
      `        toastBad: false,
        toast: ""
      });

      const trimite = () => gp.patch('/orders/' + o.id, { status: next, from })
        .then(() => {
          this.setState({
            toast: delivered ? o.cust + " — livrarea a fost anulată." : o.cust + " · " + o.ref + " marcat livrat. Laboratorul vede „" + gp.done() + "”.",
            toastBad: false
          });
          return true;
        })`,
      `        toastBad: false,
        toast: delivered ? o.cust + " — livrarea a fost anulată." : o.cust + " · " + o.ref + " marcat livrat. Laboratorul vede „" + gp.done() + "”."
      });

      const trimite = () => gp.patch('/orders/' + o.id, { status: next, from })
        .then(() => true)`,
    ]],
    picaSigur: [
      'până răspunde serverul, șoferul nu primește nicio veste',
      'nicio veste scrisă înainte de răspuns nu pomenește laboratorul',
      'pe comanda anulată între timp, vestea verde nu se scrie niciodată',
      'pe o pană de rețea vestea verde nu se scrie în nicio clipă',
      'anularea livrării își ține vestea tot până răspunde serverul',
      'anularea refuzată nu pretinde, în nicio clipă, că livrarea a fost anulată',
    ],
  },
  {
    nume: 'A · `.then()` golit singur: vestea bună nu mai vine niciodată',
    taie: [[
      `            toast: delivered ? o.cust + " — livrarea a fost anulată." : o.cust + " · " + o.ref + " marcat livrat. Laboratorul vede „" + gp.done() + "”.",`,
      `            toast: "",`,
    ]],
    picaSigur: [
      'pe răspuns bun vestea spune că laboratorul vede etapa de încheiere',
      'pe răspuns bun, anularea livrării își spune vestea',
    ],
  },
  {
    nume: 'A · etapa de încheiere scrisă în cod, nu luată din Setări',
    taie: [[
      `marcat livrat. Laboratorul vede „" + gp.done() + "”.",`,
      `marcat livrat. Laboratorul vede „Predată”.",`,
    ]],
    picaSigur: ['etapa din vestea bună e cea din Setări, nu una scrisă în cod'],
  },
  {
    nume: 'A · cartonul nu se mai mută pe loc (purtarea optimistă bună, scoasă)',
    taie: [[
      `        orders: S.orders.map(x => x.id === o.id ? Object.assign({}, x, { status: next }) : x),`,
      `        orders: S.orders,`,
    ]],
    picaSigur: ['cartonul se mută pe loc în listă, fără să aștepte serverul'],
  },
  {
    nume: 'A · cartonul apăsat rămâne deschis',
    taie: [[
      `        open: null,
        toastBad: false,
        toast: ""`,
      `        toastBad: false,
        toast: ""`,
    ]],
    picaSigur: ['cartonul deschis se închide pe loc'],
  },
  {
    nume: 'A · etapa de plecare nu mai pleacă la server (paza de 409, desfăcută)',
    taie: [[
      `gp.patch('/orders/' + o.id, { status: next, from })`,
      `gp.patch('/orders/' + o.id, { status: next })`,
    ]],
    picaSigur: ['cererea pleacă spre server cu etapa de plecare'],
  },
  {
    nume: 'A · îndreptarea nu mai e însemnată ca veste rea (ar ieși verde pe ecran)',
    taie: [[
      `            toastBad: true
          });
          return false;`,
      `            toastBad: false
          });
          return false;`,
    ]],
    picaSigur: ['îndreptarea e însemnată ca veste rea'],
  },
  {
    nume: 'A · foaia de drum nu se mai recitește pe refuz',
    taie: [[
      `          this.__seen = null;
          this.pull();`,
      `          this.__seen = null;`,
    ]],
    picaSigur: ['pe refuz foaia de drum se recitește'],
  },
  {
    nume: 'A · îndreptarea nu mai numește etapa de acum, spune doar „nu s-a salvat"',
    taie: [[`e.code === "status_changed" && acum`, `false`]],
    picaSigur: ['pe comanda anulată între timp, îndreptarea numește etapa de acum'],
  },
  {
    nume: 'A · markDone redenumită: regulile nu mai au ce scoate din sursă',
    taie: [[`    const markDone = (o) => {`, `    const marcheazaLivrat = (o) => {`]],
    // Când extragerea pică, tot ce e sub `if (fnMark)` nu mai RULEAZĂ. Nu e o
    // trecere verde tăcută: rândul de extragere iese roșu și numărul total
    // scade — adică se vede că nu s-a mai uitat nimeni.
    picaSigur: ['markDone() se poate citi din sursă'],
  },

  {
    nume: 'B · îndreptarea scoasă iar în afara antetului lipicios (defectul de la care am pornit)',
    taie: [
      [`</span>
</div>

<!-- Îndreptarea stă ÎN antetul lipicios`, `</span>
</div>
</div>

<!-- Îndreptarea stă ÎN antetul lipicios`],
      [`</div>
</sc-if>
</div>

<sc-if value="{{ toastBun }}"`, `</div>
</sc-if>

<sc-if value="{{ toastBun }}"`],
    ],
    picaSigur: ['îndreptarea e desenată ÎNĂUNTRUL antetului lipicios'],
  },
  {
    nume: 'B · vestea bună trasă și ea în antet (așezarea stricată la fiecare confirmare)',
    taie: [
      [`</div>
</sc-if>
</div>

<sc-if value="{{ toastBun }}"`, `</div>
</sc-if>

<sc-if value="{{ toastBun }}"`],
      [`<p class="h-alert__title" style="margin:0;font-size:15px">{{ toast }}</p>
</div>
</sc-if>

<div data-rt="list"`, `<p class="h-alert__title" style="margin:0;font-size:15px">{{ toast }}</p>
</div>
</sc-if>
</div>

<div data-rt="list"`],
    ],
    picaSigur: ['vestea bună a rămas în afara antetului, ca așezarea să nu se schimbe'],
  },
  {
    nume: 'B · antetul nu mai e lipicios: reparația se evaporă toată',
    taie: [[`<div style="position:sticky;top:0;z-index:10;`, `<div style="position:relative;top:0;z-index:10;`]],
    picaSigur: ['antetul lipicios se poate găsi și i se pot număra capetele'],
  },
  {
    nume: 'B · steagurile nu mai sunt complementare: vestea rea s-ar desena de două ori',
    taie: [[`      toastBun: !!(S.toast && !S.toastBad),`, `      toastBun: !!S.toast,`]],
    picaSigur: [
      'vestea rea nu aprinde locul de sub antet',
      'cele două locuri nu se aprind niciodată deodată',
    ],
  },
  {
    nume: 'B · steagul rău nu se mai stinge când vestea se șterge (casetă goală lipită de antet)',
    taie: [[`      toastRau: !!(S.toast && S.toastBad),`, `      toastRau: !!S.toastBad,`]],
    picaSigur: ['fără veste nu se aprinde niciun loc'],
  },
  {
    nume: 'B · steagul rău citește steagul pe dos',
    taie: [[`      toastRau: !!(S.toast && S.toastBad),`, `      toastRau: !!(S.toast && !S.toastBad),`]],
    picaSigur: [
      'vestea rea aprinde locul din antetul lipicios',
      'vestea bună nu se lipește de antet',
      'cele două locuri nu se aprind niciodată deodată',
    ],
  },
  {
    nume: 'B · locul vestii bune nu se mai aprinde niciodată',
    taie: [[`      toastBun: !!(S.toast && !S.toastBad),`, `      toastBun: false,`]],
    picaSigur: ['vestea bună aprinde locul de sub antet'],
  },
  {
    nume: 'B · butonul de închidere rămâne fără nume citibil',
    taie: [[` aria-label="Am citit îndreptarea"`, ``]],
    picaSigur: ['îndreptarea are un buton de închidere cu nume citibil'],
  },
  {
    nume: 'B · butonul de închidere se face mai mic decât degetul',
    taie: [[
      `style="flex:none;height:44px;width:44px;min-height:44px;padding:0;font-size:18px;border-radius:12px"`,
      `style="flex:none;height:32px;width:32px;padding:0;font-size:18px;border-radius:12px"`,
    ]],
    picaSigur: ['butonul de închidere ține degetul: 44px pe amândouă laturile'],
  },
  {
    nume: 'B · închiderea stinge vestea, dar lasă steagul aprins',
    taie: [[
      `      onToastClose: () => this.setState({ toast: "", toastBad: false }),`,
      `      onToastClose: () => this.setState({ toast: "" }),`,
    ]],
    picaSigur: ['închiderea stinge și vestea, și steagul ei'],
  },
  {
    nume: 'B · caseta îndreptării își alege iar culoarea la desenare',
    taie: [[
      `<div class="h-alert h-alert--danger" style="margin:0;border-radius:14px;border-left:5px solid var(--h-danger);`,
      `<div class="{{ toastCls }}" style="margin:0;border-radius:14px;border-left:5px solid var(--h-danger);`,
    ]],
    picaSigur: ['locul îndreptării e roșu prin fire, nu printr-o clasă aleasă la desenare'],
  },

  // ─── STRICĂCIUNE INERTĂ ───────────────────────────────────────────────
  //
  // Lista de așteptări e GOALĂ dinadins, și rândul ăsta NU DOVEDEȘTE NIMIC.
  // E scris ca să se vadă negru pe alb unde se termină ce măsoară unealta:
  // dunga roșie groasă din stânga casetei — partea care o face să strige — nu
  // e păzită de nicio regulă. Ce e păzit e LOCUL casetei (înăuntrul antetului
  // lipicios), fiindcă acolo stătea defectul: o casetă frumoasă pe un ecran la
  // care nimeni nu se uită nu ajută cu nimic. Cât de tare strigă se măsoară cu
  // ochiul, la 390px, nu de aici.
  {
    nume: 'INERT · dunga roșie din stânga îndreptării, scoasă (nicio regulă nu se uită la ea)',
    taie: [[`border-left:5px solid var(--h-danger);`, ``]],
    picaSigur: [],
  },
];

/* ───────────────────────────────────────────────────────── mașinăria ── */

/** Rulează check-panouri și citește ce a picat. */
function ruleaza() {
  const r = spawnSync(process.execPath, [UNEALTA], { cwd: ROOT, encoding: 'utf8' });
  const out = (r.stdout || '') + (r.stderr || '');
  const picate = new Set();
  let randuri = 0;
  for (const linie of out.split('\n')) {
    // `check()` scrie:  «  FAIL  <nume>  [<defect>] — <lămurire>»
    const m = linie.match(/^\s*FAIL\s\s(.+?)\s\s\[/);
    if (m) { picate.add(m[1].trim()); randuri++; }
  }
  // O rulare moartă la mijloc are și ea rânduri FAIL (cele apucate), dar ele
  // nu spun nimic despre regulile care n-au mai rulat. Socoteala de la capăt
  // trebuie să existe ȘI să se potrivească cu rândurile citite.
  const socoteala = out.match(/^(\d+) trecute · (\d+) picate/m);
  const eroare = (out.match(/^\[?([A-Za-z]*Error: [^\]\n]*)/m) || [])[1];
  let deCe = '';
  if (!socoteala) deCe = 'check-panouri s-a oprit înainte de socoteala de la capăt: ' + (eroare || 'fără nicio eroare tipărită');
  else if (Number(socoteala[2]) !== randuri) deCe = `socoteala spune ${socoteala[2]} picate, dar s-au citit ${randuri} rânduri FAIL`;
  return {
    picate,
    trecute: socoteala ? Number(socoteala[1]) : 0,
    intreaga: deCe === '',
    deCe,
  };
}

const ABS = path.join(ROOT, PAGINA);
const paza = pazesteFisiere([PAGINA], 'vestea-soferului');
const BUN = fs.readFileSync(ABS, 'utf8');

let intreg = true;
const neintregi = [];
let trecuteLumeaBuna = 0;

try {
  console.log('\n=== lumea bună: nicio regulă nu pică ===');
  {
    const { picate, trecute, intreaga, deCe } = ruleaza();
    trecuteLumeaBuna = trecute;
    if (!intreaga) {
      neintregi.push('lumea bună: ' + deCe);
      console.log('  —     nejudecat: harnașamentul, nu codul (vezi la sfârșit)');
    } else {
      // Alte benzi lucrează ACUM în același proiect. Dacă pică ceva ce nu e al
      // nostru, se vede aici pe nume: nu se repară de aici și nu se ascunde.
      check('pe pagina întreagă, check-panouri n-are niciun eșec', picate.size === 0,
        [...picate].slice(0, 5).join(' · '));
      console.log(`  ·     ${trecute} reguli trecute`);
    }
  }

  for (const d of DEFECTE) {
    console.log(`\n=== ${d.nume} ===`);

    // Fiecare bucată căutată trebuie să se găsească EXACT o dată: altfel nu
    // știm ce-am stricat, iar dovada n-ar mai însemna nimic.
    let stricat = BUN, potrivit = true;
    for (const [din, catre] of d.taie) {
      const cate = stricat.split(din).length - 1;
      if (cate !== 1) {
        check(`${d.nume}: bucata de stricat se găsește exact o dată`, false,
          `găsită de ${cate} ori — tiparul din DEFECTE s-a depărtat de pagină: «${din.slice(0, 60)}…»`);
        potrivit = false;
        break;
      }
      // Funcție, nu șir: într-un șir de înlocuire `$&` ar însemna „ce s-a
      // găsit", iar `\\` s-ar înjumătăți. Textele de aici au și una, și alta.
      stricat = stricat.replace(din, () => catre);
    }
    if (!potrivit) continue;

    if (stricat === BUN) {
      check(`${d.nume}: stricăciunea chiar schimbă pagina`, false, 'textul a ieșit neschimbat');
      continue;
    }

    let rulare = null;
    try {
      fs.writeFileSync(ABS, stricat);
      rulare = ruleaza();
    } finally {
      fs.writeFileSync(ABS, BUN);
    }

    if (!rulare.intreaga) {
      neintregi.push(`${d.nume}: ${rulare.deCe}`);
      console.log('  —     nejudecat: harnașamentul, nu codul (vezi la sfârșit)');
      continue;
    }

    if (!d.picaSigur.length) {
      // Stricăciune INERTĂ: nu se așteaptă nimic, deci nu se dovedește nimic.
      // Se tipărește doar ce s-a văzut, ca golul de acoperire să fie scris.
      console.log(`  ·     inertă: ${rulare.picate.size} reguli picate`
        + (rulare.picate.size ? ' (' + [...rulare.picate].slice(0, 3).join(' · ') + ')' : ''));
      continue;
    }

    for (const regula of d.picaSigur) {
      check(`«${regula}» pică`, rulare.picate.has(regula),
        rulare.picate.size === 0 ? 'unealta a rămas verde pe pagina stricată'
          : 'au picat în schimb: ' + [...rulare.picate].slice(0, 4).join(' · '));
    }
  }
} finally {
  if (fs.readFileSync(ABS, 'utf8') !== BUN) {
    fs.writeFileSync(ABS, BUN);
    if (fs.readFileSync(ABS, 'utf8') !== BUN) intreg = false;
  }
  paza.puneLaLoc();
}

console.log('\n=== harnașamentul: fiecare judecată s-a sprijinit pe ce trebuia ===');
check('fiecare rulare a lui check-panouri a mers până la capăt', neintregi.length === 0,
  'harnașamentul, nu codul: ' + neintregi.slice(0, 3).join(' · '));

console.log('\n=== pagina a rămas cum a fost ===');
check('„' + PAGINA + '" a fost pusă la loc', intreg,
  'copia bună e în .claude/.adapost/vestea-soferului/');

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} picate   (check-panouri pe lumea bună: ${trecuteLumeaBuna} reguli)`);
if (esecuri.length) {
  console.log('\nEșecuri:');
  esecuri.forEach((f) => console.log('  · ' + f));
}
process.exitCode = fail ? 1 : 0;
