<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->timestamp('run_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_tasks', function (Blueprint $table) {
            $table->timestamp('run_at')->nullable(false)->change();
        });
    }
};
