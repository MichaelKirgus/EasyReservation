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
            'type' => 'required|string',
            'run_at' => 'nullable|date',
            'options' => 'nullable|array',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'relative_to' => 'nullable|string',
            'relative_offset_minutes' => 'nullable|integer',
            'active' => 'boolean',
        ]);
        // Validation: Either run_at or (relative_to + relative_offset_minutes) must be set
        if (empty($data['run_at']) && (empty($data['relative_to']) || $data['relative_offset_minutes'] === null)) {
            return response()->json(['message' => __('scheduled_task_either_run_at_or_relative')], 422);
        }
        $task = ScheduledTask::create($data);
        return response()->json($task, 201);
    }

    // PUT /admin/scheduled-tasks/{id}
    public function update(Request $request, $id)
    {
        $task = ScheduledTask::findOrFail($id);
        $data = $request->validate([
            'type' => 'required|string',
            'run_at' => 'nullable|date',
            'options' => 'nullable|array',
            'executed' => 'boolean',
            'executed_at' => 'nullable|date',
            'active' => 'boolean',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'relative_to' => 'nullable|string',
            'relative_offset_minutes' => 'nullable|integer',
        ]);
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
            
            \Log::info('ScheduledTaskController: Dispatching ExecuteScheduledTaskJob for task ' . $task->id);
            $service->queueTaskExecution($task);
            
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
}
