<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRssFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_rss_feed_requires_api_key(): void
    {
        $response = $this->get('/api/admin/rss');

        $response->assertForbidden();
    }

    public function test_combined_feed_returns_reservations_and_waitlist_entries(): void
    {
        Setting::updateOrCreate(['name' => 'rss_feed_enabled'], ['value' => '1']);

        $user = User::factory()->create([
            'role' => 'admin',
            'active' => true,
            'api_token' => 'rss-token',
            'api_token_is_hashed' => false,
        ]);

        Reservation::create([
            'display_name' => 'Alice Example',
            'email' => 'alice@example.com',
            'date_added' => now()->subMinutes(10),
        ]);

        WaitlistEntry::create([
            'display_name' => 'Bob Waiter',
            'email' => 'bob@example.com',
            'status' => 'pending',
            'date_added' => now()->subMinutes(5),
            'undo_token' => 'waitlist-undo-token',
        ]);

        $response = $this->get('/api/admin/rss?api_key='.$user->api_token.'&limit=10');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

        $xml = $response->getContent();

        $this->assertIsString($xml);
        $this->assertStringContainsString('<rss', $xml);
        $this->assertStringContainsString('<title>Reservations and Waitlist Feed</title>', $xml);
        $this->assertStringContainsString('Reservation: Alice Example', $xml);
        $this->assertStringContainsString('Waitlist: Bob Waiter', $xml);
    }

    public function test_feed_variants_return_expected_entries(): void
    {
        Setting::updateOrCreate(['name' => 'rss_feed_enabled'], ['value' => '1']);

        $user = User::factory()->create([
            'role' => 'superadmin',
            'active' => true,
            'api_token' => 'rss-token-2',
            'api_token_is_hashed' => false,
        ]);

        Reservation::create([
            'display_name' => 'Reservation Only',
            'email' => 'reservation@example.com',
            'date_added' => now()->subMinutes(20),
        ]);

        WaitlistEntry::create([
            'display_name' => 'Waitlist Only',
            'email' => 'waitlist@example.com',
            'status' => 'pending',
            'date_added' => now()->subMinutes(15),
            'undo_token' => 'waitlist-undo-token-2',
        ]);

        $reservationsFeed = $this->get('/api/admin/rss/reservations?api_key='.$user->api_token);
        $reservationsFeed->assertOk();
        $reservationsXml = (string) $reservationsFeed->getContent();
        $this->assertStringContainsString('Reservation: Reservation Only', $reservationsXml);
        $this->assertStringNotContainsString('Waitlist: Waitlist Only', $reservationsXml);

        $waitlistFeed = $this->get('/api/admin/rss/waitlist?api_key='.$user->api_token);
        $waitlistFeed->assertOk();
        $waitlistXml = (string) $waitlistFeed->getContent();
        $this->assertStringContainsString('Waitlist: Waitlist Only', $waitlistXml);
        $this->assertStringNotContainsString('Reservation: Reservation Only', $waitlistXml);
    }

    public function test_guest_feed_is_scoped_by_site_token(): void
    {
        Setting::updateOrCreate(['name' => 'rss_feed_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['name' => 'reservation_token_enabled'], ['value' => '1']);

        User::factory()->create([
            'role' => 'guest',
            'active' => true,
            'api_token' => 'guest-token-a',
            'api_token_is_hashed' => false,
        ]);

        User::factory()->create([
            'role' => 'guest',
            'active' => true,
            'api_token' => 'guest-token-b',
            'api_token_is_hashed' => false,
        ]);

        Reservation::create([
            'display_name' => 'Guest A Reservation',
            'email' => 'ga@example.com',
            'site_token' => 'guest-token-a',
            'date_added' => now()->subMinutes(10),
        ]);

        Reservation::create([
            'display_name' => 'Guest B Reservation',
            'email' => 'gb@example.com',
            'site_token' => 'guest-token-b',
            'date_added' => now()->subMinutes(9),
        ]);

        WaitlistEntry::create([
            'display_name' => 'Guest A Waitlist',
            'email' => 'gaw@example.com',
            'status' => 'pending',
            'site_token' => 'guest-token-a',
            'undo_token' => 'undo-a',
            'date_added' => now()->subMinutes(8),
        ]);

        WaitlistEntry::create([
            'display_name' => 'Guest B Waitlist',
            'email' => 'gbw@example.com',
            'status' => 'pending',
            'site_token' => 'guest-token-b',
            'undo_token' => 'undo-b',
            'date_added' => now()->subMinutes(7),
        ]);

        $response = $this->get('/api/rss?t=guest-token-a');

        $response->assertOk();

        $xml = (string) $response->getContent();
        $this->assertStringContainsString('Guest A Reservation', $xml);
        $this->assertStringContainsString('Guest A Waitlist', $xml);
        $this->assertStringNotContainsString('Guest B Reservation', $xml);
        $this->assertStringNotContainsString('Guest B Waitlist', $xml);
    }

    public function test_rss_can_be_disabled_globally_via_setting(): void
    {
        Setting::updateOrCreate(['name' => 'rss_feed_enabled'], ['value' => '0']);

        $admin = User::factory()->create([
            'role' => 'admin',
            'active' => true,
            'api_token' => 'rss-admin-disabled',
            'api_token_is_hashed' => false,
        ]);

        $guest = User::factory()->create([
            'role' => 'guest',
            'active' => true,
            'api_token' => 'rss-guest-disabled',
            'api_token_is_hashed' => false,
        ]);

        $adminResponse = $this->get('/api/admin/rss?api_key='.$admin->api_token);
        $adminResponse->assertForbidden();
        $adminResponse->assertJsonPath('message', __('rss_feed_disabled'));

        Setting::updateOrCreate(['name' => 'reservation_token_enabled'], ['value' => '1']);
        $guestResponse = $this->get('/api/rss?t='.$guest->api_token);
        $guestResponse->assertForbidden();
        $guestResponse->assertJsonPath('message', __('rss_feed_disabled'));
    }
}
