// Aplică un fișier .sql pe baza de dezvoltare.
//
//   node .claude/aplica-sql.mjs sql/004_variante_pe_pozitie.sql
//
// Pe serverul adevărat migrările se importă din phpMyAdmin, pas cu pas; aici
// e doar ca să nu deschidem interfața la fiecare schimbare de schemă.
//
// Refuză orice bază care nu e una de dezvoltare: datele adevărate nu se ating
// dintr-un script de test. Cu o instanță izolată pornită și `GP_BASE` arătând
// spre ea, migrarea se aplică pe baza instanței — nu pe `gp_dev`.

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BAZA_DE_PROBA, CERE_CONFIG } from './baza-probei.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function bin(envVar, folder, exe) {
  if (process.env[envVar]) return process.env[envVar];
  const guess = 'C:/laragon/bin/' + folder;
  try {
    const v = fs.readdirSync(guess).find((d) => d.startsWith(folder + '-'));
    if (v) return path.join(guess, v, folder === 'mysql' ? 'bin' : '', exe);
  } catch { /* alt sistem */ }
  return exe.replace('.exe', '');
}

const fisier = process.argv[2];
if (!fisier) {
  console.error('Folosire: node .claude/aplica-sql.mjs <fișier.sql>');
  process.exit(2);
}
const cale = path.resolve(ROOT, fisier);
if (!fs.existsSync(cale)) {
  console.error('Nu găsesc fișierul: ' + fisier);
  process.exit(2);
}

let db;
try {
  db = JSON.parse(execFileSync(bin('GP_PHP', 'php', 'php.exe'), [
    '-r', '$c = require ' + CERE_CONFIG + '; echo json_encode($c["db"]);',
  ], { cwd: ROOT, encoding: 'utf8' }));
} catch (e) {
  console.error('Nu pot citi api/config.php: ' + e.message.split('\n')[0]);
  process.exit(1);
}

if (!BAZA_DE_PROBA.test(db.name)) {
  console.error(`Refuz: baza „${db.name}” nu e una de dezvoltare.`);
  process.exit(1);
}

try {
  execFileSync(bin('GP_MYSQL', 'mysql', 'mysql.exe'), [
    '--default-character-set=utf8mb4',
    '-u', db.user, ...(db.pass ? ['-p' + db.pass] : []),
    '-h', db.host, '-P', String(db.port), db.name,
  ], { input: fs.readFileSync(cale, 'utf8'), encoding: 'utf8', stdio: ['pipe', 'inherit', 'inherit'] });
  console.log(`aplicat pe „${db.name}”: ${path.basename(cale)}`);
} catch (e) {
  console.error('Import eșuat: ' + (e.message || '').split('\n').slice(0, 4).join('\n'));
  process.exit(1);
}
