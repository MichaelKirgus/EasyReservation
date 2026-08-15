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
        if (! Schema::hasTable('survey_questions')) {
            Schema::create('survey_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('survey_id')->constrained()->onDelete('cascade');
                $table->foreignId('global_question_id')->nullable()->constrained('global_questions')->nullOnDelete();
                $table->string('question_text');
                $table->enum('field_type', ['score_1_5', 'text_open', 'text_multiple_choice'])->default('text_open');
                $table->boolean('is_required')->default(false);
                $table->integer('display_order')->default(0);
                $table->json('options')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        } else {
            if (! Schema::hasColumn('survey_questions', 'global_question_id')) {
                Schema::table('survey_questions', function (Blueprint $table) {
                    $table->unsignedBigInteger('global_question_id')->nullable();
                });
            }

            if (! Schema::hasColumn('survey_questions', 'active')) {
                Schema::table('survey_questions', function (Blueprint $table) {
                    $table->boolean('active')->default(true);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_questions');
    }
};
