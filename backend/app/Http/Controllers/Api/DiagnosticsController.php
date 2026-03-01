<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DiagnosticsService;
use App\Services\WorkerStatusService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;

class DiagnosticsController extends Controller
{
    public function __construct(private readonly DiagnosticsService $diagnostics, private readonly Filesystem $files)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 25);
        $limit = max(1, min($limit, 1000)); // Clamp between 1 and 1000
        return response()->json($this->diagnostics->snapshot($limit));
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

    public function flushState(): JsonResponse
    {
        try {
            $this->flushRedisStore();
            $this->flushSessions();
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => __('diagnostics_flush_error'),
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function flushRedisStore(): void
    {
        try {
            Redis::flushdb();
        } catch (\Throwable $e) {
            // Log but don't stop session cleanup
            report($e);
        }
    }

    private function flushSessions(): void
    {
        $driver = config('session.driver');

        try {
            switch ($driver) {
                case 'file':
                    $path = config('session.files');
                    if ($path && $this->files->isDirectory($path)) {
                        foreach ($this->files->files($path) as $file) {
                            $this->files->delete($file);
                        }
                    }
                    break;
                case 'database':
                    $table = config('session.table', 'sessions');
                    DB::table($table)->truncate();
                    break;
                case 'redis':
                    $connection = config('session.connection');
                    try {
                        Redis::connection($connection)->flushdb();
                    } catch (\Throwable $e) {
                        report($e);
                    }
                    break;
                case 'array':
                    // In-memory only; nothing to clear.
                    break;
                default:
                    $handler = Session::getHandler();
                    if (method_exists($handler, 'flush')) {
                        $handler->flush();
                    }
                    break;
            }
        } catch (\Throwable $e) {
            report($e);
            throw $e;
        }
    }

    public function workers(WorkerStatusService $service)
    {
        // Legacy endpoint – delegates to the refactored WorkerStatusService.
        // The new dedicated endpoint is GET /api/admin/worker-stats.
        return response()->json($service->getAllStatuses());
    }
}
