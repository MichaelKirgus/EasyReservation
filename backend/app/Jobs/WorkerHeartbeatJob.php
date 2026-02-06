<?php

namespace App\Jobs;

use App\Services\WorkerStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WorkerHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $queue = 'heartbeat'; // niedrige Prio-Queue

    public function handle()
    {
        $workerId = gethostname() . ':' . getmypid();
        $ip = gethostbyname(gethostname());
        $memory = memory_get_usage(true);
        $redisStart = microtime(true);
        try {
            \Illuminate\Support\Facades\Cache::store(config('workerstatus.store', config('cache.default')))->get('dummy');
            $redisLatency = round((microtime(true) - $redisStart) * 1000, 2);
        } catch (\Exception $e) {
            $redisLatency = null;
        }
        $jobs = [];
        $store = app(WorkerStatusService::class)->getStore();
        $statsKey = "worker_stats:$workerId";
        $stats = $store->get($statsKey) ?: [
            'total_jobs' => 0,
            'last_job_time' => null,
            'last_job_duration' => null,
        ];
        // Store the stats data first
        $store->put($statsKey, $stats, 1800); // 30 Minuten
        
        // Create a unified status object that includes both stats and current status info
        $statusData = [
            'ip' => $ip,
            'timestamp' => now()->timestamp,
            'memory' => $memory,
            'redis_latency' => $redisLatency,
            'jobs' => json_encode($jobs),
            'total_jobs' => $stats['total_jobs'],
            'last_job_time' => $stats['last_job_time'],
            'last_job_duration' => $stats['last_job_duration'],
        ];
        
        // Store the status data using the WorkerStatusService
        try {
            app(WorkerStatusService::class)->setStatus($workerId, $statusData);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to store worker status: ' . $e->getMessage());
        }
    }
}
