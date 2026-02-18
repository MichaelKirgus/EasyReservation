<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WorkerStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerStatsController extends Controller
{
    public function __construct(private readonly WorkerStatusService $service)
    {
    }

    /**
     * GET /api/admin/worker-stats
     *
     * Returns all tracked workers with normalised fields.
     * ?online_only=1 to filter to workers that sent a heartbeat within the TTL window.
     */
    public function index(Request $request): JsonResponse
    {
        $onlineOnly = $request->boolean('online_only', false);
        $workers    = $this->service->getAllStatuses($onlineOnly);

        return response()->json([
            'driver'  => config('workerstatus.driver'),
            'ttl'     => config('workerstatus.ttl'),
            'workers' => $workers,
        ]);
    }

    /**
     * DELETE /api/admin/worker-stats/cleanup
     *
     * Purge stale entries (database driver only).
     */
    public function cleanup(): JsonResponse
    {
        $deleted = $this->service->cleanup();

        return response()->json([
            'deleted' => $deleted,
        ]);
    }
}
