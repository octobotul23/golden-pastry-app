-- Migrarea 028 — trei prețuri, după cantitate
--
-- Se rulează o singură dată, după 027. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare coloană se adaugă numai dacă lipsește, iar setările se
-- seamănă cu `INSERT IGNORE`, deci ce a hotărât patronul între două rulări
-- rămâne cum a hotărât.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/028_preturi_pe_trepte.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- CEREREA PATRONULUI, 24 septembrie 2026: „până la trei prețuri — prețul A sub
-- 100 de bucăți, între 100 și 300 prețul B, peste 300 prețul C". Pragurile sunt
-- ale FIECĂRUI produs (100 și 300 sunt doar un exemplu), iar cantitatea după
-- care se alege treapta e cea cumpărată din produsul acela, pe latura aceea.
--
-- PE PRODUS:
--
--   `price_frozen_b`, `price_frozen_c`   prețurile B și C ale laturii congelate
--   `price_baked_b`,  `price_baked_c`    și ale celei gata de servire
--   `prag_b`, `prag_c`                   de la câte bucăți încep B și C
--
-- Prețul A rămâne în coloanele de azi, `price_frozen` și `price_baked`. Nu se
-- redenumește nimic: pe un produs fără trepte cele patru coloane noi sunt NULL,
-- iar produsul se vinde EXACT ca ieri — cu un singur preț, oricâte bucăți.
--
-- Pragurile sunt o singură pereche pentru amândouă laturile (hotărârea
-- patronului, 24 septembrie): „de la 100 de bucăți" înseamnă același lucru la
-- congelate și la gata de servire. Prețurile, în schimb, sunt ale fiecărei
-- laturi, ca și prețul A.
--
-- PE FIECARE RÂND DE COMANDĂ ȘI DE CERERE:
--
--   `treapta`        1 = A, 2 = B, 3 = C — treapta la care s-a pus prețul
--   `prag_treapta`   pragul care a câștigat-o, așa cum era ATUNCI
--
-- DE CE SE ȚIN PE RÂND, DEȘI PREȚUL E DEJA ACOLO. Fiindcă o comandă se
-- corectează, iar la corectură casa trebuie să știe DE CE s-a dat prețul acela.
-- Clientul care a cerut 150 de bucăți la prețul B și taie apoi comanda la 90 nu
-- mai are dreptul la B — dar numai `unit_price` nu spune asta: 3,90 poate fi un
-- preț B de azi, un A de anul trecut ori o reducere. Cu treapta și pragul
-- scrise pe rând, `price_items()` (api/lib/orders.php) poate hotărî limpede:
-- sub pragul care a câștigat-o, promisiunea cade și cantitatea nouă se
-- tarifează la zi; peste el, prețul promis rămâne, chiar dacă între timp
-- patronul a mutat pragurile ori a scumpit.
--
-- Pragul se ține pe rând și nu se recitește din produs din același motiv pentru
-- care `unit_price` nu se recitește din catalog: catalogul se schimbă, iar o
-- comandă de acum o lună s-ar judeca după o regulă pe care n-a văzut-o nimeni
-- când a fost făcută.
--
-- Rândurile vechi rămân cu NULL în amândouă coloanele, iar NULL înseamnă „A,
-- fără prag" — chiar ce erau: până azi nu exista altă treaptă.
--
-- ÎN SETĂRI, patru rânduri noi. Salvarea din Setări scrie numai cu UPDATE
-- (`settings_save()` refuză pe nume orice cheie fără rând), deci o casetă nouă
-- fără rândul ei semănat aici ar fi refuzată la prima salvare:
--
--   `catalog_vedere_telefon`      vederea cu care se deschide magazinul pe
--                                 telefon: `unu` (unul pe rând, cu detalii),
--                                 `patru` sau `sase` pe ecran
--   `catalog_vedere_calculator`   aceeași alegere, pe calculator și tabletă
--   `pret_prag_b_implicit`        pragurile cu care se precompletează un
--   `pret_prag_c_implicit`        produs nou în pagina Produse
--
-- Pragurile din Setări NU se aplică singure niciunui produs: sunt doar
-- valoarea de pornire a casetelor. Un produs are pragurile LUI, scrise pe el;
-- schimbarea din Setări nu mută pragul nimănui care și-l are deja.
--
-- CE NU FACE:
--   * nu scrie niciun preț B sau C. Ce trepte are un produs hotărăște patronul,
--     din Produse; migrarea nu inventează reduceri;
--   * nu atinge rândurile de comandă vechi. NULL e răspunsul lor corect;
--   * nu schimbă `price_frozen` / `price_baked`. Ele sunt prețul A, cum au fost
--     întotdeauna prețul unic — un panou vechi, care nu știe de trepte, le
--     scrie și le citește la fel ca ieri.

