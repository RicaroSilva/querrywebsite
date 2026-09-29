<?php
declare(strict_types=1);

namespace App\Modules\Analyses;

use App\Core\HttpException;
use App\Modules\Drivers\DriverFactory;
use App\Modules\Drivers\PostgresDriver;
use App\Modules\Execution\Placeholders;
use App\Modules\Execution\QueryExecutor;

/**
 * Runs analyses. Two kinds:
 *
 *  - function: a PostgreSQL set-returning function. The call is built with named
 *    arguments and ONLY the parameters the user filled in, so empty fields fall back to
 *    the function's own DEFAULT:   SELECT * FROM fn(p_a => ?::numeric, p_c => ?::integer)
 *  - query:    a SQL template with {{name}} placeholders (bound parameters) and optional
 *    blocks [[ ... ]] that are removed when their placeholders are empty:
 *              SELECT ... WHERE 1=1 [[AND date >= {{desde}}]]
 *
 * Always executed read-only.
 */
final class AnalysisService
{
    public const INPUT_TYPES = ['text', 'number', 'integer', 'date', 'boolean', 'select'];
    private const IDENT = '[A-Za-z_][A-Za-z0-9_$]*';

    public const MAX_TIMEOUT = 3600;

    public function run(array $analysis, array $conn, array $values, int $userId, ?string $database = null, ?string $executionId = null): array
    {
        [$sql, $params] = $this->build($analysis, $values, $conn['driver']);
        self::assertSingleReadOnly($sql, $conn['driver']);
        $r = (new QueryExecutor())->run($conn, $sql, [
            'user_id'   => $userId,
            'database'  => $database,
            'read_only'    => true,
            'raw_params'   => $params, // already bound by build(): one statement, positional "?"
            'timeout'      => (int) ($analysis['timeout_seconds'] ?? 0) ?: (int) config('query.timeout', 60),
            'execution_id' => $executionId,
        ]);
        return $r + ['sql' => $sql];
    }

