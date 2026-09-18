-- Migrarea 019 — jetonul care leagă cererea de contul făcut imediat după
--
-- Se rulează o singură dată, după 018. Poate fi rulată de câte ori vrei fără
-- pagubă: tabelul se face numai dacă lipsește, iar `CREATE TABLE IF NOT EXISTS`
-- sare peste tot ce e înăuntru, chei străine cu tot. (De-aia nu se poate
-- re-rula `001_schema.sql`: acolo o cheie străină ajunge să se numească a doua
-- oară la fel și MySQL se oprește cu 1826.)
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/019_jeton_cerere.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- PROBLEMA DE REZOLVAT. Vizitatorul apasă „Trimite comanda și fă-ți cont".
-- Cererea pleacă pe loc (`POST /requests`) și e a nimănui: `customer_id` e
-- NULL, fiindcă omul n-avea încă niciun cont. Peste un minut își face contul.
-- Contul e proaspăt, deci `self_registered = 1` și `vouched_at` NULL — iar
-- singura legătură pe care o face serverul azi între o comandă și un cont e
-- `client_by_name()`, care cere tocmai `self_registered = 0 OR vouched_at IS
-- NOT NULL`. Așa că omul tocmai a scris o comandă ȘI un cont, iar „Comanda
-- mea" e goală. Ca să-și vadă comanda trebuie să-l recunoască cineva din casă,
-- cu mâna.
--
-- DE CE NU SE SLĂBEȘTE PAZA DE LA NUME. Sunt două uși care răspund la două
-- întrebări deosebite. `client_by_name()` răspunde la „un NUME tastat dă
-- dreptul la comenzile scrise pe numele ăla?" — și răspunde nu, fiindcă un
-- străin care se înscria singur cu numele unui client angro moștenea
-- comenzile, adresa, telefonul și prețurile negociate ale aceluia. Jetonul de
-- aici răspunde la altceva: „omul ăsta e chiar cel care a trimis cererea
-- asta?" — iar dovada nu e un nume, e un secret pe care i l-a dat serverul, o
-- singură dată, în răspunsul la chiar trimiterea aceea. Dă o cerere anume,
-- nimic retroactiv și nimic viitor. Cele două uși rămân despărțite.
--
-- DE CE TABEL, ȘI NU O COLOANĂ PE `order_requests`. Rândul cererii e plimbat
-- întreg prin acceptare și prin anunțuri (`SELECT *`, apoi e-mail, WhatsApp,
-- Telegram). Un secret viu pe rândul acela ar trebui șters cu mâna din fiecare
-- drum, iar prima uitare l-ar trimite în lume. Tiparul e al casei: tabel
-- deoparte, ca `password_resets` (001) și ca `founder_invites` (009), care l-a
-- copiat dinadins pe primul.
--
-- CE SE PĂSTREAZĂ ȘI CE NU. Numai `token_hash` — sha256 peste jetonul în clar.
-- Jetonul întreg există o singură dată, în răspunsul HTTP către browserul care
-- tocmai a trimis cererea; nu pleacă pe e-mail și nu se scrie nicăieri. Dacă
-- baza e citită de cineva, jetoanele din ea nu deschid nimic.

SET NAMES utf8mb4;

-- `request_id` e UNIQUE: o cerere are cel mult un jeton. Retrimiterea ar
-- însemna stingerea celui vechi întâi, cum face „am uitat parola" cu
-- `UPDATE password_resets SET used_at = ... WHERE used_at IS NULL`.
--
-- `ON DELETE CASCADE` pe cerere: cererea ștearsă își ia jetonul cu ea. Un
-- jeton care supraviețuiește cererii n-ar mai avea ce lega.
--
-- `used_by` e pentru citit peste ani — cine a ridicat cererea —, cu
-- `ON DELETE SET NULL` ca `founder_invites.requested_by`: ștergerea unui cont
-- nu trebuie să șteargă urma că cineva a folosit jetonul.
--
-- Fără index pe `expires_at`: nu se caută niciodată după el, se caută după
-- `token_hash` și se citește data de pe rândul găsit.
CREATE TABLE IF NOT EXISTS request_claims (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64)     NOT NULL,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME     NULL,
  used_by    INT UNSIGNED NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_claim_request FOREIGN KEY (request_id) REFERENCES order_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_claim_user    FOREIGN KEY (used_by)    REFERENCES users(id)          ON DELETE SET NULL,
  UNIQUE KEY uq_claim_request (request_id),
  KEY ix_claim_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
