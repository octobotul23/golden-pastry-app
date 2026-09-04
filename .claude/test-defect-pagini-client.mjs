// Probe pentru defectele grupei „pagini-client”:
//   Client.dc.html      cererea trimisă din magazin nu apărea în „Comanda mea”
//   Ruta Sofer.dc.html  zilele se calculau o singură dată, la deschiderea paginii
//   Produse.dc.html     catalogul cerea de la server două adrese „{{ … }}”
//
//   php -S 127.0.0.1:8100 -t . .claude/dev-server.php
//   node .claude/test-defect-pagini-client.mjs
//
// Paginile se rulează cu codul lor adevărat: blocul <script type="text/x-dc">
// se compilează într-un context izolat, cu `gp` cel din shared/gp-api.js, iar
// componenta e pusă să facă exact ce face în browser. Nu căutăm șiruri de
// caractere în sursă — o probă care trece și cu reparația anulată nu valorează
// nimic. Singura verificare pe marcaj e cea de la Produse: acolo defectul CHIAR
// este în marcaj, fiindcă browserul citește șablonul nerandat din <x-dc> și
// cere de la server adresele din atribute înainte ca runtime-ul să apuce să
// înlocuiască legăturile.

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
// Adresa API-ului. Se termină întotdeauna cu `/api`: suita o dă așa
// (`GP_BASE=http://127.0.0.1:8201/api`), dar o adresă scrisă de mână fără
// coada aceea trebuie să meargă la fel.
const BASE = (() => {
  let b = (process.env.GP_BASE || 'http://127.0.0.1:8100/api').trim();
  while (b.endsWith('/')) b = b.slice(0, -1);
  return b.endsWith('/api') ? b : b + '/api';
})();
const ADRESA = new URL(BASE);

let pass = 0, fail = 0;
const failures = [];
const check = (name, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
};

/* ------------------------------------------------------- bucătăria probei -- */

/** Șablonul brut din <x-dc> și blocul de logică al unei pagini. */
function paginaBruta(nume) {
  const src = fs.readFileSync(path.join(ROOT, nume), 'utf8');
  const desc = /<x-dc(?:\s[^>]*)?>/.exec(src);
  const inch = src.lastIndexOf('</x-dc>');
  const cod = src.match(/<script[^>]*type="text\/x-dc"[^>]*>([\s\S]*?)<\/script>/);
  return {
    src,
    sablon: desc && inch > 0 ? src.slice(desc.index + desc[0].length, inch) : '',
    cod: cod ? cod[1] : '',
  };
}

/**
 * Ceas mutabil pentru contextul paginii.
 *
 * Tot ce ține de zile — `gp.days()`, `new Date()` din pagină — trebuie să
 * poată fi mutat înainte fără să aștept până la miezul nopții.
 */
function ceasFals(startISO) {
  const stare = { acum: Date.parse(startISO) };
  class Ceas extends Date {
    constructor(...a) { if (a.length === 0) super(stare.acum); else super(...a); }
    static now() { return stare.acum; }
  }
  return { Ceas, muta: (iso) => { stare.acum = Date.parse(iso); } };
}

/** Bază minimă de clasă, cât îi trebuie unei pagini ca să meargă în afara browserului. */
class DCLogic {
  constructor(props) {
    this.props = props || {};
    this.state = {};
  }
  setState(patch, cb) {
    const p = typeof patch === 'function' ? patch(this.state) : patch;
    this.state = Object.assign({}, this.state, p);
    if (typeof cb === 'function') cb();
  }
  forceUpdate() {}
}

/**
 * Contextul în care rulează pagina: exact globalele pe care le are în browser.
 *
 * `shared/gp-api.js` se încarcă adevărat, nu imitat: `gp.days()`, `gp.toPageOrder()`
 * și `gp.toPageRequest()` sunt cele pe care le folosesc paginile în producție.
 */
