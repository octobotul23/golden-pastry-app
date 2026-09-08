-- Migrarea 007 — roluri noi și sistemul de recomandări (referral)
--
-- Se rulează o singură dată, după 006. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare pas se uită întâi în information_schema și, dacă are deja ce
-- caută, nu face nimic. Nu atinge, nu rescrie și nu șterge niciun rând
-- existent: toți pașii sunt ADITIVI.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/007_update_roles_referral.sql
-- Rulare pe cPanel:         phpMyAdmin → baza de date → Import → acest fișier
--
-- Ce aduce:
--   1. rolurile „manager" și „agent_vanzari", pe lângă cele trei existente
--   2. codul de recomandare al fiecărui cont, derivat din prenume
--   3. legătura „cine pe cine a adus", ca auto-referință în tabelul users
--
-- IMPORTANT despre pasul 1: valorile noi se adaugă la SFÂRȘITUL listei ENUM.
-- MySQL nu ține în rând textul rolului, ci poziția lui în listă. O valoare
-- strecurată la mijloc ar deplasa pozițiile tuturor celor de după ea, iar
-- conturile existente și-ar schimba rolul în tăcere — un angajat s-ar trezi
-- client, un client s-ar trezi altceva. La coadă, pozițiile 1-3 rămân exact
-- cum erau, iar rândurile de azi nu se clintesc.

SET NAMES utf8mb4;

-- ------------------------------------------------ 1. rolurile noi --

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role' AND COLUMN_TYPE LIKE '%agent_vanzari%') = 0,
  'ALTER TABLE users MODIFY role
     ENUM(''admin'',''angajat'',''client'',''manager'',''agent_vanzari'')
     NOT NULL DEFAULT ''client''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------- 2. coloanele de referral --

-- Codul cu care contul îi aduce pe alții. Derivat din prenume („Ana Maria" →
-- „ana"), cu o cifră la coadă când prenumele e deja luat („ion", „ion1"…).
-- Rămâne NULL pe conturile care încă n-au primit unul: NULL înseamnă „fără
-- cod", nu „cod gol", iar indexul unic de mai jos acceptă oricâte NULL-uri.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'referral_code') = 0,
  'ALTER TABLE users ADD COLUMN referral_code VARCHAR(64) NULL AFTER customer_name',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cine a adus contul. Auto-referință: un utilizator arată către alt utilizator.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'referred_by') = 0,
  'ALTER TABLE users ADD COLUMN referred_by INT UNSIGNED NULL AFTER referral_code',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Când. Se scrie o singură dată, odată cu legătura; nu se mai atinge după.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'referred_at') = 0,
  'ALTER TABLE users ADD COLUMN referred_at DATETIME NULL AFTER referred_by',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------- 3. indecși și chei străine --

-- Codul trebuie să ducă la un singur cont, altfel „?ref=ana" n-ar ști pe cine
-- să premieze. Indexul e și apărarea împotriva cursei: două înscrieri paralele
-- ale unei „Ana" calculează amândouă „ana", dar numai una îl poate scrie —
-- cealaltă primește 1062, iar PHP-ul trece la „ana1" (vezi api/lib/referral.php).
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'uq_users_referral_code') = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_referral_code (referral_code)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Adresa de e-mail, unică la nivel de bază de date.
--
-- Până acum regula exista numai în PHP (check_user_email din
-- api/routes/users.php): un SELECT, apoi un INSERT, cu loc între ele pentru o a
-- doua cerere. Cât timp conturile le făcea doar administratorul, cursa era
-- teoretică. De când înscrierea e publică, nu mai e: două formulare trimise în
-- aceeași clipă cu aceeași adresă treceau amândouă, iar „Ți-ai uitat parola?"
-- rămânea fără răspuns pentru amândoi (vezi comentariul din api/routes/auth.php).
--
-- ATENȚIE: indexul se adaugă NUMAI dacă în baza de date nu există deja adrese
-- duplicate, rămase din perioada dinaintea regulii. Dacă există, pasul se sare
-- în tăcere și baza rămâne exact cum era — migrarea NU eșuează și NU șterge
-- nimic. Le găsești cu:
--
--   SELECT email, COUNT(*) c, GROUP_CONCAT(username) FROM users
--    WHERE email IS NOT NULL AND email <> '' GROUP BY email HAVING c > 1;
--
-- Alege pe care cont rămâne adresa, golește-o pe celelalte, apoi rulează
-- fișierul din nou: pasul se va executa atunci.
SET @dubluri := (
  SELECT COUNT(*) FROM (
    SELECT email FROM users
     WHERE email IS NOT NULL AND email <> ''
     GROUP BY email HAVING COUNT(*) > 1
  ) d
);
SET @sql := IF(
  @dubluri = 0 AND (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'uq_users_email') = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cheia străină. ON DELETE SET NULL, nu CASCADE: ștergerea celui care a
-- recomandat nu are voie să ia cu ea contul celui recomandat.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND CONSTRAINT_NAME = 'fk_users_referrer') = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_referrer
     FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
