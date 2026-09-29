<?php
declare(strict_types=1);

namespace App\Modules\Explorer;

use App\Modules\Drivers\DriverFactory;
use App\Modules\Drivers\DriverInterface;

/**
 * Builds the lazy-loaded object tree (pgAdmin / SSMS style) on top of the
 * driver metadata API, so the tree works identically for every engine.
 */
final class ExplorerService
{
    private const GROUP_LABELS = [
        'tables' => 'Tables', 'views' => 'Views', 'functions' => 'Functions',
        'procedures' => 'Procedures', 'sequences' => 'Sequences',
    ];
    private const GROUP_ICONS = [
        'tables' => 'table', 'views' => 'eye', 'functions' => 'function', 'procedures' => 'zap', 'sequences' => 'list',
    ];
    private const SINGULAR = [
        'tables' => 'table', 'views' => 'view', 'functions' => 'function', 'procedures' => 'procedure', 'sequences' => 'sequence',
    ];

    private DriverInterface $driver;
    private array $caps;

    public function __construct(private array $conn)
    {
        $this->driver = DriverFactory::fromConnection($conn);
        $this->caps = $this->driver::capabilities();
    }

    private function driverFor(?string $database): DriverInterface
    {
        return $this->driver->withDatabase($database);
    }

    public function children(array $n): array
    {
        $db = $n['database'];
        $schema = $n['schema'];

        switch ($n['kind']) {
            case 'root':
                if ($this->caps['databases']) {
                    $current = $this->driver->currentDatabase();
                    return array_map(fn($d) => $this->node('database', $d['name'], 'database', [
                        'database' => $d['name'], 'badge' => $d['name'] === $current ? 'default' : null]),
                        $this->driver->databases());
                }
                return $this->groups(null, null);

            case 'database':
                if ($this->caps['schemas']) {
                    return array_map(fn($s) => $this->node('schema', $s['name'], 'layers', ['database' => $db, 'schema' => $s['name']]),
                        $this->driverFor($db)->schemas($db));
                }
                return $this->groups($db, null);

            case 'schema':
                return $this->groups($db, $schema);

            case 'group':
                $type = $n['group'];
                if (!isset(self::SINGULAR[$type])) {
                    return [];
                }
                $kind = self::SINGULAR[$type];
                return array_map(function ($o) use ($kind, $db, $schema) {
                    $meta = array_filter([
                        'rows'  => isset($o['rows_estimate']) && $o['rows_estimate'] !== null ? (int) $o['rows_estimate'] : null,
                        'size'  => isset($o['size_bytes']) && $o['size_bytes'] !== null ? (int) $o['size_bytes'] : null,
                        'extra' => $o['returns'] ?? ($o['engine'] ?? null),
                    ], static fn($v) => $v !== null);
                    return $this->node($kind, $o['label'] ?? $o['name'], self::GROUP_ICONS[$kind . 's'] ?? 'table', [
                        'database' => $db, 'schema' => $schema, 'name' => $o['name'], 'key' => $o['key'] ?? null,
                        'leaf' => !in_array($kind, ['table', 'view'], true), 'meta' => $meta,
                        'badge' => ($o['kind'] ?? null) === 'm' ? 'MV' : null,
                    ]);
                }, $this->driverFor($db)->objects($db, $schema, $type));

            case 'table':
            case 'view':
                $nodes = [$this->node('columns', 'Columns', 'columns', ['database' => $db, 'schema' => $schema, 'name' => $n['name']])];
                if ($n['kind'] === 'table') {
                    $nodes[] = $this->node('indexes', 'Indexes', 'key', ['database' => $db, 'schema' => $schema, 'name' => $n['name']]);
                }
                return $nodes;

            case 'columns':
                return array_map(fn($c) => $this->node('column', $c['name'], $c['primary'] ? 'key' : 'columns', [
                    'database' => $db, 'schema' => $schema, 'name' => $c['name'], 'leaf' => true,
                    'meta' => ['extra' => $c['type'] . ($c['nullable'] ? '' : ' not null')],
                    'badge' => $c['primary'] ? 'PK' : null,
                ]), $this->driverFor($db)->columns($db, $schema, (string) $n['name']));

            case 'indexes':
                return array_map(fn($i) => $this->node('index', $i['name'], 'key', [
                    'database' => $db, 'schema' => $schema, 'name' => $i['name'], 'leaf' => true,
                    'meta' => ['extra' => $i['definition'] ?? ''],
                    'badge' => $i['primary'] ? 'PK' : ($i['unique'] ? 'UQ' : null),
                ]), $this->driverFor($db)->indexes($db, $schema, (string) $n['name']));
        }
        return [];
    }

