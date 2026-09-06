# Cum publici o modificare pe site-ul care merge deja

Ghidul ăsta e pentru **după** prima publicare, când pe server sunt deja comenzi
reale, clienți reali și poze urcate de tine. Instalarea de la zero e în
[03-publicare-cpanel.md](03-publicare-cpanel.md).

Ideea de bază, în două rânduri: **sursa e pe calculatorul tău, `deploy/` e doar
rezultatul.** Modifici sursa, rulezi un script, urci ce a ieșit. Folderul
`deploy/` se șterge și se reface complet la fiecare construire
(`.claude/build-deploy.mjs:329`), deci orice ai scrie direct acolo dispare.

---

## Regula de aur

Cinci lucruri. Niciunul nu are excepții.

**1. `public_html/api/config.php` nu se suprascrie niciodată.**
Acolo stau parola bazei de date și cheia de criptare. Nu e în pachet deloc —
scriptul îl scoate (`.claude/build-deploy.mjs:409-410`) și mai verifică o dată
la final (`:427-433`), deci nicio urcare nu-l poate atinge din greșeală.
**Cheia de criptare nu se schimbă niciodată după instalare.** Dacă se schimbă,
`api/lib/crypto.php:85` întoarce `null`, iar `api/lib/settings.php:36` doar
scrie o linie în jurnal și merge mai departe — *tăcut*. Efectul vizibil:
codul tabletelor devine gol, `api/routes/auth.php:250-254` răspunde 503
„Codul panoului nu este configurat", și toată echipa din laborator rămâne
pe dinafară.

**2. `public_html/products/` nu se șterge și nu se mai urcă după prima instalare.**
Pozele pe care le încarci din pagina Catalog trăiesc **numai** acolo:
`api/routes/catalog.php:369` le dă un nume cu cifre aleatoare
(`prod-14-9c3a71ef.jpg`), `:370` le scrie pe disc, și nimic nu le aduce înapoi
în proiect. Pachetul conține și el un folder `products/`, construit din copia
mea locală — cele două se depărtează unul de altul din prima zi. Urcând peste,
ștergi pozele adăugate live, iar în bază rămân trimiteri către fișiere care
nu mai există.

**3. `sql/001_schema.sql` și `sql/002_seed.sql` nu se mai importă niciodată.**
Al doilea import al schemei se oprește cu eroarea `#1826`
(`sql/001_schema.sql:229-230`, o cheie străină adăugată neprotejat). Iar
seed-ul conține 22 de `UPDATE products` necondiționate
(`sql/002_seed.sql:76-229`) care îți rescriu prețurile, denumirile,
descrierile, gramajele **și fotografiile**. Detalii la capcana 1.

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
(`.claude/build-deploy.mjs:168, 238, 254, 272`), nu pe server.

---

## Cazul obișnuit: s-a schimbat doar aspectul sau textul

Adică una sau mai multe pagini `.dc.html`, sau `_ds/`, sau `assets/`. Nimic în
`api/`, nicio migrare.

### 1. Construiește pachetul

```bash
node .claude/build-deploy.mjs
```

Dacă la final scrie „N probleme", **te oprești aici** — scriptul iese cu cod 1
(`:444-447`). Două motive posibile: o legătură către o pagină pe care nu o
cunoaște (`:335-337`), sau `setup.php` / `verifica.php` a rămas fără zăvorul
de după instalare (`:418-433`).

### 2. Vezi ce s-a schimbat față de ce e pe server

Harta sursă → pachet e fixă (`.claude/build-deploy.mjs:33-51` și `:56-63`):

| Ai modificat | Se schimbă în pachet | Urci în |
|---|---|---|
| `Site Public.dc.html` | `public_html/app.html` | `public_html/` |
| `Administrare Comenzi.dc.html` | `lab/comenzi.html` | `public_html/lab/` |
| `Panou Laborator` · `Depozit` · `Conturi` · `Jurnal` · `Ruta Sofer` · `Produse` · `Setari` | câte un `.html` în `lab/` | `public_html/lab/` |
| `Client.dc.html` | `clienti/client.html` | `public_html/clienti/` |
| **`Intrare.dc.html`** | `clienti/index.html` **și** `lab/index.html` | **două foldere** |
| **`Parola.dc.html`** | `clienti/parola.html` **și** `lab/parola.html` | **două foldere** |
| `support.js` · `shared/gp-api.js` · `_ds/` · `assets/` | **câte trei copii** | **toate trei subdomeniile** |
| `image-slot.js` · `.image-slots.state.json` | o singură copie | `public_html/` |
| `seo/index.php` · `seo/sitemap.php` | `public_html/index.php`, `sitemap.php` | `public_html/` |

