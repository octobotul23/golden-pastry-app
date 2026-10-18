#!/usr/bin/env bash
#
# Golden Pastry — actualizarea unei instalări care merge deja pe VPS.
#
#   sudo bash actualizeaza-vps.sh
#
# Ce face, în ordine: copie de siguranță a bazei, a lui api/config.php și a
# codului, aplică migrările care lipsesc, copiază pachetul din deploy/ peste
# cele patru foldere, pune drepturile la loc, repornește Apache. La urmă, dacă
# serverul n-are niciun program de poștă, pune unul (postfix, numai de trimis).
# Pe drum micșorează, o singură dată, pozele de produs mai mari de 1600 de
# puncte (cele urcate înainte de 2.4.2): pe loc, cu același nume, după ce le
# pune originalele deoparte.
#
# Ce NU atinge niciodată: api/config.php (parola bazei, cheia de criptare,
# codul de instalare). Pozele urcate din Catalog nu se șterg și nu se mută.
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
FARA_POSTA=0
FARA_MICSORARE=0

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
    --fara-posta)   FARA_POSTA=1; shift ;;
    --fara-micsorare) FARA_MICSORARE=1; shift ;;
    -h|--help)
      sed -n '2,19p' "$0" | sed 's/^# \?//'
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
  --fara-posta         nu pune programul de poștă (postfix, numai de trimis)
  --fara-micsorare     nu micșora pozele mai mari de 1600 de puncte
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
  # (la pasul „Fișiere"), iar raportul de la „Gata" nu mai arată calea unui
  # fișier care nu mai e. Amândouă se uitau la adevăr; adevărul era cel stricat.
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

  # Registrul migrărilor: 1 dacă e AL CASEI, 0 dacă nu e niciun tabel cu numele
  # ăsta. Întrebarea e pusă pe COLOANĂ, nu pe nume de tabel — pricina e scrisă
  # la `registruStrain`, mai jos, și e una măsurată pe serverul adevărat.
  registruExista() {
    my -N -B "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
        AND COLUMN_NAME = 'fisier'"
  }

  # Un tabel cu numele nostru, dar fără coloana noastră: 1 = e acolo și e străin.
  #
  # UN NUME NU E O IDENTITATE. Pe 21 septembrie 2026, urcarea rundei 2.4.0 pe
  # serverul de producție a murit cu
  #
  #     ERROR 1054 (42S22) at line 1: Unknown column 'fisier' in 'where clause'
  #
  # fiindcă în bază era un `schema_migrations` făcut cu mâna pe 1 septembrie, cu
  # alte coloane — `filename`, `applied_at`, `note` —, rămășița unei idei
  # părăsite în 2.0.3, scoasă din documente și niciodată scoasă din bază.
  # „Nu se mai face" nu desface ce s-a făcut deja. Paza de atunci întreba
  # `TABLE_NAME = 'schema_migrations'`, i se răspundea „da", și `inRegistru`
  # pica pe un tabel care avea numele bun și coloanele altcuiva.
  #
  # `CREATE TABLE IF NOT EXISTS` din 025 se potrivește tot pe NUME, deci nici el
  # nu îndreaptă nimic: tace și pleacă mai departe, iar coada de registru a
  # fiecărei migrări ar pica apoi pe rând, una câte una, pe aceeași eroare.
  registruStrain() {
    my -N -B "$DB_NAME" -e "SELECT COUNT(*) FROM information_schema.TABLES t
      WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = 'schema_migrations'
        AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c
          WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = 'schema_migrations'
            AND c.COLUMN_NAME = 'fisier')"
  }

  # Coloanele tabelului cu numele ăsta, ca să le vadă omul în mesaj — altfel
  # „nu e al nostru" e o vorbă pe care n-are cum s-o verifice.
  coloaneleRegistrului() {
    my -N -B "$DB_NAME" -e "SELECT GROUP_CONCAT(COLUMN_NAME
        ORDER BY ORDINAL_POSITION SEPARATOR ', ')
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'"
  }

  # Câte rânduri poartă numele ăsta în registru: 0 ori 1.
  #
  # Se cheamă NUMAI cu registrul casei făcut. N-are cum să se apere singură cu un
  # `IF(tabelul există, ..., 0)`: MySQL dezleagă numele tabelelor la citirea
  # cererii, nu la rularea ei, deci un `SELECT ... FROM schema_migrations` pică
  # pe „table doesn't exist" chiar și pe ramura pe care `IF()` n-o alege. De-aia
  # paza stă afară, în bash, nu înăuntru, în SQL. (Cozile migrărilor ocolesc
  # asta altfel: `PREPARE` dintr-un șir clădit cu `IF()` — acolo cererea nici
  # nu se citește dacă nu s-a ales ramura ei.)
  inRegistru() {
    my -N -B "$DB_NAME" -e "SELECT COUNT(*) FROM schema_migrations WHERE fisier = '$1'"
  }

  # STRĂINUL SE VEDE ÎNAINTEA ORICĂREI MIGRĂRI. Dacă în bază e un
  # `schema_migrations` care nu e al casei, actualizarea s-ar opri oricum —
  # coada de registru a primei migrări ar pica —, dar s-ar opri pe o eroare de
  # MySQL pe care patronul n-are cum s-o citească. Aici se oprește cu vorbele
  # casei, cu numele coloanelor sub ochi și cu comanda de scăpare; și se
  # oprește ÎNAINTE de pasul „Fișiere", deci site-ul rămâne întreg pe versiunea
  # care merge.
  if [[ "$(registruStrain || echo 0)" != 0 ]]; then
    rosu "  În baza de date e un tabel „schema_migrations” care nu e al aplicației."
    info "  Are coloanele: $(coloaneleRegistrului || echo '?')"
    info "  Al casei are „fisier” și „aplicata_la”. Ăsta e de altundeva — cel mai"
    info "  probabil o încercare veche, dinainte de hotărârea din 2.0.3."
    echo
    info "  Nu se șterge nimic: i se schimbă numele, și rămâne acolo de citit."
    info "      sudo mysql $DB_NAME -e 'RENAME TABLE schema_migrations TO schema_migrations_vechi'"
    info "  Apoi dai iar comanda asta de actualizare."
    echo
    rosu "  Fișierele NU au fost copiate — serverul merge mai departe cu versiunea veche."
    exit 1
  fi

  APLICATE=0
  SARITE=0

  # Registrul se face ÎNAINTEA buclei, nu la rândul lui în ea. Fișierul se
  # cheamă `025`, deci în ordinea numelui ar rula DUPĂ `003`-`024`: primele
  # douăzeci și două de migrări ar trece pe lângă un tabel care încă nu există,
  # n-ar avea unde-și scrie numele, și registrul s-ar umple abia la
  # actualizarea următoare — o rundă întreagă în care pagina de verificare
  # n-ar ști nimic. Aici rulează o dată, la început, și se sare peste el în
  # buclă; e idempotent, deci rularea asta nu strică nimic la a doua urcare.
  #
  # Lipsa lui din `sql/` — pachet mai vechi decât runda 2.4.0 — nu oprește
  # nimic: migrările își văd de treabă, coada lor nu scrie pe o bază fără
  # registru, iar bilanțul de mai jos se ține atunci după amprentă, ca până
  # acum.
  REGISTRU="$SQL_DIR/025_registru_migrari.sql"
  REG_VIU=0
  REG_NOU=0
  if [[ -e "$REGISTRU" ]]; then
    REG_ERA=0
    # NU ÎN ATRIBUIRE. Sub `set -euo pipefail`, `X="$(f)"` moare fără niciun
    # cuvânt al casei când `f` pică: statutul atribuirii e chiar statutul
    # substituției. Aici era singura chemare de mysql din tot scriptul nelegată
    # de un `if`, și chiar ea a picat pe 21 septembrie 2026 — patronul a văzut
    # un rând brut de MySQL în loc de „Fișierele NU au fost copiate", adică
    # tocmai propoziția care-i spune că site-ul e neatins.
    if [[ "$(registruExista || echo 0)" != 0 ]]; then
      if ! REG_ERA="$(inRegistru 025_registru_migrari.sql 2>/tmp/gp-mig.err)"; then
        rosu "  Registrul migrărilor n-a putut fi citit:"
        sed -n '1,8p' /tmp/gp-mig.err | sed 's/^/    /'
        echo
        rosu "  Fișierele NU au fost copiate — serverul merge mai departe cu versiunea veche."
        exit 1
      fi
    fi
    if ! my "$DB_NAME" < "$REGISTRU" >/dev/null 2>/tmp/gp-mig.err; then
      rosu "  Registrul migrărilor n-a putut fi făcut:"
      sed -n '1,8p' /tmp/gp-mig.err | sed 's/^/    /'
      echo
      rosu "  Fișierele NU au fost copiate — serverul merge mai departe cu versiunea veche."
      exit 1
    fi
    REG_VIU=1
    if [[ "$REG_ERA" != 0 ]]; then
      info "025_registru_migrari.sql — era deja aplicată"
      SARITE=$((SARITE + 1))
    else
      # Registrul s-a NĂSCUT la rularea asta, deci e gol. Din clipa asta,
      # pentru fiecare migrare de mai jos „nu era în registru înainte, e
      # după" e adevărat — și despre cele intrate acum, și despre cele
      # intrate acum o lună. Semnul se ține ca să nu se citească 0 → 1 drept
      # dovadă la prima rulare; v. hotărârea de mai jos.
      REG_NOU=1
      verde "  025_registru_migrari.sql — aplicată acum (registrul migrărilor)"
      APLICATE=$((APLICATE + 1))
    fi
  else
    galben "  sql/025_registru_migrari.sql lipsește din pachet."
    info "  Migrările nu lasă urmă scrisă; bilanțul de mai jos se ține după amprenta schemei."
  fi

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

    # Registrul a rulat deja, mai sus. Săritura se face pe CALEA ținută în
    # `$REGISTRU`, nu pe un nume scris a doua oară aici: numele fișierului stă
    # într-un singur loc, deci cele două nu pot ieși din pas.
    if [[ "$f" == "$REGISTRU" ]]; then
      continue
    fi

    INAINTE="$(amprenta)"
    # `?` înseamnă „registrul n-a putut răspunde". Nu e o oprire: hotărârea
    # cade atunci pe amprentă, ca până acum.
    REG_INAINTE='?'
    if (( REG_VIU )); then
      REG_INAINTE="$(inRegistru "$NUME" 2>/dev/null || echo '?')"
    fi
    if ! my "$DB_NAME" < "$f" >/dev/null 2>/tmp/gp-mig.err; then
      rosu "  $NUME a picat:"
      sed -n '1,8p' /tmp/gp-mig.err | sed 's/^/    /'
      echo
      rosu "  Fișierele NU au fost copiate — serverul merge mai departe cu versiunea veche."
      if [[ -n "$DUMP" ]]; then
        info "Dacă baza a rămas pe jumătate schimbată, se pune la loc așa:"
        info "    gunzip -c $DUMP | sudo mysql --default-character-set=utf8mb4 $DB_NAME"
      fi
      exit 1
    fi
    DUPA="$(amprenta)"
    REG_DUPA='?'
    if (( REG_VIU )); then
      REG_DUPA="$(inRegistru "$NUME" 2>/dev/null || echo '?')"
    fi

    # Cine hotărăște dacă migrarea a intrat ACUM: registrul, când știe de ea;
    # amprenta, când nu.
    #
    # Amprenta e o ghicitoare, și se înșală tocmai unde doare. `017` nu atinge
    # DELOC schema — scrie un rând în `settings` și atât —, deci cele trei cifre
    # nu se mișcă niciodată pentru ea. Măsurat, nu bănuit: 21·229·2546 înainte,
    # 21·229·2546 după. Munca se făcea, dar raportul spunea „era deja aplicată"
    # CHIAR ȘI la prima rulare, exact despre migrarea care repară comisioanele
    # de 0,00 RON. Registrul nu ghicește: rândul apare atunci când migrarea
    # trece, fiindcă și-l scrie ea însăși, la coadă.
    #
    # „Registrul nu știe de ea" are două pricini cinstite — un `sql/` mai vechi
    # decât `025`, ori o migrare scrisă fără coada de registru — și amândouă
    # merită socoteala veche, nu o ghicitoare nouă.
    #
    # A treia pricină e chiar NAȘTEREA registrului, și e cea care înșală cel mai
    # frumos: la rularea în care intră 025, tabelul e gol, deci FIECARE migrare
    # de sub el trece de la 0 la 1 și s-ar raporta „aplicată acum" — inclusiv
    # cele intrate acum o lună. Nu e o pagubă (migrările se păzesc singure prin
    # `information_schema`), e un raport prea sigur pe el. La nașterea unui
    # registru, „a intrat acum" și „era deja aici" chiar NU se pot deosebi: n-are
    # cine să-și amintească. De-aia `REG_NOU` taie ramura registrului la prima
    # rulare și lasă amprenta să hotărască, ca înainte de 025.
    if [[ "$REG_INAINTE" == 0 && "$REG_DUPA" == 1 && "$REG_NOU" == 0 ]]; then
      ERA_DEJA=0
    elif [[ "$REG_INAINTE" == 1 ]]; then
      ERA_DEJA=1
    elif [[ "$INAINTE" == "$DUPA" ]]; then
      ERA_DEJA=1
    else
      ERA_DEJA=0
    fi

    if (( ERA_DEJA )); then
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
  if (( REG_NOU )); then
    info "  Registrul migrărilor s-a făcut chiar acum, deci nu poate spune ce era"
    info "  aplicat înaintea lui: bilanțul de mai sus e ținut după amprenta schemei,"
    info "  ca până acum. De la actualizarea următoare, numărătoarea e a registrului."
  fi

  # 017 e singura migrare din tot lanțul care nu atinge DELOC schema: scrie un
  # rând în `settings` și atât. Până în runda 2.4.0, bilanțul de mai sus o dădea
  # „era deja aplicată" CHIAR ȘI la prima rulare, fiindcă se ținea numai după
  # amprentă, iar pentru ea cele trei cifre nu se mișcă niciodată — măsurat, nu
  # bănuit: 21·229·2546 înainte, 21·229·2546 după. Registrul a îndreptat
  # RAPORTUL: acum se vede când intră.
  #
  # Martorul de mai jos rămâne, și nu de prisos: registrul spune că migrarea a
  # trecut, dar nu spune CU CE a rămas setarea. 017 îndreaptă procentul la 5
  # numai acolo unde nu l-a atins omul, deci un zero pus de patron rămâne zero,
  # pe bună dreptate — iar un zero pus de patron și un zero rămas de la 008
  # arată la fel din registru. De-aceea procentul se citește aici din bază și se
  # spune CU VALOAREA LUI și cu cine a scris-o.
  #
  # Golul are însă două pricini, și nu se spun la fel. Dacă mysql răspunde fără
  # niciun rând, rândul lipsește: 017 n-a intrat. Dacă mysql pică, nu știm
  # nimic — iar „n-a intrat" ar trimite omul după o migrare care poate e la
  # locul ei. De-aia codul de ieșire se ține minte, nu se îneacă într-un
  # `|| echo ""`, cum se îneca până acum: atunci martorul tăcea la amândouă.
  PROC="$(my -N -B "$DB_NAME" -e \
    "SELECT COALESCE(TRIM(value), ''), IF(updated_by IS NULL, 'casa', 'om')
       FROM settings WHERE setting_key = 'referral_rate_default'" 2>/tmp/gp-martor.err)" \
    && PROC_CITIT=1 || PROC_CITIT=0
  if (( ! PROC_CITIT )); then
    # O citire picată e o AVERTIZARE, nu o oprire: migrările de mai sus au
    # trecut deja, iar un `mor` aici ar lăsa fișierele necopiate pentru o
    # întrebare pusă după lucru.
    galben "  N-am putut citi procentul de recomandare din bază:"
    sed -n '1,4p' /tmp/gp-martor.err 2>/dev/null | sed 's/^/    /' || true
    info "Migrările de mai sus au trecut; a picat numai citirea de după ele, deci"
    info "nu știu ce procent a rămas. Uită-te în Setări → General."
  elif [[ -z "$PROC" ]]; then
    # Rândul nu e în tabel. După o buclă de migrări reușită asta înseamnă că 017
    # n-a fost printre fișiere: `INSERT IGNORE`-ul ei l-ar fi pus. Urmarea nu se
    # vede de nicăieri: aplicația socotește cu procentul din cod
    # (`referral_default_rate()`, api/lib/referral.php), iar din Setări nu se
    # poate pune altul, fiindcă salvarea scrie numai peste rânduri care există
    # și refuză cheia fără rând (`unknown_setting`).
    galben "  Procentul de recomandare n-are rând în Setări — migrarea 017 n-a intrat."
    info "Până intră, aplicația socotește cu procentul scris în cod (5%), iar"
    info "„Procent de recomandare” din Setări → General nu se poate salva."
    info "Caută mai sus rândul „017_procent_recomandare.sql”: dacă lipsește, folderul"
    info "sql/ urcat e mai vechi decât pachetul."
  else
    # Tăiat pe TAB, nu cu `read`: valoarea poate fi goală, iar `read VAL CINE`
    # ar muta atunci „casa" în VAL și raportul ar scrie „Procentul: casa%".
    VAL="${PROC%%$'\t'*}"
    CINE="${PROC##*$'\t'}"
    if [[ -z "$VAL" ]]; then
      galben "  Procentul de recomandare e gol — recomandările nu aduc nimic."
      info "Îl pui din Setări → General."
    elif [[ "$CINE" == "casa" && "$VAL" =~ ^0+([.,]0+)?$ ]]; then
      # Un zero pe care nu l-a hotărât nimeni e semănătura lui 008. 017 îl urcă
      # la 5 la fiecare rulare (`UPDATE … WHERE updated_by IS NULL`), deci un
      # zero al casei rămas DUPĂ migrări spune că 017 n-a fost printre fișiere.
      # Fără ramura asta el cădea pe cea de dedesubt și ieșea VERDE, „0% — pus
      # acum": tocmai zeroul care naște comisioanele de 0,00 RON, lăudat.
      galben "  Procentul de recomandare e 0%, semănat de casă — migrarea 017 n-a intrat."
      info "Cât timp e zero, fiecare comision nou se naște 0,00 RON și rămâne așa pe"
      info "veci. Caută mai sus rândul „017_procent_recomandare.sql”: dacă lipsește,"
      info "folderul sql/ urcat e mai vechi decât pachetul."
    elif [[ "$CINE" == "casa" ]]; then
      # Fără „pus acum": martorul numai citește, iar la orice urcare de după
      # prima `017` n-are ce urca — și lista migrărilor de mai sus o dă „era
      # deja aplicată". Rândul spune ce știe: valoarea, și a cui e.
      verde "  Procentul de recomandare: $VAL% — semănat de casă, nu l-a hotărât nimeni din Setări."
    elif [[ "$VAL" =~ ^0+([.,]0+)?$ ]]; then
      # Un zero hotărât de om rămâne zero — migrarea nu-i schimbă părerea
      # patronului în somn. Dar nici nu tace despre urmarea lui: procentul se
      # îngheață la nașterea rândului de comision, deci fiecare comision făcut
      # cât timp e zero rămâne 0,00 RON pe veci, oricât s-ar pune după aceea.
      galben "  Procentul de recomandare e 0% — hotărât de tine, deci l-am lăsat."
      info "Cât timp e zero, fiecare comision nou se naște 0,00 RON și rămâne"
      info "așa pe veci: procentul se îngheață la nașterea rândului. Îl schimbi"
      info "din Setări → General."
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
  # nimic nicăieri (`settings_save()` trecea atunci tăcut peste cheile fără
  # rând; azi le refuză pe nume), deci adresa firmei era în fapt scrisă în cod. Un raport care spune
  # „era deja aplicată" despre tocmai reparația aceea îl lasă pe om să creadă
  # că nici acum nu se poate scrie.
  #
  # Se citesc deci amândouă și se spun CU VALOAREA LOR, ca procentul de mai
  # sus: rândul însuși e martorul, nu urma lui într-o cifră de schemă.
  #
  # Și aici golul are două pricini, ca la procent: niciun rând înseamnă că 018
  # n-a intrat, mysql picat înseamnă că nu știm. Până acum amândouă ieșeau
  # „migrarea 018 n-a intrat", iar omul căuta o migrare care era la locul ei.
  FIRMA="$(my -N -B "$DB_NAME" -e \
    "SELECT setting_key, COALESCE(TRIM(value), ''), IF(updated_by IS NULL, 'casa', 'om')
       FROM settings WHERE setting_key IN ('company_address', 'company_hours')
      ORDER BY setting_key" 2>/tmp/gp-martor.err)" \
    && FIRMA_CITITA=1 || FIRMA_CITITA=0
  if (( ! FIRMA_CITITA )); then
    galben "  N-am putut citi adresa și programul din bază:"
    sed -n '1,4p' /tmp/gp-martor.err 2>/dev/null | sed 's/^/    /' || true
    info "Migrările de mai sus au trecut; a picat numai citirea de după ele, deci"
    info "nu știu ce scrie în ele. Uită-te în Setări → General."
  elif [[ -z "$FIRMA" ]]; then
    # Nici măcar rândurile goale n-au ajuns în tabel: 018 n-a intrat, iar
    # casetele „Adresa publică” și „Program de lucru” din Setări stau închise.
    galben "  Adresa și programul n-au rând în Setări — migrarea 018 n-a intrat."
    info "Până intră, casetele „Adresa publică” și „Program de lucru” din Setări"
    info "stau închise, iar site-ul arată adresa și programul scrise în cod."
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
      #
      # Și aici fără „acum", ca la procent — iar aici „acum" avea și mai puțin
      # temei: `018` e un `INSERT IGNORE` și atât, deci peste un rând care
      # există nu scrie nimic, niciodată. Martorul spune a cui e valoarea, nu ce
      # a făcut rularea.
      if [[ "$CHEIE" == "company_address" ]]; then
        CE="Adresa publică"; GOL="e goală"; PUS="semănată de casă, n-a scris-o nimeni din Setări"
        LASAT="scrisă de tine, am lăsat-o în pace"; PUNE="O pui"
      else
        CE="Programul de lucru"; GOL="e gol"; PUS="semănat de casă, nu l-a scris nimeni din Setări"
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
  #
  # Și aici codul de ieșire se ține minte, ca la procent și la adresă. `COUNT(*)`
  # întoarce mereu un rând, deci golul de după vechiul `|| echo ""` nu putea
  # însemna decât o citire picată — iar ea tăcea, exact ca „niciun cont de
  # recunoscut". Adică tocmai când nu știa nimic, scriptul lăsa impresia că a
  # verificat și n-a găsit.
  NERECUNOSCUTE="$(my -N -B "$DB_NAME" -e \
    "SELECT COUNT(*) FROM users WHERE self_registered = 1 AND vouched_at IS NULL" 2>/tmp/gp-martor.err)" \
    && CONTURI_CITITE=1 || CONTURI_CITITE=0
  if (( ! CONTURI_CITITE )); then
    galben "  N-am putut citi câte conturi făcute pe site așteaptă să le recunoști:"
    sed -n '1,4p' /tmp/gp-martor.err 2>/dev/null | sed 's/^/    /' || true
    info "Migrările de mai sus au trecut; a picat numai citirea de după ele, deci"
    info "nu știu dacă e vreunul. Uită-te în Conturi, după „Recunosc clientul”."
  elif [[ "$NERECUNOSCUTE" =~ ^[0-9]+$ ]] && (( NERECUNOSCUTE > 0 )); then
    # Acordul, ca la `numar()`: „1 cont … singur", „3 conturi … singure". O
    # singură formă scria „1 cont făcut pe site nu se mai leagă singure".
    if (( NERECUNOSCUTE == 1 )); then SINGUR="singur"; PE_EL="Îl"
    else SINGUR="singure"; PE_EL="Le"; fi
    galben "  $(numar "$NERECUNOSCUTE" "cont făcut pe site" "conturi făcute pe site") nu se mai leagă $SINGUR la comenzile luate la telefon."
    info "$PE_EL recunoști din Conturi, cu „Recunosc clientul”. Până atunci, clientul"
    info "își vede în cont numai comenzile date chiar de pe site."
  fi
