<?php
declare(strict_types=1);

namespace App\Modules\Explorer;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Connections\ConnectionRepository;

final class ExplorerController
{
    public function index(Request $request): never
    {
        Response::html(View::render('explorer/index', [
            'title'       => 'Bases de Dados',
            'active'      => 'databases',
            'connections' => (new ConnectionRepository())->all(),
            'flush'       => true,
        ]));
    }

    public function tree(Request $request): never
    {
        Session::release();
        $service = $this->service($request);
        try {
            Response::json(['ok' => true, 'nodes' => $service->children($this->node($request))]);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $this->clean($e)], 200);
        }
    }

    public function object(Request $request): never
    {
        Session::release();
        $service = $this->service($request);
        try {
            Response::json(['ok' => true, 'object' => $service->describe($this->node($request))]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => $this->clean($e)], 200);
        }
    }

    public function databases(Request $request): never
    {
        Session::release();
        $service = $this->service($request);
        try {
            Response::json(['ok' => true, 'databases' => $service->databaseList()]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'databases' => [], 'error' => $this->clean($e)], 200);
        }
    }

    public function completion(Request $request): never
    {
        Session::release();
        $service = $this->service($request);
        try {
            Response::json(['ok' => true, 'tables' => $service->completion($request->str('database') ?: null, $request->str('schema') ?: null)]);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'tables' => [], 'error' => $this->clean($e)], 200);
        }
    }

    private function service(Request $request): ExplorerService
    {
        $conn = (new ConnectionRepository())->findOrFail((int) $request->param('id'));
        return new ExplorerService($conn);
    }

    private function node(Request $request): array
    {
        $node = [];
        foreach (['kind', 'database', 'schema', 'name', 'group', 'key'] as $k) {
            $v = $request->query($k);
            $node[$k] = is_string($v) && $v !== '' ? mb_substr($v, 0, 256) : null;
        }
        $node['kind'] ??= 'root';
        return $node;
    }

    private function clean(\Throwable $e): string
    {
        return preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[[^\]]*\]\s*)*/', '', $e->getMessage());
    }
}
