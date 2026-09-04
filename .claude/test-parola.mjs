// Recuperarea parolei: de la „am uitat-o" până la parola nouă.
//
//   node .claude/test-parola.mjs
//
// Spre deosebire de celelalte suite, aceasta citește și direct din baza de
// date. N-are încotro: tokenul pleacă pe e-mail și nu se întoarce niciodată
// prin API — tocmai asta e ideea. Ca să verificăm ce se întâmplă cu el, îl
// punem noi în rând, exact cum l-ar fi pus serverul.
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

const php = (cod, ...arg) => execFileSync(PHP, ['-r', cod, ...arg], { encoding: 'utf8' }).trim();

/** Un token nou, plus rândul care îl face valabil — cum l-ar fi scris serverul. */
function tokenPentru(username, { minute = 60 } = {}) {
  const t = php('echo bin2hex(random_bytes(32));');
  const h = php('echo hash("sha256", $argv[1]);', t);
  sql(`INSERT INTO password_resets (user_id, token_hash, expires_at)
       SELECT id, '${h}', UTC_TIMESTAMP() + INTERVAL ${minute} MINUTE
         FROM users WHERE username = '${username}'`);
  return t;
}

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

/* --------------------------------------------------------- scenariul -- */

const CLIENT = { u: 'testclient', p: 'Parola-Test-9012' };
const NOUA = 'Parola-Schimbata-4321';
const anon = agent();

