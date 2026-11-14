# Golden Pastry — aplicația de comenzi

> 📄 **Studiu de caz:** [CASE_STUDY.md](CASE_STUDY.md) · [iluz.ro](https://iluz.ro/video/proiect/h-mw8lo682) — 19 versiuni în <2 luni, 664/717 probleme rezolvate.

Aplicație multi-utilizator pentru o patiserie: site public de unde se comandă,
panou de clienți, și panourile interne — laborator, șofer, depozit,
administrare, pontaj, setări, și statisticile casei.

Datele stau pe server, într-o bază MySQL. O comandă plasată de pe telefonul
unui client ajunge pe ecranul din laborator, pe alt dispozitiv — lucru care în
prototipul de la care am pornit era imposibil: totul se ținea în `localStorage`,
adică în browserul care crease datele.

---

## Unde e ce

| Folder | Conține |
|---|---|
| `*.dc.html` | paginile, în forma lor de lucru (sursa) |
| `seo/` | învelișul PHP al paginii publice: antet, schema.org, sitemap |
| `api/` | serverul: PHP 8, PDO, JSON |
| `sql/` | schema bazei de date și datele inițiale |
| `shared/gp-api.js` | clientul prin care paginile vorbesc cu serverul |
| `deploy/` | pachetul gata de urcat — **generat, nu se editează** |
| `docs/` | instalarea locală, panoul de Setări, publicarea |
| `.claude/` | verificările, testele și scriptul de publicare |

---

## Cum lucrezi la el

Pornește serverul local (Laragon aduce PHP și MySQL — vezi
[docs/01-instalare-locala.md](docs/01-instalare-locala.md)):

```bash
php -S 127.0.0.1:8100 -t . .claude/dev-server.php
```

Deschide `http://localhost:8100/Intrare.dc.html`.

După orice modificare:

```bash
node .claude/verifica-tot.mjs
```

Rulează 96 de verificări statice și 103 suite pe serverul
local — peste unsprezece mii de probe. Se poate rula de câte ori
vrei: la final, `curata-probe` scoate din baza de date produsele, cererile și
comenzile de probă. Fără el, pagina Catalog se
umple de „Ștrudel de probă · SCOS DIN CATALOG", fila „Cereri de pe site" de
cereri de la „Proba varianta", iar registrul de comenzi ajunge la cinci sute de
rânduri, toate din aceeași zi. Rulează și separat:

```bash
node .claude/curata-probe.mjs
```

Refuză să pornească pe altă bază decât cea de dezvoltare.

**Dă-i probei tale nume care începe cu „Probă ".** Atât: curățenia îl șterge
fiindcă așa se numește, fără să mai trebuiască trecut în vreo listă. Numele care
nu țin convenția se scriu de mână în `curata-probe.mjs`, iar dacă uiți, scriptul
îți spune la sfârșit ce n-a putut lua. Nu e o formalitate: trei probe își
scriau numele printr-un parametru, curățenia nu le vedea, și s-au strâns tăcut
1746 de comenzi — destule cât o zi să treacă de tăietura de 500 de rânduri pe
pagină și o probă despre cu totul altceva să înceapă să pice.

Probele pentru pagina publică au nevoie de pachetul construit și servit; fără
el sunt sărite, nu raportate ca eșec:

```bash
php -S 127.0.0.1:8101 -t deploy .claude/deploy-server.php
```

`127.0.0.1`, nu `localhost`: pe Windows `localhost` se leagă doar pe IPv6, iar
probele cer adresa IPv4. Pornit greșit, serverul răspunde în browser, dar
`test-seo` nu-l găsește și se sare tăcut, raportând „0 trecute · 0 eșuate".

---

## Ce verifică fiecare

| Verificare | Ce prinde |
|---|---|
| `verify-import` | fișiere din import lipsă sau stricate |
| `check-pages` | sintaxă, metode apelate dar inexistente, chei de stare duplicate, pagini care folosesc etapele fără să le ceară de la server |
| `check-php` | funcții PHP apelate dintr-un fișier care nu se încarcă |
| `check-sql` | tabele și coloane care nu se potrivesc între schemă și cod |
| `check-secrets` | parole sau tokenuri rămase în paginile care ajung în browser |
| `check-dates` | date calendaristice scrise în cod |
| `check-meniu` | meniul lateral diferit de la un panou la altul |
| `check-site-public` | reparațiile din pagina publică: coșul, cantitatea scrisă de mână, telefonul, catalogul, traducerile |
| `check-poze` | sloturile de poze: atingerea pe telefon, slotul gol, trasul cu mouse-ul — și în sursă, și în pachet |
| `check-setari` | butonul de test din Setări nu mai șterge modificările nesalvate |
| `check-deploy` | pachetul de publicare: `.htaccess`, `robots.txt`, rescrierile din pagini |
| `check-panouri` | reparațiile din panourile de lucru și din ajutorul comun — funcțiile paginilor, scoase din sursă și rulate aici |
| `test-php.php` | funcții verificate direct: ce consideră serverul „adresa clientului", cum se construiesc adresele produselor |

Fiecare a fost adăugată după ce a scăpat un defect real. `check-pages` a fost
scrisă pentru un apel către o metodă ștearsă — pagina se încărca fără eroare și
crăpa abia la apăsarea butonului. `check-dates` a fost scrisă după ce pagina
clientului anunța „3 zile până la comandă" pentru o comandă de azi.
`check-meniu` a fost scrisă după ce „Catalog" a ajuns al treilea într-o pagină
și ultimul în alta: fiecare panou își ținea propria copie a listei.

| Teste | Ce acoperă |
|---|---|
| `test-instalare.php` | instalarea de la zero: schema, primul cont, codul tabletelor |
| `test-api` | drepturi pe roluri, sesiuni, CSRF, limitarea autentificărilor, CORS |
| `test-sesiune-tableta` | tableta din laborator nu se deconectează de la sine, dar sesiunea unui om se închide ca până acum, iar schimbarea codului o scoate afară pe loc |
| `test-flow` | cererea de pe site devine comandă și ajunge în laborator |
| `test-parola` | parola uitată: linkul de pe e-mail, tokenul de unică folosință, sesiunile închise |
| `test-settings` | setări, liste editabile, ruta șoferului, scăderea din depozit |
| `test-stoc-concurent.php` | două scăderi din stoc pornite deodată nu se pot dubla |
| `test-catalog` | catalogul editabil: produse, grupe, fotografii, cele două feluri de ștergere |
| `test-editare` | formularul din panou salvează tot: clientul, pozițiile, ziua |
| `test-seo` | ce vede un motor de căutare la prima cerere (cere pachetul construit și servit) |

---

## Publicarea

```bash
node .claude/build-deploy.mjs
```

Construiește `deploy/`, cu câte un folder pentru fiecare subdomeniu. Legăturile
din același subdomeniu rămân relative, cele care trec dintr-unul în altul devin
adrese complete. Pașii de urcat pe server sunt în
[docs/08-publicare-vps-hostinger.md](docs/08-publicare-vps-hostinger.md).

Pachetul se poate proba local, ca și cum ar fi pe server:

```bash
php -S 127.0.0.1:8101 -t deploy .claude/deploy-server.php
```

`api/config.php` — datele bazei și cheia de criptare — **nu face parte din
pachet**, ca să nu fie suprascris la o actualizare și să nu plece de aici date
de conectare.

---

## De citit

- [docs/01-instalare-locala.md](docs/01-instalare-locala.md) — mediul de test
- [docs/02-panoul-de-setari.md](docs/02-panoul-de-setari.md) — WhatsApp, liste, cod tablete
- [docs/08-publicare-vps-hostinger.md](docs/08-publicare-vps-hostinger.md) — instalarea de la zero pe un VPS, pas cu pas
- [docs/09-actualizare-vps.md](docs/09-actualizare-vps.md) — actualizarea VPS-ului cu un singur script
- [docs/04-catalogul-de-produse.md](docs/04-catalogul-de-produse.md) — produse, grupe, prețuri, ce vede Google
- [docs/05-conturile-de-client.md](docs/05-conturile-de-client.md) — comanda de pe cont, istoricul, comanda luată la telefon
- [docs/06-defecte-de-reparat.md](docs/06-defecte-de-reparat.md) — **717 defecte găsite**, 664 reparate, fiecare cu proba ei — 53 lăsate deschise dinadins, scrise acolo cu ce pățește omul
- [docs/07-actualizari.md](docs/07-actualizari.md) — cum publici o modificare **după** ce site-ul merge deja, fără să pierzi date
- [docs/10-trecerea-cu-ochii.md](docs/10-trecerea-cu-ochii.md) — **proba de dinaintea publicării**: ce apeși și ce trebuie să vezi; douăzeci și patru de plimbări, care țin două sute patruzeci de minute
