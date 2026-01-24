<?php

namespace App\Http;

use App\Http\Middleware\EnsureAdminKey;
use App\Http\Middleware\EnsureModeratorKey;
use App\Http\Middleware\EnsureSiteToken;
use App\Http\Middleware\EnsureUserRole;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\SubstituteBindings;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            SubstituteBindings::class,
        ],

        'api' => [
            SubstituteBindings::class,
            \App\Http\Middleware\AuditLogMiddleware::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * @var array<string, class-string|string>
     */
    protected $routeMiddleware = [
        'role' => EnsureUserRole::class,
        'site-token' => EnsureSiteToken::class,
        'admin-key' => EnsureAdminKey::class,
        'moderator-key' => EnsureModeratorKey::class,
    ];
}
