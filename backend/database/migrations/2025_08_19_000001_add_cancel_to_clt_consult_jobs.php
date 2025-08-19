<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Ajusta o ENUM para incluir 'cancelado'
        DB::statement("ALTER TABLE clt_consult_jobs 
            MODIFY COLUMN status ENUM('pendente','em_progresso','concluido','falhou','cancelado') 
            NOT NULL DEFAULT 'pendente'");

        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            $table->timestamp('canceled_at')->nullable()->after('finished_at');
            $table->string('cancel_reason', 191)->nullable()->after('canceled_at');
        });
    }

    public function down(): void
    {
        // Reverte o ENUM (remove 'cancelado')
        DB::statement("ALTER TABLE clt_consult_jobs 
            MODIFY COLUMN status ENUM('pendente','em_progresso','concluido','falhou') 
            NOT NULL DEFAULT 'pendente'");

        Schema::table('clt_consult_jobs', function (Blueprint $table) {
            $table->dropColumn(['canceled_at', 'cancel_reason']);
        });
    }
};