function contextPagina({ Ceas, fetchul } = {}) {
  const stocare = () => {
    const m = new Map();
    return {
      getItem: (k) => (m.has(k) ? m.get(k) : null),
      setItem: (k, v) => m.set(k, String(v)),
      removeItem: (k) => m.delete(k),
    };
  };
  const el = () => ({
    setAttribute() {}, getAttribute() { return null; }, closest() { return null; },
    addEventListener() {}, removeEventListener() {},
  });
  const ceasuri = [];
  const ctx = {
    console,
    addEventListener() {}, removeEventListener() {},
    DCLogic,
    URL, JSON, Math, Object, Array, String, Number, Boolean, RegExp, Error, Promise, Map, Set,
    isNaN, isFinite, parseInt, parseFloat, encodeURIComponent, decodeURIComponent,
    Date: Ceas || Date,
    fetch: fetchul || fetch,
    localStorage: stocare(),
    sessionStorage: stocare(),
    history: { pushState() {}, back() {}, state: null },
    setTimeout, clearTimeout,
    setInterval: (fn) => { ceasuri.push(fn); return ceasuri.length; },
    clearInterval: () => {},
    __ceasuri: ceasuri,
  };
  // Pagina trebuie să bată în serverul din `GP_BASE`, nu în portul 8100 scris
  // de mână. Scris așa, blocul de mai jos pica cu „Nu am putut contacta
  // serverul" ori de câte ori suita rula pe o instanță izolată — o picare care
  // nu spunea nimic despre cod, dar arăta ca un defect adevărat.
  //
  // `GP_API_BASE` are întâietate în `detectBase()` din shared/gp-api.js, deci
  // e de ajuns; `location` rămâne totuși potrivită cu ea, ca paginile care o
  // citesc singure să nu vadă altceva.
  ctx.GP_API_BASE = BASE;
  ctx.location = {
    origin: ADRESA.origin,
    hostname: ADRESA.hostname,
    protocol: ADRESA.protocol,
    port: ADRESA.port,
    search: '',
    href: '',
  };
  ctx.document = {
    hidden: false, documentElement: el(),
    addEventListener() {}, removeEventListener() {}, querySelector() { return null; },
  };
  ctx.window = ctx;
  ctx.globalThis = ctx;
  vm.createContext(ctx);
  vm.runInContext(fs.readFileSync(path.join(ROOT, 'shared', 'gp-api.js'), 'utf8'), ctx, { filename: 'gp-api.js' });
  return ctx;
}

/** Clasa `Component` a paginii, compilată în contextul dat. */
function componenta(nume, ctx) {
  const { cod } = paginaBruta(nume);
  return vm.runInContext(cod + '\n;Component;', ctx, { filename: nume });
}

/** `fetch` cu borcan de fursecuri: sesiunea de pe server trebuie să țină. */
function fetchCuSesiune() {
  const cookies = new Map();
  return async (url, init = {}) => {
    const headers = Object.assign({}, init.headers);
    if (cookies.size) headers['Cookie'] = [...cookies].map(([k, v]) => `${k}=${v}`).join('; ');
    const res = await fetch(url, Object.assign({}, init, { headers }));
    for (const raw of res.headers.getSetCookie?.() ?? []) {
      const [pair] = raw.split(';');
      const i = pair.indexOf('=');
      const k = pair.slice(0, i).trim(), v = pair.slice(i + 1).trim();
      if (v === '') cookies.delete(k); else cookies.set(k, v);
    }
    return res;
  };
}

/**
 * Contul de probă trebuie să poată folosi aplicația.
 *
 * `POST /users` naște orice cont cu `must_change_password = 1`, iar de acolo
 * serverul refuză tot în afară de schimbarea parolei (api/lib/auth.php:100).
 * Suita recreează conturile de probă la fiecare rulare a lui test-api, deci
 * clientul poate ajunge aici cu steagul ridicat, din altă probă, nu din
 * defectul nostru. Îl coborâm pe ușa din față — aceeași pe care o folosește
 * omul: își pune parola. Fără asta, proba ar pica din motive străine.
 */
async function scoateParolaProvizorie(gp, parola) {
  const eu = await gp.me();
  if (!eu || eu.mustChangePassword !== true) return false;
  await gp.changePassword(parola, parola);
  console.log('  —     contul „' + eu.username + '” avea parolă provizorie de la altă probă; am reintrat pe el');
  return true;
}

/* ================================================================ Client -- */
//
// Comanda trimisă din magazin rămâne cerere până când o acceptă cineva din
// laborator: nu e în tabelul `orders`, deci `GET /orders` n-o vede niciodată.
// Pagina cerea doar `/orders`, iar clientul venit chiar de pe ecranul care îl
// trimitea aici citea „Nu găsim nicio comandă pe numele …”.

console.log('\n=== Client.dc.html — cererea trimisă din magazin ===');

