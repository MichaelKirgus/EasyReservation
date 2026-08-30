<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_location_and_event(): void
    {
        $admin = $this->createApiUser('admin');

        $locationResponse = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/locations', [
                'name' => 'Main Hall',
                'city' => 'Berlin',
                'active' => true,
            ]);

        $locationResponse->assertCreated()
            ->assertJsonPath('name', 'Main Hall');

        $eventResponse = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/events', [
                'title' => 'Summer Event',
                'start_at' => '2026-08-20T12:00',
                'end_at' => '2026-08-20T14:00',
                'location_id' => $locationResponse->json('id'),
                'active' => true,
            ]);

        $eventResponse->assertCreated()
            ->assertJsonPath('title', 'Summer Event')
            ->assertJsonPath('location_id', $locationResponse->json('id'));
    }

    public function test_event_times_are_stored_as_utc_from_configured_timezone(): void
    {
        $this->setSetting('event_timezone', 'Europe/Berlin');
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/events', [
                'title' => 'Timezone Event',
                'start_at' => '2026-08-20T12:00',
                'active' => true,
            ]);

        $response->assertCreated();
        $event = Event::query()->where('title', 'Timezone Event')->firstOrFail();
        $this->assertSame('2026-08-20 10:00:00', $event->start_at->format('Y-m-d H:i:s'));
    }

    public function test_event_rejects_unknown_location(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/events', [
                'title' => 'Invalid Location Event',
                'start_at' => '2026-08-20T12:00',
                'active' => true,
                'location_id' => 999999,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['location_id']);
    }

    public function test_public_upcoming_events_returns_future_active_events(): void
    {
        $this->setSetting('reservation_token_enabled', 0);
        $event = Event::create([
            'title' => 'Upcoming Event',
            'start_at' => Carbon::now()->addDay(),
            'active' => true,
        ]);

        $response = $this->getJson('/api/events/upcoming');

        $response->assertOk()
            ->assertJsonPath('upcoming.0.id', $event->id)
            ->assertJsonPath('upcoming.0.title', 'Upcoming Event');
    }

    public function test_moderator_cannot_manage_admin_events(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/admin/events');

        $response->assertForbidden();
    }
}
