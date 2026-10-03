# Trecerea cu ochii, înainte de publicare

O listă scurtă: **ce apeși** și **ce trebuie să vezi**. Nu e un ghid de folosire
— e proba de dinaintea publicării, pe pachetul construit, cel care pleacă pe
server. Dacă toate rândurile de mai jos ies cum scrie aici, plasturele poate
urca.

Durează **o sută patruzeci de minute** cu totul. Nu trebuie făcută dintr-o
bucată: fiecare din cele optsprezece plimbări stă singură și își spune la
început cât ține. Cifra de sus e suma minutelor scrise acolo, plus cele ale
bucăților care au minutele lor: 4.4, 7.7 și 7.8 — dacă se mai adaugă o
plimbare, se adună și aici.

Cele optsprezece, în ordinea în care se fac cel mai ușor:

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
   telefon: produsele (8.5), tragerea (8.6) și oprirea la „Gata de
   livrare" (8.7).
9. **Runda 2.3.1** — caseta de raportat care mișcă cifra (9.1), cele patru
   pastile din Depozit (9.2), capul listei care numără ce se vede (9.3) și
   pastila cu versiunea, mutată în capul sertarului (9.4).
10. **Runda 2.4.0** — comutatorul cu trei poziții (10.1), latura stinsă cu
    prețul rămas în casetă (10.2), cele două poze (10.3), coșul peste hotarul
    laturilor (10.4), curățenia care nu mai mănâncă poza coaptă (10.5) și
    pagina produsului cu ce pleacă spre Google (10.6).
11. **Runda 2.4.1** — fiecare latură numai cu poza ei (11.1), poza schimbată
    la o latură care n-o mai clintește pe cealaltă (11.2) și vitrina neatinsă
    de migrarea care a tăiat împrumutul (11.3).
12. **Runda 2.4.2** — poza mare de pe telefon, micșorată pe server (12.1),
    poza deja mică lăsată neatinsă, ca să rămână dreaptă (12.2), și pagina de
    verificare, care spune ce server web ai (12.3).
13. **Runda 2.5.0** — semnele celor două cataloage (13.1), cele trei prețuri
    în formular (13.2), scara și îndemnul de pe card (13.3), coșul care trece
    de 100 (13.4), comanda corectată peste trepte (13.5), vederile
    magazinului și cea de pornire din Setări (13.6), filtrele magazinului
    (13.7), vederile și filtrele din Catalog (13.8) și conturile, pe plăci
    (13.9).
14. **Runda 2.5.1** — numărul unui produs nou (14.1), un număr liber și unul
    greșit (14.2), un număr ocupat, cu produsele care se dau cu un loc (14.3),
    întoarcerea la numărul de la început (14.4) și numele nou al laturii
    coapte, „Coapte", peste tot unde se vede (14.5).
15. **Runda 2.5.2** — „Patru" mare și „Șase" ca vechiul „Patru", pe
    calculator (15.1), bulele de pe telefon (15.2) și de pe tabletă (15.3),
    antetul pe un calculator mai mic (15.4) și `Tab` prin cantitate (15.5).
16. **Runda 2.6.0 în birou** — comanda nouă fără „Stare" și fără
    „Referință" (16.1), fereastra „Din catalog" cu Congelate și Coapte
    (16.2), cererea agentului (16.3), anunțul care spune cine a scris și al
    cui e clientul (16.4), fișa tehnică în magazin și în Catalog (16.5) și
    refuzul din Setări (16.6).
17. **Runda 2.6.0 pe drum** — bifele puse cât marfa e încă în lucru (17.1),
    laboratorul oprit la „Gata de livrare" (17.2), „Marchează livrat",
    „Resetează bifele" și comanda care se face gri (17.3), livrarea pusă din
    „Comenzi" și ridicarea închisă de birou (17.4).
18. **Telefon, tabletă, calculator** — 390, 820, 1440, și degetele de 44 px.

A optsprezecea se face la capăt dinadins: prinde într-o singură trecere tot ce
ai deschis mai devreme.

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

**Apeși:** `testadmin` → `/lab/conturi.html` → apeși placa lui **Angajat
Test**, ca să i se deschidă fișa → pe rândul **„Cod de pontaj"** apeși
**„Pune un cod"** → în caseta **„Codul nou"** scrii patru cifre →
**„Salvează codul"**.

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

**Pe ceas, de la runda 2.6.0.** Cât tura e încă deschisă, apasă pe ceas, sus,
pe numele omului. **Trebuie să vezi** nu tastatura, ci pasul **„Tură nepontată
la plecare"**, cu numele lui. Ca administrator, dacă raportul de jos cuprinde
ziua turei, scrie *„Tura rămasă deschisă dintr-o zi trecută se încheie de mână.
Până atunci, omul nu poate ponta."*, cu butoanele **„Scrie plecarea"** (deschide
aceeași fereastră ca rândul din raport) și **„Înapoi la listă"**; dacă nu o
cuprinde, rândul îți spune să muți „De la" mai în urmă ori să alegi „Toată
lumea", și butonul „Scrie plecarea" lipsește. Cu `testangajat`, același om dă
*„Ai o tură rămasă deschisă dintr-o zi trecută. Spune-i unui administrator s-o
încheie; până atunci nu poți ponta."* și numai „Înapoi la listă". Până la
runda 2.6.0, omul primea tastatura, iar serverul îi refuza orice cod.

### 2.5 Iei codul înapoi

**Apeși:** `/lab/conturi.html` → placa lui Angajat Test, ca să i se deschidă
fișa → **„Ia-i codul"**.

**Trebuie să vezi:** butonul se face roșu și scrie **„Apasă din nou: îi iau
codul"**. Abia a doua apăsare ia codul. Eticheta se face la loc **„nepus"** —
iar pe `/lab/pontaj.html` omul dispare din lista ceasului.

> **Codul nu se vede niciodată nicăieri.** Nici pe placă, nici pe fișă, nici
> în Jurnal, nici într-un răspuns al serverului. Pagina știe doar dacă un om
> ARE sau N-ARE cod. Dacă vezi vreodată patru cifre scrise undeva, ăla e un
> defect grav — scrie-mi unde.

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
pe cererea ta, **„Acceptă și fă comanda"**. Se deschide formularul, completat
din cerere (clientul, ziua, intervalul, adresa și zona de departe); nu schimbi
nimic și apeși, jos, **„Acceptă cererea"**. Comanda se naște abia acum: sus
scrie *„Cerere acceptată → #… · numele tău."*, iar formularul se închide. Apoi
`/lab/ruta.html`, pe ziua ei.

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

**Desfaci:** comanda de probă e una adevărată, în registru și pe rută. În
`/lab/comenzi.html`, pe rândul ei, **„Vezi produsele"** → la **„Etapa"** alegi
**„Anulată"** — merge direct, fără casetă (7.1). Iese de pe rută și din
laborator, dar rămâne în registru, cu „Anulată" pe ea. Contul făcut la 4.1 nu
mai trebuie nimănui: `/lab/conturi.html` → placa lui → în fișă, **„Șterge
contul"**, apoi încă o dată, pe **„Apasă din nou: șterg definitiv contul"**.
Comanda și cererea lui rămân în registru, doar că fără cont legat de ele.

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

Tot cu `testadmin`, alege acum din fișă **„Predată"**: rândul spune *„Ziua
comenzii e în trecut. Trecută în «Predată», rămâne predată pe ziua aceea: e
bine numai dacă atunci a fost livrată…"*. Apeși **„Lasă-o anulată"**.

Ieși, intră cu **`demo-manager`** și alege din nou o etapă din flux, din fișa
aceleiași comenzi. Rândul galben **se încheie la fel ca la administrator**:
din 19 septembrie 2026 managerul corectează comenzi și are câmpul „Zi", deci
își mută singur ziua. Celălalt sfârșit, *„Ziua se mută dintr-un cont de
administrator sau de manager: cere-i s-o mute, apoi refă comanda."*, îl citesc
numai conturile care din fișă schimbă doar etapa și n-au câmpul „Zi". Apeși
**„Lasă-o anulată"**.

Dacă în lista **„Etapa"** a managerului lipsește **„Predată"**, contul lui n-are
semnul „Poate confirma livrarea", iar sub listă scrie de ce: *„«Predată» o pune
un administrator ori un cont cu bifa «Poate confirma livrarea», din
«Conturi»."* Nu e un defect: e paza de la 8.7.

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
**„Comandă livrată"** → **„Salvează"**. Notează-ți cum erau bifele când ai
venit: la capăt le pui la loc.

**Trebuie să vezi:** sub „Comandă anulată", ajutorul spune și *„Pe aceleași
canale pleacă și «Comandă refăcută», dacă o aduci înapoi."* Un rând **„Comandă
refăcută"** **nu** există: n-are bifă a lui, dinadins.

**Apeși:** `/lab/comenzi.html` → pe o comandă de azi sau de mâine, din fișă,
**„Anulată"**, apoi **„La ambalare"** → **„Reface comanda"**. Deschizi Mailpit.

**Trebuie să vezi** două mesaje noi cu aceeași referință: **„Comandă anulată
#…"** și, deasupra lui, **„Comandă refăcută #…"**. În corpul celui de sus scrie
*„Etapă: Anulată → La ambalare"*. Dacă lipsește „Comandă refăcută", pe server a
rămas API-ul vechi.

**Apeși:** în Setări bifezi **E-mail** și pe **„Comandă livrată"**, salvezi, apoi
din fișa aceleiași comenzi **„Anulată"** → **„Predată"** → **„Reface comanda"**.

**Trebuie să vezi** trei mesaje noi. Citite de jos în sus, în ordinea în care au
plecat: **„Comandă anulată"**, **„Comandă refăcută"**, **„Comandă livrată"**. Două
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
`testangajat` — rolul „angajat" are panoul „Rută". Contului îi pui întâi, din
Conturi, semnul **„Poate confirma livrarea"** (ca la 8.7): fără el, „Marchează
livrat" nici nu se desenează. La capăt îl scoți.

Alegi o comandă de livrare de azi care **nu e livrată** și are cel puțin două
opriri înaintea ei, ca ecranul să fie derulat, ca al lui. De la runda 2.6.0
butonul se aprinde numai pe o comandă gata de plecare și încărcată, deci îi mai
trebuie două lucruri:

- să fie în **„Gata de livrare"**. Dacă nu e, ține minte etapa ei și adu-o
  acolo din `/lab/laborator.html`, cu butonul mare de pe cardul ei, până scrie
  pe el **„Așteaptă șoferul"** (8.7);
- să aibă **totul bifat**. În fila B îi deschizi cartonul și atingi, una câte
  una, pozițiile de sub **„Bifează ce încarci"**. Butonul se aprinde abia după
  ultima.

**Apeși:** în fila B, cartonul ei rămâne **deschis**, cu „Marchează livrat"
aprins — la buton se ajunge numai prin carton. Deschide-l ACUM, nu după: cele
cincisprezece secunde le vrei întregi pentru apăsare, iar deschiderea unui carton
șterge și mesajul de pe ecran.

**Apeși:** treci în fila A → pe aceeași comandă, din fișă, la **„Etapa"** alegi
**„Anulată"** (merge direct, fără casetă — 7.1). Te întorci pe loc în fila B și
te uiți întâi dacă **cartonul mai e pe listă**. El e ceasul tău: ruta arată numai
comenzile vii, deci cât timp cartonul e acolo, lista din telefon e cea veche.

- **e tot acolo, deschis** → ecranul e rămas în urmă, exact ca în buzunar. Apeși.
- **a pierit** → tura de cincisprezece secunde a apucat să treacă și lista s-a pus
  la zi. Refaci comanda din fila A — la „Etapa" alegi **„Gata de livrare"** →
  caseta **„Refaci comanda #…?"** → **„Reface comanda"** — și încerci din nou,
  mai repede. Bifele nu se pierd: pozițiile comenzii au rămas aceleași, deci
  cartonul se întoarce tot bifat. Nu-ți trebuie să apeși pe o listă pusă la zi:
  acolo nu mai e nimic de probat.

**Apeși:** în fila B, **„Marchează livrat"**.

**Trebuie să vezi**, toate cinci:

