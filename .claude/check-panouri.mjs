// Probele de regresie pentru panourile de lucru — paginile `.dc.html` din
// `lab.` și `clienti.`, plus ajutorul comun `shared/gp-api.js`.
//
//   node .claude/check-panouri.mjs
//
// Paginile se randează în browser, deci nu pot fi apelate dintr-un test de
// API. Ce se poate verifica din afară e cusătura dintre ce promite marcajul și
// ce calculează blocul de logică — plus, acolo unde se poate, funcția însăși,
// scoasă din sursă și rulată aici. Fiecare probă e legată de un defect din
// `.claude/defecte.json` și a fost verificată stricând reparația la loc.
//
// Perechea ei pentru pagina publică este `.claude/check-site-public.mjs`.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cite = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');

let pass = 0, fail = 0;
const check = (defect, nume, cond, extra = '') => {
  if (cond) { pass++; console.log(`  ok    ${nume}`); }
  else { fail++; console.log(`  FAIL  ${nume}  [${defect}]${extra ? ' — ' + extra : ''}`); }
};

/**
 * `gp.qty` și `gp.qtyLabel`, scoase din shared/gp-api.js și făcute funcții.
 *
 * Paginile citesc cantitățile prin ele. Un `gp` simulat care le-ar aproxima ar
 * face proba să treacă și peste o citire greșită — exact defectul din care au
 * apărut: cantitatea zecimală se pierdea fiindcă pagina o citea altfel decât
 * credea cine se uita la ea.
 */
function gpCantitati() {
  const src = cite('shared/gp-api.js');
  const scoate = (nume) => {
    const i = src.indexOf('\n    ' + nume + '(v) {');
    if (i < 0) throw new Error('nu găsesc gp.' + nume + ' în shared/gp-api.js');
    const j = src.indexOf('\n    },', i);
    return src.slice(i + 1, j + 1) + '\n    },';
  };
  return new Function('const gp = {\n' + scoate('qty') + '\n' + scoate('qtyLabel') + '\n};\nreturn gp;')();
}

/** Marcajul și logica unei pagini, separat. */
function parti(fisier) {
  const src = cite(fisier);
  const i = src.indexOf('<script type="text/x-dc"');
  return { src, marcaj: src.slice(0, i < 0 ? src.length : i), logica: i < 0 ? '' : src.slice(i) };
}

/** Ziua, așa cum o scrie pagina: 29.08.2026 */
const dataAsteptata = (ms) => {
  const d = new Date(ms), z = (n) => (n < 10 ? '0' : '') + n;
  return z(d.getDate()) + '.' + z(d.getMonth() + 1) + '.' + d.getFullYear();
};

/* ======================================================= Conturi.dc.html == */

console.log('\n=== Conturi.dc.html ===');
{
  const { src, marcaj, logica } = parti('Conturi.dc.html');

  // Serverul refuză orice parolă sub 8 caractere. Pagina cerea 6 și propunea
  // parole de 7: omul apăsa „Generează", serverul refuza, iar refuzul apărea
  // în bannerul verde de succes.
  //
  // Scoatem chiar funcțiile din sursă și le rulăm, ca proba să nu se uite doar
  // la un număr scris undeva.
  const fnMinLen = /minLen\(\)\s*\{([\s\S]*?)\n  \}/.exec(logica);
  const fnSuggest = /suggest\(\)\s*\{([\s\S]*?)\n  \}/.exec(logica);
  const listaWords = /const WORDS\s*=\s*(\[[\s\S]*?\]);/.exec(logica);

  check('Conturi.dc.html:425', 'funcțiile de parolă se pot citi din sursă',
    !!(fnMinLen && fnSuggest && listaWords));

  if (fnMinLen && fnSuggest && listaWords) {
    const WORDS = JSON.parse(listaWords[1].replace(/'/g, '"').replace(/,(\s*])/, '$1'));
    const fabrica = (prop) => {
      const obiect = {
        props: { minLength: prop },
        WORDS,
        minLen: new Function('WORDS', 'return function () {' + fnMinLen[1] + '\n};')(WORDS),
        suggest: new Function('WORDS', 'return function () {' + fnSuggest[1] + '\n};')(WORDS),
      };
      return obiect;
    };

    // Oricât de mic ar fi propul, pragul paginii nu poate coborî sub cel al
    // serverului: altfel caseta promite ceva ce salvarea refuză.
    const subPrag = [undefined, '', '1', '4', '6', '7'].filter((p) => fabrica(p).minLen() < 8);
    check('Conturi.dc.html:425', 'pragul paginii nu coboară niciodată sub cele 8 caractere ale serverului',
      subPrag.length === 0, 'a dat sub 8 pentru: ' + JSON.stringify(subPrag));

    // Parola propusă trebuie să treacă de prag de fiecare dată, nu de obicei:
    // lista de cuvinte are și „unt", de trei litere.
    let scurte = 0, cellMaiScurt = 99;
    for (const prop of ['8', '12', '16']) {
      const o = fabrica(prop);
      for (let i = 0; i < 800; i++) {
        const p = o.suggest();
        if (p.length < o.minLen()) scurte++;
        if (p.length < cellMaiScurt) cellMaiScurt = p.length;
      }
    }
    check('Conturi.dc.html:425', 'parola propusă trece de prag la fiecare încercare',
      scurte === 0, scurte + ' prea scurte, cea mai scurtă de ' + cellMaiScurt);
  }

  // Un refuz nu mai are voie să fie desenat ca o confirmare.
  check('Conturi.dc.html:425', 'bannerul de sus își ia culoarea din stare, nu e mereu verde',
    marcaj.includes('class="{{ msgCls }}"') && !marcaj.includes('class="h-alert h-alert--success" style="margin:16px 28px 0'));
  check('Conturi.dc.html:425', 'pagina știe să deseneze și un banner de refuz',
    /msgCls:\s*S\.msgBad\s*\?/.test(logica));

  // Fiecare drum care pune un mesaj de eroare trebuie să-l și marcheze ca
  // atare. Ne uităm la toate locurile care scriu `msg: e.message`.
  const eroriNemarcate = [...logica.matchAll(/msg: e\.message[^}]*\}/g)]
    .map((m) => m[0])
    .filter((m) => !m.includes('msgBad'));
  check('Conturi.dc.html:425', 'orice mesaj de eroare se marchează ca refuz',
    eroriNemarcate.length === 0, JSON.stringify(eroriNemarcate).slice(0, 200));

  // Contul de sistem al tabletelor nu are parolă: butoanele de parolă nu
  // trebuie să apară pentru el, iar lista trebuie să spună care e.
  check('api/routes/users.php:158', 'pagina primește de la server steagul de cont de sistem',
    /sys:\s*x\.isSystem === true/.test(logica));
  check('api/routes/users.php:158', 'butoanele de parolă sunt ascunse pentru contul de sistem',
    src.includes('<sc-if value="{{ a.notSystem }}"') && /canEditPass:\s*!editing && !acc\.sys/.test(logica));
}

/* ============================== Conturi.dc.html · parole și redenumiri == */

