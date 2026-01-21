<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DiagnosticsService;
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
            return response()->json(['error' => 'Key fehlt'], 400);
        }
        try {
            \Illuminate\Support\Facades\Redis::del($key);
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
