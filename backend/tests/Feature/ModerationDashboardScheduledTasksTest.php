<?php

namespace Tests\Feature;

use App\Models\ActionList;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\User;
use App\Services\ScheduledTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ModerationDashboardScheduledTasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_stats_include_upcoming_and_recent_scheduler_tasks(): void
    {
        $user = User::factory()->create([
            'role' => 'moderator',
            'active' => true,
            'api_token' => 'moderation-token',
            'api_token_is_hashed' => false,
        ]);

        $actionList = ActionList::create([
            'name' => 'Morning Tasks',
            'description' => 'Test list',
            'active' => true,
            'moderation_selectable' => true,
        ]);

        $taskOne = ScheduledTask::create([
            'type' => 'action_list',
            'run_at' => now()->addHour(),
            'executed' => false,
            'active' => true,
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        $taskTwo = ScheduledTask::create([
            'type' => 'action_list',
            'run_at' => now()->addHours(2),
            'executed' => false,
            'active' => true,
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        $taskThree = ScheduledTask::create([
            'type' => 'action_list',
            'run_at' => now()->addHours(3),
            'executed' => false,
            'active' => true,
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        ScheduledTask::create([
            'type' => 'action_list',
            'run_at' => now()->addHours(4),
            'executed' => false,
            'active' => true,
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        ScheduledTaskExecution::create([
            'scheduled_task_id' => $taskTwo->id,
            'action_list_id' => $actionList->id,
            'action_list_name' => $actionList->name,
            'task_type' => 'action_list',
            'trigger_source' => 'scheduler',
            'status' => 'success',
            'planned_for' => now()->subHours(4),
            'started_at' => now()->subHours(4),
            'finished_at' => now()->subHours(4)->addMinute(),
        ]);

        $latestExecution = ScheduledTaskExecution::create([
            'scheduled_task_id' => $taskThree->id,
            'action_list_id' => $actionList->id,
            'action_list_name' => $actionList->name,
            'task_type' => 'action_list',
            'trigger_source' => 'scheduler',
            'status' => 'failed',
            'planned_for' => now()->subHour(),
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour()->addMinute(),
            'error_message' => 'Boom',
        ]);

        ScheduledTaskExecution::create([
            'scheduled_task_id' => $taskOne->id,
            'action_list_id' => $actionList->id,
            'action_list_name' => $actionList->name,
            'task_type' => 'action_list',
            'trigger_source' => 'manual',
            'status' => 'success',
            'planned_for' => now()->subMinutes(30),
            'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(29),
        ]);

        $response = $this->withHeaders([
            'X-Api-Key' => $user->api_token,
        ])->getJson('/api/admin/moderation-dashboard/stats');

        $response->assertOk();

        $payload = $response->json();

        $this->assertCount(3, $payload['upcoming_scheduled_tasks']);
        $this->assertSame([$taskOne->id, $taskTwo->id, $taskThree->id], array_column($payload['upcoming_scheduled_tasks'], 'id'));

        $this->assertCount(2, $payload['last_executed_scheduled_tasks']);
        $this->assertSame($latestExecution->id, $payload['last_executed_scheduled_tasks'][0]['id']);
        $this->assertSame('failed', $payload['last_executed_scheduled_tasks'][0]['status']);
        $this->assertSame('Boom', $payload['last_executed_scheduled_tasks'][0]['error_message']);
    }

    public function test_execute_task_creates_scheduler_execution_history_entry(): void
    {
        $actionList = ActionList::create([
            'name' => 'Empty Action List',
            'description' => 'No actions',
            'active' => true,
            'moderation_selectable' => true,
        ]);

        $task = ScheduledTask::create([
            'type' => 'action_list',
            'run_at' => now()->addMinutes(10),
            'executed' => false,
            'active' => true,
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        app(ScheduledTaskService::class)->executeTask($task, true, 'scheduler');

        $execution = ScheduledTaskExecution::query()->latest('id')->first();

        $this->assertNotNull($execution);
        $this->assertSame($task->id, $execution->scheduled_task_id);
        $this->assertSame('scheduler', $execution->trigger_source);
        $this->assertSame('success', $execution->status);
        $this->assertNotNull($execution->finished_at);
    }

    public function test_scheduled_task_execution_history_is_trimmed_to_configured_max_rows(): void
    {
        Config::set('app.scheduled_task_execution_retention_days', 365);
        Config::set('app.scheduled_task_execution_max_rows', 2);

        $actionList = ActionList::create([
            'name' => 'Retention List',
            'description' => 'Retention test',
            'active' => true,
            'moderation_selectable' => true,
        ]);

        $task = ScheduledTask::create([
            'type' => 'action_list',
            'run_at' => now()->addMinutes(5),
            'executed' => false,
            'active' => true,
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        $first = ScheduledTaskExecution::create([
            'scheduled_task_id' => $task->id,
            'action_list_id' => $actionList->id,
            'action_list_name' => $actionList->name,
            'task_type' => 'action_list',
            'trigger_source' => 'scheduler',
            'status' => 'success',
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinutes(3),
        ]);

        ScheduledTaskExecution::create([
            'scheduled_task_id' => $task->id,
            'action_list_id' => $actionList->id,
            'action_list_name' => $actionList->name,
            'task_type' => 'action_list',
            'trigger_source' => 'scheduler',
            'status' => 'success',
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinutes(2),
        ]);

        ScheduledTaskExecution::create([
            'scheduled_task_id' => $task->id,
            'action_list_id' => $actionList->id,
            'action_list_name' => $actionList->name,
            'task_type' => 'action_list',
            'trigger_source' => 'scheduler',
            'status' => 'success',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]);

        ScheduledTaskExecution::pruneHistory();

        $this->assertDatabaseMissing('scheduled_task_executions', ['id' => $first->id]);
        $this->assertSame(2, ScheduledTaskExecution::count());
    }
}