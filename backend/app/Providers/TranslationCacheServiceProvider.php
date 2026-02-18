<?php

namespace App\Providers;

use App\Contracts\TranslationCacheStore;
use App\Services\TranslationCache\TranslationCacheManager;
use App\Services\TranslationService;
use Illuminate\Support\ServiceProvider;

class TranslationCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            base_path('config/translations.php'),
            'translations',
        );

        // Manager (singleton) – resolves drivers lazily
        $this->app->singleton(TranslationCacheManager::class, function ($app) {
            return new TranslationCacheManager($app);
        });

        // Convenience binding so you can type-hint the interface directly
        $this->app->bind(TranslationCacheStore::class, function ($app) {
            return $app->make(TranslationCacheManager::class)->driver();
        });

        // The high-level service
        $this->app->singleton(TranslationService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\TranslationsCacheCommand::class,
                \App\Console\Commands\TranslationsClearCommand::class,
            ]);
        }

        // Auto-warm cache on boot when enabled
        if (config('translations.auto_warm', true)) {
            $this->app->booted(function () {
                try {
                    /** @var TranslationService $service */
                    $service = $this->app->make(TranslationService::class);

                    foreach ($service->availableLocales() as $locale) {
                        if (! $this->app->make(TranslationCacheStore::class)->has($locale)) {
                            $service->warm($locale);
                        }
                    }
                } catch (\Throwable $e) {
                    // Silently skip – cache backend may not be ready yet
                    // (e.g. during migrations or first deploy).
                    \Illuminate\Support\Facades\Log::warning(
                        'Translation cache auto-warm failed: ' . $e->getMessage()
                    );
                }
            });
        }
    }
}
