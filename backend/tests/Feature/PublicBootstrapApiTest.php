<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicBootstrapApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSetting('reservation_token_enabled', 0);
    }

    public function test_languages_endpoint_lists_available_languages(): void
    {
        $response = $this->getJson('/api/languages');

        $response->assertOk()
            ->assertJsonFragment(['de'])
            ->assertJsonFragment(['en']);
    }

    public function test_language_names_endpoint_returns_display_names(): void
    {
        $response = $this->getJson('/api/language-names');

        $response->assertOk()
            ->assertJsonPath('de', 'Deutsch')
            ->assertJsonPath('en', 'English');
    }

    public function test_translation_endpoint_returns_public_translations(): void
    {
        $response = $this->getJson('/api/translations/de');

        $response->assertOk()
            ->assertJsonStructure(['button_submit_reservation']);
    }

    public function test_translation_endpoint_returns_not_found_for_unknown_language(): void
    {
        $response = $this->getJson('/api/translations/xx');

        $response->assertNotFound();
    }

    public function test_public_config_returns_frontend_bootstrap_shape(): void
    {
        $response = $this->getJson('/api/public/config');

        $response->assertOk()
            ->assertJsonStructure([
                'settings',
                'form_fields',
                'attendees',
                'waitlist_entries',
                'app_version',
                'stats' => ['count', 'max', 'waitlist_pending', 'waitlist_limit', 'waitlist_enabled'],
                'privacy_policy_enabled',
                'faq_enabled',
            ]);
    }

    public function test_public_config_requires_site_token_when_enabled(): void
    {
        $this->setSetting('reservation_token_enabled', 1);

        $response = $this->getJson('/api/public/config');

        $response->assertForbidden()
            ->assertJsonPath('message', __('middleware_invalid_site_token'));
    }
}
