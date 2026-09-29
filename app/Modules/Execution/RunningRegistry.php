<?php
declare(strict_types=1);

namespace App\Modules\Execution;

/** Tracks in-flight executions (backend id per execution) so they can be cancelled. */
final class RunningRegistry
{
    private static function file(string $execId): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $execId)) {
            throw new \InvalidArgumentException('Invalid execution id');
        }
        return storage_path("cache/running/$execId.json");
    }

    public static function register(string $execId, array $data): void
    {
        @mkdir(storage_path('cache/running'), 0770, true);
        file_put_contents(self::file($execId), json_encode($data + ['started_at' => time()]));
    }

    public static function get(string $execId): ?array
    {
        $f = self::file($execId);
        return is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    }

    public static function remove(string $execId): void
    {
        @unlink(self::file($execId));
    }

    public static function markCancelled(string $execId): void
    {
        $data = self::get($execId);
        if ($data) {
            $data['cancelled'] = true;
            file_put_contents(self::file($execId), json_encode($data));
        }
    }
}
