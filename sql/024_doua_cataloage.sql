-- Migrarea 024 — două cataloage, o vitrină
--
-- Se rulează o singură dată, după 023. Poate fi rulată de câte ori vrei fără
-- pagubă: cele trei coloane se adaugă numai dacă lipsesc, iar singurul pas care
-- SCRIE date se face NUMAI în rularea care aduce coloanele. Vezi pasul 0.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/024_doua_cataloage.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- CE A CERUT PATRONUL. Două cataloage în panou — unul de congelate, unul de
-- gata de servire —, cu poze deosebite de fiecare parte. Pe site rămâne o
-- singură vitrină, cu comutatorul de azi. Cele două cataloage se oglindesc, cu
-- două deosebiri: la gata de servire vor fi MAI MULTE produse, și vor fi grupe
-- care se văd numai acolo.
--
-- CE ÎMPIEDICA ASTA. Nu lipsa unui ecran: magazinul ascunde orice produs care
-- n-are AMÂNDOUĂ prețurile. Regula e scrisă în șase locuri deosebite — în
-- pagina magazinului de două ori, în cele două fișiere de SEO, în socoteala
-- pachetului de probă, și ca refuz la crearea unui produs. Un produs care se
-- vinde numai gata de servire n-are cum să existe azi: ori îi inventezi un preț
-- congelat pe care nu-l vinzi nimănui, ori nu se vede nicăieri.
--
-- Lucrarea e, la miez, desfacerea acelui „și" în două socoteli deosebite. Iar
-- ca să se desfacă, trebuie întâi ca baza să știe DE CE nu se vede un produs
-- într-o parte: fiindcă n-are preț acolo, sau fiindcă patronul n-a vrut.
--
-- DE CE NU DOUĂ RÂNDURI ÎN `products`. Fiindcă un produs e un lucru, nu două.
-- E legat de o rețetă (`product_recipes`), de un articol de depozit
-- (`stock_items.product_id`) și de fiecare comandă din trecut; memoria de preț
-- are cheia „id|variantă", deci un al doilea rând ar rupe-o în două. Ordinea,
-- numele, descrierea, grupa și rețeta ar trebui ținute în pas cu mâna, la
-- fiecare schimbare, pentru totdeauna. Oglinda cerută e un lucru privit de două
-- ori, nu două lucruri.
--
-- URMAREA, SPUSĂ PE FAȚĂ. `sort` și `category` rămân coloane unice, deci cele
-- două cataloage NU POT ieși din ordine sau din grupare — de-acolo vine
-- oglindirea, pe gratis. Dar tot de-acolo vine și ce nu se va putea: „vreau
-- altă ordine la gata de servire" cere o a doua coloană de ordine, adică încă o
-- migrare. Nu e o scăpare, e alegerea făcută aici.
--
-- DE CE `photo` NU SE REDENUMEȘTE. Ar fi fost mai frumos `photo_frozen` lângă
-- `photo_baked`. Dar `photo` e citit din opt locuri, iar pe server fișierele
-- ajung ÎNAINTE de importul SQL: în fereastra dintre ele, codul nou ar cere o
-- coloană care încă nu există și fiecare poză de pe site ar fi goală. Deci
-- `photo` își păstrează numele și capătă un înțeles: e latura congelată ȘI poza
-- de bază, pe care cade cealaltă latură când n-are una a ei.
--
-- PAZA DE DINAINTE. `.claude/curata-probe.mjs` șterge de pe disc — și din
-- fiecare copie din `deploy/` — orice fotografie urcată pe care n-o mai
-- pomenește nicio coloană de poză. Până la runda asta întreba o singură
-- coloană, scrisă cu mâna. O poză legată numai de `photo_baked` ar fi fost
-- socotită fără stăpân și ștearsă tăcut, la prima curățenie. De-aia lista de
-- coloane (`COLOANE_POZA`, `.claude/poze-fara-produs.mjs`) s-a schimbat
-- ÎNAINTEA migrării ăsteia, iar `.claude/check-sql.mjs` o ține de-acum în pas
-- cu `sql/`, la amândouă capetele.

SET NAMES utf8mb4;

-- ------------------------------------------------------ 0. opritorul --
--
-- `actualizeaza-vps.sh` rulează TOATE migrările la fiecare actualizare. Pasul
-- 4 de mai jos scrie în `products`, deci fără opritor ar rula și el de fiecare
-- dată — și ar pune la loc, lună după lună, fiecare latură pe care patronul o
-- stinsese cu mâna. Exact pățania migrării 016, scrisă pe larg acolo și păzită
-- de `.claude/test-migrari-repetate.mjs`.
--
-- Opritorul e coloana însăși, numărată ÎNAINTE de `ALTER`: după, ar fi mereu
-- acolo și pasul n-ar rula niciodată, nici prima dată.
--
-- Se numără `in_frozen`, prima adăugată. Ce NU apără: o rulare ruptă între
-- primul `ALTER` și pasul 4 — coloanele ar rămâne, iar umplerea n-ar mai rula.
-- De-aia `actualizeaza-vps.sh` se oprește la prima eroare și spune cum se pune
-- la loc copia bazei făcută înainte de migrări.
SET @coloane_noi := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                        AND COLUMN_NAME = 'in_frozen');

