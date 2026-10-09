-- Migrarea 034 — prețul de cumpărare al articolelor din depozit
--
-- Se rulează o singură dată, după 033. Poate fi rulată de câte ori vrei fără
-- pagubă: `CREATE TABLE IF NOT EXISTS` nu atinge un tabel care există deja, și
-- migrarea nu scrie niciun rând în el.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/034_costuri.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- CEREREA PATRONULUI, 4 octombrie 2026: să poată scrie cât costă făina, ouăle,
-- untul, să vadă cât îl costă fiecare produs și cât îi rămâne din el, și să
-- poată schimba prețurile când se schimbă.
--
-- TABELUL `stock_item_prices` ține ISTORICUL prețurilor, nu numai pe cel de azi:
--
--   `item_id`     articolul din depozit (făină, ouă, cutii…).
--   `price`       lei pe UNITATEA articolului, fără TVA ori cu — cum îl scrie
--                 patronul, același peste tot. Patru zecimale: un gram de
--                 drojdie costă sub un ban, iar rotunjit la doi bani costul
--                 unui covrig ar ieși zero.
--   `unit`        unitatea articolului ÎN CLIPA scrierii prețului. Articolul
--                 poate fi trecut mai târziu din kg în g; prețul vechi ar
--                 rămâne atunci „3,45 lei" pe o unitate de o mie de ori mai
--                 mică, și fiecare cost ar sări de o mie de ori. Cu unitatea
--                 ținută lângă preț, nepotrivirea se VEDE și se spune.
--   `valid_from`  ziua de la care e bun prețul. Costul unei zile se socotește
--                 cu ultimul preț de dinaintea ei — de aceea trecutul rămâne
--                 cu prețul de atunci, cum a hotărât patronul.
--   `note`        de pe ce factură, de la ce furnizor.
--   `set_by`, `set_at`  cine l-a scris și când.
--
-- UN PREȚ PE ZI, PE ARTICOL (`uq_item_prices_day`): a doua scriere în aceeași
-- zi e o CORECTURĂ a primei, nu un al doilea preț.
--
-- DE CE UN TABEL AL LUI, și nu o coloană pe `stock_items`. Două pricini:
--   * istoricul — o coloană ține un singur preț, iar profitul lunii trecute
--     s-ar socoti atunci cu făina de azi;
--   * ferirea — prețurile sunt, din pornire, numai ale fondatorului (cheia
--     `preturi` din „Acces"). Depozitul îl deschid și angajatul, și tableta din
--     laborator; cu prețul pe rândul articolului, orice `SELECT i.*` de acolo
--     l-ar fi cărat cu el. Într-un tabel al lui, prețul nu pleacă decât pe
--     rutele care îl cer anume (api/routes/costuri.php).
--
-- PREȚUL ZERO E UN PREȚ. Un articol primit pe gratis are costul 0, nu „lipsă";
-- de aceea coloana e NOT NULL și „n-are preț" înseamnă „n-are niciun rând".
--
-- CE NU FACE:
--   * nu scrie niciun preț: tabelul pornește gol, iar până la primul preț
--     costul unui produs se arată „nu se poate socoti", nu zero;
--   * nu atinge articolele, rețetele ori mișcările din depozit;
--   * nu are semn în `settings`: nu face nimic „o singură dată".

SET NAMES utf8mb4;

-- ------------------------------------------- tabelul prețurilor ------------
--
-- `ON DELETE CASCADE` spre articol: prețurile unui articol șters de-a binelea
-- nu mai au al cui fi. (Un articol cu istoric nu se șterge — se scoate din
-- listă, v. `route_items_delete()` —, deci cascada atinge numai greșelile de
-- tastare de acum două minute.) `set_by` cu `SET NULL`, ca peste tot.

CREATE TABLE IF NOT EXISTS stock_item_prices (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id    INT UNSIGNED NOT NULL,
  price      DECIMAL(12,4) NOT NULL,
  unit       VARCHAR(16)  NOT NULL,
  valid_from DATE         NOT NULL,
  note       VARCHAR(255) NULL,
  set_by     INT UNSIGNED NULL,
  set_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_item_prices_item FOREIGN KEY (item_id) REFERENCES stock_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_prices_user FOREIGN KEY (set_by)  REFERENCES users(id)       ON DELETE SET NULL,
  UNIQUE KEY uq_item_prices_day (item_id, valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------- urma că a rulat ------------
--
-- Registrul migrărilor: `sql/025_registru_migrari.sql`. Rândul de mai jos se
-- scrie numai dacă registrul e acolo — importată singură, de mână, pe o bază
-- fără `025`, migrarea asta trebuie să treacă, nu să pice pe un tabel lipsă.

SET @gp_reg := (SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations');
SET @gp_reg_sql := IF(@gp_reg > 0,
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('034_costuri.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
