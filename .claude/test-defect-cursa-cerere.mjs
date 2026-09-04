// Proba pentru cursa dintre „Acceptă" și „Respinge" pe aceeași cerere.
//
//   node .claude/test-defect-cursa-cerere.mjs
//   GP_BASE=http://127.0.0.1:8200/api node .claude/test-defect-cursa-cerere.mjs
//
// Defectul: `route_requests_reject` citea starea în afara oricărei tranzacții
// și apoi scria `status = 'respinsa'` fără nicio condiție pe stare. Cele două
// butoane apăsate în aceeași clipă, de pe două tablete, lăsau cererea trecută
// ca respinsă — dar comanda era deja născută și plecase în laborator: marfa se
// făcea, iar șoferul mergea la un client căruia tocmai i se spusese că nu se
// poate. Mai departe, `route_requests_reopen` vedea o cerere „respinsa", o
// readucea la „nou", iar de acolo o a doua acceptare năștea A DOUA comandă din
// aceleași date.
//
// Reparația: respingerea blochează rândul în tranzacție (SELECT ... FOR UPDATE)
// și marchează cu `WHERE id = ? AND status = 'nou'`, verificând rowCount — exact
// ce face acceptarea. Redeschiderea capătă aceeași condiție pe stare și refuză
// o cerere care are deja o comandă în spate.
//
// Verificarea care pică sigur dacă reparația e dată înapoi:
//   „respingerea peste o cerere deja acceptată nu atinge niciun rând" —
// aceeași instrucțiune scoasă din sursă, rulată pe bază într-o tranzacție dată
// înapoi. Fără condiția pe stare atinge rândul și proba pică. La fel și
// perechea ei de pe sursă, „marcarea ca respinsă cere starea «nou»".

import { execFileSync, spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BAZA_DE_PROBA, configulLuiBase } from './baza-probei.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';

let pass = 0, fail = 0;
const failures = [];
const check = (name, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
};

/* ------------------------------------------------------------- unelte -- */

const agent = () => ({ cookies: new Map(), csrf: '' });

async function call(a, method, path_, body) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (a.csrf && method !== 'GET') headers['X-CSRF-Token'] = a.csrf;
  if (a.cookies.size) headers['Cookie'] = [...a.cookies].map(([k, v]) => `${k}=${v}`).join('; ');

  const res = await fetch(BASE + path_, {
    method, headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  for (const raw of res.headers.getSetCookie?.() ?? []) {
    const [pair] = raw.split(';');
    const i = pair.indexOf('=');
    const k = pair.slice(0, i).trim(), v = pair.slice(i + 1).trim();
    if (v === '') a.cookies.delete(k); else a.cookies.set(k, v);
  }
  const raw = await res.text();
  let data = null;
  if (raw !== '') {
    try { data = JSON.parse(raw); }
    catch { throw new Error('răspuns care nu e JSON la ' + method + ' ' + path_ + ': ' + raw.slice(0, 200).replace(/\s+/g, ' ')); }
  }
  return { status: res.status, data };
}
const get = (a, p) => call(a, 'GET', p);
const post = (a, p, b) => call(a, 'POST', p, b ?? {});

/** Pe Laragon binarele nu sunt în PATH; le căutăm ca celelalte probe. */
const binar = (env, folder, exe) => {
  if (process.env[env]) return process.env[env];
  const g = 'C:/laragon/bin/' + folder;
  try {
    const v = fs.readdirSync(g).find((d) => d.startsWith(folder + '-'));
    if (v) return folder === 'mysql' ? `${g}/${v}/bin/${exe}` : `${g}/${v}/${exe}`;
  } catch { /* alt sistem */ }
  return exe.replace('.exe', '');
};
const PHP = binar('GP_PHP', 'php', 'php.exe');
const MYSQL = binar('GP_MYSQL', 'mysql', 'mysql.exe');

const CFG = configulLuiBase(BASE);
let db = null;
try {
  db = JSON.parse(execFileSync(PHP, [
    '-r', '$c = require $argv[1]; echo json_encode($c["db"]);',
    '--', CFG || path.join(ROOT, 'api', 'config.php'),
  ], { cwd: ROOT, encoding: 'utf8' }));
} catch { /* fără PHP la îndemână rămân verificările pe sursă și prin HTTP */ }

// Plasă de siguranță: pe o bază adevărată proba n-are ce căuta.
const PE_BAZA = !!db && BAZA_DE_PROBA.test(db.name);

/** Rulează SQL pe baza din spatele lui GP_BASE și întoarce rândurile brute. */
const sql = (text) => execFileSync(MYSQL, [
  '--default-character-set=utf8mb4',
  '-u', db.user, ...(db.pass ? ['-p' + db.pass] : []),
  '-h', db.host, '-P', String(db.port), db.name, '-N', '-B', '-e', text,
], { encoding: 'utf8' }).trim();

const asteapta = (ms) => new Promise((r) => setTimeout(r, ms));

/* ---------------------------------------------------------- pregătire -- */

const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica drept admin: ' + r.status); process.exit(1); }
}

