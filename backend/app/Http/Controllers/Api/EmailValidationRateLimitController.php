<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RateLimitCacheService;

class EmailValidationRateLimitController extends Controller
{
    public function __construct(private RateLimitCacheService $rateLimitCache) {}

    // GET /admin/email-validation-rate-limits
    public function index()
    {
        $keys = $this->rateLimitCache->findKeys('email_validation_rate:');
        $result = [];
        foreach ($keys as $logicalKey) {
            $parts = explode(':', $logicalKey);
            // logicalKey = email_validation_rate:<ip>:<hour>
            $ip = $parts[1] ?? null;
            $hour = $parts[2] ?? null;
            $count = $this->rateLimitCache->get($logicalKey, 0);
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
        $this->rateLimitCache->forgetByPrefix('email_validation_rate:' . $ip . ':');
        return response()->noContent();
    }

    // DELETE /admin/email-validation-rate-limits
    public function destroyAll()
    {
        $this->rateLimitCache->forgetByPrefix('email_validation_rate:');
        return response()->noContent();
    }

    // GET /admin/email-validation-admin-rate-limits
    public function adminApprovalIndex()
    {
        $keys = $this->rateLimitCache->findKeys('email_validation_admin_rate:');
        $result = [];
        foreach ($keys as $logicalKey) {
            $parts = explode(':', $logicalKey);
            // logicalKey = email_validation_admin_rate:global:<hour>
            $scope = $parts[1] ?? null;
            $hour = $parts[2] ?? null;
            $count = $this->rateLimitCache->get($logicalKey, 0);
            if ($scope && $hour) {
                $result[] = [
                    'scope' => $scope,
                    'count' => $count,
                    'hour' => $hour,
                ];
            }
        }
        return response()->json($result);
    }

    // DELETE /admin/email-validation-admin-rate-limits
    public function destroyAllAdminApproval()
    {
        $this->rateLimitCache->forgetByPrefix('email_validation_admin_rate:');
        return response()->noContent();
    }
}
