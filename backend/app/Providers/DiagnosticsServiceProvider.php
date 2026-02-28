<?php

namespace App\Providers;

use App\Models\JobLog;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class DiagnosticsServiceProvider extends ServiceProvider
{
    /** @var array<string,float> */
    private array $startTimes = [];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Queue::before(function (JobProcessing $event): void {
            $this->startTimes[$this->jobKey($event->job)] = microtime(true);
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
        $startedAt = $start ? Carbon::createFromTimestamp($start) : null;
        $runtimeMs = $start ? (int) round((microtime(true) - $start) * 1000) : null;

        unset($this->startTimes[$key]);

        try {
            JobLog::create([
                'job' => method_exists($job, 'resolveName') ? $job->resolveName() : get_class($job),
                'queue' => method_exists($job, 'getQueue') ? $job->getQueue() : null,
                'worker_name' => env('WORKER_NAME'),
                'status' => $status,
                'runtime_ms' => $runtimeMs,
                'message' => $message,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);
        } catch (\Throwable $e) {
            // Never break the queue on diagnostics logging failures.
        }
    }

    private function jobKey(object $job): string
    {
        return method_exists($job, 'uuid') ? (string) $job->uuid() : spl_object_hash($job);
    }
}
