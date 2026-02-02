<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v8_consult_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->enum('status', ['pendente', 'em_progresso', 'concluido', 'falhou', 'cancelado'])->default('pendente');

            $table->unsignedInteger('total_cpfs')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('fail_count')->default(0);

            $table->string('file_disk')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();

            $table->string('spool_path')->nullable();
            $table->string('spool_inputs_path')->nullable();
            $table->unsignedBigInteger('spool_bytes')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v8_consult_jobs');
    }
};
