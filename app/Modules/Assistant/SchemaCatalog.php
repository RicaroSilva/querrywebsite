<?php
declare(strict_types=1);

namespace App\Modules\Assistant;

use App\Modules\Drivers\DriverFactory;

/**
 * Structured description of a connection's schema for the AI assistant.
 *
 * Large databases (hundreds of tables, e.g. Cyclos) don't fit in a model's context,
 * so the catalog offers two renderings:
 *  - compact(): one line per table (name, approx. rows, first columns) → used to let
 *    the model pick the relevant tables;
 *  - detail():  full columns/types/PKs/FKs for the selected tables → used to write SQL.
 * Cached on disk (1 hour); "refresh" rebuilds it.
 */
final class SchemaCatalog
{
    private const TTL = 3600;
    private const MAX_TABLES = 3000;

    /** @var array<string, array> qualified name => table info */
    private array $tables = [];

    private function __construct(private string $default)
    {
    }

    private static function file(array $conn, ?string $database): string
    {
        return storage_path('cache/aicatalog_' . md5($conn['id'] . '|' . $conn['updated_at'] . '|' . $database) . '.json');
    }

    public static function forget(array $conn, ?string $database): void
    {
        @unlink(self::file($conn, $database));
    }

    public static function load(array $conn, ?string $database): self
    {
        $default = ['pgsql' => 'public', 'sqlsrv' => 'dbo'][$conn['driver']] ?? '';
        $file = self::file($conn, $database);
        $self = new self($default);
        if (is_file($file) && filemtime($file) > time() - self::TTL) {
            $self->tables = json_decode((string) file_get_contents($file), true) ?: [];
            return $self;
        }
        $driver = DriverFactory::fromConnection($conn)->withDatabase($database);
        $caps = $driver::capabilities();
        $schemas = $caps['schemas'] ? array_slice(array_column($driver->schemas($database), 'name'), 0, 30) : [null];
        foreach ($schemas as $schema) {
            foreach (['tables', 'views'] as $type) {
                foreach ($driver->objects($database, $schema, $type) as $o) {
                    if (count($self->tables) >= self::MAX_TABLES) {
                        break 3;
                    }
                    $name = ($schema && $schema !== $default ? $schema . '.' : '') . $o['name'];
                    try {
                        $cols = array_map(static fn($c) => [$c['name'], preg_replace('/\s+/', ' ', (string) $c['type']), (bool) $c['primary']],
                            $driver->columns($database, $schema, $o['name']));
                        $fks = $type === 'tables'
                            ? array_map(static fn($f) => preg_replace('/^FOREIGN KEY\s*/i', '', (string) $f['definition']), $driver->foreignKeys($database, $schema, $o['name']))
                            : [];
                    } catch (\Throwable) {
                        $cols = [];
                        $fks = [];
                    }
                    $self->tables[$name] = [
                        'name'    => $name,
                        'view'    => $type === 'views',
                        'rows'    => isset($o['rows_estimate']) && $o['rows_estimate'] !== null ? (int) $o['rows_estimate'] : null,
                        'comment' => $o['comment'] ?? null,
                        'columns' => $cols,
                        'fks'     => $fks,
                    ];
                }
            }
        }
        @file_put_contents($file, json_encode($self->tables, JSON_UNESCAPED_UNICODE));
        return $self;
    }

    public function count(): int
    {
        return count($this->tables);
    }

    public function names(): array
    {
        return array_keys($this->tables);
    }

