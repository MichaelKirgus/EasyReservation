<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class WorkerStatusService
{
    protected $store;
    protected $ttl = 30; // Sekunden

    public function __construct()
    {
        // Optional: expliziten Store wählen, z.B. 'redis', 'database', etc.
        $this->store = Cache::store(config('workerstatus.store', config('cache.default')));
    }

    public function setStatus($workerId, array $data)
    {
        $key = "worker_status:$workerId";
        $this->store->put($key, $data, $this->ttl);
    }

    public function getAllStatuses()
    {
        // Bei Redis/Memcached: alle Keys mit Prefix holen, bei DB: alle Rows
        if (method_exists($this->store->getStore(), 'connection')) {
            $redis = $this->store->getStore()->connection();
            $keys = $redis->keys('worker_status:*');
            $workers = [];
            foreach ($keys as $key) {
                $data = $this->store->get($key);
                if ($data) {
                    $data['worker_id'] = str_replace('worker_status:', '', $key);
                    // Hole Stats
                    $statsKey = 'worker_stats:' . $data['worker_id'];
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
            // Fallback: nicht alle Stores unterstützen Key-Listing
            return [];
        }
    }

    public function getStore()
    {
        return $this->store;
    }
}
