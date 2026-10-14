# Actualizarea unei instalări de pe VPS

Instalarea o face `instaleaza-vps.sh`, o dată (vezi
`08-publicare-vps-hostinger.md`). Ghidul ăsta e despre a doua zi și despre toate
zilele de după: ai modificat ceva pe calculatorul tău și vrei modificarea pe
server, fără să pierzi nicio comandă luată până atunci și fără să scoți
patiseria din priză.

Pe găzduirea veche, cu cPanel, toate astea se făceau cu mouse-ul, prin File
Manager și phpMyAdmin. cPanel nu mai e o cale: serverul de azi e un VPS, iar pe
VPS ai linie de comandă, deci ai și un script. `07-actualizari.md` a rămas
ghidul regulilor care nu țin de unealtă — ce nu se suprascrie niciodată, ce se
poate reimporta și ce nu, ce verifici după.

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

La actualizarea care aduce 2.7.0, citește întâi „Ziua actualizării la 2.7.0”,
mai jos: primul lucru de acolo se face **înainte** să urci. La cea care aduce
2.7.5 n-ai nimic de făcut înainte, dar ai patru lucruri de făcut **după**, în
ordine: sunt la „Ziua actualizării la 2.7.5”.

---

## „Am urcat și n-a făcut update-ul"

**`Actualizat.` verde înseamnă „am pus ce am găsit", nu „am pus ce ai construit
azi".** Pe un server pe care s-a mai lucrat rămân pachete vechi — un `/root/deploy`
de la instalare, un plasture de la o actualizare mai veche — iar dacă scriptul
dă peste unul din alea, raportul iese exact la fel de verde și site-ul rămâne
cum era.

De-aceea pachetul se ștampilează singur. `build-deploy.mjs` scrie
`deploy/PACHET.txt` (data construirii + amprenta `gp-api.js?v=…`), iar scriptul
o pune în raport la pasul „Caut pachetul":

```
▸ Caut pachetul
  Pachet: /root/plasture-vps 2.7.5/deploy
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
nu ajunge în browser (API-ul), un martor din PHP, ales din codul rundei 2.7.0:

```bash
grep -c GP_DREPT_IMPLICIT /var/www/gp/api/lib/auth.php
```

Un `0` înseamnă că API-ul de pe server e mai vechi de 2.7.0: numele acela a
apărut în cod abia odată cu ea. Orice alt număr înseamnă că a urcat.

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
   (`actualizeaza-vps.sh:237`). Tot aici se face și o arhivă a codului de
   dinainte — cele patru foldere, fără `config.php` și fără poze —, cu care te
   poți întoarce la versiunea veche fără să atingi baza; comanda ți-o scrie
   raportul, la sfârșit.
4. **Migrările.** Le rulează pe toate, de la 003 în sus, în ordine. Nu e risipă:
   fiecare migrare se uită întâi în `information_schema` și, dacă are deja ce
   caută, nu face nimic — de-aia scrie în capul fiecăreia „poate fi rulată de
   câte ori vrei fără pagubă” (`sql/007_update_roles_referral.sql:3-4`).

   **Cum îți spune care a intrat acum.** Fiecare migrare își scrie numele într-un
   registru — tabelul `schema_migrations` — la coada ei, iar scriptul îl citește.
   Registrul e făcut de `sql/025_registru_migrari.sql`, rulat **înaintea buclei**,
   nu la rândul lui: se cheamă `025`, deci în ordinea numelui ar veni după
   `003`–`024`, iar atunci primele douăzeci și două de migrări ar trece pe lângă
   un tabel care încă nu există și și-ar scrie numele abia la actualizarea
   următoare.

   Registrul **însemnează, nu oprește**: migrările se rulează toate, la fiecare
   actualizare, exact ca înainte. Pe un `sql/` mai vechi decât `025` scriptul se
   întoarce singur la socoteala dinainte — măsoară schema înainte și după fiecare
   (`actualizeaza-vps.sh:372`). Aceea e însă o ghicitoare, și se înșela tocmai
   unde doare: `017` nu atinge DELOC schema, deci ieșea „era deja aplicată" chiar
   și la prima rulare — despre singura migrare care face recomandările să aducă
   bani.

   `001` și `002` se sar, dinadins: prima face schema de la zero, a doua pune
   datele inițiale. A doua pare inofensivă, fiindcă are numai `INSERT IGNORE` —
   dar `IGNORE` sare peste chei duplicate, iar un produs pe care l-ai **șters**
   din Catalog n-are cu ce se ciocni și s-ar întoarce de la morți la fiecare
   actualizare (`actualizeaza-vps.sh:530`).
5. **Fișierele**, în cele patru foldere. `api/config.php` e scos și din copiere,
   și din curățenie (`actualizeaza-vps.sh:890`), iar folderul pozelor e citit din
   `photo_dir` și ocolit pe numele lui (`actualizeaza-vps.sh:820`,
   `api/routes/catalog.php:874`). Folderul fișelor tehnice, `public_html/fise/`,
   **nu** e ocolit: vine cu pachetul și se scrie peste, cu tot cu curățenie, deci
   o fișă care nu mai e în pachet dispare și de pe server. Fișele încărcate din
   Catalog nu stau acolo, ci lângă poze, în `products/`, așa că le apără aceeași
   ocolire.
6. **Drepturile**: totul pe `www-data`, `755` la foldere, `644` la fișiere,
   `640` la `config.php`.
7. **Ceasul plăților**, de la 2.7.0. Se uită dacă fișierul
   `/etc/cron.d/goldenpastry-plati` e la locul lui și are textul bun, și îl
   scrie numai dacă lipsește ori diferă. E rândul care pornește raportul de
   încasări de pe Telegram fără să fie cineva pe site. Vine după drepturi
   fiindcă rândul cheamă un fișier pe care abia copierea de la pasul 5 l-a
   adus; ce scrie, când tace și ce înseamnă rândurile lui galbene e la „Ceasul
   plăților”, mai jos.
8. **Apache**: întâi `configtest`, și abia dacă trece, `reload`. O configurație
   stricată nu ajunge niciodată să oprească serverul. Tot aici se uită dacă
   `mod_expires` e aprins și, dacă nu e, te avertizează pe galben — nu oprește
   nimic, vezi „Avertismentul galben" mai jos.
9. **O cerere adevărată către API**, de pe server către el însuși. Nu contează ce
   răspunde, ci că răspunde: un `500` aici înseamnă că PHP a pornit și s-a
   împiedicat, iar asta e mai bine să afli acum decât de la primul client.
10. **Programul de poștă**, de la 2.7.5. Dacă serverul n-are niciun program
    prin care PHP să poată trimite e-mail, pune unul: postfix, numai de trimis.
    Dacă are deja unul, nu-l atinge. Vine la urmă dinadins: până aici
    actualizarea a reușit întreagă, iar pasul ăsta nu o mai poate strica —
    orice ar păți, spune pe galben ce n-a ieșit și merge mai departe. Cu
    `--fara-posta` îl sare; cu `--doar-baza` nu rulează. Ce pune, de ce, și ce
    înseamnă fiecare rând galben e la „Programul de poștă”, mai jos.

**Migrările merg înaintea fișierelor.** Toate sunt aditive, deci codul vechi
trece liniștit peste coloanele noi — nu le cere. Invers însă, codul nou pe o bază
veche cere coloane care nu există și scoate „Eroare internă” în fața clienților,
exact în minutele dintre cele două jumătăți ale actualizării.

---

## Ce nu atinge, niciodată

| Ce | De ce |
|---|---|
| `api/config.php` | parola bazei, cheia de criptare, codul de instalare. Nu e în pachet și nu se poate reface: cheia aia descuie tokenurile salvate din Setări. |
| pozele produselor | urcate din panoul Catalog, nu ies din niciun `build-deploy`. Folderul se citește din `photo_dir` și se ocolește. Tot acolo stau și fișele tehnice încărcate din Catalog. Fișele venite cu pachetul, din `fise/`, **nu** sunt în rândul ăsta: ele se scriu peste la fiecare actualizare. |
| rândurile din bază | toate migrările sunt aditive. Nimic nu se șterge și nimic nu se rescrie. |
| certificatele, gazdele virtuale, pachetele serverului | treaba instalării, nu a actualizării. De-aia actualizarea ține sub un minut. Tot de-aia nu instalează nici `cron`, dacă serverul nu-l are: îți spune comanda și merge mai departe (vezi „Ceasul plăților”). **O singură abatere**, de la 2.7.5: programul de poștă. Dacă serverul n-are niciunul, pasul de la coadă îl instalează (un pachet, o dată; poate ține un minut) — fără el, e-mailul n-ar avea pe unde pleca până completezi căsuța. Cu `--fara-posta` nu-l pune (vezi „Programul de poștă”). |
| un program de poștă pe care serverul îl are deja | pasul de la coadă îl recunoaște și **nu-i schimbă nimic**, oricare ar fi el. Se uită numai, fără să scrie, dacă merge și dacă e așezat cum trebuie, și îți spune pe galben ce a găsit. |
| orele raportului, chatul lui, pragul, adresa panoului | alese de tine în „Încasări” ori, de la 2.7.5, în Setări → Notificări. Migrarea `032` le scrie numai dacă lipsesc, deci o actualizare de după nu le calcă. |
| căsuța de e-mail scrisă în Setări → Notificări | cele cinci rânduri ale ei le pune migrarea `037` cu `INSERT IGNORE`: ce e deja scris rămâne, cu tot cu parola căsuței. |

Fișierele `api/setup.php` și `api/verifica.php` sunt un caz aparte: ghidul de
instalare cere ștergerea lor, dar amândouă sunt în pachet, deci copierea le-ar
pune la loc de fiecare dată. Scriptul ține minte care lipseau și le scoate din
nou (`actualizeaza-vps.sh:839`). Se închid și singure, cu `404`, din clipa în
care instalarea are un administrator activ — dar hotărârea rămâne a ta.

---

## Când ceva pică

Scriptul se oprește la primul semn de rău și îți spune unde. Trei locuri:

**Copia de siguranță n-a ieșit.** Nu s-a atins nimic. Cel mai des e spațiu pe
disc (`df -h`) sau drepturi lipsă pe baza de date.

**O migrare a picat.** Fișierele **nu** au fost copiate — serverul merge mai
departe cu versiunea veche, întreagă. Îți scrie pe ecran mesajul lui MySQL și
comanda cu care pui baza la loc. **O copiezi de pe ecran, nu din ghid**:
scriptul a pus deja în ea calea copiei de azi și numele bazei, deci n-ai nimic
de completat, iar comanda nu cere nicio parolă. Are forma asta, cu numele tale
în locul punctelor:

```
gunzip -c /var/backups/golden-pastry/gp-….sql.gz | sudo mysql --default-character-set=utf8mb4 …
```

Până la 2.7.5 rândul tipărit cerea parola bazei (`mysql -u … -p`), pe care n-ai
de unde s-o știi pe de rost: o generează instalarea și stă numai în
`api/config.php`. Atenție la ce face: duce baza înapoi la clipa copiei și
**șterge tot ce s-a scris de atunci**. După o migrare picată nu s-a scris încă
nimic nou, deci aici e chiar leacul.

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

## Pozele mari și cele trei plafoane

Din 2.4.2 Catalogul primește fotografii de până la **10 MB**, iar serverul le
micșorează singur la 1600 de puncte pe latura lungă. Asta înseamnă că poți urca
direct de pe telefon, fără să mai treci poza prin vreun program.

Dar o poză care urcă trece prin **trei plafoane**, nu unul, iar ele cad în
grija a două unelte deosebite. Două le pune plasturele; al treilea, când
există, e numai al tău.

| # | Plafonul | Cine îl pune | Ce vezi când lovești în el |
|---|---|---|---|
| 1 | `upload_max_filesize` | plasturele | „Serverul primește acum fotografii de cel mult…" |
| 2 | `post_max_size` | plasturele | același mesaj — corpul cererii cară și plicul, nu doar fișierul |
| 3 | `client_max_body_size` | **tu, dacă ai nginx** | „Serverul web a oprit fișierul înainte să ajungă la aplicație" |

**Primele două sunt ale lui PHP** și vin gata scrise în pachet, în două locuri,
fiindcă PHP le citește pe căi deosebite după cum e pornit:

- `api/.htaccess` — blocul `php_value`, citit de **Apache cu PHP ca modul**.
  Stă sub `<IfModule php_module>` dinadins: pe un server unde modulul lipsește,
  rândurile acelea nepăzite ar face Apache să răspundă **500 la tot**, nu doar
  la poze. Măsurat pe Apache 2.4.68.
- `api/.user.ini` — perechea pentru **PHP-FPM**, adică felul în care rulează
  PHP pe mai toate instalările de azi. Acolo `php_value` din `.htaccess` nu se
  aplică deloc, iar fișierul ăsta rămâne singurul care vorbește.

Nu trebuie să alegi între ele: se urcă amândouă, iar fiecare server îl citește
pe al lui și îl trece cu vederea pe celălalt.

**Al treilea nu e al lui PHP, și de-aia e cel care surprinde.**
`client_max_body_size` e al lui nginx, iar nginx **taie cererea înainte să
pornească PHP**. Nu e vorba de un mesaj mai puțin limpede: e vorba de niciun
mesaj. Codul nostru care spune „poza e prea mare" e scris în PHP, iar PHP nu
apucă să ruleze. Browserul primește o pagină „413" a lui nginx, goală.
Implicitul lui nginx e **1 MB** — adică sub aproape orice fotografie făcută cu
telefonul.

**De unde știi dacă te privește.** Deschide `https://api.DOMENIU/verifica.php`
și caută rândul **„Plafonul serverului web (PHP nu-l poate vedea)"**. El scrie
ce server ai, cu numele lui, și — când e nginx — cifra pe care trebuie s-o pui.
Pe Apache rândul e verde și n-ai nimic de făcut: `.htaccess` chiar se citește
acolo, iar al treilea plafon nici nu există. Pagina se deschide însă numai cât
instalarea n-are încă un administrator; pe un server în lucru răspunde `404`
(vezi „Ce nu atinge, niciodată”), iar acolo semnul e chiar mesajul din tabel, la
prima poză mare urcată din Catalog.

