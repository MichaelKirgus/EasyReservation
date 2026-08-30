<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IcalTemplate>
 */
class IcalTemplateFactory extends Factory
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
            'content' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR",
        ];
    }

    /**
     * Template without custom content (falls back to generated event data).
     */
    public function emptyContent(): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => null,
        ]);
    }
}
