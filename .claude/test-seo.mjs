// Teste pentru pagina publică servită de server.
//
//   node .claude/build-deploy.mjs
//   php -S 127.0.0.1:8101 -t deploy .claude/deploy-server.php
//   node .claude/test-seo.mjs
//
// Miza: ce vede un motor de căutare la PRIMA cerere, fără să ruleze
// JavaScript. Aplicația se construiește în browser, deci fără învelișul ăsta
// Google vede o pagină aproape goală. Iar datele trebuie să vină din aceeași
// bază din care se vând produsele — altfel prețul din rezultatele căutării
// rămâne în urmă față de cel real.

// Adresa e scrisă cu 127.0.0.1, nu cu „localhost": serverul încorporat din PHP
// leagă o singură adresă, iar pe Windows „localhost" se rezolvă întâi la ::1,
// unde nu ascultă nimeni. Cererea ar ajunge oricum, prin reîncercarea pe a doua
// adresă, dar numai dacă prima e refuzată — un firewall care o lasă să cadă în
// gol ar face proba să atârne în loc să pice. Perechea ei, test-api.mjs, folosea
// deja 127.0.0.1.
const BASE = process.env.GP_SITE || 'http://127.0.0.1:8101';

let pass = 0, fail = 0;
const failures = [];

function check(name, cond, extra = '') {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
}

let html = '';
try {
  const res = await fetch(BASE + '/');
  html = await res.text();
  check('pagina publică răspunde → 200', res.status === 200, `a dat ${res.status}`);
} catch (e) {
  // Probele astea cer pachetul construit și servit. Fără el nu sunt „eșuate",
  // ci nerulate — altfel o verificare completă ar părea căzută doar fiindcă
  // n-ai pornit al doilea server.
  console.log('\n  —  sărit: serverul pachetului nu răspunde pe ' + BASE);
  console.log('     node .claude/build-deploy.mjs');
  console.log('     php -S 127.0.0.1:8101 -t deploy .claude/deploy-server.php\n');
  console.log('0 trecute · 0 eșuate (sărit)');
  process.exitCode = 0;
  process.exit(0);
}

/* -------------------------------------------------------------- antet -- */

console.log('\n=== ce vede motorul de căutare ===');
{
  const title = (html.match(/<title>([^<]*)<\/title>/) || [])[1] || '';
  check('are titlu', title.length > 20, JSON.stringify(title));
  check('titlul spune ce vinde firma', /patiserie/i.test(title), title);

  const desc = (html.match(/<meta name="description" content="([^"]*)"/) || [])[1] || '';
  check('are descriere', desc.length > 60, `${desc.length} caractere`);
  check('descrierea conține adresa', /Olteni/i.test(desc), desc.slice(0, 80));

  check('are canonical', /<link rel="canonical"/.test(html));
  check('are imagine de partajare (og:image)', /<meta property="og:image"/.test(html));
  check('are og:title și og:description',
    /og:title/.test(html) && /og:description/.test(html));
}

/* ------------------------------------------------- date structurate -- */

console.log('\n=== datele structurate ===');
{
  const raw = (html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/) || [])[1];
  check('are un bloc schema.org', !!raw);

  let ld = null;
  try { ld = JSON.parse(raw || 'null'); } catch { /* rămâne null */ }
  check('blocul este JSON valid', !!ld, 'un bloc stricat e ignorat în întregime');

  const graph = (ld && ld['@graph']) || [];
  const firma = graph.find((x) => x['@type'] === 'Bakery');
  check('descrie firma', !!firma);
  check('cu adresă completă',
    firma?.address?.streetAddress && firma?.address?.addressLocality,
    JSON.stringify(firma?.address));
  check('cu coordonate', firma?.geo?.latitude > 40 && firma?.geo?.longitude > 20,
    JSON.stringify(firma?.geo));
  check('cu telefon', /\d{6,}/.test(String(firma?.telephone || '')), firma?.telephone);
  check('cu zonele deservite', Array.isArray(firma?.areaServed) && firma.areaServed.length > 0,
    JSON.stringify(firma?.areaServed));

  const lista = graph.find((x) => x['@type'] === 'ItemList');
  check('conține catalogul', !!lista && lista.numberOfItems > 0, String(lista?.numberOfItems));

  const primul = lista?.itemListElement?.[0]?.item;
  check('fiecare produs are nume și preț',
    !!primul?.name && Number(primul?.offers?.price) > 0,
    JSON.stringify(primul?.offers));
  check('prețul e în lei', primul?.offers?.priceCurrency === 'RON', primul?.offers?.priceCurrency);
}

