// Probele pentru cele două defecte din Depozit.dc.html.
//
//   node .claude/test-defect-depozit.mjs
//
// 1. Depozit.dc.html:605 — addMove() înghițea eroarea și o transforma în
//    promisiune rezolvată, iar apelantul anunța „Intrare înregistrată · +50 kg”
//    pentru o mișcare pe care serverul o refuzase. Depozitarul rămânea convins
//    că a scăzut din stoc.
// 2. Depozit.dc.html — „Rezervat” și „Lipsă la comenzi” se socoteau în pagină,
//    din comenzile aduse de GET /orders (cel mult 500). Peste atâtea comenzi
//    cifra devenea tăcut greșită. Socoteala e acum pe server; proba de aici
//    apără granița: pagina afișează ce i se trimite și nu reface nimic.
//
// Probele nu citesc șiruri din sursă: scot blocul de logică al paginii, îl
// rulează cu un `gp` de mucava și se uită la ce iese din renderVals() — adică
// la cifrele și mesajele care ajung pe ecran. Fiecare a fost verificată
// stricând reparația la loc.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SRC = fs.readFileSync(path.join(ROOT, 'Depozit.dc.html'), 'utf8');

let pass = 0, fail = 0;
const failures = [];
const check = (nume, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${nume}`); }
  else { fail++; failures.push(nume + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${nume}${extra ? ' — ' + extra : ''}`); }
};

/** Marcajul și blocul de logică, separat — ca în check-panouri.mjs. */
const iScript = SRC.indexOf('<script type="text/x-dc"');
const MARCAJ = SRC.slice(0, iScript);
const LOGICA = (/<script[^>]*type="text\/x-dc"[^>]*>([\s\S]*?)<\/script>/.exec(SRC) || [])[1] || '';

if (!LOGICA) {
  console.log('  FAIL  blocul <script type="text/x-dc"> nu se poate citi din Depozit.dc.html');
  process.exit(1);
}

const AZI = (() => {
  const d = new Date(), z = (n) => (n < 10 ? '0' : '') + n;
  return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate());
})();

/**
 * Clasa paginii, rulată în afara browserului.
 *
 * Tot ce pagina ia din browser (localStorage, document, window, history) și
 * tot ce ia de pe server (`gp`) intră ca parametru, deci proba vede exact
 * codul livrat, nu o copie a lui.
 */
function fabricaComponent(gp) {
  class DCLogic {
    setState(x) { Object.assign(this.state, typeof x === 'function' ? x(this.state) : x); }
  }
  const localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
  const document = { documentElement: { setAttribute() {} }, addEventListener() {} };
  const window = { addEventListener() {}, __gpNav: 1 };
  const history = { state: null, pushState() {}, back() {} };
  const location = { search: '', href: '' };
  const f = new Function(
    'gp', 'DCLogic', 'localStorage', 'document', 'window', 'history', 'location',
    'setInterval', 'clearInterval',
    LOGICA + '\nreturn Component;'
  );
  return f(gp, DCLogic, localStorage, document, window, history, location, () => 0, () => {});
}

/** `gp` de mucava: doar cât are nevoie pagina. */
function fabricaGp(peste = {}) {
  return Object.assign({
    days: () => [{ iso: AZI }],
    done: () => 'Predată',
    cancelled: () => 'Anulată',
    lists: () => Promise.resolve({}),
    me: () => Promise.resolve({ name: 'Depozitar Test', role: 'angajat' }),
    toPageOrder: (o) => o,
    __lists: { stockCategories: ['Materii prime', 'Produse finite', 'Ambalaje'] },
    get: () => Promise.resolve({}),
    post: () => Promise.resolve({}),
    patch: () => Promise.resolve({}),
    syncStock: () => Promise.resolve({ orders: 0, pieces: 0, missing: [], skipped: [] }),
  }, peste);
}

/** O pagină gata încărcată, fără componentDidMount (fără cronometre, fără pull). */
function pagina(gp, stare, props = {}) {
  const Component = fabricaComponent(gp);
  const c = new Component();
  c.props = Object.assign({ showReserved: true, autoDeduct: false, defaultView: 'Stoc' }, props);
  Object.assign(c.state, { loaded: true, who: 'Depozitar Test', role: 'angajat' }, stare);
  return c;
}

/** Eroarea așa cum o aruncă shared/gp-api.js. */
function gpErr(mesaj, status = 401) {
  const e = new Error(mesaj);
  e.status = status;
  e.isAuth = status === 401;
  e.code = status === 0 ? 'network' : '';
  return e;
}

const asteapta = () => new Promise((r) => setTimeout(r, 5));

