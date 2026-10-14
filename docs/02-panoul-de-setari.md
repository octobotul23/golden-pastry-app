# Panoul de Setări

`lab.goldenpastry.ro/setari` — **din pornire** o au administratorul și
fondatorul. De la 2.7.0 pagina e un rând în „Acces”, de unde fondatorul o poate
da și altui rol ori i-o poate lua administratorului (v.
`docs/05-conturile-de-client.md`, la „Pagina „Acces””). Verificarea se face pe
server, la fiecare cerere, deci nu se poate ocoli din browser.

Cine o deschide fără s-o aibă dă de un ecran de refuz: „Contul „…” nu are acces
la setări. Configurarea aplicației o schimbă numai cine a primit pagina asta.”
Sub el stau două butoane, unul sub altul. Primul, **„← Înapoi la panoul meu”**,
îl duce în pagina de acasă a rolului lui: managerul în Comenzi, agentul de
vânzări în Recomandări, angajatul și tableta din laborator în Laborator, omul
de laborator în Pontaj, clientul în „Comanda mea”. Dacă pagina aceea i-a fost
luată din „Acces”, îl duce în primul panou pe care îl mai are. Al doilea e
**„← Alege panoul”** — la client, **„← Înapoi la magazin”**. Până la 2.6.0
primul buton era „Comenzi”, același pentru toți, iar angajatul, omul de
laborator, clientul și tableta dădeau acolo de al doilea refuz la rând:
niciunul n-avea panoul Comenzi.

De aici configurezi aplicația fără să atingi vreun fișier. Tot ce salvezi aici
se aplică imediat, pe toate dispozitivele, și intră în jurnal (cine, ce, când).

---

## Notificare la comandă nouă

Trei canale independente. Poți ține pornite oricâte.

| Canal | Ce cere | Cât costă |
|---|---|---|
| **E-mail** | o căsuță de e-mail a firmei, de la furnizorul domeniului, trecută în cartonașul „E-mail”; fără ea, mesajele pleacă direct de pe server și ajung des în Spam | gratuit, dacă ai deja căsuța |
| **WhatsApp** | cont Meta Business, număr dedicat, șablon aprobat | per mesaj |
| **Telegram** | un bot creat în 5 minute | gratuit |

Recomandarea mea: pornește pe **Telegram**, care merge în cinci minute;
completează căsuța de **e-mail** de îndată ce ai datele ei de la furnizor — de
ea țin și linkurile de la „Ți-ai uitat parola?”; și treci pe **WhatsApp** după
ce Meta îți aprobă numărul — aprobarea durează, iar până atunci n-ai vrea să
rămâi fără anunțuri.

Până la 2.7.5 rândul e-mailului spunea aici că nu-ți trebuie nimic și că merge
de la bun început. Era adevărat pe găzduirea veche, care își ținea singură
programul de poștă; pe serverul de azi n-a mai fost.

### E-mailul: căsuța firmei și drumul direct

Până la 2.7.5 cartonașul **„E-mail”** din fila Notificări afirma că e-mailul
merge fără niciun cont din afară. De la mutarea de pe găzduirea veche nu mai
pleca însă niciun e-mail — nici anunțurile, nici linkurile de la „Ți-ai uitat
parola?” —, iar ecranul spunea mai departe că merge. De la 2.7.5 e-mailul are
două drumuri, iar cartonașul scrie, chiar sub titlu, pe care pleacă acum.

**Drumul de nădejde: căsuța firmei.** O căsuță de e-mail obișnuită, pe domeniul
tău, de la furnizorul domeniului — una ca „comenzi@goldenpastry.ro”. Aplicația
intră în ea cum ar intra un program de e-mail și trimite prin ea. Se
completează în partea **„Căsuța prin care se trimite (SMTP)”** a cartonașului:

| Caseta | Ce scrii |
|---|---|
| **„Server SMTP”** | numele serverului, cum îl dă furnizorul: „fără „https://” și fără port. Lăsat gol, căsuța nu e folosită.” |
| **„Port”** | „De obicei 465 ori 587. Lăsat gol înseamnă 465.” |
| **„Utilizator”** | „De obicei chiar adresa căsuței” |
| **„Criptare”** | una din trei: „Automat (după port)”, „SSL/TLS — de obicei portul 465”, „STARTTLS — de obicei portul 587”. „Lasă „Automat” dacă furnizorul nu spune altceva.” |
| **„Parola căsuței”** | parola căsuței, scrisă de tine |

Numele serverului, portul și felul criptării **le citești din contul tău de la
furnizorul domeniului**, la setările căsuței. Ghidul nu le scrie: le hotărăște
furnizorul și le poate schimba. La **„Adresa expeditor”**, mai sus în
cartonaș, scrie chiar adresa căsuței; multe căsuțe refuză să trimită în numele
altei adrese, iar pagina te atenționează când cele două nu se potrivesc. Totul
se salvează cu „Salvează” din bara de sus.

**Parola o scrii tu, aici, și n-o spui nimănui** — nici celui care îți
îngrijește aplicația. Se păstrează criptată, iar serverul nu o mai trimite
înapoi niciodată, nici măcar ultimele ei semne: după salvare caseta rămâne
goală, cu „Parolă salvată — lasă gol ca s-o păstrezi”. Scrii alta numai ca s-o
înlocuiești. „Șterge parola” o scoate la următorul „Salvează”; dacă te-ai
răzgândit înainte, apeși „Renunță”. Cât o scrii, parola se vede pe ecran —
caseta e una de text, ca browserul să nu pună în ea parola contului tău —, deci
scrie-o când nu-ți stă nimeni peste umăr.

**Schimbi serverul? Scrii și parola din nou.** Dacă schimbi „Server SMTP” cât
e o parolă salvată, „Salvează” cere în aceeași salvare și parola căsuței (ori
„Șterge parola”); caseta ți-o spune dinainte: „Ai schimbat serverul: scrie
parola din nou”. Parola salvată e a serverului de dinainte și nu pleacă spre
altul. Golirea lui „Server SMTP”, care întoarce e-mailul pe drumul direct, nu
cere nimic.

**Drumul direct: de pe server.** Cât „Server SMTP” e gol, mesajele pleacă
direct de pe server, printr-un program de poștă pe care îl pun, de la 2.7.5,
`instaleaza-vps.sh` și `actualizeaza-vps.sh`. Pleacă, dar fără nicio
făgăduială: un asemenea mesaj nu poartă semnătura domeniului, iar căsuțele mari
îl trec des în Spam ori nu-l primesc deloc. E o plasă, nu drumul de bază.

**Cele două drumuri nu se amestecă.** Cât „Server SMTP” e completat, e-mailul
pleacă numai prin căsuță. Dacă îi lipsește utilizatorul ori parola, nu pleacă
**nimic**, nici direct: serverul nu ocolește pe tăcute o căsuță completată pe
jumătate. Ca să te întorci la drumul direct, golești „Server SMTP” și salvezi.

**Rândul de stare**, de sub titlul „E-mail”, spune ce face serverul acum, după
ce e salvat, nu după ce e scris pe ecran și încă neplecat:

| Ce scrie | Cum arată | Ce înseamnă |
|---|---|---|
| „Trimite prin căsuța …”, cu adresa, serverul și portul ei | verde | drumul de nădejde |
| „Trimite direct de pe server. Fără o căsuță în spate, mesajele pot ajunge în Spam ori deloc. Completează căsuța de mai jos ca să fie sigur.” | pe fond galben | pleacă, fără făgăduială |
| „Serverul nu are niciun program de poștă: acum NU pleacă niciun e-mail. Completează căsuța de mai jos.” | roșu | nu pleacă nimic |
| „Căsuța e completată pe jumătate: acum NU pleacă niciun e-mail. Completează mai jos ce lipsește.” | roșu | nu pleacă nimic |
| „Nu se știe încă pe ce cale pleacă e-mailul: serverul n-a spus.” | șters | setările n-au sosit; reîncarcă pagina |

**Comutatorul „Pornit / Oprit”** al cartonașului oprește numai anunțurile de
comenzi. O spune rândul de sub el: „Linkurile de la „Ți-ai uitat parola?”
pleacă oricum, pe aceeași cale.”

**Butonul „Trimite e-mail de test”** trimite un mesaj la „Adresa care primește
anunțul”, cu datele **salvate**; cât ai modificări nesalvate scrie „Salvează
întâi, apoi testează”. Poate ține până la o jumătate de minut. Răspunsul apare
lângă buton și spune pe ce drum a plecat mesajul:

- „Căsuța … a luat în primire mesajul de test pentru … De aici îl duce
  furnizorul căsuței: caută-l în Inbox, iar dacă nu e acolo, în Spam.” —
  serverul căsuței a răspuns că are mesajul;
- „Serverul a predat mesajul programului lui de poștă. Asta nu înseamnă că a și
  ajuns: caută-l la …, în Inbox și în Spam.” — drumul direct.

**Ce nu dovedește testul.** Pe drumul direct, „predat” nu înseamnă „ajuns”:
serverul află numai că programul lui de poștă a primit mesajul. Nici pe drumul
căsuței testul nu vede în Inboxul tău. Singura dovadă e mesajul găsit acolo —
de aceea, după primul test, uită-te și în Spam. Un test picat își scrie pricina
tot lângă buton, cu vorbele serverului și cu ce ai de făcut: „Serverul de
e-mail a refuzat utilizatorul ori parola căsuței. Scrie parola din nou în
Setări → Notificări → E-mail și apasă iar butonul de test.”, ori îndemnul de a
încerca celălalt fel de criptare, când legătura criptată n-a pornit.

**Când nu pleacă un link de parolă** afli din două locuri: caseta roșie de
deasupra filelor, cu rândul „Parolă uitată — linkul n-a plecat (vezi
Jurnalul)”, și Jurnalul, cu pricina. Titlul casetei roșii spune și despre un
asemenea link „Un anunț nu a plecat.”: rândul de sub el e cel care lămurește.
Ce citește vizitatorul și ce rămâne în Jurnal scrie în
`docs/05-conturile-de-client.md`, la „Dacă și-a uitat parola”.

