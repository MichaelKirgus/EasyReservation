<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role')->default('admin');
            });
        }

        if (! Schema::hasColumn('users', 'api_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('api_token', 255)->nullable()->unique();
            });
        }

        if (! Schema::hasColumn('users', 'api_token_is_hashed')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('api_token_is_hashed')->default(false);
            });
        }

        if (! Schema::hasColumn('users', 'active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('active')->default(true);
            });
        }
    }

    public function down(): void
    {
        $columns = array_filter([
            Schema::hasColumn('users', 'role') ? 'role' : null,
            Schema::hasColumn('users', 'api_token') ? 'api_token' : null,
            Schema::hasColumn('users', 'api_token_is_hashed') ? 'api_token_is_hashed' : null,
            Schema::hasColumn('users', 'active') ? 'active' : null,
        ]);

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
