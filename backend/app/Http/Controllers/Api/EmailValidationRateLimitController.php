<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RateLimitCacheService;

class EmailValidationRateLimitController extends Controller
{
    public function __construct(private RateLimitCacheService $rateLimitCache) {}

    /**
     * Parse keys like: email_validation_rate:<ip>:<YYYYMMDDHH>
     * Works with IPv4 and IPv6 because it does not rely on colon splitting.
     *
     * @return array{ip:string,hour:string}|null
     */
    private function parseEmailValidationRateKey(string $logicalKey): ?array
    {
        $prefix = 'email_validation_rate:';
        if (!str_starts_with($logicalKey, $prefix)) {
            return null;
        }

        $rest = substr($logicalKey, strlen($prefix));
        $lastColonPos = strrpos($rest, ':');
        if ($lastColonPos === false) {
            return null;
        }

        $ip = substr($rest, 0, $lastColonPos);
        $hour = substr($rest, $lastColonPos + 1);

        if ($ip === '' || !preg_match('/^\d{10}$/', $hour)) {
            return null;
        }

        return ['ip' => $ip, 'hour' => $hour];
    }

    // GET /admin/email-validation-rate-limits
    public function index()
    {
        $keys = $this->rateLimitCache->findKeys('email_validation_rate:');
        $result = [];
        foreach ($keys as $logicalKey) {
            $parsed = $this->parseEmailValidationRateKey($logicalKey);
            if (!$parsed) {
                continue;
            }

            $ip = $parsed['ip'];
            $hour = $parsed['hour'];
            $count = $this->rateLimitCache->get($logicalKey, 0);

            $result[] = [
                'ip' => $ip,
                'count' => $count,
                'hour' => $hour,
            ];
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