`Intrare` și `Parola` sunt singurele pagini care ajung în două locuri, iar
**cele două copii nu sunt identice**: scriptul le injectează adresa API-ului
(`:344-345`) și rescrie legăturile dintre subdomenii (`:136-153`). O modificare
acolo cere două urcări.

### 3. Scoate din pachet ce n-are voie să plece

Înainte de a face arhiva, șterge din `deploy/`:

- **`deploy/public_html/products/`** — regula 2 de mai sus.
- **`deploy/api/setup.php`** și **`deploy/api/verifica.php`** — revin în pachet
  la fiecare construire, deliberat (`:379`, motivat la `:408-417`). Amândouă au
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
paginile, clientul primește pe ecran exact textul de la `api/index.php:224` —
„Rută inexistentă: POST /requests/12/reopen" — iar comanda pur și simplu nu se
face. Cu ordinea corectă, fereastra e „API nou + pagini vechi", care nu strică
nimic.

*Excepție:* dacă îți spun explicit că o actualizare **schimbă forma** unui
răspuns, nu doar adaugă, atunci nicio ordine nu e sigură. Aceea se face
duminică dimineața, nu la prânz.

**Compară `config.example.php` cu `config.php` de pe server.** Fiindcă
`config.php` nu se suprascrie niciodată, cheile noi **nu apar singure**, iar
aplicația merge mai departe pe valorile implicite fără să se plângă. S-a
întâmplat deja o dată: [03-publicare-cpanel.md:279-295](03-publicare-cpanel.md)
listează șase chei care au trebuit adăugate de mână. Deschide exemplul alături
și copiază ce lipsește — niciodată invers.

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
excepție → `api/index.php:225-230` întoarce „Eroare internă." Nu e o pagină
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
| `002_seed.sql` | **NU, niciodată în întregime.** Vezi capcana 1. |
| `003_route_order.sql` | Da — fiecare pas e învelit într-un test pe `information_schema`. **Dar** `:104` readuce grupele de produse pe care le-ai șters din pagina Produse. |
| `004_variante_pe_pozitie.sql` | Da, dar fără motiv nu. |
| `005_parola_schimbata.sql` | Da, complet sigur — nu atinge niciun rând. |
| `006_retete_rezervari.sql` | Da — fiecare pas se uită întâi în `information_schema`. Nu scrie în `settings` și nu șterge nimic. |

Dacă o versiune viitoare are nevoie de o **setare** nouă, primești un fișier
separat care conține doar acel `INSERT IGNORE INTO settings`. Nu reimportul
seed-ului.

### Cum verifici că migrarea a intrat

`api/verifica.php` **nu te ajută**: pe o instalare vie răspunde 404
(`api/verifica.php:332-342`). phpMyAdmin → fila **SQL** → lipești și apeși Go.
Pentru starea de azi (după 003, 004, 005, 006) trebuie să întoarcă **8 rânduri**:

```sql
SELECT TABLE_NAME, COLUMN_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND (TABLE_NAME, COLUMN_NAME) IN
       (('orders','route_seq'), ('orders','stock_synced'),
        ('order_items','variant'), ('order_request_items','variant'),
        ('notifications','seen_at'), ('users','password_changed_at'),
        ('order_items','done_qty'), ('stock_moves','order_id'));
```

La fiecare migrare nouă îți trimit lista lungită cu un rând.

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
6. **Catalog → un produs cu poză urcată de tine.** Confirmă că se vede
   miniatura: cererea trece prin redirecționarea din `.claude/build-deploy.mjs:278`,
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
dată cele trei interogări de mai jos prin phpMyAdmin (fila **SQL**, pe baza
aplicației) după ce ai urcat. Durează un minut și nu schimbă nimic: toate trei
sunt `SELECT`.

**Zero rânduri la toate trei = nu e nimic de îndreptat.** Nu te alarma dacă una
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

### 2. Comenzi scăzute de mai multe ori

Un produs redenumit după scădere putea fi scăzut a doua oară, pe articolul nou.
Interogarea compară cât a ieșit **net** din depozit pentru fiecare comandă (adică
scăderi minus returi) cu câte bucăți are comanda pe ea.

```sql
SELECT o.ref, o.`day`, o.customer_name,
       ROUND(-SUM(m.qty * m.direction), 2) AS iesit_net,
       (SELECT ROUND(SUM(i.qty), 2) FROM order_items i WHERE i.order_id = o.id) AS pe_comanda
  FROM orders o
  JOIN stock_moves m ON m.note = TRIM(CONCAT(o.ref, ' ', o.customer_name))
 GROUP BY o.id, o.ref, o.`day`, o.customer_name
HAVING iesit_net > pe_comanda
 ORDER BY o.`day` DESC;
```

