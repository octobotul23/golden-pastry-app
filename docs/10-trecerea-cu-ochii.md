# Trecerea cu ochii, înainte de publicare

O listă scurtă: **ce apeși** și **ce trebuie să vezi**. Nu e un ghid de folosire
— e proba de dinaintea publicării, pe pachetul construit, cel care pleacă pe
server. Dacă toate rândurile de mai jos ies cum scrie aici, plasturele poate
urca.

Durează **șaizeci și nouă de minute** cu totul. Nu trebuie făcută dintr-o
bucată: fiecare din cele zece plimbări stă singură și își spune la început cât
ține. Cifra de sus e suma minutelor scrise acolo, plus cele ale bucăților care au
minutele lor: 4.4, 7.7 și 7.8 — dacă se mai adaugă o plimbare, se adună și aici.

Cele zece, în ordinea în care se fac cel mai ușor:

1. **Meniul, după rol** — sertarul, pentru toate cele șapte conturi.
2. **Pontajul din laborator** — codul, ceasul, raportul, plecarea scrisă de mână.
3. **Tabla de anunțuri din laborator** — anunțuri, termene, Jurnal.
4. **Zona pe cont, ruta, și comanda dintr-o apăsare** — plus coșul în două file
   și trimiterea căzută (4.4).
5. **Rozeta, unde lipsește poza** — formularul de produs, pe amândouă temele.
6. **Statisticile, care sunt numai ale fondatorului** — cele trei vederi, data
   socotită, poarta.
7. **Comanda anulată, adusă înapoi** — cele trei uși, caseta, anunțul care
   pleacă la capăt (7.5) și ecranul rămas în urmă: în „Comenzi" (7.6), pe
   tableta din laborator (7.7) și pe telefonul șoferului (7.8).
8. **Runda 2.3.0** — comutatorul din magazin (8.1), bara strânsă (8.2),
   harta din Contact (8.3), versiunea la vedere (8.4), și laboratorul pe
   telefon: produsele (8.5), tragerea (8.6) și oprirea la „Predată
   curierului" (8.7).
9. **Runda 2.3.1** — caseta de raportat care mișcă cifra (9.1), cele patru
   pastile din Depozit (9.2), capul listei care numără ce se vede (9.3) și
   pastila cu versiunea, mutată în capul sertarului (9.4).
10. **Telefon, tabletă, calculator** — 390, 820, 1440, și degetele de 44 px.

A zecea se face la capăt dinadins: prinde într-o singură trecere tot ce ai
deschis mai devreme.

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
îți dă butonul **„Mergi la panoul tău"** — iar pe o sesiune de client, **„Vezi
comanda mea"**, cu **„Intră în magazin"** dedesubt. Clientul n-are panou de
lucru, deci butonul nu-i poate promite unul.

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
- **Fondatorul are „Statistici" și în panoul de comenzi, în grupa „În
  comenzi".** Pe monitor stă în bara din stânga, sub **„Cereri de pe site"**, cu o
  săgeată → în loc de număr, fiindcă nu e o vedere a panoului, ci altă pagină.
  Pe telefon e ultimul rând din **„Schimbă ▾"**, după cele patru vederi și
  înaintea filtrelor. Apasă-l: trebuie să se deschidă Statisticile. Cu
  `testadmin` grupa rămâne cu cele patru vederi — Comenzi, Rută șofer, Produse,
  Cereri de pe site — fără „Statistici"; dacă o vede, e același defect ca în
  sertar.
- **Agentul `mihai` NU trebuie să cadă pe Comenzi.** Ziua lui începe la
  Recomandări, deși are și Comenzi în sertar.
- **Nimeni nu vede o intrare pe care apăsând-o capătă „nu ai voie".** Dacă se
  întâmplă, ăla e un defect și merită scris pe hârtie: ce cont, ce buton.
