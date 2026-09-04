// Probele pentru cele trei defecte din `api/routes/orders.php`.
//
//   node .claude/test-defect-comenzi-api.mjs
//
// 1. PATCH /orders recalcula `customer_id` din numele scris în formular, la
//    fiecare salvare. Contul redenumit din pagina Conturi nu se mai regăsea
//    după nume, iar salvarea ștergea legătura: comanda dispărea din „Comanda
//    mea" a clientului, deși pleca pe rută. Legătura nu se mai refăcea nicicând.
// 2. Referința era verificată la 32 de caractere, dar coloana `orders.ref` ține
//    24: o referință mai lungă trecea de verificare și pica în MySQL, pe 1406.
//    Excepția nu e SQLSTATE 23000, deci ieșea „Eroare internă" 500, iar
//    tranzacția anula tot ce se corectase în formular.
// 3. POST /orders trimitea răspunsul DUPĂ anunțuri. Cu furnizorul de WhatsApp
//    sau Telegram căzut, fiecare canal ține conexiunea până la cincisprezece
//    secunde, iar peste `max_execution_time` procesul era ucis: panoul arăta
//    eroare pentru o comandă salvată, iar operatorul o introducea din nou.
//
// Fiecare probă de aici a fost verificată stricând reparația la loc.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { CERE_CONFIG } from './baza-probei.mjs';

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
  return { status: res.status, data, headers: res.headers };
}
const get = (a, p) => call(a, 'GET', p);
const post = (a, p, b) => call(a, 'POST', p, b ?? {});
const patch = (a, p, b) => call(a, 'PATCH', p, b ?? {});

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

/**
 * Un număr citit direct din baza de date, cu acreditările din `api/config.php`.
 *
 * Se folosește acolo unde API-ul nu poate răspunde: anunțurile nu se văd prin
 * el decât dacă au eșuat, iar sesiunea clientului de probă poate fi indisponibilă
 * — celelalte teste rulează în paralel pe aceeași bază și îi schimbă contul.
 */
function numarDinBaza(sql, param) {
  const out = execFileSync(phpBinary(), [
    '-r',
    '$c = require ' + CERE_CONFIG + '; $d = $c["db"];'
    + ' $p = new PDO("mysql:host={$d["host"]};port={$d["port"]};dbname={$d["name"]};charset=utf8mb4", $d["user"], $d["pass"]);'
    + ' $s = $p->prepare($argv[1]); $s->execute([(int) $argv[2]]);'
    + ' $v = $s->fetchColumn(); echo $v === false || $v === null ? "" : (int) $v;',
    sql, String(param),
  ], { cwd: ROOT, encoding: 'utf8' });
  const t = out.trim();
  return t === '' ? null : parseInt(t, 10);
}

/**
 * Câte anunțuri s-au înregistrat pentru o comandă.
 *
 * Pe stația de dezvoltare `mail()` întoarce „trimis", deci rândul nu apare în
 * lista de eșecuri din Setări — dar el există, și tocmai existența lui arată că
 * anunțul a plecat după răspuns, nu că a fost aruncat ca să scape ruta de
 * așteptare.
 */
const notificariPentru = (orderId) => numarDinBaza('SELECT COUNT(*) FROM notifications WHERE order_id = ?', orderId);

const asteapta = (ms) => new Promise((r) => setTimeout(r, ms));

/* ---------------------------------------------------------- pregătire -- */

const admin = agent();
const client = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica ca admin: ' + r.status); process.exit(1); }
}
// Contul de client de probă și numele lui de firmă: exact perechea pe care o
// leagă `client_by_name()` la comanda luată la telefon.
let CONT = 0, NUME = '';
{
  const r = await post(client, '/auth/login', { username: 'testclient', password: 'Parola-Test-9012' });
  client.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica drept client: ' + r.status); process.exit(1); }
  CONT = Number(r.data?.user?.id);
  NUME = String(r.data?.user?.customerName || r.data?.user?.name || '').trim();
}
if (!CONT || NUME === '') { console.log('contul de client de probă nu are nume de firmă'); process.exit(1); }

const ZI = '2026-09-21';

/** Comandă de probă, scrisă de admin, pe numele dat. */
async function comanda(nume) {
  const r = await post(admin, '/orders', {
    customerName: nume, phone: '0700 000 111', address: 'Str. Probei 1',
    day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: 1, qty: 1, variant: 'copt' }],
  });
  if (r.status !== 201) throw new Error('nu pot crea comanda de probă: ' + r.status + ' ' + JSON.stringify(r.data));
  return r.data.order;
}

