// Probe pentru trei greșeli din Setări care ies în lume fără să se plângă
// nimeni: coordonatele depozitului salvate cu virgulă zecimală, adresa de
// e-mail publică salvată fără verificare și adresa din pagina publică luată
// din eticheta primei opriri de pe rută.
//
//   php -S 127.0.0.1:8100 -t . .claude/dev-server.php
//   node .claude/test-defect-setari-produse.mjs
//
// Miza e că toate trei se salvează cu „Setările au fost salvate.”: nimic nu
// arată pe ecran că depozitul a sărit 43 de kilometri, că linkul „scrie-ne”
// duce la o adresă imposibilă sau că Google indexează cuvântul „Depozit” în
// loc de stradă. De aceea probele nu se uită la mesajul de răspuns, ci la ce
// iese pe partea cealaltă: GET /lists și HTML-ul servit de seo/index.php.
//
// Partea de SEO NU atinge baza de dezvoltare: își face o bază proprie, cu
// valorile exacte din scenariu, și rulează pe o copie a fișierului adevărat.
// Altfel n-ar avea cum să verifice ce se întâmplă cu caseta golită fără să
// strice setările altcuiva care lucrează în același timp.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BAZA_DE_PROBA, CERE_CONFIG } from './baza-probei.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';

let pass = 0, fail = 0;
const failures = [];

function check(name, cond, extra = '') {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
}

function bin(envVar, folder, exe) {
  if (process.env[envVar]) return process.env[envVar];
  const guess = 'C:/laragon/bin/' + folder;
  try {
    const v = fs.readdirSync(guess).find((d) => d.startsWith(folder + '-'));
    if (v) return path.join(guess, v, folder === 'mysql' ? 'bin' : '', exe);
  } catch { /* alt sistem */ }
  return exe.replace('.exe', '');
}

const PHP = bin('GP_PHP', 'php', 'php.exe');
const MYSQL = bin('GP_MYSQL', 'mysql', 'mysql.exe');

/* ------------------------------------------------------- unelte pentru API -- */

function agent() {
  return { cookies: new Map(), csrf: '' };
}

async function call(a, method, p, body) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (a.csrf && method !== 'GET') headers['X-CSRF-Token'] = a.csrf;
  if (a.cookies.size) headers['Cookie'] = [...a.cookies].map(([k, v]) => `${k}=${v}`).join('; ');

  const res = await fetch(BASE + p, {
    method, headers,
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
      throw new Error('răspuns care nu este JSON la ' + method + ' ' + p + ': ' + raw.slice(0, 200).replace(/\s+/g, ' '));
    }
  }
  return { status: res.status, data };
}

const get = (a, p) => call(a, 'GET', p);
const put = (a, p, b) => call(a, 'PUT', p, b ?? {});

const cod = (r) => r.data?.error?.code ?? String(r.status);

/* --------------------------------------------------------------- pornire -- */

const admin = agent();
try {
  const r = await call(admin, 'POST', '/auth/login', { username: 'testadmin', password: 'Parola-Test-1234' });
  if (r.status !== 200) throw new Error('autentificare eșuată: ' + r.status);
  admin.csrf = r.data.csrf;
} catch (e) {
  // Fără serverul de dezvoltare probele astea nu sunt „eșuate”, ci nerulate.
  console.log('\n  —  sărit: API-ul nu răspunde pe ' + BASE + ' (' + e.message.split('\n')[0] + ')');
  console.log('     php -S 127.0.0.1:8100 -t . .claude/dev-server.php\n');
  console.log('0 trecute · 0 eșuate (sărit)');
  process.exit(0);
}

const before = (await get(admin, '/settings')).data.settings;
const restore = async () => {
  await put(admin, '/settings', {
    settings: {
      hq_lat: before.hq_lat,
      hq_lng: before.hq_lng,
      company_email: before.company_email,
    },
  });
};

