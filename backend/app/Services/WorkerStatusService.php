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
            // Prefix berücksichtigen (z.B. aus config/database.php)
            $prefix = '';
            if (method_exists($redis, 'getOptions')) {
                $options = $redis->getOptions();
                if ($options && isset($options['prefix'])) {
                    $prefix = $options['prefix'];
                }
            } elseif (property_exists($redis, 'options') && isset($redis->options['prefix'])) {
                $prefix = $redis->options['prefix'];
            }
            
            // Try multiple patterns to find worker keys
            $keys = [];
            $patterns = [
                'worker_status:*',
                $prefix . 'worker_status:*'
            ];
            
            foreach ($patterns as $pattern) {
                $foundKeys = $redis->keys($pattern);
                if (!empty($foundKeys)) {
                    $keys = array_merge($keys, $foundKeys);
                    break; // Found keys with one pattern, no need to try others
                }
            }
            
            $workers = [];
            foreach ($keys as $key) {
                $data = $this->store->get($key);
                if ($data) {
                    // worker_id extrahieren (Prefix und worker_status: entfernen)
                    $workerId = $key;
                    if ($prefix && strpos($workerId, $prefix) === 0) {
                        $workerId = substr($workerId, strlen($prefix));
                    }
                    $workerId = str_replace('worker_status:', '', $workerId);
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
