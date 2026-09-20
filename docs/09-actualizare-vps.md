# Actualizarea unei instalări de pe VPS

Instalarea o face `instaleaza-vps.sh`, o dată (vezi
`08-publicare-vps-hostinger.md`). Ghidul ăsta e despre a doua zi și despre toate
zilele de după: ai modificat ceva pe calculatorul tău și vrei modificarea pe
server, fără să pierzi comenzile de ieri și fără să scoți patiseria din priză.

Pe cPanel toate astea se făceau cu mouse-ul, prin File Manager și phpMyAdmin —
așa e scris `07-actualizari.md`, și așa rămâne pentru cine mai are un cPanel. Pe
VPS ai linie de comandă, deci ai și un script.

---

## Varianta scurtă

Pe calculatorul tău, după ce ai terminat modificările:

```bash
node .claude/verifica-tot.mjs
```

```bash
node .claude/build-deploy.mjs
```

```powershell
scp -r deploy sql actualizeaza-vps.sh root@IP_VPS:/root/
```

Pe server:

```bash
sudo bash /root/actualizeaza-vps.sh
```

Atât. Scriptul stă lângă `deploy/` și `sql/` și se descurcă singur mai departe.

---

## „Am urcat și n-a făcut update-ul"

**`Actualizat.` verde înseamnă „am pus ce am găsit", nu „am pus ce ai construit
azi".** Pe un server pe care s-a mai lucrat rămân pachete vechi — un `/root/deploy`
de la instalare, un plasture de data trecută — iar dacă scriptul dă peste unul
din alea, raportul iese exact la fel de verde și site-ul rămâne cum era.

De-aceea pachetul se ștampilează singur. `build-deploy.mjs` scrie
`deploy/PACHET.txt` (data construirii + amprenta `gp-api.js?v=…`), iar scriptul
o pune în raport la pasul „Caut pachetul":

```
▸ Caut pachetul
  Pachet: /root/plasture-vps 2.3.1/deploy
  Făcut la: 2026-09-13 20:15
  Amprenta: gp-api.js?v=ef3f2729
```

Data e a **pachetului**, nu a zilei de azi. Mai veche decât te aștepți =
s-a aplicat un pachet vechi. Un avertisment galben în locul celor două rânduri
înseamnă un pachet construit înainte ca ștampila să existe.

Cifrele de mai sus sunt **dinadins** dintr-un pachet vechi, ca să se vadă ce
înseamnă „mai veche decât te aștepți". Pe a ta o citești înainte să urci, din
proiect:

```bash
cat deploy/PACHET.txt
```

`PACHET.txt` nu pleacă pe site: se copiază numai `api/`, `public_html/`,
`clienti/` și `lab/`, iar el stă deasupra lor. E și singurul fișier din proiect
scris fără diacritice — se citește cu `cat` printr-un ssh al cărui locale poate
fi „C", iar acolo diacriticele ies ca semne de întrebare.

Verificarea care nu depinde de raport, pe server:

```bash
grep -o 'gp-api.js?v=[a-z0-9]*' /var/www/gp/public_html/app.html | head -1
```

Aceeași amprentă ca în raport = pe site e chiar pachetul tău. Pentru partea care
nu ajunge în browser (API-ul), un martor din PHP:

```bash
grep -c session_days_panou /var/www/gp/api/lib/auth.php
```

---

## Ce face, în ordine

1. **Se uită dacă are ce actualiza.** Fără `api/config.php` în `/var/www/gp` nu
   e o actualizare, ci o instalare — și te trimite la `instaleaza-vps.sh`.
2. **Citește datele bazei din `api/config.php`**, nu ți le cere. Acolo stă
   adevărul: dacă ți-ai aduce aminte greșit numele bazei, ai face copia de
   siguranță a alteia decât cea pe care o schimbi.
3. **Copie de siguranță**, în `/var/backups/golden-pastry`: baza întreagă,
   comprimată, plus `api/config.php`. Copia se și verifică — se deschide și i se
   citește ultimul rând, fiindcă un fișier rupt la mijloc arată în `ls` exact ca
   unul întreg. Dacă nu iese, scriptul se oprește acolo și nu atinge nimic
   (`actualizeaza-vps.sh:233`).
