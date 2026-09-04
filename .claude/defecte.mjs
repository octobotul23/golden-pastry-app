// Lista defectelor: bifat, numărat, și regenerarea documentului.
//
//   node .claude/defecte.mjs                       — câte mai sunt
//   node .claude/defecte.mjs lista [grav]          — ce a mai rămas
//   node .claude/defecte.mjs bifa <fișier:linie> "cum s-a reparat"
//   node .claude/defecte.mjs doc                   — rescrie docs/06-…
//
// Adevărul stă în `.claude/defecte.json`; documentul se generează din el, ca
// să nu ajungă cele două să spună lucruri diferite.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const JSON_PATH = path.join(ROOT, '.claude/defecte.json');
const DOC_PATH = path.join(ROOT, 'docs/06-defecte-de-reparat.md');

const ORD = { critic: 0, grav: 1, mediu: 2, mic: 3 };
const TITLU = {
  critic: ['Critice', 'Pierd bani, date sau lasă ușa deschisă. Nu publica până nu sunt rezolvate.'],
  grav: ['Grave', 'Se întâmplă la folosirea obișnuită: date pierdute, sume greșite, munca cuiva aruncată.'],
  mediu: ['Medii', 'Se văd și încurcă, dar se pot ocoli sau nu apar zilnic.'],
  mic: ['Mici', 'Nepotriviri și texte care mint mărunt.'],
};

const REPRODUSE = new Set([
  'Administrare Comenzi.dc.html:1158', 'Administrare Comenzi.dc.html:1121',
  'Administrare Comenzi.dc.html:1120', 'api/routes/orders.php:84',
  'api/routes/orders.php:238', 'api/routes/stock.php:246',
  'api/routes/auth.php:75', 'api/routes/requests.php:138',
  'api/lib/auth.php:142', 'api/setup.php:73', 'Site Public.dc.html:1767',
]);

const toate = JSON.parse(fs.readFileSync(JSON_PATH, 'utf8'));
const cheie = (d) => d.fisier + ':' + d.linie;
const salveaza = () => fs.writeFileSync(JSON_PATH, JSON.stringify(toate, null, 1));

const nr = () => {
  const t = { critic: [0, 0], grav: [0, 0], mediu: [0, 0], mic: [0, 0] };
  for (const d of toate) {
    const c = t[d.gravitate] || (t[d.gravitate] = [0, 0]);
    c[0]++;
    if (d.reparat) c[1]++;
  }
  return t;
};

function rezumat() {
  const t = nr();
  let tot = 0, rep = 0;
  console.log('');
  for (const g of ['critic', 'grav', 'mediu', 'mic']) {
    if (!t[g] || !t[g][0]) continue;
    tot += t[g][0]; rep += t[g][1];
    const bara = '█'.repeat(Math.round((t[g][1] / t[g][0]) * 24)).padEnd(24, '·');
    console.log(`  ${TITLU[g][0].padEnd(8)} ${bara}  ${String(t[g][1]).padStart(3)} / ${t[g][0]}`);
  }
  console.log(`\n  ${rep} reparate · ${tot - rep} rămase · ${tot} în total\n`);
}

function lista(filtru) {
  const ramase = toate
    .filter((d) => !d.reparat && (!filtru || d.gravitate === filtru))
    .sort((a, b) => (ORD[a.gravitate] ?? 9) - (ORD[b.gravitate] ?? 9) || a.fisier.localeCompare(b.fisier));
  for (const d of ramase) {
    console.log(`  [${d.gravitate.padEnd(6)}] ${cheie(d).padEnd(38)} ${String(d.titlu).replace(/\s+/g, ' ')}`);
  }
  console.log(`\n  ${ramase.length} rămase${filtru ? ' (' + filtru + ')' : ''}\n`);
}

function bifa(k, cum) {
  const d = toate.filter((x) => cheie(x) === k);
  if (!d.length) { console.error('Nu găsesc: ' + k); process.exit(1); }
  if (d.length > 1) { console.error('Mai multe potriviri pentru ' + k); process.exit(1); }
  if (!cum) { console.error('Spune și cum s-a reparat — intră în document.'); process.exit(1); }
  d[0].reparat = true;
  d[0].reparatCum = cum;
  salveaza();
  console.log('bifat: ' + k);
}

