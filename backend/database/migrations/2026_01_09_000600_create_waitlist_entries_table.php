<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->json('payload')->nullable();
            $table->string('site_token', 255)->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('date_added')->useCurrent();
            $table->timestamps();

            $table->index('status');
            $table->index('date_added');
            $table->index('display_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
