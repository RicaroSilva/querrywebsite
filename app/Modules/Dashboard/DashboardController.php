<?php
declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Connections\ConnectionRepository;
use App\Modules\History\HistoryRepository;
use App\Modules\Queries\QueryRepository;
use App\Modules\Reports\ReportRepository;

final class DashboardController
{
    public function index(Request $request): never
    {
        $uid = (int) Auth::id();
        $db = Database::get();
        $history = new HistoryRepository();
        $today = date('Y-m-d 00:00:00');
        Response::html(View::render('dashboard/index', [
            'title'       => 'Dashboard',
            'active'      => 'dashboard',
            'counts'      => [
                'connections' => (new ConnectionRepository())->count(),
                'queries'     => (new QueryRepository())->count(),
                'reports'     => (new ReportRepository())->count(),
                'today'       => (int) $db->value('SELECT COUNT(*) FROM query_history WHERE user_id = ? AND created_at >= ?', [$uid, $today]),
                'errors'      => (int) $db->value("SELECT COUNT(*) FROM query_history WHERE user_id = ? AND created_at >= ? AND status <> 'success'", [$uid, $today]),
                'avg_ms'      => (int) $db->value("SELECT AVG(duration_ms) FROM query_history WHERE user_id = ? AND status = 'success'", [$uid]),
            ],
            'recent'      => $history->recent($uid, 8),
            'activity'    => $history->stats($uid),
            'favorites'   => (new QueryRepository())->favorites(6),
            'connections' => array_slice((new ConnectionRepository())->all(), 0, 8),
            'reports'     => array_slice((new ReportRepository())->all(), 0, 5),
        ]));
    }
}