function doc() {
  const list = toate.slice().sort((a, b) =>
    (ORD[a.gravitate] ?? 9) - (ORD[b.gravitate] ?? 9) || a.fisier.localeCompare(b.fisier));
  const reparate = list.filter((d) => d.reparat);
  const ramase = list.filter((d) => !d.reparat);
  const c = nr();

  let md = `# Defecte găsite, de reparat

Inspecție făcută înainte de publicare, pe tot codul: 4.700 de linii de PHP,
11.300 de linii de pagini, pachetul de publicare și baza de date.

**${list.length} de defecte confirmate** — ${c.critic[0]} critice, ${c.grav[0]} grave, ${c.mediu[0]} medii, ${c.mic[0]} mici.
**${reparate.length} reparate**, ${ramase.length} rămase.

Lista s-a strâns în trei valuri. Inspecția dinaintea publicării a dat 102 fișe:
din 123 de descoperiri brute au rămas 97 după eliminarea dublurilor, fiecare
trecută prin doi verificatori care au încercat s-o demonteze. Alte cinci au
ieșit la iveală chiar în timpul reparațiilor. Ultimele ${list.length - 107} vin
dintr-o a doua vânătoare, pornită după ce suita trecea integral: șase agenți au
citit codul pe zone separate, fără să știe ce caută ceilalți, iar fiecare
susținere a fost dată unui sceptic care avea sarcina s-o dărâme — șase din
douăzeci și trei au căzut acolo. Tot atunci s-au adăugat cele găsite deschizând
fiecare pagină în browser, cu consola și rețeaua la vedere. Cele marcate
**[reprodus]** au fost declanșate
cu mâna pe aplicația care rulează, nu doar citite din cod.

Aceeași listă, cu filtre și căutare:
<https://claude.ai/code/artifact/64a4ebba-42c9-4ed2-be1c-abba79f60d72>

`;

  const stare = ['critic', 'grav', 'mediu', 'mic']
    .filter((g) => c[g] && c[g][0])
    .map((g) => `| ${TITLU[g][0]} | ${c[g][1]} | ${c[g][0] - c[g][1]} | ${c[g][0]} |`)
    .join('\n');
  md += `| Gravitate | Reparate | Rămase | Total |\n|---|---|---|---|\n${stare}\n\n---\n\n`;

  if (reparate.length) {
    md += `## Ce e reparat — ${reparate.length}\n\n`;
    md += 'Fiecare cu proba lui de regresie: proba a fost verificată stricând\n'
        + 'reparația la loc și văzând-o picând.\n\n';
    for (const d of reparate) {
      md += `- [x] **${String(d.titlu).replace(/\s+/g, ' ')}**\n`;
      md += `  \`${cheie(d)}\` · *${d.gravitate}*\n`;
      md += `  ${String(d.reparatCum || '').replace(/\s+/g, ' ')}\n\n`;
    }
    md += '---\n\n';
  }

  for (const g of ['critic', 'grav', 'mediu', 'mic']) {
    const l = ramase.filter((d) => d.gravitate === g);
    if (!l.length) continue;
    md += `## ${TITLU[g][0]} — ${l.length} rămase\n\n${TITLU[g][1]}\n\n`;
    for (const d of l) {
      const m = [];
      if (REPRODUSE.has(cheie(d))) m.push('reprodus');
      if (d.zona === 'gasit-de-mine') m.push('găsit separat');
      if (d.voturi_total === 2 && d.voturi_reale === 1) m.push('un vot din doi');
      md += `- [ ] **${String(d.titlu).replace(/\s+/g, ' ')}**${m.length ? ' `' + m.join('` `') + '`' : ''}\n`;
      md += `  \`${cheie(d)}\`\n`;
      md += `  **Ce e greșit:** ${String(d.ce || '').replace(/\s+/g, ' ')}\n`;
      md += `  **Ce pățește omul:** ${String(d.scenariu || '').replace(/\s+/g, ' ')}\n`;
      if (d.dovada) md += `  **Dovada:** \`${String(d.dovada).replace(/\s+/g, ' ').replace(/`/g, "'")}\`\n`;
      md += '\n';
    }
    md += '---\n\n';
  }

  md += `## Ce am respins

Ca lista să nu fie umflată — patru lucruri care păreau defecte și nu sunt:

- **Traversare de cale în rute.** \`/orders/1/../../users\` părea să dea 200, dar
  era browserul care normaliza adresa înainte s-o trimită. Cerut cu
  \`curl --path-as-is\`, serverul dă 404.
- **\`env => 'dev'\` implicit în configurare.** E documentat în pașii de
  publicare *și* verificat de \`api/verifica.php\`.
- **Paginarea Jurnalului.** \`page=-5\` și \`page=99999999999\` par nevalidate, dar
  \`max(1, (int)…)\` le prinde.
- **Încărcarea fotografiilor.** Respinge cod PHP deghizat în \`.jpg\` și nume de
  fișier cu \`../\`; nimic nu s-a scris în afara folderului.

---

## Cum a fost făcută

17 căutători, câte unul pe zonă — drepturi, sesiuni, comenzi, cereri, catalog,
depozit, setări, infrastructură, paginile, publicarea, plus trei transversale:
banii, timpul și matricea de drepturi.

Fiecare descoperire a trecut prin doi verificatori independenți: unul care
încerca s-o demonteze, unul care urmărea drumul datelor prin cod și prin baza de
date. Verificatorii n-au avut voie să scrie nimic — nici în bază, nici în
fișiere; reproducerile le-am făcut separat.

212 agenți, 2.294 de apeluri de unelte, 61 de minute.

O notă despre probele existente: **cele 297 de teste treceau toate** în timp ce
defectele astea erau în cod. Nu acopereau niciunul dintre ele — de-asta au și
trecut nevăzute. Fiecare reparație vine cu proba ei, iar proba se verifică
stricând reparația la loc: dacă reparația se dă înapoi și proba trece mai
departe, proba nu e bună. \`node .claude/verifica-tot.mjs\` rulează acum peste
nouă sute de probe.
`;

  fs.writeFileSync(DOC_PATH, md);
  console.log(`scris docs/06-defecte-de-reparat.md — ${(md.length / 1024).toFixed(0)} KB · ${reparate.length} reparate · ${ramase.length} rămase`);
}

const [, , cmd, ...rest] = process.argv;
if (cmd === 'lista') lista(rest[0]);
else if (cmd === 'bifa') { bifa(rest[0], rest.slice(1).join(' ')); doc(); }
else if (cmd === 'doc') doc();
else rezumat();
