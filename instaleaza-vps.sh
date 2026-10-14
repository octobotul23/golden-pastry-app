#!/usr/bin/env bash
#
# Golden Pastry — instalare completă pe un VPS Ubuntu/Debian curat.
#
#   sudo bash instaleaza-vps.sh --domain goldenpastry.ro --email tu@exemplu.ro
#
# Ce face, în ordine: instalează Apache + PHP + MySQL, creează baza și
# utilizatorul, copiază pachetul din deploy/ în patru foldere, scrie gazdele
# virtuale, generează api/config.php, importă schema, cere certificatele.
#
# Se poate rula de câte ori vrei: a doua oară e o actualizare — nu atinge
# api/config.php, nu șterge pozele urcate din panou și nu reimportă baza.
#
# Pune și un program de poștă (postfix, numai de trimis), ca e-mailul să poată
# pleca și fără căsuța din Setări; cu --fara-posta îl sare.
#
set -euo pipefail

DOMAIN=""
EMAIL=""
DIR="/var/www/gp"
PACHET=""
DB_NAME="gp"
DB_USER="gp"
DB_ROOT_PASS=""
FARA_SSL=0
FARA_POSTA=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain)   DOMAIN="${2:-}"; shift 2 ;;
    --email)    EMAIL="${2:-}"; shift 2 ;;
    --dir)      DIR="${2:-}"; shift 2 ;;
    --package)  PACHET="${2:-}"; shift 2 ;;
    --db-name)  DB_NAME="${2:-}"; shift 2 ;;
    --db-user)  DB_USER="${2:-}"; shift 2 ;;
    --db-root-pass) DB_ROOT_PASS="${2:-}"; shift 2 ;;
    --no-ssl)   FARA_SSL=1; shift ;;
    --fara-posta) FARA_POSTA=1; shift ;;
    -h|--help)
      sed -n '2,15p' "$0" | sed 's/^# \?//'
      exit 0 ;;
    *) echo "Argument necunoscut: $1"; exit 2 ;;
  esac
done

# Bara de la coadă se taie AICI, ca în actualizeaza-vps.sh, înainte ca vreo cale
# să fie folosită. Calea ajunge, literă cu literă, în rândul de cron de mai jos,
# iar cele două scripturi își compară textul cu fișierul și îl rescriu „numai
# dacă diferă": cu „--dir /var/www/gp/" scris firesc, cu bară, instalarea ar fi
# scris „…/gp//api/cron/plati.php", actualizarea (care taie bara) „…/gp/api/…",
# și fiecare l-ar fi găsit „altfel" pe al celeilalte, la fiecare rulare.
DIR="${DIR%/}"

# ------------------------------------------------------------------ ajutoare --

