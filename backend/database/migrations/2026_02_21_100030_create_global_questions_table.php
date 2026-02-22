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
        Schema::create('global_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question_text');
            $table->enum('field_type', ['score_1_5', 'text_open', 'text_multiple_choice'])->default('text_open');
            $table->boolean('is_required')->default(false);
            $table->integer('display_order')->default(0);
            $table->json('options')->nullable(); // For multiple choice options
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('global_questions');
    }
};