1. **Mesajul e roșu, și stă în antet.** Sus, în antetul care rămâne lipit de
   marginea ecranului, o casetă roșie cu **×** în dreapta: *„Comanda #… nu mai e
   pe ruta zilei: a fost anulată ori mutată din birou. N-am marcat-o livrată."*
   E singurul loc din toată reparația unde vestea e roșie, nu neutră. Ca să ai
   cu ce compara, marchezi pe urmă o livrare care merge (mai jos): vestea bună
   e verde, n-are **×** și stă **sub** antet, nu în el. Dacă și refuzul iese
   verde, culoarea nu se mai uită la ce s-a întâmplat.
2. **Încape pe 390.** Se rupe în rânduri întregi, nu taie niciun cuvânt, nu iese
   din chenar și nu împinge pagina în lateral.
3. **Îl vezi fără să derulezi?** Tu ai apăsat la a treia oprire, cu pagina
   derulată, iar mesajul stă în antetul lipit sus, deci e pe ecran la orice
   derulare. Dacă a trebuit să urci ca să-l găsești, **scrie-o ca defect**: pe
   un telefon scos din buzunar, un mesaj pe care nu-l vezi e un mesaj care n-a
   fost dat.
4. **Cartonul nu se face verde: piere.** La apăsare butonul se stinge și scrie
   **„Se verifică comanda…"**: telefonul recitește ruta înainte să trimită ceva.
   Comanda nu mai e pe ea, deci nu pleacă nimic la server, iar oprirea **nu mai
   e pe listă**: ruta duce comenzile vii, iar una anulată n-are ce căuta în
   mașină. Dacă era o oprire numerotată, cele de după ea se trag cu una mai sus,
   iar numărătoarea din capul paginii scade cu o livrare. Dacă vezi cartonul
   trecând, fie și o clipă, pe livrat — bulină **✓**, antet verde —, ăla e un
   defect: așa făcea până la runda 2.6.0, ca și când livrarea ar fi mers.
5. **Mesajul rămâne peste dispariție.** Aici e tot rostul bucății: oprirea îi
   piere de sub deget tocmai cât citește, iar dacă ar pleca și mesajul odată cu
   ea, șoferul ar rămâne cu o listă mai scurtă cu una și fără nicio vorbă despre
   ce s-a întâmplat — exact felul în care se nasc telefoanele în laborator. Nu se
   stinge singur, nici după ce trece o tură de cincisprezece secunde peste el:
   pleacă abia când șoferul apasă **×**, atinge un carton sau schimbă ziua.

**Apeși:** `/lab/jurnal.html`, filtrul de zonă **„Comenzi"**, cauți referința
comenzii.

**Trebuie să vezi** rândul anulării de adineauri și **niciun** rând scris la ora
apăsării de pe telefon. Un `order_update · Comandă #…: Anulată → Predată` pus la
ora aia ar însemna că livrarea a trecut totuși și că mesajul roșu a mințit — iar
pe deasupra, că din „Anulată" s-a ieșit, deci pe canalele din Setări a plecat un
„Comandă refăcută" (7.5) pentru o comandă pe care patronul tocmai o oprise.
De la runda 2.6.0 telefonul nici nu mai trimite cererea, dar paza de pe server
a rămas: refuză sub lacăt, înainte de orice scriere. De-aia Jurnalul e locul
unde s-ar vedea minciuna.

**Apeși**, ca să ai cu ce compara: în fila A refaci comanda pe **„Gata de
livrare"** (la „Etapa" → caseta → **„Reface comanda"**). În fila B, în cel mult
cincisprezece secunde, cartonul se întoarce, tot bifat. Îl deschizi și apeși
**„Marchează livrat"**.

**Trebuie să vezi** cartonul strâns, cenușiu, cu antet verde și **✓** în
bulină, iar sub antet, pe verde: *„<client> · #… marcat livrat. Laboratorul
vede «Predată»."* Deschizi iar cartonul și apeși **„Anulează livrarea"**: *„<client>
— livrarea a fost anulată."*, iar comanda e iar în „Gata de livrare".

La capăt, pune comanda înapoi în etapa în care o găsiseși: din fila A, la
„Etapa", direct dacă era mai devreme în flux. Apoi, în fila B, scoate-i bifele
atingând încă o dată fiecare poziție, pune fila B înapoi pe lățimea ei și, dacă
ai lucrat cu `testangajat`, scoate-i din Conturi semnul „Poate confirma
livrarea".

---

## 8 · Runda 2.3.0: comutatorul, bara, harta, versiunea, laboratorul pe telefon

*Nouă minute. Cele șapte bucăți de mai jos sunt lucrările rundei ăsteia, deci
singurele care n-au mai fost plimbate niciodată.*

Primele patru se fac în magazin, fără cont. Ultimele trei se fac cu
`demo-manager`, pe telefon (390), în panoul de laborator; a șaptea mai cere, la
capăt, `testangajat` pe rută și `testadmin` în Conturi.

### 8.1 Comutatorul congelate ↔ coapte

Magazinul pe telefon (390), `http://127.0.0.1:8101/catalog`. Derulezi până
trec uneltele de deasupra produselor: de acolo, sub antet, la mijloc, stă
pastila cu două semne — fulgul și flacăra. Apeși pe fiecare, pe rând, de
vreo cinci ori, repede.

**Trebuie să vezi:** un jeton rotund care **alunecă** de sub un semn sub
celălalt, cu un arc scurt la capăt. Nu sare, nu clipește, nu apare de nicăieri.
Sub pastilă iese o clipă o vorbă — „Congelate" ori „Coapte" — scrisă
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

> S-a schimbat în 2.5.0: cât uneltele sunt pe ecran, pastila stă ascunsă —
> acolo comutatorul e în bara de unelte, cu semne și cu vorbe, iar două
> comutatoare deodată ar fi fost unul de prisos. Al doilea semn era un
> cuptor; acum e o flacără (13.1).

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

**Trebuie să scrie** `ver. 2.6.0 · ` și o ștampilă — ziua și ora pachetului, de
pildă `20 sep 14:37`. Pe 8101 ștampila e lipită la construire; pe 8100, unde
paginile se servesc crude, în locul ei scrie `local`. Dacă pe 8101 vezi
`local`, pachetul e vechi: `node .claude/build-deploy.mjs`.

Numărul trebuie să fie **același** cu cel din numele folderului
`plasture-vps 2.6.0` și cu cel din `CITESTE-MA.md`. Dacă nu se potrivesc, nu
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

### 8.7 Laboratorul se oprește la „Gata de livrare"

Pe `/lab/laborator.html`, iei o comandă de livrare de azi și o muți din etapă
în etapă cu butonul mare. Ține minte etapa în care ai găsit-o.

**Trebuie să vezi:** pe etapa dinaintea ei, butonul scrie **„Gata de
livrare"**. Îl apeși, și după el **se stinge** și scrie **„Așteaptă șoferul"**
(pe o ridicare: **„Așteaptă clientul"**). Deschizi foaia de detaliu a comenzii:
butonul stins scrie acolo **„Gata — o ia șoferul"**, iar dedesubt stă
lămurirea: *„Comanda e gata de plecare. «Predată» o pune șoferul, de pe «Rută»,
după ce bifează ce a încărcat — ori biroul, din «Comenzi»."* (Pe calculator, a
doua oară: ținând mausul pe butonul cardului.) Laboratorul **nu mai poate
ajunge** la etapa finală, nici din card, nici din foaia de detaliu. Până la
runda 2.6.0, butonul stins scria aici „Predată curierului", deși marfa încă nu
plecase.

**Capcana care se uită:** pe un card ajuns la etapa finală, butonul stins scrie
**„Finalizată"**, iar lângă el săgeata **„←"** trebuie să fie tot acolo; în
foaia de detaliu, butonul **„Înapoi la: Gata de livrare"**. Dacă a dispărut,
laboratorul a rămas fără singura cale de întors tocmai când șoferul apasă din
greșeală. Dacă azi nu e nicio comandă livrată, o vezi la 17.3.

**Apoi partea de pe server**, care e miezul lucrării. Intri cu `testangajat` pe
`/lab/ruta.html`, într-o fereastră privată, și deschizi cartonul comenzii pe
care tocmai ai adus-o la „Gata de livrare".

**Trebuie să vezi** că butonul **„Marchează livrat" lipsește cu totul** — nu
stins, ci nedesenat —, iar în locul lui o lămurire care spune ce lipsește, pe ce
cont, și cine o pune: *„Contul ăsta n-are semnul «poate confirma livrarea»,
deci nu poate pune etapa «Predată». Se bifează în «Conturi», pe contul
șoferului; după aceea butonul apare aici singur, fără reîncărcare."*

