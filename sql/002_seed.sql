-- Golden Pastry — date inițiale
-- GENERAT din catalogul real din „Site Public.dc.html" (22 produse).
--
-- Rulează DUPĂ 001_schema.sql, la instalare.
--
-- Se poate rula din nou fără teamă: totul e INSERT IGNORE — adaugă ce lipsește
-- și lasă neatins ce există. Un rând deja prezent dă doar avertismentul
-- „#1062 Duplicate entry ... for key 'products.PRIMARY'" și „0 rows affected",
-- comportamentul normal al lui INSERT IGNORE. Nu se pierde nimic.
--
-- Înainte, fișierul avea și 22 de UPDATE necondiționate (rândurile 76–229)
-- care rescriau prețurile, denumirile, descrierile ȘI fotografiile produselor
-- 1–22 la fiecare reimport, peste tot ce se schimbase din pagina Produse — iar
-- documentația chiar recomanda reimportul „ca să apară setările noi". Blocul a
-- fost scos: la instalare INSERT-ul pune oricum aceleași date, iar pe o
-- instalare vie n-avea ce căuta.
--
-- Compatibil MySQL 5.7 / 8.x și MariaDB 10.x.

SET NAMES utf8mb4;

-- ------------------------------------------------------------ categorii --
-- Grupele din catalog, în ordinea în care apar pe site. Se editează din
-- pagina Produse; `slug` este cheia pe care o folosesc produsele.

INSERT IGNORE INTO product_categories (slug, name, name_en, note, note_en, sort) VALUES
  ('strudel', 'Ștrudel', 'Strudel', 'Foietaj crocant, trei umpluturi', 'Crisp puff pastry, three fillings', 0),
  ('trigon', 'Trigon', 'Triangles', 'Foietaj, dulce și sărat', 'Puff pastry, sweet and savoury', 10),
  ('croissant', 'Croissant', 'Croissants', 'Straturi fine, umpluturi cu cremă', 'Fine layers, cream fillings', 20),
  ('roll', 'Roll', 'Rolls', 'Aluat dospit, gama sărată', 'Leavened dough, the savoury range', 30),
  ('covrig', 'Covrigi', 'Pretzels', 'Aluat dospit, la bucată', 'Leavened dough, sold by the piece', 40),
  ('corn', 'Corn', 'Horns', 'Aluat fraged, gama dulce', 'Tender dough, the sweet range', 50),
  ('special', 'Specialități', 'Specialities', 'Rețete de casă, preț special', 'House recipes, special price', 60);

-- --------------------------------------------------------------- produse --
-- id-ul corespunde pozei: produsul 9 → products/prod-9.jpg

INSERT IGNORE INTO products
  (id, slug, name, name_en, description, description_en, photo, grams, price_frozen, price_baked, category, is_new, sort)
