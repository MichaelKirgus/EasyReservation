<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('archive_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('original_reservation_id')->comment('Original reservation ID for reference');
            $table->string('display_name');
            $table->string('email')->nullable()->comment('Encrypted if original was encrypted');
            $table->json('payload')->nullable();
            $table->timestamp('date_added');
            $table->string('site_token')->nullable();
            $table->boolean('email_encrypted')->default(false);
            $table->timestamps();

            $table->index('archive_id', 'idx_archive_id');
            $table->index('original_reservation_id', 'idx_original_reservation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_reservations');
    }
};
