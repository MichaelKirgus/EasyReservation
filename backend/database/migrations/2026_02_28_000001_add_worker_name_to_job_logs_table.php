<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_logs', function (Blueprint $table) {
            $table->string('worker_name')->nullable()->after('queue');
        });
    }

    public function down(): void
    {
        Schema::table('job_logs', function (Blueprint $table) {
            $table->dropColumn('worker_name');
        });
    }
};