4. **Migrările.** Le rulează pe toate, de la 003 în sus, în ordine. Nu e risipă:
   fiecare migrare se uită întâi în `information_schema` și, dacă are deja ce
   caută, nu face nimic — de-aia scrie în capul fiecăreia „poate fi rulată de
   câte ori vrei fără pagubă” (`sql/007_update_roles_referral.sql:3-4`). Scriptul
   măsoară schema înainte și după fiecare, ca să-ți poată spune care a avut ceva
   de făcut și care nu (`actualizeaza-vps.sh:368`).

   `001` și `002` se sar, dinadins: prima face schema de la zero, a doua pune
   datele inițiale. A doua pare inofensivă, fiindcă are numai `INSERT IGNORE` —
   dar `IGNORE` sare peste chei duplicate, iar un produs pe care l-ai **șters**
   din Catalog n-are cu ce se ciocni și s-ar întoarce de la morți la fiecare
   actualizare (`actualizeaza-vps.sh:390`).
5. **Fișierele**, în cele patru foldere. `api/config.php` e scos și din copiere,
   și din curățenie (`actualizeaza-vps.sh:684`), iar folderul pozelor e citit din
   `photo_dir` și ocolit pe numele lui (`actualizeaza-vps.sh:614`,
   `api/routes/catalog.php:512`).
6. **Drepturile**: totul pe `www-data`, `755` la foldere, `644` la fișiere,
   `640` la `config.php`.
7. **Apache**: întâi `configtest`, și abia dacă trece, `reload`. O configurație
   stricată nu ajunge niciodată să oprească serverul. Tot aici se uită dacă
   `mod_expires` e aprins și, dacă nu e, te avertizează pe galben — nu oprește
   nimic, vezi „Avertismentul galben" mai jos.
8. **O cerere adevărată către API**, de pe server către el însuși. Nu contează ce
   răspunde, ci că răspunde: un `500` aici înseamnă că PHP a pornit și s-a
   împiedicat, iar asta e mai bine să afli acum decât de la primul client.

**Migrările merg înaintea fișierelor.** Toate sunt aditive, deci codul vechi
trece liniștit peste coloanele noi — nu le cere. Invers însă, codul nou pe o bază
veche cere coloane care nu există și scoate „Eroare internă” în fața clienților,
exact în minutele dintre cele două jumătăți ale actualizării.

---

## Ce nu atinge, niciodată

| Ce | De ce |
|---|---|
| `api/config.php` | parola bazei, cheia de criptare, codul de instalare. Nu e în pachet și nu se poate reface: cheia aia descuie tokenurile salvate din Setări. |
| pozele produselor | urcate din panoul Catalog, nu ies din niciun `build-deploy`. Folderul se citește din `photo_dir` și se ocolește. |
| rândurile din bază | toate migrările sunt aditive. Nimic nu se șterge și nimic nu se rescrie. |
| certificatele, gazdele virtuale, `apt` | treaba instalării, nu a actualizării. De-aia actualizarea ține sub un minut. |

Fișierele `api/setup.php` și `api/verifica.php` sunt un caz aparte: ghidul de
instalare cere ștergerea lor, dar amândouă sunt în pachet, deci copierea le-ar
pune la loc de fiecare dată. Scriptul ține minte care lipseau și le scoate din
nou (`actualizeaza-vps.sh:633`). Se închid și singure, cu `404`, din clipa în
care instalarea are un administrator activ — dar hotărârea rămâne a ta.

---

## Când ceva pică

Scriptul se oprește la primul semn de rău și îți spune unde. Trei locuri:

**Copia de siguranță n-a ieșit.** Nu s-a atins nimic. Cel mai des e spațiu pe
disc (`df -h`) sau drepturi lipsă pe baza de date.

**O migrare a picat.** Fișierele **nu** au fost copiate — serverul merge mai
departe cu versiunea veche, întreagă. Îți scrie pe ecran mesajul lui MySQL și
comanda cu care pui baza la loc, cu calea copiei de azi în ea:

```bash
gunzip -c /var/backups/golden-pastry/gp-ANUL-LUNA-ZIUA-ORA.sql.gz | mysql -u gp -p --default-character-set=utf8mb4 gp
```

