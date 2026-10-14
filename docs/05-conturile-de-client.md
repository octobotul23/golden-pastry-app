# Conturile de client

Un client cu cont comandă altfel decât un vizitator: nu-și mai scrie numele,
telefonul și adresa la fiecare comandă, iar în `clienti.goldenpastry.ro/client`
își vede tot ce a comandat până acum.

Contul îl deschizi tu, din `lab.goldenpastry.ro/conturi`.

---

## Ce vede clientul

**După autentificare ajunge în „Comanda mea”**, nu în magazin. Acolo îl
așteaptă comanda care urmează, ziua livrării și, în ziua aceea, câte opriri mai
sunt până la el. Magazinul rămâne la un clic: butonul **„Intră în magazin”** de
pe pagina de intrare, și rândul din meniul contului.

În magazin, când ajunge acolo, în locul butonului „Intră în cont” antetul îi
arată numele firmei, iar sub el meniul contului: **„Comanda mea”**,
**„Recomandări”** — numai dacă are cod de recomandare —, **„Parola mea”** și
**„Ieși din cont”**. „Recomandări” e nouă acolo în 2.6.0: clientul cu cod o
avea deja în meniul din „Comanda mea”, dar în magazin n-o găsea.

De la 2.7.0, meniul din „Comanda mea” mai are un rând, **„Istoric”**: pagina cu
tot ce a comandat (v. „Istoricul comenzilor”, mai jos). În meniul contului din
magazin rândul nu e; de acolo clientul ajunge la istoric prin „Comanda mea”.

Tot de la 2.7.0, pe un ecran lat și înalt — calculator ori tabletă, de la 768
de puncte lățime și 500 înălțime —, fereastra unui produs și fereastra de
finalizare se deschid în mijlocul ecranului; pe telefon urcă de jos, ca o
foaie. Tasta Escape închide ce e deasupra, câte una la o apăsare: întâi meniul,
apoi fereastra de finalizare, apoi a produsului, apoi foaia coșului. Până la
2.7.0 Escape închidea numai meniul, iar cine deschidea un produs pe calculator
apăsa tasta degeaba. Asta vede și vizitatorul fără cont.

> O vreme l-am trimis de-a dreptul în magazin, cu socoteala „clientul vine să
> comande, nu să-și citească istoricul”. Socoteala era a patiseriei, nu a
> omului: cine își scrie parola vrea să vadă **ce a comandat** — altfel n-avea
> nevoie de parolă, fiindcă magazinul se deschide și fără cont. Schimbat la
> cererea patronului, 13 septembrie 2026.

**Formularul de comandă se scurtează.** Companie, persoană de contact, e-mail,
telefon, segment și volum estimat sunt întrebări de prim contact: pentru un
client cu cont ele dispar. Rămân produsele alese, adresa de livrare, ziua în
care vrea marfa și un câmp de mențiuni.

Adresa vine de pe cont și, dacă o schimbă, rămâne salvată acolo pentru comanda
următoare. La fiecare comandă poate scrie alta — nu e blocată.

**Numele nu poate fi schimbat din formular.** Serverul îl ia de pe cont și
ignoră ce vine din pagină, ca o comandă să nu poată intra pe numele altui
client.

Pe „Comanda mea” stă și cardul **„Recomandă Golden Pastry”**: cu linkul lui de
recomandare, butonul „Copiază” și „Vezi ce ai câștigat →”, ori, dacă n-are încă
un cod, cu butonul „Fă-ți un cod →”, care îl duce în pagina „Recomandări”, unde
își face unul. Clientul vede cardul cu cod ori fără; intrarea „Recomandări” din
meniu apare însă numai clientului care are deja un cod.

### Soldul și semnul de plată

De la 2.7.0, primul lucru de pe „Comanda mea” e **soldul**: un card pe care
scrie „Sold de plată: 340,00 RON · 2 comenzi” ori, când n-are nimic de dat, „Nu
ai nimic de plată.” Stă deasupra comenzii care urmează, fiindcă e singurul
lucru de pe pagină care îl poate opri din a mai comanda.

Fiecare comandă poartă, lângă insigna cu etapa, un al doilea semn: **„De
plată”** ori **„Plătită”**. Comanda anulată nu poartă niciunul. Același semn
stă și pe rândurile din „Istoric”.

Casa ține patru stări ale plății — „Neplătită”, „Amânată”, „Plătită” și
„Plătită prin bancă” —, dar clientul citește numai două: o amânare e pentru el
tot „De plată”, iar cum a plătit nu i se spune. Stările întregi le vede numai
cine are pagina „Încasări” (v. `docs/02-panoul-de-setari.md`, la „Plățile și
pagina „Încasări””). Dacă pui alături ecranul lui și pe al tău, suma lui e
scrisă în „RON”, iar a ta în „lei”: sunt aceiași bani.

Pe un server fără migrarea `sql/032_plati_si_prag.sql` nu se desenează nici
cardul, nici semnul, iar pagina arată ca înainte de 2.7.0.

### Când ajunge la pragul de neplătite

Un client poate avea un număr de comenzi neplătite; de la numărul acela în sus,
comenzile noi i se opresc până plătește. Numărul se cheamă **prag**. Din
pornire e **2, pentru toți clienții** (hotărârea patronului din 4 octombrie
2026). Îl schimbi pentru toți din Setări ori din „Încasări” (v.
`docs/02-panoul-de-setari.md`, la „Pragul de comenzi neplătite”), iar pentru un
singur client din fișa lui (mai jos, la „Pragul de neplătite al unui client”).

**Ce se numără.** Comenzile lui neplătite și neanulate, legate de contul lui —
cele amânate tot neplătite sunt — și, pe lângă ele, cererile trimise și încă
neacceptate, dacă au produse. De aceea un client poate fi oprit fără să aibă
nimic de plată: a trimis două cereri și nu i-a răspuns încă nimeni. Soldul
adună numai comenzile. O comandă scrisă pe un nume care nu duce la niciun cont
nu se numără nimănui.

**Întâi e prevenit.** Cât e sub prag, dar are deja ceva neplătit, formularul de
comandă din magazin îi scrie, pe un rând galben, deasupra butonului de
trimitere: „Ai 1 comandă neplătită (340,00 RON). Poți trimite încă una; după
aceea comenzile se opresc până la plată.” Când la numărătoare intră și cereri,
fraza spune „neplătită ori în așteptare”. Cu pragul 2, a doua comandă neplătită
e ultima primită, iar a treia e refuzată. Cu pragul 1 nu există avertizare: cât
are o comandă neplătită, nu mai poate trimite alta.

**Apoi e oprit.** Ajuns la prag, în locul rândului galben stă o casetă roșie,
cu vorbele alese de patron: „Numărul maxim de comenzi a fost atins.” și „Până
plătești soldul comenzilor precedente nu putem primi o comandă nouă.” Sub ele
stau comenzile de plătit — numărul, ziua și suma fiecăreia, cel mult douăzeci,
apoi „… și încă 3 comenzi.” —, soldul („Sold de plată: 340,00 RON”) și, dacă la
numărătoare au intrat și cereri, „Se numără și o cerere trimisă, încă
neacceptată.” Butonul de trimitere se stinge și scrie **„Oprit până la
plată”**, iar sub el: „Comanda nu poate pleca până la plata soldului de mai
sus. Coșul tău rămâne salvat.” Coșul nu se golește: după plată, comanda pleacă
din același loc.

Aceeași casetă roșie o găsește pe „Comanda mea”, în locul cardului cu soldul.
Butoanele **„Comandă din nou →”** se sting, cu lămurirea „Se poate din nou după
plata comenzilor de mai sus.”; în „Istoric”, unde soldul nu e pe pagină,
lămurirea e „Se poate din nou după plata comenzilor neplătite. Soldul îl vezi
în „Comanda mea”.” Clientului oprit numai de cereri încă neacceptate, fără
nimic de plată, i se scrie altceva, fiindcă n-are ce plăti: „Se poate din nou
după ce se lămuresc cererile trimise, de mai sus.”

**„Contactează-ne”.** În caseta roșie stă butonul **„Contactează-ne”**, cu
„pentru mai multe detalii despre situația actuală” lângă el. Deschide
formularul din magazin, care de data asta trimite numai un mesaj, fără produse,
cu textul pus gata: „Vreau să vorbesc cu cineva despre comenzile neplătite.” Un
mesaj fără produse nu e oprit niciodată de prag, deci clientul oprit are mereu
pe unde să-ți scrie.

**Cum iese din oprire.** Numărătoarea se face din nou la fiecare încercare,
deci nu e nimic de „deblocat” cu mâna. Iese de cum una din comenzile lui e
trecută ca plătită în „Încasări” ori e anulată, ori de cum o cerere care se
număra e respinsă. Acceptarea unei cereri nu-l scoate: cererea acceptată
devine o comandă neplătită și se numără mai departe. Dacă vrei să-l lași să
comande fără să fi plătit, ai două căi: îi ridici pragul din fișa contului
(ori i-l scoți, cu „Fără limită”), ori îi scrie comanda, din „Comenzi”, cineva
care are voie să treacă peste prag (v. „Clientul ajuns la prag, în formularul
tău”, mai jos).

### Ce scrie pe comanda lui

Pe „Comanda mea”, fiecare comandă poartă o insignă cu etapa, pe limba
clientului, nu pe a laboratorului:

| Unde e comanda | Ce citește clientul |
|---|---|
| „În așteptare” | Programată |
| „În lucru”, „La dospit”, „În cuptor” | Se prepară |
| „La ambalare” | La ambalare (verde) |
| etapa dinaintea celei finale („Gata de livrare”) | **Gata de livrare** (verde) — ori **Gata de ridicat**, când vine singur după marfă |
| etapa finală („Predată”) | **Livrată** (pe plin, închis la culoare) — ori **Ridicată** |
| etapa de anulare | Anulată (roșu) |

Ultimele trei rânduri se citesc după **locul** etapei în listă, nu după nume:
dacă redenumești „Predată” din Setări, clientul citește mai departe „Livrată”.
O etapă pe care ai adăugat-o tu și care nu e în tabel apare cu numele ei. Cine
„vine singur după marfă” spune lista „Moduri fără mașină” din Setări — v.
`docs/02-panoul-de-setari.md`, la „Ce ajunge pe ruta șoferului”.

Până la 2.6.0 cartonul de sus scria „Gata” la etapa finală, iar istoricul
scria „Livrată” ori „Ridicată” despre aceeași comandă. De la „Gata de livrare”
încoace, „gata” e tocmai numele etapei dinaintea predării, iar clientul care
citea „Gata” n-ar mai fi știut dacă marfa îl așteaptă ori a primit-o. Acum
cartonul și istoricul spun aceeași vorbă.

În ziua livrării, când marfa pleacă cu mașina, deasupra comenzii stă și
cartonașul **„Livrarea de azi”**, cu câte opriri mai sunt până la client și
insigna „Pe rută”. Când marfa a ajuns, insigna lui e chiar
cea a comenzii, „Livrată”, pe plin: clientul vede atunci două „Livrată” pe
același ecran, de aceeași culoare. Până la 2.6.0 insigna cartonașului era
verde la sosire; acum verdele stă numai pe comenzile încă nepredate, „La
ambalare” și „Gata de livrare”.

---

## Istoricul comenzilor

De la 2.7.0 istoricul are pagina lui. Pe `clienti.goldenpastry.ro/client`, sub
comanda care urmează, au rămas cele mai noi șase comenzi, sub titlul „Ce ai
comandat până acum”. Când sunt mai multe, sub ele stă legătura **„Vezi tot
istoricul →”**; până la 2.7.0 era acolo un buton care desfăcea restul în
aceeași pagină.

Fiecare rând arată ziua, câte bucăți și cât a fost. Apăsat, se desface și arată
produsele, numărul comenzii și adresa la care a plecat. Comenzile anulate apar
și ele, marcate, oricând ar fi fost.