// Pornim de la zero: rândurile rămase de la o rulare dinainte și pragul de
// cereri pe IP ar face proba să depindă de ce s-a întâmplat înainte.
sql('DELETE FROM password_resets');
sql("DELETE FROM login_attempts WHERE username IN ('recuperare', 'testclient')");
// Contul de probă nu vine de la sine. Pe „gp_dev” el e acolo fiindcă proba
// dinainte din suită (test-api) îl face și îl lasă în urmă — dar pe o bază
// proaspătă, cum e a unei instanțe izolate, nu există. Fără el, „SELECT id …”
// întorcea gol, iar golul intra într-un UPDATE și proba murea cu o eroare de
// sintaxă MySQL, adică arăta ca o defecțiune a aplicației. Îl facem noi, ca
// proba să nu depindă de ordinea în care e chemată.
if (sql(`SELECT id FROM users WHERE username = '${CLIENT.u}'`) === '') {
  const sef = agent();
  const l = await post(sef, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  if (l.status !== 200) {
    console.log('  —     sărit: nu pot intra ca administrator ca să fac contul de probă');
    process.exit(0);
  }
  sef.csrf = l.data?.csrf || '';
  const f = await post(sef, '/users', {
    username: CLIENT.u, password: CLIENT.p, name: 'Client Test',
    role: 'client', customerName: 'Cafeneaua Test',
    phone: '0722 111 222', email: 'comenzi@cafeneaua-test.ro',
  });
  if (f.status !== 201) {
    console.error('Proba nu poate continua: nu pot face contul „' + CLIENT.u + '”: '
      + f.status + ' ' + JSON.stringify(f.data?.error?.code || ''));
    process.exit(2);
  }
}
// Contul pornește cu parola pe care o știu celelalte suite și fără blocare.
// O rulare căzută la mijloc lasă altă parolă sau un cont blocat, iar
// următoarea ar pica din prima — arătând ca o defecțiune a aplicației, când
// de fapt e o urmă rămasă de dinainte.
{
  const h = php('echo password_hash($argv[1], PASSWORD_DEFAULT);', CLIENT.p);
  sql(`UPDATE users SET password_hash = '${h}', failed_logins = 0, locked_until = NULL
        WHERE username = '${CLIENT.u}'`);
}

console.log('\n=== cererea de parolă nouă ===');
let mesajGeneric = '';
{
  const r = await post(anon, '/auth/forgot', { username: 'nu-exista-contul-asta' });
  mesajGeneric = r.data?.message || '';
  check('cont inexistent → 200', r.status === 200, `a dat ${r.status}`);
  check('nu se creează nimic pentru un cont inexistent',
    sql('SELECT COUNT(*) FROM password_resets') === '0', sql('SELECT COUNT(*) FROM password_resets'));
}
{
  const r = await post(anon, '/auth/forgot', { username: CLIENT.u });
  check('cont real → 200', r.status === 200, `a dat ${r.status}`);
  // Dacă mesajele ar diferi, formularul ar spune oricui care utilizatori
  // sunt reali — adică i-ar da unui atacator lista de ținte.
  check('mesajul e identic cu cel pentru un cont inexistent',
    r.data?.message === mesajGeneric, r.data?.message);
  check('se creează o cerere', sql('SELECT COUNT(*) FROM password_resets') === '1');
}
{
  const r = await post(anon, '/auth/forgot', { username: 'comenzi@cafeneaua-test.ro' });
  check('merge și după adresa de e-mail, nu doar după utilizator', r.status === 200);
  // Un singur link valabil: cel din urmă e-mail. Altfel un token vechi,
  // ajuns la altcineva, ar rămâne bun luni de zile.
  check('cererea dinainte se închide când se cere alta',
    sql('SELECT COUNT(*) FROM password_resets WHERE used_at IS NULL') === '1',
    sql('SELECT COUNT(*) FROM password_resets WHERE used_at IS NULL'));
}
{
  const r = await post(anon, '/auth/forgot', { username: 'panou' });
  check('contul de sistem al tabletelor nu primește link',
    r.status === 200 && sql("SELECT COUNT(*) FROM password_resets pr JOIN users u ON u.id = pr.user_id WHERE u.username = 'panou'") === '0');
}

console.log('\n=== tokenul ===');
{
  const r = await post(anon, '/auth/reset', { token: 'nu-e-hexa', password: NOUA });
  check('token fără formă de token → 422', r.status === 422, `a dat ${r.status} ${r.data?.error?.code}`);
}
{
  const r = await post(anon, '/auth/reset', { token: 'a'.repeat(64), password: NOUA });
  check('token bine format dar necunoscut → 410', r.status === 410, `a dat ${r.status} ${r.data?.error?.code}`);
}
{
  const vechi = tokenPentru(CLIENT.u, { minute: -5 });
  const r = await post(anon, '/auth/reset', { token: vechi, password: NOUA });
  check('token expirat → 410', r.status === 410, `a dat ${r.status} ${r.data?.error?.code}`);
}
{
  const t = tokenPentru(CLIENT.u);
  const r = await post(anon, '/auth/reset', { token: t, password: 'scurta' });
  check('parolă prea scurtă → 422', r.status === 422, `a dat ${r.status} ${r.data?.error?.code}`);
  // Agent nou dinadins: dacă ne-am autentifica pe `anon`, el ar căpăta o
  // sesiune, iar cererile lui de mai jos ar fi respinse pentru CSRF lipsă —
  // ceea ce arată ca o eroare a aplicației, dar e a probei.
  const dupa = await post(agent(), '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  check('o parolă respinsă nu strică parola veche', dupa.status === 200, `a dat ${dupa.status}`);
}

console.log('\n=== schimbarea propriu-zisă ===');
{
  // O sesiune deschisă dinainte, ca să vedem că se închide.
  const vechea = agent();
  const l = await post(vechea, '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  vechea.csrf = l.data?.csrf || '';
  check('sesiune deschisă înainte de schimbare', (await get(vechea, '/auth/me')).data?.user?.username === CLIENT.u);

  const t = tokenPentru(CLIENT.u);
  const r = await post(anon, '/auth/reset', { token: t, password: NOUA });
  check('schimbarea reușește → 200', r.status === 200, `a dat ${r.status} ${JSON.stringify(r.data)}`);

  const noua = await post(agent(), '/auth/login', { username: CLIENT.u, password: NOUA });
  check('parola nouă merge', noua.status === 200, `a dat ${noua.status}`);
  const veche = await post(agent(), '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  check('parola veche nu mai merge', veche.status === 401, `a dat ${veche.status}`);

  // Dacă ai cerut parolă nouă fiindcă bănuiești că o mai știe cineva,
  // sesiunile lui trebuie să dispară odată cu ea.
  const inca = await get(vechea, '/auth/me');
  check('sesiunile deschise dinainte se închid', !inca.data?.user, JSON.stringify(inca.data));

  const iar = await post(anon, '/auth/reset', { token: t, password: 'Si-Alta-Parola-1' });
  check('același link nu merge a doua oară → 410', iar.status === 410, `a dat ${iar.status}`);
}

console.log('\n=== contul blocat ===');
{
  // Cine ajunge la e-mailul contului a dovedit că e al lui, deci blocarea
  // strânsă de greșeli nu trebuie să-l țină pe dinafară după resetare.
  sql(`UPDATE users SET failed_logins = 99, locked_until = UTC_TIMESTAMP() + INTERVAL 30 MINUTE
        WHERE username = '${CLIENT.u}'`);
  const t = tokenPentru(CLIENT.u);
  await post(anon, '/auth/reset', { token: t, password: CLIENT.p });
  sql("DELETE FROM login_attempts WHERE username = 'testclient'");
  const r = await post(agent(), '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  check('resetarea deblochează contul', r.status === 200, `a dat ${r.status} ${r.data?.error?.code}`);
}

console.log('\n=== pragul de cereri ===');
{
  sql("DELETE FROM login_attempts WHERE username = 'recuperare'");
  let ultim = 0;
  for (let i = 0; i < 14; i++) {
    ultim = (await post(anon, '/auth/forgot', { username: 'oricine' })).status;
    if (ultim === 429) break;
  }
  check('cererile în lanț sunt oprite → 429', ultim === 429, `ultimul a dat ${ultim}`);
  sql("DELETE FROM login_attempts WHERE username = 'recuperare'");
}

console.log('\n=== pragul de cereri e pe conexiune, nu pe toată aplicația ===');
{
  sql("DELETE FROM login_attempts WHERE username = 'recuperare'");
  // Douăsprezece cereri venite de la altă conexiune. Vechea limitare le număra
  // pe toate la un loc, sub același pseudo-utilizator, fără să se uite la IP:
  // cineva cu un script oprea recuperarea parolei pentru toată lumea.
  const strain = Array.from({ length: 12 }, () => "('203.0.113.7', 'recuperare', 0)").join(',');
  sql(`INSERT INTO login_attempts (ip, username, ok) VALUES ${strain}`);
  const r = await post(agent(), '/auth/forgot', { username: CLIENT.u });
  check('cererile altei conexiuni nu opresc recuperarea de aici', r.status === 200, `a dat ${r.status}`);
  sql("DELETE FROM login_attempts WHERE username = 'recuperare'");
}
{
  // Formularul trimis gol nu scrie nimic și nu trimite niciun e-mail, deci
  // n-are de ce să mănânce din plafonul omului care chiar și-a uitat parola.
  for (let i = 0; i < 12; i++) await post(agent(), '/auth/forgot', { username: '' });
  const n = sql("SELECT COUNT(*) FROM login_attempts WHERE username = 'recuperare'");
  check('cererea cu câmpul gol nu consumă din plafon', n === '0', n);
  const r = await post(agent(), '/auth/forgot', { username: CLIENT.u });
  check('recuperarea merge mai departe după ele', r.status === 200, `a dat ${r.status}`);
  sql("DELETE FROM login_attempts WHERE username = 'recuperare'");
}

/** Contul de probă, adus înapoi la parola și la starea de pornire. */
const resetCont = () => {
  const h = php('echo password_hash($argv[1], PASSWORD_DEFAULT);', CLIENT.p);
  sql(`UPDATE users SET password_hash = '${h}', failed_logins = 0, locked_until = NULL
        WHERE username = '${CLIENT.u}'`);
  sql(`DELETE FROM login_attempts WHERE username = '${CLIENT.u}'`);
};
/** Greșeli scrise direct în tabel, cu vechimea pe care o vrem. */
const gresel = (cate, cand) => {
  const r = Array.from({ length: cate }, () => `('127.0.0.1', '${CLIENT.u}', 0, UTC_TIMESTAMP() - INTERVAL ${cand})`).join(',');
  sql(`INSERT INTO login_attempts (ip, username, ok, created_at) VALUES ${r}`);
};

console.log('\n=== blocarea contului are fereastră de timp ===');
{
  resetCont();
  // Ziua în care contul chiar s-a blocat o dată: contorul din coloană a rămas
  // acolo, fiindcă nu scade niciodată de la sine. Vechiul cod aduna peste el,
  // deci ORICE greșeală de după închidea contul încă douăzeci de minute — o
  // literă greșită, peste o lună, și tot așa, la nesfârșit.
  sql(`UPDATE users SET failed_logins = 12 WHERE username = '${CLIENT.u}'`);
  const gresit = await post(agent(), '/auth/login', { username: CLIENT.u, password: 'nu-e-parola' });
  check('o parolă greșită → 401', gresit.status === 401, `a dat ${gresit.status}`);
  const bun = await post(agent(), '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  check('contorul rămas din trecut nu mai blochează contul', bun.status === 200,
    `a dat ${bun.status} ${bun.data?.error?.code}`);
}
{
  resetCont();
  gresel(12, '30 MINUTE');
  await post(agent(), '/auth/login', { username: CLIENT.u, password: 'nu-e-parola' });
  const bun = await post(agent(), '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  check('douăsprezece greșeli strânse într-o oră blochează contul → 423', bun.status === 423,
    `a dat ${bun.status} ${bun.data?.error?.code}`);
}
{
  resetCont();
  gresel(12, '3 HOUR');
  await post(agent(), '/auth/login', { username: CLIENT.u, password: 'nu-e-parola' });
  const bun = await post(agent(), '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  check('aceleași greșeli, de acum trei ore, nu mai blochează', bun.status === 200,
    `a dat ${bun.status} ${bun.data?.error?.code}`);
}

console.log('\n=== parola nouă deblochează și numărătoarea ===');
{
  resetCont();
  // Zece greșeli proaspete: limitarea răspunde 429 chiar și cu parola bună.
  // Blocarea se calculează din ele, deci parola nouă trebuie să le și șteargă
  // — altfel omul primește linkul, pune parola și tot nu intră.
  gresel(10, '1 MINUTE');
  const inainte = await post(agent(), '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  check('înainte de resetare, parola bună e oprită de prag → 429', inainte.status === 429, `a dat ${inainte.status}`);

  const t = tokenPentru(CLIENT.u);
  const r = await post(agent(), '/auth/reset', { token: t, password: CLIENT.p });
  check('resetarea prin link reușește → 200', r.status === 200, `a dat ${r.status}`);
  const dupa = await post(agent(), '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  check('după resetare se intră imediat', dupa.status === 200, `a dat ${dupa.status} ${dupa.data?.error?.code}`);
}

console.log('\n=== parola pusă cu spații la capete ===');
{
  resetCont();
  const t = tokenPentru(CLIENT.u);
  const r = await post(agent(), '/auth/reset', { token: t, password: '  ' + NOUA + '  ' });
  check('parola cu spații e acceptată → 200', r.status === 200, `a dat ${r.status}`);
  // Autentificarea taie spațiile, deci hash-ul parolei netăiate n-ar mai fi
  // potrivit niciodată: omul rămânea pe dinafară cu parola tocmai scrisă.
  const intra = await post(agent(), '/auth/login', { username: CLIENT.u, password: NOUA });
  check('se intră cu parola fără spații, cum o taie și autentificarea',
    intra.status === 200, `a dat ${intra.status} ${intra.data?.error?.code}`);
  resetCont();
}

console.log('\n=== schimbarea parolei anulează linkurile deja trimise ===');
{
  resetCont();
  const t = tokenPentru(CLIENT.u);
  const eu = agent();
  const intrare = await post(eu, '/auth/login', { username: CLIENT.u, password: CLIENT.p });
  eu.csrf = intrare.data?.csrf || '';
  const sch = await post(eu, '/auth/password', { currentPassword: CLIENT.p, newPassword: NOUA });
  check('parola se schimbă din cont → 200', sch.status === 200, `a dat ${sch.status} ${sch.data?.error?.code}`);
  // Tokenul rămânea bun încă o oră doar pe baza lui used_at IS NULL: cine avea
  // e-mailul putea pune altă parolă PESTE cea nouă, adică exact lucrul de care
  // fugea schimbarea.
  const link = await post(agent(), '/auth/reset', { token: t, password: 'Alta-Parola-Peste-1' });
  check('linkul cerut înainte nu mai poate pune altă parolă → 410', link.status === 410,
    `a dat ${link.status} ${link.data?.error?.code}`);
  const inca = await post(agent(), '/auth/login', { username: CLIENT.u, password: NOUA });
  check('parola rămâne cea pusă de om', inca.status === 200, `a dat ${inca.status}`);
  resetCont();
}

console.log('\n=== parola dată de administrator ===');
{
  resetCont();
  const t = tokenPentru(CLIENT.u);
  gresel(10, '1 MINUTE');

  const sef = agent();
  const l = await post(sef, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  sef.csrf = l.data?.csrf || '';
  const uid = sql(`SELECT id FROM users WHERE username = '${CLIENT.u}'`);
  const r = await post(sef, '/users/' + uid + '/password', { password: NOUA });
  check('administratorul pune parola → 200', r.status === 200, `a dat ${r.status} ${r.data?.error?.code}`);

  const link = await post(agent(), '/auth/reset', { token: t, password: 'Alta-Parola-Peste-2' });
  check('și aici linkul cerut înainte se închide → 410', link.status === 410,
    `a dat ${link.status} ${link.data?.error?.code}`);
  const intra = await post(agent(), '/auth/login', { username: CLIENT.u, password: NOUA });
  check('se intră imediat cu parola dată de administrator', intra.status === 200,
    `a dat ${intra.status} ${intra.data?.error?.code}`);
  resetCont();
}

console.log('\n=== ce știe aplicația despre parola unui cont ===');
{
  // Pagina Conturi scria în dreptul fiecărui cont „parolă schimbată <dată>",
  // dar data venea din `last_login`. Un cont care tocmai se autentificase cu
  // parola primită de la administrator apărea ca și cum și-ar fi pus parolă
  // proprie — exact pe dos față de ce caută administratorul acolo. Acum
  // adevărul stă în două valori: steagul pus la fiecare resetare și data
  // scrisă doar când omul își schimbă singur parola.
  resetCont();
  const uid = sql(`SELECT id FROM users WHERE username = '${CLIENT.u}'`);

  const sef = agent();
  const l = await post(sef, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  sef.csrf = l.data?.csrf || '';

  // 1. Administratorul pune o parolă.
  await post(sef, '/users/' + uid + '/password', { password: NOUA });
  const dupaReset = (await get(sef, '/users')).data?.users?.find((u) => u.username === CLIENT.u);
  check('după resetarea făcută de administrator, contul e marcat cu parolă provizorie',
    dupaReset?.mustChangePassword === true, JSON.stringify(dupaReset?.mustChangePassword));
  check('și nu există nicio dată de schimbare a parolei, fiindcă n-a schimbat-o nimeni',
    dupaReset?.passwordChangedAt === null, JSON.stringify(dupaReset?.passwordChangedAt));

  // 2. Omul intră cu ea. Autentificarea NU e o schimbare de parolă — aici
  //    stătea toată minciuna: `last_login` se muta, iar pagina îl citea ca
  //    dată a schimbării.
  const el = agent();
  const intra = await post(el, '/auth/login', { username: CLIENT.u, password: NOUA });
  el.csrf = intra.data?.csrf || '';
  check('se intră cu parola dată de administrator', intra.status === 200,
    `a dat ${intra.status} ${intra.data?.error?.code}`);

  const dupaIntrare = (await get(sef, '/users')).data?.users?.find((u) => u.username === CLIENT.u);
  check('o autentificare nu trece drept schimbare de parolă',
    dupaIntrare?.passwordChangedAt === null, JSON.stringify(dupaIntrare?.passwordChangedAt));
  check('iar contul rămâne marcat cu parolă provizorie',
    dupaIntrare?.mustChangePassword === true, JSON.stringify(dupaIntrare?.mustChangePassword));
  check('ultima intrare chiar s-a înregistrat, ca să se vadă că sunt două lucruri diferite',
    typeof dupaIntrare?.lastLogin === 'string' && dupaIntrare.lastLogin !== '',
    JSON.stringify(dupaIntrare?.lastLogin));

  // 3. Omul își schimbă singur parola din „Parola mea".
  const proprie = 'Parola-Mea-Numai-A-Mea-77';
  const sch = await post(el, '/auth/password', { currentPassword: NOUA, newPassword: proprie });
  check('își schimbă singur parola → 200', sch.status === 200,
    `a dat ${sch.status} ${sch.data?.error?.code}`);

  const dupaSchimbare = (await get(sef, '/users')).data?.users?.find((u) => u.username === CLIENT.u);
  check('acum contul nu mai are parolă provizorie',
    dupaSchimbare?.mustChangePassword === false, JSON.stringify(dupaSchimbare?.mustChangePassword));
  check('și abia acum există o dată a schimbării parolei',
    typeof dupaSchimbare?.passwordChangedAt === 'string' && dupaSchimbare.passwordChangedAt !== '',
    JSON.stringify(dupaSchimbare?.passwordChangedAt));

  // 4. Și linkul de recuperare e tot o schimbare făcută de om.
  sql(`UPDATE users SET password_changed_at = NULL, must_change_password = 1 WHERE id = ${uid}`);
  const t = tokenPentru(CLIENT.u);
  const prinLink = await post(agent(), '/auth/reset', { token: t, password: 'Parola-Prin-Link-99' });
  check('parola pusă prin link → 200', prinLink.status === 200,
    `a dat ${prinLink.status} ${prinLink.data?.error?.code}`);
  const dupaLink = (await get(sef, '/users')).data?.users?.find((u) => u.username === CLIENT.u);
  check('linkul de recuperare scrie și el data schimbării',
    typeof dupaLink?.passwordChangedAt === 'string' && dupaLink.passwordChangedAt !== '',
    JSON.stringify(dupaLink?.passwordChangedAt));
  check('și scoate contul din starea de parolă provizorie',
    dupaLink?.mustChangePassword === false, JSON.stringify(dupaLink?.mustChangePassword));

  resetCont();
  sql(`UPDATE users SET password_changed_at = NULL, must_change_password = 0 WHERE id = ${uid}`);
}

// Curățenie: parola de probă rămâne cea pe care o știu celelalte suite.
sql('DELETE FROM password_resets');
sql("DELETE FROM login_attempts WHERE username IN ('recuperare', 'testclient')");

console.log(`\n${'='.repeat(52)}`);
console.log(`${pass} trecute · ${fail} eșuate`);
if (fail) { console.log('\nESUATE:'); for (const f of failures) console.log('  x ' + f); }
console.log();
process.exit(fail ? 1 : 0);