const FAINA = { id: 7, name: 'Făină 650', category: 'Materii prime', unit: 'kg', qty: 120, minQty: 100, low: false, productId: null };

/* ============================== Depozit.dc.html:605 · mișcarea refuzată === */

console.log('\n=== Depozit.dc.html:605 · mișcarea refuzată nu are voie să pară înregistrată ===');

// Promisiunea lui addMove trebuie să rămână respinsă. Aici se rupea lanțul:
// cu `.catch` care doar punea mesajul, promisiunea se rezolva și orice `.then`
// al apelantului rula ca după o salvare reușită.
{
  const c = pagina(fabricaGp({ post: () => Promise.reject(gpErr('Sesiune expirată. Intră din nou în cont.')) }), {
    items: [FAINA], orders: [], moves: [],
  });
  let stare = 'rezolvată';
  await c.addMove(7, 50, 1, 'Livrare furnizor', '').then(() => {}, () => { stare = 'respinsă'; });
  check('addMove() lasă eroarea serverului să treacă mai departe',
    stare === 'respinsă', 'promisiunea a ieșit ' + stare);
  check('addMove() pune totuși mesajul serverului pe ecran',
    c.state.msg === 'Sesiune expirată. Intră din nou în cont.', 'mesajul a rămas „' + c.state.msg + '”');
}

// Drumul omului: caseta „Salvează intrarea”, cu sesiunea expirată între timp.
{
  const c = pagina(fabricaGp({ post: () => Promise.reject(gpErr('Sesiune expirată. Intră din nou în cont.')) }), {
    items: [FAINA], orders: [], moves: [],
    form: { k: 'in', p: 'Făină 650', q: '50', r: 'Livrare furnizor', note: '', err: '' },
  });
  c.renderVals().onSave();
  await asteapta();

  check('intrarea refuzată de server nu mai e anunțată ca înregistrată',
    !/Intrare înregistrată/.test(c.state.msg || ''),
    'pagina a scris „' + c.state.msg + '”');
  const F = c.state.form;
  check('caseta se deschide la loc, cu cifrele omului în ea, ca să poată încerca din nou',
    !!F && F.q === '50' && F.p === 'Făină 650' && /Sesiune expirată/.test(F.err || ''),
    F ? JSON.stringify({ q: F.q, err: F.err }) : 'caseta a rămas închisă');
}

// Ieșirea refuzată cu 422 („bad_qty”, „bad_item”) — fără nicio pană de rețea.
{
  const c = pagina(fabricaGp({ post: () => Promise.reject(gpErr('Cantitate prea mare.', 422)) }), {
    items: [FAINA], orders: [], moves: [],
    form: { k: 'out', p: 'Făină 650', q: '20', r: 'Consum în producție', note: '', err: '' },
  });
  c.renderVals().onSave();
  await asteapta();
  check('ieșirea refuzată cu 422 nu mai e anunțată ca înregistrată',
    !/Ieșire înregistrată/.test(c.state.msg || ''), 'pagina a scris „' + c.state.msg + '”');
}

// Drumul reușit trebuie să rămână neatins: altfel „reparația" ar fi doar
// stingerea confirmării.
{
  let trimis = null;
  const gp = fabricaGp({
    post: (cale, corp) => { trimis = { cale, corp }; return Promise.resolve({ id: 1 }); },
    get: (cale) => Promise.resolve(cale === '/stock/items'
      ? { items: [FAINA], categories: [], units: [] }
      : cale === '/stock/moves' ? { moves: [] } : { orders: [] }),
  });
  const c = pagina(gp, {
    items: [FAINA], orders: [], moves: [],
    form: { k: 'in', p: 'Făină 650', q: '50', r: 'Livrare furnizor', note: '', err: '' },
  });
  c.renderVals().onSave();
  await asteapta();
  check('intrarea primită de server ajunge chiar pe server',
    !!trimis && trimis.cale === '/stock/moves' && trimis.corp.itemId === 7 && trimis.corp.qty === 50,
    JSON.stringify(trimis));
  check('intrarea reușită se anunță în continuare',
    /^Intrare înregistrată · \+50 kg Făină 650$/.test(c.state.msg || ''),
    'pagina a scris „' + c.state.msg + '”');
  check('după o salvare reușită caseta rămâne închisă',
    c.state.form === null, JSON.stringify(c.state.form));
}

