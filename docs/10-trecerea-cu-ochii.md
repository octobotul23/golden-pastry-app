# Trecerea cu ochii, înainte de publicare

O listă scurtă: **ce apeși** și **ce trebuie să vezi**. Nu e un ghid de folosire
— e proba de dinaintea publicării, pe pachetul construit, cel care pleacă pe
server. Dacă toate rândurile de mai jos ies cum scrie aici, plasturele poate
urca.

Durează în jur de douăzeci și cinci de minute. Nu trebuie făcută dintr-o bucată:
fiecare din cele șapte plimbări stă singură.

---

## Înainte — două servere, și de ce contează care

În două ferestre de terminal, din folderul proiectului:

```bash
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe -S 127.0.0.1:8100 -t . .claude/dev-server.php
```

```bash
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe -S 127.0.0.1:8101 -t deploy .claude/deploy-server.php
```

**Plimbarea se face pe 8101.** Acolo se servește folderul `deploy/`, adică exact
ce se copiază pe VPS: numele rescrise (`laborator.html`, nu `Panou
Laborator.dc.html`), amprentele de conținut lipite pe fiecare `.js` și `.css`,
cele patru `.htaccess`. Un fișier uitat din pachet sau un link rescris strâmb se
vede numai aici. Pe 8100 stă codul-sursă, bun pentru reparat, nu pentru probă.

Dacă `deploy/` lipsește ori e vechi, se face din nou cu:

```bash
node .claude/build-deploy.mjs
```

### Ce se apasă și ce se tastează

**În panou, sertarul merge.** La construire, fiecare subdomeniu capătă copia lui
de `gp-api.js`, iar legăturile din interiorul aceleiași gazde rămân scurte:
`comenzi`, `laborator`, `pontaj`. Deci umbli prin panou apăsând, ca pe server.

**Săritura între subdomenii, nu.** „Comanda mea" din panou, „Intră în cont" din
magazin, întoarcerea clientului în magazin — toate sunt adrese de producție
întregi (`https://clienti.domeniu/client`). Apăsate pe calculatorul tău, te duc
pe site-ul adevărat. **Nu e un defect** — pe server sunt exact ce trebuie. Local,
te întorci tastând adresa:

| Ce e | Adresa de tastat |
|---|---|
| Magazinul (site-ul public) | `http://127.0.0.1:8101/` |
| Intrarea în panou | `http://127.0.0.1:8101/lab/index.html` |
| Intrarea clienților | `http://127.0.0.1:8101/clienti/index.html` |
| Comenzi | `http://127.0.0.1:8101/lab/comenzi.html` |
| Laborator | `http://127.0.0.1:8101/lab/laborator.html` |
| Rută | `http://127.0.0.1:8101/lab/ruta.html` |
| Depozit | `http://127.0.0.1:8101/lab/depozit.html` |
| **Pontaj** | `http://127.0.0.1:8101/lab/pontaj.html` |
| Catalog | `http://127.0.0.1:8101/lab/produse.html` |
| Conturi | `http://127.0.0.1:8101/lab/conturi.html` |
| Recomandări | `http://127.0.0.1:8101/lab/recomandari.html` |
| Jurnal | `http://127.0.0.1:8101/lab/jurnal.html` |
| **Statistici** | `http://127.0.0.1:8101/lab/statistici.html` |
| Setări | `http://127.0.0.1:8101/lab/setari.html` |
| Comanda mea (client) | `http://127.0.0.1:8101/clienti/client.html` |

### Conturile cu care intri

| Cont | Parolă | Rol |
|---|---|---|
| `demo-fondator` | `Parola-Test-1234` | fondator |
| `testadmin` | `Parola-Test-1234` | administrator |
| `demo-manager` | `Parola-Test-2468` | manager |
| `testangajat` | `Parola-Test-5678` | angajat |
| `mihai` | `Parola-Test-1357` | agent de vânzări |
| `demo-cumparator` | `Parola-Test-3690` | client, cu cod de recomandare |
| `testclient` | `Parola-Test-9012` | client, cu adresă pe cont |

Ieșirea din cont e butonul **„Ieși"** din bara de sus (pe telefon, **„Ieși din
cont"**, în sertar). Între două conturi, ieși întâi — altfel al doilea intră
peste sesiunea primului. Dacă ai deja o sesiune deschisă, pagina de intrare nu-ți
mai cere parola: scrie **„Ai deja o sesiune deschisă pe acest dispozitiv"** și
îți dă butonul **„Mergi la panoul tău"**.

