<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailValidationService;
use Illuminate\Http\JsonResponse;

class EmailValidationController extends Controller
{
    public function __construct(private readonly EmailValidationService $validationService)
    {
    }

    public function verify(string $token): JsonResponse
    {
        try {
            $result = $this->validationService->verifyToken($token);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        if (($result['pending_admin'] ?? false) === true) {
            return response()->json([
                'message' => 'E-Mail bestÃ¤tigt. Wartet auf Freigabe durch Admin.',
                'pending_admin' => true,
            ], 202);
        }

        $waitlist = (bool) ($result['waitlist'] ?? false);

        return response()->json([
            'message' => $waitlist ? 'E-Mail bestÃ¤tigt. Eintrag auf Warteliste erstellt.' : 'E-Mail bestÃ¤tigt. Reservierung erstellt.',
            'waitlist' => $waitlist,
            'reservation' => $result['reservation'] ?? null,
            'entry' => $result['entry'] ?? null,
        ]);
    }

    public function approve(string $token): JsonResponse
    {
        try {
            $result = $this->validationService->approveToken($token);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        $waitlist = (bool) ($result['waitlist'] ?? false);

        return response()->json([
            'message' => $waitlist ? __('waitlist_entry_approved') : __('reservation_approved'),
            'waitlist' => $waitlist,
            'result' => $result,
        ]);
    }
}
