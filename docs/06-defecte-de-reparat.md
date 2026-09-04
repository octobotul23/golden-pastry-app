# Defecte găsite, de reparat

Inspecție făcută înainte de publicare, pe tot codul: 4.700 de linii de PHP,
11.300 de linii de pagini, pachetul de publicare și baza de date.

**170 de defecte confirmate** — 5 critice, 58 grave, 77 medii, 30 mici.
**170 reparate**, 0 rămase.

Lista s-a strâns în patru valuri. Inspecția dinaintea publicării a dat 102 fișe:
din 123 de descoperiri brute au rămas 97 după eliminarea dublurilor, fiecare
trecută prin doi verificatori care au încercat s-o demonteze. Alte cinci au
ieșit la iveală chiar în timpul reparațiilor. Ultimele 51 vin
dintr-o a doua vânătoare, pornită după ce suita trecea integral: șase agenți au
citit codul pe zone separate, fără să știe ce caută ceilalți, iar fiecare
susținere a fost dată unui sceptic care avea sarcina s-o dărâme — șase din
douăzeci și trei au căzut acolo. Tot atunci s-au adăugat cele găsite deschizând
fiecare pagină în browser, cu consola și rețeaua la vedere. Cele marcate
**[reprodus]** au fost declanșate
cu mâna pe aplicația care rulează, nu doar citite din cod.

Ultimele zece vin din al patrulea val, cel mai scump dintre toate: folosirea
aplicației pe viu. Nu s-au găsit citind cod, ci lucrând cu ea — bifa care nu se
debifa, degetul care nu derula pagina, cardul verde pentru o comandă care nu
era minimă, „curier fx2ep" din registru. Trei dintre ele stăteau în locuri prin
care inspecția trecuse deja.

Aceeași listă, cu filtre și căutare:
<https://claude.ai/code/artifact/64a4ebba-42c9-4ed2-be1c-abba79f60d72>

| Gravitate | Reparate | Rămase | Total |
|---|---|---|---|
| Critice | 5 | 0 | 5 |
| Grave | 58 | 0 | 58 |
| Medii | 77 | 0 | 77 |
| Mici | 30 | 0 | 30 |

---

## Ce e reparat — 170

Fiecare cu proba lui de regresie: proba a fost verificată stricând
reparația la loc și văzând-o picând.

- [x] **„Salvează modificările" nu salvează clientul, adresa, referința și pozițiile comenzii**
  `Administrare Comenzi.dc.html:1158` · *critic*
  Formularul trimite acum toată comanda — client, telefon, adresă, referință, prioritate și pozițiile cu varianta fiecăreia — iar route_orders_update() le acceptă și rescrie pozițiile în aceeași tranzacție, cu totalul recalculat din catalog. Ziua nu se mai reconstruiește ghicind luna: formularul poartă data ISO întreagă. Probe: .claude/test-editare.mjs, secțiunea „PATCH /orders/{id}: se salvează toată comanda" (verificată stricând reparația la loc).

- [x] **POST /orders nu cere autentificare: oricine poate băga comenzi direct în laborator, ocolind treapta de cerere**
  `api/routes/orders.php:84` · *critic*
  Ruta se deschide acum cu `require_staff()`. Un vizitator anonim primește 401, un client autentificat 403; comenzile directe rămân doar pentru panou. Probe: „comandă direct în laborator, fără cont → 401" și „nici clientul autentificat nu bagă comenzi direct → 403".

- [x] **Două apăsări simultane pe „Scade din stoc” scad aceleași comenzi de două ori**
  `api/routes/stock.php:246` · *critic*
  Lista comenzilor de scăzut se citește acum în tranzacție, cu FOR UPDATE, iar marcajul cere „stock_synced = 0”: a doua apăsare așteaptă și nu mai găsește nimic. Butonul din Depozit are și el o pază: nu pornește a doua cerere cât timp prima e în zbor. Probă: .claude/test-stoc-concurent.php (două conexiuni împletite manual, fiindcă php -S nu poate răspunde la două cereri odată pe Windows).

- [x] **Codul panoului este „lab123" — o valoare fixă, pusă de instalator și scrisă în clar într-o pagină publică**
  `api/setup.php:73` · *critic*
  Instalatorul generează acum un cod aleator de 8 caractere (random_panel_code() din api/lib/crypto.php, alfabet fără i/l/1/o/0) și îl arată o singură dată, pe pagina de final. Nu se mai păstrează nicăieri în clar. Literalul a dispărut și din Conturi.dc.html și Setari.dc.html, adică din paginile publicate ca fișiere statice pe lab.goldenpastry.ro. Probe: .claude/test-instalare.php (instalare pe o bază goală: codul afișat se decriptează exact din baza de date, a doua instalare primește alt cod), .claude/test-php.php (generatorul: alfabet, lungime, 200 de coduri distincte) și .claude/check-secrets.mjs (nu se mai poate întoarce un cod fix în instalator sau în pagini).

- [x] **Coșul cu produse din ambele variante se trimite cu o singură variantă — clientul plătește altă sumă decât cea afișată și primește altă marfă**
  `Site Public.dc.html:1767` · *critic*
  Cererea nu se mai construiește din `picks` cu `productId` direct: rândul „pachet de test" — care n-are `productId` — se desface în cele 22 de poziții reale, câte 3 bucăți din fiecare, iar reducerea se scrie în notă („PACHET DE TEST −20%: … aplică reducerea la confirmare"). Un coș care rămâne fără nicio poziție nu mai pleacă spre server. Verificat în browser: pachet + 10 croasante → 23 de poziții trimise, cererea 257 înregistrată cu 247,05 lei, exact cât scria pe ecran. Probe în `.claude/check-site-public.mjs`.

- [x] **.htaccess-ul generat blochează chiar fișierul din care se încarcă poza principală a paginii de start**
  `.claude/build-deploy.mjs:182` · *grav*
  Excepție <Files ".image-slots.state.json"> Require all granted, pusă DUPĂ cele două interdicții (în Apache ultima secțiune care se potrivește câștigă). Fără ea, poza mare din antetul paginii de start și încă două imagini fără src în marcaj primeau 403, iar fetch-ul din image-slot.js înghițea eroarea în tăcere. Probă: check-deploy „.htaccess lasă să treacă fișierul din care se încarcă poza din antet”.

- [x] **Ziua cerută de client își pierde luna la acceptare și devine, în tăcere, o zi din săptămâna curentă**
  `Administrare Comenzi.dc.html:1121` · *grav*
  Formularul poartă data ISO întreagă, nu ziua din lună: dayToIso — care alegea luna cea mai apropiată de azi — a dispărut cu totul. Probă: .claude/test-editare.mjs, „ziua s-a salvat" pe PATCH și mutarea între zile.

- [x] **Pozițiile, clientul și adresa modificate în formularul de acceptare se pierd fără urmă**
  `Administrare Comenzi.dc.html:1168` · *grav*
  route_requests_accept() citește acum customerName, phone, address, notes, urgent, timed și items din corpul cererii, nu doar ziua și intervalul: corecturile făcute în formularul de acceptare ajung în comandă. Prețurile nu se mai recalculează din catalog: reparația de retarifare le-a schimbat regula, iar acceptarea onorează prețul pe care l-a văzut clientul când a trimis cererea — numai cantitatea adăugată de administrator ia prețul de azi (vezi fișa despre retarifarea la acceptare). Probă: .claude/test-editare.mjs, secțiunea „acceptarea unei cereri păstrează corecturile".

- [x] **Filtrul de zi, statisticile și ruta compară ziua din lună, nu data întreagă**
  `Administrare Comenzi.dc.html:1104` · *grav*
  Filtrul de zi, statisticile și ruta compară acum data întreagă (o.date === S.fDay, o.date === TODAY, o.date === day), nu ziua din lună. Probă: verificat în browser pe registrul local, cu comenzi din luni diferite.

- [x] **Ziua cerută de client e înlocuită în tăcere cu ziua de azi la acceptarea cererii**
  `Administrare Comenzi.dc.html:1120` · *grav*
  openFromReq păstrează ziua cerută de client așa cum a scris-o (day: r.date), chiar dacă e dincolo de fereastra de șapte zile; selectorul de zi o adaugă în listă ca să rămână vizibilă. Probă: .claude/test-editare.mjs verifică ziua trimisă la acceptare.

- [x] **La comanda nouă, pozițiile care nu se potrivesc exact cu catalogul dispar sau se transformă în alt produs**
  `Administrare Comenzi.dc.html:1181` · *grav*
  Aceeași reparație ca la potrivirea produselor: nume exact, fără diacritice și fără spații în plus; o linie care nu se regăsește în catalog oprește salvarea și e numită în mesaj, în loc să dispară sau să devină alt produs. Probă: .claude/test-editare.mjs, secțiunea „o poziție greșită oprește salvarea, nu dispare".

- [x] **Ordinea opririlor se salvează pe data greșită și șterge ordinea altei zile**
  `Administrare Comenzi.dc.html:1020` · *grav*
  Ordinea opririlor se salvează pe ziua ISO primită (gp.setRouteOrder(S.rDay, [])), nu pe una recompusă; ordinea altei zile nu se mai șterge. Probă: .claude/test-settings.mjs, secțiunea de rută.

