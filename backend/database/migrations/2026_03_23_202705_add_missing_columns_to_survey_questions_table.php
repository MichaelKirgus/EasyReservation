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
        if (! Schema::hasColumn('survey_questions', 'global_question_id')) {
            Schema::table('survey_questions', function (Blueprint $table) {
                $table->foreignId('global_question_id')->nullable()->constrained('global_questions')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('survey_questions', 'active')) {
            Schema::table('survey_questions', function (Blueprint $table) {
                $table->boolean('active')->default(true);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            //
        });
    }
};
