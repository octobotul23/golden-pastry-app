// Proba pentru retarifarea tăcută — acceptarea cererii și salvarea comenzii.
//
//   node .claude/test-reparat-retarifare.mjs
//
// 1. POST /requests/{id}/accept chema price_items() ori de câte ori panoul
//    trimitea `items` — și „Administrare Comenzi" îi trimite ÎNTOTDEAUNA. O
//    scumpire pusă în catalog între trimiterea cererii de către client și
//    apăsarea butonului „Acceptă" rescria tăcut comanda: clientul văzuse 285
//    de lei, comanda se năștea cu 785, iar diferența se descoperea la factură.
// 2. PATCH /orders/{id} rescria pozițiile întregi și le retarifa pe TOATE din
//    catalog. O singură bucată adăugată luni pe comanda de vineri retarifa și
//    bucățile convenite atunci.
//
// Reparația: prețul văzut de client se onorează. O poziție care exista deja
// (același produs, aceeași variantă) își păstrează prețul unitar de atunci,
// chiar dacă i se schimbă cantitatea; doar o poziție nouă ia prețul de azi.
// Diferența față de catalog se scrie în jurnal, ca administratorul să nu afle
// abia din încasări că a vândut sub prețul curent.
//
// Dată înapoi reparația, pică pe rând: „comanda păstrează prețul văzut de
// client", „poziția veche își ține prețul la schimbarea cantității",
// „jurnalul spune că s-a onorat alt preț decât cel din catalog".

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
const del = (a, p) => call(a, 'DELETE', p);

/** Banii comparați ca bani, nu ca numere în virgulă mobilă. */
const bani = (a, b) => Math.abs(Number(a) - Number(b)) < 0.005;

/** Prețul unitar al unei poziții din comandă, după produs și variantă. */
const unitarul = (o, pid, variant, laPretul = null) =>
  ((o?.items || []).find((i) => i.productId === pid && i.variant === variant
    && (laPretul === null || Math.abs(Number(i.unitPrice) - laPretul) < 0.005)) || {}).unitPrice;

/* ---------------------------------------------------------- pregătire -- */

const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica ca admin: ' + r.status); process.exit(1); }
}

// Grupa se ia din catalog, nu se scrie aici: `product_category()` refuză o
// grupă care nu există, iar lista e editabilă din panou.
const catalog = await get(admin, '/products?all=1');
const GRUPA = (catalog.data?.categories || [])[0]?.slug || '';
if (GRUPA === '') { console.log('catalogul nu are nicio grupă în care să pun produsele de probă'); process.exit(1); }

// Ziua se socotește de la ceasul de azi: acceptarea refuză, pe bună dreptate,
// o zi trecută, iar o dată scrisă în cod ar face proba să pice singură peste
// un an.
const ZI = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
const CLIENT = 'Proba Retarifare';

// Slugul poartă prefixul pe care îl strânge `curata-probe`: produsele intră în
// comenzi, deci ștergerea lor le lasă doar scoase din catalog, iar fila
// Produse s-ar umple de la o rulare la alta.
const marca = Math.random().toString(36).slice(2, 7);
async function produs(eticheta, congelat, copt) {
  const r = await post(admin, '/products', {
    slug: 'strudel-de-proba-retarif-' + eticheta + '-' + marca,
    name: 'Ștrudel de probă retarifare ' + eticheta + ' ' + marca,
    nameEn: 'Test repricing strudel ' + eticheta + ' ' + marca,
    description: 'Produs de probă. Se șterge după rulare.',
    descriptionEn: 'Test product. Removed after the run.',
    category: GRUPA, grams: 100, priceFrozen: congelat, priceBaked: copt,
  });
  if (r.status !== 201) throw new Error('nu pot crea produsul de probă: ' + r.status + ' ' + JSON.stringify(r.data));
  return Number(r.data.id);
}
const pretul = (id, congelat, copt) =>
  patch(admin, `/products/${id}`, { priceFrozen: congelat, priceBaked: copt });

// A e produsul care se scumpește sub mâna clientului; B e cel adăugat mai
// târziu de administrator, care trebuie să ia prețul de azi.
const A = await produs('a', 285, 285);
const B = await produs('b', 10, 10);
const deSters = [A, B];

