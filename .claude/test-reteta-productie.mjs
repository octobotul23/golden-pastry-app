// Proba pentru rețetele produselor și pentru legarea târzie a articolelor.
//
//   node .claude/instanta.mjs porneste reteta
//   GP_BASE=http://127.0.0.1:82xx/api node .claude/test-reteta-productie.mjs
//   node .claude/instanta.mjs opreste reteta
//
// Ce apără:
//
// 1. `PUT /products/{id}/recipe` ÎNLOCUIEȘTE rețeta, nu adaugă la ea. Panoul
//    trimite ce vede omul pe ecran; o rută care ar adăuga ar face ca o linie
//    ștearsă din ecran să rămână în bază, iar produsul ar consuma pe vecie o
//    materie primă pe care nimeni n-o mai vede scrisă nicăieri.
// 2. Refuzurile sunt lămurite (422/409 cu cod), nu „Eroare internă", și sunt
//    ÎNTREGI: o rețetă refuzată n-o strică pe cea salvată înainte.
// 3. `PATCH /stock/items` acceptă `productId`. Până acum îl accepta doar
//    crearea, iar comentariul din `route_items_create` recunoștea singur unde
//    duce asta: un articol făcut înainte de produs „ar rămâne nelegabil pentru
//    totdeauna". Cu rețetele, gaura nu mai e teoretică — fără articol finit,
//    produsul n-are unde să-și adune bucățile făcute.
//
// 4. `POST /orders/{id}/production` scrie consumul de materii prime. Cifra
//    trimisă e TOTALUL făcut, nu adaosul: tableta reîncearcă singură, iar pe
//    diferență o cerere repetată ar scădea făina de două ori.
// 5. Rescrierea pozițiilor din `PATCH /orders` nu are voie să șteargă
//    producția raportată: materiile prime consumate nu se întorc, deci o
//    comandă care „uită" ce s-a făcut ar pune tura următoare să facă tot lotul
//    a doua oară, cu făină scăzută de două ori pentru aceleași bucăți.
// 6. Zidul dintre etapă și restul comenzii rămâne: angajatul raportează
//    producția pe ruta ei, dar tot nu poate rescrie pozițiile printr-un PATCH.
//
// Verificările care pică dacă reparația e dată înapoi:
//   · „a doua rețetă o înlocuiește pe prima"      (rămân 3 linii în loc de 1)
//   · „rețeta refuzată n-o strică pe cea bună"    (rămâne rețeta pe jumătate)
//   · „articolul vechi se poate lega de produs"   (422 nothing_to_update)
//   · „articolul deja legat nu se refuză pe sine" (409 product_taken)
//   · „raportul repetat nu mai mișcă nimic"       (făina se scade de două ori)
//   · „producția trece peste rescriere"           (done_qty se pierde la PATCH)
//   · „scăderea de la Predată nu se dublează"     (finitul iese de două ori)

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
const put = (a, p, b) => call(a, 'PUT', p, b ?? {});

/** Codul refuzului, ca probele să se uite la el, nu la textul mesajului. */
const cod = (r) => (r.data && r.data.error && r.data.error.code) || '';

/* ---------------------------------------------------------- pregătire -- */

const admin = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica ca admin: ' + r.status); process.exit(1); }
}

const MARCA = 'reteta' + Date.now().toString(36);

// Contul de tură: rețeta e a adminului, iar proba trebuie s-o dovedească.
// Îl facem noi, nu ne bizuim pe `testangajat`: pe o instanță izolată singurul
// cont așezat de-a gata e administratorul, iar proba trebuie să meargă și acolo,
// și pe `gp_dev`. Numele începe cu „proba", deci curățenia îl ia.
const staff = agent();
{
  const nume = 'probareteta' + Date.now().toString(36);
  const c = await post(admin, '/users', {
    username: nume, password: 'Parola-Probe-2468', name: 'Angajat probă rețete', role: 'angajat',
  });
  if (c.status !== 201) { console.log('nu pot crea contul de tură: ' + c.status + ' ' + JSON.stringify(c.data)); process.exit(1); }
  const r = await post(staff, '/auth/login', { username: nume, password: 'Parola-Probe-2468' });
  staff.csrf = r.data?.csrf || '';
  if (r.status !== 200) { console.log('nu m-am putut autentifica cu contul de tură: ' + r.status); process.exit(1); }
  // `POST /users` naște orice cont cu parolă provizorie, iar până n-o schimbă,
  // contul primește 403 la ORICE rută. Fără pasul ăsta, probele de drepturi de
  // mai jos ar trece pentru motivul greșit: nu fiindcă poarta ține, ci fiindcă
  // omul nici n-a intrat pe ușă.
  const sch = await post(staff, '/auth/password', { currentPassword: 'Parola-Probe-2468', newPassword: 'Parola-Probe-2468' });
  if (sch.status !== 200) { console.log('nu pot scoate parola provizorie: ' + sch.status + ' ' + JSON.stringify(sch.data)); process.exit(1); }
}

const GRUPA = ((await get(admin, '/categories')).data?.categories || [])
  .filter((c) => c.active).map((c) => c.slug)[0];
