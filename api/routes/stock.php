<?php
/**
 * Depozit: articole, mișcări de stoc și praguri minime.
 *
 * Stocul curent nu are coloană proprie — se calculează din mișcări. Așa nu
 * poate ajunge niciodată în dezacord cu registrul, iar orice cifră afișată
 * poate fi explicată prin intrările și ieșirile din spatele ei.
 */

declare(strict_types=1);

/* -------------------------------------------------------------- articole -- */

/**
 * GET /stock/items — articolele cu stocul curent și cu ce e promis alăturat.
 *
 * „Rezervat" se socotea în browser, în `Depozit.dc.html`, din comenzile aduse
 * de `GET /orders` — care are `LIMIT 500`. Peste cinci sute de comenzi cifra
 * devenea TĂCUT greșit: nimic pe ecran nu spunea că s-a numărat doar o parte,
 * iar depozitarul cumpăra materie primă după ea.
 *
 * Motivul pentru care socoteala aceea era grea rămâne adevărat și aici, doar că
 * acum e rezolvat o dată, la scriere: o poziție de comandă nu se potrivește cu
 * un articol de depozit după nume. Numele se schimbă din Catalog fără ca
 * depozitul să afle, iar articolele „adevărate" ale unui produs sunt legate prin
 * `product_id`. Potrivirea se face la crearea rezervării
 * (`stock_reserve_order`), cu exact aceeași subinterogare ca scăderea de la
 * „Predată" — deci promisiunea și ieșirea cad pe același raft.
 *
 * Ce se numără: `qty - consumed`, adică promisiunea din care s-a scăzut ce a
 * ieșit deja pe bune la producție. Și numai pentru comenzile vii: etapa de
 * încheiere și cea de anulare sting rezervarea prin simplul fapt că nu intră în
 * filtru. Eliberarea la „Predată", redeschiderea dacă iese din „Predată" și
 * eliberarea la anulare sunt astfel un singur `WHERE`, nu trei bucăți de cod
 * care pot rămâne în urmă una față de alta.
 */
function route_items_list(): void
{
    require_staff();

    // Cele două agregări stau în subinterogări, nu în același GROUP BY: unite,
    // fiecare mișcare s-ar înmulți cu fiecare rezervare a articolului, iar
    // stocul ar crește cu numărul comenzilor deschise.
    $rows = all(
        "SELECT i.*,
                COALESCE(mv.qty, 0)  AS qty,
                COALESCE(rez.qty, 0) AS reserved
           FROM stock_items i
           LEFT JOIN (
                SELECT m.item_id, SUM(m.qty * m.direction) AS qty
                  FROM stock_moves m
                 GROUP BY m.item_id
           ) mv ON mv.item_id = i.id
           LEFT JOIN (
                SELECT r.item_id, SUM(GREATEST(r.qty - r.consumed, 0)) AS qty
                  FROM stock_reservations r
                  JOIN orders o ON o.id = r.order_id
                 WHERE o.status <> ? AND o.status <> ?
                 GROUP BY r.item_id
           ) rez ON rez.item_id = i.id
          WHERE i.active = 1
          ORDER BY i.category, i.sort, i.name",
        // Etapele vin din Setări. Neconfigurate, ies ca text gol, care nu se
        // potrivește cu niciun status — deci nu se stinge nicio rezervare, ceea
        // ce e alegerea prudentă: mai bine promis prea mult decât prea puțin.
        [status_done(), status_cancelled()]
    );

    json_out([
        'items'      => array_map('stock_item_public', $rows),
        'categories' => stock_categories(),
        'units'      => stock_units(),
    ]);
}

