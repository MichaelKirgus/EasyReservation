<?php

namespace App\Services;

use App\Jobs\SendMailJob;
use App\Models\EmailTemplate;
use App\Models\EmailValidation;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;

class EmailValidationService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly WaitlistService $waitlist,
        private readonly EventService $events,
        private readonly EmailBroadcastService $mailer,
        private readonly ReservationValidationService $validator,
        private readonly IcsService $ics,
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
        $count = Cache::get($key, 0);
        if ($count >= $limit) {
            throw new \RuntimeException('Too many requests from IP, please try again later.');
        }
        Cache::put($key, $count + 1, now()->addHour());
    }

    public function createRequest(string $type, string $name, ?string $email, array $payload = [], ?string $siteToken = null): EmailValidation
    {
        $requiresEmail = $this->emailValidationEnabled();
        $requiresAdmin = $this->adminApprovalEnabled();

        // Rate-Limit check
        if ($requiresEmail) {
            $ip = $this->getClientIp();
            $this->checkRateLimit($ip);
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

        if ($requiresEmail) {
            $this->sendValidationEmail($validation);
        }

        if (! $requiresEmail && ! $requiresAdmin) {
            $this->finalize($validation);
        }

        return $validation;
    }

    public function verifyToken(string $token): array
    {
        /** @var EmailValidation|null $validation */
        $validation = EmailValidation::query()->where('token', $token)->first();

        if (! $validation) {
            throw new \RuntimeException('Token nicht gefunden.');
        }

        if ($validation->expires_at && now()->greaterThan($validation->expires_at)) {
            $validation->status = 'expired';
            $validation->last_error = 'Token abgelaufen';
            $validation->save();
            throw new \RuntimeException('Der Link ist abgelaufen.');
        }

        if (in_array($validation->status, ['completed', 'cancelled', 'expired', 'failed'])) {
            throw new \RuntimeException('Der Link wurde bereits verwendet oder ist abgelaufen.');
        }

        if ($validation->validated_at) {
            if ($validation->status === 'completed') {
                throw new \RuntimeException('Der Link wurde bereits verwendet.');
            }
        }

        $validation->validated_at = now();
        $validation->status = $validation->requires_admin_approval ? 'waiting_admin' : 'ready';
        $validation->save();

        if (! $validation->requires_admin_approval) {
            return $this->finalize($validation);
        }

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
            if ($validation->status === 'completed') {
                return ['status' => 'completed', 'validation' => $validation];
            }

            try {
                if ($validation->type === 'waitlist') {
                    $entry = $this->waitlist->addToWaitlist($validation->display_name, $validation->email, $validation->payload ?? []);
                    $validation->waitlist_entry_id = $entry->id;
                    $result = ['waitlist' => true, 'entry' => $entry];
                    $this->sendWaitlistValidationSuccessEmail($entry);
                } else {
                    $reservation = $this->createReservationOrWaitlist($validation);
                    if ($reservation instanceof WaitlistEntry) {
                        $validation->waitlist_entry_id = $reservation->id;
                        $result = ['waitlist' => true, 'entry' => $reservation];
                        $this->sendWaitlistValidationSuccessEmail($reservation);
                    } else {
                        $validation->reservation_id = $reservation->id;
                        $result = ['reservation' => $reservation];
                        $this->sendReservationNotification($reservation, 'email_reservation_success_template_id', true);
                    }
                }

                $validation->status = 'completed';
                $validation->completed_at = now();
                $validation->last_error = null;
                $validation->save();

                return $result + ['validation' => $validation];
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
            throw new \RuntimeException('Reservierungen sind deaktiviert.');
        }

        if (! $this->validator->nameIsValid($name)) {
            throw new \RuntimeException('Name ungültig.');
        }

        if (! $this->validator->emailIsValid($email)) {
            throw new \RuntimeException('E-Mail ungültig.');
        }

        $siteToken = $validation->site_token ?? null;
        return DB::transaction(function () use ($name, $email, $payload, $overflowEnabled, $siteToken) {
            $max = (int) ($this->settings->get('reservation_max', 0) ?? 0);

            $current = Reservation::query()->lockForUpdate()->count();
            if ($max > 0 && $current >= $max) {
                if ($overflowEnabled && $this->waitlist->waitlistEnabled()) {
                    return $this->waitlist->addToWaitlist($name, $email, $payload);
                }
                throw new \RuntimeException('Reservation limit reached.');
            }

            $duplicate = Reservation::query()
                ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($name)])
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw new \RuntimeException('Name already reserved.');
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
            throw new \RuntimeException('Validierung bereits abgeschlossen.');
        }
        $this->sendValidationEmail($validation);
    }

    private function sendValidationEmail(EmailValidation $validation): void
    {
        if (! $validation->email) {
            throw new \RuntimeException('E-Mail wird fÃ¼r die Validierung benÃ¶tigt.');
        }

        $mailerConfig = $this->buildMailerConfig();
        if (! $mailerConfig) {
            throw new \RuntimeException('Mail-Server ist nicht konfiguriert.');
        }

        $template = $this->resolveTemplate();
        $link = $this->buildValidationLink($validation);

        $replacements = $this->baseReplacements([
            '{{name}}' => $validation->display_name,
            '{{email}}' => $validation->email ?? '',
            '{{validation_link}}' => $link,
            '{{undo_link}}' => '',
            '{{attach_event_ical}}' => '',
        ]);

        $subject = $this->renderTemplate($template['subject'], $replacements);
        $body = $this->renderTemplate($template['body'], $replacements);

        $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
        $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

        $attachments = $this->attachmentsForTemplate($template);

        SendMailJob::dispatch($mailerConfig, $validation->email, $validation->display_name, $subject, $body, $fromAddress, $fromName, $attachments);
    }

    private function buildValidationLink(EmailValidation $validation): string
    {
        $base = trim((string) ($this->settings->get('email_validation_base_url', config('app.url'))));
        if ($base === '') {
            $base = rtrim(config('app.url'), '/');
        }

        $params = ['v' => (string) $validation->token];

        if (!empty($validation->site_token)) {
            $params['t'] = $validation->site_token;
        } else if (!empty($validation->reservation_id)) {
            $reservation = Reservation::find($validation->reservation_id);
            if ($reservation && !empty($reservation->site_token)) {
                $params['t'] = $reservation->site_token;
            }
        }

        return $this->appendQuery($base, $params);
    }

    private function buildUndoLink(Reservation $reservation): string
    {
        if (empty($reservation->undo_token)) {
            $reservation->undo_token = (string) Str::uuid();
            $reservation->save();
        }

        $base = trim((string) ($this->settings->get('email_validation_base_url', config('app.url'))));
        if ($base === '') {
            $base = rtrim(config('app.url'), '/');
        }

        $params = ['u' => (string) $reservation->undo_token];
        if (!empty($reservation->site_token)) {
            $params['t'] = $reservation->site_token;
        }

        return $this->appendQuery($base, $params);
    }

    private function renderTemplate(string $template, array $replacements): string
    {
        return strtr($template, $replacements);
    }

    private function resolveTemplate(): array
    {
        $templateId = $this->settings->get('email_validation_template_id');
        $template = $templateId ? EmailTemplate::query()->find($templateId) : null;

        $subject = 'Bitte E-Mail bestÃ¤tigen';
        $body = <<<HTML
<p>Hallo {{name}},</p>
<p>bitte bestÃ¤tige deine E-Mail-Adresse, um die Reservierung abzuschliessen.</p>
<p><a href="{{validation_link}}">E-Mail bestÃ¤tigen</a></p>
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

    private function buildMailerConfig(): ?array
    {
        $host = $this->settings->get('mail_host');
        $port = (int) ($this->settings->get('mail_port') ?? 0);
        $username = $this->settings->get('mail_username');
        $password = $this->settings->get('mail_password');
        $encryption = $this->settings->get('mail_encryption', null) ?: null;

        if (! $host || ! $port) {
            return null;
        }

        return [
            'transport' => 'smtp',
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => $encryption,
            'timeout' => null,
        ];
    }

    private function appendQuery(string $base, array $params): string
    {
        $separator = str_contains($base, '?') ? '&' : '?';
        return $base.$separator.http_build_query($params);
    }

    public function sendReservationNotification(Reservation $reservation, string $templateSettingKey, bool $includeUndoLink): void
    {
        if (! $reservation->email) {
            return;
        }

        $templateId = (int) ($this->settings->get($templateSettingKey, 0) ?? 0);
        if ($templateId <= 0) {
            return;
        }

        $mailerConfig = $this->buildMailerConfig();
        if (! $mailerConfig) {
            return;
        }

        $undoLink = $includeUndoLink ? $this->buildUndoLink($reservation) : '';
        $replacements = $this->baseReplacements([
            '{{name}}' => $reservation->display_name,
            '{{email}}' => $reservation->email ?? '',
            '{{validation_link}}' => '',
            '{{undo_link}}' => $undoLink,
            '{{attach_event_ical}}' => '',
        ]);

        $template = $this->resolveTemplateById($templateId, 'Info zu deiner Reservierung', '<p>Hallo {{name}},</p><p>deine Reservierung fÃ¼r {{reservation_name}} war erfolgreich.</p><p><a href="{{undo_link}}">Reservierung stornieren</a></p>');
        $subject = $this->renderTemplate($template['subject'], $replacements);
        $body = $this->renderTemplate($template['body'], $replacements);

        $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
        $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

        $attachments = $this->attachmentsForTemplate($template);

        SendMailJob::dispatch($mailerConfig, $reservation->email, $reservation->display_name, $subject, $body, $fromAddress, $fromName, $attachments);
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
            '{{event_city}}' => $this->resolveEventCity(),
            '{{event_title}}' => $this->resolveEventTitle(),
            '{{event_date}}' => $this->resolveEventDate(),
            '{{event_time}}' => $this->resolveEventTime(),
            '{{event_url}}' => $this->resolveEventUrl(),
            '{{event_public_transport_info}}' => $this->resolveEventPublicTransportUrl(),
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

    private function resolveEventCity(): string
    {
        $next = $this->events->next();
        return $next ? (string) ($next->city ?? '') : '';
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

    private function resolveEventPublicTransportUrl(): string
    {
        $next = $this->events->next();
        return $next ? (string) ($next->public_transport_url ?? '') : '';
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