/* ---------------------------------------- catalogul, fără JavaScript -- */

console.log('\n=== catalogul fără JavaScript ===');
{
  const ns = (html.match(/<noscript>([\s\S]*?)<\/noscript>/) || [])[1] || '';
  check('există un catalog în text', ns.length > 500, `${ns.length} caractere`);
  check('cu titluri de grupe', /<h3>/.test(ns));
  check('cu prețuri în lei', /lei/.test(ns));
  check('cu telefon apelabil', /href="tel:/.test(ns));
}

/* --------------------------------------- datele vin din baza de date -- */

console.log('\n=== datele sunt cele reale ===');
{
  const r = await fetch((process.env.GP_BASE || BASE + '/api') + '/products');
  const d = await r.json();
  const prods = d.products || [];
  check('API-ul răspunde cu produse', prods.length > 0, String(prods.length));

  const raw = (html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/) || [])[1];
  const ld = JSON.parse(raw);
  const lista = ld['@graph'].find((x) => x['@type'] === 'ItemList');

  check('pagina publică arată exact câte produse are catalogul',
    lista.numberOfItems === prods.filter((p) => p.priceBaked !== null || p.priceFrozen !== null).length,
    `pagina ${lista.numberOfItems}, catalogul ${prods.length}`);

  // Miezul: prețul din rezultatele căutării trebuie să fie cel din bază.
  const primul = prods[0];
  const inPagina = lista.itemListElement.find((x) => x.item.name === primul.name);
  // Prețul marcat e cel afișat implicit pe card: magazinul se deschide pe
  // varianta congelată, deci acela e numărul mare de pe pagină.
  check('prețul din pagina publică e cel din catalog',
    Number(inPagina?.item?.offers?.price) === (primul.priceFrozen ?? primul.priceBaked),
    `pagina ${inPagina?.item?.offers?.price}, catalogul ${primul.priceFrozen ?? primul.priceBaked}`);
}

/* ------------------------------ prețul marcat e cel de pe ecran --------- */
// Defect seo/index.php:162. Magazinul se deschide pe varianta congelată
// (`tier: 'frozen'` în Site Public.dc.html), deci prețul mare de pe card este
// price_frozen. Marcajul trimitea prețul „gata de servire", mai mare, adică
// Google arăta în rezultate o sumă pe care pagina n-o afișează.

console.log('\n=== prețul trimis motoarelor e cel de pe ecran ===');
{
  const r = await fetch((process.env.GP_BASE || BASE + '/api') + '/products');
  const prods = (await r.json()).products || [];
  const raw = (html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/) || [])[1];
  const lista = JSON.parse(raw)['@graph'].find((x) => x['@type'] === 'ItemList');
  const marcat = new Map((lista?.itemListElement || [])
    .map((x) => [x.item.name, Number(x.item.offers?.price)]));

  check('marcajul chiar conține produse', marcat.size > 0, String(marcat.size));

  // Fără produse la care cele două prețuri diferă, proba ar trece și cu
  // reparația dată înapoi, deci n-ar dovedi nimic.
  const cuDoua = prods.filter((p) => p.priceFrozen !== null && p.priceBaked !== null
    && p.priceFrozen !== p.priceBaked && marcat.has(p.name));
  check('catalogul are produse cu preț congelat diferit de cel copt',
    cuDoua.length > 0, String(cuDoua.length));

  const gresite = prods
    .filter((p) => marcat.has(p.name))
    .filter((p) => marcat.get(p.name) !== (p.priceFrozen ?? p.priceBaked))
    .map((p) => `${p.name}: marcat ${marcat.get(p.name)}, pe pagină ${p.priceFrozen ?? p.priceBaked}`);
  check('fiecare preț din datele structurate e cel afișat implicit pe card',
    gresite.length === 0, gresite.slice(0, 3).join(' · '));
}