function stock_item_public(array $r): array
{
    $qty = (float) $r['qty'];
    $min = (float) $r['min_qty'];
    // `reserved` vine doar din listare, nu și din celelalte locuri care trec
    // rânduri pe aici (creare, modificare). Acolo cifra n-ar fi decât zero
    // proaspăt calculat degeaba, iar pagina reîncarcă lista oricum.
    $rez = isset($r['reserved']) ? (float) $r['reserved'] : 0.0;
    return [
        'id'        => (int) $r['id'],
        'name'      => $r['name'],
        'category'  => $r['category'],
        'unit'      => $r['unit'],
        'qty'       => $qty,
        'minQty'    => $min,
        'low'       => $qty < $min,
        'reserved'  => $rez,
        // Ce rămâne cu adevărat de vândut sau de folosit la altceva. Poate ieși
        // pe minus, și e bine că poate: înseamnă că s-a promis mai mult decât e
        // în raft, exact lucrul pe care depozitarul trebuie să-l vadă.
        'available' => round($qty - $rez, 4),
        'productId' => $r['product_id'] === null ? null : (int) $r['product_id'],
    ];
}

/** POST /stock/items — articol nou. */
function route_items_create(): void
{
    require_staff();
    $b = body();

    // Lungimile se verifică aici, unde încă se poate spune ce e greșit.
    //
    // `need()` se uită doar dacă e gol. Denumirea lungă a unui ambalaj, lipită
    // din alt document, trecea de el, ajungea la MySQL și se întorcea ca 1406,
    // adică „Eroare internă." în producție: depozitarul rămânea cu caseta
    // deschisă, cu articolul nesalvat și fără să afle care câmp e de vină.
    $name = text_field($b['name'] ?? null, 'Numele articolului', 160, true);
    $cat  = trim((string) ($b['category'] ?? ''));
    if ($cat === '' || !in_array($cat, stock_categories(), true)) {
        fail('Categorie necunoscută. Valori permise: ' . implode(', ', stock_categories()), 422, 'bad_category');
    }
    $unit = text_field($b['unit'] ?? null, 'Unitatea de măsură', 16) ?: 'buc';
    // Pragul implicit vine din Setări („Prag minim implicit"). Un articol nou
    // pornit de la 0 nu apare niciodată în „Sub prag minim", deci nimeni nu
    // află că s-a terminat: îl descoperi abia când îți trebuie și nu-l ai.
    // `min_qty` e DECIMAL(10,2): peste 99.999.999,99 MySQL răspunde 1264, tot
    // ca „Eroare internă". Iar `(float)` nu refuză nimic — pe un tablou dă 1.0
    // — deci un câmp completat greșit intra tăcut ca prag de o bucată.
    $min = isset($b['minQty'])
        ? num_field($b['minQty'], 'Pragul minim', 0, 99999999.99, 'bad_min')
        : (float) setting('stock_min_default', '0');
    if (scalar('SELECT 1 FROM stock_items WHERE name = ?', [$name])) {
        fail('Există deja un articol cu numele „' . $name . '”.', 409, 'name_taken');
    }

    // Produsul de care ține articolul, dacă ține de unul.
    //
    // Un id inexistent cădea până în cheia străină și ieșea „Eroare internă":
    // depozitarul rămânea cu caseta deschisă, cu articolul nesalvat și fără să
    // afle ce e greșit. Iar al doilea articol pe același produs e chiar defectul
    // pe care îl repară scăderea din stoc: produsul redenumit în Catalog nu mai
    // seamănă cu articolul din depozit, omul îl adaugă încă o dată sub numele
    // nou și de atunci fiecare comandă are două articole pe care le-ar putea
    // scădea. Îl oprim aici, unde se naște, și îi spunem pe care să-l
    // folosească.
    //
    // Verificarea nu e sprijinită de un index unic pe `product_id`, deci două
    // cereri suprapuse pot trece amândouă. Nu e o garanție, e o poartă: scăderea
    // din stoc alege oricum un singur articol, chiar dacă ar fi două.
    $productId = null;
    if (isset($b['productId']) && $b['productId'] !== '') {
        $productId = (int) $b['productId'];
        $produs    = one('SELECT id, name FROM products WHERE id = ?', [$productId]);
        if (!$produs) {
            fail('Produsul din catalog nu există. Alege altul sau lasă articolul fără produs.', 422, 'bad_product');
        }
        // Numai articolele care mai sunt în depozit. Unul scos din listă nu se
        // mai vede nicăieri, iar un refuz care trimite spre el i-ar cere
        // depozitarului să pună intrările pe un articol pe care nu-l găsește —
        // și, fiindcă nicio rută nu scrie `product_id` pe un articol existent,
        // produsul ar rămâne nelegabil pentru totdeauna.
        $deja = one('SELECT id, name FROM stock_items WHERE product_id = ? AND active = 1', [$productId]);
        if ($deja) {
            fail(
                'Produsul „' . $produs['name'] . '” are deja articolul „' . $deja['name']
                . '” în depozit. Fă intrările și ieșirile pe el — comenzile se scad tot de acolo.',
                409,
                'product_taken'
            );
        }
    }

    // Verificarea de mai sus e din memorie; asta e a bazei. Doi depozitari care
    // apasă „Salvează" în aceeași secundă trec amândoi de prima și se despart
    // abia aici — al doilea primește refuzul lămurit, nu „Eroare internă".
    q_unique(
        'INSERT INTO stock_items (name, category, unit, min_qty, product_id) VALUES (?, ?, ?, ?, ?)',
        [$name, $cat, $unit, $min, $productId],
        'Există deja un articol cu numele „' . $name . '”.',
        'name_taken'
    );
    $id = (int) db()->lastInsertId();

    audit('stock_item_create', 'stock_item', (string) $id, $name . ' (' . $cat . ')');
    json_out(['id' => $id], 201);
}

