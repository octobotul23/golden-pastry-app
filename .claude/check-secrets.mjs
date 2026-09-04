// Caută parole și tokenuri rămase în sursele care ajung în browser.
//
// Verificarea de dinainte se uita doar după tipare de cod (`p: "parola"`), așa
// că a ratat două locuri reale: un paragraf de ajutor care scria parolele
// demo în text („admin / goldenpastry"), și un buton care încerca să trimită
// parola pe e-mail. Ambele erau vizibile oricui deschidea pagina.
//
// A doua încercare căuta cuvintele oriunde și a raportat 32 de potriviri, din
// care 31 erau „patiserie" și „Belvedere" — meseria firmei și numele unui
// client. O verificare pe care n-o citește nimeni nu apără nimic, deci acum
// un cuvânt cunoscut numără doar dacă apare LÂNGĂ un cuvânt despre acreditări.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// Fișierele care ajung la utilizator. `api/config.php` conține prin definiție
// parola bazei de date, dar nu pleacă niciodată către browser.
const files = [
  ...fs.readdirSync(ROOT).filter((f) => f.endsWith('.dc.html')),
  ...fs.readdirSync(path.join(ROOT, 'shared')).map((f) => 'shared/' + f),
];

// Parolele care existau în prototip, în clar. Dacă vreuna reapare lângă un
// cuvânt despre acreditări, e o regresie.
const KNOWN = /\b(goldenpastry|patiserie|client123|parola123|admin123|123456)\b/i;
const CREDENTIAL_CONTEXT = /\b(parol|password|utilizator|username|user\b|autentific|login|cont de|acreditar)/i;

const PATTERNS = [
  { re: /\bp\s*:\s*["'][^"']{3,}["']/, why: 'câmp „p" cu valoare literală (parolă în cod)' },
  { re: /\b(password|passwd|pwd)\s*[:=]\s*["'][^"']{3,}["']/i, why: 'parolă cu valoare literală' },
  { re: /<strong>\s*[a-z0-9._-]{4,}\s*<\/strong>\s*\/\s*<strong>/i, why: 'pereche utilizator / parolă scrisă în pagină' },
  { re: /\bBearer\s+[A-Za-z0-9._-]{16,}/, why: 'token de acces literal' },
  { re: /\bEAA[A-Za-z0-9]{20,}/, why: 'token Meta/Facebook literal' },
  { re: /\b\d{6,}:[A-Za-z0-9_-]{30,}/, why: 'token de bot Telegram literal' },
  // Codul tabletelor deschide o sesiune de angajat dintr-o singură cerere.
  // Scris ca literal într-o pagină, e public: paginile din `lab/` se servesc
  // ca fișiere statice, fără autentificare pe server.
  { re: /\b(DEFAULT_CODE|PANEL_CODE|panel_?code|cod_?panou)\b\s*[:=]\s*["'][^"']{3,}["']/i, why: 'codul tabletelor scris în clar' },
  // Valoarea fixă de odinioară. Nu are nevoie de context: nu e un cuvânt care
  // să apară din întâmplare, cum se întâmplă cu „patiserie" sau „Belvedere".
  { re: /\blab123\b/i, why: 'codul implicit al tabletelor, scos din proiect' },
];

// Rândurile care explică tocmai că parolele au fost scoase nu sunt o problemă.
const EXPLANATION = /varianta veche|prototip|nu mai|au fost|hash|bcrypt|criptat|nu poate fi|nimeni|niciodată/i;

