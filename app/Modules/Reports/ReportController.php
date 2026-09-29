<?php
declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Modules\Connections\ConnectionRepository;
use App\Modules\Queries\QueryRepository;

final class ReportController
{
    private ReportRepository $repo;

    public function __construct()
    {
        $this->repo = new ReportRepository();
    }

    public function index(Request $request): never
    {
        Response::html(View::render('reports/index', [
            'title'   => 'Relatórios',
            'active'  => 'reports',
            'reports' => $this->repo->all(),
        ]));
    }

    public function show(Request $request): never
    {
        $report = $this->repo->findOrFail((int) $request->param('id'));
        Response::html(View::render('reports/show', [
            'title'       => $report['name'],
            'active'      => 'reports',
            'report'      => $report,
            'widgets'     => $this->repo->widgets((int) $report['id']),
            'types'       => ReportRepository::WIDGET_TYPES,
            'queries'     => (new QueryRepository())->search(['sort' => 'name']),
            'connections' => (new ConnectionRepository())->all(),
            'canManage'   => Auth::can('reports.manage'),
        ]));
    }

    public function get(Request $request): never
    {
        $r = $this->repo->findOrFail((int) $request->param('id'));
        Response::json(['ok' => true, 'report' => $r, 'widgets' => $this->repo->widgets((int) $r['id'])]);
    }

    private function validatedReport(Request $request): array
    {
        $d = Validator::validate($request->all(), ['name' => 'required|string|max:190', 'description' => 'nullable|string|max:5000']);
        $filters = [];
        foreach ((array) $request->input('filters', []) as $f) {
            if (!is_array($f) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', (string) ($f['name'] ?? ''))) {
                continue;
            }
            $filters[] = [
                'name'    => $f['name'],
                'label'   => mb_substr((string) ($f['label'] ?? $f['name']), 0, 80),
                'type'    => in_array($f['type'] ?? '', ['text', 'number', 'date', 'select'], true) ? $f['type'] : 'text',
                'default' => mb_substr((string) ($f['default'] ?? ''), 0, 200),
                'options' => array_slice(array_map(static fn($o) => mb_substr((string) $o, 0, 100), (array) ($f['options'] ?? [])), 0, 100),
            ];
        }
        $d['filters'] = $filters;
        return $d;
    }

    public function store(Request $request): never
    {
        $id = $this->repo->create($this->validatedReport($request));
        Audit::log('report.create', 'report', $id);
        Response::json(['ok' => true, 'id' => $id, 'url' => url('reports/' . $id)], 201);
    }

    public function fromQuery(Request $request): never
    {
        $q = (new QueryRepository())->findOrFail((int) $request->param('qid'));
        $id = $this->repo->create(['name' => $q['name'], 'description' => $q['description']]);
        $this->repo->addWidget($id, ['type' => 'table', 'title' => $q['name'], 'saved_query_id' => (int) $q['id'],
            'connection_id' => $q['connection_id'], 'width' => 12, 'config' => []]);
        Audit::log('report.create', 'report', $id, ['from_query' => $q['id']]);
        Response::json(['ok' => true, 'id' => $id, 'url' => url('reports/' . $id)], 201);
    }

    public function update(Request $request): never
    {
        $id = (int) $request->param('id');
        $this->repo->findOrFail($id);
        $this->repo->update($id, $this->validatedReport($request));
        Response::json(['ok' => true]);
    }

    public function destroy(Request $request): never
    {
        $id = (int) $request->param('id');
        $r = $this->repo->findOrFail($id);
        $this->repo->delete($id);
        Audit::log('report.delete', 'report', $id, ['name' => $r['name']]);
        Response::json(['ok' => true]);
    }

    public function duplicate(Request $request): never
    {
        $id = $this->repo->duplicate((int) $request->param('id'));
        Response::json(['ok' => true, 'id' => $id, 'url' => url('reports/' . $id)], 201);
    }

    private function validatedWidget(Request $request): array
    {
        $d = Validator::validate($request->all(), [
            'type'           => 'required|in:' . implode(',', array_keys(ReportRepository::WIDGET_TYPES)),
            'title'          => 'nullable|string|max:190',
            'saved_query_id' => 'nullable|int',
            'connection_id'  => 'nullable|int',
            'sql_text'       => 'nullable|string',
            'width'          => 'nullable|int',
        ]);
        $cfg = (array) $request->input('config', []);
        $allowed = ['label_col', 'value_col', 'value_cols', 'format', 'prefix', 'suffix', 'decimals', 'text',
            'limit', 'stacked', 'horizontal', 'database', 'subtitle', 'color'];
        $d['config'] = array_intersect_key($cfg, array_flip($allowed));
        if (isset($d['config']['text'])) {
            $d['config']['text'] = mb_substr((string) $d['config']['text'], 0, 20000);
        }
        if ($d['type'] !== 'text' && !$d['saved_query_id'] && !trim((string) $d['sql_text'])) {
            throw new HttpException(422, 'Escolha uma query guardada ou escreva SQL.', ['sql_text' => 'Obrigatório']);
        }
        if ($d['type'] !== 'text' && !$d['saved_query_id'] && !$d['connection_id']) {
            throw new HttpException(422, 'Escolha a conexão.', ['connection_id' => 'Obrigatório']);
        }
        return $d;
    }

    public function widgetStore(Request $request): never
    {
        $rid = (int) $request->param('id');
        $this->repo->findOrFail($rid);
        $wid = $this->repo->addWidget($rid, $this->validatedWidget($request));
        Response::json(['ok' => true, 'widget' => $this->repo->widget($rid, $wid)], 201);
    }

    public function widgetUpdate(Request $request): never
    {
        $rid = (int) $request->param('id');
        $wid = (int) $request->param('wid');
        $this->repo->widget($rid, $wid);
        $this->repo->updateWidget($rid, $wid, $this->validatedWidget($request));
        Response::json(['ok' => true, 'widget' => $this->repo->widget($rid, $wid)]);
    }

    public function widgetDestroy(Request $request): never
    {
        $this->repo->deleteWidget((int) $request->param('id'), (int) $request->param('wid'));
        Response::json(['ok' => true]);
    }

    public function reorder(Request $request): never
    {
        $this->repo->reorder((int) $request->param('id'), array_map('intval', (array) $request->input('ids', [])));
        Response::json(['ok' => true]);
    }

    public function widgetData(Request $request): never
    {
        $report = $this->repo->findOrFail((int) $request->param('id'));
        $widget = $this->repo->widget((int) $report['id'], (int) $request->param('wid'));
        Session::release();
        try {
            $data = (new ReportService())->run($report, $widget, (array) $request->input('filters', []), (int) Auth::id());
        } catch (HttpException $e) {
            $data = ['type' => 'error', 'error' => $e->getMessage()];
        }
        Response::json(['ok' => true, 'data' => $data]);
    }
}