**Leacul, pe nginx.** Se dă o singură dată în viața serverului. În blocul
`server` al site-ului — de obicei `/etc/nginx/sites-available/` —, lângă
`server_name`:

```
client_max_body_size 13M;
```

apoi:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

`nginx -t` cântărește configurarea **înainte** de repornire: dacă ai greșit o
acoladă, îți spune pe ce rând, iar site-ul rămâne în picioare cu cea veche.
Fără el, un `reload` peste o configurare stricată poate lăsa serverul oprit.

**De ce 13, dacă panoul promite 10.** Fiindcă `post_max_size` cântărește
**corpul întreg al cererii**, nu fișierul: peste poză mai vin numele câmpurilor,
hotarele dintre ele și latura pe care se urcă. Cele trei cifre din pachet sunt
deci în scară — 10 promis, 11 îngăduit pe fișier, 13 pe cerere —, iar plafonul
lui nginx trebuie să fie **cel puțin cât cel mai mare dintre ele**. Mai mult nu
strică: el nu rezervă nimic, doar taie peste o cifră.

**Dacă ai Apache, nu ai ce face aici.** Ghidul de instalare
(`08-publicare-vps-hostinger.md`) cere Apache anume, fiindcă rescrierile de
adrese ale aplicației sunt scrise în `.htaccess`, iar nginx nu-l citește. Pe o
instalare făcută după el, rândul din `verifica.php` va fi verde de la sine.

---

## Ceasul plăților

Prima actualizare la 2.7.0 aduce pe server un lucru pe care până atunci nu-l
avea: ceva care **rulează singur, fără să fie cineva pe site**. Raportul de
încasări de pe Telegram și amintirile pentru plățile amânate nu le cere nicio
pagină; le pornește ceasul sistemului, `cron`, care din cinci în cinci minute
rulează un fișier din pachet, `api/cron/plati.php`. Fără rândul lui nu pleacă
niciun raport, oricât de bine ar fi ales chatul în „Încasări”, și nimic din
panou nu spune de ce. De aceea scriptul îl pune singur, la pasul „Ceasul
plăților”, și spune pe față când n-a putut.

**De la 2.7.5, unde scrie mai jos „în „Încasări””, citește „în „Încasări” ori
în Setări → Notificări”.** Chatul, orele, amintirile și adresa panoului se aleg
la fel din cutia „Raport de bani pe Telegram”, ultima din cartonașul
„Telegram”. Sunt aceleași setări, arătate în două pagini: ce salvezi într-un
loc găsești în celălalt. Cutia are „Salvează” al ei; butonul din bara de sus a
Setărilor nu salvează raportul. Tot acolo stau „Trimite acum un raport de
probă” și „Ultimele trimiteri”.

**Ce scrie.** Un singur fișier, `/etc/cron.d/goldenpastry-plati`, al lui
`root`, cu drepturile `644` și cu exact aceste trei rânduri:

```
# Golden Pastry: raportul de încasări și amintirile de scadență, pe Telegram.
# Scris de scripturile de instalare și de actualizare; nu se editează de mână.
*/5 * * * * www-data flock -n /run/lock/gp-plati.lock php /var/www/gp/api/cron/plati.php 2>&1 | logger -t gp-plati
```

E același text, literă cu literă, pe care îl scrie și `instaleaza-vps.sh`; ce
înseamnă fiecare bucată din rândul al treilea e în
[08-publicare-vps-hostinger.md](08-publicare-vps-hostinger.md), la pasul 6b.
Calea din rând e a folderului de instalare: cu `--dir`, e cea dată acolo.

**Când îl scrie și când nu.** Scriptul pune textul de pe server lângă cel de
mai sus și scrie **numai dacă diferă**. La actualizarea care îl aduce, raportul
spune
`/etc/cron.d/goldenpastry-plati — scris (din 5 în 5 minute; se citește cu: journalctl -t gp-plati)`;
la toate cele de după, `/etc/cron.d/goldenpastry-plati — neschimbat`. Pricina e
a lui `cron`: el recitește un fișier când i se schimbă data, iar unul rescris
la fiecare actualizare ar arăta, în `ls -l`, ca umblat de fiecare dată. Urmarea
pentru tine e dublă. Fișierul nu se editează de mână — orice ai schimba în el,
actualizarea următoare îl întoarce la textul de mai sus —, iar dacă l-ai șters,
tot ea îl pune la loc.

Pasul ține de fișiere, nu de bază. Rulează deci și la `--doar-fisiere`, dar
**nu** la `--doar-baza`: acolo n-a venit niciun fișier nou, iar rândul cheamă
unul pe care abia copierea îl aduce.

**Când nu-l poate pune.** Actualizarea nu se oprește pentru ceas — baza și
fișierele sunt deja la locul lor —, deci tot ce urmează vine pe galben, nu pe
roșu, într-un raport care se încheie altfel cu `Actualizat.` verde. Trei
rânduri se termină cu „Ceasul NU a fost pus”. Unul spune că folderul de
instalare are în cale semne pe care `cron` le citește altfel decât terminalul,
un spațiu ori un `%`; scriptul primește în cale numai litere, cifre și semnele
`_`, `.`, `-` și `/`. Atunci nu scrie nimic și nici nu atinge un fișier pus de
tine, deci rândul rămâne de scris de mână — dar nu întocmai ca mai sus, fiindcă
textul de mai sus, cu o asemenea cale, s-ar strica în `cron` la fel: calea se
pune între ghilimele drepte, iar fiecare `%` din ea se scrie `\%`. Un folder
fără asemenea semne, cum e cel din pornire, `/var/www/gp`, te scutește de asta.
Altul, că pachetul nu are `api/cron/plati.php`, adică e mai vechi de 2.7.0. Al
treilea, că serverul nu are folderul `/etc/cron.d`; îți dă comanda
`apt-get install -y cron`, după care rulezi actualizarea încă o dată, cu
`--doar-fisiere`.

Al patrulea caz e cel care contează pe un server instalat **înainte de 2.7.0**.
Instalarea are `cron` în lista ei de pachete abia de la 2.7.0, deci un server
mai vechi poate să nu-l aibă — iar folderul `/etc/cron.d` există și fără el,
fiindcă îl aduc alte pachete. Rândul se scrie atunci fără nicio piedică și nu-l
citește nimeni, niciodată. De aceea scriptul întreabă, după scriere, de program
și de serviciu, la fiecare actualizare cu fișiere. Dacă programul lipsește,
scrie „Rândul e scris, dar serverul nu are programul cron: ceasul NU merge.” și
comanda care îl pune:

```bash
apt-get install -y cron
```

Actualizarea nu instalează `cron` (singurul pachet pe care îl pune ea, de la
2.7.5, e programul de poștă), deci comanda o dai tu, o singură dată în viața
serverului. După ea nu mai rulezi nimic: `cron` pornește singur, iar
rândul e deja la locul lui. Dacă programul există, scriptul îl pornește, îl
lasă pornit la fiecare repornire a mașinii și scrie „serviciul cron merge”;
dacă tot nu merge, scrie „Rândul e scris, dar serviciul cron e oprit: ceasul NU
merge.”, cu `systemctl status cron` ca să vezi de ce.

**Ce înseamnă pentru patiserie.** Raportul pleacă la cele două ore alese în
„Încasări” — din pornire 09:00 și 18:00, ceasul României — și numai după ce
i-ai ales chatul. Ceasul bate din cinci în cinci minute, deci raportul de la
09:00 sosește între 09:00 și 09:05.

Dacă serverul e oprit la ora raportului — o repornire, o pană la găzduire —,
raportul nu se pierde pe loc. La prima bătaie de după, ceasul vede că ora a
trecut și îl trimite atunci, dar numai dacă au trecut mai puțin de **două
ore**. De la două ore în sus îl sare și așteaptă ora următoare: un raport „de
dimineață” sosit după-amiaza ar spune o poveste veche cu aer de proaspătă.
Cele două ore sunt hotărârea luată în runda 2.7.0 și stau în cod, nu în
„Încasări”: din panou nu se mută. Regula ține și peste miezul nopții — o oră
pusă la 23:30 e prinsă și la 00:10.

Același raport nu pleacă niciodată de două ori. Înainte să trimită, ceasul își
însemnează în bază că ora aceea e luată, și abia apoi trimite; bătaia de peste
cinci minute găsește însemnarea și trece mai departe. Prețul e spus pe față:
dacă trimiterea pică tocmai atunci, raportul acela **nu se mai încearcă**.
Rămâne scris ca neplecat, cu pricina lui, în „Încasări”, la „Ultimele
trimiteri”, iar cifrele le poți cere pe loc cu „Trimite acum un raport de
probă”. Ordinea pe dos ar fi fost mai rea: un raport trimis și neînsemnat ar fi
plecat din nou la fiecare cinci minute, două ore la rând.

Amintirile pentru amânări merg altfel, fiindcă pentru ele nu vine „altul” peste
câteva ore. Pleacă o singură dată pentru fiecare amânare, după raport și numai
dacă raportul a plecat; dacă pică tocmai mesajul lor, se încearcă din nou la
ora următoare. O amânare ajunsă la termen într-o zi în care serverul a stat
oprit nu se pierde nici ea: e spusă la primul raport de după, cu întârzierea
scrisă în text.

**Cum vezi că merge.** În raportul actualizării, la pasul „Ceasul plăților”,
trebuie să stea două rânduri fără galben: cel cu fișierul — „scris” la prima
actualizare, „neschimbat” de atunci încolo — și „serviciul cron merge”. Apoi,
pe server, jurnalul sistemului:

```bash
journalctl -t gp-plati -n 20
```

Trebuie să vezi câte un rând la fiecare cinci minute. În fața fiecăruia,
`journalctl` pune ceasul serverului, care poate fi altul decât al României;
data și ora României sunt cele de după cuvântul `plati`, ca în
`plati 2026-10-12 09:00 · nimic de trimis`. Cele pe care le vei întâlni:

| Rândul se încheie cu | Ce înseamnă |
|---|---|
| `nimic de trimis` | e între ore; așa arată mai toate rândurile unei zile |
| `raportul de la 09:00 a plecat` | a trimis |
| `… · amintirea pentru 2 amânări a plecat` | după raport a plecat și mesajul cu amânările ajunse la termen |
| `… · amintirea pentru 2 amânări NU a plecat; se încearcă din nou la ora următoare: …` | raportul a plecat, mesajul amânărilor nu; pricina e scrisă după cele două puncte, iar amânările acelea sunt spuse din nou la raportul următor |
| `nicio amânare de amintit` | ai oprit amândouă orele și ai lăsat „Amintiri la Amânate” aprins: la 09:00 ceasul a căutat amânări ajunse la termen și n-a găsit niciuna |
| `oprit: Raportul de bani nu are chat. …` | n-ai ales încă chatul, în „Încasări” |
| `oprit: nicio oră de raport aleasă, iar amintirile sunt oprite` | le-ai oprit tu, din „Încasări” |
| `raportul de la 09:00 NU a plecat și nu se mai încearcă: …` | Telegram a refuzat ori n-a răspuns; pricina e scrisă după cele două puncte |
| `nimic de făcut: baza nu are încă plățile (migrarea 032)` | ai urcat numai fișierele, iar baza n-are încă `032`: rulează actualizarea întreagă |
| `… · spre GAZDĂ (telegram_api_base din config.php)` | în `config.php` de pe server a rămas cheia de probă `telegram_api_base`, iar rapoartele nu ajung la Telegram: golește-o |