**Pe un server fără migrarea 037** casetele căsuței stau închise, cu rândul
„Căsuța nu se poate completa încă: pe server lipsesc rândurile ei, fiindcă
migrarea sql/037_posta_smtp.sql n-a intrat. Rulează actualizarea (sudo bash
actualizeaza-vps.sh), apoi reîncarcă pagina.” Până atunci e-mailul are numai
drumul direct.

### WhatsApp — ce trebuie să faci tu

Eu am scris integrarea, dar **nu introduc credențiale**. Tokenul îl pui tu, o
singură dată, în panou.

1. Creează un cont pe <https://business.facebook.com> (Meta Business).
2. În **Meta for Developers** → aplicație nouă → produsul **WhatsApp**.
3. La **API Setup** găsești **Phone Number ID** și un **Access Token**.
4. Trimite spre aprobare un șablon („utility template”) cu **un singur
   parametru în corp** — acolo intră rezumatul comenzii. Numele implicit pe
   care îl așteaptă aplicația este `comanda_noua`; dacă îl numești altfel,
   scrie numele tău în câmpul „Nume șablon”.
5. Completează în panou: furnizor **Meta Cloud API**, numărul tău de WhatsApp
   (cu prefix de țară), Phone Number ID, tokenul, numele șablonului.
6. **Salvează**, apoi apasă **Trimite mesaj de test**.

Butonul de test folosește datele deja salvate, nu ce e pe ecran — de aceea
salvezi întâi. Dacă Meta refuză, eroarea lor apare ca atare în panou
(„template not found”, „token expired”) și îți spune exact ce lipsește.

> **Tokenul de acces expirat** este cea mai frecventă cauză. Tokenul temporar
> din API Setup ține 24 de ore; pentru producție generează unul permanent, din
> **System Users**.

### Cum sunt păstrate tokenurile

Criptate în baza de date, cu cheia din `api/config.php`. Serverul **nu le
trimite niciodată înapoi în clar**: în panou vezi doar ultimele caractere
(`••••••••3f9a`), cât să recunoști care token e salvat.

Din asta decurg două reguli:

- **Câmpul lăsat neatins păstrează valoarea salvată.** Nu trebuie să reintroduci
  tokenul la fiecare salvare.
- **Ștergerea se face explicit**, cu butonul „Șterge tokenul”.

**Parola căsuței de e-mail** (de la 2.7.5) se păstrează la fel, criptată cu
aceeași cheie, cu o deosebire: din ea nu vezi nici ultimele semne. Un token e
un șir scos de o mașină; parola o alege un om, iar coada ei spune ceva despre
ea. Caseta stă deci goală, cu „Parolă salvată — lasă gol ca s-o păstrezi”, iar
ștergerea se face cu „Șterge parola”.

Dacă schimbi `encryption_key` din `config.php`, tokenurile și parola căsuței
deja salvate devin ilizibile și trebuie reintroduse. Aplicația continuă să
funcționeze, dar notificările pe canalul respectiv se opresc până le pui la
loc.

### Ce anunțuri primești, și ce scrie în ele

În fila **Notificări**, cardul **„Ce anunțuri primești, și pe unde”** are șase
rânduri, fiecare cu câte o bifă pentru e-mail, WhatsApp și Telegram: *Cerere
nouă · Comandă nouă · Comandă livrată · Comandă anulată · Cont nou pe site ·
Articol sub pragul minim*. Un canal oprit din întrerupătorul lui nu trimite
nimic, oricâte bife ar avea aici. Textul anunțului e același pe toate trei
canalele; numai WhatsApp prin șablonul Meta îl lipește pe un singur rând (v.
„Comenzile lungi”, mai jos).

Două rânduri și-au schimbat numele în 2.6.0. Bifele puse înainte rămân pe loc:
s-a schimbat numai ce scrie pe ecran.

- **„Comandă livrată”** se chema „Comandă gata” (hotărârea patronului din 2
  octombrie 2026). De când laboratorul are etapa „Gata de livrare”, un anunț
  numit „gata” ar fi fost citit drept marfa pusă pe masă, nu marfa predată.
  Pleacă tot la intrarea în etapa care încheie comanda; v. „Etapele cu rol
  special”, mai jos. Mesajul începe cu aceleași cuvinte ca bifa — „Comandă
  livrată #1042 — …” —, apoi spune trecerea („Etapă: Gata de livrare →
  Predată”), ziua și totalul. La o comandă după care vine clientul, sub cap
  mai stă un rând: *„Onorare: Ridicare — vine clientul după marfă”*, ca să
  deosebești de pe telefon o ridicare de o livrare.
- **„Cerere nouă”** se chema „Cerere nouă de pe site”. Din 18 septembrie 2026
  tot aici ajung și cererile agenților de vânzări, iar cu numele vechi
  patronul care voia să afle de ele căuta o a doua bifă, care nu există. Cine
  a scris cererea spune anunțul însuși.

**Ce scrie în „Comandă nouă”.** Până la 2.6.0 mesajul spunea numărul, clientul,
ziua, telefonul, adresa, produsele și totalul. Acum spune și cele patru lucruri
după care hotărăști, de pe telefon, dacă ai de sunat pe cineva — cine a scris
comanda, al cui e clientul, unde merge marfa și pe ce latură e fiecare produs:

| Rândul | Ce spune |
|---|---|
| „Comandă nouă #1042 — …” | primul rând, neschimbat: după el cauți comanda în panou |
| „Scrisă de: … (agent de vânzări)” | omul din casă care a scris-o; la cererea venită de pe site, „Trimisă de client, de pe site” |
| „Acceptată de: … (administrator)” | cine a acceptat cererea din care s-a născut comanda; cu „, de pe Telegram” când s-a apăsat butonul de acolo. Lipsește când a acceptat-o chiar cel care a scris-o. De la 2.7.5, când a apăsat în grup un om cu contul nelegat, rândul se pune întotdeauna și poartă numele lui de pe Telegram, fără rol: „Acceptată de: Ion Pop, de pe Telegram (cont nelegat)” — v. „Telegram: cine apare când apeși un buton din grup”, mai jos |
| „Client adus de: …” | recomandantul clientului; „nimeni (client al casei)” când n-are. Când cel care a scris comanda e chiar cel care a adus clientul, rândul „Scrisă de” **nu se mai pune**, iar omul e numit o singură dată, aici: „Client adus de: … — tot cine a scris comanda” (la o cerere, „— tot cine a scris cererea”) |
| „Atenție: …” | numai când a scris-o un agent de vânzări, v. mai jos |
| „Pentru: 2026-10-03, ora 08:00” | ziua, cu ora din câmpul „Interval”; în coada rândului, „(oră fixă)” când butonul de sub „Interval”, în formularul comenzii, scrie „Ora se afișează pe panouri” |
| „Onorare: Livrare” | modul de onorare; la ridicare, „Onorare: Ridicare — vine clientul după marfă” și **fără adresă**, fiindcă adresa unei ridicări e a clientului, nu a drumului |
| „Adresă: … · Sector 2” | adresa și zona, pe același rând |
| „· 24 × Croissant cu unt — congelat” | câte un rând pe produs, cu latura lui: „congelat” ori „copt” |
| „Total: … lei” | totalul comenzii întregi |

Telefonul și mențiunile stau mai departe în mesaj, ca până acum. Rolul de
lângă nume e scris ca în lista din Conturi („agent de vânzări”,
„administrator”, „om de laborator”). Care moduri sunt „ridicare” spune lista
**Moduri fără mașină** din fila Liste, nu numele modului — v. „Ce ajunge pe
ruta șoferului”, mai jos.

Rândul **„Atenție”** se aprinde numai pentru un agent de vânzări, în două
cazuri, fiecare cu vorbele lui:

- *„Atenție: a scris-o alt agent decât cel al clientului.”*
- *„Atenție: clientul e mai vechi al casei, iar cererea poartă codul
  agentului.”* — clientul n-a fost adus de nimeni, iar agentul a scris pe
  numele lui o cerere cu codul lui. La acest caz comisionul chiar se scrie pe
  codul agentului, de aceea trebuie văzut de un om.

Administratorul și managerul scriu comenzi pentru clienții oricui — asta le e
munca —, deci la ei nu se aprinde.

Rândul „Client adus de” **lipsește** când nu se poate afla: numele de pe
comandă duce la două conturi, ori la un cont înscris singur pe site și încă
nerecunoscut de casă. Lipsa înseamnă „nu știm”, nu „al casei”.

**Un caz rămas nereparat și în 2.7.0.** Dacă ștergi contul unui om din casă
care mai are cereri nehotărâte, la acceptare cererile lui se anunță *„Trimisă
de client, de pe site”*: ștergerea golește pe cerere numele celui care a
scris-o. Până se repară, **oprește** contul (comutatorul „Cont pornit” din
Conturi) în loc să-l ștergi; un cont oprit își păstrează numele pe cereri.

„Cerere nouă” are aceleași rânduri despre oameni, loc și produse, cu „Total
estimat” în loc de „Total”, și se termină cu *„Acceptă sau respinge din pagina
Administrare Comenzi.”*

> **Pe Telegram, anunțul pleacă în conversația aleasă în Setări — de obicei
> grupul personalului —, nu numai la tine.** Rândurile „Client adus de” și
> „Atenție” le citește deci tot grupul, cu agentul care a scris cererea cu tot.
> Formularul de comandă îi ascunde agentului ce clienți are casa; anunțul i-o
> spune, o clipă mai târziu. E hotărârea ta dacă rândurile acestea au ce căuta
> acolo.

**Comenzile lungi.** După 40 de produse lista se oprește cu „… și încă 3
poziții — vezi în panou” (cu numărul lor), iar totalul de sub ea rămâne al
comenzii întregi. Telegram refuză cu totul un mesaj mai lung de 4096 de semne,
deci unul care tot trece de 4000 pleacă tăiat, cu ultimul rând *„… (mesaj tăiat
— vezi în panou)”*. Pe WhatsApp, prin șablonul Meta, rândurile se lipesc într-un
singur șir, iar șirul se taie la 1000 de semne: la o comandă lungă se pierd
ultimele produse și totalul. Tăietura e veche; cu rândurile noi, se ajunge la
ea mai devreme.

### Telegram: cine apare când apeși un buton din grup

