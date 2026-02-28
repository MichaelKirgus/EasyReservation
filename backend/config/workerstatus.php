<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Worker Status Storage Driver
    |--------------------------------------------------------------------------
    |
    | Determines where worker heartbeats and stats are stored.
    | Supported: "database", "redis"
    |
    */
    'driver' => env('WORKERSTATUS_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Heartbeat TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Workers that haven't sent a heartbeat within this window are considered
    | offline. Also used as the TTL for Redis keys.
    |
    */
    'ttl' => (int) env('WORKERSTATUS_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | Only used when driver = "redis".
    | "connection" maps to a key in database.redis.* (e.g. "default", "cache").
    | "prefix" is prepended to every key so worker data doesn't collide with
    | other Redis usage.
    |
    */
    'redis' => [
        'connection' => env('WORKERSTATUS_REDIS_CONNECTION', 'default'),
        'prefix' => env('WORKERSTATUS_REDIS_PREFIX', 'easyreservation-database-worker:'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Only used when driver = "database".
    | "connection" is the database connection name (null = default).
    | "table" is the table where worker status rows are stored.
    |
    */
    'database' => [
        'connection' => env('WORKERSTATUS_DB_CONNECTION', null),
        'table'      => env('WORKERSTATUS_DB_TABLE', 'worker_statuses'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cleanup: keep stale entries (seconds)
    |--------------------------------------------------------------------------
    |
    | Database rows older than this will be purged on cleanup.
    | (Redis keys auto-expire via their TTL.)
    |
    */
    'cleanup_after' => (int) env('WORKERSTATUS_CLEANUP_AFTER', 86400),

];
