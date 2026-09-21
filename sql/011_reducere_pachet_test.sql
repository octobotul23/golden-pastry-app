-- Migrarea 011 — reducerea, ca preț, nu ca rugăminte scrisă în mențiuni
--
-- Se rulează o singură dată, după 010. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare pas se uită întâi în information_schema și, dacă are deja ce
-- caută, nu face nimic. Nu șterge și nu rescrie niciun rând existent.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/011_reducere_pachet_test.sql
-- Rulare pe cPanel:         phpMyAdmin → baza de date → Import → acest fișier
--
-- Ce aduce, și DE CE
-- ==================
--
-- Pe site, „Pachetul de test" scrie −20%. Clientul îl punea în coș, vedea
-- 244,89 lei în loc de 306,15 și trimitea cererea. În panou intra 306,15,
-- fiindcă API-ul n-avea nicio noțiune de reducere: `price_items()` retarifa
-- fiecare poziție din `products` și atât. Pagina a cârpit-o o vreme cu o notă
-- în mențiuni — „clientul a văzut 244,89, catalog 306,15; aplică reducerea la
-- confirmare" — adică reducerea era o rugăminte pe care o ținea minte un om.
-- Uitată, clientului i se cerea suta de lei pe care n-o văzuse pe ecran;
-- ținută minte, se scădea cu mâna, fără ca nimic din sistem să știe de ce
-- comanda aceea are alt preț decât catalogul.
--
-- A doua pagubă, mai puțin văzută: COMISIONUL. `referral_commission_sync()`
-- socotește din `orders.total`. Cu totalul la preț de catalog, agentul primea
-- procentul lui dintr-o sumă pe care patiseria n-a încasat-o — o parte din
-- comision se lua din reducere.
--
--   1. ce reducere a primit cererea, și apoi comanda?   → promo
--   2. cât scria în catalog pentru poziția aceea?       → list_price
--
-- DE CE `promo` PE RÂND, ȘI NU O SETARE. Reducerea nu e o stare a magazinului,
-- e un fapt despre o cerere anume: „pachetul ăsta l-a primit omul acesta, în
-- ziua aceea". Din el se răspunde și la „i s-a mai dat o dată?" — regula „o
-- singură dată per client" n-are alt loc unde să se sprijine. O setare globală
-- ar fi spus doar dacă oferta e deschisă azi, adică tocmai ce nu trebuie.
--
-- DE CE `list_price`, DACĂ PREȚUL SE POATE RECALCULA. Nu se poate: catalogul
-- se schimbă. Peste trei luni, `products.price_frozen` e alt număr, iar comanda
-- veche ar arăta o reducere de altă mărime decât cea dată — sau niciuna. Cifra
-- tăiată e ce a văzut clientul pe ecran în ziua aceea; ea trebuie să rămână
-- scrisă lângă cea plătită, altfel panoul vede o sumă mai mică decât catalogul
-- și n-are cum să afle de ce.
--
-- DE CE NULL, ȘI NU 0. Zero ar fi însemnat „prețul de catalog e zero", care e o
-- afirmație. NULL înseamnă „nu s-a scris nimic aici" — starea tuturor
-- rândurilor de dinaintea migrării, și a celor pentru care catalogul n-avea
-- preț pe varianta cerută (`price_items()` poate onora un preț promis pe o
-- variantă care azi n-are preț în catalog).

SET NAMES utf8mb4;

-- --------------------------------- 1. ce reducere poartă cererea --

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'promo') = 0,
  'ALTER TABLE order_requests
     ADD COLUMN promo VARCHAR(32) NULL AFTER total',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexul e pentru o singură întrebare, dar una care se pune la FIECARE cerere
-- venită de pe site cu pachetul în coș: „omul ăsta l-a mai luat o dată?".
-- Căutarea merge pe `promo` întâi, fiindcă rândurile cu reducere sunt puține
-- față de tot tabelul; telefonul și e-mailul se cern pe urmă.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND INDEX_NAME = 'ix_req_promo') = 0,
  'ALTER TABLE order_requests ADD KEY ix_req_promo (promo, status)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------- 2. și comanda născută din ea --

-- Comanda o poartă și ea, nu doar cererea. Panoul de comenzi nu deschide
-- cererea din care s-a născut comanda — și nici n-ar avea ce deschide pentru o
-- comandă luată la telefon —, deci fără coloana asta laboratorul ar vedea un
-- total mai mic decât catalogul, fără nimic care să spună de ce.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'promo') = 0,
  'ALTER TABLE orders
     ADD COLUMN promo VARCHAR(32) NULL AFTER total',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------- 3. prețul tăiat, pe fiecare poziție --

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_request_items'
      AND COLUMN_NAME = 'list_price') = 0,
  'ALTER TABLE order_request_items
     ADD COLUMN list_price DECIMAL(10,2) NULL AFTER unit_price',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'
      AND COLUMN_NAME = 'list_price') = 0,
  'ALTER TABLE order_items
     ADD COLUMN list_price DECIMAL(10,2) NULL AFTER unit_price',
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
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('011_reducere_pachet_test.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