    /** @return array{0: string, 1: array} */
    public function build(array $a, array $values, string $driver): array
    {
        $defs = $a['params'] ?? [];
        if ($a['kind'] === 'function') {
            if ($driver !== 'pgsql') {
                throw new HttpException(422, 'Análises do tipo função só são suportadas em PostgreSQL.');
            }
            $args = [];
            $params = [];
            foreach ($defs as $p) {
                $v = $this->valueFor($p, $values);
                if ($v === null) {
                    if (!empty($p['required'])) {
                        throw new HttpException(422, 'Preencha o campo "' . ($p['label'] ?: $p['name']) . '".');
                    }
                    continue; // omitted → the function's DEFAULT is used
                }
                $args[] = self::quote($p['name']) . ' => ?' . (!empty($p['arg_type']) ? '::' . $p['arg_type'] : '');
                $params[] = $v;
            }
            return ['SELECT * FROM ' . self::qualified((string) $a['function_name']) . '(' . implode(', ', $args) . ')', $params];
        }

        // query template
        $sql = (string) $a['sql_text'];
        $filled = [];
        foreach ($defs as $p) {
            $filled[$p['name']] = $this->valueFor($p, $values);
        }
        foreach (Placeholders::names($sql) as $n) {
            if (!array_key_exists($n, $filled)) {
                $filled[$n] = $this->valueFor(['name' => $n, 'type' => 'text'], $values);
            }
        }
        // optional blocks
        $sql = (string) preg_replace_callback('/\[\[(.*?)\]\]/s', static function ($m) use ($filled) {
            foreach (Placeholders::names($m[1]) as $n) {
                if (($filled[$n] ?? null) === null) {
                    return '';
                }
            }
            return $m[1];
        }, $sql);
        foreach ($defs as $p) {
            if (!empty($p['required']) && $filled[$p['name']] === null && in_array($p['name'], Placeholders::names($sql), true)) {
                throw new HttpException(422, 'Preencha o campo "' . ($p['label'] ?: $p['name']) . '".');
            }
        }
        $params = [];
        $bound = (string) preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', static function ($m) use (&$params, $filled) {
            $params[] = $filled[$m[1]] ?? null;
            return '?';
        }, $sql);
        return [$bound, $params];
    }

    /** User value, else the analysis default, cast to the parameter type; null when empty. */
    private function valueFor(array $p, array $values): int|float|string|bool|null
    {
        $v = $values[$p['name']] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            $v = $p['default'] ?? null;
        }
        if ($v === null || (is_string($v) && trim($v) === '') || is_array($v)) {
            return null;
        }
        $v = trim((string) $v);
        $label = $p['label'] ?? $p['name'];
        return match ($p['type'] ?? 'text') {
            'number'  => is_numeric($n = str_replace(',', '.', $v))
                ? (preg_match('/^-?\d+$/', $n) ? (int) $n : (float) $n)
                : throw new HttpException(422, "\"$label\" tem de ser um número."),
            'integer' => preg_match('/^-?\d+$/', $v) ? (int) $v : throw new HttpException(422, "\"$label\" tem de ser um número inteiro."),
            'date'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : throw new HttpException(422, "\"$label\" tem de ser uma data (AAAA-MM-DD)."),
            'boolean' => in_array(strtolower($v), ['1', 'true', 'sim', 'yes', 't'], true) ? 'true' : 'false',
            default   => mb_substr($v, 0, 4000),
        };
    }

    public static function assertSingleReadOnly(string $sql, string $driver): void
    {
        $class = DriverFactory::class($driver);
        $parts = \App\Modules\Execution\SqlSplitter::split($sql, $class::splitMode(), $driver);
        if (count($parts) !== 1 || !\App\Modules\Execution\SqlSplitter::isReadOnly($parts[0]['sql'])) {
            throw new HttpException(422, 'Uma análise tem de ser UMA query de leitura (SELECT / WITH).');
        }
    }

    public static function quote(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    public static function qualified(string $name): string
    {
        if (!preg_match('/^' . self::IDENT . '(\.' . self::IDENT . ')?$/', $name)) {
            throw new HttpException(422, 'Nome de função inválido.');
        }
        return implode('.', array_map([self::class, 'quote'], explode('.', $name)));
    }

    /** Validate + normalise the parameter list coming from the editor. */
    public static function normaliseParams(array $raw, string $kind): array
    {
        $out = [];
        foreach (array_slice($raw, 0, 50) as $p) {
            if (!is_array($p) || !preg_match('/^' . self::IDENT . '$/', (string) ($p['name'] ?? ''))) {
                continue;
            }
            $argType = trim((string) ($p['arg_type'] ?? ''));
            if ($argType !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_ ,.()\[\]]{0,60}$/', $argType)) {
                throw new HttpException(422, "Tipo inválido no parâmetro {$p['name']}.");
            }
            $out[] = [
                'name'     => $p['name'],
                'label'    => mb_substr(trim((string) ($p['label'] ?? '')) ?: self::humanize($p['name']), 0, 80),
                'type'     => in_array($p['type'] ?? '', self::INPUT_TYPES, true) ? $p['type'] : 'text',
                'default'  => mb_substr((string) ($p['default'] ?? ''), 0, 200),
                'required' => filter_var($p['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'options'  => array_slice(array_values(array_filter(array_map(static fn($o) => mb_substr(trim((string) $o), 0, 100), (array) ($p['options'] ?? [])), 'strlen')), 0, 200),
                'arg_type' => $kind === 'function' ? $argType : '',
                'hint'     => mb_substr((string) ($p['hint'] ?? ''), 0, 200),
            ];
        }
        return $out;
    }

    public static function humanize(string $name): string
    {
        $s = preg_replace('/^p_/', '', $name);
        $s = str_replace('_', ' ', (string) $s);
        $s = (string) preg_replace('/(?<=[a-z])(?=\d)/i', ' ', $s); // mes1 → mes 1
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    // ------------------------------------------------------------------ PostgreSQL introspection

    /** Set-returning / regular functions of a connection (PostgreSQL). */
    public function listFunctions(array $conn, ?string $database): array
    {
        $d = DriverFactory::fromConnection($conn)->withDatabase($database);
        if (!$d instanceof PostgresDriver) {
            return [];
        }
        $stmt = $d->pdo()->query("SELECT n.nspname AS schema, p.proname AS name, p.oid::text AS oid,
                pg_get_function_identity_arguments(p.oid) AS args, pg_get_function_result(p.oid) AS returns
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\\_%'
              AND p.prokind = 'f'
            ORDER BY p.proretset DESC, n.nspname, p.proname LIMIT 1000");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Parameters of a function, ready for the analysis form (defaults read from the signature). */
    public function describeFunction(array $conn, ?string $database, string $oid): array
    {
        $d = DriverFactory::fromConnection($conn)->withDatabase($database);
        if (!$d instanceof PostgresDriver || !ctype_digit($oid)) {
            throw new HttpException(422, 'Só disponível para PostgreSQL.');
        }
        $stmt = $d->pdo()->prepare("SELECT n.nspname AS schema, p.proname AS name, pg_get_function_arguments(p.oid) AS args,
                pg_get_function_result(p.oid) AS returns, obj_description(p.oid, 'pg_proc') AS comment
            FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE p.oid = ?::oid");
        $stmt->execute([$oid]);
        $f = $stmt->fetch(\PDO::FETCH_ASSOC) ?: throw new HttpException(404, 'Função não encontrada.');
        $params = [];
        foreach (self::splitTopLevel((string) $f['args']) as $arg) {
            $arg = trim($arg);
            if ($arg === '' || preg_match('/^(OUT|TABLE)\s/i', $arg)) {
                continue;
            }
            $arg = preg_replace('/^(IN|INOUT|VARIADIC)\s+/i', '', $arg);
            $default = null;
            if (preg_match('/^(.*?)\s+(?:DEFAULT|=)\s+(.*)$/is', (string) $arg, $m)) {
                $arg = $m[1];
                $default = trim(preg_replace("/^'(.*)'::[\\w ]+$/s", '$1', trim($m[2])));
            }
            if (!preg_match('/^(' . self::IDENT . ')\s+(.+)$/', trim((string) $arg), $m)) {
                continue; // unnamed argument: cannot use named notation
            }
            $type = strtolower(trim($m[2]));
            $params[] = [
                'name'     => $m[1],
                'label'    => self::humanize($m[1]),
                'type'     => self::inputType($type),
                'default'  => '',               // empty = use the function DEFAULT
                'hint'     => $default !== null ? "por omissão: $default" : 'obrigatório',
                'required' => $default === null,
                'options'  => [],
                'arg_type' => $type,
            ];
        }
        return ['function_name' => ($f['schema'] === 'public' ? '' : $f['schema'] . '.') . $f['name'],
            'params' => $params, 'returns' => $f['returns'], 'comment' => $f['comment']];
    }

    private static function inputType(string $pgType): string
    {
        return match (true) {
            (bool) preg_match('/^(smallint|integer|bigint|int\d?)$/', $pgType)                    => 'integer',
            (bool) preg_match('/^(numeric|decimal|real|double precision|float\d?|money)/', $pgType) => 'number',
            (bool) preg_match('/^(date|timestamp)/', $pgType)                                  => 'date',
            $pgType === 'boolean'                                                              => 'boolean',
            default                                                                             => 'text',
        };
    }

    private static function splitTopLevel(string $s): array
    {
        $parts = [];
        $depth = 0;
        $cur = '';
        $quote = false;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c === "'") {
                $quote = !$quote;
            } elseif (!$quote && $c === '(') {
                $depth++;
            } elseif (!$quote && $c === ')') {
                $depth--;
            } elseif (!$quote && $depth === 0 && $c === ',') {
                $parts[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $parts[] = $cur;
        return $parts;
    }
}
