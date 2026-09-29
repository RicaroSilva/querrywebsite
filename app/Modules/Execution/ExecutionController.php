<?php
declare(strict_types=1);

namespace App\Modules\Execution;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Connections\ConnectionRepository;
use App\Modules\Queries\QueryRepository;

final class ExecutionController
{
    public function execute(Request $request): never
    {
        $sql = (string) $request->input('sql', '');
        if (trim($sql) === '') {
            throw new HttpException(422, 'Escreva uma query para executar.');
        }
        if (strlen($sql) > 5 * 1024 * 1024) {
            throw new HttpException(422, 'Script demasiado grande (máx. 5 MB).');
        }
        $conn = (new ConnectionRepository())->findOrFail($request->int('connection_id'));
        $execId = preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $request->str('execution_id')) ? $request->str('execution_id') : null;
        $savedId = $request->int('saved_query_id') ?: null;
        $maxRows = $request->int('max_rows') ?: null;
        $database = $request->str('database') ?: null;
        $userId = (int) Auth::id();
        $readOnly = !Auth::can('queries.write');
        $placeholders = [];
        foreach ((array) $request->input('params', []) as $k => $v) {
            if (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k) && (is_scalar($v) || $v === null)) {
                $placeholders[$k] = $v;
            }
        }

        // Long-running query: release the session lock so the user can cancel / keep browsing.
        Session::release();

        $result = (new QueryExecutor())->run($conn, $sql, [
            'user_id'        => $userId,
            'execution_id'   => $execId,
            'database'       => $database,
            'read_only'      => $readOnly,
            'saved_query_id' => $savedId,
            'line_offset'    => max(0, $request->int('line_offset')),
            'max_rows'       => $maxRows ? min($maxRows, (int) config('query.max_rows', 10000)) : null,
        ] + (Placeholders::names($sql) ? ['placeholder_values' => $placeholders] : []));
        if ($savedId) {
            (new QueryRepository())->markRun($savedId);
        }
        Response::json(['ok' => true] + $result);
    }

    public function cancel(Request $request): never
    {
        $execId = (string) $request->param('exec');
        $running = RunningRegistry::get($execId);
        if (!$running) {
            Response::json(['ok' => false, 'error' => 'A execução já terminou.']);
        }
        if ((int) $running['user_id'] !== Auth::id() && !Auth::isAdmin()) {
            throw new HttpException(403);
        }
        Session::release();
        RunningRegistry::markCancelled($execId);
        $conn = (new ConnectionRepository())->findOrFail((int) $running['connection_id']);
        try {
            $driver = (new QueryExecutor())->driverFor($conn, $running['database'] ?? null);
            if (!($driver::capabilities()['cancel'] ?? false)) {
                Response::json(['ok' => false, 'error' => 'Este tipo de base de dados não suporta cancelamento.']);
            }
            $ok = $driver->cancel((string) $running['backend']);
            Response::json(['ok' => $ok, 'message' => $ok ? 'Pedido de cancelamento enviado.' : 'Não foi possível cancelar.']);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'Falha ao cancelar: ' . $e->getMessage()]);
        }
    }

    public function page(Request $request): never
    {
        Session::release();
        Response::json(['ok' => true] + ResultStore::query((string) $request->param('rid'), (int) Auth::id(), [
            'page'     => $request->int('page', 1),
            'per_page' => $request->int('per_page', (int) config('query.page_size', 100)),
            'sort'     => $request->query('sort'),
            'dir'      => $request->query('dir'),
            'search'   => $request->query('search'),
            'filters'  => is_array($request->query('filters')) ? $request->query('filters') : [],
        ]));
    }

    /** Tab-separated copy of the (filtered) cached result, for pasting into Excel/Sheets. */
    public function copy(Request $request): never
    {
        Session::release();
        $rid = (string) $request->param('rid');
        $meta = ResultStore::meta($rid, (int) Auth::id());
        $rows = ResultStore::filtered($rid, mb_strtolower(trim((string) $request->query('search', ''))),
            array_filter(is_array($request->query('filters')) ? $request->query('filters') : [], static fn($v) => $v !== ''));
        $clean = static fn($v) => $v === null ? '' : str_replace(["\t", "\r\n", "\n"], [' ', ' ', ' '], (string) (is_bool($v) ? ($v ? 'true' : 'false') : $v));
        $lines = [implode("\t", array_map($clean, array_column($meta['columns'], 'name')))];
        foreach ($rows as $r) {
            $lines[] = implode("\t", array_map($clean, $r));
        }
        Response::json(['ok' => true, 'text' => implode("\n", $lines), 'rows' => count($rows)]);
    }
}
