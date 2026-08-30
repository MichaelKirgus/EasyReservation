<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MailGroupAccount>
 */
class MailGroupAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => \App\Models\MailTransportGroup::factory(),
            'account_id' => \App\Models\MailAccount::factory(),
            'priority' => 0,
        ];
    }

    /**
     * Assignment for a specific group and account.
     */
    public function assign(\App\Models\MailTransportGroup $group, \App\Models\MailAccount $account): static
    {
        return $this->state(fn (array $attributes) => [
            'group_id' => $group->id,
            'account_id' => $account->id,
        ]);
    }
}
