<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fgts_off_snapshots', function (Blueprint $table) {
            $table->string('cpf', 11)->primary();            // último resultado por CPF
            $table->foreignId('lead_id')->nullable()->index(); // preenchido via join por CPF
            $table->string('situacao')->nullable();          // "Autorizado", "Não autorizado - ...", etc.
            $table->boolean('authorized')->nullable();       // true/false/NULL (quando não aplicável)
            $table->timestamp('authorized_until')->nullable();
            $table->timestamp('consultado_em')->nullable();  // quando a consulta ocorreu
            $table->unsignedBigInteger('job_id')->nullable()->index();
            $table->json('raw_meta')->nullable();            // opcional (curto) p/ debugging
            $table->timestamp('updated_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fgts_off_snapshots');
    }
};
