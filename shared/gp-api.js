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

    const { hostname, protocol, port } = global.location;

    // Dezvoltare locală: Laragon (gp.test) sau un server static pe localhost.
    if (hostname === 'localhost' || hostname === '127.0.0.1') {
      return `${protocol}//${hostname}${port ? ':' + port : ''}/api`;
    }
    if (hostname.endsWith('.test')) {
      return `${protocol}//api.gp.test`;
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
    constructor(message, status, code) {
      super(message);
      this.name = 'GpError';
      this.status = status;
      this.code = code;
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
        err.code || String(res.status)
      );
    }

    return data;
  }

  /* --------------------------------------------------------------- API -- */

  const gp = {
    base: BASE,
    GpError,

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
     * @param {{username:string,name:string,password:string,email?:string,
     *          phone?:string,customerName?:string,ref?:string}} date
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
      return user;
    },

    /** Câte zile ține sesiunea, așa cum spune serverul. 0 până la primul me(). */
    sessionDays: () => sessionDays,

    changePassword: (currentPassword, newPassword) =>
      request('POST', '/auth/password', { currentPassword, newPassword }),

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
        stockSynced: !!o.stockSynced,
        variant: o.variant || 'copt',
        phone: o.phone || '',
        total: o.total,
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
        at: gp.utc(r.createdAt),
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
    },

    async lists() {
      if (!gp.__lists) {
        gp.__lists = await request('GET', '/lists');
        if (gp.__lists) {
          if (gp.__lists.statuses && gp.__lists.statuses.length) gp.__L.statuses = gp.__lists.statuses;
          if (gp.__lists.done) gp.__L.done = gp.__lists.done;
          if (gp.__lists.cancelled) gp.__L.cancelled = gp.__lists.cancelled;
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
     * Telefonul și e-mailul publice, din Setări.
     *
     * Erau scrise în pagina magazinului, în cinci locuri. Patiseria își
     * schimba numărul din panou, iar clientul suna tot la cel vechi — și tot
     * acolo ducea și butonul de WhatsApp. Valorile de mai jos țin numai până
     * răspunde serverul; adevărul este cel scris în Setări.
     */
    contact: () => {
      const c = (gp.__lists && gp.__lists.contact) || {};
      return {
        phone: c.phone || '0769 493 163',
        email: c.email || 'contact@goldenpastry.ro',
      };
    },

    /**
     * Punctul de pe hartă al unei zone de livrare, din Setări.
     *
     * O zonă fără coordonate cade pe depozit: distanța iese 0, ceea ce e
     * vizibil greșit pe rută — mai bine decât să pară corectă și să nu fie.
     */
    zonePoint(zone) {
      const map = (gp.__lists && gp.__lists.zoneCoords) || {};
      return map[zone] || gp.hq();
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
    days(n = 7) {
      const DOW = ['Dum', 'Lun', 'Mar', 'Mie', 'Joi', 'Vin', 'Sâm'];
      const DOWL = ['duminică', 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă'];
      const MON = ['ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'noi', 'dec'];
      const MONL = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie',
        'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
      const p2 = (x) => (x < 10 ? '0' : '') + x;

      const base = new Date();
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

  global.gp = gp;
})(window);
