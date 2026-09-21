<?php
/**
 * Autentificare, sesiuni, roluri, CSRF și jurnalizare.
 *
 * Principiul de bază: rolul se verifică ÎNTOTDEAUNA aici, pe server. Interfața
 * poate ascunde butoane, dar asta nu este o măsură de securitate — în
 * prototipul de la care am pornit, verificarea se făcea în browser și oricine
 * putea să o ocolească.
 */

declare(strict_types=1);

const GP_COOKIE = 'gp_session';

/* ------------------------------------------------------------- sesiune -- */

/**
 * Contul pe numele căruia lucrează cererea de față, când nu vine dintr-un
 * browser cu cookie.
 *
 * EXISTĂ UN SINGUR FOLOSITOR, și e scris aici ca să nu apară al doilea fără să
 * se uite cineva la rândurile astea: `api/routes/telegram.php`, adică apăsarea
 * pe butonul „Acceptă" de sub anunțul primit pe telefon. Acolo nu există cookie
 * — cererea vine de la serverele Telegram — dar fapta trebuie să treacă prin
 * EXACT aceleași rute ca apăsarea din panou: aceleași validări de zi, interval,
 * zonă și mod de onorare, aceeași naștere de comandă, aceeași rezervare de
 * stoc, același comision, același rând în Jurnal.
 *
 * A doua variantă ar fi fost să se scrie încă o dată cele trei sute de rânduri
 * din `route_requests_accept()`, pe altă ușă. Atunci prima reparație făcută
 * într-una din ele ar fi rămas nefăcută în cealaltă, iar cererea acceptată de
 * pe telefon ar fi început, într-o zi, să se poarte altfel decât aceeași cerere
 * acceptată din panou — fără ca nimeni să bage de seamă, fiindcă amândouă merg.
 *
 * Contul împrumutat nu e o portiță: `api/routes/telegram.php` îl citește din
 * `notify_telegram_actor_id`, adică din omul care a legat botul cu mâna lui, îl
 * verifică să fie încă activ și administrator, și abia după ce a potrivit
 * secretul din antet cu cel din Setări. Ce se împrumută e IDENTITATEA, nu
 * dreptul: `require_admin()` se execută în continuare, pe contul acela.
 *
 * `$set` e separat de `$u` dinadins: fără el, `act_as(null)` din interiorul
 * unei citiri ar șterge împrumutul în loc să-l citească.
 */
function act_as(?array $u = null, bool $set = false): ?array
{
    static $actor = null;
    if ($set) {
        $actor = $u;
    }
    return $actor;
}

/** Utilizatorul curent, sau null dacă nu este autentificat. */
function current_user(): ?array
{
    // Împrumutul se citește ÎNAINTEA memoriei de mai jos, nu în locul ei:
    // `check_csrf()` cheamă funcția asta înaintea rutei și ar fi înghețat un
    // `null` pe care împrumutul nu l-ar mai fi putut desface.
    $imprumutat = act_as();
    if ($imprumutat !== null) {
        return $imprumutat;
    }

    static $user = false;
    if ($user !== false) {
        return $user;
    }

    $token = $_COOKIE[GP_COOKIE] ?? '';
    if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return $user = null;
    }

    $row = one(
        'SELECT s.id AS sid, s.csrf_token, s.expires_at,
                u.id, u.username, u.name, u.email, u.phone, u.address, u.role, u.customer_name,
                u.active, u.must_change_password, u.is_system
           FROM sessions s
           JOIN users u ON u.id = s.user_id
          WHERE s.id = ? AND s.expires_at > UTC_TIMESTAMP()',
        [token_hash($token)]
    );

    if (!$row || (int) $row['active'] !== 1) {
        return $user = null;
    }

    // Tableta din laborator nu se deconectează de la sine: cât e folosită,
    // sesiunea ei se împinge înainte. Stă aici, nu într-o rută anume, fiindcă
    // „e folosită" înseamnă orice cerere — și lista de comenzi cerută la
    // fiecare douăzeci de secunde, și un PATCH de etapă. `current_user()` ține
    // minte răspunsul pe cerere (`static $user`), deci se cheamă o singură dată.
    if ((int) ($row['is_system'] ?? 0) === 1) {
        prelungeste_sesiunea_panoului($row);
    }

    return $user = $row;
}

/**
 * Ruta cerută, normalizată ca în index.php.
 *
 * Nu se ia din routerul de acolo: `require_auth()` e chemată și din probe și
 * din scripturi, unde nu există niciun router. Se recalculează din cerere,
 * fără nicio variabilă globală. Când nu există cerere (linia de comandă),
 * iese '/' — adică o cale care NU e pe lista de mai jos, deci poarta se
 * închide, nu se deschide.
 */
function request_path(): string
{
    $p = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return '/' . trim((string) $p, '/');
}

/**
 * Contul umblă încă pe parola pusă de altcineva?
 *
 * `must_change_password` era pus de administrator la crearea contului și la
 * resetarea parolei, dar nu-l consulta nicio rută: singura piedică era o
 * redirecționare făcută în browser, pe pagina de intrare, iar pagina spre care
 * ducea avea pe ea linkul „← Înapoi la administrare". Un clic și omul lucra
 * mai departe cu parola dictată la telefon — cea pe care o mai știe cel puțin
 * o persoană. Steagul rămânea 1 la nesfârșit.
 *
 * De aici încolo serverul e cel care oprește, nu interfața. Rămâne deschisă o
 * singură rută, schimbarea parolei: fără ea contul ar fi închis definitiv,
 * fiindcă tocmai acolo trebuie să ajungă. Ieșirea din cont (/auth/logout) și
 * /auth/me nu trec pe aici — folosesc current_user() — deci omul se poate
 * oricând deconecta.
 *
 * Comparația se face pe coadă, nu pe egalitate: API-ul poate sta și într-un
 * subfolder (goldenpastry.ro/api/...), iar atunci calea are un prefix.
 *
 * Conturile de sistem sunt lăsate în afară — vezi mai jos de ce.
 */
function password_change_route(string $path): bool
{
    // A DOUA UȘĂ, „păstrez parola actuală", trebuie scrisă aici pe față.
    //
    // `str_ends_with($path, '/auth/password')` NU o prinde: calea ei se
    // termină în `/keep`. Fără rândul de mai jos, omul cu steagul pus ar fi
    // primit 403 `must_change_password` chiar de la butonul făcut pentru el —
    // singurul buton care i-l putea stinge. Poarta ar fi apărat ușa ei proprie.
    foreach (['/auth/password', '/auth/password/keep'] as $usa) {
        if ($path === $usa || str_ends_with($path, $usa)) {
            return true;
        }
    }
    return false;
}

function require_auth(): array
{
    $u = current_user();
    if (!$u) {
        fail('Trebuie să fii autentificat.', 401, 'unauthenticated');
    }
    // Contul „panou" al tabletelor nu are parolă proprie: se deschide cu codul
    // comun, iar hash-ul lui e o valoare aleatoare pe care n-o știe nimeni.
    // Dacă steagul ajunge cumva 1 pe el, nu s-ar mai putea scoate niciodată —
    // /auth/password cere parola curentă — și tot laboratorul ar rămâne fără
    // panouri. Conturile de sistem nu intră deci sub regula asta.
    $sistem = (int) ($u['is_system'] ?? 0) === 1;
    if (!$sistem && (int) ($u['must_change_password'] ?? 0) === 1 && !password_change_route(request_path())) {
        fail(
            'Trebuie să îți schimbi parola provizorie înainte de a folosi aplicația.',
            403,
            'must_change_password'
        );
    }
    return $u;
}

/** Cere unul dintre rolurile date, altfel 403. */
function require_role(string ...$roles): array
{
    $u = require_auth();
    if (!in_array($u['role'], $roles, true)) {
        fail('Nu ai dreptul să accesezi această resursă.', 403, 'forbidden');
    }
    return $u;
}

/**
 * Rolurile care înseamnă „personal", adică oricine lucrează în firmă.
 *
 * Ținute într-o constantă, nu scrise în require_staff(): lista a crescut o dată
 * și va mai crește, iar un rol nou trebuie adăugat într-un singur loc, nu
 * căutat prin cod. `client` NU e pe listă și nu are ce căuta pe ea.
 *
 * Atenție: „personal" nu înseamnă „administrator". Rutele care cer drepturi
 * depline — conturi, catalog, setări, jurnal, ordinea opririlor pe rută —
 * folosesc mai departe require_admin() și rămân închise pentru manager și
 * pentru agentul de vânzări, exact cum sunt pentru angajat.
 *
 * Iar de la 18 septembrie 2026 „personal" nu mai înseamnă nici „scrie
 * comenzi": nașterea unei comenzi — `POST /orders` și acceptarea unei cereri —
 * are lista ei, `GP_ORDER_CREATE_ROLES`, mai largă decât administratorii
 * (intră și managerul) și mai strâmtă decât personalul (ies agentul de vânzări
 * și angajatul). Ce le rămâne celor doi prin ușa asta e restul: lista de
 * comenzi, cotația, mutarea prin etape.
 *
 * Singura excepție e `fondator`, adăugat la coadă odată cu migrarea 009: el
 * e personal ȘI administrator (vezi GP_ADMIN_ROLES mai jos). Restul listei
 * a rămas exact cum era — nimic din ce era permis până acum nu s-a lărgit.
 */
const GP_STAFF_ROLES = ['admin', 'angajat', 'manager', 'agent_vanzari', 'fondator'];

/** Scurtătură pentru personalul intern. */
function require_staff(): array
{
    return require_role(...GP_STAFF_ROLES);
}

/**
 * Rolurile care ating marfa: depozitul, laboratorul, ruta.
 *
 * `GP_STAFF_ROLES` fără `agent_vanzari`. Agentul aduce clienți și își vede
 * comisionul; nu face intrări în depozit, nu raportează bucăți din laborator
 * și nu citește foaia de drum a șoferului. Meniul lui spune deja asta
 * (`shared/gp-api.js` · `PANOURI_ROL.agent_vanzari` = Comenzi, Recomandări,
 * Parola mea), dar serverul îi răspundea 200 pe toate — deci meniul era o
 * politețe, nu o poartă: cine cunoștea adresa intra oricum.
 *
 * De ce contează, dacă oricum nu are butonul: pe un panou lipsește butonul, pe
 * un API lipsește numai bunăvoința. Un cont de agent furat, sau agentul însuși
 * cu instrumentele browserului, putea scădea stocul firmei. Iar fără poarta
 * asta orice pagină nouă care se bizuie pe meniu ca pe o pază moștenește
 * golul, fără să-l vadă cineva.
 *
 * `client` nu e pe listă din același motiv pentru care nu e nici pe
 * `GP_STAFF_ROLES`.
 */
const GP_OPS_ROLES = ['admin', 'angajat', 'manager', 'fondator'];

/** Scurtătură pentru depozit, laborator și rută. Vezi GP_OPS_ROLES. */
function require_ops(): array
{
    return require_role(...GP_OPS_ROLES);
}

