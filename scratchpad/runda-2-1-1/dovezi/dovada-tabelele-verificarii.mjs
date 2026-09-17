// Dovada că regulile noi despre `api/verifica.php` chiar pot să pică.
//
//   node scratchpad/runda-2-1-1/dovezi/dovada-tabelele-verificarii.mjs
//
// DE CE EXISTĂ
// ────────────
// `api/verifica.php` e pagina pe care o deschide patronul DUPĂ ce urcă pachetul,
// ca să afle dacă instalarea e întreagă. Ea ținea o listă scrisă de mână cu cele
// 17 tabele din `sql/001_schema.sql`, iar aplicația de azi cere 21:
// `referral_commissions` (008), `founder_invites` (009), `lab_shifts` (014) și
// `lab_messages` (015) se nasc abia în migrări. Măsurat: pe o bază oprită la
// `002`, pagina răspundea „17 din 17 prezente", cu bifă verde, pe o bază căreia
// îi lipseau patru tabele.
//
// Reparația nu a fost să se adauge cele patru nume — aia ar fi repetat greșeala,
// fiindcă peste trei luni ar fi fost cinci. Lista a fost legată de disc prin
// două reguli noi, iar unealta de aici dovedește că amândouă se înroșesc:
//
//   1. `lista de tabele din verifica.php e chiar ce creeaza sql`
//      (`.claude/test-doc-actualizari.mjs`) — cheile lui `$need` trebuie să fie
//      EXACT numele din toate `CREATE TABLE` din `sql/`, fiecare cu fișierul
//      care îl naște primul.
//   2. `verifica.php numeste fiecare migrare din sql` (același fișier) — fiecare
//      `NNN_*.sql` trebuie numit undeva în pagină, fie într-un rând de
//      verificare, fie într-un comentariu care spune de ce nu se poate verifica.
//
// Și, fiindcă regulile statice citesc numai sursa, se rulează și proba vie —
// `.claude/test-defect-instalare.php`, care ridică o bază oprită la `002` și
// chiar deschide pagina pe ea:
//
//   3. `pagina numără toate cele 21 tabele din sql/`
//   4. `tabelele aduse de migrări lipsesc din pagină dacă lipsesc din bază`
//   5. `un tabel lipsă din schemă blochează pornirea`
//
// O regulă care nu poate pica e mai rea decât una care lipsește: nu se vede că
// lipsește ceva, dimpotrivă, apare un rând verde care spune că s-a uitat cineva.
//
// PUNEREA LA LOC nu stă în `finally` — `finally` nu rulează la Ctrl-C, la o pană
// de curent sau la un proces omorât, iar proiectul nu e sub git. Stă în
// `pazesteFisiere()` din `.claude/proba-pazita.mjs`, legată de `process.on
// ('exit')` și de SIGINT/SIGTERM. Ce nici ea nu poate prinde rămâne în
// `.claude/.adapost/`, iar `verifica-tot` refuză să pornească până nu se
// lămurește. `finally` e păstrat pe deasupra, ca a doua plasă.
//
// ALTE BENZI LUCREAZĂ ACUM în același proiect. Unealta NU cere ca `test-doc-
// actualizari` să fie verde peste tot: cere doar ca CELE DOUĂ REGULI ALE
// NOASTRE să fie verzi pe codul bun și roșii pe cel stricat. Ce mai pică se
// tipărește pe nume, ca să se vadă că nu s-a ascuns.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { pazesteFisiere } from '../../../.claude/proba-pazita.mjs';

const AICI = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(AICI, '..', '..', '..');
const PAGINA = 'api/verifica.php';
const ABS = path.join(ROOT, PAGINA);
const UNEALTA_DOC = path.join(ROOT, '.claude', 'test-doc-actualizari.mjs');
const UNEALTA_VIE = path.join(ROOT, '.claude', 'test-defect-instalare.php');

/** PHP-ul casei, ca în `.claude/instanta.mjs`. */
function php() {
  if (process.env.GP_PHP) return process.env.GP_PHP;
  const g = 'C:/laragon/bin/php';
  try {
    const v = fs.readdirSync(g).find((d) => d.startsWith('php-'));
    if (v) return path.join(g, v, 'php.exe');
  } catch { /* alt sistem */ }
  return 'php';
}

