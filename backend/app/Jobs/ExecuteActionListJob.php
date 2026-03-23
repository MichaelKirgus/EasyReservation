<?php

namespace App\Jobs;

use App\Models\ActionList;
use App\Services\ActionListService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteActionListJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $actionListId;

    public array $context;

    public function __construct(int $actionListId, array $context = [])
    {
        $this->actionListId = $actionListId;
        $this->context = $context;
    }

    public function handle(ActionListService $service): void
    {
        \Log::info('ExecuteActionListJob: Starting job for action list ID ' . $this->actionListId);

        try {
            $actionList = ActionList::find($this->actionListId);

            if (!$actionList) {
                \Log::error('ExecuteActionListJob: Action list with ID ' . $this->actionListId . ' not found');
                throw new \RuntimeException('Action list with ID ' . $this->actionListId . ' not found');
            }

            \Log::info('ExecuteActionListJob: Found action list ' . $actionList->id . ', executing via ActionListService');

            // Execute the action list
            $service->executeActionList($this->actionListId, $this->context);

            \Log::info('ExecuteActionListJob: Action list ' . $this->actionListId . ' completed successfully');
        } catch (\Throwable $e) {
            \Log::error('ExecuteActionListJob: Error executing action list ' . $this->actionListId . ': ' . $e->getMessage());
            \Log::error('Stack trace:', ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }
}
