// Probe pentru uneltele din .claude/ — grupa „unelte".
//
//   node .claude/test-defect-unelte.mjs
//
// Trei dintre defectele reparate aici sunt de același fel: o verificare care
// nu poate să pice. E cel mai periculos fel de defect, fiindcă nu lipsește
// nimic din listă — dimpotrivă, apare un rând verde care spune că s-a
// verificat. De aceea probele de mai jos nu se uită la ce scrie în unealtă, ci
// îi iau condiția din fișier și o rulează pe intrări făcute anume: o dată pe
// lumea în care lucrul verificat e stricat (condiția TREBUIE să iasă falsă) și
// o dată pe lumea bună (TREBUIE să iasă adevărată). O probă care nu arată
// amândouă rezultatele nu dovedește nimic.

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import vm from 'node:vm';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');

let pass = 0, fail = 0;
const failures = [];

function check(name, cond, extra = '') {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
}

/**
 * Textul unui apel întreg, de la `check(` până la paranteza pereche.
 *
 * Luăm condiția din fișierul adevărat, nu o rescriem aici: o copie a condiției
 * ar trece verde și după ce cineva o strică pe cea din unealtă — exact boala pe
 * care probele astea o vindecă.
 */
function apelDinFisier(src, inceput) {
  const i = src.indexOf(inceput);
  if (i < 0) return null;
  let d = 0, j = src.indexOf('(', i);
  const start = j;
  for (; j < src.length; j++) {
    if (src[j] === '(') d++;
    else if (src[j] === ')' && --d === 0) return src.slice(i, j + 1);
  }
  return start < 0 ? null : null;
}

/* ================================================ .claude/check-pages.mjs == */
//
// Verificarea cheilor duplicate din `state` cerea acolada de închidere pe
// aceeași linie cu `state = {` și nu accepta forma `this.state = {`. Sărea în
// tăcere peste 6 din 14 pagini — printre ele cele mai mari trei. O rulăm pe
// pagini-fixtură scrise anume greșit, prin GP_PAGES_ROOT.

console.log('\n=== check-pages: cheile duplicate din „state" ===');

const PAGINA = (corp) => `<!doctype html>
<x-dc>
<script type="text/x-dc">
class Pagina extends DCLogic {
  constructor(props) {
    super(props);
${corp}
  }
  render() { return null; }
}
</script>
`;

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'gp-unelte-'));
function ruleazaCheckPages(fisiere) {
  for (const f of fs.readdirSync(dir)) fs.rmSync(path.join(dir, f));
  for (const [nume, corp] of Object.entries(fisiere)) {
    fs.writeFileSync(path.join(dir, nume), PAGINA(corp), 'utf8');
  }
  const r = spawnSync(process.execPath, [path.join(HERE, 'check-pages.mjs')], {
    cwd: ROOT, encoding: 'utf8', env: { ...process.env, GP_PAGES_ROOT: dir },
  });
  return { cod: r.status, out: (r.stdout || '') + (r.stderr || '') };
}

