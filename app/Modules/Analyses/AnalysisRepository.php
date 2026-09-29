<?php
declare(strict_types=1);

namespace App\Modules\Analyses;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

final class AnalysisRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    private static function present(array $a): array
    {
        $a['id'] = (int) $a['id'];
        $a['connection_id'] = $a['connection_id'] !== null ? (int) $a['connection_id'] : null;
        $a['params'] = json_decode($a['params'] ?? '[]', true) ?: [];
        return $a;
    }

    public function all(): array
    {
        return array_map([self::class, 'present'], $this->db->fetchAll('SELECT a.*, c.name AS connection_name, u.name AS creator_name
            FROM analyses a LEFT JOIN connections c ON c.id = a.connection_id LEFT JOIN users u ON u.id = a.created_by
            ORDER BY COALESCE(a.category, \'\'), a.name'));
    }

    public function find(int $id): ?array
    {
        $a = $this->db->fetch('SELECT a.*, c.name AS connection_name FROM analyses a LEFT JOIN connections c ON c.id = a.connection_id WHERE a.id = ?', [$id]);
        return $a ? self::present($a) : null;
    }

    public function findOrFail(int $id): array
    {
        return $this->find($id) ?? throw new HttpException(404, 'Análise não encontrada.');
    }

    public function save(?int $id, array $d): int
    {
        $row = [
            'name'          => $d['name'],
            'description'   => $d['description'] ?: null,
            'category'      => $d['category'] ?: null,
            'connection_id' => $d['connection_id'],
            'kind'          => $d['kind'],
            'function_name' => $d['kind'] === 'function' ? $d['function_name'] : null,
            'sql_text'      => $d['kind'] === 'query' ? $d['sql_text'] : null,
            'params'        => json_encode($d['params'], JSON_UNESCAPED_UNICODE),
            'updated_at'    => now(),
        ];
        if ($id) {
            $this->db->update('analyses', $row, 'id = ?', [$id]);
            return $id;
        }
        return $this->db->insert('analyses', $row + ['created_by' => Auth::id(), 'created_at' => now()]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM analyses WHERE id = ?', [$id]);
    }
}