rosu()   { printf '\033[31m%s\033[0m\n' "$*"; }
verde()  { printf '\033[32m%s\033[0m\n' "$*"; }
galben() { printf '\033[33m%s\033[0m\n' "$*"; }
pas()    { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
info()   { printf '  %s\n' "$*"; }
mor()    { rosu "EROARE: $*"; exit 1; }

# Conectarea la MySQL ca root: pe Ubuntu merge prin socket, fără parolă. Dacă
# ai pus una (mysql_secure_installation), dă-o cu --db-root-pass.
my() {
  if [[ -n "$DB_ROOT_PASS" ]]; then mysql -uroot -p"$DB_ROOT_PASS" "$@";
  else mysql --protocol=socket -uroot "$@"; fi
}

[[ $EUID -eq 0 ]] || mor "rulează cu sudo: sudo bash $0 --domain exemplu.ro"
[[ -n "$DOMAIN" ]] || mor "lipsește --domain (ex: --domain goldenpastry.ro)"
# După tăierea barei, „--dir /" rămâne gol: mai jos vin `chown -R` și `find … chmod`
# pe calea asta, care pe rădăcina discului ar fi schimbat stăpânul întregului server.
[[ "$DIR" == /?* ]] || mor "--dir cere o cale întreagă, alta decât rădăcina discului (ex: --dir /var/www/gp)"
command -v apt-get >/dev/null || mor "scriptul e pentru Ubuntu/Debian (apt)"

# Unde e pachetul: folderul dat, folderul curent sau cel al scriptului.
gaseste_pachet() {
  local c
  for c in "$PACHET" "$PWD" "$(cd "$(dirname "$0")" && pwd)"; do
    [[ -z "$c" ]] && continue
    [[ -d "$c/deploy/api" ]] && { echo "$c/deploy"; return; }
    [[ -d "$c/api" && -d "$c/public_html" ]] && { echo "$c"; return; }
  done
  echo ""
}

# ------------------------------------------------------------------- pachet --

pas "Caut pachetul de publicare"
SURSA="$(gaseste_pachet)"

if [[ -z "$SURSA" ]]; then
  # Sursa nefabricată: dacă avem proiectul întreg, construim pachetul aici.
  RADACINA="${PACHET:-$PWD}"
  if [[ -f "$RADACINA/.claude/build-deploy.mjs" ]]; then
    info "Nu găsesc deploy/, dar găsesc sursa — încerc să-l construiesc aici."

    # Sursa trebuie să fie completă: paginile, ajutoarele și folderele de
    # resurse. Altfel build-deploy.mjs se oprește cu un ENOENT din node, greu
    # de citit. Cel mai des lipsesc fiindcă urcarea prin SFTP a fost oprită la
    # jumătate sau s-a urcat doar o parte din foldere.
    LIPSA=()
    for f in "Site Public.dc.html" "Intrare.dc.html" "support.js" "shared" "_ds" "assets" "seo"; do
      [[ -e "$RADACINA/$f" ]] || LIPSA+=("$f")
    done
    if (( ${#LIPSA[@]} )); then
      rosu "  Sursa e incompletă. Lipsesc din $RADACINA:"
      for f in "${LIPSA[@]}"; do info "    $f"; done
      echo
      info "Două variante, oricare merge:"
      info "  a) construiește pachetul pe Windows — node .claude/build-deploy.mjs —"
      info "     și urcă doar deploy/ și sql/ lângă script (mult mai puține fișiere);"
      info "  b) urcă tot proiectul, cu tot cu paginile .dc.html și folderele de mai sus."
      exit 1
    fi

    command -v node >/dev/null || { apt-get update -qq; apt-get install -y -qq nodejs; }
    ( cd "$RADACINA" && node .claude/build-deploy.mjs ) \
      || mor "build-deploy.mjs nu a reușit. Construiește pachetul pe Windows și urcă deploy/."
    SURSA="$RADACINA/deploy"
  fi
fi
[[ -n "$SURSA" && -d "$SURSA/api" ]] || mor "nu găsesc pachetul. Urcă folderul deploy/ și rulează scriptul lângă el (sau dă --package /cale/catre/deploy)."
info "Pachet: $SURSA"

SQL_DIR=""
for c in "$SURSA/../sql" "$SURSA/sql" "$PWD/sql"; do
  [[ -f "$c/001_schema.sql" ]] && { SQL_DIR="$(cd "$c" && pwd)"; break; }
done
[[ -n "$SQL_DIR" ]] || mor "nu găsesc sql/001_schema.sql (urcă și folderul sql/)"
info "Schema: $SQL_DIR"

ACTUALIZARE=0
[[ -f "$DIR/api/config.php" ]] && ACTUALIZARE=1
(( ACTUALIZARE )) && verde "  Instalare existentă găsită — rulez ca ACTUALIZARE."

# ------------------------------------------------------------------ pachete --

pas "Instalez Apache, PHP și MySQL"
export DEBIAN_FRONTEND=noninteractive
# `cron` e în listă din 2.7.0: raportul de încasări îl pornește serverul, nu o
# pagină (vezi „Ceasul plăților", mai jos). Imaginile obișnuite de Ubuntu îl au
# deja; cele subțiri nu, iar acolo rapoartele n-ar fi plecat niciodată, în tăcere.
apt-get update -qq
apt-get install -y -qq apache2 mysql-server rsync unzip openssl cron \
  php php-mysql php-mbstring php-curl php-xml php-zip php-gd libapache2-mod-php \
  >/dev/null
# Portul 80 liber? Multe imagini de VPS vin cu nginx (sau un panou) pornit, iar
# Apache pică tăcut la pornire cu „Address already in use" — se vede abia în
# systemctl status, iar din afară site-ul pare că nu răspunde deloc.
OCUPANT="$( { ss -lntpH 2>/dev/null | awk '$4 ~ /:80$/ {print $0}' | grep -o 'users:(("[^"]*"' | head -1 | sed 's/.*(("//;s/"//'; } || true )"
if [[ -n "$OCUPANT" && "$OCUPANT" != "apache2" ]]; then
  rosu "  Portul 80 e ocupat de: $OCUPANT"
  info "Aplicația are nevoie de Apache pe 80 (se bazează pe .htaccess)."
  info "Dacă $OCUPANT nu servește altceva de care ai nevoie, oprește-l:"
  info "    systemctl disable --now $OCUPANT"
  info "și rulează din nou scriptul. Dacă ai nevoie de el, mută-l pe alt port"
  info "sau pune-l ca proxy către Apache."
  exit 1
fi

# „expires" a fost adăugat în 2.0.3. Pe Debian și Ubuntu nu e aprins din oficiu,
# iar .htaccess-ul paginilor își ține sub „<IfModule mod_expires.c>" tot capitolul
# de memorare în browser — inclusiv termenele pozelor, ale .css și ale .js. Fără
# modul, Apache sare peste blocul acela în tăcere: nicio eroare, dar fiecare
# deschidere de pagină cere din nou tot ce s-ar fi putut ține. (Paginile în sine
# sunt apărate și fără el, de blocul de mod_headers scris alături.)
a2enmod rewrite headers expires >/dev/null
info "PHP $(php -r 'echo PHP_VERSION;')"
php -r 'foreach (["pdo_mysql","openssl","mbstring","curl","json","filter"] as $e) if (!extension_loaded($e)) { fwrite(STDERR, "lipsește extensia $e\n"); exit(1); }' \
  || mor "lipsesc extensii PHP"
systemctl enable --now apache2 >/dev/null 2>&1 || service apache2 start || true
systemctl enable --now mysql   >/dev/null 2>&1 || service mysql start   || true

# --------------------------------------------------------------- baza de date --

pas "Baza de date"
my -e "SELECT 1" >/dev/null 2>&1 \
  || mor "nu mă pot conecta la MySQL ca root. Dacă root are parolă, adaugă --db-root-pass 'parola'."

DB_PASS=""
if (( ACTUALIZARE )); then
  DB_PASS="$(php -r '$c = require $argv[1]; echo $c["db"]["pass"];' "$DIR/api/config.php")"
  info "Folosesc datele din config.php existent."
else
  DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 28)"
fi

my <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

TABELE="$(my -N -B -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'")"

if [[ "$TABELE" -eq 0 ]]; then
  info "Bază goală — import schema și datele inițiale."
  my --default-character-set=utf8mb4 "$DB_NAME" < "$SQL_DIR/001_schema.sql"
  my --default-character-set=utf8mb4 "$DB_NAME" < "$SQL_DIR/002_seed.sql"

  # Schema (001) e ținută la zi până la 007, dar nu mai departe: migrările de
  # după ea aduc tabele și coloane pe care 001 nu le are — comisioanele de
  # recomandare (008), pontajul (009), originea contului (010), reducerea
  # pachetului de test (011), rândul de comision scos din socoteală (012).
  # Fără ele, serverul pornește cu o bază pe jumătate și codul nou răspunde
  # „Eroare internă" la prima apăsare pe „Recomandări".
  #
  # Toate sunt idempotente și se uită întâi în information_schema, deci se pot
  # rula și peste ce a pus deja 001. Aceeași buclă, cu aceeași săritură peste
  # 001/002, ca în actualizeaza-vps.sh.
  # Registrul migrărilor se face ÎNAINTEA buclei. `025` se cheamă 025, deci în
  # ordinea numelui ar rula după `003`-`024`, iar cele dinaintea lui ar trece pe
  # lângă un tabel care încă nu există și n-ar avea unde-și scrie numele. E
  # idempotent, deci bucla îl poate lua încă o dată la rând, fără pagubă.
  # Lipsa lui dintr-un pachet mai vechi nu oprește instalarea.
  if [[ -e "$SQL_DIR/025_registru_migrari.sql" ]]; then
    my --default-character-set=utf8mb4 "$DB_NAME" < "$SQL_DIR/025_registru_migrari.sql" \
      || mor "registrul migrărilor n-a putut fi făcut — baza a rămas pe jumătate"
  fi

  for f in "$SQL_DIR"/[0-9][0-9][0-9]_*.sql; do
    [[ -e "$f" ]] || continue
    case "$(basename "$f")" in 001_*|002_*) continue ;; esac
    my --default-character-set=utf8mb4 "$DB_NAME" < "$f" \
      || mor "migrarea $(basename "$f") a picat — baza a rămas pe jumătate"
  done
  info "Migrări: toate, până la $(basename "$(ls -1 "$SQL_DIR"/[0-9][0-9][0-9]_*.sql | tail -1)")"
else
  info "Baza are deja $TABELE tabele — nu import nimic aici."
  info "Migrările care lipsesc se aplică cu actualizeaza-vps.sh (vezi docs/09)."
fi
PRODUSE="$(my -N -B -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.products" 2>/dev/null || echo 0)"
info "Tabele: $(my -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'") · produse: $PRODUSE"

# ------------------------------------------------------------------ fișiere --

pas "Copiez fișierele în $DIR"
mkdir -p "$DIR"/{public_html,clienti,lab,api}

# Cele patru copieri, fiecare cu plasa ei. `set -euo pipefail` oprea deja
# scriptul dacă una pica — dar îl oprea MUT, și tăcerea costă altfel după cum
# e prima rulare sau a doua. Prima oară, un folder necopiat înseamnă doar un
# site care nu merge încă: se rulează din nou și gata. A doua oară, când
# `ACTUALIZARE` e 1, înseamnă un site pe jumătate schimbat pe care CIRCULĂ
# comenzi adevărate — paginile noi cheamă un api care nu le cunoaște rutele.
# Scriptul ăsta, spre deosebire de `actualizeaza-vps.sh`, NU face nicio copie
# de siguranță: de-aia mesajul de acolo îl trimite pe om la celălalt script,
# nu la o arhivă care nu există.
rupt() {
  rosu ""
  rosu "EROARE: n-am putut copia folderul „$1”."
  rosu ""
  if (( ACTUALIZARE )); then
    rosu "Instalarea exista deja, deci site-ul e ACUM pe jumătate schimbat: ce s-a"
    rosu "copiat înainte de „$1” e nou, restul a rămas vechi. Cele două jumătăți nu"
    rosu "se potrivesc — clientul vede numai refuzuri."
    rosu ""
    rosu "Scriptul ăsta nu face copie de siguranță. Pentru actualizări folosește"
    rosu "actualizeaza-vps.sh: el arhivează întâi codul și baza, și știe să te ducă"
    rosu "înapoi dintr-o comandă. Vezi docs/09-actualizare-vps.md."
  else
    rosu "E prima instalare, deci nu s-a stricat nimic din ce era: pur și simplu"
    rosu "site-ul nu e încă întreg. Află de ce a picat copierea — de obicei e discul"
    rosu "plin (df -h) sau drepturile pe folder — și rulează scriptul din nou."
  fi
  rosu ""
  exit 1
}

# public_html: fără --delete, ca să nu dispară pozele urcate din panoul Catalog.
rsync -a            "$SURSA/public_html/" "$DIR/public_html/" || rupt public_html
rsync -a --delete   "$SURSA/clienti/"     "$DIR/clienti/"     || rupt clienti
rsync -a --delete   "$SURSA/lab/"         "$DIR/lab/"         || rupt lab
rsync -a --delete --exclude '/config.php' "$SURSA/api/"       "$DIR/api/"       || rupt api
mkdir -p "$DIR/public_html/products"
info "public_html · clienti · lab · api"

# --------------------------------------------------------------- config.php --

pas "api/config.php"
if (( ACTUALIZARE )); then
  info "Există deja — nu îl ating."
else
  CHEIE="$(php -r 'echo bin2hex(random_bytes(32));')"
  COD_INSTALARE="$(openssl rand -base64 24 | tr -d '/+=' | head -c 20)"
  cat > "$DIR/api/config.php" <<CFG
<?php
/**
 * Golden Pastry — configurare de producție.
 * Generat de instaleaza-vps.sh pe $(date '+%d.%m.%Y %H:%M'). Nu se urcă în git.
 * Cheile care lipsesc de aici au valori implicite în cod (vezi config.example.php).
 */

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => '${DB_NAME}',
        'user'    => '${DB_USER}',
        'pass'    => '${DB_PASS}',
        'charset' => 'utf8mb4',
    ],

    // 64 de caractere hexazecimale. Schimbată, tokenurile salvate din panoul
    // de Setări devin ilizibile și trebuie reintroduse.
    'encryption_key' => '${CHEIE}',

    // Deschide api/setup.php, pagina care creează primul administrator.
    // După ce contul există, pagina răspunde 404 și codul nu mai contează.
    'install_code' => '${COD_INSTALARE}',

    'allowed_origins' => [
        'https://${DOMAIN}',
        'https://www.${DOMAIN}',
        'https://clienti.${DOMAIN}',
        'https://lab.${DOMAIN}',
    ],

    // Punctul din față face sesiunea valabilă pe toate subdomeniile.
    'cookie_domain' => '.${DOMAIN}',
    'cookie_secure' => true,

    'site_url'  => 'https://${DOMAIN}',

    // API-ul stă în alt folder decât site-ul public: fără linia asta, pozele
    // urcate din Catalog s-ar salva unde site-ul nu le vede.
    'photo_dir' => '${DIR}/public_html/products',

    'env' => 'prod',
];
CFG
  php -l "$DIR/api/config.php" >/dev/null || mor "config.php generat greșit"
  verde "  Generat, cu cheie și cod de instalare noi."
