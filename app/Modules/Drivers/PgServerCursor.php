<?php
declare(strict_types=1);

namespace App\Modules\Drivers;

use PDO;

/**
 * PostgreSQL server-side cursor (DECLARE … FETCH n).
 *
 * libpq normally buffers the ENTIRE result client-side; with millions of rows
 * that would exhaust PHP memory. A named cursor lets us pull rows in batches,
 * which powers both the capped result grid and streamed exports.
 */
final class PgServerCursor implements ResultCursor
{
    private array $buffer = [];
    private int $pos = 0;
    private bool $done = false;
    private ?array $columns = null;
    private string $name;

    /**
     * @param bool $ownTransaction false when the user's script already opened a
     *             transaction: then we must not BEGIN/COMMIT on their behalf.
     */
    public function __construct(private PDO $pdo, string $sql, private bool $ownTransaction = true,
                                string $begin = 'BEGIN', private int $batch = 1000)
    {
        $this->name = 'qd_cur_' . bin2hex(random_bytes(4));
        if ($this->ownTransaction) {
            $this->pdo->exec($begin);
        }
        try {
            $this->pdo->exec("DECLARE {$this->name} NO SCROLL CURSOR FOR " . rtrim(trim($sql), "; \t\r\n"));
            $this->fill();
        } catch (\Throwable $e) {
            if ($this->ownTransaction) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
    }

    private function fill(): void
    {
        $stmt = $this->pdo->query("FETCH FORWARD {$this->batch} FROM {$this->name}");
        if ($this->columns === null) {
            $this->columns = [];
            for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
                $meta = $stmt->getColumnMeta($i) ?: [];
                $this->columns[] = ['name' => (string) ($meta['name'] ?? "column_$i"), 'type' => strtolower((string) ($meta['native_type'] ?? ''))];
            }
        }
        $this->buffer = $stmt->fetchAll(PDO::FETCH_NUM);
        $this->pos = 0;
        if (count($this->buffer) < $this->batch) {
            $this->done = true;
        }
    }

    public function hasRows(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return $this->columns ?? [];
    }

    public function fetch(): ?array
    {
        if ($this->pos >= count($this->buffer)) {
            if ($this->done) {
                return null;
            }
            $this->fill();
            if (!$this->buffer) {
                return null;
            }
        }
        return $this->buffer[$this->pos++];
    }

    public function affected(): int
    {
        return -1;
    }

    public function nextRowset(): bool
    {
        return false;
    }

    public function close(): void
    {
        try {
            $this->pdo->exec("CLOSE {$this->name}");
            if ($this->ownTransaction) {
                $this->pdo->exec('COMMIT');
            }
        } catch (\Throwable) {
            if ($this->ownTransaction) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                }
            }
        }
    }
}
