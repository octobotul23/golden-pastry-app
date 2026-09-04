// Cantitățile zecimale, tăiate de `parseInt` în toate paginile.
//
// Baza ține cantitățile în DECIMAL(10,2) și formularul din panou le acceptă
// cu zecimale de la bun început (`citesteCantitate` din Administrare Comenzi
// le permite explicit). Paginile le citeau însă cu `parseInt(i.q, 10) || 0`:
// o jumătate de tavă, 0,5, devenea 0. Pe lista de făcut a laboratorului
// poziția dispărea cu totul din totalul zilei, iar brutarul afla de bucata
// lipsă abia la ambalare.
//
// Proba nu se uită la comentarii: scoate liniile din fișiere și le rulează.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
let trecute = 0, esuate = 0;
const check = (nume, ok, detaliu = '') => {
  if (ok) { trecute++; console.log('  ✓ ' + nume); }
  else { esuate++; console.log('  ✗ ' + nume + (detaliu ? '\n      ' + detaliu : '')); }
};

const citeste = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');

/* ── A. Ajutoarele din gp-api.js, rulate ─────────────────────────────────── */
console.log('\n=== gp.qty / gp.qtyLabel: cifrele de după virgulă rămân ===');
let gp = null;
{
  const src = citeste('shared/gp-api.js');
  const scoate = (nume) => {
    const i = src.indexOf('\n    ' + nume + '(v) {');
    if (i < 0) return null;
    const j = src.indexOf('\n    },', i);
    return j < 0 ? null : src.slice(i + 1, j + 1) + '\n    },';
  };
  const qty = scoate('qty');
  const qtyLabel = scoate('qtyLabel');
  check('shared/gp-api.js: gp.qty există', !!qty);
  check('shared/gp-api.js: gp.qtyLabel există', !!qtyLabel);
  if (qty && qtyLabel) {
    try {
      // Le punem într-un obiect numit `gp`, fiindcă qtyLabel îl cheamă pe qty
      // prin numele lui global — exact cum face în pagină.
      gp = new Function('const gp = {\n' + qty + '\n' + qtyLabel + '\n};\nreturn gp;')();
    } catch (e) { check('ajutoarele se pot rula', false, String(e)); }
  }
}
if (gp) {
  const q = [
    ['0.5 rămâne 0.5', gp.qty('0.5'), 0.5],
    ['„0,5" cu virgulă românească rămâne 0.5', gp.qty('0,5'), 0.5],
    ['numărul 0.5 (nu textul) rămâne 0.5', gp.qty(0.5), 0.5],
    ['12 rămâne 12', gp.qty('12'), 12],
    ['12.25 rămâne 12.25', gp.qty('12.25'), 12.25],
    ['câmpul gol e 0', gp.qty(''), 0],
    ['null e 0', gp.qty(null), 0],
    ['nedefinit e 0', gp.qty(undefined), 0],
    ['textul „două" e 0, nu NaN', gp.qty('două'), 0],
  ];
  for (const [nume, a, b] of q) {
    check('gp.qty: ' + nume, a === b, 'a ieșit: ' + JSON.stringify(a) + ', se aștepta ' + b);
  }

  const e = [
    ['întregul se scrie fără zecimale', gp.qtyLabel(10), '10'],
    ['jumătatea se scrie cu virgulă', gp.qtyLabel(0.5), '0,5'],
    ['12,25 la fel', gp.qtyLabel(12.25), '12,25'],
    ['0,1 + 0,2 nu iese „0.30000000000000004"', gp.qtyLabel(0.1 + 0.2), '0,3'],
    ['suma unor zecimale rămâne curată', gp.qtyLabel(0.5 + 0.5 + 0.5), '1,5'],
    ['zero rămâne „0"', gp.qtyLabel(0), '0'],
  ];
  for (const [nume, a, b] of e) {
    check('gp.qtyLabel: ' + nume, a === b, 'a ieșit: ' + JSON.stringify(a) + ', se aștepta ' + JSON.stringify(b));
  }
}

/* ── B. Totalul zilei din Panoul Laborator, rulat pe linia din fișier ────── */
console.log('\n=== Panou Laborator: totalul zilei nu mai pierde jumătățile ===');
{
  const src = citeste('Panou Laborator.dc.html');

  const mPieces = src.match(/^\s*const pieces = base\.reduce\((.+)\);\s*$/m);
  check('linia care adună bucățile zilei există', !!mPieces);
  if (mPieces && gp) {
    const calc = new Function('base', 'gp', 'const pieces = base.reduce(' + mPieces[1] + ');\nreturn pieces;');
    const comenzi = [
      { items: [{ q: '0.5', n: 'Tavă cu mere' }, { q: '2', n: 'Croasant' }] },
      { items: [{ q: '0.5', n: 'Tavă cu mere' }] },
    ];
    const out = calc(comenzi, gp);
    check('o comandă de 0,5 + 2 și una de 0,5 dau 3, nu 2',
      Math.abs(out - 3) < 1e-9, 'a ieșit: ' + out);
  }

  const mTot = src.match(/^\s*tot\[i\.n\] = (.+);\s*$/m);
  check('linia care adună pe produs există', !!mTot);
  if (mTot && gp) {
    const calc = new Function('tot', 'i', 'gp', 'tot[i.n] = ' + mTot[1] + ';\nreturn tot;');
    let tot = {};
    tot = calc(tot, { n: 'Tavă cu mere', q: '0.5' }, gp);
    tot = calc(tot, { n: 'Tavă cu mere', q: '0.5' }, gp);
    check('două jumătăți de tavă fac o tavă pe lista de făcut',
      Math.abs(tot['Tavă cu mere'] - 1) < 1e-9,
      'a ieșit: ' + JSON.stringify(tot));
  }
}

/* ── C. Nicăieri nu mai rămâne un parseInt pe cantități ──────────────────── */
console.log('\n=== nicio pagină nu mai taie cantitatea cu parseInt ===');
{
  const pagini = fs.readdirSync(ROOT).filter((f) => f.endsWith('.dc.html'));
  const rele = [];
  for (const f of pagini) {
    const linii = citeste(f).split('\n');
    linii.forEach((l, i) => {
      // Comentariile explică tocmai defectul reparat; ne uităm doar la cod.
      const cod = l.replace(/^\s*(\/\/|\*|\/\*).*$/, '');
      if (/parseInt\s*\(\s*[a-z]+\.(q|qty)\b/.test(cod)) rele.push(f + ':' + (i + 1) + '  ' + l.trim());
    });
  }
  check('nicio cantitate nu mai trece prin parseInt', rele.length === 0, rele.join('\n      '));

  // Și invers: ajutorul chiar e folosit acolo unde erau tăierile.
  for (const [f, cate] of [
    ['Administrare Comenzi.dc.html', 4],
    ['Panou Laborator.dc.html', 2],
    ['Client.dc.html', 1],
    ['Intrare.dc.html', 1],
    ['Ruta Sofer.dc.html', 1],
  ]) {
    const n = (citeste(f).match(/gp\.qty\(/g) || []).length;
    check(f + ': cantitățile trec prin gp.qty (cel puțin ' + cate + ' locuri)',
      n >= cate, 'găsite: ' + n);
  }
}

console.log('\n  ' + trecute + ' trecute · ' + esuate + ' eșuate');
process.exitCode = esuate ? 1 : 0;
