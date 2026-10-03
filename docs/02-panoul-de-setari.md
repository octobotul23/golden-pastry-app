# Panoul de Setări

`lab.goldenpastry.ro/setari` — accesibil **doar** conturilor de administrator
(și fondatorului). Verificarea se face pe server, deci nu se poate ocoli din
browser.

Cine o deschide cu alt cont dă de un ecran de refuz cu două butoane, unul sub
altul. Primul, **„← Înapoi la panoul meu”**, îl duce la panoul de acasă al
rolului lui: managerul în Comenzi, agentul de vânzări în Recomandări, angajatul
și tableta din laborator în Laborator, omul de laborator în Pontaj, clientul în
„Comanda mea”. Al doilea e **„← Alege panoul”** — la client, **„← Înapoi la
magazin”**. Până la 2.6.0 primul buton era „Comenzi”, același pentru toți, iar
angajatul, omul de laborator, clientul și tableta dădeau acolo de al doilea
refuz la rând: niciunul n-are panoul Comenzi.

De aici configurezi aplicația fără să atingi vreun fișier. Tot ce salvezi aici
se aplică imediat, pe toate dispozitivele, și intră în jurnal (cine, ce, când).

---

## Notificare la comandă nouă

Trei canale independente. Poți ține pornite oricâte.

| Canal | Ce cere | Cât costă |
|---|---|---|
| **E-mail** | nimic, merge din prima zi | gratuit |
| **WhatsApp** | cont Meta Business, număr dedicat, șablon aprobat | per mesaj |
| **Telegram** | un bot creat în 5 minute | gratuit |

Recomandarea mea: pornește pe **e-mail** azi, adaugă **Telegram** dacă vrei
ceva instant pe telefon, și treci pe **WhatsApp** după ce Meta îți aprobă
numărul — aprobarea durează, iar până atunci n-ai vrea să rămâi fără anunțuri.

### WhatsApp — ce trebuie să faci tu

Eu am scris integrarea, dar **nu introduc credențiale**. Tokenul îl pui tu, o
singură dată, în panou.

