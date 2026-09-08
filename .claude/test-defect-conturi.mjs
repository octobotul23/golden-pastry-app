// Adresa de e-mail a unui cont: formatul și unicitatea ei.
//
//   node .claude/test-defect-conturi.mjs
//
// Două defecte legate între ele, amândouă despre același câmp:
//
//   1. PATCH /users/{id} scria orice text în `users.email`, fără să se uite la
//      el (api/routes/users.php). POST /users refuza aceeași valoare cu 422.
//      O adresă strâmbă nu se plânge la salvare, dar face ca „am uitat parola"
//      să nu mai poată trimite nimic — și nimeni nu află de ce.
//
//   2. Nimic nu oprea două conturi să aibă aceeași adresă, iar /auth/forgot
//      alegea între ele cu `LIMIT 1` fără ORDER BY (api/routes/auth.php).
//      Omul de pe al doilea cont cerea parolă nouă și primea un link care,
//      folosit, schimba parola primului cont și îi ștergea sesiunile.
//
// Ca și test-parola.mjs, proba se uită și direct în baza de date: tokenul de
// recuperare pleacă pe e-mail și nu se întoarce niciodată prin API, deci
// singurul loc unde se vede PE CE CONT a fost scris e tabelul `password_resets`.
//
// Rulează doar pe baza de dezvoltare; pe oricare alta se oprește singură.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BAZA_DE_PROBA, CERE_CONFIG } from './baza-probei.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';

let pass = 0, fail = 0;
const failures = [];
const check = (name, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
};

/* ------------------------------------------------------------ unelte -- */

function binar(nume, folder) {
  const env = process.env['GP_' + nume.toUpperCase()];
  if (env) return env;
  const guess = 'C:/laragon/bin/' + folder;
  try {
    const v = fs.readdirSync(guess).find((d) => d.startsWith(folder + '-'));
    if (v) return folder === 'mysql' ? path.join(guess, v, 'bin', 'mysql.exe') : path.join(guess, v, 'php.exe');
  } catch { /* alt sistem */ }
  return nume;
}
const PHP = binar('php', 'php');
const MYSQL = binar('mysql', 'mysql');

let db;
try {
  db = JSON.parse(execFileSync(PHP, ['-r', '$c = require ' + CERE_CONFIG + '; echo json_encode($c["db"]);'],
    { cwd: ROOT, encoding: 'utf8' }));
} catch (e) {
  console.log('  —     nu pot citi api/config.php (' + String(e.message).split('\n')[0] + ')');
  process.exit(0);
}
if (!BAZA_DE_PROBA.test(db.name)) {
  console.log(`  —     sărit: baza „${db.name}” nu e una de dezvoltare`);
  process.exit(0);
}

const sql = (q) => execFileSync(MYSQL, [
  '--default-character-set=utf8mb4',
  '-u', db.user, ...(db.pass ? ['-p' + db.pass] : []),
  '-h', db.host, '-P', String(db.port), db.name,
  '-N', '-B', '-e', q,
], { encoding: 'utf8' }).trim();

const agent = () => ({ cookies: new Map(), csrf: '' });
async function call(a, method, p, body) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (a.csrf && method !== 'GET') headers['X-CSRF-Token'] = a.csrf;
  if (a.cookies.size) headers['Cookie'] = [...a.cookies].map(([k, v]) => `${k}=${v}`).join('; ');

  const res = await fetch(BASE + p, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
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
    catch { throw new Error('răspuns care nu este JSON la ' + method + ' ' + p + ': ' + raw.slice(0, 160)); }
  }
  return { status: res.status, data };
}
const get = (a, p) => call(a, 'GET', p);
const post = (a, p, b) => call(a, 'POST', p, b ?? {});
const patch = (a, p, b) => call(a, 'PATCH', p, b);
const del = (a, p) => call(a, 'DELETE', p);

/* --------------------------------------------------------- pregătire -- */