/**
 * Rolurile cu drepturi depline: conturi, catalog, setări, jurnal, recomandări.
 *
 * Fondatorul e administrator plus atât — „plus" fiind numai puterea asupra
 * administratorilor și a celorlalți fondatori (vezi api/routes/users.php).
 * Peste tot în rest, cele două roluri trebuie să treacă prin aceleași uși.
 *
 * De ce o constantă și o scurtătură, și nu `require_role('admin', 'fondator')`
 * scris de 31 de ori: exact motivul pentru care există GP_STAFF_ROLES deasupra.
 * Ușile astea se înmulțesc — fiecare rută nouă de administrator e încă una —
 * iar dacă lista se scrie la fiecare, atunci ruta scrisă mâine din obișnuință
 * cu `require_role('admin')` închide fondatorul pe dinafară fără ca cineva
 * să observe: pagina merge, dar numai pentru unii. Așa, un rol nou se adaugă
 * într-un singur loc. Proba din .claude/check-php.mjs păzește obiceiul.
 */
const GP_ADMIN_ROLES = ['admin', 'fondator'];

/** Scurtătură pentru drepturile depline. Vezi GP_ADMIN_ROLES. */
function require_admin(): array
{
    return require_role(...GP_ADMIN_ROLES);
}

/**
 * Cine conduce tura: raportul de pontaj și tabla de anunțuri din laborator.
 *
 * DOUĂ DREPTURI, O SINGURĂ LISTĂ, fiindcă sunt aceiași oameni. Lista s-a
 * născut pentru raportul de ore (migrarea 014) și a primit al doilea drept
 * odată cu tabla de anunțuri (migrarea 015): cine scrie pe ecranul
 * laboratorului „mâine vine făina la șase" e chiar cel care răspunde de tura
 * de mâine. Scrisă a doua oară, sub alt nume, lista s-ar fi depărtat de asta
 * la primul rol nou — și atunci un om ar fi citit orele fără să poată spune o
 * vorbă, sau invers, fără ca nimeni să fi hotărât asta.
 *
 * `GP_ADMIN_ROLES` plus managerul, și e singura listă din casă croită așa.
 * Managerul conduce tura: el știe cine a venit la cinci și cine la șapte, deci
 * raportul nu-i spune nimic ce n-a văzut cu ochii lui — îi spune doar adunat.
 * Restul panourilor de administrator (conturi, setări, jurnal, catalog) rămân
 * închise pentru el, neatinse.
 *
 * DE CE O LISTĂ NOUĂ ȘI NU `GP_OPS_ROLES`. Acolo e și `angajat` — adică
 * oamenii al căror pontaj e chiar ăsta. Un raport care le arată tuturor orele
 * tuturor transformă o unealtă de plată într-o listă de comparat între colegi,
 * și nimeni n-a cerut asta. Omul își vede propria venire pe tabletă, în clipa
 * în care pontează; atât îi trebuie ca să știe că s-a scris.
 *
 * A TREIA POARTĂ, mai strâmtă, nu e aici: corectura unui rând uitat deschis
 * cere `require_admin()` (api/routes/lab.php · `route_lab_shift_update()`).
 * Managerul citește statul de plată, nu îl scrie.
 */
const GP_SHIFTS_ROLES = ['admin', 'fondator', 'manager'];

/** Scurtătură pentru cine conduce tura. Vezi GP_SHIFTS_ROLES. */
function require_shifts(): array
{
    return require_role(...GP_SHIFTS_ROLES);
}

/**
 * Conduce omul ăsta tura? Întrebarea care NU oprește cererea.
 *
 * Perechea lui `e_admin()`, și din același motiv: `require_shifts()` e pentru
 * ușile închise — cine n-are voie primește 403 — iar asta e pentru butoanele
 * care nici nu se desenează. Panoul Laborator o întreabă o dată, la citirea
 * anunțurilor, și primește răspunsul ca `canPost`. Așa pagina nu ține o a doua
 * copie a listei de roluri, care s-ar depărta de asta în tăcere, iar brutarul
 * din fața tabletei nu vede un buton care i-ar răspunde „n-ai voie".
 */
function leads_shifts($cine): bool
{
    $rol = is_array($cine) ? (string) ($cine['role'] ?? '') : (string) $cine;
    return in_array($rol, GP_SHIFTS_ROLES, true);
}

/**
 * Cine poate scrie o comandă DIRECT în sistem, fără ca altcineva s-o
 * încuviințeze.
 *
 * HOTĂRÂREA PATRONULUI, 18 septembrie 2026: „comenzile nu apar direct în
 * sistem decât când sunt adăugate de admin/manager/fondator — restul apar ca
 * și cereri pentru ei". Până azi ușa era `require_staff()`, adică toți cei
 * din `GP_STAFF_ROLES`: agentul de vânzări scria o comandă care intra pe loc
 * în laborator, fără ca cineva s-o fi încuviințat — iar `angajat` putea face
 * același lucru prin API, deși meniul lui nici n-are Comenzi (`PANOURI_ROL`,
 * shared/gp-api.js). Așa se închide și gaura aceea, care nu se vedea de
 * nicăieri din aplicație.
 *
 * ACEIAȘI OAMENI CA `GP_SHIFTS_ROLES`, ȘI TOTUȘI ALTĂ LISTĂ. Azi cele două au
 * exact aceleași trei roluri, deci un alias ar fi mers — și ar fi fost o
 * capcană. Una răspunde la „cine citește statul de plată", cealaltă la „cine
 * răspunde de ce intră în laborator": două întrebări deosebite, care se despart
 * la primul rol nou. Un „șef de tură" care numără orele fără să comande, sau un
 * „dispecer" care scrie comenzi fără să vadă salariile colegilor — cu o listă
 * împărțită, oricare din cei doi ar fi căpătat dintr-o mișcare și dreptul
 * celălalt, fără ca nimeni să fi cerut asta. Două liste care se nimeresc la fel
 * nu sunt aceeași listă.
 *
 * CE NU ÎNCHIDE, spus pe față: numai NAȘTEREA comenzii. Mutarea prin stadii,
 * cotația și editarea rămân pe `require_staff()`, fiindcă omul din laborator
 * trebuie să poată duce mai departe o comandă pe care n-avea voie s-o scrie.
 * Poarta stă pe fapta de a crea, nu pe registru.
 *
 * CELE DOUĂ UȘI PE CARE SE NAȘTE O COMANDĂ sunt `route_orders_create()` și
 * `route_requests_accept()` — atât, iar `.claude/test-comanda-peste-tot.mjs` le
 * numără tocmai ca să rămână atât. Amândouă trec de-acum prin lista asta, deci
 * „cererile pot fi aprobate doar de admin/manager/fondator" și „comanda se
 * scrie doar de ei" sunt o singură regulă, scrisă o singură dată.
 */
const GP_ORDER_CREATE_ROLES = ['admin', 'fondator', 'manager'];

/** Scurtătură pentru cine scrie comenzi de-a dreptul. Vezi GP_ORDER_CREATE_ROLES. */
function require_order_create(): array
{
    return require_role(...GP_ORDER_CREATE_ROLES);
}

/**
 * Scrie omul ăsta comenzi de-a dreptul? Întrebarea care NU oprește cererea.
 *
 * Perechea lui `require_order_create()`, cum e `e_admin()` pentru
 * `require_admin()`. Formularul de comandă rămâne ACELAȘI pentru toți cei care
 * îl pot deschide; se schimbă numai ușa pe care pleacă la salvare: `POST
 * /orders` pentru cine e în listă, `POST /requests` pentru ceilalți. O
 * întrebare, nu o poartă, fiindcă agentul de vânzări n-a greșit cu nimic —
 * cererea lui e drumul cuvenit, nu un refuz, și n-are de ce să afle asta
 * dintr-un 403 după ce a scris toată comanda.
 *
 * Oglinda ei din pagină e `gp.eScrieComenzi()` (shared/gp-api.js).
 */
function writes_orders($cine): bool
{
    $rol = is_array($cine) ? (string) ($cine['role'] ?? '') : (string) $cine;
    return in_array($rol, GP_ORDER_CREATE_ROLES, true);
}

/**
 * Cine poate sta în fața tabletei de pontaj: `GP_OPS_ROLES` plus omul de
 * laborator.
 *
 * DE CE O A PATRA LISTĂ, și nu `GP_OPS_ROLES` lărgită. Rolul
 * `utilizator_lab` e al omului care PONTEAZĂ, atât. Băgat în `GP_OPS_ROLES`,
 * ar fi căpătat odată cu tableta și depozitul, și laboratorul, și foaia de drum
 * a șoferului — trei uși pe care nimeni n-a cerut să i le deschidă. Așa capătă
 * exact una.
 *
 * DE CE SE SPRIJINĂ PE `GP_OPS_ROLES` ÎN LOC SĂ-ȘI SCRIE NUMELE. Cine stă la
 * tabletele din laborator e aceeași lume cu cine deschide Laboratorul, Ruta și
 * Depozitul — sunt aceleași tablete, pe aceleași mese. Scrisă din nou, lista ar
 * fi rămas în urmă la primul rol nou.
 *
 * CE NU FACE POARTA ASTA, spus pe față: nu se uită de UNDE vine cererea.
 * Aplicația n-are nicăieri o îngrădire după loc, iar pontajul n-o aduce el cel
 * dintâi. Cine are cont și cod își poate ponta venirea și de acasă, de pe
 * telefon. Ce oprește poarta e ALTCEVA, și e lucrul cerut: nimeni nu poate
 * ponta ÎN LOCUL ALTUIA, fiindcă la fiecare apăsare se cere codul personal.
 */
const GP_CLOCK_ROLES = [...GP_OPS_ROLES, 'utilizator_lab'];

/** Scurtătură pentru tableta de pontaj. Vezi GP_CLOCK_ROLES. */
function require_clock(): array
{
    return require_role(...GP_CLOCK_ROLES);
}

/**
 * Cine vede cifrele casei: NUMAI fondatorul.
 *
 * Singura listă din casă mai strâmtă decât `GP_ADMIN_ROLES`, și singura în
 * care `admin` nu intră. Restul ușilor merg în cealaltă direcție — fondatorul
 * e administrator PLUS ceva — iar aici e pe dos, dinadins: administratorul
 * conduce patiseria zi de zi, fondatorul e cel ale cărui sunt banii. Cât a
 * încasat firma într-o lună, cine sunt clienții care aduc cel mai mult și cât
 * scoate fiecare produs nu sunt cifre de care are nevoie cineva ca să-și facă
 * treaba; sunt cifrele proprietarului. Hotărârea e a patronului, scrisă aici
 * ca s-o poată schimba dintr-un singur loc.
 *
 * DE CE O LISTĂ, DACĂ E UN SINGUR ROL ȘI O SINGURĂ UȘĂ. Din același motiv ca
 * `GP_ADMIN_ROLES`: ziua în care patronul spune „să vadă și contabilul" e o
 * schimbare de un cuvânt aici, nu o căutare prin rute. Iar oglinda de pe
 * pagină — `PANOURI_ROL` din shared/gp-api.js — are, așa, un nume la care să
 * trimită, nu un rol scris din nou.
 *
 * `require_role('fondator')` scris de mână NU e semnalat de .claude/check-php.mjs
 * (vezi explicația de acolo: regula cere ca `admin` să fie în listă). Deci paza
 * obiceiului ăstuia nu vine de la ea, ci de la proba din .claude/test-statistici.mjs,
 * care cere ca fiecare din celelalte roluri să ia 403 pe ușa asta.
 */
