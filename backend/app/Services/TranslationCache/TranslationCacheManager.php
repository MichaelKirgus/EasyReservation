<?php

namespace App\Services\TranslationCache;

use App\Contracts\TranslationCacheStore;
use Illuminate\Support\Manager;

/**
 * Manages translation cache drivers using Laravel's Manager pattern.
 *
 * Drivers can be resolved via the config key `translations.driver`.
 * Custom drivers may be registered at runtime:
 *
 *     app(TranslationCacheManager::class)->extend('custom', fn ($app) => ...);
 */
class TranslationCacheManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('translations.driver', 'redis');
    }

    // ------------------------------------------------------------------
    // Built-in drivers
    // ------------------------------------------------------------------

    protected function createRedisDriver(): TranslationCacheStore
    {
        $cfg = $this->config->get('translations.redis', []);

        return new RedisTranslationCacheStore(
            $cfg['connection'] ?? 'cache',
            $cfg['prefix']     ?? 'translations:',
            (int) ($cfg['ttl'] ?? 86400),
        );
    }

    protected function createDatabaseDriver(): TranslationCacheStore
    {
        $cfg = $this->config->get('translations.database', []);

        return new DatabaseTranslationCacheStore(
            $cfg['connection'] ?? null,
            $cfg['table']      ?? 'translations',
        );
    }
}
