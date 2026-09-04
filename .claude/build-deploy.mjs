// Construiește pachetul de publicare în `deploy/`.
//
// De ce un script și nu copiere de mână: fiecare subdomeniu primește alt set
// de pagini, cu alte legături între ele. Legăturile din același subdomeniu
// rămân relative; cele care trec dintr-un subdomeniu în altul devin adrese
// complete. Făcut manual, o singură legătură ratată dă un „404" pe care nu-l
// vede nimeni până când un angajat apasă butonul.
//
//   node .claude/build-deploy.mjs
//
// Rezultatul se urcă în cPanel, folder cu folder. Nimic din `deploy/` nu se
// editează de mână: se schimbă sursa și se rulează din nou.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const OUT = path.join(ROOT, 'deploy');

const DOMAIN = 'goldenpastry.ro';

// Unde ajunge fiecare subdomeniu și cum se numește adresa lui.
const HOSTS = {
  root:    { origin: `https://${DOMAIN}`,          dir: 'public_html' },
  clienti: { origin: `https://clienti.${DOMAIN}`,  dir: 'clienti' },
  lab:     { origin: `https://lab.${DOMAIN}`,      dir: 'lab' },
  api:     { origin: `https://api.${DOMAIN}`,      dir: 'api' },
};

// Fiecare pagină: pe ce subdomenii stă și sub ce nume.
// `Intrare` apare pe două subdomenii — e poarta de intrare pentru amândouă.
const PAGES = [
  // Pe domeniul principal aplicația se numește app.html: index.php o citește,
  // îi completează antetul cu datele pentru motoarele de căutare și o servește.
  { src: 'Site Public.dc.html',        on: { root: 'app.html' } },
  { src: 'Intrare.dc.html',            on: { clienti: 'index.html', lab: 'index.html' } },
  { src: 'Client.dc.html',             on: { clienti: 'client.html' } },
  { src: 'Administrare Comenzi.dc.html', on: { lab: 'comenzi.html' } },
  { src: 'Panou Laborator.dc.html',    on: { lab: 'laborator.html' } },
  { src: 'Depozit.dc.html',            on: { lab: 'depozit.html' } },
  { src: 'Conturi.dc.html',            on: { lab: 'conturi.html' } },
  { src: 'Jurnal.dc.html',             on: { lab: 'jurnal.html' } },
  { src: 'Ruta Sofer.dc.html',         on: { lab: 'ruta.html' } },
  { src: 'Produse.dc.html',            on: { lab: 'produse.html' } },
  { src: 'Setari.dc.html',             on: { lab: 'setari.html' } },
  // Schimbarea propriei parole. Stă pe amândouă subdomeniile: și clientul, și
  // omul din laborator primesc o parolă provizorie de la administrator, și
  // până acum niciunul nu avea de unde s-o schimbe.
  { src: 'Parola.dc.html',             on: { clienti: 'parola.html', lab: 'parola.html' } },
];

// Fișierele de care are nevoie fiecare subdomeniu. Se dublează, deliberat:
// legate de pe alt subdomeniu, fonturile din CSS ar cere anteturi CORS, iar o
// singură setare lipsă ar strica tipografia pe toate paginile.
const ASSETS = {
  root:    ['_ds', 'shared', 'support.js', 'image-slot.js', '.image-slots.state.json',
            'products', 'assets', 'logo-full-gold.png',
            'ratio-1x1-golden-pastryuntitled-design-3-mt7jbr2f-anx6.png',
            'untitled-design-4--mt7jflxp-jzo9.png'],
  clienti: ['_ds', 'shared', 'support.js', 'assets'],
  lab:     ['_ds', 'shared', 'support.js', 'assets'],
};

/* ------------------------------------------------------------- ajutoare -- */

