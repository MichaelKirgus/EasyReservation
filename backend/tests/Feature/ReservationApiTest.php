<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSetting('reservation_enabled', 1);
        $this->setSetting('reservation_token_enabled', 0);
        $this->setSetting('waitlist_enabled', 0);
        $this->setSetting('reservation_max', 0);
    }

    public function test_public_reservation_can_be_created(): void
    {
        $response = $this->postJson('/api/reservations', [
            'name' => 'Alice Example',
            'email' => 'alice@example.com',
            'site_token' => $this->validSiteToken(),
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['reservation'])
            ->assertJsonPath('reservation.display_name', 'Alice Example');
    }

    public function test_public_reservation_requires_a_valid_name(): void
    {
        $response = $this->postJson('/api/reservations', [
            'name' => '',
            'email' => 'alice@example.com',
            'site_token' => $this->validSiteToken(),
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', __('validation_invalid_name'));
    }

    public function test_public_reservation_requires_site_token_when_enabled(): void
    {
        $this->setSetting('reservation_token_enabled', 1);

        $response = $this->postJson('/api/reservations', [
            'name' => 'Alice Example',
            'email' => 'alice@example.com',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('message', __('middleware_invalid_site_token'));
    }
}