Sub anunțul unei cereri noi, botul pune două butoane: „Acceptă” și „Respinge”.
Cutia **„Butoane sub anunț”**, din cartonașul „Telegram”, le pornește și le
oprește și ține legătura botului cu serverul: „Leagă botul” (după aceea,
„Leagă din nou”) și „Dezleagă botul”, pe care le are numai conducerea — un
administrator ori un fondator. Apăsarea de pe telefon face exact ce ar fi făcut
apăsarea din panou și se scrie la fel în Jurnal. Anunțul de comandă nouă pleacă
fără butoane.

Botul stă într-un grup, iar în grup stă toată tura. Până la 2.7.5, oricine ar
fi apăsat „Acceptă”, mesajul, panoul și Jurnalul spuneau că a acceptat cel care
legase botul — de obicei patronul. De la 2.7.5 (hotărârea patronului) o apăsare
poartă numele celui care a apăsat și **niciodată numele altcuiva**. Ce se
întâmplă atârnă de un singur lucru: dacă Telegramul celui care a apăsat e legat
de contul lui din aplicație.

| Cine a apăsat | Ce se întâmplă | Cu ce nume apare |
|---|---|---|
| un om cu contul **legat**, pornit și cu dreptul de a scrie comenzi | hotărârea se scrie | numele contului lui: „✅ Acceptată de Maria Ionescu, de pe Telegram” |
| un om cu contul legat, dar **oprit** ori fără dreptul acela | **nimic**; pe telefon citește „Contul tău e legat de Telegram, dar e oprit ori nu are dreptul de a scrie comenzi. Nu am scris nimic. Cere dreptul din pagina „Acces”, apoi apasă din nou.” | — |
| un om cu contul **nelegat** | hotărârea se scrie, pe drepturile contului care a legat botul | numele lui de pe Telegram și vorba „cont nelegat”: „✅ Acceptată de Ion Pop, de pe Telegram (cont nelegat)” |
| oricine cu contul nelegat, când contul care a legat botul a fost oprit ori nu mai e de administrator | **nimic**; pe telefon citește că botul nu e legat de niciun cont de administrator și că se leagă din Setări → Notificări, cu „Leagă botul” | — |

Numele omului nelegat e același peste tot, mereu cu vorba lângă el: sub mesajul
din grup; în anunțul comenzii născute din cerere („Acceptată de: Ion Pop, de pe
Telegram (cont nelegat)”); în panou, pe cererea hotărâtă și, când cererea a
venit de pe site, la „Creat de” al comenzii născute din ea („Ion Pop —
Telegram, cont nelegat”); și în Jurnal, unde rândul scrie „Ion Pop (Telegram,
cont nelegat)”, fără niciun rol lângă nume. Cine n-are un nume pe Telegram
apare cu numele lui de utilizator de acolo, cu „@” în față, iar în lipsa lui,
cu „Telegram #” și numărul lui.

**Numele de pe Telegram și-l pune fiecare singur.** Un om nelegat își poate
scrie acolo orice nume, iar hotărârea lui se scrie totuși, pe drepturile
contului care a legat botul. De aceea „cont nelegat” stă mereu lângă nume: e
semnul că aplicația nu știe cine e omul, numai cum își spune. Leacul e legarea,
care se face în două feluri.

**„Contul meu de Telegram”** — fiecare își leagă singur contul, în trei pași.
Apeși „Leagă-mi contul”, iar botul trimite în grup un buton pentru tine:
„Butonul a plecat în grupul de pe Telegram. Apasă-l de pe telefonul tău, apoi
întoarce-te aici și încuviințează.” Îl apeși de pe telefon. Te întorci în
Setări și **reîncarci pagina** — cutia își citește starea numai la deschidere,
deci fără reîncărcare rămâne pe „Butonul e în grup”. Reîncărcată, scrie „A
apăsat: …”, cu numele de pe Telegram al celui care a apăsat, și apeși „Da, eu
am apăsat” — **numai dacă ai apăsat chiar tu**:
de-atunci încolo, apăsările omului aceluia îți poartă numele. Butonul trimis
ține o jumătate de oră; „Trimite alt buton” îl înlocuiește. „Dezleagă-mi
contul” desface legătura. Cutia cere botul legat: fără el, butonul ei stă stins
și spune „Leagă întâi botul, mai sus: butonul tău pleacă tot prin el.”

**„Oameni din grup”** (nouă în 2.7.5) — fondatorul îi leagă pe ceilalți, fără
ca ei să aibă pagina Setări. Cutia ține minte pe oricine a apăsat un buton sub
un anunț: numele lui de pe Telegram, „@”-ul dacă are, „ultima apăsare” și câte
apăsări a făcut, cu insigna „Nelegat” ori „Legat de …”. Pe rândul unui om
nelegat alegi din „Alege contul…” contul lui din aplicație și apeși „Leagă”;
pagina răspunde „Legat. De-acum, ce apasă de pe telefon se scrie pe numele
contului.” Pe rândul unui om legat stă „Dezleagă”. Cutia nu trece prin
„Salvează” din bara de sus: fiecare apăsare lucrează pe loc.

- **Numai fondatorul.** Legătura hotărăște al cui nume se scrie sub o cerere
  acceptată. Cine are Setările fără să fie fondator citește, în locul listei,
  „Pe oamenii din grup îi leagă de conturi numai fondatorul.”
- **„Leagă numai un om pe care îl recunoști.”** Numele din listă e cel pe care
  și l-a pus omul pe Telegram, nu unul verificat de cineva. Dacă nu ești sigur
  cine e, roagă-l să mai apese o dată un buton cât ești lângă el și apasă
  „Reîncarcă lista”: rândul lui e cel la care se mișcă „ultima apăsare”.
- **Lista se umple din apăsări**, nu din membrii grupului: un coleg care n-a
  apăsat niciodată un buton nu apare. Până apasă cineva, cutia scrie „Încă n-a
  apăsat nimeni un buton în grup.” Lista nu se împrospătează singură — pentru
  asta e „Reîncarcă lista” — și arată cei mai noi cincizeci de oameni.
- **În lista de conturi** intră numai conturile pornite ale casei care n-au
  deja un Telegram legat. Unul care nu poate hotărî cereri rămâne în listă, cu
  coada „— nu poate hotărî cereri”: legat de el, omul apasă și e refuzat. La
  fel, sub un om legat de un cont oprit scrie „Contul e oprit: ce apasă omul de
  pe telefon e refuzat până îl pornești ori îl dezlegi.”
- **Cum desfaci:** „Dezleagă”, pe rândul omului. Pagina răspunde „Dezlegat.
  De-acum, ce apasă apare cu numele de pe Telegram și cu „cont nelegat”.” Ce a
  hotărât cât a fost legat rămâne scris pe numele contului.
- **Conturi legate fără rând în listă.** Un cont poate avea Telegram legat fără
  ca omul lui să fi apăsat ceva în grup de când se ține lista — de pildă unul
  legat prin „Contul meu de Telegram” înainte de 2.7.5. Sub listă, cutia le
  arată separat, sub „Conturi legate care n-au mai apăsat nimic în grup”, cu
  numele și rolul contului („· oprit” la unul oprit) și cu „Dezleagă” pe fiecare
  rând. Dacă nu știi al cui e telefonul legat acolo, dezleagă contul (fișa 710).
  Numărul de Telegram nu se arată nici aici.

**Ce nu s-a schimbat.** Runda n-a rescris nimic din ce era în bază: o cerere
hotărâtă de pe telefon înainte de 2.7.5 poartă mai departe numele pus atunci.
Pe un server pe care n-a intrat încă migrarea 036, apăsarea unui om nelegat e
refuzată, cu numele fișierului (`sql/036_telegram_oameni.sql`), în loc să se
scrie pe numele altcuiva; apăsările celor legați merg ca înainte.

---

## Casetele care stau închise

O casetă din **General → Date despre firmă** poate apărea închisă, cu un rând
colorat sub ea în locul ajutorului obișnuit. Înseamnă că pe server lipsește
rândul acelei setări: setările se scriu peste rânduri care există, iar rândul
îl aduce o migrare din `sql/` care n-a intrat încă. Rândul de sub casetă o
numește: `018_adresa_si_program.sql` pentru **Adresa publică** și **Program de
lucru**, `017_procent_recomandare.sql` pentru **Procent de recomandare**.

La fel pot sta închise cele patru casete din cardul **„Catalogul”**, tot din
fila General, cât lipsește `028_preturi_pe_trepte.sql` — v. capitolul
„Catalogul”, mai jos.

Leacul e actualizarea — `sudo bash actualizeaza-vps.sh`, vezi
`docs/09-actualizare-vps.md` — apoi reîncarci pagina. Până atunci, pentru adresă
și program, site-ul arată valorile scrise în cod.

Dacă o asemenea setare ajunge totuși într-o salvare, pagina nu trimite nimic:
spune care casete n-au rând, le scoate din formular, iar restul modificărilor
rămân pe ecran pentru o nouă apăsare pe **Salvează**. Serverul face la fel pe
partea lui: o cerere cu chei fără rând e refuzată întreagă, cu toate cheile
numite (`unknown_setting`), și nu scrie nimic din ea.

---

## Punctul de plecare al livrărilor

În fila **General**, în cardul „Date despre firmă”, ultimele trei câmpuri:
„Punctul de plecare al livrărilor”, adică numele depozitului, apoi „Latitudine
depozit” și „Longitudine depozit”. Sub cardul acela, fila mai are și altele,
începând cu „Catalogul”, deci nu le căuta la capătul paginii. De aici pleacă
mașina, iar din ele se calculează kilometrii de pe ruta șoferului și ordinea
opririlor la „Optimizează ruta”.

Coordonatele implicite sunt ale depozitului real — incinta ICTCM, Șos.
Olteniței 103. Prototipul avea altceva scris în cod, „Str. Franceză 12”, în
centru, la vreo cinci kilometri distanță: fiecare estimare de rută pornea din
alt cartier.

Dacă te muți, le iei din Google Maps: clic dreapta pe locul depozitului →
primul număr e latitudinea, al doilea longitudinea.

---

## Catalogul: cum se deschide magazinul, și de unde pornesc pragurile

Al doilea card din fila **General**, **„Catalogul”**, venit în 2.5.0 cu
migrarea 028 (sub el stă, de la 2.7.0, cardul „Comenzi neplătite”, cu capitolul
lui, mai jos). Are patru casete:

