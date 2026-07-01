<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function hasIndex(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn(object $row): bool => ($row->name ?? null) === $index);
        }

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    public function up(): void
    {
        $cltIndexes = [
            'clt_snapshots_politica_credito_aprovado_idx' => ['politica_credito_aprovado'],
            'clt_snapshots_politica_credito_prazo_idx' => ['politica_credito_prazo_maximo_disponivel'],
            'clt_snapshots_not_found_politica_consulted_cpf_idx' => ['not_found', 'politica_credito_aprovado', 'consulted_at', 'cpf'],
        ];

        foreach ($cltIndexes as $name => $columns) {
            if (! $this->hasIndex('clt_snapshots', $name)) {
                Schema::table('clt_snapshots', function (Blueprint $table) use ($name, $columns) {
                    $table->index($columns, $name);
                });
            }
        }

        $uy3Indexes = [
            'uy3_snapshots_data_admissao_idx' => ['data_admissao'],
            'uy3_snapshots_elegivel_emprestimo_idx' => ['elegivel_emprestimo'],
            'uy3_snapshots_margem_disponivel_idx' => ['margem_disponivel'],
            'uy3_snapshots_valor_liberado_idx' => ['valor_liberado'],
            'uy3_snapshots_numero_parcelas_idx' => ['numero_parcelas'],
        ];

        foreach ($uy3Indexes as $name => $columns) {
            if (! $this->hasIndex('uy3_snapshots', $name)) {
                Schema::table('uy3_snapshots', function (Blueprint $table) use ($name, $columns) {
                    $table->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ([
            ['clt_snapshots', 'clt_snapshots_politica_credito_aprovado_idx'],
            ['clt_snapshots', 'clt_snapshots_politica_credito_prazo_idx'],
            ['clt_snapshots', 'clt_snapshots_not_found_politica_consulted_cpf_idx'],
            ['uy3_snapshots', 'uy3_snapshots_data_admissao_idx'],
            ['uy3_snapshots', 'uy3_snapshots_elegivel_emprestimo_idx'],
            ['uy3_snapshots', 'uy3_snapshots_margem_disponivel_idx'],
            ['uy3_snapshots', 'uy3_snapshots_valor_liberado_idx'],
            ['uy3_snapshots', 'uy3_snapshots_numero_parcelas_idx'],
        ] as [$tableName, $index]) {
            if ($this->hasIndex($tableName, $index)) {
                Schema::table($tableName, function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }
    }
};