fi

# ------------------------------------------------------------------ drepturi --

pas "Drepturi"
chown -R www-data:www-data "$DIR"
find "$DIR" -type d -exec chmod 755 {} \;
find "$DIR" -type f -exec chmod 644 {} \;
chmod 640 "$DIR/api/config.php"
info "products/ scriibil de Apache"

# ------------------------------------------------------------ ceasul plăților --

pas "Ceasul plăților"
# Raportul de încasări și amintirile de scadență (2.7.0) nu le cere nicio
# pagină: le pornește serverul, din cinci în cinci minute. Fără rândul de mai
# jos nu pleacă niciun raport, oricât de bine ar fi pus chatul în „Încasări".
#
# ACELAȘI text, literă cu literă, ca în actualizeaza-vps.sh: fiecare dintre cele
# două scripturi scrie fișierul numai dacă diferă, deci două texte deosebite
# l-ar fi rescris pe rând, la fiecare rulare a celuilalt. De ce `flock`, de ce
# `logger` și de ce întâi lângă el și apoi mutat — vezi acolo.
CRON_FISIER="/etc/cron.d/goldenpastry-plati"
CRON_TEXT="# Golden Pastry: raportul de încasări și amintirile de scadență, pe Telegram.
# Scris de scripturile de instalare și de actualizare; nu se editează de mână.
*/5 * * * * www-data flock -n /run/lock/gp-plati.lock php $DIR/api/cron/plati.php 2>&1 | logger -t gp-plati"
if [[ ! "$DIR" =~ ^[A-Za-z0-9_./-]+$ ]]; then
  rosu "  Calea „$DIR” are semne pe care cron le citește altfel. Ceasul NU a fost pus;"
  info "rapoartele de încasări nu vor pleca până nu e pus de mână."
