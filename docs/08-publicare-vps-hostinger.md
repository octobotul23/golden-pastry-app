# Publicarea pe un VPS Hostinger (Ubuntu + Apache)

Ghidul de instalare de la zero, pe un server al tău. Patru adrese, o singură
bază de date, și tu ești administratorul mașinii — nu există panou de găzduire
care să facă vreun pas în locul tău.

| Adresă | Folder pe server | Ce conține |
|---|---|---|
| `goldenpastry.ro` | `/var/www/gp/public_html` | site-ul public (`index.php`) |
| `clienti.goldenpastry.ro` | `/var/www/gp/clienti` | intrarea și panoul clienților |
| `lab.goldenpastry.ro` | `/var/www/gp/lab` | laborator, șofer, depozit, administrare, setări |
| `api.goldenpastry.ro` | `/var/www/gp/api` | serverul care ține datele |

**Apache, nu nginx.** Aplicația se bazează pe `.htaccess` (rescrierea către
`api/index.php`, adresele fără `.html`, protecția lui `config.php`). Apache și
OpenLiteSpeed le citesc; nginx nu — cu nginx toate regulile ar trebui rescrise
de mână.

---

## Varianta scurtă: `instaleaza-vps.sh`

Scriptul din rădăcina proiectului face singur tot ce urmează în ghid: instalează
Apache + PHP + MySQL, creează baza și utilizatorul cu parolă generată, copiază
pachetul în cele patru foldere, scrie gazdele virtuale, generează
`api/config.php` (cheie de criptare, cod de instalare, `photo_dir`, cookie pe
`.domeniu`), importă schema, pune ceasul plăților (pasul 6b), pune programul
de poștă al serverului (pasul 6c) și cere certificatele.

Urci pe VPS `deploy/`, `sql/` și scriptul, unul lângă altul, apoi:

```bash
sudo bash instaleaza-vps.sh --domain goldenpastry.ro --email tu@exemplu.ro
```

Opțiuni: `--dir` (implicit `/var/www/gp`), `--no-ssl`, `--db-name`, `--db-user`,
`--db-root-pass` (dacă root-ul de MySQL are parolă), `--package /cale/spre/deploy`
și, de la 2.7.5, `--fara-posta` — nu pune programul de poștă (pasul 6c spune
ce pierzi și ce nu).

La final îți scrie pe ecran parola bazei și codul de instalare, plus adresele
de urmat: `verifica.php`, `setup.php`, panoul și cartonașul „E-mail” din
Setări → Notificări, unde completezi căsuța firmei (pasul 8d).

**Se rulează de câte ori vrei**, dar e scris pentru prima oară: nu atinge
`api/config.php`, nu reimportă baza și nu șterge pozele urcate din panoul
Catalog — însă nici nu aplică migrările și nici nu face copie de siguranță
înainte. Pentru actualizări e `actualizeaza-vps.sh`, cu ghidul lui,
`09-actualizare-vps.md`.

Restul ghidului rămâne util pentru ce face scriptul pas cu pas, și obligatoriu
dacă alegi CyberPanel în loc de Ubuntu curat.

---

## 0. Ce alegi la crearea VPS-ului

În hPanel → VPS → **Operating System**:

- **Ubuntu 24.04 curat** — varianta din ghidul ăsta. PHP 8.3 și MySQL 8 vin din
  depozitele oficiale, fără panou care să stea în drum.
- **CyberPanel** (template disponibil) — dacă vrei interfață grafică pentru mai
  multe site-uri: phpMyAdmin, File Manager, SSL cu un buton, foarte aproape de
  un panou de găzduire obișnuit. Pașii de mai jos se aplică aproape identic;
  sari peste secțiunile 2 și 4, pe care le face panoul.
- **CloudPanel** — nginx. Nu îl alege pentru aplicația asta.

Restul ghidului presupune Ubuntu 24.04 curat și conectare prin SSH ca `root`
(hPanel → VPS → SSH access; parola sau cheia de acolo).

---

## 1. DNS

La registrarul domeniului (sau în hPanel, dacă domeniul e la Hostinger), patru
înregistrări **A** către IP-ul VPS-ului:

```
@         A   IP_VPS
www       A   IP_VPS
clienti   A   IP_VPS
lab       A   IP_VPS
api       A   IP_VPS
```

Așteaptă să se propage înainte de pasul cu certificatele. Verifici cu
`ping api.goldenpastry.ro` — trebuie să răspundă IP-ul VPS-ului.

---

## 2. Serverul

```bash
apt update && apt upgrade -y
apt install -y apache2 mysql-server unzip cron \
  php php-mysql php-mbstring php-curl php-xml php-zip php-gd libapache2-mod-php
a2enmod rewrite headers expires
systemctl restart apache2

ufw allow OpenSSH
ufw allow 'Apache Full'
ufw --force enable
```

Verifici versiunea: `php -v` trebuie să arate 8.3 sau mai nou (aplicația cere
minim 8.0). Extensiile obligatorii: `pdo_mysql`, `openssl`, `mbstring`, `curl`,
`json`, `filter`.

`cron` e în listă de la 2.7.0, și nu de formă: e ceasul serverului, cel care
pornește singur, din cinci în cinci minute, verificarea raportului de încasări
(pasul 6b). Pe un Ubuntu întreg e deja pus și atunci `apt` doar trece pe lângă
el; pe unul pornit dintr-o imagine minimală poate lipsi, iar fără el raportul
de bani nu pleacă niciodată. Paginile aplicației n-au de unde ști asta. O spune
scriptul de instalare, la pasul „Ceasul plăților”: pe roșu când lipsește până
și folderul `/etc/cron.d`, pe galben când folderul e, dar programul `cron`
lipsește ori stă oprit. Pagina de verificare de la pasul 8 vede numai dacă
fișierul ceasului e scris, nu și dacă `cron` rulează; asta se vede în jurnal,
cum scrie la pasul 6b.

Cele trei module Apache nu sunt de formă:

| Modul | Fără el |
|---|---|
| `rewrite` | adresele fără `.html` nu mai merg, iar API-ul nu-și mai găsește rutele: 404 pe aproape tot |
| `headers` | cad antetele de securitate, `noindex`-ul panourilor și al fișelor tehnice (PDF) și oprirea memorării paginilor în browser |
| `expires` | pozele, `.css`-ul și `.js`-ul pleacă fără termen de păstrare și se cer din nou la fiecare deschidere |

`expires` e singurul care lipsește des: pe Debian și Ubuntu nu e aprins din
oficiu, iar `.htaccess`-ul își ține tot capitolul de memorare sub
`<IfModule mod_expires.c>`. „IfModule" e o condiție, nu un titlu — fără modul,
Apache sare peste blocul întreg **în tăcere**, fără nicio eroare și fără niciun
rând în jurnal. De-aia `actualizeaza-vps.sh` se uită după el la fiecare rulare
și te avertizează pe galben dacă nu-l găsește; ce înseamnă avertismentul e
scris în [09-actualizare-vps.md](09-actualizare-vps.md).

Verifici oricând ce e aprins cu `apache2ctl -M`.

Parola de root la MySQL, o singură dată:

```bash
mysql_secure_installation
```

---

## 3. Baza de date

```bash
sudo mysql
```

```sql
CREATE DATABASE gp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gp'@'localhost' IDENTIFIED BY 'o-parolă-lungă-generată';
GRANT ALL PRIVILEGES ON gp.* TO 'gp'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Importul îl faci după ce ai urcat fișierele (pasul 5). Pe Linux fișierele sunt
UTF-8, deci importul din linia de comandă e sigur — avertismentul despre
diacritice din ghidul de cPanel privea clientul MySQL de pe Windows.

---

## 4. Structura de foldere și gazdele virtuale

```bash
mkdir -p /var/www/gp/{public_html,clienti,lab,api}
```

Câte un fișier pentru fiecare adresă:

```bash
cat > /etc/apache2/sites-available/gp-root.conf <<'CONF'
<VirtualHost *:80>
  ServerName goldenpastry.ro
  ServerAlias www.goldenpastry.ro
  DocumentRoot /var/www/gp/public_html
  <Directory /var/www/gp/public_html>
    AllowOverride All
    Require all granted
  </Directory>
</VirtualHost>
CONF
```

La fel pentru celelalte trei, schimbând numele și folderul:

| Fișier | ServerName | DocumentRoot |
|---|---|---|
| `gp-clienti.conf` | `clienti.goldenpastry.ro` | `/var/www/gp/clienti` |
| `gp-lab.conf` | `lab.goldenpastry.ro` | `/var/www/gp/lab` |
| `gp-api.conf` | `api.goldenpastry.ro` | `/var/www/gp/api` |

`AllowOverride All` este obligatoriu în toate patru: fără el `.htaccess` e
ignorat, iar API-ul răspunde 404 la orice rută.

```bash
a2ensite gp-root gp-clienti gp-lab gp-api
a2dissite 000-default
systemctl reload apache2
```

---

## 5. Pachetul și urcarea lui

Pe calculatorul tău, în folderul proiectului:

```bash
node .claude/build-deploy.mjs
```

Se construiește `deploy/` cu cele patru foldere plus `sql/`. Din PowerShell:

```powershell
scp -r deploy root@IP_VPS:/root/
```

`scp -r` duce și fișierele ascunse (`.htaccess`, `.image-slots.state.json`) —
sunt esențiale. Alternativ, cu interfață: [WinSCP](https://winscp.net/).

De la runda 2.6.0, în `deploy/public_html/` stă și folderul **`fise/`**: cele
21 de fișe tehnice ale produselor, în PDF, câte una pe produs, toate ale
laturii congelate (`fisa-tehnica-rolldog-congelat.pdf` și celelalte). Pleacă
numai la magazin, cu `public_html/`; `lab/` și `clienti/` n-au copie, iar
`.htaccess`-ul lor trimite orice cerere `fise/…` la `goldenpastry.ro` cu un
302, la fel ca pe cele pentru poze (`.claude/build-deploy.mjs:582-590`).
`.htaccess`-ul magazinului le dă PDF-urilor tipul `application/pdf`, le cere
browserului să întrebe de fiecare dată dacă fișa s-a schimbat (`no-cache`) și
le ține în afara Google (`X-Robots-Tag: noindex`) — blocul „fișele tehnice
(PDF)” din `HT_SEO` (`:492`). Pe `api/` nu ajunge nimic din toate astea.

Pe server:

```bash
cp -a /root/deploy/public_html/. /var/www/gp/public_html/
cp -a /root/deploy/clienti/.     /var/www/gp/clienti/
cp -a /root/deploy/lab/.         /var/www/gp/lab/
cp -a /root/deploy/api/.         /var/www/gp/api/

