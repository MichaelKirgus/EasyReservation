<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\User;
use App\Services\LinkBuildingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkBuildingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_rss_link_appends_api_segment_when_base_url_is_public_root(): void
    {
        Setting::updateOrCreate(
            ['name' => 'email_validation_base_url'],
            ['value' => 'https://example.com']
        );

        User::factory()->create([
            'role' => 'guest',
            'active' => true,
            'api_token' => 'guest-token-root',
            'api_token_is_hashed' => false,
        ]);

        $service = app(LinkBuildingService::class);

        $this->assertSame(
            'https://example.com/api/rss?t=guest-token-root',
            $service->buildPublicRssFeedLink()
        );
    }

    public function test_public_rss_link_does_not_duplicate_api_segment_when_base_url_already_ends_with_api(): void
    {
        Setting::updateOrCreate(
            ['name' => 'email_validation_base_url'],
            ['value' => 'https://example.com/api']
        );

        User::factory()->create([
            'role' => 'guest',
            'active' => true,
            'api_token' => 'guest-token-api',
            'api_token_is_hashed' => false,
        ]);

        $service = app(LinkBuildingService::class);

        $this->assertSame(
            'https://example.com/api/rss?t=guest-token-api',
            $service->buildPublicRssFeedLink()
        );
    }
}
