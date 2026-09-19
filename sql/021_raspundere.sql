-- Migrarea 021 — răspunderea: cine a scris comanda, cine era de tură
--
-- Se rulează o singură dată, după 020. Poate fi rulată de câte ori vrei fără
-- pagubă: fiecare bucată se uită întâi dacă nu e deja acolo.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/021_raspundere.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- Patru bucăți, toate pentru aceeași întrebare: CINE. Cine a scris comanda
-- (1, 2, 3) și cine era în laborator când s-a făcut (4).
--
-- CE NU E AICI: codul de recomandare al agenților fără cod. Codul se face din
-- NUMELE omului, cu dezambiguizare la ciocnire (`referral_assign()`,
-- api/lib/referral.php:188) — o socoteală pe care SQL-ul n-o poate face la fel,
-- iar două socoteli deosebite pentru același cod ar da coduri deosebite. Se
-- face deci în PHP, la nevoie, în `route_users_create()`. Ajutorul acela sare
-- singur peste conturile care au deja cod, deci nu strică nimic rulat de două
-- ori.

SET NAMES utf8mb4;

-- --------------------------------------------- 1. indexul autorului --------
--
-- `orders.created_by` există din 001 (rândul 213) și e scris pe amândouă căile
-- prin care se naște o comandă — `route_orders_create()` și
-- `route_requests_accept()`. Până azi nu-l citea NIMENI: nici `attach_items()`,
-- nici vreo rută, nicio pagină. De-acum se citește la fiecare listă de comenzi,
-- ca să se vadă cine a scris-o.
--
-- Indexul e pe o singură coloană fiindcă întrebarea e una singură: „numele
-- conturilor din lotul ăsta". Se caută cu `WHERE id IN (...)` pe `users`, deci
-- ce se câștigă aici e strângerea lui `created_by` din comenzi fără să se ia
-- fiecare rând în parte când se cere lista unui singur autor.
--
-- SE POATE UITA FĂRĂ PAGUBĂ, ca 020: nicio poartă din PHP nu-l caută. Fără el
-- socoteala iese la fel, doar mai încet.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'ix_orders_autor') = 0,
  'ALTER TABLE orders ADD KEY ix_orders_autor (created_by)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------- 2. Telegramul pe cont -------
