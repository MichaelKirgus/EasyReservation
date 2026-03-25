<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_portability_transport_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('target_base_url', 2048);
            $table->text('target_api_token');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('timeout_seconds')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_portability_transport_profiles');
    }
};
