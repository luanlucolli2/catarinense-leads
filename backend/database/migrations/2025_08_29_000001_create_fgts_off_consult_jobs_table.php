<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fgts_off_consult_jobs', function (Blueprint $table) {
            $table->id();

            // Relacionamento
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Metadados
            $table->string('title', 191);
            $table->string('status', 20)->default('pendente'); // pendente|em_progresso|concluido|falhou|cancelado

            // Contadores
            $table->unsignedInteger('total_cpfs')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);

            // Arquivo final
            $table->string('file_disk', 50)->nullable();
            $table->string('file_path', 512)->nullable();
            $table->string('file_name', 255)->nullable();

            // Janela de execução
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Cancelamento
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancel_reason', 191)->nullable();

            // Prévia
            $table->string('preview_disk', 50)->nullable();
            $table->string('preview_path', 512)->nullable();
            $table->string('preview_name', 255)->nullable();
            $table->timestamp('preview_updated_at')->nullable();

            $table->timestamps();

            // Índices úteis
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fgts_off_consult_jobs');
    }
};
