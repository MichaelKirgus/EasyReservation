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
    ) {
    }

    public function waitlistEnabled(): bool
    {
        return (int) ($this->settings->get('waitlist_enabled', 0) ?? 0) === 1;
    }

    public function addToWaitlist(string $name, ?string $email, ?array $payload = null, ?string $siteToken = null): WaitlistEntry
    {
        $name = trim($name);
        $email = trim((string) $email);

        $limit = (int) ($this->settings->get('waitlist_limit', 0) ?? 0);
        $pendingCount = WaitlistEntry::query()->where('status', 'pending')->count();
        if ($limit > 0 && $pendingCount >= $limit) {
            throw new \RuntimeException('Waitlist limit reached.');
        }

        $duplicate = WaitlistEntry::query()
            ->where('status', 'pending')
            ->whereRaw('LOWER(display_name) = ?', [Str::lower($name)])
            ->exists();

        if ($duplicate) {
            throw new \RuntimeException('Already on waitlist.');
        }

        // Automatisch einen gültigen Gast-Site-Token verwenden, falls keiner übergeben wurde
        if (empty($siteToken)) {
            $siteToken = \App\Models\User::query()
                ->where('role', 'guest')
                ->where('active', true)
                ->whereNotNull('api_token')
                ->orderByDesc('id')
                ->value('api_token');
        }

        return WaitlistEntry::create([
            'display_name' => $name,
            'email' => $email === '' ? null : $email,
            'payload' => $payload,
            'status' => 'pending',
            'undo_token' => (string) Str::uuid(),
            'site_token' => $siteToken,
        ]);
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
                throw new \RuntimeException('Reservation limit not set.');
            }

            $current = Reservation::query()->lockForUpdate()->count();
            if ($current >= $max) {
                throw new \RuntimeException('No free slots available.');
            }

            if ($entry->status !== 'pending') {
                throw new \RuntimeException('Entry already processed.');
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
            $this->mailer->queueBroadcast($templateId, 'selection', false, [], [$entry->id], [], true);
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
            $this->mailer->queueBroadcast($templateId, 'selection', false, [], [$entry->id], [], true);
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
            $this->mailer->queueBroadcast($templateId, 'reservations', false, [$reservation->id], [], [], true);
        } catch (\Throwable $e) {
            Log::warning('Waitlist promotion email failed', [
                'error' => $e->getMessage(),
                'reservation_id' => $reservation->id,
            ]);
        }
    }
}
