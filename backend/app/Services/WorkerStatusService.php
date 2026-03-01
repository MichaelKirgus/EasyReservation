<?php

namespace App\Services;

use App\Models\WorkerStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class WorkerStatusService
{
    protected string $driver;
    protected int    $ttl;

    /* ── Redis-specific ── */
    protected string $redisConnection;
    protected string $redisPrefix;

    public function __construct()
    {
        $this->driver          = config('workerstatus.driver', 'database');
        $this->ttl             = (int) config('workerstatus.ttl', 300);
        $this->redisConnection = config('workerstatus.redis.connection', 'default');
        $this->redisPrefix     = config('workerstatus.redis.prefix', 'worker:');
    }

    /* ================================================================
     *  WRITE: heartbeat / status
     * ============================================================= */

    /**
     * Store or update a worker heartbeat.
     *
     * $data must include at least: ip, hostname, pid, memory_bytes.
     * Optional: redis_latency_ms, db_latency_ms, active_jobs.
     */
    public function heartbeat(string $workerId, array $data): void
    {
        $data['last_heartbeat_at'] = now();

        // Only include total_jobs if explicitly set (for overwrite)
        if (array_key_exists('total_jobs', $data) && $data['total_jobs'] === null) {
            unset($data['total_jobs']);
        }

        try {
            if ($this->driver === 'redis') {
                $this->redisHeartbeat($workerId, $data);
            } else {
                $this->databaseHeartbeat($workerId, $data);
            }
        } catch (\Throwable $e) {
            Log::error('[WorkerStatus] Failed to store heartbeat', [
                'worker' => $workerId,
                'driver' => $this->driver,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /* ================================================================
     *  WRITE: job stats (called after a job finishes)
     * ============================================================= */

    public function recordJobCompleted(string $workerId, ?float $durationMs = null): void
    {
        try {
            if ($this->driver === 'redis') {
                $this->redisRecordJob($workerId, $durationMs);
            } else {
                $this->databaseRecordJob($workerId, $durationMs);
            }
        } catch (\Throwable $e) {
            Log::error('[WorkerStatus] Failed to record job completion', [
                'worker' => $workerId,
                'driver' => $this->driver,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /* ================================================================
     *  READ
     * ============================================================= */

    /**
     * Return all workers, optionally only those still online.
     */
    public function getAllStatuses(bool $onlineOnly = false): array
    {
        try {
            if ($this->driver === 'redis') {
                return $this->redisGetAll($onlineOnly);
            }

            return $this->databaseGetAll($onlineOnly);
        } catch (\Throwable $e) {
            Log::error('[WorkerStatus] Failed to read statuses', [
                'driver' => $this->driver,
                'error'  => $e->getMessage(),
            ]);
            return [];
        }
    }

    /* ================================================================
     *  CLEANUP
     * ============================================================= */

    /**
     * Remove stale entries older than cleanup_after seconds.
     * (Redis keys auto-expire, so this only matters for the DB driver.)
     */
    public function cleanup(): int
    {
        if ($this->driver !== 'database') {
            return 0;
        }

        $cutoff = now()->subSeconds((int) config('workerstatus.cleanup_after', 86400));
        return WorkerStatus::where('last_heartbeat_at', '<', $cutoff)->delete();
    }

    /* ================================================================
     *  DATABASE driver
     * ============================================================= */

    private function databaseHeartbeat(string $workerId, array $data): void
    {
        WorkerStatus::updateOrCreate(
            ['worker_id' => $workerId],
            [
                'hostname'         => $data['hostname'] ?? null,
                'pid'              => $data['pid'] ?? null,
                'ip'               => $data['ip'] ?? null,
                'memory_bytes'     => $data['memory_bytes'] ?? 0,
                'redis_latency_ms' => $data['redis_latency_ms'] ?? null,
                'db_latency_ms'    => $data['db_latency_ms'] ?? null,
                'active_jobs'      => $data['active_jobs'] ?? [],
                'last_heartbeat_at'=> $data['last_heartbeat_at'],
            ]
        );
    }

    private function databaseRecordJob(string $workerId, ?float $durationMs): void
    {
        $worker = WorkerStatus::firstOrCreate(
            ['worker_id' => $workerId],
            ['last_heartbeat_at' => now()]
        );

        $worker->increment('total_jobs');
        $worker->update([
            'last_job_at'          => now(),
            'last_job_duration_ms' => $durationMs,
        ]);
    }

    private function databaseGetAll(bool $onlineOnly): array
    {
        $query = WorkerStatus::query()->orderByDesc('last_heartbeat_at');

        if ($onlineOnly) {
            $query->online();
        }

        return $query->get()->map->toApiArray()->all();
    }

    /* ================================================================
     *  REDIS driver  (uses Redis facade directly — no Cache prefix issues)
     * ============================================================= */

    private function redis()
    {
        return Redis::connection($this->redisConnection);
    }

    private function redisKey(string $workerId): string
    {
        return $this->redisPrefix . $workerId;
    }

    private function redisHeartbeat(string $workerId, array $data): void
    {
        $key      = $this->redisKey($workerId);
        $existing = $this->redisGetWorker($workerId);

        // Merge with existing stats so they aren't lost on heartbeat
        $merged = array_merge([
            'worker_id'          => $workerId,
            'hostname'           => null,
            'pid'                => null,
            'ip'                 => null,
            'memory_bytes'       => 0,
            'redis_latency_ms'   => null,
            'db_latency_ms'      => null,
            'total_jobs'         => 0,
            'last_job_at'        => null,
            'last_job_duration_ms' => null,
            'active_jobs'        => [],
        ], $existing ?: [], $data, ['last_heartbeat_at' => now()->toIso8601String()]);

        $this->redis()->setex($key, $this->ttl, json_encode($merged));
    }

    private function redisRecordJob(string $workerId, ?float $durationMs): void
    {
        $key      = $this->redisKey($workerId);
        $existing = $this->redisGetWorker($workerId);

        if (!$existing) {
            $existing = [
                'worker_id'    => $workerId,
                'total_jobs'   => 0,
                'last_job_at'  => null,
                'last_job_duration_ms' => null,
            ];
        }

        $existing['total_jobs']          = ($existing['total_jobs'] ?? 0) + 1;
        $existing['last_job_at']         = now()->toIso8601String();
        $existing['last_job_duration_ms'] = $durationMs;

        $this->redis()->setex($key, $this->ttl, json_encode($existing));
    }

    private function redisGetWorker(string $workerId): ?array
    {
        $raw = $this->redis()->get($this->redisKey($workerId));
        return $raw ? json_decode($raw, true) : null;
    }

    private function redisGetAll(bool $onlineOnly): array
    {
        // Use SCAN instead of KEYS for production safety
        $pattern = $this->redisPrefix . '*';
        $conn    = $this->redis();

        // Collect all matching keys via SCAN (works with both predis & phpredis)
        $keys = [];
        $cursor = '0';
        do {
            [$cursor, $results] = $conn->scan($cursor, ['match' => $pattern, 'count' => 100]);
            if ($results) {
                $keys = array_merge($keys, $results);
            }
        } while ($cursor && $cursor !== '0' && $cursor !== 0);

        \Log::debug('[WorkerStatus] Redis keys found for pattern', ['pattern' => $pattern, 'keys' => $keys]);

        $workerMap = [];
        foreach ($keys as $key) {
            $raw = $conn->get($key);
            if (!$raw) {
                \Log::warning('[WorkerStatus] Redis key has no value (null)', ['key' => $key]);
                continue;
            }
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                \Log::warning('[WorkerStatus] Redis key has invalid JSON or is not an array', ['key' => $key, 'raw' => $raw, 'json_error' => json_last_error_msg()]);
                continue;
            }

            // Extract worker base ID (everything before last colon)
            $workerBaseId = null;
            if (isset($data['worker_id'])) {
                $parts = explode(':', $data['worker_id']);
                array_pop($parts); // remove last part (pid or unique)
                $workerBaseId = implode(':', $parts);
            } else {
                // fallback: use key
                $parts = explode(':', $key);
                array_pop($parts);
                $workerBaseId = implode(':', $parts);
            }

            // Derive online status
            $heartbeat = isset($data['last_heartbeat_at'])
                ? \Carbon\Carbon::parse($data['last_heartbeat_at'])
                : null;
            $isOnline = $heartbeat && $heartbeat->diffInSeconds(now()) < $this->ttl;

            if ($onlineOnly && !$isOnline) {
                continue;
            }

            // Build normalised output (same shape as database driver)
            $workerEntry = [
                // Show only the base worker name (before last colon)
                'worker_id'          => $workerBaseId,
                'hostname'           => $data['hostname'] ?? null,
                'pid'                => $data['pid'] ?? null,
                'ip'                 => $data['ip'] ?? null,
                'memory_mb'          => isset($data['memory_bytes'])
                    ? round($data['memory_bytes'] / 1024 / 1024, 2)
                    : null,
                'memory_bytes'       => $data['memory_bytes'] ?? 0,
                'redis_latency_ms'   => $data['redis_latency_ms'] ?? null,
                'db_latency_ms'      => $data['db_latency_ms'] ?? null,
                'total_jobs'         => $data['total_jobs'] ?? 0,
                'last_job_at'        => $data['last_job_at'] ?? null,
                'last_job_duration_ms' => $data['last_job_duration_ms'] ?? null,
                'active_jobs'        => $data['active_jobs'] ?? [],
                'last_heartbeat_at'  => $data['last_heartbeat_at'] ?? null,
                'status'             => $isOnline ? 'online' : 'offline',
                'updated_at'         => $data['last_heartbeat_at'] ?? null,
            ];

            // Aggregate multiple PIDs for the same base worker name instead of overwriting newer heartbeats that may have 0 jobs.
            if (!isset($workerMap[$workerBaseId])) {
                // Initialize aggregate structure
                $workerMap[$workerBaseId] = $workerEntry;
            } else {
                $agg = &$workerMap[$workerBaseId];

                // Sum total jobs across PIDs
                $agg['total_jobs'] = (int) ($agg['total_jobs'] ?? 0) + (int) ($workerEntry['total_jobs'] ?? 0);

                // Track latest job info
                $currentJobAt  = $agg['last_job_at'] ?? null;
                $incomingJobAt = $workerEntry['last_job_at'] ?? null;
                if ($incomingJobAt && (!$currentJobAt || $incomingJobAt > $currentJobAt)) {
                    $agg['last_job_at']         = $incomingJobAt;
                    $agg['last_job_duration_ms'] = $workerEntry['last_job_duration_ms'] ?? null;
                }

                // Track latest heartbeat for status/memory/latency/ip
                $currentHb  = $agg['last_heartbeat_at'] ?? null;
                $incomingHb = $workerEntry['last_heartbeat_at'] ?? null;
                if ($incomingHb && (!$currentHb || $incomingHb > $currentHb)) {
                    $agg['last_heartbeat_at'] = $incomingHb;
                    $agg['memory_mb']         = $workerEntry['memory_mb'] ?? $agg['memory_mb'];
                    $agg['memory_bytes']      = $workerEntry['memory_bytes'] ?? $agg['memory_bytes'];
                    $agg['redis_latency_ms']  = $workerEntry['redis_latency_ms'] ?? $agg['redis_latency_ms'];
                    $agg['db_latency_ms']     = $workerEntry['db_latency_ms'] ?? $agg['db_latency_ms'];
                    $agg['ip']                = $workerEntry['ip'] ?? $agg['ip'];
                    $agg['pid']               = $workerEntry['pid'] ?? $agg['pid'];
                }

                // Merge active jobs arrays
                $agg['active_jobs'] = array_merge($agg['active_jobs'] ?? [], $workerEntry['active_jobs'] ?? []);

                // Online if any PID is online
                $agg['status'] = ($agg['status'] === 'online' || $workerEntry['status'] === 'online') ? 'online' : 'offline';

                unset($agg);
            }
        }

        // Sort by last heartbeat descending (most recent first)
        $workers = array_values($workerMap);
        usort($workers, fn ($a, $b) => strcmp($b['last_heartbeat_at'] ?? '', $a['last_heartbeat_at'] ?? ''));

        \Log::debug('[WorkerStatus] Final deduplicated workers array', ['workers' => $workers]);

        return $workers;
    }
}
