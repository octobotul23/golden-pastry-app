# Publicarea pe cPanel

Aplicația se împarte pe patru adrese. Fiecare e un folder separat în cPanel.

| Adresă | Folder | Ce conține |
|---|---|---|
| `goldenpastry.ro` | `public_html/` | site-ul public, de unde se comandă (servit de `index.php`) |
| `clienti.goldenpastry.ro` | `public_html/clienti/` | intrarea și panoul clienților |
| `lab.goldenpastry.ro` | `public_html/lab/` | laborator, șofer, depozit, administrare, setări |
| `api.goldenpastry.ro` | `public_html/api/` | serverul care ține datele |

Pachetul gata de urcat este în folderul **`deploy/`**. Nu edita nimic acolo:
se generează cu `node .claude/build-deploy.mjs` și se suprascrie la fiecare
rulare.

Durează în jur de 40 de minute prima dată. Ordinea contează — baza de date
înaintea fișierelor, altfel primul lucru pe care îl vezi e o eroare.

---

## 1. Baza de date

**cPanel → MySQL® Databases**

1. **Create New Database**: scrie `gp`. Numele final va fi `numecont_gp`.
2. **Add New User**: nume `gp`, iar la parolă folosește **Password Generator**.
   Copiaz-o într-un loc sigur — o vei scrie o singură dată, în pasul 4.
3. **Add User To Database**: alege utilizatorul și baza, apoi **ALL PRIVILEGES**.

Notează-ți numele complete, cu tot cu prefix. Le vei scrie în `config.php`.

**cPanel → phpMyAdmin**, alege baza `numecont_gp` din stânga, apoi fila
**Import**:

1. Încarcă `sql/001_schema.sql` → **Go**. Creează cele 17 tabele.
2. Încarcă `sql/002_seed.sql` → **Go**. Pune produsele, articolele de depozit
   și setările implicite.

`sql/003_route_order.sql`, `sql/004_variante_pe_pozitie.sql`,
`sql/005_parola_schimbata.sql` și `sql/006_retete_rezervari.sql` **nu se
importă** la o instalare nouă — schema conține deja tot ce aduc ele. Sunt acolo
doar pentru o bază creată înainte de acele modificări.

> Importă întotdeauna din phpMyAdmin, nu din linia de comandă. Clientul MySQL
> de pe Windows citește fișierul în codificarea consolei, nu UTF-8, iar
> diacriticele se strică: „Predată" devine „Predat─â", și aplicația nu mai
> recunoaște etapa.

**Verifică:** în stânga trebuie să apară 17 tabele, iar `products` să aibă
22 de rânduri.

---

## 2. Subdomeniile

**cPanel → Domains → Create A New Domain** (sau **Subdomains**), de trei ori:

| Subdomain | Document Root |
|---|---|
| `clienti` | `public_html/clienti` |
| `lab` | `public_html/lab` |
| `api` | `public_html/api` |

Debifează „Share document root with…" — fiecare are nevoie de folderul lui.

**cPanel → SSL/TLS Status**: bifează toate cele patru domenii și apasă
**Run AutoSSL**. Durează câteva minute. Nu urca nimic până nu apar toate cu
verde: fără certificat, cookie-ul de sesiune nu circulă și nimeni nu se poate
autentifica.

---

## 3. Fișierele

**cPanel → File Manager**. Cel mai simplu e cu arhive: File Manager decomprimă
pe server, deci urci un fișier în loc de câteva mii.

Pentru fiecare folder din `deploy/`, fă o arhivă `.zip` din **conținutul** lui
(nu din folderul însuși) și urcă-o unde trebuie:

| Arhivezi conținutul din | Urci și extragi în |
|---|---|
| `deploy/public_html/` | `public_html/` |
| `deploy/clienti/` | `public_html/clienti/` |
| `deploy/lab/` | `public_html/lab/` |
| `deploy/api/` | `public_html/api/` |

După extragere, șterge arhivele de pe server.

**Atenție la fișierele care încep cu punct.** `.htaccess` există în toate
patru folderele și este esențial: fără el, API-ul nu răspunde deloc, iar
adresele fără „.html" dau 404. În File Manager: **Settings → Show Hidden Files
(dotfiles)**. Verifică după extragere că fiecare folder are `.htaccess`.

