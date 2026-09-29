<?php
declare(strict_types=1);

namespace App\Modules\Drivers;

use PDO;
use PDOStatement;

final class PdoCursor implements ResultCursor
{
    private ?array $columns = null;

    public function __construct(private PDOStatement $stmt, private ?\Closure $typeResolver = null)
    {
    }

    public function hasRows(): bool
    {
        return $this->stmt->columnCount() > 0;
    }

    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $cols = [];
        for ($i = 0, $n = $this->stmt->columnCount(); $i < $n; $i++) {
            $meta = @$this->stmt->getColumnMeta($i) ?: [];
            $type = $meta['native_type'] ?? ($meta['sqlsrv:decl_type'] ?? ($meta['sqlite:decl_type'] ?? ''));
            if ($this->typeResolver) {
                $type = ($this->typeResolver)($meta) ?? $type;
            }
            $cols[] = ['name' => (string) ($meta['name'] ?? "column_$i"), 'type' => strtolower((string) $type)];
        }
        return $this->columns = $cols;
    }

    public function fetch(): ?array
    {
        $row = $this->stmt->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row;
    }

    public function affected(): int
    {
        try {
            return $this->stmt->rowCount();
        } catch (\Throwable) {
            return -1;
        }
    }

    public function nextRowset(): bool
    {
        try {
            $this->columns = null;
            return $this->stmt->nextRowset();
        } catch (\PDOException $e) {
            // Drivers without multi-rowset support throw "driver does not support"; real SQL errors must bubble.
            if (str_contains($e->getMessage(), 'does not support') || $e->getCode() === 'IM001') {
                return false;
            }
            throw $e;
        }
    }

    public function close(): void
    {
        try {
            $this->stmt->closeCursor();
        } catch (\Throwable) {
        }
    }
}
