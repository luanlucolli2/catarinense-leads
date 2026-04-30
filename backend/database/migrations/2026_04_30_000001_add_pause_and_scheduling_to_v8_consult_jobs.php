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
            ALTER TABLE `v8_consult_jobs`
            MODIFY `status` ENUM('agendado','pendente','em_progresso','pausado','concluido','falhou','cancelado')
            NOT NULL DEFAULT 'pendente'
        ");

        Schema::table('v8_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('v8_consult_jobs', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('canceled_at');
            }

            if (!Schema::hasColumn('v8_consult_jobs', 'scheduled_for')) {
                $table->dateTime('scheduled_for')->nullable()->after('paused_at');
            }
        });

        $indexExists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'v8_consult_jobs')
            ->where('INDEX_NAME', 'v8_consult_jobs_status_scheduled_for_index')
            ->exists();

        if (!$indexExists) {
            Schema::table('v8_consult_jobs', function (Blueprint $table) {
                $table->index(['status', 'scheduled_for'], 'v8_consult_jobs_status_scheduled_for_index');
            });
        }
    }

    public function down(): void
    {
        DB::table('v8_consult_jobs')
            ->where('status', 'agendado')
            ->update(['status' => 'pendente']);

        $indexExists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'v8_consult_jobs')
            ->where('INDEX_NAME', 'v8_consult_jobs_status_scheduled_for_index')
            ->exists();

        Schema::table('v8_consult_jobs', function (Blueprint $table) use ($indexExists) {
            if (Schema::hasColumn('v8_consult_jobs', 'scheduled_for')) {
                if ($indexExists) {
                    $table->dropIndex('v8_consult_jobs_status_scheduled_for_index');
                }
                $table->dropColumn('scheduled_for');
            }

            if (Schema::hasColumn('v8_consult_jobs', 'paused_at')) {
                $table->dropColumn('paused_at');
            }
        });

        DB::statement("
            ALTER TABLE `v8_consult_jobs`
            MODIFY `status` ENUM('pendente','em_progresso','pausado','concluido','falhou','cancelado')
            NOT NULL DEFAULT 'pendente'
        ");
    }
};
