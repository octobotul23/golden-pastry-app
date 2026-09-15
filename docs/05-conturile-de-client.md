# Conturile de client

Un client cu cont comandă altfel decât un vizitator: nu-și mai scrie numele,
telefonul și adresa la fiecare comandă, iar în `clienti.goldenpastry.ro/client`
își vede tot ce a comandat până acum.

Contul îl deschizi tu, din `lab.goldenpastry.ro/conturi`.

---

## Ce vede clientul

**După autentificare ajunge în magazin**, nu într-un panou. Acolo, în locul
butonului „Intră în cont", antetul îi arată numele firmei și un meniu cu două
intrări: `Comanda mea` și `Ieși din cont`.

Înainte era trimis în panoul „Comanda mea", care arată o singură comandă și
niciun produs. Ca să comande trebuia să găsească singur drumul înapoi la
magazin — și acolo să-și scrie din nou datele, deși tocmai se autentificase.

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
