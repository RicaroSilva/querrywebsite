<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Tiny router. Route options:
 *   'auth'  => bool   require an authenticated user (default true)
 *   'guest' => bool   only for guests (login page)
 *   'can'   => string permission required (see config/permissions.php)
 */
final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, array $opts = []): void
    {
        $this->add('GET', $path, $handler, $opts);
    }

    public function post(string $path, array $handler, array $opts = []): void
    {
        $this->add('POST', $path, $handler, $opts);
    }

    public function put(string $path, array $handler, array $opts = []): void
    {
        $this->add('PUT', $path, $handler, $opts);
    }

    public function delete(string $path, array $handler, array $opts = []): void
    {
        $this->add('DELETE', $path, $handler, $opts);
    }

    private function add(string $method, string $path, array $handler, array $opts): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($path, '/') ?: '/') . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'opts');
    }

    public function dispatch(Request $request): mixed
    {
        $method = $request->method();
        // HTML forms can't send PUT/DELETE: allow _method override on POST.
        if ($method === 'POST' && in_array(strtoupper((string) $request->input('_method')), ['PUT', 'DELETE'], true)) {
            $method = strtoupper((string) $request->input('_method'));
        }
        $path = $request->path();
        $allowed = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $allowed = true;
            if ($route['method'] !== $method) {
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->guard($request, $route['opts'], $method);

            [$class, $action] = $route['handler'];
            return (new $class())->$action($request);
        }
        throw new HttpException($allowed ? 405 : 404, $allowed ? 'Método não permitido.' : '');
    }

    private function guard(Request $request, array $opts, string $method): void
    {
        if ($method !== 'GET' && !Csrf::verify($request)) {
            throw new HttpException(419);
        }
        if (!empty($opts['guest'])) {
            if (Auth::check()) {
                Response::redirect('/');
            }
            return;
        }
        if (($opts['auth'] ?? true) && !Auth::check()) {
            if ($request->wantsJson()) {
                throw new HttpException(401);
            }
            Session::flash('intended', $request->path());
            Response::redirect('/login');
        }
        if (!empty($opts['can']) && !Auth::can($opts['can'])) {
            throw new HttpException(403);
        }
    }
}
