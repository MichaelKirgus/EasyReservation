<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmailTemplate>
 */
class EmailTemplateFactory extends Factory
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
            'type' => 'validation',
            'subject' => 'Hello {{ display_name }}',
            'body' => "Hi {{ display_name }},\n\nYour reservation has been received.\n\nRegards",
        ];
    }

    /**
     * Template of a specific type (e.g. reservation_success, waitlist_promoted).
     */
    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    /**
     * Template assigned to a mail transport group.
     */
    public function withTransportGroup(\App\Models\MailTransportGroup $group): static
    {
        return $this->state(fn (array $attributes) => [
            'transport_group_id' => $group->id,
        ]);
    }
}
