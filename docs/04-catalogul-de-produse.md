# Catalogul de produse

`lab.goldenpastry.ro/produse` — pentru cine a primit pagina „Catalog”: din
pornire, administratorul și fondatorul (v. „Cine intră”, mai jos).

De aici adaugi, modifici și scoți produse și grupe. Ce salvezi apare imediat pe
`goldenpastry.ro`, în formularul de comandă și în datele pe care le citește
Google.

De la 2.7.0, ghidul urmărește și banii din spatele catalogului: cu cât cumperi
materiile prime („Prețurile de cumpărare, în Depozit”), cât te costă o bucată
(„Costul unei bucăți, lângă rețetă”) și cât îți rămâne din ce vinzi („Profitul
din materii prime, în Statistici”). Toate trei sunt, din pornire, numai ale
fondatorului.

---

## Cine intră

Din pornire, catalogul se deschide pentru **administrator** și **fondator**;
fondatorul îl poate da și altui rol, din pagina „Acces”. Un cont care nu l-a
primit — din pornire: managerul, agentul de vânzări, angajatul, omul de
laborator; tableta comună din laborator și clientul, niciodată, orice ai bifa
în „Acces” — vede în locul paginii un ecran cu o singură propoziție, cu numele
contului între ghilimele:
*„Contul „…” nu are acces la catalog. Produsele și prețurile le schimbă numai
cine a primit pagina asta.”*

Un astfel de cont nu poate face nimic aici: pagina nici nu-i cere serverului
catalogul, iar orice scriere — produs, fotografie, fișă tehnică,
rețetă, grupă — îi e refuzată oricum de server.

Sub propoziție stau două ieșiri, socotite pentru contul care a ajuns acolo:

| Contul | „← Înapoi la panoul meu” duce la | A doua ieșire |
|---|---|---|
| managerul | Comenzi | „← Alege panoul” |
| agentul de vânzări | Recomandări | „← Alege panoul” |
| angajatul și tableta comună | Laborator | „← Alege panoul” |
| omul de laborator | Pontaj | „← Alege panoul” |
| clientul | Comanda mea | „← Înapoi la magazin” |

Tabelul arată starea din pornire. Dacă i-ai luat unui rol, din „Acces”,
panoul lui de acasă, „← Înapoi la panoul meu” îl duce la primul panou pe care
îl mai are; iar dacă nu i-a rămas niciunul, la „Parola mea”, singura pagină pe
care o are orice cont.

> Până la 2.6.0 ecranul avea două legături scrise la fel pentru oricine:
> „Comenzi” și „← Alege panoul”. Pentru angajat, pentru omul de laborator, pentru
> client și pentru tabletă, „Comenzi” era al doilea refuz la rând — niciunul n-are
> panoul acela —, iar clientul n-are niciun panou de ales.

Ecranul de refuz se aprinde numai după ce pagina a aflat al cui e contul. Dacă
sesiunea nu poate fi citită, apare în loc caseta roșie *„Catalogul nu s-a
încărcat.”*, cu butonul **„Încearcă din nou”** — pagina n-a aflat nimic, deci
nu dă niciun verdict despre cont.

### Banii din spatele catalogului se dau aparte

Cine are pagina „Catalog” nu vede, numai pentru atât, cu cât cumperi făina și
cât îți rămâne dintr-un croissant. De la 2.7.0 sunt două bife pentru asta, în
pagina „Acces”, în tabelul „Fapte” — sub care scrie, pe calculator, *„Ce are
voie să facă într-un panou cine îl are deja.”* (pe telefon, mai scurt: *„Ce
are voie să facă într-un panou.”*):

| Bifa din „Acces” | Ce deschide | Cine o are din pornire |
|---|---|---|
| „Prețurile ingredientelor” | fila „Prețuri” din Depozit: citit, scris și șters prețuri de cumpărare | numai fondatorul |
| „Costul produselor și profitul” | costul de lângă rețetă, în formularul produsului, și profitul din Statistici | numai fondatorul |

Sunt despărțite dinadins. Omul care trece prețurile de pe facturi nu trebuie
să afle, pentru asta, cât îți rămâne după materiile prime; iar cel căruia îi
arăți profitul nu trebuie să poată schimba un preț.

Fiecare bifă are nevoie de ușa prin care se ajunge la ea:

- „Prețurile ingredientelor” ține de panoul „Depozit”. Bifată la un rol care
  n-are Depozitul, nu ajută la nimic, iar pagina „Acces” o arată ștearsă, cu
  semnul „bifată, dar fără panoul ei”. Tot de aceea, dacă iei cuiva Depozitul,
  nu trebuie să-ți amintești să-i iei și prețurile: pleacă odată cu el.
- „Costul produselor și profitul” se vede în două locuri, fiecare cu ușa lui:
  lângă rețetă, pentru cine are și „Catalog”; în Statistici, pentru cine are
  și „Statistici”. Aici pagina „Acces” nu te mai previne: bifa stă plină și
  la un rol care n-are nici „Catalog”, nici „Statistici”, deși lui nu-i
  deschide nimic. Te uiți singur, pe coloana rolului, dacă are măcar una din
  cele două pagini.

Fără a doua bifă nu lipsește nimic din ce era: nici formularul produsului,
nici Statisticile n-au vreun loc gol ori vreo vorbă despre cost. Asta nu
înseamnă că Statisticile arată întocmai ca până la 2.7.0: numele vânzării
s-au schimbat acolo pentru toată lumea, cu bifă ori fără (v. „Vânzarea nu mai
e numită încasare”, la „Profitul din materii prime, în Statistici”). Serverul
nici nu trimite cifrele unui cont care n-are bifa, deci nu pot fi scoase la
iveală din browser.

O bifă dată din greșeală se ia înapoi tot din „Acces”, din aceeași căsuță; ce
a apucat omul să vadă între timp nu se mai poate lua înapoi.

---

## De ce a fost nevoie de asta

Catalogul exista în două locuri: o listă de 22 de rânduri scrisă în pagina
site-ului, și un tabel în baza de date. Coincideau doar fiindcă din listă am
generat tabelul.

Site-ul afișa din listă, dar totalul comenzii se calcula din tabel. La prima
modificare de preț, clientul ar fi văzut o sumă pe ecran și ar fi fost taxat cu
alta — fără ca vreunul dintre voi să înțeleagă de unde vine diferența.

Acum există un singur catalog: baza de date.

---

## Un produs

**+ Produs nou** deschide formularul. Obligatorii:

- **numele în română și în engleză** — site-ul are versiune în ambele limbi,
  iar un câmp gol acolo arată ca o scăpare;
- **descrierea în ambele limbi**;
- **grupa**;
- **cel puțin un catalog, cu prețul lui**.

Un produs nou pornește cu **amândouă comutatoarele „În catalog” aprinse** —
și la „Congelate”, și la „Coapte” —, iar fiecare comutator aprins cere prețul A
din panoul lui. Dacă produsul se vinde într-o singură parte, stinge comutatorul
celeilalte înainte să salvezi; altfel salvarea se oprește cu *„Produsul e în
catalogul „Coapte”, dar n-are prețul de acolo. Scrie prețul A din panoul
„Coapte”, sau scoate-l din catalogul ăsta.”*

Butonul de jos se cheamă **„Adaugă în catalog”** la un produs nou și
**„Salvează”** la unul care există deja; cât lucrează, scrie „Se salvează…”.
Când totul a trecut, formularul se închide singur, iar sus apare un mesaj verde
care începe cu *„Produsul a fost adăugat în catalog”* ori cu *„Produsul a fost
salvat”*. **„Renunță”** (sau **×** din colț) închide formularul fără să mai
trimită nimic: pe loc, dacă n-ai scris nimic în el; dacă ai scris, de la 2.7.0
întâi te întreabă — v. „Când ieși din formular fără să salvezi”, mai jos.
Dacă un pas se oprește la jumătate, formularul rămâne deschis — v. „Când
salvarea se oprește la jumătate”, tot acolo.

### Cele două cataloage

| Pe site | Semnul | În formular | Ce înseamnă |
|---|---|---|---|
| Congelate | fulg albastru | panoul „Congelate” | livrat în lanț frigorific |
| Coapte | flacără portocalie | panoul „Coapte” | copt la noi, livrat gata de vitrină |

Fiecare catalog are în formular **panoul lui**: semnul, numele, comutatorul
**În catalog** și trei casete de preț, **A, B și C**. A e prețul dintotdeauna;
B și C sunt prețurile de volum și pot rămâne goale — v. „Trei prețuri, după
cantitate”, mai jos. Un produs poate fi numai într-unul din cele două — asta e
chiar rostul comutatoarelor: la coapte sunt mai multe produse decât la
congelate, și sunt grupe care se văd numai acolo.

> Până pe 26 septembrie 2026 al doilea catalog se chema „Gata de servire”.
> Numele s-a schimbat numai pe ecran: comenzile, prețurile și pozele sunt
> aceleași. Unde numește catalogul, vorba e la plural — „Congelate”,
> „Coapte” —, iar unde numește marfa unui rând (cardurile din Laborator,
> capetele de coloană din Statistici), la singular — „Congelat”, „Copt”.
> În engleză, „Frozen” și „Baked”.

Trei lucruri de ținut minte, fiindcă altfel par scăpări:

- **scoaterea dintr-un catalog nu șterge prețurile.** Casetele rămân scrise,
  doar șterse la vedere; comutatorul le pune la loc fără să le cauți;
- **un comutator aprins cere prețul A de acolo.** Dacă îl aprinzi fără preț,
  salvarea e refuzată și mesajul spune care catalog. Poți aprinde comutatorul și
  scrie prețul în aceeași salvare;
- **un produs trebuie să fie în cel puțin un catalog.** Dacă nu se mai vinde
  deloc, nu stinge amândouă comutatoarele — apasă „Scoate din catalog” pe rândul
  lui. Aia e fapta care se numește așa, și se poate întoarce oricând.

Pe rândurile din listă, cele două comutatoare sunt **pastile**, fiecare cu
semnul lui: arată dintr-o privire unde e fiecare produs și se apasă ca să-l
muți. Deasupra listei, **„Congelate | Coapte | Amândouă”** taie lista
pe un catalog sau le arată pe amândouă — pe „Amândouă” se vede oglinda, adică
ce lipsește dintr-o parte. O grupă fără produse în catalogul deschis se ascunde
singură.

**Semnele.** Fulgul albastru și flacăra portocalie, desenată numai din contur,
stau **înaintea cuvântului** oriunde apare unul din cele două cataloage: pe
comutatorul magazinului — și pe calculator, nu numai în pastila de pe telefon —,
pe rândul de pe card care spune ce costă produsul în celălalt catalog, deasupra
scărilor de preț din pagina produsului, aici în Catalog, pe cererile de pe site
și lângă varianta fiecărei poziții din formularul de comandă, pe cardurile din
laborator și în Statistici. Sunt desenate într-un singur loc, `shared/gp-api.js`
(`<gp-semn latura="congelat">` și `latura="copt"`), deci arată la fel peste tot.
Singurul loc fără ele e lista deschisă a unei casete derulante: o opțiune dintr-un
`<select>` nu poate purta un desen, deci acolo rămâne numai cuvântul.

> Până la 2.5.0, semnele — un fulg și un clopot de servire — stăteau numai în
> pastila de pe telefon, iar în formular fiecare catalog avea o singură casetă,
> „Preț congelat” ori „Preț copt”.

**Prețul depinde de variantă, nu de client.** Asta a fost o corecție: coloanele
se numeau `price_horeca` și `price_retail`, iar serverul alegea prețul după cum
cel care comanda are cont sau nu. Era o citire greșită a catalogului. Efectul se
vedea în bani: un vizitator alegea „Congelate”, vedea 28,50 lei pe ecran, și
cererea se înregistra cu 34,90.

Varianta aleasă se păstrează pe comandă și apare pe cardul din laborator, ca
echipa să știe ce pregătește.

Câmpul primește și `2,85`, și `2.85` — scrie cum îți vine la îndemână. Ce **nu**
primește este un text care nu e număr („la cerere”) sau un zero: le refuză pe
loc, cu un mesaj care cere cifre. Înainte, orice text însemna tăcut 0,00 lei,
iar produsul se publica pe gratis; prima dată când cineva îl comanda, comanda
intra cu 0 lei și nimeni nu vedea de unde vine.

### Fotografiile

**Două casete, una de fiecare catalog:** „Fotografie — congelat” și
„Fotografie — copt”. JPEG, PNG sau WebP, maximum 10 MB. Alegi
fișierul, se încarcă la salvare.

**Poza se micșorează pe server, și de-aia poți urca direct de pe telefon.**
O fotografie de telefon are 4000 de puncte pe latură și câțiva megaocteți;
serverul o aduce la 1600 de puncte pe latura lungă și o rescrie, deci ajunge
pe la 200-400 KB. Cardul din magazin o arată oricum pe vreo 500 de puncte, iar
clientul de pe date mobile n-o descarcă întreagă degeaba. Ce urci tu rămâne
limpede și pe ecranele cu puncte dese. O poză deja mică nu se atinge deloc —
iar una făcută cu telefonul ținut pe verticală se întoarce cum trebuie, fiindcă
la rescriere se coace în puncte întoarcerea scrisă în antetul ei.

**Fiecare latură arată numai poza ei.** O latură fără fotografie apare pe site
cu substituentul casei, nu cu poza celeilalte. Miniatura de lângă fiecare
casetă arată exact ce e în caseta aceea, deci ce vezi acolo e ce vede
cumpărătorul pe latura aceea.

Până în 2.4.1 nu era așa: latura fără poză o împrumuta pe a celeilalte, ca
produsul să nu apară gol în ziua dintre cele două urcări. Ideea era bună, dar
din afară **nu se putea deosebi de o poză scrisă în amândouă locurile**: urcai
o fotografie nouă la congelate și o vedeai apărând și la coapte, fără s-o fi
pus acolo. Împrumutul s-a tăiat, iar la actualizare fiecare produs care avea
poză numai la congelate a primit-o, o dată, și în caseta de la coapte — deci
în ziua schimbării nimic nu s-a golit pe site.

Două urmări ale acelei copieri, ca să nu te mire:

- **Produsele vechi au acum același fișier în amândouă casetele.** Schimbi poza
  la o latură și cealaltă rămâne cu cea veche — ceea ce e tocmai ce ai cerut,
  dar e pe dos față de cum se purta înainte. Când vrei aceeași fotografie pe
  amândouă, o urci de două ori.
- **Un produs adăugat de acum înainte** pornește cu amândouă casetele goale.
  Îi pui poză numai la congelate, iar la coapte va apărea
  substituentul până îi urci una și acolo.

Pozele făcute cu telefonul intră ca atare; nu-i nevoie să le micșorezi
dinainte. Dacă totuși îți spune că fotografia e prea mare, mesajul îți spune
și **cât primește serverul**: când cifra aceea e mai mică decât cea de mai
sus, nu poza e de vină, ci folderul `api/`, care n-a ajuns întreg pe server.

Tipul se verifică **după conținut**, nu după extensie sau după ce spune
browserul: un fișier PHP redenumit `poza.jpg` ar fi cod executabil în folderul
public al site-ului. Fișierul primește un nume nou la fiecare încărcare, ca
browserele să nu arate poza veche zile în șir.

Pozele stau în `public_html/products/`. Folderul trebuie să permită scrierea —
`api/verifica.php` îți spune dacă nu.

### Fișa tehnică

Fișa tehnică e documentul pe care clientul îl descarcă din magazin și îl arată
la un control: ingredientele, alergenii, valorile nutriționale, cum se
păstrează produsul și cum se coace. Din 2.6.0 fiecare produs are **două locuri de fișă, câte unul de
catalog**, ca la fotografii.

În formular, fișele stau sub cele două fotografii, înainte de „Nume (RO)”:
**„Fișă tehnică (PDF) — congelat”** și **„Fișă tehnică (PDF) — copt”**. Fiecare
casetă are:

- **„Vezi fișa”** — numai când latura are fișa ei. Deschide PDF-ul într-o filă
  nouă, ca formularul să rămână cum l-ai lăsat;