function rmrf(p) {
  // Pe Windows, ștergerea pică cu EPERM dacă un server de test ține folderul
  // deschis. Reîncercăm de câteva ori înainte să ne dăm bătuți.
  if (!fs.existsSync(p)) return;
  for (let i = 0; i < 5; i++) {
    try {
      fs.rmSync(p, { recursive: true, force: true, maxRetries: 3, retryDelay: 120 });
      return;
    } catch (e) {
      if (i === 4) {
        throw new Error('Nu pot șterge ' + p + ' — oprește serverul de test (portul 8101) și încearcă din nou.');
      }
    }
  }
}

function copy(src, dst) {
  const st = fs.statSync(src);
  if (st.isDirectory()) {
    fs.mkdirSync(dst, { recursive: true });
    for (const f of fs.readdirSync(src)) copy(path.join(src, f), path.join(dst, f));
  } else {
    fs.mkdirSync(path.dirname(dst), { recursive: true });
    fs.copyFileSync(src, dst);
  }
}

function bytes(p) {
  const st = fs.statSync(p);
  if (!st.isDirectory()) return st.size;
  return fs.readdirSync(p).reduce((a, f) => a + bytes(path.join(p, f)), 0);
}

const human = (n) => (n < 1024 ? n + ' B' : n < 1048576 ? (n / 1024).toFixed(0) + ' KB' : (n / 1048576).toFixed(1) + ' MB');

/**
 * Adresa publică a unei pagini, în folderul în care e scrisă.
 *
 * `app.html` este singura excepție: fișierul trebuie să existe, fiindcă
 * `index.php` îl citește și îl servește mai departe cu antetul, datele
 * structurate și catalogul scrise dinainte. Dar adresa magazinului este „/",
 * nu „/app": cerut direct, `app.html` este aceeași pagină fără nimic din ce
 * are nevoie un motor de căutare, iar `.htaccess` îl trimite oricum la „/".
 * O legătură către „/app" ar fi dus vizitatorii pe varianta dezbrăcată.
 */
function slugUrl(file) {
  if (file === 'app.html') return 'index';
  return file.replace(/\.html$/, '');
}

/** Unde ajunge o pagină sursă, privită de pe subdomeniul `from`. */
function target(srcFile, from) {
  const page = PAGES.find((p) => p.src === srcFile);
  if (!page) return null;

  // Aceeași pagină există pe subdomeniul curent? Atunci legătura rămâne relativă.
  // `index` se scrie ca „./": adresa de pornire a subdomeniului, nu „/index".
  if (page.on[from]) {
    const slug = slugUrl(page.on[from]);
    return slug === 'index' ? './' : slug;
  }

  // Altfel, prima gazdă pe care se află — cu adresă completă.
  const host = Object.keys(page.on)[0];
  const slug = slugUrl(page.on[host]);
  return HOSTS[host].origin + (slug === 'index' ? '/' : '/' + slug);
}

/** Aceeași rescriere, pentru fișierele de cod dintr-un folder copiat. */
function rescrieFolder(dir, host) {
  for (const nume of fs.readdirSync(dir)) {
    const p = path.join(dir, nume);
    if (fs.statSync(p).isDirectory()) { rescrieFolder(p, host); continue; }
    if (!/\.(js|css|json)$/i.test(nume)) continue;
    const { out } = rewriteLinks(fs.readFileSync(p, 'utf8'), host);
    fs.writeFileSync(p, out);
  }
}

/** Rescrie toate trimiterile către `*.dc.html` din conținutul unei pagini. */
function rewriteLinks(src, from) {
  let out = src;
  let missed = [];

  for (const page of PAGES) {
    const dest = target(page.src, from);
    // Forma din HTML este codificată („Administrare%20Comenzi.dc.html"), cea
    // din JavaScript poate fi și necodificată.
    for (const form of [page.src, page.src.replace(/ /g, '%20')]) {
      out = out.split(form).join(dest);
    }
  }

  // Ce a rămas neatins înseamnă o legătură pe care nu o cunosc.
  const left = out.match(/[A-Za-z0-9%. ]+\.dc\.html/g);
  if (left) missed = [...new Set(left)];
  return { out, missed };
}

