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
        
        $timezone = $this->settings->get('ical_timezone', 'Europe/Berlin');

        // Generate UID from Event UUID if available, otherwise use event ID
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $uid = ($event->uuid ? (string) $event->uuid : 'event-'.$event->id).'@'.$host;
        $dtstamp = now()->setTimezone($timezone)->format('Ymd\THis');
        $start = $event->start_at->copy()->setTimezone($timezone);
        $end = $event->end_at
            ? $event->end_at->copy()->setTimezone($timezone)
            : $event->start_at->copy()->setTimezone($timezone)->addHour();
        $dtstart = $start->format('Ymd\THis');
        $dtend = $end->format('Ymd\THis');

        // Alle Felder als Platzhalter, keine Zusammenbauten mehr
        $replacements = [
            '{{uid}}' => $uid,
            '{{dtstamp}}' => $dtstamp,
            '{{dtstart}}' => $dtstart,
            '{{dtend}}' => $dtend,
            '{{timezone}}' => $timezone,
            '{{title}}' => $this->escape((string) $event->title),
            '{{location}}' => $this->escape((string) $event->location),
            '{{notes}}' => $this->escape((string) $event->notes),
            '{{url}}' => $this->escape((string) $event->url),
            '{{start_date}}' => $start->format('Y-m-d'),
            '{{start_time}}' => $start->format('H:i'),
            '{{end_date}}' => $end->format('Y-m-d'),
            '{{end_time}}' => $end->format('H:i'),
            // Für Kompatibilität mit alten Vorlagen:
            '{{summary}}' => $this->escape((string) $event->title),
            '{{description}}' => $this->escape((string) $event->notes),
        ];
        // Merge mit globalen Platzhaltern
        $replacements = array_merge($this->placeholders->replacements(), $replacements);

        if ($template && is_string($template) && trim($template) !== '') {
            return strtr($template, $replacements);
        }

        // Fallback: bisherige Logik (wie gehabt)
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
            'UID:'.$this->escape($uid),
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
