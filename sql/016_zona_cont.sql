-- Migrarea 016 — zona ține de cont, și mai e o zonă
--
-- Se rulează o singură dată, după 015. Poate fi rulată de câte ori vrei fără
-- pagubă: coloana nouă se adaugă numai dacă lipsește, iar cei doi pași care
-- scriu date — zona nouă în listă și completarea conturilor vechi — se fac
-- NUMAI în rularea care adaugă coloana. Orice rulare de după o găsește deja
-- acolo și nu mai atinge nici lista, nici conturile: o zonă redenumită din
-- Setări sau ștearsă de pe un cont rămâne cum a lăsat-o omul. Vezi pasul 0.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/016_zona_cont.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- PROBLEMA DE REZOLVAT, partea întâi: CONTUL NU ȘTIE UNDE E FIRMA. Formularul
-- „Fă-ți cont" scrie, în capul lui, că îți ține adresa și telefonul ca să nu le
-- mai scrii la fiecare comandă. Telefonul îl cere. Adresa n-o cere niciodată,
-- deși coloana `users.address` există din 001 — deci promisiunea aceea era pe
-- jumătate de la bun început, iar adresa rămâne goală pentru toți cei care și-au
-- făcut singuri cont de pe site.
--
-- Iar zona nu se ține nicăieri pe cont. `orders.zone` și `order_requests.zone`
-- există, dar acelea sunt zona COMENZII: se alege iar din listă la fiecare
-- comandă nouă, și pentru clientul care de doi ani livrează în același punct de
-- lucru. De-aici coloana de mai jos.
--
-- PROBLEMA DE REZOLVAT, partea a doua: LISTA DE ZONE SE OPREA LA ILFOV. Un
-- client din Ploiești n-avea ce alege. Operatoarea punea „Ilfov", fiindcă altfel
-- comanda nu se salva — și de-atunci oprirea aceea stătea pe ruta șoferului,
-- între două sectoare, pe un punct de hartă care nu e al ei.
--
-- DE CE ZONA NOUĂ N-ARE PUNCT PE HARTĂ. `delivery_zone_coords` rămâne cu cele
-- șapte chei ale ei, dinadins. „În afara Bucureștiului și Ilfovului" nu e un
-- loc, e restul hărții: orice punct i-am da ar fi o minciună măsurabilă în
-- kilometri, iar șoferul ar primi o rută calculată pe ea.
--
-- Și lipsa punctului e taman semnalul de care are nevoie ruta. O oprire fără
-- punct nu poate fi așezată în ordine față de celelalte — n-are de unde — deci
-- iese din ordonare și se arată aparte, într-o listă a ei, dusă de cine poate.
-- Așa a cerut patronul: ruta de azi rămâne neatinsă, cu sectoarele și Ilfovul în
-- ordinea lor, și capătă dedesubt încă o listă scurtă.
--
-- Semnalul e lipsa punctului, NU numele zonei. Zonele se redenumesc din Setări:
-- un nume scris în cod ar ține până în ziua în care cineva scrie „În afara
-- Bucureștiului" fără „și Ilfovului", și de-atunci oprirea ar intra tăcut înapoi
-- în ordonare.
--
-- ȘI REPARĂ UN DEFECT CARE EXISTA DINAINTE. Azi orice zonă fără punct cade pe
-- depozit (`gp.zonePoint`, shared/gp-api.js): oprirea se desenează în curtea
-- magaziei, piciorul de drum până la ea iese 0 km, iar „Optimizează ruta" o pune
-- PRIMA, fiindcă e cea mai apropiată. O zonă adăugată din Setări căreia i s-a
-- golit coordonata pățea exact asta, fără niciun semn. De acum încolo, o zonă
-- fără punct nu mai minte: se vede că e deoparte.
--
-- DE CE NU UN `UPDATE` CU LISTA SCRISĂ AICI. E exact greșeala pe care a făcut-o
-- `sql/003` și pe care a trebuit s-o luăm înapoi: un UPDATE necondiționat
-- ștergea, la fiecare re-import, zonele adăugate din Setări și le punea la loc
-- pe cele din prototip — o rută într-un cartier nou dispărea în tăcere. Aici se
-- adaugă UN SINGUR nume la capătul listei pe care o are baza, oricare ar fi ea,
-- și numai dacă nu e deja acolo.

SET NAMES utf8mb4;

-- Numele se scrie o singură dată, ca să nu se despartă cele patru locuri de mai
-- jos una de alta la prima corectură de diacritice.
SET @zona := 'În afara Bucureștiului și Ilfovului';

-- ------------------------------------------------------ 0. opritorul --

