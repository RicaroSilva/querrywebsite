<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private array $json = [];
    public array $params = [];

    public function __construct()
    {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($type, 'application/json')) {
            $body = file_get_contents('php://input');
            $decoded = json_decode($body ?: '[]', true);
            $this->json = is_array($decoded) ? $decoded : [];
        }
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * URL prefix of the application ("" at a domain root, "/querydeck" in a sub-folder).
     * Supports both a web root pointing at public/ and a project folder served as-is
     * (e.g. XAMPP htdocs/querydeck, where the root .htaccess rewrites into public/).
     */
    public static function basePath(): string
    {
        static $cache = null;
        if ($cache !== null && PHP_SAPI !== 'cli') {
            return $cache;
        }
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/.');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_ends_with($base, '/public') && $uri !== $base && !str_starts_with($uri, $base . '/')) {
            $base = substr($base, 0, -7); // request came through the project-root .htaccess
        }
        return $cache = $base;
    }

    /** Cookie path: the app folder (without /public) so both URL styles share the session. */
    public static function cookiePath(): string
    {
        return (string) preg_replace('#/public$#', '', self::basePath()) . '/';
    }

    public function path(): string
    {
        $uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $base = self::basePath();
        if ($base !== '' && ($uri === $base || str_starts_with($uri, $base . '/'))) {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim($uri, '/');
        return $uri === '/index.php' ? '/' : $uri;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($_GET, $_POST, $this->json);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->input($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path(), '/api/')
            || str_contains($this->header('Accept') ?? '', 'application/json')
            || $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
