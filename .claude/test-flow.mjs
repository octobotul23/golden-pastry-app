// Test end-to-end pentru fluxul în două trepte: cerere → acceptare → comandă.
//
//   node .claude/test-flow.mjs
//
// Verifică exact lucrul care azi este imposibil în aplicație: o cerere
// trimisă de pe un dispozitiv ajunge, prin acceptare, pe panoul din laborator.

import { BAZA_DE_PROBA, CERE_CONFIG } from './baza-probei.mjs';

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
    try {
      data = JSON.parse(raw);
    } catch {
      const head = raw.slice(0, 200).replace(/\s+/g, ' ');
      throw new Error('răspuns care nu este JSON la ' + method + ' ' + path + ': ' + head);
    }
  }
  return { status: res.status, data };
}
const get = (a, p) => call(a, 'GET', p);
const post = (a, p, b) => call(a, 'POST', p, b ?? {});
const patch = (a, p, b) => call(a, 'PATCH', p, b ?? {});

/* ------------------------------------------------------------ scenariu -- */

// Zonele și intervalele sunt editabile din Setări. Testele le iau de la
// server, ca să nu pice atunci când utilizatorul își schimbă lista.
const LISTS = (await (await fetch(BASE + '/lists')).json());
const ZONE = (LISTS.zones || [])[0] || '';
const SLOT = (LISTS.slots || [])[0] || '';

const anon = agent();
const admin = agent();

console.log('\n=== treapta 1: clientul trimite o cerere de pe site ===');
let reqId = null;
{
  const r = await post(anon, '/requests', {
    customerName: 'Cafeneaua Lumina',
    phone: '0722334455',
    day: '2026-09-02',
    slot: '06:30',
    zone: ZONE,
    address: 'Str. Lipscani 12',
    notes: 'Livrare la intrarea din spate.',
    items: [{ productId: 1, qty: 24 }, { productId: 7, qty: 12 }],
  });
  check('cerere trimisă fără cont → 201', r.status === 201, `${r.status} ${JSON.stringify(r.data)}`);
  reqId = r.data?.request?.id;
  check('cererea pornește cu status „nou”', r.data?.request?.status === 'nou', r.data?.request?.status);
  // prod 1 retail 3.49 × 24 + prod 7 retail 3.99 × 12 = 83.76 + 47.88 = 131.64
  check('totalul e calculat din prețurile din BD', r.data?.request?.total === 131.64, `${r.data?.request?.total}`);
  check('intervalul a fost reținut', r.data?.request?.slot === '06:30');
}
{
  const r = await post(anon, '/requests', { customerName: 'X', phone: '0722', items: [], day: '2026-09-02' });
  check('cerere fără produse → 422', r.status === 422, `a dat ${r.status}`);
}
{
  const r = await post(anon, '/requests', {
    customerName: 'X', phone: '0722', slot: '23:45',
    items: [{ productId: 1, qty: 1 }],
  });
  check('interval inexistent → 422', r.status === 422, `a dat ${r.status}`);
}

// Coșul de pe site ține cheile separat („frozen:1" și „baked:4"), deci un
// client poate cere 100 congelate și 100 coapte în aceeași cerere. Până acum
// spre server pleca o singură variantă pentru tot coșul — cea a tabului
// deschis în acel moment — iar serverul recalcula TOTUL la prețul ei: pe ecran
// scria 754,00 lei, iar cererea se înregistra cu 818,00 sau cu 664,00.
let mixtId = null;
{
  // 100 × Ștrudel cu mere congelat (2,85) + 100 × Trigon cu pizza copt (4,69)
  const asteptat = 100 * 2.85 + 100 * 4.69;
  const r = await post(anon, '/requests', {
    customerName: 'Cofetăria Amestecată',
    phone: '0722334466',
    day: '2026-09-02',
    zone: ZONE,
    // Varianta de la nivel de cerere rămâne doar implicitul: pozițiile o au
    // pe a lor. Aici e pusă dinadins pe cealaltă, ca o regresie să se vadă.
    variant: 'copt',
    items: [
      { productId: 1, qty: 100, variant: 'congelat' },
      { productId: 4, qty: 100, variant: 'copt' },
    ],
  });
  mixtId = r.data?.request?.id;
  check('cerere cu ambele variante → 201', r.status === 201, `${r.status} ${JSON.stringify(r.data)}`);
  check('cererea se plătește la prețul variantei fiecărei poziții',
    r.data?.request?.total === asteptat,
    `a dat ${r.data?.request?.total}, așteptam ${asteptat}`);
  check('pozițiile cererii își păstrează varianta',
    (r.data?.request?.items || []).map((i) => i.variant).join(',') === 'congelat,copt',
    JSON.stringify((r.data?.request?.items || []).map((i) => i.variant)));
  check('cererea amestecată e marcată „mixt”', r.data?.request?.variant === 'mixt',
    r.data?.request?.variant);
}