const GP_STATS_ROLES = ['fondator'];

/** Scurtătură pentru cifrele casei. Vezi GP_STATS_ROLES. */
function require_stats(): array
{
    return require_role(...GP_STATS_ROLES);
}

/**
 * Cui îi sunt deschise comenzile, fie și o singură felie din ele.
 *
 * A CINCEA LISTĂ, și prima care cuprinde și clientul. Comenzile nu sunt un
 * panou, sunt un registru pe care fiecare îl vede altfel: clientul pe ale lui,
 * agentul pe ale cumpărătorilor aduși de el, personalul de operațiuni pe toate.
 * Cât cuprinde fiecare se hotărăște în `orders_visibility_filter()`
 * (api/routes/orders.php); lista de aici răspunde la întrebarea dinainte —
 * „are omul ăsta ce căuta în registru, oricât de puțin?".
 *
 * DE CE E NEVOIE DE ÎNTREBAREA ASTA SEPARAT. Filtrul avea până acum două
 * îngustări și o cădere: clientul, agentul, iar restul — tot. Cât timp „restul"
 * era personalul, regula era dreaptă și nimeni n-avea ce scrie. Rolul
 * `utilizator_lab` din migrarea 014 nu e niciunul din cele trei, și a moștenit
 * în tăcere registrul întreg: nume, telefoane, adrese și sume, pe o sesiune
 * deschisă de pe tableta din laborator. Nimeni n-a scris asta; a picat din
 * formă. Numită pe față, forma nu mai poate face daruri — rolul de mâine nu
 * vede nimic până nu-l trece cineva aici.
 *
 * DE CE STĂ ÎN `lib/auth.php` ȘI NU ÎN `routes/orders.php`. Router-ul încarcă
 * un singur fișier de rute pe cerere (api/index.php), iar marginea asta o cere
 * și `/requests` — o cerere are tot nume, telefon și adresă.
 */
const GP_ORDERS_ROLES = [...GP_OPS_ROLES, 'client', 'agent_vanzari'];

/** Îi e deschis registrul de comenzi? Vezi GP_ORDERS_ROLES. */
function orders_readable($cine): bool
{
    $rol = is_array($cine) ? (string) ($cine['role'] ?? '') : (string) $cine;
    return in_array($rol, GP_ORDERS_ROLES, true);
}

/**
 * Are contul drepturi depline? Întrebarea care NU oprește cererea.
 *
 * `require_admin()` e pentru ușile închise: cine n-are voie primește 403 și
 * gata. Dar sunt și locuri unde răspunsul doar se îngustează — `?all=1` din
 * catalog, unde pentru restul lumii înseamnă „fără produsele scoase", nu un
 * refuz. Acolo trebuia o întrebare, nu o poartă, și fiindcă nu exista, cele
 * trei locuri au ajuns să scrie `$u['role'] === 'admin'` de mână — adică
 * fix greșeala pe care GP_ADMIN_ROLES o previne: fondatorul rămânea afară.
 *
 * Oglinda lui `gp.eAdmin()` din shared/gp-api.js, până și la ce primește:
 * rolul singur sau rândul întreg, ca apelantul să nu scrie de fiecare dată
 * același `['role']`.
 */
function e_admin($cine): bool
{
    $rol = is_array($cine) ? (string) ($cine['role'] ?? '') : (string) $cine;
    return in_array($rol, GP_ADMIN_ROLES, true);
}

/**
 * Are baza rolul de fondator și tabelul invitațiilor? (migrarea 009)
 *
 * Aceeași poartă ca `referral_supported()`, și din același motiv: există
 * ferestre în care codul de pe server e mai nou decât baza lui. Actualizarea
 * cuminte nu le face — `actualizeaza-vps.sh` importă migrările ÎNTÂI și urcă
 * fișierele pe urmă (docs/07-actualizari.md, „Ordinea: baza întâi, apoi
 * fișierele") — dar trei drumuri le fac, și toate trei țin mai mult decât o
 * actualizare: `--doar-fisiere`, un import oprit la mijloc, și plasturele pus
 * de mână. În fereastra aceea, butonul „Cere drept de fondator" trebuie să
 * lipsească din pagină, nu să dea peste un „1265 Data truncated" pe care omul
 * îl citește ca „Eroare internă.".
 */
function founder_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $rol = in_array('fondator', roles_available(['fondator']), true);
        $tabel = (int) scalar(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'founder_invites'"
        );
        return $ok = ($rol && $tabel === 1);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica suportul de fondator: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Există măcar un fondator activ?
 *
 * Nu e o curiozitate, e comutatorul care ține instalările vechi în picioare.
 * Regula „numai un fondator umblă la rolurile de conducere" e bună — dar
 * aplicată din prima clipă a migrării, pe o bază fără niciun fondator, ea
 * închide o ușă care azi e deschisă: doi administratori, iar unul nu-l mai
 * poate retrograda pe celălalt, fiindcă nu există nimeni care să aibă voie.
 * Ieșirea ar fi un rând de SQL scris de mână — adică exact felul de blocare
 * tăcută de care se ferește tot codul ăsta.
 *
 * Deci: cât timp nu există niciun fondator, administratorii păstrează fix
 * puterile de azi. Nu se dă nimic pe gratis — singura putere în plus a
 * fondatorului e cea asupra administratorilor, iar când fondatori nu există,
 * administratorul o are oricum. În clipa în care apare primul, ușa se închide
 * și rămâne închisă.
 *
 * ATENȚIE: portița NU se întinde și asupra ACORDĂRII rolului de fondator.
 * Aceea cere mereu un fondator sau cutia poștală a firmei (api/routes/founder.php).
 * Altfel primul administrator venit și-ar da singur rolul de deasupra lui, iar
 * poarta gândită anume ca să nu stea în pagina Conturi ar fi ocolită din chiar
 * pagina Conturi.
 *
 * Nu se ține în `static`: spre deosebire de schemă, răspunsul se schimbă
 * chiar în timpul cererii care ridică primul fondator.
 */
function founder_exists(): bool
{
    if (!founder_supported()) {
        return false;
    }
    try {
        return (int) scalar("SELECT COUNT(*) FROM users WHERE role = 'fondator' AND active = 1") > 0;
    } catch (Throwable $e) {
        error_log('[gp] nu pot număra fondatorii: ' . $e->getMessage());
        return false;
    }
}

/**
 * Rolurile pe care le acceptă CHIAR baza de date, acum.
 *
 * `ROLES` din api/routes/users.php spune ce știe codul; ENUM-ul coloanei
 * `users.role` spune ce încape în rând. Cele două se despart într-o fereastră
 * reală: `--doar-fisiere`, un import oprit la mijloc, ori plasturele pus de
 * mână lasă pe server cod mai nou decât baza. În fereastra aceea, un rol nou
 * trecea de verificarea din PHP și
 * se lovea de MySQL cu „1265 Data truncated for column 'role'" — adică
 * „Eroare internă." în fața administratorului, fără nicio vorbă despre migrarea
 * neimportată.
 *
 * Citim lista adevărată o dată pe cerere și o intersectăm cu ce știe codul.
 * Dacă interogarea nu merge, ne întoarcem la lista din cod: o poartă care se
 * închide singură ar fi mai rea decât eroarea pe care o evităm.
 *
 * @param string[] $cunoscute rolurile pe care le știe codul
 * @return string[]
 */
function roles_available(array $cunoscute): array
{
    static $inBaza = null;
    if ($inBaza === null) {
        try {
            $tip = (string) scalar(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                    AND COLUMN_NAME = 'role'"
            );
            preg_match_all("/'([^']+)'/", $tip, $m);
            $inBaza = $m[1] ?: [];
        } catch (Throwable $e) {
            error_log('[gp] nu pot citi rolurile din schemă: ' . $e->getMessage());
            $inBaza = [];
        }
    }
    // Lista goală înseamnă „n-am putut afla", nu „niciun rol permis".
    return $inBaza ? array_values(array_intersect($cunoscute, $inBaza)) : $cunoscute;
}

/* ---------------------------------------------------------------- CSRF -- */

/**
 * Verifică tokenul CSRF pentru orice operație care modifică date.
 * Cookie-ul de sesiune este trimis automat de browser; tokenul acesta nu —
 * el trebuie citit din răspunsul de login și pus în antetul X-CSRF-Token,
 * ceea ce un site străin nu poate face.
 */
/**
 * Rutele care intră sau ies din sesiune.
 *
 * Ele NU pot cere token CSRF. Tokenul se obține din răspunsul autentificării
 * și se ține în sessionStorage, care dispare la închiderea filei — în timp ce
 * cookie-ul de sesiune ține 14 zile. Rezultă o combinație perfect obișnuită
 * (cookie valid, token pierdut) în care utilizatorul era considerat
 * autentificat, deci i se cerea tokenul, dar nu îl mai avea și nici nu îl mai
 * putea obține: nu se mai putea nici autentifica, nici deconecta. Pe un
 * calculator din laborator, folosit pe rând de mai mulți oameni, asta
 * însemna blocare completă.
 *
 * Ce pierdem: protecția împotriva „login CSRF" — cineva te poate forța să
 * intri în contul LUI. Cere ca atacatorul să aibă deja un cont valid aici,
 * iar paguba e că lucrezi în sesiunea lui, nu că ajunge el în a ta. Față de
 * blocarea reală de mai sus, schimbul merită.
 *
 * `/auth/register` intră pe aceeași listă din motivul cel mai simplu cu putință:
 * cine își face cont NU are încă sesiune, deci nu are de unde lua un token.
 * Ruta nu e totuși descoperită: are prag propriu pe conexiune
 * (register_rate_limited) și scrie rolul fix, nu-l citește din cerere.
 *
 * `/founder/accept` intră pe listă din aceeași pricină, într-o formă care se
 * vede ușor: linkul din e-mail se deschide într-o filă NOUĂ, pornită din
 * clientul de mail. `sessionStorage` e al filei, nu al browserului, deci fila
 * aceea n-are tokenul — dar cookie-ul de sesiune, care ține paisprezece zile,
 * e trimis. Rezultatul, fără scutire, ar fi tocmai omul care ARE cont și e
 * conectat oprit cu „Token CSRF lipsă”, în timp ce un vizitator nelegat de
 * nimeni trece: check_csrf() nu cere nimic celor fără sesiune.
 *
 * Nu se pierde nimic: pe ruta asta sesiunea nu înseamnă nimic. Autoritatea e
 * tokenul din adresă, pe care un site străin nu-l are — dacă l-ar avea, ar
 * putea folosi linkul direct, fără browserul tău. Contul ridicat e cel scris
 * în rândul invitației, niciodată cel din cookie.
 */
