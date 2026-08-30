<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Survey>
 */
class SurveyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(4),
            'description' => fake()->paragraph(),
            'event_id' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addWeeks(2),
            'active' => true,
            'response_token_type' => 'anonymous',
            'max_responses_per_user' => 1,
        ];
    }

    /**
     * Survey bound to an event.
     */
    public function forEvent(\App\Models\Event $event): static
    {
        return $this->state(fn (array $attributes) => [
            'event_id' => $event->id,
        ]);
    }

    /**
     * Survey that is not yet open.
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeeks(3),
        ]);
    }

    /**
     * Survey that is already closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subWeeks(2),
            'ends_at' => now()->subWeek(),
        ]);
    }

    /**
     * Inactive survey.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
