<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startAt = now()->addDays(7)->setTime(18, 0);

        return [
            'title' => fake()->unique()->words(3, true),
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addHours(4),
            'location_id' => null,
            'capacity_override' => 50,
            'active' => true,
            'notes' => fake()->sentence(),
        ];
    }

    /**
     * Event attached to a location.
     */
    public function atLocation(\App\Models\Location $location): static
    {
        return $this->state(fn (array $attributes) => [
            'location_id' => $location->id,
        ]);
    }

    /**
     * Event that already took place.
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_at' => now()->subDays(2)->setTime(18, 0),
            'end_at' => now()->subDays(2)->setTime(22, 0),
        ]);
    }

    /**
     * Inactive event.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