/** PATCH /stock/items/{id} — denumire, categorie, unitate, prag, produs. */
function route_items_update(string $id): void
{
    require_staff();
    $b  = body();
    $id = (int) $id;

    $item = one('SELECT * FROM stock_items WHERE id = ?', [$id]);
    if (!$item) {
        fail('Articolul nu există.', 404, 'not_found');
    }

    $sets = [];
    $vals = [];
    $numeFinal = (string) $item['name'];

    if (array_key_exists('name', $b)) {
        // Aceeași măsură ca la creare, plus unicitatea. Crearea răspundea
        // politicos „Există deja un articol cu numele …", dar redenumirea peste
        // numele altui articol se izbea de `uq_stock_items_name` și ieșea ca
        // „Eroare internă": aceeași greșeală omenească, două răspunsuri
        // diferite, după ușa pe care ai intrat.
        $numeFinal = text_field($b['name'] ?? null, 'Numele articolului', 160, true);
        if (scalar('SELECT 1 FROM stock_items WHERE name = ? AND id <> ?', [$numeFinal, $id])) {
            fail('Există deja un articol cu numele „' . $numeFinal . '”.', 409, 'name_taken');
        }
        $sets[] = 'name = ?';
        $vals[] = $numeFinal;
    }
    if (array_key_exists('category', $b)) {
        if (!in_array($b['category'], stock_categories(), true)) {
            fail('Categorie necunoscută.', 422, 'bad_category');
        }
        $sets[] = 'category = ?';
        $vals[] = $b['category'];
    }
    if (array_key_exists('unit', $b)) {
        $sets[] = 'unit = ?';
        $vals[] = text_field($b['unit'] ?? null, 'Unitatea de măsură', 16) ?: 'buc';
    }
    if (array_key_exists('minQty', $b)) {
        $sets[] = 'min_qty = ?';
        $vals[] = num_field($b['minQty'], 'Pragul minim', 0, 99999999.99, 'bad_min');
    }
    if (array_key_exists('active', $b)) {
        $sets[] = 'active = ?';
        $vals[] = $b['active'] ? 1 : 0;
    }

    // Legarea de un produs din catalog, târziu.
    //
    // Crearea accepta `productId`, modificarea nu — iar comentariul de la
    // creare recunoștea singur unde duce asta: un articol făcut înainte să
    // existe produsul, sau legat din greșeală de altul, „ar rămâne nelegabil
    // pentru totdeauna". Cu rețetele, gaura nu mai e teoretică: fiecare produs
    // vândut are nevoie de articolul lui finit ca să i se poată scădea materia
    // primă, iar jumătate din depozitele existente au articolele făcute înainte
    // de produse.
    //
    // Verificările sunt aceleași ca la creare, cuvânt cu cuvânt. O poartă care
    // răspunde altfel după ușa pe care ai intrat nu e o poartă, e o loterie.
    if (array_key_exists('productId', $b)) {
        $productId = null;
        // Gol înseamnă dezlegat: un articol legat greșit trebuie să poată fi și
        // desprins, nu doar mutat pe altul.
        if ($b['productId'] !== null && $b['productId'] !== '') {
            $productId = (int) $b['productId'];
            $produs    = one('SELECT id, name FROM products WHERE id = ?', [$productId]);
            if (!$produs) {
                fail('Produsul din catalog nu există. Alege altul sau lasă articolul fără produs.', 422, 'bad_product');
            }
            // `id <> ?` în plus față de creare: articolul deja legat de produsul
            // ăsta e el însuși, nu un rival. Fără excepție, o salvare care nu
            // schimbă produsul s-ar refuza singură.
            $deja = one(
                'SELECT id, name FROM stock_items WHERE product_id = ? AND active = 1 AND id <> ?',
                [$productId, $id]
            );
            if ($deja) {
                fail(
                    'Produsul „' . $produs['name'] . '” are deja articolul „' . $deja['name']
                    . '” în depozit. Fă intrările și ieșirile pe el — comenzile se scad tot de acolo.',
                    409,
                    'product_taken'
                );
            }
        }
        $sets[] = 'product_id = ?';
        $vals[] = $productId;
    }

    if (!$sets) {
        fail('Nimic de modificat.', 422, 'nothing_to_update');
    }

    $vals[] = $id;
    q_unique(
        'UPDATE stock_items SET ' . implode(', ', $sets) . ' WHERE id = ?',
        $vals,
        'Există deja un articol cu numele „' . $numeFinal . '”.',
        'name_taken'
    );
    audit('stock_item_update', 'stock_item', (string) $id, $item['name']);

    json_out(['ok' => true]);
}