Niciun rând nou de mai mult de cinci minute înseamnă că `cron` nu rulează:
`systemctl status cron`.

Rândul se poate cere și pe loc, cu o rulare care **nu trimite și nu însemnează
nimic** — numai tipărește ce ar pleca dacă ar fi ora:

```bash
sudo -u www-data php /var/www/gp/api/cron/plati.php --uscat --acum="2026-10-12 09:02"
```

La `--acum` scrii ziua în care ești și o oră de raport, în ceasul României;
fără el, ceasul judecă pentru clipa de față și, între ore, răspunde doar „nimic
de trimis”. Tot „nimic de trimis” răspunde și dacă raportul orei aceleia a
plecat deja în ziua scrisă: fiecare oră se trimite o singură dată pe zi, iar
proba ține seama de asta. Scrie atunci ziua de mâine: textul tipărit e socotit
pentru ziua scrisă, nu pentru cea în care ești. Cât n-ai ales chatul, și proba
spune tot „oprit: Raportul de bani nu are chat. …”, ca ceasul adevărat, deci
textul raportului îl vezi abia după ce ai pus chatul, în „Încasări”.
Rulează-l ca `www-data`, nu ca `root`: așa afli și dacă acel cont poate citi
`config.php`, adică exact ce i se cere ceasului adevărat. În panou, aceleași
trimiteri se văd în „Încasări”, la „Ultimele trimiteri”; cardul în care stau,
„Raport pe Telegram”, îl vede din pornire numai fondatorul.

**Cum îl oprești.** Dacă nu vrei rapoarte, nu umbla la server: în „Încasări”,
la cardul „Raport pe Telegram” (ori, de la 2.7.5, în Setări → Notificări, la
cutia „Raport de bani pe Telegram”), apasă „Oprește” la „Ora 1” și la „Ora 2”,
stinge „Amintiri la Amânate” și apasă „Salvează”, cel al cardului. Ceasul bate mai departe, dar
scrie „oprit: nicio oră de raport aleasă, iar amintirile sunt oprite” și nu
trimite nimic; îl pornești la loc scriind orele înapoi și salvând. Fișierul din
`/etc/cron.d` se poate și șterge, dar ține numai până la actualizarea
următoare, care îl pune la loc — deci, dacă vrei liniște și după ea, oprește
orele, nu fișierul.

**Dacă întorci codul la versiunea veche.** Raportul actualizării îți scrie, la
sfârșit, comanda care despachetează arhiva codului de dinainte peste folderul
aplicației. Ea pune la loc fișierele vechi, dar **nu șterge nimic**: ceasul,
rândul lui din `/etc/cron.d` și coloanele lui `032` din bază rămân. Ceasul ar
bate deci mai departe, din cinci în cinci minute, lângă un cod care nu mai e al
lui. Pentru asta are o pază a lui: dacă bibliotecile de lângă el nu sunt ale
rundei lui, nu trimite nimic și scrie în jurnal „oprit: codul de lângă ceas e
mai vechi decât el”, cu sfatul de mai jos. Lăsat așa nu strică nimic, dar
jurnalul se umple cu același rând la fiecare cinci minute; îl taci ștergând
fișierul:

```bash
rm -f /etc/cron.d/goldenpastry-plati
```

Actualizarea următoare cu un pachet de la 2.7.0 în sus îl pune la loc.

**Dacă întorci codul la 2.7.0 și ai completat căsuța de e-mail.** Codul 2.7.0
ascunde parola căsuței (`mail_smtp_password`, adusă de migrarea 037) ca pe
celelalte chei ascunse: cu buline, dar cu ULTIMELE PATRU SEMNE la vedere,
oricui are dreptul „Setări” (2.7.5 nu trimite din ea nimic). Deci, înainte să
întorci codul, deschide Setări › Notificări › E-mail, apasă „Șterge parola” și
„Salvează”. Dacă ai întors deja codul, schimbă parola căsuței la furnizorul de
e-mail. Când urci din nou 2.7.5, scrii parola nouă în „Parola căsuței”.

Ce ai de făcut în panou în ziua în care urci 2.7.0 — chatul, orele, pragul —
e mai jos, la „Ziua actualizării la 2.7.0”.

---

## Programul de poștă

Prima actualizare la 2.7.5 se încheie cu un pas nou, „Programul de poștă”.
De la 2.7.5, e-mailul aplicației — anunțurile de comenzi și linkurile de la
„Ți-ai uitat parola?” — are două drumuri: **căsuța firmei**, când e completată
în Setări → Notificări, în cartonașul „E-mail”, și, cât căsuța e goală,
**drumul direct**, pe care PHP predă mesajul unui program de poștă al mașinii.
Un server pus cu `instaleaza-vps.sh` înainte de 2.7.5 n-a primit niciodată un
asemenea program: instalarea nu punea niciunul, iar pe găzduirea veche îl ținea
gazda. Pe un asemenea server nu pleca niciun e-mail, iar panoul spunea mai
departe că merge. După toate semnele, așa stă și serverul de azi; sigur ți-o
spune pasul, la prima rulare.

**Unde stă în lucrare.** La coadă, după cererea de probă către API și înainte
de „Gata”. Până la el, baza, fișierele și Apache sunt deja la locul lor, iar
pasul nu are nicio ieșire cu eroare: orice piedică e un rând galben, cu ce ai
de făcut, într-un raport care se încheie tot cu `Actualizat.` verde. Rulează și
cu `--doar-fisiere`; cu `--doar-baza` scrie „Sărit (--doar-baza).”.

**Ce pune, dacă serverul n-are niciun program de poștă.** postfix, numai de
trimis, așezat la fel ca la instalare: ascultă numai pe 127.0.0.1, trimite
numai pe IPv4, nu se socotește gazda domeniului tău și trimite în numele lui.
De ce fiecare din cele patru, și aceleași lucruri făcute cu mâna, sunt în
[08-publicare-vps-hostinger.md](08-publicare-vps-hostinger.md), la pasul 6c.
Domeniul nu ți-l cere: îl ia din adresa site-ului scrisă în `api/config.php`,
fără `www.` în față. Instalarea poate ține un minut și se face o singură dată.

**Ce vezi pe o rulare fără piedici**, la actualizarea care îl aduce (în locul
punctelor stă numele serverului care primește poșta domeniului tău):

```
▸ Programul de poștă
  instalez postfix, numai de trimis (poate ține un minut)…
  postfix — pus: ascultă numai pe 127.0.0.1, trimite ca @goldenpastry.ro
  portul 25 spre lume e deschis (am ajuns la …)
  Calea de nădejde rămâne căsuța (SMTP): ce pleacă direct de pe server ajunge des în Spam.
  Proba adevărată: butonul „Trimite e-mail de test” din Setări → Notificări → E-mail.
  Ce n-a plecat:  mailq      De ce:  tail -30 /var/log/mail.log
```

La actualizările de după, primele două rânduri se schimbă în
`serverul are deja un program de poștă (/usr/sbin/sendmail) — nu-l ating` și
`serviciul postfix merge`. Nu e alt program: e chiar postfix-ul pus data
trecută, pe care pasul îl găsește gata și nu-l mai atinge. Tot așa scrie și pe
un server care avea dinainte un program de poștă al lui, oricare ar fi el.

**Rândurile fără galben, pe scurt.** Cele două de mai sus; cele trei de la
capăt, care vin de câte ori pasul ajunge până acolo — nu și când l-ai sărit,
nici când s-a oprit la unul din primele trei rânduri galbene din tabelul de
mai jos, fiindcă n-a putut pune programul; `Sărit (--fara-posta). …`, când ai
cerut tu;
și `portul 25 nu l-am putut încerca (n-am aflat cine primește poșta lui …)`,
când serverul n-a putut afla la cine să bată — atunci despre port nu știi
nimic, nici de bine, nici de rău.

**Rândurile galbene.** Niciunul nu oprește actualizarea și niciunul nu
înseamnă că s-a stricat ceva din ce mergea. Fiecare spune că pe drumul direct
e-mailul nu pleacă ori nu ajunge, și fiecare are același leac de rezervă:
căsuța firmei, completată în Setări → Notificări.

| Rândul galben | Ce înseamnă | Ce faci |
|---|---|---|
| `Nu am putut citi domeniul site-ului, deci NU am pus programul de poștă.` | în `api/config.php` adresa site-ului lipsește ori nu e un nume de domeniu; fără el, programul n-ar avea în numele cui să trimită | completezi căsuța; pe drumul direct nu pleacă nimic. Rândul vine de obicei împreună cu „Proba de viață a fost sărită”, din aceeași pricină |
| `Serverul nu are apt: programul de poștă NU a fost pus.` | mașina nu e un Ubuntu ori un Debian, ori îi lipsește unealta cu care se răspunde dinainte la întrebările instalării | completezi căsuța, ori pui tu un program de poștă, numai de trimis |
| `Programul de poștă NU s-a putut instala (apt a refuzat ori n-are legătură).` | pachetul n-a putut fi adus. Sub el scrie „Restul lucrării e întreg.” și e adevărat: actualizarea a reușit | completezi căsuța. Dacă vrei și drumul direct, vezi „După o instalare picată”, sub tabel |
| `postfix s-a instalat, dar nu l-am putut așeza (postconf a refuzat).` | pachetul e pe mașină, dar cele trei așezări n-au intrat: nu te bizui pe el | completezi căsuța; așezarea o dai cu mâna, cu rândul de sub tabel |
| `Programul de poștă se socotește gazda lui … (mydestination).` | programul crede că domeniul tău e al lui: un mesaj către o adresă a domeniului — și tocmai la una merg anunțurile de comenzi — rămâne pe server în loc să plece la căsuța adevărată | dai comanda scrisă sub rând: `postconf -e 'mydestination = localhost.localdomain, localhost' && systemctl restart postfix`. O lași cum e numai dacă serverul chiar ține căsuțele domeniului, ceea ce pe o instalare făcută după ghid nu se întâmplă |
| `Programul de poștă e instalat, dar oprit: pe calea directă NU pleacă nimic.` | serviciul nu merge | `systemctl enable --now postfix`, iar dacă tot nu pornește, `systemctl status postfix` spune de ce |
| `Portul 25 pare închis de găzduire (n-am ajuns la …).` | singura legătură încercată spre serverul care primește poșta domeniului n-a ieșit în opt secunde. Dacă portul chiar e închis, mesajele rămân în coadă și nu pleacă, **fără nicio eroare în panou** | completezi căsuța: „Leacul nu e pe server”. „Pare” e spus cu rost — e o singură încercare, iar celălalt server putea fi el ocupat; `mailq`, după un e-mail de test, arată dacă mesajul a rămas pe loc |

Așezarea cu mâna, pentru rândul cu `postconf` și pentru cazul de mai jos:

```bash
postconf -e 'inet_interfaces = loopback-only' 'inet_protocols = ipv4' 'mydestination = localhost.localdomain, localhost'
```

```bash
systemctl restart postfix
```

**După o instalare picată.** Scriptul îți scrie cele două rânduri de dat mai
târziu: instalarea, `DEBIAN_FRONTEND=noninteractive apt-get install -y postfix`,
și așezarea cu repornirea, `postconf -e … && systemctl restart postfix`. Dă-le
pe AMÂNDOUĂ: la o rulare de după, pasul găsește programul gata pus și, după
regula lui, **nu-l mai așază** — scrie „nu-l ating” și, cel mult, rândul galben
cu `mydestination`. Apoi rulează actualizarea încă o dată, cu `--doar-fisiere`,
ca să vezi ce spune pasul.

**`--fara-posta`.** Sare pasul cu totul:

```bash
sudo bash /root/actualizeaza-vps.sh --fara-posta
```

