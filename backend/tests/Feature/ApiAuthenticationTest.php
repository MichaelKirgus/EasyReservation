<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_issues_api_token_and_session_cookie(): void
    {
        $user = $this->createApiUser('admin', [
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'api_token',
                'user' => ['id', 'name', 'email', 'role'],
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertCookie('api_session');

        $this->assertNotSame($user->api_token, $response->json('api_token'));
    }

    public function test_login_rejects_invalid_credentials_without_issuing_a_token(): void
    {
        $user = $this->createApiUser('admin', [
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('message', __('auth_invalid_credentials'));

        $this->assertNull($response->json('api_token'));
    }

    public function test_logout_expires_the_api_session_cookie(): void
    {
        $user = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->postJson('/api/auth/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Logged out.'])
            ->assertCookieExpired('api_session');
    }

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