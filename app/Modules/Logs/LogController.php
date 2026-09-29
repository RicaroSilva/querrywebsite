<?php
declare(strict_types=1);

namespace App\Modules\Logs;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/** Audit log viewer (admins). */
final class LogController
{
    public function index(Request $request): never
    {
        $page = max(1, $request->int('page', 1));
        $per = 50;
        $db = Database::get();
        $where = '';
        $params = [];
        if ($request->str('action') !== '') {
            $where = 'WHERE a.action LIKE ?';
            $params[] = $request->str('action') . '%';
        }
        $total = (int) $db->value("SELECT COUNT(*) FROM audit_logs a $where", $params);
        $rows = $db->fetchAll("SELECT a.*, u.name AS user_name, u.email FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
            $where ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
        Response::html(View::render('logs/index', [
            'title'  => 'Logs de auditoria',
            'active' => 'settings',
            'rows'   => $rows,
            'page'   => $page,
            'pages'  => max(1, (int) ceil($total / $per)),
            'total'  => $total,
            'action' => $request->str('action'),
        ]));
    }
}
