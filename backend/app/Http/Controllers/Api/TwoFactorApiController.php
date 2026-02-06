<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Actions\GetTwoFactorQrCodeSvg;
use Illuminate\Http\JsonResponse;

class TwoFactorApiController extends Controller
{
    public function enable(Request $request)
    {
        $user = Auth::user();
        app(EnableTwoFactorAuthentication::class)($user);
        
        // Generate recovery codes when enabling 2FA
        if (empty($user->two_factor_recovery_codes)) {
            app(GenerateNewRecoveryCodes::class)($user);
        }
        
        return response()->json(['enabled' => true]);
    }

    public function disable(Request $request)
    {
        $user = Auth::user();
        app(DisableTwoFactorAuthentication::class)($user);
        return response()->json(['enabled' => false]);
    }

    public function qr(Request $request)
    {
        $user = Auth::user();
        $svg = $user->twoFactorQrCodeSvg();
        $secret = $user->two_factor_secret ? decrypt($user->two_factor_secret) : null;
        return response()->json(['svg' => $svg, 'secret' => $secret]);
    }

    public function recovery(Request $request)
    {
        $user = Auth::user();
        
        // Check if user has recovery codes
        if (empty($user->two_factor_recovery_codes)) {
            // Generate new recovery codes if they don't exist
            app(GenerateNewRecoveryCodes::class)($user);
        }
        
        // Return the recovery codes by decrypting them from the database
        $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        return response()->json($recoveryCodes);
    }

    public function confirm(Request $request)
    {
        $user = Auth::user();
        $ip = $request->ip();
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            \Log::warning('2FA confirm failed: validation error', [
                'user_id' => $user?->id,
                'email' => $user?->email,
                'ip' => $ip,
                'errors' => $validator->errors()->toArray(),
                'input' => $request->all(),
            ]);
            return response()->json([
                'confirmed' => false,
                'message' => 'The provided two factor authentication code was invalid.',
                'errors' => $validator->errors(),
            ], 422);
        }
        $result = app(ConfirmTwoFactorAuthentication::class)($user, $request->input('code'));
        if ($result) {
            \Log::info('2FA confirm success', [
                'user_id' => $user?->id,
                'email' => $user?->email,
                'ip' => $ip,
            ]);
            return response()->json(['confirmed' => true]);
        }
        \Log::warning('2FA confirm failed: invalid code', [
            'user_id' => $user?->id,
            'email' => $user?->email,
            'ip' => $ip,
            'input' => $request->all(),
        ]);
        return response()->json([
            'confirmed' => false,
            'message' => 'The provided two factor authentication code was invalid.',
            'errors' => ['code' => ['The provided two factor authentication code was invalid.']],
        ], 422);
    }

    public function status(Request $request)
    {
        $user = Auth::user();
        return response()->json([
            'two_factor_enabled' => !empty($user->two_factor_secret),
        ]);
    }
}