Insigna de pe fiecare rând e aceeași ca pe cartonul de sus, din aceeași
socoteală (tabelul de la „Ce scrie pe comanda lui”): o comandă predată scrie
„Livrată” ori „Ridicată” în amândouă locurile. Lângă ea stă, de la 2.7.0,
semnul de plată.

### Pagina „Istoric”

`clienti.goldenpastry.ro/istoric`, din rândul „Istoric” al meniului ori din
„Vezi tot istoricul →”. Sub titlul „Istoricul comenzilor” scrie a cui e lista:
„Tot ce ai comandat, de la cea mai nouă.”

Comenzile sunt grupate pe luni și vin câte douăzeci. Sub listă scrie câte se
văd din câte sunt („20 din 57”), iar butonul **„Arată încă 20”** le aduce pe
următoarele; la capăt scrie „Arată ultimele 7” ori „Arată ultima”.

Deasupra comenzilor, când e cazul, stă lista **„Cereri care n-au devenit
comenzi”**: cererile trimise și încă neacceptate și cele respinse, acestea cu
„Motivul” scris la respingere. O cerere acceptată nu mai apare aici: a devenit
comandă și stă în listă, la luna ei.

Cine n-a comandat încă nimic citește „Încă n-ai nicio comandă. Când trimiți
prima, o găsești aici.”, cu butonul „Intră în magazin”.

### „Comandă din nou”

Pe orice rând din „Istoric” — și pe comenzile încă în lucru, fiindcă cine
comandă același lucru în fiecare zi vrea să-l repete tocmai cât comanda de azi
e pe drum — și pe rândurile de sub „Ce ai comandat până acum” stă butonul
**„Comandă din nou →”**. Îl duce pe client în magazin, cu produsele comenzii
puse în coș. Nimic nu pleacă spre patiserie: comanda o trimite tot el, din
formularul magazinului, după ce alege ziua.

Ajuns în magazin, o casetă cu titlul „Comandă din nou” îi spune ce s-a
întâmplat: „Am pus în coș produsele din comanda #1003. Ce aveai deja a rămas.
Prețurile sunt cele de azi.” Sunt trei lucruri de știut în fraza asta:

- **Produsele se adaugă la coș, nu îl înlocuiesc.** Până la 2.7.0 coșul de
  dinainte era înlocuit cu totul.
- **Prețurile sunt cele de azi**, nu cele de pe comanda veche.
- **Ce nu se mai vinde rămâne pe dinafară**, și i se spune: „Un produs nu mai e
  în catalog și a rămas pe dinafară.” (ori „3 produse nu mai sunt în catalog și
  au rămas pe dinafară.”). Află încă dinainte, de sub buton: „Nu intră în coș,
  fiindcă nu sunt în magazin: …”, cu numele lor. Dacă nimic de pe comandă nu se
  mai vinde, butonul lipsește și în locul lui scrie „Nu se poate reface din
  magazin: produsele ei nu mai sunt acolo.”

**A doua apăsare nu dublează coșul.** Cine deschide încă o dată aceeași
legătură citește „Comanda #1003 era deja în coș. Am completat cantitățile până
la cele din comandă.” ori „Produsele din comanda #1003 erau deja în coș. Nu am
mai adăugat nimic.” Dintr-un produs intră în coș cel mult 9999 de bucăți.

**Cum se ia înapoi.** Caseta are două butoane. **„Am înțeles”** o închide.
**„Scoate-le înapoi”** scade din coș exact ce a adăugat apăsarea și lasă ce
avea omul dinainte. Până la 2.7.0 butonul se chema „Pune la loc coșul de
dinainte”, fiindcă atunci coșul era înlocuit.

Clientul oprit la prag găsește butonul stins (v. „Când ajunge la pragul de
neplătite”, mai sus).

### Istoricul celui care scrie comenzi, și copia unei comenzi

Pagina „Istoric” e și a casei, la `lab.goldenpastry.ro/istoric`. Rândul din
meniu îl are cine are fapta „Scrie comenzi și acceptă cereri” — din pornire
administratorul, managerul și fondatorul — și, după rol, agentul de vânzări
(v. „Ce poate fiecare rol”, la coada ghidului). Altcineva din casă citește
„Contul „…” e cont de angajat: istoricul e al celor care scriu comenzi.”, cu
rolul lui.

Sub titlu scrie a cui e lista: „Comenzile scrise de tine din panou. Cele venite
de pe site sunt ale clienților și nu apar aici.” „Scrise de tine” înseamnă cu
mâna ta: comanda din formular și cea născută din cererea ta, oricine ar fi
acceptat-o. Comanda unui client venită de pe site nu intră în istoricul celui
care doar a apăsat „Acceptă”. Agentul rămâne și aici în marginile lui: o
comandă scrisă de el pentru un cumpărător care între timp nu mai e al lui iese
din istoric, cum a ieșit și din „Comenzi”.

**„Comandă din nou →”**, pe orice rând, deschide formularul de comandă nouă
din „Comenzi” — la agent, formularul de cerere — cu comanda veche copiată în
el. Butonul se desenează numai cui are și panoul „Comenzi”. Nimic nu se scrie
până nu salvezi: până atunci e un formular deschis, pe care îl poți închide
fără urmă.

| Ce | Se copiază? |
|---|---|
| clientul, telefonul, adresa | da |
| zona | da — cea de pe **comanda veche**, nu cea de pe cont; dacă nu mai e în Setări, rămâne nealeasă |
| felul livrării | da, dacă mai e în Setări; altfel rămâne neales, iar salvarea cere „Alege felul de livrare, la „Canal”.” |
| produsele, cu latura (copt ori congelat) și cantitatea | da, cele care se mai vând |
| **ziua** | **nu** — rămâne goală, dinadins |
| ora, etapa, „urgent”, nota | nu |
| prețurile | nu — se pun cele **de azi**, cu treptele după cantitate |
| codul de recomandare și comisionul | nu — formularul pornește cu codul tău, ca la orice comandă nouă |
| numărul comenzii | nu — îl dă serverul, la salvare |

**Ziua nu se copiază, fiindcă e singurul lucru sigur greșit**: comanda veche e
din trecut. Lista zilelor arată „— alege ziua —”, iar salvarea fără zi e
refuzată, cu „Alege ziua de livrare.” lângă câmpul „Zi”.

În formular, o bandă spune ce s-a copiat și ce nu, cu rândurile care se
potrivesc:

- „Copie după comanda #1003. Alege ziua de livrare.”;
- „N-am copiat: Cozonac cu nucă, Pască — nu se mai fac.”, când un produs a
  ieșit din catalog ori nu se mai face pe latura aceea;
- „Felul de livrare de atunci („…”) nu mai e în Setări: alege altul, la
  „Canal”.”, și la fel pentru zonă;
- „Nota comenzii vechi n-a fost copiată: „…”.”, ca s-o poți scrie din nou, dacă
  mai e adevărată;
- „Comanda veche era legată de un cont de client. Cea nouă se leagă după numele
  scris la „Client”, nu după contul acela.”

Ultimul rând contează: dacă schimbi numele în formular, comanda nouă se leagă
de contul cu numele nou, ori de niciunul, ca orice comandă luată la telefon
(v. „Comanda luată la telefon”, mai jos).

Clientul ajuns la prag oprește și copia, la salvare (v. „Clientul ajuns la
prag, în formularul tău”, mai jos).

---

## Comanda luată la telefon

Comanda pe care o scrii tu în `lab.goldenpastry.ro/comenzi` ajunge în istoricul
clientului dacă numele scris de tine e **exact** numele de pe cont — cel din
caseta „Numele clientului din comenzi” a contului, sau numele contului dacă
aceea e goală.

Dacă două conturi poartă același nume, aplicația nu ghicește: comanda rămâne
nelegată și clientul n-o vede în panoul lui. Redenumește unul dintre conturi.

> În primele versiuni, de dinainte de 2.0.1, numele pe care îl scriai era
> înlocuit în tăcere cu numele contului tău. O comandă adăugată de administrator
> pentru „Hotel Belvedere” apărea în laborator și pe ruta șoferului drept
> „Administrator” — fără client, fără niciun semn că s-a pierdut ceva.

### Nu mai scrii numele din cap: îl cauți

Cum tastezi în caseta **Client**, sub ea apare lista clienților care se
potrivesc, cu **telefonul** lângă nume — lucrul după care omul de la comenzi
recunoaște clientul dintr-o ochire. Alegi unul și se umplu singure numele,
telefonul, adresa și zona **ultimei lui comenzi** — nu cele de pe cont: adresa
contului poate fi cea de acasă, pe când marfa merge de trei luni la birou.

**Felul livrării nu se ia**, deși îl știm. El schimbă banii (taxa de curier), iar
o comandă care se scumpește singură fiindcă ai ales un client din listă e tocmai
soiul de surpriză de care ne ferim. Scrie în dosar, ca să-l poți pune cu mâna.

**Te uiți înainte să alegi.** Cu mausul deasupra unui rând — sau, pe telefon,
ținând degetul pe el — se deschide dosarul scurt al clientului: câte comenzi a
făcut și cât a lăsat în casă, ultima comandă (ziua, intervalul, felul livrării),
unde s-a dus marfa ultima oară, telefonul și e-mailul. Apăsarea lungă deschide
dosarul, nu alege clientul — altfel „uită-te înainte să alegi” s-ar fi prefăcut
în „te-ai uitat, gata, ai ales”.

Dacă acel cont s-a făcut singur de pe site și nu l-a recunoscut încă nimeni din
casă, dosarul o spune pe față: **comanda se scrie, dar nu se leagă de el** până
nu-l întărește cineva din „Conturi”. Nu e o poartă — numele se poate alege
oricum —, e o urmare pe care trebuie s-o afli *înainte*, nu peste o săptămână,
când clientul întreabă de ce nu-și vede comanda în panoul lui.

Cine vede lista vede numai clienții lui: agentul de vânzări îi vede pe cei aduși
de el, iar cine scrie comenzi de-a dreptul — din pornire administratorul,
managerul și fondatorul — vede toată ograda. Marginea stă pe
server, într-un singur loc (`client_scope_for()`, `api/lib/orders.php`); caseta
din pagină doar o oglindește, fiindcă o listă care promite ce serverul refuză e
mai rea decât lipsa ei.

### Codul de recomandare, la comanda nouă

Cine scrie comenzi — din pornire administratorul, fondatorul și managerul; de
la 2.7.0, cine are fapta „Scrie comenzi și acceptă cereri” — are în formular
caseta
**„Cod de recomandare”**, deschisă cu **codul lui** scris deja. Poate pune altul:
comanda poate fi adusă de un coleg, iar el doar o scrie; dacă n-a adus-o nimeni,
golește caseta. Cine n-are cod pe cont găsește caseta goală.

**Codul se caută și după nume** (de la 2.7.5). Până acum, cine scria comanda
trebuia să știe codul pe de rost ori să-l caute în altă pagină, iar un cod
greșit cu o literă se salva fără nicio vorbă: comanda intra, comisionul nu. În
aceeași casetă scrii acum fie codul, fie o bucată din numele celui care a
trimis clientul; caseta goală o spune: „cod ori nume, ex. Octavian”. De la două
litere, sub ea apar cel mult cinci oameni, câte unul pe rând:

> Octavian Pleș · agent · cod ples

Apeși rândul și în casetă intră codul lui. Sub casetă, un rând spune al cui e
codul scris — „Cod ples — Octavian Pleș, agent.” —, și când l-ai tastat cu
mâna, întocmai.

