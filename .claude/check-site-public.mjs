// Probele de regresie pentru pagina publică — `Site Public.dc.html`.
//
//   node .claude/check-site-public.mjs
//
// Pagina se randează în browser, deci nu poate fi apelată dintr-un test de
// API. Ce se poate verifica din afară e legătura dintre ce scrie în marcaj și
// ce calculează blocul de logică: exact cusătura pe care au stat defectele.
// Fiecare probă e legată de un defect din `.claude/defecte.json` și a fost
// verificată stricând reparația la loc.
//
// Ce *nu* acoperă: prețurile și variantele pe server — alea sunt în
// `.claude/test-flow.mjs`, care trimite cereri adevărate.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const FILE = 'Site Public.dc.html';
const src = fs.readFileSync(path.join(ROOT, FILE), 'utf8');
const cite = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');

// Marcajul și logica, separat: multe probe cer ca un text să fie *numai* în
// logică (unde trece prin `t()`), nu scris de-a dreptul în marcaj.
const iScript = src.indexOf('<script type="text/x-dc"');
const marcaj = src.slice(0, iScript < 0 ? src.length : iScript);
const logica = iScript < 0 ? '' : src.slice(iScript);

let pass = 0, fail = 0;
const check = (defect, nume, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${nume}`); }
  else { fail++; console.log(`  FAIL  ${nume}  [${defect}]${extra ? ' — ' + extra : ''}`); }
};

console.log(`\n=== ${FILE} ===`);

/* --- pachetul de test ajungea la server ca nimic ------------------------- */
// Rândul „pachet de test" din coș n-are `productId`, iar cererea se construia
// din `picks` cu `productId` direct: pachetul dispărea în tăcere, iar dacă era
// singur în coș pleca o cerere fără nicio poziție.
check('Site Public.dc.html:1767', 'pachetul de test se desface în poziții reale',
  /row\.trial/.test(logica) && /linii\.push\(\s*\{\s*productId:\s*r\[1\]/.test(logica));
check('Site Public.dc.html:1767', 'cererea se trimite cu pozițiile desfăcute',
  /items:\s*linii\b/.test(logica));
check('Site Public.dc.html:1767', 'coșul gol nu mai pleacă spre server',
  /if\s*\(!linii\.length\)/.test(logica));
check('Site Public.dc.html:1767', 'reducerea pachetului e scrisă în notă',
  /PACHET DE TEST −20%/.test(logica));

/* --- o singură variantă pentru tot coșul --------------------------------- */
// Coșul ține cheile separat („frozen:3", „baked:3"), deci poate fi amestecat.
// Varianta pleca o singură dată, luată din tabul deschis.
check('Site Public.dc.html:1742', 'fiecare rând din coș își poartă varianta',
  /variant:\s*parts\[0\]\s*===\s*'frozen'\s*\?\s*'congelat'\s*:\s*'copt'/.test(logica));
check('Site Public.dc.html:1742', 'poziția trimisă poartă varianta rândului',
  /linii\.push\(\{\s*productId:\s*row\.productId[^}]*variant:\s*row\.variant/.test(logica));

/* --- căsuța de cantitate golită ștergea rândul --------------------------- */
check('Site Public.dc.html:1246', 'cantitatea golită nu mai scoate rândul din coș',
  /if\s*\(Number\(cifre\)\s*>\s*0\)\s*this\.setQty/.test(logica));
check('Site Public.dc.html:1246', 'textul brut din căsuță se ține în stare',
  /editKey:\s*''/.test(logica) && /editText:\s*''/.test(logica)
  && /k\s*===\s*this\.state\.editKey/.test(logica));

/* --- telefonul nu era obligatoriu pentru un vizitator fără cont ---------- */
// Serverul cere telefon de la un vizitator anonim; formularul nu-l cerea, deci
// cererea pleca și se întorcea cu 422 după ce omul apăsa „Trimite".
check('Site Public.dc.html:796', 'câmpul de telefon e marcat obligatoriu în marcaj',
  /id="q-phone"[^>]*required="\{\{\s*phoneReq\s*\}\}"/.test(marcaj));
check('Site Public.dc.html:796', 'obligativitatea se calculează din sesiune',
  /phoneReq:\s*this\.state\.authReady\s*&&\s*!A/.test(logica));
check('Site Public.dc.html:796', 'eticheta spune „(obligatoriu)" când chiar e',
  /lblPhone:/.test(logica) && /\(obligatoriu\)/.test(logica));

/* --- catalogul care nu se încarcă nu spunea nimic ------------------------ */
// `catErr` exista în stare, dar nu ajungea în nicio legătură: catalogul rămânea
// gol fără explicație, iar cardul pachetului de test oferea „0 produse".
check('Site Public.dc.html:969', 'eroarea de catalog are un anunț în pagină',
  /<sc-if value="\{\{ catBroken \}\}">/.test(marcaj) && /role="alert"/.test(marcaj));
check('Site Public.dc.html:969', 'anunțul se aprinde din starea componentei',
  /catBroken:\s*!!this\.state\.catErr/.test(logica));
check('Site Public.dc.html:969', 'pachetul de test se ascunde peste un catalog gol',
  /<sc-if value="\{\{ hasCat \}\}"/.test(marcaj) && /hasCat:\s*PROD\.length\s*>\s*0/.test(logica));

/* --- texte rămase în română pe versiunea în engleză ---------------------- */
const roInMarcaj = [
  ['Patiserie artizanală', 'logoKicker'],
  ['Depozit — incinta ICTCM', 'mapTitle'],
  ['Incinta ICTCM', 'mapCaption'],
];
for (const [text, leg] of roInMarcaj) {
  check('Site Public.dc.html:590', `„${text}" nu mai e scris direct în marcaj`,
    !marcaj.includes(text), `încă apare lângă {{ ${leg} }}`);
}
check('Site Public.dc.html:590', 'antetul și harta citesc texte traduse',
  /\{\{ logoKicker \}\}/.test(marcaj)
  && /title="\{\{ mapTitle \}\}"/.test(marcaj)
  && /\{\{ mapCaption \}\}/.test(marcaj));