Îl alegi dacă nu vrei pe mașină niciun program de poștă. Nu pierzi drumul de
nădejde — cu căsuța completată, e-mailul pleacă prin ea și n-are nevoie de
program —, pierzi numai plasa: cât căsuța e goală, nu pleacă nimic, iar
cartonașul „E-mail” o scrie pe față. Două lucruri de știut. Opțiunea **nu se
ține minte**: o dai la fiecare actualizare, altfel prima rulată fără ea pune
programul. Și nu scoate nimic: pe un server care are deja un program de poștă,
pasul oricum nu face nimic, cu ea ori fără ea.

**Portul 25, și ce se știe despre el.** Un program de poștă trimite spre lume
pe portul 25, iar multe găzduiri îl țin închis. După pagina de ajutor a
găzduirii („Is SMTP port 25 blocked on VPS?”), Hostinger **nu** închide portul
25 pe VPS-urile lui, dar ține e-mailul trimis la cel mult cinci mesaje pe
minut. E ce spune găzduirea, nu ceva măsurat de noi pe serverul tău: de aceea
pasul încearcă el portul, la fiecare rulare, fără să trimită niciun mesaj, și
spune ce a văzut.

**Ce nu făgăduiește drumul direct**, nici cu portul deschis. Un mesaj plecat
de pe server n-are semnătura domeniului (DKIM), iar regula domeniului pentru
expeditori (SPF) e „~all”: e de așteptat să ajungă des în **Spam**. Și „predat”
nu înseamnă „ajuns”: aplicația află numai că programul de poștă a primit
mesajul. Ce a rămas în coadă arată `mailq`; de ce, `tail -30 /var/log/mail.log`
ori, unde fișierul lipsește, `journalctl -u 'postfix*' -n 30 --no-pager` —
pasul îți scrie la capăt care din două e a serverului tău. De aceea drumul de
nădejde rămâne căsuța, iar proba adevărată e butonul „Trimite e-mail de test”:
amândouă sunt la pasul 3 din „Ziua actualizării la 2.7.5”.

**Ce nu s-a încercat.** Pasul a fost probat numai pe un server închipuit — un
dosar de lucru în care instalarea pachetului și repornirea serviciului sunt
jucate, nu făcute. Pe o mașină adevărată, și pe VPS-ul de azi, încă nu rulase
la data scrierii. Dacă la prima rulare scrie altceva decât e aici, crede
ecranul, nu ghidul, și păstrează rândurile.

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
| `--fara-posta` | de la 2.7.5: nu pune programul de poștă (postfix, numai de trimis). E-mailul pleacă atunci numai prin căsuța din Setări → Notificări |

`--doar-baza` și `--doar-fisiere` sunt pentru repornirea unei actualizări care a
picat la mijloc: repari ce era de reparat și continui de unde a rămas. Ceasul
plăților ține de fișiere: se pune și cu `--doar-fisiere`, iar cu `--doar-baza`
nu se atinge. La fel programul de poștă: pasul lui rulează și cu
`--doar-fisiere`, iar cu `--doar-baza` scrie „Sărit (--doar-baza).” și nu
face nimic.

---

## Ce verifici după

În raportul scriptului:

- **rândurile verzi „aplicată acum"** — câte unul pentru fiecare migrare pe
  care serverul n-o avea. Câte sunt nu depinde de pachetul pe care îl urci, ci
  de cât de veche e instalarea: pe una lăsată în urmă cu trei versiuni se aprind
  trei, pe una deja la zi niciuna. A doua oară scriu toate „era deja
  aplicată" — e în regulă, asta înseamnă. Cea mai nouă migrare din pachet e
  `sql/037_posta_smtp.sql`;
- **numărul de tabele**, în capul raportului, la „Baza de date”. Scriptul
  numără tabelele o singură dată, **înaintea** migrărilor, deci la rularea care
  aduce tabele noi capul arată încă numărul de dinaintea lor. La actualizarea
  care aduce 2.7.0 pe o instalare ținută la zi scrie **25 de tabele**, nu 28,
  și nu e semn că a lipsit ceva: cele trei tabele noi le vezi mai jos, la
  „Migrări”, pe rândurile verzi ale lui `033_acces.sql`, `034_costuri.sql` și
  `035_comenzi_sterse.sql`, fiecare cu „aplicată acum” și, în paranteză, cu
  „1 tabel” urmat de numărul coloanelor lui. **28 de tabele** scrie capul de la
  rularea următoare încolo (cele din urmă venite sunt `role_rights`,
  `stock_item_prices` și `orders_deleted`; înaintea lor, `schema_migrations`,
  registrul adus de `025_registru_migrari.sql`, iar înaintea lui,
  `telegram_links` și `lab_production`, aduse de `021_raspundere.sql`).
  Numărul nu se mișcă nici la `016`, nici la `020`: prima aduce o coloană
  (`users.zone`) și o zonă în plus în lista de livrare, a doua numai un index
  (`ix_orders_client_rang` pe `orders`) — niciuna un tabel. `021` îl mișcă cu
  **două**, deși aduce patru lucruri: celelalte două sunt tot o coloană
  (`users.telegram_user_id`) și tot un index (`ix_orders_autor`). `022` nu-l
  mișcă deloc: ea pune patru coloane pe `order_requests`, un index și o cheie
  străină; nici `023`, care pune două coloane și atât (`orders.lab_seq`,
  `users.poate_confirma_livrarea`); nici `024`, cu trei coloane pe `products`;
  nici `026`, cu o coloană și un index, tot pe `order_requests`. Așa că de la
  `021` încoace raportul a arătat **24**, iar `025` l-a dus la **25**, unde a
  stat până la `032` — care pune numai coloane, indexuri și setări. `033`,
  `034` și `035` nasc câte un tabel și îl urcă la **28**, cifră pe care capul
  raportului o scrie abia de la rularea de după cea care le aduce.

  La fel la actualizarea care aduce 2.7.5: pe o instalare ținută la zi capul
  scrie **28 de tabele**, nu 29. Al douăzeci și nouălea, `telegram_oameni`, îl
  vezi la „Migrări”, pe rândul verde al lui `036_telegram_oameni.sql`, cu
  „aplicată acum” și, în paranteză, cu „1 tabel” urmat de numărul coloanelor
  aduse — ale tabelului și cele două puse pe `order_requests`. Rândul lui
  `037_posta_smtp.sql` vine fără paranteză: ea nu aduce nici tabel, nici
  coloană, ci cinci rânduri de setări. **29 de tabele** scrie capul de la
  rularea următoare încolo;
- linia **`api.DOMENIU răspunde (200)`**. Cifra din paranteză e codul cu care a
  răspuns API-ul la proba scriptului, făcută ca un vizitator nelogat: pe o
  instalare sănătoasă e 200, iar orice cod care începe cu 2 ori cu 4 e bun. Ce
  cauți e să nu fie **500**, care înseamnă că o migrare n-a intrat; scriptul îl
  scrie atunci pe roșu, cu ultimele rânduri din jurnalul de erori, pe care le
  vezi și cu `tail -20 /var/log/apache2/gp-api-error.log`;
- de la 2.7.0, la pasul „Ceasul plăților”, rândul cu
  `/etc/cron.d/goldenpastry-plati` — „scris” la actualizarea care îl aduce,
  „neschimbat” la cele de după — și, sub el, „serviciul cron merge”. Orice
  rând galben de aici înseamnă că rapoartele de încasări nu vor pleca; ce spune
  fiecare e la „Ceasul plăților”, mai sus;
- de la 2.7.5, la pasul „Programul de poștă”, ori rândul
  „postfix — pus: ascultă numai pe 127.0.0.1, trimite ca @DOMENIU”, ori
  „serverul are deja un program de poștă (…) — nu-l ating”. Un rând galben de
  aici nu oprește nimic și nu strică actualizarea, dar spune că pe drumul
  direct e-mailul nu pleacă ori nu ajunge; ce înseamnă fiecare e la „Programul
  de poștă”, mai sus;
- căile celor două copii — a bazei și a codului.

Apoi, din browser: intră în panou, deschide **Recomandări** și **Catalog**. Dacă
amândouă se încarcă, actualizarea e întreagă.

La actualizarea care aduce 2.7.0 mai e ceva de făcut în panou, în aceeași zi:
pragul de comenzi neplătite lucrează din primul minut, iar raportul de bani nu
pleacă până nu-i alegi chatul. Pașii, în ordine, sunt la „Ziua actualizării la
2.7.0”, mai jos.

De la runda 2.6.0, tot în **Catalog**: la un produs care are fișă tehnică,
„Vezi fișa” trebuie să deschidă PDF-ul într-o filă nouă, de pe
`goldenpastry.ro`. Dacă în locul lui vine o pagină „nu am găsit”, adresa din
filă îți spune care din două: cu `lab.goldenpastry.ro/fise/` în față, în `lab/`
a rămas `.htaccess`-ul vechi; cu `goldenpastry.ro/fise/`, folderul `fise/` n-a
ajuns în `public_html/`. Antetele
fișei le vezi și de pe server, cu cele două comenzi `curl -sI` de la pasul 8c
din [08-publicare-vps-hostinger.md](08-publicare-vps-hostinger.md).

Pentru `016` mai e o privire de aruncat, fiindcă ea nu se vede în numărul de
tabele: în Setări, pe fila „Liste”, **„Zone de livrare”** trebuie să aibă „În
afara Bucureștiului și Ilfovului" la coada listei și **fără coordonate** —
lipsa punctului nu e o scăpare, e chiar
semnalul după care opririle de acolo ies din ordinea rutei și se arată aparte,
în josul ecranului de rută.

Privirea asta e pentru actualizarea care **aduce** `016`. Dacă după aceea ai
redenumit zona sau ai scos-o din listă, rămâne cum ai lăsat-o: `016` scrie în
listă și pe conturi numai în rularea care adaugă coloana `users.zone`, iar
orice actualizare de după nu le mai atinge. La fel o zonă pe care ai șters-o
de pe un cont din **Conturi**: rămâne ștearsă.

Dacă ai redenumit zona sau ai șters zone de pe conturi **înainte** de
actualizarea asta, uită-te o dată în Setări, la **„Zone de livrare”**, după o
zonă „În afara Bucureștiului și Ilfovului" apărută din nou lângă cea redenumită
de tine, și
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
deschisă dinaintea actualizării) capătă un refuz care le numește pe **toate**, nu doar pe
prima, iar din salvarea aceea nu se scrie nimic: formularul se salvează întreg
sau deloc (`unknown_setting`). Până la `018`, aceeași salvare răspundea „ok" și
nu scria nimic — de-aia adresa firmei a stat ani de zile scrisă în cod, fără ca
cineva s-o poată schimba.

Pentru `017` și `018`, raportul face singur privirea asta, după `Bilanț:`:
citește din bază procentul, adresa și programul și le spune cu valoarea lor
(`actualizeaza-vps.sh:640`, `:722`). Un rând galben care spune că `017` sau
`018` **n-a intrat** e o migrare lipsă — aproape mereu un folder `sql/` urcat
mai vechi decât pachetul; la procent îl dă și un `0` rămas de la casă, pe care
`017` l-ar fi urcat la 5. Un rând galben care începe cu **„N-am putut citi…"**,
cu eroarea lui mysql dedesubt, e altceva: migrările au trecut, a picat numai
întrebarea de după ele, deci raportul nu știe ce e acolo și nu ghicește. Nu
rulezi din nou pentru atât; te uiți tu, în Setări. La fel vorbește și rândul
conturilor făcute pe site pe care nu le-a recunoscut nimeni (`:788`): acolo o
citire picată tăcea înainte, exact ca „niciun cont", iar acum se aude.

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
Ce lipsește dacă lipsesc, pe rând. Fără `telegram_links`, cutia „Contul meu de
Telegram” din Setări → Notificări nu se desenează deloc, deci nimeni nu-și
poate lega contul. Fără `users.telegram_user_id`, serverul nu recunoaște pe
nimeni din grup. De la 2.7.5 asta nu mai înseamnă un nume greșit, ci unul
neverificat: orice apăsare se scrie pe drepturile contului care a legat botul,
dar cu numele de pe Telegram al celui care a apăsat și cu vorba „cont nelegat”
(v. `036`, mai jos). Până la 2.7.5 ieșea pe numele celui care legase botul,
oricine ar fi apăsat, și **fără niciun semn**. Fără
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

