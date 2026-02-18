<?php

namespace App\Services;

use App\Models\Reservation;
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

        $limit = (int) ($this->settings->get('waitlist_limit', 0) ?? 0);
        $pendingCount = WaitlistEntry::query()->where('status', 'pending')->count();
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
                    ->exists();
                if ($duplicateName) {
                    throw new \RuntimeException(__('feedback_waitlist_success'));
                }
            }

            if (! $allowDuplicateEmail && $email !== null && $email !== '') {
                $duplicateEmail = WaitlistEntry::query()
                    ->where('status', 'pending')
                    ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
                    ->exists();
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

    public function sendWaitlistValidationSuccessEmail(WaitlistEntry $entry): void
    {
        $templateId = (int) ($this->settings->get('email_waitlist_validation_success_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            return;
        }
        if (empty($entry->email)) {
            return;
        }

        try {
            $mailerConfig = $this->buildMailerConfig();
            if ($mailerConfig) {
                $this->emailService->sendWaitlistValidationSuccessEmail($mailerConfig, $entry);
            }
        } catch (\Throwable $e) {
            Log::warning('Waitlist validation success email failed', [
                'error' => $e->getMessage(),
                'waitlist_entry_id' => $entry->id,
            ]);
        }
    }

    public function sendWaitlistCancelledEmail(WaitlistEntry $entry): void
    {
        $templateId = (int) ($this->settings->get('email_waitlist_cancel_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            return;
        }
        if (empty($entry->email)) {
            return;
        }

        try {
            $mailerConfig = $this->buildMailerConfig();
            if ($mailerConfig) {
                $this->emailService->sendWaitlistCancelledEmail($mailerConfig, $entry);
            }
        } catch (\Throwable $e) {
            Log::warning('Waitlist cancel email failed', [
                'error' => $e->getMessage(),
                'waitlist_entry_id' => $entry->id,
            ]);
        }
    }

    private function sendPromotedEmail(Reservation $reservation): void
    {
        $templateId = (int) ($this->settings->get('email_waitlist_promoted_template_id', 0) ?? 0);
        if ($templateId <= 0) {
            return;
        }
        if (empty($reservation->email)) {
            return;
        }

        if (empty($reservation->undo_token)) {
            $reservation->undo_token = (string) Str::uuid();
            $reservation->save();
        }

        try {
            $mailerConfig = $this->buildMailerConfig();
            if ($mailerConfig) {
                $this->emailService->sendWaitlistPromotedEmail($mailerConfig, $reservation);
            }
        } catch (\Throwable $e) {
            Log::warning('Waitlist promotion email failed', [
                'error' => $e->getMessage(),
                'reservation_id' => $reservation->id,
            ]);
        }
    }
}