| Caseta | La pornire | Ce hotărăște |
|---|---|---|
| Cum se deschide magazinul pe telefon și tabletă | Patru pe ecran | vederea cu care intră un client pe un ecran sub 1080 de puncte |
| Cum se deschide magazinul pe calculator | Patru pe ecran | la fel, de la 1080 de puncte în sus |
| Prețul B începe de la (buc.) | 100 | pragul cu care pornește un produs în Catalog |
| Prețul C începe de la (buc.) | 300 | la fel, pentru C |

**Vederile** sunt trei: *Unul pe rând, cu toate detaliile · Patru pe ecran ·
Șase pe ecran* — ce înseamnă fiecare, pe ce ecran, scrie în
`docs/04-catalogul-de-produse.md`, la „Cum se vede catalogul”. Hotarul dintre
„telefon și tabletă” și „calculator” e același pe care îl folosește magazinul
pentru tot restul, 1080 de puncte, deci o tabletă ținută pe lat poate intra pe
vederea de calculator.

**Pe telefon, pornirea s-a schimbat la 2.7.0.** Până atunci magazinul se
deschidea acolo pe „Unul pe rând”. La actualizare, migrarea 032 a trecut caseta
pe „Patru pe ecran” — o singură dată, și numai dacă era încă pe „Unul pe
rând”; „Șase pe ecran”, dacă îl aleseseși tu, a rămas cum era. „Unul pe rând”
îl poți pune la loc de aici oricând: actualizările de după nu-l mai ating.

Clientul își poate schimba vederea din magazin, iar alegerea lui se ține minte
în browserul lui. **Până schimbi tu vederea de aici**: atunci schimbarea ajunge
la toată lumea, și la cine își alesese alta înainte. Altfel un client care a
apăsat o dată „Șase” n-ar mai vedea niciodată vederea aleasă de tine.

Setările nu ating vederile din panou: Catalogul și Conturile își țin fiecare
vederea lui, în browserul celui care lucrează.

**Pragurile** de aici nu schimbă niciun produs. Sunt doar valorile cu care se
precompletează rândul de praguri în formularul unui produs care n-are încă
praguri; fiecare produs își are apoi pragurile lui, iar ele se salvează numai
împreună cu un preț B ori C. Cum se aplică prețurile, în
`docs/04-catalogul-de-produse.md`, la „Trei prețuri, după cantitate”.

Casetele își spun singure greșeala, înainte de „Salvează”: un prag e un număr
întreg de bucăți, între 2 și 10000, iar al lui C trebuie să fie mai mare decât
al lui B (*„Trebuie să fie mai mare decât pragul lui B (100 buc.).”*). O casetă
goală e îngăduită și înseamnă „nu precompleta nimic”. Serverul judecă la fel, și
refuză întreagă o salvare care ar lăsa pragul C sub B.

Pe o bază fără migrarea 028, cele patru casete stau închise, cu rândul care
numește migrarea (v. „Casetele care stau închise”, mai sus). Până atunci
magazinul se deschide pe „Patru pe ecran”, și pe telefon, și pe calculator.

---

## Pragul de comenzi neplătite

De la 2.7.0, un client care a strâns prea multe comenzi neplătite nu mai poate
trimite alta până plătește. „Prea multe” e un număr, **pragul**, și pornește de
la **2** (hotărârea patronului din 4 octombrie 2026): cu o comandă neplătită
clientul e prevenit, la a doua e oprit. Ce vede el și ce vede omul din birou
care scrie comenzi pe numele lui scrie în `docs/05-conturile-de-client.md`, la
„Când ajunge la pragul de neplătite” și la „Clientul ajuns la prag, în
formularul tău”. Aici e numai numărul.

Pragul **general** e al tuturor clienților care n-au unul al lor. Se schimbă
din două locuri, care scriu aceeași setare:

- **Setări → General**, al treilea card, **„Comenzi neplătite”**, caseta **„Prag
  de comenzi neplătite”**. Pleacă odată cu restul paginii, la „Salvează”.
- **„Încasări”**, cardul **„Pragul pentru toți clienții”**, cu butonul lui,
  „Salvează pragul”.

**Ce număr se poate scrie.** Un număr întreg de la 0 la 99. **Zero înseamnă
„fără limită”**: clienții care urmează pragul general nu mai sunt opriți,
oricâte comenzi neplătite au. Caseta goală nu se salvează, fiindcă golul nu
înseamnă zero; pagina o spune sub casetă: „Caseta goală nu se salvează: golul
nu înseamnă zero. Scrie un număr de la 0 la 99.” Cu pragul 1 nu mai există
avertizare: clientul e oprit de la prima comandă neplătită.

**Pe cine atinge.** Numai pe clienții lăsați pe „General”. Cel căruia i-ai pus
un prag al lui, din Conturi, rămâne cu al lui, orice ai scrie aici (v.
`docs/05-conturile-de-client.md`, la „Pragul de neplătite al unui client”).
Schimbarea se simte de la prima comandă pe care clientul încearcă s-o trimită:
socoteala se face din nou de fiecare dată, deci un prag urcat îl dezleagă pe
loc, iar unul coborât îl oprește la următoarea încercare. Comenzile deja
primite nu se ating.

**Cine vede cardul.** Nu ajunge să ai pagina Setări. Cardul din Setări se
desenează numai celui care are și fapta **„Pragul de neplătite al unui
client”**, iar fapta ține de panoul „Conturi”. Din pornire o are numai
fondatorul: administratorul deschide Setări și nu găsește nici titlul cardului.
E dinadins — un card gol ar fi spus că există o setare pe care omul n-o poate
vedea. Dacă vrei ca administratorul să poată muta pragul, bifează-i fapta în
„Acces”. Cardul lipsește la fel pe un server pe care n-a intrat încă migrarea
`sql/032_plati_si_prag.sql`.

**Cum desfaci.** Scrii la loc numărul dinainte și salvezi. Schimbarea rămâne în
Jurnal cu amândouă numerele — „Pragul general: 2 → 3” —, deci pe cel vechi îl
găsești acolo dacă l-ai uitat. Rândul acesta îl vede însă numai cine are, pe
lângă „Jurnal”, și panoul „Încasări”.

---

## Listele configurabile

Etapele din laborator, intervalele de livrare, zonele, modurile de onorare,
categoriile și unitățile din depozit — toate se editează din fila **Liste**.
Se folosesc în toate panourile.

Ordinea contează la **etape**. Lista se cheamă în fila Liste **„Etape în
laborator”**: butonul de etapă din panoul laboratorului poartă numele etapei
următoare de aici și duce comanda exact acolo — dar numai până la etapa
dinaintea celei care încheie comanda. Muți o etapă cu săgețile ↑ ↓.

### „Gata de livrare”: unde se oprește laboratorul

De la 2.6.0 lista are o etapă nouă, **„Gata de livrare”**, chiar înaintea celei
care încheie comanda: marfa e ambalată și așteaptă mașina ori clientul. O
instalare nouă pornește cu *În așteptare · La dospit · În cuptor · La ambalare
· Gata de livrare · Predată · Anulată*. Pe una care rulează deja, etapa o pune
actualizarea (migrarea `030_gata_de_livrare.sql`), o singură dată, înaintea
etapei finale, oricum s-ar numi aceea.

**Laboratorul se oprește la „Gata de livrare”, pentru toată lumea —
administratorii inclusiv.** Pe tableta din laborator, butonul unei comenzi
ajunse acolo se stinge și spune pe cine așteaptă comanda. Etapa finală
(„Predată”) n-o mai pune nimeni de pe tabletă. O pune:

- **șoferul**, de pe „Rută”, cu „Marchează livrat”, după ce a bifat ce a
  încărcat;
- **biroul**, din „Comenzi” — de pildă când clientul a venit singur după marfă.

Până la 2.6.0 oprirea ținea numai pentru conturile fără drept de livrare: un
administrator ori un șofer cu bifa de livrare ducea comanda până la „Predată”
chiar de pe tabletă, deci comanda trecea drept livrată înainte să fi urcat în
mașină. Patronul a cerut, pe 2 octombrie 2026, ca laboratorul să se oprească
aici pentru toți.

**Cine poate pune etapa finală.** Administratorul și fondatorul, oricând:
dreptul le vine din rang și nu e o bifă din „Acces”, deci nu se dă și nu se ia
de acolo. Ceilalți — manager, agent de vânzări, angajat, contul tabletelor —
numai dacă au bifa **„Poate confirma livrarea”**, pusă pe contul lor din pagina
**Conturi**.
Comutatorul stă în fișa fiecărui cont și se scrie de cum îl apeși, fără alt
buton de salvare; sus apare *„„…” poate confirma livrarea.”* Îl desfaci
apăsând încă o dată (*„„…” nu mai poate confirma livrarea.”*). Dedesubt are un
rând care spune ce înseamnă:

| Pe ce cont | Rândul de sub comutator |
|---|---|
| administrator, fondator | *„Confirmă livrarea oricum: dreptul îi vine din rol, nu din bifa asta.”* |
| alt cont, cu bifa pusă | *„Poate pune etapa „Predată” din „Ruta șoferului”.”* |
| alt cont, fără bifă | *„Fără bifă, „Marchează livrat” e stins pe telefonul lui, iar serverul refuză etapa „Predată”.”* |

Pe fișa unui client și, de la 2.6.0, pe a unui om de laborator comutatorul nu
se mai desenează: niciunul nu ajunge la vreun loc din care se pune etapa
finală, deci bifa s-ar fi scris fără să schimbe nimic. Numele etapei, în
rândurile de mai sus, e cel din Setări: dacă redenumești „Predată”, rândurile
îl urmează.

Bifa nu e numai a șoferului. Un cont fără ea **nu mai găsește etapa finală** în
lista „Stare” din formularul comenzii și nici în lista „Etapa” din vederea
rapidă; sub listă i se spune cine o pune: *„„Predată” o pune un administrator
ori un cont cu bifa „Poate confirma livrarea”, din „Conturi”.”* Un manager care
închide din birou ridicările de la sediu trebuie deci bifat.

**În ziua actualizării, comenzile aflate la „La ambalare” rămân acolo.**
Laboratorul mai apasă o dată pe fiecare, ca să ajungă la „Gata de livrare”, și
abia atunci șoferul le poate marca livrate.

