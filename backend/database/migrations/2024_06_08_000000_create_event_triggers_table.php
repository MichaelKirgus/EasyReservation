<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('event_triggers', function (Blueprint $table) {
            $table->id();
            $table->string('event_type'); // z.B. reservierungsliste_voll
            $table->string('action_type'); // email, webhook
            $table->unsignedBigInteger('template_id')->nullable(); // für E-Mail
            $table->text('webhook_url')->nullable(); // für Webhook
            $table->integer('delay_seconds')->default(0);
            $table->integer('cooldown_seconds')->default(0);
            $table->timestamp('last_triggered_at')->nullable();
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable(); // für weitere Daten
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('event_triggers');
    }
};