// Forma pe care tiparul vechi o prindea. Rămâne aici ca plasă: reparația nu
// avea voie să piardă cazul care mergea.
{
  const r = ruleazaCheckPages({
    'Un Rand.dc.html': [
      '    this.x = 1;',
      '  }',
      "  state = { zi: 1, filtru: 'toate', zi: 2 };",
      '  altceva() {',
    ].join('\n'),
  });
  check('starea pe un singur rând: cheia dublă e prinsă',
    r.cod !== 0 && /cheia „zi" de două ori/.test(r.out), r.out.trim());
}

// Forma pe care o sărea: obiect pe mai multe rânduri, scris cu `this.state`.
{
  const r = ruleazaCheckPages({
    'Multe Randuri.dc.html': [
      '    this.state = {',
      '      zi: 1,',
      "      filtru: 'toate',",
      '      pagina: 0,',
      '      zi: 2,',
      '    };',
    ].join('\n'),
  });
  check('starea pe mai multe rânduri: cheia dublă e prinsă',
    r.cod !== 0 && /cheia „zi" de două ori/.test(r.out), r.out.trim());
}

// Și forma fără `this.`, tot pe mai multe rânduri (câmp de clasă).
{
  const r = ruleazaCheckPages({
    'Camp De Clasa.dc.html': [
      '    this.x = 1;',
      '  }',
      '  state = {',
      '    lista: [],',
      '    lista: null,',
      '  };',
      '  altceva() {',
    ].join('\n'),
  });
  check('câmpul de clasă pe mai multe rânduri: cheia dublă e prinsă',
    r.cod !== 0 && /cheia „lista" de două ori/.test(r.out), r.out.trim());
}

// O stare curată nu trebuie să dea alarmă falsă, nici când un obiect imbricat
// folosește un nume care există și afară.
{
  const r = ruleazaCheckPages({
    'Curata.dc.html': [
      '    this.state = {',
      '      zi: 1,',
      '      filtre: { zi: 2, ord: 1 },',
      "      etichete: ['zi', 'zi'],",
      '      gata: false,',
      '    };',
    ].join('\n'),
  });
  check('starea curată nu dă alarmă falsă',
    r.cod === 0 && /1 cu starea verificată/.test(r.out), r.out.trim());
}

// Acoperirea pe paginile adevărate: câte pagini au un obiect de stare și
// pentru câte spune unealta că l-a găsit. Cu tiparul vechi ieșea 8 din 14.
{
  const cuStare = fs.readdirSync(ROOT).filter((f) => f.endsWith('.dc.html')).filter((f) => {
    const m = fs.readFileSync(path.join(ROOT, f), 'utf8')
      .match(/<script[^>]*type="text\/x-dc"[^>]*>([\s\S]*?)<\/script>/);
    return !!m && /\bstate\s*=\s*\{/.test(m[1]);
  }).length;
  const r = spawnSync(process.execPath, [path.join(HERE, 'check-pages.mjs')], {
    cwd: ROOT, encoding: 'utf8',
  });
  const m = ((r.stdout || '') + (r.stderr || '')).match(/\|\s*(\d+) cu starea verificată/);
  const gasite = m ? Number(m[1]) : -1;
  check('verificarea rulează pe toate paginile care au stare',
    cuStare > 0 && gasite === cuStare, `pagini cu stare: ${cuStare}, verificate: ${gasite}`);
}

fs.rmSync(dir, { recursive: true, force: true });

/* =================================================== .claude/test-api.mjs == */
//
// `check('contul temporar a fost șters', !!id, …)` afirma ștergerea în nume,
// dar verifica prezența numărului primit la creare — adevărată orice ar fi
// răspuns serverul. Luăm blocul din fișier și îl rulăm pe patru lumi.

console.log('\n=== test-api: „contul temporar a fost șters" ===');
{
  const src = fs.readFileSync(path.join(HERE, 'test-api.mjs'), 'utf8');
  const i = src.indexOf("const id = made.data");
  const apel = apelDinFisier(src.slice(i), "check('contul temporar a fost șters'");
  const bloc = apel === null ? null : src.slice(i, i + src.slice(i).indexOf(apel) + apel.length) + ';';
  check('blocul de ștergere a fost găsit în test-api.mjs', bloc !== null,
    'proba nu-și mai găsește ținta — s-a rescris blocul?');

  async function conditia(sters, listaContine) {
    const tmpUser = 'proba.parola.zzz';
    let cond = null;
    const cutie = {
      made: { status: 201, data: { user: { id: 733, username: tmpUser } } },
      tmpUser,
      admin: {},
      del: async () => sters,
      get: async () => ({
        status: 200,
        data: { users: listaContine ? [{ id: 733, username: tmpUser }] : [{ id: 1, username: 'testadmin' }] },
      }),
      check: (_n, c) => { cond = !!c; },
      JSON,
    };
    await vm.runInNewContext('(async () => {\n' + bloc + '\n})()', cutie);
    return cond;
  }

  if (bloc) {
    const bun = await conditia({ status: 200, data: { ok: true } }, false);
    check('serverul șterge cu adevărat → proba trece', bun === true, `a ieșit ${bun}`);

    const drepturi = await conditia({ status: 403, data: { error: { code: 'forbidden' } } }, true);
    check('drepturi inversate pe DELETE /users/{id} → proba pică', drepturi === false, `a ieșit ${drepturi}`);

    const scoasa = await conditia({ status: 404, data: { error: { code: 'not_found' } } }, true);
    check('ruta de ștergere scoasă din tabel → proba pică', scoasa === false, `a ieșit ${scoasa}`);

    // Cazul cel mai urât: serverul răspunde „ok", dar interogarea n-a șters
    // nimic. Doar recitirea listei îl prinde.
    const minte = await conditia({ status: 200, data: { ok: true } }, true);
    check('răspuns „ok" dar contul rămâne în bază → proba pică', minte === false, `a ieșit ${minte}`);
  }
}

/* =================================================== .claude/test-seo.mjs == */
//
// „app.html cerut direct nu servește o pagină fără antet" accepta printre
// rezultatele bune și un 200 — chiar situația pe care numele o refuză.

console.log('\n=== test-seo: „app.html cerut direct" ===');
{
  const src = fs.readFileSync(path.join(HERE, 'test-seo.mjs'), 'utf8');

  check('nu mai trece un 200 printre rezultatele bune',
    !/direct\.status === 200 \|\| direct\.status === 301/.test(src),
    'condiția veche e încă în fișier');

  // Regula de rescriere, citită din pachetul care ajunge pe cPanel.
  const apelHt = apelDinFisier(src, "check('.htaccess mută app.html cerut direct");
  check('proba citește regula din .htaccess-ul publicat', apelHt !== null,
    'nu găsesc verificarea regulii');
  if (apelHt) {
    const cu = `# ceva\nRewriteRule ^app\\.html$ / [R=301,L]\n# altceva\n`;
    const fara = `# ceva\n# altceva\n`;
    const rulaHt = (ht) => {
      let cond = null;
      vm.runInNewContext(apelHt + ';', { ht, check: (_n, c) => { cond = !!c; } });
      return cond;
    };
    check('cu regula în .htaccess → proba trece', rulaHt(cu) === true);
    check('cu regula ștearsă din .htaccess → proba pică', rulaHt(fara) === false);
  }

  // Iar cererea, acolo unde se poate face: un 200 fără antet nu mai e acceptat.
  const apelCerere = apelDinFisier(src, "check('app.html cerut direct nu servește o pagină fără antet'");
  check('proba pe cerere există mai departe', apelCerere !== null);
  if (apelCerere) {
    const rulaCerere = (status, corp) => {
      let cond = null;
      vm.runInNewContext(apelCerere + ';', {
        direct: { status }, corp, check: (_n, c) => { cond = !!c; },
      });
      return cond;
    };
    const gol = '<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>';
    const cuAntet = '<html><head><title>Golden Pastry</title>'
      + '<link rel="canonical" href="https://goldenpastry.ro/"></head></html>';
    check('200 cu pagina fără antet → proba pică', rulaCerere(200, gol) === false);
    check('301 spre „/" → proba trece', rulaCerere(301, '') === true);
    check('200 dar cu antetul pus de server → proba trece', rulaCerere(200, cuAntet) === true);
  }
}

/* =============================================== .claude/build-deploy.mjs == */
//
// `Options -Indexes` era scris doar în .htaccess-ul API-ului. Cele trei
// subdomenii de pagini plecau fără el, iar pe cPanel folderele fără index
// (products/, _ds/, uploads/) își arată conținutul.

console.log('\n=== build-deploy: fără răsfoirea folderelor ===');
{
  const src = fs.readFileSync(path.join(HERE, 'build-deploy.mjs'), 'utf8');
  const sablon = (nume) => {
    const m = src.match(new RegExp('const ' + nume + ' = `([\\s\\S]*?)`;'));
    return m ? m[1] : null;
  };
  const HT_COMMON = sablon('HT_COMMON');
  const HT_SEO = sablon('HT_SEO');
  const HT_NOINDEX = sablon('HT_NOINDEX');
  const HT_API = sablon('HT_API');
  check('șabloanele de .htaccess se citesc din build-deploy.mjs',
    [HT_COMMON, HT_SEO, HT_NOINDEX, HT_API].every((s) => typeof s === 'string' && s.length > 0));

  // Exact compunerile pe care le scrie build-deploy.mjs pe disc.
  const arePe = (t) => t !== null && /^Options -Indexes\s*$/m.test(t);
  const compuneri = {
    'public_html': HT_COMMON === null || HT_SEO === null ? null : HT_COMMON + HT_SEO,
    'clienti': HT_COMMON === null || HT_NOINDEX === null ? null : HT_COMMON + HT_NOINDEX,
    'lab': HT_COMMON === null || HT_NOINDEX === null ? null : HT_COMMON + HT_NOINDEX,
    'api': HT_API,
  };
  for (const [nume, text] of Object.entries(compuneri)) {
    check(`.htaccess pentru ${nume} interzice răsfoirea folderului`, arePe(text),
      text === null ? 'șablon negăsit' : 'lipsește „Options -Indexes"');
  }

  // Și în pachetul chiar construit, dacă există. Nu-l reconstruim: folderul
  // deploy/ e ținut deschis de serverul de pe 8101.
  for (const sub of ['public_html', 'clienti', 'lab', 'api']) {
    const f = path.join(ROOT, 'deploy', sub, '.htaccess');
    if (!fs.existsSync(f)) {
      console.log(`  —     deploy/${sub}/.htaccess lipsește (rulează node .claude/build-deploy.mjs)`);
      continue;
    }
    check(`pachetul construit: deploy/${sub}/.htaccess are „Options -Indexes"`,
      /^Options -Indexes\s*$/m.test(fs.readFileSync(f, 'utf8')));
  }
}

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} picate`);
if (failures.length) {
  console.log('\nEșecuri:');
  failures.forEach((f) => console.log('  · ' + f));
}
process.exit(fail ? 1 : 0);
