<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * Handle an incoming request.
     *
     * @param  array<int,string>  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $apiKey = $request->header('X-Api-Key')
            ?? $request->query('api_key')
            ?? $request->cookie('api_session');

        if (! $apiKey) {
            return response()->json([
                'message' => __('middleware_missing_api_key'),
            ], Response::HTTP_FORBIDDEN);
        }

        $candidate = User::query()
            ->where('active', true)
            ->whereNotNull('api_token')
            ->whereIn('role', $roles)
            ->get()
            ->first(function (User $user) use ($apiKey) {
                if ($user->api_token_is_hashed) {
                    return Hash::check($apiKey, $user->api_token);
                }

                return hash_equals((string) $user->api_token, (string) $apiKey);
            });

        if (! $candidate) {
            return response()->json([
                'message' => __('middleware_unauthorized_role', ['roles' => implode(', ', $roles)]),
            ], Response::HTTP_FORBIDDEN);
        }

        $request->setUserResolver(fn () => $candidate);
        \Auth::login($candidate);

        return $next($request);
    }
}