function csrf_exempt(string $path): bool
{
    return in_array($path, ['/auth/login', '/auth/logout', '/auth/panel', '/auth/register',
        '/founder/accept',
        // Apăsarea pe un buton de sub anunțul de pe Telegram. Cererea vine de
        // la serverele Telegram, deci nu are de unde avea token — și nici nu
        // are nevoie: în locul lui stă secretul din antetul
        // `X-Telegram-Bot-Api-Secret-Token`, potrivit cu `hash_equals` în
        // api/routes/telegram.php.
        //
        // Scutirea e brâu peste bretele, nu o poartă deschisă: `check_csrf()`
        // se întoarce oricum mai jos când nu există sesiune, iar aici nu există
        // niciodată. E scrisă totuși, ca purtarea rutei să nu atârne de o
        // ramură aflată în altă funcție — dacă mâine `check_csrf()` ar începe
        // să refuze și cererile fără sesiune, webhookul ar fi murit în tăcere,
        // iar butoanele ar fi încetat să lucreze fără ca nimeni să lege una de
        // alta.
        '/telegram/webhook'], true);
}

function check_csrf(string $path = ''): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if ($path !== '' && csrf_exempt($path)) {
        return;
    }
    $u = current_user();
    if (!$u) {
        return;   // cererile neautentificate sunt oprite oricum de require_auth
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($sent === '' || !hash_equals((string) $u['csrf_token'], $sent)) {
        fail('Token CSRF lipsă sau invalid.', 403, 'bad_csrf');
    }
}

/* ------------------------------------------------------- autentificare -- */

/**
 * Prea multe încercări eșuate?
 *
 * Se numără pe DOUĂ niveluri, deliberat:
 *
 *  - pe utilizator: cineva care ghicește parola unui cont anume este oprit
 *    repede, indiferent de unde încearcă;
 *  - pe IP: prag mult mai larg, ca să prindă un atac automat.
 *
 * Motivul separării: într-o patiserie toate tabletele și calculatoarele ies
 * la internet prin aceeași adresă IP. Cu o singură limită pe IP, un angajat
 * care își greșește parola de câteva ori ar bloca tot personalul.
 */
function login_rate_limited(string $ip, string $username = '', ?int $maxPerUser = null): bool
{
    $window = (int) config('login_window_min');

    if ($username !== '') {
        // Pe utilizator ȘI pe conexiune. Numărat pe utilizator indiferent de
        // IP, pragul era o armă: oricine știa numele contului de administrator
        // îl închidea de oriunde cu opt parole greșite la fiecare sfert de oră
        // — la nesfârșit, fără să știe parola. Acum greșelile unui străin îl
        // opresc doar pe străin; ghicirea de pe multe adrese o oprește pragul
        // pe IP de mai jos și costul hash-ului, nu blocarea contului.
        $perUser = (int) scalar(
            'SELECT COUNT(*) FROM login_attempts
              WHERE username = ? AND ip = ? AND ok = 0 AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
            [$username, $ip, $window]
        );
        if ($perUser >= ($maxPerUser ?? (int) config('max_login_attempts'))) {
            return true;
        }
    }

    $perIp = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND ok = 0 AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$ip, $window]
    );
    return $perIp >= (int) config('max_login_attempts_ip');
}

/**
 * Contul e blocat pentru ACEASTĂ conexiune?
 *
 * Blocat înseamnă: cel puțin `lock_account_after` greșeli de pe același IP
 * în ultimele `lock_window_min` minute, iar ultima dintre ele mai nouă de
 * `lock_minutes`. Fereastra e mai lungă decât cea a limitării (15 minute),
 * altfel blocarea n-ar apuca să se declanșeze niciodată — limitarea răspunde
 * 429 la a opta greșeală dintr-un sfert de oră, înainte de a zecea. Rostul ei
 * e ghicirea răbdătoare, întinsă peste mai multe ferestre, de pe aceeași
 * adresă. O intrare reușită șterge greșelile (record_attempt), deci și
 * blocarea; la fel resetarea parolei (clear_attempts).
 */
function login_locked(string $ip, string $username): bool
{
    $window = (int) config('lock_window_min', 60);
    $ultima = scalar(
        'SELECT MAX(created_at) FROM login_attempts
          WHERE username = ? AND ip = ? AND ok = 0
            AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$username, $ip, $window]
    );
    if ($ultima === null) {
        return false;
    }
    $n = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts
          WHERE username = ? AND ip = ? AND ok = 0
            AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$username, $ip, $window]
    );
    return $n >= (int) config('lock_account_after')
        && strtotime($ultima . ' UTC') + 60 * (int) config('lock_minutes') > time();
}

/** Pseudo-utilizatorul sub care se numără cererile de parolă nouă. */
const GP_FORGOT_KEY = 'recuperare';

/**
 * Prea multe cereri de parolă nouă de pe ACEEAȘI conexiune?
 *
 * Cererile de recuperare se înregistrau toate sub același pseudo-utilizator,
 * iar pragul se citea din ramura „pe utilizator" a limitării de mai sus — care
 * nu se uită deloc la IP. Zece cereri, de oriunde din lume, opreau recuperarea
 * parolei pentru toți clienții și toți angajații, un sfert de oră la rând.
 * Mesajul afișat spunea totuși „de pe această conexiune".
 *
 * Aici se numără pe IP ȘI pe pseudo-utilizator, deci pragul e chiar cel promis
 * de mesaj, iar rândurile ies din socoteală singure, la capătul ferestrei.
 */
function forgot_rate_limited(string $ip, int $max): bool
{
    $window = (int) config('login_window_min');
    $n = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND username = ? AND ok = 0
            AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$ip, GP_FORGOT_KEY, $window]
    );
    return $n >= $max;
}

/** Pseudo-utilizatorul sub care se numără înscrierile de pe site. */
const GP_REGISTER_KEY = 'inscriere';

/**
 * Prea multe conturi făcute de pe ACEEAȘI conexiune?
 *
 * `/auth/register` e singura rută care scrie în `users` fără să ceară o sesiune.
 * Fără prag, un script poate umple tabelul peste noapte cu mii de conturi — și,
 * cu ele, jurnalul, lista din pagina Conturi și codurile de recomandare.
 *
 * Fereastra are cheie proprie — `register_window_min`, 60 de minute implicit —
 * nu cea de 15 minute a autentificării: o înscriere nu e o încercare de parolă
 * și pragul cerut e „zece pe oră". Numărătoarea e pe IP ȘI pe pseudo-utilizator,
 * ca înscrierile de la o conexiune să nu consume plafonul altcuiva — greșeala pe
 * care o făcea recuperarea parolei înainte de a fi reparată.
 *
 * Nu se cheamă niciodată record_attempt(..., true) cu cheia asta: o reușită ar
 * șterge, prin regula din record_attempt(), încercările TUTUROR conexiunilor.
 */
function register_rate_limited(string $ip, int $max): bool
{
    $window = (int) config('register_window_min', 60);
    $window = $window > 0 ? $window : 60;
    $n = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND username = ? AND ok = 0
            AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$ip, GP_REGISTER_KEY, $window]
    );
    return $n >= $max;
}

/** Pseudo-utilizatorul sub care se numără cererile de pe site fără cont. */
const GP_REQUEST_KEY = 'cerere';

/**
 * Prea multe cereri de comandă de pe ACEEAȘI conexiune, fără cont?
 *
 * `POST /requests` e ruta prin care vizitatorul de pe site comandă — publică,
 * fără parolă. Fiecare cerere scrie în bază, în jurnal și pornește anunțurile
 * pe e-mail, WhatsApp și Telegram. Fără prag, un script trimitea cinci sute de
 * cereri într-un minut: anunțuri fără oprire pe telefonul patiseriei, iar
 * cererile adevărate împinse afară din panou, care arată doar ultimele 500.
 *
 * Pragul e larg — implicit 20 pe oră — fiindcă o cerere nu e o încercare de
 * parolă: un client care își corectează comanda de trei ori nu trebuie oprit.
 *
 * CINE INTRĂ ÎN NUMĂRĂTOARE se hotărăște în `request_counts_against_ip()`, de
 * mai jos, și nu mai e „cine n-are cont". Aici a stat multă vreme scris că
 * „clienții cu cont nu trec pe aici: ei sunt identificați" — și nu era
 * adevărat: un cont se face singur, de pe site, în zece secunde. Deci poarta
 * avea o clanță pe dinafară.
 */
function request_rate_limited(string $ip, int $max): bool
{
    $window = (int) config('register_window_min', 60);
    $window = $window > 0 ? $window : 60;
    $n = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND username = ? AND ok = 0
            AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$ip, GP_REQUEST_KEY, $window]
    );
    return $n >= $max;
}

/**
 * Cererea asta intră în numărătoarea de pe conexiune?
 *
 * DE CE NU MAI E DE AJUNS „ARE CONT". Pragul de deasupra a fost scris când
 * singura cale de a trimite o cerere fără cont era formularul public. Între
 * timp site-ul are înscriere: oricine își face un cont în zece secunde, fără ca
 * nimeni din casă să fi recunoscut pe cineva — și de-atunci contul acela era
 * chiar clanța de pe dinafara porții. Un script care se înscrie o dată trimitea
 * pe urmă oricâte cereri: anunțuri fără oprire pe telefonul patiseriei, și
 * cererile adevărate împinse afară din panou.
 *
 * ÎNTREBAREA NU E NOUĂ, și de-aia nici răspunsul nu se scrie a doua oară:
 * `client_by_name()` (api/lib/orders.php) leagă o comandă de un cont numai dacă
 * acesta e `self_registered = 0 OR vouched_at IS NOT NULL` — adică o comandă
 * luată la telefon NU intră în contul unui cont făcut singur pe site și
 * nerecunoscut încă de casă. Programul socotea deci de mult că un asemenea cont
 * „nu e o identitate"; aici se poartă la fel.
 *
 * CINE RĂMÂNE NELIMITAT, și pe drept: personalul (e cunoscut cu numele lui, iar
 * o cerere scrisă de la tejghea pentru cineva care sună e lucru de toată ziua)
 * și clientul recunoscut de casă — cel făcut din Conturi, ori cel care s-a
 * înscris singur și a fost pe urmă legitimat.
 *
 * FĂRĂ MIGRAREA 010 cade DESCHIS, adică pe purtarea de până acum: coloanele nu
 * există, deci întrebarea n-are răspuns, iar dacă am număra pe toată lumea am
 * pune un prag peste clienții cinstiți ai casei în fereastra unei actualizări.
 * Aceeași alegere ca la `user_origin_supported()`, și din același motiv: o
 * poartă care se închide singură e mai rea decât paguba de care fuge.
 *
 * @param array|null $u contul care trimite, sau null pentru vizitator
 */