let pass = 0, fail = 0;
const esecuri = [];
const check = (nume, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${nume}`); }
  else { fail++; esecuri.push(nume + (extra ? ' — ' + extra : '')); console.log(`  FAIL  ${nume}${extra ? ' — ' + extra : ''}`); }
};

/* ─────────────────────────────────────────── regulile pe care le păzim ── */

const REGULI_DOC = [
  'lista de tabele din verifica.php e chiar ce creeaza sql',
  'verifica.php numeste fiecare migrare din sql',
];

/* ───────────────────────────────────────────────────── stricăciunile ── */
//
// `taie` e o listă de perechi [din, în]. Fiecare bucată căutată trebuie să se
// găsească EXACT o dată, altfel unealta spune „n-am putut strica", nu trece
// verde pe tăcute.

// Blocul de dinaintea reparației, scris aici întreg. Nu e o stricăciune
// inventată: e chiar codul care a stat în fișier până azi.
const LISTA_NOUA = `        $need   = [
            'users'                => '001_schema.sql',
            'sessions'             => '001_schema.sql',
            'password_resets'      => '001_schema.sql',
            'product_categories'   => '001_schema.sql',
            'products'             => '001_schema.sql',
            'order_requests'       => '001_schema.sql',
            'order_request_items'  => '001_schema.sql',
            'orders'               => '001_schema.sql',
            'order_items'          => '001_schema.sql',
            'stock_items'          => '001_schema.sql',
            'stock_moves'          => '001_schema.sql',
            'product_recipes'      => '001_schema.sql',
            'stock_reservations'   => '001_schema.sql',
            'audit_log'            => '001_schema.sql',
            'notifications'        => '001_schema.sql',
            'settings'             => '001_schema.sql',
            'login_attempts'       => '001_schema.sql',
            'referral_commissions' => '008_comisioane_recomandari.sql',
            'founder_invites'      => '009_pontaj_si_roluri.sql',
            'lab_shifts'           => '014_pontaj_laborator.sql',
            'lab_messages'         => '015_mesaje_laborator.sql',
        ];`;

const LISTA_VECHE = `        $need   = [
            'users', 'sessions', 'password_resets', 'product_categories', 'products',
            'order_requests', 'order_request_items', 'orders', 'order_items',
            'stock_items', 'stock_moves', 'product_recipes', 'stock_reservations',
            'audit_log', 'notifications', 'settings',
            'login_attempts',
        ];`;

const SOCOTEALA_NOUA = `        $missing = array_values(array_diff(array_keys($need), $tables));`;
const SOCOTEALA_VECHE = `        $missing = array_values(array_diff($need, $tables));
        $numite = $missing;
        $lipsaSchema = [];`;

// Coada verificării, de la `$numite` până la steagul de gravitate.
const COADA_NOUA = `        $numite = array_map(static fn(string $t): string => $t . ' (' . $need[$t] . ')', $missing);`;

const DEFECTE = [
  {
    nume: 'A · lista pusă înapoi exact cum era: cele 17 tabele ale schemei, fără migrări',
    taie: [
      [LISTA_NOUA, LISTA_VECHE],
      [SOCOTEALA_NOUA, SOCOTEALA_VECHE],
      [COADA_NOUA, ''],
    ],
    picaDoc: [
      'lista de tabele din verifica.php e chiar ce creeaza sql',
      // 008 și 009 rămân numite în blocurile lor; 014 rămâne numit la „Codul de
      // tabletă"; 015 nu mai e numit nicăieri, deci pică și a doua regulă.
      'verifica.php numeste fiecare migrare din sql',
    ],
    picaViu: [
      'pagina numără toate cele',
      'tabelele aduse de migrări lipsesc din pagină dacă lipsesc din bază',
    ],
  },
  {
    nume: 'A · un singur tabel scăpat din listă (lab_shifts), restul neatins',
    taie: [[`            'lab_shifts'           => '014_pontaj_laborator.sql',\n`, '']],
    // `014_pontaj_laborator.sql` rămâne numit la „Codul de tabletă al
    // pontajului", deci a doua regulă NU are voie să pice aici. Dacă ar pica,
    // n-am ști care dintre ele a prins scăparea.
    picaDoc: ['lista de tabele din verifica.php e chiar ce creeaza sql'],
    picaViu: ['pagina numără toate cele'],
  },
  {
    nume: 'A · tabelul e în listă, dar scris pe altă migrare decât cea care îl naște',
    taie: [[
      `            'founder_invites'      => '009_pontaj_si_roluri.sql',`,
      `            'founder_invites'      => '008_comisioane_recomandari.sql',`,
    ]],
    // Numărul rămâne 21, deci proba vie stă verde: aici se vede că regula
    // statică prinde ceva ce cea vie nu poate prinde — leacul tipărit ar fi
    // trimis omul după fișierul greșit.
    picaDoc: ['lista de tabele din verifica.php e chiar ce creeaza sql'],
    picaViu: [],
  },
  {
    nume: 'A · un tabel care nu există în sql/, strecurat în listă',
    taie: [[
      `            'lab_messages'         => '015_mesaje_laborator.sql',`,
      `            'lab_messages'         => '015_mesaje_laborator.sql',\n            'schema_migrations'    => '015_mesaje_laborator.sql',`,
    ]],
    picaDoc: ['lista de tabele din verifica.php e chiar ce creeaza sql'],
    picaViu: [],
  },
  {
    nume: 'B · o migrare scoasă din blocul „restul migrărilor" (016)',
    taie: [[`            '016_zona_cont.sql' => [`, `            '016_zona.sql' => [`]],
    picaDoc: ['verifica.php numeste fiecare migrare din sql'],
    picaViu: [],
  },
  {
    nume: 'B · seed-ul scos din leacul tipărit: 002 nu mai e numit nicăieri',
    taie: [[
      `'sql/001_schema.sql și sql/002_seed.sql NU sunt tot: restul tabelelor se nasc abia '`,
      `'sql/001_schema.sql nu e tot: restul tabelelor se nasc abia '`,
    ]],
    picaDoc: ['verifica.php numeste fiecare migrare din sql'],
    picaViu: [],
  },
  {
    nume: 'B · pricina scrisă pentru 017 ștearsă (migrarea care NU se vede în bază)',
    taie: [[`\`017_procent_recomandare.sql\` e numită aici anume`, `Migrarea aceea e numită aici anume`]],
    // 017 nu se poate verifica din bază: scrie un singur rând în `settings`, pe
    // care `008` îl pusese deja. Regula nu cere o verificare — cere ca fișierul
    // să fie NUMIT, fie într-un rând, fie într-un comentariu care spune de ce
    // nu se poate. Ștergerea pricinii o înroșește, deci pricina nu poate
    // dispărea în tăcere.
    picaDoc: ['verifica.php numeste fiecare migrare din sql'],
    picaViu: [],
  },
  {
    nume: 'C · tabelul lipsă nu mai blochează pornirea, e doar „de rezolvat"',
    taie: [[`            $lipsaSchema !== []\n        );`, `            false\n        );`]],
    // Regulile statice NU se uită la gravitate — și nu trebuie să se uite, ar fi
    // o a doua listă de ținut. Paza ei e proba vie.
    picaDoc: [],
    picaViu: ['un tabel lipsă din schemă blochează pornirea'],
  },
  {
    nume: 'C · rândurile care nu s-au putut măsura bifate verde („prezente")',
    taie: [[
      `                $lipsa === [] && $nestiut === [],`,
      `                $lipsa === [],`,
    ]],
    // Pe o bază oprită la `002` tabelul `referral_commissions` nu există, deci
    // coloanele migrării 012 nu se pot măsura. Cu rândul de mai sus stricat,
    // pagina scrie „prezente" fără să se fi uitat nimeni la nimic — aceeași
    // tăcere verde, mutată cu un cot mai încolo. Regulile statice nu se uită la
    // asta (citesc sursa, nu o bază), deci paza e tot a probei vii.
    picaDoc: [],
    picaViu: ['ce nu s-a putut măsura e spus pe față, nu bifat verde'],
  },
  // ─── STRICĂCIUNE INERTĂ ──────────────────────────────────────────────────
  //
  // Regula `folderul sql chiar creeaza tabele` e paza harnașamentului: se
  // înroșește numai dacă extractorul nu mai găsește niciun `CREATE TABLE` în
  // `sql/`. Ca s-o dovedim ar trebui stricat un fișier din `sql/`, iar banda
  // asta n-are voie să atingă `sql/` — schema nu se schimbă aici. Rândul de mai
  // jos strică pagina într-un loc pe care nicio regulă nu-l vede, și e scris
  // tocmai ca golul să fie spus, nu ascuns.
  {
    nume: 'INERT · diacriticele din numele rândului stricate (nicio regulă nu se uită la ele)',
    taie: [[`            'Tabelele aplicației',`, `            'Tabelele aplicatiei',`]],
    picaDoc: [],
    picaViu: [],
  },
];

