-- Migrarea 030 — lista de etape capătă „Gata de livrare", chiar înaintea celei finale
--
-- Se rulează o singură dată, după 029. Poate fi rulată de câte ori vrei fără
-- pagubă: etapa se pune o singură dată, ținută de un semn în `settings`.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/030_gata_de_livrare.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- HOTĂRÂREA PATRONULUI, 2 octombrie 2026: laboratorul se oprește la „Gata de
-- livrare", iar de-acolo comanda e a curierului — el bifează marfa în mașină
-- și tot el spune că a ajuns la client.
--
-- Până azi lista n-avea nicio etapă pentru „ambalată, așteaptă curierul".
-- Plafonul laboratorului e etapa dinaintea celei finale (`status_lab_ceiling()`,
-- api/lib/settings.php), deci era „La ambalare": o comandă abia intrată la
-- ambalat și una stând gata lângă ușă arătau la fel, iar curierul n-avea de
-- unde ști pe care o poate lua. Etapa nouă e tocmai deosebirea asta.
--
-- CE FACE: pune numele „Gata de livrare" în lista `order_statuses`, pe locul
-- dinaintea etapei finale. Atât. Plafonul laboratorului, poarta etapei finale
-- și butonul curierului se socotesc toate din locul etapei în listă, nu din
-- numele ei, deci se mută singure odată cu ea.
--
-- DE CE LOCUL SE CAUTĂ, NU SE SCRIE. Lista e a patronului: se editează din
-- Setări, iar cea de pe site nu mai seamănă de mult cu sămânța din `002`. Un
-- `UPDATE` cu lista întreagă scrisă aici ar fi greșeala lui `003`, luată
-- înapoi o dată (vezi `016`). Iar „înaintea Predatei" scris pe litere ar ține
-- până la prima redenumire. Etapa finală se citește deci din BAZĂ, din
-- `order_status_done`, cum o citește și serverul (`status_done()`).
--
-- Cititul din bază mai are un folos: numele etapei finale poartă diacritice,
-- iar un literal cu diacritice ajunge stricat în bază când fișierul e dat lui
-- mysql.exe pe o legătură care nu e utf8mb4 (pățania e scrisă în `003`). Aici
-- niciun rând care se execută nu are vreo literă în afara ASCII: singurul nume
-- scris de mână e cel nou, iar el n-are diacritice.
--
-- DE CE UN ZĂVOR, ca la `027` și `029`. Fiindcă migrările rulează TOATE, la
-- fiecare actualizare (`actualizeaza-vps.sh`), iar `JSON_SEARCH` caută NUMELE,
-- nu hotărârea patronului: o etapă redenumită din Setări, ori scoasă dinadins,
-- n-ar mai fi găsită și s-ar pune la loc lună de lună. E lecția lui `016`.
-- Semnul se citește ÎNTÂI și se scrie LA URMĂ.
--
-- CE NU FACE:
--   * nu atinge schema: nici tabel, nici coloană, nici index;
--   * nu mută nicio comandă. Cele aflate la „La ambalare" în ziua actualizării
--     rămân acolo: laboratorul mai apasă o dată pe fiecare, și abia atunci se
--     aprinde butonul curierului;
--   * nu atinge `order_status_done` și `order_status_cancelled`;
--   * nu pune rândul `order_statuses` dacă lipsește cu totul. Serverul cade
--     atunci pe lista din cod (`order_statuses()`), care are deja etapa;
--   * nu atinge o listă care nu e JSON, ori care nu e un șir simplu de nume.
--     Pe aceea serverul o citește oricum ca pe o listă stricată; migrarea
--     trece mai departe și își scrie semnul.

SET NAMES utf8mb4;

-- ------------------------------------------- 1. semnul, citit ÎNTÂI --------
--
-- Se citește înaintea oricărei scrieri, din același motiv ca la `027`: citit
-- după, ar spune mereu „s-a făcut" și etapa nu s-ar mai pune niciodată.

SET @gdl_pusa := (SELECT COUNT(*) FROM settings
                    WHERE setting_key = 'migration_030_gata_de_livrare');

-- ------------------------------------------- 2. numele, citite din bază ----
--
-- Numele nou se scrie o singură dată, ca la `@zona` din `016`.
--
-- Etapa finală și cea de anulare se iau din setările lor. Rândul poate lipsi
-- (o bază care n-a trecut prin `003`): `IFNULL` îl face șir gol, iar pasul 3
-- cade atunci pe regula de rezervă.

SET @gdl_etapa := 'Gata de livrare';

SET @gdl_finala  := IFNULL((SELECT value FROM settings
                             WHERE setting_key = 'order_status_done'), '');
SET @gdl_anulata := IFNULL((SELECT value FROM settings
                             WHERE setting_key = 'order_status_cancelled'), '');

-- CAPCANA LUI `JSON_SEARCH`, cea din `016`, aici cu urmări: compară ca un
-- LIKE, deci `%` și `_` dintr-un nume de etapă ar fi caractere cu noimă, nu
-- litere. O etapă finală numită „Dus_100%" s-ar potrivi și cu „DusX100Yz", iar
-- etapa nouă ar ateriza înaintea aceleia. Numele se editează din Setări, deci
-- se scapă: caracterul de scăpare e `|`, ales fiindcă backslash-ul ar mai
-- trebui dublat o dată pentru șirul dat lui `PREPARE` și încă o dată pentru
-- LIKE. Întâi se dublează `|` însuși, apoi se scapă celelalte două — pe dos,
-- scăparea pusă pentru `%` ar fi dublată și ea.

