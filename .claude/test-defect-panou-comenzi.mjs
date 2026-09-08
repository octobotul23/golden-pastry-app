// Probe pentru cele trei defecte reparate în „Administrare Comenzi.dc.html".
//
//   node .claude/test-defect-panou-comenzi.mjs
//
// Pagina se randează în browser, deci nu poate fi apelată direct. Ca în
// `.claude/check-panouri.mjs`, scoatem funcțiile din sursă și le rulăm aici:
// probele se uită la ce calculează codul, nu la un cuvânt scris undeva. Ultima
// secțiune merge până la capăt, pe serverul local: reface scenariul cu doi
// oameni care lucrează în același timp și verifică pe comanda reală din bază.
//
// Defectele acoperite:
//   Administrare Comenzi.dc.html:918  reîmprospătarea aducea doar cererile, iar
//                                     salvarea rescria comanda cu instantaneul
//                                     vechi, etapă cu tot;
//   Administrare Comenzi.dc.html:868  fereastra de zile înghețată la încărcare;
//   Administrare Comenzi.dc.html:1273 cantitatea greșită devenea tăcut 1 bucată.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const FISIER = 'Administrare Comenzi.dc.html';
const src = fs.readFileSync(path.join(ROOT, FISIER), 'utf8');
const taietura = src.indexOf('<script type="text/x-dc"');
const marcaj = src.slice(0, taietura < 0 ? src.length : taietura);
const logica = taietura < 0 ? '' : src.slice(taietura);
// Comentariile pomenesc liniile vechi ca să explice de ce s-au schimbat, deci
// căutările „codul vechi nu mai există" trebuie să se uite doar la cod.
const cod = logica.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