console.log('\n=== Conturi.dc.html · ce spune pagina despre parola unui cont ===');
{
  const { src, marcaj, logica } = parti('Conturi.dc.html');

  // Rândul fiecărui cont scria „parolă schimbată <dată>", dar data era
  // `lastLogin`. Administratorul se uită exact acolo ca să afle dacă parola
  // dictată la telefon mai deschide contul, iar pagina îi răspundea pe dos.
  // Scoatem funcția din sursă și o rulăm pe toate cele patru feluri de cont.
  const fnData = /const dataOra = [\s\S]*?\n\};/.exec(logica);
  const fnDespre = /const desprePar = [\s\S]*?\n\};/.exec(logica);
  check('Conturi.dc.html:338', 'funcția care descrie parola se poate citi din sursă',
    !!(fnData && fnDespre));

  if (fnData && fnDespre) {
    const desprePar = new Function(fnData[0] + '\n' + fnDespre[0] + '\nreturn desprePar;')();
    const ieri = Date.now() - 24 * 3600 * 1000;

    // Cazul defectului: contul are parola pusă de administrator și s-a
    // autentificat ieri. Pagina nu are voie să numească asta o schimbare de
    // parolă, și cu atât mai puțin să pună data intrării în locul ei.
    const provizoriu = desprePar({ sys: false, temp: true, pwAt: 0, at: ieri });
    check('Conturi.dc.html:338', 'un cont cu parola pusă de administrator e numit așa, nu „parolă schimbată”',
      /provizorie/i.test(provizoriu) && !/schimbat/i.test(provizoriu), provizoriu);
    check('Conturi.dc.html:539', 'și nu i se lipește data ultimei autentificări',
      !/\d{2}\.\d{2}\.\d{4}/.test(provizoriu), provizoriu);

    // Un cont care și-a schimbat singur parola: aici data e adevărată.
    const propriu = desprePar({ sys: false, temp: false, pwAt: ieri, at: Date.now() });
    check('Conturi.dc.html:539', 'un cont care și-a schimbat singur parola primește data schimbării',
      /schimbat/i.test(propriu) && /\d{2}\.\d{2}\.\d{4}/.test(propriu), propriu);
    check('Conturi.dc.html:539', 'iar data afișată e a schimbării, nu a ultimei intrări',
      propriu.includes(dataAsteptata(ieri)) && !propriu.includes(dataAsteptata(Date.now())),
      propriu);

    // Cont vechi, dinainte de coloană: nu se inventează nicio dată.
    const fara = desprePar({ sys: false, temp: false, pwAt: 0, at: ieri });
    check('Conturi.dc.html:338', 'fără o dată reală, pagina nu scrie niciuna',
      !/\d{2}\.\d{2}\.\d{4}/.test(fara), fara);

    const sistem = desprePar({ sys: true, temp: false, pwAt: 0, at: 0 });
    check('Conturi.dc.html:539', 'contul tabletelor spune că nu are parolă',
      /cod/i.test(sistem), sistem);
  }

  // Serverul trimite acum cele două valori adevărate; pagina trebuie să le ia.
  check('api/routes/users.php:172', 'pagina citește de la server steagul de parolă provizorie',
    /temp:\s*x\.mustChangePassword === true/.test(logica));
  check('Conturi.dc.html:338', 'și data reală a schimbării parolei',
    /pwAt:\s*x\.passwordChangedAt/.test(logica));
  check('Conturi.dc.html:338', 'nu mai există nicio funcție care numește „schimbată” ultima autentificare',
    !/parolă schimbată " \+/.test(logica) && !/"parolă inițială, neschimbată"/.test(logica));

  // O adresă de e-mail scrisă în cod ajungea preîncărcată în fiecare cont nou
  // și primea linkurile de recuperare ale clienților.
  // Textul din casete („nume@exemplu.ro") e un exemplu care se vede și rămâne;
  // ne uităm la blocul de logică, unde stătea adresa care ajungea valoare
  // implicită și destinatar de rezervă.
  check('Conturi.dc.html:442', 'nicio adresă de e-mail scrisă în logica paginii Conturi',
    !/[\w.+-]+@[\w-]+\.[a-z]{2,}/i.test(logica), (logica.match(/[\w.+-]+@[\w-]+\.[a-z]{2,}/i) || [''])[0]);

  // Fiecare tastă apăsată în Nume / E-mail / Nume client pleca la server ca
  // PATCH separat: un UPDATE și o intrare de jurnal per literă, iar caseta —
  // input controlat de React fără stare locală — își pierdea litera până venea
  // răspunsul. Acum se scrie într-o ciornă și se salvează la ieșirea din câmp.
  const laFiecareTasta = /on(Name|Mail|Cust):\s*\(e\) => this\.patchUser/.test(logica);
  check('Conturi.dc.html:561', 'casetele de nume nu mai trimit un PATCH la fiecare tastă',
    !laFiecareTasta);
  check('Conturi.dc.html:561', 'ce se tastează se ține într-o ciornă a paginii',
    /draft:\s*\{\}/.test(logica) && /onTasta\(acc,/.test(logica));
  for (const camp of ['saveName', 'saveMail', 'saveCust']) {
    check('Conturi.dc.html:561', 'caseta „' + camp + '” se salvează la ieșirea din câmp',
      marcaj.includes('onBlur="{{ a.' + camp + ' }}"'));
  }

  // Ștergerea unui cont se făcea din prima apăsare. Pe server e un DELETE
  // adevărat: rândul dispare cu tot cu parolă.
  check('Conturi.dc.html:574', 'ștergerea unui cont cere o a doua apăsare',
    /if \(S\.sterg !== acc\.u\)/.test(logica));
  check('Conturi.dc.html:574', 'și se poate opri înainte să se întâmple',
    marcaj.includes('{{ a.onDelCancel }}') && /onDelCancel:/.test(logica));
}

/* ======================================================== Parola.dc.html == */

console.log('\n=== Parola.dc.html ===');
{
  // Serverul avea ruta de schimbare a propriei parole și punea pe fiecare cont
  // nou steagul „trebuie schimbată", dar nicio pagină nu chema ruta și nicio
  // pagină nu citea steagul: parola dictată la telefon rămânea a contului la
  // nesfârșit.
  const { marcaj, logica } = parti('Parola.dc.html');
  check('Conturi.dc.html:442', 'pagina de schimbare a propriei parole cheamă ruta serverului',
    /gp\.changePassword\(/.test(logica));
  check('Conturi.dc.html:442', 'cere parola de acum, nu doar pe cea nouă',
    marcaj.includes('{{ onVeche }}'));
  check('Conturi.dc.html:442', 'pragul paginii nu coboară sub cele 8 caractere ale serverului',
    /const MIN = (8|9|1\d)/.test(logica));
  check('Conturi.dc.html:442', 'îi spune omului când pe cont e încă parola de la patiserie',
    /mustChangePassword === true/.test(logica) && marcaj.includes('{{ provizorie }}'));

  // Degeaba există pagina dacă nu ajunge nimeni la ea.
  const drumuri = [
    ['Intrare.dc.html', 'prima intrare cu o parolă provizorie duce direct acolo'],
    ['Client.dc.html', 'panoul clientului trimite spre ea'],
    ['Site Public.dc.html', 'meniul contului din magazin trimite spre ea'],
    ['Administrare Comenzi.dc.html', 'panoul de administrare trimite spre ea'],
  ];
  for (const [fisier, ce] of drumuri) {
    check('Conturi.dc.html:442', ce, cite(fisier).includes('Parola.dc.html'));
  }
  check('Conturi.dc.html:442', 'pagina se publică pe amândouă subdomeniile',
    /Parola\.dc\.html'[^\n]*clienti: 'parola\.html', lab: 'parola\.html'/.test(cite('.claude/build-deploy.mjs')));
  check('api/routes/users.php:172', 'panoul clientului arată singur că are parolă provizorie',
    /mustChange:\s*!!\(auth && auth\.temp\)/.test(cite('Client.dc.html')));
}

/* =========================== Autentificarea, într-un singur loc =========== */

console.log('\n=== Autentificarea, într-un singur loc ===');
{
  // Fiecare panou își avea formularul lui de autentificare, copiat. În
  // „Administrare Comenzi" acela nici nu putea fi folosit: `pullRequests()`
  // pleca din `componentDidMount` ÎNAINTE ca `me()` să spună dacă există
  // sesiune, lua 401 și, din `catch`, trimitea omul înapoi la pagina de
  // intrare. Din afară arăta ca o pagină care te bagă înăuntru și te dă
  // imediat afară — și chiar asta era.
  //
  // Formularele copiate au dispărut. A rămas un singur loc de autentificare,
  // iar panourile pleacă acolo cu o urmă de întoarcere. Urma vine din bara de
  // adrese, deci e scrisă de oricine trimite un link: de aici cele mai multe
  // dintre probele de mai jos.

  /* --- gp.loginHref(): unde pleacă panoul și ce ține minte --------------- */

  // Funcția adevărată din `shared/gp-api.js`, rulată aici cu o fereastră
  // falsă. Un `gp` simulat care ar aproxima-o ar trece și peste o verificare
  // scoasă — adică exact peste ce apără proba.
  const fereastraLog = (pathname, search) => {
    const f = {
      document: { hidden: false, documentElement: { setAttribute() {} }, addEventListener() {}, removeEventListener() {} },
      setInterval, clearInterval, fetch: async () => { throw new Error('fără rețea în probă'); },
      location: { origin: 'http://127.0.0.1:8100', hostname: '127.0.0.1', protocol: 'http:', port: '8100', pathname, search },
      sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
      localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
      URLSearchParams, console,
    };
    new Function('window', 'globalThis', cite('shared/gp-api.js'))(f, f);
    return f.gp;
  };
  const unde = (pathname, search = '') => fereastraLog(pathname, search).loginHref();

  check('shared/gp-api.js:loginHref', 'panoul pleacă la Intrare cu pagina lui ținută minte',
    unde('/Depozit.dc.html') === 'Intrare.dc.html?next=Depozit.dc.html',
    unde('/Depozit.dc.html'));

  check('shared/gp-api.js:loginHref', 'numele cu spațiu se citește din adresă, nu se ghicește',
    unde('/Administrare%20Comenzi.dc.html') === 'Intrare.dc.html?next=Administrare+Comenzi.dc.html',
    unde('/Administrare%20Comenzi.dc.html'));

  // În pachetul livrat paginile n-au extensie. O regulă scrisă pe „.dc.html"
  // ar fi mers în dezvoltare și ar fi tăcut pe serverul adevărat.
  check('shared/gp-api.js:loginHref', 'și numele fără extensie din pachet e ținut minte',
    unde('/depozit') === 'Intrare.dc.html?next=depozit', unde('/depozit'));

  check('shared/gp-api.js:loginHref', 'o adresă fără nume de pagină nu lasă urmă',
    unde('/') === 'Intrare.dc.html', unde('/'));

  check('shared/gp-api.js:loginHref', 'restul parametrilor rămân pe drum',
    unde('/Depozit.dc.html', '?lang=en').includes('lang=en')
    && unde('/Depozit.dc.html', '?lang=en').includes('next=Depozit.dc.html'));

  // Cine ajunge pe panou cu un „next" străin agățat de adresă nu-l poate
  // spăla trecând prin plecarea spre autentificare: se suprascrie, nu se
  // adaugă lângă.
  {
    const a = unde('/Depozit.dc.html', '?next=https%3A%2F%2Fevil.tld');
    check('shared/gp-api.js:loginHref', 'un „next" străin e înlocuit, nu păstrat alături',
      a === 'Intrare.dc.html?next=Depozit.dc.html', a);
  }
  {
    const a = unde('/', '?next=https%3A%2F%2Fevil.tld');
    check('shared/gp-api.js:loginHref', 'fără pagină de întors, „next"-ul străin e șters',
      a === 'Intrare.dc.html', a);
  }

  /* --- verificarea din Intrare.dc.html: numai o pagină a aplicației ------ */

  const { logica: logIntrare } = parti('Intrare.dc.html');

  // Verificarea se scoate din sursă și se rulează, nu se citește cu ochii:
  // o expresie regulată prost scrisă arată la fel de convingător ca una bună.
  const paginaAplicatiei = (() => {
    const cap = 'const paginaAplicatiei = (brut) => {';
    const coada = '\n    };';
    const i = logIntrare.indexOf(cap);
    if (i < 0) throw new Error('nu găsesc paginaAplicatiei în Intrare.dc.html');
    const j = logIntrare.indexOf(coada, i);
    return new Function(logIntrare.slice(i, j + coada.length) + '\nreturn paginaAplicatiei;')();
  })();

  check('Intrare.dc.html:281', 'o pagină a aplicației trece',
    paginaAplicatiei('Depozit.dc.html') === 'Depozit.dc.html'
    && paginaAplicatiei('Administrare Comenzi.dc.html') === 'Administrare Comenzi.dc.html');

  // Numele din pachetul livrat, cele pe care le vede serverul adevărat.
  // Verificarea cerută pe „.dc.html" le refuza pe toate, în tăcere: acasă
  // mergea, la patiserie nu.
  check('Intrare.dc.html:281', 'numele fără extensie din pachetul livrat trec',
    paginaAplicatiei('depozit') === 'depozit' && paginaAplicatiei('comenzi') === 'comenzi');

  // Fiecare dintre acestea e o cale de a duce omul, imediat DUPĂ ce și-a
  // scris parola, într-o pagină străină care arată la fel.
  const OTRAVA = [
    ['adresă absolută', 'https://example.com/phish'],
    ['adresă de rețea', '//evil.tld/x'],
    ['adresă de rețea, codată', '%2F%2Fevil.tld'],
    ['ieșire din folder', '../Setari.dc.html'],
    ['ieșire din folder, codată', '..%2FSetari.dc.html'],
    ['ieșire din folder, codată cu litere mici', '%2e%2e%2fSetari.dc.html'],
    ['schemă javascript', 'javascript:alert(1)'],
    ['schemă fără două bare', 'https:evil.tld'],
    ['cale de rețea cu bară inversă', '\\\\evil.tld\\x'],
    ['bară inversă codată', '%5C%5Cevil.tld'],
    ['adresă cu acreditări în ea', 'https://user@evil.tld'],
    ['cale absolută pe același server', '/etc/passwd'],
    ['nimic', ''],
    ['lipsă', null],
  ];
  for (const [cum, valoare] of OTRAVA) {
    check('Intrare.dc.html:281', 'nu se acceptă întoarcere prin ' + cum,
      paginaAplicatiei(valoare) === '', JSON.stringify(paginaAplicatiei(valoare)));
  }

  check('Intrare.dc.html:329', '„next" refuzat se scoate și el din bara de adrese',
    /if \(brut !== null\) \{[\s\S]{0,900}?if \(n\) this\.setState\(\{ next: n \}\);[\s\S]{0,900}?history\.replaceState/.test(logIntrare));

  /* --- după autentificare, întoarcerea are întâietate ------------------- */

  check('Intrare.dc.html:542', 'pagina cerută se încearcă înaintea celei implicite',
    logIntrare.indexOf('const cerut = role === "client"') > 0
    && logIntrare.indexOf('const cerut = role === "client"') < logIntrare.indexOf('const dest = role === "client"'));

  check('Intrare.dc.html:542', 'clientul nu e trimis într-un panou al patiseriei',
    /const cerut = role === "client" \? "" : this\.state\.next;/.test(logIntrare));

  /* --- nicio trimitere scrisă de mână în panouri ------------------------ */

  const PANOURI = [
    'Administrare Comenzi.dc.html', 'Depozit.dc.html', 'Jurnal.dc.html',
    'Panou Laborator.dc.html', 'Produse.dc.html', 'Ruta Sofer.dc.html', 'Setari.dc.html',
  ];
  for (const f of PANOURI) {
    const { logica } = parti(f);
    check(f + ':toLogin', f.replace('.dc.html', '') + ' pleacă la autentificare prin locul comun',
      !/location\.href\s*=\s*["']Intrare\.dc\.html/.test(logica) && /gp\.toLogin\(\)/.test(logica));
  }

  /* --- ordinea la încărcare: întâi cine ești, abia apoi ce ai de lucru --- */

  const { logica: logAdm } = parti('Administrare Comenzi.dc.html');
  const mountAdm = (() => {
    const i = logAdm.indexOf('\n  componentDidMount() {');
    if (i < 0) throw new Error('nu găsesc componentDidMount în Administrare Comenzi.dc.html');
    return logAdm.slice(i, logAdm.indexOf('\n  }\n', i));
  })();

  check('Administrare Comenzi.dc.html:894', 'la încărcare nu se cer date înaintea sesiunii',
    !/this\.pull(Requests|Orders)\s*\(/.test(mountAdm), 'chiar asta era defectul');

  // Ceasul de 4 secunde e aceeași cursă, doar mai lentă: pornit din montare,
  // cere comenzi și cereri fără să știe dacă are cui. Iar clientul oprit la
  // ecranul de refuz ar bate serverul la nesfârșit din spatele lui.
  check('Administrare Comenzi.dc.html:894', 'nici ceasul de reîmprospătare nu pornește înaintea sesiunii',
    !/setInterval/.test(mountAdm) && !/addEventListener\("storage"/.test(mountAdm));

  check('Administrare Comenzi.dc.html:porneste', 'ceasul pornește doar pentru cine are ce lucra aici',
    /if \(user\.role === "client"\) return;\s*\n\s*this\.porneste\(\);/.test(logAdm));

  check('Administrare Comenzi.dc.html:porneste', 'o a doua încercare nu suprapune două ceasuri',
    /porneste\(\) \{\s*\n\s*if \(this\.reqTimer\) return;/.test(logAdm));

  check('Administrare Comenzi.dc.html:922', 'încărcarea trece prin locul care duce și înapoi',
    /this\.intra\(\);/.test(mountAdm));

  check('Administrare Comenzi.dc.html:939', 'intra() cere sesiunea înaintea oricărei alte cereri',
    /intra\(\) \{[\s\S]{0,1400}?gp\.requireUser\(\)[\s\S]{0,700}?this\.pullOrders\(\)/.test(logAdm));

  /* --- formularul copiat a dispărut din amândouă paginile --------------- */

  const poarta = (fisier) => {
    const { marcaj } = parti(fisier);
    const i = marcaj.indexOf('<sc-if value="{{ locked }}"');
    const j = marcaj.indexOf('<sc-if value="{{ denied }}"');
    if (i < 0 || j < 0 || j < i) throw new Error('nu găsesc poarta în ' + fisier);
    return marcaj.slice(i, j);
  };
  for (const f of ['Administrare Comenzi.dc.html', 'Conturi.dc.html']) {
    const { logica } = parti(f);
    const nume = f.replace('.dc.html', '');
    check(f + ':poartă', nume + ' nu mai cere parola în pagina lui',
      !/<input/i.test(poarta(f)) && !/doLogin/.test(logica));
    // Cât ține întrebarea către server nu se spune nici „autentifică-te", nici
    // „n-ai voie": nu se știe încă.
    check(f + ':poartă', nume + ' arată o așteptare, nu un refuz pripit',
      /lockedLine/.test(logica) && /Se verifică sesiunea/.test(logica));
    // Serverul mut nu e o sesiune expirată: nu se trimite nimeni să-și scrie
    // parola degeaba, i se oferă o reîncercare.
    check(f + ':poartă', nume + ' nu confundă serverul mut cu sesiunea expirată',
      /offline/.test(logica) && /onRetry/.test(logica));
  }

  /* --- propoziția de refuz nu-i mai spune clientului „angajat" ---------- */

  for (const f of ['Administrare Comenzi.dc.html', 'Conturi.dc.html']) {
    const { logica } = parti(f);
    const i = logica.indexOf('deniedLine:');
    const fraza = logica.slice(i, i + 900);
    check(f + ':deniedLine', f.replace('.dc.html', '') + ': refuzul vorbește pe limba rolului',
      /authRole\(S\.auth\) === "client"/.test(fraza)
      && /cont de client/.test(fraza) && /cont de angajat/.test(fraza));
  }
}

/* ================================== Intrare.dc.html · deschiderea cu cod == */

console.log('\n=== Intrare.dc.html · deschiderea cu cod ===');
{
  const { logica } = parti('Intrare.dc.html');

  // Pagina de intrare se poate rula aici, ca și cea a clientului: îi dăm
  // ajutorul comun `gp` și o clasă de bază falsă, apoi îi cerem chiar
  // `renderVals()` — adică exact ce ajunge pe ecranul tabletei din laborator.
  const fereastraIntrare = {
    document: { hidden: false, documentElement: { setAttribute() {} }, addEventListener() {}, removeEventListener() {} },
    setInterval, clearInterval, fetch: async () => { throw new Error('fără rețea în probă'); },
    location: { origin: 'http://127.0.0.1:8100', hostname: '127.0.0.1', protocol: 'http:', port: '8100', pathname: '/Intrare.dc.html', search: '' },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    console,
  };
  new Function('window', 'globalThis', cite('shared/gp-api.js'))(fereastraIntrare, fereastraIntrare);
  const gpi = fereastraIntrare.gp;

  // Serverul nu e chemat: codul e primit ca bun, iar cele trei liste vin de
  // aici. Așa se vede dacă pagina le cere după deschiderea cu cod sau dacă
  // așteaptă degeaba o reîncărcare pe care nimeni nu i-o face.
  const AZI = gpi.today();
  const MAINE = gpi.days(2)[1].iso;
  let cerute = [];
  gpi.panelUnlock = async () => ({ username: 'panou', name: 'Panou laborator', role: 'angajat' });
  gpi.get = async (cale) => {
    cerute.push(cale);
    if (cale.startsWith('/orders')) {
      return { orders: [
        { id: 1, ref: '#1001', customerName: 'Cafeneaua Test', fulfillment: 'Livrare', day: AZI,
          slot: '06:30', zone: 'Sector 1', status: 'În așteptare', total: 100,
          items: [{ productId: 1, name: 'Croissant cu unt', qty: 12, unitPrice: 3.49 }] },
        { id: 2, ref: '#1002', customerName: 'Cofetăria Alta', fulfillment: 'Livrare', day: AZI,
          slot: '08:00', zone: 'Sector 2', status: 'Predată', total: 50,
          items: [{ productId: 1, name: 'Croissant cu unt', qty: 4, unitPrice: 3.49 }] },
        { id: 3, ref: '#1003', customerName: 'Bistro Mâine', fulfillment: 'Ridicare', day: MAINE,
          slot: '09:00', zone: 'Sector 3', status: 'În așteptare', total: 30,
          items: [{ productId: 1, name: 'Croissant cu unt', qty: 6, unitPrice: 3.49 }] },
      ] };
    }
    if (cale.startsWith('/routes')) {
      return { stops: [
        { id: 1, ref: '#1001', customerName: 'Cafeneaua Test', fulfillment: 'Livrare', day: AZI,
          zone: 'Sector 1', status: 'În așteptare', routeSeq: 0, items: [] },
        { id: 2, ref: '#1002', customerName: 'Cofetăria Alta', fulfillment: 'Livrare', day: AZI,
          zone: 'Sector 2', status: 'Predată', routeSeq: 1, items: [] },
      ] };
    }
    if (cale.startsWith('/stock/moves')) return { moves: [{ id: 1 }, { id: 2 }, { id: 3 }] };
    return null;
  };

  // Blocul <script type="text/x-dc"> al paginii, scos din sursă și rulat.
  const corpIntrare = logica.slice(logica.indexOf('>') + 1, logica.lastIndexOf('</script>'));
  class DCLogicFalsIntrare {
    constructor(props) { this.props = props || {}; }
    setState(x) { this.state = Object.assign({}, this.state, typeof x === 'function' ? x(this.state) : x); }
  }
  let ComponentIntrare = null;
  try {
    ComponentIntrare = new Function('gp', 'DCLogic', 'window', 'document', 'localStorage', 'history',
      corpIntrare + '\n;return Component;')(
      gpi, DCLogicFalsIntrare, fereastraIntrare, fereastraIntrare.document,
      fereastraIntrare.localStorage, { replaceState() {} });
  } catch (e) {
    console.log('  (pagina nu a putut fi încărcată: ' + e.message + ')');
  }
  check('Intrare.dc.html:383', 'blocul de logică al paginii de intrare se poate rula în probă',
    typeof ComponentIntrare === 'function');

  if (typeof ComponentIntrare === 'function') {
    const tableta = new ComponentIntrare({});
    tableta.props = {};
    tableta.state = Object.assign({}, tableta.state, { loaded: true, code: '4321' });

    // Înainte de cod nu are de unde ști cifrele, și e corect să nu le inventeze.
    const inainte = tableta.renderVals();
    check('Intrare.dc.html:383', 'până la deschidere pagina nu inventează cifre',
      inainte.unlocked === false && inainte.panels.every((p) => p.stat === '—'),
      'unlocked=' + inainte.unlocked);

    // Codul corect: exact ce apasă brutarul pe tabletă, o singură dată.
    await inainte.onUnlock();
    await new Promise((r) => setTimeout(r, 0));
    await new Promise((r) => setTimeout(r, 0));

    const dupa = tableta.renderVals();
    check('Intrare.dc.html:383', 'codul corect deschide panourile',
      dupa.unlocked === true);
    // Miezul defectului: cifrele se cereau numai la încărcarea paginii, deci
    // pe drumul cu cod nu se cereau niciodată.
    check('Intrare.dc.html:383', 'deschiderea cu cod cere cifrele de la server',
      cerute.some((c) => c.startsWith('/orders')) && cerute.some((c) => c.startsWith('/routes'))
        && cerute.some((c) => c.startsWith('/stock/moves')),
      'cerute: ' + JSON.stringify(cerute));
    check('Intrare.dc.html:383', 'niciun card nu mai rămâne cu „—” după codul corect',
      dupa.panels.every((p) => p.stat !== '—'),
      dupa.panels.map((p) => p.role + '=' + p.stat).join(', '));
    check('Intrare.dc.html:383', 'niciun card nu mai cere autentificare celui care tocmai s-a autentificat',
      dupa.panels.every((p) => !/cere autentificare/.test(p.statLabel)),
      dupa.panels.map((p) => p.role + ': ' + p.statLabel).join(' | '));
    // Și cifrele sunt cele trimise de server, nu niște zerouri liniștitoare.
    check('Intrare.dc.html:383', 'cifrele de pe carduri sunt cele venite de la server',
      dupa.panels[0].stat === '1' && dupa.panels[1].stat === '1'
        && dupa.panels[2].stat === '3' && dupa.panels[3].stat === '3',
      dupa.panels.map((p) => p.stat).join('/'));

    // A doua oară, după „Blochează panoul”: același drum, aceeași așteptare.
    cerute = [];
    dupa.onLock();
    tableta.setState({ stats: null, code: '4321' });
    const dinNou = tableta.renderVals();
    await dinNou.onUnlock();
    await new Promise((r) => setTimeout(r, 0));
    await new Promise((r) => setTimeout(r, 0));
    const aDouaOara = tableta.renderVals();
    check('Intrare.dc.html:383', 'după blocare și redeschidere cifrele revin, nu „—”',
      aDouaOara.unlocked === true && aDouaOara.panels.every((p) => p.stat !== '—'),
      aDouaOara.panels.map((p) => p.stat).join('/'));
  }
}

/* ================================ Intrare.dc.html · cifrele de pe carduri == */

console.log('\n=== Intrare.dc.html · cardul căruia nu i-a venit cifra ===');
{
  const { logica } = parti('Intrare.dc.html');

  // Cele patru cifre se pot chiar calcula aici: scoatem din sursă bucata care
  // le produce, împreună cu lista de carduri, și le rulăm pe stări făcute de
  // mână. O probă care ar căuta doar un text din pagină ar trece și peste o
  // reparație care pune liniuța pe cardul greșit.
  const fnKm = /\n  km\(a, b\) \{([\s\S]*?)\n  \}/.exec(logica);
  const bucataCifre = /    const stops = \(st && st\.stops\) \|\| \[\];[\s\S]*?\n    const lbl = .*/.exec(logica);
  const bucataPanels = /\n      panels: (\[[\s\S]*?\n      \]),\n/.exec(logica);

  check('Intrare.dc.html:330', 'cifrele de pe carduri se pot citi din sursă',
    !!(fnKm && bucataCifre && bucataPanels));

  if (fnKm && bucataCifre && bucataPanels) {
    const km = new Function('a', 'b', fnKm[1]);
    const carduri = (stats) => new Function('gp', 'HQ_OF', 'TODAY_ISO', 'S',
      'const st = S.stats;\n' + bucataCifre[0] + '\n    return ' + bucataPanels[1] + ';'
    ).call({ km },
      // `qty` și `qtyLabel` sunt cele adevărate din shared/gp-api.js, nu niște
      // aproximări: cantitățile zecimale se pierdeau tocmai fiindcă pagina le
      // citea altfel decât credea proba.
      { zonePoint: () => ({ lat: 46.77, lng: 23.60 }), done: () => 'Predată', ...gpCantitati() },
      () => ({ lat: 46.77, lng: 23.59 }),
      '2026-08-29',
      { stats, auth: null });

    const ZI = '2026-08-29';
    const intregi = {
      orders: [
        { date: ZI, status: 'La cuptor', zone: 'Centru', items: [{ q: 12 }] },
        { date: ZI, status: 'Predată', zone: 'Centru', items: [{ q: 4 }] },
      ],
      stops: [
        { date: ZI, status: 'La cuptor', zone: 'Centru', lat: 46.78, lng: 23.61 },
        { date: ZI, status: 'Predată', zone: 'Mărăști', lat: 46.79, lng: 23.63 },
      ],
      moves: 7,
    };

    // Când au răspuns toate trei, cardurile arată cifrele — altfel „reparația"
    // ar putea fi pur și simplu o liniuță pusă peste tot.
    const bune = carduri(intregi);
    check('Intrare.dc.html:330', 'cu toate trei cererile reușite, cardurile arată cifrele',
      bune[0].stat === '1' && bune[1].stat === '1' && bune[2].stat === '7' && bune[3].stat === '2',
      bune.map((p) => p.stat).join(' / '));
    check('Intrare.dc.html:330', 'cu toate trei cererile reușite, etichetele sunt cele obișnuite',
      bune[0].statLabel === '12 bucăți de făcut azi' &&
      bune[2].statLabel === 'mișcări înregistrate azi' &&
      bune[3].statLabel === 'comenzi în registrul pe 7 zile',
      bune.map((p) => p.statLabel).join(' / '));

    // Miezul defectului: pică numai /stock/moves. Cardul „Depozit" spunea „0
    // mișcări înregistrate azi" într-o zi în care depozitul lucrase de
    // dimineață, iar administratorul pleca de la ecran fără să verifice.
    const faraMiscari = carduri(Object.assign({}, intregi, { moves: null }));
    check('Intrare.dc.html:330', 'cererea picată de mișcări nu mai raportează „0" mișcări',
      faraMiscari[2].stat === '—', 'a ieșit ' + faraMiscari[2].stat);
    check('Intrare.dc.html:330', 'cardul fără cifră nu mai păstrează eticheta obișnuită',
      faraMiscari[2].statLabel !== 'mișcări înregistrate azi', faraMiscari[2].statLabel);
    check('Intrare.dc.html:330', 'omul autentificat nu e trimis să se autentifice din nou',
      faraMiscari[2].statLabel !== 'cere autentificare', faraMiscari[2].statLabel);
    check('Intrare.dc.html:330', 'celelalte trei carduri își păstrează cifrele venite',
      faraMiscari[0].stat === '1' && faraMiscari[1].stat === '1' && faraMiscari[3].stat === '2',
      faraMiscari.map((p) => p.stat).join(' / '));

    // Simetric, pe fiecare dintre celelalte două cereri.
    const faraComenzi = carduri(Object.assign({}, intregi, { orders: null }));
    check('Intrare.dc.html:330', 'cererea picată de comenzi lasă liniuță pe „Laborator" și „Administrare"',
      faraComenzi[0].stat === '—' && faraComenzi[3].stat === '—',
      faraComenzi.map((p) => p.stat).join(' / '));
    check('Intrare.dc.html:330', 'cererea picată de comenzi nu strică opririle și mișcările',
      faraComenzi[1].stat === '1' && faraComenzi[2].stat === '7',
      faraComenzi.map((p) => p.stat).join(' / '));
    check('Intrare.dc.html:330', 'cardul „Laborator" fără comenzi nu mai promite „0 bucăți de făcut azi"',
      faraComenzi[0].statLabel !== '0 bucăți de făcut azi', faraComenzi[0].statLabel);

    const faraOpriri = carduri(Object.assign({}, intregi, { stops: null }));
    check('Intrare.dc.html:330', 'cererea picată de rută lasă liniuță pe „Foaia de rută"',
      faraOpriri[1].stat === '—', 'a ieșit ' + faraOpriri[1].stat);
    check('Intrare.dc.html:330', 'cardul de rută nu mai anunță „0 opriri · 0.0 km"',
      !/opriri/.test(faraOpriri[1].statLabel), faraOpriri[1].statLabel);
    check('Intrare.dc.html:330', 'cererea picată de rută nu strică comenzile și mișcările',
      faraOpriri[0].stat === '1' && faraOpriri[2].stat === '7' && faraOpriri[3].stat === '2',
      faraOpriri.map((p) => p.stat).join(' / '));

    // Cazul vechi, care mergea deja și trebuie să meargă mai departe: fără
    // sesiune nu vine niciuna dintre cele trei liste.
    const faraSesiune = carduri(null);
    check('Intrare.dc.html:330', 'fără sesiune, toate patru cardurile stau pe liniuță',
      faraSesiune.every((p) => p.stat === '—'), faraSesiune.map((p) => p.stat).join(' / '));
    check('Intrare.dc.html:330', 'fără sesiune, cardurile cer autentificarea',
      faraSesiune.every((p) => p.statLabel === 'cere autentificare'),
      faraSesiune.map((p) => p.statLabel).join(' / '));
  }
}

/* ==================== Intrare.dc.html · ieșirea din panourile de lucru === */

console.log('\n=== Intrare.dc.html — ieșirea din panou nu lasă pagina cu sesiunea veche ===');
{
  const { logica } = parti('Intrare.dc.html');

  // Scoatem chiar cele două ieșiri din sursă și le rulăm, ca proba să nu se
  // uite doar la un cuvânt scris undeva.
  const fnLock = /\n      onLock: \(\) => \{([\s\S]*?)\n      \},\n/.exec(logica);
  const fnLogout = /\n      onLogout: \(\) => \{([\s\S]*?)\n      \}\n    \};/.exec(logica);
  check('Intrare.dc.html:524', '„Blochează panoul" și „Ieși" se pot citi din sursă',
    !!(fnLock && fnLogout));

  if (fnLock && fnLogout) {
    // Starea mixtă e reală: administratorul era conectat, a apăsat „Panouri de
    // lucru" și a deschis cu codul tabletei, iar POST /auth/panel i-a înlocuit
    // sesiunea de pe server cu cea a contului de sistem „panou". Pagina, însă,
    // încă ține numele lui în `auth`.
    const stareMixta = () => ({
      auth: { u: 'testadmin', name: 'Administrator Test', role: 'admin' },
      open: true, view: 'staff', code: '', cerr: '',
    });
    // Runtime-ul dc face merge, nu replace, la setState.
    const pagina = (stare) => ({
      state: stare,
      setState(patch) { Object.assign(this.state, patch); },
    });

    let iesiriPeServer = 0;
    const gpFals = { logout: () => { iesiriPeServer++; return Promise.resolve(); } };

    const laBlocare = pagina(stareMixta());
    new Function('gp', 'return function () {' + fnLock[1] + '\n};')(gpFals).call(laBlocare);

    check('Intrare.dc.html:524', '„Blochează panoul" închide sesiunea și pe server',
      iesiriPeServer === 1, 'apeluri: ' + iesiriPeServer);
    check('Intrare.dc.html:524', 'după „Blochează panoul" pagina nu mai spune „conectat" cu numele vechi',
      !laBlocare.state.auth, JSON.stringify(laBlocare.state.auth));
    check('Intrare.dc.html:524', 'după „Blochează panoul" se revine la ecranul de intrare',
      laBlocare.state.open === false && laBlocare.state.view === 'client',
      JSON.stringify({ open: laBlocare.state.open, view: laBlocare.state.view }));

    const laIesire = pagina(stareMixta());
    new Function('gp', 'return function () {' + fnLogout[1] + '\n};')(gpFals).call(laIesire);
    // Starea se schimbă abia după ce răspunde serverul, în `finally`.
    await new Promise((gata) => setTimeout(gata, 0));

    check('Intrare.dc.html:524', '„Ieși" închide sesiunea și pe server',
      iesiriPeServer === 2, 'apeluri: ' + iesiriPeServer);
    check('Intrare.dc.html:524', 'după „Ieși" nu mai rămâne nicio sesiune în pagină',
      !laIesire.state.auth, JSON.stringify(laIesire.state.auth));
    check('Intrare.dc.html:524', 'după „Ieși" grila de panouri nu mai rămâne pe ecran',
      laIesire.state.open === false,
      JSON.stringify({ open: laIesire.state.open, view: laIesire.state.view }));
  }
}

/* ============================== Intrare.dc.html · parola nouă din e-mail == */

console.log('\n=== Intrare.dc.html · linkul de parolă nouă pe un dispozitiv cu sesiune ===');
{
  const { marcaj, logica } = parti('Intrare.dc.html');

  // Linkul din e-mail se deschide de cele mai multe ori pe telefon — adică
  // exact acolo unde omul are deja o sesiune deschisă, uneori pe alt cont din
  // casă. Pagina cerea `!S.auth` ca să deseneze formularul de parolă nouă,
  // iar `sc-if` fals nu ascunde copiii, îi lasă nerandați: formularul clipea
  // până răspundea gp.me() și dispărea, cu tokenul deja scos din adresă.
  // Rulăm chiar blocul de logică al paginii, ca proba să vadă ce ajunge pe
  // ecran, nu doar ce scrie în sursă.
  const fereastraIntrare = {
    document: { documentElement: { setAttribute() {} }, addEventListener() {}, removeEventListener() {} },
    setInterval, clearInterval, fetch: async () => { throw new Error('fără rețea în probă'); },
    location: { origin: 'http://127.0.0.1:8100', hostname: '127.0.0.1', protocol: 'http:', port: '8100',
      search: '', pathname: '/Intrare.dc.html' },
    history: { replaceState() {} },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    console,
  };
  new Function('window', 'globalThis', cite('shared/gp-api.js'))(fereastraIntrare, fereastraIntrare);
  const gpi = fereastraIntrare.gp;

  const corpIntrare = logica.slice(logica.indexOf('>') + 1, logica.lastIndexOf('</script>'));
  class DCLogicFalsIntrare {
    constructor(props) { this.props = props || {}; }
    setState(x) { this.state = Object.assign({}, this.state, typeof x === 'function' ? x(this.state) : x); }
  }
  let ComponentIntrare = null;
  try {
    ComponentIntrare = new Function('gp', 'DCLogic', 'window', 'document', 'location', 'history', 'localStorage',
      corpIntrare + '\n;return Component;')(
      gpi, DCLogicFalsIntrare, fereastraIntrare, fereastraIntrare.document, fereastraIntrare.location,
      fereastraIntrare.history, fereastraIntrare.localStorage);
  } catch (e) {
    console.log('  (pagina nu a putut fi încărcată: ' + e.message + ')');
  }
  check('Intrare.dc.html:579', 'blocul de logică al paginii de intrare se poate rula în probă',
    typeof ComponentIntrare === 'function');

  /** Ce ar vedea omul pe ecran, pentru o stare dată. */
  const ecranIntrare = (stare) => {
    if (typeof ComponentIntrare !== 'function') return {};
    const c = new ComponentIntrare({});
    c.state = Object.assign({}, c.state, stare);
    return c.renderVals();
  };

  const CONT = { u: 'testclient', name: 'Cafeneaua Test', role: 'client' };
  const TOKEN = 'a'.repeat(64);

  const dinEmail = ecranIntrare({ loaded: true, rtoken: TOKEN, auth: CONT });
  check('Intrare.dc.html:579', 'formularul de parolă nouă apare și pe dispozitivul cu sesiune deschisă',
    dinEmail.showReset === true, 'showReset=' + dinEmail.showReset);
  check('Intrare.dc.html:579', 'caseta e cea de scris parola, nu ecranul de „gata”',
    dinEmail.resetOpen === true && dinEmail.resetDone === false);
  check('Intrare.dc.html:579', 'cardul „conectat” se dă la o parte cât ține resetarea',
    dinEmail.showSession === false, 'showSession=' + dinEmail.showSession);
  // Altfel omul crede că schimbă parola contului deschis pe telefon — care
  // poate fi al altcuiva din casă.
  check('Intrare.dc.html:579', 'pagina spune al cui e contul deschis pe dispozitiv',
    typeof dinEmail.resetNote === 'string' && dinEmail.resetNote.includes(CONT.name),
    JSON.stringify(dinEmail.resetNote));

  const faraSesiune = ecranIntrare({ loaded: true, rtoken: TOKEN, auth: null });
  check('Intrare.dc.html:579', 'fără sesiune pe dispozitiv, formularul apare ca înainte',
    faraSesiune.showReset === true && faraSesiune.showSession === false);
  check('Intrare.dc.html:579', 'și nu are ce notă să scrie despre vreo sesiune',
    faraSesiune.resetNote === '', JSON.stringify(faraSesiune.resetNote));

  const doarSesiune = ecranIntrare({ loaded: true, auth: CONT });
  check('Intrare.dc.html:579', 'fără link în adresă rămâne cardul de sesiune, nu formularul',
    doarSesiune.showSession === true && doarSesiune.showReset === false);
  const renuntat = ecranIntrare({ loaded: true, rtoken: '', rdone: false, auth: CONT });
  check('Intrare.dc.html:579', 'după „Renunț” se vede iar cardul de sesiune',
    renuntat.showSession === true && renuntat.showReset === false);

  check('Intrare.dc.html:579', 'marcajul cere chiar steagurile care țin cont de resetare',
    marcaj.includes('<sc-if value="{{ showSession }}"') && marcaj.includes('{{ resetNote }}'));
  // Serverul șterge toate sesiunile contului odată cu parola veche, deci
  // cardul „conectat” ar trimite omul cu un cookie deja mort.
  check('Intrare.dc.html:579', 'parola pusă cu succes uită și sesiunea închisă de server',
    /rdone: true[^}]*auth: null/.test(logica));
}

/* ======================================================== Client.dc.html == */

console.log('\n=== Client.dc.html ===');
{
  const { logica } = parti('Client.dc.html');

  // Reîmprospătarea comună se oprește când fila e ascunsă și nu lasă două ture
  // să se suprapună; un setInterval propriu nu face niciuna dintre cele două.
  check('Client.dc.html:302', 'pagina folosește reîmprospătarea comună gp.poll()',
    /gp\.poll\(\(\) => this\.pull\(\)/.test(logica));
  check('Client.dc.html:302', 'nu mai există un cronometru propriu pentru reîmprospătare',
    !/setInterval\(\(\) => this\.pull\(\)/.test(logica));
  check('Client.dc.html:302', 'reîmprospătarea se oprește la ieșirea din pagină',
    /componentWillUnmount\(\)\s*\{[\s\S]{0,200}this\.stopPoll\(\)/.test(logica));
  // Pagina clientului se poate chiar rula aici: ca să afle ce ajunge pe ecran,
  // blocul ei de logică nu are nevoie decât de ajutorul comun `gp` și de clasa
  // de bază a runtime-ului. I le dăm și îi cerem chiar `renderVals()` — adică
  // ce citește clientul — pentru cele două locuri în care pagina spunea altceva
  // decât adevărul: contul redenumit și poziția pe ruta zilei.
  const fereastraClient = {
    document: { hidden: false, addEventListener() {}, removeEventListener() {} },
    setInterval, clearInterval, fetch: async () => { throw new Error('fără rețea în probă'); },
    location: { origin: 'http://127.0.0.1:8100', hostname: '127.0.0.1', protocol: 'http:', port: '8100' },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    console,
  };
  new Function('window', 'globalThis', cite('shared/gp-api.js'))(fereastraClient, fereastraClient);
  const gpc = fereastraClient.gp;

  // Blocul <script type="text/x-dc"> al paginii, scos din sursă și rulat.
  const corpClient = logica.slice(logica.indexOf('>') + 1, logica.lastIndexOf('</script>'));
  class DCLogicFals {
    constructor(props) { this.props = props || {}; }
    setState(x) { this.state = Object.assign({}, this.state, typeof x === 'function' ? x(this.state) : x); }
  }
  let ComponentClient = null;
  try {
    ComponentClient = new Function('gp', 'DCLogic', 'window', 'document', 'localStorage',
      corpClient + '\n;return Component;')(
      gpc, DCLogicFals, fereastraClient, fereastraClient.document, fereastraClient.localStorage);
  } catch (e) {
    console.log('  (pagina nu a putut fi încărcată: ' + e.message + ')');
  }
  check('Client.dc.html:373', 'blocul de logică al paginii clientului se poate rula în probă',
    typeof ComponentClient === 'function');

  /** Ce ar vedea omul pe ecran, pentru o stare dată. */
  const ecran = (stare, props) => {
    if (typeof ComponentClient !== 'function') return {};
    const c = new ComponentClient(props || {});
    c.props = props || {};
    c.state = Object.assign({}, c.state, stare);
    return c.renderVals();
  };

  const AZI = gpc.today();
  const IERI = new Date(Date.parse(AZI + 'T00:00:00') - 86400000).toISOString().slice(0, 10);
  const comanda = (extra) => gpc.toPageOrder(Object.assign({
    id: 1, ref: '#1008', customerName: 'Cafeneaua Test', fulfillment: 'Livrare',
    day: AZI, slot: '06:30', zone: 'Sector 1', address: 'Str. Lipscani 12',
    status: 'În așteptare', total: 118.4, routeSeq: null,
    items: [{ productId: 1, name: 'Croissant cu unt', qty: 12, unitPrice: 3.49, variant: 'copt' }],
  }, extra || {}));
  // Contul a fost redenumit din Conturi; comanda păstrează numele înghețat la
  // creare, fiindcă `orders.customer_name` nu se rescrie niciodată.
  const CONT_REDENUMIT = { u: 'testclient', name: 'Cafeneaua Test SRL', role: 'client' };

  const azi = ecran({ loaded: true, auth: CONT_REDENUMIT, orders: [comanda({ routeSeq: 8 })] });
  check('Client.dc.html:373', 'comanda de azi rămâne pe ecran după ce contul a fost redenumit',
    azi.hasOrder === true && azi.noOrder === false, 'hasOrder=' + azi.hasOrder);

  const istoric = ecran({
    loaded: true, auth: CONT_REDENUMIT,
    orders: [comanda({ id: 2, day: IERI, status: 'Predată' })],
  });
  check('Client.dc.html:373', 'istoricul nu dispare după ce contul a fost redenumit',
    istoric.hasPast === true && istoric.past.length === 1, 'past=' + istoric.past.length);

  // Previzualizarea de admin e singurul loc în care numele mai alege ceva:
  // acolo serverul chiar trimite comenzile tuturor clienților.
  const previz = ecran({
    loaded: true, auth: { u: 'testadmin', name: 'Administrator Test', role: 'admin' },
    pick: 'Cafeneaua Test',
    orders: [comanda({}), comanda({ id: 3, customerName: 'Cofetăria Alta' })],
  });
  check('Client.dc.html:373', 'previzualizarea de admin arată tot numai clientul ales',
    previz.hasOrder === true && previz.hasMore === false && previz.clientName === 'Cafeneaua Test',
    'hasMore=' + previz.hasMore + ' clientName=' + previz.clientName);

  /* --- cererea respinsă și motivul ei --- */
  // Pagina cerea doar `?status=nou`, deși serverul salvează motivul refuzului
  // de când există butonul. Clientul vedea cererea dispărând și suna să
  // întrebe ce s-a întâmplat.
  check('Client.dc.html:385', 'pagina cere de la server și cererile respinse',
    /\/requests\?status=nou,respinsa/.test(logica));
  check('shared/gp-api.js:273', 'motivul refuzului ajunge în forma citită de pagină',
    /reason:\s*r\.rejectReason\s*\|\|\s*''/.test(cite('shared/gp-api.js')));

  const cerere = (extra) => gpc.toPageRequest(Object.assign({
    id: 7, customerName: 'Cafeneaua Test', day: AZI, slot: '06:30',
    zone: 'Sector 1', address: 'Str. Lipscani 12', status: 'nou', total: 64,
    createdAt: AZI + ' 05:00:00',
    items: [{ productId: 1, name: 'Croissant cu unt', qty: 12, unitPrice: 3.49, variant: 'copt' }],
  }, extra || {}));

  const refuz = ecran({
    loaded: true, auth: CONT_REDENUMIT, orders: [],
    requests: [cerere({ status: 'respinsa', rejectReason: 'Nu avem capacitate în ziua cerută.' })],
  });
  check('Client.dc.html:459', 'cererea respinsă apare pe ecranul clientului',
    refuz.hasRejected === true && refuz.rejected.length === 1, 'hasRejected=' + refuz.hasRejected);
  check('Client.dc.html:459', 'motivul refuzului se vede, nu se pierde',
    refuz.rejected[0] && refuz.rejected[0].reason === 'Nu avem capacitate în ziua cerută.',
    JSON.stringify(refuz.rejected && refuz.rejected[0] && refuz.rejected[0].reason));
  // Un refuz fără motiv scris în bază nu are voie să lase un rând gol pe ecran.
  const refuzMut = ecran({
    loaded: true, auth: CONT_REDENUMIT, orders: [],
    requests: [cerere({ status: 'respinsa', rejectReason: null })],
  });
  check('Client.dc.html:459', 'refuzul fără motiv scris spune asta pe față',
    refuzMut.rejected[0] && /Sună-ne/.test(refuzMut.rejected[0].reason),
    JSON.stringify(refuzMut.rejected && refuzMut.rejected[0] && refuzMut.rejected[0].reason));
  // Cererea refuzată nu e o comandă în așteptare: n-are voie nici să se
  // amestece în cartonașul galben, nici să facă pagina să pretindă că omul are
  // ceva pe drum.
  check('Client.dc.html:459', 'refuzul nu se amestecă printre cererile în așteptare',
    refuz.hasPending === false && refuz.noOrder === true,
    'hasPending=' + refuz.hasPending + ' noOrder=' + refuz.noOrder);
  const inAsteptare = ecran({
    loaded: true, auth: CONT_REDENUMIT, orders: [], requests: [cerere({})],
  });
  check('Client.dc.html:459', 'cererea încă neconfirmată rămâne unde era',
    inAsteptare.hasPending === true && inAsteptare.hasRejected === false,
    'hasPending=' + inAsteptare.hasPending + ' hasRejected=' + inAsteptare.hasRejected);

  /* --- insigna modului de onorare, derivată din Setări --------------------- */
  // Culoarea venea dintr-o hartă cu trei nume scrise în cod, într-o aplicație în
  // care modurile de onorare se editează din Setări: un mod nou, legitim, cădea
  // pe insigna neutră și rămânea gri pe vecie. Regula se ia din pagină și se
  // rulează aici, cu liste de moduri făcute anume — inclusiv una în care apare
  // un mod pe care harta veche n-avea de unde să-l știe.
  {
    const CULOARE = 'shared/gp-api.js';
    check('shared/gp-api.js:433', 'clientul servește lista modurilor fără mașină',
      /pickupFulfillments:\s*\(\)\s*=>/.test(cite(CULOARE)));
    check('api/routes/products.php:52', 'serverul o trimite în /lists',
      /'fulfillmentPickup'\s*=>\s*pickup_fulfillments\(\)/.test(cite('api/routes/products.php')));

    for (const pagina of ['Administrare Comenzi.dc.html', 'Panou Laborator.dc.html']) {
      const { logica } = parti(pagina);
      check('Administrare Comenzi.dc.html:899',
        pagina + ': culoarea nu mai e o hartă de nume scrise în cod',
        !/const CH = \{/.test(logica));

      const sursa = (logica.match(/const CH = \(chan\) => \{[\s\S]*?\n\};/) || [null])[0];
      check('Administrare Comenzi.dc.html:899', pagina + ': regula de culoare se poate scoate din pagină',
        !!sursa);
      if (!sursa) continue;

      const cu = (lista) => new Function('gp', sursa + '\nreturn CH;')({
        pickupFulfillments: () => lista.slice(),
      });
      const IMPLICIT = ['Angro', 'Ridicare'];

      check('Administrare Comenzi.dc.html:899', pagina + ': ce își ia clientul singur e miere',
        cu(IMPLICIT)('Ridicare') === 'h-badge h-badge--honey', cu(IMPLICIT)('Ridicare'));
      check('Administrare Comenzi.dc.html:899', pagina + ': ce pleacă cu duba e accent',
        cu(IMPLICIT)('Livrare') === 'h-badge h-badge--accent', cu(IMPLICIT)('Livrare'));

      // Proba care pică pe harta veche: două moduri adăugate din Setări, pe care
      // niciun cod n-are de unde să le știe. Harta de trei nume le dădea gri.
      const LARGIT = IMPLICIT.concat(['Ridicare din depozit']);
      check('Administrare Comenzi.dc.html:899',
        pagina + ': un mod de ridicare adăugat din Setări primește culoarea lui',
        cu(LARGIT)('Ridicare din depozit') === 'h-badge h-badge--honey',
        cu(LARGIT)('Ridicare din depozit'));
      check('Administrare Comenzi.dc.html:899',
        pagina + ': un mod de livrare adăugat din Setări primește culoarea lui',
        cu(IMPLICIT)('Curier rapid') === 'h-badge h-badge--accent',
        cu(IMPLICIT)('Curier rapid'));

      // Și ce nu se știe rămâne nespus: lista nevenită de la server nu se ghicește.
      check('Administrare Comenzi.dc.html:899',
        pagina + ': fără lista de la server insigna rămâne neutră',
        cu([])('Livrare') === 'h-badge', cu([])('Livrare'));
      check('Administrare Comenzi.dc.html:899',
        pagina + ': comanda fără mod de onorare nu primește culoare',
        cu(IMPLICIT)('') === 'h-badge', cu(IMPLICIT)(''));
    }
  }


  /* --- „În lucru" / „Gata" pe ruta șoferului ------------------------------- */
  // Șoferul vedea toate comenzile zilei, dar nu și care sunt gata de urcat: în
  // pagină exista un singur semn de etapă, „livrată sau nu". Regula nouă se ia
  // din sursă și se rulează aici, inclusiv pe o listă de etape reordonată din
  // Setări — cazul care deosebește regula corectă de scurtătura „penultima din
  // listă".
  {
    const { marcaj, logica } = parti('Ruta Sofer.dc.html');

    const sursa = (logica.match(/const flow = gp\.flow\(\);[\s\S]*?\n {4}\};/) || [null])[0];
    check('Ruta Sofer.dc.html:427', 'regula de etapă se poate scoate din pagină', !!sursa);

    const eLabel = (logica.match(/stageLabel:\s*(.+),\n/) || [null, null])[1];
    const eCls = (logica.match(/stageCls:\s*(.+),\n/) || [null, null])[1];
    check('Ruta Sofer.dc.html:427', 'insigna are text și culoare pe fiecare oprire',
      !!eLabel && !!eCls);

    if (sursa && eLabel && eCls) {
      const regula = (etape, done) => new Function('gp', sursa + '\nreturn etapaGata;')({
        flow: () => etape.slice(), done: () => done,
      });
      const eticheta = new Function('done', 'gata', 'return (' + eLabel + ');');
      const culoare = new Function('gata', 'return (' + eCls + ');');

      const OBISNUIT = ['În așteptare', 'La dospit', 'În cuptor', 'La ambalare', 'Predată'];
      const r = regula(OBISNUIT, 'Predată');

      check('Ruta Sofer.dc.html:427', 'comanda ajunsă la ambalare e gata',
        r('La ambalare') === true);
      check('Ruta Sofer.dc.html:427', 'comanda din cuptor e încă în lucru',
        r('În cuptor') === false);
      check('Ruta Sofer.dc.html:427', 'comanda abia primită e în lucru',
        r('În așteptare') === false);

      // Cazul care pică pe scurtătura „flow[flow.length - 2]": `guard_status_list`
      // impune doar ca etapele în uz să nu fie scoase, nu că etapa de încheiere e
      // ultima din listă. Un administrator poate reordona lista din Setări.
      const REORDONAT = ['În așteptare', 'La dospit', 'Predată', 'În cuptor', 'La ambalare'];
      const rr = regula(REORDONAT, 'Predată');
      check('Ruta Sofer.dc.html:427', 'cu lista reordonată, etapa dinaintea încheierii e „gata"',
        rr('La dospit') === true, String(rr('La dospit')));
      check('Ruta Sofer.dc.html:427', 'cu lista reordonată, prima etapă rămâne „în lucru"',
        rr('În așteptare') === false, String(rr('În așteptare')));

      // Ce nu se știe nu se spune.
      check('Ruta Sofer.dc.html:427', 'o etapă necunoscută nu primește insignă',
        r('Ceva scris de mână') === null);
      check('Ruta Sofer.dc.html:427', 'o listă fără etapa de încheiere nu primește insignă',
        regula(['A', 'B', 'C'], 'Predată')('B') === null);
      check('Ruta Sofer.dc.html:427', 'etapa de încheiere pusă prima nu primește insignă',
        regula(['Predată', 'La dospit'], 'Predată')('La dospit') === null);

      check('Ruta Sofer.dc.html:427', 'oprirea deja livrată nu primește a treia insignă',
        eticheta(true, true) === '');
      check('Ruta Sofer.dc.html:427', 'fără verdict nu se afișează nimic',
        eticheta(false, null) === '');
      check('Ruta Sofer.dc.html:427', 'verdictul se scrie „Gata" și „În lucru"',
        eticheta(false, true) === 'Gata' && eticheta(false, false) === 'În lucru');
      check('Ruta Sofer.dc.html:427', 'gata e verde, în lucru e de așteptare',
        /--success/.test(culoare(true)) && /--warning/.test(culoare(false)));
    }

    check('Ruta Sofer.dc.html:86', 'insigna apare în marcaj, lângă „Urgent"',
      /s\.stageLabel[\s\S]{0,200}\{\{ s\.stageCls \}\}[\s\S]{0,80}\{\{ s\.stageLabel \}\}/.test(marcaj));
  }


  /* --- a câta oprire e clientul pe ruta zilei --- */

  const alNoualea = ecran({ loaded: true, auth: CONT_REDENUMIT, orders: [comanda({ routeSeq: 8 })] });
  check('Client.dc.html:399', 'clientul de pe locul 9 nu mai e anunțat drept următoarea oprire',
    !/următoarea oprire/i.test(alNoualea.stopsLabel), alNoualea.stopsLabel);
  check('Client.dc.html:399', 'pagina spune câte opriri sunt înaintea lui',
    alNoualea.stopsNum === '8' && /8 opriri înaintea ta/.test(alNoualea.stopsLabel),
    alNoualea.stopsNum + ' · ' + alNoualea.stopsLabel);
  check('Client.dc.html:399', 'pagina spune a câta oprire e pe ruta de azi',
    /oprirea 9/.test(alNoualea.routeTotal), alNoualea.routeTotal);
  check('Client.dc.html:399', 'pagina nu mai inventează pe unde umblă șoferul',
    !/Șoferul/.test(alNoualea.driverLine), alNoualea.driverLine);

  const prima = ecran({ loaded: true, auth: CONT_REDENUMIT, orders: [comanda({ routeSeq: 0 })] });
  check('Client.dc.html:399', 'prima oprire a zilei e numită prima, nu „următoarea”',
    /prima oprire/i.test(prima.stopsLabel) && /oprirea 1/.test(prima.routeTotal),
    prima.stopsLabel + ' · ' + prima.routeTotal);

  const nearanjata = ecran({ loaded: true, auth: CONT_REDENUMIT, orders: [comanda({ routeSeq: null })] });
  check('Client.dc.html:399', 'fără ordine stabilită, pagina spune asta în loc să numere',
    nearanjata.showRoute === true && /nu e stabilită/i.test(nearanjata.stopsLabel)
      && nearanjata.stopsNum === '—' && nearanjata.routeTotal === '',
    nearanjata.stopsNum + ' · ' + nearanjata.stopsLabel + ' · ' + nearanjata.routeTotal);

  const gata = ecran({ loaded: true, auth: CONT_REDENUMIT, orders: [comanda({ routeSeq: 3, status: 'Predată' })] });
  check('Client.dc.html:399', 'comanda deja livrată e anunțată ca livrată',
    gata.stopsNum === '✓' && /a fost livrată/.test(gata.stopsLabel),
    gata.stopsNum + ' · ' + gata.stopsLabel);

  // Socoteala opririlor nu se mai poate face din comenzile clientului: nici
  // funcția care își construia o rută din ele, nici bara de progres care arăta
  // „0 din 1 opriri făcute” nu mai au ce căuta în pagină.
  check('Client.dc.html:399', 'pagina nu mai construiește o rută din propriile comenzi',
    !/stopsFor\(/.test(logica));
  check('Client.dc.html:399', 'bara de progres nu mai promite o socoteală pe care pagina nu o poate face',
    !parti('Client.dc.html').marcaj.includes('h-progress'));

}

/* ===================================== Jurnal.dc.html · filtrul de zonă == */

console.log('\n=== Jurnal.dc.html · filtrul de zonă ===');
{
  // Blocul de logică al paginii se poate chiar rula: îi dăm un `DCLogic` de
  // mucava și un `gp` care întoarce un jurnal făcut de noi, apoi apăsăm
  // butoanele de zonă exact cum le apasă omul, prin `onClick`.
  const { logica } = parti('Jurnal.dc.html');
  const cod = logica.slice(logica.indexOf('>') + 1, logica.lastIndexOf('</script>'));

  // Zonele reale sunt entitățile scrise de server în audit_log — le citim din
  // PHP, ca proba să nu creadă pe cuvânt o listă scrisă în pagină.
  const php = ['api/lib/auth.php', 'api/lib/settings.php', 'api/routes/auth.php',
    'api/routes/catalog.php', 'api/routes/orders.php', 'api/routes/requests.php',
    'api/routes/settings.php', 'api/routes/stock.php', 'api/routes/users.php']
    .map(cite).join('\n');
  const zoneReale = Array.from(new Set(
    [...php.matchAll(/audit\(\s*'[^']+',\s*'([^']+)'/g)].map((m) => m[1])
  ));
  check('Jurnal.dc.html:188', 'zonele scrise de server se pot citi din PHP',
    zoneReale.length >= 8, zoneReale.join(', '));

  const intrari = zoneReale.map((ent, i) => ({
    id: 900 - i, createdAt: '2026-08-29 09:' + String(10 + i).padStart(2, '0') + ':00',
    user: 'testadmin', role: 'admin', action: ent + '_update', entity: ent, detail: 'Ceva despre ' + ent
  }));

  class Baza {
    constructor(p) { this.props = p || {}; this.state = {}; }
    setState(x) { Object.assign(this.state, typeof x === 'function' ? x(this.state) : x); }
  }
  const gpFals = { get: () => Promise.resolve({ entries: intrari, total: intrari.length, page: 1, pages: 1 }) };
  const nimic = { hidden: false, addEventListener() {}, removeEventListener() {}, documentElement: { setAttribute() {} } };
  const Component = new Function('DCLogic', 'gp', 'window', 'document', 'localStorage', 'location',
    cod + '\n;return Component;')(Baza, gpFals, nimic, nimic, nimic, { href: '', search: '' });

  const pagina = new Component({});
  pagina.state.auth = { u: 'testadmin', name: 'Test', role: 'admin' };
  await pagina.pull(1);

  const butoane = pagina.renderVals().pageFilters.filter((b) => b.label !== 'Toate zonele');
  check('Jurnal.dc.html:188', 'butoanele de zonă acoperă zonele pe care le scrie serverul',
    butoane.length === zoneReale.length, 'sunt ' + butoane.length + ' butoane pentru ' + zoneReale.length + ' zone');
  check('Jurnal.dc.html:188', 'niciun buton de zonă nu arată 0',
    butoane.length > 0 && butoane.every((b) => Number(b.count) > 0),
    butoane.map((b) => b.label + ' ' + b.count).join(' · '));

  const golite = [];
  for (const b of butoane) {
    b.onClick();
    const v = pagina.renderVals();
    if (v.empty || v.groups.length === 0) golite.push(b.label);
  }
  check('Jurnal.dc.html:188', 'niciun buton de zonă nu golește jurnalul apăsat',
    golite.length === 0, 'au golit lista: ' + golite.join(', '));

  pagina.state.page = 'Toate';
  const randuri = pagina.renderVals().groups.flatMap((g) => g.rows);
  const brute = randuri.map((r) => r.page).filter((p) => zoneReale.includes(p));
  check('Jurnal.dc.html:188', 'coloana „Zonă” arată numele zonei, nu eticheta tehnică a serverului',
    randuri.length === intrari.length && brute.length === 0, 'au rămas: ' + brute.join(', '));
  check('Jurnal.dc.html:188', 'fiecare zonă are culoarea ei de etichetă, nu cade pe cea neutră',
    randuri.every((r) => r.pageCls && r.pageCls !== 'h-badge') ||
    randuri.filter((r) => r.pageCls === 'h-badge').length <= 1,
    randuri.map((r) => r.page + '=' + r.pageCls).join(' · '));
}

/* ========================================= Jurnal.dc.html · paginarea == */

console.log('\n=== Jurnal.dc.html · paginarea ===');
{
  const { logica } = parti('Jurnal.dc.html');
  const cod = logica.slice(logica.indexOf('>') + 1, logica.lastIndexOf('</script>'));

  // Un jurnal de 300 de intrări, servit câte 50, exact ca ruta /log.
  const TOT = [];
  for (let i = 0; i < 300; i++) {
    TOT.push({
      id: 1000 - i, createdAt: '2026-08-29 09:00:00', user: 'testadmin', role: 'admin',
      action: 'order_update', entity: 'order', detail: 'Comandă #' + (300 - i)
    });
  }
  const gpFals = {
    get: (cale) => {
      const p = Number((/[?&]page=(\d+)/.exec(cale) || [])[1] || 1);
      return Promise.resolve({
        entries: TOT.slice((p - 1) * 50, p * 50), total: TOT.length, page: p,
        pages: Math.ceil(TOT.length / 50)
      });
    }
  };
  class Baza {
    constructor(pr) { this.props = pr || {}; this.state = {}; }
    setState(x) { Object.assign(this.state, typeof x === 'function' ? x(this.state) : x); }
  }
  const nimic = { hidden: false, addEventListener() {}, removeEventListener() {}, documentElement: { setAttribute() {} } };
  const Component = new Function('DCLogic', 'gp', 'window', 'document', 'localStorage', 'location',
    cod + '\n;return Component;')(Baza, gpFals, nimic, nimic, nimic, { href: '', search: '' });

  const pagina = new Component({});
  pagina.state.auth = { u: 'testadmin', name: 'Test', role: 'admin' };

  await pagina.pull(1);                 // deschiderea paginii
  await pagina.renderVals().onMore();   // „Încarcă mai vechi”
  const dupaPrimaApasare = pagina.state.log.length;
  await pagina.pull(1);                 // tura de la cinci secunde
  check('Jurnal.dc.html:265', 'reîmprospătarea nu dă cursorul înapoi la prima pagină',
    pagina.state.pg === 2, 'cursorul a ajuns la ' + pagina.state.pg);
  check('Jurnal.dc.html:265', 'reîmprospătarea nu aruncă paginile deja aduse',
    pagina.state.log.length === dupaPrimaApasare, pagina.state.log.length + ' din ' + dupaPrimaApasare);

  // A doua apăsare, după reîmprospătare — exact drumul omului care citește.
  const v = pagina.renderVals();
  check('Jurnal.dc.html:265', 'butonul „Încarcă mai vechi” e încă acolo', v.hasMore === true);
  await v.onMore();
  check('Jurnal.dc.html:265', 'a doua apăsare după reîmprospătare chiar aduce pagina a treia',
    pagina.state.log.length === 150, 'sunt ' + pagina.state.log.length + ' intrări în loc de 150');

  // Până la capătul jurnalului, cu o reîmprospătare între apăsări.
  for (let i = 0; i < 5; i++) {
    const vv = pagina.renderVals();
    if (!vv.hasMore) break;
    await vv.onMore();
    await pagina.pull(1);
  }
  check('Jurnal.dc.html:265', 'jurnalul se poate citi până la capăt, nu se oprește la 100 de intrări',
    pagina.state.log.length === 300 && pagina.renderVals().hasMore === false,
    's-a oprit la ' + pagina.state.log.length);

  // Reîmprospătarea comună se oprește pe fila ascunsă și nu lasă două ture să
  // se suprapună; un cronometru propriu nu face niciuna dintre cele două.
  check('Jurnal.dc.html:265', 'pagina folosește reîmprospătarea comună gp.poll()',
    /gp\.poll\(\(\) => this\.pull\(1\)/.test(logica));
  check('Jurnal.dc.html:265', 'nu mai există un cronometru propriu de reîmprospătare',
    !/setInterval\(/.test(logica));
  check('Jurnal.dc.html:265', 'reîmprospătarea se oprește la ieșirea din pagină',
    /componentWillUnmount\(\)\s*\{[\s\S]{0,120}this\.stopPoll\(\)/.test(logica));
}

/* ========================================== Jurnal.dc.html · căutarea == */

console.log('\n=== Jurnal.dc.html · căutarea ===');
{
  const { logica } = parti('Jurnal.dc.html');
  const cod = logica.slice(logica.indexOf('>') + 1, logica.lastIndexOf('</script>'));

  // Serverul caută în tot jurnalul și fără diacritice, ca MySQL: „comanda”
  // găsește și „Comandă”. Intrarea căutată stă dinadins pe pagina a treia.
  const fara = (s) => s.normalize('NFD').replace(/[̀-̧̦ͯ]/g, '').toLowerCase();
  const TOT = [];
  for (let i = 0; i < 300; i++) {
    TOT.push({
      id: 1000 - i, createdAt: '2026-08-29 09:00:00', user: 'testadmin', role: 'admin',
      action: 'order_update', entity: 'order',
      detail: i === 120 ? 'Comandă #5 — CURSA fractie2' : 'Comandă oarecare ' + i
    });
  }
  const cereri = [];
  const gpFals = {
    get: (cale) => {
      cereri.push(cale);
      const p = Number((/[?&]page=(\d+)/.exec(cale) || [])[1] || 1);
      const cautat = decodeURIComponent((/[?&]search=([^&]*)/.exec(cale) || [])[1] || '');
      const gasite = cautat
        ? TOT.filter((e) => fara(e.action + ' ' + e.detail + ' ' + e.user).includes(fara(cautat)))
        : TOT;
      return Promise.resolve({
        entries: gasite.slice((p - 1) * 50, p * 50), total: gasite.length, page: p,
        pages: Math.max(1, Math.ceil(gasite.length / 50))
      });
    }
  };
  class Baza {
    constructor(pr) { this.props = pr || {}; this.state = {}; }
    setState(x) { Object.assign(this.state, typeof x === 'function' ? x(this.state) : x); }
  }
  const nimic = { hidden: false, addEventListener() {}, removeEventListener() {}, documentElement: { setAttribute() {} } };
  const Component = new Function('DCLogic', 'gp', 'window', 'document', 'localStorage', 'location',
    cod + '\n;return Component;')(Baza, gpFals, nimic, nimic, nimic, { href: '', search: '' });

  const pagina = new Component({});
  pagina.state.auth = { u: 'testadmin', name: 'Test', role: 'admin' };
  await pagina.pull(1);
  check('Jurnal.dc.html:284', 'acțiunea căutată chiar nu e printre intrările încărcate',
    !pagina.state.log.some((e) => (e.detail || '').includes('CURSA')));

  // Omul scrie în casetă și se oprește; căutarea pleacă singură la server.
  const v = pagina.renderVals();
  v.onSearch({ target: { value: 'CURSA' } });
  await new Promise((r) => setTimeout(r, 400));
  check('Jurnal.dc.html:284', 'căutarea pleacă la server, nu se face doar peste ce e încărcat',
    cereri.some((c) => c.includes('search=CURSA')), cereri.join(' | '));
  check('Jurnal.dc.html:284', 'căutarea găsește o acțiune care nu era în primele 50',
    pagina.state.log.length === 1 && (pagina.state.log[0].detail || '').includes('CURSA'),
    'au venit ' + pagina.state.log.length + ' intrări');
  const dupa = pagina.renderVals();
  check('Jurnal.dc.html:284', 'acțiunea găsită ajunge chiar pe ecran',
    dupa.groups.flatMap((g) => g.rows).length === 1 && dupa.empty === false);

  // Cu reparația dată înapoi pagina n-are metoda `cauta` și nu întreabă
  // serverul; atunci rămâne doar textul din casetă, iar probele de mai jos cad
  // pe fond, nu cu o excepție.
  const caut = async (text) => {
    pagina.setState({ search: text });
    if (typeof pagina.cauta === 'function') await pagina.cauta(text);
  };

  // Serverul potrivește fără diacritice; dacă pagina ar mai filtra o dată,
  // rândul chiar găsit ar dispărea de pe ecran.
  await caut('comanda #5');
  const dupa2 = pagina.renderVals();
  check('Jurnal.dc.html:284', 'rândurile găsite de server nu sunt filtrate a doua oară în pagină',
    dupa2.groups.flatMap((g) => g.rows).length === 1 && dupa2.empty === false,
    'au rămas pe ecran ' + dupa2.groups.flatMap((g) => g.rows).length + ' rânduri');

  // Rezultatele unei căutări largi se răsfoiesc tot de pe server, mai departe
  // de primele 50.
  await caut('oarecare');
  check('Jurnal.dc.html:284', 'contorul arată câte potriviri are tot jurnalul, nu câte s-au încărcat',
    pagina.state.total === 299, 'total ' + pagina.state.total);
  const multe = pagina.renderVals();
  check('Jurnal.dc.html:284', 'subsolul spune limpede că cifra e a căutării',
    multe.footLine.includes('găsite în tot jurnalul'), multe.footLine);
  await multe.onMore();
  check('Jurnal.dc.html:284', '„Încarcă mai vechi” răsfoiește mai departe rezultatele căutării',
    pagina.state.log.length === 100 && /search=/.test(cereri[cereri.length - 1]),
    cereri[cereri.length - 1]);

  // O căutare care nu găsește nimic nu are voie să spună că jurnalul e gol.
  await caut('nusegasesteniciodata');
  const gol = pagina.renderVals();
  check('Jurnal.dc.html:284', 'o căutare fără rezultat nu mai pretinde că jurnalul e gol',
    gol.empty === true && !gol.emptyLine.includes('înregistrată încă') && gol.emptyLine.includes('tot jurnalul'),
    gol.emptyLine);

  // Ștergerea filtrelor readuce jurnalul întreg, nu doar golește caseta.
  gol.onClearFilters();
  await new Promise((r) => setTimeout(r, 0));
  check('Jurnal.dc.html:284', '„Șterge filtrele” readuce jurnalul întreg',
    pagina.state.q === '' && pagina.state.log.length === 50, pagina.state.log.length + ' intrări');

  // Contorul și „Încarcă mai vechi” nu mai dispar când filtrele nu potrivesc
  // nimic din ce e adus: exact atunci omul are nevoie de ele.
  pagina.state.who = 'nimeni';
  const stramt = pagina.renderVals();
  check('Jurnal.dc.html:284', 'subsolul și „Încarcă mai vechi” rămân când filtrul local nu potrivește nimic',
    stramt.empty === true && stramt.hasRows === true && stramt.hasMore === true);
}

/* ======================================================= Depozit.dc.html == */

console.log('\n=== Depozit.dc.html · stocul din listă ===');
{
  const { logica } = parti('Depozit.dc.html');

  // Cifrele din depozit se pot chiar calcula aici: scoatem din sursă bucata
  // care le produce și o rulăm pe o stare făcută de mână. O probă care ar căuta
  // doar un șir de caractere ar trece și peste o reparație care nu socotește.
  const declNum = /const num = \(v\) => \{[\s\S]*?\};/.exec(logica);
  const bucataStoc = /    const items = cat\.map\(c => \{[\s\S]*?\n    \}\);/.exec(logica);
  const linieFStock = /    const fStock = F \? .*/.exec(logica);

  check('Depozit.dc.html:658', 'calculul stocului se poate citi din sursă',
    !!(declNum && bucataStoc && linieFStock));

  if (declNum && bucataStoc && linieFStock) {
    const num = new Function(declNum[0] + '\nreturn num;')();
    const calcItems = new Function('cat', 'led', 'showRes', 'num',
      bucataStoc[0] + '\n    return items;');
    // Stare goală, dinadins: rândul n-are voie să se sprijine pe comenzile
    // ajunse în pagină. Dacă socoteala rezervărilor se întoarce în browser, de
    // aici iese zero, cifrele nu se mai potrivesc, iar proba spune „FAIL" în
    // loc să crape cu urma de stivă a lui `this.state`.
    const ruleaza = (...a) => calcItems.call({ state: { orders: [] } }, ...a);

    // Articolul are 370 kg după soldul serverului, dar registrul ajuns în
    // pagină e tăiat la ultimele 1000 de mișcări: în fereastra rămasă au căzut
    // tocmai recepțiile vechi, deci suma din browser ar da −635.
    const faina = { id: 7, n: 'Făină 650', c: 'Materii prime', u: 'kg', min: 100, open: 370, low: false };
    const registruTaiat = { 'Făină 650': { in: 5, out: 640, last: { d: '2026-08-29', t: '09:10', k: 'out', q: 5, r: 'Consum în producție' } } };

    const r1 = ruleaza([faina], registruTaiat, true, num)[0];
    check('Depozit.dc.html:658', 'coloana „Stoc" ia soldul serverului, nu suma mișcărilor ajunse în pagină',
      r1.stock === 370, 'a ieșit ' + r1.stock);
    check('Depozit.dc.html:658', 'un registru tăiat nu mai inventează „Sub prag" și „Lipsă"',
      r1.under === false && r1.short === false, JSON.stringify({ under: r1.under, short: r1.short }));

    // Al doilea declanșator, care nu are nevoie de 1000 de rânduri: articolul a
    // fost redenumit din panou, iar mișcările păstrează numele de atunci.
    const redenumit = Object.assign({}, faina, { n: 'Făină 650 tip BL' });
    const r2 = ruleaza([redenumit], registruTaiat, true, num)[0];
    check('Depozit.dc.html:658', 'un articol redenumit își păstrează stocul',
      r2.stock === 370 && r2.short === false, 'a ieșit ' + r2.stock);

    // Caseta de intrare/ieșire trebuie să pornească de la aceeași cifră, altfel
    // refuză o ieșire pe care depozitul o acoperă.
    const calcFStock = new Function('F', 'fc', 'led', 'num',
      linieFStock[0] + '\n    return fStock;');
    const fStock = calcFStock({ k: 'out', p: 'Făină 650', q: '50' }, { id: 7, u: 'kg', open: 370 }, registruTaiat, num);
    check('Depozit.dc.html:658', 'caseta de ieșire pornește de la soldul serverului',
      fStock === 370, 'a ieșit ' + fStock);
    check('Depozit.dc.html:658', 'o ieșire acoperită de stoc nu mai este refuzată',
      !(50 > fStock), 'pragul casetei era ' + fStock);
  }
}

/* ============================================ Depozit.dc.html · rezervări == */

console.log('\n=== Depozit.dc.html · stocul rezervat vine de la server ===');
{
  const { logica } = parti('Depozit.dc.html');

  // Scoatem din sursă cele două bucăți prin care trece cifra — traducerea
  // articolului venit de la server și socoteala rândului — și le rulăm. O probă
  // care ar căuta doar textul „it.reserved" ar trece și peste o citire pusă
  // unde nu trebuie.
  const declNum = /const num = \(v\) => \{[\s\S]*?\};/.exec(logica);
  const fnCatalog = /\n  catalog\(\) \{([\s\S]*?)\n  \}/.exec(logica);
  const bucataStoc = /    const items = cat\.map\(c => \{[\s\S]*?\n    \}\);/.exec(logica);

  check('Depozit.dc.html:597', 'socoteala rândului se poate citi din sursă',
    !!(declNum && fnCatalog && bucataStoc));

  // Cifra se socotea în pagină, adunând pozițiile comenzilor deschise. Suma
  // pleca însă de la GET /orders, care întoarce cel mult 500 de comenzi: peste
  // atâtea, „Rezervat" scădea tăcut, iar depozitarul cumpăra materie primă după
  // o cifră care nu mai era adevărată. Nimic pe ecran nu spunea că s-a numărat
  // doar o parte. Socoteala e acum pe server (route_items_list), care vede tot.
  check('Depozit.dc.html:597', 'pagina nu mai adună rezervările din comenzile primite',
    !/\n  reserved\(\)/.test(logica) && !/peProdus/.test(logica),
    'socoteala rezervărilor e încă în pagină');

  if (declNum && fnCatalog && bucataStoc) {
    const num = new Function(declNum[0] + '\nreturn num;')();
    const catalog = new Function('return function () {' + fnCatalog[1] + '\n};')();
    const calcItems = new Function('cat', 'led', 'showRes', 'num',
      bucataStoc[0] + '\n    return items;');
    // Vezi mai sus: stare goală, ca revenirea la socoteala în browser să se
    // vadă ca „FAIL", nu ca o excepție.
    const ruleaza = (...a) => calcItems.call({ state: { orders: [] } }, ...a);

    // Ce trimite serverul pentru croissantul din seed: 40 pe raft, 300 promise.
    const dinServer = {
      id: 24, name: 'Croissant cu fistic', category: 'Produse finite', unit: 'buc',
      qty: 40, minQty: 0, low: false, productId: 9, reserved: 300, available: -260,
    };
    const cat = catalog.call({ state: { items: [dinServer] } });
    check('Depozit.dc.html:597', 'articolul poartă mai departe cifrele venite de la server',
      cat[0].res === 300 && cat[0].avail === -260,
      JSON.stringify({ res: cat[0].res, avail: cat[0].avail }));

    const led = { 'Croissant cu fistic': { in: 40, out: 0, last: null } };
    const rand = ruleaza(cat, led, true, num)[0];
    check('Depozit.dc.html:597', 'coloana „Disponibil" și eticheta „Lipsă" se sprijină pe cifra serverului',
      rand.res === 300 && rand.avail === -260 && rand.short === true,
      JSON.stringify({ res: rand.res, avail: rand.avail, short: rand.short }));

    // Steagul din proprietăți stinge coloana, nu marfa: fără „Rezervat",
    // disponibilul e stocul întreg și rândul nu mai strigă „Lipsă".
    const stins = ruleaza(cat, led, false, num)[0];
    check('Depozit.dc.html:597', 'cu „Rezervat" stins, rândul arată stocul de pe raft',
      stins.res === 0 && stins.avail === 40 && stins.short === false,
      JSON.stringify({ res: stins.res, avail: stins.avail, short: stins.short }));

    // Un server care încă nu trimite câmpurile nu are voie să facă tot
    // depozitul să pară în lipsă.
    const fara = catalog.call({ state: { items: [{ id: 24, name: 'Croissant cu fistic', category: 'Produse finite', unit: 'buc', qty: 40, minQty: 0, low: false, productId: 9 }] } });
    const randFara = ruleaza(fara, led, true, num)[0];
    check('Depozit.dc.html:597', 'fără cifrele de la server, rândul nu inventează o lipsă',
      randFara.res === 0 && randFara.avail === 40 && randFara.short === false,
      JSON.stringify({ res: randFara.res, avail: randFara.avail }));
  }
}

/* ============================ Depozit.dc.html · butonul „Scade din stoc" == */

console.log('\n=== Depozit.dc.html · butonul „Scade din stoc” ===');
{
  const { marcaj, logica } = parti('Depozit.dc.html');

  // Scoatem sync() din sursă și îl rulăm cu un `gp` de mucava, ca să vedem
  // chiar comportamentul: un al doilea tap dat cât timp prima cerere e pe drum
  // nu are voie să pornească a doua scădere, iar pagina trebuie să spună, cât
  // ține scăderea, că lucrează.
  const fnSync = /\n  sync\(silent\) \{\n([\s\S]*?)\n  \}/.exec(logica);
  check('Depozit.dc.html:615', 'sync() se poate citi din sursă', !!fnSync);

  if (fnSync) {
    let cereri = 0;
    const stare = {};
    const gpFals = {
      syncStock: () => {
        cereri++;
        return new Promise((gata) => setTimeout(() => gata({ orders: 3, pieces: 42, missing: [], skipped: [] }), 40));
      }
    };
    const obiect = {
      state: stare,
      pending: () => [{ id: 1 }, { id: 2 }, { id: 3 }],
      pull: () => Promise.resolve(),
      setState(x) { Object.assign(stare, x); },
    };
    obiect.sync = new Function('gp', 'fmt', 'return function (silent) {' + fnSync[1] + '\n};')(
      gpFals, (x) => String(x)
    );

    obiect.sync(false);
    const semnInTimpulLucrului = stare.syncing === true;
    obiect.sync(false);            // al doilea tap, la câteva zeci de ms
    obiect.sync(false);            // și al treilea
    const cereriInTimpulLucrului = cereri;

    await new Promise((r) => setTimeout(r, 160));

    check('Depozit.dc.html:615', 'pagina spune că lucrează cât ține scăderea',
      semnInTimpulLucrului, 'syncing = ' + JSON.stringify(stare.syncing));
    check('Depozit.dc.html:615', 'tapurile date până răspunde serverul nu pornesc a doua scădere',
      cereriInTimpulLucrului === 1, cereriInTimpulLucrului + ' cereri pornite');
    check('Depozit.dc.html:615', 'după scădere butonul se aprinde la loc',
      stare.syncing === false, 'syncing = ' + JSON.stringify(stare.syncing));
  }

  // Semnul din stare trebuie să ajungă și pe buton: fără `disabled`, butonul
  // arată la fel după apăsare și omul mai dă un tap.
  check('Depozit.dc.html:615', 'butonul „Scade din stoc” se stinge cât timp lucrează',
    /onClick="\{\{ onSync \}\}" disabled="\{\{ syncBusy \}\}"/.test(marcaj));
  check('Depozit.dc.html:615', 'steagul butonului vine chiar din starea de scădere',
    /syncBusy:\s*!!S\.syncing,/.test(logica));
}

/* ============== Depozit.dc.html · „Articol nou” pleacă legat de produs === */

console.log('\n=== Depozit.dc.html · „Articol nou” pleacă legat de produs ===');
{
  const { logica } = parti('Depozit.dc.html');

  const fnCauta  = /\n  produsulDupaNume\(nume\) \{\n([\s\S]*?)\n  \}/.exec(logica);
  const fnAdauga = /\n  adaugaArticol\(nume, categorie, unitate, prag\) \{\n([\s\S]*?)\n  \}/.exec(logica);
  check('Depozit.dc.html:634', 'căutarea produsului după nume se poate citi din sursă', !!fnCauta);
  check('Depozit.dc.html:634', 'adaugaArticol() se poate citi din sursă', !!fnAdauga);

  if (fnCauta && fnAdauga) {
    const gpFals = { done: () => 'Predată', cancelled: () => 'Anulată' };
    const cauta  = new Function('gp', 'return function (nume) {' + fnCauta[1] + '\n};')(gpFals);
    const cu     = (orders) => ({ state: { orders } });

    // Cazul care contează: mesajul „creează articolele și apasă din nou” se
    // arată pentru comenzile PREDATE — scăderea din stoc se uită numai la ele.
    check('Depozit.dc.html:634', 'produsul se află și dintr-o comandă predată',
      cauta.call(cu([{ status: 'Predată', items: [{ n: 'Croasant', productId: 7 }] }]), 'Croasant') === 7,
      'a dat ' + cauta.call(cu([{ status: 'Predată', items: [{ n: 'Croasant', productId: 7 }] }]), 'Croasant'));

    check('Depozit.dc.html:634', 'și dintr-una în lucru, ca înainte',
      cauta.call(cu([{ status: 'La dospit', items: [{ n: 'Croasant', productId: 7 }] }]), 'Croasant') === 7);

    // Comanda anulată rămâne pe dinafară: un nume reciclat de ea ar lega
    // articolul de alt produs.
    check('Depozit.dc.html:634', 'comanda anulată nu leagă articolul de produsul ei',
      cauta.call(cu([{ status: 'Anulată', items: [{ n: 'Croasant', productId: 7 }] }]), 'Croasant') === null);

    // La nepotrivire nu ghicim: articolul intră nelegat, ca înainte.
    check('Depozit.dc.html:634', 'două produse cu același nume nu se ghicesc',
      cauta.call(cu([
        { status: 'Predată',   items: [{ n: 'Croasant', productId: 7 }] },
        { status: 'La dospit', items: [{ n: 'Croasant', productId: 9 }] },
      ]), 'Croasant') === null);

    check('Depozit.dc.html:634', 'un nume care nu apare nicăieri lasă articolul nelegat',
      cauta.call(cu([{ status: 'Predată', items: [{ n: 'Altceva', productId: 7 }] }]), 'Făină') === null);

    // Și că legătura chiar pleacă în cerere, nu doar se calculează.
    let trimis = null;
    const adauga = new Function('gp', 'return function (nume, categorie, unitate, prag) {'
      + fnAdauga[1] + '\n};')({ post: (u, b) => { trimis = { u, b }; return Promise.resolve(); } });
    const pagina = {
      state: { orders: [{ status: 'Predată', items: [{ n: 'Croasant', productId: 7 }] }] },
      produsulDupaNume: cauta,
      pull: () => Promise.resolve(),
    };
    await adauga.call(pagina, 'Croasant', 'Produse finite', 'buc', null);
    check('Depozit.dc.html:634', 'cererea de articol nou poartă produsul găsit',
      trimis?.b?.productId === 7, JSON.stringify(trimis?.b));

    trimis = null;
    const paginaGoala = { state: { orders: [] }, produsulDupaNume: cauta, pull: () => Promise.resolve() };
    await adauga.call(paginaGoala, 'Făină', 'Materii prime', 'kg', 10);
    check('Depozit.dc.html:634', 'articolul fără produs pleacă în continuare nelegat',
      trimis !== null && !('productId' in (trimis.b || {})), JSON.stringify(trimis?.b));
  }
}

/* ====================================== Depozit.dc.html · pragul minim === */

console.log('\n=== Depozit.dc.html · pragul minim din depozit ===');
{
  const { marcaj, logica } = parti('Depozit.dc.html');

  // Tastarea nu mai are voie să scrie pe server: ține doar minte textul.
  check('Depozit.dc.html:699', 'tastarea în câmpul „Prag” doar ține minte textul',
    /onMin: \(e\) => set\(\{ minEdit: x\.c\.id, minText: e\.target\.value \}\),/.test(logica)
    && !/onMin: \(e\) => this\.setMin\(/.test(logica));
  check('Depozit.dc.html:699', 'pragul se salvează la ieșirea din câmp, în ambele randări',
    (marcaj.match(/onChange="\{\{ r\.onMin \}\}" onBlur="\{\{ r\.onMinBlur \}\}"/g) || []).length === 2);
  check('Depozit.dc.html:699', 'reîmprospătarea nu mai suprascrie câmpul în care se scrie',
    /min: S\.minEdit === x\.c\.id \? S\.minText : String\(x\.c\.min\),/.test(logica));

  // Și acum funcția însăși, scoasă din sursă și rulată: ce pleacă spre server
  // și, mai ales, ce NU pleacă.
  const fnPrag = /\n  incheiePrag\(itemId, pragVechi\) \{\n([\s\S]*?)\n  \}/.exec(logica);
  check('Depozit.dc.html:699', 'incheiePrag() se poate citi din sursă', !!fnPrag);

  if (fnPrag) {
    const incearca = (text) => {
      const trimise = [];
      const stare = { minEdit: 7, minText: text };
      const obiect = {
        state: stare,
        setMin(id, v) { trimise.push([id, v]); return Promise.resolve(); },
        setState(x) { Object.assign(stare, x); },
      };
      obiect.incheiePrag = new Function('return function (itemId, pragVechi) {' + fnPrag[1] + '\n};')();
      obiect.incheiePrag(7, 30);
      return { trimise, stare };
    };

    check('Depozit.dc.html:699', 'pragul scris întreg ajunge o singură dată pe server',
      JSON.stringify(incearca('125').trimise) === '[[7,125]]');
    check('Depozit.dc.html:699', 'pragul scris cu virgulă zecimală se înțelege',
      JSON.stringify(incearca('12,5').trimise) === '[[7,12.5]]');
    check('Depozit.dc.html:699', 'câmpul golit nu salvează pragul 0',
      incearca('').trimise.length === 0, JSON.stringify(incearca('').trimise));
    check('Depozit.dc.html:699', 'un text care nu e număr nu salvează nimic',
      incearca('abc').trimise.length === 0, JSON.stringify(incearca('abc').trimise));
    check('Depozit.dc.html:699', 'o valoare negativă nu pleacă spre server',
      incearca('-5').trimise.length === 0, JSON.stringify(incearca('-5').trimise));
    check('Depozit.dc.html:699', 'pragul neschimbat nu mai lasă o intrare în Jurnal',
      incearca('30').trimise.length === 0, JSON.stringify(incearca('30').trimise));
    check('Depozit.dc.html:699', 'după ieșirea din câmp rândul revine pe cifra serverului',
      incearca('').stare.minEdit === null);
  }
}

/* ============================ Depozit.dc.html · anularea unei mișcări ==== */

console.log('\n=== Depozit.dc.html · anularea unei mișcări ===');
{
  const { marcaj, logica } = parti('Depozit.dc.html');

  const numSrc = /^const num = (.+);$/m.exec(logica);
  const fnCorectii = /\n  corectii\(\) \{\n([\s\S]*?)\n  \}/.exec(logica);
  check('Depozit.dc.html:726', 'num() și corectii() se pot citi din sursă', !!(numSrc && fnCorectii));

  if (numSrc && fnCorectii) {
    const num = new Function('return ' + numSrc[1])();
    const stie = (moves) => {
      const obiect = { state: { moves } };
      obiect.corectii = new Function('num', 'return function () {' + fnCorectii[1] + '\n};')(num);
      return obiect.corectii.call(obiect);
    };

    const gresita = { id: 11, itemId: 4, d: '2026-08-12', t: '09:14', k: 'in', q: 20, r: 'Livrare furnizor', note: '' };
    const corectia = { id: 30, itemId: 4, d: '2026-08-14', t: '10:02', k: 'out', q: 20, r: 'Corecție', note: 'Anulează mișcarea din 2026-08-12 09:14' };
    // Altă cantitate, același articol, același minut: nu are voie să fie
    // luată drept corectată de corecția de mai sus.
    const alta = { id: 12, itemId: 4, d: '2026-08-12', t: '09:14', k: 'in', q: 5, r: 'Livrare furnizor', note: '' };
    // Alt articol, aceeași zi și oră.
    const altArticol = { id: 13, itemId: 9, d: '2026-08-12', t: '09:14', k: 'in', q: 20, r: 'Livrare furnizor', note: '' };

    check('Depozit.dc.html:726', 'o mișcare necorectată se poate încă anula',
      stie([gresita, alta, altArticol])(gresita) === false);
    check('Depozit.dc.html:726', 'mișcarea deja anulată nu se mai poate anula a doua oară',
      stie([gresita, corectia])(gresita) === true);
    check('Depozit.dc.html:726', 'corecția nu stinge butonul altei mișcări din același minut',
      stie([gresita, alta, corectia])(alta) === false);
    check('Depozit.dc.html:726', 'corecția nu stinge butonul altui articol din același minut',
      stie([gresita, altArticol, corectia])(altArticol) === false);
    check('Depozit.dc.html:726', 'o mișcare cu alt motiv nu trece drept corecție',
      stie([gresita, Object.assign({}, corectia, { r: 'Corecție inventar' })])(gresita) === false);
  }

  check('Depozit.dc.html:726', 'butonul de anulare dispare de pe mișcarea deja corectată',
    /canDelete: !!mv\.itemId && !corectat\(mv\),/.test(logica)
    && /const corectat = this\.corectii\(\);/.test(logica));
  check('Depozit.dc.html:726', 'rândul corectat își spune starea, în locul butonului',
    /<sc-if value="\{\{ m\.corrected \}\}"/.test(marcaj) && marcaj.includes('Corectată'));
  check('Depozit.dc.html:726', 'butonul e stins cât timp corecția e pe drum',
    /onClick="\{\{ m\.onDelete \}\}" disabled="\{\{ m\.undoOff \}\}"/.test(marcaj)
    && /if \(this\.__anulez\) return;/.test(logica));
}

/* ==================================== Depozit.dc.html · articolul nou ==== */

console.log('\n=== Depozit.dc.html · articolul nou din depozit ===');
{
  const { marcaj, logica } = parti('Depozit.dc.html');

  // Ruta `POST /stock/items` era pe server de la început, dar n-o chema nicio
  // pagină: un ambalaj nou nu putea fi urmărit până nu intra cineva direct în
  // baza de date. Chiar și mesajul scăderii din stoc cere „creează articolele
  // și apasă din nou”, ceea ce depozitarul n-avea cum să facă.
  //
  // Pagina se poate rula aici, deci proba nu se uită la un buton scris în
  // marcaj, ci la ce pleacă spre server când omul apasă „Adaugă articolul”.
  const fereastraDep = {
    document: {
      hidden: false, addEventListener() {}, removeEventListener() {},
      documentElement: { setAttribute() {} },
    },
    setInterval, clearInterval, fetch: async () => { throw new Error('fără rețea în probă'); },
    location: { origin: 'http://127.0.0.1:8100', hostname: '127.0.0.1', protocol: 'http:', port: '8100' },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    history: { pushState() {}, back() {}, state: null },
    addEventListener() {}, removeEventListener() {},
    console,
  };
  new Function('window', 'globalThis', cite('shared/gp-api.js'))(fereastraDep, fereastraDep);
  const gpd = fereastraDep.gp;

  const corpDep = logica.slice(logica.indexOf('>') + 1, logica.lastIndexOf('</script>'));
  class DCLogicFalsDep {
    constructor(props) { this.props = props || {}; }
    setState(x) { this.state = Object.assign({}, this.state, typeof x === 'function' ? x(this.state) : x); }
  }
  let ComponentDep = null;
  try {
    ComponentDep = new Function('gp', 'DCLogic', 'window', 'document', 'localStorage',
      corpDep + '\n;return Component;')(
      gpd, DCLogicFalsDep, fereastraDep, fereastraDep.document, fereastraDep.localStorage);
  } catch (e) {
    console.log('  (pagina nu a putut fi încărcată: ' + e.message + ')');
  }
  check('Depozit.dc.html:509', 'blocul de logică al depozitului se poate rula în probă',
    typeof ComponentDep === 'function');

  /**
   * Deschide caseta de articol nou, completează ce i se cere și apasă
   * „Adaugă articolul”. Întoarce ce a plecat spre server și ce a rămas pe ecran.
   */
  const adauga = async (completeaza, raspunsulServerului) => {
    const cereri = [];
    if (typeof ComponentDep !== 'function') return { cereri, deschisa: false, caseta: {}, dupa: {} };

    const postVechi = gpd.post;
    gpd.post = (cale, corp) => {
      cereri.push({ cale, corp });
      return raspunsulServerului ? raspunsulServerului() : Promise.resolve({ id: 99 });
    };
    try {
      const c = new ComponentDep({});
      c.props = {};
      c.state = Object.assign({}, c.state, {
        loaded: true, who: 'Depozitar', role: 'angajat', cat: 'Ambalaje',
        items: [{ id: 7, name: 'Făină 650', category: 'Materii prime', unit: 'kg', qty: 370, minQty: 100, low: false }],
        // Listele vin din Setări → Liste; „Etichete” și „set” nu sunt printre
        // valorile scrise de rezervă în pagină, deci se vede de unde sunt luate.
        categories: ['Materii prime', 'Produse finite', 'Ambalaje', 'Etichete'],
        units: ['buc', 'kg', 'L', 'set'],
        moves: [], orders: [],
      });
      // Reîmprospătarea ar cere pe rețea; aici ne interesează doar ce s-a trimis.
      c.pull = () => Promise.resolve();

      let v = c.renderVals();
      if (typeof v.onNewArt !== 'function') return { cereri, deschisa: false, caseta: {}, dupa: v };
      v.onNewArt();
      const caseta = (v = c.renderVals());
      if (!v.hasArt) return { cereri, deschisa: false, caseta, dupa: v };

      const scrie = (camp, val) => {
        const f = c.renderVals()[camp];
        if (typeof f === 'function') f({ target: { value: val } });
      };
      completeaza(scrie);

      const gata = c.renderVals().onArtSave;
      if (typeof gata === 'function') await gata();
      return { cereri, deschisa: true, caseta, dupa: c.renderVals() };
    } finally {
      gpd.post = postVechi;
    }
  };

  const fara = await adauga(() => {});
  check('Depozit.dc.html:509', 'pagina deschide o casetă de articol nou',
    fara.deschisa === true);
  check('Depozit.dc.html:509', 'articolul fără denumire nu pleacă spre server',
    fara.cereri.length === 0 && /denumire/i.test(String(fara.dupa.artErr)),
    fara.cereri.length + ' cereri · „' + fara.dupa.artErr + '”');

  const cutie = await adauga((scrie) => {
    scrie('onArtN', '  Cutie tort 26 cm  ');
    scrie('onArtC', 'Ambalaje');
    scrie('onArtU', 'buc');
  });
  check('Depozit.dc.html:509', 'articolul nou chiar pleacă spre POST /stock/items',
    cutie.cereri.length === 1 && cutie.cereri[0].cale === '/stock/items',
    JSON.stringify(cutie.cereri));
  check('Depozit.dc.html:509', 'denumirea, categoria și unitatea ajung așa cum le cere serverul',
    cutie.cereri.length === 1
    && cutie.cereri[0].corp.name === 'Cutie tort 26 cm'
    && cutie.cereri[0].corp.category === 'Ambalaje'
    && cutie.cereri[0].corp.unit === 'buc',
    JSON.stringify(cutie.cereri[0] && cutie.cereri[0].corp));
  // Câmpul „Prag” lăsat gol înseamnă „pragul implicit din Setări”, nu prag 0:
  // cu pragul 0 articolul nu apare niciodată în „Sub prag minim”, deci nimeni
  // nu află când s-a terminat.
  check('Depozit.dc.html:509', 'pragul lăsat gol lasă serverul să pună pragul implicit din Setări',
    cutie.cereri.length === 1 && !('minQty' in cutie.cereri[0].corp),
    JSON.stringify(cutie.cereri[0] && cutie.cereri[0].corp));
  check('Depozit.dc.html:509', 'după salvare caseta se închide și pagina spune ce s-a adăugat',
    cutie.dupa.hasArt === false && String(cutie.dupa.msg).includes('Cutie tort 26 cm'),
    JSON.stringify({ hasArt: cutie.dupa.hasArt, msg: cutie.dupa.msg }));

  const cuPrag = await adauga((scrie) => {
    scrie('onArtN', 'Cutie tort 30 cm');
    scrie('onArtC', 'Ambalaje');
    scrie('onArtMin', '12,5');
  });
  check('Depozit.dc.html:509', 'pragul scris cu virgulă zecimală ajunge număr pe server',
    cuPrag.cereri.length === 1 && cuPrag.cereri[0].corp.minQty === 12.5,
    JSON.stringify(cuPrag.cereri[0] && cuPrag.cereri[0].corp));

  const pragAiurea = await adauga((scrie) => {
    scrie('onArtN', 'Cutie tort 32 cm');
    scrie('onArtMin', 'cam mult');
  });
  check('Depozit.dc.html:509', 'un prag care nu e număr nu trece drept 0',
    pragAiurea.cereri.length === 0 && !!pragAiurea.dupa.artErr,
    JSON.stringify(pragAiurea.cereri));

  const pragNegativ = await adauga((scrie) => {
    scrie('onArtN', 'Cutie tort 34 cm');
    scrie('onArtMin', '-3');
  });
  check('Depozit.dc.html:509', 'un prag negativ nu pleacă spre server',
    pragNegativ.cereri.length === 0 && !!pragNegativ.dupa.artErr,
    JSON.stringify(pragNegativ.cereri));

  // Serverul refuză numele deja folosit. Refuzul trebuie citit în casetă, nu în
  // bannerul de sus: acela rămâne acoperit de fereastră, deci omul ar vedea
  // doar o casetă care nu se închide, fără să afle de ce.
  const luat = await adauga(
    (scrie) => { scrie('onArtN', 'Făină 650'); scrie('onArtC', 'Materii prime'); },
    () => Promise.reject(Object.assign(new Error('Există deja un articol cu numele „Făină 650”.'), { status: 409 }))
  );
  check('Depozit.dc.html:509', 'numele deja folosit lasă caseta deschisă, cu motivul în ea',
    luat.dupa.hasArt === true && String(luat.dupa.artErr).includes('Făină 650'),
    JSON.stringify({ hasArt: luat.dupa.hasArt, artErr: luat.dupa.artErr }));
  check('Depozit.dc.html:509', 'după refuz butonul se aprinde la loc, ca omul să poată încerca iar',
    luat.dupa.artBusy === false, 'artBusy = ' + JSON.stringify(luat.dupa.artBusy));

  // Categoriile și unitățile se editează din Setări → Liste. Scrise în pagină,
  // o categorie adăugată din panou n-ar apărea în casetă, iar una ștearsă ar fi
  // propusă în continuare, iar salvarea ar pica cu „Categorie necunoscută”.
  const propuse = (lista) => (fara.caseta[lista] || []).map((o) => o.v);
  check('Depozit.dc.html:509', 'categoriile propuse vin din Setări, nu din lista scrisă în pagină',
    propuse('artCats').includes('Etichete'), JSON.stringify(propuse('artCats')));
  check('Depozit.dc.html:509', 'unitățile propuse vin tot din Setări',
    propuse('artUnits').includes('set'), JSON.stringify(propuse('artUnits')));

  // Bara de sus e ascunsă pe telefon, deci butonul trebuie să fie și în bara de
  // jos: altfel depozitarul cu tableta în mână rămâne fără nicio cale.
  const butoane = (marcaj.match(/onClick="\{\{ onNewArt \}\}"/g) || []).length;
  check('Depozit.dc.html:509', 'articolul nou se poate porni și de pe telefon, nu doar din bara de sus',
    butoane >= 2, butoane + ' butoane');

  // Două tapuri grăbite pe „Adaugă articolul”, înainte ca ecranul să se
  // redeseneze. Butonul se stinge, dar `setState` nu schimbă starea pe loc:
  // funcția apăsată a doua oară e aceeași, cu aceeași stare veche în ea. Un
  // semafor ținut doar în stare le-ar fi lăsat pe amândouă să treacă, iar
  // depozitarul ar fi primit „există deja un articol cu numele…” pentru
  // propriul lui articol, tocmai salvat.
  const douaTapuri = await (async () => {
    const cereri = [];
    if (typeof ComponentDep !== 'function') return cereri;
    const postVechi = gpd.post;
    let deblocheaza = null;
    const asteapta = new Promise((res) => { deblocheaza = res; });
    gpd.post = (cale, corp) => { cereri.push({ cale, corp }); return asteapta.then(() => ({ id: 99 })); };
    try {
      const c = new ComponentDep({});
      c.props = {};
      c.state = Object.assign({}, c.state, {
        loaded: true, who: 'Depozitar', role: 'angajat', cat: 'Ambalaje',
        items: [], categories: ['Ambalaje'], units: ['buc'], moves: [], orders: [],
      });
      c.pull = () => Promise.resolve();
      c.renderVals().onNewArt();
      const v = c.renderVals();
      v.onArtN({ target: { value: 'Cutie tort 28 cm' } });
      // Aceeași funcție, apăsată de două ori: exact ce se întâmplă între două
      // tapuri, fiindcă ecranul nu s-a redesenat între ele.
      const gata = c.renderVals().onArtSave;
      gata(); gata();
      deblocheaza();
      await new Promise((r) => setTimeout(r, 0));
      return cereri;
    } finally {
      gpd.post = postVechi;
    }
  })();
  check('Depozit.dc.html:509', 'două tapuri grăbite adaugă articolul o singură dată',
    douaTapuri.length === 1, douaTapuri.length + ' cereri: ' + JSON.stringify(douaTapuri.map((x) => x.corp && x.corp.name)));
}

/* ================================ Produse.dc.html · salvarea produsului nou == */

console.log('\n=== Produse.dc.html · produsul nou când fotografia e refuzată ===');
{
  const { logica } = parti('Produse.dc.html');

  // Produsul pleacă spre server într-o cerere, fotografia în alta. Dacă a doua
  // e refuzată (poză peste 5 MB, tip neacceptat, folder fără drept de scriere),
  // prima a reușit deja: produsul e în catalog și e viu pe site. Formularul
  // trebuie să știe asta, altfel a doua apăsare pe „Adaugă în catalog” mai
  // trimite un produs cu același nume, serverul îl refuză cu „există deja un
  // produs cu adresa …”, iar administratorul rămâne fără nicio cale de a pune
  // poza pe produsul pe care tocmai l-a făcut.
  //
  // Scoatem save() din sursă și îl rulăm cu un `gp` de mucava, ca proba să se
  // uite la ce pleacă spre server, nu la cum e scris codul.
  const fnSave = /\n  save\(\) \{\n([\s\S]*?)\n  \}/.exec(logica);
  check('Produse.dc.html:409', 'save() se poate citi din sursă', !!fnSave);

  if (fnSave) {
    // `cadePoza` se poate răzgândi între apăsări: prima dată poza e prea mare,
    // a doua oară omul alege una mai mică.
    const scena = (cadePoza, cadeReteta) => {
      const cereri = [];
      let pulls = 0;
      const gpFals = {
        post: (cale) => {
          cereri.push('POST ' + cale);
          // Serverul are cheie unică pe adresă: al doilea produs cu același
          // nume e refuzat, exact ca în api/routes/catalog.php.
          if (cereri.filter((c) => c === 'POST /products').length > 1) {
            return Promise.reject(new Error('Există deja un produs cu adresa „test-1”.'));
          }
          return Promise.resolve({ id: 41, slug: 'test-1' });
        },
        patch: (cale) => { cereri.push('PATCH ' + cale); return Promise.resolve({ ok: true }); },
      };
      const stare = {
        saving: false, formErr: '', msg: '', pendingPhoto: { name: 'poza.jpg' },
        form: {
          id: null, name: 'Test 1', nameEn: 'Test 1', desc: 'Probă', descEn: 'Probe',
          category: 'corn', grams: '', horeca: '2.85', retail: '3.49', isNew: false, photo: ''
        }
      };
      const obiect = {
        state: stare,
        // Runtime-ul dc scrie starea pe loc (support.js:970), deci și aici.
        setState(x) { Object.assign(stare, x); },
        pull() { pulls++; return Promise.resolve(); },
        uploadPhoto(id) {
          cereri.push('FOTO ' + id);
          return cadePoza()
            ? Promise.reject(new Error('Fotografia depășește 5 MB. Micșoreaz-o și încearcă din nou.'))
            : Promise.resolve();
        },
        // Rețeta pleacă a treia, după produs și după poză. E un colaborator ca
        // celelalte două, deci scena îl are pe al ei — altfel proba n-ar
        // deosebi „rețeta a fost trimisă" de „save() s-a împiedicat în ea".
        salveazaReteta(id) {
          cereri.push('REȚETĂ ' + id);
          if (!cadeReteta || !cadeReteta()) return Promise.resolve();
          const e = new Error('Cantitatea pentru „Făină” trebuie să fie un număr mai mare decât zero.');
          e.reteta = true;
          return Promise.reject(e);
        },
      };
      obiect.save = new Function('gp', 'return function () {' + fnSave[1] + '\n};')(gpFals);
      return { obiect, stare, cereri, pulls: () => pulls };
    };

    const asteapta = () => new Promise((r) => setTimeout(r, 20));

    let cade = true;
    const s = scena(() => cade);

    s.obiect.save();
    await asteapta();

    check('Produse.dc.html:409', 'produsul creat nu se mai pierde: formularul îi ține numărul',
      !!(s.stare.form && s.stare.form.id === 41),
      'form.id = ' + JSON.stringify(s.stare.form && s.stare.form.id));
    check('Produse.dc.html:409', 'mesajul spune că produsul a intrat în catalog și doar poza a căzut',
      /adăugat în catalog/.test(s.stare.formErr) && /fotografi/i.test(s.stare.formErr),
      JSON.stringify(s.stare.formErr));
    check('Produse.dc.html:409', 'lista din spatele formularului se reîmprospătează, ca produsul nou să apară',
      s.pulls() >= 1, 'reîncărcări: ' + s.pulls());
    check('Produse.dc.html:409', 'formularul rămâne deschis, cu butonul aprins la loc',
      s.stare.form !== null && s.stare.saving === false,
      JSON.stringify({ form: s.stare.form !== null, saving: s.stare.saving }));

    // A doua apăsare, cu o fotografie acceptabilă.
    cade = false;
    s.obiect.save();
    await asteapta();

    const posturi = s.cereri.filter((c) => c === 'POST /products').length;
    check('Produse.dc.html:409', 'a doua apăsare nu mai creează un al doilea produs',
      posturi === 1, JSON.stringify(s.cereri));
    check('Produse.dc.html:409', 'a doua apăsare scrie pe produsul deja creat',
      s.cereri.includes('PATCH /products/41'), JSON.stringify(s.cereri));
    check('Produse.dc.html:409', 'fotografia ajunge pe produsul creat, nu pe altul',
      s.cereri.filter((c) => c === 'FOTO 41').length === 2, JSON.stringify(s.cereri));
    check('Produse.dc.html:409', 'după ce trece și poza, formularul se închide',
      s.stare.form === null && s.stare.saving === false && s.stare.pendingPhoto === null,
      JSON.stringify({ form: s.stare.form, saving: s.stare.saving }));

    // Drumul fără nicio piedică rămâne cel dinainte: o singură creare și un
    // mesaj care spune că produsul e nou, nu că a fost „salvat”.
    const bun = scena(() => false);
    bun.obiect.save();
    await asteapta();
    check('Produse.dc.html:409', 'produsul salvat din prima pleacă tot cu o singură cerere de creare',
      bun.cereri.filter((c) => c === 'POST /products').length === 1
      && bun.cereri.filter((c) => c.startsWith('PATCH')).length === 0, JSON.stringify(bun.cereri));
    check('Produse.dc.html:409', 'produsul salvat din prima anunță că a fost adăugat în catalog',
      bun.stare.msg === 'Produsul a fost adăugat în catalog.' && bun.stare.form === null,
      JSON.stringify(bun.stare.msg));

    // Rețeta pleacă odată cu produsul, nu cu un al doilea buton: un formular cu
    // două salvări e un formular în care omul nu mai știe ce a salvat.
    check('Produse.dc.html:409', 'rețeta pleacă pe produsul creat, după fotografie',
      bun.cereri.indexOf('REȚETĂ 41') > bun.cereri.indexOf('FOTO 41')
      && bun.cereri.indexOf('FOTO 41') > bun.cereri.indexOf('POST /products'),
      JSON.stringify(bun.cereri));

    // Când pică rețeta, produsul și poza sunt deja în catalog. Mesajul vechi —
    // „adăugat, dar fără fotografie" — ar trimite omul să schimbe poza pentru o
    // cantitate greșită, iar poza ar pleca a doua oară degeaba.
    const cuReteta = scena(() => false, () => true);
    cuReteta.obiect.save();
    await asteapta();
    check('Produse.dc.html:409', 'rețeta picată nu dă vina pe fotografie',
      /rețeta nu/i.test(cuReteta.stare.formErr) && !/fotografi/i.test(cuReteta.stare.formErr),
      JSON.stringify(cuReteta.stare.formErr));
    check('Produse.dc.html:409', 'rețeta picată lasă formularul deschis, cu butonul aprins la loc',
      cuReteta.stare.form !== null && cuReteta.stare.saving === false,
      JSON.stringify({ form: cuReteta.stare.form !== null, saving: cuReteta.stare.saving }));
    check('Produse.dc.html:409', 'rețeta picată nu inventează mesajul de izbândă',
      cuReteta.stare.msg === '', JSON.stringify(cuReteta.stare.msg));
  }
}

/* ========================== Produse.dc.html · rețeta produsului =========== */

console.log('\n=== Produse.dc.html · rețeta care nu se șterge singură ===');
{
  const { logica } = parti('Produse.dc.html');

  // Rețeta se citește cu o a doua cerere, după ce formularul s-a deschis. Cât e
  // pe drum, pagina nu știe ce e în ea — iar `PUT` înlocuiește lista întreagă.
  // O salvare pornită în acel interval, cu lista goală din stare, ar șterge
  // rețeta produsului fără ca cineva s-o fi atins. De aceea trimiterea are o
  // poartă, `recipeReady`. Proba o rulează, nu o citește.
  const fn = /\n  salveazaReteta\(id\) \{\n([\s\S]*?)\n  \}/.exec(logica);
  check('Produse.dc.html:499', 'salveazaReteta() se poate citi din sursă', !!fn);

  if (fn) {
    const scena = (form) => {
      const cereri = [];
      const gpFals = { put: (cale, corp) => { cereri.push({ cale, corp }); return Promise.resolve({ ok: true }); } };
      const obiect = { state: { form } };
      obiect.salveazaReteta = new Function('gp', 'return function (id) {' + fn[1] + '\n};')(gpFals);
      return { obiect, cereri };
    };
    const rand = (itemId, qty) => ({ itemId, qty, name: 'Făină', unit: 'kg', active: true });

    const necitita = scena({ id: 7, recipe: [], recipeReady: false, recipeErr: '' });
    await necitita.obiect.salveazaReteta(7);
    check('Produse.dc.html:499', 'rețeta încă necitită nu pleacă: nu se șterge ce nu s-a văzut',
      necitita.cereri.length === 0, JSON.stringify(necitita.cereri));

    const cazuta = scena({ id: 7, recipe: [], recipeReady: false, recipeErr: 'Nu merge rețeaua.' });
    await cazuta.obiect.salveazaReteta(7);
    check('Produse.dc.html:499', 'rețeta pe care serverul n-a dat-o rămâne neatinsă',
      cazuta.cereri.length === 0, JSON.stringify(cazuta.cereri));

    // Golirea deliberată e altceva decât necunoașterea: rețeta citită și golită
    // de om trebuie să plece, altfel ultima linie n-ar mai putea fi scoasă.
    const golita = scena({ id: 7, recipe: [], recipeReady: true, recipeErr: '' });
    await golita.obiect.salveazaReteta(7);
    check('Produse.dc.html:499', 'rețeta citită și golită de om pleacă goală, ca să se și golească',
      golita.cereri.length === 1 && golita.cereri[0].cale === '/products/7/recipe'
      && Array.isArray(golita.cereri[0].corp.recipe) && golita.cereri[0].corp.recipe.length === 0,
      JSON.stringify(golita.cereri));

    // Tastatura de pe tabletă scrie virgulă; coloana din bază vrea punct.
    const virgula = scena({ id: 7, recipe: [rand(3, '0,005')], recipeReady: true, recipeErr: '' });
    await virgula.obiect.salveazaReteta(7);
    check('Produse.dc.html:499', 'cantitatea scrisă cu virgulă ajunge număr, nu text',
      virgula.cereri.length === 1 && virgula.cereri[0].corp.recipe[0].qty === 0.005,
      JSON.stringify(virgula.cereri));

    // Zero nu e o cantitate, e o linie care spune „croissantul are drojdie, în
    // cantitate zero". Scoaterea liniei e răspunsul, iar mesajul o spune.
    for (const rea of ['0', '', 'multă', '-1']) {
      const stricata = scena({ id: 7, recipe: [rand(3, rea)], recipeReady: true, recipeErr: '' });
      let mesaj = '';
      let steag = false;
      await stricata.obiect.salveazaReteta(7).catch((e) => { mesaj = e.message; steag = !!e.reteta; });
      check('Produse.dc.html:499', 'cantitatea „' + rea + '” e refuzată în pagină, nu la server',
        stricata.cereri.length === 0 && /Făină/.test(mesaj) && steag,
        JSON.stringify({ cereri: stricata.cereri.length, mesaj, steag }));
    }
  }

  // Lista din care se alege materia primă n-are voie să ofere nici ce e deja în
  // rețetă (serverul refuză cu 409), nici articolul finit al produsului — care
  // s-ar scădea și s-ar adăuga în aceeași mișcare de producție.
  const alegere = /\n {4}const finitId = ([\s\S]*?)\n {4}const libere = ([^\n]*)\n/.exec(logica);
  check('Produse.dc.html:765', 'filtrul listei de materii prime se poate citi din sursă', !!alegere);
  if (alegere) {
    const filtru = new Function('S', 'rec',
      'const finitId = ' + alegere[1] + '\nconst libere = ' + alegere[2] + '\nreturn libere;');
    const depozit = [
      { id: 3, name: 'Făină', unit: 'kg' },
      { id: 4, name: 'Drojdie', unit: 'kg' },
      { id: 9, name: 'Croissant congelat', unit: 'buc' }
    ];
    const rec = [{ itemId: 3, qty: '0,5' }];
    const nume = filtru(
      { stockItems: depozit, form: { id: 7, finished: { id: 9, name: 'Croissant congelat' }, recipe: rec } },
      rec
    ).map((x) => x.name);
    check('Produse.dc.html:765', 'articolul deja pus în rețetă nu se mai oferă a doua oară',
      nume.indexOf('Făină') < 0, JSON.stringify(nume));
    check('Produse.dc.html:765', 'produsul nu se poate pune materie primă în propria lui rețetă',
      nume.indexOf('Croissant congelat') < 0, JSON.stringify(nume));
    check('Produse.dc.html:765', 'restul depozitului rămâne de ales',
      nume.length === 1 && nume[0] === 'Drojdie', JSON.stringify(nume));
    const faraFinit = filtru({ stockItems: depozit, form: { id: 7, finished: null, recipe: [] } }, []);
    check('Produse.dc.html:765', 'produsul fără articol finit nu pierde nimic din listă',
      faraFinit.length === 3, JSON.stringify(faraFinit.map((x) => x.name)));
  }
}

/* =============================== Produse.dc.html · butonul × de pe rând == */

console.log('\n=== Produse.dc.html — × chiar șterge produsul adăugat din greșeală ===');
{
  const { logica } = parti('Produse.dc.html');

  // Manualul promite că un produs care n-a fost comandat niciodată „dispare
  // complet" la ×. Pagina trimitea însă PATCH { active: false }: rândul rămânea
  // pentru totdeauna în listă cu eticheta „scos din catalog", iar fotografia
  // urcată din greșeală rămânea pe disc, fiindcă singurul loc care o șterge e
  // ramura de ștergere a serverului. Scoatem handlerul din sursă și ne uităm ce
  // pleacă la apăsare.
  const exprToggle = /\n {10}onToggle: ([\s\S]*?)\n {8}\}\)\)/.exec(logica);
  check('Produse.dc.html:473', 'handlerul butonului × se poate citi din sursă', !!exprToggle);

  if (exprToggle) {
    const apeluri = [];
    const selfFals = {
      removeProduct: (p) => apeluri.push({ fel: 'sterge', id: p.id }),
      patchProduct: (p, patch) => apeluri.push({ fel: 'patch', id: p.id, patch }),
    };
    const handler = (p) => new Function('self', 'p', 'return ' + exprToggle[1] + ';')(selfFals, p);

    handler({ id: 7, name: 'Test 1', active: 1 })();
    check('Produse.dc.html:473', '× pe un produs din catalog cere ștergerea, nu dezactivarea',
      apeluri.length === 1 && apeluri[0].fel === 'sterge' && apeluri[0].id === 7,
      JSON.stringify(apeluri));

    apeluri.length = 0;
    handler({ id: 7, name: 'Test 1', active: 0 })();
    check('Produse.dc.html:473', '↩ pe un produs scos îl repune în catalog, fără să-l șteargă',
      apeluri.length === 1 && apeluri[0].fel === 'patch' && apeluri[0].patch.active === true,
      JSON.stringify(apeluri));
  }

  // Și funcția însăși, rulată cu un `gp` de mucava: pe ce cale pleacă cererea și
  // ce citește omul după ea. Serverul alege între ștergere și scoatere din
  // catalog, deci mesajul trebuie să fie al lui — altfel pagina promite una și
  // se întâmplă alta.
  const fnSterge = /\n  removeProduct\(p\) \{\n([\s\S]*?)\n  \}/.exec(logica);
  check('Produse.dc.html:473', 'removeProduct() se poate citi din sursă', !!fnSterge);

  if (fnSterge) {
    const ruleaza = async (raspuns) => {
      const cai = [], stare = {};
      const gpFals = { del: (cale) => { cai.push(cale); return Promise.resolve(raspuns); } };
      const obiect = {
        state: stare,
        pull: () => Promise.resolve(),
        setState(x) { Object.assign(stare, x); },
      };
      obiect.removeProduct = new Function('gp', 'return function (p) {' + fnSterge[1] + '\n};')(gpFals);
      obiect.removeProduct({ id: 7, name: 'Test 1' });
      await new Promise((r) => setTimeout(r, 20));
      return { cai, stare };
    };

    const sters = await ruleaza({
      ok: true, deleted: true,
      message: 'Produsul a fost șters. Nu apărea în nicio comandă și nu avea articol în depozit.',
    });
    check('Produse.dc.html:473', 'cererea pleacă pe DELETE /products/{id}',
      sters.cai.join(',') === '/products/7', JSON.stringify(sters.cai));
    check('Produse.dc.html:473', 'omului i se spune că produsul chiar a dispărut',
      /a fost șters/.test(String(sters.stare.msg)), JSON.stringify(sters.stare.msg));

    const cuIstoric = await ruleaza({
      ok: true, deleted: false,
      message: 'Produsul a fost scos din catalog. Apare în 2 comenzi, deci rândul rămâne'
        + ' ca istoricul să nu se rupă. Îl poți repune oricând.',
    });
    check('Produse.dc.html:473', 'produsul cu istoric rămâne, iar explicația e tot a serverului',
      /scos din catalog\. Apare în 2 comenzi/.test(String(cuIstoric.stare.msg)),
      JSON.stringify(cuIstoric.stare.msg));
  }

  check('Produse.dc.html:473', 'pagina nu mai stinge produsul în locul serverului',
    !/\{ active: !p\.active \}/.test(logica), 'a rămas PATCH { active: !p.active }');
}

/* ============================== Produse.dc.html · redenumirea unei grupe == */

console.log('\n=== Produse.dc.html · redenumirea unei grupe ===');
{
  const { marcaj, logica } = parti('Produse.dc.html');

  // Cele patru câmpuri din fila „Grupe” scriau pe server la fiecare tastă, iar
  // răspunsurile întoarse în altă ordine lăsau în baza de date un nume ciuntit.
  // Acum tastarea doar ține minte textul; cererea pleacă la ieșirea din câmp.
  const campuri = [
    ['Name', 'Nume (RO)'], ['NameEn', 'Name (EN)'],
    ['Note', 'Subtitlu (RO)'], ['NoteEn', 'Subtitle (EN)']
  ];
  for (const [prop, eticheta] of campuri) {
    check('Produse.dc.html:490', 'câmpul „' + eticheta + '” din fila „Grupe” salvează la ieșirea din câmp',
      marcaj.includes('onChange="{{ c.on' + prop + ' }}" onBlur="{{ c.on' + prop + 'Blur }}"'));
  }

  check('Produse.dc.html:490', 'tastarea într-un câmp de grupă doar ține minte textul',
    /const field = \(key\) => \(e\) => set\(\{ catEdit: cheie\(key\), catText: e\.target\.value \}\);/.test(logica)
    && !/const field = \(key\) => \(e\) => \{[\s\S]{0,90}gp\.patch\("\/categories\/"/.test(logica));
  check('Produse.dc.html:490', 'reîncărcarea catalogului nu suprascrie câmpul în care se scrie',
    /const text = \(key, val\) => \(S\.catEdit === cheie\(key\) \? S\.catText : \(val \|\| ""\)\);/.test(logica));

  // Și funcția însăși, scoasă din sursă și rulată: ce pleacă spre server și,
  // mai ales, ce NU pleacă.
  const fnGrupa = /\n  incheieGrupa\(catId, key, vechi\) \{\n([\s\S]*?)\n  \}/.exec(logica);
  check('Produse.dc.html:490', 'incheieGrupa() se poate citi din sursă', !!fnGrupa);

  if (fnGrupa) {
    const incearca = (key, scris, vechi) => {
      const trimise = [];
      const stare = { catEdit: '3:' + key, catText: scris, err: '' };
      const gpFals = {
        patch: (cale, corp) => { trimise.push([cale, corp]); return Promise.resolve({ ok: true }); }
      };
      const obiect = {
        state: stare,
        pull() { return Promise.resolve(); },
        setState(x) { Object.assign(stare, x); }
      };
      obiect.incheieGrupa = new Function('gp',
        'return function (catId, key, vechi) {' + fnGrupa[1] + '\n};')(gpFals);
      obiect.incheieGrupa(3, key, vechi);
      return { trimise, stare };
    };

    const redenumire = incearca('name', 'Covrigi calzi', 'Covrigi');
    check('Produse.dc.html:490', 'numele nou pleacă o singură dată, întreg',
      JSON.stringify(redenumire.trimise) === '[["/categories/3",{"name":"Covrigi calzi"}]]',
      JSON.stringify(redenumire.trimise));

    const neatins = incearca('name', 'Covrigi', 'Covrigi');
    check('Produse.dc.html:490', 'numele neschimbat nu mai lasă o intrare în Jurnal',
      neatins.trimise.length === 0, JSON.stringify(neatins.trimise));
    check('Produse.dc.html:490', 'după ieșirea din câmp rândul revine pe textul serverului',
      neatins.stare.catEdit === null, String(neatins.stare.catEdit));

    const golit = incearca('name', '', 'Covrigi');
    check('Produse.dc.html:490', 'câmpul golit nu trimite numele gol pe care serverul îl refuză',
      golit.trimise.length === 0 && /gol/.test(golit.stare.err),
      JSON.stringify(golit.trimise) + ' · „' + golit.stare.err + '”');

    const subtitlu = incearca('noteEn', '', 'warm');
    check('Produse.dc.html:490', 'subtitlul, în schimb, chiar se poate șterge',
      JSON.stringify(subtitlu.trimise) === '[["/categories/3",{"noteEn":""}]]',
      JSON.stringify(subtitlu.trimise));
  }
}

/* =========================== Produse.dc.html · insigna de pe fila Grupe == */

console.log('\n=== Produse.dc.html · câte produse spune insigna că are grupa ===');
{
  const { src, logica } = parti('Produse.dc.html');

  // Insigna număra doar produsele rămase în catalog, iar refuzul de la ștergere
  // le numără pe toate. O grupă din care produsele fuseseră doar scoase din
  // catalog arăta „0 produse", dar la × răspundea „grupa are 3 produse în ea":
  // două cifre care se contrazic pe același ecran, iar adminul nu avea de unde
  // ști de unde ies cele 3, fiindcă rândurile scoase nu se văd în fila Grupe.
  const bloc = /\n {6}const scoase = Math\.max\(0, \(c\.productsAll \|\| 0\) - c\.products\);\n[\s\S]*?\n {8}count: ([\s\S]*?),\n {8}onName:/.exec(logica);
  check('api/lib/catalog.php:47', 'textul insignei se poate citi din sursă', !!bloc);

  if (bloc) {
    const insigna = new Function('c',
      'const scoase = Math.max(0, (c.productsAll || 0) - c.products); return (' + bloc[1] + ');');

    check('api/lib/catalog.php:47', 'grupa fără produse scoase se citește ca înainte',
      insigna({ products: 3, productsAll: 3 }) === '3 produse',
      JSON.stringify(insigna({ products: 3, productsAll: 3 })));
    check('api/lib/catalog.php:47', 'un singur produs rămâne la singular',
      insigna({ products: 1, productsAll: 1 }) === '1 produs',
      JSON.stringify(insigna({ products: 1, productsAll: 1 })));
    check('api/lib/catalog.php:47', 'grupa golită doar din catalog nu mai arată „0 produse”',
      insigna({ products: 0, productsAll: 3 }) === '0 în catalog · 3 scoase',
      JSON.stringify(insigna({ products: 0, productsAll: 3 })));
    check('api/lib/catalog.php:47', 'un singur produs scos se scrie tot la singular',
      insigna({ products: 2, productsAll: 3 }) === '2 în catalog · 1 scos',
      JSON.stringify(insigna({ products: 2, productsAll: 3 })));
  }

  check('api/lib/catalog.php:47', 'nota din fila Grupe spune că și produsele scoase opresc ștergerea',
    src.includes('nici dacă produsele sunt doar scoase din catalog'));
}

/* ================================================ Panou Laborator.dc.html == */

/* ======================= Administrare Comenzi.dc.html · dublul clic ======= */

console.log('\n=== Administrare Comenzi.dc.html · comanda trimisă de două ori ===');
{
  const { marcaj, logica } = parti('Administrare Comenzi.dc.html');

  // Formularul se închide abia în `commit()`, adică după ce răspunde serverul.
  // Între apăsare și răspuns nu se schimba nimic pe ecran, iar al doilea clic
  // pleca tot spre server: la acceptarea unei cereri ieșeau două comenzi, cu
  // numere diferite, pentru aceeași cerere.
  //
  // Proba nu se uită la un atribut din marcaj, ci apasă butonul de două ori
  // înainte ca serverul să apuce să răspundă și numără ce a plecat.
  const fereastraAdm = {
    document: {
      hidden: false, addEventListener() {}, removeEventListener() {},
      documentElement: { setAttribute() {} },
    },
    setInterval, clearInterval, fetch: async () => { throw new Error('fără rețea în probă'); },
    location: { origin: 'http://127.0.0.1:8100', hostname: '127.0.0.1', protocol: 'http:', port: '8100' },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    history: { pushState() {}, back() {}, state: null },
    addEventListener() {}, removeEventListener() {},
    console,
  };
  new Function('window', 'globalThis', cite('shared/gp-api.js'))(fereastraAdm, fereastraAdm);
  const gpa = fereastraAdm.gp;

  // Catalogul se cere la încărcarea blocului; `save()` refuză fără el.
  const CAT = [{ id: 3, name: 'Croissant simplu', price: 4.5 }];
  const getVechi = gpa.get;
  gpa.get = (cale) => (String(cale).startsWith('/products')
    ? Promise.resolve({ products: CAT })
    : Promise.resolve({}));

  const corpAdm = logica.slice(logica.indexOf('>') + 1, logica.lastIndexOf('</script>'));
  class DCLogicFalsAdm {
    constructor(props) { this.props = props || {}; }
    // React NU schimbă starea pe loc: o aplică la următoarea randare. Proba
    // trebuie să se poarte la fel, altfel un semafor ținut în stare ar părea
    // că oprește al doilea clic, deși în browser trei clicuri în aceeași
    // sutime de secundă citesc toate aceeași stare veche și trec toate.
    setState(x) {
      Promise.resolve().then(() => {
        this.state = Object.assign({}, this.state, typeof x === 'function' ? x(this.state) : x);
      });
    }
  }
  let ComponentAdm = null;
  try {
    ComponentAdm = new Function('gp', 'DCLogic', 'window', 'document', 'localStorage',
      corpAdm + '\n;return Component;')(
      gpa, DCLogicFalsAdm, fereastraAdm, fereastraAdm.document, fereastraAdm.localStorage);
  } catch (e) {
    console.log('  (pagina nu a putut fi încărcată: ' + e.message + ')');
  }
  gpa.get = getVechi;
  check('Administrare Comenzi.dc.html:728', 'blocul de logică al panoului de comenzi se poate rula în probă',
    typeof ComponentAdm === 'function');

  // Lăsăm catalogul să ajungă în variabila din bloc (se cere într-un `then`).
  await Promise.resolve(); await Promise.resolve(); await Promise.resolve();

  /**
   * Deschide un formular, apasă „Salvează” de `clicuri` ori la rând — fără să
   * aștepte serverul între ele — și abia apoi lasă răspunsul să vină.
   * Întoarce ce a plecat spre server și ce se vedea pe buton între timp.
   */
  const apasa = async (form, clicuri, raspuns) => {
    const cereri = [];
    if (typeof ComponentAdm !== 'function') return { cereri, intre: {}, dupa: {} };

    let deblocheaza = null;
    const asteapta = new Promise((res) => { deblocheaza = res; });
    const postVechi = gpa.post, patchVechi = gpa.patch;
    gpa.post = (cale, corp) => { cereri.push({ fel: 'post', cale, corp }); return asteapta.then(() => raspuns()); };
    gpa.patch = (cale, corp) => { cereri.push({ fel: 'patch', cale, corp }); return asteapta.then(() => raspuns()); };
    try {
      const c = new ComponentAdm({});
      c.props = {};
      c.state = Object.assign({}, c.state, { orders: [], requests: [], form });
      c.pullOrders = () => Promise.resolve();
      c.pullRequests = () => Promise.resolve();

      // Clicurile cad în aceeași tură de buclă, ca la un dublu clic grăbit:
      // între ele React n-a apucat să randeze nimic.
      for (let i = 0; i < clicuri; i++) {
        const v = c.renderVals();
        if (typeof v.onSave === 'function') v.onSave();
      }
      await Promise.resolve(); await Promise.resolve();
      const intre = c.renderVals();
      deblocheaza();
      await asteapta;
      // Refuzul serverului trece prin `.catch`, adică prin câteva ture de
      // microtaskuri. Așteptăm o tură întreagă de buclă, nu un număr ghicit.
      await new Promise((r) => setTimeout(r, 0));
      return { cereri, intre, dupa: c.renderVals() };
    } finally {
      gpa.post = postVechi; gpa.patch = patchVechi;
    }
  };

  const formNou = (peste) => Object.assign({
    id: null, ref: '', cust: 'Cofetăria Belvedere', chan: 'Ridicare',
    day: '2026-09-10', slot: '08:00', status: 'În așteptare', urgent: false, timed: false,
    note: '', phone: '', addr: '', zone: '', variant: 'copt',
    items: [{ q: '2', n: 'Croissant simplu', variant: 'copt', productId: 3, n0: 'Croissant simplu' }],
  }, peste || {});

  const bun = () => Promise.resolve({ order: { id: 51, ref: '#1042' } });

  const dublu = await apasa(formNou(), 2, bun);
  check('Administrare Comenzi.dc.html:728', 'două apăsări la rând trimit o singură comandă nouă',
    dublu.cereri.length === 1 && dublu.cereri[0].cale === '/orders',
    dublu.cereri.length + ' cereri: ' + JSON.stringify(dublu.cereri.map((x) => x.cale)));
  check('Administrare Comenzi.dc.html:728', 'cât timp cererea e pe drum butonul e stins',
    dublu.intre.saveOff === true, 'saveOff = ' + JSON.stringify(dublu.intre.saveOff));
  check('Administrare Comenzi.dc.html:728', 'butonul spune că salvează, ca omul să nu creadă că n-a apăsat',
    /salv/i.test(String(dublu.intre.saveLabel)), 'scria „' + dublu.intre.saveLabel + '”');
  check('Administrare Comenzi.dc.html:728', 'după răspunsul bun formularul se închide',
    dublu.dupa.formOpen === false, 'formOpen = ' + JSON.stringify(dublu.dupa.formOpen));

  // Acceptarea unei cereri e cazul care doare: două comenzi cu numere diferite
  // pentru aceeași cerere, deci marfă coaptă de două ori și încă o oprire pe rută.
  const cerere = await apasa(formNou({ fromReq: 17 }), 3, bun);
  check('Administrare Comenzi.dc.html:728', 'trei apăsări acceptă cererea o singură dată',
    cerere.cereri.length === 1 && cerere.cereri[0].cale === '/requests/17/accept',
    cerere.cereri.length + ' cereri: ' + JSON.stringify(cerere.cereri.map((x) => x.cale)));

  const editare = await apasa(formNou({ id: 51, ref: '#1042' }), 2, bun);
  check('Administrare Comenzi.dc.html:728', 'și modificarea unei comenzi pleacă o singură dată',
    editare.cereri.length === 1 && editare.cereri[0].fel === 'patch',
    editare.cereri.length + ' cereri: ' + JSON.stringify(editare.cereri.map((x) => x.cale)));

  // Dacă serverul refuză, butonul trebuie să se aprindă la loc: altfel omul
  // rămâne cu formularul completat și fără nicio cale să-l trimită.
  const refuz = await apasa(formNou(), 1,
    () => Promise.reject(new Error('Ziua aleasă nu mai este disponibilă.')));
  check('Administrare Comenzi.dc.html:728', 'după un refuz butonul se aprinde la loc',
    refuz.dupa.saveOff === false && String(refuz.dupa.formErr).includes('disponibilă'),
    JSON.stringify({ saveOff: refuz.dupa.saveOff, formErr: refuz.dupa.formErr }));
  const dinNou = await apasa(formNou(), 1, bun);
  check('Administrare Comenzi.dc.html:728', 'un formular nou deschis pornește cu butonul aprins',
    dinNou.cereri.length === 1, dinNou.cereri.length + ' cereri');

  // Și în marcaj: un buton fără `disabled` primește clicul chiar dacă logica
  // îl ignoră, iar omul nu vede nimic schimbat pe ecran.
  check('Administrare Comenzi.dc.html:728', 'butonul de salvare chiar poartă `disabled` în marcaj',
    /data-adm="btnsave"[^>]*disabled="\{\{ saveOff \}\}"/.test(marcaj),
    'nu am găsit disabled pe butonul btnsave');
}

console.log('\n=== Panou Laborator.dc.html · comanda apăsată ===');
{
  const { logica } = parti('Panou Laborator.dc.html');

  // Dacă blocul de logică nu se mai parsează, funcțiile scoase mai jos ar putea
  // trece proba deși pagina nici nu pornește în browser.
  const corp = logica.slice(logica.indexOf('>') + 1, logica.lastIndexOf('</script>'));
  let sintaxa = true;
  try { new Function('DCLogic', 'gp', corp); } catch (e) { sintaxa = false; }
  check('Panou Laborator.dc.html:363', 'blocul de logică al panoului se parsează', sintaxa);

  // Referința repornește de la „#1001" în fiecare zi, iar panoul ține șapte zile
  // în aceeași listă. Scoatem `writeStatus` din sursă și o rulăm cu două comenzi
  // care poartă aceeași referință, în ordinea în care le trimite serverul
  // (`ORDER BY o.day DESC`): prima e cea din ziua cea mai îndepărtată.
  const fnWrite = /\n  writeStatus\(([^)]*)\)\s*\{([\s\S]*?)\n  \}\n/.exec(logica);
  check('Panou Laborator.dc.html:363', 'funcția writeStatus se poate citi din sursă', !!fnWrite);

  if (fnWrite) {
    check('Panou Laborator.dc.html:363', 'writeStatus primește id-ul comenzii, nu referința',
      fnWrite[1].trim().split(/\s*,\s*/)[0] === 'id', 'semnătura este (' + fnWrite[1] + ')');

    const cereri = [];
    const gpFals = { patch: (cale, corpul) => { cereri.push({ cale, corp: corpul }); return Promise.resolve({}); } };
    const panou = {
      state: {
        orders: [
          { id: 732, ref: '#1001', day: '02', status: 'La dospit' },
          { id: 733, ref: '#1001', day: '29', status: 'În așteptare' },
        ],
      },
      setState(p) { Object.assign(this.state, p); },
      pull() {},
      clearPending(id) { const c = Object.assign({}, this.__pending || {}); delete c[id]; this.__pending = c; },
    };
    // O construim cu semnătura ei reală, ca proba să pice cu un mesaj limpede în
    // loc să crape, dacă cineva pune la loc referința în locul id-ului.
    panou.writeStatus = new Function('gp', 'return function (' + fnWrite[1] + ') {' + fnWrite[2] + '\n};')(gpFals);
    panou.writeStatus(733, 'În cuptor');

    check('Panou Laborator.dc.html:363', 'PATCH-ul pleacă pe id-ul comenzii apăsate',
      cereri.length === 1 && cereri[0].cale === '/orders/733', JSON.stringify(cereri));
    check('Panou Laborator.dc.html:363', 'comanda cu aceeași referință din altă zi rămâne unde era',
      panou.state.orders[0].status === 'La dospit' && panou.state.orders[1].status === 'În cuptor',
      JSON.stringify(panou.state.orders.map((o) => o.id + ':' + o.status)));
  }

  // Aceeași cheie neunivocă otrăvea și bifele pe poziții, și selecția, și
  // ordinea manuală salvată în browserul tabletei.
  //
  // Bifele au plecat cu totul din browser: cantitatea făcută stă pe server. Dar
  // cheia neunivocă doar a coborât cu un nivel — indexul poziției în tablou nu e
  // adresă, fiindcă un PATCH din Administrare comenzi rescrie pozițiile și le
  // schimbă ordinea. Proba coboară odată cu ea, pe id-ul poziției.
  check('Panou Laborator.dc.html:363', 'cantitatea făcută se raportează pe id de poziție, nu pe indexul din tablou',
    /this\.reportProd\(ord\.id, it\.itemId,/.test(logica) && !/S\.done\[/.test(logica));
  check('Panou Laborator.dc.html:363', 'comanda selectată se ține pe id',
    /S\.sel === ord\.id/.test(logica) && !/S\.sel === ord\.ref/.test(logica)
      && /set\(\{ sel: ord\.id \}\)/.test(logica));
  check('Panou Laborator.dc.html:363', 'ordinea manuală se ține pe id',
    /const ids = base\.map\(x => x\.id\)/.test(logica) && !/base\.map\(x => x\.ref\)/.test(logica));
  check('Panou Laborator.dc.html:363', 'butoanele cardului trimit id-ul, nu referința',
    !/advance\(ord\.ref/.test(logica) && !/back\(ord\.ref/.test(logica) && !/reportProd\(ord\.ref/.test(logica));
}

console.log('\n=== Panou Laborator.dc.html · pasul pe bucăți ===');
{
  const { logica, marcaj } = parti('Panou Laborator.dc.html');

  // Tura de dimineață nu termină lotul, iar cea de după-amiază trebuie să afle
  // câte au mai rămas. Bifa de dinainte spunea doar „poziția e atinsă", și doar
  // în browserul acelei tablete.
  //
  // Scoatem raportarea din sursă și o rulăm cu un `gp` fals și cu ceasuri false:
  // proba trebuie să vadă exact ce pleacă spre server, nu doar că pleacă ceva.
  const fnRep = /\n  reportProd\(([^)]*)\)\s*\{([\s\S]*?)\n  \}\n/.exec(logica);
  const fnTri = /\n  trimiteProd\(\)\s*\{([\s\S]*?)\n  \}\n/.exec(logica);
  check('Panou Laborator.dc.html:192', 'raportarea producției se poate citi din sursă', !!fnRep && !!fnTri);

  if (fnRep && fnTri) {
    const cereri = [];
    const gpFals = { post: (cale, corpul) => { cereri.push({ cale, corp: corpul }); return Promise.resolve({}); } };
    let ceas = null;
    const panou = {
      state: {
        orders: [{
          id: 732,
          items: [
            { itemId: 51, q: '8', n: 'Croasant', done: 0 },
            { itemId: 52, q: '3', n: 'Tartă cu vișine', done: 0 },
          ],
        }],
      },
      setState(p) { Object.assign(this.state, p); },
      pull() {},
    };
    panou.reportProd = new Function(
      'gp', 'setTimeout', 'clearTimeout',
      'return function (' + fnRep[1] + ') {' + fnRep[2] + '\n};'
    )(gpFals, (fn) => { ceas = fn; return 1; }, () => { ceas = null; });
    panou.trimiteProd = new Function('gp', 'return function () {' + fnTri[1] + '\n};')(gpFals);

    // Trei apăsări pe „+", una după alta, ca la tejghea.
    panou.reportProd(732, 51, 1);
    panou.reportProd(732, 51, 2);
    panou.reportProd(732, 51, 3);

    check('Panou Laborator.dc.html:192', 'apăsările repetate nu pleacă una câte una spre server',
      cereri.length === 0, cereri.length + ' cereri înainte de scurgerea întârzierii');
    check('Panou Laborator.dc.html:192', 'cifra apare pe ecran înainte de răspunsul serverului',
      panou.state.orders[0].items[0].done === 3, JSON.stringify(panou.state.orders[0].items));

    if (ceas) ceas();

    check('Panou Laborator.dc.html:192', 'pleacă un singur raport, pe ruta de producție a comenzii',
      cereri.length === 1 && cereri[0].cale === '/orders/732/production', JSON.stringify(cereri));
    // Cheia întregii rute: se trimite TOTALUL, nu adaosul. Cu adaos, cele trei
    // apăsări ar scădea făină pentru șase croasante în loc de trei.
    check('Panou Laborator.dc.html:192', 'raportul poartă totalul poziției, nu adaosul',
      cereri.length === 1 && JSON.stringify(cereri[0].corp) === JSON.stringify({ items: [{ id: 51, doneQty: 3 }] }),
      JSON.stringify(cereri[0] && cereri[0].corp));
    check('Panou Laborator.dc.html:192', 'poziția neatinsă nu intră în raport',
      panou.state.orders[0].items[1].done === 0);

    // Două poziții din aceeași comandă, într-o singură cerere: ruta le scrie
    // într-o singură tranzacție, deci ori intră tot consumul, ori niciunul.
    cereri.length = 0;
    panou.reportProd(732, 51, 8);
    panou.reportProd(732, 52, 3);
    if (ceas) ceas();
    check('Panou Laborator.dc.html:192', 'tot lotul pleacă într-o singură cerere',
      cereri.length === 1 && cereri[0].corp.items.length === 2, JSON.stringify(cereri));
  }

  // Progresul, socotit din sursă. `nrScurt`, `facutPe` și `bucati` stau lipite
  // în `renderVals`, deci le luăm blocul întreg și le rulăm.
  const blocSoc = /\n(    const nrScurt = [\s\S]*?\n    \};)\n/.exec(logica);
  check('Panou Laborator.dc.html:552', 'socoteala progresului se poate citi din sursă', !!blocSoc);

  if (blocSoc) {
    const H = new Function('gp', blocSoc[1] + '\nreturn { nrScurt, facutPe, bucati };')({
      qty: (x) => Number(String(x).replace(',', '.')) || 0,
    });

    // Numărate pe poziții, cele două tarte făcute ar arăta comanda pe jumătate
    // gata, iar tura de după-amiază ar crede că mai are puțin. Sunt 300 de
    // croasante de făcut.
    const lot = { items: [{ q: '300', done: 0 }, { q: '2', done: 2 }] };
    const b = H.bucati(lot);
    check('Panou Laborator.dc.html:552', 'progresul se numără în bucăți, nu în poziții',
      b.cerut === 302 && b.facut === 2, JSON.stringify(b));

    check('Panou Laborator.dc.html:552', 'cantitatea făcută nu trece de cât s-a cerut',
      H.facutPe({ q: '5', done: 9 }) === 5, String(H.facutPe({ q: '5', done: 9 })));
    check('Panou Laborator.dc.html:552', 'o cifră negativă nu trage progresul înapoi',
      H.facutPe({ q: '5', done: -3 }) === 0, String(H.facutPe({ q: '5', done: -3 })));
    check('Panou Laborator.dc.html:552', 'jumătatea de tavă se scrie cu virgulă, întregul fără zecimale',
      H.nrScurt(0.5) === '0,5' && H.nrScurt(7) === '7', H.nrScurt(0.5) + ' / ' + H.nrScurt(7));
  }

  // Bifele salvate în browserul tabletei nu se traduc în cantități: erau pe
  // indexul poziției, iar indexul se schimbă la prima rescriere a comenzii.
  const fnCit = /\n  citesteLocal\(\)\s*\{([\s\S]*?)\n  \}\n/.exec(logica);
  check('Panou Laborator.dc.html:366', 'citirea stării locale se poate citi din sursă', !!fnCit);

  if (fnCit) {
    const depozit = {
      val: JSON.stringify({ st: { 732: 'În cuptor' }, done: { 732: { 0: true, 1: true } }, ord: { d02: [7, 8] } }),
      getItem() { return this.val; },
      setItem(_k, v) { this.val = v; },
    };
    const p = { state: {}, setState(patch) { Object.assign(this.state, patch); } };
    p.citesteLocal = new Function('localStorage', 'KEY', 'return function () {' + fnCit[1] + '\n};')(depozit, 'gp-lab-state-v1');
    p.citesteLocal();

    check('Panou Laborator.dc.html:366', 'bifele vechi nu se ghicesc în cantități făcute',
      p.state.done === undefined && !/"done"/.test(depozit.val), depozit.val);
    check('Panou Laborator.dc.html:366', 'pierderea bifelor vechi se spune cu voce tare',
      String(p.state.toast || '').includes('bucăți'), String(p.state.toast));
    check('Panou Laborator.dc.html:366', 'ordinea manuală și etapele locale rămân neatinse',
      JSON.stringify(p.state.ord) === JSON.stringify({ d02: [7, 8] })
        && JSON.stringify(p.state.st) === JSON.stringify({ 732: 'În cuptor' }),
      JSON.stringify(p.state));

    // A doua deschidere, după curățenie: fără bife vechi, fără notificare.
    const p2 = { state: {}, setState(patch) { Object.assign(this.state, patch); } };
    p2.citesteLocal = new Function('localStorage', 'KEY', 'return function () {' + fnCit[1] + '\n};')(depozit, 'gp-lab-state-v1');
    p2.citesteLocal();
    check('Panou Laborator.dc.html:366', 'a doua deschidere nu mai anunță nimic',
      p2.state.toast === undefined, String(p2.state.toast));
  }

  // Reîmprospătarea de la patru secunde citește din bază, deci poate întoarce o
  // cifră mai veche decât degetul de pe „+".
  check('Panou Laborator.dc.html:405', 'reîmprospătarea nu șterge de pe ecran cifra abia apăsată',
    /__pendingProd/.test(logica) && /facute\[it\.itemId\] !== undefined/.test(logica));

  // Și în marcaj: pe o tabletă atinsă cu degete cu făină, un buton mic e un
  // buton apăsat greșit.
  check('Panou Laborator.dc.html:192', 'butoanele de „−" și „+" rămân de 44 de pixeli',
    /onClick="\{\{ it\.onMinus \}\}"[^>]*width:44px;height:44px/.test(marcaj)
      && /onClick="\{\{ it\.onPlus \}\}"[^>]*width:44px;height:44px/.test(marcaj));
  check('Panou Laborator.dc.html:192', 'rândul poziției arată cât s-a făcut din cât',
    /\{\{ it\.count \}\}/.test(marcaj) && /min-height:52px[^>]*background:\{\{ it\.bg \}\}/.test(marcaj));
  check('Panou Laborator.dc.html:200', 'butonul de lot nu mai promite bife',
    /Tot lotul e gata<\/button>/.test(marcaj) && !/Bifează toate/.test(marcaj));
}

console.log('\n=== Panou Laborator.dc.html · fereastra de zile ===');
{
  const { logica } = parti('Panou Laborator.dc.html');

  // Scoatem din sursă blocul care calculează zilele, plus corpul ceasului, și le
  // rulăm cu un ceas pe care îl mutăm noi peste miezul nopții. `Date` intră ca
  // parametru, deci codul paginii vede ceasul nostru, nu pe cel al mașinii.
  const codZile = /const DOW = \["Dum"[\s\S]*?const refreshDays = \(\) => \{[\s\S]*?\n\};/.exec(logica);
  const corpTick = /\n  tick\(\)\s*\{([\s\S]*?)\n  \}\n/.exec(logica);
  check('Panou Laborator.dc.html:268', 'fereastra de zile și ceasul se pot citi din sursă', !!(codZile && corpTick));

  if (codZile && corpTick) {
    const ceas = { acum: '2026-08-29T07:12:00' };
    const DataFalsa = class extends Date {
      constructor(...a) { if (a.length === 0) super(ceas.acum); else super(...a); }
    };
    const modul = new Function('Date', codZile[0]
      + '\nreturn { refreshDays, zile: () => DAY_KEYS, from: () => FROM_DAY, to: () => TO_DAY,'
      + ' tick: function () {' + corpTick[1] + '\n} };')(DataFalsa);

    check('Panou Laborator.dc.html:268', 'la deschidere fereastra pornește de la ziua curentă',
      modul.from() === '2026-08-29' && modul.to() === '2026-09-04', modul.from() + ' … ' + modul.to());

    const tableta = {
      state: { day: 0 },
      setState(p, cb) { Object.assign(this.state, p); if (cb) cb(); },
      pull() { this.__pulls = (this.__pulls || 0) + 1; },
    };
    ceas.acum = '2026-08-30T00:04:00';
    modul.tick.call(tableta);
    check('Panou Laborator.dc.html:268', 'după miezul nopții panoul cere de la server fereastra zilei noi',
      modul.from() === '2026-08-30' && modul.to() === '2026-09-05', modul.from() + ' … ' + modul.to());
    check('Panou Laborator.dc.html:268', 'fila marcată drept zi curentă e ziua de azi, nu cea de ieri',
      modul.zile()[tableta.state.day].iso === '2026-08-30', modul.zile()[tableta.state.day].iso);
    check('Panou Laborator.dc.html:268', 'comenzile se cer din nou pentru fereastra nouă',
      tableta.__pulls === 1, 'reîncărcări: ' + tableta.__pulls);

    // Cine se uita la comenzile de poimâine trebuie să rămână pe aceeași dată din
    // calendar, nu să fie mutat pe altă listă fără să fi atins nimic.
    const alesCuMana = {
      state: { day: 3 },
      setState(p, cb) { Object.assign(this.state, p); if (cb) cb(); },
      pull() {},
    };
    const inainte = modul.zile()[3].iso;
    ceas.acum = '2026-08-31T00:02:00';
    modul.tick.call(alesCuMana);
    check('Panou Laborator.dc.html:268', 'ziua aleasă cu mâna rămâne aceeași dată din calendar',
      modul.zile()[alesCuMana.state.day].iso === inainte,
      inainte + ' → ' + modul.zile()[alesCuMana.state.day].iso);

    // Iar ceasul care bate de trei ori pe minut nu are voie să miște fereastra
    // în cursul zilei: altfel panoul ar reîncărca lista la fiecare bătaie.
    const fereastraDeDimineata = modul.from();
    ceas.acum = '2026-08-31T09:30:00';
    modul.tick.call(alesCuMana);
    check('Panou Laborator.dc.html:268', 'în cursul zilei ceasul nu clatină fereastra',
      modul.from() === fereastraDeDimineata, fereastraDeDimineata + ' → ' + modul.from());
  }
}

console.log('\n=== Panou Laborator.dc.html · reîmprospătarea și refuzul salvării ===');
{
  const { marcaj, logica } = parti('Panou Laborator.dc.html');

  // Răspunsul unui GET plecat înainte de apăsare conține încă etapa veche. Rulăm
  // `pull()` cu exact acel răspuns, o dată cu o apăsare încă neconfirmată și o
  // dată fără, ca paza să nu ajungă să înghețe panoul pe o etapă veche.
  const fnPull = /\n  pull\(\)\s*\{([\s\S]*?)\n  \}\n/.exec(logica);
  check('Panou Laborator.dc.html:334', 'funcția pull se poate citi din sursă', !!fnPull);

  if (fnPull) {
    const gpFals = {
      get: () => Promise.resolve({
        orders: [{ id: 733, ref: '#1001', day: '2026-08-29', status: 'În cuptor', items: [] }],
      }),
      toPageOrder: (o) => ({ id: o.id, ref: o.ref, day: (o.day || '').slice(-2), status: o.status, items: [] }),
      cancelled: () => 'Anulată',
    };
    const faPull = new Function('gp', 'DAY_KEYS', 'FROM_DAY', 'TO_DAY', 'location',
      'return function () {' + fnPull[1] + '\n};')(gpFals, [{ date: '29' }], '2026-08-29', '2026-09-04', {});

    const cuApasare = {
      __pending: { 733: 'La ambalare' },
      state: { orders: [] },
      setState(p) { Object.assign(this.state, p); },
    };
    await faPull.call(cuApasare);
    check('Panou Laborator.dc.html:334', 'reîmprospătarea nu șterge de pe ecran etapa tocmai apăsată',
      cuApasare.state.orders[0].status === 'La ambalare', JSON.stringify(cuApasare.state.orders));

    const faraApasare = { state: { orders: [] }, setState(p) { Object.assign(this.state, p); } };
    await faPull.call(faraApasare);
    check('Panou Laborator.dc.html:334', 'fără o apăsare în așteptare, panoul arată etapa de pe server',
      faraApasare.state.orders[0].status === 'În cuptor', JSON.stringify(faraApasare.state.orders));
  }

  // Refuzul serverului — de pildă o etapă redenumită între timp din Setări —
  // trebuie să ajungă pe ecranul din laborator, nu doar în consola browserului.
  const fnWrite = /\n  writeStatus\(([^)]*)\)\s*\{([\s\S]*?)\n  \}\n/.exec(logica);
  if (fnWrite) {
    const gpRefuz = { patch: () => Promise.reject(new Error('Status necunoscut.')) };
    const refuzat = {
      state: { orders: [{ id: 733, ref: '#1001', day: '29', status: 'În cuptor' }], toast: '' },
      setState(p) { Object.assign(this.state, p); },
      pull() { this.__pulls = (this.__pulls || 0) + 1; },
      clearPending(id) { const c = Object.assign({}, this.__pending || {}); delete c[id]; this.__pending = c; },
    };
    refuzat.writeStatus = new Function('gp', 'return function (' + fnWrite[1] + ') {' + fnWrite[2] + '\n};')(gpRefuz);
    refuzat.writeStatus(733, 'La ambalare');
    await new Promise((r) => setTimeout(r, 5));

    check('Panou Laborator.dc.html:334', 'un refuz al salvării ajunge pe ecran, nu doar în consolă',
      /Nu s-a salvat/.test(refuzat.state.toast || ''), JSON.stringify(refuzat.state.toast));
    check('Panou Laborator.dc.html:334', 'după un refuz panoul cere din nou adevărul de la server',
      refuzat.__pulls === 1, 'reîncărcări: ' + refuzat.__pulls);
  }

  check('Panou Laborator.dc.html:334', 'pagina are unde să arate mesajul de eroare',
    marcaj.includes('<sc-if value="{{ toast }}"') && marcaj.includes('{{ toast }}</p>'));
  check('Panou Laborator.dc.html:334', 'renderVals întoarce mesajul, ca marcajul să-l poată lega',
    /toast: S\.toast/.test(logica) && /onToastClose:/.test(logica));
}

/* ============== Panou Laborator.dc.html · reîmprospătarea între file ===== */

console.log('\n=== Panou Laborator.dc.html · reîmprospătarea între file ===');
{
  const { logica } = parti('Panou Laborator.dc.html');

  // Ascultătorul de „storage" se poate chiar rula: îl scoatem din sursă și îi
  // dăm un `this` de mucava. Aici stătea un ReferenceError — cheia se compara
  // cu `OKEY`, un nume care nu există în pagină — deci o probă care ar căuta
  // doar un șir de caractere n-ar fi prins nimic: textul arăta cuminte.
  //
  // Capătul funcției îl luăm înapoi de la rândul care o înregistrează, ca
  // proba să scoată din sursă la fel de bine varianta pe un rând și cea pe mai
  // multe rânduri: altfel, dată înapoi reparația, proba ar crăpa în loc să pice.
  const a = logica.indexOf('this.onStore = ');
  const z = logica.indexOf('window.addEventListener("storage"', a);
  const b = a > 0 && z > a ? logica.lastIndexOf('};', z) : -1;
  check('Panou Laborator.dc.html:338', 'ascultătorul de „storage” se poate citi din sursă',
    a > 0 && b > a);

  if (a > 0 && b > a) {
    const sursa = logica.slice(a + 'this.onStore = '.length, b + 1);
    const KEY = 'gp-lab-state-v1';
    let face = null, deCompilare = null;
    try { face = new Function('KEY', 'return ' + sursa + ';'); } catch (err) { deCompilare = err; }
    check('Panou Laborator.dc.html:338', 'ascultătorul scos din sursă se compilează',
      face !== null, String(deCompilare));

    const cere = (e) => {
      const urme = { citit: 0, adus: 0 };
      const eu = { citesteLocal: () => urme.citit++, pull: () => urme.adus++ };
      let aExplodat = null;
      try { face.call(eu, KEY)(e); } catch (err) { aExplodat = err; }
      return { urme, aExplodat };
    };

    if (face) {
      const aceeasiCheie = cere({ key: KEY });
      check('Panou Laborator.dc.html:338', 'un eveniment „storage” nu mai aruncă ReferenceError',
        aceeasiCheie.aExplodat === null, String(aceeasiCheie.aExplodat));
      check('Panou Laborator.dc.html:338', 'schimbarea din altă filă reîncarcă starea locală, nu doar comenzile',
        aceeasiCheie.urme.citit === 1 && aceeasiCheie.urme.adus === 1,
        JSON.stringify(aceeasiCheie.urme));

      const altaCheie = cere({ key: 'cu-totul-altceva' });
      check('Panou Laborator.dc.html:338', 'o cheie străină nu pune panoul pe drumuri',
        altaCheie.aExplodat === null && altaCheie.urme.citit === 0 && altaCheie.urme.adus === 0,
        JSON.stringify(altaCheie.urme));

      const faraCheie = cere({});
      check('Panou Laborator.dc.html:338', 'un eveniment fără cheie (localStorage golit) reîmprospătează tot',
        faraCheie.aExplodat === null && faraCheie.urme.citit === 1 && faraCheie.urme.adus === 1,
        JSON.stringify(faraCheie.urme));
    }
  }

  // Citirea stării locale trebuie să fie o metodă de sine stătătoare: altfel
  // ascultătorul n-are ce chema și bifele celeilalte file rămân pe ecran.
  check('Panou Laborator.dc.html:338', 'starea locală se citește dintr-o metodă, chemată și la deschidere',
    logica.includes('  citesteLocal() {') && logica.includes('    this.citesteLocal();'));
}

/* ====================================================== shared/gp-api.js == */

console.log('\n=== shared/gp-api.js ===');
{
  // gp.poll() se poate chiar rula aici: are nevoie doar de `document` și de
  // cronometre. Îl încărcăm cu un `window` de mucava.
  const src = cite('shared/gp-api.js');
  const fereastra = {
    document: { hidden: false, addEventListener() {}, removeEventListener() {} },
    setInterval, clearInterval, fetch: async () => { throw new Error('fără rețea în probă'); },
    location: { origin: 'http://127.0.0.1:8100', hostname: '127.0.0.1', protocol: 'http:', port: '8100' },
    sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    console,
  };
  new Function('window', 'globalThis', src + '\n;globalThis.__gp = window.gp;')(fereastra, fereastra);
  const gp = fereastra.gp;

  check('Client.dc.html:302', 'ajutorul comun chiar expune gp.poll()', typeof gp?.poll === 'function');

  if (typeof gp?.poll === 'function') {
    const rezultat = await new Promise((gata) => {
      let pornite = 0, simultane = 0, maxim = 0;
      const stop = gp.poll(async () => {
        pornite++;
        simultane++;
        if (simultane > maxim) maxim = simultane;
        // O tură mai lentă decât intervalul: exact cazul unei legături proaste.
        await new Promise((r) => setTimeout(r, 70));
        simultane--;
      }, 10);
      setTimeout(() => { stop(); gata({ pornite, maxim }); }, 300);
    });
    // Fără pază, la 10 ms interval și 70 ms per tură se încalecă imediat.
    check('Client.dc.html:302', 'o tură nelămurită nu lasă alta să plece',
      rezultat.maxim === 1, 's-au suprapus ' + rezultat.maxim);
    check('Client.dc.html:302', 'reîmprospătarea chiar rulează, nu s-a blocat de tot',
      rezultat.pornite >= 2, 'doar ' + rezultat.pornite + ' ture');
  }

  // Fila ascunsă nu mai interoghează serverul.
  if (typeof gp?.poll === 'function') {
    const cate = await new Promise((gata) => {
      let n = 0;
      fereastra.document.hidden = true;
      const stop = gp.poll(async () => { n++; }, 10);
      setTimeout(() => { stop(); fereastra.document.hidden = false; gata(n); }, 120);
    });
    check('Client.dc.html:302', 'fila ascunsă nu mai cere nimic de la server', cate === 0, cate + ' ture');
  }
}

/* ==================================================== Ruta Sofer.dc.html == */

console.log('\n=== Ruta Sofer.dc.html — opririle nu se aleg după un text din cod ===');
{
  const { logica } = parti('Ruta Sofer.dc.html');

  // Modurile de onorare sunt o listă editabilă din Setări, deci pagina nu are
  // voie să păstreze doar comenzile cu textul „Livrare". Scoatem chiar
  // `stopsFor()` din sursă și o rulăm, ca proba să nu se uite la un șir.
  const fnStops = /\n  stopsFor\(day\) \{([\s\S]*?)\n  \}/.exec(logica);
  check('Ruta Sofer.dc.html:279', 'stopsFor() se poate citi din sursă', !!fnStops);

  if (fnStops) {
    const stopsFor = new Function('return function (day) {' + fnStops[1] + '\n};')();
    const o = (id, chan, slot) => ({ id, chan, day: '02', slot, routeSeq: null });
    const pagina = { state: { orders: [
      o(1, 'Curier', '08:00'),
      o(2, 'Livrare la domiciliu', '06:30'),
      o(4, 'Livrare', '10:00'),
    ] } };

    const ids = stopsFor.call(pagina, '02').map((x) => x.id);
    check('Ruta Sofer.dc.html:279', 'un mod de onorare adăugat din Setări („Curier") rămâne oprire',
      ids.includes(1), JSON.stringify(ids));
    check('Ruta Sofer.dc.html:279', '„Livrare" redenumită nu golește ruta',
      ids.includes(2), JSON.stringify(ids));
    check('Ruta Sofer.dc.html:279', 'opririle rămân în ordinea intervalelor promise',
      ids.join(',') === '2,1,4', JSON.stringify(ids));

    // Ziua cerută rămâne singura afișată: între apăsarea altei zile și
    // răspunsul serverului, pagina încă ține opririle zilei dinainte.
    const alta = stopsFor.call({ state: { orders: [o(9, 'Livrare', '08:00')] } }, '03');
    check('Ruta Sofer.dc.html:279', 'opririle altei zile nu se amestecă',
      alta.length === 0, JSON.stringify(alta.map((x) => x.id)));
  }

  check('Ruta Sofer.dc.html:279', 'pagina nu mai compară modul de onorare cu un text scris în cod',
    !/o\.chan === "Livrare"/.test(logica), 'a rămas o comparație cu „Livrare"');
}

console.log('\n=== Ruta Sofer.dc.html — meniul de zile numără de la server ===');
{
  const { logica } = parti('Ruta Sofer.dc.html');

  const fnCount = /\n  dayCount\(day\) \{([\s\S]*?)\n  \}/.exec(logica);
  const exprMeniu = /menuDays: (DAYS\.map\(d => \{[\s\S]*?\n {6}\}\)),\n/.exec(logica);
  check('Ruta Sofer.dc.html:406', 'numărătoarea zilei și meniul se pot citi din sursă',
    !!(fnCount && exprMeniu));

  if (fnCount && exprMeniu) {
    const ISOOF = { '29': '2026-08-29', '30': '2026-08-30', '02': '2026-09-02' };
    const DAYS = [{ v: '29', l: 'Sâm 29' }, { v: '30', l: 'Dum 30' }, { v: '02', l: 'Mie 2' }];
    const DLONG = {
      '29': 'Sâmbătă 29 august', '30': 'Duminică 30 august', '02': 'Miercuri 2 septembrie',
    };

    // Exact situația din bază: pagina a încărcat ziua 29, care n-are livrări,
    // iar pe 2 septembrie sunt 4 opriri — pe care pagina nu le are în mână.
    const S = { day: '29', orders: [], counts: { '2026-08-29': 0, '2026-09-02': 4 } };
    const pagina = {
      state: S,
      goDay() {},
      dayCount: new Function('ISOOF', 'return function (day) {' + fnCount[1] + '\n};')(ISOOF),
    };

    const meniu = new Function('DAYS', 'DLONG', 'S',
      'return function () { return ' + exprMeniu[1] + '; };')(DAYS, DLONG, S).call(pagina);

    const doi = meniu.find((m) => m.label === DLONG['02']);
    check('Ruta Sofer.dc.html:406', 'o zi neîncărcată își arată opririle reale',
      doi && doi.count === '4 opriri', doi ? doi.count : 'zi lipsă din meniu');

    const azi = meniu.find((m) => m.label === DLONG['29']);
    check('Ruta Sofer.dc.html:406', 'ziua fără livrări rămâne pe zero',
      azi && azi.count === '0 opriri', azi ? azi.count : 'zi lipsă din meniu');

    check('Ruta Sofer.dc.html:406', 'o zi despre care serverul n-a răspuns încă nu inventează opriri',
      pagina.dayCount('30') === 0, String(pagina.dayCount('30')));
  }

  check('Ruta Sofer.dc.html:406', 'pagina cere numărul opririlor pentru toată fereastra de zile',
    /gp\.get\('\/routes\/counts\?from='/.test(logica));
}

console.log('\n=== Ruta Sofer.dc.html — nota despre ordinea opririlor ===');
{
  const { logica } = parti('Ruta Sofer.dc.html');

  const expr = /orderNote: ([\s\S]*?),\n {6}onReset:/.exec(logica);
  check('Ruta Sofer.dc.html:428', 'nota din subsol se poate citi din sursă', !!expr);

  if (expr) {
    const nota = new Function('list', 'stops', 'return ' + expr[1] + ';');

    // Obiectele din `stops` sunt cele pregătite pentru afișare: nu au câmpul
    // routeSeq. Comenzile brute din `list` îl au, cu null cât timp nimeni
    // n-a reordonat ruta din administrare.
    const stops = [{ num: '1', cust: 'Hotel Belvedere' }, { num: '2', cust: 'Studio Arta' }];

    const fara = nota([{ routeSeq: null }, { routeSeq: null }], stops);
    check('Ruta Sofer.dc.html:428', 'fără ordine stabilită, nota trimite la intervalele promise',
      fara === 'Opriri în ordinea intervalelor promise.', fara);

    const cu = nota([{ routeSeq: 0 }, { routeSeq: 1 }], stops);
    check('Ruta Sofer.dc.html:428', 'cu ordine stabilită, nota trimite la administrare',
      cu === 'Ordinea opririlor e cea stabilită în administrare.', cu);

    const goala = nota([], []);
    check('Ruta Sofer.dc.html:428', 'ziua fără opriri nu pretinde o ordine stabilită',
      goala === 'Opriri în ordinea intervalelor promise.', goala);
  }
}

/* ---------------------------------------------------------------- final -- */

console.log(`\n${'='.repeat(52)}`);
console.log(`${pass} trecute · ${fail} picate`);
process.exit(fail ? 1 : 0);
