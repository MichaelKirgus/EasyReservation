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
        Schema::create('mail_transport_accounts', function (Blueprint $table) {
            $table->id();
            
            // Account identification
            $table->string('name');
            
            // SMTP configuration
            $table->string('host')->nullable();
            $table->integer('port')->default(587);
            $table->enum('encryption', ['tls', 'ssl', 'none'])->default('tls');
            
            // Authentication
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // Encrypted password
            
            // Auth method: plain, login, crammd5, oauth2_exchange, oauth2_google, api_key_*
            $table->string('auth_method')->default('plain');
            
            // OAuth2 tokens (encrypted)
            $table->text('oauth2_client_id')->nullable();
            $table->text('oauth2_client_secret')->nullable(); // Encrypted
            $table->text('oauth2_refresh_token')->nullable(); // Encrypted
            $table->text('oauth2_access_token')->nullable(); // Encrypted
            $table->timestamp('oauth2_token_expiry')->nullable();
            
            // TLS options
            $table->boolean('ignore_self_signed')->default(false);
            $table->enum('tls_version', ['auto', '1.2', '1.3'])->default('auto');
            
            // Connection settings
            $table->integer('timeout')->default(30);
            
            // Rate limiting (configurable via config)
            $table->boolean('rate_limit_enabled')->default(false);
            $table->integer('rate_limit_per_minute')->nullable();
            $table->integer('rate_limit_per_hour')->nullable();

            // Retry behavior for this account
            $table->integer('retry_count')->default(3);
            
            // Email headers
            $table->string('from_address')->nullable(); // Send as address
            $table->string('reply_to_address')->nullable();
            $table->string('return_path_address')->nullable(); // Bounce address
            
            // Status
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mail_transport_accounts');
    }
};
