// Instanță izolată: bază proprie + server propriu, pentru probe care pot rula
// în paralel fără să se vadă între ele.
//
//   node .claude/instanta.mjs porneste <nume>   → tipărește adresa API-ului
//   node .claude/instanta.mjs opreste  <nume>
//   node .claude/instanta.mjs curata            → oprește tot și șterge bazele
//
// De ce există: `api/config.php` are numele bazei scris în el, deci toate
// probele „live" lucrează pe `gp_dev`. Două rulate deodată își strică una
// alteia numerele de comandă și stocul. `api/index.php:17` citește însă
// `GP_CONFIG` din mediu, așa că fiecare instanță primește configul ei.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import { spawn, spawnSync, execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const CASA = path.join(os.tmpdir(), 'gp-instante');
fs.mkdirSync(CASA, { recursive: true });

const MYSQL = process.env.GP_MYSQL
  || (fs.existsSync('C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe')
    ? 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe' : 'mysql');

function php() {
  if (process.env.GP_PHP) return process.env.GP_PHP;
  const g = 'C:/laragon/bin/php';
  try {
    const v = fs.readdirSync(g).find((d) => d.startsWith('php-'));
    if (v) return path.join(g, v, 'php.exe');
  } catch { /* alt sistem */ }
  return 'php';
}

const CFG = JSON.parse(execFileSync(php(), [
  '-r', 'echo json_encode(require $argv[1]);', '--', ROOT + '/api/config.php',
], { encoding: 'utf8' }));

const slug = (s) => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 24);
const stareaLui = (n) => path.join(CASA, slug(n) + '.json');

const argMysql = (db) => [
  '-h', CFG.db.host, '-P', String(CFG.db.port), '-u', CFG.db.user,
  ...(CFG.db.pass ? ['-p' + CFG.db.pass] : []),
  '--default-character-set=utf8mb4',
  ...(db ? [db] : []),
];

const mysql = (sql, db) => execFileSync(MYSQL, [...argMysql(db), '-e', sql],
  { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });

