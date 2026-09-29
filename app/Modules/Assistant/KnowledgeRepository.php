<?php
declare(strict_types=1);

namespace App\Modules\Assistant;

use App\Core\Auth;
use App\Core\Database;

/** What the assistant "knows" about each connection: team notes + validated examples. */
final class KnowledgeRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get();
    }

    public function notes(int $connectionId): string
    {
        return (string) $this->db->value("SELECT content FROM ai_knowledge WHERE connection_id = ? AND kind = 'note'", [$connectionId]);
    }

    public function saveNotes(int $connectionId, string $content): void
    {
        $id = $this->db->value("SELECT id FROM ai_knowledge WHERE connection_id = ? AND kind = 'note'", [$connectionId]);
        if ($id) {
            $this->db->update('ai_knowledge', ['content' => $content, 'updated_at' => now()], 'id = ?', [$id]);
        } else {
            $this->db->insert('ai_knowledge', ['connection_id' => $connectionId, 'kind' => 'note', 'content' => $content,
                'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function examples(int $connectionId): array
    {
        return $this->db->fetchAll("SELECT k.id, k.question, k.sql_text, k.created_at, u.name AS creator_name
            FROM ai_knowledge k LEFT JOIN users u ON u.id = k.created_by
            WHERE k.connection_id = ? AND k.kind = 'example' ORDER BY k.id DESC LIMIT 500", [$connectionId]);
    }

    public function addExample(int $connectionId, string $question, string $sql): int
    {
        // Same question again → replace the old answer
        $this->db->run("DELETE FROM ai_knowledge WHERE connection_id = ? AND kind = 'example' AND LOWER(question) = LOWER(?)",
            [$connectionId, $question]);
        return $this->db->insert('ai_knowledge', ['connection_id' => $connectionId, 'kind' => 'example',
            'question' => $question, 'sql_text' => $sql, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function deleteExample(int $connectionId, int $id): void
    {
        $this->db->run("DELETE FROM ai_knowledge WHERE id = ? AND connection_id = ? AND kind = 'example'", [$id, $connectionId]);
    }

    /** The examples most similar to the question (word overlap), best first. */
    public function relevantExamples(int $connectionId, string $question, int $limit = 6): array
    {
        $q = self::words($question);
        $scored = [];
        foreach ($this->examples($connectionId) as $i => $e) {
            $w = self::words((string) $e['question']);
            $score = count(array_intersect($q, $w)) / max(1, count(array_unique(array_merge($q, $w))));
            $scored[] = [$score, -$i, $e];
        }
        rsort($scored);
        return array_map(static fn($s) => $s[2], array_slice($scored, 0, $limit));
    }

    public static function words(string $text): array
    {
        $text = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);
        $words = preg_split('/[^a-z0-9_]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($words, static fn($w) => strlen($w) > 2)));
    }
}
