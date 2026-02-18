<?php

namespace App\Services;

use App\Models\User;

class SiteTokenService
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {
    }

    public function getValidSiteToken(): ?string
    {
        // If an admin has selected a specific guest user, prefer that one
        $selectedUserId = $this->settings->get('site_guest_user_id');

        if ($selectedUserId) {
            $selectedUser = User::query()
                ->where('id', (int) $selectedUserId)
                ->where('role', 'guest')
                ->where('active', true)
                ->whereNotNull('api_token')
                ->first();

            if ($selectedUser) {
                return $selectedUser->api_token;
            }
        }

        // Fallback: pick the first active guest user with a token
        $guestUser = User::query()
            ->where('role', 'guest')
            ->where('active', true)
            ->whereNotNull('api_token')
            ->orderByDesc('id')
            ->first();

        return $guestUser ? $guestUser->api_token : null;
    }
}