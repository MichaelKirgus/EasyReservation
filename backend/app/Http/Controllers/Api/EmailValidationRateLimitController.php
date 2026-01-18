<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\EmailValidationService;

class EmailValidationRateLimitController extends Controller
{
    public function __construct(private EmailValidationService $service) {}

    // GET /admin/email-validation-rate-limits
    public function index()
    {
        $pattern = 'email_validation_rate:*';
        $keys = Cache::getRedis()->keys($pattern);
        $result = [];
        foreach ($keys as $key) {
            $parts = explode(':', $key);
            $ip = $parts[1] ?? null;
            $hour = $parts[2] ?? null;
            $count = Cache::get($key, 0);
            if ($ip && $hour) {
                $result[] = [
                    'ip' => $ip,
                    'count' => $count,
                    'hour' => $hour,
                ];
            }
        }
        return response()->json($result);
    }

    // DELETE /admin/email-validation-rate-limits/{ip}
    public function destroy($ip)
    {
        $pattern = 'email_validation_rate:' . $ip . ':*';
        $keys = Cache::getRedis()->keys($pattern);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        return response()->noContent();
    }

    // DELETE /admin/email-validation-rate-limits
    public function destroyAll()
    {
        $pattern = 'email_validation_rate:*';
        $keys = Cache::getRedis()->keys($pattern);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        return response()->noContent();
    }
}