`public_html/` mai conține și `.image-slots.state.json` — tot ascuns, tot
necesar (fără el, trei imagini de pe site rămân goale).

**Folderul `products/` trebuie să permită scrierea.** Acolo ajung fotografiile
urcate din pagina Catalog. În File Manager: clic dreapta pe folder →
**Change Permissions** → **755**. Fără asta, butonul de încărcare eșuează abia
la prima poză.

---

## 4. Configurarea

În `public_html/api/` există `config.example.php`. Copiază-l ca `config.php`
(**Copy**, apoi **Rename**) și deschide-l cu **Edit**.

Completează:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'numecont_gp',      // numele complet, cu prefix
    'user' => 'numecont_gp',
    'pass' => 'parola de la pasul 1',
],
```

**Cheia de criptare.** Protejează tokenurile din panoul de Setări. Trebuie să
aibă exact 64 de caractere hexazecimale: cifre și literele de la a la f, nimic
altceva. Un șir alfanumeric de 64 de caractere arată la fel de bine, dar
aplicația îl refuză la prima criptare — adică la crearea primului
administrator, când e prea târziu. Generează cheia cu PHP (cPanel → Terminal,
sau pe calculatorul tău):

```
php -r "echo bin2hex(random_bytes(32));"
```

Lipește rezultatul, fără spații, în `config.php`:

```php
'encryption_key' => 'aici cele 64 de caractere hexazecimale',
```

Nu o schimba după ce ai salvat tokenuri: devin ilizibile și trebuie
reintroduse.

**Restul, pentru producție:**

```php
'cookie_domain' => '.goldenpastry.ro',   // punctul din față e obligatoriu
'cookie_secure' => true,
'env'           => 'prod',
```

Punctul din fața domeniului face sesiunea valabilă pe toate subdomeniile — de
el depinde ca autentificarea de pe `clienti.` să fie recunoscută și pe `lab.`.

`env => 'prod'` oprește afișarea erorilor interne în browser.

**Codul de instalare.** Cu el se deschide, la pasul 6, pagina care creează
primul administrator. Ia unul din **Password Generator** (același buton ca la
parola bazei de date) și pune-l aici:

```php
'install_code' => 'codul de cel puțin 12 caractere',
```

Fără el, `setup.php` nu creează niciun cont. Fișierul acela ajunge pe
`api.goldenpastry.ro` odată cu restul și stă acolo până când îți aduci aminte
să-l ștergi; între momentul ăsta și pasul 6 pot trece ore, iar fără cod primul
străin care nimerește adresa devine administratorul patiseriei. Notează-l lângă
parola bazei — îl scrii o singură dată, în formularul de la pasul 6, și după
aceea nu-ți mai trebuie.

---

## 5. Verificarea

Deschide **`https://api.goldenpastry.ro/verifica.php`**.

Trebuie să iasă totul verde. Dacă nu:

| Ce scrie | Ce faci |
|---|---|
| `pdo_mysql` sau `openssl` lipsesc | cPanel → Select PHP Version → Extensions → bifează |
| Fără drept de scriere în `products/` | Change Permissions → 755 pe folder |
| Conectarea la baza de date a eșuat | numele bazei și al utilizatorului au prefixul contului |
| Lipsesc tabele | reia importul din pasul 1 |
| Cheia de criptare | în `config.php` nu sunt exact 64 de caractere hexazecimale |
| Conexiune HTTPS: nu | AutoSSL n-a terminat, sau `.htaccess` lipsește |

**Șterge `verifica.php` după ce totul e verde.** Dacă uiți, nu rămâne deschisă
pentru oricine: din clipa în care există un cont de administrator (pasul 6),
pagina răspunde 404, ca și cum ar fi fost ștearsă. Până atunci e deschisă, așa
că nu lăsa instalarea neterminată peste noapte.

---

## 6. Primul cont de administrator

Baza de date pornește **fără niciun cont de om**. Singurul cont existent este
`panou`, contul de sistem al tabletelor din laborator, în care nu se intră cu
parolă. Până creezi un administrator, nimeni nu poate intra nicăieri.

