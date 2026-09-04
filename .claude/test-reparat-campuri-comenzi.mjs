// Probele pentru grupa „câmpurile comenzii”, adică cele trei fișe în care
// PATCH /orders scria în bază ce primea, fără să se uite:
//
//   · zona nu se compara cu lista din Setări, nici ca lungime — o zonă care nu
//     există intra în comandă și ajungea pe ruta șoferului, unde harta o punea
//     peste depozit;
//   · mențiunile nu treceau prin `text_field()` — aceeași comandă era refuzată
//     la 2001 de caractere când o scriai de la zero, dar trecea corectată, iar
//     un text lipit dintr-un fir de e-mail dădea „Eroare internă";
//   · `price_items()` storcea numerele cu `(int)` și `(float)`, iar în PHP
//     `(int)` pe un tablou nevid dă 1: `productId: [22, 21]` devenea tăcut
//     produsul cu id 1, la alt preț, cu 201 „comandă primită" la client.
//
//   php -S 127.0.0.1:8100 -t . .claude/dev-server.php
//   node .claude/test-reparat-campuri-comenzi.mjs
//
// Fiecare verificare de aici a fost măsurată dând reparația înapoi.

const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';

let pass = 0, fail = 0;
const failures = [];
const check = (name, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
};
const opreste = (mesaj) => { console.error('\nProba nu poate continua: ' + mesaj); process.exit(2); };

const agent = () => ({ cookies: new Map(), csrf: '' });

async function call(a, method, path, body) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (a.csrf && method !== 'GET') headers['X-CSRF-Token'] = a.csrf;
  if (a.cookies.size) headers['Cookie'] = [...a.cookies].map(([k, v]) => `${k}=${v}`).join('; ');

  const res = await fetch(BASE + path, {
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
    catch { throw new Error('răspuns care nu e JSON la ' + method + ' ' + path + ': ' + raw.slice(0, 200).replace(/\s+/g, ' ')); }
  }
  return { status: res.status, data };
}
const get = (a, p) => call(a, 'GET', p);
const post = (a, p, b) => call(a, 'POST', p, b ?? {});
const patch = (a, p, b) => call(a, 'PATCH', p, b ?? {});

const cod = (r) => r.data?.error?.code || '';
const scurt = (r) => r.status + ' ' + JSON.stringify(r.data?.error?.code || r.data?.error?.message || '');

/* ------------------------------------------------------------- pregătire -- */

const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  if (r.status !== 200) opreste('nu pot intra ca administrator: ' + scurt(r));
  admin.csrf = r.data?.csrf || '';
  // Contul de probă poate avea o parolă provizorie, care blochează restul
  // rutelor până la schimbare.
  if (r.data?.user?.mustChangePassword) {
    await post(admin, '/auth/password', { current: 'Parola-Test-1234', next: 'Parola-Test-1234' });
  }
}

const LISTS = await (await fetch(BASE + '/lists')).json();
const ZONE = (LISTS.zones || [])[0] || '';
const ZONE2 = (LISTS.zones || [])[1] || ZONE;
const ZONA_INVENTATA = 'Cartierul Care Nu Există 4471';
check('lista de zone din Setări chiar are zone', !!ZONE, JSON.stringify(LISTS.zones));
check('zona inventată nu e, într-adevăr, în listă',
  !(LISTS.zones || []).includes(ZONA_INVENTATA));

const produse = ((await get(admin, '/products')).data?.products || []).filter((p) => p.active !== false);
if (produse.length < 2) opreste('am nevoie de cel puțin două produse active în catalog');
const P1 = produse[0], P2 = produse[1];

const CLIENT = 'Probă Câmpuri Comandă';
const comandaNoua = async (extra = {}) => {
  const r = await post(admin, '/orders', Object.assign({
    customerName: CLIENT, fulfillment: 'Livrare', zone: ZONE,
    items: [{ productId: P1.id, qty: 2, variant: 'copt' }],
  }, extra));
  if (r.status !== 201) opreste('nu pot face comanda de probă: ' + scurt(r));
  return r.data.order;
};
const citeste = async (id) => (await get(admin, '/orders/' + id)).data?.order;

/* ===== 1. zona la corectare ============================================== */

