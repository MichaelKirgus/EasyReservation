<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\CustomPlaceholderService;
use App\Services\SiteTokenService;

class PlaceholderService
{
    private const RECIPIENT_TOKENS = [
        '{{name}}',
        '{{email}}',
        '{{undo_link}}',
        '{{undo_link_html}}',
        '{{validation_link}}',
        '{{validation_link_html}}',
        '{{admin_approval_link}}',
    ];

    private const CONTEXT_TOKENS = [
        '{{error_message}}',
    ];

    /**
     * Context-specific placeholders set externally (e.g. from EventTriggerService).
     */
    private array $contextPlaceholders = [];

    private const SITE_TOKENS = [
        '{{site_base_url}}',
        '{{site_guest_token}}',
    ];

    public function __construct(
        private readonly EventService $events,
        private readonly SettingsService $settings,
        private readonly CustomPlaceholderService $customPlaceholders,
        private readonly SiteTokenService $siteTokens,
    ) {
    }

    public function replacements(array $recipient = []): array
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
            '{{reservation_list_max_count}}' => (string) ($this->settings->get('reservation_max', 0) ?? 0),
            '{{reservation_list_current_count}}' => (string) Reservation::query()->count(),
            '{{reservation_list_free_count}}' => (string) max(0, ($this->settings->get('reservation_max', 0) ?? 0) - Reservation::query()->count()),
            '{{reservation_list_items}}' => $this->getReservationListItems(),
            '{{waitlist_max_count}}' => (string) ($this->settings->get('waitlist_limit', 0) ?? 0),
            '{{waitlist_current_count}}' => (string) WaitlistEntry::query()->where('status', 'pending')->count(),
            '{{waitlist_free_count}}' => (string) max(0, ($this->settings->get('waitlist_limit', 0) ?? 0) - WaitlistEntry::query()->where('status', 'pending')->count()),
            '{{waiting_list_items}}' => $this->getWaitingListItems(),
        ];
        
        // Site tokens
        $appUrl = config('app.url');
        $siteBaseUrl = rtrim($appUrl, '/');
        $guestToken = $this->siteTokens->getValidSiteToken() ?? '';
        
        $core['{{site_base_url}}'] = $siteBaseUrl;
        $core['{{site_guest_token}}'] = $guestToken;
        
        $custom = $this->customPlaceholders->getAll();
        $recipientTokens = [
            '{{name}}' => $recipient['name'] ?? '',
            '{{email}}' => $recipient['email'] ?? '',
            '{{undo_link}}' => $recipient['undo_link'] ?? '',
            '{{undo_link_html}}' => $recipient['undo_link_html'] ?? '',
            '{{validation_link}}' => $recipient['validation_link'] ?? '',
            '{{validation_link_html}}' => $recipient['validation_link_html'] ?? '',
            '{{admin_approval_link}}' => $recipient['admin_approval_link'] ?? '',
            '{{admin_approval_link_html}}' => $recipient['admin_approval_link_html'] ?? '',
        ];
        // Context placeholders (e.g. error_message from event triggers)
        $contextTokens = [
            '{{error_message}}' => $this->contextPlaceholders['error_message'] ?? '',
        ];
        // Reihenfolge: custom < core < context < recipientTokens (Empfänger-spezifische überschreiben alles)
        return array_merge($custom, $core, $contextTokens, $recipientTokens);
    }

    public function tokens(): array
    {
        $tokens = array_keys($this->replacements());
        $tokens = array_merge($tokens, self::RECIPIENT_TOKENS, self::SITE_TOKENS, self::CONTEXT_TOKENS);
        $tokens = array_values(array_unique($tokens));
        sort($tokens);

        return $tokens;
    }



    /**
     * Set context-specific placeholders (e.g. error_message) for the current request/trigger.
     */
    public function setContextPlaceholders(array $context): self
    {
        $this->contextPlaceholders = array_merge($this->contextPlaceholders, $context);
        return $this;
    }

    /**
     * Clear context-specific placeholders.
     */
    public function clearContextPlaceholders(): self
    {
        $this->contextPlaceholders = [];
        return $this;
    }

    public function replaceString(?string $value): string
    {
        if ($value === null || $value === '') {
            return $value ?? '';
        }

        return strtr($value, $this->replacements());
    }

    private function getReservationListItems(): string
    {
        $names = Reservation::query()
            ->pluck('display_name')
            ->filter(fn ($v) => $v !== '')
            ->values();

        if ($names->isEmpty()) {
            return '';
        }

        return $names->map(fn ($v) => $v . "\n")->implode('');
    }

    private function getWaitingListItems(): string
    {
        $names = WaitlistEntry::query()
            ->where('status', 'pending')
            ->pluck('display_name')
            ->filter(fn ($v) => $v !== '')
            ->values();

        if ($names->isEmpty()) {
            return '';
        }

        return $names->map(fn ($v) => $v . "\n")->implode('');
    }
}
