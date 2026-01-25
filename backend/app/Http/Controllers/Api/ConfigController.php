<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Services\SettingsService;
use App\Services\EventService;
use App\Services\PlaceholderService;
use Illuminate\Http\JsonResponse;



class ConfigController extends Controller
{

    public function __construct(
        private readonly SettingsService $settings,
        private readonly EventService $events,
        private readonly PlaceholderService $placeholders,
    ) {
    }

    public function show(): JsonResponse
    {
        $settings = $this->settings->all();

        $user = auth()->user();
        $whitelistFile = null;
        if (!$user) {
            $whitelistFile = resource_path('settings-whitelists/public.json');
        } elseif (in_array($user->role, ['user', 'moderator'])) {
            $whitelistFile = resource_path('settings-whitelists/user.json');
        } // Admin/Superadmin: All settings.

        if ($whitelistFile && file_exists($whitelistFile)) {
            $keys = json_decode(file_get_contents($whitelistFile), true);
            $settings = array_intersect_key($settings, array_flip($keys));
        }

        $formFields = FormField::query()
            ->where('active', true)
            ->where('visible_public', true)
            ->orderBy('order')
            ->get();

        $attendees = [];
        if ((int) ($settings['reservation_show_attendees_enabled'] ?? 0) === 1) {
            $attendees = Reservation::query()
                ->orderBy('date_added')
                ->get(['display_name', 'date_added'])
                ->map(fn ($r) => [
                    'display_name' => $r->display_name,
                    'date_added' => $r->date_added,
                ])->all();
        }

        $max = (int) ($settings['reservation_max'] ?? 0);
        $current = Reservation::query()->count();
        $waitlistEnabled = (int) ($settings['waitlist_enabled'] ?? 0) === 1;

        $waitlistPending = $waitlistEnabled
            ? WaitlistEntry::query()->where('status', 'pending')->count()
            : 0;

        // Events: only from DB
        $upcomingEvents = $this->events->upcoming()->map(fn ($e) => $this->events->format($e))->all();
        $nextEvent = $this->events->next();
        $nextEventText = $nextEvent ? $this->events->format($nextEvent) : null;

        $settings['reservation_next_event'] = $nextEventText;
        $settings['reservation_upcoming_events_array'] = $upcomingEvents;
        $settings['reservation_upcoming_events_list'] = empty($upcomingEvents)
            ? ''
            : implode("\n", array_map(fn ($v) => '• '.$v, $upcomingEvents));

        // Replace placeholders in public-facing text fields
        $placeholderFields = [
            'reservation_additional_info',
            'reservation_details',
            'reservation_additional_info_link_text',
            'reservation_details_info_link_text',
            'waitlist_full_text',
            'waitlist_join_button_text',
            'waitlist_success_text',
            'waitlist_disabled_text',
            'reservation_page_title',
        ];
        foreach ($placeholderFields as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = $this->placeholders->replaceString($settings[$key]);
            }
        }

        $waitlistEntries = [];
        $showWaitlistPublic = (int) ($settings['waitlist_show_public'] ?? 0) === 1;
        if ($waitlistEnabled && $showWaitlistPublic) {
            $waitlistEntries = WaitlistEntry::query()
                ->where('status', 'pending')
                ->orderBy('date_added')
                ->get(['display_name', 'date_added'])
                ->map(fn ($w) => [
                    'display_name' => $w->display_name,
                    'date_added' => $w->date_added,
                ])->all();
        }

        // Für Public: date_added entfernen
        if (!$user) {
            $attendees = array_map(fn($a) => ['display_name' => $a['display_name']], $attendees);
            $waitlistEntries = array_map(fn($w) => ['display_name' => $w['display_name']], $waitlistEntries);
        }

        return response()->json([
            'settings' => $settings,
            'form_fields' => $formFields,
            'attendees' => $attendees,
            'waitlist_entries' => $waitlistEntries,
            'stats' => [
                'count' => $current,
                'max' => $max,
                'waitlist_pending' => $waitlistPending,
                'waitlist_limit' => (int) ($settings['waitlist_limit'] ?? 0),
                'waitlist_enabled' => $waitlistEnabled,
            ],
            // explizit als Top-Level-Property für das Frontend
            'privacy_policy_enabled' => (int)($settings['privacy_policy_enabled'] ?? 0) === 1,
            'faq_enabled' => (int)($settings['faq_enabled'] ?? 0) === 1,
            'clear_localstorage_on_logout' => (int)($settings['clear_localstorage_on_logout'] ?? 0) === 1,
        ]);
    }
}
