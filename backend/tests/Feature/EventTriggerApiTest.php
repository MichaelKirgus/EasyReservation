<?php

namespace Tests\Feature;

use App\Models\ActionList;
use App\Models\EventTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTriggerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_list_event_triggers(): void
    {
        $admin = $this->createApiUser('admin');
        $actionList = ActionList::create([
            'name' => 'Reservation Actions',
            'description' => 'Test actions',
            'active' => true,
        ]);

        $createResponse = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/event-triggers', [
                'event_type' => 'reservation_created',
                'action_list_id' => $actionList->id,
                'active' => true,
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('event_type', 'reservation_created')
            ->assertJsonPath('action_list_id', $actionList->id);

        $listResponse = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/event-triggers');

        $listResponse->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.action_list_name', 'Reservation Actions');
    }

    public function test_trigger_requires_an_existing_action_list(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/event-triggers', [
                'event_type' => 'reservation_created',
                'action_list_id' => 999999,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['action_list_id']);
    }

    public function test_admin_can_toggle_trigger_active_state(): void
    {
        $admin = $this->createApiUser('admin');
        $actionList = ActionList::create(['name' => 'Toggle Actions', 'active' => true]);
        $trigger = EventTrigger::create([
            'event_type' => 'reservation_created',
            'action_list_id' => $actionList->id,
            'active' => true,
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->patchJson('/api/admin/event-triggers/'.$trigger->id.'/toggle-active');

        $response->assertOk()
            ->assertJson(['success' => true, 'active' => false]);
        $this->assertFalse($trigger->refresh()->active);
    }

    public function test_admin_can_clone_trigger_with_new_event_type(): void
    {
        $admin = $this->createApiUser('admin');
        $actionList = ActionList::create(['name' => 'Clone Actions', 'active' => true]);
        $trigger = EventTrigger::create([
            'event_type' => 'reservation_created',
            'action_list_id' => $actionList->id,
            'active' => true,
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/event-triggers/'.$trigger->id.'/clone', [
                'event_type' => 'waitlist_entry_added',
            ]);

        $response->assertCreated()
            ->assertJsonPath('event_type', 'waitlist_entry_added')
            ->assertJsonPath('action_list_id', $actionList->id);
        $this->assertDatabaseCount('event_triggers', 2);
    }

    public function test_moderator_cannot_manage_admin_event_triggers(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/admin/event-triggers');

        $response->assertForbidden();
    }
}
