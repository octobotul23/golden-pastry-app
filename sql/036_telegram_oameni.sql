-- Migrarea 036 — cine a apăsat, cu adevărat, pe Telegram
--
-- Se rulează o singură dată, după 035. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare coloană e păzită prin `information_schema`, `CREATE TABLE IF
-- NOT EXISTS` nu atinge un tabel care există deja, și migrarea nu scrie și nu
-- schimbă niciun rând.
--
-- „NU ATINGE” ÎNSEAMNĂ ȘI CĂ NU-L ÎNDREAPTĂ (lecția lui 035). Ce se adaugă în
-- `CREATE TABLE` după ce tabelul a fost făcut o dată nu mai ajunge pe baza
-- aceea, oricâte rulări ar urma. O schimbare de formă a lui `telegram_oameni`
-- se face deci într-o migrare NOUĂ, cu `ALTER` păzit — nu aici.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/036_telegram_oameni.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- CEREREA PATRONULUI, runda 2.7.5: botul stă într-un GRUP cu oamenii casei.
-- Când altcineva apăsa „Acceptă”, mesajul, panoul și Jurnalul spuneau că a
-- acceptat patronul. Pricina: cine n-avea contul legat de Telegramul lui lucra
-- pe contul care a apăsat „Leagă botul” — cu tot cu NUMELE acelui cont.
--
-- HOTĂRÂREA: apăsarea unui om cu contul nelegat MERGE mai departe (botul ține
-- drepturile, ca până azi), dar poartă NUMELE LUI de pe Telegram și vorba „cont
-- nelegat”. Niciodată numele altcuiva.
--
-- Pentru asta trebuie două lucruri pe care baza nu le avea unde ține:
--
--   1. PE CERERE, cine a apăsat. `handled_by` rămâne contul pe ale cărui
--      drepturi s-a lucrat (al botului); lângă el stau acum
--        `handled_tg_id`    identificatorul de Telegram al celui care a apăsat;
--        `handled_tg_name`  numele lui, CURĂȚAT și tăiat de server
--                           (`telegram_nume_curat()`, api/lib/telegram-legaturi.php)
--                           înainte de a fi scris — coloana nu ține niciodată
--                           textul brut venit de la Telegram.
--      Amândouă se scriu în ACEEAȘI tranzacție cu hotărârea și se golesc la
--      redeschidere, odată cu `handled_by`. Rămân goale pentru hotărârile din
--      panou și pentru apăsările unui om cu contul legat: acolo `handled_by`
--      spune deja adevărul.
--
--   2. OAMENII VĂZUȚI ÎN GRUP: tabelul `telegram_oameni`, un rând de om, ținut
--      la zi la fiecare apăsare care a trecut de poarta conversației. Din el își
--      alege fondatorul, în Setări, pe cine să lege de ce cont — altfel ar
--      trebui să afle de la fiecare un număr de zece cifre.
--        `tg_id`          identificatorul de Telegram; unic.
--        `name`           numele curățat (prenume + nume; în lipsă `@utilizator`;
--                         în lipsă „Telegram #număr”). Niciodată gol.
--        `username`       numele de utilizator, fără „@”; poate lipsi.
--        `first_seen_at`, `last_seen_at`  prima și ultima apăsare, în UTC (le
--                         scrie serverul cu `UTC_TIMESTAMP()`, nu implicitul).
--        `apasari`        câte apăsări s-au văzut de la el.
--
-- FĂRĂ COLOANĂ DE LEGĂTURĂ, dinadins. Legătura om → cont rămâne unde e de la
-- 021: `users.telegram_user_id`. Scrisă în două locuri, s-ar depărta la prima
-- dezlegare. „E legat?” se află alăturând `telegram_oameni.tg_id` de coloana
-- aceea.
--
-- FĂRĂ CHEI STRĂINE: identificatorul de Telegram nu e al niciunui rând din
-- bază, iar pe cerere numele trebuie să rămână și după ce omul pleacă din grup.
--
-- TABELUL NU CREȘTE LA NESFÂRȘIT: serverul ține cel mult 200 de rânduri și le
-- scoate pe cele mai de demult văzute dintre cei NELEGAȚI. Paza e în cod
-- (`telegram_om_vazut()`), nu aici.
--
-- BIGINT UNSIGNED, ca `users.telegram_user_id` din 021: identificatorii de
-- Telegram au trecut de 2^31. VARCHAR(64) pentru nume: serverul taie la 40.
--
-- CE NU FACE:
--   * nu atinge niciun rând vechi: hotărârile de până azi rămân fără semn — nu
--     se poate afla cine a apăsat atunci, iar ghicitul ar fi o minciună scrisă
--     în tabel;
--   * nu leagă pe nimeni și nu dezleagă pe nimeni;
--   * nu are semn în `settings`: nu face nimic „o singură dată”.
--
-- FĂRĂ EA, apăsarea unui om cu contul nelegat e REFUZATĂ, cu un mesaj care
-- numește fișierul ăsta: fără cele două coloane numele lui n-ar avea unde se
-- scrie, iar hotărârea ar ieși iar pe numele contului care a legat botul. Cine
-- are contul legat lucrează ca până acum.

SET NAMES utf8mb4;

-- --------------------------------------- 1. cine a apăsat, pe cerere --

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'handled_tg_id') = 0,
  'ALTER TABLE order_requests ADD COLUMN handled_tg_id BIGINT UNSIGNED NULL AFTER handled_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'handled_tg_name') = 0,
  'ALTER TABLE order_requests ADD COLUMN handled_tg_name VARCHAR(64) NULL AFTER handled_tg_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 2. oamenii văzuți în grup --

CREATE TABLE IF NOT EXISTS telegram_oameni (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tg_id         BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(64)  NOT NULL,
  username      VARCHAR(64)  NULL,
  first_seen_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  apasari       INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_tgoameni_tg (tg_id),
  -- Lista din Setări îi arată de la cel mai de curând văzut, iar paza celor 200
  -- de rânduri îi caută pe cei mai de demult.
  KEY ix_tgoameni_vazut (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`. Rândul de mai jos se
-- scrie numai dacă registrul e acolo — importată singură, de mână, pe o bază
-- fără `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('036_telegram_oameni.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