- **Apasă-le pe toate din sertar**, nu doar citește-le. În panou, legăturile
  sunt scurte și trebuie să meargă. (Singura care nu merge local e „Comanda
  mea", pe care oricum n-o are niciunul din cele cinci.)

### Clientul, aparte

**Apeși:** `demo-cumparator` pe `/clienti/index.html`.

**Trebuie să vezi:** după parolă, browserul cade singur în **„Comanda mea"**
(`http://127.0.0.1:8101/clienti/client`) — n-ai nimic de tastat. Aia e adresa
bună: cine își scrie parola vrea să vadă ce a comandat, fiindcă magazinul se
deschide și fără cont. Dacă în locul ei te trezești în magazin, s-a întors
purtarea de dinainte de 13 septembrie 2026.

Magazinul e la un clic — butonul **„Intră în magazin"** de pe pagina de intrare
—, dar acela e o săritură între subdomenii: local te duce pe site-ul adevărat
(`goldenpastry.ro`). Te întorci tastând `http://127.0.0.1:8101/`.

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
prima (1234, 0000 și frații lor). Alege altul."** Sunt douăzeci și patru de
coduri refuzate — zece cu toate cifrele la fel, plus câte șapte pe fiecare din
cele două șiruri drepte (0123…6789 și 9876…3210). Nu e o regulă
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
Codurile de pontaj, în schimb, nu apar niciodată — vezi 2.5.

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

**Apeși:** contul făcut te lasă tot în **„Comanda mea"** — sesiunea e deschisă
de server, deci drumul e același ca după o intrare cu parolă. De acolo tastează
`http://127.0.0.1:8101/`, alegi un produs și mergi la comandă.

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

### 4.4 Coșul în două file, și trimiterea căzută

*Patru minute. Pe magazin, fără cont, afară de ultimul pas.*

**Apeși:** deschizi `http://127.0.0.1:8101/` în **două file** ale aceluiași
browser. În prima pui un produs în coș, apoi treci în a doua, **fără s-o
reîncarci**.

**Trebuie să vezi** produsul deja în coș și în a doua filă, cu aceeași sumă. Până
la 2.1.1 a doua filă rămânea cu coșul de la deschidere, iar prima apăsare în ea
scria coșul ei vechi peste cel nou.

**Apeși:** în a doua filă, **„Golește"**. Te întorci în prima.

**Trebuie să vezi** și prima filă cu coșul gol.

**Apeși:** în prima filă pui un produs, apoi, în aceeași filă, tastezi
`http://127.0.0.1:8101/lab/index.html`. În a doua filă mai pui un produs. Te
întorci în prima și apeși săgeata **„Înapoi"** a browserului.

**Trebuie să vezi** magazinul cu **amândouă** produsele în coș, nu doar cu primul.
Browserul ține pagina în memorie și o arată pe loc la „Înapoi"; acum pagina își
recitește coșul când e adusă așa înapoi.

**Apeși:** cu un produs în coș → **„Finalizare comandă"** → completezi numele și
telefonul → `F12` → fila **Network** → în lista de sus, unde scrie **„No
throttling"**, alegi **„Offline"** → **„Trimite cererea"**.

**Trebuie să vezi**, în caseta roșie de deasupra butonului: *„Legătura la
internet s-a întrerupt chiar în timp ce trimiteam cererea, așa că nu știm dacă a
ajuns la noi."*, urmată de îndemnul să nu o trimiți din nou pe loc, ci să scrii
pe WhatsApp, cu butonul verde de dedesubt. Coșul rămâne plin, iar butonul
**„Trimite cererea"** se aprinde la loc.

Același pas, cu **`testclient`** (intri pe `/clienti/index.html`, apoi tastezi
magazinul): caseta spune *„Înainte s-o trimiți din nou, uită-te în «Comanda
mea», din meniul contului"*. Cu **`testadmin`**, trimite la **Comenzi**, la
**„Cereri de pe site"**. Pe `http://127.0.0.1:8101/en/`, toate trei sunt în
engleză.

> **De ce nu „încearcă din nou".** Legătura cade des tocmai după ce serverul a
> scris cererea, iar răspunsul se pierde pe drum. Serverul nu recunoaște încă o
> a doua trimitere a aceleiași cereri, deci un „încearcă din nou" ar fi adus în
> laborator două cereri identice. Un **refuz** al serverului (telefon greșit,
> produs scos din gamă) nu e o cădere din rețea și rămâne cu vorbele lui, ca
> până acum.

La capăt pui lista din Network înapoi pe **„No throttling"** — altfel nimic din ce
urmează nu mai ajunge la server.

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

**Apeși:** intri cu `demo-fondator` și apeși **„Statistici"** în sertar (sau, în
panoul de comenzi, în grupa **„În comenzi"**). Pe amândouă le-ai numărat deja la
1; aici se probează pagina din spatele lor. Dacă baza de probă e goală pe
intervalul de pornire, pune întâi în filtre un interval în care chiar sunt
comenzi — pagina nu inventează cifre, și pe gol arată gol.

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

## 7 · Comanda anulată, adusă înapoi

*Treisprezece minute. O comandă se scoate din „Anulată" pe trei uși — butonul
„Reface comanda" din formular, lista „Etapa" din fișa rapidă și lista „Stare" a
formularului, la salvare —, iar toate trei trec prin aceeași casetă. La capăt,
echipa află: pleacă anunțul „Comandă refăcută" (7.5), iar doi oameni pe aceeași
comandă nu se mai scriu unul peste altul — în „Comenzi" (7.6), pe tableta din
laborator (7.7) și pe telefonul șoferului (7.8).*

### 7.1 Din fișa rapidă, pe o comandă de azi

**Apeși:** `testadmin` → `/lab/comenzi.html` → pe rândul unei comenzi de azi sau
de mâine, butonul mic **„Vezi produsele"** (se deschide fișa) → la **„Etapa"**
alegi **„Anulată"**.

**Trebuie să vezi:** comanda trece în „Anulată" pe loc, fără nicio întrebare.
Anularea din fișă merge direct, ca până acum.

**Apeși:** tot în fișă, la **„Etapa"**, alegi **„La ambalare"**.

**Trebuie să vezi:**
- peste fișă, caseta **„Refaci comanda #…?"**, iar lista de etape de dedesubt
  **tot pe „Anulată"** — nimic n-a plecat încă la server;
- în notă, **etapa aleasă**: *„Se întoarce în «La ambalare», reapare pe panoul
  din laborator și pe ruta șoferului, iar marfa e din nou pusă deoparte în
  depozit. Scăderea din raft se face când ajunge iar la «Predată», cu «Scade din
  stoc» din Depozit."* Dacă scrie „În așteptare", ăla e un defect;
- **niciun** rând galben: ziua comenzii nu e în trecut;
- butoanele **„Lasă-o anulată"** și **„Reface comanda"**.

**Apeși „Lasă-o anulată".** Caseta se închide, comanda rămâne „Anulată".

**Alegi iar „La ambalare" → „Reface comanda".** Comanda e în **„La ambalare"**,
iar sus scrie *„Comanda #… s-a întors în «La ambalare»."*

Mutările între etapele din flux (de pildă „La ambalare" → „Predată") merg în
continuare direct, fără casetă.

### 7.2 Spre „Predată"

**Apeși:** pe aceeași comandă, din fișă, **„Anulată"**, apoi **„Predată"**.

**Trebuie să vezi** în casetă: *„Trece direct în «Predată»: marfa nu se mai pune
deoparte, ci așteaptă scăderea din raft, cu «Scade din stoc» din Depozit."* — nu
promisiunea cu panoul și ruta. Apeși **„Lasă-o anulată"**, apoi o aduci înapoi
în etapa în care era, tot prin casetă.

### 7.3 Pe o zi care a trecut

Îți trebuie o comandă de ieri sau mai veche, care **nu** e „Predată" (ca să nu
umbli la stoc). Pune sus filtrul **„Toate zilele · cu istoric"**. Dacă baza de
probă n-are niciuna, sari peste 7.3: proba automată acoperă cazul.

**Apeși:** fișa ei → **„Anulată"** → apoi o etapă din flux, de pildă **„La
dospit"**.

**Trebuie să vezi:**
- nota **fără** „reapare pe panoul din laborator și pe ruta șoferului";
- între notă și butoane, un **rând galben**: *„Ziua comenzii e în trecut și
  trebuie mutată. Refăcută așa, rămâne pe ziua veche și nu reapare nici pe
  panoul din laborator, nici pe ruta șoferului, care pornesc de la azi. Alege în
  câmpul «Zi» al comenzii o zi de azi încolo, salvează, apoi refă comanda."*

**Apeși „Lasă-o anulată"**, apoi **„Editează comanda"** → în subsol, **„Reface
comanda"**. **Trebuie să vezi** același rând galben, pe cealaltă ușă. Apeși
**„Lasă-o anulată"**.

Ieși, intră cu **`demo-manager`** și alege din nou o etapă din fișa aceleiași
comenzi. Rândul galben se încheie altfel: *„Ziua se mută dintr-un cont de
administrator: cere-i s-o mute, apoi refă comanda."* — managerul n-are câmpul
„Zi". Apeși **„Lasă-o anulată"**.