if (!GRUPA) { console.log('nu există nicio grupă de produse activă'); process.exit(1); }

// Categoriile de depozit se editează din Setări, deci nu le scriem în probă.
const RAFT = ((await get(admin, '/stock/items')).data?.categories || [])[0];
if (!RAFT) { console.log('nu există nicio categorie de articole în depozit'); process.exit(1); }

/** Un produs numai pentru proba asta. Numele îl face recognoscibil la curățenie. */
async function produs(sufix) {
  const r = await post(admin, '/products', {
    name: 'Probă Rețetă ' + sufix + ' ' + MARCA,
    nameEn: 'Recipe probe ' + sufix + ' ' + MARCA,
    description: 'Produs de probă.', descriptionEn: 'Probe product.',
    category: GRUPA, grams: 100, priceFrozen: '2.00', priceBaked: '3.00',
  });
  if (r.status !== 201) throw new Error('nu pot crea produsul de probă: ' + r.status + ' ' + JSON.stringify(r.data));
  return r.data.id;
}

/** Un articol de depozit, legat sau nu de un produs. */
async function articol(sufix, productId) {
  const corp = {
    name: 'Probă Rețetă ' + sufix + ' ' + MARCA,
    category: RAFT, unit: 'kg', minQty: 0,
  };
  if (productId) corp.productId = productId;
  const r = await post(admin, '/stock/items', corp);
  if (r.status !== 201) throw new Error('nu pot crea articolul de probă: ' + r.status + ' ' + JSON.stringify(r.data));
  return r.data.id;
}

/** Rețeta așa cum o citește panoul, cu liniile aranjate ca să se poată compara. */
async function reteta(produsId) {
  const r = await get(admin, '/products/' + produsId + '/recipe');
  const linii = (r.data?.recipe || []).slice().sort((a, b) => a.itemId - b.itemId);
  return { status: r.status, linii, finit: r.data?.finished || null };
}

/** Perechile articol→cantitate, ca text, pentru mesajele de eșec. */
const scurt = (linii) => JSON.stringify(linii.map((l) => [l.itemId, l.qty]));

/* ====== 1. PUT înlocuiește rețeta, nu adaugă la ea ===================== */

console.log('\n=== PUT /products/#/recipe înlocuiește lista întreagă ===');

const CROISSANT = await produs('Croissant');
const FAINA = await articol('Făină');
const DROJDIE = await articol('Drojdie');
const UNT = await articol('Unt');

{
  const r1 = await put(admin, '/products/' + CROISSANT + '/recipe', {
    recipe: [{ itemId: FAINA, qty: 0.05 }, { itemId: DROJDIE, qty: 0.005 }, { itemId: UNT, qty: 0.02 }],
  });
  check('rețeta cu trei linii se salvează', r1.status === 200 && r1.data?.lines === 3,
    r1.status + ' ' + JSON.stringify(r1.data));

  const a = await reteta(CROISSANT);
  check('rețeta salvată se citește înapoi întreagă', a.linii.length === 3, scurt(a.linii));

  // Patru zecimale, nu două: la două, drojdia de pe o bucată s-ar rotunji la
  // 0,01 — dublul. Pe o mie de bucăți, cinci kilograme care nu există.
  const droj = a.linii.find((l) => l.itemId === DROJDIE);
  check('cantitatea mică nu se rotunjește la două zecimale', droj && droj.qty === 0.005,
    JSON.stringify(droj));
  check('linia poartă numele și unitatea articolului, ca panoul să nu le mai caute',
    !!(droj && droj.name && droj.unit), JSON.stringify(droj));

  // Miezul lotului: a doua salvare e ce a rămas pe ecran după ce omul a șters
  // două linii. O rută care ar adăuga ar lăsa croissantul cu drojdie și unt pe
  // care nimeni nu le mai vede scrise nicăieri.
  const r2 = await put(admin, '/products/' + CROISSANT + '/recipe', {
    recipe: [{ itemId: FAINA, qty: 0.06 }],
  });
  const b = await reteta(CROISSANT);
  check('a doua rețetă o înlocuiește pe prima, nu se adaugă la ea',
    r2.status === 200 && b.linii.length === 1 && b.linii[0].itemId === FAINA,
    scurt(b.linii));
  check('cantitatea schimbată e cea nouă, nu cea veche',
    b.linii.length === 1 && b.linii[0].qty === 0.06, scurt(b.linii));

  const r3 = await put(admin, '/products/' + CROISSANT + '/recipe', { recipe: [] });
  const c = await reteta(CROISSANT);
  check('rețeta golită chiar se golește: ultima linie se poate scoate',
    r3.status === 200 && c.linii.length === 0, scurt(c.linii));
}

/* ====== 2. refuzurile sunt lămurite și nu strică ce era salvat ========= */

