<?php

namespace App\Jobs\Concerns;

use App\Models\JobLog;
use Carbon\CarbonInterface;

trait LogsJob
{
    /**
     * Write a job log entry with optional details and timing.
     */
    protected function logJob(
        string $jobName,
        string $status,
        string $message,
        array $details = [],
        ?int $runtimeMs = null,
        ?CarbonInterface $startedAt = null,
        ?CarbonInterface $finishedAt = null,
        ?string $queueName = null
    ): void {
        $queue = $queueName ?? ($this->queue ?? ($this->onQueue ?? null));
        $workerName = config('app.worker_name');
        $encodedDetails = $details === [] ? null : json_encode($details);

        JobLog::create([
            'job' => $jobName,
            'queue' => $queue,
            'worker_name' => $workerName,
            'status' => $status,
            'runtime_ms' => $runtimeMs,
            'message' => $message,
            'details' => $encodedDetails,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
    }
}