Deschide **`https://api.goldenpastry.ro/setup.php`** și completează formularul.
Primul câmp este **codul de instalare** — cel pe care l-ai pus la pasul 4 în
`config.php`, la `install_code`. Fără el pagina nu creează nimic, oricâte
încercări ar veni de pe internet. Restul: alege singur utilizatorul și parola —
eu nu introduc niciodată credențiale. Parola trebuie să aibă minimum 10
caractere; e contul cu drepturi depline peste comenzi, conturi și setări, deci
nu o refolosi de altundeva.

Dacă pagina îți spune că nu e pus niciun cod de instalare, deschide
`api/config.php` în File Manager, completează `install_code` cu un cod de cel
puțin 12 caractere, salvează și reîncarcă.

Pagina merge **o singură dată**. Din clipa în care există un administrator
activ, `setup.php` răspunde 404 — la fel ca `verifica.php`. Nu mai spune nici
măcar că există: pentru cine trece pe acolo, adresa arată ca și cum fișierul ar
fi fost deja șters. Ștergerea rămâne de făcut, dar dacă uiți, pagina nu mai
poate crea niciun cont.

> **Notează codul tabletelor.** După ce creezi contul, pagina afișează, într-o
> casetă galbenă, un cod de opt caractere generat atunci, pe loc. Cu el se
> deschid panourile de lucru de pe tabletele din laborator, fără cont
> individual. Se păstrează criptat, deci **nu mai poate fi afișat niciodată** —
> nici de pagina asta, nici din Setări. Dacă îl pierzi, pui pur și simplu altul
> din *Setări → Acces*.
>
> Codul nu mai e o valoare fixă, aceeași la orice instalare: una fixă ar fi
> însemnat că oricine a citit proiectul poate deschide panourile tale.

**Șterge `api/setup.php` de pe server imediat după.**

---

## 7. Proba de foc

În ordine:

1. `https://goldenpastry.ro` — se vede catalogul, se poate trimite o cerere.
2. `https://lab.goldenpastry.ro` — pagina de intrare.
3. Intră cu contul de administrator → **Comenzi** → cererea trimisă la punctul 1
   trebuie să fie acolo.
4. Acceptă cererea → devine comandă.
5. `https://clienti.goldenpastry.ro` — intră cu un cont de client, vezi comanda.

Dacă pasul 3 merge, partea grea e gata: datele chiar circulă între dispozitive.

---

## 8. Primele setări

**`lab.goldenpastry.ro/setari`**, cu contul de administrator.

- **General** — numele firmei, telefonul, adresa de contact.
- **Notificări** — adresa pe care vrei să primești comenzile noi, apoi
  **Trimite e-mail de test**. Pentru WhatsApp, pașii sunt în
  [02-panoul-de-setari.md](02-panoul-de-setari.md).
- **Acces** — aici schimbi codul tabletelor. Cel de la instalare merge deja;
  pune altul când pleacă cineva din echipă, sau dacă l-ai pierdut. Codul nu se
  poate afișa — se păstrează criptat — deci câmpul arată doar dacă există unul.
  Cât timp nu e configurat niciunul, tabletele nu pot deschide panoul: serverul
  răspunde „Codul panoului nu este configurat", adică poarta stă închisă, nu
  deschisă.
- **Liste** — etapele, intervalele și zonele, dacă ale tale diferă.

**`lab.goldenpastry.ro/conturi`** — creează conturile echipei. Fiecare client
care trebuie să-și vadă comenzile are nevoie de un cont cu rolul „client":
serverul îi arată exclusiv comenzile lui, filtrarea nu se face în browser.

Nu există conturi implicite în afara celui creat de tine la pasul 6 — nici
demo, nici de test. Cele din prototip (`admin`/`goldenpastry`,
`sofia`/`patiserie`) au fost șterse: parolele lor erau scrise în sursa
paginilor, vizibile oricui.

**Depozitul pornește de la zero.** Toate articolele apar sub prag până faci un
inventar real: `lab.goldenpastry.ro/depozit` → **+ Mișcare** → **Intrare** →
„Corecție inventar", cu cantitățile de pe raft.

---

## 9. Actualizări