/* --- telefonul și e-mailul erau scrise în pagină ------------------------- */
// „Telefon public" și „E-mail public" din Setări nu schimbau nimic pentru
// vizitator: numărul, adresa și linkul de WhatsApp erau scrise în marcaj, în
// cinci locuri. Firma își schimba numărul din panou, clientul suna tot la cel
// vechi, iar datele trimise lui Google arătau numărul nou.
check('Setari.dc.html:439', 'numărul de telefon nu mai e scris în marcaj',
  !/tel:/.test(marcaj) && !marcaj.includes('0769 493 163'));
check('Setari.dc.html:439', 'adresa de e-mail nu mai e scrisă în marcaj',
  !/mailto:/.test(marcaj) && !marcaj.includes('contact@goldenpastry.ro'));
check('Setari.dc.html:439', 'contactul din marcaj vine din legături',
  /href="\{\{ telHref \}\}"/.test(marcaj) && /\{\{ phoneText \}\}/.test(marcaj)
  && /href="\{\{ mailHref \}\}"/.test(marcaj) && /\{\{ emailText \}\}/.test(marcaj));
check('Setari.dc.html:439', 'contactul se cere de la server, din Setări',
  /pullContact\(\)/.test(logica) && /gp\.contact\(\)/.test(logica));
check('Setari.dc.html:439', 'butonul de WhatsApp folosește același număr',
  /'https:\/\/wa\.me\/'\s*\+\s*phoneDigits/.test(logica));


/* --- cererea trimisă de două ori ----------------------------------------- */

