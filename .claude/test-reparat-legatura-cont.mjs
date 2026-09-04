// Proba pentru defectul din `api/routes/requests.php`, ruta de acceptare:
//
//   node .claude/test-reparat-legatura-cont.mjs
//
// Administratorul corectează numele clientului chiar în formularul de
// acceptare — „Brutăria X" devine „Brutăria X S.R.L." — iar ruta căuta contul
// din nou după numele nou. `client_by_name()` nu-l mai găsea, deci comanda se
// năștea cu `customer_id` gol: clientul nu-și mai vedea propria comandă în
// „Comanda mea" și primea „Nu ai acces la această comandă" (403) când o
// deschidea direct. Aceeași corectură făcută prin PATCH /orders păstra
// legătura, fiindcă acolo căutarea după nume poate doar să adauge una care
// lipsește, niciodată să o șteargă pe cea bună.
//
// Verificarea care pică dacă reparația e dată înapoi:
//   „legătura cu contul rămâne după corectura numelui" — și, în lanț,
//   „clientul își vede comanda în «Comanda mea»" și „o poate deschide direct".
//
// Rulează pe o instanță izolată:
//   node .claude/instanta.mjs porneste proba-legatura
//   GP_BASE=<adresa tipărită> node .claude/test-reparat-legatura-cont.mjs
//   node .claude/instanta.mjs opreste proba-legatura

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

/* ---------------------------------------------------------- pregătire -- */

const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica drept admin: ' + r.status); process.exit(1); }
}

// Contul de client și-l face proba singură: numele lui de firmă e tocmai cel
// pe care administratorul îl va „corecta" la acceptare.
const sufix  = Math.random().toString(36).slice(2, 7);
const UTIL   = 'proba' + sufix;
const NUME   = 'Brutăria Probei ' + sufix;
const PAROLA = 'Parola-Probei-' + sufix;
let CONT = 0;
{
  const r = await post(admin, '/users', {
    username: UTIL, name: 'Client Probă ' + sufix, password: 'Parola-Initiala-1',
    role: 'client', customerName: NUME, phone: '0700 000 111',
  });
  if (r.status !== 201) { console.log('nu pot crea contul de probă: ' + r.status + ' ' + JSON.stringify(r.data)); process.exit(1); }
  CONT = Number(r.data.user.id);
}

// Un al doilea cont, pentru cazul în care administratorul chiar VREA să mute
// comanda: punctul de lucru a cerut-o, firma-mamă o plătește.
const NUME2 = 'Cofetăria Probei ' + sufix;
let CONT2 = 0;
{
  const r = await post(admin, '/users', {
    username: 'proba2' + sufix, name: 'Client Probă 2 ' + sufix,
    password: 'Parola-Initiala-1', role: 'client', customerName: NUME2, phone: '0700 000 222',
  });
  if (r.status !== 201) { console.log('nu pot crea al doilea cont: ' + r.status + ' ' + JSON.stringify(r.data)); process.exit(1); }
  CONT2 = Number(r.data.user.id);
}

// Contul nou vine cu parolă provizorie, iar `require_auth()` îi închide toate
// rutele până și-o schimbă. O schimbăm, altfel clientul nu poate nici măcar
// să-și trimită cererea.
const client = agent();
{
  let r = await post(client, '/auth/login', { username: UTIL, password: 'Parola-Initiala-1' });
  client.csrf = r.data?.csrf || '';
  r = await post(client, '/auth/password', { currentPassword: 'Parola-Initiala-1', newPassword: PAROLA });
  if (r.status !== 200) { console.log('nu pot scoate parola provizorie: ' + r.status); process.exit(1); }
  // Schimbarea parolei închide celelalte sesiuni ale contului; intrăm din nou.
  r = await post(client, '/auth/login', { username: UTIL, password: PAROLA });
  client.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu mă pot autentifica drept client: ' + r.status); process.exit(1); }
}

// O zi din viitor: acceptarea refuză, pe drept, o zi trecută.
const ZI = new Date(Date.now() + 3 * 86400000).toISOString().slice(0, 10);

/** Cererea trimisă de client de pe site, din contul lui. */
async function cerereaClientului() {
  const r = await post(client, '/requests', {
    day: ZI, items: [{ productId: 1, qty: 1, variant: 'copt' }],
  });
  if (r.status !== 201) throw new Error('nu pot trimite cererea: ' + r.status + ' ' + JSON.stringify(r.data));
  return r.data.request;
}

/** Cererea unui vizitator fără cont: nimeni autentificat, doar nume și telefon. */
async function cerereaVizitatorului(nume) {
  const r = await post(agent(), '/requests', {
    customerName: nume, phone: '0700 222 333', day: ZI,
    items: [{ productId: 1, qty: 1, variant: 'copt' }],
  });
  if (r.status !== 201) throw new Error('nu pot trimite cererea de vizitator: ' + r.status + ' ' + JSON.stringify(r.data));
  return r.data.request;
}

/** Acceptarea, așa cum o trimite formularul din „Administrare Comenzi". */
const accepta = (id, camp) => post(admin, `/requests/${id}/accept`, { day: ZI, fulfillment: 'Livrare', ...camp });

/** Vede clientul comanda în „Comanda mea"? Întrebăm chiar panoul lui. */
async function clientulVede(id) {
  const r = await get(client, '/orders?day=' + ZI);
  return r.status === 200 && (r.data?.orders || []).some((o) => o.id === id);
}

/* ========== 1. numele corectat la acceptare nu rupe legătura ============ */

