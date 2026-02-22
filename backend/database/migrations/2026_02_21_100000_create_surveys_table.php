<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('event_id')->nullable()->index();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('response_token_type')->default('anonymous'); // anonymous, reservation_email, user_account
            $table->integer('max_responses_per_user')->default(1);
            $table->timestamps();

            $table->foreign('event_id')
                ->references('id')
                ->on('events')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
