<?php

namespace App\Jobs;

use App\Jobs\Concerns\LogsJob;
use App\Models\DataPortabilityOperation;
use App\Services\DataPortabilityTransportService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunDatabaseTransportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, LogsJob;

    public function __construct(private readonly int $operationId)
    {
        $this->onQueue(config('data-portability.queue'));
    }

    public function handle(DataPortabilityTransportService $transportService): void
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
            $result = $transportService->transport($operation);
            $options = is_array($operation->options) ? $operation->options : [];
            $options['transport_result'] = $result;

            $operation->update([
                'status' => 'completed',
                'options' => $options,
                'finished_at' => now(),
            ]);

            $this->logJob(
                'RunDatabaseTransportJob',
                'done',
                'Data portability transport completed.',
                [
                    'operation_id' => $operation->id,
                    'type' => $operation->type,
                    'target_operation_id' => $result['target_operation_id'] ?? null,
                    'tables_sent' => $result['tables_sent'] ?? 0,
                    'rows_sent' => $result['rows_sent'] ?? 0,
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
                'RunDatabaseTransportJob',
                'failed',
                'Data portability transport skeleton failed.',
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