- **În ce se caută:** în numele omului, în numele firmei lui (la clienți,
  rândul arată firma, cu omul între paranteze) și în cod. Nu e nevoie de
  diacritice: „stefanescu” îl găsește pe „Ștefănescu”. **Numele de utilizator
  nu se caută** — altfel, cine scrie comenzi ar putea afla, literă cu literă,
  cu ce nume intră în cont oricine are un cod.
- **Cine apare:** numai conturile pornite care au un cod, fiecare cu felul lui
  — „agent”, „client” ori „din casă”. Întâi codul scris întocmai, apoi oamenii
  casei, apoi clienții.
- **Cine are lista:** cine are caseta, adică cine scrie comenzi. Agentul de
  vânzări n-o are, iar la acceptarea unei cereri nu e casetă deloc (v. mai
  jos).
- **Dacă nu găsește pe nimeni,** o spune și nu te oprește: „Niciun cod și
  niciun nume nu seamănă cu ce ai scris. Poți salva și așa: un cod pe care
  nu-l are nimeni nu aduce comision prin el însuși. Comisionul merge atunci la
  cine l-a adus pe cumpărător, dacă l-a adus cineva.” Pe o comandă pe care o
  corectezi se mai adaugă o propoziție: „Altfel, un comision neplătit de pe
  comanda asta rămâne la cine îl are.”
- **Dacă sunt mai mulți de cinci,** scrie „Sunt mai mulți decât încap în
  listă. Scrie mai mult din nume ori alege un rând de mai sus. Ce ai scris nu
  e codul nimănui: salvat așa, nu aduce comision prin el însuși.”
- **Dacă n-a putut căuta:** „N-am putut căuta acum. Codul se poate scrie de
  mână, ca până acum.”

**Un nume scris nu e cod până nu alegi rândul.** Dacă scrii „octav”, îl vezi
pe Octavian în listă și apeși „Salvează” fără să-l alegi, comanda **nu** pleacă
la prima apăsare: formularul se oprește, deschide lista la loc și scrie
„„octav” nu e un cod ales din listă. Dacă e numele celui care a trimis
comanda, alege-i codul din lista de sub casetă. Ca să salvezi totuși cu „octav”
la cod, apasă încă o dată „Salvează”: un cod pe care nu-l are nimeni nu aduce
comision prin el însuși.” Alegi rândul lui și salvezi — pleacă „ples”, din
prima. Ori apeși a doua oară „Salvează” și pleacă „octav”, așa cum l-ai scris
(fișa 674, runda 2.7.5).

- **Întreabă numai când are de ce:** cât încă se caută, ori când căutarea a
  găsit oameni pe care nu i-ai ales. Când n-a găsit pe nimeni, ori n-a putut
  căuta, ori ce ai scris e chiar codul găsit, salvează din prima, ca până acum.
- **A doua apăsare e pentru același text.** Dacă schimbi ce e în casetă după
  întrebare, prima apăsare întreabă din nou.

**Cum desfaci** o comandă salvată cu un nume la cod: deschizi comanda, alegi
omul din listă și salvezi din nou.

Pe telefoanele cele mai înguste (320 de pixeli) se văd patru din cele cinci
rânduri ale listei; la al cincilea ajungi derulând lista. La un nume lung se
taie numele, cu „…”, nu codul.

**Agentul de vânzări nu mai are casetă** (hotărârea patronului, 2 octombrie
2026). Codul lui pleacă singur odată cu cererea, exact ca până acum — numai că
nu-l mai poate schimba în al altcuiva. În locul casetei citește un singur rând:
**„Cererea pleacă cu codul tău: …”**, cu codul lui. Dacă n-are cod pe cont, nu
vede nici rândul, iar cererea pleacă fără cod.

Pe cererea pentru un client care are deja cont la el, serverul nu scrie codul
(hotărârea din 14 septembrie 2026): comisionul îl hotărăște cel trecut pe
contul clientului drept cel care l-a adus, adică tot agentul. Codul ajunge pe
hârtie când cererea nu se leagă de niciun cont: un client nou, ori unul care nu
e al lui — adus de altcineva, ori unul vechi al casei. La cele două din urmă,
anunțurile „Cerere nouă” și „Comandă nouă” au și rândul „Atenție” (v.
`docs/02-panoul-de-setari.md`, la „Ce anunțuri primești, și ce scrie în ele”).

**La acceptarea unei cereri nu e casetă pentru nimeni.** Până la 2.6.0 se
desena, dar acceptarea nu citea ce scriai în ea: un cod pus acolo se pierdea la
salvare, fără un cuvânt. Codul cererii rămâne cel cu care a venit, arătat pe
cardul ei.

Sub casetă stă bifa **„leagă și contul cumpărătorului pe viitor”**, pornită
nebifată, la comanda nouă ca și la corectare (hotărârea patronului, 19
septembrie 2026). Nebifată, codul e numai al comenzii de față: ea intră în
„Recomandări” ca adusă de cel cu codul, iar comisionul ei se calculează întreg.
Bifată, și comenzile viitoare ale aceluiași cumpărător merg la același om, fără
să mai scrie nimeni nimic. Până pe 19 septembrie 2026, un cod pus la nașterea
comenzii lega contul cumpărătorului pe tăcute, pentru totdeauna.

Două lucruri e bine de știut:

- **Nu te poți recomanda pe tine.** Dacă cel cu codul e chiar cumpărătorul,
  legătura nu se scrie, iar în jurnal rămâne `referral_self_blocked`.
- **Pe o comandă deja salvată, codul îl îndreaptă cine poate corecta
  comanda**: din pornire administratorul, fondatorul și, din 19 septembrie
  2026, managerul. Vezi „Ce poate fiecare rol”, la coada ghidului.

### Clientul ajuns la prag, în formularul tău

Pragul de neplătite (v. „Când ajunge la pragul de neplătite”, mai sus) nu-l
oprește pe client numai în magazin. De la 2.7.0 oprește și comanda scrisă
pentru el din casă: altfel clientul oprit ar fi sunat la birou și ar fi
comandat mai departe. Din pornire, toți afară de fondator sunt opriți întocmai
ca el — agentul de vânzări, managerul și administratorul.

**Afli înainte să scrii.** De cum alegi din listă un client cu cont, sub caseta
„Client” apare cum stă: „Are 1 comandă neplătită (340,00 lei). Mai poate primi
una.”, iar la prag: „Are 2 comenzi neplătite (680,00 lei) — pragul e 2. Comanda
nu se va putea scrie.” Rândul apare și pe copia unei comenzi, înainte să alegi
ziua.

**La salvare, serverul refuză și nu scrie nimic.** Formularul rămâne deschis,
cu tot ce ai tastat, iar în el apare o casetă roșie: „Hotel Belvedere are 2
comenzi neplătite (680,00 lei) — pragul e 2. Nu am scris nimic.”, apoi lista
comenzilor neplătite și „Sold neplătit: 680,00 lei”. Când la numărătoare intră
și cereri încă neacceptate, fraza spune „neplătite ori în așteptare”.

Poarta e aceeași pe toate drumurile pe care ajunge o comandă pe contul lui:
comanda nouă, cererea trimisă de agent, acceptarea unei cereri, redeschiderea
unei cereri respinse, mutarea unei comenzi pe contul lui și scoaterea unei
comenzi din „Anulată”.

**Cine poate trece peste prag.** Cine are fapta „Trece peste prag” — din
pornire numai fondatorul, care o poate da mai departe din „Acces” — găsește în
caseta roșie întrebarea „Treci peste prag pentru Hotel Belvedere?”, cu
butoanele „Nu” și „Da, scrie comanda”. Încă de la alegerea clientului i se
spune: „Poți trece peste prag la salvare.” „Da” scrie comanda aceea, și numai
pe aceea: la următoarea, întrebarea vine din nou. Fiecare trecere rămâne în
Jurnal (`prag_ocolit`), pe contul clientului, cu cine a apăsat. O trecere nu se
desface; dacă ai scris comanda din greșeală, o anulezi ca pe oricare alta.

Celorlalți nu li se pune nicio întrebare. Au de ales între a-l ruga pe client
să plătească, a cere cuiva cu „Încasări” să treacă plata, dacă banii au venit
deja, și a-i cere comanda cuiva care poate trece peste prag.

**De pe Telegram nu se trece niciodată.** Butonul „✅ Acceptă” de sub anunțul
unei cereri răspunde, pentru un client la prag, „Clientul are comenzi neplătite
peste prag. Acceptă cererea din panou.” Cererea rămâne neatinsă, iar în panou
se judecă la fel ca oricare alta.

**Cui nu i se spun cifrele.** Cât datorează clientul vede numai cine scrie
comenzi, pentru orice client, și agentul de vânzări, pentru clienții lui.
Altcineva oprit la prag primește refuzul scurt, fără nume și fără sume:
„Clientul ăsta a ajuns la pragul de comenzi neplătite. Nu am scris nimic. Roagă
pe cineva care scrie comenzi să vadă cum stă cu plățile.”

---

## Contul făcut imediat după comandă

În fereastra de finalizare de pe `goldenpastry.ro`, vizitatorul are, de la
2.2.0, două butoane: **„Trimite cererea”** și **„Trimite cererea și fă-ți
cont”**.

Al doilea nu amână nimic. Cererea pleacă în clipa apăsării, exact ca la primul
buton, și o vezi în panou pe loc. Abia după ce a plecat, omul e dus pe pagina
de înscriere, cu numele firmei, telefonul, e-mailul și codul de recomandare
deja scrise. Îi rămân de ales numai numele de utilizator și parola.

**Dacă se răzgândește acolo și închide fila, cererea rămâne trimisă.** Nu se
pierde nimic: e o cerere ca oricare alta, fără cont, cu bulina roșie pe ea.

Butonul se arată numai vizitatorilor. Cine e deja intrat în contul lui n-are ce
cont să-și mai facă.

### Cum se leagă cererea de contul făcut după ea

Legătura nu se face după nume. Numele se poate tasta de oricine, iar pe ușa
aceea stă o pază anume, pusă odată cu migrarea `sql/010_origine_cont.sql`: un
cont făcut pe site nu moștenește comenzile scrise pe numele lui până nu
garantează cineva din casă pentru el.

Aici dovada e de altă natură. La trimiterea cererii, serverul dă browserului un
**jeton de o singură folosință** — un secret pe care nu-l mai are nimeni
altcineva și care spune un singur lucru: *browserul ăsta e chiar cel care a
trimis cererea asta*. Jetonul pleacă odată cu înscrierea, leagă acea cerere (și
comanda născută din ea, dacă ai apucat s-o accepți între timp) de contul nou, și
se stinge. A doua oară nu mai merge, și nu spune nimic despre cererile altora.

În baza de date nu stă jetonul, ci numai amprenta lui. Dacă cineva ar citi
tabelul, n-ar putea deschide cu el nimic.

Jetonul ține **o oră**. Cât să-ți faci contul, nu cât să te răzgândești mâine.

### Ce nu se completează singur

Formularul de pe site nu cere vizitatorului adresa și zona de livrare — le
stabiliți voi la confirmare. Deci nici pe pagina de înscriere nu au de unde
veni: casetele acelea rămân goale, iar omul le scrie el sau i le completați voi
mai târziu, din Conturi.

### Dacă a trecut ora

Contul se face oricum. Omul vede un ecran care îi spune pe față ce s-a
întâmplat: **contul e gata și e intrat în el, comanda e la noi și se pregătește,
dar n-o va găsi în „Comanda mea”** — legătura a expirat. Nu se inventează nimic
și nu se cere nimic din nou. O legați voi, la telefon, din formularul comenzii.

---

## Insigna de pe comandă: cine e omul

Pe fiecare comandă și pe fiecare cerere, în panoul de comenzi și în Panoul
Laborator, stă un semn care spune dintr-o privire pe cine ai în față:

| Semn | Înseamnă |
|---|---|
| pastilă **#1** | prima comandă a acestui cont |
| **bifă dublă**, ca la WhatsApp | cont care a mai comandat |
| **bulină roșie** | comandă fără cont |