const ADMIN = { u: 'testadmin', p: 'Parola-Test-1234' };
// Conturile probei. Numele lor nu seamănă cu ale nimănui altcuiva: alte suite
// rulează în același timp, pe aceeași bază.
const A = { u: 'proba-email-nord', name: 'Proba Nord', mail: 'proba-email-nord@exemplu.test' };
const B = { u: 'proba-email-sud',  name: 'Proba Sud',  mail: 'proba-email-sud@exemplu.test' };
const COMUN = 'comenzi@proba-email-comun.test';
const STRAMB = 'ion.popescu@yahoocom';   // punctul lipsă din „.com" — greșeala obișnuită

const chei = `'${A.u}', '${B.u}'`;
// Toate conturile probei încep cu prefixul ăsta, inclusiv cele două create doar
// ca să fie refuzate. O rulare căzută la mijloc le-ar lăsa în urmă, iar
// următoarea ar pica pe „utilizator duplicat" — adică ar arăta ca o defecțiune
// a aplicației, când de fapt e o urmă rămasă de dinainte.
const PREFIX = 'proba-email-';

function curata() {
  // Rândurile din `password_resets` pleacă înaintea conturilor: doar ale
  // conturilor noastre, ca să nu ștergem tokenii altei suite care rulează
  // chiar acum.
  sql(`DELETE FROM password_resets WHERE user_id IN (SELECT id FROM users WHERE username LIKE '${PREFIX}%')`);
  sql(`DELETE FROM users WHERE username LIKE '${PREFIX}%'`);
}

const sef = agent();
{
  const l = await post(sef, '/auth/login', { username: ADMIN.u, password: ADMIN.p });
  if (l.status !== 200) {
    console.log(`  —     nu mă pot autentifica drept ${ADMIN.u} (a dat ${l.status}); serverul de dezvoltare rulează?`);
    process.exit(1);
  }
  sef.csrf = l.data?.csrf || '';
}

curata();
// Pragul cererilor de parolă nouă e pe IP și se numără din `login_attempts`.
// Fără linia asta, o suită rulată înainte de a noastră ne-ar lăsa fără cele
// două cereri de mai jos, iar proba ar pica din motive care n-au legătură cu ea.
sql("DELETE FROM login_attempts WHERE username = 'recuperare'");

let idA = null, idB = null;
{
  const a = await post(sef, '/users', { username: A.u, name: A.name, password: 'Parola-Proba-1234', role: 'client', email: A.mail });
  const b = await post(sef, '/users', { username: B.u, name: B.name, password: 'Parola-Proba-1234', role: 'client', email: B.mail });
  idA = a.data?.user?.id;
  idB = b.data?.user?.id;
  check('conturile probei se creează → 201', a.status === 201 && b.status === 201 && idA && idB,
    `a dat ${a.status}/${b.status}`);
  if (!idA || !idB) {
    console.log('\nNu pot continua fără conturile probei.');
    process.exit(1);
  }
}

const contul = async (id) => ((await get(sef, '/users')).data?.users || []).find((u) => u.id === id);

/* ------------------------- 1. formatul adresei, și la editare, nu doar la creare -- */

console.log('\n=== formatul adresei la PATCH /users/{id} ===');
{
  // Control: aceeași valoare, pe drumul de creare, era refuzată dinainte.
  const laCreare = await post(sef, '/users', {
    username: 'proba-email-control', name: 'Control', password: 'Parola-Proba-1234', role: 'client', email: STRAMB,
  });
  check('POST /users cu adresă strâmbă → 422 bad_email (control)',
    laCreare.status === 422 && laCreare.data?.error?.code === 'bad_email',
    `a dat ${laCreare.status} ${laCreare.data?.error?.code}`);
  if (laCreare.status === 201) await del(sef, '/users/' + laCreare.data.user.id);

  const r = await patch(sef, '/users/' + idA, { email: STRAMB });
  check('PATCH /users/{id} cu adresă strâmbă → 422 bad_email',
    r.status === 422 && r.data?.error?.code === 'bad_email',
    `a dat ${r.status} ${r.data?.error?.code}`);

  const dupa = await contul(idA);
  check('adresa dinainte rămâne pe cont, nu e înlocuită cu cea strâmbă',
    dupa?.email === A.mail, JSON.stringify(dupa?.email));
}
{
  // Cealaltă față a regulii: o adresă bună trebuie să treacă mai departe.
  const bun = await patch(sef, '/users/' + idA, { email: 'proba-email-nord2@exemplu.test' });
  check('PATCH cu o adresă validă și liberă → 200', bun.status === 200, `a dat ${bun.status} ${bun.data?.error?.code}`);
  const gol = await patch(sef, '/users/' + idA, { email: '' });
  check('PATCH cu adresa golită → 200 (contul fără e-mail e o stare normală)',
    gol.status === 200, `a dat ${gol.status} ${gol.data?.error?.code}`);
  await patch(sef, '/users/' + idA, { email: A.mail });
}

