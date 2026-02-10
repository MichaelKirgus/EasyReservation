<?php

namespace App\Services;

use App\Jobs\SendMailJob;
use App\Models\JobLog;
use Illuminate\Support\Str;
use App\Models\EmailTemplate;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Support\Collection;

class EmailBroadcastService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly IcsService $ics,
        private readonly PlaceholderService $placeholders,
        private readonly LinkBuildingService $linkBuilder,
    ) {
    }

    /**
     * @param  array<int,int>  $reservationIds
     * @param  array<int,int>  $waitlistIds
     * @param  array<int,array{name?:string,email:string}>  $customRecipients
     */
    public function queueBroadcast(
        int $templateId,
        string $scope,
        bool $sendToAll,
        array $reservationIds = [],
        array $waitlistIds = [],
        array $customRecipients = [],
        bool $deduplicate = true,
    ): array {
        $template = EmailTemplate::query()->find($templateId);
        if (! $template) {
            throw new \RuntimeException(__('email_template_not_found'));
        }

        $mailerConfig = $this->buildMailerConfig();
        if (! $mailerConfig) {
            throw new \RuntimeException(__('mail_server_not_configured'));
        }

        $recipients = $this->collectRecipients($scope, $sendToAll, $reservationIds, $waitlistIds, $customRecipients);

        $beforeDedupCount = $recipients->count();
        $skippedNoEmail = $recipients->filter(fn ($r) => empty($r['email']))->count();
        $recipients = $recipients->filter(fn ($r) => ! empty($r['email']));

        $duplicatesRemoved = 0;
        if ($deduplicate) {
            $before = $recipients->count();
            $recipients = $recipients->unique('email');
            $duplicatesRemoved = $before - $recipients->count();
        }

        // Empfänger-Platzhalter werden direkt in replacements() gemerged

        $wantsIcs = $this->templateWantsIcs($template);
        $icsAttachment = $wantsIcs ? $this->ics->nextEventAttachment() : null;

        $queued = 0;
        $skippedBlacklisted = 0;
        $validator = app(\App\Services\ReservationValidationService::class);
        foreach ($recipients as $recipient) {
            $email = $recipient['email'] ?? '';
            if ($validator->isDebugBlacklistedEmail($email)) {
                JobLog::create([
                    'job' => 'EmailBroadcastService',
                    'queue' => 'mail',
                    'status' => 'warning',
                    'runtime_ms' => 0,
                    'message' => 'Mail skipped: ' . $email . ' (Domain on debug blacklist).',
                    'started_at' => now(),
                    'finished_at' => now(),
                ]);
                $skippedBlacklisted++;
                continue;
            }
            $replacements = $this->placeholders->replacements([
                'name' => $recipient['name'] ?? '',
                'email' => $recipient['email'] ?? '',
                'undo_link' => $recipient['undo_link'] ?? '',
                'validation_link' => '',
            ]);

            $subject = $this->renderTemplate($template->subject, $replacements);
            $body = $this->renderTemplate($template->body, $replacements);

            $fromAddress = $this->settings->get('mail_from_address', config('mail.from.address'));
            $fromName = $this->settings->get('mail_from_name', config('mail.from.name'));

            $attachments = [];
            if ($icsAttachment) {
                $attachments[] = $icsAttachment;
            }

            SendMailJob::dispatch($mailerConfig, $recipient['email'], $recipient['name'] ?? $recipient['email'], $subject, $body, $fromAddress, $fromName, $attachments);
            $queued++;
        }

        return [
            'queued' => $queued,
            'skipped_no_email' => $skippedNoEmail,
            'skipped_blacklisted' => $skippedBlacklisted,
            'duplicates_removed' => $duplicatesRemoved,
            'candidates' => $beforeDedupCount,
            'template_id' => $templateId,
        ];
    }

    /**
     * @param  array<int,int>  $reservationIds
     * @param  array<int,int>  $waitlistIds
     * @param  array<int,array{name?:string,email:string}>  $customRecipients
     */
    private function collectRecipients(string $scope, bool $sendToAll, array $reservationIds, array $waitlistIds, array $customRecipients): Collection
    {
        $recipients = collect();

        if (in_array($scope, ['reservations', 'both', 'selection'], true)) {
            $query = Reservation::query()->orderBy('id');
            if (! $sendToAll) {
                $ids = array_filter($reservationIds, fn ($id) => is_numeric($id));
                if (count($ids) === 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('id', $ids);
                }
            }
            $query->get(['id', 'display_name', 'email', 'undo_token'])
                ->each(function (Reservation $reservation) use (&$recipients) {
                    $recipients->push([
                        'type' => 'reservation',
                        'id' => $reservation->id,
                        'name' => $reservation->display_name,
                        'email' => $reservation->email,
                        'undo_link' => $reservation->email ? $this->linkBuilder->buildUndoLink($reservation) : '',
                    ]);
                });
        }

        if (in_array($scope, ['waitlist', 'both', 'selection'], true)) {
            $query = WaitlistEntry::query()->orderBy('id');
            if (! $sendToAll) {
                $ids = array_filter($waitlistIds, fn ($id) => is_numeric($id));
                if (count($ids) === 0) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('id', $ids);
                }
            }
            $query->get(['id', 'display_name', 'email', 'undo_token'])
                ->each(function (WaitlistEntry $entry) use (&$recipients) {
                    $recipients->push([
                        'type' => 'waitlist',
                        'id' => $entry->id,
                        'name' => $entry->display_name,
                        'email' => $entry->email,
                        'undo_link' => $this->linkBuilder->buildWaitlistUndoLink($entry),
                    ]);
                });
        }

        foreach ($customRecipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $recipients->push([
                'type' => 'custom',
                'id' => null,
                'name' => $recipient['name'] ?? null,
                'email' => $email,
                'undo_link' => '',
            ]);
        }

        return $recipients;
    }

    private function renderTemplate(string $template, array $replacements): string
    {
        return strtr($template, $replacements);
    }

    private function buildMailerConfig(): ?array
    {
        $host = $this->settings->get('mail_host', config('mail.mailers.smtp.host'));
        $port = (int) ($this->settings->get('mail_port', config('mail.mailers.smtp.port')) ?? 0);
        $username = $this->settings->get('mail_username', config('mail.mailers.smtp.username'));
        $password = $this->settings->get('mail_password', config('mail.mailers.smtp.password'));
        $encryption = $this->settings->get('mail_encryption', config('mail.mailers.smtp.encryption')) ?: null;

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


    private function buildWaitlistUndoLink(WaitlistEntry $entry): string
    {
        $undoEnabled = (int) ($this->settings->get('waitlist_undo_enabled', 0) ?? 0) === 1;
        if (! $undoEnabled) {
            return '';
        }

        if (! $entry->undo_token) {
            return '';
        }

        $base = trim((string) ($this->settings->get('email_validation_base_url', config('app.url'))));
        if ($base === '') {
            $base = rtrim(config('app.url'), '/');
        }

        $params = ['wu' => (string) $entry->undo_token];
        if ($this->settings->isTokenRequired() && $this->settings->siteToken()) {
            $params['t'] = $this->settings->siteToken();
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.http_build_query($params);
    }

    private function templateWantsIcs(EmailTemplate $template): bool
    {
        return str_contains($template->subject ?? '', '{{attach_event_ical}}')
            || str_contains($template->body ?? '', '{{attach_event_ical}}');
    }

    /**
     * Versendet ein E-Mail-Template an alle Reservierungen eines Events (Bulk über queueBroadcast).
     * @param int $eventId
     * @param int|null $templateId
     */
    public function sendTemplateToReservationList($eventId, $templateId = null)
    {
        if (!$templateId) {
            // Kein Template angegeben, nichts tun
            return;
        }
        // Use existing bulk logic
        $this->queueBroadcast(
            $templateId,
            'reservations', // scope: reservations only
            true,           // sendToAll: all participants
            [],             // reservationIds
            [],             // waitlistIds
            [],             // customRecipients
            true            // deduplicate
        );
    }
}
