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
            // Add attachment_template_id column to link specific attachment templates to email templates
            $table->foreignId('attachment_template_id')->nullable()
                ->after('transport_group_id')
                ->constrained('attachment_templates')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropForeign(['attachment_template_id']);
            $table->dropColumn('attachment_template_id');
        });
    }
};
