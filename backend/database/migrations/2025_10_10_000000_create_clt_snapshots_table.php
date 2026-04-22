<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clt_snapshots', function (Blueprint $table) {
            $table->string('cpf', 11)->primary();

            $table->foreignId('lead_id')->nullable()->index();

            // ✅ Campos solicitados
            $table->string('nome', 150)->nullable();
            $table->boolean('elegivel')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->unsignedSmallInteger('idade')->nullable();
            $table->string('sexo', 20)->nullable();

            $table->date('data_admissao')->nullable();
            $table->unsignedInteger('meses_admissao')->nullable();

            $table->decimal('valor_renda', 15, 2)->nullable();
            $table->decimal('valor_base_margem', 15, 2)->nullable();
            $table->decimal('margem_disponivel', 15, 2)->nullable();
            $table->decimal('valor_max_prestacao', 15, 2)->nullable();

            $table->string('categoria_trabalhador_codigo', 20)->nullable();

            $table->date('inicio_atividade_empregador')->nullable();

            $table->unsignedInteger('qtd_emprestimos_ativos_suspensos')->nullable();
            $table->unsignedInteger('emprestimos_legados')->nullable();

            // Controle
            $table->boolean('not_found')->default(false)->index();
            $table->unsignedBigInteger('job_id')->nullable()->index();
            $table->timestamp('updated_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clt_snapshots');
    }
};