-- ------------------------------------------- 1. apartenența la catalog --
--
-- TINYINT(1) NOT NULL DEFAULT 1, ca `is_new` și `active` de lângă ele: e
-- același fel de bifă, și se citește la fel de des.
--
-- DEFAULT 1, NU 0, și nu din optimism. Pe server fișierele ajung înaintea
-- importului SQL, deci există o fereastră în care codul NOU rulează peste baza
-- VECHE — dar și una în care codul VECHI ar putea naște produse după ce
-- coloanele au apărut: inserarea din `api/routes/catalog.php` nu numește
-- coloanele astea, fiindcă nu le știe. Cu DEFAULT 0, orice produs născut așa
-- s-ar naște în NICIUN catalog — nevăzut nicăieri, fără nicio eroare, și fără
-- ca patronul să aibă de unde bănui de ce. Cu DEFAULT 1 se naște în amândouă,
-- adică exact ca azi.
--
-- Fără index pe niciuna. Se citesc întotdeauna odată cu produsul, iar produsele
-- sunt zeci: cernerea se face în PHP, după ce rândurile au fost oricum aduse
-- toate. Un index ar fi încă o scriere la fiecare bifă, pentru o cernere de
-- treizeci de rânduri.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'in_frozen') = 0,
  'ALTER TABLE products
     ADD COLUMN in_frozen TINYINT(1) NOT NULL DEFAULT 1 AFTER price_baked',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'in_baked') = 0,
  'ALTER TABLE products
     ADD COLUMN in_baked TINYINT(1) NOT NULL DEFAULT 1 AFTER in_frozen',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 2. poza laturii coapte --
--
-- VARCHAR(255) NULL, ca `photo`: e același fel de valoare — o cale scrisă cu
-- prefixul din `photo_web_prefix()`, de pildă „products/prod-7-1a2b3c4d.jpg".
--
-- NULL înseamnă „latura asta n-are poză a ei", și atunci se arată poza
-- celeilalte. E o hotărâre a patronului, nu o scăpare: pozele se urcă una câte
-- una, iar un produs n-are voie să apară gol pe site în ziua dintre cele două
-- urcări.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'photo_baked') = 0,
  'ALTER TABLE products
     ADD COLUMN photo_baked VARCHAR(255) NULL AFTER photo',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------- 3. ce NU se adaugă aici --
--
-- Nicio coloană de poză pentru latura congelată: aceea e `photo`. Două coloane
-- noi ar fi însemnat să mutăm datele din `photo` în una din ele, adică tocmai
-- redenumirea de care ne-am ferit mai sus.
--
-- Și nicio a doua coloană de ordine (`sort_baked`), deși ar fi ieftină acum:
-- n-a cerut-o nimeni, iar o coloană care n-are ecran se umple tăcut cu zerouri
-- și se uită. Când se cere, se adaugă — v. „URMAREA, SPUSĂ PE FAȚĂ" de sus.

-- ------------------------------------------- 4. umplerea, o singură dată --
--
-- INVARIANTUL, care de-acum e păzit de server: dacă un produs e într-un
-- catalog, are prețul acelei laturi. Niciodată pe dos — un preț poate sta în
-- casetă și cu latura stinsă, fiindcă patronul a cerut anume ca scoaterea
-- dintr-un catalog să NU șteargă prețul.
--
-- UMPLEREA E PRUDENTĂ, ȘI ASTA E O HOTĂRÂRE, NU O TEAMĂ. Forma îndrăzneață ar
-- fi fost „in_frozen = price_frozen IS NOT NULL" — adică „cine are preț de raft
-- intră la gata de servire". Ar fi ghicit apartenența din preț, exact ce a
-- hotărât patronul să nu se facă: apartenența se bifează. Și ar fi publicat
-- produse pe jumătate completate în secunda importului, fără niciun ecran între
-- el și site.
--
-- Așa cum e scrisă, migrarea NU MIȘCĂ NIMIC PE VITRINĂ: un produs cu amândouă
-- prețurile rămâne în amândouă cataloagele, unul cu un singur preț rămâne
-- ascuns — ca azi. Ce se schimbă e numai vorba cu care panoul îl arată: „nu e
-- în niciun catalog" în loc de „îi lipsește un preț".
--
-- „În niciun catalog" e o stare ADEVĂRATĂ, nu una stricată: e chiar produsul
-- ascuns de azi. Serverul nu te lasă să intri în ea prin cele două bife —
-- pentru „nu se mai vinde deloc" există butonul de scos din catalog — dar ea
-- poate exista, iar panoul trebuie s-o spună pe față.
--
-- Se scrie cu PREPARE, ca pașii 2 și 3 din 016, ca să fie adevărat pe litere că
-- o rulare de după nu atinge produsele: acolo UPDATE-ul nici nu se pregătește.
SET @sql := IF(@coloane_noi = 1,
  'UPDATE products
      SET in_frozen = (price_frozen IS NOT NULL AND price_baked IS NOT NULL),
          in_baked  = (price_frozen IS NOT NULL AND price_baked IS NOT NULL)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ce NU face migrarea asta, ca să nu se caute mai târziu:
--
--   * nu atinge `photo` al niciunui produs. Poza de azi rămâne unde e și
--     rămâne poza de bază a amândurora;
--   * nu atinge `active`. Un produs scos din catalog rămâne scos, oricâte
--     laturi ar avea aprinse;
--   * nu adaugă nicio grupă. Grupele care se văd numai la gata de servire ies
--     singure: o grupă fără produse pe latura deschisă se ascunde, fără nicio
--     bifă pe ea.

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`, unde e scris pe larg
-- de ce există și de ce se face înaintea buclei. Rândul de mai jos se scrie
-- numai dacă registrul e acolo — importată singură, de mână, pe o bază fără
-- `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('024_doua_cataloage.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
