<?php
// database/migrations/2025_10_16_000000_add_missing_filter_indexes.php

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

    /** Retorna somente os índices ausentes (nome => colunas) */
    private function missing(string $table, array $map): array
    {
        $out = [];
        foreach ($map as $name => $cols) {
            if (!$this->hasIndex($table, $name)) $out[$name] = $cols;
        }
        return $out;
    }

    public function up(): void
    {
        // ===== leads =====
        $leads = $this->missing('leads', [
            'leads_fone1_index'        => ['fone1'],
            'leads_fone2_index'        => ['fone2'],
            'leads_fone3_index'        => ['fone3'],
            'leads_fone4_index'        => ['fone4'],
            'leads_updated_at_index'   => ['updated_at'], // acelera ORDER BY updated_at DESC
        ]);
        if ($leads) {
            Schema::table('leads', function (Blueprint $table) use ($leads) {
                foreach ($leads as $name => $cols) $table->index($cols, $name);
            });
        }
        // Índice funcional p/ MONTH(data_nascimento) (sem criar coluna)
        if (!$this->hasIndex('leads', 'leads_birth_month_index')) {
            DB::statement('CREATE INDEX `leads_birth_month_index` ON `leads` ((MONTH(`data_nascimento`)))');
        }

        // ===== lead_imports =====
        $leadImports = $this->missing('lead_imports', [
            'lead_imports_created_at_index'          => ['created_at'],
            // ajuda o subselect "mais recente por lead" (ORDER BY created_at, import_job_id)
            'lead_imports_lead_created_import_idx'   => ['lead_id', 'created_at', 'import_job_id'],
        ]);
        if ($leadImports) {
            Schema::table('lead_imports', function (Blueprint $table) use ($leadImports) {
                foreach ($leadImports as $name => $cols) $table->index($cols, $name);
            });
        }

        // ===== clt_snapshots =====
        $clt = $this->missing('clt_snapshots', [
            'clt_snapshots_data_admissao_index'               => ['data_admissao'],
            'clt_snapshots_meses_admissao_index'              => ['meses_admissao'],
            'clt_snapshots_inicio_atividade_empregador_index' => ['inicio_atividade_empregador'],
            'clt_snapshots_categoria_trab_cod_index'          => ['categoria_trabalhador_codigo'],
            'clt_snapshots_idade_index'                       => ['idade'],
            'clt_snapshots_sexo_index'                        => ['sexo'],
            'clt_snapshots_valor_renda_index'                 => ['valor_renda'],
            'clt_snapshots_valor_base_margem_index'           => ['valor_base_margem'],
            'clt_snapshots_margem_disponivel_index'           => ['margem_disponivel'],
            'clt_snapshots_valor_max_prestacao_index'         => ['valor_max_prestacao'],
            'clt_snapshots_qtd_ativos_suspensos_index'        => ['qtd_emprestimos_ativos_suspensos'],
        ]);
        if ($clt) {
            Schema::table('clt_snapshots', function (Blueprint $table) use ($clt) {
                foreach ($clt as $name => $cols) $table->index($cols, $name);
            });
        }

        // ===== fgts_off_snapshots =====
        $fgtsOff = $this->missing('fgts_off_snapshots', [
            'fgts_off_snapshots_authorized_index'        => ['authorized'],
            // acelera status + janela de data
            'fgts_off_snapshots_auth_updated_index'      => ['authorized', 'updated_at'],
        ]);
        if ($fgtsOff) {
            Schema::table('fgts_off_snapshots', function (Blueprint $table) use ($fgtsOff) {
                foreach ($fgtsOff as $name => $cols) $table->index($cols, $name);
            });
        }
    }

    public function down(): void
    {
        // Remoção segura (só se existir)

        // leads
        foreach ([
            'leads_fone1_index','leads_fone2_index','leads_fone3_index','leads_fone4_index','leads_updated_at_index'
        ] as $idx) {
            if ($this->hasIndex('leads', $idx)) {
                Schema::table('leads', fn (Blueprint $t) => $t->dropIndex($idx));
            }
        }
        if ($this->hasIndex('leads', 'leads_birth_month_index')) {
            DB::statement('DROP INDEX `leads_birth_month_index` ON `leads`');
        }

        // lead_imports
        foreach ([
            'lead_imports_created_at_index','lead_imports_lead_created_import_idx'
        ] as $idx) {
            if ($this->hasIndex('lead_imports', $idx)) {
                Schema::table('lead_imports', fn (Blueprint $t) => $t->dropIndex($idx));
            }
        }

        // clt_snapshots
        foreach ([
            'clt_snapshots_data_admissao_index',
            'clt_snapshots_meses_admissao_index',
            'clt_snapshots_inicio_atividade_empregador_index',
            'clt_snapshots_categoria_trab_cod_index',
            'clt_snapshots_idade_index',
            'clt_snapshots_sexo_index',
            'clt_snapshots_valor_renda_index',
            'clt_snapshots_valor_base_margem_index',
            'clt_snapshots_margem_disponivel_index',
            'clt_snapshots_valor_max_prestacao_index',
            'clt_snapshots_qtd_ativos_suspensos_index',
        ] as $idx) {
            if ($this->hasIndex('clt_snapshots', $idx)) {
                Schema::table('clt_snapshots', fn (Blueprint $t) => $t->dropIndex($idx));
            }
        }

        // fgts_off_snapshots
        foreach ([
            'fgts_off_snapshots_authorized_index',
            'fgts_off_snapshots_auth_updated_index',
        ] as $idx) {
            if ($this->hasIndex('fgts_off_snapshots', $idx)) {
                Schema::table('fgts_off_snapshots', fn (Blueprint $t) => $t->dropIndex($idx));
            }
        }
    }
};