**API-ul răspunde cu `500` la sfârșit.** Fișierele sunt copiate, deci ceva din
codul nou nu se împacă cu serverul. Scriptul îți arată ultimele rânduri din
`/var/log/apache2/gp-api-error.log`. Cea mai deasă cauză e o migrare nerulată —
dar dacă ai ajuns aici prin script, migrările au trecut, deci uită-te la mesaj.

---

## Avertismentul galben: „mod_expires nu e aprins"

Nu e o eroare și nu oprește nimic — actualizarea a mers până la capăt. După ce
repornește Apache, scriptul se uită dacă modulul `mod_expires` e aprins pe
server și, dacă nu-l găsește, îți scrie pe galben comanda care îl aprinde.

**De ce contează.** Tot capitolul de memorare în browser din `.htaccess`-ul
paginilor — cât ține browserul pozele, `.css`-ul și `.js`-ul, și, mai ales,
faptul că paginile **nu** se țin deloc — stă scris sub
`<IfModule mod_expires.c>`. „IfModule" e o condiție, nu un titlu: fără modul,
Apache sare peste blocul întreg **în tăcere**. Nicio eroare, niciun rând în
jurnal, `.htaccess`-ul pare în regulă. Iar pe Debian și Ubuntu modulul nu e
aprins din oficiu — instalările făcute înainte de 2.0.3 au pornite doar
`rewrite` și `headers`.

Aici s-a ascuns multă vreme „am urcat patch-ul și pe telefon e tot vechiul".
O pagină plecată fără `Cache-Control` și fără `Expires`, numai cu
`Last-Modified`, cade pe euristica browserului: acesta o ține cam a zecea parte
din cât e de veche fișierul. Un panou neatins de o lună rămâne „proaspăt" încă
vreo trei zile **după** publicare. Iar amprenta `?v=…` de pe `.js` și `.css` nu
apără de asta: ea e scrisă **în** pagină, deci pagina veche, scoasă din cache,
cheamă tot codul vechi.

**Ce e reparat deja.** Din 2.0.3, `.htaccess`-ul are alături un al doilea bloc,
scris pe `mod_headers`, care pune pe fișierele `.html` un
`Cache-Control: no-cache, must-revalidate`. `mod_headers` e aprins pe orice
instalare a aplicației — fără el n-ar merge nici antetele de securitate — deci
rândul acela lucrează oricum, fără nicio comandă dată de tine pe server.
De-aia avertismentul e galben, nu roșu: **paginile se împrospătează și așa.**

Ce rămâne nereparat fără `mod_expires` sunt **pozele, `.css` și `.js`**: pleacă
fără termen de păstrare, deci browserul le cere din nou la fiecare deschidere de
pagină. Site-ul merge, dar merge mai greu degeaba.

**Leacul** e o comandă dată o singură dată în viața serverului, exact cum ți-o
scrie scriptul:

```bash
sudo a2enmod expires && sudo systemctl reload apache2
```

De la actualizarea următoare avertismentul nu mai apare. Verifici oricând
singur:

```bash
apache2ctl -M | grep expires
```

---

## Opțiuni

| Opțiune | La ce e bună |
|---|---|
| `--dir CALE` | instalarea nu e în `/var/www/gp` |
| `--package CALE` | pachetul nu e lângă script |
| `--db-root-pass PAROLA` | parola contului din `config.php` nu mai merge în MySQL |
| `--copii CALE` | copiile de siguranță în altă parte |
| `--pastreaza N` | câte copii se țin (implicit 10, restul se șterg) |
| `--doar-baza` | numai copia și migrările |
| `--doar-fisiere` | numai fișierele, baza rămâne neatinsă |
| `--fara-copie` | sari peste copie — numai dacă tocmai ai făcut una cu mâna |

`--doar-baza` și `--doar-fisiere` sunt pentru repornirea unei actualizări care a
picat la mijloc: repari ce era de reparat și continui de unde a rămas.

---

## Ce verifici după

În raportul scriptului:

- **rândurile verzi „aplicată acum"** — câte unul pentru fiecare migrare pe
  care serverul n-o avea. Câte sunt nu depinde de pachetul pe care îl urci, ci
  de cât de veche e instalarea: pe una lăsată în urmă cu trei versiuni se aprind
  trei, pe una actualizată ieri niciuna. A doua oară scriu toate „era deja
  aplicată" — e în regulă, asta înseamnă. Cea mai nouă migrare din pachet e
  `sql/023_laboratorul_si_livrarea.sql`;