console.log('\n=== cererea NU intră singură în laborator ===');
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  check('adminul se autentifică', r.status === 200, `a dat ${r.status}`);
}
{
  // Legat de cererea curentă, nu de numărul total: baza păstrează comenzi de
  // la rulările anterioare, iar suita trebuie să poată rula oricând.
  const r = await get(admin, '/orders');
  check('cererea nu a produs încă o comandă',
    !(r.data?.orders || []).some((o) => o.requestId === reqId));
}
{
  const r = await get(admin, '/requests?status=nou');
  check('cererea apare în lista de așteptare', (r.data?.requests || []).some((x) => x.id === reqId));
  const req = (r.data?.requests || []).find((x) => x.id === reqId);
  check('cererea păstrează pozițiile', req?.items?.length === 2, `${req?.items?.length}`);
}

console.log('\n=== treapta 2: adminul acceptă → devine comandă ===');
let orderId = null, orderRef = null;
{
  const r = await post(admin, `/requests/${reqId}/accept`, {
    day: '2026-09-02', slot: '06:30', zone: ZONE, fulfillment: 'Livrare',
  });
  check('acceptare → 201', r.status === 201, `${r.status} ${JSON.stringify(r.data)}`);
  orderId = r.data?.order?.id;
  orderRef = r.data?.order?.ref;
  check('comanda primește referință #', /^#\d+$/.test(orderRef || ''), orderRef);
  check('comanda pornește de la prima etapă', r.data?.order?.status === 'În așteptare', r.data?.order?.status);
  check('pozițiile s-au transferat', r.data?.order?.items?.length === 2);
  check('totalul s-a păstrat', r.data?.order?.total === 131.64, `${r.data?.order?.total}`);
  check('comanda păstrează legătura cu cererea', r.data?.order?.requestId === reqId);
}
{
  const r = await post(admin, `/requests/${reqId}/accept`, { day: '2026-09-02' });
  check('a doua acceptare a aceleiași cereri → 409', r.status === 409, `a dat ${r.status}`);
}

/* ---- două acceptări suprapuse nu au voie să facă două comenzi ---------- */
// Cursa nu se poate stârni prin HTTP: `php -S` răspunde la o singură cerere
// odată, deci a doua apăsare ajunge oricum după prima și o oprește verificarea
// de la începutul rutei. Ne uităm atunci la încuietoare, cu chiar instrucțiunea
// scoasă din api/routes/requests.php: dacă marcarea cererii nu cere starea
// „nou”, două tablete care apasă „Adaugă comanda” în aceeași secundă fac două
// comenzi din aceeași cerere, laboratorul pregătește marfa de două ori, iar
// comanda în plus nu se mai poate șterge din panou.
{
  const fs = await import('node:fs');
  const { execFileSync } = await import('node:child_process');
  const { fileURLToPath } = await import('node:url');
  const ROOT = fileURLToPath(new URL('..', import.meta.url));
  const sursa = fs.readFileSync(new URL('../api/routes/requests.php', import.meta.url), 'utf8');

  const i = sursa.indexOf("UPDATE order_requests SET status = 'acceptata'");
  const marcarea = i < 0 ? '' : sursa.slice(i, sursa.indexOf('"', i));
  check('marcarea cererii ca acceptată cere starea „nou”',
    /WHERE id = \? AND status = 'nou'\s*$/.test(marcarea),
    marcarea || 'n-am găsit instrucțiunea în api/routes/requests.php');
  check('cererea se blochează în tranzacție înainte să se insereze comanda',
    /FROM order_requests WHERE id = \? FOR UPDATE/.test(sursa));
  check('acceptarea care n-a marcat niciun rând se dă înapoi, cu 409',
    /rowCount\(\)/.test(sursa)
      && /rollBack\(\);\s*[\r\n]+\s*fail\([^()]*409, 'already_handled'\)/.test(sursa));

  // Proba adevărată: aceeași instrucțiune, rulată a doua oară peste cererea
  // deja acceptată, nu are voie să atingă niciun rând. Merge într-o tranzacție
  // dată înapoi, ca proba să nu lase urme în bază. `order_id = NULL` e acolo ca
  // rândul chiar să se schimbe — altfel ar da zero din întâmplare, fiindcă
  // starea era oricum „acceptata”.
  const binar = (env, folder, exe) => {
    if (process.env[env]) return process.env[env];
    const g = 'C:/laragon/bin/' + folder;
    try {
      const v = fs.readdirSync(g).find((d) => d.startsWith(folder + '-'));
      if (v) return folder === 'mysql' ? `${g}/${v}/bin/${exe}` : `${g}/${v}/${exe}`;
    } catch { /* alt sistem */ }
    return exe.replace('.exe', '');
  };
  let db = null;
  try {
    db = JSON.parse(execFileSync(binar('GP_PHP', 'php', 'php.exe'),
      ['-r', '$c = require ' + CERE_CONFIG + '; echo json_encode($c["db"]);'],
      { cwd: ROOT, encoding: 'utf8' }));
  } catch { /* fără PHP la îndemână rămân doar verificările pe sursă */ }

  if (marcarea && db && BAZA_DE_PROBA.test(db.name)) {
    const valori = ['NULL', 'handled_by', String(reqId)];
    const instr = marcarea.replace(/\?/g, () => valori.shift());
    const atinse = execFileSync(binar('GP_MYSQL', 'mysql', 'mysql.exe'), [
      '--default-character-set=utf8mb4',
      '-u', db.user, ...(db.pass ? ['-p' + db.pass] : []),
      '-h', db.host, '-P', String(db.port), db.name, '-N', '-B',
      '-e', `START TRANSACTION; ${instr}; SELECT ROW_COUNT(); ROLLBACK;`,
    ], { encoding: 'utf8' }).trim();
    check('marcarea repetată a cererii deja acceptate nu atinge niciun rând',
      atinse === '0', `a atins ${atinse} rânduri`);
  } else {
    console.log('  —     sărit: proba pe bază cere PHP, MySQL și o bază de dezvoltare');
  }
}
{
  // Varianta fiecărei poziții trebuie să treacă și peste treapta a doua:
  // altfel clientul cere amestecat, iar laboratorul primește totul într-una.
  const r = await post(admin, `/requests/${mixtId}/accept`, {
    day: '2026-09-03', slot: '06:30', zone: ZONE, fulfillment: 'Livrare',
  });
  check('acceptarea cererii amestecate → 201', r.status === 201,
    `${r.status} ${JSON.stringify(r.data)}`);
  check('comanda păstrează varianta fiecărei poziții',
    (r.data?.order?.items || []).map((i) => i.variant).join(',') === 'congelat,copt',
    JSON.stringify((r.data?.order?.items || []).map((i) => i.variant)));
  check('comanda amestecată e marcată „mixt”', r.data?.order?.variant === 'mixt',
    r.data?.order?.variant);
  check('totalul amestecat s-a păstrat la acceptare',
    r.data?.order?.total === 100 * 2.85 + 100 * 4.69, `${r.data?.order?.total}`);
}
{
  const r = await get(admin, '/requests');
  const req = (r.data?.requests || []).find((x) => x.id === reqId);
  check('cererea e marcată „acceptata”', req?.status === 'acceptata', req?.status);
  check('cererea arată numărul comenzii', req?.orderRef === orderRef, `${req?.orderRef}`);
}

console.log('\n=== comanda e acum în laborator ===');
{
  const r = await get(admin, '/orders?day=2026-09-02');
  const o = (r.data?.orders || []).find((x) => x.id === orderId);
  check('comanda apare pe ziua ei', !!o);
  check('are interval și zonă', o?.slot === SLOT && o?.zone === ZONE, `${o?.slot} / ${o?.zone}`);
  check('are mod de onorare', o?.fulfillment === 'Livrare', o?.fulfillment);
}
{
  const r = await patch(admin, `/orders/${orderId}`, { status: 'La dospit' });
  check('laboratorul avansează etapa → 200', r.status === 200, `a dat ${r.status}`);
  check('etapa s-a salvat', r.data?.order?.status === 'La dospit', r.data?.order?.status);
}
{
  const r = await patch(admin, `/orders/${orderId}`, { status: 'Inventat' });
  check('etapă inexistentă → 422', r.status === 422, `a dat ${r.status}`);
}
{
  const r = await get(admin, '/routes?day=2026-09-02');
  check('comanda apare pe ruta șoferului', (r.data?.stops || []).some((x) => x.id === orderId));
}

console.log('\n=== respingerea unei cereri ===');
{
  const c = await post(anon, '/requests', {
    customerName: 'Client Respins', phone: '0733111222',
    items: [{ productId: 3, qty: 5 }],
  });
  const rid = c.data?.request?.id;
  const r = await post(admin, `/requests/${rid}/reject`, { reason: 'Capacitate depășită pentru ziua respectivă.' });
  check('respingere → 200', r.status === 200, `a dat ${r.status}`);

  const list = await get(admin, '/requests');
  const req = (list.data?.requests || []).find((x) => x.id === rid);
  check('cererea e marcată „respinsa”', req?.status === 'respinsa', req?.status);
  check('motivul e păstrat', /Capacitate/.test(req?.rejectReason || ''), req?.rejectReason);

  const orders = await get(admin, '/orders');
  check('cererea respinsă NU a creat comandă',
    !(orders.data?.orders || []).some((o) => o.requestId === rid));
}

console.log('\n=== clientul cu cont comandă fără să-și mai scrie datele ===');
{
  // Clientul autentificat trimite un formular din care lipsesc numele,
  // telefonul și e-mailul: aplicația le știe deja. Înainte i se cereau din
  // nou, la fiecare comandă, deși tocmai se autentificase.
  const client = agent();
  const l = await post(client, '/auth/login', { username: 'testclient', password: 'Parola-Test-9012' });
  client.csrf = l.data?.csrf || '';
  check('clientul de probă se autentifică', l.status === 200, `a dat ${l.status}`);

  // Contul primește date de contact chiar aici: proba nu trebuie să depindă
  // de ce a lăsat în urmă altă suită.
  const TEL = '0722 111 222';
  const users = await get(admin, '/users');
  const contul = (users.data?.users || []).find((x) => x.username === 'testclient');
  await patch(admin, '/users/' + contul.id, { phone: TEL, email: 'comenzi@cafeneaua-test.ro' });

  const ADRESA = 'Str. Probelor ' + Math.floor(Math.random() * 900 + 100);
  const r = await post(client, '/requests', {
    customerName: '', phone: '', email: '',
    address: ADRESA,
    items: [{ productId: 1, qty: 2 }],
  });
  check('cerere fără date de contact → 201', r.status === 201, `a dat ${r.status} ${JSON.stringify(r.data)}`);

  const req = r.data?.request;
  check('numele vine de pe cont', req?.customerName === 'Cafeneaua Test', req?.customerName);
  check('cererea e legată de contul clientului', Number.isInteger(req?.customerId), String(req?.customerId));
  check('telefonul vine de pe cont', req?.phone === TEL, req?.phone);

  // Adresa rămâne pe cont, ca data viitoare formularul să fie deja completat.
  const me = await get(client, '/auth/me');
  check('adresa rămâne salvată pe cont', me.data?.user?.address === ADRESA, me.data?.user?.address);

  // Numele contului nu poate fi înlocuit din formular: altfel o cerere ar
  // putea intra pe numele altui client.
  const alta = await post(client, '/requests', {
    customerName: 'Altcineva SRL', phone: '0700000000',
    items: [{ productId: 1, qty: 1 }],
  });
  check('numele scris în formular nu suprascrie contul clientului',
    alta.data?.request?.customerName === 'Cafeneaua Test', alta.data?.request?.customerName);
}

console.log('\n=== comanda scrisă de personal rămâne a clientului ===');
{
  // Numele scris de administrator era înlocuit în tăcere cu numele contului
  // lui: comanda ajungea în laborator și pe ruta șoferului drept
  // „Administrator Test", fără client.
  const r = await post(admin, '/orders', {
    customerName: 'Cafeneaua Test', phone: '0722 111 222',
    fulfillment: 'Ridicare',
    items: [{ productId: 1, qty: 1 }],
  });
  check('comandă adăugată de administrator → 201', r.status === 201, `a dat ${r.status}`);
  const o = r.data?.order;
  check('comanda păstrează numele clientului, nu al administratorului',
    o?.customerName === 'Cafeneaua Test', o?.customerName);

  // Un vizitator anonim NU trebuie să poată lega o cerere de contul altcuiva
  // scriind numele lui în formular: clientul acela ar vedea în panoul lui
  // comanda, telefonul și mențiunile unui străin.
  const strain = await post(agent(), '/requests', {
    customerName: 'Cafeneaua Test', phone: '0799888777',
    notes: 'cerere de la un vizitator care a scris numele altcuiva',
    items: [{ productId: 1, qty: 1 }],
  });
  check('cererea unui vizitator anonim nu se leagă de contul altui client',
    strain.data?.request?.customerId === null, String(strain.data?.request?.customerId));

  // Aceeași regulă la cereri: administratorul care trimite o cerere pentru un
  // client păstrează numele clientului, nu îl primește pe al lui.
  const cer = await post(admin, '/requests', {
    customerName: 'Cofetăria Verificare', phone: '0799111222',
    items: [{ productId: 1, qty: 1 }],
  });
  check('cererea scrisă de administrator păstrează numele clientului',
    cer.data?.request?.customerName === 'Cofetăria Verificare', cer.data?.request?.customerName);

  // Și ajunge în istoricul clientului: panoul lui întoarce doar comenzile
  // legate de contul lui, iar una nelegată nu s-ar vedea niciodată acolo.
  const client = agent();
  const l = await post(client, '/auth/login', { username: 'testclient', password: 'Parola-Test-9012' });
  client.csrf = l.data?.csrf || '';
  const mele = await get(client, '/orders');
  check('comanda apare în istoricul clientului',
    (mele.data?.orders || []).some((x) => x.id === o?.id), 'id ' + o?.id);

  // Un client nu vede comenzile altcuiva — filtrul stă în SQL, nu în pagină.
  const straine = (mele.data?.orders || []).filter((x) => x.customerName !== 'Cafeneaua Test');
  check('clientul nu vede comenzile altor clienți', straine.length === 0,
    JSON.stringify(straine.map((x) => x.customerName)));
}

console.log('\n=== clientul află a câta oprire e pe ruta zilei ===');
{
  // Pagina „Comanda mea" nu poate vedea ruta întreagă: GET /routes cere cont de
  // personal, iar GET /orders îi dă clientului doar comenzile lui. Singurul
  // lucru din care își poate afla locul pe ruta zilei este `routeSeq` de pe
  // propria comandă. Dacă răspunsul nu-l mai duce, pagina rămâne fără sursă de
  // adevăr și se întoarce la „Ești următoarea oprire" pentru toată lumea.
  const ZIUA = '2026-09-11';
  const alta = await post(admin, '/orders', {
    customerName: 'Cofetăria Dinaintea Ta', fulfillment: 'Livrare',
    zone: ZONE, slot: SLOT, day: ZIUA, items: [{ productId: 1, qty: 1 }],
  });
  const mea = await post(admin, '/orders', {
    customerName: 'Cafeneaua Test', fulfillment: 'Livrare',
    zone: ZONE, slot: SLOT, day: ZIUA, items: [{ productId: 1, qty: 2 }],
  });
  check('cele două livrări ale zilei s-au creat',
    alta.status === 201 && mea.status === 201, `${alta.status} / ${mea.status}`);

  const ordine = await call(admin, 'PUT', '/routes', {
    day: ZIUA, ids: [alta.data?.order?.id, mea.data?.order?.id],
  });
  check('ordinea opririlor se salvează pe server', ordine.status === 200, `a dat ${ordine.status}`);

  const client = agent();
  const l = await post(client, '/auth/login', { username: 'testclient', password: 'Parola-Test-9012' });
  client.csrf = l.data?.csrf || '';
  const lista = await get(client, '/orders');
  const aMea = (lista.data?.orders || []).find((x) => x.id === mea.data?.order?.id);
  check('comanda clientului îi spune a câta oprire e pe ruta zilei',
    aMea?.routeSeq === 1, String(aMea?.routeSeq));
  check('clientul tot nu primește comanda celui dinaintea lui pe rută',
    !(lista.data?.orders || []).some((x) => x.id === alta.data?.order?.id));
}

console.log('\n=== jurnalul ===');
{
  const r = await get(admin, '/log');
  const acts = (r.data?.entries || []).map((e) => e.action);
  check('cererea e jurnalizată', acts.includes('request_create'));
  check('acceptarea e jurnalizată', acts.includes('request_accept'));
  check('respingerea e jurnalizată', acts.includes('request_reject'));
}

console.log(`\n${'='.repeat(52)}`);
console.log(`${pass} trecute · ${fail} eșuate`);
if (fail) { console.log('\nESUATE:'); for (const f of failures) console.log('  x ' + f); }
console.log();
process.exit(fail ? 1 : 0);
