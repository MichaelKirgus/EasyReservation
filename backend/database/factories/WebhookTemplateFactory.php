<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WebhookTemplate>
 */
class WebhookTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'url' => fake()->url() . '/webhook',
            'payload_template' => '{"event": "{{ event_type }}", "display_name": "{{ display_name }}"}',
            'headers_template' => null,
        ];
    }

    /**
     * Template with custom headers.
     */
    public function withHeaders(array $headers): static
    {
        return $this->state(fn (array $attributes) => [
            'headers_template' => json_encode($headers),
        ]);
    }
}
