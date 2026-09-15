-- Migrarea 008 — jumătatea comercială a recomandărilor: procent, câștig, plată
--
-- Se rulează după 007. Poate fi rulată de câte ori vrei fără pagubă: fiecare
-- pas se uită întâi în information_schema și, dacă are deja ce caută, nu face
-- nimic. Toți pașii sunt ADITIVI — nu se șterge și nu se rescrie niciun rând.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/008_comisioane_recomandari.sql
-- Rulare pe cPanel:         phpMyAdmin → baza de date → Import → acest fișier
--
-- Ce aduce:
--   1. procentul de comision, global în Setări și suprascriptibil pe cont
--   2. codul tastat de mână, pe cerere și pe comandă
--   3. registrul câștigurilor: referral_commissions
--
-- CE NU FACE, DINADINS: nu calculează nimic pe comenzile de dinainte. Un UPDATE
-- în masă peste istoric e exact operația care merge prost o singură dată și nu
-- se mai poate desface — iar rezultatul ei ar fi o datorie de bani către oameni
-- adevărați. Câștigurile încep de la prima comandă de DUPĂ import.
--
-- Ce aduce 007 și se folosește aici: users.referral_code (codul contului) și
-- users.referred_by (cine pe cine a adus). Fără ele, migrarea asta n-are pe cine
-- premia — rulează 007 întâi.

SET NAMES utf8mb4;

-- ------------------------------------------- 1. procentul, pe fiecare cont --

-- NULL nu e totuna cu 0, și de asta atârnă toată socoteala:
--
--   NULL = „ia-l pe cel global" — contul urmează procentul din Setări și se
--          mișcă odată cu el;
--   0    = „nu câștigă nimic, dinadins" — cineva a hotărât asta pentru contul
--          acesta, iar ridicarea procentului global nu-l atinge.
--
-- Cu DEFAULT 0 în loc de NULL, cele două ar arăta la fel în bază, iar prima
-- ridicare a procentului global n-ar mai ajunge la nimeni: fiecare cont ar
-- părea că are deja o hotărâre proprie.
--
-- DECIMAL(5,2) ține 0,00–999,99. Mărginirea la 0–100 se face în PHP, unde se
-- poate și explica omului ce a greșit; baza n-are cum să spună asta.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'referral_rate') = 0,
  'ALTER TABLE users ADD COLUMN referral_rate DECIMAL(5,2) NULL AFTER referred_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------- 2. codul tastat de mână --

-- Pe cerere. Cererea de pe site nu e o comandă: stă în coadă până o acceptă
-- cineva, uneori peste weekend. Codul scris în coș trebuie să aștepte acolo cu
-- ea, altfel se pierde între trimitere și acceptare.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'referral_code') = 0,
  'ALTER TABLE order_requests ADD COLUMN referral_code VARCHAR(64) NULL AFTER notes',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Pe comandă. ATENȚIE la nume: orders.ref EXISTĂ deja și NU e un cod de
-- recomandare — e numărul comenzii, „#1042", cel pe care îl știe clientul.
-- Coloana nouă se cheamă referral_code, ca pe cont, tocmai ca nimeni să nu le
-- confunde citind un SELECT.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'referral_code') = 0,
  'ALTER TABLE orders ADD COLUMN referral_code VARCHAR(64) NULL AFTER notes',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------- 3. registrul câștigurilor --
