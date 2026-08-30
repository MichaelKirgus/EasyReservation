<?php

namespace Tests\Feature;

use App\Jobs\SendMailJob;
use App\Models\EmailTemplate;
use App\Models\MailAccount;
use App\Models\MailGroupAccount;
use App\Models\MailTransportGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailValidationApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->setSetting('reservation_enabled', 1);
        $this->setSetting('waitlist_enabled', 1);
    }

    /**
     * Wire up a full mail chain (transport group + account + template) for the
     * given setting key so that email dispatch is exercised end-to-end.
     */
    protected function configureMailChain(string $templateSettingKey): void
    {
        $group = MailTransportGroup::factory()->create();
        $account = MailAccount::factory()->create();
        MailGroupAccount::factory()->assign($group, $account)->create();

        $template = EmailTemplate::factory()->withTransportGroup($group)->create([
            'subject' => 'Test {{name}}',
            'body' => '<p>Hi {{name}}</p>',
        ]);

        $this->setSetting($templateSettingKey, $template->id);
    }

    public function test_public_verify_with_valid_token_creates_reservation(): void
    {
        \App\Models\EmailValidation::factory()->create([
            'type' => 'reservation',
            'display_name' => 'Verify Alice',
            'email' => 'verify-alice@example.com',
            'token' => 'tok-verify-ok',
            'status' => 'email_pending',
        ]);

        $response = $this->getJson('/api/email-validations/tok-verify-ok');

        $response->assertOk()
            ->assertJsonPath('waitlist', false)
            ->assertJsonStructure(['message', 'reservation']);

        $this->assertDatabaseHas('reservations', ['display_name' => 'Verify Alice']);
    }

    public function test_public_verify_with_unknown_token_returns_400(): void
    {
        $response = $this->getJson('/api/email-validations/does-not-exist');

        $response->assertStatus(400)
            ->assertJsonPath('message', __('validation_token_not_found'));
    }

    public function test_expired_token_is_marked_expired_and_rejected(): void
    {
        \App\Models\EmailValidation::factory()->expired()->create([
            'token' => 'tok-expired',
            'status' => 'email_pending',
        ]);

        $response = $this->getJson('/api/email-validations/tok-expired');

        $response->assertStatus(400)
            ->assertJsonPath('message', __('validation_link_expired'));

        $this->assertSame('expired', \App\Models\EmailValidation::query()->where('token', 'tok-expired')->first()->status);
    }

    public function test_completed_link_cannot_be_used_twice(): void
    {
        \App\Models\EmailValidation::factory()->create([
            'token' => 'tok-used',
            'status' => 'completed',
        ]);

        $response = $this->getJson('/api/email-validations/tok-used');

        $response->assertStatus(400)
            ->assertJsonPath('message', __('validation_link_already_used'));
    }

    public function test_verify_with_admin_approval_returns_pending_202(): void
    {
        \App\Models\EmailValidation::factory()->requiringApproval()->create([
            'token' => 'tok-approve-pending',
            'status' => 'email_pending',
        ]);

        $response = $this->getJson('/api/email-validations/tok-approve-pending');

        // Note: the message string in EmailValidationController is hard-coded with a
        // non-UTF8 encoding artifact ("bestÃ¤tigt"), so we assert on the stable parts.
        $response->assertStatus(202)
            ->assertJsonPath('pending_admin', true);
        $this->assertStringContainsString('Wartet auf Freigabe durch Admin.', (string) $response->json('message'));

        $pending = \App\Models\EmailValidation::query()->where('token', 'tok-approve-pending')->first();
        $this->assertSame('waiting_admin', $pending->status);
        $this->assertNull($pending->reservation_id, 'No reservation may exist before admin approval.');
    }

    public function test_admin_approve_creates_reservation_and_sends_email(): void
    {
        $admin = $this->createApiUser('admin');
        $this->configureMailChain('email_reservation_success_template_id');

        \App\Models\EmailValidation::factory()->requiringApproval()->create([
            'display_name' => 'Approved Bob',
            'email' => 'approved-bob@example.com',
            'token' => 'tok-admin-approve',
            'status' => 'waiting_admin',
        ]);

        $validation = \App\Models\EmailValidation::query()->where('token', 'tok-admin-approve')->first();

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/email-validations/'.$validation->id.'/approve');

        $response->assertOk()
            ->assertJsonPath('message', __('reservation_approved'));

        $this->assertDatabaseHas('reservations', ['display_name' => 'Approved Bob']);
        $this->assertSame('completed', $validation->refresh()->status);

        // The reservation success email was dispatched through the mail pipeline.
        $this->assertDatabaseHas('job_logs', [
            'job' => SendMailJob::class,
            'status' => 'started',
        ]);
    }

    public function test_admin_approve_waitlist_validation_creates_entry(): void
    {
        $admin = $this->createApiUser('admin');
        $this->configureMailChain('email_waitlist_validation_success_template_id');

        \App\Models\EmailValidation::factory()->forWaitlist()->requiringApproval()->create([
            'display_name' => 'Waitlist Carol',
            'email' => 'waitlist-carol@example.com',
            'token' => 'tok-waitlist-approve',
            'status' => 'waiting_admin',
        ]);

        $validation = \App\Models\EmailValidation::query()->where('token', 'tok-waitlist-approve')->first();

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/email-validations/'.$validation->id.'/approve');

        $response->assertOk()
            ->assertJsonPath('message', __('waitlist_entry_approved'));

        $this->assertDatabaseHas('waitlist_entries', [
            'display_name' => 'Waitlist Carol',
            'status' => 'pending',
        ]);
        $this->assertSame('completed', $validation->refresh()->status);
    }

    public function test_public_approve_by_token_route_creates_reservation(): void
    {
        $this->configureMailChain('email_reservation_success_template_id');

        \App\Models\EmailValidation::factory()->requiringApproval()->create([
            'display_name' => 'Public Approve Dave',
            'email' => 'public-dave@example.com',
            'token' => 'tok-public-approve',
            'status' => 'waiting_admin',
        ]);

        $response = $this->getJson('/api/email-validations/admin-approve/tok-public-approve');

        $response->assertOk()
            ->assertJsonPath('waitlist', false)
            ->assertJsonPath('result.status', 'completed');

        $this->assertDatabaseHas('reservations', ['display_name' => 'Public Approve Dave']);
    }

    public function test_approving_email_pending_validation_is_rejected(): void
    {
        $admin = $this->createApiUser('admin');

        \App\Models\EmailValidation::factory()->requiringApproval()->create([
            'token' => 'tok-still-pending',
            'status' => 'email_pending',
        ]);

        $validation = \App\Models\EmailValidation::query()->where('token', 'tok-still-pending')->first();

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/email-validations/'.$validation->id.'/approve');

        $response->assertStatus(400)
            ->assertJsonPath('message', __('email_validation_pending_text'));
    }

    public function test_resend_sends_validation_email_when_template_configured(): void
    {
        $admin = $this->createApiUser('admin');
        $this->configureMailChain('email_validation_template_id');

        \App\Models\EmailValidation::factory()->requiringApproval()->create([
            'token' => 'tok-resend',
            'status' => 'waiting_admin',
        ]);

        $validation = \App\Models\EmailValidation::query()->where('token', 'tok-resend')->first();

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/email-validations/'.$validation->id.'/resend');

        $response->assertOk()
            ->assertJsonPath('message', __('validation_email_resent'));

        $this->assertDatabaseHas('job_logs', [
            'job' => SendMailJob::class,
            'status' => 'started',
        ]);
    }

    public function test_resend_without_configured_template_returns_error(): void
    {
        $admin = $this->createApiUser('admin');

        \App\Models\EmailValidation::factory()->requiringApproval()->create([
            'token' => 'tok-resend-broken',
            'status' => 'waiting_admin',
        ]);

        $validation = \App\Models\EmailValidation::query()->where('token', 'tok-resend-broken')->first();

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/email-validations/'.$validation->id.'/resend');

        $response->assertStatus(400);
    }
}