/* --------------------------------------------------------------- mișcări -- */

/**
 * Miezul nopții unei zile locale, scris ca moment UTC.
 *
 * Registrul se ține în UTC — api/lib/db.php pune `SET time_zone = '+00:00'` pe
 * fiecare conexiune, iar `created_at` vine din CURRENT_TIMESTAMP — dar zilele
 * cerute de pagini sunt zile de calendar românești: Intrare.dc.html trimite
 * `gp.today()`, compus din ceasul tabletei. Cele două nu se pot compara direct,
 * deci traducem ziua locală în clipa de la care începe.
 *
 * `$plusZile` dă marginea de sus: începutul zilei URMĂTOARE. Așa intră și
 * ultima secundă a zilei, fără să depindem de forma lui 23:59:59.
 *
 * Fusul e scris aici, nu luat de la găzduire: patiseria e în București (vezi
 * api/lib/settings.php), iar ceasul serverului de cPanel poate fi oricare.
 * DateTimeZone ține singur socoteala orei de vară, deci iulie (+03) și
 * ianuarie (+02) ies amândouă corect.
 */
function stock_local_day_utc(string $day, int $plusZile = 0): string
{
    $d = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('Europe/Bucharest'));
    if ($plusZile !== 0) {
        $d = $d->modify('+' . $plusZile . ' day');
    }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** GET /stock/moves?from=&to=&item= — registrul. */
