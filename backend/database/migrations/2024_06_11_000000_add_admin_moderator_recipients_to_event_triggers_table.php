<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('event_triggers', function (Blueprint $table) {
            $table->boolean('recipient_admins')->default(false)->after('recipient_waitlist');
            $table->boolean('recipient_moderators')->default(false)->after('recipient_admins');
        });
    }

    public function down()
    {
        Schema::table('event_triggers', function (Blueprint $table) {
            $table->dropColumn(['recipient_admins', 'recipient_moderators']);
        });
    }
};
