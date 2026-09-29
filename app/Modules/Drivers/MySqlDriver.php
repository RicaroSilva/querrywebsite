<?php
declare(strict_types=1);

namespace App\Modules\Drivers;

use PDO;

/** MySQL and MariaDB. */
final class MySqlDriver extends AbstractPdoDriver
{
    public static function name(): string
    {
        return 'mysql';
    }

    public static function label(): string
    {
        return 'MySQL / MariaDB';
    }

    public static function requiredExtension(): string
    {
        return 'pdo_mysql';
    }

    public static function defaultPort(): int
    {
        return 3306;
    }

    public static function formFields(): array
    {
        return [
            ...parent::formFields(),
            ['name' => 'ssl', 'label' => 'Usar SSL/TLS', 'type' => 'checkbox', 'option' => true],
            ['name' => 'ssl_ca', 'label' => 'CA certificate (caminho no servidor)', 'type' => 'text', 'option' => true],
            ['name' => 'ssl_verify', 'label' => 'Verificar certificado do servidor', 'type' => 'checkbox', 'option' => true, 'default' => true],
            ['name' => 'connect_timeout', 'label' => 'Connect timeout (s)', 'type' => 'number', 'option' => true, 'default' => 10],
        ];
    }

    public static function capabilities(): array
    {
        // In MySQL a "database" is a schema: the explorer lists databases → objects directly.
        return ['databases' => true, 'schemas' => false,
            'objectTypes' => ['tables', 'views', 'functions', 'procedures'], 'cancel' => true];
    }

    protected function dsn(): string
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->config['host'] ?: 'localhost',
            $this->config['port'] ?: self::defaultPort());
        if (!empty($this->config['database'])) {
            $dsn .= ';dbname=' . $this->config['database'];
        }
        return $dsn;
    }

    protected function pdoOptions(): array
    {
        $o = $this->config['options'];
        // Unbuffered: rows stream from the server instead of being loaded in PHP memory all at once.
        $opts = [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false];
        if (!empty($o['ssl']) || !empty($o['ssl_ca'])) {
            if (!empty($o['ssl_ca'])) {
                $opts[PDO::MYSQL_ATTR_SSL_CA] = $o['ssl_ca'];
            }
            $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) ($o['ssl_verify'] ?? true);
        }
        return $opts;
    }

    private function isMaria(): bool
    {
        return stripos($this->serverVersion(), 'mariadb') !== false;
    }

    public function prepareSession(int $timeoutSeconds, bool $readOnly): void
    {
        $pdo = $this->pdo();
        try {
            $pdo->exec($this->isMaria()
                ? 'SET SESSION max_statement_time = ' . max(0, $timeoutSeconds)
                : 'SET SESSION max_execution_time = ' . max(0, $timeoutSeconds) * 1000);
        } catch (\Throwable) {
            // older servers: no statement timeout support
        }
        if ($readOnly) {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        }
    }

    public function backendId(): ?string
    {
        return (string) $this->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    }

    public function cancel(string $backendId): bool
    {
        $this->pdo()->exec('KILL QUERY ' . (int) $backendId);
        return true;
    }

    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function currentDatabase(): ?string
    {
        return $this->config['database'] ?: null;
    }

    private function db(?string $database): string
    {
        return $database ?: (string) ($this->currentDatabase() ?? $this->pdo()->query('SELECT DATABASE()')->fetchColumn());
    }

    public function previewSql(?string $schema, string $table, int $limit = 100): string
    {
        return "SELECT *\nFROM " . $this->qualified($schema, $table) . "\nLIMIT $limit;";
    }

    public function databases(): array
    {
        return array_map(static fn($n) => ['name' => $n],
            $this->list('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA ORDER BY SCHEMA_NAME'));
    }

    public function objects(?string $database, ?string $schema, string $type): array
    {
        $db = $this->db($database);
        return match ($type) {
            'tables', 'views' => $this->meta('SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS rows_estimate,
                    (DATA_LENGTH + INDEX_LENGTH) AS size_bytes, TABLE_COMMENT AS comment
                FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE ' . ($type === 'tables' ? "IN ('BASE TABLE','SYSTEM VERSIONED')" : "= 'VIEW'")
                . ' ORDER BY TABLE_NAME', [$db]),
            'functions', 'procedures' => $this->meta('SELECT ROUTINE_NAME AS name, DTD_IDENTIFIER AS returns
                FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = ? ORDER BY ROUTINE_NAME',
                [$db, $type === 'functions' ? 'FUNCTION' : 'PROCEDURE']),
            default => [],
        };
    }

    public function columns(?string $database, ?string $schema, string $table): array
    {
        return array_map(static fn($r) => [
            'name'     => $r['COLUMN_NAME'],
            'type'     => $r['COLUMN_TYPE'],
            'nullable' => $r['IS_NULLABLE'] === 'YES',
            'default'  => $r['COLUMN_DEFAULT'],
            'primary'  => $r['COLUMN_KEY'] === 'PRI',
            'extra'    => $r['EXTRA'],
            'comment'  => $r['COLUMN_COMMENT'],
        ], $this->meta('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, COLUMN_COMMENT
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->db($database), $table]));
    }

    public function indexes(?string $database, ?string $schema, string $table): array
    {
        $rows = $this->meta('SELECT INDEX_NAME, NON_UNIQUE, INDEX_TYPE, COLUMN_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$this->db($database), $table]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['INDEX_NAME']] ??= ['name' => $r['INDEX_NAME'], 'unique' => !$r['NON_UNIQUE'],
                'primary' => $r['INDEX_NAME'] === 'PRIMARY', 'method' => $r['INDEX_TYPE'], 'columns' => []];
            $out[$r['INDEX_NAME']]['columns'][] = $r['COLUMN_NAME'];
        }
        return array_map(static fn($i) => [...$i, 'definition' => '(' . implode(', ', $i['columns']) . ')'], array_values($out));
    }

    public function foreignKeys(?string $database, ?string $schema, string $table): array
    {
        return array_map(static fn($r) => ['name' => $r['CONSTRAINT_NAME'],
            'definition' => "({$r['COLUMN_NAME']}) REFERENCES {$r['REFERENCED_TABLE_NAME']}({$r['REFERENCED_COLUMN_NAME']})"],
            $this->meta('SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$this->db($database), $table]));
    }

    public function definition(?string $database, ?string $schema, string $name, string $type): ?string
    {
        $kw = ['table' => 'TABLE', 'view' => 'VIEW', 'function' => 'FUNCTION', 'procedure' => 'PROCEDURE'][$type] ?? null;
        if (!$kw) {
            return null;
        }
        $row = $this->pdo()->query("SHOW CREATE $kw " . $this->qualified($this->db($database), $name))->fetchAll(PDO::FETCH_NUM)[0] ?? [];
        // Column index of the DDL differs per object type
        foreach ((array) $row as $v) {
            if (is_string($v) && preg_match('/^\s*CREATE\b/i', $v)) {
                return $v;
            }
        }
        return null;
    }

    public function completion(?string $database, ?string $schema): array
    {
        $map = [];
        foreach ($this->meta('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME, ORDINAL_POSITION LIMIT 20000', [$this->db($database)]) as $r) {
            $map[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
        }
        return $map;
    }
}
