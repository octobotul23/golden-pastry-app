/**
 * Golden Pastry — client de API pentru paginile din browser.
 *
 * Înlocuiește apelurile `localStorage` din prototip. Diferența esențială:
 * datele stau pe server, deci o comandă plasată de client pe telefonul lui
 * ajunge în panoul laboratorului, pe alt dispozitiv — ceea ce înainte era
 * imposibil.
 *
 * Sesiunea circulă printr-un cookie HttpOnly pus pe `.goldenpastry.ro`, deci
 * funcționează pe toate subdomeniile. JavaScript-ul nu poate citi cookie-ul;
 * de aceea autentificarea nu se poate falsifica din consolă, cum se putea în
 * varianta cu localStorage.
 *
 * Utilizare:
 *     const { orders } = await gp.get('/orders?day=2026-08-28');
 *     await gp.patch('/orders/12', { status: 'gata' });
 */
(function (global) {
  'use strict';

  /** Adresa API-ului, dedusă din domeniul curent. */
  function detectBase() {
    if (global.GP_API_BASE) return String(global.GP_API_BASE).replace(/\/$/, '');

    // Numai `hostname`, `protocol` și `port` — niciodată `host` sau `origin`.
    // Probele care rulează logica paginilor își fac o `location` de mucava cu
    // exact aceste trei câmpuri (.claude/meniu-real.mjs, .claude/check-panouri.mjs);
    // o a patra citire ar arunca acolo, la încărcare, în toate suitele deodată.
    const { hostname, protocol, port } = global.location;

    // Chiar serverul care a trimis pagina, cu portul lui cu tot.
    const aici = `${protocol}//${hostname}${port ? ':' + port : ''}/api`;

    // Dezvoltare locală pe Laragon: domeniile `*.test` au API-ul lor.
    if (hostname.endsWith('.test')) {
      return `${protocol}//api.gp.test`;
    }

    // Gazde din care NU se poate deduce un subdomeniu `api.`:
    //   - nume fără punct — `localhost`, dar și numele calculatorului în rețea;
    //   - adresă numerică — `127.0.0.1`, dar și `192.168.1.13` de pe telefon;
    //   - IPv6, pe care `location.hostname` îl dă în paranteze drepte.
    //
    // Fără ramura asta, `192.168.1.13` intra pe drumul de producție de mai jos:
    // `parts.slice(-2)` scotea „1.13”, ieșea `http://api.1.13`, iar portul se
    // pierdea cu totul — adică aplicația deschisă de pe telefon, în rețeaua
    // casei, nu găsea niciun server. Aceeași pază ca la `staffHref` din
    // Client.dc.html (numele câmpului, nu numărul rândului: rândurile paginii
    // se mută la fiecare lucrare) și ca la `referralCode()`, mai jos.
    if (!hostname.includes('.') || /^[0-9.]+$/.test(hostname) || hostname.charAt(0) === '[') {
      return aici;
    }

    // Producție: api.goldenpastry.ro, indiferent de subdomeniul apelant.
    const parts = hostname.split('.');
    const root = parts.slice(-2).join('.');
    return `${protocol}//api.${root}`;
  }

  const BASE = detectBase();
  const CSRF_KEY = 'gp-csrf';

  /* ------------------------------------------------------------- CSRF -- */

  // Tokenul CSRF nu este un secret de autentificare (cookie-ul HttpOnly este),
  // ci dovada că cererea vine din pagina noastră. sessionStorage e potrivit:
  // dispare la închiderea filei.
  function getCsrf() {
    try {
      return global.sessionStorage.getItem(CSRF_KEY) || '';
    } catch (_) {
      return csrfFallback;
    }
  }

  let csrfFallback = '';
  // Câte zile ține sesiunea. Vine de la server la fiecare /auth/me, ca pagina
  // de intrare să poată explica de ce nu mai cere parola.
  let sessionDays = 0;
  function setCsrf(token) {
    csrfFallback = token || '';
    try {
      if (token) global.sessionStorage.setItem(CSRF_KEY, token);
      else global.sessionStorage.removeItem(CSRF_KEY);
    } catch (_) {
      /* mod privat sau stocare blocată — rămâne varianta din memorie */
    }
  }

  /* ------------------------------------------------------------ erori -- */

  class GpError extends Error {
    constructor(message, status, code, detalii) {
      super(message);
      this.name = 'GpError';
      this.status = status;
      this.code = code;
      // Obiectul `error` întreg, așa cum l-a trimis serverul. Unele refuzuri
      // spun mai mult decât un cod și un mesaj: 409 `status_changed` pune în
      // `current` etapa pe care o are ACUM comanda, ca ecranul rămas în urmă
      // să poată spune omului ce s-a întâmplat, fără încă o cerere. Niciodată
      // null, ca pagina să poată citi `e.detalii.current` fără pază.
      this.detalii = detalii || {};
    }
    /** Sesiunea a expirat sau nu există. */
    get isAuth() {
      return this.status === 401;
    }
    get isForbidden() {
      return this.status === 403;
    }
  }

  /* ----------------------------------------------------------- cereri -- */

  /**
   * Rutele care intră sau ies din sesiune. Pe ele serverul NU cere token
   * (csrf_exempt din api/lib/auth.php), deci n-are rost să-l căutăm întâi —
   * am face o cerere în plus înaintea fiecărei autentificări.
   */
  const FARA_CSRF = ['/auth/login', '/auth/logout', '/auth/panel', '/auth/register'];

  /**
   * Se asigură că avem un token CSRF înainte de o scriere.
   *
   * Tokenul stă în sessionStorage, care e AL FILEI: se golește la fiecare filă
   * nouă, în timp ce cookie-ul de sesiune ține zile întregi. O pagină deschisă
   * direct — un link trimis pe WhatsApp, o filă nouă, un semn de carte — pornea
   * deci cu sesiune validă și fără token. Citirile mergeau (GET nu cere token),
   * dar prima scriere se lovea de „Token CSRF lipsă sau invalid.".
   *
   * Așa s-a manifestat pe „Ruta Șofer": ruta se vedea, dar „Marchează livrat"
   * refuza, iar de pe altă adresă — adică dintr-o filă care trecuse deja printr-o
   * pagină care ceruse sesiunea — mergea. Aceeași lipsă o avea și „Panou
   * Laborator", unde ar fi oprit avansarea etapelor pe tabletele din laborator.
   *
   * Reparat aici, în clientul comun, nu în fiecare pagină: e singurul loc prin
   * care trec toate cererile, deci acoperă și paginile care se vor adăuga.
   * `gp.me()` pune tokenul prin setCsrf(), iar cererile pornite în același timp
   * așteaptă aceeași promisiune — nu plecăm cu zece „/auth/me" deodată.
   */
  let cerereMe = null;
  async function asiguraCsrf() {
    if (getCsrf()) return;
    if (!cerereMe) {
      cerereMe = request('GET', '/auth/me')
        .then((out) => {
          if (out && out.csrf) setCsrf(out.csrf);
          if (out && out.sessionDays) sessionDays = out.sessionDays;
        })
        // Fără sesiune nu există token, iar cererea va lua oricum 401 de la
        // server, cu mesajul potrivit. Nu stricăm aici cu altă eroare.
        .catch(() => {})
        .finally(() => { cerereMe = null; });
    }
    await cerereMe;
  }

  async function request(method, path, payload, reincercat) {
    const scrie = method !== 'GET' && method !== 'HEAD';
    if (scrie && !FARA_CSRF.includes(path.split('?')[0])) {
      await asiguraCsrf();
    }
    const headers = {};
    const init = {
      method,
      // Fără asta cookie-ul de sesiune nu pleacă spre alt subdomeniu.
      credentials: 'include',
      headers,
    };

    // Un FormData (fișier) pleacă așa cum e: browserul pune singur
    // Content-Type-ul multipart, cu tot cu boundary. JSON-ul se serializează.
    if (payload !== undefined) {
      if (typeof FormData !== 'undefined' && payload instanceof FormData) {
        init.body = payload;
      } else {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(payload);
      }
    }
    if (method !== 'GET' && method !== 'HEAD') {
      const token = getCsrf();
      if (token) headers['X-CSRF-Token'] = token;
    }

    let res;
    try {
      res = await fetch(BASE + path, init);
    } catch (e) {
      throw new GpError(
        'Nu am putut contacta serverul. Verifică conexiunea la internet.',
        0,
        'network'
      );
    }

    if (res.status === 204) return null;

    let data = null;
    const type = res.headers.get('content-type') || '';
    if (type.includes('application/json')) {
      try {
        data = await res.json();
      } catch (_) {
        data = null;
      }
    }

    if (!res.ok) {
      const err = (data && data.error) || {};
      // O sesiune expirată invalidează și tokenul CSRF păstrat local.
      if (res.status === 401) setCsrf('');

      // Token vechi: se întâmplă când sesiunea a fost refăcută în altă filă
      // (cineva s-a autentificat acolo), iar fila asta a rămas cu tokenul
      // dinainte. Îl aruncăm, cerem unul nou și încercăm O SINGURĂ dată —
      // altfel un refuz adevărat s-ar învârti la nesfârșit. Omul n-are ce
      // învăța dintr-un „Token CSRF invalid": e treaba noastră, nu a lui.
      if (res.status === 403 && err.code === 'bad_csrf' && !reincercat) {
        setCsrf('');
        await asiguraCsrf();
        return request(method, path, payload, true);
      }

      // Un 404 fără corp JSON înseamnă, aproape întotdeauna, că pagina e
      // deschisă de pe un server care nu are API-ul — nu că ruta nu există.
      //
      // S-a întâmplat: pagina servită de pe portul serverului static din etapa
      // de import, unde /api nu răspunde. Autentificarea eșua cu „Eroare 404",
      // adică un chenar roșu fără nicio explicație, iar utilizatorul,
      // cu parola corectă în față, nu avea de unde ști ce se întâmplă.
      if (res.status === 404 && !data) {
        throw new GpError(
          'Serverul aplicației nu răspunde la ' + BASE + '. ' +
          'Deschide pagina de pe adresa pe care rulează PHP — ' +
          'implicit http://localhost:8100 — nu de pe un server care servește doar fișiere.',
          res.status,
          'no_api'
        );
      }

      throw new GpError(
        err.message || `Eroare ${res.status}.`,
        res.status,
        err.code || String(res.status),
        // Tot ce a mai pus serverul în `error` merge mai departe, neatins:
        // pagina alege ce citește din el. Vezi `detalii` la clasă.
        err
      );
    }

    return data;
  }

  /* ------------------------------------------------------------ roluri -- */

  /**
   * Ce panouri are fiecare rol.
   *
   * Lista asta NU e o pază. Paza stă pe server, în `require_staff()` și
   * `require_admin()` din api/lib/auth.php, și rămâne acolo. Lista de aici e
   * OGLINDA ei: un buton care nu apare e un buton care ar fi dus la un refuz.
   *
   * Până acum meniul n-avea nicio noțiune de rol — le arăta pe toate zece
   * oricui ajungea la el, iar jumătate răspundeau „nu ai voie". Managerul și
   * agentul de vânzări nici măcar nu ajungeau: intrarea trimitea tot ce nu e
   * client la Administrare Comenzi, care îi refuza. Două roluri care existau
   * în baza de date și nu duceau nicăieri.
   *
   * Regula când se schimbă ceva: aici se scrie DUPĂ ce s-a deschis poarta de
   * pe server, niciodată înainte. Serverul mai strâmt decât meniul e doar un
   * buton care dă un refuz; meniul mai larg decât serverul e chiar defectul
   * de dinainte.
   */
  const PANOURI_TOT = ['Comenzi', 'Laborator', 'Rută', 'Depozit', 'Pontaj', 'Catalog',
    'Conturi', 'Recomandări', 'Jurnal', 'Setări', 'Parola mea'];

  const PANOURI_ROL = {
    admin: PANOURI_TOT,
    // Lista administratorului PLUS una, nu o copie a ei cu un rând în plus:
    // fondatorul e administrator plus ceva (GP_ADMIN_ROLES pe server), iar
    // două liste care trebuie să fie egale se despart întotdeauna, mai
    // devreme sau mai târziu.
    //
    // „Ceva"-ul acela are acum două fețe. Una veche: dreptul asupra
    // administratorilor și a celorlalți fondatori. Una nouă: Statistici —
    // singurul panou din casă pe care administratorul NU-l are. Oglinda de pe
    // server e `GP_STATS_ROLES` (api/lib/auth.php), singura listă de roluri
    // mai strâmtă decât `GP_ADMIN_ROLES`; motivul e scris acolo.
    fondator: [...PANOURI_TOT, 'Statistici'],
    manager: ['Comenzi', 'Laborator', 'Rută', 'Depozit', 'Pontaj', 'Parola mea'],
    agent_vanzari: ['Comenzi', 'Recomandări', 'Parola mea'],
    angajat: ['Laborator', 'Rută', 'Depozit', 'Pontaj', 'Parola mea'],
    // Omul de laborator: EXACT o ușă, cea pentru care i s-a făcut contul.
    //
    // Rolul ăsta a stat în baza de date din migrarea 009 fără să ducă nicăieri
    // — n-avea nicio intrare aici, deci cine intra cu el capăta un sertar gol
    // și era trimis la „Parola mea" de scurtătura de la sfârșitul lui
    // `primaPagina()`. Pontajul e rostul lui; oglinda de pe server e
    // `GP_CLOCK_ROLES` (api/lib/auth.php), care îi deschide tableta și atât —
    // nici depozitul, nici laboratorul, nici foaia de drum.
    //
    // În fața tabletei din laborator nu are nevoie de rândul ăsta: acolo nu
    // intră el cu parolă, ci apasă pe numele lui pe ecranul deschis de tură.
    // Rândul e pentru ziua în care își deschide singur pagina, de pe telefon.
    utilizator_lab: ['Pontaj', 'Parola mea'],
    client: ['Comanda mea', 'Recomandări', 'Parola mea'],
  };

  /**
   * Unde cade fiecare rol după ce a scris parola.
   *
   * Nu „prima intrare din meniu": aia e o întâmplare a ordinii din MENIU.
   * Agentul de vânzări are și Comenzi, dar ziua lui începe la Recomandări.
   * Scris pe față, ca să se poată citi dintr-o privire — iar proba din
   * .claude/test-roluri.mjs verifică că fiecare destinație de aici chiar e în
   * meniul rolului ei, ca lista să nu se depărteze pe tăcute.
   */
  const ACASA_ROL = {
    admin: 'Administrare%20Comenzi.dc.html',
    fondator: 'Administrare%20Comenzi.dc.html',
    manager: 'Administrare%20Comenzi.dc.html',
    agent_vanzari: 'Recomandari.dc.html',
    angajat: 'Panou%20Laborator.dc.html',
    // Singura lui pagină e și acasa lui.
    utilizator_lab: 'Pontaj.dc.html',
    // Clientul intră în „Comanda mea": acolo e comanda lui de mâine, ziua
    // livrării și, în ziua aceea, câte opriri mai sunt până la el. Magazinul
    // rămâne la un clic, în sertar și în butonul „Intră în magazin".
    //
    // Aici a scris o vreme magazinul, cu motivul „clientul vine să comande, nu
    // să-și citească istoricul". Motivul era al patiseriei, nu al omului: cine
    // își scrie parola vrea să vadă CE A COMANDAT, altfel n-avea nevoie de
    // parolă — magazinul se deschide și fără cont. Schimbat la cererea
    // patronului, 13 septembrie 2026.
    client: 'Client.dc.html',
  };

  /* --------------------------------------------------------- versiunea -- */

  /**
   * Numărul rundei, scris de mână, într-un singur loc din tot proiectul.
   *
   * DE CE CHIAR AICI. Fișierul ăsta e singurul pe care îl încarcă toate cele
   * 15 pagini publicate, deci e singurul loc din care numărul ajunge peste tot
   * fără nicio listă scrisă de mână. Dar mai e o pricină, și e cea grea:
   * amprenta `?v=` pe care `build-deploy` o lipește pe fiecare trimitere către
   * un `.js` e `sha256(shared/gp-api.js)` ȘI NIMIC ALTCEVA. Până acum, o rundă
   * care nu atingea fișierul ăsta pleca pe server cu toate trei amprentele
   * neclintite, iar martorul din `CITESTE-MA.md` nu putea deosebi pachetul nou
   * de cel vechi — pățit la 2.2.4, scris chiar acolo. Versiunea stând CHIAR
   * aici, orice rundă mișcă amprentele de la sine, fără să ne mai gândim.
   */
  const VERSIUNE = '2.3.1';

  /**
   * Ștampila pachetului servit. Pe serverul de lucru rămâne `local`: acolo
   * paginile se servesc crude, nu s-a construit niciun pachet, deci n-are ce
   * ștampilă să poarte — iar „local" spune asta pe față, în loc să mintă o oră.
   *
   * La construire, `build-deploy.mjs` o înlocuiește în COPIA fiecărui
   * subdomeniu, niciodată în fișierul ăsta.
   *
   * ȘI N-O IA DIN CEAS, CI DIN CONȚINUTUL SURSELOR. Dacă ar veni din ceas,
   * fiecare reconstruire ar schimba fișierul, deci toate amprentele, deci
   * toate paginile — iar `test-plasa-rsync.mjs:1151` compară pachetul din
   * proiect cu cel din plasture octet cu octet, cu un singur fișier scutit
   * (`PACHET.txt`). S-ar fi înroșit la fiecare suită, iar o regulă care se
   * înroșește mereu se învață să fie sărită. Așa, același cod construit de
   * două ori iese cu aceeași ștampilă, iar ea se mută numai când se mută codul.
   */
  const STAMPILA = 'local';

  /* --------------------------------------------------------------- API -- */

  const gp = {
    base: BASE,
    GpError,

    VERSIUNE,
    STAMPILA,
    /** `ver. 2.3.0 · 19 sep 16:48` — ce scrie în colțul fiecărei pagini. */
    versiune: () => 'ver. ' + VERSIUNE + ' · ' + STAMPILA,

    get: (path) => request('GET', path),
    post: (path, body) => request('POST', path, body === undefined ? {} : body),
    patch: (path, body) => request('PATCH', path, body === undefined ? {} : body),
    // Un fișier, ca multipart, pe ACELAȘI drum ca orice scriere: tokenul CSRF
    // asigurat înainte (și din memorie, când sessionStorage e blocat),
    // reîncercarea la token vechi, erorile ca GpError. Pagina Catalog urca
    // poza cu un fetch scris de mână, care citea tokenul direct din
    // sessionStorage: în fereastră privată pleca gol — produsul se crea,
    // poza pica cu „Token CSRF lipsă sau invalid".
    upload: (path, formData) => request('POST', path, formData),
    put: (path, body) => request('PUT', path, body === undefined ? {} : body),
    del: (path) => request('DELETE', path),

    /* --- autentificare --- */

    async login(username, password) {
      const out = await request('POST', '/auth/login', { username, password });
      setCsrf(out.csrf);
      if (out && out.sessionDays) sessionDays = out.sessionDays;
      return out.user;
    },

    async logout() {
      try {
        await request('POST', '/auth/logout');
      } finally {
        setCsrf('');
      }
    },

    /**
     * Contul pe care și-l face singur vizitatorul magazinului.
     *
     * Serverul deschide sesiunea pe loc, ca la `login()`: cine tocmai și-a ales
     * o parolă n-are de ce s-o mai scrie o dată. De aceea tokenul CSRF se pune
     * și aici — fără el, prima cerere de comandă a omului ar pica cu 403.
     *
     * Codul de recomandare se trimite ca `ref`. Unul greșit nu oprește nimic:
     * serverul creează contul oricum, doar fără recomandant.
     *
     * Adresa și zona sunt neobligatorii, dar odată scrise nu se mai cer la
     * fiecare comandă: formularul de pe site le completează de pe cont. Zona se
     * verifică pe server față de lista din Setări — una inventată e refuzată cu
     * 422, nu înghițită tăcut.
     *
     * @param {{username:string,name:string,password:string,email?:string,
     *          phone?:string,address?:string,zone?:string,
     *          customerName?:string,ref?:string}} date
     * @returns {Promise<{user:object,referral:?object,referredBy:?string}>}
     */
    async register(date) {
      const out = await request('POST', '/auth/register', date || {});
      setCsrf(out.csrf);
      if (out && out.sessionDays) sessionDays = out.sessionDays;
      return out;
    },

    /**
     * Codul de recomandare venit prin „?ref=" în adresă.
     *
     * Se citește o singură dată, la prima încărcare, și se ține în
     * sessionStorage: între clicul pe link și formularul de cont omul umblă prin
     * catalog, iar o variabilă din pagină s-ar pierde la prima navigare.
     *
     * URL-ul se curăță pe loc cu history.replaceState, fără reîncărcare — ca
     * linkul să nu rămână în istoricul browserului și să nu plece mai departe ca
     * „referrer" către alte site-uri. Exact tratamentul pe care îl primește deja
     * „?parola=" în Intrare.dc.html, din același motiv.
     *
     * Întoarce '' când nu există niciun cod. Nu aruncă niciodată: pe un browser
     * cu stocarea blocată recomandarea se pierde, dar înscrierea merge.
     */
    referralCode() {
      const KEY = 'gp-ref';
      // Codul trebuie să treacă de pe site (goldenpastry.ro) pe pagina de
      // înscriere (clienti.goldenpastry.ro). sessionStorage e legat de
      // origine, deci acolo codul rămânea pe site și înscrierea pleca fără
      // el — pe localhost, unde totul e aceeași origine, nu se vedea. Un
      // cookie pe domeniul-rădăcină (.goldenpastry.ro) îl citesc toate
      // subdomeniile; local, fără domeniu, e tot un cookie al paginii.
      const domeniu = (() => {
        const h = String(global.location.hostname || '');
        if (!h || h === 'localhost' || /^[0-9.]+$/.test(h) || h.endsWith('.test')) return '';
        const p = h.split('.');
        return p.length >= 2 ? '.' + p.slice(-2).join('.') : '';
      })();
      const cookie = {
        get() {
          try {
            const m = String(global.document.cookie || '').match(/(?:^|;\s*)gp-ref=([^;]*)/);
            return m ? decodeURIComponent(m[1]) : '';
          } catch (e) { return ''; }
        },
        set(v) {
          try {
            global.document.cookie = 'gp-ref=' + encodeURIComponent(v) + '; path=/; max-age=' + (30 * 86400)
              + '; samesite=lax' + (domeniu ? '; domain=' + domeniu : '')
              + (global.location.protocol === 'https:' ? '; secure' : '');
          } catch (e) { /* cookie-uri blocate */ }
        },
        del() {
          try {
            global.document.cookie = 'gp-ref=; path=/; max-age=0' + (domeniu ? '; domain=' + domeniu : '');
            global.document.cookie = 'gp-ref=; path=/; max-age=0';
          } catch (e) { /* noop */ }
        },
      };
      gp.__refCookie = cookie;
      let dinUrl = '';
      try {
        const q = new global.URLSearchParams(global.location.search || '');
        dinUrl = String(q.get('ref') || '').trim().slice(0, 64);
        if (dinUrl) {
          q.delete('ref');
          const coada = q.toString();
          global.history.replaceState(
            null,
            '',
            global.location.pathname + (coada ? '?' + coada : '') + (global.location.hash || '')
          );
        }
      } catch (e) { /* fără URLSearchParams sau fără history: mergem mai departe */ }

      if (dinUrl) cookie.set(dinUrl);
      try {
        if (dinUrl) {
          global.sessionStorage.setItem(KEY, dinUrl);
          return dinUrl;
        }
        return String(global.sessionStorage.getItem(KEY) || '') || cookie.get();
      } catch (e) {
        // Stocare blocată (fereastră privată, setări stricte). Codul rămâne
        // valabil doar pentru pagina curentă — tot mai bine decât deloc.
        return dinUrl || cookie.get();
      }
    },

    /** Uită codul de recomandare. Se cheamă după ce contul a fost creat. */
    forgetReferral() {
      try { global.sessionStorage.removeItem('gp-ref'); } catch (e) { /* noop */ }
      try { if (gp.__refCookie) gp.__refCookie.del(); } catch (e) { /* noop */ }
    },

    /**
     * „Comandă din nou”: o comandă veche, strânsă într-un cod scurt de adresă.
     *
     * DE CE PRIN ADRESĂ, ȘI NU PRIN COȘUL DIN BROWSER. Pagina clientului stă pe
     * clienti.goldenpastry.ro, magazinul pe goldenpastry.ro — două origini, deci
     * două localStorage-uri care nu se văd unul pe altul. Coșul scris de aici
     * în `gp-cart-v1` ar fi mers perfect pe serverul de probă, unde totul e
     * aceeași origine, și n-ar fi ajuns NICIODATĂ în magazinul din producție.
     * Aceeași pățanie ca a codului de recomandare (`referralCode()`, mai sus).
     *
     * Codul: bucăți `f<id>x<cant>` (congelat) și `b<id>x<cant>` (copt),
     * legate cu punct — `f12x10.b9x2`. Numai litere, cifre și punct, deci nu
     * cere codare în adresă și nu se strică trecând prin build-deploy.
     *
     * Întoarce și ce a rămas pe dinafară, din aceeași trecere, ca nota de sub
     * buton să nu poată spune altceva decât ce a intrat în cod:
     *   `scoase`   — pozițiile fără produs din catalog (scrise de mână la
     *                telefon, sau produs șters): coșul are cheie pe produs, deci
     *                n-au unde intra;
     *   `rotunjit` — o sumă cu zecimale (0,5 tăvi din panou) a intrat rotunjită
     *                în SUS: coșul lucrează numai cu bucăți întregi, iar în jos
     *                0,4 ar fi dispărut cu totul;
     *   `taiat`    — o sumă peste 9999, cât primește căsuța de cantitate.
     *
     * Același produs pe două rânduri (bucățile la prețul promis și cele puse
     * azi, api/lib/orders.php) se ADUNĂ: scrise rând cu rând în aceeași cheie,
     * al doilea l-ar fi călcat pe primul și s-ar fi pierdut marfă.
     */
    reia(items) {
      const sume = {};
      const ordine = [];
      const scoase = [];
      (items || []).forEach((i) => {
        if (!i) return;
        const q = gp.qty(i.q != null ? i.q : i.qty);
        if (!(q > 0)) return;
        const id = Number(i.productId);
        const v = i.variant === 'congelat' ? 'f' : i.variant === 'copt' ? 'b' : '';
        if (!(Number.isInteger(id) && id > 0) || !v) {
          const n = String(i.n != null ? i.n : (i.name || '')).trim();
          if (n && scoase.indexOf(n) < 0) scoase.push(n);
          return;
        }
        const k = v + id;
        if (!(k in sume)) { sume[k] = 0; ordine.push(k); }
        sume[k] += q;
      });
      let rotunjit = false;
      let taiat = false;
      const bucati = ordine.map((k) => {
        // Rotunjirea la sutimi înainte de `ceil`: 2,7 + 0,1 + 0,2 adunate în
        // virgulă mobilă dau 3,0000000000000004, iar `ceil` ar fi urcat un 3 la 4.
        const exact = Math.round(sume[k] * 100) / 100;
        if (exact % 1 !== 0) rotunjit = true;
        const n = Math.ceil(exact);
        if (n > 9999) taiat = true;
        return k + 'x' + Math.min(9999, n);
      });
      return { cod: bucati.join('.'), scoase, rotunjit, taiat };
    },

    /**
     * Codul lui `reia()`, citit înapoi în cheile coșului din magazin
     * (`frozen:12`, `baked:9`). Strict: o bucată care nu arată exact ca una
     * scrisă de `reia()` se sare, nu se ghicește — adresa poate fi tăiată de un
     * program de mesagerie sau scrisă de mână. Cel mult 200 de bucăți și 9999
     * dintr-un produs, cât primește căsuța de cantitate.
     *
     * Întoarce un obiect gol pentru un cod de neînțeles: magazinul spune atunci
     * că legătura nu s-a putut citi și lasă coșul cum era.
     */
    reiaCitire(s) {
      const qty = {};
      String(s == null ? '' : s).split('.').slice(0, 200).forEach((b) => {
        const m = /^([fb])([1-9][0-9]{0,8})x([1-9][0-9]{0,3})$/.exec(b);
        if (!m) return;
        const k = (m[1] === 'f' ? 'frozen:' : 'baked:') + m[2];
        qty[k] = Math.min(9999, (qty[k] || 0) + parseInt(m[3], 10));
      });
      return qty;
    },

    /**
     * `?reia=` din adresa magazinului: îl citește O SINGURĂ DATĂ și îl scoate.
     *
     * Lăsat în adresă, parametrul s-ar fi aplicat din nou la fiecare
     * reîncărcare, iar `duLa()` l-ar fi purtat pe toate paginile magazinului
     * (ea duce `location.search` mai departe, pentru `?utm_source=`). Restul
     * adresei rămâne neatins — alți parametri, `#`, starea din istoric —,
     * exact ca la `?ref=`.
     *
     * `null` când parametrul lipsește. Nu aruncă niciodată: un browser fără
     * istoric scriibil lasă parametrul pe loc, dar reluarea tot se citește.
     */
    reiaDinAdresa() {
      try {
        const loc = global.location;
        const q = new global.URLSearchParams(loc.search || '');
        const v = q.get('reia');
        if (v === null) return null;
        q.delete('reia');
        const coada = q.toString();
        try {
          global.history.replaceState(
            global.history.state,
            '',
            loc.pathname + (coada ? '?' + coada : '') + (loc.hash || '')
          );
        } catch (e) { /* istoric nescriibil: adresa rămâne, coșul se umple oricum */ }
        return gp.reiaCitire(v);
      } catch (e) {
        return null;
      }
    },

    /**
     * Deschide panoul cu codul comun al tabletelor din laborator.
     *
     * Codul se verifică pe server. Sesiunea rezultată vede și avansează
     * comenzi, dar nu ajunge la conturi, setări sau jurnal.
     */
    async panelUnlock(code) {
      const out = await request('POST', '/auth/panel', { code });
      setCsrf(out.csrf);
      return out.user;
    },

    /** Utilizatorul curent, sau null. Nu aruncă eroare dacă nu e autentificat. */
    async me() {
      const out = await request('GET', '/auth/me');
      if (out && out.csrf) setCsrf(out.csrf);
      if (out && out.sessionDays) sessionDays = out.sessionDays;
      const user = (out && out.user) || null;
      // Codul de recomandare al contului se lipește de utilizator, ca paginile
      // să nu fie nevoite să cheme separat serverul pentru el. Lipsește — deci
      // câmpul rămâne nedefinit — pe o instalare pe care migrarea 007 n-a fost
      // rulată, iar paginile verifică asta înainte să deseneze ceva.
      if (user && out && out.referral) user.referral = out.referral;
      // Și codul celui care l-a ADUS. Nu e același lucru cu cel de deasupra:
      // acela e codul LUI, ăsta e al celuilalt. Magazinul public umple din el
      // caseta „Cod de recomandare" a clientului cu cont, ca ea să fie o
      // oglindă a contului — altfel omul vede o casetă goală, tastează un cod
      // din memorie, și naște un conflict de descâlcit cu mâna.
      //
      // Lipsa lui NU se preface în `false`, ca la `founderReady`: acolo
      // răspunsul e un da/nu despre instalare, aici lipsa înseamnă „serverul
      // ăsta nu știe încă să răspundă", care nu e totuna cu „nu l-a adus
      // nimeni". Rămâne nedefinit, iar pagina desenează caseta goală în
      // amândouă cazurile — dar fără să spună ceva ce nu știe.
      if (user && out && out.referredByCode) user.referredByCode = out.referredByCode;
      // Dacă instalarea are contul de fondator (migrarea 009). Steag despre
      // BAZĂ, nu despre om — de aceea e `=== true`, nu `||`: pe un server
      // vechi, care nu trimite câmpul, trebuie să iasă `false`, nu
      // `undefined`, altfel pagina desenează un buton care duce în 503.
      if (user) user.founderReady = out && out.founder === true;
      // De unde vine contul, și dacă a garantat casa pentru el (migrarea 010).
      //
      // Aici NU merge tiparul lui `founderReady`, cu `=== true`: acolo
      // răspunsul e un da/nu despre instalare, pe când aici lipsa câmpului
      // înseamnă „serverul ăsta nu știe încă să răspundă", care nu e totuna cu
      // „nu e cont făcut pe site". Rămâne nedefinit, iar pagina „Comanda mea"
      // verifică asta înainte să scrie un mesaj despre o regulă care pe
      // instalarea aceea nici nu se aplică.
      if (user && out && out.origine) user.origine = out.origine;
      // Semnul „poate confirma livrarea" (migrarea 023), mutat de la rădăcină
      // pe utilizator. Serverul îl pune deoparte din aceeași pricină ca
      // `referral`: rândul vine din `current_user()`, o interogare cu listă
      // fixă de coloane, iar dreptul nici nu se citește numai din tabelă —
      // `poate_confirma_livrarea()` îl dă din ROL administratorului, cu
      // coloana goală. Deci se socotește în răspuns, nu pe rând.
      //
      // NICIUNUL din cele două tipare de mai sus nu merge aici:
      //
      //   · paza pe adevărat (`if (out.x) user.x = out.x`), ca la `origine`,
      //     ar înghiți tocmai `false` — adică EXACT contul fără semn, singurul
      //     despre care e vorba. Semnul ar fi ajuns nedefinit, iar `gp.auth()`
      //     l-ar fi citit „poate";
      //   · `=== true` necondiționat, ca la `founderReady`, ar face un server
      //     de dinaintea rundei — care nu trimite câmpul — să spună „nu poate"
      //     pentru TOȚI: butonul șoferului s-ar fi stins pe toate telefoanele
      //     în fereastra de urcare, adică chiar paguba de care fuge lucrarea.
      //
      // Deci se copiază NUMAI dacă serverul a trimis câmpul; lipsa lui rămâne
      // nedefinită, iar `gp.auth()` o citește cu `!== false`, adică „poate".
      //
      // PĂȚANIA, ca să nu se repete a treia oară: lipirea asta a lipsit la
      // prima scriere a rundei 2.3.0. Serverul refuza etapa finală corect
      // (403), pagina avea butonul stins scris cum trebuie — dar semnul nu
      // ajungea la ea, deci butonul rămânea aprins pe orice cont, iar omul
      // afla abia din refuz. A doua oară pe ACEEAȘI țeavă: întâia dată s-a
      // pierdut `referralCode` (v. `.claude/test-roluri.mjs`, grupul B2).
      if (user && out && 'poateConfirmaLivrarea' in out) {
        user.poateConfirmaLivrarea = out.poateConfirmaLivrarea === true;
      }
      return user;
    },

    /** Câte zile ține sesiunea, așa cum spune serverul. 0 până la primul me(). */
    sessionDays: () => sessionDays,

    changePassword: (currentPassword, newPassword) =>
      request('POST', '/auth/password', { currentPassword, newPassword }),

    /**
     * „Păstrez parola actuală": stinge steagul de la prima intrare, atât.
     *
     * Nu ia niciun argument, dinadins — nici contul. Cine apasă se știe din
     * sesiune, pe server. Un identificator aici ar fi însemnat că se poate
     * cere stingerea steagului altcuiva.
     *
     * Ruta e nouă în runda 2.2.1. Pe un server cu fișierele vechi iese 404, iar
     * pagina cade atunci înapoi pe schimbarea obligatorie de până acum — vezi
     * `onPastrez` din Parola.dc.html. Ajutorul nu înghite nimic: 404-ul trebuie
     * să ajungă la pagină ca s-o poată face.
     */
    keepPassword: () => request('POST', '/auth/password/keep', {}),

    /* --------------------------------------- recomandări cu comisioane -- */

    /**
     * Câștigurile MELE: codul, linkul, cele trei plăci și istoricul.
     *
     * Nu primește niciun parametru, dinadins — nici măcar un identificator de
     * cont. Cine întreabă se știe din sesiune, pe server, iar răspunsul n-are
     * cum să fie lărgit dintr-o adresă scrisă de mână. Vezi
     * `referral_history()` din api/lib/referral.php.
     *
     * Pe o bază fără migrarea 008 întoarce `money: false` și liste goale, nu
     * eroare: pagina desenează atunci doar cardul cu codul.
     */
    referrals: () => request('GET', '/referrals'),

    /** „Fă-mi un cod" — derivat din numele contului, ca la înscriere. */
    referralMakeCode: () => request('POST', '/referrals/code'),

    /**
     * Toate comisioanele — numai pentru administrator.
     *
     * `luna` e „2026-09" sau '' pentru tot. Restul filtrării (căutare, sortare)
     * se face în pagină: lista unei patiserii are sute de rânduri, iar un drum
     * la server la fiecare literă tastată ar fi mai lent decât filtrarea locală.
     */
    referralsAll: (luna) =>
      request('GET', '/referrals/all' + (luna ? '?month=' + encodeURIComponent(luna) : '')),

    /** Bifa „i-am dat banii", pusă sau scoasă, pe unul sau pe mai multe rânduri. */
    referralsPay: (ids, paid) =>
      request('POST', '/referrals/pay', { ids: ids, paid: paid !== false }),

    /** Conflictul, hotărât: care dintre cele două coduri câștigă. */
    referralResolve: (id, code) =>
      request('POST', '/referrals/' + Number(id) + '/resolve', { code: code }),

    /**
     * Scoate rândul din socoteală — și adu-l înapoi.
     *
     * `DELETE` e ce cere omul care apasă. Dedesubt nu e o ștergere, și nici
     * n-are cum să fie: rândul e recalculat de server la fiecare atingere a
     * comenzii, deci șters de-a dreptul s-ar scrie la loc singur la prima
     * mutare de etapă de pe tableta din laborator. Se ține minte faptul că a
     * fost scos — de-aia există și perechea de mai jos.
     *
     * Răspunsul poate aduce `commission: null` la aducerea înapoi: recitirea
     * comenzii poate arăta că n-a mai rămas nimeni de premiat. Nu e o eroare;
     * reîncarcă lista.
     */
    referralDismiss: (id) => request('DELETE', '/referrals/' + Number(id)),

    /** Perechea: rândul scos, adus înapoi în socoteală. */
    referralRestore: (id) =>
      request('POST', '/referrals/' + Number(id) + '/restore', {}),

    /**
     * Codul și procentul unui cont, schimbate de administrator.
     *
     * `PATCH`, nu `POST`: celălalt doar DĂ un cod contului care n-are. Ăsta
     * suprascrie, iar codul vechi moare pe loc — linkurile deja trimise cu el
     * nu mai recomandă pe nimeni. Spune-i asta omului înainte să apese.
     *
     * `rate: null` înseamnă „ia-l pe cel global"; `rate: 0` înseamnă „nu câștigă
     * nimic". Nu sunt același lucru.
     */
    userReferralEdit: (id, date) =>
      request('PATCH', '/users/' + Number(id) + '/referral', date || {}),

    /* ------------------------------------------------ contul de fondator -- */

    /**
     * „Cere drept de fondator" — pentru MINE, niciodată pentru altcineva.
     *
     * Nu primește niciun parametru și nu poate primi: contul care cere se ia
     * din sesiune, pe server. Linkul de confirmare NU vine înapoi în răspuns
     * — pleacă pe adresa firmei din Setări. Dacă ar veni și încoace, poarta ar
     * fi tot cutia poștală doar cu numele, iar cine apasă butonul s-ar ridica
     * singur în rang.
     */
    founderRequest: () => request('POST', '/founder/request'),

    /**
     * Cine ar deveni fondator dacă se confirmă linkul ăsta.
     *
     * Publică: se cheamă din pagina de intrare, de obicei fără nicio sesiune —
     * cel care deschide cutia poștală a firmei nu are neapărat cont.
     */
    founderInvite: (token) =>
      request('GET', '/founder/invite?token=' + encodeURIComponent(String(token || ''))),

    /** Confirmarea. Tokenul e singura autoritate; sesiunea nu contează. */
    founderAccept: (token) =>
      request('POST', '/founder/accept', { token: String(token || '') }),

    /**
     * Ce a scris omul în comanda trimisă de pe site, ca să se completeze singur
     * formularul de cont nou.
     *
     * Publică, ca `founderInvite` și din același motiv: cine o cheamă nu are
     * încă niciun cont — tocmai de aceea o cheamă. Poarta e jetonul însuși,
     * dat o singură dată, în răspunsul la chiar trimiterea acelei cereri.
     *
     * Jetonul pleacă în CORP, nu în adresă, deși ruta e un POST oricum: un GET
     * cu el în coadă l-ar fi lăsat în jurnalele serverului și în istoricul
     * browserului, exact ce ocolește pagina când îl șterge din bara de adrese.
     *
     * Nu întoarce nici numărul cererii, nici pozițiile, nici totalul — numai
     * casetele formularului. Cu cât dă mai puțin, cu atât un jeton scăpat
     * costă mai puțin.
     */
    cerereJeton: (jeton) =>
      request('POST', '/requests/claim', { jeton: String(jeton || '') }),

    /**
     * Adresa paginii de autentificare, cu urma de unde a venit omul.
     *
     * Autentificarea stă într-un singur loc: `Intrare.dc.html`. Panourile
     * aveau fiecare formularul lui, copiat, iar în „Administrare Comenzi"
     * formularul se bătea cap în cap cu redirectul din `catch`: cererile din
     * fundal plecau înaintea lui `me()`, luau 401 și trimiteau omul înapoi
     * înainte să apuce să scrie ceva. Din afară arăta ca o pagină care te
     * refuză singură; formularul acela nu putea fi folosit niciodată.
     *
     * `next` spune paginii de intrare unde să te ducă înapoi. Se ia din
     * adresă, nu se scrie în fiecare panou: un fișier redenumit n-are cum să
     * trimită pe cineva în gol. Restul parametrilor se păstrează, fiindcă ei
     * circulă între pagini (vezi comportamentul de pe legături din panouri).
     */
    loginHref(pagina) {
      let aici = pagina || '';
      if (!aici) {
        try {
          aici = decodeURIComponent(String(global.location.pathname || '').split('/').pop() || '');
        } catch (e) { aici = ''; }
      }
      let q;
      try { q = new global.URLSearchParams(global.location.search || ''); }
      catch (e) { q = new global.URLSearchParams(''); }
      // Numai o bucată de cale merită ținută minte — un nume de pagină, nimic
      // altceva. Regula întreabă FORMA, nu extensia, fiindcă numele paginilor
      // se schimbă la construirea pachetului: în dezvoltare au extensia lungă
      // a fișierelor sursă, pe server n-au niciuna. Scrisă pe extensia din
      // dezvoltare, întoarcerea ar fi mers pe calculatorul programatorului și
      // ar fi tăcut pe serverul adevărat — adică exact acolo unde omul chiar
      // apasă pe „Administrare".
      //
      // Ce se refuză pleacă fără urmă: mai bine pagina implicită decât o
      // întoarcere pe care n-o putem garanta.
      if (aici && !/[\/\\:]/.test(aici) && aici.indexOf('..') < 0) q.set('next', aici);
      else q.delete('next');
      const coada = q.toString();
      return 'Intrare.dc.html' + (coada ? '?' + coada : '');
    },

    /** Duce la autentificare, spunându-i de unde ai venit. */
    toLogin() {
      global.location.href = gp.loginHref();
    },

    /**
     * Meniul panourilor — o singură listă, pentru toate paginile.
     *
     * Fiecare panou își ținea propria copie a listei, scrisă de mână în HTML,
     * și copiile s-au depărtat una de alta: „Catalog" era al treilea într-o
     * pagină și ultimul în alta, „Jurnal" lipsea din patru pagini din șase.
     * De-aia s-a scris `.claude/check-meniu.mjs` — el compară barele laterale
     * între ele și se supără când nu se mai potrivesc.
     *
     * Sertarul de pe telefon are nevoie de exact aceeași listă. Scris tot în
     * HTML, ar fi însemnat încă opt copii — de data asta pe un ecran la care
     * verificarea nu se uită, deci s-ar fi depărtat în tăcere, adică fix
     * defectul pe care fișierul acela a fost scris să-l prindă. Aici lista e
     * una singură: barele laterale rămân cum sunt (verificarea le păzește),
     * iar sertarele se desenează din rândurile de mai jos.
     *
     * Adresele se scriu codificat („%20"), ca în HTML: `build-deploy.mjs` le
     * rescrie pe amândouă formele, iar fișierul ăsta trece prin aceeași
     * rescriere ca paginile (vezi `rescrieFolder`). O pagină care nu e încă în
     * `PAGES` n-are ce căuta aici — rescrierea ar lăsa-o neatinsă și legătura
     * ar duce în gol tocmai pe serverul adevărat, nu pe calculatorul de acasă.
     */
    /**
     * Are contul ăsta drepturi depline? („admin" sau „fondator")
     *
     * Perechea din pagină a lui GP_ADMIN_ROLES / require_admin() din
     * api/lib/auth.php. Serverul păzește; rândurile astea doar hotărăsc ce se
     * desenează — dar dacă ele rămân la `role === "admin"`, un fondator vede
     * o aplicație care îl refuză peste tot, în timp ce API-ul îi răspunde la
     * orice. Nu e o gaură de securitate, e mai rău de trăit: aplicația pare
     * stricată exact pentru omul cu cele mai multe drepturi.
     *
     * Zece porți în opt pagini, deci scrisă o dată. Când mâine apare încă un
     * rol cu drepturi depline, se adaugă aici — nu se caută prin pagini.
     *
     * Primește ori obiectul contului, ori direct șirul cu rolul, fiindcă
     * paginile țin când una, când alta (S.auth în unele, S.role în altele).
     */
    eAdmin(cine) {
      const rol = typeof cine === 'string' ? cine : (cine && cine.role) || '';
      return rol === 'admin' || rol === 'fondator';
    },

    /**
     * „Vede raportul de pontaj?" — administrator, fondator sau manager.
     *
     * Perechea din pagină a lui `GP_SHIFTS_ROLES` / `require_shifts()` din
     * api/lib/auth.php. Serverul păzește; rândul ăsta hotărăște doar dacă
     * jumătatea de jos a paginii de Pontaj se DESENEAZĂ.
     *
     * DE CE ÎNTREBAREA SE PUNE AICI, și nu se află din răspunsul serverului, ca
     * poarta ceasului de deasupra ei. Ceasul e pagina întreagă: dacă serverul
     * spune 403, pagina arată ecranul de refuz și gata. Raportul e o bucată
     * dintr-o pagină care se deschide oricum — iar o bucată desenată întâi și
     * ștearsă după ce vine refuzul clipește pe ecranul unui angajat cu orele
     * colegilor lui în ea. Mai bine o întrebare pusă înainte.
     *
     * DE CE NU `GP_OPS_ROLES`, adică de ce nu și angajatul: raportul arată
     * orele TUTUROR, iar ale lui sunt printre ele. O foaie de salarii deschisă
     * întregii ture nu mai e o unealtă de plată, e o listă de comparat.
     */
    eRaportPontaj(cine) {
      const rol = typeof cine === 'string' ? cine : (cine && cine.role) || '';
      return rol === 'admin' || rol === 'fondator' || rol === 'manager';
    },

    /**
     * „Scrie comanda de-a dreptul, sau trimite o cerere?"
     *
     * Perechea din pagină a lui `GP_ORDER_CREATE_ROLES` / `writes_orders()`
     * din api/lib/auth.php. Serverul păzește — `POST /orders` răspunde 403
     * celorlalți —, iar rândul ăsta hotărăște numai pe ce ușă pleacă
     * formularul la salvare și dacă fila „Cereri de pe site" se desenează.
     *
     * DE CE O ÎNTREBARE PUSĂ ÎNAINTE, și nu un refuz aflat după. Formularul de
     * comandă e ACELAȘI pentru toți cei care îl pot deschide: agentul scrie
     * clientul, ziua, pozițiile, tot. Aflat abia la apăsarea butonului, refuzul
     * ar veni peste o muncă deja făcută, iar omul n-ar avea ce face cu el —
     * cererea lui e drumul cuvenit, nu o greșeală. Așa, butonul îi spune de la
     * bun început că trimite spre aprobare.
     *
     * DE CE NU `eRaportPontaj`, care azi întoarce exact același răspuns pentru
     * exact aceleași trei roluri. Fiindcă întrebările sunt deosebite — „citește
     * statul de plată" și „răspunde de ce intră în laborator" — și se despart
     * la primul rol nou. Aceeași pricină, scrisă pe larg, stă lângă
     * `GP_ORDER_CREATE_ROLES`: două liste care se nimeresc la fel nu sunt
     * aceeași listă.
     */
    eScrieComenzi(cine) {
      const rol = typeof cine === 'string' ? cine : (cine && cine.role) || '';
      return rol === 'admin' || rol === 'fondator' || rol === 'manager';
    },

    /**
     * „Poate căuta un client după nume?"
     *
     * Perechea din pagină a lui `client_scope_for()` (api/lib/orders.php).
     * Serverul păzește — `GET /clients` răspunde 403 `client_search_denied`
     * celorlalți —, iar rândul ăsta hotărăște numai dacă se DESENEAZĂ caseta de
     * căutare. O casetă care promite ce serverul refuză e mai rea decât lipsa
     * ei: omul scrie un nume, nu se întâmplă nimic, și nu află de ce.
     *
     * PATRU ROLURI, DOUĂ OGRĂZI. Admin, fondator și manager caută printre toți
     * clienții casei; agentul de vânzări numai printre cei aduși de el.
     * Deosebirea dintre cele două ogrăzi NU se ia de aici, ci din SQL, unde e
     * scrisă o singură dată: pagina întreabă doar „se desenează caseta?".
     *
     * DE CE NU `eScrieComenzi`, care azi întoarce același răspuns pentru trei
     * din cele patru. Fiindcă întrebările sunt deosebite — „naște comanda" și
     * „știe pentru cine o naște" — iar agentul le desparte chiar acum: el nu
     * scrie comenzi, dar își caută clienții. Aceeași pricină, scrisă pe larg,
     * stă lângă `GP_ORDER_CREATE_ROLES` în api/lib/auth.php.
     *
     * Managerul a intrat pe 19 septembrie 2026, la cererea patronului: „când
     * scrii numele clientului să ai o căutare prin istoricul clienților și
     * să-i poți alege".
     */
    eCautaClienti(cine) {
      const rol = typeof cine === 'string' ? cine : (cine && cine.role) || '';
      return rol === 'admin' || rol === 'fondator' || rol === 'manager'
        || rol === 'agent_vanzari';
    },

    /**
     * „Vede cifrele casei?" — deocamdată numai fondatorul.
     *
     * Perechea din pagină a lui `GP_STATS_ROLES` / `require_stats()` din
     * api/lib/auth.php. Serverul păzește; rândul ăsta hotărăște doar dacă
     * pagina se DESENEAZĂ sau arată ecranul de refuz.
     *
     * DE CE O FUNCȚIE PENTRU UN SINGUR ROL, și nu `role === "fondator"` scris
     * în pagină. Fiindcă întrebarea nu e „e fondator?", ci „are voie la
     * statistici?" — iar cele două vor rămâne același lucru doar până când
     * cineva hotărăște altfel. Scrisă pe rol, schimbarea aia ar trebui găsită
     * prin pagini; scrisă aici, se face într-un rând, lângă oglinda ei de pe
     * server.
     *
     * DE CE NU `eAdmin`, adică de ce administratorul NU intră. E singura ușă
     * din casă mai îngustă decât drepturile depline, și e îngustă dinadins:
     * administratorul ține patiseria în mână zi de zi, dar banii sunt ai
     * proprietarului. Peste tot în rest cele două roluri trec prin aceleași
     * uși — aici nu. `.claude/check-meniu.mjs` se plânge dacă se apropie iar,
     * și dacă rândul ăsta nu mai spune ce spune PANOURI_ROL (secțiunea G).
     */
    eStats(cine) {
      const rol = typeof cine === 'string' ? cine : (cine && cine.role) || '';
      return rol === 'fondator';
    },

    MENIU: [
      { et: 'Comenzi', href: 'Administrare%20Comenzi.dc.html' },
      { et: 'Laborator', href: 'Panou%20Laborator.dc.html' },
      { et: 'Rută', href: 'Ruta%20Sofer.dc.html' },
      { et: 'Depozit', href: 'Depozit.dc.html' },
      // Lângă celelalte panouri de tabletă, fiindcă de pe aceleași tablete se
      // deschide: ceasul de pontaj sus, raportul dedesubt — dar raportul numai
      // pentru cine are voie să-l vadă (GP_SHIFTS_ROLES pe server).
      { et: 'Pontaj', href: 'Pontaj.dc.html' },
      { et: 'Catalog', href: 'Produse.dc.html' },
      { et: 'Conturi', href: 'Conturi.dc.html' },
      { et: 'Recomandări', href: 'Recomandari.dc.html' },
      { et: 'Jurnal', href: 'Jurnal.dc.html' },
      // Lângă Jurnal, fiindcă amândouă se uită înapoi: unul la ce a făcut
      // fiecare, celălalt la ce a ieșit din toate. Singura intrare pe care
      // administratorul n-o are — vezi PANOURI_ROL mai jos.
      { et: 'Statistici', href: 'Statistici.dc.html' },
      { et: 'Setări', href: 'Setari.dc.html' },
      // Pagina clientului. Stă în aceeași listă cu panourile fiindcă e tot o
      // intrare de meniu, chiar dacă n-o vede decât un rol: altfel clientul ar
      // fi avut al doilea meniu, scris în alt loc, care s-ar fi depărtat de
      // ăsta la prima schimbare.
      { et: 'Comanda mea', href: 'Client.dc.html' },
      { et: 'Parola mea', href: 'Parola.dc.html' },
    ],

    PANOURI_ROL,
    ACASA_ROL,

    /**
     * Forma în care paginile țin contul în starea lor.
     *
     * Toate țineau deja `{u, name, role}`, scris de mână în zece locuri. De
     * când meniul se filtrează pe rol, îi mai trebuie două lucruri: codul de
     * recomandare — ca „Recomandări" să apară clientului care chiar recomandă,
     * nu tuturor — și `isSystem`, ca tableta din tură să nu-și poată schimba
     * parola comună. Scrise tot de mână, ar fi lipsit exact din pagina uitată,
     * iar meniul de acolo ar fi fost altul decât în rest, fără să pice nimic.
     */
    auth(user) {
      if (!user) return null;
      return {
        u: user.username,
        name: user.name,
        role: user.role,
        // Codul se caută în DOUĂ locuri, fiindcă serverul îl trimite altfel
        // după cine întreabă:
        //
        //   · `GET /auth/me` (contul propriu) îl pune deoparte, în
        //     `referral: {code, link}` — `public_user()` nu-l are, fiindcă
        //     rândul vine din `current_user()`, o interogare cu listă fixă de
        //     coloane care nu cuprinde `referral_code` (motivul, pe larg, la
        //     api/routes/auth.php:219-223). `gp.me()` îl lipește pe utilizator
        //     ca `user.referral`.
        //   · `GET /users` (lista din Conturi) îl pune chiar pe utilizator, ca
        //     `referralCode`, fiindcă acolo interogarea îl citește.
        //
        // Prima formă căuta numai a doua cale. Pe contul propriu ieșea deci
        // MEREU `null`, iar urmarea se vedea în meniu: clientul care recomandă
        // nu primea „Recomandări", adică singura pagină în care își vede banii.
        // Măsurat pe `demo-cumparator` (cod „cumparator"): meniul lui era
        // „Comanda mea · Parola mea"; cu codul ajuns aici devine „Recomandări ·
        // Comanda mea · Parola mea". Nu pica nicio probă, fiindcă
        // `.claude/meniu-real.mjs` cheamă `gp.meniu()` cu conturi făcute de el,
        // nu cu ce iese din funcția asta.
        referralCode: user.referralCode || (user.referral && user.referral.code) || null,
        isSystem: user.isSystem === true,
        // Poate contul ăsta să spună că marfa a ajuns la client? (runda 2.3.0)
        //
        // `!== false`, nu `=== true`: un server de dinaintea rundei n-are
        // câmpul, iar `undefined` trebuie să însemne „poate" — aceeași cădere
        // deschisă ca pe server. Cu `=== true`, actualizarea paginilor înaintea
        // celei a API-ului ar fi stins butonul șoferului pe toate telefoanele.
        poateConfirmaLivrarea: user.poateConfirmaLivrarea !== false,
      };
    },

    /**
     * Unde trimitem omul după autentificare.
     *
     * Până acum era o singură întrebare — client sau nu — iar tot ce nu era
     * client ajungea la Administrare Comenzi. Pentru manager și pentru agentul
     * de vânzări asta însemna un ecran de refuz imediat după parola corectă.
     */
    primaPagina(cine) {
      const rol = typeof cine === 'string' ? cine : (cine && cine.role) || '';
      return ACASA_ROL[rol] || 'Parola.dc.html';
    },

    /**
     * Meniul gata de desenat: numai intrările rolului, cu pagina curentă
     * marcată.
     *
     * `activ` e eticheta paginii care întreabă — „Conturi" în Conturi.
     * Intrarea ei rămâne fără adresă: o legătură către pagina pe care ești
     * deja arată ca un buton stricat, apeși și nu se întâmplă nimic.
     *
     * `cine` e contul, în forma dată de `gp.auth()`. Fără el iese listă goală,
     * dinadins: rolul vine de la server, deci în prima clipă a paginii încă nu
     * se știe. Un meniu care ar apărea întreg și apoi s-ar strânge ar arăta
     * omului, o clipă, exact butoanele pe care lucrarea asta le scoate.
     *
     * Culorile nu vin de aici. Fișierul ăsta traduce date, nu desenează —
     * panourile au fundal închis, paginile de client au fundal deschis, iar o
     * culoare scrisă aici ar fi greșită într-una dintre ele.
     */
    meniu(activ, cine) {
      const rol = typeof cine === 'string' ? cine : (cine && cine.role) || '';
      const permise = PANOURI_ROL[rol] || [];
      const obiect = !!cine && typeof cine === 'object';
      const areCod = obiect && !!cine.referralCode;
      const eSistem = obiect && cine.isSystem === true;

      return gp.MENIU.filter((x) => {
        // Pagina pe care ești deja rămâne mereu în listă, oricare ar fi rolul.
        // Altfel meniul n-ar avea nicio intrare marcată și n-ar mai spune unde
        // te afli — se întâmplă la administratorul care deschide „Comanda mea"
        // ca să vadă ce vede clientul. Nu e o portiță: ești deja pe pagină, iar
        // datele ți le dă serverul sau nu.
        if (x.et === activ) return true;

        // „RECOMANDĂRI" SE HOTĂRĂȘTE DUPĂ COD, NU DUPĂ ROL — și de aceea stă
        // ÎNAINTEA matricei, nu după ea.
        //
        // Regula, într-o propoziție: pagina banilor e a celui care chiar are un
        // cod. Din ea ies amândouă capetele:
        //
        //   · clientul FĂRĂ cod n-o vede, deși e în matricea lui — ar deschide
        //     o pagină goală cu o singură propoziție tristă;
        //   · managerul sau angajatul CU cod o vede, deși nu e în matricea lui.
        //
        // Al doilea cap e o reparație, nu o lărgire de drepturi. Un cod se dă
        // din „Conturi" oricărui cont care nu e de sistem, iar
        // `referral_owner()` nu se uită la rol: un manager cu cod CHIAR
        // câștigă comision, iar `GET /referrals` îi răspunde 200 cu rândurile
        // și cu totalul lui. Măsurat pe instanța de probă: comision
        // `{amount: 1.05, status: "activ"}`, `totals.due = 1.05` — bani
        // adevărați, pe un cont care nu avea pe unde să ajungă la pagina în
        // care se văd. Matricea spune unde te trimite ROLUL; codul e al OMULUI.
        //
        // Managerul obișnuit, cel fără cod, nu vede nicio schimbare.
        if (x.et === 'Recomandări') {
          // Clientul: NUMAI cu cod. Fără el pagina e goală, iar un client
          // nu-și poate face singur un cod — deci ar fi o intrare care nu
          // duce nicăieri. (Paza: .claude/check-meniu.mjs.)
          if (rol === 'client') return areCod;
          // Restul: din matricea rolului SAU cu un cod pe cont. Agentul de
          // vânzări o păstrează și fără cod, fiindcă își poate face unul
          // chiar de acolo (`canMake`, GET /referrals).
          return permise.indexOf('Recomandări') >= 0 || areCod;
        }

        if (permise.indexOf(x.et) < 0) return false;
        // Contul comun al tabletelor n-are parolă a lui: e a întregii ture.
        // Cine ar schimba-o de acolo ar închide panoul tuturor, fără să vrea.
        if (x.et === 'Parola mea' && eSistem) return false;
        return true;
      }).map((x) => ({
        et: x.et,
        href: x.href,
        activ: x.et === activ,
        // Perechea lui `activ`, fiindcă șablonul nu știe să nege: `sc-if`
        // primește o valoare, nu o expresie. Două `sc-if` complementare sunt
        // și tiparul casei (vezi `deniedClient` / `deniedStaff` în Conturi).
        link: x.et !== activ,
      }));
    },

    /**
     * Ultimul rând al sertarului: ieșirea din panou.
     *
     * Era scris de mână, la fel în toate cele unsprezece pagini:
     * „← Alege panoul", spre `Intrare.dc.html`. Pentru personal e corect. Un
     * client însă n-are niciun panou de ales — rândul îl scotea din magazin și
     * îl punea în pagina de autentificare, care lui îi arată un card cu încă
     * două legături. Nu era un fund de sac, dar era un ocol printr-o pagină
     * care îi cerea să aleagă dintre lucruri pe care nu le are.
     *
     * Aici, nu în pagini, din același motiv pentru care meniul e aici: unsprezece
     * copii ale aceleiași hotărâri se depărtează una de alta.
     */
    iesire(cine) {
      const rol = typeof cine === 'string' ? cine : (cine && cine.role) || '';
      return rol === 'client'
        ? { href: 'Site%20Public.dc.html', et: '← Înapoi la magazin' }
        : { href: 'Intrare.dc.html', et: '← Alege panoul' };
    },

    /**
     * De ce n-au venit datele, spus omului care se uită la ecran.
     *
     * Panourile prindeau eroarea și o scriau în `console.warn` — adică
     * nicăieri. Pe tableta din laborator și pe telefonul șoferului nu există
     * consolă și nu se deschide una. Ecranul rămânea gol, iar starea goală
     * spunea mai mult decât știa pagina: „Nicio livrare în ziua asta” e o
     * afirmație de fapt, dar pagina nu apucase să întrebe. Șoferul citea un
     * „n-ai ce livra” sigur pe el și pleca acasă cu marfa în mașină.
     *
     * Așa s-a văzut defectul raportat din patiserie: în meniul de zile scria
     * că sunt opriri, iar lista de dedesubt era goală, fără niciun motiv. Cele
     * două numere vin din două cereri deosebite, iar când una pică și cealaltă
     * nu, ecranul se contrazice singur și nu spune de ce.
     *
     * Aici, nu în pagini, din același motiv pentru care meniul e aici: cinci
     * copii ale aceluiași mesaj se depărtează una de alta.
     *
     * `ce` numește lucrul care n-a venit, cum intră în frază: „ruta zilei”,
     * „comenzile zilei”. 401 NU trece pe aici — sesiunea expirată are deja
     * drumul ei, `gp.toLogin()`. Excepție: vitrina publică, unde nu există
     * sesiune de pierdut și unde refuzul se arată ca oricare altul.
     *
     * Magazinul e singura pagină bilingvă din casă. Ca să nu-i primească
     * vizitatorul de pe „/en/" o frază românească în mijlocul ecranului, `ce`
     * poate veni și ca pereche — `{ ro: 'produsele din magazin', en: 'the
     * shop products' }` — iar atunci răspunsul e tot o pereche, `{ ro, en }`,
     * din care pagina alege la desenare. Chemat cu `ce` șir, cum îl cheamă
     * celelalte cincisprezece pagini, dă exact ce dădea și până acum: un
     * singur șir, în română.
     */
    motiv(e, ce) {
      // Perechea o duce mai departe `motivEn()`, geamănul de dedesubt: fraza
      // englezească se scrie o dată, aici, nu în pagină. Copiată în pagină,
      // s-ar depărta de originalul de aici la prima îndreptare.
      if (ce && typeof ce === 'object') {
        return { ro: gp.motiv(e, ce.ro), en: gp.motivEn(e, ce.en) };
      }
      const lucrul = ce || 'datele';
      const s = e && e.status;
      if (s === 403) {
        return 'Contul cu care ești intrat nu are voie să vadă ' + lucrul
          + '. Cere-i unui administrator să-ți pună rolul potrivit.';
      }
      if (s === 404) {
        return 'Serverul nu cunoaște adresa cerută. Cel mai probabil actualizarea '
          + 's-a oprit la jumătate — spune-i administratorului.';
      }
      if (s === 422 || s === 409) {
        return (e && e.message) || ('Cererea pentru ' + lucrul + ' a fost refuzată.');
      }
      if (s >= 500) {
        return 'Serverul n-a putut aduce ' + lucrul + ' (eroarea ' + s + '). '
          + 'Motivul îl vede administratorul în jurnalul serverului.';
      }
      if (!s) {
        return 'Nu am ajuns la server. Verifică legătura la internet, apoi încearcă din nou.';
      }
      return (e && e.message) || ('Nu am putut aduce ' + lucrul + '.');
    },

    /**
     * Geamănul englezesc al lui `motiv()`, pentru vitrina publică.
     *
     * Nu se cheamă de-a dreptul din pagini: intrarea e tot `gp.motiv()`, cu
     * `ce` dat ca pereche. Stă aici, lipit de original, ca cele două fraze să
     * se citească una sub alta: ramurile sunt aceleași, în aceeași ordine, și
     * cine adaugă mâine un refuz nou vede pe loc că are două locuri de scris,
     * nu unul. În pagini n-are ce căuta — acolo s-ar înmulți.
     *
     * Aceleași reguli ca dincolo: niciun mesaj nu pretinde că nu există date,
     * eroarea de server își poartă numărul, iar refuzul lămurit de server se
     * arată cu vorbele serverului (care vin în română de la API — le lăsăm
     * așa: sunt mai exacte decât orice ghicim noi aici), cu o singură
     * excepție, explicată la `alServerului` mai jos.
     */
    motivEn(e, ce) {
      const lucrul = ce || 'the data';
      const s = e && e.status;
      // Excepția de la regula „vorbele serverului trec așa cum vin”.
      //
      // O singură frază din `e.message` NU e scrisă de server: „Eroare NNN.”,
      // pe care o fabrică `request()` (vezi mai sus, la aruncarea lui
      // `GpError`) când răspunsul are cod de eroare și n-are corp JSON. Așa
      // arată un 429 dat de portarul din față sau un 413 dat de serverul web,
      // înainte ca PHP-ul nostru să apuce să spună ceva.
      //
      // Aia e româneasca NOASTRĂ, nu a API-ului, și sub un titlu englezesc
      // n-are ce căuta: vizitatorul de pe „/en/” ar citi „Eroare 429.” în
      // mijlocul unei pagini englezești, fără să afle nici ce s-a întâmplat,
      // nici ce are de făcut. O lăsăm deci să cadă pe fraza englezească de
      // dedesubt, care măcar spune și ce urmează.
      const alServerului = !!(e && e.message) && e.message !== 'Eroare ' + s + '.';
      if (s === 403) {
        return 'The account you are signed in with may not see ' + lucrul
          + '. Ask an administrator to give you the right role.';
      }
      if (s === 404) {
        return 'The server does not know the requested address. The update most '
          + 'likely stopped halfway — please tell the administrator.';
      }
      if (s === 422 || s === 409) {
        return (alServerului && e.message) || ('The request for ' + lucrul + ' was refused.');
      }
      if (s >= 500) {
        return 'The server could not fetch ' + lucrul + ' (error ' + s + '). '
          + 'The administrator can see why in the server log.';
      }
      if (!s) {
        return 'We could not reach the server. Check your internet connection, then try again.';
      }
      return (alServerului && e.message) || ('We could not fetch ' + lucrul + '.');
    },

    /**
     * Cere o sesiune. Fără ea, duce omul la autentificare.
     *
     * Fiecare panou o apelează la încărcare, ÎNAINTE de orice altă cerere.
     * Ordinea contează: o cerere de date pornită mai devreme ia 401 și
     * declanșează singură o plecare, iar pagina se golește sub degete în
     * timp ce omul citește un ecran care încă îi cere parola.
     */
    async requireUser() {
      const user = await gp.me();
      if (!user) {
        gp.toLogin();
        // Oprim execuția până la redirect, ca pagina să nu randeze goală.
        await new Promise(() => {});
      }
      return user;
    },

    /* --- traducere între forma API și forma paginilor --- */

    /**
     * Comanda din API în forma folosită de panouri.
     *
     * Paginile au fost desenate în jurul unei structuri proprii (`cust`,
     * `chan`, `q`/`n` la produse). Traducem aici, într-un singur loc, ca
     * fiecare panou să nu-și facă propria versiune și să nu trebuiască
     * rescris designul.
     */
    toPageOrder(o) {
      return {
        id: o.id,
        ref: o.ref,
        cust: o.customerName || '',
        chan: o.fulfillment || 'Livrare',
        day: (o.day || '').slice(-2),      // panourile lucrează cu ziua din lună
        date: o.day,
        slot: o.slot || '',
        zone: o.zone || '',
        addr: o.address || '',
        status: o.status,
        note: o.notes || '',
        urgent: !!o.urgent,
        timed: !!o.timed,
        routeSeq: o.routeSeq === undefined ? null : o.routeSeq,
        labSeq: o.labSeq === undefined ? null : o.labSeq,
        stockSynced: !!o.stockSynced,
        variant: o.variant || 'copt',
        phone: o.phone || '',
        total: o.total,
        // Cine a adus cumpărătorul, pentru eticheta violetă de pe card.
        //
        // Trece nedesfăcut: pagina are nevoie și de `status` (un comision în
        // așteptare nu e al nimănui încă), și de `paid` (bifa din fereastra
        // comenzii), și de `id` (rândul pe care îl bifează). Serverul îl pune
        // pe toate cele patru drumuri ale comenzii — listă, citire, creare,
        // actualizare — deci eticheta apare fără reîncărcare.
        //
        // `null` pe o bază fără migrarea 008 și pe comenzile fără recomandant:
        // pagina nu desenează nimic, exact ca înainte.
        commission: o.commission || null,
        // Codul scris pe comandă, ca formularul să-l poată ARĂTA, nu doar
        // ghici din eticheta violetă. Serverul îl trimite numai personalului
        // (api/lib/orders.php, attach_items), deci pe drumul clientului vine
        // null și câmpul rămâne gol — la fel ca pe o bază fără migrarea 008.
        refCode: o.referralCode || '',
        // De ce cont s-a lipit comanda. Serverul îl trimitea de mult
        // (`customerId`, api/lib/orders.php · attach_items), dar nicio pagină
        // nu-l citea — deci legarea după nume lucra nevăzută, iar o comandă
        // lipită de contul greșit arăta exact ca una lipită de cel bun.
        // Fereastra comenzii îl arată acum, ca text, nu ca număr.
        contId: o.customerId === undefined ? null : o.customerId,
        // A CÂTA COMANDĂ E ASTA pentru contul din spatele ei: „prima",
        // „revenit" sau „fara-cont". Socoteala e a serverului, peste tot
        // istoricul (api/lib/orders.php · `rang_client`). Făcută în pagină,
        // din comenzile aduse, ar fi citit doar fereastra de șapte zile și ar
        // fi spus „prima comandă" despre un client de trei ani.
        //
        // Gol pe un server rămas în urmă — și atunci nu se desenează nicio
        // insignă, ceea ce e altceva decât a desena una greșită.
        rangClient: o.rangClient || '',
        // Cine a SCRIS comanda, nu cine a cumpărat-o. Vine numai celor din
        // casă; serverul îl lasă gol pentru cumpărător, iar un server rămas în
        // urmă nu trimite deloc cheia. Amândouă ies `null`, iar `gp.creatDe()`
        // nu desenează nimic — v. lămurirea de la el.
        creatDe: o.creatDe || null,
        items: (o.items || []).map((it) => ({
          q: String(it.qty), n: it.name,
          // Numărul poziției și câte bucăți s-au raportat făcute. `itemId` e
          // adresa la care laboratorul raportează producția: indexul din tablou
          // nu e adresă, fiindcă o rescriere a comenzii schimbă ordinea.
          itemId: it.id === undefined ? null : it.id,
          done: it.doneQty === undefined ? 0 : Number(it.doneQty),
          productId: it.productId === undefined ? null : it.productId,
          variant: it.variant || o.variant || 'copt',
          unitPrice: it.unitPrice,
        })),
      };
    },

    /** Cererea din API în forma folosită de pagina de administrare. */
    toPageRequest(r) {
      return {
        id: r.id,
        cust: r.customerName || '',
        phone: r.phone || '',
        email: r.email || '',
        day: (r.day || '').slice(-2),
        date: r.day,
        slot: r.slot || '',
        zone: r.zone || '',
        addr: r.address || '',
        note: r.notes || '',
        status: r.status,
        // Motivul respingerii. Serverul îl salvează de când există butonul de
        // refuz, dar nu-l citea nimeni: clientul vedea cererea dispărând fără
        // să afle vreodată de ce.
        reason: r.rejectReason || '',
        total: r.total,
        variant: r.variant || 'copt',
        orderRef: r.orderRef || '',
        // Același rang ca pe comenzi, venit prin celălalt pâlnier al
        // serverului (`attach_request_items`), ca insigna să se vadă și în
        // fila „Cereri de pe site" — acolo unde „fără cont" e chiar regula,
        // nu excepția.
        rangClient: r.rangClient || '',
        // Cine a HOTĂRÂT cererea. Pe cererea încă nehotărâtă e gol — n-a
        // hotărât nimeni încă —, iar pe cea respinsă e singurul nume rămas:
        // din ea nu s-a născut nicio comandă care să-l poarte.
        creatDe: r.hotaratDe || null,
        // CINE A SCRIS-O, de la migrarea 022 încoace, și e altceva decât cine a
        // hotărât-o: cererea scrisă de agentul de vânzări în panou poartă
        // numele lui de la bun început, cât încă e pe „nou" și nimeni n-a
        // hotărât nimic. Fără el, hârtia agentului arată la fel cu una picată
        // de pe site, iar administratorul n-are pe cine întreba ce s-a vorbit
        // la telefon. Gol înseamnă „chiar de pe site".
        scrisDe: r.scrisDe || null,
        // Ce a ales omul care a luat telefonul (tot migrarea 022). Pe cererea
        // de pe site sunt goale, fiindcă vizitatorul n-a ales nimic — iar gol
        // se citește la acceptare ca „cade pe implicitul din Setări", nu ca o
        // alegere.
        fulfillment: r.fulfillment || '',
        urgent: !!r.urgent,
        timed: !!r.timed,
        // Cererea n-are comision — rândul se naște abia la acceptare — deci
        // duce codul tastat în coș și contul din spatele lui, dacă mai există.
        // `name` gol înseamnă „cod fără cont activ", nu „fără cod".
        referral: r.referralCode
          ? { code: r.referralCode, name: r.referralName || '', id: r.referralId || null }
          : null,
        at: gp.utc(r.createdAt),
        // Cererea pusă deoparte de la butonul „🕒 Mai târziu" din anunțul de
        // pe telefon. Ora vine în aceeași formă ca `createdAt` —
        // „2026-09-12 22:05:10", UTC, fără fus — deci trece prin aceeași
        // socoteală; `new Date(șir)` pe curat ar citi-o drept oră locală și ar
        // greși cu trei ore vara.
        //
        // `0` înseamnă „nu e pusă deoparte", ca paginile să compare numere în
        // loc să deosebească null de șir gol. Pe un server pe care nu s-a
        // rulat migrarea 013 cheia lipsește cu totul din răspuns — tot 0 iese.
        snoozed: r.snoozedUntil ? gp.utc(r.snoozedUntil) : 0,
        // Pe unde s-a hotărât cererea: „panou" sau „telegram". Gol înseamnă
        // „nu se știe", și e o stare cinstită, nu o defecțiune: coloana a fost
        // goală până acum, deci toate cererile de dinainte au null, ca și
        // toate cele de pe un server nemigrat.
        decidedVia: r.decidedVia || '',
        items: (r.items || []).map((it) => ({
          q: String(it.qty), n: it.name,
          productId: it.productId === undefined ? null : it.productId,
          variant: it.variant || r.variant || 'copt',
          unitPrice: it.unitPrice,
        })),
      };
    },

    /**
     * Listele configurabile din Setări (etape, intervale, zone) plus cele
     * două etape cu rol special: cea care încheie comanda și cea care o
     * anulează.
     *
     * Valorile de mai jos acoperă intervalul dintre încărcarea paginii și
     * primul răspuns al serverului. Ele NU trebuie folosite ca adevăr: dacă
     * schimbi „Predată” în „Livrată” din Setări, paginile trebuie să afle de
     * la server, nu dintr-un text scris în cod.
     */
    __lists: null,
    __L: {
      statuses: ['În așteptare', 'La dospit', 'În cuptor', 'La ambalare', 'Predată', 'Anulată'],
      done: 'Predată',
      cancelled: 'Anulată',
      // Se umple din `/lists` (v. `labCeiling()`). Gol la început DINADINS: pe
      // un server vechi, care n-are câmpul, se socotește din etape — iar o
      // valoare scrisă aici de-a gata ar fi mințit tocmai atunci, când Setările
      // au alte etape decât cele de mai sus.
      labCeiling: '',
    },

    async lists() {
      if (!gp.__lists) {
        gp.__lists = await request('GET', '/lists');
        if (gp.__lists) {
          if (gp.__lists.statuses && gp.__lists.statuses.length) gp.__L.statuses = gp.__lists.statuses;
          if (gp.__lists.done) gp.__L.done = gp.__lists.done;
          if (gp.__lists.cancelled) gp.__L.cancelled = gp.__lists.cancelled;
          if (gp.__lists.labCeiling) gp.__L.labCeiling = gp.__lists.labCeiling;
        }
      }
      return gp.__lists;
    },

    /**
     * Punctul de plecare al livrărilor, din Setări.
     *
     * Era scris în cod, cu adresa din prototip — la câțiva kilometri de
     * depozitul real — deci toți kilometrii de pe ruta șoferului porneau
     * greșit, iar „optimizează ruta" aranja opririle față de alt oraș.
     */
    hq: () => (gp.__lists && gp.__lists.hq) || { label: 'Depozit', lat: 44.39235, lng: 26.12385 },

    /**
     * Ce spune patiseria despre sine, în public: telefon, e-mail, adresă,
     * program.
     *
     * Erau scrise în pagina magazinului — telefonul în cinci locuri, adresa în
     * șapte, programul în două. Patiseria își schimba numărul din panou, iar
     * clientul suna tot la cel vechi; și tot acolo ducea și butonul de
     * WhatsApp. Cu adresa și programul era mai rău decât „rămâne în urmă":
     * site-ul spunea DOUĂ programe deodată. Pagina citită de om zicea
     * „Luni–Vineri 07:00–21:00, Sâmbătă 07:00–17:00, Duminică închis"; datele
     * trimise lui Google, din aceleași Setări, ziceau „Luni–Sâmbătă
     * 05:00–21:00". Nu forme deosebite ale aceluiași program — ore și zile
     * deosebite. Unul din cei doi clienți venea la ușă închisă.
     *
     * Acum amândouă vin din `api/lib/firma.php`, chemat și de GET /lists, și de
     * pagina scrisă pe server. Orele ajung aici DESFĂCUTE pe zile, de pe
     * server: o a doua desfacere, în JavaScript, ar fi tocmai al doilea izvor
     * pe care îl astupăm.
     *
     * Valorile de mai jos țin numai până răspunde serverul; adevărul e cel
     * scris în Setări. `hours.days` rămâne GOL până atunci, dinadins: un tabel
     * cu ore de rezervă, scrise în cod, e exact paguba de la care am plecat.
     * Cine îl desenează întreabă întâi dacă are ce.
     */
    contact: () => {
      const c = (gp.__lists && gp.__lists.contact) || {};
      const h = c.hours || {};
      return {
        phone: c.phone || '0769 493 163',
        email: c.email || 'contact@goldenpastry.ro',
        address: c.address || 'Șos. Olteniței 103',
        addressFull: c.addressFull || 'Șos. Olteniței 103, sector 4, București',
        addressFullEn: c.addressFullEn || 'Șos. Olteniței 103, district 4, Bucharest',
        mapUrl: c.mapUrl
          || 'https://www.google.com/maps/search/?api=1&query='
             + encodeURIComponent('Șos. Olteniței 103, sector 4, București'),
        hours: { ro: h.ro || '', en: h.en || '', days: h.days || null },
      };
    },

    /**
     * Programul, strâns în rânduri gata de desenat.
     *
     * Zilele alăturate cu aceleași ore se strâng într-un singur rând, ca la
     * rândul scris de `firma_publica()` pentru Google: „Luni – Vineri", nu șapte
     * rânduri, din care cinci la fel. Caseta de contact din magazin are loc
     * pentru trei-patru rânduri, nu pentru șapte, iar un tabel cu șapte rânduri
     * identice nu se citește mai bine — se citește mai greu.
     *
     * Fiecare rând: numele zilelor în limba cerută, orele (`null` = închis, iar
     * cine desenează scrie acolo cuvântul lui, fiindcă numai el știe cât loc
     * are), și dacă ziua de AZI cade înăuntru — de acolo iese rândul îngroșat.
     *
     * Ziua de azi se ia cu `gp.acumLaPatiserie()`, nu din ceasul telefonului:
     * pagina se deschide de oriunde, iar rândul îngroșat spune „azi suntem
     * deschiși până la…". Un client aflat pe alt fus vedea îngroșată ziua lui,
     * nu pe a patiseriei. Lecția e veche în magazin, la rândurile de program —
     * ea se mută aici odată cu ele.
     *
     * Lista e goală cât timp serverul n-a răspuns — vezi `gp.contact()`. Cine o
     * desenează arată caseta abia când are ce pune în ea: un tabel cu ore de
     * rezervă, scrise în cod, e chiar paguba de la care am plecat.
     */
    program(ro = true) {
      const zile = gp.contact().hours.days;
      if (!zile) return [];
      const ORDINE = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
      const NUME = {
        Monday: ['Luni', 'Monday'],
        Tuesday: ['Marți', 'Tuesday'],
        Wednesday: ['Miercuri', 'Wednesday'],
        Thursday: ['Joi', 'Thursday'],
        Friday: ['Vineri', 'Friday'],
        Saturday: ['Sâmbătă', 'Saturday'],
        Sunday: ['Duminică', 'Sunday'],
      };
      // getDay() dă 0 pentru duminică; ordinea de mai sus începe cu luni.
      const azi = ORDINE[(gp.acumLaPatiserie().getDay() + 6) % 7];
      const cheie = (z) => (zile[z] ? zile[z][0] + '-' + zile[z][1] : 'inchis');

      const randuri = [];
      for (const z of ORDINE) {
        const ultim = randuri[randuri.length - 1];
        if (ultim && ultim.cheie === cheie(z)) {
          ultim.pana = z;
          ultim.azi = ultim.azi || z === azi;
          continue;
        }
        randuri.push({ cheie: cheie(z), de: z, pana: z, orele: zile[z] || null, azi: z === azi });
      }

      const i = ro ? 0 : 1;
      return randuri.map((r) => {
        const a = NUME[r.de][i];
        const b = NUME[r.pana][i];
        let nume = a;
        if (r.de !== r.pana) {
          // Două zile alăturate se despart cu virgulă; trei sau mai multe se
          // scriu ca interval. „Sâmbătă – Duminică" ar fi o listă lungă scrisă
          // ca drum, iar „Luni, Marți, Miercuri, Joi, Vineri" ar fi un drum
          // scris ca listă.
          const lung = ORDINE.indexOf(r.pana) - ORDINE.indexOf(r.de) >= 2;
          nume = lung ? a + ' – ' + b : a + ', ' + b;
        }
        return { nume, orele: r.orele, azi: r.azi };
      });
    },

    /**
     * Punctul de pe hartă al unei zone de livrare, din Setări.
     *
     * O zonă fără coordonate cade pe depozit: distanța iese 0, ceea ce e
     * vizibil greșit pe rută — mai bine decât să pară corectă și să nu fie.
     *
     * Ecranele de rută nu mai ajung aici pentru o astfel de zonă: întreabă
     * întâi `gp.zoneFaraPunct()` și o scot cu totul din ordonare. Căderea pe
     * depozit rămâne pentru restul paginilor, care doar desenează un punct.
     */
    zonePoint(zone) {
      const map = (gp.__lists && gp.__lists.zoneCoords) || {};
      return map[zone] || gp.hq();
    },

    /**
     * Zona asta e una fără punct pe hartă?
     *
     * Altfel spus: o oprire din zona asta NU poate fi așezată în ordinea rutei,
     * fiindcă n-are față de ce. Ecranele de rută o scot din ordonare și o arată
     * aparte, într-o listă a ei, dusă de cine poate.
     *
     * ÎNTREBAREA NU SE PUNE PE NUME. „În afara Bucureștiului și Ilfovului" e
     * croită anume fără punct (vezi sql/016_zona_cont.sql), dar zonele se
     * redenumesc din Setări: un nume scris aici ar ține până în ziua în care
     * patronul îl scurtează, și de-atunci oprirea ar intra tăcut înapoi în
     * ordonare. Semnalul e lipsa punctului, care e chiar lucrul care lipsește.
     *
     * O oprire FĂRĂ NICIO ZONĂ rămâne unde e azi — pe rută, căzută pe depozit.
     * Aia e o comandă căreia nu i-a scris nimeni zona, de obicei una luată la
     * telefon prin oraș; scoasă din rută, ar putea să nu mai fie văzută.
     *
     * HARTA GOALĂ înseamnă „n-am aflat încă", nu „nicio zonă n-are punct":
     * altfel, cât timp listele sunt pe drum de la server, TOATĂ ruta s-ar muta
     * pentru o clipă în lista de deoparte, și tot acolo ar rămâne pe o
     * instalare căreia nu i s-au scris încă punctele.
     */
    zoneFaraPunct(zone) {
      const map = (gp.__lists && gp.__lists.zoneCoords) || {};
      if (!Object.keys(map).length) return false;
      const z = String(zone == null ? '' : zone).trim();
      return z !== '' && !map[z];
    },

    /**
     * Prima și ultima zi în care se poate cere o comandă, din Setări.
     *
     * Vin de la server tocmai ca să nu depindă de ceasul dispozitivului: un
     * telefon rămas cu data greșită i-ar fi arătat clientului alt „azi" decât
     * cel după care se ia patiseria, iar cererea lui ar fi picat abia la
     * acceptare, după ce pagina îi mulțumise deja. Valorile de mai jos țin
     * numai până răspunde serverul.
     */
    orderWindow() {
      const w = (gp.__lists && gp.__lists.orderWindow) || {};
      const azi = new Date();
      const zi = (d) => d.getFullYear() + '-'
        + String(d.getMonth() + 1).padStart(2, '0') + '-'
        + String(d.getDate()).padStart(2, '0');
      const peste = new Date(azi.getTime());
      peste.setFullYear(peste.getFullYear() + 1);
      return { first: w.first || zi(azi), last: w.last || zi(peste) };
    },

    /**
     * Eticheta variantei în care pleacă marfa.
     *
     * Prețul produsului depinde de ea: „congelat" folosește prețul pentru
     * livrare în lanț frigorific, „copt" pe cel pentru marfă gata de servire.
     */
    variantLabel: (v) => (v === 'congelat' ? 'Congelat' : 'Gata de servire'),

    /**
     * Insigna care spune cine e clientul din spatele unei comenzi.
     *
     * Trei stări, venite de la server în `rangClient`:
     *
     *   prima      #1    contul ăsta n-a mai avut nicio comandă înainte
     *   revenit    ✓✓    a mai comandat — bifa dublă, ca la mesaje
     *   fara-cont  ●     comanda nu e legată de niciun cont
     *
     * SCRISĂ O SINGURĂ DATĂ fiindcă o poartă trei ecrane: rândul din panou,
     * cardul de telefon și numele din laborator. În trei copii, prima vorbă
     * schimbată ar fi făcut același semn să spună lucruri diferite pe ecrane
     * care stau la doi metri unul de altul.
     *
     * Semnul singur nu se poate citi de cine îl vede prima oară, de aceea
     * fiecare are și vorbe: `et` pentru ecranele largi, `titlu` pentru
     * `title` și `aria-label` — acolo se spune și DE CE, nu doar CE.
     *
     * Un rang necunoscut (server rămas în urmă, câmp gol) întoarce `fel` gol,
     * iar paginile nu desenează nimic. Nicio insignă e răspunsul cinstit când
     * nu se știe; o insignă greșită ar fi o minciună despre un om.
     */
    rangClient(r) {
      if (r === 'prima') {
        return {
          fel: 'prima', semn: '#1', et: 'prima comandă',
          cls: 'h-badge h-badge--accent',
          titlu: 'Prima comandă a contului ăstuia. Comenzile anulate nu se numără.',
        };
      }
      if (r === 'revenit') {
        return {
          fel: 'revenit', semn: '✓✓', et: 'client revenit',
          cls: 'h-badge h-badge--success',
          titlu: 'Contul ăsta a mai comandat până acum.',
        };
      }
      if (r === 'fara-cont') {
        return {
          fel: 'fara-cont', semn: '●', et: 'fără cont',
          cls: 'h-badge h-badge--danger',
          titlu: 'Comandă fără cont: nu e legată de nimeni, deci omul nu o vede '
            + 'în „Comanda mea" și nu are istoric.',
        };
      }
      return { fel: '', semn: '', et: '', cls: 'h-badge', titlu: '' };
    },

    /**
     * Insigna care spune cine a scris comanda — sau cine a hotărât cererea.
     *
     * `orders.created_by` se scrie din 001 pe amândouă căile prin care se naște
     * o comandă, iar `order_requests.handled_by` la fiecare hotărâre. Până azi
     * nu le citea nimeni: fapta era în bază, dar nu pe niciun ecran.
     *
     * SCRISĂ O SINGURĂ DATĂ, ca `rangClient` de deasupra și din aceeași
     * pricină: o poartă patru ecrane — rândul din panou, cardul de telefon,
     * cardul de cereri și panoul de laborator. În patru copii, prima vorbă
     * schimbată ar fi făcut același nume să fie scris altfel pe ecrane care
     * stau la doi metri unul de altul.
     *
     * TREI TĂCERI, toate cinstite, toate ieșind pe aceeași ușă (`are: false`):
     *
     *   server rămas în urmă   cheia nu vine deloc  → `undefined`
     *   se uită cumpărătorul   serverul o lasă goală → `null`
     *   contul e șters         numele nu se mai găsește → `null`
     *
     * Ultima merită spusă apăsat: `orders.created_by` n-are cheie străină
     * (sql/001_schema.sql:213), deci id-ul rămâne scris după ștergerea
     * contului, dar numele nu se mai poate afla. O insignă goală, sau un „#14",
     * n-ar spune nimănui nimic; nicio insignă e răspunsul cinstit când nu se
     * știe. Aceeași alegere ca la un rang necunoscut.
     *
     * Insigna e neutră dinadins, fără `--accent` sau `--success`: nu e o stare
     * a comenzii, e o însemnare despre cine a atins-o, și stă lângă insigna de
     * client, care CHIAR e o stare. Două insigne colorate una lângă alta s-ar
     * fi citit ca două stări.
     */
    creatDe(nume, ce) {
      const n = typeof nume === 'string' ? nume.trim() : '';
      if (n === '') {
        return { are: false, nume: '', et: '', cls: 'h-badge', titlu: '' };
      }
      // ETICHETA E ACEEAȘI VORBĂ pentru amândouă hârtiile, fiindcă e aceeași
      // faptă: cineva din casă a scris-o cu mâna lui. Se schimbă numai
      // lămurirea de sub mouse, fiindcă urmarea E alta — o comandă e deja în
      // laborator, o cerere abia așteaptă o apăsare. Un al doilea desenator ar
      // fi însemnat aceeași insignă scrisă de două ori, iar a doua ar fi rămas
      // în urmă la prima schimbare de formă.
      //
      // Al doilea parametru lipsă înseamnă „comandă", deci toate chemările de
      // până azi spun exact ce spuneau.
      return {
        are: true,
        nume: n,
        et: 'Creat de ' + n,
        cls: 'h-badge',
        titlu: ce === 'cerere'
          ? 'Cererea a fost scrisă în panou de ' + n
            + '. N-a intrat încă în laborator — intră abia dacă o accepți.'
          : 'Comanda a fost scrisă în sistem de ' + n
            + '. Nu înseamnă că el a și făcut marfa.',
      };
    },

    /**
     * Cantitatea unei poziții, ca număr.
     *
     * Baza ține cantitățile în DECIMAL(10,2), iar formularul din panou le
     * primește cu zecimale de la bun început — o jumătate de tavă, 0,5.
     * Paginile le citeau însă cu `parseInt`, care taie tot ce e după virgulă:
     * pe lista de făcut a laboratorului „0,5” devenea 0, iar o poziție de o
     * jumătate de tavă dispărea cu totul din totalul zilei. Brutarul făcea
     * marfa de pe listă, iar bucata lipsă se descoperea abia la ambalare.
     */
    qty(v) {
      const n = parseFloat(String(v == null ? '' : v).replace(',', '.'));
      return isFinite(n) ? n : 0;
    },

    /**
     * Un moment UTC scris de MySQL („2026-09-07 10:20:30”), ca milisecunde.
     *
     * Formatul cu spațiu nu e ISO 8601, iar `Date.parse` îl acceptă doar
     * după bunul plac al browserului: Chrome îl citește, Safari de pe iPhone
     * întoarce NaN. Paginile lipeau „Z" și cădeau pe `|| 0`, adică 1 ianuarie
     * 1970 — Jurnalul arăta toate intrările în 1970, iar cererile clientului
     * se sortau aiurea pe telefon. Aici spațiul devine „T", cum cere ISO, și
     * toate paginile trec prin același loc.
     */
    utc(s) {
      const t = String(s == null ? '' : s).trim();
      if (!t) return 0;
      const iso = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(t) ? t.replace(' ', 'T') + 'Z'
        : /Z$|[+-]\d{2}:?\d{2}$/.test(t) ? t : t + 'Z';
      const ms = Date.parse(iso);
      return isFinite(ms) ? ms : 0;
    },

    /**
     * Aceeași cantitate, scrisă pentru ochi: „10”, nu „10.00”; „0,5”, nu „0.5”.
     * Zecimalele se arată doar când există, cu virgulă, cum se scrie la noi.
     *
     * Rotunjirea la două zecimale nu e cosmetică: totalurile se adună din
     * poziții, iar 0,1 + 0,2 dă în virgulă mobilă 0,30000000000000004. Pe
     * ecranul laboratorului ar fi apărut chiar așa. Coloana din bază ține
     * oricum două zecimale, deci nu se pierde nimic adevărat.
     */
    qtyLabel(v) {
      const n = Math.round(gp.qty(v) * 100) / 100;
      return Number.isInteger(n) ? String(n) : String(n).replace('.', ',');
    },
    /**
     * „o oprire", „3 opriri", „20 de opriri" — numărul scris românește.
     *
     * Regula lui „de" nu e o toană: peste douăzeci numeralul cere prepoziția,
     * iar de la o sută în sus se uită la ULTIMELE două cifre — „101 opriri",
     * dar „120 de opriri". Scrisă o dată, fiindcă ferestrele spre rută sunt
     * două, a clientului și a agentului, și ar fi început să se contrazică.
     */
    opriri(n) {
      const k = Math.max(0, Math.round(Number(n) || 0));
      if (k === 1) return 'o oprire';
      const r = k % 100;
      return (r === 0 || r >= 20) ? k + ' de opriri' : k + ' opriri';
    },

    /**
     * Rândul de sus al ferestrei spre rută: cât mai are șoferul și pe unde.
     *
     * Primește răspunsul lui `GET /routes/mine` întreg. Zonele vin numărate
     * de server, dar NU acoperă totdeauna toate opririle: o comandă poate fi
     * scrisă fără zonă, iar atunci suma de pe ecran ar fi ieșit mai mică
     * decât cifra de lângă ea, și omul ar fi crezut că una dintre ele minte.
     * Restul se numește pe față, „fără zonă", ca socoteala să se închidă.
     */
    rutaRezumat(rt) {
      if (!rt || typeof rt.remaining !== 'number') return '';
      if (rt.remaining === 0) return 'Toate livrările de azi sunt încheiate.';
      const z = (rt.zones || []).map((x) => x.zone + ' (' + x.remaining + ')');
      const numite = (rt.zones || []).reduce((a, x) => a + (Number(x.remaining) || 0), 0);
      const fara = rt.remaining - numite;
      if (fara > 0) z.push('fără zonă (' + fara + ')');
      return 'Șoferul mai are ' + gp.opriri(rt.remaining) + ' azi'
        + (z.length ? ' — ' + z.join(' · ') : '') + '.';
    },

    /**
     * Listele editabile din Setări, cu implicit gol.
     *
     * Panourile aveau aceleași valori scrise în cod — „Ridicare/Livrare/Angro",
     * intervalele, zonele. O listă schimbată din Setări nu ajungea în panou:
     * formularul oferea în continuare vechea valoare, iar serverul o refuza cu
     * 422. Acum panoul oferă exact ce acceptă serverul.
     */
    fulfillments: () => ((gp.__lists && gp.__lists.fulfillment) || []).slice(),
    /** Modurile la care clientul vine după marfă — tot din Setări. */
    pickupFulfillments: () => ((gp.__lists && gp.__lists.fulfillmentPickup) || []).slice(),
    slots: () => ((gp.__lists && gp.__lists.slots) || []).slice(),
    zones: () => ((gp.__lists && gp.__lists.zones) || []).slice(),

    /** Etapa care încheie comanda, așa cum e configurată în Setări. */
    done: () => gp.__L.done,
    /** Etapa care anulează comanda. */
    cancelled: () => gp.__L.cancelled,
    /** Etapele din laborator, în ordine — inclusiv cea de anulare. */
    statuses: () => gp.__L.statuses,

    /**
     * Etapele prin care trece efectiv o comandă, fără cea de anulare.
     *
     * Butonul „etapa următoare” din laborator merge pe lista asta. Cu lista
     * completă, o comandă ajunsă la ultima etapă de producție ar fi trecut mai
     * departe în „Anulată” — adică apăsarea butonului ar fi anulat comanda.
     */
    flow: () => gp.__L.statuses.filter((x) => x !== gp.__L.cancelled),
    /**
     * Mai are comanda asta de lucru? — regula după care se numără ziua.
     *
     * DE CE STĂ AICI, ȘI NU ÎN PAGINĂ. Serverul o avea deja scrisă, în
     * `route_lab_productie_imparte()` (`api/routes/lab.php`): împărțirea sare
     * peste comenzile anulate și peste cele ajunse la etapa finală, fiindcă o
     * comandă plecată la client n-are ce primi. Panoul „Total de făcut” le
     * număra însă pe TOATE comenzile zilei, fără nicio privire la stare. Două
     * socoteli pentru aceeași zi, și nu se potriveau:
     *
     *   · panoul cerea laboratorului să facă marfă pentru comenzi ANULATE.
     *     Măsurat pe 18 septembrie 2026, în baza de lucru: trei comenzi
     *     anulate, iar panoul cerea șase ștrudele pe care nu-i mai aștepta
     *     nimeni. Paguba nu e o cifră urâtă, e făină arsă degeaba;
     *   · iar caseta „+ câte” de lângă cifră n-o putea mișca NICIODATĂ. Ce
     *     tastai intra întreg în prisos, scris cu `order_id = NULL`, deci
     *     niciun `done_qty` nu se schimba și dunga rămânea la `0 / 15`. Omul
     *     care apasă de trei ori și nu se întâmplă nimic nu raportează un
     *     defect: se lasă de raportat producția, și atunci nu mai știe nimeni
     *     ce s-a făcut în tură.
     *
     * Gol în Setări înseamnă „nu există etapa asta”, la fel ca pe server
     * (`$anulata !== ''`): atunci nu se sare peste nimic.
     *
     * Păzită de `.claude/test-productie-imparte.mjs`, care o pune față în față
     * cu purtarea adevărată a serverului — stare cu stare, nu pe numele
     * fișierului.
     */
    intraLaProductie: (st) => {
      const anulata = gp.__L.cancelled;
      const finala  = gp.__L.done;
      return !((anulata && st === anulata) || (finala && st === finala));
    },

    /**
     * Până unde poate duce LABORATORUL o comandă: etapa dinaintea celei finale.
     *
     * Spusă de server (`status_lab_ceiling()`, api/lib/settings.php), fiindcă
     * acolo e și paza adevărată — `poarta_etapei_finale()`. Socotită aici din
     * nou, ar fi fost a doua copie a aceleiași reguli, iar cele două s-ar fi
     * despărțit la prima corectură.
     *
     * Socoteala de rezervă e pentru UN SINGUR caz: un server de dinaintea
     * rundei 2.3.0, care n-are câmpul. Atunci iese aceeași etapă ca pe server,
     * iar paginile merg mai departe. Fluxul cu o singură etapă — care se poate
     * scrie din Setări — dă chiar etapa aceea: nu există un „dinainte" al ei.
     */
    labCeiling: () => {
      if (gp.__L.labCeiling) return gp.__L.labCeiling;
      const f = gp.flow();
      if (!f.length) return '';
      const i = f.indexOf(gp.__L.done);
      return i < 0 ? f[f.length - 1] : f[Math.max(i - 1, 0)];
    },

    /**
     * Ordinea în care laboratorul lucrează ziua. Lista goală o șterge.
     *
     * Soră cu `setRouteOrder()` de mai jos, și trimisă la fel — lista ÎNTREAGĂ
     * a zilei, nu o mutare. Deosebirea e cine are voie: ruta șoferului o pune
     * biroul, ordinea din laborator o pune tura. Pricina, pe larg, lângă
     * `route_lab_ordine()`.
     */
    setLabOrder: (day, ids) => request('PUT', '/lab/ordine', { day, ids }),

    /**
     * „Tocmai am mai făcut N bucăți din produsul ăsta." Se împart pe comenzile
     * zilei, de sus în jos, pe ordinea de lucru; ce trece peste rămâne al casei.
     *
     * Întoarce, între altele, `lot` — lista exactă a pozițiilor atinse. Pe EA o
     * dă îndărăt `undoProduction()`, nu o scădere ghicită: între cele două
     * apăsări poate intra munca unui coleg.
     */
    splitProduction: (day, produs, qty) => request('POST', '/lab/productie/imparte', Object.assign(
      { day, qty },
      typeof produs === 'number' ? { productId: produs } : { name: String(produs) }
    )),

    /** Desface exact lotul întors de `splitProduction()`. */
    undoProduction: (day, produs, lot) => request('POST', '/lab/productie/desfa', Object.assign(
      { day, lot },
      typeof produs === 'number' ? { productId: produs } : { name: String(produs) }
    )),

    /** Ordinea opririlor pe ruta unei zile. Lista goală revine la implicit. */
    setRouteOrder: (day, ids) => request('PUT', '/routes', { day, ids }),

    /**
     * Scade din depozit comenzile încheiate care n-au fost încă scăzute.
     *
     * Decizia „ce s-a scăzut deja” aparține serverului. Ținută în pagină, se
     * pierdea la reîncărcare și aceleași produse se scădeau de mai multe ori.
     */
    syncStock: () => request('POST', '/stock/sync', {}),

    /* --- zile --- */

    /**
     * Fereastra de zile a panourilor: azi și următoarele n-1 zile.
     *
     * Se calculează la fiecare încărcare, nu se scrie în cod. Prototipul avea
     * zilele fixe („Mie 26 aug”), ceea ce ar fi însemnat că panourile rămân
     * blocate în trecut la câteva zile după publicare, iar filtrul de zi nu ar
     * mai fi găsit nicio comandă.
     *
     * Fiecare zi are:
     *   v     ziua din lună, „29” — cheia folosită de panouri
     *   iso   data completă, „2026-08-29” — forma cerută de server
     *   dow   „Sâm”
     *   short „Sâm 29”
     *   label „Sâm 29 aug”
     *   long  „Sâmbătă 29 august”
     */
    /**
     * Aceeași clipă, citită după ceasul patiseriei.
     *
     * Întoarce un `Date` NOU ale cărui câmpuri LOCALE (`getFullYear`,
     * `getMonth`, `getDate`, `getHours`, `getMinutes`) arată ora de perete din
     * București pentru clipa dată, oricare ar fi fusul aparatului. Serverul
     * socotește așa de la început (`today_local()`, `api/lib/http.php:311`, cu
     * fusul scris în cod ca să nu atârne de setarea găzduirii); ăsta e același
     * lucru, pentru pagini.
     *
     * SE FOLOSEȘTE NUMAI PENTRU CITIT ziua și ora. Rezultatul nu mai e clipa
     * adevărată în milisecunde, deci nu se scade din alt `Date` și nu se
     * trimite la server ca marcaj de timp — se trimite ziua scoasă din el.
     *
     * Pe un dispozitiv fără datele de fusuri, `Intl` aruncă; atunci rămâne
     * ceasul aparatului, adică purtarea de dinainte. Mai bine ziua lui decât
     * niciun răspuns.
     */
    laPatiserie(cand) {
      const d = new Date(cand.getTime());
      if (isNaN(d.getTime())) return d;
      try {
        const p = {};
        const fmt = new Intl.DateTimeFormat('en-CA', {
          timeZone: 'Europe/Bucharest',
          year: 'numeric', month: '2-digit', day: '2-digit',
          hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
        });
        for (const x of fmt.formatToParts(d)) p[x.type] = x.value;
        if (p.year && p.month && p.day) {
          // Ora se pune ÎNTÂI: `setHours` poate revărsa în ziua următoare (ora
          // 25 e mâine), iar dacă ziua se așază după el revărsarea e ștearsă.
          // Invers ar fi un defect de o zi, o dată la câteva luni.
          //
          // `% 24` fiindcă `hour12: false` dă `24` la miezul nopții în unele
          // versiuni de ICU — amănuntul care trece toate probele scrise la
          // ora trei după-amiaza și cade o singură dată pe noapte.
          d.setHours(Number(p.hour) % 24, Number(p.minute), Number(p.second), 0);
          d.setFullYear(Number(p.year), Number(p.month) - 1, Number(p.day));
        }
      } catch (e) { /* dispozitiv fără date de fus: rămâne ceasul lui */ }
      return d;
    },

    /** Clipa de acum, citită după ceasul patiseriei. V. `gp.laPatiserie`. */
    acumLaPatiserie() {
      return gp.laPatiserie(new Date());
    },

    days(n = 7, deLa = '') {
      const DOW = ['Dum', 'Lun', 'Mar', 'Mie', 'Joi', 'Vin', 'Sâm'];
      const DOWL = ['duminică', 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă'];
      const MON = ['ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'noi', 'dec'];
      const MONL = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie',
        'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
      const p2 = (x) => (x < 10 ? '0' : '') + x;

      // Ziua PATISERIEI, nu ziua aparatului care întreabă.
      //
      // Aici era doar `new Date()`, adică ceasul dispozitivului. De acolo au
      // curs cinci defecte deodată; cel mai scump: tableta lăsată pe UTC naște
      // comanda scrisă la 01:00 pe ziua de IERI, iar `?from=azi` o taie din
      // toate listele. Marfa nu se coace, și nimic pe niciun ecran nu spune de
      // ce, fiindcă serverul răspunde 200 — marginea de jos lipsește dinadins
      // (`valid_day_not_too_far()`), ca administratorul să poată consemna o
      // livrare de ieri.
      //
      // Se schimbă NUMAI baza. Restul funcției merge pe câmpurile de ceas
      // local ale lui `Date`, deci mersul zilelor și etichetele rămân cum
      // erau; schimbarea urcă singură în `gp.today()` și în toate paginile
      // care cheamă `gp.days()`, fără să atingă niciuna.
      //
      // Ziua NU se ia din `gp.__lists.orderWindow.first`, deși serverul o
      // trimite: `gp.lists()` reține răspunsul o singură dată, deci ziua ar
      // ÎNGHEȚA — și ar rupe exact reparațiile de trecere în ziua nouă
      // (`refreshDays`, `treziZiua`), care au nevoie de un ceas care merge.
      //
      // `deLa` MUTĂ BAZA pe o zi aleasă de om, și e singurul fel în care
      // fereastra se poate uita înapoi: socoteala de mai jos merge numai
      // înainte (`+ i`), deci fără ea trecutul nu există pentru nicio pagină
      // care cheamă funcția asta. Gol înseamnă „de azi", adică purtarea
      // dintotdeauna — `gp.today()` cheamă `days(1)` fără el, deci ceasul
      // rămâne un ceas care merge, iar reparațiile de trecere în ziua nouă
      // (`refreshDays`, `treziZiua`) au mai departe pe ce se sprijini.
      //
      // Ziua se desface pe componente, nu prin `new Date(iso)`: acela o
      // citește ca miez de noapte UTC, deci la est de Greenwich cade pe ziua
      // dinainte — fereastra ar porni cu o zi mai devreme decât a apăsat omul,
      // iar el ar vedea ruta de alaltăieri sub eticheta de ieri. Aceeași
      // grijă e scrisă și în `dinIso` din Panou Laborator, din același motiv.
      //
      // Ce nu se potrivește tiparului cade înapoi pe ceasul patiseriei, tăcut
      // și dinadins: o zi stricată în adresă nu e un motiv să rămână panoul
      // gol, iar ziua bună se vede scrisă pe ecran.
      const aleasa = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(deLa || ''));
      const base = aleasa
        ? new Date(Number(aleasa[1]), Number(aleasa[2]) - 1, Number(aleasa[3]))
        : gp.acumLaPatiserie();
      base.setHours(0, 0, 0, 0);
      const out = [];
      for (let i = 0; i < n; i++) {
        const d = new Date(base);
        d.setDate(base.getDate() + i);
        const dd = d.getDate();
        const mm = d.getMonth();
        out.push({
          v: p2(dd),
          iso: d.getFullYear() + '-' + p2(mm + 1) + '-' + p2(dd),
          dow: DOW[d.getDay()],
          short: DOW[d.getDay()] + ' ' + dd,
          label: DOW[d.getDay()] + ' ' + dd + ' ' + MON[mm],
          long: DOWL[d.getDay()] + ' ' + dd + ' ' + MONL[mm],
        });
      }
      return out;
    },

    /** Data de azi, în forma cerută de server. */
    today() {
      return gp.days(1)[0].iso;
    },

    /* --- reîmprospătare periodică --- */

    /**
     * Reia `fn` la fiecare `ms` milisecunde. Se oprește când fila este
     * ascunsă și reia imediat la revenire, ca un panou lăsat deschis peste
     * noapte să nu interogheze serverul degeaba.
     *
     * Întoarce o funcție care oprește reîmprospătarea.
     */
    poll(fn, ms = 12000) {
      let timer = null;
      let stopped = false;
      let inFlight = false;

      const tick = async () => {
        if (stopped || global.document.hidden) return;
        // O tură care încă nu s-a întors nu lasă alta să plece. Fără asta, o
        // legătură proastă strângea cereri una peste alta, iar răspunsurile
        // care se întorceau în altă ordine puteau pune pe ecran o listă mai
        // veche decât cea deja afișată.
        if (inFlight) return;
        inFlight = true;
        try {
          await fn();
        } catch (e) {
          // O eroare de rețea trecătoare nu trebuie să oprească panoul.
          if (!(e instanceof GpError) || e.status !== 401) {
            console.warn('[gp] reîmprospătare eșuată:', e.message);
          }
        } finally {
          inFlight = false;
        }
      };

      const start = () => {
        if (timer) clearInterval(timer);
        timer = setInterval(tick, ms);
      };

      const onVisible = () => {
        if (!global.document.hidden) {
          tick();
          start();
        }
      };

      global.document.addEventListener('visibilitychange', onVisible);
      start();
      tick();

      return () => {
        stopped = true;
        if (timer) clearInterval(timer);
        global.document.removeEventListener('visibilitychange', onVisible);
      };
    },
  };

  /* ------------------------------------- pastila cu versiunea, în pagini -- */

  /**
   * `<gp-versiune></gp-versiune>` — scrie `ver. 2.3.0 · 19 sep 16:48`.
   *
   * DE CE UN ELEMENT, și nu o cheie în obiectul de desenare al fiecărei pagini.
   * Cheia ar fi însemnat 21 de rânduri scrise de mână în 21 de fișiere, și
   * fiecare putea lipsi în TĂCERE: pagina care o uita n-ar fi arătat nimic, iar
   * paginile uitate sunt tocmai cele pe care patronul ar vrea să le întrebe ce
   * cod poartă. Așa, marcajul din pagini e un rând fără niciun cuvânt în el,
   * același peste tot, iar scrisul vine din fișierul ăsta.
   *
   * React nu i se bagă înăuntru: gazda ține NODUL, nu măruntaiele lui — la fel
   * cum ține `<image-slot>` din magazin.
   *
   * PAZA DE LA ÎNCEPUT NU-I DE FALĂ. Probele casei rulează logica paginilor
   * într-o lume de mucava, cu o `location` făcută din trei câmpuri
   * (`.claude/meniu-real.mjs`, `.claude/check-panouri.mjs`); acolo nu există
   * nici `customElements`, nici `HTMLElement`, iar o chemare neîngrădită ar fi
   * aruncat la ÎNCĂRCAREA fișierului, adică în toate suitele deodată.
   */
  if (typeof customElements !== 'undefined' && typeof HTMLElement !== 'undefined'
    && !customElements.get('gp-versiune')) {
    customElements.define('gp-versiune', class extends HTMLElement {
      connectedCallback() {
        this.textContent = gp.versiune();
      }
    });
  }

  global.gp = gp;
})(window);
