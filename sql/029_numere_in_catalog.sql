-- Migrarea 029 — numărul din colțul pozei devine al produsului, nu al bazei
--
-- Se rulează o singură dată, după 028. Poate fi rulată de câte ori vrei fără
-- pagubă: numerotarea se face o singură dată, ținută de un semn în `settings`.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/029_numere_in_catalog.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- CEREREA PATRONULUI, 25 septembrie 2026: „Produsele au niște numere în colț,
-- fă să pot aloca ce număr vreau la fiecare produs, default să fie în ordinea
-- în care sunt postate."
--
-- Până azi numărul din colțul pozei era `products.id` — cheia bazei, dată de
-- MySQL la naștere și neschimbabilă. De-aia magazinul arăta „Trigon" cu 04, 05,
-- 06 și 19: al patrulea trigon a fost adăugat după cornuri, iar cheia lui a
-- rămas 19 pentru totdeauna.
--
-- NUMĂRUL NOU STĂ ÎN `products.sort`, care exista deja și ținea deja ordinea
-- produselor în magazin (`ORDER BY sort, id`). Nu se face o coloană nouă,
-- dinadins: două coloane — una pentru ordine, una pentru număr — s-ar putea
-- despărți, iar magazinul ar arăta 07 după 09 în aceeași grupă. Cu una singură,
-- ordinea dintr-o grupă e chiar ordinea numerelor.
--
-- `sort` n-a putut fi scris de nimeni până acum (niciun ecran nu-l schimba),
-- deci ține ce i-a dat crearea: 0, 10, 20… — numărul cel mai mare de până
-- atunci, plus zece. Adică exact ordinea în care au fost puse produsele,
-- numai în pași de zece.
--
-- CE FACE: renumerotează o dată TOATE produsele — și pe cele scoase din
-- catalog, care își păstrează locul pentru când se întorc — cu 1, 2, 3… în
-- ordinea în care au fost puse (`id`). Pe o bază în care nimeni n-a șters
-- produse, numerele ies exact cele de ieri: 01 rămâne 01, 19 rămâne 19. Deci
-- în clipa actualizării nu se schimbă nimic pe vitrină; se schimbă numai
-- faptul că, de acum, patronul le poate muta din Catalog.
--
-- DE CE UN LACĂT, ca la `027`. Fiindcă migrările rulează TOATE, la fiecare
-- actualizare (`actualizeaza-vps.sh`). Fără lacăt, fiecare urcare ar pune la
-- loc numerele alese de patron — adică ar șterge, lună de lună, tocmai
-- lucrarea pe care o aduce migrarea asta.
--
-- CE NU FACE:
--   * nu atinge schema: nici tabel, nici coloană, nici index. `sort` e din
--     `001_schema.sql`;
--   * nu pune un index UNIC pe `sort`. Unicitatea o ține serverul, sub lacăt
--     (`route_products_update()`), iar un index ar fi făcut orice produs
--     scris cu mâna în bază, fără `sort`, să cadă la import pe un zero dublu;
--   * nu atinge grupele: ordinea grupelor e a lor (`product_categories.sort`);
--   * nu schimbă `id`-urile: coșurile, pozele și comenzile trimit tot la ele.

-- ------------------------------------------- 1. semnul, citit ÎNTÂI --------
--
-- Se citește înaintea oricărei scrieri, din același motiv ca la `027`: citit
-- după, ar spune mereu „s-a făcut" și numerotarea n-ar mai avea loc niciodată.

SET @numere_puse := (SELECT COUNT(*) FROM settings
                       WHERE setting_key = 'migration_029_numere_catalog');

-- ------------------------------------------- 2. numerotarea, o singură dată -
--
-- `ROW_NUMBER()` peste `id`, legat de rând printr-un tabel derivat: MySQL nu
-- lasă o subinterogare să citească tabelul pe care îl scrie aceeași cerere,
-- dar un tabel derivat cu o funcție de fereastră se strânge întâi deoparte,
-- deci se poate. Merge pe MySQL 8.0 și mai nou — cel pe care îl pune
-- `instaleaza-vps.sh` (`mysql-server`) și cel din Laragon.

SET @sql := IF(@numere_puse = 0,
  'UPDATE products p
     JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY id) AS nr FROM products) x ON x.id = p.id
      SET p.sort = x.nr',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 3. semnul, scris la urmă ------
--
-- `INSERT IGNORE`, deci a doua rulare nu-l mișcă și nu schimbă data. Serverul
-- îl citește ca poartă (`catalog_numere_supported()`, api/lib/catalog.php):
-- fără el — fișierele urcate, importul încă nu — magazinul arată numărul de
-- ieri, cheia bazei, iar Catalogul nu lasă numerele să fie mutate. Cu el,
-- numărul e `sort`.

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('migration_029_numere_catalog', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), 0);

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`. Rândul de mai jos se
-- scrie numai dacă registrul e acolo — importată singură, de mână, pe o bază
-- fără `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('029_numere_in_catalog.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
