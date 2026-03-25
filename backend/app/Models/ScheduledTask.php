<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ScheduledTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'run_at',
        'cron_expression',
        'next_run_at',
        'last_run_at',
        'options',
        'executed',
        'executed_at',
        'active',
        'run_once',
        'skip_if_overdue',
        'reference_type',
        'reference_id',
        'relative_to',
        'relative_offset_minutes',
        'action_list_id',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'executed_at' => 'datetime',
        'options' => 'array',
        'executed' => 'boolean',
        'active' => 'boolean',
        'run_once' => 'boolean',
        'skip_if_overdue' => 'boolean',
        'reference_id' => 'integer',
        'relative_offset_minutes' => 'integer',
        'action_list_id' => 'integer',
    ];

    // Geplantes Ausführungsdatum berechnen (absolut oder relativ, auch für alle Objekte)
    public function getPlannedRunAtAttribute()
    {
        if ($this->run_at) {
            // ISO-Format mit Z für UTC
            return $this->run_at->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
        }
        if (!empty($this->cron_expression)) {
            // Cron-basierte Aufgabe - berechne nächste Ausführungszeit
            try {
                $cron = CronExpression::factory($this->cron_expression);
                $nextRun = $cron->getNextRunDate(now());
                return $nextRun->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            } catch (\Exception $e) {
                // Ungültiger Cron-Ausdruck
                return null;
            }
        }
        if ($this->reference_type && $this->relative_to && $this->relative_offset_minutes !== null) {
            // Einzelnes Objekt
            if ($this->reference_id) {
                $refs = [$this->getReferenceObject($this->reference_type, $this->reference_id)];
            } else {
                // Alle Objekte des Typs (bei Events nur zukünftige)
                $refs = $this->getAllReferenceObjects($this->reference_type, $this->relative_to);
            }
            $dates = [];
            foreach ($refs as $ref) {
                if ($ref && $ref->{$this->relative_to}) {
                    $base = strtotime($ref->{$this->relative_to});
                    if ($base !== false) {
                        $planned = $base + $this->relative_offset_minutes * 60;
                        // Nur zukünftige Termine
                        if ($planned >= time()) {
                            // ISO-Format mit Z für UTC
                            $dates[] = gmdate('Y-m-d\TH:i:s\Z', $planned);
                        }
                    }
                }
            }
            if ($dates) {
                sort($dates);
                return $dates[0];
            }
        }
        return null;
    }

    protected function getReferenceObject($type, $id) {
        if ($type === 'event') return \App\Models\Event::find($id);
        if ($type === 'reservation') return \App\Models\Reservation::find($id);
        if ($type === 'user') return \App\Models\User::find($id);
        return null;
    }

    protected function getAllReferenceObjects($type, $relativeTo = null) {
        if ($type === 'event') {
            // Nur Events mit zukünftigem relative_to-Datum
            $query = \App\Models\Event::query();
            if ($relativeTo) {
                $query->where($relativeTo, '>=', now());
            }
            return $query->get();
        }
        if ($type === 'reservation') return \App\Models\Reservation::all();
        if ($type === 'user') return \App\Models\User::all();
        return [];
    }

    protected $appends = ['planned_run_at'];

    /**
     * Calculate the next run time from a cron expression.
     * Returns the next datetime when the task should run based on the cron pattern.
     */
    public function getNextRunAtAttribute()
    {
        if (!$this->cron_expression) {
            return null;
        }

        try {
            // Use dragonmantank/cron-expression to parse and calculate next run
            $cron = CronExpression::factory($this->cron_expression);
            
            // Get the next run time from now (or from last_run_at if available)
            $baseTime = $this->last_run_at ? $this->last_run_at : now();
            
            return Carbon::instance($cron->getNextRunDate($baseTime))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            \Log::warning('Invalid cron expression for task ' . $this->id . ': ' . $this->cron_expression);
            return null;
        }
    }

    /**
     * Check if a cron expression is valid.
     */
    public static function isValidCron(string $expression): bool
    {
        // Basic validation: must have exactly 5 space-separated fields
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            return false;
        }

        // Validate each field contains only valid cron characters
        foreach ($parts as $part) {
            if (!preg_match('/^[\*0-9,\-\/]+$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get human-readable description of cron expression.
     */
    public static function getCronDescription(string $expression): string
    {
        // Return the expression itself as a simple description
        return $expression;
    }
    /**
     * Get the action list associated with this task.
     */
    public function actionList()
    {
        return $this->belongsTo(\App\Models\ActionList::class, 'action_list_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(\App\Models\ScheduledTaskExecution::class);
    }
}
