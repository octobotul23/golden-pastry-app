// Proba pentru defectul „produsul scos din catalog blochează comenzile și
// cererile care îl conțin".
//
//   node .claude/test-defect-produs-scos.mjs
//
// `price_items()` căuta produsul cu `WHERE id = ? AND active = 1`, iar toți cei
// patru chemători treceau prin ea. Consecințele, în patiserie:
//
// 1. O comandă care conținea produsul scos nu se mai putea corecta deloc. PATCH
//    /orders rescrie TOATE pozițiile și le retarifează, deci până și mărirea
//    cantității la celălalt produs de pe ea pica cu „Produs inexistent sau
//    inactiv". Operatorul nu avea decât să anuleze comanda și să o scrie din nou.
// 2. O cerere trimisă ÎNAINTE ca produsul să fie scos nu se mai putea accepta
//    niciodată: formularul de acceptare trimite întotdeauna pozițiile, deci
//    cererea rămânea pe „nou" la nesfârșit, iar clientul nu afla nimic.
//
// Reparația: `price_items()` primește lista produselor îngăduite chiar scoase
// din catalog — cele deja aflate pe comanda editată, respectiv pe cererea
// acceptată. Pe drumurile pe care se naște ceva nou (POST /orders de la telefon,
// POST /requests de pe site) lista rămâne goală, deci un produs scos din catalog
// nu se mai poate vinde.
//
// Probele care pică dacă reparația e dată înapoi (verificat, stricând-o la loc):
//   · „cantitatea celuilalt produs se poate corecta" — 422 bad_product în loc de 200;
//   · „cererea veche se poate accepta, cu pozițiile trimise de panou" — 422 în loc de 201;
//   · și, pe lângă ele, toate verificările care citesc comanda salvată după aceea.
//
// Probele care pică dacă cineva „repară" ștergând pur și simplu `active = 1`:
//   · „produsul scos nu se poate pune pe o comandă nouă";
//   · „produsul scos nu se poate pune pe o cerere nouă de pe site";
//   · „un produs scos care NU era pe comandă nu se poate adăuga la ea".

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

/**
 * Ziua de livrare, la București.
 *
 * Acceptarea unei cereri refuză o zi trecută (`day_in_past`), iar ziua se
 * judecă acolo după ceasul românesc, nu după UTC. O zi scrisă în cod ar fi
 * expirat, deci o calculăm de la ceas — patru zile în față, ca proba să nu
 * atârne de ora la care rulează.
 */
const ZI = (() => {
  const azi = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Bucharest' }).format(new Date());
  const d = new Date(azi + 'T12:00:00Z');
  d.setUTCDate(d.getUTCDate() + 4);
  return d.toISOString().slice(0, 10);
})();

const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica ca admin: ' + r.status); process.exit(1); }
}

// Eticheta ține produsele acestei rulări separate de ale celei dinainte.
// Numele e cel pe care îl strânge `curata-probe`: un produs ajuns pe o comandă
// nu se mai poate șterge prin API, deci ar rămâne pentru totdeauna în Catalog.
const TAG = Math.random().toString(36).slice(2, 7);
const GRUPA = (await get(admin, '/products')).data?.products?.[0]?.category;
if (!GRUPA) { console.log('catalogul de probă e gol; nu am pe ce lucra'); process.exit(1); }

/** Un produs nou în catalog. Amândouă prețurile sunt obligatorii. */
async function produs(sufix) {
  const nume = 'Ștrudel de probă ' + TAG + sufix;
  const r = await post(admin, '/products', {
    name: nume, nameEn: 'Test strudel ' + TAG + sufix,
    description: 'Produs de probă.', descriptionEn: 'Test product.',
    category: GRUPA, grams: 100, priceFrozen: 10, priceBaked: 15,
  });
  if (r.status !== 201) throw new Error('nu pot crea produsul de probă: ' + r.status + ' ' + JSON.stringify(r.data));
  return { id: r.data.id, nume };
}

/* ---------------------------------------------------------- pregătire -- */

// A pleacă din catalog în timpul probei; B rămâne, ca să avem ce corecta pe
// aceeași comandă. D e al treilea produs scos, ca să verificăm că îngăduința
// e legată de hârtia care se editează, nu de „inactiv" în general.
const A = await produs('a');
const B = await produs('b');
const D = await produs('d');

