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
  Pachet: /root/plasture-vps 2.0.4/deploy
  Făcut la: 2026-09-13 20:15
  Amprenta: gp-api.js?v=ef3f2729
```

Data e a **pachetului**, nu a zilei de azi. Mai veche decât te aștepți =
s-a aplicat un pachet vechi. Un avertisment galben în locul celor două rânduri
înseamnă un pachet construit înainte ca ștampila să existe.

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
   și din curățenie (`actualizeaza-vps.sh:604`), iar folderul pozelor e citit din
   `photo_dir` și ocolit pe numele lui (`actualizeaza-vps.sh:551`,
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
nou (`actualizeaza-vps.sh:570`). Se închid și singure, cu `404`, din clipa în
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
  `sql/018_adresa_si_program.sql`;
- **21 de tabele** în capul raportului, la „Baza de date" (a douăzeci și una
  e `lab_messages`, adusă de `015_mesaje_laborator.sql`). Numărul rămâne 21 și
  după `016`: aceea aduce o coloană (`users.zone`) și o zonă în plus în lista
  de livrare, nu un tabel;
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

Iar pentru `017` privirea e și mai scurtă, fiindcă nici ea nu se vede în
numărul de tabele: **Setări → Procent de recomandare** trebuie să scrie **5**,
nu `0`. Migrarea urcă cifra numai dacă rândul e încă cel semănat la instalare;
dacă ai pus tu vreodată o valoare de acolo — zero inclusiv — te ocolește
dinadins, și atunci `0` e răspunsul corect. Dar dacă n-ai umblat niciodată la
ea și tot scrie `0`, migrarea n-a intrat: recomandările se leagă și se numără
mai departe, însă fiecare comandă adusă prin ele naște un câștig de
`0,00 RON` — și, fiindcă procentul se îngheață pe comandă, acele comenzi
rămân la zero și după ce cifra se îndreaptă.

Iar pentru `018` privirea e tot scurtă, și tot în Setări, fiindcă și ea scrie
setări, nu schemă: **Adresa publică** trebuie să conțină „Șos. Olteniței 103",
iar **Program de lucru** „Lu-Vi 07:00-21:00, Sâ 07:00-17:00" — afară de cazul în care le-ai
scris tu între timp, și atunci rămân ale tale. Dacă amândouă sunt goale,
migrarea n-a intrat, și se vede pe loc: scrii ceva în ele, apeși „Salvează" și
capeți un refuz care numește cheia (`unknown_setting`). Până la `018`, aceeași
apăsare răspundea „ok" și nu scria nimic — de-aia adresa firmei a stat ani de
zile scrisă în cod, fără ca cineva s-o poată schimba.

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
