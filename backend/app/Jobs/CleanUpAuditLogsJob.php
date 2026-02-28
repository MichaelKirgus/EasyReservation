<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\JobLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class CleanUpAuditLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        // Fetch retention days from settings (to be implemented: fetch from DB/settings table or config)
        $retentionDays = Config::get('app.audit_log_retention_days', 30); // fallback default
        $cutoff = now()->subDays($retentionDays);
        $queue = $this->queue ?? ($this->onQueue ?? null);
        $workerName = config('app.worker_name');
        $startTime = now();

        $jobStartData = [
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toDateTimeString(),
        ];

        // Log job start
        JobLog::create([
            'job' => 'CleanUpAuditLogsJob',
            'queue' => $queue,
            'worker_name' => $workerName,
            'message' => "Cleanup started for audit logs older than {$retentionDays} days.",
            'status' => 'started',
            'details' => json_encode($jobStartData),
            'started_at' => $startTime,
        ]);

        try {
            $deleted = AuditLog::where('created_at', '<', $cutoff)->delete();
            $finishTime = now();
            $jobCompleteData = [
                'retention_days' => $retentionDays,
                'cutoff' => $cutoff->toDateTimeString(),
                'deleted_count' => $deleted,
                'status' => 'success',
                'timestamp' => $finishTime->toISOString(),
            ];

            Log::info("CleanUpAuditLogsJob: Deleted {$deleted} audit log entries older than {$retentionDays} days.");

            JobLog::create([
                'job' => 'CleanUpAuditLogsJob',
                'queue' => $queue,
                'worker_name' => $workerName,
                'message' => "Deleted {$deleted} audit log entries older than {$retentionDays} days.",
                'status' => 'success',
                'details' => json_encode($jobCompleteData),
                'started_at' => $startTime,
                'finished_at' => $finishTime,
            ]);
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

            Log::error('CleanUpAuditLogsJob: Error during cleanup', $jobErrorData);

            JobLog::create([
                'job' => 'CleanUpAuditLogsJob',
                'queue' => $queue,
                'worker_name' => $workerName,
                'message' => "Error during cleanup: {$e->getMessage()}",
                'status' => 'error',
                'details' => json_encode($jobErrorData),
                'started_at' => $startTime,
                'finished_at' => $finishTime,
            ]);
        }
    }
}
