-- Migrarea 013 — ce anunț pe ce canal, și butoanele din Telegram
--
-- Se rulează o singură dată, după 012. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare pas se uită întâi în information_schema și, dacă are deja ce
-- caută, nu face nimic. Nu șterge și nu rescrie niciun rând existent.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/013_anunturi_pe_canal.sql
-- Rulare pe cPanel:         phpMyAdmin → baza de date → Import → acest fișier
--
-- Ce aduce, și DE CE
-- ==================
--
-- Până acum canalul era un întrerupător: pornit însemna „primești TOT",
-- oprit însemna „nu primești nimic". Iar „tot" erau exact două lucruri —
-- comandă nouă și cerere nouă — fiindcă restul faptelor zilei nu anunțau pe
-- nimeni, nicăieri. Cine voia comenzile pe Telegram și numai cererile pe
-- e-mail nu avea de unde alege; cine voia să afle că s-a terminat făina afla
-- abia când intra în Depozit.
--
-- De aici încolo fiecare canal are LISTA lui de evenimente, ca trei cutii
-- poștale deosebite. Listele se seamănă cu exact ce se trimitea până acum —
-- `["request_new","order_new"]` — deci o actualizare nu schimbă nimic până
-- când patronul nu bifează el altceva. E aceeași grijă ca la procentul de
-- recomandare din 008, semănat cu zero: o migrare n-are voie să înceapă
-- singură să trimită mesaje pe care nu le-a cerut nimeni.
--
-- DE CE O LISTĂ JSON ȘI NU UN TABEL DE LEGĂTURĂ. Trei canale ori șase
-- evenimente înseamnă cel mult optsprezece bifе, citite în bloc la fiecare
-- anunț și scrise toate deodată dintr-un singur formular. Un tabel ar fi adus
-- optsprezece rânduri, o interogare în plus pe fiecare comandă și o a doua
-- cale prin care starea poate rămâne pe jumătate scrisă. Listele configurabile
-- ale aplicației (etape, intervale, zone) stau deja tot așa, în `settings`, și
-- trec prin aceleași `setting_list()` / `validate_lists()`.
--
-- BUTOANELE DIN TELEGRAM. O cerere de pe site ajunge pe telefon cu trei
-- butoane sub ea — „Acceptă", „Respinge", „Mai târziu" — iar apăsarea lor
-- face exact ce ar fi făcut apăsarea din panou. Ca să fie cu putință, Telegram
-- trebuie să poată suna înapoi la server: de aceea `notify_telegram_webhook_secret`,
-- un secret pe care îl generăm noi și pe care Telegram îl trimite înapoi în
-- antetul fiecărei cereri. Fără el, adresa aceea ar fi o ușă deschisă spre
-- acceptarea cererilor, știută de oricine ghicește un nume de rută.
--
-- Și `notify_telegram_actor_id`: apăsarea din Telegram se scrie în jurnal pe
-- numele omului care a legat botul, nu pe „nimeni". O faptă fără autor e o
-- faptă pe care n-o mai poate lămuri nimeni la o reclamație.

-- ------------------------------------ 1. ce eveniment pe ce canal --

-- Semănate cu purtarea de azi, nu cu „tot": cele două anunțuri care se
-- trimiteau deja. INSERT IGNORE, ca a doua rulare să nu calce peste ce a
-- bifat între timp patronul.
INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('notify_email_events',    '["request_new","order_new"]', 0),
  ('notify_whatsapp_events', '["request_new","order_new"]', 0),
  ('notify_telegram_events', '["request_new","order_new"]', 0);

-- ------------------------------------------ 2. butoanele din Telegram --

-- `notify_telegram_buttons`  — pornit/oprit, pus pe „1" fiindcă butoanele nu
--   pleacă nicăieri singure: fără webhook legat, apăsarea lor doar spune
--   „botul nu e legat". Iar cine leagă botul vrea tocmai butoanele.
-- `notify_telegram_webhook_secret` — criptat, ca tokenul. Cine îl are poate
--   accepta cereri.
-- `notify_telegram_webhook_status` — ce s-a întâmplat la ultima legare, ca
--   panoul să nu fie nevoit să întrebe Telegramul de fiecare dată.
-- `notify_telegram_actor_id` — pe numele cui se scriu în jurnal apăsările.
INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('notify_telegram_buttons',        '1',            0),
  ('notify_telegram_webhook_secret', '',             1),
  ('notify_telegram_webhook_status', 'nelegat',      0),
  ('notify_telegram_actor_id',       '',             0);

-- ---------------------------------- 3. anunțul își spune felul --

-- Caseta „anunțuri care nu au plecat" din Setări arăta canalul și cauza, dar
-- nu și DESPRE CE era anunțul: un eșec la „cont nou" și unul la „comandă nouă"
-- se citeau la fel. Cu șase feluri de anunț în loc de două, deosebirea începe
-- să conteze — mai ales fiindcă de acum un canal poate să nu fi fost bifat
-- deloc pentru felul acela, și atunci nu e nicio defecțiune.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
      AND COLUMN_NAME = 'event') = 0,
  'ALTER TABLE notifications
     ADD COLUMN event VARCHAR(32) NULL AFTER channel',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Rândurile de dinaintea migrării: tot ce s-a trimis până acum a fost ori o
-- comandă, ori o cerere, ori un test. Comanda se recunoaște după `order_id`;
-- restul rămân nemarcate, fiindcă ghicitul ar fi o minciună scrisă în tabel.
UPDATE notifications SET event = 'order_new' WHERE event IS NULL AND order_id IS NOT NULL;

-- ------------------------------- 4. cererea atinsă din Telegram --

-- `order_requests` ține minte cine a acceptat și când. O apăsare din Telegram
-- trece prin exact aceeași rută ca apăsarea din panou, deci scrie în aceleași
-- coloane — dar nu se mai poate afla PE UNDE a venit apăsarea. Coloana de mai
-- jos o spune, și e singurul loc din care se poate lămuri, la o reclamație, de
-- ce o cerere a fost acceptată la 23:40 de pe telefon.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'decided_via') = 0,
  'ALTER TABLE order_requests
     ADD COLUMN decided_via VARCHAR(16) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------- 5. „Mai târziu" are o oră --

-- Butonul al treilea nu e o hotărâre, e o amânare: cererea rămâne „nouă", dar
-- se ține minte până când a fost pusă deoparte, ca să nu i se mai trimită
-- același anunț din cinci în cinci minute și ca lista din panou să poată
-- arăta, la o adică, ce a lăsat patronul pentru dimineață.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'snoozed_until') = 0,
  'ALTER TABLE order_requests
     ADD COLUMN snoozed_until DATETIME NULL',
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
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('013_anunturi_pe_canal.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