// Comanda de dinainte de scoatere: exact cazul din patiserie.
const com = await post(admin, '/orders', {
  customerName: 'Test catalog', phone: '0700 000 222', address: 'Str. Probei 1',
  day: ZI, fulfillment: 'Livrare', channel: 'panou',
  items: [
    { productId: A.id, qty: 2, variant: 'copt' },
    { productId: B.id, qty: 3, variant: 'copt' },
  ],
});
if (com.status !== 201) { console.log('nu pot crea comanda de probă: ' + com.status + ' ' + JSON.stringify(com.data)); process.exit(1); }
const COM = com.data.order;

// Cererea trimisă de pe site, tot înainte de scoatere.
const cer = await post(agent(), '/requests', {
  customerName: 'Client Anonim', phone: '0700 000 333', address: 'Str. Probei 2',
  day: ZI, items: [{ productId: A.id, qty: 4, variant: 'copt' }],
});
if (cer.status !== 201) { console.log('nu pot crea cererea de probă: ' + cer.status + ' ' + JSON.stringify(cer.data)); process.exit(1); }
const CER = cer.data.request;

// D ajunge pe o comandă separată doar ca să nu poată fi șters de tot: un produs
// care n-a fost comandat niciodată dispare din tabel, iar noi avem nevoie de el
// scos din catalog, nu inexistent.
await post(admin, '/orders', {
  customerName: 'Test catalog', day: ZI, fulfillment: 'Ridicare', channel: 'panou',
  items: [{ productId: D.id, qty: 1, variant: 'copt' }],
});

/* =============== 0. produsul comandat se scoate, nu se șterge ============ */

console.log('\n=== DELETE /products: cel comandat se scoate din catalog, nu se șterge ===');
{
  const r = await del(admin, '/products/' + A.id);
  check('produsul comandat se scoate din catalog → 200', r.status === 200, `${r.status} ${JSON.stringify(r.data)}`);
  // Miezul: rândul rămâne în tabel. Pe el se sprijină toată reparația — dacă
  // produsul ar dispărea, pozițiile vechi n-ar mai avea de unde fi tarifate.
  check('rândul rămâne în baza de date (nu „deleted")', r.data?.deleted === false, JSON.stringify(r.data));

  await del(admin, '/products/' + D.id);

  const pub = await get(agent(), '/products');
  check('nu mai apare în catalogul public', !(pub.data?.products || []).some((p) => p.id === A.id));

  const adm = await get(admin, '/products?all=1');
  check('administratorul îl vede în continuare, ca inactiv',
    (adm.data?.products || []).some((p) => p.id === A.id && p.active === false));
}

/* ====== 1. comanda cu produsul scos rămâne lucrabilă (PATCH /orders) ===== */

console.log('\n=== PATCH /orders: comanda cu produsul scos se poate în continuare corecta ===');
{
  // Clientul sună și cere încă două bucăți din CELĂLALT produs. Panoul trimite
  // toate pozițiile, cu tot cu cea scoasă din catalog — asta bloca salvarea.
  const r = await patch(admin, `/orders/${COM.id}`, {
    items: [
      { productId: A.id, qty: 2, variant: 'copt' },
      { productId: B.id, qty: 5, variant: 'copt' },
    ],
  });
  check('cantitatea celuilalt produs se poate corecta', r.status === 200,
    `${r.status} ${JSON.stringify(r.data)}`);

  const o = (await get(admin, `/orders/${COM.id}`)).data?.order;
  const linii = o?.items || [];
  check('comanda are în continuare amândouă pozițiile', linii.length === 2, JSON.stringify(linii));
  const scos = linii.find((i) => i.productId === A.id);
  const ramas = linii.find((i) => i.productId === B.id);
  check('poziția cu produsul scos și-a păstrat denumirea', scos?.name === A.nume, JSON.stringify(scos));
  check('și prețul lui din catalog', scos?.unitPrice === 15, JSON.stringify(scos));
  check('corectura chiar s-a scris', ramas?.qty === 5, JSON.stringify(ramas));
  check('totalul e recalculat din catalog: 2 × 15 + 5 × 15', o?.total === 105, `total = ${o?.total}`);

  // Și cantitatea produsului scos se poate corecta: marfa se face azi, iar
  // clientul poate cere mai puțină, chiar dacă produsul nu se mai vinde altora.
  const r2 = await patch(admin, `/orders/${COM.id}`, {
    items: [
      { productId: A.id, qty: 1, variant: 'copt' },
      { productId: B.id, qty: 5, variant: 'copt' },
    ],
  });
  check('și cantitatea produsului scos se poate corecta', r2.status === 200,
    `${r2.status} ${JSON.stringify(r2.data)}`);
  check('totalul urmează corectura: 1 × 15 + 5 × 15', r2.data?.order?.total === 90,
    `total = ${r2.data?.order?.total}`);

  // Îngăduința ține de hârtia asta, nu de „inactiv" în general: un ALT produs
  // scos din catalog nu are ce căuta pe ea.
  const r3 = await patch(admin, `/orders/${COM.id}`, {
    items: [
      { productId: A.id, qty: 1, variant: 'copt' },
      { productId: D.id, qty: 1, variant: 'copt' },
    ],
  });
  check('un produs scos care NU era pe comandă nu se poate adăuga la ea', r3.status === 422,
    `${r3.status} ${JSON.stringify(r3.data)}`);
  check('refuzul spune care produs și ce e de făcut',
    /scos din catalog/.test(r3.data?.error?.message || '') && /Produse/.test(r3.data?.error?.message || ''),
    JSON.stringify(r3.data?.error));

  const dupa = (await get(admin, `/orders/${COM.id}`)).data?.order;
  check('comanda rămâne neatinsă după refuz', dupa?.total === 90, `total = ${dupa?.total}`);
}

