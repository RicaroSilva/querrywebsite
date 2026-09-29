<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Connection to the APPLICATION database (QueryDeck metadata).
 *
 * Every query issued by the application itself goes through prepared
 * statements here. External/user databases are handled separately by
 * App\Modules\Drivers\* and never share this connection.
 */
final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private string $driver;

    private function __construct()
    {
        $cfg = config('database');
        $this->driver = $cfg['driver'];

        if ($this->driver === 'sqlite') {
            $path = $cfg['sqlite'];
            if (!str_starts_with($path, '/')) {
                $path = base_path($path);
            }
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0770, true);
            }
            $this->pdo = new PDO('sqlite:' . $path);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } elseif ($this->driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']);
            $this->pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
            ]);
        } else {
            throw new \RuntimeException("Unsupported application DB driver: {$this->driver}");
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Insert a row; column names are whitelisted by callers (never user input). */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table,
            implode(', ', $cols), implode(', ', array_fill(0, count($cols), '?')));
        $this->run($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($data)));
        return $this->run("UPDATE $table SET $set WHERE $where",
            [...array_values($data), ...$whereParams])->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
