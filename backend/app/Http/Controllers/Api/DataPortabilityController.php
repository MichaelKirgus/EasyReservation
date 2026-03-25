<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunDatabaseBackupJob;
use App\Jobs\RunDatabaseRestoreJob;
use App\Jobs\RunDatabaseTransportJob;
use App\Models\DataPortabilityOperation;
use App\Models\DataPortabilityTransportProfile;
use App\Services\DataPortabilityFileService;
use App\Services\DataPortabilityTableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataPortabilityController extends Controller
{
    public function __construct(
        private readonly DataPortabilityTableService $tableService,
        private readonly DataPortabilityFileService $fileService,
    ) {
    }

    public function tables(): JsonResponse
    {
        return response()->json([
            'tables' => $this->tableService->discoverTables(),
        ]);
    }

    public function files(): JsonResponse
    {
        return response()->json([
            'files' => $this->fileService->listBackupFiles(),
        ]);
    }

    public function uploadFile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'mimetypes:application/json,text/plain',
                'max:' . (int) config('data-portability.storage.upload_max_kb', 51200),
            ],
        ]);

        try {
            $path = $this->fileService->storeUploadedFile($data['file']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Backup file uploaded successfully',
            'path' => $path,
        ], 201);
    }

    public function downloadFile(string $file): StreamedResponse
    {
        return $this->fileService->downloadBackupFile($file);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 25);
        $limit = max(1, min($limit, 200));

        $operations = DataPortabilityOperation::query()
            ->with('requestedByUser:id,name,email')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json($operations);
    }

    public function show(DataPortabilityOperation $operation): JsonResponse
    {
        $operation->load('requestedByUser:id,name,email');

        return response()->json($operation);
    }

    public function createBackup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selected_tables' => ['nullable', 'array'],
            'selected_tables.*' => ['string', 'max:255'],
            'options' => ['nullable', 'array'],
        ]);

        $selectedTables = $this->tableService->resolveSelectedTables($data['selected_tables'] ?? null);

        if (($data['selected_tables'] ?? null) !== null && $selectedTables === []) {
            return response()->json(['message' => 'No valid tables selected for backup.'], 422);
        }

        $operation = DataPortabilityOperation::create([
            'type' => 'backup',
            'status' => 'queued',
            'requested_by_user_id' => $request->user()?->id,
            'selected_tables' => $selectedTables,
            'options' => $data['options'] ?? [],
        ]);

        RunDatabaseBackupJob::dispatch($operation->id)
            ->onQueue(config('data-portability.queue'));

        return response()->json([
            'message' => 'Backup operation queued',
            'operation_id' => $operation->id,
        ], 202);
    }

    public function createRestore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_file_path' => ['required', 'string', 'max:2048'],
            'selected_tables' => ['nullable', 'array'],
            'selected_tables.*' => ['string', 'max:255'],
            'restore_mode' => ['nullable', 'in:truncate_insert,upsert'],
            'options' => ['nullable', 'array'],
        ]);

        $restoreMode = $data['restore_mode'] ?? config('data-portability.restore.default_mode', 'truncate_insert');

        $operation = DataPortabilityOperation::create([
            'type' => 'restore',
            'status' => 'queued',
            'requested_by_user_id' => $request->user()?->id,
            'source_file_path' => $data['source_file_path'],
            'selected_tables' => $data['selected_tables'] ?? null,
            'restore_mode' => $restoreMode,
            'options' => $data['options'] ?? [],
        ]);

        RunDatabaseRestoreJob::dispatch($operation->id)
            ->onQueue(config('data-portability.queue'));

        return response()->json([
            'message' => 'Restore operation queued',
            'operation_id' => $operation->id,
        ], 202);
    }

    public function createTransport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transport_profile_id' => ['required', 'integer', 'exists:data_portability_transport_profiles,id'],
            'selected_tables' => ['required', 'array', 'min:1'],
            'selected_tables.*' => ['string', 'max:255'],
            'restore_mode' => ['nullable', 'in:truncate_insert,upsert'],
            'options' => ['nullable', 'array'],
        ]);

        $restoreMode = $data['restore_mode'] ?? config('data-portability.restore.default_mode', 'truncate_insert');

        $options = array_merge($data['options'] ?? [], [
            'transport_profile_id' => (int) $data['transport_profile_id'],
            'delivery_mode' => 'direct_payload',
        ]);

        $operation = DataPortabilityOperation::create([
            'type' => 'transport',
            'status' => 'queued',
            'requested_by_user_id' => $request->user()?->id,
            'selected_tables' => $data['selected_tables'],
            'restore_mode' => $restoreMode,
            'options' => $options,
        ]);

        RunDatabaseTransportJob::dispatch($operation->id)
            ->onQueue(config('data-portability.queue'));

        return response()->json([
            'message' => 'Transport operation queued',
            'operation_id' => $operation->id,
        ], 202);
    }

    public function preflightTransport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'selected_tables' => ['required', 'array', 'min:1'],
            'selected_tables.*' => ['string', 'max:255'],
        ]);

        $requestedTables = array_values(array_unique(array_map('strval', $data['selected_tables'])));
        $availableTables = $this->tableService->discoverTables();
        $availableMap = array_fill_keys($availableTables, true);

        $missingTables = [];
        $acceptedTables = [];

        foreach ($requestedTables as $table) {
            if (isset($availableMap[$table])) {
                $acceptedTables[] = $table;
            } else {
                $missingTables[] = $table;
            }
        }

        return response()->json([
            'requested_tables' => $requestedTables,
            'accepted_tables' => $acceptedTables,
            'missing_tables' => $missingTables,
            'can_transport' => $missingTables === [] && $acceptedTables !== [],
        ]);
    }

    public function receiveTransport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payload' => ['required', 'array'],
            'payload.meta' => ['required', 'array'],
            'payload.tables' => ['required', 'array', 'min:1'],
            'selected_tables' => ['nullable', 'array'],
            'selected_tables.*' => ['string', 'max:255'],
            'restore_mode' => ['nullable', 'in:truncate_insert,upsert'],
            'source_operation_id' => ['nullable', 'integer'],
        ]);

        $restoreMode = $data['restore_mode'] ?? config('data-portability.restore.default_mode', 'truncate_insert');
        $payloadTables = array_map('strval', array_keys((array) $data['payload']['tables']));
        $requestedTables = $data['selected_tables'] ?? $payloadTables;
        $requestedTables = array_values(array_unique(array_map('strval', $requestedTables)));

        $availableTables = $this->tableService->discoverTables();
        $availableMap = array_fill_keys($availableTables, true);

        $missingTables = [];
        $selectedTables = [];

        foreach ($requestedTables as $table) {
            if (! in_array($table, $payloadTables, true)) {
                $missingTables[] = $table;
                continue;
            }

            if (isset($availableMap[$table])) {
                $selectedTables[] = $table;
            } else {
                $missingTables[] = $table;
            }
        }

        if ($missingTables !== []) {
            return response()->json([
                'message' => 'Transport preflight failed: destination is missing one or more requested tables.',
                'requested_tables' => $requestedTables,
                'accepted_tables' => $selectedTables,
                'missing_tables' => array_values(array_unique($missingTables)),
            ], 422);
        }

        if ($selectedTables === []) {
            return response()->json([
                'message' => 'No valid tables selected for restore.',
                'requested_tables' => $requestedTables,
                'accepted_tables' => [],
                'missing_tables' => $requestedTables,
            ], 422);
        }

        $operation = DataPortabilityOperation::create([
            'type' => 'restore',
            'status' => 'queued',
            'requested_by_user_id' => $request->user()?->id,
            'restore_mode' => $restoreMode,
            'selected_tables' => $selectedTables,
            'options' => [
                'source' => 'transport',
                'source_operation_id' => $data['source_operation_id'] ?? null,
                'payload' => $data['payload'],
            ],
        ]);

        RunDatabaseRestoreJob::dispatch($operation->id)
            ->onQueue(config('data-portability.queue'));

        return response()->json([
            'message' => 'Transport payload accepted and restore queued',
            'operation_id' => $operation->id,
        ], 202);
    }

    public function listTransportProfiles(): JsonResponse
    {
        $profiles = DataPortabilityTransportProfile::query()
            ->with('createdByUser:id,name,email')
            ->orderBy('name')
            ->get()
            ->map(function (DataPortabilityTransportProfile $profile) {
                return [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'target_base_url' => $profile->target_base_url,
                    'is_active' => (bool) $profile->is_active,
                    'timeout_seconds' => $profile->timeout_seconds,
                    'created_by_user_id' => $profile->created_by_user_id,
                    'created_by_user' => $profile->createdByUser,
                    'created_at' => optional($profile->created_at)?->toIso8601String(),
                    'updated_at' => optional($profile->updated_at)?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json(['profiles' => $profiles]);
    }

    public function createTransportProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:data_portability_transport_profiles,name'],
            'target_base_url' => ['required', 'url', 'max:2048'],
            'target_api_token' => ['required', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
            'timeout_seconds' => ['nullable', 'integer', 'min:10', 'max:600'],
        ]);

        $profile = DataPortabilityTransportProfile::create([
            'name' => $data['name'],
            'target_base_url' => $data['target_base_url'],
            'target_api_token' => $data['target_api_token'],
            'is_active' => $data['is_active'] ?? true,
            'timeout_seconds' => $data['timeout_seconds'] ?? null,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Transport profile created successfully',
            'profile_id' => $profile->id,
        ], 201);
    }

    public function updateTransportProfile(Request $request, DataPortabilityTransportProfile $profile): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:data_portability_transport_profiles,name,' . $profile->id],
            'target_base_url' => ['sometimes', 'required', 'url', 'max:2048'],
            'target_api_token' => ['sometimes', 'required', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'timeout_seconds' => ['nullable', 'integer', 'min:10', 'max:600'],
        ]);

        $profile->fill($data);
        $profile->save();

        return response()->json(['message' => 'Transport profile updated successfully']);
    }

    public function deleteTransportProfile(DataPortabilityTransportProfile $profile): JsonResponse
    {
        $profile->delete();

        return response()->json(['message' => 'Transport profile deleted successfully']);
    }
}
