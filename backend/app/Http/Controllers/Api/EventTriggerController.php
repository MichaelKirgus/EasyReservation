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
        $trigger = EventTrigger::create($request->all());
        return response()->json($trigger, 201);
    }

    // Update trigger
    public function update(Request $request, $id)
    {
        $trigger = EventTrigger::findOrFail($id);
        $trigger->update($request->all());
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
        // Hier: Logik zum Simulieren des Events (z.B. Aktion ausführen)
        // ...
        return response()->json(['simulated' => true]);
    }
}
