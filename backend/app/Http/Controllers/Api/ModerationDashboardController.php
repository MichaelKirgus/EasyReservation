<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionList;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Services\LinkBuildingService;
use App\Services\RateLimitCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ModerationDashboardController extends Controller
{
    public function __construct(
        private LinkBuildingService $linkBuildingService,
        private RateLimitCacheService $rateLimitCache
    ) {}

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

        // Moderation insights
        $waitlistConversion = $this->getWaitlistConversionStats();
        $pendingValidationAges = $this->getPendingValidationAgeBuckets();
        $reservationFunnel = $this->getReservationFunnelStats();

        // Get the public URL with valid token using LinkBuildingService
        $publicUrl = $this->linkBuildingService->buildPublicReservationLink();

        return response()->json([
            'reservation_count' => $reservationCount,
            'waitlist_count' => $waitlistCount,
            'mail_validation_pending' => $mailValidationPending,
            'rate_limit_entries' => $rateLimitEntries,
            'daily_trends' => $dailyTrends,
            'waitlist_conversion' => $waitlistConversion,
            'pending_validation_ages' => $pendingValidationAges,
            'reservation_funnel' => $reservationFunnel,
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
     * Waitlist conversion summary.
     */
    private function getWaitlistConversionStats(): array
    {
        try {
            $total = WaitlistEntry::count();
            $promoted = WaitlistEntry::query()
                ->whereNotNull('promoted_at')
                ->orWhereNotNull('reservation_id')
                ->orWhere('status', 'promoted')
                ->count();

            $rate = $total > 0 ? round(($promoted / $total) * 100, 1) : 0.0;

            return [
                'total' => $total,
                'promoted' => $promoted,
                'rate' => $rate,
            ];
        } catch (\Throwable $e) {
            return [
                'total' => 0,
                'promoted' => 0,
                'rate' => 0.0,
            ];
        }
    }

    /**
     * Pending validation buckets by age in hours.
     */
    private function getPendingValidationAgeBuckets(): array
    {
        try {
            $underOneHour = DB::table('email_validations')
                ->where('status', 'pending')
                ->where('created_at', '>=', now()->subHour())
                ->count();

            $betweenOneAndTwentyFour = DB::table('email_validations')
                ->where('status', 'pending')
                ->where('created_at', '<', now()->subHour())
                ->where('created_at', '>=', now()->subDay())
                ->count();

            $overTwentyFour = DB::table('email_validations')
                ->where('status', 'pending')
                ->where('created_at', '<', now()->subDay())
                ->count();

            return [
                'under_1h' => $underOneHour,
                'between_1h_24h' => $betweenOneAndTwentyFour,
                'over_24h' => $overTwentyFour,
            ];
        } catch (\Throwable $e) {
            return [
                'under_1h' => 0,
                'between_1h_24h' => 0,
                'over_24h' => 0,
            ];
        }
    }

    /**
     * Reservation funnel counters for moderation context.
     */
    private function getReservationFunnelStats(): array
    {
        try {
            $attempts = DB::table('email_validations')
                ->where('type', 'reservation')
                ->count();

            if ($attempts === 0) {
                $attempts = Reservation::count() + WaitlistEntry::count();
            }

            $completed = Reservation::count();

            $movedToWaitlist = WaitlistEntry::count();

            $pendingVerification = DB::table('email_validations')
                ->where('type', 'reservation')
                ->where('status', 'pending')
                ->count();

            return [
                'attempts' => $attempts,
                'pending_verification' => $pendingVerification,
                'completed' => $completed,
                'moved_to_waitlist' => $movedToWaitlist,
            ];
        } catch (\Throwable $e) {
            return [
                'attempts' => 0,
                'pending_verification' => 0,
                'completed' => 0,
                'moved_to_waitlist' => 0,
            ];
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