- **„Scoate fișa”** — tot numai când latura are fișa ei și n-ai ales alt PDF în
  locul ei. Nu scoate nimic pe loc: butonul se face **„Păstrează fișa”**, iar sub
  casetă scrie ce se va întâmpla la salvare. A doua apăsare te răzgândește;
- **caseta de fișier**, din care alegi PDF-ul;
- **un rând de lămurire**, care spune în cuvinte ce oferă magazinul pe latura
  aceea.

| Ce scrie sub casetă | Când |
|---|---|
| *„Magazinul oferă fișa asta la congelate. Alege alt PDF ca s-o înlocuiești (maximum 10 MB).”* | latura are fișa ei (la coapte scrie „la coapte”) |
| *„Coapte nu are fișa ei: magazinul o oferă pe cea de la congelate. Încarcă una aici ca să o înlocuiești.”* | numai la coapte, când n-are fișă proprie, dar congelatele au |
| *„Fără fișă, butonul nu apare în magazin. PDF, maximum 10 MB.”* | latura n-are nicio fișă de oferit |
| *„Se încarcă la salvare: …”*, cu numele fișierului ales | ai ales un PDF |
| *„Se scoate la salvare. Ca s-o ai înapoi, o încarci din nou de aici.”* | ai apăsat „Scoate fișa” |
| *„Se scoate la salvare. La coapte, magazinul va oferi apoi fișa de la congelate.”* | ai apăsat „Scoate fișa” la coapte, iar congelatele rămân cu fișă |

Ca la poze, **nimic nu pleacă până la „Salvează”**: nici PDF-ul ales, nici
scoaterea.

**Ce primește.** Numai PDF, de cel mult 10 MB. Tipul se judecă **după
conținut**, ca la poze: fișierul trebuie să înceapă chiar cu semnul unui PDF,
oricum s-ar numi. Altceva e refuzat cu *„Acceptăm doar fișiere PDF.”*, iar un
fișier prea mare cu *„Fișa depășește 10 MB. Salveaz-o mai mică (fără poze
scanate la rezoluție mare) și încearcă din nou.”* O fișă obișnuită are sub
100 KB; marginea de 10 MB e pentru una scanată.

**Unde stă.** O fișă încărcată din Catalog stă lângă poze, în `products/`, și
primește un nume nou la fiecare încărcare, ca browserul clientului să nu
păstreze fișa veche. Când o înlocuiești ori o scoți, serverul o șterge de pe
disc pe cea veche, dacă n-o mai ține cealaltă latură. Fișele venite cu pachetul
stau în `fise/` și **nu se șterg niciodată de pe disc**: „Scoate fișa” doar le
dezleagă de produs. Fișele tale nu pot sta în `fise/`, fiindcă actualizarea
aduce folderul acela exact cum e în pachet și ar șterge orice fișier care nu e
acolo.

**Coapte împrumută de la congelate.** Cât latura coaptă n-are fișa ei, magazinul
oferă la Coapte fișa de la congelate. Așa ai cerut pe 2 octombrie 2026: produsul
copt e același produs, cu aceleași ingrediente și aceiași alergeni. Invers nu:
o fișă de produs copt nu spune cum se ține și cum se coace unul congelat, deci
congelatele nu împrumută niciodată de la coapte.

E pe dos față de fotografii, unde împrumutul s-a tăiat pe 21 septembrie 2026
(v. „Fotografiile”, mai sus). Ca să nu se poată confunda cu o fișă pusă
dinadins, împrumutul nu se scrie în baza de date — serverul îl face în clipa în
care trimite catalogul —, iar caseta de la coapte rămâne fără
„Vezi fișa”, iar rândul de sub ea spune că magazinul o oferă pe cea de la
congelate. Trei urmări:

- încarci o fișă la coapte — aceea câștigă, iar împrumutul se oprește;
- o scoți — Coapte se întoarce la fișa de la congelate;
- scoți fișa de la congelate, iar coaptele n-au a lor — butonul dispare din
  magazin **de pe amândouă laturile**.

**La salvare**, fișele pleacă după fotografii și înaintea rețetei, cea de la
congelate întâi, fiindcă pe ea o împrumută și Coapte. Dacă una nu trece,
mesajul din formular spune care: *„Produsul a fost salvat, dar fișa tehnică
pentru coapte nu: … Îndreapt-o și apasă „Salvează” din nou: rețeta pleacă abia
după fișe.”* (v. „Când salvarea se oprește la jumătate”, mai jos).

### Fișa tehnică, în magazin

În fereastra produsului și pe pagina lui, sub rândul cu butonul de adăugat în
coș și „Cere ofertă”, stă un buton pe rândul lui: **„Descarcă fișa tehnică”**.
Pe engleză scrie *„Download the data sheet (PDF, in Romanian)”* — fișa e numai
în română, iar omul trebuie să știe asta înainte s-o descarce.

- **Butonul urmează catalogul în care se arată produsul.** Pe Congelate dă fișa
  de la congelate; pe Coapte, fișa de la coapte, ori, cât n-are una, pe cea
  împrumutată de la congelate.
- **Un produs fără fișă pe latura deschisă n-are rândul deloc.** La fel pe un
  server pe care n-a intrat încă migrarea 031.
- **Fișierul se descarcă sub numele produsului**, nu sub cel de pe server:
  `fisa-tehnica-<slug>-congelat.pdf`, unde în loc de slug stă numele produsului
  din adresa lui — de pildă `fisa-tehnica-corn-cu-vanilie-congelat.pdf`. Coada
  „-copt” apare numai când Coapte are o fișă a ei, alta decât cea de la
  congelate; fișa împrumutată se cheamă tot „congelat”, fiindcă e același
  fișier. Așa, cine descarcă amândouă fișele aceluiași produs găsește în
  Descărcări două nume deosebite. Numele îl face `numeFisaTehnica()`, în
  `Site Public.dc.html`.

Fișele din pachet scriu în capul lor *„FIȘĂ TEHNICĂ PRODUS — PATISERIE
CONGELATĂ”*. Cât Coapte le împrumută, clientul de pe Coapte descarcă tocmai
documentul acela.

### Fișele din pachet

Pachetul 2.6.0 aduce în folderul `fise/` fișele pe care le-ai trimis: PDF-ul de
22 de pagini făcut pe 16 iulie, tăiat câte unul pe produs, fără nicio literă
schimbată. La urcare, migrarea 031 le leagă **o singură dată** de latura
congelată a produselor, după numele din adresa fiecăruia:

- **21 de produse** primesc fișă;
- **„Covrig cu susan / mac / semințe”** are o fișă cu **două pagini**, „Covrig cu
  susan” și „Covrig cu mac”, fiindcă pe site e un singur produs. Semințele n-au
  pagină;
- **„Trigon cu vanilie și fructe”** n-are fișă: n-a venit niciuna pentru el.
  Butonul nu apare la el până nu-i încarci una din Catalog;
- un produs căruia i s-a schimbat numele din adresă nu e găsit și rămâne fără
  fișă; i se încarcă una din Catalog. La fel un produs adăugat de tine din
  Catalog: în pachet n-are fișă.

La coapte migrarea nu scrie nimic — Coapte împrumută, ca mai sus.

**O fișă din pachet scoasă din Catalog nu se mai întoarce singură.** Migrările
rulează toate la fiecare actualizare, dar 031 pune în baza de date, la prima
rulare, un semn (`migration_031_fise_tehnice`) după care nu mai leagă nimic. Ce
ai scos rămâne scos, ce ai pus rămâne pus, oricâte actualizări ar veni.

Ca s-o ai înapoi: PDF-ul stă mai departe pe server, fiindcă fișele din pachet
nu se șterg niciodată de pe disc. Îl deschizi în browser la adresa
goldenpastry.ro/fise/fisa-tehnica-…-congelat.pdf, unde în locul celor trei
puncte stă numele produsului din adresa lui — ce scrie după goldenpastry.ro/produs/
pe pagina produsului (de pildă fisa-tehnica-corn-cu-vanilie-congelat.pdf) —, și
îl salvezi pe calculator. Apoi, în Catalog: **Editează** pe rândul produsului →
caseta **„Fișă tehnică (PDF) — congelat”** → alegi fișierul salvat →
**„Salvează”**.

**Migrarea nu atinge gramajul.** Nici prețul, nici descrierea. Fișele tipăresc
pe alocuri alt gramaj decât site-ul, iar care e cel bun hotărăști tu, din
Catalog, la **„Gramaj”**. Pe 2 octombrie 2026 erau douăsprezece deosebiri:

| Produsul | Pe site | În fișă |
|---|---|---|
| Ștrudel cu brânză dulce | 170 g | 140 g |
| Ștrudel cu dovleac | 150 g | 140 g |
| Trigon cu pizza | 140 g | 170 g |
| Croissant cu ciocolată | 140 g | 150 g |
| Roll cu cabanos | 140 g | 100 g |
| Roll cu șuncă și cașcaval | 140 g | 150 g |
| Covrig cu cașcaval | 160 g | 140 g |
| Covrig cu măr | 140 g | 130 g |
| Corn cu ciocolată | 150 g | 140 g |
| Corn cu vanilie | 130 g | 140 g |
| Poale-n brâu | 150 g | 160 g |
| Roll cu pizza | 160 g | 150 g |

Coloana „Pe site” e gramajul cu care au fost puse produsele în catalog la
început. Dacă ai schimbat vreunul între timp din Catalog, cifra din Catalog e
cea care contează.

Când fișa are dreptate, schimbi gramajul în Catalog: **Editează** pe rândul
produsului → caseta **„Gramaj”** → scrii cifra din fișă → **„Salvează”**.
Formularul se închide, iar site-ul se potrivește pe loc. Dacă ai greșit,
o întorci la fel, scriind înapoi cifra din coloana „Pe site”. Când site-ul are
dreptate, fișa trebuie refăcută: încarci la produsul acela PDF-ul îndreptat, în
caseta **„Fișă tehnică (PDF) — congelat”**.

**Fișele mai poartă alte numere și alte nume decât magazinul.** Sunt paginile
tale, neatinse:

- numerele 16–19 din fișe sunt produsele 15–18 din magazin („Covrig cu măr”,
  „Corn cu ciocolată”, „Corn cu vanilie”, „Corn cu fistic”), fiindcă în fișe
  covrigul cu susan și cel cu mac au câte un număr, 14 și 15;
- trei nume sunt scrise altfel: „Trigon Pizza” (în magazin, „Trigon cu pizza”),
  „Merdenea cu brânză” („Merdenea”) și „Poale-n brâu cu brânză dulce”
  („Poale-n brâu”).

### Rețeta — din ce e făcut produsul

Jos, în același formular: **Rețetă — pentru o bucată**. Alegi o materie primă
din listă și scrii cât intră **într-o singură bucată** — 0,045 kg făină pe un
trigon, nu cât intră pe o tavă. Cantitatea se scrie cu virgulă, ca peste tot în
aplicație, și merge până la patru zecimale: la două, cele 0,005 kg de drojdie
dintr-un croissant s-ar rotunji la 0,01, adică dublul, iar pe o comandă de o mie
de bucăți ar lipsi din depozit cinci kilograme de drojdie care n-au existat
niciodată.

Lista de ales conține articolele din **Depozit**. Ce e deja în rețetă nu se mai
oferă a doua oară, iar produsul nu se poate cuprinde pe sine. Ca să scoți o
materie primă, apeși **×** pe rândul ei — nu-i pui cantitatea 0. Rețeta se
salvează odată cu produsul, cu același buton: ce vezi pe ecran e ce rămâne în
bază.

Rețetele sunt **pe un singur nivel**. O „cutie cadou” care conține croissante
trece croissantul ca linie de rețetă, pe articolul lui finit din depozit, și îl
scade direct de acolo. Nu are nevoie de rețeta croissantului.

Un singur nivel e vorba despre **ce se mișcă în depozit**, nu și despre cost.
De la 2.7.0, când socotește cât te costă cutia, aplicația coboară totuși în
rețeta croissantului: altfel n-ar avea de unde ști cât face un croissant pe
care nu-l cumperi de la nimeni (v. „Costul unei bucăți, lângă rețetă”, mai
jos).

**Fiecare produs vândut are nevoie de articolul lui finit în Depozit** — un
articol legat de produs, care adună bucățile făcute și din care scade comanda
predată. Dacă lipsește, formularul ți-o spune printr-o casetă galbenă: fără el,
bucățile făcute n-au unde să se adune, iar comanda rămâne nescăzută la
„Predată”. Legătura se face din Depozit, pe articol, și se poate face și mai
târziu, pe un articol care există de mult.

Un produs **fără** rețetă nu e o greșeală: la producție pur și simplu nu se
scade nimic din depozit pentru el. Se poate lucra așa, cu rețete puse pe rând,
pe măsură ce le socotești. De la 2.7.0 lipsa are totuși un preț: fără rețetă,
produsul n-are cost, iar ce se vinde din el stă în Statistici deoparte, la
„fără rețetă”, și nu intră la profit.

### Costul unei bucăți, lângă rețetă

De la 2.7.0, sub rețetă stă și cât te costă, în materii prime, o bucată. Vede
costul cine are bifa „Costul produselor și profitul” — din pornire, numai
fondatorul; se dă din pagina „Acces” (v. „Banii din spatele catalogului se dau
aparte”, la „Cine intră”). Pentru ceilalți, rețeta arată întocmai ca până la
2.7.0.

Costul nu se scrie nicăieri. Se socotește din ce e deja în casă: cantitățile
din rețetă și prețurile de cumpărare din Depozit (v. „Prețurile de cumpărare,
în Depozit”). De aceea n-are casetă și nu se poate greși cu mâna: dacă cifra
iese strâmbă, strâmbă e o cantitate din rețetă ori un preț din Depozit, și
acolo se îndreaptă.

**Linie cu linie.** Sub fiecare materie primă, pe rândul de dedesubt, scrie
prețul ei de azi și cât face la cantitatea din casetă. Exemplul pe care e
probată socoteala e un croissant cu două linii — făină la 10,00 lei kilogramul
și ouă la 0,50 lei bucata:

| Linia | Cantitatea | Ce scrie sub ea |
|---|---|---|
| Făină | 0,1 kg | „× 10,00 lei/kg = 1,00 lei” |
| Ouă | 2 buc | „× 0,50 lei/buc = 1,00 lei” |

**Rezumatul.** Sub rețeta întreagă, pe un rând, stă suma și ce îți rămâne. La
croissantul de mai sus, cu prețul A de la „Coapte” de 5,00 lei:

*„Cost materii prime: 2,00 lei/buc · la coapte (5,00) rămân 3,00 lei, 60,0%”*

Cu creionul: 1,00 + 1,00 fac 2,00 lei, costul bucății. Din 5,00 lei scazi 2,00
și rămân 3,00 lei. Iar 3,00 din 5,00 înseamnă 60,0% — atât din prețul de
vânzare îți rămâne după materiile prime.

Rândul are câte o bucată pentru fiecare catalog în care produsul e aprins și
are prețul A scris, întâi coaptele, apoi congelatele: „· la congelate (…)
rămân … lei, …%”. Un catalog stins ori fără preț nu apare. Patru lucruri de
știut despre rând:

- **Prețul de vânzare e cel din casetă, de acum**, nu cel salvat. Schimbi
  prețul A în formular și rândul se schimbă pe loc, înaintea oricărei salvări:
  poți încerca un preț și vedea ce-ți rămâne la el. Tot așa cantitățile din
  rețetă. Încercarea nu schimbă nimic pe site cât nu apeși „Salvează”; dacă a
  fost numai o încercare, ieși cu „Renunță” și răspunzi „Ies fără să salvez”
  (v. „Când ieși din formular fără să salvezi”, mai jos).
- **Se compară numai cu prețul A.** Pentru treptele B și C scazi singur costul
  bucății din prețul treptei.
- **Sub cost, rândul nu scrie un minus**, ci, cu roșu, „pierzi … lei” — fără
  procent.