**Titlurile mici se desenează cu MAJUSCULE.** Mai jos sunt scrise cum stau în
cod — „Ceasul de tură", „Raportul de pontaj", „De la" — dar pe ecran le vezi
„CEASUL DE TURĂ", „RAPORTUL DE PONTAJ", „DE LA". Sunt aceleași cuvinte.

---

## 1 · Meniul, după rol

*Șapte minute. Șapte conturi, șapte ieșiri.*

Asta e proba care prinde cel mai mult: sertarul e oglinda porților de pe server,
iar un buton în plus e un buton care duce la un refuz.

**Apeși:** intri pe `/lab/index.html` cu **primele cinci** conturi din tabel, pe
rând, și te uiți la două lucruri — **unde te aruncă** după parolă și **ce scrie
în meniu**. Pe monitor lat, meniul e bara întunecată din stânga, mereu la vedere.
Pe telefon, e butonul **☰** din dreapta barei de sus.

**Trebuie să vezi:**

| Cont | Cade pe | În sertar, exact astea |
|---|---|---|
| `demo-fondator` | Comenzi | Comenzi · Laborator · Rută · Depozit · **Pontaj** · Catalog · Conturi · Recomandări · Jurnal · **Statistici** · Setări · Parola mea |
| `testadmin` | Comenzi | aceleași, **fără Statistici** — unsprezece |
| `demo-manager` | Comenzi | Comenzi · Laborator · Rută · Depozit · **Pontaj** · Parola mea |
| `testangajat` | Laborator | Laborator · Rută · Depozit · **Pontaj** · Parola mea |
| `mihai` | **Recomandări** | Comenzi · Recomandări · Parola mea |

**Ce caut aici, mai ales:**

- **Managerul și angajatul au acum „Pontaj" în sertar.** E intrarea nouă. Dacă
  lipsește, pachetul nu duce `shared/gp-api.js` proaspăt.
- **„Statistici" e singurul buton pe care fondatorul îl are și administratorul
  nu.** Până acum, cele două sertare erau la fel, iar deosebirea dintre roluri se
  vedea numai în Conturi. Pune-le una lângă alta: intri cu `demo-fondator`,
  numeri **douăsprezece** intrări, ieși, intri cu `testadmin` și numeri
  **unsprezece**. Dacă administratorul vede „Statistici", poarta de pe server
  (`GP_STATS_ROLES`) și sertarul s-au despărțit — e defect, și e cel mai supărător
  fel de defect, fiindcă butonul duce la un refuz.
- **Agentul `mihai` NU trebuie să cadă pe Comenzi.** Ziua lui începe la
  Recomandări, deși are și Comenzi în sertar.
- **Nimeni nu vede o intrare pe care apăsând-o capătă „nu ai voie".** Dacă se
  întâmplă, ăla e un defect și merită scris pe hârtie: ce cont, ce buton.
