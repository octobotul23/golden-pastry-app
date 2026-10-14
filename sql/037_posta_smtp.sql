-- Migrarea 037 — căsuța de e-mail (SMTP) capătă rânduri în `settings`
--
-- Se rulează o singură dată, după 036. Poate fi rulată de câte ori vrei fără
-- pagubă: `INSERT IGNORE` nu atinge un rând care există deja, deci o căsuță pe
-- care ai completat-o tu rămâne a ta, cu parola ei cu tot.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/037_posta_smtp.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- PROBLEMA DE REZOLVAT: E-MAILUL „ȚI-AI UITAT PAROLA?” NU MAI AJUNGE. Serverul
-- trimitea e-mail pe un singur drum, funcția `mail()` din PHP — adică „dă-i
-- mesajul programului de poștă al serverului”. Site-ul s-a mutat pe un VPS pe
-- care nu e instalat niciun program de poștă. `mail()` întoarce acolo „nu”,
-- fără niciun cuvânt, iar pagina de intrare răspundea mai departe „am trimis un
-- e-mail cu instrucțiuni”.
--
-- HOTĂRÂREA PATRONULUI (runda 2.7.5): AMÂNDOUĂ DRUMURILE. E-mailul pleacă prin
-- căsuța firmei (SMTP), când e completată în Setări → Notificări → E-mail;
-- altfel direct de pe server, ca până acum. Pentru primul drum trebuie un loc
-- în care să se scrie căsuța — iar `settings_save()` (api/lib/settings.php)
-- scrie NUMAI peste cheile care au deja rând în tabel. Fără rândurile de aici,
-- casetele din Setări stau închise și numesc migrarea asta.
--
-- CELE CINCI RÂNDURI
-- ==================
--
--   mail_smtp_host      gazda serverului de e-mail (de pildă mail.domeniul.ro).
--                       GOALĂ din pornire — și ea e comutatorul: câtă vreme e
--                       goală, e-mailul pleacă direct de pe server, exact ca
--                       înainte de migrare. Migrarea nu schimbă deci nimic din
--                       ce face site-ul în clipa actualizării.
--   mail_smtp_port      465 din pornire (legătură criptată de la primul octet).
--   mail_smtp_user      utilizatorul căsuței (de obicei chiar adresa ei).
--   mail_smtp_password  parola căsuței. SECRET (`is_secret` = 1): se ține
--                       criptată, ca tokenurile de Telegram și WhatsApp, și nu
--                       se mai întoarce niciodată spre pagină — nici măcar
--                       ultimele ei semne. O scrie patronul, din Setări; nu
--                       trece prin niciun fișier.
--   mail_smtp_security  „auto” din pornire: după port (465 → criptat de la
--                       început, oricare altul → STARTTLS). Se mai poate
--                       „ssl” ori „starttls”. Necriptat nu există ca alegere.
--
-- Codul nu cere migrarea: pe o bază fără rândurile astea, `smtp_date()`
-- (api/lib/smtp.php) citește gol și poșta merge pe drumul direct.

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('mail_smtp_host', '', 0),
  ('mail_smtp_port', '465', 0),
  ('mail_smtp_user', '', 0),
  ('mail_smtp_password', '', 1),
  ('mail_smtp_security', 'auto', 0);

-- ---------------------------------------------------- registrul migrărilor --
-- Își scrie numele în registru, dacă registrul există (migrarea 025). Vezi
-- capul lui `025_registru_migrari.sql`.
SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('037_posta_smtp.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
