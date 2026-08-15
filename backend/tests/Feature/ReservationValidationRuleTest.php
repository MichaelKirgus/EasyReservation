<?php

namespace Tests\Feature;

use App\Models\ValidationRule;
use App\Models\WebhookTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationValidationRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSetting('validation_rules_enabled', true);
        $this->setSetting('validation_rules_mode', 'blacklist');
        $this->setSetting('reservation_enabled', 1);
        $this->setSetting('waitlist_enabled', 0);
        $this->setSetting('reservation_max', 0);
    }

    /**
     * Test reservation fails when validation rule triggers
     */
    public function test_reservation_fails_when_validation_rule_triggers()
    {
        ValidationRule::create([
            'name' => 'Forbidden Name',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => 'admin',
            'error_message' => 'This name is not allowed',
            'sort_order' => 0,
        ]);

        $response = $this->postJson('/api/reservations', [
            'name' => 'admin user',
            'site_token' => $this->getValidSiteToken(),
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'This name is not allowed']);
    }

    /**
     * Test reservation succeeds when validation rules are not triggered
     */
    public function test_reservation_succeeds_when_validation_rules_pass()
    {
        ValidationRule::create([
            'name' => 'Forbidden Number',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'regex',
            'condition_operator' => 'match',
            'condition_value' => '/[0-9]/',
            'sort_order' => 0,
        ]);

        $response = $this->postJson('/api/reservations', [
            'name' => 'valid name',
            'site_token' => $this->getValidSiteToken(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['reservation']);
    }

    /**
     * Test validation rules can access custom payload fields
     */
    public function test_validation_rules_can_access_custom_fields()
    {
        ValidationRule::create([
            'name' => 'Phone Length Check',
            'active' => true,
            'field_key' => 'phone',
            'condition_type' => 'length',
            'condition_operator' => '<',
            'condition_value' => '10',
            'error_message' => 'Phone number too short',
            'sort_order' => 0,
        ]);

        $response = $this->postJson('/api/reservations', [
            'name' => 'john doe',
            'email' => 'john@example.com',
            'payload' => ['phone' => '123'],
            'site_token' => $this->getValidSiteToken(),
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Phone number too short']);
    }

    /**
     * Test whitelist mode: all rules must pass
     */
    public function test_whitelist_mode_requires_all_rules_to_pass()
    {
        $this->setSetting('validation_rules_mode', 'whitelist');

        ValidationRule::create([
            'name' => 'Rule 1',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'length',
            'condition_operator' => '>=',
            'condition_value' => '3',
            'sort_order' => 0,
        ]);

        ValidationRule::create([
            'name' => 'Rule 2',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'regex',
            'condition_operator' => 'not_match',
            'condition_value' => '/[0-9]/',
            'sort_order' => 1,
        ]);

        // Valid: passes both rules
        $response = $this->postJson('/api/reservations', [
            'name' => 'validname',
            'site_token' => $this->getValidSiteToken(),
        ]);
        $response->assertStatus(201);

        // Invalid: fails rule 1 (too short)
        $response = $this->postJson('/api/reservations', [
            'name' => 'ab',
            'site_token' => $this->getValidSiteToken(),
        ]);
        $response->assertStatus(422);

        // Invalid: fails rule 2 (has numbers)
        $response = $this->postJson('/api/reservations', [
            'name' => 'test123',
            'site_token' => $this->getValidSiteToken(),
        ]);
        $response->assertStatus(422);
    }

    /**
     * Test disabled validation rules are skipped
     */
    public function test_disabled_validation_rules_are_skipped()
    {
        ValidationRule::create([
            'name' => 'Disabled Rule',
            'active' => false,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => 'forbidden',
            'sort_order' => 0,
        ]);

        $response = $this->postJson('/api/reservations', [
            'name' => 'forbidden name',
            'site_token' => $this->getValidSiteToken(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['reservation']);
    }

    /**
     * Test global validation rule engine disable
     */
    public function test_global_validation_rules_disable()
    {
        ValidationRule::create([
            'name' => 'Should Be Skipped',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => 'forbidden',
            'sort_order' => 0,
        ]);

        // Disable rules
        $this->setSetting('validation_rules_enabled', false);

        $response = $this->postJson('/api/reservations', [
            'name' => 'forbidden name',
            'site_token' => $this->getValidSiteToken(),
        ]);

        // Should succeed since rules are disabled
        $response->assertStatus(201);
        $response->assertJsonStructure(['reservation']);
    }

}
