<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Data Portability Queue
    |--------------------------------------------------------------------------
    |
    | Dedicated queue for backup, restore, and transport operations.
    |
    */
    'queue' => env('DATA_PORTABILITY_QUEUE', 'data-portability'),

    /*
    |--------------------------------------------------------------------------
    | Restore Defaults
    |--------------------------------------------------------------------------
    */
    'restore' => [
        'default_mode' => env('DATA_PORTABILITY_RESTORE_DEFAULT_MODE', 'truncate_insert'),
        'insert_chunk_size' => (int) env('DATA_PORTABILITY_RESTORE_INSERT_CHUNK_SIZE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Defaults
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'disk' => env('DATA_PORTABILITY_DISK', env('FILESYSTEM_DISK', 'local')),
        'base_path' => env('DATA_PORTABILITY_BASE_PATH', 'data-portability'),
        'backup_dir' => env('DATA_PORTABILITY_BACKUP_DIR', 'backups'),
        'upload_dir' => env('DATA_PORTABILITY_UPLOAD_DIR', 'uploads'),
        'upload_max_kb' => (int) env('DATA_PORTABILITY_UPLOAD_MAX_KB', 51200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Discovery Rules
    |--------------------------------------------------------------------------
    |
    | Exclusions are config-driven to avoid hardcoding in application logic.
    |
    */
    'tables' => [
        'exclude_exact' => [
            'migrations',
            'jobs',
            'failed_jobs',
            'job_batches',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
        ],
        'exclude_patterns' => [
            'telescope_%',
            'horizon_%',
        ],
    ],

    'transport' => [
        'receive_endpoint' => env('DATA_PORTABILITY_TRANSPORT_RECEIVE_ENDPOINT', '/api/admin/data-portability/receive-transport'),
        'preflight_endpoint' => env('DATA_PORTABILITY_TRANSPORT_PREFLIGHT_ENDPOINT', '/api/admin/data-portability/preflight-transport'),
        'timeout_seconds' => (int) env('DATA_PORTABILITY_TRANSPORT_TIMEOUT', 120),
        'strict_tables' => env('DATA_PORTABILITY_TRANSPORT_STRICT_TABLES', true),
    ],
];
