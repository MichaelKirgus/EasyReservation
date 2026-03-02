<?php

namespace App\Services;

use App\Jobs\SendMailJob;
use App\Models\EmailTemplate;
use App\Models\EmailValidation;
use App\Models\MailTransportGroup;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Request;
use App\Services\PlaceholderService;
use App\Services\EmailService;
use App\Services\MailTransportService;
use App\Services\RateLimitCacheService;

class EmailValidationService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly WaitlistService $waitlist,
        private readonly EventService $events,
        private readonly EmailBroadcastService $mailer,
        private readonly ReservationValidationService $validator,
        private readonly IcsService $ics,
        private readonly PlaceholderService $placeholders,
        private readonly EmailService $emailService,
        private readonly MailTransportService $mailTransportService,
        private readonly RateLimitCacheService $rateLimitCache,
    ) {
    }

    public function emailValidationEnabled(): bool
    {
        return (int) ($this->settings->get('email_validation_enabled', 0) ?? 0) === 1;
    }

    public function adminApprovalEnabled(): bool
    {
        return (int) ($this->settings->get('email_validation_admin_enabled', 0) ?? 0) === 1;
    }

    private function getClientIp(): string
    {
        $forwarded = Request::server($this->settings->get('email_validation_rate_limit_header', 'HTTP_X_FORWARDED_FOR'));
        if ($forwarded) {
            $ips = explode(',', $forwarded);
            return trim($ips[0]);
        }
        return Request::ip();
    }

    private function checkRateLimit(string $ip): void
    {
        $limit = (int)($this->settings->get('email_validation_rate_limit_per_hour', 5) ?? 5);
        if ($limit <= 0) {
            return; // No limit
        }
        $key = 'email_validation_rate:' . $ip . ':' . now()->format('YmdH');
        $count = $this->rateLimitCache->get($key, 0);
        if ($count >= $limit) {
            throw new \RuntimeException('email_validation_rate_limit');
        }
        $this->rateLimitCache->put($key, $count + 1, now()->addHour());
    }

    /**
     * Check the separate rate limit for admin approval notification emails.
     * Uses the setting 'email_validation_admin_rate_limit_per_hour' (default 10).
     * If the limit is <= 0, rate limiting is disabled.
     */
    private function checkAdminApprovalRateLimit(): void
    {
        $limit = (int)($this->settings->get('email_validation_admin_rate_limit_per_hour', 10) ?? 10);
        if ($limit <= 0) {
            return; // No limit
        }
        $key = 'email_validation_admin_rate:global:' . now()->format('YmdH');
        $count = $this->rateLimitCache->get($key, 0);
        if ($count >= $limit) {
            Log::warning('Admin approval email rate limit reached', ['count' => $count, 'limit' => $limit]);
            return;
        }
        $this->rateLimitCache->put($key, $count + 1, now()->addHour());
    }

    /**
     * Send admin approval notification email for a validation that is waiting for admin approval.
     * Respects a separate rate limit for admin approval emails.
     */
    private function sendAdminApprovalNotification(EmailValidation $validation): void
    {
        try {
            $this->checkAdminApprovalRateLimit();

            // Get transport group ID from admin approval template setting
            $templateId = (int) ($this->settings->get('email_validation_admin_template_id') ?? 0);
            $transportGroupId = $this->getTransportGroupIdFromSetting($templateId);

            if (!$transportGroupId) {
                Log::error('EmailValidationService: No transport group ID found for admin approval email');
                return;
            }

            // Use MailTransportService for failover support
            $this->emailService->sendAdminApprovalEmail($transportGroupId, $validation);
        } catch (\Throwable $e) {
            Log::warning('Admin approval notification email failed', [
                'error' => $e->getMessage(),
                'validation_id' => is_object($validation) && isset($validation->id) ? $validation->id : null,
                'validation_type' => gettype($validation),
                'validation_value' => $validation,
            ]);
        }
    }

    public function createRequest(string $type, string $name, ?string $email, array $payload = [], ?string $siteToken = null): EmailValidation
    {
        Log::debug('createRequest: start', [
            'type' => $type,
            'name' => $name,
            'email' => $email,
            'payload' => $payload,
            'siteToken' => $siteToken,
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10),
        ]);
        Log::debug('createRequest called', [
            'type' => $type,
            'name' => $name,
            'email' => $email,
            'payload' => $payload,
            'siteToken' => $siteToken,
        ]);
        Log::debug('createRequest: entering', [
            'type' => $type,
            'name' => $name,
            'email' => $email,
            'payload' => $payload,
            'siteToken' => $siteToken,
        ]);
        $requiresEmail = $this->emailValidationEnabled();
        $requiresAdmin = $this->adminApprovalEnabled();

        // Rate-Limit check — apply when any validation is active
        if ($requiresEmail || $requiresAdmin) {
            $ip = $this->getClientIp();
            $this->checkRateLimit($ip);
        }

        $name = trim($name);
        $email = trim((string) $email);

        $user = auth()->user();

        if ($type === 'reservation') {
            $adminExempt = (int)($this->settings->get('reservation_duplicate_check_admin_exempt', 0) ?? 0) === 1;
            $isAdminOrMod = $user && in_array($user->role, ['admin', 'moderator', 'superadmin']);
            $skipDuplicateCheck = $adminExempt && $isAdminOrMod;
            $allowDuplicateName = (int)($this->settings->get('reservation_allow_duplicate_name', 0) ?? 0) === 1;
            $allowDuplicateEmail = (int)($this->settings->get('reservation_allow_duplicate_email', 0) ?? 0) === 1;
            if (! $skipDuplicateCheck) {
                if (! $allowDuplicateName) {
                    $duplicateName = Reservation::query()
                        ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])
                        ->exists();
                    if ($duplicateName) {
                        throw new \RuntimeException(__('name_already_reserved'));
                    }
                }
                if (! $allowDuplicateEmail && $email !== null && $email !== '') {
                    $duplicateEmail = $this->reservationEmailExists($email);
                    if ($duplicateEmail) {
                        throw new \RuntimeException(__('email_already_reserved'));
                    }
                }
            }
        } else if ($type === 'waitlist') {
            $adminExempt = (int)($this->settings->get('waitlist_duplicate_check_admin_exempt', 0) ?? 0) === 1;
            $isAdminOrMod = $user && in_array($user->role, ['admin', 'moderator', 'superadmin']);
            $skipDuplicateCheck = $adminExempt && $isAdminOrMod;
            $allowDuplicateName = (int)($this->settings->get('waitlist_allow_duplicate_name', 0) ?? 0) === 1;
            $allowDuplicateEmail = (int)($this->settings->get('waitlist_allow_duplicate_email', 0) ?? 0) === 1;
            if (! $skipDuplicateCheck) {
                if (! $allowDuplicateName) {
                    $duplicateName = WaitlistEntry::query()
                        ->where('status', 'pending')
                        ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])
                        ->exists();
                    if ($duplicateName) {
                        throw new \RuntimeException(__('name_already_on_waitlist'));
                    }
                }
                if (! $allowDuplicateEmail && $email !== null && $email !== '') {
                    $duplicateEmail = $this->waitlistEmailExists($email);
                    if ($duplicateEmail) {
                        throw new \RuntimeException(__('email_already_on_waitlist'));
                    }
                }
            }
        }

        $validation = EmailValidation::create([
            'type' => $type,
            'display_name' => $name,
            'email' => $email ?: null,
            'payload' => $payload,
            'token' => $requiresEmail ? (string) Str::uuid() : null,
            'status' => $requiresEmail ? 'email_pending' : ($requiresAdmin ? 'waiting_admin' : 'ready'),
            'requires_admin_approval' => $requiresAdmin,
            'site_token' => $siteToken,
            'expires_at' => $requiresEmail ? now()->addMinutes((int) ($this->settings->get('email_validation_ttl_minutes', 1440))) : null,
            'validated_at' => $requiresEmail ? null : now(),
        ]);
        Log::debug('createRequest after EmailValidation::create', [
            'validation_type' => gettype($validation),
            'validation_class' => is_object($validation) ? get_class($validation) : null,
            'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
        ]);

        // Defensive: log before every return
        Log::debug('createRequest: about to return', [
            'validation_type' => gettype($validation),
            'validation_class' => is_object($validation) ? get_class($validation) : null,
            'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
            'debug_backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10),
        ]);

        if ($requiresEmail) {
            Log::debug('createRequest before return (requiresEmail)', [
                'validation_type' => gettype($validation),
                'validation_class' => is_object($validation) ? get_class($validation) : null,
                'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
            ]);
            $this->sendValidationEmail($validation);
        }

        // Admin-only mode (no email validation): send admin approval notification immediately
        if (! $requiresEmail && $requiresAdmin) {
            Log::debug('createRequest before return (requiresAdmin)', [
                'validation_type' => gettype($validation),
                'validation_class' => is_object($validation) ? get_class($validation) : null,
                'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
            ]);
            $this->sendAdminApprovalNotification($validation);
        }

        if (! $requiresEmail && ! $requiresAdmin) {
            // Only call finalize for side effects, do not return its result here
            $this->finalize($validation);
            Log::debug('createRequest before return (no validation required)', [
                'validation_type' => gettype($validation),
                'validation_class' => is_object($validation) ? get_class($validation) : null,
                'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
            ]);
        }

        // Always return the EmailValidation Eloquent object, never an array
        Log::debug('createRequest final return', [
            'validation_type' => gettype($validation),
            'validation_class' => is_object($validation) ? get_class($validation) : null,
            'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
        ]);
        return $validation;
    }

    public function verifyToken(string $token): array
    {
        /** @var EmailValidation|null $validation */
        $validation = EmailValidation::query()->where('token', $token)->first();

        if (! $validation) {
            throw new \RuntimeException(__("validation_token_not_found"));
        }

        if ($validation->expires_at && now()->greaterThan($validation->expires_at)) {
            $validation->status = 'expired';
            $validation->last_error = 'Token abgelaufen';
            $validation->save();
            throw new \RuntimeException(__("validation_link_expired"));
        }

        if (in_array($validation->status, ['completed', 'cancelled', 'expired', 'failed'])) {
            throw new \RuntimeException(__("validation_link_already_used"));
        }

        if ($validation->validated_at) {
            if ($validation->status === 'completed') {
                throw new \RuntimeException(__("validation_link_already_used"));
            }
        }

        $validation->validated_at = now();
        $validation->status = $validation->requires_admin_approval ? 'waiting_admin' : 'ready';
        $validation->save();

        if (! $validation->requires_admin_approval) {
            // Finalize and return consistent array for reservation/waitlist
            $result = $this->finalize($validation);
            $isWaitlist = $validation->type === 'waitlist'
                || ($result instanceof \App\Models\EmailValidation && $result->type === 'waitlist')
                || (is_array($result) && (($result['type'] ?? null) === 'waitlist' || !empty($result['waitlist_entry_id'])));
            // $result may be EmailValidation (reservation) or array (waitlist)
            if ($result instanceof \App\Models\EmailValidation) {
                return [
                    'reservation' => $result,
                    'waitlist' => $isWaitlist,
                    'pending_admin' => false,
                ];
            } elseif (is_array($result)) {
                // Waitlist flow
                return $result + [
                    'pending_admin' => false,
                    'waitlist' => $isWaitlist,
                ];
            } else {
                // Fallback
                return [
                    'reservation' => null,
                    'waitlist' => $isWaitlist,
                    'pending_admin' => false,
                ];
            }
        }

        // Email validated, now waiting for admin approval — send notification to admin
        $this->sendAdminApprovalNotification($validation);
        return [
            'pending_admin' => true,
            'validation' => $validation,
        ];
    }

    public function approve(EmailValidation $validation): array
    {
        if ($validation->status === 'completed') {
            return ['status' => 'completed', 'validation' => $validation];
        }

        if (! $validation->requires_admin_approval) {
            return $this->finalize($validation);
        }

        $validation->approved_at = now();
        $validation->status = 'ready';
        $validation->save();

        return $this->finalize($validation);
    }

    private function finalize(EmailValidation $validation): array
    {
        return DB::transaction(function () use ($validation) {
            Log::debug('finalize: entered', [
                'validation_type' => gettype($validation),
                'validation_class' => is_object($validation) ? get_class($validation) : null,
                'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
            ]);
            if ($validation->status === 'completed') {
                Log::debug('finalize: returning completed', [
                    'validation_type' => gettype($validation),
                    'validation_class' => is_object($validation) ? get_class($validation) : null,
                    'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
                ]);
                return is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : (array)$validation;
            }

            try {
                if ($validation->type === 'waitlist') {
                    $entry = $this->waitlist->addToWaitlist($validation->display_name, $validation->email, $validation->payload ?? []);
                    $validation->waitlist_entry_id = $entry->id;
                    $this->sendWaitlistValidationSuccessEmail($entry);
                    Log::debug('finalize: returning waitlist', [
                        'entry_type' => gettype($entry),
                        'entry_class' => is_object($entry) ? get_class($entry) : null,
                        'entry' => is_object($entry) && method_exists($entry, 'toArray') ? $entry->toArray() : $entry,
                        'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
                    ]);
                } else {
                    $reservation = $this->createReservationOrWaitlist($validation);
                    if ($reservation instanceof WaitlistEntry) {
                        $validation->waitlist_entry_id = $reservation->id;
                        $this->sendWaitlistValidationSuccessEmail($reservation);
                        Log::debug('finalize: returning reservation as waitlist', [
                            'reservation_type' => gettype($reservation),
                            'reservation_class' => is_object($reservation) ? get_class($reservation) : null,
                            'reservation' => is_object($reservation) && method_exists($reservation, 'toArray') ? $reservation->toArray() : $reservation,
                            'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
                        ]);
                    } else {
                        $validation->reservation_id = $reservation->id;
                        $this->sendReservationNotification($reservation, 'email_reservation_success_template_id', true);

                        // Trigger: reservation_added (after reservation is created via email validation)
                        app(\App\Services\EventTriggerService::class)->handle('reservation_added', ['reservation' => $reservation]);
                        app(\App\Services\EventTriggerService::class)->handle('reservation_enabled', ['reservation' => $reservation]);
                        Log::debug('finalize: returning reservation', [
                            'reservation_type' => gettype($reservation),
                            'reservation_class' => is_object($reservation) ? get_class($reservation) : null,
                            'reservation' => is_object($reservation) && method_exists($reservation, 'toArray') ? $reservation->toArray() : $reservation,
                            'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
                        ]);
                    }
                }

                $validation->status = 'completed';
                $validation->completed_at = now();
                $validation->last_error = null;
                $validation->save();

                Log::debug('finalize: about to return', [
                    'validation_type' => gettype($validation),
                    'validation_class' => is_object($validation) ? get_class($validation) : null,
                    'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
                ]);
                return is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : (array)$validation;
            } catch (\Throwable $e) {
                $validation->last_error = $e->getMessage();
                $validation->status = 'failed';
                $validation->save();
                Log::warning('Email validation finalize failed', ['error' => $e->getMessage()]);
                throw $e;
            }
        });
    }

    private function createReservationOrWaitlist(EmailValidation $validation): Reservation|WaitlistEntry
    {
        $name = $validation->display_name;
        $email = $validation->email;
        $payload = $validation->payload ?? [];

        $overflowEnabled = (int) ($this->settings->get('waitlist_overflow_enabled', 1) ?? 1) === 1;
        $enabled = (int) ($this->settings->get('reservation_enabled', 0) ?? 0) === 1;
        if (! $enabled) {
            if ($overflowEnabled && $this->waitlist->waitlistEnabled()) {
                return $this->waitlist->addToWaitlist($name, $email, $payload);
            }
            throw new \RuntimeException(__('reservation_disabled'));
        }

        if (! $this->validator->nameIsValid($name)) {
            throw new \RuntimeException(__('name_invalid'));
        }

        if (! $this->validator->emailIsValid($email)) {
            throw new \RuntimeException(__('email_invalid'));
        }

        $siteToken = $validation->site_token ?? null;
        $allowDuplicateEmail = (int)($this->settings->get('reservation_allow_duplicate_email', 0) ?? 0) === 1;
        $allowDuplicateName = (int)($this->settings->get('reservation_allow_duplicate_name', 0) ?? 0) === 1;
        return DB::transaction(function () use ($name, $email, $payload, $overflowEnabled, $siteToken, $allowDuplicateEmail, $allowDuplicateName) {
            $max = (int) ($this->settings->get('reservation_max', 0) ?? 0);

            $current = Reservation::query()->lockForUpdate()->count();
            if ($max > 0 && $current >= $max) {
                if ($overflowEnabled && $this->waitlist->waitlistEnabled()) {
                    return $this->waitlist->addToWaitlist($name, $email, $payload);
                }
                throw new \RuntimeException(__('reservation_limit_reached'));
            }

            if (! $allowDuplicateName) {
                $duplicateName = Reservation::query()
                    ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])
                    ->lockForUpdate()
                    ->exists();
                if ($duplicateName) {
                    throw new \RuntimeException(__('reservation_name_already_reserved'));
                }
            }

            if (! $allowDuplicateEmail && $email !== null && $email !== '') {
                $duplicateEmail = $this->reservationEmailExists($email, true);
                if ($duplicateEmail) {
                    throw new \RuntimeException(__('email_already_reserved'));
                }
            }

            return Reservation::create([
                'display_name' => $name,
                'email' => $email === '' ? null : $email,
                'payload' => $payload,
                'undo_token' => (string) Str::uuid(),
                'site_token' => $siteToken,
            ]);
        });
    }

    public function resendValidationEmail(EmailValidation $validation): void
    {
        if ($validation->status === 'completed') {
            throw new \RuntimeException(__('validation_completed'));
        }
        $this->sendValidationEmail($validation);
    }

    private function getTransportGroupIdFromSetting(?int $templateId): ?int
    {
        if (! $templateId) {
            return null;
        }
        
        // Get the template to check for transport_group_id
        $template = EmailTemplate::query()->with('transportGroup')->find($templateId);
        return $template && $template->transport_group_id ? $template->transport_group_id : null;
    }

    private function sendValidationEmail(EmailValidation $validation): void
    {
        Log::debug('sendValidationEmail: entered', [
            'validation_type' => gettype($validation),
            'validation_class' => is_object($validation) ? get_class($validation) : null,
            'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
        ]);
        // Get transport group ID from validation template setting
        $templateId = (int) ($this->settings->get('email_validation_template_id') ?? 0);
        $transportGroupId = $this->getTransportGroupIdFromSetting($templateId);
        
        if (!$transportGroupId) {
            Log::error('EmailValidationService: No transport group ID found for validation email');
            throw new \RuntimeException(__('mail_server_not_configured'));
        }
        
        // Use MailTransportService for failover support
        $this->emailService->sendValidationEmail($transportGroupId, $validation);
    }


    private function renderTemplate(string $template, array $replacements): string
    {
        return strtr($template, $replacements);
    }

    private function resolveTemplate(): array
    {
        $templateId = $this->settings->get('email_validation_template_id');
        $template = $templateId ? EmailTemplate::query()->find($templateId) : null;

        $subject = 'Bitte E-Mail bestätigen';
        $body = <<<HTML
<p>Hallo {{name}},</p>
<p>bitte bestätige deine E-Mail-Adresse, um die Reservierung abzuschliessen.</p>
<p><a href="{{validation_link}}">E-Mail bestätigen</a></p>
<p>Falls der Link nicht klickbar ist, kopiere ihn in die Adresszeile: {{validation_link}}</p>
HTML;

        if ($template) {
            $subject = $template->subject;
            $body = $template->body;
        }

        return ['subject' => $subject, 'body' => $body];
    }

    private function resolveTemplateById(?int $templateId, string $defaultSubject, string $defaultBody): array
    {
        $template = $templateId ? EmailTemplate::query()->find($templateId) : null;
        if ($template) {
            return ['subject' => $template->subject, 'body' => $template->body];
        }

        return ['subject' => $defaultSubject, 'body' => $defaultBody];
    }

    private function buildMailerConfigWithTransportGroup(?int $transportGroupId): ?array
    {
        if (!$transportGroupId) {
            Log::error('EmailValidationService: No transport group ID provided for mailer config');
            return null;
        }
        
        // Use MailTransportService to get config from transport group
        return $this->mailTransportService->buildMailerConfigFromTransportGroup($transportGroupId);
    }

    public function sendReservationNotification(Reservation $reservation, string $templateSettingKey, bool $includeUndoLink): void
    {
        if (! $reservation->email) {
            Log::debug('EmailValidationService: Reservation has no email, skipping notification');
            return;
        }

        $templateId = (int) ($this->settings->get($templateSettingKey, 0) ?? 0);
        if ($templateId <= 0) {
            Log::warning('EmailValidationService: No template configured for reservation notification');
            return;
        }
        
        // Get transport group ID from template
        $transportGroupId = $this->getTransportGroupIdFromSetting($templateId);

        if (!$transportGroupId) {
            Log::error('EmailValidationService: No transport group ID found for reservation notification');
            return;
        }

        // Use MailTransportService for failover support
        $this->emailService->sendReservationNotification($transportGroupId, $reservation, $templateSettingKey, $includeUndoLink);
    }

    /**
     * Check if a reservation with the given email exists (email is stored encrypted).
     * When $lock is true, rows are locked for update to avoid races during creation.
     */
    private function reservationEmailExists(string $email, bool $lock = false): bool
    {
        $query = Reservation::query();
        if ($lock) {
            $query->lockForUpdate();
        }

        $reservations = $query->get(['id', 'email']);

        $lower = mb_strtolower($email);

        return $reservations->contains(function (Reservation $reservation) use ($lower) {
            return mb_strtolower((string) ($reservation->email ?? '')) === $lower;
        });
    }

    /**
     * Check if a pending waitlist entry with the given email exists (email is stored encrypted).
     */
    private function waitlistEmailExists(string $email): bool
    {
        $entries = WaitlistEntry::query()
            ->where('status', 'pending')
            ->get(['id', 'email']);

        $lower = mb_strtolower($email);

        return $entries->contains(function (WaitlistEntry $entry) use ($lower) {
            return mb_strtolower((string) ($entry->email ?? '')) === $lower;
        });
    }

    private function baseReplacements(array $overrides = []): array
    {
        $base = [
            '{{reservation_name}}' => (string) $this->settings->get('reservation_name', ''),
            '{{next_event}}' => $this->resolveNextEventText(),
            '{{upcoming_events}}' => $this->resolveUpcomingEventsList(),
            '{{upcoming_events_without_next}}' => $this->resolveUpcomingEventsWithoutNextList(),
            '{{upcoming_event_dates_without_next}}' => $this->resolveUpcomingEventDatesWithoutNextList(),
            '{{event_location}}' => $this->resolveEventLocation(),
            '{{event_title}}' => $this->resolveEventTitle(),
            '{{event_date}}' => $this->resolveEventDate(),
            '{{event_time}}' => $this->resolveEventTime(),
            '{{event_url}}' => $this->resolveEventUrl(),
            // Use location data instead of event data
            '{{event_location_city}}' => $this->resolveEventLocationCity(),
            '{{event_location_public_transport}}' => $this->resolveEventLocationPublicTransport(),
            '{{attach_event_ical}}' => '',
        ];

        return $overrides + $base;
    }

    private function resolveNextEventText(): string
    {
        $next = $this->events->next();
        return $next ? $this->events->format($next) : '';
    }

    private function resolveUpcomingEventsList(): string
    {
        $list = $this->events->upcoming()->map(fn ($e) => (string) ($e->title ?? ''))->filter(fn ($v) => $v !== '')->all();
        return empty($list) ? '' : implode("\n", array_map(fn ($v) => '• '.$v, $list));
    }

    private function resolveUpcomingEventsWithoutNextList(): string
    {
        $list = $this->events->upcoming()->slice(1)->map(fn ($e) => (string) ($e->title ?? ''))->filter(fn ($v) => $v !== '')->all();
        return empty($list) ? '' : implode("\n", array_map(fn ($v) => '• '.$v, $list));
    }

    private function resolveUpcomingEventDatesWithoutNextList(): string
    {
        $format = (string) ($this->settings->get('event_date_format', 'Y-m-d') ?: 'Y-m-d');
        $list = $this->events->upcoming()->slice(1)->map(fn ($e) => $e->start_at?->format($format) ?? '')->filter(fn ($v) => $v !== '')->all();
        return empty($list) ? '' : implode("\n", array_map(fn ($v) => '• '.$v, $list));
    }

    private function resolveEventLocation(): string
    {
        $next = $this->events->next();
        return $next ? (string) ($next->location ?? '') : '';
    }

    private function resolveEventLocationCity(): string
    {
        $next = $this->events->next();
        if (!$next || !$next->location_id) return '';
        $locationModel = $next->location()->first();
        return $locationModel ? (string) ($locationModel->city ?? '') : '';
    }

    private function resolveEventTitle(): string
    {
        $next = $this->events->next();
        return $next ? (string) ($next->title ?? '') : '';
    }

    private function resolveEventDate(): string
    {
        $format = (string) ($this->settings->get('event_date_format', 'Y-m-d') ?: 'Y-m-d');
        $next = $this->events->next();
        return $next?->start_at?->format($format) ?? '';
    }

    private function resolveEventTime(): string
    {
        $format = (string) ($this->settings->get('event_time_format', 'H:i') ?: 'H:i');
        $next = $this->events->next();
        return $next?->start_at?->format($format) ?? '';
    }

    private function resolveEventUrl(): string
    {
        $next = $this->events->next();
        return $next ? (string) ($next->url ?? '') : '';
    }

    private function resolveEventLocationPublicTransport(): string
    {
        $next = $this->events->next();
        if (!$next || !$next->location_id) return '';
        $locationModel = $next->location()->first();
        return $locationModel ? (string) ($locationModel->public_transport ?? '') : '';
    }

    private function sendWaitlistValidationSuccessEmail(WaitlistEntry $entry): void
    {
        $this->waitlist->sendWaitlistValidationSuccessEmail($entry);
    }

    private function attachmentsForTemplate(array $template): array
    {
        if (! $this->templateWantsIcs($template)) {
            return [];
        }

        $ics = $this->ics->nextEventAttachment();
        return $ics ? [$ics] : [];
    }

    private function templateWantsIcs(array $template): bool
    {
        return str_contains($template['subject'] ?? '', '{{attach_event_ical}}')
            || str_contains($template['body'] ?? '', '{{attach_event_ical}}');
    }
}