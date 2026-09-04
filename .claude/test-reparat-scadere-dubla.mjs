// Proba reparației din `api/routes/stock.php`: un produs redenumit nu mai
// scade de două ori din depozit.
//
//   node .claude/instanta.mjs porneste proba-scadere-dubla
//   set GP_BASE=http://127.0.0.1:8200/api && node .claude/test-reparat-scadere-dubla.mjs
//   node .claude/instanta.mjs opreste proba-scadere-dubla
//
// Defectul: legătura din `route_stock_sync` avea două ramuri — pe
// `product_id` și, pentru articolele nelegate de un produs, pe nume — și le
// lăsa pe amândouă să prindă. După o redenumire din Catalog, articolul vechi
// rămânea prins pe `product_id`, iar cel adăugat de depozitar din „Articol
// nou” (pagina Depozit trimite doar nume, categorie și unitate) se prindea pe
// numele nou. O comandă de 8 bucăți scădea 8 din fiecare: raportul spunea 16,
// amândouă stocurile coborau, iar comanda ieșea marcată ca scăzută, deci nici
// reluată nu mai putea fi. Depozitul arăta cu o zi de producție mai puțin
// decât avea în rafturi, iar lipsa se vedea abia la inventar.
//
// Verificările care pică dacă reparația e dată înapoi:
//   „comanda de 8 bucăți scade 8, nu 16”, „o singură mișcare pentru comandă,
//   nu două” și „articolul adăugat din «Articol nou» nu se atinge”, toate din
//   secțiunea 1. Cu legătura veche ies 16 bucăți, două mișcări și amândouă
//   stocurile pe 92. Secțiunea 3 pică la fel, pe „5, nu 10”.
//
// Rulează pe o instanță izolată: creează produse și comenzi, iar prin API
// comenzile nu se șterg. Dacă o rulezi totuși pe `gp_dev`, adaugă numele din
// `CLIENT` în lista `NUME_DE_TEST` din `.claude/curata-probe.mjs`; produsele
// rămân cu slug `strudel-de-proba-…` și inactive, deci curățenia le ia.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BAZA_DE_PROBA, configulLuiBase } from './baza-probei.mjs';

const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const unealta = (env, dir, cauta) => {
  if (process.env[env]) return process.env[env];
  try {
    const v = fs.readdirSync(dir).find((x) => x.startsWith(cauta));
    if (v) return env === 'GP_PHP' ? path.join(dir, v, 'php.exe') : path.join(dir, v, 'bin', 'mysql.exe');
  } catch { /* alt sistem */ }
  return env === 'GP_PHP' ? 'php' : 'mysql';
};
const PHP   = unealta('GP_PHP', 'C:/laragon/bin/php', 'php-');
const MYSQL = unealta('GP_MYSQL', 'C:/laragon/bin/mysql', 'mysql-');

const CFG = configulLuiBase(BASE);
let db = null;
try {
  db = JSON.parse(execFileSync(PHP, [
    '-r', '$c = require $argv[1]; echo json_encode($c["db"]);',
    '--', CFG || path.join(ROOT, 'api', 'config.php'),
  ], { cwd: ROOT, encoding: 'utf8' }));
} catch { /* fără PHP la îndemână sar scenele care cer baza */ }

// Plasă de siguranță: pe o bază adevărată proba n-are ce căuta.
const PE_BAZA = !!db && BAZA_DE_PROBA.test(db.name);

/** Rulează SQL pe baza din spatele lui `GP_BASE` și întoarce rândurile brute. */
const sql = (text) => execFileSync(MYSQL, [
  '--default-character-set=utf8mb4',
  '-u', db.user, ...(db.pass ? ['-p' + db.pass] : []),
  '-h', db.host, '-P', String(db.port), db.name, '-N', '-B', '-e', text,
], { encoding: 'utf8' }).trim();

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
const patch = (a, p, b) => call(a, 'PATCH', p, b ?? {});

const opreste = (motiv) => { console.log('  —     ' + motiv); process.exit(1); };

/* ---------------------------------------------------------- pregătire -- */

