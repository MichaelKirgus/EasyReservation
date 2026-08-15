<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_is_public_and_reports_service_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'service',
                'timestamp',
                'checks' => ['database', 'cache', 'storage'],
            ])
            ->assertJsonPath('service', 'backend');
    }

    public function test_admin_endpoint_accepts_api_key_header(): void
    {
        $user = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/admin/settings-keys');

        $response->assertOk();
    }

    public function test_admin_endpoint_accepts_api_session_cookie(): void
    {
        $user = $this->createApiUser('admin');

        $response = $this->withCredentials()
            ->withUnencryptedCookie('api_session', $user->api_token)
            ->getJson('/api/admin/settings-keys');

        $response->assertOk();
    }

    public function test_admin_endpoint_rejects_missing_api_key(): void
    {
        $response = $this->getJson('/api/admin/settings-keys');

        $response->assertForbidden()
            ->assertJsonPath('message', __('middleware_missing_api_key'));
    }

    public function test_admin_endpoint_rejects_inactive_admin(): void
    {
        $user = $this->createApiUser('admin', ['active' => false]);

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/admin/settings-keys');

        $response->assertForbidden()
            ->assertJsonPath('message', __('middleware_unauthorized_role', ['roles' => 'admin, superadmin']));
    }

    public function test_admin_endpoint_rejects_moderator_role(): void
    {
        $user = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/admin/settings-keys');

        $response->assertForbidden()
            ->assertJsonPath('message', __('middleware_unauthorized_role', ['roles' => 'admin, superadmin']));
    }

    public function test_moderator_can_access_moderation_dashboard(): void
    {
        $user = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/moderator/moderation-dashboard/stats');

        $response->assertOk();
    }

    public function test_moderator_can_access_admin_namespace_moderation_dashboard(): void
    {
        $user = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/admin/moderation-dashboard/stats');

        $response->assertOk();
    }
}