> **După actualizare, reîncarcă orice filă de Setări rămasă deschisă de
> dinainte**, pe orice calculator ori telefon. Pagina trimite lista etapelor
> întreagă, iar o filă veche are lista de dinainte, fără „Gata de livrare”.
> Dacă muți, adaugi ori ștergi o etapă dintr-o asemenea filă și salvezi, „Gata de
> livrare” dispare din listă fără niciun cuvânt — dacă nu stă nicio comandă în
> ea; dacă stă, serverul refuză salvarea și spune câte sunt. Actualizarea n-o
> mai pune la loc: o pune o singură dată. Leacul, dacă s-a întâmplat: Setări →
> Liste → „Etape în laborator”, scrii „Gata de livrare”, o adaugi, o urci cu ↑
> chiar deasupra etapei finale și salvezi.

### Două protecții

**Nu poți șterge o etapă în care se află comenzi.** Serverul refuză și îți
spune câte sunt: *„Etapa „În cuptor” nu poate fi ștearsă: 3 comenzi se află în
ea. Mută-le mai întâi în altă etapă.”* Altfel comenzile ar rămâne cu un status
pe care nicio pagină nu îl recunoaște — ar dispărea din panou, din filtre și de
pe ruta șoferului, fără niciun semn.

**O listă nu poate rămâne goală**, și nici nu poate conține text care nu e
listă validă.

### Etapele cu rol special

Poți redenumi orice etapă. Dar aplicația trebuie să știe **care etapă încheie
comanda** și **care o anulează** — de asta depind:

- pagina clientului: la etapa care încheie comanda scrie „Livrată” (ori
  „Ridicată”, când clientul vine singur după marfă), iar la cea dinaintea ei
  „Gata de livrare” (ori „Gata de ridicat”). Până la 2.6.0 scria „Gata” chiar
  la etapa finală. Pe larg, în `docs/05-conturile-de-client.md`, la „Ce scrie
  pe comanda lui”;
- ruta șoferului (comenzile anulate nu apar pe ea);
- panoul din laborator (comenzile anulate nu apar deloc, iar butonul se
  oprește la etapa dinaintea celei care încheie comanda);
- scăderea din depozit (se face la comenzile încheiate);
- anunțurile de pe telefon: „Comandă livrată” la intrarea în etapa care
  încheie, „Comandă anulată” la intrarea în cea care anulează și „Comandă
  refăcută” la ieșirea din ea. „Comandă refăcută” n-are bifă a ei: pleacă pe
  exact canalele pe care e bifată „Comandă anulată”. O comandă anulată mutată
  direct în etapa care încheie trimite două mesaje, întâi „Comandă refăcută”,
  apoi „Comandă livrată”.

Le alegi din două liste derulante, în cardul **„Etapele cu rol special”**, la
capătul filei **Liste**:

- **„Comanda este încheiată (livrată) când ajunge la”**, cu dedesubt: *„Etapa
  în care marfa a ajuns la client. O pune șoferul, de pe „Rută”, ori biroul,
  din „Comenzi”. Etapa dinaintea ei e ultima pe care o poate pune
  laboratorul.”*
- **„Comanda este anulată când ajunge la”**, cu dedesubt: *„Comenzile de aici
  nu apar pe ruta șoferului.”*

În lista „Etape în laborator”, cele două poartă câte un semn lângă nume:
**LIVRATĂ** și **ANULARE**. Până la 2.6.0 primul scria „GATA” — care ar fi
stat acum chiar sub „Gata de livrare”, pe etapa de după ea.

**Două reguli pentru alegerea lor**, păzite pe server:

- **Comanda se încheie la ultima etapă din listă**; etapa de anulare nu se
  numără, ea poate sta oriunde. Ca altă etapă să încheie comanda, mut-o întâi
  la capătul listei „Etape în laborator”, apoi alege-o aici și salvează —
  merg amândouă în aceeași salvare.
- **Aceeași etapă nu poate și încheia, și anula comanda.**

Regulile se judecă numai la salvarea care **mută** una dintre cele două
alegeri. O salvare care le lasă pe loc trece ca până acum — de pildă o etapă
nouă adăugată la coada listei.

Amândouă regulile sunt noi în 2.6.0. Prima închide o greșeală ușor de făcut,
acum că lista are două etape vecine care sună a sfârșit: „Gata de livrare”
aleasă drept etapa care încheie comanda. Fără regulă, serverul ar fi primit-o,
fiindcă etapa e în listă, și s-ar fi stricat trei lucruri deodată, fără niciun
semn: clientul ar fi citit „Livrată” cu marfa încă în laborator, laboratorul
s-ar fi oprit cu o etapă mai devreme, iar comenzile vechi din „Predată” s-ar fi
întors pe panou drept comenzi de lucru.

**Nu lăsa însă o etapă de lucru după cea finală.** „Adaugă” pune etapa nouă la
coada listei, adică după „Predată”, iar serverul n-o oprește acolo. Laboratorul
o citește drept încheiată, dar clientul ar citi pe pagina lui chiar numele ei
(de pildă „Facturată”), iar „Total de făcut” din laborator ar număra-o iar ca
marfă de făcut. O etapă de lucru nouă o urci deci cu ↑ înaintea lui „Gata de
livrare”, înainte să salvezi. La coadă are ce căuta numai o etapă pe care o
alegi drept etapa de anulare.

#### Caseta roșie de lângă cele două liste

Când serverul refuză o alegere, refuzul se vede de două ori: în bara roșie de
sus, ca orice refuz, și într-o casetă roșie chiar deasupra celor două liste
derulante. A doua e acolo fiindcă cardul e ultimul din filă, iar „Salvează” se
apasă din bara de jos: altfel ai fi rămas cu ochii pe o alegere refuzată fără
să vezi de ce. Caseta scrie cuvintele serverului. Sunt trei feluri de refuz:

1. **Etapa aleasă nu e în listă** — de obicei fiindcă ai șters ori ai
   redenumit-o. *„Etapa „încheiată” trebuie să fie una dintre etapele din
   listă. „…” nu e printre ele.”* (ori „anulată”). Dacă ai umblat numai la
   listă, fără să alegi altceva jos: *„Etapa care marchează comanda „încheiată”
   e acum „…”, iar ea nu mai e în listă. Alege alta la „Etapele cu rol
   special” și salvează amândouă deodată.”* Ce faci: alegi jos etapa cea nouă
   și salvezi o singură dată, lista și alegerea împreună.
2. **Aceeași etapă pe amândouă.** *„Aceeași etapă, „…”, nu poate și încheia,
   și anula comanda. Alege două etape diferite la „Etapele cu rol special”.”*
   Ce faci: alegi două etape diferite.
3. **O etapă după care mai urmează altele, drept „încheiată”.** Cu lista de
   pornire, alegerea lui „Gata de livrare” dă: *„„Gata de livrare” nu poate fi
   etapa care încheie comanda: după ea mai urmează „Predată”. Comanda se
   încheie la ultima etapă din listă (cea de anulare nu se numără). Dacă
   marfa ajunge la client chiar la „Gata de livrare”, mut-o întâi la capătul
   listei „Etape în laborator”, apoi alege-o la „Etapele cu rol special” și
   salvează.”* Ce faci: de cele mai multe ori, pui la loc etapa de dinainte
   („Predată”). Numai dacă marfa chiar ajunge la client la etapa aleasă, o
   muți la capătul listei și salvezi.

Caseta n-are ×. Se stinge singură de cum umbli la „Etape în laborator” ori la
una dintre cele două liste derulante, la „Renunță” și la salvarea următoare.
Nu se stinge când închizi bara de sus și nici când schimbi alt câmp, de pildă
un telefon: rămâne lângă alegerea care a pricinuit-o până o îndrepți.

### Ecranul rămas în urmă

Tableta din laborator își împrospătează lista la patru secunde, telefonul
șoferului la cincisprezece. Cele două nu tac la fel cât ecranul stă stins:
tableta se oprește de tot cât fila e ascunsă și își recitește lista pe loc la
întoarcere, pe când foaia de drum își ține tura ei de cincisprezece secunde și
nimic mai mult — nu se uită dacă șoferul tocmai a scos telefonul din buzunar.
Oricum ar fi, între ce scrie pe ecran și ce e în baza de date încape o mutare
făcută între timp de altcineva: un sfert de minut în mâna șoferului, ori cât a
stat tableta cu fața la perete.

Apăsarea de pe un ecran rămas în urmă se scria fără să întrebe nimic. Cel mai
urât caz: administratorul anulează o comandă, iar tableta, trezită din somn, o
împinge mai departe pe flux. Comanda învia în tăcere — reapărea în laborator și
pe rută, iar echipa primea „Comandă refăcută”, deși nimeni n-a refăcut-o.

Acum fiecare apăsare spune serverului și **de pe ce etapă a plecat**. Serverul
compară etapa aceea cu cea din baza de date, sub lacăt — adică o a doua apăsare
venită în aceeași clipă din alt panou își așteaptă rândul, nu citește vechiul ca
să scrie peste — și hotărăște:

- se potrivesc: mutarea se face ca întotdeauna;
- nu se potrivesc: **nu se scrie nimic**. Nici etapa, nici rândul de jurnal,
  nici vreun anunț către echipă. Ecranul primește înapoi etapa de acum și scrie
  omului, în românește: „Comanda #1042 a fost schimbată între timp: acum e
  „Anulată”. N-am mutat-o.” Apoi își recitește lista, ca să arate adevărul.

Nu e o piedică pentru mâna iute. Două apăsări una după alta pe același card —
„La dospit”, apoi „În cuptor” — merg mai departe: a doua pleacă la server abia
după ce prima s-a scris, deci pornește de pe etapa adevărată. Iar dacă prima
n-a ajuns, a doua nu mai pleacă deloc: lista tocmai se recitește, și n-are rost
să se scrie peste ea o etapă pe care serverul n-a văzut-o niciodată.

O pagină veche, rămasă deschisă într-un browser de dinaintea actualizării, nu
trimite etapa de plecare. Pentru ea nu se schimbă nimic — merge ca până acum, și
se repară singură la prima reîncărcare.

### Ce ajunge pe ruta șoferului

