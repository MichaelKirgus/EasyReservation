<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WorkerStatusService;
use Illuminate\Support\Facades\DB;

class WorkerHeartbeat extends Command
{
    protected $signature = 'worker:heartbeat';
    protected $description = 'Send worker/scheduler diagnostics heartbeat';

    public function handle(WorkerStatusService $service): int
    {
        $hostname = gethostname();
        $pid      = getmypid();
        // Allow WORKER_NAME env var to override workerId for uniqueness
        $workerName = getenv('WORKER_NAME');
        $workerId = $workerName ? ($workerName . ':' . $pid) : ($hostname . ':' . $pid);

        // ── Redis latency ──
        $redisLatency = null;
        try {
            $start = microtime(true);
            \Illuminate\Support\Facades\Redis::ping();
            $redisLatency = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable $e) {
            // Redis not available
        }

        // ── DB latency ──
        $dbLatency = null;
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $dbLatency = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable $e) {
            // DB not available
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

        $this->info("Heartbeat sent for {$workerId}");
        return self::SUCCESS;
    }
}
