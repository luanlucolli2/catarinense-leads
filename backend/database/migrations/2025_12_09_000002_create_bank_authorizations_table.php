<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_authorizations', function (Blueprint $table) {
            $table->bigIncrements('id');

            // FK lógica para a triagem (tracking_id é PK na outra tabela)
            $table->uuid('tracking_id');

            // Ex.: c6, facta, outro_banco...
            $table->string('bank', 32);

            // Ex.: authorization, proposal, fgts, etc. (pensando em pipeline futuro)
            $table->string('step', 32)->default('authorization');

            $table->string('cpf', 11);
            $table->string('phone', 32)->nullable();

            // Link retornado pelo banco (no caso do C6, o link de autorização)
            $table->text('link')->nullable();

            // Se o banco retornar algum identificador específico da autorização, guarda aqui
            $table->string('external_id', 128)->nullable();

            // pending, authorized, denied, error, timed_out, etc.
            $table->string('status', 32)->default('pending');

            // Última resposta completa da API de status do banco (para debug/auditoria)
            $table->json('last_status_payload')->nullable();

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['tracking_id', 'bank']);
            $table->index(['cpf', 'bank']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_authorizations');
    }
};
