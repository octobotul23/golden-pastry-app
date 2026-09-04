-- Golden Pastry — schema bazei de date
-- MySQL 5.7+ / MariaDB 10.3+  •  utf8mb4 pentru diacritice românești
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/001_schema.sql
-- Rulare pe cPanel:         phpMyAdmin → baza de date → Import → acest fișier

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------- conturi --

CREATE TABLE IF NOT EXISTS users (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(64)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  name                 VARCHAR(128) NOT NULL,
  email                VARCHAR(190) NULL,
  phone                VARCHAR(32)  NULL,
  -- Adresa la care i se duce marfa. Ținută pe cont ca să nu fie scrisă din
  -- nou la fiecare comandă; administratorul o poate corecta la acceptare.
  -- Aceeași lungime ca în cerere și în comandă: la 190 aici, o adresă lungă
  -- trecea de cerere și pica abia la scrierea pe cont, cu „Eroare internă".
  address              VARCHAR(255) NULL,
  role                 ENUM('admin','angajat','client') NOT NULL DEFAULT 'client',
  customer_name        VARCHAR(160) NULL,
  active               TINYINT(1)   NOT NULL DEFAULT 1,
  -- Conturi de sistem (contul „panou" al tabletelor): nu pot fi șterse sau
  -- redenumite din interfață, altfel tabletele din laborator rămân blocate.
  is_system            TINYINT(1)   NOT NULL DEFAULT 0,
  -- forțează schimbarea parolei la prima autentificare (conturi create de admin)
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  -- Momentul în care omul și-a schimbat SINGUR parola. Rămâne NULL cât timp
  -- pe cont e parola pusă de administrator. Pagina Conturi arăta aici
  -- `last_login`, deci scria „parolă schimbată azi" despre un cont care doar
  -- se autentificase cu parola primită pe WhatsApp — exact informația inversă
  -- față de ce caută administratorul când verifică ce parole mai circulă.
  password_changed_at  DATETIME     NULL,
  failed_logins        INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until         DATETIME     NULL,
  last_login           DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  KEY ix_users_role (role, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesiunile păstrează doar hash-ul tokenului: dacă baza de date e compromisă,
-- tokenurile din ea nu pot fi folosite pentru a se autentifica.
CREATE TABLE IF NOT EXISTS sessions (
  id          CHAR(64)     NOT NULL PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  csrf_token  CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  ip          VARCHAR(45)  NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY ix_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME     NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY ix_resets_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- produse --

-- Catalogul are două niveluri de preț (HoReCa / retail), denumiri și descrieri
-- bilingve — exact cum sunt azi în „Site Public.dc.html".
-- Grupele din catalog: ștrudele, trigoane, croissante… Sunt un tabel, nu o
-- listă de text, fiindcă fiecare are nume și subtitlu în două limbi și o
-- ordine proprie în pagină. Se editează din pagina Produse.
CREATE TABLE IF NOT EXISTS product_categories (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- cheia scurtă folosită de produse; nu se schimbă după ce există produse
  slug    VARCHAR(64)  NOT NULL,
  name    VARCHAR(96)  NOT NULL,
  name_en VARCHAR(96)  NOT NULL,
  -- subtitlul de sub numele grupei, în catalog
  note    VARCHAR(160) NULL,
  note_en VARCHAR(160) NULL,
  sort    INT          NOT NULL DEFAULT 0,
  active  TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY uq_pcat_slug (slug),
  KEY ix_pcat_active (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug           VARCHAR(96)  NOT NULL,
  name           VARCHAR(160) NOT NULL,
  name_en        VARCHAR(160) NULL,
  description    TEXT         NULL,
  description_en TEXT         NULL,
  photo          VARCHAR(255) NULL,
  grams          INT UNSIGNED NULL,
  price_frozen   DECIMAL(10,2) NULL,
  price_baked   DECIMAL(10,2) NULL,
  category       VARCHAR(64)  NULL,
  is_new         TINYINT(1)   NOT NULL DEFAULT 0,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  sort           INT          NOT NULL DEFAULT 0,
  UNIQUE KEY uq_products_slug (slug),
  KEY ix_products_active (active, sort),
  KEY ix_products_category (category, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- comenzi --

-- Fluxul are două trepte, ca în realitate:
--
--   1. CERERE  — ce trimite clientul de pe site. Nu intră în producție.
--   2. COMANDĂ — cererea acceptată de administrator, cu număr, interval și
--                zonă. Abia ea ajunge pe panoul din laborator.
--
-- Separarea contează: laboratorul nu trebuie să vadă ca ferm ceva ce nu a
-- fost verificat ca încăpând în capacitatea zilei.

CREATE TABLE IF NOT EXISTS order_requests (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NULL,
  customer_name VARCHAR(160) NOT NULL,
  phone         VARCHAR(32)  NULL,
  email         VARCHAR(190) NULL,
  day           DATE         NULL,
  slot          VARCHAR(16)  NULL,
  zone          VARCHAR(64)  NULL,
  address       VARCHAR(255) NULL,
  -- Cum pleacă marfa: „congelat" (în lanț frigorific) sau „copt" (gata de
  -- servire). Decide prețul — cele două prețuri ale produsului sunt exact
  -- pentru cele două variante — și îi spune laboratorului ce să pregătească.
  variant       VARCHAR(16)  NOT NULL DEFAULT 'copt',
  notes         TEXT         NULL,
  total         DECIMAL(10,2) NOT NULL DEFAULT 0,
  status        ENUM('nou','acceptata','respinsa') NOT NULL DEFAULT 'nou',
  -- comanda creată la acceptare; păstrează legătura cerere → comandă
  order_id      INT UNSIGNED NULL,
  handled_by    INT UNSIGNED NULL,
  handled_at    DATETIME     NULL,
  reject_reason VARCHAR(255) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_req_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_req_handler  FOREIGN KEY (handled_by)  REFERENCES users(id) ON DELETE SET NULL,
  KEY ix_req_status (status, created_at),
  KEY ix_req_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_request_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  name       VARCHAR(160) NOT NULL,
  -- „congelat" sau „copt", pe FIECARE poziție: coșul de pe site poate conține
  -- același produs în ambele variante, la prețuri diferite.
  variant    VARCHAR(16)  NOT NULL DEFAULT 'copt',
  qty        DECIMAL(10,2) NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_reqitems_request FOREIGN KEY (request_id) REFERENCES order_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_reqitems_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  KEY ix_reqitems_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- numărul comenzii din ziua respectivă (1, 2, 3…), afișat ca „#1042"
  seq           INT UNSIGNED NOT NULL,
  ref           VARCHAR(24)  NULL,
  customer_id   INT UNSIGNED NULL,
  -- păstrate separat: o comandă poate veni de la cineva fără cont
  customer_name VARCHAR(160) NULL,
  phone         VARCHAR(32)  NULL,
  address       VARCHAR(255) NULL,
  zone          VARCHAR(64)  NULL,
  day           DATE         NOT NULL,
  -- intervalul de livrare (06:30, 08:00…), din lista configurabilă în Setări
  slot          VARCHAR(16)  NULL,
  -- cum se onorează: Angro / Livrare / Ridicare
  fulfillment   VARCHAR(32)  NOT NULL DEFAULT 'Livrare',
  -- pe unde a intrat: site, telefon, whatsapp, panou. Diferit de `fulfillment`:
  -- una spune cum a ajuns comanda la noi, cealaltă cum ajunge marfa la client.
  channel       VARCHAR(32)  NOT NULL DEFAULT 'site',
  -- Cum pleacă marfa: „congelat" (în lanț frigorific) sau „copt" (gata de
  -- servire). Decide prețul — cele două prețuri ale produsului sunt exact
  -- pentru cele două variante — și îi spune laboratorului ce să pregătească.
  variant       VARCHAR(16)  NOT NULL DEFAULT 'copt',
  -- Etapa din laborator. Text liber, nu ENUM: lista de etape se editează din
  -- panoul de Setări, iar un ENUM ar cere modificarea schemei la fiecare
  -- schimbare de flux.
  status        VARCHAR(48)  NOT NULL DEFAULT 'În așteptare',
  notes         TEXT         NULL,
  -- Comandă urgentă: apare prima pe panoul din laborator.
  urgent        TINYINT(1)   NOT NULL DEFAULT 0,
  -- Are oră fixă, nu doar interval orientativ.
  timed         TINYINT(1)   NOT NULL DEFAULT 0,
  total         DECIMAL(10,2) NOT NULL DEFAULT 0,
  -- Poziția opririi pe ruta zilei, stabilită din Administrare. NULL = ordinea
  -- implicită (după intervalul promis). Stă aici, nu în browserul care a
  -- stabilit-o: altfel șoferul, care deschide ruta pe telefonul lui, n-ar
  -- vedea niciodată ordinea pusă de administrator.
  route_seq     SMALLINT UNSIGNED NULL,
  -- Marcaj: cantitățile acestei comenzi au fost deja scăzute din depozit.
  -- Trebuie să stea pe server. Ținut în pagina care a făcut scăderea, s-ar fi
  -- pierdut la prima reîncărcare, iar a doua apăsare a butonului ar fi scăzut
  -- încă o dată aceleași produse — stocul ar fi scăzut în tăcere la fiecare
  -- deschidere a paginii Depozit.
  stock_synced  TINYINT(1)   NOT NULL DEFAULT 0,
  request_id    INT UNSIGNED NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_request  FOREIGN KEY (request_id)  REFERENCES order_requests(id) ON DELETE SET NULL,
  -- garantează că două comenzi din aceeași zi nu primesc același număr,
  -- chiar dacă sosesc simultan
  UNIQUE KEY uq_orders_day_seq (day, seq),
  KEY ix_orders_day_status (day, status),
  KEY ix_orders_customer (customer_id, day),
  KEY ix_orders_zone (day, zone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Legătura inversă cerere → comandă. Se adaugă separat fiindcă cele două
-- tabele se referă reciproc, iar `orders` nu există încă la crearea lui
-- `order_requests`.
ALTER TABLE order_requests
  ADD CONSTRAINT fk_req_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS order_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id   INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  -- denumirea la momentul comenzii: dacă produsul se redenumește mai târziu,
  -- comanda veche rămâne corectă
  name       VARCHAR(160) NOT NULL,
  -- „congelat" sau „copt", pe FIECARE poziție. Varianta de pe comandă rămâne,
  -- dar spune doar ce e majoritar („mixt" când sunt amândouă): prețul și ce
  -- pregătește laboratorul se citesc de aici.
  variant    VARCHAR(16)  NOT NULL DEFAULT 'copt',
  qty        DECIMAL(10,2) NOT NULL DEFAULT 1,
  -- cât s-a făcut efectiv din poziție, raportat din laborator. Tura care intră
  -- vede ce a rămas de făcut, în loc să întrebe tura care iese.
  done_qty   DECIMAL(10,2) NOT NULL DEFAULT 0,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  KEY ix_items_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- depozit --

-- Depozitul ține trei feluri de articole: materii prime, produse finite și
-- ambalaje. Sunt separate de tabelul `products` fiindcă făina și cutiile nu
-- se vând; produsele finite se pot lega de catalog prin `product_id`.
--
-- Categoria este text, nu ENUM: se editează din Setări, ca lista de etape.
CREATE TABLE IF NOT EXISTS stock_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(160) NOT NULL,
  category   VARCHAR(64)  NOT NULL,
  -- buc, kg, L…
  unit       VARCHAR(16)  NOT NULL DEFAULT 'buc',
  -- pragul sub care articolul apare ca „stoc scăzut"
  min_qty    DECIMAL(10,2) NOT NULL DEFAULT 0,
  product_id INT UNSIGNED NULL,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort       INT          NOT NULL DEFAULT 0,
  CONSTRAINT fk_items_product2 FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  UNIQUE KEY uq_stock_items_name (name),
  KEY ix_stock_items_cat (category, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stocul NU se ține într-o coloană: se calculează din mișcări, ca să nu poată
-- ajunge niciodată în dezacord cu istoricul.
CREATE TABLE IF NOT EXISTS stock_moves (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id    INT UNSIGNED NULL,
  -- denumirea la momentul mișcării: dacă articolul se redenumește, registrul
  -- vechi rămâne lizibil
  name       VARCHAR(160) NOT NULL,
  -- patru zecimale: consumul de materii prime se scrie tot aici, iar la două
  -- zecimale drojdia de pe o bucată de croissant dispare în rotunjire
  qty        DECIMAL(12,4) NOT NULL,
  -- +1 intrare, -1 ieșire; stocul curent = SUM(qty * direction)
  direction  TINYINT      NOT NULL DEFAULT 1,
  reason     VARCHAR(96)  NULL,
  -- comanda din care vine mișcarea, dacă vine dintr-una. SET NULL, nu CASCADE:
  -- registrul rămâne lizibil și după ce comanda dispare — o ieșire de făină
  -- s-a întâmplat, indiferent ce s-a hotărât despre comandă.
  order_id   INT UNSIGNED NULL,
  note       VARCHAR(255) NULL,
  user_id    INT UNSIGNED NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_moves_item FOREIGN KEY (item_id) REFERENCES stock_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_moves_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_moves_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  KEY ix_moves_item_date (item_id, created_at),
  KEY ix_moves_date (created_at),
  KEY ix_moves_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Din ce e făcut un produs: câte kilograme de făină, câte ouă, cât unt intră
-- într-O SINGURĂ bucată. Rețetele sunt pe un singur nivel, deliberat — o „cutie
-- cadou” care conține croissante trece croissantul ca linie de rețetă și scade
-- direct articolul lui finit. Nicio recursie, rezultat corect.
--
-- `qty` are patru zecimale, nu două: la două, cele 0,005 kg de drojdie dintr-un
-- croissant s-ar rotunji la 0,01 — dublul.
CREATE TABLE IF NOT EXISTS product_recipes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  item_id    INT UNSIGNED NOT NULL,
  -- cantitatea pentru O SINGURĂ bucată de produs
  qty        DECIMAL(12,4) NOT NULL DEFAULT 0,
  CONSTRAINT fk_recipe_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  -- RESTRICT, nu CASCADE: un articol folosit într-o rețetă nu se șterge din
  -- greșeală. Depozitul oricum nu șterge articole, le dezactivează.
  CONSTRAINT fk_recipe_item FOREIGN KEY (item_id) REFERENCES stock_items(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_recipe (product_id, item_id),
  KEY ix_recipe_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ce e promis unei comenzi, dar încă neconsumat.
--
-- Tabel separat, nu un rând cu `direction = 0` în `stock_moves`: soldul e o
-- sumă, nu o coloană, iar un rând-zero ar fi invizibil pentru SUM(qty *
-- direction) și totuși vizibil în toate listările de registru. În plus
-- registrul e append-only, iar rezervarea se modifică.
--
-- Rezervarea DESCHISĂ nu se gestionează, se derivă: `qty - consumed`, numărat
-- doar pentru comenzile al căror status nu e nici cel de încheiere, nici cel de
-- anulare. Eliberarea la „Predată”, redeschiderea dacă iese din „Predată” și
-- eliberarea la anulare sunt toate același filtru pe status.
CREATE TABLE IF NOT EXISTS stock_reservations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED NOT NULL,
  -- 'finit' = produsul gata făcut; 'materie' = o linie din rețeta lui
  kind          VARCHAR(8)    NOT NULL DEFAULT 'finit',
  -- cât s-a promis
  qty           DECIMAL(12,4) NOT NULL DEFAULT 0,
  -- cât din promisiune s-a și consumat (producție raportată)
  consumed      DECIMAL(12,4) NOT NULL DEFAULT 0,
  CONSTRAINT fk_resv_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  -- CASCADE pe poziție: `PATCH /orders` șterge și rescrie order_items, deci
  -- rezervările vechi pleacă odată cu pozițiile vechi, fără cod care să le caute.
  CONSTRAINT fk_resv_oitem FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_resv_stock FOREIGN KEY (item_id) REFERENCES stock_items(id) ON DELETE CASCADE,
  UNIQUE KEY uq_resv (order_item_id, item_id),
  KEY ix_resv_item (item_id),
  KEY ix_resv_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- jurnal --

CREATE TABLE IF NOT EXISTS audit_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  -- păstrat ca text: jurnalul trebuie să rămână lizibil și după ștergerea contului
  user_label VARCHAR(128) NULL,
  action     VARCHAR(64)  NOT NULL,
  entity     VARCHAR(64)  NULL,
  entity_id  VARCHAR(64)  NULL,
  detail     TEXT         NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  KEY ix_log_date (created_at),
  KEY ix_log_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ notificări --

CREATE TABLE IF NOT EXISTS notifications (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel    VARCHAR(32)  NOT NULL,
  recipient  VARCHAR(190) NULL,
  subject    VARCHAR(255) NULL,
  payload    TEXT         NOT NULL,
  status     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts   INT UNSIGNED NOT NULL DEFAULT 0,
  error      TEXT         NULL,
  order_id   INT UNSIGNED NULL,
  sent_at    DATETIME     NULL,
  -- Momentul în care cineva a văzut eșecul în Setări. Fără el, un anunț
  -- nelivrat rămânea scris aici și nu-l afla nimeni, niciodată.
  seen_at    DATETIME     NULL DEFAULT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  KEY ix_notif_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- setări --

-- Configurarea editabilă din panoul de Setări. Valorile marcate is_secret
-- (tokenuri de API) se stochează criptat și nu sunt returnate niciodată
-- în clar de către API — vezi api/lib/crypto.php.
CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(64)  NOT NULL PRIMARY KEY,
  value       TEXT         NULL,
  is_secret   TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by  INT UNSIGNED NULL,
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limitarea încercărilor de autentificare, pe IP (completează blocarea pe cont).
CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45) NOT NULL,
  username   VARCHAR(64) NULL,
  ok         TINYINT(1)  NOT NULL DEFAULT 0,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
