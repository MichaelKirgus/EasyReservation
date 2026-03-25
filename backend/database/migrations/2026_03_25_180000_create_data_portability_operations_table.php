<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_portability_operations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('status', 20)->default('queued');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('restore_mode', 30)->nullable();
            $table->string('source_file_path')->nullable();
            $table->string('result_file_path')->nullable();
            $table->json('selected_tables')->nullable();
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['type', 'status'], 'dp_ops_type_status_idx');
            $table->index(['requested_by_user_id', 'created_at'], 'dp_ops_user_created_idx');
            $table->index('created_at', 'dp_ops_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_portability_operations');
    }
};
