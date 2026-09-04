// Proba pentru defectul din `api/routes/orders.php`: PATCH /orders cerea doar
// require_staff(), deci contul comun al tabletelor — „panou", rol angajat,
// deschis cu un cod știut de toată tura — putea rescrie orice câmp al oricărei
// comenzi. Un singur {customerName: "Brutăria X"} lega comanda de contul acelui
// client, care o vedea de atunci în „Comanda mea", cu adresa, telefonul și
// pozițiile altui client; în jurnal autorul rămânea „Panou comun", deci nu se
// putea afla cine a făcut-o.
//
//   node .claude/instanta.mjs porneste proba-drepturi-patch
//   GP_BASE=http://127.0.0.1:PORT/api node .claude/test-reparat-drepturi-patch.mjs
//   node .claude/instanta.mjs opreste proba-drepturi-patch
//
// Reparația: lista explicită ORDER_FIELDS_STAFF = ['status']. Personalul mută
// etapa — tableta din laborator și telefonul șoferului trăiesc din asta —, iar
// restul câmpurilor cer rol de administrator.
//
// Dacă reparația e dată înapoi, pică prima dată „numele altui client din contul
// de angajat → 403", iar imediat după ea „comanda NU s-a legat de contul
// clientului" și „clientul NU o vede în «Comanda mea»" — adică exact paguba din
// patiserie.

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
const get   = (a, p)    => call(a, 'GET', p);
const post  = (a, p, b) => call(a, 'POST', p, b ?? {});
const patch = (a, p, b) => call(a, 'PATCH', p, b ?? {});
const put   = (a, p, b) => call(a, 'PUT', p, b ?? {});

/** Sufix propriu, ca proba să poată fi rulată de mai multe ori pe aceeași bază. */
const S = Date.now().toString(36).slice(-6);

/* ---------------------------------------------------------- pregătire -- */

const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica ca admin: ' + r.status); process.exit(1); }
}

/** Cont nou plus prima intrare: parola provizorie se schimbă, altfel serverul refuză tot. */
async function contNou(username, name, role, extra = {}) {
  const provizorie = 'Parola-Prov-1234';
  const proprie    = 'Parola-Proba-9876';
  const c = await post(admin, '/users', { username, name, password: provizorie, role, ...extra });
  if (c.status !== 201) throw new Error('nu pot crea contul ' + username + ': ' + c.status + ' ' + JSON.stringify(c.data));
  const a = agent();
  const l = await post(a, '/auth/login', { username, password: provizorie });
  a.csrf = l.data?.csrf || '';
  if (l.status !== 200) throw new Error('nu pot intra în contul ' + username + ': ' + l.status);
  const p = await post(a, '/auth/password', { currentPassword: provizorie, newPassword: proprie });
  if (p.status !== 200) throw new Error('nu pot schimba parola provizorie a lui ' + username + ': ' + p.status);
  return { agent: a, id: Number(c.data.user.id) };
}

// Angajatul de tură — șoferul intră cu un cont ca ăsta, de pe telefon.
const angajat = await contNou('proba.ang.' + S, 'Angajat Probă', 'angajat');
// Clientul pe contul căruia ajungea comanda, cu numele de firmă după care caută
// `client_by_name()`.
const NUME_CLIENT = 'Brutăria Probei ' + S;
const client = await contNou('proba.cli.' + S, NUME_CLIENT, 'client', { customerName: NUME_CLIENT });

// Tableta din laborator nu se deschide cu utilizator și parolă, ci cu codul
// comun din Setări. Proba trece chiar pe ruta aceea, ca dreptul verificat să fie
// al contului real de tabletă, nu al unui angajat inventat de probă.
const COD = 'cod-tableta-' + S;
{
  const r = await put(admin, '/settings', { settings: { panel_enabled: '1', panel_code: COD } });
  if (r.status !== 200) { console.log('nu pot configura codul panoului: ' + r.status + ' ' + JSON.stringify(r.data)); process.exit(1); }
}
const tableta = agent();
{
  const r = await post(tableta, '/auth/panel', { code: COD });
  tableta.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu pot deschide panoul cu cod: ' + r.status + ' ' + JSON.stringify(r.data)); process.exit(1); }
}

