<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mercantil_snapshots', function (Blueprint $table) {
            $table->string('cpf', 11)->primary();

            $table->string('status', 64)->nullable()->index();
            $table->text('mensagem_erro')->nullable();
            $table->timestamp('data_hora_origem')->nullable()->index();

            $table->decimal('valor_financiado', 15, 2)->nullable();
            $table->decimal('valor_iof', 15, 2)->nullable();
            $table->date('data_primeiro_vencimento')->nullable();
            $table->decimal('valor_emprestimo', 15, 2)->nullable();
            $table->unsignedSmallInteger('quantidade_parcelas')->nullable();
            $table->decimal('valor_liberado', 15, 2)->nullable();
            $table->decimal('taxa_juros_mes', 8, 4)->nullable();
            $table->decimal('valor_parcela', 15, 2)->nullable();

            $table->unsignedBigInteger('job_id')->nullable()->index();
            $table->timestamp('updated_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercantil_snapshots');
    }
};