elif [[ ! -f "$DIR/api/cron/plati.php" ]]; then
  rosu "  Pachetul nu are api/cron/plati.php (e mai vechi de 2.7.0?). Ceasul NU a fost pus."
elif [[ ! -d /etc/cron.d ]]; then
  rosu "  Serverul nu are cron (lipsește /etc/cron.d). Ceasul NU a fost pus."
  info "Pune-l cu:  apt-get install -y cron   și rulează din nou scriptul."
elif [[ -f "$CRON_FISIER" && "$(cat "$CRON_FISIER")" == "$CRON_TEXT" ]]; then
  info "$CRON_FISIER — neschimbat"
else
  printf '%s\n' "$CRON_TEXT" > "$CRON_FISIER.nou"
  chown root:root "$CRON_FISIER.nou"
  chmod 644 "$CRON_FISIER.nou"
  mv -f "$CRON_FISIER.nou" "$CRON_FISIER"
  info "$CRON_FISIER — scris (din 5 în 5 minute; se citește cu: journalctl -t gp-plati)"
fi
# FIȘIERUL NU E CEASUL. /etc/cron.d există și pe un server FĂRĂ cron: îl aduc
# alte pachete (php își pune acolo curățenia sesiunilor), deci întrebarea de mai
# sus trece, rândul se scrie, scriptul zice „scris" — și nu-l citește nimeni,
# niciodată. De aceea se întreabă de PROGRAM și de SERVICIU, cu același bloc ca
# în actualizeaza-vps.sh, și se spune pe față când rândul e pus degeaba.
if [[ -f "$CRON_FISIER" ]]; then
  if ! command -v cron >/dev/null 2>&1; then
    galben "  Rândul e scris, dar serverul nu are programul cron: ceasul NU merge."
    info "Pune-l cu:  apt-get install -y cron   (pornește singur; rândul e deja la locul lui)."
  else
    systemctl enable --now cron >/dev/null 2>&1 || service cron start >/dev/null 2>&1 || true
    if systemctl is-active --quiet cron 2>/dev/null || pgrep -x cron >/dev/null 2>&1; then
      info "serviciul cron merge"
    else
      galben "  Rândul e scris, dar serviciul cron e oprit: ceasul NU merge."
      info "Pornește-l cu:  systemctl enable --now cron   și vezi de ce:  systemctl status cron"
    fi
  fi
