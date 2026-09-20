-- Migrarea 023 — ordinea de lucru a laboratorului și dreptul de a confirma livrarea
--
-- Se rulează o singură dată, după 022. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare bucată se uită întâi dacă nu e deja acolo.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/023_laboratorul_si_livrarea.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- Două coloane, pentru două hotărâri ale patronului din 19 septembrie 2026.
-- Stau în aceeași migrare fiindcă vin din aceeași poveste — tableta din
-- laborator — și fiindcă ecranul care le folosește pe amândouă e unul singur.
--
-- 1 · ORDINEA COMENZILOR ESTE A CASEI, NU A ECRANULUI.
--
-- Până azi ordinea scrisă de mână în „Panou Laborator" stătea numai în
-- `localStorage`, iar pagina o spunea chiar ea: „ordinea cardurilor rămâne a
-- acestui ecran". În laborator sunt mai multe tablete și un telefon; brutarul
-- care aranja ziua pe una din ele aranja pentru el singur, iar colegul de
-- lângă el vedea altceva. Mai rău, împărțirea cantităților produse (lucrarea 7)
-- merge DE SUS ÎN JOS pe lista aceea: cu ordinea ținută în browser, aceeași
-- apăsare ar fi umplut alte comenzi, după tableta de pe care s-a apăsat.
--
-- 2 · LIVRAREA O CONFIRMĂ NUMAI CINE ARE SEMNUL.
--
-- Etapa finală („Predată") înseamnă „ajunsă la client". O punea oricine din
-- personal: serverul n-avea nicio regulă de trecere și niciun drept pe etapă.
-- Laboratorul o apăsa când termina de ambalat, iar comanda apărea livrată
-- înainte să fi urcat în mașină.
--
-- Oprirea numai în pagină n-ar fi ținut: contul `angajat` are ȘI panoul
-- Laborator, ȘI panoul Rută — același om ar fi apăsat pe cealaltă ușă.

SET NAMES utf8mb4;

-- ------------------------------------------- 1. ordinea de lucru -----------
--
-- Soră cu `route_seq` (migrarea 003) și cu aceeași formă, fiindcă e același
-- fel de lucru: o poziție scrisă de mână peste o ordine firească, ștearsă cu
-- NULL când nimeni n-a aranjat nimic.
--
-- DE CE NU SE REFOLOSEȘTE `route_seq`. Aceea e ordinea OPRIRILOR pe traseul
-- șoferului, pusă de birou și păzită de `require_admin()`: ordinea în care
-- mașina trece pe la clienți n-are nimic de-a face cu ordinea în care
-- laboratorul coace. Două înțelesuri într-o coloană ar fi însemnat că tableta
-- rearanjează traseul mașinii, iar biroul rearanjează cuptorul.
--
-- NULL, nu 0, pentru „nearanjată": 0 e o poziție adevărată — cea dintâi —, iar
-- ruta care scrie ordinea începe tocmai de la 0. Cu 0 drept „nimic", prima
-- comandă aranjată n-ar fi fost de deosebit de una neatinsă.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'lab_seq') = 0,
  'ALTER TABLE orders ADD COLUMN lab_seq SMALLINT UNSIGNED NULL AFTER route_seq',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 2. dreptul de livrare ---------
--
-- `NOT NULL DEFAULT 0` — ȘI NICIO UMPLERE ÎNAPOI. Rândul ăsta e miezul
-- migrării, iar ciorna hotărâse pe dos: să se pună semnul pe toate conturile
-- de operații care există acum, „ca să nu înrăutățim nimic".
--
-- Patronul a limpezit ce nu se putea citi din cod: laboratorul, șoferul și
-- lucrătorii au conturi DEOSEBITE, contul de laborator fiind o tejghea comună
-- pentru treburi scurte, fără telefon. Deci o umplere înapoi ar fi pus semnul
-- tocmai pe contul de laborator — adică pe contul din pricina căruia s-a cerut
-- lucrarea. Regula ar fi plecat la drum nefăcând nimic, și nimeni n-ar fi
-- băgat de seamă: o poartă care se naște deschisă pentru toți e o poartă care
-- nu există, dar care se citește în cod ca și cum ar exista.
--
-- Deci toți se nasc fără semn, iar patronul bifează șoferul în Conturi. Raza
-- de explozie e mică și STRIGĂ: administratorul și fondatorul îl au din cod
-- (v. `poate_confirma_livrarea()`, api/lib/auth.php), deci biroul nu se poate
-- încuia niciodată pe dinafară, iar refuzul îi spune omului chiar pagina și
-- bifa care-l repară. Un refuz greșit se drege în zece secunde; o regulă care
-- tace nu se drege niciodată.
--
-- Numele e românesc și întreg, ca `must_change_password` să rămână singura
-- coloană scrisă altfel în tabelul ăsta: cine citește `SHOW COLUMNS` trebuie
-- să înțeleagă din nume ce poate omul, nu ce rol are.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'poate_confirma_livrarea') = 0,
  'ALTER TABLE users ADD COLUMN poate_confirma_livrarea TINYINT(1) NOT NULL DEFAULT 0 AFTER role',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 3. ce NU e aici ---------------
--
-- Nicio cheie și niciun index pe cele două coloane, dinadins:
--
--   · `lab_seq` se citește întotdeauna împreună cu ziua, iar ziua are deja
--     `ix_orders_day (day, status)` din 001. Comenzile unei zile sunt zeci, nu
--     zeci de mii: sortarea lor se face în PHP, după ce au fost oricum aduse
--     toate. Un index în plus ar fi încă o scriere la fiecare rearanjare, ca
--     să se câștige o sortare de treizeci de rânduri.
--   · `poate_confirma_livrarea` se citește pentru UN cont, după cheia primară,
--     pe drumul unei singure cereri. Nu se caută niciodată „toți cei care pot".
--
-- Și nicio umplere de date. Migrarea asta e numai schelet: o bază pe care s-a
-- rulat de zece ori arată la fel ca una pe care s-a rulat o dată, inclusiv
-- după ce patronul a bifat și a debifat șoferul între rulări — tocmai pățania
-- lui 016, scrisă pe larg în `.claude/test-migrari-repetate.mjs`.
