# Cum publici o modificare pe site-ul care merge deja

Ghidul ăsta e pentru **după** prima publicare, când pe server sunt deja comenzi
reale, clienți reali și poze urcate de tine. Instalarea de la zero e în
[08-publicare-vps-hostinger.md](08-publicare-vps-hostinger.md).

Ideea de bază, în două rânduri: **sursa e pe calculatorul tău, `deploy/` e doar
rezultatul.** Modifici sursa, rulezi un script, urci ce a ieșit. Folderul
`deploy/` se șterge și se reface complet la fiecare construire
(`.claude/build-deploy.mjs:661`), deci orice ai scrie direct acolo dispare.

---

## Regula de aur

Cinci lucruri. Niciunul nu are excepții.

**1. `public_html/api/config.php` nu se suprascrie niciodată.**
Acolo stau parola bazei de date și cheia de criptare. Nu e în pachet deloc —
scriptul îl scoate (`.claude/build-deploy.mjs:868-870`) și mai verifică o dată
la final (`:888-895`), deci nicio urcare nu-l poate atinge din greșeală.
**Cheia de criptare nu se schimbă niciodată după instalare.** Dacă se schimbă,
`api/lib/crypto.php:85` întoarce `null`, iar `api/lib/settings.php:36` doar
scrie o linie în jurnal și merge mai departe — *tăcut*. Efectul vizibil:
codul tabletelor devine gol, `api/routes/auth.php:400-404` răspunde 503
„Codul panoului nu este configurat", și toată echipa din laborator rămâne
pe dinafară.

**2. `public_html/products/` nu se șterge și nu se mai urcă după prima instalare.**
Pozele pe care le încarci din pagina Catalog trăiesc **numai** acolo:
`api/routes/catalog.php:590` le scrie pe disc sub un nume cu cifre aleatoare
(`prod-14-9c3a71ef.jpg`), și nimic nu le aduce înapoi în proiect. Pachetul conține și el un folder `products/`, construit din copia
mea locală — cele două se depărtează unul de altul din prima zi. Urcând peste,
ștergi pozele adăugate live, iar în bază rămân trimiteri către fișiere care
nu mai există.

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
(`.claude/build-deploy.mjs:353, 478, 528, 546`), nu pe server.

---

## Cazul obișnuit: s-a schimbat doar aspectul sau textul

Adică una sau mai multe pagini `.dc.html`, sau `_ds/`, sau `assets/`. Nimic în
`api/`, nicio migrare.

### 1. Construiește pachetul

```bash
node .claude/build-deploy.mjs
```

Dacă la final scrie „N probleme", **te oprești aici** — scriptul iese cu cod 1
(`:802`). Sunt **șapte** lucruri din care se poate opri, și fiecare e scris pe
ecran cu un `!` la început:

| Ce scrie | Ce înseamnă | Unde |
|---|---|---|
| `legături nerezolvate` | o pagină trimite spre altă pagină pe care harta n-o cunoaște — pe server, butonul acela ar da 404 | `:586` |
| `lipsește … (cerut de …)` | un fișier din lista gazdei nu e pe disc | `:617` |
| `a ajuns în pachet — conține parola bazei de date` | `api/config.php` a intrat în pachet. Scriptul îl și șterge, dar tot se oprește: vrea să afli cum a ajuns acolo | `:681` |
| `fără zăvorul de după instalare` | `setup.php` sau `verifica.php` a intrat în pachet fără zăvorul lui | `:707` |
| `nume din dezvoltare rămase în pachet` | o trimitere a rămas pe numele de fișier din dezvoltare, nu pe adresa de pe server | `:748` |
| `nu am putut citi adresele din seo/adrese.php` | tabelul de adrese al magazinului n-a putut fi citit, deci verificarea de după el ar fi mințit | `:782` |
| `fișiere care fură o adresă a magazinului` | un fișier adevărat stă exact pe o adresă pe care ar trebui s-o servească `index.php` — Apache îl servește pe el, iar pagina aceea dispare din magazin | `:793` |