console.log('\n=== zona corectată se verifică, ca la creare ===');
{
  const o = await comandaNoua();

  const r = await patch(admin, '/orders/' + o.id, { zone: ZONA_INVENTATA });
  check('o zonă care nu e în Setări → 422 bad_zone',
    r.status === 422 && cod(r) === 'bad_zone', scurt(r));
  check('refuzul spune ce zone sunt permise',
    String(r.data?.error?.message || '').includes(ZONE),
    JSON.stringify(r.data?.error?.message));
  check('comanda rămâne cu zona ei bună',
    (await citeste(o.id))?.zone === ZONE, (await citeste(o.id))?.zone);

  // Aceeași zonă, la creare, era refuzată dinainte de reparație: rostul fișei
  // era tocmai că cele două uși nu erau păzite la fel.
  const laCreare = await post(admin, '/orders', {
    customerName: CLIENT, fulfillment: 'Livrare', zone: ZONA_INVENTATA,
    items: [{ productId: P1.id, qty: 1 }],
  });
  check('aceeași zonă e refuzată și la creare, ca înainte',
    laCreare.status === 422 && cod(laCreare) === 'bad_zone', scurt(laCreare));

  const lung = await patch(admin, '/orders/' + o.id, { zone: 'z'.repeat(100) });
  check('o zonă lipită greșit, de 100 de caractere → 422, nu „Eroare internă”',
    lung.status === 422 && cod(lung) === 'field_too_long', scurt(lung));

  const bun = await patch(admin, '/orders/' + o.id, { zone: ZONE2 });
  check('o zonă din listă trece → 200', bun.status === 200, scurt(bun));
  check('și chiar se salvează', (await citeste(o.id))?.zone === ZONE2,
    (await citeste(o.id))?.zone);

  const gol = await patch(admin, '/orders/' + o.id, { zone: '' });
  check('golirea zonei rămâne permisă → 200', gol.status === 200, scurt(gol));
  check('zona goală chiar se golește', (await citeste(o.id))?.zone == null,
    JSON.stringify((await citeste(o.id))?.zone));
}

/* ===== 2. mențiunile la corectare ======================================== */

console.log('\n=== mențiunile corectate trec prin aceeași măsură ca la creare ===');
{
  const o = await comandaNoua({ notes: 'notă de pornire' });

  const lung = await patch(admin, '/orders/' + o.id, { notes: 'x'.repeat(3000) });
  check('3000 de caractere → 422 field_too_long',
    lung.status === 422 && cod(lung) === 'field_too_long', scurt(lung));
  check('mențiunile vechi rămân neatinse după refuz',
    (await citeste(o.id))?.notes === 'notă de pornire',
    JSON.stringify((await citeste(o.id))?.notes));

  // Cazul din fișă: firul de e-mail lipit în casetă. Pică tot, dar cu un mesaj
  // despre mențiuni, nu cu „Eroare internă” și cu toată corectura pierdută.
  const enorm = await patch(admin, '/orders/' + o.id, { notes: 'z'.repeat(120000) });
  check('un fir de e-mail lipit întreg → 422, nu 500',
    enorm.status === 422 && cod(enorm) === 'field_too_long', scurt(enorm));

  const tablou = await patch(admin, '/orders/' + o.id, { notes: ['prima linie', 'a doua'] });
  check('un tablou trimis în locul textului nu mai ajunge „Array” în bază',
    tablou.status !== 200 || (await citeste(o.id))?.notes !== 'Array',
    JSON.stringify((await citeste(o.id))?.notes));

  const randuri = await patch(admin, '/orders/' + o.id, { notes: 'linia 1\r\nlinia 2\tcu tab' });
  check('mențiunile cu rânduri noi trec → 200', randuri.status === 200, scurt(randuri));
  const dupa = (await citeste(o.id))?.notes || '';
  check('caracterele de control se strâng la un spațiu, ca la creare',
    !/[\x00-\x1F\x7F]/.test(dupa) && dupa === 'linia 1 linia 2 cu tab',
    JSON.stringify(dupa));

  const laLimita = await patch(admin, '/orders/' + o.id, { notes: 'y'.repeat(2000) });
  check('exact 2000 de caractere încă trec → 200', laLimita.status === 200, scurt(laLimita));

  const gol = await patch(admin, '/orders/' + o.id, { notes: '' });
  check('golirea mențiunilor rămâne permisă', gol.status === 200
    && (await citeste(o.id))?.notes == null,
    scurt(gol) + ' · ' + JSON.stringify((await citeste(o.id))?.notes));
}