chown -R www-data:www-data /var/www/gp
find /var/www/gp -type d -exec chmod 755 {} \;
find /var/www/gp -type f -exec chmod 644 {} \;

mysql --default-character-set=utf8mb4 -u root -p gp < /root/deploy/sql/001_schema.sql
mysql --default-character-set=utf8mb4 -u root -p gp < /root/deploy/sql/002_seed.sql
```

Migrările `003`–`006` n-au ce face la o instalare nouă: schema le conține
deja. `007` **nu** — măsurat în `001_schema.sql`: `referral_code`,
`referred_by` și `referred_at` nu apar nicăieri în el, iar `users.role` e tot
`ENUM('admin','angajat','client')`, fără `manager` și `agent_vanzari`. Așa că
cele care contează sunt **de la `007` în sus** — schema nu le are. Bucla de mai
jos le rulează pe toate, de la `003` încolo, și e bine așa:
fiecare se uită întâi în `information_schema` și, dacă are deja ce caută, nu
face nimic. Nu le alege pe sprânceană — o listă scrisă cu mâna e exact felul în
care se uită o migrare.

Prima comandă e **registrul migrărilor**, și se dă înaintea buclei, nu la rândul
ei în ea: fișierul se cheamă `025`, deci în ordinea numelui ar rula după
`003`–`024`, iar cele dinaintea lui ar trece pe lângă un tabel care încă nu
există și n-ar avea unde-și scrie numele. E idempotent, deci bucla îl poate lua
încă o dată la rând, fără pagubă.

```bash
mysql --default-character-set=utf8mb4 -u root -p gp < /root/deploy/sql/025_registru_migrari.sql
```

```bash
for f in /root/deploy/sql/[0-9][0-9][0-9]_*.sql; do
  case "$(basename "$f")" in 001_*|002_*) continue;; esac
  mysql --default-character-set=utf8mb4 -u root -p gp < "$f"
