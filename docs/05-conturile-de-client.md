# Conturile de client

Un client cu cont comandă altfel decât un vizitator: nu-și mai scrie numele,
telefonul și adresa la fiecare comandă, iar în `clienti.goldenpastry.ro/client`
își vede tot ce a comandat până acum.

Contul îl deschizi tu, din `lab.goldenpastry.ro/conturi`.

---

## Ce vede clientul

**După autentificare ajunge în „Comanda mea"**, nu în magazin. Acolo îl
așteaptă comanda care urmează, ziua livrării și, în ziua aceea, câte opriri mai
sunt până la el. Magazinul rămâne la un clic: butonul **„Intră în magazin"** de
pe pagina de intrare, și rândul din meniul contului.

În magazin, când ajunge acolo, în locul butonului „Intră în cont" antetul îi
arată numele firmei și un meniu cu două intrări: `Comanda mea` și
`Ieși din cont`.

> O vreme l-am trimis de-a dreptul în magazin, cu socoteala „clientul vine să
> comande, nu să-și citească istoricul". Socoteala era a patiseriei, nu a
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

---

## Istoricul comenzilor

`clienti.goldenpastry.ro/client`, sub comanda care urmează.

Fiecare rând arată ziua, câte bucăți și cât a fost. Apăsat, se desface și arată
produsele, numărul comenzii și adresa la care a plecat. Comenzile anulate apar
și ele, marcate, oricând ar fi fost.

Se afișează șase, cu un buton pentru restul.

---

## Comanda luată la telefon

Comanda pe care o scrii tu în `lab.goldenpastry.ro/comenzi` ajunge în istoricul
clientului dacă numele scris de tine e **exact** numele de pe cont — cel din
câmpul „Client" al contului, sau numele contului dacă acela e gol.

Dacă două conturi poartă același nume, aplicația nu ghicește: comanda rămâne
nelegată și clientul n-o vede în panoul lui. Redenumește unul dintre conturi.

> Până la această modificare, numele pe care îl scriai era înlocuit în tăcere cu
> numele contului tău. O comandă adăugată de administrator pentru „Hotel
> Belvedere" apărea în laborator și pe ruta șoferului drept „Administrator" —
> fără client, fără niciun semn că s-a pierdut ceva.

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
dosarul, nu alege clientul — altfel „uită-te înainte să alegi" s-ar fi prefăcut
în „te-ai uitat, gata, ai ales".

Dacă acel cont s-a făcut singur de pe site și nu l-a recunoscut încă nimeni din
casă, dosarul o spune pe față: **comanda se scrie, dar nu se leagă de el** până
nu-l întărește cineva din „Conturi". Nu e o poartă — numele se poate alege
oricum —, e o urmare pe care trebuie s-o afli *înainte*, nu peste o săptămână,
când clientul întreabă de ce nu-și vede comanda în panoul lui.

Cine vede lista vede numai clienții lui: agentul de vânzări îi vede pe cei aduși
de el, administratorul, managerul și fondatorul văd toată ograda. Marginea stă pe
server, într-un singur loc (`client_scope_for()`, `api/lib/orders.php`); caseta
din pagină doar o oglindește, fiindcă o listă care promite ce serverul refuză e
mai rea decât lipsa ei.

### Codul de recomandare, la comanda nouă

Formularul se deschide cu **codul tău** scris deja, dar poți pune altul: comanda
poate fi adusă de un coleg, iar tu doar o scrii. Asta valabil pentru oricine
poate naște o comandă — administrator, manager, fondator.

Două lucruri rămân neschimbate, și e bine de știut de ce:

- **Nu te poți recomanda pe tine.** Dacă cel cu codul e chiar cumpărătorul,
  legătura nu se scrie, iar în jurnal rămâne `referral_self_blocked`.
- **Pe o comandă deja salvată, codul îl schimbă tot numai administratorul.**
  Vezi „Ce poate fiecare rol", la coada ghidului.

