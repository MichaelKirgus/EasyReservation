<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->json('payload')->nullable();
            $table->string('site_token', 255)->nullable();
            $table->timestamp('date_added')->useCurrent();
            $table->timestamps();
            $table->index('display_name');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
