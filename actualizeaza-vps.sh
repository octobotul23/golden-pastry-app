#!/usr/bin/env bash
#
# Golden Pastry — actualizarea unei instalări care merge deja pe VPS.
#
#   sudo bash actualizeaza-vps.sh
#
# Ce face, în ordine: copie de siguranță a bazei, a lui api/config.php și a
# codului, aplică migrările care lipsesc, copiază pachetul din deploy/ peste
# cele patru foldere, pune drepturile la loc, repornește Apache.
#
# Ce NU atinge niciodată: api/config.php (parola bazei, cheia de criptare,
# codul de instalare) și folderul pozelor urcate din panoul Catalog.
#
# Se poate rula de câte ori vrei. Pe un server gol nu merge — acolo e
# instaleaza-vps.sh, care face și Apache, și MySQL, și certificatele.
#
set -euo pipefail

DIR="/var/www/gp"
PACHET=""
DB_ROOT_PASS=""
COPII="/var/backups/golden-pastry"
PASTREAZA=10
FARA_COPIE=0
DOAR=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dir)          DIR="${2:-}"; shift 2 ;;
    --package)      PACHET="${2:-}"; shift 2 ;;
    --db-root-pass) DB_ROOT_PASS="${2:-}"; shift 2 ;;
    --copii)        COPII="${2:-}"; shift 2 ;;
    --pastreaza)    PASTREAZA="${2:-}"; shift 2 ;;
    --fara-copie)   FARA_COPIE=1; shift ;;
    --doar-baza)    DOAR="baza"; shift ;;
    --doar-fisiere) DOAR="fisiere"; shift ;;
    -h|--help)
      sed -n '2,15p' "$0" | sed 's/^# \?//'
      cat <<'AJUTOR'

Opțiuni:
  --dir CALE           unde e instalarea (implicit /var/www/gp)
  --package CALE       unde e pachetul, dacă nu e lângă script
  --db-root-pass PAR   intră ca root în MySQL, nu cu contul din config.php
  --copii CALE         unde se pun copiile (implicit /var/backups/golden-pastry)
  --pastreaza N        câte copii se țin (implicit 10)
  --fara-copie         sari peste copia de siguranță — numai dacă tocmai ai
                       făcut una cu mâna ta
  --doar-baza          numai copia și migrările, fără fișiere
  --doar-fisiere       numai fișierele, fără să atingi baza
AJUTOR
      exit 0 ;;
    *) echo "Argument necunoscut: $1"; exit 2 ;;
  esac
done

# Bara de la coadă se taie AICI, înainte ca vreo cale să fie folosită undeva.
# Apărarea pozelor de mai jos e, la rădăcină, o potrivire de text între două
# căi: cu „--dir /var/www/gp/" scris firesc, cu bară, potrivirea dă greș, iar
# rsync ar goli folderul cu fotografiile urcate de patiserie — în timp ce
# scriptul scrie, de două ori, că nu le-a atins.
DIR="${DIR%/}"
COPII="${COPII%/}"
PACHET="${PACHET%/}"

# ------------------------------------------------------------------ ajutoare --

rosu()   { printf '\033[31m%s\033[0m\n' "$*"; }
verde()  { printf '\033[32m%s\033[0m\n' "$*"; }
galben() { printf '\033[33m%s\033[0m\n' "$*"; }
pas()    { printf '\n\033[1m▸ %s\033[0m\n' "$*"; }
info()   { printf '  %s\n' "$*"; }
mor()    { rosu "EROARE: $*"; exit 1; }

# Româna cere „de” de la douăzeci în sus: 3 coloane, dar 22 de coloane. Fără
# asta, raportul iese cu „1 tabele” exact în rândul pe care omul îl citește ca
# să afle dacă i-a mers actualizarea.
numar() {
  if   (( $1 == 1 )); then echo "$1 $2"
  elif (( $1 < 20 )); then echo "$1 $3"
  else                     echo "$1 de $3"
  fi
}

[[ $EUID -eq 0 ]] || mor "rulează cu sudo: sudo bash $0"

# Toate uneltele se cer ACUM, nu pe rând, când se împiedică lucrarea de ele.
# Fără php, citirea lui config.php întoarce un șir gol, iar scriptul s-ar
# plânge că „nu pot citi numele bazei" — adică ar arăta cu degetul exact spre
# fișierul care n-are nicio vină.
for u in rsync php mysql mysqldump tar gzip; do
  command -v "$u" >/dev/null && continue
  case "$u" in
    php)             mor "lipsește php (apt-get install -y php-cli) — fără el nu pot citi api/config.php" ;;
    mysql|mysqldump) mor "lipsește $u (apt-get install -y mysql-client, ori mariadb-client)" ;;
    *)               mor "lipsește $u (apt-get install -y $u)" ;;
  esac
done

if [[ ! -f "$DIR/api/config.php" ]]; then
  rosu "EROARE: nu găsesc o instalare în $DIR (lipsește api/config.php)."
  info "Dacă serverul e gol, rulează întâi instalarea:"
  info "    sudo bash instaleaza-vps.sh --domain exemplul-tau.ro --email tu@exemplu.ro"
  info "Dacă instalarea e în altă parte, dă-i calea: --dir /calea/catre/gp"
  exit 1
fi

# -------------------------------------------------------------------- pachet --