function request_counts_against_ip(?array $u): bool
{
    // Vizitatorul fără cont: cum a fost dintotdeauna.
    if (!$u) {
        return true;
    }
    if ((string) ($u['role'] ?? '') !== 'client') {
        return false;
    }
    if (!user_origin_supported()) {
        return false;
    }
    // Se citește din bază, nu din `$u`: `current_user()` are listă fixă de
    // coloane și e pe drumul FIECĂREI cereri, deci o coloană adăugată acolo ar
    // cădea cu 1054 pe o instalare nemigrată. Același tipar ca
    // `user_origin_public()` și `referral_public()`.
    $r = one('SELECT self_registered, vouched_at FROM users WHERE id = ?', [(int) ($u['id'] ?? 0)]);
    if (!$r) {
        // Contul a pierit între sesiune și clipa asta. Se numără: necunoscutul
        // se poartă ca un vizitator, nu ca un client al casei.
        return true;
    }
    return (int) $r['self_registered'] === 1 && $r['vouched_at'] === null;
}

/** Pseudo-utilizatorul sub care se numără deschiderile panoului cu cod. */
const GP_PANEL_KEY = 'panou';

/** Pseudo-utilizatorul sub care se numără cererile de drept de fondator. */
const GP_FOUNDER_KEY = 'fondator';

/**
 * Prea multe cereri de fondator de pe ACEEAȘI conexiune?
 *
 * Fiecare cerere trimite un e-mail către cutia firmei — o adresă pe care cel
 * care cere n-o alege și, de obicei, nici n-o citește. Fără prag, butonul ar fi
 * o unealtă de umplut cutia poștală a patiseriei, exact ca „am uitat parola"
 * înainte de pragul lui.
 *
 * Pragul e strâns dinadins — implicit trei pe oră: nimeni nu cere de trei ori
 * pe oră să fie făcut fondator. Numărat pe IP ȘI pe pseudo-utilizator, ca
 * încercările unei conexiuni să nu mănânce plafonul alteia.
 */
function founder_rate_limited(string $ip, int $max): bool
{
    $window = (int) config('register_window_min', 60);
    $window = $window > 0 ? $window : 60;
    $n = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND username = ? AND ok = 0
            AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$ip, GP_FOUNDER_KEY, $window]
    );
    return $n >= $max;
}

/**
 * Numele sub care se numără limitările, care NU pot fi nume de cont.
 *
 * `record_attempt(..., true)` șterge greșelile contului care tocmai a intrat.
 * Dacă cineva își făcea un cont chiar cu numele „inscriere" sau „recuperare",
 * fiecare intrare reușită a lui ștergea contoarele TUTUROR înscrierilor și
 * cererilor de parolă nouă de pe internet — și plafonul de zece pe oră
 * dispărea. Așa că numele astea sunt refuzate la crearea contului, iar
 * ștergerea de mai jos le ocolește oricum.
 */
function reserved_username(string $username): bool
{
    return in_array($username, [GP_FORGOT_KEY, GP_REGISTER_KEY, GP_REQUEST_KEY, GP_PANEL_KEY,
        GP_FOUNDER_KEY], true);
}

/**
 * Prea multe coduri greșite de panou de pe ACEEAȘI conexiune?
 *
 * Codul tabletelor e unul singur, deci toate greșelile se strâng sub același
 * pseudo-utilizator. Numărate global — cum le numără ramura „pe utilizator" a
 * limitării de mai sus — douăzeci de cereri trimise de oriunde de pe internet
 * închideau deschiderea panourilor pentru TOATĂ echipa: exact paguba pe care
 * pragul separat din configurație voia s-o evite („cu aluatul pe masă"), doar
 * că pornită din afară, nu de colegi. Și /auth/panel este o rută publică, fără
 * parolă și fără token CSRF, deci „oricine" înseamnă chiar oricine.
 *
 * Aici se numără pe IP ȘI pe pseudo-utilizator: cel care ghicește codul își
 * blochează doar propria conexiune, iar tableta din laborator deschide mai
 * departe cu codul bun.
 */
function panel_rate_limited(string $ip, int $max): bool
{
    $window = (int) config('login_window_min');
    $n = (int) scalar(
        'SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND username = ? AND ok = 0
            AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
        [$ip, GP_PANEL_KEY, $window]
    );
    return $n >= $max;
}

function record_attempt(string $ip, string $username, bool $ok): void
{
    // Tăiat la lungimea coloanei (VARCHAR(64)). Orice text lipit în câmpul
    // „Utilizator" ajunge până aici: o linie copiată dintr-un mesaj, un token,
    // o completare automată greșită. Mai lung de 64 de caractere, INSERT-ul
    // pica cu eroarea 1406, cererea se încheia cu „Eroare internă." în loc de
    // „Utilizator sau parolă greșită.", iar încercarea nici nu se mai număra.
    // Un cont nu poate avea oricum un nume mai lung, deci tăierea nu confundă
    // niciodată două conturi reale.
    $username = mb_substr($username, 0, 64);
    q('INSERT INTO login_attempts (ip, username, ok) VALUES (?, ?, ?)', [$ip, $username, $ok ? 1 : 0]);

    // O intrare reușită șterge greșelile de dinainte ale aceluiași cont.
    //
    // Fără asta, limitarea numără greșelile dintr-o fereastră de timp,
    // indiferent de ce s-a întâmplat între ele: într-o dimineață aglomerată,
    // greșelile răzlețe ale mai multor oameni se adunau până închideau contul,
    // deși de fiecare dată cineva intrase corect imediat după. Contează cât
    // s-a greșit LA RÂND, nu cât s-a greșit în total.
    //
    // Nu slăbește apărarea: cine nu știe parola nu poate ajunge niciodată aici
    // cu $ok = true, deci nu-și poate șterge propriile încercări.
    if ($ok && $username !== '' && ($username === GP_PANEL_KEY || !reserved_username($username))) {
        // Contul „panou" e comun tuturor tabletelor și se numără pe conexiune
        // (vezi panel_rate_limited), deci și ștergerea rămâne pe conexiune.
        // Altfel prima tabletă care deschide dimineața ar șterge și greșelile
        // celui care ghicește codul de la distanță, adică i-ar da drumul de la
        // capăt tocmai când echipa își vede de treabă.
        if ($username === GP_PANEL_KEY) {
            q('DELETE FROM login_attempts WHERE username = ? AND ip = ? AND ok = 0', [$username, $ip]);
        } else {
            q('DELETE FROM login_attempts WHERE username = ? AND ok = 0', [$username]);
        }
    }
}

/**
 * Șterge greșelile numărate pentru un cont.
 *
 * Blocarea se calculează din `login_attempts`, nu dintr-o coloană, deci „i-am
 * dat parolă nouă, contul e liber" trebuie să însemne și că numărătoarea o ia
 * de la capăt. Altfel contul ar rămâne blocat chiar cu parola cea bună, iar
 * butonul din Conturi ar părea că nu face nimic.
 */
function clear_attempts(string $username): void
{
    if ($username !== '') {
        q('DELETE FROM login_attempts WHERE username = ? AND ok = 0', [$username]);
    }
}

/**
 * Verifică datele de autentificare și deschide o sesiune.
 * Întoarce [token, csrf, user] sau oprește cererea cu o eroare.
 */
function do_login(string $username, string $password): array
{
    $ip = client_ip();

    if (login_rate_limited($ip, $username)) {
        fail('Prea multe încercări. Încearcă din nou peste câteva minute.', 429, 'rate_limited');
    }

    $u = one(
        'SELECT id, username, password_hash, name, role, active, locked_until, failed_logins
           FROM users WHERE username = ?',
        [$username]
    );

    // Blocarea contului, verificată ÎNAINTE de parolă, cu același răspuns
    // indiferent de parolă. Înainte se verifica abia după password_verify():
    // parola greșită primea 401, cea corectă 423 — blocarea nu oprea ghicirea,
    // ci confirma parola bună.
    //
    // Și e pe conexiune, ca limitarea de mai sus: se calculează din
    // `login_attempts` pentru (utilizator, IP), nu din `users.locked_until`,
    // care e o singură valoare pentru toată lumea. Cu ea, zece greșeli pe oră
    // trimise de oriunde țineau contul închis douăzeci de minute, iar repetate
    // ținându-l închis permanent — tot fără să știe nimeni parola.
    if ($u && login_locked($ip, $username)) {
        record_attempt($ip, $username, false);
        fail('Contul este blocat temporar. Încearcă mai târziu.', 423, 'account_locked');
    }

    // Verificăm parola chiar și când contul nu există, ca durata răspunsului
    // să nu dezvăluie dacă utilizatorul este valid. Hash-ul fals se face cu
    // ACELAȘI cost ca al conturilor reale (PASSWORD_DEFAULT, o singură dată pe
    // proces): unul scris fix, cu cost 10, dura de patru ori mai puțin decât
    // un hash real cu cost 12, iar diferența spunea dacă utilizatorul există.
    static $dummy = null;
    $dummy ??= password_hash('parola-care-nu-exista', PASSWORD_DEFAULT);
    $hash = $u['password_hash'] ?? $dummy;
    $ok   = password_verify($password, $hash);

    if (!$u || !$ok || (int) $u['active'] !== 1) {
        record_attempt($ip, $username, false);
        if ($u) {
            // Se numără greșelile din fereastra de mai jos, nu din toată
            // istoria contului.
            //
            // `users.failed_logins` era un contor care creștea și nu scădea
            // niciodată de la sine: se punea pe zero doar la o autentificare
            // reușită sau la o resetare de parolă. Odată ajuns la prag, rămânea
            // acolo, deci condiția de blocare era adevărată la FIECARE greșeală
            // de după — o singură literă greșită, peste o lună, închidea contul
            // încă douăzeci de minute, și tot așa, la nesfârșit.
            //
            // Fereastra e mai lungă decât cea a limitării încercărilor (15
            // minute), altfel blocarea n-ar mai fi apucat să se declanșeze
            // niciodată: limitarea răspunde 429 la a opta greșeală dintr-un
            // sfert de oră, înainte de a zecea. Rostul blocării e tocmai să
            // prindă ghicirea răbdătoare, întinsă peste mai multe ferestre.
            //
            // Rândul curent e deja înregistrat de record_attempt() de mai sus,
            // deci numărătoarea îl include.
            //
            // `locked_until` nu se mai scrie: blocarea se citește din
            // login_attempts, pe conexiune (vezi login_locked()). Contorul
            // rămâne pentru pagina de diagnostic.
            $fails = (int) scalar(
                'SELECT COUNT(*) FROM login_attempts
                  WHERE username = ? AND ip = ? AND ok = 0
                    AND created_at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)',
                [$username, $ip, (int) config('lock_window_min', 60)]
            );
            q('UPDATE users SET failed_logins = ? WHERE id = ?', [$fails, $u['id']]);
        }
        // Mesaj identic în toate cazurile: nu spunem care parte a fost greșită.
        fail('Utilizator sau parolă greșită.', 401, 'bad_credentials');
    }

    // Parola veche poate avea un cost mai mic decât cel curent al PHP-ului.
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }

    q('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
    record_attempt($ip, $username, true);

    // Sesiunea dinainte se închide. Pe un calculator din laborator, unde
    // oamenii se schimbă la tură, altfel ar fi rămas în baza de date o sesiune
    // valabilă a colegului dinainte, cu tot cu tokenul ei.
    $previous = $_COOKIE[GP_COOKIE] ?? '';
    if ($previous !== '') {
        q('DELETE FROM sessions WHERE id = ?', [token_hash($previous)]);
    }

    $token = random_token();
    $csrf  = random_token();
    q(
        'INSERT INTO sessions (id, user_id, csrf_token, expires_at, ip, user_agent)
         VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY, ?, ?)',
        [
            token_hash($token), $u['id'], $csrf,
            session_days_for((string) $u['role'], (int) ($u['is_system'] ?? 0) === 1),
            $ip, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]
    );

    set_session_cookie($token, session_days_for((string) $u['role'], (int) ($u['is_system'] ?? 0) === 1));
    audit('login', 'user', (string) $u['id'], null, (int) $u['id'], $u['name']);

    return [$token, $csrf, $u];
}