`024_doua_cataloage.sql` **nu se vede nici ea în raport**: nici tabel, nici
setare, nici index — trei coloane pe `products`, `in_frozen`, `in_baked` și
`photo_baked`. O ceri singur:
`SHOW COLUMNS FROM products LIKE 'in_frozen'`.
Fără primele două, magazinul se poartă ca înainte de runda asta: arată numai
produsele cu **amândouă** prețurile. Nu e o stricăciune — e purtarea pe care
codul nou o ține dinadins, fiindcă fișierele ajung înaintea importului și nimic
n-are voie să se golească în fereastra dintre ele. Ce nu merge până la import:
cele două bife din panou n-au unde se scrie, iar o poză urcată anume pentru
coapte cade peste cea congelată. Bifele se nasc **aprinse**, nu
stinse: un produs născut de codul vechi între cele două momente trebuie să
apară în amândouă cataloagele, nu în niciunul.

Migrarea scrie o singură dată, la rularea care aduce coloanele, și e zăvorâtă
anume (`@coloane_noi`): a doua actualizare nu pune la loc laturile pe care
le-ai stins din panou. E lecția lui `016`, scrisă acolo pe larg.

`026_cheie_cerere.sql` **nu se vede nici ea în raport**: nici tabel, nici
setare — o coloană pe `order_requests`, `idempotency_key`, și indexul unic de
peste ea, `uq_req_cheie`. O ceri singur:
`SHOW INDEX FROM order_requests WHERE Key_name = 'uq_req_cheie'`.
Întreab-o pe **ea**, nu coloana: o bază care are coloana dar nu indexul
primește chei și nu bagă de seamă niciodată că a primit-o de două ori, adică
exact starea pe care migrarea vine s-o închidă. De-asta și codul întreabă tot
de index (`request_key_supported()`), nu de coloană.
Fără ea, cererea trimisă a doua oară — degetul apăsat încă o dată, legătura
care a căzut tocmai când răspunsul venea încoace — intră a doua oară, iar
laboratorul vede două comenzi acolo unde omul a făcut una. Purtarea e cea de
până acum, nu o stricăciune nouă: pagina știe să se poarte și pe o bază fără
cheie. Ce se schimbă la import e că serverul începe să recunoască perechea și
răspunde cu cererea dintâi, ca și cum ar fi fost acum.

`027_poza_fiecarei_laturi.sql` **nu se vede nici ea în raport**, și tot dintr-o
pricină aparte: e singura migrare care nu atinge schema DELOC — nici tabel, nici
coloană, nici index —, deci nici amprenta nu se mișcă pentru ea. Numai registrul
o vede. O ceri și singur:

```sql
SELECT value FROM settings WHERE setting_key = 'migration_027_poza_pe_latura';
```

Rândul acela e și zăvorul ei: a doua actualizare îl găsește și nu mai copiază
nimic, deci o poză pe care ai schimbat-o între timp la coapte rămâne a
ta. E aceeași lecție ca la `016` și `024`.

Ce face: copiază o dată poza congelată în coloana laturii coapte acolo unde
aceea e goală. Până în 2.4.1 o latură fără poză a ei o arăta pe a celeilalte —
de-aia o poză schimbată la congelate se vedea și la coapte. Împrumutul
s-a tăiat în runda asta, iar migrarea e cea care face ca tăierea să nu golească
nicio vitrină: în ziua urcării, amândouă laturile arată exact ce arătau. De-acum
gol înseamnă gol — un produs căruia nu i-ai urcat poză la coapte apare
acolo cu substituentul „fără poză", nu cu poza congelată.

`028_preturi_pe_trepte.sql` **nu se vede nici ea în raport**: nici tabel, nici
index — zece coloane (șase pe `products`, câte două pe `order_items` și pe
`order_request_items`) și patru setări ale catalogului. O ceri singur, pe
ultima coloană pe care o adaugă:
`SHOW COLUMNS FROM order_request_items LIKE 'prag_treapta'`.
Codul le cere pe **toate zece** deodată (`catalog_trepte_supported()`), deci
în fereastra dintre fișiere și import — ori pe o bază rămasă cu jumătate din
migrare — casa vinde exact ca înainte: un singur preț pe latură, fără scară în
magazin, comenzile tarifate la A. Singurul lucru refuzat până la import e
salvarea unui preț B sau C din fila Produse: serverul spune că lipsește `028`
și nu scrie nimic, iar restul fișei se salvează. Setările intră cu
`INSERT IGNORE`, deci a doua actualizare nu-ți calcă vederile și pragurile
alese în Setări → General, cardul „Catalogul" — aceeași lecție ca la `016`,
`024` și `027`. Pozițiile comenzilor de dinainte rămân cu treapta goală,
citită ca A: o comandă veche corectată după actualizare își păstrează prețul
promis, ca până acum, și coboară la B ori la C numai dacă noua cantitate ajunge
la pragul lor.

`029_numere_in_catalog.sql` **nu se vede nici ea în raport**, ca `027`: nu
atinge schema deloc, deci nici amprenta nu se mișcă. O ceri singur:

```sql
SELECT value FROM settings WHERE setting_key = 'migration_029_numere_catalog';
```

Rândul poartă data la care a rulat și e zăvorul ei: numerotarea se face o
singură dată, la actualizarea care o aduce, iar cele de după găsesc semnul și
nu mai ating nimic — deci numerele pe care le alegi în fila Produse rămân ale
tale. Ce face la import: dă fiecărui produs, și celor scoși din catalog,
numărul 1, 2, 3…, în ordinea în care au fost puse. Dacă n-ai șters niciodată un
produs, numerele ies exact cele de dinainte, iar vitrina nu se mișcă; dacă ai șters
unul, cele de după el urcă cu un loc și închid golul. Coșurile, pozele și
comenzile nu simt nimic: ele țin cheia produsului, nu numărul din colț. În
fereastra dintre fișiere și import magazinul arată numerele de dinainte, iar fișa
produsului refuză schimbarea numărului cu un mesaj care numește `029` — restul
fișei se salvează.

`030_gata_de_livrare.sql` **nu se vede nici ea în raport**, ca `029`: nu
atinge schema deloc, deci nici amprenta nu se mișcă. O ceri singur:

```sql
SELECT value FROM settings WHERE setting_key = 'migration_030_gata_de_livrare';
```

Ce face la import: pune etapa „Gata de livrare" în lista ta de etape, chiar
înaintea celei finale — cea aleasă în Setări → Liste, oricum s-ar chema la
tine. Celelalte etape rămân cum le-ai lăsat, cu numele și cu ordinea lor. O
vezi cu ochii tot acolo, în Setări → Liste. Rândul de mai sus poartă data
rulării și e zăvorul ei: etapa se pune o singură dată, la actualizarea care o
aduce, iar cele de după găsesc semnul și nu mai ating lista — deci dacă
redenumești etapa ori o scoți, rămâne cum ai lăsat-o.

**În ziua actualizării, o privire în laborator.** Migrarea nu mută nicio
comandă. Cele care stăteau la „La ambalare” în clipa importului rămân acolo,
iar de-acum ambalarea nu mai e ultima etapă a laboratorului: pe fiecare din ele
laboratorul mai apasă o dată, până la „Gata de livrare”, și abia atunci se
aprinde butonul curierului. Dacă șoferul spune că la o comandă ambalată înainte
de actualizare butonul de livrare stă stins, asta e pricina, nu o stricăciune.

În fereastra dintre fișiere și import — fișierele noi pe o bază încă fără
`030` — lista de etape e cea veche, dar purtarea e deja cea nouă. Laboratorul
se oprește la etapa dinaintea celei finale, care e atunci ambalarea, și se
oprește acolo pentru toți, administratorii cu tot: etapa finală o pun de-acum
numai „Rută” și „Comenzi”. Pe „Rută”, „Marchează livrat” se aprinde la
comenzile ajunse la ambalare, și numai după ce curierul a bifat fiecare poziție
a comenzii.

**După urcare, ecranele rămase deschise.** Comenzile se împrospătează singure
în „Laborator”, pe „Rută” și în „Comenzi”, dar codul paginii și lista de etape
din Setări se citesc o singură dată, la deschidere. De-aceea, în ziua
actualizării:

- **Tabletele din laborator și fiecare „Rută” deschisă se reîncarcă.** O
  pagină deschisă înainte de urcare e pagina veche: tableta nu cunoaște „Gata
  de livrare”, deci nu poate duce comanda până acolo, iar „Rută” cea veche are
  încă „Tot încărcat” și aprinde „Marchează livrat” pe orice etapă, cu lista
  nebifată. Reîncărcată, fiecare primește regulile noi.
- **Bifele de pe telefonul șoferului se văd nebifate.** Pagina nouă ține bifa
  pe poziția comenzii, nu pe locul din listă, iar bifele vechi nu se potrivesc
  cu nicio poziție. Nu se pierde nimic: șoferul bifează încă o dată comenzile
  zilei.
- **O filă „Setări” rămasă deschisă de dinainte se reîncarcă înainte de orice
  salvare**, pe orice calculator ori telefon. Dacă în ea umbli la etape — muți,
  adaugi ori ștergi una — și salvezi, fila veche trimite lista ei veche, iar
  „Gata de livrare” dispare fără niciun semn. (Numai dacă vreo comandă stă deja
  în ea, serverul refuză salvarea și spune de ce.) Dacă s-a întâmplat, într-o
  filă reîncărcată:
  Setări → „Liste”, la „Etape în laborator” scrii „Gata de livrare” în caseta
  de sub listă și apeși „Adaugă”. Etapa intră la coada listei, așa că o urci cu
  săgeata ↑ („Mai sus”) până stă chiar deasupra etapei cu semnul „livrată”.
  Apoi „Salvează”, în bara de sus. Migrarea n-o mai pune singură: zăvorul ei e
  deja scris.
- **Un cont fără bifa „Poate confirma livrarea” nu mai vede „Marchează
  livrat”** pe „Rută”; sub comandă scrie ce lipsește și unde se pune. Bifa se
  pune din „Conturi”, pe contul șoferului, iar butonul apare singur, fără
  reîncărcare, la următoarea împrospătare a paginii — cam în cincisprezece
  secunde. Administratorul și fondatorul o au din cod; un manager care închide
  din „Comenzi” comenzile cu ridicare are nevoie și el de bifă.

`031_fise_tehnice.sql` **nu se vede nici ea în raport**: nici tabel, nici
index — două coloane pe `products`, `sheet_frozen` și `sheet_baked`, și semnul
ei din `settings`. O ceri singur, pe a doua coloană:
`SHOW COLUMNS FROM products LIKE 'sheet_baked'`.
În coloane stă calea fișei tehnice a fiecărei laturi. La import, migrarea leagă
o dată, după `slug`, fișele venite cu pachetul de latura congelată a
produselor lor; latura coaptă o împrumută pe aceea cât n-are una a ei, iar
împrumutul se face în cod, deci `sheet_baked` rămâne gol. Un produs pus de tine
în Catalog cu altă adresă decât cea din pachet (alt `slug` — de pildă unul
făcut din nou sub alt nume) nu e găsit și rămâne fără fișă: i se încarcă una
din Catalog. Legarea e zăvorâtă cu semnul `migration_031_fise_tehnice` —
aceeași lecție ca la `016`, `024`, `027` și `029`: o fișă pe care ai scos-o
între timp rămâne scoasă la actualizările de după. În fereastra dintre fișiere
și import magazinul nu arată butonul fișei la niciun produs, iar încărcarea
unei fișe e refuzată cu un mesaj care numește `031`. Produsul și pozele lui se
salvează, dar rețeta nu: ea pleacă abia după fișe, iar formularul rămâne
deschis și o spune.

`032_plati_si_prag.sql` **nu se vede nici ea în raport**: niciun tabel — opt
coloane (șapte pe `orders`, de la `plata` la `plata_amintire_la`, și
`prag_neplatite` pe `users`), două indexuri pe `orders` și opt rânduri în
`settings`, între ele semnul ei. O ceri singur, pe semn:

```sql
SELECT value FROM settings WHERE setting_key = 'migration_032_plati_si_prag';
```

Rândul poartă data rulării și e zăvorul celor doi pași ai ei care scriu date:
judecata comenzilor vechi și vederea magazinului pe telefon (despre ea, două
paragrafe mai jos). Ce face la import: dă fiecărei comenzi o stare de plată — neplătită, amânată,
plătită ori plătită prin bancă — și judecă o singură dată comenzile de
dinainte. Cele aflate la etapa finală, cu ziua **trecută**, sunt trecute
plătite, cu totalul lor drept sumă; toate celelalte rămân neplătite, iar cele
anulate nu se numără oricum. Fără judecata asta, fiecare client vechi ar fi
apărut în ziua urcării cu tot istoricul lui „de plată”, iar pragul l-ar fi
oprit de la prima comandă nouă. Clipa plății nu se scrie pentru comenzile
vechi: nu se știe când s-au plătit, iar o dată pusă din burtă le-ar fi băgat pe
toate în „încasat azi”.