- [x] **Numele produsului scris în panou se potrivește prin „conține", deci se comandă alt produs, la alt preț — sau linia dispare în tăcere**
  `Administrare Comenzi.dc.html:793` · *grav*
  Potrivirea prin „conține" a fost înlocuită cu una exactă, insensibilă la diacritice și la spații (normalize NFD + eliminarea semnelor). Ce nu se potrivește nu mai dispare și nu mai devine alt produs: salvarea se oprește și numește liniile greșite. Probă: .claude/test-editare.mjs, secțiunea „o poziție greșită oprește salvarea, nu dispare"; potrivirea fără diacritice verificată în browser („strudel cu mere" → produsul 1).

- [x] **Lista „Moduri de onorare” din Setări e editabilă, dar panoul de comenzi are cele trei valori scrise în cod — orice modificare face imposibilă salvarea comenzilor**
  `Administrare Comenzi.dc.html:858` · *grav*
  Modurile de onorare vin din Setări prin gp.fulfillments() (CHANS_OF), în formular, în filtre și în meniu; nu mai există cele trei valori scrise în cod.

- [x] **Comanda luată la telefon se facturează întotdeauna la prețul „copt”**
  `Administrare Comenzi.dc.html:1176` · *grav*
  Fiecare poziție are propria variantă (copt/congelat) în formular, iar serverul o tarifează cu prețul ei; comanda primește „mixt" când sunt amândouă. Probă: .claude/test-editare.mjs, „varianta fiecărei poziții s-a salvat" și „totalul s-a recalculat din catalog"; verificat și în browser: 5 congelate + 3 coapte = 28,32 RON.

- [x] **Dublu-clic pe „Adaugă comanda” creează două comenzi: butonul rămâne aprins cât timp cererea e pe drum**
  `Administrare Comenzi.dc.html:728` · *grav*
  Butonul de salvare se stinge cât timp cererea e pe drum și scrie „Se salvează…”, iar save() se oprește din prima linie dacă o salvare e deja pornită. Semaforul stă pe instanță (this.__saving), nu în stare: setState nu schimbă starea pe loc, deci trei clicuri în aceeași sutime de secundă citesc toate aceeași stare veche și ar trece toate — verificat în browser, prima variantă lăsa trei comenzi în baza de date deși butonul apărea stins. La un refuz butonul se aprinde la loc, iar deschiderea sau anularea unui formular stinge semaforul, ca o cerere fără răspuns să nu blocheze panoul. Probe în .claude/check-panouri.mjs: pagina e rulată cu un setState asincron, ca în React, butonul apăsat de două-trei ori într-o singură tură de buclă, iar spre server pleacă o singură comandă — inclusiv pe calea acceptării unei cereri, unde al doilea clic făcea a doua comandă pentru aceeași cerere.

- [x] **Comenzile nu se reîmprospătează; salvarea rescrie comanda cu instantaneul vechi, etapă cu tot**
  `Administrare Comenzi.dc.html:918` · *grav*
  Defectul avea DOUA cauze si le-am reparat pe amandoua. (a) Cronometrul aducea doar cererile. Linia 929: `this.reqTimer = setInterval(() => this.pullRequests(), 4000)` a devenit `setInterval(() => this.tick(), 4000)`. Metoda noua `tick()` (linia 1015) cheama `this.treziZiua(); this.pullRequests(); this.pullOrders();`. Garda `__ordSeen` din pullOrders ramane, deci setState se face doar cand datele chiar s-au schimbat.

- [x] **Limita cererilor de parolă nouă este globală, nu pe conexiune: 10 cereri opresc recuperarea pentru toată lumea**
  `api/lib/auth.php:142` · *grav*
  Pragul cererilor de parolă nouă e acum pe conexiune: forgot_rate_limited() numără WHERE ip = ? AND username = 'recuperare', iar rândul se scrie abia după ce se știe că cererea are ce trimite. Probe: test-parola.mjs „cererile altei conexiuni nu opresc recuperarea de aici" și „cererea cu câmpul gol nu consumă din plafon" — pică amândouă dacă pun înapoi login_rate_limited().

- [x] **Contorul de blocare a contului nu are fereastră de timp: după prima blocare, o singură greșeală blochează contul încă 20 de minute, la nesfârșit**
  `api/lib/auth.php:205` · *grav*
  Contorul de blocare se calculează din login_attempts, în fereastra nouă lock_window_min (60 minute), nu din coloana users.failed_logins care nu scădea niciodată. Probă: test-parola.mjs „contorul rămas din trecut nu mai blochează contul" (pică dacă revin la failed_logins + 1), plus perechea „douăsprezece greșeli strânse într-o oră blochează contul → 423" / „aceleași greșeli, de acum trei ore, nu mai blochează", care arată că fereastra chiar taie.

- [x] **O notificare eșuată nu e văzută de nimeni, niciodată**
  `api/lib/notify.php:111` · *grav*
  GET /settings aduce acum lista anunțurilor eșuate nevăzute (coloana seen_at exista în schemă din start, dar n-o scria și n-o citea nimeni), Setările le arată ca alertă roșie deasupra filelor — vizibilă pe orice filă — iar POST /settings/notifications/seen le marchează ca văzute. Până acum un anunț nelivrat trăia doar în tabelul notifications, în care nu se uita nicio pagină. Probe: test-settings, blocul „anunțurile eșuate ajung sub ochii cuiva” (8 verificări, inclusiv 403 pentru angajat).

- [x] **Produsul scos din catalog blochează definitiv orice corectură pe comenzile și cererile care îl conțin**
  `api/lib/orders.php:162` · *grav*
  price_items() a pierdut AND active = 1 din interogare (rândul trebuie citit oricum, ca să se poată tarifa) și a primit al patrulea parametru, $ingaduite: produsele aflate DEJA pe comanda care se corectează sau pe cererea care se acceptă trec chiar scoase din catalog. Cele două rute care nasc ceva nou — POST /orders și POST /requests — nu trimit nimic, deci un produs retras tot nu se mai vinde. Refuzurile s-au despărțit: „inexistent" (cu ce e de făcut) și „scos din catalog" (cu numele produsului și cum se repune), amândouă cu codul bad_product. Probă: test-defect-produs-scos (34 de verificări; dată înapoi reparația, pică 12).

- [x] **Schimbarea codului tabletelor nu închide tabletele deja deschise, deși pagina spune să-l schimbi „când pleacă cineva din echipă”**
  `api/lib/settings.php:251` · *grav*
  settings_save() șterge sesiunile contului „panou" când se schimbă panel_code sau când panel_enabled trece pe 0. Starea se citește din ce tocmai s-a scris, nu cu setting_bool(): settings_all() ține un cache pe cerere, dinaintea salvării. Probe: test-api.mjs „schimbarea codului scoate tabletele deja deschise → 401" și „oprirea accesului cu cod scoate tabletele deschise → 401".

- [x] **Schimbarea parolei nu anulează linkurile de recuperare deja trimise**
  `api/routes/auth.php:301` · *grav*
  Schimbarea parolei — din cont (route_change_password) și de către administrator (route_users_password) — închide acum și cererile de recuperare nefolosite: UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL. Probe: test-parola.mjs „linkul cerut înainte nu mai poate pune altă parolă → 410" și „și aici linkul cerut înainte se închide → 410".

- [x] **Parola pusă prin linkul „am uitat parola" nu e tăiată de spații, dar la autentificare este**
  `api/routes/auth.php:238` · *grav*
  route_reset() taie parola de spații, ca toate celelalte drumuri, și șterge încercările eșuate ale contului (clear_attempts), fiindcă blocarea se numără acum din ele. Pagina Intrare taie și ea (Intrare.dc.html). Probe: test-parola.mjs „se intră cu parola fără spații, cum o taie și autentificarea" și „după resetare se intră imediat" — pică amândouă dacă scot trim-ul, respectiv clear_attempts.

- [x] **Mutarea unei comenzi în altă zi păstrează seq-ul vechi și pică pe cheia unică (day, seq) — modificarea se pierde cu „Eroare internă”**
  `api/routes/orders.php:238` · *grav*
  Mutarea în altă zi renumerotează comanda: seq devine MAX(seq)+1 pe ziua nouă, în tranzacție, cu reîncercare la coliziune. Referința pe care o știe clientul rămâne neschimbată. Probă: .claude/test-editare.mjs, secțiunea „mutarea unei comenzi în altă zi", pe două zile libere căutate pe server ca prima comandă a fiecărei zile să poarte numărul 1 (verificată stricând renumerotarea la loc).

- [x] **„Salvează modificările” minte: pozițiile, clientul și adresa editate în formular nu se scriu nicăieri**
  `api/routes/orders.php:227` · *grav*
  route_orders_update() acceptă acum customerName (cu relegarea contului de client), phone, address, ref și items; pozițiile se rescriu în aceeași tranzacție cu antetul, cu totalul și varianta recalculate din catalog. Prețul trimis de browser se ignoră. Probă: .claude/test-editare.mjs, secțiunea „PATCH /orders/{id}".

- [x] **PATCH /orders recalculează customer_id din nume la fiecare salvare și rupe legătura cu contul clientului**
  `api/routes/orders.php:245` · *grav*
  PATCH /orders nu mai recalculează customer_id din numele de pe formular. Am scos perechea `$sets[] = 'customer_id = ?'; $vals[] = client_by_name($nume);` din ramura `customerName` și am pus în loc regula: legătura o dată existentă nu se mai atinge; căutarea după nume rulează DOAR când comanda nu are încă niciun cont (`$o['customer_id'] === null`) și doar dacă găsește ceva — deci poate adăuga o legătură care lipsește,

- [x] **Cantitățile schimbate pe o comandă deja scăzută nu ajung niciodată în depozit**
  `api/routes/orders.php:405` · *grav*
  Ajutorul `stock_unsync_order()` din api/lib/orders.php întoarce marfa în registru ca mișcări de intrare (+1) și coboară `stock_synced`. E chemat din tranzacția lui `route_orders_update`, ÎNAINTE de rescrierea pozițiilor — după `DELETE FROM order_items` nu mai există din ce calcula returul. Rândul comenzii se blochează cu FOR UPDATE, deci două salvări simultane nu întorc marfa de două ori, iar blocarea e și singura poartă a întrebării „era chiar scăzută?”. O poziție care nu-și mai găsește articolul de depozit oprește salvarea cu 409 `stock_return_missing_item`, în loc să coboare marcajul fără să întoarcă nimic: altfel aceleași bucăți ar ieși a doua oară la următoarea scădere. Probă: .claude/test-defect-zavor-stoc.mjs (35 de verificări; dată înapoi reparația, pică 17).

- [x] **O bucată adăugată pe o comandă veche retarifează și bucățile deja confirmate**
  `api/routes/orders.php:370` · *grav*
  Aceeași reparație ca la acceptare, pe PATCH /orders/{id}: pozițiile care erau deja pe comandă își păstrează prețul cu care au fost confirmate, iar prețul de azi se aplică numai cantității adăugate acum. Ruta citește order_items înainte de tranzacție și trece harta lui price_items(). Probă: test-reparat-retarifare.

- [x] **PATCH /orders cere doar require_staff: contul comun al tabletelor mută orice comandă pe contul oricărui client**
  `api/routes/orders.php:240` · *grav*
  Poartă de câmpuri la intrarea în rută: din contul de personal trece doar `status` (`ORDER_FIELDS_STAFF`, api/routes/orders.php). Cererea se oprește întreagă, cu 403 `staff_field_denied`, iar mesajul numește câmpurile refuzate — un `customerName` strecurat lângă `status` nu mai trece neobservat odată cu apăsarea firească a brutarului. Administratorul lucrează mai departe pe tot formularul, iar schimbarea clientului se scrie acum și în jurnal. Probă: .claude/test-reparat-drepturi-patch.mjs (35 de verificări; dată înapoi reparația, pică 23).

- [x] **Două acceptări simultane ale aceleiași cereri produc două comenzi**
  `api/routes/requests.php:225` · *grav*
  Acceptarea blochează cererea în tranzacție (SELECT … FOR UPDATE) și o marchează numai dacă e încă „nou” (UPDATE … AND status = 'nou'); dacă marcarea nu atinge niciun rând, tranzacția se dă înapoi și cererea a doua primește 409. Probă în .claude/test-flow.mjs: chiar instrucțiunea de marcare, scoasă din sursă și rulată a doua oară peste cererea acceptată, nu are voie să atingă niciun rând.

- [x] **Notificările blochează răspunsul către client, deși comentariul spune contrariul: până la 45 de secunde de așteptare la trimiterea unei cereri**
  `api/routes/requests.php:167` · *grav*
  POST /requests — rută publică, fără cont — trimitea anunțurile sincron, înaintea răspunsului: cu trei canale pornite și un capăt care înghite pachetele, vizitatorul aștepta 30-45 de secunde pentru o cerere deja salvată, iar fiecare astfel de cerere ținea ocupat un proces PHP. json_out_early() din api/lib/http.php trimite antetele și corpul cu Content-Length exact, golește toate nivelurile de buffer, cheamă fastcgi_finish_request()/litespeed_finish_request() și se întoarce; anunțurile pleacă după. Probe: test-php, patru verificări, inclusiv ordinea din rută și plasa care prinde cazul în care funcția ar opri scriptul.

- [x] **Cererile de pe site nu au nicio verificare de lungime: un câmp mai lung decât coloana pierde comanda cu „Eroare internă”**
  `api/routes/requests.php:138` · *grav*
  Câmpurile de text trec prin `text_field()` din api/lib/orders.php: taie caracterele de control, refuză cu 422 și un mesaj clar ce câmp e prea lung și cât încape, în loc să lase PDO să dea „Eroare internă". Numele clientului e obligatoriu — nu se mai înlocuiește tăcut cu „Client". Probe: „nume mai lung decât coloana → 422, nu 500" și „comandă fără numele clientului → 422".

- [x] **Pagina spune angajatului „nu are acces la comenzi", dar serverul îi lasă deschise acceptarea/respingerea cererilor și ordinea rutei**
  `api/routes/requests.php:191` · *grav*
  Acceptarea, respingerea și redeschiderea cererilor, plus ordinea opririlor de pe rută (PUT /routes), cer acum require_role('admin'), nu require_staff(). Nicio pagină nu pierde nimic: toate patru se cheamă doar din „Administrare Comenzi”, care se închide singură pentru alte roluri. Probe în .claude/test-api.mjs (angajatul primește 403 la respingere, acceptare și redeschidere, iar cererea rămâne unde era) și în .claude/test-settings.mjs (angajat 403, administrator 200 la reordonarea rutei).

- [x] **Numele completat la acceptare rupe legătura comenzii cu contul clientului**
  `api/routes/requests.php:314` · *grav*
  Căutarea după nume, din route_requests_accept, are voie de acum să LEGE și să MUTE comanda, niciodată să o dezlege: dacă numele scris de administrator duce la un cont, comanda pleacă la acel cont; dacă nu duce nicăieri, rămâne contul cu care a venit cererea. Înainte, o corectură nevinovată („Brutăria X" → „Brutăria X S.R.L.") arunca legătura, iar clientul primea „Nu ai acces la această comandă" pentru propria lui comandă. Dezlegarea rămâne posibilă, dar cerută pe față: PATCH /orders/{id} cu customerId null. Probă: test-reparat-legatura-cont (18 verificări; dată înapoi reparația, pică 3).

- [x] **Acceptarea unei cereri o retarifează la prețul de azi din Catalog, fără să spună nimănui**
  `api/routes/requests.php:341` · *grav*
  price_items() primește harta prețurilor deja promise (honored_prices(), din order_request_items la acceptare și din order_items la salvare). Prețul promis acoperă CANTITATEA promisă: cele 10 bucăți confirmate la 3,49 rămân la 3,49, a unsprezecea se plătește cu prețul de azi — altfel „mai pune unul" ar fi fost portița prin care o mie de bucăți se cumpărau la prețul de anul trecut. Feliile cu același preț se lipesc la loc, deci în cazul obișnuit comanda are tot o singură poziție. Diferența față de catalog se scrie în jurnal (price_honored_note(), tăiată la 12 poziții ca să nu depășească TEXT-ul coloanei). Probă: test-reparat-retarifare (31 de verificări; dată înapoi reparația, pică 12).

- [x] **„Acceptă" și „Respinge" apăsate în aceeași clipă: cererea rămâne „respinsă", comanda pleacă în laborator, iar redeschiderea naște a doua comandă**
  `api/routes/requests.php:448` · *grav*
  Respingerea se face acum într-o tranzacție, cu rândul blocat (SELECT ... FOR UPDATE) și cu starea recitită sub zăvor, iar UPDATE-ul are condiția AND status = 'nou' și i se verifică rowCount: cine apasă al doilea pleacă cu 409, nu suprascrie. Redeschiderea refuză pe față o cerere care are deja order_id, iar acceptarea o refuză și ea, oricare i-ar fi starea — plasa pentru rândurile lăsate strâmbe înainte de reparație, care puteau fi redeschise și acceptate a doua oară. Probă: test-defect-cursa-cerere (28 de verificări; dată înapoi reparația, pică 14).

- [x] **Comanda ale cărei produse n-au articol în depozit e marcată „scăzută” definitiv, deși nu s-a scăzut nimic**
  `api/routes/stock.php:296` · *grav*
  Comanda a devenit unitatea de lucru: dacă vreo poziție a ei n-are articol în depozit, nu se scade nimic din ea și rămâne nescăzută, ca să poată fi reluată după ce se creează articolul. Răspunsul spune și câte comenzi au rămas pe loc („skipped”), iar Depozit scrie asta în mesaj. Probă: .claude/test-settings.mjs, secțiunea „comanda fără articol în depozit”.

- [x] **Un produs redenumit ajunge cu două articole de depozit și fiecare comandă scade de două ori**
  `api/routes/stock.php:321` · *grav*
  Scăderea alege UN singur articol pentru fiecare poziție, printr-o subinterogare care întoarce cel mult un id (api/routes/stock.php): întâi cel legat prin `product_id`, apoi cel potrivit pe nume, iar articolele scoase din depozit trec la urmă — altfel scăderea ar cădea pe un rând pe care depozitarul nu-l mai vede nicăieri, iar singurul articol vizibil n-ar mai coborî niciodată. Dublura e oprită și la naștere: `POST /stock/items` refuză cu 409 `product_taken` un al doilea articol viu pe același produs, iar caseta „Articol nou" din Depozit trimite acum `productId`, aflat din comenzile în care apare numele — inclusiv din cele predate, tocmai cele din mesajul care îi cere depozitarului să facă articolul. Returul de stoc din `stock_unsync_order()` folosește exact aceeași alegere, ca marfa să nu iasă dintr-un raft și să se întoarcă în altul. Probe: .claude/test-reparat-scadere-dubla.mjs (27 de verificări; dată înapoi reparația, pică 11) și blocul „Articol nou" din .claude/check-panouri.mjs (9 verificări).

- [x] **Resetarea parolei nu protejează contul de sistem „panou" și deconectează toate tabletele**
  `api/routes/users.php:158` · *grav*
  route_users_password() refuză contul de sistem (422 system_account), route_users_list() întoarce isSystem, iar pagina Conturi ascunde pentru el Generează / Trimite pe mail / Schimbă parola și pune în loc trimiterea la Setări → Acces. În plus resetarea cheamă clear_attempts(), fiindcă blocarea se numără din login_attempts. Probe: test-api.mjs „lista spune care cont e de sistem" și „contul de sistem nu primește parolă → 422"; test-parola.mjs „se intră imediat cu parola dată de administrator".

- [x] **Ștergerea numelui în pagina Conturi trimite name = NULL pe o coloană NOT NULL și dă „Eroare internă"**
  `api/routes/users.php:93` · *grav*
  Numele golit e refuzat cu 422 empty_name în loc să ajungă NULL pe o coloană NOT NULL și să iasă „Eroare internă.". Celelalte trei câmpuri rămân nullable, deci golirea lor merge mai departe. Probe: test-api.mjs „numele golit e refuzat, nu dă eroare internă → 422", „numele rămâne cel dinainte" și „e-mailul golit se acceptă mai departe → 200".

- [x] **Instalarea nu taie spațiile din parola primului administrator, dar autentificarea le taie**
  `api/setup.php:76` · *grav*
  api/setup.php:76-77 — parola și confirmarea ei se taie acum de spații înainte de orice altceva: `$pass = trim((string) ($_POST['password'] ?? ''));` și la fel `$pass2`. Cele două capete se poartă identic: autentificarea taie în need() (api/lib/http.php:139), deci și locul unde se STABILEȘTE parola taie. Nu am mutat nimic din need(), n-am atins niciun fișier din afara alor mele.

- [x] **Pagina clientului îi spune mereu că e următoarea oprire a șoferului, oricâte opriri ar fi înaintea lui**
  `Client.dc.html:399` · *grav*
  A câta oprire e clientul vine din routeSeq trimis de server; pagina nu-și mai construiește o rută din propriile comenzi.

- [x] **Pagina clientului filtrează încă o dată comenzile după numele afișat; la redenumirea contului toate comenzile lui dispar**
  `Client.dc.html:373` · *grav*
  Pagina clientului își ia comenzile după contul lui, nu după numele afișat: redenumirea contului nu-i mai golește ecranul.

- [x] **Ștergerea unui cont se face din prima apăsare, fără nicio confirmare și fără cale de întoarcere**
  `Conturi.dc.html:574` · *grav*
  Ștergerea unui cont cere o a doua apăsare pe același buton, cu un avertisment care spune ce dispare și ce rămâne, plus un buton de renunțare. Probe: check-panouri „ștergerea unui cont cere o a doua apăsare”, „și se poate opri înainte să se întâmple”.

- [x] **Pagina Depozit calculează stocul din ultimele 1000 de mișcări, nu din soldul trimis de server**
  `Depozit.dc.html:658` · *grav*
  Stocul pornește de la soldul trimis de server, nu de la suma ultimelor 1000 de mișcări; caseta de ieșire nu mai refuză o ieșire acoperită.

- [x] **Linkul de resetare a parolei nu afișează formularul dacă dispozitivul are deja o sesiune deschisă**
  `Intrare.dc.html:579` · *grav*
  Pagina de Intrare deosebește acum linkul de resetare de o sesiune deschisă: cu ?reset=… în adresă arată formularul de parolă nouă chiar dacă dispozitivul e conectat, cu o notă care spune al cui e contul; fără el arată ca înainte. Probe în .claude/check-panouri.mjs.

- [x] **Jurnalul nu poate fi citit dincolo de primele 100 de intrări: reîmprospătarea din 5 în 5 secunde resetează pagina curentă**
  `Jurnal.dc.html:265` · *grav*
  Jurnalul se paginează de-adevăratelea: „Încarcă mai vechi” nu se mai pierde la reîmprospătare, iar pagina folosește gp.poll() în locul cronometrului propriu.

- [x] **Filtrul „Zonă” din Jurnal nu se potrivește niciodată: toate butoanele arată 0 și golesc lista**
  `Jurnal.dc.html:188` · *grav*
  Butoanele de zonă și coloana „Zonă” arată chiar zonele scrise de server în audit_log, nu o listă veche din pagină.

- [x] **Referința comenzii se repetă în fiecare zi, iar Panoul Laborator schimbă etapa după `ref` — avansează comanda altei zile**
  `Panou Laborator.dc.html:363` · *grav*
  Panoul ține comenzile pe id, nu pe referința care repornește în fiecare zi: apăsarea nu mai avansează comanda omonimă din altă zi.

- [x] **Redenumirea unei grupe trimite un PATCH la fiecare tastă, iar răspunsurile care se întorc în altă ordine lasă în baza de date un nume trunchiat**
  `Produse.dc.html:490` · *grav*
  Cele patru câmpuri din fila „Grupe” nu mai scriu pe server la fiecare tastă: tastarea ține minte doar textul (catEdit/catText), iar cererea pleacă o singură dată, la ieșirea din câmp (onBlur). Un nume neschimbat nu mai lasă intrare în Jurnal, iar un nume golit e oprit în pagină, cu mesaj. Probă în .claude/check-panouri.mjs: incheieGrupa() e scoasă din sursă și rulată — numele nou pleacă o dată, întreg; cel neschimbat și cel gol nu pleacă deloc.

- [x] **Dacă fotografia e refuzată la salvarea unui produs nou, produsul a fost deja creat, iar a doua apăsare pe „Adaugă în catalog” dă „adresa există deja”**
  `Produse.dc.html:409` · *grav*
  Formularul își trece numărul produsului imediat ce serverul l-a creat, înainte de urcarea fotografiei; dacă poza e refuzată, mesajul spune că produsul a intrat deja în catalog și doar fotografia a căzut, lista se reîmprospătează, iar a doua apăsare trimite un PATCH pe produsul existent, nu un al doilea produs cu același nume. Probă în .claude/check-panouri.mjs: save() e scos din sursă și rulat cu un „gp” de mucava — a doua apăsare are voie să scoată un singur POST /products.

- [x] **În ruta șoferului, meniul de zile arată „0 opriri" pentru toate zilele în afară de cea deschisă**
  `Ruta Sofer.dc.html:406` · *grav*
  Meniul de zile numără opririle de la server (GET /routes/counts), cu aceeași regulă ca ruta, nu din ziua deja încărcată.

- [x] **Ruta șoferului alege opririle după textul „Livrare” scris în cod, deci un mod de onorare redenumit din Setări golește ruta**
  `Ruta Sofer.dc.html:279` · *grav*
  Serverul hotărăște ce ajunge pe rută, după lista „moduri fără mașină” din Setări; un mod de onorare nou sau redenumit nu mai golește ruta.

- [x] **sitemap.xml trimite Google la patru adrese care nu există (404)**
  `seo/sitemap.php:20` · *grav*
  Harta declara catalog/, distribution/, about/ și contact/ — adrese care nu există: magazinul are o singură pagină reală, restul se comută în browser. Motoarele le cereau pe rând și primeau 404, apoi le raportau în Search Console. Probă: test-seo cere de la server fiecare adresă din sitemap; „fiecare adresă din sitemap există pe server”.

- [x] **Butonul „Trimite mesaj de test” șterge fără avertisment toate modificările nesalvate din pagina de Setări**
  `Setari.dc.html:570` · *grav*
  Butonul „Trimite mesaj de test” chema pull() după succes, care înlocuia tot formularul cu ce vine de la server: intervalele, etapele și zonele adăugate și nesalvate dispăreau, iar pe ecran rămânea mesajul verde. Acum, dacă există modificări nesalvate, cererea nu mai pleacă deloc și pagina spune că testul folosește datele de pe server; reîmprospătarea de după test se face doar cu formularul curat. Probe: check-setari.mjs (fișier nou, 5 verificări).

- [x] **Pachetul de test cu −20% nu ajunge niciodată la server: e scos tăcut din cerere, iar singur în coș blochează trimiterea**
  `Site Public.dc.html:1742` · *grav*
  Varianta se ia acum de pe fiecare rând al coșului (`frozen:` → congelat, `baked:` → copt), nu din tabul deschis, și pleacă pe fiecare poziție. Coșul amestecat — 100 congelate + 100 coapte — se înregistrează cu 754 lei, cât scrie pe ecran, cu pozițiile „congelat" și „copt" separat și comanda marcată „mixt". Verificat în browser (cererea 256) și în `.claude/test-flow.mjs` (cerere → acceptare → comandă).

- [x] **Când catalogul nu se încarcă, pagina nu spune nimic și oferă un pachet de test de 0,00 RON**
  `Site Public.dc.html:969` · *grav*
  `catErr` ajunge într-o legătură din pagină: un anunț `role="alert"` explică ce s-a întâmplat și oferă „Reîncarcă pagina", iar cardul pachetului de test se ascunde peste un catalog gol în loc să ofere „0 produse, 0,00 RON". Verificat în browser cu ruta `/api/products` oprită: anunțul apare, cardul dispare, statistica arată „—".

- [x] **Pozele mari ale paginii publice lipsesc din sursă, iar publicarea le-ar fi șters și de pe serverul care merge**
  `Site Public.dc.html:183` · *grav*
  Cele trei poze au fost aduse de pe server în `assets/img/` (fișiere adevărate, nu `data:`), iar sloturile au primit `src`, exact în aceeași așezare ca pe serverul care merge. Nu s-a refăcut fișierul de stare, deși se putea: `image-slot.js:94` citește `.image-slots.state.json`, care pe server dă 404 — deci `assets/image-slots.state.json` de acolo e o fosilă a unui export vechi, pe care nimeni n-o citește, iar pozele de pe site vin numai din `src`. Am ales calea dovedită. Fișierul de stare a rămas `{}`: slotul de probă din el nu avea ce căuta în pachet. Probă: ultima grupă din .claude/check-site-public.mjs (30 → 43 de verificări) leagă trei lucruri care singure ar minți — atributul din marcaj, fișierul pe disc și amândouă în pachet. Măsurată prin trei reveniri: scos `src`-ul → pică „slotul cere o poză"; ștearsă poza din `deploy/` → pică „a ajuns în pachet"; pus slotul de probă înapoi → pică „fișierul de stare nu poartă sloturi de probă". `pf-about-1..4` de la „Despre noi" nu se pot recupera: n-au `src` nici pe server și nu există în nicio copie — `data:` nu lasă fișier pe disc din care să le iei înapoi. Le-a pus omul la loc, ca fișiere: patru PNG-uri de 2 MB, botezate exact după substituenții sloturilor, deci potrivirea n-a avut nimic de ghicit. Trecute prin GD la 1200 pe latura lungă și calitate 82 — aceeași măsură la care stau celelalte trei — 8,3 MB au ieșit 453 KB, și au intrat în `assets/img/` cu prefixul `despre-`, ca să nu se lipească `ambalare.webp` de `ambalaj.webp`, care sunt poze diferite despărțite de o literă. Sloturile au primit `src` în aceeași așezare. Verificat în browser pe pagina „Despre noi": toate patru `complete: true`, 1200×1200 și 1200×900 măsurate din `naturalWidth`, patru `200 OK` în rețea, zero erori în consolă. Lista din probă a crescut de la trei poze la toate șapte (43 → 59 de verificări), măsurată din nou prin trei reveniri: scos `src`-ul de la `pf-about-2` → pică exact „slotul cere o poză", și numai ea; ascunsă `despre-echipa.webp` de pe disc → pică două; golită copia din `deploy/` → pică „a ajuns în pachet".

- [x] **Adresa clientului încape în cerere (255) dar nu pe cont (190): cererea se salvează, iar clientul primește „Eroare internă”**
  `sql/001_schema.sql:21` · *grav*
  Migrarea `sql/004_variante_pe_pozitie.sql` lărgește `users.address` la VARCHAR(255), cât are și cererea. Adresa nu se mai trunchiază la salvarea contului.

- [x] **În pachetul publicat, panoul Catalog nu are folderul products/, deci toate miniaturile produselor sunt rupte pe lab.goldenpastry.ro**
  `.claude/build-deploy.mjs:58` · *mediu*
  RewriteRule ^products/(.+)$ → https://goldenpastry.ro/products/$1 [R=302] în .htaccess-ul panourilor. Miniaturile din Catalog cereau pozele de la lab.goldenpastry.ro, unde folderul nu există. N-am copiat folderul în lab/: prima poză urcată după publicare ar fi apărut ruptă chiar în panoul din care tocmai fusese urcată, și s-ar fi dublat 5,2 MB în pachet. Probă: check-deploy „panourile trimit cererile de poze acolo unde stau fișierele”.

- [x] **Pachetul de publicare include verifica.php, o pagină de diagnostic fără autentificare**
  `.claude/build-deploy.mjs:333` · *mediu*
  Pachetul refuză acum să trimită o pagină de instalare căreia i-a dispărut zăvorul: verifică setup.php pentru http_response_code(404) și pentru codul de instalare, verifica.php pentru 404, iar dacă lipsesc, șterge fișierul din pachet și iese cu eroare. Probă: check-secrets, blocul „zăvor” — cu plasa scoasă apare „ATENȚIE .claude/build-deploy.mjs (plasa din pachet)”.

- [x] **robots.txt-ul panourilor interzice crawlarea, ceea ce împiedică Google să vadă tocmai antetul noindex**
  `.claude/build-deploy.mjs:262` · *mediu*
  robots.txt-ul panourilor spune acum Allow: / — interdicția rămâne în antetul X-Robots-Tag, pe care robotul îl citește doar dacă are voie să ceară pagina. Probe: check-deploy „robots.txt-ul panourilor nu mai interzice crawlarea”, „robotul are voie să intre ca să citească interdicția”, „interdicția rămâne pusă în antet”.

- [x] **Proba pachetului cerea ca pagina să aibă sloturi goale, deci striga „defect” exact când pozele erau puse la loc**
  `.claude/check-deploy.mjs:51` · *mediu*
  Premisa se citește acum din cod, nu din pagină: verificarea cere ca `Site Public.dc.html` să ducă `image-slot.js`, iar scriptul să aibă și `fetch(STATE_FILE)`, și `const url = this._userUrl || srcAttr`. Astea trei sunt chiar ce ține pericolul viu, și nu se schimbă când umpli sloturile. Comentariul de deasupra scrie amândouă variantele greșite dinainte, ca a treia să nu le repete. Măsurată prin două reveniri de sens diferit: scos `<script src="./image-slot.js">` din pagină → pică; schimbat `const url = this._userUrl || srcAttr` în `const url = srcAttr` → pică. Verificarea pe `.htaccess` a rămas neatinsă: ea era corectă, doar paznicul ei mințea.

- [x] **Verificarea cheilor duplicate din `state` nu rulează pe 6 din 14 pagini, printre ele cele mai mari trei**
  `.claude/check-pages.mjs:140` · *mediu*
  Am înlocuit tiparul de o linie `/^\s*state\s*=\s*\{([^\n]*)\}/m` cu o funcție nouă, `stareaPaginii(code)`, care: (1) rulează pe codul trecut prin `faraTexte()`, ca să nu numere acolade dintr-un șir sau chei dintr-un exemplu comentat; (2) prinde şi `state = {` şi `this.state = {`, cu `matchAll` — deci toate atribuirile din pagină, nu doar prima; (3) merge de la acolada deschisă până la perechea ei numărând `{[(` şi `}

- [x] **Verificarea check-sql nu se uită niciodată la datele inițiale: regula caută „INSERT INTO”, iar seed-ul folosește „INSERT IGNORE INTO”**
  `.claude/check-sql.mjs:36` · *mediu*
  Verificatorul rescris: prinde și „INSERT IGNORE INTO”, numără valorile mergând pe adâncimea parantezelor (un rând poate ocupa mai multe linii), taie blocul la primul „ON DUPLICATE KEY” sau „;”, citește și coloanele adăugate prin ALTER TABLE din migrări, iar dacă nu mai găsește nicio inserare se plânge el însuși. Verifică acum 11 inserări, față de 0. Probe (dovada3): cu tiparul stricat apare „nicio inserare găsită”; cu o valoare ștearsă dintr-un rând real apare „rândul 6: 5 valori pentru 6 coloane”.

- [x] **O probă afirmă în titlu că un cont a fost șters, dar verifică doar că i-a primit numărul**
  `.claude/test-api.mjs:163` · *mediu*
  `check('contul temporar a fost șters', !!id, …)` afirma ștergerea în nume, dar verifica prezența numărului primit la creare, iar rezultatul lui `del()` se arunca. Acum: const sters = id ? await del(admin, '/users/' + id) : null; const dupa = await get(admin, '/users'); const maiExista = (dupa.data?.users || []).some((u) => u.username === tmpUser); check('contul temporar a fost șters', sters?.status === 200 && sters?.

- [x] **Probele care ating baza de date citesc `api/config.php`, deci se uită în „gp_dev” orice ar spune GP_BASE**
  `.claude/test-parola.mjs:46` · *mediu*
  Ajutorul comun .claude/baza-probei.mjs: `configulLuiBase()` află, după portul din `GP_BASE`, configurarea instanței care ascultă acolo (`instanta.mjs` își ține starea în %TEMP%/gp-instante/<nume>.json) și o pune în mediu ca `GP_CONFIG` — chiar la încărcarea modulului, fiindcă PHP-ul chemat de probă moștenește mediul procesului. `api/index.php` citea deja variabila asta, deci regula e aceeași peste tot: mediul are întâietate. Fragmentele `php -r` din probe s-au scris cu `CERE_CONFIG` (`(getenv("GP_CONFIG") ?: "api/config.php")`) în locul căii bătute în cuie, iar plasa de siguranță `/^(gp_dev|gp_test|gp_fresh)$/` s-a mutat în `BAZA_DE_PROBA`, care primește și bazele instanțelor (`gp_i_<nume>`) — altfel probele s-ar fi oprit singure cu „baza nu e una de dezvoltare”. Unsprezece fișiere trecute pe ajutorul comun (opt probe, curata-probe.mjs, aplica-sql.mjs), inclusiv trei care purtau fiecare câte o copie a aceleiași funcții. O valoare pusă de om din afară nu se atinge. Fără nicio instanță pornită nu se schimbă nimic: `GP_CONFIG` rămâne nepus și PHP cade tot pe `api/config.php`. Dovadă: suita întreagă, rulată cu `GP_BASE=http://127.0.0.1:8203/api`, dă tot 1509 trecute · 0 picate, iar curățenia raportează „Șters, din «gp_i_verifg»: 120 urme” — o bază pe care nu o atinsese niciodată înainte.

- [x] **test-parola moare cu o eroare de sintaxă MySQL pe o bază proaspătă, fiindcă așteaptă un cont pe care îl face altă probă**
  `.claude/test-parola.mjs:357` · *mediu*
  Proba își face singură contul dacă nu-l găsește: caută `testclient` în `users`, iar dacă lipsește intră ca administrator și îl creează prin API cu aceleași date pe care i le dă `test-api` (nume, client, telefon, adresă de e-mail — proba are nevoie și de adresă, fiindcă verifică „se cere link și după e-mail, nu doar după utilizator”). Dacă nici administratorul nu răspunde, proba se oprește spunând ce-i lipsește, nu cu o eroare de MySQL. Dovadă: pe instanța proaspătă de pe 8203, aceeași probă dă acum 51 trecute · 0 eșuate — cât și pe „gp_dev”.

- [x] **Filtrul „Refuzate” din panoul de cereri nu găsește niciodată nimic**
  `Administrare Comenzi.dc.html:1426` · *mediu*
  Filtrul cere acum starea reală din baza de date, „respinsa", nu „refuzata" — valoare care nu exista. Probă: .claude/test-editare.mjs, „cererea refuzată apare pe filtrul respinsa"; verificat în browser: contorul „Refuzate" arată 2, nu 0.

- [x] **„Redeschide cererea” nu redeschide nimic și afișează un mesaj fără legătură**
  `Administrare Comenzi.dc.html:1469` · *mediu*
  Butonul se numește „Anulează refuzul", apare doar pe cererile respinse și cheamă POST /requests/{id}/reopen — rută nouă, care readuce cererea la „nou", șterge motivul refuzului și o lasă să fie acceptată normal. O cerere deja acceptată nu se poate redeschide (409): ar naște a doua comandă din aceleași date. Probă: .claude/test-editare.mjs, secțiunea „un refuz dat din greșeală se poate anula" (verificată scoțând ruta din api/index.php).

- [x] **Comanda nouă se salvează cu zona „Centru", care nu există în Setări — oprirea ajunge peste depozit**
  `Administrare Comenzi.dc.html:1136` · *mediu*
  Formularul nu mai inventează o zonă: pornește fără zonă, iar la livrare cere una explicit („Alege zona: fără ea, oprirea nu apare corect pe ruta șoferului."). Zonele vin din Setări, nu din cod. Probă: verificat în browser — salvarea unei livrări fără zonă e refuzată.

- [x] **Panoul de comenzi compară doar ziua din lună: „Comenzi azi" numără și comenzi din alte luni, iar „Total 7 zile" arată de fapt tot istoricul**
  `Administrare Comenzi.dc.html:1224` · *mediu*
  Statistica „Total 7 zile" numără doar comenzile din fereastra de șapte zile, nu tot ce întoarce serverul; subtitlul spune acum câte sunt în săptămâna curentă. Comenzile de azi se compară pe data întreagă. Probă: verificat în browser — 117 comenzi în registru, 39 în săptămâna curentă.

- [x] **Intervalul „06:00” din formularul de comenzi este refuzat de server**
  `Administrare Comenzi.dc.html:855` · *mediu*
  Intervalele din formular vin de la server (gp.slots()), deci sunt exact cele pe care le acceptă. Probă: .claude/test-settings.mjs verifică listele; formularul le citește prin SLOTS_OF().

- [x] **Fereastra de zile înghețată la încărcare: după miezul nopții comanda nouă pleacă pe ziua de ieri**
  `Administrare Comenzi.dc.html:868` · *mediu*
  `const DAYS` / `const TODAY` (liniile 868-869) au devenit `let`, plus o functie noua `refreshDays()` (linia 877) care le recalculeaza — exact tiparul din Panou Laborator.dc.html:282. TODAY fiind acum o legatura de modul, toate cele opt locuri care il citesc (day-ul comenzii noi 1200 -> acum 1338, acceptarea unei cereri, cardul „Comenzi azi", rDay, dayLabel, sortarea, filtrele) iau valoarea din momentul apelului, nu d

- [x] **Cantitatea scrisă greșit devine în tăcere 1 bucată, în loc să fie refuzată**
  `Administrare Comenzi.dc.html:1273` · *mediu*
  Metoda noua `citesteCantitate(v)` (linia 1056): taie spatiile, accepta virgula ca separator zecimal, cere `^\d+(?:\.\d{1,2})?$`, apoi numarul sa fie > 0 si <= 10000. Orice altceva intoarce `null`. Zecimalele sunt permise fiindca baza le pastreaza (qty DECIMAL(10,2), sql/001_schema.sql:161) si o comanda veche trebuie sa se poata salva mai departe; ce nu e numar sau nu e pozitiv se refuza.

- [x] **Eticheta grupei arată „0 produse”, dar ștergerea grupei e refuzată fiindcă serverul numără și produsele scoase din catalog**
  `api/lib/catalog.php:47` · *mediu*
  Serverul trimite acum două cifre pentru fiecare grupă — „products” (în catalog) și „productsAll” (toate, ca la refuzul ștergerii) — iar insigna din fila „Grupe” le arată pe amândouă: „2 în catalog · 1 scos”. Nota de sub listă spune și ea că produsele doar scoase din catalog opresc ștergerea. Probe în .claude/test-catalog.mjs (cifra din refuz e chiar cea din insignă) și în .claude/check-panouri.mjs (textul insignei, rulat din sursă).

- [x] **Adresa expeditor pentru e-mail nu este validată nicăieri, deși intră brut în antetele mesajului**
  `api/lib/notify.php:165` · *mediu*
  Adresa expeditor se validează cu FILTER_VALIDATE_EMAIL în două locuri: la salvare, ca greșeala să fie prinsă în fața omului care o face, și la trimitere, fiindcă în bază poate sta deja o valoare pusă înainte. O singură verificare acoperă și adresa fără TLD, și CR/LF-ul care ar fi adăugat anteturi proprii în mesaj. Probe: test-settings „o adresă expeditor fără @ este respinsă → 422”, „cu salt de linie → 422”, „adresa bună a rămas neatinsă după respingeri”.

- [x] **Produsul și cantitatea se iau prin cast brutal: un tablou devine tăcut produsul #1, respectiv 1 bucată**
  `api/lib/orders.php:153` · *mediu*
  `price_items()` cere numere, nu le mai stoarce din ce-a venit (api/lib/orders.php): `productId` trebuie să fie întreg sau text de cifre, `qty` număr sau text numeric. `productId: [22, 21]` pică acum cu 422 `bad_product` în loc să devină tăcut produsul cu id 1, la alt preț, sub un 201 „comandă primită”. Textul din <input> trece mai departe, ca panoul să salveze ca înainte. Probă: .claude/test-reparat-campuri-comenzi.mjs, grupa „produsul și cantitatea”; dată înapoi reparația, proba pică pe 15 verificări, printre care exact dovada din fișă — „Ștrudel cu mere”, 3,49 lei.

- [x] **O coordonată de zonă lăsată goală este acceptată ca 0, nu respinsă — oprirea ajunge în Golful Guineei**
  `api/lib/settings.php:154` · *mediu*
  isset('') e true și (float) '' e 0, deci o casetă de coordonată golită nu era ignorată, ci păstrată ca 0 — un punct din Golful Guineei: piciorul de rută ieșea de mii de kilometri și „Optimizează ruta” rearanja opririle față de el. Reparat în două locuri: zone_coord() întoarce null pentru gol, text sau zero (și acceptă virgula zecimală românească, care înainte tăia partea fracționară), iar guard_zone_coords() refuză cu 422 punctele stricate de chiar salvarea curentă, numind zona. Zonele deja salvate cu 0,0 nu blochează nimic: dispar din hartă, deci oprirea cade pe depozit. Probe: test-settings, blocul „coordonatele zonelor” (10 verificări).

- [x] **Oricine poate bloca de la distanță deschiderea tuturor tabletelor din laborator**
  `api/routes/auth.php:75` · *mediu*
  Codul tabletelor se numără acum pe conexiune: panel_rate_limited() din api/lib/auth.php contorizează pe IP ȘI pe pseudo-utilizatorul „panou”, iar record_attempt() nu mai șterge global greșelile lui la o intrare reușită. Cine ghicește codul își blochează doar propria conexiune, iar tableta din laborator deschide mai departe. Probe în .claude/test-api.mjs: 20 de greșeli de pe o adresă → 429 pentru ea, 200 pentru tabletă, iar intrarea bună a tabletei nu deblochează conexiunea care ghicește.

- [x] **Un preț scris cu litere e salvat tăcut ca 0,00 lei, iar comanda se înregistrează cu 0 lei**
  `api/routes/catalog.php:449` · *mediu*
  Prețul se citește acum cu o verificare adevărată, nu cu un (float) tăcut: textul trebuie să arate a număr (virgula e primită mai departe), iar zero și valorile negative sunt refuzate cu 422 și un mesaj care cere cifre. Aceeași închidere e folosită și la creare, și la editare. Probă în .claude/test-catalog.mjs: „la cerere” și 0 sunt respinse, produsul stricat nu intră în catalog, „2,85” se salvează ca 2.85, iar o editare respinsă lasă prețul vechi neatins.

- [x] **Zona comenzii nu se validează la creare/modificare (spre deosebire de cereri), iar o zonă inexistentă pică pe coordonatele depozitului**
  `api/routes/orders.php:143` · *mediu*
  Zona se verifică la creare față de lista din setări (`delivery_zones`); una necunoscută întoarce 422 cu zona numită în mesaj. O zonă goală rămâne permisă — comenzile ridicate de la magazin n-au zonă. Probă: „zonă inexistentă la creare → 422".

- [x] **Marcajul URGENT pus la adăugarea unei comenzi noi se pierde: creaea nu acceptă urgent/timed**
  `api/routes/orders.php:169` · *mediu*
  `urgent` și `timed` se citesc din corpul cererii și intră în INSERT. Marcajul pus la adăugare se păstrează, nu mai trebuie pus a doua oară după salvare. Probă: „URGENT pus la creare se păstrează".

- [x] **Referința e validată la 32 de caractere, dar coloana ține 24 — salvarea comenzii cade cu „Eroare internă"**
  `api/routes/orders.php:257` · *mediu*
  Am verificat în sql/001_schema.sql:172 lățimea reală a coloanei — `ref VARCHAR(24) NULL` — și am potrivit validarea la ea: `text_field($b['ref'], 'Referința', 32)` → `text_field($b['ref'], 'Referința', 24)`. Nu am atins sql/.

- [x] **Comanda mutată în altă zi ajunge cu aceeași referință ca o comandă de acolo**
  `api/routes/orders.php:397` · *mediu*
  Referința se păstrează, cum era gândit — e singurul lucru pe care clientul îl ține minte — dar se schimbă când e deja folosită în ziua nouă (api/routes/orders.php). Noua referință se caută până când e liberă acolo, iar schimbarea se scrie în jurnal („referință schimbată: #1001 → #1002 (era deja folosită în ziua nouă)”), ca omul de la telefon să poată spune clientului noul număr. Probă: .claude/test-editare.mjs, blocul „mutarea pe altă zi” — verifică amândouă fețele regulii: referința se schimbă la ciocnire și rămâne unică în ziua nouă, iar o mutare pe o zi liberă o lasă neatinsă. Dată înapoi reparația, pică 4 verificări.

- [x] **Comanda mutată pe altă zi intră în capul rutei deja stabilite: route_seq nu se resetează**
  `api/routes/orders.php:396` · *mediu*
  `route_seq` se golește la mutarea pe altă zi (api/routes/orders.php): locul de pe foaia de drum e al zilei lui. Comanda mutată se așază la coada rutei noi (`ORDER BY route_seq IS NULL, …`) și așteaptă să fie pusă la rând de administrator, în loc să se suprapună peste oprirea care avea deja acel număr. Probă: .claude/test-editare.mjs — o comandă primește un loc pe rută prin PUT /routes, apoi e mutată; verificarea cere `routeSeq === null`. Dată înapoi reparația, pică.

- [x] **PATCH /orders: zona nu se verifică deloc — nici față de lista din Setări, nici ca lungime**
  `api/routes/orders.php:345` · *mediu*
  Zona corectată trece prin `text_field($b['zone'], 'Zona', 64)` și se compară cu `delivery_zones()`, exact ca la creare și la acceptarea unei cereri (api/routes/orders.php): o zonă care nu e în Setări pică cu 422 `bad_zone`, iar refuzul enumeră valorile permise. O listă goală de zone lasă câmpul liber, ca la creare — patiseria care nu lucrează pe zone n-are de ce să fie oprită. Probă: .claude/test-reparat-campuri-comenzi.mjs, grupa „zona corectată”.

- [x] **PATCH /orders: mențiunile nu trec prin text_field — nelimitate, necurățate, iar peste 65.535 de octeți dau „Eroare internă”**
  `api/routes/orders.php:321` · *mediu*
  Mențiunile corectate trec prin `text_field($b['notes'], 'Mențiunile', 2000)`, aceeași măsură ca la creare (api/routes/orders.php): peste 2000 de caractere pică cu 422 `field_too_long` — un mesaj despre mențiuni, nu „Eroare internă”, și cu restul corecturii nepierdut — iar rândurile noi lipite din alt document se strâng la un spațiu. Un tablou trimis în locul textului nu mai ajunge cuvântul „Array” în bază. Probă: .claude/test-reparat-campuri-comenzi.mjs, grupa „mențiunile corectate”.

- [x] **hq_lat / hq_lng se salvează nevalidate: virgula zecimală mută punctul de plecare al rutei**
  `api/routes/products.php:69` · *mediu*
  Reprodus întâi pe serverul viu: PUT /settings {hq_lat:"44,39235"} -> 200 "changed":["hq_lat"], iar GET /lists întorcea {"lat":44,...} (depozitul cu ~43 km mai la sud); cu "n/a" -> lat 0 (Golful Guineei). Am pus setările la loc imediat. Cauza (lipsa validării la salvare) — api/routes/settings.php: am adăugat guard_hq_coords(), chemată în route_settings_save() imediat după guard_zone_coords().

- [x] **Zona nu e validată la acceptare, iar panoul trimite „Centru”, o zonă care nu există în Setări**
  `api/routes/requests.php:206` · *mediu*
  Acceptarea validează zona față de lista din Setări și răspunde 422 la una necunoscută; panoul nu mai trimite „Centru", ci cere o zonă explicită la livrare. Probă: .claude/test-editare.mjs, secțiunea „acceptarea refuză o zonă care nu există".

- [x] **Acceptarea unei cereri rămase în urmă creează comanda pe o zi trecută, pe care panoul din laborator n-o arată niciodată**
  `api/routes/requests.php:235` · *mediu*
  Am adăugat o margine de jos la ziua comenzii născute din cerere. Concret, două lucruri în api/routes/requests.php: 1) Funcția nouă `requests_today_local(): string` (pusă la începutul secțiunii „acceptare", înaintea rutei), care întoarce `(new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d')`.

- [x] **Nicio limită în sus la ziua comenzii: anul 3000 trece prin tot lanțul și rămâne comandă activă**
  `api/routes/requests.php:1` · *mediu*
  

- [x] **Comanda anulată după ce a fost scăzută din depozit nu întoarce marfa, iar registrul nu mai poate fi îndreptat**
  `api/routes/stock.php:368` · *mediu*
  Aceeași reparație: ieșirea din etapa de încheiere — cel mai des pe „Anulată” — cere returul, iar marfa se întoarce în depozit cu motivul „Retur: comandă ieșită din «…»”, purtând în `note` referința comenzii, ca perechea ei de ieșire. Scăderea următoare nu mai atinge comanda anulată; repusă pe etapa de încheiere, se scade din nou o singură dată. Motivul se taie la 96 de caractere, cât ține `stock_moves.reason`, ca o etapă lungă din Setări să nu transforme anularea în „Eroare internă”. Probă: .claude/test-defect-zavor-stoc.mjs, scena a doua.

- [x] **Datele prea lungi din depozit ies ca „Eroare internă”, nu ca refuz explicat**
  `api/routes/stock.php:57` · *mediu*
  Câmpurile depozitului au acum margini verificate acolo unde încă se poate spune ce e greșit (api/routes/stock.php): numele articolului și denumirea din registru prin `text_field()` la 160 de caractere, unitatea la 16, motivul unei mișcări la 96 și mențiunea la 255. Pragul minim și cantitatea trec printr-un ajutor nou, `num_field()` (api/lib/http.php, lângă `need()`): cere un număr adevărat — `(float)` pe un tablou dădea 1.0, deci un câmp completat greșit intra tăcut ca prag de o bucată — și îl ține în marginile lui DECIMAL(10,2), ca 1264 „Out of range" să nu mai ajungă „Eroare internă". `route_items_update` verifică și unicitatea numelui, ca redenumirea peste alt articol să răspundă tot 409 `name_taken`, nu 500: aceeași greșeală omenească nu mai are două răspunsuri, după ușa pe care ai intrat. Probă: .claude/test-reparat-depozit-refuzuri.mjs (31 de verificări; dată înapoi reparația, pică 17).

- [x] **PATCH /users/{id} salvează orice e-mail, fără validare — recuperarea parolei eșuează în tăcere**
  `api/routes/users.php:100` · *mediu*
  Reprodus întâi: POST /users cu „ion.popescu@yahoocom" dădea 422 bad_email, iar PATCH /users/729 cu ACEEAȘI valoare dădea 200 și salva adresa strâmbă. Reparație: am adăugat în api/routes/users.php funcția `check_user_email(string $email, ?int $exceptId = null)` (imediat după `const ROLES`), care întoarce fără nimic pentru adresa goală (cont fără e-mail e o stare normală), refuză formatul greșit cu 422 `bad_email` — ex

- [x] **setup.php ajunge în pachetul de publicare și creează primul administrator fără nicio autentificare**
  `api/setup.php:34` · *mediu*
  Două găuri, amândouă închise. DUPĂ instalare: pagina răspunde 404 și nu mai tipărește nimic, în loc să confirme oricui că a găsit o instalare Golden Pastry cu instalatorul încă pe server. ÎNAINTE de primul administrator — fereastra de ore dintre pasul 4 și pasul 6 din manual, pe un domeniu deja anunțat public de certificat: formularul cere un cod de instalare de cel puțin 12 caractere, scris de om în api/config.php, comparat cu hash_equals, trimis prin POST (nu în adresă, ca să nu rămână în jurnalele Apache). Probe: test-instalare „fără codul de instalare nu se creează niciun cont”, „cu alt cod decât cel din config.php instalarea e refuzată”, „nicio încercare respinsă nu lasă un administrator în bază”, „a doua rulare nu mai tipărește nimic”.

- [x] **verifica.php intră în pachetul de publicare și rămâne accesibil oricui pe api.goldenpastry.ro**
  `api/verifica.php:16` · *mediu*
  Pagina de verificare răspunde 404 din clipa în care instalarea are un administrator activ. Până acum spunea oricui trecea pe acolo ce versiune de PHP și de MySQL rulezi, ce extensii ai, cum se numesc tabelele și dacă erorile interne pleacă spre browser. Probe: test-api „GET /verifica.php pe o instalare cu administrator → 404”, „nu mai trimite nimic în pagină”.

- [x] **Verificarea de dinaintea publicării dă verde unei chei de criptare pe care aplicația o refuză**
  `api/verifica.php:213` · *mediu*
  Regula cheii de criptare stă acum într-un singur loc: crypto_key_valid() din api/lib/crypto.php, chemată și de crypto_key(), și de verifica.php. Pragul scris a doua oară în verificare — „cel puțin 32 de caractere” — dădea verde tocmai cheii alfanumerice de 64 de caractere pe care o cerea manualul, iar criptarea se oprea abia la setup.php: administrator deja creat, cod de tablete gol, instalare la jumătate. Manualul cere acum bin2hex(random_bytes(32)). Probe: test-php, cele opt cazuri de cheie + „crypto_key() întreabă crypto_key_valid(), nu o a doua copie a regulii” + „verifica.php judecă cheia cu crypto_key_valid(), nu cu un prag propriu”.

- [x] **Comanda trimisă din magazin nu apare în „Comanda mea”, deși ecranul de succes trimite clientul acolo**
  `Client.dc.html:340` · *mediu*
  Pagina „Comanda mea" arată acum și cererile trimise de pe site, nu doar comenzile deja acceptate. Cererea în așteptare nu devine comandă până n-o acceptă cineva din panou, deci clientul trimis pe pagina aceea de ecranul de succes găsea „Nicio comandă activă". Cele fără zi cerută stau la urmă: n-au după ce fi ordonate.

- [x] **Fiecare tastă apăsată în Nume / E-mail / Nume client trimite un PATCH și scrie o intrare în jurnal**
  `Conturi.dc.html:561` · *mediu*
  Casetele Nume / E-mail / Nume client scriu într-o ciornă a paginii și se salvează o singură dată, la ieșirea din câmp sau la Enter, nu la fiecare tastă. Verificat în browser: 20 de taste → 0 cereri, o ieșire din câmp → un singur PATCH cu valoarea întreagă. Probe: check-panouri „casetele de nume nu mai trimit un PATCH la fiecare tastă”, „ce se tastează se ține într-o ciornă a paginii”, „caseta «saveName/saveMail/saveCust» se salvează la ieșirea din câmp”.

- [x] **Pe cardul contului scrie „parolă schimbată <data>”, dar data afișată este ultima autentificare**
  `Conturi.dc.html:539` · *mediu*
  Data de lângă „parolă” e acum a schimbării, nu a ultimei autentificări; ultima intrare are rândul ei separat. Probe: check-panouri „și nu i se lipește data ultimei autentificări”, „iar data afișată e a schimbării, nu a ultimei intrări”, „contul tabletelor spune că nu are parolă”.

- [x] **Pagina Conturi afișează „parolă schimbată <dată>", dar data este a ultimei autentificări**
  `Conturi.dc.html:338` · *mediu*
  Coloană nouă users.password_changed_at (sql/005), scrisă la schimbarea proprie a parolei și ștearsă la resetarea de administrator; pagina o citește ca pwAt și desprePar() spune adevărul. Probe: check-panouri „un cont cu parola pusă de administrator e numit așa, nu «parolă schimbată»”, „fără o dată reală, pagina nu scrie niciuna”, „și data reală a schimbării parolei”.

- [x] **Nicio pagină nu permite schimbarea propriei parole, iar semnalul must_change_password nu e citit nicăieri**
  `Conturi.dc.html:442` · *mediu*
  Pagină nouă Parola.dc.html (parola.html pe lab. și clienti.): cere parola de acum, minim 8 caractere ca serverul, cheamă /auth/password. Se ajunge la ea din meniul celor șase panouri, din antetul Clientului, din meniul contului din magazin, iar la prima intrare cu parolă provizorie Intrarea duce direct acolo. Adresa dezvoltatorului scrisă în cod — valoare implicită la conturi noi și destinatar de rezervă — a fost scoasă. Probe: check-panouri „pagina de schimbare a propriei parole cheamă ruta serverului”, „cere parola de acum, nu doar pe cea nouă”, cele patru drumuri spre ea, „nicio adresă de e-mail scrisă în logica paginii Conturi”.

- [x] **Comenzile anulate continuă să blocheze („rezervă”) stoc pe pagina Depozit**
  `Depozit.dc.html:597` · *mediu*
  Comanda anulată nu mai rezervă marfă: „Disponibil” nu mai scade și eticheta „Lipsă” nu mai apare degeaba.

- [x] **Pragul minim din depozit se salvează la fiecare tastă apăsată și e suprascris de reîmprospătare**
  `Depozit.dc.html:699` · *mediu*
  Pragul minim se salvează la ieșirea din câmp, cu validare, și nu mai lasă o intrare în Jurnal când nu s-a schimbat nimic.

- [x] **„Scade din stoc" nu se blochează cât timp lucrează, iar serverul citește comenzile nescăzute fără blocare**
  `Depozit.dc.html:615` · *mediu*
  Butonul „Scade din stoc” se stinge cât timp lucrează, iar serverul citește comenzile nescăzute cu blocare, deci scăderea nu se poate dubla.

- [x] **Depozitul nu poate adăuga un articol nou: nicio pagină nu cheamă POST /stock/items**
  `Depozit.dc.html:509` · *mediu*
  Depozitul are acum caseta „Articol nou”: butonul din bara de sus și cel din bara de jos (pentru telefon) deschid un formular cu denumire, categorie, unitate și prag, iar salvarea cheamă POST /stock/items. Categoriile și unitățile vin din Setări → Liste, pragul gol lasă serverul să pună pragul implicit, iar un nume deja folosit se citește în casetă, nu în bannerul acoperit. Probe în .claude/check-panouri.mjs, care rulează pagina și se uită la ce pleacă spre server.

- [x] **addMove() înghite eroarea, apoi apelantul anunță succes: mișcarea pierdută pare înregistrată**
  `Depozit.dc.html:605` · *mediu*
  Cauza: addMove() se termina cu `.catch((e) => this.setState({ msg: e.message }))`, deci promisiunea se REZOLVA si pe esec, iar `.then`-ul apelantului anunta succesul peste mesajul de eroare. 1) addMove() (acum Depozit.dc.html:613-620): catch-ul pune mesajul si `msgBad: true`, apoi `throw e` — promisiunea ramane respinsa.

- [x] **„Rezervat” și „Lipsă la comenzi” se potrivesc după nume, nu după product_id**
  `Depozit.dc.html:700` · *mediu*
  Cauza: reserved() indexa cantitatile comandate dupa numele pozitiei (`by[i.n]`), iar randul de stoc le cauta dupa numele articolului de depozit (`res[c.n]`). Cele doua nume se despart la prima redenumire a produsului din catalog (api/routes/catalog.php nu scrie in stock_items), deci comenzile intrate dupa redenumire nu se mai potriveau.

- [x] **După deschiderea panourilor cu codul tabletei, toate cardurile arată „—" și scrie „cere autentificare"**
  `Intrare.dc.html:383` · *mediu*
  După deschiderea panourilor cu codul tabletei, pagina cere din nou cifrele: cardurile arată numerele zilei în loc de „—” și de „cere autentificare”. Probe în .claude/check-panouri.mjs.

- [x] **„Blochează panoul" închide sesiunea pe server, dar pagina continuă să afișeze „conectat" cu numele vechi**
  `Intrare.dc.html:524` · *mediu*
  „Blochează panoul” și „Ieși din cont” golesc acum și starea din pagină, nu doar sesiunea de pe server: gp.logout() e chemat, iar ecranul nu mai arată „conectat” cu numele omului care tocmai a plecat. Probe în .claude/check-panouri.mjs.

- [x] **Dacă doar una dintre cele trei cereri de cifre eșuează, cardurile afișează „0" în loc de „—"**
  `Intrare.dc.html:330` · *mediu*
  Cardurile de pe Intrare numără fiecare cerere separat: ce n-a răspuns rămâne „—”, ce a răspuns arată cifra. Înainte, o singură cerere picată punea „0” peste tot, iar laboratorul citea „0 comenzi azi” într-o zi plină. Probe în .claude/check-panouri.mjs.

- [x] **Căutarea din Jurnal caută doar în cele ~50 de intrări încărcate, deși serverul știe să caute în tot jurnalul**
  `Jurnal.dc.html:284` · *mediu*
  Căutarea și filtrele pleacă la server și caută în tot jurnalul, nu doar în intrările deja aduse.

- [x] **În Panou Laborator, reîmprospătarea la 4 secunde poate anula pe ecran etapa tocmai apăsată, iar eșecul salvării nu se anunță**
  `Panou Laborator.dc.html:334` · *mediu*
  Etapa apăsată nu mai e ștearsă de reîmprospătarea de la 4 secunde, iar refuzul serverului se vede pe ecran, nu doar în consolă.

- [x] **Fereastra de zile se calculează o singură dată la încărcare: tabletele lăsate deschise rămân peste noapte în ziua de ieri**
  `Panou Laborator.dc.html:268` · *mediu*
  Fereastra de zile se recalculează la ceas: tableta lăsată deschisă trece singură în ziua nouă.

- [x] **Reîmprospătarea între file a Panoului Laborator cade cu ReferenceError: `OKEY` nu există**
  `Panou Laborator.dc.html:338` · *mediu*
  Ascultătorul de „storage” nu mai compară cheia cu un nume inexistent: reîmprospătarea între filele aceluiași browser chiar funcționează, iar starea locală (bife, ordine manuală) se recitește.

- [x] **Cantitatea zecimală tastată în panou dispare de pe lista de făcut a laboratorului**
  `Panou Laborator.dc.html:605` · *mediu*
  

- [x] **Butonul × nu șterge niciodată definitiv un produs: ruta DELETE /products/{id} nu e apelată de nicio pagină, deși documentația promite ștergerea**
  `Produse.dc.html:473` · *mediu*
  × de pe rând cere acum DELETE /products/{id}, iar serverul alege între ștergerea de tot (cu fotografie cu tot) și scoaterea din catalog, după cum produsul apare sau nu în comenzi; mesajul afișat este al lui, ca pagina să nu promită una și să se întâmple alta. ↩ repune produsul, ca înainte. Probă în .claude/check-panouri.mjs: handlerul butonului și removeProduct() sunt scoase din sursă și rulate — se urmărește ce verb pleacă și ce citește omul după.

- [x] **Zilele din pagina șoferului se calculează o singură dată: peste noapte tableta arată ruta de ieri**
  `Ruta Sofer.dc.html:169` · *mediu*
  Zilele din pagina șoferului se recalculează, nu se mai îngheață la încărcare. Tableta lăsată deschisă peste noapte arăta ruta de ieri, iar șoferul pleca pe livrările zilei trecute.

- [x] **Datele structurate trimit Google prețul de produs copt, pe când pagina arată implicit prețul congelat**
  `seo/index.php:162` · *mediu*
  Prețul din datele structurate e cel afișat implicit pe card (varianta congelată, cu care se deschide magazinul), nu prețul „gata de servire”, mai mare. Google arăta în rezultate o sumă pe care pagina n-o afișează. Probă: test-seo compară fiecare preț marcat cu cel din catalog și verifică întâi că există produse la care cele două prețuri chiar diferă — altfel proba ar trece și cu reparația dată înapoi.

- [x] **canonical, og:url și sitemap se construiesc din antetul Host, iar www.goldenpastry.ro nu e redirecționat nicăieri**
  `seo/index.php:103` · *mediu*
  $base nu se mai ia din antetul Host: canonical, og:url, datele structurate și sitemap-ul arată întotdeauna spre adresa fără www, iar .htaccess mută www pe ea cu 301. Altfel magazinul exista la două adrese, fiecare declarându-se canonică. Probe: test-seo, blocul „www duce la aceeași adresă” — cererea se face cu node:http, fiindcă fetch din Node rescrie antetul Host.

- [x] **„Telefon public” și „E-mail public” din Setări nu schimbă nimic din ce vede un vizitator pe site**
  `Setari.dc.html:439` · *mediu*
  „Telefon public” și „E-mail public” din Setări erau citite doar de învelișul trimis motoarelor de căutare; pagina pe care o vede clientul le avea scrise în cod, în cinci locuri, inclusiv linkul de WhatsApp care alimentează toate cele cinci butoane. Site-ul se contrazicea singur: Google primea numărul nou, clientul suna la cel vechi. Acum pleacă prin GET /lists (rută publică), gp.contact() le expune ca gp.hq(), iar tel:, mailto: și wa.me se calculează din același număr. Probe: test-settings „telefonul de pe site e cel scris în Setări” + check-site-public, cinci verificări că nu mai există tel:/mailto:/număr în marcaj.

- [x] **Setarea „Prag minim implicit” nu e citită de nimeni, iar niciun panou nu poate adăuga un articol de depozit**
  `Setari.dc.html:441` · *mediu*
  „Prag minim implicit” nu era citit de nimeni: POST /stock/items lua (float) ($b['minQty'] ?? 0), deci un articol creat fără prag pornea de la 0 și nu ajungea niciodată în „Sub prag minim”, nici cu stocul pe zero. Acum ruta ia implicitul din Setări; pragul trimis explicit rămâne al lui. Probe: test-settings „articolul nou primește pragul din Setări”, „cu stocul pe zero, articolul e raportat ca fiind pe terminate”, „pragul trimis explicit bate implicitul”.

- [x] **Ștergerea cifrei din căsuța de cantitate scoate produsul din coș**
  `Site Public.dc.html:1246` · *mediu*
  Ștergerea cifrei din căsuța de cantitate nu mai scoate rândul din coș: cât timp se scrie, textul brut stă în `editKey`/`editText`, iar în coș rămâne cantitatea de dinainte; `setQty` se apelează doar peste un număr mai mare ca zero. Scos din coș se face cu „×" sau cu „−". Verificat în browser: căsuța golită, rândul rămâne, `gp-cart-v1` neatins, iar retastarea funcționează.

- [x] **Clientul care apasă de două ori „Trimite cererea” trimite două cereri identice**
  `Site Public.dc.html:1826` · *mediu*
  submitQuote se oprește din prima linie dacă o cerere e deja pornită. Semaforul stă pe instanță (this.__trimit), nu în stare: setState nu schimbă starea pe loc, deci două apăsări grăbite — sau Enter apăsat de două ori în formular — citeau amândouă „sending: false” și plecau amândouă, deși butonul apărea stins. La un refuz semaforul se stinge, ca cererea completată să poată fi trimisă din nou după corectură. Probe în .claude/check-site-public.mjs: pagina e rulată cu un setState asincron, ca în React, „Trimite cererea” apăsat de două-trei ori într-o singură tură de buclă, iar spre server pleacă o singură cerere.

- [x] **Pagina de start cere de la server chiar textul legăturii, nu poza — patru 404 la fiecare vizită**
  `Site Public.dc.html:213` · *mediu*
  image-slot.js: `const srcAttr = srcBrut.includes('{{') ? '' : srcBrut;` — o legătură încă nerezolvată e tratată ca adresă lipsă (se arată substituentul), iar randarea de după aduce poza adevărată. Petec local, de repus după un reimport din Claude Design. Probă: test-defect-404-start.

- [x] **Produsul adăugat fără poză primește o cale ghicită, care nu există niciodată**
  `Site Public.dc.html:1214` · *mediu*
  `photo: r[10] ? res('prod' + r[1], r[10]) : ''` — gol înseamnă gol, deci se arată substituentul fără nicio cerere la server. Probă: test-defect-404-start.

- [x] **Site-ul primește o zi din trecut și mulțumește clientului, dar cererea nu poate fi acceptată niciodată**
  `Site Public.dc.html:833` · *mediu*
  

- [x] **Migrarea 003 rescrie necondiționat zonele de livrare și șterge zonele adăugate din Setări**
  `sql/003_route_order.sql:82` · *mediu*
  `UPDATE settings SET value = …` a devenit `INSERT IGNORE`: migrarea pune zonele implicite doar dacă lipsesc. Reimportarea ei nu mai șterge zonele adăugate din Setări.

- [x] **Rescrierea interceptorului de navigare schimbă înțelesul condiției: `!(A && B)` devine `!A && !B`**
  `.claude/build-deploy.mjs:294` · *mic*
  Rescrierea interceptorului de legături punea expresia fără paranteze înăuntrul unui !( ), deci negația se lipea doar de primul test și condiția ieșea pe dos: tocmai legăturile absolute între subdomenii erau oprite și duse mai departe cu șirul de interogare lipit la coadă. Probă: check-deploy aplică rescrierea pe linia adevărată din fiecare pagină publicată și evaluează condiția rezultată cu vm, pe șapte feluri de adresă.

- [x] **Cele trei subdomenii de pagini se publică fără `Options -Indexes`: folderele fără index se pot răsfoi**
  `.claude/build-deploy.mjs:196` · *mic*
  Reprodus prin numărare pe pachetul generat: `Options -Indexes` apărea doar în `deploy/api/.htaccess` (1), iar în `deploy/public_html`, `deploy/clienti`, `deploy/lab` de 0 ori. Am mutat interdicţia în `HT_COMMON`, blocul din care se naşte .htaccess-ul tuturor celor trei subdomenii de pagini (`HT_COMMON + (host === 'root' ? HT_SEO : HT_NOINDEX)`), pusă înaintea blocului „fişiere care nu au ce căuta în browser", cu un c

- [x] **Proba pentru „app.html cerut direct" acceptă chiar rezultatul pe care ar trebui să-l refuze**
  `.claude/test-seo.mjs:257` · *mic*
  `check('app.html cerut direct nu servește o pagină fără antet', direct.status === 200 || direct.status === 301, …)` accepta 200-ul, adică chiar rezultatul refuzat de nume. Reprodus: `curl -o /dev/null -w %{http_code} http://127.0.0.1:8101/app.html` → 200, iar corpul n-are nici `<title>`, nici `<link rel="canonical">`.

- [x] **API-ul își anunță versiunea de PHP în fiecare răspuns**
  `api/index.php:62` · *mic*
  header_remove('X-Powered-By') în api/index.php, ca să nu depindă de expose_php din configurarea serverului. Probă: test-api.mjs „răspunsul nu mai anunță versiunea de PHP" (plus „antetele de securitate sunt la locul lor", ca să nu se piardă celelalte).

- [x] **Un utilizator mai lung de 64 de caractere la autentificare dă 500 în loc de „Utilizator sau parolă greșită”**
  `api/lib/auth.php:161` · *mic*
  record_attempt() taie numele la 64 de caractere, cât are coloana. Orice text lipit în câmpul „Utilizator" ajunge acum la mesajul obișnuit de credențiale greșite, nu la „Eroare internă.", iar încercarea se numără mai departe la limitare. Probe: test-api.mjs „utilizator de 300 de caractere → 401, nu 500", „mesajul e cel obișnuit, nu unul de defecțiune" și varianta cu diacritice.

- [x] **must_change_password nu e impus pe server, iar pagina care îl cere are pe ea butonul de ocolire**
  `api/lib/auth.php:33` · *mic*
  api/lib/auth.php — trei schimbări, toate în fișierul meu: 1. Linia 33, în SELECT-ul din current_user(): am adăugat `u.is_system` lângă `u.must_change_password`, ca regula de mai jos să poată scuti conturile de sistem. 2.

- [x] **O listă trimisă ca tablou JSON este validată corect, dar salvată ca textul „Array”**
  `api/lib/settings.php:238` · *mic*
  settings_save() encodează acum tabloul cu json_encode(JSON_UNESCAPED_UNICODE) în loc de (string), deci o listă trimisă ca tablou JSON — curl, Postman, un script de import — ajunge în baza de date ca listă, nu ca textul „Array”. Probă în .claude/test-settings.mjs: delivery_slots trimis ca tablou se regăsește identic în /lists.

- [x] **Două conturi pot avea același e-mail, iar „am uitat parola” schimbă parola altui cont**
  `api/routes/auth.php:194` · *mic*
  Reprodus întâi, cu două conturi de client care au primit aceeași adresă (comenzi@firma.ro): contul „repro-sud" (id 730) a cerut parolă nouă pe adresă, iar tokenul s-a scris în `password_resets` pe user_id 729 = „repro-nord" — contul care nu ceruse nimic. Reparație în `route_forgot`: interogarea unică `WHERE (username = ? OR email = ?) ... LIMIT 1` (fără ORDER BY) a fost desfăcută în două.

- [x] **Setarea photo_dir mută fișierul, dar adresa scrisă în baza de date rămâne mereu „products/…”**
  `api/routes/catalog.php:375` · *mic*
  Adresa scrisă în baza de date și ștergerea pozei vechi se iau acum din chiar folderul configurat (photo_web_prefix() / is_uploaded_photo()), nu din textul fix „products/". Probe: test-php.php, secțiunea „unde ajung fotografiile produselor" — șapte probe, printre care „adresa web urmează folderul mutat" și „nici o cale care încearcă să iasă din folder".

- [x] **Crearea comenzii așteaptă notificările: cu furnizorul picat, răspunsul întârzie ~30 s sau moare**
  `api/routes/orders.php:203` · *mic*
  POST /orders trimite acum răspunsul înaintea anunțurilor, exact ca ruta publică deja reparată (api/routes/requests.php:191). Am înlocuit `json_out(['order' => $order], 201);` de după notify_new_order cu `json_out_early(['order' => $order], 201);` PUS ÎNAINTE de notificări, iar după blocul try/catch al notificărilor am pus `exit;` — altfel dispecerul din api/index.php ar mai scrie octeți peste un răspuns deja trimis.

- [x] **Între 00:00 și 03:00 ora României serverul scrie și citește ziua de ieri (gmdate în loc de ora locală)**
  `api/routes/orders.php:103` · *mic*
  

- [x] **O listă cu valori imbricate trece de validare și ajunge în baza de date ca textul „Array”**
  `api/routes/settings.php:158` · *mic*
  validate_lists() respinge acum cu 422 orice element de listă care nu e text simplu, cu numele cheii în mesaj, iar setting_list() sare peste el la citire, pentru rândurile scrise înainte de verificare. Probe în .claude/test-settings.mjs: un tablou imbricat și un obiect strecurate în delivery_slots sunt refuzate, iar intervalele salvate rămân neatinse — niciunul nu devine „Array”.

- [x] **company_email (contactul public) nu e validat la salvare, deși adresele de notificare alăturate sunt**
  `api/routes/settings.php:89` · *mic*
  Reprodus întâi pe serverul viu: PUT /settings {company_email:"contact@goldenpastry"} -> 200, iar GET /lists (rută publică) întorcea contact.email = "contact@goldenpastry"; controlul pe câmpul vecin, notify_email_to cu aceeași adresă, dădea 422 bad_email. Am pus adresa la loc imediat.

- [x] **Filtrul de zi al registrului de depozit taie după data UTC, dar paginile trimit data locală**
  `api/routes/stock.php:151` · *mic*
  Am mutat filtrul de zi al registrului de pe „data UTC" pe „intervalul de clipe al zilei locale". Concret, în api/routes/stock.php: 1) Funcția nouă `stock_local_day_utc(string $day, int $plusZile = 0): string`, pusă la începutul secțiunii „mișcări": construiește `new DateTimeImmutable($day .

- [x] **Doi care adaugă același articol de depozit (sau același cont) în aceeași clipă: „Eroare BD" 500 în loc de refuzul lămurit**
  `api/routes/stock.php:70` · *mic*
  Ajutorul `q_unique()` (api/lib/db.php) prinde chiar căderea pe indexul unic și o întoarce în refuzul pe care cererea l-ar fi primit dacă venea o clipă mai târziu: 409 `name_taken` la articolele de depozit (creare și redenumire) și 409 `username_taken` la conturi. Verificarea din memorie rămâne unde era — ea dă mesajul bun în cazul obișnuit; asta e a doua poartă, cea pe care o ține baza, pentru clipa dintre „SELECT 1 … WHERE name = ?" și INSERT. Se prinde numai 1062 (rând duplicat): restul lui 23000, o cheie străină ruptă de pildă, e o defecțiune adevărată și trebuie să rămână una. Probă: grupa a patra din .claude/test-reparat-depozit-refuzuri.mjs — ciocnirea nu se poate stârni prin `php -S`, care are un singur proces și așază cererile la rând, deci se cere direct de la bază, prin .claude/proba-unicitate.php. Dată înapoi reparația, proba arată exact dovada din fișă: „1062 Duplicate entry … for key 'stock_items.uq_stock_items_name'".

- [x] **Steagul „trebuie să-și schimbe parola" se pune, dar nu-l citește nimeni niciodată**
  `api/routes/users.php:172` · *mic*
  must_change_password se întoarce acum în /users (mustChangePassword) și e citit de Conturi (badge „PAROLĂ PROVIZORIE”), de Client (banner) și de Intrare (redirect la prima intrare). Probe: test-parola „după resetarea făcută de administrator, contul e marcat cu parolă provizorie”, „o autentificare nu trece drept schimbare de parolă”; check-panouri „pagina citește de la server steagul de parolă provizorie”, „panoul clientului arată singur că are parolă provizorie”.

- [x] **Pagina de verificare a instalării numără 14 tabele din 15 și raportează „toate prezente"**
  `api/verifica.php:180` · *mic*
  api/verifica.php — am adăugat `product_categories` în lista `$need`, care are acum 15 nume, exact câte tabele creează sql/001_schema.sql (numărate: users, sessions, password_resets, product_categories, products, order_requests, order_request_items, orders, order_items, stock_items, stock_moves, audit_log, notifications, settings, login_attempts).

- [x] **Reîmprospătarea din pagina clientului nu se oprește niciodată și nu are pază la suprapunere**
  `Client.dc.html:302` · *mic*
  Pagina folosește gp.poll(), care se oprește când fila e ascunsă și reia la revenire; gp.poll() a primit și o pază la suprapunere, deci o tură care nu s-a întors nu lasă alta să plece — de ea beneficiază orice pagină. Probe: .claude/check-panouri.mjs rulează chiar gp.poll() cu o tură mai lentă decât intervalul și verifică atât că nu se suprapun, cât și că fila ascunsă nu mai cere nimic.

- [x] **Caseta cere „minim 6 caractere", serverul refuză sub 8, iar butonul „Propune o parolă" poate genera una de 7**
  `Conturi.dc.html:425` · *mic*
  Pragul paginii nu mai poate coborî sub cele 8 caractere ale serverului (minLen() dă Math.max(8, …), propul are acum implicit 8 și minim 8), parola propusă e construită ca să treacă de prag oricât ar fi el, iar bannerul de sus își ia culoarea din stare — un refuz nu mai e desenat verde, ca o confirmare. Probe: .claude/check-panouri.mjs, care scoate minLen() și suggest() din sursă și le rulează (2400 de parole propuse), plus probele pe banner.

- [x] **Codul panourilor: pagina cere minim 4 caractere, serverul refuză sub 6**
  `Conturi.dc.html:633` · *mic*
  Caseta cere acum minim 6 caractere, cât cere și serverul — și în text, și în verificarea dinaintea trimiterii. Înainte, un cod de 4–5 caractere trecea de pagină și se lovea de server cu o eroare roșie. Cardul de deasupra trece pe „••••••••” imediat după salvare, nu rămâne pe „neconfigurat”. Verificat în browser.

- [x] **Butonul de corecție al unei mișcări poate fi apăsat de oricâte ori, generând corecții repetate**
  `Depozit.dc.html:726` · *mic*
  O mișcare deja corectată își spune starea în locul butonului: a doua corecție nu mai poate fi scrisă.

- [x] **Catalogul cere de la server două adrese literale „{{ … }}" la fiecare deschidere**
  `Produse.dc.html:140` · *mic*
  Miniaturile din Catalog au primit loading="lazy" — nu pentru viteză, ci ca parserul să nu ceară de la server chiar textul cu acolade al legăturii, înainte ca runtime-ul s-o înlocuiască. Erau două 404-uri la fiecare deschidere a paginii, în jurnalul găzduirii și în consolă.

- [x] **Ruta șoferului scrie mereu că ordinea opririlor vine din administrare, chiar când nu e stabilită**
  `Ruta Sofer.dc.html:428` · *mic*
  Nota de sub listă spune adevărul: ordinea din administrare se anunță doar când chiar există.

- [x] **Adresa publică din meta description și din <noscript> vine din `hq_label`, eticheta primei opriri de pe rută**
  `seo/index.php:121` · *mic*
  Reprodus pe pachetul servit la :8101: <meta name="description" content="... Laborator propriu în București, Depozit · Șos. Olteniței 103. Comenzi la +40769493163."> — adică eticheta primei opriri de pe rută, nu adresa firmei.

- [x] **„Numele firmei” din Setări nu apare în antetul mesajelor, așa cum promite textul de sub câmp**
  `Setari.dc.html:438` · *mic*
  Numele firmei din Setări ajunge acum în antetul From al e-mailurilor, prin email_from_name(), în loc să fie scris în cod. Numele se codifică RFC 2047 dacă are diacritice — un antet cu diacritice brute poate fi refuzat de server, adică anunțul comenzii noi să nu mai ajungă la laborator — și i se taie orice salt de linie, care ar fi putut adăuga un Bcc. Probe: test-php, cinci verificări pe funcția scoasă din sursă.

- [x] **Câmpul pentru codul tabletelor sugerează chiar codul implicit pus de instalator, „lab123”**
  `Setari.dc.html:856` · *mic*
  Sugestia din câmpul gol nu mai e un cod care chiar funcționa, ci regula: „Minim 6 caractere, greu de ghicit”. Verificat în browser, cu codul șters din Setări. Probă: .claude/check-secrets.mjs — vechea valoare e acum un tipar căutat în toate paginile care ajung în browser.

- [x] **Telefonul e obligatoriu pe server, dar formularul nu-l cere**
  `Site Public.dc.html:796` · *mic*
  Câmpul de telefon primește `required` când vizitatorul nu are cont, iar eticheta devine „Telefon (obligatoriu)" — exact regula pe care o aplică serverul. Cu cont, telefonul rămâne opțional, ca și pe server. Verificat în browser, deconectat: `el.required === true`.

- [x] **Textul de sub hartă rămâne în română pe versiunea în engleză**
  `Site Public.dc.html:590` · *mic*
  Textele rămase în română pe versiunea în engleză trec prin `t()`: subtitlul din antet (`logoKicker`), titlul hărții (`mapTitle`) și legenda ei (`mapCaption`). Verificat în browser pe EN: „Artisan pastry", „Warehouse — ICTCM site, Șoseaua Olteniței 103", „ICTCM site · Șos. Olteniței 103, district 4 · opposite Piața Sudului metro station".

- [x] **Bifa „Trimiteți-mi și catalogul PDF complet" nu se poate debifa**
  `Site Public.dc.html:842` · *mediu*
  Căsuța era legată în marcaj direct la `checked="{{ true }}"`, fără `onChange` și fără nicio stare în spate — singurul `checked=` din tot codul fără pereche. Runtime-ul readuce sincron valoarea din props după fiecare apăsare, deci bifa nici nu clipea, iar `submitQuote` o citea din `FormData`: pe server pleca „vrea catalogul" la fiecare cerere, inclusiv de la cine tocmai o refuzase. Acum e stare adevărată (`wantCatalog` + `toggleCatalog`), citită din stare la trimitere, cu aceeași condiție pe cont ca marcajul (`asGuest`) — clientul conectat nu vede căsuța, deci n-are voie s-o trimită. Probe: check-site-public, cinci verificări. Dată înapoi reparația, pică 2.

- [x] **Pe telefon, degetul pus pe o poză nu mai derulează pagina**
  `image-slot.js:300` · *grav*
  `touch-action:none` stătea pe regula `.frame img`, necondiționat, injectat în fiecare shadow root — inclusiv pe site-ul servit clienților. Pe catalogul public poza e o bandă pe toată înălțimea fiecărui card de produs, deci lista întreagă era moartă la atingere: vizitatorul trăgea în jos și pagina stătea pe loc. Nu era carusel și nu era handler JS — pan-ul și redimensionarea pornesc de pe `.spill`, care își are propriul `touch-action`, deci regula n-a apărat niciodată vreun gest. Acum se aplică doar la reîncadrare (`:host([data-reframe])`). Probă: .claude/check-poze.mjs, fișier nou.

- [x] **Un vizitator care atinge un produs fără poză își deschide selectorul de fișiere; o poză trasă peste site o înlocuiește**
  `image-slot.js:571, :857` · *mediu*
  Doi ascultători din modul de editare rămăseseră fără poarta `data-editable`, care închide corect restul (pan, zoom, reîncadrare, controale): clicul pe slotul gol chema `_input.click()`, iar `handleEvent` făcea `preventDefault()` pe `dragover`/`drop`, adică lua browserului gestul și punea poza trasă în locul celei de pe ecran. Amândoi primesc acum aceeași poartă; ascultătorii de drag rămân atașați, ca simetria add/remove și contorul de adâncime să nu se strice. Probă: aceeași, check-poze.

- [x] **Clientul nu află niciodată de ce i s-a respins cererea**
  `Client.dc.html:385` · *mediu*
  Serverul salvează motivul de când există butonul de refuz (`order_requests.reject_reason`) și îl întoarce în `request_public`, dar pagina clientului cerea doar `?status=nou`: cererea dispărea de pe ecran fără nicio explicație, iar omul suna să întrebe ce s-a întâmplat. Acum cere `?status=nou,respinsa` — filtrul pe cont rămâne pe server, unde era — și le arată într-un cartonaș propriu, cu motivul dedesubt; un refuz fără motiv scris spune asta pe față, în loc să lase un rând gol. Refuzul nu se amestecă printre cererile în așteptare și nu face pagina să pretindă că omul are ceva pe drum. Serverul nu s-a atins. Probe: check-panouri (7 verificări, pagina rulată cu logica ei adevărată) și test-defect-pagini-client (3 verificări vii: cererea se respinge din admin și se citește înapoi din contul clientului). Dată înapoi reparația, pică 2 dintre cele vii.

- [x] **Pachetul de test în coș face să dispară promptul „comandă minimă 700 RON"**
  `Site Public.dc.html:1351` · *mediu*
  Pachetul intra în total la preț de listă, deși clientul plătește net, cu −20%. O singură variabilă, `totMoney`, răspundea la două întrebări diferite — „cât scrie pe ecran și cât pleacă în ofertă?" și „comanda se califică la livrare gratuită?" — iar pragul se compara cu cifra greșită: cardul se făcea verde „Comandă minimă atinsă" pentru un coș care nu atingea minimul. Comentariul care ține totalul la preț de listă e corect și rămâne cum e (API-ul n-are noțiunea de reducere, deci ecranul și oferta trebuie să spună aceeași cifră); s-a adăugat a doua sumă, `totNet`, și numai ea decide pragul. Fiindcă acum ecranul arată două cifre, spune și de ce: „La prag socotim 658,77 RON, nu 700,20 RON: reducerea de 20% a pachetului de test e deja scăzută" — în ambele limbi, și pe card, și în textul ofertei trimise pe WhatsApp. Verificat în browser: coș de 700,20 la listă și 658,77 net, cardul rămâne gri, „mai adaugă 41,23 RON". Probe: check-site-public, 13 verificări, cu aritmetica scoasă din pagină și rulată, nu rescrisă. Dată înapoi reparația, pică 5.

- [x] **Al doilea pachet de test ar fi plecat gratis**
  `Site Public.dc.html:1292` · *mic*
  În aceeași ramură: cantitatea din coș era citită, dar bucățile, banii și rândul de pe ecran o ignorau — pachetul intra mereu ca și cum ar fi fost unul singur. Azi butonul pune întotdeauna 1, deci nu se vedea; nimic din cod nu impunea însă asta. Toate cifrele se înmulțesc acum cu cantitatea, iar eticheta rândului respectă pluralul românesc („1 pachet", „2 pachete", „20 de pachete"). Probe: aceleași, 3 verificări pe cazul cu două pachete.

- [x] **Probele lăsau în urmă sute de comenzi în baza de dezvoltare; una a fost raportată ca defect al aplicației**
  `.claude/curata-probe.mjs` · *mediu*
  „Curier fx2ep, ce înseamnă?" — un mod de onorare botezat `'Curier ' + random` de `test-settings`, cu două comenzi pe rulare, niciuna înregistrată la curățenie. Numărate, erau **697 de rânduri** din nouă nume neînregistrate, nu unul: comenzi și cereri de la opt fișiere de probă diferite. Producția pornește pe bază curată, deci nu ajunge pe server — se repară unealta, nu aplicația. Numele s-au înregistrat (plus cele vechi, ca bazele existente să se cureţe), iar proba de onorare se cheamă acum „Probă Onorare …" și își restaurează lista din `finally`, ca o picare la mijloc să nu lase setarea murdară. **Reparația care contează e alta:** curățenia nu se mai bazează pe o listă ținută minte de om. Culege numele chiar din fișierele probelor și întreabă baza ce a supraviețuit; ce rămâne se raportează pe nume, cu fișierul din care vine, iar `verifica-tot` numără asta ca eșec — până acum ieșirea curățeniei era ignorată. La prima rulare a găsit singură un al nouălea nume, în tabelul de cereri, pe care nu-l văzusem.

- [x] **Culoarea insignei de mod de onorare era o hartă cu trei nume scrise în cod**
  `Administrare Comenzi.dc.html:899`, `Panou Laborator.dc.html:252` · *mic*
  „Ridicare/Livrare/Angro", scrise în două pagini, într-o aplicație în care modurile de onorare se editează din Setări: un mod nou, legitim, cădea pe insigna neutră și rămânea gri pe vecie. Culoarea se derivă acum din lista după care serverul decide oricum ce urcă în mașină (`fulfillment_pickup`), expusă în `/lists` și servită de `gp.pickupFulfillments()`. Fără lista de la server insigna rămâne neutră — „nu știu" e un răspuns onest, o culoare ghicită nu. Probe: check-panouri, 22 de verificări, regula scoasă din fiecare pagină și rulată cu liste de moduri făcute anume.

- [x] **Șoferul vede comenzile zilei, dar nu și care sunt gata de urcat în mașină**
  `Ruta Sofer.dc.html:427` · *mediu*
  Serverul filtra deja opririle după modul de onorare, nu după etapă, deci comenzile apăreau toate — dar singurul semn de etapă din pagină era „livrată sau nu". Șoferul suna în laborator la fiecare tură. Fiecare oprire nelivrată poartă acum „ÎN LUCRU" sau „GATA", derivat din lista de etape din Setări: „gata" înseamnă că s-a ajuns la etapa dinaintea celei care încheie comanda, sau mai departe. Dacă patiseria vrea o etapă explicită „Gata de livrare", o adaugă în Setări și insigna o urmează, fără cod nou. **Nu** e „penultima din listă": `guard_status_list` impune doar ca etapele în uz să nu fie scoase, nu că etapa de încheiere e ultima — poziția se ia față de `gp.done()`. Etapă necunoscută sau listă fără etapa de încheiere → nicio insignă: o insignă lipsă e o întrebare, una greșită e un drum degeaba. Pe oprirea livrată insigna tace, fiindcă antetul verde și „✓" din bulină o spun deja. Verificat în browser, cu trei comenzi în etape diferite. Probe: check-panouri, 15 verificări; cea pe lista reordonată pică pe scurtătura „flow.length − 2".

- [x] **Un articol de depozit nu se mai putea lega de un produs după ce era creat**
  `api/routes/stock.php:135` · *grav*
  Crearea accepta `productId`; modificarea, nu — iar comentariul de la creare recunoștea singur unde duce asta: „nicio rută nu scrie `product_id` pe un articol existent, deci produsul ar rămâne nelegabil pentru totdeauna". Depozitele reale se umplu înainte ca produsele să existe în catalog, iar un articol legat din greșeală de alt produs nu se mai putea desprinde: singura cale era un articol nou, cu istoricul rupt în două, exact lucrul pe care `409 product_taken` îl oprește la creare. Cererea nici nu ieșea ca refuz lămurit — `productId` nu ajungea în lista de câmpuri, deci răspunsul era „Nimic de modificat". `PATCH /stock/items` primește acum `productId`, cu **aceleași** verificări ca la creare (`422 bad_product`, `409 product_taken`) plus `id <> ?`, ca articolul deja legat să nu se refuze pe sine la a doua apăsare pe „Salvează"; gol înseamnă dezlegat. Fără asta, rețetele n-ar fi avut pe ce lucra: fiecare produs vândut are nevoie de articolul lui finit. Probe: test-reteta-productie, 10 verificări — 9 pică dacă ramura e scoasă.


- [x] **„Rezervat" se socotea în browser, din cel mult 500 de comenzi: peste atâtea, cifra devenea tăcut greșită**
  `Depozit.dc.html:759` · *mediu*
  Rândul de stoc își aduna singur promisiunile, parcurgând comenzile ajunse în pagină. Dar `GET /orders` întoarce cel mult 500 de comenzi (`api/routes/orders.php:57`), iar pagina nu avea de unde să știe că lista e tăiată: nicio etichetă, niciun „…și altele". Peste pragul acela „Rezervat" scădea, „Disponibil" creștea, iar depozitarul cumpăra materie primă după o cifră care arăta mai multă marfă liberă decât era — **exact în patiseriile cu destule comenzi cât să conteze**. Defectul anterior de pe același rând (potrivirea pe nume în loc de `product_id`) fusese reparat corect, dar pe un teren care nu ținea. Socoteala a plecat pe server: rezervările se scriu în `stock_reservations` la nașterea poziției — toate trei locurile care scriu `order_items`, inclusiv acceptarea unei cereri, care are INSERT propriu — iar `GET /stock/items` întoarce `reserved` și `available` dintr-un agregat filtrat pe statusul comenzii. Din filtrul acela ies gratis toate cele trei purtări de care altfel ar fi avut nevoie cod separat: eliberarea la „Predată", redeschiderea dacă iese din „Predată" și eliberarea la anulare. Cele două agregări (registru și rezervări) stau în subinterogări separate, altfel fiecare mișcare s-ar înmulți cu fiecare rezervare și stocul ar crește cu numărul comenzilor deschise. `reserved()` s-a **șters** din pagină, nu s-a dublat. Probe: test-reteta-productie, 21 de verificări (11 pică dacă joinul e scos, una dacă se uită apelul din acceptarea cererii) și check-panouri + test-defect-depozit, 9 verificări care pică dacă socoteala se întoarce în browser.

- [x] **Pagina de administrare te băga înăuntru și te dădea imediat afară, iar formularul ei de autentificare nu putea fi folosit niciodată**
  `Administrare Comenzi.dc.html:894` · *grav*
  Apeși „Administrare", pagina se deschide, iar o clipă mai târziu ești înapoi la pagina de intrare. Cauza e ordinea, nu redirectul: `pullRequests()` pleca din `componentDidMount` **necondiționat**, înaintea lui `me()`. Fără sesiune lua 401, iar din `catch` trimitea omul afară — inclusiv atunci când pagina tocmai își desenase propriul formular de autentificare, care dispărea astfel sub degete, împins de o cerere pornită înaintea lui. Formularul acela era, prin construcție, cod mort: nimeni n-a apucat vreodată să scrie în el. Aceeași cursă, mai lentă, stătea în ceasul de 4 secunde și în ascultătorul dintre file, pornite tot din montare — pentru un client oprit la ecranul de refuz însemna cereri duse degeaba la nesfârșit, în spatele unui ecran care tocmai îi spusese că n-are ce căuta acolo.
  Reparația nu e o pază pusă pe redirect, ci scoaterea dublurii. Formularul era copiat în patru pagini, iar unul copiat în patru locuri nu-l repară nimeni în toate patru. A rămas unul singur, `Intrare.dc.html` — serverul avea oricum o singură rută `POST /auth/login`, un singur cookie de sesiune și rolurile hotărâte pe server; ce era duplicat era doar formularul. Panourile pleacă acum prin `gp.toLogin()`, care lasă în adresă pagina din care s-au ridicat (`?next=`), iar după autentificare omul se întoarce exact unde voia, nu într-o pagină implicită. Cât ține întrebarea către server nu se mai afirmă nimic: ecranul spune „Se verifică sesiunea…", fiindcă nici pagina nu știe încă. Iar serverul mut nu se mai confundă cu sesiunea expirată — acolo se oferă „Încearcă din nou", nu un formular de parolă la care omul ar scrie degeaba. `Client.dc.html` a rămas neatins, deliberat: își are formularul lui, nu trimite pe nimeni nicăieri și e singura pagină pe care o vede un client din afară.
  **`?next=` vine din bara de adrese, deci de la oricine trimite un link.** Netratat, e tiparul numit „redirect deschis": un link `…/Intrare.dc.html?next=https://…` duce omul, imediat DUPĂ ce și-a scris parola, într-o pagină străină care arată la fel — cel mai ieftin mod de a lua o parolă de la cineva care tocmai a avut încredere. Nu se ține o listă de pagini permise, fiindcă ar rămâne în urmă la fiecare pagină nouă; se cere **forma**: se decodează întâi, ca `%2F` să nu treacă pe lângă verificare, apoi se refuză orice conține „/", „\" sau „:" și se cere un nume `*.dc.html`. Fără cele trei caractere nu se poate scrie nici adresă absolută, nici de rețea, nici ieșire din folder. O valoare refuzată se **șterge** din adresă, nu doar se ignoră: altfel „https://…" ar rămâne agățat de bara de adrese a patiseriei mult după ce a fost aruncat și ar fi cărat mai departe de fiecare legătură din pagină.
  **Capcana prinsă înainte de urcare, nu după.** Prima variantă a reparației mergea perfect local și ar fi tăcut pe serverul adevărat, din două motive deodată. Numele paginilor se schimbă la construirea pachetului — în dezvoltare `Depozit.dc.html`, pe server `/depozit` — deci o regulă scrisă pe „.dc.html" nu recunoștea nimic acolo și `?next=` n-ar fi fost pus niciodată: întoarcerea, adică tot ce s-a cerut aici, ar fi funcționat doar pe calculatorul programatorului. Iar `shared/gp-api.js` nu trecea prin rescrierea de legături, fiindcă până acum doar paginile aveau legături: trimiterea către autentificare pleca în pachet ca `Intrare.dc.html`, un 404 pe `lab.goldenpastry.ro`. Amândouă sunt reparate — regula întreabă acum **forma** (o singură bucată de cale, fără „/", „\" sau „:"), iar `shared/` se rescrie ca paginile. **Reparația care ține însă e a treia:** construirea pachetului citește la final tot ce a ieșit în folderele din care navighează browserul și se **oprește** dacă găsește vreun nume din dezvoltare rămas, oriunde ar fi. Lista fișierelor de rescris nu mai trebuie ținută minte de nimeni. Verificat pe pachetul construit, servit local: `/lab/depozit` fără sesiune → `/lab/?next=depozit` → după autentificare, înapoi la `/lab/depozit`.
  Ieșit la iveală pe drum: propoziția de refuz îi spunea unui client „contul tău e cont de angajat… folosește panourile de lucru de mai jos", deși sub ea îi apărea butonul „Vezi comanda mea" — textul și butonul se contraziceau pe același ecran. Se desparte acum pe aceeași întrebare pe care o puneau deja butoanele.
  Verificat în browser, pe fiecare drum: fără sesiune → întoarcere la pagina cerută după autentificare, și pentru Administrare și pentru Conturi; cont de client → ecranul de refuz, care rămâne pe loc; `?next=https://example.com/phish` și `?next=//evil.tld` → refuzate și scoase din adresă. Probe: check-panouri, 47 de verificări, cu verificarea de formă și `loginHref()` scoase din sursă și rulate — o expresie regulată prost scrisă arată la fel de convingător ca una bună. Fișa de revenire: zece reparații stricate pe rând, fiecare prinsă — verificarea de formă → 12 probe pică; urma de întoarcere din `loginHref` → 5; întâietatea paginii cerute → 2; câte una pentru cererea pornită înaintea sesiunii, ceasul pornit din montare, trimiterea scrisă de mână, ștergerea unui „next" refuzat, regula întoarsă pe „.dc.html" și propoziția de refuz; iar rescrierea scoasă din `shared/` **oprește construirea pachetului**, singura dintre ele care nu se vede în probele de pagină.

---

## Ce am respins

Ca lista să nu fie umflată — patru lucruri care păreau defecte și nu sunt:

- **Traversare de cale în rute.** `/orders/1/../../users` părea să dea 200, dar
  era browserul care normaliza adresa înainte s-o trimită. Cerut cu
  `curl --path-as-is`, serverul dă 404.
- **`env => 'dev'` implicit în configurare.** E documentat în pașii de
  publicare *și* verificat de `api/verifica.php`.
- **Paginarea Jurnalului.** `page=-5` și `page=99999999999` par nevalidate, dar
  `max(1, (int)…)` le prinde.
- **Încărcarea fotografiilor.** Respinge cod PHP deghizat în `.jpg` și nume de
  fișier cu `../`; nimic nu s-a scris în afara folderului.

---

## Cum a fost făcută

17 căutători, câte unul pe zonă — drepturi, sesiuni, comenzi, cereri, catalog,
depozit, setări, infrastructură, paginile, publicarea, plus trei transversale:
banii, timpul și matricea de drepturi.

Fiecare descoperire a trecut prin doi verificatori independenți: unul care
încerca s-o demonteze, unul care urmărea drumul datelor prin cod și prin baza de
date. Verificatorii n-au avut voie să scrie nimic — nici în bază, nici în
fișiere; reproducerile le-am făcut separat.

212 agenți, 2.294 de apeluri de unelte, 61 de minute.

O notă despre probele existente: **cele 297 de teste treceau toate** în timp ce
defectele astea erau în cod. Nu acopereau niciunul dintre ele — de-asta au și
trecut nevăzute. Fiecare reparație vine cu proba ei, iar proba se verifică
stricând reparația la loc: dacă reparația se dă înapoi și proba trece mai
departe, proba nu e bună. `node .claude/verifica-tot.mjs` rulează acum peste
nouă sute de probe.
