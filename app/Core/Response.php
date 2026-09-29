<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; "
            . "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if (Request::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_HEX_TAG);
        exit;
    }

    public static function redirect(string $path, int $status = 302): never
    {
        header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)), true, $status);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /** Prepare headers for a streamed file download. */
    public static function download(string $filename, string $contentType): void
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no'); // disable nginx buffering → true streaming
    }
}
