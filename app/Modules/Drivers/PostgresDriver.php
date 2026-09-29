<?php
declare(strict_types=1);

namespace App\Modules\Drivers;

use App\Modules\Execution\SqlSplitter;
use PDO;

final class PostgresDriver extends AbstractPdoDriver
{
    private bool $readOnly = false;

    public static function name(): string
    {
        return 'pgsql';
    }

    public static function label(): string
    {
        return 'PostgreSQL';
    }

    public static function requiredExtension(): string
    {
        return 'pdo_pgsql';
    }

    public static function formFields(): array
    {
        return [
            ...parent::formFields(),
            ['name' => 'sslmode', 'label' => 'SSL mode', 'type' => 'select', 'option' => true, 'default' => 'prefer',
                'choices' => ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full']],
            ['name' => 'sslrootcert', 'label' => 'CA certificate (caminho no servidor)', 'type' => 'text', 'option' => true],
            ['name' => 'connect_timeout', 'label' => 'Connect timeout (s)', 'type' => 'number', 'option' => true, 'default' => 10],
        ];
    }

    public static function defaultPort(): int
    {
        return 5432;
    }

    public static function capabilities(): array
    {
        return ['databases' => true, 'schemas' => true,
            'objectTypes' => ['tables', 'views', 'functions', 'procedures', 'sequences'], 'cancel' => true];
    }

    protected function dsn(): string
    {
        $o = $this->config['options'];
        $parts = [
            'host'            => $this->config['host'] ?: 'localhost',
            'port'            => $this->config['port'] ?: self::defaultPort(),
            'dbname'          => $this->config['database'] ?: 'postgres',
            'sslmode'         => $o['sslmode'] ?? 'prefer',
            'connect_timeout' => (int) ($o['connect_timeout'] ?? 10),
        ];
        if (!empty($o['sslrootcert'])) {
            $parts['sslrootcert'] = $o['sslrootcert'];
        }
        $dsn = [];
        foreach ($parts as $k => $v) {
            // libpq conninfo quoting
            $dsn[] = $k . "='" . addcslashes((string) $v, "'\\") . "'";
        }
        return 'pgsql:' . implode(';', $dsn);
    }

    public function prepareSession(int $timeoutSeconds, bool $readOnly): void
    {
        $pdo = $this->pdo();
        $pdo->exec("SET application_name = 'QueryDeck'");
        $pdo->exec('SET statement_timeout = ' . max(0, $timeoutSeconds) * 1000);
        if ($readOnly) {
            $this->readOnly = true;
            $pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
        }
    }

    public function backendId(): ?string
    {
        return (string) $this->pdo()->query('SELECT pg_backend_pid()')->fetchColumn();
    }

    public function cancel(string $backendId): bool
    {
        $stmt = $this->pdo()->prepare('SELECT pg_cancel_backend(?::int)');
        $stmt->execute([(int) $backendId]);
        return (bool) $stmt->fetchColumn();
    }

    public function open(string $sql, array $params = [], bool $inUserTransaction = false): ResultCursor
    {
        // Large-result safe path: server-side cursor for plain read queries.
        if (!$params && SqlSplitter::isReadOnly($sql)
            && in_array(SqlSplitter::firstKeyword($sql), ['SELECT', 'WITH', 'VALUES', 'TABLE'], true)) {
            return new PgServerCursor($this->pdo(), $sql, !$inUserTransaction,
                $this->readOnly ? 'BEGIN TRANSACTION READ ONLY' : 'BEGIN');
        }
        if ($this->readOnly && !$inUserTransaction) {
            // Explicit read-only transaction per statement: immune to session-level overrides.
            $this->pdo()->exec('BEGIN TRANSACTION READ ONLY');
            try {
                $cursor = parent::open($sql, $params, true);
                return new class ($cursor, $this->pdo()) implements ResultCursor {
                    public function __construct(private ResultCursor $inner, private \PDO $pdo)
                    {
                    }

                    public function hasRows(): bool { return $this->inner->hasRows(); }
                    public function columns(): array { return $this->inner->columns(); }
                    public function fetch(): ?array { return $this->inner->fetch(); }
                    public function affected(): int { return $this->inner->affected(); }
                    public function nextRowset(): bool { return $this->inner->nextRowset(); }

                    public function close(): void
                    {
                        $this->inner->close();
                        try {
                            $this->pdo->exec('COMMIT');
                        } catch (\Throwable) {
                        }
                    }
                };
            } catch (\Throwable $e) {
                $this->pdo()->exec('ROLLBACK');
                throw $e;
            }
        }
        return parent::open($sql, $params, $inUserTransaction);
    }

    public function databases(): array
    {
        return array_map(static fn($n) => ['name' => $n],
            $this->list('SELECT datname FROM pg_database WHERE datallowconn AND NOT datistemplate ORDER BY datname'));
    }

    public function schemas(?string $database): array
    {
        return array_map(static fn($n) => ['name' => $n], $this->list(
            "SELECT nspname FROM pg_namespace WHERE nspname NOT LIKE 'pg\\_%' AND nspname <> 'information_schema' ORDER BY nspname"));
    }

    private function versionNum(): int
    {
        static $v = [];
        return $v[spl_object_id($this)] ??= (int) $this->pdo()->query('SHOW server_version_num')->fetchColumn();
    }

    public function objects(?string $database, ?string $schema, string $type): array
    {
        $schema = $schema ?: 'public';
        $relkinds = ['tables' => "'r','p','f'", 'views' => "'v','m'", 'sequences' => "'S'"];
        if (isset($relkinds[$type])) {
            return $this->meta("SELECT c.relname AS name, c.relkind AS kind,
                    CASE WHEN c.relkind IN ('r','p') THEN GREATEST(c.reltuples, 0)::bigint END AS rows_estimate,
                    CASE WHEN c.relkind IN ('r','p','m') THEN pg_total_relation_size(c.oid) END AS size_bytes,
                    obj_description(c.oid, 'pg_class') AS comment
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = ? AND c.relkind IN ({$relkinds[$type]})
                ORDER BY c.relname", [$schema]);
        }
        if ($type === 'functions' || $type === 'procedures') {
            $kindFilter = $this->versionNum() >= 110000
                ? ($type === 'functions' ? "p.prokind IN ('f','w')" : "p.prokind = 'p'")
                : ($type === 'functions' ? 'NOT p.proisagg' : 'false');
            return $this->meta("SELECT p.proname AS name, p.oid::text AS key,
                    p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')' AS label,
                    pg_get_function_result(p.oid) AS returns, l.lanname AS language
                FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                JOIN pg_language l ON l.oid = p.prolang
                WHERE n.nspname = ? AND $kindFilter
                ORDER BY p.proname", [$schema]);
        }
        return [];
    }

    private function relOid(?string $schema, string $table): string
    {
        return "(SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ? LIMIT 1)";
    }

    public function columns(?string $database, ?string $schema, string $table): array
    {
        return array_map(static function ($r) {
            $r['nullable'] = (bool) $r['nullable'];
            $r['primary'] = (bool) $r['primary'];
            return $r;
        }, $this->meta("SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type,
                NOT a.attnotnull AS nullable, pg_get_expr(d.adbin, d.adrelid) AS \"default\",
                COALESCE((SELECT true FROM pg_index i WHERE i.indrelid = a.attrelid AND i.indisprimary
                          AND a.attnum = ANY(i.indkey)), false) AS primary,
                col_description(a.attrelid, a.attnum) AS comment
            FROM pg_attribute a
            LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
            WHERE a.attrelid = {$this->relOid($schema, $table)} AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum", [$schema ?: 'public', $table]));
    }

    public function indexes(?string $database, ?string $schema, string $table): array
    {
        return $this->meta("SELECT i.relname AS name, ix.indisunique AS unique, ix.indisprimary AS primary,
                am.amname AS method, pg_get_indexdef(ix.indexrelid) AS definition
            FROM pg_index ix
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN pg_am am ON am.oid = i.relam
            WHERE ix.indrelid = {$this->relOid($schema, $table)}
            ORDER BY ix.indisprimary DESC, i.relname", [$schema ?: 'public', $table]);
    }

    public function foreignKeys(?string $database, ?string $schema, string $table): array
    {
        return $this->meta("SELECT conname AS name, pg_get_constraintdef(oid) AS definition
            FROM pg_constraint WHERE contype = 'f' AND conrelid = {$this->relOid($schema, $table)}
            ORDER BY conname", [$schema ?: 'public', $table]);
    }

    public function definition(?string $database, ?string $schema, string $name, string $type): ?string
    {
        if ($type === 'view') {
            $rows = $this->meta("SELECT c.relkind, pg_get_viewdef(c.oid, true) AS def FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = ? AND c.relname = ?", [$schema ?: 'public', $name]);
            if (!$rows) {
                return null;
            }
            $kw = $rows[0]['relkind'] === 'm' ? 'CREATE MATERIALIZED VIEW ' : 'CREATE OR REPLACE VIEW ';
            return $kw . $this->qualified($schema, $name) . " AS\n" . $rows[0]['def'];
        }
        if ($type === 'function' || $type === 'procedure') {
            // $name is the pg_proc OID (key) for overloaded functions
            $oid = ctype_digit($name) ? (int) $name : null;
            $rows = $oid !== null
                ? $this->meta('SELECT pg_get_functiondef(?::oid) AS def', [$oid])
                : $this->meta('SELECT pg_get_functiondef(p.oid) AS def FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                    WHERE n.nspname = ? AND p.proname = ? LIMIT 1', [$schema ?: 'public', $name]);
            return $rows[0]['def'] ?? null;
        }
        if ($type === 'sequence') {
            $rows = $this->meta('SELECT * FROM pg_sequences WHERE schemaname = ? AND sequencename = ?', [$schema ?: 'public', $name]);
            if (!$rows) {
                return null;
            }
            $s = $rows[0];
            return sprintf("CREATE SEQUENCE %s\n  START %s INCREMENT %s MINVALUE %s MAXVALUE %s%s;\n-- last_value: %s",
                $this->qualified($schema, $name), $s['start_value'], $s['increment_by'], $s['min_value'], $s['max_value'],
                $s['cycle'] ? ' CYCLE' : '', $s['last_value'] ?? 'null');
        }
        return null;
    }

    public function completion(?string $database, ?string $schema): array
    {
        $rows = $this->meta("SELECT c.relname AS t, a.attname AS col FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
            WHERE n.nspname = ? AND c.relkind IN ('r','p','v','m','f')
            ORDER BY c.relname, a.attnum LIMIT 20000", [$schema ?: 'public']);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['t']][] = $r['col'];
        }
        return $map;
    }

    public function parseError(\Throwable $e, string $sql): array
    {
        $err = parent::parseError($e, $sql);
        $raw = $e instanceof \PDOException ? (string) ($e->errorInfo[2] ?? $e->getMessage()) : $e->getMessage();
        $err['code'] = $err['sqlstate'];
        // libpq: "ERROR:  msg\nLINE 3: SELECT * FORM t\n                 ^"
        if (preg_match('/^LINE (\d+): (.*)\n(\s*)\^/m', $raw, $m)) {
            $err['line'] = (int) $m[1];
            $err['column'] = strlen($m[3]) - strlen("LINE {$m[1]}: ") + 1;
            $err['position'] = null;
        }
        $err['message'] = trim(preg_replace('/^ERROR:\s+/', '', explode("\nLINE", $err['message'])[0]));
        return $err;
    }
}
