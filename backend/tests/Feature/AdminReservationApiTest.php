<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReservationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSetting('admin_reservation_notify_default', 0);
        $this->setSetting('reservation_undo_enabled', 1);
    }

    public function test_admin_can_list_and_update_reservations(): void
    {
        $admin = $this->createApiUser('admin');
        $reservation = Reservation::create([
            'display_name' => 'Original Name',
            'email' => 'original@example.com',
            'date_added' => now(),
        ]);

        $list = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/reservations');
        $list->assertOk()->assertJsonFragment(['display_name' => 'Original Name']);

        $update = $this->withHeaders($this->apiHeaders($admin))
            ->patchJson('/api/admin/reservations/'.$reservation->id, [
                'display_name' => 'Updated Name',
                'email' => 'updated@example.com',
            ]);

        $update->assertOk()
            ->assertJsonPath('display_name', 'Updated Name');
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'display_name' => 'Updated Name',
        ]);
    }

    public function test_admin_can_delete_reservation_and_export_csv(): void
    {
        $admin = $this->createApiUser('admin');
        $reservation = Reservation::create([
            'display_name' => 'Export Alice',
            'email' => 'export@example.com',
            'date_added' => now(),
        ]);

        $export = $this->withHeaders($this->apiHeaders($admin))
            ->get('/api/admin/export');
        $export->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $delete = $this->withHeaders($this->apiHeaders($admin))
            ->deleteJson('/api/admin/reservations/'.$reservation->id);
        $delete->assertOk();
        $this->assertDatabaseMissing('reservations', ['id' => $reservation->id]);
    }

    public function test_reservation_can_be_undone_by_token(): void
    {
        $reservation = Reservation::create([
            'display_name' => 'Undo Alice',
            'email' => 'undo@example.com',
            'undo_token' => 'undo-token-123',
            'date_added' => now(),
        ]);

        $response = $this->getJson('/api/reservations/undo-token/undo-token-123');

        $response->assertOk()
            ->assertJsonPath('message', __('reservation_removed'));
        $this->assertDatabaseMissing('reservations', ['id' => $reservation->id]);
    }

    public function test_reservation_undo_is_rejected_when_disabled(): void
    {
        $this->setSetting('reservation_undo_enabled', 0);

        $response = $this->getJson('/api/reservations/undo-token/disabled-token');

        $response->assertForbidden()
            ->assertJsonPath('message', __('undo_disabled'));
    }

    public function test_moderator_cannot_use_admin_reservation_endpoint(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/admin/reservations');

        $response->assertForbidden();
    }
}