/** Ce scrie jurnalul despre o faptă anume. */
async function jurnal(action, entityId) {
  const r = await get(admin, `/log?action=${action}`);
  const e = (r.data?.entries || []).find((x) => String(x.entityId) === String(entityId));
  return e?.detail || '';
}

/* ====== 1. acceptarea onorează prețul pe care l-a văzut clientul ======== */

console.log('\n=== POST /requests/{id}/accept: prețul văzut de client se onorează ===');
let comandaId = null;
{
  // Clientul trimite cererea de pe site, fără cont, la prețul afișat atunci.
  const cerere = await post(agent(), '/requests', {
    customerName: CLIENT, phone: '0700 111 222', address: 'Str. Probei 1',
    day: ZI, variant: 'copt', items: [{ productId: A, qty: 1, variant: 'copt' }],
  });
  check('cererea clientului se înregistrează → 201', cerere.status === 201,
    `${cerere.status} ${JSON.stringify(cerere.data)}`);
  check('cererea poartă prețul văzut pe ecran', bani(cerere.data?.request?.total, 285),
    `total = ${cerere.data?.request?.total}`);
  const cererea = Number(cerere.data?.request?.id);

  // Între trimiterea cererii și butonul „Acceptă" se schimbă catalogul.
  const scump = await pretul(A, 785, 785);
  check('prețul din catalog se schimbă între timp → 200', scump.status === 200,
    `${scump.status} ${JSON.stringify(scump.data)}`);

  // Exact ce trimite „Administrare Comenzi": antetul ȘI pozițiile, la fiecare
  // acceptare, chiar dacă administratorul nu a atins nimic.
  const acc = await post(admin, `/requests/${cererea}/accept`, {
    customerName: CLIENT, phone: '0700 111 222', address: 'Str. Probei 1',
    day: ZI, slot: '', zone: '', fulfillment: 'Livrare', notes: '',
    items: [{ productId: A, qty: 1, variant: 'copt' }],
  });
  check('cererea devine comandă → 201', acc.status === 201, `${acc.status} ${JSON.stringify(acc.data)}`);
  const o = acc.data?.order;
  comandaId = o?.id;

  // Aici pica înainte: 785 în loc de 285.
  check('comanda păstrează prețul văzut de client', bani(o?.total, 285),
    `total = ${o?.total}, așteptam 285 (prețul de la trimiterea cererii)`);
  check('poziția păstrează prețul unitar de atunci', bani(unitarul(o, A, 'copt'), 285),
    `unitPrice = ${unitarul(o, A, 'copt')}`);

  // Prețul se onorează, dar tăcerea nu se onorează: administratorul trebuie să
  // afle că a livrat sub prețul de azi, iar pagina Jurnal e locul unde află.
  const nota = await jurnal('request_accept', cererea);
  check('jurnalul spune că s-a onorat alt preț decât cel din catalog',
    /onorat/.test(nota) && /285/.test(nota) && /785/.test(nota), `detaliu = „${nota}”`);
}

/* ====== 2. cantitatea schimbată nu retarifează poziția veche =========== */

console.log('\n=== PATCH /orders/{id}: poziția care exista își ține prețul ===');
{
  // Prețul crește din nou după ce comanda e deja confirmată.
  await pretul(A, 999, 999);

  const r = await patch(admin, `/orders/${comandaId}`, {
    items: [{ productId: A, qty: 3, variant: 'copt' }],
  });
  check('salvarea cu altă cantitate reușește → 200', r.status === 200, `${r.status} ${JSON.stringify(r.data)}`);
  // Bucata promisă rămâne la 285 și iese ca poziție separată; cele două
  // adăugate acum poartă prețul de azi. Două prețuri, două rânduri: pe factură
  // înmulțirea trebuie să dea totalul, iar un preț mediu inventat n-ar da.
  check('bucata promisă își ține prețul la schimbarea cantității',
    bani(unitarul(r.data?.order, A, 'copt', 285), 285),
    `poziții = ${JSON.stringify(r.data?.order?.items)}`);
  check('bucățile adăugate peste cele promise se tarifează la zi',
    bani(unitarul(r.data?.order, A, 'copt', 999), 999),
    `poziții = ${JSON.stringify(r.data?.order?.items)}`);
  check('totalul e promisul la prețul promis plus adăugatul la prețul de azi',
    bani(r.data?.order?.total, 285 + 2 * 999),
    `total = ${r.data?.order?.total}, așteptam 285 + 2 × 999`);

  const nota = await jurnal('order_update', String(comandaId));
  check('jurnalul notează și la salvare diferența față de catalog',
    /onorat/.test(nota) && /285/.test(nota) && /999/.test(nota), `detaliu = „${nota}”`);
}

