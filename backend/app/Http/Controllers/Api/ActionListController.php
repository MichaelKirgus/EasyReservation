<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionList;
use App\Models\ActionListAction;
use App\Services\ActionListService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ActionListController extends Controller
{
    public function __construct(
        private readonly ActionListService $actionListService,
    ) {}

    /**
     * Display a listing of action lists.
     */
    public function index(): JsonResponse
    {
        $actionLists = ActionList::with('actions')->get();
        
        return response()->json([
            'action_lists' => $actionLists->map(function($list) {
                return [
                    'id' => $list->id,
                    'name' => $list->name,
                    'description' => $list->description,
                    'active' => $list->active,
                    'moderation_selectable' => $list->moderation_selectable,
                    'actions_count' => $list->actions->count(),
                    'created_at' => $list->created_at?->toIso8601String(),
                    'actions' => $list->actions->map(function($action) {
                        return [
                            'id' => $action->id,
                            'type' => $action->type,
                            'config' => $action->config,
                            'sort_order' => $action->sort_order,
                        ];
                    })->toArray(),
                ];
            })->toArray(),
        ]);
    }

    /**
     * Store a newly created action list in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'active' => 'boolean',
            'moderation_selectable' => 'boolean',
            'actions' => 'array',
        ]);

        $actionList = ActionList::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'active' => $validated['active'] ?? true,
            'moderation_selectable' => $validated['moderation_selectable'] ?? true,
        ]);

        // Process actions
        if (isset($validated['actions']) && is_array($validated['actions'])) {
            foreach ($validated['actions'] as $index => $actionData) {
                ActionListAction::create([
                    'action_list_id' => $actionList->id,
                    'type' => $actionData['type'] ?? 'email',
                    'config' => $actionData['config'] ?? [],
                    'sort_order' => $index,
                ]);
            }
        }

        return response()->json($actionList, 201);
    }

    /**
     * Display the specified action list.
     */
    public function show(int $id): JsonResponse
    {
        $actionList = ActionList::with('actions')->find($id);

        if (!$actionList) {
            return response()->json(['message' => 'Action list not found'], 404);
        }

        return response()->json([
            'id' => $actionList->id,
            'name' => $actionList->name,
            'description' => $actionList->description,
            'active' => $actionList->active,
            'moderation_selectable' => $actionList->moderation_selectable,
            'actions_count' => $actionList->actions->count(),
            'created_at' => $actionList->created_at?->toIso8601String(),
            'actions' => $actionList->actions->map(function($action) {
                return [
                    'id' => $action->id,
                    'type' => $action->type,
                    'config' => $action->config,
                    'sort_order' => $action->sort_order,
                ];
            })->toArray(),
        ]);
    }

    /**
     * Update the specified action list in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $actionList = ActionList::find($id);

        if (!$actionList) {
            return response()->json(['message' => 'Action list not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'active' => 'boolean',
            'moderation_selectable' => 'boolean',
            'actions' => 'array',
        ]);

        $actionList->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'active' => $validated['active'] ?? true,
            'moderation_selectable' => $validated['moderation_selectable'] ?? true,
        ]);

        // Process actions - delete existing and create new
        if (isset($validated['actions']) && is_array($validated['actions'])) {
            // Delete existing actions
            ActionListAction::where('action_list_id', $id)->delete();

            foreach ($validated['actions'] as $index => $actionData) {
                ActionListAction::create([
                    'action_list_id' => $id,
                    'type' => $actionData['type'] ?? 'email',
                    'config' => $actionData['config'] ?? [],
                    'sort_order' => $index,
                ]);
            }
        }

        return response()->json($actionList);
    }

    /**
     * Remove the specified action list from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $actionList = ActionList::find($id);

        if (!$actionList) {
            return response()->json(['message' => 'Action list not found'], 404);
        }

        // Delete associated actions first (cascade should handle this, but explicit is safer)
        ActionListAction::where('action_list_id', $id)->delete();

        $actionList->delete();

        return response()->json(['message' => 'Action list deleted successfully']);
    }

    /**
     * Execute the specified action list immediately.
     */
    public function execute(Request $request, int $id): JsonResponse
    {
        \Log::info('ActionListController: execute called for action list ID ' . $id);

        $validated = $request->validate([
            'action_ids' => 'nullable|array',
            'action_ids.*' => 'integer',
        ]);

        $actionIds = $validated['action_ids'] ?? null;

        $actionList = ActionList::find($id);

        if (!$actionList) {
            return response()->json(['message' => 'Action list not found'], 404);
        }

        try {
            // Dispatch job to queue instead of synchronous execution
            \Log::info('ActionListController: Dispatching ExecuteActionListJob for action list ' . $id);
            dispatch(new \App\Jobs\ExecuteActionListJob($id, [], $actionIds));

            $message = is_array($actionIds) && count($actionIds) > 0
                ? 'Selected action execution started (queued)'
                : 'Action list execution started (queued)';
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'action_list' => $actionList
            ]);
        } catch (\Throwable $e) {
            \Log::error('ActionListController: Error dispatching action list ' . $id . ': ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Update an action within an action list.
     */
    public function updateAction(Request $request, int $actionListId, int $actionId): JsonResponse
    {
        $action = ActionListAction::where('action_list_id', $actionListId)->find($actionId);

        if (!$action) {
            return response()->json(['message' => 'Action not found'], 404);
        }

        $validated = $request->validate([
            'type' => 'required|string|in:email,webhook,change_setting',
            'config' => 'nullable|array',
            'sort_order' => 'required|integer',
        ]);

        $action->update([
            'type' => $validated['type'],
            'config' => $validated['config'] ?? [],
            'sort_order' => $validated['sort_order'],
        ]);

        return response()->json($action);
    }

    /**
     * Delete an action within an action list.
     */
    public function destroyAction(int $actionListId, int $actionId): JsonResponse
    {
        $action = ActionListAction::where('action_list_id', $actionListId)->find($actionId);

        if (!$action) {
            return response()->json(['message' => 'Action not found'], 404);
        }

        $action->delete();

        return response()->json(['message' => 'Action deleted successfully']);
    }
}