- **Ce rămâne se scade din cifrele scrise**, la doi bani, ca rândul să iasă
  întreg când îl verifici cu creionul. Procentul, în schimb, se scoate din
  costul nerotunjit, ca să fie același cu cel din Statistici. De aceea, ori
  de câte ori costul bucății nu cade pe un ban întreg, procentul de pe rând
  poate să difere de cel scos cu creionul din leii de pe rând: cu o zecime,
  două, și cu atât mai mult cu cât produsul e mai ieftin. Produsul cu drojdie
  de mai jos costă 0,1945 lei; la un preț A de 3,00 lei, rândul scrie costul
  „0,19 lei/buc” și „rămân 2,81 lei, 93,5%”, iar cu creionul 2,81 din 3,00
  fac 93,7%. Cel bun e 93,5%, fiindcă e scos din costul întreg.

Sub rând scrie de unde vine cifra: *„Socotit din ce e scris acum în formular,
cu prețurile de azi din Depozit.”* „De azi” e partea de ținut minte.
Statisticile socotesc fiecare vânzare cu prețul din ziua ei, deci costul de
aici și cel de acolo pot să difere, și pe bună dreptate.

**De ce nu se rotunjește pe drum.** Prețul unei materii prime ține până la
patru zecimale, iar suma se rotunjește o singură dată, la urmă. Tot din probă:
0,005 kg de drojdie la 38,90 lei kilogramul fac 0,1945 lei. Pe linie și în
rezumat scrie 0,19, fiindcă pe ecran banii au două zecimale; dar șapte bucăți
vândute costă în Statistici 1,36 lei (7 × 0,1945), nu 1,33 (7 × 0,19).
Rotunjită fiecare linie în parte, două linii ca asta ar da 0,38 lei în loc de
0,39: un ban pierdut la fiecare bucată, la fiecare produs cu ingrediente
mărunte.

Câteva semne care apar pe linii:

| Ce scrie | Ce înseamnă |
|---|---|
| „· ≈ estimat”, după cost | materia primă n-are încă un preț care să fi început: singurul ei preț e scris cu o zi viitoare, iar socoteala îl folosește de pe acum. Rezumatul capătă și el „≈”, iar sub el scrie *„≈ o parte e estimată: la unele materii prime primul preț scris începe abia de la o zi viitoare.”* |
| „· din rețeta lui”, după cost | linia e un articol finit fără preț propriu (croissantul din cutia cadou): costul lui e cel socotit din rețeta produsului de care e legat |
| „sub 0,01” | linia costă ceva, dar mai puțin de jumătate de ban; nu se scrie „0,00”, ca să nu pară primită pe gratis |
| „· scos din depozit”, lângă nume | articolul a fost scos din Depozit, dar a rămas în rețetă; se socotește ca oricare altul |

Prețul zero e un preț — marfă primită pe gratis —, nu o lipsă: linia lui face
0,00 lei și produsul are cost.

**Cât de adânc coboară socoteala.** Un articol finit fără preț propriu ia
costul produsului de care e legat, socotit din rețeta aceluia. În probă, cutia
cu șase croissante costă 6 × 2,00 = 12,00 lei. Coborârea are o margine: trei
rețete una într-alta sub produsul deschis (cutia, croissantul din ea, aluatul
croissantului, crema aluatului). Mai jos de atât socoteala nu mai deschide
rețete: articolul finit de la margine rămâne fără preț, iar caseta galbenă îl
numește ca pe orice lipsă („… lipsește prețul la: …”). Acolo îi scrii, în
Depozit, prețul lui — costul pe care îl citești în formularul produsului de
care e legat —, știind că un preț scris cu mâna nu se mai mișcă singur când se
scumpesc materiile de sub el.

Un preț pus unui articol finit — de obicei fiindcă îl cumperi gata făcut —
câștigă din ziua în care începe: cutia cu doi cozonaci cumpărați cu 7,25 lei
bucata costă 2 × 7,25 = 14,50 lei, orice ar spune rețeta cozonacului.

#### Când lipsește un preț

Atunci nu se arată **niciun** cost, nici măcar suma liniilor care au preț: un
cost pe jumătate ar arăta un câștig mincinos. În locul rezumatului apare o
casetă galbenă care începe cu *„Costul nu se poate socoti.”* și numește ce
lipsește, de pildă: *„Costul nu se poate socoti. Lipsește prețul la: Unt. În
rețeta de la Croissant (finit) lipsește prețul la: Ouă.”* Fiecare fel de lipsă
are fraza și leacul ei:

| Fraza din casetă | Ce e de făcut |
|---|---|
| „Lipsește prețul la: …” | scrii prețul materiei prime în Depozit, în fila „Prețuri”. Dacă numele e al unui articol finit — un produs din Catalog pus ca linie —, nu-i lipsește un preț, ci rețeta: produsul de care e legat n-are niciuna. I-o scrii în Catalog; preț îi pui numai dacă îl cumperi gata făcut |
| „În rețeta de la … lipsește prețul la: …” | lipsa nu e în rețeta de pe ecran, ci în rețeta unui produs din ea: cutia are croissantul, croissantul are ouăle fără preț. Prețul se scrie la ouă, nu la croissant. Dacă numele de la urmă e tot al unui articol finit, ori produsul lui n-are rețetă (rândul de mai sus), ori e dincolo de marginea de adâncime (v. „Cât de adânc coboară socoteala”, mai sus) |
| „Pentru … nu e scris niciun preț, iar din rețeta produsului lui costul nu se poate socoti: deschide produsul acela ca să vezi ce-i lipsește.” | pagina știe că lipsa e mai jos, dar nu știe care e; deschizi produsul numit și citești caseta lui |
| „Rețetele se mușcă de coadă: urmărind rețeta de la … se ajunge din nou la …” | aici nu e de scris niciun preț: un produs a ajuns, pe ocolite, propria lui materie primă. Scoți linia din una dintre rețete |

Linia vinovată o spune și ea, cu roșu: „fără preț”, „fără preț · din rețeta
lui lipsește un preț”, „rețetele se mușcă de coadă” ori „preț pe altă unitate
— scris pe kg”. Cea din urmă apare când articolului i s-a schimbat unitatea de
măsură după ce i s-a scris prețul: aplicația nu înmulțește pe tăcute
kilogramele cu gramele, ci cere prețul scris din nou. Un preț nou, cu ziua de
azi, aduce costul înapoi aici, lângă rețetă. Vânzările de dinaintea lui rămân
însă fără cost în Statistici până rescrii și prețul vechi, pe ziua lui (v.
„Când unui articol i s-a schimbat unitatea de măsură”, la „Prețurile de
cumpărare, în Depozit”).

Sub frază, cine are și bifa „Prețurile ingredientelor” găsește butonul
**„Scrie prețurile în Depozit”**, cu lămurirea *„Se deschide în altă filă, ca
să nu pierzi ce ai scris aici. Prețurile noi se citesc când deschizi din nou
produsul.”* Adică: scrii prețurile în fila deschisă, te întorci, salvezi
produsul ori ieși din el, și abia la următoarea deschidere apare costul.
Butonul lipsește când singurul necaz sunt rețetele care se mușcă de coadă —
acolo n-ai ce preț să scrii. Cine vede costul, dar n-are prețurile, vede caseta
fără buton și îi spune celui care ține prețurile ce lipsește.

#### Când costul nu se poate socoti încă

Fără să lipsească vreun preț, costul mai poate aștepta un pas de-al tău. Nu e
o alarmă, deci vorba e ștearsă, nu galbenă:

- *„Costul întreg apare după salvare, când deschizi din nou produsul.”* — la un
  produs nou, a cărui rețetă n-a ajuns încă la server, ori când ai adăugat o
  materie primă făcută în Depozit după ce ai deschis formularul. Pe linia ei
  scrie „costul apare după salvare”.
- *„Costul apare după ce scrii cantitatea la: ….”* — o linie are caseta
  cantității goală ori scrisă pe jumătate.

O rețetă goală nu arată nimic din toate astea.

### Când salvarea se oprește la jumătate

„Salvează” (la un produs nou, „Adaugă în catalog”) face mai mulți pași, unul
după altul: întâi produsul, apoi fotografia de la congelate, cea de la coapte,
fișa de la congelate, cea de la coapte și, la urmă, rețeta. O fotografie ori o
fișă pleacă numai dacă ai atins caseta ei. Când trec toți, formularul se
închide și sus apare mesajul verde. Dacă un pas cade, cei de dinainte rămân
salvați, formularul rămâne deschis, iar mesajul roșu din formular spune care a
căzut și ce n-a mai plecat după el. Din clipa în care produsul nou a ajuns pe
server, butonul de jos se cheamă „Salvează”, ca la orice produs care există:

| A căzut | Mesajul |
|---|---|
| chiar produsul — primul pas | numai motivul dat de server, întreg; la apăsarea asta nu s-a salvat nimic, nici poze, nici fișe |
| o fotografie, la un produs nou | *„Produsul a fost adăugat în catalog, dar fără fotografia pentru coapte: … Alege altă fotografie și apasă „Salvează”, ori renunță — produsul rămâne, cu poza lipsă.”* |
| o fotografie, la un produs de dinainte | *„Produsul a fost salvat, dar fotografia pentru coapte nu: … Alege altă fotografie și apasă „Salvează” din nou: fișele și rețeta pleacă abia după poze.”* |
| o fișă tehnică | *„Produsul a fost salvat, dar fișa tehnică pentru congelate nu: … Îndreapt-o și apasă „Salvează” din nou: rețeta pleacă abia după fișe.”* |
| rețeta | *„Produsul a fost salvat, dar rețeta nu: …”* |
| un pas care nu se poate numi | *„Produsul a fost salvat, dar restul salvării (poze, fișe, rețetă) nu a putut fi dus la capăt. Apasă „Salvează” din nou. Eroarea: …”* — la un produs nou, începutul e „Produsul a fost adăugat în catalog” |

În locul celor trei puncte stă motivul dat de server, întreg. Până la 2.6.0,
la un produs nou o cădere pe care pagina n-o putea numi se citea „fără
fotografie” — și omul schimba o fotografie bună —, iar la un produs de dinainte
o fotografie căzută ori o cădere fără nume lăsa numai vorba serverului, fără să
spună că produsul fusese totuși salvat. Rețeta și fotografia unui produs nou
erau numite și atunci.

**A doua apăsare trimite numai ce a rămas.** Produsul se scrie din nou — e doar
text —, dar o fotografie ori o fișă deja primită de server nu mai pleacă:
fișierul ales se uită în clipa în care a ajuns, iar caseta lui se golește.
Miniatura ia atunci poza chiar de la server, deci arată ce e pe site, nu
fișierul de pe calculatorul tău. Mesajul verde de la capăt spune, după
„Poze:”, ce s-a întâmplat cu fiecare fotografie urcată la oricare dintre
apăsări: cât s-a micșorat ori, dacă a rămas mare, de ce. O poză deja mică nu e
pomenită. O fotografie aleasă din nou la aceeași latură, între două apăsări,
îi ia locul celei vechi și în mesaj.

Ce a apucat să se salveze nu se desface singur: nici „Renunță”, nici
închiderea formularului nu-l întorc. Un produs nou rămas pe jumătate e deja pe
site. Îl repari deschizându-l iar cu **Editează**, ori, dacă nu-l mai vrei
deloc, îl ștergi cu **×** de pe rândul lui și **„Șterge definitiv”**: cât n-a
apucat să intre într-o comandă, dispare de tot (v. „Scoaterea unui produs”,
mai jos).

De la 2.7.0, după o salvare oprită la jumătate, ieșirea din formular te
întreabă numai despre ce a rămas netrimis — fotografia, fișa ori rețeta —, nu
și despre ce a ajuns deja pe server. Întrebarea e cea din secțiunea următoare.

### Când ieși din formular fără să salvezi

Până la 2.7.0, formularul se închidea la orice atingere a ieșirii, cu tot ce
era scris în el, iar „Editează” apăsat pe alt rând îl înlocuia pe tăcute.
Hotărârea din 4 octombrie 2026 e una pentru toată aplicația: **ce ai scris nu
se pierde fără o întrebare.**

Formularul neatins se închide pe loc, ca până acum. Dacă ai schimbat ceva —
un câmp, o fotografie aleasă, o fișă aleasă ori pusă la scos, o linie de
rețetă —, oricare dintre ieșiri întreabă întâi. Ieșirile sunt: **„Renunță”**,
**×** din colț, o apăsare pe fundalul din jurul formularului, **„+ Produs
nou”**, **„Editează”** de pe alt rând și „Înapoi” al browserului ori al
telefonului.

Întrebarea se cheamă *„Ieși fără să salvezi produsul?”* și spune ce pierzi:

| Formularul | Ce scrie sub titlu |
|---|---|
| un produs care există | *„Ai schimbat „…”. Dacă ieși, schimbările se pierd, iar produsul rămâne cum era.”* — cu numele sub care îl știi din listă, nu cu cel tocmai scris în casetă |
| un produs nou | *„Ai început un produs nou. Dacă ieși, tot ce ai scris și ai ales aici se pierde.”* |

**„Rămân în formular”** te lasă unde erai, cu tot ce ai scris. **„Ies fără să
salvez”** închide formularul, iar ce era scris în el nu se mai poate aduce
înapoi: nu se păstrează nicăieri. Dacă ai apăsat-o din greșeală, deschizi
produsul iar și scrii din nou. În catalog însă nu s-a schimbat nimic, deci
n-ai ce repara acolo.

Aceeași întrebare vine când pleci din pagină cu formularul scris: o legătură
din meniu ori ieșirea din cont. La închiderea filei ori la reîncărcarea
paginii întreabă browserul, cu fereastra și cu vorbele lui, nu ale aplicației.

#### Cât merge salvarea

După ce ai apăsat „Salvează” (la un produs nou, „Adaugă în catalog”),
casetele se sting și butonul scrie „Se salvează…”. **În primele zece secunde,
ieșirile formularului nu fac nimic**: nici nu închid, nici nu întreabă.
Răspunsul e pe drum și tot el hotărăște — închide formularul dacă a reușit, îl
lasă deschis, cu pricina scrisă în el, dacă nu.

Pe un semnal slab, răspunsul poate întârzia cu minutele. De aceea, după zece
secunde, aceleași ieșiri întreabă altceva: *„Ieși fără să aștepți salvarea?”*,
cu lămurirea *„Salvarea a plecat, dar serverul n-a răspuns încă. Dacă ieși,
răspunsul apare sus, în pagină, când vine. Înainte să scrii din nou, uită-te
în listă: poate s-a salvat.”* Când mai erau de trimis poze, fișe ori rețeta,
întrebarea adaugă: *„Fotografiile, fișele și rețeta care n-au apucat să plece
nu se mai trimit.”*

- **„Rămân în formular”** așteaptă mai departe.
- **„Ies fără să aștept”** închide formularul. Salvarea plecată nu se mai
  poate opri: produsul se scrie ori nu, după cum răspunde serverul.

Răspunsul vine apoi în banda de sus a paginii, cu numele produsului:

| Ce scrie în bandă | Ce s-a întâmplat | Ce faci |
|---|---|---|
| verde: *„Produsul „…” a fost salvat.”* (la unul nou, *„… a fost adăugat în catalog.”*) | a reușit | nimic |
| verde, cu coada *„Fotografiile, fișele ori rețeta care n-au apucat să plece nu s-au mai trimis: deschide produsul și vezi ce lipsește.”* | produsul e salvat, restul nu a mai plecat | deschizi produsul cu „Editează”, alegi din nou ce lipsește și salvezi |
| roșu: *„Produsul „…” a fost salvat, dar restul salvării (poze, fișe, rețetă) nu a putut fi dus la capăt: … Deschide produsul și vezi ce lipsește.”* | produsul e salvat, un pas de după el a căzut | la fel |
| roșu: *„Produsul „…” nu a fost salvat: … Ce era scris în formularul lui nu s-a păstrat.”* | nu s-a scris nimic | deschizi produsul și scrii din nou |

Până vine răspunsul acela, **altă salvare nu poate pleca** — nici a aceluiași
produs, nici a altuia. Două salvări deodată și-ar urca pozele una pe produsul
celeilalte. Dacă apeși totuși butonul, formularul o spune, cu roșu:
*„Salvarea de dinainte n-a primit încă răspuns de la server și, până nu vine,
alta nu poate pleca. Ce ai scris aici rămâne pe loc. Când rândul acesta
dispare, apasă „Salvează” din nou.”* (la un produs nou, rândul numește butonul
„Adaugă în catalog”). Ce ai scris rămâne în casete; aștepți banda de sus și
apeși iar.