// Butonul „Anulează" din registru, pe același lanț de promisiuni.
{
  const miscare = { id: 55, itemId: 7, d: AZI, t: '09:10', k: 'in', p: 'Făină 650', q: 50, r: 'Livrare furnizor', note: '', w: 'Depozitar Test' };
  const c = pagina(fabricaGp({ post: () => Promise.reject(gpErr('Nu am putut contacta serverul.', 0)) }), {
    items: [FAINA], orders: [], moves: [miscare],
  });
  const rand = c.renderVals().logDays[0].moves[0];
  await rand.onDelete();
  await asteapta();
  check('corecția refuzată nu mai e anunțată ca înregistrată',
    !/Corecție înregistrată/.test(c.state.msg || ''), 'pagina a scris „' + c.state.msg + '”');
  check('corecția refuzată lasă butonul liber pentru a doua încercare',
    c.state.undoing === null && !c.__anulez,
    JSON.stringify({ undoing: c.state.undoing, anulez: c.__anulez }));
}

// Corecția reușită trebuie să se anunțe mai departe.
{
  const miscare = { id: 55, itemId: 7, d: AZI, t: '09:10', k: 'in', p: 'Făină 650', q: 50, r: 'Livrare furnizor', note: '', w: 'Depozitar Test' };
  const gp = fabricaGp({
    post: () => Promise.resolve({ id: 56 }),
    get: (cale) => Promise.resolve(cale === '/stock/items'
      ? { items: [FAINA], categories: [], units: [] }
      : cale === '/stock/moves' ? { moves: [] } : { orders: [] }),
  });
  const c = pagina(gp, { items: [FAINA], orders: [], moves: [miscare] });
  await c.renderVals().logDays[0].moves[0].onDelete();
  await asteapta();
  check('corecția reușită se anunță în continuare',
    (c.state.msg || '') === 'Corecție înregistrată pentru · Făină 650', 'pagina a scris „' + c.state.msg + '”');
}

// Bannerul de sus: o eroare desenată verde e tot o confirmare falsă.
{
  const c = pagina(fabricaGp({ post: () => Promise.reject(gpErr('Sesiune expirată. Intră din nou în cont.')) }), {
    items: [FAINA], orders: [], moves: [],
  });
  await c.addMove(7, 50, 1, 'Livrare furnizor', '').then(() => {}, () => {});
  const eroare = c.renderVals().msgCls || '';
  check('mesajul de eroare nu se mai desenează ca succes',
    /danger/.test(eroare) && !/success/.test(eroare), 'clasa a ieșit „' + eroare + '”');

  c.setState({ msg: 'Articol adăugat · Cutie carton', msgBad: false });
  const succes = c.renderVals().msgCls || '';
  check('mesajul de succes rămâne verde',
    /success/.test(succes) && !/danger/.test(succes), 'clasa a ieșit „' + succes + '”');
}

// Clasa calculată nu ajută cu nimic dacă marcajul o ignoră.
check('bannerul își ia clasa din logică, nu din marcaj',
  /<div class="\{\{ msgCls \}\}"/.test(MARCAJ) && !/class="h-alert h-alert--success"/.test(MARCAJ),
  'în marcaj clasa bannerului e încă scrisă fix');

/* ===================== Depozit.dc.html · rezervările vin de la server ===== */

console.log('\n=== Depozit.dc.html · „Rezervat" e cifra serverului, nu una refăcută în pagină ===');

// Istoria rândului, pe scurt. „Rezervat" se socotea aici, în browser: pagina
// aduna pozițiile comenzilor deschise. Potrivirea pe nume s-a dovedit greșită
// (numele produsului se schimbă din catalog, cel al articolului de depozit nu)
// și s-a reparat legând pe product_id. Reparația era bună, dar așezată pe un
// teren care nu ținea: suma pleca de la `GET /orders`, care întoarce cel mult
// 500 de comenzi. Peste atâtea, „Rezervat" scădea TĂCUT — niciun semn pe ecran
// că s-a numărat doar o parte — iar depozitarul cumpăra după cifra aceea.
//
// Socoteala s-a mutat pe server (api/routes/stock.php, route_items_list), pe
// tabelul `stock_reservations`, unde intră toate comenzile. Potrivirea poziție
// → articol se face acum o singură dată, la scrierea rezervării, și e probată
// pe bază adevărată în .claude/test-reteta-productie.mjs.
//
// Ce apără probele de mai jos e granița: pagina AFIȘEAZĂ ce i se trimite. Dacă
// cineva readuce vreodată socoteala în browser, cifrele de aici nu se mai
// potrivesc și proba pică.

// Croissantul din sql/002_seed.sql: articol de depozit 24, product_id 9.
// Serverul spune: 40 pe raft, 300 promise, deci lipsă de 260.
const CROISSANT = {
  id: 24, name: 'Croissant cu fistic', category: 'Produse finite', unit: 'buc',
  qty: 40, minQty: 0, low: false, productId: 9, reserved: 300, available: -260,
};