/**
 * Câte zile ține sesiunea unui rol.
 *
 * Era un singur număr pentru toată lumea. Dar telefonul clientului și
 * calculatorul din laborator nu sunt același lucru: pe primul, o sesiune lungă
 * scutește o parolă scrisă degeaba; pe al doilea, ea rămâne deschisă pentru
 * cine intră după tine în tură.
 */
function session_days_for(string $role, bool $sistem = false): int
{
    // Contul comun al tabletelor nu e „un angajat cu o sesiune lungă", e un
    // dispozitiv. Vezi `session_days_panou()`.
    if ($sistem) {
        return session_days_panou();
    }
    $key  = $role === 'client' ? 'session_days_client' : 'session_days_staff';
    $days = (int) (config($key) ?? config('session_days', 14));
    return $days > 0 ? $days : 14;
}

/**
 * Câte zile ține sesiunea tabletei comune din laborator.
 *
 * Tableta nu e dispozitivul nimănui: stă în laborator, pornită, și se deschide
 * cu codul știut de toată tura. Când sesiunea ei expiră, ecranul cu comenzile
 * zilei se golește în mijlocul turei și cineva trebuie să caute codul — exact
 * în ora în care nimeni n-are mâinile libere. De-asta nu ia numărul
 * personalului (`session_days_staff`, șapte zile), ci unul al ei.
 *
 * Cheia poate lipsi cu totul din `api/config.php` de pe server: fișierul acela
 * nu se suprascrie niciodată la actualizare (`docs/07-actualizari.md`), deci
 * cheile noi nu apar singure. Valoarea implicită stă AICI, în cod, nu doar în
 * `config.example.php` — altfel prima instalare actualizată ar fi rămas pe
 * `null`, adică pe zero zile.
 */
function session_days_panou(): int
{
    $zile = (int) (config('session_days_panou') ?? 365);
    return $zile > 0 ? $zile : 365;
}

/**
 * Împinge înainte sesiunea tabletei, ca să nu expire niciodată din mers.
 *
 * De ce nu ajunge un număr mare de zile, oricât de mare: cookie-ul. Browserele
 * taie durata unui cookie la 400 de zile (Chrome 104 și urmașele, după
 * RFC 6265bis), deci un „zece ani" scris în `Expires` e o durată pe care
 * browserul o scurtează fără să spună nimănui. Rândul din `sessions` ar rămâne
 * valabil, dar cookie-ul cu care se ajunge la el ar fi dispărut din tabletă —
 * adică tot o deconectare, doar mai târziu și mai greu de înțeles.
 *
 * Aici se face invers: la fiecare cerere venită de pe tabletă se uită cât i-a
 * mai rămas, iar dacă a trecut o zi de la ultima împrospătare, se pune din nou
 * termenul întreg — și în baza de date, și în cookie. Panourile întreabă
 * serverul la fiecare cincisprezece-douăzeci de secunde
 * (`Panou Laborator.dc.html:705`, `Ruta Sofer.dc.html:375`), deci o tabletă
 * folosită nu ajunge niciodată la capăt. Una uitată într-un sertar are anul de
 * mai sus, apoi cere iar codul.
 *
 * Scrierea e o dată pe zi, nu la fiecare cerere: cu pulsul acela, un UPDATE
 * necondiționat ar însemna vreo patru mii de scrieri pe zi de fiecare tabletă,
 * pentru un rând care oricum n-ar sări mai mult de-o clipă.
 *
 * Ce NU face: nu ține în viață o tabletă căreia i s-a schimbat codul.
 * Ștergerea din `api/lib/settings.php` (steagul `$inchide`) taie rândul din
 * tabelă, iar o sesiune ștearsă nu mai trece nici pe aici. Aceea rămâne
 * singura pârghie prin care se scoate o tabletă afară, și rămâne întreagă.
 */
function prelungeste_sesiunea_panoului(array $row): void
{
    $token = (string) ($_COOKIE[GP_COOKIE] ?? '');
    if ($token === '') {
        return;
    }

    $zile = session_days_panou();

    // `expires_at` e scris cu UTC_TIMESTAMP(), deci se citește ca UTC oriunde
    // ar fi pus ceasul serverului. Fără „ UTC" aici, un server pe ora
    // României ar fi crezut sesiunea cu trei ore mai bătrână decât e.
    $capat  = strtotime((string) ($row['expires_at'] ?? '') . ' UTC');
    $ramase = $capat ? $capat - time() : 0;
    if ($ramase > ($zile - 1) * 86400) {
        return;                              // s-a împrospătat deja în ziua asta
    }

    q(
        'UPDATE sessions SET expires_at = UTC_TIMESTAMP() + INTERVAL ? DAY WHERE id = ?',
        [$zile, $row['sid']]
    );

    // Cookie-ul primește același termen. Fără rândul ăsta, sesiunea ar trăi un
    // an în baza de date și tableta ar pierde-o oricum, la ziua scrisă în
    // browser. `headers_sent()` fiindcă funcția asta e pe drumul fiecărei
    // cereri, inclusiv din probe și din scripturi care au tipărit deja ceva.
    if (!headers_sent()) {
        set_session_cookie($token, $zile);
    }
}

function set_session_cookie(string $token, int $days = 0): void
{
    $days = $days > 0 ? $days : (int) config('session_days', 14);
    $opts = [
        'expires'  => time() + 86400 * $days,
        'path'     => '/',
        'httponly' => true,                       // inaccesibil din JavaScript
        'samesite' => 'Lax',
        'secure'   => (bool) config('cookie_secure'),
    ];
    $domain = (string) config('cookie_domain');
    if ($domain !== '') {
        $opts['domain'] = $domain;                // „.goldenpastry.ro” → toate subdomeniile
    }
    setcookie(GP_COOKIE, $token, $opts);
}

function do_logout(): void
{
    $token = $_COOKIE[GP_COOKIE] ?? '';
    if ($token !== '') {
        q('DELETE FROM sessions WHERE id = ?', [token_hash($token)]);
    }
    $opts = ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'];
    $domain = (string) config('cookie_domain');
    if ($domain !== '') {
        $opts['domain'] = $domain;
    }
    setcookie(GP_COOKIE, '', $opts);
}