/* ──────────────────────────────────────────────────────── mașinăria ── */

/** Rulează `test-doc-actualizari.mjs` și citește ce a picat. */
function ruleazaDoc() {
  const r = spawnSync(process.execPath, [UNEALTA_DOC], { cwd: ROOT, encoding: 'utf8' });
  const out = (r.stdout || '') + (r.stderr || '');
  const picate = new Set();
  let randuri = 0;
  for (const linie of out.split('\n')) {
    const m = linie.match(/^\s*✗\s(.+)$/);
    if (m) { picate.add(m[1].trim()); randuri++; }
  }
  // O rulare moartă la mijloc are și ea rânduri roșii (cele apucate), dar ele nu
  // spun nimic despre regulile care n-au mai rulat. Socoteala de la capăt
  // trebuie să existe ȘI să se potrivească cu rândurile citite.
  const socoteala = out.match(/^\s*(\d+) trecute · (\d+) eșuate/m);
  const eroare = (out.match(/^\[?([A-Za-z]*Error: [^\]\n]*)/m) || [])[1];
  let deCe = '';
  if (!socoteala) deCe = 'test-doc-actualizari s-a oprit înainte de socoteala de la capăt: ' + (eroare || 'fără nicio eroare tipărită');
  else if (Number(socoteala[2]) !== randuri) deCe = `socoteala spune ${socoteala[2]} eșuate, dar s-au citit ${randuri} rânduri roșii`;
  return { picate, trecute: socoteala ? Number(socoteala[1]) : 0, intreaga: deCe === '', deCe };
}

