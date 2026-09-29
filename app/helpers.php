<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Env;

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function base_path(string $path = ''): string
{
    return BASE_PATH . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function storage_path(string $path = ''): string
{
    return base_path('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
}

/** HTML-escape any value for safe output (XSS protection in templates). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an absolute URL path inside the app (supports sub-directory installs). */
function url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
    }
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = base_path('public/assets/' . ltrim($path, '/'));
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '0';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

/** Inline SVG icon from the built-in icon set. */
function icon(string $name, string $class = ''): string
{
    return App\Core\Icons::svg($name, $class);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $diff = time() - strtotime($datetime);
    return match (true) {
        $diff < 5      => 'agora',
        $diff < 60     => $diff . 's atrás',
        $diff < 3600   => floor($diff / 60) . 'm atrás',
        $diff < 86400  => floor($diff / 3600) . 'h atrás',
        $diff < 604800 => floor($diff / 86400) . 'd atrás',
        default        => date('d/m/Y', strtotime($datetime)),
    };
}

function long_date(): string
{
    if (class_exists(IntlDateFormatter::class)) {
        $f = new IntlDateFormatter('pt_PT', IntlDateFormatter::FULL, IntlDateFormatter::NONE, date_default_timezone_get());
        return (string) $f->format(time());
    }
    return date('d/m/Y');
}

/** True for "/x", "\x", "C:\x" and "C:/x" (Linux and Windows absolute paths). */
function is_absolute_path(string $path): bool
{
    return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
}