Fiecare poartă numele scris în cuvinte, și pentru ochi (`title`), și pentru
cititorul de ecran: un semn fără nume nu se poate citi de cine îl vede prima
oară.

Trei lucruri de știut despre cifra din spatele lor:

- **Comenzile anulate nu se numără.** O comandă anulată nu face din nimeni
  client fidel, deci a doua comandă a unui cont a cărui primă comandă s-a
  anulat poartă tot **#1**. Anulata rămâne și ea cu **#1**, fiindcă nu există
  nicio comandă neanulată mai veche decât ea.
- **Contul șters lasă comanda fără cont.** Comanda rămâne în registru, dar
  legătura cu contul se rupe, deci capătă **bulina roșie**. Nu e o greșeală de
  arătat: comanda chiar nu mai e a nimănui. Nu se încearcă recunoașterea după
  nume — potrivirea pe nume e exact ce păzește casa de la migrarea
  `sql/010_origine_cont.sql` încoace.
- **Socoteala se face pe server, o dată pentru tot lotul.** Nu se numără din
  comenzile aduse pe ecran: lista e tăiată la 500 de rânduri pe zi, iar o
  numărătoare făcută în pagină ar spune „prima comandă” despre un client cu
  treizeci, doar fiindcă restul n-au încăput pe ecran.

Pe un server rămas în urmă, care nu trimite încă rangul, nu apare nicio
insignă — nimic nu se rupe.

---

## Dacă și-a uitat parola

Pe pagina de intrare, sub butonul de autentificare, e **„Ți-ai uitat parola?”**.
Scrie utilizatorul sau adresa de e-mail și primește pe e-mail un link către o
pagină unde își alege singur parola nouă.

Linkul ține **o oră** și se consumă la prima folosire. Cererea unui link nou îl
închide pe cel dinainte, deci rămâne valabil doar ultimul e-mail primit. La
schimbare se închid toate sesiunile contului — dacă a cerut parolă nouă fiindcă
bănuiește că o mai știe cineva, sesiunile aceluia dispar odată cu ea. Un cont
blocat de prea multe încercări greșite se deblochează tot atunci.

Funcționează și pentru contul tău de administrator. Fără asta, dacă îți uitai
parola rămâneai blocat definitiv: `setup.php` se șterge după instalare, iar
singura cale înapoi ar fi fost prin phpMyAdmin.

**Merge doar dacă e-mailul chiar pleacă.** De la mutarea pe serverul de azi și
până la 2.7.5 n-a plecat: serverul n-avea niciun program de poștă, iar pagina
răspundea mai departe că a trimis. De la 2.7.5 e-mailul are două drumuri —
căsuța firmei, completată în Setări → Notificări, în cartonașul „E-mail”, și
drumul direct, de pe server (v. `docs/02-panoul-de-setari.md`, la „E-mailul:
căsuța firmei și drumul direct”). Încearcă-l întâi cu butonul „Trimite e-mail
de test” de acolo, apoi cere o parolă nouă pentru contul tău, ca să vezi drumul
întreg. Și merge doar dacă pe cont e trecută o adresă: fără ea, omul nu
primește nimic.

**Vizitatorul citește mereu același răspuns:** „Dacă există un cont cu datele
astea, am trimis un e-mail cu instrucțiuni. Dacă nu primești nimic în câteva
minute, sună-ne: se poate ca pe cont să nu fie trecută o adresă.” La fel când
contul există și când nu, când are adresă și când nu, când e-mailul a plecat și
când a căzut — altfel formularul ar fi o listă de utilizatori reali pentru
oricine îl încearcă. De la 2.7.5 răspunsul vine și la fel de repede pentru
toți: pleacă înainte ca serverul să caute contul, iar e-mailul se trimite după
el. Până atunci, cererea pentru un cont adevărat dura cât trimiterea, iar cea
pentru unul închipuit se întorcea pe loc.

**Ce afli tu, și de unde.** Până la 2.7.5 un link neplecat nu lăsa nicio urmă
pe care s-o vezi. Acum fiecare cerere pentru un cont adevărat lasă un rând în
Jurnal, la contul acela:

| Ce scrie în Jurnal | Ce înseamnă |
|---|---|
| „Cerere de parolă nouă — e-mailul a fost luat în primire de căsuța de e-mail” | a plecat prin căsuța firmei |
| „Cerere de parolă nouă — e-mailul a fost predat programului de poștă al serverului; nu se știe dacă a ajuns” | a plecat pe drumul direct; poate fi în Spam |
| „Cerere de parolă nouă — e-mailul NU a plecat: …”, cu pricina | n-a plecat |
| „Cerere de parolă nouă — pe cont nu e trecută nicio adresă de e-mail, deci nu s-a trimis nimic” | contul n-are adresă |

Când e-mailul n-a plecat, în Setări, în caseta roșie de deasupra filelor, apare
și rândul „Parolă uitată — linkul n-a plecat (vezi Jurnalul)”. Nici în Jurnal,
nici în casetă nu stau linkul ori adresa omului: linkul e chiar cheia contului.
Pentru un cont care nu există nu se scrie nimic, nicăieri.

**Când te sună cineva că n-a primit nimic**, îl cauți în Jurnal. Dacă n-are
adresă pe cont ori e-mailul n-a plecat, îi dai tu o parolă provizorie din
Conturi, cu „Generează” (v. capitolul următor). Dacă aceeași adresă e
trecută pe două conturi și omul a scris adresa, nu pleacă niciun link —
serverul nu ghicește al cui e — și nici în Jurnal nu rămâne vreun rând: e
singura cerere pentru un cont adevărat pe care n-o găsești acolo. Roagă-l să
scrie numele de utilizator.

**O margine rămasă.** Pe serverul de azi, cine măsoară nu răspunsul, ci clipa
în care se închide legătura mai poate deosebi un cont adevărat, cu adresă, de
unul închipuit. Un om cu un browser nu vede asta; un program scris anume, da.
Pragul de zece cereri de pe aceeași conexiune ține încercările puține. E
scrisă, cu ce ar închide-o, ca fișă deschisă în
`docs/06-defecte-de-reparat.md`.

---

## Parola pe care i-o dai tu, și parola lui

Când faci un cont nou, sau când îi pui o parolă nouă unui cont existent — din
fișa lui, cu „Generează” ori cu „Schimbă parola” —, parola pe care i-o dictezi
este **provizorie**. Contul e marcat ca atare: în Conturi
placa lui poartă insigna **PAROLĂ PROVIZORIE** (pe o placă îngustă, numai
**PROVIZORIE**), iar în fișa lui, sub nume, scrie „parolă provizorie, de
schimbat la prima intrare”. Antetul paginii numără câte conturi sunt în starea
asta, ca să nu le cauți unul câte unul.

**Parola propusă se dictează ușor (de la 2.7.5).** „Generează”, din fișa
contului, și „Propune o parolă” — de sub „Schimbă parola” și din pagina
**Parola mea** — dau o parolă de felul „caramel4821”: un cuvânt din bucătărie,
scris numai cu litere mici fără diacritice, lipit de patru cifre. Până la 2.7.5
ieșea „brioșă-4821”: cu „ș” și „ă”, pe care omul de la celălalt capăt al
telefonului nu le găsește ușor pe tastatură, și cu o liniuță pe care o putea
scrie și „-”, și „_”. Numărul începe acum cu o cifră de la 2 în sus, ca 0 și 1
să nu fie luate drept „o” și „l” lângă litere. Cele două butoane nu fac același
lucru: „Generează” pune parola pe cont pe loc și ți-o arată o singură dată —
„Notează parola acum: … — nu mai poate fi afișată după ce pleci de pe pagină.”
—, iar „Propune o parolă” numai o scrie în casete, și se pune abia când apeși
butonul de salvare. Parolele date până acum, cu liniuță, rămân bune: nimic nu
s-a schimbat pe conturile care le au.

La prima intrare cu o parolă provizorie, omul e dus direct la pagina
**Parola mea** și nu ajunge nicăieri altundeva până nu alege. Pagina nu-i mai
pune formularul în față, ci **întrebarea**, cu două butoane:

- **„Vreau să schimb parola”** deschide, peste pagină, o fereastră cu
  formularul de întotdeauna: parola de acum, apoi cea nouă de două ori, minim 8
  caractere, cu butonul care propune una.
- **„Păstrez parola actuală”** stinge semnul pe loc și îl duce în cont, fără să
  schimbe nimic. Parola rămâne cea dictată de tine.

Se ajunge la pagină oricând, nu doar la prima intrare: din meniul lateral al
panourilor, din antetul panoului clientului, și din meniul contului din
magazin. Întrebarea se arată **numai** cât timp contul poartă semnul; după, e
formularul obișnuit.

**Contul comun al tabletelor nu are „Parola mea”**, de la 2.6.0: rândul a ieșit
și din meniul lateral, și din meniul contului din magazin. Parola lui e a
întregii ture, iar cine ar fi schimbat-o de pe o tabletă ar fi închis panoul
tuturor. Dacă cineva deschide totuși pagina cu adresa scrisă de mână, citește
„Acesta e contul comun al tabletelor: parola lui nu se schimbă de aici.” și,
dedesubt, „Codul tabletelor îl schimbă un administrator, din Setări.” Codul stă
în Setări, în fila „Tableta” (până la 2.7.0 fila se chema „Acces”), și îl
schimbă cine are pagina Setări — din pornire administratorul și fondatorul.

**Cine poate păstra: oricine are semnul, personalul inclusiv.** A fost o
alegere a patronului, luată știind urmarea, și e bine să fie scrisă aici:
parola pe care ai dictat-o tu la telefon rămâne o parolă care intră în acel
cont, iar pentru un cont de personal asta înseamnă comenzile, conturile și
datele celorlalți. Dacă ai dictat parola cu voce tare într-o încăpere plină,
schimb-o tu însuți din „Conturi” — butonul „Generează” pune semnul la loc.

**Păstrarea se scrie în Jurnal** (`password_kept`), lângă schimbările de parolă.
Deci se poate afla oricând cine a ales să rămână cu parola dictată, și când.

> Alegerea asta nu slăbește cu nimic poarta de pe server. Contul cu semnul pus
> tot nu ajunge nicăieri până nu trece prin pagina Parola mea: serverul refuză
> orice altă cerere cu 403, iar „Păstrez” e singura ușă nouă pe care o lasă
> deschisă. Pe un server încă neactualizat, butonul „Păstrez” ia 404, iar
> pagina cade înapoi pe schimbarea obligatorie de dinainte — nu se blochează.

Din clipa în care și-a pus parola lui, rândul din Conturi se schimbă în
„parolă proprie, schimbată <data>”. Insigna dispare.

> Până la migrarea `sql/005_parola_schimbata.sql`, pagina scria „parolă
> schimbată” urmat de data **ultimei autentificări**. Un cont care tocmai
> intrase cu parola dictată de tine la telefon arăta ca și cum și-ar fi pus de
> mult parolă proprie. Te uitai exact acolo ca să afli care parole dictate mai
> deschid conturi, și pagina îți răspundea pe dos. De la migrarea aceea, data
> adevărată se ține într-o coloană separată, scrisă doar când omul își schimbă
> singur parola — din pagina Parola mea sau prin linkul de recuperare.

---

## Cât ține sesiunea

| Cine | Zile |
|---|---|
| client | 30 |
| oricine din casă: fondator, administrator, angajat, manager, agent de vânzări, om de laborator | 7 |
| tableta din laborator (contul comun) | 365, împins înainte cât e folosită |

Clientul comandă de pe telefonul lui, deci o lună fără parolă nu deranjează pe
nimeni. Oamenii din patiserie lucrează pe calculatoare la care ajunge toată
tura, deci sesiunea lor se închide mai repede — și nu se reînnoiește singură,
tocmai ca să nu rămână deschisă pentru cine intră după tine.

