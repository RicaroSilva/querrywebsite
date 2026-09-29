<?php
declare(strict_types=1);

namespace App\Modules\Drivers;

use PDO;

/**
 * Microsoft SQL Server / Azure SQL.
 * Uses pdo_sqlsrv (recommended, Microsoft driver) or falls back to pdo_dblib (FreeTDS).
 */
final class SqlServerDriver extends AbstractPdoDriver
{
    public static function name(): string
    {
        return 'sqlsrv';
    }

    public static function label(): string
    {
        return 'SQL Server';
    }

    public static function requiredExtension(): string
    {
        return 'pdo_sqlsrv';
    }

    public static function available(): bool
    {
        return extension_loaded('pdo_sqlsrv') || extension_loaded('pdo_dblib');
    }

    public static function defaultPort(): int
    {
        return 1433;
    }

    public static function splitMode(): string
    {
        return 'batch'; // split on GO only: variables must survive between statements of a batch
    }

    public static function formFields(): array
    {
        return [
            ...parent::formFields(),
            ['name' => 'encrypt', 'label' => 'Encrypt', 'type' => 'checkbox', 'option' => true, 'default' => true],
            ['name' => 'trust_cert', 'label' => 'Trust server certificate', 'type' => 'checkbox', 'option' => true],
            ['name' => 'connect_timeout', 'label' => 'Connect timeout (s)', 'type' => 'number', 'option' => true, 'default' => 10],
        ];
    }

    public static function capabilities(): array
    {
        return ['databases' => true, 'schemas' => true,
            'objectTypes' => ['tables', 'views', 'functions', 'procedures', 'sequences'], 'cancel' => true];
    }

    private static function useSqlsrv(): bool
    {
        return extension_loaded('pdo_sqlsrv');
    }

    protected function dsn(): string
    {
        $o = $this->config['options'];
        $host = $this->config['host'] ?: 'localhost';
        $port = $this->config['port'] ?: self::defaultPort();
        if (self::useSqlsrv()) {
            $dsn = "sqlsrv:Server=$host,$port;LoginTimeout=" . (int) ($o['connect_timeout'] ?? 10)
                . ';Encrypt=' . (($o['encrypt'] ?? true) ? 'yes' : 'no')
                . ';TrustServerCertificate=' . (!empty($o['trust_cert']) ? 'yes' : 'no')
                . ';APP=QueryDeck';
            if (!empty($this->config['database'])) {
                $dsn .= ';Database=' . $this->config['database'];
            }
            return $dsn;
        }
        $dsn = "dblib:host=$host:$port;charset=UTF-8;appname=QueryDeck";
        if (!empty($this->config['database'])) {
            $dsn .= ';dbname=' . $this->config['database'];
        }
        return $dsn;
    }

    protected function pdoOptions(): array
    {
        return self::useSqlsrv() ? [PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8] : [];
    }