/** Rulează `test-defect-instalare.php` și citește ce a picat. */
function ruleazaViu() {
  const r = spawnSync(php(), [UNEALTA_VIE], { cwd: ROOT, encoding: 'utf8' });
  const out = (r.stdout || '') + (r.stderr || '');
  const picate = [];
  let randuri = 0;
  for (const linie of out.split('\n')) {
    const m = linie.match(/^\s*FAIL\s+(.+)$/);
    if (m) { picate.push(m[1].trim()); randuri++; }
  }
  const socoteala = out.match(/^(\d+) trecute · (\d+) eșuate/m);
  const eroare = (out.match(/^(?:PHP )?(?:Fatal error|Parse error)[^\n]*/m) || [])[0];
  let deCe = '';
  if (!socoteala) deCe = 'test-defect-instalare s-a oprit înainte de socoteala de la capăt: ' + (eroare || 'fără nicio eroare tipărită');
  else if (Number(socoteala[2]) !== randuri) deCe = `socoteala spune ${socoteala[2]} eșuate, dar s-au citit ${randuri} rânduri FAIL`;
  return { picate, trecute: socoteala ? Number(socoteala[1]) : 0, intreaga: deCe === '', deCe };
}

const areViu = (rulare, bucata) => rulare.picate.some((p) => p.includes(bucata));

const paza = pazesteFisiere([PAGINA], 'tabelele-verificarii');
const BUN = fs.readFileSync(ABS, 'utf8');

let intreg = true;
const neintregi = [];
let docBun = 0, viuBun = 0;

