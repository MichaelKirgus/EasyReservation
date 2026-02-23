<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mail_transport_groups', function (Blueprint $table) {
            $table->id();
            
            // Group identification
            $table->string('name');
            $table->text('description')->nullable();
            
            // Rate limiting configuration
            $table->boolean('rate_limit_enabled')->default(false);
            $table->integer('rate_limit_per_minute')->nullable();
            $table->integer('rate_limit_per_hour')->nullable();
            
            // Failover strategy: sequential, round_robin, random
            $table->enum('failover_strategy', ['sequential', 'round_robin', 'random'])->default('sequential');
            
            // Retry settings
            $table->integer('max_retries_per_account')->default(3);
            
            // Status
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_transport_groups');
    }
};
