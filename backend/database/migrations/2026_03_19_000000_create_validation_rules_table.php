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
        Schema::create('validation_rules', function (Blueprint $table) {
            $table->id();
            
            // Rule identification
            $table->string('name');
            $table->text('description')->nullable();
            
            // Rule status
            $table->boolean('active')->default(true);
            
            // Field reference (name, email, or form_fields.key)
            $table->string('field_key');
            
            // Condition configuration
            $table->enum('condition_type', ['length', 'regex', 'unicode_category', 'contains', 'email_format']);
            $table->string('condition_operator'); // <, >, =, <=, >=, match, not_match, contains, not_contains
            $table->text('condition_value');
            
            // User feedback
            $table->text('error_message')->nullable();
            
            // Optional webhook trigger
            $table->foreignId('webhook_template_id')
                ->nullable()
                ->constrained('webhook_templates')
                ->onDelete('set null');
            
            // Ordering
            $table->integer('sort_order')->default(0);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validation_rules');
    }
};