try {

  /* ------------------------------------- coordonatele depozitului, la salvare -- */
  // Scenariul: administratorul corectează „Latitudine depozit” de la tastatura
  // românească și scrie 44,39235. Înainte, valoarea se salva ca atare și era
  // citită ca 44 — depozitul cu 43 km mai la sud, fără niciun mesaj.
  console.log('\n=== coordonatele depozitului nu se mai salvează nevalidate ===');
  {
    const r = await put(admin, '/settings', { settings: { hq_lat: '44,39235' } });
    check('latitudinea scrisă cu virgulă este acceptată → 200', r.status === 200, 'a dat ' + cod(r));

    const s = (await get(admin, '/settings')).data.settings;
    check('și se salvează cu punct, o singură formă în baza de date',
      s.hq_lat === '44.39235', JSON.stringify(s.hq_lat));

    const hq = (await get(admin, '/lists')).data.hq;
    check('GET /lists dă depozitul acolo unde este, nu cu 43 km mai la sud',
      Math.abs(hq.lat - 44.39235) < 1e-9, JSON.stringify(hq));
  }
  {
    const r = await put(admin, '/settings', { settings: { hq_lng: '26,12385' } });
    check('longitudinea scrisă cu virgulă este acceptată → 200', r.status === 200, 'a dat ' + cod(r));
    const hq = (await get(admin, '/lists')).data.hq;
    check('longitudinea ajunge întreagă în GET /lists',
      Math.abs(hq.lng - 26.12385) < 1e-9, JSON.stringify(hq));
  }
  {
    const r = await put(admin, '/settings', { settings: { hq_lat: 'n/a' } });
    check('o latitudine fără cifre este respinsă → 422', r.status === 422 && cod(r) === 'bad_hq_point', 'a dat ' + cod(r));
  }
  {
    // 0 nu e „necompletat”, e Golful Guineei — exact punctul împotriva căruia
    // au fost blindate coordonatele zonelor.
    const r = await put(admin, '/settings', { settings: { hq_lng: '0' } });
    check('un 0 pe longitudine este respins → 422', r.status === 422 && cod(r) === 'bad_hq_point', 'a dat ' + cod(r));
  }
  {
    const r = await put(admin, '/settings', { settings: { hq_lat: '999' } });
    check('o latitudine din afara hărții este respinsă → 422', r.status === 422 && cod(r) === 'bad_hq_point', 'a dat ' + cod(r));
  }
  {
    const hq = (await get(admin, '/lists')).data.hq;
    check('după respingeri, depozitul a rămas neatins',
      Math.abs(hq.lat - 44.39235) < 1e-9 && Math.abs(hq.lng - 26.12385) < 1e-9, JSON.stringify(hq));
  }

  /* --------------------------------------- adresa publică de e-mail, la salvare -- */
  // Scenariul: „E-mail public” primește o adresă fără domeniu. Pleacă prin
  // GET /lists, care e public, și ajunge în linkul „scrie-ne” de pe site.
  console.log('\n=== e-mailul public se verifică la fel ca cele de notificare ===');
  {
    const bun = before.company_email || '';
    for (const gresit of ['contact@goldenpastry', 'contact @goldenpastry.ro', 'scrie-ne pe site']) {
      const r = await put(admin, '/settings', { settings: { company_email: gresit } });
      check('e-mailul public „' + gresit + '” este respins → 422',
        r.status === 422 && cod(r) === 'bad_email', 'a dat ' + cod(r));
    }

    const c = (await get(admin, '/lists')).data.contact;
    check('adresa publică bună a rămas neatinsă după respingeri',
      c.email === bun, JSON.stringify(c.email));

    const ok = await put(admin, '/settings', { settings: { company_email: 'proba-contact@exemplu.ro' } });
    check('o adresă publică validă se salvează → 200', ok.status === 200, 'a dat ' + cod(ok));
    check('și ajunge în GET /lists',
      (await get(admin, '/lists')).data.contact.email === 'proba-contact@exemplu.ro');
  }

} finally {
  await restore();
}

