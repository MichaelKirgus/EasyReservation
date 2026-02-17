<?php

return [
    'blacklist' => [
        ['path' => 'api/languages',   'methods' => ['GET']],
        ['path' => 'api/languages-names',   'methods' => ['GET']],
        ['path' => 'api/languages/*', 'methods' => ['*']],
        ['path' => 'api/flags', 'methods' => ['GET']],
        ['path' => 'api/flags/*', 'methods' => ['*']],
        ['path' => 'api/translations/*', 'methods' => ['*']],
        ['path' => 'api/admin/*',   'methods' => ['GET']],
        ['path' => 'api/public/config', 'methods' => ['GET']],
    ],

];