done
```

După buclă, registrul îți spune singur ce a intrat — nu mai trebuie numărate
tabele ca să ghicești:

```bash
sudo mysql gp -e "SELECT fisier, aplicata_la FROM schema_migrations ORDER BY fisier"
```

Verifici: **29 de tabele**, iar `products` cu 22 de rânduri. Dacă ies mai
puține, numele care lipsește îți spune care migrare n-a intrat:
`referral_commissions` e din `008`, `founder_invites` din `009`, `lab_shifts`
din `014`, `lab_messages` din `015`, `request_claims` din `019`,
`telegram_links` și `lab_production` din `021`, `schema_migrations` din
`025`, `role_rights` din `033`, `stock_item_prices` din `034`,
`orders_deleted` din `035`, iar `telegram_oameni` din `036`. Fără `referral_commissions`, pagina „Recomandări" răspunde „Eroare
internă"; fără `lab_shifts` și `lab_messages`, „Pontaj" și tabla de anunțuri
din Panoul Laborator răspund lămurit că lipsește migrarea — vezi
`lab_supported()` și `lab_board_supported()`. Iar dacă lipsește chiar
`schema_migrations`, întrebarea de mai sus n-are cui să răspundă: atunci numeri
tabelele, e singura cale rămasă. (`instaleaza-vps.sh` face bucla asta singur.)

`017_procent_recomandare.sql` nu se vede nici în tabele, nici în coloane: ea
scrie o setare, atât. O verifici în **Setări → General**, la „Procent de
recomandare", unde pe o instalare nouă trebuie să scrie **5**. Dacă scrie `0`,
recomandările merg — se leagă, se numără — dar nu aduc niciun ban, și nimic din
aplicație nu te anunță despre asta. Iar dacă acolo nu e nicio cifră, ci o casetă
închisă cu `017_procent_recomandare.sql` scris sub ea, rândul lipsește cu totul
din `settings`: până intră migrarea, aplicația socotește cu 5%, cifra scrisă în
cod, iar din panou nu poți pune alta.

`018_adresa_si_program.sql` e la fel ca `017`: nu se vede nici în tabele, nici
în coloane, fiindcă scrie două setări — adresa publică și programul. O verifici
tot în **Setări → General**, la „Adresa publică" și „Program de lucru": pe o
instalare nouă trebuie să scrie „Șos. Olteniței 103", respectiv „Lu-Vi 07:00-21:00, Sâ 07:00-17:00".
Semnul că migrarea n-a intrat nu e caseta goală — goală și deschisă înseamnă că
ai golit-o tu, și atunci site-ul arată adresa și programul scrise în cod —, ci
caseta **închisă**, în care nu se poate scrie, cu `018_adresa_si_program.sql`
scris sub ea:
setările se scriu peste rânduri care există, deci fără rând n-au unde se scrie.
O cerere care le poartă totuși (un script, un import) e refuzată întreagă, cu
toate cheile lipsă numite (`unknown_setting`), și nu scrie nimic din ea.

`016_zona_cont.sql` nu se vede în numărul de tabele: ea aduce o **coloană**
(`users.zone`) și încă o zonă în lista de livrare. O verifici în aplicație, nu
în `SHOW TABLES` — în Setări, pe fila „Liste”, „Zone de livrare” trebuie să
aibă „În afara Bucureștiului și Ilfovului" la coada listei, **fără
coordonate**, iar formularul de cont nou din
„Conturi" trebuie să aibă caseta „Zona". Fără ea, zona de pe cont răspunde 503
cu un mesaj care spune limpede ce lipsește, iar restul aplicației merge mai
departe.

`019_jeton_cerere.sql` **se vede** în numărul de tabele: ea aduce
`request_claims`, al douăzeci și doilea. Acolo stau amprentele jetoanelor cu
care se leagă cererea trimisă de pe site de contul făcut imediat după ea. Fără
tabel, butonul „Trimite cererea și fă-ți cont” trimite cererea la fel — nu se
pierde nicio comandă —, dar contul nou n-o vede în „Comanda mea” până n-o
leagă cineva cu mâna, din formularul comenzii.

`020_rang_client.sql` nu se vede nici în tabele, nici în coloane, nici în
setări: ea pune un **index** pe `orders`, pentru insigna care spune dacă omul e
la prima comandă ori a mai comandat. E prima migrare din toate care **se
poate uita fără pagubă**: fără ea insignele ies la fel, doar socoteala lor
merge mai încet. Dacă vrei totuși s-o vezi:
`SHOW INDEX FROM orders WHERE Key_name = 'ix_orders_client_rang'`.

`021_raspundere.sql` **se vede** în numărul de tabele: ea aduce `telegram_links`
și `lab_production`, al douăzeci și treilea și al douăzeci și patrulea. Mai
aduce o coloană (`users.telegram_user_id`) și un index (`ix_orders_autor`) —
singura migrare care le are pe toate trei felurile deodată, de-aceea numărul de
tabele singur n-o dovedește întreagă.

Ce face fiecare bucată, și ce se pierde fără ea. `telegram_links` ține cererile
de legare a contului cu Telegramul, cu amprenta jetonului, ca `password_resets`
și `request_claims`; fără tabel, cutia „Contul meu de Telegram” din Setări →
Notificări nu se desenează deloc, deci nimeni nu-și poate lega contul. Coloana
`users.telegram_user_id` spune **al cui cont** e cel care a apăsat butonul din
grup: fără ea, serverul nu recunoaște pe nimeni. De la 2.7.5 asta nu mai
înseamnă un nume greșit, ci unul neverificat: orice apăsare se scrie pe
drepturile contului care a legat botul, dar cu numele de pe Telegram al celui
care a apăsat și cu vorba „cont nelegat” (v. `036`, mai jos). Până la 2.7.5
ieșea, tăcut, pe numele celui care legase botul, oricine ar fi apăsat.
`lab_production` ține ce s-a făcut în laborator, cu ceasul pe fiecare rând;
fără el, **raportul de producție pe tură iese gol, nu cu eroare** — adică se
descoperă abia la prima plată. Indexul `ix_orders_autor` e singurul care se
poate uita fără pagubă, ca `020`; îl vezi cu
`SHOW INDEX FROM orders WHERE Key_name = 'ix_orders_autor'`.

`022_cerere_de_la_personal.sql` **nu se vede deloc** în numărul de tabele, ca
`017`: ea adaugă patru coloane pe `order_requests` — `fulfillment`, `urgent`,
`timed`, `created_by` — plus indexul `ix_req_autor` și cheia străină
`fk_req_autor`. Deci numărul rămâne **24**, iar dovada ei se cere anume:
`SHOW COLUMNS FROM order_requests LIKE 'created_by'`.

E migrarea fără de care hotărârea din 18 septembrie 2026 nu se poate ține:
comanda intră de-a dreptul în sistem numai când o scrie un administrator, un
manager sau fondatorul; agentul de vânzări și restul personalului scriu o
**cerere**, care se încuviințează. Cererea mergea pe drumul site-ului, iar
drumul acela purta mai puțin decât o comandă: fără `fulfillment`, modul de
onorare ales de cel care a vorbit cu clientul se pierde, și hotărăște
`default_fulfillment()` în locul lui; fără `urgent` și `timed`, graba nu ajunge
la cel care apasă „Acceptă"; fără `created_by`, comanda născută din cererea
agentului poartă numele administratorului care a acceptat-o, iar agentul
dispare din propria lui muncă. Tot `created_by` spune și **de unde** a venit
cererea — una scrisă de personal are autor, cea a vizitatorului nu —, deci fără
ea comenzile personalului ies însemnate „site" pe nedrept. Niciuna din cele
patru nu dă eroare când lipsește: se pierd tăcut, ca `lab_production`.

`023_laboratorul_si_livrarea.sql` **nu se vede nici ea** în numărul de tabele,
ca `022`: două coloane și nimic altceva — `orders.lab_seq` (ordinea de lucru a
laboratorului) și `users.poate_confirma_livrarea` (dreptul de a spune că marfa
a ajuns la client). Deci numărul rămâne **24** și după ea, iar dovada se cere
anume: `SHOW COLUMNS FROM users LIKE 'poate_confirma_livrarea'`.

Fără `lab_seq`, ordinea comenzilor din „Panou Laborator" rămâne unde era până
acum: în `localStorage`, adică în browserul fiecărei tablete. Nu dă eroare —
fiecare vede altă zi, iar împărțirea cantităților produse, care merge de sus în
jos pe lista aceea, umple alte comenzi după tableta de pe care s-a apăsat.
Fără `poate_confirma_livrarea` e pe dos, și mai tăcut: paza **cade deschisă**,
dinadins (o poartă care se închide singură într-o fereastră de actualizare e
mai rea decât paguba de care fuge), deci serverul primește „Predată" de la
oricine din personal, exact ca înainte. Bifa se pune pe urmă din „Conturi", pe
contul șoferului; **nimeni nu se naște cu ea**, nici conturile de operații care
există azi — contul de laborator e o tejghea comună, și tocmai el n-are voie
s-o aibă. Administratorul și fondatorul o au din cod, deci biroul nu se poate
încuia pe dinafară.

`024_doua_cataloage.sql` **nu se vede nici ea** în numărul de tabele: trei
coloane pe `products` și nimic altceva — `in_frozen` și `in_baked` (în care
catalog e produsul) și `photo_baked` (poza laturii coapte). Deci
numărul rămâne **24** și după ea, iar dovada se cere anume:
`SHOW COLUMNS FROM products LIKE 'in_frozen'`.

Fără cele două bife, magazinul se întoarce la regula de dinainte — „se vede
numai cine are **amândouă** prețurile" —, și asta nu e o stricăciune, ci chiar
purtarea pe care codul nou o ține dinadins: fișierele ajung pe server înaintea
importului SQL, iar în fereastra dintre ele nimic nu are voie să se golească.
Ce nu merge până la import: cele două bife din panou n-au unde se scrie, iar
o poză urcată anume pentru coapte ar cădea peste cea congelată. Bifele
se nasc **aprinse** (`DEFAULT 1`), nu stinse: un produs născut de codul vechi
între cele două momente trebuie să apară în amândouă cataloagele, nu în
niciunul — nevăzut nicăieri, fără nicio eroare, e tocmai felul de pagubă pe
care n-o observi.

`026_cheie_cerere.sql` **nu se vede nici ea** în numărul de tabele: o coloană
pe `order_requests`, `idempotency_key`, și indexul unic de peste ea,
`uq_req_cheie`. Deci numărul rămâne **25** și după ea, iar dovada se cere
anume — și se cere pe **index**, nu pe coloană:
`SHOW INDEX FROM order_requests WHERE Key_name = 'uq_req_cheie'`.
O bază care are coloana dar n-are indexul primește chei și nu bagă de seamă
niciodată că a primit aceeași cheie de două ori: arată reparată și nu e. Tot de
index întreabă și codul, din aceeași pricină (`request_key_supported()`).

Cheia e ce recunoaște cererea trimisă **a doua oară**: degetul apăsat încă o
dată, sau legătura căzută tocmai când răspunsul venea încoace. Pe o instalare
nouă nu se vede nimic din asta — e o pază, nu o pagubă reparată. Fără ea,
purtarea e cea dinainte: a doua trimitere face a doua cerere, iar laboratorul
primește două comenzi acolo unde omul a făcut una. Indexul e unic, dar trece
peste cererile de până acum oricâte ar fi: în SQL `NULL` nu se ciocnește cu
`NULL`, iar ele toate au cheia goală. Ce **nu** face: nu desface cererile duble
adunate înainte — n-avem de unde ști dacă omul a apăsat de două ori sau chiar a
vrut două comenzi.

`027_poza_fiecarei_laturi.sql` **nu se vede nici ea**, și nici n-ar avea cum:
e singura migrare care nu atinge schema deloc — nici tabel, nici coloană, nici
index. Numărul rămâne tot **25**. Ea mută date: copiază poza congelată în
coloana laturii coapte acolo unde aceea e goală. Dovada se cere din `settings`:

```sql
SELECT value FROM settings WHERE setting_key = 'migration_027_poza_pe_latura';
```

Rândul acela e și zăvorul ei: a doua rulare îl găsește și nu mai copiază nimic.
Pe o instalare nouă migrarea nu are ce copia — n-are încă niciun produs —, deci
rândul apare și atât, ceea ce e tocmai răspunsul bun.

Ce repară: până în 2.4.1, o latură fără poză a ei o arăta pe a celeilalte, și
de-aia o poză schimbată la congelate se vedea și la coapte. Împrumutul
s-a tăiat, iar migrarea asta e cea care face ca tăierea să nu golească nicio
vitrină: în ziua schimbării, amândouă laturile arată exact ce arătau.

`028_preturi_pe_trepte.sql` **nu se vede nici ea** în numărul de tabele: zece
coloane și patru setări. Pe `products` stau pragurile (`prag_b`, `prag_c`) și
prețurile B și C ale fiecărei laturi (`price_frozen_b`, `price_frozen_c`,
`price_baked_b`, `price_baked_c`); pe `order_items` și pe `order_request_items`,
`treapta` și `prag_treapta` — treapta la care s-a vândut fiecare poziție și
pragul care a câștigat-o. Numărul rămâne tot **25**, iar dovada se cere pe
ultima coloană pe care o adaugă:

```sql
SHOW COLUMNS FROM order_request_items LIKE 'prag_treapta';
```

Codul le cere pe **toate zece** deodată (`catalog_trepte_supported()`). Fără
ele, magazinul arată un singur preț pe latură, ca înainte, iar fișa produsului
refuză prețurile B și C cu un mesaj care numește migrarea — restul fișei se
salvează. Pe o instalare nouă fiecare produs pornește cu un singur preț: scara
se scrie din fila Produse, produs cu produs, iar pragurile se precompletează
din Setări → General, cardul „Catalogul" (100 și 300 la pornire, cum le-a pus
migrarea).

`029_numere_in_catalog.sql` **nu se vede nici ea**, și din aceeași pricină ca
`027`: nu atinge schema deloc. Numărul rămâne tot **25**. Ea dă fiecărui produs
numărul din colțul pozei — 1, 2, 3…, în ordinea în care au fost puse — în
coloana `products.sort`, care exista din `001`, și își scrie la coadă semnul
din `settings`. Dovada se cere de acolo:

```sql
SELECT value FROM settings WHERE setting_key = 'migration_029_numere_catalog';
```

Rândul poartă data rulării și e și zăvorul ei: a doua rulare îl găsește și nu
mai renumerotează nimic, deci numerele alese de patron în fila Produse rămân
ale lui la fiecare actualizare. Pe o instalare nouă numerotează chiar la primul
import cele 22 de produse semănate de `002`, de la 01 la 22; un produs adăugat
după aceea ia numărul de după cel mai mare, până la 999. Fără semn, magazinul
arată numărul vechi (cheia bazei), iar fișa produsului refuză schimbarea
numărului cu un mesaj care numește migrarea.

`030_gata_de_livrare.sql` **nu se vede nici ea**, și din aceeași pricină ca
`029`: nu atinge schema deloc. Numărul rămâne tot **25**. Ea pune etapa „Gata
de livrare" în lista de etape, chiar înaintea celei finale, și își scrie la
coadă semnul din `settings`. Dovada se cere de acolo:

```sql
SELECT value FROM settings WHERE setting_key = 'migration_030_gata_de_livrare';
```

Rândul poartă data rulării și e și zăvorul ei: a doua rulare îl găsește și nu
mai atinge lista, deci o etapă pe care o redenumești ori o scoți din Setări →
Liste rămâne cum ai lăsat-o, la fiecare actualizare. Pe o instalare nouă
migrarea n-are ce pune — lista semănată de `002` are deja etapa —, deci rândul
apare și atât. Ce înseamnă etapa: laboratorul duce comanda până la „Gata de
livrare" și se oprește acolo; de-acolo comanda e a curierului. Locul ei în
listă e cel care contează, nu numele: laboratorul se oprește la etapa
dinaintea celei finale, oricum s-ar chema ea.

`031_fise_tehnice.sql` **nu se vede nici ea** în numărul de tabele: două
coloane pe `products` — `sheet_frozen` și `sheet_baked` — și semnul ei din
`settings`. Numărul rămâne tot **25**, iar dovada se cere pe a doua coloană:

```sql
SHOW COLUMNS FROM products LIKE 'sheet_baked';
```

În coloane stă calea fișei tehnice a fiecărei laturi. Migrarea leagă o dată,
după `slug`, fișele venite cu pachetul de latura congelată a produselor lor;
pe o instalare nouă, produsele semănate de `002` și le primesc de la primul
import. Latura coaptă împrumută fișa celei congelate cât n-are una a ei, iar
împrumutul se face în cod, nu în bază — deci `sheet_baked` rămâne gol, și așa
trebuie. Legarea e zăvorâtă cu semnul `migration_031_fise_tehnice`: o rulare de
după nu mai pune la loc o fișă pe care ai scos-o din Catalog. Fără
coloane, magazinul nu arată butonul fișei la niciun produs, iar încărcarea
unei fișe e refuzată cu un mesaj care numește migrarea.

`032_plati_si_prag.sql` **nu se vede nici ea** în numărul de tabele: opt
coloane, două indexuri și opt rânduri în `settings`. Pe `orders` stau cele
șapte coloane ale plății — de la `plata` la `plata_amintire_la` —, iar pe
`users`, `prag_neplatite`. Numărul rămâne tot **25**, iar dovada se cere din
`settings`, pe semnul pe care migrarea și-l scrie la coadă:

```sql
SELECT value FROM settings WHERE setting_key = 'migration_032_plati_si_prag';
```

Rândul poartă data rulării și e zăvorul celor doi pași ai ei care scriu date.
Întâi, comenzile aflate la etapa finală, cu ziua trecută, sunt trecute o dată
drept plătite; pe o instalare nouă n-are ce judeca — nu e încă nicio comandă.
Apoi, vederea cu care se deschide magazinul pe telefon și tabletă
(`catalog_vedere_telefon`, semănată de `028` cu `unu`) e trecută pe `patru`:
de aceea, pe o instalare nouă, magazinul pornește pe „Patru pe ecran” și pe
telefon, și pe calculator. Ce aduce: starea plății fiecărei comenzi — neplătită,
amânată, plătită ori plătită prin bancă; comanda nouă se naște neplătită — și
pragul de comenzi neplătite: cel general, `plati_prag_implicit`, semănat cu
**2**, și cel de pe cont, `prag_neplatite`, gol din pornire, adică „pragul
general”. Tot ea seamănă chatul și orele raportului de bani
(`plati_telegram_chat_id`, gol, și `plati_raport_ore`, „09:00,18:00”) și adresa
panoului (`panel_base_url`); pe toate le alegi din „Încasări” ori, de la
2.7.5, din Setări → Notificări, la pasul 8d.
Cele două indexuri, `ix_orders_plata` și `ix_orders_plata_la`, sunt de felul
lui `020`: doar grăbesc. Fără migrare, casa vinde ca înaintea ei — niciun prag
nu oprește vreo comandă —, iar „Încasări” și tot ce arată ori schimbă plata
răspund lămurit că plățile nu sunt pornite, cu numele fișierului în frază.
Ceasul de la pasul 6b nu se supără nici el: scrie în jurnalul lui că baza nu
are încă plățile și nu trimite nimic.

`033_acces.sql` **se vede** în numărul de tabele: ea aduce `role_rights`, al
douăzeci și șaselea. Acolo stau bifele pe care fondatorul le mută în „Acces” —
cine intră pe ce panou și cine face ce faptă —, și numai cele schimbate față de
pornire. Tabelul se naște gol, iar gol înseamnă că fiecare rol intră unde îl
lasă codul din pornire: pe o instalare nouă n-ai nimic de făcut. Fără tabel,
rolurile intră tot așa, dar o bifă mutată n-are unde se scrie: pagina „Acces”
se deschide numai de citit, sub caseta „Bifele nu se pot muta încă.”, care
numește fișierul migrării.

`034_costuri.sql` **se vede** și ea: aduce `stock_item_prices`, al douăzeci și
șaptelea — istoricul prețurilor de cumpărare ale articolelor din depozit, cu
ziua de la care e bun fiecare. Se naște gol: migrarea nu scrie niciun preț.
Fără tabel, prețurile n-au unde se scrie, deci costul unui produs n-are din ce
se socoti: fila „Prețuri” din Depozit arată, în locul listei, fraza serverului
cu numele fișierului. Articolele, rețetele și mișcările din depozit nu cer
tabelul și merg fără el.

`035_comenzi_sterse.sql` **se vede** și ea: aduce `orders_deleted`, al douăzeci
și optulea. E o arhivă — poza unei comenzi anulate care a fost apoi ștearsă, cu
cine a șters-o și când —, fără nicio cheie străină, dinadins: o cheie spre
client ar fi șters poza odată cu clientul ori i-ar fi golit câmpul, iar arhiva
trebuie să rămână întreagă tocmai după ce clientul ori contul acela nu mai
există. Se naște goală. Fără tabel, anularea unei comenzi merge, dar „Șterge
definitiv” e refuzat cu numele fișierului în frază, fiindcă poza n-ar avea unde
rămâne.

`036_telegram_oameni.sql` **se vede** și ea: aduce `telegram_oameni`, al
douăzeci și nouălea, și două coloane pe `order_requests` (`handled_tg_id` și
`handled_tg_name`). Tabelul ține minte cine a apăsat un buton sub un anunț din
grupul de pe Telegram — numele de acolo și câte apăsări —, ca fondatorul să-l
poată lega de contul lui din cutia „Oameni din grup” (Setări → Notificări);
coloanele țin, pe cererea hotărâtă, numele celui care a apăsat fără să aibă
contul legat. Se naște gol și nu scrie nimic în `settings`. Fără ea, două
lucruri se opresc, amândouă cu numele fișierului în frază: apăsarea unui om cu
contul nelegat e **refuzată** — pe telefon citește că serverul n-are încă unde
să-i scrie numele, și nu se scrie nimic —, iar cutia „Oameni din grup” arată,
în locul listei, „Lista „Oameni din grup” n-are încă tabelul ei: rulează întâi
sql/036_telegram_oameni.sql.” Cine are contul legat apasă mai departe ca până
acum. După ea, numărul e cel de sus: **29**.

`037_posta_smtp.sql` **nu se vede** în numărul de tabele: pune cinci rânduri în
`settings`, cele ale căsuței de e-mail a firmei — `mail_smtp_host`,
`mail_smtp_port` (din pornire `465`), `mail_smtp_user`, `mail_smtp_password`
(ținută criptată) și `mail_smtp_security` (din pornire `auto`). Le scrie cu
`INSERT IGNORE`, deci a doua rulare nu schimbă nimic din ce ai completat între
timp. Pe o instalare nouă căsuța se naște goală, iar e-mailul pleacă pe drumul
direct, prin programul de poștă de la pasul 6c, până o completezi la pasul 8d.
Fără migrare, casetele căsuței din cartonașul „E-mail” stau închise, cu un rând
care numește fișierul, și rămâne numai drumul direct. Dacă vrei s-o vezi cu
ochii, fără să scoți la vedere parola căsuței:

```sql
SELECT setting_key FROM settings WHERE setting_key LIKE 'mail_smtp_%';
```

Trebuie să iasă cinci rânduri. Interogarea cere numai numele rândurilor, nu și
valorile: una dintre ele e parola.

---

## 5b. Urcarea cu WinSCP

1. **Sesiune nouă**: protocol **SFTP**, host = IP-ul VPS-ului, port 22, user
   `root`, parola din hPanel (sau cheia privată, la Advanced -> SSH ->
   Authentication). La prima conectare compară amprenta cheii cu cea din hPanel.
2. **Arată fișierele ascunse** — `Ctrl+Alt+H`, sau Options -> Preferences ->
   Panels -> *Show hidden files*. Fără asta nu vezi `.htaccess` și
   `.image-slots.state.json`, iar site-ul urcat fără ele dă 404 pe orice rută de
   API și rămâne cu trei poze goale pe pagina publică.
3. **Transfer în mod Binary** — bara de jos, *Transfer settings* -> Binary. În
   modul Text WinSCP schimbă sfârșiturile de linie și strică fișierele binare
   (poze, fonturi) care nimeresc în listă.
4. **Urcă conținutul, nu folderul.** În stânga intri în `deploy\`, în dreapta în
   `/var/www/gp/`:

   | Din stânga (Windows) | În dreapta (VPS) |
   |---|---|
   | conținutul `deploy\public_html\` | `/var/www/gp/public_html/` |
   | conținutul `deploy\clienti\` | `/var/www/gp/clienti/` |
   | conținutul `deploy\lab\` | `/var/www/gp/lab/` |
   | conținutul `deploy\api\` | `/var/www/gp/api/` |

   `deploy\sql\` îl urci în `/root/` — nu are ce căuta în web. Folderele care
   lipsesc le faci cu `F7`.
5. **Drepturile, după urcare** — din terminal (*Commands -> Open in PuTTY*):

   ```bash
   chown -R www-data:www-data /var/www/gp
   find /var/www/gp -type d -exec chmod 755 {} \;
   find /var/www/gp -type f -exec chmod 644 {} \;
   chmod 640 /var/www/gp/api/config.php
   ```

6. **`config.php` se face comod tot din WinSCP**: `F5` pe `config.example.php`
   ca să-l copiezi cu alt nume, apoi `F4` ca să-l editezi pe loc.
7. **La actualizări**: *Commands -> Synchronize*, direcția **Remote**, modul
   *Mirror files*, cu masca de excludere:

   ```
   | config.php; products/
   ```

   Așa nu se suprascriu datele de conectare la bază și nu se șterg pozele urcate
   din panoul Catalog. Bifează *Preview changes* înainte de OK — lista îți arată
   exact ce se scrie peste ce.

   `fise/` **nu** intră în mască, dinadins: e folderul fișelor tehnice venite
   cu pachetul și se înlocuiește la fiecare urcare. Fișele pe care le încarci
   tu din Catalog stau în `products/`, lângă poze, deci le apără aceeași mască.

---

## 6. `api/config.php`

Nu vine în pachet, dinadins. Îl faci pe server:

```bash
cp /var/www/gp/api/config.example.php /var/www/gp/api/config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # cheia de criptare
openssl rand -base64 18                             # codul de instalare
nano /var/www/gp/api/config.php
```

Ce schimbi față de exemplu:

```php
'db' => [
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'gp',
    'user' => 'gp',
    'pass' => 'parola de la pasul 3',
],

