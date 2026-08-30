<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomPlaceholder>
 */
class CustomPlaceholderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Note: setting `value` triggers the model mutator which encrypts the
     * value when `type` is "secret".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // NOTE: `type` must be assigned before `value` — the value mutator
        // reads $this->type to decide whether to encrypt.
        return [
            'key' => Str::snake(fake()->unique()->words(2, true)),
            'type' => 'text',
            'description' => fake()->sentence(),
            'value' => fake()->word(),
        ];
    }

    /**
     * Secret placeholder (value stored encrypted).
     */
    public function secret(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'secret',
        ]);
    }
}