Tableta e altceva: nu e dispozitivul nimănui, stă pornită în laborator și se
deschide cu codul turei. Sesiunea ei se împinge înainte la fiecare zi în care e
folosită, deci practic nu se închide niciodată de la sine; se închide când
schimbi codul din Setări. Se schimbă din `api/config.php`, cheile
`session_days_client`, `session_days_staff` și `session_days_panou`.

---

## Ce ține contul

| Câmp | La ce folosește |
|---|---|
| Utilizator, parolă | autentificarea |
| Nume (în Conturi: „Nume afișat”) | numele persoanei |
| Client (în Conturi: „Numele clientului din comenzi”) | numele firmei — el apare pe comandă, în laborator și pe rută |
| Telefon, e-mail | completate automat în comanda de pe site |
| Adresă (în Conturi: „Adresa firmei”) | adresa de livrare, completată automat și actualizată de client |

Adresa o scrie clientul, la prima comandă, și o poți îndrepta și tu: în fișa
contului de client, din Conturi, caseta **„Adresa firmei”** stă lângă „Numele
clientului din comenzi” și „Zona”. Se salvează când ieși din câmp sau când
apeși Enter, fără alt buton; ca să desfaci, scrii la loc adresa de dinainte. La
acceptarea unei cereri o poți corecta și în formularul de comandă.

De la 2.7.0 contul de client mai ține un lucru, care nu e în tabel fiindcă nu-l
completează nimeni la înscriere: **pragul lui de comenzi neplătite**, gol cât
clientul merge după pragul general (v. „Pragul de neplătite al unui client”,
mai jos).

---

## Pagina Conturi: pastile, plăci și fișe

Până în 2.5.0, `lab.goldenpastry.ro/conturi` arăta un singur cont pe rând, cu
fișa lui întreagă: toate casetele și toate butoanele, unul sub altul. Cu
treizeci de clienți și zece oameni în casă, contul căutat era la câteva ecrane
distanță. Patronul a cerut să vadă mai multe deodată și să le poată alege după
rol.

**Pastilele de rol.** Sub căutare stă un rând de pastile, fiecare cu numărul ei
de conturi: *Toate · Fondatori · Administratori · Manageri · Agenți de vânzări ·
Angajați · Oameni de laborator · Clienți*, în ordinea casei, de la cine
hotărăște la cine cumpără. Un rol fără niciun cont nu are pastilă. Când toată
lista are un singur rol, rândul nu se mai desenează, fiindcă n-ar avea ce
alege.

Numărul de pe pastilă e al listei întregi, nu al căutării. E dinadins: un număr
care s-ar schimba cu fiecare literă tastată ar muta pastilele sub degetul care
tocmai vrea să apese una.

**Căutarea caută în rolul ales.** Cu „Clienți” apăsat, „maria” găsește
clientele, nu și pe colega Maria de la laborator, iar sub casetă scrie câte au
ieșit și din câte („2 din 6 conturi · clienți”). Când în rolul ales nu iese
nimic, dar în altul da, pagina nu se oprește la „niciun cont”: pune dedesubt
butonul **„Caută în toate rolurile · 1 potrivire”**, care te duce acolo dintr-o
apăsare. Altfel „niciun cont” ar fi părut un răspuns despre toată casa.

În rest, căutarea e cea de dinainte: nume, utilizator, e-mail, firmă, zonă sau
rol, fără diacritice — „gheorghita” îl găsește pe Gheorghiță. În casetă scrie
acum numai „Caută un cont”, iar lista câmpurilor stă în eticheta ei, care se
arată ținând mausul deasupra. Fraza întreagă de dinainte se tăia la jumătate pe
telefon.

**Trei vederi**, din butoanele cu desen de lângă căutare:

| Vedere | Ce arată | Pe telefon | Pe tabletă | Pe calculator |
|---|---|:-:|:-:|:-:|
| **Listă** | fiecare cont cu fișa lui întreagă, ca înainte | 1 | 1 | 1 |
| **Patru pe rând** | plăci | 2 | 3 | 4 |
| **Șase pe rând** | plăci mai mici | 3 | 4 | 6 |

Pe butoane nu scrie nimic, au numai desenul. Numele lor întreg se arată ținând
mausul deasupra și îl aude cititorul de ecran: „Listă: fiecare cont cu fișa lui
întreagă”, „Plăci: patru pe rând” și „Plăci: șase pe rând”. În ghidul acesta le
spunem pe scurt, ca în tabel.

Numărul din nume e cât pune pagina **cel mult** pe un rând. Pune mai puține
numai acolo unde placa n-ar mai avea loc de nume: pe tabletă, meniul din stânga
îi ia din lățime. Coloanele din tabel sunt cele măsurate la 390, 820 și 1440 de
pixeli.

Vederea aleasă se ține minte **în browserul acela**, ca în Catalog. Unde nu s-a
ales încă nimic, pagina pornește din „Patru pe rând”. Nu vine din Setări: e
unealta celui care lucrează, nu o hotărâre a casei.

**Placa** arată monograma (primele litere din nume, pe culoarea rolului),
numele, utilizatorul, rolul, semnele contului și ultima intrare, sau „n-a intrat
încă niciodată”. În „Șase pe rând” ultima intrare nu mai încape, iar rolurile
lungi se scurtează: „Admin”, „Agent”, „Laborator”.

Semnele sunt trei:

| Semn | Înseamnă |
|---|---|
| **contul tău** (în „Șase pe rând”: **al tău**) | contul cu care ești intrat acum |
| **parolă provizorie** (pe o placă îngustă: **provizorie**) | parola e încă cea dictată de tine, v. mai jos |
| **cont oprit** (în „Șase pe rând”: **oprit**) | contul nu mai poate intra în aplicație |

„Parolă provizorie” se scurtează după lățimea plăcii, nu după vedere. Aceeași
placă din „Patru pe rând” are loc de insigna întreagă pe telefon, dar nu și pe
tabletă, unde meniul îi ia din lățime. Cititorul de ecran aude mai departe
ambele cuvinte, iar insigna întreagă stă și în eticheta ei.

**Numele lungi se micșorează, nu se rup.** Pe placă, numele are cel mult două
rânduri. Lăsat în voia lui, un cuvânt mai lung decât placa s-ar rupe la
margine, în mijlocul lui („Restaurant” pe un rând, „ul” pe următorul), iar unul
prea lung pentru al doilea rând s-ar termina în „…”, ca „Ștefan / Hohenzol…”.
De aceea pagina măsoară cel mai lung cuvânt din fiecare nume și, unde n-ar
încăpea, micșorează numele cât îi trebuie. Coboară cel mult până la mărimea
rândurilor de sub el, 13 pixeli în „Patru pe rând” și 12 în „Șase pe rând”:
mai mărunt decât utilizatorul lui, numele n-ar mai arăta a nume. Un cuvânt
care nu încape nici așa tot se rupe. La 390 de pixeli, în „Șase pe rând”,
„Constantinescu” ar avea nevoie de 11,5.

Mărimea se ia din lățimea plăcii, deci se potrivește singură pe tabletă și
când tableta se rotește. Două nume de pe același rând pot ieși astfel de mărimi
puțin diferite: e prețul cuvintelor întregi.

**Placa se deschide pe loc.** O apăsare pe ea desface fișa întreagă a contului
— aceeași din „Listă”, cu toate casetele și butoanele ei — chiar sub rândul
plăcii, pe toată lățimea. Se strânge din butonul **„Strânge”** din colțul ei,
cu încă o apăsare pe placă sau, pe telefon, cu butonul Înapoi, iar focusul se
întoarce pe placă. O singură fișă stă deschisă odată. Deschiderea alteia o
strânge pe prima, și tot ce rămăsese început în ea — o parolă, un PIN, un cod de
recomandare, o ștergere armată — se închide odată cu ea. Altfel te-ar fi
așteptat la întoarcere, pe un cont pe care nu-l mai vedeai.

**Ordinea**: după rol, în ordinea pastilelor, iar în același rol după nume.
Serverul trimite conturile ca înainte, iar ordinea se face în pagină. Până în
2.5.0 lista venea în ordinea în care baza de date își ține rolurile, și
clienții ieșeau la mijloc, între angajați și fondatori.

**Fondatorul are insigna lui, aurie**, și monograma la fel. Până în 2.5.0
purta insigna cenușie a angajaților, deci contul care dă și ia rolurile
celorlalți arăta ca oricare altul.

**Contul oprit se vede.** Serverul știe de mult să oprească un cont, iar contul
oprit nu mai intră în aplicație, cu nicio parolă. Pagina însă nu-l arăta: un
cont oprit arăta ca unul bun. Acum poartă insigna roșie „cont oprit”, pe placă
și în capul fișei.

### Comutatorul „Cont pornit”

Tot din 2.5.0, contul se oprește și se pornește chiar din pagină. În fișa
fiecărui cont, deasupra butoanelor de jos, stă comutatorul **„Cont pornit”**,
iar sub el un rând care spune ce înseamnă starea de acum.

**Oprirea cere două apăsări.** Prima nu trimite nimic: comutatorul se stinge,
rama rândului se face roșie, iar dedesubt apare întrebarea „Oprești
contul…?”, care spune pe scurt ce urmează — omul nu mai poate intra, iese pe
loc dacă e conectat, iar comenzile și istoria lui rămân —, cu butoanele
**„Renunț”** și **„Oprește contul”**. Abia al doilea pleacă spre server.
Deschiderea altei fișe renunță singură, ca la o ștergere armată: o oprire
lăsată în așteptare te-ar fi pândit la întoarcere, pe un cont pe care nu-l mai
vedeai.

**Pornirea e dintr-o apăsare**, fiindcă nu strică nimic: contul pornit la loc
intră cu parola lui de dinainte.

**Oprit nu înseamnă șters.** Comenzile, cererile și istoria contului rămân pe
loc. Dacă omul e conectat când îl oprești, serverul îi închide sesiunile pe
loc, iar parola nu-l mai lasă să intre până nu-l pornești la loc. Cât pleacă
cererea, comutatorul stă stins și scrie „Opresc contul…” ori „Pornesc
contul…”; răspunsul apare sus, în mesajul paginii.

**Cine pe cine.** Regulile sunt ale serverului; pagina doar le arată dinainte.
Unde comutatorul n-ar putea fi apăsat, de la 2.6.0 **nu se mai desenează**: în
locul lui scrie starea, **„Cont pornit”** ori **„Cont oprit”**, iar dedesubt
motivul. Până atunci stătea acolo un comutator stins, pe care omul îl vedea și
nu-l putea folosi.

- nimeni nu-și poate opri propriul cont — pe fișa ta scrie „E contul cu care
  ești conectat: nimeni nu-și poate opri propriul cont.”;
- contul unui administrator ori al unui fondator îl oprește **și** îl pornește
  numai un fondator, cât timp există vreunul pornit — celorlalți le scrie
  „Contul unui administrator îl oprește și îl pornește numai un fondator.”
  (ori „…unui fondator…”). Într-o casă fără niciun fondator pornit, contul unui
  administrator îl oprește și îl pornește alt administrator, iar cine are
  „Conturi” fără să fie el însuși administrator citește „Contul unui
  administrator îl oprește și îl pornește numai un administrator.”;
- contul de sistem „panou”, al tabletelor din laborator, n-are deloc rândul:
  accesul lui cu cod se oprește din Setări, din fila „Tableta”.

Tot în fișă, deasupra rândului „Cont pornit”, stă comutatorul **„Poate confirma
livrarea”**: cine îl are pune etapa finală de pe „Rută” și, dacă are panoul,
din „Comenzi”; cine nu, nu. Se scrie de cum îl apeși, fără alt buton, și se
desface apăsând încă o dată. Clientul și omul de laborator nu-l au deloc. Ce înseamnă pentru laborator și
pentru șofer, de la „Gata de livrare” încoace, e scris în
`docs/02-panoul-de-setari.md`, la „„Gata de livrare”: unde se oprește
laboratorul”.

