// Proba pentru zăvorul `orders.stock_synced`, care mergea într-un singur sens.
//
//   node .claude/instanta.mjs porneste zavor
//   GP_BASE=http://127.0.0.1:82xx/api node .claude/test-defect-zavor-stoc.mjs
//   node .claude/instanta.mjs opreste zavor
//
// 1. PATCH /orders rescria pozițiile unei comenzi deja scăzute din depozit, dar
//    lăsa marcajul pus. Clientul suna și urca de la 10 la 40 de bucăți; a doua
//    apăsare pe „Scade comenzile" răspundea „0 comenzi, 0 bucăți", iar
//    depozitul rămânea pe vecie cu 10 scăzute pentru o marfă de 40. Nimeni nu
//    mai putea îndrepta registrul din aplicație.
// 2. O comandă deja scăzută trecută pe „Anulată" nu întorcea marfa: ea era în
//    rafturi, dar în evidență ieșise. Depozitul arăta mai puțin decât are, iar
//    marfa se comanda a doua oară.
//
// Proba își face singură produsele și articolele de depozit, ca cifrele să fie
// absolute și să nu depindă de ce a lăsat în urmă altă probă.
//
// Verificările care pică dacă reparația e dată înapoi:
//   · „a doua scădere trece 40 de bucăți prin depozit"  (dă 0 fără reparație)
//   · „stocul ajunge la −40, cât e comanda"             (rămâne −10)
//   · „anularea scrie o intrare de retur în registru"   (nu se scrie nimic)
//   · „stocul se întoarce la 0 după anulare"            (rămâne −7)

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
const patch = (a, p, b) => call(a, 'PATCH', p, b ?? {});

/* ---------------------------------------------------------- pregătire -- */

const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica ca admin: ' + r.status); process.exit(1); }
}

const liste = (await get(admin, '/lists')).data || {};
const PREDATA = liste.done;
const ANULATA = liste.cancelled;
// Fără cele două etape configurate, defectul nici nu se poate pune în scenă:
// scăderea din depozit se face numai pe etapa care încheie comanda.
if (!PREDATA || !ANULATA) {
  console.log('instanța nu are configurate etapa de încheiere și cea de anulare'); process.exit(1);
}
const GRUPA = ((await get(admin, '/categories')).data?.categories || [])
  .filter((c) => c.active).map((c) => c.slug)[0];
if (!GRUPA) { console.log('nu există nicio grupă de produse activă'); process.exit(1); }

const MARCA = 'zavor' + Date.now().toString(36);
const ZI = '2026-09-25';

/**
 * Un produs nou și articolul de depozit legat de el, numai pentru proba asta.
 *
 * Legătura se face prin `productId`, exact cum o caută scăderea din depozit:
 * după `product_id`, nu după nume.
 */
async function perecheProdusArticol(sufix) {
  const p = await post(admin, '/products', {
    name: 'Probă zăvor ' + sufix + ' ' + MARCA,
    nameEn: 'Lock probe ' + sufix + ' ' + MARCA,
    description: 'Produs de probă.', descriptionEn: 'Probe product.',
    category: GRUPA, grams: 100, priceFrozen: '2.00', priceBaked: '3.00',
  });
  if (p.status !== 201) throw new Error('nu pot crea produsul de probă: ' + p.status + ' ' + JSON.stringify(p.data));

  const a = await post(admin, '/stock/items', {
    name: 'Probă zăvor ' + sufix + ' ' + MARCA,
    category: 'Produse finite', unit: 'buc', minQty: 0, productId: p.data.id,
  });
  if (a.status !== 201) throw new Error('nu pot crea articolul de depozit: ' + a.status + ' ' + JSON.stringify(a.data));

  return { produs: p.data.id, articol: a.data.id };
}

