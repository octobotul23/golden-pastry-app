-- ---------------------------------------------------------------------------
-- Golden Pastry — migrare 003: ordinea opririlor pe ruta șoferului
-- ---------------------------------------------------------------------------
--
-- De rulat O SINGURĂ DATĂ, pe o bază creată înainte de această modificare.
--
-- PE SERVER, LA PRIMA INSTALARE, NU AI NEVOIE DE EL: coloanele există deja în
-- 001_schema.sql. Fișierul acesta e doar pentru baza locală de test, creată
-- înainte ca ele să fie adăugate.
--
-- IMPORTĂ-L DIN phpMyAdmin, ca pe celelalte. Dacă îl dai prin `mysql` din linia
-- de comandă pe Windows, clientul citește fișierul în codepage-ul consolei, nu
-- în UTF-8, iar diacriticele se strică: „Predată” ajunge „Predat─â”, iar
-- aplicația nu mai recunoaște etapa. Dacă totuși folosești linia de comandă,
-- adaugă --default-character-set=utf8mb4.
--
-- Motivul: ordinea opririlor era ținută în localStorage, în browserul
-- administratorului. Șoferul deschidea ruta pe telefonul lui și vedea altă
-- ordine — deși pagina scria „Șoferul o vede imediat”.
--
-- MySQL nu are `ADD COLUMN IF NOT EXISTS`, iar MariaDB îl are. Verificăm în
-- catalog și construim instrucțiunea doar dacă lipsește, ca rularea repetată
-- să nu dea eroare pe niciunul dintre ele.

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE orders ADD COLUMN route_seq SMALLINT UNSIGNED NULL AFTER total',
    'DO 0'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'orders'
    AND COLUMN_NAME  = 'route_seq'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Cele două etape speciale: care încheie comanda și care o anulează.
-- Lista de etape este editabilă, deci aplicația nu mai poate presupune
-- că „Predată” se numește așa.
INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('order_status_done', 'Predată', 0),
  ('order_status_cancelled', 'Anulată', 0);

-- Marcaj: cantitățile comenzii au fost deja scăzute din depozit.
-- Se ținea în pagina care făcuse scăderea, deci se pierdea la reîncărcare —
-- iar a doua apăsare a butonului scădea încă o dată aceleași produse.
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE orders ADD COLUMN stock_synced TINYINT(1) NOT NULL DEFAULT 0 AFTER route_seq',
    'DO 0'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'orders'
    AND COLUMN_NAME  = 'stock_synced'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Contul de sistem al tabletelor din laborator. Lipsea din seed, deci pe o
