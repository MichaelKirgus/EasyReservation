<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_settings(): void
    {
        Setting::create(['name' => 'reservation_enabled', 'value' => '1']);

        $user = $this->createApiUser('admin');
        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/admin/settings');

        $response->assertOk()
            ->assertJsonPath('reservation_enabled', '1');
    }

    public function test_moderator_cannot_read_admin_settings(): void
    {
        $user = $this->createApiUser('moderator');
        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/admin/settings');

        $response->assertForbidden();
    }

    public function test_admin_update_normalizes_boolean_settings_and_refreshes_cache(): void
    {
        Setting::create(['name' => 'reservation_enabled', 'value' => '0']);
        $user = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->postJson('/api/admin/settings', [
                'settings' => ['reservation_enabled' => true],
            ]);

        $response->assertOk()
            ->assertJsonPath('settings.reservation_enabled', '1');
        $this->assertSame('1', Setting::find('reservation_enabled')->value);
    }

    public function test_settings_export_converts_boolean_strings(): void
    {
        Setting::create(['name' => 'reservation_enabled', 'value' => '1']);
        Setting::create(['name' => 'reservation_max', 'value' => '10']);
        $user = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/admin/settings/export');

        $response->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="settings-export.json"')
            ->assertJsonPath('reservation_enabled', true)
            ->assertJsonPath('reservation_max', '10');
    }

    public function test_settings_import_updates_known_keys_and_skips_unknown_keys(): void
    {
        Setting::create(['name' => 'reservation_enabled', 'value' => '0']);
        $user = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->postJson('/api/admin/settings/import', [
                'settings' => [
                    'reservation_enabled' => '1',
                    'unknown_setting' => '"ignored"',
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('settings.reservation_enabled', '1')
            ->assertJsonMissingPath('settings.unknown_setting');
        $this->assertSame('1', Setting::find('reservation_enabled')->value);
        $this->assertNull(Setting::find('unknown_setting'));
    }
}