La fel ca la etape, aplicația trebuie să știe **care moduri de onorare pun
mașina pe drum**. De asta există, sub „Moduri de onorare”, a doua listă:
**Moduri fără mașină**. Tot ce nu e trecut acolo devine oprire pe ruta
șoferului.

Lista e scrisă pe dos dinadins. Un mod nou — să zicem „Curier” — intră singur
pe rută până când cineva spune explicit că nu are ce căuta acolo. O oprire în
plus se vede și se scoate; una lipsă nu se vede deloc, iar clientul rămâne
fără marfă.

Implicit sunt trecute acolo `Angro` și `Ridicare`, adică exact ce era înainte
scris în pagină: rută = doar „Livrare”. Diferența e că acum poți redenumi
„Livrare” fără să golești ruta.

Tot lista asta spune cine vine singur după marfă, și în alte trei locuri: pe
pagina clientului („Gata de ridicat” și „Ridicată” în loc de „Gata de livrare”
și „Livrată”), pe butonul stins din laborator și în anunțuri. De aceea o
comandă Angro se anunță, cu lista de pornire, *„Onorare: Angro — vine clientul
după marfă”*, fără adresă. Dacă la voi marfa angro pleacă cu mașina, scoate
`Angro` de aici: comenzile lui intră pe rută, iar anunțul și pagina clientului
le spun livrări.

> Pe o instalare care rulează deja, importă o dată `sql/002_seed.sql` ca
> rândul să existe și lista să se poată edita din panou. Fișierul e
> `INSERT IGNORE`: nu atinge nimic din ce ai configurat deja. Până atunci,
> serverul cade pe valorile implicite și ruta merge ca înainte.

---

## Codul tabletelor

Tableta din laborator e folosită de mai mulți oameni, deci nu are cont propriu.
Primul cod îl generează instalarea și îl arată o singură dată, pe pagina de
final a lui `api/setup.php`; de acolo încolo îl schimbi din fila **Tableta**.
Până la 2.7.0 fila se chema „Acces”; numele a trecut la pagina fondatorului,
cea cu bifele pe roluri, iar două lucruri cu același nume s-ar fi încurcat.

Se verifică **pe server** și se păstrează criptat, ca tokenurile. Sesiunea pe
care o deschide vede și avansează comenzi, dar nu ajunge la conturi, setări sau
jurnal. De la 2.7.0 marginea ei e scrisă și ca plafon: tableta ține de rolul
„Angajat” și îi primește bifele din „Acces”, dar numai până la Laborator, Rută,
Depozit și Pontaj. Orice altceva i-ai bifa angajatului, tableta nu primește.

**Minimum 6 caractere.** Nu e un capriciu: pragul de încercări greșite pentru
cod este larg — 20 în 15 minute — tocmai pentru că toate tabletele folosesc
același cod. Cu pragul strâns al conturilor obișnuite, opt greșeli de tastare
adunate într-o dimineață aglomerată ar fi închis panoul pentru toată echipa, cu
aluatul pe masă. Compensarea e lungimea codului: un cod scurt s-ar putea ghici
în pragul acela, unul de șase caractere nu.

Cele 20 de încercări se numără **pe fiecare conexiune în parte**, nu la un loc.
Numărate la un loc — cum s-ar părea firesc, de vreme ce codul e unul singur —
oricine de pe internet putea consuma pragul trimițând douăzeci de coduri
greșite către o adresă publică, iar bucătarul primea „Prea multe încercări” cu
codul bun în mână. Acum cine ghicește își blochează doar propria conexiune, iar
tabletele din laborator deschid mai departe. În sens invers, o tabletă care
intră cu codul bun nu mai șterge greșelile strânse de altcineva: altfel fiecare
intrare de dimineață i-ar fi dat celui de afară încă douăzeci de încercări.

**Sesiunea tabletei nu se închide de la sine.** Tableta stă în laborator,
pornită, și e folosită în fiecare zi; un ecran care cere codul în mijlocul turei
oprește lucrul exact când nimeni n-are mâinile libere. Termenul ei e un an
(`session_days_panou` din `api/config.php`), iar cât timp e folosită se împinge
singur înainte — o tabletă din laborator nu ajunge practic niciodată la capăt.
Una lăsată nefolosită un an cere codul din nou.

Tocmai de-aceea, schimbă codul când pleacă cineva din echipă: cine îl are vede
fluxul de comenzi al zilei. **Salvarea codului nou închide pe loc tabletele deja
deschise** — și asta e acum SINGURA cale prin care se închide o tabletă, de
vreme ce sesiunea ei nu mai expiră singură. Tabletele care rămân în laborator
cer codul din nou, o singură dată.

Comutatorul **Activ / Dezactivat** închide complet accesul cu cod, fără să
ștergi codul — și, la fel, scoate afară sesiunile deja deschise.

---

## Plățile și pagina „Încasări”

De la 2.7.0 fiecare comandă își ține și plata, iar pagina **„Încasări”**
(`lab.goldenpastry.ro/incasari`) e locul din care se bifează. Stă aici, lângă
setări, fiindcă tot de acolo se configurează două lucruri ale casei: raportul
de bani de pe Telegram și pragul general de neplătite. Raportul se pune la
punct, de la 2.7.5, și din Setări → Notificări — v. „Raportul de bani pe
Telegram”, mai jos.

**Cine o deschide.** Din pornire, numai fondatorul: pagina e banii casei.
Rândul „Încasări” din „Acces” o dă și altui rol, iar trei fapte, tot de acolo,
împart ce se poate face în ea — „Schimbă starea plății”, „Raportul de bani pe
Telegram” și „Pragul de neplătite al unui client” (v.
`docs/05-conturile-de-client.md`, la „Faptele, din pornire”). Cine are pagina
fără nicio faptă vede listele și sumele, dar nu poate bifa nimic. Cine n-o are
citește „Contul „…” nu are acces la încasări. Banii casei îi vede numai cine a
primit pagina asta.”

**Plata e a comenzii întregi și nu mișcă nimic altceva.** Pagina o spune în
meniul ei: „Plata se ține pe comanda întreagă. O bifă pusă aici nu schimbă
etapa comenzii, totalul ori stocul și nu trimite niciun anunț.” Nu există
plată pe jumătate și nici plată pe un singur produs.

### Cele patru stări

| Starea | Ce înseamnă | Ce citește clientul |
|---|---|---|
| **Neplătită** | banii n-au venit; așa pornește orice comandă | „De plată” |
| **Amânată** | ați căzut de acord asupra unei zile până la care plătește | „De plată” |
| **Plătită** | banii au venit de-a dreptul | „Plătită” |
| **Bancă** — pe larg, „Plătită prin bancă” | banii au venit prin bancă | „Plătită” |

Clientul vede deci două stări, nu patru: amânarea e o înțelegere a casei cu el,
nu o stare pe care s-o citească pe ecran. **O comandă amânată se numără mai
departe la prag**, ca una neplătită: amânarea mută termenul, nu datoria. Pe o
comandă anulată clientul nu vede niciun semn de plată.

Sumele se scriu aici în „lei” — „340,00 lei” —, iar clientului, în magazin și în
„Comanda mea”, în „RON”. E aceeași sumă.

**Comenzile de dinainte de 2.7.0** și-au primit starea o singură dată, la
actualizare (migrarea `sql/032_plati_si_prag.sql`). Cele ajunse la etapa finală
într-o zi de dinaintea actualizării sunt „Plătită” și poartă pe rând „Notă:
migrare 032”, ca să le deosebești de cele bifate de un om. Toate celelalte au
pornit „Neplătită” și, neanulate, se numără la prag ca oricare alta. Dacă una
din ele fusese de fapt încasată, o treci pe „Plătită” din comutatorul de pe
rând; dacă una primită „Plătită” nu fusese, o întorci la fel pe „Neplătită”.
Tabelul întreg și ordinea de lucru din prima zi sunt în
`docs/09-actualizare-vps.md`, la „Ziua actualizării la 2.7.0”.

### Comutatorul de pe rând

Fiecare comandă din listă are, în coloana „Plata”, un comutator cu cele patru
stări. Îl vede numai cine are fapta **„Schimbă starea plății”**. Starea se
scrie de cum o apeși, fără alt buton, iar pe rând apare „Salvat: Plătită.”

**„Amânată”** nu se scrie dintr-o apăsare: deschide o foaie cu **„Plătește până
pe”** și **„Notă (nu e obligatorie)”**, cu butoanele „Renunță” și „Salvează”.
Ziua e obligatorie și se alege de azi înainte, cel mult cu un an. Pe o amânare
deja pusă poți schimba numai nota, fără să-i dai clientului un termen nou — așa
scrii „nu răspunde la telefon” pe una depășită și o lași printre depășite. Pe
rând scrie apoi „până” și ziua, iar după termen, „termen depășit de o zi” ori de
câte zile sunt.

**Cum desfaci.** Apeși, pe același rând, starea de dinainte. Rândul nu fuge de
sub mână când îl bifezi: rămâne pe ecran, cu „Nu mai ține de vederea „De
încasat”. Rămâne aici până reîncarci lista.”, tocmai ca să poți îndrepta o
apăsare greșită. Ce se întâmplă cu clipa și suma plății:

- când comanda trece pe „Plătită” ori „Bancă”, pagina ține minte când și câți
  bani erau pe comandă în clipa aceea;
- întoarsă pe „Neplătită” ori „Amânată”, le uită: n-a intrat nimic;
- de pe „Plătită” pe „Bancă” și înapoi, rămân neatinse: îndrepți felul plății,
  nu faci una nouă, deci o corectură de azi nu mută pe azi o încasare de
  săptămâna trecută.

**Doi oameni pe aceeași comandă.** Dacă altcineva a schimbat plata între timp,
apăsarea ta nu se scrie peste a lui: rândul spune „Altcineva a schimbat-o între
timp.” și arată starea de acum.

### Vederile și plăcile de sus

Sus stau patru plăci cu sume: **„De încasat acum”** (cea mare), **„Amânate”**,
**„Încasat azi”** și **„Încasat în perioadă”**. Încă două apar numai când au ce
arăta:

- **„De restituit”** — comenzi anulate, cu banii încasați. Pe rândul lor scrie
  ce e de făcut: „Comanda e anulată, dar banii sunt încasați. Dă banii înapoi,
  apoi pune aici „Neplătită”.”
