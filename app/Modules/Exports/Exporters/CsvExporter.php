<?php
declare(strict_types=1);

namespace App\Modules\Exports\Exporters;

final class CsvExporter extends Exporter
{
    public function extension(): string
    {
        return ($this->options['delimiter'] ?? ',') === "\t" ? 'tsv' : 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv; charset=utf-8';
    }

    public function begin(array $columns): void
    {
        parent::begin($columns);
        if (!empty($this->options['bom'])) {
            fwrite($this->out, "\xEF\xBB\xBF"); // helps Excel detect UTF-8
        }
        if ($this->options['header'] ?? true) {
            $this->line(array_column($columns, 'name'));
        }
    }

    public function row(array $row): void
    {
        $this->line(array_map([self::class, 'scalar'], $row));
    }

    private function line(array $values): void
    {
        $d = $this->options['delimiter'] ?? ',';
        // Neutralise spreadsheet formula injection (=, +, -, @) when requested
        if (!empty($this->options['safe'])) {
            $values = array_map(static fn($v) => is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) && !is_numeric($v) ? "'" . $v : $v, $values);
        }
        fputcsv($this->out, $values, $d, '"', '');
        if (++$this->count % 500 === 0) {
            fflush($this->out);
            flush();
        }
    }
}
