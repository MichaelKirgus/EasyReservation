<?php

namespace App\Services;

use App\Models\DataPortabilityOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DataPortabilityBackupService
{
    public function __construct(private readonly DataPortabilityTableService $tableService)
    {
    }

    /**
     * @return array{path: string, table_count: int, row_count: int}
     */
    public function createBackupFile(DataPortabilityOperation $operation): array
    {
        $disk = config('data-portability.storage.disk');
        $basePath = trim((string) config('data-portability.storage.base_path', 'data-portability'), '/');
        $backupDir = trim((string) config('data-portability.storage.backup_dir', 'backups'), '/');

        $selected = is_array($operation->selected_tables) ? $operation->selected_tables : null;
        $tables = $this->tableService->resolveSelectedTables($selected);
        $payload = $this->buildPayload($tables, $operation->id, 'easyreservation.logical-backup.v1');

        $options = is_array($operation->options) ? $operation->options : [];
        $customFilename = isset($options['custom_filename']) ? $this->sanitizeFilename((string) $options['custom_filename']) : '';

        $filename = $customFilename !== ''
            ? $customFilename
            : sprintf(
                'backup-%s-op%s-%s.json',
                now()->format('Ymd_His'),
                $operation->id,
                Str::random(8),
            );

        $relativePath = $basePath . '/' . $backupDir . '/' . $filename;

        Storage::disk($disk)->put(
            $relativePath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return [
            'path' => $relativePath,
            'table_count' => (int) ($payload['meta']['table_count'] ?? 0),
            'row_count' => (int) ($payload['meta']['row_count'] ?? 0),
        ];
    }

    /**
     * @param array<int, string> $tables
     * @return array<string, mixed>
     */
    public function buildPayload(array $tables, ?int $operationId = null, string $format = 'easyreservation.transport-payload.v1'): array
    {
        $tableData = [];
        $totalRows = 0;

        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            $columns = Schema::getColumnListing($table);
            $rowCount = count($rows);

            $totalRows += $rowCount;

            $tableData[$table] = [
                'columns' => $columns,
                'row_count' => $rowCount,
                'rows' => $rows,
            ];
        }

        return [
            'meta' => [
                'format' => $format,
                'created_at' => now()->toIso8601String(),
                'operation_id' => $operationId,
                'database_driver' => DB::connection()->getDriverName(),
                'app_version' => env('APP_VERSION', 'unknown'),
                'table_count' => count($tables),
                'row_count' => $totalRows,
            ],
            'tables' => $tableData,
        ];
    }

    private function sanitizeFilename(string $filename): string
    {
        $trimmed = trim($filename);
        if ($trimmed === '') {
            return '';
        }

        $base = basename($trimmed);
        $normalized = preg_replace('/[^a-zA-Z0-9._-]/', '-', $base) ?: '';
        $normalized = trim($normalized, '.-');

        if ($normalized === '') {
            return '';
        }

        if (! str_ends_with(strtolower($normalized), '.json')) {
            $normalized .= '.json';
        }

        return $normalized;
    }
}
