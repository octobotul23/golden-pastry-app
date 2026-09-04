// Teste pentru catalogul editabil: produse, grupe, fotografii.
//
//   php -S 127.0.0.1:8100 -t . .claude/dev-server.php
//   node .claude/test-catalog.mjs
//
// Miza: catalogul e ce vede clientul și ce se facturează. Un produs scos din
// catalog nu are voie să șteargă istoricul comenzilor, iar o fotografie urcată
// nu are voie să fie orice fișier.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

let pass = 0, fail = 0;
const failures = [];

function check(name, cond, extra = '') {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
}

function agent() { return { cookies: new Map(), csrf: '' }; }

async function send(a, method, path, { body, form } = {}) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (a.csrf && method !== 'GET') headers['X-CSRF-Token'] = a.csrf;
  if (a.cookies.size) headers['Cookie'] = [...a.cookies].map(([k, v]) => `${k}=${v}`).join('; ');

  const res = await fetch(BASE + path, {
    method, headers,
    body: form !== undefined ? form : (body === undefined ? undefined : JSON.stringify(body)),
  });

  for (const raw of res.headers.getSetCookie?.() ?? []) {
    const [pair] = raw.split(';');
    const i = pair.indexOf('=');
    a.cookies.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
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

const get = (a, p) => send(a, 'GET', p);
const post = (a, p, b) => send(a, 'POST', p, { body: b ?? {} });
const patch = (a, p, b) => send(a, 'PATCH', p, { body: b ?? {} });
const del = (a, p) => send(a, 'DELETE', p);

const admin = agent();
const staff = agent();
{
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data.csrf;
  const s = await post(staff, '/auth/login', { username: 'testangajat', password: 'Parola-Test-5678' });
  staff.csrf = s.data.csrf;
}

const tag = Math.random().toString(36).slice(2, 7);
let catId = null, prodId = null;

try {

  /* ------------------------------------------------------------ drepturi -- */
  console.log('\n=== cine poate schimba catalogul ===');
  {
    const anon = agent();
    const pub = await get(anon, '/products');
    check('oricine poate citi catalogul → 200', pub.status === 200, `a dat ${pub.status}`);
    check('catalogul vine cu grupe cu tot', Array.isArray(pub.data?.categories));

    const r = await post(anon, '/products', { name: 'Furat' });
    check('un vizitator nu poate adăuga produse → 401', r.status === 401, `a dat ${r.status}`);

    const e = await post(staff, '/products', { name: 'Furat' });
    check('un angajat nu poate adăuga produse → 403', e.status === 403, `a dat ${e.status}`);

    const d = await del(staff, '/products/1');
    check('un angajat nu poate scoate produse → 403', d.status === 403, `a dat ${d.status}`);
  }

  /* -------------------------------------------------------------- grupe -- */
  console.log('\n=== grupe ===');
  {
    const r = await post(admin, '/categories', { name: 'Probă ' + tag, nameEn: 'Test ' + tag });
    check('grupă nouă → 201', r.status === 201, `a dat ${r.status} ${JSON.stringify(r.data)}`);
    catId = r.data?.id;
    check('cheia se construiește din nume', /^proba-/.test(r.data?.slug || ''), r.data?.slug);

    const dup = await post(admin, '/categories', { name: 'Probă ' + tag, nameEn: 'Test ' + tag });
    check('a doua grupă cu aceeași cheie e respinsă → 409', dup.status === 409, `a dat ${dup.status}`);

    const noEn = await post(admin, '/categories', { name: 'Fără engleză ' + tag });
    check('grupa fără nume în engleză e respinsă → 422', noEn.status === 422, `a dat ${noEn.status}`);
  }

  /* ------------------------------------------------------------ produse -- */
  console.log('\n=== produse ===');
  const slug = (await get(admin, '/categories')).data.categories.find((c) => c.id === catId).slug;
  {
    const lipsa = await post(admin, '/products', {
      name: 'Doar română ' + tag, category: slug,
    });
    check('produsul fără traducere e respins → 422', lipsa.status === 422, `a dat ${lipsa.status}`);
    check('mesajul spune exact ce lipsește',
      /engleză/.test(lipsa.data?.error?.message || ''), lipsa.data?.error?.message);

    const gresit = await post(admin, '/products', {
      name: 'X ' + tag, nameEn: 'X', description: 'a', descriptionEn: 'a', category: 'grupa-inexistenta',
    });
    check('grupa inexistentă e respinsă → 422', gresit.status === 422, `a dat ${gresit.status}`);

    const r = await post(admin, '/products', {
      name: 'Ștrudel de probă ' + tag, nameEn: 'Test strudel ' + tag,
      description: 'Descriere de probă.', descriptionEn: 'Test description.',
      category: slug, grams: 150, priceFrozen: 3.2, priceBaked: 3.99, isNew: true,
    });
    check('produs nou → 201', r.status === 201, `a dat ${r.status} ${JSON.stringify(r.data)}`);
    prodId = r.data?.id;
    check('adresa are diacriticele transformate', /^strudel-de-proba-/.test(r.data?.slug || ''), r.data?.slug);

    const pret = await post(admin, '/products', {
      name: 'Preț aiurea ' + tag, nameEn: 'Bad price', description: 'a', descriptionEn: 'a',
      category: slug, priceFrozen: 1, priceBaked: -5,
    });
    check('prețul negativ e respins → 422', pret.status === 422, `a dat ${pret.status}`);

    // Prețul e câmp de text liber în panou. Înainte, `(float) "la cerere"` dădea
    // tăcut 0: produsul se publica cu 0,00 lei și se putea comanda pe gratis.
    const litere = await post(admin, '/products', {
      name: 'Preț cu litere ' + tag, nameEn: 'Worded price ' + tag,
      description: 'a', descriptionEn: 'a',
      category: slug, priceFrozen: 'la cerere', priceBaked: 3.49,
    });
    check('prețul scris cu litere e respins → 422', litere.status === 422, `a dat ${litere.status}`);
    check('mesajul cere cifre, nu tace', /cifre/.test(litere.data?.error?.message || ''),
      litere.data?.error?.message);

    const zero = await post(admin, '/products', {
      name: 'Preț zero ' + tag, nameEn: 'Zero price ' + tag,
      description: 'a', descriptionEn: 'a',
      category: slug, priceFrozen: 0, priceBaked: 3.49,
    });
    check('prețul 0 e respins → 422', zero.status === 422, `a dat ${zero.status}`);

    const toate = (await get(admin, '/products?all=1')).data.products;
    check('produsul cu preț stricat nu a intrat în catalog',
      !toate.some((x) => (x.name || '').includes('Preț cu litere ' + tag)
        || (x.name || '').includes('Preț zero ' + tag)));

    // Virgula rămâne primită: adminul scrie „2,85", nu „2.85".
    const virgula = await post(admin, '/products', {
      name: 'Preț cu virgulă ' + tag, nameEn: 'Comma price ' + tag,
      description: 'a', descriptionEn: 'a',
      category: slug, priceFrozen: '2,85', priceBaked: '3,49',
    });
    check('prețul scris cu virgulă e primit → 201', virgula.status === 201, `a dat ${virgula.status}`);
    const cuVirgula = (await get(admin, '/products?all=1')).data.products
      .find((x) => x.id === virgula.data?.id);
    check('virgula s-a citit ca zecimală',
      cuVirgula?.priceFrozen === 2.85 && cuVirgula?.priceBaked === 3.49,
      `${cuVirgula?.priceFrozen} / ${cuVirgula?.priceBaked}`);
    if (virgula.data?.id) await del(admin, '/products/' + virgula.data.id);

    // Aceeași gaură era și la editare: un preț bun putea fi înlocuit cu 0.
    const stricat = await patch(admin, '/products/' + prodId, { priceFrozen: 'gratis' });
    check('editarea cu preț scris cu litere e respinsă → 422', stricat.status === 422,
      `a dat ${stricat.status}`);
    const dupa = (await get(admin, '/products?all=1')).data.products.find((x) => x.id === prodId);
    check('prețul vechi a rămas neatins după editarea respinsă', dupa?.priceFrozen === 3.2,
      String(dupa?.priceFrozen));

    const lista = await get(admin, '/products');
    const meu = lista.data.products.find((x) => x.id === prodId);
    check('produsul apare în catalog', !!meu);
    check('prețurile s-au păstrat', meu?.priceFrozen === 3.2 && meu?.priceBaked === 3.99,
      `${meu?.priceFrozen} / ${meu?.priceBaked}`);
    check('engleza s-a păstrat', meu?.nameEn?.includes('Test strudel'), meu?.nameEn);
  }

  /* ------------------------------------------------------- fotografia --- */
  console.log('\n=== fotografia ===');
  {
    // Un PNG minim, construit aici: 1x1 pixel transparent.
    const png = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
      'base64'
    );
    const fd = new FormData();
    fd.append('photo', new Blob([png], { type: 'image/png' }), 'poza.png');
    const r = await send(admin, 'POST', '/products/' + prodId + '/photo', { form: fd });
    check('fotografia se încarcă → 200', r.status === 200, `a dat ${r.status} ${JSON.stringify(r.data)}`);
    check('răspunsul dă calea nouă', /^products\/prod-\d+-[0-9a-f]{8}\.png$/.test(r.data?.photo || ''), r.data?.photo);

    const onDisk = path.join(ROOT, r.data?.photo || 'products/lipsa');
    check('fișierul chiar există pe disc', fs.existsSync(onDisk), onDisk);

    // Miezul: un fișier PHP redenumit în .jpg NU trebuie acceptat. Tipul se
    // verifică după conținut, nu după nume sau după ce spune browserul.
    const fake = new FormData();
    fake.append('photo', new Blob([Buffer.from('<?php echo "salut"; ?>')], { type: 'image/jpeg' }), 'poza.jpg');
    const bad = await send(admin, 'POST', '/products/' + prodId + '/photo', { form: fake });
    check('un fișier PHP deghizat în poză e respins → 422', bad.status === 422, `a dat ${bad.status}`);
    check('mesajul spune ce se acceptă',
      /JPEG|PNG|WebP/i.test(bad.data?.error?.message || ''), bad.data?.error?.message);

    // A doua încărcare schimbă numele, ca browserele să nu arate poza veche.
    const fd2 = new FormData();
    fd2.append('photo', new Blob([png], { type: 'image/png' }), 'poza.png');
    const r2 = await send(admin, 'POST', '/products/' + prodId + '/photo', { form: fd2 });
    check('a doua încărcare primește alt nume', r2.data?.photo !== r.data?.photo,
      `${r.data?.photo} → ${r2.data?.photo}`);
    check('poza dinainte a fost ștearsă', !fs.existsSync(onDisk), onDisk);
  }

  /* --------------------------------------------------- scoatere din catalog -- */
  console.log('\n=== scoaterea din catalog ===');
  {
    // O comandă cu produsul, ca să verificăm că istoricul rezistă.
    const day = new Date().toISOString().slice(0, 10);
    const com = await post(admin, '/orders', {
      customerName: 'Test catalog', day, fulfillment: 'Ridicare',
      items: [{ productId: prodId, qty: 2 }],
    });
    check('produsul nou poate fi comandat', com.status === 201, `a dat ${com.status}`);
    const orderId = com.data?.order?.id;

    const d = await del(admin, '/products/' + prodId);
    check('produsul se scoate din catalog → 200', d.status === 200, `a dat ${d.status}`);

    const pub = await get(agent(), '/products');
    check('nu mai apare în catalogul public',
      !pub.data.products.some((x) => x.id === prodId));

    const adm = await get(admin, '/products?all=1');
    check('administratorul îl vede în continuare, ca inactiv',
      adm.data.products.some((x) => x.id === prodId && x.active === false));

    // Miezul: comanda veche rămâne întreagă.
    const o = await get(admin, '/orders/' + orderId);
    check('comanda veche își păstrează poziția', (o.data?.order?.items || []).length === 1,
      JSON.stringify(o.data?.order?.items));
    check('denumirea din comandă a rămas', (o.data?.order?.items || [])[0]?.name?.includes('probă'),
      (o.data?.order?.items || [])[0]?.name);

    const back = await patch(admin, '/products/' + prodId, { active: true });
    check('produsul poate fi repus în catalog', back.status === 200, `a dat ${back.status}`);
  }

  /* -------------------------------------------- cele două ștergeri ------- */
  console.log('\n=== ștergerea unui produs ===');
  {
    // Produs adăugat din greșeală, niciodată comandat: trebuie să dispară.
    const fara = await post(admin, '/products', {
      name: 'Fără preț ' + tag, nameEn: 'No price ' + tag,
      description: 'a', descriptionEn: 'b', category: slug,
    });
    check('produsul fără preț e respins → 422', fara.status === 422, `a dat ${fara.status}`);

    const r = await post(admin, '/products', {
      name: 'Greșeală ' + tag, nameEn: 'Mistake ' + tag,
      description: 'Adăugat din greșeală.', descriptionEn: 'Added by mistake.',
      category: slug, priceFrozen: 1.5, priceBaked: 2,
    });
    check('produs adăugat din greșeală → 201', r.status === 201, `a dat ${r.status}`);

    const d = await del(admin, '/products/' + r.data.id);
    check('se șterge de tot, nu doar se ascunde', d.data?.deleted === true, JSON.stringify(d.data));

    const adm = await get(admin, '/products?all=1');
    check('nu mai apare nici pentru administrator',
      !adm.data.products.some((x) => x.id === r.data.id));

    // Produsul comandat mai devreme s-a dezactivat, nu s-a șters.
    const still = adm.data.products.find((x) => x.id === prodId);
    check('produsul cu istoric rămâne în listă, ca inactiv sau activ', !!still,
      'produsul comandat a dispărut din baza de date');
  }

  /* --------------------------------------------- grupa cu produse în ea -- */
  console.log('\n=== protecția grupelor ===');
  {
    const d = await del(admin, '/categories/' + catId);
    check('o grupă cu produse nu se poate șterge → 409', d.status === 409, `a dat ${d.status}`);
    check('mesajul spune câte produse sunt',
      /produs/.test(d.data?.error?.message || ''), d.data?.error?.message);

    const h = await patch(admin, '/categories/' + catId, { active: false });
    check('o grupă cu produse active nu se poate ascunde → 409', h.status === 409, `a dat ${h.status}`);

    // Grupa golită doar prin scoaterea produselor din catalog: insigna din fila
    // Grupe și refuzul de la ștergere trebuie să numere aceleași produse.
    // Altfel adminul citește „0 produse", apasă × și i se răspunde că grupa are
    // produse în ea — două cifre care se contrazic pe același ecran.
    await patch(admin, '/products/' + prodId, { active: false });

    const inGrupa = ((await get(admin, '/products?all=1')).data?.products || [])
      .filter((p) => p.category === slug);
    const inCatalog = inGrupa.filter((p) => p.active).length;
    check('grupa de probă chiar are produse scoase din catalog',
      inGrupa.length > inCatalog, `${inGrupa.length} în grupă, ${inCatalog} în catalog`);

    const grupa = ((await get(admin, '/categories?all=1')).data?.categories || [])
      .find((c) => c.id === catId);
    check('insigna grupei numără produsele rămase în catalog',
      grupa?.products === inCatalog, `products = ${JSON.stringify(grupa?.products)}`);
    check('insigna grupei le numără și pe cele scoase din catalog',
      grupa?.productsAll === inGrupa.length, `productsAll = ${JSON.stringify(grupa?.productsAll)}`);

    const scoase = await del(admin, '/categories/' + catId);
    check('grupa cu produse doar scoase din catalog tot nu se șterge → 409',
      scoase.status === 409, `a dat ${scoase.status}`);
    const cifra = Number((scoase.data?.error?.message || '').match(/are (\d+) produs/)?.[1]);
    check('cifra din refuz e chiar cea pe care o arată insigna',
      Number.isFinite(cifra) && cifra === grupa?.productsAll,
      `refuzul spune ${cifra}, insigna ar arăta ${JSON.stringify(grupa?.productsAll)}`);

    await patch(admin, '/products/' + prodId, { active: true });
  }

} finally {
  // curățenie: produsul de probă și grupa lui
  // Produsul de probă a fost comandat, deci ștergerea îl dezactivează. Îl mutăm
  // într-o grupă existentă ca grupa de probă să rămână goală și ștergibilă.
  if (prodId) {
    const cats = (await get(admin, '/categories')).data.categories;
    const first = cats.find((c) => c.id !== catId);
    if (first) await patch(admin, '/products/' + prodId, { category: first.slug });
    await del(admin, '/products/' + prodId);
  }
  if (catId) {
    const r = await del(admin, '/categories/' + catId);
    if (r.status !== 200) {
      console.log('  —     grupa de probă a rămas (' + (r.data?.error?.message || r.status) + ')');
    }
  }
}

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} eșuate`);
if (failures.length) {
  console.log('\nEșecuri:');
  failures.forEach((f) => console.log('  · ' + f));
}
process.exit(fail ? 1 : 0);
