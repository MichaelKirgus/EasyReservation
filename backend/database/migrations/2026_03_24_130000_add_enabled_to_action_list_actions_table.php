<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('action_list_actions', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('action_list_actions', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }
};