const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) opreste('nu m-am putut autentifica ca admin: ' + r.status);
}

// Etapa care încheie comanda și grupa din catalog se citesc de pe server:
// amândouă se editează din Setări, iar o probă care le scrie în cod ar pica la
// prima redenumire, fără să fie nimic stricat în aplicație.
const setari = (await get(admin, '/settings')).data?.settings || {};
const PREDATA = setari.order_status_done || '';
if (!PREDATA) opreste('nu e configurată etapa care încheie comanda');

const GRUPA = ((await get(admin, '/categories')).data?.categories || []).find((c) => c.active)?.slug;
if (!GRUPA) opreste('nu există nicio grupă activă în catalog');

const U  = Math.random().toString(36).slice(2, 8);
const ZI = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
// Un singur nume de client pentru toată proba, ca `curata-probe` să poată
// strânge comenzile cu o singură regulă.
const CLIENT = 'Probă Scădere Dublă';

let n = 0;
const nume = (ce) => 'Ștrudel de probă ' + U + '-' + (++n) + ' ' + ce;

/** Un produs nou în catalog. Slugul e cel pe care îl mătură `curata-probe`. */
async function produs(eticheta) {
  const denumire = nume(eticheta);
  const r = await post(admin, '/products', {
    name: denumire, nameEn: 'Test ' + U + ' ' + n, description: 'probă', descriptionEn: 'test',
    slug: 'strudel-de-proba-' + U + '-' + n, grams: 100,
    priceFrozen: 3, priceBaked: 5, category: GRUPA,
  });
  if (r.status !== 201) opreste('nu pot crea produsul: ' + r.status + ' ' + JSON.stringify(r.data));
  return { id: r.data.id, name: denumire };
}

/** Un articol de depozit, cu sau fără legătură la un produs din catalog. */
async function articol(denumire, productId, intrare) {
  const cerere = { name: denumire, category: 'Produse finite', unit: 'buc', minQty: 5 };
  if (productId) cerere.productId = productId;
  const r = await post(admin, '/stock/items', cerere);
  if (r.status !== 201) opreste('nu pot crea articolul „' + denumire + '”: ' + r.status + ' ' + JSON.stringify(r.data));
  const id = r.data.id;
  if (intrare) {
    const m = await post(admin, '/stock/moves', { itemId: id, qty: intrare, direction: 1, reason: 'Intrare probă' });
    if (m.status !== 201) opreste('nu pot înregistra intrarea: ' + m.status + ' ' + JSON.stringify(m.data));
  }
  return id;
}

async function comanda(pozitii) {
  const r = await post(admin, '/orders', {
    customerName: CLIENT, phone: '0700 000 111', address: 'Str. Probei 1',
    day: ZI, fulfillment: 'Livrare', channel: 'panou', items: pozitii,
  });
  if (r.status !== 201) opreste('nu pot crea comanda: ' + r.status + ' ' + JSON.stringify(r.data));
  return r.data.order;
}

/**
 * Id-ul ultimei mișcări dinaintea ultimei apăsări pe „scade din stoc”.
 *
 * Registrul e comun și nu se golește între rulări: ziua probei e fixă, deci
 * comenzile primesc de fiecare dată aceleași referințe, iar însemnarea unei
 * scăderi e chiar „referință + client”. Fără reperul ăsta, a doua rulare a
 * suitei pe aceeași bază număra și scăderile rulării dinainte și raporta „2
 * mișcări” acolo unde codul făcuse una — o picare care nu spunea nimic despre
 * cod.
 */
let reperMiscari = 0;

const ultimaMiscare = async () =>
  ((await get(admin, '/stock/moves')).data?.moves || [])
    .reduce((max, m) => (m.id > max ? m.id : max), 0);

/** Predă comanda și apasă „scade din stoc”, exact ca depozitarul. */
async function predaSiScade(orderId) {
  const p = await patch(admin, '/orders/' + orderId, { status: PREDATA });
  if (p.status !== 200) opreste('nu pot preda comanda: ' + p.status + ' ' + JSON.stringify(p.data));
  reperMiscari = await ultimaMiscare();
  return post(admin, '/stock/sync', {});
}

