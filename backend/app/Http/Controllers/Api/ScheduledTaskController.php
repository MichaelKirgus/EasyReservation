<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ScheduledTask;
use Illuminate\Support\Carbon;
use App\Services\ScheduledTaskService;

class ScheduledTaskController extends Controller
{
    // GET /admin/scheduled-tasks
    public function index()
    {
        $tasks = ScheduledTask::orderBy('run_at', 'asc')->get();
        // next_run_at: das nächste geplante Ausführungsdatum (absolut oder relativ)
        $nextPlanned = $tasks->filter(function($t) {
            return !$t->executed && $t->active && $t->planned_run_at;
        })->sortBy('planned_run_at')->first();
        return response()->json([
            'tasks' => $tasks,
            'next_run_at' => $nextPlanned ? $nextPlanned->planned_run_at : null,
        ]);
    }

    // POST /admin/scheduled-tasks
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => 'nullable|string',
            'run_at' => 'nullable|date',
            'cron_expression' => 'nullable|string|min:5|max:30',
            'options' => 'nullable|array',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'relative_to' => 'nullable|string',
            'relative_offset_minutes' => 'nullable|integer',
            'active' => 'boolean',
            'run_once' => 'boolean',
            'skip_if_overdue' => 'boolean',
            'action_list_id' => 'required|integer|exists:action_lists,id',
        ]);

        // Scheduled tasks execute action lists only.
        $data['type'] = 'action_list';
        $data['options'] = array_merge($data['options'] ?? [], [
            'action_list_id' => $data['action_list_id'],
        ]);
        
        // Validation: Either run_at, cron_expression, or (relative_to + relative_offset_minutes) must be set
        if (empty($data['run_at']) && empty($data['cron_expression']) && (empty($data['relative_to']) || $data['relative_offset_minutes'] === null)) {
            return response()->json(['message' => __('scheduled_task_either_run_at_or_relative')], 422);
        }
        
        // Validate cron expression if provided
        if (!empty($data['cron_expression'])) {
            if (!ScheduledTask::isValidCron($data['cron_expression'])) {
                return response()->json(['message' => __('scheduled_task_invalid_cron')], 422);
            }
            
            // Calculate next_run_at from cron expression
            $task = new ScheduledTask();
            $task->fill($data);
            $data['next_run_at'] = $task->next_run_at;
        }
        
        $task = ScheduledTask::create($data);
        return response()->json($task, 201);
    }

    // PUT /admin/scheduled-tasks/{id}
    public function update(Request $request, $id)
    {
        $task = ScheduledTask::findOrFail($id);
        $original = $task->replicate();
        $data = $request->validate([
            'type' => 'nullable|string',
            'run_at' => 'nullable|date',
            'cron_expression' => 'nullable|string|regex:/^(\*|[0-9*,\/\-]+)\s+(\*|[0-9*,\/\-]+)\s+(\*|[0-9*,\/\-]+)\s+(\*|[0-9*,\/\-]+)\s+(\*|[0-9*,\/\-]+)$/',
            'options' => 'nullable|array',
            'executed' => 'boolean',
            'executed_at' => 'nullable|date',
            'active' => 'boolean',
            'run_once' => 'boolean',
            'skip_if_overdue' => 'boolean',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'relative_to' => 'nullable|string',
            'relative_offset_minutes' => 'nullable|integer',
            'action_list_id' => 'required|integer|exists:action_lists,id',
        ]);

        // Scheduled tasks execute action lists only.
        $data['type'] = 'action_list';
        $data['options'] = array_merge($data['options'] ?? [], [
            'action_list_id' => $data['action_list_id'],
        ]);
        
        // Validate cron expression if provided
        if (!empty($data['cron_expression'])) {
            if (!ScheduledTask::isValidCron($data['cron_expression'])) {
                return response()->json(['message' => __('scheduled_task_invalid_cron')], 422);
            }
            
            // Calculate next_run_at from cron expression
            $tempTask = new ScheduledTask();
            $tempTask->fill($data);
            $data['next_run_at'] = $tempTask->next_run_at;
        }

        // If scheduling parameters changed, make the task runnable again.
        $incomingRunAt = array_key_exists('run_at', $data) && !empty($data['run_at'])
            ? Carbon::parse($data['run_at'])->utc()->toIso8601String()
            : null;
        $originalRunAt = $original->run_at ? $original->run_at->copy()->utc()->toIso8601String() : null;

        $rescheduled = (
            array_key_exists('run_at', $data) && ($incomingRunAt !== $originalRunAt)
        ) || (
            array_key_exists('cron_expression', $data) && (($data['cron_expression'] ?? null) !== $original->cron_expression)
        ) || (
            array_key_exists('reference_type', $data) && (($data['reference_type'] ?? null) !== $original->reference_type)
        ) || (
            array_key_exists('reference_id', $data) && (($data['reference_id'] ?? null) != $original->reference_id)
        ) || (
            array_key_exists('relative_to', $data) && (($data['relative_to'] ?? null) !== $original->relative_to)
        ) || (
            array_key_exists('relative_offset_minutes', $data) && (($data['relative_offset_minutes'] ?? null) != $original->relative_offset_minutes)
        );

        if ($rescheduled) {
            $data['executed'] = false;
            $data['executed_at'] = null;
        }
        
        $task->update($data);
        return response()->json($task);
    }

    // PATCH /admin/scheduled-tasks/{id}/activate
    public function activate($id)
    {
        $task = ScheduledTask::findOrFail($id);
        $task->active = true;
        $task->save();
        return response()->json($task);
    }

    // PATCH /admin/scheduled-tasks/{id}/deactivate
    public function deactivate($id)
    {
        $task = ScheduledTask::findOrFail($id);
        $task->active = false;
        $task->save();
        return response()->json($task);
    }

    // POST /admin/scheduled-tasks/{id}/run-now
    public function runNow($id)
    {
        \Log::info('ScheduledTaskController: runNow called for task ID ' . $id);
        
        $task = ScheduledTask::findOrFail($id);
        
        try {
            // Unabhängig vom Status: Job für die Ausführung erzeugen und in die Queue stellen
            $service = app(ScheduledTaskService::class);
            
            \Log::info('ScheduledTaskController: Dispatching ExecuteScheduledTaskJob for task ' . $task->id . ' (manual run)');
            $service->queueTaskExecution($task, true);
            
            return response()->json([
                'success' => true,
                'message' => 'Task execution started',
                'task' => $task
            ]);
        } catch (\Throwable $e) {
            \Log::error('ScheduledTaskController: Error running task ' . $id . ': ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    // DELETE /admin/scheduled-tasks/{id}
    public function destroy($id)
    {
        $task = ScheduledTask::findOrFail($id);
        $task->delete();
        return response()->noContent();
    }

    // POST /admin/cron/next-run
    public function getNextCronRun(Request $request)
    {
        $data = $request->validate([
            'expression' => 'required|string|min:5|max:30',
        ]);

        try {
            $nextRuns = $this->calculateNextCronRuns($data['expression'], 5);
            
            return response()->json([
                'expression' => $data['expression'],
                'next_runs' => $nextRuns,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid cron expression: ' . $e->getMessage()], 422);
        }
    }

    /**
     * Calculate next run times for a cron expression.
     * Implements basic cron parsing without external dependencies.
     */
    private function calculateNextCronRuns(string $expression, int $count = 5): array
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException('Invalid cron expression: must have exactly 5 fields');
        }

        list($minuteExpr, $hourExpr, $dayExpr, $monthExpr, $weekdayExpr) = $parts;

        $results = [];
        $currentTime = time();
        
        // Start from the next minute
        $currentTime += 60 - ($currentTime % 60);
        
        for ($i = 0; $i < $count; $i++) {
            $found = false;
            $maxAttempts = 525600; // Max 1 year of minutes to search
            
            for ($attempt = 0; $attempt < $maxAttempts && !$found; $attempt++) {
                $timestamp = $currentTime + ($attempt * 60);
                $minute = (int)date('i', $timestamp);
                $hour = (int)date('H', $timestamp);
                $day = (int)date('j', $timestamp);
                $month = (int)date('n', $timestamp);
                $weekday = (int)date('w', $timestamp); // 0 = Sunday

                if ($this->matchCronField($minuteExpr, $minute, 0, 59) &&
                    $this->matchCronField($hourExpr, $hour, 0, 23) &&
                    $this->matchCronField($dayExpr, $day, 1, 31) &&
                    $this->matchCronField($monthExpr, $month, 1, 12) &&
                    $this->matchCronField($weekdayExpr, $weekday, 0, 6)) {
                    
                    $results[] = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
                    $currentTime += ($attempt + 1) * 60;
                    $found = true;
                }
            }

            if (!$found) {
                break;
            }
        }

        return $results;
    }

    /**
     * Check if a value matches a cron field expression.
     */
    private function matchCronField(string $expression, int $value, int $min, int $max): bool
    {
        // Handle wildcard
        if ($expression === '*') {
            return true;
        }

        // Split by comma for multiple values
        $parts = explode(',', $expression);
        
        foreach ($parts as $part) {
            // Handle step values (e.g., */5)
            if (str_starts_with($part, '*/')) {
                $step = (int)substr($part, 2);
                if ($step > 0 && ($value - $min) % $step === 0) {
                    return true;
                }
                continue;
            }

            // Handle range with step (e.g., 1-10/2)
            if (str_contains($part, '/')) {
                list($range, $step) = explode('/', $part);
                $step = (int)$step;
                
                if ($step <= 0) continue;
                
                if (str_contains($range, '-')) {
                    list($start, $end) = explode('-', $range);
                    $start = (int)$start;
                    $end = (int)$end;
                    
                    for ($v = $start; $v <= $end; $v += $step) {
                        if ($v === $value) return true;
                    }
                } else {
                    // Single value with step
                    for ($v = (int)$range; $v <= $max; $v += $step) {
                        if ($v === $value) return true;
                    }
                }
                continue;
            }

            // Handle range (e.g., 1-5)
            if (str_contains($part, '-')) {
                list($start, $end) = explode('-', $part);
                if ($value >= (int)$start && $value <= (int)$end) {
                    return true;
                }
                continue;
            }

            // Handle single value
            if ((int)$part === $value) {
                return true;
            }
        }

        return false;
    }
}
