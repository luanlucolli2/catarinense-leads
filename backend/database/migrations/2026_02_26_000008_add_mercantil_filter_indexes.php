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
        $missing = [];

        $map = [
            'mercantil_snapshots_status_data_hora_idx' => ['status', 'data_hora_origem'],
            'mercantil_snapshots_status_updated_at_idx' => ['status', 'updated_at'],
            'mercantil_snapshots_valor_parcela_idx' => ['valor_parcela'],
            'mercantil_snapshots_qtd_parcelas_idx' => ['quantidade_parcelas'],
        ];

        foreach ($map as $name => $columns) {
            if (!$this->hasIndex('mercantil_snapshots', $name)) {
                $missing[$name] = $columns;
            }
        }

        if (empty($missing)) {
            return;
        }

        Schema::table('mercantil_snapshots', function (Blueprint $table) use ($missing) {
            foreach ($missing as $name => $columns) {
                $table->index($columns, $name);
            }
        });
    }

    public function down(): void
    {
        $indexes = [
            'mercantil_snapshots_status_data_hora_idx',
            'mercantil_snapshots_status_updated_at_idx',
            'mercantil_snapshots_valor_parcela_idx',
            'mercantil_snapshots_qtd_parcelas_idx',
        ];

        foreach ($indexes as $index) {
            if ($this->hasIndex('mercantil_snapshots', $index)) {
                Schema::table('mercantil_snapshots', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }
    }
};

