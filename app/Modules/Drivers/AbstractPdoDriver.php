<?php
declare(strict_types=1);

namespace App\Modules\Drivers;

use App\Modules\Execution\SqlSplitter;
use PDO;

/** Shared PDO plumbing for SQL engines. */
abstract class AbstractPdoDriver implements DriverInterface
{
    protected ?PDO $pdo = null;

    /**
     * @param array{host?: ?string, port?: ?int, database?: ?string, username?: ?string,
     *              password?: ?string, options?: array} $config  Decrypted connection settings (server-side only).
     */
    public function __construct(protected array $config)
    {
        $this->config['options'] = $this->config['options'] ?? [];
    }

    abstract protected function dsn(): string;

    protected function pdoOptions(): array
    {
        return [];
    }

    public static function available(): bool
    {
        return extension_loaded(static::requiredExtension());
    }

    public static function splitMode(): string
    {
        return 'semicolon';
    }

    public static function formFields(): array
    {
        return [
            ['name' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true, 'placeholder' => 'localhost'],
            ['name' => 'port', 'label' => 'Porta', 'type' => 'number', 'required' => false],
            ['name' => 'database_name', 'label' => 'Database', 'type' => 'text', 'required' => false],
            ['name' => 'username', 'label' => 'Utilizador', 'type' => 'text', 'required' => false],
            ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => false],
        ];
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            if (!static::available()) {
                throw new \RuntimeException(sprintf('A extensão PHP "%s" não está instalada no servidor; não é possível ligar a %s.',
                    static::requiredExtension(), static::label()));
            }
            $options = $this->pdoOptions() + [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => (int) ($this->config['options']['connect_timeout'] ?? 10),
            ];
            $this->pdo = new PDO($this->dsn(), $this->config['username'] ?? null, $this->config['password'] ?? null, $options);
        }
        return $this->pdo;
    }

    /**
     * Returns a driver bound to another database of the same server
     * (reconnects; PHP requests are stateless so this is cheap to reason about).
     */
    public function withDatabase(?string $database): static
    {
        if ($database === null || $database === '' || $database === $this->currentDatabase()) {
            return $this;
        }
        self::assertSafeDsnValue($database, 'database');
        $clone = clone $this;
        $clone->pdo = null;
        $clone->config['database'] = $database;
        return $clone;
    }

    /** Guards against DSN injection (e.g. "db;host=evil"). */
    public static function assertSafeDsnValue(?string $value, string $field): void
    {
        if ($value !== null && preg_match('/[;\x00-\x1F\'"=]/', $value)) {
            throw new \InvalidArgumentException("Valor inválido no campo \"$field\".");
        }
    }

    public function currentDatabase(): ?string
    {
        return $this->config['database'] ?? null;
    }

    public function serverVersion(): string
    {
        return (string) $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function open(string $sql, array $params = [], bool $inUserTransaction = false): ResultCursor
    {
        if ($params) {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
        } else {
            $stmt = $this->pdo()->query($sql);
        }
        return new PdoCursor($stmt);
    }

    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    protected function qualified(?string $schema, string $name): string
    {
        return ($schema !== null && $schema !== '' ? $this->quoteIdentifier($schema) . '.' : '') . $this->quoteIdentifier($name);
    }

    public function previewSql(?string $schema, string $table, int $limit = 100): string
    {
        return 'SELECT *' . "\nFROM " . $this->qualified($schema, $table) . "\nLIMIT " . $limit . ';';
    }

    public function databases(): array
    {
        return [];
    }

    public function schemas(?string $database): array
    {
        return [];
    }

    public function foreignKeys(?string $database, ?string $schema, string $table): array
    {
        return [];
    }

    public function definition(?string $database, ?string $schema, string $name, string $type): ?string
    {
        return null;
    }

    public function backendId(): ?string
    {
        return null;
    }

    public function cancel(string $backendId): bool
    {
        return false;
    }

    /** Run a metadata query with bound parameters, returning rows. */
    protected function meta(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function list(string $sql, array $params = []): array
    {
        return array_map(static fn($r) => (string) reset($r), $this->meta($sql, $params));
    }

    public function completion(?string $database, ?string $schema): array
    {
        $map = [];
        try {
            foreach (array_slice($this->objects($database, $schema, 'tables'), 0, 400) as $t) {
                $map[$t['name']] = array_column($this->columns($database, $schema, $t['name']), 'name');
            }
            foreach (array_slice($this->objects($database, $schema, 'views'), 0, 200) as $v) {
                $map[$v['name']] = array_column($this->columns($database, $schema, $v['name']), 'name');
            }
        } catch (\Throwable) {
        }
        return $map;
    }

    public function parseError(\Throwable $e, string $sql): array
    {
        $info = $e instanceof \PDOException ? ($e->errorInfo ?? []) : [];
        $message = (string) ($info[2] ?? $e->getMessage());
        $message = preg_replace('/^SQLSTATE\[\w+\]:?\s*(\[[^\]]*\]\s*)*/', '', $message);
        $code = $info[1] ?? null;
        $state = $info[0] ?? ($e instanceof \PDOException ? (string) $e->getCode() : null);
        $line = null;
        $position = null;

        if (preg_match('/\bat line (\d+)/i', $message, $m) || preg_match('/\bLine (\d+)/', $message, $m)) {
            $line = (int) $m[1];
        }
        // Fallback: locate the token mentioned in `near "X"` / near 'X'
        if ($line === null && preg_match('/near ["\']([^"\']{1,80})["\']/i', $message, $m)) {
            $pos = stripos($sql, $m[1]);
            if ($pos !== false) {
                $line = substr_count($sql, "\n", 0, $pos) + 1;
                $position = $pos + 1;
            }
        }
        return [
            'code'     => $code !== null ? (string) $code : null,
            'sqlstate' => $state,
            'message'  => trim($message),
            'line'     => $line,
            'position' => $position,
        ];
    }

    /** Helper for engines whose PDO driver lacks server-side cursors: use DB-native row limiting. */
    protected static function isSelect(string $sql): bool
    {
        return in_array(SqlSplitter::firstKeyword($sql), ['SELECT', 'WITH', 'VALUES', 'TABLE'], true);
    }
}