/**
 * DELETE /stock/items/{id} — scoate un articol din depozit.
 *
 * Ștergerea unui articol nu e o singură operație, fiindcă articolele nu sunt
 * toate la fel. Trei situații, trei răspunsuri:
 *
 *  1. **Intră într-o rețetă** → refuz, cu produsele numite. `fk_recipe_item` e
 *     ON DELETE RESTRICT, deci baza ar refuza oricum — dar cu „Eroare internă.",
 *     iar depozitarul n-ar afla că vinovată e rețeta unui produs. Rețeta se
 *     schimbă din pagina Produse, de un administrator; noi doar spunem unde.
 *
 *  2. **Are istoric** — mișcări în registru sau marfă rezervată pentru comenzi
 *     → articolul se SCOATE DIN LISTĂ (`active = 0`), nu se șterge. Rândurile
 *     din `stock_moves` poartă numele ca text, deci registrul rămâne lizibil,
 *     iar `fk_moves_item` e ON DELETE SET NULL: o ștergere adevărată ar tăia
 *     legătura și ar lăsa în urmă intrări care nu mai duc nicăieri. Registrul
 *     depozitului e o socoteală, nu o listă de curățat.
 *
 *  3. **Curat, fără nicio urmă** → se șterge de-a binelea. E cazul greșelii de
 *     tastare de acum două minute, iar acolo un articol „scos din listă", pe
 *     care nu-l mai vede nimeni dar care ocupă numele, ar fi doar gunoi ascuns:
 *     `uq_stock_items_name` l-ar împiedica pe om să scrie numele corect.
 *
 * Răspunsul spune ce s-a întâmplat (`mode`), ca pagina să nu ghicească și să
 * poată explica omului de ce articolul cu istoric n-a dispărut de tot.
 *
 * `require_staff()`, ca restul depozitului: cine poate face intrări și ieșiri
 * poate și să scoată un articol din listă — putea deja, prin PATCH cu
 * `active: false`. Ștergerea adevărată se face doar acolo unde n-are ce strica.
 */
function route_items_delete(string $id): void
{
    require_staff();
    $id = (int) $id;

    $item = one('SELECT id, name, active FROM stock_items WHERE id = ?', [$id]);
    if (!$item) {
        fail('Articolul nu există.', 404, 'not_found');
    }

    // 1. Rețetele. Le numim pe toate: cu un singur produs în mesaj, omul le-ar
    // descoperi pe rând, câte una la fiecare încercare.
    $retete = all(
        'SELECT p.name FROM product_recipes r
           JOIN products p ON p.id = r.product_id
          WHERE r.item_id = ?
          ORDER BY p.name',
        [$id]
    );
    if ($retete) {
        $nume = array_column($retete, 'name');
        fail(
            'Articolul „' . $item['name'] . '” intră în rețeta produsului '
            . (count($nume) === 1 ? '„' . $nume[0] . '”' : 'următoarelor produse: „' . implode('”, „', $nume) . '”')
            . '. Scoate-l întâi din rețetă, din pagina Produse.',
            409,
            'in_recipe'
        );
    }

    // 2. Istoricul: mișcări din registru sau marfă rezervată pentru comenzi.
    $miscari  = (int) scalar('SELECT COUNT(*) FROM stock_moves WHERE item_id = ?', [$id]);
    $rezervat = (int) scalar('SELECT COUNT(*) FROM stock_reservations WHERE item_id = ?', [$id]);

    if ($miscari > 0 || $rezervat > 0) {
        q('UPDATE stock_items SET active = 0 WHERE id = ?', [$id]);
        audit('stock_item_deactivate', 'stock_item', (string) $id, $item['name']);
        json_out([
            'ok'   => true,
            'mode' => 'deactivated',
            'message' => 'Articolul „' . $item['name'] . '” a fost scos din depozit. '
                . 'Nu s-a șters de tot fiindcă are ' . $miscari . ' '
                . ($miscari === 1 ? 'mișcare' : 'mișcări') . ' în registru'
                . ($rezervat > 0 ? ' și marfă rezervată pentru comenzi' : '')
                . ' — istoricul rămâne întreg.',
        ]);
    }

    // 3. Fără nicio urmă: se șterge.
    q('DELETE FROM stock_items WHERE id = ?', [$id]);
    audit('stock_item_delete', 'stock_item', (string) $id, $item['name']);
    json_out([
        'ok'   => true,
        'mode' => 'deleted',
        'message' => 'Articolul „' . $item['name'] . '” a fost șters.',
    ]);
}

