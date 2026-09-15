-- Migrarea 014 — pontajul din laborator
--
-- Se rulează o singură dată, după 013. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare pas se uită întâi în information_schema și, dacă are deja ce
-- caută, nu face nimic. Nu șterge și nu rescrie niciun rând existent.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/014_pontaj_laborator.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- Rolul `utilizator_lab` stă în ENUM-ul coloanei `users.role` din migrarea 009,
-- fără să-l poată folosi nimeni: acolo scrie chiar pe față că „pontajul care îi
-- dă un rost vine cu pasul următor". Ăsta e pasul.
--
-- PROBLEMA DE REZOLVAT. Tabletele din laborator se deschid cu UN SINGUR cont,
-- `panou` (`is_system = 1`), cu un cod știut de toată tura — vezi
-- `api/routes/auth.php:321`. E o alegere bună pentru ecranul de comenzi: nimeni
-- nu-și pierde minutele cu parola când are mâinile în aluat. Dar înseamnă că
-- tableta NU ȘTIE cine stă în fața ei, iar un pontaj care nu știe cine a venit
-- nu e pontaj.
--
-- DECI DOUĂ LUCRURI NOI:
--
--   1. `users.lab_pin` — un cod scurt, propriu fiecărui om, pe care tableta îl
--      cere după ce omul apasă pe numele lui. Se ține HASH-uit, ca parola.
--   2. `lab_shifts` — un rând pentru fiecare venire, închis la plecare.
--
-- DE CE HASH ȘI NU PATRU CIFRE SCRISE ÎN RÂND. Un cod de 4 cifre are zece mii
-- de valori: cine capătă o copie a bazei le-ar citi pe toate dintr-o privire,
-- iar de acolo ar putea ponta în locul oricui. Hash-ul nu-l face de neghicit —
-- zece mii se încearcă repede — dar asta o oprește limitarea de încercări de pe
-- server (`login_rate_limited()`, api/lib/auth.php:461), care numără greșelile
-- pe om și pe conexiune. Hash-ul închide cealaltă ușă: pe cea prin care nu se
-- ghicește, ci se citește.
--
-- DE CE ORE LOCALE, NU UTC. Restul aplicației ține sesiunile în UTC, fiindcă
-- acolo contează cât a trecut, nu cât e ceasul. La pontaj e exact pe dos: omul
-- a venit „la șase și zece", iar ora aceea se citește pe un stat de plată, nu
-- se scade din alta. `orders.day` e deja o zi locală, din același motiv. Prețul
-- e noaptea în care se dă ceasul înapoi: o oră apare de două ori pe an de două
-- ori. Pe un stat de plată de brutărie, un rând de lămurit o dată pe an e mai
-- ieftin decât ore care nu seamănă cu ceasul de pe perete în fiecare zi.
--
-- DE CE RÂNDUL RĂMÂNE DESCHIS CÂND OMUL UITĂ SĂ PLECE. `ended_at` rămâne NULL
-- și se vede apăsat în raport. Nimeni nu-i scrie o oră în locul lui: o oră pusă
-- de calculator arată pe hârtie exact ca una adevărată, iar peste o lună nimeni
-- n-ar mai ști care e care. Administratorul o scrie de mână, iar `ended_by` ține
-- minte că n-a scris-o omul — plus un rând în `audit_log`.

SET NAMES utf8mb4;

-- ------------------------------------------ 1. codul de pontaj --

-- CHAR(60), ca `password_hash`: exact lungimea unui hash bcrypt. Rămâne NULL pe
-- conturile care n-au ce ponta — adică pe aproape toate. Un cont fără cod nu
-- apare pe tabletă, deci lista de nume de acolo se face singură, din cine a
-- primit cod, fără o a doua listă de ținut în acord cu prima.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'lab_pin') = 0,
  'ALTER TABLE users ADD COLUMN lab_pin CHAR(60) NULL AFTER password_hash',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------ 2. turele --

CREATE TABLE IF NOT EXISTS lab_shifts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  -- Ziua locală în care a ÎNCEPUT tura. O tură de noapte care trece de miezul
  -- nopții rămâne scrisă pe ziua în care omul a venit la lucru — așa o caută
  -- și el, și cine face statul de plată.
  day        DATE         NOT NULL,
  started_at DATETIME     NOT NULL,
  -- NULL = omul e încă în tură, SAU a uitat să ponteze plecarea. Care din două,
  -- se vede din zi: deschis pe ziua de azi e firesc, deschis pe o zi trecută e
  -- o uitare, și raportul o spune apăsat.
  ended_at   DATETIME     NULL,
  -- Cine a scris ora de plecare, când n-a scris-o omul. NULL înseamnă „a pontat
  -- el însuși" — adică rândul e curat. Rămâne lizibil și după ce contul celui
  -- care a corectat dispare, de-aia SET NULL, nu CASCADE.
  ended_by   INT UNSIGNED NULL,
  -- De ce s-a corectat. Se cere la corectură, ca peste o lună rândul să-și
  -- spună singur povestea.
  note       VARCHAR(255) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_lab_shift_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_lab_shift_ender FOREIGN KEY (ended_by) REFERENCES users(id) ON DELETE SET NULL,
  -- Raportul se citește pe interval de zile, apoi pe om: exact ordinea asta.
  KEY ix_lab_shift_day (day, user_id),
  -- „Are omul ăsta o tură deschisă?" e întrebarea pusă la FIECARE apăsare de pe
  -- tabletă. Fără cheia asta ar fi o citire a tuturor turelor lui, de la
  -- angajare încoace, ca să se afle un singur rând.
  KEY ix_lab_shift_open (user_id, ended_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