console.log('\n=== PATCH /orders/{id}: poziția nouă ia prețul de azi ===');
{
  const r = await patch(admin, `/orders/${comandaId}`, {
    items: [
      { productId: A, qty: 3, variant: 'copt' },
      { productId: B, qty: 2, variant: 'copt' },
    ],
  });
  check('bucata adăugată acum se salvează → 200', r.status === 200, `${r.status} ${JSON.stringify(r.data)}`);
  check('bucata adăugată acum se tarifează din catalog',
    bani(unitarul(r.data?.order, B, 'copt'), 10), `unitPrice = ${unitarul(r.data?.order, B, 'copt')}`);
  check('bucata confirmată demult nu se retarifează',
    bani(unitarul(r.data?.order, A, 'copt', 285), 285),
    `poziții = ${JSON.stringify(r.data?.order?.items)}`);
  check('totalul le adună pe toate așa cum sunt',
    bani(r.data?.order?.total, 285 + 2 * 999 + 2 * 10),
    `total = ${r.data?.order?.total}, așteptam 285 + 2 × 999 + 2 × 10`);
}

console.log('\n=== varianta schimbată e marfă nouă, nu aceeași ieftinită ===');
{
  // Congelat și copt sunt două prețuri diferite pe site: clientul care trece
  // de la unul la altul n-a văzut niciodată prețul celuilalt, deci nu are ce
  // i se onora. Fără verificarea asta, o reparație care ține minte prețul doar
  // pe produs ar livra congelatul la prețul coptului.
  await pretul(A, 40, 999);
  const r = await patch(admin, `/orders/${comandaId}`, {
    items: [
      { productId: A, qty: 3, variant: 'copt' },
      { productId: A, qty: 1, variant: 'congelat' },
    ],
  });
  check('varianta nouă se tarifează din catalog', bani(unitarul(r.data?.order, A, 'congelat'), 40),
    `unitPrice = ${unitarul(r.data?.order, A, 'congelat')}`);
  check('varianta veche rămâne la prețul ei', bani(unitarul(r.data?.order, A, 'copt'), 285),
    `unitPrice = ${unitarul(r.data?.order, A, 'copt')}`);
}

/* ====== 3. ce NU trebuie să se schimbe ================================= */

console.log('\n=== comanda nouă se tarifează în continuare din catalog ===');
{
  // Onorarea privește doar ce s-a promis deja. O comandă scrisă acum, la
  // telefon, trebuie să ia prețul de azi — altfel scumpirile n-ar intra
  // niciodată în vigoare.
  const r = await post(admin, '/orders', {
    customerName: CLIENT, phone: '0700 111 222', address: 'Str. Probei 1',
    day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: A, qty: 1, variant: 'copt' }],
  });
  check('comanda luată la telefon se creează → 201', r.status === 201,
    `${r.status} ${JSON.stringify(r.data)}`);
  check('și poartă prețul de azi, nu unul vechi', bani(r.data?.order?.total, 999),
    `total = ${r.data?.order?.total}, așteptam 999`);
  const nou = r.data?.order?.id;

  // Prețul tot nu se poate impune din browser: poziția nouă îl ia din catalog,
  // iar cea veche din ce s-a promis — niciodată din corpul cererii.
  const fals = await patch(admin, `/orders/${nou}`, {
    items: [
      { productId: A, qty: 1, variant: 'copt', unitPrice: 0.01 },
      { productId: B, qty: 1, variant: 'copt', unitPrice: 0.01 },
    ],
  });
  check('prețul trimis de browser e ignorat și pe poziția veche',
    bani(unitarul(fals.data?.order, A, 'copt'), 999), `unitPrice = ${unitarul(fals.data?.order, A, 'copt')}`);
  check('prețul trimis de browser e ignorat și pe poziția nouă',
    bani(unitarul(fals.data?.order, B, 'copt'), 10), `unitPrice = ${unitarul(fals.data?.order, B, 'copt')}`);

  // Fără schimbare de preț nu se murdărește jurnalul cu note care nu spun
  // nimic: administratorul trebuie să vadă numai abaterile adevărate.
  const nota = await jurnal('order_update', String(nou));
  check('jurnalul tace când nu s-a onorat nimic altfel', !/onorat/.test(nota), `detaliu = „${nota}”`);
}

