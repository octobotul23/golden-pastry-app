// Ziua cerută de client: fereastra în care se poate comanda.
//
// Două defecte, aceeași lipsă. Câmpul „Data dorită" de pe site n-avea nici
// `min`, nici `max`, iar `POST /requests` verifica doar FORMA zilei:
//
//  A. O zi din trecut trecea. Pagina răspundea „Mulțumim! Cererea ta a intrat
//     la noi în platformă", cererea chiar intra în panou — și abia butonul
//     „Acceptă" o refuza, cu `day_in_past`. Cererea rămânea blocată pe „nou"
//     pentru totdeauna, iar cineva trebuia să sune clientul ca s-o ia de la capăt.
//  B. În sus nu exista nicio margine. „3026" în loc de „2026" — o greșeală de o
//     tastă — năștea o comandă adevărată, activă, pe care niciun ecran n-o mai
//     vede: Panoul Laborator și Intrare arată azi..azi+6, ruta o singură zi.
//
// Proba are două jumătăți: una statică, peste fișiere, și una pe API-ul viu.
// Partea live are nevoie de GP_BASE (o pornește `verifica-tot.mjs`).
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE = process.env.GP_BASE || 'http://127.0.0.1:8100/api';

let trecute = 0, esuate = 0;
const check = (nume, ok, detaliu = '') => {
  if (ok) { trecute++; console.log('  ✓ ' + nume); }
  else { esuate++; console.log('  ✗ ' + nume + (detaliu ? '\n      ' + detaliu : '')); }
};
const citeste = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');

