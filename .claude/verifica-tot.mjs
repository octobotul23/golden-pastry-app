// Rulează toate verificările și toate testele, într-o singură comandă.
//
//   node .claude/verifica-tot.mjs
//
// Testele au nevoie de serverul local pornit:
//   php -S 127.0.0.1:8100 -t . .claude/dev-server.php
//
// Verificările statice merg oricând, fără server.

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');

const STATIC = [
  ['verify-import', 'fișierele importate sunt întregi'],
  ['check-pages', 'sintaxa paginilor și metodele apelate'],
  ['check-php', 'funcțiile PHP se încarcă între fișiere'],
  ['check-sql', 'tabelele și coloanele se potrivesc'],
  ['check-secrets', 'nicio parolă sau token în paginile din browser'],
  ['check-dates', 'nicio dată calendaristică scrisă în cod'],
  ['check-meniu', 'meniul lateral e la fel pe toate panourile'],
  ['check-site-public', 'reparațiile din pagina publică (coș, telefon, catalog, traduceri)'],
  ['check-poze', 'sloturile de poze: atingerea, slotul gol, trasul cu mouse-ul'],
  ['check-setari', 'butonul de test nu mai șterge modificările nesalvate din Setări'],
  ['check-deploy', 'pachetul de publicare: .htaccess, robots.txt, rescrierile din pagini'],
  ['check-panouri', 'reparațiile din panourile de lucru și din ajutorul comun'],
  ['test-php.php', 'funcții PHP verificate direct (adresa clientului, adrese produse)'],
  ['test-doc-actualizari', 'ghidul de actualizare arată spre codul real'],
  ['test-defect-depozit', 'depozit: mișcarea pierdută nu mai pare salvată, iar „Rezervat” e cifra serverului'],
  ['test-defect-unelte', 'uneltele nu mai mint: probele vacue pică pe cod stricat'],
  ['test-defect-404-start', 'pagina de start nu mai cere adrese care nu există (legături nerezolvate, produs fără poză)'],
  ['test-defect-cantitati-zecimale', 'cantitățile zecimale nu mai sunt tăiate: o jumătate de tavă rămâne jumătate'],
];

const LIVE = [
  // Își face singură o bază goală, o instalează și o șterge. Nu are nevoie de
  // serverul local, dar are nevoie de MySQL, ca toate testele de mai jos.
  ['test-instalare.php', 'instalarea de la zero (schema, contul, codul tabletelor)'],
  ['test-api', 'drepturi, sesiuni, CORS'],
  ['test-flow', 'cererea de pe site devine comandă'],
  ['test-parola', 'parola uitată: linkul, tokenul, sesiunile închise'],
  ['test-settings', 'setări, liste, rută, stoc'],
  // Merge direct la baza de date, pe două conexiuni: două apăsări suprapuse pe
  // „Scade din stoc" nu se pot reproduce prin `php -S`, care pe Windows
  // răspunde la o singură cerere odată.
  ['test-stoc-concurent.php', 'scăderea din stoc nu se poate dubla'],
  ['test-catalog', 'catalogul editabil: produse, grupe, poze'],
  ['test-editare', 'formularul din panou salvează tot (client, poziții, zi)'],
  ['test-sloturi', 'pozele puse cu mouse-ul în pagină rămân pe disc'],
  ['test-defect-instalare.php', 'instalarea: parola primului administrator, parola provizorie, tabelele'],
  ['test-defect-conturi', 'conturi: e-mail validat, un singur cont per adresă la resetare'],
  ['test-defect-comenzi-api', 'comenzi: legătura cu contul, referința, anunțul care nu mai blochează'],
  ['test-defect-cereri-stoc', 'cereri și depozit: ziua corectă, nu cea din UTC'],
  ['test-defect-setari-produse', 'setări, coordonate, adresa publică din pagina pentru Google'],
  ['test-defect-panou-comenzi', 'panoul de comenzi: reîmprospătare, fereastra de zile, cantitatea greșită'],
  ['test-defect-pagini-client', 'paginile clientului, șoferului și catalogului'],
  ['test-defect-ziua-comenzii', 'ziua cerută: nici din trecut, nici din anul 3000 — refuzată de la trimitere'],
  ['test-reparat-legatura-cont', 'numele corectat la acceptare nu mai scoate comanda din contul clientului'],
  ['test-defect-cursa-cerere', '„Acceptă" și „Respinge" în aceeași clipă: o singură apăsare câștigă'],
  ['test-reparat-retarifare', 'prețul văzut de client se onorează; doar cantitatea adăugată ia prețul de azi'],
  ['test-defect-produs-scos', 'produsul scos din catalog nu mai îngheață comenzile și cererile care îl conțin'],
  ['test-reparat-drepturi-patch', 'din contul de tură se schimbă doar etapa; clientul comenzii rămâne al administratorului'],
  ['test-defect-zavor-stoc', 'comanda corectată sau anulată își întoarce marfa în depozit; marcajul nu mai e într-un singur sens'],
  ['test-reparat-scadere-dubla', 'un produs redenumit nu mai scade de două ori din depozit; articolul scos din listă nu fură scăderea'],
  ['test-reparat-campuri-comenzi', 'zona, mențiunile, produsul și cantitatea se verifică și la corectare, nu doar la creare'],
  ['test-reparat-depozit-refuzuri', 'datele prea lungi și numele deja luat ies ca refuz lămurit, nu ca „Eroare internă”'],
  ['test-reteta-productie', 'rețete, producție și rezervări: raportul poartă totalul, rescrierea nu pierde ce s-a făcut, iar ce e promis se stinge singur'],
  ['test-seo', 'pagina publică servită de server (cere pachetul pornit)'],
];