**Apeși:** în fereastra obișnuită ieși din `demo-manager` și intri cu
`testadmin` (managerul n-are pagina „Conturi") → `/lab/conturi.html`
→ placa lui `testangajat` → în fișa lui, comutatorul **„Poate confirma
livrarea"**. Sus
scrie *„«…» poate confirma livrarea."*, cu numele contului în loc de puncte,
cum stă pe placă.

**Trebuie să vezi** pe rută, în cel mult cincisprezece secunde și fără
reîncărcare, butonul **„Marchează livrat"** apărut — stins până bifezi tot ce
încarci (17.1). Nu-l apăsa aici: livrarea întreagă o faci la 17.3.

**Desfaci:** în Conturi muți comutatorul înapoi — sus scrie *„«…» nu mai poate
confirma livrarea."* —, ca să nu lași contul de probă cu drepturi în plus. Pe
`/lab/laborator.html`, cu săgeata **„←"**, aduci comanda înapoi în etapa în care
o găsiseși.

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

**Trebuie să vezi:** pastila `ver. 2.6.0 · ` și ștampila stă **în capul foii**,
sub „Golden Pastry" și numele paginii — se vede din clipa în care se deschide
sertarul, fără nicio derulare. **Nu** la coadă, **nu** lipită de fundul foii.

**Derulează sertarul în sus și în jos.** Pastila trebuie să plece odată cu
lista, ca orice alt rând. Dacă rămâne pe loc, cu un dreptunghi închis peste
rândurile care trec pe dedesubt, s-a întors lipirea de runda trecută — ăsta e
chiar defectul reclamat: „se suprapune peste meniu".

Pe calculator, la 1440, pastila stă unde stătea: în colțul din stânga-jos al
barei laterale, sub „← Alege panoul".

---

## 10 · Runda 2.4.0: două cataloage, o vitrină

*Șase minute. Cele șase bucăți de mai jos sunt lucrarea rundei ăsteia, deci
singurele care n-au mai fost plimbate niciodată.*

Plimbarea asta se face **pe pachetul construit** (8101), nu pe surse: patru din
șase bucăți ating pagina produsului sau ce pleacă spre Google, iar acelea nu
există pe 8100 — adresa `/produs/<nume>` e o rută a pachetului, nu a serverului
de surse.

Înainte de orice, ai nevoie de un produs **cu amândouă prețurile scrise**. Nu
de unul fără preț: tot ce se plimbă mai jos se vede numai pe forma în care
prețul există și bifa e stinsă — cealaltă formă e cea de ieri și n-are nimic
nou de arătat.

### 10.1 Comutatorul cu trei poziții, și de ce se deschide pe „Amândouă"

`demo-fondator`, pe 1440, panoul **Catalog**. Deasupra listei stă o bandă cu
trei poziții: **„Congelate | Coapte | Amândouă"**.

**Trebuie să vezi:** la deschiderea paginii e aprins **„Amândouă"**, iar lista
arată exact ce arăta înainte de runda asta — toate produsele, în ordinea lor.
Asta e și pricina pentru care lucrarea n-a ieșit o a treia filă: două cataloage
care se oglindesc se citesc comparând treizeci de rânduri, nu editând un
produs, iar o filă a treia le-ar fi făcut imposibil de văzut deodată.

Apeși **„Congelate"**. Lista se taie la ce se vinde congelat. Apeși
**„Coapte"**: se taie la cealaltă parte, și acolo trebuie să fie **mai multe**
rânduri, fiindcă acolo se pun produsele care n-au corespondent congelat.

**Uită-te și la grupe:** o grupă rămasă fără niciun produs pe latura aleasă
**nu piere** din panou — o vezi goală, cu titlul ei. În panou trebuie s-o vezi
ca să ai unde pune ceva în ea. **Pe site e pe dos**, și asta se plimbă la 10.4.

### 10.2 Stingi o latură, și prețul rămâne în casetă

Tot în Catalog, pe „Amândouă". Alegi un produs cu amândouă prețurile și
deschizi formularul.

**Trebuie să vezi:** două panouri, **„Congelate"** și **„Coapte"**,
fiecare cu semnul lui, cu comutatorul **„În catalog"** și cu prețurile lui.
Vorbele „HoReCa" și „raft" nu mai există nicăieri în casă; dacă le mai vezi
undeva, pachetul e mai vechi decât crezi.

> S-a schimbat în 2.5.0: până atunci erau patru casete în grilă, sub „Preț
> congelat" și „Preț copt". Fiecare panou are acum trei prețuri,
> A, B și C; aici contează numai A, iar celelalte se plimbă la 13.2.

În panoul **„Congelate"** stingi comutatorul **„În catalog"** și salvezi.

**Trebuie să vezi trei lucruri:**

1. Salvarea trece — **nu** te refuză nimic.
2. **Prețul congelat e tot acolo**, scris, nu șters. Caseta rămâne văzută, nu
   ascunsă: ascunsă, ar fi ascuns tocmai ce ți s-a promis că se păstrează.
3. Pe rândul din listă, pastila **„congelate"** se stinge (cenușie), iar
   **„coapte"** rămâne aprinsă — nu eticheta roșie de până acum.
   Roșu înseamnă de-acum „nu e în niciun catalog" ori „e în catalog, fără
   prețul lui" — stări stricate. Un produs dintr-o singură parte e
   **legiuit**, deci n-are ce căuta în roșu.

**Încearcă și ce trebuie să fie refuzat**, ca să vezi că paza există:

- aprinzi o latură și lași caseta ei goală → **te refuză**, iar mesajul spune
  care catalog și care casetă;
- stingi **amândouă** comutatoarele → **te refuză**, și te trimite la butonul
  de scos din catalog. „În niciun catalog" și „scos din catalog" ar fi fost
  două nume pentru aceeași stare, iar două nume pentru o stare sunt chiar
  felul în care se nasc încurcăturile.

### 10.3 Două poze pentru același produs

Tot pe produsul de la 10.2. În formular sunt acum **două casete de fișier**:
cea de până acum și **„Fotografie — copt"**.

Urci o poză anume pentru coapte — una care se deosebește la prima
vedere de cea congelată — și salvezi.

**Trebuie să vezi:** previzualizarea din formular arată poza **rezolvată**,
adică exact ce vede cumpărătorul pe latura aleasă, nu calea scrisă în bază.

Treci pe site (`/`), pe magazin. Comuți **congelate ↔ coapte**.

**Trebuie să vezi:** poza de pe cartonaș **se schimbă odată cu fila**.

Aici era, în runda 2.4.0, o a doua așteptare: un produs cu o singură poză o
arăta pe aceea pe amândouă laturile, ca să nu apară gol în ziua dintre cele
două urcări. **S-a răsturnat în 2.4.1** — se plimbă la 11.1 — fiindcă purtarea
aceea nu se poate deosebi, din afară, de o poză scrisă în amândouă coloanele.

**Folosește o poză adevărată de telefon, nu una micșorată dinainte.** Aici e
lucrarea de la urmă a rundei, și e chiar pricina pentru care s-a făcut: până
azi serverul primea `2 MB`, deși sub caseta de fișier scrie „maximum 5 MB".
Cum un telefon de azi face poze de 3-6 MB, cădeau aproape toate, cu „Fișierul
e mai mare decât acceptă serverul" și fără nicio cifră. Acum serverul
primește 6 MB.

**Trebuie să vezi:** poza de 3-4 MB **intră**, fără niciun mesaj. Dacă totuși
îți spune că e prea mare, citește mesajul: de acum îți spune și **cât**
primește serverul, și **unde** se ridică. Dacă cifra aceea e sub 5 MB,
folderul `api/` n-a ajuns pe server — de reparat e urcarea, nu poza. Rândul
„Limita de mărime pentru fotografii" din `api/verifica.php` spune același
lucru, fără să mai încerci o urcare.

Și o dată în sens invers, dacă ai la îndemână o poză mare de tot:

- **peste 6 MB** — „Fotografia depășește 5 MB. Micșoreaz-o și încearcă din
  nou." Regula casei, cu vorba ei.
- **peste 8 MB** — „Poza n-a ajuns deloc la server: cererea avea …". Acolo
  PHP aruncă tot corpul cererii înainte să ruleze un rând de-al nostru, deci
  mesajul e altul dinadins.

Ce **nu** are voie să scrie, în niciunul din cele două cazuri, e „Nu ai ales
niciun fișier". Ăla era defectul-pereche găsit runda asta: omul tocmai
alesese un fișier, și era trimis să caute unde nu era nimic.

### 10.4 Coșul: rândul care rămâne, și rândul care trebuie să plece

Pe magazin, fără cont. Pui în coș produsul de la 10.2 **pe partea de
coapte**, apoi comuți fila pe congelate și înapoi.

**Trebuie să vezi:** rândul din coș **nu piere**, și nici banii de pe el.
Coșul poate ține legiuit același produs în amândouă variantele, iar fila de sus
se comută peste el.

**Și partea cealaltă, care e defectul rundei** — merită făcută anume, fiindcă
nu se vede din nimic altceva:

1. Cu fila pe **congelate**, pui în coș un produs care se vinde în amândouă.
2. Te duci în panou și **stingi latura congelată** a acelui produs (prețul
   rămâne scris, ca la 10.2).
3. Te întorci pe site și **reîmprospătezi pagina**.

**Trebuie să vezi:** rândul acela **a ieșit din coș**, iar totalul a scăzut cu
el. Dacă e încă acolo, cu un preț care arată cum trebuie, ăsta e chiar defectul
reparat: prețul congelat rămâne scris, deci rândul nu se face gol și nu se
colorează — arată exact ca unul sănătos, iar serverul l-ar fi refuzat abia
după ce clientul completa tot formularul.

**Uită-te și la grupe, pe site:** o grupă rămasă fără produse pe latura
deschisă **se ascunde singură** — pe dos față de panou (10.1). Un raft gol pus
la vedere e o promisiune pe care magazinul n-o ține. Dar dacă intri **de-a
dreptul** pe adresa grupei aceleia (`/catalog/<grupa>`), trebuie să vezi grupa,
o vorbă care spune de ce e goală **pe latura asta**, și comutatorul — nu o
pagină albă.

### 10.5 Curățenia nu mai mănâncă poza laturii coapte

În terminal, în dosarul proiectului:

```bash
node .claude/curata-probe.mjs
```

**Trebuie să vezi, după ce se termină:** **amândouă** pozele produsului de la
10.3 sunt încă pe disc, în `products/`, **și** în `deploy/public_html/products/`.

Bucata asta e scurtă și e cea mai importantă din toată plimbarea. Curățenia
șterge de pe disc — și din fiecare copie din `deploy/` — orice fotografie
urcată pe care n-o mai pomenește nicio coloană de poză, tăcut, cu un rând de
izbândă tipărit. Până la runda asta întreba **o singură coloană**, scrisă cu
mâna. O poză legată numai de latura coaptă ar fi fost socotită fără stăpân și
ștearsă la prima curățenie — peste pozele adevărate ale patronului, nu peste
fișiere de probă.

### 10.6 Pagina produsului, și ce pleacă spre Google

Pe 8101, deschizi `/produs/<numele-produsului>` al produsului de la 10.2 — cel
care se vinde acum **numai copt**.

**Trebuie să vezi, pe ecran:**

- butonul își **numește latura**: „Adaugă — coapte", nu doar „Adaugă";
- caseta laturii pe care nu se vinde **nu mai există**: pe un produs de pe o
  singură latură vezi **două** căsuțe, nu trei, iar cele două rămase se lățesc
  ca să umple rândul — fără gol la dreapta. Așa a cerut-o patronul, și din
  amândouă ecranele: și din fereastra de vedere rapidă, și de aici. Până în
  2.4.0 scria „Nu se vinde", o propoziție cât o căsuță de preț; iar „preț la
  cerere" — ce-ar fi ieșit dacă se lăsa cifra goală — s-ar fi citit ca o
  ofertă, și omul ar fi cerut un preț care nu există;
- pagina **n-are comutator**, dinadins: ea arată marfa unde se vinde, nu te
  pune să alegi.

**Și ce nu se vede pe ecran** — apeși `Ctrl+U` (codul paginii) și cauți
`"price"`:

**Trebuie să vezi prețul laturii pe care marfa chiar se vinde** — cel de la
coapte —, nu prețul congelat rămas scris în casetă. Asta e defectul cel mai
scump al rundei și singurul care nu se vede din niciun ecran: panoul arată
prețul bun, magazinul arată prețul bun, și numai ce pleacă spre Google era
altceva. Tot acolo, în cap, titlul trebuie să spună **„din cuptorul
nostru"**, nu „livrare congelată".

Deschizi și `/sitemap.xml`: produsul trebuie să fie **înăuntru**. Un produs
vândut undeva are o adresă publică, oricare ar fi latura lui.

---

## 11 · Runda 2.4.1: fiecare latură cu poza ei

*Trei minute. Cele trei bucăți de mai jos sunt lucrarea rundei ăsteia, deci
singurele care n-au mai fost plimbate niciodată.*

Runda asta n-a adăugat niciun ecran: a **tăiat** o purtare. Până acum o latură
fără poză a ei o arăta pe a celeilalte, ca produsul să nu apară gol pe site în
ziua dintre cele două urcări. Ideea era bună și scrisă în plan — dar din afară
nu se poate deosebi de o poză scrisă în amândouă coloanele, și chiar așa s-a
citit, în prima zi de folosință: *„Când schimb poza la congelate se schimbă și
la gata de servire."* (Așa se chema atunci latura coaptă.)

Se plimbă **pe pachetul construit** (8101), ca toată runda 2.4.0.

Îți trebuie un produs **nou**, făcut acum, ca să nu fi trecut prin migrarea
`027` — aceea a umplut casetele produselor vechi, și tocmai de-aia ele nu pot
arăta ce se plimbă la 11.1.

### 11.1 O latură fără poza ei arată substituentul, nu poza celeilalte

`demo-fondator`, Catalog → **Adaugă produs**. Îi scrii nume, gramaj și
**amândouă prețurile**, ca să fie în amândouă cataloagele. Urci poză **numai în
caseta „Fotografie — congelat"** și salvezi.

**Trebuie să vezi, în formular:** miniatura de lângă caseta de congelat arată
poza urcată, iar cea de lângă „copt" **rămâne goală**. Lămurirea de
sub casetă o spune pe față: „Fără ea, produsul apare acolo fără poză."

Treci pe site, pe magazin, și comuți **congelate ↔ coapte**.

**Trebuie să vezi:** pe congelate, poza urcată; pe coapte,
**substituentul casei** — rozeta, nu fotografia congelată. Asta e hotărârea, și
e singura formă în care omul poate deosebi „n-are încă poză aici" de „i-am pus
poză și aici".

Bucata asta **nu se poate plimba pe un produs vechi**: acolo migrarea `027` a
pus deja o poză în amândouă casetele, deci n-ai unde vedea golul. De-aia se
face pe unul nou-nouț.

### 11.2 Schimbi poza la o latură, și cealaltă nu se clintește

Tot pe produsul de la 11.1. Îi urci acum o poză **și** în caseta „Fotografie
— copt", una care se deosebește la prima vedere. Salvezi. Apoi îl deschizi din
nou și schimbi **numai** poza de la congelat, cu o a treia, și salvezi iar.

**Trebuie să vezi, pe site:** latura congelată arată a treia poză, iar cea
coaptă o arată **pe a doua, neatinsă**. Exact ce a semnalat patronul, pe dos.

Și pe disc, în `products/`: **toate trei fișierele** nu trebuie să fie acolo —
poza congelată dinainte de ultima schimbare se dezleagă și se șterge, fiindcă
n-o mai ține nimeni. Dar poza laturii coapte trebuie să fie **întreagă**. Dacă
a dispărut și ea, dezlegarea a rămas oarbă la coloana celeilalte laturi.

### 11.3 Migrarea nu golește nicio vitrină

Acum un produs **vechi**, din cele care erau în catalog înainte de runda asta —
unul căruia nu i-ai urcat niciodată o poză anume pentru coapte.

**Trebuie să vezi, în formular:** **amândouă** miniaturile arată o poză, și e
**aceeași**. Nu e căderea de ieri — căderea s-a tăiat. E `027`, care a copiat o
dată poza congelată în caseta laturii coapte, acolo unde aceea era goală, ca
tăierea să nu golească nicio vitrină în ziua schimbării.

Pe site, comuți între laturi:

**Trebuie să vezi** aceeași fotografie pe amândouă — adică exact ce se vedea și
înainte de actualizare. Un produs vechi nu are voie să-și fi pierdut poza pe
nicio latură; dacă una arată rozeta, migrarea n-a rulat pe baza asta.

Și o dată, ca să se vadă că `027` **nu** e căderea sub alt nume: pe produsul
ăsta vechi, schimbă poza de la congelat și uită-te iar. Cealaltă latură
**rămâne cu cea de dinainte** — copierea a fost o singură dată, la actualizare,
nu la fiecare urcare.
---

## 12 · Runda 2.4.2: poza direct de pe telefon

*Patru minute. Cele trei bucăți de mai jos sunt lucrarea rundei ăsteia, deci
singurele care n-au mai fost plimbate niciodată.*

Până acum panoul primea fotografii de cel mult 5 MB, iar telefoanele de azi fac
poze de opt și de nouă. Ai dat peste asta în prima zi de folosință: *„Tot nu mă*
*lasă să încarc decât 5 MB la poze."* Runda ridică plafonul la **10 MB** și pune
serverul să le **micșoreze singur**, la 1600 de puncte pe latura lungă — deci
urci de pe telefon, fără să mai treci poza prin vreun program, iar magazinul
rămâne la fel de ușor ca azi.

Se plimbă **pe pachetul construit** (8101). Pagina de la 12.3 nu există pe 8100:
acolo răspunde 404, fiindcă serverul de lucru nu duce cererile spre ea.

Îți trebuie o fotografie **mare și adevărată**, făcută cu telefonul — nu una
mică redenumită. Orice poză de peste 4 MB e bună.

### 12.1 Poza mare urcă, iar panoul spune cât s-a micșorat

`demo-fondator`, Catalog → un produs oarecare → **„Editează"**. În caseta
**„Fotografie — congelat"** alegi fotografia mare și salvezi.

**Trebuie să vezi**, întâi, lămurirea de sub casetă scriind **„maximum 10 MB"**
— până acum scria 5. Iar la capăt, în rândul verde de izbândă, o coadă nouă:

```
Produsul a fost salvat. Poze: congelate 9,4 MB → 310 KB.
```

Cifrele vor fi altele, dar forma e asta: **de unde** și **unde**. Coada aia e
tot rostul rundei făcut vizibil. Fără ea, o poză de 9 MB s-ar face 300 KB în
tăcere — iar dacă serverului i-ar lipsi `gd`, ar rămâne întreagă **tot** în
tăcere, și n-ai afla până la factura de trafic.

**Dacă scrie altceva** — de pildă „congelate a rămas la 9,4 MB (serverul n-are
extensia gd)" — nu e un eșec al salvării: poza e pusă, dar nemicșorată, iar
fraza din paranteză spune de ce. 12.3 îți arată unde se rezolvă.

### 12.2 Poza care e deja mică nu se atinge deloc

Același produs, aceeași casetă, dar acum urci o poză **mică** — sub 1600 de
puncte pe latura lungă. Dacă ai una făcută cu telefonul **pe verticală**, cu
atât mai bine.

**Trebuie să vezi:** rândul verde scrie doar „Produsul a fost salvat.", **fără**
coada „Poze:". Asta e dinadins — „era deja mică" e purtarea obișnuită și n-are
ce spune; se aud numai pozele care chiar au pățit ceva.

Și, pe site, poza apare **dreaptă**, nu culcată. Și asta e dinadins: o poză
micșorată e re-scrisă de la zero, iar la re-scriere se pierde antetul în care
telefonul notează pe ce parte a fost ținut. De-aia poza mică nu e atinsă
**deloc** — nici măcar „re-scrisă la fel" —, iar cea mare capătă întoarcerea
coaptă în puncte înainte să i se piardă antetul.

### 12.3 Pagina de verificare îți spune ce server web ai

Deschizi, în browser:

```
http://127.0.0.1:8101/api/verifica.php
```

Pe 8101 pachetul n-are `api/config.php` — el se scrie pe server, nu se
construiește —, deci câteva rânduri ies roșii. E în regulă: aici te uiți la
rândurile despre **server**, nu la cele despre bază.

**Trebuie să vezi** două rânduri noi și unul schimbat:

- **„Extensia gd"** — „Micșorează pozele urcate la 1600 de puncte pe latura
  lungă." Pe calculatorul tău e verde.
- **„Plafonul serverului web (PHP nu-l poate vedea)"** — cu numele serverului
  scris în coloana din mijloc, așa cum se prezintă el.
- **„Limita de mărime pentru fotografii"** spune acum „panoul promite 10 MB".
  Până în runda asta scria 5.

**Pe serverul adevărat**, rândul al doilea e singurul care îți spune dacă mai ai
ceva de făcut. Dacă scrie **nginx**, mai există un plafon pe care PHP nu-l poate
vedea, iar rândul îți dă comanda întreagă. Dacă scrie **Apache**, n-ai nimic de
făcut. Nu e o întrebare la care pot răspunde eu din proiect — de-aia răspunde
pagina, de pe serverul tău.

---

## 13 · Runda 2.5.0: trei prețuri, vederile catalogului, semnele, conturile

*Șaptesprezece minute. Cele nouă bucăți de mai jos sunt lucrarea rundei
ăsteia, deci singurele care n-au mai fost plimbate niciodată.*

Pe 24 septembrie 2026 ai cerut, dintr-o suflare: semne noi pentru cele două
cataloage, și pe calculator, înaintea vorbei; filtre și plăci în Conturi; până
la trei prețuri după cantitate; mai multe feluri de a vedea catalogul, cu cel
de pornire ales din Setări; un catalog elegant, nu înghesuit; și nimic
suprapus nicăieri. Plimbarea de aici le trece pe toate, în ordinea în care se
sprijină una pe alta.

Se plimbă **pe pachetul construit** (8101): pagina produsului de la 13.3 nu
există pe 8100.

**Înainte de orice, un produs de plimbare.** Treptele nu se pun pe un produs al
casei, nici măcar pentru o jumătate de oră. Proba automată de la „După
plimbare" scrie comenzi de câte 100 de bucăți pe produsele de azi și le
socotește banii la prețul A; un prag de 100 pus pe unul din ele i-ar muta banii
pe B, iar proba s-ar înroși fără nicio vină a codului.

`demo-fondator` → `/lab/produse.html` → **„+ Produs nou"**:

- numele **„Plimbare 2.5.0"** și o descriere scurtă, în amândouă limbile,
  gramajul **140** și o grupă oarecare. Poza poate lipsi — rozeta îi ține
  locul;
- pragurile: **100** și **300**. De obicei sunt deja scrise, din Setări;
- panoul **„Congelate"**, cu comutatorul **„În catalog"** aprins: Preț A
  **4,20**, Preț B **3,90**, Preț C **3,60**;
- panoul **„Coapte"**, aprins și el: **5,20**, **4,80**, **4,50**;
- **„Adaugă în catalog"**.

### 13.1 Semnele celor două cataloage

Până acum semnele se vedeau numai în pastila de pe telefon, iar al doilea era
un cuptor. De-acum fiecare catalog își are semnul lui peste tot unde apare,
**înaintea vorbei**: **un fulg albastru** la congelate, **o flacără
portocalie, desenată numai din contur**, la coapte.

Magazinul pe calculator (1440): `http://127.0.0.1:8101/catalog`.

**Trebuie să vezi**, în coloana coșului, sub **„Variantă de livrare"**, fulgul
înaintea lui **„Congelate"** și flacăra înaintea lui **„Coapte"**.

Pe telefon (390), aceeași pagină. Sus, chiar deasupra produselor, comutatorul
e în bara de unelte, cu semne **și** cu vorbe. Derulezi până iese de pe ecran:
abia atunci vine, sub antet, pastila plutitoare, numai cu semne (8.1).

În panou, tot cu `demo-fondator`, semnele trebuie să stea:

- în **Catalog**: pe comutatorul de deasupra listei, pe cele două pastile de pe
  fiecare rând și în capul celor două panouri din formular;
- în **Comenzi**: pe cererile venite de pe site, dacă ai vreuna, și lângă
  varianta fiecărei poziții din formularul de comandă — care se cheamă
  **„Coapte"**, ca în magazin, nu „Copt (gata de servit)", cum scria
  înainte de 2.5.0;
- în **Laborator**: pe eticheta variantei, pe fiecare card de comandă;
- în **Statistici**, pe vederea „Registrul": în legenda cardului „Congelate ori
  coapte?". Același nume stă și pe chipul de sus al vederii
  „Inelul zilei" și în foaia care se deschide când apeși o categorie
  (**„Coapte · congelate"**).

Pe tema întunecată semnele se luminează un pic, ca să nu se piardă pe fundal.

**Singurul loc fără semn** e lista deschisă a unei casete de alegere — de
pildă varianta, în formularul de comandă. O listă ca aceea nu poate purta
desene; închisă, caseta își are semnul în față.

### 13.2 Trei prețuri, și ce refuză formularul

`/lab/produse.html` → pe rândul lui „Plimbare 2.5.0", **„Editează"**.

**Trebuie să vezi**, de sus în jos: **un singur rând de praguri** — „Prețul B
începe de la" și „Prețul C începe de la" —, apoi cele două panouri, fiecare cu
**Preț A, Preț B, Preț C**. Sub fiecare preț scrie cine îl primește: **„1–99
buc."**, **„100–299 buc."**, **„300+ buc."**.

Acum îl strici, câte un lucru o dată, și pui la loc după fiecare:

1. Preț B la congelate: **4,50**. Caseta se încercuiește cu roșu, iar sub
   panou scrie *„Prețul B (4,50 lei) trebuie să fie mai mic decât A (4,20
   lei)."*
2. Pragul C: **80**. Sub rândul pragurilor: *„Pragul C (80 buc.) trebuie să fie
   mai mare decât pragul B (100 buc.)."*
3. Pragul B, golit: *„Scrie de la câte bucăți începe prețul B."*

Pe oricare din ele apeși **„Salvează"**.

**Trebuie să vezi:** nu pleacă nimic. Sus scrie prima greșeală, cu
**„Prețurile după cantitate: …"** în față. Serverul judecă la fel, dar
formularul nu-l mai deranjează cu o cerere care s-ar întoarce refuzată.

Pui totul la loc (4,20 · 3,90 · 3,60, pragurile 100 și 300) și salvezi.

**Trebuie să vezi**, pe rândul din listă, tabelul treptelor: **„1+"**,
**„100+"**, **„300+"**, cu prețurile sub ele, câte un rând pentru fiecare
catalog, cu semnul lui în față. Prețul A e scris apăsat.

### 13.3 Scara de pe card, și îndemnul

Magazinul, 1440, fila **„Congelate"**. Din butoanele desenate de deasupra
produselor alegi **„Unul pe rând, cu toate detaliile"**, și găsești „Plimbare
2.5.0" în grupa lui.

**Trebuie să vezi:** trei căsuțe alăturate — **„1–99 buc. · 4,20 RON"**,
**„100–299 buc. · 3,90 RON"**, **„300+ buc. · 3,60 RON"** — și **niciuna
aprinsă**: produsul nu e încă în coș, deci n-a ajuns nimeni nicăieri. Dedesubt,
rândul celuilalt catalog, cu flacăra în față: **„Coapte: 5,20 RON"**.

Apeși **„Adaugă la ofertă"**, apoi, în căsuța dintre „−" și „+", scrii **88**.

**Trebuie să vezi:** căsuța **„1–99 buc."** se aprinde în auriu, iar sub scară
apare îndemnul: *„Încă 12 buc. și prețul scade la 3,90 RON/buc."*

Deschizi și pagina produsului, `/produs/<numele-produsului>`, ca la 10.6.

**Trebuie să vezi amândouă scările**, fiecare cu semnul și numele catalogului
ei. Aprinsă e numai cea de congelate — latura pe care ai bucăți în coș.

### 13.4 Coșul trece de 100

Înapoi în catalog, pe același card: **100** în loc de 88.

**Trebuie să vezi**, pe rândul din coș: *„140 g · 3,90 RON/buc · preț de la 100
buc. · Congelate"*, iar suma rândului **390,00 RON** — nu 420. Pe card, auriul
a trecut pe **„100–299 buc."**, iar îndemnul spune acum cât mai e până la C.

Apoi scrii **60** la congelate, comuți pe **„Coapte"** și pui încă
**60** de acolo.

**Trebuie să vezi:** niciun rând n-a primit B. Bucățile se numără **pe fiecare
catalog în parte**, fiindcă și prețurile sunt ale fiecăruia: 60 de congelate și
60 de coapte nu fac 120, ci câte 60. Pe amândouă rândurile, îndemnul
spune cât mai lipsește până la 100.

La capăt golești coșul, cu **„Golește"**.

### 13.5 O comandă corectată peste trepte

`/lab/comenzi.html` → **„+ Comandă nouă"**. La client scrii „Plimbare 2.5.0".
Comanda nouă pornește pe un mod care pleacă cu mașina și **fără zonă**, iar fără
zonă nu se salvează (*„Alege zona: fără ea, oprirea nu apare corect pe ruta
șoferului."*); așa că la **„Zonă"** alegi una din oraș, oricare. Jos, caseta
**„Cod de recomandare"** vine cu codul tău scris în ea, dacă ai unul: o golești,
ca o comandă de probă să nu scrie comision pe numele nimănui.
Pe poziție alegi întâi varianta **„Congelate"**, apoi scrii cantitatea
**150** și, la nume, „plimbare".

**Trebuie să vezi**, în lista scurtă de sub nume, scara produsului pe scurt:
*„140 g · 4,20 → 3,60 RON"* — de la prețul A până la cel mai mic. Îl alegi.

**Trebuie să vezi**, sub poziție, rândul de bani: *„150 × 3,90 (preț B, de la
100 buc.) = 585,00 RON"*. Apeși **„Adaugă comanda"**. Formularul se închide,
iar sus scrie *„Comandă nouă adăugată: #… · Plimbare 2.5.0."*

Acum schimbi catalogul pe sub comandă: în Catalog, Preț B la congelate devine
**4,00**, și salvezi. Înapoi în Comenzi, pe comanda nouă: **„Vezi produsele"** →
**„Editează comanda"**.

**Trebuie să vezi** tot *„150 × 3,90 (preț B, de la 100 buc.)"*. Prețul promis
rămâne, deși în catalog B e acum mai scump.

Apoi schimbi numai cantitatea, pe rând, **fără să salvezi**:

| Cantitatea | Trebuie să vezi | De ce |
|---|---|---|
| **350** | *„350 × 3,60 (preț C, de la 300 buc.)"* | a urcat într-o treaptă mai bună: toate bucățile trec pe ea |
| **90** | *„90 × 4,20"* | a coborât sub pragul promis, deci promisiunea cade |
| **160** | *„150 × 3,90 (preț B, de la 100 buc.) + 10 × 4,00 (preț B, de la 100 buc.)"* | cele 150 își păstrează prețul; cele 10 noi iau prețul de azi |

Rândul cu 90 e cel care contează: fără el, „cer 150 la B, apoi tai la 90" ar fi
adus B unei cantități care nu l-ar fi primit niciodată.

Închizi formularul cu **×**, fără să salvezi. În Catalog pui B la loc,
**3,90**. Comanda o anulezi ca la 7.4: **„Editează comanda"** → la **„Stare"**,
**„Anulată"** → **„Salvează modificările"**.

### 13.6 Vederile magazinului, și cea de pornire din Setări

O **fereastră privată** (`Ctrl+Shift+N`), 1440, `http://127.0.0.1:8101/catalog`.

**Trebuie să vezi:** se deschide pe **„Patru pe ecran"** — trei carduri mari pe
rând (cum arată, la 15.1). Treci pe **„Unul pe rând"** și pe **„Șase pe ecran"**: nicio poză, niciun
nume și niciun preț peste altul, nimic tăiat pe margine. Rămâi pe „Șase" și
reîncarci: se deschide tot pe „Șase", fiindcă alegerea ta se ține minte.

Acum schimbi pornirea. În panou, `/lab/setari.html` → fila **General** →
cardul **„Catalogul"** → la **„Cum se deschide magazinul pe calculator"**
alegi **„Unul pe rând, cu toate detaliile"** → **„Salvează"**. Reîncarci
fereastra privată.

**Trebuie să vezi:** se deschide pe **„Unul pe rând"**, deși acolo alesesei
„Șase". Alegerea fusese făcută sub vechea pornire; o pornire nouă ajunge la
toată lumea, și la cine își alesese alta înainte.

Pui la loc **„Patru pe ecran"** în Setări, și salvezi.

Tot în fereastra privată treci pe 390 și reîncarci.

**Trebuie să vezi:** pornește pe **„Unul pe rând"** — pornirea de telefon, pe
care n-ai atins-o. Alegerea de pe calculator nu trece pe telefon: se țin minte
deosebit. „Patru pe ecran" pune **două** carduri pe rând, „Șase pe ecran"
**trei**: pe un ecran îngust grila pune câte încap, fără să strivească vreun
card.

### 13.7 Filtrele magazinului

Tot în magazin, pe 1440, cu fila „Congelate":

- în căutare scrii **„plimbare"** → rămâne numai produsul de plimbare;
- golești căutarea și apeși **„Cu preț de volum"** → rămân numai produsele cu
  B, adică și al tău;
- la **„Ordonează"** alegi **„Preț crescător"** → produsele se așază după
  prețul A, dar **înăuntrul fiecărei grupe**: grupele rămân în ordinea lor;
- scrii **„zzz"** în căutare.

**Trebuie să vezi**, la ultima: **„Niciun produs nu se potrivește"**, cu
butonul **„Șterge filtrele"**. Îl apeși, și catalogul revine întreg.

Pe telefon (390), filtrele stau strânse sub butonul **„Filtre"**. Îl deschizi
și aprinzi **„Cu preț de volum"**.

**Trebuie să vezi:** butonul scrie acum **„Filtre · 1"**. Căutarea nu se
numără acolo: se vede oricum.

### 13.8 Vederile și filtrele din Catalog

`/lab/produse.html`, 1440. Lângă comutatorul de sus stau trei butoane desenate,
**„Cum se arată produsele"**.

**Trebuie să vezi:** „Unul pe rând" e lista de până acum; „Patru" și „Șase"
sunt plăci. Pe placă tabelul treptelor e întors — treptele pe rânduri,
cataloagele pe coloane, fiindcă trei trepte nu încap pe lățimea unei plăci mici
—, și nimic nu e tăiat. Alegi „Șase" și reîncarci: se deschide tot pe „Șase".
Setările nu ating vederea asta; cele de la 13.6 sunt pentru clienți.

Apoi, pe rând:

- pastila **„Cu preț de volum"** → rămâne „Plimbare 2.5.0";
- **„Doar prețul A"** → rămân celelalte, fără el;
- ordinea **„Prețul A, crescător"**;
- **„Arată tot"** → totul revine.

**Trebuie să vezi** pe fiecare pastilă numărul produselor ei. Una cu zero e
stinsă și nu se apasă: n-ar avea ce arăta.

Pe telefon (390), grupa, ordinea și pastilele stau sub butonul **„Filtre"**,
iar **„Congelate | Coapte | Amândouă"** sunt trei căsuțe egale, pe un
rând.

### 13.9 Conturile, pe plăci

Ieși și intri cu `testadmin` → `/lab/conturi.html`, 1440.

**Trebuie să vezi:**

- conturile ca **plăci, patru pe rând**, în ordinea rolurilor — fondatorii
  întâi, clienții la coadă —, iar în fiecare rol după nume;
- deasupra lor, pastilele rolurilor, fiecare cu numărul ei: **Toate ·
  Fondatori · Administratori · Manageri · …**. Un rol fără niciun cont nu are
  pastilă;
- pe placa fondatorului, insigna rolului în **auriu**;
- pe placa ta, **„contul tău"**.

Apeși pastila **„Clienți"**, apoi cauți „Angajat". Nu e client, deci lista
rămâne goală.

**Trebuie să vezi**, sub ea, butonul **„Caută în toate rolurile · …"**, cu
numărul potrivirilor. Îl apeși, și îl găsești pe Angajat Test.

Apeși placa lui.

**Trebuie să vezi:** fișa lui se deschide **pe loc**, pe toată lățimea, chiar
sub rândul plăcii — nu pe altă pagină. Apeși altă placă: prima fișă se
strânge, se deschide a doua. **„Strânge"** o închide.

Deschizi iar fișa lui Angajat Test. **Trebuie să vezi**, la **„Acces"**, în
listă numai numele rolului, „Angajat", iar lângă ea, mai mic, ce poate rolul:
*„Laborator, rută, depozit, pontaj"* — pe telefon, rândul acela stă sub listă.

Deasupra butoanelor de jos stă comutatorul **„Cont pornit"**. Îl apeși.

**Trebuie să vezi:** nu pleacă nimic. Comutatorul se stinge, rama rândului se
face roșie și apare întrebarea **„Oprești contul…?"**, cu **„Renunț"** și
**„Oprește contul"**. Apeși **„Renunț"**: comutatorul e iar pornit. Nu opri
contul: e al probelor de la „După plimbare".

Deschizi apoi fișa ta și pe a fondatorului. **Trebuie să vezi** la amândouă
**niciun comutator**: în locul lui scrie doar **„Cont pornit"**, cu motivul
dedesubt. Pe a ta: *„E contul cu care ești conectat: nimeni nu-și poate opri
propriul cont."*; pe a fondatorului: *„Contul unui fondator îl oprește și îl
pornește numai un fondator."* Până la runda 2.6.0 stătea acolo un comutator
stins, pe care omul îl vedea și nu-l putea apăsa. Dacă vezi unul, ăla e un
defect.

Treci și pe **„Plăci: șase pe rând"** și pe **„Listă"**, apoi reîncarci:
vederea se ține minte.

**Dacă vreun cont are parolă provizorie**, uită-te la insigna lui la toate
cele trei lățimi. Pe o placă lată scrie **„PAROLĂ PROVIZORIE"**; pe una
îngustă, numai **„PROVIZORIE"**. Niciodată tăiat cu „…".

**Dacă vreun cont are un nume lung**, de pildă o firmă, uită-te la placa lui la
390, în „Șase pe rând". **Trebuie să vezi** cuvintele întregi, cel mult cu
litera puțin mai mică decât a vecinilor, nu „Restaurant" pe un rând și „ul"
pe următorul.

**La capăt**, scoți produsul de plimbare. În Catalog, pe rândul lui, **×**.
Apare într-o comandă — cea anulată de la 13.5 —, deci nu se poate șterge:
fereastra îți dă **„Scoate din catalog"**. După asta nu se mai vede nici în
magazin, nici în formularul de comandă.

---

## 14 · Runda 2.5.1: numărul din colțul pozei, și „Coapte"

*Opt minute. Un singur produs de plimbare, mutat de trei ori și pus la loc,
așa că la capăt numerele sunt exact cele de la început; apoi, fără niciun
produs nou, numele laturii coapte (14.5).*

Pe 25 septembrie 2026 ai cerut să alegi tu ce număr poartă fiecare produs în
colțul pozei, iar de la sine numerele să urmeze ordinea în care au fost puse
produsele. Numărul e și locul produsului în grupa lui: magazinul și Catalogul
le așază după el.

Tot pe pachetul construit (8101), tot cu `demo-fondator`.

**Plimbarea mută dinadins numerele altor produse, apoi le pune la loc.** Fă
bucățile în ordine și nu sări peste 14.4: un produs de plimbare șters de pe
03 ar lăsa acolo un gol, iar toate produsele de după el ar rămâne cu un număr
mai mare decât aveau.

### 14.1 Numărul unui produs nou

`/lab/produse.html`. **Trebuie să vezi**, pe fiecare rând din listă, un număr
înaintea numelui — **01**, **02**, **03**… —, iar în fiecare grupă rândurile
stau în ordinea numerelor. Pe plăci („Patru" și „Șase") numărul stă în colțul
pozei.

**„+ Produs nou"**. **Trebuie să vezi** caseta **„Număr în catalog"** deja
scrisă, cu numărul de după cel mai mare din listă, iar lângă ea: *„Următorul
după ultimul produs — ordinea în care sunt puse. Scrie altul ca să-l pui mai
în față."* Notează-ți numărul: e locul lui, și la el se întoarce la 14.4.

Completezi:

- numele **„Plimbare 2.5.1"**, o descriere scurtă în amândouă limbile,
  gramajul **140** și o grupă oarecare. Poza poate lipsi;
- panoul **„Congelate"**, aprins, cu Preț A **4,20**;
- panoul **„Coapte"**, stins din comutatorul **„În catalog"**;
- **„Adaugă în catalog"**.

**Trebuie să vezi**, sus: *„Produsul a fost adăugat în catalog, cu numărul
…"* — același număr ca în casetă. În magazin
(`http://127.0.0.1:8101/catalog`, în vederea „Patru"), cardul lui poartă
numărul în colțul din stânga-sus al pozei, iar în grupa lui stă ultimul.

### 14.2 Un număr liber, și unul greșit

Pe rândul lui „Plimbare 2.5.1", **„Editează"**. **Trebuie să vezi**, lângă
număr: *„Numărul din colțul pozei, în magazin. Scrie altul ca să-l muți; dacă
e ocupat, cele de la rând se dau cu un loc, ca într-o listă."*

Scrii **1000**. **Trebuie să vezi**, cu roșu: *„Scrie un număr întreg de la 1
la 999 — cel din colțul pozei, în magazin."*

Scrii **500**. **Trebuie să vezi**: *„500 e liber — nu se mută niciun alt
produs."* Apeși **„Salvează"**.

**Trebuie să vezi**: *„Produsul a fost salvat, cu numărul 500."* — nicio vorbă
despre alte produse. În magazin, cardul poartă **500**, iar vecinii și-au
păstrat numerele.

### 14.3 Un număr ocupat

**„Editează"** din nou. Scrii **03**. **Trebuie să vezi**, înainte de orice
salvare, cine se mută, cu numele: *„03 e acum la „…". La salvare se dau cu un
loc … produse (03–… → 04–…), de la „…" până la „…"."* Notează-ți produsul de
pe 03.

Apeși **„Salvează"**. **Trebuie să vezi**: *„Produsul a fost salvat, cu
numărul 03. Alte … produse s-au dat cu un loc."*

În magazin:

- produsul notat poartă acum **04**;
- „Plimbare 2.5.1" poartă **03** și stă în grupa lui înaintea celor cu număr
  mai mare;
- în căsuța de căutare scrii **03**: îl găsește pe „Plimbare 2.5.1".

Pui o bucată de „Plimbare 2.5.1" în coș. **Trebuie să vezi** rândul din coș
începând cu **„3. Plimbare 2.5.1"** — numărul din colț, cum pleacă și în
mesajul de WhatsApp. Apoi **„Golește"**.

### 14.4 Înapoi la numărul de la început

**„Editează"**. Scrii numărul notat la 14.1. **Trebuie să vezi**: *„… e acum la
„…". La salvare se dau cu un loc … produse (04–… → 03–…), …"* — aceleași
produse ca la 14.3, date înapoi. Apeși **„Salvează"**.

**Trebuie să vezi**, în magazin, produsul notat la 14.3 iar pe **03**.

**La capăt**, pe rândul lui „Plimbare 2.5.1", **×**. N-a intrat în nicio
comandă, deci fereastra întreabă *„Ștergi „Plimbare 2.5.1"?"* și îți dă
**„Șterge definitiv"**. Stătea ultimul, deci nu lasă niciun gol: numerele din
Catalog sunt cele de dinainte de plimbare.

### 14.5 Latura coaptă se cheamă „Coapte"

Pe 26 septembrie 2026 ai hotărât ca partea caldă a catalogului să se cheme
**„Coapte"**, pereche cu **„Congelate"**, după pozele „acum / coapte" de la
390 și de la 1440. Până atunci se chema „Gata de servire". Se schimbă numai
vorba de pe ecran: comenzile, prețurile și pozele sunt aceleași.

Magazinul pe calculator (1440), fără cont: `http://127.0.0.1:8101/catalog`.

**Trebuie să vezi**, sub titlul catalogului: *„… Alege varianta de livrare —
congelate sau coapte — și adaugă produsele în cererea de ofertă."* În coloana
coșului, sub **„Variantă de livrare"**, comutatorul **„Congelate | Coapte"**,
fiecare cu semnul lui. Apeși **„Coapte"**. **Trebuie să vezi** dedesubt: *„Le
coacem în laboratorul nostru și le livrăm zilnic, gata de vitrină. Fără cuptor
și fără pregătire la tine."*

Apeși **EN**, sus. **Trebuie să vezi** comutatorul spunând **„Frozen |
Baked"**. Apoi înapoi pe **RO**.

În panou, cu `demo-fondator`, numele trebuie să fie:

- **„Coapte"** — la plural, ca **„Congelate"** — unde numește catalogul:
  banda de deasupra listei din **Catalog** (**„Congelate | Coapte |
  Amândouă"**), al doilea panou din formularul unui produs, lista de latură
  de pe o poziție din formularul de **Comenzi** și legenda din
  **Statistici** („Congelate ori coapte?");
- **„Copt"** — la singular, ca **„Congelat"** — unde numește marfa unui
  rând: eticheta variantei de pe cardurile din **Laborator** și din
  **Comenzi**, și capetele de coloană din **Statistici**;
- **„Fotografie — copt"**, caseta de poză a panoului „Coapte".

**Nicăieri nu trebuie să mai vezi „Gata de servire"** — nici pe o pagină, nici
într-un mesaj trimis de server, nici în titlul unei pagini de produs
(`Ctrl+U`, ca la 10.6). Dacă îl vezi, pachetul e mai vechi decât crezi.

---

## 15 · Runda 2.5.2: vederile catalogului, refăcute

*Șapte minute. Numai magazinul, fără cont și fără niciun produs nou: se
schimbă doar cum arată cardurile.*

Pe 29 septembrie 2026 ai cerut ca pe calculator „Patru pe ecran" să aibă
carduri mai mari și mai bogate, „Șase pe ecran" să ia cardurile de până
atunci ale lui „Patru", iar pe telefon cele două vederi să fie desenate din
nou. „Unul pe rând" a rămas cum era.

Tot pe pachetul construit (8101), într-o **fereastră privată**
(`Ctrl+Shift+N`), ca să nu te încurce o vedere aleasă mai demult.

### 15.1 Calculatorul: „Patru" mare, „Șase" ca vechiul „Patru"

1440, `http://127.0.0.1:8101/catalog`.

**Trebuie să vezi:** se deschide pe **„Patru pe ecran"** — **trei** carduri pe
rând. Sub descriere, fiecare card are **două cutii**, câte una pe catalog:
„Congelate" cu prețul ei, aurie, și „Coapte" cu al ei, fiecare cu semnul ei.

În coloana coșului apeși **„Coapte"**. **Trebuie să vezi** cutia aurie trecând
pe „Coapte", pe fiecare card. Pui la loc „Congelate".

Treci pe **„Șase pe ecran"**. **Trebuie să vezi** **patru** carduri pe rând,
fiecare cum arăta „Patru" până la 2.5.2: poza, numele, gramajul, prețul
celuilalt catalog, descrierea pe două rânduri și **„Adaugă la ofertă"**.

Înapoi pe „Patru", treci pe **1100**. **Trebuie să vezi** **două** carduri pe
rând: la trei, cutiile n-ar mai avea loc. De pe la **1130** revin trei.

### 15.2 Telefonul: numele și prețul, într-o bulă

Treci pe **390**, reîncarci și apeși **„Patru pe ecran"**.

**Trebuie să vezi:** **două** carduri pe rând. Jos pe fiecare poză, o **bulă
întunecată**: numele, prețul cu semnul catalogului, apoi prețul celuilalt
catalog, cu semnul lui, și gramajul. Deasupra bulei se vede produsul, cu
numărul lui în colț. Sub poză, o bară: **„+ Adaugă"**.

Apeși „+ Adaugă" pe primul card. **Trebuie să vezi** bara făcută **„−", o
căsuță cu 1, „+"**, fiecare cât un deget: 44 px în F12. Apeși „+": 2. Apeși
„−" de două ori: bara redevine „+ Adaugă".

Apeși pe bulă, nu pe bară. **Trebuie să vezi** fereastra produsului, ca la o
apăsare pe poză. O închizi.

Treci pe **„Șase pe ecran"**. **Trebuie să vezi** **trei** carduri pe rând, cu
numai numele și prețul în bulă. Apeși „+ Adaugă": bara se face **„−", cifra
1, „+"** — fără căsuță, că n-are loc.

### 15.3 Tableta

Treci pe **820** și reîncarci.

**Trebuie să vezi:** tot bulele de pe telefon, pe amândouă vederile —
„Patru pe ecran" cu **patru** carduri pe rând, „Șase pe ecran" cu **șase**.
Sub 1080 de puncte, magazinul folosește cardurile de telefon.

### 15.4 Antetul, pe un calculator mai mic

Treci pe **1200** și urci sus de tot.

**Trebuie să vezi:** antetul pe un singur rând, cu nimic tăiat și nimic ieșit
din el. Legăturile stau mai strâns, iar „Intră în cont" a rămas **numai cu
semnul lui**, într-un buton rotund; ții mausul pe el și scrie *„Intră în
cont"*. Pe **1440** vorba se vede iar întreagă, lângă semn.

Treci pe **320**. **Trebuie să vezi** „Golden Pastry" pe un singur rând.

### 15.5 Tab prin cantitate

Pe 1440, în „Șase pe ecran", apeși **„Adaugă la ofertă"** pe primul card,
apoi `Tab`.

**Trebuie să vezi** inelul auriu pe **„−"** al aceluiași card. Încă un `Tab`:
căsuța cu cantitatea. Încă unul: **„+"**. Încă unul: cardul următor. Inelul
se vede întreg la fiecare pas, și niciun `Tab` nu dispare într-un loc pe care
nu-l vezi.

---

## 16 · Runda 2.6.0 în birou: comanda nouă, fereastra „Din catalog", fișele, Setări

*Paisprezece minute. Tot ce s-a schimbat la masa din birou: formularul de
comandă, fereastra din care se aleg produsele, anunțul care pleacă la echipă,
fișa tehnică și o pază nouă în Setări. Două din comenzile scrise aici le
folosește plimbarea următoare, deci fă-le pe amândouă în aceeași zi.*

Pe 2 octombrie 2026 ai hotărât ca formularul de comandă să-i arate fiecărui rol
numai ce-i folosește, ca fereastra „Din catalog" să semene cu magazinul, ca
anunțul să spună cine a scris comanda și al cui e clientul, iar laboratorul să
se oprească la „Gata de livrare" (17). Tot pe pachetul construit (8101), pe
calculator (1440).

**Pregătești:** `testadmin` → `/lab/setari.html` → fila **„Notificări"**.
Cartonașul **E-mail** trebuie să fie pornit și să aibă o adresă, ca la 7.5. Sus,
în **„Ce anunțuri primești, și pe unde"**, notează-ți întâi cum sunt bifele,
apoi bifezi **E-mail** pe **„Cerere nouă"**, **„Comandă nouă"** și **„Comandă
livrată"** → **„Salvează"**. Dacă pe vreunul din cele trei rânduri e bifat și
**Telegram** ori **WhatsApp**, scoate-i bifa până la capătul plimbării: de pe
calculatorul tău, amândouă trimit de-adevăratelea — Telegram în grupul
echipei, WhatsApp pe numărul tău. E-mailul cade în Mailpit
(`http://127.0.0.1:8025`) și nu pleacă nicăieri; ține-l deschis într-o filă
alăturată.

### 16.1 Comanda nouă, fără „Stare" și fără „Referință"

**Apeși:** ieși, intri cu `demo-fondator` → `/lab/comenzi.html` → **„+ Comandă
nouă"**.

**Trebuie să vezi:**
- titlul **„Comandă nouă"**, cu cursorul deja în caseta **„Client"**;
- lângă „Client", numai **„Telefon"** — **nu** și „Referință": numărul comenzii
  îl dă serverul, la salvare;
- la **„Canal"**, aprins **„Livrare"**; la **„Prioritate"**, **„Normal — după
  interval"**;
- **„Zi"** pe azi și **„Interval"**, cu **„Doar ordinea (ora ascunsă)"** sub el —
  două casete alăturate, **fără** „Stare": serverul pune comanda nouă mereu în
  prima etapă din listă, deci caseta n-avea ce schimba. Dacă vezi „Stare" ori
  „Referință" pe o comandă nouă, pe server a rămas pagina veche;
- la **„Poziții"**, un singur rând gol, cu **1** la cantitate și latura
  **„Coapte"**;
- **„Zonă"** pe **„— fără zonă —"**;
- jos, caseta **„Cod de recomandare"**, cu codul tău scris în ea dacă ai unul,
  și butonul **„Adaugă comanda"**.

Golește caseta codului, dacă e ceva în ea: comanda de probă n-are voie să scrie
comision pe numele nimănui.

**Apeși „Adaugă comanda"**, cu formularul gol. **Trebuie să vezi:** *„Scrie
numele clientului."* Scrii la „Client" `Plimbare 2.6.0 livrare` și apeși iar.
**Trebuie să vezi:** *„Adaugă cel puțin o poziție cu nume de produs."* Nu s-a
salvat nimic.

### 16.2 Fereastra „Din catalog": Congelate și Coapte

**Apeși:** tot în formular, **„Din catalog"**, lângă „+ Adaugă poziție".

**Trebuie să vezi:**
- fereastra **„Din catalog"**, deschisă pe **„Congelate"** — pastila cu fulgul
  e cea apăsată. Până la 2.6.0 se deschidea pe coapte și din ea nu se putea
  pune nimic congelat;
- sub titlu: *„Prețurile și pozele sunt ale laturii deschise. Comanda n-are încă
  nicio poziție."*;
- jos, **„Nicio poziție"** și butonul **„Gata"**;
- căsuța de căutare **fără** cursor în ea: pe telefon, tastatura ar acoperi
  tocmai cardurile.

**Apeși „+"** pe cardul unui produs.

**Trebuie să vezi:** cardul se face auriu, cu **1** în căsuță; pe pastila
„Congelate" apare cifra **1**; sus, *„… Pe comandă: 1 la Congelate · 0 la
Coapte."*; jos, **„1 poziție"**. Încă un „+": **2**. Un „−": înapoi la **1**.

**Apeși** pastila **„Coapte"**, apoi **„+"** pe alt produs.

**Trebuie să vezi** prețurile și pozele laturii coapte, cifra **1** pe pastila
„Coapte" și tot **1** pe „Congelate": o comandă amestecată nu pare goală de pe
nicio latură. Jos: **„2 poziții"**.

**Apeși:** în căsuța de căutare scrii `zzz`. **Trebuie să vezi:** *„Nimic la
Coapte pentru «zzz». Caută și la Congelate, ori șterge căutarea."* Apeși **×**
din căsuță: cardurile revin.

**Apeși „Gata".** **Trebuie să vezi** fereastra închisă, iar la „Poziții" **două
rânduri**: unul pe „Congelate", unul pe „Coapte", fiecare cu 1. Rândul gol de la
deschidere s-a umplut, nu a rămas în urmă. Apeși iar **„Din catalog"**: se
deschide pe **„Coapte"**, unde ai lăsat-o. O închizi cu **×**.

**Apeși:** la **„Adresă de livrare"** scrii `Strada Probei 1`, lași „Zonă" pe
„— fără zonă —" și apeși **„Adaugă comanda"**. **Trebuie să vezi:** *„Alege
zona: fără ea, oprirea nu apare corect pe ruta șoferului."* Alegi o zonă și
apeși iar.

**Trebuie să vezi:** formularul se închide, iar sus scrie *„Comandă nouă
adăugată: #… · Plimbare 2.6.0 livrare."* Notează-ți numărul: e **comanda A**.

**Apeși:** pe rândul ei, **„Vezi produsele"** → **„Editează comanda"**.
**Trebuie să vezi** titlul **„Editează comanda #…"**, iar aici **„Stare"** și
**„Referință"** sunt la locul lor: la corectare chiar scriu. Închizi cu
**„Anulează"** de jos, care închide formularul fără să salveze; comanda nu se
atinge.

**Apeși:** ieși, intri cu `demo-manager` → **„+ Comandă nouă"**. Același titlu,
același buton, tot fără „Stare" și „Referință". Client `Plimbare 2.6.0
ridicare`, la „Canal" **„Ridicare"**, o poziție din **„Din catalog"**, de pe
„Congelate". Golești caseta codului, dacă e ceva în ea, și apeși **„Adaugă
comanda"**: merge **fără zonă**, fiindcă la ridicare nu pleacă nicio mașină.
Asta e **comanda B**.

### 16.3 Cererea agentului

**Apeși:** ieși, intri cu `mihai` → `/lab/comenzi.html`. Butonul de sus scrie
**„+ Cerere nouă"**; îl apeși.

**Trebuie să vezi:**
- titlul **„Cerere nouă"** și același formular ca la 16.1;
- **nicio** casetă „Cod de recomandare". Dacă `mihai` are cod pe cont, în locul
  ei stă un rând pe care nu-l poate schimba: *„Cererea pleacă cu codul tău:
  …"*;
- jos, **„Trimite spre aprobare"**, nu „Adaugă comanda";
- în meniul lui, **nicio** filă „Cereri de pe site": el trimite cereri, nu le
  aprobă.

**Apeși:** client `Plimbare 2.6.0 agent`, „Livrare", o adresă, o zonă și o
poziție → **„Trimite spre aprobare"**. **Trebuie să vezi** formularul închis și
sus: *„Cerere trimisă spre aprobare · Plimbare 2.6.0 agent. Intră în laborator
după ce o acceptă un administrator."*

**Apeși:** ieși, intri cu `testadmin` → `/lab/comenzi.html` → fila **„Cereri de
pe site"** → pe cererea lui, **„Acceptă și fă comanda"**.

**Trebuie să vezi** formularul fără „Stare" și fără caseta codului, cu titlul tot
**„Comandă nouă"** (vezi „Ce NU e defect aici") și cu butonul **„Acceptă
cererea"**. Îl apeși: *„Cerere acceptată → #… · Plimbare 2.6.0 agent."* Asta e
**comanda C**.

### 16.4 Anunțul: cine a scris, al cui e clientul, unde merge marfa

În Mailpit sunt patru mesaje noi: trei **„Comandă nouă #…"** — A, B și C — și
un **„Cerere nouă de la Plimbare 2.6.0 agent"**. Pe Telegram pleacă același
text, cuvânt cu cuvânt; cererea mai are acolo, dedesubt, două butoane,
**„Acceptă"** și **„Respinge"**.

**Trebuie să vezi**, în mesajul comenzii **A**:
- *„Scrisă de: … (fondator)"* și *„Client adus de: nimeni (client al casei)"*;
- *„Pentru: …"*, cu ziua și intervalul;
- *„Onorare: Livrare"* și *„Adresă: Strada Probei 1 · …"*, cu zona aleasă;
- câte un rând pe poziție, cu latura la capăt: unul se termină în
  *„— congelat"*, celălalt în *„— copt"*;
- *„Total: … lei"*.

În mesajul comenzii **B**: *„Scrisă de: … (manager)"*, *„Onorare: Ridicare —
vine clientul după marfă"* și **niciun** rând „Adresă": la o ridicare, adresa
clientului citită pe telefon ar însemna „du marfa acolo".

În mesajul **cererii**: dacă `mihai` are cod, un singur rând, *„Client adus de:
… (agent de vânzări) — tot cine a scris cererea"*; fără cod, *„Scrisă de: …
(agent de vânzări)"* și *„Client adus de: nimeni (client al casei)"*. La
capăt, *„Total estimat: … lei"* și *„Acceptă sau respinge din pagina
Administrare Comenzi."*

În mesajul comenzii **C**, în plus: *„Acceptată de: … (administrator)"*.

Rândurile „Client adus de" le citește, pe Telegram, tot grupul echipei, cu
agentul cu tot. Dacă ți se pare prea mult pentru grup, spune: e de hotărât.

**Desfaci C:** în `/lab/comenzi.html`, din fișa ei, la **„Etapa"** alegi
**„Anulată"** — merge direct, fără casetă (7.1). Comanda rămâne în registru,
anulată; dacă s-a scris un comision pe ea, trece și el pe anulat și nu mai
intră în „Câștigat".

### 16.5 Fișa tehnică: în magazin și în Catalog

**Apeși:** într-o fereastră privată, fără cont, `http://127.0.0.1:8101/catalog`
→ apeși poza lui **„Croissant cu ciocolată"**. Se deschide fereastra produsului.

**Trebuie să vezi** butonul **„Descarcă fișa tehnică"**. Îl apeși: se descarcă
un PDF, `fisa-tehnica-croissant-cu-ciocolata-congelat.pdf`. Același buton stă
și pe pagina produsului, `/produs/croissant-cu-ciocolata`, iar pe **EN** scrie
*„Download the data sheet (PDF, in Romanian)"*. Pe „Coapte" butonul e tot
acolo: coaptele împrumută fișa de la congelate cât n-au una a lor. La
**„Trigon cu vanilie și fructe"** butonul **lipsește**: produsul n-are fișă.

**Apeși:** `testadmin` → `/lab/produse.html` → **„Editează"** pe „Croissant cu
ciocolată".

**Trebuie să vezi** două casete de fișă, alăturate:
- **„Fișă tehnică (PDF) — congelat"**, cu **„Vezi fișa"** și **„Scoate fișa"**,
  iar dedesubt: *„Magazinul oferă fișa asta la congelate. Alege alt PDF ca s-o
  înlocuiești (maximum 10 MB)."*;
- **„Fișă tehnică (PDF) — copt"**, **fără** „Vezi fișa", cu: *„Coapte nu are
  fișa ei: magazinul o oferă pe cea de la congelate. Încarcă una aici ca să o
  înlocuiești."*

**Apeși „Scoate fișa"** la congelat. **Trebuie să vezi** butonul făcut
**„Păstrează fișa"**, dedesubt *„Se scoate la salvare. Ca s-o ai înapoi, o
încarci din nou de aici."*, iar sub caseta de copt: *„Fără fișă, butonul nu
apare în magazin. PDF, maximum 10 MB."* Nimic n-a plecat încă: scoaterea se
face numai la salvare.

**Apeși „Păstrează fișa"**, apoi **„Renunță"**. **Nu salva.** Dacă ai salvat
din greșeală, editezi iar produsul, alegi la congelat fișierul
`fise/fisa-tehnica-croissant-cu-ciocolata-congelat.pdf` din folderul
proiectului și salvezi.

### 16.6 Refuzul din Setări

**Apeși:** `testadmin` → `/lab/setari.html` → fila **„Liste"** → cardul
**„Etapele cu rol special"**. La **„Comanda este încheiată (livrată) când ajunge
la"** alegi **„Gata de livrare"** → **„Salvează"**, din bara de sus.

**Trebuie să vezi** refuzul, roșu, sus și chiar deasupra celor două liste:
*„«Gata de livrare» nu poate fi etapa care încheie comanda: după ea mai urmează
«Predată». Comanda se încheie la ultima etapă din listă (cea de anulare nu se
numără). Dacă marfa ajunge la client chiar la «Gata de livrare», mut-o întâi la
capătul listei «Etape în laborator», apoi alege-o la «Etapele cu rol special»
și salvează."* Nu s-a salvat nimic: clientul ar fi citit „Livrată" cu marfa încă
în laborator.

**Apeși:** pui lista înapoi pe **„Predată"** — refuzul de lângă liste se stinge
singur —, apoi la **„Comanda este anulată când ajunge la"** alegi tot
**„Predată"** → **„Salvează"**.

**Trebuie să vezi:** *„Aceeași etapă, «Predată», nu poate și încheia, și anula
comanda. Alege două etape diferite la «Etapele cu rol special»."*

**Apeși „Renunță"**, din bara de sus. **Trebuie să vezi** cele două liste cum
erau și **niciun** „Ai modificări nesalvate". Bifele de anunțuri le pui la loc
abia la capătul lui 17: plimbarea următoare le mai folosește.

Dacă nu faci 17 în aceeași zi, desfă pe loc ce ai scris: comenzile A și B, din
fișa lor, la „Etapa" → **„Anulată"**, și bifele din „Notificări" cum le-ai
găsit.

---

## 17 · Runda 2.6.0 pe drum: de la laborator la client

*Douăsprezece minute. Drumul unei comenzi de la ultima etapă din laborator până
la client, cu bifele șoferului ca o cheie. Îți trebuie comenzile A și B de la
16.2, amândouă încă în prima etapă.*

Pe 2 octombrie 2026 ai hotărât ca laboratorul să se oprească la **„Gata de
livrare"**, ca de acolo comanda să fie a șoferului — el bifează marfa în
mașină și tot el spune că a ajuns la client —, iar o ridicare s-o închidă
biroul. Etapa nouă o pune în listă migrarea 030, pe locul dinaintea celei
finale. Comenzile aflate la „La ambalare" în ziua actualizării rămân acolo:
laboratorul mai apasă o dată pe fiecare, și abia atunci se aprinde butonul
șoferului.

Două file, amândouă cu `testadmin`, ca la 7.8: **L**, `/lab/laborator.html`, și
**R**, `/lab/ruta.html` îngustată la **390**.

### 17.1 Bifele se pun cât marfa e încă în lucru

**Apeși:** în fila R, pe ziua de azi, cartonul comenzii A.

**Trebuie să vezi:**
- lângă numele clientului, insigna **„În lucru"**;
- **„Bifează ce încarci · 0 din 2"**, cu cele două poziții dedesubt;
- **„Navighează"** singur pe rândul lui — butonul care bifa toată comanda
  dintr-o apăsare nu mai e;
- **„Marchează livrat"** stins, cu lămurirea: *„Comanda e încă în lucru
  («…»). «Marchează livrat» se aprinde când laboratorul o trece în «Gata de
  livrare». Până atunci bifează ce încarci: mai sunt 2 poziții din 2."*

**Apeși** pe cele două poziții, una după alta. **Trebuie să vezi** fiecare
poziție tăiată, cu bifă verde, sus **„2 din 2"**, iar lămurirea fără coada
despre bife. Butonul rămâne **stins**: lipsește etapa, nu bifele.

Comanda B nu e pe rută: la o ridicare nu pleacă nicio mașină.

### 17.2 Laboratorul se oprește la „Gata de livrare"

**Apeși:** în fila L, cardul comenzii A → butonul mare, etapă cu etapă, până
scrie pe el **„Gata de livrare"**, și încă o dată. Mută numai etapa: nu atinge
cantitățile făcute, ca să nu umbli la depozit.

**Trebuie să vezi** butonul stins, cu **„Așteaptă șoferul"**, iar în foaia de
detaliu **„Gata — o ia șoferul"**, cu lămurirea de la 8.7 și cu **„Înapoi la:
La ambalare"** — dacă la tine etapa dinainte se cheamă altfel, numele ei.

**Apeși:** la fel cu comanda B. **Trebuie să vezi** **„Așteaptă clientul"** și,
în foaie, **„Gata — o ridică clientul"**, cu *„Comanda e gata de ridicat.
«Predată» o pune biroul, din «Comenzi», când clientul și-a luat marfa."*

O apăsare greșită se desface cu săgeata **„←"** de pe card.

### 17.3 „Marchează livrat", „Resetează bifele", și comanda care se face gri

**Trebuie să vezi** în fila R, în cel mult cincisprezece secunde și fără
reîncărcare: pe cartonul lui A insigna **„Gata"**, verde, iar **„Marchează
livrat"** aprins — etapa și bifele sunt amândouă la locul lor.

**Apeși „Resetează bifele"**, jos. **Trebuie să vezi** butonul făcut roșu,
**„Sigur? Apasă iar"**, și deasupra lui: *„Ștergi bifele de pe toate comenzile
zilei, iar «Marchează livrat» se stinge până bifezi din nou. Apasă încă o dată
ca să le ștergi."* Nu apeși nimic: după cinci secunde butonul se dezarmează
singur și redevine „Resetează bifele".

**Apeși** iar „Resetează bifele", citești rândul și apeși **încă o dată**.
**Trebuie să vezi:** *„Bifele de încărcare au fost șterse."*, „Marchează
livrat" stins și lămurirea *„Bifează tot ce încarci: mai sunt 2 poziții din
2."* O atingere din greșeală nu mai șterge nimic: trebuie două. Bifezi iar
amândouă pozițiile.

**Apeși:** în fila L treci pe `/lab/comenzi.html` → fișa lui A → **„Editează
comanda"** → la prima poziție, cantitatea **2** → **„Salvează modificările"**.

**Trebuie să vezi** în fila R, în cel mult cincisprezece secunde, rândul galben
*„Biroul a schimbat comanda după ce ai bifat-o: bifele se pun din nou."* și
toate bifele jos — și cea de pe poziția neatinsă: o salvare cu poziții le
rescrie pe toate. Pui cantitatea la loc pe **1** și salvezi; bifele cad încă o
dată, iar tu le pui din nou pe amândouă.

**Apeși „Marchează livrat"**.

**Trebuie să vezi:**
- o clipă, pe buton, **„Se verifică comanda…"**: telefonul recitește comanda
  înainte să trimită;
- sub antet, pe verde: *„Plimbare 2.6.0 livrare · #… marcat livrat.
  Laboratorul vede «Predată»."*;
- cartonul strâns și **cenușiu**, cu antet verde, **✓** în bulină și fără
  insignă. Dacă A era singura oprire a zilei, dedesubt: *„Ruta e gata — toate
  comenzile au fost predate."*;
- în Mailpit, **„Comandă livrată #…"**, cu *„Etapă: Gata de livrare →
  Predată"*;
- în fila L, pe care o aduci înapoi pe `/lab/laborator.html` (la pasul cu
  „Editează comanda" ai mutat-o în „Comenzi"), cardul lui A pe
  **„Finalizată"**, cu **„←"** alături (8.7).

Nu apăsa **„Scade din stoc"** în Depozit: marfa de probă n-a ieșit din raft.

**Desfaci:** în fila R deschizi iar cartonul lui A → **„Anulează livrarea"**.
**Trebuie să vezi:** *„Plimbare 2.6.0 livrare — livrarea a fost anulată."*, iar
comanda e iar în „Gata de livrare".

### 17.4 Livrarea pusă din „Comenzi", și ridicarea închisă de birou

**Apeși:** `testadmin` → `/lab/comenzi.html` → fișa lui A → la **„Etapa"**,
**„Predată"**. **Trebuie să vezi** sus *„#… a trecut în «Predată»."*: biroul
poate închide o livrare și fără bifele șoferului — de pildă când telefonul lui
a rămas fără baterie.

**Înainte să închizi B**, cât e încă în „Gata de livrare": ieși, intri cu
`demo-manager` → `/lab/comenzi.html` → fișa lui B. Dacă în lista **„Etapa"**
**lipsește „Predată"**, contul lui n-are semnul „Poate confirma livrarea", iar
sub listă scrie de ce: *„«Predată» o pune un administrator ori un cont cu bifa
«Poate confirma livrarea», din «Conturi»."* Atunci managerul nu poate închide
nici ridicările; semnul se pune din Conturi, ca la 8.7. Dacă „Predată" e în
listă, contul are semnul. Nu alegi nimic: ieși și intri iar cu `testadmin`.

**Apeși:** pe B, la fel ca pe A. **Trebuie să vezi** în Mailpit **„Comandă
livrată #…"** cu *„Onorare: Ridicare — vine clientul după marfă"* și *„Etapă:
Gata de livrare → Predată"*. Rândul de onorare stă numai pe ridicare: de pe
telefon deosebești o livrare de o marfă luată de client.

**Desfaci tot:** A și B, din fișa lor, la „Etapa" → **„Anulată"** (direct, ca
la 7.1). Rămân în registru, anulate. În Setări, la „Notificări", pui bifele cum
le-ai găsit, cu tot cu Telegram ori WhatsApp, dacă le-ai scos. Bifele din fila
R nu stau pe server, ci în browserul tău, legate de numărul comenzii A: o comandă anulată
nu mai apare pe rută, deci bifele ei nu încurcă nicio altă comandă și pot
rămâne acolo. Nu le șterge cu „Resetează bifele" dacă pe același browser mai
lucrează cineva pe ruta zilei: ar pierde și bifele lui.

---

## 18 · Telefon, tabletă, calculator

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

**Vederile noi se probează și ele la toate trei** (cele de la 13.6, 13.8,
13.9 și 15): magazinul în „Unul pe rând", „Patru" și „Șase", Catalogul din
panou și plăcile din Conturi. La fiecare lățime, niciun card peste altul și nimic
tăiat pe margine.

**Butonul plutitor de WhatsApp**, pe prima pagină: cât se vede butonul verde
mare de sus, **plutitorul nu e pe ecran**. Derulezi până iese cel de sus din
ecran: plutitorul apare în colț, fără să stea peste vreun buton. Urci iar: se
ascunde.

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
- **Fiecare latură are două forme ale numelui, dinadins**: la plural —
  „Congelate", „Coapte" — unde numește catalogul (în magazin, în Catalog, în
  filtrele din Statistici și în formularul de comandă); la singular —
  „Congelat", „Copt" — unde numește marfa unui rând (pe cardurile din
  Laborator și din Comenzi, pe fișa produsului din magazin și în capetele de
  coloană din Statistici). Insignele de pe prima pagină („Congelat crud",
  „Gata de consum") și fraza pentru restaurante („produs finit, gata de
  servit") descriu marfa, nu numesc latura, deci au rămas cum erau.
- **Un produs șters își lasă numărul gol.** Ștergi 08: rămân 07 și 09.
  Numerele pe care le știu deja clienții nu se schimbă din cauza unei
  ștergeri, iar produsul nou nu umple golul — primește numărul de după cel
  mai mare. Dacă vrei 08 plin, muți acolo un produs (14.3).
- **Pe tabletă, în „Șase", o grupă mică lasă loc gol în dreapta.** Grupa cu
  patru produse ocupă patru din cele șase coloane. E hotărârea ta din 29
  septembrie 2026: pe tabletă, „Șase" rămâne de șase pe rând.
- **Pe telefon, butonul de WhatsApp și pastila cu fulgul și flacăra plutesc
  peste carduri** cât derulezi. Așa erau și în 2.5.1; pentru WhatsApp ai
  hotărât pe 29 septembrie 2026 să rămână cum e.
- **Pe calculator, de la 1130 până pe la 1240 de puncte, cele două cutii din
  „Patru" stau una sub alta**, nu alăturate: cardul e prea îngust ca să le
  țină pe amândouă cu prețul pe un rând.
- **Pe card numărul are zero în față, în coș nu**: „03" în colțul pozei,
  „3. Plimbare 2.5.1" în coș și în mesajul de WhatsApp. Așa era și înainte de
  2.5.1, cu vechiul număr al produsului.
- **Bifele de pe „Rută" stau în telefon**, nu pe server. Alt telefon, alt
  browser ori o fereastră privată pornesc de la zero bife, chiar pe aceeași
  comandă. Pe același telefon rămân și după o reîncărcare.
- **„Navighează" stă singur pe rândul lui**, în cartonul de pe „Rută". Butonul
  care bifa toată comanda dintr-o apăsare a fost scos dinadins în runda 2.6.0:
  bifele sunt acum cheia livrării, deci se pun poziție cu poziție, cu marfa în
  mână.
- **La acceptarea unei cereri, formularul se cheamă tot „Comandă nouă"**, deși
  butonul de jos scrie „Acceptă cererea". Titlul de acolo așteaptă hotărârea
  ta; până atunci nu e un defect.
- **„Comandă livrată" pleacă și pentru o ridicare.** Capul mesajului e numele
  bifei din Setări; ce deosebește ridicarea e rândul *„Onorare: Ridicare — vine
  clientul după marfă"* (16.4, 17.4).

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

Când toate cele optsprezece ies, proba automată se mai trece o dată pe
deasupra:

```bash
node .claude/verifica-tot.mjs
```

Trebuie să iasă **0 verificări au eșuat**. Abia atunci urcă plasturele pe server,
după `CITESTE-MA.md` din folderul `plasture-vps 2.6.0`.
