-- Migrarea 027 — fiecare latură capătă poza EI, ca să se poată tăia căderea
--
-- Se rulează o singură dată, după 026. Poate fi rulată de câte ori vrei fără
-- pagubă: lucrul se face o singură dată, ținut de un semn în `settings`.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/027_poza_fiecarei_laturi.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- HOTĂRÂREA CARE SE SCHIMBĂ. La runda 2.4.0, hotărârea 2 a patronului spunea:
-- „poza lipsă cade pe cealaltă" — o latură fără poză a ei o arată pe a
-- celeilalte, ca un produs să nu apară gol pe site în ziua dintre cele două
-- urcări. Văzută pe viu, purtarea asta e greu de deosebit de un defect:
-- patronul a schimbat poza la congelate și s-a schimbat și la gata de servire,
-- fiindcă niciun produs n-avea încă poză a lui de gata-de-servire. Hotărârea
-- nouă, din 21 septembrie 2026: **fiecare latură arată numai poza ei.**
--
-- PROBLEMA PE CARE O REZOLVĂ MIGRAREA ASTA. Tăierea căderii e o schimbare de
-- cod, nu de date — dar aplicată singură ar goli catalogul de gata-de-servire
-- peste noapte: `photo_baked` e NULL la TOATE produsele (coloana s-a născut cu
-- `024`, acum o zi, și nimeni n-a apucat să urce o a doua poză). Site-ul ar
-- rămâne cu substituentul „fără poză" pe jumătate din marfă, până când
-- patronul ar urca, unul câte unul, tot catalogul a doua oară.
--
-- Migrarea desparte deci PURTAREA de STARE: purtarea se schimbă (de mâine,
-- laturile sunt neatârnate), starea nu (azi, amândouă laturile arată aceeași
-- poză — chiar ce se vede acum). Ce era împrumutat devine al lui.
--
-- DE CE UN LACĂT, ȘI NU DOAR `WHERE photo_baked IS NULL`. Fiindcă fără lacăt
-- migrarea n-ar fi o îndreptare o dată pentru totdeauna, ci **căderea înviată
-- la fiecare urcare**: un produs adăugat peste o lună, cu poză numai la
-- congelate, ar avea `photo_baked` gol — iar următoarea rulare a lui
-- `actualizeaza-vps.sh` i l-ar umple pe tăcute. Exact purtarea pe care codul
-- tocmai a scos-o, întoarsă pe ușa din dos, o dată la fiecare actualizare.
-- Lacătul spune „a fost odată o cădere, și am strâns-o", nu „ține laturile în
-- pas".
--
-- Tiparul e cel din `010_origine_cont.sql:158-181`: semnul se citește ÎNAINTE,
-- cererea se clădește cu `IF()` și se rulează prin `PREPARE`, semnul se scrie
-- la urmă cu `INSERT IGNORE`.
--
-- CE NU FACE:
--   * nu atinge produsele fără nicio poză. `photo` gol înseamnă că n-are ce
--     copia; rămân amândouă laturile goale, ca azi;
--   * nu atinge `photo`. Latura congelată e izvorul, nu ținta;
--   * nu atinge produsele care AU deja poză de gata-de-servire. Dacă între
--     `024` și migrarea asta patronul a apucat să urce una, ea rămâne a ei;
--   * nu face copii ale FIȘIERELOR. Cele două coloane ajung să arate spre
--     același fișier de pe disc, și e bine așa — o copie a fiecărei poze ar
--     dubla degeaba folderul. Urmarea se ține în cod: `route_products_photo()`
--     nu mai șterge de pe disc poza veche dacă cealaltă latură încă o
--     folosește. Fără paza aia, prima poză nouă de congelate ar fi rupt tăcut
--     poza de gata-de-servire a aceluiași produs.

-- ------------------------------------------- 1. semnul, citit ÎNTÂI --------
--
-- Se citește înaintea oricărei scrieri. Citit după, ar fi mereu 1 la a doua
-- rulare din aceeași trecere și lucrul nu s-ar mai face niciodată — iar citit
-- din aceeași cerere care îl scrie, n-ar avea nicio putere.

SET @poze_copiate := (SELECT COUNT(*) FROM settings
                        WHERE setting_key = 'migration_027_poza_pe_latura');

-- ------------------------------------------- 2. copierea, o singură dată ---
--
-- `photo_baked` gol înseamnă și NULL, și șir gol: un produs adăugat din
-- Catalog fără poză are `photo` scris ca șir gol, nu NULL, iar `photo_baked`
-- se poate naște oricare din două. Amândouă se socotesc „n-are poză".
--
-- Coloana poate lipsi cu totul — cineva care importă fișierul ăsta singur, de
-- mână, pe o bază fără `024`. Atunci cererea nici nu se citește: `PREPARE` ia
-- șirul ales de `IF()`, iar ramura nealeasă nu ajunge niciodată la parser.

SET @are_coloana := (SELECT COUNT(*) FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE()
                         AND TABLE_NAME = 'products'
                         AND COLUMN_NAME = 'photo_baked');

SET @sql := IF(@poze_copiate = 0 AND @are_coloana = 1,
  'UPDATE products
      SET photo_baked = photo
    WHERE (photo_baked IS NULL OR photo_baked = '''')
      AND photo IS NOT NULL
      AND photo <> ''''',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 3. semnul, scris la urmă ------
--
-- `INSERT IGNORE`, deci a doua rulare nu-l mișcă și nu schimbă data. Valoarea
-- e clipa în care s-a strâns căderea; singurul care o va citi vreodată e
-- cineva care se întreabă „de când sunt laturile neatârnate?".
--
-- Se scrie chiar dacă `024` lipsea și copierea n-a avut ce face: pe o bază
-- fără coloană nu există nimic de copiat, nici acum, nici mai târziu —
-- coloana se naște goală abia cu `024`, iar produsele de după `024` n-au voie
-- să fie atinse de migrarea asta oricum. Semnul înseamnă „migrarea 027 a
-- trecut pe-aici", nu „s-a copiat ceva".

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('migration_027_poza_pe_latura', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), 0);

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`, unde e scris pe larg
-- de ce există și de ce se face înaintea buclei. Rândul de mai jos se scrie
-- numai dacă registrul e acolo — importată singură, de mână, pe o bază fără
-- `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('027_poza_fiecarei_laturi.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
