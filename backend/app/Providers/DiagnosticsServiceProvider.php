<?php

namespace App\Providers;

use App\Jobs\Concerns\LogsJob;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class DiagnosticsServiceProvider extends ServiceProvider
{
    use LogsJob;

    /** @var array<string,array{monotonic: float, started_at: Carbon}> */
    private array $startTimes = [];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Queue::before(function (JobProcessing $event): void {
            $this->startTimes[$this->jobKey($event->job)] = [
                'monotonic' => $this->monotonicNow(),
                'started_at' => Carbon::now(),
            ];
        });

        Queue::after(function (JobProcessed $event): void {
            $this->storeLog($event, 'processed', null);
        });

        Queue::failing(function (JobFailed $event): void {
            $this->storeLog($event, 'failed', $event->exception->getMessage());
        });
    }

    private function storeLog(object $event, string $status, ?string $message): void
    {
        $job = $event->job ?? null;

        if (! $job) {
            return;
        }

        $key = $this->jobKey($job);
        $start = $this->startTimes[$key] ?? null;
        $finishedAt = Carbon::now();
        $startedAt = $start['started_at'] ?? null;
        $runtimeMs = isset($start['monotonic']) ? $this->runtimeMs($start['monotonic']) : null;

        unset($this->startTimes[$key]);

        try {
            $this->logJob(
                method_exists($job, 'resolveName') ? $job->resolveName() : get_class($job),
                $status,
                $message ?? '',
                [],
                $runtimeMs,
                $startedAt,
                $finishedAt,
                method_exists($job, 'getQueue') ? $job->getQueue() : null
            );
        } catch (\Throwable $e) {
            // Never break the queue on diagnostics logging failures.
        }
    }

    private function jobKey(object $job): string
    {
        return method_exists($job, 'uuid') ? (string) $job->uuid() : spl_object_hash($job);
    }

    private function monotonicNow(): float
    {
        return function_exists('hrtime')
            ? hrtime(true) / 1_000_000_000
            : microtime(true);
    }

    private function runtimeMs(float $start): int
    {
        $elapsedMs = (int) round(($this->monotonicNow() - $start) * 1000);

        return $elapsedMs < 0 ? 0 : $elapsedMs;
    }
}
