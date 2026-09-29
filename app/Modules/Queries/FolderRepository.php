<?php
declare(strict_types=1);

namespace App\Modules\Queries;

use App\Core\Auth;
use App\Core\Database;

/** Query folders (nestable through parent_id). Shared across the team in v1. */
final class FolderRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function all(): array
    {
        return $this->db->fetchAll('SELECT f.*, (SELECT COUNT(*) FROM saved_queries q WHERE q.folder_id = f.id) AS query_count
            FROM folders f ORDER BY f.name');
    }

    public function find(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM folders WHERE id = ?', [$id]);
    }

    public function create(string $name, ?int $parentId, ?string $color): int
    {
        return $this->db->insert('folders', ['name' => $name, 'parent_id' => $parentId, 'color' => $color,
            'created_by' => Auth::id(), 'created_at' => now()]);
    }

    public function update(int $id, string $name, ?int $parentId, ?string $color): void
    {
        if ($parentId === $id) {
            $parentId = null;
        }
        $this->db->update('folders', ['name' => $name, 'parent_id' => $parentId, 'color' => $color], 'id = ?', [$id]);
    }

    public function delete(int $id): void
    {
        // queries inside go back to "no folder" (FK ON DELETE SET NULL); sub-folders cascade
        $this->db->run('UPDATE saved_queries SET folder_id = NULL WHERE folder_id = ?', [$id]);
        $this->db->run('DELETE FROM folders WHERE id = ?', [$id]);
    }
}