'encryption_key' => 'cele 64 de caractere hexazecimale generate mai sus',
'install_code'   => 'codul generat mai sus',

'cookie_domain' => '.goldenpastry.ro',   // punctul din față e obligatoriu
'cookie_secure' => true,
'env'           => 'prod',

// API-ul stă în alt folder decât site-ul: fără linia asta, pozele urcate din
// Catalog s-ar salva în /var/www/gp/products, de unde site-ul nu le vede.
'photo_dir' => '/var/www/gp/public_html/products',
```

`allowed_origins` conține deja cele patru adrese de producție; scoate liniile
`http://*.gp.test` de dezvoltare.

De la 2.7.0, exemplul are și o cheie pe care **o lași cum e**:
`'telegram_api_base' => ''`. Ea spune la ce adresă pleacă mesajele de Telegram,
iar goală ori lipsă înseamnă Telegramul adevărat, `https://api.telegram.org` —
singura valoare bună pe server. Cheia există numai pentru probele de pe
calculatorul de lucru, care o îndreaptă spre un primitor local, ca rapoartele
de încasări trimise de ele să poată fi numărate fără să ajungă pe telefonul
cuiva. Stă în fișier, și nu în Setări, dinadins: la adresa aceea călătorește,
cu fiecare mesaj, tokenul botului. Ce stă în Setări se schimbă dintr-un ecran,
de către oricine are dreptul lui, pe când `config.php` îl scrie numai cine are
deja serverul în mână. Dacă rămâne din greșeală o adresă în ea, pagina de
verificare de la pasul 8 o arată, la rândul „Adresa Telegramului”, iar ceasul
de la pasul 6b o scrie la coada rândului din jurnalul lui, de fiecare dată când
trimite ceva.

De la 2.7.5 mai e una de același fel, pe care tot **o lași cum e**:
`'smtp_ca_file' => ''`. E un certificat de probă pentru căsuța de e-mail, luat
în seamă numai când căsuța e chiar pe calculatorul de lucru. Pe server rămâne
goală; dacă are ceva în ea, pagina de verificare de la pasul 8 o arată, la
rândul „Certificatul de probă al căsuței de e-mail”. Datele căsuței adevărate —
serverul, portul, utilizatorul, parola — **nu** se scriu în `config.php`: se
completează din panou, la pasul 8d.

Apoi:

```bash
chown www-data:www-data /var/www/gp/api/config.php
chmod 640 /var/www/gp/api/config.php
mkdir -p /var/www/gp/public_html/products
chown -R www-data:www-data /var/www/gp/public_html/products
chmod 755 /var/www/gp/public_html/products
```

---

## 6b. Ceasul plăților

De la 2.7.0, aplicația are un lucru care **rulează singur, fără să fie cineva
pe site**: raportul de încasări de pe Telegram și amintirile pentru plățile
amânate. Până la ea, tot ce făcea serverul pornea dintr-o apăsare — a unui
client, a unui om din laborator, a ta. Un raport „la 09:00” n-are cine să-l
apese, așa că îl pornește ceasul sistemului, `cron`: din cinci în cinci minute
rulează un fișier din pachet, `api/cron/plati.php`, care se uită la ceasul
României și se întreabă dacă a venit vreuna din orele alese în „Încasări” ori,
de la 2.7.5, în Setări → Notificări (sunt aceleași ore, văzute din două
locuri).

