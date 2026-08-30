<?php

namespace Tests\Feature;

use App\Models\ActionList;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledTaskLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function admin()
    {
        return $this->createApiUser('admin');
    }

    public function test_deactivate_endpoint_deactivates_task(): void
    {
        $task = ScheduledTask::factory()->create();

        $response = $this->withHeaders($this->apiHeaders($this->admin()))
            ->patchJson('/api/admin/scheduled-tasks/'.$task->id.'/deactivate');

        $response->assertOk()->assertJsonPath('active', false);
        $this->assertFalse((bool) $task->refresh()->active);
    }

    public function test_activate_endpoint_activates_task(): void
    {
        $task = ScheduledTask::factory()->inactive()->create();

        $response = $this->withHeaders($this->apiHeaders($this->admin()))
            ->patchJson('/api/admin/scheduled-tasks/'.$task->id.'/activate');

        $response->assertOk()->assertJsonPath('active', true);
        $this->assertTrue((bool) $task->refresh()->active);
    }

    public function test_clone_endpoint_creates_unexecuted_copy(): void
    {
        $task = ScheduledTask::factory()->create([
            'run_at' => now()->addDays(2),
            'executed' => true,
            'executed_at' => now()->subDay(),
        ]);

        $response = $this->withHeaders($this->apiHeaders($this->admin()))
            ->postJson('/api/admin/scheduled-tasks/'.$task->id.'/clone');

        $response->assertCreated();

        $cloned = ScheduledTask::find($response->json('id'));
        $this->assertNotNull($cloned);
        $this->assertNotSame($task->id, $cloned->id);
        $this->assertFalse((bool) $cloned->executed);
        $this->assertNull($cloned->executed_at);
        $this->assertTrue($task->run_at->eq($cloned->run_at));
    }

    public function test_due_task_is_executed_by_scheduler_command(): void
    {
        $actionList = ActionList::factory()->create();
        $task = ScheduledTask::factory()->due()->create([
            'type' => 'action_list',
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        $this->artisan('scheduled-tasks:run')->assertExitCode(0);

        $task->refresh();
        $this->assertTrue((bool) $task->executed);
        $this->assertNotNull($task->executed_at);

        $this->assertDatabaseHas('scheduled_task_executions', [
            'scheduled_task_id' => $task->id,
            'status' => 'success',
            'trigger_source' => 'scheduler',
        ]);
    }

    public function test_not_due_task_is_skipped(): void
    {
        $actionList = ActionList::factory()->create();
        $task = ScheduledTask::factory()->create([
            'run_at' => now()->addHour(),
            'type' => 'action_list',
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        $this->artisan('scheduled-tasks:run')->assertExitCode(0);

        $this->assertFalse((bool) $task->refresh()->executed);
        $this->assertSame(0, ScheduledTaskExecution::query()->where('scheduled_task_id', $task->id)->count());
    }

    public function test_inactive_task_is_skipped_even_when_due(): void
    {
        $actionList = ActionList::factory()->create();
        $task = ScheduledTask::factory()->due()->inactive()->create([
            'type' => 'action_list',
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        $this->artisan('scheduled-tasks:run')->assertExitCode(0);

        $this->assertFalse((bool) $task->refresh()->executed);
    }

    public function test_failing_task_records_failure_history(): void
    {
        // A webhook task without a template throws during execution.
        $task = ScheduledTask::factory()->due()->create([
            'type' => 'webhook',
            'options' => ['webhook_template_id' => null],
        ]);

        $this->artisan('scheduled-tasks:run')->assertExitCode(0);

        $this->assertFalse((bool) $task->refresh()->executed, 'Failed tasks must stay runnable.');

        $execution = ScheduledTaskExecution::query()
            ->where('scheduled_task_id', $task->id)
            ->first();

        $this->assertNotNull($execution);
        $this->assertSame('failed', $execution->status);
        $this->assertStringContainsString('webhook_template_id', (string) $execution->error_message);
    }

    public function test_run_now_executes_without_consuming_scheduled_run(): void
    {
        $actionList = ActionList::factory()->create();
        $task = ScheduledTask::factory()->create([
            'run_at' => now()->addDay(),
            'type' => 'action_list',
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        $response = $this->withHeaders($this->apiHeaders($this->admin()))
            ->postJson('/api/admin/scheduled-tasks/'.$task->id.'/run-now');

        $response->assertOk()->assertJsonPath('success', true);

        // The job ran inline (sync queue) and logged a manual execution…
        $this->assertDatabaseHas('scheduled_task_executions', [
            'scheduled_task_id' => $task->id,
            'status' => 'success',
            'trigger_source' => 'manual',
        ]);

        // …but the scheduled run itself is not consumed.
        $this->assertFalse((bool) $task->refresh()->executed);
    }

    public function test_run_once_task_is_deactivated_after_execution(): void
    {
        $actionList = ActionList::factory()->create();
        $task = ScheduledTask::factory()->due()->create([
            'run_at' => now()->subMinute(),
            'run_once' => true,
            'type' => 'action_list',
            'action_list_id' => $actionList->id,
            'options' => ['action_list_id' => $actionList->id],
        ]);

        $this->artisan('scheduled-tasks:run')->assertExitCode(0);

        $task->refresh();
        $this->assertTrue((bool) $task->executed);
        $this->assertFalse((bool) $task->active, 'run_once tasks must be deactivated after execution.');
    }

    public function test_index_returns_tasks_and_next_run_at(): void
    {
        ScheduledTask::factory()->create(['run_at' => now()->addHour()]);
        ScheduledTask::factory()->create(['run_at' => now()->addDays(3)]);

        $response = $this->withHeaders($this->apiHeaders($this->admin()))
            ->getJson('/api/admin/scheduled-tasks');

        $response->assertOk()
            ->assertJsonCount(2, 'tasks')
            ->assertJsonStructure(['tasks', 'next_run_at']);

        $this->assertNotNull($response->json('next_run_at'));
    }

    public function test_store_requires_action_list(): void
    {
        $response = $this->withHeaders($this->apiHeaders($this->admin()))
            ->postJson('/api/admin/scheduled-tasks', [
                'run_at' => now()->addHour()->toIso8601String(),
            ]);

        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_cron_expression(): void
    {
        $actionList = ActionList::factory()->create();

        $response = $this->withHeaders($this->apiHeaders($this->admin()))
            ->postJson('/api/admin/scheduled-tasks', [
                'cron_expression' => 'not a cron',
                'action_list_id' => $actionList->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_cron_next_run_endpoint_returns_upcoming_runs(): void
    {
        $response = $this->withHeaders($this->apiHeaders($this->admin()))
            ->postJson('/api/admin/cron/next-run', [
                'expression' => '*/5 * * * *',
            ]);

        $response->assertOk()
            ->assertJsonPath('expression', '*/5 * * * *')
            ->assertJsonCount(5, 'next_runs');
    }
}