-- „DE CÂTE ORI VREI FĂRĂ PAGUBĂ" ERA ADEVĂRAT NUMAI PE O BAZĂ PE CARE N-A
-- UMBLAT NIMENI. `actualizeaza-vps.sh` rulează la FIECARE actualizare toate
-- migrările de la 003 în sus, deci pașii 2 și 3 de mai jos rulau și ei de
-- fiecare dată, luni după prima lor rulare. Paza lor era „numai dacă
-- lipsește" — iar patronul face tocmai lipsa aceea, cu mâna:
--
--   * redenumește zona a opta din Setări, ori o scoate din listă fiindcă nu
--     mai livrează departe. Pasul 2 nu mai găsea numele și îl punea iar la
--     coadă: două zone fără punct care spun același lucru, ori o zonă scoasă
--     dinadins care se întoarce singură în formularul clientului;
--   * șterge zona de pe un cont, din Conturi. Ștergerea scrie NULL
--     (api/routes/users.php, `zone_field(...) ?: null`), deci pasul 3 avea
--     iar ce „completa" și punea la loc zona din ultima comandă — de pildă
--     „Ilfov"-ul pus de operatoare unui client din Ploiești pe vremea când
--     lista se oprea la Ilfov. De-acolo, cererea următoare a clientului își
--     lua zona de pe cont, iar oprirea intra iar în ordinea rutei.
--
-- Opritorul e coloana însăși: se numără ÎNAINTE de pasul 1, într-o variabilă,
-- ca `@pcat_gol` din sql/003. Numărată după `ALTER`, coloana ar fi mereu
-- acolo, iar pașii n-ar rula niciodată — nici măcar prima dată.
--
-- DE CE NU O URMĂ ÎN `settings`, CA LA 010. Pe serverul unde 016 a rulat
-- deja, urma n-ar exista încă, deci pașii s-ar mai face o dată — adică tocmai
-- paguba de mai sus, la actualizarea care aduce reparația. Coloana e acolo de
-- la prima rulare, deci răspunsul ei e bun din prima zi.
--
-- Pe o instalare nouă coloana lipsește din 001, deci pașii rulează: lista din
-- 002 are deja zona (pasul 2 n-are ce adăuga), iar clienți încă nu sunt
-- (pasul 3 n-are ce completa).
--
-- Ce NU apără: o rulare ruptă între `ALTER` și pasul 3. Coloana ar rămâne, iar
-- pașii n-ar mai rula. De-aia `actualizeaza-vps.sh` se oprește la prima eroare
-- și spune cum se pune la loc copia bazei făcută înainte de migrări; după
-- punere, coloana lipsește iar și totul se face de la capăt. Cu `--fara-copie`
-- n-ai copie, deci n-ai nici drumul ăsta înapoi.
SET @coloana_noua := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                         AND COLUMN_NAME = 'zone');

-- ------------------------------------------- 1. zona ține de cont --

-- VARCHAR(64), ca `orders.zone` și `order_requests.zone`: e același fel de
-- valoare, iar o zonă care încape în comandă trebuie să încapă și pe cont.
-- NULL înseamnă „nu s-a spus încă", și e altceva decât '' — un cont vechi n-a
-- fost întrebat niciodată.
--
-- Fără index: zona se citește o dată cu contul, după id.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'zone') = 0,
  'ALTER TABLE users
     ADD COLUMN zone VARCHAR(64) NULL AFTER address',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------- 2. zona a opta, în listă --

-- Pe o bază unde cheia lipsește cu totul — n-a trecut niciodată prin 002 sau
-- 003 — o punem întreagă, cu toate opt. `INSERT IGNORE` nu atinge un rând care
-- există deja, deci pe baza patronului rândul ăsta nu face nimic.
INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('delivery_zones', '["Sector 1","Sector 2","Sector 3","Sector 4","Sector 5","Sector 6","Ilfov","În afara Bucureștiului și Ilfovului"]', 0);