VALUES
  (1, 'strudel-cu-mere', 'Ștrudel cu mere', 'Apple strudel', 'Foietaj crocant și auriu, umplut cu mere fragede, scorțișoară și zahăr brun.', 'Crisp golden puff pastry filled with tender apples, cinnamon and brown sugar.', 'products/prod-1.jpg', 140, 2.85, 3.49, 'strudel', 0, 0),
  (2, 'strudel-cu-branza-dulce', 'Ștrudel cu brânză dulce', 'Sweet cheese strudel', 'Foietaj fin și crocant, umplut cu brânză proaspătă, cremoasă și ușor dulce.', 'Fine, crisp puff pastry filled with fresh, creamy, lightly sweetened cheese.', 'products/prod-2.jpg', 170, 2.85, 3.49, 'strudel', 0, 10),
  (3, 'strudel-cu-dovleac', 'Ștrudel cu dovleac', 'Pumpkin strudel', 'Foietaj auriu și crocant, umplut cu dovleac catifelat și condimente calde aromate.', 'Golden, crisp puff pastry filled with velvety pumpkin and warm aromatic spices.', 'products/prod-3.jpg', 150, 2.85, 3.49, 'strudel', 0, 20),
  (4, 'trigon-cu-pizza', 'Trigon cu pizza', 'Pizza triangle', 'Foietaj crocant și auriu, umplut cu șuncă fragedă, sos de roșii, cașcaval topit și măsline.', 'Crisp golden puff pastry with tender ham, tomato sauce, melted cheese and olives.', 'products/prod-4.jpg', 140, 3.79, 4.69, 'trigon', 0, 30),
  (5, 'trigon-cu-branza', 'Trigon cu brânză', 'Cheese triangle', 'Foietaj delicat și crocant, umplut cu brânză proaspătă, cremoasă și ușor sărată.', 'Delicate, crisp puff pastry filled with fresh, creamy, lightly salted cheese.', 'products/prod-5.jpg', 160, 2.8, 3.49, 'trigon', 0, 40),
  (6, 'trigon-cu-gem-de-caise', 'Trigon cu gem de caise', 'Apricot jam triangle', 'Foietaj fin și auriu, umplut cu gem de caise dulce-acrișor și parfumat de vară.', 'Fine golden puff pastry filled with sweet-sharp apricot jam with a scent of summer.', 'products/prod-6.jpg', 160, 2.8, 3.49, 'trigon', 0, 50),
  (7, 'croissant-cu-ciocolata', 'Croissant cu ciocolată', 'Chocolate croissant', 'Foietaj crocant cu straturi fine, umplut cu cremă fină de ciocolată.', 'Crisp, finely layered puff pastry filled with smooth chocolate cream.', 'products/prod-7.jpg', 140, 3.2, 3.99, 'croissant', 0, 60),
  (8, 'croissant-cu-vanilie-si-zmeura', 'Croissant cu vanilie și zmeură', 'Vanilla & raspberry croissant', 'Foietaj delicat și crocant, umplut generos cu cremă de vanilie și zmeură.', 'Delicate, crisp pastry generously filled with vanilla and raspberry cream.', 'products/prod-8.jpg', 160, 4.1, 4.99, 'croissant', 0, 70),
  (9, 'croissant-cu-fistic', 'Croissant cu fistic', 'Pistachio croissant', 'Foietaj aerat și auriu, umplut cu cremă bogată și parfumată de fistic.', 'Airy golden pastry filled with rich, fragrant pistachio cream.', 'products/prod-9.jpg', 150, 5.8, 6.99, 'croissant', 0, 80),
  (10, 'rolldog', 'Rolldog', 'Rolldog', 'Aluat dospit crocant la exterior, învelit în jurul unui crenvurști suculent și apetisant.', 'Leavened dough, crisp on the outside, wrapped around a juicy frankfurter.', 'products/prod-10.jpg', 100, 2.2, 2.69, 'roll', 0, 90),
  (11, 'roll-cu-cabanos', 'Roll cu cabanos', 'Cabanos sausage roll', 'Aluat dospit moale și auriu, umplut cu cârnat fraged și bine condimentat.', 'Soft golden leavened dough filled with tender, well-seasoned sausage.', 'products/prod-11.jpg', 140, 2.9, 3.49, 'roll', 0, 100),
  (12, 'roll-cu-sunca-si-cascaval', 'Roll cu șuncă și cașcaval', 'Ham & cheese roll', 'Aluat dospit pufos, umplut generos cu șuncă și cașcaval topit.', 'Fluffy leavened dough, generously filled with ham and melted cheese.', 'products/prod-12.jpg', 140, 2.5, 3.09, 'roll', 0, 110),
  (13, 'covrig-cu-cascaval', 'Covrig cu cașcaval', 'Cheese pretzel', 'Aluat dospit moale și cald, umplut cu cașcaval afumat bine topit.', 'Soft, warm leavened dough filled with well-melted smoked cheese.', 'products/prod-13.jpg', 160, 2.79, 3.39, 'covrig', 0, 120),
  (14, 'covrig-cu-susan-mac-seminte', 'Covrig cu susan / mac / semințe', 'Sesame, poppy or seed pretzel', 'Aluat dospit crocant, acoperit cu susan, mac sau semințe.', 'Crisp leavened dough topped with sesame, poppy seeds or mixed seeds.', 'products/prod-14.jpg', 100, 1.2, 1.49, 'covrig', 0, 130),
  (15, 'covrig-cu-mar', 'Covrig cu măr', 'Apple pretzel', 'Aluat dospit pufos și aromat, umplut cu felii de mere fragede.', 'Fluffy, aromatic leavened dough filled with tender apple slices.', 'products/prod-15.jpg', 140, 2.49, 2.99, 'covrig', 0, 140),
  (16, 'corn-cu-ciocolata', 'Corn cu ciocolată', 'Chocolate horn', 'Aluat fraged și ușor dulce, umplut cu ciocolată bogată și intensă.', 'Tender, lightly sweetened dough filled with rich, intense chocolate.', 'products/prod-16.jpg', 150, 2.75, 3.39, 'corn', 0, 150),
  (17, 'corn-cu-vanilie', 'Corn cu vanilie', 'Vanilla horn', 'Aluat fraged și ușor dulce, umplut cu cremă fină și parfumată de vanilie.', 'Tender, lightly sweetened dough filled with smooth, fragrant vanilla cream.', 'products/prod-17.jpg', 130, 3.65, 4.49, 'corn', 0, 160),
  (18, 'corn-cu-fistic', 'Corn cu fistic', 'Pistachio horn', 'Aluat fraged și ușor dulce, umplut cu cremă bogată de fistic.', 'Tender, lightly sweetened dough filled with rich pistachio cream.', 'products/prod-18.jpg', 140, 5.35, 6.49, 'corn', 0, 170),
  (19, 'trigon-cu-vanilie-si-fructe', 'Trigon cu vanilie și fructe', 'Vanilla & fruit triangle', 'Foietaj crocant și auriu, umplut cu cremă fină de vanilie și fructe proaspete, colorat și răcoritor.', 'Crisp golden puff pastry with smooth vanilla cream and fresh fruit — colourful and refreshing.', 'products/prod-19.jpg', 160, 3.5, 4.29, 'trigon', 1, 180),
  (20, 'poale-n-brau', 'Poale-n brâu', 'Poale-n brâu', 'Aluat dospit moale și pufos, umplut cu brânză dulce proaspătă, pudrat delicat cu zahăr.', 'Soft, fluffy leavened dough filled with fresh sweet cheese, delicately dusted with sugar.', 'products/prod-20.jpg', 150, 2.99, 3.69, 'special', 1, 190),
  (21, 'roll-cu-pizza', 'Roll cu pizza', 'Pizza roll', 'Aluat dospit aromat, rulat cu sos de roșii, mozzarella topită și condimente savuroase.', 'Aromatic leavened dough rolled with tomato sauce, melted mozzarella and savoury spices.', 'products/prod-21.jpg', 160, 2.7, 3.29, 'roll', 1, 200),
  (22, 'merdenea', 'Merdenea', 'Merdenea', 'Foietaj stratificat, crocant și auriu, umplut cu brânză sărată și cremoasă.', 'Layered, crisp golden puff pastry filled with creamy salted cheese.', 'products/prod-22.jpg', 170, 2.99, 3.69, 'special', 1, 210);

