<?php

namespace Tests\Feature;

use App\Models\ValidationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidationRuleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_validation_rule(): void
    {
        $admin = $this->createApiUser('admin');
        $data = [
            'name' => 'Blocked Name',
            'description' => 'Reject names containing admin',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => 'admin',
            'error_message' => 'Name is not allowed',
        ];

        $create = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/validation-rules', $data);
        $create->assertCreated()->assertJsonPath('name', 'Blocked Name');

        $ruleId = $create->json('id');
        $update = $this->withHeaders($this->apiHeaders($admin))
            ->putJson('/api/admin/validation-rules/'.$ruleId, array_merge($data, [
                'name' => 'Updated Rule',
            ]));
        $update->assertOk()->assertJsonPath('name', 'Updated Rule');

        $delete = $this->withHeaders($this->apiHeaders($admin))
            ->deleteJson('/api/admin/validation-rules/'.$ruleId);
        $delete->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('validation_rules', ['id' => $ruleId]);
    }

    public function test_rule_rejects_operator_not_allowed_for_condition_type(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/validation-rules', [
                'name' => 'Invalid Rule',
                'active' => true,
                'field_key' => 'name',
                'condition_type' => 'contains',
                'condition_operator' => 'match',
                'condition_value' => 'admin',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['condition_operator']);
    }

    public function test_admin_can_update_validation_rule_configuration_and_reorder(): void
    {
        $admin = $this->createApiUser('admin');
        $first = ValidationRule::create([
            'name' => 'First', 'active' => true, 'field_key' => 'name',
            'condition_type' => 'contains', 'condition_operator' => 'contains',
            'condition_value' => 'one', 'sort_order' => 0,
        ]);
        $second = ValidationRule::create([
            'name' => 'Second', 'active' => true, 'field_key' => 'name',
            'condition_type' => 'contains', 'condition_operator' => 'contains',
            'condition_value' => 'two', 'sort_order' => 1,
        ]);

        $config = $this->withHeaders($this->apiHeaders($admin))
            ->patchJson('/api/admin/validation-rules/config', [
                'enabled' => false,
                'mode' => 'whitelist',
            ]);
        $config->assertOk()->assertJson(['enabled' => false, 'mode' => 'whitelist']);

        $reorder = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/validation-rules/reorder', [
                'rules' => [
                    ['id' => $first->id, 'sort_order' => 1],
                    ['id' => $second->id, 'sort_order' => 0],
                ],
            ]);
        $reorder->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, $first->refresh()->sort_order);
        $this->assertSame(0, $second->refresh()->sort_order);
    }

    public function test_available_fields_include_standard_fields(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->getJson('/api/admin/validation-rules/available-fields');

        $response->assertOk()
            ->assertJsonFragment(['key' => 'name', 'label' => 'Name'])
            ->assertJsonFragment(['key' => 'email', 'label' => 'Email']);
    }

    public function test_moderator_cannot_manage_validation_rules(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/admin/validation-rules');

        $response->assertForbidden();
    }
}