/* ========= 2. cererea de dinainte de scoatere se poate accepta =========== */

console.log('\n=== POST /requests/{id}/accept: cererea veche nu mai rămâne blocată ===');
{
  // Formularul de acceptare trimite ÎNTOTDEAUNA pozițiile (Administrare
  // Comenzi → save(), ramura `fromReq`), deci aici se vedea defectul.
  const r = await post(admin, `/requests/${CER.id}/accept`, {
    day: ZI, fulfillment: 'Livrare',
    customerName: 'Client Anonim',
    items: [{ productId: A.id, qty: 4, variant: 'copt' }],
  });
  check('cererea veche se poate accepta, cu pozițiile trimise de panou', r.status === 201,
    `${r.status} ${JSON.stringify(r.data)}`);
  check('comanda născută din ea poartă produsul cerut',
    (r.data?.order?.items || []).some((i) => i.productId === A.id && i.qty === 4),
    JSON.stringify(r.data?.order?.items));
  check('totalul comenzii e cel din catalog: 4 × 15', r.data?.order?.total === 60,
    `total = ${r.data?.order?.total}`);

  const lista = (await get(admin, '/requests?status=acceptata')).data?.requests || [];
  check('cererea nu mai stă pe „nou"', lista.some((c) => c.id === CER.id),
    'cererea ' + CER.id + ' nu apare între cele acceptate');
}

console.log('\n=== acceptarea nu devine o portiță pentru produse scoase ===');
{
  // O a doua cerere, făcută cât timp B era încă în catalog, ca să avem ce
  // accepta. La acceptare se încearcă adăugarea produsului scos D — ceva ce
  // clientul nu ceruse. Aici catalogul de azi trebuie să se impună.
  const c2 = await post(agent(), '/requests', {
    customerName: 'Client Anonim', phone: '0700 000 444',
    day: ZI, items: [{ productId: B.id, qty: 1, variant: 'copt' }],
  });
  check('cererea de control se trimite', c2.status === 201, `${c2.status} ${JSON.stringify(c2.data)}`);

  const r = await post(admin, `/requests/${c2.data?.request?.id}/accept`, {
    day: ZI, fulfillment: 'Livrare', customerName: 'Client Anonim',
    items: [
      { productId: B.id, qty: 1, variant: 'copt' },
      { productId: D.id, qty: 1, variant: 'copt' },
    ],
  });
  check('un produs scos, adăugat la acceptare peste ce a cerut clientul → 422',
    r.status === 422, `${r.status} ${JSON.stringify(r.data)}`);

  const inca = (await get(admin, '/requests?status=nou')).data?.requests || [];
  check('cererea rămâne în așteptare după refuz, nu se pierde',
    inca.some((c) => c.id === c2.data?.request?.id));

  const ok = await post(admin, `/requests/${c2.data?.request?.id}/accept`, {
    day: ZI, fulfillment: 'Livrare', customerName: 'Client Anonim',
    items: [{ productId: B.id, qty: 1, variant: 'copt' }],
  });
  check('fără el, aceeași cerere se acceptă', ok.status === 201, `${ok.status} ${JSON.stringify(ok.data)}`);
}

