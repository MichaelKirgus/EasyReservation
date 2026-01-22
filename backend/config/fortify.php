<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'views' => false, // Vue/SPA-Frontend

    'home' => '/dashboard',

    'limiter' => null,

    'middleware' => ['web'],

    'auth_middleware' => 'verified',

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirmPassword' => true,
        ]),
    ],
];