# Folderul dat cu --package, apoi cel în care stă CHIAR SCRIPTUL, abia la
# urmă folderul curent. Ordinea nu e o preferință: pe un server pe care s-a
# făcut cândva instalarea a rămas un /root/deploy vechi, iar dacă omul dă
# comanda din /root, acela ar câștiga în fața pachetului nou și actualizarea
# ar pune la loc, cu succes raportat, exact versiunea de dinainte. Scriptul
# vine ÎN pachet, deci folderul lui e răspunsul cel mai sigur.
# Acceptăm și „deploy/” întreg, și conținutul lui desfăcut direct, fiindcă
# amândouă ies dintr-un scp reușit.
gaseste_pachet() {
  local c
  for c in "$PACHET" "$(cd "$(dirname "$0")" && pwd)" "$PWD"; do
    [[ -z "$c" ]] && continue
    [[ -d "$c/deploy/api" ]] && { echo "$c/deploy"; return; }
    [[ -d "$c/api" && -d "$c/public_html" ]] && { echo "$c"; return; }
  done
  echo ""
}

pas "Caut pachetul"
SURSA="$(gaseste_pachet)"
if [[ "$DOAR" != "baza" ]]; then
  [[ -n "$SURSA" && -d "$SURSA/api" ]] \
    || mor "nu găsesc pachetul. Urcă folderul deploy/ și rulează scriptul lângă el (sau dă --package /cale/catre/deploy)."
  info "Pachet: $SURSA"

  # „Am urcat plasturele și n-a făcut update-ul" — de cele mai multe ori nu
  # s-a stricat nimic: s-a aplicat, cu succes raportat, un pachet mai vechi
  # rămas pe server. Raportul arată la fel de verde în amândouă cazurile, deci
  # singurul lucru care lămurește e pachetul să spună el din ce zi e.
  # Ștampila o scrie `build-deploy.mjs` în deploy/PACHET.txt.
  if [[ -f "$SURSA/PACHET.txt" ]]; then
    info "$(grep -m1 '^Facut:' "$SURSA/PACHET.txt" | sed 's/^Facut: */Făcut la: /')"
    info "$(grep -m1 '^Amprenta:' "$SURSA/PACHET.txt")"
  else
    galben "  Pachetul nu poartă ștampilă — e construit cu o versiune veche de"
    galben "  build-deploy.mjs. Nu pot spune din ce zi e. Dacă te aștepți la"
    galben "  ceva nou și nu vezi, ăsta e primul lucru de bănuit."
  fi

  # Un pachet construit pe jumătate e mai rău decât niciunul: rsync l-ar copia
  # cu tot cu golurile lui, iar site-ul ar rămâne fără paginile care lipsesc.
  for f in api/index.php public_html/index.php lab/comenzi.html clienti/client.html; do
    [[ -f "$SURSA/$f" ]] || mor "pachetul e incomplet — lipsește $f. Construiește-l din nou (node .claude/build-deploy.mjs) și urcă-l întreg."
  done
else
  info "Sărit (--doar-baza)."
fi

SQL_DIR=""
for c in "$SURSA/sql" "$SURSA/../sql" "$PWD/sql" "$(cd "$(dirname "$0")" && pwd)/sql"; do
  [[ -n "$c" && -f "$c/001_schema.sql" ]] && { SQL_DIR="$(cd "$c" && pwd)"; break; }
done
if [[ "$DOAR" != "fisiere" ]]; then
  [[ -n "$SQL_DIR" ]] || mor "nu găsesc folderul sql/ (urcă-l lângă deploy/)"
  info "Migrări: $SQL_DIR"
fi

# ----------------------------------------------------------------- config.php --

# Datele bazei se citesc din configurarea serverului, nu se cer în argumente:
# acolo stă adevărul, iar o nepotrivire între ce tastezi tu și ce folosește
# aplicația ar face copia de siguranță a altei baze decât cea care se schimbă.
cheie_din_config() {
  php -r '$c = require $argv[1];
          $v = $c;
          foreach (explode(".", $argv[2]) as $p) { $v = is_array($v) ? ($v[$p] ?? "") : ""; }
          echo is_scalar($v) ? (string) $v : "";' "$DIR/api/config.php" "$1"
}

DB_HOST="$(cheie_din_config db.host)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(cheie_din_config db.port)"; DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(cheie_din_config db.name)"
DB_USER="$(cheie_din_config db.user)"
DB_PASS="$(cheie_din_config db.pass)"
POZE="$(cheie_din_config photo_dir)"
[[ -n "$DB_NAME" ]] || mor "nu pot citi numele bazei din $DIR/api/config.php"

# Calea pozelor se strânge o dată, aici, și se folosește peste tot mai jos: și
# arhiva codului, și curățenia din rsync o compară cu folderul site-ului, iar
# comparația aceea se face pe text. Gol în config înseamnă implicitul din cod
# (api/routes/catalog.php, photo_dir): folderul „products” de lângă api/.
# `readlink -m` aduce „/var/www/gp//products", „/var/www/./gp/products" și o
# legătură simbolică la aceeași formă, fără să ceară ca folderul să existe.
[[ -n "$POZE" ]] || POZE="$DIR/products"
POZE="${POZE%/}"
DIR_REAL="$DIR"
if command -v readlink >/dev/null; then
  POZE="$(readlink -m "$POZE")"
  DIR_REAL="$(readlink -m "$DIR")"
fi

# Parola nu se dă pe linia de comandă: acolo o vede orice utilizator al
# serverului cu un simplu `ps aux`, cât ține mysqldump-ul. Într-un fișier numai
# al lui root nu o vede nimeni, iar `trap` îl șterge și dacă scriptul pică.
CNF="$(mktemp)"
chmod 600 "$CNF"
trap 'rm -f "$CNF"' EXIT
if [[ -n "$DB_ROOT_PASS" ]]; then
  printf '[client]\nuser=root\npassword=%s\n' "$DB_ROOT_PASS" > "$CNF"