Al doilea rând e mai nou decât pare. Până în 2.5.0 serverul cerea un fondator
numai la **oprire**, așa că un administrator putea porni la loc contul unui
administrator oprit de fondator, iar omul scos din firmă intra iar, cu toate
drepturile. Pagina n-avea buton, deci ușa cerea o cerere scrisă de mână; cu
comutatorul, ar fi fost o apăsare. S-a închis înaintea lui, iar povestea e în
docs/06, printre defectele de conturi.

### Pragul de neplătite al unui client

De la 2.7.0, în fișa unui cont de **client** stă rândul **„Prag de
neplătite”**: câte comenzi neplătite poate avea clientul acesta până când
comenzile noi i se opresc. Ce se numără și ce vede clientul când ajunge acolo
scrie mai sus, la „Când ajunge la pragul de neplătite”.

Rândul are trei butoane:

| Butonul | Ce înseamnă | Cum se scrie |
|---|---|---|
| **„General”** | clientul merge după pragul pus pentru toți — din pornire 2 | dintr-o apăsare |
| **„Al lui”** | are un prag numai al lui, de la 1 la 99 | deschide caseta „Al lui: câte comenzi neplătite”, cu butoanele „Salvează pragul” și „Renunț”; după salvare, butonul scrie „Al lui: 5” |
| **„Fără limită”** | nu e oprit niciodată, oricâte comenzi neplătite are | dintr-o apăsare |

**Golul, zero și numărul nu sunt același lucru.** Clientul fără prag al lui —
golul, adică „General” — merge după pragul tuturor și se mișcă odată cu el.
Zero înseamnă „fără limită”, de aceea în caseta lui „Al lui” nu se poate scrie:
„Scrie un număr de la 1 la 99. Pentru un client care nu se oprește niciodată,
alege „Fără limită”.” Iar un număr al lui rămâne al lui și când schimbi pragul
general.

Sub butoane, un rând spune ce urmează din alegerea făcută. La pragul 1: „Cu
pragul 1 nu există avertizare: cât are o comandă neplătită, nu mai poate
trimite alta până la plată.” La „Fără limită”: „Nu e oprit niciodată.” Iar pe
„General”, când pragul tuturor e zero: „Pragul general e acum „fără limită”: nu
e oprit niciodată.” Clientul cu prag al lui se recunoaște și din listă: placa
lui poartă insigna „prag 5” ori „fără limită”.

**Cine vede rândul.** Numai cine are fapta „Pragul de neplătite al unui
client” — din pornire fondatorul. Administratorul, deși are „Conturi”, nu-l
vede până nu primește bifa din „Acces” (v. „Ce poate fiecare rol”, mai jos).
Pe butonul „General” numărul — „General (2)” ori „General (fără limită)” —
apare numai cui are și pagina Setări, de unde se citește pragul tuturor;
celorlalți le scrie doar „General”. Rândul nu se desenează pe conturile care nu
sunt de client și nici pe un server fără `sql/032_plati_si_prag.sql`.

**Cum desfaci.** Apeși butonul de dinainte: „General” îl întoarce pe client la
pragul tuturor. „General” și „Fără limită” se scriu de cum le apeși, „Al lui”
la „Salvează pragul”. Dacă serverul refuză, motivul se scrie chiar pe rând.

Același prag se mai poate pune din „Încasări”, din vederea „Pe clienți”: pe
cartonașul fiecărui client stă butonul „Prag: …”. Acolo butoanele casetei se
cheamă „Pune pragul” și „Renunță”; fac același lucru.

---

## Ce urmează comenzii

Nimic nu intră în producție singur. Comanda clientului ajunge în
`lab.goldenpastry.ro/comenzi`, în fila **Cereri de pe site**, și așteaptă acolo
până o accepți. La acceptare îi dai ziua, intervalul, zona și modul de onorare —
abia atunci primește număr și apare în laborator.

---

## Ce poate fiecare rol

Până la 2.7.0, cine ce vede era scris în program: ca managerul să primească
Catalogul trebuia o versiune nouă. De la 2.7.0 împărțirea o ține fondatorul,
din pagina **„Acces”** (migrarea `sql/033_acces.sql`), cu câte o bifă pentru
fiecare rol și fiecare panou. Tabelele de mai jos arată deci numai
**pornirea**: ce are fiecare rol în ziua instalării și la ce se întoarce
„Acces” când apeși „Înapoi la început”. Ce e bifat acum la tine citești în
pagină, nu aici.

Un lucru a rămas cum era: fiecare cont vede în meniu **numai panourile pe care
le poate chiar folosi**. Un buton care ar duce la „nu ai acces” nu se
desenează, iar bifa se verifică pe server, la fiecare cerere — meniul e oglinda
porților, nu o pază în plus.

### Panourile, din pornire

| Panoul | fondator | administrator | manager | agent de vânzări | angajat | om de laborator |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Comenzi | da | da | da | da, **numai ale oamenilor lui** | — | — |
| Laborator | da | da | da | — | da | — |
| Rută | da | da | da | — | da | — |
| Depozit | da | da | da | — | da | — |
| Pontaj | da | da | da | — | da | **da, mereu** |
| Catalog | da | da | — | — | — | — |
| Conturi | da | da | — | — | — | — |
| Recomandări (toate codurile) | da | da | — | — | — | — |
| Jurnal | da | da | — | — | — | — |
| Setări | da | da | — | — | — | — |
| Statistici | da | — | — | — | — | — |
| Încasări | da | — | — | — | — | — |

Numele din prima coloană sunt cele din „Acces” și din Jurnal. Coloana
fondatorului nu se poate schimba: el are mereu tot. **„Statistici” și
„Încasări” pornesc numai la el**, fiindcă sunt cifrele și banii casei; le poate
da oricărui rol, cu o bifă. Contul comun al tabletelor ține de rolul „angajat”,
cu un plafon al lui (mai jos, la „Ce nu se dă din „Acces””).

Cinci intrări din meniu nu sunt rânduri în tabelul de sus, fiindcă nu se dau
din bife:

| Intrarea | Cine o are |
|---|---|
| Acces | numai fondatorul |
| Recomandări, cu codul tău | agentul de vânzări, mereu; oricine altcineva din casă, dacă are cod; clientul, numai cu cod |
| Istoric | cine are fapta „Scrie comenzi și acceptă cereri”, agentul de vânzări și clientul |
| Comanda mea | numai clientul |
| Parola mea | oricine, afară de contul comun al tabletelor |

**„Recomandări” se hotărăște și după cod, nu numai după bifă.** Bifa din
„Acces” se cheamă „Recomandări (toate codurile)” și dă pagina întreagă, cu
codurile tuturor. Fără ea pagina se deschide tot, dar numai cu ale tale: un cod
se dă din „Conturi” oricărui cont care nu e de sistem, iar cine îl are câștigă
comision, oricare i-ar fi rolul. De aceea managerul sau angajatul **cu** cod
vede „Recomandări” în meniu, ca să-și găsească banii; cel fără cod nu vede
nicio schimbare. Clientul o vede numai cu cod, agentul de vânzări întotdeauna —
el își poate face codul chiar de acolo. Contul comun al tabletelor n-o are
niciodată: lui nu i se face cod.

**Omul de laborator are, din pornire, o singură ușă.** Vede **Pontaj** și
**Parola mea**, nimic altceva, iar „Pontaj” e singura căsuță din „Acces” care
nu i se poate lua: fără ea n-ar mai avea nicio pagină. În fața tabletei din
laborator nici nu intră cu parolă: apasă pe numele lui pe ecranul deschis de
tură. Rândul din meniu e pentru ziua în care își deschide singur pagina, de pe
telefon.

### Faptele, din pornire

Un panou deschide o pagină. O **faptă** e ce are voie să facă într-un panou
cine îl are deja. Aproape fiecare faptă ține de un panou: bifată pe un rol care
n-are panoul ei, nu ajută la nimic, iar „Acces” o spune lângă bifă. Una singură
nu ține de niciunul, „Costul produselor și profitul”: ea nu deschide nimic, ci
adaugă cifre pe pagini pe care omul le are deja, „Catalog” și „Statistici”.
Fără niciuna din ele, bifa n-are unde să se vadă.

| Fapta, cu numele din „Acces” | Ține de panoul | Ce îngăduie | Cine o are din pornire, pe lângă fondator |
|---|---|---|---|
| Scrie comenzi și acceptă cereri | Comenzi | comanda nouă scrisă de-a dreptul, corectarea uneia salvate, acceptarea, respingerea și redeschiderea unei cereri; tot ea dă rândul „Istoric” | administratorul, managerul |
| Șterge comenzile anulate | Comenzi | „Șterge definitiv”, pe o comandă anulată | administratorul |
| Conduce tura (raportul de ore, anunțurile) | Pontaj ori Laborator | raportul de ore și anunțurile de pe ecranul laboratorului | administratorul, managerul |
| Corectează pontajul | Pontaj | ora de plecare pe care omul a uitat s-o ponteze | administratorul |
| Pune ordinea opririlor | Rută | ordinea în care merge mașina | administratorul |
| Plătește comisioane | Recomandări | „Bifează ca plătit”, la comisionul unei recomandări | administratorul |
| Schimbă starea plății | Încasări | comutatorul de pe rândul unei comenzi, în „Încasări” | nimeni |
| Raportul de bani pe Telegram | Încasări | cardul „Raport pe Telegram”, din „Încasări”, și cutia „Raport de bani pe Telegram”, din Setări → Notificări | nimeni |
| Pragul de neplătite al unui client | Conturi | pragul unui client și pragul general | nimeni |
| Trece peste prag | Comenzi | comanda scrisă pentru un client oprit la prag, cu confirmare | nimeni |
| Prețurile ingredientelor | Depozit | prețurile de cumpărare, în Depozit | nimeni |
| Costul produselor și profitul | niciunul | costul unui produs, în Catalog, și profitul, în Statistici | nimeni |

Ultimele șase sunt despre bani și pornesc numai la fondator: de la 2.7.0, tot
ce ține de bani e, din pornire, numai al lui. Agentul de vânzări, angajatul și
omul de laborator nu au, din pornire, nicio faptă.

### Pagina „Acces”

`lab.goldenpastry.ro/acces`, în meniul fondatorului, sub „Setări”. **O deschide
numai fondatorul**, iar asta nu e o bifă: pagina nu e rând în propria ei grilă
și nu se poate da altcuiva. Oricine altcineva citește „Contul „…” nu are acces
aici. Cine ce vede în aplicație hotărăște numai fondatorul.”

**Cum se citește.** Pe calculator e o grilă. Pe rânduri stau întâi
**„Panouri”** („Intrările din meniu. Fără bifă, pagina nu se deschide și
serverul nu-i dă datele.”), apoi **„Fapte”** („Ce are voie să facă într-un
panou cine îl are deja.”). Pe coloane stau rolurile: Fondator, Administrator,
Manager, Agent de vânzări, Angajat, Om de laborator. Sub numele unei fapte
scrie de ce panou ține: „Ține de Comenzi.” Pe un ecran îngust, sub 1024 de
puncte, grila nu mai încape și se face listă: alegi rolul din pastilele de sus
și îi vezi bifele una sub alta. Sub titlu, pagina numără cât te-ai depărtat de
pornire: „3 bife mutate față de prima zi” ori „Totul e ca în prima zi.”

Legenda spune cele patru semne ale unei căsuțe: „altfel decât în prima zi”,
„mutată, încă nesalvată”, „nu se poate schimba” și „bifată, dar fără panoul
ei”.