Dacă pleci din pagină cât scrie „Se salvează…” — o legătură din meniu, ieșirea
din cont —, întrebarea e a treia, tot una care nu minte: *„Pleci cât se
salvează produsul?”*, cu *„Salvarea e pe drum. Dacă pleci acum, nu mai vezi
aici dacă a reușit.”* și cu butoanele **„Rămân până se salvează”** și **„Plec
oricum”**. Dacă ai plecat, te întorci în Catalog și cauți produsul în listă.

---

### Cine consumă rețeta: laboratorul

Rețeta nu scade nimic singură. Ea așteaptă ca cineva din tură să spună **câte
bucăți a făcut**, iar asta se întâmplă în **Panoul de laborator**: deschizi o
comandă și fiecare poziție are un pas — **−**, cât s-a făcut din cât s-a cerut,
**+**. Pătratul din stânga rândului trece poziția întreagă pe „gata” dintr-o
atingere, iar **Tot lotul e gata** face același lucru pentru toate pozițiile.

La fiecare bucată raportată se întâmplă două lucruri deodată, amândouă scrise în
registrul Depozitului:

| ce | cât |
|---|---|
| materiile prime din rețetă | **ies**, câte una × cantitatea din rețetă |
| articolul finit al produsului | **intră**, o bucată |

Adică exact pasul de la tejghea: făina iese, croissantul intră. Când comanda
ajunge la **Predată** și apeși „Scade din stoc” în Depozit, croissantul **pleacă** — al
treilea pas, scris separat. Nimic nu se scade de două ori.

Cifra pe care o trimite tableta este **totalul făcut**, nu cât s-a adăugat. De
aceea o apăsare repetată, o tabletă care reîncearcă sau două ecrane deschise pe
aceeași comandă nu pot scădea făina de două ori: a doua cerere spune același
total și nu mai mișcă nimic.

**Tura următoare vede unde s-a rămas.** Bara de progres a comenzii se socotește
în bucăți, nu în poziții bifate: o comandă de 300 de croissante și 2 tarte nu e
pe jumătate gata când s-au făcut tartele.

**Ce se poate corecta și ce nu:**

- **Mai puțin decât ai raportat** — se poate, oricând. Ai tastat 8 și ai făcut 3:
  pui 3 la loc, iar aplicația scrie rândurile inverse în registru, cu motivul
  „Corecție producție”. Un rând greșit se îndreaptă cu contra-rândul, nu se
  șterge.
- **Mai mult decât s-a cerut pe comandă** — se refuză. Bucățile făcute peste
  comandă sunt marfă de vânzare, nu marfă a comenzii: se înregistrează ca
  **intrare în Depozit**, pe articolul lor.
- **Comanda anulată după ce s-a produs** — făina rămâne consumată, iar bucățile
  rămân în stocul de produs finit. Sunt în congelator și se vând altcuiva. Un
  consum s-a întâmplat; nu se desface pentru că s-a răzgândit clientul.
- **Stoc pe minus** — e permis și se vede în Depozit. Făina a plecat fizic
  indiferent ce crede aplicația; o cifră negativă e informația utilă, nu o
  eroare de ascuns.

Bifele de dinainte, care se salvau doar în browserul acelei tablete, nu se mai
folosesc: cantitățile stau pe server, deci le vede și tura de după-amiază, și
administratorul, de pe orice ecran. La prima deschidere după actualizare panoul
te anunță o dată că bifele vechi nu s-au putut muta și că e de reintrodus cât e
făcut.

---

### Ce înseamnă „Rezervat” și „Disponibil” în Depozit

Rândul fiecărui articol are trei cifre, nu una:

| coloană | ce spune |
|---|---|
| **Stoc** | ce e pe raft acum, după registrul de intrări și ieșiri |
| **Rezervat** | ce e promis unei comenzi și încă n-a plecat |
| **Disponibil** | Stoc − Rezervat: cu ce mai poți face altceva |

**Promisiunea se naște odată cu poziția de comandă.** O comandă dată la telefon
promite din clipa în care e salvată; o cerere venită de pe site nu promite nimic
cât e cerere — n-are încă poziții — și promite din clipa în care o **accepți**.
Pentru fiecare poziție se promit deopotrivă **materiile prime din rețetă** (câte
o cantitate × bucăți) și **articolul finit** al produsului.

Promisiunea se stinge singură, fără să apeși nimic:

- **la producție** — ce s-a făcut nu mai e promis din materii prime, fiindcă a
  ieșit din raft cu o mișcare adevărată. Altfel aceeași făină ar fi numărată de
  două ori și „Disponibil” ar arăta mai puțin decât ai;
- **la „Predată”** — comanda încheiată nu mai ține nimic, nici marfă gata, nici
  materie primă;
- **la anulare** — la fel: marfa se eliberează pentru altcineva.

Marfa gata rămâne promisă până pleacă din patiserie. Croissantul făcut e tot al
comenzii lui, chiar dacă stă în congelator.

**„Disponibil” poate fi negativ**, și e bine că poate: înseamnă că s-a promis mai
mult decât e pe raft. Rândul se face roșu și primește eticheta „Lipsă”, iar
cartonașul „Lipsă la comenzi” din capul paginii numără articolele aflate așa.
Asta e informația pentru care există coloana.

Cifrele se socotesc pe server, din toate comenzile. Înainte se adunau în pagină,
din comenzile pe care apuca să le aducă — cel mult 500 — deci peste atâtea
comenzi „Rezervat” scădea fără ca nimic să spună că s-a numărat doar o parte.

La fel se numără bannerul **„Scade din stoc”**: câte comenzi „Predată” n-au fost
încă scăzute, și câte bucăți au, vine de pe server, din toate comenzile. Socotit
în pagină, bannerul tăcea tocmai pentru comenzile vechi rămase pe loc — oprite
de un articol lipsă sau redeschise de o corectură —, deși butonul le-ar fi scăzut.

Un articol se leagă de produsul lui din **Depozit → articolul → Produs**. Fără
legătura asta, promisiunea unui produs redenumit între timp nu-și mai găsește
raftul: numele produsului se schimbă din Catalog, numele articolului de depozit
nu se atinge.

---

## Prețurile de cumpărare, în Depozit

De la 2.7.0, Depozitul are o filă în plus, **„Prețuri”**, ultima din rând, după
„Registru”; pe telefon e ultima intrare din meniul filelor. Cu ea deschisă,
titlul paginii e „Prețurile din depozit”. Aici scrii cu cât cumperi fiecare
articol. Din cifrele astea, și numai din ele, se socotesc costul de lângă
rețetă și profitul din Statistici.

### Cine vede fila

Fila e a celui care are bifa „Prețurile ingredientelor” — din pornire, numai
fondatorul. O dai altcuiva din pagina „Acces”, din tabelul „Fapte”; rolul
trebuie să aibă și panoul „Depozit” (v. „Banii din spatele catalogului se dau
aparte”, la „Cine intră”). Pentru un cont fără bifă, fila nu există: nu apare
nici în rândul de file, nici în meniul de pe telefon, iar serverul îi refuză
orice citire ori scriere de preț. Tableta comună din laborator nu primește
fila niciodată, nici dacă dai bifa angajaților: contul ei intră în Depozit,
dar rămâne numai cu panourile de lucru, fără nicio bifă din „Fapte”.

Cifra unui preț nu iese din filă pe altă ușă. În „Jurnal” rămâne cine a scris,
la ce articol și din ce zi e bun prețul — „Preț nou pe …, valabil din …”,
„Preț corectat pe …”, „Preț șters pe … (cel valabil din …)” —, nu și cât:
Jurnalul îl deschid și oameni care n-au prețurile.

### De ce un preț nu se scrie peste cel vechi

Un preț nu e „prețul făinii”, ci „prețul făinii **de la o zi încolo**”. Când
făina se scumpește, nu corectezi cifra veche: adaugi un preț nou, cu ziua din
care e bun, iar cel vechi rămâne în istoric. Pricina e una singură: **trecutul
își păstrează costul.** Fiecare vânzare se socotește cu prețul din ziua ei.

Proba costurilor are chiar cazul ăsta. Untul trece de la 20,00 la 30,00 lei
kilogramul, iar un produs are în rețetă 0,1 kg de unt:

| Când s-au livrat | Bucăți | Costul lor |
|---|---|---|
| în ajunul scumpirii | 10 | 10 × 2,00 = 20,00 lei |
| în ziua scumpirii | 10 | 10 × 3,00 = 30,00 lei |
| amândouă zilele la un loc | 20 | 50,00 lei, adică 2,50 lei bucata |

Dacă ai fi scris 30,00 peste 20,00, toate cele douăzeci de bucăți ar fi costat
60,00 lei, iar ajunul scumpirii ar fi arătat un profit mai mic decât cel pe
care l-ai avut. Cu fiecare scumpire scrisă așa, tot trecutul s-ar fi mutat
după prețul zilei.

Singura scriere care înlocuiește e cea din **aceeași zi**: un articol are un
singur preț pe zi. A doua scriere cu același „Valabil din” e corectura primei
— o cifră greșită la tastare —, nu un al doilea preț, iar foaia te anunță
dinainte (v. „Foaia „Preț nou””, mai jos).

### Lista

Sub titlu scrie câte articole sunt, câte au preț și câte țin o rețetă pe loc:
„… articole · … cu preț · … din rețete fără preț”. Lista se citește din nou la
fiecare intrare în filă, deci un preț scris de pe alt aparat se vede când
intri iar în „Prețuri”, fără să reîncarci pagina. Caseta de sus, „Caută produs
sau materie primă”, caută și aici, după nume.

Rândul unui articol, de la stânga la dreapta:

| Ce vezi | Ce înseamnă |
|---|---|
| numele și, dedesubt, categoria | cu „· scos din depozit” lângă categorie, dacă articolul a fost scos din Depozit, dar a rămas într-o rețetă ori are un preț scris: consumul lui e adevărat, deci se socotește |
| „3,45 lei/kg” și „din 1 oct.” | prețul de azi și ziua din care e bun |
| „▲ +7,8%”, cu roșu | s-a scumpit față de prețul dinainte (aici, de la 3,20 la 3,45) |
| „▼ −…%”, cu verde | s-a ieftinit |
| „= 0%” | prețul nou e același cu cel dinainte, ori diferă atât de puțin, încât schimbarea, rotunjită la o zecime de procent, iese zero |
| „fără preț” | articolul n-are niciun preț care să fi început |
| „de pe …: 3,60 lei/kg” | un preț **anunțat**: scris de pe acum, cu o zi viitoare. Intră singur în socoteală când vine ziua |
| cu roșu: *„Prețul e scris pe kg, articolul e acum în g. Scrie prețul din nou.”* | articolului i s-a schimbat unitatea de măsură după ce i s-a scris prețul. Aplicația nu înmulțește pe tăcute: prețul vechi nu se mai folosește. Un preț nou, pe unitatea de acum, repară numai de la ziua lui încolo; ce e de făcut pentru vânzările de dinainte scrie la „Când unui articol i s-a schimbat unitatea de măsură”, mai jos |
| **„Istoric · 3 ▾”** | desface prețurile scrise până acum; apare numai dacă articolul are măcar unul |
| **„Preț nou”** ori **„Pune preț”** | deschide foaia de preț; butonul se cheamă „Pune preț” cât articolul n-are niciunul |

Săgeata lipsește când n-are cu ce se compara: articolul e la primul lui preț,
prețul dinainte era zero ori era scris pe altă unitate.

La urmă, sub rândul *„Produse finite — pune preț numai dacă le cumperi gata
făcute; altfel costul lor se socotește din rețetă.”*, stau articolele finite,
cele legate de un produs din Catalog. Sunt despărțite de materiile prime ca să
nu arate a lipsuri: un croissant fără preț aici nu e o scăpare, costul lui
vine din rețeta croissantului.

### Avertismentele de sus

Deasupra listei, cu roșu, stau articolele care intră într-o rețetă și n-au un
preț folosibil. Titlul benzii e *„Un articol din rețete n-are preț — costul
produselor lui nu se poate socoti.”* ori, când sunt mai multe, *„… articole din
rețete n-au preț — costul produselor lor nu se poate socoti.”* Stau sus fiindcă
de ele atârnă cifre din alte două pagini: un produs cu o singură linie fără
preț rămâne fără cost de tot — nu cu suma liniilor care au —, deci și fără
profit.

Fiecare rând al benzii numește articolul, arată după săgeată produsele pe care
le ține pe loc („→ …”) și are butonul lui, ca să nu cauți articolul prin listă:

| Rândul | Butonul | Ce e de făcut |
|---|---|---|
| numai numele articolului | „Pune preț” | scrii prețul |
| *„…: prețul e scris pe kg, articolul e acum în g. Scrie prețul din nou.”* | „Preț nou” | scrii prețul pe unitatea de acum; ca să-și capete costul și vânzările de dinainte, rescrii și prețul vechi, pe ziua lui (v. „Când unui articol i s-a schimbat unitatea de măsură”, mai jos) |
| *„…: rețeta lui se întoarce la el însuși, deci costul nu se poate socoti. Îndreapt-o în Catalog.”* | niciunul | aici nu lipsește un preț: un produs a ajuns, din rețetă în rețetă, propria lui materie primă. Deschizi produsul în Catalog și scoți linia din una dintre rețete |

Cât pe bandă e un rând de felul al treilea, titlul ei nu mai spune „n-are
preț”, ci „oprește socoteala” („opresc socoteala”, la mai multe), iar rândul de
sub titlul paginii scrie „din rețete cu socoteala oprită”.

### Foaia „Preț nou”

„Preț nou” ori „Pune preț” deschide o foaie cu titlul „Preț nou”. Sub titlu
scrie pentru ce articol e și cât costă acum — „… · acum 3,45 lei/kg din 1 oct.”
ori „… · fără preț până acum”.

| Câmpul | Ce scrii | Margini |
|---|---|---|
| **„Preț”** | lei pe unitatea articolului. Unitatea e scrisă lângă casetă („lei/kg”) și nu se schimbă de aici: e a articolului | cu virgulă, până la patru zecimale; cel mult un milion de lei. Zero e un preț — marfă primită pe gratis —, nu o lipsă |
| **„Valabil din”** | ziua din care e bun prețul. Pornește pe ziua de azi | cel mult 1100 de zile în urmă și 60 de zile înainte |
| **„Notă (opțional)”** | ce te ajută să-ți amintești de unde e cifra: „factura, furnizorul” | cel mult 255 de semne |

Marginile zilei au fiecare pricina ei. În urmă, 1100 de zile — ceva peste trei
ani — sunt cât cuprind Statisticile într-o singură perioadă cerută. Marginea
aceea e însă lungimea perioadei, nu vechimea ei: Statisticile pot fi întrebate
și despre zile mai vechi de 1100 de zile, iar vânzările de atunci se socotesc
cu cel mai vechi preț scris și poartă semnul „≈ estimat”, fiindcă un preț cu
ziua lor nu se mai poate scrie. Înainte, 60 de zile ajung pentru scumpirea pe
care furnizorul o anunță pentru luna viitoare; dincolo de două luni nu mai e
un preț anunțat, e o zi greșită — anul viitor în loc de anul ăsta.

O zi din afara marginilor e refuzată lângă câmp: *„Alege o zi între … și
….”* Un preț care nu e număr e refuzat la fel: *„Scrie prețul ca număr, de
exemplu 3,45.”* Iar unul mai mare ca zero, dar prea mărunt ca să încapă în
patru zecimale, e refuzat de server, ca să nu se scrie zero în locul lui:
*„Prețul e prea mic pentru patru zecimale; scrie 0 dacă e primit pe gratis.”*

**Dacă ziua aleasă are deja un preț**, foaia o spune înainte să salvezi, cu
prețul care se pierde: *„Înlocuiește prețul din ziua asta (3,20 lei/kg).”* E
singurul caz în care un preț vechi dispare la salvare. Odată cu cifra se
înlocuiește și nota, deși rândul acela n-o pomenește: foaia pornește cu nota
goală, deci, dacă n-o scrii din nou, cea veche se pierde. Dacă nu asta voiai,
schimbă ziua. Când foaia n-a putut afla ce prețuri are articolul, o spune și
pe asta, în loc să tacă: *„Nu am putut citi prețurile de până acum, deci foaia
nu poate spune dacă ziua aleasă are deja unul. Dacă are, salvarea îl
înlocuiește.”*

