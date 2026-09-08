// Probele pentru „Salvează modificările" din Administrare Comenzi.
//
//   node .claude/test-editare.mjs
//
// Formularul din panou arată toată comanda: clientul, telefonul, adresa,
// referința, prioritatea și pozițiile. Serverul accepta însă doar status, note,
// zi, interval, zonă și mod de onorare — restul se pierdea în tăcere, sub un
// mesaj care spunea „a fost salvată". Aceleași corecturi se pierdeau și la
// acceptarea unei cereri, adică exact la pasul care există ca să le poți face.
//
// Fiecare probă de aici a fost verificată stricând reparația la loc.

const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';

let pass = 0, fail = 0;
const failures = [];
const check = (name, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
};

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
const put = (a, p, b) => call(a, 'PUT', p, b ?? {});

const LISTS = await (await fetch(BASE + '/lists')).json();
const ZONE = (LISTS.zones || [])[0] || '';
const ZONE2 = (LISTS.zones || [])[1] || ZONE;
const SLOT = (LISTS.slots || [])[0] || '';

// Prețurile vin din catalog, ca proba să nu depindă de o listă de prețuri
// scrisă aici și rămasă în urmă.
const anon = agent();
const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica: ' + r.status); process.exit(1); }
}
const PROD = (await get(admin, '/products')).data?.products || [];
const pret = (id, v) => {
  const p = PROD.find((x) => x.id === id);
  return Number(v === 'congelat' ? p.priceFrozen : p.priceBaked);
};
const rot = (n) => Math.round(n * 100) / 100;

console.log('\n=== PATCH /orders/{id}: se salvează toată comanda ===');
{
  const c = await post(admin, '/orders', {
    customerName: 'Client Inițial', phone: '0700 000 001',
    address: 'Str. Veche 1', zone: ZONE, day: '2026-09-05', slot: SLOT,
    fulfillment: 'Livrare', channel: 'panou', notes: 'notă inițială',
    items: [{ productId: 1, qty: 2, variant: 'copt' }],
  });
  check('comandă de probă creată → 201', c.status === 201, `${c.status} ${JSON.stringify(c.data)}`);
  const id = c.data?.order?.id;

  const asteptat = rot(pret(1, 'congelat') * 10 + pret(4, 'copt') * 3);
  const r = await patch(admin, `/orders/${id}`, {
    customerName: 'Client Corectat', phone: '0733 999 888',
    address: 'Bd. Nou 42', zone: ZONE2, day: '2026-09-06', slot: SLOT,
    fulfillment: 'Livrare', notes: 'notă corectată', urgent: true, timed: true,
    status: 'La dospit',
    items: [
      { productId: 1, qty: 10, variant: 'congelat' },
      { productId: 4, qty: 3, variant: 'copt' },
    ],
  });
  check('modificarea completă → 200', r.status === 200, `${r.status} ${JSON.stringify(r.data)}`);

  const o = r.data?.order;
  check('numele clientului s-a salvat', o?.customerName === 'Client Corectat', o?.customerName);
  check('telefonul s-a salvat', o?.phone === '0733 999 888', o?.phone);
  check('adresa s-a salvat', o?.address === 'Bd. Nou 42', o?.address);
  check('zona s-a salvat', o?.zone === ZONE2, o?.zone);
  check('ziua s-a salvat', o?.day === '2026-09-06', o?.day);
  check('urgența s-a salvat', o?.urgent === true, String(o?.urgent));
  check('pozițiile s-au rescris', (o?.items || []).length === 2, `${(o?.items || []).length} poziții`);
  check('cantitatea corectată s-a salvat', (o?.items || [])[0]?.qty === 10, String((o?.items || [])[0]?.qty));
  check('varianta fiecărei poziții s-a salvat',
    (o?.items || []).map((i) => i.variant).join(',') === 'congelat,copt',
    JSON.stringify((o?.items || []).map((i) => i.variant)));
  check('comanda amestecată e marcată „mixt”', o?.variant === 'mixt', o?.variant);
  check('totalul s-a recalculat din catalog', o?.total === asteptat,
    `a dat ${o?.total}, așteptam ${asteptat}`);

  // Citită din nou, nu doar din răspunsul imediat: rescrierea pozițiilor și
  // antetul trebuie să fi ajuns împreună în bază.
  const dupa = await get(admin, `/orders/${id}`);
  const o2 = dupa.data?.order;
  check('modificările sunt în bază, nu doar în răspuns',
    o2?.customerName === 'Client Corectat' && o2?.total === asteptat && (o2?.items || []).length === 2,
    JSON.stringify({ n: o2?.customerName, t: o2?.total, p: (o2?.items || []).length }));

  // Prețul nu poate fi impus din browser: poziția trimite doar produsul,
  // cantitatea și varianta.
  const fals = await patch(admin, `/orders/${id}`, {
    items: [{ productId: 1, qty: 1, variant: 'copt', unitPrice: 0.01 }],
  });
  check('prețul trimis de browser e ignorat',
    fals.data?.order?.total === rot(pret(1, 'copt')),
    `a dat ${fals.data?.order?.total}, așteptam ${rot(pret(1, 'copt'))}`);
}

