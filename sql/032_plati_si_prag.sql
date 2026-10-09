-- Migrarea 032 — plata fiecărei comenzi și pragul de comenzi neplătite
--
-- Se rulează o singură dată, după 031. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare coloană și fiecare index se adaugă numai dacă lipsește,
-- rândurile din `settings` se pun cu INSERT IGNORE, iar comenzile vechi se
-- judecă o singură dată, ținute de un semn în `settings`. Sub același semn stă
-- și singura scriere care nu ține de bani: vederea magazinului pe telefon.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/032_plati_si_prag.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- CEREREA PATRONULUI, 4 octombrie 2026: să se țină o condică a banilor — cine a
-- plătit, cine nu, care e soldul, câți bani mai trebuie să intre — și un prag:
-- a doua comandă neplătită trece cu avertizare, a treia nu mai trece până nu
-- se plătește prima.
--
-- PE COMANDĂ, șapte coloane. Stau pe `orders` și nu într-un tabel al lor,
-- fiindcă plata e mereu a comenzii întregi: nu se plătește jumătate.
--
--   `plata`              una din patru stări: neplatita · amanata · platita ·
--                        banca („plătită prin bancă"). ENUM, nu text liber ca
--                        `status`: lista asta nu se editează din Setări, e a
--                        codului, iar o a cincea stare scrisă greșit ar scoate
--                        comanda din toate socotelile deodată.
--   `plata_scadenta`     ziua până la care s-a amânat. Numai la `amanata`.
--   `plata_la`           clipa în care s-a bifat plata (UTC, ca toate datele
--                        cu oră din bază).
--   `plata_de`           contul care a schimbat ultima dată starea.
--   `plata_suma`         totalul comenzii ÎN CLIPA plății. O comandă schimbată
--                        după ce a fost plătită se vede așa, la „Diferențe".
--   `plata_nota`         o vorbă lăsată de cine a schimbat starea.
--   `plata_amintire_la`  ultima zi în care s-a trimis amintirea de scadență pe
--                        Telegram — ca să plece o singură dată pe zi, oricât de
--                        des ar rula ceasul de pe server (api/cron/plati.php).
--
-- „ANULATĂ" NU E O STARE DE PLATĂ. O comandă anulată nu se numără la nimic, iar
-- asta se citește din etapa ei (`status_cancelled()`), nu de aici: altfel ar fi
-- două locuri de ținut în pas, și primul uitat ar lăsa o comandă anulată în
-- soldul clientului.
--
-- `DEFAULT 'neplatita'` e ales cu grijă: niciun INSERT de comandă din cod nu
-- trebuie atins. Comanda nouă se naște neplătită, cum e și în lume.
--
-- PE CONT, o coloană:
--
--   `prag_neplatite`  NULL = pragul general (din Setări); 0 = fără limită;
--                     1…99 = pragul lui anume. Se pune din Conturi.
--
-- ÎN `settings`, șapte rânduri:
--
--   `plati_prag_implicit`      câte comenzi neplătite poate avea un client. 2.
--   `plati_telegram_chat_id`   chatul în care pleacă raportul de bani. ALTUL
--                              decât cel al comenzilor, la cererea patronului.
--   `plati_raport_ore`         cele două ore ale raportului, „09:00,18:00".
--   `plati_amintiri`           1 = în ziua scadenței pleacă și o amintire.
--   `plati_raport_trimis_1/2`  ziua în care a plecat ultima oară fiecare raport
--                              — de ele se leagă „o singură dată pe zi".
--   `panel_base_url`           adresa panoului, pentru butonul din Telegram.
--
-- COMENZILE DE DINAINTE — hotărârea patronului din 4 octombrie:
--
--   * cele PREDATE (etapa finală) cu ziua trecută  → plătite;
--   * toate celelalte                              → neplătite (adică rămân pe
--     DEFAULT, migrarea nu le atinge);
--   * cele anulate nu se numără oricum.
--
-- Fără judecata asta, în ziua urcării fiecare client vechi ar fi apărut cu tot
-- istoricul lui „de plată", iar pragul l-ar fi oprit de la prima comandă nouă.
-- Ce nu se potrivește — o comandă predată și neîncasată — se îndreaptă cu mâna,
-- din pagina Încasări; foaia de urcare o spune.
--
-- Etapa finală se citește din `order_status_done`. Dacă rândul lipsește ori e
-- gol, NU se marchează nimic: migrarea nu ghicește care e etapa „predată" pe o
-- instalare cu lista schimbată.
--
-- DE CE UN LACĂT, ca la `027`, `029` și `031`. Fiindcă migrările rulează TOATE,
-- la fiecare actualizare. Fără lacăt, o comandă predată pe care patronul a
-- trecut-o înapoi pe „neplătită" (chiar asta i se cere să facă, după urcare)
-- ar fi făcută la loc „plătită" la următorul plasture.
--
-- ȘI O HOTĂRÂRE CARE NU ȚINE DE BANI: VEDEREA MAGAZINULUI PE TELEFON.
--
-- CEREREA PATRONULUI, 9 octombrie 2026: „Pe site vreau ca al 2-lea view să fie
-- default, cel cu 4." Pe calculator era deja așa. Pe telefon și tabletă, `028`
-- pusese „unu": un produs pe rând. Rândul `catalog_vedere_telefon` se mută de
-- pe `unu` pe `patru`, o singură dată.
--
-- NUMAI de pe `unu`, valoarea de pornire a lui `028`: o vedere aleasă între
-- timp din Setări — „șase", de pildă — e a patronului și rămâne. Și numai o
-- dată, din același motiv ca judecata comenzilor: dacă patronul se întoarce,
-- după urcare, la „unul pe rând", plasturele următor nu are voie să i-o mute
-- la loc.
--
-- De ce stă AICI, și nu într-o migrare a ei: e o singură scriere, într-un rând
-- care există deja, și are nevoie exact de lacătul ăsta — „o dată, la urcarea
-- rundei". Un al doilea semn în `settings` pentru un singur rând ar fi fost
-- încă un lucru de ținut în pas. Tot așa a adus `028`, migrarea prețurilor pe
-- trepte, și rândurile vederilor: ce hotărăște runda pleacă într-un loc.
--
-- Urmarea, pe magazin: cine își alesese altă vedere pe telefon o pierde și
-- vede „patru" — o alegere ține numai cât Setările spun același lucru ca în
-- clipa ei (v. `CHEIE_AFISARE`, Site Public.dc.html). Așa a fost gândit: o
-- vedere implicită nouă ajunge la toată lumea.
--
-- CE NU FACE:
--   * nu atinge vederea de pe calculator și nu pune niciun rând nou: pe o bază
--     fără `028` nu are ce muta, iar magazinul se deschide pe valoarea de
--     rezervă din cod, tot „patru" (`catalog_implicite()`, api/lib/catalog.php);
--   * nu atinge etapa, totalul ori ziua niciunei comenzi;
--   * nu scrie `plata_la`: nu se știe CÂND s-au plătit comenzile vechi, iar o
--     dată pusă din burtă le-ar fi băgat pe toate în „încasat azi";
--   * nu pune niciun prag pe niciun cont (`prag_neplatite` rămâne NULL);
--   * nu trimite nimic pe Telegram.

SET NAMES utf8mb4;

-- ------------------------------------------- 1. coloanele comenzii ---------
--
-- `AFTER total`, apoi una după alta: `total` e din 001, deci migrarea trece și
-- importată de mână pe o bază căreia îi lipsește vreo migrare dintre ele.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'plata') = 0,
  'ALTER TABLE orders
     ADD COLUMN plata ENUM(''neplatita'',''amanata'',''platita'',''banca'') NOT NULL DEFAULT ''neplatita'' AFTER total',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'plata_scadenta') = 0,
  'ALTER TABLE orders ADD COLUMN plata_scadenta DATE NULL AFTER plata',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'plata_la') = 0,
  'ALTER TABLE orders ADD COLUMN plata_la DATETIME NULL AFTER plata_scadenta',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'plata_de') = 0,
  'ALTER TABLE orders ADD COLUMN plata_de INT UNSIGNED NULL AFTER plata_la',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'plata_suma') = 0,
  'ALTER TABLE orders ADD COLUMN plata_suma DECIMAL(10,2) NULL AFTER plata_de',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'plata_nota') = 0,
  'ALTER TABLE orders ADD COLUMN plata_nota VARCHAR(255) NULL AFTER plata_suma',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'plata_amintire_la') = 0,
  'ALTER TABLE orders ADD COLUMN plata_amintire_la DATE NULL AFTER plata_nota',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 2. cele două indexuri ---------
