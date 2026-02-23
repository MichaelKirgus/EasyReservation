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
        Schema::create('mail_group_accounts', function (Blueprint $table) {
            $table->id();
            
            // Foreign keys
            $table->foreignId('group_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained('mail_transport_accounts')->onDelete('cascade');
            
            // Priority for sequential failover (lower = primary)
            $table->integer('priority')->default(0);
            
            // Unique constraint to prevent duplicate account assignments
            $table->unique(['group_id', 'account_id']);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_group_accounts');
    }
};
