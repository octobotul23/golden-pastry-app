-- Migrarea 004 — varianta pe fiecare poziție, adresa contului, notificări văzute
--
-- Se rulează o singură dată, după 003. Poate fi rulată de mai multe ori fără
-- pagubă: fiecare pas verifică întâi dacă are ce face.
--
-- 1. Varianta („congelat" / „copt") se mută de pe comandă pe fiecare poziție.
--    Până acum, coșul de pe site ținea corect ambele variante — cheile sunt
--    separate, `frozen:3` și `baked:3` — dar spre server pleca o singură
--    variantă pentru toată comanda. Clientul vedea o sumă pe ecran, comanda se
--    înregistra cu alta, iar laboratorul primea altă marfă decât cea cerută.
--
-- 2. Adresa de pe cont încăpea în 190 de caractere, iar cea din cerere în 255.
--    O adresă lungă trecea de cerere și pica la scrierea pe cont, cu „Eroare
--    internă" în fața clientului.

-- --------------------------------------------------------------- adrese --

-- Aceeași lungime peste tot: cerere (255), comandă (255), cont (255).
SET @sql := IF(
  (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'address') < 255,
  'ALTER TABLE users MODIFY address VARCHAR(255) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------- varianta pe poziție --

-- Pozițiile existente păstrează varianta comenzii din care fac parte, ca
-- istoricul să rămână adevărat.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'variant') = 0,
  'ALTER TABLE order_items ADD COLUMN variant VARCHAR(16) NOT NULL DEFAULT ''copt'' AFTER name',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE order_items i
  JOIN orders o ON o.id = i.order_id
   SET i.variant = o.variant
 WHERE o.variant IN ('congelat', 'copt')
   AND i.variant <> o.variant;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_request_items' AND COLUMN_NAME = 'variant') = 0,
  'ALTER TABLE order_request_items ADD COLUMN variant VARCHAR(16) NOT NULL DEFAULT ''copt'' AFTER name',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE order_request_items i
  JOIN order_requests r ON r.id = i.request_id
   SET i.variant = r.variant
 WHERE r.variant IN ('congelat', 'copt')
   AND i.variant <> r.variant;

-- ------------------------------------------------------- notificări văzute --

-- Un anunț eșuat se scria în `notifications` cu status „failed", dar nicio
-- pagină nu citea tabelul: nimeni nu afla niciodată. Coloana de mai jos ține
-- momentul în care cineva a văzut eșecul în Setări, ca lista să se poată goli.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'seen_at') = 0,
  'ALTER TABLE notifications ADD COLUMN seen_at DATETIME NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