console.log('\n=== rețeta refuzată n-o strică pe cea salvată ===');
{
  const ECLER = await produs('Ecler');
  const ECLER_FINIT = await articol('Ecler finit', ECLER);

  await put(admin, '/products/' + ECLER + '/recipe', { recipe: [{ itemId: FAINA, qty: 0.04 }] });

  const rele = [
    ['articolul care nu există', 'bad_item', 422, [{ itemId: 999999, qty: 1 }]],
    ['linia fără articol', 'bad_item', 422, [{ qty: 1 }]],
    ['linia care nu e obiect', 'bad_item', 422, ['făină']],
    ['același articol de două ori', 'duplicate_item', 409, [{ itemId: DROJDIE, qty: 1 }, { itemId: DROJDIE, qty: 2 }]],
    ['cantitatea zero', 'bad_qty', 422, [{ itemId: DROJDIE, qty: 0 }]],
    ['cantitatea negativă', 'bad_qty', 422, [{ itemId: DROJDIE, qty: -1 }]],
    ['cantitatea scrisă în litere', 'bad_qty', 422, [{ itemId: DROJDIE, qty: 'multă' }]],
    // Produsul finit în propria rețetă s-ar scădea și s-ar adăuga în aceeași
    // mișcare de producție: zero la sold, dar rezervările tot l-ar promite.
    ['produsul finit în propria rețetă', 'self_item', 422, [{ itemId: ECLER_FINIT, qty: 1 }]],
  ];

  for (const [ce, codAsteptat, statusAsteptat, linii] of rele) {
    const r = await put(admin, '/products/' + ECLER + '/recipe', { recipe: linii });
    check(ce + ' se refuză cu „' + codAsteptat + '”',
      r.status === statusAsteptat && cod(r) === codAsteptat,
      r.status + ' ' + cod(r));
  }

  const r = await put(admin, '/products/' + ECLER + '/recipe', { recipe: 'făină și zahăr' });
  check('rețeta care nu e listă e refuzată cu „bad_recipe”',
    r.status === 422 && cod(r) === 'bad_recipe', r.status + ' ' + cod(r));

  // Plafonul e o plasă: nicio prăjitură n-are șaizeci de ingrediente, dar o
  // cerere stricată poate avea șaizeci de mii, iar fiecare linie e o
  // interogare la validare. Se numără înaintea articolelor, deci liniile pot fi
  // oricum — de asta proba nu face șaizeci și unu de articole adevărate.
  const multe = await put(admin, '/products/' + ECLER + '/recipe', {
    recipe: Array.from({ length: 61 }, () => ({ itemId: FAINA, qty: 1 })),
  });
  check('rețeta cu peste șaizeci de linii e refuzată cu „too_many_lines”',
    multe.status === 422 && cod(multe) === 'too_many_lines', multe.status + ' ' + cod(multe));

  // Zece refuzuri mai târziu, rețeta bună trebuie să fie exact cum a fost
  // lăsată. Ștergerea și rescrierea stau într-o tranzacție tocmai pentru asta.
  const a = await reteta(ECLER);
  check('rețeta refuzată n-o strică pe cea bună: ce era salvat a rămas salvat',
    a.linii.length === 1 && a.linii[0].itemId === FAINA && a.linii[0].qty === 0.04,
    scurt(a.linii));

  check('produsul cu articol finit îl spune, ca panoul să nu mai întrebe',
    a.finit && a.finit.id === ECLER_FINIT, JSON.stringify(a.finit));
  const fara = await reteta(CROISSANT);
  check('produsul fără articol finit spune că n-are', fara.finit === null, JSON.stringify(fara.finit));

  const lipsa = await put(admin, '/products/999999/recipe', { recipe: [] });
  check('rețeta unui produs care nu există e refuzată cu 404',
    lipsa.status === 404 && cod(lipsa) === 'not_found', lipsa.status + ' ' + cod(lipsa));
}

/* ====== 3. rețeta e a adminului ======================================== */

console.log('\n=== rețeta se editează doar din contul de administrator ===');
{
  const rg = await get(staff, '/products/' + CROISSANT + '/recipe');
  check('contul de tură nu poate citi rețeta', rg.status === 403, String(rg.status));

  const rp = await put(staff, '/products/' + CROISSANT + '/recipe', { recipe: [{ itemId: FAINA, qty: 1 }] });
  check('contul de tură nu poate scrie rețeta', rp.status === 403, String(rp.status));

  const a = await reteta(CROISSANT);
  check('rețeta a rămas neatinsă după refuz', a.linii.length === 0, scurt(a.linii));
}

/* ====== 4. articolul vechi se poate lega de produs ===================== */

