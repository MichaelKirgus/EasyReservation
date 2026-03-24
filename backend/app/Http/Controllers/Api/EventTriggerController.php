<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventTrigger;
use Illuminate\Http\Request;

class EventTriggerController extends Controller
{
    // List all triggers
    public function index()
    {
        $triggers = EventTrigger::query()
            ->with('actionList:id,name')
            ->orderBy('id')
            ->get();

        return response()->json($triggers->map(function (EventTrigger $trigger) {
            return [
                'id' => $trigger->id,
                'event_type' => $trigger->event_type,
                'action_list_id' => $trigger->action_list_id,
                'action_list_name' => $trigger->actionList?->name,
                'delay_seconds' => (int) ($trigger->delay_seconds ?? 0),
                'cooldown_seconds' => (int) ($trigger->cooldown_seconds ?? 0),
                'active' => (bool) $trigger->active,
                'created_at' => $trigger->created_at?->toIso8601String(),
                'updated_at' => $trigger->updated_at?->toIso8601String(),
            ];
        })->values());
    }

    // Create new trigger
    public function store(Request $request)
    {
        $data = $request->validate([
            'event_type' => 'required|string|max:255',
            'action_list_id' => 'required|integer|exists:action_lists,id',
            'delay_seconds' => 'nullable|integer|min:0',
            'cooldown_seconds' => 'nullable|integer|min:0',
            'active' => 'boolean',
        ]);

        $trigger = EventTrigger::create($data);
        return response()->json($trigger, 201);
    }

    // Update trigger
    public function update(Request $request, $id)
    {
        $trigger = EventTrigger::findOrFail($id);
        $data = $request->validate([
            'event_type' => 'required|string|max:255',
            'action_list_id' => 'required|integer|exists:action_lists,id',
            'delay_seconds' => 'nullable|integer|min:0',
            'cooldown_seconds' => 'nullable|integer|min:0',
            'active' => 'boolean',
        ]);

        $trigger->update($data);
        return response()->json($trigger);
    }

    // Delete trigger
    public function destroy($id)
    {
        EventTrigger::destroy($id);
        return response()->json(['success' => true]);
    }

    // Toggle active status
    public function toggleActive($id)
    {
        $trigger = EventTrigger::findOrFail($id);
        $trigger->active = !$trigger->active;
        $trigger->save();
        return response()->json(['success' => true, 'active' => $trigger->active]);
    }

    // Simulate event
    public function simulate($id)
    {
        $trigger = EventTrigger::findOrFail($id);
        $service = app(\App\Services\EventTriggerService::class);

        try {
            $service->executeAction($trigger, []);
        } catch (\Throwable $e) {
            return response()->json([
                'simulated' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json(['simulated' => true]);
    }
}