console.log(`\n=== ${FILE} · cererea trimisă de două ori ===`);
{
  // Butonul se stinge (`disabled="{{ sending }}"`), dar `sending` e stare
  // React, iar `setState` nu schimbă starea pe loc: între apăsare și randare
  // butonul e încă activ, iar formularul e un `<form>` real, deci și Enter
  // apăsat de două ori îl trimite de două ori. Proba nu se uită la marcaj:
  // rulează pagina și apasă „Trimite cererea” de două ori la rând.
  const fereastra = {
    document: {
      hidden: false, addEventListener() {}, removeEventListener() {},
      documentElement: { setAttribute() {} },
      querySelector: () => null, querySelectorAll: () => [],
    },
    setInterval, clearInterval, setTimeout, clearTimeout,
    fetch: async () => { throw new Error('fără rețea în probă'); },
    location: { origin: 'http://127.0.0.1:8100', hostname: '127.0.0.1', protocol: 'http:', port: '8100',
      search: '', hash: '', href: 'http://127.0.0.1:8100/' },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    history: { pushState() {}, replaceState() {}, back() {}, state: null },
    matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }),
    addEventListener() {}, removeEventListener() {},
    scrollTo() {}, console,
  };
  new Function('window', 'globalThis', cite('shared/gp-api.js'))(fereastra, fereastra);
  const gps = fereastra.gp;

  // Un `FormData` de probă: citește dintr-un obiect simplu, nu dintr-un
  // element de formular, fiindcă aici nu există DOM.
  class FormDataFalsa {
    constructor(form) { this.date = (form && form.date) || {}; }
    get(k) { return Object.prototype.hasOwnProperty.call(this.date, k) ? this.date[k] : null; }
  }

  class DCLogicFals {
    constructor(props) { this.props = props || {}; }
    // Ca în React: starea se aplică la următoarea randare, nu pe loc.
    setState(x) {
      Promise.resolve().then(() => {
        this.state = Object.assign({}, this.state, typeof x === 'function' ? x(this.state) : x);
      });
    }
  }

  // Pagina cere `React.createRef()` în constructor, pentru bara laterală.
  // Aici nu randăm nimic, deci un obiect gol e de ajuns.
  const ReactFals = { createRef: () => ({ current: null }) };

  const corp = logica.slice(logica.indexOf('>') + 1, logica.lastIndexOf('</script>'));
  let ComponentSite = null;
  try {
    ComponentSite = new Function('gp', 'DCLogic', 'window', 'document', 'localStorage', 'FormData', 'React',
      corp + '\n;return Component;')(
      gps, DCLogicFals, fereastra, fereastra.document, fereastra.localStorage, FormDataFalsa, ReactFals);
  } catch (e) {
    console.log('  (pagina nu a putut fi încărcată: ' + e.message + ')');
  }
  check('Site Public.dc.html:1826', 'blocul de logică al paginii publice se poate rula în probă',
    typeof ComponentSite === 'function');

  const trimite = async (clicuri, raspuns) => {
    const cereri = [];
    if (typeof ComponentSite !== 'function') return { cereri, intre: {}, dupa: {} };

    let deblocheaza = null;
    const asteapta = new Promise((res) => { deblocheaza = res; });
    const postVechi = gps.post;
    gps.post = (cale, corpul) => { cereri.push({ cale, corp: corpul }); return asteapta.then(() => raspuns()); };
    try {
      const c = new ComponentSite({});
      c.props = {};
      c.state = Object.assign({}, c.state, {
        // Pachetul de test are nevoie doar de catalogul încărcat: e singura
        // poziție care nu cere potrivire după numărul produsului.
        prod: [[1, 11, 'Croissant', '', '', 4.5, 5.2], [2, 12, 'Ștrudel', '', '', 6, 7]],
        groups: [], qty: { 'trial:frozen': 1 }, tier: 'frozen',
        contact: { phone: '0700 000 000' }, auth: null, sent: false, sending: false, sendErr: '',
      });

      const eveniment = {
        preventDefault() {},
        target: { date: {
          Companie: 'Cofetăria Belvedere', 'Persoana de contact': 'Ana',
          Email: 'ana@example.com', Telefon: '0722 000 000',
        } },
      };
      const gata = c.renderVals().submitQuote;
      if (typeof gata !== 'function') return { cereri, intre: {}, dupa: {} };
      // Aceeași funcție, apăsată de mai multe ori: exact ce se întâmplă între
      // două apăsări, fiindcă ecranul nu s-a redesenat între ele.
      for (let i = 0; i < clicuri; i++) gata(eveniment);
      await Promise.resolve(); await Promise.resolve();
      const intre = c.renderVals();
      deblocheaza();
      await new Promise((r) => setTimeout(r, 0));
      return { cereri, intre, dupa: c.renderVals() };
    } finally {
      gps.post = postVechi;
    }
  };

  const bun = () => Promise.resolve({ request: { id: 7 } });

  const dublu = await trimite(2, bun);
  check('Site Public.dc.html:1826', 'două apăsări la rând trimit o singură cerere',
    dublu.cereri.length === 1 && dublu.cereri[0].cale === '/requests',
    dublu.cereri.length + ' cereri: ' + JSON.stringify(dublu.cereri.map((x) => x.cale)));
  check('Site Public.dc.html:1826', 'cât timp cererea e pe drum butonul e stins',
    dublu.intre.sending === true, 'sending = ' + JSON.stringify(dublu.intre.sending));
  check('Site Public.dc.html:1826', 'după răspunsul bun pagina spune că s-a trimis',
    dublu.dupa.sent === true, 'sent = ' + JSON.stringify(dublu.dupa.sent));

  const refuz = await trimite(3,
    () => Promise.reject(new Error('Numărul de telefon nu este valid.')));
  check('Site Public.dc.html:1826', 'nici trei apăsări nu trimit mai mult de o cerere',
    refuz.cereri.length === 1, refuz.cereri.length + ' cereri');
  check('Site Public.dc.html:1826', 'după un refuz butonul se aprinde la loc, cu motivul pe ecran',
    refuz.dupa.sending === false && String(refuz.dupa.sendErr).includes('telefon'),
    JSON.stringify({ sending: refuz.dupa.sending, sendErr: refuz.dupa.sendErr }));

  // Și în marcaj: butonul trebuie să rămână stins cât ține cererea, altfel
  // omul nu vede nimic schimbat și apasă din nou.
  check('Site Public.dc.html:1826', 'butonul de trimitere poartă `disabled` în marcaj',
    /type="submit"[^>]*disabled="\{\{ sending \}\}"/.test(marcaj),
    'nu am găsit disabled pe butonul de trimitere');
}