/* ---------------------------------- 2. aceeași adresă pe două conturi -- */

console.log('\n=== unicitatea adresei ===');
{
  const r = await post(sef, '/users', {
    username: 'proba-email-tert', name: 'Terț', password: 'Parola-Proba-1234', role: 'client', email: A.mail,
  });
  check('POST /users cu adresa altui cont → 409 email_taken',
    r.status === 409 && r.data?.error?.code === 'email_taken',
    `a dat ${r.status} ${r.data?.error?.code}`);
  if (r.status === 201) await del(sef, '/users/' + r.data.user.id);
}
{
  const r = await patch(sef, '/users/' + idB, { email: A.mail });
  check('PATCH cu adresa altui cont → 409 email_taken',
    r.status === 409 && r.data?.error?.code === 'email_taken',
    `a dat ${r.status} ${r.data?.error?.code}`);

  // Coloana e utf8mb4_unicode_ci: majusculele duc la aceeași cutie poștală,
  // deci nu sunt o cale de ocolire.
  const mari = await patch(sef, '/users/' + idB, { email: A.mail.toUpperCase() });
  check('PATCH cu adresa altui cont scrisă cu majuscule → 409 email_taken',
    mari.status === 409 && mari.data?.error?.code === 'email_taken',
    `a dat ${mari.status} ${mari.data?.error?.code}`);

  const dupa = await contul(idB);
  check('contul refuzat își păstrează adresa lui', dupa?.email === B.mail, JSON.stringify(dupa?.email));
}
{
  // Pagina Conturi salvează la ieșirea din casetă, deci trimite câmpul și
  // când nu l-a atins nimeni. Propria adresă nu se poate certa cu ea însăși.
  const r = await patch(sef, '/users/' + idB, { name: B.name, email: B.mail });
  check('PATCH care retrimite adresa contului însuși → 200',
    r.status === 200, `a dat ${r.status} ${r.data?.error?.code}`);
}

/* ------------- 3. rândurile duplicate rămase în bază de dinainte de regulă -- */

