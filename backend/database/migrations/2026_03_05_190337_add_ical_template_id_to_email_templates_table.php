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
            // Add ical_template_id column to link specific iCal templates to email templates
            $table->foreignId('ical_template_id')->nullable()
                ->after('transport_group_id')
                ->constrained('ical_templates')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropForeign(['ical_template_id']);
            $table->dropColumn('ical_template_id');
        });
    }
};