Niciunul nu e „aproape bine": fiecare înseamnă un pachet care, urcat, strică
ceva pe server.

### 2. Vezi ce s-a schimbat față de ce e pe server

Harta sursă → pachet e fixă (`.claude/build-deploy.mjs:35-66` și `:71-78`):

| Ai modificat | Se schimbă în pachet | Urci în |
|---|---|---|
| `Site Public.dc.html` | `public_html/app.html` | `public_html/` |
| `Administrare Comenzi.dc.html` | `lab/comenzi.html` | `public_html/lab/` |
| `Panou Laborator` · `Depozit` · `Conturi` · `Jurnal` · `Ruta Sofer` · `Produse` · `Setari` | câte un `.html` în `lab/` | `public_html/lab/` |
| `Client.dc.html` | `clienti/client.html` | `public_html/clienti/` |
| **`Intrare.dc.html`** | `clienti/index.html` **și** `lab/index.html` | **două foldere** |
| **`Parola.dc.html`** | `clienti/parola.html` **și** `lab/parola.html` | **două foldere** |
| **`Recomandari.dc.html`** | `clienti/recomandari.html` **și** `lab/recomandari.html` | **două foldere** |
| `support.js` · `shared/gp-api.js` · `_ds/` · `assets/` | **câte trei copii** | **toate trei subdomeniile** |
| `image-slot.js` · `.image-slots.state.json` | o singură copie | `public_html/` |
| `seo/index.php` · `seo/sitemap.php` · `seo/adrese.php` | `public_html/index.php`, `sitemap.php`, `adrese.php` | `public_html/` |

`Intrare`, `Parola` și `Recomandari` sunt **cele trei** pagini care ajung în
două locuri, iar **cele două copii nu sunt identice**: scriptul le injectează
adresa API-ului și rescrie legăturile dintre subdomenii
(`rewriteLinks()`, `.claude/build-deploy.mjs:228`). O modificare în ele cere
două urcări.

`adrese.php` nu e o poză și nu e un stil: e **tabelul de adrese al
magazinului**, citit și de pagină, și de harta de site. Fără el pe server,
fiecare adresă în afară de „/" primește 404 — adică tot catalogul, toate
paginile de produs și amândouă limbile.

### 3. Scoate din pachet ce n-are voie să plece

Înainte de a face arhiva, șterge din `deploy/`:

- **`deploy/public_html/products/`** — regula 2 de mai sus.
- **`deploy/api/setup.php`** și **`deploy/api/verifica.php`** — revin în pachet
  la fiecare construire, deliberat (`:655`, motivat la `:686-695`). Amândouă au
  zăvor propriu, dar zăvorul lui `setup.php` depinde de existența unui
  administrator activ (`api/setup.php:39-48`). Șterge-le de pe server după
  fiecare urcare.
