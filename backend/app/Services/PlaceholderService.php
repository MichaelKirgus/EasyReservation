<?php

namespace App\Services;

use App\Models\Event;
use App\Models\FormField;
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
            '{{recipient_email}}',
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
        '{{changed_settings}}',
        '{{changed_by}}',
        '{{user_name}}',
        '{{user_email}}',
        '{{login_identifier}}',
        '{{login_ip}}',
    ];

    private const RUNTIME_TOKENS = [
        '{{random_alnum}}',
        '{{random_alnum_8}}',
        '{{random_numeric}}',
        '{{random_numeric_8}}',
    ];

    /**
     * Context-specific placeholders set externally (e.g. from EventTriggerService).
     */
    private array $contextPlaceholders = [];

    /**
     * Survey context for survey-specific placeholders.
     */
    private ?\App\Models\Survey $surveyContext = null;

    private const SITE_TOKENS = [
        '{{site_base_url}}',
        '{{site_guest_token}}',
        '{{rss_feed_link}}',
    ];

    private const SURVEY_TOKENS = [
        '{{survey_title}}',
        '{{survey_submission_message}}',
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
        // Extract payload from recipient if present (for form field placeholders)
        $payload = $recipient['payload'] ?? [];
        
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
            '{{ical_event_uuid}}' => $next instanceof Event && $next->uuid ? (string) $next->uuid : '',
            // iCal-specific placeholders
            '{{ical_dtstamp}}' => now()->setTimezone($timezone)->format('Ymd\THis'),
            '{{ical_dtstart}}' => $next?->start_at?->copy()->setTimezone($timezone)->format('Ymd\THis') ?? '',
            '{{ical_dtend}}' => ($next?->end_at ? $next->end_at->copy()->setTimezone($timezone) : ($next?->start_at?->copy()->setTimezone($timezone)->addHour()))?->format('Ymd\THis') ?? '',
            '{{ical_timezone}}' => $timezone,
            '{{event_notes}}' => $next instanceof Event ? (string) ($next->notes ?? '') : '',
            // iCal date/time components
            '{{ical_dtstart_date}}' => $next?->start_at?->copy()->setTimezone($timezone)->format('Y-m-d') ?? '',
            '{{ical_dtstart_time}}' => $next?->start_at?->copy()->setTimezone($timezone)->format('H:i') ?? '',
            '{{ical_dtend_date}}' => ($next?->end_at ? $next->end_at->copy()->setTimezone($timezone) : ($next?->start_at?->copy()->setTimezone($timezone)->addHour()))?->format('Y-m-d') ?? '',
            '{{ical_dtend_time}}' => ($next?->end_at ? $next->end_at->copy()->setTimezone($timezone) : ($next?->start_at?->copy()->setTimezone($timezone)->addHour()))?->format('H:i') ?? '',
            // iCal date/time in UTC
            '{{ical_event_date_utc}}' => $eventDateUtc,
            '{{ical_event_time_utc}}' => $eventTimeUtc,
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
        $core['{{rss_feed_link}}'] = $linkBuilder->buildPublicRssFeedLink();
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
                    '{{recipient_email}}' => $recipient['email'] ?? '',
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
        
        // Form field placeholders from payload
        $formFieldTokens = $this->getFormFieldPlaceholders($payload);
        
        // Context placeholders (e.g. error_message from event triggers)
        $contextTokens = [
            '{{error_message}}' => $this->contextPlaceholders['error_message'] ?? '',
            '{{changed_settings}}' => $this->contextPlaceholders['changed_settings'] ?? '',
            '{{changed_by}}' => $this->contextPlaceholders['changed_by'] ?? '',
            '{{user_name}}' => $this->contextPlaceholders['user_name'] ?? '',
            '{{user_email}}' => $this->contextPlaceholders['user_email'] ?? '',
            '{{login_identifier}}' => $this->contextPlaceholders['login_identifier'] ?? '',
            '{{login_ip}}' => $this->contextPlaceholders['login_ip'] ?? '',
        ];

        // Survey-specific placeholders
        $surveyTokens = [];
        if ($this->surveyContext) {
            $surveyTokens = [
                '{{survey_title}}' => $this->surveyContext->title ?? '',
            ];
        }

        // Reihenfolge: custom < core < formFieldTokens < context < survey < recipientTokens
        return array_merge($custom, $core, $formFieldTokens, $contextTokens, $surveyTokens, $recipientTokens);
    }

    public function tokens(): array
    {
        $tokens = array_keys($this->replacements());
        $tokens = array_merge($tokens, self::RECIPIENT_TOKENS, self::SITE_TOKENS, self::CONTEXT_TOKENS, self::RUNTIME_TOKENS, self::SURVEY_TOKENS, ['{{event_location_url_html}}']);
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

        $replaced = strtr($value, $this->replacements());

        return $this->replaceRuntimePlaceholders($replaced);
    }

    private function replaceRuntimePlaceholders(string $value): string
    {
        return (string) preg_replace_callback(
            '/\{\{random_(?:alnum|numeric)(?:_([1-8])|:(\d{1,2}))?\}\}/',
            function (array $matches): string {
                $length = 8;

                if (isset($matches[1]) && $matches[1] !== '') {
                    $length = (int) $matches[1];
                } elseif (isset($matches[2]) && $matches[2] !== '') {
                    $length = (int) $matches[2];
                }

                return $this->generateRandomLowercaseAlnumToken($length);
            },
            $value
        );
    }

    private function generateRandomLowercaseAlnumToken(int $length): string
    {
        $normalizedLength = max(1, min(8, $length));
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $maxIndex = strlen($alphabet) - 1;

        $token = '';
        for ($i = 0; $i < $normalizedLength; $i++) {
            $token .= $alphabet[random_int(0, $maxIndex)];
        }

        return $token;
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

    /**
     * Get form field placeholders from payload.
     * Form fields are defined in FormField model with 'key' attribute.
     * Placeholder format: {{form_field__{key}}}
     *
     * @param array $payload The payload array containing form field values
     */
    private function getFormFieldPlaceholders(array $payload): array
    {
        $placeholders = [];
        
        // Get all active form fields that are visible (for reference)
        $formFields = FormField::query()->where('active', true)->get(['key']);
        
        foreach ($formFields as $field) {
            $key = $field->key ?? '';
            if ($key === '') continue;
            
            $placeholderKey = '{{form_field__' . $key . '}}';
            $value = $payload[$key] ?? '';
            $placeholders[$placeholderKey] = (string) $value;
        }
        
        return $placeholders;
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
    /**
     * Set event placeholders for the current request/trigger.
     *
     * @param Event $event The event object
     */
    public function setEvent(\App\Models\Event $event): self
    {
        $this->contextPlaceholders['event_title'] = $event->title ?? '';
        $this->contextPlaceholders['event_start_at'] = $event->start_at?->toIso8601String() ?? '';
        $this->contextPlaceholders['event_end_at'] = $event->end_at?->toIso8601String() ?? '';
        $this->contextPlaceholders['event_location_id'] = $event->location_id ?? '';
        
        if ($event->location) {
            $this->contextPlaceholders['event_location_name'] = $event->location->name ?? '';
            $this->contextPlaceholders['event_location_address'] = $event->location->address ?? '';
        }
        
        return $this;
    }
  
    /**
     * Set reservation placeholders for the current request/trigger.
     *
     * @param Reservation $reservation The reservation object
     */
    public function setReservation(\App\Models\Reservation $reservation): self
    {
        $this->contextPlaceholders['reservation_name'] = $reservation->display_name ?? '';
        $this->contextPlaceholders['reservation_email'] = $reservation->email ?? '';
        $this->contextPlaceholders['reservation_created_at'] = $reservation->created_at?->toIso8601String() ?? '';
        
        return $this;
    }
  
    /**
     * Set user placeholders for the current request/trigger.
     *
     * @param User $user The user object
     */
    public function setUser(\App\Models\User $user): self
    {
        $this->contextPlaceholders['user_name'] = $user->name ?? '';
        $this->contextPlaceholders['user_email'] = $user->email ?? '';
        
        return $this;
    }
  
    public function setSurveyLink(Survey $survey, ?string $token = null): self
    {
        $linkBuilder = app(LinkBuildingService::class);
        $surveyLink = $linkBuilder->buildSurveyLink($survey->id, $token ?? (string) \Illuminate\Support\Str::uuid());
        
        $this->contextPlaceholders['survey_link'] = $surveyLink;
        $this->contextPlaceholders['survey_link_html'] = '<a href="' . $surveyLink . '">' . $surveyLink . '</a>';
        
        return $this;
    }

    /**
     * Set survey context for survey-specific placeholders.
     *
     * @param Survey $survey The survey object
     */
    public function setSurvey(\App\Models\Survey $survey): self
    {
        $this->surveyContext = $survey;
        $this->contextPlaceholders['survey_title'] = $survey->title ?? '';
        
        return $this;
    }

    /**
     * Clear survey context.
     */
    public function clearSurveyContext(): self
    {
        $this->surveyContext = null;
        unset($this->contextPlaceholders['survey_title']);
        
        return $this;
    }
}
