<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('archives', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('Unique name for the archive');
            $table->text('description')->nullable()->comment('Optional description of what this archive contains');
            $table->boolean('store_emails')->default(true)->comment('Whether email addresses should be stored (can override per entry)');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archives');
    }
};