/* -------------------------------------------------------------- .htaccess -- */

const HT_COMMON = `# Generat de .claude/build-deploy.mjs — nu edita aici, se suprascrie.

RewriteEngine On

# --- o singură gazdă: fără www -----------------------------------------
# www.goldenpastry.ro e servit din același folder ca goldenpastry.ro, deci fără
# regula asta magazinul răspunde la două adrese deodată și motoarele de căutare
# îl socotesc două site-uri cu același conținut. Mutarea stă înaintea forțării
# HTTPS și trimite direct pe https, ca vizitatorul venit pe http://www să nu
# treacă prin două redirecționări una după alta.
RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]
RewriteRule ^ https://%1%{REQUEST_URI} [R=301,L]

# --- HTTPS obligatoriu -------------------------------------------------
# Două condiții, ca să nu intre în buclă când cererea vine printr-un proxy
# care a terminat deja TLS și trimite mai departe pe HTTP.
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !=https
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "SAMEORIGIN"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"

  # HSTS: lăsat comentat intenționat. Odată trimis, browserele refuză HTTP
  # pentru domeniul acesta timp de un an, iar dacă certificatul nu se
  # reînnoiește, site-ul devine complet inaccesibil, nu doar cu avertisment.
  # Pornește-l după ce ai văzut AutoSSL reînnoind o dată singur:
  # Header always set Strict-Transport-Security "max-age=15552000"
</IfModule>

# --- adrese fără .html -------------------------------------------------
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME}.html -f
RewriteRule ^(.+)$ $1.html [L]

# --- fără listarea folderelor ------------------------------------------
# Pe cPanel răsfoirea folderelor e pornită în configurația implicită, iar
# „products/", „_ds/" și „uploads/" n-au pagină de index: cerute direct, își
# arată tot conținutul, cu nume și date. Nu se scurge nimic secret, dar se vede
# structura site-ului și fiecare poză urcată vreodată, inclusiv ale produselor
# scoase din catalog. Linia era doar în .htaccess-ul API-ului; cele trei
# subdomenii de pagini plecau fără ea.
Options -Indexes

# --- fișiere care nu au ce căuta în browser ----------------------------
<FilesMatch "\\.(sql|md|mjs|json|bak|log)$">
  Require all denied
</FilesMatch>
<FilesMatch "^\\.(image-slots|thumbnail)">
  Require all denied
</FilesMatch>

# Excepția stă după interdicții fiindcă doar așa le poate anula. Pagina de
# start își ia din fișierul acesta poza mare din antet și încă două imagini
# care nu au adresă scrisă în marcaj; image-slot.js îl cere cu fetch la fiecare
# deschidere. Prins de regulile de mai sus, fetch-ul primește 403, eroarea e
# înghițită în tăcere și vizitatorul vede pagina fără imaginea principală, fără
# ca cineva să afle de ce.
<Files ".image-slots.state.json">
  Require all granted
</Files>

# --- memorare în browser -----------------------------------------------
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/jpeg   "access plus 30 days"
  ExpiresByType image/png    "access plus 30 days"
  ExpiresByType image/webp   "access plus 30 days"
  ExpiresByType text/css     "access plus 7 days"
  ExpiresByType application/javascript "access plus 7 days"
  # Paginile NU se memorează: altfel o comandă nouă n-ar apărea până la
  # golirea manuală a cache-ului.
  ExpiresByType text/html    "access plus 0 seconds"
</IfModule>
`;