/* ------------------------------------------------------------ sitemap -- */

console.log('\n=== sitemap ===');
{
  const res = await fetch(BASE + '/sitemap.xml');
  const xml = await res.text();
  check('sitemap.xml răspunde → 200', res.status === 200, `a dat ${res.status}`);
  check('e XML de sitemap', /<urlset/.test(xml));
  check('conține pagina principală', /<loc>[^<]*\/<\/loc>/.test(xml));
  check('are dată de actualizare', /<lastmod>\d{4}-\d{2}-\d{2}<\/lastmod>/.test(xml));

  // Harta e anunțată în robots.txt, deci motoarele de căutare cer pe rând
  // fiecare adresă scrisă în ea. Una care nu există le întoarce 404 și ajunge
  // pagină negăsită în Search Console. Magazinul are o singură pagină reală:
  // catalogul, distribuția, „despre" și contactul se comută în browser, în
  // același document, și nu au adrese proprii pe server.
  const adrese = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
  check('harta declară cel puțin o adresă', adrese.length > 0, String(adrese.length));

  const rupte = [];
  for (const adresa of adrese) {
    // Gazda din <loc> e cea pe care rulează pachetul; cerem doar calea, de la
    // serverul de probă, ca verificarea să nu iasă niciodată pe internet.
    const cale = new URL(adresa).pathname;
    const r = await fetch(BASE + cale, { redirect: 'manual' });
    if (r.status >= 400) rupte.push(`${cale} → ${r.status}`);
  }
  check('fiecare adresă din sitemap există pe server', rupte.length === 0, rupte.join(', '));
}

/* -------------------------------------------- o singură adresă publică -- */
// Defect seo/index.php:103. www.goldenpastry.ro răspunde din același folder ca
// goldenpastry.ro, iar $base se lua direct din antetul Host: pagina cerută pe
// www se declara pe sine canonică, deci magazinul exista la două adrese.

console.log('\n=== www duce la aceeași adresă ===');
{
  const u = new URL(BASE);
  const net = await import(u.protocol === 'https:' ? 'node:https' : 'node:http');

  // `fetch` din Node rescrie antetul Host cu gazda din adresă, deci cererea se
  // face de mână: altfel proba ar întreba mereu localhost și n-ar vedea
  // niciodată cazul de pe www, adică exact cel care se repară.
  const caWww = (cale) => new Promise((resolve, reject) => {
    const rq = net.request({
      host: u.hostname,
      port: u.port || (u.protocol === 'https:' ? 443 : 80),
      path: cale,
      headers: { Host: 'www.goldenpastry.ro' },
    }, (res) => {
      let d = '';
      res.setEncoding('utf8');
      res.on('data', (c) => { d += c; });
      res.on('end', () => resolve(d));
    });
    rq.on('error', reject);
    rq.end();
  });

  const pagina = await caWww('/');
  const canonical = (pagina.match(/<link rel="canonical" href="([^"]*)"/) || [])[1] || '';
  const ogUrl = (pagina.match(/<meta property="og:url" content="([^"]*)"/) || [])[1] || '';

  check('pagina cerută de pe www răspunde cu antet', canonical !== '');
  check('canonical arată spre adresa fără www', !/\/\/www\./i.test(canonical), canonical);
  check('og:url arată spre adresa fără www', ogUrl !== '' && !/\/\/www\./i.test(ogUrl), ogUrl);
  check('firma din datele structurate nu se mută pe www',
    !/"@id":\s*"[^"]*\/\/www\./i.test(pagina) && !/"url":\s*"[^"]*\/\/www\./i.test(pagina));

  const harta = await caWww('/sitemap.xml');
  check('sitemap-ul cerut de pe www listează tot adresele fără www',
    /<loc>/.test(harta) && !/<loc>[^<]*\/\/www\./i.test(harta),
    (harta.match(/<loc>[^<]*www[^<]*<\/loc>/) || [])[0] || '');

  // Cealaltă jumătate a reparației: cine ajunge totuși pe www trebuie mutat cu
  // 301. Regula nu se poate proba printr-o cerere — serverul de probă nu citește
  // .htaccess — deci o citim din pachetul care chiar se urcă pe cPanel.
  const fs = await import('node:fs');
  const caleHt = new URL('../deploy/public_html/.htaccess', import.meta.url);
  const ht = fs.existsSync(caleHt) ? fs.readFileSync(caleHt, 'utf8') : '';
  check('pachetul public are .htaccess', ht !== '', 'rulează întâi node .claude/build-deploy.mjs');
  check('.htaccess mută www pe adresa fără www, cu 301',
    ht.includes('RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]')
    && ht.includes('RewriteRule ^ https://%1%{REQUEST_URI} [R=301,L]'),
    'lipsește regula din HT_COMMON (.claude/build-deploy.mjs)');
}

