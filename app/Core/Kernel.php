<?php
declare(strict_types=1);

namespace App\Core;

/** HTTP kernel: session, security headers, routing and error rendering. */
final class Kernel
{
    public function handle(): void
    {
        Response::securityHeaders();
        $request = new Request();

        try {
            if (!is_file(base_path('.env')) && getenv('APP_KEY') === false) {
                throw new \RuntimeException('Ficheiro .env em falta. Execute: php bin/console install');
            }
            Session::start();
            $router = new Router();
            (require base_path('app/routes.php'))($router);
            $router->dispatch($request);
        } catch (HttpException $e) {
            $this->renderError($request, $e->status, $e->getMessage(), $e->errors);
        } catch (\Throwable $e) {
            Logger::error($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            $message = config('app.debug')
                ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
                : 'Ocorreu um erro inesperado. Consulte os logs.';
            $this->renderError($request, 500, $message);
        }
    }

    private function renderError(Request $request, int $status, string $message, array $errors = []): never
    {
        if ($request->wantsJson()) {
            Response::json(['ok' => false, 'error' => $message, 'errors' => $errors], $status);
        }
        try {
            $html = View::render('errors/error', compact('status', 'message'), null);
        } catch (\Throwable) {
            $html = '<h1>' . $status . '</h1><p>' . e($message) . '</p>';
        }
        Response::html($html, $status);
    }
}
