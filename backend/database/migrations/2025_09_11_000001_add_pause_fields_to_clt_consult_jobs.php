<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1) Coluna paused_at (se ainda não existir)
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('clt_consult_jobs', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('canceled_at');
            }
        });

        // 2) Índice em status (cria só se não existir — sem Doctrine)
        $idxExists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'clt_consult_jobs')
            ->where('INDEX_NAME', 'clt_consult_jobs_status_index')
            ->exists();

        if (!$idxExists) {
            Schema::table('clt_consult_jobs', function (Blueprint $table) {
                $table->index('status', 'clt_consult_jobs_status_index');
            });
        }
    }

    public function down(): void
    {
        // Remover índice, se existir — sem Doctrine
        $idxExists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'clt_consult_jobs')
            ->where('INDEX_NAME', 'clt_consult_jobs_status_index')
            ->exists();

        if ($idxExists) {
            Schema::table('clt_consult_jobs', function (Blueprint $table) {
                $table->dropIndex('clt_consult_jobs_status_index');
            });
        }

        // Remover coluna paused_at, se existir
        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('clt_consult_jobs', 'paused_at')) {
                $table->dropColumn('paused_at');
            }
        });
    }
};