{
  const c = pagina(fabricaGp(), { items: [CROISSANT], moves: [], orders: [] });
  const v = c.renderVals();
  const r = v.rows[0];
  check('rândul arată cantitatea promisă trimisă de server',
    r.res === '300', 'rândul arată Rezervat ' + r.res);
  check('„Disponibil” arată lipsa, nu stocul de pe raft',
    r.avail === '-260', 'rândul arată Disponibil ' + r.avail);
  check('rândul primește eticheta „Lipsă”',
    r.flag === 'Lipsă 260 buc', 'eticheta a ieșit „' + r.flag + '”');
  check('cartonașul „Lipsă la comenzi” numără articolul',
    v.stats[1].label === 'Lipsă la comenzi' && v.stats[1].value === '1',
    JSON.stringify({ label: v.stats[1].label, value: v.stats[1].value }));
}

// Proba care pică dacă socoteala se întoarce în browser: comenzile din pagină
// spun cu totul altceva decât serverul. Pagina n-are voie să le bage în seamă —
// ele sunt acolo pentru butonul „Scade din stoc" și pentru legarea articolelor
// nou create, nu pentru rezervări.
{
  const c = pagina(fabricaGp(), {
    items: [CROISSANT], moves: [],
    orders: [
      { id: 1, status: 'La cuptor', items: [{ q: '999', n: 'Croissant cu fistic', productId: 9 }] },
      { id: 2, status: 'La dospit', items: [{ q: '77', n: 'Croissant fistic 150 g', productId: 9 }] },
    ],
  });
  const r = c.renderVals().rows[0];
  check('comenzile ajunse în pagină nu mai schimbă cifra rezervată',
    r.res === '300' && r.avail === '-260', JSON.stringify({ res: r.res, avail: r.avail }));
}

// „Disponibil" e o a doua cifră trimisă de server, nu o scădere refăcută aici:
// altfel ar exista două definiții ale aceluiași lucru, care se pot despărți.
// Fixtura e dinadins necoerentă (40 − 3 nu face 2) tocmai ca să se vadă care
// din ele ajunge pe ecran.
{
  const ciudat = Object.assign({}, CROISSANT, { qty: 40, reserved: 3, available: 2 });
  const c = pagina(fabricaGp(), { items: [ciudat], moves: [], orders: [] });
  const r = c.renderVals().rows[0];
  check('„Disponibil” e cifra serverului, nu „stoc − rezervat" refăcut în pagină',
    r.avail === '2', 'rândul arată Disponibil ' + r.avail);
}

// Articolele adăugate din caseta „Articol nou" pleacă fără product_id. Pentru
// ele serverul se leagă pe nume; pagina, la fel ca la celelalte, doar afișează.
{
  const cutie = {
    id: 30, name: 'Cutie carton 6', category: 'Ambalaje', unit: 'buc',
    qty: 5, minQty: 0, low: false, productId: null, reserved: 8, available: -3,
  };
  const c = pagina(fabricaGp(), { items: [cutie], moves: [], orders: [] });
  const r = c.renderVals().rows[0];
  check('articolul fără produs își arată la fel promisiunea',
    r.res === '8' && r.avail === '-3', JSON.stringify({ res: r.res, avail: r.avail }));
}

// Un server vechi, care încă nu trimite cele două câmpuri, nu are voie să facă
// pagina să arate „Lipsă" pe tot depozitul: fără cifre, rezervarea e zero și
// disponibilul e stocul.
{
  const vechi = { id: 24, name: 'Croissant cu fistic', category: 'Produse finite', unit: 'buc', qty: 40, minQty: 0, low: false, productId: 9 };
  const c = pagina(fabricaGp(), { items: [vechi], moves: [], orders: [] });
  const r = c.renderVals().rows[0];
  check('fără cifrele de la server, rândul arată stocul întreg, nu „Lipsă”',
    r.res === '0' && r.avail === '40' && r.flag === '', JSON.stringify({ res: r.res, avail: r.avail, flag: r.flag }));
}

// Steagul din proprietăți stinge coloana, nu socoteala.
{
  const c = pagina(fabricaGp(), { items: [CROISSANT], moves: [], orders: [] }, { showReserved: false });
  const v = c.renderVals();
  check('cu „Rezervat” stins, rândul arată stocul întreg',
    v.rows[0].res === '0' && v.rows[0].avail === '40' && v.resDisplay === 'none',
    JSON.stringify({ res: v.rows[0].res, avail: v.rows[0].avail }));
}

console.log('\n' + pass + ' trecute · ' + fail + ' eșuate');
if (fail) {
  console.log('\nCe a picat:');
  for (const f of failures) console.log('  · ' + f);
}
process.exit(fail ? 1 : 0);
