-- Migrarea 020 — indexul pentru insigna de client
--
-- Se rulează o singură dată, după 019. Poate fi rulată de câte ori vrei fără
-- pagubă: indexul se adaugă numai dacă lipsește.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/020_rang_client.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- Fiecare comandă și fiecare cerere poartă de acum o insignă care spune cine e
-- omul: prima lui comandă, client care a mai comandat, ori comandă fără cont.
-- Socoteala se face o singură dată pe lot, în `primele_comenzi()`
-- (api/lib/orders.php):
--
--   SELECT customer_id, MIN(id) FROM orders
--    WHERE customer_id IN (...) AND status <> 'Anulată'
--    GROUP BY customer_id
--
-- Indexul de azi, `ix_orders_customer (customer_id, day)`, ține ziua LIVRĂRII,
-- care n-are nicio treabă cu întrebarea. Deci interogarea găsea rândurile după
-- cont, dar trebuia să se ducă la fiecare rând ca să-i citească etapa.
--
-- `(customer_id, status)` o face să se termine în index: InnoDB lipește cheia
-- primară la coada oricărui index secundar, deci rândurile de index sunt
-- (customer_id, status, id) — adică exact cele trei coloane din interogare.
-- Niciun rând de tabel nu mai e atins.
--
-- SE POATE UITA FĂRĂ PAGUBĂ. Nu există nicio poartă în PHP care să caute
-- indexul ăsta: fără el interogarea merge la fel, doar mai încet. Spre
-- deosebire de 019, unde tabelul care lipsește chiar oprește o lucrare.
--
-- DE CE NU O COLOANĂ „a câta comandă" PE `orders`. Ar trebui rescrisă de câte
-- ori se anulează o comandă veche — adică o cifră ținută la zi cu mâna, care
-- se depărtează de adevăr la prima uitare. Numărătoarea din mers costă o
-- interogare pe lot și nu poate minți.

SET NAMES utf8mb4;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'ix_orders_client_rang') = 0,
  'ALTER TABLE orders ADD KEY ix_orders_client_rang (customer_id, status)',
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
  "INSERT IGNORE INTO schema_migrations (fisier) VALUES ('020_rang_client.sql')",
  'DO 0');
PREPARE gp_reg_stmt FROM @gp_reg_sql; EXECUTE gp_reg_stmt; DEALLOCATE PREPARE gp_reg_stmt;
