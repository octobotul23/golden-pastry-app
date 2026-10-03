# Catalogul de produse

`lab.goldenpastry.ro/produse` — doar pentru administratori.

De aici adaugi, modifici și scoți produse și grupe. Ce salvezi apare imediat pe
`goldenpastry.ro`, în formularul de comandă și în datele pe care le citește
Google.

---

## Cine intră

Catalogul se deschide numai pentru **administrator** și **fondator**. Oricare
alt cont — managerul, agentul de vânzări, angajatul, omul de laborator, tableta
comună din laborator, clientul — primește în locul paginii un ecran cu o
singură propoziție, cu numele contului între ghilimele: *„Contul „…” nu are
acces la catalog. Prețurile și produsele le schimbă doar administratorii.”*

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

> Până la 2.6.0 ecranul avea două legături scrise la fel pentru oricine:
> „Comenzi” și „← Alege panoul”. Pentru angajat, pentru omul de laborator, pentru
> client și pentru tabletă, „Comenzi” era al doilea refuz la rând — niciunul n-are
> panoul acela —, iar clientul n-are niciun panou de ales.

Ecranul de refuz se aprinde numai după ce pagina a aflat al cui e contul. Dacă
sesiunea nu poate fi citită, apare în loc caseta roșie *„Catalogul nu s-a
încărcat.”*, cu butonul **„Încearcă din nou”** — pagina n-a aflat nimic, deci
nu dă niciun verdict despre cont.

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
trimită nimic.
Dacă un pas se oprește la jumătate, formularul rămâne deschis — v. „Când
salvarea se oprește la jumătate”, mai jos.

### Cele două cataloage

| Pe site | Semnul | În formular | Ce înseamnă |
|---|---|---|---|
| Congelate | fulg albastru | panoul „Congelate" | livrat în lanț frigorific |
| Coapte | flacără portocalie | panoul „Coapte" | copt la noi, livrat gata de vitrină |

Fiecare catalog are în formular **panoul lui**: semnul, numele, comutatorul
**În catalog** și trei casete de preț, **A, B și C**. A e prețul dintotdeauna;
B și C sunt prețurile de volum și pot rămâne goale — v. „Trei prețuri, după
cantitate", mai jos. Un produs poate fi numai într-unul din cele două — asta e
chiar rostul comutatoarelor: la coapte sunt mai multe produse decât la
congelate, și sunt grupe care se văd numai acolo.

> Până pe 26 septembrie 2026 al doilea catalog se chema „Gata de servire".
> Numele s-a schimbat numai pe ecran: comenzile, prețurile și pozele sunt
> aceleași. Unde numește catalogul, vorba e la plural — „Congelate",
> „Coapte" —, iar unde numește marfa unui rând (cardurile din Laborator,
> capetele de coloană din Statistici), la singular — „Congelat", „Copt".
> În engleză, „Frozen" și „Baked".

Trei lucruri de ținut minte, fiindcă altfel par scăpări:

- **scoaterea dintr-un catalog nu șterge prețurile.** Casetele rămân scrise,
  doar șterse la vedere; comutatorul le pune la loc fără să le cauți;
- **un comutator aprins cere prețul A de acolo.** Dacă îl aprinzi fără preț,
  salvarea e refuzată și mesajul spune care catalog. Poți aprinde comutatorul și
  scrie prețul în aceeași salvare;
- **un produs trebuie să fie în cel puțin un catalog.** Dacă nu se mai vinde
  deloc, nu stinge amândouă comutatoarele — apasă „Scoate din catalog" pe rândul
  lui. Aia e fapta care se numește așa, și se poate întoarce oricând.

Pe rândurile din listă, cele două comutatoare sunt **pastile**, fiecare cu
semnul lui: arată dintr-o privire unde e fiecare produs și se apasă ca să-l
muți. Deasupra listei, **„Congelate | Coapte | Amândouă"** taie lista
pe un catalog sau le arată pe amândouă — pe „Amândouă" se vede oglinda, adică
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
> „Preț congelat" ori „Preț copt".

