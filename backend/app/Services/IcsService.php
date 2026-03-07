<?php

namespace App\Services;


use App\Models\Event;
use Illuminate\Support\Str;
use App\Services\PlaceholderService;

class IcsService
{
    public function __construct(
        private readonly EventService $events,
        private readonly SettingsService $settings,
        private readonly PlaceholderService $placeholders,
    ) {
    }

    public function nextEventAttachment(?int $icalTemplateId = null): ?array
    {
        $event = $this->events->next();
        if (! $event || ! $event->start_at) {
            return null;
        }

        return [
            'name' => 'event.ics',
            'mime' => 'text/calendar; charset=utf-8',
            'data' => $this->buildIcs($event, $icalTemplateId),
        ];
    }

    public function buildIcsForEvent(Event $event, ?int $icalTemplateId = null): string
    {
        return $this->buildIcs($event, $icalTemplateId);
    }

    private function buildIcs(Event $event, ?int $icalTemplateId = null): string
    {
        // Get ICS template - either from provided ID or global setting (backward compatibility)
        if ($icalTemplateId) {
            $template = \App\Models\IcalTemplate::find($icalTemplateId)?->content;
        } else {
            // Fallback to global setting for backward compatibility
            $template = $this->settings->get('ical_template');
        }

        // Use PlaceholderService replacements (which now include all iCal-specific placeholders)
        // The placeholders are already escaped in the template if needed, or we can escape them here
        $replacements = $this->placeholders->replacements();

        if ($template && is_string($template) && trim($template) !== '') {
            return strtr($template, $replacements);
        }

        // Fallback: generate iCal content directly (for backward compatibility)
        $summary = $event->title ?: (string) $this->settings->get('reservation_name', 'Event');
        // Get location data from relationship
        $locationModel = $event->location_id ? $event->location()->first() : null;
        $locationParts = array_filter([
            $event->location ?: null,
            $locationModel?->city ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $location = empty($locationParts) ? '' : implode(' – ', $locationParts);
        $descriptionParts = array_filter([
            (string) ($event->notes ?? ''),
            (string) ($event->url ?? ''),
            $locationModel?->public_transport ? (string) $locationModel->public_transport : null,
        ], fn ($v) => $v !== '');
        $description = empty($descriptionParts) ? $summary : implode(' | ', $descriptionParts);
        $url = $event->url ? (string) $event->url : '';
        $startUtc = $event->start_at->copy()->utc();
        $endUtc = $event->end_at
            ? $event->end_at->copy()->utc()
            : $event->start_at->copy()->utc()->addHour();
        $lines = array_filter([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//PHPEasyReservation//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$this->escape(($event->uuid ? (string) $event->uuid : 'event-'.$event->id).'@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost')),
            'DTSTAMP:'.$startUtc->format('Ymd\THis\Z'),
            'DTSTART:'.$startUtc->format('Ymd\THis\Z'),
            'DTEND:'.$endUtc->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape($summary),
            $location !== '' ? 'LOCATION:'.$this->escape($location) : null,
            'DESCRIPTION:'.$this->escape($description),
            $url ? 'URL:'.$this->escape($url) : null,
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * Escape special characters for iCal format.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ["\\", ";", ",", "\r\n", "\n", "\r"],
            ["\\\\", "\\;", "\\,", "\\n", "\\n", ''],
            $value
        );
    }
}
