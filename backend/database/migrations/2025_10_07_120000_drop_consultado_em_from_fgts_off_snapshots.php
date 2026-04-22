<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Apaga a coluna consultado_em (passaremos a usar updated_at)
        Schema::table('fgts_off_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('fgts_off_snapshots', 'consultado_em')) {
                $table->dropColumn('consultado_em');
            }
        });
    }

    public function down(): void
    {
        // Restaura a coluna para rollback (deixa nula e indexada)
        Schema::table('fgts_off_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('fgts_off_snapshots', 'consultado_em')) {
                $table->timestamp('consultado_em')->nullable()->index();
            }
        });

        // Opcional: copiar updated_at -> consultado_em para manter semântica
        DB::statement("
            UPDATE fgts_off_snapshots
            SET consultado_em = updated_at
            WHERE consultado_em IS NULL
        ");
    }
};
