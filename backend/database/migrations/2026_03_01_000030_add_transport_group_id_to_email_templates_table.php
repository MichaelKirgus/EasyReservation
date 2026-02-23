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
        Schema::table('email_templates', function (Blueprint $table) {
            // Add transport_group_id column to email_templates table
            $table->foreignId('transport_group_id')->nullable()
                ->constrained('mail_transport_groups')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropForeign(['transport_group_id']);
            $table->dropColumn('transport_group_id');
        });
    }
};
