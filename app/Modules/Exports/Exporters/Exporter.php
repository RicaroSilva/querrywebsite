<?php
declare(strict_types=1);

namespace App\Modules\Exports\Exporters;

/**
 * Streaming exporter: begin() → row()* → end(). Output is written directly to
 * php://output (except formats that need a container, like XLSX).
 */
abstract class Exporter
{
    protected $out;
    protected array $columns = [];
    protected int $count = 0;

    public function __construct(protected array $options = [])
    {
    }

    abstract public function extension(): string;

    abstract public function contentType(): string;

    public function begin(array $columns): void
    {
        $this->out = fopen('php://output', 'wb');
        $this->columns = $columns;
    }

    abstract public function row(array $row): void;

    public function end(): void
    {
        fflush($this->out);
    }

    protected function write(string $s): void
    {
        fwrite($this->out, $s);
        if (++$this->count % 500 === 0) {
            fflush($this->out);
            flush();
        }
    }

    protected static function scalar(mixed $v): string
    {
        return match (true) {
            $v === null  => '',
            is_bool($v)  => $v ? 'true' : 'false',
            default      => (string) $v,
        };
    }
}