// Produsul se ia din catalogul viu: id-urile din seed se pot schimba, iar o
// probă care le presupune pică din alt motiv decât cel pe care îl urmărește.
const PRODUS = (await get(admin, '/products')).data?.products?.[0]?.id;
if (!PRODUS) { console.log('catalogul e gol — proba n-are ce comanda'); process.exit(1); }

const MARCA = 'Cursa Cerere ' + Math.random().toString(36).slice(2, 7);
const cereriFacute = [];

/**
 * O cerere nouă, ca de pe site.
 *
 * Fără `day`: acceptarea pune atunci ziua de azi, deci proba nu depinde de
 * fereastra de zile din Setări și nu pică peste o lună, la altă dată.
 */
async function cerere(eticheta) {
  const r = await post(agent(), '/requests', {
    customerName: MARCA + ' · ' + eticheta,
    phone: '0700 000 000', address: 'Str. Probei 1',
    items: [{ productId: PRODUS, qty: 2, variant: 'copt' }],
  });
  if (r.status !== 201) throw new Error('nu pot trimite cererea de probă: ' + r.status + ' ' + JSON.stringify(r.data));
  cereriFacute.push(r.data.request.id);
  return r.data.request.id;
}

/* =================== 1. ce scrie în sursă ============================== */

console.log('\n=== încuietorile din api/routes/requests.php ===');

const sursa = fs.readFileSync(path.join(ROOT, 'api', 'routes', 'requests.php'), 'utf8');
const bucata = (de, pana) => {
  const i = sursa.indexOf(de);
  const j = pana ? sursa.indexOf(pana, i + 1) : -1;
  return i < 0 ? '' : sursa.slice(i, j < 0 ? undefined : j);
};
const instructiunea = (inceput) => {
  const i = sursa.indexOf(inceput);
  return i < 0 ? '' : sursa.slice(i, sursa.indexOf('"', i)).replace(/\s+/g, ' ').trim();
};

const respingerea = bucata('function route_requests_reject', 'function route_requests_reopen');
const redeschiderea = bucata('function route_requests_reopen', 'function attach_request_items');

const marcareaRespinsa = instructiunea("UPDATE order_requests SET status = 'respinsa'");
check('marcarea cererii ca respinsă cere starea „nou”',
  /WHERE id = \? AND status = 'nou'$/.test(marcareaRespinsa),
  marcareaRespinsa || 'n-am găsit instrucțiunea în api/routes/requests.php');
check('respingerea blochează rândul în tranzacție înainte să scrie',
  /beginTransaction\(\)/.test(respingerea) && /FROM order_requests WHERE id = \? FOR UPDATE/.test(respingerea),
  'route_requests_reject scrie fără să blocheze cererea');
check('respingerea care n-a marcat niciun rând se dă înapoi, cu 409',
  /rowCount\(\)/.test(respingerea) && /rollBack\(\);\s*[\r\n]+\s*fail\([^()]*409, 'already_handled'\)/.test(respingerea),
  'nu văd rowCount() și 409 în route_requests_reject');

const marcareaNou = instructiunea("UPDATE order_requests SET status = 'nou'");
check('redeschiderea cere starea „respinsa” și o cerere fără comandă',
  /WHERE id = \? AND status = 'respinsa' AND order_id IS NULL$/.test(marcareaNou),
  marcareaNou || 'n-am găsit instrucțiunea în api/routes/requests.php');
check('redeschiderea refuză pe față o cerere care are deja comandă',
  /order_id'\] !== null/.test(redeschiderea) && /'has_order'/.test(redeschiderea),
  'route_requests_reopen nu se uită la order_id');

/* =============== 2. aceleași instrucțiuni, rulate pe bază ============== */

console.log('\n=== instrucțiunile puse la încercare pe baza de date ===');

