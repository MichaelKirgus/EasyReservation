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

    /**
     * Return the cache prefix used by the current cache store.
     */
    private function getCachePrefix(): string
    {
        $store = Cache::getStore();
        return method_exists($store, 'getPrefix') ? $store->getPrefix() : '';
    }

    /**
     * Strip the cache prefix from a raw Redis key to get the logical cache key.
     */
    private function stripPrefix(string $key, string $prefix): string
    {
        if ($prefix !== '' && str_starts_with($key, $prefix)) {
            return substr($key, strlen($prefix));
        }
        return $key;
    }

    // GET /admin/email-validation-rate-limits
    public function index()
    {
        $prefix = $this->getCachePrefix();
        $redis = Cache::getStore()->connection();
        $keys = $redis->keys($prefix . 'email_validation_rate:*');
        $result = [];
        foreach ($keys as $key) {
            $logicalKey = $this->stripPrefix($key, $prefix);
            $parts = explode(':', $logicalKey);
            // logicalKey = email_validation_rate:<ip>:<hour>
            $ip = $parts[1] ?? null;
            $hour = $parts[2] ?? null;
            // Read via Cache facade so the value is properly unserialized
            $count = Cache::get($logicalKey, 0);
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
        $prefix = $this->getCachePrefix();
        $redis = Cache::getStore()->connection();
        $keys = $redis->keys($prefix . 'email_validation_rate:' . $ip . ':*');
        foreach ($keys as $key) {
            Cache::forget($this->stripPrefix($key, $prefix));
        }
        return response()->noContent();
    }

    // DELETE /admin/email-validation-rate-limits
    public function destroyAll()
    {
        $prefix = $this->getCachePrefix();
        $redis = Cache::getStore()->connection();
        $keys = $redis->keys($prefix . 'email_validation_rate:*');
        foreach ($keys as $key) {
            Cache::forget($this->stripPrefix($key, $prefix));
        }
        return response()->noContent();
    }
}
