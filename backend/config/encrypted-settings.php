<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Encrypted Settings Fields
    |--------------------------------------------------------------------------
    |
    | These settings will be automatically encrypted when stored in the database
    | and decrypted when retrieved. This protects sensitive data like passwords
    | and API keys from being exposed in plain text.
    |
    */

    'fields' => [
        'mail_password',
        'mail_username',
    ],
];
