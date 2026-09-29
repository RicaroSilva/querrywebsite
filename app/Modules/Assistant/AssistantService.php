<?php
declare(strict_types=1);

namespace App\Modules\Assistant;

use App\Modules\Drivers\DriverFactory;
use App\Modules\Drivers\DriverInterface;
use App\Modules\Execution\QueryExecutor;
use App\Modules\Execution\SqlSplitter;

/**
 * Natural-language questions over a connection:
 *   question → (schema + question) → model writes SQL → validated read-only → executed read-only
 *   → on SQL error the error goes back to the model for a fix (AI_MAX_ATTEMPTS)
 *   → result rows (optional, AI_SEND_RESULTS) → model writes the answer.
 *
 * Safety: the SQL is never trusted. It must be a single statement that passes the
 * read-only classifier and it runs in a read-only session (see QueryExecutor).
 */
final class AssistantService
{
    private const DIALECTS = [
        'pgsql'  => ['PostgreSQL', 'LIMIT n', 'double quotes'],
        'mysql'  => ['MySQL/MariaDB', 'LIMIT n', 'backticks'],
        'sqlsrv' => ['Microsoft SQL Server (T-SQL)', 'SELECT TOP (n)', 'square brackets'],
        'sqlite' => ['SQLite', 'LIMIT n', 'double quotes'],
    ];

    private AiClient $ai;
    private array $cfg;

    public function __construct(?AiClient $ai = null)
    {
        $this->cfg = config('ai');
        $this->ai = $ai ?? new AiClient($this->cfg);
    }

