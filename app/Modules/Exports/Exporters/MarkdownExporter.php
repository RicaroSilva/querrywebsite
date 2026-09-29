<?php
declare(strict_types=1);

namespace App\Modules\Exports\Exporters;

final class MarkdownExporter extends Exporter
{
    public function extension(): string
    {
        return 'md';
    }

    public function contentType(): string
    {
        return 'text/markdown; charset=utf-8';
    }

    private static function cell(mixed $v): string
    {
        return str_replace(['|', "\r\n", "\n"], ['\\|', ' ', ' '], $v === null ? 'NULL' : self::scalar($v));
    }

    public function begin(array $columns): void
    {
        parent::begin($columns);
        fwrite($this->out, '| ' . implode(' | ', array_map(fn($c) => self::cell($c['name']), $columns)) . " |\n");
        fwrite($this->out, '|' . str_repeat(' --- |', count($columns)) . "\n");
    }

    public function row(array $row): void
    {
        $this->write('| ' . implode(' | ', array_map([self::class, 'cell'], $row)) . " |\n");
    }
}