**În ziua actualizării, o privire peste comenzile predate.** Migrarea nu știe
care din ele au fost și încasate: le trece pe toate plătite. Pe cele predate și
neîncasate le treci tu înapoi pe „Neplătită”, cu mâna, din „Încasări” — și
rămân așa: actualizările de după găsesc semnul și nu le mai ating, aceeași
lecție ca la `027`, `029` și `031`. O comandă predată chiar în ziua
actualizării rămâne neplătită. Pragul general pornește pe **2**
(`plati_prag_implicit`) și lucrează din primul minut, iar pe conturi
`prag_neplatite` rămâne gol, adică „pragul general”: migrarea nu pune prag pe
niciun cont și nu trimite nimic pe Telegram. Cum se face privirea, pas cu pas,
și ce mai e de hotărât în aceeași zi e la „Ziua actualizării la 2.7.0”, mai
jos.

**Tot atunci, magazinul se deschide altfel pe telefon.** Sub același semn,
`032` trece pe „Patru pe ecran” vederea cu care intră un client de pe telefon
ori tabletă (până la 2.7.0 era „Unul pe rând”). O face o singură dată, și numai
dacă rândul e încă pe „Unul pe rând”; „Șase pe ecran”, dacă l-ai ales tu din
Setări → General → „Catalogul”, rămâne cum e, iar pe calculator nu se schimbă
nimic. Dacă vrei înapoi „Unul pe rând”, îl alegi acolo și salvezi:
actualizările de după nu ți-l mai mută.

În fereastra dintre fișiere și import — fișierele noi pe o bază încă fără
`032` — nimic nu e oprit și nimic nu se numără: nicio comandă nu lovește în
prag, iar ceasul plăților scrie în jurnal „nimic de făcut: baza nu are încă
plățile (migrarea 032)”. Pagina „Încasări”, schimbarea stării unei plăți și
pragul pus pe un cont sunt refuzate cu „Plățile nu sunt încă pornite pe server:
rulează întâi sql/032_plati_si_prag.sql.”

`033_acces.sql` se vede în numărul de tabele: e `role_rights`, al douăzeci și
șaselea. Acolo stau bifele pe care fondatorul le mută în „Acces” — și numai
cele mutate: tabelul se naște gol, iar gol înseamnă că fiecare rol intră exact
unde intra și înainte de actualizare. Importul ei nu mută deci nimic: niciun
rol nu câștigă și nu pierde vreo ușă. Fără el — în fereastra dintre fișiere și
import — rolurile intră tot așa, iar pagina „Acces” se deschide numai de citit,
sub caseta „Bifele nu se pot muta încă.”, care numește `sql/033_acces.sql`.

`034_costuri.sql` se vede și ea în numărul de tabele: e `stock_item_prices`, al
douăzeci și șaptelea — istoricul prețurilor de cumpărare ale articolelor din
depozit. Se naște gol: migrarea nu scrie niciun preț și nu atinge articolele,
rețetele ori mișcările din depozit. Până la primul preț scris, costul unui
produs n-are din ce se socoti. Fără ea, fila „Prețuri” din Depozit se vede, dar
sub „Prețurile nu se pot scrie încă.” stă vorba serverului, „Prețurile nu sunt
încă disponibile: rulează întâi sql/034_costuri.sql.”, și niciun buton de preț.

`035_comenzi_sterse.sql` se vede și ea: e `orders_deleted`, al douăzeci și
optulea. E arhiva comenzilor șterse — poza unei comenzi anulate care a fost
apoi ștearsă, cu cine a șters-o și când. Se naște goală: migrarea nu șterge și
nu mută nicio comandă. Tabelul n-are, dinadins, nicio legătură de cheie străină
cu `orders`: o arhivă legată de comandă ar fi pierit odată cu ea ori ar fi
oprit ștergerea. Fără `035`, anularea unei comenzi merge ca înainte, iar
„Șterge definitiv” e refuzat înainte să se atingă ceva, cu „Ștergerea
comenzilor nu e pornită încă pe server: rulează întâi
sql/035_comenzi_sterse.sql.” După cele trei, baza are **28** de tabele. La
rularea care le aduce, capul raportului scrie încă 25, fiindcă tabelele se
numără înaintea migrărilor; pe cele trei le vezi pe rândurile lor verzi, de la
„Migrări”, iar 28 apare în cap de la rularea următoare.

`036_telegram_oameni.sql` se vede și ea în numărul de tabele: e
`telegram_oameni`, al douăzeci și nouălea. Mai pune două coloane pe
`order_requests`, `handled_tg_id` și `handled_tg_name`. Tabelul ține minte cine
a apăsat un buton sub un anunț din grupul de pe Telegram — numele de acolo și
câte apăsări —, ca fondatorul să-l poată lega de contul lui din cutia „Oameni
din grup” (Setări → Notificări); coloanele țin, pe cererea hotărâtă, numele
celui care a apăsat fără să aibă contul legat. Se naște gol: migrarea nu leagă
și nu dezleagă pe nimeni și nu atinge nicio cerere veche. Cine și-a legat
contul înainte de actualizare, din cutia „Contul meu de Telegram”, rămâne
legat. **Toți ceilalți — și tu, dacă nu ți l-ai legat — apar din prima clipă
cu „cont nelegat”** (vezi „Ziua actualizării la 2.7.5”, mai jos).

În fereastra dintre fișiere și import — fișierele noi pe o bază încă fără
`036` — cine are contul legat apasă mai departe ca până acum. Apăsarea unui om
cu contul nelegat e **refuzată**, fără să se scrie ceva: pe telefon citește
„Contul tău de Telegram nu e legat de niciun cont, iar serverul n-are încă unde
să-ți scrie numele: lipsește migrarea sql/036_telegram_oameni.sql. Nu am scris
nimic.” Cutia „Oameni din grup” arată, în locul listei, „Lista „Oameni din
grup” n-are încă tabelul ei: rulează întâi sql/036_telegram_oameni.sql.” Cum
scriptul pune migrările înaintea fișierelor, fereastra asta o vezi numai după
un `--doar-fisiere`.

`037_posta_smtp.sql` **nu se vede în raport** altfel decât pe rândul ei: nici
tabel, nici coloană, nici index — cinci rânduri în `settings`, cele ale căsuței
de e-mail a firmei: `mail_smtp_host`, `mail_smtp_port` (din pornire `465`),
`mail_smtp_user`, `mail_smtp_password` (ținută criptată) și
`mail_smtp_security` (din pornire `auto`). Le scrie cu `INSERT IGNORE`, deci
actualizările de după nu calcă nimic din ce ai completat — aceeași lecție ca la
`028` și `032`. O ceri singur, fără să scoți la vedere parola căsuței:

```sql
SELECT setting_key FROM settings WHERE setting_key LIKE 'mail_smtp_%';
```

Trebuie să iasă cinci rânduri. Interogarea cere numai numele rândurilor, nu și
valorile: una dintre ele e parola.

Căsuța se naște **goală**: migrarea nu știe nici serverul, nici parola ei. Până
o completezi, e-mailul pleacă pe drumul direct, prin programul de poștă al
serverului — iar dacă serverul n-are unul, nu pleacă deloc (vezi „Programul de
poștă”, mai sus). Fără `037`, casetele căsuței din cartonașul „E-mail” stau
închise, sub rândul „Căsuța nu se poate completa încă: pe server lipsesc
rândurile ei, fiindcă migrarea sql/037_posta_smtp.sql n-a intrat. Rulează
actualizarea (sudo bash actualizeaza-vps.sh), apoi reîncarcă pagina.”, și
rămâne numai drumul direct.

După cele două, baza are **29** de tabele; la rularea care le aduce, capul
raportului scrie încă 28.

---

## Ziua actualizării la 2.7.0

Actualizarea la 2.7.0 e prima care îți lasă de lucru în panou după ce scriptul
a scris `Actualizat.`. Nu fiindcă ar fi rămas ceva neterminat, ci fiindcă aduce
banii în aplicație, iar despre bani hotărăști tu: câte comenzi neplătite rabzi
unui client, unde vrei cifrele și care din comenzile vechi au fost, de fapt,
încasate. Pașii de mai jos se fac cu un cont de **fondator** — tot ce ating e,
din pornire, numai al lui — și sunt puși în ordinea în care merită făcuți.

**Înainte să urci: vezi că ai un fondator.** Pragul de la pasul 2 lucrează
asupra clienților din primul minut, iar din pornire numai fondatorul îl poate
muta, poate însemna plăți și poate trece peste el. Pe o instalare pe care nu e
nimeni fondator, clienții ajunși la prag rămân opriți până trece cererea de
fondator — iar ea are nevoie de un e-mail care chiar pleacă și de un link bun,
din pornire, 60 de minute. Ordinea sigură e deci întâi fondatorul, apoi
actualizarea. Îl recunoști ușor: în „Parola mea”, pe contul unui fondator,
cardul scrie „Ești fondator”, iar în „Conturi” contul lui poartă rolul
„Fondator”. Dacă nu e niciunul, cere dreptul din „Parola mea”, cu „Cere drept
de fondator”, cum scrie la pasul 8d din
[08-publicare-vps-hostinger.md](08-publicare-vps-hostinger.md); se cere la fel
și pe 2.6.0, înainte de actualizare, și tot acolo scrie cum se ia înapoi.

**Ce găsești când deschizi panoul.** Fiecare comandă are de acum o stare de
plată, iar comenzile de dinainte au fost judecate o singură dată, la import:

| Comanda de dinaintea actualizării | Starea primită |
|---|---|
| la etapa finală, cu ziua comenzii **trecută** — mai veche decât ziua actualizării | „Plătită”, cu totalul ei drept sumă încasată |
| la etapa finală, cu ziua actualizării ori una de după | „Neplătită” |
| la orice altă etapă — în lucru, pe drum | „Neplătită” |
| anulată | nu e datorie: nu se numără nici la prag, nici la bani, orice stare ar purta |

Clipa plății nu s-a scris pentru niciuna din cele vechi, fiindcă nu se știe
când s-au plătit: nu apar la „Încasat azi”, iar „Încasări” le socotește la ziua
comenzii. Pe rândul fiecăreia stă „Notă: migrare 032”, ca să le deosebești de
cele însemnate de un om. „Ziua actualizării” e cea de pe ceasul serverului, care
nu e neapărat ceasul României: dacă urci în primele ore de după miezul nopții,
serverul poate fi încă în ziua dinainte, iar atunci comenzile predate în ziua
aceea au rămas „Neplătită”, deși pentru tine sunt de o zi întreagă.

În rest, nimic nu s-a mișcat de la sine. Niciun rol n-a câștigat și n-a pierdut
vreo ușă, niciun preț nu e scris, nicio comandă n-a fost ștearsă, iar pe
Telegram nu pleacă niciun raport cât nu i-ai ales chatul.

**1. Reîncarcă panoul.** O pagină rămasă deschisă dinaintea actualizării e
pagina veche: meniul ei nu știe de „Încasări” și de „Acces”. După reîncărcare,
fondatorul le are pe amândouă în meniu; ceilalți nu le văd, și așa trebuie.
Dacă ai urcat fără să fie cineva fondator, dreptul se cere și acum, din „Parola
mea”, cum scrie la pasul 8d din
[08-publicare-vps-hostinger.md](08-publicare-vps-hostinger.md); până atunci,
pragul de la pasul 2 nu-l poate muta nimeni.

**2. Uită-te la prag.** E singurul lucru din runda asta care lucrează asupra
clienților din primul minut, fără să fi apăsat nimeni nimic. În „Încasări”, la
„Pragul pentru toți clienții”, stă **2**: clientul care are două comenzi
neplătite nu mai poate trimite a treia și citește „Numărul maxim de comenzi a
fost atins. Până plătești soldul comenzilor precedente nu putem primi o comandă
nouă. Contactează-ne pentru mai multe detalii.” Iar „neplătită” e orice
comandă a lui care nu e însemnată plătită — și cea aflată încă în lucru, și
cererea cu produse pe care n-ai acceptat-o încă. Un client care comandă zilnic
ajunge deci la 2 fără să fi întârziat cu nimic. Se numără numai comenzile
legate de un cont de client. Dacă 2 e ce vrei, n-ai nimic de făcut; dacă nu,
scrie cifra ta — **0 înseamnă fără limită** — și apasă „Salvează pragul”. Se
desface scriind cifra dinainte. Unui singur client îi poți da pragul lui, tot
din „Încasări”, de pe cartonașul lui de la „Pe clienți”: apeși butonul care
începe cu „Prag:” — cât clientul n-are pragul lui, pe el scrie
„Prag: general (2)”, cu cifra pragului pentru toți în paranteză —, apoi alegi
„Fără limită” ori scrii un număr de la 1 la 99 și apeși „Pune pragul”. Se
desface din același loc, cu „General (2)”, care îl întoarce la pragul tuturor;
„Renunță” închide căsuța fără să schimbe nimic. La „Pe clienți” apar însă numai
cei care au acum ceva de plătit; pentru oricare altul, pragul lui se pune din
„Conturi”, de pe contul lui.

