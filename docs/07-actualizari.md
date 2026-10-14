# Cum publici o modificare pe site-ul care merge deja

Ghidul ăsta e pentru **după** prima publicare, când pe server sunt deja comenzi
reale, clienți reali și poze urcate de tine. Instalarea de la zero e în
[08-publicare-vps-hostinger.md](08-publicare-vps-hostinger.md).

Ideea de bază, în două rânduri: **sursa e pe calculatorul tău, `deploy/` e doar
rezultatul.** Modifici sursa, rulezi un script, urci ce a ieșit. Folderul
`deploy/` se șterge și se reface complet la fiecare construire
(`.claude/build-deploy.mjs:720`), deci orice ai scrie direct acolo dispare.

---

## Regula de aur

Cinci lucruri. Niciunul nu are excepții.

**1. `public_html/api/config.php` nu se suprascrie niciodată.**
Acolo stau parola bazei de date și cheia de criptare. Nu e în pachet deloc —
scriptul îl scoate (`.claude/build-deploy.mjs:926-928`) și mai verifică o dată
la final (`:946-953`), deci nicio urcare nu-l poate atinge din greșeală.
**Cheia de criptare nu se schimbă niciodată după instalare.** Dacă se schimbă,
`api/lib/crypto.php:85` întoarce `null`, iar `api/lib/settings.php:36` doar
scrie o linie în jurnal și merge mai departe — *tăcut*. Efectul vizibil:
codul tabletelor devine gol, `api/routes/auth.php:411-415` răspunde 503
„Codul panoului nu este configurat", și toată echipa din laborator rămâne
pe dinafară.

**2. `public_html/products/` nu se șterge și nu se mai urcă după prima instalare.**
Pozele pe care le încarci din pagina Catalog trăiesc **numai** acolo:
`api/routes/catalog.php:809` le scrie pe disc sub un nume cu cifre aleatoare
(`prod-14-9c3a71ef.jpg`), și nimic nu le aduce înapoi în proiect. Pachetul conține și el un folder `products/`, construit din copia
mea locală — cele două se depărtează unul de altul din prima zi. Urcând peste,
ștergi pozele adăugate live, iar în bază rămân trimiteri către fișiere care
nu mai există. De la runda 2.6.0 tot acolo stau și **fișele tehnice** pe care
le încarci din Catalog, la „Fișă tehnică (PDF) — congelat” ori „Fișă tehnică
(PDF) — copt”: serverul le dă un nume de același fel (`fisa-14-3b9e02aa.pdf`,
din `fisa_nume_urcat()`, `api/lib/fise.php`), iar pachetul nu le cunoaște.
Fișele venite cu pachetul stau în alt folder, `fise/`, care se urcă de fiecare
dată — vezi harta de la pasul 2.

**3. `sql/001_schema.sql` nu se mai importă niciodată.**
Al doilea import al schemei se oprește cu eroarea `#1826`
(`sql/001_schema.sql:229-230`, o cheie străină adăugată neprotejat).
`sql/002_seed.sql` e din 2.0.2 numai `INSERT IGNORE` și se poate reimporta;
înainte conținea 22 de `UPDATE products` necondiționate care rescriau
prețurile, denumirile, descrierile, gramajele **și fotografiile**. Detalii
la capcana 1.

**4. Nu goli niciodată `public_html/` înainte de a urca.**
Acolo mai stau lucruri care nu sunt în pachet și pe care nimeni nu le poate
reface: `.well-known/acme-challenge` (fără el certificatul SSL nu se mai
reînnoiește, și afli abia peste 90 de zile), `cgi-bin/`, și `products/`.
Extragerea unei arhive peste un folder existent suprascrie fișierele cu același
nume și le lasă pe restul neatinse — exact ce vrei. Ștergerea prealabilă e cea
care distruge.

**5. Singurul fișier care se editează de mână pe server e `api/config.php`.**
Orice altă corectură făcută în File Manager — într-o pagină, într-un `.php`,
în `.htaccess` — dispare fără avertisment la prima urcare. Dacă e nevoie de o
schimbare permanentă în `.htaccess`, ea se scrie în sursă
(`.claude/build-deploy.mjs:367, 492, 567, 593`), nu pe server.

---

## Cazul obișnuit: s-a schimbat doar aspectul sau textul

Adică una sau mai multe pagini `.dc.html`, sau `_ds/`, sau `assets/`. Nimic în
`api/`, nicio migrare.

### 1. Construiește pachetul

```bash
node .claude/build-deploy.mjs
```

Dacă la final scrie „N probleme", **te oprești aici** — scriptul iese cu cod 1
(`:1099`). Sunt **șapte** lucruri din care se poate opri, și fiecare e scris pe
ecran cu un `!` la început:

| Ce scrie | Ce înseamnă | Unde |
|---|---|---|
| `legături nerezolvate` | o pagină trimite spre altă pagină pe care harta n-o cunoaște — pe server, butonul acela ar da 404 | `:850` |
| `lipsește … (cerut de …)` | un fișier din lista gazdei nu e pe disc | `:881` |
| `a ajuns în pachet — conține parola bazei de date` | `api/config.php` a intrat în pachet. Scriptul îl și șterge, dar tot se oprește: vrea să afli cum a ajuns acolo | `:950` |
| `fără zăvorul de după instalare` | `setup.php` sau `verifica.php` a intrat în pachet fără zăvorul lui | `:976` |
| `nume din dezvoltare rămase în pachet` | o trimitere a rămas pe numele de fișier din dezvoltare, nu pe adresa de pe server | `:1023` |
| `nu am putut citi adresele din seo/adrese.php` | tabelul de adrese al magazinului n-a putut fi citit, deci verificarea de după el ar fi mințit | `:1057` |
| `fișiere care fură o adresă a magazinului` | un fișier adevărat stă exact pe o adresă pe care ar trebui s-o servească `index.php` — Apache îl servește pe el, iar pagina aceea dispare din magazin | `:1068` |

Niciunul nu e „aproape bine": fiecare înseamnă un pachet care, urcat, strică
ceva pe server.

### 2. Vezi ce s-a schimbat față de ce e pe server

Harta sursă → pachet e fixă (`.claude/build-deploy.mjs:35-80` și `:85-92`):

| Ai modificat | Se schimbă în pachet | Urci în |
|---|---|---|
| `Site Public.dc.html` | `public_html/app.html` | `public_html/` |
| `Administrare Comenzi.dc.html` | `lab/comenzi.html` | `public_html/lab/` |
| `Panou Laborator` · `Depozit` · `Pontaj` · `Conturi` · `Jurnal` · `Statistici` · `Incasari` · `Acces` · `Ruta Sofer` · `Produse` · `Setari` | câte un `.html` în `lab/` | `public_html/lab/` |
| `Client.dc.html` | `clienti/client.html` | `public_html/clienti/` |
| **`Intrare.dc.html`** | `clienti/index.html` **și** `lab/index.html` | **două foldere** |
| **`Parola.dc.html`** | `clienti/parola.html` **și** `lab/parola.html` | **două foldere** |
| **`Recomandari.dc.html`** | `clienti/recomandari.html` **și** `lab/recomandari.html` | **două foldere** |
| **`Istoric.dc.html`** | `clienti/istoric.html` **și** `lab/istoric.html` | **două foldere** |
| `support.js` · `shared/gp-api.js` · `_ds/` · `assets/` | **câte trei copii** | **toate trei subdomeniile** |
| `image-slot.js` · `.image-slots.state.json` | o singură copie | `public_html/` |
| `seo/index.php` · `seo/sitemap.php` · `seo/adrese.php` | `public_html/index.php`, `sitemap.php`, `adrese.php` | `public_html/` |
| `fise/` (fișele tehnice, PDF) | `public_html/fise/`, o singură copie | `public_html/` |

`Intrare`, `Parola`, `Recomandari` și `Istoric` sunt **cele patru** pagini care
ajung în două locuri, iar **cele două copii nu sunt identice**: scriptul le
injectează adresa API-ului și rescrie legăturile dintre subdomenii
(`rewriteLinks()`, `.claude/build-deploy.mjs:242`). O modificare în ele cere
două urcări. `Istoric` e cea mai nouă dintre ele, de la 2.7.0: e a clientului,
care intră pe `clienti.`, și a celor din casă care scriu comenzi, care intră pe
`lab.`. Tot de la 2.7.0 sunt `Incasari` și `Acces`, numai pe `lab.`: cine intră
pe ele hotărăște serverul, după cont, nu folderul în care stă fișierul.

`adrese.php` nu e o poză și nu e un stil: e **tabelul de adrese al
magazinului**, citit și de pagină, și de harta de site. Fără el pe server,
fiecare adresă în afară de „/" primește 404 — adică tot catalogul, toate
paginile de produs și amândouă limbile.

`fise/` ține cele 21 de fișe tehnice venite cu pachetul, câte una pe produs,
toate ale laturii congelate (`fisa-tehnica-rolldog-congelat.pdf` și celelalte).
Stă numai lângă magazin: pe `lab.` și pe `clienti.` folderul nu există, iar
cine cere de acolo o adresă `fise/…` — butonul „Vezi fișa” din Catalog — e
trimis la gazda publică cu un 302, la fel ca pozele (cele două redirecționări
sunt scrise una sub alta, `.claude/build-deploy.mjs:582-590`). Tot numai la
gazda publică stau și antetele PDF-urilor — tipul `application/pdf`,
`no-cache` și `X-Robots-Tag: noindex` —, în constanta `HT_SEO` (`:492`), la
blocul „fișele tehnice (PDF)”: fișa e un document de dat clientului, nu o
pagină de găsit pe Google. Spre deosebire de `products/`, `fise/` **se urcă la
fiecare actualizare** și se înlocuiește întreg, fiindcă pe server nu scrie
nimeni în el. Fișele încărcate de tine din Catalog nu ajung acolo, ci în
`products/` (regula 2).

### 3. Scoate din pachet ce n-are voie să plece

Înainte de a face arhiva, șterge din `deploy/`:

- **`deploy/public_html/products/`** — regula 2 de mai sus. Vecinul lui,
  `deploy/public_html/fise/`, **rămâne**: fișele din el sunt ale pachetului și
  pleacă de fiecare dată.
- **`deploy/api/setup.php`** și **`deploy/api/verifica.php`** — revin în pachet
  la fiecare construire, deliberat (`:924`, motivat la `:955-964`). Amândouă au
  zăvor propriu, dar zăvorul lui `setup.php` depinde de existența unui
  administrator activ (`api/setup.php:39-48`). Șterge-le de pe server după
  fiecare urcare.
- **`.htaccess`** — la actualizările obișnuite nu-l urca deloc. Vezi capcana 4.
  Pachetul **2.6.0** nu e una obișnuită: aduce blocul PDF-urilor în
  `.htaccess`-ul din `public_html/` și trimiterea `fise/` în cele din `lab/` și
  `clienti/`. Fără ele, fișa se deschide pe magazin fără `noindex`, iar „Vezi
  fișa” din Catalog dă 404. La pachetul acesta, cele trei `.htaccess` de pagini
  se urcă, după pașii din capcana 4.

### 4. Urcă

File Manager din cPanel → intri în folderul țintă → **Upload** → apoi
**Extract** dacă ai făcut arhivă. Bifează **Show Hidden Files** în Settings,
altfel nu vezi `.htaccess` și `.image-slots.state.json`.

---

## Când se schimbă și platforma din spate

Tot ce e mai sus, plus cinci lucruri.

**Salvează `config.php` înainte.** Copiază-l undeva de unde îl poți pune înapoi.
E singurul fișier PHP editat de mână, iar o virgulă lipsă în el face `require`-ul
de la `api/index.php:30` să crape **înainte** ca `api/index.php:47` să oprească
afișarea erorilor. Rezultat: API-ul întoarce HTML în loc de JSON și **cad toate
patru subdomeniile odată**.

**Ordinea: `api/` primul.**

> `api/` → `lab/` + `clienti/` → `public_html/`

API-ul e aproape întotdeauna aditiv: rute noi, câmpuri noi, iar paginile vechi
ignoră ce nu cunosc. Paginile noi, în schimb, cer rute noi. Dacă urci întâi
paginile, clientul primește pe ecran exact textul de la `api/index.php:454` —
„Rută inexistentă: POST /requests/12/reopen" — iar comanda pur și simplu nu se
face. Cu ordinea corectă, fereastra e „API nou + pagini vechi", care nu strică
nimic.

*Excepție:* dacă îți spun explicit că o actualizare **schimbă forma** unui
răspuns, nu doar adaugă, atunci nicio ordine nu e sigură. Aceea se face
duminică dimineața, nu la prânz.

**Compară `config.example.php` cu `config.php` de pe server.** Fiindcă
`config.php` nu se suprascrie niciodată, cheile noi **nu apar singure**, iar
aplicația merge mai departe pe valorile implicite fără să se plângă. S-a
întâmplat deja o dată, la versiunea care a adus parola nouă pe e-mail: **șase
chei de sesiune și de parolă** (`session_days_client`, `session_days_staff`,
`reset_minutes`, `max_forgot_attempts`, `reset_url`, `lock_window_min`) au
trebuit adăugate cu mâna pe fiecare instalare vie. Deschide exemplul alături
și copiază ce lipsește — niciodată invers.

Versiunea care face ca tableta din laborator să nu se mai deconecteze singură
aduce încă una: **`session_days_panou`**. Lipsa ei nu strică nimic — valoarea
implicită, un an, stă în cod (`session_days_panou()`, `api/lib/auth.php`) tocmai
pentru cazul ăsta — dar copiind-o ai numărul la vedere, acolo unde îl cauți.

