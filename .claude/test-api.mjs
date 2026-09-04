// Teste end-to-end pentru API, rulate contra serverului local de dezvoltare.
//
//   php -S 127.0.0.1:8100 -t . .claude/dev-server.php
//   node .claude/test-api.mjs
//
// Verifică în special drepturile: un client nu trebuie să vadă comenzile
// altora, iar un angajat nu trebuie să ajungă la Setări. În prototipul de la
// care am pornit, aceste verificări erau în browser și se puteau ocoli.

const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';

let pass = 0, fail = 0;
const failures = [];

function check(name, cond, extra = '') {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
}

/** Sesiune izolată: fiecare „agent" își ține propriile cookie-uri și CSRF. */
/**
 * Sesiune izolată. `ip` cere serverului să considere cererea venită de la altă
 * adresă — merge doar fiindcă în configurarea locală 127.0.0.1 e trecut ca
 * proxy de încredere. Fără asta, probele de limitare ale unei singure rulări
 * ar epuiza pragul pe IP și ar bloca toate testele următoare.
 */
function agent(ip) {
  return { cookies: new Map(), csrf: '', ip: ip || '' };
}

async function call(a, method, path, body) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (a.csrf && method !== 'GET') headers['X-CSRF-Token'] = a.csrf;
  if (a.cookies.size) {
    headers['Cookie'] = [...a.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
  }
  if (a.ip) headers['X-Forwarded-For'] = a.ip;

  const res = await fetch(BASE + path, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
    redirect: 'manual',
  });

  for (const raw of res.headers.getSetCookie?.() ?? []) {
    const [pair] = raw.split(';');
    const i = pair.indexOf('=');
    const k = pair.slice(0, i).trim(), v = pair.slice(i + 1).trim();
    if (v === '' || /expires=Thu, 01 Jan 1970/i.test(raw)) a.cookies.delete(k);
    else a.cookies.set(k, v);
  }

  const raw = await res.text();
  let data = null;
  if (raw !== '') {
    try {
      data = JSON.parse(raw);
    } catch {
      // Nu e JSON valid. Dacă începe cu ceva tipărit de PHP, spunem asta pe
      // față: e o eroare de server, nu un test scris greșit.
      const head = raw.slice(0, 200).replace(/\s+/g, ' ');
      throw new Error('răspuns care nu este JSON la ' + method + ' ' + path + ': ' + head);
    }
  }
  return { status: res.status, data };
}

const get = (a, p) => call(a, 'GET', p);
const post = (a, p, b) => call(a, 'POST', p, b ?? {});
const patch = (a, p, b) => call(a, 'PATCH', p, b ?? {});
const put = (a, p, b) => call(a, 'PUT', p, b ?? {});
const del = (a, p) => call(a, 'DELETE', p);

/* ------------------------------------------------------------ scenariu -- */

const ADMIN = { u: 'testadmin', p: 'Parola-Test-1234' };
const STAFF = { u: 'testangajat', p: 'Parola-Test-5678' };
const CLIENT = { u: 'testclient', p: 'Parola-Test-9012' };

console.log('\n=== public ===');
const anon = agent();
{
  const r = await get(anon, '/products');
  check('GET /products fără autentificare → 200', r.status === 200, `a dat ${r.status}`);
  // Catalogul e editabil, deci numărul de produse nu mai e o constantă.
  // Verificăm în schimb că produsele din instalarea inițială sunt acolo și
  // întregi — asta voia să spună proba cu „22".
  const prods = r.data?.products || [];
  check('catalogul conține produsele din instalare', prods.length >= 22, `are ${prods.length}`);
  const strudel = prods.find((x) => x.slug === 'strudel-cu-mere');
  check('un produs cunoscut are toate câmpurile',
    !!strudel && strudel.nameEn && strudel.description && strudel.priceFrozen > 0 && strudel.grams > 0,
    JSON.stringify(strudel));
  check('fiecare produs are grupă', prods.every((x) => !!x.category),
    JSON.stringify(prods.filter((x) => !x.category).map((x) => x.slug)));
}
{
  const r = await get(anon, '/orders');
  check('GET /orders fără sesiune → 401', r.status === 401, `a dat ${r.status}`);
}
{
  const r = await get(anon, '/settings');
  check('GET /settings fără sesiune → 401', r.status === 401, `a dat ${r.status}`);
}
{
  const r = await get(anon, '/ruta-inexistenta');
  check('rută inexistentă → 404', r.status === 404, `a dat ${r.status}`);
}

{
  // Paginile de instalare nu trec prin dispecerul de rute: sunt fișiere reale
  // în api/, servite direct și de Apache, și de serverul local. De aceea nu se
  // cer cu get(): răspunsul lor e HTML, nu JSON. Pe o instalare pornită — asta
  // e — amândouă trebuie să răspundă ca și cum ar fi fost șterse. Altfel
  // verifica.php spune oricui trece pe acolo ce versiune de PHP și de MySQL
  // rulezi, ce extensii ai, cum se numesc tabelele aplicației și dacă erorile
  // interne pleacă spre browser, iar setup.php confirmă că adresa e o instalare
  // Golden Pastry cu instalatorul încă pe server.
  for (const f of ['/verifica.php', '/setup.php']) {
    const res = await fetch(BASE + f, { redirect: 'manual' });
    const body = await res.text();
    check(`GET ${f} pe o instalare cu administrator → 404`, res.status === 404, `a dat ${res.status}`);
    check(`GET ${f} nu mai trimite nimic în pagină`, body.trim() === '',
      body.slice(0, 120).replace(/s+/g, ' '));
  }
}

