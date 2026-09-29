<?php
/**
 * Limits applied when running SQL against external databases.
 */
return [
    'timeout'        => (int) env('QUERY_TIMEOUT_SECONDS', 60),
    'max_rows'       => (int) env('QUERY_MAX_ROWS', 10000),
    'page_size'      => (int) env('QUERY_PAGE_SIZE', 100),
    'export_max'     => (int) env('EXPORT_MAX_ROWS', 0),
    'cache_ttl'      => (int) env('RESULT_CACHE_TTL', 3600),
    'history_days'   => (int) env('HISTORY_RETENTION_DAYS', 180),
];
