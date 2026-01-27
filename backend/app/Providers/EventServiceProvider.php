<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobProcessed;
use App\Listeners\UpdateWorkerStats;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        JobProcessed::class => [
            UpdateWorkerStats::class,
        ],
    ];
}
