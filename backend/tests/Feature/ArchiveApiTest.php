<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\ArchiveReservation;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchiveApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_populate_archive_without_deleting_live_data(): void
    {
        $admin = $this->createApiUser('admin');
        $reservation = Reservation::create([
            'display_name' => 'Archived Alice',
            'email' => 'alice@example.com',
            'date_added' => now(),
        ]);
        $waitlist = WaitlistEntry::create([
            'display_name' => 'Waiting Bob',
            'email' => 'bob@example.com',
            'status' => 'pending',
            'date_added' => now(),
        ]);

        $create = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/archives', [
                'name' => 'August Archive',
                'description' => 'Archive test',
                'store_emails' => true,
            ]);

        $create->assertCreated()
            ->assertJsonPath('name', 'August Archive');
        $archiveId = $create->json('id');

        $populate = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/archives/'.$archiveId.'/archive-data');

        $populate->assertOk();
        $this->assertDatabaseHas('archive_reservations', [
            'archive_id' => $archiveId,
            'original_reservation_id' => $reservation->id,
        ]);
        $this->assertDatabaseHas('archive_waitlist_entries', [
            'archive_id' => $archiveId,
            'original_waitlist_entry_id' => $waitlist->id,
        ]);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id]);
        $this->assertDatabaseHas('waitlist_entries', ['id' => $waitlist->id]);
    }

    public function test_admin_can_filter_archived_reservations(): void
    {
        $admin = $this->createApiUser('admin');
        $archive = Archive::create(['name' => 'Filter Archive', 'store_emails' => true]);
        ArchiveReservation::create([
            'archive_id' => $archive->id,
            'original_reservation_id' => 1,
            'display_name' => 'Alice Archived',
            'email' => 'alice@example.com',
            'date_added' => now(),
        ]);
        ArchiveReservation::create([
            'archive_id' => $archive->id,
            'original_reservation_id' => 2,
            'display_name' => 'Bob Archived',
            'email' => 'bob@example.com',
            'date_added' => now(),
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/archives/'.$archive->id.'/reservations?name=Alice');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.display_name', 'Alice Archived');
    }

    public function test_admin_can_restore_archived_reservation(): void
    {
        $admin = $this->createApiUser('admin');
        $archive = Archive::create(['name' => 'Restore Archive', 'store_emails' => true]);
        $archived = ArchiveReservation::create([
            'archive_id' => $archive->id,
            'original_reservation_id' => 3,
            'display_name' => 'Restored Alice',
            'email' => 'alice@example.com',
            'date_added' => now(),
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/archives/'.$archive->id.'/restore-reservation/'.$archived->id);

        $response->assertOk()
            ->assertJsonPath('message', 'Reservation restored successfully')
            ->assertJsonPath('reservation.display_name', 'Restored Alice');
        $this->assertDatabaseMissing('archive_reservations', ['id' => $archived->id]);
        $this->assertDatabaseHas('reservations', ['display_name' => 'Restored Alice']);
    }

    public function test_only_superadmin_can_anonymize_archive_personal_data(): void
    {
        $admin = $this->createApiUser('admin');
        $archive = Archive::create(['name' => 'Privacy Archive', 'store_emails' => true]);
        $archived = ArchiveReservation::create([
            'archive_id' => $archive->id,
            'original_reservation_id' => 4,
            'display_name' => 'Private Alice',
            'email' => 'private@example.com',
            'date_added' => now(),
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/archives/'.$archive->id.'/anonymize', [
                'fields' => ['email', 'name'],
                'scope' => 'reservations',
            ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('archive_reservations', ['id' => $archived->id]);
    }
}
