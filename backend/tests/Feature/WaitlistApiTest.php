<?php

namespace Tests\Feature;

use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSetting('waitlist_enabled', 1);
        $this->setSetting('waitlist_limit', 0);
        $this->setSetting('reservation_token_enabled', 0);
    }

    public function test_public_user_can_join_waitlist(): void
    {
        $response = $this->postJson('/api/waitlist', [
            'name' => 'Waiting Person',
            'email' => 'waiting@example.com',
            'site_token' => $this->validSiteToken(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('display_name', 'Waiting Person')
            ->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('waitlist_entries', [
            'display_name' => 'Waiting Person',
            'status' => 'pending',
        ]);
    }

    public function test_duplicate_pending_waitlist_entry_is_rejected(): void
    {
        $token = $this->validSiteToken();
        $payload = [
            'name' => 'Waiting Person',
            'email' => 'waiting@example.com',
            'site_token' => $token,
        ];

        $this->postJson('/api/waitlist', $payload)->assertCreated();
        $response = $this->postJson('/api/waitlist', $payload);

        $response->assertConflict();
    }

    public function test_admin_can_list_waitlist_entries(): void
    {
        $admin = $this->createApiUser('admin');
        WaitlistEntry::create([
            'display_name' => 'Existing Entry',
            'email' => 'existing@example.com',
            'status' => 'pending',
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/waitlist');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['display_name' => 'Existing Entry']);
    }

    public function test_moderator_cannot_use_admin_waitlist_endpoint(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/admin/waitlist');

        $response->assertForbidden();
    }
}
