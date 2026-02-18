<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use App\Services\WorkerStatusService;

class UpdateWorkerStats
{
    public function handle(JobProcessed $event): void
    {
        $workerId = gethostname() . ':' . getmypid();

        // Try to extract runtime from the job payload (if the dispatching code sets it)
        $payload    = $event->job->payload();
        $durationMs = $payload['runtime_ms'] ?? null;

        app(WorkerStatusService::class)->recordJobCompleted($workerId, $durationMs);
    }
}