// O cerere dusă până la capăt prin HTTP: acceptată, cu comandă în spate.
const idAcceptata = await cerere('acceptată');
let comandaId = null;
{
  const r = await post(admin, `/requests/${idAcceptata}/accept`, { fulfillment: 'Livrare' });
  check('cererea de probă se acceptă → 201', r.status === 201, `${r.status} ${JSON.stringify(r.data)}`);
  comandaId = r.data?.order?.id ?? null;
}
// Și una respinsă normal, pentru redeschidere.
const idRespinsa = await cerere('respinsă');
{
  const r = await post(admin, `/requests/${idRespinsa}/reject`, { reason: 'capacitate depășită' });
  check('cererea de probă se respinge → 200', r.status === 200, `${r.status} ${JSON.stringify(r.data)}`);
}

if (PE_BAZA && marcareaRespinsa && marcareaNou) {
  // Instrucțiunile se iau din sursă, nu se rescriu aici: dacă cineva scoate
  // condiția din cod, exact ce rulează pe server ajunge și în probă.
  const cuValori = (instr, valori) => instr.replace(/\?/g, () => valori.shift());
  const rulat = (pregatire, instr) =>
    sql(`START TRANSACTION; ${pregatire} ${instr}; SELECT ROW_COUNT(); ROLLBACK;`).split('\n').pop().trim();

  // Respingerea peste o cerere DEJA ACCEPTATĂ. Aici se vede tot defectul:
  // fără condiția pe stare, rândul se schimbă și cererea ajunge „respinsa"
  // deși comanda e făcută și a plecat în laborator.
  const respingePeAcceptata = cuValori(marcareaRespinsa, ['NULL', 'handled_by', String(idAcceptata)]);
  check('respingerea peste o cerere deja acceptată nu atinge niciun rând',
    rulat('', respingePeAcceptata) === '0',
    'a atins rânduri — comanda ar rămâne plecată, iar cererea ar arăta „respinsă”');

  // Perechea: aceeași instrucțiune fără condiție. Dacă nici ea nu atinge
  // rândul, verificarea de sus n-ar dovedi nimic — ar da zero din întâmplare.
  check('fără condiția pe stare, aceeași instrucțiune chiar ar suprascrie rândul',
    rulat('', respingePeAcceptata.replace(" AND status = 'nou'", '')) === '1',
    'rândul nu e de găsit; verificarea de mai sus nu dovedește nimic');

  // Redeschiderea peste o cerere care are deja comandă. Rândul se strică
  // înadins în tranzacție, exact cum îl lăsa cursa, și se dă înapoi la loc.
  const strica = comandaId ? `UPDATE order_requests SET order_id = ${Number(comandaId)} WHERE id = ${idRespinsa};` : '';
  const redeschidePeStricata = cuValori(marcareaNou, [String(idRespinsa)]);
  if (strica) {
    check('redeschiderea unei cereri cu comandă în spate nu atinge niciun rând',
      rulat(strica, redeschidePeStricata) === '0',
      'a atins rândul — cererea s-ar întoarce la „nou” și ar naște a doua comandă');
    check('fără condiția pe order_id, aceeași instrucțiune ar readuce-o la „nou”',
      rulat(strica, redeschidePeStricata.replace(" AND order_id IS NULL", '')) === '1',
      'rândul nu e de găsit; verificarea de mai sus nu dovedește nimic');
  }
} else {
  console.log('  —     sărit: probele pe bază cer PHP, MySQL și o bază de dezvoltare');
}

/* ============ 3. cursa adevărată, cu două procese deodată ============== */

console.log('\n=== „Acceptă” și „Respinge” apăsate în aceeași clipă ===');

// `php -S` răspunde la o singură cerere odată, deci pe el cursa nu se poate
// stârni: a doua apăsare ajunge oricum după prima. Pe găzduire însă fiecare
// cerere are procesul ei. Pornim al doilea proces pe ACEEAȘI bază — atât
// lipsea ca cele două apăsări să se suprapună cu adevărat.
async function portLiber(de = 8600) {
  for (let p = de; p < de + 200; p++) {
    const liber = await new Promise((res) => {
      const s = net.createServer();
      s.once('error', () => res(false));
      s.once('listening', () => s.close(() => res(true)));
      s.listen(p, '127.0.0.1');
    });
    if (liber) return p;
  }
  throw new Error('nu am găsit port liber');
}

