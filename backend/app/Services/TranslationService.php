<?php

namespace App\Services;

use App\Contracts\TranslationCacheStore;
use App\Services\TranslationCache\TranslationCacheManager;
use Illuminate\Support\Facades\File;

class TranslationService
{
    protected TranslationCacheStore $store;

    public function __construct(TranslationCacheManager $manager)
    {
        $this->store = $manager->driver();
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Get all translations for a locale.
     *
     * Tries the cache first; falls back to JSON source files.
     *
     * @return array<string, string>
     */
    public function getTranslations(string $locale, ?array $whitelist = null): array
    {
        $cached = $this->store->get($locale);

        if ($cached !== null) {
            return $whitelist !== null ? array_intersect_key($cached, array_flip($whitelist)) : $cached;
        }

        // Cache miss – load from JSON and warm the cache
        $translations = $this->loadFromJson($locale);

        if ($translations !== null) {
            if ($whitelist !== null) {
                $translations = array_intersect_key($translations, array_flip($whitelist));
            }
            $this->store->put($locale, $translations);
            return $translations;
        }

        return [];
    }

    /**
     * Check whether translations exist for the given locale
     * (either cached or as a JSON source file).
     */
    public function localeExists(string $locale): bool
    {
        return $this->store->has($locale) || File::exists($this->jsonPath($locale));
    }

    /**
     * Warm the cache for all available locales from the JSON source files.
     *
     * @return list<string>  locales that were warmed
     */
    public function warmAll(): array
    {
        $warmed  = [];

        foreach ($this->availableLocales() as $locale) {
            $translations = $this->loadFromJson($locale);

            if ($translations !== null) {
                $this->store->put($locale, $translations);
                $warmed[] = $locale;
            }
        }

        return $warmed;
    }

    /**
     * Warm the cache for a single locale from its JSON source file.
     */
    public function warm(string $locale): bool
    {
        $translations = $this->loadFromJson($locale);

        if ($translations === null) {
            return false;
        }

        $this->store->put($locale, $translations);

        return true;
    }

    /**
     * Clear cached translations for a single locale.
     */
    public function forget(string $locale): void
    {
        $this->store->forget($locale);
    }

    /**
     * Clear all cached translations.
     */
    public function flush(): void
    {
        $this->store->flush();
    }

    /**
     * Return the list of locales that have a JSON source file.
     *
     * @return list<string>
     */
    public function availableLocales(): array
    {
        $files = File::glob(resource_path('lang/*.json'));

        return array_map(
            fn (string $path) => pathinfo($path, PATHINFO_FILENAME),
            $files ?: [],
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array<string, string>|null
     */
    protected function loadFromJson(string $locale): ?array
    {
        $path = $this->jsonPath($locale);

        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function jsonPath(string $locale): string
    {
        return resource_path("lang/{$locale}.json");
    }
}
