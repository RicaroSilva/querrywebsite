<?php
declare(strict_types=1);

/**
 * Bootstraps the application: autoloader, environment, config, error handling.
 * Shared by the web front controller (public/index.php) and the CLI (bin/console).
 */

define('BASE_PATH', dirname(__DIR__));

// PSR-4 autoloader for the App\ namespace (works with or without Composer).
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once BASE_PATH . '/app/helpers.php';

App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Config::load(BASE_PATH . '/config');

date_default_timezone_set((string) config('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
