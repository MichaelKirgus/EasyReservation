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
        Schema::create('email_blacklist_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique()->comment('Domain to blacklist for email sending');
            $table->boolean('active')->default(true)->comment('Whether this blacklist entry is active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_blacklist_domains');
    }
};