/**
 * Vede clientul comanda în „Comanda mea"?
 *
 * Întrebăm chiar panoul clientului. Când contul de probă e ocupat de alt test
 * care rulează în paralel (o parolă provizorie pusă de `test-parola` îi
 * blochează toate rutele), cădem pe ce filtrează chiar ruta: `o.customer_id`.
 * Nu e o portiță — e aceeași condiție, citită din bază în loc de prin API.
 */
let prinBaza = false;
async function clientulVede(id) {
  const r = await get(client, '/orders?day=' + ZI);
  if (r.status === 200) {
    return (r.data?.orders || []).some((o) => o.id === id);
  }
  if (!prinBaza) {
    prinBaza = true;
    console.log(`  —     sesiunea clientului de probă e ocupată (${r.data?.error?.code || r.status});`
      + ' legătura se verifică direct pe `orders.customer_id`');
  }
  return numarDinBaza('SELECT customer_id FROM orders WHERE id = ?', id) === CONT;
}

// Ce lăsăm în urmă poartă numele curățat de `curata-probe`.
const deCuratat = [];

/* ============ 1. legătura cu contul nu se mai reface din nume =========== */

console.log('\n=== PATCH /orders: numele nu mai rescrie contul ===');
{
  const o = await comanda(NUME);
  deCuratat.push(o.id);
  check('comanda pe numele contului se leagă de cont', o.customerId === CONT,
    `customerId = ${o.customerId}, așteptat ${CONT}`);
  check('clientul o vede în „Comanda mea"', await clientulVede(o.id));

  // Administratorul redenumește firma în pagina Conturi; comanda veche rămâne
  // cu numele vechi, iar prima salvare din panou retrimite numele nou. Sub
  // vechea rută, `client_by_name()` nu-l mai găsea și punea NULL.
  const r = await patch(admin, `/orders/${o.id}`, { customerName: NUME + ' SRL' });
  check('salvarea cu numele redenumit reușește', r.status === 200, `${r.status} ${JSON.stringify(r.data)}`);
  check('legătura cu contul rămâne pe loc', r.data?.order?.customerId === CONT,
    `customerId = ${r.data?.order?.customerId}, așteptat ${CONT}`);
  check('comanda rămâne în „Comanda mea" a clientului', await clientulVede(o.id));

  // Și un nume care ar duce către alt cont nu are voie să mute comanda.
  const r2 = await patch(admin, `/orders/${o.id}`, { customerName: 'Client Anonim' });
  check('un nume străin nu mută comanda pe alt cont', r2.data?.order?.customerId === CONT,
    `customerId = ${r2.data?.order?.customerId}`);

  // Doar o schimbare cerută pe față mișcă legătura — atât are nevoie
  // personalul ca să repare o comandă legată greșit.
  const r3 = await patch(admin, `/orders/${o.id}`, { customerId: null });
  check('`customerId: null` dezleagă comanda de cont', r3.data?.order?.customerId === null,
    JSON.stringify(r3.data?.order?.customerId));
  const r4 = await patch(admin, `/orders/${o.id}`, { customerId: CONT });
  check('`customerId` o leagă înapoi', r4.data?.order?.customerId === CONT,
    JSON.stringify(r4.data?.order?.customerId));
  const r5 = await patch(admin, `/orders/${o.id}`, { customerId: 99999999 });
  check('un cont inexistent e refuzat, nu scris în comandă', r5.status === 422,
    `${r5.status} ${JSON.stringify(r5.data)}`);

  await patch(admin, `/orders/${o.id}`, { customerName: NUME });
}

console.log('\n=== comanda luată la telefon se leagă în continuare ===');
{
  // Clientul e doar un nume, fără cont: comanda nu are ce legătură pierde, iar
  // căutarea după nume are voie să adauge una.
  const o = await comanda('Client Anonim');
  deCuratat.push(o.id);
  check('comanda fără cont potrivit rămâne nelegată', o.customerId === null,
    `customerId = ${o.customerId}`);
  check('clientul nu o vede', !(await clientulVede(o.id)));

  const r = await patch(admin, `/orders/${o.id}`, { customerName: NUME });
  check('numele corectat leagă comanda de contul găsit', r.data?.order?.customerId === CONT,
    `customerId = ${r.data?.order?.customerId}, așteptat ${CONT}`);
  check('clientul o vede după corectură', await clientulVede(o.id));
}