SET @gdl_cauta_f := REPLACE(REPLACE(REPLACE(@gdl_finala,  '|', '||'), '%', '|%'), '_', '|_');
SET @gdl_cauta_a := REPLACE(REPLACE(REPLACE(@gdl_anulata, '|', '||'), '%', '|%'), '_', '|_');

-- Etapa finală se caută numai dacă setarea spune ceva și nu spune tocmai
-- „anulată". Cu o setare stricată așa, etapa nouă ar ateriza înaintea
-- anulării, adică DUPĂ etapa la care se încheie de fapt comenzile; atunci se
-- merge pe regula de rezervă, ca și cum setarea ar lipsi. `HEX` de amândouă
-- părțile: coloana se compară fără să țină seama de diacritice
-- (utf8mb4_unicode_ci), iar aici trebuie literă cu literă.

SET @gdl_are_finala := IF(CHAR_LENGTH(@gdl_finala) > 0
                          AND HEX(@gdl_finala) <> HEX(@gdl_anulata), 1, 0);

-- ------------------------------------------- 3. etapa, o singură dată ------
--
-- LOCUL. Întâi cel al etapei finale din setare: `JSON_ARRAY_INSERT` pune
-- numele nou pe locul ei și o împinge cu unul mai încolo, deci etapa nouă
-- iese chiar înaintea ei. Dacă setarea lipsește, e goală ori arată spre un
-- nume care nu mai e în listă, se merge pe regula lui `status_done()`: etapa
-- finală e ultima din listă care nu e cea de anulare. Adică penultimul loc
-- când lista se încheie cu anularea, ultimul altfel.
--
-- Ramura de rezervă nu poate ieși NULL, și asta se ține dinadins: `value` e o
-- coloană care primește NULL, deci o cale NULL ar șterge lista fără nicio
-- eroare. `JSON_LENGTH` e mereu un număr pe un JSON valid, iar un `IF` cu
-- condiția NULL (anularea negăsită) ia ramura a doua.
--
-- PAZELE:
--   * `JSON_VALID` stă în IF-ul de afară, nu în WHERE, ca la `016`: pe MySQL,
--     o funcție JSON peste un text care nu e JSON oprește migrarea cu eroarea
--     3141, iar ordinea condițiilor dintr-un WHERE nu e promisă de nimeni;
--   * `JSON_TYPE` și `JSON_DEPTH` cer un șir simplu de nume. Adâncimea 2
--     înseamnă „șir cu cel puțin un nume și nimic cuibărit în el": într-o listă
--     cu un șir înăuntru, `JSON_SEARCH` ar putea întoarce o cale spre
--     interiorul aceluia. Șirul gol are adâncimea 1, deci rămâne și el neatins;
--   * `JSON_SEARCH ... IS NULL` — etapa nu se pune a doua oară peste una pusă
--     de mână înaintea actualizării.
--
-- `JSON_ARRAY_INSERT` scrie înapoi forma canonică a MySQL, cu un spațiu după
-- virgulă, ca `JSON_ARRAY_APPEND` din `016`. Lista se citește cu `json_decode`
-- (`setting_list()`), deci spațiul n-are nicio urmare.
--
-- Apostrofurile dinăuntru se dublează. Variabilele `@gdl_…` se citesc la
-- EXECUTE, din aceeași legătură, deci rămân cele puse mai sus.

SET @sql := IF(
  @gdl_pusa = 0
  AND (SELECT COUNT(*) FROM settings
        WHERE setting_key = 'order_statuses' AND JSON_VALID(value)) = 1,
  'UPDATE settings
      SET value = JSON_ARRAY_INSERT(value,
            COALESCE(
              IF(@gdl_are_finala = 1,
                 JSON_UNQUOTE(JSON_SEARCH(value, ''one'', @gdl_cauta_f, ''|'')),
                 NULL),
              CONCAT(''$['',
                     IF(JSON_LENGTH(value) > 1
                        AND JSON_UNQUOTE(JSON_SEARCH(value, ''one'', @gdl_cauta_a, ''|''))
                            = CONCAT(''$['', JSON_LENGTH(value) - 1, '']''),
                        JSON_LENGTH(value) - 2,
                        JSON_LENGTH(value) - 1),
                     '']'')),
            @gdl_etapa)
    WHERE setting_key = ''order_statuses''
      AND JSON_TYPE(value) = ''ARRAY''
      AND JSON_DEPTH(value) = 2
      AND JSON_SEARCH(value, ''one'', @gdl_etapa) IS NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 4. semnul, scris la urmă ------
--
-- `INSERT IGNORE`, deci a doua rulare nu-l mișcă și nu schimbă data. Se scrie
-- și când pasul 3 n-a avut ce face — lista avea deja etapa (o instalare nouă o
-- primește din `002`), ori era de necitit: semnul înseamnă „migrarea 030 a
-- trecut pe-aici", nu „s-a pus ceva". Fără el scris de fiecare dată, o listă
-- îndreptată de patron luni mai târziu ar primi etapa peste hotărârea lui.

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('migration_030_gata_de_livrare', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'), 0);

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`. Rândul de mai jos se
-- scrie numai dacă registrul e acolo — importată singură, de mână, pe o bază
-- fără `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('030_gata_de_livrare.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
