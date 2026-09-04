// Probele pentru ultimele două fișe ale depozitului — amândouă despre greșeli
// omenești care ieșeau ca „Eroare internă":
//
//   · datele prea lungi (numele unui ambalaj lipit din alt document, unitatea,
//     pragul minim cu prea multe cifre, motivul și mențiunea unei mișcări)
//     treceau neverificate, ajungeau la MySQL și se întorceau ca 1406 / 1264,
//     adică 500 `db_error`. Depozitarul rămânea cu caseta deschisă și fără să
//     afle care câmp e de vină. Redenumirea peste numele altui articol pica la
//     fel, deși crearea cu același nume răspundea politicos 409;
//   · doi oameni care adaugă în aceeași clipă același articol (sau același
//     cont) treceau amândoi de verificarea din memorie, iar al doilea se izbea
//     de indexul unic — tot 500.
//
//   php -S 127.0.0.1:8100 -t . .claude/dev-server.php
//   node .claude/test-reparat-depozit-refuzuri.mjs
//
// Fiecare verificare de aici a fost măsurată dând reparația înapoi.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { configulLuiBase } from './baza-probei.mjs';

const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

let pass = 0, fail = 0;
const failures = [];
const check = (name, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
};
const opreste = (mesaj) => { console.error('\nProba nu poate continua: ' + mesaj); process.exit(2); };

/* --------------------------------------------------------- unelte locale -- */

const PHP = process.env.GP_PHP || (() => {
  try {
    const v = fs.readdirSync('C:/laragon/bin/php').find((x) => x.startsWith('php-'));
    if (v) return path.join('C:/laragon/bin/php', v, 'php.exe');
  } catch { /* alt sistem */ }
  return 'php';
})();

const CFG = configulLuiBase(BASE) || path.join(ROOT, 'api', 'config.php');