fi

# -------------------------------------------------------- programul de poștă --

# >>> posta-directa >>>
# ACELAȘI text, literă cu literă, în instaleaza-vps.sh și în actualizeaza-vps.sh
# (le compară .claude/test-posta-vps.mjs). Blocul e numai funcția; fiecare script
# o cheamă la locul lui, după ce a pus POSTA_DOMENIU (domeniul gol, fără „www.")
# și FARA_POSTA (0 ori 1). Se sprijină numai pe ajutoarele info și galben.
#
# DE CE EXISTĂ. Aplicația trimite e-mail pe două căi: prin căsuța (SMTP) din
# Setări → Notificări → E-mail, când e completată, ori — altfel — prin mail() din
# PHP. mail() nu trimite nimic el însuși: predă mesajul unui program de pe server
# (/usr/sbin/sendmail). Pe un VPS pus de instaleaza-vps.sh programul acela n-a
# existat niciodată (pe găzduirea de dinainte îl ținea gazda), deci linkul de la
# „Ți-ai uitat parola?" nu pleca și nimic nu spunea de ce.
#
# CE PUNE: postfix NUMAI DE TRIMIS. Ascultă doar pe 127.0.0.1 (din afară nu-l
# poate folosi nimeni), trimite numai pe IPv4 (adresa pe care o acoperă regula
# SPF a domeniului) și NU se socotește gazda domeniului: Debian trece din oficiu
# domeniul în `mydestination`, iar atunci un mesaj către o adresă a casei
# (cineva@domeniu) ar fi predat unui utilizator local care nu există, în loc să
# plece la căsuțele adevărate.
#
# NICIODATĂ `mor`, niciodată o ieșire cu eroare: e un pas de la coada unei
# lucrări care a reușit deja. Orice piedică e un rând galben, cu comanda de dat
# de mână. Un program de poștă găsit gata pus NU se atinge.
pune_posta() {
  local domeniu="${POSTA_DOMENIU:-}" prog="" mx="" dest="" pus=0
  # Ultima bucată numai din litere: un IP ori „localhost" nu e domeniu de poștă.
  local tipar='^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$'
  domeniu="${domeniu,,}"

  if (( ${FARA_POSTA:-0} )); then
    info "Sărit (--fara-posta). E-mailul pleacă numai prin căsuța (SMTP) din Setări → Notificări → E-mail."
    return 0
  fi

  if [[ -x /usr/sbin/sendmail ]]; then prog="/usr/sbin/sendmail"
  else prog="$(command -v sendmail 2>/dev/null || true)"; fi

  if [[ -n "$prog" ]]; then
    info "serverul are deja un program de poștă ($prog) — nu-l ating"
  elif [[ ! "$domeniu" =~ $tipar ]]; then
    galben "  Nu am putut citi domeniul site-ului, deci NU am pus programul de poștă."
    info "Până atunci e-mailul pleacă numai prin căsuța (SMTP) din Setări → Notificări → E-mail."
    return 0
  elif ! command -v apt-get >/dev/null 2>&1 || ! command -v debconf-set-selections >/dev/null 2>&1; then
    galben "  Serverul nu are apt: programul de poștă NU a fost pus."
    info "Pune unul de mână (postfix, numai de trimis) ori completează căsuța (SMTP) din Setări → Notificări → E-mail."
    return 0
  else
    info "instalez postfix, numai de trimis (poate ține un minut)…"
    # Răspunsurile la cele două întrebări ale instalării se dau dinainte: fără
    # ele, apt s-ar opri la un ecran albastru pe care, prin ssh, nu-l vede nimeni.
    printf 'postfix postfix/main_mailer_type select Internet Site\npostfix postfix/mailname string %s\n' "$domeniu" \
      | debconf-set-selections >/dev/null 2>&1 || true
    apt-get update -qq >/dev/null 2>&1 || true
    if DEBIAN_FRONTEND=noninteractive apt-get install -y -qq postfix >/dev/null 2>&1 \
       && command -v postconf >/dev/null 2>&1; then
      if postconf -e 'inet_interfaces = loopback-only' 'inet_protocols = ipv4' \
                     'mydestination = localhost.localdomain, localhost' >/dev/null 2>&1; then
        systemctl enable postfix >/dev/null 2>&1 || true
        systemctl restart postfix >/dev/null 2>&1 || service postfix restart >/dev/null 2>&1 || true
        info "postfix — pus: ascultă numai pe 127.0.0.1, trimite ca @$domeniu"
        pus=1
      else
        galben "  postfix s-a instalat, dar nu l-am putut așeza (postconf a refuzat)."
        info "Până e așezat, nu te bizui pe el: completează căsuța (SMTP) din Setări → Notificări → E-mail."
      fi
    else
      galben "  Programul de poștă NU s-a putut instala (apt a refuzat ori n-are legătură)."
      info "Restul lucrării e întreg. Ori completezi căsuța (SMTP) din Setări → Notificări → E-mail, ori dai de mână, mai târziu, AMÂNDOUĂ rândurile:"
      info "    DEBIAN_FRONTEND=noninteractive apt-get install -y postfix"
      info "    postconf -e 'inet_interfaces = loopback-only' 'inet_protocols = ipv4' 'mydestination = localhost.localdomain, localhost' && systemctl restart postfix"
      return 0
    fi
  fi

  # De aici încolo se numai CITEȘTE, fie că programul e al nostru, fie că era.
  if command -v postconf >/dev/null 2>&1; then
    # -x desface $myhostname; un postfix prea vechi ca să-l știe răspunde fără el.
    dest="$(postconf -hx mydestination 2>/dev/null || postconf -h mydestination 2>/dev/null || true)"
    dest=" ${dest//,/ } "
    if [[ -n "$domeniu" && "${dest,,}" == *" $domeniu "* ]]; then
      galben "  Programul de poștă se socotește gazda lui $domeniu (mydestination)."
      info "Un mesaj către o adresă @$domeniu NU pleacă la căsuța adevărată: rămâne pe server."
      info "Se îndreaptă cu:"
      info "    postconf -e 'mydestination = localhost.localdomain, localhost' && systemctl restart postfix"
    fi
    if ! (systemctl is-active --quiet postfix 2>/dev/null || pgrep -x master >/dev/null 2>&1); then
      galben "  Programul de poștă e instalat, dar oprit: pe calea directă NU pleacă nimic."
      info "Pornește-l cu:  systemctl enable --now postfix   și vezi de ce:  systemctl status postfix"
    elif (( ! pus )); then
      info "serviciul postfix merge"
    fi
  fi

  # Portul 25 spre lume. Multe găzduiri de VPS îl țin închis din oficiu; atunci
  # programul de poștă primește mesajul, zice „luat" și îl ține în coadă zile
  # întregi. Se încearcă o singură legătură, către serverul care primește poșta
  # domeniului, fără să se trimită nimic.
  if [[ -n "$domeniu" ]] && command -v php >/dev/null 2>&1 && command -v timeout >/dev/null 2>&1; then
    mx="$(php -r '$g = []; $p = []; if (@getmxrr($argv[1], $g, $p) && $g) { array_multisort($p, $g); echo $g[0]; }' "$domeniu" 2>/dev/null || true)"
    if [[ ! "$mx" =~ ^[A-Za-z0-9.-]+$ ]]; then
      info "portul 25 nu l-am putut încerca (n-am aflat cine primește poșta lui ${domeniu})"
    elif timeout 8 bash -c 'exec 3<>"/dev/tcp/$0/25"' "$mx" >/dev/null 2>&1; then
      info "portul 25 spre lume e deschis (am ajuns la $mx)"
    else
      galben "  Portul 25 pare închis de găzduire (n-am ajuns la $mx)."
      info "Pe calea directă mesajele rămân atunci în coadă și NU pleacă, fără nicio eroare în panou."
      info "Leacul nu e pe server: completează căsuța (SMTP) din Setări → Notificări → E-mail."
    fi
  fi

  info "Calea de nădejde rămâne căsuța (SMTP): ce pleacă direct de pe server ajunge des în Spam."
  info "Proba adevărată: butonul „Trimite e-mail de test” din Setări → Notificări → E-mail."
  if [[ -f /var/log/mail.log ]]; then
    info "Ce n-a plecat:  mailq      De ce:  tail -30 /var/log/mail.log"
  else
    info "Ce n-a plecat:  mailq      De ce:  journalctl -u 'postfix*' -n 30 --no-pager"
  fi
  return 0
}
# <<< posta-directa <<<

