<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_backups', function (Blueprint $table) {
            $table->id();

            // vínculo ao job que gerou o backup
            $table->foreignId('import_job_id')
                  ->constrained('import_jobs')
                  ->onDelete('cascade');

            // referência ao lead original (ainda existente ou já deletado no rollback)
            $table->foreignId('lead_id')
                  ->constrained('leads')
                  ->onDelete('cascade');

            // true se o lead foi criado pelo job; false se apenas atualizado
            $table->boolean('was_new')->default(false);

            /* snapshot dos campos de leads – mantenha em sincronia com a tabela original */
            $table->string('cpf', 11);
            $table->string('nome')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->string('fone1')->nullable();
            $table->string('classe_fone1')->nullable();
            $table->string('fone2')->nullable();
            $table->string('classe_fone2')->nullable();
            $table->string('fone3')->nullable();
            $table->string('classe_fone3')->nullable();
            $table->string('fone4')->nullable();
            $table->string('classe_fone4')->nullable();
            $table->string('consulta')->nullable();
            $table->timestamp('data_atualizacao')->nullable();
            $table->string('saldo')->nullable();
            $table->string('libera')->nullable();

            $table->timestamps(); // created_at = data do backup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_backups');
    }
};
