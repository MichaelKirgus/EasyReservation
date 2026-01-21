<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('webhook_templates', function (Blueprint $table) {
            $table->string('url', 500)->after('description');
        });
    }

    public function down()
    {
        Schema::table('webhook_templates', function (Blueprint $table) {
            $table->dropColumn('url');
        });
    }
};
