<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clt_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('clt_snapshots', 'politica_credito_aprovado')) {
                $table->boolean('politica_credito_aprovado')->nullable()->after('emprestimos_legados');
            }
            if (!Schema::hasColumn('clt_snapshots', 'politica_credito_mensagem')) {
                $table->text('politica_credito_mensagem')->nullable()->after('politica_credito_aprovado');
            }
            if (!Schema::hasColumn('clt_snapshots', 'politica_credito_valor_maximo_disponivel')) {
                $table->decimal('politica_credito_valor_maximo_disponivel', 15, 2)->nullable()->after('politica_credito_mensagem');
            }
            if (!Schema::hasColumn('clt_snapshots', 'politica_credito_prazo_maximo_disponivel')) {
                $table->unsignedSmallInteger('politica_credito_prazo_maximo_disponivel')->nullable()->after('politica_credito_valor_maximo_disponivel');
            }
            if (!Schema::hasColumn('clt_snapshots', 'politica_credito_data_consulta')) {
                $table->dateTime('politica_credito_data_consulta')->nullable()->after('politica_credito_prazo_maximo_disponivel');
            }
            if (!Schema::hasColumn('clt_snapshots', 'politica_credito_tabela_aprovada')) {
                $table->string('politica_credito_tabela_aprovada', 191)->nullable()->after('politica_credito_data_consulta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clt_snapshots', function (Blueprint $table) {
            foreach ([
                'politica_credito_tabela_aprovada',
                'politica_credito_data_consulta',
                'politica_credito_prazo_maximo_disponivel',
                'politica_credito_valor_maximo_disponivel',
                'politica_credito_mensagem',
                'politica_credito_aprovado',
            ] as $column) {
                if (Schema::hasColumn('clt_snapshots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