- **„Diferențe”** — comenzi schimbate după ce au fost plătite: suma de pe
  comandă nu mai e cea încasată, iar rândul le arată pe amândouă.

O apăsare pe o placă alege vederea ei. Vederile sunt șase — **„De încasat”**,
**„Amânate”**, **„Plătite”**, **„De restituit”**, **„Diferențe”**, **„Toate”** —
iar pagina se deschide pe „De încasat”. Lista are coloanele „Comanda”,
„Clientul”, „Total” și „Plata”.

„De încasat” se poate citi în două feluri: **„Pe comenzi”**, rând cu rând, ori
**„Pe clienți”**, cu câte un cartonaș de client, cel cu soldul cel mai mare
primul. Pe cartonaș, cine are fapta „Pragul de neplătite al unui client”
găsește și butonul „Prag: …”, a doua ușă spre pragul clientului (v.
`docs/05-conturile-de-client.md`, la „Pragul de neplătite al unui client”).

**Lista se strânge în trei feluri:** cu „Caută”, cu perioada („De la”, „Până
la”) și cu un client ales — apeși capul cartonașului lui, în „Pe clienți”, iar
deasupra listei scrie „Numai comenzile lui „…””. La un client fără cont,
aceeași apăsare îi scrie numele în „Caută”. Perioada se alege numai în „Pe
comenzi”: soldul unui client e tot ce are de plătit, iar o perioadă pusă peste
el l-ar arăta mai mic decât e. Fără perioadă aleasă, „Plătite” arată plățile
din ultimele 30 de zile.

**Cum desfaci un filtru.** Fiecare se scoate din locul lui: căutarea, ștergând
ce ai scris; perioada, cu **„Fără perioadă”**; clientul ales, cu **„Toți
clienții”**. **„Șterge filtrele”**, care le scoate pe toate deodată, nu stă
mereu pe pagină: apare numai când lista a rămas goală, sub „Nicio comandă
pentru ce ai ales.”

### Raportul de bani pe Telegram

Cardul **„Raport pe Telegram”**, de sub listă, îl vede numai cine are fapta
**„Raportul de bani pe Telegram”**. Spune ce face: „De două ori pe zi, în
grupul ales: cât e de încasat, cât s-a încasat și ce amânări au trecut de
termen.”

**De la 2.7.5 raportul se pune la punct din două locuri**, cu aceleași
casete: din cardul acesta și din **Setări → Notificări**, unde cutia
**„Raport de bani pe Telegram”** stă ultima în cartonașul „Telegram”. Sunt
aceleași setări, nu două rânduri de setări: ce salvezi într-un loc găsești în
celălalt. Fiecare trimite la celălalt — în Încasări scrie „Aceleași setări sunt
și în Setări › Notificări › Telegram.”, în Setări „Aceleași setări sunt și în
Încasări › Raport pe Telegram.” Cutia din Setări o vede tot numai cine are
fapta; celorlalți nu li se desenează.

| Caseta | La pornire | Ce hotărăște |
|---|---|---|
| **„Chat ID”** | goală | grupul în care pleacă raportul. Cât e goală, nu pleacă niciun raport |
| **„Ora 1”**, **„Ora 2”** | 09:00 și 18:00 | orele raportului. „O oră lăsată goală e oprită.”; „Oprește”, de lângă ea, o golește |
| **„Amintiri la Amânate”** | pornite | „La primul raport al zilei, un mesaj cu amânările ajunse la termen.” |
| **„Adresa panoului”** | `https://lab.goldenpastry.ro` | „Pe ea se sprijină butonul din raport, care deschide pagina asta gata filtrată.” |

**Raportul de bani are chatul lui, altul decât cel al comenzilor.** În grupul
comenzilor stă toată tura, iar raportul poartă nume de datornici și sume.
Serverul refuză același chat din amândouă părțile: nici aici nu poți scrie
chatul comenzilor, nici în Setări → Notificări nu poți muta chatul comenzilor
peste cel al banilor (hotărârea patronului din 4–5 octombrie 2026). Cele două
ore nu pot fi nici ele aceeași: „Cele două rapoarte nu pot pleca la aceeași
oră. Lasă gol locul pe care nu-l vrei.”

Cardul se salvează cu butonul lui, „Salvează” — nu cu al altei pagini. La fel
cutia din Setări: are „Salvează” al ei, iar sub el scrie „Se salvează cu
butonul de aici, nu cu „Salvează” de sus.” Bara de sus a Setărilor nu salvează
raportul, iar „Salvează” al raportului nu salvează restul filei. Nici
„Renunță” de sus nu-l atinge: dacă ai scris și în cutie, întrebarea lui spune
la coadă „Cutia „Raport de bani pe Telegram” rămâne cum ai scris-o: are
„Salvează” al ei.” Când pleci din pagină, în schimb, se pierde și ce ai scris
în cutie: pagina te întreabă întâi, ca la orice text nesalvat. **Cum
desfaci:** scrii la loc ce era și salvezi; ca să oprești raportul cu totul,
golește „Chat ID” ori amândouă orele.

**Ce îi trebuie raportului ca să plece:** tokenul botului, salvat în cartonașul
„Telegram”, și chatul **lui**, scris la „Chat ID”-ul raportului. Întrerupătorul
„Pornit / Oprit” al cartonașului „Telegram” oprește numai anunțurile de
comenzi; raportul pleacă și cu el oprit. Cutia din Setări le spune pe
amândouă — „îi trebuie tokenul botului și chatul lui, scris mai jos la „Chat
ID”” (până la 2.7.5 numea numai tokenul). Fără chat, raportul nu pleacă, iar
proba e refuzată, cu pricina scrisă sub casetă:
„Raportul de bani nu are chat. Pune numărul chatului la „Raport pe Telegram” și
salvează.”

**Ce scrie în raport.** Patru lucruri, pe rânduri:

- „De încasat: 2.340,00 lei · 9 comenzi · 5 clienți”;
- „Amânate: 3 · 610,00 lei (1 depășită)”;
- „Încasat ieri: 1.120,00 lei (direct 700,00 · bancă 420,00)”;
- „Cei mai mari:”, cu clienții care au cel mai mult de plătit.

Sub mesaj stă butonul „Deschide Încasări”. Amintirea despre amânări e un mesaj
aparte, cu „Important — azi e termenul de plată” ori „Important — termene de
plată trecute” în cap și cu butonul „Vezi amânatele”.

**Proba.** „Trimite acum un raport de probă” trimite pe loc raportul, cu „Probă
— așa arată raportul care pleacă la orele alese.” pe primul rând. Salvează
întâi: pagina o spune sub buton — „Raportul de probă pleacă după ce e salvat pe
server, nu după ce scrie aici.” Reușita se vede pe loc: „Raportul de probă a
plecat. Uită-te în grupul de pe Telegram.” **„Ultimele trimiteri”**, dedesubt,
arată ce a plecat și ce nu, cu pricina; până la primul raport scrie „Încă nu a
plecat niciun raport.”

**Raportul îl trimite ceasul serverului**, nu pagina: pleacă și când nimeni nu
are aplicația deschisă. Dacă serverul a fost oprit la ora raportului, îl
trimite la pornire, dar numai dacă n-au trecut două ore; unul mai vechi nu mai
pleacă. Un raport pe care Telegram l-a refuzat nu se reîncearcă: îl vezi, cu
pricina, la „Ultimele trimiteri”. Unde stă ceasul și cum afli că merge scrie în
`docs/09-actualizare-vps.md`, la „Ceasul plăților”.

### Ce rămâne în Jurnal

Fiecare schimbare de stare lasă un rând în Jurnal, cu cine a apăsat și cu
trecerea — de pildă „Neplătită → Amânată până pe 2026-10-16”. **Fără sume**:
rândul spune cine și ce stare, atât. Nici nota nu se scrie, numai că s-a umblat
la ea („notă pusă”, „nota schimbată”, „nota ștearsă”), fiindcă notele poartă
adesea sume. La fel rămân schimbările din cardul raportului și ale pragului
general („Pragul general: 2 → 3”); numărul chatului nu se scrie nici vechi,
nici nou, numai că a fost pus, schimbat ori șters.

Rândurile acestea le vede numai cine are, pe lângă „Jurnal”, și „Încasări”. Un
administrator cu Jurnalul, dar fără „Încasări”, nu le găsește. Trecerea peste
prag rămâne în schimb la vedere pentru oricine are Jurnalul: e o faptă a casei
care trebuie să poată fi întrebată, nu o cifră.

### Pe un server fără migrarea 032

Pagina se deschide și spune „Încasările nu sunt pornite încă.”, cu numele
fișierului de rulat, `sql/032_plati_si_prag.sql`. Până atunci nu se poate bifa
nicio plată, clienții nu văd sold și nu sunt opriți la niciun prag, iar
comenzile merg ca înainte.

---

## Ștergerea definitivă a unei comenzi anulate

Până la 2.7.0 o comandă nu se putea șterge: se anula, și rămânea în registru,
marcată „Anulată”. Rămâne și acum drumul obișnuit. De la 2.7.0 (hotărârea
patronului din 4 octombrie 2026) o comandă **deja anulată** poate fi însă
scoasă cu totul — de pildă una scrisă din greșeală, de două ori.

**Cine.** Cine are fapta **„Șterge comenzile anulate”**, care ține de panoul
„Comenzi”: din pornire administratorul și fondatorul. Fondatorul o dă și o ia
din „Acces”. Celorlalți butonul nu li se desenează.

**De unde.** Din două locuri, amândouă în „Comenzi”:

- butonul din coada rândului unei comenzi anulate, care scrie **„Șterge”** (pe
  o comandă vie, același buton scrie „Anulează”);
- butonul **„Șterge definitiv”**, din josul formularului unei comenzi anulate,
  lângă „Reface comanda”. Pe telefon, de la 2.7.5, amândouă stau într-un rând
  al formularului, chiar deasupra barei de jos; bara ține acolo numai „Închide”
  și butonul de salvare, pe un singur rând.

