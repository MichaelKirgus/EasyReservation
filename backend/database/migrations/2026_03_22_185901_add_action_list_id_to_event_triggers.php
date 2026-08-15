<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('event_triggers', 'action_list_id')) {
            return;
        }

        Schema::table('event_triggers', function (Blueprint $table) {
            $table->foreignId('action_list_id')->nullable()->constrained()->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('event_triggers', function (Blueprint $table) {
            $table->dropForeign(['action_list_id']);
            $table->dropColumn('action_list_id');
        });
    }
};