Un cod pus la **nașterea** comenzii leagă contul cumpărătorului de recomandant
pentru totdeauna, dacă acela n-avea încă unul. La **corectarea** unei comenzi
vechi, legătura nu se mai scrie singură: acolo trebuie bifat anume, tocmai
fiindcă legatul e o faptă care se cere pe față.

---
## Contul făcut imediat după comandă

În fereastra de finalizare de pe `goldenpastry.ro`, vizitatorul are de acum
două butoane: **„Trimite cererea"** și **„Trimite cererea și fă-ți cont"**.

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
aceea stă de o rundă o pază anume: un cont făcut pe site nu moștenește comenzile
scrise pe numele lui până nu garantează cineva din casă pentru el.

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
dar n-o va găsi în „Comanda mea"** — legătura a expirat. Nu se inventează nimic
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
  nume — potrivirea pe nume e exact ce păzește casa de la o rundă încoace.
- **Socoteala se face pe server, o dată pentru tot lotul.** Nu se numără din
  comenzile aduse pe ecran: lista e tăiată la 500 de rânduri pe zi, iar o
  numărătoare făcută în pagină ar spune „prima comandă" despre un client cu
  treizeci, doar fiindcă restul n-au încăput pe ecran.

Pe un server rămas în urmă, care nu trimite încă rangul, nu apare nicio
insignă — nimic nu se rupe.

---

## Dacă și-a uitat parola

Pe pagina de intrare, sub butonul de autentificare, e **„Ți-ai uitat parola?"**.
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

**Merge doar dacă e-mailul chiar pleacă de pe server.** Verifică-l întâi cu
butonul de test din Setări → Notificări. Și doar dacă pe cont e trecută o
adresă: fără ea, omul nu primește nimic. Pagina nu spune care dintre cele două
lipsește — răspunsul e identic și când contul există, și când nu, altfel
formularul ar fi o listă de utilizatori reali pentru oricine îl încearcă. De
aceea mesajul îi spune omului să sune dacă nu primește nimic în câteva minute.

---

## Parola pe care i-o dai tu, și parola lui

Când faci un cont nou, sau când apeși „Parolă nouă" pe un cont existent, parola
pe care i-o dictezi este **provizorie**. Contul e marcat ca atare: în Conturi
placa lui poartă insigna **PAROLĂ PROVIZORIE** (pe o placă îngustă, numai
**PROVIZORIE**), iar în fișa lui, sub nume, scrie „parolă provizorie, pusă de
administrator". Antetul paginii numără câte conturi sunt în starea asta, ca să
nu le cauți unul câte unul.

La prima intrare cu o parolă provizorie, omul e dus direct la pagina
**Parola mea** și nu ajunge nicăieri altundeva până nu alege. Pagina nu-i mai
pune formularul în față, ci **întrebarea**, cu două butoane:

- **„Vreau să-mi schimb parola"** deschide, peste pagină, o fereastră cu
  formularul de întotdeauna: parola de acum, apoi cea nouă de două ori, minim 8
  caractere, cu butonul care propune una.
- **„Păstrez parola actuală"** stinge semnul pe loc și îl duce în cont, fără să
  schimbe nimic. Parola rămâne cea dictată de tine.

Se ajunge la pagină oricând, nu doar la prima intrare: din meniul lateral al
panourilor, din antetul panoului clientului, și din meniul contului din
magazin. Întrebarea se arată **numai** cât timp contul poartă semnul; după, e
formularul obișnuit.

**Cine poate păstra: oricine are semnul, personalul inclusiv.** A fost o
alegere a patronului, luată știind urmarea, și e bine să fie scrisă aici:
parola pe care ai dictat-o tu la telefon rămâne o parolă care intră în acel
cont, iar pentru un cont de personal asta înseamnă comenzile, conturile și
datele celorlalți. Dacă ai dictat parola cu voce tare într-o încăpere plină,
schimb-o tu însuți din „Conturi" — butonul „Parolă nouă" pune semnul la loc.

**Păstrarea se scrie în Jurnal** (`password_kept`), lângă schimbările de parolă.
Deci se poate afla oricând cine a ales să rămână cu parola dictată, și când.

