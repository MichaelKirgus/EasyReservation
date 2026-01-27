<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use App\Services\WorkerStatusService;

class UpdateWorkerStats
{
    public function handle(JobProcessed $event)
    {
        $workerId = gethostname() . ':' . getmypid();
        $store = app(WorkerStatusService::class)->getStore();
        $statsKey = "worker_stats:$workerId";
        $stats = $store->get($statsKey) ?: [
            'total_jobs' => 0,
            'last_job_time' => null,
            'last_job_duration' => null,
        ];

        $stats['total_jobs']++;
        $stats['last_job_time'] = now()->timestamp;
        // Versuche die Laufzeit zu bestimmen (optional, falls im Job-Objekt verfügbar)
        $payload = $event->job->payload();
        $stats['last_job_duration'] = isset($payload['runtime_ms']) ? $payload['runtime_ms'] : null;

        $store->put($statsKey, $stats, 3600 * 24 * 7);
    }
}
