<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_logs', function (Blueprint $table) {
            $table->id();
            $table->string('job');
            $table->string('queue')->nullable();
            $table->string('status', 50);
            $table->unsignedBigInteger('runtime_ms')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['finished_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_logs');
    }
};
