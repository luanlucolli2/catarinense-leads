<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            ALTER TABLE `v8_consult_jobs`
            MODIFY `status` ENUM('pendente','em_progresso','pausado','concluido','falhou','cancelado')
            NOT NULL DEFAULT 'pendente'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE `v8_consult_jobs`
            MODIFY `status` ENUM('pendente','em_progresso','concluido','falhou','cancelado')
            NOT NULL DEFAULT 'pendente'
        ");
    }
};
