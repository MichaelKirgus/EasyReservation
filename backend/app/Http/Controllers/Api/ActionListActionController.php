<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionListAction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ActionListActionController extends Controller
{
    /**
     * Display a listing of actions for an action list.
     */
    public function index(int $actionListId): JsonResponse
    {
        $actions = ActionListAction::where('action_list_id', $actionListId)->orderBy('sort_order')->get();
        
        return response()->json($actions);
    }

    /**
     * Store a newly created action for an action list.
     */
    public function store(Request $request, int $actionListId): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:email,webhook,change_setting,remove_attendees_from_reservation_list,remove_attendees_from_waitlist,remove_mail_validation_ip_rate_limits',
            'config' => 'nullable|array',
            'enabled' => 'boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $action = ActionListAction::create([
            'action_list_id' => $actionListId,
            'type' => $validated['type'],
            'config' => $validated['config'] ?? [],
            'enabled' => $validated['enabled'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json($action, 201);
    }

    /**
     * Update the specified action for an action list.
     */
    public function update(Request $request, int $actionListId, int $actionId): JsonResponse
    {
        $action = ActionListAction::where('action_list_id', $actionListId)->find($actionId);

        if (!$action) {
            return response()->json(['message' => 'Action not found'], 404);
        }

        $validated = $request->validate([
            'type' => 'required|string|in:email,webhook,change_setting,remove_attendees_from_reservation_list,remove_attendees_from_waitlist,remove_mail_validation_ip_rate_limits',
            'config' => 'nullable|array',
            'enabled' => 'boolean',
            'sort_order' => 'required|integer',
        ]);

        $action->update([
            'type' => $validated['type'],
            'config' => $validated['config'] ?? [],
            'enabled' => $validated['enabled'] ?? true,
            'sort_order' => $validated['sort_order'],
        ]);

        return response()->json($action);
    }

    /**
     * Remove the specified action from storage.
     */
    public function destroy(int $actionListId, int $actionId): JsonResponse
    {
        $action = ActionListAction::where('action_list_id', $actionListId)->find($actionId);

        if (!$action) {
            return response()->json(['message' => 'Action not found'], 404);
        }

        $action->delete();

        return response()->json(['message' => 'Action deleted successfully']);
    }
}
