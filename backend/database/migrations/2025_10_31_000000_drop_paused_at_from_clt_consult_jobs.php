<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Normaliza possíveis registros com status "pausado"
        try {
            DB::table('clt_consult_jobs')
                ->where('status', 'pausado')
                ->update(['status' => 'pendente']);
        } catch (\Throwable $e) {
            // segue sem impedir a migração
        }

        if (Schema::hasColumn('clt_consult_jobs', 'paused_at')) {
            Schema::table('clt_consult_jobs', function (Blueprint $table) {
                $table->dropColumn('paused_at');
            });
        }
    }

    public function down(): void
    {
        // Recria coluna opcionalmente, sem restaurar dados
        if (!Schema::hasColumn('clt_consult_jobs', 'paused_at')) {
            Schema::table('clt_consult_jobs', function (Blueprint $table) {
                $table->timestamp('paused_at')->nullable()->after('finished_at');
            });
        }
    }
};
