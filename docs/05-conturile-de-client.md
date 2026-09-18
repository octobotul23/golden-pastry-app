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
pe care i-o dictezi este **provizorie**. Contul e marcat ca atare: în lista de
conturi rândul lui arată insigna **PAROLĂ PROVIZORIE**, iar dedesubt scrie
„parolă provizorie, pusă de administrator". Antetul paginii numără câte conturi
sunt în starea asta, ca să nu le cauți rând cu rând.

La prima intrare cu o parolă provizorie, omul e dus direct la pagina
**Parola mea** și nu ajunge nicăieri altundeva până nu și-o schimbă. Pagina
cere parola de acum, apoi pe cea nouă de două ori, minim 8 caractere — și are
un buton care propune una. Se ajunge la ea oricând, nu doar la prima intrare:
din meniul lateral al panourilor, din antetul panoului clientului, și din
meniul contului din magazin.

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

**Managerul și agentul, în „Comenzi", doar mută etapa.** Văd lista, deschid o
comandă, o trec dintr-o etapă în alta și pot lua o comandă nouă la telefon.
Formularul de editare — client, adresă, poziții, cod de recomandare — rămâne al
administratorului.

**Agentul vede în „Comenzi" numai comenzile oamenilor aduși de el** — ale
cumpărătorilor legați de codul lui pentru totdeauna și cele care poartă codul
chiar pe ele. Restul comenzilor casei nu-i apar nici în listă, nici în căutare,
nici în ruta zilei, nici între cererile de pe site, și nu se pot deschide nici
cerându-le după număr. Marginea stă pe server, într-un singur loc
(`referral_agent_scope()`, `api/lib/referral.php`), nu în pagină.

**Agentul de vânzări face conturi de cumpărători**, din pagina „Recomandări",
lângă „Copiază codul". Contul nou e legat pe loc de codul lui, iar parola pe
care i-o dă cumpărătorului e provizorie: acesta o schimbă la prima intrare.
Agentul nu poate cere alt rol decât „client", și nu poate alege recomandantul —
acela e el, întotdeauna.

Iar în „Comenzi", între cele două trepte de personal:

| | administrator / fondator | restul personalului |
|---|---|---|
| **Acceptă, respinge sau redeschide o cerere de pe site** | da | **nu** |
| **Stabilește ordinea opririlor pe rută** | da | **nu** |
| Editează comanda: client, adresă, poziții, cod de recomandare | da | **nu** |

Primele două rânduri erau, până de curând, deschise întregii ture.
Ecranul „Administrare Comenzi" se închidea corect pentru angajați, dar serverul
primea cererea oricum — iar contul tabletelor, pe care îl deschide un cod știut
de toată lumea, are exact acest rol. Cine ajungea la el putea respinge din
consolă cererea unui client, iar de acolo cererea nu se mai putea accepta
niciodată: se pierdea, și jurnalul nu putea spune cine a apăsat, fiindcă îl
trecea pe autor ca „Panou comun". La fel cu ordinea opririlor: se putea
răsturna de pe orice tabletă, iar șoferul pleca pe alt traseu decât cel stabilit
în birou. Acum ambele cer un cont de administrator, verificat pe server.
