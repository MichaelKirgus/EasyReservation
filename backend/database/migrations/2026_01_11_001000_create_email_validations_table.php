<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_validations', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // reservation | waitlist
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->json('payload')->nullable();
            $table->string('token')->nullable()->index();
            $table->string('status')->default('pending');
            $table->boolean('requires_admin_approval')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('waitlist_entry_id')->nullable();
            $table->string('site_token', 255)->nullable(); // hinzugefügt
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['reservation_id', 'waitlist_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_validations');
    }
};
