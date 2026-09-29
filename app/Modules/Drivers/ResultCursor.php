<?php
declare(strict_types=1);

namespace App\Modules\Drivers;

/**
 * Forward-only iterator over the result(s) of one executed statement/batch.
 * Rows are always numeric arrays so duplicate column names survive.
 */
interface ResultCursor
{
    /** True if the current rowset returns rows (SELECT-like). */
    public function hasRows(): bool;

    /** @return array<int, array{name: string, type: string}> */
    public function columns(): array;

    /** Next row as a list of values, or null when the rowset is exhausted. */
    public function fetch(): ?array;

    /** Rows affected (DML) for the current rowset, -1 when unknown. */
    public function affected(): int;

    /** Advance to the next rowset (T-SQL / MySQL multi-results). */
    public function nextRowset(): bool;

    public function close(): void;
}
