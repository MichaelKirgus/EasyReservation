<?php

namespace Tests\Feature;

use App\Jobs\ExecuteScheduledTaskJob;
use App\Models\ActionList;
use App\Models\ActionListAction;
use App\Models\ScheduledTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledActionListApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_action_list_with_actions(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/action-lists', [
                'name' => 'Morning Actions',
                'active' => true,
                'moderation_selectable' => true,
                'actions' => [[
                    'type' => 'change_setting',
                    'config' => ['name' => 'reservation_enabled', 'value' => '1'],
                    'enabled' => true,
                ]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Morning Actions');
        $this->assertDatabaseHas('action_list_actions', [
            'action_list_id' => $response->json('id'),
            'type' => 'change_setting',
        ]);
    }

    public function test_action_list_action_can_be_updated_and_deleted(): void
    {
        $admin = $this->createApiUser('admin');
        $list = ActionList::create(['name' => 'Editable Actions', 'active' => true]);
        $action = ActionListAction::create([
            'action_list_id' => $list->id,
            'type' => 'change_setting',
            'config' => [],
            'enabled' => true,
            'sort_order' => 0,
        ]);

        $update = $this->withHeaders($this->apiHeaders($admin))
            ->putJson('/api/admin/action-lists/'.$list->id.'/actions/'.$action->id, [
                'type' => 'wait_n_seconds',
                'config' => ['seconds' => 2],
                'enabled' => false,
                'sort_order' => 1,
            ]);

        $update->assertOk()
            ->assertJsonPath('type', 'wait_n_seconds')
            ->assertJsonPath('enabled', false);

        $delete = $this->withHeaders($this->apiHeaders($admin))
            ->deleteJson('/api/admin/action-lists/'.$list->id.'/actions/'.$action->id);

        $delete->assertOk();
        $this->assertDatabaseMissing('action_list_actions', ['id' => $action->id]);
    }

    public function test_scheduled_task_requires_schedule_and_valid_action_list(): void
    {
        $admin = $this->createApiUser('admin');

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/scheduled-tasks', [
                'action_list_id' => 999999,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['action_list_id']);
    }

    public function test_admin_can_create_cron_task_and_get_next_runs(): void
    {
        $admin = $this->createApiUser('admin');
        $list = ActionList::create(['name' => 'Cron Actions', 'active' => true]);

        $create = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/scheduled-tasks', [
                'action_list_id' => $list->id,
                'cron_expression' => '*/5 * * * *',
                'active' => true,
            ]);

        $create->assertCreated()
            ->assertJsonPath('type', 'action_list')
            ->assertJsonPath('action_list_id', $list->id);
        $this->assertNotNull($create->json('next_run_at'));

        $nextRuns = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/cron/next-run', ['expression' => '*/5 * * * *']);

        $nextRuns->assertOk()
            ->assertJsonCount(5, 'next_runs');
    }

    public function test_run_now_queues_action_list_execution(): void
    {
        Queue::fake();
        $admin = $this->createApiUser('admin');
        $list = ActionList::create(['name' => 'Queued Actions', 'active' => true]);
        $task = ScheduledTask::create([
            'type' => 'action_list',
            'run_at' => now()->addHour(),
            'active' => true,
            'executed' => false,
            'action_list_id' => $list->id,
            'options' => ['action_list_id' => $list->id],
        ]);

        $response = $this->withHeaders($this->apiHeaders($admin))
            ->postJson('/api/admin/scheduled-tasks/'.$task->id.'/run-now');

        $response->assertOk()
            ->assertJsonPath('success', true);
        Queue::assertPushed(ExecuteScheduledTaskJob::class);
    }

    public function test_moderator_cannot_manage_scheduled_tasks(): void
    {
        $moderator = $this->createApiUser('moderator');

        $response = $this->withHeaders($this->apiHeaders($moderator))
            ->getJson('/api/admin/scheduled-tasks');

        $response->assertForbidden();
    }
}
