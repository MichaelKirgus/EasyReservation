<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('action_lists', function (Blueprint $table) {
            $table->boolean('moderation_selectable')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('action_lists', function (Blueprint $table) {
            $table->dropColumn('moderation_selectable');
        });
    }
};
