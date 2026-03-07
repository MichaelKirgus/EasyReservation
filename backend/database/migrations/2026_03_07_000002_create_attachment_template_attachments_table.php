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
        Schema::create('attachment_template_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_template_id')
                ->constrained('attachment_templates')
                ->onDelete('cascade');
            $table->string('original_filename');
            $table->string('stored_filename')->unique();
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('storage_path');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachment_template_attachments');
    }
};
