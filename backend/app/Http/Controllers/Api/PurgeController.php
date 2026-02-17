<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;

class PurgeController extends Controller
{
    public function purgeAll(Request $request)
    {
        DB::transaction(function () {
            // Delete all reservations
            \App\Models\Reservation::query()->delete();
            // Delete all waitlist entries
            if (class_exists('App\\Models\\WaitlistEntry')) {
                \App\Models\WaitlistEntry::query()->delete();
            } elseif (class_exists('App\\Models\\Waitlist')) {
                \App\Models\Waitlist::query()->delete();
            }
        });
        // Delete all email validation rate limits (Redis keys)
        $store = \Cache::getStore();
        $prefix = method_exists($store, 'getPrefix') ? $store->getPrefix() : '';
        $redis = $store->connection();
        $keys = $redis->keys($prefix . 'email_validation_rate:*');
        foreach ($keys as $key) {
            $logicalKey = ($prefix !== '' && str_starts_with($key, $prefix))
                ? substr($key, strlen($prefix))
                : $key;
            \Cache::forget($logicalKey);
        }
        return response()->json(['success' => true]);
    }
}
