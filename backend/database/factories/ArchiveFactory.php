<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Archive>
 */
class ArchiveFactory extends Factory
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
            'store_emails' => true,
        ];
    }

    /**
     * Archive that does not store email addresses.
     */
    public function withoutEmails(): static
    {
        return $this->state(fn (array $attributes) => [
            'store_emails' => false,
        ]);
    }
}