/* --- pozele paginii publice trăiau doar în fișierul de stare ------------- */
// Pozele mari — cea din cap, ambalajul, logistica — au stat o vreme numai în
// `.image-slots.state.json`, ca `data:` base64. Fișierul acela a fost mâncat de
// două ori de o versiune veche a probei sloturilor, iar pozele nu mai există în
// nicio copie: `data:` nu lasă fișier pe disc din care să le iei înapoi.
//
// Acum sunt fișiere adevărate, cerute prin `src`. Poarta asta ține trei lucruri
// laolaltă, fiindcă oricare singur ar minți: atributul din marcaj, fișierul pe
// disc și, dacă pachetul e construit, amândouă în el. Un `src` fără fișier e un
// 404 în pagină; un fișier fără `src` nu se vede nicăieri; iar amândouă bune în
// sursă, dar lipsă din pachet, șterg pozele de pe serverul care merge deja —
// exact așa au dispărut o dată.
{
  const POZE = [
    ['pf-hero', 'assets/img/hero.webp'],
    ['pf-range-3', 'assets/img/ambalaj.webp'],
    ['pf-logistics', 'assets/img/logistica.webp'],
    ['pf-about-1', 'assets/img/despre-productie.webp'],
    ['pf-about-2', 'assets/img/despre-ambalare.webp'],
    ['pf-about-3', 'assets/img/despre-depozit.webp'],
    ['pf-about-4', 'assets/img/despre-echipa.webp'],
  ];
  const PACHET = path.join(ROOT, 'deploy', 'public_html');
  const arePachet = fs.existsSync(path.join(PACHET, 'app.html'));
  const app = arePachet ? fs.readFileSync(path.join(PACHET, 'app.html'), 'utf8') : '';

  for (const [id, cale] of POZE) {
    const slot = (marcaj.match(new RegExp('<image-slot id="' + id + '"[^>]*>')) || [])[0] || '';
    check('Site Public.dc.html', `slotul „${id}" cere o poză`, slot.includes('src="' + cale + '"'),
      slot || 'nu am găsit slotul');

    const peDisc = path.join(ROOT, cale);
    const marime = fs.existsSync(peDisc) ? fs.statSync(peDisc).size : 0;
    check('Site Public.dc.html', `poza „${cale}" există și nu e goală`, marime > 1024,
      marime ? marime + ' octeți' : 'lipsește de pe disc');

    if (arePachet) {
      check('.claude/build-deploy.mjs', `pachetul cere „${id}" la fel ca sursa`,
        app.includes('src="' + cale + '"'), 'app.html nu are src-ul');
      const inPachet = path.join(PACHET, cale);
      check('.claude/build-deploy.mjs', `poza „${cale}" a ajuns în pachet`,
        fs.existsSync(inPachet) && fs.statSync(inPachet).size === marime,
        fs.existsSync(inPachet) ? 'altă mărime decât sursa' : 'lipsește din deploy/');
    }
  }
  if (!arePachet) console.log('  ~     sar peste verificările pe pachet: nu e construit');

  // Și fișierul de stare nu are voie să plece cu urme de probă în el.
  const STARE = path.join(ROOT, '.image-slots.state.json');
  if (fs.existsSync(STARE)) {
    const chei = Object.keys(JSON.parse(fs.readFileSync(STARE, 'utf8') || '{}'));
    check('.claude/test-sloturi.mjs', 'fișierul de stare nu poartă sloturi de probă',
      !chei.some((k) => k.startsWith('zz-proba')), 'are: ' + chei.join(', '));
  }
}

