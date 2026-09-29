<?php
declare(strict_types=1);

namespace App\Modules\Execution;

/**
 * Named placeholders in user SQL: {{name}}.
 * Each occurrence is replaced by a positional "?" and its value is bound as a
 * prepared-statement parameter (never concatenated into the SQL text).
 * Used by report filters and by the SQL editor's parameter prompt.
 */
final class Placeholders
{
    private const PATTERN = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    /** @return string[] distinct placeholder names in order of appearance */
    public static function names(string $sql): array
    {
        preg_match_all(self::PATTERN, $sql, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * @param array $defs   list of ['name', 'type' (text|number|date|select), 'default']
     * @param array $values name => raw value
     * @return array{0: string, 1: array} [sql with ?, params]
     */
    public static function bind(string $sql, array $defs, array $values): array
    {
        $byName = [];
        foreach ($defs as $d) {
            $byName[$d['name']] = $d;
        }
        $params = [];
        $bound = preg_replace_callback(self::PATTERN, static function ($m) use (&$params, $byName, $values) {
            $def = $byName[$m[1]] ?? ['type' => 'auto'];
            $v = $values[$m[1]] ?? ($def['default'] ?? null);
            $params[] = self::cast($v, (string) ($def['type'] ?? 'auto'));
            return '?';
        }, $sql);
        return [(string) $bound, $params];
    }

    private static function cast(mixed $v, string $type): int|float|string|null
    {
        if ($v === null || $v === '' || is_array($v) || is_object($v)) {
            return null;
        }
        $v = (string) $v;
        return match ($type) {
            'number' => is_numeric($v) ? $v + 0 : null,
            'date'   => preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v) ? $v : null,
            'auto'   => is_numeric($v) && !preg_match('/^0\d/', $v) ? $v + 0 : mb_substr($v, 0, 4000),
            default  => mb_substr($v, 0, 4000),
        };
    }
}
