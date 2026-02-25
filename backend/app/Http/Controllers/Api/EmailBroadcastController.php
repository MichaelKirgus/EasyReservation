<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailBroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailBroadcastController extends Controller
{
    public function __construct(private readonly EmailBroadcastService $service)
    {
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:email_templates,id'],
            'transport_group_id' => ['nullable', 'integer', 'exists:mail_transport_groups,id'],
            'scope' => ['required', 'in:reservations,waitlist,both,selection,internal_users'],
            'send_to_all' => ['sometimes', 'boolean'],
            'deduplicate' => ['sometimes', 'boolean'],
            'reservation_ids' => ['sometimes', 'array'],
            'reservation_ids.*' => ['integer', 'exists:reservations,id'],
            'waitlist_ids' => ['sometimes', 'array'],
            'waitlist_ids.*' => ['integer', 'exists:waitlist_entries,id'],
            'custom_recipients' => ['sometimes', 'array'],
            'custom_recipients.*.email' => ['required_with:custom_recipients', 'email'],
            'custom_recipients.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'user_roles' => ['sometimes', 'array'],
            'user_roles.*' => ['in:admin,moderator,user'],
        ]);

        $scope = $data['scope'];
        $sendToAll = (bool) ($data['send_to_all'] ?? $scope !== 'selection');
        $deduplicate = (bool) ($data['deduplicate'] ?? true);
        $reservationIds = $data['reservation_ids'] ?? [];
        $waitlistIds = $data['waitlist_ids'] ?? [];
        $customRecipients = $data['custom_recipients'] ?? [];
        $userRoles = $data['user_roles'] ?? [];
        $transportGroupId = $data['transport_group_id'] ?? null;

        try {
            $result = $this->service->queueBroadcast(
                (int) $data['template_id'],
                $scope,
                $sendToAll,
                $reservationIds,
                $waitlistIds,
                $customRecipients,
                $deduplicate,
                $userRoles,
                $transportGroupId,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => __('emails_queued_successfully'),
            'result' => $result,
        ]);
    }
}