try {
  console.log('\n=== lumea bună: regulile noastre sunt verzi ===');
  {
    const d = ruleazaDoc();
    if (!d.intreaga) {
      neintregi.push('lumea bună (doc): ' + d.deCe);
      console.log('  —     nejudecat: harnașamentul, nu codul (vezi la sfârșit)');
    } else {
      docBun = d.trecute;
      for (const r of REGULI_DOC) check(`pe pagina întreagă, «${r}» e verde`, !d.picate.has(r));
      // Alte benzi lucrează ACUM. Ce mai pică nu e al nostru și nu se repară de
      // aici, dar se scrie pe nume.
      const straine = [...d.picate].filter((p) => !REGULI_DOC.includes(p));
      console.log(`  ·     ${d.trecute} reguli trecute`
        + (straine.length ? ` · roșii care NU sunt ale acestei benzi: ${straine.join(' · ')}` : ''));
    }

    const v = ruleazaViu();
    if (!v.intreaga) {
      neintregi.push('lumea bună (viu): ' + v.deCe);
      console.log('  —     nejudecat: harnașamentul, nu codul (vezi la sfârșit)');
    } else {
      viuBun = v.trecute;
      check('pe pagina întreagă, test-defect-instalare n-are niciun eșec', v.picate.length === 0,
        v.picate.slice(0, 3).join(' · '));
      console.log(`  ·     ${v.trecute} reguli trecute`);
    }
  }

  for (const d of DEFECTE) {
    console.log(`\n=== ${d.nume} ===`);

    let stricat = BUN, potrivit = true;
    for (const [din, catre] of d.taie) {
      const cate = stricat.split(din).length - 1;
      if (cate !== 1) {
        check(`${d.nume}: bucata de stricat se găsește exact o dată`, false,
          `găsită de ${cate} ori — tiparul s-a depărtat de fișier: «${din.slice(0, 60)}…»`);
        potrivit = false;
        break;
      }
      // Funcție, nu șir: într-un șir de înlocuire `$&` ar însemna „ce s-a
      // găsit", iar `\\` s-ar înjumătăți. Textele de aici au și una, și alta.
      stricat = stricat.replace(din, () => catre);
    }
    if (!potrivit) continue;

    if (stricat === BUN) {
      check(`${d.nume}: stricăciunea chiar schimbă fișierul`, false, 'textul a ieșit neschimbat');
      continue;
    }

    let rd = null, rv = null;
    try {
      fs.writeFileSync(ABS, stricat);
      rd = ruleazaDoc();
      if (d.picaViu.length) rv = ruleazaViu();
    } finally {
      fs.writeFileSync(ABS, BUN);
    }

    if (!rd.intreaga) {
      neintregi.push(`${d.nume} (doc): ${rd.deCe}`);
      console.log('  —     nejudecat: harnașamentul, nu codul (vezi la sfârșit)');
    } else {
      for (const regula of d.picaDoc) {
        check(`«${regula}» pică`, rd.picate.has(regula),
          'au picat în schimb: ' + ([...rd.picate].join(' · ') || 'nimic'));
      }
      // Regulile noastre care NU sunt așteptate să pice trebuie să rămână verzi:
      // altfel n-am ști care dintre ele a prins stricăciunea.
      for (const regula of REGULI_DOC.filter((r) => !d.picaDoc.includes(r))) {
        check(`«${regula}» rămâne verde (nu ea prinde stricăciunea asta)`, !rd.picate.has(regula));
      }
    }

    if (rv && !rv.intreaga) {
      neintregi.push(`${d.nume} (viu): ${rv.deCe}`);
      console.log('  —     nejudecat: harnașamentul, nu codul (vezi la sfârșit)');
    } else if (rv) {
      for (const bucata of d.picaViu) {
        check(`proba vie: «${bucata}…» pică`, areViu(rv, bucata),
          'au picat în schimb: ' + (rv.picate.join(' · ') || 'nimic'));
      }
    }

    if (!d.picaDoc.length && !d.picaViu.length) {
      // Stricăciune INERTĂ ori gol de acoperire: nu se așteaptă nimic, deci nu se
      // dovedește nimic. Se tipărește doar ce s-a văzut.
      const straine = rd.intreaga ? [...rd.picate].filter((p) => REGULI_DOC.includes(p)) : [];
      console.log(`  ·     inertă: din regulile noastre au picat ${straine.length}`
        + (straine.length ? ' (' + straine.join(' · ') + ')' : ''));
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
check('fiecare rulare a uneltelor a mers până la capăt', neintregi.length === 0,
  'harnașamentul, nu codul: ' + neintregi.slice(0, 3).join(' · '));

console.log('\n=== fișierul a rămas cum a fost ===');
check('„' + PAGINA + '" a fost pus la loc', intreg,
  'copia bună e în .claude/.adapost/tabelele-verificarii/');

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} picate   (pe lumea bună: ${docBun} reguli statice, ${viuBun} vii)`);
if (esecuri.length) {
  console.log('\nEșecuri:');
  esecuri.forEach((f) => console.log('  · ' + f));
}
process.exitCode = fail ? 1 : 0;