let alDoilea = null, BAZA2 = '';
if (PE_BAZA) {
  try {
    const port = await portLiber();
    const jurnal = path.join(os.tmpdir(), 'gp-cursa-cerere-' + port + '.log');
    const env = { ...process.env };
    if (CFG) env.GP_CONFIG = CFG; else delete env.GP_CONFIG;
    const out = fs.openSync(jurnal, 'w');
    alDoilea = spawn(PHP, ['-S', '127.0.0.1:' + port, '-t', '.', '.claude/dev-server.php'], {
      cwd: ROOT, env, detached: true, windowsHide: true, stdio: ['ignore', out, out],
    });
    BAZA2 = 'http://127.0.0.1:' + port + '/api';
    let gata = false;
    for (let i = 0; i < 60 && !gata; i++) {
      try { gata = (await fetch(BAZA2 + '/products')).status === 200; } catch { /* încă pornește */ }
      if (!gata) await asteapta(200);
    }
    if (!gata) throw new Error('al doilea server nu a pornit; jurnal: ' + fs.readFileSync(jurnal, 'utf8').slice(0, 300));
  } catch (e) {
    if (alDoilea) spawnSync('taskkill', ['/F', '/T', '/PID', String(alDoilea.pid)], { stdio: 'ignore' });
    alDoilea = null;
    console.log('  —     sărit: nu am putut porni al doilea proces PHP (' + e.message.split('\n')[0] + ')');
  }
}

if (alDoilea) {
  // Respingerea pleacă pe al doilea proces, cu întârzieri diferite: undeva
  // între ele cade exact clipa în care acceptarea își ține tranzacția deschisă.
  const doiHeaders = () => ({
    'Content-Type': 'application/json',
    'X-CSRF-Token': admin.csrf,
    Cookie: [...admin.cookies].map(([k, v]) => `${k}=${v}`).join('; '),
  });

  let incoerente = 0, amandouaAuReusit = 0;
  for (const t of [0, 5, 10, 15, 20, 25, 30, 35, 40, 50, 25, 40]) {
    const id = await cerere('cursa +' + t);
    const acceptarea = post(admin, `/requests/${id}/accept`, { fulfillment: 'Livrare' });
    const respingerea2 = (async () => {
      await asteapta(t);
      const res = await fetch(BAZA2 + `/requests/${id}/reject`, {
        method: 'POST', headers: doiHeaders(), body: JSON.stringify({ reason: 'nu avem capacitate' }),
      });
      return res.status;
    })();
    const [ra, sb] = await Promise.all([acceptarea, respingerea2]);

    if (ra.status === 201 && sb === 200) amandouaAuReusit++;
    if (PE_BAZA) {
      const [stare, comenzi] = sql(
        `SELECT status FROM order_requests WHERE id = ${id};`
        + ` SELECT COUNT(*) FROM orders WHERE request_id = ${id};`
      ).split('\n').map((x) => x.trim());
      if (stare === 'respinsa' && comenzi !== '0') incoerente++;
      if (stare === 'acceptata' && comenzi !== '1') incoerente++;
    }
  }

  check('nu s-a întâmplat ca ambele apăsări să reușească',
    amandouaAuReusit === 0,
    amandouaAuReusit + ' curse în care acceptarea a dat 201 ȘI respingerea 200');
  check('nicio cerere nu a rămas „respinsă” cu comanda plecată',
    incoerente === 0,
    incoerente + ' cereri cu starea și comanda în dezacord');

  spawnSync('taskkill', ['/F', '/T', '/PID', String(alDoilea.pid)], { stdio: 'ignore' });
}

/* ============ 4. plasa de la redeschidere, prin API =================== */

console.log('\n=== redeschiderea nu mai naște a doua comandă ===');

if (PE_BAZA && comandaId) {
  // Cerere lăsată exact cum o lăsa cursa: trecută „respinsa", dar cu o comandă
  // adevărată în spate. Așa arată rândurile rămase în baza reală de dinainte
  // de reparație — plasa e pentru ele.
  const idStricata = await cerere('rămasă strâmbă');
  await post(admin, `/requests/${idStricata}/reject`, { reason: 'strâmbă înadins' });
  sql(`UPDATE order_requests SET order_id = ${Number(comandaId)} WHERE id = ${idStricata}`);

  const r = await post(admin, `/requests/${idStricata}/reopen`, {});
  check('redeschiderea unei cereri cu comandă în spate → 409', r.status === 409,
    `${r.status} ${JSON.stringify(r.data)}`);
  check('mesajul spune care comandă o oprește și ce are de făcut',
    /comanda #?\d+/.test(r.data?.error?.message || '') && /anulează comanda/i.test(r.data?.error?.message || ''),
    JSON.stringify(r.data?.error?.message));
  const stare = sql(`SELECT status FROM order_requests WHERE id = ${idStricata}`).trim();
  check('cererea rămâne respinsă după refuz', stare === 'respinsa', stare);
}

