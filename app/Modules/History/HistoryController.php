<?php
declare(strict_types=1);

namespace App\Modules\History;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Connections\ConnectionRepository;

final class HistoryController
{
    public function index(Request $request): never
    {
        Response::html(View::render('history/index', [
            'title'       => 'Histórico',
            'active'      => 'history',
            'connections' => (new ConnectionRepository())->all(),
            'canSeeAll'   => Auth::isAdmin(),
        ]));
    }

    public function list(Request $request): never
    {
        $data = (new HistoryRepository())->paginate((int) Auth::id(), Auth::isAdmin(), [
            'status'        => $request->str('status'),
            'connection_id' => $request->int('connection_id'),
            'search'        => $request->str('search'),
            'scope'         => $request->str('scope', 'mine'),
        ], max(1, $request->int('page', 1)), min(200, max(10, $request->int('per_page', 50))));
        Response::json(['ok' => true] + $data);
    }

    public function show(Request $request): never
    {
        $row = (new HistoryRepository())->find((int) $request->param('id'), (int) Auth::id(), Auth::isAdmin())
            ?? throw new HttpException(404);
        Response::json(['ok' => true, 'entry' => $row]);
    }

    public function clear(Request $request): never
    {
        $n = (new HistoryRepository())->clear((int) Auth::id());
        Audit::log('history.clear', 'user', Auth::id(), ['rows' => $n]);
        Response::json(['ok' => true, 'deleted' => $n]);
    }
}
