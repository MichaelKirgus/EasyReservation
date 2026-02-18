<?php

namespace App\Console\Commands;

use App\Services\TranslationService;
use Illuminate\Console\Command;

class TranslationsCacheCommand extends Command
{
    protected $signature = 'translations:cache
                            {locale? : Warm a specific locale only (e.g. "en")}';

    protected $description = 'Load translations from JSON source files into the configured cache backend';

    public function handle(TranslationService $service): int
    {
        $locale = $this->argument('locale');

        if ($locale) {
            if ($service->warm($locale)) {
                $this->info("Translations for [{$locale}] cached successfully.");
            } else {
                $this->error("Source file for locale [{$locale}] not found.");
                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $warmed = $service->warmAll();

        if (empty($warmed)) {
            $this->warn('No translation source files found in resources/lang/.');
            return self::FAILURE;
        }

        $this->info('Cached translations for: ' . implode(', ', $warmed));

        return self::SUCCESS;
    }
}
