-- Migrarea 005 — data la care contul și-a schimbat singur parola
--
-- Se rulează o singură dată, după 004. Poate fi rulată de mai multe ori fără
-- pagubă: pasul verifică întâi dacă are ce face.
--
-- Motivul: pagina Conturi scria în dreptul fiecărui cont „parolă schimbată
-- <dată>", dar data afișată era `last_login` — ultima autentificare. Un cont
-- care intrase azi cu parola primită de la administrator apărea drept
-- „parolă schimbată azi". Administratorul se uită tocmai la coloana asta ca
-- să afle dacă parola dictată la telefon mai e în circulație, deci pagina îi
-- răspundea exact pe dos. Baza nu avea de unde ști adevărul: nu exista nicio
-- coloană cu momentul schimbării. Acum există.
--
-- Rămâne NULL cât timp parola de pe cont e cea pusă de administrator. Se
-- completează doar când omul își schimbă singur parola — din pagina „Parola
-- mea" sau printr-un link de recuperare.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_changed_at') = 0,
  'ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER must_change_password',
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
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('005_parola_schimbata.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
