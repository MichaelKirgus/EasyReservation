<?php

namespace App\Services;

use App\Models\DataPortabilityOperation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DataPortabilityRestoreService
{
    public function __construct(private readonly DataPortabilityTableService $tableService)
    {
    }

    /**
     * @return array{tables_restored: int, rows_restored: int, table_results: array<int, array<string, mixed>>}
     */
    public function restore(DataPortabilityOperation $operation): array
    {
        $payload = $this->resolvePayload($operation);
        $payloadTables = (array) ($payload['tables'] ?? []);

        if ($payloadTables === []) {
            throw new \RuntimeException('Restore payload does not contain any tables.');
        }

        $available = $this->tableService->discoverTables();
        $availableMap = array_fill_keys($available, true);

        $requested = is_array($operation->selected_tables) ? $operation->selected_tables : array_keys($payloadTables);
        $selected = [];
        foreach ($requested as $table) {
            $name = (string) $table;
            if (isset($payloadTables[$name]) && isset($availableMap[$name])) {
                $selected[] = $name;
            }
        }

        if ($selected === []) {
            throw new \RuntimeException('No compatible tables available for restore.');
        }

        $restoreMode = (string) ($operation->restore_mode ?: config('data-portability.restore.default_mode', 'truncate_insert'));
        $chunkSize = max(1, (int) config('data-portability.restore.insert_chunk_size', 500));

        $tableResults = [];
        $rowsRestored = 0;

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($selected as $table) {
                $entry = (array) $payloadTables[$table];
                $rows = array_values(array_map(fn ($row) => (array) $row, (array) ($entry['rows'] ?? [])));

                if ($restoreMode === 'upsert') {
                    $inserted = $this->applyUpsert($table, $rows, $chunkSize);
                } else {
                    $inserted = $this->applyTruncateInsert($table, $rows, $chunkSize);
                }

                $rowsRestored += $inserted;
                $tableResults[] = [
                    'table' => $table,
                    'mode' => $restoreMode,
                    'rows' => $inserted,
                ];
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return [
            'tables_restored' => count($selected),
            'rows_restored' => $rowsRestored,
            'table_results' => $tableResults,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePayload(DataPortabilityOperation $operation): array
    {
        $options = is_array($operation->options) ? $operation->options : [];
        $inlinePayload = $options['payload'] ?? null;

        if (is_array($inlinePayload)) {
            return $inlinePayload;
        }

        if (! $operation->source_file_path) {
            throw new \RuntimeException('No restore payload or source file path specified.');
        }

        $disk = config('data-portability.storage.disk');
        $storage = Storage::disk($disk);

        if (! $storage->exists($operation->source_file_path)) {
            throw new \RuntimeException('Restore source file not found: ' . $operation->source_file_path);
        }

        $raw = $storage->get($operation->source_file_path);
        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Restore source file is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function applyTruncateInsert(string $table, array $rows, int $chunkSize): int
    {
        DB::table($table)->truncate();

        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        return count($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function applyUpsert(string $table, array $rows, int $chunkSize): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = Schema::getColumnListing($table);
        $primaryCandidates = ['id'];
        $uniqueBy = array_values(array_filter($primaryCandidates, fn ($col) => in_array($col, $columns, true)));

        if ($uniqueBy === []) {
            return $this->applyTruncateInsert($table, $rows, $chunkSize);
        }

        $updateColumns = array_values(array_filter($columns, fn ($col) => ! in_array($col, $uniqueBy, true)));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $updateColumns);
        }

        return count($rows);
    }
}
