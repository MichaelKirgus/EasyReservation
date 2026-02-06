<?php

return [
    'store' => env('WORKERSTATUS_STORE', 'redis'), // oder 'database', 'memcached', etc.
    'redis_db' => env('WORKERSTATUS_REDIS_DB', 1),
];
