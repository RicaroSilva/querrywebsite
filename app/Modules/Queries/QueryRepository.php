<?php
declare(strict_types=1);

namespace App\Modules\Queries;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

final class QueryRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    private function baseSelect(): string
    {
        return 'SELECT q.*, f.name AS folder_name, c.name AS connection_name, c.driver AS connection_driver,
                u.name AS creator_name, uu.name AS updater_name,
                CASE WHEN fav.query_id IS NULL THEN 0 ELSE 1 END AS is_favorite
            FROM saved_queries q
            LEFT JOIN folders f ON f.id = q.folder_id
            LEFT JOIN connections c ON c.id = q.connection_id
            LEFT JOIN users u ON u.id = q.created_by
            LEFT JOIN users uu ON uu.id = q.updated_by
            LEFT JOIN query_favorites fav ON fav.query_id = q.id AND fav.user_id = ?';
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch($this->baseSelect() . ' WHERE q.id = ?', [Auth::id(), $id]);
    }

    public function findOrFail(int $id): array
    {
        return $this->find($id) ?? throw new HttpException(404, 'Query não encontrada.');
    }

    /** @param array $f search, folder_id ('none' for root), connection_id, tag, favorites, sort */
    public function search(array $f = []): array
    {
        $where = [];
        $params = [Auth::id()];
        if (!empty($f['search'])) {
            $where[] = '(LOWER(q.name) LIKE ? OR LOWER(q.description) LIKE ? OR LOWER(q.sql_text) LIKE ? OR LOWER(q.tags) LIKE ?)';
            $like = '%' . mb_strtolower($f['search']) . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (($f['folder_id'] ?? '') === 'none') {
            $where[] = 'q.folder_id IS NULL';
        } elseif (!empty($f['folder_id'])) {
            $where[] = 'q.folder_id = ?';
            $params[] = (int) $f['folder_id'];
        }
        if (!empty($f['connection_id'])) {
            $where[] = 'q.connection_id = ?';
            $params[] = (int) $f['connection_id'];
        }
        if (!empty($f['tag'])) {
            $where[] = "(',' || LOWER(REPLACE(q.tags, ' ', '')) || ',') LIKE ?";
            $params[] = '%,' . mb_strtolower(str_replace(' ', '', $f['tag'])) . ',%';
        }
        if (!empty($f['favorites'])) {
            $where[] = 'fav.query_id IS NOT NULL';
        }
        $order = match ($f['sort'] ?? 'updated') {
            'name'  => 'q.name ASC',
            'runs'  => 'q.run_count DESC, q.name',
            'run'   => 'q.last_run_at DESC',
            default => 'q.updated_at DESC',
        };
        $sql = $this->baseSelect() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $order LIMIT 500";
        if ($this->db->driver() === 'mysql') {
            $sql = str_replace("(',' || LOWER(REPLACE(q.tags, ' ', '')) || ',')", "CONCAT(',', LOWER(REPLACE(q.tags, ' ', '')), ',')", $sql);
        }
        return $this->db->fetchAll($sql, $params);
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM saved_queries');
    }

    public function favorites(int $limit = 8): array
    {
        return array_slice($this->search(['favorites' => true, 'sort' => 'run']), 0, $limit);
    }

    public function allTags(): array
    {
        $tags = [];
        foreach ($this->db->fetchAll("SELECT tags FROM saved_queries WHERE tags IS NOT NULL AND tags <> ''") as $r) {
            foreach (self::parseTags($r['tags']) as $t) {
                $tags[$t] = ($tags[$t] ?? 0) + 1;
            }
        }
        arsort($tags);
        return $tags;
    }

    public static function parseTags(?string $tags): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn($t) => mb_substr(mb_strtolower(trim($t)), 0, 40), explode(',', (string) $tags)))));
    }

    public function create(array $d): int
    {
        return $this->db->insert('saved_queries', [
            'name' => $d['name'], 'description' => $d['description'] ?? null, 'sql_text' => $d['sql_text'],
            'connection_id' => $d['connection_id'] ?? null, 'folder_id' => $d['folder_id'] ?? null,
            'tags' => implode(', ', self::parseTags($d['tags'] ?? '')) ?: null,
            'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function update(int $id, array $d): void
    {
        $this->db->update('saved_queries', [
            'name' => $d['name'], 'description' => $d['description'] ?? null, 'sql_text' => $d['sql_text'],
            'connection_id' => $d['connection_id'] ?? null, 'folder_id' => $d['folder_id'] ?? null,
            'tags' => implode(', ', self::parseTags($d['tags'] ?? '')) ?: null,
            'updated_by' => Auth::id(), 'updated_at' => now(),
        ], 'id = ?', [$id]);
    }

    public function duplicate(int $id): int
    {
        $q = $this->findOrFail($id);
        return $this->create([...$q, 'name' => mb_substr($q['name'] . ' (cópia)', 0, 190)]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM saved_queries WHERE id = ?', [$id]);
    }

    public function toggleFavorite(int $id, int $userId): bool
    {
        $exists = $this->db->value('SELECT 1 FROM query_favorites WHERE user_id = ? AND query_id = ?', [$userId, $id]);
        if ($exists) {
            $this->db->run('DELETE FROM query_favorites WHERE user_id = ? AND query_id = ?', [$userId, $id]);
            return false;
        }
        $this->db->insert('query_favorites', ['user_id' => $userId, 'query_id' => $id, 'created_at' => now()]);
        return true;
    }

    public function markRun(int $id): void
    {
        $this->db->run('UPDATE saved_queries SET run_count = run_count + 1, last_run_at = ? WHERE id = ?', [now(), $id]);
    }
}