console.log('\n=== mutarea pe altă zi nu se lovește de numerotarea zilei ===');
{
  // Numerotarea e per zi. Comanda mutată își păstra numărul, iar dacă ziua
  // nouă avea deja o comandă cu acel număr, salvarea cădea cu „Duplicate
  // entry" — un mesaj de bază de date afișat administratorului, cu corectura
  // pierdută. Se întâmplă la prima comandă a fiecărei zile, adică des.
  // Două zile libere, căutate pe server la fiecare rulare: pe o zi goală prima
  // comandă primește mereu numărul 1, deci ciocnirea e sigură. Pe zile
  // rămase de la rulările anterioare numerele ar diverge, iar proba ar trece
  // degeaba — de aceea zilele se aleg întrebând, nu ghicind.
  const zi = (n) => new Date(Date.UTC(2027, 0, 1 + n)).toISOString().slice(0, 10);
  const goala = async (z) => ((await get(admin, '/orders?day=' + z)).data?.orders || []).length === 0;
  let ZI_A = '', ZI_B = '';
  for (let n = 0; n < 600 && !ZI_B; n += 2) {
    if (await goala(zi(n)) && await goala(zi(n + 1))) { ZI_A = zi(n); ZI_B = zi(n + 1); }
  }
  check('am găsit două zile libere pentru probă', !!ZI_B, 'toate zilele de probă sunt ocupate');
  const nou = (z) => post(admin, '/orders', {
    customerName: 'Client Mutare', fulfillment: 'Ridicare', day: z,
    items: [{ productId: 1, qty: 1 }],
  });

  const a = await nou(ZI_A);
  const b = await nou(ZI_B);
  check('două comenzi de probă, pe zile libere diferite',
    a.status === 201 && b.status === 201, `${a.status} / ${b.status}`);
  check('amândouă poartă numărul 1 în ziua lor',
    a.data?.order?.seq === 1 && b.data?.order?.seq === 1,
    `${a.data?.order?.seq} / ${b.data?.order?.seq}`);

  const seqA = a.data?.order?.seq;
  const refB = b.data?.order?.ref;
  const r = await patch(admin, `/orders/${b.data?.order?.id}`, { day: ZI_A });
  check('mutarea pe o zi ocupată → 200', r.status === 200,
    `${r.status} ${JSON.stringify(r.data?.error || '')}`);
  check('comanda mutată primește alt număr în ziua nouă',
    Number.isInteger(r.data?.order?.seq) && r.data.order.seq !== seqA,
    `a dat seq ${r.data?.order?.seq}, ziua avea deja ${seqA}`);
  // Amândouă comenzile purtau „#1001", fiindcă numerotarea reîncepe în fiecare
  // zi. Mutată peste cealaltă, comanda nu are voie să rămână cu aceeași
  // referință: foaia de drum ar arăta două opriri „#1001" la doi clienți.
  const refMutat = r.data?.order?.ref;
  check('referința deja folosită în ziua nouă se schimbă',
    refMutat !== refB, `a rămas ${refMutat}`);
  const inZiuaA = (await get(admin, '/orders?day=' + ZI_A)).data?.orders || [];
  check('și noua referință e singura din ziua aceea',
    inZiuaA.filter((o) => o.ref === refMutat).length === 1,
    JSON.stringify(inZiuaA.map((o) => o.ref)));
  check('comanda chiar s-a mutat', r.data?.order?.day === ZI_A, r.data?.order?.day);

  // Referința e singurul lucru pe care clientul îl ține minte, deci schimbarea
  // se scrie în jurnal — altfel omul de la telefon n-ar avea de unde ști ce
  // număr să-i spună.
  const jurnal = (await get(admin, '/log?action=order_update')).data?.entries || [];
  check('schimbarea referinței se vede în jurnal',
    jurnal.some((e) => String(e.detail || '').includes('referință schimbată: ' + refB + ' → ' + refMutat)),
    JSON.stringify(jurnal.slice(0, 2).map((e) => e.detail)));

  // Cazul obișnuit — ziua nouă n-are acel număr — lasă referința în pace:
  // clientul sună a doua zi cu numărul pe care i l-am dat.
  let ZI_C = '';
  for (let n = 100; n < 700 && !ZI_C; n++) {
    if (await goala(zi(n))) ZI_C = zi(n);
  }
  check('am găsit o zi liberă pentru mutarea fără ciocnire', !!ZI_C);
  if (ZI_C) {
    const m = await patch(admin, `/orders/${b.data?.order?.id}`, { day: ZI_C });
    check('mutarea pe o zi liberă → 200', m.status === 200, `${m.status}`);
    check('referința pe care o știe clientul rămâne neschimbată',
      m.data?.order?.ref === refMutat, `${refMutat} → ${m.data?.order?.ref}`);
  }

  // Locul de pe foaia de drum e al zilei lui. Rămas neatins la mutare, comanda
  // se așeza peste oprirea care avea deja acel număr în ziua nouă.
  const cuLoc = await nou(ZI_A);
  const idLoc = cuLoc.data?.order?.id;
  const asezare = await put(admin, '/routes', { day: ZI_A, ids: [idLoc] });
  check('ruta zilei se poate așeza', asezare.status === 200, `${asezare.status}`);
  const inainte = (await get(admin, '/orders/' + idLoc)).data?.order;
  check('comanda are un loc pe ruta zilei', inainte?.routeSeq === 0,
    'routeSeq = ' + JSON.stringify(inainte?.routeSeq));
  const mutata = await patch(admin, `/orders/${idLoc}`, { day: ZI_C || ZI_B });
  check('mutată pe altă zi, comanda nu mai ține locul altcuiva',
    mutata.data?.order?.routeSeq === null,
    'routeSeq = ' + JSON.stringify(mutata.data?.order?.routeSeq));
}