    public function has(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /** Case-insensitive lookup, accepts "schema.table", "table", quoted names. */
    public function find(string $name): ?string
    {
        $n = strtolower(trim(str_replace(['"', '`', '[', ']'], '', $name)));
        if ($this->default !== '' && str_starts_with($n, strtolower($this->default) . '.')) {
            $n = substr($n, strlen($this->default) + 1);
        }
        foreach ($this->tables as $key => $_) {
            if (strtolower($key) === $n) {
                return $key;
            }
        }
        return null;
    }

    private static function rows(?int $rows): string
    {
        return match (true) {
            $rows === null => '',
            $rows <= 0     => ' [empty]',
            $rows < 1000   => " [~$rows rows]",
            $rows < 1e6    => ' [~' . round($rows / 1000) . 'k rows]',
            default        => ' [~' . round($rows / 1e6, 1) . 'M rows]',
        };
    }

    /** One line per table: good enough for the model to choose which tables matter. */
    public function compact(int $maxColumns = 14): string
    {
        $lines = [];
        foreach ($this->tables as $t) {
            $cols = array_slice(array_column($t['columns'], 0), 0, $maxColumns);
            $more = count($t['columns']) > $maxColumns ? ', …' : '';
            $lines[] = ($t['view'] ? 'view ' : '') . $t['name'] . self::rows($t['rows']) . ': ' . implode(', ', $cols) . $more;
        }
        return implode("\n", $lines);
    }

    /** Full description of the given tables (all when null). */
    public function detail(?array $names = null): string
    {
        $out = [];
        foreach ($names === null ? array_keys($this->tables) : $names as $n) {
            $key = $this->find($n);
            if (!$key) {
                continue;
            }
            $t = $this->tables[$key];
            $cols = array_map(static fn($c) => $c[0] . ' ' . $c[1] . ($c[2] ? ' PK' : ''), $t['columns']);
            $line = ($t['view'] ? 'VIEW ' : 'TABLE ') . $t['name'] . self::rows($t['rows'])
                . ($t['comment'] ? ' -- ' . $t['comment'] : '') . "\n  (" . implode(', ', $cols) . ')';
            foreach ($t['fks'] as $fk) {
                $line .= "\n  FK " . $fk;
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    public function detailLength(): int
    {
        return strlen($this->detail());
    }

    /** Tables referenced by the FKs of the given tables (1 hop), plus tables that reference them. */
    public function neighbours(array $names): array
    {
        $set = array_filter(array_map(fn($n) => $this->find($n), $names));
        $extra = [];
        foreach ($this->tables as $key => $t) {
            foreach ($t['fks'] as $fk) {
                if (!preg_match('/REFERENCES\s+([\w."]+)/i', $fk, $m)) {
                    continue;
                }
                $ref = $this->find($m[1]);
                if ($ref && in_array($key, $set, true) && !in_array($ref, $set, true)) {
                    $extra[$ref] = true;           // outgoing FK
                } elseif ($ref && in_array($ref, $set, true) && !in_array($key, $set, true)) {
                    $extra[$key] = ($extra[$key] ?? false); // incoming FK (lower priority)
                }
            }
        }
        arsort($extra); // outgoing first
        return array_keys($extra);
    }

    /** Table names mentioned (as whole words) in arbitrary text: SQL, notes, examples. */
    public function mentionedIn(string $text): array
    {
        $found = [];
        $lower = strtolower($text);
        foreach ($this->tables as $key => $_) {
            $short = strtolower(str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key);
            if (preg_match('/(?<![\w.])' . preg_quote(strtolower($key), '/') . '(?!\w)/', $lower)
                || (strlen($short) > 2 && preg_match('/(?<![\w])' . preg_quote($short, '/') . '(?!\w)/', $lower))) {
                $found[] = $key;
            }
        }
        return $found;
    }

    /** Cheap fallback relevance: word overlap between question and table/column names. */
    public function keywordMatches(string $question, int $limit = 12): array
    {
        $words = KnowledgeRepository::words($question);
        $scores = [];
        foreach ($this->tables as $key => $t) {
            $hay = strtolower($key . ' ' . implode(' ', array_column($t['columns'], 0)));
            $s = 0;
            foreach ($words as $w) {
                $stem = strlen($w) > 4 ? substr($w, 0, -1) : $w;
                if (str_contains($hay, $stem)) {
                    $s += str_contains(strtolower($key), $stem) ? 3 : 1;
                }
            }
            if ($s > 0 && ($t['rows'] ?? 1) !== 0) {
                $scores[$key] = $s;
            }
        }
        arsort($scores);
        return array_slice(array_keys($scores), 0, $limit);
    }
}
