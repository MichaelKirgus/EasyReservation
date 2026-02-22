<?php

namespace App\Services;

use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class EventService
{
    public function upcoming(int $limit = 10): Collection
    {
        return Event::query()
            ->where('active', true)
            ->where('start_at', '>=', now()->subDay())
            ->orderBy('start_at')
            ->limit($limit)
            ->get();
    }

    public function next(): ?Event
    {
        return Event::query()
            ->where('active', true)
            ->where('start_at', '>=', now()->subDay())
            ->orderBy('start_at')
            ->first();
    }

    public function format(Event $event): string
    {
        $start = $this->formatDate($event->start_at);
        $end = $event->end_at ? $this->formatDate($event->end_at) : null;
        
        // Use explicit relationship query to avoid collision with the legacy
        // "location" string column which shadows the location() relationship.
        $locationModel = $event->location_id ? $event->location()->first() : null;
        $locationName = $event->location_id ? ($locationModel?->name ?? '') : ($event->getAttributes()['location'] ?? '');
        $city = $locationModel?->city;
        
        $placeParts = array_filter([
            $city ?? '',
            $locationName ?? '',
        ], fn ($v) => $v !== '' && $v !== null);
        $place = count($placeParts) ? ' @ '.implode(' – ', $placeParts) : '';

        return $end
            ? sprintf('%s – %s%s', $start, $end, $place)
            : sprintf('%s%s', $start, $place);
    }

    private function formatDate(CarbonInterface $date): string
    {
        return $date->format('Y-m-d H:i');
    }
}
