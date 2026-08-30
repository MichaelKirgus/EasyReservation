<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MailTransportGroup>
 */
class MailTransportGroupFactory extends Factory
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
            'rate_limit_enabled' => false,
            'failover_strategy' => 'sequential',
            'max_retries_per_account' => 3,
            'is_active' => true,
        ];
    }

    /**
     * Group using round-robin failover.
     */
    public function roundRobin(): static
    {
        return $this->state(fn (array $attributes) => [
            'failover_strategy' => 'round_robin',
        ]);
    }

    /**
     * Inactive group.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
