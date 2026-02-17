<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limit Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store used for all rate limit data (e.g. email validation
    | attempt counters). Set to null to use the application's default cache
    | store. Valid values are any store name defined in config/cache.php:
    | "database", "redis", "file", "array", etc.
    |
    | All rate limit reads, writes, listings and deletions will go through
    | this single store — a single source of truth.
    |
    */

    'store' => env('RATE_LIMIT_CACHE_STORE', 'redis'),

];
