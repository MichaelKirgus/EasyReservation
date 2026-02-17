<?php

namespace App\Services;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;

/**
 * Centralised rate-limit cache access.
 *
 * Every piece of code that reads, writes, lists or deletes rate-limit
 * entries MUST go through this service so there is exactly one source
 * of truth regardless of the configured cache driver.
 */
class RateLimitCacheService
{
    private CacheRepository $store;
    private string $driver;

    public function __construct(CacheManager $cacheManager)
    {
        $storeName = config('ratelimit.store') ?? config('cache.default');
        $this->store = $cacheManager->store($storeName);

        // Resolve the driver name for backend-specific operations (key listing).
        $this->driver = config("cache.stores.{$storeName}.driver", 'database');
    }

    /* ------------------------------------------------------------------
     |  Basic cache operations
     | ----------------------------------------------------------------*/

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($key, $default);
    }

    public function put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int $ttl): bool
    {
        return $this->store->put($key, $value, $ttl);
    }

    public function forget(string $key): bool
    {
        return $this->store->forget($key);
    }

    public function increment(string $key, int $value = 1): int|bool
    {
        return $this->store->increment($key, $value);
    }

    /* ------------------------------------------------------------------
     |  Key listing / pattern search  (driver-aware)
     | ----------------------------------------------------------------*/

    /**
     * Return all logical cache keys matching a given prefix string.
     * Example: findKeys('email_validation_rate:') returns all rate-limit keys.
     */
    public function findKeys(string $prefix): array
    {
        return match ($this->driver) {
            'redis'    => $this->findKeysRedis($prefix),
            'database' => $this->findKeysDatabase($prefix),
            'file'     => $this->findKeysFile($prefix),
            default    => $this->findKeysDatabase($prefix),
        };
    }

    /**
     * Delete all keys matching a given prefix.
     */
    public function forgetByPrefix(string $prefix): int
    {
        $keys = $this->findKeys($prefix);
        foreach ($keys as $key) {
            $this->store->forget($key);
        }
        return count($keys);
    }

    /* ------------------------------------------------------------------
     |  Driver-specific key discovery
     | ----------------------------------------------------------------*/

    private function findKeysRedis(string $logicalPrefix): array
    {
        /** @var \Illuminate\Cache\RedisStore $redisStore */
        $redisStore = $this->store->getStore();
        $cachePrefix = $redisStore->getPrefix();
        $redis = $redisStore->connection();

        $rawKeys = $redis->keys($cachePrefix . $logicalPrefix . '*');

        return array_map(
            fn (string $raw) => ($cachePrefix !== '' && str_starts_with($raw, $cachePrefix))
                ? substr($raw, strlen($cachePrefix))
                : $raw,
            $rawKeys,
        );
    }

    private function findKeysDatabase(string $logicalPrefix): array
    {
        $storeName = config('ratelimit.store') ?? config('cache.default');
        $storeConfig = config("cache.stores.{$storeName}");
        $table = $storeConfig['table'] ?? 'cache';
        $cachePrefix = config('cache.prefix', '');

        $rows = DB::table($table)
            ->where('key', 'like', $cachePrefix . $logicalPrefix . '%')
            ->where('expiration', '>=', now()->timestamp)
            ->pluck('key');

        return $rows->map(fn (string $raw) =>
            ($cachePrefix !== '' && str_starts_with($raw, $cachePrefix))
                ? substr($raw, strlen($cachePrefix))
                : $raw
        )->values()->all();
    }

    private function findKeysFile(string $logicalPrefix): array
    {
        // File driver stores keys hashed — we cannot scan by prefix.
        // Fall back: attempt database lookup (many setups have both).
        // If that also fails, return empty.
        try {
            return $this->findKeysDatabase($logicalPrefix);
        } catch (\Throwable) {
            return [];
        }
    }
}