let pass = 0, fail = 0;
const esecuri = [];
const check = (defect, nume, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${nume}`); }
  else {
    fail++;
    esecuri.push(`${nume}  [${defect}]${extra ? ' — ' + extra : ''}`);
    console.log(`  FAIL  ${nume}  [${defect}]${extra ? ' — ' + extra : ''}`);
  }
};

/** Corpul unei metode a clasei, scos din sursă. */
const metoda = (nume, argumente) => {
  const re = new RegExp('\\n  ' + nume + '\\(' + argumente + '\\) \\{([\\s\\S]*?)\\n  \\}');
  const m = re.exec(logica);
  return m ? m[1] : null;
};

const p2 = (n) => (n < 10 ? '0' : '') + n;
const iso = (d) => d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate());

/* ============================ 1273 · cantitatea scrisă greșit se refuză == */

console.log('\n=== Administrare Comenzi.dc.html:1273 — cantitatea nu mai devine tăcut 1 ===');

const corpCant = metoda('citesteCantitate', 'v');
check('Administrare Comenzi.dc.html:1273', 'pagina are o funcție care citește cantitatea',
  !!corpCant, 'nu am găsit citesteCantitate(v) în sursă');

let citesteCantitate = null;
if (corpCant) {
  citesteCantitate = new Function('v', corpCant);

  // Exact intrările din scenariu: câmpul golit ca să fie rescris, „0" pus
  // intenționat pentru o poziție de anulat, un cuvânt în loc de cifră.
  const refuzate = ['', ' ', '0', '0.00', 'doua', '12 buc', '-3', 'NaN', null, undefined, '1e3', '10001'];
  const trecute = refuzate.filter((v) => citesteCantitate(v) !== null);
  check('Administrare Comenzi.dc.html:1273', 'orice cantitate necitibilă e refuzată, nu transformată în 1',
    trecute.length === 0,
    'au trecut: ' + JSON.stringify(trecute.map((v) => [v, citesteCantitate(v)])));

  // Refuzul nu are voie să lovească și cantitățile bune.
  const bune = [['12', 12], ['1', 1], [' 7 ', 7], ['2.5', 2.5], ['2,5', 2.5], ['10000', 10000], [3, 3]];
  const stricate = bune.filter(([v, asteptat]) => citesteCantitate(v) !== asteptat);
  check('Administrare Comenzi.dc.html:1273', 'cantitățile bune trec neatinse',
    stricate.length === 0,
    'au ieșit greșit: ' + JSON.stringify(stricate.map(([v]) => [v, citesteCantitate(v)])));

  check('Administrare Comenzi.dc.html:1273', 'niciuna dintre cantitățile refuzate nu iese ca 1 bucată',
    refuzate.every((v) => citesteCantitate(v) !== 1));
}

// Funcția nu ajută cu nimic dacă salvarea nu o folosește.
check('Administrare Comenzi.dc.html:1273', 'salvarea nu mai citește cantitatea cu „parseInt || 1"',
  !/parseInt\(i\.q, ?10\) *\|\| *1/.test(cod), 'linia veche e încă în sursă');
check('Administrare Comenzi.dc.html:1273', 'salvarea trece cantitatea prin funcția care poate refuza',
  /qty: this\.citesteCantitate\(i\.q\)/.test(logica));
check('Administrare Comenzi.dc.html:1273', 'o cantitate refuzată oprește salvarea cu un mesaj',
  /\.filter\(i => i\.qty === null\)/.test(logica) && /cantitate validă/.test(logica),
  'nu am găsit oprirea salvării pentru cantități invalide');
check('Administrare Comenzi.dc.html:1273', 'câmpul de cantitate cere tastatura numerică',
  // „decimal", nu „numeric": tastatura numerică de pe iPhone n-are virgulă,
  // iar cantitățile pot fi 0,5 (vezi citesteCantitate).
  /value="\{\{ it\.q \}\}"[^>]*inputMode="(numeric|decimal)"/.test(marcaj));

/* ================================= 868 · fereastra de zile se recalculează */

console.log('\n=== Administrare Comenzi.dc.html:868 — fereastra de zile nu mai îngheață ===');

const declDays = /\nlet DAYS = [^\n]*\n/.exec(logica);
const declToday = /\nlet TODAY = [^\n]*\n/.exec(logica);
const fnRefresh = /\nconst refreshDays = \(\) => \{[\s\S]*?\n\};/.exec(logica);
const corpTrezi = metoda('treziZiua', '');

check('Administrare Comenzi.dc.html:868', 'fereastra de zile se poate recalcula (nu mai e constantă)',
  !!(declDays && declToday && fnRefresh),
  'lipsesc: ' + [!declDays && 'let DAYS', !declToday && 'let TODAY', !fnRefresh && 'refreshDays']
    .filter(Boolean).join(', '));
check('Administrare Comenzi.dc.html:868', 'trecerea în ziua nouă se poate citi din sursă', !!corpTrezi);

if (declDays && declToday && fnRefresh && corpTrezi) {
  // Un calendar de mână, ca să putem muta ceasul peste noapte.
  let ceas = new Date(2026, 7, 29, 21, 0, 0);   // 29 august 2026, seara
  const gpFals = {
    days(n = 7) {
      const out = [];
      const baza = new Date(ceas); baza.setHours(0, 0, 0, 0);
      for (let i = 0; i < n; i++) {
        const d = new Date(baza); d.setDate(baza.getDate() + i);
        out.push({ iso: iso(d), label: 'et-' + iso(d) });
      }
      return out;
    },
    today() { return iso(ceas); },
  };

  const modul = new Function('gp', [
    declDays[0], declToday[0], fnRefresh[0],
    'function treziZiua() {' + corpTrezi + '\n}',
    'return { treziZiua, azi: () => TODAY, fereastra: () => DAYS };',
  ].join('\n'))(gpFals);

  check('Administrare Comenzi.dc.html:868', 'la deschidere, ziua implicită e ziua curentă',
    modul.azi() === '2026-08-29', 'a dat ' + modul.azi());

  // Panoul rămâne deschis peste noapte. Fără reparație, aici rămâne ieri.
  ceas = new Date(2026, 7, 30, 8, 0, 0);
  check('Administrare Comenzi.dc.html:868', 'fără trezire, ziua rămâne cea de la deschidere (defectul)',
    modul.azi() === '2026-08-29', 'a dat ' + modul.azi());

  const panou = {
    state: { rDay: '2026-08-29', fDay: 'toate' },
    randari: 0,
    setState(p) { Object.assign(this.state, p); this.randari++; },
    forceUpdate() { this.randari++; },
  };
  modul.treziZiua.call(panou);

  check('Administrare Comenzi.dc.html:868', 'dimineața, comanda nouă pornește pe ziua de azi, nu pe ieri',
    modul.azi() === '2026-08-30', 'a dat ' + modul.azi());
  check('Administrare Comenzi.dc.html:868', 'fereastra întreagă se mută odată cu ziua',
    modul.fereastra().length === 7
    && modul.fereastra()[0].v === '2026-08-30'
    && modul.fereastra()[6].v === '2026-09-05',
    JSON.stringify(modul.fereastra().map((d) => d.v)));
  check('Administrare Comenzi.dc.html:868', 'ruta pornită pe ziua de ieri e adusă pe ziua nouă',
    panou.state.rDay === '2026-08-30', 'a rămas ' + panou.state.rDay);
  check('Administrare Comenzi.dc.html:868', 'ecranul se redesenează la trecerea zilei',
    panou.randari > 0);

  // Cine se uita la comenzile de poimâine nu trebuie mutat pe altă listă.
  ceas = new Date(2026, 7, 31, 8, 0, 0);
  const alDoilea = {
    state: { rDay: '2026-09-02', fDay: '2026-09-02' },
    randari: 0,
    setState(p) { Object.assign(this.state, p); this.randari++; },
    forceUpdate() { this.randari++; },
  };
  modul.treziZiua.call(alDoilea);
  check('Administrare Comenzi.dc.html:868', 'ziua aleasă de om nu se schimbă dacă mai încape în fereastră',
    alDoilea.state.rDay === '2026-09-02' && alDoilea.state.fDay === '2026-09-02',
    JSON.stringify(alDoilea.state));

  // Ziua nu se schimbă → nu se recalculează nimic degeaba.
  const inaintea = modul.azi();
  const stabil = { state: { rDay: modul.azi(), fDay: 'toate' }, randari: 0, setState() { this.randari++; }, forceUpdate() { this.randari++; } };
  modul.treziZiua.call(stabil);
  check('Administrare Comenzi.dc.html:868', 'în aceeași zi nu se redesenează degeaba',
    stabil.randari === 0 && modul.azi() === inaintea);
}

/* ============= 918 · reîmprospătarea comenzilor și salvarea fără regresie = */

console.log('\n=== Administrare Comenzi.dc.html:918 — comenzile se reîmprospătează, salvarea nu mai dă etapa înapoi ===');

// setInterval sau gp.poll — al doilea se oprește cât fila e ascunsă și nu
// suprapune două ture; bătaia e aceeași.
const cronometru = /this\.reqTimer = (?:setInterval|gp\.poll)\(\(\) => this\.(\w+)\(\), (\d+)\);/.exec(logica);
check('Administrare Comenzi.dc.html:918', 'cronometrul panoului se poate citi din sursă', !!cronometru);
if (cronometru) {
  const corpTick = metoda(cronometru[1], '');
  check('Administrare Comenzi.dc.html:918', 'bătaia periodică aduce și comenzile, nu doar cererile',
    !!corpTick && /this\.pullOrders\(\)/.test(corpTick) && /this\.pullRequests\(\)/.test(corpTick),
    'corpul lui ' + cronometru[1] + '(): ' + JSON.stringify(corpTick));
  check('Administrare Comenzi.dc.html:918', 'bătaia periodică duce panoul și în ziua nouă',
    !!corpTick && /this\.treziZiua\(\)/.test(corpTick));
  check('Administrare Comenzi.dc.html:918', 'reîmprospătarea rămâne la patru secunde',
    Number(cronometru[2]) <= 4000, 'a dat ' + cronometru[2] + ' ms');
}

// Reîmprospătarea nu are voie să atingă formularul deschis: comenzile intră în
// `orders`, ce a tastat omul stă în `form`.
const corpPullOrders = metoda('pullOrders', '');
check('Administrare Comenzi.dc.html:918', 'reîmprospătarea comenzilor nu se atinge de formularul deschis',
  !!corpPullOrders && !/\bform\b/.test(corpPullOrders),
  'pullOrders() scrie în form: ' + JSON.stringify(corpPullOrders));

const corpDiff = metoda('doarSchimbate', 'nou, baza');
const corpAntet = metoda('antetDin', 'o');
check('Administrare Comenzi.dc.html:918', 'salvarea are un punct de reper și o comparație, citibile din sursă',
  !!(corpDiff && corpAntet),
  'lipsesc: ' + [!corpDiff && 'doarSchimbate', !corpAntet && 'antetDin'].filter(Boolean).join(', '));
check('Administrare Comenzi.dc.html:918', 'formularul reține comanda așa cum a venit de la server',
  /this\.__formBase = o \? this\.antetDin\(o\) : null/.test(logica));
check('Administrare Comenzi.dc.html:918', 'PATCH-ul nu mai trimite antetul întreg, ci doar ce s-a schimbat',
  /gp\.patch\('\/orders\/' \+ f\.id, schimbat\)/.test(logica)
  && !/gp\.patch\('\/orders\/' \+ f\.id, Object\.assign\(\{\}, antet/.test(logica));

let doarSchimbate = null, antetDin = null, baza = null;
const comanda = {
  id: 1042, ref: '#1042', cust: 'Ana Pop', chan: 'Livrare', date: '2026-09-01',
  slot: '08:00', status: 'În așteptare', urgent: false, timed: false,
  note: '', phone: '0700111222', addr: 'Calea Victoriei 63', zone: 'Sector 1',
  items: [{ q: '12', n: 'Croasant', productId: 7, variant: 'copt' }],
};

if (corpDiff && corpAntet && citesteCantitate) {
  const gazda = {
    citesteCantitate,
    antetDin: new Function('o', corpAntet),
    doarSchimbate: new Function('nou', 'baza', corpDiff),
  };
  antetDin = (o) => gazda.antetDin.call(gazda, o);
  doarSchimbate = (n, b) => gazda.doarSchimbate.call(gazda, n, b);
  baza = antetDin(comanda);

  // Scenariul din raport: administratorul deschide comanda dimineața, o salvează
  // la prânz doar ca să corecteze telefonul. Între timp laboratorul a mutat-o
  // în cuptor. Ce nu a atins el nu are voie să plece spre server.
  const doarTelefon = doarSchimbate(Object.assign({}, baza, { phone: '0733444555' }), baza);
  check('Administrare Comenzi.dc.html:918', 'corectarea telefonului trimite numai telefonul',
    JSON.stringify(Object.keys(doarTelefon).sort()) === '["phone"]',
    JSON.stringify(Object.keys(doarTelefon)));
  check('Administrare Comenzi.dc.html:918', 'etapa neatinsă nu mai pleacă spre server',
    !('status' in doarTelefon));
  check('Administrare Comenzi.dc.html:918', 'pozițiile neatinse nu se rescriu',
    !('items' in doarTelefon));

  // Reparația nu are voie să înghită modificările adevărate.
  const etapa = doarSchimbate(Object.assign({}, baza, { status: 'Predată' }), baza);
  check('Administrare Comenzi.dc.html:918', 'etapa schimbată de om chiar pleacă',
    etapa.status === 'Predată' && Object.keys(etapa).length === 1, JSON.stringify(etapa));

  const cuAlteCantitati = Object.assign({}, baza, {
    items: [{ productId: 7, qty: 24, variant: 'copt' }],
  });
  const pozitii = doarSchimbate(cuAlteCantitati, baza);
  check('Administrare Comenzi.dc.html:918', 'pozițiile schimbate chiar pleacă',
    Array.isArray(pozitii.items) && pozitii.items[0].qty === 24 && Object.keys(pozitii).length === 1,
    JSON.stringify(pozitii));

  check('Administrare Comenzi.dc.html:918', 'un formular nemodificat nu trimite nimic',
    Object.keys(doarSchimbate(baza, baza)).length === 0,
    JSON.stringify(doarSchimbate(baza, baza)));
  check('Administrare Comenzi.dc.html:918', 'comanda nouă (fără punct de reper) se trimite întreagă',
    Object.keys(doarSchimbate(baza, null)).length === Object.keys(baza).length);
  check('Administrare Comenzi.dc.html:918', 'salvarea fără nicio schimbare nu mai cere serverului un PATCH gol',
    /if \(!Object\.keys\(schimbat\)\.length\)/.test(logica));
}

/* ================================ 918 · proba pe serverul local, cap-coadă */

console.log('\n=== Administrare Comenzi.dc.html:918 — pe comanda reală din bază ===');

const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';
const a = { cookies: new Map(), csrf: '' };
async function call(metodaHttp, cale, corp) {
  const headers = {};
  if (corp !== undefined) headers['Content-Type'] = 'application/json';
  if (a.csrf && metodaHttp !== 'GET') headers['X-CSRF-Token'] = a.csrf;
  if (a.cookies.size) headers['Cookie'] = [...a.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
  const res = await fetch(BASE + cale, {
    method: metodaHttp, headers,
    body: corp === undefined ? undefined : JSON.stringify(corp),
    redirect: 'manual',
  });
  for (const raw of res.headers.getSetCookie?.() ?? []) {
    const [pereche] = raw.split(';');
    const i = pereche.indexOf('=');
    a.cookies.set(pereche.slice(0, i).trim(), pereche.slice(i + 1).trim());
  }
  const brut = await res.text();
  let data = null;
  if (brut) { try { data = JSON.parse(brut); } catch { data = brut.slice(0, 200); } }
  return { status: res.status, data };
}

let comandaProba = null;
try {
  const intrare = await call('POST', '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  if (intrare.status !== 200) throw new Error('autentificare ' + intrare.status);
  a.csrf = intrare.data.csrf;
} catch (e) {
  console.log('\n  —  sărit: serverul de dezvoltare nu răspunde pe ' + BASE + ' (' + e.message + ')');
}

// Dacă reparația e anulată, funcțiile nu mai există în sursă. Secțiunea merge
// mai departe cu ce făcea pagina înainte — trimite antetul întreg —, ca proba
// să arate pe comanda reală ce se întâmplă atunci: etapa sare înapoi.
const reperulDin = antetDin || ((o) => ({
  customerName: o.cust || '', phone: o.phone || '', address: o.addr || '',
  zone: o.zone || '', day: o.date, slot: o.slot || '', fulfillment: o.chan,
  notes: o.note || '', urgent: !!o.urgent, timed: !!o.timed,
  ref: (o.ref || '').trim(), status: o.status,
  items: (o.items || []).map((i) => ({
    productId: i.productId == null ? null : i.productId,
    qty: parseInt(i.q, 10) || 1,
    variant: i.variant === 'congelat' ? 'congelat' : 'copt',
  })),
}));
const cePleaca = doarSchimbate || ((nou) => nou);

if (a.csrf) {
  try {
    const produse = (await call('GET', '/products')).data.products;
    const liste = (await call('GET', '/lists')).data;
    const azi = iso(new Date());
    const creata = await call('POST', '/orders', {
      customerName: 'PROBA panou comenzi', phone: '0700111222', address: '', zone: '',
      day: azi, slot: liste.slots[0], fulfillment: 'Ridicare', notes: '',
      urgent: false, timed: false, channel: 'panou',
      items: [{ productId: produse[0].id, qty: 12, variant: 'copt' }],
    });
    check('Administrare Comenzi.dc.html:918', 'comanda de probă se creează',
      creata.status === 201, JSON.stringify(creata.data).slice(0, 200));
    comandaProba = creata.data.order;

    if (comandaProba) {
      // Instantaneul de pe ecranul administratorului, de la 08:00.
      const vazutLa8 = {
        id: comandaProba.id, ref: comandaProba.ref, cust: comandaProba.customerName,
        chan: comandaProba.fulfillment, date: comandaProba.day, slot: comandaProba.slot,
        status: comandaProba.status, urgent: false, timed: false, note: '',
        phone: comandaProba.phone || '', addr: '', zone: '',
        items: (creata.data.order.items || []).map((it) => ({
          q: String(it.qty), n: it.name, productId: it.productId, variant: it.variant,
        })),
      };
      const reper = reperulDin(vazutLa8);

      // 08:20 — laboratorul o duce în cuptor.
      const mutata = await call('PATCH', '/orders/' + comandaProba.id, { status: 'În cuptor' });
      check('Administrare Comenzi.dc.html:918', 'laboratorul mută comanda la „În cuptor"',
        mutata.data?.order?.status === 'În cuptor', JSON.stringify(mutata.data).slice(0, 200));

      // 11:00 — administratorul, cu ecranul de la 08:00, corectează telefonul.
      const trimis = cePleaca(Object.assign({}, reper, { phone: '0733444555' }), reper);
      const salvata = await call('PATCH', '/orders/' + comandaProba.id, trimis);
      check('Administrare Comenzi.dc.html:918', 'corectura de telefon chiar se salvează',
        salvata.status === 200 && salvata.data?.order?.phone === '0733444555',
        JSON.stringify(salvata.data).slice(0, 200));
      check('Administrare Comenzi.dc.html:918', 'comanda rămâne „În cuptor" — nu mai sare înapoi cu o etapă',
        salvata.data?.order?.status === 'În cuptor',
        'a ajuns ' + salvata.data?.order?.status);
    }
  } finally {
    // Nu lăsăm gunoi în gp_dev: comanda de probă se anulează.
    if (comandaProba) await call('PATCH', '/orders/' + comandaProba.id, { status: 'Anulată' });
  }
}

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} picate`);
if (esecuri.length) {
  console.log('\nEșecuri:');
  esecuri.forEach((f) => console.log('  · ' + f));
}
process.exitCode = fail ? 1 : 0;
