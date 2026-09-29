<?php
declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Database;

final class UserRepository
{
    public const ROLES = ['admin' => 'Administrador', 'editor' => 'Editor', 'readonly' => 'Só leitura'];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->fetch('SELECT * FROM users WHERE LOWER(email) = LOWER(?)', [$email]);
    }

    public function all(): array
    {
        return $this->db->fetchAll('SELECT id, name, email, role, is_active, last_login_at, created_at FROM users ORDER BY name');
    }

    public function create(array $data): int
    {
        return $this->db->insert('users', [
            'name'          => $data['name'],
            'email'         => strtolower($data['email']),
            'password_hash' => self::hash($data['password']),
            'role'          => array_key_exists($data['role'] ?? '', self::ROLES) ? $data['role'] : 'editor',
            'is_active'     => 1,
            'preferences'   => json_encode(['theme' => 'dark']),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function update(int $id, array $data): void
    {
        $data['updated_at'] = now();
        $this->db->update('users', $data, 'id = ?', [$id]);
    }

    public function setPassword(int $id, string $password): void
    {
        $this->update($id, ['password_hash' => self::hash($password)]);
    }

    public static function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }

    public function preferences(array $user): array
    {
        return json_decode($user['preferences'] ?? '{}', true) ?: [];
    }

    public function setPreference(int $id, string $key, mixed $value): void
    {
        $user = $this->find($id);
        $prefs = $this->preferences($user);
        $prefs[$key] = $value;
        $this->update($id, ['preferences' => json_encode($prefs)]);
    }
}
