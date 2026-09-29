<?php
declare(strict_types=1);

namespace App\Modules\History;

use App\Core\Database;
use App\Core\Logger;

final class HistoryRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function record(array $row): void
    {
        try {
            $this->db->insert('query_history', [...$row, 'created_at' => now()]);
        } catch (\Throwable $e) {
            Logger::error('History write failed: ' . $e->getMessage());
        }
    }

    public function find(int $id, int $userId, bool $all = false): ?array
    {
        return $this->db->fetch('SELECT h.*, u.name AS user_name FROM query_history h LEFT JOIN users u ON u.id = h.user_id
            WHERE h.id = ?' . ($all ? '' : ' AND h.user_id = ?'), $all ? [$id] : [$id, $userId]);
    }

    /** @param array $f status, connection_id, search, user (admin: 'all') */
    public function paginate(int $userId, bool $all, array $f, int $page = 1, int $perPage = 50): array
    {
        $where = [];
        $params = [];
        if (!$all || ($f['scope'] ?? 'mine') !== 'all') {
            $where[] = 'h.user_id = ?';
            $params[] = $userId;
        }
        if (!empty($f['status']) && in_array($f['status'], ['success', 'error', 'cancelled'], true)) {
            $where[] = 'h.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['connection_id'])) {
            $where[] = 'h.connection_id = ?';
            $params[] = (int) $f['connection_id'];
        }
        if (!empty($f['search'])) {
            $where[] = 'LOWER(h.sql_text) LIKE ?';
            $params[] = '%' . mb_strtolower($f['search']) . '%';
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->db->value("SELECT COUNT(*) FROM query_history h $sqlWhere", $params);
        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->fetchAll("SELECT h.id, h.user_id, u.name AS user_name, h.connection_id, h.connection_name,
                h.saved_query_id, h.sql_text, h.status, h.error_message, h.duration_ms, h.row_count, h.created_at
            FROM query_history h LEFT JOIN users u ON u.id = h.user_id $sqlWhere
            ORDER BY h.created_at DESC, h.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset, $params);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function recent(int $userId, int $limit = 8): array
    {
        return $this->db->fetchAll('SELECT h.id, h.sql_text, h.connection_id, h.connection_name, h.status, h.duration_ms, h.row_count,
                h.created_at, h.saved_query_id, q.name AS query_name, c.driver
            FROM query_history h
            LEFT JOIN saved_queries q ON q.id = h.saved_query_id
            LEFT JOIN connections c ON c.id = h.connection_id
            WHERE h.user_id = ? ORDER BY h.created_at DESC, h.id DESC LIMIT ' . (int) $limit, [$userId]);
    }

    public function stats(int $userId): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-13 days midnight'));
        $rows = $this->db->fetchAll('SELECT created_at, status FROM query_history WHERE user_id = ? AND created_at >= ?', [$userId, $since]);
        $days = [];
        for ($d = 13; $d >= 0; $d--) {
            $days[date('Y-m-d', strtotime("-$d days"))] = ['success' => 0, 'error' => 0];
        }
        foreach ($rows as $r) {
            $day = substr($r['created_at'], 0, 10);
            if (isset($days[$day])) {
                $days[$day][$r['status'] === 'success' ? 'success' : 'error']++;
            }
        }
        return $days;
    }

    public function clear(int $userId): int
    {
        return $this->db->run('DELETE FROM query_history WHERE user_id = ?', [$userId])->rowCount();
    }
}
