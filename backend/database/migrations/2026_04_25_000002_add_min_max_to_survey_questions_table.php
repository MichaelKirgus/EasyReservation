<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->integer('min_value')->nullable()->after('options');
            $table->integer('max_value')->nullable()->after('options');
        });

        Schema::table('global_questions', function (Blueprint $table) {
            $table->integer('min_value')->nullable()->after('options');
            $table->integer('max_value')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->dropColumn(['min_value', 'max_value']);
        });

        Schema::table('global_questions', function (Blueprint $table) {
            $table->dropColumn(['min_value', 'max_value']);
        });
    }
};
