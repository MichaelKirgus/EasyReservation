<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Representative authorization matrix across route groups and roles.
 *
 * Route groups (routes/api.php):
 *  - role:superadmin,admin,moderator,user   → /self-2fa/*
 *  - role:admin,superadmin                  → /admin/*
 *  - role:moderator,admin,superadmin        → /admin/moderation-dashboard/*
 *  - role:superadmin,admin,moderator        → /moderator/*
 *  - role:superadmin                        → /audit-logs*
 */
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Representative routes of the admin group (role:admin,superadmin).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function adminRouteProvider(): array
    {
        return [
            'reservations index' => ['GET', '/api/admin/reservations'],
            'users index' => ['GET', '/api/admin/users'],
            'waitlist index' => ['GET', '/api/admin/waitlist'],
            'email-validations index' => ['GET', '/api/admin/email-validations'],
            'surveys index' => ['GET', '/api/admin/surveys'],
            'events index' => ['GET', '/api/admin/events'],
            'locations index' => ['GET', '/api/admin/locations'],
            'archives index' => ['GET', '/api/admin/archives'],
            'webhook-templates index' => ['GET', '/api/admin/webhook-templates'],
            'settings index' => ['GET', '/api/admin/settings'],
            'scheduled-tasks index' => ['GET', '/api/admin/scheduled-tasks'],
            'action-lists index' => ['GET', '/api/admin/action-lists'],
        ];
    }

    /**
     * Representative routes of the moderator group (role:superadmin,admin,moderator).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function moderatorRouteProvider(): array
    {
        return [
            'reservations index' => ['GET', '/api/moderator/reservations'],
            'waitlist index' => ['GET', '/api/moderator/waitlist'],
            'email-validations index' => ['GET', '/api/moderator/email-validations'],
            'archives index' => ['GET', '/api/moderator/archives'],
            'surveys index' => ['GET', '/api/moderator/surveys'],
            'faqs index' => ['GET', '/api/moderator/faqs'],
            'events index' => ['GET', '/api/moderator/events'],
            'locations index' => ['GET', '/api/moderator/locations'],
            'custom-placeholders index' => ['GET', '/api/moderator/custom-placeholders'],
            'action-lists index' => ['GET', '/api/moderator/action-lists'],
            'mail-transport-options' => ['GET', '/api/moderator/mail-transport-options'],
        ];
    }

    /**
     * Dispatch a request with the given user's API key header.
     */
    protected function requestAs(\App\Models\User $user, string $method, string $uri): \Illuminate\Testing\TestResponse
    {
        return match (strtoupper($method)) {
            'GET' => $this->withHeaders($this->apiHeaders($user))->getJson($uri),
            'POST' => $this->withHeaders($this->apiHeaders($user))->postJson($uri, []),
            'PUT' => $this->withHeaders($this->apiHeaders($user))->putJson($uri, []),
            'PATCH' => $this->withHeaders($this->apiHeaders($user))->patchJson($uri, []),
            'DELETE' => $this->withHeaders($this->apiHeaders($user))->deleteJson($uri),
            default => throw new \InvalidArgumentException("Unsupported method {$method}"),
        };
    }

    public function test_unauthenticated_requests_receive_json_403_on_all_protected_groups(): void
    {
        $routes = array_merge(
            self::adminRouteProvider(),
            self::moderatorRouteProvider(),
            [
                'audit-logs index' => ['GET', '/api/audit-logs'],
                'self-2fa status' => ['GET', '/api/self-2fa/status'],
            ]
        );

        foreach ($routes as [$method, $uri]) {
            $response = match (strtoupper($method)) {
                'GET' => $this->getJson($uri),
                default => $this->postJson($uri, []),
            };

            $response->assertForbidden();
            $this->assertSame('application/json', $response->headers->get('Content-Type'), "Non-JSON response on {$uri}");
            $this->assertArrayHasKey('message', $response->json(), "Missing JSON message body on {$uri}");
        }
    }

    #[DataProvider('adminRouteProvider')]
    public function test_guest_and_user_roles_are_denied_on_admin_routes(string $method, string $uri): void
    {
        foreach (['guest', 'user'] as $role) {
            $user = $this->createApiUser($role);

            $response = $this->requestAs($user, $method, $uri);

            $response->assertForbidden();
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
        }
    }

    #[DataProvider('moderatorRouteProvider')]
    public function test_guest_and_user_roles_are_denied_on_moderator_routes(string $method, string $uri): void
    {
        foreach (['guest', 'user'] as $role) {
            $user = $this->createApiUser($role);

            $response = $this->requestAs($user, $method, $uri);

            $response->assertForbidden();
        }
    }

    public function test_admin_and_moderator_are_denied_on_superadmin_audit_log_routes(): void
    {
        foreach (['admin', 'moderator'] as $role) {
            $user = $this->createApiUser($role);

            $response = $this->withHeaders($this->apiHeaders($user))
                ->getJson('/api/audit-logs');

            $response->assertForbidden();
        }
    }

    public function test_superadmin_can_access_audit_log_routes(): void
    {
        $superadmin = $this->createApiUser('superadmin');

        $response = $this->withHeaders($this->apiHeaders($superadmin))
            ->getJson('/api/audit-logs');

        $response->assertOk();
    }

    #[DataProvider('moderatorRouteProvider')]
    public function test_moderator_is_allowed_on_moderation_routes(string $method, string $uri): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->requestAs($moderator, $method, $uri);

        $response->assertOk();
    }

    #[DataProvider('adminRouteProvider')]
    public function test_admin_is_allowed_on_admin_routes(string $method, string $uri): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->requestAs($admin, $method, $uri);

        $response->assertOk();
    }

    public function test_user_role_is_allowed_on_self_two_factor_status(): void
    {
        $user = $this->createApiUser('user');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/self-2fa/status');

        $response->assertOk();
    }

    public function test_unknown_api_key_is_rejected_with_json_403(): void
    {
        $response = $this->withHeaders(['X-Api-Key' => 'does-not-exist'])
            ->getJson('/api/admin/reservations');

        $response->assertForbidden();
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }
}
