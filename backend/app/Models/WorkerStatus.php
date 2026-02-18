<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerStatus extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'pid'                  => 'integer',
        'memory_bytes'         => 'integer',
        'redis_latency_ms'     => 'float',
        'db_latency_ms'        => 'float',
        'total_jobs'           => 'integer',
        'last_job_at'          => 'datetime',
        'last_job_duration_ms' => 'float',
        'active_jobs'          => 'array',
        'last_heartbeat_at'    => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('workerstatus.database.table', 'worker_statuses'));
        $conn = config('workerstatus.database.connection');
        if ($conn) {
            $this->setConnection($conn);
        }
    }

    /**
     * A worker is considered online if its last heartbeat is within the TTL window.
     */
    public function getIsOnlineAttribute(): bool
    {
        if (!$this->last_heartbeat_at) {
            return false;
        }

        return $this->last_heartbeat_at->diffInSeconds(now()) < config('workerstatus.ttl', 300);
    }

    /**
     * Scope: only workers whose heartbeat is within the TTL window.
     */
    public function scopeOnline($query)
    {
        return $query->where('last_heartbeat_at', '>=', now()->subSeconds(config('workerstatus.ttl', 300)));
    }

    /**
     * Normalised representation used in API responses.
     */
    public function toApiArray(): array
    {
        return [
            'worker_id'          => $this->worker_id,
            'hostname'           => $this->hostname,
            'pid'                => $this->pid,
            'ip'                 => $this->ip,
            'memory_mb'          => round($this->memory_bytes / 1024 / 1024, 2),
            'memory_bytes'       => $this->memory_bytes,
            'redis_latency_ms'   => $this->redis_latency_ms,
            'db_latency_ms'      => $this->db_latency_ms,
            'total_jobs'         => $this->total_jobs,
            'last_job_at'        => $this->last_job_at?->toIso8601String(),
            'last_job_duration_ms' => $this->last_job_duration_ms,
            'active_jobs'        => $this->active_jobs ?? [],
            'last_heartbeat_at'  => $this->last_heartbeat_at?->toIso8601String(),
            'status'             => $this->is_online ? 'online' : 'offline',
            'updated_at'         => $this->updated_at?->toIso8601String(),
        ];
    }
}
