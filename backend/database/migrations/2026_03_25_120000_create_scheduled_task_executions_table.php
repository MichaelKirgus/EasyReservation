<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_task_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_task_id')->nullable()->constrained('scheduled_tasks')->nullOnDelete();
            $table->foreignId('action_list_id')->nullable()->constrained('action_lists')->nullOnDelete();
            $table->string('action_list_name')->nullable();
            $table->string('task_type')->nullable();
            $table->string('trigger_source', 20);
            $table->string('status', 20);
            $table->timestamp('planned_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['trigger_source', 'finished_at']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_executions');
    }
};