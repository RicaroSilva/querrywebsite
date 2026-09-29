<?php
declare(strict_types=1);

namespace App\Modules\Exports\Exporters;

final class JsonExporter extends Exporter
{
    private bool $first = true;
    private array $names = [];

    public function extension(): string
    {
        return 'json';
    }

    public function contentType(): string
    {
        return 'application/json; charset=utf-8';
    }

    public function begin(array $columns): void
    {
        parent::begin($columns);
        // Disambiguate duplicate column names (e.g. two "id" from a JOIN)
        $seen = [];
        foreach ($columns as $c) {
            $n = $c['name'];
            $seen[$n] = ($seen[$n] ?? 0) + 1;
            $this->names[] = $seen[$n] > 1 ? $n . '_' . $seen[$n] : $n;
        }
        fwrite($this->out, "[\n");
    }

    public function row(array $row): void
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            | (!empty($this->options['pretty']) ? JSON_PRETTY_PRINT : 0);
        $obj = array_combine($this->names, array_pad($row, count($this->names), null));
        $this->write(($this->first ? '  ' : ",\n  ") . json_encode($obj, $flags));
        $this->first = false;
    }

    public function end(): void
    {
        fwrite($this->out, "\n]\n");
        parent::end();
    }
}