/** Rulează ajutorul în PHP și întoarce ce a scris la ieșire. */
function inPhp(ce, nume) {
  const r = { status: 0, text: '', data: null };
  try {
    r.text = execFileSync(PHP, [path.join(ROOT, '.claude', 'proba-unicitate.php'), ROOT, ce, nume], {
      cwd: ROOT, encoding: 'utf8', env: Object.assign({}, process.env, { GP_CONFIG: CFG }),
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch (e) {
    r.status = e.status ?? 1;
    r.text = (e.stdout || '') + (e.stderr || '');
  }
  try { r.data = JSON.parse(r.text); } catch { /* n-a ieșit JSON */ }
  return r;
}

/* ------------------------------------------------------------ HTTP simplu -- */

const agent = () => ({ cookies: new Map(), csrf: '' });

async function call(a, method, p, body) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (a.csrf && method !== 'GET') headers['X-CSRF-Token'] = a.csrf;
  if (a.cookies.size) headers['Cookie'] = [...a.cookies].map(([k, v]) => `${k}=${v}`).join('; ');

  const res = await fetch(BASE + p, {
    method, headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  for (const raw of res.headers.getSetCookie?.() ?? []) {
    const [pair] = raw.split(';');
    const i = pair.indexOf('=');
    const k = pair.slice(0, i).trim(), v = pair.slice(i + 1).trim();
    if (v === '') a.cookies.delete(k); else a.cookies.set(k, v);
  }
  const brut = await res.text();
  let data = null;
  if (brut !== '') {
    try { data = JSON.parse(brut); }
    catch { throw new Error('răspuns care nu e JSON la ' + method + ' ' + p + ': ' + brut.slice(0, 200).replace(/\s+/g, ' ')); }
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
  if (r.data?.user?.mustChangePassword) {
    await post(admin, '/auth/password', { current: 'Parola-Test-1234', next: 'Parola-Test-1234' });
  }
}

const sufix = Math.random().toString(36).slice(2, 8);
const nume = (ce) => 'Probă Refuz ' + ce + ' ' + sufix;

const CAT = ((await get(admin, '/stock/items')).data?.categories || [])[0] || 'Produse finite';

const articol = async (n, extra = {}) => {
  const r = await post(admin, '/stock/items', Object.assign({ name: n, category: CAT, unit: 'buc' }, extra));
  if (r.status !== 201) opreste('nu pot face articolul „' + n + '”: ' + scurt(r));
  return r.data.id;
};

/* ===== 1. datele prea lungi la articol nou =============================== */

console.log('\n=== articol nou: ce e prea lung se refuză lămurit, nu cu „Eroare internă” ===');
{
  const lung = await post(admin, '/stock/items', { name: 'A'.repeat(200), category: CAT, unit: 'buc' });
  check('numele de 200 de caractere → 422 field_too_long, nu 500',
    lung.status === 422 && cod(lung) === 'field_too_long', scurt(lung));
  check('refuzul spune câte caractere sunt și câte se pot',
    /200/.test(String(lung.data?.error?.message || '')) && /160/.test(String(lung.data?.error?.message || '')),
    JSON.stringify(lung.data?.error?.message));

  const unit = await post(admin, '/stock/items', { name: nume('unitate'), category: CAT, unit: 'u'.repeat(40) });
  check('unitatea de 40 de caractere → 422, nu 500',
    unit.status === 422 && cod(unit) === 'field_too_long', scurt(unit));

  const prag = await post(admin, '/stock/items', {
    name: nume('prag'), category: CAT, unit: 'buc', minQty: 99999999999,
  });
  check('pragul minim cu prea multe cifre → 422 bad_min, nu 500',
    prag.status === 422 && cod(prag) === 'bad_min', scurt(prag));

  const tablou = await post(admin, '/stock/items', {
    name: nume('prag tablou'), category: CAT, unit: 'buc', minQty: [5],
  });
  check('pragul trimis ca tablou → 422, nu prag tăcut de o bucată',
    tablou.status === 422 && cod(tablou) === 'bad_min', scurt(tablou));

  const negativ = await post(admin, '/stock/items', {
    name: nume('prag negativ'), category: CAT, unit: 'buc', minQty: -1,
  });
  check('pragul negativ rămâne refuzat, ca înainte',
    negativ.status === 422 && cod(negativ) === 'bad_min', scurt(negativ));

  // Și că poarta nu s-a închis peste ce e bun: exact la limită încă se poate.
  const laLimita = await post(admin, '/stock/items', {
    name: 'Probă Refuz ' + sufix + ' ' + 'L'.repeat(160 - ('Probă Refuz ' + sufix + ' ').length),
    category: CAT, unit: 'buc', minQty: 5,
  });
  check('un nume de fix 160 de caractere încă trece → 201', laLimita.status === 201, scurt(laLimita));

  const gol = await post(admin, '/stock/items', { name: '   ', category: CAT, unit: 'buc' });
  check('numele gol rămâne refuzat, ca înainte',
    gol.status === 422 && cod(gol) === 'missing_field', scurt(gol));
}

/* ===== 2. datele prea lungi și numele luat, la corectarea articolului ==== */

console.log('\n=== articol corectat: aceeași măsură ca la creare ===');
{
  const numeA = nume('A');
  const numeB = nume('B');
  const a = await articol(numeA);
  await articol(numeB);

  const luat = await patch(admin, '/stock/items/' + a, { name: numeB });
  check('redenumirea peste numele altui articol → 409 name_taken, nu 500',
    luat.status === 409 && cod(luat) === 'name_taken', scurt(luat));
  check('refuzul spune care nume e ocupat',
    String(luat.data?.error?.message || '').includes(numeB),
    JSON.stringify(luat.data?.error?.message));

  const acelasi = await patch(admin, '/stock/items/' + a, { name: numeA });
  check('articolul se poate salva cu numele lui → 200', acelasi.status === 200, scurt(acelasi));

  const lung = await patch(admin, '/stock/items/' + a, { name: 'B'.repeat(200) });
  check('numele de 200 de caractere, la corectare → 422, nu 500',
    lung.status === 422 && cod(lung) === 'field_too_long', scurt(lung));

  const unit = await patch(admin, '/stock/items/' + a, { unit: 'u'.repeat(40) });
  check('unitatea prea lungă, la corectare → 422, nu 500',
    unit.status === 422 && cod(unit) === 'field_too_long', scurt(unit));

  const prag = await patch(admin, '/stock/items/' + a, { minQty: 99999999999 });
  check('pragul cu prea multe cifre, la corectare → 422, nu 500',
    prag.status === 422 && cod(prag) === 'bad_min', scurt(prag));

  const bun = await patch(admin, '/stock/items/' + a, { minQty: 12.5, unit: 'kg' });
  check('o corectură bună trece mai departe → 200', bun.status === 200, scurt(bun));
  const dupa = ((await get(admin, '/stock/items')).data?.items || []).find((i) => i.id === a);
  check('și chiar se salvează', dupa?.minQty === 12.5 && dupa?.unit === 'kg',
    JSON.stringify({ minQty: dupa?.minQty, unit: dupa?.unit }));

  // Numele articolului rămâne cel dinainte după fiecare refuz.
  check('numele articolului a rămas neatins după refuzuri', dupa?.name === numeA, dupa?.name);
}

/* ===== 3. motivul și mențiunea unei mișcări ============================== */

console.log('\n=== intrare de marfă: motivul și mențiunea au margini ===');
{
  const id = await articol(nume('mișcări'));

  const motiv = await post(admin, '/stock/moves', {
    itemId: id, qty: 10, direction: 1, reason: 'M'.repeat(200),
  });
  check('motivul de 200 de caractere → 422, nu 500',
    motiv.status === 422 && cod(motiv) === 'field_too_long', scurt(motiv));

  const nota = await post(admin, '/stock/moves', {
    itemId: id, qty: 10, direction: 1, reason: 'Intrare probă', note: 'n'.repeat(400),
  });
  check('mențiunea de 400 de caractere → 422, nu 500',
    nota.status === 422 && cod(nota) === 'field_too_long', scurt(nota));

  const qtyTablou = await post(admin, '/stock/moves', { itemId: id, qty: [10], direction: 1 });
  check('cantitatea trimisă ca tablou → 422 bad_qty, nu o bucată tăcută',
    qtyTablou.status === 422 && cod(qtyTablou) === 'bad_qty', scurt(qtyTablou));

  const qtyDa = await post(admin, '/stock/moves', { itemId: id, qty: true, direction: 1 });
  check('cantitatea trimisă ca „da” → 422 bad_qty',
    qtyDa.status === 422 && cod(qtyDa) === 'bad_qty', scurt(qtyDa));

  const text = await post(admin, '/stock/moves', {
    itemId: id, qty: '7.5', direction: 1, reason: 'Intrare probă',
  });
  check('cantitatea scrisă ca text tot trece → 201', text.status === 201, scurt(text));

  const zero = await post(admin, '/stock/moves', { itemId: id, qty: 0, direction: 1 });
  check('cantitatea zero rămâne refuzată, ca înainte',
    zero.status === 422 && cod(zero) === 'bad_qty', scurt(zero));

  const bun = await post(admin, '/stock/moves', {
    itemId: id, qty: 25, direction: 1, reason: 'R'.repeat(96), note: 'N'.repeat(255),
  });
  check('motivul și mențiunea exact la limită încă trec → 201', bun.status === 201, scurt(bun));

  const sold = ((await get(admin, '/stock/items')).data?.items || []).find((i) => i.id === id);
  check('în depozit au intrat doar mișcările primite (7,5 + 25)',
    sold?.qty === 32.5, 'sold = ' + JSON.stringify(sold?.qty));
}

/* ===== 4. ciocnirea pe indexul unic ===================================== */

console.log('\n=== doi care scriu în aceeași clipă: refuz lămurit, nu „Eroare internă” ===');
{
  // Pe rând, cele două cereri primesc de mult răspunsul bun — asta o verificăm
  // prin HTTP, ca să se vadă că poarta din memorie n-a fost stricată.
  const n = nume('pe rând');
  await articol(n);
  const iar = await post(admin, '/stock/items', { name: n, category: CAT, unit: 'buc' });
  check('al doilea articol cu același nume, pe rând → 409 name_taken',
    iar.status === 409 && cod(iar) === 'name_taken', scurt(iar));

  // Suprapunerea, însă, nu se poate stârni prin `php -S`: are un singur proces,
  // deci cererile se așază la rând. O cerem direct de la bază.
  const numeArt = nume('ciocnire');
  const art = inPhp('articol', numeArt);
  check('scrierea care pierde cursa nu mai urcă drept excepție',
    art.data?.error?.code === 'name_taken',
    'a ieșit: ' + art.text.slice(0, 200).replace(/\s+/g, ' '));
  check('și îi spune omului care nume e deja luat',
    String(art.data?.error?.message || '').includes(numeArt),
    JSON.stringify(art.data?.error?.message));
  inPhp('curata', numeArt);

  const numeCont = 'probarefuz' + sufix;
  const cont = inPhp('cont', numeCont);
  check('la conturi, aceeași ciocnire dă tot un refuz lămurit',
    cont.data?.error?.code === 'username_taken',
    'a ieșit: ' + cont.text.slice(0, 200).replace(/\s+/g, ' '));
  inPhp('curata', numeCont);

  // Și pe drumul obișnuit contul dublat răspunde la fel, ca înainte.
  const u1 = await post(admin, '/users', {
    username: numeCont, name: 'Probă Refuz Unicitate', password: 'Parola-Probă-1234', role: 'client',
  });
  check('cont nou de probă → 201', u1.status === 201, scurt(u1));
  const u2 = await post(admin, '/users', {
    username: numeCont, name: 'Probă Refuz Unicitate', password: 'Parola-Probă-1234', role: 'client',
  });
  check('al doilea cont cu același utilizator → 409 username_taken',
    u2.status === 409 && cod(u2) === 'username_taken', scurt(u2));
}

/* -------------------------------------------------------------- final -- */

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} eșuate`);
if (fail) {
  console.log('\nEȘUATE:');
  failures.forEach((f) => console.log('  · ' + f));
}
process.exit(fail ? 1 : 0);
