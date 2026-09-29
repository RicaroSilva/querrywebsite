<?php
declare(strict_types=1);

namespace App\Core;

/** Minimal .env loader (KEY=value, quotes, # comments, inline comments). */
final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strpos($value, $quote, 1);
                $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value));
            }
            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        // Real environment variables (Docker, systemd, php-fpm pools) win over the .env file.
        $real = getenv($key);
        $value = $real !== false ? $real : (self::$vars[$key] ?? null);
        if ($value === false || $value === null) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}
