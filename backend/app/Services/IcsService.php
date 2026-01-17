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

    public function nextEventAttachment(): ?array
    {
        $event = $this->events->next();
        if (! $event || ! $event->start_at) {
            return null;
        }

        return [
            'name' => 'event.ics',
            'mime' => 'text/calendar; charset=utf-8',
            'data' => $this->buildIcs($event),
        ];
    }

    private function buildIcs(Event $event): string
    {
        // ICS Template and Timezone from settings
        $template = $this->settings->get('ical_template');
        $timezone = $this->settings->get('ical_timezone', 'Europe/Berlin');

        // Alle Event-Infos als Platzhalter bereitstellen
        $host = parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $uid = ($event->id ? 'event-'.$event->id : (string) Str::uuid()).'@'.$host;
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
            '{{city}}' => $this->escape((string) $event->city),
            '{{notes}}' => $this->escape((string) $event->notes),
            '{{url}}' => $this->escape((string) $event->url),
            '{{public_transport_url}}' => $this->escape((string) $event->public_transport_url),
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
        $locationParts = array_filter([
            $event->location ?: null,
            $event->city ?: null,
        ], fn ($v) => $v !== null && $v !== '');
        $location = empty($locationParts) ? '' : implode(' – ', $locationParts);
        $descriptionParts = array_filter([
            (string) ($event->notes ?? ''),
            (string) ($event->url ?? ''),
            (string) ($event->public_transport_url ?? ''),
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

    private function escape(string $value): string
    {
        return str_replace(
            ["\\", ";", ",", "\r\n", "\n", "\r"],
            ["\\\\", "\\;", "\\,", "\\n", "\\n", ''],
            $value
        );
    }
}
