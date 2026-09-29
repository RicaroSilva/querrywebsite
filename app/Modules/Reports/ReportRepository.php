<?php
declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;

final class ReportRepository
{
    public const WIDGET_TYPES = [
        'kpi'      => 'Card / KPI',
        'table'    => 'Tabela',
        'bar'      => 'Gráfico de barras',
        'line'     => 'Gráfico de linhas',
        'area'     => 'Gráfico de área',
        'pie'      => 'Pie chart',
        'doughnut' => 'Doughnut',
        'text'     => 'Texto',
    ];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function all(): array
    {
        return $this->db->fetchAll('SELECT r.*, u.name AS creator_name,
                (SELECT COUNT(*) FROM report_widgets w WHERE w.report_id = r.id) AS widget_count
            FROM reports r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.updated_at DESC');
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM reports');
    }

    public function find(int $id): ?array
    {
        $r = $this->db->fetch('SELECT r.*, u.name AS creator_name FROM reports r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ?', [$id]);
        if ($r) {
            $r['filters'] = json_decode($r['filters'] ?? '[]', true) ?: [];
        }
        return $r;
    }

    public function findOrFail(int $id): array
    {
        return $this->find($id) ?? throw new HttpException(404, 'Relatório não encontrado.');
    }

    public function widgets(int $reportId): array
    {
        return array_map([self::class, 'presentWidget'], $this->db->fetchAll('SELECT w.*, q.name AS query_name
            FROM report_widgets w LEFT JOIN saved_queries q ON q.id = w.saved_query_id
            WHERE w.report_id = ? ORDER BY w.position, w.id', [$reportId]));
    }

    public function widget(int $reportId, int $id): array
    {
        $w = $this->db->fetch('SELECT w.*, q.name AS query_name FROM report_widgets w LEFT JOIN saved_queries q ON q.id = w.saved_query_id
            WHERE w.report_id = ? AND w.id = ?', [$reportId, $id]);
        return $w ? self::presentWidget($w) : throw new HttpException(404, 'Componente não encontrado.');
    }

    public static function presentWidget(array $w): array
    {
        return [
            'id'             => (int) $w['id'],
            'report_id'      => (int) $w['report_id'],
            'type'           => $w['type'],
            'title'          => $w['title'],
            'saved_query_id' => $w['saved_query_id'] !== null ? (int) $w['saved_query_id'] : null,
            'query_name'     => $w['query_name'] ?? null,
            'connection_id'  => $w['connection_id'] !== null ? (int) $w['connection_id'] : null,
            'sql_text'       => $w['sql_text'],
            'config'         => json_decode($w['config'] ?? '{}', true) ?: (object) [],
            'width'          => (int) $w['width'],
            'position'       => (int) $w['position'],
        ];
    }

    public function create(array $d): int
    {
        return $this->db->insert('reports', ['name' => $d['name'], 'description' => $d['description'] ?? null,
            'filters' => json_encode($d['filters'] ?? []), 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function update(int $id, array $d): void
    {
        $this->db->update('reports', ['name' => $d['name'], 'description' => $d['description'] ?? null,
            'filters' => json_encode($d['filters'] ?? []), 'updated_at' => now()], 'id = ?', [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM report_widgets WHERE report_id = ?', [$id]);
        $this->db->run('DELETE FROM reports WHERE id = ?', [$id]);
    }

    public function addWidget(int $reportId, array $d): int
    {
        $pos = (int) $this->db->value('SELECT COALESCE(MAX(position), 0) + 1 FROM report_widgets WHERE report_id = ?', [$reportId]);
        $id = $this->db->insert('report_widgets', [...$this->widgetRow($d), 'report_id' => $reportId, 'position' => $pos,
            'created_at' => now(), 'updated_at' => now()]);
        $this->touch($reportId);
        return $id;
    }

    public function updateWidget(int $reportId, int $id, array $d): void
    {
        $this->db->update('report_widgets', [...$this->widgetRow($d), 'updated_at' => now()], 'id = ? AND report_id = ?', [$id, $reportId]);
        $this->touch($reportId);
    }

    private function widgetRow(array $d): array
    {
        return [
            'type'           => $d['type'],
            'title'          => $d['title'] ?? null,
            'saved_query_id' => $d['saved_query_id'] ?? null,
            'connection_id'  => $d['connection_id'] ?? null,
            'sql_text'       => $d['sql_text'] ?? null,
            'config'         => json_encode($d['config'] ?? []),
            'width'          => in_array((int) ($d['width'] ?? 6), [3, 4, 6, 8, 12], true) ? (int) $d['width'] : 6,
        ];
    }

    public function deleteWidget(int $reportId, int $id): void
    {
        $this->db->run('DELETE FROM report_widgets WHERE id = ? AND report_id = ?', [$id, $reportId]);
        $this->touch($reportId);
    }

    public function reorder(int $reportId, array $ids): void
    {
        foreach (array_values($ids) as $pos => $id) {
            $this->db->run('UPDATE report_widgets SET position = ? WHERE id = ? AND report_id = ?', [$pos, (int) $id, $reportId]);
        }
        $this->touch($reportId);
    }

    public function duplicate(int $id): int
    {
        $r = $this->findOrFail($id);
        $new = $this->create(['name' => mb_substr($r['name'] . ' (cópia)', 0, 190), 'description' => $r['description'], 'filters' => $r['filters']]);
        foreach ($this->widgets($id) as $w) {
            $this->addWidget($new, [...$w, 'config' => (array) $w['config']]);
        }
        return $new;
    }

    private function touch(int $id): void
    {
        $this->db->run('UPDATE reports SET updated_at = ? WHERE id = ?', [now(), $id]);
    }
}
