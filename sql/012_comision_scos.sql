-- Migrarea 012 — comisionul scos din socoteală de mâna patronului
--
-- Se rulează o singură dată, după 011. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare pas se uită întâi în information_schema și, dacă are deja ce
-- caută, nu face nimic. Nu șterge și nu rescrie niciun rând existent.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/012_comision_scos.sql
-- Rulare pe cPanel:         phpMyAdmin → baza de date → Import → acest fișier
--
-- Ce aduce, și DE CE
-- ==================
--
-- Până acum nu exista nicio cale de a scoate un rând de comision. Se putea
-- bifa plata, se putea alege între cele două coduri ale unui conflict — dar un
-- rând care pur și simplu n-avea ce căuta acolo rămânea în listă și în
-- totaluri. Iar cazul nu e rar: o comandă de probă, un cod tastat greșit, un
-- client trecut din greșeală pe agentul care nu l-a adus.
--
-- DE CE O COLOANĂ, ȘI NU UN DELETE. Rândul de comision nu e un fapt scris de
-- cineva: e o CONSECINȚĂ, recalculată de `referral_commission_sync()` la
-- FIECARE atingere a comenzii — o corectură de adresă, o mutare de etapă de pe
-- tableta din laborator, butonul „Șterge comanda". Șters de-a dreptul, rândul
-- se scria la loc, cu alt id, de îndată ce cineva mișca ceva la comandă:
--
--   * dacă pe comandă mai stă un cod, ramura 1 îl rescrie;
--   * dacă cumpărătorul are `referred_by` pe cont — legătura „pentru
--     totdeauna" — ramura 2 îl rescrie chiar și fără niciun cod pe comandă.
--
-- Adică patronul ar fi apăsat „Șterge", ar fi văzut rândul dispărând, iar
-- peste zece minute l-ar fi găsit înapoi, fără să înțeleagă de ce. O ștergere
-- care se desface singură e mai rea decât lipsa ei: prima dată o crezi.
--
-- Singurele două cauze care nasc rândul se pot, teoretic, tăia și ele: se
-- scoate codul de pe comandă, se rupe legătura contului. Prima o poate face
-- deja patronul din formularul comenzii. A doua ar însemna însă ca „scoate
-- rândul ăsta din martie" să taie în tăcere TOATE comisioanele viitoare ale
-- clientului aceluia — cu totul altceva decât a cerut omul.
--
-- Deci se ține minte FAPTUL: cineva a scos rândul, atunci, și se știe cine.
-- Un fapt scris nu se poate recalcula, deci nu se poate desface singur. E
-- aceeași hotărâre ca la comenzi, care nu se șterg, ci se anulează.
--
-- Ce se schimbă în cod, pe scurt:
--   * `referral_commission_sync()` nu atinge un rând scos: nici nu-l
--     recalculează, nici nu-l șterge, nici nu-l învie;
--   * totalurile și lista agentului sar peste el;
--   * lista patronului ÎL ARATĂ, tăiat, cu un buton „Adu înapoi" — un rând
--     ascuns cu totul ar fi un cimitir din care nu se mai iese.

-- ------------------------------------------- 1. când a fost scos --

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referral_commissions'
      AND COLUMN_NAME = 'dismissed_at') = 0,
  'ALTER TABLE referral_commissions
     ADD COLUMN dismissed_at DATETIME NULL AFTER paid_by',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------- 2. și de către cine --

-- Rămâne lizibil și după ce omul acela pleacă din firmă, de-aia SET NULL și nu
-- CASCADE — la fel ca `paid_by` din 008 și `vouched_by` din 010.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referral_commissions'
      AND COLUMN_NAME = 'dismissed_by') = 0,
  'ALTER TABLE referral_commissions
     ADD COLUMN dismissed_by INT UNSIGNED NULL AFTER dismissed_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referral_commissions'
      AND INDEX_NAME = 'fk_commission_dismisser') = 0,
  'ALTER TABLE referral_commissions
     ADD CONSTRAINT fk_commission_dismisser FOREIGN KEY (dismissed_by)
         REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------- 3. citirea listelor --

-- Fiecare listă și fiecare total sar peste rândurile scoase, deci filtrul cade
-- pe „dismissed_at IS NULL" alături de cele două chei după care se citește
-- oricum. Indexul le pune pe amândouă în același drum, ca lista lunii să nu
-- ajungă să treacă prin toate rândurile ca să le arunce pe cele scoase.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referral_commissions'
      AND INDEX_NAME = 'ix_commission_scos') = 0,
  'ALTER TABLE referral_commissions
     ADD KEY ix_commission_scos (dismissed_at, order_day)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`, unde e scris pe larg
-- de ce există și de ce se face înaintea buclei. Rândul de mai jos se scrie
-- numai dacă registrul e acolo — importată singură, de mână, pe o bază fără
-- `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('012_comision_scos.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
