<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            // Cron expression (e.g., "0 * * * *" for every hour)
            $table->string('cron_expression')->nullable()->after('relative_offset_minutes');
            
            // Next scheduled execution time (calculated from cron)
            $table->timestamp('next_run_at')->nullable()->after('cron_expression');
            
            // Last execution time
            $table->timestamp('last_run_at')->nullable()->after('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->dropColumn(['cron_expression', 'next_run_at', 'last_run_at']);
        });
    }
};
