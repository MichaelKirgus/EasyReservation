<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReservationStoreRequest;
use App\Http\Requests\ReservationUndoRequest;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\EmailService;
use App\Services\EmailValidationService;
use App\Services\EventTriggerService;
use App\Services\ReservationValidationService;
use App\Services\SettingsService;
use App\Services\SiteTokenService;
use App\Services\WaitlistService;
use App\Services\PlaceholderService;
use App\Services\ValidationRuleEngine;
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
        private readonly SiteTokenService $siteTokenService,
        private readonly PlaceholderService $placeholders,
        private readonly ValidationRuleEngine $validationRuleEngine,
    ) {
    }

    public function store(ReservationStoreRequest $request): JsonResponse
    {
        Log::debug('ReservationController@store called', [
            'request' => $request->all(),
            'ip' => $request->ip(),
        ]);

        $settings = $this->settings->all();
        // Site-Token aus Request holen
        $siteToken = $request->input('site_token', null);

        if ((int) ($settings['reservation_enabled'] ?? 0) !== 1) {
            // Trigger: reservation_disabled
            $this->eventTriggers->handle('reservation_disabled');
            return response()->json(['message' => __('feedback_reservation_disabled')], 403);
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
                'message' => __('reservation_required_confirmation_missing'),
                'missing' => $missingRequiredCheckboxes,
            ], 422);
        }

        // Validate using the new rule-based validation engine
        $flatData = array_merge(
            ['name' => $name, 'email' => $email],
            $payload ?? []
        );
        $validationContext = [
            'reservation_name' => $name,
            'reservation_email' => $email,
            'payload' => $payload ?? [],
        ];
        $validationResult = $this->validationRuleEngine->evaluate($flatData, $validationContext);
        if ($validationResult->fails()) {
            return response()->json(['message' => $validationResult->errorMessage()], 422);
        }

        if (! $this->validator->nameIsValid($name)) {
            return response()->json(['message' => __('validation_invalid_name')], 422);
        }

        if (! $this->validator->emailIsValid($email)) {
            return response()->json(['message' => __('validation_invalid_email')], 422);
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
                return response()->json(['message' => __('feedback_reservation_limit')], 409);
            }
        }

        if ($target === 'reservation') {
            $duplicate = Reservation::query()
                ->whereRaw('LOWER(display_name) = ?', [Str::lower($name)])
                ->exists();

            if ($duplicate) {
                return response()->json(['message' => __('feedback_reservation_failed_name_duplicate')], 409);
            }
        } else {
            $waitlistDuplicate = WaitlistEntry::query()
                ->where('status', 'pending')
                ->whereRaw('LOWER(display_name) = ?', [Str::lower($name)])
                ->exists();

            if ($waitlistDuplicate) {
                return response()->json(['message' => __('feedback_waitlist_success')], 409);
            }

            $waitlistLimit = (int) ($settings['waitlist_limit'] ?? 0);
            $waitlistPendingCount = WaitlistEntry::query()->where('status', 'pending')->count();
            if ($waitlistLimit > 0 && $waitlistPendingCount >= $waitlistLimit) {
                return response()->json(['message' => __('feedback_waitlist_full')], 409);
            }
        }

        $validationFeatureEnabled = $this->emailValidation->emailValidationEnabled()
            || $this->emailValidation->adminApprovalEnabled();

        if ($validationFeatureEnabled) {
            try {
                $validation = $this->emailValidation->createRequest($target, $name, $email, $payload, $siteToken);
                Log::debug('ReservationController: $validation after createRequest', [
                    'type' => gettype($validation),
                    'class' => is_object($validation) ? get_class($validation) : null,
                    'validation' => is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
                ]);
                if (!is_object($validation)) {
                    Log::error('ReservationController: $validation is not an object after createRequest', [
                        'type' => gettype($validation),
                        'validation' => $validation,
                        'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20),
                    ]);
                    throw new \RuntimeException('ReservationController: $validation is not an object after createRequest');
                }
                // Add debug log for validation creation
                Log::debug('Reservation validation triggered', [
                    'target' => $target,
                    'name' => $name,
                    'email' => $email,
                    'site_token' => $siteToken,
                    'validation_id' => is_object($validation) ? ($validation->id ?? null) : (is_array($validation) ? ($validation['id'] ?? null) : null),
                    'validation_status' => is_object($validation) ? ($validation->status ?? null) : (is_array($validation) ? ($validation['status'] ?? null) : null),
                    'validation' => method_exists($validation, 'toArray') ? $validation->toArray() : $validation,
                ]);
                // Return only necessary fields for validation
                $validationData = [
                    'id' => $validation->id,
                    'status' => $validation->status,
                    'type' => $validation->type,
                    'display_name' => $validation->display_name,
                    'email' => $validation->email,
                    'expires_at' => $validation->expires_at,
                    'requires_admin_approval' => $validation->requires_admin_approval,
                ];
                return response()->json([
                    'message' => $target === 'waitlist'
                        ? __('feedback_waitlist_success')
                        : __('reservation_required_confirmation_missing'),
                    'validation_pending' => true,
                    'target' => $target,
                    'pending_admin' => $this->emailValidation->adminApprovalEnabled() && ! $this->emailValidation->emailValidationEnabled(),
                    'validation' => $validationData,
                ], 202);
            } catch (\Throwable $e) {
                $isRateLimited = $e instanceof \RuntimeException && Str::contains($e->getMessage(), 'email_validation_rate_limit');
                if ($isRateLimited) {
                    $customRateLimit = $this->settings->get('email_validation_rate_limit_text');
                    $rateLimitMessage = $customRateLimit ?: __('email_validation_rate_limited');

                    return response()->json([
                        'message' => $rateLimitMessage,
                        'error' => $e->getMessage(),
                    ], 429);
                }

                Log::error('ReservationController: Exception caught in store', [
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_trace' => $e->getTraceAsString(),
                    'target' => $target ?? null,
                    'name' => $name ?? null,
                    'email' => $email ?? null,
                    'site_token' => $siteToken ?? null,
                    'validation_type' => isset($validation) ? gettype($validation) : null,
                    'validation_is_object' => isset($validation) ? is_object($validation) : null,
                    'validation_value' => isset($validation) ? (is_object($validation) && method_exists($validation, 'toArray') ? $validation->toArray() : $validation) : null,
                ]);
                return response()->json([
                    'message' => __('reservation_validation_error'),
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        if ($target === 'waitlist') {
            try {
                $entry = $this->waitlist->addToWaitlist($name, $email, $payload, $siteToken);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => __($e->getMessage())], 409);
            }

            return response()->json([
                'message' => __('feedback_waitlist_success'),
                'waitlist' => true,
                'entry' => $entry,
            ], 201);
        }

        // If no site token provided, get a valid one from guest users - but only for admin/moderator users
        if (empty($siteToken)) {
            $user = auth()->user();
            if ($user && in_array($user->role, ['admin', 'superadmin', 'moderator'])) {
                $siteToken = $this->siteTokenService->getValidSiteToken();
            } else {
                return response()->json(['message' => __('reservation_site_token_required')], 422);
            }
        }

        $reservation = Reservation::create([
            'display_name' => $name,
            'email' => $email === '' ? null : $email,
            'payload' => $payload,
            'undo_token' => (string) Str::uuid(),
            'site_token' => $siteToken,
        ]);

        $this->emailValidation->sendReservationNotification($reservation, 'email_reservation_success_template_id', true);

        // Trigger: reservation_added (on new reservation creation)
        $this->eventTriggers->handle('reservation_added', ['reservation' => $reservation]);

        // Trigger: reservation_enabled (e.g. on successful reservation)
        $this->eventTriggers->handle('reservation_enabled', ['reservation' => $reservation]);

        return response()->json([
            'message' => __('feedback_reservation_success'),
            'reservation' => $reservation,
        ], 201);
    }

    public function undo(ReservationUndoRequest $request): JsonResponse
    {
        $settings = $this->settings->all();

        if ((int) ($settings['reservation_undo_enabled'] ?? 0) !== 1) {
            return response()->json(['message' => __('reservation_undo_disabled')], 403);
        }

        $user = $this->resolveUserFromToken($request);

        $name = trim($request->string('name')->toString());
        $email = trim((string) $request->input('email'));

        if ($user && $user->role === 'user') {
            $name = $user->name;
            $email = $user->email ?? '';
        }

        if ($email === '' || ! $this->validator->emailIsValid($email)) {
            return response()->json(['message' => __('validation_invalid_email')], 422);
        }

        if (! $this->validator->nameIsValid($name)) {
            return response()->json(['message' => __('validation_invalid_name')], 422);
        }

        $candidate = $this->findReservationByNameAndEmail($name, $email);

        if (! $candidate) {
            \Log::debug('Reservation undo: no matching reservation found', [
                'name' => $name,
                'email' => $email,
            ]);

            $message = $this->customNotFoundMessage(['name' => $name, 'email' => $email]) ?? __('reservation_not_found');

            return response()->json(['message' => $message], 404);
        }

        $this->emailValidation->sendReservationNotification($candidate, 'email_reservation_cancel_template_id', false);

        $candidate->delete();

        // Trigger: reservation_removed (after deletion so placeholder values reflect current state)
        $this->eventTriggers->handle('reservation_removed', ['reservation' => $candidate]);

        // Trigger: reservation_canceled (z.B. bei erfolgreichem Undo)
        $this->eventTriggers->handle('reservation_canceled', ['reservation' => $candidate]);

        if ((int) ($settings['waitlist_auto_promote_enabled'] ?? 0) === 1) {
            try {
                $this->waitlist->promoteOldestIfSlotAvailable();
            } catch (\Throwable $e) {
                Log::warning('Auto-promote failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['message' => __('reservation_removed')]);
    }

    public function undoByToken(string $token): JsonResponse
    {
        $settings = $this->settings->all();
        if ((int) ($settings['reservation_undo_enabled'] ?? 0) !== 1) {
            return response()->json(['message' => __('undo_disabled')], 403);
        }

        $reservation = Reservation::query()->where('undo_token', $token)->first();

        if (! $reservation) {
            $message = $this->customNotFoundMessage([]) ?? __('reservation_not_found');
            return response()->json(['message' => $message], 404);
        }

        $this->emailValidation->sendReservationNotification($reservation, 'email_reservation_cancel_template_id', false);

        $reservation->delete();

        // Trigger: reservation_removed (after deletion so placeholder values reflect current state)
        $this->eventTriggers->handle('reservation_removed', ['reservation' => $reservation]);

        // Trigger: reservation_canceled (z.B. bei erfolgreichem Undo)
        $this->eventTriggers->handle('reservation_canceled', ['reservation' => $reservation]);

        if ((int) ($settings['waitlist_auto_promote_enabled'] ?? 0) === 1) {
            try {
                $this->waitlist->promoteOldestIfSlotAvailable();
            } catch (\Throwable $e) {
                Log::warning('Auto-promote failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['message' => __('reservation_removed')]);
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

    /**
     * Resolve custom not-found message with placeholders if configured.
     */
    private function customNotFoundMessage(array $recipient): ?string
    {
        $raw = (string) ($this->settings->get('reservation_undo_not_found_text', '') ?? '');
        if ($raw === '') {
            return null;
        }

        $replacements = $this->placeholders->replacements([
            'name' => $recipient['name'] ?? '',
            'email' => $recipient['email'] ?? '',
            'undo_link' => '',
            'undo_link_html' => '',
            'validation_link' => '',
            'validation_link_html' => '',
        ]);

        return strtr($raw, $replacements);
    }

    /**
     * Find a reservation by case-insensitive name and decrypted email.
     */
    private function findReservationByNameAndEmail(string $name, string $email): ?Reservation
    {
        $emailLower = Str::lower($email);

        $candidates = Reservation::query()
            ->whereRaw('LOWER(display_name) = ?', [Str::lower($name)])
            ->get();

        $match = $candidates->first(function (Reservation $reservation) use ($emailLower) {
            $resEmail = (string) ($reservation->email ?? '');
            return Str::lower($resEmail) === $emailLower;
        });

        if ($match) {
            \Log::debug('Reservation undo: matched reservation', [
                'reservation_id' => $match->id,
                'name' => $match->display_name,
            ]);
        }

        return $match;
    }
}