const LISTE = (await get(admin, '/lists')).data || {};
const ETAPE = LISTE.statuses || [];
if (ETAPE.length < 4) { console.log('lista de etape e prea scurtă pentru probă'); process.exit(1); }
const ZI = '2026-09-21';

/** Comandă de probă, scrisă de administrator, nelegată de niciun cont. */
async function comanda() {
  const r = await post(admin, '/orders', {
    customerName: 'Client Anonim ' + S, phone: '0700 000 111', address: 'Str. Probei 1',
    day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: 1, qty: 1, variant: 'copt' }],
  });
  if (r.status !== 201) throw new Error('nu pot crea comanda de probă: ' + r.status + ' ' + JSON.stringify(r.data));
  return r.data.order;
}
const citeste = async (id) => (await get(admin, '/orders/' + id)).data?.order;
const clientulVede = async (id) => {
  const r = await get(client.agent, '/orders?day=' + ZI);
  return r.status === 200 && (r.data?.orders || []).some((o) => o.id === id);
};

/* ====== 1. numele altui client nu mai mută comanda, din contul de tură ==== */

console.log('\n=== PATCH /orders: numele clientului cere administrator ===');
{
  const o = await comanda();
  check('comanda de probă pleacă nelegată de vreun cont', o.customerId === null, `customerId = ${o.customerId}`);

  const r = await patch(angajat.agent, `/orders/${o.id}`, { customerName: NUME_CLIENT });
  check('numele altui client din contul de angajat → 403', r.status === 403,
    `${r.status} ${JSON.stringify(r.data)}`);
  check('refuzul spune ce se poate schimba din contul de tură',
    /etapa/i.test(r.data?.error?.message || ''), JSON.stringify(r.data?.error));
  check('refuzul numește câmpul oprit',
    /customerName/.test(r.data?.error?.message || ''), JSON.stringify(r.data?.error));

  const dupa = await citeste(o.id);
  check('comanda NU s-a legat de contul clientului', dupa?.customerId === null,
    `customerId = ${dupa?.customerId}, contul clientului e ${client.id}`);
  check('numele comenzii a rămas neatins', dupa?.customerName === 'Client Anonim ' + S,
    `customerName = ${dupa?.customerName}`);
  check('clientul NU o vede în „Comanda mea"', !(await clientulVede(o.id)));

  // Aceeași cerere, din contul comun al tabletelor: drumul pe care mergea
  // defectul în laborator.
  const t = await patch(tableta, `/orders/${o.id}`, { customerName: NUME_CLIENT });
  check('și din contul „panou" al tabletei → 403', t.status === 403,
    `${t.status} ${JSON.stringify(t.data)}`);
  check('comanda tot nu s-a legat de cont', (await citeste(o.id))?.customerId === null);
}

/* =========== 2. restul câmpurilor cer tot rol de administrator =========== */

console.log('\n=== PATCH /orders: câmpurile de hotărâre cer administrator ===');
{
  const o = await comanda();
  const oprite = [
    ['customerId',  client.id],
    ['phone',       '0777 000 000'],
    ['address',     'Str. Mutată 9'],
    ['ref',         'REF-TURA-' + S.slice(0, 4)],
    ['day',         '2026-10-30'],
    ['slot',        ''],
    ['notes',       'notă scrisă din tură'],
    ['zone',        ''],
    ['urgent',      true],
    ['timed',       true],
    ['fulfillment', 'Livrare'],
    ['items',       [{ productId: 2, qty: 40, variant: 'copt' }]],
  ];
  for (const [camp, val] of oprite) {
    const r = await patch(angajat.agent, `/orders/${o.id}`, { [camp]: val });
    check(`«${camp}» din contul de angajat → 403`, r.status === 403,
      `${r.status} ${JSON.stringify(r.data?.error || r.data?.order?.[camp])}`);
  }

  const dupa = await citeste(o.id);
  check('comanda a rămas exact cum a lăsat-o administratorul',
    dupa?.address === 'Str. Probei 1' && dupa?.day === ZI && dupa?.urgent === false
      && dupa?.total === o.total && dupa?.ref === o.ref,
    JSON.stringify({ address: dupa?.address, day: dupa?.day, urgent: dupa?.urgent, total: dupa?.total, ref: dupa?.ref }));

  // Etapa strecurată lângă un câmp oprit nu deschide ușa: cererea cade întreagă,
  // altfel jumătate de formular s-ar salva și jumătate nu.
  const amestec = await patch(angajat.agent, `/orders/${o.id}`, { status: ETAPE[1], address: 'Str. Strecurată 3' });
  check('etapa trimisă împreună cu un câmp oprit → 403', amestec.status === 403,
    `${amestec.status} ${JSON.stringify(amestec.data)}`);
  const dupa2 = await citeste(o.id);
  check('nici etapa, nici adresa nu s-au scris pe jumătate',
    dupa2?.status === ETAPE[0] && dupa2?.address === 'Str. Probei 1',
    JSON.stringify({ status: dupa2?.status, address: dupa2?.address }));
}