console.log('\n=== „am uitat parola" pe o adresă purtată de două conturi ===');
{
  // Regula de mai sus oprește scrierile noi, dar nu poate șterge trecutul:
  // instalările de până acum au putut ajunge la două conturi cu aceeași
  // adresă. Le punem în starea aceea direct în bază, cum ar fi rămas ele.
  //
  // De la migrarea 007, `users.email` are index unic (`uq_users_email`), deci
  // starea asta nu se mai poate NAȘTE — dar poate fi MOȘTENITĂ: pe o bază
  // veche, cu duplicate, pasul care adaugă indexul se sare (vezi comentariul
  // din sql/007_update_roles_referral.sql), iar rândurile rămân acolo. Exact
  // acea bază o imităm aici: scoatem indexul cât fabricăm situația și îl punem
  // la loc imediat. Ce se verifică mai jos — că /auth/forgot NU ghicește când
  // adresa arată spre două conturi — rămâne neschimbat, fiindcă acel cod de pe
  // server e neschimbat și trebuie să funcționeze pe bazele moștenite.
  const areIndexEmail = Number(sql(
    "SELECT COUNT(*) FROM information_schema.STATISTICS "
    + "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' "
    + "AND INDEX_NAME = 'uq_users_email'"
  )) > 0;
  if (areIndexEmail) sql('ALTER TABLE users DROP INDEX uq_users_email');
  sql(`UPDATE users SET email = '${COMUN}' WHERE username IN (${chei})`);
  sql(`DELETE FROM password_resets WHERE user_id IN (${idA}, ${idB})`);

  const r = await post(agent(), '/auth/forgot', { username: COMUN });
  check('cererea pe adresa ambiguă răspunde tot 200, cu mesajul uniform',
    r.status === 200 && typeof r.data?.message === 'string',
    `a dat ${r.status} ${JSON.stringify(r.data)}`);

  // Miezul defectului: înainte, aici se scria un token pe contul ales de
  // bază — adică pe contul omului care NU ceruse nimic.
  const scrise = sql(`SELECT COUNT(*) FROM password_resets WHERE user_id IN (${idA}, ${idB})`);
  check('nu se scrie niciun link de resetare când adresa arată spre două conturi',
    Number(scrise) === 0, `au apărut ${scrise} rânduri în password_resets`);
}
{
  // Calea sigură rămâne deschisă: utilizatorul are index unic, deci o cerere
  // pe el nu poate nimeri alt cont.
  sql(`DELETE FROM password_resets WHERE user_id IN (${idA}, ${idB})`);
  const r = await post(agent(), '/auth/forgot', { username: B.u });
  check('cererea pe numele de utilizator răspunde 200', r.status === 200, `a dat ${r.status}`);
  const pe = sql(`SELECT GROUP_CONCAT(user_id) FROM password_resets WHERE user_id IN (${idA}, ${idB})`);
  check('linkul se scrie pe contul cerut, nu pe celălalt',
    pe === String(idB), `a fost scris pe ${pe || '(niciunul)'}, așteptat ${idB}`);
}
{
  // Un rând duplicat rămas din trecut nu trebuie să blocheze administratorul:
  // altfel n-ar mai putea nici să-l corecteze, nici să schimbe altceva pe el.
  const alt = await patch(sef, '/users/' + idA, { name: 'Proba Nord Corectat' });
  check('un rând duplicat vechi rămâne editabil pe celelalte câmpuri → 200',
    alt.status === 200, `a dat ${alt.status} ${alt.data?.error?.code}`);
  const acelasi = await patch(sef, '/users/' + idA, { name: A.name, email: COMUN });
  check('și acceptă retrimiterea adresei lui duplicate, netinsă → 200',
    acelasi.status === 200, `a dat ${acelasi.status} ${acelasi.data?.error?.code}`);
  const iesire = await patch(sef, '/users/' + idA, { email: A.mail });
  check('iar mutarea lui pe o adresă liberă îl scoate din duplicat → 200',
    iesire.status === 200, `a dat ${iesire.status} ${iesire.data?.error?.code}`);
}

/* ------------------------------------------------------------ final -- */

curata();
sql("DELETE FROM login_attempts WHERE username = 'recuperare'");
// Indexul unic pe adresă se pune la loc, dacă proba l-a scos ca să fabrice
// starea moștenită de mai sus. Se face DUPĂ curata(), care șterge conturile
// duplicate — altfel ALTER-ul s-ar lovi chiar de rândurile probei. Condiția pe
// information_schema face pasul sigur pe o bază pe care migrarea 007 n-a fost
// rulată: acolo nu era niciun index de pus la loc.
sql(
  "SET @s := IF((SELECT COUNT(*) FROM information_schema.STATISTICS "
  + "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' "
  + "AND INDEX_NAME = 'uq_users_email') = 0 "
  + "AND (SELECT COUNT(*) FROM information_schema.COLUMNS "
  + "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' "
  + "AND COLUMN_NAME = 'referral_code') = 1, "
  + "'ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)', 'SELECT 1'); "
  + "PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st"
);

console.log(`\n${'='.repeat(52)}`);
console.log(`${pass} trecute · ${fail} eșuate`);
if (fail) { console.log('\nESUATE:'); for (const f of failures) console.log('  x ' + f); }
console.log();
process.exit(fail ? 1 : 0);
