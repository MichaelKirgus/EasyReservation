<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('validation_rules')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `validation_rules` MODIFY `condition_type` ENUM('length', 'regex', 'unicode_category', 'contains', 'email_format') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('validation_rules')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `validation_rules` MODIFY `condition_type` ENUM('length', 'regex', 'unicode_category', 'contains') NOT NULL");
    }
};