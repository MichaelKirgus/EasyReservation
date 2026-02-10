<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModeratorKey
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Moderator-Key') ?? $request->query('moderatorpw');
        $expected = $this->settings->moderatorKey();

        if ($expected && $key && hash_equals((string) $expected, (string) $key)) {
            return $next($request);
        }

        return response()->json([
            'message' => __('middleware_unauthorized_moderator'),
        ], Response::HTTP_FORBIDDEN);
    }
}
