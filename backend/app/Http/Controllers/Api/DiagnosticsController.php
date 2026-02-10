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
        $workers = $service->getAllStatuses();
        // Aufbereitung: Speicher in MB, Zeitstempel als Datum
        foreach ($workers as &$data) {
            if (isset($data['memory'])) {
                $data['memory'] = round($data['memory'] / 1024 / 1024, 2);
            }
            if (isset($data['timestamp'])) {
                $data['timestamp'] = date('Y-m-d H:i:s', $data['timestamp']);
            }
            if (isset($data['jobs'])) {
                $data['jobs'] = is_string($data['jobs']) ? json_decode($data['jobs'], true) : $data['jobs'];
            }
        }
        return response()->json($workers);
    }
}
