<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'], // name oder email
            'password' => ['required', 'string'],
            'otp' => ['nullable', 'string'],
        ]);

        $user = User::query()
            ->where('active', true)
            ->whereIn('role', ['admin', 'moderator', 'user', 'guest', 'superadmin'])
            ->where(function ($q) use ($data) {
                $q->where('email', $data['identifier'])->orWhere('name', $data['identifier']);
            })
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => __('auth_invalid_credentials')], 403);
        }

        // Check if user has two-factor authentication enabled
        if (! empty($user->two_factor_secret)) {
            $otp = $data['otp'] ?? null;

            // If no OTP code provided, tell the frontend to request one
            if (empty($otp)) {
                return response()->json([
                    'message' => __('two_factor_required'),
                    'two_factor' => true,
                ], 403);
            }

            // Verify the OTP code
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
            $valid = app(TwoFactorAuthenticationProvider::class)->verify($secret, $otp);

            // If OTP is invalid, also check recovery codes
            if (! $valid) {
                $valid = $this->validateRecoveryCode($user, $otp);
            }

            if (! $valid) {
                return response()->json([
                    'message' => __('two_factor_invalid_code'),
                    'two_factor' => true,
                ], 403);
            }
        }

        $plainToken = Str::random(40);
        if ($user->api_token_is_hashed) {
            $user->api_token = Hash::make($plainToken);
        } else {
            $user->api_token = $plainToken;
        }
        $user->save();

        return response()->json([
            'api_token' => $plainToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Check if the given code is a valid recovery code and consume it.
     */
    private function validateRecoveryCode(User $user, string $code): bool
    {
        if (empty($user->two_factor_recovery_codes)) {
            return false;
        }

        $recoveryCodes = json_decode(
            Fortify::currentEncrypter()->decrypt($user->two_factor_recovery_codes),
            true
        );

        if (! is_array($recoveryCodes) || ! in_array($code, $recoveryCodes, true)) {
            return false;
        }

        // Consume the recovery code by replacing it
        $user->replaceRecoveryCode($code);

        return true;
    }
}
