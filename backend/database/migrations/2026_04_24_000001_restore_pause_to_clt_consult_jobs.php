<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE `clt_consult_jobs`
            MODIFY `status` ENUM('pendente','em_progresso','pausado','concluido','falhou','cancelado')
            NOT NULL DEFAULT 'pendente'
        ");

        if (!Schema::hasColumn('clt_consult_jobs', 'paused_at')) {
            Schema::table('clt_consult_jobs', function (Blueprint $table) {
                $table->timestamp('paused_at')->nullable()->after('canceled_at');
            });
        }
    }

    public function down(): void
    {
        DB::table('clt_consult_jobs')
            ->where('status', 'pausado')
            ->update(['status' => 'pendente']);

        DB::statement("
            ALTER TABLE `clt_consult_jobs`
            MODIFY `status` ENUM('pendente','em_progresso','concluido','falhou','cancelado')
            NOT NULL DEFAULT 'pendente'
        ");

        if (Schema::hasColumn('clt_consult_jobs', 'paused_at')) {
            Schema::table('clt_consult_jobs', function (Blueprint $table) {
                $table->dropColumn('paused_at');
            });
        }
    }
};
