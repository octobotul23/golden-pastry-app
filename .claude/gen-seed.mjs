import fs from 'node:fs';

const rows = JSON.parse(fs.readFileSync(process.argv[2], 'utf8')).sort((a, b) => a.id - b.id);

const DIA = { 'ă': 'a', 'â': 'a', 'î': 'i', 'ș': 's', 'ş': 's', 'ț': 't', 'ţ': 't' };
const slugify = (s) =>
  s.toLowerCase().replace(/[ăâîșşțţ]/g, (c) => DIA[c] || c)
    .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

// Escape pentru literal MySQL: apostrof și backslash.
const q = (v) =>
  v === null || v === undefined ? 'NULL'
    : typeof v === 'number' ? String(v)
      : "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "''") + "'";

// Slug unic, calculat o singură dată și păstrat pe rând.
const seen = new Set();
for (const r of rows) {
  let sl = slugify(r.ro);
  while (seen.has(sl)) sl += '-' + r.id;
  seen.add(sl);
  r.slug = sl;
  r.photo = 'products/prod-' + r.id + '.jpg';
}
rows.forEach((r, i) => { r.sort = i * 10; });

const COLS = [
  'id', 'slug', 'name', 'name_en', 'description', 'description_en',
  'photo', 'grams', 'price_frozen', 'price_baked', 'category', 'is_new', 'sort',
];

const values = rows.map((r) => '  (' + [
  r.id, q(r.slug), q(r.ro), q(r.en), q(r.dro), q(r.den),
  q(r.photo), r.g, r.p1, r.p2, q(r.cat), r.flag ? 1 : 0, r.sort,
].join(', ') + ')');

// Reîmprospătarea se face cu UPDATE-uri explicite, nu cu
// „ON DUPLICATE KEY UPDATE ... VALUES(col)": VALUES() este depreciat în MySQL
// 8.0.20+, iar înlocuitorul recomandat (VALUES ... AS alias) nu există nici în
// MariaDB, nici în MySQL 5.7 — adică exact ce rulează de obicei pe cPanel.
// Varianta INSERT IGNORE + UPDATE merge pe toate, fără avertismente.
const updates = rows.map((r) => `UPDATE products SET
    slug = ${q(r.slug)}, name = ${q(r.ro)}, name_en = ${q(r.en)},
    description = ${q(r.dro)}, description_en = ${q(r.den)},
    photo = ${q(r.photo)}, grams = ${r.g},
    price_frozen = ${r.p1}, price_baked = ${r.p2},
    category = ${q(r.cat)}, is_new = ${r.flag ? 1 : 0}, sort = ${r.sort}
  WHERE id = ${r.id};`);

const SETTINGS = [
  ['company_name', 'Golden Pastry', 0],
  ['company_phone', '+40769493163', 0],
  ['company_email', 'contact@goldenpastry.ro', 0],
  ['notify_email_enabled', '1', 0],
  ['notify_email_to', '', 0],
  ['notify_email_from', 'noreply@goldenpastry.ro', 0],
  ['notify_whatsapp_enabled', '0', 0],
  ['notify_whatsapp_provider', 'meta', 0],
  ['notify_whatsapp_to', '', 0],
  ['notify_whatsapp_phone_id', '', 0],
  ['notify_whatsapp_from', '', 0],
  ['notify_whatsapp_token', '', 1],
  ['notify_whatsapp_template', 'comanda_noua', 0],
  ['notify_whatsapp_status', 'neconfigurat', 0],
  ['notify_whatsapp_last_error', '', 0],
  ['notify_telegram_enabled', '0', 0],
  ['notify_telegram_token', '', 1],
  ['notify_telegram_chat_id', '', 0],
  ['stock_min_default', '10', 0],

  // Codul comun al tabletelor din laborator. Se verifică PE SERVER și deschide
  // o sesiune limitată pe contul „panou": vede și avansează comenzi, dar nu
  // ajunge la conturi, setări sau jurnal.
  //
  // Rămâne GOL aici: fiind secret, valoarea se stochează criptată, iar SQL-ul
  // nu poate cripta. Codul inițial îl generează api/setup.php, care are cheia,
  // și îl arată instalatorului o singură dată — nu mai e o valoare fixă, scrisă
  // și în sursa paginilor publicate. Cât timp e gol, poarta refuză accesul —
  // se închide, nu se deschide.
  ['panel_code', '', 1],
  ['panel_enabled', '1', 0],

  // Liste editabile din panoul de Setări, stocate ca JSON. Ordinea contează:
  // panoul din laborator avansează comanda prin etape exact în această ordine.
  ['order_statuses', JSON.stringify([
    'În așteptare', 'La dospit', 'În cuptor', 'La ambalare', 'Predată', 'Anulată',
  ]), 0],
  ['delivery_slots', JSON.stringify([
    '06:30', '08:00', '09:00', '10:00', '12:30', '14:00', '16:00',
  ]), 0],
  ['delivery_zones', JSON.stringify([
    'Centru', 'Dorobanți', 'Aviatorilor', 'Floreasca', 'Pipera',
    'Timpuri Noi', 'Tineretului', 'Berceni', 'Cotroceni', 'Militari',
  ]), 0],
  ['fulfillment_types', JSON.stringify(['Angro', 'Livrare', 'Ridicare']), 0],
  // Modurile în care marfa NU pleacă cu mașina noastră; restul devin opriri pe
  // ruta șoferului. Ruta le alegea după textul „Livrare" scris în pagină, deci
  // un mod adăugat din Setări sau „Livrare" redenumită o lăsau goală.
  ['fulfillment_pickup', JSON.stringify(['Angro', 'Ridicare']), 0],
  ['stock_categories', JSON.stringify(['Materii prime', 'Produse finite', 'Ambalaje']), 0],
  ['stock_units', JSON.stringify(['buc', 'kg', 'L']), 0],
];

