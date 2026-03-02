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
        Schema::table('mail_transport_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('mail_transport_accounts', 'retry_count')) {
                $table->integer('retry_count')->default(3)->after('rate_limit_per_hour');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mail_transport_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('mail_transport_accounts', 'retry_count')) {
                $table->dropColumn('retry_count');
            }
        });
    }
};