console.log('\n=== acceptare: corectura numelui nu dezleagă comanda de cont ===');
{
  const req = await cerereaClientului();
  check('cererea clientului cu cont se naște legată de contul lui', req.customerId === CONT,
    `customerId = ${req.customerId}, așteptat ${CONT}`);

  // Corectura pe care o face administratorul în formularul de acceptare:
  // firma se cheamă, în acte, „... S.R.L.". Numele nou nu mai duce la niciun
  // cont — și tocmai de asta nu are voie să șteargă legătura existentă.
  const a = await accepta(req.id, { customerName: NUME + ' S.R.L.' });
  check('acceptarea reușește → 201', a.status === 201, `${a.status} ${JSON.stringify(a.data)}`);
  const o = a.data?.order;
  check('numele corectat ajunge în comandă', o?.customerName === NUME + ' S.R.L.',
    `customerName = ${o?.customerName}`);

  // Verificarea care pică dacă reparația e dată înapoi:
  check('legătura cu contul rămâne după corectura numelui', o?.customerId === CONT,
    `customerId = ${o?.customerId}, așteptat ${CONT}`);
  check('clientul își vede comanda în „Comanda mea"', await clientulVede(o?.id));

  const direct = await get(client, '/orders/' + o?.id);
  check('și o poate deschide direct, fără 403', direct.status === 200,
    `${direct.status} ${JSON.stringify(direct.data)}`);
}

/* ====== 2. cererea vizitatorului: numele corectat ADAUGĂ legătura ======= */

console.log('\n=== acceptare: numele corectat leagă cererea fără cont ===');
{
  const req = await cerereaVizitatorului('brutaria probei ' + sufix + ' (la telefon)');
  check('cererea vizitatorului nu se leagă singură de niciun cont', req.customerId === null,
    `customerId = ${req.customerId}`);

  // Administratorul recunoaște clientul de la telefon și îi scrie numele
  // întreg. Aici căutarea are voie: nu e nicio legătură de pierdut.
  const a = await accepta(req.id, { customerName: NUME });
  check('acceptarea reușește → 201', a.status === 201, `${a.status} ${JSON.stringify(a.data)}`);
  const o = a.data?.order;
  check('numele corectat leagă comanda de contul găsit', o?.customerId === CONT,
    `customerId = ${o?.customerId}, așteptat ${CONT}`);
  check('clientul o vede în „Comanda mea"', await clientulVede(o?.id));
}

/* ====== 2bis. numele ALTUI cont mută comanda, nu o lasă unde era ======== */

console.log('\n=== acceptare: numele altui cont mută comanda la acel cont ===');
{
  // Comanda a venit din contul punctului de lucru, dar se facturează pe firma
  // care are contul ei. Administratorul scrie numele firmei chiar în
  // formularul de acceptare; dacă mutarea nu se face, comanda rămâne în
  // „Comanda mea" a primului client, cu numele celuilalt scris pe ea — două
  // firme diferite pe același ecran.
  //
  // Grupa asta e plasa pentru reparația prea strânsă: „nu dezlega niciodată"
  // scris ca „nu căuta decât când legătura lipsește" ar trece toate celelalte
  // verificări și ar pierde tocmai mutarea.
  const req = await cerereaClientului();
  const o = (await accepta(req.id, { customerName: NUME2 })).data?.order;
  check('comanda trece în contul firmei scrise la acceptare', o?.customerId === CONT2,
    `customerId = ${o?.customerId}, așteptat ${CONT2}`);
  check('primul client nu o mai are în „Comanda mea"', !(await clientulVede(o?.id)));
}

/* ============ 3. numele neatins păstrează starea de dinainte ============ */

console.log('\n=== acceptare fără corectură: nimic nu se mișcă ===');
{
  const req = await cerereaClientului();
  const o = (await accepta(req.id)).data?.order;
  check('comanda din cererea legată rămâne legată', o?.customerId === CONT,
    `customerId = ${o?.customerId}, așteptat ${CONT}`);

  const anon = await cerereaVizitatorului('Cofetăria Necunoscută ' + sufix);
  const o2 = (await accepta(anon.id)).data?.order;
  check('comanda din cererea fără cont rămâne nelegată', o2?.customerId === null,
    `customerId = ${o2?.customerId}`);
}

/* =========== 4. dezlegarea explicită rămâne posibilă, pe față ========== */

console.log('\n=== dezlegarea cerută pe față merge mai departe ===');
{
  const req = await cerereaClientului();
  const o = (await accepta(req.id)).data?.order;
  check('comanda pleacă legată de cont', o?.customerId === CONT, `customerId = ${o?.customerId}`);

  // Legătura pusă greșit se rupe cerând-o limpede, nu ca efect al unei
  // corecturi de nume: PATCH /orders cu `customerId: null`.
  const r = await patch(admin, `/orders/${o?.id}`, { customerId: null });
  check('`customerId: null` dezleagă comanda', r.status === 200 && r.data?.order?.customerId === null,
    `${r.status} customerId = ${r.data?.order?.customerId}`);
  check('clientul nu o mai vede în „Comanda mea"', !(await clientulVede(o?.id)));

  const inapoi = await patch(admin, `/orders/${o?.id}`, { customerId: CONT });
  check('și se poate lega înapoi', inapoi.data?.order?.customerId === CONT,
    `customerId = ${inapoi.data?.order?.customerId}`);
}

/* -------------------------------------------------------------- final -- */

console.log(`\n${pass} trecute, ${fail} picate`);
if (fail) {
  console.log('\nPicate:');
  for (const f of failures) console.log('  - ' + f);
  process.exit(1);
}
process.exit(0);