**„Socotește din factură.”** Pe factură nu scrie prețul pe kilogram, scrie cât
ai plătit pe un sac. În caseta de jos completezi *„am plătit [50] lei pe [25]
kg”*, iar foaia face împărțirea și pune rezultatul în câmpul de preț: *„= 2,00
lei/kg, pus în câmpul de preț”*. Dacă tastezi alt preț peste cel socotit,
rămâne al tău. Dacă ștergi cantitatea, câmpul se întoarce la ce scriseseși cu
mâna, ca să nu rămână în el o cifră pe care n-o mai ține nicio socoteală.

**„Salvează”** scrie prețul și închide foaia; sus apare *„Preț nou · … · 3,45
lei/kg din 1 oct.”* ori, dacă ai înlocuit prețul aceleiași zile, *„Preț
corectat · …”*. Din clipa aceea, costul produselor care au articolul în rețetă
și profitul din Statistici se socotesc cu prețul nou, de la ziua lui încolo.

**Cum desfaci un preț scris greșit:**

- **cifra e greșită** — deschizi iar „Preț nou” și pui la „Valabil din” ziua
  prețului greșit. Foaia pornește de fiecare dată pe ziua de azi, deci la un
  preț scris cu altă zi trebuie s-o alegi tu. Semnul că ai nimerit ziua e
  rândul „Înlocuiește prețul din ziua asta (…)”; dacă nu apare, nu corectezi
  nimic, ci adaugi un al doilea preț. Scrii cifra bună și, din nou, nota —
  foaia pornește cu nota goală, iar corectura o înlocuiește și pe ea — și
  salvezi;
- **ziua e greșită**, ori prețul nu trebuia scris deloc — îl ștergi din istoric
  (v. mai jos) și, dacă e cazul, îl scrii din nou cu ziua bună.

Foaia se închide cu **„Renunță”**, cu **×** din colț ori cu „Înapoi” de pe
telefon; o apăsare pe fundal n-o închide. Neatinsă, se închide pe loc. Dacă ai
scris ceva în ea, întreabă întâi: *„Ieși fără să salvezi prețul?”*, cu
lămurirea *„Prețul, ziua și nota scrise aici se pierd: prețul nou nu se scrie,
iar costurile rămân socotite ca până acum.”* și cu butoanele **„Rămân în
formular”** și **„Ies fără să salvez”**.

Cât scrie „Se salvează…”, ieșirile foii nu fac nimic în primele zece secunde.
După zece secunde fără răspuns întreabă *„Ieși fără să aștepți salvarea?”*,
cu aceleași vorbe și aceleași două butoane ca la formularul produsului (v.
„Cât merge salvarea”, la „Când ieși din formular fără să salvezi”). Dacă ieși,
răspunsul apare în banda de sus când vine; înainte să scrii prețul a doua
oară, uită-te pe rândul articolului: poate s-a salvat.

### Istoricul și ștergerea unui preț

„Istoric · … ▾” desface, sub rândul articolului, toate prețurile lui, de la cel
mai nou la cel mai vechi. Pe fiecare rând: ziua din care e bun, prețul și,
stins, nota, cine l-a scris („scris de …”) și, dacă e cazul, „pe altă unitate
decât are articolul acum (g)”, cu unitatea de azi a articolului între
paranteze (v. „Când unui articol i s-a schimbat unitatea de măsură”, mai jos).
Lângă zi scrie „· acum” la prețul folosit azi și „· urmează” la unul anunțat.

**„Șterge”** de pe rândul unui preț întreabă chiar acolo, pe rând: *„Ștergi
prețul din 1 oct. (3,45 lei/kg)? Costurile de după ziua aceea se vor socoti cu
prețul dinainte.”* **„Păstrează”** închide întrebarea fără să atingă nimic.
**„Șterge”** îl scoate, iar sus apare *„Preț șters · … · 1 oct.”*

Ștergerea mută socotelile pe loc, cum spune întrebarea: de la ziua prețului
șters încolo, costul se face cu prețul de dinaintea lui. Întrebarea e scrisă
la fel și la **cel mai vechi** preț al articolului, deși acolo nu mai e niciun
preț dinainte. Atunci zilele de până la prețul următor se socotesc cu acela,
cel de după, și poartă semnul „≈ estimat” (v. „Ce scrie la „Stare””, la
„Profitul din materii prime, în Statistici”). Dacă era singurul preț al
articolului, produsele care îl au în rețetă rămân fără cost și articolul urcă
în banda roșie.

Un preț șters nu se poate aduce înapoi cu un buton. **Îl scrii din nou**, din
„Preț nou”, cu aceeași zi la „Valabil din” și cu aceeași cifră — de aceea
merită trecută factura la notă. În „Jurnal” rămâne că a fost șters, la ce
articol și din ce zi era bun, nu și cât era.

Dacă sus, în locul lui *„Preț șters · …”*, scrie *„Prețul fusese deja șters ·
…”*, l-a scos altcineva, de pe alt aparat, cât tu aveai lista veche pe ecran.
Nu e nimic de reparat.

Un articol care are prețuri scrise nu se mai șterge de tot din Depozit. La
ștergere e numai scos din depozit — mesajul spune că are „prețuri scrise” și
că „istoricul rămâne întreg” —, iar de acolo îl poți repune oricând, din josul
paginii, de la „Scoase din depozit”.

### Când unui articol i s-a schimbat unitatea de măsură

Dacă un articol era ținut în kilograme și l-ai trecut pe grame, prețurile lui
scrise până atunci au rămas pe kilogram. Aplicația nu le împarte singură la o
mie: un preț scris pe altă unitate decât are articolul nu se folosește deloc,
iar produsele care au articolul în rețetă rămân fără cost. De aceea rândul
articolului și banda roșie cer *„Scrie prețul din nou.”*

Un preț nou, scris cu ziua de azi, repară **numai de azi încolo**: costul de
lângă rețetă apare iar, iar articolul coboară din banda roșie. Vânzările de
dinaintea zilei lui se socotesc însă tot cu prețul din ziua lor — cel vechi,
de pe kilogram —, deci rămân fără cost. Așa ajung două pagini să spună lucruri
deosebite: banda din Depozit e goală, iar Statisticile unei luni trecute cer
mai departe *„Scrie din nou prețul la: … (i s-a schimbat unitatea de
măsură)”*. Nu se contrazic: Depozitul judecă ziua de azi, Statisticile judecă
fiecare zi din perioada aleasă.

Ca să-și capete costul și trecutul, **rescrii fiecare preț vechi pe ziua
lui**:

1. Deschizi „Istoric” la articol și cauți prețurile care au, stins, vorba „pe
   altă unitate decât are articolul acum (g)”.
2. Pentru fiecare, deschizi „Preț nou”, pui la „Valabil din” ziua acelui preț
   și scrii aceeași cifră, trecută pe unitatea de acum: 4,00 lei pe kilogram
   sunt 0,004 lei pe gram.
3. Foaia arată *„Înlocuiește prețul din ziua asta (4,00 lei/kg).”* — semnul că
   ai nimerit ziua. Salvezi.

Corectura mută, odată cu cifra, și unitatea prețului pe cea de acum a
articolului: vorba „pe altă unitate…” dispare de pe rândul lui din istoric, iar
vânzările din zilele acelea își capătă costul. Nota prețului vechi se pierde
dacă n-o scrii din nou în foaie (v. „Cum desfaci un preț scris greșit”, mai
sus). O cifră greșită la rescriere se îndreaptă la fel: încă o corectură, pe
aceeași zi.

Un lucru de știut la socoteală: un preț ține numai patru zecimale. 4,00 lei
pe kilogram încap întocmai în 0,004 lei pe gram, dar 3,45 lei pe kilogram ar
fi 0,00345 lei pe gram, iar a cincea zecimală se rotunjește la salvare. La un
articol ieftin trecut pe o unitate de o mie de ori mai mică, costul vechi iese
deci apropiat, nu la ban.

### Când fila spune că prețurile nu se pot scrie

Dacă în locul listei apare caseta galbenă *„Prețurile nu se pot scrie încă.”*,
aplicația a fost urcată, dar baza de date n-a primit încă tabelul prețurilor.
Sub titlul casetei stă vorba serverului, care numește fișierul de rulat:
*„Prețurile nu sunt încă disponibile: rulează întâi sql/034_costuri.sql.”* Nu
e ceva ce se repară din pagină: îi dictezi rândul acela celui care ține
serverul (pașii sunt în `docs/09-actualizare-vps.md`). Până atunci, nici costul
de lângă rețetă, nici profitul din Statistici nu apar.

---

## Profitul din materii prime, în Statistici

De la 2.7.0, pagina „Statistici” arată, lângă cât ai vândut, și cât ți-a rămas
după materiile prime. Vede profitul cine are pagina „Statistici” **și** bifa
„Costul produselor și profitul” — din pornire, numai fondatorul; amândouă se
dau din pagina „Acces”. Cine are Statisticile fără bifa a doua nu vede nicio
vorbă despre cost ori profit. Pagina lui nu e totuși întocmai cea de până la
2.7.0: numele vânzării s-au schimbat pentru toată lumea (v. „Vânzarea nu mai e
numită încasare”, mai jos), iar cine are și pagina „Încasări” vede plățile
(v. „Foaia „Încasări””).

„Profit din materii prime” e tot numele, și e ales cu grijă: din vânzare se
scade **numai** ce costă făina, untul, ouăle. Salariile, chiria și curentul nu
sunt aici, iar pagina o spune unde arată cifra. Nu e profitul firmei; e
răspunsul la „care produs își merită locul în vitrină”.

### Cum se socotește

Pentru fiecare rând de comandă: **bucățile × costul unei bucăți din ziua
rândului**. Ziua e cea după care numeri și vânzările — „ziua livrării” ori
„ziua comenzii”, după cum e ales sus. Profitul rândului e vânzarea minus
costul, iar marja e profitul împărțit la vânzare.

Sub fiecare loc unde scrie un profit stă aceeași frază: *„Socotit cu rețeta de
azi și cu prețul materiilor prime din ziua fiecărei comenzi.”* Cele două
jumătăți ale ei nu sunt la fel:

- **prețurile sunt cele din ziua vânzării** — de aceea un preț nou se adaugă cu
  ziua lui, nu se scrie peste cel vechi (v. „De ce un preț nu se scrie peste
  cel vechi”);
- **rețeta e cea de azi.** Aplicația nu ține minte cum arăta rețeta într-o zi
  trecută. Dacă azi scoți zece grame de unt din croissant, și croissantele
  vândute în martie se socotesc cu rețeta nouă. E o hotărâre luată pe față la
  2.7.0, nu o scăpare: pagina o spune ori de câte ori arată cifra. Ține minte
  asta când schimbi o rețetă și te uiți apoi la o lună veche.

Proba costurilor socotește, cu creionul, o zi cu șapte produse vândute. Primul
e croissantul din „Costul unei bucăți, lângă rețetă”:

| Produsul | Bucăți × preț | Vânzări | Cost | Profit | Marjă | La „Stare” |
|---|---|---|---|---|---|---|
| croissantul | 12 × 5,00 | 60,00 | 24,00 (2,00 lei/buc) | 36,00 | 60% | |
| unul cu 0,005 kg de drojdie | 7 × 3,00 | 21,00 | 1,36 | 19,64 | 93,5% | |
| unul cu o materie primă primită pe gratis | 5 × 4,00 | 20,00 | 5,00 | 15,00 | 75% | |
| cutia cu șase croissante | 2 × 40,00 | 80,00 | 24,00 | 56,00 | 70% | |
| cutia cu doi cozonaci cumpărați | 1 × 10,00 | 10,00 | 14,50 | −4,50 | −45% | „sub cost” |
| unul cu o materie primă fără preț | 4 × 6,00 | 24,00 | — | — | — | „lipsește prețul la: …” |
| unul fără rețetă | 3 × 2,50 | 7,50 | — | — | — | „fără rețetă” |

Totalul zilei, cum îl scrie pagina:

| | |
|---|---|
| vânzări, cu totul | 222,50 lei |
| din care cu cost socotit | 191,00 lei |
| costul materiilor prime | 68,86 lei |
| profit | 122,14 lei |
| marjă | 63,9% — adică 122,14 din 191,00, nu din 222,50 |
| vânzări fără cost socotit | 31,50 lei, pe 2 produse |

### De ce vânzările fără cost stau deoparte

Ultimele două produse din tabel n-au cost: unuia îi lipsește un preț, celălalt
n-are rețetă. Pagina avea de ales între două feluri de a le arăta, și l-a ales
pe cel care nu minte.

Dacă le-ar fi socotit **cu cost zero**, cei 31,50 lei ar fi intrat întregi la
profit: 153,64 lei în loc de 122,14, cu o marjă pe care n-o ai. Un produs fără
rețetă ar fi arătat ca cel mai bănos din vitrină tocmai fiindcă nu se știe
nimic despre el. O cifră falsă și frumoasă e mai rea decât o liniuță, fiindcă
pe ea se iau hotărâri.

De aceea rândurile fără cost au liniuțe la „Cost”, „Profit” și „Marjă”, stau la
urma listei, iar banii lor se adună aparte: „Fără cost socotit — 2 produse”,
cu „nu intră la profit” la „Stare”. Marja se împarte numai la vânzările care
au cost. Cu cât pui mai multe rețete și prețuri, cu atât suma de deoparte
scade și profitul arătat se apropie de cel întreg.

### Ce scrie la „Stare”

| Vorba | Ce înseamnă | Ce e de făcut |
|---|---|---|
| nimic | costul e întreg, socotit din prețuri scrise | nimic |
| „≈ estimat” | o parte din vânzări sunt dinaintea primului preț scris al unei materii prime. Pentru zilele acelea s-a luat cel mai vechi preț cunoscut — cea mai bună părere despre ce a fost înaintea lui. Semnul „≈” stă pe cost, pe profit și pe marjă, nu pe bucăți și pe vânzări, care sunt numărate | nimic; dacă știi prețul de atunci, îl scrii cu ziua lui și semnul dispare. Foaia de preț primește însă cel mult 1100 de zile în urmă: la vânzări mai vechi de atât, semnul rămâne |
| „sub cost” | produsul s-a vândut cu mai puțin decât costă materiile lui; profitul are minus și e roșu | te uiți la prețul de vânzare ori la rețetă |
| „fără rețetă” | produsul n-are nicio linie de rețetă. Tot așa scrie la o poziție de comandă al cărei produs a fost șters între timp din catalog | îi scrii rețeta, din formularul produsului; la un produs șters din catalog nu mai e nimic de făcut |
| „lipsește prețul la: …” | o materie primă din rețetă, numită, n-are preț folosibil. Vorba e aceeași și când pricina nu e un preț nescris, ci o unitate de măsură schimbată ori o rețetă care se întoarce la ea însăși | care din trei e, o spune îndemnul de sub titlul listei (v. „Îndemnul de sub titlu”, mai jos): un preț nescris se scrie în Depozit, fila „Prețuri”; celelalte două au leacul lor |
| „fără cost socotit” | costul nu s-a putut socoti dintr-o altă pricină | te uiți la îndemnul de sub titlul listei |

Când o parte din cost e estimată, sub cifre scrie și *„≈ o parte din cost e
estimată: unele prețuri au fost scrise după ce s-au vândut produsele.”*

De ce „estimat” și nu „lipsă”, pentru zilele dinaintea primului preț:
prețurile încep să fie scrise în octombrie 2026, iar comenzile sunt în bază de
ani. Cu „lipsă”, statisticile lunilor trecute ar fi rămas goale pentru
totdeauna — nimeni nu scrie înapoi prețul făinii din martie.

Marja e scrisă pe o pastilă colorată, cu treptele hotărâte în 4–5 octombrie
2026: de la 60% în sus e bine (verde), între 30% și 60% e la mijloc
(chihlimbar), sub 30% e slab (roșu). Capetele țin de treapta de sus: 60,0% e
bine, 30,0% e la mijloc. O marjă sub zero cade tot la „slab”.

