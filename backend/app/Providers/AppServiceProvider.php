<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use App\Services\PlaceholderService;
use App\Services\SettingsService;
use App\Services\EventService;
use App\Services\CustomPlaceholderService;
use App\Services\SiteTokenService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PlaceholderService::class, function ($app) {
            return new PlaceholderService(
                $app->make(EventService::class),
                $app->make(SettingsService::class),
                $app->make(CustomPlaceholderService::class),
                $app->make(SiteTokenService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('database.default') === 'mysql') {
            $config = config('database.connections.mysql');
            $database = $config['database'] ?? null;

            if ($database) {
                $charset = $config['charset'] ?? 'utf8mb4';
                $collation = $config['collation'] ?? 'utf8mb4_unicode_ci';

                config()->set('database.connections.mysql.database', null);
                DB::purge('mysql');

                DB::statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET {$charset} COLLATE {$collation}");

                config()->set('database.connections.mysql.database', $database);
                DB::purge('mysql');
            }
        }

        // Heartbeat-Logik wurde entfernt. Die Aktualisierung erfolgt ausschließlich über den Scheduler-Command worker:heartbeat.
    }

}
