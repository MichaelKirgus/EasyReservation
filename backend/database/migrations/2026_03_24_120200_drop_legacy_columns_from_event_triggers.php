<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('event_triggers')) {
            return;
        }

        Schema::table('event_triggers', function (Blueprint $table) {
            if (Schema::hasColumn('event_triggers', 'webhook_template_id')) {
                // Guarded: foreign key may or may not exist depending on historical schema state.
                try {
                    $table->dropForeign(['webhook_template_id']);
                } catch (Throwable $e) {
                    // no-op
                }
            }

            $columnsToDrop = [];
            foreach ([
                'action_type',
                'template_id',
                'webhook_url',
                'recipient_attendees',
                'recipient_waitlist',
                'recipient_admins',
                'recipient_moderators',
                'custom_recipients',
                'webhook_template_id',
                'meta',
            ] as $column) {
                if (Schema::hasColumn('event_triggers', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('event_triggers')) {
            return;
        }

        Schema::table('event_triggers', function (Blueprint $table) {
            if (!Schema::hasColumn('event_triggers', 'action_type')) {
                $table->string('action_type')->nullable();
            }
            if (!Schema::hasColumn('event_triggers', 'template_id')) {
                $table->unsignedBigInteger('template_id')->nullable();
            }
            if (!Schema::hasColumn('event_triggers', 'webhook_url')) {
                $table->text('webhook_url')->nullable();
            }
            if (!Schema::hasColumn('event_triggers', 'recipient_attendees')) {
                $table->boolean('recipient_attendees')->default(false);
            }
            if (!Schema::hasColumn('event_triggers', 'recipient_waitlist')) {
                $table->boolean('recipient_waitlist')->default(false);
            }
            if (!Schema::hasColumn('event_triggers', 'recipient_admins')) {
                $table->boolean('recipient_admins')->default(false);
            }
            if (!Schema::hasColumn('event_triggers', 'recipient_moderators')) {
                $table->boolean('recipient_moderators')->default(false);
            }
            if (!Schema::hasColumn('event_triggers', 'custom_recipients')) {
                $table->text('custom_recipients')->nullable();
            }
            if (!Schema::hasColumn('event_triggers', 'webhook_template_id')) {
                $table->unsignedBigInteger('webhook_template_id')->nullable();
            }
            if (!Schema::hasColumn('event_triggers', 'meta')) {
                $table->json('meta')->nullable();
            }
        });

        Schema::table('event_triggers', function (Blueprint $table) {
            if (Schema::hasColumn('event_triggers', 'webhook_template_id')) {
                try {
                    $table->foreign('webhook_template_id')->references('id')->on('webhook_templates')->nullOnDelete();
                } catch (Throwable $e) {
                    // no-op
                }
            }
        });
    }
};
