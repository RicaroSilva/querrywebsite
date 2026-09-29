<?php
declare(strict_types=1);

namespace App\Modules\Assistant;

use App\Modules\Drivers\DriverFactory;
use App\Modules\Execution\QueryExecutor;
use App\Modules\Execution\SqlSplitter;

/**
 * Natural-language questions over a connection:
 *
 *   1. context   — schema catalog + team notes + validated examples for this connection
 *   2. tables    — small schemas are sent whole; large ones (e.g. Cyclos) go through a first
 *                  model call that picks the relevant tables from a compact catalog
 *   3. SQL       — the model writes one read-only query; it is validated and executed in a
 *                  read-only session; on error, the error + exact columns of the tables it
 *                  used go back to the model for a fix (AI_MAX_ATTEMPTS)
 *   4. answer    — the model answers from the result rows (unless AI_SEND_RESULTS=false)
 *
 * Safety: the SQL is never trusted (single statement, read-only classifier, read-only session).
 */
final class AssistantService
{
    private const DIALECTS = [
        'pgsql'  => ['PostgreSQL', 'LIMIT n', 'double quotes', "now() - interval '3 months'"],
        'mysql'  => ['MySQL/MariaDB', 'LIMIT n', 'backticks', 'NOW() - INTERVAL 3 MONTH'],
        'sqlsrv' => ['Microsoft SQL Server (T-SQL)', 'SELECT TOP (n)', 'square brackets', 'DATEADD(month, -3, GETDATE())'],
        'sqlite' => ['SQLite', 'LIMIT n', 'double quotes', "date('now', '-3 months')"],
    ];
    private const MAX_SELECTED = 25;

    private AiClient $ai;
    private array $cfg;
    private KnowledgeRepository $knowledge;

    public function __construct(?AiClient $ai = null, ?KnowledgeRepository $knowledge = null)
    {
        $this->cfg = config('ai') + ['schema_chars' => 30000];
        $this->ai = $ai ?? new AiClient($this->cfg);
        $this->knowledge = $knowledge ?? new KnowledgeRepository();
    }