`instaleaza-vps.sh` pune ceasul singur, la pasul „Ceasul plăților”, după ce a
scris `config.php` și a așezat drepturile. Cu mâna, e un singur fișier,
`/etc/cron.d/goldenpastry-plati`, cu exact aceste trei rânduri:

```
# Golden Pastry: raportul de încasări și amintirile de scadență, pe Telegram.
# Scris de scripturile de instalare și de actualizare; nu se editează de mână.
*/5 * * * * www-data flock -n /run/lock/gp-plati.lock php /var/www/gp/api/cron/plati.php 2>&1 | logger -t gp-plati
```

```bash
chown root:root /etc/cron.d/goldenpastry-plati
chmod 644 /etc/cron.d/goldenpastry-plati
systemctl enable --now cron
```

Fiecare bucată din rândul al treilea are rostul ei. `*/5 * * * *` înseamnă
„din cinci în cinci minute”. `www-data` e contul sub care rulează, același cu
al serverului web — singurul, în afară de `root`, care are voie să citească
`config.php`. `flock -n` ține un lacăt cât durează o rulare: dacă Telegram nu
răspunde și una ține mai mult de cinci minute, următoarea nu pornește peste ea.
`logger -t gp-plati` trimite în jurnalul sistemului tot ce tipărește ceasul;
fără el, `cron` ar încerca să trimită ieșirea pe e-mail, într-o cutie de pe
server pe care n-o citește nimeni. Calea e cea a folderului de instalare: dacă
ai instalat cu `--dir` în
altă parte, scriptul scrie în rând folderul acela.

Scriptul scrie fișierul **numai dacă textul de pe server diferă** de cel de mai
sus. La rularea în care îl pune, spune
`/etc/cron.d/goldenpastry-plati — scris (din 5 în 5 minute; se citește cu: journalctl -t gp-plati)`;
la toate cele de după, `/etc/cron.d/goldenpastry-plati — neschimbat`.
`actualizeaza-vps.sh` scrie același text, literă cu literă, tocmai ca niciunul
dintre cele două scripturi să nu-l rescrie pe al celuilalt. De aceea fișierul
nu se editează de mână: orice ai schimba în el, rularea următoare a oricăruia
dintre scripturi îl întoarce la textul de mai sus.

Sunt trei pricini din care scriptul **nu** pune ceasul, și le spune pe roșu,
fără să oprească instalarea; toate trei se încheie cu „Ceasul NU a fost pus”.
Întâi, un folder de instalare cu spații ori cu alte semne pe care `cron` le
citește altfel decât terminalul — scriptul primește în cale numai litere, cifre
și semnele `_`, `.`, `-` și `/`. Atunci rândul rămâne de scris de mână, iar până
atunci, spune scriptul, „rapoartele de încasări nu vor pleca”; scris întocmai
ca mai sus s-ar strica însă la fel, deci calea se pune între ghilimele drepte,
iar fiecare `%` din ea se scrie `\%`. Mai simplu e să instalezi de la început
într-un folder fără asemenea semne, cum e cel din pornire, `/var/www/gp`. Apoi,
un pachet fără `api/cron/plati.php`, adică unul mai vechi de 2.7.0. Și un
server fără folderul `/etc/cron.d`, caz în care îți dă și comanda care îl
aduce, `apt-get install -y cron`, după care rulezi scriptul încă o dată.

Mai e o capcană, și scriptul o caută anume: **fișierul nu e ceasul.** Folderul
`/etc/cron.d` există și pe un server fără `cron` — îl aduc alte pachete —, deci
rândul se poate scrie fără să-l citească nimeni, niciodată. De aceea, după
scriere, scriptul întreabă de program și de serviciu. Dacă programul lipsește,
scrie pe galben „Rândul e scris, dar serverul nu are programul cron: ceasul NU
merge.” și comanda care îl pune. Dacă programul e, îl pornește și îl lasă
pornit la fiecare repornire a mașinii, apoi spune „serviciul cron merge”; dacă
tot nu merge, „Rândul e scris, dar serviciul cron e oprit: ceasul NU merge.”,
cu `systemctl status cron` ca să vezi de ce.

**Ce înseamnă pentru tine.** Din pornire, raportul pleacă la 09:00 și la 18:00,
ora României, dar numai după ce ai ales chatul în care să vină — pasul 8d. Până
atunci ceasul bate în gol și nu e nimic stricat: la fiecare cinci minute scrie
în jurnal de ce tace și nu trimite nimic.

Dacă serverul e oprit la ora raportului, raportul nu se pierde pe loc. La prima
bătaie de după repornire, ceasul vede că ora a trecut și trimite raportul
atunci — dar numai dacă au trecut mai puțin de **două ore** de la ea. De la
două ore în sus îl sare, iar următorul vine la ora următoare: un raport „de dimineață”
sosit după-amiaza ar spune o poveste veche cu aer de proaspătă. Tot așa sunt
prinse și orele de dinaintea miezului nopții: una pusă la 23:30 e trimisă și la
00:10, dacă atunci s-a trezit serverul.

Același raport nu pleacă niciodată de două ori. Înainte să trimită, ceasul își
însemnează în bază că ora aceea e luată, și abia apoi trimite; rularea de peste
cinci minute găsește însemnarea și trece mai departe. Prețul alegerii e spus pe
față în jurnal: dacă trimiterea pică tocmai atunci — Telegram nu răspunde —,
raportul acela **nu se mai încearcă**. Rămâne scris ca neplecat, cu pricina
lui, în „Încasări”, la „Ultimele trimiteri”, și îl poți cere pe loc cu
„Trimite acum un raport de probă”. Ordinea pe dos ar fi fost mai rea: un raport
trimis și neînsemnat ar fi plecat din nou la fiecare cinci minute, două ore la
rând, cu lista datornicilor de fiecare dată.

**Cum vezi că merge.** În trei locuri, de la cel mai apropiat de ceas la cel
mai apropiat de tine. Întâi jurnalul sistemului, pe server:

```bash
journalctl -t gp-plati -n 20
```

Trebuie să vezi câte un rând la fiecare cinci minute:
`plati 2026-10-12 09:00 · raportul de la 09:00 a plecat` când a trimis,
`… · nimic de trimis` între ore, `… · oprit: Raportul de bani nu are chat. …`
cât n-ai ales chatul. În fața fiecăruia, `journalctl` pune ceasul serverului,
care poate fi altul decât al României; data și ora României sunt cele de după
cuvântul `plati`. Niciun rând nou de mai mult de cinci minute înseamnă că
`cron` nu rulează: `systemctl status cron`.

Apoi o rulare de probă, care **nu trimite și nu însemnează nimic** — numai
tipărește ce ar pleca dacă ar fi ora:

```bash
sudo -u www-data php /var/www/gp/api/cron/plati.php --uscat --acum="2026-10-12 09:02"
```

La `--acum` scrii ziua în care ești și o oră de raport, în ceasul României;
fără el, ceasul judecă pentru clipa de față și, între ore, răspunde doar „nimic
de trimis”. Tot „nimic de trimis” răspunde și dacă raportul orei aceleia a
plecat deja în ziua scrisă: fiecare oră se trimite o singură dată pe zi, iar
proba ține seama de asta. Scrie atunci ziua de mâine: textul tipărit e socotit
pentru ziua scrisă, nu pentru cea în care ești. Cât n-ai ales chatul, și
rularea de probă spune tot „oprit: Raportul de bani nu are chat. …”, deci proba
cu textul raportului se face după pasul 8d. Rulează-l ca `www-data`, nu ca
`root`: așa afli și dacă acel cont poate citi `config.php`, adică exact ce i se
cere ceasului adevărat.

Al treilea loc e pagina de verificare de la pasul 8, la rândul „Ceasul plăților
(raportul de încasări pe Telegram)”, iar după ce ai intrat în panou, lista
„Ultimele trimiteri” din „Încasări”.

**Cum îl oprești.** Dacă nu vrei rapoarte, nu umbla la server: în „Încasări”,
la cardul „Raport pe Telegram” — ori, de la 2.7.5, în Setări → Notificări, în
cutia „Raport de bani pe Telegram”, care ține aceleași setări și aceeași listă
„Ultimele trimiteri” —, apasă „Oprește” la „Ora 1” și la „Ora 2”, stinge
„Amintiri la Amânate” și apasă „Salvează”, cel al cardului. Ceasul bate mai departe, dar
scrie „oprit: nicio oră de raport aleasă, iar amintirile sunt oprite” și nu
trimite nimic; îl pornești la loc scriind orele înapoi. Dacă vrei să taci
ceasul însuși — muți aplicația, ții serverul în reparație —, ștergi fișierul:

```bash
rm -f /etc/cron.d/goldenpastry-plati
```

Nu se pierde nimic: orele și chatul stau în bază, nu în fișier. Îl pune la loc
următoarea rulare a lui `actualizeaza-vps.sh` ori a lui `instaleaza-vps.sh`, fie
că vrei, fie că nu — deci, dacă vrei să rămână oprit și după o actualizare,
oprește orele din „Încasări”, nu fișierul.

Prin browser, ceasul nu se poate porni. Fișierul lui stă în folderul publicat,
deci are o adresă, dar cerut pe ea răspunde 404 și nu face nimic: altfel oricine
i-ar fi aflat adresa ar fi putut porni un raport.

---

## 6c. Programul de poștă

De la 2.7.5, e-mailul aplicației — anunțurile de comenzi și linkurile de la
„Ți-ai uitat parola?” — are două drumuri. **Drumul de nădejde e căsuța firmei**:
o căsuță de e-mail obișnuită, pe domeniul tău, prin care aplicația trimite cum
ar trimite un program de e-mail; o completezi din panou, la pasul 8d. **Drumul
direct** e cel de pe server: PHP predă mesajul unui program de poștă al
mașinii, care îl duce mai departe. Un Ubuntu curat nu are un asemenea program.
Așa a stat serverul de azi de la mutarea de pe găzduirea veche și până la
2.7.5: nu pleca niciun e-mail, iar panoul spunea mai departe că merge.

`instaleaza-vps.sh` pune programul singur, la pasul „Programul de poștă”, după
„Ceasul plăților” și înaintea gazdelor virtuale. Ce pune și cum îl așază:

- **postfix, numai de trimis.** Nu e un server de e-mail: nu ține căsuțe și nu
  primește nimic din afară. Aplicația îi predă un mesaj, iar el îl duce la
  serverul celui care trebuie să-l primească.
- **ascultă numai pe 127.0.0.1**, adică numai pe mașina însăși. Din afară nu se
  poate lega nimeni de el, deci nu poate fi folosit de altcineva ca să trimită
  e-mail în numele tău. Nu deschizi niciun port în paravanul de foc pentru el.
