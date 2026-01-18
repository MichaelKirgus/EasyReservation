<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ScheduledTask;

class ScheduledTaskController extends Controller
{
    // GET /admin/scheduled-tasks
    public function index()
    {
        return ScheduledTask::orderBy('run_at', 'asc')->get();
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
        ]);
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
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'relative_to' => 'nullable|string',
            'relative_offset_minutes' => 'nullable|integer',
        ]);
        $task->update($data);
        return response()->json($task);
    }

    // DELETE /admin/scheduled-tasks/{id}
    public function destroy($id)
    {
        $task = ScheduledTask::findOrFail($id);
        $task->delete();
        return response()->noContent();
    }
}
