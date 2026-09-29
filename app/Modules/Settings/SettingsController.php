<?php
declare(strict_types=1);

namespace App\Modules\Settings;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Modules\Drivers\DriverFactory;
use App\Modules\Users\UserRepository;

final class SettingsController
{
    public function index(Request $request): never
    {
        $users = new UserRepository();
        Response::html(View::render('settings/index', [
            'title'   => 'Definições',
            'active'  => 'settings',
            'prefs'   => $users->preferences(Auth::user()),
            'users'   => Auth::can('users.manage') ? $users->all() : [],
            'roles'   => UserRepository::ROLES,
            'drivers' => DriverFactory::catalog(),
            'limits'  => config('query'),
            'system'  => [
                'php'      => PHP_VERSION,
                'app_db'   => config('database.driver'),
                'version'  => config('app.version'),
                'env'      => config('app.env'),
            ],
        ]));
    }

    /** Per-user UI preferences (theme, editor font size, page size...). */
    public function preferences(Request $request): never
    {
        $allowed = [
            'theme'         => ['dark', 'light', 'system'],
            'editor_font'   => ['12', '13', '14', '15', '16'],
            'page_size'     => ['50', '100', '200', '500'],
            'autocomplete'  => ['1', '0'],
            'confirm_write' => ['1', '0'],
        ];
        $users = new UserRepository();
        foreach ($allowed as $key => $values) {
            $v = $request->input($key);
            if ($v !== null && in_array((string) $v, $values, true)) {
                $users->setPreference((int) Auth::id(), $key, (string) $v);
            }
        }
        Response::json(['ok' => true]);
    }

    public function password(Request $request): never
    {
        $d = Validator::validate($request->all(), ['current' => 'required|string', 'password' => 'required|string|min:10|max:200']);
        $user = Auth::user();
        if (!password_verify($d['current'], $user['password_hash'])) {
            throw new HttpException(422, 'A password atual está incorreta.', ['current' => 'Incorreta']);
        }
        if ($d['password'] !== $request->str('password_confirmation')) {
            throw new HttpException(422, 'As passwords não coincidem.', ['password_confirmation' => 'Não coincide']);
        }
        $users = new UserRepository();
        $users->setPassword((int) $user['id'], $d['password']);
        Auth::login($users->find((int) $user['id'])); // refresh fingerprint for this session
        Audit::log('user.password_changed', 'user', $user['id']);
        Response::json(['ok' => true, 'message' => 'Password alterada.']);
    }

    public function profile(Request $request): never
    {
        $d = Validator::validate($request->all(), ['name' => 'required|string|max:120']);
        (new UserRepository())->update((int) Auth::id(), ['name' => $d['name']]);
        Response::json(['ok' => true]);
    }
}