const STOCK = JSON.parse(fs.readFileSync(process.argv[4], 'utf8'))
  .filter((x) => x.category !== 'Produse finite');

const stockRows = [
  ...STOCK.map((x, i) => `  (${q(x.name)}, ${q(x.category)}, ${q(x.unit)}, ${x.min}, NULL, ${i * 10})`),
  ...rows.map((r, i) => `  (${q(r.ro)}, 'Produse finite', 'buc', 0, ${r.id}, ${1000 + i * 10})`),
];

const out = `-- Golden Pastry — date inițiale
-- GENERAT din catalogul real din „Site Public.dc.html" (${rows.length} produse).
--
-- Rulează DUPĂ 001_schema.sql. Se poate rula de câte ori e nevoie: adaugă ce
-- lipsește și reîmprospătează restul, fără să șteargă comenzi sau setări.
--
-- LA REIMPORT vei vedea avertismente „#1062 Duplicate entry ... for key
-- 'products.PRIMARY'", câte unul per produs existent, urmate de „0 rows
-- affected". Este comportamentul normal al lui INSERT IGNORE: rândurile deja
-- prezente sunt sărite, iar UPDATE-urile de mai jos le actualizează datele.
-- Nu este o eroare și nu se pierde nimic.
--
-- Compatibil MySQL 5.7 / 8.x și MariaDB 10.x. Nu folosește VALUES() în
-- ON DUPLICATE KEY UPDATE (depreciat în MySQL 8.0.20+) și nici sintaxa cu
-- alias care o înlocuiește (absentă în MariaDB și MySQL 5.7).

SET NAMES utf8mb4;

-- --------------------------------------------------------------- produse --
-- id-ul corespunde pozei: produsul 9 → products/prod-9.jpg

INSERT IGNORE INTO products
  (${COLS.join(', ')})
VALUES
${values.join(',\n')};

-- Reîmprospătează datele produselor existente (prețuri, denumiri, descrieri).
${updates.join('\n')}

-- ---------------------------------------------------------------- setări --
-- Valorile goale se completează din panoul de Setări (lab.goldenpastry.ro/setari).
-- is_secret = 1 → stocat criptat, nereturnat niciodată în clar de API.
--
-- INSERT IGNORE, deliberat: o rulare ulterioară NU trebuie să șteargă tokenul
-- de WhatsApp sau adresa de notificare pe care le-ai configurat deja.

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
${SETTINGS.map(([k, v, s]) => `  (${q(k)}, ${q(v)}, ${s})`).join(',\n')};

-- --------------------------------------------------------------- depozit --
-- Materiile prime și ambalajele vin din prototip — sunt plauzibile pentru o
-- patiserie și pot fi editate din pagina Depozit. Produsele finite sunt
-- generate din catalogul REAL, legate prin product_id: denumirile demo din
-- prototip („Croissant cu unt", „Kouign-amann") nu corespundeau produselor
-- vândute efectiv.

INSERT IGNORE INTO stock_items (name, category, unit, min_qty, product_id, sort) VALUES
${stockRows.join(',\n')};
`;

fs.writeFileSync(process.argv[3], out);
console.log('scris ' + process.argv[3]);
console.log(`produse: ${rows.length} | setări: ${SETTINGS.length} | update-uri: ${updates.length}`);
const dup = rows.map((r) => r.slug).filter((v, i, a) => a.indexOf(v) !== i);
console.log('slug-uri duplicate: ' + (dup.length ? dup.join(', ') : 'niciunul'));
