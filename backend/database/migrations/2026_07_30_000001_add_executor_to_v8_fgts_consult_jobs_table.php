<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v8_fgts_consult_jobs', function (Blueprint $table) {
            $table->string('executor', 10)->default('local')->after('title');
            $table->string('external_job_id')->nullable()->unique()->after('executor');
            $table->boolean('external_has_report')->default(false)->after('external_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('v8_fgts_consult_jobs', function (Blueprint $table) {
            $table->dropUnique(['external_job_id']);
            $table->dropColumn(['executor', 'external_job_id', 'external_has_report']);
        });
    }
};
