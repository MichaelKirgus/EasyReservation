<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EventTriggerService;
use App\Services\SettingsService;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class AuthController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TranslationService $translationService,
        private readonly EventTriggerService $eventTriggers,
    ) {}

    public function login(Request $request): JsonResponse
    {
        // Set locale based on frontend's selected language (if provided)
        $lang = $request->query('lang');
        if ($lang && $this->translationService->localeExists($lang)) {
            app()->setLocale($lang);
        }

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
            $this->eventTriggers->handle('login_failed', [
                'login_identifier' => $data['identifier'],
                'login_ip' => $request->ip(),
            ]);
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
                $this->eventTriggers->handle('login_failed', [
                    'user' => $user,
                    'login_identifier' => $data['identifier'],
                    'login_ip' => $request->ip(),
                ]);
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

        $remember = $request->boolean('remember', false);
        $configuredMinutes = $this->settings->sessionLifetimeMinutes();
        // 0 = session cookie (expires when browser closes), otherwise use configured value
        $cookieMinutes = $remember ? ($configuredMinutes > 0 ? $configuredMinutes : 60 * 24 * 30) : 0;
        $secure = $request->isSecure();

        $cookie = Cookie::make(
            'api_session',
            $plainToken,
            $cookieMinutes,
            '/',
            null,
            $secure,
            true, // httpOnly — not accessible via JavaScript
            false,
            'Lax'
        );

        // Clear translation cache to ensure fresh translations are loaded for the new user role
        $this->translationService->flush();

        $this->eventTriggers->handle('login_succeeded', ['user' => $user]);

        return response()->json([
            'api_token' => $plainToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ])->cookie($cookie);
    }

    /**
     * Log out the current user by clearing the auth cookie.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $this->resolveUserFromRequest($request);
        if ($user) {
            $this->eventTriggers->handle('logout', ['user' => $user]);
        }

        // Clear translation cache on logout to ensure clean state for next login
        $this->translationService->flush();

        $cookie = Cookie::forget('api_session', '/');

        return response()->json(['message' => 'Logged out.'])->cookie($cookie);
    }

    /**
     * Resolve the currently authenticated user from the api token cookie/header (logout route has no auth middleware).
     */
    private function resolveUserFromRequest(Request $request): ?User
    {
        $apiKey = $request->header('X-Api-Key')
            ?? $request->query('api_key')
            ?? $request->cookie('api_session');

        if (! $apiKey) {
            return null;
        }

        return User::query()
            ->where('active', true)
            ->whereNotNull('api_token')
            ->get()
            ->first(function (User $candidate) use ($apiKey) {
                if ($candidate->api_token_is_hashed) {
                    return Hash::check($apiKey, $candidate->api_token);
                }

                return hash_equals((string) $candidate->api_token, (string) $apiKey);
            });
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