console.log('\n=== autentificare ===');
const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: ADMIN.u, password: ADMIN.p });
  check('autentificare corectă → 200', r.status === 200, `a dat ${r.status} ${JSON.stringify(r.data)}`);
  admin.csrf = r.data?.csrf || '';
  check('răspunsul conține token CSRF', admin.csrf.length === 64);
  check('rolul este admin', r.data?.user?.role === 'admin', r.data?.user?.role);
  check('parola nu apare în răspuns', !JSON.stringify(r.data || {}).includes(ADMIN.p));
  check('hash-ul parolei nu apare în răspuns', !/password_hash|\$2y\$/.test(JSON.stringify(r.data || {})));
}
{
  // Proba cu parolă greșită se face pe un cont creat acum și șters imediat.
  //
  // Se făcea pe contul de administrator, iar limitarea numără încercările
  // eșuate pe utilizator, în ultimele 15 minute. După opt rulări ale suitei,
  // testele își blocau singure contul cu care lucrau, iar tot restul pica —
  // exact tiparul pe care limitarea trebuie să-l prindă la un atacator.
  const tmpUser = 'proba.parola.' + Math.random().toString(36).slice(2, 8);
  const made = await post(admin, '/users', {
    username: tmpUser, name: 'Probă parolă', role: 'angajat', password: 'Proba-Temporara-9911',
  });
  check('cont temporar creat pentru proba de parolă', made.status === 201 || made.status === 200,
    `a dat ${made.status} ${JSON.stringify(made.data)}`);

  const anon2 = agent();
  const bad = await post(anon2, '/auth/login', { username: tmpUser, password: 'parola-gresita' });
  check('parolă greșită → 401', bad.status === 401, `a dat ${bad.status}`);
  check('mesajul nu dezvăluie dacă utilizatorul există',
    /utilizator sau parol/i.test(bad.data?.error?.message || ''), bad.data?.error?.message);

  // Proba afirma ștergerea în nume, dar verifica doar `!!id` — adică faptul că
  // avem numărul contului de la creare, adevărat orice ar fi răspuns serverul.
  // Răspunsul lui del() se arunca. O rută de ștergere complet stricată trecea
  // verde, iar contul rămânea în bază. Acum citim și răspunsul, și lista.
  const id = made.data?.id ?? made.data?.user?.id;
  const sters = id ? await del(admin, '/users/' + id) : null;
  const dupa = await get(admin, '/users');
  const maiExista = (dupa.data?.users || []).some((u) => u.username === tmpUser);
  check('contul temporar a fost șters',
    sters?.status === 200 && sters?.data?.ok === true && !maiExista,
    id
      ? `ștergerea a dat ${sters.status} ${JSON.stringify(sters.data)}`
        + `; contul ${maiExista ? 'e încă în listă' : 'nu mai e în listă'}`
      : `nu s-a primit id la creare: ${JSON.stringify(made.data)}`);
}
{
  const r = await get(admin, '/auth/me');
  check('GET /auth/me cu sesiune → utilizatorul', r.data?.user?.username === ADMIN.u, JSON.stringify(r.data));

  // Pagina de intrare scrie „sesiunea rămâne deschisă N zile". Numărul vine de
  // aici, nu din pagină: dacă dispare, textul ar minți în tăcere.
  check(
    'GET /auth/me spune câte zile ține sesiunea',
    Number.isInteger(r.data?.sessionDays) && r.data.sessionDays > 0,
    JSON.stringify(r.data?.sessionDays)
  );
}

console.log('\n=== CSRF ===');
{
  const noCsrf = { cookies: admin.cookies, csrf: '' };
  const r = await post(noCsrf, '/orders', { customerName: 'X', items: [] });
  check('POST autentificat fără token CSRF → 403', r.status === 403, `a dat ${r.status}`);
  check('codul erorii este bad_csrf', r.data?.error?.code === 'bad_csrf', r.data?.error?.code);
}

// Suita trebuie să poată rula de câte ori e nevoie: ștergem conturile lăsate
// de rulările anterioare, altfel crearea lor ar da 409.
{
  const r = await get(admin, '/users');
  const stale = (r.data?.users || []).filter((u) => u.username !== ADMIN.u && u.username.startsWith('test'));
  for (const u of stale) await del(admin, `/users/${u.id}`);
  if (stale.length) console.log(`  (curățat ${stale.length} conturi de test din rularea anterioară)`);
}

console.log('\n=== conturi ===');
let staffId = null, clientId = null;
{
  const r = await post(admin, '/users', { username: STAFF.u, password: STAFF.p, name: 'Angajat Test', role: 'angajat' });
  check('admin poate crea un angajat → 201', r.status === 201, `${r.status} ${JSON.stringify(r.data)}`);
  staffId = r.data?.user?.id;
}
{
  const r = await post(admin, '/users', {
    username: CLIENT.u, password: CLIENT.p, name: 'Client Test',
    role: 'client', customerName: 'Cafeneaua Test',
    // Un cont de client are date de contact: de pe ele completează site-ul
    // formularul de comandă, ca omul să nu le mai scrie de fiecare dată.
    phone: '0722 111 222', email: 'comenzi@cafeneaua-test.ro',
  });
  check('admin poate crea un client → 201', r.status === 201, `${r.status} ${JSON.stringify(r.data)}`);
  clientId = r.data?.user?.id;
  check('contul de client păstrează telefonul', r.data?.user?.phone === '0722 111 222', r.data?.user?.phone);
}
{
  const r = await post(admin, '/users', { username: STAFF.u, password: 'Alta-Parola-123', name: 'Dublu', role: 'angajat' });
  check('utilizator duplicat → 409', r.status === 409, `a dat ${r.status}`);
}
{
  const r = await post(admin, '/users', { username: 'x', password: 'scurt', name: 'N', role: 'angajat' });
  check('utilizator prea scurt / parolă slabă → 422', r.status === 422, `a dat ${r.status}`);
}
{
  const r = await post(admin, '/users', { username: 'valid.user', password: 'Parola-Buna-123', name: 'N', role: 'rege' });
  check('rol inexistent → 422', r.status === 422, `a dat ${r.status}`);
}

// Contul făcut de administrator primește o parolă provizorie, iar serverul îl
// ține pe loc până când omul își alege singur alta (api/lib/auth.php:100).
// Suita lucrează mai departe cu conturile astea, deci trece prin schimbarea de
// parolă exact cum o face angajatul adevărat în prima lui zi — și verifică, din
// drum, că regula chiar se aplică pe server, nu doar în pagina de intrare.
console.log('\n=== parola provizorie a contului nou ===');
async function treciDeParolaProvizorie(cine, u, p) {
  const a = agent();
  const intrare = await post(a, '/auth/login', { username: u, password: p });
  a.csrf = intrare.data?.csrf || '';
  check(`${cine}: contul nou vine cu parolă provizorie`,
    intrare.data?.user?.mustChangePassword === true,
    String(intrare.data?.user?.mustChangePassword));

  const blocat = await get(a, '/orders');
  check(`${cine}: până își schimbă parola, serverul îl oprește → 403`,
    blocat.status === 403 && blocat.data?.error?.code === 'must_change_password',
    `${blocat.status} ${JSON.stringify(blocat.data?.error?.code)}`);

  const sch = await post(a, '/auth/password', { currentPassword: p, newPassword: p });
  check(`${cine}: își schimbă parola → 200`, sch.status === 200,
    `${sch.status} ${JSON.stringify(sch.data)}`);

  // Schimbarea închide celelalte sesiuni ale contului, deci se intră din nou.
  const b = agent();
  const dupa = await post(b, '/auth/login', { username: u, password: p });
  b.csrf = dupa.data?.csrf || '';
  check(`${cine}: steagul a căzut după schimbare`,
    dupa.data?.user?.mustChangePassword === false,
    String(dupa.data?.user?.mustChangePassword));
  const acum = await get(b, '/orders');
  check(`${cine}: de aici încolo aplicația i se deschide`, acum.status === 200,
    `a dat ${acum.status}`);
}
await treciDeParolaProvizorie('angajatul', STAFF.u, STAFF.p);
await treciDeParolaProvizorie('clientul', CLIENT.u, CLIENT.p);

