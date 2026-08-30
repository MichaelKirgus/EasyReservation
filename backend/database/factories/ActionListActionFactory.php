<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActionListAction>
 */
class ActionListActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action_list_id' => \App\Models\ActionList::factory(),
            'type' => 'email',
            'config' => [],
            'sort_order' => 0,
            'enabled' => true,
        ];
    }

    /**
     * Action belonging to a specific action list.
     */
    public function forList(\App\Models\ActionList $list): static
    {
        return $this->state(fn (array $attributes) => [
            'action_list_id' => $list->id,
        ]);
    }

    /**
     * Email action sending a template to reservations.
     */
    public function emailAction(\App\Models\EmailTemplate $template): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'email',
            'config' => ['template_id' => $template->id, 'audience' => 'reservations'],
        ]);
    }

    /**
     * Webhook action.
     */
    public function webhookAction(string $url): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'webhook',
            'config' => ['url' => $url, 'payload_template' => '{}'],
        ]);
    }

    /**
     * Setting-change action.
     */
    public function settingAction(string $name, mixed $value): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'change_setting',
            'config' => ['setting_name' => $name, 'value' => $value],
        ]);
    }

    /**
     * Disabled action.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }
}
