# Publicarea pe un VPS Hostinger (Ubuntu + Apache)

Varianta pentru VPS a ghidului `03-publicare-cpanel.md`. Aceleași patru adrese,
aceeași bază de date — doar că aici tu ești administratorul mașinii.

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

**Se rulează de câte ori vrei.** A doua oară e o actualizare: nu atinge
`api/config.php`, nu reimportă baza și nu șterge pozele urcate din panoul
Catalog.

Restul ghidului rămâne util pentru ce face scriptul pas cu pas, și obligatoriu
dacă alegi CyberPanel în loc de Ubuntu curat.

---

## 0. Ce alegi la crearea VPS-ului

În hPanel → VPS → **Operating System**:

- **Ubuntu 24.04 curat** — varianta din ghidul ăsta. PHP 8.3 și MySQL 8 vin din
  depozitele oficiale, fără panou care să stea în drum.
- **CyberPanel** (template disponibil) — dacă vrei interfață grafică pentru mai
  multe site-uri: phpMyAdmin, File Manager, SSL cu un buton, foarte aproape de
  cPanel. Pașii din `03-publicare-cpanel.md` se aplică aproape identic; sari
  peste secțiunile 2 și 4 de mai jos.
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
a2enmod rewrite headers
systemctl restart apache2

ufw allow OpenSSH
ufw allow 'Apache Full'
ufw --force enable
```

Verifici versiunea: `php -v` trebuie să arate 8.3 sau mai nou (aplicația cere
minim 8.0). Extensiile obligatorii: `pdo_mysql`, `openssl`, `mbstring`, `curl`,
`json`, `filter`.

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

Migrările `003`–`007` **nu** se importă la o instalare nouă: schema le conține
deja. Verifici: 17 tabele, iar `products` cu 22 de rânduri.

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

## 8. Verificarea și primul cont

1. `https://api.goldenpastry.ro/verifica.php` — trebuie să iasă totul verde.
   Apoi **șterge fișierul**: `rm /var/www/gp/api/verifica.php`.
2. `https://api.goldenpastry.ro/setup.php` — formularul cere codul de instalare
   din `config.php` și creează primul administrator. Apoi
   `rm /var/www/gp/api/setup.php`.
3. `https://lab.goldenpastry.ro` — intri cu contul creat.

---

## 9. Actualizări

După o modificare, pe calculatorul tău `node .claude/build-deploy.mjs`, apoi:

```powershell
scp -r deploy root@IP_VPS:/root/
```

Pe server, aceleași `cp -a`. Ce **nu** se atinge niciodată la o actualizare:
`api/config.php` (nu e în pachet) și `public_html/products/` (pozele urcate din
panou). Restul pașilor și migrările de bază de date: `docs/07-actualizari.md`.

Copie de siguranță înainte de orice actualizare:

```bash
mysqldump -u root -p --default-character-set=utf8mb4 gp > /root/gp-$(date +%F).sql
```