    /**
     * @param array  $history previous turns: [['question' => ..., 'sql' => ...], ...]
     * @param string $mode    'ask' (generate + run + answer) | 'generate' (SQL only) | 'run' (run given SQL + answer)
     */
    public function ask(array $conn, ?string $database, string $question, array $history, int $userId,
                        string $mode = 'ask', ?string $sql = null, ?string $model = null): array
    {
        $t0 = microtime(true);
        $model = $model ?: $this->cfg['model'];
        if ($model === '') {
            throw new \RuntimeException('Nenhum modelo configurado (AI_MODEL no .env).');
        }
        $catalog = SchemaCatalog::load($conn, $database);
        $notes = $this->knowledge->notes((int) $conn['id']);
        $examples = $this->knowledge->relevantExamples((int) $conn['id'], $question, 6);

        // --- which tables does the model get to see in detail? ---------------------------
        $selected = null; // null = whole schema
        if (count(KnowledgeRepository::words($question)) < 2 && $mode !== 'run') {
            $selected = []; // greetings / one-word messages: no schema needed
        } elseif ($catalog->detailLength() > (int) $this->cfg['schema_chars']) {
            $selected = $this->selectTables($catalog, $conn, $question, $notes, $examples, $history, $model, $sql);
        }
        $schemaText = $catalog->detail($selected);

        $messages = [['role' => 'system', 'content' => $this->sqlSystemPrompt($conn, $database, $schemaText, $notes, $selected !== null, $catalog->count())]];
        foreach (array_reverse($examples) as $e) {
            $messages[] = ['role' => 'user', 'content' => (string) $e['question']];
            $messages[] = ['role' => 'assistant', 'content' => "```sql\n" . trim((string) $e['sql_text']) . "\n```"];
        }
        foreach (array_slice($history, -4) as $h) {
            if (!empty($h['question']) && !empty($h['sql'])) {
                $messages[] = ['role' => 'user', 'content' => (string) $h['question']];
                $messages[] = ['role' => 'assistant', 'content' => "```sql\n" . $h['sql'] . "\n```"];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $meta = ['tables' => $selected ?? [], 'schema_tables' => $catalog->count()];
        $attempts = [];
        $explanation = null;
        $result = null;
        $maxAttempts = max(1, (int) $this->cfg['max_attempts']) + ($mode === 'run' ? 1 : 0);

        for ($i = 1; $i <= $maxAttempts; $i++) {
            if (!($mode === 'run' && $i === 1)) {
                $reply = $this->ai->chat($messages, $model, (float) $this->cfg['temperature']);
                [$sql, $explanation] = self::extractSql($reply);
                if ($sql === null) {
                    return $this->done($t0, $model, $meta + ['answer' => $reply ?: 'A IA não devolveu SQL.', 'sql' => null, 'attempts' => $attempts, 'chat' => true]);
                }
                if (preg_match('/^\s*--\s*CHAT:?\s*(.*)$/is', $sql, $m)) {
                    return $this->done($t0, $model, $meta + ['answer' => trim($m[1]), 'sql' => null, 'attempts' => $attempts, 'chat' => true]);
                }
                if (preg_match('/^\s*--\s*CANNOT:?\s*(.*)$/is', $sql, $m)) {
                    return $this->done($t0, $model, $meta + ['answer' => 'Não consigo responder com esta base de dados: ' . trim($m[1])
                        . "\n\nDica: explique nas **notas de conhecimento** que tabelas/colunas representam isto.", 'sql' => null, 'attempts' => $attempts]);
                }
            }
            $problem = $this->validate((string) $sql, $conn['driver']);
            if ($problem === null && $mode === 'generate') {
                return $this->done($t0, $model, $meta + ['answer' => null, 'sql' => $sql, 'explanation' => $explanation, 'attempts' => $attempts, 'pending' => true]);
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
            if ($i === $maxAttempts) {
                return $this->done($t0, $model, $meta + [
                    'answer' => 'Não consegui obter um resultado válido. Último erro: ' . $problem,
                    'sql' => $sql, 'explanation' => $explanation, 'attempts' => $attempts, 'failed' => true,
                ]);
            }
            $messages[] = ['role' => 'assistant', 'content' => "```sql\n$sql\n```"];
            $messages[] = ['role' => 'user', 'content' => $this->fixPrompt((string) $sql, $problem, $catalog)];
        }

        $answer = $this->answer($question, (string) $sql, $result, $model);
        return $this->done($t0, $model, $meta + ['answer' => $answer, 'sql' => $sql, 'explanation' => $explanation,
            'result' => $result, 'attempts' => $attempts]);
    }

    private function done(float $t0, string $model, array $data): array
    {
        return $data + ['model' => $model, 'duration_ms' => (int) round((microtime(true) - $t0) * 1000)];
    }

    /** Stage 1 for large schemas: the model picks the relevant tables from a compact catalog. */
    private function selectTables(SchemaCatalog $catalog, array $conn, string $question, string $notes,
                                  array $examples, array $history, string $model, ?string $sql): array
    {
        $picked = [];
        // Tables already known to be relevant: from similar validated examples, the conversation and user SQL
        foreach ($examples as $e) {
            array_push($picked, ...$catalog->mentionedIn((string) $e['sql_text']));
        }
        foreach (array_slice($history, -2) as $h) {
            array_push($picked, ...$catalog->mentionedIn((string) ($h['sql'] ?? '')));
        }
        if ($sql) {
            array_push($picked, ...$catalog->mentionedIn($sql));
        }

        $compact = $catalog->compact();
        if (strlen($compact) > (int) $this->cfg['schema_chars'] * 2) {
            $compact = $catalog->compact(6);
        }
        try {
            $reply = $this->ai->chat([
                ['role' => 'system', 'content' => "You select database tables for a SQL analyst.\n"
                    . "Given the user's question, list the tables (exact names from the catalog) needed to answer it, "
                    . "including the tables required for JOINs. Prefer tables that have rows; tables marked [empty] are unused.\n"
                    . "Reply ONLY with a JSON array of table names, most important first, at most 15. Example: [\"orders\", \"customers\"]\n\n"
                    . ($notes !== '' ? "Notes from the team about this database (trust them):\n$notes\n\n" : '')
                    . "Catalog (table [approx rows]: columns):\n$compact"],
                ['role' => 'user', 'content' => $question],
            ], $model, 0.0);
            if (preg_match('/\[[^\[\]]*\]/s', $reply, $m) && is_array($list = json_decode($m[0], true))) {
                foreach ($list as $name) {
                    if (is_string($name) && ($key = $catalog->find($name))) {
                        $picked[] = $key;
                    }
                }
            } else {
                array_push($picked, ...$catalog->mentionedIn($reply));
            }
        } catch (\RuntimeException) {
            // fall back to keyword matching below
        }
        if (!$picked) {
            $picked = $catalog->keywordMatches($question);
        }
        $picked = array_values(array_unique($picked));
        foreach ($catalog->neighbours($picked) as $n) {
            if (count($picked) >= self::MAX_SELECTED) {
                break;
            }
            $picked[] = $n;
        }
        return array_slice(array_values(array_unique($picked)), 0, self::MAX_SELECTED);
    }

    private function fixPrompt(string $sql, string $problem, SchemaCatalog $catalog): string
    {
        $used = $catalog->mentionedIn($sql);
        $unknown = [];
        if (preg_match_all('/\b(?:FROM|JOIN)\s+([\w."`\[\]]+)/i', $sql, $m)) {
            foreach ($m[1] as $t) {
                if (!$catalog->find($t) && !preg_match('/^\(/', $t)) {
                    $unknown[] = $t;
                }
            }
        }
        $txt = "That query failed with this error:\n$problem\n\n";
        if ($unknown) {
            $txt .= 'These tables do NOT exist: ' . implode(', ', array_unique($unknown)) . ".\n";
        }
        if ($used) {
            $txt .= "Exact columns of the tables you used (use ONLY these names):\n" . $catalog->detail($used) . "\n\n";
        }
        return $txt . 'Fix the query. Reply with a single ```sql block.';
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
        $explanation = null;
        if (preg_match('/```(?:sql|SQL|tsql|postgresql|pgsql|mysql)?\s*\n?(.*?)```/s', $reply, $m)) {
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
        return [$sql === '' ? null : $sql, $explanation !== null && $explanation !== '' ? mb_substr($explanation, 0, 1000) : null];
    }

    private function sqlSystemPrompt(array $conn, ?string $database, string $schemaText, string $notes, bool $partial, int $total): string
    {
        [$dialect, $limit, $quote, $dateExample] = self::DIALECTS[$conn['driver']] ?? ['SQL', 'LIMIT n', 'double quotes', ''];
        return "You are an expert $dialect data analyst working inside a SQL client.\n"
            . "Write ONE read-only SQL query that answers the user's question, using the database schema below.\n\n"
            . "Rules:\n"
            . "- Use ONLY tables and columns listed in the schema, with their exact names (quote identifiers with $quote when needed). Never guess column names.\n"
            . "- Read-only: a single SELECT (or WITH ... SELECT). Never INSERT, UPDATE, DELETE, DDL, or multiple statements.\n"
            . "- Follow the foreign keys (FK lines) to join tables. Aggregate with GROUP BY for totals, rankings or \"who/which has the most\".\n"
            . "- Relative dates: use the database's current date (e.g. $dateExample).\n"
            . "- Limit detailed listings to 100 rows ($limit).\n"
            . "- Give result columns short, readable aliases in the same language as the question.\n"
            . "- If the message is a greeting or not a question about the data, reply exactly: ```sql\n-- CHAT: <short friendly reply in the user's language>\n```\n"
            . "- If the question cannot be answered with this schema, reply exactly: ```sql\n-- CANNOT: <short reason>\n```\n\n"
            . "Output: one ```sql code block, then at most one short sentence explaining the logic.\n"
            . 'Today is ' . date('Y-m-d') . ".\n\n"
            . ($notes !== '' ? "IMPORTANT — notes from the team about this database (business meaning; always follow them):\n$notes\n\n" : '')
            . 'Database schema' . ($database ? " (database: $database)" : '')
            . ($partial ? " — only the tables relevant to this question are shown (the database has $total tables)" : '') . ":\n"
            . ($schemaText !== '' ? $schemaText : '-- (no tables found)');
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

    /** Draft notes: the model describes the main business tables (the team then reviews/edits them). */
    public function describeDatabase(array $conn, ?string $database, ?string $model = null): string
    {
        $catalog = SchemaCatalog::load($conn, $database);
        $compact = $catalog->compact();
        if (strlen($compact) > (int) $this->cfg['schema_chars'] * 2) {
            $compact = $catalog->compact(8);
        }
        return $this->ai->chat([
            ['role' => 'system', 'content' => "You document databases for business analysts. From the catalog below, write concise notes "
                . "in Portuguese (Portugal) describing the MAIN business entities only (ignore empty [empty] and purely technical tables).\n"
                . "Format, one line each:\n"
                . "- <table>: what it represents; key columns; how it joins to other tables (column -> table.column)\n"
                . "Then a section 'Termos:' mapping business words to tables, e.g. 'clientes = users (where ...)'.\n"
                . "Maximum 40 lines. Only use names that exist in the catalog."],
            ['role' => 'user', 'content' => "Catalog (table [approx rows]: columns):\n$compact"],
        ], $model ?: $this->cfg['model'], 0.1);
    }
}
