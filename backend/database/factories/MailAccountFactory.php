<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MailAccount>
 */
class MailAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Note: `password` is encrypted by a model mutator on write and
     * decrypted on read, so plain values can be used here.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => fake()->unique()->safeEmail(),
            'password' => fake()->password(),
            'auth_method' => 'plain',
            'ignore_self_signed' => false,
            'tls_version' => 'auto',
            'timeout' => 30,
            'rate_limit_enabled' => false,
            'retry_count' => 3,
            'from_address' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }

    /**
     * Account with rate limiting enabled.
     */
    public function rateLimited(int $perMinute = 10, ?int $perHour = null): static
    {
        return $this->state(fn (array $attributes) => [
            'rate_limit_enabled' => true,
            'rate_limit_per_minute' => $perMinute,
            'rate_limit_per_hour' => $perHour,
        ]);
    }

    /**
     * Inactive account.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