**Căsuțele care nu se pot schimba** sunt de trei feluri:

- toată coloana fondatorului. Bifele ei nu sunt butoane și nu răspund la
  apăsare. De ce, scrie sub grilă: „Fondatorul are mereu tot, iar pagina asta e
  numai a lui: nu se poate da altcuiva din bife.”;
- „Pontaj”, la omul de laborator, e mereu bifată: „E singura pagină a omului de
  laborator.”;
- „Scrie comenzi și acceptă cereri”, la agentul de vânzări, e mereu nebifată:
  „Comenzile agentului trec pe la birou, ca cereri.”

Ultimele două își spun motivul când le apeși: sub numele rândului apare, de
pildă, „Agent de vânzări: nu se poate schimba. Comenzile agentului trec pe la
birou, ca cereri.” Pe ecranul îngust, unde grila e listă, motivul stă scris de
la început sub comutatorul rândului, iar fondatorul nu e printre pastilele de
rol: n-ai ce muta la el.

**O faptă are nevoie de panoul ei.** Poți bifa „Prețurile ingredientelor” la
agentul de vânzări, care n-are „Depozit”, dar bifa nu-i dă nimic: serverul cere
și panoul. Pagina nu te oprește; îți scrie lângă bifă „Agent de vânzări: fără
Depozit bifa nu ajută.” și ți-o mai spune o dată, înainte de salvare.

**Salvarea.** Bifele mutate nu pleacă nicăieri până nu apeși **„Salvează”**,
din bara care apare odată cu prima bifă mutată și le numără („3 schimbări
nesalvate”). „Renunță”, de lângă el, aruncă bifele nesalvate, după ce întreabă.
Cardul **„La salvare”** îți spune în cuvinte ce urmează să se schimbe —
„Manager primește „Catalog”.”, „Angajat pierde „Depozit”.” — și te previne
unde e cazul:

- „„Statistici” rămâne numai la fondator. Cât lipsește el, panoul nu-l mai
  deschide nimeni.”, când iei un panou tuturor rolurilor;
- „Manager rămâne cu „Plătește comisioane” bifată, dar fără panoul de care ține
  bifa nu ajută.”, când iei panoul și lași fapta;
- „Cine are „Conturi” face conturi noi și dă parole noi celorlalți, deci poate
  intra în locul lor. De conturile de administrator și de fondator nu se poate
  atinge.”, când dai „Conturi” unui rol.

Salvarea e **tot ori nimic**: dacă serverul refuză o singură bifă, nu se scrie
niciuna, iar pagina spune „Bifele nu s-au salvat. Niciuna.” Reușita se vede
sus: „Bifele sunt salvate.”, cu „Serverul ține cont de ele de pe acum. Cine e
deja intrat își vede meniul schimbat la următoarea pagină deschisă.” Nu
trebuie deci să scoți pe nimeni din cont: o ușă închisă de tine e închisă de la
prima lui cerere, chiar dacă meniul vechi îi mai stă o clipă pe ecran.

Fiecare salvare rămâne în **Jurnal**, pe zona „Acces”, cu ce s-a mutat:
„Manager: +Catalog, −Depozit · Angajat: +Jurnal”.

**Cum desfaci.** O bifă pusă greșit o muți la loc și salvezi din nou. Ca să
arunci tot ce ai schimbat vreodată, apasă **„Înapoi la început”**; butonul se
arată numai când e ceva schimbat pe server. Întreabă întâi — „Te întorci la
împărțirea din prima zi?”, cu „Păstrează cum e” și „Da, înapoi la început” — și
îți spune câte bife se pierd. După aceea fiecare rol are ce avea când a fost
instalată aplicația, iar în Jurnal rămâne scris la ce ai renunțat. De la
„Înapoi la început” nu e alt drum înapoi decât să pui bifele din nou, cu mâna:
rândul din Jurnal îți spune care erau.

**Dacă ieși cu bife nesalvate**, pagina întreabă: „Arunci schimbările
nesalvate?”, cu „Rămân” și „Le arunc” (v. `docs/02-panoul-de-setari.md`, la
„Paza formularelor nesalvate”).

**Pe un server fără migrarea 033** pagina se deschide, dar nu primește apăsări:
spune „Bifele nu se pot muta încă.” și numește fișierul de rulat,
`sql/033_acces.sql`. Până atunci aplicația merge pe împărțirea din pornire,
adică pe tabelele de mai sus, iar pagina ți-o arată.

**În „Conturi” mai e un „Acces”, și nu e pagina.** În fișa fiecărui cont, rolul
stă sub eticheta „Acces”: o listă, unde îl poți schimba, ori numai numele lui,
unde nu. Lista spune ce rol are omul; pagina „Acces” hotărăște ce înseamnă
rolul acela. Lângă ea scrie pe scurt ce are rolul. După ce i-ai mutat o bifă,
rândul numește panourile pe care le are acum și se încheie cu „· schimbat din
„Acces””, iar ținând mausul deasupra listei citești pe larg „Vede: …. Poate:
….”, cu încheierea „Rolul a fost schimbat din pagina „Acces”: aici scrie ce are
acum, nu ce avea din pornire.” Fondatorului i se mai scrie, sub fișă, „Ce
panouri vede fiecare rol se hotărăște în pagina Acces.”, cu legătură spre
pagină.

### Ce nu se dă din „Acces”

„Acces” împarte panouri și fapte. Câteva lucruri țin însă de **rang** — de
faptul că un cont e de administrator ori de fondator — și rămân acolo orice ai
bifa:

- **Conturile conducerii.** Cine primește „Conturi” face conturi noi și dă
  parole noi clienților, agenților și angajaților, dar de contul unui
  administrator ori al unui fondator nu se poate atinge: „Contul unui
  administrator ori al unui fondator îl poate schimba numai cineva din
  conducere.” Rolul de administrator îl dă și îl ia numai un fondator, cât timp
  există vreunul pornit; într-o casă fără niciun fondator pornit îl dă și îl ia
  un administrator. Rolul de fondator îl dă, din „Conturi”, numai alt fondator.
  A doua ușă spre el e cererea: orice cont, afară de cel comun al tabletelor,
  poate apăsa **„Cere drept de fondator”**, pe cardul „Contul de fondator” din
  „Parola mea”, iar linkul de confirmare pleacă pe adresa firmei, cea de la
  „E-mail public” din Setări. Hotărăște deci cine deschide cutia aceea, nu cine
  cere (pașii sunt în `docs/08-publicare-vps-hostinger.md`, la „8d. Primele
  setări”). Ultimul fondator nu poate fi scos.
- **Butoanele de sub anunțul din Telegram.** „✅ Acceptă” și „❌ Respinge” sunt
  ale administratorilor și fondatorilor care și-au legat Telegramul de cont.
  „Acces” nu le atinge: un manager cu fapta „Scrie comenzi și acceptă cereri”
  acceptă cererile din panou, nu din Telegram.
- **Etapa finală fără bifă.** Administratorul și fondatorul pun „Predată”
  oricând; ceilalți, numai cu bifa „Poate confirma livrarea” din fișa contului
  (v. `docs/02-panoul-de-setari.md`, la „„Gata de livrare”: unde se oprește
  laboratorul”).
- **Anunțul altuia, de pe ecranul laboratorului.** Cine are fapta „Conduce
  tura” ia jos anunțul pus de el. Pe al altuia îl ia jos numai un administrator
  ori un fondator.

**Tableta din laborator are un plafon.** Contul comun al tabletelor ține de
rolul „Angajat”, deci primește bifele angajatului — dar numai până la
Laborator, Rută, Depozit și Pontaj. Dacă îi dai angajatului „Jurnal”, îl au
angajații cu cont propriu; tableta, pe care o deschide un cod știut de toată
tura, nu. Pagina o spune sub grilă: „Tableta rămâne însă numai cu Laborator,
Rută, Depozit și Pontaj, orice ai bifa aici.”

**Clienții nu au bife.** Văd magazinul, comenzile lor și — dacă au cod —
„Recomandări”. Nimic din „Acces” nu le dă și nu le ia ceva.

**Marginile agentului rămân.** Bifa „Comenzi” îi dă agentului de vânzări
panoul. Ce vede în el — numai comenzile oamenilor aduși de el — nu e o bifă și
nu se lărgește din „Acces”.

### De unde vine împărțirea din pornire

**Fondatorul e administrator plus trei lucruri.** Unul vechi: puterea asupra
administratorilor și a celorlalți fondatori. Două de la 2.7.0: pagina „Acces”,
care e numai a lui, și faptul că are mereu tot, orice s-ar bifa. Din pornire
mai are singur „Statistici”, „Încasări” și faptele despre bani.

**De ce statisticile nu pornesc și la administrator.** Nu e o neîncredere în
om, e o deosebire între munci: administratorul conduce patiseria zi de zi —
comenzi, marfă, oameni, prețuri — iar cifrele de pe interval (cât s-a vândut,
cine cumpără cel mai mult, ce se vinde și ce nu) sunt ale celui care ține
firma. Până la 2.7.0 deosebirea era scrisă în program și nu se putea muta; acum
e o bifă nepusă, pe care fondatorul o poate pune. Lista de pornire stă într-un
singur loc, `GP_DREPT_IMPLICIT` (`api/lib/auth.php`).

**Managerul scrie comenzi de-a dreptul; agentul scrie cereri.** De la hotărârea
din 18 septembrie 2026, comanda intră pe loc în laborator numai scrisă de
administrator, manager sau fondator — de la 2.7.0, de cine are fapta „Scrie
comenzi și acceptă cereri”. Managerul poate deci lua o comandă la telefon și o
poate scrie el, cu ce cod de recomandare vrea. Agentul de vânzări și contul de
tură nu pot: agentul scrie o **cerere**, care așteaptă o apăsare, iar căsuța
lui din „Acces” e dintre cele care nu se pot schimba.

**O comandă DEJA SALVATĂ — client, adresă, poziții, cod de recomandare — o
corectează cine o poate naște**: e aceeași faptă. Hotărârea patronului, 19
septembrie 2026. Până atunci corectarea era numai a administratorului, iar
managerul care luase comanda la telefon nu-și putea îndrepta nici propria
greșeală de tastare de acum patru minute: trebuia să sune un administrator.
Cine are panoul „Comenzi” fără faptă schimbă numai etapa (agentul mai poate
anula ori reface o comandă a oamenilor lui).

Două lucruri **nu** s-au lărgit odată cu ea, și de aceea sunt fapte aparte, cu
bifa lor:

- **„Pune ordinea opririlor”** pornește numai la administrator: traseul se
  hotărăște în birou, iar managerului nu i se desenează butoanele de ordonat
  până nu primește bifa;
- **„Plătește comisioane”**, adică butonul „Bifează ca plătit” de la comisionul
  unei recomandări, pornește tot numai la administrator. A corecta o comandă și
  a plăti un comision sunt două drepturi, și numai primul s-a lărgit.

**Agentul vede în „Comenzi” numai comenzile oamenilor aduși de el** — ale
cumpărătorilor legați de codul lui pentru totdeauna și cele care poartă codul
chiar pe ele. Restul comenzilor casei nu-i apar nici în listă, nici în căutare,
nici în ruta zilei, nici între cererile de pe site, și nu se pot deschide nici
cerându-le după număr. Marginea stă pe server, într-un singur loc
(`referral_agent_scope()`, `api/lib/referral.php`), nu în pagină.

**Agentul de vânzări face conturi de cumpărători**, din pagina „Recomandări”,
lângă „Copiază codul”. Contul nou e legat pe loc de codul lui, iar parola pe
care i-o dă cumpărătorului e provizorie: acesta alege la prima intrare, ca mai
sus. Agentul nu poate cere alt rol decât „client”, și nu poate alege
recomandantul — acela e el, întotdeauna.