/* ===== 3. produsul și cantitatea se cer numere ========================== */

console.log('\n=== produsul și cantitatea nu se mai storc din ce-a venit ===');
{
  // Fișa, literă cu literă: două id-uri rămase într-un tablou după o modificare
  // a coșului. Înainte, serverul răspundea 201 și punea în comandă produsul cu
  // id 1 — alt produs, alt preț, cu confirmare trimisă clientului.
  const dublu = await post(admin, '/orders', {
    customerName: CLIENT, fulfillment: 'Ridicare',
    items: [{ productId: [P1.id, P2.id], qty: 5 }],
  });
  check('productId trimis ca tablou → 422 bad_product',
    dublu.status === 422 && cod(dublu) === 'bad_product', scurt(dublu));
  check('și nu s-a strecurat nicio comandă din el',
    !dublu.data?.order, JSON.stringify(dublu.data?.order?.items));

  const qtyTablou = await post(admin, '/orders', {
    customerName: CLIENT, fulfillment: 'Ridicare',
    items: [{ productId: P1.id, qty: [9] }],
  });
  check('cantitatea trimisă ca tablou → 422 bad_qty',
    qtyTablou.status === 422 && cod(qtyTablou) === 'bad_qty', scurt(qtyTablou));

  const qtyDa = await post(admin, '/orders', {
    customerName: CLIENT, fulfillment: 'Ridicare',
    items: [{ productId: P1.id, qty: true }],
  });
  check('cantitatea trimisă ca „da” → 422 bad_qty',
    qtyDa.status === 422 && cod(qtyDa) === 'bad_qty', scurt(qtyDa));

  const pidGol = await post(admin, '/orders', {
    customerName: CLIENT, fulfillment: 'Ridicare',
    items: [{ qty: 2 }],
  });
  check('poziția fără produs → 422 bad_product',
    pidGol.status === 422 && cod(pidGol) === 'bad_product', scurt(pidGol));

  // Compatibilitatea contează la fel de mult: câmpurile din formular sunt
  // <input>-uri, deci browserul trimite text. Refuzat, panoul n-ar mai salva
  // nimic.
  const text = await post(admin, '/orders', {
    customerName: CLIENT, fulfillment: 'Ridicare',
    items: [{ productId: String(P1.id), qty: '3' }],
  });
  check('produsul și cantitatea scrise ca text tot trec → 201',
    text.status === 201, scurt(text));
  check('și ajung produsul cerut, în cantitatea cerută',
    text.data?.order?.items?.[0]?.productId === P1.id
      && Number(text.data?.order?.items?.[0]?.qty) === 3,
    JSON.stringify(text.data?.order?.items));

  const zecimal = await post(admin, '/orders', {
    customerName: CLIENT, fulfillment: 'Ridicare',
    items: [{ productId: P1.id, qty: 2.5 }],
  });
  check('cantitatea cu zecimale trece mai departe → 201', zecimal.status === 201, scurt(zecimal));

  // Și la corectare, nu doar la creare: acolo pică toată salvarea, deci
  // comanda trebuie să rămână întreagă.
  const o = await comandaNoua();
  const r = await patch(admin, '/orders/' + o.id, {
    items: [{ productId: [P1.id, P2.id], qty: 1 }],
  });
  check('același tablou, la corectare → 422 bad_product',
    r.status === 422 && cod(r) === 'bad_product', scurt(r));
  const intacta = await citeste(o.id);
  check('comanda rămâne cu poziția ei bună',
    (intacta?.items || []).length === 1 && intacta.items[0].productId === P1.id,
    JSON.stringify(intacta?.items));
  check('și cu totalul neschimbat', intacta?.total === o.total,
    o.total + ' → ' + intacta?.total);
}

/* -------------------------------------------------------------- final -- */

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} eșuate`);
if (fail) {
  console.log('\nEȘUATE:');
  failures.forEach((f) => console.log('  · ' + f));
}
process.exit(fail ? 1 : 0);