SET NAMES utf8mb4;

-- ------------------------------------------- 1. prețurile B și C -----------
--
-- Același tip ca prețul A (`DECIMAL(10,2) NULL`, din 001 și 003), ca o
-- comparație „B mai mic decât A" să nu depindă de rotunjiri deosebite.
-- `AFTER price_baked` și nu după `in_baked`: importată de mână pe o bază fără
-- 024, migrarea trebuie să treacă, nu să pice pe o coloană lipsă.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'price_frozen_b') = 0,
  'ALTER TABLE products ADD COLUMN price_frozen_b DECIMAL(10,2) NULL AFTER price_baked',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'price_frozen_c') = 0,
  'ALTER TABLE products ADD COLUMN price_frozen_c DECIMAL(10,2) NULL AFTER price_frozen_b',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'price_baked_b') = 0,
  'ALTER TABLE products ADD COLUMN price_baked_b DECIMAL(10,2) NULL AFTER price_frozen_c',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'price_baked_c') = 0,
  'ALTER TABLE products ADD COLUMN price_baked_c DECIMAL(10,2) NULL AFTER price_baked_b',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 2. pragurile ------------------
--
-- Întregi fără semn: cantitatea se socotește în bucăți, iar un prag de „99,5
-- bucăți" n-ar însemna nimic pentru nimeni. Poziția de comandă primește și
-- zecimale (DECIMAL(10,2)), dar pragul se compară cu ea ca număr, deci 99,99
-- rămâne sub 100.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'prag_b') = 0,
  'ALTER TABLE products ADD COLUMN prag_b INT UNSIGNED NULL AFTER price_baked_c',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'prag_c') = 0,
  'ALTER TABLE products ADD COLUMN prag_c INT UNSIGNED NULL AFTER prag_b',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 3. treapta, pe fiecare rând ---
--
-- Pe amândouă tabelele de poziții, fiindcă prețul trece din cerere în comandă
-- la acceptare (`route_requests_accept()`), iar treapta trebuie să treacă
-- odată cu el. `AFTER unit_price`: coloana de care ține.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'
      AND COLUMN_NAME = 'treapta') = 0,
  'ALTER TABLE order_items ADD COLUMN treapta TINYINT UNSIGNED NULL AFTER unit_price',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'
      AND COLUMN_NAME = 'prag_treapta') = 0,
  'ALTER TABLE order_items ADD COLUMN prag_treapta INT UNSIGNED NULL AFTER treapta',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_request_items'
      AND COLUMN_NAME = 'treapta') = 0,
  'ALTER TABLE order_request_items ADD COLUMN treapta TINYINT UNSIGNED NULL AFTER unit_price',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_request_items'
      AND COLUMN_NAME = 'prag_treapta') = 0,
  'ALTER TABLE order_request_items ADD COLUMN prag_treapta INT UNSIGNED NULL AFTER treapta',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 4. setările noi --------------
--
-- `INSERT IGNORE`: a doua rulare nu mișcă nimic, deci o vedere ori un prag
-- ales de patron între două actualizări rămâne ales.
--
-- Valorile de pornire: pe telefon un produs pe rând, cu toate detaliile —
-- acolo încap mai puține, iar cele trei prețuri se citesc mai ușor pe un card
-- lat; pe calculator patru pe ecran. Pragurile, chiar exemplul patronului.

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('catalog_vedere_telefon', 'unu', 0),
  ('catalog_vedere_calculator', 'patru', 0),
  ('pret_prag_b_implicit', '100', 0),
  ('pret_prag_c_implicit', '300', 0);

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`, unde e scris pe larg
-- de ce există și de ce se face înaintea buclei. Rândul de mai jos se scrie
-- numai dacă registrul e acolo — importată singură, de mână, pe o bază fără
-- `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('028_preturi_pe_trepte.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
