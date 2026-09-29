<?php
declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\HttpException;
use App\Modules\Connections\ConnectionRepository;
use App\Modules\Execution\QueryExecutor;
use App\Modules\Queries\QueryRepository;

/**
 * Runs report widgets. Report SQL is ALWAYS executed read-only.
 *
 * Filters: a report defines filters (name/type/default). Widget SQL references
 * them as {{name}}; each placeholder becomes a bound parameter (see
 * Execution\Placeholders), e.g.   WHERE created_at >= {{start_date}}
 */
final class ReportService
{
    public const MAX_WIDGET_ROWS = 2000;

    public function run(array $report, array $widget, array $filterValues, int $userId): array
    {
        if ($widget['type'] === 'text') {
            return ['type' => 'text'];
        }
        $sql = $widget['sql_text'];
        $connectionId = $widget['connection_id'];
        if ($widget['saved_query_id']) {
            $q = (new QueryRepository())->find($widget['saved_query_id']);
            if (!$q) {
                throw new HttpException(422, 'A query guardada associada foi eliminada.');
            }
            $sql = $q['sql_text'];
            $connectionId = $connectionId ?: $q['connection_id'];
        }
        if (!$sql || trim($sql) === '') {
            throw new HttpException(422, 'Componente sem SQL definido.');
        }
        if (!$connectionId) {
            throw new HttpException(422, 'Componente sem conexão definida.');
        }
        $conn = (new ConnectionRepository())->findOrFail((int) $connectionId);
        $result = (new QueryExecutor())->run($conn, $sql, [
            'user_id'            => $userId,
            'read_only'          => true,
            'placeholder_defs'   => $report['filters'] ?? [],
            'placeholder_values' => $filterValues,
            'max_rows'           => self::MAX_WIDGET_ROWS,
            'page_size'          => self::MAX_WIDGET_ROWS,
            'record_history'     => false,
            'database'           => (((array) $widget['config'])['database'] ?? null) ?: null,
        ]);
        // Use the last statement that returned rows
        $data = null;
        foreach ($result['results'] as $r) {
            if ($r['type'] === 'error') {
                return ['type' => 'error', 'error' => $r['error']['message'], 'duration_ms' => $result['duration_ms']];
            }
            if ($r['type'] === 'rows') {
                $data = $r;
            }
        }
        if (!$data) {
            return ['type' => 'error', 'error' => 'A query não devolveu linhas (use um SELECT).'];
        }
        return [
            'type'        => 'rows',
            'columns'     => $data['columns'],
            'rows'        => $data['rows'],
            'row_count'   => $data['row_count'],
            'truncated'   => $data['truncated'],
            'duration_ms' => $result['duration_ms'],
            'connection'  => $conn['name'],
        ];
    }
}
