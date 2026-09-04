-- ---------------------------------------------------------------------------
-- Golden Pastry — migrare 006: rețetele produselor și rezervările de stoc
-- ---------------------------------------------------------------------------
--
-- De rulat O SINGURĂ DATĂ, pe o bază creată înainte de această modificare.
--
-- PE SERVER, LA PRIMA INSTALARE, NU AI NEVOIE DE EL: tabelele și coloanele
-- există deja în 001_schema.sql. Fișierul acesta e doar pentru o bază făcută
-- înainte ca ele să fie adăugate.
--
-- IMPORTĂ-L DIN phpMyAdmin, ca pe celelalte. Dacă îl dai prin `mysql` din linia
-- de comandă pe Windows, clientul citește fișierul în codepage-ul consolei, nu
-- în UTF-8, iar diacriticele se strică. Dacă totuși folosești linia de comandă,
-- adaugă --default-character-set=utf8mb4.
--
-- Ce aduce: „din ce e făcut un croissant” și „cât din făina din depozit e deja
-- promisă unei comenzi”. Până acum depozitul știa doar ce a intrat și ce a
-- ieșit; nimic nu lega produsul vândut de materia primă consumată, iar
-- „Rezervat” se socotea în browser, din primele 500 de comenzi.
--
-- MySQL nu are `ADD COLUMN IF NOT EXISTS`, iar MariaDB îl are. Verificăm în
-- catalog și construim instrucțiunea doar dacă lipsește, ca rularea repetată
-- să nu dea eroare pe niciunul dintre ele.
--
-- NIMIC din acest fișier nu scrie în `settings` și nicio instrucțiune nu e
-- necondiționată: o migrare reimportată peste o bază configurată nu are voie
-- să șteargă ce a ales patiseria (vezi ce a pățit 003 cu lista de zone).

