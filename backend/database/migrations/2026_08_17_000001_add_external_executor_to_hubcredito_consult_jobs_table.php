<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hubcredito_consult_jobs', function (Blueprint $table) {
            $table->string('executor', 10)->default('local')->after('title');
            $table->string('external_job_id')->nullable()->unique()->after('executor');
            $table->boolean('external_has_report')->default(false)->after('external_job_id');
            $table->timestamp('scheduled_for')->nullable()->after('cancel_reason');
            $table->timestamp('paused_at')->nullable()->after('scheduled_for');
            $table->unsignedInteger('phase1_submitted_count')->default(0)->after('total_cpfs');
            $table->unsignedInteger('phase1_not_approved_count')->default(0)->after('phase1_submitted_count');
            $table->unsignedInteger('phase1_fail_count')->default(0)->after('phase1_not_approved_count');
            $table->unsignedInteger('phase2_approved_count')->default(0)->after('phase1_fail_count');
            $table->unsignedInteger('phase2_not_approved_count')->default(0)->after('phase2_approved_count');
            $table->unsignedInteger('phase2_fail_count')->default(0)->after('phase2_not_approved_count');
        });
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE hubcredito_consult_jobs MODIFY status ENUM('agendado','pendente','em_progresso','pausado','concluido','falhou','cancelado') NOT NULL DEFAULT 'pendente'");
    }
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE hubcredito_consult_jobs MODIFY status ENUM('pendente','em_progresso','concluido','falhou','cancelado') NOT NULL DEFAULT 'pendente'");
        Schema::table('hubcredito_consult_jobs', function (Blueprint $table) {
            $table->dropUnique(['external_job_id']);
            $table->dropColumn(['executor', 'external_job_id', 'external_has_report', 'scheduled_for', 'paused_at', 'phase1_submitted_count', 'phase1_not_approved_count', 'phase1_fail_count', 'phase2_approved_count', 'phase2_not_approved_count', 'phase2_fail_count']);
        });
    }
};