let hits = 0;
for (const rel of files) {
  const lines = fs.readFileSync(path.join(ROOT, rel), 'utf8').split('\n');
  const found = [];

  lines.forEach((line, i) => {
    if (EXPLANATION.test(line)) return;

    for (const { re, why } of PATTERNS) {
      const m = re.exec(line);
      if (m) found.push({ n: i + 1, why, text: m[0].slice(0, 70) });
    }

    // Cuvânt cunoscut ȘI context de acreditare, pe aceeași linie.
    const clean = line.replace(/goldenpastry\.ro|Golden Pastry|golden-pastry/gi, '');
    if (KNOWN.test(clean) && CREDENTIAL_CONTEXT.test(clean)) {
      found.push({ n: i + 1, why: 'parolă din prototip lângă un text despre conturi', text: line.trim().slice(0, 70) });
    }
  });

  if (found.length) {
    hits += found.length;
    console.log(`  ATENȚIE ${rel}`);
    for (const f of found) console.log(`         · linia ${f.n}: ${f.why}\n           ${f.text}`);
  } else {
    console.log(`  ok      ${rel}`);
  }
}

// Instalatorul nu e o pagină de browser, dar el hotărăște primul secret al
// aplicației: codul cu care se deschid tabletele. Cât timp scria o valoare
// fixă, codul era public prin definiție — îl știa oricine citise proiectul sau
// documentația, fără să aibă nevoie de sursa vreunei pagini.
{
  const src = fs.readFileSync(path.join(ROOT, 'api/setup.php'), 'utf8');
  const generat = /random_panel_code\s*\(/.test(src);
  const fix = /encrypt_value\s*\(\s*['"][^'"]+['"]\s*\)/.exec(src);

  if (!generat || fix) {
    hits++;
    console.log('  ATENȚIE api/setup.php');
    if (!generat) console.log('         · codul tabletelor nu se mai generează (lipsește random_panel_code)');
    if (fix) console.log(`         · secret fix scris la instalare\n           ${fix[0].slice(0, 70)}`);
  } else {
    console.log('  ok      api/setup.php');
  }
}

// Paginile de instalare pleacă în pachetul de publicare fiindcă omul are nevoie
// de ele la prima instalare: pasul 5 din docs/03-publicare-cpanel.md deschide
// verifica.php, pasul 6 deschide setup.php. Amândouă cer să fie șterse după
// aceea și amândouă rămân, în practică, pe un domeniu public. Ce le ține
// închise e un zăvor din cod, pe care nu-l vede nimeni la o rulare obișnuită:
// 404 din clipa în care instalarea are un administrator activ, plus codul din
// config.php care ține setup.php închis până atunci. Scos din greșeală, ar
// pleca în pachet fără să bage nimeni de seamă.
{
  const ZAVOARE = [
    ['api/setup.php (zăvor)', 'api/setup.php', [
      ['http_response_code(404)', 'nu mai răspunde 404 după ce există un administrator'],
      ["config('install_code'", 'nu mai cere codul de instalare din api/config.php'],
    ]],
    ['api/verifica.php (zăvor)', 'api/verifica.php', [
      ['http_response_code(404)', 'rămâne deschisă și după instalare: spune ce versiuni, ce extensii și ce tabele rulezi'],
    ]],
    // Fără plasa din build, zăvorul poate dispărea dintr-o editare de mâine și
    // pachetul ar pleca liniștit cu instalatorul deschis.
    ['.claude/build-deploy.mjs (plasa din pachet)', '.claude/build-deploy.mjs', [
      ['ZAVOARE', 'pachetul nu mai verifică zăvorul paginilor de instalare'],
      ['api/verifica.php', 'verifica.php nu mai e pe lista verificată la publicare'],
      ['api/setup.php', 'setup.php nu mai e pe lista verificată la publicare'],
    ]],
  ];

  for (const [eticheta, rel, semne] of ZAVOARE) {
    const src = fs.readFileSync(path.join(ROOT, rel), 'utf8');
    const lipsa = semne.filter(([s]) => !src.includes(s));
    if (lipsa.length) {
      hits += lipsa.length;
      console.log(`  ATENȚIE ${eticheta}`);
      for (const [s, why] of lipsa) console.log(`         · ${why}\n           lipsește: ${s}`);
    } else {
      console.log(`  ok      ${eticheta}`);
    }
  }
}

console.log(`\n${files.length + 4} fișiere | ${hits} ${hits === 1 ? 'potrivire' : 'potriviri'}\n`);
process.exit(hits ? 1 : 0);