/* ================= 2. referința se potrivește cu coloana ================ */

console.log('\n=== PATCH /orders: referința prea lungă e refuzată pe față ===');
{
  const o = await comanda(NUME);
  deCuratat.push(o.id);
  const refVechi = o.ref;

  // 28 de caractere: trecea de verificarea de 32 și pica în MySQL, pe coloana
  // de 24, cu „Eroare internă" și cu formularul întreg pierdut.
  const lung = 'Comandă Restaurant Bistro 12';
  const r = await patch(admin, `/orders/${o.id}`, {
    ref: lung, address: 'Bd. Corectat 42', notes: 'notă corectată',
  });
  check('referința de 28 de caractere → 422, nu 500',
    r.status === 422, `${r.status} ${JSON.stringify(r.data)}`);
  check('răspunsul spune ce câmp e de vină',
    /[Rr]eferința/.test(r.data?.error?.message || ''), JSON.stringify(r.data));
  check('răspunsul spune cât încape',
    /24/.test(r.data?.error?.message || ''), JSON.stringify(r.data));

  const dupa = (await get(admin, `/orders/${o.id}`)).data?.order;
  check('comanda rămâne neatinsă după refuz', dupa?.ref === refVechi,
    `ref = ${dupa?.ref}, era ${refVechi}`);

  // Exact cât ține coloana: trebuie să treacă și să se salveze.
  const potrivit = 'R' + '2'.repeat(23);
  const ok = await patch(admin, `/orders/${o.id}`, { ref: potrivit });
  check('referința de exact 24 de caractere se salvează',
    ok.status === 200 && ok.data?.order?.ref === potrivit,
    `${ok.status} ${JSON.stringify(ok.data?.error || ok.data?.order?.ref)}`);
  const citit = (await get(admin, `/orders/${o.id}`)).data?.order;
  check('și se regăsește întreagă în bază', citit?.ref === potrivit, `ref = ${citit?.ref}`);
}

/* ============ 3. răspunsul la creare nu așteaptă anunțurile ============= */

console.log('\n=== POST /orders: răspunsul pleacă înaintea anunțurilor ===');
{
  const r = await post(admin, '/orders', {
    customerName: NUME, phone: '0700 000 111', address: 'Str. Probei 1',
    day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: 1, qty: 1, variant: 'copt' }],
  });
  check('comanda se creează → 201', r.status === 201, `${r.status} ${JSON.stringify(r.data)}`);
  const o = r.data?.order;
  if (o) deCuratat.push(o.id);

  // `json_out_early()` încheie răspunsul cu lungimea lui declarată și lasă
  // scriptul să meargă mai departe. `json_out()` nu o pune: corpul se termină
  // abia când moare procesul, adică după toate anunțurile. Antetul e urma pe
  // care o lasă reparația în răspunsul primit de panou.
  check('răspunsul e încheiat cu Content-Length (a plecat înaintea anunțurilor)',
    r.headers.get('content-length') !== null,
    'lipsește Content-Length: răspunsul se închide abia la sfârșitul scriptului');

  check('răspunsul aduce comanda salvată', !!o?.id && !!o?.ref, JSON.stringify(r.data).slice(0, 150));
  const citit = (await get(admin, `/orders/${o.id}`)).data?.order;
  check('comanda chiar există în bază', citit?.id === o.id);

  // Anunțul nu e sacrificat ca să scape ruta de așteptare: se trimite după
  // răspuns și rămâne scris în `notifications`, cu status și motiv.
  let n = 0;
  for (let i = 0; i < 20 && n === 0; i++) {
    n = notificariPentru(o.id);
    if (n === 0) await asteapta(250);
  }
  check('anunțul se înregistrează în `notifications` după răspuns', n > 0,
    'niciun rând pentru comanda ' + o.id + ' — anunțul nu mai pleacă deloc');
}

/* -------------------------------------------------------------- final -- */

// Toate comenzile de probă rămân pe numele contului de probă, ca
// `curata-probe` să le poată strânge.
for (const id of deCuratat) {
  await patch(admin, `/orders/${id}`, { customerName: NUME });
}

console.log(`\n${pass} trecute, ${fail} picate`);
if (fail) {
  console.log('\nPicate:');
  for (const f of failures) console.log('  - ' + f);
  process.exit(1);
}
process.exit(0);