--
-- `ix_orders_plata` e al pragului: „câte comenzi neplătite are clientul ăsta"
-- se întreabă la FIECARE comandă nouă, sub lacăt. `ix_orders_plata_la` e al
-- condicii: „ce s-a încasat în perioada asta".

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'ix_orders_plata') = 0,
  'ALTER TABLE orders ADD KEY ix_orders_plata (customer_id, plata)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'ix_orders_plata_la') = 0,
  'ALTER TABLE orders ADD KEY ix_orders_plata_la (plata_la)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 3. pragul de pe cont ----------
--
-- `AFTER role`: coloană din 001. NULL din pornire, adică „pragul general".

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'prag_neplatite') = 0,
  'ALTER TABLE users ADD COLUMN prag_neplatite SMALLINT UNSIGNED NULL AFTER role',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 4. rândurile din Setări -------
--
-- INSERT IGNORE: o valoare schimbată de patron nu se calcă la a doua rulare.
-- Cele două rânduri „trimis" pornesc goale — „nu s-a trimis niciodată".

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('plati_prag_implicit', '2', 0),
  ('plati_telegram_chat_id', '', 0),
  ('plati_raport_ore', '09:00,18:00', 0),
  ('plati_amintiri', '1', 0),
  ('plati_raport_trimis_1', '', 0),
  ('plati_raport_trimis_2', '', 0),
  ('panel_base_url', 'https://lab.goldenpastry.ro', 0);

