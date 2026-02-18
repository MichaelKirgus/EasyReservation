<?php

namespace App\Console\Commands;

use App\Services\TranslationService;
use Illuminate\Console\Command;

class TranslationsClearCommand extends Command
{
    protected $signature = 'translations:clear
                            {locale? : Clear a specific locale only (e.g. "en")}';

    protected $description = 'Remove cached translations from the configured cache backend';

    public function handle(TranslationService $service): int
    {
        $locale = $this->argument('locale');

        if ($locale) {
            $service->forget($locale);
            $this->info("Cached translations for [{$locale}] cleared.");
        } else {
            $service->flush();
            $this->info('All cached translations cleared.');
        }

        return self::SUCCESS;
    }
}
