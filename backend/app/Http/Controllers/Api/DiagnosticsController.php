<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DiagnosticsService;
use App\Services\WorkerStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiagnosticsController extends Controller
{
    public function __construct(private readonly DiagnosticsService $diagnostics)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->diagnostics->snapshot());
    }
    
    public function redisKeys()
    {
        return response()->json(app(\App\Services\DiagnosticsService::class)->redisKeys());
    }

    public function deleteRedisKey(Request $request)
    {
        $key = $request->input('key');
        if (!$key) {
            return response()->json(['error' => __('key_missing')], 400);
        }
        try {
            \Illuminate\Support\Facades\Redis::del($key);
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function workers(WorkerStatusService $service)
    {
        // Legacy endpoint – delegates to the refactored WorkerStatusService.
        // The new dedicated endpoint is GET /api/admin/worker-stats.
        return response()->json($service->getAllStatuses());
    }
}
