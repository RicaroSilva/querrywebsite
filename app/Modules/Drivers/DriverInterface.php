<?php
declare(strict_types=1);

namespace App\Modules\Drivers;

use PDO;

/**
 * Contract every external database engine implements.
 *
 * Adding a new engine = one class implementing this interface (usually by
 * extending AbstractPdoDriver) + one line in DriverFactory::DRIVERS.
 * Nothing else in the application needs to change.
 */
interface DriverInterface
{
    /** Machine name stored in connections.driver (e.g. "pgsql"). */
    public static function name(): string;

    public static function label(): string;

    /** PHP extension(s) needed; used to show a helpful message in the UI. */
    public static function requiredExtension(): string;

    public static function available(): bool;

    public static function defaultPort(): ?int;

    /**
     * Connection form definition for the UI:
     * [['name' => 'host', 'label' => 'Host', 'type' => 'text', 'default' => ..., 'required' => bool], ...]
     */
    public static function formFields(): array;

    /** What the explorer can show: databases, schemas, functions, procedures, sequences... */
    public static function capabilities(): array;

    /** 'semicolon' or 'batch' (T-SQL GO). */
    public static function splitMode(): string;

    public function pdo(): PDO;

    /** Same connection settings, but pointing to another database (PostgreSQL). */
    public function withDatabase(?string $database): static;

    public function currentDatabase(): ?string;

    public function serverVersion(): string;

    /** Apply statement timeout and read-only safety net for this session. */
    public function prepareSession(int $timeoutSeconds, bool $readOnly): void;

    /** Identifier of the server-side session/backend (used to cancel). */
    public function backendId(): ?string;

    /** Cancel the running statement of another backend. Runs on a separate connection. */
    public function cancel(string $backendId): bool;

    /** Execute one statement/batch and return a forward-only cursor. */
    public function open(string $sql, array $params = [], bool $inUserTransaction = false): ResultCursor;

    public function quoteIdentifier(string $name): string;

    /** SELECT … LIMIT n for the given object (used by "View data"). */
    public function previewSql(?string $schema, string $table, int $limit = 100): string;

    // ---- metadata / explorer ------------------------------------------------
    public function databases(): array;

    public function schemas(?string $database): array;

    /** @param string $type tables|views|functions|procedures|sequences */
    public function objects(?string $database, ?string $schema, string $type): array;

    public function columns(?string $database, ?string $schema, string $table): array;

    public function indexes(?string $database, ?string $schema, string $table): array;

    public function foreignKeys(?string $database, ?string $schema, string $table): array;

    /** Source/DDL for views, functions and procedures when the engine exposes it. */
    public function definition(?string $database, ?string $schema, string $name, string $type): ?string;

    /** Table → column names map for editor autocompletion (bounded). */
    public function completion(?string $database, ?string $schema): array;

    /**
     * Normalise a driver error into ['code' => ?, 'message' => string, 'line' => ?int, 'position' => ?int].
     * Line/position are relative to the executed statement.
     */
    public function parseError(\Throwable $e, string $sql): array;
}
