<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\TestCase;

class SelfTwoFactorApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deterministic TOTP provider: the only valid code is '123456'.
     */
    protected function fakeTotpProvider(): object
    {
        return new class implements TwoFactorAuthenticationProvider
        {
            public function generateSecretKey()
            {
                return 'TEST-TWO-FACTOR-SECRET';
            }

            public function qrCodeUrl($companyName, $companyEmail, $secret)
            {
                return "otpauth://totp/{$companyName}:{$companyEmail}?secret={$secret}";
            }

            public function verify($secret, $code): bool
            {
                return $secret === 'TEST-TWO-FACTOR-SECRET' && $code === '123456';
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(TwoFactorAuthenticationProvider::class, $this->fakeTotpProvider());
    }

    protected function userWithEnabledTwoFactor(User $user): User
    {
        $response = $this->withHeaders($this->apiHeaders($user))
            ->postJson('/api/self-2fa/enable');
        $response->assertOk();

        return $user->refresh();
    }

    public function test_status_reports_disabled_when_no_secret_set(): void
    {
        $user = $this->createApiUser('user');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/self-2fa/status');

        $response->assertOk()->assertExactJson(['two_factor_enabled' => false]);
    }

    public function test_status_reports_enabled_when_secret_set(): void
    {
        $user = $this->createApiUser('user', [
            'two_factor_secret' => encrypt('TEST-TWO-FACTOR-SECRET'),
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/self-2fa/status');

        $response->assertOk()->assertExactJson(['two_factor_enabled' => true]);
    }

    public function test_enable_generates_secret_and_recovery_codes(): void
    {
        $user = $this->createApiUser('user');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->postJson('/api/self-2fa/enable');

        $response->assertOk()->assertExactJson(['enabled' => true]);

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertSame('TEST-TWO-FACTOR-SECRET', decrypt($user->two_factor_secret));
        $this->assertNull($user->two_factor_confirmed_at, 'Enabling must not auto-confirm 2FA.');

        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        $this->assertCount(8, $codes);
    }

    public function test_enable_is_idempotent_and_does_not_reissue_recovery_codes(): void
    {
        $user = $this->createApiUser('user');
        $this->userWithEnabledTwoFactor($user);

        $firstCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);

        $response = $this->withHeaders($this->apiHeaders($user))
            ->postJson('/api/self-2fa/enable');
        $response->assertOk();

        $user->refresh();
        $secondCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);

        $this->assertSame($firstCodes, $secondCodes, 'Recovery codes must be issued only once.');
    }

    public function test_qr_returns_svg_and_decrypted_secret(): void
    {
        $user = $this->createApiUser('user');
        $this->userWithEnabledTwoFactor($user);

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/self-2fa/qr');

        $response->assertOk()
            ->assertJsonPath('secret', 'TEST-TWO-FACTOR-SECRET')
            ->assertJsonStructure(['svg', 'secret']);

        $this->assertStringContainsString('<svg', $response->json('svg'));
    }

    public function test_qr_without_secret_returns_null_secret(): void
    {
        $user = $this->createApiUser('user');

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/self-2fa/qr');

        $response->assertOk();
        $this->assertNull($response->json('secret'));
        $this->assertNull($response->json('svg'));
    }

    public function test_confirm_with_valid_code_confirms_two_factor(): void
    {
        $user = $this->createApiUser('user');
        $this->userWithEnabledTwoFactor($user);
        $this->assertNull($user->two_factor_confirmed_at);

        $response = $this->withHeaders($this->apiHeaders($user))
            ->postJson('/api/self-2fa/confirm', ['code' => '123456']);

        $response->assertOk()->assertExactJson(['confirmed' => true]);
        $this->assertNotNull($user->refresh()->two_factor_confirmed_at);
    }

    public function test_confirm_with_invalid_code_returns_422(): void
    {
        $user = $this->createApiUser('user');
        $this->userWithEnabledTwoFactor($user);

        $response = $this->withHeaders($this->apiHeaders($user))
            ->postJson('/api/self-2fa/confirm', ['code' => '000000']);

        $response->assertStatus(422)
            ->assertExactJson([
                'confirmed' => false,
                'message' => __('two_factor_invalid_code'),
                'errors' => ['code' => ['The provided two factor authentication code was invalid.']],
            ]);

        $this->assertNull($user->refresh()->two_factor_confirmed_at);
    }

    public function test_confirm_without_code_returns_validation_error(): void
    {
        $user = $this->createApiUser('user');
        $this->userWithEnabledTwoFactor($user);

        $response = $this->withHeaders($this->apiHeaders($user))
            ->postJson('/api/self-2fa/confirm', []);

        $response->assertStatus(422)
            ->assertJsonPath('confirmed', false)
            ->assertJsonStructure(['errors' => ['code']]);
    }

    public function test_recovery_returns_existing_codes_without_regenerating(): void
    {
        $user = $this->createApiUser('user');
        $this->userWithEnabledTwoFactor($user);

        $expected = json_decode(decrypt($user->two_factor_recovery_codes), true);

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/self-2fa/recovery');

        $response->assertOk()->assertExactJson($expected);
    }

    public function test_recovery_generates_codes_when_missing(): void
    {
        // Secret set but recovery codes wiped (e.g. after a failed migration).
        $user = $this->createApiUser('user', [
            'two_factor_secret' => encrypt('TEST-TWO-FACTOR-SECRET'),
            'two_factor_recovery_codes' => null,
        ]);

        $response = $this->withHeaders($this->apiHeaders($user))
            ->getJson('/api/self-2fa/recovery');

        $codes = $response->assertOk()->json();
        $this->assertCount(8, $codes);
        $this->assertSame($codes, json_decode(decrypt($user->refresh()->two_factor_recovery_codes), true));
    }

    public function test_disable_clears_all_two_factor_fields(): void
    {
        $user = $this->createApiUser('user', [
            'two_factor_secret' => encrypt('TEST-TWO-FACTOR-SECRET'),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        ]);

        $response = $this->withHeaders($this->apiHeaders($user))
            ->deleteJson('/api/self-2fa/disable');

        $response->assertOk()->assertExactJson(['enabled' => false]);

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_guest_role_is_denied_on_self_two_factor_routes(): void
    {
        $guest = $this->createApiUser('guest');

        $status = $this->withHeaders($this->apiHeaders($guest))->getJson('/api/self-2fa/status');
        $enable = $this->withHeaders($this->apiHeaders($guest))->postJson('/api/self-2fa/enable', []);

        foreach ([$status, $enable] as $response) {
            $response->assertForbidden()->assertJsonStructure(['message']);
        }
    }

    public function test_unauthenticated_request_is_rejected_with_json(): void
    {
        $response = $this->getJson('/api/self-2fa/status');

        $response->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_moderator_can_manage_own_two_factor(): void
    {
        $moderator = $this->createApiUser('moderator');

        $enable = $this->withHeaders($this->apiHeaders($moderator))
            ->postJson('/api/self-2fa/enable');
        $enable->assertOk();

        $status = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/self-2fa/status');
        $status->assertOk()->assertExactJson(['two_factor_enabled' => true]);
    }
}