console.log('\n=== PATCH /stock/items acceptă productId ===');
{
  // Cazul din viață: depozitul a fost umplut înainte ca produsele să existe în
  // catalog, sau articolul a fost legat din greșeală de alt produs. Fără
  // reparație, singura cale era un articol nou — cu istoricul rupt în două.
  const TARTA = await produs('Tartă');
  const VECHI = await articol('Tartă articol vechi');

  const r1 = await patch(admin, '/stock/items/' + VECHI, { productId: TARTA });
  check('articolul vechi se poate lega de produs', r1.status === 200, r1.status + ' ' + cod(r1));

  const dupa = ((await get(admin, '/stock/items')).data?.items || []).find((i) => i.id === VECHI);
  check('legătura chiar s-a scris', dupa && dupa.productId === TARTA, JSON.stringify(dupa));

  // A doua salvare, cu același produs: articolul deja legat e el însuși, nu un
  // rival. Fără `id <> ?` în verificare, produsul s-ar refuza pe sine, iar
  // simpla reapăsare pe „Salvează" ar da 409.
  const r2 = await patch(admin, '/stock/items/' + VECHI, { productId: TARTA });
  check('articolul deja legat nu se refuză pe sine la o a doua salvare',
    r2.status === 200, r2.status + ' ' + cod(r2));

  const ALTUL = await articol('Tartă al doilea articol');
  const r3 = await patch(admin, '/stock/items/' + ALTUL, { productId: TARTA });
  check('al doilea articol pe același produs e refuzat cu „product_taken”',
    r3.status === 409 && cod(r3) === 'product_taken', r3.status + ' ' + cod(r3));
  check('refuzul spune pe care articol să facă intrările',
    /Probă Rețetă Tartă articol vechi/.test(r3.data?.error?.message || ''),
    JSON.stringify(r3.data?.error?.message));

  const r4 = await patch(admin, '/stock/items/' + ALTUL, { productId: 999999 });
  check('produsul care nu există e refuzat cu „bad_product”',
    r4.status === 422 && cod(r4) === 'bad_product', r4.status + ' ' + cod(r4));

  // Dezlegarea: un articol legat greșit trebuie să poată fi și desprins.
  const r5 = await patch(admin, '/stock/items/' + VECHI, { productId: null });
  const dezlegat = ((await get(admin, '/stock/items')).data?.items || []).find((i) => i.id === VECHI);
  check('articolul se poate și dezlega de produs',
    r5.status === 200 && dezlegat && dezlegat.productId === null,
    r5.status + ' ' + JSON.stringify(dezlegat));

  const r6 = await patch(admin, '/stock/items/' + ALTUL, { productId: TARTA });
  check('produsul rămas liber se poate lega de alt articol',
    r6.status === 200, r6.status + ' ' + cod(r6));

  // Restul câmpurilor nu s-au stricat: `productId` e o ramură nouă, nu o
  // rescriere a celorlalte.
  const r7 = await patch(admin, '/stock/items/' + ALTUL, { minQty: 12.5 });
  const cuPrag = ((await get(admin, '/stock/items')).data?.items || []).find((i) => i.id === ALTUL);
  check('pragul minim se salvează în continuare, iar legătura rămâne',
    r7.status === 200 && cuPrag && cuPrag.minQty === 12.5 && cuPrag.productId === TARTA,
    JSON.stringify(cuPrag));

  const r8 = await patch(admin, '/stock/items/' + ALTUL, {});
  check('cererea fără nimic de schimbat rămâne refuzată lămurit',
    r8.status === 422 && cod(r8) === 'nothing_to_update', r8.status + ' ' + cod(r8));
}

/* ====== 5. raportarea producției ======================================= */

console.log('\n=== POST /orders/#/production: totaluri, nu adaosuri ===');

// Etapele vin din Setări; fără cea de încheiere, partea cu „Predată" n-are ce
// pune în scenă și se sare, spus pe față.
const liste = (await get(admin, '/lists')).data || {};
const PREDATA = liste.done || '';
const ZI = '2026-09-25';

/** Stocul curent al unui articol, așa cum îl vede pagina Depozit. */
async function stoc(articol) {
  const r = await get(admin, '/stock/items');
  const it = (r.data?.items || []).find((i) => i.id === articol);
  return it ? it.qty : null;
}

/** Mișcările scrise pentru un articol, cele mai noi întâi. */
const miscari = async (articol) => (await get(admin, '/stock/moves?item=' + articol)).data?.moves || [];

/** O comandă de probă cu o singură poziție. */
async function comanda(sufix, produsId, cantitate) {
  const r = await post(admin, '/orders', {
    customerName: 'Probă Rețetă ' + sufix + ' ' + MARCA,
    phone: '0700 000 111', address: 'Str. Probei 1',
    day: ZI, fulfillment: 'Livrare', channel: 'panou',
    items: [{ productId: produsId, qty: cantitate, variant: 'copt' }],
  });
  if (r.status !== 201) throw new Error('nu pot crea comanda de probă: ' + r.status + ' ' + JSON.stringify(r.data));
  return r.data.order;
}