function route_moves_list(): void
{
    require_staff();

    $where  = [];
    $params = [];

    // Tăiat cu `DATE(m.created_at)`, filtrul compara ziua UTC cu ziua locală
    // trimisă de pagină. Vara, o intrare de făină înregistrată la 01:30 e
    // scrisă cu 22:30 în ziua UTC precedentă, deci nu intra în „mișcări
    // înregistrate azi" de pe pagina de start — și rămânea nenumărată toată
    // ziua — în timp ce mișcările de după miezul nopții următoare intrau în
    // ea. Exact orele în care se lucrează pentru livrarea de 06:30.
    //
    // Comparăm deci pe clipă, între marginile zilei locale. Ca efect alăturat,
    // indicele `ix_moves_date` redevine folosibil: pe coloana brută, nu pe o
    // valoare calculată din ea.
    $from = trim((string) ($_GET['from'] ?? ''));
    if ($from !== '') {
        $where[]  = 'm.created_at >= ?';
        $params[] = stock_local_day_utc(valid_day($from));
    }
    $to = trim((string) ($_GET['to'] ?? ''));
    if ($to !== '') {
        $where[]  = 'm.created_at < ?';
        $params[] = stock_local_day_utc(valid_day($to), 1);
    }
    $item = trim((string) ($_GET['item'] ?? ''));
    if ($item !== '') {
        $where[]  = 'm.item_id = ?';
        $params[] = (int) $item;
    }

    $sql = 'SELECT m.*, u.name AS user_name, i.category, i.unit
              FROM stock_moves m
              LEFT JOIN users u       ON u.id = m.user_id
              LEFT JOIN stock_items i ON i.id = m.item_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY m.created_at DESC, m.id DESC LIMIT 1000';

    json_out(['moves' => array_map(static fn($r) => [
        'id'        => (int) $r['id'],
        'itemId'    => $r['item_id'] === null ? null : (int) $r['item_id'],
        'name'      => $r['name'],
        'category'  => $r['category'],
        'unit'      => $r['unit'] ?: 'buc',
        'qty'       => (float) $r['qty'],
        'direction' => (int) $r['direction'],
        'reason'    => $r['reason'],
        'note'      => $r['note'],
        'user'      => $r['user_name'],
        'createdAt' => $r['created_at'],
    ], all($sql, $params))]);
}

/** POST /stock/moves — intrare (direction 1) sau ieșire (-1). */
function route_moves_create(): void
{
    $u = require_staff();
    $b = body();

    $qty = num_field($b['qty'] ?? null, 'Cantitatea', 0, 1000000, 'bad_qty');
    if ($qty <= 0) {
        fail('Cantitatea trebuie să fie un număr pozitiv.', 422, 'bad_qty');
    }
    $direction = (int) ($b['direction'] ?? 1) >= 0 ? 1 : -1;

    $itemId = isset($b['itemId']) ? (int) $b['itemId'] : 0;
    $item   = $itemId ? one('SELECT id, name FROM stock_items WHERE id = ?', [$itemId]) : null;
    if (!$item) {
        fail('Articol de depozit inexistent.', 422, 'bad_item');
    }

    // `reason` e VARCHAR(96) și `note` VARCHAR(255). Un motiv lipit dintr-un
    // e-mail de la furnizor trecea neatins și se întorcea din MySQL ca 1406:
    // intrarea de marfă nu se înregistra, iar depozitarul nu afla de ce.
    $motiv = text_field($b['reason'] ?? null, 'Motivul', 96) ?: null;
    $notaM = text_field($b['note'] ?? null, 'Mențiunea', 255) ?: null;

    q(
        'INSERT INTO stock_moves (item_id, name, qty, direction, reason, note, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$item['id'], $item['name'], $qty, $direction, $motiv, $notaM, (int) $u['id']]
    );
    $id = (int) db()->lastInsertId();

    audit(
        'stock_move', 'stock', (string) $id,
        sprintf('%s %s × %s', $direction > 0 ? 'Intrare' : 'Ieșire', $qty, $item['name'])
    );

    json_out(['id' => $id], 201);
}

