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
        $map = [
            'import_jobs_type_id_idx' => ['type', 'id'],
            'import_jobs_status_id_idx' => ['status', 'id'],
            'import_jobs_type_origin_id_idx' => ['type', 'origin', 'id'],
            'import_jobs_type_status_rolled_back_id_idx' => ['type', 'status', 'rolled_back_at', 'id'],
        ];

        $missing = [];
        foreach ($map as $name => $columns) {
            if (!$this->hasIndex('import_jobs', $name)) {
                $missing[$name] = $columns;
            }
        }

        if (empty($missing)) {
            return;
        }

        Schema::table('import_jobs', function (Blueprint $table) use ($missing) {
            foreach ($missing as $name => $columns) {
                $table->index($columns, $name);
            }
        });
    }

    public function down(): void
    {
        $indexes = [
            'import_jobs_type_id_idx',
            'import_jobs_status_id_idx',
            'import_jobs_type_origin_id_idx',
            'import_jobs_type_status_rolled_back_id_idx',
        ];

        foreach ($indexes as $index) {
            if ($this->hasIndex('import_jobs', $index)) {
                Schema::table('import_jobs', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }
    }
};

