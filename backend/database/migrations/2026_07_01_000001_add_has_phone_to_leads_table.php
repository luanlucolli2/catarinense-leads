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
        if (! Schema::hasColumn('leads', 'has_phone')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->boolean('has_phone')->storedAs(
                    "CASE
                        WHEN (
                            (`fone1` IS NOT NULL AND TRIM(`fone1`) <> '')
                            OR (`fone2` IS NOT NULL AND TRIM(`fone2`) <> '')
                            OR (`fone3` IS NOT NULL AND TRIM(`fone3`) <> '')
                            OR (`fone4` IS NOT NULL AND TRIM(`fone4`) <> '')
                        ) THEN 1
                        ELSE 0
                    END"
                )->after('classe_fone4');
            });
        }

        if (! $this->hasIndex('leads', 'leads_has_phone_idx')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->index(['has_phone'], 'leads_has_phone_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('leads', 'leads_has_phone_idx')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropIndex('leads_has_phone_idx');
            });
        }

        if (Schema::hasColumn('leads', 'has_phone')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropColumn('has_phone');
            });
        }
    }
};