-- ------------------------------------------------------- cont de sistem --
-- Tableta din laborator e folosită de mai mulți oameni, deci nu are cont
-- individual: se deschide cu codul comun, verificat de server, iar sesiunea
-- rezultată rulează pe contul acesta.
--
-- Fără el, POST /auth/panel răspunde 503 și tabletele nu pot fi deschise
-- deloc — adică laboratorul rămâne pe dinafară din prima zi.
--
-- Parola este un hash imposibil de obținut dintr-o parolă reală: în contul
-- acesta NU se intră cu utilizator și parolă, ci exclusiv cu codul din Setări.
-- is_system = 1 îl protejează de ștergere și redenumire din pagina Conturi.

INSERT IGNORE INTO users (username, password_hash, name, role, active, is_system) VALUES
  ('panou', '$2y$10$sistemsistemsistemsistemsistemsistemsistemsistemsiste',
   'Panou comun', 'angajat', 1, 1);

-- ---------------------------------------------------------------- setări --
-- Valorile goale se completează din panoul de Setări (lab.goldenpastry.ro/setari).
-- is_secret = 1 → stocat criptat, nereturnat niciodată în clar de API.
--
-- INSERT IGNORE, deliberat: o rulare ulterioară NU trebuie să șteargă tokenul
-- de WhatsApp sau adresa de notificare pe care le-ai configurat deja.