Alege acum **„Predată"**: rândul spune *„Ziua comenzii e în trecut. Trecută în
«Predată», rămâne predată pe ziua aceea: e bine numai dacă atunci a fost
livrată…"*. Apeși **„Lasă-o anulată"**.

La capăt, pune comanda înapoi în etapa în care o găsiseși: **„Reface comanda"**
din casetă. Ziua nu se mută, deci rămâne cum era.

### 7.4 A treia ușă: lista „Stare" a formularului, la salvare

Ușa asta nu mută numai etapa: pleacă tot formularul, într-o singură salvare.
De-aia caseta arată la fel, dar butoanele spun altceva.

**Apeși:** `testadmin` → `/lab/comenzi.html` → pe o comandă de azi sau de mâine,
**„Vezi produsele"** → **„Editează comanda"** → în formular, la **„Stare"**,
alegi **„Anulată"** → **„Salvează modificările"**.

**Trebuie să vezi:** comanda trece în „Anulată" pe loc, **fără casetă**.
Anularea din formular merge direct, ca până acum.

**Apeși:** iar **„Editează comanda"** pe ea și schimbi **două** lucruri: la
**„Stare"** alegi **„La ambalare"**, iar în **„Telefon"** mai adaugi o cifră.
Apeși **„Salvează modificările"**.

**Trebuie să vezi:**
- **aceeași casetă** „Refaci comanda #…?", cu nota pe **etapa aleasă**: *„Se
  întoarce în «La ambalare»…"*. Dacă scrie „În așteptare", ăla e un defect:
  caseta trebuie să spună unde duce chiar lista din formular;
- **formularul rămâne dedesubt**, cu tot ce ai tastat, iar comanda de pe rând e
  tot „Anulată": n-a plecat nimic la server;
- butoanele, altele decât pe celelalte două uși: **„Înapoi la formular"** și
  **„Salvează și reface"**.

**Apeși „Înapoi la formular".** Caseta se închide, formularul rămâne deschis, cu
„La ambalare" în listă și cu cifra în plus la telefon — nimic nu s-a pierdut.

**Apeși din nou „Salvează modificările" → „Salvează și reface".** Comanda e în
**„La ambalare"** *și* cu telefonul schimbat: o singură salvare, nu două.