{
  // Un produs cu rețetă și cu articol finit: capătul dinspre materii prime și
  // capătul dinspre marfa gata, cele două care trebuie să nu se încurce.
  const BAGHETA = await produs('Baghetă');
  const FAINA2  = await articol('Făină de baghetă');
  const SARE    = await articol('Sare de baghetă');
  const FINIT   = await articol('Baghetă finită', BAGHETA);
  await put(admin, '/products/' + BAGHETA + '/recipe', {
    recipe: [{ itemId: FAINA2, qty: 0.25 }, { itemId: SARE, qty: 0.005 }],
  });

  const o = await comanda('Producție', BAGHETA, 10);
  const poz = o.items[0];

  check('poziția comenzii își spune numărul, ca laboratorul să aibă unde raporta',
    !!poz && typeof poz.id === 'number' && poz.id > 0, JSON.stringify(poz));
  check('poziția pornește cu zero bucăți făcute', poz && poz.doneQty === 0, JSON.stringify(poz));

  // Tura de dimineață apucă 4 din 10.
  const r1 = await post(staff, '/orders/' + o.id + '/production', {
    items: [{ id: poz.id, doneQty: 4 }],
  });
  check('tura raportează ce a apucat să facă', r1.status === 200, r1.status + ' ' + cod(r1));
  check('comanda întoarsă spune cât s-a făcut',
    r1.data?.order?.items?.[0]?.doneQty === 4, JSON.stringify(r1.data?.order?.items));
  check('făina scade pentru cele patru bucăți, nu pentru zece',
    await stoc(FAINA2) === -1, 'stoc făină = ' + await stoc(FAINA2));
  check('sarea scade cu cele patru zecimi de gram, fără rotunjire la zero',
    await stoc(SARE) === -0.02, 'stoc sare = ' + await stoc(SARE));
  check('bagheta gata intră în depozit, nu iese din el',
    await stoc(FINIT) === 4, 'stoc finit = ' + await stoc(FINIT));

  const mvF = await miscari(FAINA2);
  check('consumul se scrie cu motivul din vocabularul Depozitului',
    mvF.length === 1 && mvF[0].reason === 'Consum în producție', JSON.stringify(mvF.map((m) => m.reason)));

  // ACELAȘI raport, încă o dată: tableta reîncearcă, degetul apasă de două ori.
  const r2 = await post(staff, '/orders/' + o.id + '/production', {
    items: [{ id: poz.id, doneQty: 4 }],
  });
  check('raportul repetat e primit fără să se plângă', r2.status === 200, r2.status + ' ' + cod(r2));
  check('raportul repetat nu mai mișcă nimic în depozit',
    r2.data?.moves === 0 && await stoc(FAINA2) === -1 && await stoc(FINIT) === 4,
    'moves=' + r2.data?.moves + ' făină=' + await stoc(FAINA2) + ' finit=' + await stoc(FINIT));

  // Tura de după-amiază termină lotul: totalul urcă la 10, nu se trimite 6.
  const r3 = await post(staff, '/orders/' + o.id + '/production', {
    items: [{ id: poz.id, doneQty: 10 }],
  });
  check('a doua tură ridică totalul, nu adaugă la el', r3.status === 200, r3.status + ' ' + cod(r3));
  check('făina scade doar pentru cele șase bucăți rămase',
    await stoc(FAINA2) === -2.5, 'stoc făină = ' + await stoc(FAINA2));
  check('stocul de baghete gata ajunge la zece',
    await stoc(FINIT) === 10, 'stoc finit = ' + await stoc(FINIT));

  // Peste comandă nu se raportează: bucățile în plus sunt marfă de vânzare.
  const r4 = await post(staff, '/orders/' + o.id + '/production', {
    items: [{ id: poz.id, doneQty: 11 }],
  });
  check('mai mult decât s-a cerut se refuză lămurit',
    r4.status === 422 && cod(r4) === 'bad_done_qty', r4.status + ' ' + cod(r4));
  check('refuzul spune ce e de făcut cu bucățile în plus',
    String(r4.data?.error?.message || '').includes('Depozit'), JSON.stringify(r4.data));
  check('refuzul nu lasă în urmă jumătate de consum',
    await stoc(FAINA2) === -2.5 && await stoc(FINIT) === 10,
    'făină=' + await stoc(FAINA2) + ' finit=' + await stoc(FINIT));

  const r5 = await post(staff, '/orders/' + o.id + '/production', {
    items: [{ id: poz.id, doneQty: -1 }],
  });
  check('o cantitate negativă se refuză cu același cod',
    r5.status === 422 && cod(r5) === 'bad_done_qty', r5.status + ' ' + cod(r5));

  const r6 = await post(staff, '/orders/' + o.id + '/production', {
    items: [{ id: poz.id + 900000, doneQty: 1 }],
  });
  check('poziția care nu e pe comandă se refuză, nu se inventează',
    r6.status === 422 && cod(r6) === 'bad_item', r6.status + ' ' + cod(r6));

  // Îndreptarea în jos: cine tastează 10 și a făcut 7 trebuie să poată corecta.
  const r7 = await post(staff, '/orders/' + o.id + '/production', {
    items: [{ id: poz.id, doneQty: 7 }],
  });
  check('corecția în jos e primită', r7.status === 200, r7.status + ' ' + cod(r7));
  check('corecția se scrie ca rând invers, nu prin ștergerea celor vechi',
    (await miscari(FAINA2)).length === 3, JSON.stringify((await miscari(FAINA2)).map((m) => [m.direction, m.qty])));
  check('după corecție cifrele se potrivesc cu cele șapte bucăți',
    await stoc(FAINA2) === -1.75 && await stoc(FINIT) === 7,
    'făină=' + await stoc(FAINA2) + ' finit=' + await stoc(FINIT));
  const mvC = (await miscari(FAINA2))[0];
  check('rândul de corecție se vede în registru ca fiind corecție',
    mvC && mvC.reason === 'Corecție producție' && mvC.direction === 1, JSON.stringify(mvC));

  // Scăderea de la „Predată" e pasul următor: bagheta pleacă din congelator.
  // Dacă s-ar scădea și producția, aceleași bucăți ar ieși de două ori.
  if (PREDATA) {
    await post(staff, '/orders/' + o.id + '/production', { items: [{ id: poz.id, doneQty: 10 }] });
    await patch(admin, '/orders/' + o.id, { status: PREDATA });
    await post(admin, '/stock/sync');
    check('scăderea de la „Predată" ia bucățile o singură dată',
      await stoc(FINIT) === 0, 'stoc finit = ' + await stoc(FINIT));
    check('materiile prime nu se întorc la livrare: consumul e un fapt',
      await stoc(FAINA2) === -2.5, 'stoc făină = ' + await stoc(FAINA2));
  } else {
    console.log('  (sar peste „Predată": instanța nu are configurată etapa de încheiere)');
  }
}

