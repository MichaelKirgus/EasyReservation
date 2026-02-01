<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReservationStoreRequest;
use App\Http\Requests\ReservationUndoRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\EmailValidationService;
use App\Services\EventTriggerService;
use App\Services\ReservationValidationService;
use App\Services\SettingsService;
use App\Services\WaitlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReservationController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ReservationValidationService $validator,
        private readonly WaitlistService $waitlist,
        private readonly EmailValidationService $emailValidation,
        private readonly EventTriggerService $eventTriggers,
        private readonly EmailService $emailService,
    ) {
    }

    public function store(ReservationStoreRequest $request): JsonResponse
    {

        $settings = $this->settings->all();
        // Site-Token aus Request holen
        $siteToken = $request->input('site_token', null);

        if ((int) ($settings['reservation_enabled'] ?? 0) !== 1) {
            // Trigger: reservation_disabled
            $this->eventTriggers->handle('reservation_disabled');
            return response()->json(['message' => 'Reservations are currently disabled.'], 403);
        }

        $user = $this->resolveUserFromToken($request);

        $name = trim($request->string('name')->toString());
        $email = trim((string) $request->input('email'));

        if ($user && $user->role === 'user') {
            $name = $user->name;
            $email = $user->email ?? '';
        }

        $payload = $request->input('payload');
        $payload = is_array($payload) ? $payload : [];

        $missingRequiredCheckboxes = $this->validator->missingRequiredCheckboxes($payload);
        if (! empty($missingRequiredCheckboxes)) {
            return response()->json([
                'message' => 'Required confirmation missing.',
                'missing' => $missingRequiredCheckboxes,
            ], 422);
        }

        if (! $this->validator->nameIsValid($name)) {
            return response()->json(['message' => 'Invalid name.'], 422);
        }

        if (! $this->validator->emailIsValid($email)) {
            return response()->json(['message' => 'Invalid email.'], 422);
        }

        $max = (int) ($settings['reservation_max'] ?? 0);
        $current = Reservation::query()->count();
        $waitlistEnabled = (int) ($settings['waitlist_enabled'] ?? 0) === 1;

        $target = 'reservation';
        if ($max > 0 && $current >= $max) {
            // Trigger: reservation_full
            $this->eventTriggers->handle('reservation_full');
            if ($waitlistEnabled) {
                $target = 'waitlist';
            } else {
                return response()->json(['message' => 'Reservation limit reached.'], 409);
            }
        }

        if ($target === 'reservation') {
            $duplicate = Reservation::query()
                ->whereRaw('LOWER(display_name) = ?', [Str::lower($name)])
                ->exists();

            if ($duplicate) {
                return response()->json(['message' => 'Name already reserved.'], 409);
            }
        } else {
            $waitlistDuplicate = WaitlistEntry::query()
                ->where('status', 'pending')
                ->whereRaw('LOWER(display_name) = ?', [Str::lower($name)])
                ->exists();

            if ($waitlistDuplicate) {
                return response()->json(['message' => 'Already on waitlist.'], 409);
            }

            $waitlistLimit = (int) ($settings['waitlist_limit'] ?? 0);
            $waitlistPendingCount = WaitlistEntry::query()->where('status', 'pending')->count();
            if ($waitlistLimit > 0 && $waitlistPendingCount >= $waitlistLimit) {
                return response()->json(['message' => 'Waitlist limit reached.'], 409);
            }
        }

        $validationFeatureEnabled = $this->emailValidation->emailValidationEnabled()
            || $this->emailValidation->adminApprovalEnabled();

        if ($validationFeatureEnabled) {
            try {
                $validation = $this->emailValidation->createRequest($target, $name, $email, $payload, $siteToken);
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 500);
            }

            return response()->json([
                'message' => $target === 'waitlist'
                    ? 'BestÃ¤tigung erforderlich. PrÃ¼fe deine E-Mail, um die Wartelisten-Anfrage abzuschlieÃŸen.'
                    : 'BestÃ¤tigung erforderlich. PrÃ¼fe deine E-Mail, um die Reservierung abzuschlieÃŸen.',
                'validation_pending' => true,
                'target' => $target,
                'pending_admin' => $this->emailValidation->adminApprovalEnabled() && ! $this->emailValidation->emailValidationEnabled(),
                'validation' => $validation,
            ], 202);
        }

        if ($target === 'waitlist') {
            try {
                $entry = $this->waitlist->addToWaitlist($name, $email, $payload, $siteToken);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return response()->json([
                'message' => 'Added to waitlist.',
                'waitlist' => true,
                'entry' => $entry,
            ], 201);
        }

        $reservation = Reservation::create([
            'display_name' => $name,
            'email' => $email === '' ? null : $email,
            'payload' => $payload,
            'undo_token' => (string) Str::uuid(),
            'site_token' => $siteToken,
        ]);

        $this->emailValidation->sendReservationNotification($reservation, 'email_reservation_success_template_id', true);

        // Trigger: reservation_enabled (z.B. bei erfolgreicher Reservierung)
        $this->eventTriggers->handle('reservation_enabled', ['reservation' => $reservation]);

        return response()->json([
            'message' => 'Reservation created.',
            'reservation' => $reservation,
        ], 201);
    }

    public function undo(ReservationUndoRequest $request): JsonResponse
    {
        $settings = $this->settings->all();

        if ((int) ($settings['reservation_undo_enabled'] ?? 0) !== 1) {
            return response()->json(['message' => 'Undo is disabled.'], 403);
        }

        $user = $this->resolveUserFromToken($request);

        $name = trim($request->string('name')->toString());
        $email = trim((string) $request->input('email'));

        if ($user && $user->role === 'user') {
            $name = $user->name;
            $email = $user->email ?? '';
        }

        if ($email === '' || ! $this->validator->emailIsValid($email)) {
            return response()->json(['message' => 'Invalid email.'], 422);
        }

        if (! $this->validator->nameIsValid($name)) {
            return response()->json(['message' => 'Invalid name.'], 422);
        }

        $query = Reservation::query()
            ->whereRaw('LOWER(display_name) = ?', [Str::lower($name)]);

        if ($email === '') {
            $query->whereNull('email');
        } else {
            $query->where('email', $email);
        }

        $candidate = $query->first();

        if (! $candidate) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        $this->emailValidation->sendReservationNotification($candidate, 'email_reservation_cancel_template_id', false);

        $candidate->delete();

        // Trigger: reservation_canceled (z.B. bei erfolgreichem Undo)
        $this->eventTriggers->handle('reservation_canceled', ['reservation' => $candidate]);

        if ((int) ($settings['waitlist_auto_promote_enabled'] ?? 0) === 1) {
            try {
                $this->waitlist->promoteOldestIfSlotAvailable();
            } catch (\Throwable $e) {
                Log::warning('Auto-promote failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['message' => 'Reservation removed.']);
    }

    public function undoByToken(string $token): JsonResponse
    {
        $settings = $this->settings->all();
        if ((int) ($settings['reservation_undo_enabled'] ?? 0) !== 1) {
            return response()->json(['message' => 'Undo is disabled.'], 403);
        }

        $reservation = Reservation::query()->where('undo_token', $token)->first();

        if (! $reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }

        $this->emailValidation->sendReservationNotification($reservation, 'email_reservation_cancel_template_id', false);

        $reservation->delete();

        // Trigger: reservation_canceled (z.B. bei erfolgreichem Undo)
        $this->eventTriggers->handle('reservation_canceled', ['reservation' => $reservation]);

        if ((int) ($settings['waitlist_auto_promote_enabled'] ?? 0) === 1) {
            try {
                $this->waitlist->promoteOldestIfSlotAvailable();
            } catch (\Throwable $e) {
                Log::warning('Auto-promote failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['message' => 'Reservation removed.']);
    }

    private function resolveUserFromToken(Request $request): ?User
    {
        $token = $request->header('X-Api-Key');
        if (! $token) {
            return null;
        }

        $candidates = User::query()->where('active', true)->get();
        foreach ($candidates as $user) {
            if (! $user->api_token) {
                continue;
            }
            if ($user->api_token_is_hashed) {
                if (Hash::check($token, $user->api_token)) {
                    return $user;
                }
            } else {
                if (hash_equals((string) $user->api_token, (string) $token)) {
                    return $user;
                }
            }
        }

        return null;
    }
}
