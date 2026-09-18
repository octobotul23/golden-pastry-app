# Catalogul de produse

`lab.goldenpastry.ro/produse` — doar pentru administratori.

De aici adaugi, modifici și scoți produse și grupe. Ce salvezi apare imediat pe
`goldenpastry.ro`, în formularul de comandă și în datele pe care le citește
Google.

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
- **ambele prețuri**.

### Cele două prețuri

| Pe site | În formular | Ce înseamnă |
|---|---|---|
| Congelate | Preț congelat | livrat în lanț frigorific |
| Gata de servire | Preț copt | livrat gata de vânzare |

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

### Fotografia

JPEG, PNG sau WebP, maximum 5 MB. Alegi fișierul, se încarcă la salvare.

Tipul se verifică **după conținut**, nu după extensie sau după ce spune
browserul: un fișier PHP redenumit `poza.jpg` ar fi cod executabil în folderul
public al site-ului. Fișierul primește un nume nou la fiecare încărcare, ca
browserele să nu arate poza veche zile în șir.

Pozele stau în `public_html/products/`. Folderul trebuie să permită scrierea —
`api/verifica.php` îți spune dacă nu.

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

## Scoaterea unui produs

Butonul **×** de pe rând. Se întâmplă unul din două lucruri, în funcție de
istoric:

**Produs care nu a fost comandat niciodată** — se șterge de tot. Ai adăugat ceva
din greșeală, dispare complet.

**Produs care apare în comenzi sau în depozit** — se scoate din catalog, dar
rândul rămâne. Dispare de pe site și din formulare; comenzile vechi își
păstrează denumirea și prețul de atunci, iar rapoartele nu rămân cu goluri.
Îl repui oricând cu **↩**.

---

## Grupele

Fila **Grupe**. Fiecare are nume și subtitlu în ambele limbi, plus o ordine —
aceeași în care apar pe site.

Două protecții:

- o grupă cu produse în ea **nu poate fi ștearsă** — nici dacă produsele sunt
  doar scoase din catalog;
- o grupă cu produse active **nu poate fi ascunsă** — altfel produsele ar
  dispărea de pe site fără să pară scoase din catalog.

În ambele cazuri mută întâi produsele în altă grupă.

Insigna de pe rând spune de ce: `3 produse` cât timp toate sunt în catalog, și
`0 în catalog · 3 scoase` când au rămas doar rânduri scoase. Cifra a doua e
chiar cea pe care o folosește refuzul de la ștergere. Înainte insigna arăta
doar produsele din catalog, deci o grupă golită scria „0 produse", iar la ×
răspundea „grupa are 3 produse în ea" — două cifre care se contraziceau pe
același ecran, fără să ai de unde ști de unde ies cele trei.

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
copt. „cr" scoate croissantele. Potrivirea nu se uită la diacritice și nici la
majuscule, și caută oriunde în nume, nu doar la început.

Alegi unul din listă și poziția rămâne legată de produsul acela din catalog,
nu de numele lui scris. Dacă produsul e redenumit mâine, comanda nu se rupe.

### Sau deschizi lista întreagă

Lângă **+ Adaugă poziție** stă un buton care deschide catalogul grupat pe
grupe, cu prețul fiecărei bucăți. E pentru când clientul îți spune „ceva cu
mere" și nu știi cum se cheamă în catalog.

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
  zonele deservite și catalogul complet cu prețuri;
- catalogul ca text, pentru cine nu rulează JavaScript;
- `sitemap.xml`, construit tot din baza de date.

Aplicația interactivă rămâne exact cum era — se încarcă peste.

Nimic din toate astea nu trebuie întreținut separat: schimbi un preț din pagina
Catalog și se schimbă și în ce vede Google.
