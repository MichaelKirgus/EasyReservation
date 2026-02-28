<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use App\Services\WorkerStatusService;

class UpdateWorkerStats
{
    /**
     * Store job start times for duration measurement.
     *
     * @var array<string, float>
     */
    private static array $startTimes = [];

    public function subscribe(): void
    {
        // Not used, but here for completeness if needed for event subscriber registration
    }

    public function handle(JobProcessed $event): void
    {
        $workerId = gethostname() . ':' . getmypid();
        $job = $event->job;
        $key = method_exists($job, 'uuid') ? (string) $job->uuid() : spl_object_hash($job);

        // Try to get start time from static array (populated by JobProcessing event)
        $start = self::$startTimes[$key] ?? null;
        $durationMs = $start ? (int) round((microtime(true) - $start) * 1000) : null;
        unset(self::$startTimes[$key]);

        app(WorkerStatusService::class)->recordJobCompleted($workerId, $durationMs);
    }

    // Register for JobProcessing to store start time
    public function onJobProcessing($event): void
    {
        $job = $event->job;
        $key = method_exists($job, 'uuid') ? (string) $job->uuid() : spl_object_hash($job);
        self::$startTimes[$key] = microtime(true);
    }
}