/* ====== 6. rescrierea comenzii nu șterge producția ==================== */

console.log('\n=== PATCH /orders: done_qty trece peste rescrierea pozițiilor ===');

{
  const CORNET = await produs('Cornet');
  const ZAHAR  = await articol('Zahăr de cornet');
  const FCORN  = await articol('Cornet finit', CORNET);
  await put(admin, '/products/' + CORNET + '/recipe', { recipe: [{ itemId: ZAHAR, qty: 0.1 }] });

  const o = await comanda('Rescriere', CORNET, 20);
  const poz = o.items[0];
  await post(staff, '/orders/' + o.id + '/production', { items: [{ id: poz.id, doneQty: 12 }] });

  // Clientul sună și urcă comanda de la 20 la 30. Pozițiile se șterg și se
  // scriu la loc — exact locul unde producția s-ar fi pierdut.
  const p1 = await patch(admin, '/orders/' + o.id, {
    items: [{ productId: CORNET, qty: 30, variant: 'copt' }],
  });
  check('comanda mărită se salvează', p1.status === 200, p1.status + ' ' + cod(p1));
  check('cele douăsprezece bucăți făcute trec peste rescriere',
    p1.data?.order?.items?.[0]?.doneQty === 12, JSON.stringify(p1.data?.order?.items));
  check('rescrierea nu atinge nici zahărul, nici cornetele gata',
    await stoc(ZAHAR) === -1.2 && await stoc(FCORN) === 12,
    'zahăr=' + await stoc(ZAHAR) + ' finit=' + await stoc(FCORN));

  // Și invers: comanda tăiată sub cât s-a făcut. Bucata în plus nu se pierde
  // din depozit — intrarea ei e scrisă și rămâne scrisă.
  const p2 = await patch(admin, '/orders/' + o.id, {
    items: [{ productId: CORNET, qty: 5, variant: 'copt' }],
  });
  check('comanda tăiată sub cât s-a făcut se salvează', p2.status === 200, p2.status + ' ' + cod(p2));
  check('producția se plafonează la cât mai are comanda',
    p2.data?.order?.items?.[0]?.doneQty === 5, JSON.stringify(p2.data?.order?.items));
  check('bucățile făcute peste comandă rămân în stocul de finit',
    await stoc(FCORN) === 12, 'stoc finit = ' + await stoc(FCORN));

  // Noua poziție are alt id: raportarea trebuie să meargă pe el, nu pe cel vechi.
  const nou = p2.data?.order?.items?.[0];
  const r = await post(staff, '/orders/' + o.id + '/production', { items: [{ id: nou.id, doneQty: 5 }] });
  check('raportul pe poziția rescrisă nu mai mișcă nimic: era deja la cinci',
    r.status === 200 && r.data?.moves === 0, r.status + ' moves=' + r.data?.moves);
  const vechi = await post(staff, '/orders/' + o.id + '/production', { items: [{ id: poz.id, doneQty: 1 }] });
  check('raportul pe poziția ștearsă se refuză, nu nimerește alta',
    vechi.status === 422 && cod(vechi) === 'bad_item', vechi.status + ' ' + cod(vechi));
}

/* ====== 7. zidul dintre etapă și restul comenzii ====================== */

console.log('\n=== Angajatul raportează producția, dar tot nu rescrie comanda ===');

{
  const CHIFLA = await produs('Chiflă');
  const o = await comanda('Drepturi', CHIFLA, 4);

  const p = await patch(staff, '/orders/' + o.id, { qty: 9, customerName: 'Altcineva' });
  check('angajatul tot nu poate schimba altceva decât etapa prin PATCH',
    p.status === 403 && cod(p) === 'staff_field_denied', p.status + ' ' + cod(p));

  const pi = await patch(staff, '/orders/' + o.id, {
    items: [{ productId: CHIFLA, qty: 99, variant: 'copt' }],
  });
  const dupa = (await get(admin, '/orders/' + o.id)).data?.order;
  check('nici pozițiile nu se pot rescrie de pe tabletă',
    dupa && dupa.items.length === 1 && dupa.items[0].qty === 4,
    pi.status + ' ' + JSON.stringify(dupa?.items));

  const r = await post(staff, '/orders/' + o.id + '/production', { items: [{ id: o.items[0].id, doneQty: 4 }] });
  check('dar producția o raportează, că de-aia are rută proprie',
    r.status === 200, r.status + ' ' + cod(r));

  // Clientul de rând n-are ce căuta pe ruta asta.
  const strain = agent();
  const rs = await post(strain, '/orders/' + o.id + '/production', { items: [{ id: o.items[0].id, doneQty: 1 }] });
  check('cine nu e din tură nu raportează producție', rs.status === 401 || rs.status === 403, String(rs.status));
}

