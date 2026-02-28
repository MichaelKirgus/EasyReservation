<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RateLimitCacheService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RateLimitDiagnosticsController extends Controller
{
    public function __construct(
        private RateLimitCacheService $rateLimitCache,
        private SettingsService $settings
    ) {}

    // GET /admin/rate-limit-diagnostics
    public function index(): JsonResponse
    {
        // Find all login rate-limit keys (prefix may vary by implementation)
        $loginKeys = $this->rateLimitCache->findKeys('login:');
        $loginLimits = [];
        foreach ($loginKeys as $key) {
            $count = $this->rateLimitCache->get($key, 0);
            $loginLimits[] = [
                'key' => $key,
                'count' => $count,
            ];
        }
        // Add config info
        $config = [
            'attempts' => $this->settings->loginRateLimitAttempts(),
            'decay_minutes' => $this->settings->loginRateLimitDecayMinutes(),
        ];
        return response()->json([
            'login_limits' => $loginLimits,
            'config' => $config,
        ]);
    }
}
