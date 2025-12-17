<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inovachat_triages', function (Blueprint $table) {
            // tracking_id vindo do controller (UUID) como PK
            $table->uuid('tracking_id')->primary();

            $table->string('cpf', 11);
            $table->string('connection_token', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('ticket_id', 64)->nullable();
            $table->string('protocol', 64)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('first_name', 255)->nullable();
            $table->string('source', 64)->nullable();

            // Ex.: started, authorized, denied, error, timed_out...
            $table->string('status', 32)->default('started');

            $table->timestamps();

            $table->index('cpf');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inovachat_triages');
    }
};
