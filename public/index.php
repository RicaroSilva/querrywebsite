<?php
declare(strict_types=1);

// PHP built-in dev server: let it serve static assets directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

(new App\Core\Kernel())->handle();