pas "Programul de poștă"
POSTA_DOMENIU="${DOMAIN#www.}"
pune_posta

# ------------------------------------------------------------ gazde virtuale --

pas "Gazdele virtuale Apache"
vhost() { # nume  server_name  docroot  [alias]
  local nume="$1" sname="$2" root="$3" alias="${4:-}"
  cat > "/etc/apache2/sites-available/${nume}.conf" <<VH
<VirtualHost *:80>
  ServerName ${sname}
  ${alias:+ServerAlias ${alias}}
  DocumentRoot ${root}
  <Directory ${root}>
    AllowOverride All
    Require all granted
  </Directory>
  ErrorLog \${APACHE_LOG_DIR}/${nume}-error.log
  CustomLog \${APACHE_LOG_DIR}/${nume}-access.log combined
</VirtualHost>
VH
  a2ensite "$nume" >/dev/null
  info "$sname → $root"
}

vhost gp-root    "$DOMAIN"          "$DIR/public_html" "www.$DOMAIN"
vhost gp-clienti "clienti.$DOMAIN"  "$DIR/clienti"
vhost gp-lab     "lab.$DOMAIN"      "$DIR/lab"
vhost gp-api     "api.$DOMAIN"      "$DIR/api"
a2dissite 000-default >/dev/null 2>&1 || true
apache2ctl configtest || mor "configurația Apache are o problemă"
systemctl reload apache2 >/dev/null 2>&1 || service apache2 reload || true
if ! (systemctl is-active --quiet apache2 2>/dev/null || pgrep -x apache2 >/dev/null); then
  rosu "  Apache nu rulează. Motivul, pe scurt:"
  systemctl status apache2 --no-pager 2>/dev/null | sed -n '1,8p' | sed 's/^/    /'
  mor "pornește Apache și rulează din nou (cel mai des: portul 80 e luat de altcineva)"