**Butonul e acolo din prima zi, și pentru agentul care n-a cerut niciodată un
cod.** La început nu era: butonul se arăta doar dacă agentul avea deja cod de
recomandare, iar codul se făcea apăsând altundeva pe aceeași pagină, un pas pe
care nimic nu i-l spunea. Un agent proaspăt deschidea „Recomandări”, nu vedea
niciun buton de cont, și nu avea de unde ști că-i lipsește un pas, nu un drept.
Acum codul se naște singur, pe server, în clipa în care agentul face primul
cont — din numele lui, cu aceeași socoteală prin care se nasc toate codurile
casei, ca să nu iasă două coduri deosebite pentru același om.

Refuzul a rămas însă acolo unde chiar e o alegere greșită: administratorul care
cere anume „leagă contul de mine” fără să aibă el cod primește mai departe
eroarea, fiindcă acolo nu lipsește o pornire, ci s-a cerut un lucru care nu se
poate.

Mai e un caz, rar, în care și agentul primește refuz: **numele lui nu mai dă
niciun cod liber.** Codurile se fac din nume, iar într-o casă cu trei Maria
Popescu toate variantele care se pot scoate din numele acela pot fi deja luate.
Mesajul îl spune pe față și îi arată calea: un cod ales de mână, din „Conturi”.
Nu e același lucru cu lipsa de pornire de mai sus, și de-asta i se scrie
altceva.

**Cererile și ordinea opririlor au fost o vreme deschise întregii ture.**
Ecranul „Administrare Comenzi” se închidea corect pentru angajați, dar serverul
primea cererea oricum — iar contul tabletelor, pe care îl deschide un cod știut
de toată lumea, are exact acest rol. Cine ajungea la el putea respinge din
consolă cererea unui client, iar de acolo cererea nu se mai putea accepta
niciodată: se pierdea, și jurnalul nu putea spune cine a apăsat, fiindcă îl
trecea pe autor ca „Panou comun”. La fel cu ordinea opririlor: se putea
răsturna de pe orice tabletă, iar șoferul pleca pe alt traseu decât cel stabilit
în birou. De atunci amândouă se verifică pe server. Managerul a intrat la
cereri pe 18 septembrie 2026, fiindcă acceptarea unei cereri **este** nașterea
unei comenzi, doar pe celălalt drum — sunt singurele două din casă. Lăsată
numai pe administrator, poarta i-ar fi spus managerului „scrie tu una nouă în
loc s-o accepți pe asta”, adică exact comanda pe care are voie s-o scrie, numai
că fără clientul și pozițiile scrise deja.

### Formularul de comandă, pe roluri

Formularul din „Comenzi” e unul singur, dar fiecare cont vede în el numai ce
poate folosi. Din 2.6.0 au ieșit casetele care cereau ochi și nu dădeau nimic.
De la 2.7.0, ce se desenează ține de bifele din „Acces”, nu de numele rolului:
mai jos, „cine scrie comenzi” înseamnă cine are fapta „Scrie comenzi și acceptă
cereri” — din pornire administratorul, managerul și fondatorul.

**Titlul și butoanele spun ce se întâmplă la apăsare:**

| Cine, și ce face | Butonul de deasupra listei | Titlul formularului | Butonul de jos |
|---|---|---|---|
| cine scrie comenzi — comandă nouă | „+ Comandă nouă” | „Comandă nouă” | „Adaugă comanda” |
| cine are „Comenzi” fără faptă, din pornire agentul de vânzări — cerere nouă | „+ Cerere nouă” | „Cerere nouă” | „Trimite spre aprobare” |
| acceptarea unei cereri | — | „Comandă nouă” | „Acceptă cererea” |
| corectarea unei comenzi salvate | — | „Editează comanda …”, cu numărul ei | „Salvează modificările” |

La acceptarea unei cereri titlul rămâne „Comandă nouă”, deși butonul de jos
spune „Acceptă cererea”. E lăsat așa dinadins, până hotărăște patronul ce să
scrie acolo.

**Butoanele de jos și-au schimbat vorbele în 2.7.0** (hotărârea patronului din
4 octombrie 2026). Până atunci un buton scria „Șterge comanda” și de fapt
anula, iar cel de lângă salvare scria „Anulează” și de fapt închidea
formularul: două vorbe mutate cu un loc. De când există și ștergerea adevărată,
fiecare buton spune ce face. **„Anulează comanda”** trece comanda în etapa de
anulare, după o întrebare; pe comanda anulată, în locul lui stă **„Reface
comanda”**, drumul ei înapoi. **„Închide”** închide formularul. **„Șterge
definitiv”** se arată numai pe o comandă deja anulată și numai cui are fapta
„Șterge comenzile anulate”; o scoate din registru, fără drum înapoi (v.
`docs/02-panoul-de-setari.md`, la „Ștergerea definitivă a unei comenzi
anulate”).

**„Stare” și „Referință” apar numai la corectare** (2 octombrie 2026). Pe o
comandă nouă erau amândouă casete moarte. „Stare” se putea alege, dar nu pleca
nicăieri: comanda nouă intră mereu în prima etapă, pusă de server — omul alegea
„În cuptor”, salva, și găsea comanda „În așteptare”. „Referință” era
doar-citire, cu „se completează automat” în ea: numărul îl dă serverul, pe zi,
în ordine. La corectare rămân amândouă, fiindcă acolo chiar scriu.

**Plata** se citește în formularul unei comenzi salvate pe un singur rând —
„Plata: Neplătită”, „Plata: Amânată până pe …”, „Plata: Plătită pe …” ori
„Plata: Plătită prin bancă pe …” — și numai de cine are panoul „Încasări”.
Rândul doar arată: starea se schimbă din „Încasări”, nu de aici.

**Codul de recomandare**: caseta o are cine scrie comenzi; agentul citește
„Cererea pleacă cu codul tău: …”; la acceptarea unei cereri nu e casetă. Pe
larg, mai sus, la „Codul de recomandare, la comanda nouă”.

**„Adus de …”**, eticheta care spune cine a adus clientul, e legătură numai
pentru cine are panoul „Recomandări (toate codurile)” — din pornire
administratorul și fondatorul: îi duce în „Recomandări”, la dosarul celui care
a adus comanda. Celorlalți li se arată același text, dar fără legătură:
managerul n-are, din pornire, pagina cu toate codurile, iar agentul ar fi ajuns
la propria lui pagină, fără nimic din ce promitea eticheta. Nici lămurirea de
la trecerea cu mausul nu-i mai trimite acolo: pe pastila „Recomandare de
lămurit” lor le scrie „…până alege cineva care vede toate codurile.”, nu
„…până alegi. Deschide Recomandări.” Vorba e din 2.7.0 și e aleasă cu grijă:
între cele două coduri alege cine are panoul „Recomandări (toate codurile)”,
iar acela nu mai e neapărat un administrator.

**„Bifează ca plătit”**, sub comisionul unei comenzi, o vede numai cine are
fapta „Plătește comisioane” — din pornire administratorul și fondatorul.
Managerul, care deschide formularul de corectare din 19 septembrie 2026, vedea
butonul, îl apăsa și primea refuz: plata comisionului e alt drept decât
corectarea comenzii.

**Etapa finală** („Predată”) se oferă în lista „Stare” și în „Etapa” din
vederea rapidă numai celui care o poate pune: administratorul, fondatorul și
contul cu bifa „Poate confirma livrarea”. Celorlalți, sub listă le scrie
„„Predată” o pune un administrator ori un cont cu bifa „Poate confirma
livrarea”, din „Conturi”.”, cu numele pe care îl poartă etapa în Setări. O
comandă care e deja în etapa finală o arată mai departe, la oricine. Aici e
vorba de rang și de bifa din fișa contului, nu de „Acces”.

### Ecranele de refuz, și pe unde ieși din ele

Cine deschide cu adresa scrisă de mână un panou pe care nu-l are dă de un ecran
de refuz. De la 2.7.0 cele mai multe nu mai numesc roluri, fiindcă panoul poate
fi al oricui l-a primit din „Acces”. Fiecare are ieșiri care duc undeva, nu în
alt refuz:

| Panoul | Ce scrie | Butoanele |
|---|---|---|
| Setări | „Contul „…” nu are acces la setări. Configurarea aplicației o schimbă numai cine a primit pagina asta.” | **„← Înapoi la panoul meu”**, apoi „← Alege panoul” (clientului: „← Înapoi la magazin”) |
| Catalog | „Contul „…” nu are acces la catalog. Produsele și prețurile le schimbă numai cine a primit pagina asta.” | aceleași |
| Jurnal | „Contul „…” nu are acces la jurnal. Ce a schimbat fiecare cont vede numai cine a primit pagina asta.” | aceleași |
| Încasări | „Contul „…” nu are acces la încasări. Banii casei îi vede numai cine a primit pagina asta.” | aceleași |
| Statistici | „Contul „…” nu are acces la statistici. Cifrele casei le vede fondatorul și cine a primit pagina de la el.” | aceleași |
| Acces | „Contul „…” nu are acces aici. Cine ce vede în aplicație hotărăște numai fondatorul.” | aceleași |
| Comenzi | clientului: „Contul „…” e cont de client: aici lucrează doar personalul. Comenzile tale le vezi în „Comanda mea”.”; personalului: „Contul „…” e cont de angajat: nu are acces la comenzi.”, cu rolul lui, urmat de „Folosește panourile de lucru de mai jos.” ori, când nu i-a rămas niciun panou, de „Cere-i cuiva din conducere panoul de care ai nevoie.” | clientului „Vezi comanda mea”, personalului panourile din meniul lui (primul, plin); apoi „Ieși din cont” și „← Alege panoul” |
| Conturi | clientului, aceeași frază ca la Comenzi; personalului: „Contul „…” e cont de manager: nu poate vedea sau schimba parolele.”, cu rolul fiecăruia în loc de „manager” | clientului „Vezi comanda mea”, personalului panourile din meniul lui; apoi „Ieși din cont” și ieșirea obișnuită a rolului |
| Depozit | „Contul „…” nu are acces la depozit. Dacă ai nevoie de stoc, cere-i unui administrator să-ți pună rolul potrivit.” | panourile din meniul lui, „Ieși din cont” și ieșirea obișnuită a rolului |

Celelalte pagini ale casei își spun refuzul fiecare cu fraza ei. Laboratorul și
Ruta: „Contul „…” nu are acces la …. Rolul lui lucrează în altă parte — te duc
acolo.”, cu numele panoului. Pontajul: „Contul „…” nu pontează în laborator.
Ceasul e al oamenilor de acolo; raportul, al celor care conduc tura.”
Istoricul: „Contul „…” e cont de angajat: istoricul e al celor care scriu
comenzi.”, cu rolul lui.

**„← Înapoi la panoul meu”** duce fiecare cont în pagina lui de acasă:
administratorul, fondatorul și managerul în „Comenzi”, agentul de vânzări în
„Recomandări”, angajatul și tableta în „Laborator”, omul de laborator în
„Pontaj”, clientul în „Comanda mea”. De la 2.7.0 se uită întâi dacă pagina
aceea mai e în meniul lui: dacă i-a fost luată din „Acces”, îl duce în primul
panou pe care îl mai are, iar dacă nu i-a rămas niciunul, în „Parola mea”. În
Setări, Catalog și Jurnal butonul e din 2.6.0. Până atunci refuzul avea
legături scrise pe litere, aceleași pentru oricine — „Comenzi” în Setări și în
Catalog; „Conturi”, „Comenzi” și „Depozit” în Jurnal —, iar pentru cele mai
multe roluri fiecare ducea în alt refuz.

„Ieșirea obișnuită a rolului” e „← Alege panoul” pentru personal și „← Înapoi la
magazin” pentru client. Pe refuzul din „Comenzi”, ultimul rând scrie „← Alege
panoul” și clientului.