fi

# ------------------------------------------------------------------ fișiere --

# Ce lasă în urmă pasul „Pozele mari", pentru raportul de la „Gata". Se pun aici,
# nu în pas: la --doar-baza pasul nu rulează, iar `set -u` ar opri raportul.
POZE_COPIE=""
POZE_FACUTE=""

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
    rosu "restul a rămas vechi. API-ul merge primul tocmai ca jumătatea asta să fie"
    rosu "cea care ține: paginile vechi nu-i cer nimic din ce-a apărut nou, deci"
    rosu "clientul comandă și laboratorul mută mai departe. Nu lăsa totuși casa"
    rosu "așa — rămân ecrane din două versiuni, iar ce vede unul nu se potrivește"
    rosu "cu ce vede altul."
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

  # ORDINEA CELOR PATRU E O HOTĂRÂRE, nu felul în care au ieșit din listă:
  # API-ul merge PRIMUL, paginile după el. E aceeași socoteală ca la pasul
  # „Migrări" de mai sus — întâi se schimbă cel de care depinde celălalt.
  #
  # Între primul rsync și ultimul, casa are câteva zeci de secunde două vârste.
  # Cu paginile copiate întâi, fereastra aceea era o pană adevărată, nu o
  # subțiere: paginile din 2.1.1 trimit la PATCH /orders câmpul `from` — etapa
  # pe care o avea comanda pe ecranul de pe care s-a apăsat — iar API-ul vechi
  # nu-l cunoaște. Din contul de tură el nu curăță cererea de câmpul străin, ci
  # o oprește întreagă, cu 403 `staff_field_denied`, deci tableta din laborator
  # nu mai putea muta NIMIC până se termina copierea.
  #
  # Invers, fereastra e goală: API-ul nou nu cere nimic nou de la pagini. `from`
  # e facultativ acolo — „Lipsa lui se poartă ca până acum", vezi
  # ORDER_FIELDS_PRECONDITION în api/routes/orders.php — deci paginile vechi,
  # care nu-l trimit, merg pe el neschimbate.
  #
  # --exclude apără config.php și de copiere, și de ștergere: rsync nu șterge
  # ce a exclus decât dacă i se cere anume, cu --delete-excluded.
  rsync -a --delete --exclude '/config.php' "$SURSA/api/"         "$DIR/api/"         || rupt api
  rsync "${ARG_PUBLIC[@]}"                  "$SURSA/public_html/" "$DIR/public_html/" || rupt public_html
  rsync -a --delete                         "$SURSA/clienti/"     "$DIR/clienti/"     || rupt clienti
  rsync -a --delete                         "$SURSA/lab/"         "$DIR/lab/"         || rupt lab
  info "api · public_html · clienti · lab"

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

  pas "Ceasul plăților"
  # Raportul de încasări și amintirile de scadență (2.7.0) nu le cere nicio
  # pagină: le pornește serverul, din cinci în cinci minute. E primul lucru din
  # aplicație care rulează fără ca cineva să fi deschis site-ul, deci fără
  # rândul de mai jos nu pleacă niciun raport, oricât de bine ar fi pus chatul
  # în „Încasări" — și nimic nu spune de ce.
  #
  # Stă AICI, în ramura fișierelor (deci și la --doar-fisiere), după drepturi:
  # rândul cheamă un fișier pe care abia copierea de mai sus l-a adus. La
  # --doar-baza nu se atinge — acolo n-a venit niciun fișier nou.
  #
  # `flock -n`: dacă o rulare ține mai mult de cinci minute (Telegram care nu
  # răspunde), următoarea nu pornește peste ea. `logger`: ce tipărește ceasul
  # se citește cu `journalctl -t gp-plati`; fără el, cron ar încerca să trimită
  # ieșirea pe e-mail, unui server care n-are poștă.
  #
  # Se scrie NUMAI dacă diferă: cron recitește fișierul când i se schimbă data,
  # iar un fișier rescris la fiecare actualizare ar arăta, în `ls -l`, ca umblat
  # de fiecare dată. Întâi lângă el și apoi mutat, ca cron să nu prindă niciodată
  # un rând scris pe jumătate (numele cu punct nu-l citește: cron sare peste
  # fișierele din cron.d care au punct în nume).
  CRON_FISIER="/etc/cron.d/goldenpastry-plati"
  CRON_TEXT="# Golden Pastry: raportul de încasări și amintirile de scadență, pe Telegram.
