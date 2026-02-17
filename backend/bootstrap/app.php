<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        // Run the command-based scheduler every minute
        $schedule->command('scheduled-tasks:run')->everyMinute()->withoutOverlapping();

        // Run the job-based scheduler every minute (alternative approach)
        $schedule->job(new \App\Jobs\CheckScheduledTasksJob)->everyMinute()->withoutOverlapping();

        // Register heartbeat worker job
        $schedule->job(new \App\Jobs\WorkerHeartbeatJob)->everyMinute()->onQueue('heartbeat');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\AuditLogMiddleware::class,
        ]);
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserRole::class,
            'site-token' => \App\Http\Middleware\EnsureSiteToken::class,
            'admin-key' => \App\Http\Middleware\EnsureAdminKey::class,
            'moderator-key' => \App\Http\Middleware\EnsureModeratorKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
