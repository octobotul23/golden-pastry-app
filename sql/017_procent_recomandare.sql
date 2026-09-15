-- Migrarea 017 — procentul de recomandare pornește de la 5%
--
-- Se rulează o singură dată, după 016. Poate fi rulată de câte ori vrei fără
-- pagubă: scrie numai peste valoarea pusă de noi la instalare, niciodată peste
-- una hotărâtă de un om. Nu atinge niciun comision deja socotit.
--
-- Rulare locală (Laragon):  mysql -u root gp_dev < sql/017_procent_recomandare.sql
-- Rulare pe VPS:            bash actualizeaza-vps.sh (le rulează pe toate, în ordine)
--
-- Ce aduce, și DE CE
-- ==================
--
-- PROBLEMA DE REZOLVAT: RECOMANDĂRILE MERGEAU, DAR NU ADUCEAU NIMIC. Migrarea
-- 008 a semănat `referral_rate_default` cu valoarea „0", și avea un motiv scris
-- negru pe alb acolo: „o valoare inventată de noi ar face ca prima comandă de
-- după actualizare să nască o datorie de bani pe care nimeni n-a hotărât-o".
-- Motivul era bun. Urmarea nu.
--
-- Pe serverul patronului, trei comenzi au venit prin cod de recomandare. Toate
-- trei s-au legat corect, s-au numărat corect, și au ieșit pe ecran cu 0,00 RON
-- — fiindcă procentul era zero și nimeni nu i-a spus vreodată asta. Panoul
-- „Recomandări" arăta un sistem care merge perfect și nu face nimic.
--
-- Mai rău: procentul se ÎNGHEAȚĂ la nașterea rândului de comision
-- (`api/lib/referral.php`, „PROCENTUL SE ÎNGHEAȚĂ LA NAȘTEREA RÂNDULUI"), tocmai
-- ca patronul să nu poată lua înapoi, urcând sau coborând procentul, un câștig
-- deja făcut. Înghețul e bun și rămâne. Dar el a înghețat și zerourile: cele
-- trei comenzi ar fi rămas 0,00 RON pe veci, oricât s-ar fi pus în Setări după
-- aceea.
--
-- HOTĂRÂREA: 5%, luată de patron pe 14 septembrie 2026. Nu mai e „o valoare
-- inventată de noi" — e valoarea casei, și de-aceea se poate semăna liniștit.
--
-- CE NU ATINGE, DINADINS
-- ======================
--
-- `updated_by` e martorul: rândurile din `settings` semănate la instalare îl au
-- NULL, iar orice scriere din pagina Setări îl umple cu id-ul omului
-- (`api/lib/settings.php`, UPDATE-ul care pune `updated_by = ?`). Deci putem
-- deosebi „n-a hotărât nimeni nimic" de „cineva a hotărât zero" — și un zero
-- hotărât de om rămâne zero. Casa nu schimbă părerea patronului în somn.
--
-- Comisioanele deja socotite NU se ating aici. Rândurile născute la procentul
-- zero se îndreaptă din panoul „Recomandări", la cererea omului, fiindcă acolo
-- se vede pe cine și cu cât — nu dintr-o migrare care trece tăcută peste bani.

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('referral_rate_default', '5', 0);

UPDATE settings
   SET value = '5'
 WHERE setting_key = 'referral_rate_default'
   AND updated_by IS NULL
   AND (value IS NULL OR TRIM(value) = '' OR TRIM(value) = '0' OR TRIM(value) = '0.00');