/* ------------------------------------------------- scăderea comenzilor -- */

/**
 * POST /stock/sync — scade din depozit comenzile încheiate.
 *
 * Se face aici, într-o singură tranzacție, din două motive:
 *
 * 1. Marcajul „deja scăzut” trebuie să fie pe server. Varianta veche îl ținea
 *    în pagina care apăsase butonul, deci se pierdea la reîncărcare: a doua
 *    apăsare scădea încă o dată aceleași produse, iar pe două calculatoare se
 *    scădea de două ori. Stocul cobora singur, fără ca nimeni să vadă de ce.
 *
 * 2. Scrierea mișcărilor una câte una putea să reușească pe jumătate și
 *    totuși să marcheze totul ca scăzut. Aici, dacă ceva pică, nu se scrie
 *    nimic.
 *
 * 3. Lista comenzilor se citește ÎN tranzacție, cu `FOR UPDATE`: altfel două
 *    apăsări în aceeași secundă — un dublu-clic, sau două tablete — citeau
 *    amândouă aceleași comenzi nescăzute și inserau fiecare setul complet de
 *    mișcări. Stocul cobora de două ori, iar al doilea marcaj nu semnala
 *    nimic. Cu blocarea rândurilor, a doua cerere așteaptă și apoi nu mai
 *    găsește nimic de scăzut.
 *
 * Comanda e unitatea de lucru: dacă vreo poziție a ei nu are articol în
 * depozit, nu se scade NIMIC din ea și rămâne nescăzută, ca să poată fi
 * reluată după ce se creează articolul. Varianta veche scădea ce găsea și
 * marca totuși comanda ca încheiată, deci restul nu se mai scădea niciodată.
 *
 * Legătura dintre poziția din comandă și articolul de depozit se face în
 * primul rând după `product_id`, nu după nume: numele se poate schimba, iar o
 * potrivire ratată ar însemna marfă ieșită din laborator care rămâne în
 * evidență. Numele e doar plasa de dedesubt, pentru articolele care n-au produs
 * în catalog, și se caută numai când nu s-a găsit niciunul prin `product_id` —
 * altfel un produs redenumit ar prinde două articole deodată și fiecare comandă
 * ar scădea de două ori.
 */