const stocuri = async () => Object.fromEntries(
  ((await get(admin, '/stock/items')).data?.items || []).map((it) => [it.id, it.qty])
);

/**
 * Mișcările scrise de scădere pentru o comandă.
 *
 * Se caută după însemnarea întreagă — referință ȘI client — nu doar după
 * referință: `orders.ref` se repetă de la o zi la alta (numerotarea e per zi),
 * deci un filtru pe ea singură strângea și mișcările altei probe care se
 * nimerise cu același număr, iar proba pica din motive care n-aveau nicio
 * legătură cu ce măsoară.
 */
const scaderiPentru = async (ref) =>
  ((await get(admin, '/stock/moves')).data?.moves || [])
    .filter((m) => m.id > reperMiscari
      && m.reason === 'Comandă client'
      && String(m.note || '') === ref + ' ' + CLIENT);

// Instanța poate avea deja comenzi predate și nescăzute: le scoatem din drum,
// ca numerele probei să fie ale probei.
await post(admin, '/stock/sync', {});

const produseDeStins = [];

/* ===== 1. produs redenumit + articol adăugat din „Articol nou” ========== */

console.log('\n=== redenumire în Catalog: comanda scade o singură dată ===');
{
  const p = await produs('redenumit');
  produseDeStins.push(p.id);
  // Articolul legat de produs, cum îl pune instalarea: `product_id` scris și
  // numele pe care îl avea produsul atunci.
  const legat = await articol(p.name, p.id, 100);

  const numeNou = nume('nume nou');
  const r = await patch(admin, '/products/' + p.id, { name: numeNou });
  if (r.status !== 200) opreste('nu pot redenumi produsul: ' + r.status + ' ' + JSON.stringify(r.data));

  const o = await comanda([{ productId: p.id, qty: 8, variant: 'copt' }]);
  check('poziția comenzii poartă numele nou al produsului',
    o.items?.[0]?.name === numeNou, JSON.stringify(o.items));

  // Depozitarul nu găsește numele nou în listă și îl adaugă din „Articol nou”:
  // pagina Depozit trimite doar nume, categorie și unitate, deci fără produs.
  const nelegat = await articol(numeNou, null, 100);

  const inainte = await stocuri();
  const s = await predaSiScade(o.id);

  check('scăderea răspunde cu 200', s.status === 200, s.status + ' ' + JSON.stringify(s.data));
  check('comanda de 8 bucăți scade 8, nu 16',
    s.data?.pieces === 8, 'pieces = ' + JSON.stringify(s.data?.pieces));
  check('comanda nu rămâne pe loc din lipsă de articol',
    !(s.data?.skipped || []).includes(o.id), JSON.stringify(s.data));
  check('comanda iese marcată ca scăzută',
    (await get(admin, '/orders/' + o.id)).data?.order?.stockSynced === true, JSON.stringify(s.data));

  const m = await scaderiPentru(o.ref);
  check('o singură mișcare pentru comandă, nu două',
    m.length === 1, m.length + ' mișcări: ' + JSON.stringify(m.map((x) => x.name)));
  check('mișcarea e pusă pe articolul legat prin produs',
    m[0]?.itemId === legat, 'itemId = ' + m[0]?.itemId + ', așteptat ' + legat);

  const dupa = await stocuri();
  check('articolul legat prin produs scade cu 8',
    dupa[legat] === inainte[legat] - 8, inainte[legat] + ' → ' + dupa[legat]);
  check('articolul adăugat din „Articol nou” nu se atinge',
    dupa[nelegat] === inainte[nelegat], inainte[nelegat] + ' → ' + dupa[nelegat]);
}

/* ===== 2. ramura pe nume rămâne, pentru articolele fără produs ========== */

