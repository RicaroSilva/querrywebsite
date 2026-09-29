<?php
declare(strict_types=1);

namespace App\Modules\Execution;

/**
 * Splits a SQL script into executable statements, aware of:
 *  - '…' strings (with '' escapes and backslash escapes for MySQL), "…" and `…` identifiers, […] (T-SQL)
 *  - -- / # line comments and block comments
 *  - PostgreSQL $tag$ … $tag$ dollar quoting
 *  - MySQL DELIMITER directives
 *  - T-SQL `GO` batch separators (mode "batch": semicolons do NOT split)
 *
 * Each statement is returned with its starting line (1-based) in the original text,
 * so error positions can be mapped back to the editor.
 */
final class SqlSplitter
{
    /**
     * @param string $mode 'semicolon' | 'batch' (T-SQL GO)
     * @param string $dialect pgsql|mysql|sqlsrv|sqlite
     * @return array<int, array{sql: string, line: int, offset: int}>
     */
    public static function split(string $sql, string $mode = 'semicolon', string $dialect = 'pgsql'): array
    {
        if ($mode === 'batch') {
            return self::splitBatches($sql);
        }

        $out = [];
        $len = strlen($sql);
        $delimiter = ';';
        $start = 0;
        $i = 0;
        $backslashEscapes = $dialect === 'mysql';

        while ($i < $len) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            // MySQL DELIMITER directive at start of a line
            if ($dialect === 'mysql' && ($i === 0 || $sql[$i - 1] === "\n")
                && preg_match('/\GDELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/Ai', $sql, $m, 0, $i)) {
                self::push($out, $sql, $start, $i);
                $delimiter = $m[1];
                $i += strlen($m[0]);
                $start = $i;
                continue;
            }

            if ($c === '-' && $next === '-' || ($c === '#' && $dialect === 'mysql')) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $i = self::skipQuoted($sql, $i, $c, $backslashEscapes && $c !== '`');
                continue;
            }
            if ($c === '[' && $dialect === 'sqlsrv') {
                $end = strpos($sql, ']', $i + 1);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === '$' && $dialect === 'pgsql' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/A', $sql, $m, 0, $i)) {
                $tag = $m[0];
                $end = strpos($sql, $tag, $i + strlen($tag));
                $i = $end === false ? $len : $end + strlen($tag);
                continue;
            }
            if (substr_compare($sql, $delimiter, $i, strlen($delimiter)) === 0) {
                self::push($out, $sql, $start, $i);
                $i += strlen($delimiter);
                $start = $i;
                continue;
            }
            $i++;
        }
        self::push($out, $sql, $start, $len);
        return $out;
    }

    private static function skipQuoted(string $sql, int $i, string $q, bool $backslash): int
    {
        $len = strlen($sql);
        $i++;
        while ($i < $len) {
            if ($backslash && $sql[$i] === '\\') {
                $i += 2;
                continue;
            }
            if ($sql[$i] === $q) {
                if (($sql[$i + 1] ?? '') === $q) { // doubled quote escape
                    $i += 2;
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }
        return $len;
    }

    private static function push(array &$out, string $sql, int $start, int $end): void
    {
        $chunk = substr($sql, $start, $end - $start);
        if (trim(self::stripComments($chunk)) === '') {
            return;
        }
        // Skip leading whitespace so the reported line is where the statement text starts.
        $lead = strlen($chunk) - strlen(ltrim($chunk));
        $offset = $start + $lead;
        $out[] = [
            'sql'    => trim($chunk),
            'line'   => substr_count($sql, "\n", 0, $offset) + 1,
            'offset' => $offset,
        ];
    }

    private static function splitBatches(string $sql): array
    {
        $out = [];
        $lines = preg_split('/(?<=\n)/', $sql);
        $buffer = '';
        $bufferStart = 0;
        $offset = 0;
        foreach ($lines as $line) {
            if (preg_match('/^\s*GO\s*(--.*)?$/i', rtrim($line, "\r\n"))) {
                self::push($out, $sql, $bufferStart, $bufferStart + strlen($buffer));
                $offset += strlen($line);
                $buffer = '';
                $bufferStart = $offset;
                continue;
            }
            $buffer .= $line;
            $offset += strlen($line);
        }
        self::push($out, $sql, $bufferStart, $bufferStart + strlen($buffer));
        return $out;
    }

    public static function stripComments(string $sql): string
    {
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql);
        return (string) preg_replace('/(--|#)[^\n]*/', ' ', (string) $sql);
    }

    /** First keyword of a statement, uppercased (comments stripped). */
    public static function firstKeyword(string $sql): string
    {
        $clean = ltrim(self::stripComments($sql), " \t\r\n(");
        return preg_match('/^([A-Za-z]+)/', $clean, $m) ? strtoupper($m[1]) : '';
    }

    /**
     * Conservative read-only classifier used as a safety net for read-only
     * connections / users. The database user's own privileges remain the
     * authoritative protection.
     */
    public static function isReadOnly(string $sql): bool
    {
        $kw = self::firstKeyword($sql);
        $readKeywords = ['SELECT', 'WITH', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC', 'VALUES', 'TABLE', 'PRAGMA'];
        if (!in_array($kw, $readKeywords, true)) {
            return false;
        }
        $clean = strtoupper(self::stripComments(preg_replace("/'(?:[^']|'')*'/", "''", $sql)));
        // Data-modifying CTEs, SELECT INTO, EXPLAIN ANALYZE of writes, pragma assignments...
        if (preg_match('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|INTO|CALL|EXEC|EXECUTE|COPY|LOCK|SET|ANALYZE|ANALYSE)\b/', $clean)) {
            return false;
        }
        // Functions that change session state or reach outside the database
        if (preg_match('/\b(SET_CONFIG|DBLINK\w*|LO_IMPORT|LO_EXPORT|PG_READ_\w+|PG_TERMINATE_BACKEND|PG_CANCEL_BACKEND|LOAD_FILE|SLEEP|BENCHMARK|XP_\w+|OPENROWSET|OPENQUERY)\s*\(/', $clean)) {
            return false;
        }
        if ($kw === 'PRAGMA' && str_contains($clean, '=')) {
            return false;
        }
        return true;
    }
}
