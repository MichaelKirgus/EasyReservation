<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('workerstatus.database.connection');
        $table      = config('workerstatus.database.table', 'worker_statuses');

        Schema::connection($connection)->create($table, function (Blueprint $t) {
            $t->id();
            $t->string('worker_id')->unique();
            $t->string('hostname')->nullable();
            $t->unsignedInteger('pid')->nullable();
            $t->string('ip')->nullable();
            $t->unsignedBigInteger('memory_bytes')->default(0);
            $t->float('redis_latency_ms')->nullable();
            $t->float('db_latency_ms')->nullable();
            $t->unsignedBigInteger('total_jobs')->default(0);
            $t->timestamp('last_job_at')->nullable();
            $t->float('last_job_duration_ms')->nullable();
            $t->json('active_jobs')->nullable();
            $t->timestamp('last_heartbeat_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        $connection = config('workerstatus.database.connection');
        $table      = config('workerstatus.database.table', 'worker_statuses');

        Schema::connection($connection)->dropIfExists($table);
    }
};
