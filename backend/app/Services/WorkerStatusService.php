<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class WorkerStatusService
{
    protected $store;
    protected $ttl = 180;

    public function __construct()
    {
        // Ensure we're using the correct cache store for worker status
        $storeName = config('workerstatus.store', config('cache.default'));
        
        // If using Redis, make sure we use the right connection that matches where workers are storing data
        if ($storeName === 'redis') {
            // Use the same approach as in WorkerHeartbeatJob - get the cache store directly
            $this->store = Cache::store('redis');
        } else {
            $this->store = Cache::store($storeName);
        }
    }

    public function setStatus($workerId, array $data)
    {
        try {
            $key = "worker_status:$workerId";
            \Illuminate\Support\Facades\Log::debug('Storing worker status for key: ' . $key);
            $this->store->put($key, $data, $this->ttl);
            \Illuminate\Support\Facades\Log::debug('Successfully stored worker status for key: ' . $key);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to store worker status for key ' . $workerId . ': ' . $e->getMessage());
            throw $e; // Re-throw to let the calling code know there was an error
        }
    }

    public function getAllStatuses()
    {
        if (method_exists($this->store->getStore(), 'connection')) {
            $redis = $this->store->getStore()->connection();

            // Get the cache prefix that Laravel's RedisStore prepends to every key.
            // Without this, the KEYS pattern will never match the stored keys.
            $cachePrefix = $this->store->getStore()->getPrefix();

            // phpredis auto-prepends the Redis connection prefix to the pattern,
            // so we only need to include the cache-level prefix here.
            $keys = $redis->keys($cachePrefix . 'worker_status:*');

            $workers = [];
            foreach ($keys as $key) {
                // $key from keys() has the Redis connection prefix stripped (phpredis)
                // but still carries the cache prefix. Strip it to get the logical key
                // that Cache::store()->get() expects.
                $logicalKey = $key;
                if ($cachePrefix && strpos($logicalKey, $cachePrefix) === 0) {
                    $logicalKey = substr($logicalKey, strlen($cachePrefix));
                }

                // Use the cache store so values are properly deserialized.
                $data = $this->store->get($logicalKey);
                if ($data) {
                    $workerId = str_replace('worker_status:', '', $logicalKey);
                    $data['worker_id'] = $workerId;

                    $statsKey = 'worker_stats:' . $workerId;
                    $stats = $this->store->get($statsKey) ?: [
                        'total_jobs' => 0,
                        'last_job_time' => null,
                        'last_job_duration' => null,
                    ];
                    $data['total_jobs'] = $stats['total_jobs'];
                    $data['last_job_time'] = $stats['last_job_time'];
                    $data['last_job_duration'] = $stats['last_job_duration'];
                    $workers[] = $data;
                }
            }
            return $workers;
        } else {
            return [];
        }
    }

    public function getStore()
    {
        return $this->store;
    }
}
