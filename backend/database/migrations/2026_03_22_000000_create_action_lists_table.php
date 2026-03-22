<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('action_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('action_list_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_list_id')->constrained()->onDelete('cascade');
            $table->string('type'); // email, webhook, change_setting
            $table->json('config')->nullable(); // Configuration data for the action
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_list_actions');
        Schema::dropIfExists('action_lists');
    }
};
