<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Add uuid column to events table for consistent iCal UID generation
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        // Set UUID for existing events if not present
        \DB::table('events')
            ->whereNull('uuid')
            ->pluck('id')
            ->each(function ($eventId): void {
                \DB::table('events')
                    ->where('id', $eventId)
                    ->update(['uuid' => (string) Str::uuid()]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
