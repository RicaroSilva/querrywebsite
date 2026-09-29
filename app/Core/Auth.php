<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Users\UserRepository;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = Session::get('user_id');
            if ($id) {
                $user = (new UserRepository())->find((int) $id);
                // Invalidate session if user was disabled or password changed elsewhere.
                if ($user && $user['is_active'] && hash_equals((string) Session::get('user_hash'), self::fingerprint($user))) {
                    self::$user = $user;
                } else {
                    Session::forget('user_id');
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function login(array $user): void
    {
        Session::regenerate();           // prevent session fixation
        Session::set('user_id', (int) $user['id']);
        Session::set('user_hash', self::fingerprint($user));
        Csrf::token();
        self::$user = $user;
        self::$loaded = true;
    }

    public static function logout(): void
    {
        self::$user = null;
        Session::destroy();
    }

    /** Ties the session to the current password hash: changing password logs out other sessions. */
    private static function fingerprint(array $user): string
    {
        return hash('sha256', $user['id'] . '|' . $user['password_hash']);
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if (!$user) {
            return false;
        }
        $perms = config('permissions.' . $user['role'], []);
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public static function authorize(string $permission): void
    {
        if (!self::can($permission)) {
            throw new HttpException(403);
        }
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }
}
