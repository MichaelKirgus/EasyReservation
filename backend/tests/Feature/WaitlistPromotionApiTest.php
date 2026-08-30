<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WaitlistPromotionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->setSetting('reservation_enabled', 1);
        $this->setSetting('waitlist_enabled', 1);
        $this->setSetting('waitlist_limit', 0);
        $this->setSetting('reservation_max', 2);
    }

    public function test_full_capacity_reservation_is_redirected_to_waitlist(): void
    {
        // Fill the reservation capacity.
        Reservation::factory()->count(2)->create();

        $response = $this->postJson('/api/reservations', [
            'name' => 'Overflow Guest',
            'email' => 'overflow@example.com',
            'site_token' => $this->validSiteToken(),
        ]);

        // The reservation is full, so the request lands on the waitlist.
        $response->assertCreated()
            ->assertJsonPath('waitlist', true)
            ->assertJsonPath('entry.display_name', 'Overflow Guest')
            ->assertJsonPath('entry.status', 'pending');

        $this->assertSame(2, Reservation::query()->count());
        $this->assertDatabaseHas('waitlist_entries', [
            'display_name' => 'Overflow Guest',
            'status' => 'pending',
        ]);
    }

    public function test_full_capacity_without_waitlist_returns_409(): void
    {
        $this->setSetting('waitlist_enabled', 0);
        Reservation::factory()->count(2)->create();

        $response = $this->postJson('/api/reservations', [
            'name' => 'Overflow Guest',
            'email' => 'overflow@example.com',
            'site_token' => $this->validSiteToken(),
        ]);

        $response->assertStatus(409);
        $this->assertSame(2, Reservation::query()->count());
    }

    public function test_cancelling_reservation_promotes_oldest_waitlist_entry(): void
    {
        $admin = $this->createApiUser('admin');
        $this->setSetting('waitlist_auto_promote_enabled', 1);

        // One reservation occupies a slot; two waitlist entries are queued.
        $reservation = Reservation::factory()->create();
        WaitlistEntry::factory()->create([
            'display_name' => 'Oldest Guest',
            'email' => 'oldest@example.com',
            'date_added' => now()->subHour(),
        ]);
        WaitlistEntry::factory()->create([
            'display_name' => 'Newer Guest',
            'email' => 'newer@example.com',
            'date_added' => now(),
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->deleteJson('/api/admin/reservations/'.$reservation->id);

        $response->assertOk();

        // The oldest pending entry is promoted into the freed slot.
        $oldest = WaitlistEntry::query()->where('display_name', 'Oldest Guest')->first();
        $this->assertSame('promoted', $oldest->status);
        $this->assertNotNull($oldest->reservation_id);

        $newer = WaitlistEntry::query()->where('display_name', 'Newer Guest')->first();
        $this->assertSame('pending', $newer->status, 'Only the oldest entry should be promoted.');

        $promotedReservation = Reservation::find($oldest->reservation_id);
        $this->assertNotNull($promotedReservation);
        $this->assertTrue((bool) $promotedReservation->from_waitlist);
    }

    public function test_no_promotion_when_auto_promote_disabled(): void
    {
        $admin = $this->createApiUser('admin');
        // waitlist_auto_promote_enabled defaults to 0.

        $reservation = Reservation::factory()->create();
        WaitlistEntry::factory()->create(['display_name' => 'Queued Guest']);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->deleteJson('/api/admin/reservations/'.$reservation->id);

        $response->assertOk();

        $entry = WaitlistEntry::query()->where('display_name', 'Queued Guest')->first();
        $this->assertSame('pending', $entry->status);
    }

    public function test_manual_promote_endpoint_creates_reservation(): void
    {
        $admin = $this->createApiUser('admin');
        WaitlistEntry::factory()->create([
            'display_name' => 'Manual Guest',
            'email' => 'manual@example.com',
        ]);

        // One slot is free (max 2, zero reservations).
        $entry = WaitlistEntry::query()->where('display_name', 'Manual Guest')->first();

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/waitlist/'.$entry->id.'/promote');

        $response->assertOk()
            ->assertJsonStructure(['message', 'reservation']);

        $entry->refresh();
        $this->assertSame('promoted', $entry->status);
        $this->assertTrue((bool) Reservation::find($entry->reservation_id)->from_waitlist);
    }

    public function test_manual_promote_fails_when_no_free_slots(): void
    {
        $admin = $this->createApiUser('admin');
        Reservation::factory()->count(2)->create(); // capacity full
        WaitlistEntry::factory()->create(['display_name' => 'Blocked Guest']);

        $entry = WaitlistEntry::query()->where('display_name', 'Blocked Guest')->first();

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/waitlist/'.$entry->id.'/promote');

        $response->assertStatus(409);
        $this->assertSame('pending', $entry->refresh()->status);
    }

    public function test_waitlist_limit_is_enforced(): void
    {
        $this->setSetting('waitlist_limit', 1);
        WaitlistEntry::factory()->create(['display_name' => 'First In Line']);

        $response = $this->postJson('/api/waitlist', [
            'name' => 'Second Guest',
            'email' => 'second@example.com',
            'site_token' => $this->validSiteToken(),
        ]);

        // Waitlist is full → 409.
        $response->assertStatus(409);
        $this->assertSame(1, WaitlistEntry::query()->where('status', 'pending')->count());
    }

    public function test_update_waitlist_entry_changes_fields(): void
    {
        $admin = $this->createApiUser('admin');
        WaitlistEntry::factory()->create([
            'display_name' => 'Original Name',
            'email' => 'original@example.com',
        ]);

        $entry = WaitlistEntry::query()->where('display_name', 'Original Name')->first();

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->patchJson('/api/admin/waitlist/'.$entry->id, [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('display_name', 'Updated Name');

        $this->assertSame('Updated Name', $entry->refresh()->display_name);
    }

    public function test_update_processed_entry_is_rejected(): void
    {
        $admin = $this->createApiUser('admin');
        WaitlistEntry::factory()->promoted()->create(['display_name' => 'Already Promoted']);

        $entry = WaitlistEntry::query()->where('display_name', 'Already Promoted')->first();

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->patchJson('/api/admin/waitlist/'.$entry->id, ['name' => 'New Name']);

        $response->assertStatus(409);
    }

    public function test_export_returns_csv_with_entries(): void
    {
        WaitlistEntry::factory()->create([
            'display_name' => 'Export Alice',
            'email' => 'alice@example.com',
        ]);

        $response = $this->withHeaders($this->apiHeaders($this->createApiUser('admin')))
            ->get('/api/admin/waitlist/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = (string) $response->streamedContent();
        $this->assertStringContainsString('Export Alice', $content);
    }

    public function test_undo_by_token_cancels_and_deletes_entry(): void
    {
        $this->setSetting('waitlist_undo_enabled', 1);
        WaitlistEntry::factory()->create([
            'display_name' => 'Undo Guest',
            'email' => 'undo@example.com',
            'undo_token' => 'test-undo-token-123',
        ]);

        $response = $this->getJson('/api/waitlist/undo-token/test-undo-token-123');

        $response->assertOk();
        $this->assertDatabaseMissing('waitlist_entries', ['display_name' => 'Undo Guest']);
    }

    public function test_undo_by_token_is_rejected_when_feature_disabled(): void
    {
        // waitlist_undo_enabled defaults to 0.
        WaitlistEntry::factory()->create([
            'display_name' => 'Locked Guest',
            'undo_token' => 'test-undo-token-456',
        ]);

        $response = $this->getJson('/api/waitlist/undo-token/test-undo-token-456');

        $response->assertStatus(403);
        $this->assertDatabaseHas('waitlist_entries', ['display_name' => 'Locked Guest']);
    }

    public function test_undo_by_unknown_token_returns_404(): void
    {
        $this->setSetting('waitlist_undo_enabled', 1);

        $response = $this->getJson('/api/waitlist/undo-token/does-not-exist');

        $response->assertStatus(404);
    }
}