else
  printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' \
    "$DB_HOST" "$DB_PORT" "$DB_USER" "$DB_PASS" > "$CNF"
fi

# --default-character-set=utf8mb4 la fiecare chemare, nu în fișier: fără el
# diacriticele din migrări ajung stricate în bază, iar „Predată” devine un text
# pe care aplicația nu-l mai recunoaște ca etapă.
my()     { mysql     --defaults-extra-file="$CNF" --default-character-set=utf8mb4 "$@"; }
mydump() { mysqldump --defaults-extra-file="$CNF" --default-character-set=utf8mb4 "$@"; }

pas "Baza de date"
my -N -B -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1 || {
  rosu "  Nu mă pot conecta la baza „$DB_NAME” cu datele din config.php."
  info "Dacă parola contului a fost schimbată în MySQL fără să fie trecută și în"
  info "config.php, intră ca root pentru rularea asta:  --db-root-pass 'parola'"
  exit 1
}
TABELE="$(my -N -B -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB_NAME'")"
[[ "$TABELE" -gt 0 ]] || mor "baza „$DB_NAME” e goală. Asta nu e o actualizare — rulează instaleaza-vps.sh."
info "„$DB_NAME” · $(numar "$TABELE" tabel tabele) · cont $( [[ -n "$DB_ROOT_PASS" ]] && echo root || echo "$DB_USER" )"

# ------------------------------------------------------- copia de siguranță --

pas "Copie de siguranță"
CLIPA="$(date '+%Y-%m-%d-%H%M%S')"
DUMP=""
if (( FARA_COPIE )); then
  galben "  Sărită (--fara-copie). Dacă migrarea pică, nu ai la ce te întoarce."
elif [[ "$DOAR" == "fisiere" ]]; then
  info "Sărită (--doar-fisiere): baza nu se atinge."
else
  mkdir -p "$COPII"
  chmod 700 "$COPII"   # dump-ul are telefoanele și adresele clienților
  DUMP="$COPII/gp-$CLIPA.sql.gz"

  # --single-transaction: fotografie coerentă fără să blocheze tabelele, deci
  # patiseria poate primi comenzi cât ține copia.
  # --no-tablespaces: pe MySQL 8 mysqldump cere dreptul global PROCESS ca să
  # citească tablespace-urile, iar contul aplicației are drepturi doar pe baza
  # ei. Fără opțiunea asta, copia pică fix pe serverul unde totul e în regulă.
  DUMP_OPT=(--single-transaction --routines --events --no-tablespaces)
  # Ajutorul se citește ÎNTÂI într-o variabilă și abia apoi se caută în el.
  # Legat direct în conductă, `grep -q` iese la prima potrivire, mysqldump ia
  # SIGPIPE, iar `pipefail` face conducta să pară picată — adică exact pe
  # serverul unde opțiunea EXISTĂ am fi renunțat la ea, și copia ar fi picat.
  AJUTOR_DUMP="$(mydump --help 2>/dev/null || true)"
  case "$AJUTOR_DUMP" in
    *--no-tablespaces*) ;;
    *) DUMP_OPT=(--single-transaction --routines --events) ;;
  esac

  if ! mydump "${DUMP_OPT[@]}" "$DB_NAME" 2>/tmp/gp-dump.err | gzip -9 > "$DUMP"; then
    rosu "  mysqldump n-a reușit:"
    sed -n '1,6p' /tmp/gp-dump.err | sed 's/^/    /'
    mor "nu pornesc actualizarea fără copie de siguranță"
  fi

  # Un fișier gz care se deschide și se termină cu marcajul lui mysqldump. O
  # copie ruptă la mijloc arată la fel de bine în `ls` ca una întreagă — se
  # descoperă abia în ziua în care ai nevoie de ea.
  gzip -t "$DUMP" 2>/dev/null || mor "copia a ieșit stricată: $DUMP"
  COADA="$(gunzip -c "$DUMP" | tail -5)"
  case "$COADA" in
    *"Dump completed"*) ;;
    *) mor "copia s-a oprit la mijloc: $DUMP" ;;
  esac
  chmod 600 "$DUMP"
  info "Baza:       $DUMP  ($(du -h "$DUMP" | cut -f1))"
  # config.php nu e în niciun pachet și nu se poate reface: cheia de criptare
  # din el descuie tokenurile salvate din Setări.
  #
  # `cp` simplu, nu `cp -a`: `-a` păstrează data fișierului sursă, așa că toate
  # copiile ar purta data lui api/config.php, adică aceeași. Curățenia de mai
  # jos ar avea atunci de ales între fișiere la fel de „vechi" și ar putea
  # șterge exact copia de azi. Drepturile le punem oricum imediat dedesubt.
  cp "$DIR/api/config.php" "$COPII/config-$CLIPA.php"
  chmod 600 "$COPII/config-$CLIPA.php"
  info "config.php: $COPII/config-$CLIPA.php"

  # Ordinea se ia din NUME, nu din data fișierului: ștampila e an-lună-zi-oră,
  # deci ordinea alfabetică e chiar ordinea în timp, și rămâne dreaptă și dacă
  # cineva atinge fișierele cu `touch` sau le mută de pe alt disc.
  if [[ "$PASTREAZA" =~ ^[0-9]+$ ]] && (( PASTREAZA > 0 )); then
    VECHI=0
    for f in $(ls -1 "$COPII"/gp-*.sql.gz 2>/dev/null | sort -r | tail -n +$((PASTREAZA + 1))); do
      rm -f "$f"; VECHI=$((VECHI + 1))
    done
    for f in $(ls -1 "$COPII"/config-*.php 2>/dev/null | sort -r | tail -n +$((PASTREAZA + 1))); do
      rm -f "$f"
    done
    (( VECHI )) && info "Am șters $(numar "$VECHI" "copie mai veche" "copii mai vechi") (păstrez ultimele $PASTREAZA)."
  fi