/** Stocul curent al unui articol, așa cum îl vede pagina Depozit. */
async function stoc(articol) {
  const r = await get(admin, '/stock/items');
  const it = (r.data?.items || []).find((i) => i.id === articol);
  return it ? it.qty : null;
}

/** Mișcările scrise pentru un articol, cele mai noi întâi. */
const miscari = async (articol) => (await get(admin, '/stock/moves?item=' + articol)).data?.moves || [];
const intrari = (mv) => mv.filter((m) => m.direction === 1);

async function comanda(nume, produs, cantitate) {
  const r = await post(admin, '/orders', {
    customerName: nume, phone: '0700 000 111', address: 'Str. Probei 1',
    day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: produs, qty: cantitate, variant: 'copt' }],
  });
  if (r.status !== 201) throw new Error('nu pot crea comanda de probă: ' + r.status + ' ' + JSON.stringify(r.data));
  return r.data.order;
}

/* ====== 1. cantitatea urcată la telefon ajunge în depozit ============== */

console.log('\n=== PATCH /orders: pozițiile schimbate deschid zăvorul ===');
{
  const { produs, articol } = await perecheProdusArticol('cantitate');
  const o = await comanda('Probă Zăvor Cantitate ' + MARCA, produs, 10);

  await patch(admin, `/orders/${o.id}`, { status: PREDATA });
  const s1 = await post(admin, '/stock/sync');
  check('prima scădere trece 10 bucăți prin depozit',
    (s1.data?.pieces || 0) >= 10, JSON.stringify(s1.data));
  check('stocul ajunge la −10', await stoc(articol) === -10, 'stoc = ' + await stoc(articol));

  // Clientul sună: aceeași comandă, 40 de bucăți în loc de 10.
  const p = await patch(admin, `/orders/${o.id}`, {
    items: [{ productId: produs, qty: 40, variant: 'copt' }],
  });
  check('corectura cantității reușește', p.status === 200, `${p.status} ${JSON.stringify(p.data)}`);
  check('comanda redevine „de scăzut" (stockSynced = false)',
    p.data?.order?.stockSynced === false, 'stockSynced = ' + p.data?.order?.stockSynced);

  const mv = await miscari(articol);
  check('cele 10 bucăți scăzute s-au întors în depozit',
    intrari(mv).length === 1 && intrari(mv)[0].qty === 10,
    JSON.stringify(intrari(mv).map((m) => ({ qty: m.qty, reason: m.reason }))));
  check('returul spune în registru de ce a apărut',
    /[Rr]etur/.test(intrari(mv)[0]?.reason || ''), JSON.stringify(intrari(mv)[0]?.reason));
  check('registrul revine la zero între cele două scăderi',
    await stoc(articol) === 0, 'stoc = ' + await stoc(articol));

  // O a doua salvare, pe comanda deja dezlegată, nu are ce mai întoarce:
  // altfel fiecare apăsare pe „Salvează" ar umfla depozitul cu marfă
  // inexistentă.
  await patch(admin, `/orders/${o.id}`, { items: [{ productId: produs, qty: 40, variant: 'copt' }] });
  check('o a doua salvare nu mai întoarce încă o dată marfa',
    intrari(await miscari(articol)).length === 1,
    JSON.stringify(intrari(await miscari(articol)).map((m) => m.qty)));

  const s2 = await post(admin, '/stock/sync');
  check('a doua scădere trece 40 de bucăți prin depozit',
    (s2.data?.pieces || 0) >= 40, JSON.stringify(s2.data));
  check('stocul ajunge la −40, cât e comanda',
    await stoc(articol) === -40, 'stoc = ' + await stoc(articol));
}

/* ====== 2. comanda anulată își întoarce marfa în rafturi =============== */

