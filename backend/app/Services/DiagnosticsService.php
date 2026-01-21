<?php

namespace App\Services;

use App\Models\JobLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class DiagnosticsService
{
    public function snapshot(): array
    {
        // Scheduler-Status
        $lastExecuted = \App\Models\ScheduledTask::whereNotNull('executed_at')->orderByDesc('executed_at')->first();
        $nextRun = \App\Models\ScheduledTask::where('executed', false)->where('active', true)->whereNotNull('run_at')->orderBy('run_at')->first();
        $schedulerActive = false;
        $lastExecutedAt = $lastExecuted?->executed_at;
        if ($lastExecutedAt) {
            $diff = now()->diffInMinutes($lastExecutedAt);
            $schedulerActive = $diff < 10; // z.B. aktiv, wenn <10min her
        }
        return [
            'timestamp' => now()->toIso8601String(),
            'app' => [
                'name' => config('app.name'),
                'environment' => config('app.env'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'queue_connection' => config('queue.default'),
                'cache_store' => config('cache.default'),
            ],
            'latency' => [
                'mysql' => $this->measure(fn () => DB::select('select 1')),
                'redis' => $this->measure(fn () => Redis::ping()),
            ],
            'queue' => $this->queueInfo(),
            'scheduler' => [
                'last_executed_at' => $lastExecutedAt?->toIso8601String(),
                'next_run_at' => $nextRun?->run_at?->toIso8601String(),
                'active' => $schedulerActive,
            ],
        ];
    }

    private function measure(callable $callback): array
    {
        $started = microtime(true);
        $status = 'ok';
        $error = null;

        try {
            $callback();
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        return [
            'status' => $status,
            'latency_ms' => $latency,
            'error' => $error,
        ];
    }

    private function queueInfo(): array
    {
        $recent = [];
        $error = null;
        $processingJobs = 0;
        $workerCount = 0;

        try {
            $recent = JobLog::query()
                ->latest('finished_at')
                ->latest('id')
                ->limit(25)
                ->get(['id', 'job', 'queue', 'status', 'runtime_ms', 'message', 'started_at', 'finished_at'])
                ->map(function (JobLog $log) {
                    return [
                        'id' => $log->id,
                        'job' => $log->job,
                        'queue' => $log->queue,
                        'status' => $log->status,
                        'runtime_ms' => $log->runtime_ms,
                        'message' => $log->message,
                        'started_at' => optional($log->started_at)->toIso8601String(),
                        'finished_at' => optional($log->finished_at)->toIso8601String(),
                    ];
                })
                ->all();
            $processingJobs = JobLog::where('status', 'processing')->count();
            if (config('queue.default') === 'redis') {
                $workerKeys = \Illuminate\Support\Facades\Redis::keys('queues:workers*');
                $workerCount = is_array($workerKeys) ? count($workerKeys) : 0;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return [
            'connection' => config('queue.default'),
            'recent' => $recent,
            'error' => $error,
            'processing_jobs' => $processingJobs,
            'worker_count' => $workerCount,
        ];
    }

    public function redisKeys(): array
    {
        $keys = [];
        $error = null;
        try {
            if (config('queue.default') === 'redis') {
                $rawKeys = \Illuminate\Support\Facades\Redis::keys('*');
                foreach ($rawKeys as $key) {
                    $type = \Illuminate\Support\Facades\Redis::type($key);
                    $len = null;
                    if ($type === 'list') {
                        $len = \Illuminate\Support\Facades\Redis::llen($key);
                    } elseif ($type === 'set') {
                        $len = \Illuminate\Support\Facades\Redis::scard($key);
                    } elseif ($type === 'zset') {
                        $len = \Illuminate\Support\Facades\Redis::zcard($key);
                    } elseif ($type === 'hash') {
                        $len = \Illuminate\Support\Facades\Redis::hlen($key);
                    }
                    $keys[] = [
                        'key' => $key,
                        'type' => $type,
                        'length' => $len,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        return [
            'keys' => $keys,
            'error' => $error,
        ];
    }
}
