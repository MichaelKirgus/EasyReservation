<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            // When true, deactivate the task after a successful execution
            $table->boolean('run_once')->default(false)->after('active');

            // When true, skip execution if the scheduled time is already past (overdue)
            $table->boolean('skip_if_overdue')->default(false)->after('run_once');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->dropColumn(['run_once', 'skip_if_overdue']);
        });
    }
};