-- Iar pe o bază care are deja lista — cu zonele ei, oricare ar fi — se adaugă
-- numai numele nou, la capăt. Și asta numai în rularea care aduce coloana
-- (pasul 0): `JSON_SEARCH` caută NUMELE, nu hotărârea patronului, deci o zonă
-- redenumită sau scoasă din Setări nu mai era găsită și se punea la loc la
-- fiecare actualizare.
--
-- Paza pe `JSON_VALID` stă într-un IF, nu într-un AND din WHERE: pe MySQL,
-- `JSON_SEARCH` peste un text care nu e JSON nu întoarce NULL, ci oprește
-- migrarea cu eroarea 3141. Iar ordinea în care se evaluează condițiile dintr-un
-- WHERE nu e promisă de nimeni, deci nu ne putem sprijini pe ea.
--
-- CAPCANA LUI `JSON_SEARCH`: compară ca un LIKE, deci `%` și `_` dintr-un nume
-- de zonă ar fi caractere cu noimă, nu litere. Numele de aici n-are niciunul;
-- dacă vreodată se caută altul, aici e locul unde s-ar rupe.
--
-- `JSON_ARRAY_APPEND` scrie înapoi forma canonică a MySQL, cu un spațiu după
-- virgulă. Lista se citește cu `json_decode` (`setting_list()`,
-- api/lib/settings.php), deci spațiul n-are nicio urmare — dar cine compară
-- vreodată valoarea ca text să știe de ce nu seamănă cu sămânța din 002.
SET @sql := IF(
  @coloana_noua = 1
  AND (SELECT COUNT(*) FROM settings
    WHERE setting_key = 'delivery_zones' AND JSON_VALID(value)) = 1,
  'UPDATE settings
      SET value = JSON_ARRAY_APPEND(value, ''$'', @zona)
    WHERE setting_key = ''delivery_zones''
      AND JSON_SEARCH(value, ''one'', @zona) IS NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------- 3. conturile care au deja un trecut --

-- Zona nu e o ghicitoare aici: e zona pe care clientul ăsta a dat-o el însuși,
-- la ultima lui comandă. Fără pasul ăsta, tot ce a comandat vreodată cineva
-- rămâne fără zonă pe cont, iar „clientul intrat în cont comandă din două
-- atingeri" n-ar fi adevărat taman pentru clienții vechi — adică pentru cei
-- pentru care s-a făcut.
--
-- E un punct de plecare, nu o hotărâre: caseta din formular arată zona și o
-- lasă schimbată oricând, iar serverul verifică la salvare ce primește.
--
-- De-aia nu se cerne aici după lista de azi. Dacă zona de pe comanda veche a
-- fost între timp redenumită din Setări, caseta pur și simplu nu găsește ce să
-- bifeze și clientul alege — exact ce se întâmplă azi pentru toată lumea. N-am
-- câștiga nimic dintr-o verificare care ar cere JSON înăuntrul unui UPDATE, și
-- am putea pierde o zonă bună dintr-o listă scrisă strâmb.
--
-- `u.zone IS NULL` NU făcea pasul repetabil, deși așa scria aici. Ștergerea
-- zonei din Conturi scrie tot NULL, deci la actualizarea următoare pasul avea
-- din nou ce „completa": zona pe care omul tocmai o ștersese. Repetabil îl
-- face opritorul de sus — pasul rulează numai în rularea care aduce coloana,
-- când nimeni n-a apucat încă să scrie vreo zonă pe vreun cont. Condiția
-- rămâne: nu costă nimic și spune pe față ce se completează.
--
-- Urmarea, spusă pe față: zona de pe cont se ia din comenzi o singură dată pe
-- instalare. Un client făcut după aceea fără zonă n-o mai capătă la nicio
-- actualizare — și-o alege el în formular, ori i-o pui tu din Conturi.
--
-- Un client fără nicio comandă rămâne NULL, și așa trebuie — n-avem de unde
-- ști.
--
-- Se scrie cu PREPARE, ca pasul 2, ca să fie adevărat pe litere că o rulare
-- de după nu atinge conturile: acolo UPDATE-ul nici nu se pregătește, se
-- rulează în locul lui un `SELECT 1`.
-- Apostrofurile dinăuntru se dublează.
SET @sql := IF(@coloana_noua = 1,
  'UPDATE users u
      SET u.zone = (
            SELECT o.zone
              FROM orders o
             WHERE o.customer_id = u.id
               AND o.zone IS NOT NULL
               AND CHAR_LENGTH(o.zone) > 0
             ORDER BY o.id DESC
             LIMIT 1
          )
    WHERE u.role = ''client''
      AND u.zone IS NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ce NU face migrarea asta, ca să nu se caute mai târziu:
--
--   * nu atinge `delivery_zone_coords` — vezi mai sus de ce;
--   * nu completează `users.address` din comenzi. Adresa se editează de mult
--     din „Comanda mea" și din Conturi, deci o adresă goală poate fi goală
--     dinadins; zona n-a putut fi scrisă până azi de nimeni, deci o zonă goală
--     nu spune nimic.

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`, unde e scris pe larg
-- de ce există și de ce se face înaintea buclei. Rândul de mai jos se scrie
-- numai dacă registrul e acolo — importată singură, de mână, pe o bază fără
-- `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('016_zona_cont.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