**Apeși:** tot din formular, pune-o iar pe **„Anulată"**, salvezi, apoi
**„Editează comanda"** → la „Stare" alegi **„Predată"** → **„Salvează
modificările"**. **Trebuie să vezi** în casetă nota cu scăderea din raft
(*„Trece direct în «Predată»…"*), nu promisiunea cu panoul și ruta. Apeși
**„Înapoi la formular"**, pui la loc etapa în care o găsiseși și salvezi.

**Pe o zi care a trecut** (aceeași comandă ca la 7.3, dacă ai avut una): o
anulezi din formular, apoi, tot din formular, alegi o etapă din flux și salvezi.
Rândul galben e cel de la 7.3, dar **sfatul se încheie altfel**: *„Alege în
câmpul «Zi» o zi de azi încolo și salvează din nou: ziua și etapa pleacă în
aceeași salvare."* Apeși **„Înapoi la formular"**, muți **„Zi"** pe mâine
*fără* să atingi lista „Stare" și apeși iar **„Salvează modificările"**:
**caseta apare fără rândul galben**, fiindcă se cântărește ziua de DUPĂ salvare.
Apeși **„Salvează și reface"** și pune comanda înapoi cum ai găsit-o.

### 7.5 Anunțul „Comandă refăcută"

Pe calculatorul tău e-mailurile nu pleacă în lume: cad în cutia locală
**Mailpit**, pe `http://127.0.0.1:8025`. Acolo vezi ce ar fi primit patronul pe
telefon. Dacă adresa nu răspunde, pornește
`C:\laragon\bin\mailpit\1.22.3\mailpit.exe` și lasă fereastra deschisă.

**Apeși:** `testadmin` → `/lab/setari.html` → fila **„Notificări"**. Cartonașul
**E-mail** trebuie să fie pornit și să aibă o adresă în „Adresa care primește
anunțul" (oricare: nu pleacă nimic). Sus, în **„Ce anunțuri primești, și pe
unde"**, bifezi **E-mail** pe rândul **„Comandă anulată"** și îl lași nebifat pe
**„Comandă gata"** → **„Salvează"**.

**Trebuie să vezi:** sub „Comandă anulată", ajutorul spune și *„Pe aceleași
canale pleacă și «Comandă refăcută», dacă o aduci înapoi."* Un rând **„Comandă
refăcută"** **nu** există: n-are bifă a lui, dinadins.

**Apeși:** `/lab/comenzi.html` → pe o comandă de azi sau de mâine, din fișă,
**„Anulată"**, apoi **„La ambalare"** → **„Reface comanda"**. Deschizi Mailpit.

**Trebuie să vezi** două mesaje noi cu aceeași referință: **„Comandă anulată
#…"** și, deasupra lui, **„Comandă refăcută #…"**. În corpul celui de sus scrie
*„Etapă: Anulată → La ambalare"*. Dacă lipsește „Comandă refăcută", pe server a
rămas API-ul vechi.

**Apeși:** în Setări bifezi **E-mail** și pe **„Comandă gata"**, salvezi, apoi
din fișa aceleiași comenzi **„Anulată"** → **„Predată"** → **„Reface comanda"**.

**Trebuie să vezi** trei mesaje noi. Citite de jos în sus, în ordinea în care au
plecat: **„Comandă anulată"**, **„Comandă refăcută"**, **„Comandă gata"**. Două
mesaje la aceeași apăsare sunt hotărârea patronului: comanda a fost și adusă
înapoi, și predată, iar ultimul mesaj rămâne cel adevărat.

**Apeși:** în Setări scoți bifa **E-mail** de pe **„Comandă anulată"** și salvezi,
apoi din fișă **„Anulată"** → **„La ambalare"** → **„Reface comanda"**.

**Trebuie să vezi** în Mailpit **niciun** mesaj nou: fără bifa anulării, pe
canalul acela nu vine niciunul din cele două.

**Apeși:** pui bifele la loc, apoi faci același drum de pe **ușa salvării**
(7.4): din formular, „Anulată" → salvezi → „La ambalare" plus o cifră în plus la
telefon → **„Salvează și reface"**.

**Trebuie să vezi** în Mailpit **un singur** „Comandă refăcută" pentru apăsarea
asta. Două ar însemna două cereri la server pentru o salvare, adică două rânduri
de anunțuri pentru patron.

La capăt, pune comanda înapoi în etapa în care o găsiseși (din fișă, direct) și
lasă bifele din Setări cum le-ai găsit.

### 7.6 Doi oameni pe aceeași comandă: cine ajunge al doilea

*Patronul și cea care ia comenzile la telefon stau amândoi în „Comenzi", pe
aceeași comandă. Cine apasă al doilea nu mai scrie peste mutarea celuilalt:
serverul îl oprește, iar pagina îi spune ce s-a întâmplat și unde a ajuns
comanda.*

**Pregătești:** aceeași pagină `/lab/comenzi.html` în **două file**, amândouă cu
`testadmin`. Le spunem **A** și **B**. Alegi o comandă de azi sau de mâine și
lucrezi numai pe ea.

**Prima ușă: caseta lăsată deschisă.**

**Apeși:** în fila B, din fișa comenzii, **„Anulată"** (merge direct). Treci în
fila A, o reîncarci (ca să vadă și ea „Anulată") și, din fișă, alegi **„La
ambalare"** → se deschide caseta **„Refaci comanda #…?"**. **Lași caseta
deschisă** și treci iar în fila B: acolo, din fișa aceleiași comenzi, o refaci tu
— **„La dospit"** → **„Reface comanda"**. Te întorci în fila A și apeși, în
caseta rămasă deschisă, **„Reface comanda"**.

**Trebuie să vezi** în fila A: caseta se închide, iar sus apare un chenar
**roșu**: *„Comanda #… a fost schimbată între timp de altcineva: acum e «La
dospit». N-am refăcut-o."* Tabelul și fișa arată pe loc **„La dospit"** — lista
s-a citit din nou —, iar comanda **nu** s-a dus în „La ambalare". Dacă în locul
chenarului roșu scrie „s-a întors în «La ambalare»", pe server a rămas API-ul
vechi.

**A doua ușă: formularul lăsat deschis.**

**Apeși:** în fila A, pe aceeași comandă, **„Editează comanda"**; mai adaugi o
cifră la telefon, dar **nu salvezi**. Treci în fila B și, din fișă, muți comanda
în **„În cuptor"**. Înapoi în fila A, în formularul rămas deschis, alegi la
**„Stare"** → **„La ambalare"** și apeși **„Salvează modificările"**.

**Trebuie să vezi** în fila A: **formularul rămâne deschis**, cu telefonul tastat
în el, iar deasupra butoanelor, în chenar roșu: *„Comanda #… a fost schimbată
între timp de altcineva: acum e «În cuptor». Nu s-a salvat nimic: modificările
tale au rămas în formular."* În fila B, comanda e tot în **„În cuptor"**, iar
**telefonul a rămas cel vechi** — din salvarea aceea n-a plecat nimic, nici
măcar câmpurile care n-au nicio legătură cu etapa.

**Apeși:** în fila A închizi formularul cu **„Anulează"** (butonul de jos, nu
etapa), pui comanda înapoi în etapa în care ai găsit-o și închizi una din file.

### 7.7 Ecranul rămas în urmă: tableta din laborator

*Patru minute. Îți trebuie două file deschise pe `lab.`, amândouă cu
`testadmin`. Aceeași pază ca la 7.6, dar pe panoul din laborator, unde nu e nici
casetă, nici formular — numai un card și un buton.*

**Partea întâi: cardul comenzii anulate pleacă singur de pe ecran.**

**Apeși:** în fila întâi, `/lab/laborator.html`. Alegi de pe listă o comandă de
azi și o lași pe ecran. **Treci pe fila a doua.**

**Apeși:** în fila a doua, `/lab/comenzi.html` → pe aceeași comandă, din fișă, la
**„Etapa"** alegi **„Anulată"**. Merge direct, fără casetă — anularea din fișă n-a
cerut niciodată încuviințare (7.1); caseta se deschide numai la **ieșirea** din
„Anulată".

**Apeși:** te întorci la fila întâi, **fără s-o reîncarci**.

**Trebuie să vezi:** cardul comenzii **nu mai e pe panou**, din clipa întoarcerii.
Panoul își cere lista la patru secunde și stă pe loc cât fila e ascunsă, dar la
revenire citește **pe loc**, fără să aștepte tura următoare. O comandă moartă nu
rămâne pe ecranul din laborator, oricât ar fi stat tableta cu fața la perete.
Dacă după o clipă cardul e tot acolo, ăla e defectul.

**Apeși:** în fila a doua, aduci comanda înapoi în etapa în care era: din fișă,
etapa ei → caseta **„Refaci comanda #…?"** → **„Reface comanda"**. În fila întâi,
cardul se întoarce la cel mult patru secunde.

**Partea a doua: mesajul serverului, cu împrospătarea tăiată pentru o clipă.**

Ca să vezi chiar mesajul trebuie să apeși pe un card sub care comanda e deja
anulată — adică pe un ecran rămas în urmă. Partea întâi tocmai a arătat că panoul
nu te lasă să rămâi: la patru secunde, și încă o dată la întoarcerea pe filă, își
recitește lista, iar cardul piere înainte să apuci să-l atingi. **Nu fiindcă paza
ar fi slabă, ci fiindcă panoul e prea harnic.** Deci îi tăiem lista cu mâna, cât
să încapă o apăsare.

**Apeși:** în fila întâi, `F12` → fila **„Network" („Rețea")** → la lista de
viteze din capul ei alegi **„Offline"**. Lași fereastra deschisă și **treci pe
fila a doua**.

**Apeși:** în fila a doua, din fișă, **„Anulată"**.

**Apeși:** te întorci la fila întâi. Panoul cere lista la revenire, nu primește
nimic și rămâne cu cea veche — cardul e tot acolo, pe etapa lui. Pui înapoi **„No
throttling"** în locul lui „Offline" și apeși **îndată** butonul de pe card, cel
care duce comanda în etapa următoare. **Ai cel mult patru secunde**: prima tură
care prinde iar rețeaua ia cardul de pe ecran.

**Trebuie să vezi:** sus, mesajul **„Comanda #… a fost schimbată între timp: acum
e «Anulată». N-am mutat-o."** — nu „Nu s-a salvat". Mesajul stă până îl închizi
tu, deci nu-l pierzi nici dacă între timp cardul a plecat; și pleacă, fiindcă
după refuz panoul recitește lista pe loc. În fila a doua, comanda e tot
**„Anulată"**.

**Apeși:** `/lab/jurnal.html`, filtrul de zonă **„Comenzi"**, cauți referința
comenzii.

