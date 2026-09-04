// Probe pentru două defecte de fus orar: acceptarea cererilor și registrul de depozit.
//
//   php -S 127.0.0.1:8100 -t . .claude/dev-server.php
//   node .claude/test-defect-cereri-stoc.mjs
//
// Amândouă vin din același loc: aplicația scrie totul în UTC (api/lib/db.php),
// dar „ziua" pe care o folosesc oamenii e o zi de calendar românească —
// panourile își fac fereastra din ceasul tabletei. România e la UTC+2 iarna și
// UTC+3 vara, deci cele două noțiuni se despart în fiecare noapte, exact în
// orele în care laboratorul lucrează pentru livrarea de 06:30.
//
//   api/routes/requests.php — acceptarea unei cereri rămase în urmă năștea o
//     comandă pe o zi trecută. Răspunsul era 201 „Cerere acceptată", dar
//     comanda nu apărea pe niciun ecran de producție (Panou Laborator, Intrare
//     și ruta șoferului cer numai zile de azi înainte), deci marfa nu se făcea.
//
//   api/routes/stock.php — filtrul registrului tăia după `DATE(created_at)`,
//     adică după ziua UTC, deși pagina de start trimite ziua locală. Mișcările
//     din noaptea românească rămâneau nenumărate toată ziua, iar cele de după
//     miezul nopții următoare erau numărate în ziua greșită.
//
// Probele pentru depozit nu se pot scrie prin API: `POST /stock/moves` pune
// `created_at` din ceasul serverului, iar defectul se vede doar la ore alese.
// De aceea mișcările de probă se scriu direct în MySQL, cu clipa lor exactă, și
// se șterg la sfârșit — chiar dacă vreo probă pică pe drum.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BAZA_DE_PROBA, CERE_CONFIG } from './baza-probei.mjs';

// 127.0.0.1, nu „localhost": pe Windows „localhost" se rezolvă întâi la ::1,
// unde serverul încorporat nu ascultă. Aceeași alegere ca în test-api.mjs.
const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');

let pass = 0, fail = 0;
const failures = [];

function check(name, cond, extra = '') {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
}

/* ------------------------------------------------------------- unelte -- */

/** Sesiune izolată: cookie-uri și jeton CSRF proprii, ca în test-api.mjs. */
function agent() {
  return { cookies: new Map(), csrf: '' };
}

async function call(a, method, p, body) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (a.csrf && method !== 'GET') headers['X-CSRF-Token'] = a.csrf;
  if (a.cookies.size) headers['Cookie'] = [...a.cookies].map(([k, v]) => `${k}=${v}`).join('; ');

  const res = await fetch(BASE + p, {
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
      // Un răspuns care nu e JSON înseamnă eroare de server, nu probă scrisă
      // greșit — o spunem pe față, ca în test-api.mjs.
      throw new Error('răspuns care nu este JSON la ' + method + ' ' + p + ': '
        + raw.slice(0, 200).replace(/\s+/g, ' '));
    }
  }
  return { status: res.status, data };
}

const get = (a, p) => call(a, 'GET', p);
const post = (a, p, b) => call(a, 'POST', p, b ?? {});

/** PHP știe deja să citească `api/config.php`; nu redeschidem noi fișierul. */
function phpBinary() {
  if (process.env.GP_PHP) return process.env.GP_PHP;
  const guess = 'C:/laragon/bin/php';
  try {
    const v = fs.readdirSync(guess).find((d) => d.startsWith('php-'));
    if (v) return path.join(guess, v, 'php.exe');
  } catch { /* alt sistem */ }
  return 'php';
}

function mysqlBinary() {
  if (process.env.GP_MYSQL) return process.env.GP_MYSQL;
  const guess = 'C:/laragon/bin/mysql';
  try {
    const v = fs.readdirSync(guess).find((d) => d.startsWith('mysql-'));
    if (v) return path.join(guess, v, 'bin', 'mysql.exe');
  } catch { /* alt sistem */ }
  return 'mysql';
}