// Unde se află PHP. Pe Laragon nu e în PATH, deci îl căutăm.
function phpBinary() {
  if (process.env.GP_PHP) return process.env.GP_PHP;
  const guess = 'C:/laragon/bin/php';
  try {
    const v = fs.readdirSync(guess).find((d) => d.startsWith('php-'));
    if (v) return path.join(guess, v, 'php.exe');
  } catch { /* alt sistem */ }
  return 'php';
}

function run(name) {
  // Probele pe funcții sunt scrise în PHP: unele lucruri — cum ar fi ce
  // consideră serverul „adresa clientului" — nu se pot verifica prin HTTP.
  const isPhp = name.endsWith('.php');
  const r = isPhp
    ? spawnSync(phpBinary(), [path.join(HERE, name)], { cwd: ROOT, encoding: 'utf8' })
    : spawnSync(process.execPath, [path.join(HERE, name + '.mjs')], { cwd: ROOT, encoding: 'utf8' });
  const out = (r.stdout || '') + (r.stderr || '');
  const last = out.trim().split('\n').filter(Boolean).pop() || '(fără ieșire)';
  return { ok: r.status === 0, last: last.replace(/\[\d+m/g, '').trim(), out };
}

let failed = 0;
let total = 0;
const details = [];

/** Ultimul rând al fiecărei suite spune „N trecute”; le adunăm pe toate. */
const numara = (last) => {
  const m = last.match(/^([0-9]+) trecute/);
  if (m) total += Number(m[1]);
};

console.log('\n  VERIFICĂRI STATICE\n');
for (const [name, what] of STATIC) {
  const r = run(name);
  if (!r.ok) { failed++; details.push([name, r.out]); }
  numara(r.last);
  console.log(`  ${r.ok ? '✓' : '✗'} ${name.padEnd(23)} ${what.padEnd(52)} ${r.last}`);
}

console.log('\n  TESTE PE SERVERUL LOCAL\n');
for (const [name, what] of LIVE) {
  const r = run(name);
  if (!r.ok) { failed++; details.push([name, r.out]); }
  numara(r.last);
  console.log(`  ${r.ok ? '✓' : '✗'} ${name.padEnd(23)} ${what.padEnd(52)} ${r.last}`);
}

// Testele lasă în urmă produse pe care API-ul nu le mai poate șterge: un
// produs deja comandat se dezactivează, nu dispare, ca să nu strice comenzile
// vechi. După treisprezece rulări, pagina Catalog era plină de „Ștrudel de
// probă 059rw · SCOS DIN CATALOG". Facem curat aici, la sfârșit.
{
  const r = run('curata-probe');
  if (!/^Nimic de curățat/.test(r.last)) console.log('\n  ' + r.last);
  // Curățenia spune și ce N-A putut lua: o probă care lasă în urmă un nume
  // neînregistrat o face să iasă cu eșec. Ieșirea ei era ignorată aici, deci
  // rândurile se adunau tăcut — șapte sute, până le-am numărat o dată.
  if (!r.ok) { failed++; details.push(['curata-probe', r.out]); }
}

if (details.length) {
  console.log('\n  ────────────────────────────────────────────────────────\n');
  for (const [name, out] of details) {
    console.log(`  ${name}:`);
    console.log(out.split('\n').filter((l) => /EROARE|FAIL|ATENȚIE|Error|·/.test(l)).slice(0, 25).map((l) => '    ' + l).join('\n'));
    console.log('');
  }
}

console.log(`\n  ${total} teste trecute · ${failed} ${failed === 1 ? 'verificare a eșuat' : 'verificări au eșuat'}\n`);
process.exit(failed ? 1 : 0);
