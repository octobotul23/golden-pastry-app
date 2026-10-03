-- Migrarea 031 — fișa tehnică a fiecărui produs
--
-- Se rulează o singură dată, după 030. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare coloană se adaugă numai dacă lipsește, iar fișele din pachet
-- se leagă de produse o singură dată, ținute de un semn în `settings`.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/031_fise_tehnice.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- CEREREA PATRONULUI, 2 octombrie 2026: fișele tehnice ale produselor (un PDF
-- cu 22 de pagini, făcut pe 16 iulie) să poată fi descărcate din magazin, de
-- pe pagina fiecărui produs.
--
-- PE PRODUS, două locuri — câte unul de latură, ca la poze (`024`, `027`):
--
--   `sheet_frozen`   fișa produsului congelat
--   `sheet_baked`    fișa produsului gata de servire
--
-- În coloană stă calea fișierului, așa cum o cere browserul: `fise/…` pentru
-- o fișă venită odată cu pachetul, `products/fisa-<id>-<8 hex>.pdf` pentru una
-- încărcată de patron din Catalog. NULL înseamnă „fără fișă”, și atunci
-- magazinul nu arată niciun buton.
--
-- COAPTE ÎMPRUMUTĂ FIȘA DE LA CONGELATE cât timp n-are una a ei. Împrumutul
-- NU se scrie aici: `sheet_baked` rămâne NULL, iar serverul întoarce fișa
-- congelată pentru latura coaptă dintr-un singur loc (`latura_fisa()`,
-- api/lib/laturi.php). Scris în bază, împrumutul n-ar mai putea fi deosebit de
-- o fișă pusă dinadins, iar ziua în care patronul schimbă fișa congelată ar
-- lăsa-o pe cea veche la Coapte.
--
-- CE LEAGĂ: cele 21 de fișe din folderul `fise/` al pachetului, după `slug`,
-- de latura congelată. Fișele sunt tăiate din PDF-ul patronului de
-- `.claude/taie-fise.mjs`, iar numele lor e `fisa-tehnica-<slug>-congelat.pdf`.
-- Trei lucruri care nu se văd din listă:
--
--   * „Covrig cu susan / mac / semințe” are o fișă cu DOUĂ pagini (susan și
--     mac), fiindcă pe site e un singur produs;
--   * „Trigon cu vanilie și fructe” n-are fișă: patronul n-a trimis una. Rămâne
--     NULL și magazinul nu-i arată butonul;
--   * un produs căruia patronul i-a schimbat `slug`-ul nu e găsit și rămâne
--     fără fișă. I se încarcă una din Catalog.
--
-- DE CE UN LACĂT, ca la `027` și `029`. Fiindcă migrările rulează TOATE, la
-- fiecare actualizare (`actualizeaza-vps.sh`). Fără lacăt, o fișă SCOASĂ de
-- patron din Catalog (greșită, ori a unui produs schimbat) ar avea coloana
-- goală — iar următoarea urcare i-ar pune-o la loc. `WHERE sheet_frozen IS
-- NULL` singur nu ajunge: tocmai „goală” e starea pe care a ales-o el.
--
-- CE NU FACE:
--   * nu atinge greutatea, prețul ori descrierea niciunui produs. Fișele
--     tipăresc pe alocuri alte greutăți decât site-ul; care e cea bună
--     hotărăște patronul, din Catalog, nu o migrare;
--   * nu scrie nimic în `sheet_baked`: vezi împrumutul de mai sus;
--   * nu calcă peste o fișă deja pusă (`WHERE sheet_frozen IS NULL`);
--   * nu șterge și nu mută niciun fișier: migrarea scrie numai căi.

SET NAMES utf8mb4;

-- ------------------------------------------- 1. cele două coloane ----------
--
-- Același tip ca `photo_baked` (`VARCHAR(255) NULL`, din 024): tot o cale de
-- fișier ține. Numele NU încep cu „photo” dinadins — curățenia pozelor
-- (.claude/poze-fara-produs.mjs) întreabă coloanele „photo…” după poze, iar
-- fișele au lista lor, `COLOANE_FISA`, ținută în pas de `.claude/check-sql.mjs`.
-- `AFTER photo` și nu după `photo_baked`: importată de mână pe o bază fără
-- 024, migrarea trebuie să treacă, nu să pice pe o coloană lipsă.

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'sheet_frozen') = 0,
  'ALTER TABLE products ADD COLUMN sheet_frozen VARCHAR(255) NULL AFTER photo',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'sheet_baked') = 0,
  'ALTER TABLE products ADD COLUMN sheet_baked VARCHAR(255) NULL AFTER sheet_frozen',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 2. semnul, citit ÎNTÂI --------
--
-- Se citește înaintea oricărei scrieri, din același motiv ca la `027` și
-- `029`: citit după, ar spune mereu „s-a făcut" și fișele nu s-ar mai lega
-- niciodată.

SET @fise_puse := (SELECT COUNT(*) FROM settings
                     WHERE setting_key = 'migration_031_fise_tehnice');

-- ------------------------------------------- 3. fișele din pachet, o dată --
--
-- O singură instrucțiune, după `slug`: `id`-urile sunt ale bazei (un produs
-- șters și refăcut are altul), `slug`-ul e al produsului. Lista e scrisă nume
-- cu nume și nu „toate produsele”: un produs adăugat de patron din Catalog
-- n-are fișier în `fise/`, iar o cale spre un fișier care nu există ar pune în
-- magazin un buton care deschide pagina „nu am găsit”.
--
-- `.claude/check-fise-tehnice.mjs` ține lista de aici în pas cu fișierele din
-- `fise/` și cu tabla din `.claude/taie-fise.mjs`.

SET @sql := IF(@fise_puse = 0,
  'UPDATE products
      SET sheet_frozen = CONCAT(''fise/fisa-tehnica-'', slug, ''-congelat.pdf'')
    WHERE sheet_frozen IS NULL
      AND slug IN (
        ''strudel-cu-mere'',
        ''strudel-cu-branza-dulce'',
        ''strudel-cu-dovleac'',
        ''trigon-cu-pizza'',
        ''trigon-cu-branza'',
        ''trigon-cu-gem-de-caise'',
        ''croissant-cu-ciocolata'',
        ''croissant-cu-vanilie-si-zmeura'',
        ''croissant-cu-fistic'',
        ''rolldog'',
        ''roll-cu-cabanos'',
        ''roll-cu-sunca-si-cascaval'',
        ''covrig-cu-cascaval'',
        ''covrig-cu-susan-mac-seminte'',
        ''covrig-cu-mar'',
        ''corn-cu-ciocolata'',
        ''corn-cu-vanilie'',
        ''corn-cu-fistic'',
        ''poale-n-brau'',
        ''roll-cu-pizza'',
        ''merdenea''
      )',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 4. semnul, scris la urmă ------
--
-- `INSERT IGNORE`, deci a doua rulare nu-l mișcă și nu schimbă data. De aici
-- încolo fișele sunt ale patronului: ce scoate el rămâne scos, ce pune el
-- rămâne pus, oricâte actualizări ar veni.

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('migration_031_fise_tehnice', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), 0);

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`. Rândul de mai jos se
-- scrie numai dacă registrul e acolo — importată singură, de mână, pe o bază
-- fără `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('031_fise_tehnice.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
