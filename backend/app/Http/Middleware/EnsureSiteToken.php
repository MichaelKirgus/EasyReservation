<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class EnsureSiteToken
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tokenRequired = $this->settings->isTokenRequired();

        $headerToken = $request->header('X-Site-Token');
        $queryToken = $request->query('t');
        $apiKey = $request->header('X-Api-Key') ?? $request->cookie('api_session');

        // Allow valid user token (e.g., guest/admin/moderator) to bypass site token
        if ($apiKey && $this->isValidUserToken($apiKey)) {
            return $next($request);
        }

        $incoming = $headerToken ?: $queryToken;

        if (! $tokenRequired) {
            return $next($request);
        }

        // Prüfe, ob der übergebene Token zu einem aktiven Gast-Benutzer gehört
        if ($incoming && $this->isValidGuestToken($incoming)) {
            return $next($request);
        }

        return response()->json([
            'message' => __('middleware_invalid_site_token'),
        ], Response::HTTP_FORBIDDEN);
    }

    private function isValidUserToken(string $token): bool
    {
        $candidates = User::query()->where('active', true)->get();
        foreach ($candidates as $user) {
            if (! $user->api_token) {
                continue;
            }
            if ($user->api_token_is_hashed) {
                if (Hash::check($token, $user->api_token)) {
                    return true;
                }
            } else {
                if (hash_equals((string) $user->api_token, (string) $token)) {
                    return true;
                }
            }
        }

        return false;
    }
    private function isValidGuestToken(string $token): bool
    {
        $candidates = \App\Models\User::query()->where('active', true)->where('role', 'guest')->get();
        foreach ($candidates as $user) {
            if (! $user->api_token) {
                continue;
            }
            if ($user->api_token_is_hashed) {
                if (\Illuminate\Support\Facades\Hash::check($token, $user->api_token)) {
                    return true;
                }
            } else {
                if (hash_equals((string) $user->api_token, (string) $token)) {
                    return true;
                }
            }
        }
        return false;
    }
}