console.log('\n=== PATCH /orders: anularea întoarce marfa în depozit ===');
{
  const { produs, articol } = await perecheProdusArticol('anulare');
  const o = await comanda('Probă Zăvor Anulare ' + MARCA, produs, 7);

  await patch(admin, `/orders/${o.id}`, { status: PREDATA });
  await post(admin, '/stock/sync');
  check('comanda predată e scăzută din depozit', await stoc(articol) === -7,
    'stoc = ' + await stoc(articol));

  const p = await patch(admin, `/orders/${o.id}`, { status: ANULATA });
  check('anularea reușește', p.status === 200, `${p.status} ${JSON.stringify(p.data)}`);
  check('comanda anulată nu mai e marcată ca scăzută',
    p.data?.order?.stockSynced === false, 'stockSynced = ' + p.data?.order?.stockSynced);

  const mv = await miscari(articol);
  check('anularea scrie o intrare de retur în registru',
    intrari(mv).length === 1 && intrari(mv)[0].qty === 7,
    JSON.stringify(intrari(mv).map((m) => ({ qty: m.qty, reason: m.reason }))));
  check('returul poartă numele comenzii, ca perechea lui de ieșire',
    (intrari(mv)[0]?.note || '').includes(o.ref), JSON.stringify(intrari(mv)[0]?.note));
  check('stocul se întoarce la 0 după anulare', await stoc(articol) === 0,
    'stoc = ' + await stoc(articol));

  // Comanda anulată nu mai e „încheiată", deci scăderea nu o mai atinge: marfa
  // întoarsă nu are cum să iasă din nou pe ușă fără ca cineva să ceară asta.
  const s = await post(admin, '/stock/sync');
  check('scăderea nu ia din nou comanda anulată',
    !(s.data?.skipped || []).includes(o.id) && await stoc(articol) === 0,
    JSON.stringify(s.data) + ' stoc = ' + await stoc(articol));

  // Anulată din greșeală: repusă pe „Predată", se scade a doua oară — o
  // singură dată, nu de două ori.
  const inapoi = await patch(admin, `/orders/${o.id}`, { status: PREDATA });
  check('comanda poate fi repusă pe etapa de încheiere', inapoi.status === 200,
    `${inapoi.status} ${JSON.stringify(inapoi.data)}`);
  await post(admin, '/stock/sync');
  check('repusă pe „predată", se scade din nou o singură dată',
    await stoc(articol) === -7, 'stoc = ' + await stoc(articol));
}

/* ====== 3. o corectură fără legătură nu clatină registrul ============== */

console.log('\n=== PATCH /orders: telefonul corectat nu mișcă depozitul ===');
{
  const { produs, articol } = await perecheProdusArticol('telefon');
  const o = await comanda('Probă Zăvor Telefon ' + MARCA, produs, 5);

  await patch(admin, `/orders/${o.id}`, { status: PREDATA });
  await post(admin, '/stock/sync');
  check('comanda e scăzută', await stoc(articol) === -5, 'stoc = ' + await stoc(articol));

  const p = await patch(admin, `/orders/${o.id}`, { phone: '0722 333 444', notes: 'sună la poartă' });
  check('corectura de telefon reușește', p.status === 200, `${p.status} ${JSON.stringify(p.data)}`);
  check('comanda rămâne scăzută, nu se redeschide degeaba',
    p.data?.order?.stockSynced === true, 'stockSynced = ' + p.data?.order?.stockSynced);
  check('nu s-a întors nimic în depozit', intrari(await miscari(articol)).length === 0,
    JSON.stringify(intrari(await miscari(articol)).map((m) => m.qty)));
  check('stocul rămâne la −5', await stoc(articol) === -5, 'stoc = ' + await stoc(articol));

  // Aceeași etapă trimisă încă o dată nu e o ieșire din ea.
  await patch(admin, `/orders/${o.id}`, { status: PREDATA, notes: 'a doua salvare' });
  check('retrimiterea aceleiași etape nu întoarce marfa',
    intrari(await miscari(articol)).length === 0 && await stoc(articol) === -5,
    'stoc = ' + await stoc(articol));
}