fi

if command -v ufw >/dev/null; then
  ufw allow OpenSSH >/dev/null 2>&1 || true
  ufw allow 'Apache Full' >/dev/null 2>&1 || true
  info "firewall: 22, 80, 443 deschise"
fi

# ---------------------------------------------------------------------- SSL --

pas "Certificate HTTPS"
if (( FARA_SSL )); then
  info "Sărit (--no-ssl). Fără HTTPS nu se poate autentifica nimeni: cookie_secure e true."
else
  IP_MEU="$(curl -4 -s --max-time 8 https://api.ipify.org || curl -4 -s --max-time 8 https://ifconfig.me || true)"

  # Ce văd rezolvatoarele publice, nu ce vede serverul: /etc/hosts sau un DNS
  # local rămas de la vechea găzduire dau alt răspuns decât restul lumii, iar
  # Let's Encrypt verifică oricum din exterior.
  command -v dig >/dev/null || apt-get install -y -qq dnsutils >/dev/null 2>&1 || true
  if command -v dig >/dev/null; then
    IP_DNS="$(dig +short @1.1.1.1 "api.$DOMAIN" A | tail -1)"
    IP_LOCAL="$(getent hosts "api.$DOMAIN" | awk '{print $1}' | head -1)"
    if [[ -n "$IP_LOCAL" && -n "$IP_DNS" && "$IP_LOCAL" != "$IP_DNS" ]]; then
      rosu "  Atenție: serverul rezolvă api.$DOMAIN spre $IP_LOCAL, dar în DNS-ul public e $IP_DNS."
      info "Verifică /etc/hosts și /etc/resolv.conf — nu oprește certbot, care întreabă din exterior."
    fi
  else
    IP_DNS="$(getent hosts "api.$DOMAIN" | awk '{print $1}' | head -1)"
  fi

  if [[ -z "$IP_DNS" ]]; then
    rosu "  api.$DOMAIN nu se rezolvă deloc. Fă înregistrările A și rulează după aceea:"
    info "  certbot --apache -d $DOMAIN -d www.$DOMAIN -d clienti.$DOMAIN -d lab.$DOMAIN -d api.$DOMAIN"
  else
    if [[ -n "$IP_MEU" && "$IP_MEU" != "$IP_DNS" ]]; then
      rosu "  DNS-ul public dă $IP_DNS, iar serverul are $IP_MEU. Încerc oricum — dacă"
      info "  înregistrările tocmai s-au schimbat, certbot poate reuși; dacă nu, va spune el."
    fi
    apt-get install -y -qq certbot python3-certbot-apache >/dev/null
    certbot --apache --non-interactive --agree-tos --redirect \
      ${EMAIL:+-m "$EMAIL"} ${EMAIL:---register-unsafely-without-email} \
      -d "$DOMAIN" -d "www.$DOMAIN" -d "clienti.$DOMAIN" -d "lab.$DOMAIN" -d "api.$DOMAIN" \
      && verde "  Certificate emise, reînnoire automată activă." \
      || rosu "  Certbot n-a reușit. Citește mesajul lui de mai sus și reia comanda manual."
  fi