const importa = (fisier, db) => {
  const cale = path.join(ROOT, 'sql', fisier).replace(/\\/g, '/');
  execFileSync(MYSQL, [...argMysql(db), '-e', 'SOURCE ' + cale],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
};

async function portLiber(de = 8200) {
  for (let p = de; p < de + 300; p++) {
    const liber = await new Promise((res) => {
      const s = net.createServer();
      s.once('error', () => res(false));
      s.once('listening', () => s.close(() => res(true)));
      s.listen(p, '127.0.0.1');
    });
    if (liber) return p;
  }
  throw new Error('nu am găsit port liber');
}

// Singurul cont pus de-a gata: administratorul, exact cum îl are și `gp_dev`
// după instalarea locală. Așa, orice probă „live" existentă merge neschimbată
// pe o instanță izolată, doar cu GP_BASE schimbat. Angajatul și clientul și le
// creează probele singure, prin API — asta chiar vor să verifice.
//
// Parola e cea din instalarea locală de dezvoltare, scrisă și în
// docs/01-instalare-locala.md. Baza asta trăiește câteva minute pe 127.0.0.1
// și se șterge; nu are nicio legătură cu instalarea reală.
const CONTURI = [
  ['testadmin', 'Parola-Test-1234', 'Administrator Test', 'admin'],
];

async function porneste(nume) {
  const s = slug(nume);
  if (!s) throw new Error('dă-i un nume');
  const db = 'gp_i_' + s;
  if (db === CFG.db.name) throw new Error('numele s-ar ciocni cu baza din config');
  opreste(nume, true);

  mysql('DROP DATABASE IF EXISTS `' + db + '`; CREATE DATABASE `' + db
    + '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
  importa('001_schema.sql', db);
  importa('002_seed.sql', db);

  // Parolele trec prin password_hash, ca la instalare.
  const hash = JSON.parse(execFileSync(php(), [
    '-r', 'echo json_encode(array_map(fn($p) => password_hash($p, PASSWORD_BCRYPT), json_decode($argv[1], true)));',
    '--', JSON.stringify(CONTURI.map((c) => c[1])),
  ], { encoding: 'utf8' }));
  const randuri = CONTURI.map((c, i) =>
    "('" + c[0] + "','" + hash[i] + "','" + c[2] + "','" + c[3] + "',1,0,0,NOW())").join(',');
  mysql('INSERT INTO users (username,password_hash,name,role,active,is_system,'
    + 'must_change_password,password_changed_at) VALUES ' + randuri, db);

  const port = await portLiber();
  const cfg = JSON.parse(JSON.stringify(CFG));
  cfg.db.name = db;
  cfg.allowed_origins = [...(cfg.allowed_origins || []), 'http://127.0.0.1:' + port];
  const caleCfg = path.join(CASA, s + '.config.php');
  fs.writeFileSync(caleCfg, execFileSync(php(), [
    '-r', 'echo "<?php return " . var_export(json_decode($argv[1], true), true) . ";";',
    '--', JSON.stringify(cfg),
  ], { encoding: 'utf8' }), 'utf8');

  const jurnal = path.join(CASA, s + '.log');
  const out = fs.openSync(jurnal, 'w');
  const copil = spawn(php(),
    ['-S', '127.0.0.1:' + port, '-t', '.', '.claude/dev-server.php'],
    {
      cwd: ROOT,
      env: { ...process.env, GP_CONFIG: caleCfg },
      detached: true, windowsHide: true, stdio: ['ignore', out, out],
    });
  copil.unref();

  const base = 'http://127.0.0.1:' + port + '/api';
  let gata = false;
  for (let i = 0; i < 100 && !gata; i++) {
    try { gata = (await fetch(base + '/products')).status === 200; } catch { /* încă pornește */ }
    if (!gata) await new Promise((r) => setTimeout(r, 200));
  }
  if (!gata) {
    spawnSync('taskkill', ['/F', '/T', '/PID', String(copil.pid)], { stdio: 'ignore' });
    throw new Error('serverul nu a pornit; jurnal:\n' + fs.readFileSync(jurnal, 'utf8').slice(0, 900));
  }

  fs.writeFileSync(stareaLui(nume),
    JSON.stringify({ nume: s, db, port, pid: copil.pid, cfg: caleCfg, jurnal }), 'utf8');
  console.log(base);
  return base;
}

function opreste(nume, tacut = false) {
  const cale = stareaLui(nume);
  if (!fs.existsSync(cale)) { if (!tacut) console.log('nu rulează nimic cu numele ăsta'); return; }
  const st = JSON.parse(fs.readFileSync(cale, 'utf8'));
  if (st.pid) spawnSync('taskkill', ['/F', '/T', '/PID', String(st.pid)], { stdio: 'ignore' });
  try { mysql('DROP DATABASE IF EXISTS `' + st.db + '`'); } catch { /* deja ștearsă */ }
  fs.rmSync(st.cfg, { force: true });
  fs.rmSync(cale, { force: true });
  if (!tacut) console.log('oprit: ' + st.nume + ' (port ' + st.port + ', baza ' + st.db + ')');
}

function curata() {
  for (const f of fs.readdirSync(CASA).filter((f) => f.endsWith('.json'))) {
    opreste(f.replace(/\.json$/, ''));
  }
  const baze = mysql("SHOW DATABASES LIKE 'gp\\_i\\_%'").trim().split('\n').slice(1)
    .map((b) => b.trim()).filter(Boolean);
  for (const b of baze) mysql('DROP DATABASE IF EXISTS `' + b + '`');
  console.log('curat: ' + baze.length + ' baze rămase șterse');
}

const [, , cmd, nume] = process.argv;
if (cmd === 'porneste') await porneste(nume);
else if (cmd === 'opreste') opreste(nume);
else if (cmd === 'curata') curata();
else {
  console.log('folosire: node .claude/instanta.mjs porneste|opreste <nume>   |   curata');
  process.exitCode = 1;
}
