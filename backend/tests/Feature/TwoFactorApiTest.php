<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance('pragmarx.google2fa', new class
        {
            public function generateSecretKey(): string
            {
                return 'TEST-TWO-FACTOR-SECRET';
            }
        });
    }

    public function test_admin_can_enable_two_factor_for_user(): void
    {
        $admin = $this->createApiUser('admin');
        $target = $this->createApiUser('user');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/users/'.$target->id.'/2fa/enable');

        $response->assertOk()
            ->assertJsonPath('message', __('two_factor_enabled_for_user'));
        $this->assertNotNull($target->refresh()->two_factor_secret);
        $this->assertNotNull($target->two_factor_confirmed_at);
        $this->assertNotNull($target->two_factor_recovery_codes);
    }

    public function test_admin_can_disable_two_factor_for_user(): void
    {
        $admin = $this->createApiUser('admin');
        $target = $this->createApiUser('user', [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'])),
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/users/'.$target->id.'/2fa/disable');

        $response->assertOk()
            ->assertJsonPath('message', __('two_factor_disabled_for_user'));
        $target->refresh();
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_confirmed_at);
        $this->assertNull($target->two_factor_recovery_codes);
    }

    public function test_admin_can_reset_two_factor_for_user(): void
    {
        $admin = $this->createApiUser('admin');
        $target = $this->createApiUser('user');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/users/'.$target->id.'/2fa/reset');

        $response->assertOk()
            ->assertJsonPath('message', __('two_factor_reset_for_user'));
        $this->assertNotNull($target->refresh()->two_factor_secret);
        $this->assertNotNull($target->two_factor_recovery_codes);
    }

    public function test_moderator_cannot_manage_user_two_factor(): void
    {
        $moderator = $this->createApiUser('moderator');
        $target = $this->createApiUser('user');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->postJson('/api/admin/users/'.$target->id.'/2fa/enable');

        $response->assertForbidden();
    }
}