fi

# ------------------------------------------------------------------- raport --

pas "Gata"
if (( ACTUALIZARE )); then
  verde "Actualizare terminată. Baza, config.php și pozele au rămas neatinse."
  echo
  echo "  https://lab.$DOMAIN"
  exit 0
fi

cat <<RAPORT

  Site public      https://$DOMAIN
  Clienți          https://clienti.$DOMAIN
  Laborator        https://lab.$DOMAIN
  API              https://api.$DOMAIN

  Baza de date     ${DB_NAME} · utilizator ${DB_USER}
  Parola bazei     ${DB_PASS}
  Cod de instalare ${COD_INSTALARE:-(în api/config.php)}

  Notează-le acum — parola și codul sunt și în ${DIR}/api/config.php.

  Mai departe:
    1. https://api.$DOMAIN/verifica.php   — totul verde?
       apoi:  rm ${DIR}/api/verifica.php
    2. https://api.$DOMAIN/setup.php      — creează primul administrator
       (primul câmp e codul de instalare de mai sus)
       apoi:  rm ${DIR}/api/setup.php
    3. https://lab.$DOMAIN                — intri cu contul creat
    4. Setări → Notificări → E-mail       — completează căsuța (SMTP) și apasă
       „Trimite e-mail de test”: pe ea se bizuie linkurile de parolă uitată

  Actualizări: urci noul deploy/ și rulezi din nou exact aceeași comandă.
  Copie de siguranță: mysqldump -uroot ${DB_NAME} > ~/gp-\$(date +%F).sql

RAPORT
