<?php
declare(strict_types=1);

namespace App\Modules\Execution;

use App\Core\HttpException;

/**
 * Server-side cache of result sets (storage/cache/results).
 *
 * The browser never receives more than one page at a time: paging, sorting,
 * searching and filtering of up to QUERY_MAX_ROWS rows happen here.
 * Files are owned by a user id and expire after RESULT_CACHE_TTL seconds.
 */
final class ResultStore
{
    private static function dir(): string
    {
        return storage_path('cache/results');
    }

    private static function path(string $id, string $ext): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            throw new HttpException(400, 'Identificador de resultado inválido.');
        }
        return self::dir() . "/$id.$ext";
    }

    /** @return array{0: string, 1: resource} id + writable handle for rows (JSON lines) */
    public static function create(): array
    {
        if (!is_dir(self::dir())) {
            mkdir(self::dir(), 0770, true);
        }
        if (random_int(1, 50) === 1) {
            self::gc();
        }
        $id = bin2hex(random_bytes(16));
        $fh = fopen(self::path($id, 'rows'), 'wb');
        return [$id, $fh];
    }

    public static function writeRow($fh, array $row): void
    {
        fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
    }

    public static function finish(string $id, $fh, array $meta): void
    {
        fclose($fh);
        file_put_contents(self::path($id, 'meta'), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public static function meta(string $id, int $userId): array
    {
        $file = self::path($id, 'meta');
        if (!is_file($file)) {
            throw new HttpException(404, 'O resultado expirou. Execute a query novamente.');
        }
        $meta = json_decode((string) file_get_contents($file), true) ?: [];
        if ((int) ($meta['user_id'] ?? 0) !== $userId) {
            throw new HttpException(403);
        }
        return $meta;
    }

    public static function rows(string $id): \Generator
    {
        $fh = fopen(self::path($id, 'rows'), 'rb');
        while (($line = fgets($fh)) !== false) {
            yield json_decode($line, true);
        }
        fclose($fh);
    }

    /**
     * @param array<int,string> $filters column index => text (contains; supports =, >, <, !, null)
     */
    public static function query(string $id, int $userId, array $opts): array
    {
        $meta = self::meta($id, $userId);
        $page = max(1, (int) ($opts['page'] ?? 1));
        $perPage = min(1000, max(10, (int) ($opts['per_page'] ?? config('query.page_size', 100))));
        $search = mb_strtolower(trim((string) ($opts['search'] ?? '')));
        $filters = array_filter((array) ($opts['filters'] ?? []), static fn($v) => $v !== '' && $v !== null);
        $sort = isset($opts['sort']) && $opts['sort'] !== '' ? (int) $opts['sort'] : null;
        $dir = strtolower((string) ($opts['dir'] ?? 'asc')) === 'desc' ? -1 : 1;

        $rows = self::filtered($id, $search, $filters);
        $filteredCount = count($rows);

        if ($sort !== null && $sort >= 0 && $sort < count($meta['columns'])) {
            usort($rows, static function ($a, $b) use ($sort, $dir) {
                $x = $a[$sort];
                $y = $b[$sort];
                if ($x === null || $y === null) {
                    return ($x === null ? 1 : 0) - ($y === null ? 1 : 0);
                }
                if (is_numeric($x) && is_numeric($y)) {
                    return ($x <=> $y) * $dir;
                }
                return strnatcasecmp((string) $x, (string) $y) * $dir;
            });
        }

        return [
            'result_id' => $id,
            'columns'   => $meta['columns'],
            'rows'      => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => (int) $meta['row_count'],
            'filtered'  => $filteredCount,
            'truncated' => (bool) $meta['truncated'],
        ];
    }

    /** All cached rows matching search/filters (used by the grid and by "export current view"). */
    public static function filtered(string $id, string $search = '', array $filters = []): array
    {
        $rows = [];
        foreach (self::rows($id) as $row) {
            if ($search !== '' && !self::rowContains($row, $search)) {
                continue;
            }
            foreach ($filters as $col => $expr) {
                if (!self::matches($row[(int) $col] ?? null, (string) $expr)) {
                    continue 2;
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private static function rowContains(array $row, string $needle): bool
    {
        foreach ($row as $v) {
            if ($v !== null && str_contains(mb_strtolower((string) $v), $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Filter mini-language: "text" contains, "=x" equals, "!x" not contains, ">n" "<n" ">=n" "<=n", "null", "!null". */
    private static function matches(mixed $value, string $expr): bool
    {
        $expr = trim($expr);
        $lower = mb_strtolower($expr);
        if ($lower === 'null') {
            return $value === null;
        }
        if ($lower === '!null') {
            return $value !== null;
        }
        if (preg_match('/^(>=|<=|>|<)\s*(.+)$/', $expr, $m)) {
            if ($value === null) {
                return false;
            }
            $a = is_numeric($value) && is_numeric($m[2]) ? (float) $value : (string) $value;
            $b = is_numeric($value) && is_numeric($m[2]) ? (float) $m[2] : $m[2];
            return match ($m[1]) { '>' => $a > $b, '<' => $a < $b, '>=' => $a >= $b, '<=' => $a <= $b };
        }
        $str = mb_strtolower((string) ($value ?? ''));
        if (str_starts_with($expr, '=')) {
            return $str === mb_strtolower(substr($expr, 1));
        }
        if (str_starts_with($expr, '!')) {
            return !str_contains($str, mb_strtolower(substr($expr, 1)));
        }
        return str_contains($str, $lower);
    }

    public static function gc(): void
    {
        $ttl = (int) config('query.cache_ttl', 3600);
        foreach (glob(self::dir() . '/*') ?: [] as $f) {
            if (filemtime($f) < time() - $ttl) {
                @unlink($f);
            }
        }
    }
}
