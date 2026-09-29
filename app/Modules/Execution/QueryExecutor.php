<?php
declare(strict_types=1);

namespace App\Modules\Execution;

use App\Core\Logger;
use App\Modules\Connections\ConnectionRepository;
use App\Modules\Drivers\DriverInterface;
use App\Modules\History\HistoryRepository;

/**
 * Executes SQL scripts against an external connection.
 *
 * - splits the script into statements (driver-specific rules)
 * - applies statement timeout + read-only safety net
 * - streams each result set into the ResultStore (capped at max_rows),
 *   returning only the first page to the browser
 * - records the execution in the history
 */
final class QueryExecutor
{
    public function __construct(
        private ConnectionRepository $connections = new ConnectionRepository(),
        private HistoryRepository $history = new HistoryRepository(),
    ) {
    }

    /**
     * @param array $opts database, read_only, timeout, max_rows, page_size, params, user_id,
     *                    execution_id, saved_query_id, record_history, line_offset
     */
    public function run(array $conn, string $sql, array $opts = []): array
    {
        $userId = (int) $opts['user_id'];
        $execId = $opts['execution_id'] ?? bin2hex(random_bytes(8));
        $timeout = (int) ($opts['timeout'] ?? config('query.timeout', 60));
        $maxRows = max(1, (int) ($opts['max_rows'] ?? config('query.max_rows', 10000)));
        $pageSize = (int) ($opts['page_size'] ?? config('query.page_size', 100));
        $readOnly = !empty($opts['read_only']) || !empty($conn['read_only']);
        $usePlaceholders = array_key_exists('placeholder_values', $opts);
        $phValues = (array) ($opts['placeholder_values'] ?? []);
        $phDefs = (array) ($opts['placeholder_defs'] ?? []);
        $lineOffset = (int) ($opts['line_offset'] ?? 0);

        set_time_limit($timeout > 0 ? $timeout + 30 : 0);
        $started = microtime(true);
        $results = [];
        $status = 'success';
        $errorMessage = null;
        $totalRows = 0;
        $database = $opts['database'] ?? null;

        try {
            $driver = $this->connections->driver($conn, $database);
            $driver->prepareSession($timeout, $readOnly);
            try {
                $backend = $driver->backendId();
                if ($backend !== null) {
                    RunningRegistry::register($execId, ['user_id' => $userId, 'connection_id' => (int) $conn['id'],
                        'database' => $database, 'backend' => $backend]);
                }
            } catch (\Throwable) {
                // cancel support is best effort
            }

            $statements = SqlSplitter::split($sql, $driver::splitMode(), $driver::name());
            if (!$statements) {
                throw new \RuntimeException('Nada para executar.');
            }
            $inTransaction = false;

            foreach ($statements as $i => $st) {
                $absLine = $st['line'] + $lineOffset;
                if ($readOnly && !SqlSplitter::isReadOnly($st['sql'])) {
                    $results[] = $this->errorResult($i, $st, $absLine, [
                        'code' => 'READ_ONLY', 'message' => 'Bloqueado: esta conexão/utilizador está em modo só de leitura. '
                            . 'Apenas SELECT/WITH/SHOW/EXPLAIN são permitidos.', 'line' => null, 'position' => null]);
                    $status = 'error';
                    $errorMessage = 'Read-only violation';
                    break;
                }

                $kw = SqlSplitter::firstKeyword($st['sql']);
                [$stSql, $params] = $usePlaceholders ? Placeholders::bind($st['sql'], $phDefs, $phValues) : [$st['sql'], []];
                $t0 = microtime(true);
                try {
                    $cursor = $driver->open($stSql, $params, $inTransaction);
                    do {
                        $results[] = $cursor->hasRows()
                            ? $this->collect($cursor, $i, [...$st, 'bound' => $stSql, 'params' => $params], $absLine, $maxRows, $pageSize, $userId, $conn, $database, $t0)
                            : ['index' => $i, 'type' => 'command', 'sql' => $st['sql'], 'line' => $absLine,
                               'keyword' => $kw, 'affected' => $cursor->affected(),
                               'duration_ms' => (int) round((microtime(true) - $t0) * 1000)];
                        $totalRows += (int) (end($results)['row_count'] ?? max(0, (int) (end($results)['affected'] ?? 0)));
                    } while ($cursor->nextRowset());
                    $cursor->close();
                } catch (\Throwable $e) {
                    $cancelled = (RunningRegistry::get($execId)['cancelled'] ?? false);
                    $err = $driver->parseError($e, $st['sql']);
                    if ($cancelled) {
                        $err['message'] = 'Execução cancelada pelo utilizador. (' . $err['message'] . ')';
                    }
                    $results[] = $this->errorResult($i, $st, $absLine, $err);
                    $status = $cancelled ? 'cancelled' : 'error';
                    $errorMessage = $err['message'];
                    break;
                }

                if (in_array($kw, ['BEGIN', 'START'], true)) {
                    $inTransaction = true;
                } elseif (in_array($kw, ['COMMIT', 'ROLLBACK', 'END'], true)) {
                    $inTransaction = false;
                }
            }
            $this->connections->touch((int) $conn['id']);
        } catch (\Throwable $e) {
            // Connection-level failure (auth, network, driver missing...)
            $msg = $e instanceof \PDOException ? preg_replace('/^SQLSTATE\[[^\]]*\]\s*(\[[^\]]*\]\s*)*/', '', $e->getMessage()) : $e->getMessage();
            $results[] = ['index' => 0, 'type' => 'error', 'sql' => $sql, 'line' => 1 + $lineOffset,
                'error' => ['code' => $e instanceof \PDOException ? (string) $e->getCode() : null, 'message' => $msg,
                    'line' => null, 'position' => null, 'abs_line' => null]];
            $status = 'error';
            $errorMessage = $msg;
            Logger::info('Connection failure', ['connection' => $conn['id'], 'error' => $msg]);
        } finally {
            RunningRegistry::remove($execId);
        }

        $duration = (int) round((microtime(true) - $started) * 1000);
        if ($opts['record_history'] ?? true) {
            $this->history->record([
                'user_id'         => $userId,
                'connection_id'   => (int) $conn['id'],
                'connection_name' => $conn['name'] . ($database ? " / $database" : ''),
                'saved_query_id'  => $opts['saved_query_id'] ?? null,
                'sql_text'        => $sql,
                'status'          => $status,
                'error_message'   => $errorMessage ? mb_substr($errorMessage, 0, 2000) : null,
                'duration_ms'     => $duration,
                'row_count'       => $totalRows,
            ]);
        }

        return [
            'execution_id' => $execId,
            'status'       => $status,
            'connection'   => ['id' => (int) $conn['id'], 'name' => $conn['name'], 'driver' => $conn['driver'], 'database' => $database],
            'duration_ms'  => $duration,
            'read_only'    => $readOnly,
            'results'      => $results,
        ];
    }