> Alegerea asta nu slăbește cu nimic poarta de pe server. Contul cu semnul pus
> tot nu ajunge nicăieri până nu trece prin pagina Parola mea: serverul refuză
> orice altă cerere cu 403, iar „Păstrez" e singura ușă nouă pe care o lasă
> deschisă. Pe un server încă neactualizat, butonul „Păstrez" ia 404, iar
> pagina cade înapoi pe schimbarea obligatorie de dinainte — nu se blochează.

Din clipa în care și-a pus parola lui, rândul din Conturi se schimbă în
„parolă proprie, schimbată <data>". Insigna dispare.

> Până la această modificare, pagina scria „parolă schimbată" urmat de data
> **ultimei autentificări**. Un cont care tocmai intrase cu parola dictată de
> tine la telefon arăta ca și cum și-ar fi pus de mult parolă proprie. Te
> uitai exact acolo ca să afli care parole dictate mai deschid conturi, și
> pagina îți răspundea pe dos. Data adevărată se ține acum într-o coloană
> separată, scrisă doar când omul își schimbă singur parola — din pagina
> Parola mea sau prin linkul de recuperare.

---

## Cât ține sesiunea

| Cine | Zile |
|---|---|
| client | 30 |
| administrator, angajat, manager, agent | 7 |
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
| Nume | numele persoanei |
| Client | numele firmei — el apare pe comandă, în laborator și pe rută |
| Telefon, e-mail | completate automat în comanda de pe site |
| Adresă | adresa de livrare, completată automat și actualizată de client |

Adresa nu se editează din `Conturi`: o scrie clientul, la prima comandă. Tu o
poți corecta la acceptarea cererii, în formularul de comandă.

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