**Trebuie să vezi** rândul anulării de adineauri și **niciun** rând de modificare
scris la ora apăsării de pe card. Un `order_update · Comandă #…: Anulată → …` pus
la ora aceea ar însemna că mutarea a trecut totuși, iar mesajul de pe ecran a
mințit. Serverul refuză sub lacăt, înainte de orice scriere: nu umblă la depozit,
nu scrie în Jurnal, nu pune niciun anunț la coadă — de-aia Jurnalul e locul unde
minciuna s-ar vedea.

**Dacă ai pierdut cursa** și cardul a dispărut înainte să apeși, refă comanda din
fila a doua și încearcă din nou. Dacă o pierzi de fiecare dată, taie numai cererea
listei, nu toată rețeaua: în „Network", clic-dreapta pe rândul `orders?day=…` →
**„Block request URL"**. Atunci panoul rămâne în urmă cât vrei tu, iar apăsarea de
pe card pleacă nestingherită — ea merge pe altă adresă, `/orders/<număr>`. La
capăt scoți regula din lista de blocări, altfel panoul rămâne mort și în plimbările
următoare.

**Apeși:** pe cardul unei comenzi pe care n-a atins-o nimeni între timp, de două
ori repede, două etape una după alta.

**Trebuie să vezi:** amândouă etapele se scriu, în ordinea apăsării — cardul
rămâne pe a doua, și după împrospătare. Niciun mesaj de „schimbată între timp":
apăsările pe card nu trec prin împrospătare, ci printr-un lanț al lor, ținut
pe fiecare comandă în parte, iar a doua pleacă abia după ce prima s-a scris. Fără
lanț, a doua ar porni cu etapa de dinaintea primei și ar lua un refuz pe degeaba.

La capăt, pune comanda înapoi în etapa în care o găsiseși.

### 7.8 Ecranul rămas în urmă: telefonul șoferului

*Trei minute. Aceeași pază ca la 7.7, pe celălalt ecran de tură. Aici nu-ți
trebuie nimic din „Network": foaia de drum se împrospătează la CINCISPREZECE
secunde, nu la patru, și nu se recitește la întoarcerea pe filă. Cursa se prinde
din prima, cu mâna goală.*

Șoferul e la a treia oprire, cu telefonul în buzunar de zece minute.
Administratorul anulează comanda. Șoferul scoate telefonul și apasă **„Marchează
livrat"** pe o listă veche de-un sfert de minut. Ce vede el în clipa aia n-a
văzut nimeni, în niciun browser — de-asta e scrisă bucata de față.

**Pregătești:** două file, amândouă cu `testadmin`.

- **A**, a administratorului: `/lab/comenzi.html`.
- **B**, a șoferului: `/lab/ruta.html`, pe ziua de azi, îngustată la telefon —
  `F12` → butonul cu telefon și tabletă → **390** (ca la 8).

Amândouă cu același cont, ca la 7.7: sesiunea e a browserului, nu a filei, deci
un al doilea cont ar intra peste primul în amândouă. Dacă vrei totuși ecranul
șoferului cu contul lui, deschide fila B într-o fereastră privată, cu
`testangajat` — rolul „angajat" are panoul „Rută".

Alegi o comandă de azi care **nu e livrată** și are cel puțin două opriri
înaintea ei, ca ecranul să fie derulat, ca al lui.

**Apeși:** în fila B, pe cartonul ei, ca să se **deschidă** — la butonul
„Marchează livrat" se ajunge numai prin carton. Deschide-l ACUM, nu după: cele
cincisprezece secunde le vrei întregi pentru apăsare, iar deschiderea unui carton
șterge și mesajul de pe ecran.

**Apeși:** treci în fila A → pe aceeași comandă, din fișă, la **„Etapa"** alegi
**„Anulată"** (merge direct, fără casetă — 7.1). Te întorci pe loc în fila B și
te uiți întâi dacă **cartonul mai e pe listă**. El e ceasul tău: ruta arată numai
comenzile vii, deci cât timp cartonul e acolo, lista din telefon e cea veche.

- **e tot acolo, deschis** → ecranul e rămas în urmă, exact ca în buzunar. Apeși.
- **a pierit** → tura de cincisprezece secunde a apucat să treacă și lista s-a pus
  la zi. Refaci comanda din fila A — etapa ei → caseta **„Refaci comanda #…?"** →
  **„Reface comanda"** — și încerci din nou, mai repede. Nu-ți trebuie să apeși pe
  o listă pusă la zi: acolo nu mai e nimic de probat.

**Apeși:** în fila B, **„Marchează livrat"**.

**Trebuie să vezi**, toate cinci:

1. **Mesajul e roșu.** Sus, sub antet: *„Comanda #… a fost schimbată între timp:
   acum e «Anulată». N-am mutat-o."* E singurul loc din toată reparația unde
   vestea e roșie, nu neutră. Ca să ai cu ce compara, marchează pe urmă o livrare
   care merge: aceeași casetă, în același loc, pe verde. Dacă și refuzul iese
   verde, culoarea nu se mai uită la ce s-a întâmplat.
2. **Încape pe 390.** Se rupe în rânduri întregi, nu taie niciun cuvânt, nu iese
   din chenar și nu împinge pagina în lateral.
3. **Îl vezi fără să derulezi?** Mesajul se așază deasupra listei, iar tu ai
   apăsat la a treia oprire, cu pagina derulată. Dacă a trebuit să urci ca să-l
   găsești, **scrie-o ca defect**: pe un telefon scos din buzunar, un mesaj pe
   care nu-l vezi e un mesaj care n-a fost dat.
4. **Cartonul sare înapoi, apoi piere de tot.** La apăsare se strânge și trece o
   clipă pe livrat — bulină **✓**, antet verde —, ca și când ar fi mers; așa face
   și când chiar merge. După refuz, foaia de drum se recitește pe loc, iar
   oprirea **nu mai e pe listă**: ruta duce comenzile vii, iar una anulată n-are
   ce căuta în mașină. Dacă era o oprire numerotată, cele de după ea se trag cu
   una mai sus, iar numărătoarea din capul paginii scade cu o livrare. Clipa de
   verde poate fi prea scurtă ca s-o prinzi pe local — ce se cere aici e **unde
   ajunge cartonul**, nu cât a ținut verdele.
