// Două surse de 404 pe pagina de start, ambele tăcute pentru cine se uită doar
// la ecran: pagina arată bine, dar fiecare vizitator lasă erori roșii în
// consolă și rânduri de 404 în jurnalul găzduirii.
//
//  A. <image-slot src="{{ ceva }}"> — elementul se randează o dată înainte ca
//     runtime-ul „dc" să înlocuiască legăturile, deci cere de la server chiar
//     textul cu acolade (Site Public.dc.html:213, 221, 361, 403).
//  B. Un produs adăugat din Catalog fără poză primea o cale ghicită după
//     numărul lui de ordine — o cale care nu există niciodată pe server.
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

// ---- A ----
console.log('\n=== image-slot: legătura nerezolvată nu se cere de la server ===');
{
  const linii = fs.readFileSync(path.join(ROOT, 'image-slot.js'), 'utf8').split('\n');
  const i = linii.findIndex((l) => /^\s*const srcAttr\s*=/.test(l));
  check('image-slot.js: linia care citește atributul src există', i >= 0);
  if (i >= 0) {
    // Luăm linia și, dacă e, cea de deasupra (pregătirea valorii brute).
    const bloc = linii.slice(/^\s*const srcBrut\s*=/.test(linii[i - 1] || '') ? i - 1 : i, i + 1).join('\n');
    let citeste;
    try {
      citeste = new Function('valoare',
        'const self = { getAttribute: () => valoare };\n' +
        bloc.replace(/\bthis\b/g, 'self') + '\nreturn srcAttr;');
    } catch (e) { check('blocul se poate rula', false, String(e)); }
    if (citeste) {
      check('„{{ pr.photo }}" e tratat ca adresă lipsă, nu cerut de la server',
        citeste('{{ pr.photo }}') === '', 'a ieșit: ' + JSON.stringify(citeste('{{ pr.photo }}')));
      check('„{{ photoRange1 }}" la fel',
        citeste('{{ photoRange1 }}') === '');
      check('o adresă adevărată trece neatinsă',
        citeste('products/prod-3.jpg') === 'products/prod-3.jpg');
      check('o adresă data: trece neatinsă',
        citeste('data:image/webp;base64,AAAA') === 'data:image/webp;base64,AAAA');
      check('lipsa atributului rămâne șir gol', citeste(null) === '');
    }
  }
}

// ---- B ----
console.log('\n=== pagina de start: produsul fără poză nu cere o cale inventată ===');
{
  const text = fs.readFileSync(path.join(ROOT, 'Site Public.dc.html'), 'utf8');
  const m = text.match(/^\s*photo: (.+),\s*$/m);
  check('Site Public.dc.html: linia „photo:" din lista de produse există', !!m);
  if (m) {
    const res = (_id, url) => url;               // în producție window.__resources lipsește
    const calc = new Function('r', 'res', 'return (' + m[1] + ');');
    const cuPoza = ['Congelate', 5, 'Croasant', 'Croissant', 80, 3, 4, '', '', 0, 'products/foto-x9.jpg'];
    const faraPoza = [...cuPoza]; faraPoza[10] = null;
    const goala = [...cuPoza]; goala[10] = '';
    check('produsul cu poză își păstrează adresa din bază',
      calc(cuPoza, res) === 'products/foto-x9.jpg', 'a ieșit: ' + JSON.stringify(calc(cuPoza, res)));
    check('produsul fără poză (NULL) nu primește nicio adresă',
      calc(faraPoza, res) === '', 'a ieșit: ' + JSON.stringify(calc(faraPoza, res)));
    check('produsul cu poză goală nu primește nicio adresă',
      calc(goala, res) === '', 'a ieșit: ' + JSON.stringify(calc(goala, res)));
    check('nu se mai construiește „products/prod-<nr>.jpg" în pagină',
      !/'products\/prod-' \+ r\[1\]/.test(text));
  }
}

console.log('\n  ' + trecute + ' trecute · ' + esuate + ' eșuate');
process.exitCode = esuate ? 1 : 0;
