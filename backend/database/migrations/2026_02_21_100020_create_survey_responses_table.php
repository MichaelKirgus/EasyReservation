<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('survey_id')->index();
            $table->unsignedBigInteger('question_id')->nullable()->index();
            $table->string('responder_token'); // unique token per user (reservation email hash or anonymous token)
            $table->text('response_text')->nullable(); // for open text questions
            $table->integer('response_score')->nullable(); // for score 1-5
            $table->boolean('is_responded')->default(false); // flag to prevent multiple votes
            $table->timestamps();

            $table->foreign('survey_id')
                ->references('id')
                ->on('surveys')
                ->onDelete('cascade');

            $table->foreign('question_id')
                ->references('id')
                ->on('survey_questions')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