console.log('\n=== acceptarea fără poziții rămâne cum era ===');
{
  // Cererea acceptată fără corectură nu trecea niciodată prin retarifare, și
  // nu trebuie să înceapă acum.
  await pretul(A, 40, 500);
  const cerere = await post(agent(), '/requests', {
    customerName: CLIENT, phone: '0700 111 222', address: 'Str. Probei 1',
    day: ZI, variant: 'copt', items: [{ productId: A, qty: 2, variant: 'copt' }],
  });
  const cererea = Number(cerere.data?.request?.id);
  await pretul(A, 40, 700);

  const acc = await post(admin, `/requests/${cererea}/accept`, {
    day: ZI, fulfillment: 'Livrare',
  });
  check('acceptarea fără poziții trece → 201', acc.status === 201,
    `${acc.status} ${JSON.stringify(acc.data)}`);
  check('comanda poartă totalul cererii', bani(acc.data?.order?.total, 1000),
    `total = ${acc.data?.order?.total}, așteptam 2 × 500`);
}

/* ====== 4. scenariul din fișa defectului, cu cifrele lui =============== */

console.log('\n=== 10 la 3,49 confirmate, a unsprezecea la 5,49 ===');
{
  // Exact cazul scris în docs/06-defecte-de-reparat.md: brutăria confirmă 10
  // ștrudele la 3,49 lei; peste noapte prețul urcă la 5,49; a doua zi sună și
  // mai cere unul. Cele zece rămân la prețul convenit, al unsprezecelea se
  // plătește cu prețul de azi: 10 × 3,49 + 5,49 = 40,39.
  await pretul(A, 3.49, 3.49);
  const c = await post(admin, '/orders', {
    customerName: CLIENT, phone: '0700 111 222', address: 'Str. Probei 1',
    day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: A, qty: 10, variant: 'copt' }],
  });
  check('comanda de 10 bucăți se creează → 201', c.status === 201, `${c.status}`);
  check('și poartă prețul de atunci', bani(c.data?.order?.total, 34.9),
    `total = ${c.data?.order?.total}`);

  const id = c.data?.order?.id;
  await pretul(A, 5.49, 5.49);

  const r = await patch(admin, `/orders/${id}`, {
    items: [{ productId: A, qty: 11, variant: 'copt' }],
  });
  check('a unsprezecea bucată se salvează → 200', r.status === 200, `${r.status}`);
  check('totalul e cel din fișa defectului: 10 × 3,49 + 5,49',
    bani(r.data?.order?.total, 40.39),
    `total = ${r.data?.order?.total}, poziții = ${JSON.stringify(r.data?.order?.items)}`);

  // Dus-întors: ce a întors serverul, retrimis identic, nu are voie să miște
  // nimic. Altfel o redeschidere și o resalvare a formularului ar retarifa în
  // tăcere, iar totalul s-ar plimba de la sine la fiecare atingere.
  const inapoi = await patch(admin, `/orders/${id}`, {
    items: (r.data?.order?.items || []).map((i) => ({
      productId: i.productId, qty: i.qty, variant: i.variant,
    })),
  });
  check('resalvarea acelorași poziții nu schimbă totalul',
    bani(inapoi.data?.order?.total, 40.39),
    `total = ${inapoi.data?.order?.total}`);

  const aTreia = await patch(admin, `/orders/${id}`, {
    items: (inapoi.data?.order?.items || []).map((i) => ({
      productId: i.productId, qty: i.qty, variant: i.variant,
    })),
  });
  check('și nici a treia oară', bani(aTreia.data?.order?.total, 40.39),
    `total = ${aTreia.data?.order?.total}`);
}

/* -------------------------------------------------------------- final -- */

// Produsele de probă intră în comenzi, deci ștergerea le lasă doar scoase din
// catalog — de acolo le ia `curata-probe`, împreună cu comenzile rămase goale.
for (const id of deSters) {
  await del(admin, `/products/${id}`);
}

console.log(`\n${pass} trecute, ${fail} picate`);
if (fail) {
  console.log('\nPicate:');
  for (const f of failures) console.log('  - ' + f);
  process.exit(1);
}
process.exit(0);

