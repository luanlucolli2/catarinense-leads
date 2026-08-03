<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v8_consult_jobs', function (Blueprint $table) {
            $table->string('executor', 10)->default('local')->after('title');
            $table->string('external_job_id')->nullable()->unique()->after('executor');
            $table->boolean('external_has_report')->default(false)->after('external_job_id');
            $table->unsignedInteger('phase1_submitted_count')->default(0)->after('external_has_report');
            $table->unsignedInteger('phase1_not_eligible_count')->default(0)->after('phase1_submitted_count');
            $table->unsignedInteger('phase1_errors_count')->default(0)->after('phase1_not_eligible_count');
            $table->unsignedInteger('phase2_approved_count')->default(0)->after('phase1_errors_count');
            $table->unsignedInteger('phase2_not_approved_count')->default(0)->after('phase2_approved_count');
            $table->unsignedInteger('phase2_errors_count')->default(0)->after('phase2_not_approved_count');
        });
    }

    public function down(): void
    {
        Schema::table('v8_consult_jobs', function (Blueprint $table) {
            $table->dropUnique(['external_job_id']);
            $table->dropColumn([
                'executor',
                'external_job_id',
                'external_has_report',
                'phase1_submitted_count',
                'phase1_not_eligible_count',
                'phase1_errors_count',
                'phase2_approved_count',
                'phase2_not_approved_count',
                'phase2_errors_count',
            ]);
        });
    }
};
