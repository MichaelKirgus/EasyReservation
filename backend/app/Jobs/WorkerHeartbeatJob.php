<?php

namespace App\Jobs;

use App\Services\WorkerStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkerHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('heartbeat');
    }

    public function handle(WorkerStatusService $service): void
    {
        $hostname = gethostname();
        $pid      = getmypid();

        // Prefer explicit WORKER_NAME when provided so IDs stay stable across hosts
        $workerName = getenv('WORKER_NAME') ?: $hostname;
        $workerId   = $workerName . ':' . $pid;

        // ── Measure Redis latency ──
        $redisLatency = null;
        try {
            $start = microtime(true);
            \Illuminate\Support\Facades\Redis::ping();
            $redisLatency = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable $e) {
            Log::debug('[WorkerHeartbeat] Redis latency check failed: ' . $e->getMessage());
        }

        // ── Measure DB latency ──
        $dbLatency = null;
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $dbLatency = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable $e) {
            Log::debug('[WorkerHeartbeat] DB latency check failed: ' . $e->getMessage());
        }

        $service->heartbeat($workerId, [
            'hostname'         => $hostname,
            'pid'              => $pid,
            'ip'               => gethostbyname($hostname),
            'memory_bytes'     => memory_get_usage(true),
            'redis_latency_ms' => $redisLatency,
            'db_latency_ms'    => $dbLatency,
            'active_jobs'      => [],
        ]);
    }
}
