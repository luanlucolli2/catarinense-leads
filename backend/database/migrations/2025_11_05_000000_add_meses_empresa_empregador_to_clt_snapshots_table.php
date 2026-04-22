<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_snapshots', function (Blueprint $table) {
            // inteiro em meses, pode ser nulo quando não houver data de início do empregador
            $table->integer('meses_empresa_empregador')
                  ->nullable()
                  ->after('inicio_atividade_empregador');
        });
    }

    public function down(): void
    {
        Schema::table('clt_snapshots', function (Blueprint $table) {
            $table->dropColumn('meses_empresa_empregador');
        });
    }
};