- **24 de tabele** în capul raportului, la „Baza de date" (ultimele două sunt
  `telegram_links` și `lab_production`, aduse de `021_raspundere.sql`). Numărul
  nu se mișcă nici la `016`, nici la `020`: prima aduce o coloană (`users.zone`)
  și o zonă în plus în lista de livrare, a doua numai un index
  (`ix_orders_client_rang` pe `orders`) — niciuna un tabel. `021` îl mișcă cu
  **două**, deși aduce patru lucruri: celelalte două sunt tot o coloană
  (`users.telegram_user_id`) și tot un index (`ix_orders_autor`). `022` nu-l
  mișcă deloc: ea pune patru coloane pe `order_requests`, un index și o cheie
  străină, deci raportul arată tot **24** și după ea; nici `023`, care
  pune două coloane și atât (`orders.lab_seq`, `users.poate_confirma_livrarea`);
- linia **`api.DOMENIU răspunde (401)`**. Un **500** înseamnă că o migrare n-a
  intrat: `tail -20 /var/log/apache2/gp-api-error.log`;
- căile celor două copii — a bazei și a codului.

Apoi, din browser: intră în panou, deschide **Recomandări** și **Catalog**. Dacă
amândouă se încarcă, actualizarea e întreagă.

Pentru `016` mai e o privire de aruncat, fiindcă ea nu se vede în numărul de
tabele: **Setări → Zone** trebuie să aibă „În afara Bucureștiului și Ilfovului"
la coada listei și **fără coordonate** — lipsa punctului nu e o scăpare, e chiar
semnalul după care opririle de acolo ies din ordinea rutei și se arată aparte,
în josul ecranului de rută.

Privirea asta e pentru actualizarea care **aduce** `016`. Dacă după aceea ai
redenumit zona sau ai scos-o din listă, rămâne cum ai lăsat-o: `016` scrie în
listă și pe conturi numai în rularea care adaugă coloana `users.zone`, iar
orice actualizare de după nu le mai atinge. La fel o zonă pe care ai șters-o
de pe un cont din **Conturi**: rămâne ștearsă.

Dacă ai redenumit zona sau ai șters zone de pe conturi **înainte** de
actualizarea asta, uită-te o dată în **Setări → Zone** după o zonă „În afara
Bucureștiului și Ilfovului" apărută din nou lângă cea redenumită de tine, și
în **Conturi** după zonele șterse care s-au întors. Migrarea de dinainte le
punea la loc la fiecare actualizare; reparația oprește asta de acum încolo, dar
nu desface ce s-a pus deja.

Iar pentru `017` privirea e și mai scurtă, fiindcă nici ea nu se vede în
numărul de tabele: în **Setări → General**, „Procent de recomandare" trebuie să
scrie **5**, nu `0`. Migrarea urcă cifra numai dacă rândul e încă cel semănat
la instalare; dacă ai pus tu vreodată o valoare de acolo — zero inclusiv — te ocolește
dinadins, și atunci `0` e răspunsul corect. Dar dacă n-ai umblat niciodată la
ea și tot scrie `0`, migrarea n-a intrat: recomandările se leagă și se numără
mai departe, însă fiecare comandă adusă prin ele naște un câștig de
`0,00 RON` — și, fiindcă procentul se îngheață pe comandă, acele comenzi
rămân la zero și după ce cifra se îndreaptă. Iar dacă acolo nu e nicio cifră, ci
o casetă închisă, cu `017_procent_recomandare.sql` scris sub ea, rândul lipsește
cu totul din tabel: până intră migrarea, aplicația socotește cu 5%, cifra din
cod, iar din panou nu poți pune alta.