Runda 2.7.0 aduce în exemplu o cheie care merge pe dos: **`telegram_api_base`**
e prima pe care **nu** o copiezi. Ea spune la ce adresă pleacă mesajele de
Telegram, iar pe server trebuie să lipsească ori să rămână goală — atunci
adresa e cea adevărată, `https://api.telegram.org`. Cheia există numai pentru
probele de pe calculatorul de lucru, care mută Telegramul pe un primitor local,
ca rapoartele de încasări trimise de ele să poată fi numărate fără să ajungă pe
telefonul nimănui. Stă în fișier, și nu în Setări, dinadins: la adresa aceea
pleacă, în fiecare cerere, tokenul botului. Ce stă în Setări se schimbă
dintr-un ecran, de către oricine are dreptul lui; ce stă în `config.php`, numai
de către cine are deja serverul în mână. O valoare stricată nu cade în tăcere
pe adresa adevărată, ci oprește trimiterile cu o eroare spusă pe nume, care îți
cere să scoți cheia.

Runda 2.7.5 aduce a doua cheie de același fel: **`smtp_ca_file`**. Nici pe ea
n-o copiezi. Pe server lipsește ori rămâne goală — atunci certificatul
serverului de e-mail se verifică după certificatele sistemului, singura purtare
bună pentru site. Cheia există numai pentru probele de pe calculatorul de
lucru, care trimit e-mail printr-o căsuță locală, ca mesajele lor să poată fi
numărate fără să plece nicăieri, și e ascultată numai când gazda căsuței din
Setări e calculatorul însuși (`127.0.0.1`, `localhost`); pentru orice altă
gazdă e ca și cum n-ar fi. Stă în fișier, și nu în Setări, din același motiv ca
`telegram_api_base`: un certificat de încredere pus dintr-un ecran ar lăsa pe
oricine are ecranul să dea parola căsuței unui server străin. Datele căsuței
înseși — serverul, portul, utilizatorul, parola — **nu** stau în `config.php`:
se scriu din Setări → Notificări, în cartonașul „E-mail”. Deci pentru ca
e-mailul să plece nu ai nimic de adăugat în fișier.

**Șterge `setup.php` și `verifica.php` de pe server** după urcare.

**Prima verificare:** `https://api.goldenpastry.ro/products` în bara de adrese.

---

## Când e și o migrare de bază de date

### Copia de siguranță, imediat înainte

Nu există nicio cale de întoarcere automată — nu există fișiere „down" și nu
există rută de export în aplicație. Trei lucruri, în ordinea asta:

1. **Baza** — cPanel → Backup → *Download a MySQL Database Backup*. (Din
   phpMyAdmin, exportul poate expira pe o bază cu multe comenzi; varianta din
   cPanel rulează pe server.)
2. **Folderul cu poze** — File Manager → clic dreapta pe `public_html/products`
   → Compress → descarcă.
3. **`api/config.php`** — descarcă-l. Baza și `config.php` sunt o **pereche**:
   dacă restaurezi baza peste un `config.php` cu altă cheie de criptare, codul
   tabletelor, tokenurile de notificare și, de la 2.7.5, parola căsuței de
   e-mail devin ilizibile, tăcut.

O restaurare înseamnă că **tot ce a intrat între backup și restaurare dispare** —
comenzi, cereri de pe site, mișcări de stoc. De-asta o actualizare cu migrare
se face când nu intră comenzi.

### Ordinea: baza întâi, apoi fișierele

> backup → import SQL → `api/` → `lab/` + `clienti/` → `public_html/` → verificări

**De ce baza întâi.** În fereastra dintre import și urcare rulează codul vechi
peste schema nouă. Codul vechi nu știe de coloana nouă și nu o cere;
`INSERT`-urile lui nu o numesc, deci se aplică valoarea implicită. Aplicația
funcționează normal tot intervalul.

**De ce nu invers.** Codul nou cere o coloană care încă nu există → PDO ridică
excepție → `api/index.php:455-461` întoarce „Eroare internă." Nu e o pagină
urâtă, e o **oprire**: formularul de comandă cade la trimitere, panoul nu mai
încarcă lista. Nu există coadă și nu există reîncercare — comenzile din acel
interval se pierd.

### Cum se importă

phpMyAdmin → alegi baza → fila **Import** → *Choose File* → fișierul din `sql/`
→ **Go**. Nu schimba „Format" și nu bifa „Partial import".

**Din phpMyAdmin, nu din linia de comandă** — motivul e scris în
`sql/003_route_order.sql:11-15`: clientul `mysql` de pe Windows citește fișierul
în codepage-ul consolei, nu UTF-8, iar „Predată" ajunge „Predat─â", după care
etapa nu se mai potrivește cu nimic și comenzile nu se mai pot închide.

Bară verde → gata. Un fișier scris corect poate raporta și `0 rows affected`:
înseamnă că modificarea exista deja. **Bară roșie** → te oprești acolo și îmi
trimiți textul erorii cu tot cu număr (`#1054`, `#1060`, `#1062`, `#1826`).
Migrarea s-a oprit la instrucțiunea aceea: ce era înainte s-a aplicat, ce era
după nu.

### Ce se poate reimporta și ce nu

