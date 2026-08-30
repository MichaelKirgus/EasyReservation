<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmailValidation>
 */
class EmailValidationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'reservation',
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'payload' => ['note' => fake()->sentence()],
            'token' => Str::random(40),
            'status' => 'pending',
            'requires_admin_approval' => false,
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Validation for a waitlist entry.
     */
    public function forWaitlist(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'waitlist',
        ]);
    }

    /**
     * Validation that requires admin approval before completing.
     */
    public function requiringApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_admin_approval' => true,
        ]);
    }

    /**
     * Expired validation.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