fi

# Baza se poate întoarce din dump; codul, până acum, nu se putea întoarce din
# nimic. `rsync --delete` îl scrie peste, fără plasă: dacă pachetul nou se
# dovedește stricat, ori dacă rsync-ul pică la al treilea din patru foldere,
# singura cale înapoi era o nouă construire pe calculatorul de acasă. O arhivă
# a celor patru foldere costă câteva secunde și rezolvă amândouă cazurile.
#
# Se face și când baza nu se atinge (--doar-fisiere): tocmai atunci rsync e
# singurul lucru care se întâmplă. api/config.php nu intră — e salvat separat,
# iar o arhivă fără el se poate desface peste instalare fără să calce parola.
ARHIVA_COD=""
if (( ! FARA_COPIE )) && [[ "$DOAR" != "baza" ]]; then
  mkdir -p "$COPII"
  chmod 700 "$COPII"
  ARHIVA_COD="$COPII/cod-$CLIPA.tar.gz"
  EXCL_COD=(--exclude api/config.php)
  [[ "$POZE" == "$DIR_REAL/"* ]] && EXCL_COD+=(--exclude "${POZE#"$DIR_REAL/"}")
  # Arhiva se scrie pe ieșirea standard, nu cu -f: un nume de fișier care are
  # două puncte în el (`--copii C:/ceva`, ori o cale de rețea) e citit de tar
  # ca "gazdă:cale" și încercarea pleacă pe rețea. Cu ">" nu are ce înțelege
  # greșit, fiindcă numele nici nu ajunge la tar.
  if tar cz -C "$DIR" "${EXCL_COD[@]}" api public_html clienti lab > "$ARHIVA_COD" 2>/tmp/gp-tar.err; then
    chmod 600 "$ARHIVA_COD"
    info "Codul:      $ARHIVA_COD  ($(du -h "$ARHIVA_COD" | cut -f1))"
  else
    sed -n '1,4p' /tmp/gp-tar.err | sed 's/^/    /'
    mor "n-am putut face copia codului — nu pornesc actualizarea fără ea"
  fi
  # Aceeași pază ca la curățenia copiilor de bază, cuvânt cu cuvânt. Lipsea, și
  # asta nu ștergea „o copie mai veche": `sort -r` pune arhiva de AZI prima, iar
  # `--pastreaza 0` face `tail -n +1`, adică lista întreagă. Scriptul scria
  # „Codul: …", se lăuda cu dimensiunea ei, și o ștergea zece rânduri mai jos —
  # tocmai copia pe care tot el o numește, la sfârșit, calea înapoi.
  #
  # Aceeași valoare rea trecea și pe lângă cifre: `--pastreaza` fără nimic după
  # el lasă variabila goală, iar `$(( + 1))` e 1, deci iar lista întreagă.
  # De-aia paza cere ÎNTÂI să fie un număr și abia pe urmă să fie mai mare ca
  # zero, în ordinea asta.
  #
  # Cu paza pusă, `--pastreaza 0` înseamnă „nu face curățenie deloc" — exact ce
  # înseamnă deja pentru copiile de bază. Și tot ea face iar adevărate două
  # mesaje de mai jos, fără să le atingă nimeni: `rupt()` nu mai spune „ai
  # pornit scriptul cu --fara-copie" despre o arhivă pe care a șters-o singur
  # (:505-512), iar raportul de la urmă nu mai arată calea unui fișier care nu
  # mai e (:635). Amândouă se uitau la adevăr; adevărul era cel stricat.
  if [[ "$PASTREAZA" =~ ^[0-9]+$ ]] && (( PASTREAZA > 0 )); then
    for f in $(ls -1 "$COPII"/cod-*.tar.gz 2>/dev/null | sort -r | tail -n +$((PASTREAZA + 1))); do
      rm -f "$f"
    done
  fi
fi

# ------------------------------------------------------------------ migrări --

# Migrările merg ÎNAINTEA fișierelor, nu după. Toate sunt aditive, deci codul
# vechi trece liniștit peste coloanele noi — nu le cere. Invers însă, codul nou
# pe o bază veche cere coloane care nu există și răspunde cu „Eroare internă”
# clienților adevărați, exact în minutele dintre cele două jumătăți.
pas "Migrări"
if [[ "$DOAR" == "fisiere" ]]; then
  info "Sărite (--doar-fisiere)."