- **Apasă-le pe toate din sertar**, nu doar citește-le. În panou, legăturile
  sunt scurte și trebuie să meargă. (Singura care nu merge local e „Comanda
  mea", pe care oricum n-o are niciunul din cele cinci.)

### Clientul, aparte

**Apeși:** `demo-cumparator` pe `/clienti/index.html`.

**Trebuie să vezi:** după parolă, browserul pleacă spre **magazinul de
producție** (`goldenpastry.ro`). Aia e adresa bună — clientul cade în magazin,
nu într-un panou — dar e pe a treia gazdă, deci local n-ai unde ateriza. Ești
totuși conectat: **tastează** `http://127.0.0.1:8101/clienti/client.html` și
continuă de acolo.

**Trebuie să vezi**, în sertarul lui: **Comanda mea · Recomandări · Parola mea**
— „Recomandări" fiindcă are cod (`cumparator`). Intră apoi cu **`testclient`**,
care **n-are** cod: în sertarul lui rămân doar **Comanda mea · Parola mea**.

---

## 2 · Pontajul din laborator

*Șapte minute. Lucrarea cea mai nouă, și cea cu cele mai multe piese.*

Astăzi **nimeni n-are cod de pontaj** — așa se livrează. Deci prima apăsare e să
pui unul.

### 2.1 Pui un cod

**Apeși:** `testadmin` → `/lab/conturi.html` → cauți cardul lui **Angajat Test**
→ pe rândul **„Cod de pontaj"** apeși **„Pune un cod"** → în caseta **„Codul
nou"** scrii patru cifre → **„Salvează codul"**.

**Trebuie să vezi:**
- lângă „Cod de pontaj", eticheta se schimbă din **„nepus"** (galben) în
  **„pus"** (verde);
- butonul devine **„Schimbă codul"**, și apare al doilea, **„Ia-i codul"**.

**Probează și refuzul:** încearcă întâi `1234`, apoi `0000`, apoi `4321`.

**Trebuie să vezi**, de fiecare dată, rândul roșu: **„Codul ăsta se ghicește din
prima (1234, 0000 și frații lor). Alege altul."** Sunt optsprezece coduri
refuzate — cele cu patru cifre la fel și cele două șiruri drepte. Nu e o regulă
de tărie (patru cifre n-au cum fi tari), ci una de bun-simț: dacă oricine din
laborator ghicește din prima codul colegului, pontajul nu mai spune cine a venit.

> Pune un cod și lui **Manager Demo** — ca la 2.2 să ai doi oameni pe ceas și să
> se vadă că numărătoarea de sus îi deosebește: *câți sunt în tură* și *câți au
> cod*.

### 2.2 Pontezi venirea și plecarea

**Apeși:** `/lab/pontaj.html` (poți rămâne pe `testadmin`) → sub titlul **„Ceasul
de tură"** apeși pe **numele** lui Angajat Test → tastezi cele patru cifre pe
tastatura de pe ecran.

**Trebuie să vezi:**
- deasupra tastaturii scrie **„Pontează venirea"** și numele omului;
- se trimite singur la a patra cifră — nu există buton „ok";
- ecranul de confirmare: **☀️**, **„Bun venit, Angajat Test!"** și ora
  („Venirea s-a pontat la 07:12.");
- butonul **„Gata"**; dacă nu apeși nimic, se întoarce singur la listă în șase
  secunde;
- înapoi pe listă, sub numele lui scrie acum că e **în tură**, iar rândul de sus
  numără: *„1 om în tură acum · 2 cu cod de pontaj"*.

**Apoi apeși pe el din nou**, cu același cod.

**Trebuie să vezi:** **🌙**, **„Spor la odihnă, Angajat Test!"**, și intervalul
cu totalul: *„Ai fost în tură de la 07:12 la 07:13 — 0 h 1 min."*

**Probează și codul greșit:** apasă pe nume și tastează patru cifre aiurea.
Trebuie să capeți un rând roșu în casetă, și **atât** — pagina NU trebuie să te
dea afară. (Pe o tabletă comună, o cifră tastată strâmb care ar închide sesiunea
ar opri toată tura.)

### 2.3 Citești raportul

**Trebuie să vezi**, sub ceas, cartonașul **„Raportul de pontaj"**, cu trei
casete: **„De la"**, **„Până la"**, **„Cine"**. Lărgește intervalul cât să
prindă ziua de azi.

- turele apar, cu ora de venire, ora de plecare și totalul;
- jos, totalurile pe om.

**Acum proba care contează:** ieși și intră cu **`demo-manager`** → `/lab/pontaj.html`.

**Trebuie să vezi:** managerul **vede raportul** — dar pe un rând de tură
nepontată la plecare **nu are** butonul „Scrie plecarea". Corectura e a
administratorului. În josul barei din stânga îi scrie de ce: *„Ceasul e deschis
oricui lucrează în laborator; raportul de dedesubt îl vezi tu, ca administrator
sau manager."*

Cu **`testangajat`**, aceeași pagină: **ceasul da, raportul nu**. Jos, în bara
din stânga: *„Apeși pe numele tău, tastezi codul, și atât."*

> Rândul acela se vede **numai pe monitor**. Sub 760 de pixeli bara laterală se
> strânge într-o bandă de sus, iar josul ei — numele tău, rolul și explicația —
> se ascunde. Nu e un defect: pe o tabletă de laborator, ecranul e al ceasului.

### 2.4 Scrii de mână o plecare uitată

Dacă ai o tură rămasă deschisă de ieri (sau lași una deschisă acum și te întorci
mâine), pe rândul ei apare **„Scrie plecarea"**, numai pentru administrator și
fondator.

**Apeși** pe el → completezi ziua și ora → în caseta **„De ce o scrii de mână"**
scrii motivul → **„Închide tura"**.

**Trebuie să vezi:** tura se închide, cu total, iar motivul rămâne pe ea.
Motivul e obligatoriu — fără el, butonul refuză.

### 2.5 Iei codul înapoi

**Apeși:** `/lab/conturi.html` → pe cardul lui Angajat Test → **„Ia-i codul"**.

**Trebuie să vezi:** butonul se face roșu și scrie **„Apasă din nou: îi iau
codul"**. Abia a doua apăsare ia codul. Eticheta se face la loc **„nepus"** —
iar pe `/lab/pontaj.html` omul dispare din lista ceasului.

> **Codul nu se vede niciodată nicăieri.** Nici pe card, nici în Jurnal, nici
> într-un răspuns al serverului. Pagina știe doar dacă un om ARE sau N-ARE cod.
> Dacă vezi vreodată patru cifre scrise undeva, ăla e un defect grav — scrie-mi
> unde.

---

## 3 · Tabla de anunțuri din laborator

*Patru minute.*

### 3.1 Pui un anunț

**Apeși:** `testadmin` (sau `demo-manager`) → `/lab/laborator.html` → deasupra
comenzilor zilei, butonul **„Pune un anunț pe ecran"** → scrii textul → **„Pune
pe ecran"**.

**Trebuie să vezi:**
- fereastra se numește **„Un anunț pentru laborator"**;
- caseta are scris în ea, pal: *„Mâine vine făina la șase — lăsați loc lângă
  ușă."*;
- anunțul apare **deasupra comenzilor**, cu numele tău și ora;
- lângă buton scrie **„Se vede pe ecranele din laborator, pentru toată tura."**

### 3.2 Pui un termen

**Apeși:** încă un anunț, dar completezi numai caseta de **oră** din „Ține până
la", fără zi.

**Trebuie să vezi** un rând roșu: **„Ai scris ora, dar nu și ziua până la care
ține anunțul."** Completează și ziua — atunci intră.

Un anunț cu ziua completată și ora goală ține **până la miezul nopții**. Scrie
chiar sub casete.

### 3.3 Umpli ecranul

**Apeși:** pui anunțuri până ai **cinci**.

**Trebuie să vezi:** butonul **„Pune un anunț pe ecran"** se stinge, iar rândul
de lângă el se schimbă în: **„Ecranul e plin: 5 anunțuri deodată e cât încape
deasupra comenzilor. Ia unul jos ca să faci loc."**

### 3.4 Iei unul jos, și te uiți în Jurnal

**Apeși:** pe butonul de pe rândul anunțului, apoi `/lab/jurnal.html`.

**Trebuie să vezi** două rânduri noi, cu **textul întreg al anunțului** în ele:
- `lab_message_post · Anunț pus pe ecranul laboratorului, până la … : <textul>`
- `lab_message_down · Anunț luat de pe ecranul laboratorului, pus la … : <textul>`

Textul scris acolo e **dinadins**: un anunț e o vorbă spusă întregii ture, iar
cine l-a pus și ce scria în el e tocmai ce trebuie să se poată citi peste o lună.
Codurile de pontaj, în schimb, nu apar niciodată — vezi mai sus.

### 3.5 Cine poate și cine nu

**Apeși:** intri cu **`testangajat`** pe `/lab/laborator.html`.

**Trebuie să vezi:** anunțurile **da**, butonul de pus **nu**. Punerea e a
administratorului, a fondatorului și a managerului.

---

## 4 · Zona pe cont, ruta, și comanda dintr-o apăsare

*Șase minute. A doua lucrare nouă.*

### 4.1 Cont nou, cu adresă din prima

**Apeși:** `http://127.0.0.1:8101/clienti/index.html` (butonul „Intră în cont"
din magazin duce tot aici, dar e o legătură între subdomenii — local o tastezi)
→ **„Fă-ți cont"** → completezi, și la **„Zona"** alegi **„În afara Bucureștiului
și Ilfovului"** → **„Creează contul"**.

**Trebuie să vezi:**
- formularul cere **„Adresa firmei"** și **„Zona"** încă de la înscriere. Până
  acum se cereau abia la prima comandă;
- zona are prima opțiune **„Alege zona (nu e obligatoriu)"** — nu e o casetă care
  te ține pe loc;
- în clipa în care alegi zona de departe, rândul de sub casetă se schimbă în:
  **„Pentru livrările din afara Bucureștiului și Ilfovului ora e orientativă: o
  confirmăm la telefon."** Vestea se dă acolo, nu în ziua în care omul așteaptă
  mașina.

### 4.2 Comanda se umple singură

**Apeși:** după ce contul s-a făcut, browserul pleacă spre magazinul de
producție. Ești conectat — tastează `http://127.0.0.1:8101/`, alegi un produs și
mergi la comandă.

**Trebuie să vezi:**
- **nu** ți se mai cer numele, telefonul, adresa și zona — sunt deja pe cont;
- în locul lor, un cartonaș strâns cu numele tău, contactul, **adresa · zona** și
  ziua, plus butonul **„Modifică"**;
- sub el, avertizarea zonei: **„Livrăm și în afara Bucureștiului și Ilfovului.
  Ora e orientativă acolo: o confirmăm la telefon."**

**Apeși „Modifică".**

**Trebuie să vezi:** se deschid casetele, iar avertizarea **se mută sub „Când
vrei comanda"** — nu se dublează, apare într-un singur loc.

> **Ziua și intervalul rămân la vedere**, chiar și pentru zona de departe. Așa ai
> ales: omul spune când i-ar conveni, iar noi confirmăm la telefon. Dacă
> intervalul dispare, ăla e un defect.

Cu **`testclient`** (are adresă pe cont, dar **n-are zonă**) cartonașul strâns
**nu** se desenează: casetele se deschid singure. E purtarea bună — un rezumat
cu un rând gol e o cursă, nu un rezumat.

**Trimiți comanda.**

**Trebuie să vezi** mesajul de mulțumire, scris anume pentru cine are cont:
**„Comanda a ajuns la noi. Intră în producție după ce o confirmăm — o vezi în
«Comanda mea», din meniul contului."** (Vizitatorului nelogat i se spune altceva:
*„Cererea ta a intrat la noi în platformă…"*.) Magazinul trimite o **cerere** în
amândouă cazurile — comanda se naște la acceptare, în panou.

### 4.3 Comanda de departe, pe foaia de drum

**Apeși:** `testadmin` → `/lab/comenzi.html` → fila **„Cereri de pe site"** →
pe cererea ta, **„Acceptă și fă comanda"** → apoi `/lab/ruta.html`, pe ziua ei.

**Trebuie să vezi:**
- opririle din oraș, numerotate **1, 2, 3…**, ca până acum;
- **dedesubt, o listă aparte**, cu banda **„În afara orașului"** și rândul: *„Nu
  intră pe rută: zona n-are punct pe hartă, deci kilometrii și orele de sus nu
  le cuprind. Le duce cine poate, iar ora promisă se confirmă la telefon."*;
- comanda ta acolo, cu semnul **↗** în locul numărului de ordine;
- sus, în rezumat, coada: **„, din care 1 în afara orașului"**.

**De ce contează:** zona de departe n-are punct pe hartă (`Setări` → zone), deci
n-are cum să intre în ordonarea rutei. Dacă ar fi intrat, ar fi mințit și
kilometrii, și orele tuturor celorlalte opriri.

> Semnul e **lipsa punctului**, nu numele zonei. Dacă mâine scurtezi numele din
> Setări, purtarea rămâne aceeași. Dar dacă îi **dai un punct pe hartă**, intră
> pe rută ca oricare alta — și ăla e tot un răspuns bun, dacă asta vrei.

---

## 5 · Rozeta, unde lipsește poza

*Un minut.*

**Apeși:** `testadmin` → `/lab/produse.html` → **„+ Produs nou"**, și te uiți la
pătratul fotografiei din formular. Nu completa nimic și nu salva — închide-l
după ce te-ai uitat.

**Trebuie să vezi**, în pătrat, **rozeta aurie** — o filigranare palidă pe crem,
nu un pătrat gol și nu un desen apăsat.

> Toate cele 22 de produse adevărate din catalog **au** fotografie, deci rozeta
> nu se vede în listă. Formularul e locul unde se arată oricând, fără să strici
> nimic. (Dacă în listă dai peste vreun „Ștrudel de probă" fără poză, e resturi
> de la probele automate; se mătură cu `node .claude/curata-probe.mjs`.)

**Apoi schimbă tema calculatorului pe întuneric** (Windows: *Setări → Culori →
Modul aplicației: Întunecat*) și reîncarcă.

**Trebuie să vezi:** aceeași rozetă aurie, acum limpede pe ciocolatiu. Aurul e
singura cerneală care se vede în amândouă temele — albul e orbitor noaptea și
practic invizibil ziua.

---

## 6 · Statisticile, care sunt numai ale fondatorului

*Cinci minute. Panoul cel mai nou, și singurul din casă cu o poartă mai strâmtă
decât a administratorului.*

**Apeși:** intri cu `demo-fondator` și apeși **„Statistici"** în sertar. Dacă
baza de probă e goală pe intervalul de pornire, pune întâi în filtre un interval
în care chiar sunt comenzi — pagina nu inventează cifre, și pe gol arată gol.

### 6.1 Cele trei vederi

Sus, în dreapta barei de filtre, stau trei butoane: **„Inelul zilei"**,
**„Registrul"**, **„Tabloul de dimineață"**. Nu sunt trei pagini — sunt trei
croieli ale **aceleiași** pagini: aceleași cifre, aceleași widget-uri, alt
așezământ.

**Trebuie să vezi:**

- butoanele poartă **numai titlurile**, fără literele A, B, C;
- la fiecare apăsare, cifrele rămân aceleași — se mută doar unde stau;
- **„Tabloul de dimineață" e întunecat de sus până jos** — fundalul, bara de sus,
  fișa de filtre, tot. Și **fără licărire albă** la trecere: dacă vezi o clipă de
  alb înainte să se facă întuneric, tema s-a pus după desenare, nu înainte;
- ieși din pagină și te întorci în alta: **noaptea nu pleacă după tine.** Tema
  întunecată e a paginii ăsteia, nu a aplicației.

### 6.2 Inelul, și lupa

Inelul e **dublu**: cercul din afară e **felul mărfii** — copt și congelat, cu
culorile lor, aceleași peste tot în aplicație — iar cel dinăuntru sunt
**categoriile**, cel mult șapte, ca să nu se calce pe culori cu felul mărfii.

**Trebuie să vezi:**

- ții mouse-ul pe o felie → felia **crește puțin**, iar browserul scrie lângă
  cursor numele ei întreg („ștrudel · 68 buc · 53,8%");
- apeși pe o felie, ori pe oricare din cardurile de cifre → se deschide **lupa**,
  o foaie cu desfășurarea cifrei aceleia;
- **`Esc` închide lupa.** Dacă nu, tastatura n-a fost legată la deschidere.

### 6.3 Data socotită

În fișa de filtre, sub interval, stau două buline: **„ziua livrării"** (bifată
din pornire) și **„ziua în care s-a comandat"**.

**Trebuie să vezi:** sunt **buline** — cercuri mici, în coaja casei, cu scrisul
pe **un singur rând** lângă ele. Dacă arată ca niște casete de scris întinse pe
toată lățimea fișei, cu textul rupt pe două rânduri, e defectul reparat în 2.1.0
și s-a întors.

Mută-le pe **„ziua în care s-a comandat"**: cifrele se schimbă, iar rândul de sub
titlu spune **„după ziua comenzii"** în loc de „după ziua livrării".

**Ce caut aici, mai ales:** o comandă luată la telefon **după miezul nopții**
trebuie să cadă în ziua în care a fost luată — nu în cea dinainte. Ceasul bazei
merge pe UTC, ceasul patiseriei pe ora României; între ele sunt două sau trei
ore, adică exact valul de comenzi de dinaintea livrării de dimineață. Proba
automată ține scena asta (`.claude/test-statistici.mjs`, grupa D); aici e de
văzut doar că cifra pe „ziua comenzii" nu e absurdă.

### 6.4 Poarta

**Apeși:** ieși, intri cu **`testadmin`** și **tastezi de mână**
`http://127.0.0.1:8101/lab/statistici.html` — în sertarul lui butonul nu există,
și asta e jumătate din probă.

**Trebuie să vezi:** un ecran închis la culoare, cu sigla casei și rândul
*„Contul «testadmin» nu are acces la statistici. Cifrele casei se deschid numai
fondatorului — nici măcar administratorului, care conduce patiseria zi de zi."*
Dedesubt, două butoane: înapoi la panoul lui și ieșirea lui obișnuită. **Niciun
buton din ecranul ăsta nu are voie să ducă la un al doilea refuz** — de-aia sunt
socotite din rolul omului, nu scrise de mână.

---

## 7 · Telefon, tabletă, calculator

*Trei minute. Se face la capăt, ca să prinzi totul dintr-o trecere.*

În browser, `F12` → butonul cu telefon și tabletă (sus-stânga în panoul care se
deschide) → scrii lățimea de mână.

| Lățime | Ce probezi |
|---|---|
| **390** | telefonul. Sertarul e strâns sub butonul cu trei linii; „Ieși din cont" e în sertar. |
| **820** | tableta din laborator. `/lab/laborator.html` și `/lab/pontaj.html` — tastatura ceasului trebuie să încapă întreagă, fără derulare. |
| **1440** | calculatorul din birou. Sertarul e deschis, lângă conținut. |

**Trebuie să vezi**, la toate trei: **niciun rând de text tăiat**, niciun tabel
care împinge pagina în lateral, niciun buton mai mic decât un deget pe 390.

Tabelele late (raportul de pontaj, lista de comenzi) au voie să se **deruleze în
cutia lor**. Ce n-au voie e să miște pagina întreagă.

**Statisticile se probează la toate trei vederile**, nu doar la una: e aceeași
pagină croită de trei ori, deci sunt trei așezăminte de verificat la fiecare
lățime. Inelul are voie să se micșoreze, dar nu să-și taie scrisul de pe felii.

---

## Ce NU e defect aici

Ca să nu cauți ce nu s-a stricat:

- **Săriturile între subdomenii te duc pe site-ul adevărat.** „Comanda mea",
  „Intră în cont", aterizarea clientului după parolă — în pachet sunt adrese de
  producție, și așa trebuie. Local te întorci tastând adresa din tabelul de sus.
  **Sertarul panoului, în schimb, trebuie să meargă apăsat.**
- **`/lab/intrare.html` dă 404.** Pagina de intrare se cheamă `index.html` în
  pachet, pe amândouă subdomeniile. Merg și adresele scurte, fără `.html`, exact
  ca pe server: `/lab/pontaj`, `/lab/comenzi`, `/clienti/client`.
- **Nimeni n-are cod de pontaj la început**, iar ceasul scrie *„Nimeni n-are încă
  un cod de pontaj."* Așa se livrează. Codurile le pui tu, din Conturi.
- **`testclient` n-are zonă pe cont**, numai adresă. E dinadins, ca să se poată
  proba și cazul de la 4.2.
- **Pontajul nu se vede pe subdomeniul clienților.** Pagina e pusă numai pe
  `lab`, fiindcă acolo n-ar avea cui răspunde.
- **Anunțul se vede întreg în Jurnal.** E o hotărâre, scrisă în cod cu motivul
  ei.
- **În Statistici, tabelul de clienți nu spune „cum ia marfa".** În desenul de
  dinainte era o coloană cu asta; serverul n-are cifra aceea pe client — felul de
  onorare stă pe fiecare comandă, nu pe om, iar un client care ia și congelat, și
  copt, n-ar avea ce scrie acolo. În locul ei e **„Cum e trecut"**: *are cont*
  sau *scris pe comandă*. E o hotărâre, nu o coloană uitată.
- **În „Tabloul de dimineață", „după ziua livrării" e scris, nu apăsat.** E un
  rând care spune pe ce temei s-au socotit cifrele, exact ca în desen. Temeiul se
  schimbă din fișa de filtre (6.3), care e a întregii pagini — deci și a
  tabloului.

---

## Dacă ceva iese altfel

Scrie trei lucruri, atât:

1. **cu ce cont** erai,
2. **ce ai apăsat** (adresa paginii și butonul),
3. **ce ai văzut** în loc de ce scrie aici.

Dacă browserul a scos ceva roșu în consolă (`F12` → *Console*), fotografia
rândului ajută cel mai mult.

---

## După plimbare

Când toate cele șapte ies, proba automată se mai trece o dată pe deasupra:

```bash
node .claude/verifica-tot.mjs
```

Trebuie să iasă **0 verificări au eșuat**. Abia atunci urcă plasturele pe server,
după `CITESTE-MA.md` din folderul `plasture-vps 2.0.4`.