    private function collect($cursor, int $i, array $st, int $absLine, int $maxRows, int $pageSize,
                             int $userId, array $conn, ?string $database, float $t0): array
    {
        $columns = $cursor->columns();
        [$resultId, $fh] = ResultStore::create();
        $count = 0;
        $firstPage = [];
        $truncated = false;
        while (($row = $cursor->fetch()) !== null) {
            if ($count >= $maxRows) {
                $truncated = true;
                break;
            }
            $row = array_map([self::class, 'normalise'], $row);
            ResultStore::writeRow($fh, $row);
            if ($count < $pageSize) {
                $firstPage[] = $row;
            }
            $count++;
        }
        if (!$columns) {
            $columns = $cursor->columns();
        }
        $duration = (int) round((microtime(true) - $t0) * 1000);
        ResultStore::finish($resultId, $fh, [
            'user_id'       => $userId,
            'connection_id' => (int) $conn['id'],
            'database'      => $database,
            'sql'           => $st['bound'] ?? $st['sql'],
            'params'        => $st['params'] ?? [],
            'columns'       => $columns,
            'row_count'     => $count,
            'truncated'     => $truncated,
            'created_at'    => time(),
        ]);
        return [
            'index'       => $i,
            'type'        => 'rows',
            'sql'         => $st['sql'],
            'line'        => $absLine,
            'result_id'   => $resultId,
            'columns'     => $columns,
            'rows'        => $firstPage,
            'row_count'   => $count,
            'filtered'    => $count,
            'truncated'   => $truncated,
            'max_rows'    => $maxRows,
            'page'        => 1,
            'per_page'    => $pageSize,
            'exportable'  => SqlSplitter::isReadOnly($st['sql']),
            'duration_ms' => $duration,
        ];
    }

    private function errorResult(int $i, array $st, int $absLine, array $err): array
    {
        $err['abs_line'] = $err['line'] !== null ? $absLine + (int) $err['line'] - 1 : $absLine;
        if (($err['position'] ?? null) !== null && !isset($err['column'])) {
            $before = substr($st['sql'], 0, (int) $err['position'] - 1);
            $nl = strrpos($before, "\n");
            $err['column'] = $nl === false ? strlen($before) + 1 : strlen($before) - $nl;
        }
        return ['index' => $i, 'type' => 'error', 'sql' => $st['sql'], 'line' => $absLine, 'error' => $err];
    }

    /** Make any driver value JSON-safe (binary → hex, streams → string, bool → bool). */
    public static function normalise(mixed $v): mixed
    {
        if (is_resource($v)) {
            $v = stream_get_contents($v);
        }
        if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
            return '0x' . strtoupper(bin2hex(strlen($v) > 4096 ? substr($v, 0, 4096) : $v)) . (strlen($v) > 4096 ? '…' : '');
        }
        return $v;
    }

    /** Stream every row of a read-only statement (exports). Yields columns first, then rows. */
    public function stream(array $conn, string $sql, ?string $database, int $timeout, array $params = []): \Generator
    {
        $driver = $this->connections->driver($conn, $database);
        $driver->prepareSession($timeout, true);
        $cursor = $driver->open($sql, $params);
        yield 'columns' => $cursor->columns();
        $max = (int) config('query.export_max', 0);
        $n = 0;
        while (($row = $cursor->fetch()) !== null) {
            if ($max > 0 && $n++ >= $max) {
                break;
            }
            yield 'row' => array_map([self::class, 'normalise'], $row);
        }
        $cursor->close();
    }

    public function driverFor(array $conn, ?string $database = null): DriverInterface
    {
        return $this->connections->driver($conn, $database);
    }
}
