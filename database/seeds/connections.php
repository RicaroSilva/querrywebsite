<?php
declare(strict_types=1);

/**
 * Connections declared in .env (CONNECTION_1_*, CONNECTION_2_*, ... up to 20).
 * Created by `php bin/console install` / `db:seed` if no connection with the same
 * name exists yet. Passwords are stored ENCRYPTED in the application database;
 * after the first install you may remove CONNECTION_n_PASSWORD from .env.
 *
 *   CONNECTION_1_NAME="Cyclos"
 *   CONNECTION_1_DRIVER=pgsql            # pgsql | mysql | sqlsrv | sqlite
 *   CONNECTION_1_HOST=localhost
 *   CONNECTION_1_PORT=5433
 *   CONNECTION_1_DATABASE=cyclos
 *   CONNECTION_1_USERNAME=postgres
 *   CONNECTION_1_PASSWORD=secret
 *   CONNECTION_1_SSLMODE=disable         # pgsql only
 *   CONNECTION_1_READ_ONLY=true
 *   CONNECTION_1_ENVIRONMENT=local       # production | staging | development | local
 */

use App\Core\Database;
use App\Modules\Connections\ConnectionRepository;

$repo = new ConnectionRepository();
$db = Database::get();
for ($i = 1; $i <= 20; $i++) {
    $name = env("CONNECTION_{$i}_NAME");
    if (!$name) {
        continue;
    }
    if ($db->value('SELECT id FROM connections WHERE name = ?', [$name])) {
        echo "• Connection \"$name\" already exists (unchanged)\n";
        continue;
    }
    $in = [
        'name'          => $name,
        'driver'        => env("CONNECTION_{$i}_DRIVER", 'pgsql'),
        'host'          => env("CONNECTION_{$i}_HOST", 'localhost'),
        'port'          => env("CONNECTION_{$i}_PORT"),
        'database_name' => env("CONNECTION_{$i}_DATABASE"),
        'username'      => env("CONNECTION_{$i}_USERNAME"),
        'password'      => (string) env("CONNECTION_{$i}_PASSWORD", ''),
        'sslmode'       => env("CONNECTION_{$i}_SSLMODE", 'prefer'),
        'read_only'     => env("CONNECTION_{$i}_READ_ONLY", false),
        'environment'   => env("CONNECTION_{$i}_ENVIRONMENT", 'local'),
    ];
    try {
        $id = $repo->create($repo->normalise($in));
        echo "• Connection \"$name\" created (id $id)\n";
    } catch (App\Core\HttpException $e) {
        echo "! Connection \"$name\" invalid: " . $e->getMessage() . ' ' . json_encode($e->errors, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
