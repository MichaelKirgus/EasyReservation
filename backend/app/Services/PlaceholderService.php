<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\Survey;
use App\Models\User;
use App\Models\WaitlistEntry;

use App\Services\CustomPlaceholderService;
use App\Services\SiteTokenService;
use App\Services\TranslationService;

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
        '{{privacy_link}}',
        '{{privacy_link_html}}',
        '{{faq_link}}',
        '{{faq_link_html}}',
        '{{faq_link_translated}}',
        '{{faq_link_html_translated}}',
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
        private readonly TranslationService $translationService,
    ) {
    }

    public function replacements(array $recipient = []): array
    {
        $next = $this->events->next();
        $dateFormat = (string) ($this->settings->get('event_date_format', 'Y-m-d') ?: 'Y-m-d');
        $timeFormat = (string) ($this->settings->get('event_time_format', 'H:i') ?: 'H:i');

        $timezone = $this->settings->get('event_timezone', 'Europe/Berlin');
        $nextEvent = $next ? $this->events->format($next) : '';
        // Use explicit relationship query to avoid collision with the legacy
        // "location" string column on the events table which shadows the
        // location() BelongsTo relationship accessor.
        $locationModel = $next instanceof Event && $next->location_id
            ? $next->location()->first()
            : null;
        $eventLocationName = $next instanceof Event ? (string) ($next->location_id ? ($locationModel?->name ?? '') : ($next->getAttributes()['location'] ?? '')) : '';
        $eventCity = $next instanceof Event && $locationModel ? (string) ($locationModel->city ?? '') : '';
        $legacyLocation = $next instanceof Event ? (string) ($next->getAttributes()['location'] ?? '') : '';
        $locationAddress = $locationModel ? (string) ($locationModel->address ?? '') : '';
        $locationPublicTransport = $locationModel ? (string) ($locationModel->public_transport ?? '') : '';
        $locationNotes = $locationModel ? (string) ($locationModel->notes ?? '') : '';
        $locationCapacity = $locationModel ? (string) ($locationModel->capacity_override ?? '') : '';
        $locationContactEmail = $locationModel ? (string) ($locationModel->contact_email ?? '') : '';
        $locationLatitude = $locationModel ? (string) ($locationModel->latitude ?? '') : '';
        $locationLongitude = $locationModel ? (string) ($locationModel->longitude ?? '') : '';
        $eventTitle = $next instanceof Event ? (string) ($next->title ?? '') : '';
        $eventDate = $next?->start_at?->copy()->setTimezone($timezone)->format($dateFormat) ?? '';
        $eventTime = $next?->start_at?->copy()->setTimezone($timezone)->format($timeFormat) ?? '';
        $eventDateUtc = $next?->start_at?->copy()->setTimezone('UTC')->format($dateFormat) ?? '';
        $eventTimeUtc = $next?->start_at?->copy()->setTimezone('UTC')->format($timeFormat) ?? '';
        // Get location URL from relationship
        $locationUrl = $locationModel ? (string) ($locationModel->url ?? '') : '';
        
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
            '{{event_location}}' => $legacyLocation, // Backward compatible - uses old location field if no relationship exists
            '{{event_location_name}}' => $eventLocationName, // New: uses location_id relationship
            '{{event_city}}' => $eventCity,
            '{{event_location_address}}' => $locationAddress,
            '{{event_location_public_transport}}' => $locationPublicTransport,
            '{{event_location_notes}}' => $locationNotes,
            '{{event_location_capacity}}' => $locationCapacity,
            '{{event_location_contact_email}}' => $locationContactEmail,
            '{{event_location_latitude}}' => $locationLatitude,
            '{{event_location_longitude}}' => $locationLongitude,
            '{{event_title}}' => $eventTitle,
            '{{event_date}}' => $eventDate,
            '{{event_time}}' => $eventTime,
            '{{event_date_utc}}' => $eventDateUtc,
            '{{event_time_utc}}' => $eventTimeUtc,
            '{{event_location_url}}' => $locationUrl,
            '{{event_location_url_html}}' => $locationUrl ? '<a href="' . $locationUrl . '" rel="noreferrer">' . $locationUrl . '</a>' : '',
            '{{event_url}}' => $next instanceof Event ? (string) ($next->url ?? '') : '',
            '{{attach_event_ical}}' => '',
            '{{ical_event_uuid}}' => $next instanceof Event && $next->uuid ? (string) $next->uuid : '',
            // iCal-specific placeholders
            '{{ical_dtstamp}}' => now()->setTimezone($timezone)->format('Ymd\THis'),
            '{{ical_dtstart}}' => $next?->start_at?->copy()->setTimezone($timezone)->format('Ymd\THis') ?? '',
            '{{ical_dtend}}' => ($next?->end_at ? $next->end_at->copy()->setTimezone($timezone) : ($next?->start_at?->copy()->setTimezone($timezone)->addHour()))?->format('Ymd\THis') ?? '',
            '{{ical_uid}}' => $next instanceof Event ? (($next->uuid ? (string) $next->uuid : 'event-'.$next->id).'@'.parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost') : '',
            '{{ical_timezone}}' => $timezone,
            '{{event_notes}}' => $next instanceof Event ? (string) ($next->notes ?? '') : '',
            // iCal-specific aliases for backward compatibility
            '{{ical_event_title}}' => $next instanceof Event ? (string) ($next->title ?? '') : '',
            '{{ical_event_location}}' => $next instanceof Event ? (string) ($next->location_id ? ($next->location()?->name ?? '') : ($next->getAttributes()['location'] ?? '')) : '',
            '{{ical_event_notes}}' => $next instanceof Event ? (string) ($next->notes ?? '') : '',
            '{{ical_event_url}}' => $next instanceof Event ? (string) ($next->url ?? '') : '',
            // iCal date/time components
            '{{ical_dtstart_date}}' => $next?->start_at?->copy()->setTimezone($timezone)->format('Y-m-d') ?? '',
            '{{ical_dtstart_time}}' => $next?->start_at?->copy()->setTimezone($timezone)->format('H:i') ?? '',
            '{{ical_dtend_date}}' => ($next?->end_at ? $next->end_at->copy()->setTimezone($timezone) : ($next?->start_at?->copy()->setTimezone($timezone)->addHour()))?->format('Y-m-d') ?? '',
            '{{ical_dtend_time}}' => ($next?->end_at ? $next->end_at->copy()->setTimezone($timezone) : ($next?->start_at?->copy()->setTimezone($timezone)->addHour()))?->format('H:i') ?? '',
            // iCal location aliases
            '{{ical_event_location_name}}' => $eventLocationName,
            '{{ical_event_city}}' => $eventCity,
            '{{ical_event_location_address}}' => $locationAddress,
            '{{ical_event_location_public_transport}}' => $locationPublicTransport,
            '{{ical_event_location_notes}}' => $locationNotes,
            '{{ical_event_location_capacity}}' => $locationCapacity,
            '{{ical_event_location_contact_email}}' => $locationContactEmail,
            '{{ical_event_location_url}}' => $locationUrl,
            '{{ical_event_location_url_html}}' => $locationUrl ? '<a href="' . $locationUrl . '" rel="noreferrer">' . $locationUrl . '</a>' : '',
            '{{ical_event_location_latitude}}' => $locationLatitude,
            '{{ical_event_location_longitude}}' => $locationLongitude,
            // iCal date/time in UTC
            '{{ical_event_date_utc}}' => $eventDateUtc,
            '{{ical_event_time_utc}}' => $eventTimeUtc,
            // iCal date/time in configured format
            '{{ical_event_date}}' => $eventDate,
            '{{ical_event_time}}' => $eventTime,
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
        
        // Build privacy and FAQ links using LinkBuildingService to append guest token
        $linkBuilder = app(\App\Services\LinkBuildingService::class);
        $privacyParams = [];
        $faqParams = [];
        if ($guestToken) {
            $privacyParams['t'] = $guestToken;
            $faqParams['t'] = $guestToken;
        }
        $privacyLink = $linkBuilder->appendQuery($siteBaseUrl . '/privacy', $privacyParams);
        $faqLink = $linkBuilder->appendQuery($siteBaseUrl . '/faq', $faqParams);
        // Get current locale
        $locale = app()->getLocale();
        // Fallback to 'faq_button_text_label' or 'faq_title' translation key
        $faqLabel = $this->translationService->getTranslations($locale)['faq_button_text_label']
            ?? ($this->translationService->getTranslations($locale)['faq_title'] ?? 'FAQ');
        
        $recipientTokens = [
            '{{name}}' => $recipient['name'] ?? '',
            '{{email}}' => $recipient['email'] ?? '',
            '{{undo_link}}' => $recipient['undo_link'] ?? '',
            '{{undo_link_html}}' => $recipient['undo_link_html'] ?? '',
            '{{validation_link}}' => $recipient['validation_link'] ?? '',
            '{{validation_link_html}}' => $recipient['validation_link_html'] ?? '',
            '{{admin_approval_link}}' => $recipient['admin_approval_link'] ?? '',
            '{{admin_approval_link_html}}' => $recipient['admin_approval_link_html'] ?? '',
            '{{survey_link}}' => $recipient['survey_link'] ?? '',
            '{{survey_link_html}}' => $recipient['survey_link_html'] ?? '',
            '{{privacy_link}}' => $privacyLink,
            '{{privacy_link_html}}' => '<a href="' . $privacyLink . '">' . $privacyLink . '</a>',
            '{{faq_link}}' => $faqLink,
            '{{faq_link_html}}' => '<a href="' . $faqLink . '">' . $faqLink . '</a>',
            '{{faq_link_translated}}' => $faqLabel,
            '{{faq_link_html_translated}}' => '<a href="' . $faqLink . '">' . $faqLabel . '</a>',
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
        $tokens = array_merge($tokens, self::RECIPIENT_TOKENS, self::SITE_TOKENS, self::CONTEXT_TOKENS, ['{{event_location_url_html}}']);
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

    /**
     * Set survey link placeholders for email templates.
     *
     * @param Survey $survey The survey object
     * @param string|null $token Optional token for the response link
     */
    public function setSurveyLink(Survey $survey, ?string $token = null): self
    {
        $linkBuilder = app(LinkBuildingService::class);
        $surveyLink = $linkBuilder->buildSurveyLink($survey->id, $token ?? (string) \Illuminate\Support\Str::uuid());
        
        $this->contextPlaceholders['survey_link'] = $surveyLink;
        $this->contextPlaceholders['survey_link_html'] = '<a href="' . $surveyLink . '">' . $surveyLink . '</a>';
        
        return $this;
    }
}
