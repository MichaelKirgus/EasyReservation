<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionList;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Models\ValidationRule;
use App\Services\LinkBuildingService;
use App\Services\RateLimitCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ModerationDashboardController extends Controller
{
    public function __construct(private LinkBuildingService $linkBuildingService) {}

    /**
     * Get dashboard statistics for moderators.
     */
    public function stats(): JsonResponse
    {
        // Reservation count (all reservations - all are active by default)
        $reservationCount = Reservation::count();

        // Waitlist count (entries waiting for validation or promotion)
        $waitlistCount = WaitlistEntry::where('status', 'pending')->count();

        // Mail validation pending - count of pending email validations from email_validations table
        $mailValidationPending = DB::table('email_validations')
            ->where('status', 'pending')
            ->count();

        // Rate limit entries - total count of rate limit keys in cache
        $rateLimitEntries = $this->getRateLimitEntryCount();

        // Daily trends - last 7 days reservation counts
        $dailyTrends = $this->getDailyReservationTrends(7);

        // Get the public URL with valid token using LinkBuildingService
        $publicUrl = $this->linkBuildingService->buildPublicReservationLink();

        return response()->json([
            'reservation_count' => $reservationCount,
            'waitlist_count' => $waitlistCount,
            'mail_validation_pending' => $mailValidationPending,
            'rate_limit_entries' => $rateLimitEntries,
            'daily_trends' => $dailyTrends,
            'public_url' => $publicUrl,
        ]);
    }

    /**
     * Execute an action list from moderation dashboard.
     * Security check: only allows execution if moderation_selectable = true
     */
    public function executeActionList(int $id): JsonResponse
    {
        // Security check - verify the action list is marked as moderation_selectable
        $actionList = ActionList::find($id);

        if (!$actionList) {
            return response()->json(['error' => __('action_list_not_found')], 404);
        }

        if (!$actionList->moderation_selectable) {
            return response()->json([
                'error' => __('action_list_not_allowed_for_moderation'),
                'message' => __('action_list_security_check_failed')
            ], 403);
        }

        // Execute the action list
        try {
            app(\App\Services\ActionListService::class)->executeActionList($id);
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => __('action_list_execution_failed'),
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get count of rate limit entries in cache.
     */
    private function getRateLimitEntryCount(): int
    {
        try {
            // Count login rate limits
            $loginKeys = $this->rateLimitCache->findKeys('login:');
            
            // Count email validation rate limits
            $emailValidationKeys = $this->rateLimitCache->findKeys('email_validation_rate:');
            
            return count($loginKeys) + count($emailValidationKeys);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get daily reservation trends for the last N days.
     */
    private function getDailyReservationTrends(int $days = 7): array
    {
        try {
            $trends = [];
            
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                
                // Count reservations created on this date (or active status set)
                $count = Reservation::whereDate('created_at', $date)->count();
                
                $trends[] = [
                    'date' => $date,
                    'count' => $count
                ];
            }
            
            return $trends;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get the public reservation URL with valid token.
     */
    private function getPublicReservationUrl(): ?string
    {
        return $this->linkBuildingService->buildPublicReservationLink();
    }

    /**
     * Get the public reservation URL with valid token (public endpoint).
     */
    public function publicUrl(): JsonResponse
    {
        return response()->json(['public_url' => $this->linkBuildingService->buildPublicReservationLink()]);
    }
}