const HT_SEO = `
# --- pagina servită de server ------------------------------------------
# index.php trimite catalogul, adresa și datele structurate din prima cerere;
# el citește app.html și îl servește mai departe. Fără linia asta, Apache ar
# putea prefera un index.html și tot efortul ar rămâne nefolosit.
DirectoryIndex index.php index.html

# Adresa pe care o caută motoarele de căutare, servită de scriptul care o
# construiește din baza de date.
RewriteRule ^sitemap\\.xml$ sitemap.php [L]

# app.html este învelit de index.php; cerut direct, ar fi aceeași pagină fără
# antet. Trimitem la rădăcină.
RewriteRule ^app\\.html$ / [R=301,L]
`;

const HT_NOINDEX = `
# --- ținut în afara Google ---------------------------------------------
# Panourile interne nu au ce căuta în rezultatele căutării.
<IfModule mod_headers.c>
  Header always set X-Robots-Tag "noindex, nofollow, noarchive"
</IfModule>

# --- fotografiile produselor -------------------------------------------
# API-ul întoarce adresa pozei relativ la pagina care o cere
# („products/prod-1.jpg"), iar fișierele stau într-un singur folder, lângă
# site-ul public. Fără rândul de mai jos, fiecare miniatură din pagina Catalog
# ar fi cerută de la lab.${DOMAIN}, unde folderul nu există, și lista de
# produse s-ar umple de pătrate goale. Trimitem cererea acolo unde e fișierul,
# în loc să copiem folderul aici: o copie ar rămâne în urmă chiar de la prima
# poză urcată din acel panou.
RewriteRule ^products/(.+)$ https://${DOMAIN}/products/$1 [R=302,L]
`;

const HT_API = `# Generat de .claude/build-deploy.mjs — nu edita aici, se suprascrie.

RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteCond %{HTTP:X-Forwarded-Proto} !=https
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

# Tot ce nu este fișier real merge la router.
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]

# config.php ține parola bazei de date și cheia de criptare. Serverul îl
# execută, dar nu are voie să îl trimită niciodată ca text.
<Files "config.php">
  Require all denied
</Files>
<FilesMatch "\\.(sql|md|bak|log|example\\.php)$">
  Require all denied
</FilesMatch>

<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Robots-Tag "noindex, nofollow"
</IfModule>

# Fără listarea folderului.
Options -Indexes
`;

const ROBOTS_PUBLIC = `User-agent: *
Allow: /
Sitemap: https://${DOMAIN}/sitemap.xml
`;

const ROBOTS_PRIVATE = `# Panou intern. Nu are ce căuta în căutări, dar nu de aici îl ținem afară:
# dacă i-am interzice robotului să ceară paginile, n-ar ajunge niciodată la
# antetul „X-Robots-Tag: noindex" pus de .htaccess, iar adresa — spre care duc
# butoanele „Intrare" de pe site-ul public, indexat — ar putea fi totuși
# listată, ca un rând gol, fără titlu. Îl lăsăm să intre tocmai ca să citească
# interdicția.
User-agent: *
Allow: /
`;

/* ----------------------------------------------------------------- build -- */

console.log('\nGolden Pastry — pachet de publicare\n');
rmrf(OUT);

let problems = 0;

