# Defecte găsite, de reparat

Inspecție făcută înainte de publicare, pe codul de atunci: 4.700 de linii de
PHP, 11.300 de linii de pagini, pachetul de publicare și baza de date. De
atunci codul a crescut de vreo două ori, iar valurile de mai jos l-au urmat.

**305 de defecte confirmate** — 9 critice, 97 grave, 132 medii, 67 mici.
**303 reparate**, 2 rămase.

Lista s-a strâns în cinci valuri. Inspecția dinaintea publicării a dat 102 fișe:
din 123 de descoperiri brute au rămas 97 după eliminarea dublurilor, fiecare
trecută prin doi verificatori care au încercat s-o demonteze. Alte cinci au ieșit
la iveală chiar în timpul reparațiilor. Următoarele 56 vin dintr-o a doua
vânătoare, pornită după ce suita trecea integral: șase agenți au citit codul pe
zone separate, fără să știe ce caută ceilalți, iar fiecare susținere a fost dată
unui sceptic care avea sarcina s-o dărâme — șase din douăzeci și trei au căzut
acolo. Tot atunci s-au adăugat cele găsite deschizând fiecare pagină în browser,
cu consola și rețeaua la vedere. Al patrulea val, 45 de fișe, vine dintr-o
vânătoare făcută peste noapte pe tot codul, cu aceeași regulă a scepticului: din
ea au rămas în picioare treizeci, niciuna cu vreun vot împotrivă. Restul de
97 s-au adunat pe măsură ce se făceau lucrările cerute de patron, iar
30 dintre ele sunt valul al cincilea: cititori adverși puși să citească nu
codul vechi, ci reparațiile scrise cu o zi înainte. Cele marcate **[reprodus]**
au fost declanșate cu mâna pe aplicația care rulează, nu doar citite din cod.

Aceeași listă, cu filtre și căutare:
<https://claude.ai/code/artifact/64a4ebba-42c9-4ed2-be1c-abba79f60d72>

| Gravitate | Reparate | Rămase | Total |
|---|---|---|---|
| Critice | 9 | 0 | 9 |
| Grave | 97 | 0 | 97 |
| Medii | 131 | 1 | 132 |
| Mici | 66 | 1 | 67 |

---

## Ce e reparat — 303

Fiecare cu proba lui de regresie: proba a fost verificată stricând
reparația la loc și văzând-o picând.

- [x] **„Salvează modificările" nu salvează clientul, adresa, referința și pozițiile comenzii**
  `Administrare Comenzi.dc.html:1158` · *critic*
  Formularul trimite acum toată comanda — client, telefon, adresă, referință, prioritate și pozițiile cu varianta fiecăreia — iar route_orders_update() le acceptă și rescrie pozițiile în aceeași tranzacție, cu totalul recalculat din catalog. Ziua nu se mai reconstruiește ghicind luna: formularul poartă data ISO întreagă. Probe: .claude/test-editare.mjs, secțiunea „PATCH /orders/{id}: se salvează toată comanda" (verificată stricând reparația la loc).

- [x] **Ograda de clienți se ocolea scriind numele în loc să alegi contul — pe comenzi, asta năștea comision și lega contul clientului de agent pentru totdeauna**
  `api/lib/orders.php:205` · *critic*
  Hotărârea patronului: „amândouă porțile", și „ograda numai pentru cine are una". Regula s-a scris O SINGURĂ DATĂ, ca `client_by_name_in_scope()` în `api/lib/orders.php` — nu în fișierele de rute, fiindcă routerul încarcă un singur fișier de rute pe cerere (api/index.php), iar o regulă scrisă de două ori se desparte într-o zi, tăcut, pe partea care deschide. Exact pățania de față: poarta pusă pe `customerId` nu fusese pusă și pe nume. Amândouă rutele o cheamă acum. DEOSEBIRE DE PURTARE, dinadins: pe NUMĂR se răspunde 403, fiindcă omul a ales un cont anume; pe NUME se TACE și cererea rămâne nelegată, ca de la un om fără cont — un refuz pe nume ar face din formular un instrument de ghicit clienții casei. A DOUA PORȚIE, pe cereri: `$alesAnume` nu mai înseamnă „a ales cu degetul", ci „s-a legat de un cont", deci codul din formular nu mai trece pe nicio cerere legată — ceea ce acoperă și administratorul, a cărui ogradă e largă și care deci trece de poarta dintâi fără să se oprească. CLIENTUL CARE COMANDĂ SINGUR RĂMÂNE PE DINAFARĂ: caseta lui e goală și deschisă tocmai când contul spune „nu l-a adus nimeni", iar codul scris atunci e singura lui mărturie despre cine l-a trimis. Și MANAGERUL rămâne cum era: `client_scope_for()` îi întoarce `null`, dar `null` acolo înseamnă „n-are unealta de căutat clienți", nu „n-are niciun client" — citit ca ogradă goală, l-ar fi lăsat fără singurul drum prin care comanda lui de la telefon ajunge în contul clientului, fiindcă el nici nu poate căuta (403 `client_search_denied`). Proba a prins și două defecte ale ei înseși în timpul scrierii: o regulă care nu putea pica niciodată, fiindcă decorul îi dăduse clientului un `referred_by` nenul, și un nume de regulă care conținea chiar despărțitorul după care dovada taie rândurile roșii.

- [x] **POST /orders nu cere autentificare: oricine poate băga comenzi direct în laborator, ocolind treapta de cerere**
  `api/routes/orders.php:84` · *critic*
  Ruta se deschide acum cu `require_staff()`. Un vizitator anonim primește 401, un client autentificat 403; comenzile directe rămân doar pentru panou. Probe: „comandă direct în laborator, fără cont → 401" și „nici clientul autentificat nu bagă comenzi direct → 403".

- [x] **Două apăsări simultane pe „Scade din stoc” scad aceleași comenzi de două ori**
  `api/routes/stock.php:246` · *critic*
  Lista comenzilor de scăzut se citește acum în tranzacție, cu FOR UPDATE, iar marcajul cere „stock_synced = 0”: a doua apăsare așteaptă și nu mai găsește nimic. Butonul din Depozit are și el o pază: nu pornește a doua cerere cât timp prima e în zbor. Probă: .claude/test-stoc-concurent.php (două conexiuni împletite manual, fiindcă php -S nu poate răspunde la două cereri odată pe Windows).

- [x] **Un administrator obișnuit poate reseta parola fondatorului și îi ia contul**
  `api/routes/users.php:468` · *critic*
  SELECT-ul citește și `role`, iar guard_leader_role('', $target['role']) se cheamă imediat după verificarea contului de sistem — exact ca la ștergere. Un administrator primește 403 founder_only pe contul unui fondator; conturile obișnuite rămân la îndemâna lui, ca înainte. Probe: .claude/test-fondator.mjs, secțiunea „a patra ușă: resetarea parolei" (măsurată dând reparația înapoi: fără linia aceea, resetarea răspunde 200 și fondatorul e scos din sesiune).

- [x] **Un al doilea client cu același nume rupea legarea comenzilor luate la telefon — și nu numai lui, ci ȘI celui vechi**
  `api/routes/users.php:466` · *critic*
  Hotărârea patronului: „Refuz la naștere". Regula s-a scris O SINGURĂ DATĂ, ca `client_name_conflict()` în `api/lib/orders.php:148`, lângă `client_by_name()` a cărei geamănă e — nu în fișierul de rute, fiindcă routerul încarcă un singur fișier de rute pe cerere (`api/index.php`), iar o regulă scrisă de patru ori s-ar desparte într-o zi, tăcut, pe ușa care deschide. Interogarea de pază e copia literală a celei pe care o apără, poartă cu poartă: același `role = 'client' AND active = 1`, același `TRIM(COALESCE(NULLIF(customer_name, ''), name))`, aceeași poartă de recunoaștere — altfel ar refuza nume care n-ar fi stricat nimic. Numele întreg se socotește tot într-un singur loc, `client_display_name()`. POARTA STĂ LA NAȘTEREA NUMELUI, nu la folosirea lui: refuzată acolo, încurcătura nu apucă să existe; refuzată la scrierea comenzii, ar fi lovit tocmai clientul nevinovat, care n-are ce face cu ea. Nu se atinge de conturile deja făcute — regula e „nu înrăutăți", nu „respinge trecutul": perechile rămase din trecut se pot încă îndrepta, și pe alt câmp, și prin deosebirea numelui. MESAJUL SE ÎMPARTE: administratorul află pe ce cont stă numele (are lista de conturi și trebuie să știe pe ce rând să umble), agentul nu (n-are `GET /users`, deci numele contului străin ar fi pentru el o informație scoasă cu o cerere — același tipar ca la adresa de e-mail). Probe: 22 de reguli noi în `.claude/test-clienti.mjs` (41 → 63), fiecare dovedită că se înroșește pe codul stricat cu douăsprezece stricăciuni țintite, inclusiv una care nu schimbă purtarea deloc — mută regula sub alt nume — și trebuie să înroșească exact cele patru reguli statice, nimic altceva.

- [x] **Codul panoului este „lab123" — o valoare fixă, pusă de instalator și scrisă în clar într-o pagină publică**
  `api/setup.php:73` · *critic*
  Instalatorul generează acum un cod aleator de 8 caractere (random_panel_code() din api/lib/crypto.php, alfabet fără i/l/1/o/0) și îl arată o singură dată, pe pagina de final. Nu se mai păstrează nicăieri în clar. Literalul a dispărut și din Conturi.dc.html și Setari.dc.html, adică din paginile publicate ca fișiere statice pe lab.goldenpastry.ro. Probe: .claude/test-instalare.php (instalare pe o bază goală: codul afișat se decriptează exact din baza de date, a doua instalare primește alt cod), .claude/test-php.php (generatorul: alfabet, lungime, 200 de coduri distincte) și .claude/check-secrets.mjs (nu se mai poate întoarce un cod fix în instalator sau în pagini).

- [x] **Codul tabletelor se anunța schimbat fără ca cineva să se uite în răspuns: patronul credea că a închis ușa după omul plecat din echipă, iar tableta aceluia intra mai departe cu codul vechi**
  `Conturi.dc.html:1293` · *critic*
  Mesajul verde se scrie numai dacă serverul confirmă, pe amândoi martorii: `scris` = cheia apare în lista `changed` (ori serverul nu trimite listă deloc, caz în care nu avem ce să-i reproșăm), `citibil` = `panel_code_set` nu e `false`. Dacă vreunul cade, în locul verdelui apare un roșu care spune adevărul întreg — „Codul NU s-a schimbat: tabletele cer în continuare codul vechi" — fiindcă asta trebuie să afle omul, nu că a eșuat o cerere. Cardul nu se mai mută pe „cod salvat" decât după confirmare. Masca lipită în casetă e oprită înainte să plece, cu un mesaj care o numește pe nume: „Acelea sunt punctele care ascund codul, nu codul."

- [x] **Coșul cu produse din ambele variante se trimite cu o singură variantă — clientul plătește altă sumă decât cea afișată și primește altă marfă**
  `Site Public.dc.html:1767` · *critic*
  Cererea nu se mai construiește din `picks` cu `productId` direct: rândul „pachet de test" — care n-are `productId` — se desface în cele 22 de poziții reale, câte 3 bucăți din fiecare, iar reducerea se scrie în notă („PACHET DE TEST −20%: … aplică reducerea la confirmare"). Un coș care rămâne fără nicio poziție nu mai pleacă spre server. Verificat în browser: pachet + 10 croasante → 23 de poziții trimise, cererea 257 înregistrată cu 247,05 lei, exact cât scria pe ecran. Probe în `.claude/check-site-public.mjs`.

- [x] **.htaccess-ul generat blochează chiar fișierul din care se încarcă poza principală a paginii de start**
  `.claude/build-deploy.mjs:182` · *grav*
  Excepție <Files ".image-slots.state.json"> Require all granted, pusă DUPĂ cele două interdicții (în Apache ultima secțiune care se potrivește câștigă). Fără ea, poza mare din antetul paginii de start și încă două imagini fără src în marcaj primeau 403, iar fetch-ul din image-slot.js înghițea eroarea în tăcere. Probă: check-deploy „.htaccess lasă să treacă fișierul din care se încarcă poza din antet”.

- [x] **Ruta șoferului nu se încărca deloc pe server: „/routes" lovea în folderul „routes/" de pe disc și nu mai ajungea niciodată la router**
  `.claude/build-deploy.mjs:466` · *grav*
  Trei rânduri în `.htaccess`-ul API-ului, scris de `build-deploy.mjs`. (1) `DirectorySlash Off` — Apache nu mai adaugă bara de la coadă înaintea rescrierii; aici nu se servesc pagini, deci nu pierde nimic. (2) Condiția `!-d` scoasă din regula de front-controller: în folderul API-ului nu e nimic de servit dintr-un folder, deci „tot ce nu e fișier real merge la router" e chiar ce trebuia scris de la început. (3) Fiindcă rescrierea nu se mai uită la foldere, o regulă nouă ține bucătăria în afara browserului: `RewriteCond %{REQUEST_FILENAME} -f` + `RewriteRule ^(lib|routes)/ - [F]` — condiția cere fișier ADEVĂRAT, deci rutele `/routes/counts` și `/routes/mine` trec pe lângă ea nestingherite (măsurat: 403 pentru `routes/orders.php` și `lib/db.php`, 200 pentru cele două rute). Paza împotriva întoarcerii stă în `check-deploy.mjs`: citește tabelul de rute din `api/index.php`, se uită pe disc ce foldere există, și cere ca fiecare rută a cărei cale întreagă e folder să ajungă totuși la router. Are și o probă că nu rămâne fără subiect, dacă ruta se redenumește. Cele patru stricăciuni o pică una câte una (17 · 0 întreg → 16 · 1 la fiecare).