    /**
     * @param array $history previous turns: [['question' => ..., 'sql' => ..., 'answer' => ...], ...]
     * @param string $mode   'ask' (generate + run + answer) | 'generate' (SQL only, for review) | 'run' (run given SQL + answer)
     */
    public function ask(array $conn, ?string $database, string $question, array $history, int $userId,
                        string $mode = 'ask', ?string $sql = null, ?string $model = null): array
    {
        $t0 = microtime(true);
        $model = $model ?: $this->cfg['model'];
        if ($model === '') {
            throw new \RuntimeException('Nenhum modelo configurado (AI_MODEL no .env).');
        }
        $driverName = $conn['driver'];
        $attempts = [];
        $explanation = null;

        if ($mode !== 'run') {
            $messages = [['role' => 'system', 'content' => $this->sqlSystemPrompt($conn, $database)]];
            foreach (array_slice($history, -4) as $h) {
                if (!empty($h['question']) && !empty($h['sql'])) {
                    $messages[] = ['role' => 'user', 'content' => (string) $h['question']];
                    $messages[] = ['role' => 'assistant', 'content' => "```sql\n" . $h['sql'] . "\n```"];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $question];
        }

        $maxAttempts = $mode === 'run' ? 1 : max(1, (int) $this->cfg['max_attempts']);
        $result = null;
        for ($i = 1; $i <= $maxAttempts; $i++) {
            if ($mode !== 'run') {
                $reply = $this->ai->chat($messages, $model, (float) $this->cfg['temperature']);
                [$sql, $explanation] = self::extractSql($reply);
                if ($sql === null) {
                    return $this->done($t0, $model, ['answer' => $reply ?: 'A IA não devolveu SQL.', 'sql' => null, 'attempts' => $attempts]);
                }
                if (preg_match('/^\s*--\s*CANNOT:?\s*(.*)$/is', $sql, $m)) {
                    return $this->done($t0, $model, ['answer' => 'Não consigo responder com esta base de dados: ' . trim($m[1]), 'sql' => null, 'attempts' => $attempts]);
                }
            }
            $problem = $this->validate((string) $sql, $driverName);
            if ($problem === null && $mode === 'generate') {
                return $this->done($t0, $model, ['answer' => null, 'sql' => $sql, 'explanation' => $explanation, 'attempts' => $attempts, 'pending' => true]);
            }
            if ($problem === null) {
                $run = (new QueryExecutor())->run($conn, (string) $sql, [
                    'user_id'   => $userId,
                    'database'  => $database,
                    'read_only' => true,
                    'max_rows'  => (int) $this->cfg['max_rows'],
                    'page_size' => 100,
                ]);
                $first = $run['results'][0] ?? null;
                if ($first && $first['type'] === 'rows') {
                    $result = $first;
                    break;
                }
                $problem = $first['error']['message'] ?? 'A query não devolveu linhas.';
            }
            $attempts[] = ['sql' => $sql, 'error' => $problem];
            if ($mode === 'run' || $i === $maxAttempts) {
                return $this->done($t0, $model, [
                    'answer' => 'Não consegui obter um resultado válido. Último erro: ' . $problem,
                    'sql' => $sql, 'explanation' => $explanation, 'attempts' => $attempts, 'failed' => true,
                ]);
            }
            // Ask the model to fix its query
            $messages[] = ['role' => 'assistant', 'content' => "```sql\n$sql\n```"];
            $messages[] = ['role' => 'user', 'content' => "That query failed with this error:\n$problem\n\n"
                . 'Fix it. Use only tables and columns from the schema. Reply with a single ```sql block.'];
        }

        $answer = $this->answer($question, (string) $sql, $result, $model);
        return $this->done($t0, $model, ['answer' => $answer, 'sql' => $sql, 'explanation' => $explanation,
            'result' => $result, 'attempts' => $attempts]);
    }

    private function done(float $t0, string $model, array $data): array
    {
        return $data + ['model' => $model, 'duration_ms' => (int) round((microtime(true) - $t0) * 1000)];
    }

    /** Returns null when OK, or the reason the SQL is refused. */
    private function validate(string $sql, string $driver): ?string
    {
        $class = DriverFactory::class($driver);
        $parts = SqlSplitter::split($sql, $class::splitMode(), $driver);
        if (count($parts) !== 1) {
            return 'Only ONE SQL statement is allowed.';
        }
        if (!SqlSplitter::isReadOnly($parts[0]['sql'])) {
            return 'Only read-only queries (SELECT / WITH) are allowed; data modification is forbidden.';
        }
        return null;
    }

    /** @return array{0: ?string, 1: ?string} [sql, explanation] */
    public static function extractSql(string $reply): array
    {
        $sql = null;
        if (preg_match('/```(?:sql|SQL|tsql|postgresql|mysql)?\s*\n?(.*?)```/s', $reply, $m)) {
            $sql = $m[1];
            $explanation = trim(str_replace($m[0], '', $reply));
        } elseif (($j = json_decode($reply, true)) && is_array($j) && isset($j['sql'])) {
            $sql = (string) $j['sql'];
            $explanation = (string) ($j['explanation'] ?? '');
        } elseif (preg_match('/^\s*((?:SELECT|WITH)\b.*?)(?:\n\s*\n|$)/is', $reply, $m)) {
            $sql = $m[1];
            $explanation = trim(substr($reply, strlen($m[0])));
        } else {
            return [null, null];
        }
        $sql = trim(rtrim(trim($sql), ';'));
        return [$sql === '' ? null : $sql, isset($explanation) && $explanation !== '' ? mb_substr($explanation, 0, 1000) : null];
    }

    private function sqlSystemPrompt(array $conn, ?string $database): string
    {
        [$dialect, $limit, $quote] = self::DIALECTS[$conn['driver']] ?? ['SQL', 'LIMIT n', 'double quotes'];
        return "You are an expert $dialect data analyst working inside a SQL client.\n"
            . "Write ONE read-only SQL query that answers the user's question, using the database schema below.\n\n"
            . "Rules:\n"
            . "- Use ONLY tables and columns that exist in the schema, with their exact names (quote identifiers with $quote when needed).\n"
            . "- Read-only: a single SELECT (or WITH ... SELECT). Never INSERT, UPDATE, DELETE, DDL, or multiple statements.\n"
            . "- Follow the relationships (foreign keys) to join tables. Aggregate with GROUP BY when the question asks for totals, rankings or \"who/which has the most\".\n"
            . "- Limit detailed listings to 100 rows ($limit). For \"the top/most\" questions order descending and limit accordingly.\n"
            . "- Give result columns short, readable aliases in the same language as the question.\n"
            . "- If the question cannot be answered with this schema, reply exactly: ```sql\n-- CANNOT: <short reason>\n```\n\n"
            . "Output: one ```sql code block, then at most one short sentence explaining the logic.\n"
            . 'Today is ' . date('Y-m-d') . ".\n\n"
            . 'Database schema' . ($database ? " (database: $database)" : '') . ":\n"
            . $this->schema($conn, $database);
    }

    /** Compact textual schema, cached for 10 minutes. */
    public function schema(array $conn, ?string $database): string
    {
        $file = storage_path('cache/aischema_' . md5($conn['id'] . '|' . $conn['updated_at'] . '|' . $database) . '.txt');
        if (is_file($file) && filemtime($file) > time() - 600) {
            return (string) file_get_contents($file);
        }
        $driver = DriverFactory::fromConnection($conn)->withDatabase($database);
        $caps = $driver::capabilities();
        $default = ['pgsql' => 'public', 'sqlsrv' => 'dbo'][$conn['driver']] ?? null;
        $schemas = [null];
        if ($caps['schemas']) {
            $schemas = array_slice(array_column($driver->schemas($database), 'name'), 0, 20);
            usort($schemas, static fn($a, $b) => ($b === $default) <=> ($a === $default));
        }
        $max = max(1, (int) $this->cfg['schema_tables']);
        $lines = [];
        $count = 0;
        foreach ($schemas as $schema) {
            foreach (['tables', 'views'] as $type) {
                foreach ($driver->objects($database, $schema, $type) as $obj) {
                    if ($count++ >= $max) {
                        break 3;
                    }
                    $lines[] = $this->describeTable($driver, $database, $schema, $obj['name'], $type === 'views', $default, $count <= 80);
                }
            }
        }
        if ($count > $max) {
            $lines[] = "-- (schema truncated to $max tables/views)";
        }
        $text = implode("\n", $lines) ?: '-- (no tables found)';
        @file_put_contents($file, $text);
        return $text;
    }

    private function describeTable(DriverInterface $d, ?string $db, ?string $schema, string $name, bool $view, ?string $default, bool $withFks): string
    {
        $qualified = ($schema && $schema !== $default ? $schema . '.' : '') . $name;
        try {
            $cols = array_map(static fn($c) => $c['name'] . ' ' . preg_replace('/\s+/', ' ', (string) $c['type']) . ($c['primary'] ? ' PK' : ''),
                $d->columns($db, $schema, $name));
            $line = ($view ? 'VIEW ' : 'TABLE ') . $qualified . '(' . implode(', ', $cols) . ')';
            if ($withFks && !$view) {
                foreach ($d->foreignKeys($db, $schema, $name) as $fk) {
                    $line .= "\n  FK " . preg_replace('/^FOREIGN KEY\s*/i', '', (string) $fk['definition']);
                }
            }
            return $line;
        } catch (\Throwable) {
            return ($view ? 'VIEW ' : 'TABLE ') . $qualified;
        }
    }

    private function answer(string $question, string $sql, array $result, string $model): string
    {
        $n = (int) $result['row_count'];
        if (!$this->cfg['send_results']) {
            return $n === 0 ? 'A query não devolveu resultados.' : "Resultado: $n linha(s) — veja a tabela abaixo.";
        }
        $rows = array_slice($result['rows'], 0, max(1, (int) $this->cfg['result_rows']));
        $tsv = implode("\t", array_column($result['columns'], 'name')) . "\n";
        foreach ($rows as $r) {
            $tsv .= implode("\t", array_map(static fn($v) => $v === null ? 'NULL' : mb_substr(str_replace(["\t", "\n"], ' ', (string) (is_bool($v) ? ($v ? 'true' : 'false') : $v)), 0, 200), $r)) . "\n";
        }
        $note = $n > count($rows) ? "\n(showing " . count($rows) . " of $n rows" . ($result['truncated'] ? '+' : '') . ')' : '';
        return $this->ai->chat([
            ['role' => 'system', 'content' => "You are a helpful data analyst. Answer the user's question using ONLY the query result provided.\n"
                . "- Reply in the same language as the question (Portuguese from Portugal if the question is in Portuguese).\n"
                . "- Be concise: 1 to 4 sentences, lead with the direct answer and the key numbers (format numbers for readability).\n"
                . "- If the result is empty, say that no data matched. Never invent data. Do not show SQL."],
            ['role' => 'user', 'content' => "Question: $question\n\nSQL used:\n$sql\n\nResult ($n rows):\n$tsv$note"],
        ], $model, 0.2);
    }
}
