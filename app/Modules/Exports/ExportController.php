<?php
declare(strict_types=1);

namespace App\Modules\Exports;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Connections\ConnectionRepository;
use App\Modules\Execution\QueryExecutor;
use App\Modules\Execution\ResultStore;
use App\Modules\Execution\SqlSplitter;
use App\Modules\Exports\Exporters\CsvExporter;
use App\Modules\Exports\Exporters\Exporter;
use App\Modules\Exports\Exporters\JsonExporter;
use App\Modules\Exports\Exporters\MarkdownExporter;
use App\Modules\Exports\Exporters\PdfExporter;
use App\Modules\Exports\Exporters\SqlExporter;
use App\Modules\Exports\Exporters\XlsxExporter;

/**
 * Streams a result set to a file download.
 *
 * scope=all  → re-executes the original statement (read-only, streamed, no row cap)
 *              — only allowed for read-only statements (SELECT/WITH/...).
 * scope=view → exports the cached rows with the grid's current search/filters/sort.
 */
final class ExportController
{
    public const FORMATS = ['csv' => 'CSV', 'xlsx' => 'Excel (XLSX)', 'json' => 'JSON', 'sql' => 'SQL INSERTs', 'pdf' => 'PDF', 'md' => 'Markdown'];

    public function export(Request $request): never
    {
        $rid = $request->str('result_id');
        $userId = (int) Auth::id();
        $meta = ResultStore::meta($rid, $userId);
        Session::release();

        $format = $request->str('format', 'csv');
        $exporter = $this->exporter($format, $request, $meta);
        $scope = $request->str('scope', 'all');
        if ($scope === 'all' && !SqlSplitter::isReadOnly($meta['sql'])) {
            $scope = 'view'; // never re-run statements that could modify data
        }

        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $request->str('filename') ?: 'querydeck_' . date('Ymd_His'));
        Audit::log('export.run', 'result', $rid, ['format' => $format, 'scope' => $scope, 'connection_id' => $meta['connection_id']]);

        set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if ($scope === 'all') {
            $conn = (new ConnectionRepository())->findOrFail((int) $meta['connection_id']);
            $gen = (new QueryExecutor())->stream($conn, $meta['sql'], $meta['database'] ?? null,
                max((int) config('query.timeout', 60), 300), $meta['params'] ?? []);
            // Execute first so SQL errors can still produce a JSON/HTML error instead of a broken file
            try {
                $columns = $gen->current();
            } catch (\Throwable $e) {
                throw new HttpException(500, 'Erro ao exportar: ' . $e->getMessage());
            }
            Response::download($base . '.' . $exporter->extension(), $exporter->contentType());
            $exporter->begin($columns ?: $meta['columns']);
            $gen->next();
            while ($gen->valid()) {
                $exporter->row($gen->current());
                $gen->next();
            }
            $exporter->end();
            exit;
        }

        $filters = json_decode($request->str('filters', '{}'), true) ?: [];
        $opts = ['search' => $request->str('search'), 'filters' => $filters,
            'sort' => $request->input('sort'), 'dir' => $request->str('dir'), 'page' => 1, 'per_page' => 1000];
        $rows = ResultStore::filtered($rid, mb_strtolower(trim($opts['search'])), array_filter($filters, static fn($v) => $v !== ''));
        if ($opts['sort'] !== null && $opts['sort'] !== '') {
            $s = (int) $opts['sort'];
            $d = strtolower($opts['dir']) === 'desc' ? -1 : 1;
            usort($rows, static fn($a, $b) => (is_numeric($a[$s]) && is_numeric($b[$s]) ? $a[$s] <=> $b[$s] : strnatcasecmp((string) $a[$s], (string) $b[$s])) * $d);
        }
        Response::download($base . '.' . $exporter->extension(), $exporter->contentType());
        $exporter->begin($meta['columns']);
        foreach ($rows as $row) {
            $exporter->row($row);
        }
        $exporter->end();
        exit;
    }

    private function exporter(string $format, Request $request, array $meta): Exporter
    {
        $conn = (new ConnectionRepository())->find((int) $meta['connection_id']);
        $delimiter = match ($request->str('delimiter', ',')) {
            ';' => ';', 'tab', "\t" => "\t", '|' => '|', default => ',',
        };
        return match ($format) {
            'csv'  => new CsvExporter(['delimiter' => $delimiter, 'header' => $request->input('header', '1') !== '0',
                        'bom' => $request->bool('bom'), 'safe' => $request->bool('safe')]),
            'xlsx' => new XlsxExporter(['sheet' => $request->str('sheet', 'Resultados')]),
            'json' => new JsonExporter(['pretty' => $request->bool('pretty')]),
            'sql'  => new SqlExporter(['table' => $request->str('table', 'export_table'), 'dialect' => $conn['driver'] ?? 'pgsql']),
            'pdf'  => new PdfExporter(['title' => $request->str('title') ?: ($conn['name'] ?? 'QueryDeck')]),
            'md'   => new MarkdownExporter(),
            default => throw new HttpException(422, 'Formato de exportação inválido.'),
        };
    }
}
