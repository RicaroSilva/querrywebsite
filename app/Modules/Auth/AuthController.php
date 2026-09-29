<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Users\UserRepository;

final class AuthController
{
    public function showLogin(Request $request): never
    {
        Response::html(View::render('auth/login', [
            'error' => Session::flash('error'),
            'email' => Session::flash('email') ?? '',
        ], 'layouts/auth'));
    }

    public function login(Request $request): never
    {
        $email = strtolower($request->str('email'));
        $password = (string) $request->input('password', '');
        $db = Database::get();

        $window = date('Y-m-d H:i:s', time() - (int) config('app.auth.lockout_seconds'));
        $attempts = (int) $db->value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND attempted_at > ?',
            [$email, $request->ip(), $window]);

        if ($attempts >= (int) config('app.auth.max_attempts')) {
            Audit::log('auth.locked', 'user', null, ['email' => $email]);
            $this->fail('Demasiadas tentativas falhadas. Tente novamente mais tarde.', $email);
        }

        $users = new UserRepository();
        $user = $email !== '' ? $users->findByEmail($email) : null;
        // Always run password_verify to keep timing uniform when the user doesn't exist.
        $hash = $user['password_hash'] ?? '$2y$12$dVr3BOUfpvYOKO.FVPD45uMDaDwFkirZoM/0bOVf8w4LF0XoK20iW';
        $valid = password_verify($password, $hash) && $user && $user['is_active'];

        if (!$valid) {
            $db->insert('login_attempts', ['email' => $email, 'ip' => $request->ip(), 'attempted_at' => now()]);
            Audit::log('auth.failed', 'user', $user['id'] ?? null, ['email' => $email]);
            $this->fail('Credenciais inválidas.', $email);
        }

        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $users->setPassword((int) $user['id'], $password);
            $user = $users->find((int) $user['id']);
        }

        $db->run('DELETE FROM login_attempts WHERE email = ? AND ip = ?', [$email, $request->ip()]);
        $users->update((int) $user['id'], ['last_login_at' => now()]);
        Auth::login($user);
        Audit::log('auth.login', 'user', $user['id']);

        $intended = Session::flash('intended');
        Response::redirect(is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : '/');
    }

    public function logout(Request $request): never
    {
        Audit::log('auth.logout', 'user', Auth::id());
        Auth::logout();
        Response::redirect('/login');
    }

    private function fail(string $message, string $email): never
    {
        Session::flash('error', $message);
        Session::flash('email', $email);
        Response::redirect('/login');
    }
}
