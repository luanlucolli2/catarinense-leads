<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `clt_snapshots` MODIFY `emprestimos_legados` TINYINT(1) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement("
                ALTER TABLE clt_snapshots
                ALTER COLUMN emprestimos_legados TYPE BOOLEAN
                USING CASE
                    WHEN emprestimos_legados IS NULL THEN NULL
                    WHEN emprestimos_legados = 1 THEN TRUE
                    WHEN emprestimos_legados = 0 THEN FALSE
                    ELSE NULL
                END
            ");
        } else {
            // Fallback (requer doctrine/dbal para ->change())
            Schema::table('clt_snapshots', function ($table) {
                $table->boolean('emprestimos_legados')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `clt_snapshots` MODIFY `emprestimos_legados` INT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement("
                ALTER TABLE clt_snapshots
                ALTER COLUMN emprestimos_legados TYPE INTEGER
                USING CASE
                    WHEN emprestimos_legados IS NULL THEN NULL
                    WHEN emprestimos_legados = TRUE THEN 1
                    ELSE 0
                END
            ");
        } else {
            Schema::table('clt_snapshots', function ($table) {
                $table->unsignedInteger('emprestimos_legados')->nullable()->change();
            });
        }
    }
};