for (const [host, cfg] of Object.entries(HOSTS)) {
  if (host === 'api') continue;

  const dir = path.join(OUT, cfg.dir);
  fs.mkdirSync(dir, { recursive: true });

  // paginile
  const mine = PAGES.filter((p) => p.on[host]);
  for (const page of mine) {
    let src = fs.readFileSync(path.join(ROOT, page.src), 'utf8');
    const { out, missed } = rewriteLinks(src, host);
    src = out;

    if (missed.length) {
      problems++;
      console.log(`  ! ${page.src} → ${host}: legături nerezolvate: ${missed.join(', ')}`);
    }

    // Interceptorul de navigare căuta „.dc.html"; după redenumire, adresele se
    // termină în „.html" sau n-au deloc extensie.
    //
    // Parantezele din jurul expresiei nu sunt de formă: în pagină ea intră
    // înăuntrul unui `!( )`, iar fără ele negația s-ar lipi doar de primul
    // test și condiția s-ar întoarce pe dos. Atunci tocmai legăturile absolute
    // dintre subdomenii, singurele pe care interceptorul trebuie să le lase în
    // pace, ar fi oprite și duse mai departe cu șirul de interogare al paginii
    // curente lipit la coadă.
    src = src.split('/\\.dc\\.html$/i.test(h)').join('(/^[^:]*$/.test(h) && !/^https?:/i.test(h))');

    // Pagina trebuie să știe pe ce subdomeniu rulează: pe cel al clienților
    // nu se arată intrarea pentru personal.
    src = src.replace('<script src="./shared/gp-api.js"></script>',
      `<script>window.GP_SITE = ${JSON.stringify(host)};</script>\n<script src="./shared/gp-api.js"></script>`);

    fs.writeFileSync(path.join(dir, page.on[host]), src);
  }

  // fișierele auxiliare
  for (const a of ASSETS[host] || []) {
    const from = path.join(ROOT, a);
    if (!fs.existsSync(from)) {
      problems++;
      console.log(`  ! lipsește ${a} (cerut de ${host})`);
      continue;
    }
    copy(from, path.join(dir, a));

    // `shared/gp-api.js` trimite la pagina de autentificare pe nume, ca
    // paginile. Numele acela se schimbă la construirea pachetului, deci
    // fișierul trebuie trecut prin aceeași rescriere — altfel panourile de pe
    // serverul adevărat ar pleca spre „Intrare.dc.html", care acolo nu există.
    // Rescrierea paginilor nu-l atingea: el nu e pagină.
    if (a === 'shared') rescrieFolder(path.join(dir, a), host);
  }

  // Domeniul public primește învelișul servit de server: titlu, descriere,
  // datele structurate schema.org și catalogul ca text, toate din baza de date.
  if (host === 'root') {
    for (const f of ['index.php', 'sitemap.php']) {
      copy(path.join(ROOT, 'seo', f), path.join(dir, f));
    }
  }

  fs.writeFileSync(path.join(dir, '.htaccess'), HT_COMMON + (host === 'root' ? HT_SEO : HT_NOINDEX));
  fs.writeFileSync(path.join(dir, 'robots.txt'), host === 'root' ? ROBOTS_PUBLIC : ROBOTS_PRIVATE);

  const names = mine.map((p) => p.on[host]).join(', ');
  console.log(`  ${cfg.dir.padEnd(12)} ${cfg.origin.padEnd(34)} ${names}`);
}

/* --- API ---------------------------------------------------------------- */

const apiDir = path.join(OUT, 'api');
copy(path.join(ROOT, 'api'), apiDir);

// config.php local conține datele bazei de test. Nu pleacă nicăieri.
const localCfg = path.join(apiDir, 'config.php');
if (fs.existsSync(localCfg)) fs.rmSync(localCfg);

fs.writeFileSync(path.join(apiDir, '.htaccess'), HT_API);
console.log(`  api          ${HOSTS.api.origin.padEnd(34)} index.php + rute (fără config.php)`);

// baza de date, lângă pachet
copy(path.join(ROOT, 'sql'), path.join(OUT, 'sql'));

/* --- raport ------------------------------------------------------------- */

console.log('\n  mărimi:');
for (const d of fs.readdirSync(OUT)) {
  console.log(`    ${d.padEnd(14)} ${human(bytes(path.join(OUT, d)))}`);
}
console.log(`    ${'TOTAL'.padEnd(14)} ${human(bytes(OUT))}`);

// Ultima plasă: nimic din ce ține secrete nu are voie să ajungă în pachet.
for (const stray of ['api/config.php', 'public_html/api/config.php']) {
  if (fs.existsSync(path.join(OUT, stray))) {
    problems++;
    console.log('\n  ! ' + stray + ' a ajuns în pachet — conține parola bazei de date. Șters.');
    fs.rmSync(path.join(OUT, stray));
  }
}

