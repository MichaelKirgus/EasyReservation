<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ScheduledTask>
 */
class ScheduledTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'action_list',
            'run_at' => now()->addHour(),
            'options' => [],
            'executed' => false,
            'active' => true,
            'run_once' => false,
            'skip_if_overdue' => false,
        ];
    }

    /**
     * Task that is due to run right now.
     */
    public function due(): static
    {
        return $this->state(fn (array $attributes) => [
            'run_at' => now()->subMinute(),
        ]);
    }

    /**
     * Cron-based task (no absolute run_at).
     */
    public function cron(string $expression = '* * * * *'): static
    {
        return $this->state(fn (array $attributes) => [
            'run_at' => null,
            'cron_expression' => $expression,
        ]);
    }

    /**
     * Task that references an event relatively.
     */
    public function relativeToEvent(\App\Models\Event $event, string $relativeTo = 'start_at', int $offsetMinutes = 60): static
    {
        return $this->state(fn (array $attributes) => [
            'run_at' => null,
            'reference_type' => 'event',
            'reference_id' => $event->id,
            'relative_to' => $relativeTo,
            'relative_offset_minutes' => $offsetMinutes,
        ]);
    }

    /**
     * Inactive task.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }

    /**
     * Task bound to an action list.
     */
    public function withActionList(\App\Models\ActionList $actionList): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'action_list',
            'action_list_id' => $actionList->id,
        ]);
    }
}
