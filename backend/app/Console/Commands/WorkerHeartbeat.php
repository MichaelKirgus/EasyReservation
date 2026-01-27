<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WorkerStatusService;

class WorkerHeartbeat extends Command
{
    protected $signature = 'worker:heartbeat';
    protected $description = 'Send worker diagnostics heartbeat to cache';

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
        $jobs = []; // Optional: Fülle mit aktuellen Jobs

        // --- Erweiterung: Gesamtzähler und letzte Jobdaten ---
        $statsKey = "worker_stats:$workerId";
        $store = app(WorkerStatusService::class)->getStore();
        $stats = $store->get($statsKey) ?: [
            'total_jobs' => 0,
            'last_job_time' => null,
            'last_job_duration' => null,
        ];

        // Simuliere: Wenn ein Job fertig ist, erhöhe Zähler und setze Zeit/Dauer (hier als Beispiel, in echt im Job-Worker setzen!)
        // $stats['total_jobs']++;
        // $stats['last_job_time'] = now()->timestamp;
        // $stats['last_job_duration'] = 1234;

        // Schreibe Stats zurück (hier nur Heartbeat, in echt im Job-Worker nach Job-Ende!)
        $store->put($statsKey, $stats, 3600 * 24 * 7); // 7 Tage aufbewahren

        app(WorkerStatusService::class)->setStatus($workerId, [
            'ip' => $ip,
            'timestamp' => now()->timestamp,
            'memory' => $memory,
            'redis_latency' => $redisLatency,
            'jobs' => json_encode($jobs),
            'total_jobs' => $stats['total_jobs'],
            'last_job_time' => $stats['last_job_time'],
            'last_job_duration' => $stats['last_job_duration'],
        ]);
        $this->info("Heartbeat sent for $workerId");
    }
}
