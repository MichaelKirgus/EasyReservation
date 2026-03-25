<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsJob;
use App\Models\ScheduledTaskExecution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class CleanUpScheduledTaskExecutionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use LogsJob;

    public function handle(): void
    {
        $retentionDays = (int) Config::get('app.scheduled_task_execution_retention_days', 30);
        $maxRows = (int) Config::get('app.scheduled_task_execution_max_rows', 5000);
        $startTime = now();

        $this->logJob(
            'CleanUpScheduledTaskExecutionsJob',
            'started',
            'Cleanup started for scheduled task execution history.',
            [
                'retention_days' => $retentionDays,
                'max_rows' => $maxRows,
            ],
            null,
            $startTime,
            null
        );

        try {
            $result = ScheduledTaskExecution::pruneHistory();
            $finishTime = now();

            Log::info('CleanUpScheduledTaskExecutionsJob: Cleanup finished', $result + [
                'retention_days' => $retentionDays,
                'max_rows' => $maxRows,
            ]);

            $this->logJob(
                'CleanUpScheduledTaskExecutionsJob',
                'success',
                'Scheduled task execution history cleanup finished.',
                $result + [
                    'retention_days' => $retentionDays,
                    'max_rows' => $maxRows,
                ],
                null,
                $startTime,
                $finishTime
            );
        } catch (\Throwable $e) {
            $finishTime = now();

            Log::error('CleanUpScheduledTaskExecutionsJob: Cleanup failed', [
                'error' => $e->getMessage(),
            ]);

            $this->logJob(
                'CleanUpScheduledTaskExecutionsJob',
                'error',
                'Scheduled task execution history cleanup failed: ' . $e->getMessage(),
                [
                    'retention_days' => $retentionDays,
                    'max_rows' => $maxRows,
                    'error' => $e->getMessage(),
                ],
                null,
                $startTime,
                $finishTime
            );
        }
    }
}