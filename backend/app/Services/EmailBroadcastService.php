<?php

namespace App\Services;

use App\Jobs\SendMailJob;
use App\Models\JobLog;
use Illuminate\Support\Str;
use App\Models\EmailTemplate;
use App\Models\MailTransportGroup;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class EmailBroadcastService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly IcsService $ics,
        private readonly PlaceholderService $placeholders,
        private readonly LinkBuildingService $linkBuilder,
        private readonly MailTransportService $mailTransportService,
    ) {
    }

    /**
     * @param  array<int,int>  $reservationIds
     * @param  array<int,int>  $waitlistIds
     * @param  array<int,array{name?:string,email:string}>  $customRecipients
     * @param  array<string>  $userRoles  Array of roles to include: 'admin', 'moderator', 'user'
     */
    public function queueBroadcast(
        int $templateId,
        string $scope,
        bool $sendToAll,
        array $reservationIds = [],
        array $waitlistIds = [],
        array $customRecipients = [],
        bool $deduplicate = true,
        array $userRoles = [],
        ?int $transportGroupId = null,
    ): array {
        $template = EmailTemplate::query()->with('transportGroup')->find($templateId);
        if (! $template) {
            throw new \RuntimeException(__('email_template_not_found'));
        }

        // Get transport group for failover support
        $transportGroup = $transportGroupId ? MailTransportGroup::query()->with('accounts')->find($transportGroupId) : null;
        
        if ($transportGroupId && !$transportGroup) {
            throw new \RuntimeException(__('mail_transport_group_not_found'));
        }

        $recipients = $this->collectRecipients($scope, $sendToAll, $reservationIds, $waitlistIds, $customRecipients, $userRoles);

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

            // Use template-specific CC/BCC if set, otherwise use global
            $templateCc = $template->cc ?? null;
            $templateBcc = $template->bcc ?? null;
            
            $globalCc = $this->settings->get('mail_global_cc');
            $globalBcc = $this->settings->get('mail_global_bcc');

            // If template has CC/BCC, use those; otherwise fall back to global
            $cc = $templateCc ?: $globalCc;
            $bcc = $templateBcc ?: $globalBcc;

            $attachments = [];
            if ($icsAttachment) {
                $attachments[] = $icsAttachment;
            }

            // Use MailTransportService for failover/rate limiting when transport group is specified
            if ($transportGroupId) {
                try {
                    $group = MailTransportGroup::query()->with('accounts')->find($transportGroupId);
                    if ($group) {
                        $this->mailTransportService->sendWithFailover(
                            $group,
                            $recipient['email'],
                            $recipient['name'] ?? null,
                            $subject,
                            $body,
                            $fromAddress,
                            $fromName,
                            $attachments,
                            $cc,
                            $bcc
                        );
                        $queued++;
                    } else {
                        // Transport group not found - log error and skip this recipient
                        Log::error('EmailBroadcastService: Transport group ' . $transportGroupId . ' not found, skipping recipient ' . $recipient['email']);
                        continue;
                    }
                } catch (\Exception $e) {
                    Log::error('EmailBroadcastService: Failed to send via transport group ' . $transportGroupId, [
                        'error' => $e->getMessage(),
                        'email' => $recipient['email']
                    ]);
                    // Continue with next recipient even if one fails
                    continue;
                }
            } else {
                // No transport group specified - log error and skip this recipient
                Log::error('EmailBroadcastService: No transport group ID provided, skipping recipient ' . $recipient['email']);
                continue;
            }
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
     * @param  array<string>  $userRoles  Array of roles to include: 'admin', 'moderator', 'user'
     */
    private function collectRecipients(string $scope, bool $sendToAll, array $reservationIds, array $waitlistIds, array $customRecipients, array $userRoles = []): Collection
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

        // Add internal users based on roles
        if (! empty($userRoles)) {
            $this->addUserRecipients($recipients, $userRoles);
        }

        return $recipients;
    }

    /**
     * Add recipients from internal user roles.
     *
     * @param  \Illuminate\Support\Collection  $recipients
     * @param  array<string>  $roles  Roles to include: 'admin', 'moderator', 'user'
     */
    private function addUserRecipients(Collection $recipients, array $roles): void
    {
        // Map role names to database values
        $roleMap = [
            'admin' => ['superadmin', 'admin'],
            'moderator' => ['moderator'],
            'user' => ['user'],
        ];

        $includedRoles = [];
        foreach ($roles as $role) {
            if (isset($roleMap[$role])) {
                $includedRoles = array_merge($includedRoles, $roleMap[$role]);
            }
        }

        if (empty($includedRoles)) {
            return;
        }

        User::query()
            ->where('active', 1)
            ->whereIn('role', $includedRoles)
            ->get(['id', 'name', 'email'])
            ->each(function (User $user) use (&$recipients) {
                // Skip if email is empty
                if (empty($user->email)) {
                    return;
                }
                $recipients->push([
                    'type' => 'internal_user',
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'undo_link' => '',
                ]);
            });
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

    private function buildMailerConfigFromTransportGroup(int $transportGroupId): ?array
    {
        // Get the transport group with its accounts
        $group = \App\Models\MailTransportGroup::query()->with('accounts')->find($transportGroupId);
        
        if (! $group) {
            return null;
        }

        // Use the first active account from the group as primary
        $account = $group->accounts()->where('is_active', 1)->first();
        
        if (! $account) {
            return null;
        }

        return [
            'transport' => 'smtp',
            'host' => $account->host,
            'port' => (int) ($account->port ?? 587),
            'username' => $account->username,
            'password' => $account->password,
            'encryption' => $account->encryption ?: null,
            'timeout' => (int) ($account->timeout ?? 30),
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