    private function groups(?string $db, ?string $schema): array
    {
        return array_map(fn($g) => $this->node('group', self::GROUP_LABELS[$g], 'folder', [
            'database' => $db, 'schema' => $schema, 'group' => $g]), $this->caps['objectTypes']);
    }

    private function node(string $kind, string $label, string $icon, array $extra = []): array
    {
        return [
            'kind'     => $kind,
            'label'    => $label,
            'icon'     => $icon,
            'database' => $extra['database'] ?? null,
            'schema'   => $extra['schema'] ?? null,
            'name'     => $extra['name'] ?? null,
            'key'      => $extra['key'] ?? null,
            'group'    => $extra['group'] ?? null,
            'leaf'     => $extra['leaf'] ?? false,
            'meta'     => $extra['meta'] ?? (object) [],
            'badge'    => $extra['badge'] ?? null,
        ];
    }

    /** Detail panel for a table / view / routine / sequence. */
    public function describe(array $n): array
    {
        $db = $n['database'];
        $schema = $n['schema'];
        $name = (string) $n['name'];
        $kind = $n['kind'];
        $d = $this->driverFor($db);
        $out = ['kind' => $kind, 'name' => $name, 'schema' => $schema, 'database' => $db,
            'connection' => ['id' => (int) $this->conn['id'], 'name' => $this->conn['name'], 'driver' => $this->conn['driver']]];

        if ($kind === 'table' || $kind === 'view') {
            $out['columns'] = $d->columns($db, $schema, $name);
            $out['preview_sql'] = $d->previewSql($schema, $name, 100);
            if ($kind === 'table') {
                $out['indexes'] = $d->indexes($db, $schema, $name);
                $out['foreign_keys'] = $d->foreignKeys($db, $schema, $name);
                $out['definition'] = $d->definition($db, $schema, $name, 'table') ?? $this->genericDdl($d, $schema, $name, $out);
            } else {
                $out['definition'] = $d->definition($db, $schema, $name, 'view');
            }
        } elseif (in_array($kind, ['function', 'procedure', 'sequence'], true)) {
            $out['definition'] = $d->definition($db, $schema, $n['key'] ?? $name, $kind);
        }
        return $out;
    }

    /** Reconstructed CREATE TABLE for engines without a native DDL generator. */
    private function genericDdl(DriverInterface $d, ?string $schema, string $name, array $info): string
    {
        $lines = [];
        $pk = [];
        foreach ($info['columns'] as $c) {
            $line = '    ' . $d->quoteIdentifier($c['name']) . ' ' . $c['type'];
            if (!$c['nullable']) {
                $line .= ' NOT NULL';
            }
            if ($c['default'] !== null && $c['default'] !== '') {
                $line .= ' DEFAULT ' . $c['default'];
            }
            $lines[] = $line;
            if ($c['primary']) {
                $pk[] = $d->quoteIdentifier($c['name']);
            }
        }
        if ($pk) {
            $lines[] = '    PRIMARY KEY (' . implode(', ', $pk) . ')';
        }
        foreach ($info['foreign_keys'] ?? [] as $fk) {
            $lines[] = '    CONSTRAINT ' . $d->quoteIdentifier($fk['name']) . ' ' . preg_replace('/^FOREIGN KEY\s*/i', 'FOREIGN KEY ', $fk['definition']);
        }
        $table = ($schema ? $d->quoteIdentifier($schema) . '.' : '') . $d->quoteIdentifier($name);
        $sql = "CREATE TABLE $table (\n" . implode(",\n", $lines) . "\n);";
        foreach ($info['indexes'] ?? [] as $i) {
            if (!$i['primary'] && isset($i['definition']) && str_starts_with((string) $i['definition'], 'CREATE')) {
                $sql .= "\n" . $i['definition'] . ';';
            }
        }
        return $sql;
    }

    public function databaseList(): array
    {
        return $this->caps['databases']
            ? ['current' => $this->driver->currentDatabase(), 'items' => array_column($this->driver->databases(), 'name'),
               'schemas' => $this->caps['schemas']]
            : ['current' => null, 'items' => [], 'schemas' => false];
    }

    public function completion(?string $database, ?string $schema): array
    {
        $key = md5($this->conn['id'] . '|' . $this->conn['updated_at'] . '|' . $database . '|' . $schema);
        $file = storage_path("cache/completion_$key.json");
        if (is_file($file) && filemtime($file) > time() - 300) {
            return json_decode((string) file_get_contents($file), true) ?: [];
        }
        $map = $this->driverFor($database)->completion($database, $schema);
        @file_put_contents($file, json_encode($map));
        return $map;
    }
}
