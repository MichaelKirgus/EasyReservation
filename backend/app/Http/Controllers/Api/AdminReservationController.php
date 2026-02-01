<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\EmailService;
use App\Services\ReservationValidationService;
use App\Services\SettingsService;
use App\Services\WaitlistService;
use App\Services\EmailValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReservationController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly WaitlistService $waitlist,
        private readonly ReservationValidationService $validator,
        private readonly EmailValidationService $emailValidation,
        private readonly EmailService $emailService,
    ) {
    }

    public function notificationDefaults(Request $request): JsonResponse
    {
        $role = $request->is('admin/*') ? 'admin' : 'moderator';
        $key = $role === 'admin' ? 'admin_reservation_notify_default' : 'moderator_reservation_notify_default';
        $value = (int) ($this->settings->get($key, 0) ?? 0) === 1;

        return response()->json([
            'role' => $role,
            'notify_default' => $value,
        ]);
    }

    public function index(): JsonResponse
    {
        $reservations = Reservation::query()
            ->orderByDesc('date_added')
            ->get();

        return response()->json($reservations);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'payload' => ['nullable', 'array'],
            'notify' => ['nullable'],
        ]);

        $name = trim($data['name']);
        $email = trim((string) ($data['email'] ?? ''));
        $payload = $data['payload'] ?? null;

        if (! $this->validator->nameIsValid($name)) {
            return response()->json(['message' => 'Invalid name.'], 422);
        }

        if (! $this->validator->emailIsValid($email)) {
            return response()->json(['message' => 'Invalid email.'], 422);
        }

        $duplicate = Reservation::query()
            ->whereRaw('LOWER(display_name) = ?', [Str::lower($name)])
            ->exists();

        if ($duplicate) {
            return response()->json(['message' => 'Name already reserved.'], 409);
        }

        $reservation = Reservation::create([
            'display_name' => $name,
            'email' => $email === '' ? null : $email,
            'payload' => $payload,
            'undo_token' => (string) Str::uuid(),
        ]);

        if ($this->shouldNotify($request, $data['notify'] ?? null)) {
            $this->emailValidation->sendReservationNotification($reservation, 'email_reservation_success_template_id', true);
        }

        return response()->json($reservation, 201);
    }

    public function destroy(Request $request, Reservation $reservation): JsonResponse
    {
        $shouldNotify = $this->shouldNotify($request, $request->input('notify'));

        if ($shouldNotify) {
            $this->emailValidation->sendReservationNotification($reservation, 'email_reservation_cancel_template_id', false);
        }

        $reservation->delete();

        if ((int) ($this->settings->get('waitlist_auto_promote_enabled', 0) ?? 0) === 1) {
            try {
                $this->waitlist->promoteOldestIfSlotAvailable();
            } catch (\Throwable $e) {
                Log::warning('Auto-promote failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['message' => 'Reservation deleted.']);
    }

    public function export(): StreamedResponse
    {
        $reservations = Reservation::query()
            ->orderBy('date_added')
            ->get(['id', 'display_name', 'email', 'payload', 'date_added']);

        $callback = function () use ($reservations) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['id', 'display_name', 'email', 'payload', 'date_added'], ';');
            foreach ($reservations as $reservation) {
                fputcsv($handle, [
                    $reservation->id,
                    $reservation->display_name,
                    $reservation->email,
                    is_array($reservation->payload) ? json_encode($reservation->payload) : $reservation->payload,
                    $reservation->date_added,
                ], ';');
            }
            fclose($handle);
        };

        $fileName = 'reservations_'.Str::slug(now()).'.csv';

        return response()->streamDownload($callback, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'payload' => ['sometimes', 'nullable', 'array'],
            'notify' => ['nullable'],
        ]);

        $name = $data['display_name'] ?? $data['name'] ?? $reservation->display_name;
        $email = array_key_exists('email', $data) ? (string) $data['email'] : (string) $reservation->email;

        if (! $this->validator->nameIsValid($name)) {
            return response()->json(['message' => 'Invalid name.'], 422);
        }

        if (! $this->validator->emailIsValid($email)) {
            return response()->json(['message' => 'Invalid email.'], 422);
        }

        $reservation->display_name = $name;
        $reservation->email = $email === '' ? null : $email;

        if (array_key_exists('payload', $data)) {
            $reservation->payload = $data['payload'];
        }

        if (empty($reservation->undo_token)) {
            $reservation->undo_token = (string) Str::uuid();
        }

        $reservation->save();

        if ($this->shouldNotify($request, $data['notify'] ?? null)) {
            $this->emailValidation->sendReservationNotification($reservation, 'email_reservation_success_template_id', true);
        }

        return response()->json($reservation);
    }

    private function shouldNotify(Request $request, mixed $override): bool
    {
        if ($override !== null) {
            return filter_var($override, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? ((int) $override === 1);
        }

        $role = $request->is('admin/*') ? 'admin' : 'moderator';
        $key = $role === 'admin' ? 'admin_reservation_notify_default' : 'moderator_reservation_notify_default';

        return (int) ($this->settings->get($key, 0) ?? 0) === 1;
    }
}