Iar pentru `018` privirea e tot scurtă, și tot în **Setări → General**, fiindcă
și ea scrie setări, nu schemă: **Adresa publică** trebuie să conțină „Șos.
Olteniței 103", iar **Program de lucru** „Lu-Vi 07:00-21:00, Sâ 07:00-17:00" —
afară de cazul în care le-ai scris tu între timp, și atunci rămân ale tale. O
casetă goală nu e semnul migrării lipsă: goală și deschisă înseamnă că ai
golit-o tu, iar site-ul arată atunci adresa și programul scrise în cod. Semnul e
caseta **închisă**, în care nu se poate scrie, cu `018_adresa_si_program.sql`
numit sub ea; se deschide după actualizare și o reîncărcare a paginii. O cerere
care poartă totuși cheile fără rând (un script, un import, o pagină rămasă
deschisă de ieri) capătă un refuz care le numește pe **toate**, nu doar pe
prima, iar din salvarea aceea nu se scrie nimic: formularul se salvează întreg
sau deloc (`unknown_setting`). Până la `018`, aceeași salvare răspundea „ok" și
nu scria nimic — de-aia adresa firmei a stat ani de zile scrisă în cod, fără ca
cineva s-o poată schimba.

`019_jeton_cerere.sql` se vede în numărul de tabele: e `request_claims`, al
douăzeci și doilea. Fără el, butonul „Trimite cererea și fă-ți cont" de pe site
trimite cererea la fel — nu se pierde nicio comandă —, dar contul făcut imediat
după ea n-o vede în „Comanda mea" până n-o legați voi cu mâna.

`020_rang_client.sql` nu se vede nicăieri în raport: pune un index pe `orders`,
atât. E prima migrare care **se poate uita fără pagubă** — fără ea insignele
de client (prima comandă / client revenit / fără cont) ies la fel, doar
socoteala lor merge mai încet. Dacă vrei s-o vezi cu ochii:
`SHOW INDEX FROM orders WHERE Key_name = 'ix_orders_client_rang'`.

`021_raspundere.sql` se vede pe jumătate: cele două tabele noi urcă numărul de
la 22 la **24**, dar coloana și indexul din ea nu se văd nicăieri în raport.
Ce lipsește dacă lipsesc, pe rând. Fără `telegram_links`, butonul „Leagă
Telegramul" din „Parola mea" nu pornește. Fără `users.telegram_user_id`, orice
comandă acceptată dintr-un buton de Telegram iese pe numele celui care a legat
botul întâia oară — oricine ar fi apăsat, și **fără niciun semn**: codul cade
înapoi pe felul de dinainte, care avea un singur nume pentru tot grupul. Fără
`lab_production`, raportul de producție pe tură din „Pontaj" iese **gol, nu cu
eroare** — lipsa asta se descoperă la prima plată, nu la actualizare, deci e
singura din toate care merită privită cu ochii chiar în ziua actualizării:
`SELECT COUNT(*) FROM lab_production` după ce laboratorul a numărat ceva.
Indexul `ix_orders_autor` e ca al lui `020`, se poate uita fără pagubă.

`022_cerere_de_la_personal.sql` **nu se vede nicăieri în raport**: nici tabel,
nici setare — patru coloane pe `order_requests` (`fulfillment`, `urgent`,
`timed`, `created_by`), indexul `ix_req_autor` și cheia străină `fk_req_autor`.
O ceri singur: `SHOW COLUMNS FROM order_requests LIKE 'created_by'`.
De ea atârnă hotărârea din 18 septembrie 2026 — comanda intră de-a dreptul în
sistem numai scrisă de administrator, manager sau fondator, restul personalului
scrie o **cerere**. Fără `fulfillment`, modul de onorare ales de cel care a
vorbit cu clientul se pierde între formular și acceptare, iar
`default_fulfillment()` alege în locul lui, **tăcut și pe altceva**. Fără
`urgent` și `timed`, graba nu ajunge la cel care apasă „Acceptă". Fără
`created_by`, comanda născută din cererea agentului poartă numele
administratorului care a acceptat-o, iar agentul dispare din propria lui muncă;
și, fiindcă tot ea deosebește cererea scrisă de personal (are autor) de cea a
vizitatorului (n-are), fără ea comenzile personalului ies însemnate „site" pe
nedrept. Niciuna din cele patru nu dă eroare: se pierd tăcut, ca
`lab_production`.

