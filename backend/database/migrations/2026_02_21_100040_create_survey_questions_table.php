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
        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->onDelete('cascade');
            $table->foreignId('global_question_id')->nullable()->constrained('global_questions')->nullOnDelete();
            $table->string('question_text'); // Copy of question text for historical purposes
            $table->enum('field_type', ['score_1_5', 'text_open', 'text_multiple_choice'])->default('text_open');
            $table->boolean('is_required')->default(false);
            $table->integer('display_order')->default(0);
            $table->json('options')->nullable(); // For multiple choice options
            $table->boolean('active')->default(true); // Can be disabled per survey
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_questions');
    }
};