/* --- bifa „catalogul PDF" nu se putea debifa ----------------------------- */
// Căsuța era legată în marcaj direct la `{{ true }}`, fără `onChange` și fără
// nicio stare în spate. Runtime-ul readuce sincron valoarea din props după
// fiecare apăsare, deci bifa nici nu clipea, iar cererea pleca mereu cu
// „vrea catalogul" — inclusiv de la cine tocmai o debifase.
{
  const linia = (marcaj.match(/<input[^>]*name="Vreau catalogul PDF"[^>]*>/) || [''])[0];
  check('Site Public.dc.html:842', 'căsuța de catalog are handler de schimbare',
    /onChange="\{\{/.test(linia), linia ? 'fără onChange' : 'nu am găsit inputul');
  check('Site Public.dc.html:842', 'căsuța nu mai e legată la o valoare fixă',
    !/checked="\{\{\s*true\s*\}\}"/.test(linia));
  check('Site Public.dc.html:842', 'bifa are stare în componentă',
    /wantCatalog:\s*true/.test(logica) && /toggleCatalog:/.test(logica));
  check('Site Public.dc.html:1870', 'cererea citește bifa din stare, nu din formular',
    !/fd\.get\('Vreau catalogul PDF'\)/.test(logica)
      && /catalog:[\s\S]{0,220}this\.state\.wantCatalog/.test(logica));
  // Căsuța se arată doar vizitatorilor fără cont (`asGuest`). Starea nu se
  // șterge când marcajul dispare, deci fără condiția pe cont un client
  // conectat ar fi trimis „vrea catalogul" pentru o căsuță pe care nu a
  // văzut-o niciodată.
  check('Site Public.dc.html:1870', 'clientul cu cont nu trimite bifa pe care n-o vede',
    /catalog:\s*!\(this\.state\.auth\s*&&\s*this\.state\.auth\.role\s*===\s*'client'\)/.test(logica));
}

/* --- pachetul de test ștergea pragul de 700 RON -------------------------- */
// Pachetul intra în total la preț de listă, deși clientul plătește net cu −20%.
// Cardul se făcea verde „Comandă minimă atinsă" pentru un coș care nu atingea
// minimul: o singură sumă răspundea la două întrebări diferite.
//
// Aritmetica de mai jos e *scoasă din pagină*, nu rescrisă aici. O probă care
// și-ar reface singură socoteala ar trece și peste o pagină care socotește
// altfel — adică exact peste defectul pe care îl păzește.
try {
  // Reparația dată înapoi scoate chiar rândurile pe care le extragem. Fără
  // plasa asta, proba ar muri cu urmă de eroare în loc să spună „picat".
  const scoate = (re, nume) => {
    const m = logica.match(re);
    if (!m) throw new Error('nu găsesc ' + nume + ' în ' + FILE);
    return m[0];
  };
  const preturi = scoate(/const TRIAL_EACH = [\s\S]*?const trialNet = [^\n]*\n/, 'prețurile pachetului');
  const laPachet = scoate(/ {10}const unitPrice = trialList\(tr\);[\s\S]*?totNet \+= n \* trialNet\(tr\);/, 'sumele pachetului');
  const laBucata = scoate(/ {8}totPieces \+= n;[\s\S]*?totNet \+= n \* unitPrice;/, 'sumele pe bucată');
  const prag = scoate(/ {4}const MIN_ORDER = [\s\S]*?const minRedus = [^\n]*\n/, 'pragul');
  const iesire = 'return { totPieces, totMoney, totNet, minOk, minLeft, minRedus, MIN_ORDER };';

  const cuPachet = new Function('PROD', 'tr', 'n',
    preturi + '\nlet totPieces = 0, totMoney = 0, totNet = 0;\n' + laPachet + '\n' + prag + '\n' + iesire);
  const cuBucata = new Function('unitPrice', 'n',
    'let totPieces = 0, totMoney = 0, totNet = 0;\n' + laBucata + '\n' + prag + '\n' + iesire);

  // Cinci produse a 50 RON × 3 bucăți = 750 la listă, 600 net. Fereastra e
  // aleasă anume: peste prag la listă, sub prag net. Acolo trăia defectul.
  const CAT = [0, 1, 2, 3, 4].map((i) => [1, i + 1, 'P' + i, 'P' + i, 100, 50, 50]);

  const unul = cuPachet(CAT, 'frozen', 1);
  check('Site Public.dc.html:1292', 'pachetul rămâne la preț de listă în total',
    unul.totMoney === 750, 'totMoney = ' + unul.totMoney);
  check('Site Public.dc.html:1300', 'pachetul intră net în suma de la prag',
    unul.totNet === 600, 'totNet = ' + unul.totNet);
  check('Site Public.dc.html:1351', 'coșul de 750 la listă și 600 net NU atinge minimul',
    unul.minOk === false);
  check('Site Public.dc.html:1351', 'cât mai lipsește se socotește tot net',
    unul.minLeft === 100, 'minLeft = ' + unul.minLeft);
  check('Site Public.dc.html:1351', 'divergența dintre cele două sume e semnalată',
    unul.minRedus === true);

  // Cantitatea era ignorată: bucățile, banii și rândul de pe ecran ziceau „un
  // pachet" oricâte ar fi fost în coș. Azi trialAdd pune mereu 1 — nimic din
  // cod nu impune însă asta, iar al doilea pachet ar fi plecat gratis.
  const doua = cuPachet(CAT, 'frozen', 2);
  check('Site Public.dc.html:1292', 'al doilea pachet se numără la bani',
    doua.totMoney === 1500 && doua.totNet === 1200,
    doua.totMoney + ' / ' + doua.totNet);
  check('Site Public.dc.html:1292', 'al doilea pachet se numără la bucăți',
    doua.totPieces === 30, 'totPieces = ' + doua.totPieces);
  check('Site Public.dc.html:1351', 'două pachete trec pragul, unul nu',
    doua.minOk === true);

  // Produsele obișnuite n-au reducere: dacă cele două sume s-ar despărți și
  // aici, pragul ar minți în cealaltă direcție.
  const simplu = cuBucata(70, 10);
  check('Site Public.dc.html:1320', 'produsele obișnuite intră la fel în ambele sume',
    simplu.totMoney === 700 && simplu.totNet === 700);
  check('Site Public.dc.html:1351', 'fără pachet în coș nu se semnalează nicio reducere',
    simplu.minRedus === false && simplu.minOk === true);
} catch (e) {
  check('Site Public.dc.html:1351', 'socoteala pragului se poate scoate din pagină', false, e.message);
}

/* --- două cifre pe ecran, fără explicație -------------------------------- */
// Livrabilul nu e aritmetica, ci propoziția: cu pachetul în coș, totalul și
// bara arată sume diferite. Fără o vorbă, arată ca o greșeală de socoteală.
check('Site Public.dc.html:1634', 'bara de progres urmează aceeași sumă ca verdictul',
  /\(totNet \/ MIN_ORDER\)/.test(logica) && !/\(totMoney \/ MIN_ORDER\)/.test(logica));
check('Site Public.dc.html:1642', 'cardul spune de ce cifra de la prag nu e totalul',
  /minRedus[\s\S]{0,400}reducerea de 20% a pachetului de test e deja scăzută/.test(logica));
check('Site Public.dc.html:1642', 'explicația există și în engleză',
  /the 20% trial pack discount is already deducted/.test(logica));
check('Site Public.dc.html:1373', 'oferta trimisă poartă aceeași lămurire',
  /L\.push\(t\('La prag socotim/.test(logica));

console.log(`\n  ${pass} trecute · ${fail} picate\n`);
process.exit(fail ? 1 : 0);