1. Creează un cont pe <https://business.facebook.com> (Meta Business).
2. În **Meta for Developers** → aplicație nouă → produsul **WhatsApp**.
3. La **API Setup** găsești **Phone Number ID** și un **Access Token**.
4. Trimite spre aprobare un șablon („utility template") cu **un singur
   parametru în corp** — acolo intră rezumatul comenzii. Numele implicit pe
   care îl așteaptă aplicația este `comanda_noua`; dacă îl numești altfel,
   scrie numele tău în câmpul „Nume șablon".
5. Completează în panou: furnizor **Meta Cloud API**, numărul tău de WhatsApp
   (cu prefix de țară), Phone Number ID, tokenul, numele șablonului.
6. **Salvează**, apoi apasă **Trimite mesaj de test**.

Butonul de test folosește datele deja salvate, nu ce e pe ecran — de aceea
salvezi întâi. Dacă Meta refuză, eroarea lor apare ca atare în panou
(„template not found", „token expired") și îți spune exact ce lipsește.

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
- **Ștergerea se face explicit**, cu butonul „Șterge tokenul".

Dacă schimbi `encryption_key` din `config.php`, tokenurile deja salvate devin
ilizibile și trebuie reintroduse. Aplicația continuă să funcționeze, dar
notificările pe canalul respectiv se opresc până le pui la loc.

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
| „Acceptată de: … (administrator)” | cine a acceptat cererea din care s-a născut comanda; cu „, de pe Telegram” când s-a apăsat butonul de acolo. Lipsește când a acceptat-o chiar cel care a scris-o |
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

**Un caz rămas nereparat în 2.6.0.** Dacă ștergi contul unui om din casă care
mai are cereri nehotărâte, la acceptare cererile lui se anunță *„Trimisă de
client, de pe site”*: ștergerea golește pe cerere numele celui care a scris-o.
Până se repară, **oprește** contul (comutatorul „Cont pornit” din Conturi) în
loc să-l ștergi; un cont oprit își păstrează numele pe cereri.

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

---

## Casetele care stau închise

O casetă din **General → Date despre firmă** poate apărea închisă, cu un rând
colorat sub ea în locul ajutorului obișnuit. Înseamnă că pe server lipsește
rândul acelei setări: setările se scriu peste rânduri care există, iar rândul
îl aduce o migrare din `sql/` care n-a intrat încă. Rândul de sub casetă o
numește: `018_adresa_si_program.sql` pentru **Adresa publică** și **Program de
lucru**, `017_procent_recomandare.sql` pentru **Procent de recomandare**.

La fel pot sta închise cele patru casete din cardul **„Catalogul"**, tot din
fila General, cât lipsește `028_preturi_pe_trepte.sql` — v. capitolul
„Catalogul", mai jos.

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

În fila **General**, ultimele trei câmpuri: numele depozitului și coordonatele
lui. De aici pleacă mașina, iar din ele se calculează kilometrii de pe ruta
șoferului și ordinea opririlor la „Optimizează ruta".

Coordonatele implicite sunt ale depozitului real — incinta ICTCM, Șos.
Olteniței 103. Prototipul avea altceva scris în cod, „Str. Franceză 12", în
centru, la vreo cinci kilometri distanță: fiecare estimare de rută pornea din
alt cartier.

Dacă te muți, le iei din Google Maps: clic dreapta pe locul depozitului →
primul număr e latitudinea, al doilea longitudinea.

---

## Catalogul: cum se deschide magazinul, și de unde pornesc pragurile

Ultimul card din fila **General**, **„Catalogul"**, venit în 2.5.0 cu migrarea
028. Are patru casete:

| Caseta | La pornire | Ce hotărăște |
|---|---|---|
| Cum se deschide magazinul pe telefon și tabletă | Unul pe rând, cu toate detaliile | vederea cu care intră un client pe un ecran sub 1080 de puncte |
| Cum se deschide magazinul pe calculator | Patru pe ecran | la fel, de la 1080 de puncte în sus |
| Prețul B începe de la (buc.) | 100 | pragul cu care pornește un produs în Catalog |
| Prețul C începe de la (buc.) | 300 | la fel, pentru C |

**Vederile** sunt trei: *Unul pe rând, cu toate detaliile · Patru pe ecran ·
Șase pe ecran* — ce înseamnă fiecare, pe ce ecran, scrie în
`docs/04-catalogul-de-produse.md`, la „Cum se vede catalogul". Hotarul dintre
„telefon și tabletă" și „calculator" e același pe care îl folosește magazinul
pentru tot restul, 1080 de puncte, deci o tabletă ținută pe lat poate intra pe
vederea de calculator.

Clientul își poate schimba vederea din magazin, iar alegerea lui se ține minte
în browserul lui. **Până schimbi tu vederea de aici**: atunci schimbarea ajunge
la toată lumea, și la cine își alesese alta înainte. Altfel un client care a
apăsat o dată „Șase" n-ar mai vedea niciodată vederea aleasă de tine.

Setările nu ating vederile din panou: Catalogul și Conturile își țin fiecare
vederea lui, în browserul celui care lucrează.

**Pragurile** de aici nu schimbă niciun produs. Sunt doar valorile cu care se
precompletează rândul de praguri în formularul unui produs care n-are încă
praguri; fiecare produs își are apoi pragurile lui, iar ele se salvează numai
împreună cu un preț B ori C. Cum se aplică prețurile, în
`docs/04-catalogul-de-produse.md`, la „Trei prețuri, după cantitate".

Casetele își spun singure greșeala, înainte de „Salvează": un prag e un număr
întreg de bucăți, între 2 și 10000, iar al lui C trebuie să fie mai mare decât
al lui B (*„Trebuie să fie mai mare decât pragul lui B (100 buc.)."*). O casetă
goală e îngăduită și înseamnă „nu precompleta nimic". Serverul judecă la fel, și
refuză întreagă o salvare care ar lăsa pragul C sub B.

Pe o bază fără migrarea 028, cele patru casete stau închise, cu rândul care
numește migrarea (v. „Casetele care stau închise", mai sus). Până atunci
magazinul se deschide pe „Unul pe rând" pe telefon și pe „Patru pe ecran" pe
calculator.

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

**Cine poate pune etapa finală.** Administratorul și fondatorul, oricând. Ceilalți
— manager, agent de vânzări, angajat, contul tabletelor — numai dacă au bifa
**„Poate confirma livrarea”**, pusă pe contul lor din pagina **Conturi**.
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
pe rută, iar echipa primea „Comandă refăcută", deși nimeni n-a refăcut-o.

Acum fiecare apăsare spune serverului și **de pe ce etapă a plecat**. Serverul
compară etapa aceea cu cea din baza de date, sub lacăt — adică o a doua apăsare
venită în aceeași clipă din alt panou își așteaptă rândul, nu citește vechiul ca
să scrie peste — și hotărăște:

- se potrivesc: mutarea se face ca întotdeauna;
- nu se potrivesc: **nu se scrie nimic**. Nici etapa, nici rândul de jurnal,
  nici vreun anunț către echipă. Ecranul primește înapoi etapa de acum și scrie
  omului, în românește: „Comanda #1042 a fost schimbată între timp: acum e
  „Anulată”. N-am mutat-o." Apoi își recitește lista, ca să arate adevărul.

Nu e o piedică pentru mâna iute. Două apăsări una după alta pe același card —
„La dospit", apoi „În cuptor" — merg mai departe: a doua pleacă la server abia
după ce prima s-a scris, deci pornește de pe etapa adevărată. Iar dacă prima
n-a ajuns, a doua nu mai pleacă deloc: lista tocmai se recitește, și n-are rost
să se scrie peste ea o etapă pe care serverul n-a văzut-o niciodată.

O pagină veche, rămasă deschisă într-un browser de dinaintea actualizării, nu
trimite etapa de plecare. Pentru ea nu se schimbă nimic — merge ca până acum, și
se repară singură la prima reîncărcare.

### Ce ajunge pe ruta șoferului

La fel ca la etape, aplicația trebuie să știe **care moduri de onorare pun
mașina pe drum**. De asta există, sub „Moduri de onorare", a doua listă:
**Moduri fără mașină**. Tot ce nu e trecut acolo devine oprire pe ruta
șoferului.

Lista e scrisă pe dos dinadins. Un mod nou — să zicem „Curier" — intră singur
pe rută până când cineva spune explicit că nu are ce căuta acolo. O oprire în
plus se vede și se scoate; una lipsă nu se vede deloc, iar clientul rămâne
fără marfă.

Implicit sunt trecute acolo `Angro` și `Ridicare`, adică exact ce era înainte
scris în pagină: rută = doar „Livrare". Diferența e că acum poți redenumi
„Livrare" fără să golești ruta.

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
final a lui `api/setup.php`; de acolo încolo îl schimbi din fila **Acces**.

Se verifică **pe server** și se păstrează criptat, ca tokenurile. Sesiunea pe
care o deschide vede și avansează comenzi, dar nu ajunge la conturi, setări sau
jurnal.

**Minimum 6 caractere.** Nu e un capriciu: pragul de încercări greșite pentru
cod este larg — 20 în 15 minute — tocmai pentru că toate tabletele folosesc
același cod. Cu pragul strâns al conturilor obișnuite, opt greșeli de tastare
adunate într-o dimineață aglomerată ar fi închis panoul pentru toată echipa, cu
aluatul pe masă. Compensarea e lungimea codului: un cod scurt s-ar putea ghici
în pragul acela, unul de șase caractere nu.

Cele 20 de încercări se numără **pe fiecare conexiune în parte**, nu la un loc.
Numărate la un loc — cum s-ar părea firesc, de vreme ce codul e unul singur —
oricine de pe internet putea consuma pragul trimițând douăzeci de coduri
greșite către o adresă publică, iar bucătarul primea „Prea multe încercări" cu
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

## Ce NU se configurează de aici

`api/config.php`, pe server, ține două lucruri care nu au ce căuta într-o
pagină web:

- datele de conectare la baza de date;
- cheia de criptare.

Restul — WhatsApp, e-mail, Telegram, liste, cod — se face din panou.
