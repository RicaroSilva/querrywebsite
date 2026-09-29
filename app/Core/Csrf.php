<?php
declare(strict_types=1);

namespace App\Core;

/** Synchronizer-token CSRF protection (form field `_csrf` or header `X-CSRF-Token`). */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(Request $request): bool
    {
        $sent = $request->header('X-CSRF-Token') ?? $request->input('_csrf');
        return is_string($sent) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $sent);
    }
}
