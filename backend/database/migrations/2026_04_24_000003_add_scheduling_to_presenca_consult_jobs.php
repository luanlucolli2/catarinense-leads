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
            ALTER TABLE `presenca_consult_jobs`
            MODIFY `status` ENUM('agendado','pendente','em_progresso','pausado','concluido','falhou','cancelado')
            NOT NULL DEFAULT 'pendente'
        ");

        Schema::table('presenca_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('presenca_consult_jobs', 'scheduled_for')) {
                $table->dateTime('scheduled_for')->nullable()->after('paused_at');
            }
        });

        $indexExists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'presenca_consult_jobs')
            ->where('INDEX_NAME', 'presenca_consult_jobs_status_scheduled_for_index')
            ->exists();

        if (!$indexExists) {
            Schema::table('presenca_consult_jobs', function (Blueprint $table) {
                $table->index(['status', 'scheduled_for'], 'presenca_consult_jobs_status_scheduled_for_index');
            });
        }
    }

    public function down(): void
    {
        DB::table('presenca_consult_jobs')
            ->where('status', 'agendado')
            ->update(['status' => 'pendente']);

        $indexExists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'presenca_consult_jobs')
            ->where('INDEX_NAME', 'presenca_consult_jobs_status_scheduled_for_index')
            ->exists();

        Schema::table('presenca_consult_jobs', function (Blueprint $table) use ($indexExists) {
            if (Schema::hasColumn('presenca_consult_jobs', 'scheduled_for')) {
                if ($indexExists) {
                    $table->dropIndex('presenca_consult_jobs_status_scheduled_for_index');
                }
                $table->dropColumn('scheduled_for');
            }
        });

        DB::statement("
            ALTER TABLE `presenca_consult_jobs`
            MODIFY `status` ENUM('pendente','em_progresso','pausado','concluido','falhou','cancelado')
            NOT NULL DEFAULT 'pendente'
        ");
    }
};