/**
 * Ziua de calendar la București pentru o clipă dată.
 *
 * „sv-SE" fiindcă e singura limbă din listă care scrie data chiar în forma
 * AAAA-LL-ZZ pe care o cere serverul. Cheia e `timeZone`: proba nu are voie
 * să depindă de fusul mașinii pe care rulează, altfel ar trece degeaba pe un
 * calculator pus pe UTC și ar pica pe unul pus pe ora Bucureștiului.
 */
const ziLocala = (d) => d.toLocaleDateString('sv-SE', { timeZone: 'Europe/Bucharest' });
const AZI   = ziLocala(new Date());
const IERI  = ziLocala(new Date(Date.now() - 86400000));
const PLUS6 = ziLocala(new Date(Date.now() + 6 * 86400000));

/* --------------------------------------------------- serverul răspunde? -- */

const anon = agent();
const admin = agent();
try {
  const r = await post(admin, '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  admin.csrf = r.data?.csrf || '';
  if (r.status !== 200) throw new Error('autentificare eșuată: ' + r.status);
} catch (e) {
  // Fără serverul local probele nu sunt „eșuate", ci nerulate.
  console.log('\n  —  sărit: serverul de dezvoltare nu răspunde pe ' + BASE);
  console.log('     ' + String(e.message).split('\n')[0]);
  console.log('     php -S 127.0.0.1:8100 -t . .claude/dev-server.php\n');
  console.log('0 trecute · 0 eșuate (sărit)');
  process.exit(0);
}

/* ================================================================ CERERI == */
// api/routes/requests.php — acceptarea nu are voie să nască o comandă pe o zi
// pe care panourile de producție n-o mai arată niciodată.

console.log('\n=== cereri: acceptarea nu poate cădea în trecut ===');

const NUME_PROBA = 'Cofetăria Rămasă În Urmă';
const creeazaCerere = (zi) => post(anon, '/requests', {
  customerName: NUME_PROBA,
  phone: '0733010203',
  day: zi,
  items: [{ productId: 1, qty: 2 }],
});

let comandaAzi = null;
try {
  // ── Ziua trecută nu mai intră nici măcar în coadă ─────────────────────────
  // Verificarea de la acceptare a fost dusă și la trimitere (`valid_order_day`
  // din api/lib/http.php), fiindcă altfel clientul primea „Mulțumim! Cererea ta
  // a intrat la noi în platformă" pentru o cerere pe care administratorul nu o
  // putea accepta niciodată. Cererea rămânea blocată pe „nou" și cineva trebuia
  // să-l sune. Detaliile ferestrei, în .claude/test-defect-ziua-comenzii.mjs.
  {
    const respinsa = await creeazaCerere(IERI);
    check('cererea pe ziua de ieri e refuzată din start → 422', respinsa.status === 422,
      `a dat ${respinsa.status}`);
    check('refuzul de la trimitere are același cod ca cel de la acceptare',
      respinsa.data?.error?.code === 'day_in_past', JSON.stringify(respinsa.data?.error));
  }

  // ── Ziua trecută trebuie refuzată, nu acceptată și nici mutată în tăcere ──
  // Drumul rămas către o zi trecută: administratorul o tastează el, în
  // formularul de acceptare. Aceeași cale o are și o cerere veche, rămasă în
  // bază de dinainte de verificarea de la trimitere.
  {
    const cer = await creeazaCerere(AZI);
    const rid = cer.data?.request?.id;
    check('cererea rămasă în urmă s-a creat → 201', cer.status === 201, `a dat ${cer.status}`);
    check('cererea păstrează ziua cerută de client', cer.data?.request?.day === AZI,
      `are ${cer.data?.request?.day}`);

    const acc = await post(admin, `/requests/${rid}/accept`, { day: IERI, slot: '06:30' });
    check('acceptarea pe ziua de ieri e refuzată → 422', acc.status === 422, `a dat ${acc.status}`);
    check('refuzul spune de ce, cu un cod anume',
      acc.data?.error?.code === 'day_in_past', JSON.stringify(acc.data?.error));
    check('mesajul numește ziua refuzată și ziua de azi',
      String(acc.data?.error?.message || '').includes(IERI)
        && String(acc.data?.error?.message || '').includes(AZI),
      JSON.stringify(acc.data?.error?.message));

    // Miezul defectului: comanda nu are voie să existe deloc. Un 422 care ar
    // lăsa totuși rândul scris ar fi și mai rău decât defectul de dinainte.
    check('refuzul nu a creat nicio comandă', !acc.data?.order, JSON.stringify(acc.data?.order));

    const cereri = await get(admin, '/requests?status=nou');
    check('cererea rămâne în coadă, ca să poată fi acceptată pe altă zi',
      (cereri.data?.requests || []).some((x) => x.id === rid), 'id ' + rid);
  }

  // ── Ziua de azi trebuie să treacă mai departe, altfel am rupt fluxul ──────
  // Fără proba asta, o reparație care refuză orice zi ar trece la fel de bine.
  {
    const cer = await creeazaCerere(AZI);
    const rid = cer.data?.request?.id;
    const acc = await post(admin, `/requests/${rid}/accept`, { day: AZI, slot: '06:30' });
    check('aceeași cerere, mutată pe azi, se acceptă → 201', acc.status === 201,
      `a dat ${acc.status} ` + JSON.stringify(acc.data?.error));
    check('comanda se naște chiar pe ziua de azi', acc.data?.order?.day === AZI,
      `are ${acc.data?.order?.day}`);
    comandaAzi = acc.data?.order?.id ?? null;

    // Rostul întregii verificări: comanda trebuie să ajungă pe ecranele de
    // producție. Fereastra cerută de Panou Laborator și Intrare e azi..azi+6.
    const panou = await get(admin, `/orders?from=${AZI}&to=${PLUS6}`);
    check('comanda apare în fereastra panoului din laborator',
      (panou.data?.orders || []).some((o) => o.id === comandaAzi),
      'comanda ' + comandaAzi + ' lipsește din ' + AZI + '..' + PLUS6);
  }

  // ── Ziua din viitor rămâne permisă, chiar dincolo de fereastra de 7 zile ──
  // Comenzile programate departe sunt legitime; defectul e doar în trecut.
  {
    const departe = ziLocala(new Date(Date.now() + 40 * 86400000));
    const cer = await creeazaCerere(departe);
    const rid = cer.data?.request?.id;
    const acc = await post(admin, `/requests/${rid}/accept`, { day: departe, slot: '06:30' });
    check('o zi din viitor rămâne acceptabilă → 201', acc.status === 201,
      `a dat ${acc.status} ` + JSON.stringify(acc.data?.error));
    check('comanda păstrează ziua din viitor', acc.data?.order?.day === departe,
      `are ${acc.data?.order?.day}`);
  }
} catch (e) {
  // O excepție aici — serverul căzut la mijloc, un răspuns care nu e JSON — nu
  // are voie să sară peste curățenia de la sfârșit: altfel cererile de probă
  // rămân în coada administratorului la fiecare rulare eșuată.
  check('probele de cereri au putut rula', false, String(e.message).split('\n')[0]);
}

/* =============================================================== DEPOZIT == */
// api/routes/stock.php — filtrul de zi al registrului.

console.log('\n=== depozit: registrul se taie pe ziua locală ===');

let db = null;
try {
  db = JSON.parse(execFileSync(phpBinary(), [
    '-r', '$c = require ' + CERE_CONFIG + '; echo json_encode($c["db"]);',
  ], { cwd: ROOT, encoding: 'utf8' }));
} catch (e) {
  console.log('  —     sărit: nu pot citi api/config.php (' + String(e.message).split('\n')[0] + ')');
}

// Plasă de siguranță: proba scrie în tabele, deci nu are ce căuta pe un server
// adevărat. Aceeași regulă ca în curata-probe.mjs.
if (db && !BAZA_DE_PROBA.test(db.name)) {
  console.log(`  —     sărit: baza „${db.name}” nu e una de dezvoltare`);
  db = null;
}

function sql(text) {
  return execFileSync(mysqlBinary(), [
    // Fără asta, clientul mysql de pe Windows citește instrucțiunea în
    // codepage-ul consolei, nu în UTF-8, și diacriticele ajung stricate.
    '--default-character-set=utf8mb4',
    '-u', db.user, ...(db.pass ? ['-p' + db.pass] : []),
    '-h', db.host, '-P', String(db.port), db.name,
    '-N', '-B', '-e', "SET time_zone = '+00:00'; " + text,
  ], { encoding: 'utf8' });
}

// Mișcările de probă, date ca (clipa UTC scrisă în tabel → ce înseamnă local).
// Zilele sunt alese anume în cele două jumătăți ale anului: iulie prinde ora
// de vară (+03), ianuarie ora de iarnă (+02). O reparație care ar lipi un fus
// fix ar pica pe una dintre ele — de aceea sunt amândouă aici.
const VARA = '2026-07-15';   // zi locală de vară, București = UTC+3
const IARNA = '2026-01-15';  // zi locală de iarnă, București = UTC+2
const SEMN = 'PROBA-TZ-DEPOZIT';

const MISCARI = [
  // ziua locală de vară
  { nota: SEMN + '-vara-0030', utc: '2026-07-14 21:30:00', zi: VARA },   // 00:30 local
  { nota: SEMN + '-vara-0130', utc: '2026-07-14 22:30:00', zi: VARA },   // 01:30 local — șarja de noapte
  { nota: SEMN + '-vara-1200', utc: '2026-07-15 09:00:00', zi: VARA },   // 12:00 local
  { nota: SEMN + '-vara-2330', utc: '2026-07-15 20:30:00', zi: VARA },   // 23:30 local
  // deja ziua următoare, deși DATE(created_at) o arată tot pe 15 iulie
  { nota: SEMN + '-16iul-0030', utc: '2026-07-15 21:30:00', zi: null },
  // ziua locală de iarnă
  { nota: SEMN + '-iarna-0030', utc: '2026-01-14 22:30:00', zi: IARNA }, // 00:30 local
  // încă în 14 ianuarie local (23:30) — un fus fix de +03 ar trage-o în 15
  { nota: SEMN + '-14ian-2330', utc: '2026-01-14 21:30:00', zi: null },
];

const asteptateVara = MISCARI.filter((m) => m.zi === VARA).map((m) => m.nota).sort();
const asteptateIarna = MISCARI.filter((m) => m.zi === IARNA).map((m) => m.nota).sort();

const sterge = () => sql(`DELETE FROM stock_moves WHERE note LIKE '${SEMN}-%'`);

if (db) {
  try {
    sterge();
    const item = sql('SELECT id, name FROM stock_items WHERE active = 1 ORDER BY id LIMIT 1').trim();
    const [itemId, itemName] = item.split('\t');
    if (!itemId) throw new Error('nu există niciun articol de depozit activ');

    const valori = MISCARI.map((m) =>
      `(${itemId}, '${itemName.replace(/'/g, "''")}', 1, 1, 'probă', '${m.nota}', NULL, '${m.utc}')`
    ).join(', ');
    sql(`INSERT INTO stock_moves (item_id, name, qty, direction, reason, note, user_id, created_at)
         VALUES ${valori}`);

    /** Notele mișcărilor de probă întoarse de rută pentru un interval de zile. */
    async function notele(from, to) {
      const r = await get(admin, `/stock/moves?from=${from}&to=${to}`);
      if (r.status !== 200) throw new Error('GET /stock/moves a dat ' + r.status);
      return (r.data?.moves || [])
        .map((m) => String(m.note || ''))
        .filter((n) => n.startsWith(SEMN + '-'))
        .sort();
    }

    {
      const gasite = await notele(VARA, VARA);
      check('ziua locală de vară întoarce exact mișcările ei (ora de vară, +03)',
        JSON.stringify(gasite) === JSON.stringify(asteptateVara),
        'am primit ' + JSON.stringify(gasite) + ', așteptam ' + JSON.stringify(asteptateVara));
    }
    {
      // Marginea de jos: mișcarea de la 01:30 e scrisă cu 22:30 în ziua UTC
      // precedentă. Tăiat pe ziua UTC, filtrul o punea aici.
      const gasite = await notele('2026-07-14', '2026-07-14');
      check('ziua dinaintea celei de vară nu fură șarja de noapte',
        gasite.length === 0, 'a întors ' + JSON.stringify(gasite));
    }
    {
      // Marginea de sus: mișcarea de la 00:30 pe 16 iulie are DATE(created_at)
      // = 15 iulie, deci intra în ziua greșită.
      const gasite = await notele('2026-07-16', '2026-07-16');
      check('ziua de după cea de vară primește mișcarea de după miezul nopții',
        JSON.stringify(gasite) === JSON.stringify([SEMN + '-16iul-0030']),
        'a întors ' + JSON.stringify(gasite));
    }
    {
      const gasite = await notele(IARNA, IARNA);
      check('ziua locală de iarnă întoarce exact mișcările ei (ora de iarnă, +02)',
        JSON.stringify(gasite) === JSON.stringify(asteptateIarna),
        'am primit ' + JSON.stringify(gasite) + ', așteptam ' + JSON.stringify(asteptateIarna));
    }
    {
      // `from` singur, fără `to` — chiar forma pe care o trimite pagina de
      // start: `gp.get('/stock/moves?from=' + today)`.
      const r = await get(admin, `/stock/moves?from=${VARA}`);
      const gasite = (r.data?.moves || []).map((m) => String(m.note || ''))
        .filter((n) => n.startsWith(SEMN + '-vara-')).sort();
      check('cu „from" singur, cardul de pe pagina de start numără toată ziua locală',
        JSON.stringify(gasite) === JSON.stringify(asteptateVara),
        'am primit ' + JSON.stringify(gasite) + ', așteptam ' + JSON.stringify(asteptateVara));
    }
  } catch (e) {
    check('probele de depozit au putut rula', false, String(e.message).split('\n')[0]);
  } finally {
    try { sterge(); } catch { /* baza a plecat; curăță curata-probe.mjs */ }
  }
}

/* ============================================================= curățenie == */
// Prin API nu se pot șterge comenzi — comenzile se anulează, nu dispar — deci
// urmele probelor de mai sus pleacă tot pe drumul scurt, ca în curata-probe.mjs.
// Fără asta, coada de cereri și registrul de comenzi se umplu la fiecare rulare.

if (db) {
  const nume = "'" + NUME_PROBA.replace(/'/g, "''") + "'";
  try {
    sql(`DELETE ri FROM order_request_items ri JOIN order_requests r ON r.id = ri.request_id
          WHERE r.customer_name = ${nume}`);
    sql(`DELETE oi FROM order_items oi JOIN orders o ON o.id = oi.order_id
          WHERE o.customer_name = ${nume}`);
    sql(`DELETE n FROM notifications n JOIN orders o ON o.id = n.order_id
          WHERE o.customer_name = ${nume}`);
    sql(`UPDATE order_requests SET order_id = NULL WHERE customer_name = ${nume}`);
    sql(`DELETE FROM orders WHERE customer_name = ${nume}`);
    sql(`DELETE FROM order_requests WHERE customer_name = ${nume}`);
  } catch (e) {
    console.log('\n  —     nu am putut curăța urmele (' + String(e.message).split('\n')[0] + ')');
  }
}

/* ================================================================ raport == */

if (failures.length) {
  console.log('\nEșuate:');
  for (const f of failures) console.log('  x ' + f);
}
console.log(`\n${pass} trecute · ${fail} eșuate\n`);
process.exit(fail ? 1 : 0);
