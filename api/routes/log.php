<?php
/** Jurnalul de modificări. Doar `admin`. */

declare(strict_types=1);

/** GET /log?search=&page=&action= */
function route_log_list(): void
{
    require_admin();

    $where  = [];
    $params = [];

    $search = trim((string) ($_GET['search'] ?? ''));
    if ($search !== '') {
        // Numele se caută și prin legătura spre `users`, nu numai în ce s-a
        // scris atunci în jurnal: rândurile mai vechi de reparația din
        // `audit()` au `user_label` gol, iar fără COALESCE o căutare după
        // numele omului le arunca din numărătoare cu totul.
        $where[] = '(COALESCE(l.user_label, u.name) LIKE ? OR l.action LIKE ? OR l.detail LIKE ?)';
        $like    = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $action = trim((string) ($_GET['action'] ?? ''));
    if ($action !== '') {
        $where[]  = 'l.action = ?';
        $params[] = $action;
    }

    $perPage = 50;
    // Plafonat: `?page=99999999999999999999` dădea (int) PHP_INT_MAX, iar
    // înmulțirea trecea în float — „OFFSET 4.6E+20" e eroare de sintaxă SQL,
    // deci 500 pentru un număr de pagină. Peste un milion de pagini nu are
    // jurnalul, și oricum răspunsul e gol.
    $page    = min(1000000, max(1, (int) ($_GET['page'] ?? 1)));
    $offset  = ($page - 1) * $perPage;

    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    // Aceeași legătură ca la citire: `$clause` poate cuprinde `u.name`, iar
    // o numărătoare fără JOIN ar pica pe o coloană necunoscută.
    $total  = (int) scalar(
        "SELECT COUNT(*) FROM audit_log l LEFT JOIN users u ON u.id = l.user_id$clause",
        $params
    );

    // LIMIT/OFFSET se interpolează ca întregi validați: MySQL nu acceptă
    // parametri legați în această poziție.
    // Rolul vine din tabelul de conturi, prin legătură: în jurnal se păstrează
    // doar numele, ca înregistrarea să rămână lizibilă după ștergerea contului.
    // Fără el, coloana „Cont" din pagină arăta o liniuță la fiecare rând.
    $rows = all(
        "SELECT l.*, u.role, u.name AS user_name
           FROM audit_log l
           LEFT JOIN users u ON u.id = l.user_id
         $clause ORDER BY l.id DESC LIMIT $perPage OFFSET $offset",
        $params
    );

    json_out([
        'entries' => array_map(static fn($r) => [
            'id'        => (int) $r['id'],
            // Numele din jurnal, iar dacă lipsește (rând scris înainte de
            // reparația din `audit()`), cel de pe contul legat. Numai când nu
            // există nici contul rămâne gol, iar pagina scrie „Sistem" —
            // atunci chiar e adevărat.
            'user'      => $r['user_label'] ?? $r['user_name'],
            'role'      => $r['role'],
            'action'    => $r['action'],
            'entity'    => $r['entity'],
            'entityId'  => $r['entity_id'],
            'detail'    => $r['detail'],
            'ip'        => $r['ip'],
            'createdAt' => $r['created_at'],
        ], $rows),
        'total'   => $total,
        'page'    => $page,
        'pages'   => (int) ceil($total / $perPage),
    ]);
}