console.log('\n=== drepturi: angajat ===');
const staff = agent();
{
  const r = await post(staff, '/auth/login', { username: STAFF.u, password: STAFF.p });
  staff.csrf = r.data?.csrf || '';
  check('angajatul se autentifică', r.status === 200, `a dat ${r.status}`);
}
{
  const r = await get(staff, '/settings');
  check('angajat la /settings → 403', r.status === 403, `a dat ${r.status}`);
}
{
  const r = await get(staff, '/users');
  check('angajat la /users → 403', r.status === 403, `a dat ${r.status}`);
}
{
  const r = await get(staff, '/orders');
  check('angajat poate vedea comenzile → 200', r.status === 200, `a dat ${r.status}`);
}

{
  // Ecranul „Administrare Comenzi” se închide pentru orice cont care nu e
  // administrator („nu are acces la comenzi”), dar serverul lăsa acceptarea și
  // respingerea cererilor pe seama întregului personal. Cum contul comun al
  // tabletelor are rol de angajat și se deschide cu un cod știut de toată tura,
  // oricine din tură putea respinge din consolă cererea unui client — pierdută
  // definitiv, fiindcă o cerere respinsă nu mai poate fi acceptată — sau o
  // putea accepta pe altă zi decât cea cerută, iar jurnalul îl trecea pe autor
  // doar ca „Panou comun”, deci nu se putea afla cine a apăsat.
  const cer = await post(anon, '/requests', {
    customerName: 'Probă drepturi angajat', phone: '0733999888',
    items: [{ productId: 1, qty: 1 }],
  });
  const rid = cer.data?.request?.id;
  check('cererea de probă s-a creat → 201', cer.status === 201, `a dat ${cer.status}`);

  const resp = await post(staff, `/requests/${rid}/reject`, { reason: 'trimis din consolă' });
  check('angajatul NU poate respinge o cerere → 403', resp.status === 403, `a dat ${resp.status}`);

  const acc = await post(staff, `/requests/${rid}/accept`, { day: '2026-10-15' });
  check('angajatul NU poate accepta o cerere → 403', acc.status === 403, `a dat ${acc.status}`);

  // Cererea trebuie să fie tot în așteptare: dacă una dintre scrieri trecea,
  // administratorul nu o mai putea accepta niciodată.
  const nou = await get(admin, '/requests?status=nou');
  check('cererea a rămas în așteptare pentru administrator',
    (nou.data?.requests || []).some((x) => x.id === rid), 'id ' + rid);

  // Curățăm după noi: altfel coada de cereri se umple cu câte o intrare la
  // fiecare rulare și panoul administratorului devine de necitit.
  if (rid) await post(admin, `/requests/${rid}/reject`, { reason: 'cerere de probă' });

  // Redeschiderea anulează hotărârea administratorului, deci ține tot de el.
  // Altfel ușa rămânea deschisă pe dos: angajatul n-ar mai fi putut respinge o
  // cerere, dar ar fi putut readuce în coadă orice cerere respinsă de
  // administrator, iar acesta ar fi găsit-o din nou acolo fără să știe de ce.
  const redesc = await post(staff, `/requests/${rid}/reopen`);
  check('angajatul NU poate redeschide o cerere respinsă → 403', redesc.status === 403,
    `a dat ${redesc.status}`);
  const inCoada = (await get(admin, '/requests?status=nou')).data?.requests || [];
  check('cererea respinsă a rămas respinsă', !inCoada.some((x) => x.id === rid), 'id ' + rid);
}

