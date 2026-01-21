<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_triggers', function (Blueprint $table) {
            $table->unsignedBigInteger('webhook_template_id')->nullable();
            $table->foreign('webhook_template_id')->references('id')->on('webhook_templates')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('event_triggers', function (Blueprint $table) {
            $table->dropForeign(['webhook_template_id']);
            $table->dropColumn('webhook_template_id');
        });
    }
};
