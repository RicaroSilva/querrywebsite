<?php
declare(strict_types=1);

namespace App\Modules\Drivers;

use App\Core\Crypto;

/**
 * Registry of supported engines. To add a new one (e.g. Oracle, ClickHouse),
 * implement DriverInterface and register the class here.
 */
final class DriverFactory
{
    public const DRIVERS = [
        'pgsql'  => PostgresDriver::class,
        'mysql'  => MySqlDriver::class,
        'sqlsrv' => SqlServerDriver::class,
        'sqlite' => SqliteDriver::class,
    ];

    /** @return class-string<DriverInterface> */
    public static function class(string $driver): string
    {
        return self::DRIVERS[$driver] ?? throw new \InvalidArgumentException("Driver não suportado: $driver");
    }

    /** Build a driver from a `connections` row (decrypts the password server-side). */
    public static function fromConnection(array $conn): DriverInterface
    {
        $class = self::class($conn['driver']);
        return new $class([
            'host'     => $conn['host'] ?? null,
            'port'     => isset($conn['port']) ? (int) $conn['port'] : null,
            'database' => $conn['database_name'] ?? null,
            'username' => $conn['username'] ?? null,
            'password' => isset($conn['password_enc']) ? Crypto::decrypt($conn['password_enc']) : ($conn['password'] ?? null),
            'options'  => is_array($conn['options'] ?? null) ? $conn['options'] : (json_decode($conn['options'] ?? '{}', true) ?: []),
        ]);
    }

    /** Metadata for the connection form (no secrets). */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::DRIVERS as $name => $class) {
            $out[] = [
                'name'         => $name,
                'label'        => $class::label(),
                'available'    => $class::available(),
                'extension'    => $class::requiredExtension(),
                'defaultPort'  => $class::defaultPort(),
                'fields'       => $class::formFields(),
                'capabilities' => $class::capabilities(),
            ];
        }
        return $out;
    }
}