console.log('\n=== comenzi ===');
// Contul de client se deschide aici, nu mai jos: ne trebuie ca să arătăm că
// nici el nu poate scrie direct în comenzi — doar cereri.
const client = agent();
{
  const r = await post(client, '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  client.csrf = r.data?.csrf || '';
  check('clientul se autentifică', r.status === 200, `a dat ${r.status}`);
}
let orderId = null, orderSeq = null;
{
  // Ruta era deschisă oricui, „fiindcă site-ul public trebuie să poată primi
  // comenzi". Nu era adevărat: site-ul trimite POST /requests, iar cererea
  // devine comandă abia după aprobare. Cât a fost deschisă, oricine de pe
  // internet putea băga marfă direct în laborator, sărind peste aprobare.
  const r = await post(anon, '/orders', {
    customerName: 'Client Anonim', phone: '0722334455',
    items: [{ productId: 1, qty: 3 }],
  });
  check('comandă direct în laborator, fără cont → 401', r.status === 401,
    `${r.status} ${JSON.stringify(r.data)}`);

  const c = await post(client, '/orders', {
    customerName: 'Client Anonim', items: [{ productId: 1, qty: 3 }],
  });
  check('nici clientul autentificat nu bagă comenzi direct → 403', c.status === 403,
    `${c.status} ${JSON.stringify(c.data)}`);
}
{
  const r = await post(staff, '/orders', {
    customerName: 'Client Anonim', phone: '0722334455',
    items: [{ productId: 1, qty: 3 }, { productId: 7, qty: 2 }],
  });
  check('personalul scrie comanda luată la telefon → 201', r.status === 201, `${r.status} ${JSON.stringify(r.data)}`);
  orderId = r.data?.order?.id;
  orderSeq = r.data?.order?.seq;
  // prod 1 retail 3.49 × 3 + prod 7 retail 3.99 × 2 = 10.47 + 7.98 = 18.45
  check('totalul e calculat din prețurile din BD', r.data?.order?.total === 18.45, `a dat ${r.data?.order?.total}`);
  check('comanda are 2 poziții', r.data?.order?.items?.length === 2);
}
{
  const r = await post(staff, '/orders', {
    customerName: 'Hacker', phone: '0722000000',
    items: [{ productId: 1, qty: 1, unitPrice: 0.01 }],
  });
  check('prețul trimis de client este ignorat', r.data?.order?.total === 3.49, `a dat ${r.data?.order?.total}`);
}
{
  const r = await post(staff, '/orders', { customerName: 'Fără produse', phone: '0722', items: [] });
  check('comandă goală → 422', r.status === 422, `a dat ${r.status}`);
}
{
  const r = await post(staff, '/orders', {
    customerName: 'Produs inexistent', phone: '0722',
    items: [{ productId: 99999, qty: 1 }],
  });
  check('produs inexistent → 422', r.status === 422, `a dat ${r.status}`);
}
{
  // Formularul fără nume nu era refuzat: se punea tăcut numele celui
  // autentificat, iar comanda ajungea în laborator drept „Administrator Test".
  const r = await post(staff, '/orders', { items: [{ productId: 1, qty: 1 }] });
  check('comandă fără numele clientului → 422', r.status === 422,
    `a dat ${r.status} ${JSON.stringify(r.data?.order?.customerName)}`);
}
{
  // Un câmp mai lung decât coloana pica pe excepție PDO, adică „Eroare
  // internă" în fața omului, cu comanda pierdută.
  const r = await post(staff, '/orders', {
    customerName: 'A'.repeat(300), phone: '0722',
    items: [{ productId: 1, qty: 1 }],
  });
  check('nume mai lung decât coloana → 422, nu 500', r.status === 422, `a dat ${r.status}`);
  check('mesajul spune ce câmp și cât încape',
    /prea lung/.test(r.data?.error?.message || '') && /160/.test(r.data?.error?.message || ''),
    r.data?.error?.message);

  const a = await post(staff, '/orders', {
    customerName: 'Adresă lungă', address: 'B'.repeat(400),
    items: [{ productId: 1, qty: 1 }],
  });
  check('adresă mai lungă decât coloana → 422, nu 500', a.status === 422, `a dat ${a.status}`);
}
{
  // Zona nu se verifica la comenzi, deși se verifica la cereri: o zonă
  // inexistentă trecea, iar ruta o punea pe coordonatele depozitului.
  const r = await post(staff, '/orders', {
    customerName: 'Zonă inventată', zone: 'Centru',
    items: [{ productId: 1, qty: 1 }],
  });
  check('zonă inexistentă la creare → 422', r.status === 422, `a dat ${r.status}`);
}
{
  // Bifa URGENT din formularul de comandă nouă se pierdea: creaea nu accepta
  // câmpul, iar comanda intra în laborator ca una obișnuită, adică la coadă.
  const r = await post(staff, '/orders', {
    customerName: 'Comandă urgentă', urgent: true, timed: true,
    items: [{ productId: 1, qty: 1 }],
  });
  check('URGENT pus la creare se păstrează', r.data?.order?.urgent === true,
    `urgent=${r.data?.order?.urgent}`);
  check('„la oră fixă" pus la creare se păstrează', r.data?.order?.timed === true,
    `timed=${r.data?.order?.timed}`);
}
{
  const r = await patch(staff, `/orders/${orderId}`, { status: 'La dospit' });
  check('angajatul schimbă statusul → 200', r.status === 200, `a dat ${r.status}`);
  check('statusul s-a salvat', r.data?.order?.status === 'La dospit', r.data?.order?.status);
}
{
  const r = await patch(staff, `/orders/${orderId}`, { status: 'inventat' });
  check('status inexistent → 422', r.status === 422, `a dat ${r.status}`);
}

console.log('\n=== drepturi: client vede doar comenzile lui ===');
{
  // Prețul ține de VARIANTA aleasă — congelat sau gata de servire — nu de
  // faptul că cel care comandă are cont. Regula veche („cu cont = preț
  // HoReCa") venea dintr-o citire greșită a catalogului de pe site și făcea
  // ca vizitatorul să vadă o sumă pe ecran și cererea să se înregistreze cu
  // alta.
  const prod = (await get(anon, '/products')).data.products.find((x) => x.id === 2);
  check('produsul de probă are ambele prețuri',
    prod && prod.priceFrozen > 0 && prod.priceBaked > 0, JSON.stringify(prod));

  const CL = { customerName: 'Cafeneaua Test' };

  const rece = await post(staff, '/orders', { ...CL, variant: 'congelat', items: [{ productId: 2, qty: 5 }] });
  check('comanda „congelat" → 201', rece.status === 201, `${rece.status} ${JSON.stringify(rece.data)}`);
  check('se folosește prețul pentru congelat',
    rece.data?.order?.total === Math.round(prod.priceFrozen * 5 * 100) / 100,
    `a dat ${rece.data?.order?.total}, așteptam ${prod.priceFrozen * 5}`);
  check('varianta e reținută pe comandă', rece.data?.order?.variant === 'congelat',
    rece.data?.order?.variant);
  check('varianta e reținută și pe poziție',
    rece.data?.order?.items?.[0]?.variant === 'congelat',
    JSON.stringify(rece.data?.order?.items?.[0]));

  const copt = await post(staff, '/orders', { ...CL, variant: 'copt', items: [{ productId: 2, qty: 5 }] });
  check('se folosește prețul pentru gata de servire',
    copt.data?.order?.total === Math.round(prod.priceBaked * 5 * 100) / 100,
    `a dat ${copt.data?.order?.total}, așteptam ${prod.priceBaked * 5}`);

  const fara = await post(staff, '/orders', { ...CL, items: [{ productId: 2, qty: 5 }] });
  check('fără variantă se folosește „gata de servire", ca pe site',
    fara.data?.order?.variant === 'copt' && fara.data?.order?.total === copt.data?.order?.total,
    `${fara.data?.order?.variant} / ${fara.data?.order?.total}`);

  // Înainte, o variantă scrisă greșit — de pildă „frozen", cum arată cheile
  // din coșul de pe site — cădea tăcut pe „copt". Comanda se înregistra la
  // celălalt preț și pleca altă marfă, fără ca cineva să afle.
  const aiurea = await post(staff, '/orders', { ...CL, variant: 'nu-exista', items: [{ productId: 2, qty: 5 }] });
  check('variantă necunoscută → 422, nu corectată în tăcere', aiurea.status === 422,
    `a dat ${aiurea.status}, variant=${aiurea.data?.order?.variant}`);
  const engleza = await post(staff, '/orders', { ...CL, variant: 'frozen', items: [{ productId: 2, qty: 5 }] });
  check('„frozen" (cheia din coș) e refuzat, nu tratat ca „copt"', engleza.status === 422,
    `a dat ${engleza.status}`);

  // Coșul de pe site ține cheile separat — `frozen:3` și `baked:3` — deci
  // clientul poate pune același produs în ambele variante. Spre server pleca o
  // singură variantă pentru tot coșul: pe ecran scria o sumă, în comandă se
  // scria alta, iar laboratorul primea altă marfă.
  const mixt = await post(staff, '/orders', {
    ...CL,
    items: [
      { productId: 2, qty: 5, variant: 'congelat' },
      { productId: 2, qty: 2, variant: 'copt' },
    ],
  });
  const asteptat = Math.round((prod.priceFrozen * 5 + prod.priceBaked * 2) * 100) / 100;
  check('coș cu ambele variante → 201', mixt.status === 201, `${mixt.status} ${JSON.stringify(mixt.data)}`);
  check('fiecare poziție se plătește la prețul variantei ei',
    mixt.data?.order?.total === asteptat,
    `a dat ${mixt.data?.order?.total}, așteptam ${asteptat}`);
  check('pozițiile își păstrează fiecare varianta',
    mixt.data?.order?.items?.map((i) => i.variant).join(',') === 'congelat,copt',
    JSON.stringify(mixt.data?.order?.items?.map((i) => i.variant)));
  check('comanda cu variante amestecate e marcată „mixt"',
    mixt.data?.order?.variant === 'mixt', mixt.data?.order?.variant);

  const pozitieAiurea = await post(staff, '/orders', {
    ...CL, items: [{ productId: 2, qty: 1, variant: 'caldut' }],
  });
  check('variantă necunoscută pe o poziție → 422', pozitieAiurea.status === 422,
    `a dat ${pozitieAiurea.status}`);
}
{
  const r = await get(client, '/orders');
  const mine = r.data?.orders || [];
  check('clientul vede doar comenzile lui',
    mine.length > 0 && mine.every((o) => o.customerId === clientId),
    `a primit ${mine.length} comenzi, id-uri client: ${[...new Set(mine.map((o) => o.customerId))].join(',')}`);
}
{
  const r = await get(client, `/orders/${orderId}`);
  check('clientul NU poate citi comanda altcuiva → 403', r.status === 403, `a dat ${r.status}`);
}
{
  const r = await patch(client, `/orders/${orderId}`, { status: 'gata' });
  check('clientul NU poate schimba statusul → 403', r.status === 403, `a dat ${r.status}`);
}

console.log('\n=== setări ===');
{
  const r = await get(admin, '/settings');
  check('adminul citește setările → 200', r.status === 200, `a dat ${r.status}`);
  // Masca este intenționat „••••••••" + ultimele 4 caractere, ca adminul să
  // distingă ce token e configurat fără ca valoarea să fie utilizabilă.
  const tok = r.data?.settings?.notify_whatsapp_token ?? '';
  check('tokenul de WhatsApp nu este returnat în clar',
    tok === '' || (tok.startsWith('••••') && tok.length <= 12 && !tok.includes('SECRET')),
    JSON.stringify(tok));
}
{
  const r = await call(admin, 'PUT', '/settings', { notify_email_to: 'nu-e-email' });
  check('e-mail invalid la salvare → 422', r.status === 422, `a dat ${r.status}`);
}
{
  const r = await call(admin, 'PUT', '/settings', { notify_whatsapp_token: 'SECRET-TOKEN-ABC123' });
  check('salvarea unui token → 200', r.status === 200, `a dat ${r.status}`);
  const masked = r.data?.settings?.notify_whatsapp_token || '';
  check('tokenul revine mascat, nu în clar', !masked.includes('SECRET-TOKEN'), masked);
  check('mascarea păstrează ultimele 4 caractere', masked.endsWith('C123'), masked);
}
{
  // Câmp gol la salvare NU trebuie să șteargă tokenul deja configurat.
  await call(admin, 'PUT', '/settings', { notify_whatsapp_token: '' });
  const r = await get(admin, '/settings');
  check('un câmp gol nu șterge tokenul existent',
    r.data?.settings?.notify_whatsapp_token_set === true,
    JSON.stringify(r.data?.settings?.notify_whatsapp_token_set));
}

console.log('\n=== depozit ===');
let itemId = null;
{
  const r = await get(admin, '/stock/items');
  const items = r.data?.items || [];
  itemId = items.find((i) => /Făină 650/.test(i.name))?.id;
  check('articolele de depozit se încarcă → 200', r.status === 200, `a dat ${r.status}`);
  check('există cele trei categorii',
    ['Materii prime', 'Produse finite', 'Ambalaje'].every((c) => items.some((i) => i.category === c)),
    [...new Set(items.map((i) => i.category))].join(', '));
  check('produsele finite sunt legate de catalogul real',
    items.some((i) => i.category === 'Produse finite' && i.name === 'Ștrudel cu mere' && i.productId));
  check('articolele demo din prototip nu au fost importate',
    !items.some((i) => /Kouign-amann|Pain au chocolat/.test(i.name)));
}
{
  const before = (await get(admin, '/stock/items')).data.items.find((i) => i.id === itemId).qty;
  const r = await post(admin, '/stock/moves', { itemId, qty: 25, direction: 1, reason: 'Recepție' });
  check('intrare de stoc → 201', r.status === 201, `a dat ${r.status}`);
  const after = (await get(admin, '/stock/items')).data.items.find((i) => i.id === itemId).qty;
  check('stocul se recalculează din mișcări', after === before + 25, `${before} → ${after}`);

  await post(admin, '/stock/moves', { itemId, qty: 10, direction: -1, reason: 'Consum' });
  const final = (await get(admin, '/stock/items')).data.items.find((i) => i.id === itemId).qty;
  check('ieșirea scade stocul', final === before + 15, `${after} → ${final}`);
}
{
  const r = await post(admin, '/stock/moves', { itemId, qty: -5, direction: 1 });
  check('cantitate negativă → 422', r.status === 422, `a dat ${r.status}`);
  const r2 = await post(admin, '/stock/moves', { itemId: 999999, qty: 1, direction: 1 });
  check('articol inexistent → 422', r2.status === 422, `a dat ${r2.status}`);
}
{
  const r = await patch(admin, `/stock/items/${itemId}`, { minQty: 120 });
  check('pragul minim se salvează → 200', r.status === 200, `a dat ${r.status}`);
  const it = (await get(admin, '/stock/items')).data.items.find((i) => i.id === itemId);
  check('articolul sub prag este marcat', it.minQty === 120 && it.low === (it.qty < 120), `${it.qty} / ${it.minQty} / low=${it.low}`);
}
{
  const r = await get(staff, '/stock/items');
  check('angajatul poate vedea depozitul → 200', r.status === 200, `a dat ${r.status}`);
  const r2 = await get(anon, '/stock/items');
  check('depozitul nu e public → 401', r2.status === 401, `a dat ${r2.status}`);
}

console.log('\n=== limitarea numără greșelile consecutive ===');
{
  // Un cont de unică folosință, ca proba să nu atingă conturile reale.
  const u = 'proba.limita.' + Math.random().toString(36).slice(2, 8);
  const made = await post(admin, '/users', {
    username: u, name: 'Probă limită', role: 'angajat', password: 'Proba-Limita-7788',
  });
  const id = made.data?.id ?? made.data?.user?.id;
  check('cont temporar pentru proba de limitare', !!id, JSON.stringify(made.data));

  // Adresă proprie pentru proba asta: altfel cele 22 de greșeli ale ei ar
  // consuma pragul pe IP și ar bloca restul suitei.
  const a = agent('203.0.113.' + (1 + Math.floor(Math.random() * 250)));
  // Șapte greșeli: sub pragul de opt.
  for (let i = 0; i < 7; i++) {
    await post(a, '/auth/login', { username: u, password: 'gresit' + i });
  }
  const ok1 = await post(a, '/auth/login', { username: u, password: 'Proba-Limita-7788' });
  check('după șapte greșeli, parola corectă încă intră', ok1.status === 200, `a dat ${ok1.status}`);

  // Intrarea reușită trebuie să fi șters greșelile: încă șapte nu blochează.
  //
  // Fără ștergere, limitarea numără pe fereastră de timp, iar greșelile
  // răzlețe ale unei dimineți întregi se adună până închid contul, deși de
  // fiecare dată cineva intrase corect imediat după.
  for (let i = 0; i < 7; i++) {
    await post(a, '/auth/login', { username: u, password: 'iar-gresit' + i });
  }
  const ok2 = await post(a, '/auth/login', { username: u, password: 'Proba-Limita-7788' });
  check('greșelile se numără de la zero după fiecare intrare reușită',
    ok2.status === 200, `a dat ${ok2.status}`);

  // Dar opt greșeli la rând, fără nicio reușită între ele, chiar blochează.
  for (let i = 0; i < 8; i++) {
    await post(a, '/auth/login', { username: u, password: 'consecutiv' + i });
  }
  const gata = await post(a, '/auth/login', { username: u, password: 'Proba-Limita-7788' });
  check('opt greșeli la rând blochează contul → 429', gata.status === 429, `a dat ${gata.status}`);

  if (id) await del(admin, '/users/' + id);
}

console.log('\n=== poarta panoului (cod comun pentru tablete) ===');
// Codul nu mai are o valoare implicită: instalatorul generează una aleatoare
// și o arată o singură dată. Testul își pune singur un cod, ca să nu depindă
// de ce a rămas în baza de date — și ca să nu existe nicăieri un cod „știut".
const COD_PANOU = 'p' + Math.random().toString(36).slice(2, 9);
{
  const s = await put(admin, '/settings', { settings: { panel_code: COD_PANOU } });
  check('codul tabletelor se pune din Setări → 200', s.status === 200, `a dat ${s.status}`);
}
{
  const r = await post(anon, '/auth/panel', { code: 'cod-gresit-oarecare' });
  check('cod greșit → 401', r.status === 401, `a dat ${r.status}`);
}
{
  const panel = agent();
  const r = await post(panel, '/auth/panel', { code: COD_PANOU });
  panel.csrf = r.data?.csrf || '';
  check('cod corect → 200', r.status === 200, `a dat ${r.status}`);
  check('sesiunea rulează pe contul de sistem „panou”', r.data?.user?.username === 'panou', r.data?.user?.username);

  const orders = await get(panel, '/orders');
  check('panoul vede comenzile → 200', orders.status === 200, `a dat ${orders.status}`);

  for (const [ep, label] of [['/settings', 'setări'], ['/users', 'conturi'], ['/log', 'jurnal']]) {
    const x = await get(panel, ep);
    check(`panoul NU ajunge la ${label} → 403`, x.status === 403, `a dat ${x.status}`);
  }
}
{
  const r = await get(admin, '/settings');
  const c = r.data?.settings?.panel_code ?? '';
  // Codul e scurt: masca nu trebuie să lase vizibil niciun caracter din el.
  check('codul panoului nu se poate citi din Setări',
    /^[•]+$/.test(c) && !c.includes(COD_PANOU), JSON.stringify(c));
  check('Setările spun totuși că este configurat', r.data?.settings?.panel_code_set === true);
}
{
  const users = await get(admin, '/users');
  const panou = (users.data?.users || []).find((u) => u.username === 'panou');
  const r = await del(admin, `/users/${panou.id}`);
  check('contul de sistem nu poate fi șters → 422', r.status === 422, `a dat ${r.status}`);
  const r2 = await patch(admin, `/users/${panou.id}`, { active: false });
  check('contul de sistem nu poate fi dezactivat → 422', r2.status === 422, `a dat ${r2.status}`);
}

{
  // Codul se schimbă „când pleacă cineva din echipă". Dacă tabletele deja
  // deschise rămân deschise, schimbarea nu oprește nimic timp de o săptămână,
  // cât ține sesiunea — adică exact omul de care te fereai vede mai departe
  // fluxul comenzilor.
  const tableta = agent();
  const intra = await post(tableta, '/auth/panel', { code: COD_PANOU });
  tableta.csrf = intra.data?.csrf || '';
  check('tableta e înăuntru înainte de schimbare', intra.status === 200, `a dat ${intra.status}`);

  const altCod = 'p' + Math.random().toString(36).slice(2, 9);
  await put(admin, '/settings', { settings: { panel_code: altCod } });
  const dupa = await get(tableta, '/orders');
  check('schimbarea codului scoate tabletele deja deschise → 401', dupa.status === 401, `a dat ${dupa.status}`);

  const cuNoul = agent();
  const iar = await post(cuNoul, '/auth/panel', { code: altCod });
  cuNoul.csrf = iar.data?.csrf || '';
  check('codul nou deschide panoul → 200', iar.status === 200, `a dat ${iar.status}`);

  // La fel la „Dezactivat": înainte închidea doar poarta, nu și ce era deschis.
  await put(admin, '/settings', { settings: { panel_enabled: false } });
  const inchis = await get(cuNoul, '/orders');
  check('oprirea accesului cu cod scoate tabletele deschise → 401', inchis.status === 401, `a dat ${inchis.status}`);

  await put(admin, '/settings', { settings: { panel_enabled: true, panel_code: COD_PANOU } });
}

console.log('\n=== poarta panoului: blocarea nu se poate arunca de la distanță ===');
{
  // Codul tabletelor e comun, deci toate greșelile se scriu sub același
  // pseudo-utilizator „panou". Numărate global, cele douăzeci de încercări se
  // puteau consuma de oriunde de pe internet — /auth/panel n-are parolă — și
  // atunci bucătarul tasta codul bun și primea „Prea multe încercări", cu
  // aluatul pe masă. Adresele de aici sunt din intervalele rezervate pentru
  // documentație și se schimbă la fiecare rulare, ca proba să nu-și blocheze
  // singură rulările următoare.
  const PRAG = 20;   // 'max_panel_attempts' din api/config.php
  const strain = agent('203.0.113.' + (1 + Math.floor(Math.random() * 250)));
  for (let i = 0; i < PRAG; i++) {
    await post(strain, '/auth/panel', { code: 'ghicesc' + i });
  }
  const oprit = await post(strain, '/auth/panel', { code: 'inca-o-data' });
  check('conexiunea care ghicește codul se blochează pe ea însăși → 429',
    oprit.status === 429, `a dat ${oprit.status}`);

  const tableta = agent('198.51.100.' + (1 + Math.floor(Math.random() * 250)));
  const intra = await post(tableta, '/auth/panel', { code: COD_PANOU });
  check('tableta din laborator deschide panoul mai departe → 200',
    intra.status === 200,
    `a dat ${intra.status} — greșelile de pe altă conexiune închid panoul pentru toată echipa`);

  // Și în sens invers: deschiderea legitimă nu are voie să șteargă greșelile
  // celui de afară, altfel fiecare tabletă care intră îi dă drumul de la capăt.
  const iar = await post(strain, '/auth/panel', { code: 'inca-o-data' });
  check('o deschidere legitimă nu deblochează conexiunea care ghicește → 429',
    iar.status === 429, `a dat ${iar.status}`);
}

console.log('\n=== conturi: numele și contul de sistem ===');
{
  const lista = (await get(admin, '/users')).data?.users || [];
  const panou = lista.find((u) => u.username === 'panou');
  const om = lista.find((u) => u.username === ADMIN.u);
  // Fără steagul ăsta pagina Conturi nu are cum să știe că rândul „Panou
  // comun" e altfel și îi arată butonul „Generează" ca oricărui alt cont.
  check('lista spune care cont e de sistem', panou?.isSystem === true, JSON.stringify(panou?.isSystem));
  check('conturile obișnuite nu sunt marcate de sistem', om?.isSystem === false, JSON.stringify(om?.isSystem));

  // Parola pusă aici nu deschidea nimic — panoul se deschide exclusiv cu codul
  // — dar ștergea sesiunile, adică scotea toate tabletele afară în plin lucru.
  const r = await post(admin, `/users/${panou.id}/password`, { password: 'Parola-Degeaba-9988' });
  check('contul de sistem nu primește parolă → 422', r.status === 422, `a dat ${r.status} ${r.data?.error?.code}`);
}
{
  const tag = Math.random().toString(36).slice(2, 7);
  const creat = await post(admin, '/users', {
    username: 'proba.nume.' + tag, name: 'Proba Nume', password: 'Parola-Proba-4455', role: 'angajat',
  });
  const id = creat.data?.user?.id;
  check('contul de probă se creează → 201', creat.status === 201, `a dat ${creat.status}`);

  // `users.name` e NOT NULL: golit, ajungea NULL în UPDATE și ieșea cu
  // „Eroare internă." — adică ștergerea numelui ca să-l rescrii arăta ca o
  // defecțiune a aplicației.
  const gol = await patch(admin, '/users/' + id, { name: '   ' });
  check('numele golit e refuzat, nu dă eroare internă → 422', gol.status === 422,
    `a dat ${gol.status} ${gol.data?.error?.code}`);
  const inca = ((await get(admin, '/users')).data?.users || []).find((u) => u.id === id);
  check('numele rămâne cel dinainte', inca?.name === 'Proba Nume', String(inca?.name));

  // Celelalte trei câmpuri sunt nullable: golirea lor trebuie să meargă.
  const mail = await patch(admin, '/users/' + id, { email: '' });
  check('e-mailul golit se acceptă mai departe → 200', mail.status === 200, `a dat ${mail.status}`);

  if (id) await del(admin, '/users/' + id);
}

console.log('\n=== limitarea încercărilor ===');
{
  // Scenariul real: într-o patiserie toate dispozitivele ies la internet prin
  // aceeași adresă IP. Un coleg care își greșește parola nu trebuie să
  // blocheze pe toți ceilalți.
  // Un utilizator inexistent, ca testul să nu blocheze un cont real pentru
  // rulările următoare: limitatorul numără după numele încercat, nu după
  // contul găsit.
  const VICTIMA = 'test.ratelimit';
  // Aceeași adresă pentru amândoi, ca proba să însemne ceva: verificăm exact
  // că limita pe cont NU îl blochează pe colegul de la același birou.
  const birou = '198.51.100.' + (1 + Math.floor(Math.random() * 250));
  const atacator = agent(birou);
  const coleg = agent(birou);

  for (let i = 0; i < 9; i++) {
    await post(atacator, '/auth/login', { username: VICTIMA, password: 'gresit' + i });
  }
  const blocat = await post(atacator, '/auth/login', { username: VICTIMA, password: 'orice' });
  check('contul cu multe greșeli este oprit → 429', blocat.status === 429, `a dat ${blocat.status}`);

  const altul = await post(coleg, '/auth/login', { username: ADMIN.u, password: ADMIN.p });
  check('un alt coleg de pe același IP intră normal → 200', altul.status === 200,
    `a dat ${altul.status} — limita pe IP blochează tot personalul`);
}

console.log('\n=== adresa clientului nu se poate falsifica ===');
{
  // Serverul local are 127.0.0.1 în `trusted_proxies`, tocmai ca testele să
  // poată simula adrese. Verificăm aici că mecanismul chiar depinde de listă:
  // o adresă imposibilă din antet trebuie ignorată, iar una validă acceptată
  // DOAR pentru că sursa e de încredere.
  // Numele se schimbă la fiecare rulare. Cu unul fix, greșeala de aici se
  // aduna de la o rulare la alta în `login_attempts`, iar de la a opta pornire
  // a suitei serverul răspundea 429 în loc de 401: proba pica fără să fie ceva
  // stricat, exact ca la etapa de probă din test-settings.
  const strica = agent('nu-este-o-adresa');
  const r = await post(strica, '/auth/login', {
    username: 'test.antet.' + Math.random().toString(36).slice(2, 7),
    password: 'gresit',
  });
  check('un antet cu text în loc de adresă nu dărâmă serverul', r.status === 401, `a dat ${r.status}`);

  // Că antetul e ignorat când sursa NU e de încredere se verifică direct pe
  // funcție, în test-php.php: de aici, prin HTTP, sursa e mereu 127.0.0.1,
  // care local ESTE de încredere, deci n-am avea ce demonstra.
  //
  // Ce se poate arăta aici: două adrese diferite se numără separat. Dacă
  // antetul ar fi ignorat cu totul, toate cererile ar veni de la 127.0.0.1 și
  // s-ar aduna la un loc.
  const u = 'test.antet.' + Math.random().toString(36).slice(2, 7);
  const ipA = agent('192.0.2.' + (1 + Math.floor(Math.random() * 120)));
  const ipB = agent('192.0.2.' + (130 + Math.floor(Math.random() * 120)));

  for (let i = 0; i < 5; i++) {
    await post(ipA, '/auth/login', { username: u + '.a', password: 'x' + i });
    await post(ipB, '/auth/login', { username: u + '.b', password: 'x' + i });
  }
  const dupa = await post(ipB, '/auth/login', { username: ADMIN.u, password: ADMIN.p });
  check('două adrese diferite se numără separat', dupa.status === 200,
    `a dat ${dupa.status} — încercările s-au adunat pe o singură adresă`);
}

console.log('\n=== jurnal ===');
{
  const r = await get(admin, '/log');
  const acts = (r.data?.entries || []).map((e) => e.action);
  check('jurnalul înregistrează autentificările', acts.includes('login'), acts.slice(0, 8).join(', '));
  check('jurnalul înregistrează comenzile', acts.includes('order_create'), acts.slice(0, 8).join(', '));
  check('jurnalul înregistrează schimbarea setărilor', acts.includes('settings_update'));
  check('jurnalul NU conține valoarea tokenului',
    !JSON.stringify(r.data || {}).includes('SECRET-TOKEN'));
}

/* ------------------------------------------------------------------ CORS -- */
//
// În producție paginile stau pe goldenpastry.ro / lab. / clienti., iar API-ul
// pe api. — origini diferite. Dacă anteturile CORS sunt greșite, browserul
// blochează absolut fiecare cerere și aplicația pare complet moartă, fără
// niciun mesaj de eroare util. De aceea se verifică aici.

console.log('\n=== CORS ===');
{
  const ask = async (origin) => {
    const res = await fetch(BASE + '/orders', {
      method: 'OPTIONS',
      headers: { Origin: origin, 'Access-Control-Request-Method': 'GET' },
    });
    return {
      status: res.status,
      allow: res.headers.get('access-control-allow-origin'),
      creds: res.headers.get('access-control-allow-credentials'),
      vary: res.headers.get('vary'),
    };
  };

  const ok = await ask('https://lab.goldenpastry.ro');
  check('o origine permisă primește Allow-Origin', ok.allow === 'https://lab.goldenpastry.ro', String(ok.allow));
  check('și Allow-Credentials, fără care cookie-ul de sesiune nu pleacă', ok.creds === 'true', String(ok.creds));
  check('răspunsul variază după origine (Vary)', (ok.vary || '').includes('Origin'), String(ok.vary));

  for (const o of ['https://clienti.goldenpastry.ro', 'https://goldenpastry.ro']) {
    const r = await ask(o);
    check('origine permisă: ' + o, r.allow === o, String(r.allow));
  }

  const bad = await ask('https://site-strain.example');
  check('o origine străină NU primește Allow-Origin', bad.allow === null, String(bad.allow));
  check('și nici Allow-Credentials', bad.creds === null, String(bad.creds));

  // Un site care pretinde că e subdomeniul nostru, dar nu este.
  const fake = await ask('https://lab.goldenpastry.ro.atacator.example');
  check('un domeniu care doar începe la fel este respins', fake.allow === null, String(fake.allow));
}

console.log('\n=== antetele răspunsului ===');
{
  const res = await fetch(BASE + '/products');
  // „X-Powered-By: PHP/8.3.33" pleca la fiecare răspuns: îi spuneam singuri
  // unui scaner automat ce versiune să caute în lista de vulnerabilități.
  check('răspunsul nu mai anunță versiunea de PHP',
    res.headers.get('x-powered-by') === null, String(res.headers.get('x-powered-by')));
  check('antetele de securitate sunt la locul lor',
    res.headers.get('x-content-type-options') === 'nosniff'
    && res.headers.get('x-frame-options') === 'DENY',
    res.headers.get('x-content-type-options') + ' / ' + res.headers.get('x-frame-options'));
}

console.log('\n=== un utilizator absurd de lung ===');
{
  // Coloana login_attempts.username are 64 de caractere. Orice text lipit în
  // câmpul „Utilizator" ajunge acolo: o linie copiată dintr-un mesaj, un
  // token, o completare automată greșită. Mai lung, INSERT-ul pica cu eroarea
  // 1406 și omul primea „Eroare internă." în loc de „Utilizator sau parolă
  // greșită." — plus că încercarea nici nu se mai număra la limitare.
  const lung = 'x'.repeat(300);
  const r = await post(agent(), '/auth/login', { username: lung, password: 'orice-parola-1234' });
  check('utilizator de 300 de caractere → 401, nu 500', r.status === 401,
    `a dat ${r.status} ${r.data?.error?.code}`);
  check('mesajul e cel obișnuit, nu unul de defecțiune',
    r.data?.error?.code === 'bad_credentials', JSON.stringify(r.data?.error));

  // Și cu diacritice, unde numărul de octeți diferă de cel de caractere.
  const lungRo = 'ș'.repeat(200);
  const r2 = await post(agent(), '/auth/login', { username: lungRo, password: 'orice-parola-1234' });
  check('și cu diacritice → 401', r2.status === 401, `a dat ${r2.status} ${r2.data?.error?.code}`);
}

console.log('\n=== deconectare ===');
{
  await post(admin, '/auth/logout');
  const r = await get(admin, '/settings');
  check('după logout sesiunea nu mai e validă → 401', r.status === 401, `a dat ${r.status}`);
}

/* --------------------------------------------------------------- final -- */

console.log(`\n${'='.repeat(52)}`);
console.log(`${pass} trecute · ${fail} eșuate`);
if (fail) {
  console.log('\nESUATE:');
  for (const f of failures) console.log('  x ' + f);
}
console.log();
process.exit(fail ? 1 : 0);