// Paginile de instalare pleacă în pachet dinadins: pasul 5 din
// docs/03-publicare-cpanel.md deschide verifica.php, pasul 6 deschide
// setup.php, deci nu au cum să lipsească de aici. Amândouă cer să fie șterse
// după aceea, și amândouă rămân uneori pe server, pe un domeniu public, fără
// nimic în fața lor. De asta fiecare are un zăvor propriu în cod: din clipa în
// care instalarea are un administrator activ, verifica.php nu mai spune ce
// versiuni și ce tabele rulezi, iar setup.php nu mai există; până atunci,
// setup.php cere codul scris în config.php. Dacă zăvorul dispare dintr-o
// editare de peste jumătate de an, nimeni nu recitește instalatorul înainte de
// publicare — de aceea pachetul nu are voie să plece fără el.
const ZAVOARE = {
  'api/setup.php':    ['http_response_code(404)', "config('install_code'"],
  'api/verifica.php': ['http_response_code(404)'],
};
for (const [rel, semne] of Object.entries(ZAVOARE)) {
  const f = path.join(OUT, rel);
  if (!fs.existsSync(f)) continue;
  const src = fs.readFileSync(f, 'utf8');
  const lipsa = semne.filter((s) => !src.includes(s));
  if (lipsa.length) {
    problems++;
    console.log('\n  ! ' + rel + ' a ajuns în pachet fără zăvorul de după instalare (lipsește '
      + lipsa.join(', ') + '). Șters din pachet — repară sursa și rulează din nou.');
    fs.rmSync(f);
  }
}

/*
 * Nicio urmă de „*.dc.html" în tot pachetul.
 *
 * Numele din dezvoltare nu există pe serverul adevărat: paginile se cheamă
 * acolo „comenzi", „depozit", fără extensie. Rescrierea se făcea doar peste
 * pagini, fiindcă doar ele aveau legături — până când ajutorul comun
 * `shared/gp-api.js` a primit și el o trimitere către pagina de autentificare.
 * Ea a plecat în pachet neatinsă, iar fiecare panou deschis fără sesiune ar fi
 * dus omul într-un 404. Nimic nu s-ar fi văzut local: în dezvoltare numele
 * acela e chiar cel bun.
 *
 * Deci nu se mai are încredere în lista fișierelor de rescris. Se citește ce a
 * ieșit și se cere să nu mai conțină numele vechi, oriunde ar fi.
 */
{
  const ramase = [];
  const cauta = (dir, rel) => {
    for (const nume of fs.readdirSync(dir)) {
      const p = path.join(dir, nume);
      if (fs.statSync(p).isDirectory()) { cauta(p, rel + nume + '/'); continue; }
      if (!/\.(html|js|css|json)$/i.test(nume)) continue;
      const gasit = fs.readFileSync(p, 'utf8').match(/[A-Za-z0-9%. ]+\.dc\.html/g);
      if (gasit) ramase.push(rel + nume + ': ' + [...new Set(gasit)].join(', '));
    }
  };
  // Doar folderele din care browserul chiar navighează. `api/` și `sql/` nu
  // servesc pagini: acolo un nume ca „Depozit.dc.html" stă într-un comentariu
  // care spune programatorului de unde vine cerința, și e bine că stă acolo.
  for (const cfg of Object.values(HOSTS)) {
    if (!cfg.dir) continue;
    const d = path.join(OUT, cfg.dir);
    if (fs.existsSync(d)) cauta(d, cfg.dir + '/');
  }
  if (ramase.length) {
    problems++;
    console.log('\n  ! nume din dezvoltare rămase în pachet (pe server dau 404):');
    for (const r of ramase) console.log('      ' + r);
  }
}

if (problems) {
  console.log(`\n  ${problems} ${problems === 1 ? 'problemă' : 'probleme'} — vezi mai sus.\n`);
  process.exit(1);
}
console.log('\n  Pachetul e gata în deploy/.\n');