INSERT IGNORE INTO settings (setting_key, value, is_secret) VALUES
  ('company_name', 'Golden Pastry', 0),
  ('company_phone', '+40769493163', 0),
  ('company_email', 'contact@goldenpastry.ro', 0),
  -- De aici pleacă mașina. Coordonatele sunt cele ale depozitului real
  -- (incinta ICTCM, Șos. Olteniței 103), luate de pe harta de pe site.
  -- Prototipul avea altceva scris în cod — „Str. Franceză 12", în centru,
  -- la vreo cinci kilometri distanță — deci fiecare kilometru estimat pe ruta
  -- șoferului și fiecare optimizare porneau dintr-un loc greșit.
  ('hq_label', 'Depozit · Șos. Olteniței 103', 0),
  ('hq_lat', '44.39235', 0),
  ('hq_lng', '26.12385', 0),
  ('notify_email_enabled', '1', 0),
  ('notify_email_to', '', 0),
  ('notify_email_from', 'noreply@goldenpastry.ro', 0),
  ('notify_whatsapp_enabled', '0', 0),
  ('notify_whatsapp_provider', 'meta', 0),
  ('notify_whatsapp_to', '', 0),
  ('notify_whatsapp_phone_id', '', 0),
  ('notify_whatsapp_from', '', 0),
  ('notify_whatsapp_token', '', 1),
  ('notify_whatsapp_template', 'comanda_noua', 0),
  ('notify_whatsapp_status', 'neconfigurat', 0),
  ('notify_whatsapp_last_error', '', 0),
  ('notify_telegram_enabled', '0', 0),
  ('notify_telegram_token', '', 1),
  ('notify_telegram_chat_id', '', 0),
  ('stock_min_default', '10', 0),
  ('panel_code', '', 1),
  ('panel_enabled', '1', 0),
  ('order_statuses', '["În așteptare","La dospit","În cuptor","La ambalare","Predată","Anulată"]', 0),
  -- Care etapă din listă înseamnă „gata” și care înseamnă „anulată”.
  -- Sunt reținute separat fiindcă lista este editabilă: dacă redenumești
  -- „Predată” în „Livrată”, aplicația trebuie să știe în continuare care etapă
  -- încheie comanda — altfel șoferul, depozitul și clientul nu se mai potrivesc.
  ('order_status_done', 'Predată', 0),
  ('order_status_cancelled', 'Anulată', 0),
  ('delivery_slots', '["06:30","08:00","09:00","10:00","12:30","14:00","16:00"]', 0),
  ('delivery_zones', '["Sector 1","Sector 2","Sector 3","Sector 4","Sector 5","Sector 6","Ilfov"]', 0),
  -- Punctul folosit la estimarea distanțelor pentru fiecare zonă. Zonele erau
  -- editabile din Setări, dar coordonatele stăteau scrise în cod, în trei
  -- pagini: o zonă adăugată din panou primea în tăcere coordonatele Centrului,
  -- iar ruta ieșea greșită fără niciun semn.
  ('delivery_zone_coords', '{"Sector 1":{"lat":44.4795,"lng":26.0563},"Sector 2":{"lat":44.46,"lng":26.13},"Sector 3":{"lat":44.418,"lng":26.15},"Sector 4":{"lat":44.385,"lng":26.115},"Sector 5":{"lat":44.4,"lng":26.05},"Sector 6":{"lat":44.43,"lng":26.01},"Ilfov":{"lat":44.5,"lng":26.1}}', 0),
  ('fulfillment_types', '["Angro","Livrare","Ridicare"]', 0),
  -- Modurile de onorare în care marfa NU pleacă cu mașina noastră; restul
  -- devin opriri pe ruta șoferului. Ruta le alegea după textul „Livrare",
  -- scris în pagină, deși lista de mai sus se editează din Setări: un mod
  -- adăugat din panou sau „Livrare" redenumită lăsau ruta goală, fără niciun
  -- semn, iar livrările zilei nu se făceau.
  ('fulfillment_pickup', '["Angro","Ridicare"]', 0),
  ('stock_categories', '["Materii prime","Produse finite","Ambalaje"]', 0),
  ('stock_units', '["buc","kg","L"]', 0);