**Sunt doi pași, dinadins.** Întâi anulezi comanda, apoi o ștergi: o comandă
vie nu se poate șterge de-a dreptul. La al doilea pas pagina întreabă „Ștergi
definitiv comanda #1003?” și spune ce se pierde — „3 produse, 340,00 lei.
Dispare din registru; în Jurnal rămâne ce era în ea.” —, cu butoanele
„Păstrează” și „Șterge definitiv”. Dacă formularul avea schimbări nesalvate,
adaugă „Schimbările din formular nu se mai salvează.” După ștergere scrie
„Comanda #1003 a fost ștearsă. Ce era în ea se vede în Jurnal.”

**Ce nu se șterge.** Serverul refuză, cu motivul:

- o comandă care nu e anulată: „Se șterg numai comenzile anulate.”;
- o comandă pentru care s-a plătit deja un comision de recomandare: „O comandă
  cu bani dați nu se șterge.”;
- o comandă trecută ca plătită: „Dacă banii s-au dat înapoi, trece-o întâi pe
  „Neplătită”, din Încasări, apoi șterge-o.”

**Ce rămâne.** În Jurnal, un rând „Comandă ștearsă”, cu cine a șters-o și cu
blocul „Ce era în comandă”: clientul, ziua, pozițiile, suma. Le găsești pe
toate cu filtrul „Comenzi șterse”. Rândul nu mai duce nicăieri, fiindcă nu mai
are la ce. Numărul comenzii se eliberează și poate fi luat de altă comandă, din
aceeași zi.

**Ștergerea NU se poate desface.** Nu există „Reface” pentru o comandă ștearsă.
Dacă ai șters-o din greșeală, o scrii din nou, cu mâna, după rândul din Jurnal
— va fi o comandă nouă, care pornește din prima etapă. De aceea, când nu ești
sigur, las-o anulată: o comandă anulată nu mai apare nici pe panoul din
laborator, nici pe ruta șoferului, iar „Reface comanda” o aduce înapoi.

Pe un server pe care n-a intrat încă `sql/035_comenzi_sterse.sql`, serverul
refuză ștergerea și numește fișierul; anularea merge ca înainte.

---

## Paza formularelor nesalvate

Runda 2.7.0 a pornit de la o pățanie a patronului: un produs nou, scris pe
jumătate, pierdut cu un singur clic pe fundalul întunecat de lângă formular. De
atunci, un formular în care ai scris ceva **nu se mai închide fără să
întrebe**.

**Unde e.** Pe zece pagini ale casei: Comenzi, Laborator, Depozit, Pontaj,
Catalog, Conturi, Recomandări, Încasări, Setări și Acces.

**Când întreabă.** La orice ieșire care ar pierde ce ai scris:

- din formular: ×, „Renunță”, clicul pe fundal, tasta Escape, butonul „Înapoi”
  al browserului;
- din pagină: o intrare din meniu, ieșirea din cont, închiderea filei (aici
  întreabă browserul, cu fereastra lui).

**Când nu întreabă.** Un formular în care n-ai schimbat nimic se închide pe
loc, ca până acum. „Neschimbat” se judecă după înțeles, nu după litere: „2,50”
scris peste „2.5” nu e o schimbare, nici o casetă umplută și golită la loc.
Paza nu se bagă nici peste butoanele care duc treaba la capăt — salvarea,
ștergerea, anularea unei comenzi: acolo ai hotărât deja, iar formularul se
închide fără încă o întrebare.

**Ce întreabă.** „Ieși fără să salvezi?”, cu „Ce ai scris aici nu e salvat și
se pierde dacă ieși.” și cu butoanele **„Rămân”** și **„Ies fără să salvez”**.
Cele mai multe formulare își spun întrebarea pe numele lor — „Ieși fără să
salvezi contul nou?” în Conturi, „Ieși fără să adaugi grupa?” în Catalog, „Ieși
fără să scrii ora de plecare?” în Pontaj, „Arunci schimbările nesalvate?” în
Setări și în Acces. „Rămân” e răspunsul din pornire: e butonul gata ales când
se deschide întrebarea, iar Escape ori un clic pe lângă ea înseamnă tot
„rămân”. Ca să pierzi ce ai scris trebuie deci să apeși anume butonul care o
spune.

**Dacă salvarea e pe drum.** Când ai apăsat „Salvează” și pleci înainte să vină
răspunsul, întrebarea e alta — în Comenzi, „Pleci înainte să afli dacă s-a
salvat comanda?”; în Setări, „Pleci înainte să afli dacă s-au salvat
setările?” —, fiindcă nici pagina nu știe încă dacă s-a scris.

**Dacă ți-a expirat sesiunea cu ceva scris pe ecran**, pagina nu te mai aruncă
la intrare: întreabă, o singură dată, „Pleci fără ce ai scris?” — „Nu mai ești
în cont, iar până nu intri din nou nu se poate salva nimic. Poți rămâne ca să
copiezi ce ai scris.” — cu „Rămân să copiez” și „Merg la intrare”.

**Ce nu face.** Paza nu salvează nimic în locul tău și nu ține ciorne: dacă
alegi „Ies fără să salvez”, ce ai scris s-a dus, fără drum înapoi. Nu e pe
„Comanda mea”, pe „Istoric”, pe „Rută”, pe „Parola mea” și nici în magazin,
unde coșul se ține minte singur.

---

## Barele de salvare, pe telefon

Plângerea patronului, la o comandă nouă scrisă de pe telefon: „Închide” și
„Salvează” luau prea mult din ecran. Aveau de ce. Fiecare buton stătea pe câte
un rând, iar bara lipită de jos ajungea, cu patru butoane, la 246 de pixeli —
pe un ecran din care tastatura ia și ea o bună parte. Runda 2.7.5 a schimbat
două lucruri, amândouă numai pe telefon.

**Bara de jos e un singur rând scund.** În formularul comenzii și în cel al
produsului, bara ține pe telefon numai butonul care închide și pe cel care
salvează, unul lângă altul; cel de salvare ia tot locul rămas. Butoanele rare
ale comenzii — „Anulează comanda”, „Reface comanda”, „Șterge definitiv” — nu
mai stau în bară: sunt în formular, pe un rând chiar deasupra ei, cu aceleași
vorbe. Pe calculator nu s-a schimbat nimic.

**Cât scrii, barele se desprind.** Cu tastatura telefonului deschisă, bara de
jos a celor două formulare și bara de sus din Setări și din „Acces” („Ai
modificări nesalvate”) nu mai stau lipite de marginea ecranului: rămân la locul
lor din pagină și se derulează odată cu ea, ca să nu acopere caseta în care
scrii. Închizi tastatura și se lipesc la loc. Tot cât scrii se sting bara cu „+
Intrare”, „− Ieșire” și „+ Articol” din Depozit, piciorul cu „Gata” al
ferestrei din care alegi produse pentru o comandă și bara coșului din magazin.
Nu s-a pierdut niciun buton: îl găsești derulând ori închizând tastatura.

**Ce a rămas.** Trei locuri sunt scrise ca fișe deschise în
`docs/06-defecte-de-reparat.md`: la telefoanele cele mai înguste (320 de
pixeli), bara cu „Schimbă parola” din Conturi și bara de sus din Setări își
țin butoanele tot unul sub altul; iar pe o tabletă îngustă, ținută în picioare,
caseta în care scrii în josul formularului comenzii poate rămâne sub tastatură
până derulezi cu mâna.

---

## Ce NU se configurează de aici

`api/config.php`, pe server, ține lucrurile care nu au ce căuta într-o pagină
web:

- datele de conectare la baza de date;
- cheia de criptare;
- cât ține o sesiune: `session_days_client`, `session_days_staff` și
  `session_days_panou`.

Tot acolo se poate scrie o cheie care **nu are voie să existe pe server**:
`telegram_api_base`. Mută pe altă adresă toate mesajele spre Telegram, și e
făcută numai pentru probele de pe calculatorul de lucru. Adresa aceea primește,
la fiecare mesaj, tokenul botului; de aceea stă în fișier, unde scrie numai
cine are deja serverul în mână, și nu într-o casetă din Setări. Pe site cheia
lipsește, iar mesajele pleacă la Telegram.

De la 2.7.5 mai e una de același fel, `smtp_ca_file`: un certificat de probă
pentru căsuța de e-mail, luat în seamă numai când căsuța e chiar pe calculatorul
de lucru. Pe site rămâne goală. Dacă nu e, pagina de verificare a serverului
o arată la rândul „Certificatul de probă al căsuței de e-mail”, cu „! de
rezolvat” — un lucru de golit, nu unul care oprește site-ul.

**Programul de poștă al serverului** — drumul direct al e-mailului — nu e nici
el o setare din panou. Îl pun `instaleaza-vps.sh` și `actualizeaza-vps.sh`,
dacă serverul n-are deja unul; cine nu-l vrea rulează scriptul cu
„--fara-posta”. Din panou alegi numai căsuța firmei, în cartonașul „E-mail”
(v. „E-mailul: căsuța firmei și drumul direct”, mai sus).

**Ceasul raportului de bani** nu e nici el o setare din panou. Orele le alegi
din „Încasări” ori din Setări → Notificări, dar cel care se uită la ceas e un
rând de pe server,
`/etc/cron.d/goldenpastry-plati`, care rulează din cinci în cinci minute
`api/cron/plati.php`. Îl scriu `instaleaza-vps.sh` și `actualizeaza-vps.sh`;
fără el nu pleacă niciun raport, orice ai salva în pagină. Dacă întorci
vreodată serverul la o versiune mai veche de 2.7.0, rândul trebuie șters cu
mâna (`rm -f /etc/cron.d/goldenpastry-plati`): arhiva veche se despachetează
peste dosar și nu șterge nimic, deci ceasul ar rula mai departe. Pe larg, în
`docs/09-actualizare-vps.md`, la „Ceasul plăților”.

Două lucruri **se** configurează din panou, dar nu din pagina Setări:

- **cine ce vede** — din „Acces”, pagina fondatorului (v.
  `docs/05-conturile-de-client.md`, la „Pagina „Acces””);
- **pragul unui singur client** — din Conturi, din fișa lui.

**Raportul de bani** era, până la 2.7.5, al treilea: se făcea numai din pagina
„Încasări”. Acum se face și de acolo, și din Setări → Notificări, cu aceleași
setări în amândouă locurile.

Restul — WhatsApp, e-mail, Telegram, liste, pragul general, codul tabletelor —
se face din Setări.