| Fișier | Reimport |
|---|---|
| `001_schema.sql` | **NU** — `:229-230` dă `#1826`. Nu pierzi date, dar nu știi ce s-a aplicat. |
| `002_seed.sql` | Da, din 2.0.2 (numai `INSERT IGNORE`). Înainte: **NU** — vezi capcana 1. |
| `003_route_order.sql` | Da — fiecare pas e învelit într-un test pe `information_schema`. **Dar** `:104` readuce grupele de produse pe care le-ai șters din pagina Produse. |
| `004_variante_pe_pozitie.sql` | Da, dar fără motiv nu. |
| `005_parola_schimbata.sql` | Da, complet sigur — nu atinge niciun rând. |
| `006_retete_rezervari.sql` | Da — fiecare pas se uită întâi în `information_schema`. Nu scrie în `settings` și nu șterge nimic. |
| `007_update_roles_referral.sql` | Da — fiecare pas se uită întâi în `information_schema`. Rolurile noi intră la **coada** listei ENUM, deci conturile de azi nu-și schimbă rolul. |
| `008_comisioane_recomandari.sql` | Da — aditiv. Nu calculează nimic pe comenzile de dinainte: un `UPDATE` peste istoric ar face o datorie de bani către oameni adevărați. Cere 007 aplicată. |
| `009_pontaj_si_roluri.sql` | Da — aditiv, ca 007. Cere 008 aplicată. |
| `010_origine_cont.sql` | Da — fiecare pas se uită întâi în `information_schema`. Completarea coloanei noi din jurnal are `self_registered = 0` în `WHERE`, deci la a doua rulare n-are ce marca. Nu cere nicio migrare anterioară. |
| `011_reducere_pachet_test.sql` | Da — aditiv, fiecare pas se uită întâi în `information_schema`. Nu atinge niciun rând existent: coloanele noi pornesc `NULL`, iar comenzile de dinainte rămân exact cum sunt. Nu cere nicio migrare anterioară. |
| `012_comision_scos.sql` | Da — aditiv, fiecare pas se uită întâi în `information_schema`. Adaugă pe rândul de comision cine l-a scos din socoteală și când (`dismissed_at`, `dismissed_by`), plus indexul după care se citesc listele. Nu șterge și nu rescrie niciun rând. Cere `008` înaintea lui, fiindcă atinge tabelul făcut acolo. |
| `013_anunturi_pe_canal.sql` | Da — cele trei coloane noi (`notifications.event`, `order_requests.decided_via`, `order_requests.snoozed_until`) se uită întâi în `information_schema`, iar setările intră cu `INSERT IGNORE`, deci a doua rulare nu calcă peste ce ai bifat. Adaugă lista de anunțuri a fiecărui canal și cele patru setări ale butoanelor din Telegram. Singurul `UPDATE` marchează drept `order_new` anunțurile vechi legate de o comandă și prinde numai rândurile încă nemarcate. Listele se seamănă cu exact ce se trimitea până acum (`["request_new","order_new"]`), deci actualizarea nu pornește singură niciun mesaj nou. Nu cere nicio migrare anterioară. |
| `014_pontaj_laborator.sql` | Da — aditiv, fiecare pas se uită întâi în `information_schema`. Adaugă coloana `users.lab_pin` (codul de pontaj, ținut hașurat cu `password_hash`, niciodată în clar) și tabelul `lab_shifts` cu `CREATE TABLE IF NOT EXISTS`. Lărgește lista de roluri cu `utilizator_lab`, la **coada** listei ENUM, deci conturile de azi nu-și schimbă rolul. Nu atinge niciun rând existent și nu cere nicio migrare anterioară. |
| `015_mesaje_laborator.sql` | Da — face un tabel nou, `lab_messages`, cu `CREATE TABLE IF NOT EXISTS`, și nu atinge nimic altceva: nicio coloană adăugată, niciun rând schimbat, nicio listă de roluri lărgită. La a doua rulare nu are ce face. Nu cere nicio migrare anterioară. Anunțurile deja puse rămân pe ecran: rândurile nu se șterg niciodată, li se pune doar termenul pe clipa în care cineva le ia jos. |
| `016_zona_cont.sql` | Da — fiecare pas se uită întâi unde trebuie. Adaugă coloana `users.zone` (zona firmei, ca să nu mai fie cerută la fiecare comandă) numai dacă lipsește din `information_schema`. Pune zona „În afara Bucureștiului și Ilfovului" la **coada** listei tale de zone, și numai dacă nu e deja acolo (`JSON_SEARCH`), deci zonele adăugate de tine din Setări rămân neatinse — nu e un `UPDATE` cu lista scrisă în fișier, greșeala pe care a făcut-o `003` și pe care a trebuit s-o luăm înapoi. **Nu atinge `delivery_zone_coords`**: zona nouă rămâne dinadins fără punct pe hartă, fiindcă „restul țării" nu e un loc, iar lipsa punctului e chiar semnalul după care ruta o scoate din ordonare și o arată aparte. Completează zona conturilor de client din zona ultimei lor comenzi. Amândoi pașii cu date — zona în listă și zona pe conturi — se fac **numai în rularea care adaugă coloana** (coloana se numără în `information_schema` înainte de `ALTER`): orice rulare de după o găsește deja acolo și nu mai atinge nici lista, nici conturile. Așa o zonă ștearsă de tine de pe un cont rămâne ștearsă, iar zona din afara orașului, redenumită sau scoasă din Setări, nu se mai întoarce sub numele vechi la fiecare actualizare. Înainte, paza era `u.zone IS NULL` și „numai dacă numele nu e deja acolo" — iar ștergerea din Conturi scrie tocmai NULL, și o redenumire scoate tocmai numele. Urmarea: zona de pe cont se ia din comenzi o singură dată pe instalare; un client făcut după aceea fără zonă și-o alege singur în formular, ori i-o pui tu din Conturi. Nu cere nicio migrare anterioară. |
| `017_procent_recomandare.sql` | Da — scrie o singură setare și nimic altceva: nicio coloană, niciun tabel, niciun rând de comandă atins. Seamănă rândul `referral_rate_default` dacă lipsește (`INSERT IGNORE`, cu valoarea 5) și îl urcă la **5**, dar **numai dacă rândul e încă cel semănat la instalare**: paza e `updated_by IS NULL`, adică „n-a umblat niciun om la el". Dacă ai pus tu vreodată o cifră din Setări — chiar și zero, dinadins — migrarea te ocolește și la prima rulare, și la a zecea. Comisioanele deja socotite **nu** se ating: procentul se îngheață pe fiecare comandă la nașterea ei, iar îndreptarea rândurilor vechi se face din panoul „Recomandări", la apăsarea ta, nu pe furiș dintr-un fișier `.sql`. Nu cere nicio migrare anterioară: dacă `008` n-a apucat să semene rândul, îl face ea. Ea e și migrarea pe care o numește panoul sub caseta „Procent de recomandare", când rândul lipsește. |
| `018_adresa_si_program.sql` | Da — scrie două setări și nimic altceva, cu `INSERT IGNORE`: nicio coloană, niciun tabel, niciun rând de comandă atins. Seamănă rândurile `company_address` și `company_hours`, care n-au existat niciodată, deși panoul Setări avea casetele lor de mult, iar pagina publică le citea. Valorile semănate sunt chiar cele scrise în cod ca temei de rezervă (`api/lib/firma.php`, `GP_FIRMA_ADRESA` și `GP_FIRMA_PROGRAM`), ca site-ul să arate la fel în clipa actualizării. La a doua rulare nu se întâmplă nimic: `INSERT IGNORE` trece pe lângă un rând care există, deci adresa pe care ai scris-o tu din Setări **rămâne a ta**. Spre deosebire de `017`, aici nu e nevoie de niciun `UPDATE` cu pază — n-a existat o valoare semănată greșit, ci numai un rând care lipsea. Nu cere nicio migrare anterioară. |
| `019_jeton_cerere.sql` | Da — face un tabel nou și nimic altceva, cu `CREATE TABLE IF NOT EXISTS`: nicio coloană adăugată altundeva, niciun rând de comandă atins. Tabelul `request_claims` ține amprentele jetoanelor cu care se leagă cererea trimisă de pe site de contul făcut imediat după ea. La a doua rulare nu se întâmplă nimic: `IF NOT EXISTS` sare peste tot ce e înăuntru, chei străine cu tot. Cere `001` (are chei străine spre `order_requests` și `users`). |
| `020_rang_client.sql` | Da — pune un index pe `orders` și nimic altceva, după ce întreabă `information_schema` dacă nu cumva e deja acolo: niciun tabel, nicio coloană, niciun rând atins. E prima migrare care **se poate uita fără pagubă**: fără ea insignele de client ies la fel, doar mai încet. Cere `001`. |
| `021_raspundere.sql` | Da — patru bucăți, fiecare cu paza ei: indexul și coloana se uită întâi în `information_schema`, iar cele două tabele intră cu `CREATE TABLE IF NOT EXISTS`. Nu atinge niciun rând existent: coloana nouă `users.telegram_user_id` pornește `NULL` pe toate conturile, iar tabelele se nasc goale. Aduce răspunderea — cine a scris comanda (indexul `ix_orders_autor`, coloana de Telegram, tabelul `telegram_links`) și cine era de tură când s-a făcut (`lab_production`). **Jumătatea care se poate uita fără pagubă e numai indexul**, ca la `020`: fără el lista de comenzi iese la fel, doar mai încet. Celelalte trei nu: fără coloană, nimeni nu-și poate lega contul de Telegramul lui, deci fiecare apăsare din grup e a unui om „cu contul nelegat” — până la 2.7.5 ieșea pe numele celui care a legat botul, iar de la 2.7.5 poartă numele lui de pe Telegram și vorba „cont nelegat” (v. `036`); fără `telegram_links`, legarea Telegramului nu se poate nici măcar porni; fără `lab_production`, raportul de producție pe tură iese **gol, fără eroare** — adică se descoperă la prima plată, nu la actualizare. `UNIQUE`-ul de pe `telegram_user_id` nu poate ciocni la import: în MySQL `NULL` nu se ciocnește cu `NULL`, iar la actualizare toate conturile sunt `NULL`. Cere `001` (chei străine spre `users` și `orders`) și `014` (coloana nouă se pune `AFTER lab_pin`). |
| `022_cerere_de_la_personal.sql` | Da — patru coloane, fiecare cu paza ei în `information_schema`, plus indexul și cheia străină, în ordinea asta (cheia pusă înaintea indexului ar fi făcut MySQL să-și scrie singur un index fără nume ales, iar al nostru ar fi rămas al doilea, nefolosit). Nu atinge niciun rând existent: toate patru sunt `NULL` sau cu implicit, deci o cerere de pe site scrisă înaintea migrării rămâne întreagă. Aduce cererea scrisă de personal — de la hotărârea din 18 septembrie 2026, comanda intră de-a dreptul în sistem numai când o scrie un administrator, un manager sau fondatorul; restul scriu o CERERE. Drumul cererii e cel al site-ului, dar el purta mai puțin decât o comandă, iar ce nu purta se pierdea tăcut între formular și acceptare: **modul de onorare** (agentul alegea „Curier", iar la acceptare `route_requests_accept()` cădea pe `default_fulfillment()`, adică pe altceva), **urgent** și **la oră fixă** (se puteau cere numai la acceptare, deci graba știută de cel care a luat telefonul nu ajungea la cel care hotărăște) și **cine a scris-o** (`created_by`: fără ea, comanda născută din cererea agentului ar fi purtat numele administratorului care a apăsat „Acceptă", iar agentul ar fi dispărut din propria lui muncă). Coloana `created_by` ține două întrebări deodată — „cine" și „de unde": o cerere de pe site n-are autor, deci comanda născută din ea nu mai e însemnată „site" pe nedrept. Cere `001` (cheie străină spre `users`) și se rulează după `021`. |
| `023_laboratorul_si_livrarea.sql` | Da — două coloane, fiecare cu paza ei în `information_schema`; niciun index, nicio cheie, niciun rând de date. Nu atinge nimic din ce există: `orders.lab_seq` se naște `NULL` („nearanjată"), iar `users.poate_confirma_livrarea` se naște `0`, pentru toți. `lab_seq` mută ordinea de lucru a laboratorului din browserul fiecărei tablete pe server — până acum ea stătea în `localStorage`, deci brutarul care aranja ziua aranja numai pentru el, iar colegul de lângă el vedea altceva; și, fiindcă împărțirea cantităților produse merge DE SUS ÎN JOS pe lista aceea, aceeași apăsare umplea alte comenzi, după tableta de pe care s-a apăsat. E soră cu `route_seq` din `003` și scrisă la fel (`NULL`, nu `0`, fiindcă `0` e o poziție adevărată — cea dintâi), dar nu se poate refolosi aceea: `route_seq` e ordinea OPRIRILOR mașinii, pusă de birou și păzită de `require_admin()`, iar ordinea în care mașina trece pe la clienți n-are nimic de-a face cu ordinea în care laboratorul coace. `poate_confirma_livrarea` e semnul fără de care serverul refuză etapa finală — „Predată" înseamnă *ajunsă la client*, și o punea oricine din personal: laboratorul o apăsa când termina de ambalat, iar comanda apărea livrată înainte să fi urcat în mașină. **Nicio umplere înapoi, dinadins**: toți se nasc fără semn, iar patronul bifează șoferul din „Conturi". O umplere „ca să nu înrăutățim" ar fi pus semnul tocmai pe contul comun al tabletelor — adică pe contul din pricina căruia s-a cerut lucrarea —, iar regula ar fi plecat la drum nefăcând nimic, fără ca nimeni să bage de seamă. Administratorul și fondatorul îl au din cod (`poate_confirma_livrarea()`, `api/lib/auth.php`), deci biroul nu se poate încuia pe dinafară. Cere `001` și se rulează după `022`. |
| `024_doua_cataloage.sql` | Da — trei coloane pe `products`, fiecare cu paza ei în `information_schema`; niciun index, nicio cheie străină. Are însă și un pas care **scrie date** — umplerea celor două bife —, iar acela e zăvorât cu `@coloane_noi`, numărat ÎNAINTE de `ALTER`-e: la a doua rulare `UPDATE`-ul nici nu se pregătește. Fără zăvor, fiecare actualizare ar fi pus la loc, lună după lună, fiecare latură pe care patronul o stinsese cu mâna — exact pățania lui `016`, și de-aia `.claude/test-migrari-repetate.mjs` o are și pe asta sub ochi. Umplerea e **prudentă dinadins**: `in_frozen = in_baked = (amândouă prețurile scrise)`, deci migrarea **nu mișcă nimic pe vitrină** — cine se vedea înaintea ei se vede și după, cine era ascuns rămâne ascuns. Ce se schimbă e numai vorba cu care panoul îl arată: „nu e în niciun catalog" în loc de „îi lipsește un preț". Forma îndrăzneață (`in_frozen = price_frozen IS NOT NULL`) ar fi ghicit apartenența din preț — tocmai ce a hotărât patronul să nu se facă — și ar fi publicat produse pe jumătate completate în secunda importului, fără niciun ecran între el și site. Nu atinge `photo`, nu atinge `active`, nu adaugă nicio grupă. Cere `001` și se rulează după `023`. |
| `025_registru_migrari.sql` | Da — face tabelul `schema_migrations` cu `CREATE TABLE IF NOT EXISTS` și scrie un singur rând, cu `INSERT IGNORE`: a doua rulare nu face nimic. Nu atinge nicio altă tabelă și niciun rând de date. E fișierul care **se rulează înaintea celorlalte**, nu la rândul lui: se cheamă `025`, deci în ordinea numelui ar veni după `003`–`024`, iar atunci primele douăzeci și două ar trece pe lângă un tabel care încă nu există și și-ar scrie numele abia la actualizarea următoare. Cele trei drumuri de urcare îl rulează o dată la început, apoi bucla — se poate, fiindcă e idempotent. **Ce NU face: nu se umple cu mâna.** S-ar putea scrie în el, dintr-o suflare, numele tuturor migrărilor până la `024` („dacă ai ajuns la 025, au rulat și alea") — adevărat când rulează bucla, minciună când cineva îl importă singur, din phpMyAdmin, pe o bază veche. Un registru care minte e mai rău decât niciun registru. Nu cere nimic și se rulează oriunde, pe orice bază. |
| `026_cheie_cerere.sql` | Da — o coloană `idempotency_key` pe `order_requests` și un index unic `uq_req_cheie` peste ea, fiecare cu paza lui în `information_schema`: a doua rulare le găsește și nu face nimic. Nu scrie și nu schimbă niciun rând de date. Coloana se naște `NULL` și stă `AFTER created_at` fiindcă acela e ultimul câmp din `001_schema.sql`, deci e singurul loc care există pe **orice** bază, oricât de veche. Indexul unic trece peste cererile de până acum fără să se plângă, oricâte ar fi: în SQL `NULL` nu se ciocnește cu `NULL`, deci vechile cereri — toate fără cheie — rămân neatinse, și la fel cele care vin mai târziu de la un telefon cu pagina veche în memorie. **Ce NU face: nu curăță cererile duble adunate înainte.** Ea oprește perechea următoare, nu o desface pe cea de dinainte — două cereri vechi, identice, rămân două rânduri, fiindcă n-avem de unde ști dacă omul a apăsat de două ori sau chiar a vrut două comenzi. Și **nu ea alege ce se întâmplă la ciocnire**: indexul doar spune „cheia asta a mai fost"; răspunsul — cererea dintâi, întoarsă ca și cum ar fi acum — e scris în `api/routes/requests.php`. O bază care ia migrarea fără fișierele noi nu pățește nimic: coloana rămâne goală și nimeni n-o citește. Nu cere nimic și se rulează oriunde. |
| `027_poza_fiecarei_laturi.sql` | Da — și e singura de până acum care **nu atinge deloc schema**: nici tabel, nici coloană, nici index. Ea copiază o dată `products.photo` în `products.photo_baked` acolo unde a doua e goală, adică face a fiecărei laturi poza pe care până în 2.4.1 o ÎMPRUMUTA de la cealaltă. Zăvorul nu e în `information_schema` — n-ar avea ce căuta acolo, fiindcă nu se schimbă nimic în schemă —, ci în `settings`: `migration_027_poza_pe_latura`, scris la coada ei. A doua rulare îl găsește și nu mai copiază nimic, deci o poză pe care ai schimbat-o între timp la gata de servire rămâne a ta. **Ce NU face: nu pune la loc pozele de după ea.** Un produs născut după migrare are `photo_baked` gol până îi urci una — și așa și trebuie, de vreme ce împrumutul s-a tăiat. O bază care ia migrarea fără fișierele noi nu pățește nimic: cele două coloane ajung să poarte același nume de fișier, iar codul vechi arăta oricum poza aceea pe amândouă laturile. Nu cere nimic și se rulează oriunde. |
| `028_preturi_pe_trepte.sql` | Da — zece coloane, fiecare cu paza ei în `information_schema`: șase pe `products` (`price_frozen_b`, `price_frozen_c`, `price_baked_b`, `price_baked_c`, `prag_b`, `prag_c`) și câte două pe `order_items` și pe `order_request_items` (`treapta`, `prag_treapta`), plus patru setări ale catalogului, cu `INSERT IGNORE`. A doua rulare nu face nimic și nu calcă ce ai ales între timp în Setări. Nu atinge niciun rând existent: coloanele noi se nasc `NULL`, adică „nicio treaptă" pe produs — fiecare produs rămâne cu prețul lui unic, exact cum îl vindeai — și „treapta A" pe fiecare poziție de comandă de dinainte. Codul le cere **pe toate zece** deodată (`catalog_trepte_supported()`): pe o bază cu jumătate din migrare casa vinde ca înainte, numai cu prețul A, în loc să scrie comenzi tarifate la B fără să țină minte pragul care le-a câștigat. Nu cere nicio migrare anterioară; registrul (`025`) îl folosește numai dacă îl găsește. |
| `029_numere_in_catalog.sql` | Da — și, ca `027`, **nu atinge deloc schema**: nici tabel, nici coloană, nici index. Dă o dată fiecărui produs — și celor scoși din catalog, care își păstrează locul pentru când se întorc — numărul din colțul pozei, 1, 2, 3…, în ordinea în care au fost puse (`id`), în coloana `products.sort`, care exista din `001` și ținea până acum pași de zece. Zăvorul e tot în `settings`: `migration_029_numere_catalog`, scris la coada ei, cu data rulării. A doua rulare îl găsește și nu mai renumerotează nimic — fără zăvor, fiecare actualizare ar fi pus la loc numerele de la import și ar fi șters, lună de lună, pe cele alese de patron în fila Produse. Pe o bază din care n-a fost șters niciun produs numerele ies exact cele de dinainte (01 rămâne 01, 19 rămâne 19), deci vitrina nu se mișcă în clipa importului; unde s-a șters unul, cele de după el urcă cu un loc și închid golul. **Ce NU face: nu pune un index unic pe `sort`.** Unicitatea o ține serverul, sub lacăt, la fiecare mutare; un index ar fi făcut orice produs scris cu mâna în bază, fără `sort`, să cadă la import pe un zero dublu. Nu schimbă cheile: coșurile, pozele și comenzile trimit tot la ele. O bază care ia fișierele noi fără migrare nu pățește nimic: magazinul arată cheia bazei, ca înainte, iar fișa produsului refuză lămurit schimbarea numărului (`numbers_unavailable`, cu numele fișierului). Cere `001` și MySQL 8.0 sau mai nou (`ROW_NUMBER()`); registrul (`025`) îl folosește numai dacă îl găsește. |
| `030_gata_de_livrare.sql` | Da — și, ca `027` și `029`, **nu atinge deloc schema**: nici tabel, nici coloană, nici index. Pune o dată numele „Gata de livrare" în lista ta de etape (`order_statuses`), **chiar înaintea etapei finale** — cea numită în `order_status_done`, oricum s-ar chema ea la tine —, și numai dacă nu e deja acolo. Nu e un `UPDATE` cu lista scrisă în fișier, greșeala lui `003`: celelalte etape rămân cum le-ai lăsat, cu numele și cu ordinea lor. Dacă setarea etapei finale lipsește ori arată spre un nume care nu mai e în listă, locul e cel dinaintea ultimei etape care nu e anularea — aceeași regulă după care serverul își alege singur etapa finală (`status_done()`). Zăvorul e în `settings`: `migration_030_gata_de_livrare`, scris la coada ei, cu data rulării. A doua rulare îl găsește și nu mai atinge lista — fără zăvor, o etapă redenumită ori scoasă de tine din Setări s-ar întoarce sub numele vechi la fiecare actualizare, exact pățania lui `016`. Pe o instalare nouă n-are ce pune: lista din `002` are deja etapa. **Ce NU face: nu mută nicio comandă.** Cele aflate la „La ambalare" în clipa importului rămân acolo; laboratorul mai apasă o dată pe fiecare, și abia atunci se aprinde butonul curierului. Nu atinge `order_status_done` și `order_status_cancelled`, iar pe lângă o listă care nu e JSON trece fără s-o atingă. O bază care ia fișierele noi fără migrare nu pățește nimic: laboratorul se oprește tot la etapa dinaintea celei finale — numai că aceea e atunci ambalarea, deci de la „La ambalare” comanda e a curierului. Cere `001` și funcțiile JSON ale bazei (MySQL 5.7 sau mai nou); registrul (`025`) îl folosește numai dacă îl găsește. |
| `031_fise_tehnice.sql` | Da — două coloane pe `products`, `sheet_frozen` și `sheet_baked`, fiecare cu paza ei în `information_schema`; niciun index, nicio cheie străină. În ele stă calea fișei tehnice a fiecărei laturi; `NULL` înseamnă „fără fișă", și atunci magazinul nu arată niciun buton. Are și un pas care **scrie date**: leagă o dată, după `slug`, cele 21 de fișe venite cu pachetul de latura congelată a produselor lor, și numai acolo unde coloana e goală. Pasul e zăvorât în `settings` cu `migration_031_fise_tehnice`, scris la coada ei: a doua rulare îl găsește și nu mai leagă nimic — fără zăvor, o fișă scoasă de tine din Catalog s-ar întoarce la fiecare actualizare, fiindcă tocmai „goală" e starea pe care ai ales-o. **Ce NU face: nu scrie nimic în `sheet_baked`.** Coapte împrumută fișa de la congelate cât n-are una a ei, iar împrumutul se face în cod (`latura_fisa()`, `api/lib/laturi.php`), nu în bază — scris în bază, n-ar mai putea fi deosebit de o fișă pusă dinadins. Nu atinge greutatea, prețul ori descrierea niciunui produs și nu mută niciun fișier: scrie numai căi. Un produs pus de tine în Catalog cu altă adresă decât cea din pachet (alt `slug` — de pildă unul făcut din nou sub alt nume) nu e găsit și rămâne fără fișă; i se încarcă una din Catalog. O bază care ia fișierele noi fără migrare nu pățește nimic: magazinul nu arată butonul fișei, iar încărcarea unei fișe e refuzată lămurit (`fise_unavailable`, cu numele fișierului). Cere `001`; registrul (`025`) îl folosește numai dacă îl găsește. |
| `032_plati_si_prag.sql` | Da — opt coloane, fiecare cu paza ei în `information_schema`: șapte pe `orders` (`plata`, `plata_scadenta`, `plata_la`, `plata_de`, `plata_suma`, `plata_nota`, `plata_amintire_la`) și una pe `users` (`prag_neplatite`); două indexuri pe `orders` (`ix_orders_plata`, `ix_orders_plata_la`), păzite la fel; și șapte setări, cu `INSERT IGNORE`. A doua rulare nu face nimic și nu calcă ce ai ales între timp în „Încasări” ori în Setări — pragul, chatul, cele două ore. Aduce condica banilor: starea plății fiecărei comenzi — neplătită, amânată, plătită ori plătită prin bancă —, cu scadența, clipa, suma și omul care a bifat-o, și pragul de comenzi neplătite: cel general (`plati_prag_implicit`, semănat cu 2) și cel de pe cont (`prag_neplatite`: gol înseamnă „pragul general”, zero înseamnă „fără limită”). Comenzile noi se nasc neplătite, din implicitul coloanei, deci nicio scriere de comandă nu trebuie atinsă. Are și un pas care **scrie date**: trece o dată drept plătite comenzile aflate la etapa finală — cea numită în `order_status_done`, oricum s-ar chema ea la tine — și cu ziua **trecută**, iar la `plata_suma` le scrie totalul lor. Fără judecata asta, în ziua urcării fiecare client vechi ar fi apărut cu tot istoricul lui „de plată”, iar pragul l-ar fi oprit de la prima comandă nouă. Pasul e zăvorât în `settings` cu `migration_032_plati_si_prag`, scris la coada ei: a doua rulare îl găsește și nu mai judecă nimic — fără zăvor, o comandă predată pe care ai trecut-o tu înapoi pe „neplătită” ar fi făcută la loc „plătită” la fiecare actualizare. Sub același zăvor mai face o scriere, singura care nu ține de bani: trece pe `patru` vederea cu care se deschide magazinul pe telefon și tabletă (`catalog_vedere_telefon`), numai dacă mai ținea `unu`, valoarea de pornire a lui `028`; o vedere aleasă de tine din Setări nu e călcată, nici la import, nici la actualizările de după. **Ce NU face: nu scrie `plata_la`.** Nu se știe când s-au plătit comenzile vechi, iar o dată pusă din burtă le-ar fi băgat pe toate în „încasat azi”. O comandă predată chiar în ziua importului rămâne neplătită, la fel și toate cele care n-au ajuns la etapa finală; cele anulate nu se numără oricum. Dacă setarea etapei finale lipsește ori e goală, nu marchează nimic — nu ghicește —, iar semnul se scrie oricum, deci nici mai târziu nu mai marchează. Nu atinge etapa, totalul ori ziua niciunei comenzi, nu pune prag pe niciun cont și nu trimite nimic pe Telegram. Ce nu se potrivește — o comandă predată și neîncasată — se îndreaptă cu mâna, după import, din „Încasări”. O bază care ia fișierele noi fără migrare nu pățește nimic: nicio comandă nu e oprită de prag și nimic nu se numără, iar paginile care au nevoie de plăți sunt refuzate lămurit (`plati_unavailable`, cu numele fișierului). Cere `001`; registrul (`025`) îl folosește numai dacă îl găsește. |
| `033_acces.sql` | Da — face un tabel nou și nimic altceva, cu `CREATE TABLE IF NOT EXISTS`: nicio coloană adăugată altundeva, niciun rând scris, nicio setare. Tabelul `role_rights` ține bifele pe care fondatorul le mută în „Acces” — cine intră pe ce panou și cine face ce faptă —, și **numai** pe cele schimbate față de pornire: cine are ce din pornire stă în cod (`GP_DREPT_IMPLICIT`, `api/lib/auth.php`), nu în bază. De-aceea tabelul se naște gol, iar **gol înseamnă „ca înaintea ei”**: cu migrarea importată și nicio bifă mișcată, fiecare rol intră exact unde intra până la 2.7.0. La a doua rulare nu se întâmplă nimic: `IF NOT EXISTS` sare peste tot ce e înăuntru, deci bifele mutate între timp rămân ale tale. **Ce NU face: nu atinge rolul niciunui cont** — `users.role` rămâne cum e. Și nu are semn în `settings`: nu face nimic „o singură dată”, deci n-are ce ține minte; că a rulat se vede din tabel și din registru. O bază care ia fișierele noi fără migrare nu pățește nimic: fiecare rută judecă după pornire, iar salvarea unei bife e refuzată lămurit (`acces_unavailable`, cu numele fișierului). Cere `001` (cheie străină spre `users`); registrul (`025`) îl folosește numai dacă îl găsește. |
| `034_costuri.sql` | Da — face un tabel nou și nimic altceva, cu `CREATE TABLE IF NOT EXISTS`: nicio coloană adăugată altundeva, niciun rând scris, nicio setare. Tabelul `stock_item_prices` ține **istoricul** prețurilor de cumpărare ale articolelor din depozit — cât costă făina, ouăle, untul, și din ce zi (`valid_from`) —, nu numai prețul de azi: costul unei zile se socotește cu ultimul preț de dinaintea ei, deci trecutul rămâne cu prețul de atunci. Un singur preț pe zi, pe articol (`uq_item_prices_day`): a doua scriere din aceeași zi e o corectură a primei, nu un al doilea preț. Lângă preț stă unitatea articolului din clipa scrierii, ca un articol trecut mai târziu din kg în g să nu facă fiecare cost să sară de o mie de ori fără să se vadă. Tabelul se naște gol: migrarea nu scrie niciun preț, iar „n-are preț” înseamnă „n-are niciun rând” — zero e un preț adevărat, al unui articol primit pe gratis. La a doua rulare nu se întâmplă nimic, deci prețurile scrise între timp rămân. **Ce NU face: nu atinge articolele, rețetele ori mișcările din depozit.** Nu are semn în `settings`. O bază care ia fișierele noi fără migrare nu pățește nimic: depozitul, rețetele și Statisticile răspund ca înaintea ei, fără costuri, iar citirea și scrierea unui preț sunt refuzate lămurit (`costuri_unavailable`, cu numele fișierului). Cere `001` (chei străine spre `stock_items` și `users`); registrul (`025`) îl folosește numai dacă îl găsește. |
| `035_comenzi_sterse.sql` | Da — face un tabel nou și nimic altceva, cu `CREATE TABLE IF NOT EXISTS`: nicio coloană adăugată altundeva, niciun rând scris, nicio setare. Tabelul `orders_deleted` e o arhivă: ține **poza** unei comenzi anulate care a fost apoi ștearsă — antetul, fiecare poziție, cine a șters-o și când —, ca ștergerea să se poată citi și după ce rândul a plecat din `orders`. Se naște gol: migrarea **nu șterge și nu mută nicio comandă**. La a doua rulare nu se întâmplă nimic, deci pozele adunate între timp rămân. **N-are nicio cheie străină, dinadins**: comanda pe care o descrie nu mai există, iar clientul ori contul care a șters-o pot dispărea și ei — o cheie străină ar fi șters poza odată cu clientul, ori i-ar fi golit câmpurile. O comandă se șterge o singură dată (`uq_orders_deleted_order`), dar numărul ei se poate refolosi: numerotarea e pe zi, iar ștergerea îl eliberează, deci poza își ține referința ei. Nu are semn în `settings`. O bază care ia fișierele noi fără migrare nu pățește nimic: anularea unei comenzi merge ca înaintea ei, iar ștergerea uneia anulate e refuzată lămurit (`comenzi_sterse_indisponibil`, cu numele fișierului), fără să atingă comanda. Nu cere nimic și se rulează oriunde; registrul (`025`) îl folosește numai dacă îl găsește. |
| `036_telegram_oameni.sql` | Da — două coloane pe `order_requests` (`handled_tg_id`, `handled_tg_name`), fiecare cu paza ei în `information_schema`, și un tabel nou, `telegram_oameni`, cu `CREATE TABLE IF NOT EXISTS`. Nu scrie și nu schimbă niciun rând: coloanele se nasc `NULL`, iar tabelul se naște gol. Aduce răspunsul la întrebarea „cine a apăsat, cu adevărat, pe Telegram?”. Botul stă într-un grup cu oamenii casei, iar când apăsa „Acceptă” cineva care nu-și legase contul de Telegramul lui, mesajul, panoul și Jurnalul spuneau că a acceptat cel care a legat botul. De la 2.7.5 apăsarea aceea merge mai departe pe drepturile contului care a legat botul, dar poartă **numele celui care a apăsat**, cel de pe Telegram, și vorba „cont nelegat”. Cele două coloane țin numele acela pe cerere — curățat și tăiat de server înainte de a fi scris —, lângă `handled_by`, care rămâne contul pe ale cărui drepturi s-a lucrat. Tabelul ține oamenii văzuți apăsând în grup, un rând de om: din el alege fondatorul, în Setări → Notificări, la „Oameni din grup”, pe cine să lege de ce cont. Legătura însăși rămâne unde e de la `021`, pe `users.telegram_user_id`; tabelul nu are coloană de legătură, dinadins, ca același lucru să nu fie scris în două locuri. **N-are chei străine**: numărul de Telegram nu e al niciunui rând din bază, iar numele de pe cerere trebuie să rămână și după ce omul pleacă din grup. **Ce NU face: nu îndreaptă trecutul.** Hotărârile luate până la ea rămân fără semn — nu se mai poate afla cine a apăsat atunci, iar un nume ghicit ar fi o minciună scrisă în tabel. Nu leagă și nu dezleagă pe nimeni și nu are semn în `settings`. O bază care ia fișierele noi fără migrare **nu se poartă ca înaintea ei**, și e bine de știut dinainte: apăsarea unui om cu contul nelegat e **refuzată**, cu un mesaj pe telefon care numește fișierul, fiindcă numele lui n-ar avea unde se scrie, iar hotărârea ar ieși iar pe numele altcuiva. Cine are contul legat lucrează ca până acum; lista „Oameni din grup” e refuzată și ea lămurit (`telegram_oameni_unavailable`, cu numele fișierului). Cere `001` (coloanele se pun `AFTER handled_at`); legarea unui om de un cont cere și `021`. Registrul (`025`) îl folosește numai dacă îl găsește. |
| `037_posta_smtp.sql` | Da — scrie cinci setări și nimic altceva, cu `INSERT IGNORE`: nicio coloană, niciun tabel, niciun rând de comandă atins. Seamănă rândurile căsuței de e-mail a firmei: `mail_smtp_host`, `mail_smtp_port`, `mail_smtp_user`, `mail_smtp_password` și `mail_smtp_security`. La a doua rulare nu se întâmplă nimic: `INSERT IGNORE` trece pe lângă un rând care există, deci o căsuță completată de tine din Setări **rămâne a ta, cu parola ei cu tot**. Aduce locul în care se scrie căsuța prin care pleacă e-mailul. De la mutarea pe serverul de azi, e-mailul „Ți-ai uitat parola?” n-a mai ajuns la nimeni: serverul n-avea niciun program de poștă, iar pagina răspundea mai departe că a trimis. Hotărârea rundei 2.7.5 e „amândouă drumurile”: prin căsuța firmei, când e completată în Setări → Notificări, în cartonașul „E-mail”, altfel direct de pe server. Gazda (`mail_smtp_host`) se seamănă **goală**, și ea e comutatorul: cât e goală, e-mailul pleacă direct de pe server, exact ca înaintea migrării — deci importul nu schimbă nimic din ce face site-ul în clipa aceea. Portul pornește pe `465`, iar criptarea pe `auto` (după port); necriptat nu există ca alegere. Parola e însemnată ca secret (`is_secret` = 1): se ține criptată, ca tokenurile, și nu se mai întoarce spre pagină, nici măcar cu ultimele ei semne. **Ce NU face: nu scrie nicio parolă și nu trimite nimic** — căsuța o completezi tu, din Setări, iar parola ei nu trece prin niciun fișier. **Și nu pune programul de poștă al serverului**: pe acela îl pune scriptul de actualizare, la pasul „Programul de poștă” (v. [09-actualizare-vps.md](09-actualizare-vps.md)). O bază care ia fișierele noi fără migrare nu pățește nimic: e-mailul merge pe drumul direct, iar casetele căsuței stau închise în Setări și numesc fișierul sub ele. Cere `001` (tabelul `settings`); registrul (`025`) îl folosește numai dacă îl găsește. |

Dacă o versiune viitoare are nevoie de o **setare** nouă, primești un fișier
separat care conține doar acel `INSERT IGNORE INTO settings`. Nu reimportul
seed-ului.

### Cum verifici că migrarea a intrat

`api/verifica.php` **nu te ajută**: pe o instalare vie răspunde 404
(`api/verifica.php:1458-1469`). phpMyAdmin → fila **SQL** → lipești și apeși Go.

**Întâi întreabă registrul.** Din `025_registru_migrari.sql` încoace, fiecare
migrare își scrie numele într-un tabel când rulează, deci răspunsul se citește
de-a dreptul:

```sql
SELECT fisier, aplicata_la FROM schema_migrations ORDER BY fisier;
```

Un nume care lipsește de acolo ori n-a intrat, ori a intrat **înainte** ca
registrul să existe — adică la o actualizare mai veche decât el, când n-avea
unde-și scrie numele. Dacă tabelul însuși lipsește, baza n-a văzut încă `025`.
În amândouă cazurile urmează interogarea de mai jos, care nu întreabă registrul
ci schema, deci spune adevărul și despre o bază care a trecut prin actualizări
de dinaintea lui.

Pentru starea de azi — migrările **003 … 037** — trebuie să întoarcă
**100 de rânduri**:

```sql
SELECT TABLE_NAME, COLUMN_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND (TABLE_NAME, COLUMN_NAME) IN
       (('orders','route_seq'), ('orders','stock_synced'),
        ('order_items','variant'), ('order_request_items','variant'),
        ('notifications','seen_at'), ('users','password_changed_at'),
        ('order_items','done_qty'), ('stock_moves','order_id'),
        ('users','referral_code'), ('users','referred_by'),
        ('users','referred_at'), ('users','referral_rate'),
        ('order_requests','referral_code'), ('orders','referral_code'),
        ('users','self_registered'), ('users','vouched_at'),
        ('users','vouched_by'), ('order_requests','promo'),
        ('orders','promo'), ('order_request_items','list_price'),
        ('order_items','list_price'), ('referral_commissions','dismissed_at'),
        ('referral_commissions','dismissed_by'), ('notifications','event'),
        ('order_requests','decided_via'), ('order_requests','snoozed_until'),
        ('users','lab_pin'), ('users','zone'),
        ('users','telegram_user_id'), ('order_requests','fulfillment'),
        ('order_requests','urgent'), ('order_requests','timed'),
        ('order_requests','created_by'), ('orders','lab_seq'),
        ('users','poate_confirma_livrarea'), ('products','in_frozen'),
        ('products','in_baked'), ('products','photo_baked'),
        ('order_requests','idempotency_key'), ('products','price_frozen_b'),
        ('products','price_frozen_c'), ('products','price_baked_b'),
        ('products','price_baked_c'), ('products','prag_b'),
        ('products','prag_c'), ('order_items','treapta'),
        ('order_items','prag_treapta'), ('order_request_items','treapta'),
        ('order_request_items','prag_treapta'), ('products','sheet_frozen'),
        ('products','sheet_baked'), ('orders','plata'),
        ('orders','plata_scadenta'), ('orders','plata_la'),
        ('orders','plata_de'), ('orders','plata_suma'),
        ('orders','plata_nota'), ('orders','plata_amintire_la'),
        ('users','prag_neplatite'), ('order_requests','handled_tg_id'),
        ('order_requests','handled_tg_name'))
UNION ALL
SELECT TABLE_NAME, '(tabel)'
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('founder_invites', 'lab_shifts', 'lab_messages',
                      'request_claims', 'telegram_links', 'lab_production',
                      'schema_migrations', 'role_rights', 'stock_item_prices',
                      'orders_deleted', 'telegram_oameni')
UNION ALL
SELECT 'settings', CONCAT(setting_key, ' = ', value)
  FROM settings
 WHERE setting_key IN ('referral_rate_default', 'company_address', 'company_hours',
                       'catalog_vedere_telefon', 'catalog_vedere_calculator',
                       'pret_prag_b_implicit', 'pret_prag_c_implicit',
                       'migration_029_numere_catalog',
                       'migration_030_gata_de_livrare',
                       'migration_031_fise_tehnice',
                       'plati_prag_implicit', 'plati_telegram_chat_id',
                       'plati_raport_ore', 'plati_amintiri',
                       'plati_raport_trimis_1', 'plati_raport_trimis_2',
                       'panel_base_url', 'migration_032_plati_si_prag',
                       'mail_smtp_host', 'mail_smtp_port', 'mail_smtp_user',
                       'mail_smtp_security')
UNION ALL
SELECT TABLE_NAME, INDEX_NAME
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND INDEX_NAME IN ('ix_orders_client_rang', 'ix_orders_autor',
                      'ix_req_autor', 'uq_req_cheie',
                      'ix_orders_plata', 'ix_orders_plata_la')
   AND SEQ_IN_INDEX = 1;
```

**Mai puține rânduri decât 100 → citește care lipsesc.** Numele coloanei îți
spune singur unde s-a oprit importul: `referral_rate` e din 008, `vouched_at`
din 010, `promo` din 011, `dismissed_at` din 012, `snoozed_until` din 013,
`lab_pin` din 014, `zone` din 016, tabelul `request_claims` din 019, indexul
`ix_orders_client_rang` din 020, iar `telegram_user_id`, `telegram_links`,
`lab_production` și `ix_orders_autor` din 021, iar cele patru coloane de pe
`order_requests` — `fulfillment`, `urgent`, `timed`, `created_by` — împreună cu
indexul `ix_req_autor` din 022, iar `lab_seq` (pe `orders`) și
`poate_confirma_livrarea` (pe `users`) din 023, iar cele trei de pe
`products` — `in_frozen`, `in_baked`, `photo_baked` — din 024, iar tabelul
`schema_migrations` din 025, iar coloana `idempotency_key` de pe
`order_requests` și indexul ei `uq_req_cheie` din 026, iar cele zece coloane
ale celor trei prețuri — șase pe `products`, de la `price_frozen_b` la
`prag_c`, și `treapta` cu `prag_treapta` pe cele două tabele de poziții —
împreună cu cele patru setări ale catalogului, din 028, iar semnul
`migration_029_numere_catalog` din 029, iar semnul
`migration_030_gata_de_livrare` din 030, iar cele două coloane ale fișelor de
pe `products` — `sheet_frozen` și `sheet_baked` — împreună cu semnul
`migration_031_fise_tehnice`, din 031, iar cele șapte coloane ale plății de pe
`orders` — de la `plata` la `plata_amintire_la` —, `prag_neplatite` de pe
`users`, indexurile `ix_orders_plata` și `ix_orders_plata_la`, cele șapte
setări ale condicii banilor și semnul `migration_032_plati_si_prag` din 032,
iar tabelul `role_rights` din 033, `stock_item_prices` din 034 și
`orders_deleted` din 035, iar cele două coloane ale apăsătorului de pe
`order_requests` — `handled_tg_id` și `handled_tg_name` — împreună cu tabelul
`telegram_oameni` din 036, iar cele patru rânduri ale căsuței de e-mail, de la
`mail_smtp_host` la `mail_smtp_security`, din 037.

Rândurile care pot lipsi fără pagubă sunt cele cinci de index: `020`, câte o
bucată din `021` și din `022`, plus cele două din `032`. Fără ele insignele de
client, lista de comenzi, lista de cereri și numărătoarea comenzilor neplătite
ies la fel, doar mai încet — nicio poartă din cod nu le caută.
Celelalte lipsuri sunt lucrări care nu merg.

`021` e prima migrare care aduce deodată din toate felurile: o coloană
(`users.telegram_user_id`), două tabele (`telegram_links`, `lab_production`) și
un index (`ix_orders_autor`) — de-aia urcă numărul cu patru dintr-un foc. Ce se
pierde fără fiecare: fără coloană, nimeni nu-și poate lega contul de Telegramul
lui, deci fiecare apăsare din grup rămâne a unui om „cu contul nelegat” (până
la 2.7.5 ieșeau toate pe seama unui singur om, cel care a legat botul; ce
pățește azi apăsarea aceea scrie mai jos, la `036`); fără `telegram_links`,
legarea nu se poate nici măcar porni; fără `lab_production`, raportul de producție pe tură
iese **gol**, nu dă eroare — adică exact felul de lipsă care se descoperă târziu,
la prima plată.

`022` urcă numărul cu cinci — patru coloane pe `order_requests` și un index —
și e migrarea fără de care hotărârea din 18 septembrie 2026 nu se poate ține:
comanda intră de-a dreptul în sistem numai scrisă de administrator, manager sau
fondator, restul scriu o cerere. Fără `fulfillment`, modul de onorare ales de
cel care a vorbit cu clientul se pierde între formular și acceptare, iar
`default_fulfillment()` hotărăște în locul lui — **tăcut, și pe altceva**.
Fără `urgent` și `timed`, graba nu trece mai departe. Fără `created_by`, comanda
născută din cererea agentului poartă numele administratorului care a apăsat
„Acceptă", iar agentul dispare din propria lui muncă; tot ea spune și dacă
cererea a venit de pe site sau din panou, deci fără ea comenzile personalului
ies însemnate „site" pe nedrept. Niciuna nu dă eroare: se pierd toate patru în
tăcere, ca `lab_production`, și se văd abia când cineva caută cine ce a făcut.

Cele trei rânduri de la coadă sunt tabele, nu coloane: `founder_invites` e din
`009` — singura migrare care nu adaugă nicio coloană, fiindcă ea doar lărgește
lista de roluri — `lab_shifts` e din `014`, care face și una, și alta, iar
`lab_messages` e din `015`, care face numai tabelul tablei de anunțuri. De-aia
`015` nu urcă numărul coloanelor: singura ei urmă în interogare e un tabel.

`016` merge invers: aduce o coloană (`users.zone`) și niciun tabel, deci urcă
numărul de rânduri de la 30 la 31 și lasă lista de tabele cum era. Zona a opta
din listă și lipsa punctului ei pe hartă **nu** se văd în interogarea asta —
ele stau în `settings`, nu în schemă; pe acelea le vezi în Setări, pe fila
„Liste”, la „Zone de livrare”, unde zona nouă trebuie să fie în listă și fără
coordonate.

`017` e de un al treilea fel, și de-aia rândul ei din interogare arată altfel
decât toate celelalte: ea nu adaugă **nici** coloană, **nici** tabel — scrie o
singură setare. Un martor căutat în `information_schema` n-ar găsi-o niciodată,
oricât ar fi rulat. De aceea ultimul rând nu spune doar că ceva **există**, ci
îți arată **cât scrie acolo**: `referral_rate_default = 5`. Cheia era pusă de
`008` de mult, cu valoarea `0` — deci prezența ei n-ar dovedi nimic. Valoarea
dovedește. Dacă citești `= 0` și n-ai pus tu zeroul dinadins din Setări, `017`
n-a intrat: recomandările se vor ține minte în continuare, dar fiecare comandă
adusă prin ele va naște un câștig de `0,00 RON`, tăcut și definitiv.

`018` e de același fel cu `017`, și de-aia stă pe același rând de la coadă:
scrie două setări, `company_address` și `company_hours`, și nimic altceva. Cele
două chei erau citite de pagina publică de mult, aveau casetele lor în Setări —
și n-aveau rând în tabel. Iar salvarea scrie cu `UPDATE`, peste rânduri care
există: patronul scria adresa firmei, apăsa „Salvează", primea răspuns bun, și
nu se schimba nimic. Niciodată. Tăcerea aceea nu mai e cu putință: o cheie fără
rând închide caseta din panou, iar cererea care o poartă totuși — un script, un
import, o pagină rămasă deschisă dinaintea actualizării — e refuzată întreagă, cu **toate**
cheile lipsă numite și fără să se scrie nimic din ea
(`api/lib/settings.php:537-551`, codul `unknown_setting`). De-aceea aici nu
contează numai că rândurile **există**, ci și **ce scrie în ele**: pe o
instalare proaspăt migrată trebuie să citești
`company_address = Șos. Olteniței 103` și
`company_hours = Lu-Vi 07:00-21:00, Sâ 07:00-17:00` — chiar valorile care erau
până acum scrise în cod, ca site-ul să arate la fel în clipa actualizării. Dacă
lipsesc cu totul, `018` n-a intrat, iar cele două casete din Setări
stau închise și spun sub ele ce migrare lipsește; se deschid după actualizare
și o reîncărcare a paginii.
(Dacă scrie altceva, nu e o pagubă: înseamnă că le-ai scris tu, iar migrarea
trece pe lângă un rând care există.)

`028` urcă numărul cu paisprezece: zece coloane și patru setări. Cele zece merg
numai **toate deodată** — codul le numără și, dacă lipsește măcar una, se
poartă ca înainte de migrare: fiecare produs are un singur preț, magazinul nu
arată nicio scară, iar comenzile se tarifează la A. Nu e o stricăciune, e
starea de dinainte, ținută dinadins cât timp fișierele au ajuns pe server și
importul încă nu. Singurul lucru refuzat în fereastra aceea e salvarea unui
preț B sau C din fila Produse: serverul răspunde că lipsește migrarea `028` și
nu scrie nimic, iar restul fișei produsului se salvează ca de obicei. Cele
patru setări se văd cu **valoarea** lor, ca la `017` și `018`: pe o instalare
proaspăt migrată citești `catalog_vedere_telefon = patru`,
`catalog_vedere_calculator = patru`, `pret_prag_b_implicit = 100` și
`pret_prag_c_implicit = 300`. Altă valoare înseamnă că ai ales-o tu în Setări,
iar migrarea a trecut pe lângă un rând care exista. Vederea de pe telefon are o
poveste a ei: `028` o seamănă cu `unu`, iar `032` o trece o singură dată pe
`patru` (v. mai jos, la `032`); între cele două importuri citești deci `unu`.
Dacă lipsesc de tot, magazinul se deschide oricum — patru pe ecran, și pe
telefon, și pe calculator —, iar produsele noi pornesc fără prag precompletat.

`029` urcă numărul cu unu: un singur rând, semnul din `settings`. E de felul
lui `027` — nu atinge schema deloc, deci nicio coloană n-ar putea-o dovedi —,
și se citește cu **data** la care a rulat, nu cu o valoare anume:
`migration_029_numere_catalog = 2026-…`. Fără el, fișierele noi se poartă ca
înainte: numărul din colțul pozei e cheia bazei, iar fișa produsului refuză
schimbarea numărului cu un mesaj care numește migrarea — restul fișei se
salvează. Cu el, numărul e `products.sort`, pe care migrarea l-a pus 1, 2, 3…
în ordinea în care au fost puse produsele. Semnul e și zăvorul ei: dacă îl
ștergi cu mâna, actualizarea următoare renumerotează tot catalogul și calcă
numerele alese între timp în fila Produse.

`030` urcă și ea numărul cu unu, și tot cu un semn din `settings`:
`migration_030_gata_de_livrare = 2026-…`. E de felul lui `029` — nu atinge
schema —, iar ce a făcut se vede în altă parte: în **Setări → Liste**, etapa
„Gata de livrare" stă chiar înaintea celei finale. Numele etapei **nu** e în
interogarea asta, dinadins: lista e a ta, iar o etapă pe care ai redenumit-o
ori ai scos-o după aceea ar arăta ca o migrare lipsă pe o instalare cinstită.
Semnul spune numai că migrarea a trecut pe-acolo. Fără el, fișierele noi
opresc laboratorul tot la etapa dinaintea celei finale, care e atunci
ambalarea, deci o comandă abia intrată la ambalat arată la fel ca una gata de
plecare, iar butonul curierului se aprinde pe ea de cum îi sunt bifate toate
pozițiile. Semnul e și zăvorul ei: dacă îl ștergi cu mâna, actualizarea
următoare pune etapa la loc, sub numele ei dintâi, lângă cea redenumită de
tine.

`031` urcă numărul cu trei: două coloane pe `products` — `sheet_frozen` și
`sheet_baked` — și semnul `migration_031_fise_tehnice`, citit tot cu data
rulării. Coloanele spun că fișa tehnică are unde se ține; semnul spune că
fișele venite cu pachetul au fost legate o dată de produsele lor. Fără coloane,
fișierele noi se poartă ca o casă fără fișe: magazinul nu arată butonul fișei la niciun
produs, iar încărcarea unei fișe din Catalog e refuzată cu un mesaj care
numește migrarea. Produsul și pozele lui se salvează, dar rețeta nu: ea pleacă
abia după fișe, iar formularul rămâne deschis și o spune. Semnul e zăvorul
legării: dacă îl ștergi cu mâna, actualizarea următoare pune la loc fișele pe
care le-ai scos între timp.

`032` urcă numărul cu optsprezece: opt coloane, două indexuri și opt rânduri
din `settings`. Coloanele sunt cele șapte ale plății, pe `orders`, și
`prag_neplatite`, pe `users`: fără ele, plata unei comenzi n-are unde se scrie,
iar pragul de comenzi neplătite n-are ce număra. Cele șapte setări se văd cu
**valoarea** lor, ca la `028`: pe o instalare proaspăt migrată citești
`plati_prag_implicit = 2`, `plati_raport_ore = 09:00,18:00`,
`plati_amintiri = 1` și `panel_base_url = https://lab.goldenpastry.ro`, iar
`plati_telegram_chat_id`, `plati_raport_trimis_1` și `plati_raport_trimis_2`
cu nimic după semnul egal — pornesc goale, adică „niciun chat ales” și „nu s-a
trimis niciodată”. Altă valoare înseamnă că ai ales-o tu, din „Încasări” ori
din Setări (de la 2.7.5 chatul și orele raportului se aleg și din Setări →
Notificări), ori că
raportul a plecat între timp: în cele două rânduri `plati_raport_trimis_…`
ceasul serverului își scrie singur **ziua** în care a luat ora aceea — o zi de
felul `2026-10-12`, nu o oră —, tocmai ca să n-o trimită de două ori în aceeași
zi (ceasul e povestit în
[09-actualizare-vps.md](09-actualizare-vps.md), la „Ceasul plăților”). Cele
două indexuri sunt de felul lui `020`: grăbesc numărătoarea comenzilor
neplătite ale unui client și socoteala încasărilor dintr-o perioadă, nimic mai
mult.

Al optulea rând din `settings` e semnul, `migration_032_plati_si_prag`, citit
cu data rulării, și e zăvorul singurului pas din `032` care scrie în comenzi.
Migrarea îl caută **înaintea judecății** — după ce a pus coloanele, indexurile
și setările — și îl scrie **la urmă**; numai când nu-l găsește judecă, o
singură dată, comenzile de dinaintea ei. Judecata e scurtă: o comandă aflată la
etapa finală — cea numită în `order_status_done`, oricum s-ar chema ea la tine —
și cu ziua **mai veche** decât ziua importului, cu oricâte zile, e trecută
„plătită”, cu totalul ei scris la `plata_suma` și cu nota `migrare 032`. Tot
restul rămâne „neplătită”: comanda predată chiar în ziua importului și toate
cele încă în lucru; cele anulate nu se numără oricum. Clipa plății (`plata_la`)
nu se scrie la niciuna, fiindcă nu se știe când s-au plătit comenzile vechi. De
aceea „Încasări” le numără la ziua **comenzii**, nu la ziua importului, iar
banii din ziua urcării nu se umflă cu tot istoricul. Dacă setarea etapei finale
lipsește ori e goală, migrarea nu marchează nimic — nu ghicește —, dar semnul
îl scrie oricum, deci nici la rularea următoare nu mai judecă. Semnul nu se
șterge cu mâna: fără el, actualizarea următoare ar trece din nou drept plătită
fiecare comandă predată pe care ai lăsat-o între timp pe „neplătită”.

Sub același semn stă și singura scriere din `032` care nu ține de bani: vederea
cu care se deschide magazinul pe telefon și tabletă. Rândul
`catalog_vedere_telefon` e trecut pe `patru` — „Patru pe ecran” —, dar numai
dacă mai ținea `unu`, valoarea cu care l-a semănat `028`. O vedere aleasă din
Setări înaintea importului („Șase pe ecran”, de pildă) rămâne cum era, iar după
import semnul oprește mutarea: dacă pui la loc „Unul pe rând”, actualizările
următoare nu ți-l mai schimbă. Rândul de pe calculator nu e atins, și niciun
rând nu e făcut: pe o bază fără `028` nu e ce muta. Schimbarea ajunge la toți
vizitatorii, și la cei care își aleseseră altă vedere pe telefon — o alegere
ținută minte în browser ține numai cât Setările spun ce spuneau când a fost
făcută.

Fără `032`, fișierele noi vând ca înaintea ei, și fiecare parte a casei spune
altceva. Scrierea unei comenzi nu judecă niciun prag: nicio comandă nu e oprită
și nimic nu se numără. Magazinul clientului primește la întrebarea „cum stau cu
plata?” răspunsul „nimic de spus” și se deschide ca de obicei — el n-are voie
să cadă pentru o migrare neimportată. Tot ce arată ori schimbă plata e însă
refuzat pe față, cu aceeași frază, care numește fișierul: „Plățile nu sunt încă
pornite pe server: rulează întâi sql/032_plati_si_prag.sql.” Așa răspund
registrul „Încasări”, starea de plată a unei comenzi, cardul „Raport pe
Telegram”, raportul de probă și avertizarea de prag din formularul comenzii;
pragul unui client, din „Conturi”, e refuzat cu aceeași frază. Statisticile se
deschid fără partea lor de plăți, iar ceasul serverului scrie în jurnalul lui
„nimic de făcut: baza nu are încă plățile (migrarea 032)” și nu trimite nimic.
Dacă migrarea s-a oprit la mijloc — coloanele sunt, dar rândurile din
`settings` nu —, salvarea pragului ori a setărilor raportului e refuzată cu
altă frază, care spune și leacul: „Setările plăților nu sunt întregi pe server:
rulează încă o dată sql/032_plati_si_prag.sql.” A doua rulare pune rândurile
rămase pe drum și nu calcă nimic din ce a apucat să fie scris.

`033` urcă numărul cu unu, și cu un tabel: `role_rights`. E de felul lui `015`
— singura ei urmă în interogare e tabelul, fiindcă nu pune nicio coloană pe un
tabel vechi și niciun rând în `settings`. Tabelul se naște gol, deci
interogarea spune numai că el **există**, nu că s-a scris ceva în el — și așa
și trebuie: în el stau numai bifele pe care fondatorul le-a mutat în „Acces”
față de pornire, iar gol înseamnă „fiecare rol intră unde intra până la 2.7.0”.
Cine are ce din pornire stă în cod (`GP_DREPT_IMPLICIT`, `api/lib/auth.php`),
nu în bază. Fără tabel, fișierele noi nu încuie și nu descuie nimic: fiecare
rută judecă după pornire, iar pagina „Acces” se deschide și arată împărțirea
de pornire, numai de citit, sub caseta „Bifele nu se pot muta încă.”, care
numește fișierul `sql/033_acces.sql`. O salvare trimisă totuși — dintr-o pagină
rămasă deschisă — e refuzată de server la fel (`acces_unavailable`). Nimeni nu
rămâne pe dinafară și nimeni nu capătă o ușă în plus cât lipsește tabelul.

`034` urcă și ea numărul cu unu, cu tabelul `stock_item_prices`, tot gol:
migrarea nu scrie niciun preț, deci gol înseamnă „niciun preț de cumpărare
scris încă”. Fără tabel, depozitul, rețetele și Statisticile răspund ca
înaintea ei, numai fără costuri, iar tot ce ține de prețuri e refuzat pe față:
fila „Prețuri” din Depozit arată în locul listei fraza serverului, „Prețurile
nu sunt încă disponibile: rulează întâi sql/034_costuri.sql.”, și niciun preț
nu se poate nici citi, nici scrie (`costuri_unavailable`). Nu se pierde nimic
în fereastra aceea, fiindcă nimic n-a apucat să fie scris.

`035` urcă numărul tot cu unu, cu tabelul `orders_deleted`, gol și el: gol
înseamnă „nicio comandă ștearsă”. **N-are nicio cheie străină, dinadins.** O
cheie străină leagă un rând de altul și hotărăște ce pățește el când celălalt
dispare, iar aici tocmai dispariția e subiectul: tabelul ține poza unei comenzi
care nu mai există, iar clientul ei ori contul care a șters-o pot fi șterși și
ei, mai târziu. Cu o cheie spre client, ștergerea clientului ar fi luat cu ea
și poza (`ON DELETE CASCADE`) ori i-ar fi golit câmpul (`SET NULL`) — adică
arhiva s-ar fi subțiat exact atunci când cauți în ea. De aceea poza își ține
singură tot ce-i trebuie: numele clientului, referința, ziua, totalul și cine a
șters-o stau scrise în ea, lângă numerele lor, nu ca trimiteri spre alte
tabele. Fără tabel, o comandă anulată nu se poate șterge: serverul refuză
„Șterge definitiv” cu fraza „Ștergerea comenzilor nu e pornită încă pe server:
rulează întâi sql/035_comenzi_sterse.sql.” (`comenzi_sterse_indisponibil`) și
nu atinge comanda — o ștergere fără urmă e tocmai ce n-a vrut casa. Anularea
merge ca înaintea ei, fiindcă ea e o etapă, nu o ștergere.

`036` urcă numărul cu trei: două coloane pe `order_requests` — `handled_tg_id`
și `handled_tg_name` — și tabelul `telegram_oameni`. Coloanele spun că numele
celui care a apăsat pe Telegram are unde se scrie; tabelul spune că lista
„Oameni din grup” are de unde se umple. Toate trei se nasc goale și așa rămân
până apasă cineva: migrarea nu scrie nimic despre hotărârile de dinaintea ei,
fiindcă nu se mai poate afla cine a apăsat atunci. Când scrie un nume nou,
codul le cere **pe toate trei** (`telegram_apasatori_suportati()`,
`api/lib/telegram-legaturi.php`): o jumătate lipsă înseamnă că fișierul n-a
rulat întreg.

Lipsa lui `036` **nu lasă casa ca înaintea ei**, spre deosebire de aproape
toate celelalte, și e bine de știut dinainte. Până la 2.7.5, omul din grup care
nu-și legase contul apăsa „Acceptă”, iar hotărârea ieșea pe numele celui care a
legat botul. Fișierele noi nu mai scriu niciodată numele altcuiva; fără cele
două coloane n-au însă unde scrie numele adevărat, deci apăsarea e refuzată, iar
omul citește pe telefon: „Contul tău de Telegram nu e legat de niciun cont, iar
serverul n-are încă unde să-ți scrie numele: lipsește migrarea
sql/036_telegram_oameni.sql. Nu am scris nimic.” Cine are contul legat lucrează
ca până acum, iar cererile se hotărăsc mai departe din panou. În Setări →
Notificări, cutia „Oameni din grup” arată în locul listei fraza serverului,
„Lista „Oameni din grup” n-are încă tabelul ei: rulează întâi
sql/036_telegram_oameni.sql.” (`telegram_oameni_unavailable`). De aceea, la
pachetul 2.7.5, ordinea de la începutul capitolului — baza întâi, apoi
fișierele — nu e de sărit: cu fișiere noi peste o bază veche, butoanele din
grup nu mai lucrează pentru niciun om nelegat. `actualizeaza-vps.sh` ține
singur ordinea asta.

`037` urcă numărul cu patru, toate rânduri din `settings`, citite cu
**valoarea** lor, ca la `028`: pe o instalare proaspăt migrată citești
`mail_smtp_port = 465` și `mail_smtp_security = auto`, iar `mail_smtp_host` și
`mail_smtp_user` cu nimic după semnul egal — pornesc goale, adică „nicio căsuță
completată”. Altă valoare înseamnă că ai completat căsuța din Setări →
Notificări, în cartonașul „E-mail”, iar migrarea a trecut pe lângă un rând care
exista. Migrarea seamănă **cinci** rânduri, nu patru: al cincilea,
`mail_smtp_password`, lipsește dinadins din interogare. Interogarea arată
valori, iar valoarea lui e parola căsuței — ținută criptată, dar tot nu e de
plimbat pe un ecran ori printr-o poză trimisă cuiva. Că rândul e acolo se vede
din Setări: caseta „Parola căsuței” e deschisă.

Fără `037`, fișierele noi trimit e-mailul ca înaintea ei, pe drumul direct, de
pe server; nimic nu cade. În Setări, casetele căsuței stau închise, iar
deasupra lor scrie: „Căsuța nu se poate completa încă: pe server lipsesc
rândurile ei, fiindcă migrarea sql/037_posta_smtp.sql n-a intrat. Rulează
actualizarea (sudo bash actualizeaza-vps.sh), apoi reîncarcă pagina.” Ce **nu**
dovedește niciun rând de aici e că e-mailul chiar pleacă: rândurile spun numai
că ai unde scrie căsuța. Dacă pleacă afli din Setări, din rândul de stare al
cartonașului „E-mail” și cu butonul „Trimite e-mail de test” (v.
`docs/02-panoul-de-setari.md`, la „E-mailul: căsuța firmei și drumul direct”).

Lista nu se mai ține de mână. `.claude/test-doc-actualizari.mjs` chiar rulează
interogarea asta pe baza de dezvoltare și numără rândurile, iar pe lângă asta o
compară cu pazele din `information_schema` scrise în fiecare fișier din `sql/`:
o migrare nouă înroșește proba până când interogarea de aici o cuprinde și pe ea.

---

## Ce verifici după, în ordine

Vreo trei minute. Fiecare pas prinde altceva.

1. **Deschide o fereastră privată.** Pasul care se sare cel mai des. Fereastra
   ta obișnuită are fișierele vechi în memorie: la tine merge tot, în timp ce
   clienții văd altceva.
2. **`https://api.goldenpastry.ro/products`.** JSON cu produse → API-ul e sus,
   baza răspunde, `config.php` e întreg. HTML sau pagină albă → `config.php` are
   eroare de sintaxă; pune înapoi copia. **Uită-te la trei-patru prețuri:** dacă
   sunt cele din pachet și tu le schimbaseși, s-a rulat seed-ul — oprește-te și
   restaurează.
3. **`https://goldenpastry.ro/`** în fereastra privată. Dacă vezi ambele limbi
   deodată, sau `{{ ceva }}` pe ecran → `support.js` s-a urcat trunchiat.
   Pătrate goale în loc de poze → `products/` a fost golit.
4. **Coșul.** Adaugă două produse, trimite o cerere de probă, apoi șterge-o din
   panou.
5. **`https://lab.goldenpastry.ro/`** — intră, deschide o comandă, schimbă-i
   etapa și pune-o la loc. Asta probează cookie-ul între subdomenii, tokenul
   CSRF și o rută care scrie. Dacă apare „Token CSRF lipsă sau invalid",
   reîncarcă pagina o dată: tokenul stă în memoria filei, nu în cookie.

   Tot acolo, verifică și paza etapei: deschide panoul din laborator într-o
   filă, treci pe altă filă, anulează aceeași comandă din „Comenzi", apoi
   întoarce-te la prima filă **fără s-o reîncarci** și apasă „etapa următoare".
   Trebuie să scrie **„Comanda #… a fost schimbată între timp: acum e
   «Anulată». N-am mutat-o."**, iar comanda să rămână anulată. Dacă în loc de
   asta comanda se mută și reapare în laborator, pe server a rămas pachetul
   vechi. Dacă scrie „Nu s-a salvat: …", s-a urcat numai jumătate din pachet
   (API nou cu pagini vechi, ori invers): rulează din nou actualizarea.
6. **Catalog → un produs cu poză urcată de tine.** Confirmă că se vede
   miniatura: cererea trece prin redirecționarea din `.claude/build-deploy.mjs:582`,
   deci probează și `.htaccess`-ul de pe `lab`. Tot acolo, la un produs care are
   fișă tehnică, apasă „Vezi fișa”: PDF-ul trebuie să se deschidă într-o filă
   nouă, de pe `goldenpastry.ro`. Trece prin redirecționarea vecină, scrisă la
   fel pentru `fise/`. Dacă în locul fișei vine o pagină „nu am găsit”, uită-te
   la adresa din filă: dacă începe cu `lab.goldenpastry.ro/fise/`, pe `lab` a
   rămas `.htaccess`-ul de dinaintea rundei 2.6.0; dacă începe cu
   `goldenpastry.ro/fise/`, redirecționarea a mers, dar folderul `fise/` n-a
   ajuns în `public_html/`.
7. **Setări → anunțurile care n-au plecat.** Dacă apar eșecuri cu ora
   actualizării, niște e-mailuri de comandă s-au pierdut în fereastra de urcare.
   Comenzile sunt salvate, doar mesajele n-au plecat — sunt scrise în tabelul
   `notifications`, deci se știe exact care.
8. **Sesiunile nu se pierd.** Stau în tabelul `sessions`, nu în fișiere. Urcarea
   nu deconectează pe nimeni. Singurele lucruri care rup sesiunile sunt
   schimbarea domeniului de cookie sau a cheii de criptare din `config.php`.
9. **Ceasul plăților**, de la 2.7.0. E primul lucru din casă care rulează
   singur, fără să fie cineva pe site: din cinci în cinci minute se uită dacă a
   venit ora raportului de bani de pe Telegram. Rândul lui stă pe server, în
   `/etc/cron.d/goldenpastry-plati`, nu în pachet, deci o urcare de fișiere nu
   îl strică și nu îl pune; îl pune `actualizeaza-vps.sh`, care scrie în raport,
   la pasul „Ceasul plăților”, dacă merge. După o actualizare, deschide
   „Încasări” și uită-te jos, în cardul „Raport pe Telegram”, la „Ultimele
   trimiteri”: raportul orei următoare trebuie să apară acolo. Cardul îl vede
   din pornire numai fondatorul; cui i-l mai arăți hotărăști din „Acces”. Ce
   faci când raportul n-a plecat scrie în
   [09-actualizare-vps.md](09-actualizare-vps.md), la „Ceasul plăților”.
10. **E-mailul**, de la 2.7.5. Deschide Setări → Notificări și citește rândul
    de stare din cartonașul „E-mail”: spune pe ce drum pleacă e-mailul acum —
    prin căsuța firmei ori direct de pe server. Apasă „Trimite e-mail de test”
    și caută mesajul în căsuța ta, și în Spam. Programul de poștă al drumului
    direct nu e în pachet: îl pune `actualizeaza-vps.sh`, la pasul „Programul
    de poștă”, care scrie în raport dacă l-a pus, dacă serverul avea deja unul
    și dacă portul 25 pare închis de găzduire. În cazul din urmă mesajele rămân
    în coada serverului și nu pleacă, fără nicio eroare în panou, iar leacul nu
    e pe server: completezi căsuța firmei. Dacă scriptul a fost rulat cu
    `--fara-posta`, programul nu se pune, iar e-mailul pleacă numai prin
    căsuță. Proba întreagă se face de pe pagina de intrare: „Ți-ai uitat
    parola?” cu contul tău, apoi rândul din Jurnal (v.
    `docs/05-conturile-de-client.md`, la „Dacă și-a uitat parola”).
11. **Butoanele din grupul de Telegram**, de la 2.7.5. Aici nu e nimic de
    apăsat de probă: uită-te la prima cerere hotărâtă din grup de un om care nu
    și-a legat contul. Sub mesaj trebuie să scrie numele lui de pe Telegram și
    vorba „cont nelegat”, nu numele celui care a legat botul. Dacă omul citește
    în schimb pe telefon că lipsește migrarea `sql/036_telegram_oameni.sql`,
    fișierele au ajuns pe server înaintea bazei: rulează din nou actualizarea.
    Cererea lui n-a fost atinsă și se poate hotărî între timp din panou.

---

## Urmele lăsate de defectele reparate

Reparațiile opresc greșeala de aici încolo; ele **nu** îndreaptă rândurile
strâmbe scrise înainte. Dacă serverul mergea deja cu versiunea veche, treci o
dată cele patru interogări de mai jos prin phpMyAdmin (fila **SQL**, pe baza
aplicației) după ce ai urcat. Durează un minut și nu schimbă nimic: toate patru
sunt `SELECT`.

**Zero rânduri la toate patru = nu e nimic de îndreptat.** Nu te alarma dacă una
scoate ceva: sunt cazuri numărabile, se repară din aplicație.

### 1. Marfă scăzută din depozit și niciodată întoarsă

Zăvorul `orders.stock_synced` mergea într-un singur sens: se punea la scădere și
nu se mai lua jos. O comandă deja scăzută, trecută înapoi în lucru sau pe
„Anulată", lăsa marfa ieșită din evidență deși ea era în rafturi.

```sql
SELECT o.id, o.ref, o.`day`, o.status, o.customer_name
  FROM orders o
 WHERE o.stock_synced = 1
   AND o.status <> (SELECT value FROM settings WHERE setting_key = 'order_status_done')
 ORDER BY o.`day`, o.id;
```

Ce faci cu ce iese: deschide fiecare comandă în panou și **salveaz-o o dată**,
fără să schimbi nimic. Codul reparat întoarce marfa în depozit ca mișcări de
intrare și scoate marcajul. Verifică pe urmă în Depozit → registru că a apărut
rândul de intrare cu referința comenzii.

**Semnul „Scade din stoc” se poate aprinde singur după actualizare.** Până la
2.1.1, Depozitul număra comenzile de scăzut din cele mai noi 500 de comenzi, deci
o comandă „Predată” mai veche — oprită de un articol lipsă sau redeschisă de o
corectură — rămânea nescăzută fără niciun semn. Acum cifra vine de pe server,
din toate comenzile (`api/routes/stock.php:723`). Dacă semnul apare cu comenzi
la care nu te așteptai, gândește-te întâi dacă nu le-ai scos deja marfa de mână,
cu o mișcare de ieșire în registru: apăsarea pe „Scade din stoc” le-ar scădea a
doua oară. În cazul ăsta adaugă o mișcare de intrare cu aceeași cantitate după
apăsare, nu în loc de ea — altfel comanda rămâne nescăzută și semnul nu se stinge.

### 2. Comenzi scăzute de mai multe ori

Un produs redenumit după scădere putea fi scăzut a doua oară, pe articolul nou.
Interogarea compară cât a ieșit **net** din depozit pentru fiecare comandă (adică
scăderi minus returi) cu câte bucăți are comanda pe ea.

```sql
SELECT o.ref, o.`day`, o.customer_name,
       ROUND(-SUM(m.qty * m.direction), 2) AS iesit_net,
       (SELECT ROUND(SUM(i.qty), 2) FROM order_items i WHERE i.order_id = o.id) AS pe_comanda
  FROM orders o
  JOIN stock_moves m ON m.order_id = o.id
 WHERE m.reason = 'Comandă client' OR m.reason LIKE 'Retur:%'
 GROUP BY o.id, o.ref, o.`day`, o.customer_name
HAVING iesit_net > pe_comanda
 ORDER BY o.`day` DESC;
```

Două lucruri de știut despre ea. **Nu dă alarme false:** `pe_comanda` numără
toate pozițiile comenzii, inclusiv cele care n-au deloc articol în depozit, deci
e un număr mai mare decât adevărul — ce iese pe listă chiar a ieșit în plus.
**Dar scapă comenzile vechi:** legătura se face prin `stock_moves.order_id`, iar
coloana aceea se completează la scăderea comenzilor abia de la reparația
raftului-fantomă; mișcările scrise înainte de ea o au goală și nu apar aici.
Pentru comenzile vechi, singura legătură rămâne însemnarea
(`„#1042 Restaurant Bistro"`) — dar și referința, și numele clientului se pot
schimba din formularul comenzii, deci o comandă redenumită de atunci nu se mai
găsește nici așa. Tocmai de aceea codul reparat nu se mai leagă de însemnare, ci
citește returul din registru, pe `order_id` (`api/lib/orders.php:1885`).

Ce faci cu ce iese: adaugă în Depozit o mișcare de **intrare** cu diferența
(`iesit_net - pe_comanda`), pe articolul din registru, cu motivul „Corectură
scădere dublă" și cu referința comenzii în mențiune. Nu șterge mișcările vechi:
stocul curent se calculează din registru (`SUM(qty * direction)`), iar un rând
șters din el face cifra de pe ecran de neexplicat.

### 3. Aceeași referință de două ori în aceeași zi

`orders.ref` n-are index unic. Mutarea unei comenzi pe altă zi îi ținea
referința, iar dacă ziua nouă avea deja o comandă cu ea, două comenzi ajungeau
să se cheme la fel în aceeași zi — pe foaia șoferului și în discuția cu clientul,
de nedeosebit.

```sql
SELECT `day`, ref, COUNT(*) AS cate, GROUP_CONCAT(id ORDER BY id) AS comenzi
  FROM orders
 GROUP BY `day`, ref
HAVING COUNT(*) > 1
 ORDER BY `day` DESC;
```

Ce faci cu ce iese: pentru zilele **viitoare**, deschide comanda mutată (a doua
din listă, cea cu id-ul mai mare) și mut-o pe altă zi și înapoi — codul reparat
îi dă o referință liberă și scrie schimbarea în Jurnal. Pentru zilele trecute nu
umbla la ele: sunt istorie, iar clientul are pe hârtie referința pe care a
primit-o.

### 4. Comenzi rămase în lucru pe o zi care a trecut

Până la 2.1.1, o comandă scoasă din „Anulată" — cu „Reface comanda", din
lista de etape a fișei sau din lista „Stare" a formularului, la salvare — se
întorcea pe ziua ei salvată, oricât de veche, fără niciun semn. Rămânea în lucru pe o zi pe care n-o mai arată nici panoul din
laborator, nici ruta șoferului, iar marfa ei stătea pusă deoparte: rezervarea
ține seama numai de etapă, nu de zi (`api/routes/stock.php:68`). Acum caseta
spune că ziua e în trecut, dar comenzile refăcute înainte au rămas unde erau.

```sql
SELECT o.id, o.ref, o.`day`, o.status, o.customer_name
  FROM orders o
 WHERE o.`day` < CURDATE()
   AND o.status <> (SELECT value FROM settings WHERE setting_key = 'order_status_done')
   AND o.status <> (SELECT value FROM settings WHERE setting_key = 'order_status_cancelled')
 ORDER BY o.`day`, o.id;
```

Nu toate câte ies vin de aici: apare și o comandă plecată la vremea ei, dar
netrecută „Predată". Gestul e același. Pune în panoul de comenzi filtrul
**„Toate zilele · cu istoric"**, deschide fiecare comandă din listă și hotărăște:

- **a plecat atunci** → din lista de etape a fișei, treci-o „Predată". Marfa nu
  mai stă pusă deoparte, iar comanda așteaptă „Scade din stoc" în Depozit. Dacă
  i-ai scos deja marfa de mână, citește întâi ce scrie la punctul 1 despre
  scăderea a doua oară.
- **n-a plecat și nu mai trebuie** → treci-o „Anulată". Marfa se eliberează.
- **tot trebuie să plece** → deschide-o cu „Editează comanda", alege în câmpul
  **„Zi"** o zi de azi încolo și salvează. Reapare pe panoul din laborator și pe
  ruta șoferului.

Rulează interogarea în timpul zilei, nu după miezul nopții: `CURDATE()` e ziua
ceasului bazei, care poate rămâne cu câteva ore în urma patiseriei.

---

## Capcanele, de la cea mai dureroasă în jos

### 1. Reimportul lui `002_seed.sql` — REPARAT în 2.0.2

Blocul distructiv a fost scos din fișier și din generatorul lui
(`.claude/gen-seed.mjs`); `002_seed.sql` e acum numai `INSERT IGNORE`. Ce
urmează descrie fișierul de dinainte, ca să știi ce ai avut pe disc.

Fișierul avea patru părți. Trei erau într-adevăr `INSERT IGNORE` și nu stricau
nimic. A patra, rândurile 76–229, erau **22 de `UPDATE products SET …
WHERE id = N` necondiționate**.

Ce se pierde într-o secundă, pentru produsele 1–22: prețurile congelat și copt,
denumirile română și engleză, ambele descrieri, gramajul, categoria, marcajul
„nou", ordinea de afișare și **fotografia**.

Cazul fotografiilor e cel mai urât fiindcă e ireversibil în practică: în bază
stă `products/prod-1-a3f9c2b1.jpg`, iar `UPDATE`-ul scrie `products/prod-1.jpg`,
care chiar există pe disc — deci site-ul revine tăcut la poza din pachet,
**fără nicio eroare**. Fotografia ta rămâne pe disc, orfană, cu un nume aleator
pe care nu-l mai știe nimeni.

Se vede instantaneu la client: prețurile se citesc direct din bază la fiecare
cerere (`seo/index.php:112-116`), deci prețul vechi apare și în catalog, și în datele
trimise la Google.

Ce **nu** se pierde: produsele adăugate de tine (id ≥ 23), produsele scoase din
gamă rămân scoase, setările rămân, comenzile rămân.

### 2. Ștergerea sau „sincronizarea" folderului `products/`

Scenariul care se întâmplă de la sine: te uiți la folderul din pachet și la cel
de pe server, numărul nu se potrivește, și faci curat. Sau folosești FileZilla
cu *delete extraneous files*.

Rezultat: baza arată în continuare spre pozele tale, fișierele nu mai există.
Pătrate goale pe site, în pagina Catalog și în imaginile din rezultatele Google.
Nerecuperabil altfel decât reîncărcând fiecare poză de mână.

### 3. Cache de 7 zile pe JavaScript — REPARAT în 2.0.3

**Cum era.** `.claude/build-deploy.mjs:444-445` cere browserului să țină `.css`
și `.js` șapte zile, în timp ce `:448` cere paginilor să nu se memoreze deloc,
iar paginile chemau fișierele fără nicio versiune. Combinația implicită la
fiecare actualizare era deci exact pe dos: **pagină nouă cu `support.js`
vechi**, până la șapte zile.

Ce vedea clientul: o casetă gri cu text tehnic în engleză în locul catalogului.
Tu nu vedeai nimic, fiindcă tocmai îți goliseși cache-ul urcând. Mai rău: plasa
aceea prinde doar erorile de randare — o eroare la trimiterea comenzii nu e
prinsă de nimic, iar butonul rămâne pe „Se trimite…" la nesfârșit. Clientul
crede că a comandat.

**Ce s-a schimbat.** `puneVersiuni()` (`.claude/build-deploy.mjs:294`) lipește
pe fiecare trimitere către un `.js` sau `.css` amprenta conținutului acelui
fișier — opt cifre hexa din SHA-256, ca `?v=8fe7df74`. Cheia de memorare a
browserului e adresa întreagă, cu tot cu șirul de interogare:

- fișier schimbat → amprentă nouă → adresă nouă → luat din nou, chiar dacă cel
  vechi mai avea șase zile de stat în browser;
- fișier neschimbat → aceeași adresă → nu se mai cere nimic, iar cele șapte
  zile chiar folosesc la ceva.

Nu e un număr de versiune bătut la mașină, tocmai ca să nu poată fi uitat: se
schimbă singur, și numai când chiar s-a schimbat ceva. Adresele din afară
(unpkg, fonturi) rămân neatinse — nu le știm conținutul.

**Ce trebuie să faci tu:** nimic. Se întâmplă la construirea pachetului.

Rămâne o singură grijă, și nu e a ta: dacă vreodată un `.js` din pachet ajunge
să cheme alt `.js` sau `.css` local, trimiterea aceea trebuie ștampilată și ea.
Azi nu există niciuna — singurele adrese din `support.js` duc la unpkg.com — și
comentariul de la `puneVersiuni()` o spune pe față. Proba
`.claude/test-doc-actualizari.mjs` verifică la fiecare rulare că **fiecare**
trimitere din pachet poartă o amprentă, și că amprenta e chiar a fișierului spre
care arată: una rămasă în urmă ar fi mai rea decât niciuna, fiindcă ar arăta ca
o versionare care merge.

### 4. `.htaccess` suprascris peste blocurile puse de cPanel

Cele patru `.htaccess` sunt generate integral și **înlocuite integral** la
urcare, nu îmbinate. Dispar fără urmă: blocul MultiPHP Manager (și atunci
domeniul poate reveni pe versiunea implicită de PHP), Directory Privacy,
protecția la hotlink, IP Blocker, redirecționările făcute din cPanel.

Invers, dacă `.htaccess` **lipsește** după extragere (e ascuns — bifează *Show
Hidden Files*): în `api/` toate cererile dau 404, iar `config.php` devine
descărcabil ca text; în `public_html/` adresele fără `.html` dau 404 și cele
trei poze de pe prima pagină rămân goale, fără niciun mesaj în consolă.

La actualizările obișnuite **nu-l urca deloc**. Când chiar trebuie, deschide
întâi fișierul de pe server, copiază blocurile puse de cPanel într-un text, și
lipește-le înapoi sub blocul generat, după extragere.

### 5. Urcare întreruptă la jumătate

`support.js` trunchiat → pagina cu ambele limbi și `{{ }}` pe ecran. Un fișier
din `api/` trunchiat → JSON valid cu 500, prins de `api/index.php:455-461`.
Vestea bună: comenzile și cererile rulează în tranzacții, deci **nu rămân
comenzi pe jumătate în bază**.

### 6. Modificări de catalog făcute în timpul zilei

Nu ține de actualizare, dar apare exact atunci și e pus pe seama ei. Coșul
clientului se salvează în browserul lui cu **doar cantitățile**, nu prețurile
(`Site Public.dc.html:3726-3736`), deci o schimbare de preț se vede corect. **Dar
dispariția e complet tăcută**: `:4816-4818` ține în vitrină numai produsele bifate în
catalogul deschis și cu prețul de acolo scris, iar `:5527-5534` scoate din coș
rândurile rămase fără latura lor, fără niciun mesaj.

Singura excepție e coșul refăcut din **„Comandă din nou”** (butonul de pe
comenzile din „Comanda mea”). Acela ia prețurile de azi, nu pe cele de pe
comanda veche, iar magazinul spune într-o casetă câte produse n-au mai intrat;
cele șterse de tot din catalog le numește chiar pagina clientului, sub buton.
Un coș făcut de mână și lăsat peste noapte rămâne tăcut, ca mai sus.

Concret: un client își face coșul luni seara, 8 produse, 340 lei. Marți scoți un
produs din gamă cu butonul × de pe rând. Miercuri clientul găsește 7 produse și
mai puțini lei, fără nicio explicație.

**Prețul gol nu mai poate face asta.** Până la cele două cataloage, ștergerea
unui singur preț ascundea produsul din **amândouă** variantele, deși atinseseși
un singur câmp. Acum serverul refuză golirea unui preț cât catalogul lui e bifat
(422, mesajul numește catalogul și caseta — `api/routes/catalog.php:1230`), iar
stingerea bifei **lasă prețul scris**. Deci scoaterea dintr-un catalog e o
singură apăsare, și atunci pier din coșuri numai rândurile laturii aceleia;
cealaltă nu se clintește.

Până se repară dispariția tăcută: produsele se scot din gamă seara.

---

## Ce e de îmbunătățit, ca actualizările să fie mai sigure

Trei lucruri, ordonate după cât ajută față de cât costă. Două sunt făcute, iar
a treia a căzut odată cu publicarea din panoul de găzduire.

1. ~~**Versionarea fișierelor pentru cache**~~ — făcută în 2.0.3:
   `puneVersiuni()` (`.claude/build-deploy.mjs:294`) lipește pe fiecare
   trimitere către un `.js` sau `.css` amprenta conținutului lui, la
   construirea pachetului. Capcana 3 e închisă; vezi acolo ce înseamnă și ce
   rămâne de ținut minte.
2. ~~**Tăierea blocului distructiv din seed**~~ — făcut în 2.0.2: blocul a
   fost scos cu totul (la instalare `INSERT IGNORE` pune oricum aceleași
   date), iar `002_seed.sql` e ce pretinde că e: numai `INSERT IGNORE`, sigur
   la reimport. Capcana 1 e închisă.
3. ~~**Un tabel `schema_migrations`**~~ — hotărât „**nu se mai face**" în 2.0.3,
   **făcut totuși în 2.4.0**, și cu o coadă lungă. Rândul ăsta se citește tot,
   fiindcă lecția lui nu e despre registre.

   **Ce s-a hotărât în 2.0.3.** Un registru avea rost cât se publica dintr-un
   panou de găzduire: acolo importai migrările una câte una, cu mâna, și chiar
   nu aveai de unde afla ce ai importat deja — iar unealta care ar fi trebuit
   să-ți spună (`api/verifica.php`) e zăvorâtă după instalare. Odată trecută
   publicarea pe `actualizeaza-vps.sh`, care le rulează pe toate în ordine și
   scrie în raport câte a aplicat atunci („Bilanț: N aplicate acum"), evidența
   părea că există deja — și încă într-un loc care nu trebuie ținut la zi cu
   mâna, adică fără încă un loc care poate minți.

   **De ce s-a răzgândit casa în 2.4.0.** Raportul acela se ținea după
   **amprenta** schemei — tabele, coloane, lungimea totală a tipurilor —, iar
   amprenta e o ghicitoare. `017` nu atinge DELOC schema: scrie un rând în
   `settings` și atât. Măsurat, nu bănuit: 21·229·2546 înainte, 21·229·2546
   după. Deci raportul spunea „era deja aplicată" **chiar și la prima ei**
   **rulare** — tocmai despre migrarea care repară comisioanele de 0,00 RON.
   De-aceea `sql/025_registru_migrari.sql` face tabelul, iar fiecare migrare de
   la `003` încolo își scrie numele în el, la coadă. Registrul **înseamnă**, nu
   **oprește**: migrările rulează toate, la fiecare actualizare, ca și până
   acum.

   **Și lecția, care nu e despre registre.** Pe 21 septembrie 2026, urcarea
   rundei 2.4.0 pe serverul adevărat a murit cu `Unknown column 'fisier' in`
   `'where clause'`. În baza de producție era un `schema_migrations` făcut cu
   mâna pe 1 septembrie, cu alte coloane (`filename`, `applied_at`, `note`) și
   șapte rânduri — rămășița chiar a ideii părăsite aici, în 2.0.3. **„Nu se**
   **mai face" nu desface ce s-a făcut deja.** Hotărârea s-a scris în rândul
   ăsta; tabelul a rămas pe server, și a așteptat acolo trei săptămâni un cod
   care să-i ceară o coloană pe care n-o avea. Două lucruri de ținut minte,
   amândouă plătite:

   - **Un nume nu e o identitate.** Paza întreba `TABLE_NAME =`
     `'schema_migrations'` — un nume —, și i se răspundea „da".
     `CREATE TABLE IF NOT EXISTS` se potrivește tot pe nume, deci nici el n-a
     îndreptat nimic: a tăcut și a plecat mai departe. Din 2.4.1 întrebarea se
     pune pe **coloana** de care e nevoie (`registruExista`), iar un tabel cu
     numele nostru și coloanele altcuiva oprește urcarea cu vorbele casei, cu
     coloanele lui sub ochi și cu comanda de scăpare (`registruStrain`) —
     `RENAME`, nu `DROP`: nu se șterge un tabel despre care scriptul nu știe
     ce ține. Probele stau în secțiunea 13 din `.claude/test-plasa-rsync.mjs`.
   - **Când se hotărăște că ceva „nu se mai face", se scrie și ce se face cu**
     **ce s-a făcut deja.** Rândul de față n-a spus-o, și de-aia a rămas
     tabelul.
