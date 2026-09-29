<?php
declare(strict_types=1);

namespace App\Core;

/** Audit log of important actions (login, connection changes, exports...). */
final class Audit
{
    public static function log(string $action, ?string $entity = null, int|string|null $entityId = null, array $meta = []): void
    {
        try {
            Database::get()->insert('audit_logs', [
                'user_id'    => Auth::id(),
                'action'     => $action,
                'entity'     => $entity,
                'entity_id'  => $entityId === null ? null : (string) $entityId,
                'meta'       => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'cli',
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Audit write failed: ' . $e->getMessage());
        }
    }
}
