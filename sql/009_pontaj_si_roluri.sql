-- Migrarea 009 — rolurile de conducere și de laborator, plus invitațiile de fondator
--
-- Se rulează o singură dată, după 008. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare pas se uită întâi în information_schema și, dacă are deja ce
-- caută, nu face nimic. Nu atinge, nu rescrie și nu șterge niciun rând
-- existent: toți pașii sunt ADITIVI.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/009_pontaj_si_roluri.sql
-- Rulare pe cPanel:         phpMyAdmin → baza de date → Import → acest fișier
--
-- Ce aduce:
--   1. rolurile „fondator" și „utilizator_lab", la coada listei
--   2. tabelul „founder_invites" — linkul prin care un cont devine fondator
--
-- IMPORTANT despre pasul 1, la fel ca în 007: valorile noi se adaugă la
-- SFÂRȘITUL listei ENUM. MySQL nu ține în rând textul rolului, ci poziția lui
-- în listă. O valoare strecurată la mijloc ar deplasa pozițiile tuturor celor
-- de după ea, iar conturile existente și-ar schimba rolul în tăcere. La coadă,
-- pozițiile 1-5 rămân exact cum erau, iar rândurile de azi nu se clintesc.
--
-- Despre „utilizator_lab": rolul intră în schemă acum, dar codul nu îl oferă
-- încă nicăieri — pontajul vine cu pasul următor. Nu e o scăpare, e ordinea
-- firească: `roles_available()` (api/lib/auth.php) intersectează ce știe codul
-- cu ce încape în coloană, deci un rol pe care coloana îl acceptă și codul nu
-- îl cunoaște pur și simplu nu apare în nicio listă. Invers ar fi fost defect:
-- cod care cere un rol pe care baza nu-l are înseamnă „1265 Data truncated".

SET NAMES utf8mb4;

-- ------------------------------------------------ 1. rolurile noi --

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'role' AND COLUMN_TYPE LIKE '%utilizator_lab%') = 0,
  'ALTER TABLE users MODIFY role
     ENUM(''admin'',''angajat'',''client'',''manager'',''agent_vanzari'',''fondator'',''utilizator_lab'')
     NOT NULL DEFAULT ''client''',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------- 2. invitațiile de fondator --

-- Cum devine cineva fondator, și de ce trece printr-o cutie poștală.
--
-- Fondatorul e singurul care poate face și desface administratori și alți
-- fondatori. Dacă drumul spre rolul ăsta ar trece prin pagina Conturi, atunci
-- oricine ajunge o dată administrator și-ar putea acorda singur rolul de
-- deasupra lui — iar „mai multe drepturi decât un administrator" n-ar mai
-- însemna nimic. Așa că poarta e în altă parte: pe adresa firmei din Setări
-- („company_email"). Cine ține cutia aceea confirmă; ceilalți doar cer.
--
-- Tabelul e geamănul lui `password_resets` (sql/001_schema.sql:60), până la
-- numele coloanelor, fiindcă e aceeași unealtă: un secret trimis pe e-mail,
-- valabil scurt și o singură dată. Am ales să-l copiez, nu să-l refolosesc:
-- două înțelesuri („schimbă-ți parola" și „ridică-l pe om în rang") într-un
-- singur tabel ar fi însemnat că o scăpare de filtrare într-unul dintre
-- drumuri se plătește în celălalt.
CREATE TABLE IF NOT EXISTS founder_invites (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- Contul care devine fondator dacă linkul e deschis.
  user_id      INT UNSIGNED NOT NULL,
  -- Cine a apăsat butonul. Azi e mereu același om cu cel de deasupra, fiindcă
  -- se cere numai pentru sine — dar rândul rămâne lizibil peste ani fără să
  -- fie nevoie de ghicit, ca `audit_log.user_id` lângă `entity_id`. Rămâne și
  -- când contul cerut între timp a dispărut: de-aia SET NULL, nu CASCADE.
  requested_by INT UNSIGNED NULL,
  token_hash   CHAR(64)     NOT NULL,
  expires_at   DATETIME     NOT NULL,
  used_at      DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_founder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_founder_asker FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY ix_founder_token (token_hash),
  KEY ix_founder_open (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
