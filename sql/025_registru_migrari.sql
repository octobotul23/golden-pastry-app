-- Migrarea 025 — registrul migrărilor: urma scrisă că o migrare a rulat
--
-- Se rulează o singură dată, după 024. Poate fi rulată de câte ori vrei fără
-- pagubă: tabelul se face numai dacă lipsește, iar rândurile se scriu cu
-- `INSERT IGNORE`, deci a doua rulare nu face nimic.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/025_registru_migrari.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- PROBLEMA. Casa n-avea registru de migrări. Fiecare migrare se apără singură,
-- uitându-se în `information_schema` dacă mai are ce face, și nu lasă nicio
-- urmă că a rulat. Cât timp o migrare aduce o coloană sau un tabel, urma ei se
-- poate citi din schemă — și chiar asta face `api/verifica.php`, migrare cu
-- migrare.
--
-- Dar `017_procent_recomandare.sql` nu aduce nici coloană, nici tabel: scrie un
-- singur rând în `settings`, `referral_rate_default`, pe care `008` îl pusese
-- deja acolo cu valoarea 0. Prezența rândului nu deosebește o bază cu 017 de
-- una fără, iar valoarea nu se poate judeca: 017 o îndreaptă la 5 numai acolo
-- unde n-a atins-o omul, deci un zero pus de patron rămâne zero, pe bună
-- dreptate.
--
-- Așa că pagina de verificare arăta tot verde, iar patronul credea că
-- instalarea e întreagă. Dacă 017 n-a intrat, recomandările se leagă și se
-- numără, dar aduc 0 lei — și nimic din aplicație nu-l anunță. Trebuia să se
-- ducă în Setări → General și să se uite cu ochiul.
--
-- (Singura urmă de până acum era `settings.migration_010_origin_judged`, și
-- aceea din întâmplare: `010` avea nevoie de ea pentru propria idempotență, nu
-- ca să se poată vedea din afară.)
--
-- CE SE SCHIMBĂ. Fiecare migrare de la `003` încolo își scrie numele aici, la
-- coada ei. `api/verifica.php` pune registrul față în față cu lista migrărilor
-- pe care le cunoaște codul și spune pe nume care lipsește — nu mai ghicește
-- din amprenta schemei.
--
-- DE CE DE LA `003`, ȘI NU DE LA `001`. Fiindcă `001` și `002` nu sunt migrări:
-- sunt instalarea de la zero. Toate cele trei drumuri de urcare sar peste ele
-- în bucla de migrări — `actualizeaza-vps.sh`, `instaleaza-vps.sh` și bucla
-- scrisă de mână în `docs/08` —, iar `002` are numai `INSERT IGNORE`, care pare
-- inofensiv, dar `IGNORE` sare doar peste chei duplicate: un produs șters din
-- Catalog n-are cu ce se ciocni și s-ar întoarce la fiecare actualizare, în
-- catalogul clienților. Registrul ține deci exact ce rulează: migrările.
--
-- DE CE TABELUL SE FACE ÎNAINTEA BUCLEI. Fișierul ăsta se cheamă `025`, deci în
-- ordinea numelui rulează DUPĂ `003`-`024`. Dacă tabelul s-ar naște abia
-- atunci, primele douăzeci și două de migrări ar trece pe lângă un registru
-- care încă nu există, n-ar avea unde-și scrie numele, și registrul s-ar umple
-- abia la actualizarea următoare — o rundă întreagă în care pagina n-ar ști
-- nimic.
--
-- De-aia cele trei drumuri rulează fișierul ĂSTA o dată, înaintea buclei, și
-- abia apoi bucla. Se poate tocmai fiindcă e idempotent: rulat de două ori în
-- aceeași trecere, a doua oară nu face nimic.
--
-- DE CE NU SE UMPLE CU MÂNA. S-ar putea scrie aici, dintr-o suflare, numele
-- tuturor migrărilor până la `024`: „dacă ai ajuns la 025, înseamnă că au rulat
-- și alea". E adevărat când rulează bucla — care le ia în ordinea numelui și se
-- oprește la prima care pică — și e o minciună când cineva importă fișierul
-- ăsta singur, din phpMyAdmin, pe o bază veche. Un registru care minte e mai
-- rău decât niciun registru: pagina de verificare există tocmai ca să prindă o
-- urcare pe jumătate.

-- ------------------------------------------- 1. tabelul --------------------

CREATE TABLE IF NOT EXISTS schema_migrations (
  -- Numele fișierului, cu tot cu `.sql`: `017_procent_recomandare.sql`. E cheia
  -- primară, deci `INSERT IGNORE` scrie rândul o singură dată, oricâte rulări
  -- ar urma.
  fisier      VARCHAR(190)  NOT NULL,
  -- Când a fost văzută ÎNTÂIA oară. Nu se atinge la rulările următoare: ne
  -- interesează când a intrat, nu când a mai trecut scriptul pe lângă ea.
  aplicata_la DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (fisier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------- 2. urma că a rulat ------------
--
-- Blocul ăsta stă la coada fiecărei migrări de la `003` încolo, identic, numai
-- numele fișierului se schimbă. Se scrie numai dacă registrul există: cine
-- importă o migrare singură, de mână, pe o bază fără `025`, trebuie să vadă
-- migrarea trecând, nu picând pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('025_registru_migrari.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