else
  # Amprenta schemei: tabele, coloane și lungimea totală a tipurilor. A treia
  # cifră se mișcă atunci când o listă ENUM capătă o valoare — așa se vede că
  # 007 a adăugat rolurile, deși n-a apărut nicio coloană nouă.
  amprenta() {
    my -N -B "$DB_NAME" -e "
      SELECT (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()),
             (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()),
             (SELECT COALESCE(SUM(CHAR_LENGTH(COLUMN_TYPE)), 0)
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE())"
  }

  APLICATE=0
  SARITE=0
  for f in "$SQL_DIR"/[0-9][0-9][0-9]_*.sql; do
    [[ -e "$f" ]] || continue
    NUME="$(basename "$f")"

    # 001 face schema de la zero, 002 pune datele inițiale. Amândouă sunt
    # pentru o bază goală. 002 are numai INSERT IGNORE, deci pare inofensiv —
    # dar IGNORE sare doar peste chei duplicate, iar un produs pe care l-ai
    # șters din Catalog n-are cu ce se ciocni: s-ar întoarce la fiecare
    # actualizare, în catalogul clienților.
    case "$NUME" in
      001_*|002_*) continue ;;
    esac

    INAINTE="$(amprenta)"
    if ! my "$DB_NAME" < "$f" >/dev/null 2>/tmp/gp-mig.err; then
      rosu "  $NUME a picat:"
      sed -n '1,8p' /tmp/gp-mig.err | sed 's/^/    /'
      echo
      rosu "  Fișierele NU au fost copiate — serverul merge mai departe cu versiunea veche."
      if [[ -n "$DUMP" ]]; then
        info "Dacă baza a rămas pe jumătate schimbată, se pune la loc așa:"
        info "    gunzip -c $DUMP | mysql -u $DB_USER -p --default-character-set=utf8mb4 $DB_NAME"
      fi
      exit 1
    fi
    DUPA="$(amprenta)"

    if [[ "$INAINTE" == "$DUPA" ]]; then
      info "$NUME — era deja aplicată"
      SARITE=$((SARITE + 1))
    else
      read -r t1 c1 l1 <<< "$INAINTE"
      read -r t2 c2 l2 <<< "$DUPA"
      SCHIMB=""
      (( t2 > t1 )) && SCHIMB="$SCHIMB, $(numar $((t2 - t1)) tabel tabele)"
      (( c2 > c1 )) && SCHIMB="$SCHIMB, $(numar $((c2 - c1)) coloană coloane)"
      (( l2 != l1 && t2 == t1 && c2 == c1 )) && SCHIMB="$SCHIMB, valori noi în liste"
      verde "  $NUME — aplicată acum${SCHIMB:+ (${SCHIMB#, })}"
      APLICATE=$((APLICATE + 1))
    fi
  done
  info "Bilanț: $APLICATE aplicate acum · $SARITE erau deja acolo"

  # 017 e singura migrare din tot lanțul care nu atinge DELOC schema: scrie un
  # rând în `settings` și atât. Bilanțul de mai sus măsoară tabele, coloane și
  # lungimea tipurilor — pentru ea cele trei cifre nu se mișcă niciodată, deci
  # rândul ei iese „era deja aplicată" CHIAR ȘI la prima rulare. Măsurat, nu
  # bănuit: 21·229·2546 înainte, 21·229·2546 după.
  #
  # Munca se face — procentul chiar se scrie — dar raportul spune pe dos exact
  # despre migrarea care repară comisioanele de 0,00 RON. De-aceea procentul se
  # citește aici din bază și se spune CU VALOAREA LUI: e martorul însuși, nu o
  # urmă a lui într-o cifră de schemă.
  PROC="$(my -N -B "$DB_NAME" -e \
    "SELECT COALESCE(TRIM(value), ''), IF(updated_by IS NULL, 'casa', 'om')
       FROM settings WHERE setting_key = 'referral_rate_default'" 2>/dev/null || echo "")"
  if [[ -n "$PROC" ]]; then
    # Tăiat pe TAB, nu cu `read`: valoarea poate fi goală, iar `read VAL CINE`
    # ar muta atunci „casa" în VAL și raportul ar scrie „Procentul: casa%".
    VAL="${PROC%%$'\t'*}"
    CINE="${PROC##*$'\t'}"
    if [[ -z "$VAL" ]]; then
      galben "  Procentul de recomandare e gol — recomandările nu aduc nimic."
      info "Îl pui din Setări → Recomandări."
    elif [[ "$CINE" == "casa" ]]; then
      verde "  Procentul de recomandare: $VAL% — pus acum (nu-l hotărâse nimeni)."
    elif [[ "$VAL" =~ ^0+([.,]0+)?$ ]]; then
      # Un zero hotărât de om rămâne zero — migrarea nu-i schimbă părerea
      # patronului în somn. Dar nici nu tace despre urmarea lui: procentul se
      # îngheață la nașterea rândului de comision, deci fiecare comision făcut
      # cât timp e zero rămâne 0,00 RON pe veci, oricât s-ar pune după aceea.
      galben "  Procentul de recomandare e 0% — hotărât de tine, deci l-am lăsat."
      info "Cât timp e zero, fiecare comision nou se naște 0,00 RON și rămâne"
      info "așa pe veci: procentul se îngheață la nașterea rândului. Îl schimbi"
      info "din Setări → Recomandări."
    else
      info "Procentul de recomandare: $VAL% — hotărât de tine, l-am lăsat în pace."
    fi
  fi

  # 018 pățește exact ca 017, din exact aceeași pricină: seamănă două rânduri
  # în `settings` (`company_address`, `company_hours`) și nu atinge deloc
  # schema, deci cele trei cifre ale amprentei nu se mișcă și bilanțul o dă
  # „era deja aplicată" chiar la prima ei rulare.
  #
  # Aici tăcerea ar fi mai rea decât la 017. Adresa și programul sunt singurele
  # două lucruri din tot pachetul pe care le citește OMUL DE PE STRADĂ: stau în
  # pagina publică, în datele trimise lui Google și în subsolul magazinului.
  # Până la migrarea asta, casetele din Setări primeau „salvat" și nu scriau
  # nimic nicăieri (`settings_save()` trece numai peste cheile care au deja
  # rând), deci adresa firmei era în fapt scrisă în cod. Un raport care spune
  # „era deja aplicată" despre tocmai reparația aceea îl lasă pe om să creadă
  # că nici acum nu se poate scrie.
  #
  # Se citesc deci amândouă și se spun CU VALOAREA LOR, ca procentul de mai
  # sus: rândul însuși e martorul, nu urma lui într-o cifră de schemă.
  FIRMA="$(my -N -B "$DB_NAME" -e \
    "SELECT setting_key, COALESCE(TRIM(value), ''), IF(updated_by IS NULL, 'casa', 'om')
       FROM settings WHERE setting_key IN ('company_address', 'company_hours')
      ORDER BY setting_key" 2>/dev/null || echo "")"
  if [[ -z "$FIRMA" ]]; then
    # Nici măcar rândurile goale n-au ajuns în tabel: 018 n-a intrat, iar
    # casetele din Setări vor primi mai departe „salvat" fără să salveze.
    galben "  Adresa și programul n-au rând în Setări — migrarea 018 n-a intrat."
    info "Până intră, casetele „Adresa publică” și „Program de lucru” din Setări"
    info "primesc răspuns bun și nu schimbă nimic, nicăieri."
  else
    while IFS= read -r LINIE; do
      [[ -z "$LINIE" ]] && continue
      # Tăiat pe TAB cu expansiuni, nu cu `read CHEIE VAL CINE` — aceeași
      # capcană ca la procent, dar aici e sigură, nu doar cu putință: bash
      # socotește TAB-ul drept spațiu alb chiar și când IFS e pus anume pe el,
      # deci două TAB-uri lipite (valoarea goală) trec drept unul singur, iar
      # „om" ajunge în locul valorii. Raportul scria atunci, cu toată
      # seriozitatea, „Adresa publică: om".
      CHEIE="${LINIE%%$'\t'*}"
      REST="${LINIE#*$'\t'}"
      VAL="${REST%$'\t'*}"
      CINE="${REST##*$'\t'}"
      # Două nume, fiindcă româna cere acordul: adresa e pusă, programul e pus.
      # O singură formă ar fi scris „Programul de lucru e goală".
      if [[ "$CHEIE" == "company_address" ]]; then
        CE="Adresa publică"; GOL="e goală"; PUS="pusă acum (nu o scrisese nimeni)"
        LASAT="scrisă de tine, am lăsat-o în pace"; PUNE="O pui"
      else
        CE="Programul de lucru"; GOL="e gol"; PUS="pus acum (nu-l scrisese nimeni)"
        LASAT="scris de tine, l-am lăsat în pace"; PUNE="Îl pui"
      fi
      if [[ -z "$VAL" ]]; then
        # Golul e o stare adevărată, nu o lipsă de rând: cineva a șters textul
        # din Setări. Pagina publică nu rămâne goală — cade pe temeiul din
        # `api/lib/firma.php` — dar de scris nu mai scrie nimeni adevărul.
        galben "  $CE $GOL — site-ul arată temeiul scris în cod."
        info "$PUNE din Setări → General."
      elif [[ "$CINE" == "casa" ]]; then
        verde "  $CE: $VAL — $PUS."
      else
        info "$CE: $VAL — $LASAT."
      fi
    done <<< "$FIRMA"
  fi

  # 010 nu schimbă numai schema, schimbă o PURTARE, și o face în tăcere: de
  # acum înainte, un cont făcut singur pe site nu se mai leagă de la sine de
  # comenzile luate la telefon pe același nume, până nu-l recunoaște cineva
  # din Conturi. Cifra se spune acum, nu se descoperă peste o săptămână, la o
  # comandă care „nu i-a apărut clientului în cont".
  NERECUNOSCUTE="$(my -N -B "$DB_NAME" -e \
    "SELECT COUNT(*) FROM users WHERE self_registered = 1 AND vouched_at IS NULL" 2>/dev/null || echo "")"
  if [[ "$NERECUNOSCUTE" =~ ^[0-9]+$ ]] && (( NERECUNOSCUTE > 0 )); then
    galben "  $(numar "$NERECUNOSCUTE" "cont făcut pe site" "conturi făcute pe site") nu se mai leagă singure la comenzile luate la telefon."
    info "Le recunoști din Conturi, cu „Recunosc clientul”. Până atunci, clientul"
    info "își vede în cont numai comenzile date chiar de pe site."
  fi
