<?php

namespace Tests\Feature;

use App\Models\EmailValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailValidationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_pending_email_validations(): void
    {
        $admin = $this->createApiUser('admin');
        EmailValidation::create([
            'type' => 'reservation',
            'display_name' => 'Pending Alice',
            'email' => 'alice@example.com',
            'token' => 'pending-token',
            'status' => 'waiting_admin',
            'requires_admin_approval' => true,
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/email-validations?status=pending&type=reservation');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.display_name', 'Pending Alice');
    }

    public function test_admin_can_discard_email_validation(): void
    {
        $admin = $this->createApiUser('admin');
        $validation = EmailValidation::create([
            'type' => 'reservation',
            'display_name' => 'Discard Alice',
            'email' => 'alice@example.com',
            'token' => 'discard-token',
            'status' => 'waiting_admin',
            'requires_admin_approval' => true,
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->deleteJson('/api/admin/email-validations/'.$validation->id);

        $response->assertOk()
            ->assertJsonPath('message', __('validation_discarded'));
        $this->assertSame('cancelled', $validation->refresh()->status);
    }

    public function test_invalid_public_validation_token_returns_bad_request(): void
    {
        $response = $this->getJson('/api/email-validations/does-not-exist');

        $response->assertBadRequest();
    }

    public function test_moderator_can_list_and_discard_email_validations(): void
    {
        $moderator = $this->createApiUser('moderator');
        $validation = EmailValidation::create([
            'type' => 'waitlist',
            'display_name' => 'Moderator Entry',
            'email' => 'entry@example.com',
            'token' => 'moderator-token',
            'status' => 'waiting_admin',
            'requires_admin_approval' => true,
        ]);

        $list = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/moderator/email-validations');
        $list->assertOk()->assertJsonCount(1);

        $delete = $this->withHeaders($this->apiHeaders($moderator))
            ->deleteJson('/api/moderator/email-validations/'.$validation->id);
        $delete->assertOk();
    }
}
