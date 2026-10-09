<?php
/**
 * Golden Pastry — configurare.
 *
 * Serverul de producție (goldenpastry.ro), verificat pe 29 august 2026 cu
 * api/verifica.php:
 *
 *   PHP 8.3.33 pe domeniu · MySQL 8.0.43 · charset utf8mb4 · HTTPS activ
 *   pdo_mysql, openssl, mbstring, curl, json, filter — toate prezente
 *   aes-256-gcm disponibil, mail() disponibilă
 *
 * ATENȚIE la versiunea de PHP: pe cPanel fiecare domeniu poate rula pe altă
 * versiune decât phpMyAdmin. Aici domeniul pornise pe 8.1.34 (ieșită din
 * suportul de securitate) deși phpMyAdmin raporta 8.3.31; a fost mutat pe
 * 8.3 din Select PHP Version. Versiunea care contează e cea care servește
 * domeniul — o vezi în api/verifica.php.
 *
 * Aplicația cere PHP 8.0 sau mai nou. Nu folosește nimic apărut după 8.0,
 * deci merge pe oricare dintre versiunile instalate acolo.
 *
 * COPIAZĂ acest fișier ca `config.php` și completează valorile.
 * `config.php` NU se urcă în git și NU se trimite nimănui: conține parola
 * bazei de date și cheia de criptare.
 *
 * Restul configurării (WhatsApp, e-mail, Telegram) se face din panoul de
 * Setări — lab.goldenpastry.ro/setari — nu aici.
 */

