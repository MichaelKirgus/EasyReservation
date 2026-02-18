<?php

namespace App\Contracts;

interface TranslationCacheStore
{
    /**
     * Retrieve all translations for the given locale.
     *
     * @return array<string, string>|null  null when not cached yet
     */
    public function get(string $locale): ?array;

    /**
     * Store all translations for the given locale.
     *
     * @param  array<string, string>  $translations
     */
    public function put(string $locale, array $translations): void;

    /**
     * Remove cached translations for a single locale.
     */
    public function forget(string $locale): void;

    /**
     * Remove all cached translations (every locale).
     */
    public function flush(): void;

    /**
     * Check whether translations for the given locale are cached.
     */
    public function has(string $locale): bool;
}