- **trimite numai pe IPv4**, adresa pe care o acoperă regula domeniului pentru
  expeditori (SPF).
- **domeniul tău e scos din `mydestination`.** Din pornire, postfix se socotește
  gazda domeniului pe care e instalat: un mesaj către o adresă a domeniului
  (`comenzi@goldenpastry.ro`) l-ar ține pe server, convins că acolo e căsuța, și
  nu l-ar mai trimite nicăieri. Căsuțele tale adevărate stau însă la furnizorul
  domeniului. Cu domeniul scos din listă, și mesajele către propriile tale
  adrese pleacă spre el — iar tocmai acolo ajung anunțurile de comenzi.
- **trimite în numele domeniului** dat la `--domain`, fără `www.` în față.

Cu mâna, pe un server pe care n-ai rulat scriptul, sunt aceleași lucruri, în
aceeași ordine. Primul rând răspunde dinainte la cele două întrebări ale
instalării — fără el, `apt` s-ar opri la un ecran pe care, prin `ssh`, nu-l
vezi:

```bash
printf 'postfix postfix/main_mailer_type select Internet Site\npostfix postfix/mailname string %s\n' goldenpastry.ro | debconf-set-selections
DEBIAN_FRONTEND=noninteractive apt-get install -y postfix
postconf -e 'inet_interfaces = loopback-only' 'inet_protocols = ipv4' 'mydestination = localhost.localdomain, localhost'
systemctl enable postfix
systemctl restart postfix
```

Pasul **nu oprește niciodată instalarea**: orice ar păți, restul lucrării merge
mai departe, iar scriptul spune pe galben ce n-a ieșit. Rândul bun, la capăt,
e `postfix — pus: ascultă numai pe 127.0.0.1, trimite ca @goldenpastry.ro`, cu
domeniul tău. Sunt patru cazuri în care nu pune nimic:

| Ce scrie scriptul | Ce înseamnă |
|---|---|
| `Sărit (--fara-posta). E-mailul pleacă numai prin căsuța (SMTP) din Setări → Notificări → E-mail.` | ai cerut tu să nu-l pună |
| `serverul are deja un program de poștă (…) — nu-l ating` | mașina are deja unul; scriptul nu-l schimbă și nu-i umblă la setări |
| `Nu am putut citi domeniul site-ului, deci NU am pus programul de poștă.` | fără domeniu n-are în numele cui să trimită |
| `Serverul nu are apt: programul de poștă NU a fost pus.` | nu e un Ubuntu ori un Debian; îl pui tu, de mână |

Iar dacă instalarea lui pică — `Programul de poștă NU s-a putut instala (apt a
refuzat ori n-are legătură).` —, scriptul îți dă cele două rânduri de dat mai
târziu (instalarea, apoi așezarea) și spune că restul lucrării e întreg. Toate rândurile
galbene, cu ce ai de făcut la fiecare, sunt în `docs/09-actualizare-vps.md`, la
„Programul de poștă”: actualizarea rulează același pas, cu aceleași vorbe.

**`--fara-posta`** sare pasul cu totul. Îl alegi dacă nu vrei pe mașină niciun
program de poștă. Nu pierzi drumul de nădejde: cu căsuța completată la pasul
8d, e-mailul pleacă prin ea și n-are nevoie de program. Pierzi numai plasa:
cât căsuța e goală, nu pleacă nimic, iar cartonașul „E-mail” o scrie pe roșu.

**Portul 25, și ce se știe despre el.** Un program de poștă trimite spre lume
pe portul 25, iar multe găzduiri îl țin închis. După pagina de ajutor a
găzduirii („Is SMTP port 25 blocked on VPS?”), Hostinger **nu** închide portul
25 pe VPS-urile lui, dar ține e-mailul trimis la cel mult cinci mesaje pe
minut. E ce spune găzduirea, nu ceva măsurat de noi pe serverul tău. De aceea
scriptul încearcă el, la capătul pasului: o singură legătură, de cel mult opt
secunde, spre serverul care primește poșta domeniului tău, fără să trimită
niciun mesaj. Răspunde într-unul din trei feluri: `portul 25 spre lume e
deschis (am ajuns la …)`; pe galben, `Portul 25 pare închis de găzduire (n-am
ajuns la …).`, cu lămurirea că mesajele rămân atunci în coadă și nu pleacă,
fără nicio eroare în panou, și că leacul nu e pe server, ci căsuța din Setări;
ori `portul 25 nu l-am putut încerca (n-am aflat cine primește poșta lui …)`.

**Ce nu făgăduiește drumul direct**, nici cu portul deschis:

- un mesaj trimis de pe server pleacă fără semnătura domeniului (DKIM), iar
  regula domeniului pentru expeditori (SPF) e „~all”: e de așteptat să ajungă în
  **Spam**, mai ales la căsuțele mari;
- **„predat” nu înseamnă „ajuns”.** Aplicația află numai că programul de poștă
  a primit mesajul. Dacă a și plecat, și unde a ajuns, se vede pe server:
  `mailq` arată ce a rămas în coadă, iar `tail -30 /var/log/mail.log` (ori, unde
  fișierul lipsește, `journalctl -u 'postfix*' -n 30 --no-pager`) spune de ce;
- cinci mesaje pe minut ajung pentru anunțurile unei patiserii, dar sunt un
  plafon al găzduirii, nu al aplicației.

De aceea scriptul își încheie pasul, ori de câte ori ajunge până la capătul
lui, cu aceleași două rânduri: `Calea de nădejde rămâne căsuța (SMTP): ce
pleacă direct de pe server ajunge des în Spam.` și `Proba adevărată: butonul
„Trimite e-mail de test” din Setări → Notificări → E-mail.` Nu le vezi când ai
sărit pasul cu `--fara-posta`, nici când pasul s-a oprit mai devreme fiindcă
n-a putut pune programul: domeniul necitit, mașina fără apt, apt care a
refuzat. Atunci ultimul lucru scris e rândul galben, cu ce ai de făcut.

**Ce nu s-a încercat.** Pasul a fost probat numai pe un server închipuit — un
dosar de lucru în care instalarea pachetului și repornirea serviciului sunt
jucate, nu făcute. Acolo s-a văzut că prima rulare instalează, că a doua nu mai
face nimic, că `--fara-posta` e ținut, că un `apt` care refuză nu oprește
scriptul și că portul închis dă rândul galben. Pe o mașină adevărată, și pe
VPS-ul de azi, scriptul cu el încă nu rulase la data scrierii: ce scrie acolo
afli la prima rulare — rândurile de mai sus îți spun cum să le citești.

**Cum vezi că merge.** Pagina de verificare de la pasul 8 are două rânduri
pentru e-mail: „Drumul e-mailului” — „direct de pe server (căsuța din Setări e
goală)” pe o instalare nouă — și „Programul de poștă al serverului”. Proba
adevărată rămâne butonul din panou, de la pasul 8d.

**Două lucruri despre PHP-ul serverului, de știut și de lăsat cum sunt.**
Întâi, o margine: serverul pus de ghidul ăsta rulează PHP înăuntrul lui Apache
(`libapache2-mod-php`, pasul 2). La „Ți-ai uitat parola?” răspunsul vine pe loc
și e același pentru oricine, dar legătura se închide abia după ce e-mailul a
plecat ori a căzut — până la vreo 25 de secunde, cât poate ține vorba cu căsuța.
Un om cu un browser nu vede asta; un program scris anume ar putea deosebi, după
clipa închiderii, un cont adevărat de unul închipuit. Ar închide-o numai un
server cu PHP-FPM ori trimiterea mutată pe ceas; e scrisă ca fișă deschisă în
`docs/06-defecte-de-reparat.md`. Apoi, o setare: `zend.exception_ignore_args`
din `php.ini` vine pornită pe un server de producție și așa trebuie să rămână.
Oprită, urma unei erori ar putea purta, printre altele, începutul parolei
căsuței. Aplicația scrie în jurnal numai mesajul erorii, deci azi nu ajunge
nicăieri — setarea e plasa de dedesubt.

---

## 7. HTTPS

```bash
apt install -y certbot python3-certbot-apache
certbot --apache \
  -d goldenpastry.ro -d www.goldenpastry.ro \
  -d clienti.goldenpastry.ro -d lab.goldenpastry.ro -d api.goldenpastry.ro
```

Fără certificat, cookie-ul de sesiune (`cookie_secure => true`) nu circulă și
nimeni nu se poate autentifica. Reînnoirea e automată (`certbot.timer`).

---

## 8. Verificarea

Deschide **`https://api.goldenpastry.ro/verifica.php`**.

Trebuie să iasă totul verde. Dacă nu:

| Ce scrie | Ce faci |
|---|---|
| `pdo_mysql` sau `openssl` lipsesc | `apt install -y php8.3-mysql php8.3-curl`, apoi `systemctl reload apache2` |
| Fără drept de scriere în `products/` | `chown -R www-data:www-data /var/www/gp/public_html/products` și `chmod 755` pe el |
| Conectarea la baza de date a eșuat | numele bazei, utilizatorul și parola din `api/config.php` — pasul 6 |
| Lipsesc tabele | reia importul din pasul 3; dacă baza e mai veche, rulează migrarea care lipsește din `sql/` |
| Cheia de criptare | în `config.php` nu sunt exact 64 de caractere hexazecimale |
| Conexiune HTTPS: nu | `certbot` n-a terminat (pasul 7), sau `.htaccess` lipsește din folder |
| Ceasul plăților (raportul de încasări pe Telegram): „nepus: lipsește /etc/cron.d/goldenpastry-plati” | fișierul de la pasul 6b n-a fost scris: scrie-l, ori rulează din nou `instaleaza-vps.sh` |
| Ceasul plăților: „nepus: serverul nu are cron (/etc/cron.d)” | `apt-get install -y cron`, apoi pasul 6b |
| Adresa Telegramului: „mutată din config.php spre …” | în `api/config.php`, golește `telegram_api_base` (pasul 6): cu ea pusă, niciun mesaj — nici de comandă, nici raportul de bani — nu ajunge la Telegramul adevărat |

Rândul ceasului e verde și când scrie „pus, din 5 în 5 minute · chatul banilor
nu e ales, deci nu pleacă nimic”: la instalare așa și trebuie, fiindcă chatul îl
alegi abia la pasul 8d. Rândul „Adresa Telegramului” nu apare deloc pe un
server pus cum trebuie — se arată numai când cheia are ceva în ea.

**Șterge `verifica.php` după ce totul e verde:**

```bash
rm /var/www/gp/api/verifica.php
```