console.log('\n=== o poziție greșită oprește salvarea, nu dispare ===');
{
  const c = await post(admin, '/orders', {
    customerName: 'Client Poziții', fulfillment: 'Ridicare',
    items: [{ productId: 1, qty: 1 }],
  });
  const id = c.data?.order?.id;

  const r = await patch(admin, `/orders/${id}`, {
    items: [{ productId: 1, qty: 1 }, { productId: 999999, qty: 1 }],
  });
  check('produs inexistent → 422', r.status === 422, `a dat ${r.status}`);

  const dupa = await get(admin, `/orders/${id}`);
  check('comanda rămâne neatinsă după refuz',
    (dupa.data?.order?.items || []).length === 1,
    `${(dupa.data?.order?.items || []).length} poziții`);

  const gol = await patch(admin, `/orders/${id}`, { items: [] });
  check('listă de poziții goală → 422', gol.status === 422, `a dat ${gol.status}`);

  const rea = await patch(admin, `/orders/${id}`, {
    items: [{ productId: 1, qty: 1, variant: 'frozen' }],
  });
  check('variantă scrisă în engleză → 422', rea.status === 422, `a dat ${rea.status}`);
}

console.log('\n=== acceptarea unei cereri păstrează corecturile ===');
{
  // Ziua se calculează, nu se scrie: „2026-09-07" a devenit trecut la miezul
  // nopții și proba pica cu day_in_past fără nicio legătură cu ce verifică.
  const MAINE = (() => {
    const d = new Date(); d.setDate(d.getDate() + 2);
    const z = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate());
  })();
  const cer = await post(anon, '/requests', {
    customerName: 'Cofetăria Cu Greșeli', phone: '0700',
    address: 'adresă incompletă', zone: ZONE, day: MAINE,
    items: [{ productId: 1, qty: 4, variant: 'copt' }],
  });
  check('cerere de probă → 201', cer.status === 201, `${cer.status} ${JSON.stringify(cer.data)}`);
  const rid = cer.data?.request?.id;

  const asteptat = rot(pret(1, 'congelat') * 6 + pret(4, 'copt') * 2);
  const r = await post(admin, `/requests/${rid}/accept`, {
    day: MAINE, slot: SLOT, zone: ZONE2, fulfillment: 'Livrare',
    customerName: 'Cofetăria Corectată', phone: '0733 111 222',
    address: 'Str. Lămurită 8', notes: 'lămurit la telefon', urgent: true, timed: true,
    items: [
      { productId: 1, qty: 6, variant: 'congelat' },
      { productId: 4, qty: 2, variant: 'copt' },
    ],
  });
  check('acceptare cu corecturi → 201', r.status === 201, `${r.status} ${JSON.stringify(r.data)}`);

  const o = r.data?.order;
  check('numele corectat a ajuns în comandă', o?.customerName === 'Cofetăria Corectată', o?.customerName);
  check('telefonul corectat a ajuns în comandă', o?.phone === '0733 111 222', o?.phone);
  check('adresa corectată a ajuns în comandă', o?.address === 'Str. Lămurită 8', o?.address);
  check('zona corectată a ajuns în comandă', o?.zone === ZONE2, o?.zone);
  check('prioritatea din formular a ajuns în comandă', o?.urgent === true, String(o?.urgent));
  check('pozițiile corectate au înlocuit cererea', (o?.items || []).length === 2,
    `${(o?.items || []).length} poziții`);
  check('totalul s-a recalculat la acceptare', o?.total === asteptat,
    `a dat ${o?.total}, așteptam ${asteptat}`);
  check('varianta pe poziție trece de acceptare',
    (o?.items || []).map((i) => i.variant).join(',') === 'congelat,copt',
    JSON.stringify((o?.items || []).map((i) => i.variant)));
  check('cererea rămâne legată de comandă', o?.requestId === rid, String(o?.requestId));
}

