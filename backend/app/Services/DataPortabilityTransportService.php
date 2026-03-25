<?php

namespace App\Services;

use App\Models\DataPortabilityOperation;
use App\Models\DataPortabilityTransportProfile;
use Illuminate\Support\Facades\Http;

class DataPortabilityTransportService
{
    public function __construct(
        private readonly DataPortabilityBackupService $backupService,
        private readonly DataPortabilityTableService $tableService,
    ) {
    }

    /**
     * @return array{target_operation_id: int|null, tables_sent: int, rows_sent: int}
     */
    public function transport(DataPortabilityOperation $operation): array
    {
        $options = is_array($operation->options) ? $operation->options : [];
        $profileId = isset($options['transport_profile_id']) ? (int) $options['transport_profile_id'] : 0;

        $profile = DataPortabilityTransportProfile::query()
            ->where('id', $profileId)
            ->where('is_active', true)
            ->first();

        if (! $profile) {
            throw new \RuntimeException('Transport profile is missing or inactive.');
        }

        $targetBaseUrl = (string) $profile->target_base_url;
        $targetApiToken = (string) $profile->target_api_token;

        $tables = $this->tableService->resolveSelectedTables(
            is_array($operation->selected_tables) ? $operation->selected_tables : []
        );

        if ($tables === []) {
            throw new \RuntimeException('No valid tables selected for transport.');
        }

        $timeoutSeconds = $profile->timeout_seconds ?: max(10, (int) config('data-portability.transport.timeout_seconds', 120));
        $preflightEndpointPath = (string) config('data-portability.transport.preflight_endpoint', '/api/admin/data-portability/preflight-transport');
        $preflightUrl = rtrim($targetBaseUrl, '/') . '/' . ltrim($preflightEndpointPath, '/');

        $preflightResponse = Http::timeout($timeoutSeconds)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Api-Key' => $targetApiToken,
            ])
            ->post($preflightUrl, [
                'selected_tables' => $tables,
            ]);

        if (! $preflightResponse->ok()) {
            throw new \RuntimeException('Target preflight endpoint failed: ' . $preflightResponse->status() . ' ' . $preflightResponse->body());
        }

        $preflightJson = $preflightResponse->json();
        $missingTables = is_array($preflightJson) ? (array) ($preflightJson['missing_tables'] ?? []) : [];

        if ($missingTables !== [] && (bool) config('data-portability.transport.strict_tables', true)) {
            throw new \RuntimeException('Transport preflight failed. Missing destination tables: ' . implode(', ', $missingTables));
        }

        $acceptedTables = is_array($preflightJson) ? (array) ($preflightJson['accepted_tables'] ?? $tables) : $tables;
        $tablesToSend = array_values(array_unique(array_map('strval', $acceptedTables)));

        if ($tablesToSend === []) {
            throw new \RuntimeException('Transport preflight returned no acceptable tables.');
        }

        $payload = $this->backupService->buildPayload($tablesToSend, $operation->id, 'easyreservation.transport-payload.v1');

        $endpointPath = (string) config('data-portability.transport.receive_endpoint', '/api/admin/data-portability/receive-transport');
        $url = rtrim($targetBaseUrl, '/') . '/' . ltrim($endpointPath, '/');

        $response = Http::timeout($timeoutSeconds)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Api-Key' => $targetApiToken,
            ])
            ->post($url, [
                'payload' => $payload,
                'selected_tables' => $tablesToSend,
                'restore_mode' => (string) ($operation->restore_mode ?: config('data-portability.restore.default_mode', 'truncate_insert')),
                'source_operation_id' => $operation->id,
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException('Target transport endpoint failed: ' . $response->status() . ' ' . $response->body());
        }

        $json = $response->json();
        $targetOperationId = is_array($json) ? ($json['operation_id'] ?? null) : null;

        return [
            'target_operation_id' => is_numeric($targetOperationId) ? (int) $targetOperationId : null,
            'tables_sent' => (int) ($payload['meta']['table_count'] ?? count($tables)),
            'rows_sent' => (int) ($payload['meta']['row_count'] ?? 0),
        ];
    }
}
