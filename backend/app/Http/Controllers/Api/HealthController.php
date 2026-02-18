<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    /**
     * Public health check endpoint for the backend service.
     * Returns HTTP 200 when healthy, HTTP 503 when unhealthy.
     */
    public function check(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // Check database connectivity
        try {
            DB::select('SELECT 1');
            $checks['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
            $healthy = false;
        }

        // Check Redis/cache connectivity
        try {
            Cache::store(config('cache.default'))->get('health_check_ping');
            $checks['cache'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $checks['cache'] = ['status' => 'error', 'message' => $e->getMessage()];
            $healthy = false;
        }

        // Check storage is writable
        $storagePath = storage_path('framework/cache');
        $checks['storage'] = ['status' => is_writable($storagePath) ? 'ok' : 'error'];
        if ($checks['storage']['status'] === 'error') {
            $healthy = false;
        }

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'service' => 'backend',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }
}
