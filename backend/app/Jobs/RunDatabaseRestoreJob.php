<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsJob;
use App\Models\DataPortabilityOperation;
use App\Services\DataPortabilityRestoreService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunDatabaseRestoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsJob;

    public function __construct(private readonly int $operationId)
    {
        $this->onQueue(config('data-portability.queue'));
    }

    public function handle(DataPortabilityRestoreService $restoreService): void
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
            $result = $restoreService->restore($operation);
            $options = is_array($operation->options) ? $operation->options : [];
            $options['restore_result'] = $result;

            $operation->update([
                'status' => 'completed',
                'options' => $options,
                'finished_at' => now(),
            ]);

            $this->logJob(
                'RunDatabaseRestoreJob',
                'done',
                'Data portability restore skeleton completed.',
                [
                    'operation_id' => $operation->id,
                    'type' => $operation->type,
                    'restore_mode' => $operation->restore_mode,
                    'tables_restored' => $result['tables_restored'] ?? 0,
                    'rows_restored' => $result['rows_restored'] ?? 0,
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
                'RunDatabaseRestoreJob',
                'failed',
                'Data portability restore skeleton failed.',
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
