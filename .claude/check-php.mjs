// Verificare statică a backendului PHP, fără interpretor PHP instalat.
//
// Prinde în principal o clasă de erori pe care structura routerului o face
// ușor de introdus: index.php încarcă DOAR fișierul de rută care se potrivește,
// deci o funcție definită în routes/orders.php și apelată din routes/stock.php
// ar da „undefined function" abia în producție, pe o rută rar folosită.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// fileURLToPath, nu .pathname: calea proiectului conține spații, care ar
// rămâne codate ca %20.
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const API = path.join(ROOT, 'api');

const files = [];
(function walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p);
    else if (e.name.endsWith('.php')) files.push(p);
  }
})(API);

const rel = (f) => path.relative(ROOT, f).split(path.sep).join('/');

// Funcțiile PHP native pe care le folosim — nu trebuie raportate ca lipsă.
const BUILTIN = new Set(`
array_column array_fill array_filter array_intersect array_key_exists array_keys array_map
array_merge array_push array_unique array_values base64_encode base64_decode bin2hex ceil
count curl_close curl_error curl_exec curl_getinfo curl_init curl_setopt_array
date_default_timezone_set define dirname error_log error_reporting exit explode
file_get_contents filter_var function_exists gmdate hash hash_equals header hex2bin
htmlspecialchars http_build_query http_response_code implode in_array ini_set intdiv
is_array is_bool is_file is_string json_decode json_encode mb_strlen mb_substr number_format
openssl_decrypt openssl_encrypt parse_url password_hash password_needs_rehash password_verify
preg_match preg_replace random_bytes random_int rawurlencode require require_once round rtrim
setcookie str_repeat str_replace str_starts_with stream_context_create strlen strtolower
strtotime strtoupper substr time trim ucfirst usort var_dump printf sprintf max min abs
array_slice array_sum call_user_func json_last_error uniqid
`.trim().split(/\s+/));

const defs = new Map();   // funcție -> [fișiere]
const calls = new Map();  // fișier -> Set(funcții)

const DEF_RE = /^\s*function\s+([a-zA-Z_]\w*)\s*\(/gm;
const CALL_RE = /(?<![>:$\w])([a-z_]\w*)\s*\(/g;

// Funcțiile date ca TEXT, nu ca apel: array_map('nume', ...).
//
// Verificarea de dinainte căuta doar tiparul `nume(`, deci nu vedea deloc
// callback-urile trimise ca șir. Exact așa a trecut nevăzut un array_map cu o
// funcție dintr-un alt fișier de rută: PHP a acceptat fișierul, iar catalogul
// a răspuns cu eroare abia la prima cerere reală.
const CALLBACK_TAKERS = 'array_map|array_filter|array_walk|usort|uasort|uksort'
  + '|call_user_func|call_user_func_array|preg_replace_callback|array_reduce';
const STRING_CALL_RE = new RegExp(
  '(?:' + CALLBACK_TAKERS + ')\\s*\\(\\s*(?:[^,()]*,\\s*)?[\'"]([a-z_]\\w*)[\'"]', 'g'
);

// Puncte de intrare separate: nu se încarcă niciodată împreună, deci pot
// defini legitim aceeași funcție (de pildă config()).
const ENTRIES = ['index.php', 'setup.php'];
const isEntry = (f) => ENTRIES.includes(path.basename(f));

for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  for (const m of src.matchAll(DEF_RE)) {
    const prev = defs.get(m[1]) || [];
    if (prev.length && !(isEntry(f) && prev.every(isEntry))) {
      console.log(`DUBLURĂ: funcția ${m[1]}() e definită în ${prev.map(rel).join(', ')} și ${rel(f)}`);
    }
    defs.set(m[1], prev.concat(f));
  }
  const set = new Set();
  // Ignorăm definițiile ca să nu se numere drept apeluri.
  const body = src.replace(DEF_RE, '');
  for (const m of body.matchAll(CALL_RE)) set.add(m[1]);
  for (const m of body.matchAll(STRING_CALL_RE)) set.add(m[1]);
  calls.set(f, set);
}

// Context de încărcare: lib/* și index.php sunt mereu disponibile;
// un fișier din routes/ nu vede alt fișier din routes/.
const alwaysLoaded = files.filter((f) => f.includes(`${path.sep}lib${path.sep}`) || f.endsWith('index.php'));
const globalDefs = new Set();
for (const f of alwaysLoaded) {
  for (const [fn, srcs] of defs) if (srcs.includes(f)) globalDefs.add(fn);
}

const problems = [];
for (const f of files) {
  const isRoute = f.includes(`${path.sep}routes${path.sep}`);
  const own = new Set([...defs].filter(([, srcs]) => srcs.includes(f)).map(([fn]) => fn));

  for (const fn of calls.get(f)) {
    if (BUILTIN.has(fn) || own.has(fn) || globalDefs.has(fn)) continue;
    if (!defs.has(fn)) continue;                 // probabil funcție nativă neenumerată
    // Problemă doar dacă NICIUNA dintre definiții nu e disponibilă aici.
    const from = defs.get(fn);
    if (isRoute && !from.includes(f)) {
      problems.push(`${rel(f)} apelează ${fn}() definită în ${from.map(rel).join(', ')} — nu se încarcă împreună`);
    }
  }
}

// Verificări simple de echilibru, care prind trunchieri.
for (const f of files) {
  const src = fs.readFileSync(f, 'utf8');
  const nb = (src.match(/\{/g) || []).length - (src.match(/\}/g) || []).length;
  if (nb !== 0) problems.push(`${rel(f)}: acolade dezechilibrate (${nb > 0 ? '+' : ''}${nb})`);
  if (!src.startsWith('<?php') && !src.startsWith('#') && !src.includes('<?php')) {
    problems.push(`${rel(f)}: nu începe cu <?php`);
  }
}

console.log(`\n${files.length} fișiere PHP | ${defs.size} funcții definite`);
if (problems.length) {
  console.log(`\nPROBLEME (${problems.length}):`);
  for (const p of problems) console.log('  x ' + p);
  process.exit(1);
}
console.log('Nicio problemă de încărcare între fișiere.\n');