**Prețul depinde de variantă, nu de client.** Asta a fost o corecție: coloanele
se numeau `price_horeca` și `price_retail`, iar serverul alegea prețul după cum
cel care comanda are cont sau nu. Era o citire greșită a catalogului. Efectul se
vedea în bani: un vizitator alegea „Congelate", vedea 28,50 lei pe ecran, și
cererea se înregistra cu 34,90.

Varianta aleasă se păstrează pe comandă și apare pe cardul din laborator, ca
echipa să știe ce pregătește.

Câmpul primește și `2,85`, și `2.85` — scrie cum îți vine la îndemână. Ce **nu**
primește este un text care nu e număr („la cerere") sau un zero: le refuză pe
loc, cu un mesaj care cere cifre. Înainte, orice text însemna tăcut 0,00 lei,
iar produsul se publica pe gratis; prima dată când cineva îl comanda, comanda
intra cu 0 lei și nimeni nu vedea de unde vine.

### Fotografiile

**Două casete, una de fiecare catalog:** „Fotografie — congelat" și
„Fotografie — copt". JPEG, PNG sau WebP, maximum 10 MB. Alegi
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

Rețetele sunt **pe un singur nivel**. O „cutie cadou" care conține croissante
trece croissantul ca linie de rețetă, pe articolul lui finit din depozit, și îl
scade direct de acolo. Nu are nevoie de rețeta croissantului.

**Fiecare produs vândut are nevoie de articolul lui finit în Depozit** — un
articol legat de produs, care adună bucățile făcute și din care scade comanda
predată. Dacă lipsește, formularul ți-o spune printr-o casetă galbenă: fără el,
bucățile făcute n-au unde să se adune, iar comanda rămâne nescăzută la
„Predată". Legătura se face din Depozit, pe articol, și se poate face și mai
târziu, pe un articol care există de mult.

Un produs **fără** rețetă nu e o greșeală: la producție pur și simplu nu se
scade nimic din depozit pentru el. Se poate lucra așa, cu rețete puse pe rând,
pe măsură ce le socotești.

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

---

### Cine consumă rețeta: laboratorul

Rețeta nu scade nimic singură. Ea așteaptă ca cineva din tură să spună **câte
bucăți a făcut**, iar asta se întâmplă în **Panoul de laborator**: deschizi o
comandă și fiecare poziție are un pas — **−**, cât s-a făcut din cât s-a cerut,
**+**. Pătratul din stânga rândului trece poziția întreagă pe „gata" dintr-o
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
  „Corecție producție". Un rând greșit se îndreaptă cu contra-rândul, nu se
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

### Ce înseamnă „Rezervat" și „Disponibil" în Depozit

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
  două ori și „Disponibil" ar arăta mai puțin decât ai;
- **la „Predată"** — comanda încheiată nu mai ține nimic, nici marfă gata, nici
  materie primă;
- **la anulare** — la fel: marfa se eliberează pentru altcineva.

Marfa gata rămâne promisă până pleacă din patiserie. Croissantul făcut e tot al
comenzii lui, chiar dacă stă în congelator.

**„Disponibil" poate fi negativ**, și e bine că poate: înseamnă că s-a promis mai
mult decât e pe raft. Rândul se face roșu și primește eticheta „Lipsă", iar
cartonașul „Lipsă la comenzi" din capul paginii numără articolele aflate așa.
Asta e informația pentru care există coloana.

Cifrele se socotesc pe server, din toate comenzile. Înainte se adunau în pagină,
din comenzile pe care apuca să le aducă — cel mult 500 — deci peste atâtea
comenzi „Rezervat" scădea fără ca nimic să spună că s-a numărat doar o parte.

La fel se numără bannerul **„Scade din stoc”**: câte comenzi „Predată” n-au fost
încă scăzute, și câte bucăți au, vine de pe server, din toate comenzile. Socotit
în pagină, bannerul tăcea tocmai pentru comenzile vechi rămase pe loc — oprite
de un articol lipsă sau redeschise de o corectură —, deși butonul le-ar fi scăzut.

Un articol se leagă de produsul lui din **Depozit → articolul → Produs**. Fără
legătura asta, promisiunea unui produs redenumit între timp nu-și mai găsește
raftul: numele produsului se schimbă din Catalog, numele articolului de depozit
nu se atinge.

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
singur rând de praguri** — „Prețul B începe de la … buc." și „Prețul C începe
de la … buc." —, iar sub el cele două panouri, fiecare cu **Preț A, Preț B și
Preț C**.

**Pragurile sunt ale produsului, prețurile ale fiecărui catalog.** Congelatele
și coaptele pot avea prețuri deosebite, dar B începe de la aceeași
cantitate în amândouă. Pus între cele două panouri, rândul pragurilor ar fi
părut al unuia; de-aia stă deasupra lor.

Sub fiecare casetă de preț scrie cine îl primește, socotit din pragurile de
deasupra: „1–99 buc.", „100–299 buc.", „300+ buc.". O cifră fără „de la cât"
te-ar lăsa să ghicești.

Pragurile pornesc precompletate din **Setări → General, cardul „Catalogul"**
(100 și 300, cum le-a pus migrarea) — la un produs nou, și la unul vechi care
n-are încă praguri. Nu se aplică însă singure: rândul pragurilor stă șters cât
nu-l folosește niciun preț B sau C și **nu se salvează** fără ele. Un prag fără
prețul lui e doar o valoare de pornire.

### Ce se refuză

Formularul judecă treptele **pe măsură ce scrii**: caseta greșită se încercuiește
cu roșu, iar sub panoul ei scrie ce e de îndreptat. „Salvează" nu pleacă spre
server cât e ceva roșu, și spune prima greșeală: *„Prețurile după cantitate:
…"*. Regulile:

- **B mai mic decât A, și C mai mic decât B**, pe fiecare catalog: *„Prețul B
  (4,50 lei) trebuie să fie mai mic decât A (4,20 lei)."*;
- **C numai peste un B**, pe același catalog: *„Prețul C pentru congelate are
  nevoie de prețul B de acolo."*;
- **un preț B cere pragul B**, un preț C pragul C: *„Scrie de la câte bucăți
  începe prețul B."*;
- **pragurile sunt bucăți întregi, între 2 și 10000**, iar **C stă peste B**:
  *„Pragul C (80 buc.) trebuie să fie mai mare decât pragul B (100 buc.)."*
  Pragul 1 ar fi făcut din B prețul oricui.

Serverul judecă la fel, cu aceleași reguli, și refuză salvarea întreagă: paza
din pagină doar îți spune mai devreme.

Dacă cineva scrie totuși cu mâna în bază un B mai scump decât A, treapta aceea
**nu se aplică** deloc, și nici C de deasupra ei: o treaptă se aplică numai
întreagă și mai ieftină.

Pe o bază fără migrarea 028, B și C n-au unde se scrie. O salvare care aduce un
preț B ori C e refuzată pe față, cu numele migrării — altfel ai fi primit
„salvat", iar clientul ar fi plătit tot A. Cu casetele B și C goale, produsul se
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
depinde de vedere (v. „Cum se vede catalogul", mai jos):

| Unde | Cum arată scara |
|---|---|
| „Unul pe rând" și pagina produsului | trei căsuțe alăturate, sub „Preț pe bucată, după cantitate": „1–99 buc. · 4,20 RON", „100–299 buc. · 3,90 RON", „300+ buc. · 3,60 RON" |
| „Patru pe ecran", pe calculator | A stă în cutia catalogului, lângă A-ul celuilalt; sub „Preț pe bucată, după cantitate", numai B și C: „100–299 · 3,90 RON", „300+ · 3,60 RON" |
| „Șase pe ecran", pe calculator | trei rânduri sub „Preț pe bucată, după cantitate": „1–99", „100–299", „300+" |
| „Patru" și „Șase", pe telefon și tabletă | în bula de peste poză, A rămâne prețul mare; dedesubt, pe scurt, numai B și C: „100+ buc. 3,90" („100+ 3,90" în „Șase") |

**Treapta în care a ajuns coșul se aprinde în auriu.** Cu produsul lipsă din
coș nu se aprinde nimic: nimeni n-a ajuns încă nicăieri.

**Îndemnul.** Cât mai e o treaptă de urcat, sub scară scrie cât lipsește:
*„Încă 12 buc. și prețul scade la 3,90 RON/buc."* Apare pe cardul din „unul pe
rând" (după ce produsul e în coș), în pagina produsului, pe rândurile din coș și
în „Produse selectate", din formularul de comandă. Nu apare la pachetul de test,
și nici în treapta de sus, unde n-ar mai avea ce promite.

**Rândul din coș** spune prețul plătit și de unde vine: *„140 g · 3,90 RON/buc ·
preț de la 100 buc. · Congelate"*. Totalul, comanda minimă de 700 RON și textul
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

Al treilea rând închide o portiță: fără el, „cer 150 la B, apoi tai la 90" ar fi
adus B pentru o cantitate care nu l-ar fi primit niciodată. Singura excepție e
un catalog care azi **n-are niciun preț**: atunci promisiunea rămâne, fiindcă
marfa e deja convenită, iar un refuz ar îngheța toată comanda.

Când o comandă păstrează un preț promis deosebit de cel de azi, Jurnalul o
spune, ca până acum: *„„Croissant" (congelat) × 150 onorat la 3,90 lei, în
catalog 4,20 lei"*.

Comenzile de dinainte de 2.5.0 n-au treapta scrisă, deci se citesc ca A:
prețul lor de atunci rămâne, iar o corectură care le urcă peste un prag le dă
treapta mai bună, ca oricărei alte comenzi.

### Unde se vede treapta

În magazin, pe carduri și în coș. În panou, **numai în formularul de comandă**
(v. „Banii, cât scrii comanda", mai jos). Restul casei — Statisticile,
Recomandările, e-mailurile comenzii — citește prețul scris pe rând, ca până
acum: treapta e doar felul în care s-a ajuns la el.

---

## Cum se vede catalogul: vederi și filtre

### În magazin

Deasupra produselor, trei butoane desenate — **„Cum se vede catalogul"** — aleg
câte carduri stau pe rând:

| Vederea | Telefon (390) | Tabletă (820) | Calculator, cu coșul alături (1080) | Calculator (1440) |
|---|---|---|---|---|
| **Unul pe rând, cu toate detaliile** | 1 | 1 | 1 | 1 |
| **Patru pe ecran** | 2 | 4 | 2 | 3 |
| **Șase pe ecran** | 3 | 6 | 3 | 4 |

Numele vederilor spun cât de mult încape, nu numărul exact: de la runda 2.5.2
(29 septembrie 2026), pe calculator „Patru" are carduri **mai mari** decât
„Șase", iar pe un ecran mai îngust grila pune câte încap, fără să strivească
vreun card. „Patru" pe calculator trece de la două la trei pe rând pe la
1130 de puncte, când cardul are loc pentru scara lui.

- **Unul pe rând** e cardul bogat: poza stă lângă text cât încap amândouă, și
  deasupra lui pe telefon; are descrierea întreagă, scara în trei căsuțe,
  îndemnul și rândul celuilalt catalog.
- **Patru pe ecran, pe calculator**: poză, nume, gramaj, descrierea pe cel
  mult trei rânduri, apoi **două cutii, câte una pe catalog**, fiecare cu
  semnul și prețul ei — cea a catalogului deschis e aurie, iar un catalog în
  care produsul nu e scrie *„nu se vinde"*. Când cardul e prea îngust ca să
  le țină alăturate, stau una sub alta. Dedesubt, scara, dacă are.
- **Șase pe ecran, pe calculator**: cardul pe care „Patru" îl avea până la
  2.5.2, neschimbat — poză, nume, gramaj, rândul celuilalt catalog,
  descrierea pe două rânduri, scara.
- **Patru și Șase, pe telefon și tabletă**: numele și prețul stau într-o
  **bulă întunecată peste poză**, iar sub poză o bară cu **„+ Adaugă"**, care
  după prima apăsare se face **„−", cantitatea, „+"**. În „Patru" prețul
  are semnul catalogului, iar bula mai poartă prețul celuilalt catalog, cu
  semnul lui, și gramajul; în „Șase", numai numele, prețul și scara, pe
  scurt. Poza e urcată cât să se vadă produsul deasupra
  bulei, iar apăsată oriunde — și pe bulă — deschide fereastra produsului.

**Rândul celuilalt catalog**, cu semnul lui, spune ce costă produsul de partea
cealaltă — *„Coapte: 4,20 RON"* — ori că e numai de-o parte: *„Doar
congelate"*.

**Cu ce se deschide.** Vederea de pornire o alegi tu, din **Setări → General,
cardul „Catalogul"**: una pentru telefon și tabletă, alta pentru calculator
(hotarul e 1080 de puncte). La pornire sunt „Unul pe rând" și „Patru pe ecran".
Clientul își poate schimba vederea, iar alegerea lui se ține minte în browserul
lui, deosebit pe telefon și pe calculator — **până schimbi tu vederea din
Setări**: atunci schimbarea ajunge la toată lumea, și la cine își alesese alta
înainte.

**Filtrele**, deasupra produselor:

- **„Caută în catalog"** — caută în număr, în nume și în descriere, în amândouă
  limbile, fără să se uite la diacritice: „strudel" îl găsește pe „Ștrudel";
- **„Grupe de produse"** — *Toate*, apoi câte o pastilă pe grupă, cu numărul de
  produse;
- **„Doar noutăți"** — numai dacă e vreun produs nou;
- **„Cu preț de volum"** — produsele cu B (ori și C) în catalogul deschis; numai
  dacă e măcar unul;
- **„Ordonează"** — *Recomandat · Nume, A–Z · Preț crescător · Preț
  descrescător*. Prețul e A, iar ordinea se face înăuntrul fiecărei grupe:
  grupele rămân în ordinea lor.

Când nu se potrivește nimic, pagina scrie **„Niciun produs nu se potrivește"**,
cu un buton **„Șterge filtrele"**. Pe calculator filtrele stau la vedere; pe
telefon și tabletă se deschid din butonul **„Filtre"**, chiar sub bara de unelte
— „Filtre · 2" spune câte sunt aprinse (căutarea nu se numără: se vede oricum).

### În panoul Catalog

Aceleași trei desene, sub numele **„Cum se arată produsele"**: „Unul pe rând"
e lista de rânduri de până acum, „Patru" și „Șase" sunt plăci. Vederea se ține
minte în browser și pornește pe „Unul pe rând"; **Setările nu o ating**, fiindcă
ele sunt pentru clienți.

Lângă **„Congelate | Coapte | Amândouă"** stau filtrele panoului:

- **grupa** — *Toate grupele* ori una singură;
- **ordinea** — *Ordinea din magazin · După nume · Prețul A, crescător · Prețul
  A, descrescător*; produsele fără preț stau la coadă;
- câte o pastilă, una singură aprinsă deodată: *Toate · Cu preț de volum · Doar
  prețul A · Noutăți · Scoase din catalog · De reparat*, fiecare cu numărul ei.
  „Doar prețul A" sunt produsele cărora li se pot pune praguri; „De reparat",
  cele cu semnul roșu de pe rând. O pastilă cu zero e stinsă.

**„Arată tot"** le stinge pe toate deodată. Până la 1000 de puncte filtrele stau
strânse sub butonul **„Filtre"**. Filtrele panoului nu se țin minte: la
redeschidere pagina arată tot catalogul.

Pe rânduri și pe plăci, prețurile stau într-un tabel mic, **câte un rând pe
catalog și câte o coloană pe treaptă** — „1+", „100+", „300+" —, cu A îngroșat
și „—" unde treapta lipsește. Amândouă cataloagele se văd și pe comutatorul unui
singur catalog: oglinda se citește tocmai punând cifrele una sub alta. Un produs
cu un singur preț pe catalog arată ca înainte: „2,85 lei", fără tabel.

---

## Numărul din colțul pozei

Fiecare produs poartă în magazin un număr în colțul din stânga-sus al pozei:
**01**, **02**, **03**… Clientul îl spune la telefon și îl scrie pe WhatsApp,
iar numărul pleacă și în mesajul comenzii, înaintea numelui: *„3 × 7. Croasant
cu unt"*. În panou stă înaintea numelui, pe fiecare rând, și în colțul pozei,
pe plăci.

Până la 2.5.1 numărul era cheia produsului din baza de date: nu se putea
alege, iar un produs șters lăsa în urmă un gol. De-acum îl alegi tu, din
formularul produsului, la **„Număr în catalog"** — orice număr întreg de la 1
la 999.

**Numărul e și locul produsului.** Magazinul și panoul așază produsele după el,
înăuntrul fiecărei grupe: „07" stă mereu înaintea lui „08". Asta e ordinea
**„Recomandat"** din magazin și **„Ordinea din magazin"** din panou.

### Un produs nou

Primește numărul de după cel mai mare din catalog, deci, de la sine, numerele
urmează ordinea în care sunt puse produsele. Caseta e deja scrisă cu el, iar
lângă ea scrie *„Următorul după ultimul produs — ordinea în care sunt puse"*.
Dacă vrei produsul mai în față, scrii alt număr înainte de „Adaugă în catalog".

### Un număr liber, un număr ocupat

Scrii un număr, iar lângă casetă apare, **înainte de salvare**, ce se va
întâmpla:

- **Numărul e liber** — *„12 e liber — nu se mută niciun alt produs."* Se mută
  numai produsul tău.
- **Numărul e ocupat** — nu se refuză. Produsul tău intră acolo, iar cele de la
  rând **se dau cu un loc**, ca într-o listă în care muți un rând. Lămurirea
  spune cine se mută, cu numele: *„07 e acum la „Croasant cu unt". La salvare se
  dau cu un loc 12 produse (07–18 → 08–19), de la „Croasant cu unt" până la
  „Trigon cu mere"."*

| Ce faci | Ce se întâmplă |
|---|---|
| muți produsul **mai în față**, de pe 19 pe 07 | 07–18 trec pe 08–19, în locul lăsat de el |
| muți produsul **mai în spate**, de pe 07 pe 19 | 08–19 trec pe 07–18, în locul lăsat de el |
| pui un produs **nou** pe un număr ocupat | cele de la el încolo trec cu un loc mai în spate |

Șirul care se dă cu un loc se oprește la primul număr liber: dacă între 07 și
19 e un gol, golul se umple, iar ce e după el rămâne pe loc.

După salvare, mesajul de sus le numără: *„Produsul a fost salvat, cu numărul
07. Alte 12 produse s-au dat cu un loc."*

Un număr care nu e întreg, zero ori peste 999 nu pleacă: caseta se face roșie,
cu *„Scrie un număr întreg de la 1 la 999 — cel din colțul pozei, în
magazin."*

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
**„Șase pe ecran"** nu apărea: cardul de atunci era prea mic ca să mai poarte
ceva peste poză. Pe card are zero în față („03"); în coș
și în mesajul de WhatsApp, nu („3. Ștrudel cu dovleac"), ca și până acum.

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
produse", iar la × răspundea „grupa are 3 produse în ea" — două cifre care se
contraziceau pe același ecran, fără să ai de unde ști de unde ies cele trei.

**Rândul de adăugat** — „Grupă nouă (RO)”, „New group (EN)” și **„Adaugă”** —
apare numai după ce catalogul s-a încărcat. Cât pagina încă întreabă, ori cât
stă pe ecran caseta roșie *„Catalogul nu s-a încărcat.”*, lipsește, la fel ca
**„+ Produs nou”**: pagina nu știe atunci nici al cui e contul, nici ce grupe
sunt deja.

---
## Catalogul, în formularul de comandă

`lab.goldenpastry.ro/comenzi` — la fiecare poziție a comenzii.

Până acum, „Nume produs" era o casetă de scris liberă. Numele scris trebuia să
nimerească **exact** un produs din catalog, altfel salvarea era refuzată la
sfârșit, după ce scriseseși toată comanda: *„Produsele «X» nu există în
catalog"*. Refuzul era corect, dar venea prea târziu și fără să-ți spună ce să
scrii în loc.

Acum catalogul vine în întâmpinare, în trei feluri.

### Scrii două litere, îți arată ce ai

Sub casetă apare o listă scurtă cu produsele care se potrivesc: **numele,
gramajul și prețul bucății** — prețul variantei alese pe poziție, congelat sau
copt. La un produs cu trepte, prețul se scrie de la A până la cel mai mic:
*„140 g · 4,20 → 3,60 RON"*. „cr" scoate croissantele. Potrivirea nu se uită la diacritice și nici la
majuscule, și caută oriunde în nume, nu doar la început.

Alegi unul din listă și poziția rămâne legată de produsul acela din catalog,
nu de numele lui scris. Dacă produsul e redenumit mâine, comanda nu se rupe.

### Sau deschizi lista întreagă

Lângă **+ Adaugă poziție** stă un buton care deschide catalogul grupat pe
grupe. E pentru când clientul îți spune „ceva cu mere" și nu știi cum se
cheamă în catalog.

Fereastra arată, la fiecare produs, **poza lui**, numele, gramajul și prețul
bucății — prețul variantei alese pe comandă, congelat sau copt. Produsele fără
fotografie poartă rozeta casei, ca să rămână un rând de aceeași înălțime, nu o
gaură.

La un produs cu trepte, prețul spune și ce urmează: *„4,20 RON · 3,90 de la 100
· 3,60 de la 300"*. După ce ai pus 100 de bucăți, același rând arată *„3,90 RON
(preț B) · 3,60 de la 300"* — cantitatea e cea a produsului pe toată comanda, pe
varianta lui, cum o numără și serverul.

**Ce ai pus deja se vede, cu cantitate cu tot.** Produsele care au cel puțin o
bucată pe comandă stau pe **fundal de miere, cu chenar auriu**, iar cifra lor e
scrisă în casetă. Fără asta, omul care deschide catalogul a doua oară nu-și mai
amintește ce a pus și pune încă o dată.

Cantitatea se schimbă din trei feluri, toate în fereastră:

- **apeși rândul** (sau **+**) — încă o bucată;
- **−** — una mai puțin; la zero, poziția dispare cu totul din comandă, fiindcă
  un rând cu „0" pe el nu e o poziție, e un gunoi pe care l-ai fi șters cu mâna;
- **scrii cifra** de-a dreptul în casetă — cât ai scris, atât rămâne; `0` scoate
  produsul.

Cifra din fereastră vorbește despre **produs**, nu despre un rând anume: dacă
ai scris croissante pe două poziții, ea le adună. Scrisă cu mâna, le și strânge
la un loc, într-o singură poziție — altfel suma de deasupra n-ar mai fi cea
tastată. Iar `+` pe un produs deja pus îngroașă poziția lui, nu face una nouă:
serverul oricum lipește la tarifare pozițiile alăturate cu același produs și
aceeași variantă, deci lista nu se umple cu rânduri care s-ar strânge la loc pe
bonul clientului.

Rândul gol cu care se deschide un formular nou **se umple**, nu se sare. Altfel
fiecare comandă ar începe cu o poziție fără nimic în ea.

### Ce nu e în catalog merge în „Mențiuni"

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
ajunge exact ce s-ar scrie în bază dacă ai apăsa „Salvează". Pricina e chiar
cea din capul acestui ghid: o înmulțire făcută în browser ar minți la prima
comandă **editată**.

Când deschizi o comandă veche și îi adaugi o bucată, bucățile de dinainte își
păstrează prețul promis atunci; numai bucata nouă ia prețul de azi. O socoteală
din pagină ar fi înmulțit tot cu prețul de azi și ți-ar fi arătat o sumă cu care
clientul nu fusese de acord.

**Treapta se vede pe rândul de bani.** O poziție plătită la B ori la C își spune
treapta și pragul care a câștigat-o: *„150 × 3,90 (preț B, de la 100 buc.)"*.
Pragul e cel promis atunci, adus de server, nu cel de azi din fișa produsului:
o comandă veche își păstrează treapta chiar dacă între timp ai mutat pragul.
Ce se întâmplă cu treapta când schimbi cantitatea scrie la „O comandă
corectată", mai sus; formularul arată exact rezultatul acela, fiindcă îl cere
serverului.

Cât răspunsul e pe drum, cifra veche stă **stinsă**. O sumă despre care nu se
știe încă dacă mai e adevărată nu se arată ca și cum ar fi.

Dacă serverul e mai vechi și nu știe să tarifeze, formularul **nu arată bani**
și merge mai departe: nu refuză nicio salvare.

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