- [x] **Paginile rămâneau vechi în telefon zile după publicare, fiindcă întregul bloc de memorare era sărit în tăcere pe serverele fără mod_expires**
  `.claude/build-deploy.mjs:374` · *grav*
  Acelaşi lucru spus a doua oară, pe alt modul: un bloc `mod_headers` care pune `Cache-Control: no-cache, must-revalidate` pe pagini. Nu e o repetare din neatenţie — cele două se sprijină pe module diferite, iar unul dintre ele lipseşte tocmai acolo unde contează. mod_headers e aprins pe orice instalare a aplicaţiei (fără el n-ar merge nici antetele de securitate, nici „noindex"-ul panourilor), deci blocul lucrează oricum, fără să fie nevoie de vreo comandă dată pe server. „no-cache, must-revalidate", nu „no-store": pagina are voie să stea în cache, dar browserul e dator să întrebe serverul de fiecare dată dacă s-a schimbat.

- [x] **Generatorul de semințe `gen-seed.mjs` a rămas în urma fișierului pe care îl scrie: rulat azi, ar șterge contul de sistem „panou" (tabletele n-ar mai putea intra), coordonatele sediului, etapele cu rol special, zonele adevărate și tot blocul de categorii**
  `.claude/gen-seed.mjs:37` · *grav*
  Generatorul nu mai poate pierde nimic în tăcere: înainte de scriere compară ce urmează să iasă cu ce e ACUM în fișierul țintă și, dacă s-ar pierde ceva, nu scrie nimic și spune exact ce — tabelele care ar dispărea cu totul (product_categories, users), cheile care ar dispărea (hq_label, hq_lat, hq_lng, order_status_done, order_status_cancelled, delivery_zone_coords) și setările scrise de mână pe care le-ar da înapoi la valoarea din prototip (delivery_zones: zonele adevărate ale firmei față de cartierele inventate), cu amândouă valorile puse una sub alta. Plasa se uită la VALORI, nu doar la chei: delivery_zones e în amândouă fișierele, deci o comparație de chei ar fi lăsat-o să se întoarcă la prototip fără niciun sunet. Poarta e îngustă, nu un „nu" permanent: un fișier care nu există încă se scrie, iar o a doua rulare peste propria ieșire trece — refuză doar când chiar s-ar pierde ceva. Ce NU s-a făcut, dinadins: generatorul tot nu știe blocurile lipsă, fiindcă proiectul a hotărât deja că sql/002_seed.sql se ține de mână; cheile noi se scriu în amândouă fișierele, iar mesajul de refuz o spune pe față. Probat: rulat pe fișierul adevărat refuză cu cod 1 și lasă fișierul neatins (md5 identic).

- [x] **Probele care strică dinadins surse ale proiectului puneau la loc doar dintr-un „finally": un Ctrl-C lăsa fișierul stricat, tăcut, iar următoarea construire îl ducea în pachetul de urcat pe server**
  `.claude/test-seo-dovada.mjs:252` · *grav*
  Un modul nou, `.claude/proba-pazita.mjs`, cu amândouă jumătățile reparației. Întâi: punerea la loc se leagă de `process.on('exit')` ȘI de `SIGINT`/`SIGTERM`, nu de `finally` — asta prinde Ctrl-C-ul și opririle obișnuite, iar 130 se scrie de mână, fiindcă un ascultător pus pe semnal ia locul opririi de la sine. Apoi: copia bună nu mai stă într-un folder temporar, ci în `.claude/.adapost/`, cu o fișă lângă ea. Ce nici paza nu poate prinde — pana de curent, procesul ucis fără milă — rămâne ACOLO, iar `verifica-tot` refuză să pornească atâta timp cât găsește un adăpost negolit: îți spune care fișier e stricat și cum se pune la loc, în loc să treacă peste. Oprirea stă înaintea oricărei rulări, ca nimic să nu apuce să vorbească despre un cod stricat. `test-seo-dovada` folosește adăpostul și îl golește abia DUPĂ ce s-a construit pachetul din sursele puse la loc; `test-sloturi` capătă aceeași pază de la ieșire, iar punerea lui la loc ia acum și lacătul, și fișierul pe jumătate scris. Punerea la loc de mână (`node .claude/proba-pazita.mjs pune-la-loc`) amintește că pachetul din `deploy/` trebuie construit din nou. Un fișier care NU exista înainte e și el o stare de păzit: punerea lui la loc înseamnă ștergerea.

- [x] **Actualizarea raporta „Actualizat." verde și când punea la loc un pachet vechi: nimic nu spunea CE pachet s-a aplicat**
  `actualizeaza-vps.sh:133` · *grav*
  Pachetul se ștampilează singur. `build-deploy.mjs` scrie la capăt `deploy/PACHET.txt` cu data construirii și amprenta `gp-api.js?v=…` (aceeași care se schimbă la fiecare modificare de cod ce ajunge în browser), iar `actualizeaza-vps.sh` o citește și o pune în raport la pasul „Caut pachetul"; lipsa ei e un avertisment galben, nu o tăcere. Fișierul NU pleacă pe site: rsync copiază numai cele patru foldere de subdomeniu, iar el stă deasupra lor. E singurul fișier din proiect scris fără diacritice, fiindcă se citește cu `cat` printr-un ssh al cărui locale poate fi „C". Pe lângă asta, `CITESTE-MA.md` și `docs/09-actualizare-vps.md` au căpătat secțiunea „cum vezi CE pachet a pus", cu verificarea care nu depinde de raport: `grep -o 'gp-api.js?v=[a-z0-9]*' /var/www/gp/public_html/app.html`. Miezul reparației e o propoziție: „Actualizat." înseamnă „am pus ce am găsit", nu „am pus ce ai construit azi" — iar acum raportul spune care din ele.

- [x] **Scriptul de actualizare făcea arhiva codului și o ștergea singur zece rânduri mai jos, la `--pastreaza 0` — iar pe urmă amândouă mesajele care arată calea înapoi mințeau despre ea**
  `actualizeaza-vps.sh:331` · *grav*
  Hotărârea patronului: „Aceeași pază, cuvânt cu cuvânt". Bucla arhivelor de cod a căpătat exact garda buclei de deasupra, literă cu literă, ca să se vadă dintr-o privire că sunt pereche. Ordinea din ea nu e întâmplătoare: întâi se cere să fie un NUMĂR, abia pe urmă să fie mai mare ca zero — altfel o valoare goală ar trece pe lângă cifre. `--pastreaza 0` înseamnă acum „nu face curățenie deloc", exact ce înseamnă deja pentru copiile bazei. Cele două mesaje care mințeau NU s-au atins, dinadins: ele se uitau la adevăr, iar adevărul era cel stricat. Cu paza pusă, `ARHIVA_COD` e goală în pasul „Fișiere" numai când chiar s-a dat `--fara-copie` (pasul acela e sărit cu totul la `--doar-baza`), deci mesajul lui `rupt()` redevine adevărat de la sine, iar raportul de la sfârșit arată calea unui fișier care chiar e acolo. Aceeași purtare ca la mesajul de la `:422-423` după reparația dinainte. Scris în AMÂNDOUĂ copiile scriptului — cea din proiect și cea din plasturele de VPS — fiindcă `.claude/test-plasa-rsync.mjs` le cere identice, și pe drept: o reparație pusă într-una și uitată în cealaltă e mai rea decât niciuna, fiindcă nimeni n-o mai caută. Nouă reguli noi în aceeași probă (partea a șaptea), din care șase sunt VII: blocul de arhivare se rulează cu `tar` adevărat pe un site de carton, iar ce a rămas pe disc se numără din Node, nu cu un `ls` tipărit din shell. Dovedită cu trei stricăciuni, scrise de fiecare dată în amândouă copiile: bucla fără pază (codul de dinainte), paza scrisă altfel decât la sora ei (purtarea rămâne bună, dar se pierde tocmai perechea din care s-a născut defectul), și paza bună cu `sort -r` scos — ultima șterge iar arhiva de azi, pe alt drum, și arată de ce nu e de ajuns să păzești numai valoarea.

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

- [x] **Panoul de comenzi trăgea 500 de comenzi și le filtra în browser: căutarea răspundea „nu există" pentru orice comandă mai veche de tăietură, iar filtrele de zi, canal și stare filtrau pagina, nu lista**
  `Administrare Comenzi.dc.html:1258` · *grav*
  Serverul: `route_orders_list()` (api/routes/orders.php) a primit `q` (client, referință și numele pozițiilor, prin EXISTS, nu prin JOIN — un JOIN ar fi înmulțit comanda cu numărul de poziții potrivite și ar fi umflat chiar cifra din care iese numărul de pagini), `chan`, `page` și `sort=urgent`, plus un `total` socotit pe ACELEAȘI condiții ca lista, deci și cu marginile de rol. Jokerii „%" și „_" se scapă cu ESCAPE „!", nu cu backslash: backslash-ul are alt înțeles în șirurile PHP și sub NO_BACKSLASH_ESCAPES, deci aceeași interogare ar fi căutat altceva după o schimbare de configurație. Paginile sunt stabile fiindcă ORDER BY day, seq e ordine totală — `uq_orders_day_seq` e cheie unică pe coloane NOT NULL — iar de acum o verificare din `.claude/check-sql.mjs` nu lasă indexul acela să dispară în tăcere. Panoul: două aduceri în loc de una. `pullOrders()` cere fereastra de șapte zile (from/to) și hrănește contoarele, ruta și produsele; `pullLista()` cere exact pagina de tabel, cu filtrele și căutarea puse pe server. Filtrul de zi are acum două opțiuni scrise pe față — „Săptămâna de lucru" (pornirea) și „Toate zilele · cu istoric" — iar orice text scris în casetă lasă fereastra jos, fiindcă cine caută vrea să găsească oriunde. Căutarea are ceas de 300 ms și număr de cerere, ca un răspuns întârziat să nu scrie peste unul mai nou. Sub tabel: „31–60 din 214 · tot istoricul" și cinci butoane. Ecranul gol nu mai minte: cu filtrele curate scrie „Nicio comandă în săptămâna de lucru" și oferă „Caută în tot istoricul", nu un buton de șters filtre care n-au fost puse. Probă: `.claude/test-defect-lista-comenzi.mjs`, scena D (14 verificări), plus două noi în `.claude/test-defect-panou-comenzi.mjs` pentru bătaia de patru secunde.

- [x] **Bifa „leagă și contul cumpărătorului pe viitor" nu pleca nicăieri dacă omul n-a schimbat și codul de recomandare — adică tocmai în cazul pentru care exista**
  `Administrare Comenzi.dc.html:1992` · *grav*
  Condiția se uită la CERERE, nu la schimbare: `cereLegatura = !!f.refLeaga && codAcum !== ""`. Când e adevărată, pleacă și `referralCode` (chiar neschimbat), fiindcă `referral_commission_sync()` de pe server leagă contul de codul din CERERE, nu de cel din baza de date. Bifa fără niciun cod nu trimite nimic — n-ar avea de ce să lege contul. Iar mesajul de pe ecran nu mai spune ce s-a trimis, ci CE S-A ÎNTÂMPLAT: `referral_commission_sync()` întoarce acum `legat_acum`, ridicat numai dacă `referral_link_new_user()` a scris cu adevărat rândul (are `WHERE ... AND referred_by IS NULL` și întoarce ceva doar pe `rowCount() > 0`), iar ruta îl trimite mai departe ca `commission.linked`. Așa panoul poate spune cinstit „Contul cumpărătorului e legat de codul «mihai» și pe viitor" sau „Contul NU a fost legat: fie n-are cont, fie avea deja un recomandant" — două adevăruri deosebite, care până acum arătau la fel.

- [x] **Limita cererilor de parolă nouă este globală, nu pe conexiune: 10 cereri opresc recuperarea pentru toată lumea**
  `api/lib/auth.php:142` · *grav*
  Pragul cererilor de parolă nouă e acum pe conexiune: forgot_rate_limited() numără WHERE ip = ? AND username = 'recuperare', iar rândul se scrie abia după ce se știe că cererea are ce trimite. Probe: test-parola.mjs „cererile altei conexiuni nu opresc recuperarea de aici" și „cererea cu câmpul gol nu consumă din plafon" — pică amândouă dacă pun înapoi login_rate_limited().

- [x] **Contorul de blocare a contului nu are fereastră de timp: după prima blocare, o singură greșeală blochează contul încă 20 de minute, la nesfârșit**
  `api/lib/auth.php:205` · *grav*
  Contorul de blocare se calculează din login_attempts, în fereastra nouă lock_window_min (60 minute), nu din coloana users.failed_logins care nu scădea niciodată. Probă: test-parola.mjs „contorul rămas din trecut nu mai blochează contul" (pică dacă revin la failed_logins + 1), plus perechea „douăsprezece greșeli strânse într-o oră blochează contul → 423" / „aceleași greșeli, de acum trei ore, nu mai blochează", care arată că fereastra chiar taie.

- [x] **Patiseria își spunea programul și adresa în două feluri deodată: omului care deschidea site-ul, „Luni–Vineri 07:00–21:00, Sâmbătă 07:00–17:00, Duminică Închis", scris în pagină; lui Google, în aceeași clipă, „Luni–Sâmbătă 05:00–21:00", citit din Setări — deci cine căuta pe telefon „patiserie deschisă acum" și cine intra pe site aflau lucruri diferite, iar unul din ei venea la ușă închisă**
  `api/lib/firma.php:1` · *grav*
  Un singur izvor. Cititorul de ore `gp_program()` s-a mutat din `seo/index.php` într-un fișier nou, `api/lib/firma.php`, împreună cu o funcție nouă deasupra lui, `firma_publica($adresa, $program)`, care întoarce tot ce trebuie știut despre firmă dintr-o singură citire: strada, sectorul, orașul, adresa întreagă în română și în engleză, legătura spre hartă făcută din ea, și programul în trei forme — rândul de citit (ro/en), forma pe care o cere Google, și un tablou pe toate cele șapte zile, cu `null` pe zilele închise. Coada adresei se lipește numai dacă nu e deja acolo, căutată fără diacritice și fără socoteala majusculelor, ca pilda din Setări să nu mai dea adresă dublată. `seo/index.php` rămâne de sine stătător, cum a fost hotărât — caută fișierul în trei locuri și, dacă nu-l găsește, merge mai departe cu un temei de rezervă, fiindcă mai bine un site fără date structurate decât un site căzut. Aceeași funcție e chemată și de `api/routes/products.php` în `GET /lists`, care trimite magazinului adresa, adresa întreagă, cele două legături și programul — fără forma pentru Google, care ar pleca degeaba spre fiecare browser. `shared/gp-api.js` capătă `gp.program()`, care grupează zilele alăturate cu același orar („Luni – Vineri", „Sâmbătă, Duminică") și însemnează ziua de azi după ceasul patiseriei, nu după cel al telefonului. În `Site Public.dc.html` toate cele șapte locuri ale adresei și tabelul de program s-au mutat pe legături: tabelul e acum un `<sc-for>` peste ce dă `gp.program()`, ascuns cât timp serverul n-a răspuns, ca să nu se vadă nicio clipă ore de rezervă scrise în cod — adică tocmai paguba de reparat. Ce a mai rămas scris în cod: sectorul și orașul, ca două constante în `api/lib/firma.php`, exact cum erau și înainte.

- [x] **Ziua cu un octet zero în ea scoate „Eroare internă" din formularul public de cereri**
  `api/lib/http.php:186` · *grav*
  Forma se cerne cu preg_match('/^\d{4}-\d{2}-\d{2}$/') ÎNAINTE de DateTime, iar valoarea arătată înapoi în refuz trece printr-un text_de_aratat() nou, care scoate caracterele de comandă și taie la 40 de caractere. Probe: .claude/test-reparat-lungimi-si-zile.mjs, secțiunea „ziua cu octet zero" (măsurată dând reparația înapoi: trei verificări pică cu 500 server_error).

- [x] **O notificare eșuată nu e văzută de nimeni, niciodată**
  `api/lib/notify.php:111` · *grav*
  GET /settings aduce acum lista anunțurilor eșuate nevăzute (coloana seen_at exista în schemă din start, dar n-o scria și n-o citea nimeni), Setările le arată ca alertă roșie deasupra filelor — vizibilă pe orice filă — iar POST /settings/notifications/seen le marchează ca văzute. Până acum un anunț nelivrat trăia doar în tabelul notifications, în care nu se uita nicio pagină. Probe: test-settings, blocul „anunțurile eșuate ajung sub ochii cuiva” (8 verificări, inclusiv 403 pentru angajat).

- [x] **Produsul scos din catalog blochează definitiv orice corectură pe comenzile și cererile care îl conțin**
  `api/lib/orders.php:162` · *grav*
  price_items() a pierdut AND active = 1 din interogare (rândul trebuie citit oricum, ca să se poată tarifa) și a primit al patrulea parametru, $ingaduite: produsele aflate DEJA pe comanda care se corectează sau pe cererea care se acceptă trec chiar scoase din catalog. Cele două rute care nasc ceva nou — POST /orders și POST /requests — nu trimit nimic, deci un produs retras tot nu se mai vinde. Refuzurile s-au despărțit: „inexistent" (cu ce e de făcut) și „scos din catalog" (cu numele produsului și cum se repune), amândouă cu codul bad_product. Probă: test-defect-produs-scos (34 de verificări; dată înapoi reparația, pică 12).

- [x] **Marfa iese dintr-un articol și se întoarce în altul: returul recalculează raftul în loc să-l citească din mișcarea de scădere**
  `api/lib/orders.php:554` · *grav*
  Scăderea scrie `order_id` în `stock_moves`, iar returul citește raftul din registru în loc să-l recalculeze din nume: marfa se întoarce exact în articolul din care a plecat. Probă: `.claude/test-defect-raft-fantoma.mjs`.

- [x] **Redenumirea (sau dezactivarea) codului unui agent șterge comisioanele lui neplătite de pe comenzile luate la telefon**
  `api/lib/referral.php:751` · *grav*
  Rândul de comision se șterge numai când cererea de față chiar a umblat la cod; redenumirea sau dezactivarea codului agentului doar recalculează suma, pe `referrer_id` și `code_used` deja scrise în rând — faptul câștigat, nu șirul. Probă: `.claude/test-defect-comision-sters.mjs`.

- [x] **Oprirea auto-recomandării se ocolește comandând de pe site fără să fii conectat: omul își încasează comision din propriile cumpărături**
  `api/lib/referral.php:757` · *grav*
  `referral_commission_sync()` compară și identitățile pe care comanda LE ARE — telefonul normalizat, e-mailul — nu doar `customer_id`, deci o comandă făcută de pe site fără cont nu mai poate plăti codul propriului cumpărător. Refuzul se vede în Jurnal ca `referral_self_blocked`. Colateral asumat: două conturi care chiar împart un telefon pierd comisionul. Probă: `.claude/test-defect-auto-recomandare.mjs`.

- [x] **Procentul comisionului nu e înghețat: orice atingere a comenzii îl retarifează la procentul de azi**
  `api/lib/referral.php:762` · *grav*
  Procentul se citește din rândul de comision existent, nu din setarea de azi: o înțelegere de atunci rămâne înghețată la fiecare atingere a comenzii. Probă: `.claude/test-defect-procent-inghetat.mjs`.

- [x] **Comisioanele se năşteau la 0,00 RON şi rămâneau aşa pe veci: procentul implicit era zero, iar zeroul se îngheţa odată cu rândul**
  `api/lib/referral.php:597` · *grav*
  Două jumătăţi, fiindcă defectul are un trecut şi un viitor. Pentru ce vine de-acum: `sql/017_procent_recomandare.sql` urcă setarea la 5, iar `referral_default_rate()` are aceeaşi rezervă scrisă în cod — ca rândul din bază să nu se poată certa cu programul pe o instalare la care migrarea n-a ajuns. Pentru ce s-a scris deja: un buton în „Recomandări", apăsat de patron, care arată ÎNTÂI rând cu rând ce s-ar schimba (cine încasează, ce comandă, ce bază, procentul de azi şi suma nouă) şi abia la a doua apăsare scrie. Atinge numai rândurile cu procent zero, NEPLĂTITE şi „activ", fiecare cu procentul de azi al lui. Îngheţul rămâne neatins: el apără un câştig adevărat.

- [x] **Un comision DEJA PLĂTIT care ajungea în conflict rămânea fără cele două coduri — deci conflictul nu se mai putea hotărî niciodată, pe nicio cale**
  `api/lib/referral.php:1353` · *grav*
  Hotărârea patronului: „Scrie și codurile pe rândul plătit". Ramura plătită scrie acum `status`, `code_account` și `code_typed` într-un singur UPDATE; `amount` și `rate` NU intră niciodată — înghețarea sumei rămâne întreagă, și e păzită de o regulă nouă care cere ca suma să nu se clintească NICI ÎN CLIPA CONFLICTULUI (un conflict are `amount = 0` dinadins, iar scris peste un rând plătit ar șterge un câștig deja plecat din casă). Se scrie și când S-AU SCHIMBAT NUMAI CODURILE, fără starea: drumul e agentul care își redenumește codul — `code_account` se socotește din codul de ACUM al recomandantului contului, deci se schimbă sub rândul plătit fără ca starea să miște. Lăsată numai pe schimbarea stării, mărturia ar fi rămas cu codul MORT, iar butonul acela ar fi dat 422 pe vecie. Jurnalul rămâne al stării: intrarea în conflict e faptul care se ține minte, codurile se văd pe card oricând. Zero schimbări în pagini — butoanele care există deja pe card funcționează acum. Probe: secțiunea 13 din `.claude/test-recomandari.mjs` (12 reguli noi, 67 → 79), fiecare dovedită că se înroșește pe codul stricat cu patru stricăciuni țintite, una dintre ele chiar codul de dinainte de reparație.

- [x] **Hotărârea unui conflict pe un rând DEJA PLĂTIT muta codul comenzii și recomandantul contului pe celălalt agent, dar banii rămâneau la primul — editarea comenzii avea pază, hotărârea nu**
  `api/lib/referral.php:1718` · *grav*
  Hotărârea patronului: „Aceeași pază ca la editare". Legea NU s-a scris a doua oară — `referral_commission_resolve()` cheamă aceeași `referral_guard_paid_code_change()` pe care o cheamă și `PATCH /orders`. Două uși, o singură lege, scrisă o dată. Dacă judecata ar fi fost rescrisă de mână în hotărâre, cele două uși ar fi început să se depărteze la prima reparație făcută numai într-una — și asta nu se vede din purtare, deci se întreabă din text: două reguli statice cer ca definiția să fie una singură și amândouă ușile s-o cheme pe ea. (Numărătoarea sare peste rândurile de comentariu: un doc-comentariu care NUMEȘTE paza e o trimitere, nu o a doua lege, iar o regulă care le-ar socoti la fel ar cere, ca preț al verdelui, să nu mai scrie nimeni despre ea.) Chemarea stă ÎNAINTE de prima scriere și DUPĂ cele două refuzuri de sus (`bad_code`, `not_in_conflict`), deci codul străin cade unde cădea și până acum. Refuzul e îngust, ca la cealaltă ușă: hotărârea în favoarea chiar a celui deja plătit trece, fiindcă nu schimbă nimic — numai mutarea pe alt om se oprește. Mesajul s-a făcut parametru (`$urmatorulPas`), fiindcă cele două uși se apasă din ecrane diferite: la editare „…apoi pune codul", la hotărâre „…apoi hotărăște conflictul". Legea rămâne scrisă o singură dată; se schimbă numai încotro e trimis omul. Și e trimis undeva pe bune: refuzul nu e o fundătură, e o ORDINE, iar proba face chiar ce spune mesajul — scoate bifa, și hotărârea trece. Ordinea „paza înaintea scrierii" nu e o pedanterie, și se vede din stricăciunea a treia: cu paza chemată după scrieri, 409-ul iese la fel, dar codul comenzii și recomandantul contului arată deja amândouă spre Barbu. Iar `referral_commission_pay()` RECITEȘTE rândul când bifa se scoate — deci în clipa în care patronul face exact ce-i spune mesajul, conflictul se stinge singur pe „activ", hotărârea la care fusese trimis răspunde `not_pending`, și comisionul a ajuns la Barbu fără ca nimeni să fi ales asta.

- [x] **Schimbarea codului tabletelor nu închide tabletele deja deschise, deși pagina spune să-l schimbi „când pleacă cineva din echipă”**
  `api/lib/settings.php:251` · *grav*
  settings_save() șterge sesiunile contului „panou" când se schimbă panel_code sau când panel_enabled trece pe 0. Starea se citește din ce tocmai s-a scris, nu cu setting_bool(): settings_all() ține un cache pe cerere, dinaintea salvării. Probe: test-api.mjs „schimbarea codului scoate tabletele deja deschise → 401" și „oprirea accesului cu cod scoate tabletele deschise → 401".

- [x] **Schimbarea parolei nu anulează linkurile de recuperare deja trimise**
  `api/routes/auth.php:301` · *grav*
  Schimbarea parolei — din cont (route_change_password) și de către administrator (route_users_password) — închide acum și cererile de recuperare nefolosite: UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL. Probe: test-parola.mjs „linkul cerut înainte nu mai poate pune altă parolă → 410" și „și aici linkul cerut înainte se închide → 410".

- [x] **Parola pusă prin linkul „am uitat parola" nu e tăiată de spații, dar la autentificare este**
  `api/routes/auth.php:238` · *grav*
  route_reset() taie parola de spații, ca toate celelalte drumuri, și șterge încercările eșuate ale contului (clear_attempts), fiindcă blocarea se numără acum din ele. Pagina Intrare taie și ea (Intrare.dc.html). Probe: test-parola.mjs „se intră cu parola fără spații, cum o taie și autentificarea" și „după resetare se intră imediat" — pică amândouă dacă scot trim-ul, respectiv clear_attempts.

- [x] **Catalogul nu verifica lungimea textelor: un nume de 300 de caractere ieșea ca „Eroare internă"**
  `api/routes/catalog.php:34` · *grav*
  Toate cele patru locuri trec acum prin text_field(), cu lățimile coloanelor scrise o singură dată în capul fișierului (CAT_NUME_MAX, CAT_NOTA_MAX, PROD_NUME_MAX, PROD_DESC_MAX). Adresa scrisă cu mâna se refuză dacă nu încape; cea făcută din nume se scurtează singură pe ultima cratimă, cu slug_scurt() — deci un nume lung dar legitim intră în catalog, cu numele întreg și o adresă citibilă. Probe: .claude/test-reparat-lungimi-si-zile.mjs (măsurată dând reparația înapoi: 23 de verificări pică cu 500 db_error).

- [x] **Administratorul deține chiar cutia poștală care hotărăște: își mută adresa firmei pe adresa lui și își dă singur rolul de fondator**
  `api/routes/founder.php:104` · *grav*
  Adresa firmei (`company_email`) — cutia poștală care hotărăște cine devine fondator — n-o mai poate schimba decât un fondator, cât timp există unul. Probă: `.claude/test-defect-usa-fondator.mjs`.

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

- [x] **Bifa „leagă contul" de la codul de recomandare e desfăcută de următoarea atingere a comenzii**
  `api/routes/orders.php:518` · *grav*
  `PATCH /orders` pornește cu `$refLeaga = false` (api/routes/orders.php:624): o corectură pe o comandă deja făcută nu mai leagă „pentru totdeauna" contul cumpărătorului de cod. Steagul se ridică numai când cererea aduce pe față `referralCode` și bifa `referralLink`. Probe: `.claude/test-recomandari.mjs`, `.claude/check-panouri.mjs`.

- [x] **Oricine își face cont pe site cu numele unui client al casei îi capătă comenzile luate la telefon**
  `api/routes/orders.php:190` · *grav*
  Legarea comenzii de un cont după nume (`client_by_name()`, api/lib/orders.php) sare peste conturile făcute de pe site pe care casa nu le-a recunoscut încă. Migrarea `sql/010_origine_cont.sql` adaugă `users.self_registered`, `users.vouched_at` și `users.vouched_by`; butonul „Recunosc clientul" din Conturi (`POST /users/{id}/vouch`) e comutator, deci o recunoaștere greșită se poate scoate fără phpMyAdmin. Fără migrare poarta cade DESCHISĂ, dinadins: fail-closed n-ar lega nicio comandă de niciun cont și ar goli panoul fiecărui client. Probă: `.claude/test-defect-cont-uzurpat.mjs` (15 trecute · 37 eșuate pe codul de atunci, 52 · 0 pe cel reparat, aceleași 52 de verificări).

- [x] **Referința comenzii, golită cu degetul din formular, se salva „cu succes" și rămânea cea veche — sau, pe alt drum, goală: comanda devenea de negăsit în laborator și pe bon**
  `api/routes/orders.php:679` · *grav*
  Serverul refuză pe față: `if ($ref === "")` → `fail(..., 422, "empty_ref")`, cu un mesaj care spune LA CE folosește referința („după ea se caută comanda în laborator și pe bon"), nu doar că e obligatorie — omul trebuie să înțeleagă de ce nu poate, ca să nu creadă că e o toană a programului. Poarta e îngustă: apără golul, nu schimbarea, deci un alt număr scris de om trece mai departe. În formular, caseta de referință a unei comenzi NOI e `readOnly`, cu scrisul stins, cu locțiitorul „se completează automat" și cu explicația sub deget („Se dă automat la salvare, în ordinea zilei. Se poate schimba după ce comanda e făcută."); la o comandă deja făcută rămâne scriibilă, cu altă explicație. Așa cele două jumătăți spun același lucru: numărul se naște la server, și se corectează după aceea.

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

- [x] **Reducerea pachetului de test era o vorbă pe ecran: cererea se înregistra la prețul de catalog, iar comisionul agentului se socotea din cifra mare**
  `api/routes/requests.php:118` · *grav*
  Reducerea nu și-a căpătat o ramură nouă în tarifare: călărește mecanismul care exista deja, `$onorate` din `price_items()` — „prețuri promise clientului", o coadă de felii per cantitate. Pachetul de test ESTE o astfel de promisiune (3 bucăți din fiecare, la 80%), iar exprimată așa a cumpărat pe gratis trei lucruri: acceptarea cererii nu mai retarifează (`honored_prices()` citește `order_request_items`), `PATCH /orders` la fel (citește `order_items`), deci o tabletă din laborator nu mai poate strica reducerea mutând o etapă; iar coșul amestecat se taie corect în două rânduri (3 buc la 2,28 și 100 la 2,85) în loc să inventeze un preț mediu. Comisionul n-a cerut nicio schimbare — urmează `orders.total`, care acum e ce s-a încasat. Fișiere: `sql/011_reducere_pachet_test.sql` (coloanele `promo` pe cerere și pe comandă, `list_price` pe fiecare poziție), `api/lib/promo.php` (nou), plus porțile din `api/routes/requests.php`: codul trebuie să fie unul cunoscut, coșul trebuie să FIE pachetul întreg (altfel oricine trimitea `promo` lua −20% pe orice pusese în coș), și omul nu trebuie să-l fi luat deja. Probă: `.claude/test-defect-reducere-reala.mjs`, 42 de verificări.

- [x] **Cererea scrisă în numele altuia se lega de cont prin potrivire de nume, iar comisionul se scria pe codul celui care a SCRIS cererea**
  `api/routes/requests.php:173` · *grav*
  Clientul se ALEGE dintr-o listă, nu se scrie. Rută nouă şi îngustă, `GET /clients` (`api/routes/clients.php`): adminul şi fondatorul caută printre toţi clienţii, agentul de vânzări numai printre cei aduşi de codul lui, managerul şi angajatul deloc — ograda vine din SESIUNE, nu dintr-un parametru (`client_scope_for()`, api/lib/orders.php). Se întorc numai numele, telefonul, e-mailul şi datele de livrare: nimic despre bani nu pleacă pe ruta aceea. Cererea duce apoi `customerId`, un număr, trecut prin ACEEAŞI ogradă (`client_in_scope()`) ca lista care l-a arătat, iar numele se ia de pe contul ales, nu din formular. Şi partea de bani: când clientul a fost ales anume, codul din formular NU se mai scrie — socoteala cade pe ramura a doua din `referral_commission_sync()`, care citeşte `users.referred_by`, adică adevărul de pe contul clientului. În pagină caseta codului dispare cu totul şi în locul ei stă scris, la vedere, unde se duce comisionul.

- [x] **Pragul de cereri de pe site sărea peste orice cont, iar un cont se face singur în zece secunde: poarta avea o clanță pe dinafară**
  `api/routes/requests.php:174` · *grav*
  Hotărârea patronului: „Și poarta, și panoul". Partea aceasta e poarta. Răspunsul NU s-a scris a doua oară: `request_counts_against_ip()` (api/lib/auth.php) pune exact întrebarea lui `client_by_name()` — `self_registered = 1 AND vouched_at IS NULL` — și e chemată din amândouă locurile pragului, și la verificare, și la scădere. Despărțite, ar fi ieșit un prag care se verifică pe cineva și nu se scade de la el: plafonul n-ar fi fost atins niciodată, iar poarta ar fi arătat pusă fără să fie. Nelimitați rămân, pe drept, personalul și clientul recunoscut de casă. Pe o bază fără migrarea 010 poarta cade DESCHIS, ca `user_origin_supported()`: o poartă care se închide singură în fereastra unei actualizări e mai rea decât paguba de care fuge.

- [x] **Lista cererilor se taie la 500 pe vechime, de orice stare: o cerere rămasă fără răspuns iese din panou cu trecerea anilor, sau în două minute de potop — nici în listă, nici în numărul de pe filă**
  `api/routes/requests.php:110` · *grav*
  Hotărârea patronului: „Și poarta, și panoul". Partea aceasta e panoul, și s-a închis în trei, fiindcă niciuna nu ajunge singură. (1) Cele nehotărâte ies primele din `ORDER BY (r.status = 'nou') DESC, …`: chezășia stă la cel care TAIE, nu la cel care cere — o pagină poate cere `?status=nou`, dar sub „Toate" ar cere iar toate stările și s-ar tăia iar. (2) Panoul cere anume stările pe care le arată, ca pagina clientului, cu „nou" mereu între ele: filtrul de istoric schimbă ce se vede în listă, nu cât are omul de răspuns. O singură cerere pe tură, ca până acum — ceasul bate din patru în patru secunde. (3) Cifrele de pe butoane vin de acum din bază, dintr-un `GROUP BY` pe aceeași cerere, nu din lista adusă: fără partea asta reparația ar fi stricat singură lucrul pe care panoul îl apără pe față — „Fiecare număr vine din CHIAR lista pe care butonul o deschide" —, fiindcă sub filtrul „Noi" butonul „Acceptate" ar fi scris 0. Pe deasupra, numărul e de-acum adevărat, nu tăiat la 500 ca înainte.

- [x] **Și serverul refuza orice cerere fără produse, cu „Cererea trebuie să conțină cel puțin un produs" — regula unei comenzi, pusă la poarta prin care intră și întrebările**
  `api/routes/requests.php:186` · *grav*
  Hotărârea patronului: „Coș gol = mesaj, coș plin = cerere". Partea aceasta e serverul. Refuzul de la poartă s-a scos, și regula „o comandă are produse" a rămas acolo unde e adevărată: în `route_requests_accept()`, care o refuză mai departe cu 422 `empty_request`. Deosebirea e cine primește refuzul — administratorul, care poate suna omul, nu clientul, care n-avea ce face cu el. Ce NU s-a slăbit: o valoare care nu e listă rămâne refuzată, acum cu numele ei, `bad_items` (fără asta `price_items()` ar primi un șir și ar cădea cu o eroare internă), iar plafonul de 200 de poziții e neatins. Cele două gropi de sub poartă s-au astupat odată cu deschiderea ei: `$variant = $priced ? order_variant_of($priced) : $variant;` lasă varianta cerută, adică „copt", în loc de „mixt"; iar `format_request_message()` scrie pe o cerere fără poziții „FĂRĂ PRODUSE ALESE — e o întrebare sau o cerere de ofertă, nu o comandă. Citește mențiunile și sună omul." și nu-i mai pune niciun total alături. Amândouă au reguli statice ale lor și stricăciuni în dovadă.

- [x] **Etapa „anulată” rămâne agățată de o etapă ștearsă: butonul de anulare a comenzii moare, iar comenzile anulate se întorc pe rută și în rezervările de stoc**
  `api/routes/settings.php:277` · *grav*
  `guard_status_list()` verifică etapele cu rol special de fiecare dată când se salvează lista, nu doar când cheia lor vine în cerere: o etapă ștearsă nu mai poate lăsa anularea agățată de nimic, cu refuz 422 care spune ce e de făcut. Probă: `.claude/test-defect-etapa-anulare.mjs`.

- [x] **Schimbarea furnizorului de WhatsApp se salva singură, iar panoul arăta mai departe „Token salvat" — despre cheia furnizorului de dinainte, care la cel nou nu deschide nimic**
  `api/routes/settings.php:233` · *grav*
  `guard_whatsapp_provider()` stă lângă celelalte porți ale rutei, ÎNAINTE de `settings_save()` — un refuz de după scriere ar fi spus adevărul prea târziu. Se uită la SCHIMBARE, nu la furnizor: dacă cheia nu vine în cerere, ori vine cu aceeași valoare, nu cere nimic (altfel orice atingere a panoului de Setări ar fi cerut tokenul din nou). Când furnizorul chiar se schimbă, cere în ACEEAȘI salvare și tokenul, și a doua cutie, iar „scris" înseamnă nu gol, nu `__clear__` și nu începând cu „•" — masca întoarsă de server nu e o acreditare, ea înseamnă „lasă cum era", adică exact cheia veche. Mesajul numește pe nume ce lipsește la furnizorul ALES („Account SID-ul" la Twilio, „Phone Number ID-ul" la Meta), fiindcă altfel omul caută în ecranul greșit. În panou, `waSet` se stinge de îndată ce lista arată alt furnizor decât cel salvat, iar ajutorul de sub casetă explică de ce: tokenul de acolo e al furnizorului de dinainte.

- [x] **Comanda ale cărei produse n-au articol în depozit e marcată „scăzută” definitiv, deși nu s-a scăzut nimic**
  `api/routes/stock.php:296` · *grav*
  Comanda a devenit unitatea de lucru: dacă vreo poziție a ei n-are articol în depozit, nu se scade nimic din ea și rămâne nescăzută, ca să poată fi reluată după ce se creează articolul. Răspunsul spune și câte comenzi au rămas pe loc („skipped”), iar Depozit scrie asta în mesaj. Probă: .claude/test-settings.mjs, secțiunea „comanda fără articol în depozit”.

- [x] **Un produs redenumit ajunge cu două articole de depozit și fiecare comandă scade de două ori**
  `api/routes/stock.php:321` · *grav*
  Scăderea alege UN singur articol pentru fiecare poziție, printr-o subinterogare care întoarce cel mult un id (api/routes/stock.php): întâi cel legat prin `product_id`, apoi cel potrivit pe nume, iar articolele scoase din depozit trec la urmă — altfel scăderea ar cădea pe un rând pe care depozitarul nu-l mai vede nicăieri, iar singurul articol vizibil n-ar mai coborî niciodată. Dublura e oprită și la naștere: `POST /stock/items` refuză cu 409 `product_taken` un al doilea articol viu pe același produs, iar caseta „Articol nou" din Depozit trimite acum `productId`, aflat din comenzile în care apare numele — inclusiv din cele predate, tocmai cele din mesajul care îi cere depozitarului să facă articolul. Returul de stoc din `stock_unsync_order()` folosește exact aceeași alegere, ca marfa să nu iasă dintr-un raft și să se întoarcă în altul. Probe: .claude/test-reparat-scadere-dubla.mjs (27 de verificări; dată înapoi reparația, pică 11) și blocul „Articol nou" din .claude/check-panouri.mjs (9 verificări).

- [x] **Articolul „scos din depozit” rămâne raftul pe care cad scăderile și rezervările, dar nu-l mai vede nimeni**
  `api/routes/stock.php:385` · *grav*
  Articolele scoase din depozit (`active = 0`) ies din potrivirea după nume în toate cele patru locuri — scădere, retur, rezervări, producție — deci poziția iese la „missing" în loc să cadă pe un raft pe care nu-l mai vede nimeni. Probă: `.claude/test-defect-raft-fantoma.mjs`.

- [x] **Urma scrisă sub anunțul din Telegram („✅ Acceptată de pe telefon", cu butoanele scoase) stătea în același `try` cu toastul de pe ecran — iar toastul cade tocmai în ziua încărcată, și lua urma cu el**
  `api/routes/telegram.php:377` · *grav*
  Hotărârea patronului: „Urma întâi, în `try`-ul ei". Două blocuri neatârnate, cu `editMessageText` PRIMUL: urma e cea care rămâne a doua zi și singura care scoate butoanele, deci nu are voie să atârne de o curtoazie cu termen. Toastul rămâne obligatoriu, dar e al doilea și, dacă pică, pică singur — pierderea e o rotiță, nu o hotărâre. Fiecare bloc își scrie propriul rând în logul serverului, cu text deosebit, tocmai fiindcă asta e singura urmă a unei căderi care nu se vede nicăieri altundeva. Prețul, plătit dinadins și scris în comentariu: toastul întârzie cu o chemare de rețea. Cinci reguli noi în `.claude/test-defect-hotarare-de-pe-telefon.mjs`, secțiunea D, dintre care trei sunt VII, nu statice: `telegram_toast()` se cheamă de-a dreptul din PHP, cu tokenul golit de chinga probei, și se numără rândurile din log. Dovedită cu trei stricăciuni: codul de dinainte, varianta cu două blocuri dar toastul tot primul (cea pe care patronul n-a ales-o), și ordinea bună lăsată totuși într-un singur `try` — ultima arată că regula de ordine singură n-ar fi fost de ajuns.

- [x] **Resetarea parolei nu protejează contul de sistem „panou" și deconectează toate tabletele**
  `api/routes/users.php:158` · *grav*
  route_users_password() refuză contul de sistem (422 system_account), route_users_list() întoarce isSystem, iar pagina Conturi ascunde pentru el Generează / Trimite pe mail / Schimbă parola și pune în loc trimiterea la Setări → Acces. În plus resetarea cheamă clear_attempts(), fiindcă blocarea se numără din login_attempts. Probe: test-api.mjs „lista spune care cont e de sistem" și „contul de sistem nu primește parolă → 422"; test-parola.mjs „se intră imediat cu parola dată de administrator".

- [x] **Ștergerea numelui în pagina Conturi trimite name = NULL pe o coloană NOT NULL și dă „Eroare internă"**
  `api/routes/users.php:93` · *grav*
  Numele golit e refuzat cu 422 empty_name în loc să ajungă NULL pe o coloană NOT NULL și să iasă „Eroare internă.". Celelalte trei câmpuri rămân nullable, deci golirea lor merge mai departe. Probe: test-api.mjs „numele golit e refuzat, nu dă eroare internă → 422", „numele rămâne cel dinainte" și „e-mailul golit se acceptă mai departe → 200".

- [x] **Un administrator poate schimba adresa de e-mail a fondatorului și îi ia contul prin „Am uitat parola”**
  `api/routes/users.php:327` · *grav*
  Schimbarea adresei de e-mail pe contul ALTCUIVA trece prin `guard_leader_role()`, deci un administrator nu mai poate muta adresa fondatorului ca să-i ia contul prin „Am uitat parola". Propria adresă rămâne editabilă. Probă: `.claude/test-defect-usa-fondator.mjs`.

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

- [x] **Un angajat cu contul lui nu se putea autentifica nicăieri: ușa din față îl deconecta după ce parola fusese deja acceptată, iar cealaltă ușă îl refuza și ea**
  `Intrare.dc.html:917` · *grav*
  Poarta din `Intrare.dc.html` s-a mutat de pe `user.role === "angajat"` pe `user.isSystem === true`, cu motivul scris deasupra. În `Client.dc.html`, refuzul nu mai șterge sesiunea: cine își scrie acolo contul de lucru e dus la `gp.primaPagina(user)`, adică unde l-ar fi dus și intrarea pe ușa din față — o parolă bună nu se mai pedepsește cu un „nu". Două reguli noi în `check-panouri.mjs`: „un angajat cu contul lui poate intra pe ușa din față" (refuzul nu se mai poate scrie pe rol) și „contul comun al tabletelor se deschide doar cu codul" (refuzul pe `isSystem` există, iar `public_user()` chiar trimite câmpul). Dovedite stricând la loc: 733 · 0 → 731 · 2 cu refuzul pus înapoi pe rol, 732 · 1 cu poarta scoasă de tot, 732 · 1 cu `isSystem` scos din `public_user()`. [13 septembrie 2026]

- [x] **Jurnalul nu poate fi citit dincolo de primele 100 de intrări: reîmprospătarea din 5 în 5 secunde resetează pagina curentă**
  `Jurnal.dc.html:265` · *grav*
  Jurnalul se paginează de-adevăratelea: „Încarcă mai vechi” nu se mai pierde la reîmprospătare, iar pagina folosește gp.poll() în locul cronometrului propriu.

- [x] **Filtrul „Zonă” din Jurnal nu se potrivește niciodată: toate butoanele arată 0 și golesc lista**
  `Jurnal.dc.html:188` · *grav*
  Butoanele de zonă și coloana „Zonă” arată chiar zonele scrise de server în audit_log, nu o listă veche din pagină.

- [x] **Când `gp.me()` nu ajunge la server, Jurnalul tace și pleacă singur la pagina de intrare, fără să spună de ce**
  `Jurnal.dc.html:334` · *grav*
  Lanțul de pornire deosebește acum „serverul a spus că n-ai sesiune” de „n-am putut întreba”: un martor `NEAFLAT` întors din catch oprește plecarea la intrare, iar pe ecran rămâne caseta cu motivul și butonul de reîncercare. Poarta s-a adus la forma cu patru termeni din „Setări” (`S.loaded && !!auth && !isAdmin && !S.eroare`), ca ecranul de refuz să nu se mai poată desena peste o sesiune neaflată. Probă: `.claude/check-erori-tacute.mjs`.

- [x] **Referința comenzii se repetă în fiecare zi, iar Panoul Laborator schimbă etapa după `ref` — avansează comanda altei zile**
  `Panou Laborator.dc.html:363` · *grav*
  Panoul ține comenzile pe id, nu pe referința care repornește în fiecare zi: apăsarea nu mai avansează comanda omonimă din altă zi.

- [x] **În laborator, pe ecran lat, se derula pagina în loc de coloane: butoanele foii de detaliu plecau cu zeci de mii de pixeli sub linia ecranului**
  `Panou Laborator.dc.html:93` · *grav*
  Pe ecran lat (`@media (min-width:901px)`), învelișul capătă o înălțime ADEVĂRATĂ: `height:100vh` plus `overflow:hidden`, iar rândul cu coloanele `overflow:hidden`. Atunci rândul are un capăt, iar cele două coloane se derulează fiecare în ea — coloana de carduri prin `overflow-y:auto` pus în bandă, coloana de detaliu prin cel pe care îl avea deja în marcaj. Antetul, filele zilelor și caseta de avertizare rămân la vedere, ceea ce e chiar rostul lor. Trei amănunte fără care reparația n-ar ține: (1) regulile sunt `!important`, fiindcă se bat cu un stil scris în marcaj — fără semn, regula e acolo, se citește bine, și nu face nimic; (2) de-aia învelișul și rândul au căpătat cârligele `data-lb="page"` și `data-lb="shell"`, ca regulile să aibă un selector cu care să învingă; (3) `min-height:0` de pe rând e veriga fără care capătul nu ajunge la coloane, fiindcă un copil de flex are `min-height:auto` și refuză să coboare sub conținutul lui. Pe telefon NU s-a atins nimic: acolo foaia de detaliu rămâne foaie de jos, cu `max-height:88vh` și derularea ei, iar proba refuză orice înălțime pusă pe înveliș în banda de telefon.

- [x] **Redenumirea unei grupe trimite un PATCH la fiecare tastă, iar răspunsurile care se întorc în altă ordine lasă în baza de date un nume trunchiat**
  `Produse.dc.html:490` · *grav*
  Cele patru câmpuri din fila „Grupe” nu mai scriu pe server la fiecare tastă: tastarea ține minte doar textul (catEdit/catText), iar cererea pleacă o singură dată, la ieșirea din câmp (onBlur). Un nume neschimbat nu mai lasă intrare în Jurnal, iar un nume golit e oprit în pagină, cu mesaj. Probă în .claude/check-panouri.mjs: incheieGrupa() e scoasă din sursă și rulată — numele nou pleacă o dată, întreg; cel neschimbat și cel gol nu pleacă deloc.

- [x] **Dacă fotografia e refuzată la salvarea unui produs nou, produsul a fost deja creat, iar a doua apăsare pe „Adaugă în catalog” dă „adresa există deja”**
  `Produse.dc.html:409` · *grav*
  Formularul își trece numărul produsului imediat ce serverul l-a creat, înainte de urcarea fotografiei; dacă poza e refuzată, mesajul spune că produsul a intrat deja în catalog și doar fotografia a căzut, lista se reîmprospătează, iar a doua apăsare trimite un PATCH pe produsul existent, nu un al doilea produs cu același nume. Probă în .claude/check-panouri.mjs: save() e scos din sursă și rulat cu un „gp” de mucava — a doua apăsare are voie să scoată un singur POST /products.

- [x] **Butonul „Încearcă din nou” din Catalog aruncă omul pe un ecran de refuz gol, din care nu se mai iese**
  `Produse.dc.html:1064` · *grav*
  Poarta are din nou toți patru termenii (`S.loaded && !!S.auth && !isAdmin && !S.eroare`) și perechea complementară `allowed`, iar butonul pune `loaded: false` odată cu stingerea erorii, deci pagina se întoarce la starea de așteptare în loc să afirme ceva. Verificat că `pull()` pune `loaded: true` pe amândouă drumurile, ca așteptarea să aibă capăt. Probă: `.claude/check-erori-tacute.mjs`.

- [x] **În ruta șoferului, meniul de zile arată „0 opriri" pentru toate zilele în afară de cea deschisă**
  `Ruta Sofer.dc.html:406` · *grav*
  Meniul de zile numără opririle de la server (GET /routes/counts), cu aceeași regulă ca ruta, nu din ziua deja încărcată.

- [x] **Ruta șoferului alege opririle după textul „Livrare” scris în cod, deci un mod de onorare redenumit din Setări golește ruta**
  `Ruta Sofer.dc.html:279` · *grav*
  Serverul hotărăște ce ajunge pe rută, după lista „moduri fără mașină” din Setări; un mod de onorare nou sau redenumit nu mai golește ruta.

- [x] **Panoul care n-a primit datele tace și apoi minte: refuzul serverului ajunge în `console.warn`, iar cardul de gol spune „Nicio livrare în ziua asta”**
  `Ruta Sofer.dc.html:339` · *grav*
  Hotărârea s-a mutat într-un singur loc: `gp.motiv(e, ce)` în `shared/gp-api.js`, care traduce 403/404/409/422/5xx/lipsa legăturii în română, numind lucrul care n-a venit. Cele cinci catch-uri o cheamă; fiecare pagină a căpătat `eroare: ""` în stare, o casetă `h-alert--danger` cu „Încearcă din nou”, iar fiecare stare goală a căpătat lacătul `&& !S.eroare`. Păzit de `.claude/check-erori-tacute.mjs`, care se dovedește singur: strică pe rând fiecare regulă și cere să iasă roșie (12 dovezi).

- [x] **Ziua cu care se deschid panourile se lua de pe ceasul telefonului, nu de pe ceasul patiseriei: pe alt fus orar, sertarul scria „2 opriri" și ecranul rămânea gol**
  `Ruta Sofer.dc.html:287` · *grav*
  Un singur ajutor, `gp.acumLaPatiserie()` (`shared/gp-api.js`), întoarce „acum" pe ceasul patiseriei, iar toate locurile care porneau de la `new Date()` îl cheamă pe el: `Ruta Sofer.dc.html`, `Panou Laborator.dc.html`, `Depozit.dc.html`, `Intrare.dc.html`, `Recomandari.dc.html`, `Site Public.dc.html`. Peste el stă și o pază de nepotrivire, care spune pe ecran când ziua cerută și ziua întoarsă de server nu se potrivesc — fiindcă `GET /routes` își echează ziua în răspuns (`json_out(["day" => $day, ...])`), iar `GET /orders` nu.

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

- [x] **Codul de recomandare deschis o dată dintr-un link se lipea de fiecare cerere trimisă de pe acel dispozitiv, treizeci de zile la rând**
  `Site Public.dc.html:1507` · *grav*
  Codul se alege după CINE e în cont (`codRecomandare()`), iar cookie-ul rămâne numai al omului fără cont. Trei ramuri, în ordine: (1) e din echipă → codul LUI, dacă are unul; altfel caseta rămâne goală — nu cade pe cookie nici când n-are cod, altfel angajatul de la tejghea ar scrie codul cuiva pe cererea fiecărui client venit la el; (2) e client → ce scrie pe contul lui („cine l-a adus"), şi atât, inclusiv când contul spune „nu l-a adus nimeni"; (3) n-are cont → abia aici contează cookie-ul. A doua jumătate: codul venit de pe LINK se stinge (`gp.forgetReferral()`) după ce cererea a plecat cu el, ca să nu se lipească de următoarea. Codul omului din echipă NU se stinge — nu vine din cookie, e pe contul lui. Nota de sub casetă spune de unde vine codul, în patru texte, ca să nu mintă în trei cazuri din patru.

- [x] **Personalul care lua comanda la client n-avea unde scrie adresa de livrare: caseta stătea în spatele unei porţi numai a clientului**
  `Site Public.dc.html:2682` · *grav*
  Un steag nou, `staff`, şi poarta lărgită: `campuriLivrare: (mine && detaliiDeschise) || staff`. Sunt ACELEAŞI două casete, nu o a doua pereche scrisă pentru personal — o pereche copiată ar fi pus două `id="q-addr"` în aceeaşi pagină, iar eticheta `for="q-addr"` ar fi trimis degetul la cea care nu e pe ecran. Cererea pleacă acum cu `address: (mine || staff) ? … : ''`, la fel şi zona. Şi o grijă în sens invers: adresa de pe cont se seamănă în casetă numai la client — adresa de pe contul unui administrator e a PATISERIEI, iar pusă singură acolo ar fi trimis comanda clientului înapoi la laborator, sub o casetă plină pe care nimeni n-ar mai fi avut de ce s-o citească. Textul de sub casetă e scris în două feluri, fiindcă adevărul e altul pentru fiecare: la client adresa rămâne pe cont, la personal rămâne pe cerere.

- [x] **Butoanele „Cere catalogul PDF" și „Scrie-ne" duceau la un formular care nu pleca fără produse în coș: omul care voia doar să întrebe ceva era trimis înapoi la catalog**
  `Site Public.dc.html:3348` · *grav*
  Hotărârea patronului: „Coș gol = mesaj, coș plin = cerere". Partea aceasta e pagina. Opritorul s-a îngustat la drumul pe care e adevărat: `if (!linii.length && this.state.finalizare)` — cine a apăsat anume „Finalizare comandă" și are coșul golit între timp e oprit, fiindcă acolo chiar e o greșeală, nu o alegere. Se întreabă STEAGUL `this.state.finalizare`, nu `finalizare` cel socotit la randare: al doilea are `picks.length > 0` în el, deci se stinge singur tocmai în clipa pentru care e pusă paza, iar opritorul n-ar mai fi căzut niciodată. Deosebirea asta e păzită de o regulă a ei, cu o stricăciune a ei în dovadă. Pe deasupra, butonul are acum trei etichete în loc de două — a treia, „Trimite mesajul", pentru coșul gol —, iar nota de sub el spune limpede ce pleacă: „Coșul e gol, deci pleacă o întrebare, nu o comandă. Pune produse în coș dacă vrei o ofertă pentru o zi anume."

- [x] **Coșul ascuns punea pagina să ceară un cadru nou la fiecare a șaisprezecea milisecundă, pentru totdeauna: o buclă fără capăt pornită de o simplă rotire a telefonului**
  `Site Public.dc.html:1783` · *grav*
  Hotărârea patronului: „Paza întreabă dacă e ceva de văzut". `stacked` devine adevărat și când coșul n-are cutie de așezare — `a.offsetParent === null` ori `a.offsetHeight === 0`. Bucla se oprește din prima trecere, pune `transform` gol și `this._off = 0`, exact ramura care exista deja. Cele două întrebări nu sunt una singură scrisă de două ori: `offsetParent === null` prinde ascunderea și scoaterea din curgere (`position: fixed`), iar `offsetHeight === 0` prinde coșul strâns într-un părinte fără înălțime, care ARE offsetParent. Stau ÎNAINTEA celorlalte două măsurători, fiindcă acelea sunt tocmai cele care mint pe zerouri. Repară oriunde s-ar ascunde coșul, nu doar pe telefon, și e chiar înțelesul pe care îl are deja numele `stacked`: „nu-l pluti".

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

- [x] **O plasă se înroșea la prima pagină care ar fi scris un literal în marcaj: `{{ true }}` era cerut ca o cheie a modelului, deși runtime-ul nu-l caută niciodată acolo**
  `.claude/check-panouri.mjs:4857` · *mediu*
  S-au scăzut `true`, `false`, `null` și `undefined` din mulțimea de nume cerute, lângă aliasurile blocurilor `<sc-for>` care se scădeau deja, cu motivul și trimiterea la `support.js:227-230` scrise deasupra. [13 septembrie 2026]

- [x] **O cerere lipsă dintr-un teanc omora tot fişierul de verificări: `find()` întorcea `undefined`, iar numărul ieşea „-1 · -1" în loc de un rând roşu**
  `.claude/check-panouri.mjs:4726` · *mediu*
  O carcasă goală în locul lipsei: `randuri.find(…) || { lipseste: 'cererea ' + id + ' nu e în teancul ăsta' }`. Verificarea de deasupra se face atunci roşie, cu numele cererii în mesaj, în loc să dea foc fişierului. Şi regula generală, scrisă ca să nu se mai nască a doua oară: într-un fişier de verificări, orice `find()` al cărui rezultat se dereferenţiază e o mină.

- [x] **Verificarea check-sql nu se uită niciodată la datele inițiale: regula caută „INSERT INTO”, iar seed-ul folosește „INSERT IGNORE INTO”**
  `.claude/check-sql.mjs:36` · *mediu*
  Verificatorul rescris: prinde și „INSERT IGNORE INTO”, numără valorile mergând pe adâncimea parantezelor (un rând poate ocupa mai multe linii), taie blocul la primul „ON DUPLICATE KEY” sau „;”, citește și coloanele adăugate prin ALTER TABLE din migrări, iar dacă nu mai găsește nicio inserare se plânge el însuși. Verifică acum 11 inserări, față de 0. Probe (dovada3): cu tiparul stricat apare „nicio inserare găsită”; cu o valoare ștearsă dintr-un rând real apare „rândul 6: 5 valori pentru 6 coloane”.

- [x] **Același ciot de contact era scris de mână în trei probe deodată, așa că fiecare câmp nou cerut de magazin le rupea PE RÂND: prima rupere se vedea și se repara, a doua ieșea abia la rularea următoare a suitei, și arăta ca o pagină stricată — „Cannot read properties of undefined" — când de fapt era doar o fixtură rămasă în urmă**
  `.claude/ciot-contact.mjs:1` · *mediu*
  Un fișier nou, `.claude/ciot-contact.mjs`, cu `ciotContact()` (contactul întreg, cu un `adaos` care se poate suprapune peste el, și peste `hours` anume) și `ciotZile()` (cele șapte zile, pentru probele care au nevoie de tabelul de program plin). Cele trei ciururi scrise de mână s-au dus; în locul lor stă `contact: ciotContact(),`. Valorile sunt vădit false — „Strada Probei 1", „0700 000 000" — dinadins: dacă o probă trece cu ele, înseamnă că pagina chiar citește ce i se dă, nu ce știe ea. `days` e `null`, nu un tablou, fiindcă așa arată răspunsul ÎNAINTE ca serverul să apuce să răspundă, iar probele trebuie să vadă tocmai clipa aceea; cine are nevoie de zile le cere anume. Iar cele două liste de chei, scrise alături, țin ciotul legat de rută: data viitoare când magazinul cere un câmp nou, cele două reguli se înroșesc o singură dată, în locul unde se repară o singură dată.

- [x] **Curățenia sărea în tăcere numele scrise printr-un parametru: 1746 de comenzi strânse pe trei zile, iar o probă a început să pice numai în suită**
  `.claude/curata-probe.mjs:210` · *mediu*
  Trei reparații, de la simptom spre cauză. (1) Cele trei nume lipsă s-au scris în `PREFIXE_DE_TEST` („Probă Comision %", „Probă Procent %", „Probă Bani %"). (2) Mai adânc: o CONVENȚIE, `curata-probe.mjs:210` — un nume care începe cu „Probă " sau „Proba " se șterge fiindcă așa se numește, fără să mai trebuiască găsit în cod. Niciun client adevărat nu se numește așa, iar scriptul refuză oricum să pornească pe altă bază decât cea de dezvoltare. Lista scrisă de mână rămâne plasă de siguranță pentru probele care nu țin convenția („Bistro Din Greșeală", „HAMMER zi"). Așa, orice probă viitoare e curățată chiar dacă `numeleDinProbe()` n-o poate citi. (3) `test-roluri.mjs:921` nu mai cere numai pagina întâi: citește paginile până la capăt, ca „administratorul le vede pe toate trei" să fie un răspuns despre drepturi, nu despre cât de aglomerată e ziua.

- [x] **O probă afirmă în titlu că un cont a fost șters, dar verifică doar că i-a primit numărul**
  `.claude/test-api.mjs:163` · *mediu*
  `check('contul temporar a fost șters', !!id, …)` afirma ștergerea în nume, dar verifica prezența numărului primit la creare, iar rezultatul lui `del()` se arunca. Acum: const sters = id ? await del(admin, '/users/' + id) : null; const dupa = await get(admin, '/users'); const maiExista = (dupa.data?.users || []).some((u) => u.username === tmpUser); check('contul temporar a fost șters', sters?.status === 200 && sters?.

- [x] **Trei probe își făceau curat cu DELETE pe o rută care nu există: fiecare cerere lua 405, nimeni nu se uita la răspuns, iar comenzile de probă rămâneau în bază la fiecare rulare**
  `.claude/test-defect-lista-comenzi.mjs:436` · *mediu*
  Curățenia anulează, nu șterge — adică face ce se poate face de fapt, pe aceeași cale ca panoul: PATCH pe comandă cu etapa de anulare, luată din `GET /lists` (`cancelled`), nu scrisă în cod, fiindcă se poate redenumi din Setări. Și, mai important, curățenia se VERIFICĂ: o verificare nouă cere ca toate comenzile probei să fi fost anulate. La `test-defect-raft-fantoma` una NU se poate anula, și e bine că nu se poate — e chiar comanda scenei, cea al cărei articol de depozit a fost scos, iar anularea ar întoarce marfa pe un raft care nu mai există; acolo verificarea cere ca orice refuz să fie `stock_return_missing_item` și niciun alt cod. Rândurile care oricum nu se pot scoate prin API rămân în seama lui `.claude/curata-probe.mjs`, care umblă direct în MySQL tocmai fiindcă API-ul nu are (și nu trebuie să aibă) o cale de ștergere definitivă.

- [x] **Probele care ating baza de date citesc `api/config.php`, deci se uită în „gp_dev” orice ar spune GP_BASE**
  `.claude/test-parola.mjs:46` · *mediu*
  Ajutorul comun .claude/baza-probei.mjs: `configulLuiBase()` află, după portul din `GP_BASE`, configurarea instanței care ascultă acolo (`instanta.mjs` își ține starea în %TEMP%/gp-instante/<nume>.json) și o pune în mediu ca `GP_CONFIG` — chiar la încărcarea modulului, fiindcă PHP-ul chemat de probă moștenește mediul procesului. `api/index.php` citea deja variabila asta, deci regula e aceeași peste tot: mediul are întâietate. Fragmentele `php -r` din probe s-au scris cu `CERE_CONFIG` (`(getenv("GP_CONFIG") ?: "api/config.php")`) în locul căii bătute în cuie, iar plasa de siguranță `/^(gp_dev|gp_test|gp_fresh)$/` s-a mutat în `BAZA_DE_PROBA`, care primește și bazele instanțelor (`gp_i_<nume>`) — altfel probele s-ar fi oprit singure cu „baza nu e una de dezvoltare”. Unsprezece fișiere trecute pe ajutorul comun (opt probe, curata-probe.mjs, aplica-sql.mjs), inclusiv trei care purtau fiecare câte o copie a aceleiași funcții. O valoare pusă de om din afară nu se atinge. Fără nicio instanță pornită nu se schimbă nimic: `GP_CONFIG` rămâne nepus și PHP cade tot pe `api/config.php`. Dovadă: suita întreagă, rulată cu `GP_BASE=http://127.0.0.1:8203/api`, dă tot 1509 trecute · 0 picate, iar curățenia raportează „Șters, din «gp_i_verifg»: 120 urme” — o bază pe care nu o atinsese niciodată înainte.

- [x] **test-parola moare cu o eroare de sintaxă MySQL pe o bază proaspătă, fiindcă așteaptă un cont pe care îl face altă probă**
  `.claude/test-parola.mjs:357` · *mediu*
  Proba își face singură contul dacă nu-l găsește: caută `testclient` în `users`, iar dacă lipsește intră ca administrator și îl creează prin API cu aceleași date pe care i le dă `test-api` (nume, client, telefon, adresă de e-mail — proba are nevoie și de adresă, fiindcă verifică „se cere link și după e-mail, nu doar după utilizator”). Dacă nici administratorul nu răspunde, proba se oprește spunând ce-i lipsește, nu cu o eroare de MySQL. Dovadă: pe instanța proaspătă de pe 8203, aceeași probă dă acum 51 trecute · 0 eșuate — cât și pe „gp_dev”.

- [x] **Migrarea care repară comisioanele de 0,00 RON era raportată „era deja aplicată" chiar la prima ei rulare: bilanțul măsoară schema, iar ea nu atinge schema**
  `actualizeaza-vps.sh:402` · *mediu*
  Hotărârea patronului dintre trei căi: „un rând al ei, cu valoarea". Nu o cifră de schemă mai bună, ci martorul însuși — după bilanț, scriptul citește `referral_rate_default` din bază și îl spune cu valoarea lui, deosebind ce a pus plasturele (`updated_by` NULL) de ce a hotărât un om. Patru ramuri: valoare pusă de casă (verde, cu procentul), valoare hotărâtă de om (rând simplu), zero hotărât de om (galben, cu urmarea lui scrisă pe față: fiecare comision nou se naște 0,00 RON și rămâne așa, fiindcă procentul se îngheață la naștere), valoare goală (galben). Rândul se taie pe TAB, nu cu `read`: mysql întoarce „<TAB>om" la o valoare goală, iar `read` ar fi sărit peste câmpul gol și ar fi scris „Procentul de recomandare: om%". O a noua regulă păzește cauza, nu leacul: dacă `017` capătă vreodată DDL, bilanțul o va vedea singur, iar regula cade ca să recitească cineva comentariul.

- [x] **Migrarea care deschide casetele de adresă și program era raportată „era deja aplicată" chiar la prima ei rulare: bilanțul măsoară schema, iar ea nu atinge schema**
  `actualizeaza-vps.sh:460` · *mediu*
  Un martor scris de mână, după bilanț și lângă cel al procentului: scriptul citește chiar `company_address` și `company_hours` din `settings`, cu `IF(updated_by IS NULL, 'casa', 'om')`, și le spune cu valoarea lor. Patru stări: rândurile lipsesc (galben — „migrarea 018 n-a intrat", plus ce urmează: Setările răspund bun și nu schimbă nimic), pusă de casă (verde, cu textul), scrisă de om (rând simplu, „am lăsat-o în pace"), golită de om (galben — site-ul cade înapoi pe temeiul din cod). Rândul se taie pe TAB cu expansiuni, nu cu `read CHEIE VAL CINE`: bash socotește TAB-ul drept spațiu alb chiar și cu `IFS` pus anume pe el, deci două TAB-uri lipite (valoarea goală) trec drept unul singur și „om" ajunge în locul valorii — raportul scria „Adresa publică: om". Și textele sunt două, fiindcă româna cere acordul: adresa e pusă, programul e pus; o singură formă scria „Programul de lucru e goală".

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

- [x] **Vederea „Rută șofer” din panoul de comenzi minte la fel: „Nicio comandă cu livrare în ziua asta”, când de fapt comenzile n-au venit**
  `Administrare Comenzi.dc.html:2547` · *mediu*
  Lacătul `&& !S.eroare`, cu comentariul care spune de ce.

- [x] **Cele trei butoane de sus din fila „Rută șofer" scriau ordinea zilei fără nicio poartă, deși `PUT /routes` e numai al administratorului**
  `Administrare Comenzi.dc.html:578` · *mediu*
  Cele trei butoane au intrat în spatele lui `<sc-if value="{{ potEdita }}">`, același steag ca butoanele ↑/↓. Cine doar vede ruta capătă în locul lor un rând care spune cine pune ordinea: „Ordinea opririlor o pune administratorul." Păzit de grupul B3 din `.claude/test-roluri.mjs`, care verifică și refuzurile serverului, și așezarea porții în marcaj (poarta trebuie să stea ÎNAINTEA celor trei butoane în felia filei).

- [x] **Eticheta grupei arată „0 produse”, dar ștergerea grupei e refuzată fiindcă serverul numără și produsele scoase din catalog**
  `api/lib/catalog.php:47` · *mediu*
  Serverul trimite acum două cifre pentru fiecare grupă — „products” (în catalog) și „productsAll” (toate, ca la refuzul ștergerii) — iar insigna din fila „Grupe” le arată pe amândouă: „2 în catalog · 1 scos”. Nota de sub listă spune și ea că produsele doar scoase din catalog opresc ștergerea. Probe în .claude/test-catalog.mjs (cifra din refuz e chiar cea din insignă) și în .claude/check-panouri.mjs (textul insignei, rulat din sursă).

- [x] **Un octet care nu e UTF-8 valid făcea răspunsul să iasă gol: refuzul nu se mai auzea**
  `api/lib/http.php:15` · *mediu*
  json_out() și json_out_early() trec printr-o funcție nouă, json_body(), care adaugă JSON_INVALID_UTF8_SUBSTITUTE și, dacă tot nu iese, scrie un JSON de eroare în locul tăcerii. O singură funcție imunizează toate rutele. Probe: .claude/test-reparat-lungimi-si-zile.mjs, secțiunea „octeți care nu sunt UTF-8" (măsurată dând reparația înapoi: 6 verificări pică, cu „lungime 0").

- [x] **Adresa expeditor pentru e-mail nu este validată nicăieri, deși intră brut în antetele mesajului**
  `api/lib/notify.php:165` · *mediu*
  Adresa expeditor se validează cu FILTER_VALIDATE_EMAIL în două locuri: la salvare, ca greșeala să fie prinsă în fața omului care o face, și la trimitere, fiindcă în bază poate sta deja o valoare pusă înainte. O singură verificare acoperă și adresa fără TLD, și CR/LF-ul care ar fi adăugat anteturi proprii în mesaj. Probe: test-settings „o adresă expeditor fără @ este respinsă → 422”, „cu salt de linie → 422”, „adresa bună a rămas neatinsă după respingeri”.

- [x] **Produsul și cantitatea se iau prin cast brutal: un tablou devine tăcut produsul #1, respectiv 1 bucată**
  `api/lib/orders.php:153` · *mediu*
  `price_items()` cere numere, nu le mai stoarce din ce-a venit (api/lib/orders.php): `productId` trebuie să fie întreg sau text de cifre, `qty` număr sau text numeric. `productId: [22, 21]` pică acum cu 422 `bad_product` în loc să devină tăcut produsul cu id 1, la alt preț, sub un 201 „comandă primită”. Textul din <input> trece mai departe, ca panoul să salveze ca înainte. Probă: .claude/test-reparat-campuri-comenzi.mjs, grupa „produsul și cantitatea”; dată înapoi reparația, proba pică pe 15 verificări, printre care exact dovada din fișă — „Ștrudel cu mere”, 3,49 lei.

- [x] **Pagina și serverul socoteau fiecare reducerea, iar `Math.round` și `round()` din PHP nu cad la fel pe jumătatea exactă — la −20% niciodată, deci defectul stătea adormit până la primul procent schimbat**
  `api/lib/promo.php:118` · *mediu*
  Leacul nu e o formulă mai bună, ci un singur loc. `promo_unit_price()` (api/lib/promo.php) socotește prețul unei bucăți, și e chemată și de `promo_slices()` — banii — și de `promo_units()`, care trimite prețurile gata socotite odată cu catalogul (`GET /products`). Pagina nu mai scade niciun procent: adună numere venite de la server. Aritmetica dublă a dispărut, nu s-a sincronizat. `Site Public.dc.html` ascunde pachetul dacă serverul nu i-a dat un preț pentru fiecare produs de pe ecran (`promoGata`), iar `.claude/check-site-public.mjs` îi dă anume o fixtură nepotrivită — preț redus 30 la un catalog de 50, cu `off: 20` scris alături — ca o pagină care s-ar apuca iar să socotească singură să iasă la 600 în loc de 450 și să pice. Probă: `.claude/test-defect-reducere-reala.mjs`, scena J.

- [x] **Scoaterea bifei de plată lasă comisionul înghețat pe cifrele vechi: pagina arată o bază și o sumă care nu mai sunt ale comenzii**
  `api/lib/referral.php:951` · *mediu*
  Scoaterea bifei de plată recalculează comisionul pe cifrele comenzii de acum, nu-l lasă înghețat pe cele vechi. Probă: `.claude/test-defect-recomandari-bani.mjs`.

- [x] **Jurnalul scrie „Sistem” în loc de numele administratorului la fiecare mișcare de bani din Recomandări**
  `api/lib/referral.php:958` · *mediu*
  Numele administratorului se duce până la `audit()` la toate cele patru apeluri, deci Jurnalul nu mai scrie „Sistem" la mișcările de bani din Recomandări. Probă: `.claude/test-defect-recomandari-bani.mjs`.

- [x] **Totalurile de bani din Recomandări se adună doar peste primele rânduri citite: peste prag, „Câștigat” și „De încasat” arată mai puțin decât realitatea**
  `api/lib/referral.php:1053` · *mediu*
  Totalurile „Câștigat", „De încasat" și „Plătit" se socotesc peste tot istoricul, nu peste rândurile aduse în pagină. Probă: `.claude/test-defect-recomandari-bani.mjs`.

- [x] **Un rând de comision greșit nu se putea scoate din socoteală: ștergerea de-a dreptul s-ar fi rescris singură la prima atingere a comenzii, iar suma „de plată" rămânea umflată pentru totdeauna**
  `api/lib/referral.php:905` · *mediu*
  Se scrie FAPTUL, nu se șterge urmarea. `sql/012_comision_scos.sql` adaugă pe rând `dismissed_at` și `dismissed_by`; un fapt scris nu se mai poate recalcula, deci cârligul nu-l mai poate desface. E aceeași hotărâre ca la comenzi („comenzile nu se șterg, se anulează"), din același motiv: o ștergere care se desface singură e mai rea decât lipsa ei, fiindcă prima dată o crezi. `DELETE /referrals/#` e ce apasă omul; dedesubt e o tăietură care ține minte, cu perechea ei, `POST /referrals/#/restore`. Cârligul iese din treabă pe tăcute la începutul lui `referral_commission_sync()`, dar DUPĂ ce comanda își scrie codul tastat — câmpul comenzii rămâne cinstit, chiar dacă nu mai naște bani. Refuzul îl spune ruta, nu cârligul: `referral_guard_dismissed_code()` stă lângă celelalte două porți, înaintea oricărei scrieri, fiindcă `PATCH /orders` își scrie singur `referral_code` și cheamă cârligul cu `null` (api/routes/orders.php:1123) — înăuntru nu se poate ști că tocmai a tastat cineva ceva. Poarta e îngustă: același cod retrimis trece, iar restul câmpurilor comenzii se editează mai departe. Filtrarea trece prin `referral_dismiss_cond()`, care întoarce TEXT SQL, nu un parametru: pe o bază fără 012 un `?` n-ar putea să dispară, iar NUMELE coloanei ar fi o eroare de sintaxă, nu o comparație care iese falsă. Plătit și scos nu se pot suprapune niciodată, prin construcție: un rând plătit nu se scoate (409 `commission_paid`), iar unul scos nu se poate plăti — e cernut și din citire, și din UPDATE — deci nicio socoteală de bani n-are de ales între ele. În pagină, patronul vede rândul TĂIAT, cu o pastilă „scos" și un buton „Adu înapoi", fiindcă ascuns cu totul ar fi fost un cimitir din care nu se mai iese: singurul om care îl poate aduce înapoi e chiar cel de care l-am ascunde. Totalurile nu-l mai numără. Agentul nu-l mai vede deloc — el n-are buton de adus înapoi. Aducerea înapoi recitește comanda (`referral_commission_sync(..., false)` — fără să lege contul cumpărătorului), fiindcă în timpul cât a stat tăiat comanda a putut crește, se putea anula ori își putea pierde codul; recitirea poate și să stingă rândul, pe drept. Probă: `.claude/test-defect-comision-scos.mjs`, 62 de verificări.

- [x] **Caseta procentului de recomandare, golită dinadins din pagina Setări, cădea înapoi pe rezerva casei (5%) — iar scriptul de pe server îi spunea patronului, în aceeași zi, exact pe dos**
  `api/lib/referral.php:599` · *mediu*
  Hotărârea patronului: „Golul înseamnă zero" — caseta goală chiar oprește comisioanele. `api/lib/settings.php` capătă `setting_raw($key)`, care întoarce null NUMAI când rândul lipsește cu adevărat; `setting()` rămâne neatinsă, fiindcă pentru restul setărilor purtarea ei e cea bună. `referral_default_rate()` are acum trei ramuri, în ordinea în care se pun întrebările: rândul lipsește → 5 (rezerva casei, aceeași cifră cu cea semănată de `sql/017`, ca un server căruia i-a scăpat migrarea să nu socotească tăcut la zero); caseta e goală → 0 (hotărârea omului); altfel → cifra lui, tăiată în intervalul 0–100. Zeroul SCRIS și caseta GOLITĂ ajung dinadins în același loc: sunt aceeași hotărâre, spusă în două feluri. Ajutorul din `Setari.dc.html` o spune acum pe față („sau caseta lăsată goală, care înseamnă același lucru"), iar mesajul din `actualizeaza-vps.sh:422` a devenit adevărat fără să fie atins. Probe: secțiunea 10 din `.claude/test-recomandari.mjs` (10 reguli noi) plus regula celuilalt fel de gol — rândul șters din tabel, care cere o mână de bază fiindcă pagina Setări nu șterge rânduri (79 → 91). Fiecare dovedită că se înroșește pe codul stricat cu patru stricăciuni țintite, una dintre ele chiar rândul de dinainte de reparație, iar alta schimbând cele două goluri între ele.

- [x] **Codul de recomandare se scotea numai din PRENUME: la al cincizeci și unulea „Maria" nu se mai putea face niciun cod, iar refuzul îi cerea omului să-și schimbe numele contului — lucru pe care nu-l poate face nimeni, fiindcă nu există ruta**
  `api/lib/referral.php:190` · *mediu*
  Trei fântâni în loc de una, încercate în ordine: prenumele, apoi numele întreg, apoi numele de utilizator (`referral_code_bases()`). Fiecare se taie la 58 de litere, fiindcă la coadă se mai adaugă până la două cifre într-o coloană de 64; o fântână care dă același lucru ca alta nu se încearcă de două ori, și una care nu dă nimic nu intră deloc. Prima rămâne exact ce întorcea `referral_code_candidate()` înainte, deci pentru oricine se înscrie azi nu se schimbă nimic: „Maria" ia tot „maria". Abia când o fântână e secată se trece la următoarea, cu un rând în jurnal care spune de ce. Apoi `referral_assign()` capătă un al patrulea parametru, `$pricina`, prin care spune CARE din patru lucruri s-a întâmplat — `fara_tabel`, `nume_gol`, `fara_cont`, `toate_luate` — iar `referral_self_assign()` îl duce mai departe, ca butonul „fă-mi un cod" să nu rămână cu refuzul general. Cele două refuzuri se despart: pe `toate_luate` textul spune adevărul („numele e prea des întâlnit"), iar pe restul spune celălalt adevăr („nu iese nimic din nume"). Și amândouă trimit acum spre o ușă care CHIAR există: administratorul e dus la „Alege codul", de lângă cont; clientul e dus la administrator, fiindcă el poate. Sfatul „schimbă-ți numele contului" a dispărut din amândouă.

- [x] **O coordonată de zonă lăsată goală este acceptată ca 0, nu respinsă — oprirea ajunge în Golful Guineei**
  `api/lib/settings.php:154` · *mediu*
  isset('') e true și (float) '' e 0, deci o casetă de coordonată golită nu era ignorată, ci păstrată ca 0 — un punct din Golful Guineei: piciorul de rută ieșea de mii de kilometri și „Optimizează ruta” rearanja opririle față de el. Reparat în două locuri: zone_coord() întoarce null pentru gol, text sau zero (și acceptă virgula zecimală românească, care înainte tăia partea fracționară), iar guard_zone_coords() refuză cu 422 punctele stricate de chiar salvarea curentă, numind zona. Zonele deja salvate cu 0,0 nu blochează nimic: dispar din hartă, deci oprirea cade pe depozit. Probe: test-settings, blocul „coordonatele zonelor” (10 verificări).

- [x] **Setările nu aveau nicio margine de lungime, iar salvarea rămânea pe jumătate**
  `api/lib/settings.php:310` · *mediu*
  Se măsoară EXACT ce urmează să se scrie — după json_encode, după criptare — cu o margine până la 60000 de octeți, și se refuză cu 422 setting_too_long, spunând care cheie e de vină. Salvarea are acum DOUĂ treceri: întâi se pregătește și se cântărește tot, abia apoi se scrie, deci e totul sau nimic. Probe: .claude/test-reparat-lungimi-si-zile.mjs, secțiunea „setările" (măsurate separat: marginea, apoi cele două treceri).

- [x] **O cheie fără rând în tabel era sărită în tăcere la salvare: serverul răspundea „ok" despre o scriere care nu s-a întâmplat**
  `api/lib/settings.php:377` · *mediu*
  Hotărârea patronului: „Rândurile, ȘI un refuz lămurit". Partea aceasta e refuzul. `continue`-ul tăcut a ajuns `fail(..., 422, 'unknown_setting')`, cu un mesaj care numește cheia pe nume, spune că pricina cea mai deasă e o migrare lipsă din `sql/` și trimite la `docs/09-actualizare-vps.md`. Se refuză ÎNTREG, ca toate celelalte verificări de acolo: formularul de Setări se trimite dintr-o bucată, deci n-are rost să scriem jumătate din el și să ne plângem după — de-aia refuzul stă în prima trecere, cea care cântărește, nu în a doua, cea care scrie. Plasa panoului („cheile căzute") rămâne la locul ei: ea prinde orice altă tăcere care ar mai apărea între panou și server.

- [x] **Oricine poate bloca de la distanță deschiderea tuturor tabletelor din laborator**
  `api/routes/auth.php:75` · *mediu*
  Codul tabletelor se numără acum pe conexiune: panel_rate_limited() din api/lib/auth.php contorizează pe IP ȘI pe pseudo-utilizatorul „panou”, iar record_attempt() nu mai șterge global greșelile lui la o intrare reușită. Cine ghicește codul își blochează doar propria conexiune, iar tableta din laborator deschide mai departe. Probe în .claude/test-api.mjs: 20 de greșeli de pe o adresă → 429 pentru ea, 200 pentru tabletă, iar intrarea bună a tabletei nu deblochează conexiunea care ghicește.

- [x] **Tableta din laborator se deconecta singură la șapte zile: lua durata de sesiune a personalului, deși nu e dispozitivul niciunui om**
  `api/routes/auth.php:368` · *mediu*
  Trei bucăți. (1) `session_days_panou()` în `api/lib/auth.php` — un termen al tabletei, un an, cu valoarea implicită scrisă ÎN COD, fiindcă `api/config.php` de pe server nu se suprascrie niciodată la actualizare, deci cheia nouă nu apare acolo singură. `session_days_for()` capătă un al doilea parametru, `$sistem`, ca și `do_login()` să dea durata bună dacă vreodată se intră altfel. (2) `prelungeste_sesiunea_panoului()`, chemată din `current_user()` numai când `is_system = 1`: la fiecare cerere venită de pe tabletă, dacă a trecut o zi de la ultima împrospătare, termenul se pune din nou întreg — și în rând, și în cookie. Asta e miezul: un număr mare de zile NU ajunge, fiindcă browserele taie durata unui cookie la 400 de zile (Chrome 104 și urmașele, după RFC 6265bis), deci rândul ar fi trăit un an iar tableta ar fi pierdut cookie-ul oricum. Panourile întreabă serverul la 15-20 de secunde, deci o tabletă folosită nu ajunge niciodată la capăt. (3) Pragul de-o zi, ca să nu se scrie în tabelă de patru mii de ori pe zi de fiecare tabletă. Reparația e îngustă dinadins: sesiunea unui OM nu se împinge înainte, iar ștergerea de la schimbarea codului (`api/lib/settings.php`) rămâne neatinsă — e acum singura cale prin care se închide o tabletă.

- [x] **Un preț scris cu litere e salvat tăcut ca 0,00 lei, iar comanda se înregistrează cu 0 lei**
  `api/routes/catalog.php:449` · *mediu*
  Prețul se citește acum cu o verificare adevărată, nu cu un (float) tăcut: textul trebuie să arate a număr (virgula e primită mai departe), iar zero și valorile negative sunt refuzate cu 422 și un mesaj care cere cifre. Aceeași închidere e folosită și la creare, și la editare. Probă în .claude/test-catalog.mjs: „la cerere” și 0 sunt respinse, produsul stricat nu intră în catalog, „2,85” se salvează ca 2.85, iar o editare respinsă lasă prețul vechi neatins.

- [x] **Jurnalul scria CINE și PE CE, dar niciodată CE: o modificare de preț, o retrogradare de rol și o corectură de virgulă lăsau exact aceeași urmă**
  `api/routes/catalog.php:318` · *mediu*
  `audit_diff()`, pusă în bibliotecă lângă `audit()` (api/lib/auth.php), citește înapoi cele două tablouri paralele din care se face orice UPDATE al casei — `$sets` cu „coloană = ?" și `$vals` cu valorile — și le pune lângă rândul vechi, deja citit cu SELECT *. Scrisă o dată, merge la fel în conturi, catalog și depozit, și nu se poate depărta între ele. Se scrie NUMAI ce chiar s-a schimbat: paginile salvează la ieșirea din casetă, deci trimit și câmpuri neatinse, iar scrise necondiționat ar îneca rândul căutat în „nume: Ana → Ana". Etichetele sunt omenești („preț raft", „prag minim", „stare"), iar acolo unde cifra nu spune nimic se traduce: „stare: activ → oprit", nu „active: 1 → 0", și „produs legat: — → Croissant", nu id-ul brut. Trei capcane, fiecare cu verificarea ei: un DECIMAL vine din MySQL ca „12.50" și din cerere ca 12.5, deci numerele se aduc la aceeași formă — dar numai cele cu punct, fiindcă un telefon ca „0740123456" e și el „numeric" pentru PHP, iar trecut prin aceeași pâlnie ar ieși „740123456" pe amândouă capetele deodată și o pierdere de zero din față n-ar mai lăsa nicio urmă. La Setări comparația se face pe TEXTUL CURAT, nu pe ce se scrie în bază: criptarea are vector de inițializare aleatoriu, deci același token criptat de două ori dă șiruri diferite, iar o comparație pe ciphertext ar raporta „schimbat" la fiecare salvare a fiecărui secret. Și valoarea unui secret nu se scrie NICIODATĂ — nici veche, nici nouă: jurnalul îl citește orice administrator și nu se șterge niciodată, deci se spune doar „pus", „schimbat" ori „șters". O salvare care n-a schimbat nimic o spune pe față („nimic schimbat"), în loc să pară o modificare. Probă: `.claude/test-defect-jurnal-orb.mjs`, 21 de verificări.

- [x] **Căutarea de clienți cerea coloana zonei fără poarta de migrare pe care o au toate vecinele ei — deci pe o actualizare rămasă pe jumătate, fiecare căutare cădea cu „Eroare internă"**
  `api/routes/clients.php:104` · *mediu*
  Hotărârea patronului: „Aceeași poartă ca vecinele". Coloana a ieșit din lista fixă și a intrat într-un `$zona = user_zone_supported() ? ', u.zone' : ", '' AS zone"`, scris exact ca sora lui de deasupra, `$semn`. Fără coloană se întoarce șirul gol, nu se scoate cheia: răspunsul păstrează ACEEAȘI formă întotdeauna, iar pentru cel care ia comanda e ca înainte de migrarea 016 — caseta zonei rămâne goală și o alege el. O comoditate care lipsește n-are voie să oprească vânzarea. Probă nouă, `.claude/test-baza-veche.mjs` (49 de reguli), pusă și în `verifica-tot`: e SINGURA probă a casei care nu rulează pe serverul de la 8100 — își ridică singură o instanță cu baza nemigrată, stinge-o la plecare, și cere fiecăreia din cele 25 de uși de citire din `api/index.php` să NU răspundă „Eroare internă". 404, 403 și mai ales 503 sunt răspunsuri cinstite; în plus, fiecare 503 trebuie să spună ce fișier SQL lipsește. Fără proba asta, defectul nu putea fi prins de nimic: toate celelalte probe lucrează pe `gp_dev`, adică pe o bază MIGRATĂ, unde porțile n-au ce apăra. Dovedită cu trei stricăciuni — codul de dinainte de reparație, poarta pusă pe dos, și ramura fără coloană — iar dovada a prins pe drum și o regulă vacuă a mea: „nimic despre bani nu pleacă pe aici" trecea de la sine pe un rând gol, adică tocmai când căutarea crăpase.

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

- [x] **Lista de comenzi e tăiată la 500 fără niciun semn, iar tăietura cade tocmai pe zilele cele mai apropiate**
  `api/routes/orders.php:65` · *mediu*
  Lista de comenzi spune pe față când s-a tăiat și se poate citi de la capătul cerut, deci zilele apropiate nu mai cad în afara feliei fără niciun semn. Paginarea adevărată pe server a rămas dinadins pe altă dată. Probă: `.claude/test-defect-lista-comenzi.mjs`.

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
  Marginea de sus există acum în amândouă ușile. Pe drumul personalului, `valid_day_not_too_far()` (`api/lib/http.php:404`) refuză cu `422 day_too_far` orice zi de după fereastra `GP_ORIZONT_ZILE` — chemată la `POST /orders` (`api/routes/orders.php:465`) și la mutarea zilei din `PATCH /orders` (`:903`); marginea de jos nu se pune acolo dinadins, fiindcă administratorul corectează și comenzi de ieri. Pe drumul clientului, `valid_order_day()` le pune pe amândouă, și la trimiterea cererii (`api/routes/requests.php:300`) și la acceptarea ei (`:556`) — o cerere veche, primită înainte ca fereastra să existe, e oprită și la acceptare, nu doar la intrare.

- [x] **Butonul „Am văzut” stinge și anunțurile eșuate pe care nu le-a văzut nimeni**
  `api/routes/settings.php:60` · *mediu*
  „Am văzut" primește lista de id-uri și stinge exact anunțurile care au fost arătate; o listă goală nu mai golește caseta. Probă: `.claude/test-defect-anunturi-esuate.mjs`.

- [x] **Comanda anulată după ce a fost scăzută din depozit nu întoarce marfa, iar registrul nu mai poate fi îndreptat**
  `api/routes/stock.php:368` · *mediu*
  Aceeași reparație: ieșirea din etapa de încheiere — cel mai des pe „Anulată” — cere returul, iar marfa se întoarce în depozit cu motivul „Retur: comandă ieșită din «…»”, purtând în `note` referința comenzii, ca perechea ei de ieșire. Scăderea următoare nu mai atinge comanda anulată; repusă pe etapa de încheiere, se scade din nou o singură dată. Motivul se taie la 96 de caractere, cât ține `stock_moves.reason`, ca o etapă lungă din Setări să nu transforme anularea în „Eroare internă”. Probă: .claude/test-defect-zavor-stoc.mjs, scena a doua.

- [x] **Datele prea lungi din depozit ies ca „Eroare internă”, nu ca refuz explicat**
  `api/routes/stock.php:57` · *mediu*
  Câmpurile depozitului au acum margini verificate acolo unde încă se poate spune ce e greșit (api/routes/stock.php): numele articolului și denumirea din registru prin `text_field()` la 160 de caractere, unitatea la 16, motivul unei mișcări la 96 și mențiunea la 255. Pragul minim și cantitatea trec printr-un ajutor nou, `num_field()` (api/lib/http.php, lângă `need()`): cere un număr adevărat — `(float)` pe un tablou dădea 1.0, deci un câmp completat greșit intra tăcut ca prag de o bucată — și îl ține în marginile lui DECIMAL(10,2), ca 1264 „Out of range" să nu mai ajungă „Eroare internă". `route_items_update` verifică și unicitatea numelui, ca redenumirea peste alt articol să răspundă tot 409 `name_taken`, nu 500: aceeași greșeală omenească nu mai are două răspunsuri, după ușa pe care ai intrat. Probă: .claude/test-reparat-depozit-refuzuri.mjs (31 de verificări; dată înapoi reparația, pică 17).

- [x] **Numele articolului scos din depozit rămâne ocupat pentru totdeauna, iar nicio pagină nu-l poate readuce**
  `api/routes/stock.php:128` · *mediu*
  Numele unui articol scos din depozit se poate lua înapoi, după modelul deja folosit la catalog. Probă: `.claude/test-defect-depozit-nume-prag.mjs`.

- [x] **Pragul minim implicit din Setări intră fără nicio verificare: „0,5” devine 0, iar un număr prea mare dă „Eroare internă”**
  `api/routes/stock.php:127` · *mediu*
  Pragul minim implicit se verifică acolo unde se SCRIE, nu unde se citește: „0,5" e refuzat pe loc, iar un număr prea mare iese ca refuz lămurit, nu ca „Eroare internă". Probă: `.claude/test-defect-depozit-nume-prag.mjs`.

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

- [x] **Pagina clientului decide „ridicare” după numele „Ridicare” și „livrare” după numele „Livrare”, deci o comandă „Angro” i se spune clientului că se livrează**
  `Client.dc.html:805` · *mediu*
  „O ridici" sau „se livrează" se hotărăște din lista de moduri de ridicare din Setări (`CU_MASINA` → `gp.pickupFulfillments()`, Client.dc.html:376), nu din numele scris în cod, deci o comandă „Angro" nu i se mai spune clientului că se livrează. Probe: `.claude/test-defect-pagini-client.mjs`, `.claude/check-panouri.mjs`.

- [x] **Butonul „Încearcă din nou” pune pe loc formularul de intrare în fața unui client care E conectat**
  `Client.dc.html:942` · *mediu*
  Butonul pune `eroare: ""`, `cerEroare: ""` și `loaded: false` înainte de a chema cererea, deci pagina se întoarce la starea de așteptare în loc să afirme ceva despre sesiune. Probă: `.claude/check-erori-tacute.mjs`.

- [x] **O singură cerere pierdută din bucla de cinci secunde ia formularul de intrare de sub degetele vizitatorului**
  `Client.dc.html:839` · *mediu*
  Un câmp nou, `sesiuneStiuta`, se face adevărat în amândouă drumurile pe care serverul chiar a răspuns, și nu se mai întoarce niciodată. Lacătul e el, nu lipsa unui motiv: un răspuns primit o dată nu se mai uită. Probă: `.claude/check-erori-tacute.mjs`.

- [x] **Cererile trimise din magazin dispăreau de pe ecran în tăcere, iar în locul lor se aprindea „Nicio comandă activă”**
  `Client.dc.html:588` · *mediu*
  Catch-ul întoarce acum un răspuns cu steag: pe picare lista de dinainte rămâne neatinsă și motivul pleacă în câmpul nou `cerEroare`, desenat în aceeași casetă. Cardul de gol are amândouă lacătele, iar `eroareTitlu` spune care dintre cele două cereri a picat. Probă: `.claude/check-erori-tacute.mjs`.

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

- [x] **Butonul „Încearcă din nou” din Conturi reaprinde pe loc subtitlul „0 conturi · toate cu parolă proprie”, peste o pagină goală**
  `Conturi.dc.html:1657` · *mediu*
  Butonul pune `eroare: ""`, `pCodeErr: ""` și `loaded: false`, apoi cheamă amândouă cererile din funcția de după. Ca așteptarea să aibă capăt, drumul de reușită al lui `pullUsers()` a primit și el `loaded: true` — fără el, o reîncercare izbutită ar fi lăsat pagina în așteptare pentru totdeauna. Probă: `.claude/check-erori-tacute.mjs`.

- [x] **După o salvare izbutită urmată de o reîmprospătare picată, ecranul poartă trei propoziții care se bat cap în cap**
  `Conturi.dc.html:877` · *mediu*
  Catch-ul se uită acum la ce e pe ecran și la ce tocmai s-a făcut: când lista e desenată, motivul spune că lista nu s-a reîmprospătat și adaugă „Fapta de mai sus s-a făcut pe server; ce se vede în listă e starea de dinainte.” Titlul vine din `eroareTitlu` și spune „nu s-a reîmprospătat” în loc de „nu s-au încărcat”. Probă: `.claude/check-erori-tacute.mjs`.

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

- [x] **Registrul depozitului rotunjește la o zecimală: consumul de drojdie apare ca „−0 kg”**
  `Depozit.dc.html:573` · *mediu*
  Registrul rotunjește la patru zecimale și taie zerourile din coadă (`fmt`, Depozit.dc.html:603), cu virgulă ca în restul aplicației, deci consumul de drojdie nu mai apare ca „−0 kg". Rotunjirea la zece miimi taie și zgomotul de virgulă mobilă. Probă scrisă înainte de reparație și văzută picând pe codul de atunci.

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

- [x] **Caseta mare spune „Jurnalul nu s-a încărcat.” peste cincizeci de rânduri încărcate, care se văd chiar sub ea**
  `Jurnal.dc.html:678` · *mediu*
  Măsura e acum `log.length`, nu numărul paginii cerute: când e ceva pe ecran, motivul se scrie despre RÂNDURILE NOI, iar titlul casetei vine din cheia nouă `eroareTitlu`, care spune „nu s-a reîmprospătat” în loc de „nu s-a încărcat”. Probă: `.claude/check-erori-tacute.mjs`.

- [x] **Numerele de pe filtre scriu „· 0” cât timp cererea n-a venit — exact zeroul pentru care subtitlul de deasupra a fost lăcătuit**
  `Jurnal.dc.html:567` · *mediu*
  Contoarele trec acum printr-un `numar(n)` care întoarce șir gol cât timp `!S.loaded || S.eroare`, iar eticheta opțiunii pune numărul doar când există. Filtrele rămân întregi și apăsabile — se schimbă numai cifra, care tace în loc să mintă. Probă: `.claude/check-panouri.mjs`.

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
  Cantitatea nu se mai citește cu `parseInt` nicăieri: toate paginile trec prin `gp.qty()` (`shared/gp-api.js:1280`), care face `parseFloat` după ce înlocuiește virgula cu punct, iar afișarea trece prin `gp.qtyLabel()` (`~:1314`) — „10”, nu „10.00”, și „0,5” cu virgulă, rotunjit la două zecimale ca totalurile adunate din poziții să nu iasă 0,30000000000000004 pe ecranul laboratorului. `grep -n parseInt "Panou Laborator.dc.html"` nu mai întoarce nimic. Proba: `.claude/test-defect-cantitati-zecimale.mjs`, în suita statică.

- [x] **Butonul de la ultima etapă din laborator scria în jurnal o trecere care nu s-a întâmplat: „Predată → Predată”, într-un registru care nu se golește niciodată**
  `Panou Laborator.dc.html:1006` · *mediu*
  Reparat în amândouă capetele, fiindcă sunt două greșeli. În pagină: `advance()` se oprește cu `if (nx === cur) return;` — paza stă în funcție, nu numai în marcaj, fiindcă funcția e chemată din două locuri (cardul din listă și foaia de detaliu); iar steagul nou `advanceOff: st === gp.done()` stinge amândouă butoanele, ca să se și VADĂ că nu mai e nimic de apăsat. Pe server (`api/routes/orders.php:1191`, `:1253`): rândul de jurnal numește etapa numai când etapa chiar s-a schimbat (`$etapaNoua`), iar o cerere care aduce NUMAI o etapă, și aceeași, nu mai scrie deloc în registru. Poarta e îngustă dinadins: orice altă salvare scrie mai departe, chiar și când detaliul rămâne un „Comandă #84" simplu — puțin, dar adevărat. Păzit de `.claude/test-flux-complet.mjs`, care apasă chiar butoanele.

- [x] **Lista din laborator se oprea la 500 de comenzi fără să spună nimic: peste prag, comenzile pur și simplu lipseau din tură**
  `Panou Laborator.dc.html:652` · *mediu*
  Panoul citește `r.truncated` într-un steag de stare (`taiat`) și îl spune pe ecran, ca „Administrare Comenzi".

- [x] **Panourile de tură n-aveau nicio poartă de rol în pagină: clientul care tasta adresa laboratorului își vedea propriile comenzi așezate ca o coadă de producție**
  `Panou Laborator.dc.html:48` · *mediu*
  Amândouă panourile au căpătat același ecran de refuz ca „Administrare Comenzi", cu același marcaj, și un ajutor `vedePanoul()` care citește matricea meniului (`gp.PANOURI_ROL`), nu o listă scrisă a doua oară: dacă mâine „Laborator" iese din meniul unui rol, poarta se închide singură. Trei amănunte care fac diferența: (1) poarta se aprinde pe „ȘTIU și nu are" (`!!S.auth && !vedePanoul(S.auth)`), niciodată pe „nu știu" — altfel tableta din tură, care intră pe contul comun `panou`, s-ar închide singură cât timp `gp.me()` e pe drum; (2) `pull()` se oprește la poartă, ca panoul să nu mai ceară la fiecare patru secunde ce n-are cum să afle; (3) cartonul „ziua e goală" a căpătat lacătul `!faraAcces`, fiindcă o afirmație acoperită de o perdea e tot o afirmație. Ecranul trimite omul unde are ce face, cu `gp.primaPagina()` — aceeași socoteală care-l duce acolo după autentificare.

- [x] **Butonul × nu șterge niciodată definitiv un produs: ruta DELETE /products/{id} nu e apelată de nicio pagină, deși documentația promite ștergerea**
  `Produse.dc.html:473` · *mediu*
  × de pe rând cere acum DELETE /products/{id}, iar serverul alege între ștergerea de tot (cu fotografie cu tot) și scoaterea din catalog, după cum produsul apare sau nu în comenzi; mesajul afișat este al lui, ca pagina să nu promită una și să se întâmple alta. ↩ repune produsul, ca înainte. Probă în .claude/check-panouri.mjs: handlerul butonului și removeProduct() sunt scoase din sursă și rulate — se urmărește ce verb pleacă și ce citește omul după.

- [x] **Comentariile de deasupra porții descriu alt cod decât cel de sub ele și contrazic pagina soră**
  `Produse.dc.html:1055` · *mediu*
  Comentariile sunt rescrise pe codul de azi și spun de ce fiecare dintre cei patru termeni e acolo, cu trimitere la pagina soră ca la o scriere identică, nu ca la una deosebită.

- [x] **Lângă titlul rețetei scrie „fără materii prime” și atunci când rețeta n-a putut fi citită**
  `Produse.dc.html:1225` · *mediu*
  Numărătoarea tace până când `recipeReady` e adevărat: cât nu se știe, nu se scrie nimic. Probă: `.claude/check-panouri.mjs`.

- [x] **Contul de cumpărător făcut din „Recomandări" de un admin sau fondator nu se leagă niciodată de codul lui, deși pagina anunță că s-a legat**
  `Recomandari.dc.html:843` · *mediu*
  Contul de cumpărător făcut din „Recomandări" se leagă de codul celui care l-a făcut și când acela e admin sau fondator, nu doar agent — deci pagina nu mai anunță o legare care nu s-a întâmplat. Probă: `.claude/test-defect-cont-legat.mjs`.

- [x] **Comisionul unei comenzi anulate se poate bifa „plătit", iar banii plătiți nu intră niciodată în placa „Plătit"**
  `Recomandari.dc.html:760` · *mediu*
  Comisionul unei comenzi anulate nu se mai poate bifa „plătit", iar banii chiar plătiți intră în placa „Plătit". Probă: `.claude/test-defect-recomandari-bani.mjs`.

- [x] **Comentariul din `pull()` descrie codul șters și numește un câmp care nu mai există — iar cine l-ar crede ar întoarce defectul în pagină**
  `Recomandari.dc.html:702` · *mediu*
  Comentariul e rescris pe codul de azi: numește cele două câmpuri adevărate, spune că `faraCod` e nelăcătuit dinadins, și avertizează în litere că un lacăt scris cu numele mort ar fi `!undefined`, adică mereu deschis.

- [x] **Un rând venit fără procent scria chiar cuvântul „undefined" lângă banii omului: „900,00 RON × undefined%"**
  `Recomandari.dc.html:602` · *mediu*
  Lipsa TACE: `procentRand()` întoarce şir gol pentru `null`, `undefined`, gol şi pentru orice nu e număr finit, iar `socotealaRand()` nu mai scrie nimic fără el. Un zero pus în locul lipsei ar fi fost mai rău decât „undefined": ar fi AFIRMAT că procentul e zero, adică „din comanda asta nu-ţi vine nimic" — ceva ce nimeni nu ştie şi ce omul ar fi crezut, fiindcă e scris cu cifre. Şi ce a venit se scrie CUM a venit: „5.00" de la server rămâne „5,00%", nu devine „5%"; trecerea prin `Number` e numai judecată, nu rescriere.

- [x] **Zilele din pagina șoferului se calculează o singură dată: peste noapte tableta arată ruta de ieri**
  `Ruta Sofer.dc.html:169` · *mediu*
  Zilele din pagina șoferului se recalculează, nu se mai îngheață la încărcare. Tableta lăsată deschisă peste noapte arăta ruta de ieri, iar șoferul pleca pe livrările zilei trecute.

- [x] **Numărătoarea din meniul șoferului spunea „0 opriri” și când n-o știa: refuzul la `/routes/counts` era tăcut**
  `Ruta Sofer.dc.html:330` · *mediu*
  Steag nou `contorRupt` în starea paginii; când numărătoarea pică, meniul scrie „—” în loc de „0 opriri”. Semnul spune „nu știu”, nu „nimic”.

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

- [x] **Redenumirea unei zone care trece prin numele altei zone îi șterge acesteia punctul de pe hartă**
  `Setari.dc.html:970` · *mediu*
  Redenumirea unei zone nu se mai comite la fiecare literă, deci o zonă care trece prin numele alteia nu-i mai șterge punctul de pe hartă — același tipar ca reparația deja probată din Catalog. Probă: `.claude/test-defect-zona-punct.mjs`.

- [x] **Lista anunțurilor eșuate arată numărul intern al comenzii, nu referința pe care o vede omul**
  `Setari.dc.html:858` · *mediu*
  Anunțul eșuat poartă referința comenzii, cea scrisă pe ea în toată aplicația, nu numărul intern din bază. Probă: `.claude/test-defect-anunturi-esuate.mjs`.

- [x] **Barele de sus își păstrau pinul de dreapta după ce rândul se rupea pe telefon: gol mort lângă marginea ecranului, iar pe Produse defectul măsura zero fiindcă un câmp lat îl masca**
  `Setari.dc.html:35` · *mediu*
  Cele trei benzi de telefon capătă rândul pe care celelalte trei pagini îl aveau deja, cuvânt cu cuvânt: `[data-XX=topbar]>div:last-child{width:100%!important;margin-left:0!important}`, plus regulile care lasă copiii să împartă rândul (`flex:1 1 auto` pe butoane și link, `flex:1 1 100%;width:auto` pe câmpul de căutare din Produse). Zero schimbări de marcaj — numai bandă. Proba nu e o cifră, ci o regulă, fiindcă o cifră ar fi lăsat Produse să treacă: în `.claude/check-panouri.mjs` se merge prin marcaj numărând adâncimi, se iau copiii DIRECȚI ai fiecărui container pe care banda îl face să se rupă, și se cere ca fiecare copil pinuit cu `margin-left:auto` să fie atins de o regulă care-i scoate pinul — fie pe mâner (`=savebar]`), fie pe poziție (`>div:last-child`), fiindcă întrebarea e „e scos?", nu „e scos în felul meu?". Șase panouri, opt pinuri, plus o pază care cere ca proba să fi găsit cel puțin opt: un mers prin marcaj care nu mai nimerește nimic ar raporta altfel „0 picate" fără să fi citit nimic. 26 de verificări noi, fiecare văzută roșie stricând la loc fiecare din cele trei pagini.

- [x] **Salvarea din Setări rescria formularul peste ce se tastase cât cererea era pe drum, iar mesajul verde acoperea pierderea; pe deasupra, ștergea la fiecare salvare articolele pe jumătate scrise din casetele de listă**
  `Setari.dc.html:810` · *mediu*
  `.then()` recalculează `changes()` ACUM, față de `orig`-ul vechi: cheile a căror valoare de pe ecran nu mai e cea trimisă au fost atinse între timp și se păstrează peste ce vine de la server. `orig` rămâne răspunsul curat (linia de bază față de care se socotește ce mai e de salvat), iar `s` e răspunsul plus ce a scris omul — deci casetele nu-și pierd textul, iar subtitlul „Ai modificări nesalvate" apare din nou, pe bună dreptate. Mesajul verde nu mai minte: când a rămas ceva, spune „S-au salvat setările trimise. Ce ai scris cât timp se salva a rămas în formular și e încă nesalvat — apasă «Salvează» din nou." `drafts: {}` a fost scos cu totul.

- [x] **Regula „mascat = neatins" se aplica TUTUROR cheilor din Setări, nu doar secretelor: orice casetă obișnuită al cărei text începea cu „•" nu mai pleca deloc spre server, și nici nu apărea printre cele care n-au ținut**
  `Setari.dc.html:745` · *mediu*
  Condiția s-a legat de martorul care există deja: `if (S.s[k + "_set"] !== undefined && isMasked(S.s[k])) return;`. Indicatorul `_set` vine de la server numai pentru cheile secrete, deci regula se aplică exact acolo unde masca chiar e o mască. Pentru restul cheilor, „•" e un caracter ca oricare altul.

- [x] **O singură casetă pentru două cereri: reușita setărilor stingea și mesajul despre sesiunea neaflată**
  `Setari.dc.html:685` · *mediu*
  Starea are acum două câmpuri, `eroareCine` și `eroareSetari`, fiecare scris și stins numai de cererea lui. Caseta le arată pe amândouă, iar `eroareTitlu` alege titlul după care a picat. Poarta se uită la `eroareCine`, nu la amândouă. Probă: `.claude/check-erori-tacute.mjs`, cu câmpurile declarate în lista `PAGINI`.

- [x] **Filele afirmă cum e configurat serverul chiar când pagina tocmai a spus că n-a putut citi configurarea**
  `Setari.dc.html:1663` · *mediu*
  Un steag nou, `stiute`, e adevărat numai când setările chiar au venit. Șase chei care afirmau ceva despre server spun acum „necitit” cât nu se știe, iar explicația de sub câmpul codului se schimbă într-un avertisment care spune pe față: nu scrie unul acum, fiindcă salvarea l-ar înlocui pe cel de azi și ar da tabletele afară. Probă: `.claude/check-panouri.mjs`.

- [x] **Bani câștigați și nevăzuți: managerul sau angajatul cu cod de recomandare chiar încasa comision, dar meniul nu-i dădea niciodată „Recomandări"**
  `shared/gp-api.js:799` · *mediu*
  „Recomandări" se hotărăște acum după cod, nu după rol, iar regula stă ÎNAINTEA matricei în filtrul lui `gp.meniu(activ, cine)`: clientul FĂRĂ cod n-o vede (ar deschide o pagină goală, și nu-și poate face singur un cod), iar oricine ALTCINEVA o vede dacă e în matricea rolului lui SAU dacă are un cod pe cont. Agentul de vânzări o păstrează și fără cod, fiindcă își poate face unul chiar de acolo. Păzit din două părți: `.claude/check-meniu.mjs` (matricea rol → panouri) și grupul B3 din `.claude/test-roluri.mjs`, care plimbă codul pe drumul lui adevărat, de la `GET /auth/me` prin `gp.auth()` până în sertar.

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
  Câmpul de dată are acum amândouă marginile în marcaj — `min="{{ dateMin }}" max="{{ dateMax }}"` (`Site Public.dc.html:974`) — iar serverul nu se mai bizuie pe ele: `POST /requests` verifică ziua pe loc, cu `valid_order_day()` (`api/routes/requests.php:299-300`), în loc s-o lase să treacă și s-o refuze abia butonul „Acceptă". Comentariul de deasupra (`:292-298`) povestește exact cererea care rămânea blocată pe „nou” pentru totdeauna.

- [x] **În catalog și pe pagina produsului cantitatea se putea doar citi: o sută de bucăți cereau o sută de apăsări pe „+"**
  `Site Public.dc.html:439` · *mediu*
  Toate patru cantitățile sunt acum aceeași căsuță (`type="text" inputmode="numeric"`), cu marcaj identic caracter cu caracter — cine mai adaugă una copiază, nu rescrie. Regulile s-au strâns din trei locuri în trei metode: `scrieCant(key, e)` ia numai cifrele, taie la patru și ține textul brut în `editText` (fără el, ștergerea cifrei ca s-o rescrii ar goli rândul sub degete); `terminaCant()` uită cheia și textul la ieșire; `alegeCant(e)` selectează cifra dinainte la atingere. Selecția e adaosul hotărât de mine, nu cerut: fără ea, cine atinge un „1" și scrie „100" primește 1100 — adică fix greșeala pe care căsuța trebuia s-o șteargă. Tăierea la patru cifre nu e o a doua pază, ci sinceritate: `setQty()` taie oricum la 9999, deci fără ea omul ar fi văzut „12345" în căsuță și ar fi citit un total socotit pentru 9999. A ieșit și un stil mort la curățenie (`stepVal`), rămas de pe vremea `<span>`-ului.

- [x] **Propoziţia pragului se tăia în bara de jos de pe telefon exact la cifra pentru care fusese cerută: „Comandă minimă 700 RON · înc…"**
  `Site Public.dc.html:676` · *mediu*
  Dunga şi cuvintele ei au ieşit din coloana îngustă a sumei şi au primit un rând al lor, întins cât toată bara (`flex:1 0 100%`). Bara se împachetează acum (`flex-wrap:wrap`), iar golul dintre rânduri e zero (`row-gap:0`), fiindcă distanţa de deasupra dungii o dă chiar marginea ei de 0,3rem. Mutarea a făcut patru verificări bune să pice deodată — ferestrele lor de căutare erau scrise din ochi, nu măsurate; s-au măsurat şi s-au lărgit, iar de atunci fiecare fereastră nouă poartă scris în comentariu cât s-a măsurat şi cât s-a lăsat de rezervă.

- [x] **Codul de recomandare venit pe link nu se stingea dacă omul îl schimba sau îl golea cu mâna — și se lipea de următorul om de pe același dispozitiv**
  `Site Public.dc.html:3234` · *mediu*
  Hotărârea patronului dintre trei căi: „amândouă ușile". Prima, în `onRefCod`: cine tastează peste un cod venit pe link îl stinge pe loc — și cine golește caseta la fel, fiindcă golirea e tot o hotărâre. Nu se stinge la „Modifică": deschiderea casetei singură nu e o hotărâre, omul poate doar să se uite la cod. A doua, la trimitere: se întreabă și browserul, nu doar caseta. AICI PROBA A PRINS O PAGUBĂ în timpul reparației: primul rând scris punea amândouă treburile sub un singur steag, iar regula veche „codul agentului NU se stinge după trimitere" a căzut pe loc — agentul de vânzări care ia comanda de pe o tabletă cu cookie vechi și-ar fi pierdut codul LUI din casetă. Cele două s-au despărțit: `stingeCookie` (cookie-ul de pe dispozitiv e gunoi și moare, oricine ar fi la tastatură) și `golesteCaseta` (numai codul care CHIAR venea din link pleacă de pe ecran). Regula veche a fost și ea adusă la ce apără de fapt: măsura „nu s-a chemat forgetReferral", care era o dovadă prea largă; acum măsură că `refCod` rămâne „mihai" cu `refSursa` „staff", și o regulă nouă spune, separat, că gunoiul de pe tabletă chiar moare.

- [x] **Fereastra de finalizare rămânea „armată" după ce coșul se golea: pierea de pe ecran, dar sărea singură înapoi la următorul produs pus în coș**
  `Site Public.dc.html:1834` · *mediu*
  Hotărârea patronului: „Dezarmeaz-o ȘI ține minte ce-ai scris". Partea aceasta e dezarmarea. Cele două căi prin care se mișcă coșul — `setQty()` și `bump()` — trec acum prin `dezarmeaza(s, q)`, care stinge steagul de stare când coșul rămâne fără nimic. Scutirea e `s.sent`: trimiterea reușită golește ea coșul (`qty: {}`), iar atunci fereastra TREBUIE să rămână, fiindcă are de spus „Mulțumim, cererea ta a intrat la noi în platformă". Pe drumul acela nu se trece prin `setQty`, dar paza stă scrisă, ca mutarea următoare a cuiva să n-o strice fără să bage de seamă. Paza de la desenare rămâne la locul ei, dinadins: ea stinge fereastra în aceeași clipă cu ultima bucată scoasă, fără să depindă de ordinea în care se cheamă cârligele.

- [x] **Închizând fereastra de finalizare, tot ce scrisese omul în formular se ștergea: numele, e-mailul, telefonul și mesajul, de rescris de la zero**
  `Site Public.dc.html:949` · *mediu*
  Hotărârea patronului: „Dezarmeaz-o ȘI ține minte ce-ai scris". Partea aceasta e ciorna. Cele șapte câmpuri care trăiesc numai în DOM (`Companie`, `Persoana de contact`, `Email`, `Telefon`, `Segment`, `Volum estimat`, `Mesaj`) se culeg într-un colț de pe instanță (`this.__ciorna`) și se pun înapoi la redeschidere. Culesul se face în funcția de `ref`, la desprinderea nodului — singura clipă în care el mai există —, iar punerea la loc în aceeași funcție, la montare. `getSnapshotBeforeUpdate`, care ar fi fost cârligul făcut anume pentru asta, NU e chemat: clasa `Component extends DCLogic` nu e o componentă React, ci un obiect ținut de `StreamableComponent` din `support.js`, care trece mai departe numai `componentDidMount`, `componentDidUpdate` și `componentWillUnmount` — orice alt nume ar fi fost cod mort, fără eroare și fără avertisment. De-aia `formRef` a ajuns din `React.createRef()` o pereche: cutia rămâne, pentru `sendWa`, dar în marcaj pleacă funcția, legată o singură dată în constructor (una nouă la fiecare desenare ar fi desprins și prins nodul întruna). Ciorna NU cuprinde codul de recomandare — caseta lui e controlată de React, iar scrisul peste `.value` din afară ar dezlipi-o de stare — și nu ajunge în `localStorage`, unde stă de mult coșul: coșul e o listă de produse, astea sunt numele, e-mailul și telefonul omului. Ține cât ține fila deschisă. Același mecanism repară și pagina de Contact, fiindcă e același `<main>`.

- [x] **Săgeata „înapoi” de pe telefon scotea omul din magazin în loc să închidă fereastra deschisă peste el — și lua cu ea comanda pe jumătate scrisă**
  `Site Public.dc.html:1761` · *mediu*
  Hotărârea patronului: „Înapoi închide fereastra și ATÂT”. La deschiderea foii sau a ferestrei se pune o intrare în istoric, pe ACEEAȘI adresă (`history.pushState({ gpFoaie: 1 }, "", location.href)`), ca bara de adrese să nu se clintească — foaia nu e o pagină. Prima apăsare pe „înapoi” o consumă: `_onPop` închide foaia și se oprește acolo, fără să atingă pagina. A doua apăsare schimbă pagina, ca până acum. Întrebarea se pune pe un steag de pe instanță, `this.__foaieInIstoric`, nu pe `this.state`: la două apăsări iuți, starea n-a apucat să se schimbe și a doua apăsare s-ar fi pierdut. Punerea și scoaterea intrării nu sunt scrise în fiecare buton — sunt șase drumuri pe care se închide foaia (✕, fundalul, „Golește”, scoaterea ultimei bucăți, schimbarea paginii, „înapoi”) și ar fi trebuit ținute minte toate. Se potrivesc într-un singur loc, `potrivesteIstoricul()`, chemat o dată după fiecare desenare, care compară ce e pe ecran cu ce e în istoric. Închiderea de la buton cheamă `history.back()`, nu doar stinge steagul: altfel intrarea ar fi rămas pe stivă, iar prima apăsare pe „înapoi” de după ar fi fost una moartă. Singurul drum care nu trece pe acolo e `duLa()`: acolo pagina nouă ÎNLOCUIEȘTE intrarea foii cu `replaceState`, ca să nu se ceară o întoarcere peste o navigare tocmai împinsă în istoric. Iar ce a scris omul rămâne scris, fiindcă ciorna lucrează alături.

- [x] **Adresa publică și programul aveau casete în Setări, dar niciun rând în tabel: patronul scria, apăsa „Salvează", primea „ok", și nu se schimba nimic. Niciodată**
  `sql/002_seed.sql:89` · *mediu*
  Hotărârea patronului: „Rândurile, ȘI un refuz lămurit". Partea aceasta e rândurile. O migrare nouă, `sql/018_adresa_si_program.sql`, le seamănă cu `INSERT IGNORE`, pornind de la chiar valorile scrise azi în cod (`seo/index.php`), ca site-ul să arate la fel în clipa actualizării — se schimbă doar cine ține valoarea: baza, nu fișierul. `INSERT IGNORE`, nu `REPLACE`: migrările se rulează de câte ori e nevoie, iar adresa pe care patronul a scris-o din Setări nu trebuie să se întoarcă la cea din cod la următoarea actualizare. Spre deosebire de `017`, aici nu e nevoie de niciun `UPDATE` cu pază, fiindcă n-a existat o valoare semănată greșit, ci numai un rând care lipsea. Temeiul de rezervă din cod rămâne, dinadins, și e verificat cu `?:`, nu cu `??`: o casetă golită din panou salvează șir gol, deci `??` ar lăsa pagina fără adresă în loc s-o întoarcă pe cea din cod. Cele trei ghiduri (07, 08, 09) spun acum cum se verifică `018`, iar tabelul „Ce se poate reimporta și ce nu" o are pe rândul ei.

- [x] **Migrarea 003 rescrie necondiționat zonele de livrare și șterge zonele adăugate din Setări**
  `sql/003_route_order.sql:82` · *mediu*
  `UPDATE settings SET value = …` a devenit `INSERT IGNORE`: migrarea pune zonele implicite doar dacă lipsesc. Reimportarea ei nu mai șterge zonele adăugate din Setări.

- [x] **Migrarea 010 hotăra originea conturilor din jurnal la FIECARE actualizare, deși comentariul ei se apăra cu „e în regulă tocmai fiindcă e o singură dată" — iar „o singură dată" nu era adevărat**
  `sql/010_origine_cont.sql:122` · *mediu*
  Hotărârea patronului: „Un opritor în bază". Pasul își lasă o urmă în `settings` (`migration_010_origin_judged`, cu data la care s-a judecat, scrisă citibil de om) și se uită întâi la ea: când urma e acolo, nu se face NIMIC — nici măcar nu se deschide jurnalul. Ca să fie adevărat pe litere, interogarea se dă prin `PREPARE`, ca pașii 1 și 2 de deasupra: când urma există, ce se execută e un `SELECT 1`, deci jurnalul nici nu ajunge să fie atins. Urma se scrie DUPĂ pas, cu `INSERT IGNORE`, ca a doua rulare să n-o rescrie — altfel ar minți despre CÂND s-a judecat, iar ea e singurul lucru care mai știe. Pe un server care a rulat deja 010 de multe ori, urma încă nu există, deci pasul se mai face o dată — exact cât s-ar fi făcut oricum la actualizarea aceea — și abia pe urmă se pune opritorul. Condiția `self_registered = 0` rămâne: opritorul apără de a doua ACTUALIZARE, ea apără de a doua rulare din aceeași actualizare. Probă nouă, `.claude/test-migrari-repetate.mjs` (31 de reguli), pusă și în `verifica-tot`: își ridică singură o bază curată, aplică 001+002, apoi tot lanțul, o fotografiază cu `mysqldump`, și rulează pe rând fiecare migrare a doua oară, cerând ca fotografia să rămână identică, octet cu octet. E prima probă a casei care verifică chiar promisiunea pe care se sprijină întreaga actualizare de pe VPS. Pe drum a scos la iveală și că `INSERT IGNORE` arde un număr de AUTO_INCREMENT chiar când sare peste rând, deci `003_route_order.sql` crește contorul lui `users` la fiecare actualizare — fără urmare, dar scris în probă ca să nu fie descoperit iar ca mister. Dovedită cu trei stricăciuni: codul de dinainte de reparație, urma scrisă fără IGNORE, și opritorul pus ÎNAINTEA pasului (cârpa care ar fi făcut proba verde oprind lucrarea cu totul).

- [x] **Rescrierea interceptorului de navigare schimbă înțelesul condiției: `!(A && B)` devine `!A && !B`**
  `.claude/build-deploy.mjs:294` · *mic*
  Rescrierea interceptorului de legături punea expresia fără paranteze înăuntrul unui !( ), deci negația se lipea doar de primul test și condiția ieșea pe dos: tocmai legăturile absolute între subdomenii erau oprite și duse mai departe cu șirul de interogare lipit la coadă. Probă: check-deploy aplică rescrierea pe linia adevărată din fiecare pagină publicată și evaluează condiția rezultată cu vm, pe șapte feluri de adresă.

- [x] **Cele trei subdomenii de pagini se publică fără `Options -Indexes`: folderele fără index se pot răsfoi**
  `.claude/build-deploy.mjs:196` · *mic*
  Reprodus prin numărare pe pachetul generat: `Options -Indexes` apărea doar în `deploy/api/.htaccess` (1), iar în `deploy/public_html`, `deploy/clienti`, `deploy/lab` de 0 ori. Am mutat interdicţia în `HT_COMMON`, blocul din care se naşte .htaccess-ul tuturor celor trei subdomenii de pagini (`HT_COMMON + (host === 'root' ? HT_SEO : HT_NOINDEX)`), pusă înaintea blocului „fişiere care nu au ce căuta în browser", cu un c

- [x] **Paza golului se uita la patru pagini din doisprezece: Recomandări, Conturi, Jurnal, Produse, Setări, Client și Pontaj treceau verzi fără să fie atinse**
  `.claude/check-erori-tacute.mjs:72` · *mic*
  Lista e la douăsprezece pagini, cu cheile de gol și cu numele câmpurilor de eroare declarate pentru fiecare — inclusiv cele despărțite în runda asta (`eroareCine`/`eroareSetari` în „Setări”, `eroare`/`cerEroare` în „Comanda mea”). Proba trece acum prin 145 de reguli.

- [x] **Proba care apără încărcarea fișierelor PHP striga la `telegram.php` pentru ceva ce fișierul face dinadins: îl model pe router ca și cum un fișier de rute n-ar putea vedea niciodată altul, deși unul cere pe față**
  `.claude/check-php.mjs:1` · *mic*
  Proba nu mai presupune cine pe cine vede, ci CITEȘTE cererile din codul însuși: adună `require_once __DIR__ . "/fișier.php"` din fiecare fișier și le închide tranzitiv, ca în PHP (cine cere pe A vede și ce cere A). Poarta s-a mutat de la „e fișier de rute?" la „chiar îl cere?", deci lărgirea ține exact cât ține cererea scrisă în cod: dacă `require_once` dispare, proba strigă din nou.

- [x] **Probe care lăsau în bază rânduri pe care curățenia nu le cunoștea: suita ieșea roșie, iar fila „Cereri de pe site" se umplea**
  `.claude/test-flow.mjs:117` · *mic*
  Probele poartă acum nume de casă, după sfatul scris chiar în curățenie: „Dacă numele e prea general ca să fie șters fără grijă, redenumește-l în probă («Probă …»)". Cererea coșului gol e „Probă Întrebare Fără Coș", iar cele trei probe de refuz — două în `test-flow`, una în `test-api` — sunt „Probă Refuz". Amândouă numele intră în `NUME_DE_TEST_LISTA`, unde comentariul spune de ce o probă care NU trebuie să lase niciun rând are totuși nevoie de un nume de casă: pentru clipa în care o dovadă îi strică poarta. Cele paisprezece rânduri vechi s-au șters cu aceeași cascadă pe care o folosește curățenia — întâi pozițiile, anunțurile și legătura în ambele sensuri, abia apoi cererea și comanda —, fiindcă altfel cheile străine dintre ele nu lasă niciuna să plece.

- [x] **Proba pentru „app.html cerut direct" acceptă chiar rezultatul pe care ar trebui să-l refuze**
  `.claude/test-seo.mjs:257` · *mic*
  `check('app.html cerut direct nu servește o pagină fără antet', direct.status === 200 || direct.status === 301, …)` accepta 200-ul, adică chiar rezultatul refuzat de nume. Reprodus: `curl -o /dev/null -w %{http_code} http://127.0.0.1:8101/app.html` → 200, iar corpul n-are nici `<title>`, nici `<link rel="canonical">`.

- [x] **Raportul suitei tăia tocmai motivul: o unealtă care nu vorbea în cuvintele casei pica fără să spună de ce**
  `.claude/verifica-tot.mjs:225` · *mic*
  Hotărârea patronului: „Când filtrul nu prinde nimic, arată tot". Filtrul rămâne acolo unde e bun; când din el nu iese niciun rând, se arată ieșirea întreagă a uneltei, tăiată la aceleași douăzeci și cinci de rânduri. Regula nu e scrisă pentru curățenie, ci pentru orice unealtă de aici înainte care nu nimerește cuvintele așteptate — și tocmai de-aia una din reguli păzește cealaltă jumătate: unde filtrul PRINDE, el trebuie să rămână filtru, altfel cele trei rânduri roșii s-ar îneca în trei sute verzi. Iar ultimul rând al curățeniei se tipărește numai când ea a MERS, fiindcă atunci el e socoteala („Șters, din «gp_dev»: 19 urme de la teste"); când pică, tot ce s-a găsit se vede mai jos, întreg.

- [x] **Cele patru rsync-uri n-au nicio prindere de eroare: dacă unul pică la mijloc, site-ul rămâne pe jumătate nou și comanda de întoarcere a codului nu se mai scrie pe ecran**
  `actualizeaza-vps.sh:441` · *mic*
  Cele patru copieri au acum fiecare prinderea ei de eroare: la o cădere scriptul spune care folder a picat, în ce stare a rămas site-ul și pe unde e calea înapoi — cu arhiva de cod numită pe loc, nu abia în raportul de la final. Aceeași plasă în amândouă copiile scriptului. Probă: `.claude/test-plasa-rsync.mjs`.

- [x] **Scriptul de VPS și documentul plasturelui cereau amândouă „401" de la `/auth/me`, dar ruta răspunde 200 unui vizitator nelogat**
  `actualizeaza-vps.sh:539` · *mic*
  Amândouă locurile spun acum ce caută proba, nu o cifră: în script, „orice răspuns, numai 500 nu", cu un comentariu deasupra care numește `route_me()` și purtarea lui; în `CITESTE-MA.md`, rândul de reușită e `api.DOMENIU răspunde (200)` și se spune limpede că 200 și 401 sunt amândouă bune, iar 500 nu. Formularea nouă rămâne adevărată chiar dacă ruta s-ar schimba vreodată, fiindcă repetă criteriul scriptului (`2*|4*` trece, `5*` cade) în loc să ghicească un număr. [13 septembrie 2026]

- [x] **API-ul își anunță versiunea de PHP în fiecare răspuns**
  `api/index.php:62` · *mic*
  header_remove('X-Powered-By') în api/index.php, ca să nu depindă de expose_php din configurarea serverului. Probă: test-api.mjs „răspunsul nu mai anunță versiunea de PHP" (plus „antetele de securitate sunt la locul lor", ca să nu se piardă celelalte).

- [x] **Un utilizator mai lung de 64 de caractere la autentificare dă 500 în loc de „Utilizator sau parolă greșită”**
  `api/lib/auth.php:161` · *mic*
  record_attempt() taie numele la 64 de caractere, cât are coloana. Orice text lipit în câmpul „Utilizator" ajunge acum la mesajul obișnuit de credențiale greșite, nu la „Eroare internă.", iar încercarea se numără mai departe la limitare. Probe: test-api.mjs „utilizator de 300 de caractere → 401, nu 500", „mesajul e cel obișnuit, nu unul de defecțiune" și varianta cu diacritice.

- [x] **must_change_password nu e impus pe server, iar pagina care îl cere are pe ea butonul de ocolire**
  `api/lib/auth.php:33` · *mic*
  api/lib/auth.php — trei schimbări, toate în fișierul meu: 1. Linia 33, în SELECT-ul din current_user(): am adăugat `u.is_system` lângă `u.must_change_password`, ca regula de mai jos să poată scuti conturile de sistem. 2.

- [x] **Anunțul comenzii noi poartă alt număr decât cel scris pe comandă în toată aplicația**
  `api/lib/notify.php:87` · *mic*
  Anunțul comenzii noi poartă referința scrisă pe comandă, aceeași pe care o vede omul în toată aplicația. Probă: `.claude/test-defect-anunturi-esuate.mjs`.

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
  Toate cele cinci locuri care cădeau pe `gmdate()` folosesc acum `today_local()` — ziua patiseriei, nu ziua aparatului (`api/routes/orders.php:271, 465, 1699, 1724, 1772`), cu un comentariu la `:461` care spune de ce: între 00:00 și 03:00 ora României, tocmai orele în care laboratorul lucrează pentru livrarea de 06:30, UTC e încă ziua de ieri. Proba: `.claude/test-ceas-patiserie.mjs`, care măsoară pe patru fusuri orare, în suita statică.

- [x] **Comanda mutată în altă zi pierde referința tastată de administrator: coloana ref se scrie de două ori în același UPDATE**
  `api/routes/orders.php:476` · *mic*
  Coloana `ref` nu se mai scrie de două ori în același UPDATE, deci referința tastată de administrator supraviețuiește mutării comenzii în altă zi. Probă: `.claude/test-defect-lista-comenzi.mjs`.

- [x] **Ramura pentru `agent_vanzari` din filtrul de opriri e cod mort: `require_ops()` nu lasă niciodată un agent să ajungă acolo**
  `api/routes/orders.php:1700` · *mic*
  Ramura rămâne în cod, comentariul s-a îndreptat. Poarta a fost măsurată din nou pe 13 septembrie 2026: amândouă rutele care cheamă `route_stop_filter()` cu un utilizator — `route_driver_routes()` și `route_driver_route_counts()` — încep cu `require_ops()`, iar `GP_OPS_ROLES` (api/lib/auth.php) e `admin, angajat, manager, fondator`. Deci ramura chiar e de neatins azi, cum spunea fișa. Comentariul de deasupra ei susținea însă contrariul — „e nevoie de ea" — și tocmai textul acela era defectul: cine îl citea credea că agenții își văd opririle. Acum scrie negru pe alb că rândul nu se atinge niciodată azi, de ce rămâne totuși (`GET /routes` întoarce comenzi întregi, cu nume, telefon și adresă — dacă cineva adaugă vreodată agenții la `GP_OPS_ROLES`, mărginirea listei s-ar ocoli cerând ruta zilei) și că lărgirea porții e o hotărâre despre cine ce vede, adică a patronului, nu a rândului de față. Purtarea aplicației nu s-a schimbat cu nimic: e o corectură de text, într-un singur fișier.

- [x] **O listă cu valori imbricate trece de validare și ajunge în baza de date ca textul „Array”**
  `api/routes/settings.php:158` · *mic*
  validate_lists() respinge acum cu 422 orice element de listă care nu e text simplu, cu numele cheii în mesaj, iar setting_list() sare peste el la citire, pentru rândurile scrise înainte de verificare. Probe în .claude/test-settings.mjs: un tablou imbricat și un obiect strecurate în delivery_slots sunt refuzate, iar intervalele salvate rămân neatinse — niciunul nu devine „Array”.

- [x] **company_email (contactul public) nu e validat la salvare, deși adresele de notificare alăturate sunt**
  `api/routes/settings.php:89` · *mic*
  Reprodus întâi pe serverul viu: PUT /settings {company_email:"contact@goldenpastry"} -> 200, iar GET /lists (rută publică) întorcea contact.email = "contact@goldenpastry"; controlul pe câmpul vecin, notify_email_to cu aceeași adresă, dădea 422 bad_email. Am pus adresa la loc imediat.

- [x] **Paza zonelor și a intervalelor se uită doar la comenzi, nu și la cererile clienților care așteaptă acceptarea**
  `api/routes/settings.php:315` · *mic*
  `list_usage()` numără și cererile rămase pe „nou", nu doar comenzile, deci o zonă sau un interval ținut de o cerere în așteptare nu se mai poate scoate de sub ea. Probă: `.claude/test-defect-paza-zonelor.mjs`.

- [x] **Verificarea zonelor în Setări folosește ziua în UTC, așa că între 00:00 și 03:00 blochează salvarea din cauza comenzilor de ieri**
  `api/routes/settings.php:316` · *mic*
  `list_usage()` leagă ziua calculată în PHP (`today_local()`), nu `UTC_DATE()` cerut bazei: între 00:00 și 03:00 ora României e înaintea celei din UTC, deci paza număra comenzile de IERI și refuza o ștergere pe care nimic n-o mai ținea. Probă: `.claude/test-defect-paza-zonelor.mjs`.

- [x] **Socoteala „după ziua în care s-a comandat" tăia zilele pe ceasul UTC, nu pe ceasul patiseriei: comenzile luate între miezul nopții și 3 dimineața cădeau în ziua de ieri**
  `api/routes/stats.php:103` · *mic*
  Ajutorul nou `zi_locala_sql()` (api/lib/http.php) scrie expresia SQL care dă ziua LOCALĂ a unei coloane de clipe UTC: cere fusului, în PHP, schimbările de oră din fereastra cerută și face din ele un `CASE` care mută fiecare clipă cu decalajul valabil ATUNCI — deci e drept și vara, și iarna, și în intervalul care le cuprinde pe amândouă. Nu cheamă `CONVERT_TZ`, din aceeași pricină pentru care nu-l cheamă nici anunțurile din laborator (api/routes/lab.php:463): pe o instalare fără tabelele de fusuri încărcate în MySQL acela întoarce NULL, adică o zi lipsă în tăcere. `stats.php` îl cheamă cu fereastra întreagă, de la perioada de comparat până la sfârșitul celei cerute, fiindcă și aceea se numără cu aceeași coloană. Nimic din expresie nu vine de la om: numele coloanei e scris de noi, iar orele ies din `DateTimeZone`.

- [x] **Filtrul de zi al registrului de depozit taie după data UTC, dar paginile trimit data locală**
  `api/routes/stock.php:151` · *mic*
  Am mutat filtrul de zi al registrului de pe „data UTC" pe „intervalul de clipe al zilei locale". Concret, în api/routes/stock.php: 1) Funcția nouă `stock_local_day_utc(string $day, int $plusZile = 0): string`, pusă la începutul secțiunii „mișcări": construiește `new DateTimeImmutable($day .

- [x] **Doi care adaugă același articol de depozit (sau același cont) în aceeași clipă: „Eroare BD" 500 în loc de refuzul lămurit**
  `api/routes/stock.php:70` · *mic*
  Ajutorul `q_unique()` (api/lib/db.php) prinde chiar căderea pe indexul unic și o întoarce în refuzul pe care cererea l-ar fi primit dacă venea o clipă mai târziu: 409 `name_taken` la articolele de depozit (creare și redenumire) și 409 `username_taken` la conturi. Verificarea din memorie rămâne unde era — ea dă mesajul bun în cazul obișnuit; asta e a doua poartă, cea pe care o ține baza, pentru clipa dintre „SELECT 1 … WHERE name = ?" și INSERT. Se prinde numai 1062 (rând duplicat): restul lui 23000, o cheie străină ruptă de pildă, e o defecțiune adevărată și trebuie să rămână una. Probă: grupa a patra din .claude/test-reparat-depozit-refuzuri.mjs — ciocnirea nu se poate stârni prin `php -S`, care are un singur proces și așază cererile la rând, deci se cere direct de la bază, prin .claude/proba-unicitate.php. Dată înapoi reparația, proba arată exact dovada din fișă: „1062 Duplicate entry … for key 'stock_items.uq_stock_items_name'".

- [x] **Steagul „trebuie să-și schimbe parola" se pune, dar nu-l citește nimeni niciodată**
  `api/routes/users.php:172` · *mic*
  must_change_password se întoarce acum în /users (mustChangePassword) și e citit de Conturi (badge „PAROLĂ PROVIZORIE”), de Client (banner) și de Intrare (redirect la prima intrare). Probe: test-parola „după resetarea făcută de administrator, contul e marcat cu parolă provizorie”, „o autentificare nu trece drept schimbare de parolă”; check-panouri „pagina citește de la server steagul de parolă provizorie”, „panoul clientului arată singur că are parolă provizorie”.

- [x] **Un agent de vânzări află numele de utilizator al oricărui cont, dându-i adresa de e-mail**
  `api/routes/users.php:252` · *mic*
  `check_user_email()` primește un al treilea argument: refuzul plin — „adresa e deja pe contul «X»" — îl vede numai adminul, care are lista de conturi; agentul de vânzări primește același 409 `email_taken`, dar fără numele contului. Așa nu mai poate scoate, o adresă pe rând, numele de utilizator al oricui. Probă: `.claude/test-roluri.mjs`.

- [x] **Pagina de verificare a instalării numără 14 tabele din 15 și raportează „toate prezente"**
  `api/verifica.php:180` · *mic*
  api/verifica.php — am adăugat `product_categories` în lista `$need`, care are acum 15 nume, exact câte tabele creează sql/001_schema.sql (numărate: users, sessions, password_resets, product_categories, products, order_requests, order_request_items, orders, order_items, stock_items, stock_moves, audit_log, notifications, settings, login_attempts).

- [x] **Reîmprospătarea din pagina clientului nu se oprește niciodată și nu are pază la suprapunere**
  `Client.dc.html:302` · *mic*
  Pagina folosește gp.poll(), care se oprește când fila e ascunsă și reia la revenire; gp.poll() a primit și o pază la suprapunere, deci o tură care nu s-a întors nu lasă alta să plece — de ea beneficiază orice pagină. Probe: .claude/check-panouri.mjs rulează chiar gp.poll() cu o tură mai lentă decât intervalul și verifică atât că nu se suprapun, cât și că fila ascunsă nu mai cere nimic.

- [x] **Comentariul motivează rândul nou cu o urmare pe care codul de sub el o contrazice**
  `Client.dc.html:536` · *mic*
  Comentariul spune motivul adevărat: rândul e acolo ca ecranul de intrare să nu poarte, peste el, o casetă roșie rămasă de la tura dinainte.

- [x] **Trimiterea „(marcaj :34)” dintr-un comentariu nimerește în mijlocul unei fraze, nu la caseta despre care e vorba**
  `Client.dc.html:819` · *mic*
  Trimiterile pe număr de rând sunt scoase cu totul și înlocuite cu ancore numite („chiar deasupra lui `<sc-if value="{{ locked }}">`”), cu o notă care spune de ce: numerele de rând putrezesc la prima reparație de deasupra lor.

- [x] **Caseta cere „minim 6 caractere", serverul refuză sub 8, iar butonul „Propune o parolă" poate genera una de 7**
  `Conturi.dc.html:425` · *mic*
  Pragul paginii nu mai poate coborî sub cele 8 caractere ale serverului (minLen() dă Math.max(8, …), propul are acum implicit 8 și minim 8), parola propusă e construită ca să treacă de prag oricât ar fi el, iar bannerul de sus își ia culoarea din stare — un refuz nu mai e desenat verde, ca o confirmare. Probe: .claude/check-panouri.mjs, care scoate minLen() și suggest() din sursă și le rulează (2400 de parole propuse), plus probele pe banner.

- [x] **Codul panourilor: pagina cere minim 4 caractere, serverul refuză sub 6**
  `Conturi.dc.html:633` · *mic*
  Caseta cere acum minim 6 caractere, cât cere și serverul — și în text, și în verificarea dinaintea trimiterii. Înainte, un cod de 4–5 caractere trecea de pagină și se lovea de server cu o eroare roșie. Cardul de deasupra trece pe „••••••••” imediat după salvare, nu rămâne pe „neconfigurat”. Verificat în browser.

- [x] **Aceeași apăsare de buton stinge caseta paginii și o lasă aprinsă pe cea a cardului „Cod pentru panouri”**
  `Conturi.dc.html:1665` · *mic*
  Butonul golește și `pCodeErr`, cu motivul scris lângă el. Probă: `.claude/check-erori-tacute.mjs`.

- [x] **Butonul de corecție al unei mișcări poate fi apăsat de oricâte ori, generând corecții repetate**
  `Depozit.dc.html:726` · *mic*
  O mișcare deja corectată își spune starea în locul butonului: a doua corecție nu mai poate fi scrisă.

- [x] **Tabelul „ce se poate reimporta” spunea despre migrarea 013 că e „numai INSERT IGNORE INTO settings”, deși adaugă trei coloane și rescrie o dată anunțurile vechi**
  `docs/07-actualizari.md:223` · *mic*
  Rândul spune acum cele trei coloane, că fiecare se uită întâi în `information_schema`, și că singurul UPDATE prinde numai rândurile încă nemarcate. `test-doc-actualizari.mjs`: 106 trecute · 0 eșuate.

- [x] **Ghidul de actualizări trimitea la `api/index.php:305-311` pentru mesajul „Eroare internă", dar acolo se mutase de mult coada rutării: cine urma trimiterea găsea alt cod decât cel despre care citea, iar proba care păzea cele patruzeci de trimiteri o vedea verde, fiindcă ea cerea doar ca fișierul să aibă atâtea rânduri**
  `docs/07-actualizari.md:212` · *mic*
  Cele trei trimiteri s-au mutat de la `api/index.php:304-310` și `:310` la `:312-318` și `:311`, adică pe blocurile `catch` despre care vorbesc frazele. Tabelul `TRIMITERI_ANUME` din probă leagă fiecare frază de ancora ei, iar regula cere ca ancora să cadă în interval — deci de acum înainte o mutare de cod care lasă trimiterea în urmă se vede, nu doar una care o scoate din fișier. Cele trei legate acum sunt un început, nu un capăt: proba spune singură câte sunt din câte, ca următorul care trece pe aici să știe cât mai are de legat.

- [x] **Cardul „Rută” de pe ecranul de start numără doar modul „Livrare”, deci arată „0 opriri” pentru orice alt mod de livrare**
  `Intrare.dc.html:541` · *mic*
  Filtrul suplimentar din pagină a fost scos: serverul scosese deja modurile de ridicare, pe lista din Setări, iar a doua sită cerea numele scris exact „Livrare". Cardul „Rută" numără acum toate opririle, oricum și-ar boteza patiseria modul. Probă: `.claude/check-panouri.mjs`.

- [x] **Subsolul barei laterale scrie „administrator” sub un nume gol, înainte să fi aflat cine se uită la pagină**
  `Jurnal.dc.html:624` · *mic*
  Subsolul e închis într-un `<sc-if value="{{ sideWho }}">`, cu `sideWho: !!auth && isAdmin` — aceeași scriere ca în „Setări”. Nu se mai scrie nimic despre cine se uită la pagină până nu se știe. Probă: `.claude/check-panouri.mjs`.

- [x] **Comentariul de deasupra lui `onMore` spune că pagina așteaptă promisiunea întoarsă — React o aruncă**
  `Jurnal.dc.html:751` · *mic*
  Comentariul spune acum exact cine așteaptă valoarea întors: NUMAI `.claude/check-panouri.mjs`. Pagina care rulează nu-l folosește, și scrie de ce.

- [x] **Pe telefon, butonul cel mai însemnat din foaia de detaliu a laboratorului era și cel mai mic: „poziția e gata toată" avea 30×30, între două butoane de 44×44 care fac fiecare un pas de o bucată**
  `Panou Laborator.dc.html:366` · *mic*
  Cele trei butoane au căpătat cârlige (`data-lb="itemdone"`, `data-lb="cardmove"` pe amândouă săgețile), iar banda de telefon (`@media (max-width:900px)`) le duce la 44: caseta 44×44 cu rotunjirea potrivită mărimii noi, săgețile 44 de lat. Regulile sunt `!important`, fiindcă se bat cu stilul scris în marcaj. Pe monitor NU s-a atins nimic — acolo se apasă cu mouse-ul, iar 30px e în regulă; proba cere anume ca regulile să stea în banda de telefon și să NU fie în banda de lat, fiindcă unde stă reparația e chiar rostul ei.

- [x] **Niciuna din cele 16 pagini n-are siglă de tab: fiecare încărcare cere /favicon.ico și ia 404, iar în browser patiseria n-are chip**
  `Panou Laborator.dc.html:1` · *mic*
  Toate cele 17 pagini declară acum `<link rel="icon">` cu rozeta aurie pe fundal străveziu, făcută de `.claude/fa-pictograme.php`. Browserul nu mai cere `/favicon.ico` de la sine și nu mai ia 404 la fiecare încărcare. Probă: `.claude/check-fila.mjs`.

- [x] **Nicio pagină de panou n-are <title>: în bara de file scrie numele fișierului, iar scurtătura de pe telefon se numește „Panou Laborator.dc.html”**
  `Panou Laborator.dc.html:11` · *mic*
  Toate cele 16 panouri au acum `<title>` cu numele panoului și „Golden Pastry”, deci fila browserului și scurtătura de pe telefon poartă numele casei, nu numele fișierului. Magazinul public rămâne cu titlul scris de `seo/index.php`, pe rută și pe limbă — acolo e locul lui. Probă: `.claude/check-fila.mjs`.

- [x] **Nicio căsuță deschisă la apăsarea unui buton nu primea degetul: în toată aplicația nu exista niciun `focus()`, nici în pagini, nici în `support.js`**
  `Panou Laborator.dc.html:233` · *mic*
  S-a pus `autoFocus="{{ true }}"` pe caseta din capul fiecăreia dintre cele zece foi. N-a fost nevoie de nicio schimbare în `support.js`: `encodeCase()` (`:365-369`) trece orice atribut camelCase prin `sc-camel-…` și `collectProps()` (`:422-423`) îl întoarce camelCase — de-aia merg deja `readOnly`, `inputMode`, `autoComplete`; iar `<sc-if>` SCOATE copiii din pagină când steagul e fals (`walkIf`, `:646-660`, întoarce `null`), deci la fiecare deschidere caseta se naște din nou, exact clipa în care React cheamă `autoFocus`. Regula după care s-au ales cele zece din cele 34 de perechi `<sc-if>` + casetă: caseta apare la apăsarea unui buton ȘI e primul lucru din ce a apărut. Au rămas dinadins fără: filele din Setări și Catalog, steperele de cantitate din magazin (câte unul pe rând), formularul de mișcare din Depozit (începe cu un `<select>`), foaia de închidere de tură din Pontaj (începe cu ziua și ora) și formularele care se deschid la ÎNCĂRCAREA paginii — pe tableta din laborator, tastatura sărită în față la fiecare deschidere acoperă jumătate de ecran. Verificarea nouă `.claude/check-focus.mjs` păzește și casetele, și hotărârile de a nu le da focus, și cele trei lucruri din `support.js` fără de care reparația ar muri tăcut. [13 septembrie 2026]

- [x] **Catalogul cere de la server două adrese literale „{{ … }}" la fiecare deschidere**
  `Produse.dc.html:140` · *mic*
  Miniaturile din Catalog au primit loading="lazy" — nu pentru viteză, ci ca parserul să nu ceară de la server chiar textul cu acolade al legăturii, înainte ca runtime-ul s-o înlocuiască. Erau două 404-uri la fiecare deschidere a paginii, în jurnalul găzduirii și în consolă.

- [x] **Lista de materii prime scrie „Nu mai e ce adăuga din depozit” și când depozitul n-a putut fi citit deloc**
  `Produse.dc.html:1243` · *mic*
  Prima opțiune se alege acum după `S.stockErr`: „Depozitul nu s-a putut citi” când cererea a picat, „Nu mai e ce adăuga din depozit” numai când chiar s-a citit și nu mai e nimic. Probă: `.claude/check-panouri.mjs`.

- [x] **Butonul „+ Produs nou” e apăsabil cât se încarcă pagina, iar formularul se deschide cu lista „Grupa” goală**
  `Produse.dc.html:1097` · *mic*
  Lacătul e `S.loaded && !S.eroare`, la fel ca la subtitlu. Probă: `.claude/check-erori-tacute.mjs`.

- [x] **Subsolul barei laterale scrie „administrator” peste numele unui neadministrator, cât ține fereastra dintre două setState-uri**
  `Produse.dc.html:1037` · *mic*
  Catalogul a primit cheia `sideWho: !!S.auth && isAdmin`, iar marcajul barei laterale o citește pe ea. `navWho` rămâne pentru sertarul de telefon, unde scrie doar „conectat”. Probă: `.claude/check-panouri.mjs`.

- [x] **Pătratul din locul fotografiei lipsă chema rozeta albă pe cartonașul crem: contrast 1,22:1, adică un pătrat gol**
  `Produse.dc.html:493` · *mic*
  `FOTO_LIPSA` arată acum spre `assets/logo-alb.png`, fișierul care are cerneala aurie, cu un comentariu care pune tabelul de contraste la vedere ca nimeni să nu mai „îndrepte” numele înapoi. Proba nouă `.claude/check-sigle.mjs` desface PNG-urile cu zlib, măsoară cerneala fiecărei sigle și cere ca rozeta din locul fotografiei să se vadă în amândouă temele, cu pragul 2,0:1. Dovedit roșu pe defectul pus la loc: 8 trecute · 2 picate; verde după reparație: 10 trecute · 0 picate.

- [x] **Butonul „Fă un cont de cumpărător" din „Recomandări" se arăta oricui nu era client, dar `POST /users` lasă numai administratorul, fondatorul și agentul de vânzări**
  `Recomandari.dc.html:949` · *mic*
  Steagul cere acum exact ce cere serverul: `!!(a && S.cod && (gp.eAdmin(a) || a.role === "agent_vanzari"))`. Rolul se cere, nu doar „nu e client".

- [x] **Comentariul din `incarca()` trimite la câmpul `eroare`, care nu mai există în starea paginii**
  `Recomandari.dc.html:711` · *mic*
  Comentariul spune `eroareMine`, numele de azi. Comparația cu `err` rămâne — e încă adevărată.

- [x] **Titlul casetei spune „Recomandările nu s-au încărcat.” și când picase numai lista de comisioane**
  `Recomandari.dc.html:1146` · *mic*
  Titlul vine din cheia derivată `eroareTitlu`, care alege după care cerere a picat: „Recomandările nu s-au încărcat.” sau „Lista de comisioane nu s-a încărcat.”. Marcajul citește `{{ eroareTitlu }}`. Probă: `.claude/check-erori-tacute.mjs`.

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

- [x] **Alerta roșie spune „Comenzile sunt salvate, dar nu au fost anunțate” și pentru mesajele de test, care n-au nicio comandă în spate**
  `Setari.dc.html:845` · *mic*
  Alerta roșie deosebește mesajul de test de anunțul unei comenzi: pentru un test nu mai spune că „Comenzile sunt salvate, dar nu au fost anunțate", fiindcă în spatele lui nu e nicio comandă. Probă: `.claude/test-defect-anunturi-esuate.mjs`.

- [x] **Comentariul motivează reparația cu un buton care în starea descrisă era oricum închis**
  `Setari.dc.html:736` · *mic*
  Comentariul spune drumul adevărat: o încărcare izbutită urmată de o cerere picată, nu formularul gol. „Trimite test” rămâne singurul buton deschis pe formularul gol, și scrie de ce.

- [x] **O ramură de text care nu se mai poate desena pe niciun ecran a rămas în fișier**
  `Setari.dc.html:1348` · *mic*
  Ramura moartă e scoasă; `blockedLine` are o singură propoziție, cea care chiar se scrie.

- [x] **Geamănul englezesc al motivului întorcea literal „Eroare 429.” sub un titlu englezesc**
  `shared/gp-api.js:986` · *mic*
  Fraza fabricată e deosebită de vorbele serverului printr-o singură comparație, iar pe ea geamănul cade pe propoziția englezească de dedesubt. Compromisul rămâne declarat și e acum fixat în amândouă direcțiile: o probă cere ca vorbele serverului să treacă, alta cere ca fraza fabricată să nu treacă. Dovezi roșu/verde: 118 · 1 și 115 · 4 pe codul stricat, 119 · 0 pe cel reparat.

- [x] **Telefonul e obligatoriu pe server, dar formularul nu-l cere**
  `Site Public.dc.html:796` · *mic*
  Câmpul de telefon primește `required` când vizitatorul nu are cont, iar eticheta devine „Telefon (obligatoriu)" — exact regula pe care o aplică serverul. Cu cont, telefonul rămâne opțional, ca și pe server. Verificat în browser, deconectat: `el.required === true`.

- [x] **Textul de sub hartă rămâne în română pe versiunea în engleză**
  `Site Public.dc.html:590` · *mic*
  Textele rămase în română pe versiunea în engleză trec prin `t()`: subtitlul din antet (`logoKicker`), titlul hărții (`mapTitle`) și legenda ei (`mapCaption`). Verificat în browser pe EN: „Artisan pastry", „Warehouse — ICTCM site, Șoseaua Olteniței 103", „ICTCM site · Șos. Olteniței 103, district 4 · opposite Piața Sudului metro station".

- [x] **Căsuța de e-mail din formularul de cerere scrie „nume@companie.ro” și pe versiunea în engleză**
  `Site Public.dc.html:946` · *mic*
  Substituentul trece prin `t()`, ca vecinii lui: `phEmail: t('nume@companie.ro', 'name@company.com')`. Textul a intrat și în lista `roInMarcaj` din `.claude/check-site-public.mjs` — prinsă după text, nu după diacritice, fiindcă tocmai lipsa lor o făcuse nevăzută. Dovada roșu/verde: 116 trecute · 1 picat pe codul stricat, 117 · 0 pe cel reparat.

- [x] **Coloana `order_requests.decided_via`, adusă de 013 ca să se știe PE UNDE a fost decisă o cerere (panou sau Telegram), nu e scrisă de nicăieri**
  `sql/013_anunturi_pe_canal.sql:102` · *mic*
  Coloana se scrie acum de la amândouă ușile: `api/routes/requests.php` pune „panou” la hotărârea luată din ecran, `api/routes/telegram.php` pune „telegram” la cea luată din butoanele anunțului, iar ocolirea scrisă lângă ele lasă acceptarea să meargă și pe un server care n-a primit încă migrarea 013. Probe: `.claude/test-defect-amanare-oarba.mjs` și `.claude/test-defect-amanare-mincinoasa.mjs` (amândouă verificate stricând reparația la loc).

---

## Medii — 1 rămase

Se văd și încurcă, dar se pot ocoli sau nu apar zilnic.

- [ ] **Dovada probelor de SEO se înroșește din când în când fără nicio vină a codului: rulată în suită, o stricăciune se vede pe pagina de acasă, dar nu și pe celelalte zece adrese**
  `.claude/test-seo-dovada.mjs:217`
  **Ce e greșit:** Dovada strică sursa pe rând, reconstruiește pachetul cu `build-deploy.mjs` și cere ca anume rândurile din `test-seo` să se înroșească. La stricăciunea „toate paginile poartă același titlu" (`seo/index.php`, titlul forțat pe `Golden Pastry`) s-au înroșit numai rândurile care privesc pagina de acasă — „are titlu" și „titlul spune ce vinde firma", citite din prima cerere a probei — dar NU și cele două care privesc cele zece adrese, „fiecare pagină are titlu" și „niciun titlu nu se repetă". Adică, în aceeași rulare a lui `test-seo`, prima cerere a văzut fișierul stricat, iar cererile de după l-au văzut întreg. Singurul care scrie în `deploy/` e `build-deploy.mjs`, chemat sincron ÎNAINTE, deci nu e o suprapunere de scrieri. Rămâne serverul: `php -S` ține o vreme în minte unde stă fiecare fișier — `realpath_cache_ttl` e 120 de secunde, iar dovada reconstruiește pachetul de zece ori la rând, adică taman înăuntrul ferestrei. `opcache` e stins (măsurat: `opcache.enable=0`), deci nu codul compilat e cel ținut în minte, ci legătura dintre cale și fișier. E o bănuială cu temei măsurat, nu o vinovăție dovedită.
  **Ce pățește omul:** Omul rulează suita înainte să publice și vede un rând roșu. Îl rulează separat: verde. Îl rulează din nou: verde. A doua oară când pățește asta, învață că „roșul ăla nu contează" — și atunci proba nu mai apără nimic, fiindcă exact așa arată și ziua în care chiar prinde ceva. Nu atinge aplicația: `test-seo` însăși a fost verde în amândouă rulările (102 · 0), iar pachetul de pe disc a fost verificat întreg după aceea.
  **Dovada:** `Măsurat pe 13 septembrie 2026, pe același cod, fără nicio modificare între rulări: 'verifica-tot' rularea 1 → 'test-seo-dovada' **28 trecute · 0 eșuate**; rularea 2 → **26 trecute · 2 eșuate**, cu „au picat în schimb: are titlu · titlul spune ce vinde firma". Separat, de trei ori la rând: 28 · 0, 28 · 0, 28 · 0. 'test-seo' singură: 102 · 0 înainte și după. 'check-pachet': 7 · 0. Sursele: zero urme de „rupt de test-seo-dovada" în 'seo/index.php' și 'seo/sitemap.php' — dovada își pune fișierele la loc și reconstruiește pachetul curat la capăt ('test-seo-dovada.mjs:302'). Măsurători de mediu: 'opcache.enable=0', 'realpath_cache_ttl=120', 'realpath_cache_size=4096K'.`

---

## Mici — 1 rămase

Nepotriviri și texte care mint mărunt.

- [ ] **Numele celor două sigle sunt exact pe dos: `logo-alb.png` e aurie, `logo-auriu.png` e albă — iar toate cele 36 de trimiteri din pagini cheamă „alb” ca să capete aur**
  `assets/logo-alb.png:1`
  **Ce e greșit:** Măsurat chiar în fișiere, cu `.claude/check-sigle.mjs`: `assets/logo-alb.png` (192×192) are cerneala aurie — culoarea cea mai deasă e `#BD9749` — iar `assets/logo-auriu.png` (256×256) are cerneala albă, `#FEFEFE`. Numele spun invers. Cele 14 pagini care pun o siglă o cer de 36 de ori, toate pe „alb”, adică cer albul tocmai ca să capete aurul; pe ecran iese bine, fiindcă fișierul e aurul. Cele două desene nu sunt nici măcar aceeași rozetă: „alb” are petale subțiri (91% din pătrat e străveziu), „auriu” are petale groase (75%).
  **Ce pățește omul:** Nu mai e o presupunere: s-a întâmplat. Cine a scris pătratul din locul fotografiei lipsă, în Catalog, voia aur pe un cartonaș deschis la culoare, a cerut firește „auriu” și a primit alb pe crem — contrast 1,22:1, adică un pătrat gol. A stat așa până a fost măsurat (vezi fișa `Produse.dc.html:493`). Cealaltă jumătate a capcanei e încă deschisă: cineva poate „repara” într-o zi nepotrivirea schimbând fișierele între ele, și atunci toate cele 36 de trimiteri își pierd deodată aurul.
  **Dovada:** `Măsurat cu 'node .claude/check-sigle.mjs', care desface PNG-ul cu zlib și numără numai pixelii cu totul opaci: 'assets/logo-alb.png' → cerneala '#BD9749' (auriu), 91% din pătrat străveziu, 192×192. 'assets/logo-auriu.png' → cerneala '#FEFEFE' (alb), 75% străveziu, 256×256. Contrastul pe fundalul '--h-bg-sunk' al temei: aurul dă 2,21:1 pe cremul de zi și 7,02:1 pe ciocolatiul de noapte; albul dă 1,22:1 și 19,01:1. Folosirea: 36 de trimiteri în 14 pagini, toate spre 'logo-alb.png'; după reparația din Catalog, 'logo-auriu.png' nu mai e chemat de nicio pagină.`

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
patru mii de probe.