/* ── A. Statice: marginile există în formular și în cod ──────────────────── */
console.log('\n=== formularul de pe site are marginile puse ===');
{
  const site = citeste('Site Public.dc.html');
  const camp = site.split('\n').find((l) => /name="Data dorită"[^>]*type="date"/.test(l)) || '';
  check('câmpul „Data dorită" există', !!camp);
  check('are `min`, legat de fereastra de la server', /min="\{\{ dateMin \}\}"/.test(camp), camp.trim());
  check('are `max`, legat de fereastra de la server', /max="\{\{ dateMax \}\}"/.test(camp), camp.trim());
  check('marginile vin din gp.orderWindow(), nu din ceasul browserului',
    /dateMin: gp\.orderWindow\(\)\.first/.test(site) && /dateMax: gp\.orderWindow\(\)\.last/.test(site));
  check('trimiterea verifică ziua înainte de drumul dus-întors',
    /gp\.orderWindow\(\)[\s\S]{0,400}?wantDate < fer\.first \|\| req\.wantDate > fer\.last/.test(site));

  const api = citeste('shared/gp-api.js');
  check('gp.orderWindow citește fereastra din /lists', /orderWindow\(\)\s*\{[\s\S]{0,200}gp\.__lists && gp\.__lists\.orderWindow/.test(api));
}

console.log('\n=== serverul are o singură definiție a zilei ===');
{
  const http = citeste('api/lib/http.php');
  check('api/lib/http.php: today_local() există', /function today_local\(\): string/.test(http));
  check('api/lib/http.php: order_day_window() există', /function order_day_window\(\): array/.test(http));
  check('api/lib/http.php: valid_order_day() există', /function valid_order_day\(/.test(http));
  check('orizontul e scris o singură dată, cu nume', /const GP_ORIZONT_ZILE = \d+;/.test(http));

  const cereri = citeste('api/routes/requests.php');
  check('POST /requests verifică fereastra, nu doar forma',
    /valid_order_day\(\$day, 'Ziua cerută'\)/.test(cereri));
  check('acceptarea folosește aceeași verificare',
    (cereri.match(/valid_order_day\(/g) || []).length >= 2);
  check('acceptarea nu-și mai are propria comparație de zi',
    !/if \(\$day < \$azi\) \{/.test(cereri));

  const comenzi = citeste('api/routes/orders.php');
  check('rutele de comenzi nu mai iau ziua din UTC (gmdate)',
    !/gmdate\('Y-m-d'\)/.test(comenzi),
    (comenzi.split('\n').filter((l) => /gmdate\('Y-m-d'\)/.test(l)).join(' | ')));
}

/* ── B. Pe API-ul viu ────────────────────────────────────────────────────── */
console.log('\n=== pe API viu: ce se primește și ce se refuză ===');

const cookies = new Map();
let csrf = '';
async function call(metoda, cale, corp) {
  const h = {};
  if (corp !== undefined) h['Content-Type'] = 'application/json';
  if (csrf && metoda !== 'GET') h['X-CSRF-Token'] = csrf;
  if (cookies.size) h.Cookie = [...cookies].map(([k, v]) => k + '=' + v).join('; ');
  const r = await fetch(BASE + cale, {
    method: metoda, headers: h,
    body: corp === undefined ? undefined : JSON.stringify(corp),
  });
  for (const brut of r.headers.getSetCookie?.() ?? []) {
    const [pereche] = brut.split(';');
    const i = pereche.indexOf('=');
    const k = pereche.slice(0, i).trim(), v = pereche.slice(i + 1).trim();
    if (v === '') cookies.delete(k); else cookies.set(k, v);
  }
  const t = await r.text();
  let d = null;
  try { d = t ? JSON.parse(t) : null; } catch { d = { brut: t.slice(0, 200) }; }
  return { status: r.status, data: d };
}

const zi = (deLaAzi) => {
  const d = new Date();
  d.setDate(d.getDate() + deLaAzi);
  const z = (n) => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate());
};

try {
  const liste = await call('GET', '/lists');
  const w = liste.data?.orderWindow;
  check('GET /lists spune fereastra', !!(w && w.first && w.last), JSON.stringify(w));
  check('fereastra începe azi', w?.first === zi(0), 'a ieșit: ' + w?.first + ', azi e ' + zi(0));
  check('fereastra se termină peste un an', w?.last === zi(365) || w?.last === zi(366),
    'a ieșit: ' + w?.last);

  const produse = (await call('GET', '/products')).data?.products || [];
  const P = produse[0];
  check('am un produs pe care să cer', !!P);

  if (P) {
    const cere = (z) => call('POST', '/requests', {
      customerName: 'Proba Fereastra Zilei', phone: '0722010203', day: z,
      items: [{ productId: P.id, qty: 1, variant: 'congelat' }],
    });

    const ieri = await cere(zi(-1));
    check('ziua de ieri e refuzată la TRIMITERE, nu abia la acceptare',
      ieri.status === 422 && ieri.data?.error?.code === 'day_in_past',
      'a ieșit: ' + ieri.status + ' ' + (ieri.data?.error?.code || '') + ' — ' + (ieri.data?.error?.message || '').slice(0, 90));
    check('refuzul îi spune clientului de la ce zi poate alege',
      String(ieri.data?.error?.message || '').includes(zi(0)),
      'mesajul: ' + (ieri.data?.error?.message || ''));

    const anul3000 = await cere('3000-01-01');
    check('anul 3000 e refuzat',
      anul3000.status === 422 && anul3000.data?.error?.code === 'day_too_far',
      'a ieșit: ' + anul3000.status + ' ' + (anul3000.data?.error?.code || ''));
    check('refuzul spune care e ultima zi bună',
      String(anul3000.data?.error?.message || '').includes(w?.last || '@@'),
      'mesajul: ' + (anul3000.data?.error?.message || ''));

    const azi = await cere(zi(0));
    check('ziua de azi trece', azi.status === 201,
      'a ieșit: ' + azi.status + ' ' + JSON.stringify(azi.data?.error || '').slice(0, 120));

    const maine = await cere(zi(1));
    check('ziua de mâine trece', maine.status === 201, 'a ieșit: ' + maine.status);

    const ultima = await cere(w?.last || zi(365));
    check('ultima zi din fereastră trece', ultima.status === 201, 'a ieșit: ' + ultima.status);

    const pesteUltima = await cere(zi(400));
    check('o zi de după fereastră e refuzată',
      pesteUltima.status === 422 && pesteUltima.data?.error?.code === 'day_too_far',
      'a ieșit: ' + pesteUltima.status + ' ' + (pesteUltima.data?.error?.code || ''));

    const faraZi = await call('POST', '/requests', {
      customerName: 'Proba Fara Zi', phone: '0722010203',
      items: [{ productId: P.id, qty: 1, variant: 'congelat' }],
    });
    check('cererea fără zi („cât de repede se poate") trece mai departe',
      faraZi.status === 201, 'a ieșit: ' + faraZi.status);

    const stricata = await cere('31-08-2026');
    check('forma greșită rămâne refuzată ca înainte',
      stricata.status === 422 && stricata.data?.error?.code === 'bad_date',
      'a ieșit: ' + stricata.status + ' ' + (stricata.data?.error?.code || ''));

    // Cererile de probă rămase le scoate `.claude/curata-probe.mjs`, la fel ca
    // pentru celelalte teste: API-ul nu are ștergere definitivă, și nici nu
    // trebuie să aibă. Numele folosite aici sunt trecute în lista de acolo.
  }
} catch (e) {
  check('API-ul răspunde la ' + BASE, false, String(e));
}

console.log('\n  ' + trecute + ' trecute · ' + esuate + ' eșuate');
process.exitCode = esuate ? 1 : 0;