/* ============ 3. pe ceva NOU produsul scos rămâne refuzat =============== */

console.log('\n=== produsul scos din catalog nu se mai vinde ===');
{
  const r = await post(admin, '/orders', {
    customerName: 'Test catalog', phone: '0700 000 555',
    day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: A.id, qty: 1, variant: 'copt' }],
  });
  check('produsul scos nu se poate pune pe o comandă nouă', r.status === 422,
    `${r.status} ${JSON.stringify(r.data)}`);
  check('refuzul îl numește pe nume', (r.data?.error?.message || '').includes(A.nume),
    JSON.stringify(r.data?.error));

  const c = await post(agent(), '/requests', {
    customerName: 'Client Anonim', phone: '0700 000 666',
    day: ZI, items: [{ productId: A.id, qty: 1, variant: 'copt' }],
  });
  check('produsul scos nu se poate pune pe o cerere nouă de pe site', c.status === 422,
    `${c.status} ${JSON.stringify(c.data)}`);
  check('codul refuzului rămâne „bad_product"', c.data?.error?.code === 'bad_product',
    JSON.stringify(c.data?.error));
}

/* ========== 4. produsul chiar inexistent rămâne refuzat la fel ========== */

console.log('\n=== produsul care nu există deloc ===');
{
  // Produsul niciodată comandat se șterge de tot din tabel (DELETE /products).
  // De la el încolo nu mai e nimic de tarifat, deci refuzul rămâne — dar cu alt
  // mesaj decât „scos din catalog", ca administratorul să știe ce caută.
  const E = await produs('e');
  const d = await del(admin, '/products/' + E.id);
  check('produsul necomandat se șterge de tot', d.data?.deleted === true, JSON.stringify(d.data));

  const r = await post(admin, '/orders', {
    customerName: 'Test catalog', day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: E.id, qty: 1, variant: 'copt' }],
  });
  check('un produs șters de tot → 422', r.status === 422, `${r.status} ${JSON.stringify(r.data)}`);
  check('mesajul spune „inexistent", nu „scos din catalog"',
    /inexistent/.test(r.data?.error?.message || '') && !/scos din catalog/.test(r.data?.error?.message || ''),
    JSON.stringify(r.data?.error));

  const r2 = await post(admin, '/orders', {
    customerName: 'Test catalog', day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: 99999999, qty: 1, variant: 'copt' }],
  });
  check('un id care n-a existat niciodată → 422', r2.status === 422, `${r2.status} ${JSON.stringify(r2.data)}`);

  // Nici pe o comandă veche nu se poate strecura un produs șters: comanda
  // îngăduie doar ce era deja scris pe ea.
  const r3 = await patch(admin, `/orders/${COM.id}`, {
    items: [{ productId: E.id, qty: 1, variant: 'copt' }],
  });
  check('nici pe comanda veche nu intră un produs șters', r3.status === 422,
    `${r3.status} ${JSON.stringify(r3.data)}`);
}

/* ========== 5. repus în catalog, produsul se comportă ca oricare ========= */

console.log('\n=== produsul repus în catalog se poate vinde din nou ===');
{
  const back = await patch(admin, '/products/' + A.id, { active: true });
  check('produsul se repune în catalog', back.status === 200, `${back.status} ${JSON.stringify(back.data)}`);

  const r = await post(admin, '/orders', {
    customerName: 'Test catalog', day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: A.id, qty: 1, variant: 'copt' }],
  });
  check('și se poate comanda din nou', r.status === 201, `${r.status} ${JSON.stringify(r.data)}`);
}

/* -------------------------------------------------------------- final -- */

// Produsele de probă rămân scoase din catalog, cu numele pe care îl strânge
// `curata-probe`: un produs ajuns pe o comandă nu se mai poate șterge prin API.
for (const p of [A, B, D]) {
  await del(admin, '/products/' + p.id);
}

console.log(`\n${pass} trecute, ${fail} picate`);
if (fail) {
  console.log('\nPicate:');
  for (const f of failures) console.log('  - ' + f);
  process.exit(1);
}
process.exit(0);

