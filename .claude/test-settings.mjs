// Teste pentru panoul de Setări și pentru ce depinde de el:
// listele configurabile, etapele cu rol special și ordinea opririi pe rută.
//
//   php -S 127.0.0.1:8100 -t . .claude/dev-server.php
//   node .claude/test-settings.mjs
//
// Miza: utilizatorul poate schimba etapele din interfață. Dacă face asta,
// aplicația nu are voie nici să piardă comenzi, nici să le anuleze din
// greșeală — de aceea aici se testează mai ales cazurile în care listele se
// schimbă sub picioarele aplicației.

const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';

let pass = 0, fail = 0;
const failures = [];

function check(name, cond, extra = '') {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { fail++; failures.push(name + (extra ? ` — ${extra}` : '')); console.log(`  FAIL  ${name}${extra ? ' — ' + extra : ''}`); }
}

function agent() {
  return { cookies: new Map(), csrf: '' };
}

async function call(a, method, path, body) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (a.csrf && method !== 'GET') headers['X-CSRF-Token'] = a.csrf;
  if (a.cookies.size) headers['Cookie'] = [...a.cookies].map(([k, v]) => `${k}=${v}`).join('; ');

  const res = await fetch(BASE + path, {
    method, headers,
    body: body === undefined ? undefined : JSON.stringify(body),
    redirect: 'manual',
  });

  for (const raw of res.headers.getSetCookie?.() ?? []) {
    const [pair] = raw.split(';');
    const i = pair.indexOf('=');
    const k = pair.slice(0, i).trim(), v = pair.slice(i + 1).trim();
    if (v === '' || /expires=Thu, 01 Jan 1970/i.test(raw)) a.cookies.delete(k);
    else a.cookies.set(k, v);
  }

  const raw = await res.text();
  let data = null;
  if (raw !== '') {
    try {
      data = JSON.parse(raw);
    } catch {
      // Nu e JSON valid. Dacă începe cu ceva tipărit de PHP, spunem asta pe
      // față: e o eroare de server, nu un test scris greșit.
      const head = raw.slice(0, 200).replace(/\s+/g, ' ');
      throw new Error('răspuns care nu este JSON la ' + method + ' ' + path + ': ' + head);
    }
  }
  return { status: res.status, data };
}

const get = (a, p) => call(a, 'GET', p);
const post = (a, p, b) => call(a, 'POST', p, b ?? {});
const patch = (a, p, b) => call(a, 'PATCH', p, b ?? {});
const put = (a, p, b) => call(a, 'PUT', p, b ?? {});

async function login(a, u, p) {
  const r = await post(a, '/auth/login', { username: u, password: p });
  if (r.status !== 200) throw new Error(`autentificare eșuată pentru ${u}: ${r.status} ${JSON.stringify(r.data)}`);
  a.csrf = r.data.csrf;
  return r.data.user;
}

const ADMIN = { u: 'testadmin', p: 'Parola-Test-1234' };
const STAFF = { u: 'testangajat', p: 'Parola-Test-5678' };

// Numele etapei de probă se schimbă la fiecare rulare. Cu un nume fix, o
// rulare întreruptă la mijloc — sau o etapă adăugată manual din panou — lăsa
// numele în listă, iar rularea următoare pica fără să fie nimic stricat.
const NEW_STAGE = 'Etapă de probă ' + Math.random().toString(36).slice(2, 8);

// Zona folosită de teste vine din lista configurată, nu din cod.
const ZONE = ((await (await fetch(BASE + '/lists')).json()).zones || [])[0] || '';

const admin = agent();
const staff = agent();
await login(admin, ADMIN.u, ADMIN.p);
await login(staff, STAFF.u, STAFF.p);

// Setările de la care pornim; le punem la loc la final, orice s-ar întâmpla.
const before = (await get(admin, '/settings')).data.settings;
const restore = async () => {
  await put(admin, '/settings', {
    settings: {
      order_statuses: before.order_statuses,
      order_status_done: before.order_status_done,
      order_status_cancelled: before.order_status_cancelled,
      delivery_zones: before.delivery_zones,
      delivery_zone_coords: before.delivery_zone_coords,
      fulfillment_types: before.fulfillment_types,
      fulfillment_pickup: before.fulfillment_pickup,
    },
  });
};

