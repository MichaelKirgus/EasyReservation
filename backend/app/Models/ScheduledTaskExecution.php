<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledTaskExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'scheduled_task_id',
        'action_list_id',
        'action_list_name',
        'task_type',
        'trigger_source',
        'status',
        'planned_for',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected $casts = [
        'scheduled_task_id' => 'integer',
        'action_list_id' => 'integer',
        'planned_for' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scheduledTask(): BelongsTo
    {
        return $this->belongsTo(ScheduledTask::class);
    }

    public function actionList(): BelongsTo
    {
        return $this->belongsTo(ActionList::class);
    }

    public static function pruneHistory(): array
    {
        $deletedByAge = 0;
        $deletedByCount = 0;
        $retentionDays = (int) config('app.scheduled_task_execution_retention_days', 30);
        $maxRows = (int) config('app.scheduled_task_execution_max_rows', 5000);

        if ($retentionDays > 0) {
            $cutoff = now()->subDays($retentionDays);
            $deletedByAge = static::query()->where('created_at', '<', $cutoff)->delete();
        }

        if ($maxRows > 0) {
            $overflow = static::query()->count() - $maxRows;

            if ($overflow > 0) {
                $ids = static::query()
                    ->orderBy('finished_at')
                    ->orderBy('id')
                    ->limit($overflow)
                    ->pluck('id');

                if ($ids->isNotEmpty()) {
                    $deletedByCount = static::query()->whereIn('id', $ids)->delete();
                }
            }
        }

        return [
            'deleted_by_age' => $deletedByAge,
            'deleted_by_count' => $deletedByCount,
        ];
    }
}