<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    public function up(): void
    {
        if (!$this->hasIndex('leads', 'leads_updated_at_id_idx')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->index(['updated_at', 'id'], 'leads_updated_at_id_idx');
            });
        }

        if (
            Schema::hasColumn('clt_snapshots', 'consulted_at')
            && !$this->hasIndex('clt_snapshots', 'clt_snapshots_consulted_updated_cpf_idx')
        ) {
            Schema::table('clt_snapshots', function (Blueprint $table) {
                $table->index(['consulted_at', 'updated_at', 'cpf'], 'clt_snapshots_consulted_updated_cpf_idx');
            });
        }

        if (!$this->hasIndex('mercantil_snapshots', 'mercantil_snapshots_data_hora_updated_cpf_idx')) {
            Schema::table('mercantil_snapshots', function (Blueprint $table) {
                $table->index(
                    ['data_hora_origem', 'updated_at', 'cpf'],
                    'mercantil_snapshots_data_hora_updated_cpf_idx'
                );
            });
        }
    }

    public function down(): void
    {
        foreach ([
            ['leads', 'leads_updated_at_id_idx'],
            ['clt_snapshots', 'clt_snapshots_consulted_updated_cpf_idx'],
            ['mercantil_snapshots', 'mercantil_snapshots_data_hora_updated_cpf_idx'],
        ] as [$table, $index]) {
            if ($this->hasIndex($table, $index)) {
                Schema::table($table, function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }
    }
};