/** Contul de probă a fost șters și refăcut de altă probă în timpul rulării. */
class SesiunePierduta extends Error {}

/**
 * Blocul propriu-zis. E o funcție ca să-l putem relua: `test-api` șterge și
 * recreează conturile de probă la fiecare rulare, iar dacă asta se întâmplă
 * chiar între autentificare și cerere, sesiunea noastră dispare din tabelul
 * `sessions` — un eșec care n-are nicio legătură cu pagina.
 */
async function bloculClient(marcheaza) {
  const ctx = contextPagina({ fetchul: fetchCuSesiune() });
  const gp = ctx.gp;

  await gp.login('testclient', 'Parola-Test-9012');
  await scoateParolaProvizorie(gp, 'Parola-Test-9012');
  const produse = (await gp.get('/products')).products || [];
  const prod = produse.find((p) => p.active !== false) || produse[0];

  const cerere = (await gp.post('/requests', {
    items: [{ productId: prod.id, qty: 3 }],
    notes: 'proba defect pagini-client',
  })).request;
  const id = cerere.id;

  // De aici încolo, pagina. Nu chemăm componentDidMount: acolo pornește
  // reîmprospătarea din cinci în cinci secunde, care n-are ce căuta în probă.
  const Component = componenta('Client.dc.html', ctx);
  const pagina = new Component({});

  const cerute = [];
  const getReal = gp.get;
  gp.get = (p) => { cerute.push(p); return getReal(p); };

  await pagina.pull();
  const vals = pagina.renderVals();

  // Dacă sesiunea a căzut, pagina randează ecranul de intrare și tot ce urmează
  // ar pica din alt motiv decât defectul. Reluăm blocul, nu-l declarăm picat.
  if (!vals.unlocked || vals.locked) throw new SesiunePierduta('pagina a rămas pe ecranul de intrare');

  marcheaza('pagina cere de la server și cererile neconfirmate, nu doar comenzile',
    cerute.some((p) => p.startsWith('/requests')),
    'a cerut doar ' + JSON.stringify(cerute));

  marcheaza('cererea abia trimisă ajunge pe ecranul „Comanda mea”',
    vals.hasPending === true && (vals.pending || []).some((c) => c.id === id),
    'hasPending=' + vals.hasPending + ', pending=' + JSON.stringify((vals.pending || []).map((c) => c.id)));

  const aMea = (vals.pending || []).find((c) => c.id === id) || {};
  marcheaza('cererea își arată produsele, ca omul s-o recunoască',
    (aMea.items || []).some((it) => it.n === prod.name && String(it.q) === '3'),
    JSON.stringify(aMea.items));

  marcheaza('cererea își spune că e în așteptarea confirmării',
    typeof aMea.hint === 'string' && /confirm/i.test(aMea.hint),
    JSON.stringify(aMea.hint));

  // Ecranul care neagă comanda („Nicio comandă activă” + „Nu găsim nicio
  // comandă pe numele …”) nu are voie să apară peste o cerere trimisă.
  //
  // Îl punem în situația din defect fără să depindem de ce mai are contul prin
  // bază: clientul e la prima lui comandă, deci n-are nicio comandă
  // confirmată — doar cererea de adineauri.
  pagina.state = Object.assign({}, pagina.state, { orders: [] });
  const golVals = pagina.renderVals();
  marcheaza('clientul la prima comandă nu mai primește „Nicio comandă activă”',
    golVals.noOrder === false && golVals.hasPending === true,
    'noOrder=' + golVals.noOrder + ', hasPending=' + golVals.hasPending);

  // Personalul nu-și încarcă aici toate cererile laboratorului: are panoul lui.
  const ctxA = contextPagina({ fetchul: fetchCuSesiune() });
  await ctxA.gp.login('testadmin', 'Parola-Test-1234');
  const ComponentA = componenta('Client.dc.html', ctxA);
  const previzualizare = new ComponentA({});
  const ceruteA = [];
  const getA = ctxA.gp.get;
  ctxA.gp.get = (p) => { ceruteA.push(p); return getA(p); };
  await previzualizare.pull();
  marcheaza('previzualizarea de admin nu trage toate cererile laboratorului',
    !ceruteA.some((p) => p.startsWith('/requests')),
    JSON.stringify(ceruteA));

  /* --- refuzul, cu motivul lui, ajunge înapoi la client --- */
  //
  // Serverul salvează motivul de când există butonul de refuz, dar pagina cerea
  // doar `?status=nou`: cererea dispărea de pe ecran fără nicio explicație, iar
  // clientul suna să întrebe ce s-a întâmplat.
  const MOTIV = 'Nu avem capacitate în ziua cerută — probă pagini-client.';
  await ctxA.gp.post('/requests/' + id + '/reject', { reason: MOTIV });
  respinsaDeja = true;

  await pagina.pull();
  const dupaRefuz = pagina.renderVals();

  marcheaza('cererea refuzată ajunge înapoi pe ecranul clientului',
    dupaRefuz.hasRejected === true && (dupaRefuz.rejected || []).some((c) => c.id === id),
    'hasRejected=' + dupaRefuz.hasRejected
      + ', rejected=' + JSON.stringify((dupaRefuz.rejected || []).map((c) => c.id)));

  const refuzata = (dupaRefuz.rejected || []).find((c) => c.id === id) || {};
  marcheaza('clientul citește chiar motivul scris de laborator',
    refuzata.reason === MOTIV, JSON.stringify(refuzata.reason));

  marcheaza('cererea refuzată nu mai stă printre cele în așteptare',
    !(dupaRefuz.pending || []).some((c) => c.id === id),
    JSON.stringify((dupaRefuz.pending || []).map((c) => c.id)));

  return id;
}