-- ---------------------------------------------------------------------------
-- 1. Rețetele: ce materii prime consumă o bucată dintr-un produs
-- ---------------------------------------------------------------------------
--
-- Rețetele sunt pe UN SINGUR NIVEL, deliberat. O „cutie cadou” care conține
-- croissante trece croissantul ca linie de rețetă și scade direct articolul lui
-- finit din depozit. Nicio recursie, rezultat corect.
--
-- Fără `variant`: diferența dintre copt și congelat e în energie și în timp, nu
-- în materiile prime. Dacă vreodată chiar diferă, coloana se adaugă aici, iar
-- cheia unică devine (product_id, item_id, variant).
--
-- `qty` e DECIMAL(12,4), nu (10,2): la două zecimale, cele 0,005 kg de drojdie
-- dintr-un croissant s-ar rotunji la 0,01 — dublul. Pe o comandă de o mie de
-- bucăți, diferența e cinci kilograme de drojdie care nu există.
CREATE TABLE IF NOT EXISTS product_recipes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  item_id    INT UNSIGNED NOT NULL,
  -- cantitatea pentru O SINGURĂ bucată de produs
  qty        DECIMAL(12,4) NOT NULL DEFAULT 0,
  CONSTRAINT fk_recipe_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  -- RESTRICT, nu CASCADE: un articol folosit într-o rețetă nu se șterge din
  -- greșeală. Depozitul oricum nu șterge articole, le dezactivează.
  CONSTRAINT fk_recipe_item FOREIGN KEY (item_id) REFERENCES stock_items(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_recipe (product_id, item_id),
  KEY ix_recipe_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Rezervările: ce e promis unei comenzi, dar încă neconsumat
-- ---------------------------------------------------------------------------
--
-- De ce tabel separat și nu un rând cu `direction = 0` în `stock_moves`:
--
--   1. Soldul e o sumă, nu o coloană. Un rând cu direction = 0 ar fi invizibil
--      pentru SUM(qty * direction) — dar vizibil în toate listările de registru,
--      unde pagina Depozit îl mapează pe intrare/ieșire și l-ar arăta ca ieșire.
--   2. Registrul e append-only, rezervarea e mutabilă. `stock_unsync_order`
--      scrie contra-mișcare tocmai ca să nu șteargă din registru; o rezervare
--      modelată acolo ar cere ori UPDATE pe rânduri de registru, ori o pereche
--      de rânduri-zero la fiecare raportare parțială.
--   3. O rezervare e o promisiune; o mișcare e un fapt. Faptele nu se desfac.
--
-- Rezervarea DESCHISĂ nu se gestionează, se derivă:
--     deschis = qty - consumed, numărat doar pentru comenzile al căror status
--     nu e nici cel de încheiere, nici cel de anulare.
-- De aici vin gratis: eliberarea la „Predată”, redeschiderea dacă iese din
-- „Predată”, eliberarea la anulare. Toate sunt același filtru pe status.
CREATE TABLE IF NOT EXISTS stock_reservations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  -- 'finit' = produsul gata făcut; 'materie' = o linie din rețeta lui
  kind          VARCHAR(8)    NOT NULL DEFAULT 'finit',
  -- cât s-a promis
  qty           DECIMAL(12,4) NOT NULL DEFAULT 0,
  -- cât din promisiune s-a și consumat (producție raportată)
  consumed      DECIMAL(12,4) NOT NULL DEFAULT 0,
  CONSTRAINT fk_resv_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  -- CASCADE pe poziție: `PATCH /orders` șterge și rescrie order_items, deci
  -- rezervările vechi pleacă odată cu pozițiile vechi, fără cod care să le
  -- caute.
  CONSTRAINT fk_resv_oitem FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_resv_stock FOREIGN KEY (item_id) REFERENCES stock_items(id) ON DELETE CASCADE,
  UNIQUE KEY uq_resv (order_item_id, item_id),
  KEY ix_resv_item (item_id),
  KEY ix_resv_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Cât s-a făcut din fiecare poziție
-- ---------------------------------------------------------------------------
--
-- DECIMAL(10,2), la fel ca `qty`: cantitățile zecimale sunt deja o realitate
-- acceptată în aplicație, iar o jumătate de tavă făcută rămâne jumătate.
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE order_items ADD COLUMN done_qty DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER qty',
    'DO 0'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'order_items'
    AND COLUMN_NAME  = 'done_qty'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. Mișcarea de stoc știe din ce comandă vine
-- ---------------------------------------------------------------------------
--
-- SET NULL, nu CASCADE: registrul rămâne lizibil și după ce comanda dispare. O
-- ieșire de făină s-a întâmplat, indiferent ce s-a hotărât despre comandă.
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE stock_moves ADD COLUMN order_id INT UNSIGNED NULL AFTER reason',
    'DO 0'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'stock_moves'
    AND COLUMN_NAME  = 'order_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE stock_moves ADD CONSTRAINT fk_moves_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL',
    'DO 0'
  )
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA    = DATABASE()
    AND TABLE_NAME      = 'stock_moves'
    AND CONSTRAINT_NAME = 'fk_moves_order'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE stock_moves ADD INDEX ix_moves_order (order_id)',
    'DO 0'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'stock_moves'
    AND INDEX_NAME   = 'ix_moves_order'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. Registrul primește patru zecimale
-- ---------------------------------------------------------------------------
--
-- Consumul de materii prime se scrie tot aici, iar la două zecimale drojdia de
-- pe o bucată dispare în rotunjire. MODIFY COLUMN e idempotent și nu atinge
-- valorile existente: 12,50 rămâne 12,5000.
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE stock_moves MODIFY COLUMN qty DECIMAL(12,4) NOT NULL',
    'DO 0'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA  = DATABASE()
    AND TABLE_NAME    = 'stock_moves'
    AND COLUMN_NAME   = 'qty'
    AND NUMERIC_SCALE = 4
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