--
-- De ce un tabel, și nu o socoteală făcută din mers la fiecare afișare:
--
--   1. procentul se schimbă în timp. Calculat din mers, un câștig de anul
--      trecut s-ar rescrie singur când patronul ridică procentul azi — adică
--      istoricul plăților ar minți retroactiv;
--   2. „plătit" are nevoie de un loc unde să stea. Nu e o însușire a comenzii,
--      e o însușire a datoriei;
--   3. comanda unui vizitator fără cont n-are customer_id de care să atârne un
--      nume. Cine a cumpărat trebuie fotografiat în clipa comenzii.
--
-- Aceeași alegere ca la stock_moves: un registru lângă un marcaj.
--
-- Se scrie ca CREATE TABLE IF NOT EXISTS … ) ENGINE=…, simplu, NU împachetat în
-- PREPARE. Forma asta e deja idempotentă și e singura pe care
-- .claude/check-sql.mjs:29 o poate citi — împachetat într-un șir, tabelul ar
-- deveni invizibil pentru verificare, iar o coloană scrisă greșit în PHP n-ar
-- mai fi prinsă de nimeni.
CREATE TABLE IF NOT EXISTS referral_commissions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

  -- Comanda care a produs câștigul. UNIQUE nu e curățenie, e GARANȚIA DE
  -- IDEMPOTENȚĂ: route_orders_create reia toată tranzacția de până la cinci ori
  -- la conflict de cheie (api/routes/orders.php:167, 197-202), iar fără indexul
  -- ăsta o reluare ar plăti agentul de două ori pentru aceeași comandă.
  order_id       INT UNSIGNED NOT NULL,

  -- Copii denormalizate, ca audit_log.user_label și order_items.name. Al doilea
  -- folos, mai important decât viteza: agentul își citește câștigurile NUMAI
  -- din tabelul acesta și nu poate ajunge la orders nici din greșeală.
  order_ref      VARCHAR(24)  NULL,
  order_day      DATE         NOT NULL,

  -- Cine încasează. NULL ÎNSEAMNĂ „ținut în așteptare": codul tastat arată spre
  -- alt om decât recomandantul contului, iar patronul n-a ales încă. Nu e o
  -- lipsă de date, e o stare — vezi status mai jos.
  referrer_id    INT UNSIGNED NULL,
  referrer_label VARCHAR(128) NULL,

  -- Cine a cumpărat. Rămâne lizibil și după ce contul e șters, și există chiar
  -- când n-a fost niciun cont (comandă de la un vizitator).
  buyer_id       INT UNSIGNED NULL,
  buyer_label    VARCHAR(160) NULL,

  -- Care șir a câștigat rândul. Nu e totuna cu referrer_id: codul se poate
  -- redenumi mâine, dar faptul că în ziua aceea s-a tastat „gold2025" nu se mai
  -- schimbă. Fără el, o redenumire ar face istoricul de neexplicat.
  code_used      VARCHAR(64)  NULL,
  -- Cele două fețe ale unui conflict, păstrate amândouă ca patronul să poată
  -- alege în cunoștință de cauză: recomandantul deja legat de cont, și codul
  -- scris acum în coș.
  code_account   VARCHAR(64)  NULL,
  code_typed     VARCHAR(64)  NULL,

  -- Înghețate, ca suma să fie verificabilă pe ecran: „370,00 × 5% = 18,50".
  -- Recalculate din procentul de azi, cele trei cifre n-ar mai da niciodată
  -- socoteala pe care a văzut-o omul când a fost plătit.
  rate           DECIMAL(5,2)  NOT NULL DEFAULT 0,
  base_total     DECIMAL(10,2) NOT NULL DEFAULT 0,
  amount         DECIMAL(10,2) NOT NULL DEFAULT 0,

  -- Fără diacritice, ca singurul alt ENUM de stare din schemă
  -- (order_requests.status). Etichetele românești stau în pagină, nu în bază.
  --
  -- „platit" NU E O VALOARE DE STARE, dinadins. Plata e ortogonală: un comision
  -- plătit pe o comandă anulată ulterior trebuie să se vadă ca „anulat, dar
  -- deja plătit", ca banii să poată fi ceruți înapoi. Băgat în ENUM, rândul
  -- acela ar deveni de nerecuperat — ori pierzi anularea, ori pierzi plata.
  status         ENUM('activ','in_asteptare','anulat') NOT NULL DEFAULT 'activ',

  paid_at        DATETIME     NULL,
  paid_by        INT UNSIGNED NULL,

  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_commission_order (order_id),

  -- Comanda ștearsă ia cu ea câștigul: fără comandă nu mai există bază de
  -- calcul, iar un rând orfan ar cere bani pentru ceva ce nu se mai poate
  -- arăta. (Anularea NU șterge comanda — pentru ea e status = 'anulat'.)
  CONSTRAINT fk_commission_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  -- SET NULL, nu CASCADE: contul agentului șters n-are voie să șteargă
  -- istoricul plăților. referrer_label rămâne, deci rândul se poate încă citi.
  CONSTRAINT fk_commission_referrer FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_commission_buyer FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_commission_payer FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL,

  -- Pagina agentului: câștigurile lui, în ordinea zilelor.
  KEY ix_commission_referrer (referrer_id, order_day),
  -- Filtrul pe lună, pentru toți deodată.
  KEY ix_commission_day (order_day),
  -- Lista conflictelor de rezolvat, și totalul „activ" pe o lună.
  KEY ix_commission_status (status, order_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------- 4. procentul implicit --

-- OBLIGATORIU aici, nu doar în 002_seed.sql. settings_save() sare peste cheile
-- care nu există deja în tabel (api/lib/settings.php:292-295), deci pe o bază
-- pornită înainte de migrarea asta pagina Setări ar înghiți valoarea în tăcere:
-- patronul scrie „5", apasă Salvează, primește „ok" — și nu se schimbă nimic.
-- Rândul trebuie să existe ca să se poată scrie peste el.
--
-- Zero, nu cinci. O valoare inventată de noi ar face ca prima comandă de după
-- actualizare să nască o datorie de bani pe care nimeni n-a hotărât-o.
--
-- ÎNTRE TIMP S-A HOTĂRÂT. Rândul de mai jos rămâne cum a fost — o migrare
-- aplicată nu se rescrie — dar zeroul lui nu mai apucă să însemne ceva:
-- `sql/017_procent_recomandare.sql` îl aduce la 5 imediat după, și numai cât
-- timp nu l-a atins niciun om. Judecata de sus se schimbase într-o capcană:
-- recomandările mergeau, se numărau, și ieșeau pe ecran cu 0,00 RON, fiindcă
-- nimeni nu-i spusese patronului că procentul e zero. Vezi 017 pentru toată
-- pățania.
INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('referral_rate_default', '0', 0);