5. **Mesajul rămâne peste dispariție.** Aici e tot rostul bucății: oprirea îi
   piere de sub deget tocmai cât citește, iar dacă ar pleca și mesajul odată cu
   ea, șoferul ar rămâne cu o listă mai scurtă cu una și fără nicio vorbă despre
   ce s-a întâmplat — exact felul în care se nasc telefoanele în laborator. Nu se
   stinge singur, nici după ce trece o tură de cincisprezece secunde peste el:
   pleacă abia când șoferul atinge un carton sau schimbă ziua.

**Apeși:** `/lab/jurnal.html`, filtrul de zonă **„Comenzi"**, cauți referința
comenzii.

**Trebuie să vezi** rândul anulării de adineauri și **niciun** rând scris la ora
apăsării de pe telefon. Un `order_update · Comandă #…: Anulată → Predată` pus la
ora aia ar însemna că livrarea a trecut totuși și că mesajul roșu a mințit — iar
pe deasupra, că din „Anulată" s-a ieșit, deci pe canalele din Setări a plecat un
„Comandă refăcută" (7.5) pentru o comandă pe care patronul tocmai o oprise.
Serverul refuză sub lacăt, înainte de orice scriere: de-aia Jurnalul e locul
unde s-ar vedea minciuna.

La capăt, refaci comanda din fila A și pui fila B înapoi pe lățimea ei.

---

## 8 · Runda 2.3.0: comutatorul, bara, harta, versiunea, laboratorul pe telefon

*Nouă minute. Cele șapte bucăți de mai jos sunt lucrările rundei ăsteia, deci
singurele care n-au mai fost plimbate niciodată.*

Primele patru se fac în magazin, fără cont. Ultimele trei se fac cu
`demo-manager`, pe telefon (390), în panoul de laborator.

### 8.1 Comutatorul congelat ↔ gata de servire

Magazinul, `http://127.0.0.1:8101/`. Sub titlu stă pastila cu două semne —
fulgul și cuptorul. Apeși pe fiecare, pe rând, de vreo cinci ori, repede.

**Trebuie să vezi:** un jeton rotund care **alunecă** de sub un semn sub
celălalt, cu un arc scurt la capăt. Nu sare, nu clipește, nu apare de nicăieri.
Sub pastilă iese o clipă o vorbă — „Congelate" ori „Gata de servire" — scrisă
pe **fundal închis, cu o dungă subțire de jur împrejur**, cu literele deschise
la culoare.

**Nu trebuie să vezi niciun dreptunghi alb.** Nici cât o clipă, nici peste
semne, nici sub ele. Ăsta era defectul reclamat: un cartonaș aproape alb, de
82 pe 29, care apărea la trei pixeli sub semne, stătea o secundă și jumătate și
pleca. Era singura suprafață albă din toată pagina, deci se vede din prima
dacă a revenit.

Apasă comutatorul și **cu tastatura** — `Tab` până ajungi pe el, apoi `Enter` —
nu numai cu mouse-ul. E a doua cale spre același simptom, și se probează la
fel: nimic alb, nicio clipă.

### 8.2 Bara de finalizare din Catalog, strânsă

Tot în magazin, pe telefon (390): `http://127.0.0.1:8101/catalog`. Alegi două
produse, ca să se ridice bara de jos.

**Trebuie să vezi:** o bară **de un singur rând** — butonul de coș, totalul,
WhatsApp, „Finalizare" — cu lămurirea scrisă **mărunt sub total**, pe același
rând, nu dedesubt. Progresul e un **fir subțire lipit de muchia de sus** a
barei, nu o dungă cu spațiu în jurul ei.

**Și partea care se uită:** derulează lista până la capăt. **Ultimul produs
trebuie să se vadă întreg**, nu pe jumătate sub bară. Locul de sub listă și
înălțimea barei se socotesc azi din aceleași numere; dacă ultimul rând intră
sub bară, cineva le-a despărțit iar în două cifre.

Butoanele rămân la 44 px pe înălțime — se măsoară ca la plimbarea a noua.

### 8.3 Harta din Contact

`http://127.0.0.1:8101/contact`, cartonașul **Depozit**.

**Trebuie să vezi:** o **poză** cu harta și cu un ac pe locul depozitului,
între program și legătura de dedesubt, cu colțurile rotunjite ca restul
cartonașelor. Sub ea, mărunt: **© OpenStreetMap contributors**.

Apeși pe poză: se deschide **în filă nouă** harta de la Google, pe punctul
depozitului. Te întorci în fila veche și te uiți în `F12` → *Network*: la
deschiderea paginii **nu trebuie să plece nicio cerere către alt site** —
harta e o poză din pachet, nu o hartă vie. Dacă vezi cereri spre un server de
dale, poza a fost înlocuită cu o hartă încărcată din lume, și atunci pagina
are nevoie și de o casetă de consimțământ, care nu există.

### 8.4 `ver. X.Y.Z` la vedere

> **Pastila s-a mutat între timp.** Runda 2.3.1 a scos-o din subsolul
> sertarului de telefon și a pus-o în capul lui — v. 9.4, unde scrie și de
> ce. Ce se probează aici rămâne totuși al rundei ăsteia: versiunea **se
> vede**, și se vede în două locuri deosebite.

Versiunea se vede în **două locuri**, fiindcă panourile de tabletă n-au bară
laterală deloc — adică tocmai ecranele care pot rămâne cu cod vechi n-ar fi
avut-o nicăieri.

| Unde | Cum ajungi |
|---|---|
| **capul sertarului de telefon** | pe 390, butonul cu trei linii — se vede din clipa în care se deschide, sub „Golden Pastry"; toate paginile de panou, laborator și rută între ele |
| **colțul din stânga-jos al barei laterale** | pe 1440, sub „← Alege panoul" — paginile de calculator |

**Trebuie să scrie** `ver. 2.3.1 · ` și o ștampilă — ziua și ora pachetului, de
pildă `20 sep 14:37`. Pe 8101 ștampila e lipită la construire; pe 8100, unde
paginile se servesc crude, în locul ei scrie `local`. Dacă pe 8101 vezi
`local`, pachetul e vechi: `node .claude/build-deploy.mjs`.

Numărul trebuie să fie **același** cu cel din numele folderului
`plasture-vps 2.3.1` și cu cel din `CITESTE-MA.md`. Dacă nu se potrivesc, nu
urca nimic: o versiune care minte face martorii de pe server de necrezut.

### 8.5 „Toate produsele" ajunge pe telefon

De aici încolo: `demo-manager`, pe 390, `/lab/laborator.html`.

Pe muchia din **dreapta** a ecranului stă o clapă înaltă, lipită de margine.
Scrie pe ea câte bucăți au mai rămas de făcut azi.

