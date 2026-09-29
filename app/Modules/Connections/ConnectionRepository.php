<?php
declare(strict_types=1);

namespace App\Modules\Connections;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Modules\Drivers\AbstractPdoDriver;
use App\Modules\Drivers\DriverFactory;
use App\Modules\Drivers\DriverInterface;

final class ConnectionRepository
{
    public const ENVIRONMENTS = ['production' => 'Produção', 'staging' => 'Staging', 'development' => 'Desenvolvimento', 'local' => 'Local'];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    /** Full row INCLUDING encrypted secret: server-side use only. */
    public function find(int $id): ?array
    {
        // Access-control hook: later, filter by connection_user for non-admins here.
        return $this->db->fetch('SELECT * FROM connections WHERE id = ?', [$id]);
    }

    public function findOrFail(int $id): array
    {
        return $this->find($id) ?? throw new HttpException(404, 'Conexão não encontrada.');
    }

    /** Safe representation for the frontend — never includes the password. */
    public static function toPublic(array $c): array
    {
        $options = json_decode($c['options'] ?? '{}', true) ?: [];
        $class = DriverFactory::DRIVERS[$c['driver']] ?? null;
        return [
            'id'           => (int) $c['id'],
            'name'         => $c['name'],
            'driver'       => $c['driver'],
            'driver_label' => $class ? $class::label() : $c['driver'],
            'host'         => $c['host'],
            'port'         => $c['port'] !== null ? (int) $c['port'] : null,
            'database_name'=> $c['database_name'],
            'username'     => $c['username'],
            'has_password' => !empty($c['password_enc']),
            'options'      => $options,
            'environment'  => $c['environment'],
            'color'        => $c['color'],
            'read_only'    => (bool) $c['read_only'],
            'last_used_at' => $c['last_used_at'],
            'capabilities' => $class ? $class::capabilities() : [],
        ];
    }

    public function all(): array
    {
        return array_map([self::class, 'toPublic'],
            $this->db->fetchAll("SELECT * FROM connections ORDER BY CASE environment WHEN 'production' THEN 0 WHEN 'staging' THEN 1 WHEN 'development' THEN 2 ELSE 3 END, name"));
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM connections');
    }

    /**
     * Validate + normalise form input into a DB row.
     * $existing: when editing, an empty password keeps the stored one.
     */
    public function normalise(array $in, ?array $existing = null): array
    {
        $driver = (string) ($in['driver'] ?? '');
        $class = DriverFactory::DRIVERS[$driver] ?? null;
        $errors = [];
        if (!$class) {
            throw new HttpException(422, 'Tipo de base de dados inválido.', ['driver' => 'Escolha um tipo.']);
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Nome obrigatório (máx. 120).';
        }

        $row = [
            'name'          => $name,
            'driver'        => $driver,
            'host'          => null,
            'port'          => null,
            'database_name' => trim((string) ($in['database_name'] ?? '')) ?: null,
            'username'      => null,
            'environment'   => array_key_exists($in['environment'] ?? '', self::ENVIRONMENTS) ? $in['environment'] : 'development',
            'color'         => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($in['color'] ?? '')) ? $in['color'] : null,
            'read_only'     => filter_var($in['read_only'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];

        $options = [];
        foreach ($class::formFields() as $f) {
            $value = $in[$f['name']] ?? ($in['options'][$f['name']] ?? null);
            if (!empty($f['option'])) {
                if ($f['type'] === 'checkbox') {
                    $options[$f['name']] = filter_var($value ?? ($f['default'] ?? false), FILTER_VALIDATE_BOOLEAN);
                } elseif ($f['type'] === 'select') {
                    $options[$f['name']] = in_array($value, $f['choices'], true) ? $value : ($f['default'] ?? $f['choices'][0]);
                } elseif ($value !== null && $value !== '') {
                    $options[$f['name']] = $f['type'] === 'number' ? (int) $value : trim((string) $value);
                }
                continue;
            }
            if ($f['name'] === 'host') {
                $row['host'] = trim((string) $value) ?: null;
            } elseif ($f['name'] === 'port') {
                $row['port'] = $value !== null && $value !== '' ? (int) $value : $class::defaultPort();
            } elseif ($f['name'] === 'username') {
                $row['username'] = trim((string) $value) ?: null;
            }
            if (!empty($f['required']) && ($value === null || trim((string) $value) === '')) {
                $errors[$f['name']] = 'Campo obrigatório.';
            }
        }
        if ($row['port'] !== null && ($row['port'] < 1 || $row['port'] > 65535)) {
            $errors['port'] = 'Porta inválida.';
        }
        foreach (['host', 'database_name', 'username'] as $f) {
            try {
                if ($driver !== 'sqlite') {
                    AbstractPdoDriver::assertSafeDsnValue($row[$f], $f);
                }
            } catch (\InvalidArgumentException) {
                $errors[$f] = 'Caracteres não permitidos ( ; = aspas ).';
            }
        }
        foreach ($options as $k => $v) {
            if (is_string($v) && preg_match('/[;\x00-\x1F]/', $v)) {
                $errors[$k] = 'Caracteres não permitidos.';
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Verifique os campos assinalados.', $errors);
        }
        $row['options'] = json_encode($options);

        $password = $in['password'] ?? null;
        if (is_string($password) && $password !== '') {
            $row['password_enc'] = Crypto::encrypt($password);
        } elseif (!empty($in['clear_password'])) {
            $row['password_enc'] = null;
        } elseif ($existing) {
            $row['password_enc'] = $existing['password_enc'];
        } else {
            $row['password_enc'] = null;
        }
        return $row;
    }

    public function create(array $row): int
    {
        return $this->db->insert('connections', [...$row, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function update(int $id, array $row): void
    {
        $this->db->update('connections', [...$row, 'updated_at' => now()], 'id = ?', [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM connections WHERE id = ?', [$id]);
    }

    public function touch(int $id): void
    {
        $this->db->run('UPDATE connections SET last_used_at = ? WHERE id = ?', [now(), $id]);
    }

    public function driver(array $conn, ?string $database = null): DriverInterface
    {
        return DriverFactory::fromConnection($conn)->withDatabase($database);
    }
}
