<?php

namespace App\Services;

use App\Models\JobLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class DiagnosticsService
{
    public function snapshot(int $jobLimit = 25): array
    {
        // Scheduler-Status
        $lastExecuted = \App\Models\ScheduledTask::whereNotNull('executed_at')->orderByDesc('executed_at')->first();
        $nextRun = \App\Models\ScheduledTask::where('executed', false)->where('active', true)->whereNotNull('run_at')->orderBy('run_at')->first();
        $schedulerActive = false;
        // NEU: Wert aus Cache lesen
        $lastExecutedAtCache = \Cache::get('scheduler:last_executed_at');
        $lastExecutedAt = $lastExecutedAtCache ? (is_string($lastExecutedAtCache) ? \Carbon\Carbon::parse($lastExecutedAtCache) : $lastExecutedAtCache) : $lastExecuted?->executed_at;
        if ($lastExecutedAt) {
            $diff = now()->diffInMinutes($lastExecutedAt);
            $schedulerActive = $diff < 10; // e.g., active if less than 10 minutes ago
        }
        return [
            'server_time' => now()->toIso8601String(),
            'server_timezone' => config('app.timezone', date_default_timezone_get()),
            'timestamp' => now()->toIso8601String(),
            'app' => [
                'name' => config('app.name'),
                'environment' => config('app.env'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'queue_connection' => config('queue.default'),
                'cache_store' => config('cache.default'),
                'app_version' => env('APP_VERSION', 'unbekannt'),
            ],
            'latency' => [
                'mysql' => $this->measure(fn () => DB::select('select 1')),
                'redis' => $this->measure(fn () => Redis::ping()),
            ],
            'queue' => $this->queueInfo($jobLimit),
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

    private function queueInfo(int $jobLimit = 25): array
    {
        $recent = [];
        $error = null;
        $processingJobs = 0;
        $workerCount = 0;
        $workers = [];

        try {
            $recent = JobLog::query()
                ->latest('finished_at')
                ->latest('id')
                ->limit($jobLimit)
                ->get(['id', 'job', 'queue', 'worker_name', 'status', 'runtime_ms', 'message', 'started_at', 'finished_at'])
                ->map(function (JobLog $log) {
                    return [
                        'id' => $log->id,
                        'job' => $log->job,
                        'queue' => $log->queue,
                        'worker_name' => $log->worker_name,
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
                $workerService = app(\App\Services\WorkerStatusService::class);
                $workers = $workerService->getAllStatuses();
                $workerCount = count($workers);
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
            'workers' => $workers,
        ];
    }

    public function redisKeys(): array
    {
        $connections = [
            'default' => [
                'name' => 'default',
                'db' => config('database.redis.default.database', 0),
            ],
            'cache' => [
                'name' => 'cache',
                'db' => config('database.redis.cache.database', 1),
            ],
            'queue' => [
                'name' => 'queue',
                'db' => config('database.redis.queue.database', 2),
            ],
        ];
        // Session-Store prüfen
        $sessionDriver = config('session.driver');
        if ($sessionDriver === 'redis') {
            $sessionConn = config('session.connection', 'default');
            $sessionDb = config('database.redis.' . $sessionConn . '.database', 0);
            $connections['session'] = [
                'name' => $sessionConn,
                'db' => $sessionDb,
            ];
        }

        $result = [];
        foreach ($connections as $key => $conn) {
            $keys = [];
            $error = null;
            try {
                $redis = \Illuminate\Support\Facades\Redis::connection($conn['name']);
                // DB explizit auswählen (nur relevant für phpredis, aber schadet nicht)
                if (method_exists($redis, 'client')) {
                    $redis->client()->select($conn['db']);
                } else {
                    $redis->select($conn['db']);
                }
                $rawKeys = $redis->keys('*');
                foreach ($rawKeys as $keyName) {
                    $type = $redis->type($keyName);
                    $len = null;
                    if ($type === 'list') {
                        $len = $redis->llen($keyName);
                    } elseif ($type === 'set') {
                        $len = $redis->scard($keyName);
                    } elseif ($type === 'zset') {
                        $len = $redis->zcard($keyName);
                    } elseif ($type === 'hash') {
                        $len = $redis->hlen($keyName);
                    }
                    $keys[] = [
                        'key' => $keyName,
                        'type' => $type,
                        'length' => $len,
                    ];
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $result[] = [
                'connection' => $conn['name'],
                'db' => $conn['db'],
                'keys' => $keys,
                'error' => $error,
            ];
        }
        return $result;
    }
}