/* ------------------------------------------- aplicația rămâne întreagă -- */

console.log('\n=== aplicația nu e afectată ===');
{
  check('învelișul păstrează runtime-ul paginii', /support\.js/.test(html));
  check('și clientul de API', /shared\/gp-api\.js/.test(html));
  check('și blocul aplicației', /<x-dc>/.test(html));

  // „app.html cerut direct" nu se poate proba cerându-l de la serverul de
  // probă: `php -S` nu citește .htaccess, deci trimite fișierul brut cu 200 —
  // adică exact pagina fără antet pe care proba spune că o refuză. Verificarea
  // dinainte punea acel 200 printre rezultatele bune, deci nu putea să pice:
  // trecea verde și cu rescrierea ștearsă, și cu ea la locul ei.
  //
  // Facem ca la regula de www de mai sus: citim regula din pachetul care chiar
  // se urcă pe cPanel. Cererea propriu-zisă o punem doar acolo unde poate
  // dovedi ceva — pe un server care citește .htaccess.
  const fs = await import('node:fs');
  const caleHt = new URL('../deploy/public_html/.htaccess', import.meta.url);
  const ht = fs.existsSync(caleHt) ? fs.readFileSync(caleHt, 'utf8') : '';
  check('.htaccess mută app.html cerut direct la „/", cu 301',
    ht.includes('RewriteRule ^app\\.html$ / [R=301,L]'),
    ht === '' ? 'rulează întâi node .claude/build-deploy.mjs'
              : 'lipsește regula din HT_SEO (.claude/build-deploy.mjs)');

  if (process.env.GP_SITE) {
    const direct = await fetch(BASE + '/app.html', { redirect: 'manual' });
    const corp = direct.status === 200 ? await direct.text() : '';
    // Ori mută cererea, ori — dacă tot răspunde cu 200 — pagina are antetul.
    check('app.html cerut direct nu servește o pagină fără antet',
      direct.status === 301 || direct.status === 302
      || (/<link rel="canonical"/.test(corp) && /<title>/.test(corp)),
      `a dat ${direct.status}${direct.status === 200 ? ', fără canonical și fără titlu' : ''}`);
  } else {
    console.log('  —     app.html cerut direct: sărit '
      + '(serverul de probă nu citește .htaccess — regula e verificată mai sus)');
  }
}

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} eșuate`);
if (failures.length) {
  console.log('\nEșecuri:');
  failures.forEach((f) => console.log('  · ' + f));
}
process.exitCode = fail ? 1 : 0;