> **Ce trebuie adăugat de mână la această actualizare.** `api/config.php` nu se
> suprascrie niciodată — de aceea cheile noi nu apar singure, iar aplicația
> merge mai departe cu valorile vechi fără să se plângă. Copiază din
> `api/config.example.php`:
>
> ```php
> 'session_days_client' => 30,   // fără ele, ambele roluri rămân la 'session_days'
> 'session_days_staff'  => 7,
> 'reset_minutes'       => 60,   // cât ține linkul de parolă nouă
> 'max_forgot_attempts' => 10,
> 'reset_url'           => '',   // gol = linkul duce înapoi de unde s-a cerut
> 'lock_window_min'     => 60,   // pe ce interval se numără greșelile care blochează contul
> ```
>
> Fără `lock_window_min`, blocarea cade pe valoarea implicită din cod (60 de
> minute) — deci aplicația merge, dar cheia lipsește din fișier și nu se vede
> că se poate regla.
>
> Și importă din phpMyAdmin, în ordinea asta:
>
> - `sql/003_route_order.sql` — adaugă coloana `users.address`, adresa de
>   livrare ținută pe contul clientului.
> - `sql/005_parola_schimbata.sql` — adaugă coloana
>   `users.password_changed_at`. Fără ea, pagina Conturi nu poate spune dacă
>   omul și-a schimbat singur parola sau încă merge cu cea dictată de tine:
>   până acum scria acolo data ultimei autentificări, sub eticheta „parolă
>   schimbată".
> - `sql/006_retete_rezervari.sql` — adaugă tabelele `product_recipes` și
>   `stock_reservations`, coloana `order_items.done_qty` și coloana
>   `stock_moves.order_id`. Fără ele nu se poate scrie din ce e făcut un produs,
>   laboratorul nu poate raporta câte bucăți a făcut din fiecare poziție, iar
>   depozitul nu știe cât din stoc e deja promis unei comenzi.
>
>   Toate fișierele se pot rula de câte ori vrei; ce există deja e sărit, iar
>   niciunul nu atinge setările pe care le-ai ales din panou.
> - **Nu reimporta `sql/002_seed.sql`.** Ai nevoie doar de o setare nouă,
>   **„Moduri fără mașină"**, de care depinde ce ajunge pe ruta șoferului.
>   Rulează în phpMyAdmin, fila **SQL**, doar rândul ăsta:
>
>   ```sql
>   INSERT IGNORE INTO settings (setting_key, value, is_secret)
>   VALUES ('fulfillment_pickup', '["Angro","Ridicare"]', 0);
>   ```
>
>   Fără el, ruta merge ca înainte, pe valorile implicite, dar lista nu se
>   poate edita din panou. Fișierul întreg **nu** e sigur la reimport:
>   `sql/002_seed.sql:76-229` sunt 22 de `UPDATE` necondiționate care îți
>   rescriu prețurile, denumirile, descrierile și **fotografiile**
>   produselor 1–22. Vezi [07-actualizari.md](07-actualizari.md).
>
> Verificarea **nu** se face cu `api/verifica.php`: pe o instalare care are
> deja un administrator activ, fișierul răspunde 404
> (`api/verifica.php:296-301`). Folosește în schimb interogarea de control
> din [07-actualizari.md](07-actualizari.md), secțiunea „Cum verifici că
> migrarea a intrat".


Când schimb ceva în cod:

```bash
node .claude/build-deploy.mjs
```

Apoi urci doar folderele atinse. **`api/config.php` nu se suprascrie
niciodată** — nu face parte din pachet tocmai ca să nu-l pierzi din greșeală.

Baza de date nu se atinge la actualizări obișnuite. Dacă vreuna cere o
modificare de schemă, primești un fișier `sql/00N_….sql` separat, cu
instrucțiuni.

---

## Ce merge prost cel mai des

**„Nu mă pot autentifica" pe un singur subdomeniu.**
`cookie_domain` din `config.php` nu începe cu punct.

**Paginile arată nestilizat, fără fonturi.**
Folderul `_ds` n-a fost extras complet. Fiecare subdomeniu are nevoie de
propria lui copie.

**API-ul răspunde cu 404 la orice.**
`.htaccess` lipsește din `public_html/api/` — nu s-a extras, fiind fișier
ascuns.

**„Eroare internă" fără detalii.**
Normal pe `env => 'prod'`. Cauza reală e în cPanel → **Errors**, sau în
`error_log` din folderul respectiv.

**O comandă nouă nu ajunge pe e-mail.**
Panoul de Setări → butonul de test spune de ce. Cel mai des, adresa
expeditoare nu e pe domeniul tău și serverul refuză să trimită.