### Unde apare, în fiecare din cele trei vederi

| Vederea | Unde e profitul | Ce arată |
|---|---|---|
| „Inelul zilei” | o fișă a ei, după cele patru fișe cu cifre: cifra mare e profitul, cu „profit din materii prime” dedesubt | marja și vânzările din care e socotită („… marjă · din vânzări de …”); cu galben, când e cazul, *„… din vânzări n-au cost socotit — … produse”*; fraza cu rețeta de azi |
| „Registrul” | întrebarea „A ȘASEA”: *„Cât te costă și cât îți rămâne?”* | masa întreagă, produs cu produs |
| „Tabloul de dimineață” | lista *„Profitul, rând cu rând”* | aceeași masă |

Masa are coloanele „Produs · Bucăți · Vânzări · Cost · Profit · Marjă · Stare”.
Sub cost scrie și costul pe bucată („2,00 lei/buc”). Rândurile vin de la cel
mai mare profit în jos, cu cele fără cost socotit la urmă; capetele de coloană
nu sortează. Sub ele stau rândul „Toate cele cu cost socotit” și, când e
cazul, „Fără cost socotit — … produse”.

Totalul e rotunjit o singură dată, din sumele nerotunjite, deci poate să
difere cu un ban, doi de suma rândurilor adunate cu mâna. Când diferența chiar
e pe ecran, pagina o spune: *„Totalul e rotunjit o singură dată, din sumele
nerotunjite: de aceea diferă cu … de suma rândurilor de mai sus.”*

Fișa din „Inelul zilei” e buton: apăsată, deschide foaia „Profit din materii
prime”, cu titlul „Cât rămâne după materiile prime” și rândurile „Marjă”,
„Vânzări cu cost socotit”, „Costul materiilor prime” și, când e cazul,
„Vânzări fără cost socotit”. Sub ele scrie pe față ce nu e în cifră:
*„Profitul de aici scade numai materiile prime — nu și salariile, chiria ori
curentul. Produsele fără rețetă ori cu o materie primă fără preț nu intră la
socoteală: banii lor se văd aparte.”*

Când niciun produs vândut n-are cost, fișa nu scrie „0,00 lei”, ci o liniuță
și pricina: „niciun produs vândut n-are încă cost socotit”.

### Îndemnul de sub titlu

În „Registrul” și în „Tabloul de dimineață”, sub titlul listei, pagina spune ce
ține costurile pe loc. Vorba depinde de cine citește:

| Cine citește | Ce scrie |
|---|---|
| are și „Prețurile ingredientelor” | *„Pune preț la: … — în Depozit › Prețuri”*, cu legătură de-a dreptul la fila „Prețuri”; la un articol trecut pe altă unitate: *„scrie din nou prețul la: … (i s-a schimbat unitatea de măsură)”* |
| vede profitul, dar nu ține prețurile | numai ce lipsește, fără poruncă și fără legătură: *„Lipsește prețul la: ….”* ori *„La … s-a schimbat unitatea de măsură, prețul trebuie scris din nou.”* |
| oricare din ei, la o rețetă încurcată | *„Rețetele se cer una pe alta la: … — de îndreptat în rețetă.”* — se îndreaptă din Catalog, nu din fila „Prețuri” |
| oricare din ei, când pricina e alta | *„Costul nu se poate socoti cu: ….”*, cu numele articolului |

Îndemnul cu unitatea de măsură poate rămâne pe ecran și după ce ai scris, în
Depozit, un preț pe unitatea nouă: prețul acela e bun de la ziua lui încolo,
iar perioada la care te uiți are și zile de dinaintea ei. Leacul e rescrierea
prețului vechi, pe ziua lui (v. „Când unui articol i s-a schimbat unitatea de
măsură”, la „Prețurile de cumpărare, în Depozit”).

### Vânzarea nu mai e numită încasare

Tot la 2.7.0, câteva vorbe din Statistici s-au schimbat pentru toată lumea, în
toate cele trei vederi:

| Unde | Până la 2.7.0 | De la 2.7.0 |
|---|---|---|
| foaia care se deschide din cifra vânzărilor | „Banii intrați”, cu titlul „Cât a intrat în casă” | „Vânzări”, cu titlul „Cât s-a vândut” |
| în aceeași foaie, un rând | „Încă de încasat” | „Încă de livrat” |
| „Inelul zilei”, fișa vânzărilor | „intrat din comenzi” | „vândut prin comenzi” |
| „Inelul zilei”, fișa clientului | „clientul care a adus cei mai mulți bani” | „clientul care a cumpărat cel mai mult” |
| „Registrul”, întrebarea „ÎNTÂIA” | „Câți bani au intrat?” | „Cât s-a vândut?” |
| „Tabloul de dimineață”, placa vânzărilor | „Bani intrați” | „Vânzări” |

Tot așa, în două locuri mai mărunte: sub întrebarea „Cine cumpără cel mai
mult?”, din „Registrul”, scrie „După cât au cumpărat în perioada aleasă…” în
loc de „După banii aduși în perioada aleasă…”, iar în foaia clientului, „Din
tot ce s-a vândut” în loc de „Din tot ce a intrat”.

Cifrele sunt aceleași; numele erau greșite. Pagina numea vânzarea „bani
intrați”, deși o comandă livrată și neplătită e vânzare, nu ban în casă. Iar
„Încă de încasat” număra, de fapt, comenzile **nelivrate**. Cât nimeni nu
ținea socoteala plăților, vorba trecea. De la 2.7.0 casa are condica plăților
— pagina „Încasări” —, iar aceleași cuvinte pe două pagini ar fi însemnat două
sume deosebite. De aceea vânzarea se numește peste tot vânzare („vândut prin
comenzi”, „Cât s-a vândut?”, „Vânzări”), iar banii plătiți cu adevărat au
locul lor, sub numele lor.

Bagă de seamă, în „Registrul”: *„Câți bani au intrat cu adevărat?”* nu e
vechea „Câți bani au intrat?” cu două cuvinte în plus. Vechea întrebare,
despre vânzare, se cheamă acum „Cât s-a vândut?”; cea cu „cu adevărat” e
nouă, numără plățile și o vede numai cine are și pagina „Încasări”.

### Foaia „Încasări”

Locul banilor plătiți îl vede cine are pagina „Statistici” și pagina
„Încasări” — din pornire, numai fondatorul; se dau din „Acces”. Cine n-are
„Încasări” vede Statisticile fără nicio vorbă despre plăți.

| Vederea | Unde e | Ce arată |
|---|---|---|
| „Inelul zilei” | fișa „de încasat acum” | câte comenzi și câți clienți („… comenzi · … clienți”) ori *„Toate comenzile sunt plătite.”*; „încasat în perioadă: …”; amânările, cu cele cu termenul trecut; dedesubt, legătura **„Deschide Încasări →”** |
| „Registrul” | întrebarea *„Câți bani au intrat cu adevărat?”* — „A ȘAPTEA” pentru cine vede și profitul, „A ȘASEA” altfel | trei cifre — „lei încasați în perioadă”, „lei de încasat acum”, „zile până la plată, în medie” — și masa „Cine are de plătit” |
| „Tabloul de dimineață” | *„Încasările, rând cu rând”* | plăcile „Încasat în perioadă” (cu „direct … · prin bancă …”), „De încasat acum” și „Până la plată, în medie”, apoi aceeași masă |

Sub întrebarea din „Registrul” scrie, într-un rând, deosebirea pe care stă
toată partea: *„Ce s-a plătit, nu ce s-a vândut: o comandă livrată și neplătită
e vânzare, dar nu e încă ban în casă.”*

Masa „Cine are de plătit” are coloanele „Client · Comenzi · De plată · Cea mai
veche” și primii cinci datornici, de la cea mai mare sumă în jos. Numele e
legătură numai la clientul care are cont. Dacă sunt mai mulți de cinci,
dedesubt scrie *„și încă … clienți — toți sunt în Încasări”*.

Oricare din cifre, apăsată, deschide foaia **„Încasări”**, cu titlul „Cât s-a
încasat”:

| Rândul | Ce numără |
|---|---|
| cifra mare | ce s-a încasat în perioada aleasă |
| „Direct” și „Prin bancă” | aceeași sumă, despărțită după cum s-a plătit |
| „De încasat acum” | tot ce nu s-a plătit până azi, cu numărul de comenzi |
| „Din perioada asta, încă neplătit” | partea din vânzările perioadei care încă așteaptă plata |
| „Amânate” | câte plăți au fost amânate și cât fac |
| „Până la plată, în medie” | pentru plățile bifate în perioadă, câte zile au trecut, în medie, de la ziua livrării până la ziua plății (o plată bifată înaintea livrării se numără cu zero zile); o liniuță, dacă în perioadă nu s-a bifat nicio plată |

Trei lucruri fac cifrele de aici altfel decât restul paginii, și foaia le
spune pe toate:

- **„Încasat” se numără după ziua plății, nu a livrării.** *„„Încasat” numără
  plățile bifate în perioada aleasă, după ziua în care s-a bifat plata, nu
  după ziua livrării.”* O comandă livrată în septembrie și plătită în
  octombrie e vânzare de septembrie și încasare de octombrie.
- **„De încasat acum” nu ține de perioada aleasă.** *„„De încasat acum” e tot
  ce nu s-a plătit până azi, oricând ar fi fost livrat. Comenzile anulate nu
  intră.”*
- **Filtrele de sus nu se aplică.** Când e pus vreun filtru, scrie:
  *„Încasările nu țin seama de filtrele de sus: plata e a comenzii întregi, nu
  a unui produs ori a unui fel de marfă.”*

De aici nu se bifează nicio plată: Statisticile numai arată. Plățile se țin în
pagina „Încasări”, la care duce legătura „Deschide Încasări →”.

### Când Statisticile spun că cifrele nu sunt disponibile

| Ce scrie | Ce lipsește |
|---|---|
| *„Profitul din materii prime nu e încă disponibil: baza de date n-a primit actualizarea rundei 2.7.0.”* | tabelul prețurilor, adică `sql/034_costuri.sql` |
| *„Încasările nu se pot arăta: baza de date n-a primit actualizarea plăților (migrarea 032).”* | tabelul plăților, adică `sql/032_plati_si_prag.sql` |

Amândouă se repară pe server, nu din pagină (pașii sunt în
`docs/09-actualizare-vps.md`). Restul Statisticilor merge și fără ele.

---

## Trei prețuri, după cantitate

Până la 2.5.0 un produs avea un singur preț în fiecare catalog. Acum poate avea
trei: **A** pentru puține bucăți, **B** de la un prag, **C** de la al doilea.
Cine cumpără mai mult plătește mai puțin pe bucată — și vede asta înainte să
comande, nu abia pe factură.

| Bucăți | Treapta | De pildă |
|---|---|---|
| 1–99 | A | 4,20 lei |
| 100–299 | B | 3,90 lei |
| 300 și peste | C | 3,60 lei |

Cifrele sunt doar o pildă: fiecare produs își are pragurile lui. Un produs fără
B și C rămâne cu un singur preț, exact ca până acum — pe site, în coș și pe
factură nu se schimbă nimic la el.

### Unde se scriu

În formularul produsului, secțiunea **Prețuri, după cantitate**. Sus stă **un
singur rând de praguri** — „Prețul B începe de la … buc.” și „Prețul C începe
de la … buc.” —, iar sub el cele două panouri, fiecare cu **Preț A, Preț B și
Preț C**.

**Pragurile sunt ale produsului, prețurile ale fiecărui catalog.** Congelatele
și coaptele pot avea prețuri deosebite, dar B începe de la aceeași
cantitate în amândouă. Pus între cele două panouri, rândul pragurilor ar fi
părut al unuia; de-aia stă deasupra lor.

Sub fiecare casetă de preț scrie cine îl primește, socotit din pragurile de
deasupra: „1–99 buc.”, „100–299 buc.”, „300+ buc.”. O cifră fără „de la cât”
te-ar lăsa să ghicești.

Pragurile pornesc precompletate din **Setări → General, cardul „Catalogul”**
(100 și 300, cum le-a pus migrarea) — la un produs nou, și la unul vechi care
n-are încă praguri. Nu se aplică însă singure: rândul pragurilor stă șters cât
nu-l folosește niciun preț B sau C și **nu se salvează** fără ele. Un prag fără
prețul lui e doar o valoare de pornire.

### Ce se refuză

Formularul judecă treptele **pe măsură ce scrii**: caseta greșită se încercuiește
cu roșu, iar sub panoul ei scrie ce e de îndreptat. „Salvează” nu pleacă spre
server cât e ceva roșu, și spune prima greșeală: *„Prețurile după cantitate:
…”*. Regulile:

- **B mai mic decât A, și C mai mic decât B**, pe fiecare catalog: *„Prețul B
  (4,50 lei) trebuie să fie mai mic decât A (4,20 lei).”*;
- **C numai peste un B**, pe același catalog: *„Prețul C pentru congelate are
  nevoie de prețul B de acolo.”*;
- **un preț B cere pragul B**, un preț C pragul C: *„Scrie de la câte bucăți
  începe prețul B.”*;
- **pragurile sunt bucăți întregi, între 2 și 10000**, iar **C stă peste B**:
  *„Pragul C (80 buc.) trebuie să fie mai mare decât pragul B (100 buc.).”*
  Pragul 1 ar fi făcut din B prețul oricui.

Serverul judecă la fel, cu aceleași reguli, și refuză salvarea întreagă: paza
din pagină doar îți spune mai devreme.

Dacă cineva scrie totuși cu mâna în bază un B mai scump decât A, treapta aceea
**nu se aplică** deloc, și nici C de deasupra ei: o treaptă se aplică numai
întreagă și mai ieftină.

Pe o bază fără migrarea 028, B și C n-au unde se scrie. O salvare care aduce un
preț B ori C e refuzată pe față, cu numele migrării — altfel ai fi primit
„salvat”, iar clientul ar fi plătit tot A. Cu casetele B și C goale, produsul se
salvează ca înainte.

### Cum se numără bucățile

**Pe tot coșul, pe fiecare catalog în parte.** Treapta nu se alege după un rând,
ci după suma produsului din tot coșul: același produs poate sosi pe două rânduri
— trei bucăți din pachetul de test și o sută puse cu mâna —, iar clientul a
cumpărat o sută trei, nu trei și o sută.

Cataloagele se numără deosebit, fiindcă prețurile sunt ale fiecăruia: **60 de
congelate și 60 de coapte nu fac 120**, ci câte 60 în fiecare parte.

Treapta atinsă se plătește **pe toate bucățile**, nu numai pe cele de peste
prag: 150 de bucăți la B înseamnă 150 × 3,90, nu 99 × 4,20 + 51 × 3,90.

**Banii îi socotește serverul.** Pagina arată treapta ca omul să știe la ce să
se aștepte, dar suma comenzii o face serverul la trimitere, după aceleași reguli.
O pagină rămasă deschisă de ieri nu poate vinde la prețul de ieri.

### Ce vede clientul

În magazin **toate treptele se văd deodată**, pe fiecare card. Cum se așază
depinde de vedere (v. „Cum se vede catalogul”, mai jos):

| Unde | Cum arată scara |
|---|---|
| „Unul pe rând” și pagina produsului | trei căsuțe alăturate, sub „Preț pe bucată, după cantitate”: „1–99 buc. · 4,20 RON”, „100–299 buc. · 3,90 RON”, „300+ buc. · 3,60 RON” |
| „Patru pe ecran”, pe calculator | A stă în cutia catalogului, lângă A-ul celuilalt; sub „Preț pe bucată, după cantitate”, numai B și C: „100–299 · 3,90 RON”, „300+ · 3,60 RON” |
| „Șase pe ecran”, pe calculator | trei rânduri sub „Preț pe bucată, după cantitate”: „1–99”, „100–299”, „300+” |
| „Patru” și „Șase”, pe telefon și tabletă | în bula de peste poză, A rămâne prețul mare; dedesubt, pe scurt, numai B și C: „100+ buc. 3,90” („100+ 3,90” în „Șase”) |

**Treapta în care a ajuns coșul se aprinde în auriu.** Cu produsul lipsă din
coș nu se aprinde nimic: nimeni n-a ajuns încă nicăieri.