/* -------------------------- citirea coordonatelor, pentru ce e deja în bază -- */
// Salvarea le oprește de acum, dar în `settings` pot sta valori scrise înainte
// de verificare — exact cazul pentru care zone_coords() se apără și la citire.
//
// Rulăm CHIAR route_lists_get(), cu o funcție all() de probă în locul bazei:
// așa nu atingem gp_dev, dar verificăm linia din rută, nu doar ajutorul de
// lângă ea. O reparație mutată doar în ajutor, cu ruta lăsată cum era, ar
// trece de o probă scrisă pe funcție.
console.log('\n=== citirea apără și valorile rămase din trecut ===');
{
  const cazuri = {
    'virgulă':     ['44,39235', '26,12385', 44.39235, 26.12385],
    'text':        ['n/a', 'n/a', 44.39235, 26.12385],
    'gol':         ['', '', 44.39235, 26.12385],
    'zero':        ['0', '0', 44.39235, 26.12385],
    'în afară':    ['999', '-999', 44.39235, 26.12385],
    'bun':         ['44.5', '26.5', 44.5, 26.5],
  };

  for (const [nume, [lat, lng, astLat, astLng]] of Object.entries(cazuri)) {
    const txt = (v) => "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    const cod_php = `
      $GP = [
          'hq_lat' => ${txt(lat)},
          'hq_lng' => ${txt(lng)},
          'delivery_zone_coords' => ${txt('{"Sector 1":{"lat":44.4,"lng":26.1}}')},
      ];
      function all(string $sql, array $params = []): array {
          global $GP;
          $out = [];
          foreach ($GP as $k => $v) { $out[] = ["setting_key" => $k, "value" => $v, "is_secret" => 0]; }
          return $out;
      }
      // route_lists_get() cheamă order_day_window(), care stă în lib/http.php
      // împreună cu today_local() și GP_ORIZONT_ZILE. Fără fișierul acela, proba
      // pica pe „Call to undefined function" la fiecare caz — adică nu mai
      // verifica nimic din ce spune că verifică. json_out() nu se mai imită: cel
      // adevărat face exact același lucru sub linia de comandă (antetele sunt fără
      // efect acolo), iar o copie a lui ar fi doar încă un loc de întreținut.
      require ${JSON.stringify(path.join(ROOT, 'api/lib/http.php'))};
      require ${JSON.stringify(path.join(ROOT, 'api/lib/settings.php'))};
      require ${JSON.stringify(path.join(ROOT, 'api/routes/products.php'))};
      route_lists_get();
    `;
    let hq = null, eroare = '';
    try {
      hq = JSON.parse(execFileSync(PHP, ['-r', cod_php], { cwd: ROOT, encoding: 'utf8' })).hq;
    } catch (e) {
      eroare = ((e.stdout || '') + ' ' + (e.stderr || '') + ' ' + e.message).replace(/\s+/g, ' ').slice(0, 180);
    }
    check(`GET /lists cu hq „${lat || '(gol)'} / ${lng || '(gol)'}” în bază dă ${astLat} / ${astLng}`,
      hq !== null && Math.abs(hq.lat - astLat) < 1e-9 && Math.abs(hq.lng - astLng) < 1e-9,
      hq === null ? eroare : JSON.stringify(hq));
  }
}

/* --------------------------------------------- adresa din pagina publică -- */
// seo/index.php citește direct din baza de date, deci ca să-i punem în față
// valorile din scenariu ne facem o bază numai a noastră. Rulăm o COPIE a
// fișierului adevărat: dacă reparația din el dispare, proba pică.
console.log('\n=== adresa publică nu mai vine din eticheta opririi de pe rută ===');

let db = null;
try {
  db = JSON.parse(execFileSync(PHP, ['-r', '$c = require ' + CERE_CONFIG + '; echo json_encode($c["db"]);'],
    { cwd: ROOT, encoding: 'utf8' }));
} catch (e) {
  check('pot citi api/config.php pentru datele bazei', false, (e.message || '').split('\n')[0]);
}

// Aceeași plasă ca în aplica-sql.mjs: nu ne atingem de o bază care nu e de
// dezvoltare, nici măcar ca să facem alta lângă ea.
if (db && !BAZA_DE_PROBA.test(db.name)) {
  check('baza configurată este una de dezvoltare', false, 'am găsit „' + db.name + '”');
  db = null;
}

const PROBA = 'gp_proba_seo';
let tmp = null;

function sql(text, dbName) {
  return execFileSync(MYSQL, [
    '--default-character-set=utf8mb4',
    '-u', db.user, ...(db.pass ? ['-p' + db.pass] : []),
    '-h', db.host, '-P', String(db.port), ...(dbName ? [dbName] : []),
  ], { input: text, encoding: 'utf8' });
}

/** Pune valorile date în baza de probă și întoarce HTML-ul servit de copie. */
function serveste(setari) {
  const randuri = Object.entries(setari)
    .map(([k, v]) => `('${k}', ${JSON.stringify(v).replace(/^"|"$/g, "'")}, 0)`)
    .join(',\n');
  sql(`DELETE FROM settings;\nINSERT INTO settings (setting_key, value, is_secret) VALUES\n${randuri};`, PROBA);
  return execFileSync(PHP, [path.join(tmp, 'index.php')], {
    cwd: tmp,
    encoding: 'utf8',
    env: { ...process.env, GP_CONFIG: path.join(tmp, 'config.php') },
  });
}

const desc = (html) => (html.match(/<meta name="description" content="([^"]*)"/) || [])[1] || '';
const ld = (html) => {
  const m = html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/);
  try { return m ? JSON.parse(m[1]) : null; } catch { return null; }
};
const adresaNoscript = (html) => (html.match(/<strong>Adresă:<\/strong>\s*([^<]*)/) || [])[1] || '';

if (db) {
  try {
    sql(`DROP DATABASE IF EXISTS ${PROBA};
         CREATE DATABASE ${PROBA} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`);
    sql(`
      CREATE TABLE settings (
        setting_key VARCHAR(64) PRIMARY KEY,
        value TEXT NULL,
        is_secret TINYINT NOT NULL DEFAULT 0
      );
      CREATE TABLE product_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(64) UNIQUE, name VARCHAR(120), note TEXT NULL,
        active TINYINT NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0
      );
      CREATE TABLE products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(64) UNIQUE, name VARCHAR(120), name_en VARCHAR(120) NULL,
        description TEXT NULL, photo VARCHAR(255) NULL, grams INT NULL,
        price_frozen DECIMAL(10,2) NULL, price_baked DECIMAL(10,2) NULL,
        category VARCHAR(64), active TINYINT NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0
      );
      INSERT INTO product_categories (slug, name, note) VALUES ('strudele', 'Ștrudele', NULL);
      INSERT INTO products (slug, name, description, category, grams, price_frozen)
        VALUES ('strudel-mere', 'Ștrudel cu mere', 'Foietaj cu mere', 'strudele', 120, 3.50);
    `, PROBA);

    tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'gp-proba-seo-'));
    fs.copyFileSync(path.join(ROOT, 'seo/index.php'), path.join(tmp, 'index.php'));
    fs.writeFileSync(path.join(tmp, 'app.html'), '<!doctype html><html><head></head><body></body></html>');
    const php = (v) => "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    fs.writeFileSync(path.join(tmp, 'config.php'),
      "<?php return ['db' => ["
      + "'host' => " + php(db.host) + ', '
      + "'port' => " + Number(db.port) + ', '
      + "'name' => " + php(PROBA) + ', '
      + "'user' => " + php(db.user) + ', '
      + "'pass' => " + php(db.pass ?? '') + ', '
      + "'charset' => 'utf8mb4'"
      + ']];');

    const comun = {
      company_name: 'Golden Pastry',
      company_phone: '+40769493163',
      company_email: 'contact@goldenpastry.ro',
      delivery_zones: '["Sector 1"]',
      hq_lng: '26.12385',
    };

    /* Scenariul din brief: eticheta opririi, scurtată de administrator. */
    {
      const html = serveste({ ...comun, hq_label: 'Depozit', hq_lat: '44.39235' });
      const d = desc(html);
      // Fără asta, o bază de probă necitită ar face restul verificărilor să
      // treacă din întâmplare: seo_data() înghite orice eroare și întoarce
      // liste goale, iar descrierea sare pe varianta scurtă, fără adresă.
      check('copia chiar a citit catalogul din baza de probă',
        /Laborator propriu/.test(d), d.slice(0, 90));
      check('descrierea nu mai preia eticheta opririi de pe rută',
        d !== '' && !/Depozit/.test(d), d.slice(-90));
      check('descrierea conține adresa firmei',
        /Șos\. Olteniței 103, sector 4, București/.test(d), d.slice(-90));
      check('pagina fără JavaScript arată adresa, nu eticheta opririi',
        /Șos\. Olteniței 103, sector 4, București/.test(adresaNoscript(html)),
        JSON.stringify(adresaNoscript(html)));

      const firma = (ld(html)?.['@graph'] || [])[0];
      check('datele structurate spun aceeași stradă ca textul',
        typeof firma?.address?.streetAddress === 'string'
        && d.includes(firma.address.streetAddress),
        JSON.stringify(firma?.address));
    }

    /* Caseta „Punctul de plecare al livrărilor” golită din panou. */
    {
      const html = serveste({ ...comun, hq_label: '', hq_lat: '44.39235' });
      const d = desc(html);
      check('cu eticheta opririi golită, adresa din descriere rămâne întreagă',
        /Șos\. Olteniței 103, sector 4, București/.test(d) && !/București, \./.test(d), d.slice(-90));
      check('cu eticheta opririi golită, <noscript> nu rămâne fără adresă',
        adresaNoscript(html).trim().length > 10, JSON.stringify(adresaNoscript(html)));
    }

    /* Coordonatele scrise cu virgulă, rămase în bază de dinainte de verificare. */
    {
      const html = serveste({ ...comun, hq_label: 'Depozit', hq_lat: '44,39235', hq_lng: '26,12385' });
      const geo = ((ld(html)?.['@graph'] || [])[0] || {}).geo;
      check('geo-ul trimis motoarelor de căutare nu mai pierde zecimalele',
        Math.abs((geo?.latitude ?? 0) - 44.39235) < 1e-9 && Math.abs((geo?.longitude ?? 0) - 26.12385) < 1e-9,
        JSON.stringify(geo));
    }

    /* Coordonate nefolosibile: cad pe depozitul din cod, nu pe 0. */
    {
      const html = serveste({ ...comun, hq_label: 'Depozit', hq_lat: 'n/a', hq_lng: '' });
      const geo = ((ld(html)?.['@graph'] || [])[0] || {}).geo;
      check('coordonatele nefolosibile nu mai mută patiseria în Golful Guineei',
        geo?.latitude === 44.39235 && geo?.longitude === 26.12385, JSON.stringify(geo));
    }

    /* Cheia care ar trebui să țină adresa publică, dacă cineva o adaugă. */
    {
      const html = serveste({ ...comun, hq_label: 'Depozit', hq_lat: '44.39235', company_address: 'Str. Probei 7' });
      const d = desc(html);
      const firma = (ld(html)?.['@graph'] || [])[0];
      check('o adresă configurată în `company_address` ajunge în descriere',
        /Str\. Probei 7, sector 4, București/.test(d), d.slice(-90));
      check('și în datele structurate, nu doar în text',
        firma?.address?.streetAddress === 'Str. Probei 7', JSON.stringify(firma?.address));
    }

    /* Golită, cheia nu are voie să lase pagina fără adresă. */
    {
      const html = serveste({ ...comun, hq_label: 'Depozit', hq_lat: '44.39235', company_address: '' });
      const d = desc(html);
      check('`company_address` golită cade pe adresa din cod, nu pe nimic',
        /Șos\. Olteniței 103, sector 4, București/.test(d), d.slice(-90));
    }
  } catch (e) {
    check('probele pentru pagina publică au putut rula', false,
      ((e.stdout || '') + ' ' + (e.stderr || '') + ' ' + e.message).replace(/\s+/g, ' ').slice(0, 200));
  } finally {
    try { sql(`DROP DATABASE IF EXISTS ${PROBA};`); } catch { /* a rămas curat oricum */ }
    if (tmp) { try { fs.rmSync(tmp, { recursive: true, force: true }); } catch { /* temp */ } }
  }
}

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} eșuate`);
if (failures.length) {
  console.log('\nEșecuri:');
  failures.forEach((f) => console.log('  · ' + f));
}
process.exit(fail ? 1 : 0);