try {

  /* ------------------------------------------------------------ drepturi -- */
  console.log('\n=== drepturi ===');
  {
    const r = await get(staff, '/settings');
    check('un angajat nu vede setările → 403', r.status === 403, `a dat ${r.status}`);
  }
  {
    const r = await put(staff, '/settings', { settings: { company_name: 'Furat' } });
    check('un angajat nu poate salva setări → 403', r.status === 403, `a dat ${r.status}`);
  }
  {
    const r = await post(staff, '/settings/test/email');
    check('un angajat nu poate trimite teste → 403', r.status === 403, `a dat ${r.status}`);
  }

  /* ------------------------------------------------------- etape speciale -- */
  console.log('\n=== etapele cu rol special ===');
  {
    const r = await get(admin, '/lists');
    check('/lists spune care etapă încheie comanda', typeof r.data.done === 'string' && r.data.done.length > 0, JSON.stringify(r.data.done));
    check('/lists spune care etapă o anulează', typeof r.data.cancelled === 'string' && r.data.cancelled.length > 0, JSON.stringify(r.data.cancelled));
    check('etapa „gata” face parte din listă', r.data.statuses.includes(r.data.done));
    check('etapa „anulată” face parte din listă', r.data.statuses.includes(r.data.cancelled));
    check('etapa „gata” nu este cea de anulare', r.data.done !== r.data.cancelled,
      `ambele sunt „${r.data.done}” — butonul „gata” din laborator ar anula comanda`);
  }
  {
    const r = await put(admin, '/settings', { settings: { order_status_done: 'Etapă inexistentă' } });
    check('o etapă „gata” din afara listei e respinsă → 422', r.status === 422, `a dat ${r.status}`);
  }

  /* ------------------------------------------------ liste: reguli de bază -- */
  console.log('\n=== listele configurabile ===');
  {
    const r = await put(admin, '/settings', { settings: { delivery_zones: '[]' } });
    check('o listă nu poate rămâne goală → 422', r.status === 422, `a dat ${r.status}`);
  }
  {
    const r = await put(admin, '/settings', { settings: { delivery_zones: 'nu e json' } });
    check('o listă stricată e respinsă → 422', r.status === 422, `a dat ${r.status}`);
  }
  {
    const zones = JSON.parse(before.delivery_zones);
    const r = await put(admin, '/settings', { settings: { delivery_zones: JSON.stringify(zones.concat(['Băneasa'])) } });
    check('o zonă nouă se salvează', r.status === 200, `a dat ${r.status}`);

    const l = await get(admin, '/lists');
    check('zona nouă apare imediat în /lists', l.data.zones.includes('Băneasa'));
  }
  {
    // O listă poate ajunge la server și ca tablou JSON, nu numai ca șir:
    // validările rutei o acceptă explicit în forma asta, deci o cerere scrisă
    // de mână — curl, Postman, un script de import — trece de ele. Salvarea
    // făcea însă `(string) $tablou`, ceea ce în PHP dă literal „Array”:
    // răspunsul spunea „ok”, cheia apărea în „changed”, dar valoarea nu se mai
    // putea decoda la citire și lista sărea înapoi pe intervalele scrise în
    // cod. Intervalele configurate dispăreau fără niciun mesaj, iar clientul
    // vedea în formular alte ore decât cele la care se coace.
    const noi = ['05:15', '07:45', '19:30'];
    const r = await put(admin, '/settings', { settings: { delivery_slots: noi } });
    check('o listă trimisă ca tablou JSON se salvează → 200', r.status === 200, `a dat ${r.status}`);
    check('lista trimisă ca tablou nu ajunge în baza de date ca textul „Array”',
      r.data?.settings?.delivery_slots !== 'Array', JSON.stringify(r.data?.settings?.delivery_slots));

    const l2 = await get(admin, '/lists');
    check('intervalele trimise ca tablou ajung în /lists',
      JSON.stringify(l2.data.slots) === JSON.stringify(noi), JSON.stringify(l2.data.slots));

    // Le punem la loc imediat: comenzile create mai jos au nevoie de
    // intervalul lor, iar `restore()` de la final nu atinge cheia asta.
    const inapoi = await put(admin, '/settings', { settings: { delivery_slots: before.delivery_slots } });
    check('intervalele de dinainte se pun la loc', inapoi.status === 200, `a dat ${inapoi.status}`);
  }
  {
    // Sora defectului de mai sus, pe cealaltă cale: tabloul e bun, dar un
    // element din el nu e text. `validate_lists()` și `setting_list()` îl
    // treceau prin `trim((string) $v)`, iar în PHP `(string) $tablou` dă
    // literal „Array”, cu avertismentul doar în log. Lista trecea de validare
    // (nu era goală), se salva așa cum venise, iar în panou și în formularul
    // clientului apărea un interval scris „Array”: laboratorul primea comenzi
    // pe o oră care nu există.
    const bune = (await get(admin, '/lists')).data.slots;

    const imbricat = await put(admin, '/settings', {
      settings: { delivery_slots: ['05:15', ['07:45']] },
    });
    check('o listă cu o valoare imbricată e respinsă → 422',
      imbricat.status === 422, `a dat ${imbricat.status}`);
    check('refuzul spune care listă e de vină',
      String(imbricat.data?.error?.message || '').includes('delivery_slots'),
      JSON.stringify(imbricat.data?.error));

    const obiect = await put(admin, '/settings', {
      settings: { delivery_slots: ['05:15', { ora: '07:45' }] },
    });
    check('și un obiect strecurat în listă e respins → 422',
      obiect.status === 422, `a dat ${obiect.status}`);

    const dupa = (await get(admin, '/lists')).data.slots;
    check('niciun interval nu s-a făcut „Array”',
      !dupa.includes('Array'), JSON.stringify(dupa));
    check('intervalele de dinainte au rămas neatinse',
      JSON.stringify(dupa) === JSON.stringify(bune), JSON.stringify(dupa));

    // Le scriem la loc chiar dacă n-ar fi trebuit atinse: proba asta se
    // rulează și cu reparația dată înapoi, iar atunci valoarea imbricată
    // chiar ajunge în baza de date. Lăsată acolo, ar fi refuzată la fiecare
    // rulare următoare — de reparația însăși — și restul suitei ar cădea pe
    // „Interval de livrare necunoscut”, adică proba și-ar strica vecinele.
    const curat = await put(admin, '/settings', { settings: { delivery_slots: before.delivery_slots } });
    check('intervalele bune se pun la loc și după încercările respinse',
      curat.status === 200, `a dat ${curat.status}`);
  }


  /* --------------------------------- coordonatele zonelor de livrare ------ */
  console.log('\n=== coordonatele zonelor ===');
  {
    // Latitudinea și longitudinea se scriu de mână, în casete de text. O casetă
    // golită era citită de server ca 0, iar 0/0 este un punct din Golful
    // Guineei: piciorul de rută spre zona aceea ieșea de mii de kilometri, iar
    // „Optimizează ruta” rearanja opririle față de el, fără niciun mesaj.
    const coords = JSON.parse(before.delivery_zone_coords || '{}');
    const zona = (ZONE && coords[ZONE]) ? ZONE : (Object.keys(coords)[0] || ZONE || 'Sector 1');
    const punct = coords[zona] || { lat: 44.418, lng: 26.15 };
    const cuPunct = (p) => ({
      settings: { delivery_zone_coords: JSON.stringify(Object.assign({}, coords, { [zona]: p })) },
    });

    const goala = await put(admin, '/settings', cuPunct({ lat: '', lng: String(punct.lng) }));
    check('o coordonată lăsată goală este respinsă → 422', goala.status === 422, `a dat ${goala.status}`);
    check('mesajul spune despre ce zonă e vorba',
      String(goala.data?.error?.message || '').includes(zona), goala.data?.error?.message);

    const zero = await put(admin, '/settings', cuPunct({ lat: 0, lng: 0 }));
    check('punctul 0,0 este respins → 422', zero.status === 422, `a dat ${zero.status}`);

    const litere = await put(admin, '/settings', cuPunct({ lat: 'nord', lng: String(punct.lng) }));
    check('o coordonată scrisă cu litere este respinsă → 422', litere.status === 422, `a dat ${litere.status}`);

    const afara = await put(admin, '/settings', cuPunct({ lat: 191, lng: punct.lng }));
    check('o latitudine în afara hărții este respinsă → 422', afara.status === 422, `a dat ${afara.status}`);

    const stricat = await put(admin, '/settings', { settings: { delivery_zone_coords: 'nu e json' } });
    check('o hartă a zonelor stricată este respinsă → 422', stricat.status === 422, `a dat ${stricat.status}`);

    const neatins = (await get(admin, '/lists')).data.zoneCoords[zona] || {};
    check('salvările respinse n-au schimbat coordonatele bune',
      !coords[zona] || (Number(neatins.lat) === Number(punct.lat) && Number(neatins.lng) === Number(punct.lng)),
      JSON.stringify(neatins));

    // Tastatura românească dă virgulă zecimală, iar caseta din Setări nu o
    // refuză: „44,4795” citit ca 44 mută zona cu vreo 50 de kilometri.
    const virgula = await put(admin, '/settings', cuPunct({ lat: '44,4795', lng: '26,0563' }));
    check('o coordonată cu virgulă zecimală se salvează', virgula.status === 200, `a dat ${virgula.status}`);

    const dupa = (await get(admin, '/lists')).data.zoneCoords[zona] || {};
    check('virgula zecimală nu taie partea de după virgulă',
      Math.abs(Number(dupa.lat) - 44.4795) < 1e-9 && Math.abs(Number(dupa.lng) - 26.0563) < 1e-9,
      JSON.stringify(dupa));

    // …și nu rămâne text cu virgulă în baza de date. Citirea îl înțelege, deci
    // ruta ieșea corect oricum; dar în rândul din `settings` stăteau două
    // feluri de valori pentru același lucru, iar orice se uită direct la ele
    // (un export, o interogare de mână, chiar suita asta la a doua rulare)
    // vedea ba număr, ba șir. Se curăță la salvare, o dată.
    const brut = (await get(admin, '/settings')).data.settings.delivery_zone_coords;
    const scris = JSON.parse(typeof brut === 'string' ? brut : JSON.stringify(brut))[zona] || {};
    check('coordonata se salvează ca număr, nu ca text cu virgulă',
      typeof scris.lat === 'number' && typeof scris.lng === 'number',
      JSON.stringify(scris));

    // Plasa pentru zonele salvate cu 0,0 înainte de verificare: nu blochează
    // nimic, dar nici nu ajung pe hartă — oprirea cade pe depozit, cu zero
    // kilometri, în loc să pară o distanță adevărată până în Golful Guineei.
    const toate = (await get(admin, '/lists')).data.zoneCoords || {};
    check('nicio zonă nu ajunge pe ecuator sau pe meridianul zero',
      Object.keys(toate).every((z) => Number(toate[z].lat) !== 0 && Number(toate[z].lng) !== 0),
      JSON.stringify(toate));
  }

  /* ------------------------------- etapa în uz nu poate fi ștearsă -------- */
  console.log('\n=== protecția etapelor în uz ===');
  const day = new Date().toISOString().slice(0, 10);
  let orderId = null;
  {
    const statuses = JSON.parse(before.order_statuses);
    const r = await post(admin, '/orders', {
      customerName: 'Test etape', day, fulfillment: 'Livrare', zone: ZONE,
      slot: '08:00', items: [{ productId: 1, qty: 2 }],
    });
    check('comandă de test creată', r.status === 200 || r.status === 201, `a dat ${r.status} ${JSON.stringify(r.data)}`);
    orderId = r.data?.order?.id ?? null;

    // Etapa în care se află comanda: prima din listă.
    const inUse = r.data?.order?.status || statuses[0];
    const without = statuses.filter((s) => s !== inUse);
    const bad = await put(admin, '/settings', { settings: { order_statuses: JSON.stringify(without) } });
    check('o etapă cu comenzi în ea nu poate fi ștearsă → 409', bad.status === 409, `a dat ${bad.status}`);
    const m = bad.data?.error?.message || '';
    check('mesajul spune care etapă și câte comenzi',
      m.includes(inUse) && m.includes('comen') && /[0-9]/.test(m), m);
  }
  {
    const statuses = JSON.parse(before.order_statuses);
    const r = await put(admin, '/settings', {
      settings: { order_statuses: JSON.stringify(statuses.concat([NEW_STAGE])) },
    });
    check('o etapă nouă se poate adăuga', r.status === 200, `a dat ${r.status}`);

    const l = await get(admin, '/lists');
    check('etapa nouă apare în /lists', l.data.statuses.includes(NEW_STAGE));

    const use = await patch(admin, '/orders/' + orderId, { status: NEW_STAGE });
    check('o comandă poate fi mutată în etapa nouă', use.status === 200, `a dat ${use.status}`);

    // acum etapa nouă e în uz — nu mai poate fi ștearsă
    const bad = await put(admin, '/settings', { settings: { order_statuses: before.order_statuses } });
    check('etapa nouă, odată folosită, e la rândul ei protejată → 409', bad.status === 409, `a dat ${bad.status}`);

    // o mutăm înapoi și abia apoi curățăm lista
    await patch(admin, '/orders/' + orderId, { status: JSON.parse(before.order_statuses)[0] });
    const ok = await put(admin, '/settings', { settings: { order_statuses: before.order_statuses } });
    check('după mutarea comenzii, etapa se poate șterge', ok.status === 200, `a dat ${ok.status}`);
  }

  /* ------------------------------------------------- ordinea pe rută ------ */
  console.log('\n=== ordinea opririlor ===');
  {
    // Două livrări în aceeași zi, ca să existe ce reordona.
    const mk = (name, slot) => post(admin, '/orders', {
      customerName: name, day, fulfillment: 'Livrare', zone: ZONE, slot,
      items: [{ productId: 1, qty: 1 }],
    });
    const a = await mk('Oprirea A', '08:00');
    const b = await mk('Oprirea B', '06:30');
    const ida = a.data?.order?.id, idb = b.data?.order?.id;

    // Ruta e despre livrări; celelalte comenzi ale zilei sunt filtrate de pagini.
    const deliveries = (r) => (r.data.stops || []).filter((s) => s.fulfillment === 'Livrare');

    const start = deliveries(await get(admin, '/routes?day=' + day)).map((s) => s.id);
    check('cele două opriri noi sunt pe rută',
      start.includes(ida) && start.includes(idb), JSON.stringify(start));
    check('fără ordine manuală, B (06:30) vine înaintea lui A (08:00)',
      start.indexOf(idb) < start.indexOf(ida), JSON.stringify(start));

    const r = await put(admin, '/routes', { day, ids: [ida, idb] });
    check('ordinea se salvează → 200', r.status === 200, `a dat ${r.status}`);

    const ids = deliveries(await get(admin, '/routes?day=' + day)).map((s) => s.id);
    check('ordinea cerută răstoarnă ordinea după interval',
      ids.indexOf(ida) < ids.indexOf(idb), JSON.stringify(ids));
    check('opririle reordonate trec înaintea celorlalte',
      ids[0] === ida && ids[1] === idb, JSON.stringify(ids.slice(0, 3)));

    // Miezul problemei: șoferul are altă sesiune, pe alt dispozitiv.
    const dids = deliveries(await get(staff, '/routes?day=' + day)).map((s) => s.id);
    check('șoferul, din altă sesiune, vede aceeași ordine',
      dids.indexOf(ida) < dids.indexOf(idb), JSON.stringify(dids.slice(0, 3)));

    const cleared = await put(admin, '/routes', { day, ids: [] });
    check('ordinea manuală se poate șterge', cleared.status === 200, `a dat ${cleared.status}`);
    const back = deliveries(await get(admin, '/routes?day=' + day)).map((s) => s.id);
    check('după ștergere se revine la ordinea intervalelor',
      back.indexOf(idb) < back.indexOf(ida), JSON.stringify(back));

    // O comandă anulată nu are ce căuta pe rută.
    const cancelName = (await get(admin, '/lists')).data.cancelled;
    await patch(admin, '/orders/' + ida, { status: cancelName });
    const noCancel = await get(admin, '/routes?day=' + day);
    check('comanda anulată dispare de pe ruta șoferului',
      !noCancel.data.stops.some((s) => s.id === ida),
      JSON.stringify(noCancel.data.stops.map((s) => s.id).slice(0, 5)));
  }
  {
    // Ordinea opririlor o pune administratorul, nu tura. Proba de aici cerea
    // înainte contrariul — „e treaba lui” — dar nicio pagină nu-i dă
    // angajatului butonul: singurul loc din care se cheamă PUT /routes este
    // „Administrare Comenzi”, care se închide pentru orice alt rol. Ușa
    // rămânea deschisă doar din consolă, iar șoferul putea pleca pe alt
    // traseu decât cel stabilit în birou.
    const r = await put(staff, '/routes', { day, ids: [] });
    check('un angajat nu poate reordona ruta → 403', r.status === 403, `a dat ${r.status}`);

    const a = await put(admin, '/routes', { day, ids: [] });
    check('administratorul poate reordona ruta → 200', a.status === 200, `a dat ${a.status}`);
  }

  /* ------------------------ ce ajunge pe ruta șoferului ------------------- */
  console.log('\n=== modurile de onorare care ajung pe rută ===');
  {
    // Modurile de onorare se editează din Setări. Ruta nu are voie să aleagă
    // opririle după un text scris în cod: serverul decide, după lista
    // „moduri fără mașină”.
    const tipuri = JSON.parse(before.fulfillment_types);
    // Numele trebuie să fie recognoscibil pentru `curata-probe`: cât timp se
    // chema „Curier fx2ep", comenzile de probă rămâneau în baza de dezvoltare
    // la fiecare rulare — două sute de rânduri — iar în panou apărea un mod de
    // onorare cu coadă aleatorie pe care nimeni nu-l putea explica.
    const MOD = 'Probă Onorare ' + Math.random().toString(36).slice(2, 7);

    try {
      const s = await put(admin, '/settings', {
        settings: { fulfillment_types: JSON.stringify(tipuri.concat([MOD])) },
      });
      check('un mod de onorare nou se salvează', s.status === 200, `a dat ${s.status}`);

      const mk = await post(admin, '/orders', {
        customerName: 'Probă Onorare Livrare', day, zone: ZONE, slot: '08:00',
        fulfillment: MOD, items: [{ productId: 1, qty: 1 }],
      });
      check('comandă pe modul nou', mk.status === 201 || mk.status === 200,
        `a dat ${mk.status} ${JSON.stringify(mk.data?.error)}`);
      const idNou = mk.data?.order?.id;

      const rid = await post(admin, '/orders', {
        customerName: 'Probă Onorare Ridicare', day, slot: '08:00',
        fulfillment: 'Ridicare', items: [{ productId: 1, qty: 1 }],
      });
      const idRidicare = rid.data?.order?.id;

      const r = await get(admin, '/routes?day=' + day);
      const peRuta = (r.data.stops || []).map((x) => x.id);
      check('un mod de onorare adăugat din Setări ajunge pe rută',
        peRuta.includes(idNou), JSON.stringify(peRuta));
      check('ce își ia clientul singur nu ajunge pe rută',
        !peRuta.includes(idRidicare), JSON.stringify(peRuta));


      // Numărul pe mai multe zile deodată: fără el, meniul de zile al șoferului
      // scria „0 opriri” pentru orice zi în afară de cea deschisă.
      const c = await get(admin, '/routes/counts?from=' + day + '&to=' + day);
      check('/routes/counts răspunde → 200', c.status === 200, `a dat ${c.status}`);
      check('numărul zilei se potrivește cu opririle ei',
        (c.data?.counts || {})[day] === peRuta.length,
        JSON.stringify(c.data?.counts) + ' față de ' + peRuta.length);

      const gol = await get(admin, '/routes/counts?from=1999-01-01&to=1999-01-07');
      check('o zi fără opriri nu apare cu un număr inventat',
        gol.status === 200 && Object.keys(gol.data?.counts || {}).length === 0,
        JSON.stringify(gol.data?.counts));
    } finally {
      // Restaurarea stă în `finally`, nu la coada blocului: o probă picată la
      // mijloc lăsa lista de moduri de onorare murdară pe baza de dezvoltare,
      // iar modul inventat rămânea în meniul din Setări la rulările următoare.
      await put(admin, '/settings', { settings: { fulfillment_types: before.fulfillment_types } });
    }
  }

  /* ------------------------------------------- sesiune și CSRF ------------ */
  console.log('\n=== sesiune și CSRF ===');
  {
    // Situația reală: cookie-ul de sesiune ține 14 zile, dar tokenul CSRF stă
    // în sessionStorage și dispare la închiderea filei. Utilizatorul revine cu
    // cookie valid și fără token — și trebuie să se poată autentifica.
    const stale = agent();
    await login(stale, ADMIN.u, ADMIN.p);
    stale.csrf = '';                       // tokenul pierdut, cookie-ul rămas

    const blocked = await patch(stale, '/orders/1', { notes: 'nu ar trebui' });
    check('fără token CSRF, o modificare este oprită → 403',
      blocked.status === 403, `a dat ${blocked.status}`);

    const relogin = await post(stale, '/auth/login', { username: ADMIN.u, password: ADMIN.p });
    check('cu cookie valid dar fără token, autentificarea merge → 200',
      relogin.status === 200, `a dat ${relogin.status} ${JSON.stringify(relogin.data?.error)}`);
    stale.csrf = relogin.data?.csrf || '';

    check('autentificarea întoarce un token nou', !!stale.csrf);

    const after = await patch(stale, '/orders/1', { notes: '' });
    check('cu tokenul nou, modificarea trece', after.status === 200 || after.status === 404,
      `a dat ${after.status}`);

    // Deconectarea trebuie să meargă și ea fără token: altfel cineva ar rămâne
    // blocat într-o sesiune pe care nu o poate închide.
    const out = agent();
    await login(out, STAFF.u, STAFF.p);
    out.csrf = '';
    const bye = await post(out, '/auth/logout');
    check('deconectarea merge și fără token CSRF → 200', bye.status === 200, `a dat ${bye.status}`);
    const gone = await get(out, '/auth/me');
    check('după deconectare sesiunea chiar nu mai există', gone.data?.user === null,
      JSON.stringify(gone.data?.user));
  }
  {
    // O autentificare nouă închide sesiunea dinainte, ca pe un calculator
    // folosit de mai mulți oameni să nu rămână valabilă sesiunea colegului.
    const shared = agent();
    await login(shared, STAFF.u, STAFF.p);
    const firstCookie = shared.cookies.get('gp_session');

    await login(shared, ADMIN.u, ADMIN.p);
    const secondCookie = shared.cookies.get('gp_session');
    check('a doua autentificare primește altă sesiune', firstCookie !== secondCookie);

    const old = agent();
    old.cookies.set('gp_session', firstCookie);
    const check1 = await get(old, '/auth/me');
    check('sesiunea dinainte a fost închisă', check1.data?.user === null,
      JSON.stringify(check1.data?.user));
  }

  /* ----------------------------------------- scăderea din depozit --------- */
  console.log('\n=== scăderea din stoc ===');
  {
    const doneName = (await get(admin, '/lists')).data.done;

    // O comandă nouă, dusă până la etapa finală.
    const mk = await post(admin, '/orders', {
      customerName: 'Test stoc', day, fulfillment: 'Ridicare',
      items: [{ productId: 1, qty: 3 }],
    });
    const oid = mk.data?.order?.id;
    check('comandă pentru testul de stoc', !!oid, JSON.stringify(mk.data)?.slice(0, 120));

    const stockOf = async () => {
      const r = await get(admin, '/stock/items');
      const it = (r.data.items || []).find((x) => x.productId === 1);
      return it ? it.qty : null;
    };

    // Golim coada de comenzi deja predate, ca măsurătoarea să fie curată.
    await post(admin, '/stock/sync');
    const start = await stockOf();

    await patch(admin, '/orders/' + oid, { status: doneName });
    const first = await post(admin, '/stock/sync');
    check('scăderea raportează comenzi procesate', first.data.orders >= 1, JSON.stringify(first.data));

    const after = await stockOf();
    check('stocul a scăzut cu cantitatea comandată', after === start - 3, `de la ${start} la ${after}`);

    // Miezul problemei: a doua apăsare nu are voie să mai scadă nimic.
    const second = await post(admin, '/stock/sync');
    check('a doua scădere nu mai găsește nimic de scăzut', second.data.orders === 0, JSON.stringify(second.data));

    const again = await stockOf();
    check('stocul rămâne neschimbat după a doua apăsare', again === after, `de la ${after} la ${again}`);

    const o = await get(admin, '/orders?day=' + day);
    const mine = (o.data.orders || []).find((x) => x.id === oid);
    check('comanda e marcată ca scăzută din stoc', mine && mine.stockSynced === true, JSON.stringify(mine?.stockSynced));
  }

  /* ------------------- comanda incompletă nu se scade pe bucăți ----------- */
  console.log('\n=== comanda fără articol în depozit ===');
  {
    // Comanda e unitatea de lucru: dacă o singură poziție din ea n-are articol
    // în depozit, nu se scade NIMIC din ea și rămâne nescăzută, ca să poată fi
    // reluată. Varianta veche scădea pozițiile pe care le găsea și marca
    // totuși comanda ca încheiată, deci restul nu se mai scădea niciodată —
    // oricâte articole ai fi creat după aceea.
    const lists = (await get(admin, '/lists')).data;
    const doneName = lists.done;
    const stocCat = (lists.stockCategories || [])[0];
    const catSlug = (await get(admin, '/categories')).data.categories[0]?.slug;
    const tag = Math.random().toString(36).slice(2, 7);
    const numeNou = 'Ștrudel de probă ' + tag;

    const p = await post(admin, '/products', {
      name: numeNou, nameEn: 'Test strudel ' + tag,
      description: 'Descriere de probă.', descriptionEn: 'Test description.',
      category: catSlug, grams: 150, priceFrozen: 3.2, priceBaked: 3.99,
    });
    const pid = p.data?.id;
    check('produs nou, fără articol în depozit', !!pid, JSON.stringify(p.data)?.slice(0, 140));

    const qtyOf = async (pred) => {
      const r = await get(admin, '/stock/items');
      const it = (r.data.items || []).find(pred);
      return it ? it.qty : null;
    };
    const qtyVechi = () => qtyOf((x) => x.productId === 1);
    const qtyNou = () => qtyOf((x) => x.name === numeNou);

    check('produsul nou chiar n-are articol în depozit', (await qtyNou()) === null);

    // Comanda amestecă o poziție care are articol cu una care n-are.
    const mk = await post(admin, '/orders', {
      customerName: 'Fără Articol În Depozit', day, fulfillment: 'Ridicare',
      items: [{ productId: 1, qty: 2 }, { productId: pid, qty: 5 }],
    });
    const oid = mk.data?.order?.id;
    check('comandă cu o poziție fără articol', !!oid, JSON.stringify(mk.data)?.slice(0, 160));

    await post(admin, '/stock/sync');   // golim coada rămasă de la testul dinainte
    const start = await qtyVechi();
    await patch(admin, '/orders/' + oid, { status: doneName });

    const r1 = await post(admin, '/stock/sync');
    check('articolul lipsă e raportat pe nume',
      (r1.data.missing || []).includes(numeNou), JSON.stringify(r1.data.missing));
    check('comanda e raportată ca rămasă nescăzută',
      (r1.data.skipped || []).includes(oid), JSON.stringify(r1.data.skipped));
    const dupa1 = await qtyVechi();
    check('nici poziția care avea articol nu s-a scăzut', dupa1 === start,
      `de la ${start} la ${dupa1}`);

    const inca = ((await get(admin, '/orders?day=' + day)).data.orders || []).find((x) => x.id === oid);
    check('comanda rămâne nescăzută, ca să poată fi reluată',
      !!inca && inca.stockSynced === false, JSON.stringify(inca?.stockSynced));

    // Se creează articolul care lipsea: abia acum comanda se scade, întreagă.
    const it = await post(admin, '/stock/items', {
      name: numeNou, category: stocCat, unit: 'buc', productId: pid,
    });
    check('articolul lipsă se poate crea → 201', it.status === 201,
      `a dat ${it.status} ${JSON.stringify(it.data)?.slice(0, 120)}`);

    const r2 = await post(admin, '/stock/sync');
    check('a doua scădere prinde comanda rămasă', (r2.data.orders || 0) >= 1, JSON.stringify(r2.data));
    const dupa2 = await qtyVechi();
    check('poziția cu articol vechi s-a scăzut abia acum', dupa2 === start - 2,
      `de la ${start} la ${dupa2}`);
    const nou2 = await qtyNou();
    check('poziția care blocase comanda s-a scăzut și ea', nou2 === -5, String(nou2));

    const final = ((await get(admin, '/orders?day=' + day)).data.orders || []).find((x) => x.id === oid);
    check('acum comanda e marcată ca scăzută', !!final && final.stockSynced === true,
      JSON.stringify(final?.stockSynced));

    // Produsul de probă iese din catalog; curata-probe.mjs îl șterge de tot.
    await call(admin, 'DELETE', '/products/' + pid);
  }

  /* ------------------------------------------ pragul minim implicit ------- */
  console.log('\n=== pragul minim implicit ===');
  {
    // „Prag minim implicit" din Setări nu era citit de nimeni: articolul nou
    // pornea cu pragul 0, deci nu ajungea niciodată în „Sub prag minim" și
    // nimeni nu afla că s-a terminat.
    const stocCat = ((await get(admin, '/lists')).data.stockCategories || [])[0];
    const tag = Math.random().toString(36).slice(2, 7);
    const nume = 'Articol de probă prag ' + tag;
    const altul = 'Articol de probă prag ' + tag + ' bis';

    await put(admin, '/settings', { settings: { stock_min_default: '7' } });

    const nou = await post(admin, '/stock/items', { name: nume, category: stocCat, unit: 'buc' });
    check('articol nou, fără prag trimis → 201', nou.status === 201,
      `a dat ${nou.status} ${JSON.stringify(nou.data)?.slice(0, 120)}`);

    const it = ((await get(admin, '/stock/items')).data.items || []).find((x) => x.name === nume);
    check('articolul nou primește pragul din Setări', it && it.minQty === 7,
      JSON.stringify(it?.minQty));
    // Miza: cu pragul pe 0 articolul n-ar fi ieșit niciodată la „stoc scăzut".
    check('cu stocul pe zero, articolul e raportat ca fiind pe terminate',
      it && it.low === true, JSON.stringify(it?.low));

    // Pragul scris de om rămâne al lui: setarea e un implicit, nu o regulă.
    await post(admin, '/stock/items', { name: altul, category: stocCat, unit: 'buc', minQty: 2 });
    const it2 = ((await get(admin, '/stock/items')).data.items || []).find((x) => x.name === altul);
    check('pragul trimis explicit bate implicitul', it2 && it2.minQty === 2,
      JSON.stringify(it2?.minQty));

    // Articolele de probă ies din depozit, ca fila să nu se umple cu ele.
    for (const x of [it, it2]) {
      if (x) await patch(admin, '/stock/items/' + x.id, { active: false });
    }
    await put(admin, '/settings', { settings: { stock_min_default: before.stock_min_default } });
  }


  /* ------------------------------------------- codul tabletelor ----------- */
  console.log('\n=== codul tabletelor ===');
  {
    const scurt = await put(admin, '/settings', { settings: { panel_code: 'ab12' } });
    check('un cod prea scurt este respins → 422', scurt.status === 422, `a dat ${scurt.status}`);
    check('mesajul spune de ce', /6 caractere/.test(scurt.data?.error?.message || ''),
      scurt.data?.error?.message);

    // Cod ales la rulare, nu unul fix: proiectul nu mai are voie să conțină
    // nicăieri un cod de tablete care chiar deschide o instalare.
    const cod = 'k' + Math.random().toString(36).slice(2, 7);
    const bun = await put(admin, '/settings', { settings: { panel_code: cod } });
    check('un cod de 6 caractere se salvează', bun.status === 200, `a dat ${bun.status}`);

    const r = await get(admin, '/settings');
    check('codul nu se întoarce în clar', !String(r.data.settings.panel_code).includes(cod),
      String(r.data.settings.panel_code));
    check('interfața știe că există un cod', r.data.settings.panel_code_set === true);

    // Valoarea mascată, retrimisă la salvare, nu are voie să șteargă codul —
    // altfel simpla deschidere a paginii de Setări ar închide tabletele.
    await put(admin, '/settings', { settings: { panel_code: r.data.settings.panel_code } });
    const dupa = await get(admin, '/settings');
    check('salvarea cu valoarea mascată păstrează codul',
      dupa.data.settings.panel_code_set === true);
  }

/* --------------------------------------- contactul de pe pagina publică -- */
  console.log('\n=== contactul public ===');
  {
    // Telefonul și e-mailul din Setări erau citite doar de învelișul trimis
    // motoarelor de căutare. Pagina pe care o vede clientul îi avea scriși în
    // cod, deci numărul schimbat din panou nu ajungea niciodată la el, iar
    // Google primea numărul nou: site-ul se contrazicea singur.
    const vizitator = agent();          // fără cont, exact ca un client de pe site
    const telefon = '+40 712 345 678';
    const adresa = 'proba-contact@exemplu.ro';

    const salvat = await put(admin, '/settings', {
      settings: { company_phone: telefon, company_email: adresa },
    });
    check('telefonul și e-mailul public se salvează', salvat.status === 200, `a dat ${salvat.status}`);

    const l = await get(vizitator, '/lists');
    check('site-ul primește datele de contact fără să fie autentificat',
      l.status === 200, `a dat ${l.status}`);
    check('telefonul de pe site e cel scris în Setări',
      l.data?.contact?.phone === telefon, JSON.stringify(l.data?.contact));
    check('adresa de e-mail de pe site e cea scrisă în Setări',
      l.data?.contact?.email === adresa, JSON.stringify(l.data?.contact));

    await put(admin, '/settings', {
      settings: { company_phone: before.company_phone, company_email: before.company_email },
    });
  }

  /* -------------------------------------------------------- secrete ------- */
  console.log('\n=== secretele din panou ===');
  {
    await put(admin, '/settings', { settings: { notify_telegram_token: 'SECRET-TEST-123456789' } });
    const r = await get(admin, '/settings');
    const t = r.data.settings.notify_telegram_token;
    check('tokenul nu se întoarce în clar', !t.includes('SECRET'), t);
    check('interfața știe că există un token salvat', r.data.settings.notify_telegram_token_set === true);

    // Salvare fără să atingem tokenul: valoarea mascată nu trebuie să-l șteargă.
    await put(admin, '/settings', { settings: { notify_telegram_token: t } });
    const after = await get(admin, '/settings');
    check('o salvare cu valoarea mascată păstrează tokenul',
      after.data.settings.notify_telegram_token_set === true);

    await put(admin, '/settings', { settings: { notify_telegram_token: '__clear__' } });
    const gone = await get(admin, '/settings');
    check('tokenul se poate șterge explicit',
      gone.data.settings.notify_telegram_token_set === false);
  }

  /* ------------------------------ anunțurile care nu au plecat ------------ */
  console.log('\n=== anunțurile eșuate ajung sub ochii cuiva ===');
  {
    // Un canal fără destinatar eșuează sigur și fără să iasă pe internet:
    // send_channel() se oprește înainte să cheme Telegram.
    const chatVechi = before.notify_telegram_chat_id || '';
    await put(admin, '/settings', { settings: { notify_telegram_chat_id: '' } });

    // Pornim de la o listă goală, ca să numărăm doar ce stricăm noi acum.
    await post(admin, '/settings/notifications/seen');

    const t = await post(admin, '/settings/test/telegram');
    check('testul fără destinatar eșuează → 502', t.status === 502, `a dat ${t.status}`);

    const r = await get(admin, '/settings');
    const lista = r.data.failed;
    check('setările aduc lista anunțurilor eșuate', Array.isArray(lista),
      'lipsește câmpul „failed” din GET /settings — panoul nu are de unde afla');
    const al = (lista || []).find((x) => x.channel === 'telegram');
    check('anunțul eșuat apare în listă', !!al, JSON.stringify(lista)?.slice(0, 200));
    check('lista spune și de ce a eșuat', !!al && /[Dd]estinatarul/.test(al.error || ''),
      al && al.error);

    const furat = await post(staff, '/settings/notifications/seen');
    check('un angajat nu poate goli lista → 403', furat.status === 403, `a dat ${furat.status}`);

    const seen = await post(admin, '/settings/notifications/seen');
    check('„Am văzut” golește lista → 200', seen.status === 200, `a dat ${seen.status}`);
    check('răspunsul întoarce lista deja goală',
      Array.isArray(seen.data.failed) && seen.data.failed.length === 0,
      JSON.stringify(seen.data.failed)?.slice(0, 200));

    const dupa = await get(admin, '/settings');
    check('lista rămâne goală și la reîncărcarea setărilor',
      Array.isArray(dupa.data.failed) && dupa.data.failed.length === 0,
      JSON.stringify(dupa.data.failed)?.slice(0, 200));

    await put(admin, '/settings', { settings: { notify_telegram_chat_id: chatVechi } });
  }

  /* --------------------------- adresa expeditor a notificărilor ----------- */
  console.log('\n=== adresa expeditor de e-mail ===');
  {
    const bun = before.notify_email_from || '';

    const fara = await put(admin, '/settings', {
      settings: { notify_email_from: 'noreply-fara-adresa' },
    });
    check('o adresă expeditor fără @ este respinsă → 422', fara.status === 422,
      `a dat ${fara.status}`);

    // Miezul: valoarea intră în antetul „From:”, iar anteturile se despart prin
    // salt de linie. Un CRLF în ea ar adăuga anteturi proprii în mesaj.
    const injectie = await put(admin, '/settings', {
      settings: { notify_email_from: 'noreply@goldenpastry.ro\r\nBcc: strain@exemplu.ro' },
    });
    check('o adresă expeditor cu salt de linie este respinsă → 422', injectie.status === 422,
      `a dat ${injectie.status}`);

    const r = await get(admin, '/settings');
    check('adresa expeditor bună a rămas neatinsă după respingeri',
      r.data.settings.notify_email_from === bun,
      JSON.stringify(r.data.settings.notify_email_from));

    const ok = await put(admin, '/settings', {
      settings: { notify_email_from: 'notificari@goldenpastry.ro' },
    });
    check('o adresă expeditor validă se salvează → 200', ok.status === 200, `a dat ${ok.status}`);

    await put(admin, '/settings', { settings: { notify_email_from: bun } });
  }

} finally {
  await restore();
}

console.log('\n====================================================');
console.log(`${pass} trecute · ${fail} eșuate`);
if (failures.length) {
  console.log('\nEșecuri:');
  failures.forEach((f) => console.log('  · ' + f));
}
process.exit(fail ? 1 : 0);