fi

# ------------------------------------------------------------------ fișiere --

pas "Fișiere"
if [[ "$DOAR" == "baza" ]]; then
  info "Sărite (--doar-baza)."
else
  # Dacă pozele stau ÎN site-ul public — și acolo le pune instalarea — folderul
  # lor se scoate din curățenie pe numele lui, nu pe ghicite. Restul site-ului
  # se curăță: un fișier rămas de la o versiune veche e servit în continuare,
  # cu tot cu codul lui vechi.
  ARG_PUBLIC=(-a --delete)
  SUB_PUBLIC="$DIR_REAL/public_html/"
  REL_POZE=""
  [[ "$POZE" == "$SUB_PUBLIC"* ]] && REL_POZE="${POZE#"$SUB_PUBLIC"}"
  if [[ -n "$REL_POZE" ]]; then
    ARG_PUBLIC+=(--exclude "/$REL_POZE")
    info "Pozele („$REL_POZE”) rămân neatinse."
  elif [[ "$POZE" == *"/public_html/"* ]]; then
    # Pozele sunt limpede sub site-ul public, dar potrivirea n-a ieșit. Aici
    # nu se merge mai departe cu un mesaj liniștitor: rsync ar goli folderul,
    # iar fotografiile patiseriei nu sunt în niciun pachet și în nicio copie.
    # O actualizare oprită se reia în două minute; o poză pierdută, nu.
    mor "nu pot lega folderul pozelor ($POZE) de site-ul public ($SUB_PUBLIC). Dă calea instalării simplu, fără bară la coadă: --dir /var/www/gp"
  else
    info "Pozele stau în afara site-ului public ($POZE) — nu le atinge nimic."
  fi

  # setup.php creează primul administrator, verifica.php spune ce versiuni
  # rulează serverul. Ghidul de instalare cere ștergerea lor, dar amândouă sunt
  # în pachet — deci fiecare actualizare le-ar pune la loc. Ținem minte ce
  # lipsea și lăsăm serverul cum l-am găsit. (Se închid și singure, cu 404, din
  # clipa în care există un administrator activ — dar hotărârea e a ta.)
  SCOASE=()
  for f in setup.php verifica.php; do
    [[ -f "$DIR/api/$f" ]] || SCOASE+=("$f")
  done

  # Cele patru foldere se copiază pe rând. `set -e` oprește scriptul dacă unul
  # pică, dar îl oprește mut — și tocmai aici tăcerea costă cel mai mult: un
  # eșec la al treilea lasă site-ul jumătate nou, jumătate vechi, iar cele două
  # jumătăți nu se potrivesc între ele. Arhiva de la `ARHIVA_COD` e deja făcută;
  # singurul lucru care lipsea era cineva care s-o arate omului la vreme.
  rupt() {
    rosu ""
    rosu "EROARE: n-am putut copia folderul „$1”."
    rosu ""
    rosu "Site-ul e ACUM pe jumătate schimbat: ce s-a copiat înainte de „$1” e nou,"
    rosu "restul a rămas vechi. Nu-l lăsa așa — paginile noi cheamă un api care nu"
    rosu "le cunoaște rutele, iar clientul vede numai refuzuri."
    rosu ""
    if [[ -n "${ARHIVA_COD:-}" && -f "${ARHIVA_COD:-}" ]]; then
      rosu "Pune codul înapoi cum era, dintr-o singură comandă:"
      rosu "  tar -xzf $ARHIVA_COD -C $DIR"
      rosu ""
      rosu "Pe urmă află de ce a picat copierea — de obicei e discul plin (df -h)"
      rosu "sau drepturile pe folder — și rulează scriptul din nou."
    else
      rosu "N-ai copie a codului: ai pornit scriptul cu --fara-copie. Singura cale"
      rosu "înapoi e să construiești pachetul vechi pe calculatorul tău și să-l urci."
    fi
    exit 1
  }

  rsync "${ARG_PUBLIC[@]}"                  "$SURSA/public_html/" "$DIR/public_html/" || rupt public_html
  rsync -a --delete                         "$SURSA/clienti/"     "$DIR/clienti/"     || rupt clienti
  rsync -a --delete                         "$SURSA/lab/"         "$DIR/lab/"         || rupt lab
  # --exclude apără config.php și de copiere, și de ștergere: rsync nu șterge
  # ce a exclus decât dacă i se cere anume, cu --delete-excluded.
  rsync -a --delete --exclude '/config.php' "$SURSA/api/"         "$DIR/api/"         || rupt api
  info "public_html · clienti · lab · api"

  for f in "${SCOASE[@]+"${SCOASE[@]}"}"; do
    rm -f "$DIR/api/$f"
    info "api/$f — era șters, l-am șters din nou"
  done

  mkdir -p "$POZE"

  pas "Drepturi"
  chown -R www-data:www-data "$DIR"
  # X mare: 755 la foldere, 644 la fișiere, dintr-o singură trecere. Cu -exec
  # per fișier ar fi mii de procese pornite degeaba.
  chmod -R u=rwX,go=rX "$DIR"
  chmod 640 "$DIR/api/config.php"
  info "config.php 640 · restul 755/644 · totul pe www-data"
