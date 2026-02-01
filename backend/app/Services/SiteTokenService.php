<?php

namespace App\Services;

use App\Models\User;

class SiteTokenService
{
    public function getValidSiteToken(): ?string
    {
        // Get a valid site token from active guest users
        $guestUser = User::query()
            ->where('role', 'guest')
            ->where('active', true)
            ->whereNotNull('api_token')
            ->orderByDesc('id')
            ->first();

        return $guestUser ? $guestUser->api_token : null;
    }
}