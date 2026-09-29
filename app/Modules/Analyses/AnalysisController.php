<?php
declare(strict_types=1);

namespace App\Modules\Analyses;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Modules\Connections\ConnectionRepository;

final class AnalysisController
{
    public function index(Request $request): never
    {
        Response::html(View::render('analyses/index', [
            'title'       => 'Análises',
            'active'      => 'analyses',
            'analyses'    => (new AnalysisRepository())->all(),
            'connections' => (new ConnectionRepository())->all(),
            'canManage'   => Auth::can('analyses.manage'),
            'selected'    => $request->int('id') ?: null,
        ]));
    }

    public function list(Request $request): never
    {
        Response::json(['ok' => true, 'analyses' => (new AnalysisRepository())->all()]);
    }

    private function validated(Request $request): array
    {
        $d = Validator::validate($request->all(), [
            'name'          => 'required|string|max:190',
            'description'   => 'nullable|string|max:5000',
            'category'      => 'nullable|string|max:120',
            'connection_id' => 'required|int',
            'kind'          => 'required|in:function,query',
            'function_name' => 'nullable|string|max:255',
            'sql_text'      => 'nullable|string',
        ]);
        $conn = (new ConnectionRepository())->findOrFail($d['connection_id']);
        $d['params'] = AnalysisService::normaliseParams((array) $request->input('params', []), $d['kind']);
        if ($d['kind'] === 'function') {
            if ($conn['driver'] !== 'pgsql') {
                throw new HttpException(422, 'Funções só em conexões PostgreSQL.', ['kind' => 'Use "Query SQL".']);
            }
            AnalysisService::qualified((string) $d['function_name']);
        } else {
            if (!trim((string) $d['sql_text'])) {
                throw new HttpException(422, 'Escreva o SQL.', ['sql_text' => 'Obrigatório']);
            }
            [$sql] = (new AnalysisService())->build(['kind' => 'query', 'sql_text' => $d['sql_text'], 'params' => []], [], $conn['driver']);
            AnalysisService::assertSingleReadOnly($sql, $conn['driver']);
        }
        return $d;
    }

    public function store(Request $request): never
    {
        $id = (new AnalysisRepository())->save(null, $this->validated($request));
        Audit::log('analysis.create', 'analysis', $id);
        Response::json(['ok' => true, 'analysis' => (new AnalysisRepository())->find($id)], 201);
    }

    public function update(Request $request): never
    {
        $repo = new AnalysisRepository();
        $id = (int) $request->param('id');
        $repo->findOrFail($id);
        $repo->save($id, $this->validated($request));
        Audit::log('analysis.update', 'analysis', $id);
        Response::json(['ok' => true, 'analysis' => $repo->find($id)]);
    }

    public function destroy(Request $request): never
    {
        $id = (int) $request->param('id');
        (new AnalysisRepository())->findOrFail($id);
        (new AnalysisRepository())->delete($id);
        Audit::log('analysis.delete', 'analysis', $id);
        Response::json(['ok' => true]);
    }

    public function run(Request $request): never
    {
        $a = (new AnalysisRepository())->findOrFail((int) $request->param('id'));
        if (!$a['connection_id']) {
            throw new HttpException(422, 'A análise não tem conexão associada.');
        }
        $conn = (new ConnectionRepository())->findOrFail($a['connection_id']);
        $values = array_filter((array) $request->input('values', []), static fn($v) => is_scalar($v) || $v === null);
        Audit::log('analysis.run', 'analysis', $a['id'], ['values' => $values]);
        Session::release();
        $r = (new AnalysisService())->run($a, $conn, $values, (int) Auth::id());
        Response::json(['ok' => true] + $r);
    }

    /** Preview the SQL that would be executed (shown in the UI). */
    public function preview(Request $request): never
    {
        $a = (new AnalysisRepository())->findOrFail((int) $request->param('id'));
        $conn = (new ConnectionRepository())->findOrFail((int) $a['connection_id']);
        [$sql, $params] = (new AnalysisService())->build($a, (array) $request->input('values', []), $conn['driver']);
        Response::json(['ok' => true, 'sql' => $sql, 'params' => $params]);
    }

    public function functions(Request $request): never
    {
        $conn = (new ConnectionRepository())->findOrFail((int) $request->param('cid'));
        Session::release();
        try {
            Response::json(['ok' => true, 'functions' => (new AnalysisService())->listFunctions($conn, $request->str('database') ?: null)]);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'functions' => [], 'error' => preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[[^\]]*\]\s*)*/', '', $e->getMessage())]);
        }
    }

    public function describeFunction(Request $request): never
    {
        $conn = (new ConnectionRepository())->findOrFail((int) $request->param('cid'));
        Session::release();
        Response::json(['ok' => true] + (new AnalysisService())->describeFunction($conn, $request->str('database') ?: null, $request->str('oid')));
    }
}