Dacă uiți, nu rămâne deschisă pentru oricine: din clipa în care există un cont
de administrator (pasul următor), pagina răspunde 404, ca și cum ar fi fost
ștearsă. Până atunci e deschisă, așa că nu lăsa instalarea neterminată peste
noapte.

---

## 8b. Primul cont de administrator

Baza de date pornește **fără niciun cont de om**. Singurul cont existent este
`panou`, contul de sistem al tabletelor din laborator, în care nu se intră cu
parolă. Până creezi un administrator, nimeni nu poate intra nicăieri.

Deschide **`https://api.goldenpastry.ro/setup.php`** și completează formularul.
Primul câmp este **codul de instalare** — cel pe care l-ai pus la pasul 6 în
`config.php`, la `install_code`. Fără el pagina nu creează nimic, oricâte
încercări ar veni de pe internet. Restul: alegi singur utilizatorul și parola.
Parola trebuie să aibă minimum 10 caractere; e contul cu drepturi depline peste
comenzi, conturi și setări, deci nu o refolosi de altundeva.

De la 2.7.0, „drepturi depline” nu mai înseamnă chiar tot. Contul făcut aici e
un **administrator**, iar tot ce ține de bani — paginile „Încasări” și
„Statistici”, prețurile din Depozit, costul din rețete — e din pornire numai al
**fondatorului**. Pagina „Acces”, cea cu bifele pe roluri, e a lui și a nimănui
altcuiva, orice s-ar bifa: din ea se dau toate celelalte. Cum ajunge contul
ăsta fondator e scris la pasul 8d; până atunci intrările acelea nu-ți apar în
meniu, și nu e o stricăciune.

Dacă pagina îți spune că nu e pus niciun cod de instalare, deschide
`/var/www/gp/api/config.php`, completează `install_code` cu un cod de cel puțin
12 caractere, salvează și reîncarcă.

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
> din *Setări → Tableta*.
>
> Codul nu mai e o valoare fixă, aceeași la orice instalare: una fixă ar fi
> însemnat că oricine a citit proiectul poate deschide panourile tale.

**Șterge `setup.php` imediat după:**

```bash
rm /var/www/gp/api/setup.php
```

---

## 8c. Proba de foc

În ordine:

1. `https://goldenpastry.ro` — se vede catalogul, se poate trimite o cerere.
2. `https://lab.goldenpastry.ro` — pagina de intrare.
3. Intră cu contul de administrator → **Comenzi** → cererea trimisă la punctul 1
   trebuie să fie acolo.
4. Acceptă cererea → devine comandă.
5. `https://clienti.goldenpastry.ro` — intră cu un cont de client, vezi comanda.
6. Fișele tehnice. Pe pagina unui produs din magazin, „Descarcă fișa tehnică”
   descarcă PDF-ul. Butonul stă numai la produsele care au fișă: dacă nu-l vezi,
   alege alt produs — „Trigon cu vanilie și fructe”, de pildă, n-are una în
   pachet. Apoi, de pe server, antetele:

   ```bash
   curl -sI https://goldenpastry.ro/fise/fisa-tehnica-rolldog-congelat.pdf
   curl -sI https://lab.goldenpastry.ro/fise/fisa-tehnica-rolldog-congelat.pdf
   ```

   Prima trebuie să arate `200`, `Content-Type: application/pdf` și
   `X-Robots-Tag: noindex`; a doua, `302` și `Location:
   https://goldenpastry.ro/fise/…` (dacă numele antetelor ies cu litere mici,
   e tot ce trebuie). Un `200` fără `X-Robots-Tag` la prima
   înseamnă `headers` stins (pasul 2); un `404` la a doua, un `.htaccess`
   lipsă din `/var/www/gp/lab/`.

Dacă pasul 3 merge, partea grea e gata: datele chiar circulă între dispozitive.

---

## 8d. Primele setări

**`lab.goldenpastry.ro/setari`**, cu contul de administrator. Filele, în ordinea
în care stau pe ecran:

- **General** — numele firmei, telefonul, adresa de contact. La „E-mail public”
  pune o adresă pe care o deschizi chiar tu: e adresa de contact de pe site,
  dar tot la ea pleacă, puțin mai jos, linkul care îți face contul fondator.
- **Notificări** — întâi cartonașul „E-mail”: adresa pe care vrei să primești
  comenzile noi și căsuța firmei, apoi **„Trimite e-mail de test”** (pe larg,
  mai jos, la „Căsuța firmei”). Pentru WhatsApp, pașii sunt în
  [02-panoul-de-setari.md](02-panoul-de-setari.md). Tot aici stă botul de
  Telegram al comenzilor; raportul de bani de mai jos pleacă prin același bot,
  deci fără tokenul lui nu pleacă nici raportul.
- **Liste** — etapele, intervalele și zonele, dacă ale tale diferă.
- **Tableta** — aici schimbi codul tabletelor. Cel de la instalare merge deja;
  pune altul când pleacă cineva din echipă, sau dacă l-ai pierdut. Codul nu se
  poate afișa — se păstrează criptat — deci câmpul arată doar dacă există unul.
  Cât timp nu e configurat niciunul, tabletele nu pot deschide panoul: serverul
  răspunde „Codul panoului nu este configurat", adică poarta stă închisă, nu
  deschisă. Până la 2.7.0 fila asta se numea „Acces”; numele l-a luat pagina
  fondatorului, cea cu bifele pe roluri, iar aici a rămas numai codul.

**Căsuța firmei.** De la 2.7.5, cartonașul „E-mail” din Notificări scrie, chiar
sub titlu, pe ce drum pleacă e-mailul acum. Pe o instalare nouă, cu programul
de poștă pus la pasul 6c, rândul e galben: „Trimite direct de pe server. Fără
o căsuță în spate, mesajele pot ajunge în Spam ori deloc. Completează căsuța de
mai jos ca să fie sigur.” Fă ce spune, înainte de orice altceva de pe pagina
asta — pe e-mail se sprijină și linkul de fondator de mai jos, și „Ți-ai uitat
parola?” al tuturor:

1. La furnizorul domeniului, alegi ori faci o căsuță de e-mail a firmei — una
   ca `comenzi@goldenpastry.ro`.
2. În partea „Căsuța prin care se trimite (SMTP)” completezi „Server SMTP”,
   „Port” și „Utilizator”, iar „Criptare” o lași pe „Automat (după port)” dacă
   furnizorul nu spune altceva. **Numele serverului și portul le citești din
   contul tău de la furnizor**, la setările căsuței: ghidul nu le scrie, fiindcă
   le hotărăște furnizorul și le poate schimba.
3. La „Parola căsuței” scrii parola **tu, cu mâna ta**. N-o spui nimănui — nici
   celui care îți îngrijește serverul — și n-o scrii în `config.php` ori în alt
   fișier: stă numai aici, criptată, iar după salvare n-o mai arată nimeni,
   nici pagina.
4. La „Adresa expeditor”, mai sus în cartonaș, scrii chiar adresa căsuței.
5. „Salvează”, din bara de sus. Rândul de stare se face verde: „Trimite prin
   căsuța …”, cu adresa, serverul și portul.
6. **„Trimite e-mail de test”**. Lângă buton trebuie să scrie că a luat căsuța
   mesajul în primire. Apoi îl cauți în Inbox, iar dacă nu e acolo, în Spam:
   testul dovedește că mesajul a plecat, nu că a ajuns.

Ce înseamnă fiecare rând de stare, ce spune testul când pică și cum te întorci
la drumul direct scrie în [02-panoul-de-setari.md](02-panoul-de-setari.md), la
„E-mailul: căsuța firmei și drumul direct”.

**Telegram: leagă-ți întâi contul tău.** După ce ai scris tokenul și ai apăsat
„Leagă botul”, în cutia „Contul meu de Telegram” apeși „Leagă-mi contul”, apeși
de pe telefon butonul trimis de bot în grup, te întorci, reîncarci pagina
(cutia își citește starea numai la deschidere) și apeși „Da, eu am apăsat”. De
la 2.7.5, o apăsare din grup poartă numele celui care a apăsat: cât
contul tău nu e legat, și ce apeși tu apare cu numele tău de pe Telegram și cu
„cont nelegat”. Pe colegi îi leagă fondatorul, din cutia „Oameni din grup”,
după ce fiecare a apăsat o dată un buton în grup — deci după ce ți-ai făcut
contul fondator, mai jos. Pe larg, tot în ghidul Setărilor, la „Telegram: cine
apare când apeși un buton din grup”.

**Fă-ți contul fondator, cât ești singurul administrator.** Contul de la pasul
8b e un administrator, iar de la 2.7.0 tot ce ține de bani e, din pornire,
numai al fondatorului, ca și pagina „Acces”. Cât nu există un fondator, intrările
acelea nu apar în meniul nimănui, iar pragul de comenzi neplătite, de care e
vorba mai jos, nu-l poate muta nimeni.

Dreptul de fondator nu se ia, se cere. În „Parola mea”, la cardul „Contul de
fondator”, apeși „Cere drept de fondator”: pleacă un e-mail la adresa de la
„E-mail public”, cu un link bun o singură dată, din pornire vreme de 60 de
minute. Îl deschizi, apeși „Confirm — fă-l fondator”, și contul e fondator. De
aici vine ordinea de mai sus — întâi adresa din General și proba de e-mail din
Notificări, apoi cererea. Fără adresă, pagina spune „Nu e trecută nicio adresă
de e-mail a firmei în Setări → General. Linkul de confirmare n-are unde să
plece.”; dacă e-mailul nu poate pleca, spune „Cererea s-a înregistrat, dar
e-mailul n-a putut fi trimis. Verifică setările de e-mail, apoi cere din nou.”,
și ceri din nou după ce proba de e-mail trece.

Fă asta înainte să faci alt administrator. Cât ești singurul, serverul te lasă
să ceri dreptul chiar dacă adresa firmei ai scris-o tu; din clipa în care mai
există un administrator, cere ca adresa s-o fi scris altcineva decât cel care
cere, fiindcă altfel linkul s-ar întoarce în mâna lui. Un drept de fondator dat
se ia înapoi din „Conturi”, de către alt fondator, schimbând rolul contului;
ultimul fondator nu poate fi scos.

**`lab.goldenpastry.ro/incasari`**, cu contul devenit fondator. Sunt trei
lucruri de hotărât aici înainte să dai conturi clienților, toate noi în 2.7.0.