-- instalare făcută înainte de această corecție, deschiderea panoului cu cod
-- răspundea 503 („contul de panou lipsește").
INSERT IGNORE INTO users (username, password_hash, name, role, active, is_system) VALUES
  ('panou', '$2y$10$sistemsistemsistemsistemsistemsistemsistemsistemsiste',
   'Panou comun', 'angajat', 1, 1);

-- Punctul de plecare al livrărilor. Era scris în cod, cu adresa din prototip.
INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('hq_label', 'Depozit · Șos. Olteniței 103', 0),
  ('hq_lat', '44.39235', 0),
  ('hq_lng', '26.12385', 0);

-- Zonele de livrare: sectoarele Bucureștiului și Ilfov, cu punctul folosit la
-- estimarea distanțelor. Coordonatele erau scrise în cod, în trei pagini.
INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('delivery_zone_coords', '{"Sector 1":{"lat":44.4795,"lng":26.0563},"Sector 2":{"lat":44.46,"lng":26.13},"Sector 3":{"lat":44.418,"lng":26.15},"Sector 4":{"lat":44.385,"lng":26.115},"Sector 5":{"lat":44.4,"lng":26.05},"Sector 6":{"lat":44.43,"lng":26.01},"Ilfov":{"lat":44.5,"lng":26.1}}', 0);
-- Zonele se seamănă o singură dată, în 002. Aici era un UPDATE necondiționat,
-- care la fiecare re-import ștergea zonele adăugate din Setări și le punea la
-- loc pe cele din prototip: o rută de livrare într-un cartier nou dispărea în
-- tăcere. Îl înlocuim cu o sămânță care nu atinge o valoare existentă.
INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
-- Ultima zonă rămâne dinadins fără punct în `delivery_zone_coords` de mai sus:
-- vezi sql/016_zona_cont.sql, care o aduce pe bazele deja instalate.
  ('delivery_zones', '["Sector 1","Sector 2","Sector 3","Sector 4","Sector 5","Sector 6","Ilfov","În afara Bucureștiului și Ilfovului"]', 0);

-- Catalogul devine editabil din aplicație: grupele de produse primesc tabel
-- propriu, cu nume și subtitlu în ambele limbi.
CREATE TABLE IF NOT EXISTS product_categories (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug    VARCHAR(64)  NOT NULL,
  name    VARCHAR(96)  NOT NULL,
  name_en VARCHAR(96)  NOT NULL,
  note    VARCHAR(160) NULL,
  note_en VARCHAR(160) NULL,
  sort    INT          NOT NULL DEFAULT 0,
  active  TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_pcat_slug (slug),
  KEY ix_pcat_active (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semănatul se face NUMAI dacă tabelul e gol — adică o singură dată, la
-- trecerea de la o bază de dinainte de 003. `INSERT IGNORE` singur nu ajunge:
-- el sare peste ce se ciocnește de o cheie, dar o grupă pe care patronul a
-- ȘTERS-O din Catalog n-are cu ce se ciocni și s-ar întoarce la fiecare
-- actualizare, ca secțiune goală pe site. Aceeași hotărâre pentru care
-- actualizarea sare cu totul peste 002 (vezi bucla de migrări din
-- actualizeaza-vps.sh) — numai că 003 nu era sărită, fiindcă are și ALTER-e.
--
-- Numărătoarea se ia ÎNAINTE, într-o variabilă: citită în WHERE, ar fi privit
-- tabelul în timp ce scrie în el, iar primul rând inserat ar fi oprit restul.
-- Rândurile vin dintr-un UNION ALL, nu din VALUES, fiindcă numai un SELECT
-- poate avea condiție.
SET @pcat_gol := (SELECT COUNT(*) = 0 FROM product_categories);

INSERT IGNORE INTO product_categories (slug, name, name_en, note, note_en, sort)
SELECT g.* FROM (
            SELECT 'strudel'   slug, 'Ștrudel'       name, 'Strudel'      name_en, 'Foietaj crocant, trei umpluturi'   note, 'Crisp puff pastry, three fillings' note_en,  0 sort
  UNION ALL SELECT 'trigon',        'Trigon',             'Triangles',         'Foietaj, dulce și sărat',                'Puff pastry, sweet and savoury',        10
  UNION ALL SELECT 'croissant',     'Croissant',          'Croissants',        'Straturi fine, umpluturi cu cremă',      'Fine layers, cream fillings',           20
  UNION ALL SELECT 'roll',          'Roll',               'Rolls',             'Aluat dospit, gama sărată',              'Leavened dough, the savoury range',     30
  UNION ALL SELECT 'covrig',        'Covrigi',            'Pretzels',          'Aluat dospit, la bucată',                'Leavened dough, sold by the piece',     40
  UNION ALL SELECT 'corn',          'Corn',               'Horns',             'Aluat fraged, gama dulce',               'Tender dough, the sweet range',         50
  UNION ALL SELECT 'special',       'Specialități',       'Specialities',      'Rețete de casă, preț special',           'House recipes, special price',          60
) g WHERE @pcat_gol = 1;

-- Varianta în care pleacă marfa: „congelat" sau „copt". Prețul se alegea după
-- cum clientul are cont, ceea ce nu corespundea cu ce vede pe site.
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE orders ADD COLUMN variant VARCHAR(16) NOT NULL DEFAULT ''copt''',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'variant'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Varianta în care pleacă marfa: „congelat" sau „copt". Prețul se alegea după
-- cum clientul are cont, ceea ce nu corespundea cu ce vede pe site.
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE order_requests ADD COLUMN variant VARCHAR(16) NOT NULL DEFAULT ''copt''',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests' AND COLUMN_NAME = 'variant'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Prețurile nu însemnau ce spunea numele coloanelor: nu țin de tipul
-- clientului, ci de forma în care pleacă marfa.
SET @sql := (
  SELECT IF(COUNT(*) = 1,
    'ALTER TABLE products CHANGE price_horeca price_frozen DECIMAL(10,2) NULL',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'price_horeca'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(COUNT(*) = 1,
    'ALTER TABLE products CHANGE price_retail price_baked DECIMAL(10,2) NULL',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'price_retail'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Adresa de livrare a clientului, ținută pe cont. Fără ea, un client cu cont
-- trebuia să dicteze adresa la fiecare comandă, deși aplicația știa cine este.
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN address VARCHAR(190) NULL AFTER phone',
    'DO 0'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'users'
    AND COLUMN_NAME  = 'address'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
