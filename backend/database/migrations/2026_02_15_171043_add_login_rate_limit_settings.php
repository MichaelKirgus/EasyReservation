<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('settings', function ($table) {
            $table->integer('login_rate_limit_attempts')->default(5)->after('privacy_policy_text');
            $table->integer('login_rate_limit_decay_minutes')->default(1)->after('login_rate_limit_attempts');
        });
    }

    public function down()
    {
        Schema::table('settings', function ($table) {
            $table->dropColumn(['login_rate_limit_attempts', 'login_rate_limit_decay_minutes']);
        });
    }
};
