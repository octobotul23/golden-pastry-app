// Verifică faptul că meniul din bara laterală este același, în aceeași ordine,
// pe toate panourile.
//
// Scrisă după ce butoanele au început să sară sub deget: „Catalog" era al
// treilea în pagina Catalog și ultimul în Setări, „Jurnal" lipsea din patru
// pagini din șase, iar „Laborator" și „Rută" lipseau din Setări și din Catalog.
// Fiecare pagină își ținea propria copie a listei, deci orice intrare nouă
// ajungea unde nimerea — a mea, „Catalog", a fost lipită la coada fiecărei
// liste, în afară de pagina în care era deja scrisă de mână.
//
// Un meniu în care aceeași opțiune stă în alt loc pe fiecare pagină nu se
// învață niciodată: nu te poți baza pe memoria degetului, trebuie să citești
// lista de fiecare dată.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Ordinea așteptată, o singură dată, aici. */
const ORDINE = ['Comenzi', 'Laborator', 'Rută', 'Depozit', 'Catalog', 'Conturi', 'Jurnal', 'Setări', 'Parola mea'];

/** Pagina → intrarea care trebuie să fie marcată ca activă în ea. */
const PAGINI = {
  'Administrare Comenzi.dc.html': 'Comenzi',
  'Depozit.dc.html': 'Depozit',
  'Conturi.dc.html': 'Conturi',
  'Jurnal.dc.html': 'Jurnal',
  'Setari.dc.html': 'Setări',
  'Produse.dc.html': 'Catalog',
};

/** Unde duce fiecare intrare. O legătură greșită e la fel de rea ca una lipsă. */
const ADRESE = {
  Comenzi: 'Administrare%20Comenzi.dc.html',
  Laborator: 'Panou%20Laborator.dc.html',
  'Rută': 'Ruta%20Sofer.dc.html',
  Depozit: 'Depozit.dc.html',
  Catalog: 'Produse.dc.html',
  Conturi: 'Conturi.dc.html',
  Jurnal: 'Jurnal.dc.html',
  'Setări': 'Setari.dc.html',
  'Parola mea': 'Parola.dc.html',
};

/** Intrările din meniu, în ordinea din pagină. */
function citesteMeniu(html, fisier) {
  const cap = html.match(/<div data-[a-z]+="nav" style="[^"]*">\n/);
  if (!cap) throw new Error(fisier + ': nu are meniu în bara laterală');

  const start = cap.index + cap[0].length;
  const stop = html.indexOf('</div>', start);
  if (stop < 0) throw new Error(fisier + ': meniul nu se închide');

  return html.slice(start, stop).trim().split('\n').filter(Boolean).map((rand) => {
    const activ = rand.startsWith('<span');
    const href = (rand.match(/href="([^"]*)"/) || [])[1] || null;
    const et = (rand.match(/>([^<>]+)<\/(?:a|span)>/) || [])[1] || '?';
    return { et, href, activ };
  });
}

let probleme = 0;

for (const [fisier, activAsteptat] of Object.entries(PAGINI)) {
  const cale = path.join(ROOT, fisier);
  if (!fs.existsSync(cale)) {
    console.log(`  LIPSEȘTE ${fisier}`);
    probleme++;
    continue;
  }

  const intrari = citesteMeniu(fs.readFileSync(cale, 'utf8'), fisier);
  const gasite = [];

  const etichete = intrari.map((x) => x.et);
  if (etichete.join('|') !== ORDINE.join('|')) {
    gasite.push(`ordinea diferă\n           are:    ${etichete.join(' · ')}\n           trebuie: ${ORDINE.join(' · ')}`);
  }

  const active = intrari.filter((x) => x.activ);
  if (active.length !== 1) {
    gasite.push(`${active.length} intrări marcate ca activă, trebuie exact una`);
  } else if (active[0].et !== activAsteptat) {
    gasite.push(`activă e „${active[0].et}”, trebuie „${activAsteptat}”`);
  }

  for (const x of intrari) {
    if (x.activ) continue;
    if (x.href !== ADRESE[x.et]) {
      gasite.push(`„${x.et}” duce la ${x.href || '(nicăieri)'}, trebuie la ${ADRESE[x.et]}`);
    }
  }

  if (gasite.length) {
    probleme += gasite.length;
    console.log(`  ATENȚIE ${fisier}`);
    for (const g of gasite) console.log(`         · ${g}`);
  } else {
    console.log(`  ok    ${fisier}`);
  }
}

const n = Object.keys(PAGINI).length;
console.log(
  probleme
    ? `\n${n} panouri | ${probleme} ${probleme === 1 ? "nepotrivire" : "nepotriviri"} în meniu`
    : `\n${n} panouri | meniu identic: ${ORDINE.join(' · ')}`
);
process.exit(probleme ? 1 : 0);
