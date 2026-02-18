<?php

namespace App\Services\TranslationCache;

use App\Contracts\TranslationCacheStore;
use Illuminate\Support\Facades\DB;

class DatabaseTranslationCacheStore implements TranslationCacheStore
{
    protected ?string $connection;
    protected string $table;

    public function __construct(?string $connection, string $table)
    {
        $this->connection = $connection;
        $this->table      = $table;
    }

    public function get(string $locale): ?array
    {
        $rows = $this->query()
            ->where('lang', $locale)
            ->get(['name', 'value']);

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->pluck('value', 'name')->toArray();
    }

    public function put(string $locale, array $translations): void
    {
        // Wrap in a transaction for atomicity
        $this->db()->transaction(function () use ($locale, $translations) {
            // Remove existing entries for this locale
            $this->query()->where('lang', $locale)->delete();

            // Chunk-insert for efficiency
            $rows = [];
            foreach ($translations as $name => $value) {
                $rows[] = [
                    'lang'  => $locale,
                    'name'  => $name,
                    'value' => mb_substr((string) $value, 0, 1024),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                $this->query()->insert($chunk);
            }
        });
    }

    public function forget(string $locale): void
    {
        $this->query()->where('lang', $locale)->delete();
    }

    public function flush(): void
    {
        $this->query()->truncate();
    }

    public function has(string $locale): bool
    {
        return $this->query()->where('lang', $locale)->exists();
    }

    // ------------------------------------------------------------------

    protected function query(): \Illuminate\Database\Query\Builder
    {
        return $this->db()->table($this->table);
    }

    protected function db(): \Illuminate\Database\ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