/* ====== 4. returul imposibil oprește salvarea, nu marcajul ============= */

console.log('\n=== PATCH /orders: poziția fără articol nu deschide zăvorul ===');
{
  // Articol de depozit nelegat de produs: potrivirea se face prin nume, exact
  // cum o susține join-ul din scăderea comenzilor.
  const NUME = 'Probă zăvor rupt ' + MARCA;
  const p = await post(admin, '/products', {
    name: NUME, nameEn: 'Broken link ' + MARCA,
    description: 'Produs de probă.', descriptionEn: 'Probe product.',
    category: GRUPA, grams: 100, priceFrozen: '2.00', priceBaked: '3.00',
  });
  if (p.status !== 201) throw new Error('nu pot crea produsul: ' + p.status);
  const a = await post(admin, '/stock/items', {
    name: NUME, category: 'Produse finite', unit: 'buc', minQty: 0,
  });
  if (a.status !== 201) throw new Error('nu pot crea articolul: ' + a.status);
  const articol = a.data.id;

  const o = await comanda('Probă Zăvor Rupt ' + MARCA, p.data.id, 10);
  await patch(admin, `/orders/${o.id}`, { status: PREDATA });
  await post(admin, '/stock/sync');
  check('comanda potrivită prin nume se scade din depozit',
    await stoc(articol) === -10, 'stoc = ' + await stoc(articol));

  // Depozitarul redenumește articolul. Potrivirea prin nume se rupe: comanda
  // nu mai are din ce să-și întoarcă marfa.
  const r = await patch(admin, `/stock/items/${articol}`, { name: NUME + ' (rafturi)' });
  check('articolul se poate redenumi din Depozit', r.status === 200, `${r.status}`);

  const c = await patch(admin, `/orders/${o.id}`, {
    items: [{ productId: p.data.id, qty: 40, variant: 'copt' }],
  });
  check('salvarea care nu poate întoarce marfa se oprește → 409', c.status === 409,
    `${c.status} ${JSON.stringify(c.data)}`);
  check('refuzul spune care poziție e de vină',
    (c.data?.error?.message || '').includes(NUME), JSON.stringify(c.data?.error));

  const dupa = (await get(admin, '/orders/' + o.id)).data?.order;
  check('comanda rămâne întreagă, cu cantitatea veche',
    dupa?.items?.[0]?.qty === 10, JSON.stringify(dupa?.items));
  check('marcajul rămâne pus, ca marfa să nu iasă a doua oară',
    dupa?.stockSynced === true, 'stockSynced = ' + dupa?.stockSynced);
  check('stocul nu s-a clintit', await stoc(articol) === -10,
    'stoc = ' + await stoc(articol));

  // Cu numele pus la loc, aceeași salvare trece și marfa se întoarce.
  await patch(admin, `/stock/items/${articol}`, { name: NUME });
  const c2 = await patch(admin, `/orders/${o.id}`, {
    items: [{ productId: p.data.id, qty: 40, variant: 'copt' }],
  });
  check('cu articolul la locul lui, salvarea trece → 200', c2.status === 200,
    `${c2.status} ${JSON.stringify(c2.data)}`);
  check('marfa s-a întors în depozit', await stoc(articol) === 0,
    'stoc = ' + await stoc(articol));

  // Cifra care prinde scăderea dublă: dacă marcajul ar fi căzut fără ca marfa
  // să se întoarcă, aici ar ieși −50 pentru o comandă de 40.
  await post(admin, '/stock/sync');
  check('a doua scădere lasă exact cât e comanda, nu de două ori',
    await stoc(articol) === -40, 'stoc = ' + await stoc(articol));
}

/* -------------------------------------------------------------- final -- */

console.log(`\n${pass} trecute, ${fail} picate`);
if (fail) {
  console.log('\nPicate:');
  for (const f of failures) console.log('  - ' + f);
  process.exit(1);
}
process.exit(0);
