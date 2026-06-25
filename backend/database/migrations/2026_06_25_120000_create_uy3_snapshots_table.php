<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uy3_snapshots', function (Blueprint $table) {
            $table->string('cpf', 11)->primary();
            $table->string('type_webhook', 80)->nullable();
            $table->string('status', 80)->nullable();
            $table->date('data_admissao')->nullable();
            $table->decimal('valor_liberado', 15, 2)->nullable();
            $table->unsignedSmallInteger('numero_parcelas')->nullable();
            $table->string('codigo_requisicao', 80)->nullable();
            $table->decimal('margem_disponivel', 15, 2)->nullable();
            $table->boolean('elegivel_emprestimo')->nullable();
            $table->string('numero_inscricao_empregador', 32)->nullable();
            $table->integer('pessoa_exposta_politicamente_codigo')->nullable();
            $table->timestamp('data_hora_validade_solicitacao')->nullable();
            $table->boolean('is_mei')->nullable();
            $table->json('active_fgts_debts')->nullable();
            $table->json('all_branch_employees')->nullable();
            $table->boolean('is_judicial_recovery')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uy3_snapshots');
    }
};
