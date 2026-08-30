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
        $schedule->job(new \App\Jobs\WorkerHeartbeatJob)->everyMinute();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);
        $middleware->prependToGroup('api', [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\AuditLogMiddleware::class,
        ]);
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserRole::class,
            'site-token' => \App\Http\Middleware\EnsureSiteToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (\Throwable $e) {
            try {
                $service = app(\App\Services\EventTriggerService::class);
                $service->handle('application_error', [
                    'error_message' => $e->getMessage(),
                ]);
            } catch (\Throwable $inner) {
                // Prevent infinite loops if the trigger itself fails
                \Log::warning('EventTrigger for application_error failed: ' . $inner->getMessage());
            }
        });
    })->create();
