<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailValidation;
use App\Services\EmailValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailValidationAdminController extends Controller
{
    public function __construct(private readonly EmailValidationService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $type = $request->query('type');

        $query = EmailValidation::query()->orderByDesc('created_at');

        if ($status === 'pending') {
            $query->whereIn('status', ['email_pending', 'waiting_admin', 'ready']);
        } elseif ($status) {
            $query->where('status', $status);
        }

        if ($type) {
            $query->where('type', $type);
        }

        return response()->json($query->get());
    }

    public function approve(EmailValidation $validation): JsonResponse
    {
        try {
            $result = $this->service->approve($validation);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        $waitlist = (bool) ($result['waitlist'] ?? false);

        return response()->json([
            'message' => $waitlist ? __('waitlist_entry_approved') : __('reservation_approved'),
            'result' => $result,
        ]);
    }

    public function resend(EmailValidation $validation): JsonResponse
    {
        try {
            $this->service->resendValidationEmail($validation);
            return response()->json(['message' => __('validation_email_resent')]);
        } catch (\Throwable $e) {
            return response()->json(['message' => __('error_generic', ['message' => $e->getMessage()])], 400);
        }
    }

    public function destroy(EmailValidation $validation): JsonResponse
    {
        $validation->status = 'cancelled';
        $validation->last_error = 'Cancelled by admin';
        $validation->save();

        return response()->json(['message' => __('validation_discarded')]);
    }
}
