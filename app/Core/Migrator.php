<?php
declare(strict_types=1);

namespace App\Core;

/** Runs database/migrations/*.sql once each, in order, for the application DB. */
final class Migrator
{
    public function __construct(private Database $db)
    {
    }

    public function run(callable $out): int
    {
        $pdo = $this->db->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) NOT NULL PRIMARY KEY, ran_at DATETIME NOT NULL)');
        $done = array_column($this->db->fetchAll('SELECT name FROM migrations'), 'name');
        $count = 0;

        foreach (glob(base_path('database/migrations/*.sql')) as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $sql = $this->translate((string) file_get_contents($file));
            foreach ($this->statements($sql) as $statement) {
                $pdo->exec($statement);
            }
            $this->db->insert('migrations', ['name' => $name, 'ran_at' => now()]);
            $out("  ✔ $name");
            $count++;
        }
        return $count;
    }

    private function translate(string $sql): string
    {
        $mysql = $this->db->driver() === 'mysql';
        return strtr($sql, [
            '{id}'     => $mysql ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            '{fk}'     => $mysql ? 'INT UNSIGNED' : 'INTEGER',
            '{engine}' => $mysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
        ]);
    }

    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        return array_filter(array_map('trim', explode(';', $sql)));
    }
}