**Îndemnul.** Cât mai e o treaptă de urcat, sub scară scrie cât lipsește:
*„Încă 12 buc. și prețul scade la 3,90 RON/buc.”* Apare pe cardul din „unul pe
rând” (după ce produsul e în coș), în pagina produsului, pe rândurile din coș și
în „Produse selectate”, din formularul de comandă. Nu apare la pachetul de test,
și nici în treapta de sus, unde n-ar mai avea ce promite.

**Rândul din coș** spune prețul plătit și de unde vine: *„140 g · 3,90 RON/buc ·
preț de la 100 buc. · Congelate”*. Totalul, comanda minimă de 700 RON și textul
trimis pe WhatsApp socotesc tot cu prețurile treptelor.

### Pachetul de test

Reducerea pachetului de test (−20% la trei bucăți din fiecare produs, o singură
dată pe client) se socotește din prețul A. Bucățile lui **intră în
numărătoare**: trei din pachet și 97 puse cu mâna fac 100, deci prețul B. Iar
ele se plătesc cu **cel mai mic** dintre prețul pachetului și prețul treptei —
o reducere n-are voie să iasă mai scumpă decât volumul.

### O comandă corectată

Treapta se scrie pe fiecare rând al comenzii, cu pragul care a câștigat-o. La o
corectură, bucățile deja promise se mai judecă o dată, după cantitatea de acum:

| Ce s-a schimbat | Ce se întâmplă |
|---|---|
| cantitatea a rămas peste pragul promis | **promisiunea rămâne**, chiar dacă între timp ai scumpit ori ai mutat pragurile |
| cantitatea a urcat într-o treaptă mai bună | toate bucățile trec pe ea: 150 la B care ajung 350 se plătesc toate la C, dacă C e mai mic |
| cantitatea a coborât sub pragul promis | promisiunea cade: bucățile se plătesc la treapta de azi a cantității noi |
| bucăți adăugate | la treapta de azi |

Al treilea rând închide o portiță: fără el, „cer 150 la B, apoi tai la 90” ar fi
adus B pentru o cantitate care nu l-ar fi primit niciodată. Singura excepție e
un catalog care azi **n-are niciun preț**: atunci promisiunea rămâne, fiindcă
marfa e deja convenită, iar un refuz ar îngheța toată comanda.

Când o comandă păstrează un preț promis deosebit de cel de azi, Jurnalul o
spune, ca până acum: *„„Croissant” (congelat) × 150 onorat la 3,90 lei, în
catalog 4,20 lei”*.

Comenzile de dinainte de 2.5.0 n-au treapta scrisă, deci se citesc ca A:
prețul lor de atunci rămâne, iar o corectură care le urcă peste un prag le dă
treapta mai bună, ca oricărei alte comenzi.

### Unde se vede treapta

În magazin, pe carduri și în coș. În panou, **numai în formularul de comandă**
(v. „Banii, cât scrii comanda”, mai jos). Restul casei — Statisticile,
Recomandările, e-mailurile comenzii — citește prețul scris pe rând, ca până
acum: treapta e doar felul în care s-a ajuns la el.

De la 2.7.0, din același preț scris pe rând se scade și costul, la profitul
din Statistici (v. „Profitul din materii prime, în Statistici”). Costul unei
bucăți nu ține de treaptă, deci o vânzare la B ori la C lasă mai puțin pe
bucată decât una la A, iar marja produsului pe o perioadă e amestecul tuturor
treptelor la care s-a vândut. Lângă rețetă, în schimb, socoteala se face numai
cu prețul A.

---

## Cum se vede catalogul: vederi și filtre

### În magazin

Deasupra produselor, trei butoane desenate — **„Cum se vede catalogul”** — aleg
câte carduri stau pe rând:

| Vederea | Telefon (390) | Tabletă (820) | Calculator, cu coșul alături (1080) | Calculator (1440) |
|---|---|---|---|---|
| **Unul pe rând, cu toate detaliile** | 1 | 1 | 1 | 1 |
| **Patru pe ecran** | 2 | 4 | 2 | 3 |
| **Șase pe ecran** | 3 | 6 | 3 | 4 |

Numele vederilor spun cât de mult încape, nu numărul exact: de la runda 2.5.2
(29 septembrie 2026), pe calculator „Patru” are carduri **mai mari** decât
„Șase”, iar pe un ecran mai îngust grila pune câte încap, fără să strivească
vreun card. „Patru” pe calculator trece de la două la trei pe rând pe la
1130 de puncte, când cardul are loc pentru scara lui.

- **Unul pe rând** e cardul bogat: poza stă lângă text cât încap amândouă, și
  deasupra lui pe telefon; are descrierea întreagă, scara în trei căsuțe,
  îndemnul și rândul celuilalt catalog.
- **Patru pe ecran, pe calculator**: poză, nume, gramaj, descrierea pe cel
  mult trei rânduri, apoi **două cutii, câte una pe catalog**, fiecare cu
  semnul și prețul ei — cea a catalogului deschis e aurie, iar un catalog în
  care produsul nu e scrie *„nu se vinde”*. Când cardul e prea îngust ca să
  le țină alăturate, stau una sub alta. Dedesubt, scara, dacă are.
- **Șase pe ecran, pe calculator**: cardul pe care „Patru” îl avea până la
  2.5.2, neschimbat — poză, nume, gramaj, rândul celuilalt catalog,
  descrierea pe două rânduri, scara.
- **Patru și Șase, pe telefon și tabletă**: numele și prețul stau într-o
  **bulă întunecată peste poză**, iar sub poză o bară cu **„+ Adaugă”**, care
  după prima apăsare se face **„−”, cantitatea, „+”**. În „Patru” prețul
  are semnul catalogului, iar bula mai poartă prețul celuilalt catalog, cu
  semnul lui, și gramajul; în „Șase”, numai numele, prețul și scara, pe
  scurt. Poza e urcată cât să se vadă produsul deasupra
  bulei, iar apăsată oriunde — și pe bulă — deschide fereastra produsului.

**Rândul celuilalt catalog**, cu semnul lui, spune ce costă produsul de partea
cealaltă — *„Coapte: 4,20 RON”* — ori că e numai de-o parte: *„Doar
congelate”*.

**Cu ce se deschide.** Vederea de pornire o alegi tu, din **Setări → General,
cardul „Catalogul”**: una pentru telefon și tabletă, alta pentru calculator
(hotarul e 1080 de puncte). La pornire e „Patru pe ecran” pe amândouă; pe
telefon, de la 2.7.0 — până atunci era „Unul pe rând”.
Clientul își poate schimba vederea, iar alegerea lui se ține minte în browserul
lui, deosebit pe telefon și pe calculator — **până schimbi tu vederea din
Setări**: atunci schimbarea ajunge la toată lumea, și la cine își alesese alta
înainte.

**Filtrele**, deasupra produselor:

- **„Caută în catalog”** — caută în număr, în nume și în descriere, în amândouă
  limbile, fără să se uite la diacritice: „strudel” îl găsește pe „Ștrudel”;
- **„Grupe de produse”** — *Toate*, apoi câte o pastilă pe grupă, cu numărul de
  produse;
- **„Doar noutăți”** — numai dacă e vreun produs nou;
- **„Cu preț de volum”** — produsele cu B (ori și C) în catalogul deschis; numai
  dacă e măcar unul;
- **„Ordonează”** — *Recomandat · Nume, A–Z · Preț crescător · Preț
  descrescător*. Prețul e A, iar ordinea se face înăuntrul fiecărei grupe:
  grupele rămân în ordinea lor.

Când nu se potrivește nimic, pagina scrie **„Niciun produs nu se potrivește”**,
cu un buton **„Șterge filtrele”**. Pe calculator filtrele stau la vedere; pe
telefon și tabletă se deschid din butonul **„Filtre”**, chiar sub bara de unelte
— „Filtre · 2” spune câte sunt aprinse (căutarea nu se numără: se vede oricum).

### În panoul Catalog

Aceleași trei desene, sub numele **„Cum se arată produsele”**: „Unul pe rând”
e lista de rânduri de până acum, „Patru” și „Șase” sunt plăci. Vederea se ține
minte în browser și pornește pe „Unul pe rând”; **Setările nu o ating**, fiindcă
ele sunt pentru clienți.

Lângă **„Congelate | Coapte | Amândouă”** stau filtrele panoului:

- **grupa** — *Toate grupele* ori una singură;
- **ordinea** — *Ordinea din magazin · După nume · Prețul A, crescător · Prețul
  A, descrescător*; produsele fără preț stau la coadă;
- câte o pastilă, una singură aprinsă deodată: *Toate · Cu preț de volum · Doar
  prețul A · Noutăți · Scoase din catalog · De reparat*, fiecare cu numărul ei.
  „Doar prețul A” sunt produsele cărora li se pot pune praguri; „De reparat”,
  cele cu semnul roșu de pe rând. O pastilă cu zero e stinsă.

**„Arată tot”** le stinge pe toate deodată. Până la 1000 de puncte filtrele stau
strânse sub butonul **„Filtre”**. Filtrele panoului nu se țin minte: la
redeschidere pagina arată tot catalogul.

Pe rânduri și pe plăci, prețurile stau într-un tabel mic, **câte un rând pe
catalog și câte o coloană pe treaptă** — „1+”, „100+”, „300+” —, cu A îngroșat
și „—” unde treapta lipsește. Amândouă cataloagele se văd și pe comutatorul unui
singur catalog: oglinda se citește tocmai punând cifrele una sub alta. Un produs
cu un singur preț pe catalog arată ca înainte: „2,85 lei”, fără tabel.

---

## Numărul din colțul pozei

Fiecare produs poartă în magazin un număr în colțul din stânga-sus al pozei:
**01**, **02**, **03**… Clientul îl spune la telefon și îl scrie pe WhatsApp,
iar numărul pleacă și în mesajul comenzii, înaintea numelui: *„3 × 7. Croasant
cu unt”*. În panou stă înaintea numelui, pe fiecare rând, și în colțul pozei,
pe plăci.

Până la 2.5.1 numărul era cheia produsului din baza de date: nu se putea
alege, iar un produs șters lăsa în urmă un gol. De-acum îl alegi tu, din
formularul produsului, la **„Număr în catalog”** — orice număr întreg de la 1
la 999.

**Numărul e și locul produsului.** Magazinul și panoul așază produsele după el,
înăuntrul fiecărei grupe: „07” stă mereu înaintea lui „08”. Asta e ordinea
**„Recomandat”** din magazin și **„Ordinea din magazin”** din panou.

### Un produs nou

Primește numărul de după cel mai mare din catalog, deci, de la sine, numerele
urmează ordinea în care sunt puse produsele. Caseta e deja scrisă cu el, iar
lângă ea scrie *„Următorul după ultimul produs — ordinea în care sunt puse”*.
Dacă vrei produsul mai în față, scrii alt număr înainte de „Adaugă în catalog”.

### Un număr liber, un număr ocupat

Scrii un număr, iar lângă casetă apare, **înainte de salvare**, ce se va
întâmpla:

- **Numărul e liber** — *„12 e liber — nu se mută niciun alt produs.”* Se mută
  numai produsul tău.
- **Numărul e ocupat** — nu se refuză. Produsul tău intră acolo, iar cele de la
  rând **se dau cu un loc**, ca într-o listă în care muți un rând. Lămurirea
  spune cine se mută, cu numele: *„07 e acum la „Croasant cu unt”. La salvare se
  dau cu un loc 12 produse (07–18 → 08–19), de la „Croasant cu unt” până la
  „Trigon cu mere”.”*

| Ce faci | Ce se întâmplă |
|---|---|
| muți produsul **mai în față**, de pe 19 pe 07 | 07–18 trec pe 08–19, în locul lăsat de el |
| muți produsul **mai în spate**, de pe 07 pe 19 | 08–19 trec pe 07–18, în locul lăsat de el |
| pui un produs **nou** pe un număr ocupat | cele de la el încolo trec cu un loc mai în spate |

Șirul care se dă cu un loc se oprește la primul număr liber: dacă între 07 și
19 e un gol, golul se umple, iar ce e după el rămâne pe loc.

După salvare, mesajul de sus le numără: *„Produsul a fost salvat, cu numărul
07. Alte 12 produse s-au dat cu un loc.”*

Un număr care nu e întreg, zero ori peste 999 nu pleacă: caseta se face roșie,
cu *„Scrie un număr întreg de la 1 la 999 — cel din colțul pozei, în
magazin.”*

### Un produs șters, unul scos din catalog

**Șters**, își lasă numărul gol: ștergi 08, rămân 07 și 09. Numerele pe care
le știu clienții nu se schimbă din cauza unei ștergeri, iar produsul nou nu
umple golul — ia numărul de după cel mai mare. Dacă vrei 08 plin, muți acolo un
produs.

**Scos din catalog**, rămâne în numărătoare: își ține locul, iar în magazin,
cât stă scos, locul lui arată ca un gol. Repus cu **„Repune în catalog”**, se
întoarce acolo.

### La urcarea pe server

Migrarea `029` dă numerele o singură dată, în ordinea în care au fost puse
produsele: 01, 02, 03… fără goluri. Pe 26 septembrie 2026, cele 22 de produse
din magazin aveau numerele 01–22, fără niciun gol — urcată atunci, migrarea nu
schimbă **niciun număr pe care îl văd clienții**. Un produs șters între timp
ar închide golul: fiecare produs de după el ar primi un număr cu unu mai mic.
Coșurile deschise, pozele și comenzile vechi nu se ating: ele merg pe cheia
produsului, nu pe numărul lui.

### Unde se vede

În colțul pozei, în toate trei vederile magazinului. Până la 2.5.2, în
**„Șase pe ecran”** nu apărea: cardul de atunci era prea mic ca să mai poarte
ceva peste poză. Pe card are zero în față („03”); în coș
și în mesajul de WhatsApp, nu („3. Ștrudel cu dovleac”), ca și până acum.

---

## Scoaterea unui produs

Rândul produsului are două butoane, pentru două fapte deosebite.

**„Scoate din catalog”** — produsul nu se mai vinde nici în magazin, nici pe o
comandă nouă, dar rămâne în listă. Butonul se face atunci **„Repune în
catalog”**, care îl pune la loc oricând. Nu șterge niciodată nimic.

**×** (*„Șterge produsul din catalog”*) — deschide întâi o fereastră care spune
dinainte ce urmează, în funcție de istoric:

- **produs care nu apare în nicio comandă și n-are articol în depozit** —
  fereastra întreabă *„Ștergi „…”?”*, cu **„Șterge definitiv”** și
  **„Renunță”**. Șters, dispare de tot, cu fotografiile și fișele tehnice urcate
  din Catalog cu tot (fișele din pachet rămân pe disc). Ștergerea nu se poate
  desface: ca să-l ai înapoi, îl adaugi din nou cu **+ Produs nou**;
- **produs care apare în comenzi sau în depozit** — fereastra spune *„„…” nu
  se poate șterge”* și unde apare, iar în locul butonului roșu oferă **„Scoate
  din catalog”**, lângă „Închide”. Scos, dispare de pe site și din formulare;
  comenzile vechi își păstrează denumirea și prețul de atunci, iar rapoartele
  nu rămân cu goluri.

---

## Grupele

Fila **Grupe**. Fiecare are nume și subtitlu în ambele limbi, plus o ordine —
aceeași în care apar pe site. Numele și subtitlurile se salvează când ieși din
câmp; săgețile **↑** și **↓** schimbă locul grupei cu vecina ei.

Două protecții:

- o grupă cu produse în ea **nu poate fi ștearsă** — nici dacă produsele sunt
  doar scoase din catalog. Ca s-o ștergi, mută întâi produsele în altă grupă,
  din formularul fiecărui produs (caseta **„Grupa”**);
- o grupă cu produse active **nu poate fi ascunsă** — altfel produsele ar
  dispărea de pe site fără să pară scoase din catalog. Pe fila Grupe nu există
  buton de ascuns o grupă; protecția e a serverului, care refuză orice cerere de
  felul ăsta cât grupa mai are produse active.