console.log('\n=== articol fără produs: se scade în continuare, pe nume ===');
{
  const p = await produs('fără legătură');
  produseDeStins.push(p.id);
  // Cazul obișnuit al patiseriei: articolul a fost adăugat din „Articol nou”,
  // deci n-are `product_id`. Dacă reparația ar tăia ramura pe nume, marfa ar
  // ieși din laborator și ar rămâne în evidență la nesfârșit.
  const nelegat = await articol(p.name, null, 60);

  const o = await comanda([{ productId: p.id, qty: 7, variant: 'copt' }]);
  const inainte = await stocuri();
  const s = await predaSiScade(o.id);

  check('comanda se scade, deși articolul n-are produs',
    s.data?.pieces === 7, JSON.stringify(s.data));

  const dupa = await stocuri();
  check('articolul legat pe nume scade cu 7',
    dupa[nelegat] === inainte[nelegat] - 7, inainte[nelegat] + ' → ' + dupa[nelegat]);

  const m = await scaderiPentru(o.ref);
  check('și tot o singură mișcare', m.length === 1, m.length + ' mișcări');
}

/* ===== 3. două articole legate de același produs ======================== */

console.log('\n=== două articole pe același produs: tot o singură scădere ===');
if (!PE_BAZA) {
  console.log('  —     sărită: nu pot ajunge la baza din spatele lui GP_BASE');
} else {
  const p = await produs('două articole');
  produseDeStins.push(p.id);
  const a = await articol(p.name, p.id, 100);

  // Poarta nouă: al doilea articol pe același produs nu se mai poate face din
  // aplicație. Acolo se naște dublura — depozitarul nu găsește numele vechi în
  // listă după o redenumire din Catalog și adaugă articolul încă o dată.
  const refuz = await post(admin, '/stock/items', {
    name: nume('dublura oprită'), category: 'Produse finite', unit: 'buc', productId: p.id,
  });
  check('al doilea articol pe același produs e refuzat → 409',
    refuz.status === 409 && refuz.data?.error?.code === 'product_taken',
    refuz.status + ' ' + JSON.stringify(refuz.data?.error));
  check('refuzul spune pe care articol să facă intrările',
    String(refuz.data?.error?.message || '').includes(p.name),
    JSON.stringify(refuz.data?.error?.message));

  // Perechea rămâne totuși de verificat: instalările de dinainte de reparație
  // o au deja făcută. O construim ca pe o instalare veche, direct în bază.
  const numeB = nume('dublura');
  sql("INSERT INTO stock_items (name, category, unit, min_qty, product_id) VALUES ("
    + "'" + numeB.replace(/'/g, "''") + "', 'Produse finite', 'buc', 5, " + p.id + ")");
  const b = Number(sql("SELECT id FROM stock_items WHERE name = '" + numeB.replace(/'/g, "''") + "'"));
  const m = await post(admin, '/stock/moves', { itemId: b, qty: 100, direction: 1, reason: 'Intrare probă' });
  if (m.status !== 201) opreste('nu pot pune intrarea pe dublura din bază: ' + m.status);

  const o = await comanda([{ productId: p.id, qty: 5, variant: 'copt' }]);
  const inainte = await stocuri();
  const s = await predaSiScade(o.id);

  check('comanda de 5 bucăți scade 5, nu 10',
    s.data?.pieces === 5, 'pieces = ' + JSON.stringify(s.data?.pieces));

  const mv = await scaderiPentru(o.ref);
  check('o singură mișcare, nu câte una de fiecare articol',
    mv.length === 1, mv.length + ' mișcări: ' + JSON.stringify(mv.map((x) => x.name)));

  const dupa = await stocuri();
  const plecat = (inainte[a] - dupa[a]) + (inainte[b] - dupa[b]);
  check('din cele două articole împreună au plecat exact 5 bucăți',
    plecat === 5, 'au plecat ' + plecat);
}

/* ===== 4. poziție fără niciun articol: comanda rămâne întreagă ========== */

console.log('\n=== poziție fără articol în depozit: comanda nu se scade ===');
{
  const p = await produs('fără articol');
  produseDeStins.push(p.id);
  const o = await comanda([{ productId: p.id, qty: 4, variant: 'copt' }]);
  const s = await predaSiScade(o.id);

  check('produsul fără articol e raportat ca lipsă',
    (s.data?.missing || []).includes(p.name), JSON.stringify(s.data?.missing));
  check('comanda e numărată printre cele rămase pe loc',
    (s.data?.skipped || []).includes(o.id), JSON.stringify(s.data?.skipped));
  check('nu s-a scris nicio mișcare pentru ea',
    (await scaderiPentru(o.ref)).length === 0);

  const citit = (await get(admin, '/orders/' + o.id)).data?.order;
  check('comanda rămâne nescăzută, ca să poată fi reluată',
    citit?.stockSynced === false, 'stockSynced = ' + citit?.stockSynced);
}

/* ===== 5. articolul scos din depozit nu fură scăderea de la cel viu ===== */

console.log('\n=== articol legat, dar scos din depozit: scade cel pe care îl vezi ===');
if (!PE_BAZA) {
  console.log('  —     sărită: nu pot ajunge la baza din spatele lui GP_BASE');
} else {
  // Povestea: produsul se redenumește în Catalog, depozitarul nu mai
  // recunoaște articolul vechi și face unul cu numele nou, apoi îl scoate din
  // listă pe cel vechi — instinctul firesc, fiindcă numele lui nu mai seamănă
  // cu nimic. Din clipa aceea singurul articol pe care îl vede e cel nou.
  const p = await produs('scos din depozit');
  produseDeStins.push(p.id);
  const vechiArt = await articol(p.name, p.id, 100);

  const numeNou = nume('după redenumire');
  const r = await patch(admin, '/products/' + p.id, { name: numeNou });
  if (r.status !== 200) opreste('nu pot redenumi produsul: ' + r.status + ' ' + JSON.stringify(r.data));

  const viu = await articol(numeNou, null, 100);
  const st = await patch(admin, '/stock/items/' + vechiArt, { active: false });
  check('articolul vechi se poate scoate din depozit', st.status === 200, String(st.status));
  check('și chiar nu mai apare în listă',
    !((await get(admin, '/stock/items')).data?.items || []).some((i) => i.id === vechiArt));

  const o = await comanda([{ productId: p.id, qty: 8, variant: 'copt' }]);
  const s2 = await predaSiScade(o.id);
  check('comanda de 8 bucăți scade 8, nu 16', s2.data?.pieces === 8,
    'pieces = ' + JSON.stringify(s2.data?.pieces));

  const dupa = await stocuri();
  check('scăderea cade pe articolul pe care îl vede depozitarul',
    dupa[viu] === 92, 'articolul viu a rămas la ' + dupa[viu]);
  // Cel scos nu se citește prin API — nu mai apare în nicio listă — deci îl
  // numărăm din registru, exact ca pagina Depozit.
  const soldScos = Number(sql('SELECT COALESCE(SUM(qty * direction), 0) FROM stock_moves WHERE item_id = ' + vechiArt));
  check('articolul scos din depozit nu se mai atinge', soldScos === 100,
    'soldul lui e ' + soldScos);

  // Și, fiindcă articolul lui a fost scos, produsul trebuie să poată fi legat
  // din nou: altfel refuzul ar trimite spre un rând care nu se vede nicăieri,
  // iar produsul ar rămâne nelegabil pentru totdeauna.
  const iar = await post(admin, '/stock/items', {
    name: nume('articol pus la loc'), category: 'Produse finite', unit: 'buc', productId: p.id,
  });
  check('produsul se poate lega din nou, după ce articolul lui a fost scos → 201',
    iar.status === 201, iar.status + ' ' + JSON.stringify(iar.data));
}

/* -------------------------------------------------------------- final -- */

// Produsele de probă ies din catalog: așa le poate strânge `curata-probe`, iar
// pagina Catalog nu se umple cu „Ștrudel de probă …” după fiecare rulare.
for (const id of produseDeStins) {
  await patch(admin, '/products/' + id, { active: false });
}

console.log(`\n${pass} trecute, ${fail} picate`);
if (fail) {
  console.log('\nPicate:');
  for (const f of failures) console.log('  - ' + f);
  process.exit(1);
}
process.exit(0);