--
-- PROBLEMA. `telegram_actor()` (api/routes/telegram.php:209) citește O SINGURĂ
-- setare globală, `notify_telegram_actor_id`, scrisă o dată la legarea botului,
-- din cine a apăsat atunci. Iar `$cb['from']['id']` — omul care chiar apasă
-- acum — nu se citește niciodată. Deci orice comandă născută dintr-un buton de
-- Telegram poartă numele celui care a legat botul, oricine ar fi apăsat. Cu
-- autorul scos la vedere (bucata 1), asta n-ar mai fi o nepotrivire ascunsă în
-- bază: ar fi un nume greșit pe ecran.
--
-- BIGINT UNSIGNED: identificatorii de Telegram au trecut de 2^31 și cresc. Un
-- INT ar fi ținut până acum câțiva ani și pe urmă ar fi tăiat tăcut.
--
-- UNIQUE: două conturi n-au voie să pretindă același Telegram. Fără el, o
-- legare greșită ar face ca apăsarea să fie creditată „vreunuia" dintre ele —
-- adică exact minciuna pe care lucrarea o repară, mutată cu un pas mai încolo.
-- NULL nu se ciocnește cu NULL în MySQL, deci conturile nelegate (aproape
-- toate) trec toate.
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'telegram_user_id') = 0,
  'ALTER TABLE users ADD COLUMN telegram_user_id BIGINT UNSIGNED NULL AFTER lab_pin',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND INDEX_NAME = 'uq_users_telegram') = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_telegram (telegram_user_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------- 3. legarea în doi pași ------
--
-- DE CE NU LEAGĂ APĂSAREA SINGURĂ. Butonul de legare pleacă în GRUPUL
-- personalului — singurul chat pe care botul îl are voie să-l vadă
-- (`notify_telegram_chat_id`, telegram.php:110-116). Deci cine apasă nu e
-- neapărat cine a cerut: un coleg care apasă primul ar căpăta de-atunci înainte
-- comenzile scrise pe numele altuia. Tocmai minciuna de reparat, pe dos.
--
-- Deci apăsarea nu leagă, ci PROPUNE: scrie pe rândul cererii cine a apăsat, iar
-- legătura se mută pe `users.telegram_user_id` numai când cel care a cerut-o
-- spune „da, eu sunt". Nici graba, nici răutatea nu leagă pe nimeni.
--
-- Tiparul e al casei — tabel deoparte, numai cu amprenta secretului, ca
-- `password_resets` (001), `founder_invites` (009) și `request_claims` (019).
-- Jetonul în clar există o singură dată: în butonul trimis în grup.
--
-- `user_id` UNIQUE: o cerere de legare deschisă pe om. A doua cerere o stinge
-- pe prima, cum face „am uitat parola".
--
-- `telegram_name` e pentru ecranul de încuviințare — „cineva cu Telegramul
-- @cutare a apăsat". Un număr gol n-ar spune nimănui nimic, iar omul trebuie să
-- recunoască pe cine confirmă. Se păstrează cum a venit, la clipa apăsării.
CREATE TABLE IF NOT EXISTS telegram_links (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  -- se umplu la apăsare, rămân NULL cât nimeni n-a apăsat
  pressed_tg_id BIGINT UNSIGNED NULL,
  pressed_name  VARCHAR(128) NULL,
  pressed_at    DATETIME     NULL,
  expires_at    DATETIME     NOT NULL,
  -- clipa încuviințării, adică singura în care se scrie pe `users`
  used_at       DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tglink_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_tglink_user (user_id),
  KEY ix_tglink_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------- 4. producția capătă ceas ----
--
-- PROBLEMA. Angajații introduc deja câte au făcut: `+`/`−` din „Panou
-- Laborator" scriu `order_items.done_qty` prin `POST /orders/{id}/production`.
-- Dar `done_qty` e un TOTAL CURENT, fără nicio clipă lipită de el. Deci nu se
-- poate spune când s-a făcut, deci nu se poate potrivi cu nicio tură.
--
-- DE CE NU `stock_moves`, care are deja ceas, `order_id` și `user_id`: când
-- articolul de produs finit lipsește din depozit, nu se scrie NICIUN rând
-- (api/routes/orders.php, `missing[]`). Un registru cu goluri n-are ce căuta
-- într-un raport care se citește lângă un stat de plată. Iar `user_id` de acolo
-- e contul comun al tabletei („panou"), nu omul.
--
-- DELTA, NU TOTALUL. `doneQty` sosește de la tabletă ca total nou, dinadins
-- (e idempotent: o cerere pierdută se vindecă singură la următoarea). Aici se
-- scrie DIFERENȚA față de cât era. Scris ca total, raportul ar număra de două
-- ori aceleași bucăți la a doua atingere a aceleiași poziții. Corecțiile în jos
-- sunt îngăduite în laborator, deci delta are voie să fie negativă — și se
-- adună cu semn, ca `stock_moves.direction`.
--
-- CEASUL, ȘI CAPCANA CARE STRICĂ TOT RAPORTUL DACĂ SE GREȘEȘTE O DATĂ.
-- Toate legăturile la bază pornesc cu `SET time_zone = '+00:00'`
-- (api/lib/db.php:45), deci orice `DEFAULT CURRENT_TIMESTAMP` scrie o clipă
-- UTC. Turele, în schimb, se scriu cu `now_local()` — ora Bucureștiului —,
-- dinadins, fiindcă ora de pontaj se citește lângă ceasul de pe peretele
-- laboratorului (pricina întreagă la api/lib/http.php:317-333).
--
-- Potrivite între ele, cele două ceasuri ar da ture greșite cu două-trei ore,
-- TĂCUT: raportul ar arăta plin și ar minți. De-aceea `at` și `day` NU au
-- `DEFAULT CURRENT_TIMESTAMP` — se scriu din PHP cu `now_local()` și
-- `today_local()`, exact ca `lab_shifts`. `created_at` rămâne UTC, ca peste tot
-- în casă: el spune când a ajuns rândul în bază, nu la ce oră s-a lucrat.
--
-- `name` la clipa producției, ca la `order_items` și `stock_moves`: poziția
-- rescrisă sau produsul redenumit lasă raportul vechi lizibil.
--
-- `order_id` cu `ON DELETE SET NULL`, nu CASCADE: bucățile s-au făcut,
-- indiferent ce s-a hotărât mai târziu despre comandă. `item_id` n-are cheie
-- străină deloc — pozițiile se rescriu la fiecare editare de comandă
-- (`fk_items_order ... ON DELETE CASCADE`), iar o cheie de-acolo ar șterge
-- munca odată cu rândul rescris.
CREATE TABLE IF NOT EXISTS lab_production (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NULL,
  item_id    INT UNSIGNED NULL,
  name       VARCHAR(160) NOT NULL,
  delta      DECIMAL(10,2) NOT NULL,
  -- ora patiseriei, scrisă din PHP. Vezi lămurirea de mai sus.
  at         DATETIME     NOT NULL,
  day        DATE         NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prod_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  KEY ix_prod_at (at),
  KEY ix_prod_day (day),
  KEY ix_prod_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
