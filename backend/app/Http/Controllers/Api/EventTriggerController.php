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
        if ($trigger->action_type === 'email') {
            // Prüfe, ob Recipients vorhanden sind
            $recipients = $trigger->custom_recipients ?? [];
            if (empty($recipients) || !is_array($recipients) || empty($recipients[0]['email'] ?? null)) {
                return response()->json([
                    'simulated' => false,
                    'error' => 'Keine E-Mail-Adresse im Trigger hinterlegt. Simulation nicht möglich.'
                ], 400);
            }
            $templateId = $trigger->template_id;
            $result = app(\App\Services\EmailBroadcastService::class)->queueBroadcast(
                $templateId,
                'custom',
                false,
                [],
                [],
                $recipients,
                true
            );
            return response()->json(['simulated' => true, 'email_result' => $result]);
        }
        // Webhook-Test ggf. wie gehabt
        // ...
        return response()->json(['simulated' => true]);
    }
}