/* ====== 5. plasa de la acceptare, pentru rândurile deja redeschise ===== */

console.log('\n=== acceptarea nu naște a doua comandă pe un rând vechi ===');

if (PE_BAZA && comandaId) {
  // Starea pe care nicio rută n-o mai poate produce, dar care există deja în
  // bazele lovite de defect: „nou" + `order_id` completat. Se năștea din cursă
  // (rând lăsat „respinsa" cu comandă), urmată de o redeschidere apăsată
  // înainte de reparație, pe vremea când redeschiderea nu se uita la
  // `order_id`. Aici o scriem de-a dreptul în bază, fiindcă e singurul mod în
  // care o mai poți întâlni.
  const idVeche = await cerere('redeschisă înainte de reparație');
  sql(`UPDATE order_requests SET status = 'nou', order_id = ${Number(comandaId)} WHERE id = ${idVeche}`);

  const inainte = Number(sql(`SELECT COUNT(*) FROM orders WHERE request_id = ${idVeche}`).trim() || '0');
  const r = await post(admin, `/requests/${idVeche}/accept`, {
    customerName: 'Proba Cursa Cerere', fulfillment: 'Livrare',
  });
  check('acceptarea unui rând vechi cu comandă în spate → 409', r.status === 409,
    `${r.status} ${JSON.stringify(r.data)}`);
  check('refuzul are codul „has_order"', r.data?.error?.code === 'has_order',
    JSON.stringify(r.data?.error));
  check('mesajul îi spune administratorului care comandă îl oprește',
    /comanda #?\d+/.test(r.data?.error?.message || ''), JSON.stringify(r.data?.error?.message));

  const dupa = Number(sql(`SELECT COUNT(*) FROM orders WHERE request_id = ${idVeche}`).trim() || '0');
  check('nu s-a născut nicio comandă în plus', dupa === inainte, `${inainte} → ${dupa}`);
}

console.log('\n=== fluxurile bune merg mai departe ===');
{
  const id = await cerere('flux normal');
  const r1 = await post(admin, `/requests/${id}/reject`, { reason: 'apăsat din greșeală' });
  check('respingerea unei cereri noi → 200', r1.status === 200, `${r1.status} ${JSON.stringify(r1.data)}`);
  const r2 = await post(admin, `/requests/${id}/reject`, { reason: 'încă o dată' });
  check('a doua respingere → 409', r2.status === 409, `a dat ${r2.status}`);
  const r3 = await post(admin, `/requests/${id}/reopen`, {});
  check('anularea refuzului → 200', r3.status === 200, `${r3.status} ${JSON.stringify(r3.data)}`);
  const r4 = await post(admin, `/requests/${id}/reopen`, {});
  check('a doua redeschidere → 409', r4.status === 409, `a dat ${r4.status}`);
  const r5 = await post(admin, `/requests/${id}/accept`, { fulfillment: 'Ridicare' });
  check('cererea redeschisă se acceptă → 201', r5.status === 201, `${r5.status} ${JSON.stringify(r5.data)}`);
  const r6 = await post(admin, `/requests/${id}/reject`, { reason: 'prea târziu' });
  check('respingerea după acceptare → 409, nu 200', r6.status === 409, `a dat ${r6.status}`);
  const r7 = await post(admin, `/requests/${id}/reopen`, {});
  check('redeschiderea unei cereri acceptate → 409', r7.status === 409, `a dat ${r7.status}`);

  const r8 = await post(admin, '/requests/99999999/reject', { reason: 'x' });
  check('respingerea unei cereri inexistente → 404', r8.status === 404, `a dat ${r8.status}`);
}

/* -------------------------------------------------------------- final -- */

// Proba își strânge singură urmele: cererile și comenzile de aici n-au ce
// căuta în panoul administratorului la rulările următoare.
if (PE_BAZA && cereriFacute.length) {
  const lista = cereriFacute.map(Number).join(',');
  sql(`DELETE FROM orders WHERE request_id IN (${lista});`
    + ` DELETE FROM order_requests WHERE id IN (${lista});`);
}

console.log(`\n${pass} trecute, ${fail} picate`);
if (fail) {
  console.log('\nPicate:');
  for (const f of failures) console.log('  - ' + f);
  process.exit(1);
}
process.exit(0);