-- --------------------------------------------------------------- depozit --
-- Materiile prime și ambalajele vin din prototip — sunt plauzibile pentru o
-- patiserie și pot fi editate din pagina Depozit. Produsele finite sunt
-- generate din catalogul REAL, legate prin product_id: denumirile demo din
-- prototip („Croissant cu unt", „Kouign-amann") nu corespundeau produselor
-- vândute efectiv.

INSERT IGNORE INTO stock_items (name, category, unit, min_qty, product_id, sort) VALUES
  ('Făină 650', 'Materii prime', 'kg', 80, NULL, 0),
  ('Făină integrală', 'Materii prime', 'kg', 30, NULL, 10),
  ('Unt 82%', 'Materii prime', 'kg', 40, NULL, 20),
  ('Ciocolată neagră 55%', 'Materii prime', 'kg', 15, NULL, 30),
  ('Zahăr alb', 'Materii prime', 'kg', 40, NULL, 40),
  ('Drojdie proaspătă', 'Materii prime', 'kg', 4, NULL, 50),
  ('Ouă', 'Materii prime', 'buc', 300, NULL, 60),
  ('Lapte integral', 'Materii prime', 'L', 30, NULL, 70),
  ('Smântână 32%', 'Materii prime', 'L', 20, NULL, 80),
  ('Migdale măcinate', 'Materii prime', 'kg', 8, NULL, 90),
  ('Căpșuni proaspete', 'Materii prime', 'kg', 5, NULL, 100),
  ('Vanilie Bourbon', 'Materii prime', 'buc', 20, NULL, 110),
  ('Cutie tort 6p', 'Ambalaje', 'buc', 50, NULL, 120),
  ('Cutie foietaje 12p', 'Ambalaje', 'buc', 60, NULL, 130),
  ('Pungă hârtie mică', 'Ambalaje', 'buc', 200, NULL, 140),
  ('Etichete Golden Pastry', 'Ambalaje', 'buc', 300, NULL, 150),
  ('Ștrudel cu mere', 'Produse finite', 'buc', 0, 1, 1000),
  ('Ștrudel cu brânză dulce', 'Produse finite', 'buc', 0, 2, 1010),
  ('Ștrudel cu dovleac', 'Produse finite', 'buc', 0, 3, 1020),
  ('Trigon cu pizza', 'Produse finite', 'buc', 0, 4, 1030),
  ('Trigon cu brânză', 'Produse finite', 'buc', 0, 5, 1040),
  ('Trigon cu gem de caise', 'Produse finite', 'buc', 0, 6, 1050),
  ('Croissant cu ciocolată', 'Produse finite', 'buc', 0, 7, 1060),
  ('Croissant cu vanilie și zmeură', 'Produse finite', 'buc', 0, 8, 1070),
  ('Croissant cu fistic', 'Produse finite', 'buc', 0, 9, 1080),
  ('Rolldog', 'Produse finite', 'buc', 0, 10, 1090),
  ('Roll cu cabanos', 'Produse finite', 'buc', 0, 11, 1100),
  ('Roll cu șuncă și cașcaval', 'Produse finite', 'buc', 0, 12, 1110),
  ('Covrig cu cașcaval', 'Produse finite', 'buc', 0, 13, 1120),
  ('Covrig cu susan / mac / semințe', 'Produse finite', 'buc', 0, 14, 1130),
  ('Covrig cu măr', 'Produse finite', 'buc', 0, 15, 1140),
  ('Corn cu ciocolată', 'Produse finite', 'buc', 0, 16, 1150),
  ('Corn cu vanilie', 'Produse finite', 'buc', 0, 17, 1160),
  ('Corn cu fistic', 'Produse finite', 'buc', 0, 18, 1170),
  ('Trigon cu vanilie și fructe', 'Produse finite', 'buc', 0, 19, 1180),
  ('Poale-n brâu', 'Produse finite', 'buc', 0, 20, 1190),
  ('Roll cu pizza', 'Produse finite', 'buc', 0, 21, 1200),
  ('Merdenea', 'Produse finite', 'buc', 0, 22, 1210);