- **`.htaccess`** — la actualizările obișnuite nu-l urca deloc. Vezi capcana 4.

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
paginile, clientul primește pe ecran exact textul de la `api/index.php:372` —
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
   tabletelor și tokenurile de notificare devin ilizibile, tăcut.

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
excepție → `api/index.php:372-378` întoarce „Eroare internă." Nu e o pagină
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
| `021_raspundere.sql` | Da — patru bucăți, fiecare cu paza ei: indexul și coloana se uită întâi în `information_schema`, iar cele două tabele intră cu `CREATE TABLE IF NOT EXISTS`. Nu atinge niciun rând existent: coloana nouă `users.telegram_user_id` pornește `NULL` pe toate conturile, iar tabelele se nasc goale. Aduce răspunderea — cine a scris comanda (indexul `ix_orders_autor`, coloana de Telegram, tabelul `telegram_links`) și cine era de tură când s-a făcut (`lab_production`). **Jumătatea care se poate uita fără pagubă e numai indexul**, ca la `020`: fără el lista de comenzi iese la fel, doar mai încet. Celelalte trei nu: fără coloană, faptele apăsate în Telegram ies toate pe numele celui care a legat botul; fără `telegram_links`, legarea Telegramului nu se poate nici măcar porni; fără `lab_production`, raportul de producție pe tură iese **gol, fără eroare** — adică se descoperă la prima plată, nu la actualizare. `UNIQUE`-ul de pe `telegram_user_id` nu poate ciocni la import: în MySQL `NULL` nu se ciocnește cu `NULL`, iar la actualizare toate conturile sunt `NULL`. Cere `001` (chei străine spre `users` și `orders`) și `014` (coloana nouă se pune `AFTER lab_pin`). |
| `022_cerere_de_la_personal.sql` | Da — patru coloane, fiecare cu paza ei în `information_schema`, plus indexul și cheia străină, în ordinea asta (cheia pusă înaintea indexului ar fi făcut MySQL să-și scrie singur un index fără nume ales, iar al nostru ar fi rămas al doilea, nefolosit). Nu atinge niciun rând existent: toate patru sunt `NULL` sau cu implicit, deci o cerere de pe site scrisă ieri rămâne întreagă. Aduce cererea scrisă de personal — de la hotărârea din 18 septembrie 2026, comanda intră de-a dreptul în sistem numai când o scrie un administrator, un manager sau fondatorul; restul scriu o CERERE. Drumul cererii e cel al site-ului, dar el purta mai puțin decât o comandă, iar ce nu purta se pierdea tăcut între formular și acceptare: **modul de onorare** (agentul alegea „Curier", iar la acceptare `route_requests_accept()` cădea pe `default_fulfillment()`, adică pe altceva), **urgent** și **la oră fixă** (se puteau cere numai la acceptare, deci graba știută de cel care a luat telefonul nu ajungea la cel care hotărăște) și **cine a scris-o** (`created_by`: fără ea, comanda născută din cererea agentului ar fi purtat numele administratorului care a apăsat „Acceptă", iar agentul ar fi dispărut din propria lui muncă). Coloana `created_by` ține două întrebări deodată — „cine" și „de unde": o cerere de pe site n-are autor, deci comanda născută din ea nu mai e însemnată „site" pe nedrept. Cere `001` (cheie străină spre `users`) și se rulează după `021`. |
| `023_laboratorul_si_livrarea.sql` | Da — două coloane, fiecare cu paza ei în `information_schema`; niciun index, nicio cheie, niciun rând de date. Nu atinge nimic din ce există: `orders.lab_seq` se naște `NULL` („nearanjată"), iar `users.poate_confirma_livrarea` se naște `0`, pentru toți. `lab_seq` mută ordinea de lucru a laboratorului din browserul fiecărei tablete pe server — până acum ea stătea în `localStorage`, deci brutarul care aranja ziua aranja numai pentru el, iar colegul de lângă el vedea altceva; și, fiindcă împărțirea cantităților produse merge DE SUS ÎN JOS pe lista aceea, aceeași apăsare umplea alte comenzi, după tableta de pe care s-a apăsat. E soră cu `route_seq` din `003` și scrisă la fel (`NULL`, nu `0`, fiindcă `0` e o poziție adevărată — cea dintâi), dar nu se poate refolosi aceea: `route_seq` e ordinea OPRIRILOR mașinii, pusă de birou și păzită de `require_admin()`, iar ordinea în care mașina trece pe la clienți n-are nimic de-a face cu ordinea în care laboratorul coace. `poate_confirma_livrarea` e semnul fără de care serverul refuză etapa finală — „Predată" înseamnă *ajunsă la client*, și o punea oricine din personal: laboratorul o apăsa când termina de ambalat, iar comanda apărea livrată înainte să fi urcat în mașină. **Nicio umplere înapoi, dinadins**: toți se nasc fără semn, iar patronul bifează șoferul din „Conturi". O umplere „ca să nu înrăutățim" ar fi pus semnul tocmai pe contul comun al tabletelor — adică pe contul din pricina căruia s-a cerut lucrarea —, iar regula ar fi plecat la drum nefăcând nimic, fără ca nimeni să bage de seamă. Administratorul și fondatorul îl au din cod (`poate_confirma_livrarea()`, `api/lib/auth.php`), deci biroul nu se poate încuia pe dinafară. Cere `001` și se rulează după `022`. |
| `024_doua_cataloage.sql` | Da — trei coloane pe `products`, fiecare cu paza ei în `information_schema`; niciun index, nicio cheie străină. Are însă și un pas care **scrie date** — umplerea celor două bife —, iar acela e zăvorât cu `@coloane_noi`, numărat ÎNAINTE de `ALTER`-e: la a doua rulare `UPDATE`-ul nici nu se pregătește. Fără zăvor, fiecare actualizare ar fi pus la loc, lună după lună, fiecare latură pe care patronul o stinsese cu mâna — exact pățania lui `016`, și de-aia `.claude/test-migrari-repetate.mjs` o are și pe asta sub ochi. Umplerea e **prudentă dinadins**: `in_frozen = in_baked = (amândouă prețurile scrise)`, deci migrarea **nu mișcă nimic pe vitrină** — cine se vedea ieri se vede și azi, cine era ascuns rămâne ascuns. Ce se schimbă e numai vorba cu care panoul îl arată: „nu e în niciun catalog" în loc de „îi lipsește un preț". Forma îndrăzneață (`in_frozen = price_frozen IS NOT NULL`) ar fi ghicit apartenența din preț — tocmai ce a hotărât patronul să nu se facă — și ar fi publicat produse pe jumătate completate în secunda importului, fără niciun ecran între el și site. Nu atinge `photo`, nu atinge `active`, nu adaugă nicio grupă. Cere `001` și se rulează după `023`. |
| `025_registru_migrari.sql` | Da — face tabelul `schema_migrations` cu `CREATE TABLE IF NOT EXISTS` și scrie un singur rând, cu `INSERT IGNORE`: a doua rulare nu face nimic. Nu atinge nicio altă tabelă și niciun rând de date. E fișierul care **se rulează înaintea celorlalte**, nu la rândul lui: se cheamă `025`, deci în ordinea numelui ar veni după `003`–`024`, iar atunci primele douăzeci și două ar trece pe lângă un tabel care încă nu există și și-ar scrie numele abia la actualizarea următoare. Cele trei drumuri de urcare îl rulează o dată la început, apoi bucla — se poate, fiindcă e idempotent. **Ce NU face: nu se umple cu mâna.** S-ar putea scrie în el, dintr-o suflare, numele tuturor migrărilor până la `024` („dacă ai ajuns la 025, au rulat și alea") — adevărat când rulează bucla, minciună când cineva îl importă singur, din phpMyAdmin, pe o bază veche. Un registru care minte e mai rău decât niciun registru. Nu cere nimic și se rulează oriunde, pe orice bază. |
| `026_cheie_cerere.sql` | Da — o coloană `idempotency_key` pe `order_requests` și un index unic `uq_req_cheie` peste ea, fiecare cu paza lui în `information_schema`: a doua rulare le găsește și nu face nimic. Nu scrie și nu schimbă niciun rând de date. Coloana se naște `NULL` și stă `AFTER created_at` fiindcă acela e ultimul câmp din `001_schema.sql`, deci e singurul loc care există pe **orice** bază, oricât de veche. Indexul unic trece peste cererile de până acum fără să se plângă, oricâte ar fi: în SQL `NULL` nu se ciocnește cu `NULL`, deci vechile cereri — toate fără cheie — rămân neatinse, și la fel cele care vin mai târziu de la un telefon cu pagina veche în memorie. **Ce NU face: nu curăță cererile duble adunate înainte.** Ea oprește perechea următoare, nu o desface pe cea de ieri — două cereri vechi, identice, rămân două rânduri, fiindcă n-avem de unde ști dacă omul a apăsat de două ori sau chiar a vrut două comenzi. Și **nu ea alege ce se întâmplă la ciocnire**: indexul doar spune „cheia asta a mai fost"; răspunsul — cererea dintâi, întoarsă ca și cum ar fi acum — e scris în `api/routes/requests.php`. O bază care ia migrarea fără fișierele noi nu pățește nimic: coloana rămâne goală și nimeni n-o citește. Nu cere nimic și se rulează oriunde. |
| `027_poza_fiecarei_laturi.sql` | Da — și e singura de până acum care **nu atinge deloc schema**: nici tabel, nici coloană, nici index. Ea copiază o dată `products.photo` în `products.photo_baked` acolo unde a doua e goală, adică face a fiecărei laturi poza pe care până în 2.4.1 o ÎMPRUMUTA de la cealaltă. Zăvorul nu e în `information_schema` — n-ar avea ce căuta acolo, fiindcă nu se schimbă nimic în schemă —, ci în `settings`: `migration_027_poza_pe_latura`, scris la coada ei. A doua rulare îl găsește și nu mai copiază nimic, deci o poză pe care ai schimbat-o între timp la gata de servire rămâne a ta. **Ce NU face: nu pune la loc pozele de după ea.** Un produs născut după migrare are `photo_baked` gol până îi urci una — și așa și trebuie, de vreme ce împrumutul s-a tăiat. O bază care ia migrarea fără fișierele noi nu pățește nimic: cele două coloane ajung să poarte același nume de fișier, iar codul vechi arăta oricum poza aceea pe amândouă laturile. Nu cere nimic și se rulează oriunde. |

Dacă o versiune viitoare are nevoie de o **setare** nouă, primești un fișier
separat care conține doar acel `INSERT IGNORE INTO settings`. Nu reimportul
seed-ului.

### Cum verifici că migrarea a intrat

`api/verifica.php` **nu te ajută**: pe o instalare vie răspunde 404
(`api/verifica.php:926`). phpMyAdmin → fila **SQL** → lipești și apeși Go.

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

Pentru starea de azi — migrările **003 … 026** — trebuie să întoarcă
**53 de rânduri**:

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
        ('order_requests','idempotency_key'))
UNION ALL
SELECT TABLE_NAME, '(tabel)'
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('founder_invites', 'lab_shifts', 'lab_messages',
                      'request_claims', 'telegram_links', 'lab_production',
                      'schema_migrations')
UNION ALL
SELECT 'settings', CONCAT(setting_key, ' = ', value)
  FROM settings
 WHERE setting_key IN ('referral_rate_default', 'company_address', 'company_hours')
UNION ALL
SELECT TABLE_NAME, INDEX_NAME
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE()
   AND INDEX_NAME IN ('ix_orders_client_rang', 'ix_orders_autor',
                      'ix_req_autor', 'uq_req_cheie')
   AND SEQ_IN_INDEX = 1;
```

**Mai puține rânduri decât 53 → citește care lipsesc.** Numele coloanei îți
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
`order_requests` și indexul ei `uq_req_cheie` din 026.

Rândurile care pot lipsi fără pagubă sunt cele trei de index: `020`, plus câte o
bucată din `021` și din `022`. Fără ele insignele de client, lista de comenzi și
lista de cereri ies la fel, doar mai încet — nicio poartă din cod nu le caută.
Celelalte lipsuri sunt lucrări care nu merg.

`021` e prima migrare care aduce deodată din toate felurile: o coloană
(`users.telegram_user_id`), două tabele (`telegram_links`, `lab_production`) și
un index (`ix_orders_autor`) — de-aia urcă numărul cu patru dintr-un foc. Ce se
pierde fără fiecare: fără coloană, faptele apăsate în Telegram ies toate pe
seama unui singur om, cel care a legat botul; fără `telegram_links`, legarea nu
se poate nici măcar porni; fără `lab_production`, raportul de producție pe tură
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
ele stau în `settings`, nu în schemă; pe acelea le vezi în Setări → Zone, unde
zona nouă trebuie să fie în listă și fără coordonate.

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
import, o pagină rămasă deschisă de ieri — e refuzată întreagă, cu **toate**
cheile lipsă numite și fără să se scrie nimic din ea
(`api/lib/settings.php:441-455`, codul `unknown_setting`). De-aceea aici nu
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
   miniatura: cererea trece prin redirecționarea din `.claude/build-deploy.mjs:543`,
   deci probează și `.htaccess`-ul de pe `lab`.
7. **Setări → anunțurile care n-au plecat.** Dacă apar eșecuri cu ora
   actualizării, niște e-mailuri de comandă s-au pierdut în fereastra de urcare.
   Comenzile sunt salvate, doar mesajele n-au plecat — sunt scrise în tabelul
   `notifications`, deci se știe exact care.
8. **Sesiunile nu se pierd.** Stau în tabelul `sessions`, nu în fișiere. Urcarea
   nu deconectează pe nimeni. Singurele lucruri care rup sesiunile sunt
   schimbarea domeniului de cookie sau a cheii de criptare din `config.php`.

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
din toate comenzile (`api/routes/stock.php:650`). Dacă semnul apare cu comenzi
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
citește returul din registru, pe `order_id` (`api/lib/orders.php:1529`).

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
ține seama numai de etapă, nu de zi (`api/routes/stock.php:63`). Acum caseta
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

**Cum era.** `.claude/build-deploy.mjs:430-431` cere browserului să țină `.css`
și `.js` șapte zile, în timp ce `:434` cere paginilor să nu se memoreze deloc,
iar paginile chemau fișierele fără nicio versiune. Combinația implicită la
fiecare actualizare era deci exact pe dos: **pagină nouă cu `support.js`
vechi**, până la șapte zile.

Ce vedea clientul: o casetă gri cu text tehnic în engleză în locul catalogului.
Tu nu vedeai nimic, fiindcă tocmai îți goliseși cache-ul urcând. Mai rău: plasa
aceea prinde doar erorile de randare — o eroare la trimiterea comenzii nu e
prinsă de nimic, iar butonul rămâne pe „Se trimite…" la nesfârșit. Clientul
crede că a comandat.

**Ce s-a schimbat.** `puneVersiuni()` (`.claude/build-deploy.mjs:280`) lipește
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
din `api/` trunchiat → JSON valid cu 500, prins de `api/index.php:372-378`.
Vestea bună: comenzile și cererile rulează în tranzacții, deci **nu rămân
comenzi pe jumătate în bază**.

### 6. Modificări de catalog făcute în timpul zilei

Nu ține de actualizare, dar apare exact atunci și e pus pe seama ei. Coșul
clientului se salvează în browserul lui cu **doar cantitățile**, nu prețurile
(`Site Public.dc.html:2567`), deci o schimbare de preț se vede corect. **Dar
dispariția e complet tăcută**: `:3179` ține în vitrină numai produsele bifate în
catalogul deschis și cu prețul de acolo scris, iar `:3522-3528` scoate din coș
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
(422, mesajul numește catalogul și caseta — `api/routes/catalog.php:766`), iar
stingerea bifei **lasă prețul scris**. Deci scoaterea dintr-un catalog e o
singură apăsare, și atunci pier din coșuri numai rândurile laturii aceleia;
cealaltă nu se clintește.

Până se repară dispariția tăcută: produsele se scot din gamă seara.

---

## Ce e de îmbunătățit, ca actualizările să fie mai sigure

Trei lucruri, ordonate după cât ajută față de cât costă. Două sunt făcute, iar
a treia a căzut odată cu publicarea din panoul de găzduire.

1. ~~**Versionarea fișierelor pentru cache**~~ — făcută în 2.0.3:
   `puneVersiuni()` (`.claude/build-deploy.mjs:280`) lipește pe fiecare
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