Cât clientul e oprit, nici cei din casă nu-i pot scrie o comandă. Cine scrie
comenzi e refuzat cu numele lui, câte are, cât datorează și cu „Nu am scris
nimic.”; cine n-are de ce să-i știe socotelile află numai că s-a ajuns la prag.
Din pornire, numai fondatorul poate trece peste prag, cu o confirmare, iar
trecerea se scrie în Jurnal.

**3. Chatul banilor și raportul de probă.** În „Încasări”, la cardul „Raport pe
Telegram”, scrii la „Chat ID” numărul grupului în care vrei cifrele. Trebuie să
fie **alt grup decât cel al comenzilor** — pe același, salvarea e refuzată cu
„Raportul de bani are chatul lui, altul decât cel al comenzilor.” — iar botul,
care e același, trebuie adăugat și în el. Numărul unui grup nu e numele lui:
are numai cifre și, la grupuri, un minus în față, ca în pilda dată de server,
`-1001234567890`. Îl iei din Telegram, cum l-ai luat și pe cel al chatului de
comenzi, și îl scrii cu minus cu tot; orice altceva e refuzat la salvare, cu
„Chatul raportului de bani nu arată a număr de chat Telegram: numai cifre, fără
zerouri în față, cu minus la grupuri (de pildă -1001234567890). Gol = raportul
e oprit.” Apeși „Salvează”, apoi „Trimite acum un raport de probă”. Proba
pleacă numai după salvare, are cifrele adevărate ale clipei și nu ia locul
niciunui raport de la oră. Dacă pagina spune „Raportul de probă a plecat.
Uită-te în grupul de pe Telegram.” și mesajul e acolo, lanțul e întreg; dacă
nu, pricina e scrisă lângă buton. Se desface golind „Chat ID” și salvând: fără
chat, ceasul bate și nu trimite nimic.

Un lucru care poate surprinde: dacă salvezi chatul la mai puțin de două ore
după una din orele raportului, raportul orei aceleia vine la prima bătaie a
ceasului, în cel mult cinci minute. Nu e proba plecată încă o dată, e raportul
adevărat, întârziat — regula celor două ore de la „Ceasul plăților”.

**4. Cele două ore.** „Ora 1” și „Ora 2” vin pe 09:00 și 18:00, ceasul
României. Le muți scriind altă oră și salvând; „Oprește”, de lângă fiecare, o
golește, iar la o oră goală nu pleacă niciun raport. Cele două nu pot fi
aceeași oră. „Amintiri la Amânate” vine aprins: după raport pleacă și un mesaj
cu amânările ajunse la termen, o singură dată pentru fiecare. Dacă oprești
amândouă orele, dar lași „Amintiri la Amânate” aprins, amintirile pleacă
singure, la 09:00; ca să nu mai plece nimic, stinge-le și pe ele. Dacă muți o
oră după ce raportul ei a plecat deja în ziua aceea, nu mai pleacă încă unul
până a doua zi: fiecare din cele două locuri trimite cel mult o dată pe zi.

**5. „Adresa panoului”.** E tot în cardul „Raport pe Telegram” și se salvează
cu același „Salvează”. Pe ea se sprijină butonul de sub raport — „Deschide
Încasări”, iar sub amintiri „Vezi amânatele” —, care deschide pagina gata
filtrată. Migrarea o scrie `https://lab.goldenpastry.ro`: dacă panoul tău e
acolo, n-ai nimic de făcut; dacă stă la altă adresă, schimb-o, altfel butonul
duce la un panou care nu e al tău. Se scrie `https://`, gazda și atât — fără
cale, fără „?” și fără „/” la capăt. O adresă care nu începe cu `https://` ori
nu se deschide de oriunde (`localhost`, o adresă din rețeaua casei, un nume
fără punct) e refuzată la salvare, cu pricina scrisă: Telegram nu primește un
asemenea buton și, odată cu el, respinge **tot mesajul**. De aceea serverul mai
are o plasă: dacă o asemenea adresă ajunge totuși în bază, raportul pleacă fără
buton, nu rămâne netrimis. Câmpul lăsat gol înseamnă același lucru, raport fără
buton, și așa se și desface: îl golești ori scrii adresa dinainte.

**6. Comenzile predate și neîncasate, trecute înapoi.** Migrarea n-a avut de
unde ști care din comenzile predate au fost și plătite, așa că le-a trecut pe
toate „Plătită”. Pe cele pentru care încă aștepți banii le întorci tu: în
„Încasări”, la „Plătite”, scrii la „Caută” numele clientului ori numărul
comenzii, iar pe rândul ei apeși „Neplătită” — sau „Amânată”, dacă v-ați
înțeles pe o zi. Rândul scrie „Salvat: Neplătită.” și rămâne pe loc, ca o
apăsare greșită să se poată îndrepta de acolo. Până faci pasul ăsta, raportul
și cifra de la „De încasat” nu știu de banii aceia.

Dacă n-o găsești, nu înseamnă că lipsește. Cât n-ai ales o perioadă, „Plătite”
arată numai ultimele 30 de zile, iar comenzile trecute de migrare stau acolo la
ziua comenzii lor, nu la ziua actualizării. Pentru una mai veche de o lună pune
la „De la” o zi dinaintea ei: lista merge atunci de acolo până azi, iar căutarea
caută în toată.

Două lucruri de știut înainte să apeși. Fiecare comandă întoarsă pe „Neplătită”
se numără la pragul clientului ei, deci îl poate opri de la comanda următoare —
de aceea pragul e pasul 2, nu ultimul. Iar îndreptarea unei apăsări greșite nu
e chiar o întoarcere: „Plătită” apăsat din nou scrie clipa plății **acum**, așa
că banii aceia intră la „Încasat azi”, nu la ziua comenzii, cum stăteau după
migrare. Nu se strică nimic, dar cifra zilei iese mai mare decât ce a intrat în
casă. Trecerea de pe „Plătită” pe „Bancă” nu mută nimic: e același ban, cu alt
fel.

Invers, comenzile predate chiar în ziua actualizării au rămas „Neplătită”: pe
cele încasate le însemni „Plătită” ori „Bancă” de la „De încasat”. Actualizările
de după nu mai ating nicio stare — semnul migrării e scris —, deci ce ai pus tu
rămâne.

**7. Primele prețuri din Depozit și rețetele.** Costul unui produs se socotește
din rețeta lui și din prețul de cumpărare al fiecărui ingredient, iar prețurile
pornesc toate goale. În Depozit, pe fila „Prețuri”, apeși „Pune preț” pe rândul
articolului, scrii prețul unei unități și ziua de la care e valabil, și
salvezi; un preț greșit se șterge din istoricul articolului, tot de acolo.
Costul apare apoi în Catalog, pe fila „Produse”, în formularul produsului, sub
„Rețetă — pentru o bucată”. Un produs fără rețetă n-are cost; unul căruia îi
lipsește prețul unui ingredient scrie „Costul nu se poate socoti.” și numele
articolului, fără să arate suma celorlalte — un cost pe jumătate ar arăta un
câștig mincinos. Nu e nimic de terminat într-o zi: fiecare preț scris aprinde
costul produselor care îl folosesc.

**8. „Acces”, dacă vrei să împarți.** Din pornire, tot ce au adus pașii de mai
sus e numai al fondatorului. Dacă vrei ca administratorul ori managerul să
țină încasările, pagina „Acces” are câte un rând pentru fiecare: „Încasări”,
„Schimbă starea plății”, „Raportul de bani pe Telegram”, „Pragul de neplătite
al unui client”, „Trece peste prag”, „Prețurile ingredientelor”, „Costul
produselor și profitul”. Bifezi la rolul ales și apeși „Salvează”. O faptă
lucrează numai dacă rolul are și panoul de care ține: „Schimbă starea plății”
fără „Încasări” nu dă nimic. Pagina scrie panoul sub numele fiecărei fapte, cu
„Ține de …”, iar perechile nu sunt toate cele la care te-ai aștepta: „Schimbă
starea plății” și „Raportul de bani pe Telegram” țin de „Încasări”, dar
„Pragul de neplătite al unui client” ține de „Conturi”, „Trece peste prag” de
„Comenzi”, iar „Prețurile ingredientelor” de „Depozit”; „Costul produselor și
profitul” nu ține de niciun panou. Un manager căruia îi bifezi „Încasări” și
pragul tot nu poate muta niciun prag, fiindcă din pornire managerul n-are
„Conturi”; cât nu are și panoul acela, bifa pragului rămâne fără urmare. Iar
„Pragul de neplătite al unui client” e, în ciuda numelui, și bifa care lasă
rolul să mute pragul pentru toți clienții. Se desface mutând bifa înapoi și
salvând; „Înapoi la început” le întoarce pe toate la împărțirea din prima zi,
iar în Jurnal rămâne scris la ce s-a renunțat. Pagina „Acces” însăși nu se
poate da nimănui: e a fondatorului și atât.

Pe server nu mai e nimic de pus pentru toate astea. Ceasul l-a pus scriptul, iar
în `api/config.php` nu se adaugă nicio cheie: exemplul rundei are una nouă,
`telegram_api_base`, dar ea e numai pentru probele de pe calculatorul de lucru
și **pe server nu se pune**. Stă în fișier, nu în Setări, fiindcă mută toate
mesajele de Telegram — comenzi, cereri, raportul de bani — spre altă adresă, cu
tokenul botului cu tot; o asemenea mutare n-are voie să se poată face dintr-un
panou. Dacă a ajuns totuși în fișierul de pe server, o vezi în jurnalul
ceasului, dar numai la coada rândurilor la care a trimis ceva — un raport ori o
amintire —, nu și pe cele cu „nimic de trimis”, adică pe mai toate rândurile
zilei. Pe loc o afli cu rularea de probă de la „Ceasul plăților”: când are ce
tipări, rândul ei se încheie cu „spre” și cu adresa la care ar pleca mesajul,
iar cea bună e `api.telegram.org`. Dacă e alta, golești cheia din fișier.

---

## Ziua actualizării la 2.7.5

Actualizarea la 2.7.5 nu-ți cere nimic **înainte** să urci și nu mută nimic de
la sine: nicio comandă, niciun preț, niciun drept, nicio parolă. Îți lasă însă
patru lucruri de făcut **după**, în panou, cu contul de **fondator**. Sunt puse
în ordinea în care merită făcute, iar primul e contul **tău** de Telegram:
lipsa lui se vede în grup de la prima cerere pe care o accepți de pe telefon.

**Ce găsești când deschizi panoul.** Întâi îl reîncarci: o pagină rămasă
deschisă dinaintea actualizării e pagina veche. Apoi:

- **În grupul de pe Telegram**, cine apasă „Acceptă” ori „Respinge” fără să
  aibă contul legat apare cu numele lui de pe Telegram și cu vorba „cont
  nelegat” — de pildă „✅ Acceptată de Ion Pop, de pe Telegram (cont nelegat)”.
  Nu e o eroare, iar hotărârea se scrie ca înainte. Până la 2.7.5 toate
  apăsările acelea ieșeau, fără niciun semn, pe numele celui care legase
  botul; acum poartă numele celui care a apăsat și niciodată al altcuiva. Cine
  și-a legat contul înainte de actualizare rămâne legat.
- **În Setări → Notificări, cartonașul „E-mail”** scrie, chiar sub titlu, pe
  ce drum pleacă e-mailul. După o actualizare fără rânduri galbene la
  „Programul de poștă” e de așteptat rândul de pe fond galben, „Trimite direct
  de pe server. Fără o căsuță în spate, mesajele pot ajunge în Spam ori deloc.
  Completează căsuța de mai jos ca să fie sigur.” Dacă pasul n-a putut pune
  programul, e de așteptat rândul roșu: „Serverul nu are niciun program de
  poștă: acum NU pleacă niciun e-mail. Completează căsuța de mai jos.”
