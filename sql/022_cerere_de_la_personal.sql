-- Migrarea 022 — cererea scrisă de personal
--
-- Se rulează o singură dată, după 021. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare bucată se uită întâi dacă nu e deja acolo.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/022_cerere_de_la_personal.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- HOTĂRÂREA PATRONULUI, 18 septembrie 2026: „comenzile nu apar direct în
-- sistem decât când sunt adăugate de admin/manager/fondator — restul apar ca
-- și cereri pentru ei". Până azi, `POST /orders` era deschis întregului
-- personal (`GP_STAFF_ROLES`), deci agentul de vânzări scria o comandă care
-- intra pe loc în laborator, fără ca nimeni s-o fi încuviințat.
--
-- De-acum agentul scrie o CERERE. Drumul e cel al site-ului, pe care casa îl
-- are de mult — dar cererea de pe site poartă mai puțin decât o comandă, iar
-- ce nu poartă se pierde tăcut între formular și acceptare:
--
--   · MODUL DE ONORARE. Agentul alege „Curier" fiindcă știe clientul; la
--     acceptare, `route_requests_accept()` cădea pe `default_fulfillment()`,
--     adică pe altceva, fără ca cineva să fi ales asta.
--   · URGENT și LA ORĂ FIXĂ. Amândouă se puteau cere numai la acceptare, deci
--     graba pe care o știa cel care a luat telefonul nu ajungea la cel care
--     hotărăște.
--   · CINE A SCRIS-O. `orders.created_by` există din 001 și spune cine a scris
--     comanda; cererea n-avea pereche. Fără ea, comanda născută din cererea
--     agentului ar fi purtat numele administratorului care a apăsat „Acceptă",
--     iar agentul ar fi dispărut din propria lui muncă. Tot de aici se află și
--     dacă cererea a venit de pe site sau din panou: una scrisă de personal
--     ARE autor, cea a vizitatorului nu — deci comanda născută din ea nu mai e
--     însemnată „site" pe nedrept.
--
-- Cele patru coloane sunt toate NULL sau cu implicit, deci rândurile vechi
-- rămân întregi și nemișcate: o cerere de pe site scrisă ieri n-are autor și
-- n-are mod de onorare, exact cum n-avea nici înainte.
--
-- JUMĂTATEA URCATĂ NU STRICĂ NIMIC. Fără migrarea asta, serverul vechi nu
-- citește coloanele și se poartă exact ca azi (acceptarea cade pe
-- `default_fulfillment()`); serverul nou pe o bază nemigrată le găsește
-- lipsă — v. `api/verifica.php`, care le numește pe nume în raport.

SET NAMES utf8mb4;

-- ------------------------------------------- 1. modul de onorare -----------
--
-- VARCHAR, nu ENUM, fiindcă modurile de onorare sunt o SETARE
-- (`fulfillment_types()`, api/lib/settings.php): patronul adaugă „Curier" sau
-- „Angro" din Setări, iar un ENUM ar fi cerut o migrare la fiecare nume nou.
-- Aceeași alegere ca la `orders.fulfillment`, și pentru același motiv.
--
-- NULL înseamnă „cererea nu spune" — nu „Livrare". Deosebirea contează: la
-- acceptare, `NULL` lasă `default_fulfillment()` să hotărască, exact ca azi
-- pentru cererile de pe site, pe când o valoare scrisă e alegerea unui om și
-- se respectă.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'fulfillment') = 0,
  'ALTER TABLE order_requests ADD COLUMN fulfillment VARCHAR(32) NULL AFTER address',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 2. graba și ora fixă ----------
--
-- Aceleași două steaguri ca pe comandă (`orders.urgent`, `orders.timed`), cu
-- aceeași formă, ca trecerea de la cerere la comandă să fie o copiere, nu o
-- traducere. `NOT NULL DEFAULT 0` fiindcă aici „nu știu" nu există: o cerere
-- care nu spune nimic nu e urgentă.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'urgent') = 0,
  'ALTER TABLE order_requests ADD COLUMN urgent TINYINT(1) NOT NULL DEFAULT 0 AFTER notes',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'timed') = 0,
  'ALTER TABLE order_requests ADD COLUMN timed TINYINT(1) NOT NULL DEFAULT 0 AFTER urgent',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 3. cine a scris cererea -------
--
-- `ON DELETE SET NULL`, ca la `orders.created_by`: un cont șters nu ia cu el
-- cererile scrise de el. Numele nu se mai găsește, deci nu se mai arată —
-- dar cererea rămâne întreagă, cu clientul, ziua și pozițiile ei.
--
-- NULL e chiar răspunsul pentru cererile de pe site: nu le-a scris niciun om
-- al casei. De asta coloana ține DOUĂ întrebări deodată — „cine" și „de
-- unde" — fără o a doua coloană care ar fi putut să nu se potrivească cu ea.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'created_by') = 0,
  'ALTER TABLE order_requests ADD COLUMN created_by INT UNSIGNED NULL AFTER handled_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND INDEX_NAME = 'ix_req_autor') = 0,
  'ALTER TABLE order_requests ADD KEY ix_req_autor (created_by)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cheia străină se pune DUPĂ index și numai dacă lipsește. Pusă înainte,
-- MySQL și-ar fi făcut singur un index fără nume ales, iar cel de sus ar fi
-- rămas al doilea, nefolosit.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND CONSTRAINT_NAME = 'fk_req_autor') = 0,
  'ALTER TABLE order_requests ADD CONSTRAINT fk_req_autor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
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
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('022_cerere_de_la_personal.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
