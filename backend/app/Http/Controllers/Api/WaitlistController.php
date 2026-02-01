<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use App\Services\ReservationValidationService;
use App\Services\WaitlistService;
use App\Services\SettingsService;
use App\Services\EventTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WaitlistController extends Controller
{
    public function __construct(
        private readonly WaitlistService $waitlist,
        private readonly ReservationValidationService $validator,
        private readonly SettingsService $settings,
        private readonly EventTriggerService $eventTriggers,
        private readonly SiteTokenService $siteTokenService,
    ) {
    }

    public function index(): JsonResponse
    {
        $entries = WaitlistEntry::query()
            ->orderBy('date_added')
            ->get();

        return response()->json($entries);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'payload' => ['nullable', 'array'],
            'notify' => ['nullable'],
            'site_token' => ['nullable', 'string', 'max:255'], // site_token erlauben
        ]);

        $name = trim($data['name']);
        $email = trim((string) ($data['email'] ?? ''));
        $payload = $data['payload'] ?? [];
        $siteToken = $data['site_token'] ?? null;

        if (! $this->validator->nameIsValid($name)) {
            return response()->json(['message' => 'Invalid name.'], 422);
        }

        if (! $this->validator->emailIsValid($email)) {
            return response()->json(['message' => 'Invalid email.'], 422);
        }

        try {
            // If no site token provided, get a valid one from guest users - but only for admin/moderator users
            if (empty($siteToken)) {
                $user = auth()->user();
                if ($user && in_array($user->role, ['admin', 'superadmin', 'moderator'])) {
                    $siteToken = $this->siteTokenService->getValidSiteToken();
                } else {
                    return response()->json(['message' => 'Site token is required for waitlist entries.'], 422);
                }
            }
            $entry = $this->waitlist->addToWaitlist($name, $email, $payload, $siteToken);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        // Trigger: waitlist_enabled (bei erfolgreichem Eintrag)
        $this->eventTriggers->handle('waitlist_enabled', ['waitlist_entry' => $entry]);

        if ($this->shouldNotify($request, $data['notify'] ?? null)) {
            $this->waitlist->sendWaitlistValidationSuccessEmail($entry);
        }

        return response()->json($entry, 201);
    }

    public function destroy(Request $request, WaitlistEntry $entry): JsonResponse
    {
        $shouldNotify = $this->shouldNotify($request, $request->input('notify'));

        if ($entry->status !== 'cancelled') {
            $entry->status = 'cancelled';
            $entry->save();
        }

        // Trigger: waitlist_disabled (bei Löschung)
        $this->eventTriggers->handle('waitlist_disabled', ['waitlist_entry' => $entry]);

        if ($shouldNotify) {
            $this->waitlist->sendWaitlistCancelledEmail($entry);
        }

        $entry->delete();

        return response()->json(['message' => 'Waitlist entry deleted.']);
    }

    public function promote(WaitlistEntry $entry): JsonResponse
    {
        try {
            $reservation = $this->waitlist->promoteEntry($entry);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Waitlist entry promoted.',
            'reservation' => $reservation,
        ]);
    }

    public function update(Request $request, WaitlistEntry $entry): JsonResponse
    {
        if ($entry->status !== 'pending') {
            return response()->json(['message' => 'Entry already processed.'], 409);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'display_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'payload' => ['sometimes', 'nullable', 'array'],
        ]);

        $name = $data['display_name'] ?? $data['name'] ?? $entry->display_name;
        $email = array_key_exists('email', $data) ? (string) $data['email'] : (string) $entry->email;

        if (! $this->validator->nameIsValid($name)) {
            return response()->json(['message' => 'Invalid name.'], 422);
        }

        if (! $this->validator->emailIsValid($email)) {
            return response()->json(['message' => 'Invalid email.'], 422);
        }

        $entry->display_name = $name;
        $entry->email = $email === '' ? null : $email;
        if (array_key_exists('payload', $data)) {
            $entry->payload = $data['payload'];
        }
        $entry->save();

        return response()->json($entry);
    }

    public function export(): StreamedResponse
    {
        $entries = WaitlistEntry::query()
            ->orderBy('date_added')
            ->get(['id', 'display_name', 'email', 'payload', 'status', 'date_added']);

        $callback = function () use ($entries) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['id', 'display_name', 'email', 'payload', 'status', 'date_added'], ';');
            foreach ($entries as $entry) {
                fputcsv($handle, [
                    $entry->id,
                    $entry->display_name,
                    $entry->email,
                    is_array($entry->payload) ? json_encode($entry->payload) : $entry->payload,
                    $entry->status,
                    $entry->date_added,
                ], ';');
            }
            fclose($handle);
        };

        return response()->streamDownload($callback, 'waitlist_'.now()->format('Ymd_His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function undoByToken(string $token): JsonResponse
    {
        $settings = $this->settings->all();
        if ((int) ($settings['waitlist_undo_enabled'] ?? 0) !== 1) {
            return response()->json(['message' => 'Undo is disabled.'], 403);
        }

        $entry = WaitlistEntry::query()
            ->where('status', 'pending')
            ->where('undo_token', $token)
            ->first();

        if (! $entry) {
            return response()->json(['message' => 'Waitlist entry not found.'], 404);
        }

        $entry->undo_used_at = now();
        $entry->status = 'cancelled';
        $entry->save();

        // Send cancellation confirmation before deleting the entry
        $this->waitlist->sendWaitlistCancelledEmail($entry);

        $entry->delete();

        return response()->json(['message' => 'Waitlist entry removed.']);
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
