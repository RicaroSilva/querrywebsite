<?php
declare(strict_types=1);

namespace App\Modules\Exports\Exporters;

/** INSERT statements, quoted for the target dialect. */
final class SqlExporter extends Exporter
{
    private string $prefix = '';

    public function extension(): string
    {
        return 'sql';
    }

    public function contentType(): string
    {
        return 'application/sql; charset=utf-8';
    }

    private function ident(string $name): string
    {
        return match ($this->options['dialect'] ?? 'pgsql') {
            'mysql'  => '`' . str_replace('`', '``', $name) . '`',
            'sqlsrv' => '[' . str_replace(']', ']]', $name) . ']',
            default  => '"' . str_replace('"', '""', $name) . '"',
        };
    }

    public function begin(array $columns): void
    {
        parent::begin($columns);
        $table = preg_replace('/[^\w.]/u', '_', (string) ($this->options['table'] ?? 'export_table')) ?: 'export_table';
        $tableSql = implode('.', array_map([$this, 'ident'], explode('.', $table)));
        $this->prefix = 'INSERT INTO ' . $tableSql . ' (' . implode(', ', array_map(fn($c) => $this->ident($c['name']), $columns)) . ') VALUES (';
        fwrite($this->out, '-- Exported by QueryDeck on ' . date('c') . "\n");
    }

    public function row(array $row): void
    {
        $vals = array_map(function ($v) {
            if ($v === null) {
                return 'NULL';
            }
            if (is_bool($v)) {
                return $v ? 'TRUE' : 'FALSE';
            }
            if (is_int($v) || is_float($v)) {
                return (string) $v;
            }
            $prefix = ($this->options['dialect'] ?? '') === 'sqlsrv' ? 'N' : '';
            $s = str_replace("'", "''", (string) $v);
            if (($this->options['dialect'] ?? '') === 'mysql') {
                $s = str_replace('\\', '\\\\', $s);
            }
            return $prefix . "'" . $s . "'";
        }, $row);
        $this->write($this->prefix . implode(', ', $vals) . ");\n");
    }
}