/* ===== 3. tableta din laborator și telefonul șoferului mută mai departe === */

console.log('\n=== etapa rămâne la îndemâna personalului ===');
{
  const o = await comanda();

  // Panou Laborator.dc.html:430 — brutarul avansează comanda de pe tabletă.
  for (const etapa of ETAPE.slice(1, 4)) {
    const r = await patch(tableta, `/orders/${o.id}`, { status: etapa });
    check(`tableta din laborator mută comanda pe „${etapa}"`,
      r.status === 200 && r.data?.order?.status === etapa,
      `${r.status} ${JSON.stringify(r.data?.error || r.data?.order?.status)}`);
  }

  // Ruta Sofer.dc.html:419 — șoferul marchează livrarea de pe telefon, dintr-un
  // cont individual de angajat.
  const predata = LISTE.done || ETAPE[ETAPE.length - 2];
  const r = await patch(angajat.agent, `/orders/${o.id}`, { status: predata });
  check(`telefonul șoferului marchează „${predata}"`,
    r.status === 200 && r.data?.order?.status === predata,
    `${r.status} ${JSON.stringify(r.data?.error || r.data?.order?.status)}`);
  check('etapa se vede și la o citire nouă', (await citeste(o.id))?.status === predata);

  // Anularea livrării întoarce comanda o etapă: și drumul înapoi trebuie să meargă.
  const inapoi = ETAPE[Math.max(ETAPE.indexOf(predata) - 1, 0)];
  const r2 = await patch(angajat.agent, `/orders/${o.id}`, { status: inapoi });
  check(`șoferul poate anula livrarea, înapoi pe „${inapoi}"`,
    r2.status === 200 && r2.data?.order?.status === inapoi,
    `${r2.status} ${JSON.stringify(r2.data?.error || r2.data?.order?.status)}`);

  // Lista de câmpuri nu ocolește verificarea etapei: o valoare inventată tot cade.
  const gresit = await patch(tableta, `/orders/${o.id}`, { status: 'Zburat' });
  check('o etapă inexistentă rămâne refuzată cu 422, nu cu 403', gresit.status === 422,
    `${gresit.status} ${JSON.stringify(gresit.data)}`);
}

/* ============== 4. administratorul lucrează mai departe ================== */

console.log('\n=== administratorul corectează în continuare tot formularul ===');
{
  const o = await comanda();
  const r = await patch(admin, `/orders/${o.id}`, {
    customerName: NUME_CLIENT, phone: '0733 111 222', address: 'Bd. Corectat 42',
    day: '2026-09-22', urgent: true, notes: 'corectat din Administrare',
    items: [{ productId: 2, qty: 3, variant: 'copt' }],
  });
  check('formularul întreg, salvat de administrator → 200', r.status === 200,
    `${r.status} ${JSON.stringify(r.data)}`);
  check('numele corectat leagă comanda de contul clientului', r.data?.order?.customerId === client.id,
    `customerId = ${r.data?.order?.customerId}, așteptat ${client.id}`);
  check('pozițiile s-au retarifat', r.data?.order?.total !== o.total,
    `total = ${r.data?.order?.total}, era ${o.total}`);

  // Clientul rămâne, ca înainte, în afara rutei — reparația nu i-a deschis nimic.
  const c = await patch(client.agent, `/orders/${o.id}`, { status: ETAPE[1] });
  check('clientul nu poate atinge comanda nici acum → 403', c.status === 403,
    `${c.status} ${JSON.stringify(c.data)}`);
}

/* -------------------------------------------------------------- final -- */

console.log(`\n${pass} trecute, ${fail} picate`);
if (fail) {
  console.log('\nPicate:');
  for (const f of failures) console.log('  - ' + f);
  process.exit(1);
}
process.exit(0);

