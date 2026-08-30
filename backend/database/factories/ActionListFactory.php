<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActionList>
 */
class ActionListFactory extends Factory
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
            'active' => true,
        ];
    }

    /**
     * Inactive action list.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }

    /**
     * Action list selectable from the moderation dashboard.
     */
    public function moderationSelectable(): static
    {
        return $this->state(fn (array $attributes) => [
            'moderation_selectable' => true,
        ]);
    }
}
