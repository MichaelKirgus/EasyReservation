<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function withRole(string $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
            'active' => true,
            'api_token' => Str::random(40),
            'api_token_is_hashed' => false,
        ]);
    }

    public function admin(): static
    {
        return $this->withRole('admin');
    }

    public function superadmin(): static
    {
        return $this->withRole('superadmin');
    }

    public function moderator(): static
    {
        return $this->withRole('moderator');
    }

    public function guest(): static
    {
        return $this->withRole('guest');
    }

    public function user(): static
    {
        return $this->withRole('user');
    }
}