**× apare numai la grupele goale.** Butonul se cheamă „Șterge grupa” (așa îl
citește și un cititor de ecran) și stă numai pe rândul unei grupe fără niciun
produs, nici scos din catalog. Pe celelalte rânduri, în locul lui, e un gol de
aceeași mărime, ca rândurile să rămână aliniate. Capul filei o spune și el:
*„Ordinea de aici e ordinea în care apar pe site. O grupă care are produse în
ea nu poate fi ștearsă — nici dacă produsele sunt doar scoase din catalog —,
așa că × apare numai la grupele goale.”* Apăsat, × șterge grupa pe loc, fără
altă întrebare, iar sus scrie *„Grupa „…” a fost ștearsă.”*

Ștergerea nu are buton de întors. Dacă ai șters o grupă din greșeală, o faci
la loc din rândul de adăugat de la capătul filei, cu aceleași două nume: apare
ultima în listă, o urci cu **↑** până la locul ei și îi scrii din nou
subtitlurile. Fiindcă grupa era goală, niciun produs n-a pierdut nimic.

> Până la 2.6.0, × stătea pe fiecare rând, iar apăsat pe o grupă plină aducea
> numai refuzul serverului.

Insigna de pe rând spune câte produse are grupa: `3 produse` cât timp toate sunt
în catalog, și `0 în catalog · 3 scoase` când au rămas doar rânduri scoase.
Cifra a doua e chiar cea după care se hotărăște dacă apare ×. La început
insigna arăta doar produsele din catalog, deci o grupă golită scria „0
produse”, iar la × răspundea „grupa are 3 produse în ea” — două cifre care se
contraziceau pe același ecran, fără să ai de unde ști de unde ies cele trei.

**Rândul de adăugat** — „Grupă nouă (RO)”, „New group (EN)” și **„Adaugă”** —
apare numai după ce catalogul s-a încărcat. Cât pagina încă întreabă, ori cât
stă pe ecran caseta roșie *„Catalogul nu s-a încărcat.”*, lipsește, la fel ca
**„+ Produs nou”**: pagina nu știe atunci nici al cui e contul, nici ce grupe
sunt deja.

De la 2.7.0, nici numele scrise în rândul ăsta nu se pierd fără o întrebare.
Dacă ai scris un nume și pleci din pagină înainte să apeși „Adaugă” — o
legătură din meniu ori ieșirea din cont —, pagina întreabă *„Ieși fără să
adaugi grupa?”*, cu lămurirea *„Numele scrise pentru grupa nouă nu au fost
adăugate și se pierd dacă ieși.”* **„Rămân în pagină”** te lasă să apeși
„Adaugă”; **„Ies fără să adaug”** pleacă, iar numele scrise nu se păstrează
nicăieri — le scrii din nou când te întorci. La închiderea filei ori la
reîncărcare întreabă browserul, cu vorbele lui.

Cât grupa e pe drum spre server, întrebarea e alta: *„Pleci cât se adaugă
grupa?”*, cu *„Grupa e pe drum. Dacă pleci acum, nu mai vezi aici dacă a fost
adăugată.”* și cu butoanele **„Rămân până se adaugă”** și **„Plec oricum”**.
Dacă ai plecat, te întorci pe fila Grupe și te uiți dacă e în listă, înainte
s-o adaugi a doua oară.

Întrebarea asta o pune numai plecarea din pagină. Ieșirile formularului de
produs și „Înapoi” închid o fereastră, nu pagina, deci numele grupei rămân în
casetele lor și nu au de ce întreba. Iar dacă ai și un produs scris în
formular, și un nume de grupă, plecarea întreabă o singură dată, despre
produs, și adaugă: *„Se pierd și numele scrise la „Grupă nouă”.”*

---
## Catalogul, în formularul de comandă

`lab.goldenpastry.ro/comenzi` — la fiecare poziție a comenzii.

Până acum, „Nume produs” era o casetă de scris liberă. Numele scris trebuia să
nimerească **exact** un produs din catalog, altfel salvarea era refuzată la
sfârșit, după ce scriseseși toată comanda: *„Produsele «X» nu există în
catalog”*. Refuzul era corect, dar venea prea târziu și fără să-ți spună ce să
scrii în loc.

Acum catalogul vine în întâmpinare, în trei feluri.

### Scrii două litere, îți arată ce ai

Sub casetă apare o listă scurtă cu produsele care se potrivesc: **numele,
gramajul și prețul bucății** — prețul variantei alese pe poziție, congelat sau
copt. La un produs cu trepte, prețul se scrie de la A până la cel mai mic:
*„140 g · 4,20 → 3,60 RON”*. „cr” scoate croissantele. Potrivirea nu se uită la diacritice și nici la
majuscule, și caută oriunde în nume, nu doar la început.

Alegi unul din listă și poziția rămâne legată de produsul acela din catalog,
nu de numele lui scris. Dacă produsul e redenumit mâine, comanda nu se rupe.

### Sau deschizi lista întreagă

Lângă **+ Adaugă poziție**, înaintea lui, stă butonul **„Din catalog”**. E
pentru când clientul îți spune „ceva cu mere” și nu știi cum se cheamă în
catalog.

De la 2.6.0, fereastra „Din catalog” arată ca vitrina magazinului: carduri cu
poza în față și cu numărul în colț. Până atunci era o listă îngustă, cu poze
mici, pe latura formularului — iar un formular nou n-are de unde alege latura,
deci din fereastră se puteau pune numai produse coapte.

**Latura.** Sus stă comutatorul **„Congelate” / „Coapte”**, ca în magazin.
Fereastra arată numai ce se vinde pe latura deschisă, cu prețurile și pozele
ei, iar produsul pus de aici intră pe comandă chiar pe latura aceea. Sub titlu
scrie *„Prețurile și pozele sunt ale laturii deschise. Pe comandă: 2 la
Congelate · 1 la Coapte.”* ori, la început, *„… Comanda n-are încă nicio
poziție.”* Pe fiecare jumătate a comutatorului stă câte poziții are comanda pe
latura ei: o comandă amestecată nu are voie să pară goală fiindcă te uiți la
latura cealaltă.

La prima deschidere, fereastra pornește pe latura pe care comanda are mai
multe bucăți; la egalitate, pe latura comenzii, iar la o comandă nouă, încă
goală, pe „Congelate”. După aceea rămâne unde ai lăsat-o, cât ține formularul.

**Căutarea și grupele.** Caseta *„Caută după nume sau număr”* caută în numărul
din colțul pozei, în nume (românesc ori englezesc) și în numele grupei, fără
să se uite la diacritice: „strudel” găsește „Ștrudel”, iar „07” găsește
produsul cu numărul 07. Sub ea stau pastilele grupelor — „Toate” și câte una
de grupă, fiecare cu numărul ei de produse pe latura deschisă. Apar numai când
sunt cel puțin două grupe cu ceva de arătat.

**Cardul.** Poza laturii deschise, numărul din colț, numele, gramajul și
prețul bucății. Un produs fără fotografie pe latura asta poartă sigla casei,
nu poza celeilalte laturi.

La un produs cu trepte, prețul scris mare e cel al treptei la care a ajuns
produsul pe comandă, iar dedesubt, stins, stă scara: *„4,20 RON”*, cu *„3,90 de
la 100 · 3,60 de la 300”* sub el. După ce ai pus 100 de bucăți, prețul mare
devine *„3,90 RON (preț B)”*, iar scara rămâne pe loc. Cantitatea e cea a
produsului pe toată comanda, pe latura deschisă, cum o numără și serverul.

**Ce ai pus deja se vede, cu cantitate cu tot.** Produsele care au cel puțin o
bucată pe comandă stau pe **fundal de miere, cu chenar auriu**, iar cifra lor e
scrisă în casetă. Fără asta, omul care deschide catalogul a doua oară nu-și mai
amintește ce a pus și pune încă o dată.

Cantitatea se schimbă din bara de jos a cardului, în trei feluri:

- **+** — încă o bucată. Cardul însuși nu e buton: o apăsare pe poză ori pe
  nume nu pune nimic;
- **−** — una mai puțin; la zero, poziția dispare cu totul din comandă, fiindcă
  un rând cu „0” pe el nu e o poziție, e un gunoi pe care l-ai fi șters cu mâna;
- **scrii cifra** de-a dreptul în casetă — cât ai scris, atât rămâne; „0” scoate
  produsul.

Cifra din fereastră vorbește despre **produs**, nu despre un rând anume: dacă
ai scris croissante pe două poziții, ea le adună. Scrisă cu mâna, le și strânge
la un loc, într-o singură poziție — altfel suma de deasupra n-ar mai fi cea
tastată. Iar „+” pe un produs deja pus îngroașă poziția lui, nu face una nouă:
serverul oricum lipește la tarifare pozițiile alăturate cu același produs și
aceeași variantă, deci lista nu se umple cu rânduri care s-ar strânge la loc pe
bonul clientului.

Rândul gol cu care se deschide un formular nou **se umple**, nu se sare. Altfel
fiecare comandă ar începe cu o poziție fără nimic în ea.

„+” lipsește într-un singur caz: pe o poziție rămasă pe comandă de pe o latură
pe care produsul nu mai are preț. Serverul n-ar primi o bucată în plus, deci
poziția aceea se poate numai scădea ori scoate.

**Ieșirea.** Jos scrie câte poziții are comanda, pe amândouă laturile la un
loc, lângă butonul **„Gata”**. „Gata”, **×** din colț și o apăsare pe fundalul
din jurul ferestrei fac același lucru: o închid. Aici nu e nimic de salvat și
nimic de pierdut — ce ai pus e deja pe pozițiile formularului, iar comanda se
scrie abia la salvarea lui. O apăsare greșită se desface cu „−”, ori ștergând
poziția din formular. La închidere, căutarea și grupa aleasă se golesc; latura
rămâne.

Când nu are ce arăta, fereastra spune de ce:

| Ce scrie | Ce înseamnă |
|---|---|
| *„Nimic la Congelate pentru „…”. Caută și la Coapte, ori șterge căutarea.”* | căutarea n-a nimerit nimic pe latura deschisă; de cele mai multe ori produsul e pe cealaltă |
| *„Niciun produs nu se vinde acum la Coapte. Latura unui produs se aprinde din „Catalog”.”* | niciun produs n-are latura asta aprinsă (v. „Cele două cataloage”) |
| cu galben: *„Catalogul nu a venit de la server. Scrie numele produsului în caseta lui — salvarea spune dacă nimerește.”* | cererea catalogului a căzut. Formularul merge mai departe, cu numele scris de mână |

### Ce nu e în catalog merge în „Mențiuni”

Caseta nu mai primește nume care nu există în catalog. O cerere ieșită din
comun — un tort scris pe comandă, o ambalare anume — se scrie în **Mențiuni**,
unde a fost locul ei dintotdeauna. Refuzul de la salvare a rămas pe loc, ca
plasă de siguranță; doar că acum nu mai are ce prinde.

---

## Banii, cât scrii comanda

Fiecare poziție arată **preț/bucată × cantitate = sumă**, iar antetul
formularului arată totalul lângă numărul de bucăți. Lista de comenzi arată și
ea suma fiecărei comenzi.

Cifrele **nu se socotesc în pagină**. La fiecare schimbare — alt produs, altă
cantitate, altă variantă — formularul cere serverului să tarifeze, iar pe ecran
ajunge exact ce s-ar scrie în bază dacă ai salva în clipa aceea — cu „Adaugă
comanda”, la una nouă, ori cu „Salvează modificările”, la una deschisă din
listă. Pricina e chiar cea din capul acestui ghid: o înmulțire făcută în
browser ar minți la prima comandă **editată**.

Când deschizi o comandă veche și îi adaugi o bucată, bucățile de dinainte își
păstrează prețul promis atunci; numai bucata nouă ia prețul de azi. O socoteală
din pagină ar fi înmulțit tot cu prețul de azi și ți-ar fi arătat o sumă cu care
clientul nu fusese de acord.

**Treapta se vede pe rândul de bani.** O poziție plătită la B ori la C își spune
treapta și pragul care a câștigat-o: *„150 × 3,90 (preț B, de la 100 buc.)”*.
Pragul e cel promis atunci, adus de server, nu cel de azi din fișa produsului:
o comandă veche își păstrează treapta chiar dacă între timp ai mutat pragul.
Ce se întâmplă cu treapta când schimbi cantitatea scrie la „O comandă
corectată”, mai sus; formularul arată exact rezultatul acela, fiindcă îl cere
serverului.

Cât răspunsul e pe drum, cifra veche stă **stinsă**. O sumă despre care nu se
știe încă dacă mai e adevărată nu se arată ca și cum ar fi.

Dacă serverul e mai vechi și nu știe să tarifeze, formularul **nu arată bani**
și merge mai departe: nu refuză nicio salvare.

**Suma de aici e vânzarea, nu plata.** De la 2.7.0, casa ține și socoteala
banilor plătiți cu adevărat, în pagina „Încasări”; formularul comenzii arată
mai departe cât face marfa, nu cât s-a încasat pe ea. Cele două se întâlnesc
într-un singur loc: clientul care are prea multe comenzi neplătite nu mai
primește una nouă. Refuzul e al serverului și vine la salvare, cu lista
comenzilor neplătite; formularul rămâne deschis, cu tot ce ai scris. Ca să
nu scrii o comandă întreagă degeaba, formularul o spune din timp, pe un rând
de sub clientul ales — de pildă *„Are 2 comenzi neplătite (…) — pragul e 2.
Comanda nu se va putea scrie.”* Rândul e numai o vorbă: nu stinge butonul de
salvare, fiindcă până apeși clientul poate plăti.

Refuzul nu e același pentru oricine. Cine are fapta „Trece peste prag” — din
pornire, numai fondatorul; se dă din pagina „Acces”, unui rol care are și
panoul „Comenzi” — vede la capătul rândului și *„Poți trece peste prag la
salvare.”* La salvare, refuzul lui vine cu o întrebare: *„Treci peste prag
pentru …?”*, cu numele clientului și cu butoanele **„Nu”** și **„Da, scrie
comanda”**. „Da, scrie comanda” scrie comanda peste prag, iar trecerea rămâne
în „Jurnal”, pe numele clientului („Peste prag pentru „…”: …”). „Nu” lasă
comanda nescrisă. O comandă scrisă așa din greșeală se anulează ca oricare
alta; rândul din „Jurnal” rămâne.

Cum se bifează o plată și cum se schimbă pragul unui client nu ține de catalog
și nu e povestit aici: sunt în `docs/02-panoul-de-setari.md`, la „Plățile și
pagina „Încasări”” și la „Pragul de comenzi neplătite”.

---

## Ce vede Google

`goldenpastry.ro` este servit de `index.php`, care citește catalogul din baza de
date și trimite din prima cerere:

- titlul și descrierea paginii, cu numărul real de produse și adresa;
- imaginea de partajare — ce se vede când cineva dă linkul pe WhatsApp;
- datele structurate **schema.org**: firma, adresa, coordonatele, telefonul,
  zonele deservite și catalogul complet cu prețuri. Prețul unui produs
  (`offers.price`) rămâne A, cifra pe care o vede omul cu coșul gol; un produs
  cu trepte mai primește, alături, câte un `UnitPriceSpecification` pe treaptă,
  cu cantitatea care o primește (`eligibleQuantity`, în bucăți). Treptele se
  scriu pentru catalogul în care se deschide produsul;
- catalogul ca text, pentru cine nu rulează JavaScript. Pe pagina unui produs
  cu fișă tehnică, textul acesta are și legătura *„Fișa tehnică (PDF)”*
  (*„Technical data sheet (PDF, in Romanian)”* pe engleză), spre fișa
  catalogului în care se deschide produsul: butonul din magazin se desenează
  în browser, deci fără JavaScript nu s-ar vedea;
- `sitemap.xml`, construit tot din baza de date.

Fișele tehnice însele **nu intră în rezultatele căutării**: PDF-urile poartă un
semn care le cere motoarelor de căutare să nu le pună acolo. Fișa e un document
de dat clientului, nu o pagină de găsit pe Google — acolo ar apărea fără
magazinul în jurul ei, iar pe alocuri tipărește alt gramaj decât site-ul (v.
„Fișele din pachet”, mai sus).

Aplicația interactivă rămâne exact cum era — se încarcă peste.

Nimic din toate astea nu trebuie întreținut separat: schimbi un preț din pagina
Catalog și se schimbă și în ce vede Google.
