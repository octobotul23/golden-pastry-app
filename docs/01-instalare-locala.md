# Etapa 0 — mediul local de test

Scopul: să putem construi și **verifica** backendul pe calculatorul tău,
înainte ca ceva să ajungă pe `goldenpastry.ro`. Fără asta am testa direct în
producție, pe o aplicație care ține comenzi reale.

Durează în jur de 10 minute și se face o singură dată.

---

## 1. Instalează Laragon

Descarcă **Laragon Full** de la <https://laragon.org/download/> (~150 MB) și
rulează installerul. Setările implicite sunt bune; reține doar folderul de
instalare, de obicei `C:\laragon`.

Laragon aduce împreună PHP, MariaDB (compatibil MySQL), Apache și phpMyAdmin.
Nu modifică variabilele de sistem și poate fi dezinstalat curat.

> **De ce Laragon și nu XAMPP:** pornește și se oprește cu un buton, creează
> singur domenii locale de forma `nume.test`, și nu lasă servicii pornite
> permanent în Windows.

După instalare, deschide Laragon și apasă **Start All**. Butonul devine verde
când Apache și MySQL rulează.

## 2. Verifică versiunea de PHP

În Laragon: **Menu → PHP → Version**. Alege **PHP 8.0 sau mai nou**.

Contează pentru că folosesc `match()` și `str_starts_with()`, disponibile de la
PHP 8.0. Dacă găzduirea ta are PHP 7.4, spune-mi — adaptez codul, dar e mai
bine să ceri upgrade la providerul de hosting: PHP 7.4 nu mai primește
actualizări de securitate din 2022.

## 3. Creează baza de date

Deschide **Menu → MySQL → phpMyAdmin** (utilizator `root`, parolă goală).

1. Apasă **New** în stânga.
2. Nume: `gp_dev`
3. Collation: `utf8mb4_unicode_ci` — obligatoriu pentru diacritice românești.
4. **Create**.

Apoi importă structura, în ordine:

1. Selectează baza `gp_dev` → tab **Import** → alege `sql/001_schema.sql` → **Go**
2. Din nou **Import** → alege `sql/002_seed.sql` → **Go**

A doua rulare adaugă cele 22 de produse reale și setările implicite.

`002_seed.sql` se poate rula ori de câte ori e nevoie: e numai `INSERT IGNORE`
— adaugă ce lipsește și lasă neatins ce există. **Nu** șterge comenzi, **nu**
rescrie produsele editate din pagina Produse și **nu** suprascrie setările pe
care le-ai configurat deja din panou (tokenul de WhatsApp, adresa de
notificare).

## 4. Pune proiectul unde îl vede Laragon

Laragon servește din `C:\laragon\www`. Cel mai simplu, fără să muți nimic, este
o legătură simbolică. Deschide **PowerShell ca administrator** și rulează:

```bash
New-Item -ItemType SymbolicLink -Path "C:\laragon\www\gp" -Target "D:\Lab App v2"
```

Apoi, în Laragon: **Menu → Apache → Reload**.

## 5. Configurarea — deja făcută

`api/config.php` este creat, cu o cheie de criptare de 64 de caractere generată
aleator. Fișierul **nu** se urcă în git și nu se trimite nimănui.

Dacă vreodată trebuie să generezi o cheie nouă:

```bash
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe -r "echo bin2hex(random_bytes(32));"
```

Atenție: schimbarea cheii face ilizibile tokenurile deja salvate din panoul de
Setări — trebuie reintroduse.

## 6. Pornirea serverului local

Nu ai nevoie de Apache: PHP are un server încorporat, iar `.claude/dev-server.php`
face rescrierea pe care în producție o face `.htaccess`.

```bash
C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe -S 127.0.0.1:8100 -t . .claude/dev-server.php
```

Apoi deschide <http://127.0.0.1:8100/> — vezi lista paginilor, iar
<http://127.0.0.1:8100/api/products> întoarce catalogul în format JSON.

Contul de administrator se creează la
<http://127.0.0.1:8100/api/setup.php>, o singură dată. Conturile demo din
prototip (`admin/goldenpastry`) **nu** sunt importate nicăieri, fiindcă
parolele lor erau vizibile în sursa paginilor.

## 7. Testele

Una singură le pornește pe toate:

```bash
node .claude/verifica-tot.mjs
```

Peste nouă sute de verificări, în două grupe. Întâi cele statice, care se uită
doar la fișiere — sintaxa paginilor, meniul lateral, pachetul de publicare,
probele de regresie ale panourilor. Apoi cele care cer serverul pornit pe 8100:
autentificare, drepturi pe rol, fluxul comenzilor de la cererea de pe site până
în laborator, parola uitată, setările, catalogul, depozitul. La final îți spune
câte au trecut și, dacă a picat ceva, exact ce.

Se rulează de câte ori vrei: fiecare suită își curăță singură urmele din
`gp_dev`, iar la sfârșit mai trece o dată prin bază și șterge ce a rămas.

Rulează-le după orice modificare. Verificările de drepturi sunt cele care
contează cel mai mult: ele confirmă că un client nu vede comenzile altcuiva, că
un angajat nu ajunge în Setări și că nu poate accepta sau respinge din contul
comun al tabletelor cererea unui client.

Fiecare suită merge și separat, când vrei doar una:

```bash
node .claude/test-api.mjs
```

Iar înainte de a urca pe server sunt utile două verificări statice, care nu cer
serverul pornit:

```bash
node .claude/check-php.mjs
```

```bash
node .claude/check-sql.mjs
```

---

## Dacă ceva nu merge

**„Lipsește api/config.php”** — pasul 5 nu a fost făcut, sau fișierul e în alt
folder decât `api/`.

**„Conexiune la baza de date eșuată”** — MySQL nu rulează (Start All în
Laragon), sau numele bazei din `config.php` nu este `gp_dev`.

**Pagină goală / eroare 500** — deschide `C:\laragon\bin\apache\...\logs\error.log`,
sau spune-mi ce scrie și mă uit eu.

**Diacriticele apar ca `?` sau `Ã¢`** — baza nu a fost creată cu
`utf8mb4_unicode_ci`. Șterge baza și reia pasul 3.

**Caracterele apar corect în phpMyAdmin dar greșit în pagină** — verifică să fi
importat `001_schema.sql` *înainte* de `002_seed.sql`.
