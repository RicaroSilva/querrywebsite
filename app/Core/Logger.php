<?php
declare(strict_types=1);

namespace App\Core;

/** File logger (storage/logs/app-YYYY-MM-DD.log). */
final class Logger
{
    public static function write(string $level, string $message, array $context = []): void
    {
        $line = sprintf("[%s] %s: %s%s\n", date('c'), strtoupper($level), $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '');
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }
}
