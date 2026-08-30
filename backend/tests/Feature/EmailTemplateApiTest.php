<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_list_email_templates(): void
    {
        $admin = $this->createApiUser('admin');

        $createResponse = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/email-templates', [
                'name' => 'Reservation Confirmation',
                'type' => 'confirmation',
                'subject' => 'Reservation received',
                'body' => 'Hello {{name}}',
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('name', 'Reservation Confirmation')
            ->assertJsonPath('to', '{{recipient_email}}');

        $listResponse = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/email-templates');

        $listResponse->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.subject', 'Reservation received');
    }

    public function test_template_preview_replaces_recipient_placeholders(): void
    {
        $admin = $this->createApiUser('admin');
        $template = EmailTemplate::create([
            'name' => 'Preview Template',
            'type' => 'confirmation',
            'subject' => 'Hello {{name}}',
            'body' => 'Contact: {{email}}',
            'to' => '{{recipient_email}}',
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/email-templates/'.$template->id.'/preview?name=Alice&email=alice@example.com');

        $response->assertOk()
            ->assertJsonPath('template_id', $template->id)
            ->assertJsonPath('subject', 'Hello Alice')
            ->assertJsonPath('body', 'Contact: alice@example.com');
    }

    public function test_admin_can_clone_email_template_with_new_name(): void
    {
        $admin = $this->createApiUser('admin');
        $template = EmailTemplate::create([
            'name' => 'Original Template',
            'type' => 'confirmation',
            'subject' => 'Subject',
            'body' => 'Body',
            'to' => '{{recipient_email}}',
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/email-templates/'.$template->id.'/clone', [
                'name' => 'Cloned Template',
            ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Cloned Template')
            ->assertJsonPath('subject', 'Subject');
        $this->assertDatabaseCount('email_templates', 2);
    }

    public function test_email_template_creation_requires_name_subject_and_body(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/email-templates', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'subject', 'body']);
    }

    public function test_moderator_cannot_manage_admin_email_templates(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/admin/email-templates');

        $response->assertForbidden();
    }
}