fi

# ------------------------------------------------------------------- Apache --

pas "Apache"
# Pasul ăsta vine DUPĂ ce baza și fișierele s-au schimbat deja. Pe un server
# pus pe nginx, un `mor` aici ar opri scriptul cu roșu pe ecran după ce toată
# lucrarea a reușit — omul ar crede că i-a picat actualizarea și ar începe s-o
# întoarcă. Deci: dacă nu e Apache, se spune ce a mai rămas de făcut.
if ! command -v apache2ctl >/dev/null; then
  galben "  Nu găsesc apache2ctl — serverul ăsta nu merge pe Apache."
  info "Baza și fișierele sunt deja la zi; a mai rămas repornirea, care e a ta:"
  info "    systemctl reload nginx   (și, dacă ai, systemctl reload php8.3-fpm)"
else
  apache2ctl configtest 2>&1 | sed 's/^/  /' || mor "configurația Apache are o problemă — nu repornesc"
  systemctl reload apache2 >/dev/null 2>&1 || service apache2 reload || true
  if ! (systemctl is-active --quiet apache2 2>/dev/null || pgrep -x apache2 >/dev/null); then
    rosu "  Apache nu rulează:"
    systemctl status apache2 --no-pager 2>/dev/null | sed -n '1,8p' | sed 's/^/    /'
    mor "pornește-l și verifică site-ul"
  fi
  info "reîncărcat"

  # Capitolul de memorare în browser din .htaccess-ul paginilor stă sub
  # „<IfModule mod_expires.c>". Când modulul lipsește — iar pe Debian și Ubuntu
  # nu e aprins din oficiu — Apache sare peste tot blocul ÎN TĂCERE: nicio
  # eroare, niciun rând în jurnal, fișierul pare în regulă. Paginile sunt
  # apărate oricum, de blocul de mod_headers de alături, dar pozele, .css și
  # .js rămân fără termen și se cer din nou la fiecare deschidere.
  #
  # E o AVERTIZARE, nu o oprire: publicarea a reușit, nu se întoarce nimic
  # pentru atâta lucru. Aprinderea modulului e o comandă dată o dată în viața
  # serverului, deci o scriem aici gata de copiat.
  if ! apache2ctl -M 2>/dev/null | grep -q expires; then
    galben "  mod_expires nu e aprins pe serverul ăsta."
    info "Paginile se împrospătează oricum (le apără mod_headers), dar pozele,"
    info "„.css” și „.js” se cer din nou la fiecare deschidere — site-ul merge"
    info "mai greu degeaba. Aprinde-l, o singură dată:"
    info "    sudo a2enmod expires && sudo systemctl reload apache2"
  fi
