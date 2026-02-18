<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Translation Cache Driver
    |--------------------------------------------------------------------------
    |
    | This option controls which cache driver is used to store compiled
    | translations. Supported drivers: "redis", "database".
    |
    | The JSON files in resources/lang/ remain the source of truth.
    | On boot (or via the artisan command) the JSON contents are loaded
    | into the configured cache backend for fast retrieval.
    |
    */

    'driver' => env('TRANSLATION_CACHE_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | When the driver is "redis", translations are stored in the Redis
    | connection defined here. The prefix is prepended to every key.
    |
    */

    'redis' => [
        'connection' => env('TRANSLATION_REDIS_CONNECTION', 'cache'),
        'prefix'     => env('TRANSLATION_REDIS_PREFIX', 'translations:'),
        'ttl'        => env('TRANSLATION_REDIS_TTL', 86400), // seconds, 0 = forever
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | When the driver is "database", translations are stored in the
    | table specified below via Eloquent (App\Models\Translation).
    | The connection falls back to the application's default.
    |
    */

    'database' => [
        'connection' => env('TRANSLATION_DB_CONNECTION'),
        'table'      => env('TRANSLATION_DB_TABLE', 'translations'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-warm on Boot
    |--------------------------------------------------------------------------
    |
    | When enabled, the service provider will check if the cache is
    | populated and warm it from the JSON source files if it is empty.
    | Disable this if you prefer to warm the cache manually via
    | `php artisan translations:cache`.
    |
    */

    'auto_warm' => env('TRANSLATION_AUTO_WARM', true),

];
