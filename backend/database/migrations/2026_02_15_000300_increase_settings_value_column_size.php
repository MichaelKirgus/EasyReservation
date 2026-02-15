<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Increase the value column size to accommodate encrypted data
            // Encrypted strings can be significantly longer than plain text
            $table->text('value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Revert to original size
            $table->string('value', 2048)->nullable()->change();
        });
    }
};
