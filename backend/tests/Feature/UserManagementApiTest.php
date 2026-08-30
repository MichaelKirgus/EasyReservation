<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users(): void
    {
        $admin = $this->createApiUser('admin');
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/users');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $moderator->id, 'role' => 'moderator']);
    }

    public function test_moderator_cannot_manage_users(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/admin/users');

        $response->assertForbidden();
    }

    public function test_admin_can_create_user_with_plain_api_token(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/users', [
                'name' => 'New Moderator',
                'email' => 'new-moderator@example.com',
                'role' => 'moderator',
                'password' => 'secure-password',
                'api_token' => 'moderator-test-token-123',
            ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'new-moderator@example.com')
            ->assertJsonPath('user.role', 'moderator')
            ->assertJsonPath('api_token', 'moderator-test-token-123');

        $this->assertDatabaseHas('users', [
            'email' => 'new-moderator@example.com',
            'role' => 'moderator',
            'api_token' => 'moderator-test-token-123',
        ]);
    }

    public function test_admin_can_rotate_a_hashed_api_token(): void
    {
        $admin = $this->createApiUser('admin');
        $target = $this->createApiUser('user');
        $oldToken = $target->api_token;

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/users/'.$target->id.'/rotate-token', [
                'hash_token' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('user.api_token_is_hashed', true);

        $target->refresh();
        $newToken = $response->json('api_token');
        $this->assertNotSame($oldToken, $newToken);
        $this->assertTrue(Hash::check($newToken, $target->api_token));
    }

    public function test_admin_cannot_delete_the_last_active_admin(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->deleteJson('/api/admin/users/'.$admin->id);

        $response->assertUnprocessable()
            ->assertJsonPath('message', __('admin_operation_requires_at_least_one_admin'));
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