**Apeși clapa.** Trebuie să urce de jos foaia cu toate produsele zilei — aceeași
mișcare ca foaia de detaliu, nu alta. Clapa **dispare** cât e foaia deschisă.

Pe fiecare rând de produs: numele, `făcut / cerut`, dunga de progres, **o
casetă de număr și un buton „Adaugă"**. Tastatura care se deschide la atingerea
casetei trebuie să fie cea de **cifre**, nu cea cu litere.

**Nu trebuie să existe nicio bifă** pe rândul produsului. O bifă ar însemna
„gata tot", adică exact sărirea peste tastare pe care patronul a cerut să n-o
facem.

Scrii o cifră mică, de pildă 5, și apeși **Adaugă**.

**Trebuie să vezi:** `făcut` crescut cu 5, dunga mișcată, și, lângă rând, un
buton **„Înapoi"**. Îl apeși: totul se întoarce exact de unde a plecat. Apoi
alegi o comandă din listă — foaia cu produse trebuie să **se închidă singură**:
două foi de jos suprapuse înseamnă că una se închide fără să știe nimeni care.

### 8.6 Comenzile se trag cu degetul

Tot acolo, în lista de comenzi. Fiecare card are pe stânga un **mâner hașurat**,
îngust și înalt.

**Tragi de mâner** și muți un card peste altul. Ordinea trebuie să se schimbe
sub deget și să rămână schimbată când ridici degetul.

**Și cele două probe care contează cu adevărat:**

1. **Tragi de card, de oriunde în afară de mâner** — de nume, de cifre, de
   marginea lui. Cardul **nu trebuie să se miște**, iar lista trebuie să se
   **deruleze** normal sub deget. Dacă lista a înțepenit la atingere, cineva a
   mutat oprirea gestului de pe mâner pe card, și atunci panoul de laborator nu
   se mai poate derula deloc pe tabletă.
2. **Lași ordinea schimbată și deschizi aceeași zi pe alt ecran** — pe
   calculator, la 1440. Ordinea trebuie să fie **aceeași**: de runda asta o ține
   serverul, nu ecranul. Săgețile ↑ și ↓ de pe calculator scriu în același loc.

Mai ține degetul pe un card câteva clipe, fără să-l ridici, cât trece o tură de
împrospătare. Cardul **nu are voie să sară înapoi** de sub deget.

### 8.7 Laboratorul se oprește la „Predată curierului"

Pe `/lab/laborator.html`, iei o comandă și o muți din etapă în etapă cu butonul
mare.

**Trebuie să vezi:** butonul scrie, la penultima etapă, **„Predată curierului"**.
Îl apeși, și după el **se stinge**, cu lămurirea că șoferul pune ultima etapă de
pe telefonul lui. Laboratorul **nu mai poate ajunge** la etapa finală, nici din
card, nici din foaia de detaliu.

**Capcana care se uită:** pe un card ajuns la etapa finală, butonul **„Înapoi
la: …"** trebuie să fie tot acolo. Dacă a dispărut, laboratorul a rămas fără
singura cale de întors tocmai când șoferul apasă din greșeală.

**Apoi partea de pe server**, care e miezul lucrării. Intri cu `testangajat` pe
`/lab/ruta.html`, pe o comandă de livrat.

**Trebuie să vezi** butonul **„Marchează livrat" stins**, cu o lămurire care
spune ce lipsește, pe ce cont, și cine o pune: semnul „poate confirma livrarea",
bifat în Conturi.

Intri în `/lab/conturi.html` cu `testadmin`, pui bifa **„Poate confirma
livrarea"** pe contul acela, te întorci pe rută și reîmprospătezi. Butonul
trebuie să se **aprindă** și livrarea să treacă. Scoți bifa la loc, ca să nu
lași contul de probă cu drepturi în plus.

Oprirea din pagină singură n-ar fi ținut: contul `angajat` are **și** panoul de
laborator, **și** foaia de drum, deci același om ar fi apăsat pe cealaltă ușă.

---

## 9 · Runda 2.3.1: caseta din laborator, pastilele din Depozit, pastila mutată

*Trei minute. Cele patru bucăți de mai jos sunt lucrările rundei ăsteia, deci
singurele care n-au mai fost plimbate niciodată.*

Trei din patru se fac pe **telefon** (390). Trei din patru sunt defecte pe care
le-a găsit patronul singur, umblând prin aplicație — și niciunul din ele nu
dădea vreo eroare, ceea ce e chiar pricina pentru care sunt scrise aici: se
văd numai cu ochiul, pe ecranul potrivit.

### 9.1 Caseta de raportat mișcă cifra de lângă ea

`demo-manager`, pe 390, `/lab/laborator.html`. **Îți trebuie o zi cu o comandă
anulată** — ai una de la plimbarea a șaptea; dacă ai dus-o înapoi, anulează la
loc una de probă și lasă în zi și una vie, cu același produs.

Apeși clapa de pe muchia din dreapta și deschizi foaia cu toate produsele
zilei.

**Trebuie să vezi:** la produsul acela, `făcut / cerut` cere numai ce e de
făcut pentru comanda **vie**. Comanda anulată nu mai cere nimic. Până azi
aduna tot ce era în zi, fără nicio privire la stare.

Scrie `2` în caseta produsului și apasă **„Adaugă"**.

**Trebuie să vezi:** cifra din stânga **crește** cu doi, și dunga odată cu ea.
Ăsta e tot defectul, în două apăsări: până azi, pe o zi în care comenzile erau
anulate, cererea pleca, serverul răspundea, marfa intra pe raft — și cifra de
pe ecran nu se clintea, oricât ai fi tastat. Patronul a citit-o exact așa: „am
avut 15 produse de făcut, am introdus 10 și nu se schimbă nimic".

**Apasă și „Înapoi"**, ca să lași ziua cum ai găsit-o.

### 9.2 Cele patru pastile din Depozit filtrează, și se vede că sunt apăsate

Tot pe 390, `/lab/depozit.html`. Sus, sub titlu, stă o bandă care se trage
lateral: **„Sub prag minim", „Lipsă la comenzi", „Intrări azi", „Ieșiri azi"**,
fiecare cu cifra ei colorată. Le apeși pe rând.

**Trebuie să vezi, la fiecare, trei lucruri deodată:** lista de dedesubt se
**taie** la ce spune pastila; rândul „VEZI ACUM" de deasupra listei își schimbă
vorba și cifra (`Sub prag minim · 14 poziții`); iar pastila apăsată capătă un
**inel subțire în culoarea ei** — auriu la prag, roșu la lipsă, verde la
intrări, vișiniu la ieșiri.

