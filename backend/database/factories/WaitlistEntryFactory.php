<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
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
            'status' => 'pending',
            'date_added' => now(),
        ];
    }

    /**
     * Waitlist entry without an email address.
     */
    public function withoutEmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
        ]);
    }

    /**
     * Entry that has already been promoted to a reservation.
     */
    public function promoted(int|callable|null $reservationId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'promoted',
            'reservation_id' => is_callable($reservationId) ? $reservationId() : ($reservationId ?? \App\Models\Reservation::factory()->create()->id),
            'promoted_at' => now(),
        ]);
    }

    /**
     * Entry that has been cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