    public function prepareSession(int $timeoutSeconds, bool $readOnly): void
    {
        if (self::useSqlsrv()) {
            $this->pdo()->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, max(0, $timeoutSeconds));
        }
        $this->pdo()->exec('SET NOCOUNT OFF; SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON; SET ANSI_WARNINGS ON');
        // SQL Server has no session-level read-only switch: QueryDeck relies on the
        // statement classifier + the login's permissions (use a db_datareader login).
    }

    public function backendId(): ?string
    {
        return (string) $this->pdo()->query('SELECT @@SPID')->fetchColumn();
    }

    public function cancel(string $backendId): bool
    {
        $this->pdo()->exec('KILL ' . (int) $backendId); // requires ALTER ANY CONNECTION
        return true;
    }

    public function quoteIdentifier(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    public function previewSql(?string $schema, string $table, int $limit = 100): string
    {
        return "SELECT TOP ($limit) *\nFROM " . $this->qualified($schema ?: 'dbo', $table) . ';';
    }

    /** Three-part name prefix for catalog views of another database: [db].sys.objects */
    private function cat(?string $database): string
    {
        return $database ? $this->quoteIdentifier($database) . '.' : '';
    }

    public function databases(): array
    {
        return array_map(static fn($n) => ['name' => $n],
            $this->list('SELECT name FROM sys.databases WHERE state = 0 AND HAS_DBACCESS(name) = 1 ORDER BY name'));
    }

    public function schemas(?string $database): array
    {
        return array_map(static fn($n) => ['name' => $n], $this->list("SELECT s.name FROM {$this->cat($database)}sys.schemas s
            WHERE s.schema_id < 16384 AND s.name NOT IN ('sys','INFORMATION_SCHEMA','guest') ORDER BY s.name"));
    }

    public function objects(?string $database, ?string $schema, string $type): array
    {
        $types = ['tables' => "'U'", 'views' => "'V'", 'functions' => "'FN','IF','TF','FS','FT'", 'procedures' => "'P','PC'", 'sequences' => "'SO'"];
        if (!isset($types[$type])) {
            return [];
        }
        $c = $this->cat($database);
        $rows = $type === 'tables'
            ? "(SELECT SUM(p.rows) FROM {$c}sys.partitions p WHERE p.object_id = o.object_id AND p.index_id IN (0,1))"
            : 'NULL';
        return $this->meta("SELECT o.name, o.type_desc AS kind, $rows AS rows_estimate
            FROM {$c}sys.objects o JOIN {$c}sys.schemas s ON s.schema_id = o.schema_id
            WHERE s.name = ? AND o.type IN ({$types[$type]}) AND o.is_ms_shipped = 0
            ORDER BY o.name", [$schema ?: 'dbo']);
    }

    public function columns(?string $database, ?string $schema, string $table): array
    {
        $c = $this->cat($database);
        return array_map(static fn($r) => [
            'name'     => $r['name'],
            'type'     => $r['type'],
            'nullable' => (bool) $r['nullable'],
            'default'  => $r['default_value'],
            'primary'  => (bool) $r['is_pk'],
            'extra'    => $r['is_identity'] ? 'IDENTITY' : '',
        ], $this->meta("SELECT col.name, t.name + CASE
                    WHEN t.name IN ('varchar','char','varbinary','binary') THEN '(' + CASE WHEN col.max_length = -1 THEN 'max' ELSE CAST(col.max_length AS varchar) END + ')'
                    WHEN t.name IN ('nvarchar','nchar') THEN '(' + CASE WHEN col.max_length = -1 THEN 'max' ELSE CAST(col.max_length / 2 AS varchar) END + ')'
                    WHEN t.name IN ('decimal','numeric') THEN '(' + CAST(col.precision AS varchar) + ',' + CAST(col.scale AS varchar) + ')'
                    ELSE '' END AS type,
                col.is_nullable AS nullable, col.is_identity, dc.definition AS default_value,
                CASE WHEN EXISTS (SELECT 1 FROM {$c}sys.index_columns ic JOIN {$c}sys.indexes i ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                    WHERE i.is_primary_key = 1 AND ic.object_id = col.object_id AND ic.column_id = col.column_id) THEN 1 ELSE 0 END AS is_pk
            FROM {$c}sys.columns col
            JOIN {$c}sys.types t ON t.user_type_id = col.user_type_id
            JOIN {$c}sys.objects o ON o.object_id = col.object_id
            JOIN {$c}sys.schemas s ON s.schema_id = o.schema_id
            LEFT JOIN {$c}sys.default_constraints dc ON dc.object_id = col.default_object_id
            WHERE s.name = ? AND o.name = ?
            ORDER BY col.column_id", [$schema ?: 'dbo', $table]));
    }

    public function indexes(?string $database, ?string $schema, string $table): array
    {
        $c = $this->cat($database);
        $rows = $this->meta("SELECT i.name AS index_name, i.is_unique, i.is_primary_key, i.type_desc, col.name AS column_name
            FROM {$c}sys.indexes i
            JOIN {$c}sys.objects o ON o.object_id = i.object_id
            JOIN {$c}sys.schemas s ON s.schema_id = o.schema_id
            JOIN {$c}sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
            JOIN {$c}sys.columns col ON col.object_id = ic.object_id AND col.column_id = ic.column_id
            WHERE s.name = ? AND o.name = ? AND i.name IS NOT NULL
            ORDER BY i.name, ic.key_ordinal", [$schema ?: 'dbo', $table]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['index_name']] ??= ['name' => $r['index_name'], 'unique' => (bool) $r['is_unique'],
                'primary' => (bool) $r['is_primary_key'], 'method' => $r['type_desc'], 'columns' => []];
            $out[$r['index_name']]['columns'][] = $r['column_name'];
        }
        return array_map(static fn($i) => [...$i, 'definition' => '(' . implode(', ', $i['columns']) . ')'], array_values($out));
    }

    public function foreignKeys(?string $database, ?string $schema, string $table): array
    {
        $c = $this->cat($database);
        return array_map(static fn($r) => ['name' => $r['name'],
            'definition' => "({$r['col']}) REFERENCES {$r['ref_table']}({$r['ref_col']})"],
            $this->meta("SELECT fk.name, pc.name AS col, rt.name AS ref_table, rc.name AS ref_col
                FROM {$c}sys.foreign_keys fk
                JOIN {$c}sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
                JOIN {$c}sys.objects o ON o.object_id = fk.parent_object_id
                JOIN {$c}sys.schemas s ON s.schema_id = o.schema_id
                JOIN {$c}sys.columns pc ON pc.object_id = fkc.parent_object_id AND pc.column_id = fkc.parent_column_id
                JOIN {$c}sys.objects rt ON rt.object_id = fkc.referenced_object_id
                JOIN {$c}sys.columns rc ON rc.object_id = fkc.referenced_object_id AND rc.column_id = fkc.referenced_column_id
                WHERE s.name = ? AND o.name = ?", [$schema ?: 'dbo', $table]));
    }

    public function definition(?string $database, ?string $schema, string $name, string $type): ?string
    {
        if (!in_array($type, ['view', 'function', 'procedure'], true)) {
            return null;
        }
        $full = ($database ? $this->quoteIdentifier($database) . '.' : '') . $this->qualified($schema ?: 'dbo', $name);
        $rows = $this->meta('SELECT OBJECT_DEFINITION(OBJECT_ID(?)) AS def', [$full]);
        return $rows[0]['def'] ?? null;
    }

    public function parseError(\Throwable $e, string $sql): array
    {
        $err = parent::parseError($e, $sql);
        $err['message'] = preg_replace('/^\[Microsoft\]\[[^\]]+\](\[SQL Server\])?/', '', $err['message']);
        return $err;
    }
}
