<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\MailTransportGroup;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WaitlistService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly EmailBroadcastService $mailer,
        private readonly EmailService $emailService,
        private readonly MailTransportService $mailTransportService,
    ) {
    }

    public function waitlistEnabled(): bool
    {
        return (int) ($this->settings->get('waitlist_enabled', 0) ?? 0) === 1;
    }

    public function getWaitlistPosition(string $name, ?string $email): int
    {
        if (!$this->waitlistEnabled()) {
            return 0;
        }

        // Get all pending waitlist entries ordered by date_added (oldest first)
        $entries = WaitlistEntry::query()
            ->where('status', 'pending')
            ->orderBy('date_added')
            ->get(['id', 'display_name', 'email', 'date_added']);

        // Find the position of the user
        foreach ($entries as $index => $entry) {
            if (strtolower($entry->display_name) === strtolower($name) &&
                ($email === null || $email === '' || strtolower($entry->email) === strtolower($email))) {
                return $index + 1; // Position is 1-based
            }
        }

        return 0; // Not found on waitlist
    }

    public function addToWaitlist(string $name, ?string $email, ?array $payload = null, ?string $siteToken = null): WaitlistEntry
    {
        $name = trim($name);
        $email = trim((string) $email);

        return DB::transaction(function () use ($name, $email, $payload, $siteToken) {
            $limit = (int) ($this->settings->get('waitlist_limit', 0) ?? 0);
            $pendingCount = WaitlistEntry::query()->lockForUpdate()->where('status', 'pending')->count();
            if ($limit > 0 && $pendingCount >= $limit) {
                throw new \RuntimeException(__('feedback_waitlist_full'));
            }

            $user = auth()->user();
            $adminExempt = (int)($this->settings->get('waitlist_duplicate_check_admin_exempt', 0) ?? 0) === 1;
            $isAdminOrMod = $user && in_array($user->role, ['admin', 'moderator', 'superadmin']);
            $skipDuplicateCheck = $adminExempt && $isAdminOrMod;
            $allowDuplicateName = (int)($this->settings->get('waitlist_allow_duplicate_name', 0) ?? 0) === 1;
            $allowDuplicateEmail = (int)($this->settings->get('waitlist_allow_duplicate_email', 0) ?? 0) === 1;

            if (! $skipDuplicateCheck) {
                if (! $allowDuplicateName) {
                    $duplicateName = WaitlistEntry::query()
                        ->where('status', 'pending')
                        ->whereRaw('LOWER(display_name) = ?', [Str::lower($name)])
                        ->lockForUpdate()
                        ->exists();
                    if ($duplicateName) {
                        throw new \RuntimeException(__('feedback_waitlist_success'));
                    }
                }

                if (! $allowDuplicateEmail && $email !== null && $email !== '') {
                    $pendingEntries = WaitlistEntry::query()
                        ->where('status', 'pending')
                        ->lockForUpdate()
                        ->get(['id', 'email']);

                    $duplicateEmail = $pendingEntries->first(function (WaitlistEntry $entry) use ($email) {
                        return Str::lower((string) ($entry->email ?? '')) === Str::lower($email);
                    });

                    if ($duplicateEmail) {
                        throw new \RuntimeException(__('feedback_waitlist_success'));
                    }
                }
            }

            // Automatisch einen gültigen Gast-Site-Token verwenden, falls keiner übergeben wurde
            if (empty($siteToken)) {
                $siteToken = app(\App\Services\SiteTokenService::class)->getValidSiteToken();
            }

            $entry = WaitlistEntry::create([
                'display_name' => $name,
                'email' => $email === '' ? null : $email,
                'payload' => $payload,
                'status' => 'pending',
                'undo_token' => (string) Str::uuid(),
                'site_token' => $siteToken,
            ]);

            // Trigger: waitlist_entry_added (on new waitlist entry creation)
            app(\App\Services\EventTriggerService::class)->handle('waitlist_entry_added', ['waitlist_entry' => $entry]);

            return $entry;
        });
    }

    public function promoteOldestIfSlotAvailable(): ?Reservation
    {
        $max = (int) ($this->settings->get('reservation_max', 0) ?? 0);
        if ($max <= 0) {
            return null;
        }

        $reservation = DB::transaction(function () use ($max) {
            $current = Reservation::query()->lockForUpdate()->count();
            if ($current >= $max) {
                return null;
            }

            $entry = WaitlistEntry::query()
                ->where('status', 'pending')
                ->orderBy('date_added')
                ->lockForUpdate()
                ->first();

            if (! $entry) {
                return null;
            }

            $reservation = Reservation::create([
                'display_name' => $entry->display_name,
                'email' => $entry->email,
                'payload' => $entry->payload,
                'from_waitlist' => true,
                'undo_token' => (string) Str::uuid(),
                'site_token' => $entry->site_token,
            ]);

            $entry->status = 'promoted';
            $entry->reservation_id = $reservation->id;
            $entry->promoted_at = now();
            $entry->save();

            return $reservation;
        });

        if ($reservation) {
            $this->sendPromotedEmail($reservation);
        }

        return $reservation;
    }

    public function promoteEntry(WaitlistEntry $entry): ?Reservation
    {
        $reservation = DB::transaction(function () use ($entry) {
            $max = (int) ($this->settings->get('reservation_max', 0) ?? 0);
            if ($max <= 0) {
                throw new \RuntimeException(__('reservation_limit_not_set'));
            }

            $current = Reservation::query()->lockForUpdate()->count();
            if ($current >= $max) {
                throw new \RuntimeException(__('no_free_slots_available'));
            }

            if ($entry->status !== 'pending') {
                throw new \RuntimeException(__('entry_already_processed'));
            }

            $reservation = Reservation::create([
                'display_name' => $entry->display_name,
                'email' => $entry->email,
                'payload' => $entry->payload,
                'from_waitlist' => true,
                'undo_token' => (string) Str::uuid(),
                'site_token' => $entry->site_token,
            ]);

            $entry->status = 'promoted';
            $entry->reservation_id = $reservation->id;
            $entry->promoted_at = now();
            $entry->save();

            // Trigger: waitlist_entry_removed (after status change so placeholder values reflect current state)
            app(\App\Services\EventTriggerService::class)->handle('waitlist_entry_removed', ['waitlist_entry' => $entry]);

            return $reservation;
        });

        if ($reservation) {
            $this->sendPromotedEmail($reservation);
        }

        return $reservation;
    }

    private function getTransportGroupIdFromTemplate(?int $templateId): ?int
    {
        if (! $templateId) {
            return null;
        }
        
        // Get the template to check for transport_group_id
        $template = \App\Models\EmailTemplate::query()->with('transportGroup')->find($templateId);
        return $template && $template->transport_group_id ? $template->transport_group_id : null;
    }

    public function sendWaitlistValidationSuccessEmail(WaitlistEntry $entry): void
    {
        \Illuminate\Support\Facades\Log::debug('WaitlistService: Starting waitlist validation success email sending', [
            'waitlist_entry_id' => $entry->id,
            'email' => $entry->email ?? 'unknown',
        ]);

        $templateId = (int) ($this->settings->get('email_waitlist_validation_success_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            \Illuminate\Support\Facades\Log::warning('WaitlistService: No waitlist validation success template configured');
            return;
        }
        if (empty($entry->email)) {
            \Illuminate\Support\Facades\Log::debug('WaitlistService: Waitlist entry has no email, skipping');
            return;
        }

        // Get transport group ID from template
        $transportGroupId = $this->getTransportGroupIdFromTemplate($templateId);

        if (!$transportGroupId) {
            \Illuminate\Support\Facades\Log::error('WaitlistService: No transport group ID found for waitlist validation success email');
            return;
        }

        try {
            $this->emailService->sendWaitlistValidationSuccessEmail($transportGroupId, $entry);
        } catch (\Throwable $e) {
            Log::warning('Waitlist validation success email failed', [
                'error' => $e->getMessage(),
                'waitlist_entry_id' => $entry->id,
            ]);
        }
    }

    public function sendWaitlistCancelledEmail(WaitlistEntry $entry): void
    {
        \Illuminate\Support\Facades\Log::debug('WaitlistService: Starting waitlist cancelled email sending', [
            'waitlist_entry_id' => $entry->id,
            'email' => $entry->email ?? 'unknown',
        ]);

        $templateId = (int) ($this->settings->get('email_waitlist_cancel_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            \Illuminate\Support\Facades\Log::warning('WaitlistService: No waitlist cancel template configured');
            return;
        }
        if (empty($entry->email)) {
            \Illuminate\Support\Facades\Log::debug('WaitlistService: Waitlist entry has no email, skipping');
            return;
        }

        // Get transport group ID from template
        $transportGroupId = $this->getTransportGroupIdFromTemplate($templateId);

        if (!$transportGroupId) {
            \Illuminate\Support\Facades\Log::error('WaitlistService: No transport group ID found for waitlist cancelled email');
            return;
        }

        try {
            $this->emailService->sendWaitlistCancelledEmail($transportGroupId, $entry);
        } catch (\Throwable $e) {
            Log::warning('Waitlist cancel email failed', [
                'error' => $e->getMessage(),
                'waitlist_entry_id' => $entry->id,
            ]);
        }
    }

    private function sendPromotedEmail(Reservation $reservation): void
    {
        \Illuminate\Support\Facades\Log::debug('WaitlistService: Starting waitlist promoted email sending', [
            'reservation_id' => $reservation->id,
            'email' => $reservation->email ?? 'unknown',
        ]);

        if (empty($reservation->email)) {
            \Illuminate\Support\Facades\Log::debug('WaitlistService: Reservation has no email, skipping');
            return;
        }

        if (empty($reservation->undo_token)) {
            $reservation->undo_token = (string) Str::uuid();
            $reservation->save();
        }

        // Send waitlist promoted email (independent of reservation success email)
        $templateId = (int) ($this->settings->get('email_waitlist_promoted_template_id', 0) ?? 0);
        if ($templateId > 0) {
            // Get transport group ID from template; fallback to global mail config if missing
            $transportGroupId = $this->getTransportGroupIdFromTemplate($templateId);

            if (!$transportGroupId) {
                \Illuminate\Support\Facades\Log::warning('WaitlistService: No transport group for promoted email, falling back to global mail config');
            }

            try {
                $this->emailService->sendWaitlistPromotedEmail($transportGroupId, $reservation);
            } catch (\Throwable $e) {
                Log::warning('Waitlist promotion email failed', [
                    'error' => $e->getMessage(),
                    'reservation_id' => $reservation->id,
                ]);
            }
        } else {
            \Illuminate\Support\Facades\Log::warning('WaitlistService: No waitlist promoted template configured');
        }

        // Always send the standard reservation success notification so promoted users
        // get the same confirmation as direct reservations, regardless of whether the
        // promoted template is configured or whether that email succeeded.
        try {
            app(\App\Services\EmailValidationService::class)->sendReservationNotification($reservation, 'email_reservation_success_template_id', true);
        } catch (\Throwable $e) {
            Log::warning('Waitlist promotion: reservation success email failed', [
                'error' => $e->getMessage(),
                'reservation_id' => $reservation->id,
            ]);
        }
    }
}
