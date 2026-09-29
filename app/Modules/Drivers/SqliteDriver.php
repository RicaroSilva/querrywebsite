<?php
declare(strict_types=1);

namespace App\Modules\Drivers;

use PDO;

/**
 * SQLite database files located on the QueryDeck server.
 * For safety, files must live inside SQLITE_ALLOWED_DIRS (default: storage/sqlite).
 */
final class SqliteDriver extends AbstractPdoDriver
{
    public static function name(): string
    {
        return 'sqlite';
    }

    public static function label(): string
    {
        return 'SQLite';
    }

    public static function requiredExtension(): string
    {
        return 'pdo_sqlite';
    }

    public static function defaultPort(): ?int
    {
        return null;
    }

    public static function formFields(): array
    {
        return [
            ['name' => 'database_name', 'label' => 'Ficheiro da base de dados', 'type' => 'text', 'required' => true,
                'placeholder' => 'storage/sqlite/minha-bd.sqlite',
                'help' => 'Caminho no servidor, dentro de: ' . implode(', ', self::allowedDirs())],
        ];
    }

    public static function capabilities(): array
    {
        return ['databases' => false, 'schemas' => false, 'objectTypes' => ['tables', 'views'], 'cancel' => false];
    }

    public static function allowedDirs(): array
    {
        $dirs = array_filter(array_map('trim', explode(',', (string) env('SQLITE_ALLOWED_DIRS', 'storage/sqlite'))));
        return array_map(static fn($d) => is_absolute_path($d) ? rtrim($d, '/\\') : base_path(rtrim($d, '/\\')), $dirs);
    }

    /** Resolve and validate the file path (prevents reading arbitrary files on the server). */
    public static function resolvePath(string $path): string
    {
        $abs = is_absolute_path($path) ? $path : base_path($path);
        $real = realpath($abs);
        if ($real === false || !is_file($real)) {
            throw new \RuntimeException("Ficheiro SQLite não encontrado: $path");
        }
        foreach (self::allowedDirs() as $dir) {
            $realDir = realpath($dir);
            if ($realDir && str_starts_with($real, $realDir . DIRECTORY_SEPARATOR)) {
                // never allow pointing at QueryDeck's own application database
                $appDb = realpath(base_path((string) config('database.sqlite')));
                if ($appDb && $real === $appDb) {
                    throw new \RuntimeException('Não é permitido ligar à base de dados interna da aplicação.');
                }
                return $real;
            }
        }
        throw new \RuntimeException('O ficheiro SQLite tem de estar numa pasta permitida (SQLITE_ALLOWED_DIRS).');
    }

    protected function dsn(): string
    {
        return 'sqlite:' . self::resolvePath((string) ($this->config['database'] ?? ''));
    }

    public function withDatabase(?string $database): static
    {
        return $this;
    }

    public function currentDatabase(): ?string
    {
        return 'main';
    }

    public function prepareSession(int $timeoutSeconds, bool $readOnly): void
    {
        $this->pdo()->exec('PRAGMA busy_timeout = ' . min(30000, max(1000, $timeoutSeconds * 1000)));
        if ($readOnly) {
            $this->pdo()->exec('PRAGMA query_only = ON');
        }
    }

    public function objects(?string $database, ?string $schema, string $type): array
    {
        $kind = ['tables' => 'table', 'views' => 'view'][$type] ?? null;
        if (!$kind) {
            return [];
        }
        return $this->meta("SELECT name FROM sqlite_master WHERE type = ? AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name", [$kind]);
    }

    public function columns(?string $database, ?string $schema, string $table): array
    {
        return array_map(static fn($r) => [
            'name'     => $r['name'],
            'type'     => $r['type'],
            'nullable' => !$r['notnull'],
            'default'  => $r['dflt_value'],
            'primary'  => (bool) $r['pk'],
        ], $this->meta('SELECT * FROM pragma_table_info(?)', [$table]));
    }

    public function indexes(?string $database, ?string $schema, string $table): array
    {
        $out = [];
        foreach ($this->meta('SELECT * FROM pragma_index_list(?)', [$table]) as $idx) {
            $cols = array_column($this->meta('SELECT name FROM pragma_index_info(?)', [$idx['name']]), 'name');
            $out[] = ['name' => $idx['name'], 'unique' => (bool) $idx['unique'], 'primary' => ($idx['origin'] ?? '') === 'pk',
                'method' => 'btree', 'columns' => $cols, 'definition' => '(' . implode(', ', $cols) . ')'];
        }
        return $out;
    }

    public function foreignKeys(?string $database, ?string $schema, string $table): array
    {
        return array_map(static fn($r) => ['name' => 'fk_' . $r['id'],
            'definition' => "({$r['from']}) REFERENCES {$r['table']}({$r['to']})"],
            $this->meta('SELECT * FROM pragma_foreign_key_list(?)', [$table]));
    }

    public function definition(?string $database, ?string $schema, string $name, string $type): ?string
    {
        $rows = $this->meta('SELECT sql FROM sqlite_master WHERE name = ?', [$name]);
        return $rows[0]['sql'] ?? null;
    }
}