**Pragul.** La „Pragul pentru toți clienții” stă câte comenzi neplătite poate
avea un client până când comenzile noi i se opresc. Instalarea îl pune pe
**2**, și e bine să știi ce numără: o comandă e „neplătită” din clipa în care e
scrisă, nu abia după livrare, iar o cerere cu produse, încă neacceptată, se
numără și ea. Cu pragul 2, a doua intră, iar la a treia clientul citește
„Numărul maxim de comenzi a fost atins. Până plătești soldul comenzilor
precedente nu putem primi o comandă nouă. Contactează-ne pentru mai multe
detalii.” Se numără numai comenzile legate de un cont de client; cele scrise pe
un nume fără cont nu opresc pe nimeni. Dacă ai clienți care comandă în fiecare
zi și plătesc mai rar, 2 e prea puțin: scrie cifra ta — **0 înseamnă fără
limită** — și apasă „Salvează pragul”. Se desface la fel, scriind cifra
dinainte; schimbarea nu atinge nicio comandă deja scrisă, numai pe următoarea.
Din pornire, peste prag poate trece numai fondatorul, cu o confirmare, iar
trecerea se scrie în Jurnal.

**Raportul pe Telegram.** La cardul „Raport pe Telegram” scrii la „Chat ID”
numărul grupului în care vrei cifrele — **alt grup decât cel al comenzilor**;
pe același, salvarea e refuzată cu „Raportul de bani are chatul lui, altul
decât cel al comenzilor.” Botul e același, deci trebuie adăugat și în grupul
ăsta. Numărul unui grup nu e numele lui: are numai cifre și, la grupuri, un
minus în față, ca în pilda dată de server, `-1001234567890`, și se scrie cu
minus cu tot; orice altceva e refuzat la salvare, cu o frază care începe cu
„Chatul raportului de bani nu arată a număr de chat Telegram”. „Ora 1” și
„Ora 2” vin pe 09:00 și 18:00, ceasul României; „Oprește”, de lângă fiecare, o
golește, iar la o oră goală nu pleacă niciun raport. „Amintiri la Amânate” vine
aprins: lasă să plece, după raport, și mesajul despre amânările ajunse la
termen. Dacă oprești amândouă orele și lași amintirile aprinse, ele pleacă
singure, la 09:00. Apeși „Salvează”, apoi „Trimite acum un raport de probă”.
Dacă pagina spune „Raportul de probă a plecat. Uită-te în grupul de pe
Telegram.” și mesajul e acolo, lanțul e întreg — token, chat, bot în grup; dacă
n-a plecat, pricina e scrisă lângă buton. Proba pleacă numai după salvare:
apăsată înainte, pagina îți cere întâi să salvezi. La ore, raportul îl trimite
ceasul de la pasul 6b; cât „Chat ID” e gol, ceasul rulează și nu trimite nimic.
Orice câmp de aici se desface scriind valoarea dinainte și salvând din nou, iar
un „Chat ID” golit oprește raportul cu totul. De la 2.7.5, aceleași setări sunt
și în Setări → Notificări, în cutia „Raport de bani pe Telegram”, ultima din
cartonașul „Telegram”: le completezi din oricare loc și se văd în amândouă.
Acolo se salvează cu „Salvează” al cutiei, nu cu cel din bara de sus a paginii.

Un lucru care poate surprinde în ziua aceea: dacă salvezi chatul la mai puțin
de două ore după una din cele două ore, raportul orei aceleia vine la prima
bătaie a ceasului, în cel mult cinci minute. Nu e proba plecată încă o dată, e
raportul adevărat, întârziat — aceeași regulă a celor două ore de la pasul 6b.

**Adresa panoului.** Tot în card, „Adresa panoului” e adresa pe care se
sprijină butonul de sub raport — „Deschide Încasări”, iar sub amintiri „Vezi
amânatele” —, care deschide pagina gata filtrată. Instalarea scrie
`https://lab.goldenpastry.ro`, oricare ar fi domeniul dat scriptului: dacă al
tău e altul, schimb-o, altfel butonul duce la un panou care nu e al tău. Se
scrie `https://`, gazda și atât — fără cale, fără „?” și fără „/” la capăt. O
adresă care nu începe cu `https://` ori nu se deschide de oriunde (`localhost`,
o adresă din rețeaua casei, un nume fără punct) e refuzată la salvare, cu
pricina scrisă: Telegram nu primește un asemenea buton și, odată cu el,
respinge **tot mesajul**. De aceea serverul mai are o plasă: dacă o asemenea
adresă ajunge totuși în bază, raportul pleacă fără buton, nu rămâne netrimis.
Câmpul lăsat gol înseamnă același lucru — raport fără buton.

**`lab.goldenpastry.ro/conturi`** — creează conturile echipei. Fiecare client
care trebuie să-și vadă comenzile are nevoie de un cont cu rolul „client":
serverul îi arată exclusiv comenzile lui, filtrarea nu se face în browser.

Nu există conturi implicite în afara celui creat de tine la pasul 8b — nici
demo, nici de test. Cele din prototip (`admin`/`goldenpastry`,
`sofia`/`patiserie`) au fost șterse: parolele lor erau scrise în sursa
paginilor, vizibile oricui.

**Depozitul pornește de la zero.** Toate articolele apar sub prag până faci un
inventar real: `lab.goldenpastry.ro/depozit` → **+ Mișcare** → **Intrare** →
„Corecție inventar", cu cantitățile de pe raft.

Tot de la zero pornesc, de la 2.7.0, și prețurile de cumpărare. Fila „Prețuri”
din Depozit — din pornire numai a fondatorului — are toate articolele fără
preț, iar până la primul preț scris costul unui produs n-are din ce se socoti.
Pe rândul articolului apeși „Pune preț”, scrii prețul unei unități și ziua de
la care e valabil, și salvezi; un preț greșit se șterge din istoricul
articolului, tot de acolo. Costul apare apoi în Catalog, în formularul
produsului, sub „Rețetă — pentru o bucată”, numai pentru produsele care au
rețetă și numai cui are dreptul — din pornire tot fondatorul. Cât unui
ingredient îi lipsește prețul, acolo scrie „Costul nu se poate socoti.” și
numele articolului fără preț.

---

## 9. Actualizări

De la a doua publicare încolo ai un al doilea script, `actualizeaza-vps.sh`, și
un ghid numai pentru asta: `09-actualizare-vps.md`. Pe scurt, pe calculatorul
tău `node .claude/build-deploy.mjs`, apoi:

```powershell
scp -r deploy sql actualizeaza-vps.sh root@IP_VPS:/root/
```

și pe server:

```bash
sudo bash /root/actualizeaza-vps.sh
```

Face singur copia de siguranță a bazei, aplică migrările care lipsesc, copiază
cele patru foldere, se uită dacă ceasul plăților de la pasul 6b e la locul lui
— îl scrie dacă lipsește ori s-a schimbat — și repornește Apache. De la 2.7.5,
la capăt pune și programul de poștă de la pasul 6c, dacă serverul n-are unul;
cu `--fara-posta` îl sare. Ce **nu** se
atinge niciodată:
`api/config.php` (nu e în pachet) și folderul pozelor urcate din panoul Catalog
— în care stau și fișele tehnice încărcate de acolo. `fise/`, în schimb, e al
pachetului: se scrie peste la fiecare actualizare, iar o fișă pusă cu mâna în el
dispare la urcarea următoare.

`instaleaza-vps.sh` se poate rula și el a doua oară, dar nu aplică migrările —
pe o bază care are deja tabele nu importă nimic dinadins
(`instaleaza-vps.sh:235`). Prima oară instalarea, de atunci încolo actualizarea.

---

## Ce merge prost cel mai des

**„Nu mă pot autentifica" pe un singur subdomeniu.**
`cookie_domain` din `config.php` nu începe cu punct.

**Paginile arată nestilizat, fără fonturi.**
Folderul `_ds` n-a ajuns întreg pe server. Fiecare subdomeniu are nevoie de
propria lui copie — `rsync` cu `-a`, nu fișiere alese pe sprânceană.

**API-ul răspunde cu 404 la orice.**
`.htaccess` lipsește din `/var/www/gp/api/` — e fișier ascuns, deci o urcare
care nu-l cuprinde arată „terminată" și lasă serverul fără rescriere. Verifică
și că `AllowOverride All` e pus pe gazda virtuală (pasul 4).

**„Eroare internă" fără detalii.**
Normal pe `env => 'prod'`. Cauza adevărată e în jurnalul Apache:

```bash
tail -20 /var/log/apache2/gp-api-error.log
```

Cel mai des e o migrare care n-a intrat — vezi numărul de tabele de la pasul 3.

**O comandă nouă nu ajunge pe e-mail.**
Setări → Notificări → cartonașul „E-mail”. Rândul de sub titlu spune pe ce drum
pleacă e-mailul acum, iar „Trimite e-mail de test” scrie lângă el de ce n-a
plecat. Cel mai des: căsuța firmei e goală și mesajele pleacă direct de pe
server, de unde ajung în Spam ori deloc; căsuța e completată pe jumătate, și
atunci nu pleacă nimic; ori „Adresa expeditor” nu e adresa căsuței, iar
furnizorul refuză să trimită în numele alteia. Dacă rândul e roșu și spune că
serverul n-are niciun program de poștă, vezi pasul 6c.

**Cineva a cerut o parolă nouă și n-a primit linkul.**
Îl cauți în Jurnal: fiecare cerere pentru un cont adevărat lasă acolo un rând
care spune dacă e-mailul a fost luat în primire de căsuță, numai predat
programului de poștă, ori n-a plecat, cu pricina. Până îl lămurești, îi dai o
parolă provizorie din „Conturi”. Pe larg, în
[05-conturile-de-client.md](05-conturile-de-client.md), la „Dacă și-a uitat
parola”.

**Raportul de încasări nu vine pe Telegram.**
Apasă întâi „Trimite acum un raport de probă”, din „Încasări” ori din Setări →
Notificări. Dacă proba vine
și raportul de la oră nu, lipsa e la ceas, nu la Telegram: pasul 6b, la „Cum
vezi că merge”. Dacă nici proba nu vine, pricina e scrisă lângă buton.

**Un client spune că nu mai poate comanda.**
Dacă mesajul lui începe cu „Numărul maxim de comenzi a fost atins”, nu e o
defecțiune: a ajuns la pragul de comenzi neplătite. În „Încasări”, la „De
încasat”, îi însemni comenzile plătite, cu „Plătită” ori „Bancă”; ori îi dai
pragul lui, de pe cartonașul lui de la „Pe clienți”, cu butonul „Prag: …”.
Pragul pentru toți clienții e la pasul 8d. Însemnarea plăților și pragul unui
singur client sunt scrise pas cu pas în
[09-actualizare-vps.md](09-actualizare-vps.md), la „Ziua actualizării la
2.7.0”, pașii 2 și 6.
