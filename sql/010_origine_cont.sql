-- Migrarea 010 — de unde vine un cont, și când l-a recunoscut casa
--
-- Se rulează o singură dată, după 009. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare pas se uită întâi în information_schema și, dacă are deja ce
-- caută, nu face nimic. Nu șterge și nu rescrie niciun rând existent — singura
-- scriere e completarea o singură dată a coloanei noi pe conturile deja
-- existente, din urma pe care și-au lăsat-o în jurnal.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/010_origine_cont.sql
-- Rulare pe cPanel:         phpMyAdmin → baza de date → Import → acest fișier
--
-- Ce aduce, și DE CE
-- ==================
--
-- Comanda luată la telefon își alegea contul numai după numele tastat de
-- operatoare (`client_by_name()`, api/lib/orders.php). Numele sub care e
-- strigat în casă un client de en-gros e scris pe firma din stradă, deci îl
-- știe oricine — iar pagina „Fă-ți cont" scria numele afișat exact cum era
-- tastat, fără să-l compare cu nimic, și năștea contul cu `role='client'`,
-- `active=1`: fix ce căuta potrivirea. Străinul își făcea un cont cu numele
-- clientului, și de a doua zi comanda scrisă în panou se lega tăcut de contul
-- LUI. În „Comanda mea" i se deschideau adresa punctului de lucru, telefonul,
-- mențiunile și PREȚUL UNITAR negociat pe fiecare poziție — pentru en-gros,
-- tocmai cifra pe care patronul n-o dă nimănui.
--
-- Reparația e simplă de spus: se potrivesc după nume numai conturile pentru
-- care a garantat casa. Ca s-o poată spune și codul, îi trebuie un răspuns
-- sigur la două întrebări pe care baza nu le putea răspunde:
--
--   1. contul ăsta și l-a făcut singur cineva de pe internet?  → self_registered
--   2. l-a recunoscut casa de-atunci?                          → vouched_at
--
-- DE CE O COLOANĂ, ȘI NU JURNALUL. Prima formă a reparației citea `audit_log`:
-- un cont născut pe site lasă acolo un rând `user_register`. Ar fi mers, și
-- n-ar fi cerut nicio migrare — dar sprijinea o hotărâre despre bani pe un
-- tabel care n-o apără:
--
--   * `audit_log.entity_id` e VARCHAR fără cheie străină, iar potrivirea ar fi
--     fost pe id. Nici MariaDB, nici MySQL 5.7 nu păstrează AUTO_INCREMENT
--     peste o repornire — îl recalculează ca MAX(id)+1. O repornire după o
--     ștergere de conturi, și un cont nou făcut din Conturi moștenea un id care
--     avea deja rând `user_register`: casa își refuza propriul client, tăcut.
--   * jurnalul e făcut ca să fie citit, deci și ca să poată fi tăiat. „Golește
--     ce e mai vechi de un an", pus mâine în Setări, ar fi făcut din fiecare
--     cont mai bătrân de-atât un „cont al casei" și ar fi redeschis gaura fără
--     niciun semn.
--
-- Coloana stă pe chiar rândul judecat, se naște odată cu contul și nu se poate
-- otrăvi din afară.
--
-- DE CE DOUĂ COLOANE, ȘI NU UNA CARE SE RESCRIE. „S-a născut pe site" e un
-- fapt, și faptele nu se schimbă; „casa l-a recunoscut" e o hotărâre, luată
-- într-o zi anume de un om anume. Ținute într-o singură coloană care se
-- rescrie, recunoașterea ar fi șters faptul, și nimeni n-ar mai fi putut afla
-- peste un an care conturi au venit de pe site.

SET NAMES utf8mb4;

-- ---------------------------------------- 1. de unde vine contul --

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'self_registered') = 0,
  'ALTER TABLE users
     ADD COLUMN self_registered TINYINT(1) NOT NULL DEFAULT 0 AFTER active',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------- 2. când l-a recunoscut casa --

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'vouched_at') = 0,
  'ALTER TABLE users
     ADD COLUMN vouched_at DATETIME NULL AFTER self_registered',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cine a apăsat „Recunosc clientul". Rămâne lizibil și după ce omul acela
-- pleacă din firmă, de-aia SET NULL și nu CASCADE — la fel ca `requested_by`
-- din `founder_invites` (sql/009).
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'vouched_by') = 0,
  'ALTER TABLE users
     ADD COLUMN vouched_by INT UNSIGNED NULL AFTER vouched_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'fk_users_vouched_by') = 0,
  'ALTER TABLE users
     ADD CONSTRAINT fk_users_vouched_by FOREIGN KEY (vouched_by)
         REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------- 3. conturile care există deja --

