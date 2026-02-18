<?php

namespace App\Services\TranslationCache;

use App\Contracts\TranslationCacheStore;
use Illuminate\Support\Facades\Redis;

class RedisTranslationCacheStore implements TranslationCacheStore
{
    protected string $connection;
    protected string $prefix;
    protected int $ttl;

    public function __construct(string $connection, string $prefix, int $ttl)
    {
        $this->connection = $connection;
        $this->prefix     = $prefix;
        $this->ttl        = $ttl;
    }

    public function get(string $locale): ?array
    {
        $raw = $this->redis()->get($this->key($locale));

        if ($raw === null || $raw === false) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function put(string $locale, array $translations): void
    {
        $payload = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($this->ttl > 0) {
            $this->redis()->setex($this->key($locale), $this->ttl, $payload);
        } else {
            $this->redis()->set($this->key($locale), $payload);
        }

        // Track known locales so flush() can remove them all
        $this->redis()->sadd($this->key('__locales'), $locale);
    }

    public function forget(string $locale): void
    {
        $this->redis()->del($this->key($locale));
        $this->redis()->srem($this->key('__locales'), $locale);
    }

    public function flush(): void
    {
        $locales = $this->redis()->smembers($this->key('__locales'));

        if (is_array($locales)) {
            foreach ($locales as $locale) {
                $this->redis()->del($this->key((string) $locale));
            }
        }

        $this->redis()->del($this->key('__locales'));
    }

    public function has(string $locale): bool
    {
        return (bool) $this->redis()->exists($this->key($locale));
    }

    // ------------------------------------------------------------------

    protected function key(string $suffix): string
    {
        return $this->prefix . $suffix;
    }

    protected function redis(): \Illuminate\Redis\Connections\Connection
    {
        return Redis::connection($this->connection);
    }
}
