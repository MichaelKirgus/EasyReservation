<?php

namespace Tests\Unit;

use App\Models\ValidationRule;
use App\Models\WebhookTemplate;
use App\Services\ValidationRuleEngine;
use App\Services\ValidationResult;
use PHPUnit\Framework\TestCase;
use Tests\TestCase as LaravelTestCase;

class ValidationRuleEngineTest extends LaravelTestCase
{
    private ValidationRuleEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(ValidationRuleEngine::class);
    }

    /**
     * Test that engine returns success when disabled
     */
    public function test_engine_disabled_returns_success()
    {
        setting('validation_rules_enabled', false);

        $result = $this->engine->evaluate(['name' => 'test']);

        $this->assertTrue($result->passes());
    }

    /**
     * Test length validation with < operator
     */
    public function test_length_validation_less_than()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Min Length Test',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'length',
            'condition_operator' => '<',
            'condition_value' => '3',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['name' => 'ab']);
        $this->assertTrue($result->fails());

        $result = $this->engine->evaluate(['name' => 'abc']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test length validation with > operator
     */
    public function test_length_validation_greater_than()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Max Length Test',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'length',
            'condition_operator' => '>',
            'condition_value' => '10',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['name' => 'abcdefghijklmnop']);
        $this->assertTrue($result->fails());

        $result = $this->engine->evaluate(['name' => 'short']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test length validation with = operator
     */
    public function test_length_validation_equals()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Exact Length Test',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'length',
            'condition_operator' => '=',
            'condition_value' => '5',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['name' => 'hello']);
        $this->assertTrue($result->fails());

        $result = $this->engine->evaluate(['name' => 'test']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test regex validation with match operator
     */
    public function test_regex_validation_match()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Regex Match Test',
            'active' => true,
            'field_key' => 'email',
            'condition_type' => 'regex',
            'condition_operator' => 'match',
            'condition_value' => '/^[a-z]+@example\.com$/',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['email' => 'user@example.com']);
        $this->assertTrue($result->fails());

        $result = $this->engine->evaluate(['email' => 'user@other.com']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test regex validation with not_match operator
     */
    public function test_regex_validation_not_match()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Regex Not Match Test',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'regex',
            'condition_operator' => 'not_match',
            'condition_value' => '/[0-9]/',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['name' => 'test123']);
        $this->assertTrue($result->fails());

        $result = $this->engine->evaluate(['name' => 'testname']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test contains validation with contains operator
     */
    public function test_contains_validation_contains()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Contains Test',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => 'badword',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['name' => 'this is badword']);
        $this->assertTrue($result->fails());

        $result = $this->engine->evaluate(['name' => 'this is fine']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test contains validation is case-insensitive
     */
    public function test_contains_validation_case_insensitive()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Case Insensitive Test',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => 'BadWord',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['name' => 'this is BADWORD']);
        $this->assertTrue($result->fails());

        $result = $this->engine->evaluate(['name' => 'this is badword']);
        $this->assertTrue($result->fails());
    }

    /**
     * Test contains validation with not_contains operator
     */
    public function test_contains_validation_not_contains()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Not Contains Test',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'not_contains',
            'condition_value' => 'required',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['name' => 'optional']);
        $this->assertTrue($result->fails());

        $result = $this->engine->evaluate(['name' => 'this has required']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test whitelist mode: all rules must pass
     */
    public function test_whitelist_mode_all_rules_must_pass()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'whitelist');

        // Rule 1: Must have at least 3 characters
        ValidationRule::create([
            'name' => 'Min Length',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'length',
            'condition_operator' => '>=',
            'condition_value' => '3',
            'sort_order' => 0,
        ]);

        // Rule 2: Must not have numbers
        ValidationRule::create([
            'name' => 'No Numbers',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'regex',
            'condition_operator' => 'not_match',
            'condition_value' => '/[0-9]/',
            'sort_order' => 1,
        ]);

        // Valid input
        $result = $this->engine->evaluate(['name' => 'validname']);
        $this->assertTrue($result->passes());

        // Too short
        $result = $this->engine->evaluate(['name' => 'ab']);
        $this->assertTrue($result->fails());

        // Has numbers
        $result = $this->engine->evaluate(['name' => 'test123']);
        $this->assertTrue($result->fails());
    }

    /**
     * Test blacklist mode: first failure triggers
     */
    public function test_blacklist_mode_first_failure_triggers()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'First Rule',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => 'bad',
            'sort_order' => 0,
        ]);

        ValidationRule::create([
            'name' => 'Second Rule',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => 'evil',
            'sort_order' => 1,
        ]);

        // Triggers first rule
        $result = $this->engine->evaluate(['name' => 'this is bad']);
        $this->assertTrue($result->fails());

        // Triggers second rule (if not caught by first)
        $result = $this->engine->evaluate(['name' => 'this is evil']);
        $this->assertTrue($result->fails());

        // Passes both
        $result = $this->engine->evaluate(['name' => 'this is good']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test inactive rules are skipped
     */
    public function test_inactive_rules_are_skipped()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Inactive Rule',
            'active' => false,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => 'forbidden',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['name' => 'this is forbidden']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test missing field is skipped
     */
    public function test_missing_field_is_skipped()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Field Rule',
            'active' => true,
            'field_key' => 'phone',
            'condition_type' => 'length',
            'condition_operator' => '>',
            'condition_value' => '10',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['name' => 'test']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test custom error message
     */
    public function test_custom_error_message()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Custom Message Rule',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => 'bad',
            'error_message' => 'Custom error message',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate(['name' => 'bad name']);
        $this->assertTrue($result->fails());
        $this->assertEquals('Custom error message', $result->errorMessage());
    }

    /**
     * Test invalid regex is caught gracefully
     */
    public function test_invalid_regex_is_caught()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Invalid Regex Rule',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'regex',
            'condition_operator' => 'match',
            'condition_value' => '/invalid[regex/', // Invalid regex
            'sort_order' => 0,
        ]);

        // Should return success (condition fails safely)
        $result = $this->engine->evaluate(['name' => 'test']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test unicode category validation
     */
    public function test_unicode_category_validation()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Emoji Check',
            'active' => true,
            'field_key' => 'name',
            'condition_type' => 'unicode_category',
            'condition_operator' => 'contains',
            'condition_value' => 'So', // Symbol, Other
            'sort_order' => 0,
        ]);

        // Contains emoji
        $result = $this->engine->evaluate(['name' => 'test 😀']);
        $this->assertTrue($result->fails());

        // No emoji
        $result = $this->engine->evaluate(['name' => 'test name']);
        $this->assertTrue($result->passes());
    }

    /**
     * Test that context placeholders can be used in contains values
     */
    public function test_contains_validation_with_placeholders()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Placeholder Contains Test',
            'active' => true,
            'field_key' => 'email',
            'condition_type' => 'contains',
            'condition_operator' => 'contains',
            'condition_value' => '{{forbidden_domain}}', // Placeholder
            'sort_order' => 0,
        ]);

        // Without context placeholder
        $result = $this->engine->evaluate(['email' => 'user@example.com']);
        $this->assertTrue($result->passes()); // Placeholder not resolved, no match

        // With context placeholder
        $result = $this->engine->evaluate(
            ['email' => 'user@badomain.com'],
            ['forbidden_domain' => 'badomain']
        );
        $this->assertTrue($result->fails()); // Placeholder resolved to "badomain", matches
    }

    /**
     * Test rule processing with custom payload fields
     */
    public function test_validation_with_custom_payload_fields()
    {
        setting('validation_rules_enabled', true);
        setting('validation_rules_mode', 'blacklist');

        ValidationRule::create([
            'name' => 'Phone Rule',
            'active' => true,
            'field_key' => 'phone',
            'condition_type' => 'length',
            'condition_operator' => '<',
            'condition_value' => '10',
            'sort_order' => 0,
        ]);

        $result = $this->engine->evaluate([
            'name' => 'John',
            'email' => 'john@example.com',
            'phone' => '123', // Too short
        ]);
        
        $this->assertTrue($result->fails());

        $result = $this->engine->evaluate([
            'name' => 'John',
            'email' => 'john@example.com',
            'phone' => '1234567890',
        ]);
        
        $this->assertTrue($result->passes());
    }
}
