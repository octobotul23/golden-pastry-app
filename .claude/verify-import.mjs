// Verify an imported Claude Design project against the expected file manifest.
//
// The design API caps get_file at 256 KiB, which silently truncates large
// binaries. A truncated PNG/JPEG/WOFF2 still writes to disk and still "exists",
// so a presence check alone is not enough -- each binary is structurally
// validated (magic bytes + terminator + declared length) to catch that.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const expected = JSON.parse(fs.readFileSync(path.join(HERE, 'expected-files.json'), 'utf8'));

const CAP = 262144; // 256 KiB API cap
const SUSPICIOUS = new Set([CAP, 196608]);

const ok = (info = '') => ({ good: true, info });
const bad = (why) => ({ good: false, info: why });

function checkPng(b) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  if (b.length < 33 || !b.subarray(0, 8).equals(sig)) return bad('not a PNG (bad signature)');
  const dims = `${b.readUInt32BE(16)}x${b.readUInt32BE(20)}`;
  // IEND is the mandatory final chunk: 4-byte length, "IEND", 4-byte CRC.
  if (b.subarray(b.length - 8, b.length - 4).toString('latin1') !== 'IEND') {
    return bad(`TRUNCATED - no IEND terminator (${dims})`);
  }
  return ok(dims);
}

function checkJpeg(b) {
  if (b.length < 4 || b[0] !== 0xff || b[1] !== 0xd8 || b[2] !== 0xff) {
    return bad('not a JPEG (bad SOI marker)');
  }
  if (b[b.length - 2] !== 0xff || b[b.length - 1] !== 0xd9) {
    return bad('TRUNCATED - no EOI marker');
  }
  // Walk segments to the frame header for dimensions.
  let i = 2;
  while (i < b.length - 1) {
    if (b[i] !== 0xff) break;
    const m = b[i + 1];
    if (m >= 0xc0 && m <= 0xcf && m !== 0xc4 && m !== 0xc8 && m !== 0xcc) {
      return ok(`${b.readUInt16BE(i + 7)}x${b.readUInt16BE(i + 5)}`);
    }
    if (m === 0xd8 || (m >= 0xd0 && m <= 0xd9)) { i += 2; continue; }
    i += 2 + b.readUInt16BE(i + 2);
  }
  return ok('');
}

function checkWoff2(b) {
  if (b.length < 12 || b.subarray(0, 4).toString('latin1') !== 'wOF2') {
    return bad('not a WOFF2 (bad signature)');
  }
  // Header field at offset 8 is the total file length -- a direct truncation check.
  const declared = b.readUInt32BE(8);
  if (declared !== b.length) {
    return bad(`TRUNCATED - header declares ${declared}B, file is ${b.length}B`);
  }
  return ok('');
}

function checkText(b, rel) {
  if (b.length === 0) return bad('EMPTY file');
  const s = b.toString('utf8');
  if (rel.endsWith('.json')) {
    try { JSON.parse(s); } catch (e) { return bad('invalid JSON: ' + e.message); }
  }
  if (rel.endsWith('.dc.html') && !/<\/(html|body|div|section|main)>\s*$/i.test(s.trimEnd())) {
    return bad('suspicious tail - may be truncated');
  }
  return ok('');
}

// Dispatch on actual magic bytes, not the extension: this project ships at least
// one JPEG named .png, and trusting the extension reports it as corrupt.
function sniff(b) {
  if (b.length >= 8 && b.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) return 'png';
  if (b.length >= 3 && b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) return 'jpeg';
  if (b.length >= 4 && b.subarray(0, 4).toString('latin1') === 'wOF2') return 'woff2';
  return null;
}
const BY_FORMAT = { png: checkPng, jpeg: checkJpeg, woff2: checkWoff2 };
const EXT_FORMAT = { '.png': 'png', '.jpg': 'jpeg', '.jpeg': 'jpeg', '.woff2': 'woff2' };

const missing = [], broken = [], flagged = [], good = [], mislabelled = [];

for (const rel of expected) {
  const abs = path.join(ROOT, rel);
  let b;
  try { b = fs.readFileSync(abs); } catch { missing.push(rel); continue; }
  const ext = path.extname(rel).toLowerCase();
  const format = sniff(b);
  const r = format ? BY_FORMAT[format](b) : checkText(b, rel);
  if (format && EXT_FORMAT[ext] && EXT_FORMAT[ext] !== format) {
    mislabelled.push(`${rel} -- actually a ${format.toUpperCase()}, not ${ext}`);
  }
  const line = `${rel} (${b.length}B${r.info ? ', ' + r.info : ''})`;
  if (!r.good) broken.push(`${rel} -- ${r.info} [${b.length}B]`);
  else {
    good.push(line);
    if (SUSPICIOUS.has(b.length) || b.length === CAP) {
      flagged.push(`${rel} -- size is exactly ${b.length}B, at a known truncation boundary`);
    }
  }
}

// Anything on disk that the manifest does not list.
const onDisk = [];
(function walk(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.name === '.claude' || e.name === '.git' || e.name === 'node_modules') continue;
    const abs = path.join(dir, e.name);
    if (e.isDirectory()) walk(abs);
    else onDisk.push(path.relative(ROOT, abs).split(path.sep).join('/'));
  }
})(ROOT);
const extra = onDisk.filter((f) => !expected.includes(f));

const R = '\x1b[31m', G = '\x1b[32m', Y = '\x1b[33m', D = '\x1b[2m', X = '\x1b[0m';
console.log(`\n${expected.length} expected | ${G}${good.length} ok${X} | ${R}${missing.length} missing${X} | ${R}${broken.length} broken${X}`);

if (missing.length) {
  console.log(`\n${R}MISSING (${missing.length})${X}`);
  for (const f of missing) console.log('  - ' + f);
}
if (broken.length) {
  console.log(`\n${R}BROKEN / TRUNCATED (${broken.length})${X}`);
  for (const f of broken) console.log('  x ' + f);
}
if (flagged.length) {
  console.log(`\n${Y}SIZE-FLAGGED (${flagged.length}) - structurally valid, verify visually${X}`);
  for (const f of flagged) console.log('  ! ' + f);
}
if (mislabelled.length) {
  console.log(`
${Y}EXTENSION MISMATCH (${mislabelled.length}) - content is valid, extension is misleading${X}`);
  for (const f of mislabelled) console.log('  ~ ' + f);
}
if (extra.length) {
  console.log(`\n${D}NOT IN MANIFEST (${extra.length})${X}`);
  for (const f of extra) console.log('  ? ' + f);
}
if (!missing.length && !broken.length) {
  console.log(`\n${G}All ${expected.length} files present and structurally valid.${X}`);
}
console.log();
process.exit(missing.length || broken.length ? 1 : 0);
