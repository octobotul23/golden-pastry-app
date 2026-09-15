# Panoul de Setări

`lab.goldenpastry.ro/setari` — accesibil **doar** conturilor de administrator.
Verificarea se face pe server, deci nu se poate ocoli din browser.

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

## Listele configurabile

Etapele din laborator, intervalele de livrare, zonele, modurile de onorare,
categoriile și unitățile din depozit — toate se editează din fila **Liste**.
Se folosesc în toate panourile.

Ordinea contează la **etape**: butonul „etapa următoare" din panoul
laboratorului merge exact pe ordinea din listă. Muți o etapă cu săgețile ↑ ↓.

### Două protecții

**Nu poți șterge o etapă în care se află comenzi.** Serverul refuză și îți
spune câte sunt: *„Etapa «În cuptor» nu poate fi ștearsă: 3 comenzi se află în
ea."* Altfel comenzile ar rămâne cu un status pe care nicio pagină nu îl
recunoaște — ar dispărea din panou, din filtre și de pe ruta șoferului, fără
niciun semn.

**O listă nu poate rămâne goală**, și nici nu poate conține text care nu e
listă validă.

### Etapele cu rol special

Poți redenumi orice etapă. Dar aplicația trebuie să știe **care etapă încheie
comanda** și **care o anulează** — de asta depind:

- pagina clientului (când scrie „Gata");
- ruta șoferului (comenzile anulate nu apar pe ea);
- panoul din laborator (comenzile anulate nu apar deloc);
- scăderea din depozit (se face la comenzile încheiate).

Le alegi din două liste derulante, în josul filei **Liste**. În lista de etape
sunt marcate cu `GATA` și `ANULARE`.

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
