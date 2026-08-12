<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('facta_clt_consult_jobs', function (Blueprint $table) {
            $table->string('executor', 10)->default('local')->after('title');
            $table->string('external_job_id')->nullable()->unique()->after('executor');
            $table->boolean('external_has_report')->default(false)->after('external_job_id');
            $table->unsignedInteger('phase2_fail_count')->default(0)->after('phase2_nao_aprovado_count');
        });
    }

    public function down(): void
    {
        Schema::table('facta_clt_consult_jobs', function (Blueprint $table) {
            $table->dropUnique(['external_job_id']);
            $table->dropColumn(['executor', 'external_job_id', 'external_has_report', 'phase2_fail_count']);
        });
    }
};
