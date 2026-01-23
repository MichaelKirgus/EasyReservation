<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('email_validations', function (Blueprint $table) {
            $table->string('site_token', 255)->nullable()->after('waitlist_entry_id');
        });
    }

    public function down()
    {
        Schema::table('email_validations', function (Blueprint $table) {
            $table->dropColumn('site_token');
        });
    }
};