**Căutarea caută în rolul ales.** Cu „Clienți" apăsat, „maria" găsește
clientele, nu și pe colega Maria de la laborator, iar sub casetă scrie câte au
ieșit și din câte („2 din 6 conturi · clienți"). Când în rolul ales nu iese
nimic, dar în altul da, pagina nu se oprește la „niciun cont": pune dedesubt
butonul **„Caută în toate rolurile · 1 potrivire"**, care te duce acolo dintr-o
apăsare. Altfel „niciun cont" ar fi părut un răspuns despre toată casa.

În rest, căutarea e cea de dinainte: nume, utilizator, e-mail, firmă, zonă sau
rol, fără diacritice — „gheorghita" îl găsește pe Gheorghiță. În casetă scrie
acum numai „Caută un cont", iar lista câmpurilor stă în eticheta ei, care se
arată ținând mausul deasupra. Fraza întreagă de dinainte se tăia la jumătate pe
telefon.

**Trei vederi**, din butoanele cu desen de lângă căutare:

| Vedere | Ce arată | Pe telefon | Pe tabletă | Pe calculator |
|---|---|:-:|:-:|:-:|
| **Listă** | fiecare cont cu fișa lui întreagă, ca înainte | 1 | 1 | 1 |
| **Patru pe rând** | plăci | 2 | 3 | 4 |
| **Șase pe rând** | plăci mai mici | 3 | 4 | 6 |

Numărul din nume e cât pune pagina **cel mult** pe un rând. Pune mai puține
numai acolo unde placa n-ar mai avea loc de nume: pe tabletă, meniul din stânga
îi ia din lățime. Coloanele din tabel sunt cele măsurate la 390, 820 și 1440 de
pixeli.

Vederea aleasă se ține minte **în browserul acela**, ca în Catalog. Unde nu s-a
ales încă nimic, pagina pornește din „Patru pe rând". Nu vine din Setări: e
unealta celui care lucrează, nu o hotărâre a casei.

**Placa** arată monograma (primele litere din nume, pe culoarea rolului),
numele, utilizatorul, rolul, semnele contului și ultima intrare, sau „n-a intrat
încă niciodată". În „Șase pe rând" ultima intrare nu mai încape, iar rolurile
lungi se scurtează: „Admin", „Agent", „Laborator".

Semnele sunt trei:

| Semn | Înseamnă |
|---|---|
| **contul tău** (în „Șase pe rând": **al tău**) | contul cu care ești intrat acum |
| **parolă provizorie** (pe o placă îngustă: **provizorie**) | parola e încă cea dictată de tine, v. mai jos |
| **cont oprit** (în „Șase pe rând": **oprit**) | contul nu mai poate intra în aplicație |

„Parolă provizorie" se scurtează după lățimea plăcii, nu după vedere. Aceeași
placă din „Patru pe rând" are loc de insigna întreagă pe telefon, dar nu și pe
tabletă, unde meniul îi ia din lățime. Cititorul de ecran aude mai departe
ambele cuvinte, iar insigna întreagă stă și în eticheta ei.

**Numele lungi se micșorează, nu se rup.** Pe placă, numele are cel mult două
rânduri. Lăsat în voia lui, un cuvânt mai lung decât placa s-ar rupe la
margine, în mijlocul lui („Restaurant" pe un rând, „ul" pe următorul), iar unul
prea lung pentru al doilea rând s-ar termina în „…", ca „Ștefan / Hohenzol…".
De aceea pagina măsoară cel mai lung cuvânt din fiecare nume și, unde n-ar
încăpea, micșorează numele cât îi trebuie. Coboară cel mult până la mărimea
rândurilor de sub el, 13 pixeli în „Patru pe rând" și 12 în „Șase pe rând":
mai mărunt decât utilizatorul lui, numele n-ar mai arăta a nume. Un cuvânt
care nu încape nici așa tot se rupe. La 390 de pixeli, în „Șase pe rând",
„Constantinescu" ar avea nevoie de 11,5.

Mărimea se ia din lățimea plăcii, deci se potrivește singură pe tabletă și
când tableta se rotește. Două nume de pe același rând pot ieși astfel de mărimi
puțin diferite: e prețul cuvintelor întregi.

**Placa se deschide pe loc.** O apăsare pe ea desface fișa întreagă a contului
— aceeași din „Listă", cu toate casetele și butoanele ei — chiar sub rândul
plăcii, pe toată lățimea. Se strânge din butonul **„Strânge"** din colțul ei,
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
cont oprit arăta ca unul bun. Acum poartă insigna roșie „cont oprit", pe placă
și în capul fișei.

### Comutatorul „Cont pornit"

Tot din 2.5.0, contul se oprește și se pornește chiar din pagină. În fișa
fiecărui cont, deasupra butoanelor de jos, stă comutatorul **„Cont pornit"**,
iar sub el un rând care spune ce înseamnă starea de acum.

**Oprirea cere două apăsări.** Prima nu trimite nimic: comutatorul se stinge,
rama rândului se face roșie, iar dedesubt apare întrebarea „Oprești
contul…?", care spune pe scurt ce urmează — omul nu mai poate intra, iese pe
loc dacă e conectat, iar comenzile și istoria lui rămân —, cu butoanele
**„Renunț"** și **„Oprește contul"**. Abia al doilea pleacă spre server.
Deschiderea altei fișe renunță singură, ca la o ștergere armată: o oprire
lăsată în așteptare te-ar fi pândit la întoarcere, pe un cont pe care nu-l mai
vedeai.

**Pornirea e dintr-o apăsare**, fiindcă nu strică nimic: contul pornit la loc
intră cu parola lui de dinainte.

**Oprit nu înseamnă șters.** Comenzile, cererile și istoria contului rămân pe
loc. Dacă omul e conectat când îl oprești, serverul îi închide sesiunile pe
loc, iar parola nu-l mai lasă să intre până nu-l pornești la loc. Cât pleacă
cererea, comutatorul stă stins și scrie „Opresc contul…" ori „Pornesc
contul…"; răspunsul apare sus, în mesajul paginii.

**Cine pe cine.** Regulile sunt ale serverului; pagina doar le arată dinainte,
cu comutatorul stins și motivul scris dedesubt:

- nimeni nu-și poate opri propriul cont;
- contul unui administrator ori al unui fondator îl oprește **și** îl pornește
  numai un fondator, cât timp există vreunul pornit;
- contul de sistem „panou", al tabletelor din laborator, n-are comutator:
  accesul lui cu cod se oprește din Setări.

Al doilea rând e mai nou decât pare. Până în 2.5.0 serverul cerea un fondator
numai la **oprire**, așa că un administrator putea porni la loc contul unui
administrator oprit de fondator, iar omul scos din firmă intra iar, cu toate
drepturile. Pagina n-avea buton, deci ușa cerea o cerere scrisă de mână; cu
comutatorul, ar fi fost o apăsare. S-a închis înaintea lui, iar povestea e în
docs/06, printre defectele de conturi.

---

## Ce urmează comenzii

Nimic nu intră în producție singur. Comanda clientului ajunge în
`lab.goldenpastry.ro/comenzi`, în fila **Cereri de pe site**, și așteaptă acolo
până o accepți. La acceptare îi dai ziua, intervalul, zona și modul de onorare —
abia atunci primește număr și apare în laborator.

---

## Ce poate fiecare rol

Fiecare rol vede în meniu **numai panourile pe care le poate chiar folosi**. Un
buton care ar duce la „nu ai acces" nu se mai arată deloc — lista de mai jos e
oglinda porților de pe server, nu o pază în plus.

| Panou | administrator / fondator | manager | agent de vânzări | angajat / tablete | client |
|---|:-:|:-:|:-:|:-:|:-:|
| Comenzi | tot | vede și mută etapa | **numai ale lui**, mută etapa | — | — |
| Laborator | da | da | — | da | — |
| Rută | da | da | — | da | — |
| Depozit | da | da | — | da | — |
| Pontaj | da | da | — | da | — |
| Catalog | da | — | — | — | — |
| Conturi | da | — | — | — | — |
| Recomandări | da | — | da | — | dacă are cod |
| Jurnal | da | — | — | — | — |
| Statistici | **numai fondatorul** | — | — | — | — |
| Setări | da | — | — | — | — |
| Comanda mea | — | — | — | — | da |
| Parola mea | da | da | da | da | da |

Mai e un rol, care nu încape în tabel fiindcă are o singură ușă:
**„utilizator de laborator"** — omul care vine la lucru și atât. El vede
**Pontaj** și **Parola mea**, nimic altceva. În fața tabletei din laborator nici
nu intră cu parolă: apasă pe numele lui pe ecranul deschis de tură. Rândul din
meniu e pentru ziua în care își deschide singur pagina, de pe telefon.

**Fondatorul e administrator plus două lucruri.** Unul vechi: puterea asupra
administratorilor și a celorlalți fondatori. Unul nou: **„Statistici"** —
singurul panou din casă pe care administratorul nu-l are. În rest trec prin
aceleași uși, iar meniul le arată același lucru.

**De ce statisticile nu sunt și ale administratorului.** Nu e o neîncredere în
om, e o deosebire între munci: administratorul conduce patiseria zi de zi —
comenzi, marfă, oameni, prețuri — iar cifrele de pe interval (cât a intrat în
casă, cine cumpără cel mai mult, ce se vinde și ce nu) sunt ale celui care ține
firma. Oglinda de pe server e `GP_STATS_ROLES` (`api/lib/auth.php`), singura
listă de roluri din casă mai strâmtă decât `GP_ADMIN_ROLES`.

**Managerul scrie comenzi de-a dreptul; agentul scrie cereri.** De la hotărârea
din 18 septembrie 2026, comanda intră pe loc în laborator numai scrisă de
administrator, manager sau fondator. Managerul poate deci lua o comandă la
telefon și o poate scrie el, cu ce cod de recomandare vrea. Agentul de vânzări
și contul de tură nu mai pot: ei scriu o **cerere**, care așteaptă o apăsare.

**Formularul de editare al unei comenzi DEJA SALVATE — client, adresă, poziții,
cod de recomandare — rămâne al administratorului.** Din contul de personal se
schimbă numai etapa. Asimetria e voită: la naștere scrie oricine poate naște, la
corectare scrie cine răspunde de bani. O comandă salvată are deja socoteli făcute
pe ea, poate chiar o plată; una care abia se naște n-are ce răsturna.

**Agentul vede în „Comenzi" numai comenzile oamenilor aduși de el** — ale
cumpărătorilor legați de codul lui pentru totdeauna și cele care poartă codul
chiar pe ele. Restul comenzilor casei nu-i apar nici în listă, nici în căutare,
nici în ruta zilei, nici între cererile de pe site, și nu se pot deschide nici
cerându-le după număr. Marginea stă pe server, într-un singur loc
(`referral_agent_scope()`, `api/lib/referral.php`), nu în pagină.

**Agentul de vânzări face conturi de cumpărători**, din pagina „Recomandări",
lângă „Copiază codul". Contul nou e legat pe loc de codul lui, iar parola pe
care i-o dă cumpărătorului e provizorie: acesta alege la prima intrare, ca mai
sus. Agentul nu poate cere alt rol decât „client", și nu poate alege
recomandantul — acela e el, întotdeauna.

**Butonul e acolo din prima zi, și pentru agentul care n-a cerut niciodată un
cod.** Până de curând nu era: butonul se arăta doar dacă agentul avea deja cod
de recomandare, iar codul se făcea apăsând altundeva pe aceeași pagină, un pas
pe care nimic nu i-l spunea. Un agent proaspăt deschidea „Recomandări", nu
vedea niciun buton de cont, și nu avea de unde ști că-i lipsește un pas, nu un
drept. Acum codul se naște singur, pe server, în clipa în care agentul face
primul cont — din numele lui, cu aceeași socoteală prin care se nasc toate
codurile casei, ca să nu iasă două coduri deosebite pentru același om.

Refuzul a rămas însă acolo unde chiar e o alegere greșită: administratorul care
cere anume „leagă contul de mine" fără să aibă el cod primește mai departe
eroarea, fiindcă acolo nu lipsește o pornire, ci s-a cerut un lucru care nu se
poate.

Mai e un caz, rar, în care și agentul primește refuz: **numele lui nu mai dă
niciun cod liber.** Codurile se fac din nume, iar într-o casă cu trei Maria
Popescu toate variantele care se pot scoate din numele acela pot fi deja luate.
Mesajul îl spune pe față și îi arată calea: un cod ales de mână, din „Conturi".
Nu e același lucru cu lipsa de pornire de mai sus, și de-asta i se scrie
altceva.

Iar în „Comenzi", între cele două trepte de personal:

| | administrator / fondator | manager | restul personalului |
|---|---|---|---|
| **Scrie o comandă nouă, de-a dreptul** | da | **da** | **nu** — scrie o cerere |
| **Acceptă, respinge sau redeschide o cerere** | da | **da** | **nu** |
| **Stabilește ordinea opririlor pe rută** | da | **nu** | **nu** |
| Editează comanda salvată: client, adresă, poziții, cod de recomandare | da | **nu** | **nu** |

Managerul a intrat pe primele două rânduri la 18 septembrie 2026. Acceptarea
unei cereri **este** nașterea unei comenzi, doar pe celălalt drum — sunt
singurele două din casă. Lăsată numai pe administrator, poarta i-ar fi spus
managerului „scrie tu una nouă în loc s-o accepți pe asta", adică exact comanda
pe care are voie s-o scrie, numai că fără clientul și pozițiile scrise deja.

Primele două rânduri erau, până de curând, deschise întregii ture.
Ecranul „Administrare Comenzi" se închidea corect pentru angajați, dar serverul
primea cererea oricum — iar contul tabletelor, pe care îl deschide un cod știut
de toată lumea, are exact acest rol. Cine ajungea la el putea respinge din
consolă cererea unui client, iar de acolo cererea nu se mai putea accepta
niciodată: se pierdea, și jurnalul nu putea spune cine a apăsat, fiindcă îl
trecea pe autor ca „Panou comun". La fel cu ordinea opririlor: se putea
răsturna de pe orice tabletă, iar șoferul pleca pe alt traseu decât cel stabilit
în birou. Acum ambele cer un cont de administrator, verificat pe server.
