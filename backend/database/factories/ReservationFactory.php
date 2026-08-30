<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Note: setting `email` triggers the model mutator which encrypts the
     * value and sets `email_encrypted = true`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'payload' => ['note' => fake()->sentence()],
            'site_token' => Str::random(40),
            'date_added' => now(),
        ];
    }

    /**
     * Reservation without an email address.
     */
    public function withoutEmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
        ]);
    }

    /**
     * Reservation that originated from the waitlist.
     */
    public function fromWaitlist(): static
    {
        return $this->state(fn (array $attributes) => [
            'from_waitlist' => true,
        ]);
    }
}