-- Singura dată când jurnalul e folosit ca temei. Antetul de sus spune limpede
-- de ce jurnalul NU e un temei bun pentru o hotărâre despre bani — id reciclat
-- după o repornire, jurnal tăiat de o curățenie — și tot acolo scrie de ce se
-- poate totuși folosi AICI: fiindcă se citește O SINGURĂ DATĂ, acum, pe baza de
-- azi, unde nici una din cele două nu s-a întâmplat încă.
--
-- NUMAI CĂ „O SINGURĂ DATĂ" ERA O VORBĂ, NU UN FAPT. `actualizeaza-vps.sh`
-- rulează, la FIECARE actualizare, toate migrările de la 003 în sus — anume,
-- fiindcă sunt scrise să fie repetabile. Deci pasul ăsta se uita în jurnal nu
-- o dată, ci de fiecare dată, luni și ani după ce baza de azi devenise baza de
-- ieri. Amândouă primejdiile de care se ferea antetul erau, de fapt, deschise:
--
--   · serverul repornește după o ștergere de conturi, AUTO_INCREMENT se
--     recalculează ca MAX(id)+1, iar un client făcut de patron din Conturi
--     moștenește un id care are deja rând `user_register`. La următoarea
--     actualizare — nu la nașterea lui, ci oricând pe urmă — contul acela
--     capătă `self_registered = 1` fără `vouched_at`, adică iese din
--     socoteala lui `client_by_name()`. De atunci, comanda luată la telefon pe
--     numele lui nu se mai leagă de contul lui, și nimeni nu poate ghici de ce:
--     contul e acolo, activ, cu numele scris corect.
--
--   · o curățenie a jurnalului ar face pe dos: conturile vechi făcute pe site
--     și-ar pierde urma, iar pasul nu i-ar mai marca — dar ăsta e un rău mai
--     mic, fiindcă cele deja marcate rămân marcate.
--
-- OPRITORUL. Pasul își lasă acum o urmă în `settings`, și se uită întâi la ea.
-- Când urma e acolo, nu se mai face NIMIC — nici măcar nu se deschide jurnalul.
-- Înțelesul din antet devine adevărat în cod, nu doar pe hârtie, iar fișierul
-- rămâne rulabil de câte ori vrei, cum cere casa.
--
-- PE UN SERVER CARE A RULAT DEJA 010 DE MULTE ORI, urma încă nu există, deci
-- pasul se mai face o dată — exact cât s-ar fi făcut oricum la actualizarea
-- asta — și abia pe urmă se pune opritorul. De la actualizarea următoare,
-- niciodată.
--
-- `client_by_name()` se uită numai la conturile de client, dar completăm
-- coloana pe toate: e un fapt despre cont, nu o regulă despre comenzi.
--
-- Condiția `self_registered = 0` din WHERE rămâne: opritorul apără de a doua
-- ACTUALIZARE, ea apără de a doua rulare din aceeași actualizare (un import
-- refăcut cu mâna, de pildă). `CAST(a.entity_id AS UNSIGNED) = u.id` se scrie
-- în ordinea asta dinadins — invers, `CAST(u.id AS CHAR)` iese cu colația
-- conexiunii, se lovește de colația coloanei și MySQL răspunde 1267 „Illegal
-- mix of collations", adică migrarea nu mai trece deloc.
--
-- Se scrie cu PREPARE, ca pașii 1 și 2 de mai sus, tocmai ca să fie adevărat pe
-- litere că jurnalul nu se deschide: interogarea nici nu ajunge la server când
-- urma există. Apostrofurile dinăuntru se dublează — așa se scrie un apostrof
-- într-un șir SQL.
SET @judecat := (SELECT COUNT(*) FROM settings
                  WHERE setting_key = 'migration_010_origin_judged');

SET @sql := IF(@judecat = 0,
  'UPDATE users u
      SET u.self_registered = 1
    WHERE u.self_registered = 0
      AND EXISTS (
            SELECT 1 FROM audit_log a
             WHERE a.entity = ''user''
               AND a.action = ''user_register''
               AND CAST(a.entity_id AS UNSIGNED) = u.id
          )',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Urma, scrisă DUPĂ pas. `INSERT IGNORE` o face nevătămătoare la a doua
-- rulare: dacă e deja acolo, rămâne cu data dintâi — data la care s-a judecat
-- cu adevărat, nu data ultimei actualizări. Valoarea e citibilă de om, fiindcă
-- singurul care o va citi vreodată e cineva care se întreabă „când s-a hotărât
-- asta?".
INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('migration_010_origin_judged', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), 0);

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`, unde e scris pe larg
-- de ce există și de ce se face înaintea buclei. Rândul de mai jos se scrie
-- numai dacă registrul e acolo — importată singură, de mână, pe o bază fără
-- `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('010_origine_cont.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
