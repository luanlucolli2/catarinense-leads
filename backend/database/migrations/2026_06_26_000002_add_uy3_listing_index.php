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
        if (!$this->hasIndex('uy3_snapshots', 'uy3_snapshots_updated_at_cpf_idx')) {
            Schema::table('uy3_snapshots', function (Blueprint $table) {
                $table->index(['updated_at', 'cpf'], 'uy3_snapshots_updated_at_cpf_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('uy3_snapshots', 'uy3_snapshots_updated_at_cpf_idx')) {
            Schema::table('uy3_snapshots', function (Blueprint $table) {
                $table->dropIndex('uy3_snapshots_updated_at_cpf_idx');
            });
        }
    }
};
