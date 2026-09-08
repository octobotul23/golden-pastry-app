// Verifica trimiterile din docs/07-actualizari.md: fiecare "fisier:linie" citat
// acolo trebuie sa arate inca spre randul care sustine afirmatia. Cand codul se
// muta, ghidul minte tacut — proba asta il prinde.
import { execFileSync } from 'node:child_process'
import { readFileSync, existsSync, readdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { BAZA_DE_PROBA, CERE_CONFIG } from './baza-probei.mjs'

/** Calea unui binar din Laragon, sau numele lui daca e in PATH. */
const binar = (folder, exe) => {
  const dinMediu = process.env['GP_' + folder.toUpperCase()]
  if (dinMediu) return dinMediu
  try {
    const v = readdirSync('C:/laragon/bin/' + folder).find((d) => d.startsWith(folder + '-'))
    if (v) return path.join('C:/laragon/bin/' + folder, v, folder === 'mysql' ? 'bin' : '', exe + '.exe')
  } catch { /* alt sistem */ }
  return exe
}

const RADACINA = fileURLToPath(new URL('..', import.meta.url))
const cd = (p) => RADACINA + p

const rezultate = []
const verifica = (nume, conditie, detaliu) => rezultate.push({ nume, ok: !!conditie, detaliu })

const linii = (cale) => {
  const c = cd(cale)
  if (!existsSync(c)) return null
  return readFileSync(c, 'utf8').split('\n')
}

// fiecare: [fisier, linie sau [de la, pana la], bucata de text care trebuie sa apara acolo]
const TRIMITERI = [
  ['.claude/build-deploy.mjs', 329, 'rmrf(OUT)'],
  ['.claude/build-deploy.mjs', [33, 51], 'Intrare.dc.html'],
  ['.claude/build-deploy.mjs', [56, 63], '.image-slots.state.json'],
  ['.claude/build-deploy.mjs', [147, 164], 'rewriteLinks'],
  ['.claude/build-deploy.mjs', [239, 240], 'access plus 7 days'],
  ['.claude/build-deploy.mjs', 243, 'text/html'],
  ['.claude/build-deploy.mjs', 278, 'products'],
  ['.claude/build-deploy.mjs', [346, 348], 'nerezolvate'],
  ['.claude/build-deploy.mjs', [360, 365], 'subdomeniu'],
  ['.claude/build-deploy.mjs', [406, 410], 'config.php'],
  ['.claude/build-deploy.mjs', [427, 433], 'config.php'],
  ['.claude/build-deploy.mjs', [436, 451], 'setup.php'],
  ['.claude/build-deploy.mjs', [502, 505], 'exit'],
  ['api/index.php', 30, 'require $configFile'],
  ['api/index.php', 47, 'display_errors'],
  ['api/index.php', 224, 'inexistent'],
  ['api/index.php', [225, 230], 'Eroare intern'],
  ['api/lib/crypto.php', 85, 'null'],
  ['api/lib/orders.php', 528, 'function stock_unsync_order'],
  ['api/lib/settings.php', 36, 'error_log'],
  ['api/routes/auth.php', [255, 260], '503'],
  ['api/routes/catalog.php', [369, 370], 'move_uploaded_file'],
  ['api/setup.php', [39, 48], 'admin'],
  ['api/verifica.php', [332, 342], '404'],
  ['sql/001_schema.sql', [229, 230], 'fk_req_order'],
  ['sql/003_route_order.sql', [11, 15], 'phpMyAdmin'],
  ['sql/003_route_order.sql', [82, 87], 'UPDATE necondi'],
  ['sql/003_route_order.sql', 104, 'product_categories'],
  ['seo/index.php', 76, 'price'],
  ['Site Public.dc.html', 1104, 'gp-cart'],
  ['Site Public.dc.html', 1210, 'r[5] !== null'],
  ['Site Public.dc.html', 1294, 'byNo'],
]

for (const [fisier, unde, asteptat] of TRIMITERI) {
  const l = linii(fisier)
  const eticheta = fisier + ':' + (Array.isArray(unde) ? unde.join('-') : unde)
  if (!l) { verifica('trimitere ' + eticheta, false, 'fisierul lipseste'); continue }
  const [de, pana] = Array.isArray(unde) ? unde : [unde, unde]
  const felie = l.slice(de - 1, pana).join('\n')
  verifica(
    'trimitere ' + eticheta + ' contine "' + asteptat + '"',
    felie.toLowerCase().includes(asteptat.toLowerCase()),
    felie.slice(0, 160).replace(/\n/g, ' | ')
  )
}

// Afirmatiile numerice din ghid, verificate direct in cod
// Din 2.0.2 seed-ul e numai INSERT IGNORE: blocul de UPDATE-uri a fost scos,
// deci reimportul chiar e sigur — și fișierul are voie s-o spună.
const seed = readFileSync(cd('sql/002_seed.sql'), 'utf8')
const nrUpdate = (seed.match(/^UPDATE products SET/gm) || []).length
verifica('seed nu mai are niciun UPDATE products', nrUpdate === 0, 'gasite ' + nrUpdate)
verifica('seed e numai INSERT IGNORE',
  (seed.match(/^INSERT /gm) || []).every(() => true) && !/^INSERT INTO/m.test(seed) && !/^UPDATE /m.test(seed) && !/^DELETE /m.test(seed),
  'exista scrieri care nu sunt INSERT IGNORE')
verifica('antetul seed spune ca reimportul e sigur',
  /Se poate rula din nou fără teamă/.test(seed), 'antet fara lamurire')

const doc03 = readFileSync(cd('docs/03-publicare-cpanel.md'), 'utf8')
verifica('docs/03 nu mai cere reimportul intregului seed',
  !/`sql\/002_seed\.sql` — încă o dată/.test(doc03) && !/nu atinge nicio valoare pe care ai schimbat-o/.test(doc03),
  'instructiunea distructiva e inca acolo')
verifica('docs/03 nu mai trimite la verifica.php pentru control dupa migrare',
  !/Deschide după aceea `api\/verifica\.php`/.test(doc03),
  'trimiterea imposibila e inca acolo')

// Zero versionare in pachet: afirmatia centrala a capcanei 3
const capcanaCache = () => {
  const app = cd('deploy/public_html/app.html')
  if (!existsSync(app)) return { sarit: true }
  const t = readFileSync(app, 'utf8')
  return { sarit: false, are: /(support|gp-api|image-slot)\.js\?v=/.test(t) }
}
const c3 = capcanaCache()
if (c3.sarit) rezultate.push({ nume: 'capcana 3 (fara pachet construit)', sarit: true })
else verifica('ghidul spune adevarul: app.html cheama .js fara ?v=', c3.are === false,
  'app.html are deja versionare — capcana 3 din ghid e depasita')

const doc07 = readFileSync(cd('docs/07-actualizari.md'), 'utf8')
for (const cheie of ['Regula de aur', 'Capcanele', 'Cum verifici că migrarea a intrat', 'Ce e de îmbunătățit',
  'Urmele lăsate de defectele reparate']) {
  verifica('ghidul are sectiunea „' + cheie + '"', doc07.includes(cheie), 'lipseste')
}

// Cele trei interogari de recuperare din ghid — cele pe care omul le copiaza in
// phpMyAdmin dupa o publicare. Un ghid care da o interogare gresita e mai rau
// decat unul care nu da niciuna: omul o ruleaza pe baza adevarata exact cand
// cauta o defectiune si primeste o eroare de MySQL. De aceea nu le citim, le
// rulam: singura dovada ca inca se potrivesc cu schema.
{
  const sectiune = doc07.split('## Urmele lăsate de defectele reparate')[1] || ''
  const pana = sectiune.indexOf('\n## ')
  const corp = pana === -1 ? sectiune : sectiune.slice(0, pana)
  const cereri = [...corp.matchAll(/```sql\n([\s\S]*?)```/g)].map((m) => m[1].trim())

  verifica('sectiunea de recuperare are trei interogari', cereri.length === 3, 'gasite ' + cereri.length)

  let baza = null
  try {
    baza = JSON.parse(execFileSync(binar('php', 'php'),
      ['-r', '$c = require ' + CERE_CONFIG + '; echo json_encode($c["db"]);'],
      { cwd: RADACINA, encoding: 'utf8' }))
  } catch { /* fara PHP sau fara config */ }

  if (!baza || !BAZA_DE_PROBA.test(baza.name)) {
    rezultate.push({ nume: 'interogarile de recuperare (fara baza de dezvoltare)', sarit: true })
  } else {
    const mysql = binar('mysql', 'mysql')
    cereri.forEach((sql, i) => {
      let eroare = ''
      try {
        execFileSync(mysql, [
          '--default-character-set=utf8mb4',
          '-u', baza.user, ...(baza.pass ? ['-p' + baza.pass] : []),
          '-h', baza.host, '-P', String(baza.port), baza.name, '-N', '-B', '-e', sql,
        ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
      } catch (e) {
        eroare = String(e.stderr || e.message || '').split('\n')[0]
      }
      verifica('interogarea ' + (i + 1) + ' din ghid chiar merge pe schema de azi', eroare === '', eroare)
    })
  }
}

const cazute = rezultate.filter((r) => !r.sarit && !r.ok)
for (const r of rezultate) {
  if (r.sarit) console.log('  ~ ' + r.nume)
  else console.log((r.ok ? '  ✓ ' : '  ✗ ') + r.nume + (r.ok ? '' : '\n      ' + r.detaliu))
}
console.log('\n  ' + rezultate.filter((r) => r.ok).length + ' trecute · ' + cazute.length + ' eșuate')
process.exit(cazute.length ? 1 : 0)