-- ------------------------------------------- 5. semnul, citit ÎNTÂI --------
--
-- Se citește înaintea judecății de mai jos, din același motiv ca la `027`,
-- `029` și `031`: citit după, ar spune mereu „s-a făcut".

SET @plati_judecate := (SELECT COUNT(*) FROM settings
                          WHERE setting_key = 'migration_032_plati_si_prag');

-- ------------------------------------------- 6. comenzile vechi, o dată ----
--
-- Etapa finală, cum o citește și serverul (`status_done()`): din setare. Goală
-- ori lipsă = nu se marchează nimic (v. capul fișierului).
--
-- `day < CURDATE()`: o comandă predată AZI rămâne neplătită — banii ei poate
-- nu s-au numărat încă, iar patronul o bifează din Încasări într-o apăsare.
-- `plata_suma = total`: pentru comenzile vechi, „cât s-a plătit" e „cât făcea".

SET @plati_gata := COALESCE((SELECT value FROM settings
                              WHERE setting_key = 'order_status_done'), '');

SET @sql := IF(@plati_judecate = 0 AND @plati_gata <> '',
  'UPDATE orders
      SET plata = ''platita'', plata_suma = total, plata_nota = ''migrare 032''
    WHERE plata = ''neplatita''
      AND status = @plati_gata
      AND day < CURDATE()',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 7. vederea de pe telefon, o dată
--
-- Hotărârea din 9 octombrie (v. capul fișierului). `value = 'unu'`: numai
-- rândul care mai ține valoarea de pornire a lui `028`. `updated_by` rămâne
-- cum era: schimbarea e a migrării, nu a unui cont.

SET @sql := IF(@plati_judecate = 0,
  'UPDATE settings
      SET value = ''patru''
    WHERE setting_key = ''catalog_vedere_telefon''
      AND value = ''unu''',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 8. semnul, scris la urmă ------
--
-- `INSERT IGNORE`, deci a doua rulare nu-l mișcă și nu schimbă data. De aici
-- încolo starea plății e a patronului: ce trece el pe „neplătită" rămâne așa,
-- oricâte actualizări ar veni. Și vederea de pe telefon, la fel.

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('migration_032_plati_si_prag', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), 0);

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`. Rândul de mai jos se
-- scrie numai dacă registrul e acolo — importată singură, de mână, pe o bază
-- fără `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('032_plati_si_prag.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
