<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FormField>
 */
class FormFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => Str::snake(fake()->unique()->words(2, true)),
            'label' => fake()->word(),
            'type' => 'text',
            'required' => false,
            'options' => null,
            'placeholder' => null,
            'help_text' => null,
            'order' => 0,
            'active' => true,
            'visible_public' => true,
            'visible_admin' => true,
            'is_email' => false,
        ];
    }

    /**
     * Required field.
     */
    public function required(): static
    {
        return $this->state(fn (array $attributes) => [
            'required' => true,
        ]);
    }

    /**
     * Email-typed field.
     */
    public function emailField(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'email',
            'is_email' => true,
        ]);
    }

    /**
     * Checkbox group with options.
     */
    public function checkboxGroup(array $options = ['Option A', 'Option B']): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'checkbox_group',
            'options' => $options,
        ]);
    }

    /**
     * Hidden from the public form.
     */
    public function hiddenPublic(): static
    {
        return $this->state(fn (array $attributes) => [
            'visible_public' => false,
        ]);
    }

    /**
     * Inactive field.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
