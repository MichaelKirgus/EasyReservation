<?php

namespace Tests\Feature;

use App\Jobs\RunDatabaseBackupJob;
use App\Jobs\RunDatabaseRestoreJob;
use App\Models\DataPortabilityTransportProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DataPortabilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_discover_tables_and_list_operations(): void
    {
        $admin = $this->createApiUser('admin');

        $tables = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/data-portability/tables');

        $tables->assertOk()
            ->assertJsonStructure(['tables']);
        $this->assertTrue(collect($tables->json('tables'))->contains(
            fn (string $table): bool => str_ends_with($table, '.users') || $table === 'users'
        ));

        $operations = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/data-portability/operations');

        $operations->assertOk()->assertJsonCount(0);
    }

    public function test_backup_request_is_queued_for_valid_tables(): void
    {
        Queue::fake();
        $admin = $this->createApiUser('admin');
        $table = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/data-portability/tables')
            ->json('tables')[0];

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/data-portability/backup', [
            'selected_tables' => [$table],
            ]);

        $response->assertAccepted()
            ->assertJsonStructure(['message', 'operation_id']);
        Queue::assertPushed(RunDatabaseBackupJob::class);
    }

    public function test_backup_rejects_unknown_tables(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/data-portability/backup', [
                'selected_tables' => ['definitely_missing_table'],
            ]);

        $response->assertUnprocessable();
    }

    public function test_transport_preflight_reports_missing_tables(): void
    {
        $admin = $this->createApiUser('admin');
        $table = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/data-portability/tables')
            ->json('tables')[0];

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/data-portability/preflight-transport', [
                'selected_tables' => [$table, 'definitely_missing_table'],
            ]);

        $response->assertOk()
            ->assertJsonPath('accepted_tables.0', $table)
            ->assertJsonPath('missing_tables.0', 'definitely_missing_table')
            ->assertJsonPath('can_transport', false);
    }

    public function test_transport_profile_hides_encrypted_api_token(): void
    {
        $admin = $this->createApiUser('admin');

        $create = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/data-portability/transport-profiles', [
                'name' => 'Staging Target',
                'target_base_url' => 'https://staging.example.com',
                'target_api_token' => 'secret-transport-token',
                'is_active' => true,
            ]);

        $create->assertCreated()
            ->assertJsonPath('profile_id', fn ($id) => is_int($id));

        $list = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/data-portability/transport-profiles');

        $list->assertOk()
            ->assertJsonPath('profiles.0.name', 'Staging Target')
            ->assertJsonMissing(['target_api_token' => 'secret-transport-token']);

        $profile = DataPortabilityTransportProfile::firstOrFail();
        $this->assertSame('secret-transport-token', $profile->target_api_token);
    }

    public function test_moderator_cannot_access_data_portability(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/admin/data-portability/tables');

        $response->assertForbidden();
    }
}
