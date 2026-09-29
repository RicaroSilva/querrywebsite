<?php
declare(strict_types=1);

namespace App\Modules\Editor;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Connections\ConnectionRepository;
use App\Modules\Exports\ExportController;
use App\Modules\Queries\FolderRepository;

final class EditorController
{
    public function index(Request $request): never
    {
        Response::html(View::render('editor/index', [
            'title'       => 'SQL Editor',
            'active'      => 'editor',
            'connections' => (new ConnectionRepository())->all(),
            'folders'     => (new FolderRepository())->all(),
            'formats'     => ExportController::FORMATS,
            'canWrite'    => Auth::can('queries.write'),
            'canSave'     => Auth::can('queries.manage'),
            'boot'        => [
                'query'      => $request->int('query') ?: null,
                'history'    => $request->int('history') ?: null,
                'connection' => $request->int('connection') ?: null,
                'database'   => $request->str('database') ?: null,
                'sql'        => mb_substr($request->str('sql'), 0, 20000) ?: null,
                'run'        => $request->bool('run'),
            ],
            'flush'       => true,
        ], 'layouts/app'));
    }
}
