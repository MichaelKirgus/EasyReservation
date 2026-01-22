<?php

namespace App\Services;

use App\Models\Event;
use App\Services\CustomPlaceholderService;

class PlaceholderService
{
    private const RECIPIENT_TOKENS = [
        '{{name}}',
        '{{email}}',
        '{{undo_link}}',
        '{{validation_link}}',
    ];

    public function __construct(
        private readonly EventService $events,
        private readonly SettingsService $settings,
        private readonly CustomPlaceholderService $customPlaceholders,
    ) {
    }

    public function replacements(): array
    {
        $next = $this->events->next();
        $dateFormat = (string) ($this->settings->get('event_date_format', 'Y-m-d') ?: 'Y-m-d');
        $timeFormat = (string) ($this->settings->get('event_time_format', 'H:i') ?: 'H:i');

        $timezone = $this->settings->get('event_timezone', 'Europe/Berlin');
        $nextEvent = $next ? $this->events->format($next) : '';
        $eventLocation = $next instanceof Event ? (string) ($next->location ?? '') : '';
        $eventCity = $next instanceof Event ? (string) ($next->city ?? '') : '';
        $eventTitle = $next instanceof Event ? (string) ($next->title ?? '') : '';
        $eventDate = $next?->start_at?->copy()->setTimezone($timezone)->format($dateFormat) ?? '';
        $eventTime = $next?->start_at?->copy()->setTimezone($timezone)->format($timeFormat) ?? '';
        $eventDateUtc = $next?->start_at?->copy()->setTimezone('UTC')->format($dateFormat) ?? '';
        $eventTimeUtc = $next?->start_at?->copy()->setTimezone('UTC')->format($timeFormat) ?? '';
        $eventUrl = $next instanceof Event ? (string) ($next->url ?? '') : '';
        $eventPublicTransportUrl = $next instanceof Event ? (string) ($next->public_transport_url ?? '') : '';

        $upcomingCollection = $this->events->upcoming();
        $upcomingTitles = $upcomingCollection
            ->map(fn (Event $e) => (string) ($e->title ?? ''))
            ->filter(fn ($v) => $v !== '')
            ->values();
        $upcomingList = $upcomingTitles->isEmpty() ? '' : implode("\n", $upcomingTitles->map(fn ($v) => '• '.$v)->all());

        $upcomingTitlesWithoutNext = $upcomingCollection->slice(1)
            ->map(fn (Event $e) => (string) ($e->title ?? ''))
            ->filter(fn ($v) => $v !== '')
            ->values();
        $upcomingListWithoutNext = $upcomingTitlesWithoutNext->isEmpty() ? '' : implode("\n", $upcomingTitlesWithoutNext->map(fn ($v) => '• '.$v)->all());

        $upcomingDatesWithoutNext = $upcomingCollection->slice(1)
            ->map(fn (Event $e) => $e->start_at?->format($dateFormat) ?? '')
            ->filter(fn ($v) => $v !== '')
            ->values();
        $upcomingDatesWithoutNextList = $upcomingDatesWithoutNext->isEmpty() ? '' : implode("\n", $upcomingDatesWithoutNext->map(fn ($v) => '• '.$v)->all());

        $core = [
            '{{reservation_name}}' => (string) $this->settings->get('reservation_name', ''),
            '{{next_event}}' => $nextEvent,
            '{{upcoming_events}}' => $upcomingList,
            '{{upcoming_events_without_next}}' => $upcomingListWithoutNext,
            '{{upcoming_event_dates_without_next}}' => $upcomingDatesWithoutNextList,
            '{{event_location}}' => $eventLocation,
            '{{event_city}}' => $eventCity,
            '{{event_title}}' => $eventTitle,
            '{{event_date}}' => $eventDate,
            '{{event_time}}' => $eventTime,
            '{{event_date_utc}}' => $eventDateUtc,
            '{{event_time_utc}}' => $eventTimeUtc,
            '{{event_url}}' => $eventUrl,
            '{{event_public_transport_info}}' => $eventPublicTransportUrl,
            '{{attach_event_ical}}' => '',
        ];
        $custom = $this->customPlaceholders->getAll();
        // Feste Platzhalter überschreiben benutzerdefinierte bei gleichem Key
        return array_merge($custom, $core);
    }

    public function tokens(): array
    {
        $tokens = array_keys($this->replacements());
        $tokens = array_merge($tokens, self::RECIPIENT_TOKENS);
        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return $tokens;
    }

    public function recipientReplacements(array $recipient = []): array
    {
        return [
            '{{name}}' => $recipient['name'] ?? '',
            '{{email}}' => $recipient['email'] ?? '',
            '{{undo_link}}' => $recipient['undo_link'] ?? '',
            '{{validation_link}}' => $recipient['validation_link'] ?? '',
        ];
    }

    public function replaceString(?string $value): string
    {
        if ($value === null || $value === '') {
            return $value ?? '';
        }

        return strtr($value, $this->replacements());
    }
}
