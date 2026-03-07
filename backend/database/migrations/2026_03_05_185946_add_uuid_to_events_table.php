<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            
            // Set UUID for existing events if not present
            \DB::table('events')
                ->whereNull('uuid')
                ->update(['uuid' => \DB::raw("UUID()")]);
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
