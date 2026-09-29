<?php
/**
 * Application database = QueryDeck's own metadata store.
 * External databases (the ones users query) are configured in the UI
 * and stored encrypted in the `connections` table.
 */
return [
    'driver'   => env('APP_DB_DRIVER', 'mysql'),
    'host'     => env('APP_DB_HOST', '127.0.0.1'),
    'port'     => (int) env('APP_DB_PORT', 3306),
    'database' => env('APP_DB_DATABASE', 'querydeck'),
    'username' => env('APP_DB_USERNAME', 'root'),
    'password' => env('APP_DB_PASSWORD', ''),
    'sqlite'   => env('APP_DB_SQLITE_PATH', 'storage/sqlite/querydeck.sqlite'),
    'charset'  => 'utf8mb4',
];