/** Șterge sesiunile și încercările expirate. Apelat ocazional, la login. */
function purge_expired(): void
{
    // Cererile de parolă nouă expiră în minute, dar rândurile rămân. Le luăm
    // odată cu sesiunile, ca tabela să nu crească la nesfârșit.
    q('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY');
    q('DELETE FROM sessions WHERE expires_at < UTC_TIMESTAMP()');
    q('DELETE FROM login_attempts WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
}

/* -------------------------------------------------------------- jurnal -- */

/**
 * Scrie o intrare în jurnal. `user_label` este păstrat ca text, ca jurnalul
 * să rămână lizibil și după ștergerea contului.
 */
function audit(
    string $action,
    ?string $entity = null,
    ?string $entityId = null,
    ?string $detail = null,
    ?int $userId = null,
    ?string $userLabel = null
): void {
    if ($userId === null) {
        $u         = current_user();
        $userId    = $u ? (int) $u['id'] : null;
        $userLabel = $u ? $u['name'] : null;
    } elseif ($userLabel === null) {
        // NUMELE LIPSĂ NU E „SISTEM".
        //
        // Cine dă `$userId` explicit — fiindcă îl are deja la îndemână, ca
        // `referral_commission_pay($ids, $paid, $adminId)` — uita al șaselea
        // argument, iar `user_label` intra NULL în coloană. Jurnalul scria
        // atunci „Sistem" (Jurnal.dc.html:337) tocmai la mișcările de bani din
        // Recomandări: cine a bifat plata unui comision nu se mai putea afla.
        // Mai rău, căutarea în jurnal pleacă la server și lovește
        // `l.user_label LIKE ?` (api/routes/log.php:16), deci rândurile astea
        // nu ieșeau nici la o căutare după numele omului.
        //
        // Aici e locul reparației, nu la cele patru apeluri: așa nu se mai
        // poate scrie vreodată un rând orb, nici de la un apel scris mâine.
        // Sesiunea de față răspunde fără drum la bază în cazul obișnuit —
        // omul care apasă butonul e chiar cel care e autentificat.
        $u = current_user();
        if ($u && (int) $u['id'] === $userId) {
            $userLabel = (string) $u['name'];
        } else {
            $nume      = scalar('SELECT name FROM users WHERE id = ?', [$userId]);
            $userLabel = $nume === null ? null : (string) $nume;
        }
    }
    // Tăiate la lungimea coloanelor (entity_id VARCHAR(64), user_label 128):
    // identificatorul vine uneori direct din adresa cererii, iar un segment
    // lung (/orders/12<70 de caractere>) trecea de (int)-ul din rută, comanda
    // se salva, tranzacția se închidea — și abia jurnalul pica cu 1406, adică
    // 500 pentru o scriere reușită. Jurnalul nu are voie să strice cererea.
    $entityId  = $entityId === null ? null : mb_substr($entityId, 0, 64);
    $userLabel = $userLabel === null ? null : mb_substr($userLabel, 0, 128);
    q(
        'INSERT INTO audit_log (user_id, user_label, action, entity, entity_id, detail, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$userId, $userLabel, $action, $entity, $entityId, $detail, client_ip()]
    );
}

/**
 * Ce s-a schimbat de fapt, în cuvinte, pentru `detail`-ul jurnalului.
 *
 * Rutele care modifică ceva construiesc UPDATE-ul din două tablouri paralele:
 * `$sets` ține „coloană = ?", `$vals` ține valoarea, în aceeași ordine. Aici
 * se citesc înapoi, pereche cu pereche, și se pun lângă rândul vechi.
 *
 * DE CE ÎN BIBLIOTECĂ, ȘI NU LA FIECARE RUTĂ. Jurnalul scria până acum numele
 * lucrului atins — „Croissant", „Ana Popescu" — și atât. Cine deschidea
 * Jurnalul după o reclamație vedea că produsul a fost modificat marți, dar nu
 * ce anume: prețul, gramajul, ori o virgulă din descriere. O urmă care spune
 * „cine" și „ce lucru", dar nu „ce", nu ajută la nimic tocmai în ziua în care e
 * nevoie de ea. Scrisă o dată, aici, regula merge la fel în conturi, în catalog
 * și în depozit, și nu se poate depărta între ele.
 *
 * Se scrie NUMAI ce chiar s-a schimbat. Paginile salvează la ieșirea din
 * casetă, deci trimit și câmpurile pe care nu le-a atins nimeni; scrise
 * necondiționat, jurnalul s-ar umple de „nume: Ana → Ana" și ar îneca exact
 * rândul căutat.
 *
 * `$etichete` dă numele omenesc al coloanei, iar pe locul al doilea, opțional,
 * o hartă de valori („1" → „activ"), fiindcă „active: 1 → 0" nu e o propoziție.
 */
function audit_diff(array $sets, array $vals, array $vechi, array $etichete = []): string
{
    $out = [];
    foreach ($sets as $i => $set) {
        if (!array_key_exists($i, $vals)) {
            continue;
        }
        $col = trim(explode('=', (string) $set, 2)[0]);
        // Rândul vechi vine dintr-un SELECT *; o coloană care nu se află în el
        // înseamnă că ruta scrie ceva ce n-a citit, deci n-avem cu ce compara.
        if (!array_key_exists($col, $vechi)) {
            continue;
        }
        $et    = $etichete[$col] ?? $col;
        $harta = [];
        if (is_array($et)) {
            [$et, $harta] = $et;
        }
        $a = audit_value($vechi[$col]);
        $b = audit_value($vals[$i]);
        if ($a === $b) {
            continue;
        }
        $out[] = $et . ': ' . ($harta[$a] ?? ($a === '' ? '—' : $a))
            . ' → ' . ($harta[$b] ?? ($b === '' ? '—' : $b));
    }
    return implode(' · ', $out);
}

/**
 * O valoare, adusă la forma în care se poate și compara, și citi.
 */
function audit_value($v): string
{
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    $s = (string) preg_replace('/\s+/u', ' ', trim((string) $v));
    // Numerele cu zecimale se aduc la aceeași formă pe amândouă capetele: din
    // MySQL un DECIMAL vine „12.50", iar din cerere același preț vine ca 12.5.
    // Comparate ca text ar părea schimbate la fiecare salvare, iar jurnalul ar
    // scrie „preț gata de servire: 12.50 → 12.5" pe o casetă pe care n-a atins-o nimeni.
    //
    // Numai cele cu punct. Un telefon ca „0740123456" e și el „numeric" pentru
    // PHP, iar trecut prin pâlnia asta ar ieși „740123456" — și, fiindcă trec
    // prin ea AMÂNDOUĂ capetele, paguba nu e o schimbare raportată degeaba, ci
    // una care DISPARE: cine pierde zeroul din față la tastare ar avea de acum
    // înainte alt număr de telefon, iar jurnalul n-ar scrie nimic despre asta.
    // Exact câmpul după care sună cineva a doua zi.
    if (is_numeric($s) && str_contains($s, '.')) {
        $s = rtrim(rtrim(number_format((float) $s, 6, '.', ''), '0'), '.');
        if ($s === '' || $s === '-') {
            $s = '0';
        }
    }
    // Tăiat: o listă JSON de zone sau o descriere lungă ar face rândul de
    // jurnal necitibil. Ce contează e că s-a schimbat, și cam ce era acolo.
    return mb_strlen($s) > 60 ? mb_substr($s, 0, 57) . '…' : $s;
}

/* ------------------------------------------------------- conturi -- */

/*
 * Mutată aici din api/routes/users.php. Motivul e structura routerului:
 * index.php încarcă doar fișierul rutei potrivite, deci o funcție din
 * routes/users.php nu există pentru routes/auth.php — iar de regula asta are
 * nevoie și înscrierea de pe site, nu doar conturile făcute de administrator.
 * Numele, semnătura și comportamentul sunt neschimbate; apelurile din
 * routes/users.php merg mai departe fără nicio modificare.
 */
/**
 * Verifică adresa unui cont înainte să fie scrisă.
 *
 * Formatul: singurul lucru pentru care aplicația folosește câmpul e „am uitat
 * parola". O adresă strâmbă nu se plânge la salvare, dar face ca linkul să nu
 * plece niciodată — send_email() aruncă, excepția e doar jurnalizată, iar omul
 * primește același răspuns ca oricine. Adică o greșeală de tastare descoperită
 * peste o lună, de cineva care nu mai poate intra în cont.
 *
 * Unicitatea: nu e curățenie, e securitate. Cu aceeași adresă pe două conturi,
 * /auth/forgot nu mai poate spune care dintre ele a cerut parola nouă (vezi
 * api/routes/auth.php), deci ori schimbă parola omului nepotrivit, ori — de
 * când e reparat acolo — nu mai poate trimite nimic niciunuia. Ambele rele se
 * opresc aici, la scriere: e singurul loc unde se mai știe cine a cerut ce.
 *
 * `$exceptId` e contul care se editează, ca adresa lui să nu se certe cu ea
 * însăși.
 */
function check_user_email(string $email, ?int $exceptId = null, bool $public = false): void
{
    // Contul fără adresă e o stare normală: clienții luați la telefon n-au una.
    if ($email === '') {
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        fail('Adresa de e-mail nu este validă.', 422, 'bad_email');
    }
    // Coloana e utf8mb4_unicode_ci, deci comparația nu se uită la majuscule:
    // „Comenzi@Firma.ro" și „comenzi@firma.ro" duc la aceeași cutie poștală și
    // trebuie să se ciocnească.
    $alt = one('SELECT username FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, $exceptId ?? 0]);
    if ($alt) {
        // Pe ruta publică de înscriere mesajul NU numește contul: altfel oricine
        // află, fără să fie autentificat, ce nume de utilizator stă în spatele
        // unei adrese — exact informația de care are nevoie ca să-i ghicească
        // parola sau să-i blocheze contul cu încercări greșite. Administratorul,
        // din Conturi, primește numele: el trebuie să știe de pe care cont s-o
        // șteargă.
        if ($public) {
            fail('Există deja un cont cu această adresă de e-mail. Dacă e al tău, folosește „Ți-ai uitat parola?”.', 409, 'email_taken');
        }
        fail('Adresa „' . $email . '” este deja trecută pe contul „' . $alt['username'] . '”. '
            . 'Recuperarea parolei se face după adresă, deci două conturi nu pot avea aceeași adresă. '
            . 'Șterge-o de pe celălalt cont sau folosește alta.', 409, 'email_taken');
    }
}

/** Forma publică a unui utilizator — fără hash de parolă, niciodată. */
function public_user(array $u): array
{
    return [
        'id'                 => (int) $u['id'],
        'username'           => $u['username'],
        'name'               => $u['name'],
        'email'              => $u['email'] ?? null,
        'role'               => $u['role'],
        'phone'              => $u['phone'] ?? null,
        // Adresa proprie, ca site-ul să poată completa formularul de comandă
        // în locul clientului. Nu e un secret față de el însuși, iar
        // public_user() se folosește numai pentru propriul cont sau din
        // paginile de administrare.
        'address'            => $u['address'] ?? null,
        // Zona firmei, ca site-ul să nu mai ceară la fiecare comandă un răspuns
        // pe care contul îl știe. Ca `referralCode` de mai jos, `?? null` nu e
        // lene: rândul ajunge aici pe mai multe drumuri, și numai unele aduc
        // coloana — `current_user()` are listă fixă (vezi `user_zone()`), iar o
        // bază fără migrarea 016 n-are ce coloană să dea. `null` înseamnă „nu se
        // știe de aici", și paginile nu bifează nimic în casetă.
        'zone'               => $u['zone'] ?? null,
        'customerName'       => $u['customer_name'] ?? null,
        // Codul de recomandare, când rândul primit îl conține. `?? null` nu e
        // lene: funcția primește și rânduri din interogări cu listă fixă de
        // coloane (current_user(), route_login()), și rânduri dintr-o bază pe
        // care migrarea 007 încă n-a fost rulată. În ambele cazuri câmpul iese
        // `null`, iar paginile nu desenează secțiunea — nimic nu se strică.
        'referralCode'       => $u['referral_code'] ?? null,
        'mustChangePassword' => (bool) ($u['must_change_password'] ?? false),
        // Cont de tabletă, deschis cu codul comun al turei (vezi POST
        // /auth/panel), sau contul unui om anume? Meniul are nevoie să
        // deosebească: „Parola mea" pe contul comun înseamnă că cine o schimbă
        // închide panoul întregii ture. Tot de aici află panourile de laborator
        // și de rută dacă au pe cine numi în sertar — până acum aveau scris în
        // cod că n-au, ceea ce era adevărat pentru tabletă și fals pentru un
        // manager care deschidea aceeași pagină.
        'isSystem'           => (bool) ($u['is_system'] ?? false),
        // ARE omul cod de pontaj — niciodată CARE e. Pagina Conturi are nevoie
        // de răspunsul ăsta ca să scrie pe buton „Pune cod" sau „Schimbă
        // codul", iar tableta din laborator ca să știe pe cine să arate.
        //
        // TREI VALORI, NU DOUĂ: `true`, `false` și `null` = „nu se știe de
        // aici". Rândurile ajung în funcția asta pe trei drumuri, și numai unul
        // din ele aduce coloana: `GET /users` o cere anume, dar
        // `current_user()` are listă fixă de coloane (vezi motivul la
        // api/routes/auth.php), iar o bază pe care migrarea 014 n-a fost încă
        // importată n-are ce coloană să dea.
        //
        // Cu două valori, `/auth/me` răspundea `false` — „n-are cod" — despre
        // un om care avea. Nimeni nu citește asta azi, dar e fix soiul de
        // minciună mică peste care se scrie mâine o pagină. `null` e falsă în
        // JavaScript la fel ca `false`, deci nicio pagină de azi nu se schimbă;
        // una de mâine poate însă deosebi „n-are" de „n-am de unde ști".
        // Aceeași croială ca `referralRate`, unde `null` nu e zero la sută.
        'hasLabPin'          => array_key_exists('lab_pin', $u)
            ? ($u['lab_pin'] !== null && $u['lab_pin'] !== '')
            : null,
    ];
}

/**
 * A fost importată migrarea 010, cea care spune de unde vine un cont?
 *
 * Aceeași poartă ca `referral_money_supported()` și din același motiv: fișierele
 * se urcă pe server înaintea importului din phpMyAdmin, iar o coloană cerută
 * necondiționat ar cădea cu 1054 „Unknown column" pe fiecare comandă luată la
 * telefon — adică ar opri patiseria pentru o regulă de identitate.
 *
 * CE SE ÎNTÂMPLĂ CÂND LIPSEȘTE, spus pe față: `client_by_name()` se poartă exact
 * ca înainte de reparație, adică leagă comanda după nume, oricare ar fi contul.
 * Poarta cade DESCHIS, dinadins. Închisă, o instalare fără migrare n-ar mai lega
 * NICIO comandă de niciun cont, iar fiecare client al casei și-ar găsi
 * „Comanda mea" goală — o pagubă zilnică, sigură și tăcută, în locul uneia rare.
 * Prețul e că defectul rămâne deschis până la import; de-aia migrarea e trecută
 * în pașii de publicare din docs/03 și docs/07.
 */
function user_origin_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        return $ok = ((int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND COLUMN_NAME IN ('self_registered', 'vouched_at', 'vouched_by')"
        ) === 3);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica originea conturilor: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * A fost importată migrarea 014, cea a pontajului din laborator?
 *
 * Aceeași poartă ca `founder_supported()` și `user_origin_supported()`, din
 * același motiv: codul de pe server poate fi mai nou decât baza lui. NU din
 * actualizarea cuminte — aceea importă migrările întâi și urcă fișierele pe
 * urmă (`docs/07-actualizari.md`, „Ordinea: baza întâi, apoi fișierele") — ci
 * din `--doar-fisiere`, dintr-un import oprit la mijloc, ori din plasturele pus
 * de mână. În fereastra aceea, o interogare pe `lab_shifts` ar cădea cu „1146
 * Table doesn't exist", iar omul din fața tabletei ar citi „Eroare internă.".
 *
 * Amândouă piesele deodată, nu una: o coloană fără tabelă ar lăsa să se pună
 * coduri de pontaj care n-au unde scrie o tură, iar o tabelă fără coloană n-ar
 * avea cum legitima pe nimeni. Migrarea le aduce împreună, deci și poarta le
 * cere împreună.
 *
 * Cade ÎNCHIS, spre deosebire de `user_origin_supported()`: acolo poarta
 * închisă ar fi stricat legarea comenzilor de conturi, adică o pagubă zilnică;
 * aici închis înseamnă doar că butonul de pontaj lipsește până la import, ceea
 * ce e chiar adevărul.
 */
function lab_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        return $ok = ((int) scalar(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_shifts'"
        ) === 1 && (int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'lab_pin'"
        ) === 1);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica pontajul: ' . $e->getMessage());
        return $ok = false;
    }
}

/** Oprește cererea lămurit pe o instalare nemigrată. Vezi lab_supported(). */
function require_lab(): void
{
    if (!lab_supported()) {
        fail(
            'Pontajul nu este disponibil: rulează întâi sql/014_pontaj_laborator.sql.',
            503,
            'lab_unavailable'
        );
    }
}

/**
 * Știe baza de tabla de anunțuri din laborator? (migrarea 015)
 *
 * POARTĂ PROPRIE, nu o lărgire a lui `lab_supported()`, fiindcă sunt două
 * migrări care se importă una după alta. Între ele e o fereastră în care
 * pontajul merge și anunțurile nu. Lipite într-o singură întrebare, importul
 * oprit la jumătate ar fi stins și pontajul — adică tocmai lucrul care mergea.
 *
 * Cade ÎNCHIS, ca sora ei: pe o bază neimportată, ecranul laboratorului n-are
 * tablă de anunțuri, ceea ce e chiar adevărul. Comenzile zilei, care sunt
 * treaba acelui ecran, se văd mai departe.
 */
function lab_board_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        return $ok = ((int) scalar(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_messages'"
        ) === 1);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica tabla de anunțuri: ' . $e->getMessage());
        return $ok = false;
    }
}