/* ====== 8. rezervările: ce e promis, dar încă n-a plecat ============== */

console.log('\n=== GET /stock/items: „Rezervat" și „Disponibil" vin de la server ===');

/** Rândul întreg al unui articol, așa cum îl primește pagina Depozit. */
async function rand(articol) {
  const r = await get(admin, '/stock/items');
  return (r.data?.items || []).find((i) => i.id === articol) || null;
}
const rez  = async (a) => { const x = await rand(a); return x ? x.reserved : null; };
const disp = async (a) => { const x = await rand(a); return x ? x.available : null; };

{
  // Un produs cu rețetă, articol finit și două materii prime. Redenumim
  // produsul ÎNAINTE de comandă: din clipa asta `order_items.name` (numele nou)
  // și `stock_items.name` (cel vechi) nu mai seamănă. Potrivirea trebuie să
  // cadă totuși pe articolul lui, prin product_id — altfel „Rezervat" ar arăta
  // zero exact când marfa e promisă. Asta e greșeala pe care o făcea socoteala
  // din browser și care se repara acolo; acum se face o singură dată, la
  // scrierea rezervării.
  const TARTA  = await produs('Tartă Promisă');
  const OUA    = await articol('Ouă de tartă');
  const FRISCA = await articol('Frișcă de tartă');
  const FTARTA = await articol('Tartă finită', TARTA);
  await put(admin, '/products/' + TARTA + '/recipe', {
    recipe: [{ itemId: OUA, qty: 2 }, { itemId: FRISCA, qty: 0.15 }],
  });
  const red = await patch(admin, '/products/' + TARTA, { name: 'Probă Rețetă Tartă Redenumită ' + MARCA });
  check('produsul se poate redenumi din catalog', red.status === 200, red.status + ' ' + cod(red));

  const o = await comanda('Rezervare', TARTA, 10);
  const poz = o.items[0];

  check('marfa gata e promisă toată, deși numele articolului nu mai seamănă cu al poziției',
    await rez(FTARTA) === 10, 'rezervat = ' + await rez(FTARTA));
  check('materiile prime se promit după rețetă, nu pe bucată',
    await rez(OUA) === 20 && await rez(FRISCA) === 1.5,
    'ouă=' + await rez(OUA) + ' frișcă=' + await rez(FRISCA));
  check('„Disponibil" e stocul minus promisiunea',
    await disp(OUA) === -20, 'disponibil = ' + await disp(OUA));

  // Producția raportată mută materia primă din promisiune în fapt: ea a ieșit
  // din raft cu o mișcare adevărată. Dacă rezervarea n-ar scădea, aceeași făină
  // ar fi numărată de două ori și „Disponibil" ar arăta mai puțin decât are
  // patiseria. Marfa gata rămâne promisă: croissantul făcut e tot al comenzii
  // până pleacă.
  const pr = await post(staff, '/orders/' + o.id + '/production', { items: [{ id: poz.id, doneQty: 4 }] });
  check('raportul de producție intră', pr.status === 200, pr.status + ' ' + cod(pr));
  check('ce s-a consumat nu mai e promis',
    await rez(OUA) === 12 && await rez(FRISCA) === 0.9,
    'ouă=' + await rez(OUA) + ' frișcă=' + await rez(FRISCA));
  check('materia primă consumată nu se numără de două ori',
    await disp(OUA) === -20, 'disponibil = ' + await disp(OUA));
  check('marfa gata rămâne promisă până pleacă din patiserie',
    await rez(FTARTA) === 10, 'rezervat = ' + await rez(FTARTA));

  // Corecția în jos redeschide promisiunea, fiindcă redeschide și consumul.
  await post(staff, '/orders/' + o.id + '/production', { items: [{ id: poz.id, doneQty: 1 }] });
  check('corecția în jos pune materia primă la loc în promisiune',
    await rez(OUA) === 18, 'rezervat = ' + await rez(OUA));

  // Rescrierea pozițiilor: rezervările vechi pleacă odată cu ele (CASCADE), iar
  // funcția le scrie la loc de pe pozițiile de acum. Idempotentă, deci PATCH
  // n-are nevoie să știe în ce ordine s-au întâmplat lucrurile.
  const p = await patch(admin, '/orders/' + o.id, { items: [{ productId: TARTA, qty: 3, variant: 'copt' }] });
  check('comanda micșorată se salvează', p.status === 200, p.status + ' ' + cod(p));
  check('promisiunea urmează cantitatea nouă, fără să se adune peste cea veche',
    await rez(FTARTA) === 3 && await rez(OUA) === 4,
    'finit=' + await rez(FTARTA) + ' ouă=' + await rez(OUA));

  // Poziția scoasă cu totul nu mai promite nimic.
  const p2 = await patch(admin, '/orders/' + o.id, { items: [] });
  if (p2.status === 200) {
    check('comanda golită nu mai ține nimic promis',
      await rez(FTARTA) === 0 && await rez(OUA) === 0,
      'finit=' + await rez(FTARTA) + ' ouă=' + await rez(OUA));
  } else {
    // Comanda fără poziții poate fi refuzată din alt motiv; atunci proba de
    // deasupra e destulă și n-o forțăm.
    check('comanda golită e refuzată lămurit, nu cu „Eroare internă"',
      p2.status === 422 && !!cod(p2), p2.status + ' ' + cod(p2));
  }
}