fi

# Sub php-fpm codul vechi rămâne în memoria proceselor până la repornirea lor;
# sub mod_php, reload-ul de mai sus e de ajuns. Îl atingem doar dacă chiar merge.
for s in $(systemctl list-units --type=service --state=running --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}'); do
  systemctl reload "$s" >/dev/null 2>&1 && info "$s reîncărcat" || true
done

# ------------------------------------------------------------- proba de viață --

# O cerere adevărată prin Apache, către API. Nu ne interesează ce răspunde, ci
# că răspunde: un 500 aici înseamnă că PHP a pornit și s-a împiedicat — cel mai
# des de o migrare nerulată — iar asta se află acum, nu de la primul client.
# site_url are voie să fie gol în config.php: codul îl citește atunci din
# prima origine îngăduită (referral_site_url(), api/lib/referral.php:384). Dacă
# proba s-ar lăsa păgubașă aici, singura verificare de după actualizare ar fi
# sărită — în tăcere, cu „Actualizat." verde dedesubt: mai rău decât deloc.
SITE="$(cheie_din_config site_url)"
[[ -n "$SITE" ]] || SITE="$(cheie_din_config allowed_origins.0)"
GAZDA="${SITE#*://}"; GAZDA="${GAZDA%%/*}"; GAZDA="${GAZDA%%:*}"; GAZDA="${GAZDA#www.}"
if [[ -z "$GAZDA" ]]; then
  BISCUIT="$(cheie_din_config cookie_domain)"   # producție: „.goldenpastry.ro"
  GAZDA="${BISCUIT#.}"
fi
if [[ -z "$GAZDA" ]] || ! command -v curl >/dev/null; then
  galben "  Proba de viață a fost sărită — nu știu domeniul din config.php (ori lipsește curl)."
  info "Verifică tu, din browser, acum:"
  info "    https://lab.DOMENIUL-TAU        trebuie să ceară parola"
  # Nu scrie „trebuie 401". `route_me()` (api/routes/auth.php) răspunde 200 cu
  # „user: null” unui vizitator nelogat — asta e purtarea, nu un semn rău.
  # Proba de mai jos primește la fel de bine 2xx și 4xx: ce se caută e un 500.
  info "    https://api.DOMENIUL-TAU/auth/me  orice răspuns, numai 500 nu"
  info "Dacă iese 500:  tail -20 /var/log/apache2/gp-api-error.log"
else
  # --resolve, nu antet „Host:”: pe un server cu certificate, gazda virtuală
  # răspunde la portul 80 cu un 301 către https, iar cererea trebuie să poată
  # merge după el fără să iasă din server. Așa amândouă porturile duc tot la
  # 127.0.0.1, iar -k trece peste faptul că certificatul nu e emis pe o adresă
  # numerică. Fără asta, un server bine pus la punct ar fi arătat ca o problemă.
  COD="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 -L \
         --resolve "api.$GAZDA:80:127.0.0.1" --resolve "api.$GAZDA:443:127.0.0.1" \
         "http://api.$GAZDA/auth/me" || echo 000)"
  case "$COD" in
    2*|4*) info "api.$GAZDA răspunde ($COD)" ;;
    3*)    galben "  api.$GAZDA trimite mai departe ($COD) și nu ajunge nicăieri — verifică gazdele virtuale." ;;
    5*)    rosu "  api.$GAZDA răspunde cu $COD — ceva s-a stricat."
           info "Ultimele rânduri din jurnalul de erori:"
           tail -5 /var/log/apache2/gp-api-error.log 2>/dev/null | sed 's/^/    /' ;;
    *)     galben "  n-am putut întreba api.$GAZDA (cod $COD) — verifică din browser." ;;
  esac
fi

# ------------------------------------------------------------------- raport --

pas "Gata"
verde "Actualizat. api/config.php și pozele au rămas neatinse."
echo
if [[ -n "$DUMP" || -n "$ARHIVA_COD" ]]; then
  echo "  Copiile de dinainte ($CLIPA):"
  [[ -n "$DUMP" ]]       && echo "    baza:   $DUMP"
  [[ -n "$ARHIVA_COD" ]] && echo "    codul:  $ARHIVA_COD"
  echo
  # Comanda de întoarcere a bazei a stat până acum aici singură, ca și cum ar
  # fi „soluția dacă ceva nu merge". Nu e: ea dă baza înapoi la clipa copiei și
  # ȘTERGE tot ce a scris patiseria de atunci — comenzi luate, etape mutate,
  # stoc. Codul, în schimb, se întoarce fără să atingă niciun rând.
  if [[ -n "$ARHIVA_COD" ]]; then
    echo "  Dacă versiunea nouă nu-ți convine, întoarce CODUL — baza rămâne întreagă:"
    echo "    tar xzf $ARHIVA_COD -C $DIR && systemctl reload apache2"
    echo
  fi
  if [[ -n "$DUMP" ]]; then
    echo "  Baza se întoarce numai dacă s-a stricat chiar ea. Atenție: o duce"
    echo "  înapoi la $CLIPA și ȘTERGE tot ce s-a scris de atunci."
    echo "    gunzip -c $DUMP | mysql -u $DB_USER -p --default-character-set=utf8mb4 $DB_NAME"
    echo
  fi
fi
[[ -n "$GAZDA" ]] && echo "  https://lab.$GAZDA"
echo