let idCerere = null;
// Blocul își respinge singur cererea, ca să verifice motivul întors clientului.
// Steagul scutește curățenia de la coadă de o a doua respingere, care ar cădea
// pe „cererea nu mai e nouă” și ar scrie un rând de eroare degeaba.
let respinsaDeja = false;
for (let incercare = 1; incercare <= 2; incercare++) {
  // Prima încercare doar strânge rezultatele; le trecem în catastif numai când
  // știm că blocul a mers până la capăt cu sesiunea în picioare.
  const strinse = [];
  try {
    idCerere = await bloculClient((n, c, e) => strinse.push([n, c, e]));
    for (const [n, c, e] of strinse) check(n, c, e);
    break;
  } catch (e) {
    const reluabil = e instanceof SesiunePierduta || e.status === 401 || e.code === 'unauthenticated';
    if (reluabil && incercare === 1) {
      console.log('  —     sesiunea de probă a căzut (alt agent recreează conturile); reiau blocul');
      continue;
    }
    for (const [n, c, er] of strinse) check(n, c, er);
    check('blocul Client a rulat până la capăt', false, e.message);
    break;
  }
}

// Curățenie: cererea de probă nu are ce căuta în panoul de administrare. Dacă
// blocul a apucat s-o respingă singur, e deja scoasă din „Cereri de pe site”.
if (idCerere && !respinsaDeja) {
  try {
    const admin = contextPagina({ fetchul: fetchCuSesiune() });
    await admin.gp.login('testadmin', 'Parola-Test-1234');
    await admin.gp.post('/requests/' + idCerere + '/reject', { reason: 'cerere de probă' });
  } catch (e) {
    console.log('  —     cererea de probă ' + idCerere + ' a rămas (' + e.message + ')');
  }
}

/* ============================================================ Ruta Sofer -- */
//
// Tableta din mașină stă aprinsă peste noapte și sesiunea ține zile. Fereastra
// de șapte zile se calcula o singură dată, la deschiderea paginii: dimineața
// eticheta spunea „azi”, dar cererea pleca cu ziua de ieri, iar șoferul pornea
// pe traseul de ieri, cu opriri deja livrate.

console.log('\n=== Ruta Sofer.dc.html — tableta lăsată peste noapte ===');