console.log('\n=== etapa comenzii stinge și redeschide promisiunea ===');

if (!PREDATA) {
  console.log('  (sar: etapa de încheiere nu e configurată în Setări)');
} else {
  const ECLER  = await produs('Ecler De Etapă');
  const CIOCO  = await articol('Ciocolată de ecler');
  const FECLER = await articol('Ecler de etapă finit', ECLER);
  await put(admin, '/products/' + ECLER + '/recipe', { recipe: [{ itemId: CIOCO, qty: 0.03 }] });

  const o = await comanda('Etapă', ECLER, 100);
  check('comanda în lucru ține marfa promisă',
    await rez(FECLER) === 100 && await rez(CIOCO) === 3,
    'finit=' + await rez(FECLER) + ' ciocolată=' + await rez(CIOCO));

  // „Predată" nu se gestionează nicăieri: rezervarea deschisă se numără doar
  // pentru comenzile care nu sunt nici încheiate, nici anulate. Eliberarea la
  // predare, redeschiderea dacă iese din predare și eliberarea la anulare sunt
  // același filtru, nu trei bucăți de cod care pot rămâne în urmă una de alta.
  const st = await patch(admin, '/orders/' + o.id, { status: PREDATA });
  check('comanda se poate trece pe etapa de încheiere', st.status === 200, st.status + ' ' + cod(st));
  check('comanda predată nu mai ține nimic promis',
    await rez(FECLER) === 0 && await rez(CIOCO) === 0,
    'finit=' + await rez(FECLER) + ' ciocolată=' + await rez(CIOCO));

  const flow = (await get(admin, '/lists')).data?.statuses || [];
  const inapoi = flow.find((x) => x !== PREDATA);
  if (inapoi) {
    await patch(admin, '/orders/' + o.id, { status: inapoi });
    check('scoasă din „Predată", comanda își cere marfa înapoi',
      await rez(FECLER) === 100, 'rezervat = ' + await rez(FECLER));
  }

  const anulata = (await get(admin, '/lists')).data?.cancelled || '';
  if (anulata) {
    await patch(admin, '/orders/' + o.id, { status: anulata });
    check('comanda anulată eliberează marfa pentru altcineva',
      await rez(FECLER) === 0 && await rez(CIOCO) === 0,
      'finit=' + await rez(FECLER) + ' ciocolată=' + await rez(CIOCO));
  }
}

console.log('\n=== cererea acceptată promite, la fel ca o comandă dată la telefon ===');

{
  // Al doilea loc care scrie `order_items` e acceptarea unei cereri: are INSERT
  // propriu, nu trece prin route_orders_create. Uitat, tot ce intră prin
  // formularul de pe site ar rămâne nepromis — adică exact comenzile clienților.
  const SAVARINA = await produs('Savarină');
  const ROM      = await articol('Rom de savarină');
  const FSAV     = await articol('Savarină finită', SAVARINA);
  await put(admin, '/products/' + SAVARINA + '/recipe', { recipe: [{ itemId: ROM, qty: 0.02 }] });

  const anon = agent();
  const c = await post(anon, '/requests', {
    customerName: 'Probă Rețetă Cerere ' + MARCA,
    phone: '0700 222 333',
    day: ZI,
    items: [{ productId: SAVARINA, qty: 50 }],
  });
  check('cererea de pe site intră', c.status === 201, c.status + ' ' + cod(c));

  const idCerere = c.data?.request?.id;
  check('cererea încă nu promite nimic: n-are poziții de comandă',
    await rez(FSAV) === 0, 'rezervat = ' + await rez(FSAV));

  const a = await post(admin, '/requests/' + idCerere + '/accept', { day: ZI, fulfillment: 'Livrare' });
  check('cererea se acceptă', a.status === 200 || a.status === 201, a.status + ' ' + cod(a));
  check('acceptarea naște promisiunea, marfă gata și materie primă deopotrivă',
    await rez(FSAV) === 50 && await rez(ROM) === 1,
    'finit=' + await rez(FSAV) + ' rom=' + await rez(ROM));
}

/* ------------------------------------------------------------ rezultat -- */

console.log('\n' + '='.repeat(52));
console.log(`${pass} trecute · ${fail} picate`);
if (fail) {
  console.log('\nPicate:');
  for (const f of failures) console.log('  · ' + f);
  process.exit(1);
}
