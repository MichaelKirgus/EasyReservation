<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('lang', 10);
            $table->string('name');
            $table->string('value', 1024);
            $table->index('lang');
            $table->unique(['lang', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
