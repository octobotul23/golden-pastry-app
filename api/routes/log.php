<?php
/** Jurnalul de modificări. Doar `admin`. */

declare(strict_types=1);

/** GET /log?search=&page=&action= */
function route_log_list(): void
{
    require_role('admin');

    $where  = [];
    $params = [];

    $search = trim((string) ($_GET['search'] ?? ''));
    if ($search !== '') {
        $where[] = '(l.user_label LIKE ? OR l.action LIKE ? OR l.detail LIKE ?)';
        $like    = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $action = trim((string) ($_GET['action'] ?? ''));
    if ($action !== '') {
        $where[]  = 'l.action = ?';
        $params[] = $action;
    }

    $perPage = 50;
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total  = (int) scalar("SELECT COUNT(*) FROM audit_log l$clause", $params);

    // LIMIT/OFFSET se interpolează ca întregi validați: MySQL nu acceptă
    // parametri legați în această poziție.
    // Rolul vine din tabelul de conturi, prin legătură: în jurnal se păstrează
    // doar numele, ca înregistrarea să rămână lizibilă după ștergerea contului.
    // Fără el, coloana „Cont" din pagină arăta o liniuță la fiecare rând.
    $rows = all(
        "SELECT l.*, u.role
           FROM audit_log l
           LEFT JOIN users u ON u.id = l.user_id
         $clause ORDER BY l.id DESC LIMIT $perPage OFFSET $offset",
        $params
    );

    json_out([
        'entries' => array_map(static fn($r) => [
            'id'        => (int) $r['id'],
            'user'      => $r['user_label'],
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