/** Oprește cererea lămurit pe o instalare nemigrată. Vezi lab_board_supported(). */
function require_lab_board(): void
{
    if (!lab_board_supported()) {
        fail(
            'Tabla de anunțuri nu este disponibilă: rulează întâi sql/015_mesaje_laborator.sql.',
            503,
            'board_unavailable'
        );
    }
}

/**
 * Cheia sub care se numără încercările greșite de cod de pontaj.
 *
 * Proprie omului și DEOSEBITĂ de numele lui de cont, dinadins: un cod greșit
 * la tabletă n-are voie să-i închidă intrarea cu parolă, nici invers. Sunt
 * două uși, cu două chei, care se pot închide una fără cealaltă.
 *
 * Stă aici, lângă `record_attempt()` și `clear_attempts()`, nu în ruta de
 * pontaj: cheia se scrie într-un loc și se șterge în altul — pontajul o pune,
 * iar pagina Conturi o golește când administratorul schimbă codul — iar două
 * copii ale aceleiași chei se depărtează exact în ziua în care contează.
 */
function lab_attempt_key(int $userId): string
{
    return 'pontaj:' . $userId;
}

/**
 * De unde vine contul, pentru pagina lui.
 *
 * NU se citește din `current_user()`: interogarea aceea are listă fixă de
 * coloane și e pe drumul fiecărei cereri, deci o coloană adăugată acolo ar
 * cădea cu 1054 pe o instalare nemigrată — adică ar opri toată aplicația pentru
 * o funcție secundară. Aici e o citire după cheia primară, chemată o dată, din
 * `route_me()`. Același tipar ca `referral_public()`.
 *
 * `null` înseamnă „instalarea nu știe încă să răspundă", nu „nu vine de pe
 * site" — paginile trebuie să deosebească, ca să nu scrie un mesaj despre o
 * regulă care pe serverul acela nici nu se aplică.
 */
function user_origin_public(int $id): ?array
{
    if (!user_origin_supported() || $id <= 0) {
        return null;
    }
    $u = one('SELECT self_registered, vouched_at FROM users WHERE id = ?', [$id]);
    if (!$u) {
        return null;
    }
    return [
        'self'    => (int) $u['self_registered'] === 1,
        'vouched' => $u['vouched_at'] !== null,
    ];
}

/**
 * A fost importată migrarea 016, cea a zonei ținute pe cont?
 *
 * Aceeași poartă ca `user_origin_supported()` și `lab_supported()`, din același
 * motiv: există ferestre în care codul nou vorbește cu baza veche. Nu la
 * actualizarea cuminte — `actualizeaza-vps.sh` importă migrările întâi și urcă
 * fișierele pe urmă (docs/07, „Ordinea: baza întâi, apoi fișierele") — ci la
 * `--doar-fisiere`, la un import oprit la mijloc, ori la plasturele pus de
 * mână. Cerută necondiționat, coloana ar răspunde 1054 „Unknown column".
 *
 * CE SE ÎNTÂMPLĂ CÂND LIPSEȘTE, spus pe față: zona nu se ține pe cont, adică
 * totul se poartă exact ca înainte de lucrarea asta — clientul alege zona la
 * fiecare comandă, cum a făcut dintotdeauna. Poarta cade pe purtarea veche, nu
 * pe un refuz: o funcție de comoditate n-are voie să oprească vânzarea.
 */
function user_zone_supported(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        return $ok = ((int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'zone'"
        ) === 1);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica zona conturilor: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Zona firmei, de pe cont.
 *
 * NU se citește din `current_user()`: interogarea aceea are listă fixă de
 * coloane și e pe drumul FIECĂREI cereri, deci o coloană adăugată acolo ar
 * cădea cu 1054 pe o instalare nemigrată — adică ar opri toată aplicația pentru
 * o funcție secundară. Aici e o citire după cheia primară, chemată din
 * `route_me()`, `route_login()` și de la primirea unei cereri de ofertă.
 * Același tipar ca `referral_public()` și `user_origin_public()`.
 *
 * Nu verifică dacă zona mai e în lista de azi. O zonă redenumită între timp din
 * Setări iese pur și simplu nebifată în casetă, iar clientul alege — adică fix
 * ce se întâmplă azi pentru toată lumea. Serverul verifică lista la salvare,
 * acolo unde contează.
 */
function user_zone(int $id): ?string
{
    if (!user_zone_supported() || $id <= 0) {
        return null;
    }
    $z = trim((string) (scalar('SELECT zone FROM users WHERE id = ?', [$id]) ?? ''));
    return $z === '' ? null : $z;
}

/**
 * A intrat migrarea 023? Același tipar ca `user_zone_supported()` de deasupra.
 *
 * Răspunsul se ține minte pe durata cererii: poarta etapei finale îl întreabă
 * o dată, dar `/auth/me` și lista de conturi îl întreabă pentru fiecare rând.
 */
function livrare_semn_suportat(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        return $ok = ((int) scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'poate_confirma_livrarea'"
        ) === 1);
    } catch (Throwable $e) {
        error_log('[gp] nu pot verifica semnul de livrare: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Poate contul ăsta să spună că o comandă a ajuns la client?
 *
 * Etapa finală („Predată") înseamnă „ajunsă la client", nu „gata de plecare":
 * lista de etape n-are una separată pentru predarea către curier. O punea
 * oricine din personal, iar laboratorul o apăsa când termina de ambalat —
 * comanda apărea livrată înainte să fi urcat în mașină.
 *
 * TREI RĂSPUNSURI, ȘI FIECARE ARE PRICINA LUI.
 *
 * 1 · ADMINISTRATORUL ȘI FONDATORUL POT ÎNTOTDEAUNA, din cod, fără bifă.
 *     Semnul se naște ÎNCHIS pentru toți (migrarea 023 nu umple nimic înapoi),
 *     iar patronul îl pune pe șofer din Conturi. Între cele două clipe cineva
 *     trebuie să poată drege o comandă marcată din greșeală — altfel biroul se
 *     încuie pe dinafară cu propria regulă, exact în ziua în care a pornit-o.
 *     `GP_ADMIN_ROLES`, nu un `in_array` scris aici: o listă a doua s-ar fi
 *     despărțit de prima la primul rol nou.
 *
 * 2 · FĂRĂ MIGRAREA 023 POATE ORICINE. Paza cade DESCHIS, dinadins. Între
 *     urcarea codului și rularea migrării — minute sau zile, migrarea se dă de
 *     mână — coloana nu există. O poartă care se închide singură în fereastra
 *     aia ar opri livrările întregii case pentru o regulă care abia se naște;
 *     deschisă, se poartă exact ca ieri. Același tipar ca
 *     `lab_production_suportata()` și `user_zone_supported()`: paguba de care
 *     fugim e mai mică decât paguba de a fugi de ea.
 *
 * 3 · RESTUL: ce scrie pe cont. Citire după cheia primară, nu o coloană în
 *     plus în `current_user()` — interogarea aceea are listă fixă și e pe
 *     drumul FIECĂREI cereri, deci o coloană adăugată acolo ar cădea cu 1054
 *     pe o instalare nemigrată, adică ar opri toată aplicația. Aceeași alegere
 *     ca la `user_zone()`, `referral_public()` și `user_origin_public()`.
 *
 * Primește ori rândul contului, ori id-ul lui. ATENȚIE, NU E ACEEAȘI FORMĂ CA
 * `e_admin()`: acela primește rândul ori NUMELE ROLULUI, fiindcă atât îi
 * trebuie. Aici trebuie un id, ca să se poată citi coloana — un rol dat ca text
 * ar cădea pe `(int) 'angajat'`, adică 0, adică „nu poate", și ar refuza tăcut
 * un om care are dreptul. Deci se cheamă cu RÂNDUL contului peste tot unde el
 * există, și cu id-ul numai acolo unde nu există.
 */
function poate_confirma_livrarea($cine): bool
{
    if (e_admin($cine)) {
        return true;
    }
    if (!livrare_semn_suportat()) {
        return true;
    }
    $id = is_array($cine) ? (int) ($cine['id'] ?? 0) : (int) $cine;
    if ($id <= 0) {
        return false;
    }
    return (int) (scalar('SELECT poate_confirma_livrarea FROM users WHERE id = ?', [$id]) ?? 0) === 1;
}