`023_laboratorul_si_livrarea.sql` **nu se vede nici ea în raport**: nici tabel,
nici setare, nici index — două coloane, `orders.lab_seq` și
`users.poate_confirma_livrarea`. O ceri singur:
`SHOW COLUMNS FROM users LIKE 'poate_confirma_livrarea'`.
Fără prima, ordinea de lucru a laboratorului rămâne în browserul fiecărei
tablete, ca până acum: nimeni nu vede aceeași zi, iar împărțirea cantităților
produse — care merge de sus în jos pe lista aceea — umple alte comenzi, după
tableta de pe care s-a apăsat. Fără a doua, paza **cade deschisă dinadins**,
deci etapa finală („Predată" = ajunsă la client) o pune iar oricine din
personal, tăcut, ca înainte de actualizare. Semnul se bifează pe urmă din
„Conturi", pe contul șoferului; **nimeni nu-l primește la migrare**, nici
conturile de operații de azi — contul de laborator e o tejghea comună, și
tocmai el n-are voie să-l aibă. Administratorul și fondatorul îl au din cod.

Raportul face singur privirea asta, după `Bilanț:`: citește din bază procentul,
adresa și programul și le spune cu valoarea lor (`actualizeaza-vps.sh:442`,
`:521`). Un rând galben care spune că `017` sau `018` **n-a intrat** e o migrare
lipsă — aproape mereu un folder `sql/` urcat mai vechi decât pachetul; la
procent îl dă și un `0` rămas de la casă, pe care `017` l-ar fi urcat la 5. Un
rând galben care începe cu **„N-am putut citi…"**, cu eroarea lui mysql
dedesubt, e altceva: migrările au trecut, a picat numai întrebarea de după ele,
deci raportul nu știe ce e acolo și nu ghicește. Nu rulezi din nou pentru atât;
te uiți tu, în Setări. La fel vorbește și rândul conturilor făcute pe site pe
care nu le-a recunoscut nimeni (`:582`): acolo o citire picată tăcea înainte,
exact ca „niciun cont", iar acum se aude.

---

## Varianta cu mâna

Dacă vrei să faci pașii unul câte unul, sunt exact aceștia:

```bash
mkdir -p /var/backups/golden-pastry
```

```bash
mysqldump --single-transaction --no-tablespaces --default-character-set=utf8mb4 -u gp -p gp | gzip > /var/backups/golden-pastry/gp-$(date +%F-%H%M%S).sql.gz
```

```bash
for f in /root/sql/[0-9][0-9][0-9]_*.sql; do case "$(basename "$f")" in 001_*|002_*) continue;; esac; mysql --default-character-set=utf8mb4 -u gp -p gp < "$f"; done
```

```bash
rsync -a --delete --exclude '/products' /root/deploy/public_html/ /var/www/gp/public_html/
```

```bash
rsync -a --delete /root/deploy/clienti/ /var/www/gp/clienti/ && rsync -a --delete /root/deploy/lab/ /var/www/gp/lab/
```

```bash
rsync -a --delete --exclude '/config.php' /root/deploy/api/ /var/www/gp/api/
```

```bash
chown -R www-data:www-data /var/www/gp && chmod -R u=rwX,go=rX /var/www/gp && chmod 640 /var/www/gp/api/config.php && systemctl reload apache2
```

Două lucruri de știut dacă le tastezi singur:

- **`--no-tablespaces` nu e de decor.** Pe MySQL 8, `mysqldump` cere dreptul
  global `PROCESS` ca să citească tablespace-urile, iar contul aplicației are
  drepturi doar pe baza ei. Fără opțiune, copia pică exact pe serverul unde
  totul e în regulă.
- **`--default-character-set=utf8mb4` la fiecare comandă.** Fără el, diacriticele
  din migrări ajung stricate în bază, iar „Predată” devine un text pe care
  aplicația nu-l mai recunoaște ca etapă.

---

## De ce nu e de ajuns să rulezi din nou instalarea

`instaleaza-vps.sh` se poate rula de câte ori vrei și a doua oară se poartă ca o
actualizare — dar una parțială. Nu aplică migrările, dinadins: pe o bază care are
deja tabele nu importă nimic (`instaleaza-vps.sh:205`). Și nu face copie de
siguranță înainte, fiindcă la instalare n-are ce copia.

Pe lângă asta, trece de fiecare dată prin `apt`, prin gazdele virtuale și prin
certbot — minute în plus și trei ocazii de stricat ceva ce mergea. Scriptul de
actualizare le sare pe toate.

Prima oară `instaleaza-vps.sh`. De atunci încolo, `actualizeaza-vps.sh`.