function route_stock_sync(): void
{
    $u    = require_staff();
    $done = status_done();
    if ($done === '') {
        fail('Nu este configurată etapa care încheie comanda.', 409, 'no_done_status');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $orders = all(
            'SELECT id, ref, customer_name FROM orders
              WHERE status = ? AND stock_synced = 0 ORDER BY id FOR UPDATE',
            [$done]
        );
        if (!$orders) {
            $pdo->commit();
            json_out(['orders' => 0, 'pieces' => 0, 'missing' => [], 'skipped' => []]);
        }

        $ids   = array_column($orders, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));

        // Un singur articol de depozit pentru fiecare poziție de comandă.
        //
        // Legătura avea două ramuri unite prin OR, iar un produs redenumit în
        // Catalog le aprindea pe amândouă: articolul vechi se potrivea pe
        // `product_id`, iar cel pe care îl adăuga depozitarul din „Articol nou"
        // — fără produs, fiindcă pagina nu trimitea `productId` — se potrivea
        // pe numele nou al poziției. LEFT JOIN întorcea atunci DOUĂ rânduri
        // pentru aceeași poziție, deci se scriau două mișcări: o comandă de opt
        // croasante scădea opt din articolul nou și încă opt din cel vechi, iar
        // comanda se marca scăzută și nu se mai putea relua. Depozitul arăta
        // șaisprezece bucăți ieșite pentru opt livrate și se cumpăra materie
        // primă pentru marfă care era în frigider.
        //
        // Alegem deci articolul cu o subinterogare care întoarce cel mult un
        // id: întâi cel legat prin `product_id` (numele se schimbă, produsul
        // nu), și abia dacă nu există niciunul căutăm după nume — ramura pe
        // nume rămâne pentru articolele care chiar n-au produs în catalog:
        // ambalaje, făină, cutii. Articolele scoase din depozit trec la urmă:
        // unul cu numele vechi, scos din listă după redenumire, nu mai apare
        // nicăieri în aplicație — dacă tocmai el ar prinde scăderea, singurul
        // articol pe care îl vede depozitarul n-ar mai coborî niciodată, iar
        // lipsa s-ar descoperi abia la inventar. La egalitate ia articolul cel
        // mai vechi, ca două rulări să scadă din același loc, nu din câte unul
        // pe rând.
        //
        // Costul: subinterogarea se reface pentru fiecare poziție (`EXPLAIN`
        // arată DEPENDENT SUBQUERY cu filesort). La scara unei zile de comenzi
        // sunt zeci de milisecunde; dacă vreodată contează, un index pe
        // `stock_items (product_id, id)` îl taie.
        $items = all(
            "SELECT oi.order_id, oi.name, oi.qty, oi.product_id,
                    si.id AS item_id, si.name AS item_name
               FROM order_items oi
               LEFT JOIN stock_items si
                      ON si.id = (
                           SELECT s.id
                             FROM stock_items s
                            WHERE (s.product_id = oi.product_id AND s.product_id IS NOT NULL)
                               OR (s.product_id IS NULL AND s.name = oi.name)
                            ORDER BY (s.active = 0), (s.product_id IS NULL), s.id
                            LIMIT 1
                         )
              WHERE oi.order_id IN ($marks)",
            $ids
        );

        $byOrder = array_column($orders, null, 'id');
        $missing = [];
        $blocate = [];
        $pieces  = 0.0;

        // Întâi aflăm ce comenzi sunt complete. O comandă cu o poziție fără
        // articol de depozit se lasă pe altă dată, întreagă.
        foreach ($items as $it) {
            if ($it['item_id'] === null) {
                $missing[$it['name']] = true;
                $blocate[(int) $it['order_id']] = true;
            }
        }

        foreach ($items as $it) {
            if ($it['item_id'] === null || isset($blocate[(int) $it['order_id']])) {
                continue;
            }
            $o    = $byOrder[$it['order_id']] ?? null;
            $note = trim((string) ($o['ref'] ?? '') . ' ' . (string) ($o['customer_name'] ?? ''));
            // `name` păstrează denumirea articolului la momentul mișcării: dacă
            // articolul se redenumește mai târziu, registrul vechi rămâne lizibil.
            q(
                'INSERT INTO stock_moves (item_id, name, qty, direction, reason, note, user_id)
                 VALUES (?, ?, ?, -1, ?, ?, ?)',
                [
                    (int) $it['item_id'], (string) $it['item_name'], (float) $it['qty'],
                    'Comandă client', $note, (int) $u['id'],
                ]
            );
            $pieces += (float) $it['qty'];
        }

        $scazute = array_values(array_filter($ids, static fn($id) => !isset($blocate[(int) $id])));

        // Marcăm comenzile abia după ce toate mișcările au intrat, și doar pe
        // cele care erau încă nescăzute — condiția e a doua plasă de siguranță
        // sub blocarea de mai sus.
        if ($scazute) {
            $mk = implode(',', array_fill(0, count($scazute), '?'));
            q("UPDATE orders SET stock_synced = 1 WHERE id IN ($mk) AND stock_synced = 0", $scazute);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('stock_sync', 'stock', null, count($scazute) . ' comenzi · ' . $pieces . ' buc.');

    json_out([
        'orders'  => count($scazute),
        'pieces'  => $pieces,
        // Spunem ce NU s-a scăzut: o scădere parțială raportată ca reușită ar
        // lăsa stocul greșit fără ca nimeni să observe.
        'missing' => array_keys($missing),
        // Câte comenzi au rămas pe loc din cauza articolelor lipsă. Fără
        // numărul ăsta, „0 comenzi scăzute" ar putea părea „nu era nimic".
        'skipped' => array_map('intval', array_keys($blocate)),
    ]);
}