console.log('\n=== acceptarea refuză o zonă care nu există ===');
{
  const cer = await post(anon, '/requests', {
    customerName: 'Client Zonă', phone: '0700 111 000',
    items: [{ productId: 1, qty: 1 }],
  });
  const rid = cer.data?.request?.id;
  const r = await post(admin, `/requests/${rid}/accept`, { zone: 'Centru', fulfillment: 'Livrare' });
  check('zonă inexistentă la acceptare → 422', r.status === 422, `a dat ${r.status}`);

  const list = await get(admin, '/requests?status=nou');
  check('cererea rămâne în așteptare după refuz',
    (list.data?.requests || []).some((x) => x.id === rid));
}

console.log('\n=== un refuz dat din greșeală se poate anula ===');
{
  const cer = await post(anon, '/requests', {
    customerName: 'Bistro Din Greșeală', phone: '0700 222 333',
    items: [{ productId: 1, qty: 2, variant: 'copt' }],
  });
  check('cerere de probă → 201', cer.status === 201, `${cer.status} ${JSON.stringify(cer.data)}`);
  const rid = cer.data?.request?.id;

  const resp = await post(admin, `/requests/${rid}/reject`, { reason: 'apăsat din greșeală' });
  check('refuzul trece → 200', resp.status === 200, `a dat ${resp.status}`);

  // Starea se numește „respinsa" în bază; panoul filtra după „refuzata", adică
  // după o valoare care nu există. Proba verifică numele real, cel pe care îl
  // primește pagina.
  const respinse = await get(admin, '/requests?status=respinsa');
  check('cererea refuzată apare pe filtrul „respinsa”',
    (respinse.data?.requests || []).some((x) => x.id === rid && x.status === 'respinsa'),
    JSON.stringify((respinse.data?.requests || []).find((x) => x.id === rid) || null));

  const redo = await post(admin, `/requests/${rid}/reopen`, {});
  check('anularea refuzului trece → 200', redo.status === 200, `${redo.status} ${JSON.stringify(redo.data)}`);

  const noi = await get(admin, '/requests?status=nou');
  const inapoi = (noi.data?.requests || []).find((x) => x.id === rid);
  check('cererea e din nou în așteptare', !!inapoi, 'nu mai apare pe filtrul „nou”');
  check('motivul refuzului s-a șters', !!inapoi && !inapoi.rejectReason, String(inapoi?.rejectReason));

  const dinNou = await post(admin, `/requests/${rid}/reopen`, {});
  check('a doua anulare pe o cerere deja deschisă → 409', dinNou.status === 409, `a dat ${dinNou.status}`);

  // Recuperarea trebuie să fie completă: după anulare, cererea se acceptă
  // normal. Altfel butonul doar mută eticheta, fără să repare nimic.
  const acc = await post(admin, `/requests/${rid}/accept`, { fulfillment: 'Ridicare' });
  check('cererea redeschisă se poate accepta → 201', acc.status === 201,
    `${acc.status} ${JSON.stringify(acc.data)}`);

  // Iar o cerere acceptată NU se mai redeschide: ar naște a doua comandă din
  // aceleași date.
  const dupaAcc = await post(admin, `/requests/${rid}/reopen`, {});
  check('o cerere acceptată nu se redeschide → 409', dupaAcc.status === 409, `a dat ${dupaAcc.status}`);
}

console.log(`\n${'='.repeat(52)}`);
console.log(`${pass} trecute · ${fail} eșuate`);
if (fail) { console.log('\nESUATE:'); for (const f of failures) console.log('  x ' + f); }
console.log();
process.exit(fail ? 1 : 0);
