<?php
/**
 * Role => permissions map.
 *
 * v1 uses a single `role` column on users. The schema already contains
 * `roles`, `permissions`, `role_permissions` and `connection_user` tables so
 * this can later move to the database without touching controllers:
 * controllers only ever call Auth::can('permission.name').
 *
 * '*' grants everything.
 */
return [
    'admin' => ['*'],
    'editor' => [
        'dashboard.view',
        'connections.view', 'connections.manage',
        'queries.execute', 'queries.write',
        'queries.view', 'queries.manage',
        'reports.view', 'reports.manage',
        'history.view', 'export.run',
        'assistant.use', 'assistant.teach',
    ],
    'readonly' => [
        'dashboard.view',
        'connections.view',
        'queries.execute',          // enforced read-only at the session level
        'queries.view',
        'reports.view',
        'history.view', 'export.run',
        'assistant.use',            // AI-generated SQL always runs read-only
    ],
];
