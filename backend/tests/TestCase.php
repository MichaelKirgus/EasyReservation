<?php

namespace Tests;

use App\Models\User;
use App\Models\Setting;
use App\Services\SiteTokenService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function apiHeaders(User $user): array
    {
        return ['X-Api-Key' => (string) $user->api_token];
    }

    protected function createApiUser(string $role = 'admin', array $attributes = []): User
    {
        return User::factory()->withRole($role)->create($attributes);
    }

    protected function validSiteToken(): string
    {
        $token = app(SiteTokenService::class)->getValidSiteToken();

        if ($token !== null) {
            return $token;
        }

        return (string) User::factory()->guest()->create()->api_token;
    }

    protected function getValidSiteToken(): string
    {
        return $this->validSiteToken();
    }

    protected function setSetting(string $name, mixed $value): void
    {
        Setting::updateOrCreate(['name' => $name], ['value' => (string) $value]);
    }
}
