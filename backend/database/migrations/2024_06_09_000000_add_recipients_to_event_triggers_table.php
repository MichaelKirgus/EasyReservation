<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('event_triggers', function (Blueprint $table) {
            $table->boolean('recipient_attendees')->default(false)->after('webhook_url');
            $table->boolean('recipient_waitlist')->default(false)->after('recipient_attendees');
            $table->text('custom_recipients')->nullable()->after('recipient_waitlist');
        });
    }

    public function down()
    {
        Schema::table('event_triggers', function (Blueprint $table) {
            $table->dropColumn(['recipient_attendees', 'recipient_waitlist', 'custom_recipients']);
        });
    }
};