Inelul e partea de privit anume, fiindcă el e jumătatea ascunsă a lucrării.
Până azi apăsatul se arăta numai printr-un fundal cu o idee mai închis —
măsurat, o deosebire de unu virgulă cincisprezece la unu, adică mult sub
pragul de la care ochiul deosebește două suprafețe. Lista chiar se schimba,
dar pastila arăta identic, și asta face un buton să pară stricat mai sigur
decât dacă n-ar face nimic.

**Apasă din nou aceeași pastilă:** se stinge, inelul piere, lista se face la
loc întreagă.

**Și încă una, care prinde nepotrivirea dinăuntru:** alege o categorie din
lista de categorii, apoi apasă o pastilă. Vederea trebuie să sară înapoi la
**„Toate"**. Cifra de pe pastilă e numărată pe tot depozitul, deci lăsată peste
o categorie deschisă ar fi arătat mai puține rânduri decât scrie pe ea.

### 9.3 Capul listei numără chiar lista de sub el

Tot în Depozit, cu pastilele stinse. Scrie **„făină"** în caseta de căutare.

**Trebuie să vezi:** cifra din rândul de deasupra listei e **cifra rândurilor
desenate**, nu numărul întregului depozit. Numără câteva rânduri dacă ai
răbdare, ori golește caseta și uită-te cum sare cifra înapoi.

Până azi scria `Tot stocul · 164 poziții` peste o listă de 128 de rânduri:
capul se socotea din tot depozitul, lista se filtra **și** după căutare. Cele
două socoteli stăteau la trei sute de rânduri depărtare **în fișier** — una la
desenarea listei, alta la scrierea capului —, destul cât nimeni să nu le vadă
pe amândouă odată.

Apasă apoi fila **„Registru"** și fă la fel cu pastila de intrări ori de
ieșiri: și acolo cifra trebuie să fie a mișcărilor rămase după filtru.

### 9.4 Pastila cu versiunea, în capul sertarului

Pe 390, în orice panou, cu **`demo-fondator`** — nu cu un cont de operații, și
merită spus de ce: sertarul crește cu rangul, iar fondatorul are cele mai multe
rânduri, deci e singurul cont care poate da defectul de gol. Măsurat cu contul
de operații, pagina ieșea curată de fiecare dată.

Apeși butonul cu trei linii.

**Trebuie să vezi:** pastila `ver. 2.3.1 · ` și ștampila stă **în capul foii**,
sub „Golden Pastry" și numele paginii — se vede din clipa în care se deschide
sertarul, fără nicio derulare. **Nu** la coadă, **nu** lipită de fundul foii.

**Derulează sertarul în sus și în jos.** Pastila trebuie să plece odată cu
lista, ca orice alt rând. Dacă rămâne pe loc, cu un dreptunghi închis peste
rândurile care trec pe dedesubt, s-a întors lipirea de runda trecută — ăsta e
chiar defectul reclamat: „se suprapune peste meniu".

Pe calculator, la 1440, pastila stă unde stătea: în colțul din stânga-jos al
barei laterale, sub „← Alege panoul".

---

## 10 · Telefon, tabletă, calculator

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

Pentru deget, pragul e **44 px pe înălțime**, și e ținut pentru butoanele
principale. În F12 se vede așa: alegi săgeata din colțul din stânga al
uneltelor și treci cu ea peste buton, iar eticheta scrie mărimea, de pildă
`button.st-capbtn 107.33 × 44` — al doilea număr e înălțimea. Pe 390 uită-te
întâi la locurile care au fost sub prag până la 2.1.1:

- în Statistici, **capetele de coloană** care sortează mesele (aveau 18 px) și
  **rândurile din legenda inelului** (30 px);
- pe rută (`/lab/ruta.html`), **„← Alege panoul" din subsol** (21 px), lângă
  „Resetează bifele";
- **„← Alege panoul" de pe ecranul de refuz** din rută, comenzi, conturi și
  laborator (26 px, chiar sub „Ieși din cont"). Ecranul îl vezi intrând cu un
  cont care n-are panoul, de pildă `testangajat` pe `/lab/conturi.html`.

Mai sunt ținte între 30 și 43 px, și se știu — vezi „Ce NU e defect aici".

Tabelele late (raportul de pontaj, lista de comenzi) au voie să se **deruleze în
cutia lor**. Ce n-au voie e să miște pagina întreagă.

**Statisticile se probează la toate trei vederile** (cele de la 6.1), nu doar la
una: e aceeași pagină croită de trei ori, deci sunt trei așezăminte de verificat
la fiecare lățime. Inelul are voie să se micșoreze, dar nu să-și taie scrisul de pe felii.

---

## Ce NU e defect aici

Ca să nu cauți ce nu s-a stricat:

- **Săriturile între subdomenii te duc pe site-ul adevărat.** „Comanda mea" din
  panou, „Intră în cont" și „Intră în magazin" — în pachet sunt adrese de
  producție, și așa trebuie. Local te întorci tastând adresa din tabelul de sus.
  **Sertarul panoului, în schimb, trebuie să meargă apăsat.** Și aterizarea
  clientului după parolă: ea NU e o săritură — cade pe `/clienti/client`, pe
  aceeași gazdă, deci local funcționează.
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
- **Pe 390 mai sunt ținte între 30 și 43 px, și se știu.** Pragul de 44 px e
  ținut pentru butoanele principale. O listă cunoscută, de vreo treizeci de
  ținte mai mici, măsurată în runda 2.1.1, rămâne pentru o rundă următoare:
  chipurile și scurtăturile din Statistici (40–42 px), butonul **„Ieși"** de
  lângă numele tău din sertar (40 px), bifa din Recomandări (30 px), butoanele
  mici din Produse și Setări (32 px) și câteva casete de 38–40 px din Depozit,
  Conturi și Comenzi. Tot de acolo: **pe tabletă (820) capetele de coloană din
  Statistici au încă 18 px** — banda de tabletă n-a intrat în 2.1.1. Nu le
  scrie ca defecte noi; un buton principal sub 44 px pe 390, da.

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

Când toate cele zece ies, proba automată se mai trece o dată pe deasupra:

```bash
node .claude/verifica-tot.mjs
```

Trebuie să iasă **0 verificări au eșuat**. Abia atunci urcă plasturele pe server,
după `CITESTE-MA.md` din folderul `plasture-vps 2.3.1`.
