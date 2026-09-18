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
`.domeniu`), importă schema și cere certificatele.

Urci pe VPS `deploy/`, `sql/` și scriptul, unul lângă altul, apoi:

```bash
sudo bash instaleaza-vps.sh --domain goldenpastry.ro --email tu@exemplu.ro
```

Opțiuni: `--dir` (implicit `/var/www/gp`), `--no-ssl`, `--db-name`, `--db-user`,
`--db-root-pass` (dacă root-ul de MySQL are parolă), `--package /cale/spre/deploy`.

La final îți scrie pe ecran parola bazei și codul de instalare, plus cele trei
adrese de urmat: `verifica.php`, `setup.php`, panoul.

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
apt install -y apache2 mysql-server unzip \
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

Cele trei module Apache nu sunt de formă:

| Modul | Fără el |
|---|---|
| `rewrite` | adresele fără `.html` nu mai merg, iar API-ul nu-și mai găsește rutele: 404 pe aproape tot |
| `headers` | cad antetele de securitate, `noindex`-ul panourilor și oprirea memorării paginilor în browser |
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
mysql -u root -p
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
cele care contează sunt **`007`–`020`** — schema nu le are (cea mai nouă e
`020_rang_client.sql`). Bucla de mai jos le rulează pe toate, de la `003` în sus, și e bine așa:
fiecare se uită întâi în `information_schema` și, dacă are deja ce caută, nu
face nimic. Nu le alege pe sprânceană — o listă scrisă cu mâna e exact felul în
care se uită o migrare.

```bash
for f in /root/deploy/sql/[0-9][0-9][0-9]_*.sql; do
  case "$(basename "$f")" in 001_*|002_*) continue;; esac
  mysql --default-character-set=utf8mb4 -u root -p gp < "$f"
done
```

Verifici: **22 de tabele**, iar `products` cu 22 de rânduri. Dacă ies mai
puține, numele care lipsește îți spune care migrare n-a intrat:
`referral_commissions` e din `008`, `lab_shifts` din `014`, `lab_messages`
din `015`, `request_claims` din `019`. Fără primul, pagina „Recomandări" răspunde „Eroare internă"; fără
celelalte două, „Pontaj" și tabla de anunțuri din Panoul Laborator răspund
lămurit că lipsește migrarea — vezi `lab_supported()` și
`lab_board_supported()`. (`instaleaza-vps.sh` face bucla asta singur.)

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
în `SHOW TABLES` — Setări → Zone trebuie să aibă „În afara Bucureștiului și
Ilfovului" la coada listei, **fără coordonate**, iar formularul de cont nou din
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
la prima comandă ori a mai comandat. E singura migrare din toate care **se
poate uita fără pagubă**: fără ea insignele ies la fel, doar socoteala lor
merge mai încet. Dacă vrei totuși s-o vezi:
`SHOW INDEX FROM orders WHERE Key_name = 'ix_orders_client_rang'`.

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

Apoi:

```bash
chown www-data:www-data /var/www/gp/api/config.php
chmod 640 /var/www/gp/api/config.php
mkdir -p /var/www/gp/public_html/products
chown -R www-data:www-data /var/www/gp/public_html/products
chmod 755 /var/www/gp/public_html/products
```

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
> din *Setări → Acces*.
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

Dacă pasul 3 merge, partea grea e gata: datele chiar circulă între dispozitive.

---

## 8d. Primele setări

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

Nu există conturi implicite în afara celui creat de tine la pasul 8b — nici
demo, nici de test. Cele din prototip (`admin`/`goldenpastry`,
`sofia`/`patiserie`) au fost șterse: parolele lor erau scrise în sursa
paginilor, vizibile oricui.

**Depozitul pornește de la zero.** Toate articolele apar sub prag până faci un
inventar real: `lab.goldenpastry.ro/depozit` → **+ Mișcare** → **Intrare** →
„Corecție inventar", cu cantitățile de pe raft.

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
cele patru foldere și repornește Apache. Ce **nu** se atinge niciodată:
`api/config.php` (nu e în pachet) și folderul pozelor urcate din panoul Catalog.

`instaleaza-vps.sh` se poate rula și el a doua oară, dar nu aplică migrările —
pe o bază care are deja tabele nu importă nimic dinadins
(`instaleaza-vps.sh:205`). Prima oară instalarea, de atunci încolo actualizarea.

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
Panoul de Setări → butonul de test spune de ce. Cel mai des, adresa
expeditoare nu e pe domeniul tău și serverul refuză să trimită.
