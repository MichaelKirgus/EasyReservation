<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('survey_id')->index();
            $table->text('question_text');
            $table->string('field_type')->default('text_open'); // score_1_5, text_open, text_multiple_choice
            $table->boolean('is_required')->default(false);
            $table->integer('display_order')->default(0);
            $table->json('options')->nullable(); // for multiple choice options
            $table->timestamps();

            $table->foreign('survey_id')
                ->references('id')
                ->on('surveys')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_questions');
    }
};
