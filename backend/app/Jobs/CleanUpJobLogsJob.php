<?php

namespace App\Jobs;

use App\Models\JobLog;
use App\Jobs\Concerns\LogsJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class CleanUpJobLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use LogsJob;

    public function handle()
    {
        $retentionDays = Config::get('app.job_log_retention_days', 30); // default 30 days
        $cutoff = now()->subDays($retentionDays);
        $queue = $this->queue ?? ($this->onQueue ?? null);
        $workerName = config('app.worker_name');
        $startTime = now();

        $jobStartData = [
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toDateTimeString(),
        ];

        // Log job start
        $this->logJob(
            'CleanUpJobLogsJob',
            'started',
            "Cleanup started for job logs older than {$retentionDays} days.",
            $jobStartData,
            null,
            $startTime,
            null
        );

        try {
            $deleted = JobLog::where('created_at', '<', $cutoff)->delete();
            $finishTime = now();
            $jobCompleteData = [
                'retention_days' => $retentionDays,
                'cutoff' => $cutoff->toDateTimeString(),
                'deleted_count' => $deleted,
                'status' => 'success',
                'timestamp' => $finishTime->toISOString(),
            ];

            Log::info("CleanUpJobLogsJob: Deleted {$deleted} job log entries older than {$retentionDays} days.");

            $this->logJob(
                'CleanUpJobLogsJob',
                'success',
                "Deleted {$deleted} job log entries older than {$retentionDays} days.",
                $jobCompleteData,
                null,
                $startTime,
                $finishTime
            );
        } catch (\Throwable $e) {
            $finishTime = now();
            $jobErrorData = [
                'retention_days' => $retentionDays,
                'cutoff' => $cutoff->toDateTimeString(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'status' => 'error',
                'timestamp' => $finishTime->toISOString(),
            ];

            Log::error('CleanUpJobLogsJob: Error during cleanup', $jobErrorData);

            $this->logJob(
                'CleanUpJobLogsJob',
                'error',
                "Error during cleanup: {$e->getMessage()}",
                $jobErrorData,
                null,
                $startTime,
                $finishTime
            );
        }
    }
}