# Scris de scripturile de instalare și de actualizare; nu se editează de mână.
*/5 * * * * www-data flock -n /run/lock/gp-plati.lock php $DIR/api/cron/plati.php 2>&1 | logger -t gp-plati"
  if [[ ! "$DIR" =~ ^[A-Za-z0-9_./-]+$ ]]; then
    # Un spațiu ori un `%` în cale ar fi citite de cron altfel decât de shell.
    galben "  Calea „$DIR” are semne pe care cron le citește altfel. Ceasul NU a fost pus;"
    info "rapoartele de încasări nu vor pleca până nu e pus de mână."
  elif [[ ! -f "$DIR/api/cron/plati.php" ]]; then
    galben "  Pachetul nu are api/cron/plati.php (e mai vechi de 2.7.0?). Ceasul NU a fost pus."
  elif [[ ! -d /etc/cron.d ]]; then
    galben "  Serverul nu are cron (lipsește /etc/cron.d). Ceasul NU a fost pus."
    info "Pune-l cu:  apt-get install -y cron   și rulează din nou actualizarea cu --doar-fisiere."
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
  # alte pachete (php își pune acolo curățenia sesiunilor), deci întrebarea de
  # mai sus trece, rândul se scrie, scriptul zice „scris" — și nu-l citește
  # nimeni, niciodată. Aici e locul cu primejdia cea mare: un server instalat
  # ÎNAINTE de 2.7.0 n-a primit `cron` de la instalare (lista de pachete îl are
  # abia de atunci). De aceea se întreabă de PROGRAM și de SERVICIU, la fiecare
  # actualizare cu fișiere, și se spune pe față când rândul e pus degeaba.
  # Pentru cron, actualizarea NU instalează pachetul: spune comanda. (Singurul
  # pachet pe care îl pune ea e programul de poștă, la coadă, și se poate sări.)
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

  # >>> poze-mari >>>
  pas "Pozele mari"
  # Pozele urcate înainte de 2.4.2 n-au trecut prin micșorare. Pe goldenpastry.ro
  # erau 22, de câte 1792×2390 de puncte și 2–3 MB, iar /catalog le cerea pe
  # toate deodată: 55 MB pe un telefon, poza unui produs abia după 21 de secunde
  # pe 4G slab (măsurat pe 17 oct. 2026). Urcarea le micșorează pe cele noi de
  # atunci; pasul ăsta le trece o dată și pe cele vechi prin ACEEAȘI micșorare
  # (api/lib/poze.php), pe loc și cu același nume — deci baza nu se atinge.
  #
  # Ordinea e paza. Unealta spune întâi CE ar atinge (--lista), root copiază
  # exact pozele acelea deoparte, abia apoi se micșorează. Fără copie nu se
  # micșorează nimic: nici la --fara-copie, nici când copierea pică la mijloc.
  # A doua oară nu mai e nimic de făcut — o poză micșorată are 1600 de puncte.
  #
  # Unealta rulează ca www-data, nu ca root: folderul pozelor îl poate umple
  # oricine scrie ca serverul web, iar un root care scrie acolo poate fi dus,
  # de o legătură simbolică pusă dinadins, oriunde pe disc. Copia o face root,
  # cu `cp -a`, care copiază o legătură ca legătură, fără s-o urmeze.
  MICSOREAZA="$DIR/api/cron/micsoreaza-poze.php"
  if (( FARA_MICSORARE )); then
    info "Sărit (--fara-micsorare)."
  elif [[ ! -f "$MICSOREAZA" ]]; then
    galben "  Pachetul nu are api/cron/micsoreaza-poze.php (e mai vechi de 2.7.6?). Pozele n-au fost atinse."
  elif ! command -v runuser >/dev/null 2>&1; then
    galben "  Lipsește runuser (pachetul util-linux), iar ca root nu micșorez. Pozele n-au fost atinse."
  elif ! POZE_LISTA="$(runuser -u www-data -- php "$MICSOREAZA" --poze="$POZE" --lista 2>/tmp/gp-poze.err)"; then
    galben "  N-am putut afla ce poze sunt de micșorat ($POZE):"
    { cat /tmp/gp-poze.err; printf '%s\n' "$POZE_LISTA"; } 2>/dev/null | grep . | sed -n '1,4p' | sed 's/^/    /' || true
    info "Pozele n-au fost atinse; site-ul merge cu ele cum sunt."
  elif [[ -z "$POZE_LISTA" ]]; then
    info "Nicio poză mai mare de 1600 de puncte — nimic de micșorat."
  else
    mapfile -t POZE_NUME <<< "$POZE_LISTA"
    POZE_N=${#POZE_NUME[@]}
    if (( FARA_COPIE )); then
      galben "  $(numar "$POZE_N" "poză e mai mare" "poze sunt mai mari") de 1600 de puncte, dar fără copie de siguranță (--fara-copie) nu le micșorez."
      info "Rulează actualizarea o dată fără --fara-copie: le pune deoparte, apoi le micșorează."
    else
      POZE_COPIE="$COPII/poze-$CLIPA"
      mkdir -p "$POZE_COPIE"
      chmod 700 "$COPII" "$POZE_COPIE"
      # Fiecare nume din listă trebuie să fie un fișier adevărat din folder. Un
      # rând care nu e (un avertisment de PHP ajuns pe ieșire, o poză ștearsă
      # între timp) înseamnă o listă în care nu se poate avea încredere.
      COPIATE=0
      for n in "${POZE_NUME[@]}"; do
        [[ -f "$POZE/$n" && ! -L "$POZE/$n" ]] || break
        cp -a -- "$POZE/$n" "$POZE_COPIE/" 2>/tmp/gp-poze.err || break
        COPIATE=$((COPIATE + 1))
      done
      if (( COPIATE < POZE_N )); then
        galben "  N-am putut pune deoparte „${POZE_NUME[$COPIATE]}” (discul plin? df -h). Fără copie nu micșorez nimic."
        sed -n '1,2p' /tmp/gp-poze.err 2>/dev/null | sed 's/^/    /' || true
        info "Pozele n-au fost atinse. Ce apucasem să copiez stă în $POZE_COPIE"
        POZE_COPIE=""
      else
        info "Originalele ($(numar "$POZE_N" poză poze), $(du -sh "$POZE_COPIE" | cut -f1)): $POZE_COPIE"
        POZE_COD=0
        POZE_IESIRE="$(runuser -u www-data -- php "$MICSOREAZA" --poze="$POZE" 2>&1)" || POZE_COD=$?
        printf '%s\n' "$POZE_IESIRE" | sed 's/^/  /'
        POZE_FACUTE="$(printf '%s\n' "$POZE_IESIRE" | sed -n 's/^Micșorate: \([0-9][0-9]*\) din .*/\1/p' | tail -n 1)"
        if [[ "$POZE_FACUTE" == "0" ]]; then
          # Nu s-a înlocuit nicio poză (una se înlocuiește numai când micșorarea
          # a reușit), deci copia e geamăna celor de pe site. Rămasă, s-ar aduna
          # câte una la fiecare actualizare: o poză pe care micșorarea n-o face
          # mai ușoară e găsită „mare" de fiecare dată.
          rm -rf -- "$POZE_COPIE"
          POZE_COPIE=""
        fi
        if (( POZE_COD != 0 )) && [[ -z "$POZE_FACUTE" ]]; then
          # Unealta s-a oprit înaintea rândului ei de socoteală (PHP căzut la
          # mijloc): nu se știe câte poze au apucat să fie înlocuite, deci nu
          # se pomenește de rânduri „neatinsă” care poate nici nu există, iar
          # copia rămâne.
          galben "  Micșorarea s-a oprit înainte de capăt (vezi rândurile de mai sus). Originalele pozelor listate sunt în $POZE_COPIE"
        elif (( POZE_COD != 0 )); then
          galben "  Unele poze n-au putut fi micșorate (rândurile „neatinsă” de mai sus). Au rămas cum erau; site-ul merge cu ele."
        fi
      fi
    fi
  fi
  # <<< poze-mari <<<
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

# Stă AICI, după proba de viață: are nevoie de domeniul citit acolo (GAZDA), iar
# o instalare de pachet care se împiedică nu trebuie să poată sta în calea a
# nimic din ce contează. La --doar-baza se sare: acolo se atinge numai baza.
pas "Programul de poștă"
if [[ "$DOAR" == "baza" ]]; then
  info "Sărit (--doar-baza)."
else
  POSTA_DOMENIU="$GAZDA"
  pune_posta
fi

# ------------------------------------------------------------------- raport --

pas "Gata"
# Rândul verde spunea „pozele au rămas neatinse" — adevărat până la 2.7.6. Acum
# spune ce s-a întâmplat: numărul vine din rândul de la coadă al uneltei, iar
# când ea s-a oprit înainte de el, nu se pretinde nicio cifră.
if [[ "$POZE_FACUTE" =~ ^[0-9]+$ ]] && (( POZE_FACUTE > 0 )); then
  verde "Actualizat. api/config.php a rămas neatins; $(numar "$POZE_FACUTE" "poză mare a fost micșorată" "poze mari au fost micșorate"), cu originalele puse deoparte."
elif [[ -n "$POZE_COPIE" ]]; then
  verde "Actualizat. api/config.php a rămas neatins; micșorarea pozelor s-a oprit la mijloc, cu originalele puse deoparte."
else
  verde "Actualizat. api/config.php și pozele au rămas neatinse."
fi
echo
if [[ -n "$DUMP" || -n "$ARHIVA_COD" || -n "$POZE_COPIE" ]]; then
  echo "  Copiile de dinainte ($CLIPA):"
  [[ -n "$DUMP" ]]       && echo "    baza:   $DUMP"
  [[ -n "$ARHIVA_COD" ]] && echo "    codul:  $ARHIVA_COD"
  [[ -n "$POZE_COPIE" ]] && echo "    pozele: $POZE_COPIE  (originalele celor micșorate)"
  echo
  # Pozele se întorc fără să atingă baza: au același nume. Arhiva codului nu le
  # are (stau în afara ei), deci întoarcerea codului nu le dă înapoi.
  #
  # Se copiază FIȘIERELE (`/*`), nu folderul (`/.`). `cp -a copie/. products/`
  # îi dă și lui products/ ce are folderul copiei — stăpânul root și drepturile
  # 700 —, iar Apache, care citește ca www-data, n-ar mai fi putut arăta nicio
  # poză, nici urca alta. Copia n-are fișiere cu punct în față, deci `*` le
  # prinde pe toate.
  if [[ -n "$POZE_COPIE" ]]; then
    echo "  Dacă vrei pozele mari înapoi, cu același nume — baza nu se atinge:"
    echo "    cp -a $POZE_COPIE/* $POZE/"
    echo
  fi
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
    echo "    gunzip -c $DUMP | sudo mysql --default-character-set=utf8mb4 $DB_NAME"
    echo
  fi
fi
[[ -n "$GAZDA" ]] && echo "  https://lab.$GAZDA"
echo
