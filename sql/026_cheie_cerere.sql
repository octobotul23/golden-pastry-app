-- Migrarea 026 — cheia cu care serverul recunoaște a doua trimitere a aceleiași cereri
--
-- Se rulează o singură dată, după 025. Poate fi rulată de câte ori vrei fără
-- pagubă: și coloana, și indexul se fac numai dacă lipsesc.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/026_cheie_cerere.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- PROBLEMA DE REZOLVAT. Clientul apasă „Trimite comanda" de pe telefon, din
-- depozit, cu semnal slab. Serverul scrie cererea, pornește anunțurile, și
-- trimite 201 — dar răspunsul se pierde pe drum. `fetch` nu are de unde ști
-- dacă cererea a plecat ori s-a pierdut la dus, deci pagina primește
-- `code: 'network'` și atâta tot. Omul mai apasă o dată. Laboratorul primește
-- DOUĂ cereri identice, la aceeași oră, de la același client — și exact așa
-- arată și două comenzi adevărate, deci administratorul le acceptă pe amândouă:
-- marfă dublată, rezervări dublate, bani dublați.
--
-- Semaforul din pagină (`__trimit`, Site Public.dc.html) oprește apăsările cât
-- cererea e pe drum, dar nu are ce face după ce drumul s-a rupt. Iar mesajul
-- îndulcit din 2.1.1 („nu știm dacă a ajuns — uită-te întâi acolo unde s-ar
-- vedea") spune adevărul, dar îl lasă pe om să hotărască. Un om grăbit apasă.
--
-- HOTĂRÂREA PATRONULUI: „ÎI ARĂTĂM CĂ A REUȘIT." A doua trimitere a aceleiași
-- cereri nu se refuză și nu se numără. Serverul o recunoaște și răspunde cu
-- cererea dintâi, ca și cum atunci ar fi sosit: același 201, același rând,
-- același număr. Omul vede „Mulțumim", fiindcă asta s-a și întâmplat — comanda
-- lui e la noi. Laboratorul primește UNA.
--
-- Celelalte două căi au fost cântărite și lăsate:
--   * un refuz („ai trimis-o deja") ar fi arătat o eroare roșie pentru ceva ce
--     a mers bine, iar omul n-ar fi știut care din două e adevărul;
--   * o a doua cerere legată de prima („dublură, de verificat") ar fi mutat
--     hotărârea pe umerii administratorului, adică taman de unde s-a luat.
--
-- CE E CHEIA. Treizeci și două de cifre hexazecimale scrise de browser, din
-- `crypto.getRandomValues` — 128 de biți de întâmplare. Se naște ODATĂ pe
-- apăsare și rămâne aceeași la retrimiterea aceleiași comenzi; comanda
-- următoare capătă alta. Deci două trimiteri cu aceeași cheie sunt, prin
-- construcție, aceeași comandă trimisă de două ori.
--
-- DE CE PE RÂNDUL CERERII, ȘI NU ÎNTR-UN TABEL DEOPARTE. Jetonul din 019 stă
-- deoparte fiindcă e un SECRET pe care rândul cererii îl plimbă prin acceptare
-- și prin anunțuri, iar prima uitare l-ar trimite în lume. Cheia de aici nu e
-- un secret al casei: e a browserului care a scris-o, și ea trebuie să stea
-- chiar pe rândul pe care-l păzește — altfel `UNIQUE` n-ar avea ce păzi, iar
-- două cereri ar putea intra amândouă înainte ca vreuna să apuce să-și scrie
-- cheia în alt tabel. Paza e indexul, nu grija codului.
--
-- DE CE `UNIQUE` ȘI NU O CĂUTARE ÎNAINTE DE SCRIERE. O căutare urmată de o
-- scriere are o crăpătură între ele: două cereri plecate în aceeași secundă (și
-- exact asta se întâmplă când omul apasă de două ori pe o legătură care se
-- îneacă) trec amândouă de căutare și scriu amândouă. Indexul nu are crăpătură:
-- a doua scriere cade cu 1062, iar codul o prinde și întoarce rândul dintâi.
--
-- NULL NU SE CIOCNEȘTE CU NULL în MySQL, deci toate cererile de până azi — și
-- toate cele care vin de la o pagină mai veche, sau prin curl — intră mai
-- departe, fără cheie și fără paza asta, întocmai ca ieri.
--
-- CÂT DE LUNGĂ, ȘI DE CE CONTEAZĂ. Serverul primește numai chei de FIX 32 de
-- cifre hexazecimale (`api/routes/requests.php`); orice altceva e citit ca
-- „fără cheie", iar cererea trece ca ieri. Nu e mofturi: răspunsul la a doua
-- trimitere e chiar rândul cererii dintâi, cu numele, telefonul și adresa pe
-- el. Cine ar putea ALEGE cheia ar putea alege una scurtă — „1" —, iar al
-- doilea om care ar nimeri-o ar primi cererea celui dintâi. Cu pragul de 128 de
-- biți pus pe server, o cheie slabă nu intră niciodată în tabel, deci nu e
-- nimic de nimerit. Ghicirea uneia adevărate e la fel de departe ca ghicirea
-- jetonului din 019, și trece și prin același prag de cereri pe conexiune.

SET NAMES utf8mb4;

-- --------------------------------------------------- 1. coloana ------------
--
-- `AFTER created_at`: ultima coloană din `001_schema.sql`, deci singura poziție
-- care există pe ORICE bază. `AFTER created_by` ar fi cerut ca 022 să fi rulat
-- deja, iar o migrare care se sprijină pe alta se rupe tocmai la reimportul de
-- mână, când omul aduce un singur fișier.
--
-- CHAR(32), nu VARCHAR: lungimea e fixă prin însăși regula de mai sus. Un rând
-- fără cheie ține NULL, care nu ocupă nimic.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND COLUMN_NAME = 'idempotency_key') = 0,
  'ALTER TABLE order_requests ADD COLUMN idempotency_key CHAR(32) NULL AFTER created_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------- 2. paza ---------------
--
-- Indexul E reparația. Fără el, coloana ar fi doar o însemnare pe care nimeni
-- n-o citește la timp.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_requests'
      AND INDEX_NAME = 'uq_req_cheie') = 0,
  'ALTER TABLE order_requests ADD UNIQUE KEY uq_req_cheie (idempotency_key)',
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
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('026_cheie_cerere.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
