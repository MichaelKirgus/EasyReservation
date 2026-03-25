<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;

class DataPortabilityTableService
{
    /**
     * @return array<int, string>
     */
    public function discoverTables(): array
    {
        $tables = Schema::getTableListing();
        $tables = array_map('strval', $tables);

        $filtered = array_values(array_filter($tables, fn (string $table) => ! $this->isExcluded($table)));
        sort($filtered);

        return $filtered;
    }

    /**
     * @param array<int, string>|null $selected
     * @return array<int, string>
     */
    public function resolveSelectedTables(?array $selected): array
    {
        $available = $this->discoverTables();

        if ($selected === null || $selected === []) {
            return $available;
        }

        $availableMap = array_fill_keys($available, true);
        $resolved = [];

        foreach ($selected as $table) {
            $name = (string) $table;
            if (isset($availableMap[$name])) {
                $resolved[] = $name;
            }
        }

        $resolved = array_values(array_unique($resolved));
        sort($resolved);

        return $resolved;
    }

    private function isExcluded(string $table): bool
    {
        $excludeExact = config('data-portability.tables.exclude_exact', []);
        if (in_array($table, $excludeExact, true)) {
            return true;
        }

        $excludePatterns = config('data-portability.tables.exclude_patterns', []);

        foreach ($excludePatterns as $pattern) {
            $regex = '/^' . str_replace('%', '.*', preg_quote((string) $pattern, '/')) . '$/i';
            if (preg_match($regex, $table) === 1) {
                return true;
            }
        }

        return false;
    }
}
