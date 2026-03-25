<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsJob;
use App\Models\DataPortabilityOperation;
use App\Services\DataPortabilityBackupService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsJob;

    public function __construct(private readonly int $operationId)
    {
        $this->onQueue(config('data-portability.queue'));
    }

    public function handle(DataPortabilityBackupService $backupService): void
    {
        $startedAt = now();
        $operation = DataPortabilityOperation::query()->find($this->operationId);

        if (! $operation) {
            return;
        }

        $operation->update([
            'status' => 'running',
            'started_at' => $startedAt,
            'error_message' => null,
        ]);

        try {
            $result = $backupService->createBackupFile($operation);

            $operation->update([
                'status' => 'completed',
                'result_file_path' => $result['path'],
                'finished_at' => now(),
            ]);

            $this->logJob(
                'RunDatabaseBackupJob',
                'done',
                'Data portability backup completed.',
                [
                    'operation_id' => $operation->id,
                    'type' => $operation->type,
                    'result_file_path' => $result['path'],
                    'table_count' => $result['table_count'],
                    'row_count' => $result['row_count'],
                ],
                $startedAt->diffInMilliseconds(now()),
                Carbon::parse($startedAt),
                now(),
            );
        } catch (\Throwable $e) {
            $operation->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            $this->logJob(
                'RunDatabaseBackupJob',
                'failed',
                'Data portability backup skeleton failed.',
                [
                    'operation_id' => $operation->id,
                    'type' => $operation->type,
                    'error' => $e->getMessage(),
                ],
                $startedAt->diffInMilliseconds(now()),
                Carbon::parse($startedAt),
                now(),
            );

            throw $e;
        }
    }
}