return [
    // --- baza de date ---------------------------------------------------
    // Local (Laragon):  host 127.0.0.1, user root, parolă goală, baza gp_dev
    // cPanel: numele bazei și al utilizatorului au prefixul contului,
    //         de forma `numecont_gp`.
    'db' => [
        // Pe cPanel, baza stă pe aceeași mașină: 'localhost'.
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'gp_dev',
        'user'     => 'root',
        'pass'     => '',
        'charset'  => 'utf8mb4',
    ],

    // --- cheia de criptare ----------------------------------------------
    // Protejează tokenurile salvate din panoul de Setări (WhatsApp, Telegram).
    // Generează una nouă, unică pentru fiecare instalare, cu:
    //     php -r "echo bin2hex(random_bytes(32));"
    // ATENȚIE: dacă schimbi cheia, tokenurile deja salvate devin ilizibile și
    // trebuie reintroduse din panou.
    'encryption_key' => '',

    // --- codul de instalare ---------------------------------------------
    // Cu el se deschide api/setup.php, pagina care creează primul cont de
    // administrator. Pune aici un cod de cel puțin 12 caractere (o comandă
    // `openssl rand -base64 24` pe server îți dă unul) și scrie-l în formular
    // la pasul 6 al publicării. Fără el, pagina nu creează niciun cont.
    //
    // De ce: setup.php ajunge pe api.goldenpastry.ro odată cu restul
    // fișierelor și stă acolo până când îți aduci aminte să-l ștergi. Între
    // momentul în care config.php e gata și momentul în care apeși „Creează
    // administratorul" pot trece ore, iar fără cod primul străin care nimerește
    // adresa în intervalul ăsta devine administratorul patiseriei. După ce
    // contul există, pagina răspunde 404 și codul nu mai contează.
    'install_code' => '',

    // --- origini permise (CORS) -----------------------------------------
    // Doar aceste adrese pot folosi API-ul cu sesiunea utilizatorului.
    'allowed_origins' => [
        'https://goldenpastry.ro',
        'https://www.goldenpastry.ro',
        'https://lab.goldenpastry.ro',
        'https://clienti.goldenpastry.ro',
        // dezvoltare locală
        'http://gp.test',
        'http://lab.gp.test',
        'http://clienti.gp.test',
    ],

    // --- cookie de sesiune ----------------------------------------------
    // Punctul din fața domeniului face sesiunea valabilă pe toate
    // subdomeniile, ca autentificarea să meargă și pe lab. și pe clienti.
    // Local lasă string gol: cookie-urile pe domenii .test nu funcționează.
    'cookie_domain' => '',        // producție: '.goldenpastry.ro'
    'cookie_secure' => false,     // producție: true (doar HTTPS)
    // Cât ține sesiunea, pe roluri. Clientul comandă de pe telefonul lui, deci
    // o lună fără parolă nu deranjează pe nimeni. Panourile din patiserie stau
    // pe calculatoare la care ajunge toată tura, deci sesiunea lor se închide
    // mai repede.
    // --- recuperarea parolei --------------------------------------------
    // Cât ține linkul din e-mail și câte cereri se acceptă de pe o conexiune.
    'reset_minutes'        => 60,
    'max_forgot_attempts'  => 10,
    // Unde duce linkul. Gol înseamnă „de unde s-a cerut" — corect în producție,
    // unde formularul stă pe pagina de intrare a subdomeniului. Local trebuie
    // scris, fiindcă pagina nu e la rădăcina serverului.
    'reset_url'            => '',

    // --- contul făcut imediat după comandă -------------------------------
    // Cererea trimisă de pe site pleacă cu un jeton de o folosință, ca omul
    // care își face contul în minutul următor să-și vadă comanda pe loc, fără
    // să aștepte să fie garantat de casă. Cât ține jetonul: destul cât să
    // scrii un nume și o parolă, nu cât să te răzgândești mâine.
    'claim_minutes'        => 30,

    // --- conturi făcute de pe site --------------------------------------
    // /auth/register e singura rută care scrie în `users` fără sesiune, deci
    // fără prag un script poate umple tabelul peste noapte. Zece conturi pe oră
    // de pe aceeași conexiune acoperă cu mult o familie sau un birou care se
    // înscriu de pe același internet.
    //
    // Cheile lipsesc dintr-un config.php mai vechi? Nu e nimic de făcut:
    // valorile de mai jos sunt și cele implicite din cod.
    'max_register_attempts' => 10,
    'register_window_min'   => 60,
    // Cereri de comandă de pe site, fără cont, de pe aceeași conexiune și în
    // aceeași fereastră. Prag larg: o cerere nu e o încercare de parolă.
    'max_request_attempts'  => 20,
    // Adresa magazinului, pentru linkurile de invitație („…/?ref=ana"). Gol
    // înseamnă „prima origine din allowed_origins", care în producție e chiar
    // https://goldenpastry.ro. Scrie-o explicit dacă schimbi ordinea listei.
    'site_url'              => '',

    'session_days_client' => 30,
    'session_days_staff'  => 7,
    // Tableta comună din laborator. Nu e dispozitivul nimănui: stă pornită, se
    // deschide cu codul turei, iar o sesiune expirată acolo înseamnă un ecran
    // gol în mijlocul lucrului. Pe lângă anul ăsta, sesiunea ei se împinge
    // singură înainte cât timp e folosită (`prelungeste_sesiunea_panoului()`),
    // deci în practică nu se închide de la sine niciodată. Rămâne să iasă la
    // schimbarea codului din Setări — singura pârghie, și e pe loc.
    'session_days_panou'  => 365,
    // Valoarea de dinaintea împărțirii pe roluri. Rămâne aici ca o instalare
    // veche, cu un singur număr în config, să nu se trezească fără sesiuni.
    'session_days'  => 14,

    // --- limitarea autentificărilor eșuate ------------------------------
    // Pe cont ȘI pe conexiune: oprește repede ghicirea parolei unui utilizator
    // anume de pe o adresă, fără ca greșelile unui străin să-i închidă contul.
    'max_login_attempts'    => 8,
    // Pe IP: prag mult mai larg. Într-o patiserie toate dispozitivele ies la
    // internet prin aceeași adresă, iar o limită strânsă pe IP ar bloca tot
    // personalul din cauza unui singur coleg care greșește parola.
    'max_login_attempts_ip' => 60,
    // Codul tabletelor: prag propriu, mai larg. Contul „panou" e comun tuturor
    // tabletelor din laborator, deci cu pragul de 8 al conturilor obișnuite opt
    // greșeli de tastare adunate într-o dimineață aglomerată ar fi închis
    // panoul pentru TOATĂ echipa, cu aluatul pe masă. Apărarea reală împotriva
    // ghicirii codului este lungimea lui: minimum 6 caractere, verificat la
    // salvarea din Setări.
    'max_panel_attempts'    => 20,
    'login_window_min'      => 15,

    // --- proxy-uri de încredere -----------------------------------------
    // Doar de la aceste adrese se cred antetele „X-Forwarded-For" și
    // „CF-Connecting-IP". Lăsată goală, se folosește adresa reală a
    // conexiunii — corect pe o găzduire cPanel obișnuită, unde cererile ajung
    // direct la Apache.
    //
    // Dacă ai lista greșită, oricine poate trimite un antet cu altă adresă la
    // fiecare cerere și ocolește limitarea încercărilor de autentificare.
    'trusted_proxies' => [
        // gol pe o găzduire obișnuită
    ],
    'lock_account_after' => 10,   // blocare temporară a contului, pe conexiune
    'lock_minutes'       => 20,
    // Fereastra în care se numără greșelile pentru blocare. Mai lungă decât
    // 'login_window_min', fiindcă limitarea de acolo oprește oricum rafalele;
    // blocarea e pentru ghicirea răbdătoare, întinsă pe mai multe ferestre.
    // Fără fereastră, contorul aduna din toată istoria contului și, odată
    // ajuns la prag, rămânea acolo: fiecare greșeală de după bloca din nou.
    'lock_window_min'    => 60,

    // --- fotografiile produselor ----------------------------------------
    // Folderul în care se salvează pozele urcate din pagina Catalog. Gol =
    // „products/" de lângă folderul api, adică exact acolo de unde le ia
    // site-ul. Folderul trebuie să permită scrierea (drepturi 755).
    'photo_dir' => '',

    // --- adresa Telegramului --------------------------------------------
    // PE SERVER RĂMÂNE GOALĂ. Goală (ori lipsă) înseamnă Telegramul adevărat,
    // https://api.telegram.org — singura valoare bună pentru site.
    //
    // Cheia există pentru probe: pe calculatorul de lucru se pune aici adresa
    // unui primitor local (de pildă 'http://127.0.0.1:8107'), ca rapoartele de
    // încasări și anunțurile trimise de probe să poată fi numărate fără să
    // ajungă pe telefonul nimănui.
    //
    // De ce stă aici și nu în Setări: la adresa asta pleacă, în fiecare cerere,
    // tokenul botului. Ce stă în Setări se schimbă dintr-un ecran; ce stă aici,
    // numai de către cine are deja serverul în mână. Se primește `https://`
    // oriunde și `http://` numai spre calculatorul însuși (127.0.0.1,
    // localhost); o valoare stricată oprește trimiterile cu o eroare spusă pe
    // nume — nu cade în tăcere pe adresa adevărată.
    'telegram_api_base' => '',

    // 'dev' arată mesaje de eroare detaliate. Pe server pune 'prod'.
    'env' => 'dev',
];