Două lucruri de știut despre ea. **Nu dă alarme false:** `pe_comanda` numără
toate pozițiile comenzii, inclusiv cele care n-au deloc articol în depozit, deci
e un număr mai mare decât adevărul — ce iese pe listă chiar a ieșit în plus.
**Dar scapă cazuri:** legătura se face prin însemnarea din registru
(`„#1042 Restaurant Bistro"`), iar și referința, și numele clientului se pot
schimba din formularul comenzii. O comandă redenumită de atunci nu mai apare
aici. E aceeași fragilitate pentru care codul reparat nu caută returile după
însemnare, ci recalculează pozițiile (`api/lib/orders.php:518`).

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

---

## Capcanele, de la cea mai dureroasă în jos

### 1. Reimportul lui `002_seed.sql`

Fișierul are patru părți. Trei sunt într-adevăr `INSERT IGNORE` și nu strică
nimic. A patra, `sql/002_seed.sql:76-229`, sunt **22 de `UPDATE products SET …
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
cerere (`seo/index.php:76`), deci prețul vechi apare și în catalog, și în datele
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

### 3. Cache de 7 zile pe JavaScript, fără nicio versionare

`.claude/build-deploy.mjs:239-240` cere browserului să țină `.css` și `.js`
șapte zile, în timp ce `:232` cere paginilor să nu se memoreze deloc. Iar
paginile cheamă fișierele fără versiune (`deploy/public_html/app.html:39,41`).
Am numărat: **zero apariții de `?v=` în tot pachetul.**

Combinația implicită la fiecare actualizare e deci exact pe dos: **pagină nouă
cu `support.js` vechi**, până la șapte zile.

Ce vede clientul: o casetă gri cu text tehnic în engleză în locul catalogului.
Tu nu vezi nimic, fiindcă tocmai ți-ai golit cache-ul urcând. Mai rău: plasa
aceea prinde doar erorile de randare — o eroare la trimiterea comenzii nu e
prinsă de nimic, iar butonul rămâne pe „Se trimite…" la nesfârșit. Clientul
crede că a comandat.

**Din procedură nu se poate evita.** E singura problemă din listă pe care nu o
poți rezolva urcând fișiere într-o anumită ordine. Vezi ultima secțiune.

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
din `api/` trunchiat → JSON valid cu 500, prins de `api/index.php:224-230`.
Vestea bună: comenzile și cererile rulează în tranzacții, deci **nu rămân
comenzi pe jumătate în bază**.

### 6. Modificări de catalog făcute în timpul zilei

Nu ține de actualizare, dar apare exact atunci și e pus pe seama ei. Coșul
clientului se salvează în browserul lui cu **doar cantitățile**, nu prețurile
(`Site Public.dc.html:1104`), deci o schimbare de preț se vede corect. **Dar
dispariția e complet tăcută**: `:1210` scoate din catalog orice produs care nu
are **amândouă** prețurile, iar `:1294` filtrează din coș rândurile rămase fără
pereche, fără niciun mesaj.

Concret: un client își face coșul luni seara, 8 produse, 340 lei. Marți scoți un
produs din gamă și, la altul, ștergi prețul „copt" ca să-l recalculezi și uiți
să-l pui la loc. Miercuri clientul găsește 6 produse și 255 lei, fără nicio
explicație. Produsul cu prețul gol dispare **și din varianta congelat**, deși ai
atins un singur câmp.

Până se repară: produsele se scot din gamă seara, iar un preț nu se lasă gol
nici măcar un minut — se schimbă direct în valoarea nouă.

---

## Ce e de îmbunătățit, ca actualizările să fie mai sigure

Trei lucruri, ordonate după cât ajută față de cât costă. Niciunul nu e făcut
încă.

1. **Versionarea fișierelor pentru cache.** Un hash scurt în adresa lui
   `support.js`, `shared/gp-api.js` și `image-slot.js`, calculat la construire.
   Rezolvă capcana 3, care altfel nu se poate rezolva. Vreo zece linii în
   `.claude/build-deploy.mjs`, în locul unde se rescriu deja legăturile
   (`:136-153`, `:349-354`).
2. **Tăierea blocului distructiv din seed** într-un fișier separat, cu un nume
   care sperie, iar `002_seed.sql` să devină ce pretinde că e: numai
   `INSERT IGNORE`, sigur la reimport. Rezolvă capcana 1 din rădăcină.
   Aceeași reparație s-a făcut deja o dată în proiect, la
   `sql/003_route_order.sql:82-87` — blocul de produse a rămas nereparat.
3. **Un tabel `schema_migrations`.** Azi nu există absolut niciun mecanism de
   evidență: nici tabel, nici cheie în setări, nici marcaj în cod. Nu ai cum să
   afli ce ai importat deja, iar unealta care ar trebui să-ți spună
   (`api/verifica.php`) e zăvorâtă după instalare. Cu un rând per fișier,
   dispare o clasă întreagă de întrebări.
