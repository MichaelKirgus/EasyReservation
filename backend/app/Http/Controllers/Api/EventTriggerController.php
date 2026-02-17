<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventTrigger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventTriggerController extends Controller
{
    // List all triggers
    public function index()
    {
        return response()->json(EventTrigger::all());
    }

    // Create new trigger
    public function store(Request $request)
    {
        $data = $request->all();
        if ($data['action_type'] === 'webhook') {
            $request->validate([
                'webhook_template_id' => 'required|exists:webhook_templates,id',
            ]);
        }
        $trigger = EventTrigger::create($data);
        return response()->json($trigger, 201);
    }

    // Update trigger
    public function update(Request $request, $id)
    {
        $trigger = EventTrigger::findOrFail($id);
        $data = $request->all();
        if ($data['action_type'] === 'webhook') {
            $request->validate([
                'webhook_template_id' => 'required|exists:webhook_templates,id',
            ]);
        }
        $trigger->update($data);
        return response()->json($trigger);
    }

    // Delete trigger
    public function destroy($id)
    {
        EventTrigger::destroy($id);
        return response()->json(['success' => true]);
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
