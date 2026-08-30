<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city() . ' Venue',
            'city' => fake()->city(),
            'address' => fake()->streetAddress(),
            'public_transport' => fake()->sentence(3),
            'notes' => null,
            'capacity_override' => 100,
            'active' => true,
        ];
    }

    /**
     * Location without a capacity override (falls back to event/global settings).
     */
    public function noCapacity(): static
    {
        return $this->state(fn (array $attributes) => [
            'capacity_override' => null,
        ]);
    }

    /**
     * Inactive location.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
