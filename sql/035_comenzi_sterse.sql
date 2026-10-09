-- Migrarea 035 — ce era într-o comandă ștearsă
--
-- Se rulează o singură dată, după 034. Poate fi rulată de câte ori vrei fără
-- pagubă: `CREATE TABLE IF NOT EXISTS` nu atinge un tabel care există deja, și
-- migrarea nu scrie niciun rând în el.
--
-- „NU ATINGE” ÎNSEAMNĂ ȘI CĂ NU-L ÎNDREAPTĂ. Ce se adaugă în `CREATE TABLE`
-- după ce tabelul a fost făcut o dată nu mai ajunge pe baza aceea, oricâte
-- rulări ar urma. E cazul cheii `ix_orders_deleted_request`: v. sub tabel.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/035_comenzi_sterse.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- CEREREA PATRONULUI, 4 octombrie 2026: pe o comandă deja anulată, butonul să
-- întrebe dacă vrei s-o ștergi; ștearsă, să dispară din registru — „dar dacă
-- văd ștergerea, să pot vedea ce era comanda".
--
-- Ștergerea e adevărată: rândul pleacă din `orders`, cu pozițiile și
-- rezervările lui. Ce rămâne e o POZĂ a comenzii, făcută în aceeași tranzacție,
-- ÎNAINTEA ștergerii:
--
--   `order_id`          numărul pe care îl avea rândul în `orders`. Unic: o
--                       comandă se șterge o singură dată.
--   `ref`, `seq`, `day`, `customer_id`, `customer_name`, `total`, `request_id`
--                       câteva câmpuri scoase din poză, ca Jurnalul să poată
--                       căuta și arăta fără să desfacă JSON-ul.
--   `snapshot`          comanda întreagă, ca JSON: antetul, fiecare poziție cu
--                       varianta, cantitatea și prețul, comisionul, cererea din
--                       care s-a născut, cine a scris-o, starea plății.
--   `deleted_by`, `deleted_by_label`, `deleted_at`
--                       cine a șters-o și când. Numele e ținut și ca text, ca în
--                       `audit_log.user_label`: poza trebuie să rămână lizibilă
--                       și după ce contul acela nu mai există.
--   `audit_id`          rândul din Jurnal al ștergerii (`order_delete`).
--
-- FĂRĂ NICIO CHEIE STRĂINĂ, dinadins. Tabelul ăsta e o arhivă: comanda pe care
-- o descrie nu mai există, iar clientul, cererea ori contul care a șters-o pot
-- dispărea și ele. O cheie străină ar fi șters poza odată cu clientul (CASCADE)
-- ori i-ar fi golit câmpurile (SET NULL) — adică exact ce nu vrea patronul de
-- la o urmă.
--
-- DE CE UN TABEL, și nu doar un rând lung în Jurnal. `audit_log.detail` e TEXT
-- — 64 de mii de octeți —, iar o comandă angro cu multe poziții, cu numele și
-- notele ei, îl depășește; tăiată, poza n-ar mai fi JSON. În plus Jurnalul se
-- citește de cine are panoul „Jurnal", iar acolo detaliul e proză de citit, nu
-- un document de desfăcut.
--
-- NUMĂRUL COMENZII SE POATE REFOLOSI. Numerotarea e pe zi (`uq_orders_day_seq`),
-- iar ștergerea eliberează numărul: o comandă nouă din aceeași zi îl poate lua.
-- De aceea poza își ține `ref`-ul ei și Jurnalul nu leagă ștergerea de „comanda
-- cu numărul acela" — legătura ar duce la altă comandă.
--
-- CE NU FACE:
--   * nu șterge și nu mută nicio comandă: tabelul pornește gol;
--   * nu are semn în `settings`: nu face nimic „o singură dată".

SET NAMES utf8mb4;

-- ------------------------------------------- arhiva comenzilor șterse ------
--
-- `MEDIUMTEXT` pentru poză (16 MB), nu `JSON`: se scrie o dată și se citește
-- întreagă, iar MariaDB-ul unei instalări vechi ține `JSON` tot ca text.

CREATE TABLE IF NOT EXISTS orders_deleted (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id         INT UNSIGNED NOT NULL,
  ref              VARCHAR(24)  NULL,
  seq              INT UNSIGNED NOT NULL DEFAULT 0,
  day              DATE         NOT NULL,
  customer_id      INT UNSIGNED NULL,
  customer_name    VARCHAR(160) NULL,
  total            DECIMAL(10,2) NOT NULL DEFAULT 0,
  request_id       INT UNSIGNED NULL,
  snapshot         MEDIUMTEXT   NOT NULL,
  deleted_by       INT UNSIGNED NULL,
  deleted_by_label VARCHAR(128) NULL,
  deleted_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  audit_id         INT UNSIGNED NULL,
  UNIQUE KEY uq_orders_deleted_order (order_id),
  KEY ix_orders_deleted_at (deleted_at),
  KEY ix_orders_deleted_customer (customer_id, day),
  -- Jurnalul caută comanda ștearsă a unei cereri: fără el, citea toată arhiva.
  KEY ix_orders_deleted_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CHEIA `ix_orders_deleted_request` ȘI BAZELE CARE AU DEJA TABELUL.
--
-- Cheia a intrat în `CREATE TABLE` de mai sus pe 9 octombrie 2026. O bază pe
-- care 035 a rulat ÎNAINTE de ziua aceea are tabelul fără ea, iar rularea din
-- nou a fișierului n-o pune: `IF NOT EXISTS` sare tabelul cu totul (iar
-- registrul migrărilor sare fișierul întreg). Așa a rămas baza de lucru
-- `gp_dev`: acolo cheia se pune de mână. O bază care rulează 035 pentru prima
-- oară cu rândul de mai sus în el o are din prima.
--
-- Cum se vede dacă lipsește (un răspuns gol = lipsește):
--   SHOW INDEX FROM orders_deleted WHERE Key_name = 'ix_orders_deleted_request';
-- Cum se pune, o dată, de mână:
--   ALTER TABLE orders_deleted ADD KEY ix_orders_deleted_request (request_id);
--
-- Fără ea nimic nu se strică: fereastra unei cereri din Jurnal doar citește
-- toată arhiva la fiecare deschidere. Migrarea n-o pune singură — 035 a rămas,
-- dinadins, numai `CREATE TABLE` (hotărârea încheierilor rundei); dacă se mai
-- găsește o bază rămasă fără cheie, leacul e un `ALTER` păzit, ca în 020 și 032.

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`. Rândul de mai jos se
-- scrie numai dacă registrul e acolo — importată singură, de mână, pe o bază
-- fără `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('035_comenzi_sterse.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
