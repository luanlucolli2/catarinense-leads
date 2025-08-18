<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('clt_consult_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->enum('status', ['pendente','em_progresso','concluido','falhou'])->default('pendente');

            $table->unsignedInteger('total_cpfs')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);

            // onde o arquivo foi salvo
            $table->string('file_disk')->nullable();   // ex.: public, s3
            $table->string('file_path')->nullable();   // ex.: clt-reports/consulta_15.xlsx
            $table->string('file_name')->nullable();   // ex.: consulta_15.xlsx

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['user_id','status']);
            $table->index('created_at');
        });
    }

    public function down(): void {
        Schema::dropIfExists('clt_consult_jobs');
    }
};
