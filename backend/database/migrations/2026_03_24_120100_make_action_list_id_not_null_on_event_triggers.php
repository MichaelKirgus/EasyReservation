<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('event_triggers') || !Schema::hasColumn('event_triggers', 'action_list_id')) {
            return;
        }

        $nullCount = DB::table('event_triggers')->whereNull('action_list_id')->count();
        if ($nullCount > 0) {
            throw new RuntimeException(
                "Refusing to harden event_triggers.action_list_id to NOT NULL: {$nullCount} rows still have NULL action_list_id. Backfill first."
            );
        }

        $orphanCount = DB::table('event_triggers as et')
            ->leftJoin('action_lists as al', 'al.id', '=', 'et.action_list_id')
            ->whereNull('al.id')
            ->count();

        if ($orphanCount > 0) {
            throw new RuntimeException(
                "Refusing to harden event_triggers.action_list_id to NOT NULL: {$orphanCount} orphaned action_list_id references found."
            );
        }

        Schema::table('event_triggers', function (Blueprint $table) {
            $table->unsignedBigInteger('action_list_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('event_triggers') || !Schema::hasColumn('event_triggers', 'action_list_id')) {
            return;
        }

        Schema::table('event_triggers', function (Blueprint $table) {
            $table->unsignedBigInteger('action_list_id')->nullable()->change();
        });
    }
};