{
  const IERI = '2026-08-29', AZI = '2026-08-30', POIMAINE = '2026-08-31';
  const ceas = ceasFals(IERI + 'T22:40:00');
  const ctx = contextPagina({ Ceas: ceas.Ceas });
  const gp = ctx.gp;

  // Serverul nu ne interesează aici: ne interesează CU CE ZI pleacă cererea.
  const cerute = [];
  gp.lists = async () => ({});
  gp.get = async (p) => {
    cerute.push(p);
    if (p.startsWith('/routes/counts')) return { counts: {} };
    return { stops: [] };
  };
  const zi = () => {
    const r = [...cerute].reverse().find((p) => p.startsWith('/routes?day='));
    return r ? r.slice('/routes?day='.length) : '';
  };

  const Component = componenta('Ruta Sofer.dc.html', ctx);
  const pagina = new Component({});
  pagina.componentDidMount();

  check('seara, tableta cere ruta zilei curente', zi() === IERI, zi());

  const tura = ctx.__ceasuri[ctx.__ceasuri.length - 1];
  check('pagina chiar are o tură periodică', typeof tura === 'function');

  // Miezul nopții trece; nimeni nu atinge tableta.
  ceas.muta(AZI + 'T06:00:00');
  tura();

  check('dimineața, „Ruta de azi” cere ziua nouă, nu pe cea de ieri',
    zi() === AZI, 'a cerut ' + zi());
  check('antetul scrie ziua nouă',
    /^Duminică 30 august/.test(pagina.renderVals().sub), pagina.renderVals().sub);
  check('meniul de zile începe tot cu ziua nouă',
    (pagina.renderVals().menuDays[0] || {}).label === 'Duminică 30 august',
    JSON.stringify((pagina.renderVals().menuDays[0] || {}).label));

  // Șoferul care se uita la ruta de mâine rămâne pe aceeași zi din calendar:
  // fereastra se mută sub el, dar lista pe care o citea nu se schimbă.
  const pagina2 = new Component({});
  pagina2.componentDidMount();
  cerute.length = 0;
  ceas.muta(IERI + 'T23:50:00');
  pagina2.goDay('31', false);
  check('seara, șoferul poate deschide ruta de poimâine', zi() === POIMAINE, zi());
  ceas.muta(AZI + 'T06:10:00');
  ctx.__ceasuri[ctx.__ceasuri.length - 1]();
  check('ziua din calendar aleasă de șofer rămâne aleasă după miezul nopții',
    zi() === POIMAINE, 'a ajuns pe ' + zi());
}

/* =============================================================== Produse -- */
//
// Șablonul stă în document, în <x-dc>, până când îl randează runtime-ul.
// Browserul îl parcurge înainte și cere de la server chiar adresa scrisă în
// atribut — adică textul cu acolade. Rezultat: 404-uri în jurnalul găzduirii
// și erori roșii în consolă, la fiecare deschidere, de la fiecare utilizator.
// O imagine leneșă nu se cere: <x-dc> e ascuns, deci nu ajunge lângă ecran.

console.log('\n=== Produse.dc.html — legături nerandate în atribute cerute de browser ===');

{
  const paginiGrupa = ['Client.dc.html', 'Ruta Sofer.dc.html', 'Produse.dc.html'];
  const vinovate = [];
  let cuLegatura = 0;

  for (const nume of paginiGrupa) {
    const { sablon } = paginaBruta(nume);
    for (const m of sablon.matchAll(/<img\b[^>]*>/gi)) {
      const tag = m[0];
      const src = /\ssrc\s*=\s*"([^"]*)"/i.exec(tag);
      if (!src || !src[1].includes('{{')) continue;
      cuLegatura++;
      // `loading="lazy"` e singurul lucru care oprește cererea speculativă a
      // parserului pentru o imagine dintr-un părinte ascuns.
      if (!/\sloading\s*=\s*"lazy"/i.test(tag)) {
        vinovate.push(nume + ' — ' + tag.slice(0, 110));
      }
    }
  }

  check('paginile chiar au imagini cu adresa legată (altfel proba n-ar verifica nimic)',
    cuLegatura >= 2, 'am găsit ' + cuLegatura);
  check('nicio imagine nu-i cere serverului textul cu acolade al legăturii',
    vinovate.length === 0, vinovate.join(' | '));

  // Legătura trebuie să rămână: „reparația” prin ștergerea fotografiei ar
  // scoate pozele din catalog, nu 404-urile din jurnal.
  const produse = paginaBruta('Produse.dc.html').sablon;
  check('miniatura din catalog își păstrează adresa legată',
    /<img\b[^>]*src\s*=\s*"\{\{\s*p\.photo\s*\}\}"/.test(produse));
  check('fotografia din formular își păstrează adresa legată',
    /<img\b[^>]*src\s*=\s*"\{\{\s*f\.photo\s*\}\}"/.test(produse));
}

console.log(`\n${'='.repeat(52)}`);
console.log(`${pass} trecute · ${fail} eșuate`);
if (failures.length) {
  console.log('\nEȘUATE:');
  for (const f of failures) console.log('  · ' + f);
}
console.log();
process.exit(fail ? 1 : 0);
