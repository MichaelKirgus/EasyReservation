<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('custom_placeholders', function (Blueprint $table) {
            // Add type column as enum-like string field
            $table->string('type')->default('generic')->after('value');
            
            // Add is_encrypted boolean for tracking encryption status
            $table->boolean('is_encrypted')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('custom_placeholders', function (Blueprint $table) {
            $table->dropColumn(['type', 'is_encrypted']);
        });
    }
};
