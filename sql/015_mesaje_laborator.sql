-- Migrarea 015 — tabla de anunțuri din laborator
--
-- Se rulează o singură dată, după 014. Poate fi rulată de câte ori vrei fără
-- pagubă: `CREATE TABLE IF NOT EXISTS` nu atinge un tabel care există deja, și
-- nu se schimbă niciun rând din alte tabele.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/015_mesaje_laborator.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- PROBLEMA DE REZOLVAT. Ce are managerul de spus laboratorului — „mâine vine
-- făina la șase", „comanda de 200 de cornuri se coace întâi", „joi e sărbătoare"
-- — se spune azi din gură, la schimbul de tură, sau pe o hârtie lipită de
-- perete. Cine intră la trei dimineața n-a fost acolo la schimb, iar hârtia se
-- udă. Ecranul de comenzi stă aprins toată tura, în fața tuturor: acolo e locul
-- anunțului.
--
-- DE CE NU PE ECRANUL ȘOFERULUI. Patronul a cerut anume numai laboratorul.
-- Ruta se citește în mers, dintr-o privire, la volan — un anunț pe ea ar fi un
-- lucru de citit exact când omul n-are voie să citească.
--
-- CINE SCRIE: administratorul, fondatorul și managerul — aceeași listă cu cea
-- a raportului de pontaj (`GP_SHIFTS_ROLES`, api/lib/auth.php), fiindcă sunt
-- aceiași oameni: cei care conduc tura. Nu se naște o a doua listă, tocmai ca
-- să nu se poată depărta de prima.
--
-- CINE IA JOS: cine l-a pus, plus orice administrator sau fondator — și pe ale
-- altora. Alegerea patronului, și are un rost limpede: managerul care pleacă în
-- concediu cu un anunț uitat pe ecran nu blochează tabla până se întoarce.
--
-- DE CE ORE LOCALE, ȘI AICI. Ca la `lab_shifts` din 014: „ține până joi la
-- șase" e ora de pe ceasul de perete al patiseriei, nu un moment absolut. Se
-- scrie cu `now_local()` și se compară cu `now_local()` — amândouă pe același
-- ceas, deci socoteala iese oricare ar fi fusul serverului.
--
-- Prețul e același, și e mic: în noaptea în care ceasul dă înapoi, un anunț pus
-- să se stingă la 03:30 mai stă o oră pe ecran. Pe o tablă de anunțuri, asta nu
-- e o greșeală de citit — e un anunț care a stat puțin mai mult.
--
-- DE CE RÂNDURILE STINSE RĂMÂN ÎN TABEL. Un anunț ieșit din termen nu se mai
-- arată nicăieri, dar nu se șterge: e urma a ceea ce s-a spus laboratorului și
-- când. Într-o patiserie se pun câteva zeci de anunțuri pe an — tabelul nu
-- crește cât să conteze, iar întrebarea „ce le-am spus despre livrarea de
-- marți?" are un răspuns.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS lab_messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- 500 de caractere: cât încape citibil pe un ecran privit de la doi metri, cu
  -- mâinile în aluat. Cine are mai mult de spus are de spus din gură.
  body       VARCHAR(500) NOT NULL,
  -- Ora locală până la care anunțul stă pe ecran. NULL = fără termen, adică
  -- stă până îl ia cineva jos. Amândouă felurile sunt cerute: „mâine vine
  -- făina" se stinge singur, „nu lăsați ușa deschisă" nu.
  until      DATETIME     NULL,
  -- Ora locală la care s-a pus. Se arată pe ecran („pus azi la 05:12"), deci
  -- trebuie să semene cu ceasul de pe perete, nu cu UTC.
  created_at DATETIME     NOT NULL,
  -- Cine l-a pus. SET NULL, nu CASCADE, din același motiv ca `ended_by` din
  -- 014: anunțul rămâne pe ecran și după ce contul celui care l-a scris dispare
  -- — pierde numele, nu înțelesul. Numele se citește prin `JOIN`, nu se copiază
  -- aici: două locuri cu același nume se depărtează la prima redenumire.
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_lab_message_author FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  -- Întrebarea pusă la fiecare rotire a panoului: „ce anunțuri mai țin acum?".
  -- Fără cheia asta ar fi o citire a tuturor anunțurilor puse vreodată, ca să se
  -- afle cele două care mai stau pe ecran.
  KEY ix_lab_message_until (until, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
