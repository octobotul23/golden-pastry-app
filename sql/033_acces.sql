-- Migrarea 033 — „Acces": cine intră pe ce panou și cine face ce faptă
--
-- Se rulează o singură dată, după 032. Poate fi rulată de câte ori vrei fără
-- pagubă: `CREATE TABLE IF NOT EXISTS` nu atinge un tabel care există deja, și
-- migrarea nu scrie niciun rând în el.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/033_acces.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- CEREREA PATRONULUI, 4 octombrie 2026: „un meniu Acces unde să pot pune
-- nivelul de acces pentru orice — dacă vreau să vadă administratorii, ori cine
-- vreau eu, lucrurile de pe site".
--
-- Până acum, cine intră unde era scris în cod: șapte liste de roluri în
-- api/lib/auth.php, câte una de fel de ușă. Fiecare dorință de felul „să vadă
-- și managerul Catalogul" era o schimbare de cod și un plasture de urcat.
--
-- TABELUL `role_rights` ține NUMAI CE A SCHIMBAT FONDATORUL față de pornire:
--
--   `cheie`   dreptul — un panou (`catalog`, `depozit`…) ori o faptă
--             (`comenzi.scrie`, `plati.stare`…). Lista lor, cu numele de arătat
--             și cu cine le are din pornire, stă în api/lib/auth.php
--             (`GP_DREPT_IMPLICIT`), nu aici.
--   `rol`     coloana matricei: admin, manager, agent_vanzari, angajat,
--             utilizator_lab. Fondatorul nu are rânduri — are mereu tot.
--   `permis`  1 = are, 0 = nu are.
--   `set_by`, `set_at`  cine a pus bifa și când. Povestea întreagă stă în
--             Jurnal (`acces_schimbat`); aici e numai ultima mână.
--
-- TABELUL GOL ÎNSEAMNĂ „CA PÂNĂ IERI". Asta e regula de aur a rundei: cu
-- migrarea importată și nicio bifă mișcată, fiecare rută răspunde exact ca în
-- 2.6.0 — o ține .claude/check-acces-echivalent.mjs, cu poza lui 2.6.0 alături.
-- Tot de aceea „înapoi la început" din pagina „Acces" e o golire a tabelului,
-- nu o listă de rânduri de rescris.
--
-- DE CE TEXT, ȘI NU ENUM, la `cheie` și `rol`: lista de drepturi crește cu
-- fiecare rundă (asta a adus douăsprezece), iar un ENUM ar cere o migrare la
-- fiecare rând nou din matrice. Ce e o cheie adevărată hotărăște serverul la
-- scriere (`PUT /acces` refuză tot ce nu cunoaște) și la citire (`drept()`
-- răspunde „nu" la o cheie necunoscută, oricui).
--
-- CE NU FACE:
--   * nu scrie niciun rând: matricea pornește goală, adică pe implicit;
--   * nu atinge rolul niciunui cont (`users.role` rămâne cum e);
--   * nu are semn în `settings`: nu face nimic „o singură dată", deci n-are ce
--     ține minte. Că a rulat se vede din tabel și din registrul migrărilor.

SET NAMES utf8mb4;

-- ------------------------------------------- tabelul celulelor schimbate ---
--
-- Cheia primară e perechea: o celulă a matricei are un singur rând, iar a doua
-- salvare a aceleiași bife îl rescrie (`INSERT … ON DUPLICATE KEY UPDATE`).
-- `set_by` cu `ON DELETE SET NULL`, ca la `settings.updated_by`: ștergerea
-- contului care a pus bifa nu trebuie să ia bifa cu ea.

CREATE TABLE IF NOT EXISTS role_rights (
  cheie   VARCHAR(48)  NOT NULL,
  rol     VARCHAR(32)  NOT NULL,
  permis  TINYINT(1)   NOT NULL,
  set_by  INT UNSIGNED NULL,
  set_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cheie, rol),
  CONSTRAINT fk_role_rights_user FOREIGN KEY (set_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`. Rândul de mai jos se
-- scrie numai dacă registrul e acolo — importată singură, de mână, pe o bază
-- fără `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('033_acces.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
