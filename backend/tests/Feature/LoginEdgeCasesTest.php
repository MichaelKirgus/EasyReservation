<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\TestCase;

class LoginEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(TwoFactorAuthenticationProvider::class, new class implements TwoFactorAuthenticationProvider
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
        });
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Cookie|null
     */
    protected function sessionCookie(\Illuminate\Testing\TestResponse $response)
    {
        return collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'api_session');
    }

    protected function twoFactorUser(array $attributes = []): User
    {
        return $this->createApiUser('user', array_merge([
            'two_factor_secret' => encrypt('TEST-TWO-FACTOR-SECRET'),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['rec-code-1'])),
        ], $attributes));
    }

    public function test_inactive_user_is_rejected_even_with_correct_password(): void
    {
        $user = $this->createApiUser('user', ['active' => false]);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', __('auth_invalid_credentials'));
    }

    public function test_login_with_name_identifier_succeeds(): void
    {
        $user = $this->createApiUser('user');

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->name,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'user');
    }

    public function test_login_with_email_identifier_succeeds_and_rotates_token(): void
    {
        $user = $this->createApiUser('user');
        $oldToken = (string) $user->api_token;

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $newToken = $response->assertOk()->json('api_token');
        $this->assertNotSame($oldToken, $newToken);

        // The new token must authenticate against protected routes.
        $status = $this->withHeaders(['X-Api-Key' => $newToken])
            ->getJson('/api/self-2fa/status');
        $status->assertOk();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->createApiUser('user');

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', __('auth_invalid_credentials'));
    }

    public function test_login_without_remember_sets_session_cookie(): void
    {
        $user = $this->createApiUser('user');

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $cookie = $this->sessionCookie($response);
        $this->assertNotNull($cookie);
        $this->assertSame(0, $cookie->getExpiresTime(), 'Session cookie must not have a fixed expiry.');
    }

    public function test_login_with_remember_sets_persistent_cookie(): void
    {
        $user = $this->createApiUser('user');

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $response->assertOk();
        $cookie = $this->sessionCookie($response);
        $this->assertNotNull($cookie);
        $this->assertGreaterThan(time(), $cookie->getExpiresTime(), 'Remember cookie must persist beyond the session.');
    }

    public function test_two_factor_user_without_otp_gets_prompt(): void
    {
        $user = $this->twoFactorUser();

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertExactJson([
                'message' => __('two_factor_required'),
                'two_factor' => true,
            ]);
    }

    public function test_two_factor_user_with_invalid_otp_is_rejected(): void
    {
        $user = $this->twoFactorUser();

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
            'otp' => '000000',
        ]);

        $response->assertStatus(403)
            ->assertExactJson([
                'message' => __('two_factor_invalid_code'),
                'two_factor' => true,
            ]);
    }

    public function test_two_factor_user_with_valid_otp_logs_in(): void
    {
        $user = $this->twoFactorUser();

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
            'otp' => '123456',
        ]);

        $response->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_recovery_code_allows_login_and_is_consumed(): void
    {
        $user = $this->twoFactorUser();

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
            'otp' => 'rec-code-1',
        ]);

        $response->assertOk()->assertJsonPath('user.id', $user->id);

        $codes = json_decode(decrypt($user->refresh()->two_factor_recovery_codes), true);
        $this->assertNotContains('rec-code-1', $codes, 'Used recovery code must be consumed.');
    }

    public function test_invalid_recovery_code_is_rejected(): void
    {
        $user = $this->twoFactorUser();

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
            'otp' => 'not-a-real-code',
        ]);

        $response->assertStatus(403)
            ->assertExactJson([
                'message' => __('two_factor_invalid_code'),
                'two_factor' => true,
            ]);

        // The original recovery code must remain usable.
        $codes = json_decode(decrypt($user->refresh()->two_factor_recovery_codes), true);
        $this->assertContains('rec-code-1', $codes);
    }

    public function test_logout_clears_session_cookie(): void
    {
        $user = $this->createApiUser('user');

        $login = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
        ]);
        $token = $login->assertOk()->json('api_token');

        $logout = $this->withHeaders(['X-Api-Key' => $token])
            ->postJson('/api/auth/logout');

        $logout->assertOk();
        $cookie = $this->sessionCookie($logout);
        $this->assertNotNull($cookie, 'Logout must send an expiring api_session cookie.');
        $this->assertLessThanOrEqual(time(), $cookie->getExpiresTime(), 'Logout must expire the session cookie.');
    }
}