- **Raportul de bani** are acum setările și în Setări → Notificări, în cutia
  „Raport de bani pe Telegram”. Sunt cele din „Încasări”, nu altele: n-ai
  nimic de ales din nou.
- **Parolele propuse** de „Generează” și de „Propune o parolă” sunt de felul
  „caramel4821”. Cele date până acum rămân bune.

**1. Contul tău de Telegram.** În Setări → Notificări, în cartonașul
„Telegram”, la cutia „Contul meu de Telegram”, uită-te la insigna de lângă
titlu. Dacă scrie „Legat”, ți l-ai legat înainte și n-ai nimic de făcut. Dacă
scrie „Nelegat”:

1. apeși „Leagă-mi contul”. Pagina răspunde „Butonul a plecat în grupul de pe
   Telegram. Apasă-l de pe telefonul tău, apoi întoarce-te aici și
   încuviințează.”;
2. pe telefon, în grup, apeși butonul trimis de bot pentru tine;
3. te întorci în Setări și **reîncarci pagina** — cutia își citește starea
   numai la deschidere. Acum scrie „A apăsat: …”, cu numele de pe Telegram al
   celui care a apăsat;
4. dacă numele e al tău, apeși „Da, eu am apăsat”. Pagina răspunde „Gata. De
   acum, ce apeși de pe telefon se scrie în Jurnal pe numele tău.”, iar
   insigna trece pe „Legat”.

Dacă numele **nu** e al tău — un coleg a apăsat butonul trimis pentru tine —,
nu încuviința: de-atunci încolo apăsările lui ar purta numele tău. Apasă
„Trimite alt buton” și ia-o de la pasul 2. Butonul ține o jumătate de oră. Se
desface cu „Dezleagă-mi contul”. Dacă „Leagă-mi contul” stă stins, botul nu e
legat: apasă întâi „Leagă botul”, în cutia de deasupra.

**2. Colegii, din „Oameni din grup”.** E cutia de dedesubt, iar lista ei o are
numai fondatorul. Lista se umple din apăsări, nu din membrii grupului, și se
naște goală: până apasă cineva un buton după actualizare scrie „Încă n-a apăsat
nimeni un buton în grup.” Nu le cere colegilor să hotărască ceva numai de
probă — apăsarea unui om nelegat lucrează de-adevăratelea. Lasă să vină de la
sine: fiecare intră în listă la prima cerere pe care o hotărăște de pe
telefon. Dacă sub anunțul unei cereri mai vechi, deja hotărâte, au rămas
butoanele, merge și o apăsare acolo: telefonul îi răspunde că cererea a fost
deja acceptată ori respinsă, nu se scrie nimic, iar omul intră în listă.

Apoi apeși „Reîncarcă lista”, iar pe rândul omului alegi, din „Alege contul…”,
contul lui din aplicație și apeși „Leagă”. Pagina răspunde „Legat. De-acum, ce
apasă de pe telefon se scrie pe numele contului.” Trei lucruri de știut:

- **„Leagă numai un om pe care îl recunoști.”** Numele din listă și-l pune
  fiecare singur, pe Telegram. Dacă nu ești sigur cine e, roagă-l să mai apese
  o dată cât ești lângă el și apasă „Reîncarcă lista”: rândul lui e cel la
  care se mișcă „ultima apăsare”.
- **Un cont cu coada „— nu poate hotărî cereri”** se poate lega, dar omul
  legat de el apasă și e refuzat. Nelegat, lucra pe drepturile contului care a
  legat botul; legat, lucrează pe ale lui. Dă-i întâi dreptul de a scrie
  comenzi, din pagina „Acces”, ori lasă-l nelegat.
- **Ce a hotărât cineva înainte de legare rămâne scris cu „cont nelegat”.**
  Numai apăsările de după poartă numele contului.

Se desface cu „Dezleagă”, pe rândul omului. Un cont legat înainte de 2.7.5
al cărui om n-a mai apăsat nimic în grup n-are rând în listă: stă dedesubt,
sub „Conturi legate care n-au mai apăsat nimic în grup”, tot cu „Dezleagă”.
Dacă nu știi al cui e telefonul legat acolo, dezleagă-l.

**3. Căsuța firmei și e-mailul de test.** E pasul care face din e-mail un
lucru pe care te poți bizui. La furnizorul domeniului tău alegi ori faci o
căsuță de e-mail a firmei. Apoi, în Setări → Notificări, în cartonașul
„E-mail”, la „Căsuța prin care se trimite (SMTP)”:

1. completezi „Server SMTP”, „Port” și „Utilizator”. Numele serverului și
   portul le citești din contul tău de la furnizor, de la setările căsuței:
   ghidul nu le scrie, fiindcă le hotărăște furnizorul. „Criptare” rămâne pe
   „Automat (după port)” dacă furnizorul nu spune altceva;
2. la „Parola căsuței” tastezi tu parola. **N-o spui nimănui** — nici celui
   care îți îngrijește aplicația — și n-o scrii în niciun fișier și în niciun
   mesaj. Cât o scrii se vede pe ecran, deci scrie-o când nu-ți stă nimeni
   peste umăr;
3. la „Adresa expeditor”, mai sus în cartonaș, scrii chiar adresa căsuței;
4. apeși „Salvează”, în bara de sus. Rândul de sub titlul „E-mail” trece pe
   verde: „Trimite prin căsuța …”, cu utilizatorul, serverul și portul ei;
5. apeși „Trimite e-mail de test”. Poate ține până la o jumătate de minut.
   Răspunsul bun e „Căsuța … a luat în primire mesajul de test pentru … De aici
   îl duce furnizorul căsuței: caută-l în Inbox, iar dacă nu e acolo, în Spam.”;
6. cauți mesajul la „Adresa care primește anunțul”, **în Inbox și în Spam**.
   Numai mesajul găsit acolo e dovada; răspunsul paginii spune doar că a
   plecat.

Un test picat își scrie pricina lângă buton, cu ce ai de făcut. Se desface
golind „Server SMTP” și salvând: e-mailul se întoarce pe drumul direct. Dacă
sari pasul ăsta, e-mailul pleacă mai departe direct de pe server, fără nicio
făgăduială că ajunge. Fiecare casetă, pe larg, e în
[02-panoul-de-setari.md](02-panoul-de-setari.md), la „E-mailul: căsuța firmei
și drumul direct”.

**4. „Ți-ai uitat parola?”, cu contul tău.** E drumul întreg, cel de la care a
pornit runda. Vezi întâi, în „Conturi”, pe fișa contului tău, că la „E-mail de
recuperare” e trecută o adresă pe care o citești: fără ea nu pleacă nimic.
Apoi, într-o fereastră privată a browserului, ca să nu ieși din cont, deschizi
panoul — nelogat, îți arată pagina de intrare —, apeși „Ți-ai uitat parola?”,
scrii utilizatorul tău și apeși „Trimite-mi linkul”. Pagina răspunde mereu la
fel, oricui: „Dacă există un cont cu datele astea, am trimis un e-mail cu
instrucțiuni. Dacă nu primești nimic în câteva minute, sună-ne: se poate ca pe
cont să nu fie trecută o adresă.”

Cauți e-mailul, în Inbox și în Spam. Apoi deschizi Jurnalul: la contul tău
trebuie să stea rândul „Cerere de parolă nouă — e-mailul a fost luat în
primire de căsuța de e-mail”. Dacă ai sărit pasul 3, rândul e altul: „… a fost
predat programului de poștă al serverului; nu se știe dacă a ajuns” — și chiar
așa e, nu se știe. Dacă scrie „… e-mailul NU a plecat: …”, pricina e după cele
două puncte, iar în Setări, în caseta roșie de deasupra filelor, apare și
„Parolă uitată — linkul n-a plecat (vezi Jurnalul)”.

Linkul din e-mail e adevărat. Dacă îl deschizi și alegi o parolă, parola ta se
schimbă și se închid toate sesiunile contului, pe toate calculatoarele și
telefoanele. Dacă ai vrut numai să vezi că ajunge, nu-l deschide: se stinge
singur într-o oră. Toate rândurile din Jurnal, cu ce înseamnă fiecare, sunt în
[05-conturile-de-client.md](05-conturile-de-client.md), la „Dacă și-a uitat
parola”.

Pe server nu mai e nimic de pus pentru toate astea. Programul de poștă l-a pus
scriptul, iar în `api/config.php` nu se adaugă nicio cheie: exemplul rundei are
una nouă, `smtp_ca_file`, dar ea e numai pentru probele de pe calculatorul de
lucru și **pe server nu se pune**. Datele căsuței nu stau în fișier, ci în
Setări.

---

## Varianta cu mâna

Dacă vrei să faci pașii unul câte unul, sunt exact aceștia:

```bash
mkdir -p /var/backups/golden-pastry
```

```bash
sudo mysqldump --single-transaction --no-tablespaces --default-character-set=utf8mb4 "$(sudo php -r '$c = require "/var/www/gp/api/config.php"; echo $c["db"]["name"];')" | gzip > /var/backups/golden-pastry/gp-$(date +%F-%H%M%S).sql.gz
```

```bash
for f in /root/sql/[0-9][0-9][0-9]_*.sql; do case "$(basename "$f")" in 001_*|002_*) continue;; esac; sudo mysql --default-character-set=utf8mb4 "$(sudo php -r '$c = require "/var/www/gp/api/config.php"; echo $c["db"]["name"];')" < "$f"; done
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

Cinci lucruri de știut dacă le tastezi singur:

- **Niciuna nu cere parolă și n-ai nimic de completat în ele.** `sudo` intră în
  MySQL ca `root` al mașinii, iar numele bazei îl scoate PHP din
  `api/config.php`, de fiecare dată. Până la 2.7.5 cele două comenzi de bază
  erau scrise cu `-u gp -p gp`, adică cereau parola bazei — pe care o
  generează instalarea și n-ai de unde s-o știi pe de rost.
- **Programul de poștă nu se pune singur.** Comenzile de mai sus nu instalează
  nimic. Pe un server fără program de poștă, e-mailul pleacă atunci numai prin
  căsuța din Setări → Notificări. Fie rulezi la urmă scriptul cu
  `--doar-fisiere`, care face și pasul „Programul de poștă”, fie dai cu mâna
  rândurile de la pasul 6c din
  [08-publicare-vps-hostinger.md](08-publicare-vps-hostinger.md).
- **Ceasul plăților nu se pune singur.** Comenzile de mai sus aduc codul lui,
  dar nu scriu nimic în `/etc/cron.d`, iar fără rândul de acolo raportul de
  bani și amintirile de la 2.7.0 nu pleacă niciodată — fără nicio eroare, pur
  și simplu nu-l pornește nimeni. Fie rulezi la urmă scriptul cu
  `--doar-fisiere`, care îl pune și spune ce a făcut, fie scrii fișierul cu
  mâna, cu cele trei rânduri de la „Ceasul plăților”.
- **`--no-tablespaces` nu e de decor.** Pe MySQL 8, `mysqldump` cere dreptul
  global `PROCESS` ca să citească tablespace-urile, iar contul aplicației are
  drepturi doar pe baza ei. Fără opțiune, o copie făcută cu contul aplicației
  pică exact pe serverul unde totul e în regulă. Cu `sudo`, ca mai sus, dreptul
  există, dar opțiunea rămâne: nu strică, și ține comanda bună și pentru cine o
  dă cu contul aplicației.
- **`--default-character-set=utf8mb4` la fiecare comandă.** Fără el, diacriticele
  din migrări ajung stricate în bază, iar „Predată” devine un text pe care
  aplicația nu-l mai recunoaște ca etapă.

---

## De ce nu e de ajuns să rulezi din nou instalarea

`instaleaza-vps.sh` se poate rula de câte ori vrei și a doua oară se poartă ca o
actualizare — dar una parțială. Nu aplică migrările, dinadins: pe o bază care are
deja tabele nu importă nimic (`instaleaza-vps.sh:235`). Și nu face copie de
siguranță înainte, fiindcă la instalare n-are ce copia.

Pe lângă asta, trece de fiecare dată prin `apt`, prin gazdele virtuale și prin
certbot — minute în plus și trei ocazii de stricat ceva ce mergea. Scriptul de
actualizare le sare pe toate.

Prima oară `instaleaza-vps.sh`. De atunci încolo, `actualizeaza-vps.sh`.
