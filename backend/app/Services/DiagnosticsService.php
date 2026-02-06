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
        // NEU: Wert aus Cache lesen
        $lastExecutedAtCache = \Cache::get('scheduler:last_executed_at');
        $lastExecutedAt = $lastExecutedAtCache ? (is_string($lastExecutedAtCache) ? \Carbon\Carbon::parse($lastExecutedAtCache) : $lastExecutedAtCache) : $lastExecuted?->executed_at;
        if ($lastExecutedAt) {
            $diff = now()->diffInMinutes($lastExecutedAt);
            $schedulerActive = $diff < 10; // z.B. aktiv, wenn <10min her
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
        $workers = [];

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
                // Worker-Status-Keys finden und Daten auslesen
                // Redis Prefix berücksichtigen - use cache connection to match worker storage
                $redis = app('redis')->connection('cache');
                $prefix = '';
                if (method_exists($redis, 'getOptions')) {
                    $options = $redis->getOptions();
                    if ($options && isset($options['prefix'])) {
                        $prefix = $options['prefix'];
                    }
                } elseif (property_exists($redis, 'options') && isset($redis->options['prefix'])) {
                    $prefix = $redis->options['prefix'];
                }
                
                // Select the correct database for worker status (as configured)
                $workerDb = config('workerstatus.redis_db', 1);
                if (method_exists($redis, 'select')) {
                    try {
                        \Illuminate\Support\Facades\Log::debug('Selecting Redis DB: ' . $workerDb);
                        $redis->select($workerDb);
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error('Could not select Redis DB for worker status: ' . $e->getMessage());
                    }
                }
                // Try to get worker keys with proper prefix handling
                \Illuminate\Support\Facades\Log::debug('Attempting to find worker keys with prefix: ' . $prefix);
                
                // Debug: Let's also try a broader search to see what's in Redis
                \Illuminate\Support\Facades\Log::debug('Checking all keys in Redis for debugging...');
                try {
                    $allKeys = $redis->keys('*');
                    \Illuminate\Support\Facades\Log::debug('Found ' . count($allKeys) . ' total keys in Redis');
                    foreach (array_slice($allKeys, 0, 20) as $key) { // Show first 20 keys
                        \Illuminate\Support\Facades\Log::debug('Redis key: ' . $key);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Error getting all Redis keys: ' . $e->getMessage());
                }
                
                $workerKeys = [];
                // Try multiple patterns including both worker_status and worker_stats formats
                $patterns = [
                    'worker_status:*',
                    $prefix . 'worker_status:*',
                    'worker_stats:*',  // Also look for stats keys (might be what's actually stored)
                    $prefix . 'worker_stats:*'
                ];
                
                foreach ($patterns as $pattern) {
                    \Illuminate\Support\Facades\Log::debug('Searching for Redis keys with pattern: ' . $pattern);
                    try {
                        $foundKeys = $redis->keys($pattern);
                        \Illuminate\Support\Facades\Log::debug('Found ' . count($foundKeys) . ' keys with pattern: ' . $pattern);
                        if (!empty($foundKeys)) {
                            $workerKeys = array_merge($workerKeys, $foundKeys);
                            \Illuminate\Support\Facades\Log::debug('Using pattern: ' . $pattern . ' - found ' . count($foundKeys) . ' keys');
                            break; // Found keys with one pattern, no need to try others
                        }
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error('Error searching for pattern ' . $pattern . ': ' . $e->getMessage());
                    }
                }
                
                // Debug: Log all found keys and their content
                \Illuminate\Support\Facades\Log::debug('Total worker keys found: ' . count($workerKeys));
                foreach ($workerKeys as $key) {
                    \Illuminate\Support\Facades\Log::debug('Worker key: ' . $key);
                    try {
                        $data = $redis->get($key);
                        \Illuminate\Support\Facades\Log::debug('Data for key ' . $key . ': ' . ($data ? substr($data, 0, 100) . '...' : 'No data'));
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error('Error getting data for key ' . $key . ': ' . $e->getMessage());
                    }
                }
                $workerCount = is_array($workerKeys) ? count($workerKeys) : 0;
                foreach ($workerKeys as $key) {
                    $data = $redis->get($key);
                    if ($data) {
                        $decoded = json_decode($data, true);
                        if (is_array($decoded)) {
                            $decoded['redis_key'] = $key;
                            $workers[] = $decoded;
                        }
                    }
                }
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
