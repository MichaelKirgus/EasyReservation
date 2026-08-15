<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('scheduled_tasks', 'action_list_id')) {
            return;
        }

        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->foreignId('action_list_id')->nullable()->constrained()->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->dropForeign(['action_list_id']);
            $table->dropColumn('action_list_id');
        });
    }
};
